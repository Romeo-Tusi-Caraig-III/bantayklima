<?php
/** Warnings about where the dashboard's data came from and how fresh it is. */
function data_health_warnings(): array {
    $lr = setting_get('last_refresh');
    if (!$lr) return [];
    if (str_starts_with((string) $lr['mode'], 'demo')) {
        return ['DEMO DATA: these levels are simulated and do not show real conditions.'];
    }
    $hours = (int) floor((time() - (int) $lr['at']) / 3600);
    if ($hours >= (int) cfg('refresh_stale_hours')) {
        return ["Risk levels were last refreshed $hours hours ago. The daily update may have stopped, so these levels may be out of date."];
    }
    return [];
}

/** One line for the admin page about the last refresh. */
function refresh_status_line(): string {
    $lr = setting_get('last_refresh');
    if (!$lr) return 'No refresh has run yet.';
    $mode = str_starts_with((string) $lr['mode'], 'demo') ? 'demo data (' . substr($lr['mode'], 5) . ')' : 'live data';
    return 'Last refresh: ' . local_time(gmdate('Y-m-d H:i:s', (int) $lr['at'])) . ', using ' . $mode . ($lr['complete'] ? '' : ' (incomplete)') . '.';
}

/** Is a weak or default admin password still set? */
function admin_password_weak(): bool {
    if (setting_get('admin_password_hash')) return false; // a password chosen in Admin > Settings is always long enough
    $p = (string) cfg('admin_password');
    return $p === '' || $p === 'change-me' || strlen($p) < 8;
}

/** Admin stays locked when the password is weak and the request does not come from this computer. */
function admin_locked(string $remoteAddr): bool {
    return admin_password_weak() && !in_array($remoteAddr, ['127.0.0.1', '::1'], true);
}

/** Is this the right admin name and password? A password saved from the admin page wins over config.php. */
function admin_credentials_ok(string $user, string $pass): bool {
    if (!hash_equals((string) cfg('admin_user'), $user)) return false;
    $hash = setting_get('admin_password_hash');
    return $hash ? password_verify($pass, $hash) : hash_equals((string) cfg('admin_password'), $pass);
}
