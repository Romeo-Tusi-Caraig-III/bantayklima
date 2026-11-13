<?php
function admin_session(): void {
    if (session_status() !== PHP_SESSION_NONE) return;
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']), 'path' => '/']);
    session_name('bk_admin');
    session_start();
}
