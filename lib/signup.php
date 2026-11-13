<?php
function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? 'cli'; }
function new_token(): string { return bin2hex(random_bytes(16)); }

/** How many times a bucket (for example "signup:1.2.3.4") was used in the last $seconds seconds. */
function rate_count(string $bucket, int $seconds): int {
    $st = db()->prepare("SELECT COUNT(*) FROM signup_log WHERE bucket = ? AND created_at >= datetime('now', ?)");
    $st->execute([$bucket, "-$seconds seconds"]);
    return (int) $st->fetchColumn();
}
function rate_hit(string $bucket): void { db()->prepare('INSERT INTO signup_log (bucket) VALUES (?)')->execute([$bucket]); }

/** Count one action against a bucket. Returns true when the limit was already reached. */
function rate_limited(string $bucket, int $max, int $seconds): bool {
    if (rate_count($bucket, $seconds) >= $max) return true;
    rate_hit($bucket);
    return false;
}

/** Where a subscriber's confirmation code goes: their phone if they gave one, otherwise their email. */
function subscriber_target(array $s): string { return $s['phone'] ?: strtolower((string) $s['email']); }

function mask_target(string $t): string {
    if (str_contains($t, '@')) return substr($t, 0, 1) . '***' . substr($t, strpos($t, '@'));
    return substr($t, 0, 4) . '***' . substr($t, -3);
}

function code_hash(string $purpose, string $target, string $code): string { return hash('sha256', "$purpose|$target|$code"); }

/** Send a one-time 6-digit code by SMS (phone numbers) or email. Returns ok, delivered, error (and the code, for tests only). */
function issue_code(string $purpose, string $target): array {
    $pdo = db();
    $st = $pdo->prepare("SELECT COUNT(*) FROM codes WHERE purpose = ? AND target = ? AND created_at >= datetime('now', '-60 seconds')");
    $st->execute([$purpose, $target]);
    if ((int) $st->fetchColumn() > 0) return ['ok' => false, 'delivered' => false, 'error' => 'A code was just sent. Please wait a minute before asking for another.'];

    if (rate_limited("codeto:$purpose:$target", 3, 3600)) {
        return ['ok' => false, 'delivered' => false, 'error' => 'Too many codes were requested for this contact. Please try again in an hour.'];
    }

    $isPhone = (bool) preg_match('/^09\d{9}$/', $target);
    if ($isPhone) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM codes WHERE target LIKE '09%' AND created_at >= datetime('now', '-1 day')");
        $st->execute();
        if ((int) $st->fetchColumn() >= (int) cfg('sms_code_daily_cap')) {
            return ['ok' => false, 'delivered' => false, 'error' => 'Too many codes have been sent today. Please try again tomorrow or contact the administrator.'];
        }
    }

    $code = sprintf('%06d', random_int(0, 999999));
    $pdo->prepare('DELETE FROM codes WHERE purpose = ? AND target = ?')->execute([$purpose, $target]);
    $pdo->prepare('INSERT INTO codes (purpose, target, code_hash, expires_at) VALUES (?, ?, ?, ?)')
        ->execute([$purpose, $target, code_hash($purpose, $target, $code), gmdate('Y-m-d H:i:s', time() + 1800)]);

    $what = $purpose === 'unsubscribe' ? 'to stop alerts' : 'to confirm';
    // The code is never saved in the message log, so only the person who receives it sees it.
    if ($isPhone) {
        $status = send_sms_batch([$target], "BantayKlima code $what: $code. Valid for 30 minutes. Do not share it.", false, "BantayKlima code $what: ****** (hidden)");
    } else {
        $status = send_email($target, 'Your BantayKlima code', "Your BantayKlima code $what is $code. It is valid for 30 minutes.\n\nIf you did not ask for this, you can ignore this message.", false, "Your BantayKlima code $what is ****** (hidden)");
    }
    return ['ok' => true, 'delivered' => in_array($status, ['sent', 'queued', 'pending'], true), 'error' => null, 'code' => $code];
}

/** Check a code. Five wrong tries lock it; codes last 30 minutes and work once. */
function check_code(string $purpose, string $target, string $code): array {
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM codes WHERE purpose = ? AND target = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$purpose, $target]);
    $row = $st->fetch();
    if (!$row || $row['expires_at'] < gmdate('Y-m-d H:i:s')) return ['ok' => false, 'error' => 'That code has expired or was not found. Please ask for a new one.'];
    if ((int) $row['attempts'] >= 5) return ['ok' => false, 'error' => 'Too many wrong tries. Please ask for a new code.'];

    $pdo->prepare('UPDATE codes SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
    if (!hash_equals($row['code_hash'], code_hash($purpose, $target, $code))) return ['ok' => false, 'error' => 'That code is not right.'];

    $pdo->prepare('DELETE FROM codes WHERE purpose = ? AND target = ?')->execute([$purpose, $target]);
    return ['ok' => true, 'error' => null];
}

/** Remove a subscriber and every message record and code tied to their contact details (unless another subscription still uses them). */
function forget_subscriber(int $id): void {
    $st = db()->prepare('SELECT phone, email, user_id FROM subscribers WHERE id = ?');
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) return;
    db()->prepare('DELETE FROM subscribers WHERE id = ?')->execute([$id]);
    if ($s['user_id']) db()->prepare('UPDATE users SET sms_optin = 0 WHERE id = ?')->execute([$s['user_id']]);
    forget_contact($s['phone'] ?: null, $s['email'] ?: null);
}

function forget_contact(?string $phone, ?string $email): void {
    $pdo = db();
    if ($phone) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM subscribers WHERE phone = ?');
        $st->execute([$phone]);
        if (!(int) $st->fetchColumn()) {
            $pdo->prepare("DELETE FROM messages WHERE channel = 'sms' AND recipient IN (?, ?)")->execute([$phone, '63' . substr($phone, 1)]);
            $pdo->prepare('DELETE FROM codes WHERE target = ?')->execute([$phone]);
        }
    }
    if ($email) {
        $email = strtolower($email);
        $st = $pdo->prepare('SELECT COUNT(*) FROM subscribers WHERE lower(email) = ?');
        $st->execute([$email]);
        if (!(int) $st->fetchColumn()) {
            $pdo->prepare("DELETE FROM messages WHERE channel = 'email' AND lower(recipient) = ?")->execute([$email]);
            $pdo->prepare('DELETE FROM codes WHERE target = ?')->execute([$email]);
        }
    }
}

/** Tidy up: unconfirmed sign-ups after a day, expired codes, old rate-limit records, and message records past their retention time. */
function cleanup_signups(): void {
    $pdo = db();
    foreach ($pdo->query("SELECT id FROM subscribers WHERE status = 'pending' AND created_at < datetime('now', '-1 day')")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        forget_subscriber((int) $id);
    }
    $pdo->prepare('DELETE FROM codes WHERE expires_at < ?')->execute([gmdate('Y-m-d H:i:s')]);
    $pdo->exec("DELETE FROM signup_log WHERE created_at < datetime('now', '-2 days')");
    $pdo->prepare("DELETE FROM messages WHERE created_at < datetime('now', ?)")->execute(['-' . max(1, (int) cfg('message_keep_days')) . ' days']);
}

function town_exists(int $id): bool {
    $st = db()->prepare('SELECT COUNT(*) FROM municipalities WHERE id = ?');
    $st->execute([$id]);
    return (bool) $st->fetchColumn();
}
