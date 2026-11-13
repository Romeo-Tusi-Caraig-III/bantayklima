<?php
/**
 * A self-audit: checks the setup, security settings and data health, and says what to fix.
 * Each check is [name, status (ok / warn / fail), detail]. $network = true also tests internet access and private-file protection.
 */
function system_checks(bool $network = false): array {
    $c = [];
    $add = function (string $name, string $status, string $detail) use (&$c) { $c[] = ['name' => $name, 'status' => $status, 'detail' => $detail]; };
    $dir = dirname((string) cfg('db_file'));

    // Setup
    $add('PHP version', PHP_VERSION_ID >= 80000 ? 'ok' : 'fail', 'PHP ' . PHP_VERSION . ' (8.0 or newer is needed)');
    foreach (['pdo_sqlite' => 'fail', 'sqlite3' => 'warn', 'curl' => 'warn', 'mbstring' => 'fail'] as $ext => $level) {
        $add("PHP extension: $ext", extension_loaded($ext) ? 'ok' : $level, extension_loaded($ext) ? 'loaded' : 'not loaded: enable it in php.ini');
    }
    // Live data is downloaded over HTTPS: PHP needs either the curl extension or the openssl extension for that.
    $canHttps = extension_loaded('curl') || in_array('https', stream_get_wrappers(), true);
    $add('Can download over HTTPS (live data, SMS)', $canHttps ? 'ok' : 'fail',
        $canHttps ? (extension_loaded('curl') ? 'yes, using curl' : 'yes, using PHP streams with openssl (enabling curl is optional)')
                  : 'no: in php.ini remove the ; before extension=curl and extension=openssl, then restart');
    if (!extension_loaded('pdo_sqlite') || !extension_loaded('mbstring')) return $c;
    try {
        $db = db();
    } catch (Throwable $error) {
        $add('Database initialization', 'fail', 'Cannot open or initialize SQLite: ' . $error->getMessage());
        return $c;
    }
    $v = $db->query('SELECT sqlite_version()')->fetchColumn();
    $add('SQLite version', version_compare($v, '3.25.0', '>=') ? 'ok' : 'fail', "$v (3.25 or newer is needed for the statistics page)");
    // Test an actual write: directory attributes on Windows can mislead is_writable().
    $probe = $dir . '/.write-check-' . bin2hex(random_bytes(8));
    $writable = @file_put_contents($probe, 'check', LOCK_EX) !== false;
    if ($writable) @unlink($probe);
    $add('Data folder is writable', $writable ? 'ok' : 'fail', $dir);
    $add('Time zone', date_default_timezone_get() === 'Asia/Manila' ? 'ok' : 'warn', date_default_timezone_get());
    $add('Municipalities loaded', (int) db()->query('SELECT COUNT(*) FROM municipalities')->fetchColumn() === 30 ? 'ok' : 'warn', 'expected 30 for Laguna');

    // Security and privacy settings
    $add('Admin password', admin_password_weak() ? 'fail' : 'ok', admin_password_weak() ? 'still the default or too short: open Admin > Settings and set a new one (8+ characters)' : 'set');
    $mail = (string) cfg('contact_email');
    $add('Contact email for the privacy notice', ($mail === '' || str_contains($mail, 'example.com')) ? 'fail' : 'ok', $mail ?: 'not set');
    $add('Site address (base_url) for email stop links', cfg('base_url') ? 'ok' : 'warn', cfg('base_url') ?: 'not set: emails will tell people to use the Stop alerts page instead of giving a link');
    $add('Sign-up confirmation by code', cfg('require_confirmation') ? 'ok' : 'warn', cfg('require_confirmation') ? 'required' : 'switched off: anyone can subscribe any number');
    $https = !empty($_SERVER['HTTPS']) || PHP_SAPI === 'cli' || in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    $add('HTTPS for the admin login', $https ? 'ok' : 'warn', $https ? 'ok (HTTPS, or running on this computer only)' : 'the admin password is sent without encryption: use HTTPS when this is online');
    $add('Debug mode', cfg('debug') ? 'warn' : 'ok', cfg('debug') ? 'on: PHP errors are shown on screen. Turn off when sharing.' : 'off');
    $errLog = $dir . '/error.log';
    $errs = is_file($errLog) ? count(file($errLog)) : 0;
    $add('Server error log', $errs ? 'warn' : 'ok', $errs ? "$errs entries in data/error.log: open it to see what went wrong" : 'no errors recorded');

    // SMS
    if (sms_setting('key')) {
        $add('SMS key', 'ok', 'set');
        $add('SMS sender name', sms_setting('sender') ? 'ok' : 'warn', sms_setting('sender') ?: 'not set: Semaphore may reject messages without a registered sender name');
        $add('SMS daily cap', (int) cfg('sms_daily_cap') > 0 ? 'ok' : 'warn', sms_sent_last_day() . ' of ' . ((int) cfg('sms_daily_cap') ?: 'no limit') . ' used in the last 24 hours');
    } else {
        $add('SMS key', 'warn', 'not set: alerts are only logged, not texted');
    }
    if (email_connected()) { $smtp = smtp_cfg(); $add('Email account', 'ok', $smtp['host'] . ':' . $smtp['port'] . ' (' . $smtp['secure'] . '), from ' . ($smtp['from_email'] ?: $smtp['user'])); }
    else $add('Email account', 'warn', 'not connected: emails are only logged. Connect one in Admin > Settings.');
    $failed = (int) db()->query("SELECT COUNT(*) FROM messages WHERE status = 'failed' AND created_at >= datetime('now', '-7 days')")->fetchColumn();
    $add('Failed messages (7 days)', $failed ? 'warn' : 'ok', $failed ? "$failed failed: see Recent messages for the reasons" : 'none');

    // Data health
    $warnings = data_health_warnings();
    $add('Data freshness', $warnings ? 'warn' : 'ok', $warnings ? implode(' ', $warnings) : refresh_status_line());
    $st = db()->prepare('SELECT * FROM advisories WHERE province = ? ORDER BY id DESC LIMIT 1');
    $st->execute([cfg('province')]);
    $adv = $st->fetch() ?: null;
    $due = pagasa_due($adv);
    $add('PAGASA status', $due ? 'warn' : 'ok', $due ? "the $due table should be imported" : ($adv ? 'up to date (' . date('F Y', strtotime($adv['month'])) . ')' : 'up to date'));
    $b = backup_list(1);
    $age = $b ? (int) floor((time() - $b[0]['time']) / 86400) : null;
    $add('Latest backup', ($b && $age <= 2) ? 'ok' : 'warn', $b ? "$age day(s) old" : 'no backup yet: one is made after each daily live refresh');
    $pending = (int) db()->query("SELECT COUNT(*) FROM subscribers WHERE status = 'pending'")->fetchColumn();
    $add('Sign-ups waiting for a code', 'ok', "$pending pending (unconfirmed ones are deleted after a day)");

    if ($network) {
        $body = http_get((string) cfg('oni_url'), 20);
        $oniVal = $body ? parse_oni($body) : null;
        $add('Internet: NOAA ONI file', $oniVal !== null ? 'ok' : 'fail', $oniVal !== null ? 'latest ONI ' . $oniVal : 'could not download or read it');
        [$code] = http_request(cfg('open_meteo_forecast') . '?latitude=14.2&longitude=121.3&daily=precipitation_sum&forecast_days=1', 20);
        $add('Internet: Open-Meteo', $code === 200 ? 'ok' : 'fail', $code === 200 ? 'reachable' : ($code ? "HTTP $code" : 'no connection'));
        $acc = sms_account();
        if ($acc) $add('Internet: Semaphore account', $acc['error'] ? 'fail' : 'ok', $acc['error'] ?: 'credits: ' . ($acc['balance'] ?? '?'));
        if (PHP_SAPI === 'cli-server') {
            // PHP's built-in server handles one request at a time, so it cannot download files from itself to test them.
            $add('Private files (data, lib, config)', 'ok', 'protected by router.php. To test a real address, run "php cli/check.php --network" from another terminal while the server is running.');
        } elseif (cfg('base_url')) {
            // The database file is the one that matters most: if this downloads, anyone could read your subscribers.
            foreach ([['/data/bantayklima.sqlite', 'fail'], ['/data/', 'warn'], ['/lib/db.php', 'warn']] as [$p, $bad]) {
                [$code] = http_request(rtrim((string) cfg('base_url'), '/') . $p, 10);
                $add("Private file not downloadable: $p", $code === 0 ? 'warn' : ($code === 200 ? $bad : 'ok'),
                    $code === 0 ? 'could not reach the site to test' : ($code === 200
                        ? 'answered HTTP 200: this folder is NOT protected. In XAMPP, open httpd.conf and set AllowOverride All for htdocs, then restart Apache'
                        : "server answered HTTP $code"));
            }
        }
    }
    return $c;
}
