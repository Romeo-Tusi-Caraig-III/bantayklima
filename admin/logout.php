<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/auth_session.php';
admin_session();
$_SESSION = [];
session_destroy();
header('Location: login.php');
