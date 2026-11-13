<?php
// Receives setup values on stdin from setup.ps1; never accepts credentials in command-line arguments.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
try {
    if (PHP_VERSION_ID < 80000) throw new RuntimeException('PHP 8.0 or newer is required.');
    foreach (['pdo_sqlite', 'mbstring'] as $ext) {
        if (!extension_loaded($ext)) throw new RuntimeException("Enable extension=$ext in " . (php_ini_loaded_file() ?: 'php.ini') . ' and restart Apache.');
    }
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $password = (string) ($input['password'] ?? '');
    $email = trim((string) ($input['email'] ?? ''));
    $url = rtrim(trim((string) ($input['url'] ?? '')), '/');
    if (strlen($password) < 8 || $password === 'change-me') throw new RuntimeException('Choose an admin password of at least 8 characters.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || str_ends_with(strtolower($email), '@example.com')) throw new RuntimeException('Enter your real contact email.');
    if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) || parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) throw new RuntimeException('Enter the site HTTP or HTTPS address without a query or fragment.');
    $file = __DIR__ . '/../config.local.php';
    if (file_exists($file)) throw new RuntimeException('config.local.php already exists; edit it manually to preserve your settings.');
    $config = ['contact_email' => $email, 'base_url' => $url];
    require __DIR__ . '/../lib/bootstrap.php';
    db();
    $handle = @fopen($file, 'x');
    if (!$handle) throw new RuntimeException('Cannot create config.local.php (already exists or folder is not writable).');
    $contents = "<?php\nreturn " . var_export($config, true) . ";\n";
    try {
        if (fwrite($handle, $contents) !== strlen($contents)) throw new RuntimeException('Could not write settings.');
        setting_set('admin_password_hash', password_hash($password, PASSWORD_DEFAULT));
    } catch (Throwable $error) {
        fclose($handle); unlink($file); throw $error;
    }
    fclose($handle);
    echo "Settings saved. Admin username: admin. Password: the one you entered.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Setup failed: ' . $error->getMessage() . "\n");
    exit(1);
}
