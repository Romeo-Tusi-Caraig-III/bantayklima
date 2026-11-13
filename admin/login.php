<?php
require __DIR__ . '/../lib/bootstrap.php';
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
require_once __DIR__ . '/auth_session.php';
admin_session();

$locked = admin_locked($_SERVER['REMOTE_ADDR'] ?? '');
$error = '';
if (!$locked && !empty($_SESSION['admin'])) { header('Location: index.php'); exit; }

if (!$locked && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($_SESSION['lcsrf'] ?? '-', (string) ($_POST['csrf'] ?? ''))) {
        $error = 'The page expired. Please try again.';
    } elseif (rate_count('adminfail:' . client_ip(), 900) >= 10) {
        $error = 'Too many wrong tries. Please wait 15 minutes and try again.';
    } elseif (admin_credentials_ok(trim((string) ($_POST['user'] ?? '')), (string) ($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['admin_actor'] = (string) cfg('admin_user');
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        header('Location: index.php'); exit;
    } else {
        rate_hit('adminfail:' . client_ip());
        $error = 'Wrong username or password.';
    }
}
$_SESSION['lcsrf'] ??= bin2hex(random_bytes(16));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin log in | BantayKlima</title>
<?= portal_assets('../assets/') ?>
</head>
<body class="dashboard portal auth-body">
<nav class="topbar"><div class="nav-inner"><a class="brand" href="../index.html"><span class="logo" aria-hidden="true"></span>BantayKlima</a><div class="navlinks"><a href="../index.html">Public dashboard</a><?= theme_button() ?></div></div></nav>
<main class="auth">
  <section class="card">
    <h1>Admin log in</h1>
    <?php if ($locked): ?>
      <p class="err" role="alert"><strong>Set a custom admin password to continue.</strong></p>
      <p>For Docker, set <code>BANTAYKLIMA_ADMIN_PASSWORD</code> to a strong password in the project’s <code>.env</code> file, then run <code>docker compose up -d --wait</code> from the project folder.</p>
      <p>For XAMPP, set <code>admin_password</code> in <code>config.local.php</code> and reload this page.</p>
    <?php else: ?>
      <?php if ($error): ?><p class="err" role="alert"><?= e($error) ?></p><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['lcsrf']) ?>">
        <label class="field">Username <input name="user" value="<?= e($_POST['user'] ?? 'admin') ?>" autocomplete="username" required></label>
        <label class="field">Password <span class="pw"><input name="password" type="password" autocomplete="current-password" required></span></label>
        <button type="submit">Log in</button>
      </form>
      <?php if (admin_password_weak()): ?><p class="small">First time? The default password is <code>change-me</code>. You will be asked to change it.</p><?php endif; ?>
    <?php endif; ?>
  </section>
</main>
<script src="../assets/auth.js"></script>
</body>
</html>
