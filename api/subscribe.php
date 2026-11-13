<?php
require __DIR__ . '/../lib/bootstrap.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'POST only.'], 405);
cleanup_signups();

// Spam trap: real people never see or fill this hidden field, bots usually do. Pretend it worked and save nothing.
if (trim($_POST['website'] ?? '') !== '') json_out(['ok' => 'You are subscribed.']);

if (($_POST['consent'] ?? '') !== '1') json_out(['error' => 'Please tick the box to agree to the privacy notice.'], 422);
if (rate_limited('signup:' . client_ip(), (int) cfg('signup_per_hour'), 3600)) {
    json_out(['error' => 'Too many sign-ups from this connection. Please try again later.'], 429);
}

$name  = trim($_POST['name'] ?? '');
$mid   = (int) ($_POST['municipality_id'] ?? 0);
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$lang  = ($_POST['language'] ?? 'en') === 'fil' ? 'fil' : 'en';

if ($name === '' || mb_strlen($name) > 100) json_out(['error' => 'Please enter your name.'], 422);
if ($phone === '' && $email === '')       json_out(['error' => 'Enter a mobile number or an email.'], 422);
if ($phone !== '') {
    $phone = normalize_ph_phone($phone) ?? '';
    if ($phone === '') json_out(['error' => 'Mobile number should look like 09XXXXXXXXX.'], 422);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'That email does not look right.'], 422);
$email = strtolower($email);

$check = db()->prepare('SELECT COUNT(*) FROM municipalities WHERE id = ?');
$check->execute([$mid]);
if (!$check->fetchColumn()) json_out(['error' => 'Please choose your municipality.'], 422);

// The same phone (or the same email, for email-only sign-ups) can only be subscribed once per municipality.
if ($phone !== '') {
    $st = db()->prepare('SELECT * FROM subscribers WHERE municipality_id = ? AND phone = ? LIMIT 1');
    $st->execute([$mid, $phone]);
} else {
    $st = db()->prepare("SELECT * FROM subscribers WHERE municipality_id = ? AND email = ? AND (phone IS NULL OR phone = '') LIMIT 1");
    $st->execute([$mid, $email]);
}
$existing = $st->fetch();
if ($existing && $existing['status'] === 'active') json_out(['error' => 'This contact is already subscribed for that municipality.'], 409);

$status = cfg('require_confirmation') ? 'pending' : 'active';
if ($existing) { // an unconfirmed sign-up: update it and send a fresh code
    $id = (int) $existing['id'];
    db()->prepare('UPDATE subscribers SET name = ?, email = ?, language = ?, consented_at = CURRENT_TIMESTAMP WHERE id = ?')
        ->execute([$name, $email ?: null, $lang, $id]);
} else {
    db()->prepare('INSERT INTO subscribers (name, phone, email, language, status, consented_at, unsub_token, municipality_id)
        VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?)')
        ->execute([$name, $phone ?: null, $email ?: null, $lang, $status, new_token(), $mid]);
    $id = (int) db()->lastInsertId();
}

if ($status === 'active') json_out(['ok' => 'You are subscribed. We will alert you when your risk level changes.']);

$target = $phone ?: $email;
$r = issue_code('confirm', $target);
if (!$r['ok']) $message = $r['error'];
elseif ($r['delivered']) $message = 'We sent a 6-digit code to ' . mask_target($target) . '. Enter it below to finish.';
else $message = 'Codes cannot be delivered on this server yet. Please ask the administrator to confirm your sign-up.';

json_out(['ok' => true, 'needs_code' => true, 'subscriber_id' => $id, 'message' => $message]);
