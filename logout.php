<?php
require __DIR__ . '/lib/bootstrap.php';
user_session();
$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
header('Location: index.html');
