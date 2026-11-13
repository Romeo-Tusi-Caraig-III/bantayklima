<?php
require __DIR__ . '/auth.php';

$msg = $_SESSION['admin_status'] ?? '';
unset($_SESSION['admin_status']);
$error = '';
$parsed = null;          // result of reading a PAGASA table, shown for confirmation
$thresholdErrors = [];
$activityActions = [
    'upload_pdf' => 'Published a PAGASA PDF', 'delete_doc' => 'Removed a PAGASA PDF',
    'advisory' => 'Entered a PAGASA advisory', 'pagasa_save' => 'Imported a PAGASA advisory',
    'refresh' => 'Updated climate assessments', 'announce' => 'Published an announcement',
    'delete_announcement' => 'Removed an announcement', 'sms_save' => 'Changed SMS settings',
    'email_save' => 'Changed email settings', 'sms_test' => 'Ran an SMS test', 'email_test' => 'Ran an email test',
    'password_change' => 'Changed the admin password', 'thresholds_save' => 'Changed risk thresholds',
    'thresholds_reset' => 'Reset risk thresholds', 'backup_now' => 'Created a backup',
    'contact_add' => 'Added an emergency contact', 'contact_delete' => 'Removed an emergency contact',
    'confirm_subscriber' => 'Confirmed a subscription', 'delete_subscriber' => 'Removed a subscription',
    'delete_user' => 'Removed a resident account',
];
$activityOutcome = 'Completed';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $msg = '';
    $action = $_POST['action'] ?? '';
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $error = 'This page expired. Reload it and try again.';
    } else {
        switch ($action) {
            case 'upload_pdf': // the simple path: save the PDF and publish it, nothing else has to succeed first
                $r = doc_save($_FILES['pdf'] ?? null, (string) ($_POST['title'] ?? ''), (string) ($_POST['doc_month'] ?? ''));
                if ($r['ok']) $msg = 'Published. Residents can read this PDF on the dashboard now.'; else $error = $r['error'];
                break;

            case 'delete_doc':
                doc_delete((int) ($_POST['id'] ?? 0));
                $msg = 'PDF removed. Residents can no longer open it.';
                break;

            case 'advisory': // manual PAGASA status
                $statuses = ['normal', 'above_normal', 'dry_condition', 'dry_spell', 'drought'];
                $alert = trim($_POST['alert_status'] ?? '');
                $rain = $_POST['rainfall_status'] ?? '';
                $month = $_POST['month'] ?? '';
                if ($alert === '' || !in_array($rain, $statuses, true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $month)) {
                    $error = 'Please fill in the alert status, rainfall status and month.';
                } else {
                    backup_db('before-advisory');
                    db()->prepare('INSERT INTO advisories (province, alert_status, rainfall_status, month, notes, source_url) VALUES (?, ?, ?, ?, ?, ?)')
                        ->execute([cfg('province'), $alert, $rain, $month, trim($_POST['notes'] ?? ''), trim($_POST['source_url'] ?? '')]);
                    $msg = 'PAGASA status saved. Press "Update risk levels" under Settings to apply it.';
                }
                break;

            case 'pagasa_preview':
            case 'pagasa_save':
                $parsed = parse_dds((string) ($_POST['table_text'] ?? ''), cfg('province'));
                $alertStatus = trim($_POST['alert_status'] ?? '');
                if (!$parsed['ok']) {
                    $error = $parsed['error'];
                    $parsed = null;
                } elseif ($alertStatus === '') {
                    $error = 'Please choose or type the PAGASA alert status (for example El Niño Advisory).';
                    $parsed = null;
                } elseif ($action === 'pagasa_save') {
                    backup_db('before-pagasa-import');
                    db()->prepare('INSERT INTO advisories (province, alert_status, rainfall_status, month, issued, notes, source_url) VALUES (?, ?, ?, ?, ?, ?, ?)')
                        ->execute([cfg('province'), $alertStatus, $parsed['status'], $parsed['month'], $parsed['issued'],
                            'Imported from the PAGASA drought and dry spell table. ' . $parsed['summary'], trim($_POST['source_url'] ?? '')]);
                    $msg = cfg('province') . ' saved as "' . str_replace('_', ' ', $parsed['status']) . '" (PAGASA table for ' . date('F Y', strtotime($parsed['month'])) . ').';
                    if (!empty($_POST['then_refresh'])) {
                        $refresh = refresh_all(null);
                        $msg .= ' ' . $refresh;
                        $activityActions[$action] = 'Imported a PAGASA advisory and requested an assessment';
                        if (!str_starts_with($refresh, 'Assessed ') || str_contains($refresh, ' Warning:')) $activityOutcome = 'Advisory saved; assessment needs review';
                    } else $msg .= ' Press "Update risk levels" under Settings to apply it.';
                    $parsed = null;
                }
                break;

            case 'refresh':
                $mode = $_POST['mode'] ?? 'live';
                $msg = refresh_all($mode === 'live' ? null : $mode);
                if ($mode !== 'live') $activityActions[$action] = 'Ran a demo climate assessment';
                if (!str_starts_with($msg, 'Assessed ')) $activityOutcome = 'Not run';
                elseif (str_contains($msg, ' Warning:')) $activityOutcome = 'Completed with incomplete data';
                break;

            case 'announce':
                $r = announce((string) ($_POST['title'] ?? ''), (string) ($_POST['body'] ?? ''), (int) ($_POST['municipality_id'] ?? 0) ?: null, !empty($_POST['send']), !empty($_POST['send_email']));
                if (!$r['ok']) { $error = $r['error']; break; }
                if ((!empty($_POST['send']) && ($r['mode'] !== 'live' || $r['sent'] < $r['people']))
                    || (!empty($_POST['send_email']) && ($r['email_mode'] !== 'live' || $r['email_sent'] < $r['email_people']))) {
                    $activityOutcome = 'Published; check delivery results';
                }
                $msg = 'Posted on the dashboard.';
                if (!empty($_POST['send_email'])) {
                    if (!$r['email_people']) $msg .= ' Nobody in that area has an email address on file, so no emails were sent.';
                    elseif ($r['email_mode'] === 'log') $msg .= " Email was not sent: no email account is connected yet (Settings > Email). {$r['email_people']} message(s) were only recorded.";
                    else $msg .= " Emailed {$r['email_sent']} of {$r['email_people']} people" . ($r['email_failed'] ? ", {$r['email_failed']} failed (see Recent messages)." : '.');
                }
                if (!empty($_POST['send'])) {
                    if (!$r['people']) $msg .= ' Nobody has SMS alerts on in that area yet, so no texts were sent.';
                    elseif ($r['mode'] === 'log') $msg .= " SMS was not sent: no SMS key is saved yet (Settings > SMS). {$r['people']} message(s) were only recorded.";
                    else $msg .= " Texted {$r['sent']} of {$r['people']} people" . ($r['failed'] ? ", {$r['failed']} failed (see Recent messages)." : '.');
                }
                break;

            case 'delete_announcement':
                db()->prepare('DELETE FROM announcements WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
                $msg = 'Announcement deleted from the dashboard.';
                break;

            case 'sms_save':
                $saved = (array) setting_get('sms', []);
                $key = trim((string) ($_POST['sms_key'] ?? ''));
                if (!empty($_POST['sms_remove'])) $saved['key'] = ''; elseif ($key !== '') $saved['key'] = $key;
                $saved['sender'] = trim((string) ($_POST['sms_sender'] ?? ''));
                if ($saved['sender'] !== '' && !preg_match('/^[A-Za-z0-9 ]{3,11}$/', $saved['sender'])) { $error = 'Sender name: 3 to 11 letters or numbers, no symbols.'; break; }
                setting_set('sms', $saved);
                $msg = 'SMS settings saved. Send a test text to check them.';
                break;

            case 'sms_test':
                $to = normalize_ph_phone((string) ($_POST['phone'] ?? ''));
                if (!$to) {
                    $error = 'Enter a valid mobile number, like 09171234567.';
                } else {
                    $before = last_message_id();
                    send_sms_batch([$to], 'BantayKlima SMS check: your alerts are working.');
                    $msg = "Test SMS to $to. " . sms_result_text(sms_summary_since($before));
                }
                break;

            case 'email_save':
                $old = (array) setting_get('smtp', []);
                $host = trim((string) ($_POST['smtp_host'] ?? ''));
                $port = (int) ($_POST['smtp_port'] ?? 0);
                $secure = in_array($_POST['smtp_secure'] ?? '', ['ssl', 'tls', 'none'], true) ? $_POST['smtp_secure'] : 'ssl';
                $user = trim((string) ($_POST['smtp_user'] ?? ''));
                $pass = (string) ($_POST['smtp_pass'] ?? '');
                $from = trim((string) ($_POST['smtp_from'] ?? ''));
                if (!empty($_POST['smtp_remove'])) { setting_set('smtp', []); $msg = 'Email account removed. Emails will only be recorded.'; break; }
                if ($host === '' || !preg_match('/^[A-Za-z0-9.-]+$/', $host)) { $error = 'Enter the mail server name, like smtp.gmail.com.'; break; }
                if ($port < 1 || $port > 65535) { $error = 'Enter the port number (465 for SSL, 587 for TLS).'; break; }
                if (!filter_var($from !== '' ? $from : $user, FILTER_VALIDATE_EMAIL)) { $error = 'Enter the email address that messages are sent from.'; break; }
                setting_set('smtp', ['host' => $host, 'port' => $port, 'secure' => $secure, 'user' => $user, 'pass' => $pass !== '' ? $pass : ($old['pass'] ?? ''),
                    'from_email' => $from, 'from_name' => mb_substr(trim((string) ($_POST['smtp_name'] ?? '')) ?: 'BantayKlima', 0, 60)]);
                $msg = 'Email settings saved. Send a test email to check them.';
                break;

            case 'email_test':
                $to = trim((string) ($_POST['to'] ?? ''));
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $error = 'Enter a valid email address to send the test to.'; break; }
                $before = last_message_id();
                $status = send_email($to, 'BantayKlima email check', "This is a test from BantayKlima.\n\nIf you can read this, email alerts and announcements will reach residents.");
                $why = (string) db()->query('SELECT detail FROM messages WHERE id > ' . $before . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
                if ($status === 'sent') $msg = "Test email sent to $to. Check the inbox (and the spam folder).";
                elseif ($status === 'logged only') $error = 'No email account is connected yet, so nothing was sent. Fill in the form above first.';
                else $error = 'The test email failed. ' . $why;
                break;

            case 'password_change':
                $new = (string) ($_POST['new_password'] ?? '');
                if (!admin_credentials_ok((string) cfg('admin_user'), (string) ($_POST['current_password'] ?? ''))) $error = 'Your current password is not right.';
                elseif (strlen($new) < 8) $error = 'The new password needs at least 8 characters.';
                elseif ($new !== (string) ($_POST['new_password2'] ?? '')) $error = 'The two new passwords are different.';
                else { setting_set('admin_password_hash', password_hash($new, PASSWORD_DEFAULT)); $msg = 'Admin password changed.'; }
                break;

            case 'confirm_subscriber':
                db()->prepare("UPDATE subscribers SET status = 'active' WHERE id = ?")->execute([(int) ($_POST['id'] ?? 0)]);
                $msg = 'Subscriber confirmed.';
                break;

            case 'delete_subscriber':
                forget_subscriber((int) ($_POST['id'] ?? 0));
                $msg = 'Subscriber removed, with their message records.';
                break;

            case 'delete_user':
                delete_user((int) ($_POST['id'] ?? 0));
                $msg = 'Account removed, with its SMS subscription.';
                break;

            case 'thresholds_save':
                [$clean, $thresholdErrors] = risk_validate_thresholds((array) ($_POST['t'] ?? []));
                if ($thresholdErrors) {
                    $error = 'The thresholds were not saved. Please fix the problems listed under "Risk thresholds".';
                } else {
                    setting_set('thresholds', $clean);
                    $msg = 'Thresholds saved. Press "Update risk levels" to apply them.';
                }
                break;

            case 'thresholds_reset':
                setting_set('thresholds', []);
                $msg = 'Thresholds reset to the defaults. Press "Update risk levels" to apply them.';
                break;

            case 'contact_add':
                $label = trim($_POST['label'] ?? '');
                $phone = trim($_POST['contact_phone'] ?? '');
                $mid = (int) ($_POST['municipality_id'] ?? 0);
                $exists = $mid === 0 || town_exists($mid);
                if ($label === '' || mb_strlen($label) > 40 || !preg_match('/^[0-9+() \-]{3,20}$/', $phone) || !$exists) {
                    $error = 'Enter a short label (like "MDRRMO") and a phone number using digits, spaces, + ( ) or -.';
                } else {
                    db()->prepare('INSERT INTO contacts (municipality_id, label, phone) VALUES (?, ?, ?)')->execute([$mid ?: null, $label, $phone]);
                    $msg = 'Emergency contact added.';
                }
                break;

            case 'contact_delete':
                db()->prepare('DELETE FROM contacts WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
                $msg = 'Emergency contact removed.';
                break;

            case 'backup_now':
                $path = backup_db('manual');
                if ($path) $msg = 'Backup saved: ' . basename($path); else $error = 'The backup could not be saved.';
                break;
        }
        if (isset($activityActions[$action]) && ($msg !== '' || $error !== '')) {
            db()->prepare('INSERT INTO admin_activity (actor, action, outcome) VALUES (?, ?, ?)')->execute([
                $_SESSION['admin_actor'] ?? (string) cfg('admin_user'), $activityActions[$action],
                $error === '' ? $activityOutcome : 'Rejected',
            ]);
        }
    }
}

$lastAction = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? '') : '';
$startTab = match (true) {
    in_array($lastAction, ['upload_pdf', 'delete_doc', 'advisory', 'pagasa_preview', 'pagasa_save'], true) => 'pagasa',
    in_array($lastAction, ['announce', 'delete_announcement'], true) => 'announce',
    in_array($lastAction, ['confirm_subscriber', 'delete_subscriber', 'delete_user', 'contact_add', 'contact_delete'], true) => 'people',
    in_array($lastAction, ['refresh', 'thresholds_save', 'thresholds_reset', 'backup_now', 'sms_save', 'sms_test', 'email_save', 'email_test', 'password_change'], true) => 'settings',
    default => 'home',
};
if ($lastAction !== '' && $msg !== '' && $error === '') {
    $_SESSION['admin_status'] = $msg;
    header('Location: index.php#' . $startTab, true, 303);
    exit;
}

$subscribers = (int) db()->query("SELECT COUNT(*) FROM subscribers WHERE status = 'active'")->fetchColumn();
$st = db()->prepare('SELECT * FROM advisories WHERE province = ? ORDER BY id DESC LIMIT 6');
$st->execute([cfg('province')]);
$advisories = $st->fetchAll();
$towns = db()->query('SELECT id, name FROM municipalities ORDER BY name')->fetchAll();
$docs = doc_list(20);
$announcements = db()->query('SELECT a.*, m.name AS town FROM announcements a LEFT JOIN municipalities m ON m.id = a.municipality_id ORDER BY a.id DESC LIMIT 10')->fetchAll();
$activity = db()->query('SELECT actor, action, outcome, created_at FROM admin_activity ORDER BY id DESC LIMIT 50')->fetchAll();
$emailLive = email_connected();
$smtp = smtp_cfg();
$smsLive = sms_setting('key') !== '';
$sms = $smsLive ? sms_account() : null;
$outbox = db()->query('SELECT * FROM messages ORDER BY id DESC LIMIT 15')->fetchAll();
$userCount = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
$userRows = db()->query('SELECT u.*, m.name AS town FROM users u JOIN municipalities m ON m.id = u.municipality_id ORDER BY u.id DESC LIMIT 30')->fetchAll();
$people = db()->query('SELECT s.*, m.name AS town FROM subscribers s JOIN municipalities m ON m.id = s.municipality_id ORDER BY s.id DESC LIMIT 20')->fetchAll();
$contactRows = db()->query('SELECT c.*, m.name AS town FROM contacts c LEFT JOIN municipalities m ON m.id = c.municipality_id ORDER BY c.id')->fetchAll();
$due = pagasa_due($advisories[0] ?? null);
$thresholds = array_merge(risk_default_thresholds(), (array) setting_get('thresholds', []));
if ($thresholdErrors) $thresholds = array_merge($thresholds, array_map('strval', (array) ($_POST['t'] ?? [])));
$csrf = e($_SESSION['csrf']);
$audience = [0 => sms_audience(null)]; $emailAudience = [0 => email_audience(null)];
foreach ($towns as $t) { $audience[(int) $t['id']] = sms_audience((int) $t['id']); $emailAudience[(int) $t['id']] = email_audience((int) $t['id']); }
$thisMonth = date('Y-m');
$hasThisMonth = false;
foreach ($docs as $d) if ($d['doc_month'] === $thisMonth) $hasThisMonth = true;
$key = sms_setting('key');
$areaOptions = '<option value="0">All of ' . e(cfg('province')) . '</option>';
foreach ($towns as $t) $areaOptions .= '<option value="' . (int) $t['id'] . '"' .
    ($lastAction === 'announce' && (int) ($_POST['municipality_id'] ?? 0) === (int) $t['id'] ? ' selected' : '') . '>' . e($t['name']) . '</option>';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>BantayKlima Admin</title>
<?= portal_assets('../assets/') ?>
</head>
<body class="dashboard portal" data-start="<?= e($startTab) ?>"<?= $_SERVER['REQUEST_METHOD'] === 'POST' ? ' data-posted="1"' : '' ?>>
<header class="topbar"><div class="nav-inner">
  <a class="brand" href="index.php"><span class="logo" aria-hidden="true"></span>BantayKlima <span class="admin-brand">Admin</span></a>
  <div class="navlinks"><?= theme_button() ?><a href="../index.html">View site</a><a href="logout.php">Log out</a></div>
</div></header>
<div class="admin-layout">
  <nav class="admin-nav" aria-label="Admin sections">
    <a href="#home" data-go="home">Overview</a><a href="#announce" data-go="announce">Announcements</a><a href="#pagasa" data-go="pagasa">PAGASA</a>
    <a href="#people" data-go="people">People</a><a href="#settings" data-go="settings">Settings</a>
    <a href="#activity" data-go="activity">Activity history</a>
    <a href="stats.php">Statistics &amp; exports</a><a href="check.php">System check</a>
  </nav>
<main class="admin-workspace">
  <header class="portal-heading">
    <div><h1 id="admin-section-title">Overview</h1><p><?= e(refresh_status_line()) ?></p></div>
    <a class="btn ghost" href="#settings/refresh-risk">Update risk levels</a>
  </header>
  <?php if ($msg): ?><p class="ok" role="status"><?= e($msg) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="err" role="alert"><?= e($error) ?></p><?php endif; ?>

  <!-- ============ HOME ============ -->
  <section class="card" data-tab="home">
    <h2>What needs doing</h2>
    <?php
    $todo = [
        [!admin_password_weak(), 'Choose your own admin password', 'settings/admin-password', 'Set password'],
        [$smsLive, 'Connect SMS so announcements reach phones', 'settings/connect-sms', 'Connect SMS'],
        [$emailLive, 'Connect email for announcements (optional)', 'settings/connect-email', 'Connect email'],
        [$hasThisMonth, 'Upload this month\'s PAGASA PDF (' . date('F Y') . ')', 'pagasa/upload-advisory', 'Upload PDF'],
        [(bool) $contactRows, 'Add local emergency numbers (MDRRMO)', 'people/emergency-numbers', 'Add numbers'],
    ];
    foreach ($todo as [$done, $label, $tab, $btn]): ?>
      <div class="item"><span><span class="pill <?= $done ? 'good' : 'bad' ?>"><?= $done ? 'Done' : 'To do' ?></span> <?= e($label) ?></span>
        <?php if (!$done): ?><a class="btn ghost" href="#<?= $tab ?>"><?= e($btn) ?></a><?php endif; ?></div>
    <?php endforeach; ?>
    <?php if ($due): ?><p class="err">The <?= e($due) ?> PAGASA drought table should be out. Upload it under PAGASA.</p><?php endif; ?>
    <?php foreach (data_health_warnings() as $w): ?><p class="err"><?= e($w) ?></p><?php endforeach; ?>
  </section>

  <section class="card" data-tab="home">
    <h2>At a glance</h2>
    <div class="stats">
      <div><strong><?= $userCount ?></strong><span>registered residents</span></div>
      <div><strong><?= $subscribers ?></strong><span>get SMS alerts</span></div>
      <div><strong><?= count($docs) ?></strong><span>PDFs residents can read</span></div>
      <div><strong><?= $smsLive ? 'Live' : 'Log only' ?></strong><span>SMS mode</span></div>
    </div>
    <div class="actions">
      <a class="btn big" href="#announce">Send an announcement<small>Post it and text residents</small></a>
      <a class="btn big" href="#pagasa">Upload a PAGASA PDF<small>Residents can read it right away</small></a>
      <a class="btn big" href="#people">See who signed up<small>Residents and emergency numbers</small></a>
    </div>
  </section>

  <!-- ============ ANNOUNCEMENTS ============ -->
  <div class="admin-columns" data-tab="announce">
    <div class="admin-stack">
  <section class="card" data-tab="announce">
    <h2>New announcement</h2>
    <p class="small">Choose an area and publish to the dashboard. Select SMS or email to also notify eligible residents.</p>
    <form method="post" id="ann-form" data-audience="<?= e(json_encode($audience)) ?>" data-email-audience="<?= e(json_encode($emailAudience)) ?>" data-live="<?= $smsLive ? '1' : '0' ?>" data-email-live="<?= $emailLive ? '1' : '0' ?>">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="announce">
      <label class="field">Title <input name="title" maxlength="100" placeholder="Class suspension tomorrow" value="<?= $lastAction === 'announce' ? e($_POST['title'] ?? '') : '' ?>" required></label>
      <label class="field">Message <textarea name="body" id="ann-body" rows="4" maxlength="500" placeholder="Write in English or Filipino. Keep it short if it goes by SMS." required><?= $lastAction === 'announce' ? e($_POST['body'] ?? '') : '' ?></textarea></label>
      <label class="field">Who should see it <select name="municipality_id" id="ann-area"><?= $areaOptions ?></select></label>
      <label class="check"><input type="checkbox" name="send" value="1" id="ann-send"<?= $lastAction === 'announce' && !empty($_POST['send']) ? ' checked' : '' ?>><span id="ann-send-text">Also send by SMS</span></label>
      <label class="check"><input type="checkbox" name="send_email" value="1" id="ann-email"<?= $lastAction === 'announce' && !empty($_POST['send_email']) ? ' checked' : '' ?>><span id="ann-email-text">Also send by email</span></label>
      <p class="small count-line" id="ann-count"></p>
      <button type="submit" id="ann-btn">Preview announcement</button>
    </form>
    <dialog id="ann-preview" aria-labelledby="ann-preview-heading">
      <h2 id="ann-preview-heading">Preview announcement</h2>
      <article class="note mine">
        <p class="small" id="ann-preview-area"></p>
        <h3 id="ann-preview-title"></h3>
        <p id="ann-preview-body"></p>
      </article>
      <dl id="ann-preview-channels"></dl>
      <div class="row"><button type="button" class="secondary" id="ann-edit" autofocus>Back to editing</button><button type="button" id="ann-publish">Publish</button></div>
    </dialog>
  </section>
    </div>
    <div class="admin-stack">
  <section class="card" data-tab="announce">
    <h2>SMS status</h2>
    <?php if (!$smsLive): ?>
      <p><span class="pill bad">Log only</span> No SMS key is saved, so texts are recorded but not sent. <a href="#settings">Connect SMS in Settings</a>.</p>
    <?php elseif ($sms && $sms['error']): ?>
      <p class="err"><?= e($sms['error']) ?> <a href="#settings">Check the key in Settings</a>.</p>
    <?php else: ?>
      <p><span class="pill good">Live</span> Texts are really sent. Credits left: <strong><?= e($sms['balance'] ?? '?') ?></strong>. Sent in the last 24 hours: <?= sms_sent_last_day() ?> (limit <?= (int) cfg('sms_daily_cap') ?: 'none' ?>).</p>
    <?php endif; ?>
  </section>
  <section class="card" data-tab="announce">
    <h2>Past announcements</h2>
    <?php foreach ($announcements as $a): ?>
      <form method="post" class="item">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="delete_announcement"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
        <span><strong><?= e($a['title']) ?></strong><br>
          <span class="small"><?= e($a['town'] ?? 'All of ' . cfg('province')) ?> &middot; <?= e(local_time($a['created_at'])) ?> PHT &middot; Published</span>
          <?php foreach (['sms' => 'SMS', 'email' => 'Email'] as $channel => $label):
              $mode = $a[$channel . '_mode'];
              $people = (int) $a[$channel . '_people'];
              $sent = (int) $a[$channel . '_sent'];
              $failed = (int) $a[$channel . '_failed'];
              $delivery = match ($mode) {
                  'live' => "$sent of $people accepted by provider, $failed failed",
                  'log' => "$people recorded only (channel disconnected)",
                  default => $a[$channel . '_requested'] ? 'No eligible recipients' : 'No delivery recorded',
              };
          ?><span class="small delivery-line"><?= e($label . ' · ' . $delivery) ?></span><?php endforeach; ?>
        </span>
        <button class="small-btn" data-confirm="Delete this announcement from the dashboard? Texts already sent cannot be recalled.">Delete</button>
      </form>
    <?php endforeach; if (!$announcements): ?><p>No announcements yet. Write the first one above.</p><?php endif; ?>
  </section>
  <section class="card" data-tab="announce">
    <details><summary>Recent messages (SMS and email log)</summary>
    <p class="small">Demo runs show "simulated": nothing is sent. One-time codes are never shown here.</p>
    <div class="scroll"><table>
      <thead><tr><th>When</th><th>To</th><th>Status</th><th>Message</th></tr></thead>
      <tbody>
      <?php foreach ($outbox as $o): ?>
        <tr><td><?= e(local_time($o['created_at'])) ?></td><td><?= e($o['channel']) ?>: <?= e($o['recipient']) ?></td>
            <td><strong><?= e($o['status']) ?></strong><?= $o['detail'] ? '<br><span class="small">' . e($o['detail']) . '</span>' : '' ?></td>
            <td><?= e(mb_substr($o['body'], 0, 90)) ?></td></tr>
      <?php endforeach; if (!$outbox): ?><tr><td colspan="4">No messages yet.</td></tr><?php endif; ?>
      </tbody>
    </table></div></details>
  </section>
    </div>
  </div>

  <!-- ============ PAGASA ============ -->
  <section class="card" data-tab="pagasa" id="upload-advisory" tabindex="-1">
    <h2>Upload the monthly PAGASA PDF</h2>
    <p class="small">Choose the PDF you downloaded from PAGASA. Residents can read it on the dashboard as soon as it uploads (even on phones). Nothing else has to be filled in first.</p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="upload_pdf">
      <label class="field">PDF file <input type="file" name="pdf" accept="application/pdf,.pdf" required></label>
      <label class="field">Month it covers <input type="month" name="doc_month" value="<?= e($thisMonth) ?>" required></label>
      <label class="field">Title (optional) <input name="title" maxlength="120" placeholder="PAGASA Drought and Dry Spell Assessment, <?= e(date('F Y')) ?>"></label>
      <button type="submit">Upload and publish</button>
    </form>
  </section>

  <section class="card" data-tab="pagasa">
    <h2>PDFs residents can read</h2>
    <?php foreach ($docs as $d): ?>
      <form method="post" class="item">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="delete_doc"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
        <span><strong><?= e($d['title']) ?></strong><br><span class="small"><?= e(human_size((int) $d['size'])) ?> &middot; <?= e(local_time($d['created_at'])) ?></span></span>
        <span class="row">
          <a class="btn ghost" href="../file.php?id=<?= (int) $d['id'] ?>" target="_blank" rel="noopener">Open</a>
          <button type="button" class="secondary" data-read-pdf="<?= (int) $d['id'] ?>">Read status from it</button>
          <button class="small-btn" data-confirm="Remove this PDF? Residents will no longer be able to open it.">Remove</button>
        </span>
      </form>
    <?php endforeach; if (!$docs): ?><p>No PDF yet. Upload one above.</p><?php endif; ?>
  </section>

  <section class="card" data-tab="pagasa">
    <h2>Update Laguna's PAGASA status</h2>
    <p class="small">Current: <strong><?= $advisories ? e($advisories[0]['alert_status']) . ', ' . e(str_replace('_', ' ', $advisories[0]['rainfall_status'])) . ' (' . e(date('F Y', strtotime($advisories[0]['month']))) . ')' : 'nothing yet' ?></strong>.
      The easy way: press "Read status from it" next to a PDF above. The app finds <?= e(cfg('province')) ?> in the table, and you check the result before it is saved.</p>
    <p id="pdf-msg" class="small" role="status"></p>
    <form method="post" id="import-form">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="pagasa_preview">
      <label class="field">PAGASA alert status
        <input name="alert_status" list="alerts" placeholder="e.g. El Niño Advisory" value="<?= e($_POST['alert_status'] ?? ($advisories[0]['alert_status'] ?? 'El Niño Advisory')) ?>" required></label>
      <datalist id="alerts"><option>El Niño Watch</option><option>El Niño Alert</option><option>El Niño Advisory</option><option>La Niña Watch</option><option>La Niña Alert</option><option>La Niña Advisory</option><option>ENSO-neutral</option></datalist>
      <input type="hidden" name="source_url" value="<?= e($_POST['source_url'] ?? ($advisories[0]['source_url'] ?? 'https://bagong.pagasa.dost.gov.ph/climate/el-nino-la-nina/advisories')) ?>">
      <details <?= isset($_POST['table_text']) && !$parsed ? 'open' : '' ?>><summary>Or paste the table text yourself</summary>
        <textarea name="table_text" id="table-text" rows="6" placeholder="Paste the text of the PAGASA assessment page here"><?= e($_POST['table_text'] ?? '') ?></textarea>
        <button type="submit" style="margin-top:8px">Read table</button></details>
    </form>
    <?php if ($parsed): ?>
      <div class="ok" style="margin-top:12px">
        <p><strong>Check this before saving</strong></p>
        <p>PAGASA table for <strong><?= e(date('F Y', strtotime($parsed['month']))) ?></strong><?= $parsed['issued'] ? ', issued ' . e($parsed['issued']) : '' ?>.</p>
        <p><?= e(cfg('province')) ?>: <strong><?= e($parsed['status'] === 'normal' ? 'not listed (normal)' : str_replace('_', ' ', $parsed['status'])) ?></strong></p>
        <p><?= e($parsed['summary']) ?> The provinces I read match PAGASA's counts.</p>
        <details><summary>See the provinces I read</summary>
          <?php foreach ($parsed['lists'] as $k => $names): ?>
            <p><strong><?= e(str_replace('_', ' ', $k)) ?> (<?= count($names) ?>):</strong> <?= e($names ? implode(', ', $names) : 'none') ?></p>
          <?php endforeach; ?>
        </details>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="pagasa_save">
          <input type="hidden" name="alert_status" value="<?= e($_POST['alert_status'] ?? '') ?>">
          <input type="hidden" name="source_url" value="<?= e($_POST['source_url'] ?? '') ?>">
          <input type="hidden" name="table_text" value="<?= e($_POST['table_text'] ?? '') ?>">
          <div class="row"><button type="submit" name="then_refresh" value="1">Save and update risk levels</button><button type="submit" class="secondary">Save only</button></div>
        </form>
      </div>
    <?php endif; ?>
    <details style="margin-top:12px"><summary>Enter the status by hand instead</summary>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="advisory">
        <input name="alert_status" list="alerts" placeholder="PAGASA alert status, e.g. El Niño Advisory" required>
        <select name="rainfall_status" required>
          <option value="normal">Normal rainfall</option><option value="above_normal">Above-normal rainfall</option><option value="dry_condition">Dry condition</option>
          <option value="dry_spell">Dry spell</option><option value="drought">Drought</option>
        </select>
        <label class="field">Advisory month <input type="date" name="month" required value="<?= e(date('Y-m-01')) ?>"></label>
        <textarea name="notes" rows="2" placeholder="Notes (optional)"></textarea>
        <input name="source_url" type="url" placeholder="Link to the PAGASA advisory (optional)">
        <button type="submit">Save status</button>
      </form>
    </details>
  </section>

  <!-- ============ PEOPLE ============ -->
  <div class="admin-columns" data-tab="people">
    <div class="admin-stack">
  <section class="card" data-tab="people">
    <h2>Registered residents (<?= $userCount ?>)</h2>
    <p class="small">Residents who made an account. Those with SMS alerts on also receive alerts and announcements.</p>
    <?php foreach ($userRows as $u): ?>
      <form method="post" class="item">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
        <span><strong><?= e($u['name']) ?></strong><br><span class="small"><?= e($u['town']) ?> &middot; <?= e(mask_target((string) $u['phone'])) ?> &middot; SMS <?= $u['sms_optin'] ? 'on' : 'off' ?></span></span>
        <button name="action" value="delete_user" class="small-btn" data-confirm="Remove this account?">Remove</button>
      </form>
    <?php endforeach; if (!$userRows): ?><p>No accounts yet. Share the site link so residents can register.</p><?php endif; ?>
  </section>
  <section class="card" data-tab="people">
    <h2>SMS subscribers</h2>
    <p class="small">Showing the latest 20 subscriptions.</p>
    <?php foreach ($people as $p): ?>
      <form method="post" class="item">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
        <span><strong><?= e($p['name']) ?></strong> <span class="small"><?= e($p['town']) ?> &middot; <?= e($p['phone'] ? mask_target($p['phone']) : 'no phone') ?> &middot; <?= e($p['status']) ?></span></span>
        <span class="row">
          <?php if ($p['status'] === 'pending'): ?><button name="action" value="confirm_subscriber" class="small-btn ok-btn">Confirm</button><?php endif; ?>
          <button name="action" value="delete_subscriber" class="small-btn" data-confirm="Remove this subscriber?">Remove</button>
        </span>
      </form>
    <?php endforeach; if (!$people): ?><p>No subscribers yet.</p><?php endif; ?>
  </section>
    </div>
    <div class="admin-stack">
  <section class="card" data-tab="people" id="emergency-numbers" tabindex="-1">
    <h2>Emergency numbers</h2>
    <p class="small">Shown on the dashboard and added to Warning and Severe emails. 911 is always shown. Add local numbers such as the MDRRMO.</p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="contact_add">
      <select name="municipality_id"><?= $areaOptions ?></select>
      <input name="label" maxlength="40" placeholder="Label, e.g. MDRRMO" required>
      <input name="contact_phone" placeholder="Phone number" required>
      <button type="submit">Add number</button>
    </form>
    <?php foreach ($contactRows as $c): ?>
      <form method="post" class="item">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="contact_delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
        <span><strong><?= e($c['label']) ?></strong>: <?= e($c['phone']) ?> <span class="small">(<?= e($c['town'] ?? 'All of ' . cfg('province')) ?>)</span></span>
        <button type="submit" class="small-btn">Remove</button>
      </form>
    <?php endforeach; ?>
  </section>
    </div>
  </div>

  <!-- ============ SETTINGS ============ -->
  <div class="admin-columns" data-tab="settings">
    <div class="admin-stack">
  <section class="card" data-tab="settings" id="connect-sms" tabindex="-1">
    <h2>Connect SMS (Semaphore)</h2>
    <ol class="steps small">
      <li>Create an account at semaphore.co and buy credits (1 credit = one 160-character text).</li>
      <li>Copy your API key from the Semaphore dashboard and paste it below.</li>
      <li>Optional: type a sender name you registered with Semaphore. Leave it empty to use their default.</li>
      <li>Send a test text to your own number.</li>
    </ol>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="sms_save">
      <label class="field">API key <input name="sms_key" type="password" autocomplete="off" placeholder="<?= $key ? 'Saved (ends in ' . e(substr($key, -4)) . '). Paste a new key to replace it.' : 'Paste your Semaphore API key' ?>"></label>
      <label class="field">Sender name (optional) <input name="sms_sender" maxlength="11" value="<?= e(sms_setting('sender')) ?>" placeholder="BantayKlima"></label>
      <?php if ($key): ?><label class="check"><input type="checkbox" name="sms_remove" value="1"><span>Remove the saved key (texts will only be recorded)</span></label><?php endif; ?>
      <button type="submit">Save SMS settings</button>
    </form>
    <form method="post" class="row" style="margin-top:12px">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="sms_test">
      <input name="phone" placeholder="Your mobile number (09XXXXXXXXX)" required>
      <button type="submit" class="secondary">Send test text</button>
    </form>
    <?php if ($sms && !$sms['error']): ?><p class="small">Credits left: <?= e($sms['balance'] ?? '?') ?>. Sender names: <?= e($sms['senders'] ? implode(', ', $sms['senders']) : 'none registered yet') ?>.</p><?php endif; ?>
  </section>
  <section class="card" data-tab="settings" id="admin-password" tabindex="-1">
    <h2>Admin password</h2>
    <?php if (admin_password_weak()): ?><p class="err">You are still using the default password. Choose your own now.</p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="password_change">
      <label class="field">Current password <input name="current_password" type="password" autocomplete="current-password" required></label>
      <label class="field">New password (8 or more characters) <input name="new_password" type="password" autocomplete="new-password" minlength="8" required></label>
      <label class="field">New password again <input name="new_password2" type="password" autocomplete="new-password" minlength="8" required></label>
      <button type="submit">Change password</button>
    </form>
  </section>
  <section class="card" data-tab="settings">
    <h2>Backups</h2>
    <p class="small">The whole database is one file. A backup is saved after each daily update and before each PAGASA update (newest <?= (int) cfg('backup_keep') ?> kept). Backups contain personal details, so store downloads safely.</p>
    <form method="post" class="row">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="backup_now">
      <button type="submit">Back up now</button><a class="btn ghost" href="backup.php">Download a backup</a>
    </form>
    <?php foreach (backup_list() as $b): ?><p class="small"><?= e($b['name']) ?> (<?= e(round($b['size'] / 1024)) ?> KB)</p><?php endforeach; ?>
  </section>
    </div>
    <div class="admin-stack">
  <section class="card" data-tab="settings" id="connect-email" tabindex="-1">
    <h2>Connect email</h2>
    <p><span class="pill <?= $emailLive ? 'good' : 'bad' ?>"><?= $emailLive ? 'Connected' : 'Not connected' ?></span>
      <?= $emailLive ? 'Emails go out from ' . e($smtp['from_email'] ?: $smtp['user']) . ' (sent in the last 24 hours: ' . email_sent_last_day() . ', limit ' . ((int) cfg('email_daily_cap') ?: 'none') . ').' : 'Until you connect an account, emails are only recorded.' ?></p>
    <p class="small"><strong>Easiest free option: a Gmail account.</strong> In your Google account turn on 2-Step Verification, then create an App Password (Google account &gt; Security &gt; App passwords). Use that 16-letter App Password below, not your normal password. Gmail sends up to about 500 emails a day.</p>
    <p class="row"><button type="button" class="secondary" id="preset-gmail">Fill in for Gmail</button></p>
    <form method="post" id="email-form">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="email_save">
      <label class="field">Mail server <input name="smtp_host" value="<?= e($smtp['host']) ?>" placeholder="smtp.gmail.com" autocomplete="off" required></label>
      <div class="grid2">
        <label class="field">Port <input name="smtp_port" type="number" value="<?= e($smtp['host'] !== '' ? $smtp['port'] : 465) ?>" required></label>
        <label class="field">Security <select name="smtp_secure">
          <option value="ssl"<?= $smtp['secure'] === 'ssl' ? ' selected' : '' ?>>SSL (port 465)</option>
          <option value="tls"<?= $smtp['secure'] === 'tls' ? ' selected' : '' ?>>TLS / STARTTLS (port 587)</option>
          <option value="none"<?= $smtp['secure'] === 'none' ? ' selected' : '' ?>>None (only for a trusted local server)</option></select></label>
      </div>
      <label class="field">Username (usually your email address) <input name="smtp_user" value="<?= e($smtp['user']) ?>" autocomplete="off"></label>
      <label class="field">Password / App Password <input name="smtp_pass" type="password" autocomplete="new-password" placeholder="<?= $smtp['pass'] !== '' ? 'Saved. Type a new one to replace it.' : 'Paste the App Password' ?>"></label>
      <label class="field">Send from (email address) <input name="smtp_from" type="email" value="<?= e($smtp['from_email']) ?>" placeholder="you@gmail.com"></label>
      <label class="field">Sender name <input name="smtp_name" maxlength="60" value="<?= e($smtp['from_name']) ?>" placeholder="BantayKlima"></label>
      <button type="submit">Save email settings</button>
      <?php if ($emailLive): ?><label class="check"><input type="checkbox" name="smtp_remove" value="1"><span>Remove this email account (emails will only be recorded)</span></label><?php endif; ?>
    </form>
    <form method="post" class="row" style="margin-top:12px">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="email_test">
      <input name="to" type="email" placeholder="Your email address" required>
      <button type="submit" class="secondary">Send test email</button>
    </form>
  </section>
  <section class="card" data-tab="settings" id="refresh-risk" tabindex="-1">
    <h2>Update risk levels</h2>
    <p class="small">This runs automatically once a day when the daily task is set up. Press it after you save a new PAGASA status.</p>
    <form method="post" class="row">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="refresh">
      <button name="mode" value="live">Update risk levels</button>
    </form>
    <details><summary>Demo data (for presentations)</summary>
      <p class="small">Demo buttons use fake data, send nothing to anyone, and show a "DEMO DATA" notice on the dashboard until you update with live data again.</p>
      <form method="post" class="row">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="refresh">
        <button name="mode" value="normal" class="secondary">Demo: normal</button><button name="mode" value="drought" class="secondary">Demo: drought</button><button name="mode" value="flood" class="secondary">Demo: flood</button>
      </form>
    </details>
  </section>
  <section class="card" data-tab="settings">
    <details<?= $thresholdErrors ? ' open' : '' ?>><summary>Risk thresholds (advanced)</summary>
    <p class="small">These numbers decide when a place moves to Watch, Warning or Severe. They are a reasonable starting point, not scientifically validated values, so change them only with advice.</p>
    <?php if ($thresholdErrors): ?><div class="err"><?php foreach ($thresholdErrors as $te): ?><p><?= e($te) ?></p><?php endforeach; ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="thresholds_save">
      <?php foreach (risk_threshold_labels() as $k => $label): ?>
        <label class="field"><span><?= e($label) ?></span><input type="number" step="any" name="t[<?= e($k) ?>]" value="<?= e($thresholds[$k]) ?>" required></label>
      <?php endforeach; ?>
      <button type="submit">Save thresholds</button>
    </form>
    <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="thresholds_reset"><button type="submit" class="secondary">Reset to defaults</button></form>
    </details>
  </section>
    </div>
  </div>
  <section class="card admin-activity" data-tab="activity">
    <h2>Recent admin actions</h2>
    <p class="small">Shows the latest 50 actions from the admin portal, attributed to the signed-in admin username.</p>
    <div class="scroll"><table>
      <thead><tr><th scope="col">Time (PHT)</th><th scope="col">Admin</th><th scope="col">Action</th><th scope="col">Result</th></tr></thead>
      <tbody>
        <?php foreach ($activity as $event): ?>
          <tr><td><?= e(local_time($event['created_at'])) ?></td><td><?= e($event['actor']) ?></td><td><?= e($event['action']) ?></td><td><?= e($event['outcome']) ?></td></tr>
        <?php endforeach; if (!$activity): ?><tr><td colspan="4">No admin actions recorded yet.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </section>
</main>
</div>
<script src="../assets/admin-ui.js"></script>
<script src="../assets/vendor/pdfjs/pdf.min.js"></script>
<script src="../assets/pdf-import.js"></script>
</body>
</html>
