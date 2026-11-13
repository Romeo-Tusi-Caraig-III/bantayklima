<?php
require __DIR__ . '/lib/bootstrap.php';
user_session();
if (current_user()) { header('Location: index.html'); exit; }

$error = ''; $v = ['name' => '', 'phone' => '', 'email' => '',
    'municipality_id' => (int) filter_input(INPUT_GET, 'town', FILTER_VALIDATE_INT), 'language' => 'en', 'sms_optin' => 1];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = array_merge($v, array_intersect_key($_POST, $v)); $v['sms_optin'] = !empty($_POST['sms_optin']) ? 1 : 0;
    if (!user_csrf_ok()) $error = 'The page expired. Please try again.';
    elseif (trim($_POST['website'] ?? '') !== '') $error = 'Something went wrong. Please try again.'; // spam trap
    elseif (rate_limited('register:' . client_ip(), 10, 3600)) $error = 'Too many sign-ups from this connection. Please try again later.';
    else {
        $r = register_user($_POST);
        if ($r['ok']) {
            session_regenerate_id(true);
            $_SESSION['uid'] = $r['id'];
            header('Location: index.html?welcome=1&town=' . (int) $v['municipality_id'], true, 303);
            exit;
        }
        $error = $r['error'];
    }
}
$towns = db()->query('SELECT id, name FROM municipalities ORDER BY name')->fetchAll();
page_head('Register');
?>
<main class="auth auth-register">
  <section class="card">
    <h1>Create your account</h1>
    <p class="small">Choose your municipality and decide whether to receive its alerts and announcements.</p>
    <?php if ($error): ?><p class="err" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" autocomplete="on">
      <input type="hidden" name="csrf" value="<?= e(user_csrf()) ?>">
      <label class="field">Your name <input name="name" value="<?= e($v['name']) ?>" autocomplete="name" required></label>
      <label class="field">Mobile number <input name="phone" type="tel" inputmode="numeric" placeholder="09XXXXXXXXX" value="<?= e($v['phone']) ?>" autocomplete="tel" required></label>
      <label class="field">Municipality
        <select name="municipality_id" required><option value="">Choose your municipality</option>
          <?php foreach ($towns as $t): ?><option value="<?= (int) $t['id'] ?>"<?= (int) $v['municipality_id'] === (int) $t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
        </select></label>
      <label class="field">Password (6 or more characters) <span class="pw"><input name="password" type="password" autocomplete="new-password" minlength="6" required></span></label>
      <label class="field">Email (optional) <input name="email" type="email" value="<?= e($v['email']) ?>" autocomplete="email"></label>
      <label class="field">Alert language
        <select name="language"><option value="en"<?= $v['language'] === 'en' ? ' selected' : '' ?>>English</option><option value="fil"<?= $v['language'] === 'fil' ? ' selected' : '' ?>>Filipino</option></select></label>
      <div class="hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
      <label class="check form-wide"><input type="checkbox" name="sms_optin" value="1"<?= $v['sms_optin'] ? ' checked' : '' ?>>
        <span>Send me alerts and announcements for my municipality (by SMS, and by email if I add one). I can turn this off any time in my account. <a href="privacy.php" target="_blank" rel="noopener">Privacy notice</a></span></label>
      <button type="submit">Create account</button>
    </form>
    <p class="small">Already have an account? <a href="login.php">Log in</a></p>
  </section>
</main>
<?php page_foot();
