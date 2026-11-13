<?php
// Website accounts for residents: plain register and login (no one-time codes).
// An account that keeps "SMS alerts" ticked is also kept in the subscribers table, so alerts and announcements reach it.

function user_session(): void {
    if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_NONE) return;
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']), 'path' => '/']);
    session_name('bk_user');
    session_start();
}

function current_user(): ?array {
    user_session();
    $id = (int) ($_SESSION['uid'] ?? 0);
    if (!$id) return null;
    $st = db()->prepare('SELECT u.*, m.name AS town FROM users u JOIN municipalities m ON m.id = u.municipality_id WHERE u.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function user_alerts_on(int $id): bool {
    $st = db()->prepare("SELECT 1 FROM subscribers WHERE user_id = ? AND status = 'active'");
    $st->execute([$id]);
    return (bool) $st->fetchColumn();
}

function user_csrf(): string { user_session(); return $_SESSION['ucsrf'] ??= bin2hex(random_bytes(16)); }
function user_csrf_ok(): bool { user_session(); return hash_equals($_SESSION['ucsrf'] ?? '-', (string) ($_POST['csrf'] ?? '')); }

function valid_new_password(string $pw): ?string {
    if (strlen($pw) < 6) return 'Password must be at least 6 characters.';
    if (strlen($pw) > 72) return 'Password is too long (72 characters at most).';
    return null;
}

/** Create an account. Returns ['ok' => true, 'id' => n] or ['ok' => false, 'error' => text]. */
function register_user(array $in): array {
    $name = trim((string) ($in['name'] ?? ''));
    $phone = normalize_ph_phone((string) ($in['phone'] ?? ''));
    $email = strtolower(trim((string) ($in['email'] ?? '')));
    $mid = (int) ($in['municipality_id'] ?? 0);
    $lang = ($in['language'] ?? 'en') === 'fil' ? 'fil' : 'en';
    $sms = !empty($in['sms_optin']) ? 1 : 0;

    if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 80) return ['ok' => false, 'error' => 'Please enter your name.'];
    if (!$phone) return ['ok' => false, 'error' => 'Mobile number should look like 09XXXXXXXXX.'];
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'That email does not look right. You can leave it empty.'];
    if ($err = valid_new_password((string) ($in['password'] ?? ''))) return ['ok' => false, 'error' => $err];
    if (!town_exists($mid)) return ['ok' => false, 'error' => 'Please choose your municipality.'];

    $st = db()->prepare('SELECT COUNT(*) FROM users WHERE phone = ? OR (? <> \'\' AND lower(email) = ?)');
    $st->execute([$phone, $email, $email]);
    if ((int) $st->fetchColumn()) return ['ok' => false, 'error' => 'That mobile number or email already has an account. Please log in instead.'];

    db()->prepare('INSERT INTO users (name, phone, email, password_hash, municipality_id, language, sms_optin) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$name, $phone, $email !== '' ? $email : null, password_hash((string) $in['password'], PASSWORD_DEFAULT), $mid, $lang, $sms]);
    $id = (int) db()->lastInsertId();
    sync_subscriber($id);
    return ['ok' => true, 'id' => $id];
}

/** Check a mobile number or email with a password. Returns the user row or null. */
function login_user(string $who, string $password): ?array {
    $who = trim($who);
    if ($who === '') return null;
    if (str_contains($who, '@')) {
        $st = db()->prepare('SELECT * FROM users WHERE lower(email) = ?');
        $st->execute([strtolower($who)]);
    } else {
        $st = db()->prepare('SELECT * FROM users WHERE phone = ?');
        $st->execute([normalize_ph_phone($who) ?? '-']);
    }
    $u = $st->fetch();
    return ($u && password_verify($password, $u['password_hash'])) ? $u : null;
}

/** Keep the subscribers table in step with the account: on when SMS alerts are ticked, removed when not. */
function sync_subscriber(int $userId): void {
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$userId]);
    $u = $st->fetch();
    if (!$u) return;
    $st = $pdo->prepare('SELECT id FROM subscribers WHERE user_id = ?');
    $st->execute([$userId]);
    $sid = $st->fetchColumn();
    if (!$u['sms_optin'] || !$u['phone']) { if ($sid) forget_subscriber((int) $sid); return; }
    if (!$sid) { // adopt an older sign-up that used the same number
        $st = $pdo->prepare('SELECT id FROM subscribers WHERE phone = ? AND user_id IS NULL LIMIT 1');
        $st->execute([$u['phone']]);
        $sid = $st->fetchColumn();
    }
    if ($sid) {
        $pdo->prepare("UPDATE subscribers SET name = ?, phone = ?, email = ?, language = ?, municipality_id = ?, status = 'active', user_id = ? WHERE id = ?")
            ->execute([$u['name'], $u['phone'], $u['email'], $u['language'], $u['municipality_id'], $userId, $sid]);
    } else {
        $pdo->prepare("INSERT INTO subscribers (name, phone, email, language, status, consented_at, unsub_token, municipality_id, user_id) VALUES (?, ?, ?, ?, 'active', datetime('now'), ?, ?, ?)")
            ->execute([$u['name'], $u['phone'], $u['email'], $u['language'], new_token(), $u['municipality_id'], $userId]);
    }
}

function delete_user(int $id): void {
    $st = db()->prepare('SELECT id FROM subscribers WHERE user_id = ?');
    $st->execute([$id]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $sid) forget_subscriber((int) $sid);
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
}

/* ---- shared page frame for login, register and account pages ---- */
function page_head(string $title, ?array $user = null): void {
    $nav = $user
        ? '<a href="account.php">' . e($user['name']) . '</a><a href="logout.php">Log out</a>'
        : '<a href="login.php">Log in</a><a class="solid" href="register.php">Create account</a>';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . ' | BantayKlima</title>' . portal_assets('assets/') . '</head><body class="dashboard portal auth-body">'
        . '<nav class="topbar"><div class="nav-inner"><a class="brand" href="index.html"><span class="logo" aria-hidden="true"></span>BantayKlima</a>'
        . '<div class="navlinks"><a href="index.html">Dashboard</a>' . $nav . theme_button() . '</div></div></nav>';
}

function portal_assets(string $base): string {
    return '<script src="' . e($base) . 'theme.js"></script><link rel="stylesheet" href="' . e($base) . 'style.css">'
        . '<link rel="stylesheet" href="' . e($base) . 'dashboard.css"><link rel="stylesheet" href="' . e($base) . 'portal.css">';
}

function theme_button(): string {
    return '<button data-theme-toggle type="button" aria-pressed="false"><span aria-hidden="true">◐</span><span data-theme-label>Dark mode</span></button>';
}
function page_foot(): void {
    echo '<footer>BantayKlima is a student project. It is not an official service of PAGASA, DOST or any local government unit. '
        . 'Always follow official bulletins. In an emergency, call 911. <a href="privacy.php">Privacy notice</a></footer><script src="assets/auth.js"></script></body></html>';
}
