<?php
require __DIR__ . '/lib/bootstrap.php';
$user = current_user();
if (!$user) { header('Location: login.php'); exit; }

$msg = $_SESSION['account_status'] ?? ''; $error = '';
unset($_SESSION['account_status']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $msg = '';
    $action = $_POST['action'] ?? '';
    if (!user_csrf_ok()) { $error = 'The page expired. Please try again.'; }
    elseif ($action === 'profile') {
        $name = trim((string) $_POST['name']); $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $phone = normalize_ph_phone((string) ($_POST['phone'] ?? '')); $mid = (int) ($_POST['municipality_id'] ?? 0);
        $dup = db()->prepare('SELECT COUNT(*) FROM users WHERE id <> ? AND (phone = ? OR (? <> \'\' AND lower(email) = ?))');
        $dup->execute([$user['id'], $phone ?? '-', $email, $email]);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) $error = 'Please enter your name.';
        elseif (!$phone) $error = 'Mobile number should look like 09XXXXXXXXX.';
        elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'That email does not look right.';
        elseif (!town_exists($mid)) $error = 'Please choose your municipality.';
        elseif ((int) $dup->fetchColumn()) $error = 'Another account already uses that mobile number or email.';
        else {
            db()->prepare('UPDATE users SET name = ?, phone = ?, email = ?, municipality_id = ?, language = ?, sms_optin = ? WHERE id = ?')
                ->execute([$name, $phone, $email !== '' ? $email : null, $mid, ($_POST['language'] ?? '') === 'fil' ? 'fil' : 'en', !empty($_POST['sms_optin']) ? 1 : 0, $user['id']]);
            sync_subscriber((int) $user['id']);
            $msg = 'Profile and alert preferences saved.';
        }
    } elseif ($action === 'password') {
        if (!password_verify((string) $_POST['current'], $user['password_hash'])) $error = 'Your current password is not right.';
        elseif ($e = valid_new_password((string) $_POST['new'])) $error = $e;
        else { db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash((string) $_POST['new'], PASSWORD_DEFAULT), $user['id']]); $msg = 'Password changed.'; }
    } elseif ($action === 'delete') {
        if (!password_verify((string) $_POST['current'], $user['password_hash'])) $error = 'Your password is not right, so nothing was deleted.';
        else { delete_user((int) $user['id']); $_SESSION = []; session_destroy(); header('Location: index.html'); exit; }
    }
    if ($msg !== '') {
        $_SESSION['account_status'] = $msg;
        header('Location: account.php', true, 303);
        exit;
    }
    $user = current_user();
}
$towns = db()->query('SELECT id, name FROM municipalities ORDER BY name')->fetchAll();
$smsOn = user_alerts_on((int) $user['id']);
page_head('My account', $user);
?>
<main class="account-layout">
  <header class="portal-heading">
    <div><h1>My account</h1><p><?= e($user['town']) ?> &middot; <span class="pill <?= $smsOn ? 'good' : 'bad' ?>"><?= $smsOn ? 'Alerts on' : 'Alerts off' ?></span></p></div>
    <a class="btn ghost" href="index.html?town=<?= (int) $user['municipality_id'] ?>">Open my town</a>
  </header>
    <?php if ($msg): ?><p class="ok" role="status"><?= e($msg) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="err" role="alert"><?= e($error) ?></p><?php endif; ?>
  <section class="card profile-card">
    <h2>Profile and alerts</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(user_csrf()) ?>"><input type="hidden" name="action" value="profile">
      <label class="field">Name <input name="name" value="<?= e($user['name']) ?>" required></label>
      <label class="field">Mobile number <input name="phone" type="tel" value="<?= e($user['phone']) ?>" required></label>
      <label class="field">Email (optional) <input name="email" type="email" value="<?= e($user['email']) ?>"></label>
      <label class="field">Municipality
        <select name="municipality_id"><?php foreach ($towns as $t): ?><option value="<?= (int) $t['id'] ?>"<?= (int) $user['municipality_id'] === (int) $t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></label>
      <label class="field">Alert language
        <select name="language"><option value="en"<?= $user['language'] === 'en' ? ' selected' : '' ?>>English</option><option value="fil"<?= $user['language'] === 'fil' ? ' selected' : '' ?>>Filipino</option></select></label>
      <label class="check form-wide"><input type="checkbox" name="sms_optin" value="1"<?= $smsOn ? ' checked' : '' ?>><span>Send me alerts and announcements (by SMS, and by email if I add one)</span></label>
      <button type="submit">Save changes</button>
    </form>
  </section>
  <section class="card">
    <h2>Change password</h2>
    <form method="post"><input type="hidden" name="csrf" value="<?= e(user_csrf()) ?>"><input type="hidden" name="action" value="password">
      <label class="field">Current password <span class="pw"><input name="current" type="password" autocomplete="current-password" required></span></label>
      <label class="field">New password <span class="pw"><input name="new" type="password" autocomplete="new-password" minlength="6" required></span></label>
      <button type="submit" class="secondary">Change password</button></form>
  </section>
  <section class="card account-danger">
    <h2>Delete my account</h2>
    <p class="small">This removes your account, your SMS subscription and your message records.</p>
    <form method="post"><input type="hidden" name="csrf" value="<?= e(user_csrf()) ?>"><input type="hidden" name="action" value="delete">
      <label class="field">Your password <span class="pw"><input name="current" type="password" autocomplete="current-password" required></span></label>
      <button type="submit" class="danger">Delete my account</button></form>
  </section>
</main>
<?php page_foot();
