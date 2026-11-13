<?php
// Shared by every admin page: bootstraps the app, then requires a signed-in admin session.
// Not signed in: go to the login form. Signing in happens in admin/login.php.
require __DIR__ . '/../lib/bootstrap.php';

header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; worker-src 'self' blob:; frame-ancestors 'none'; form-action 'self'");
header('Cache-Control: no-store');

require_once __DIR__ . '/auth_session.php';
admin_session();
if (empty($_SESSION['admin'])) { header('Location: login.php'); exit; }
// A weak default password is only usable on the computer running the server (also checked again here in case it was changed back).
if (admin_locked($_SERVER['REMOTE_ADDR'] ?? '')) { $_SESSION = []; header('Location: login.php'); exit; }
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
