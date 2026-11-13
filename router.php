<?php
// For PHP's built-in server:  php -S localhost:8000 router.php
// The built-in server ignores .htaccess, so this blocks private folders, config files and database files.
$path = str_replace('\\', '/', rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)));
$private = ['data', 'lib', 'tests', 'cli'];
$secret = ['config.php', 'config.local.php', 'router.php'];
if (preg_match('#/uploads(/|$)#i', $path)) { http_response_code(403); exit('Forbidden'); } // PDFs are served by file.php
foreach (explode('/', $path) as $segment) {
    // Windows ignores upper/lower case and trailing dots or spaces, so compare in that normal form.
    $s = strtolower(rtrim($segment, '. '));
    if ($s === '') continue;
    if ($segment === '..' || $s[0] === '.' || str_contains($s, ':')
        || in_array($s, $private, true) || in_array($s, $secret, true)
        || preg_match('/\.(md|example|sqlite3?|db|log|bak|csv|lock|ps1)$/', $s)) {
        http_response_code(403);
        exit('Forbidden');
    }
}
return false; // everything else is served normally
