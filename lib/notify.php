<?php
/** SMS settings: saved in the database from the admin page, falling back to config.local.php. $name is 'key' or 'sender'. */
function sms_setting(string $name): string {
    $saved = (array) setting_get('sms', []);
    $v = trim((string) ($saved[$name] ?? ''));
    return $v !== '' ? $v : trim((string) cfg('semaphore_' . $name));
}

function log_alert(string $line): void {
    $dir = dirname(cfg('db_file'));
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    file_put_contents($dir . '/alerts.log', '[' . date('Y-m-d H:i') . '] ' . $line . "\n", FILE_APPEND);
}

/** Save every message and its result (also appended to data/alerts.log). Status: simulated, logged only, queued, pending, sent, failed. */
function record_message(string $channel, string $to, string $body, string $status, string $detail = ''): void {
    db()->prepare('INSERT INTO messages (channel, recipient, body, status, detail) VALUES (?, ?, ?, ?, ?)')
        ->execute([$channel, $to, $body, $status, $detail]);
    log_alert(strtoupper($channel) . " to $to [$status]: " . str_replace("\n", ' ', $body));
}

/** Philippine mobile number as 09XXXXXXXXX (accepts 09..., +639..., 639..., 9... with spaces or dashes), or null if invalid. */
function normalize_ph_phone(string $raw): ?string {
    $digits = preg_replace('/[\s\-().]/', '', $raw);
    return preg_match('/^(?:\+?63|0)?(9\d{9})$/', $digits, $m) ? '0' . $m[1] : null;
}

/** SMS text: swap ñ for n (keeps it in the cheaper plain alphabet) and cut to one 160-character message. */
function sms_text(string $s, int $max = 160): string {
    $s = strtr($s, ['ñ' => 'n', 'Ñ' => 'N']);
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

/** How many SMS credits one text of this length uses per person (160 characters, then 153 per extra part). */
function sms_parts(string $s): int {
    $n = function_exists('mb_strlen') ? mb_strlen(strtr($s, ['ñ' => 'n', 'Ñ' => 'N'])) : strlen($s);
    return $n <= 160 ? 1 : (int) ceil($n / 153);
}

/** Count the messages saved after $lastId by their result, e.g. ['sent' => 12, 'logged only' => 3]. */
function sms_summary_since(int $lastId, string $channel = 'sms'): array {
    $st = db()->prepare("SELECT status, COUNT(*) FROM messages WHERE channel = ? AND id > ? GROUP BY status");
    $st->execute([$channel, $lastId]);
    return $st->fetchAll(PDO::FETCH_KEY_PAIR);
}
function last_message_id(): int { return (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM messages')->fetchColumn(); }

/** Read Semaphore's reply: one entry per recipient when it worked, or an error message when it did not. */
function parse_semaphore_response(?string $body): array {
    $d = $body ? json_decode($body, true) : null;
    if (!is_array($d)) return ['ok' => false, 'error' => 'No valid reply from Semaphore.', 'items' => []];
    if (isset($d[0]) && is_array($d[0]) && isset($d[0]['status'])) {
        return ['ok' => true, 'error' => null, 'items' => array_map(fn ($x) => [
            'recipient' => (string) ($x['recipient'] ?? '?'), 'status' => strtolower((string) $x['status']), 'message_id' => $x['message_id'] ?? '',
        ], $d)];
    }
    return ['ok' => false, 'error' => 'Semaphore said: ' . substr(json_encode($d, JSON_UNESCAPED_UNICODE), 0, 200), 'items' => []];
}

/**
 * Send one text to many numbers with a single Semaphore call (its docs ask for this, up to 1,000 numbers).
 * $logAs replaces the text in the message log (used to hide one-time codes). Returns the last message's status.
 */
function send_sms_batch(array $numbers, string $text, bool $simulate = false, ?string $logAs = null, int $maxLen = 160): string {
    $numbers = array_values(array_unique(array_filter($numbers)));
    if (!$numbers) return 'none';
    $text = sms_text($text, $maxLen);
    $logged = $logAs !== null ? sms_text($logAs, $maxLen) : $text;
    $last = 'none';

    foreach (array_chunk($numbers, 1000) as $chunk) {
        if ($simulate) { foreach ($chunk as $n) record_message('sms', $n, $logged, $last = 'simulated', 'Demo mode: nothing was sent'); continue; }
        if (!sms_setting('key')) { foreach ($chunk as $n) record_message('sms', $n, $logged, $last = 'logged only', 'No SMS key saved yet (Admin > Settings)'); continue; }

        // Credit guard: never send more than sms_daily_cap real texts in 24 hours.
        $allowed = sms_allowance(sms_sent_last_day(), (int) cfg('sms_daily_cap'));
        if ($allowed < count($chunk)) {
            foreach (array_slice($chunk, $allowed) as $n) record_message('sms', $n, $logged, $last = 'failed', 'Daily SMS limit reached (sms_daily_cap in config)');
            $chunk = array_slice($chunk, 0, $allowed);
            if (!$chunk) continue;
        }
        $fields = ['apikey' => sms_setting('key'), 'number' => implode(',', $chunk), 'message' => $text];
        if (sms_setting('sender')) $fields['sendername'] = sms_setting('sender');
        [$code, $body] = http_post(cfg('semaphore_url') . '/messages', $fields, 30);

        $r = parse_semaphore_response($body);
        if ($r['ok']) {
            foreach ($r['items'] as $it) record_message('sms', $it['recipient'], $logged, $last = $it['status'], 'Semaphore message ' . $it['message_id']);
        } else {
            foreach ($chunk as $n) record_message('sms', $n, $logged, $last = 'failed', $r['error'] . ($code ? " (HTTP $code)" : ' (no connection' . (http_last_error() ? ': ' . http_last_error() : '') . ')'));
        }
        if ((float) cfg('sms_pause') > 0) usleep((int) (cfg('sms_pause') * 1000000));
    }
    return $last;
}

/** How many real texts may still go out today (cap 0 means no limit). */
function sms_allowance(int $used, int $cap): int { return $cap <= 0 ? PHP_INT_MAX : max(0, $cap - $used); }

function sms_sent_last_day(): int {
    return (int) db()->query("SELECT COUNT(*) FROM messages WHERE channel = 'sms' AND status IN ('sent', 'queued', 'pending') AND created_at >= datetime('now', '-1 day')")->fetchColumn();
}

/** Send one email. Returns its status: sent, failed, logged only or simulated. Uses SMTP when it is connected in Admin > Settings. */
function send_email(string $to, string $subject, string $body, bool $simulate = false, ?string $logAs = null): string {
    $logged = $logAs ?? $body;
    if ($simulate) { record_message('email', $to, $logged, 'simulated', 'Demo mode: nothing was sent'); return 'simulated'; }
    if (email_connected()) {
        $cap = (int) cfg('email_daily_cap');
        if ($cap > 0 && email_sent_last_day() >= $cap) { record_message('email', $to, $logged, 'failed', 'Daily email limit reached (email_daily_cap in config)'); return 'failed'; }
        [$ok, $err] = smtp_send($to, $subject, $body);
        record_message('email', $to, $logged, $status = $ok ? 'sent' : 'failed', $err);
        return $status;
    }
    if (!cfg('send_email')) { record_message('email', $to, $logged, 'logged only', 'No email account connected yet (Admin > Settings)'); return 'logged only'; }
    $ok = @mail($to, $subject, $body, 'From: ' . cfg('mail_from') . "\r\nContent-Type: text/plain; charset=UTF-8");
    record_message('email', $to, $logged, $status = $ok ? 'sent' : 'failed', $ok ? '' : 'PHP mail() refused the message');
    return $status;
}

/** Emergency contacts for a municipality: its own first, then province-wide ones. */
function contacts_for(int $municipalityId): array {
    $st = db()->prepare('SELECT label, phone FROM contacts WHERE municipality_id = ? OR municipality_id IS NULL ORDER BY municipality_id IS NULL, id');
    $st->execute([$municipalityId]);
    return $st->fetchAll();
}

function contacts_text(int $municipalityId): string {
    $parts = ['National emergency hotline: 911'];
    foreach (contacts_for($municipalityId) as $c) $parts[] = $c['label'] . ': ' . $c['phone'];
    return 'Emergency contacts: ' . implode('; ', $parts) . '.';
}

/** Closing lines for emails so people can always stop alerts. */
function unsub_footer(array $s): string {
    $base = rtrim((string) cfg('base_url'), '/');
    if ($base !== '' && !empty($s['unsub_token'])) return "\n\n--\nTo stop these alerts: $base/unsubscribe.php?t=" . $s['unsub_token'];
    return "\n\n--\nTo stop these alerts, use the Stop alerts page on the BantayKlima website or write to " . cfg('contact_email') . '.';
}

/** Alert confirmed subscribers of a municipality about its new risk level, in the language each chose. Demo mode never really sends. */
function send_alerts(array $m, array $a, bool $simulate = false): void {
    $st = db()->prepare("SELECT * FROM subscribers WHERE municipality_id = ? AND status = 'active'");
    $st->execute([$m['id']]);
    $byLang = ['en' => [], 'fil' => []];
    foreach ($st->fetchAll() as $s) $byLang[$s['language'] === 'fil' ? 'fil' : 'en'][] = $s;
    $serious = in_array($a['level'], ['warning', 'severe'], true);
    $contacts = $serious ? "\n\n" . contacts_text((int) $m['id']) : '';

    foreach ($byLang as $lang => $subs) {
        if (!$subs) continue;
        $head = risk_head($m['name'], $a, $lang);
        $advice = risk_advice($a['hazard'], $a['level'], $lang);
        foreach ($subs as $s) {
            if ($s['email']) send_email($s['email'], 'BantayKlima: ' . $m['name'], "$head\n\n$advice$contacts" . unsub_footer($s), $simulate);
        }
        send_sms_batch(array_column($subs, 'phone'), $head . ' ' . rtrim(explode('. ', $advice)[0], '.') . '.', $simulate);
    }
}

/** Send an LGU announcement to active subscribers of one municipality (or everyone if $municipalityId is null), by SMS and/or email. Returns how many subscribers were in the area. */
function send_announcement(string $title, string $body, ?int $municipalityId, bool $bySms = true, bool $byEmail = true): int {
    if (function_exists('set_time_limit')) @set_time_limit(0); // a long email list can take a while
    $st = db()->prepare("SELECT * FROM subscribers WHERE status = 'active'" . ($municipalityId ? ' AND municipality_id = ?' : ''));
    $st->execute($municipalityId ? [$municipalityId] : []);
    $subs = $st->fetchAll();
    if ($byEmail) {
        foreach ($subs as $s) {
            if ($s['email']) send_email($s['email'], 'BantayKlima: ' . $title, "$title\n\n$body" . unsub_footer($s));
        }
    }
    if ($bySms) send_sms_batch(array_column($subs, 'phone'), announcement_sms_text($title, $body), false, null, 306); // up to 2 text parts
    return count($subs);
}

/** The text residents get by SMS for an announcement. */
function announcement_sms_text(string $title, string $body): string { return "BantayKlima: $title. $body"; }

/**
 * Post an announcement on the dashboard and, if asked, text it to the active subscribers of that area.
 * Returns ['ok' => bool, 'error' => ?string, 'people' => n, 'sent' => n, 'failed' => n, 'mode' => 'live'|'log'|'']. The SMS result is saved with the announcement.
 */
function announce(string $title, string $body, ?int $municipalityId, bool $sendSms, bool $sendEmail = false): array {
    $title = trim($title); $body = trim($body);
    $r = ['ok' => false, 'error' => null, 'id' => 0, 'people' => 0, 'sent' => 0, 'failed' => 0, 'mode' => '', 'email_people' => 0, 'email_sent' => 0, 'email_failed' => 0, 'email_mode' => ''];
    if ($title === '' || $body === '') { $r['error'] = 'Please write a title and a message.'; return $r; }
    if (mb_strlen($title) > 100) { $r['error'] = 'The title is too long (100 characters at most).'; return $r; }
    if (mb_strlen($body) > 500) { $r['error'] = 'The message is too long (500 characters at most).'; return $r; }
    if ($municipalityId && !town_exists($municipalityId)) { $r['error'] = 'Please choose a valid area.'; return $r; }
    $len = mb_strlen(strtr(announcement_sms_text($title, $body), ['ñ' => 'n', 'Ñ' => 'N']));
    if ($sendSms && $len > 306) {
        $r['error'] = 'Too long for a text message: ' . ($len - 306) . ' characters over the 306 limit. Shorten it, or untick "Also send by SMS".';
        return $r;
    }
    db()->prepare('INSERT INTO announcements (municipality_id, title, body, sms_requested, email_requested) VALUES (?, ?, ?, ?, ?)')
        ->execute([$municipalityId ?: null, $title, $body, (int) $sendSms, (int) $sendEmail]);
    $r['id'] = (int) db()->lastInsertId();
    $r['ok'] = true;
    if ($sendSms || $sendEmail) {
        $before = last_message_id();
        send_announcement($title, $body, $municipalityId ?: null, $sendSms, $sendEmail);
        if ($sendSms) {
            $sum = sms_summary_since($before);
            $r['people'] = sms_audience($municipalityId ?: null);
            $r['sent'] = (int) (($sum['sent'] ?? 0) + ($sum['queued'] ?? 0) + ($sum['pending'] ?? 0));
            $r['failed'] = (int) ($sum['failed'] ?? 0);
            $r['mode'] = isset($sum['logged only']) ? 'log' : ($r['people'] ? 'live' : '');
        }
        if ($sendEmail) {
            $sum = sms_summary_since($before, 'email');
            $r['email_people'] = email_audience($municipalityId ?: null);
            $r['email_sent'] = (int) ($sum['sent'] ?? 0);
            $r['email_failed'] = (int) ($sum['failed'] ?? 0);
            $r['email_mode'] = isset($sum['logged only']) ? 'log' : ($r['email_people'] ? 'live' : '');
        }
        db()->prepare('UPDATE announcements SET sms_people = ?, sms_sent = ?, sms_failed = ?, sms_mode = ?, email_people = ?, email_sent = ?, email_failed = ?, email_mode = ? WHERE id = ?')
            ->execute([$r['people'], $r['sent'], $r['failed'], $r['mode'], $r['email_people'], $r['email_sent'], $r['email_failed'], $r['email_mode'], $r['id']]);
    }
    return $r;
}

/** How many active subscribers an announcement to this area would reach by SMS. */
function sms_audience(?int $municipalityId): int {
    $st = db()->prepare("SELECT COUNT(*) FROM subscribers WHERE status = 'active' AND phone IS NOT NULL AND phone <> ''" . ($municipalityId ? ' AND municipality_id = ?' : ''));
    $st->execute($municipalityId ? [$municipalityId] : []);
    return (int) $st->fetchColumn();
}

/** Credit balance and sender names from Semaphore (cached for a minute: these calls are limited to 2 a minute). */
function sms_account(): ?array {
    if (!sms_setting('key')) return null;
    $ck = 'sms.account.' . md5(sms_setting('key'));
    if ($c = cache_get($ck, 60)) return $c;
    $key = urlencode((string) sms_setting('key'));
    $acc = http_get(cfg('semaphore_url') . '/account?apikey=' . $key);
    $snd = http_get(cfg('semaphore_url') . '/account/sendernames?apikey=' . $key);
    $a = $acc ? json_decode($acc, true) : null;
    if (is_array($a) && isset($a[0])) $a = $a[0];
    $s = $snd ? json_decode($snd, true) : null;
    $out = [
        'balance' => $a['credit_balance'] ?? null,
        'senders' => is_array($s) ? array_map(fn ($x) => ($x['name'] ?? '?') . ' (' . ($x['status'] ?? '?') . ')', $s) : [],
        'error' => is_array($a) ? null : 'Could not reach Semaphore, or the API key was rejected.' . (http_last_error() ? ' (' . http_last_error() . ')' : ''),
    ];
    cache_put($ck, $out);
    return $out;
}

/** Plain-words result of a batch of texts, from sms_summary_since(). */
function sms_result_text(array $sum): string {
    if (!$sum) return 'No texts were queued.';
    if (isset($sum['logged only'])) return 'SMS was only recorded, not sent: no SMS key is set in config.local.php yet.';
    if (isset($sum['simulated'])) return 'Demo mode: no real texts were sent.';
    $good = (int) (($sum['sent'] ?? 0) + ($sum['queued'] ?? 0) + ($sum['pending'] ?? 0));
    $bad = (int) ($sum['failed'] ?? 0);
    return "SMS: $good sent" . ($bad ? ", $bad failed (see Recent messages)" : '') . '.';
}
