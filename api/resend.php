<?php
require __DIR__ . '/../lib/bootstrap.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'POST only.'], 405);

$st = db()->prepare("SELECT * FROM subscribers WHERE id = ? AND status = 'pending'");
$st->execute([(int) ($_POST['subscriber_id'] ?? 0)]);
$s = $st->fetch();
if (!$s) json_out(['error' => 'This sign-up was not found, or it is already confirmed.'], 404);

$target = subscriber_target($s);
if (rate_limited('resend:' . $target, 5, 3600)) json_out(['error' => 'Too many codes requested. Please try again later.'], 429);

$r = issue_code('confirm', $target);
if (!$r['ok']) json_out(['error' => $r['error']], 429);
json_out(['ok' => $r['delivered'] ? 'We sent a new code to ' . mask_target($target) . '.'
    : 'Codes cannot be delivered on this server yet. Please ask the administrator to confirm your sign-up.']);
