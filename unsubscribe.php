<?php
require __DIR__ . '/lib/bootstrap.php';

$msg = ''; $error = ''; $step = 'start'; $phone = '';
$token = preg_replace('/[^a-f0-9]/', '', strtolower((string) ($_GET['t'] ?? $_POST['t'] ?? '')));
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? '') : '';

$subscriber = null;
if ($token !== '') {
    $st = db()->prepare('SELECT s.*, m.name AS town FROM subscribers s JOIN municipalities m ON m.id = s.municipality_id WHERE s.unsub_token = ?');
    $st->execute([$token]);
    $subscriber = $st->fetch() ?: null;
    if (!$subscriber && !$action) $error = 'This link is no longer valid. You may already have been removed.';
}

if ($action === 'remove' && $subscriber) {
    // Removing needs a button click (not just opening the link), so email scanners cannot unsubscribe people by accident.
    forget_subscriber((int) $subscriber['id']);
    $msg = 'You have been removed. You will not receive more BantayKlima alerts.';
    $subscriber = null; $step = 'done';
} elseif ($action === 'request') {
    $phone = normalize_ph_phone((string) ($_POST['phone'] ?? '')) ?? '';
    if ($phone === '') {
        $error = 'Enter your mobile number, like 09171234567.';
    } elseif (rate_limited('unsub:' . client_ip(), 5, 3600)) {
        $error = 'Too many tries. Please try again later.';
    } else {
        $st = db()->prepare('SELECT COUNT(*) FROM subscribers WHERE phone = ?');
        $st->execute([$phone]);
        if ((int) $st->fetchColumn() > 0) issue_code('unsubscribe', $phone); // only subscribed numbers get a text
        $msg = 'If that number is subscribed, we sent it a 6-digit code.'; // same answer either way
        $step = 'verify';
    }
} elseif ($action === 'verify') {
    $phone = normalize_ph_phone((string) ($_POST['phone'] ?? '')) ?? '';
    if ($phone === '' || rate_limited('unsubverify:' . client_ip(), 20, 3600)) {
        $error = 'Please start again.';
    } else {
        $r = check_code('unsubscribe', $phone, preg_replace('/\D/', '', (string) ($_POST['code'] ?? '')));
        if ($r['ok']) {
            $st = db()->prepare('SELECT id FROM subscribers WHERE phone = ?');
            $st->execute([$phone]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $sid) forget_subscriber((int) $sid);
            forget_contact($phone, null);
            $msg = 'Done. That number has been removed from all BantayKlima alerts.';
            $step = 'done';
        } else {
            $error = $r['error']; $step = 'verify';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Stop alerts | BantayKlima</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header><h1>BantayKlima</h1><p>Stop alerts</p></header>
<main>
  <section class="card">
    <?php if ($msg): ?><p class="ok"><?= e($msg) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="err"><?= e($error) ?></p><?php endif; ?>

    <?php if ($subscriber): ?>
      <h2>Stop alerts for <?= e($subscriber['town']) ?>?</h2>
      <p>Hello <?= e($subscriber['name']) ?>. Press the button to stop BantayKlima alerts for <?= e($subscriber['town']) ?>.</p>
      <form method="post">
        <input type="hidden" name="t" value="<?= e($token) ?>">
        <input type="hidden" name="action" value="remove">
        <button type="submit">Stop my alerts</button>
      </form>
    <?php elseif ($step === 'verify'): ?>
      <h2>Enter your code</h2>
      <form method="post">
        <input type="hidden" name="action" value="verify">
        <input type="hidden" name="phone" value="<?= e($phone) ?>">
        <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="6-digit code" required>
        <button type="submit">Stop my alerts</button>
      </form>
    <?php elseif ($step !== 'done'): ?>
      <h2>Stop text alerts</h2>
      <p>Enter the mobile number that gets the alerts. We will text it a code to make sure it is yours.</p>
      <form method="post">
        <input type="hidden" name="action" value="request">
        <input name="phone" placeholder="Mobile number (09XXXXXXXXX)" required>
        <button type="submit">Send me a code</button>
      </form>
      <p class="small">Email alerts have a "stop" link at the bottom of every email. You can also write to <?= e(cfg('contact_email')) ?>.</p>
    <?php endif; ?>
    <p><a href="index.html">Back to the dashboard</a></p>
  </section>
</main>
</body>
</html>
