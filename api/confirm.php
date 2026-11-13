<?php
require __DIR__ . '/../lib/bootstrap.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'POST only.'], 405);
if (rate_limited('confirm:' . client_ip(), 20, 3600)) json_out(['error' => 'Too many tries. Please try again later.'], 429);

$id = (int) ($_POST['subscriber_id'] ?? 0);
$code = preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''));

$st = db()->prepare("SELECT s.*, m.name AS town FROM subscribers s JOIN municipalities m ON m.id = s.municipality_id WHERE s.id = ? AND s.status = 'pending'");
$st->execute([$id]);
$s = $st->fetch();
if (!$s) json_out(['error' => 'This sign-up was not found, or it is already confirmed.'], 404);
if (strlen($code) !== 6) json_out(['error' => 'Enter the 6-digit code.'], 422);

$r = check_code('confirm', subscriber_target($s), $code);
if (!$r['ok']) json_out(['error' => $r['error']], 422);

db()->prepare("UPDATE subscribers SET status = 'active' WHERE id = ?")->execute([$id]);
if ($s['email']) {
    send_email($s['email'], 'You are subscribed to BantayKlima alerts',
        "Hello {$s['name']}, you will now get BantayKlima climate alerts for {$s['town']}." . unsub_footer($s), false,
        "Welcome email for {$s['town']} (name not kept in this log)");
}
json_out(['ok' => 'Confirmed! You will now receive alerts for ' . $s['town'] . '.']);
