<?php
require __DIR__ . '/lib/bootstrap.php';
user_session();
if (current_user()) { header('Location: index.html'); exit; }

$error = ''; $who = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $who = trim((string) ($_POST['who'] ?? ''));
    if (!user_csrf_ok()) $error = 'The page expired. Please try again.';
    elseif (rate_limited('login:' . client_ip(), 15, 900)) $error = 'Too many tries. Please wait 15 minutes and try again.';
    elseif ($u = login_user($who, (string) ($_POST['password'] ?? ''))) {
        session_regenerate_id(true); $_SESSION['uid'] = (int) $u['id']; header('Location: index.html'); exit;
    } else $error = 'Wrong mobile number, email or password.';
}
page_head('Log in');
?>
<main class="auth">
  <section class="card">
    <h1>Welcome back</h1>
    <p class="small">Log in to see your town and manage your SMS alerts.</p>
    <?php if ($error): ?><p class="err" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(user_csrf()) ?>">
      <label class="field">Mobile number or email <input name="who" value="<?= e($who) ?>" autocomplete="username" required autofocus></label>
      <label class="field">Password <span class="pw"><input name="password" type="password" autocomplete="current-password" required></span></label>
      <button type="submit">Log in</button>
    </form>
    <p class="small">New here? <a href="register.php">Create an account</a>.</p>
  </section>
</main>
<?php page_foot();
