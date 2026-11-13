<?php
if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    exit('BantayKlima needs PHP 8.0 or newer. This server has PHP ' . PHP_VERSION . '.');
}
date_default_timezone_set('Asia/Manila');

// Settings come from config.php; anything in config.local.php (optional, keep passwords and API keys there) overrides them.
function cfg(string $key) {
    static $c;
    if ($c === null) {
        $c = array_merge(
            require __DIR__ . '/../config.php',
            is_file(__DIR__ . '/../config.local.php') ? require __DIR__ . '/../config.local.php' : [],
            defined('BK_TEST_OVERRIDES') ? BK_TEST_OVERRIDES : []
        );
    }
    return $c[$key] ?? null;
}
function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Convert a SQLite (UTC) timestamp to a Philippine-time label. */
function local_time(?string $utc): ?string {
    if (!$utc) return null;
    return (new DateTime($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y g:i A');
}

function bk_log_error(string $msg): void {
    $dir = dirname((string) cfg('db_file'));
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($dir . '/error.log', '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}

if (PHP_SAPI !== 'cli') {
    if (!cfg('debug')) ini_set('display_errors', '0');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // Never show visitors a stack trace or a file path: log the details privately and show a plain message.
    set_exception_handler(function (Throwable $e) {
        bk_log_error(get_class($e) . ': ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
        if (!headers_sent()) http_response_code(500);
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Something went wrong on the server. Please try again later.']);
        } else {
            echo 'Something went wrong on the server. Please try again later.';
        }
    });
}

require __DIR__ . '/db.php';
require __DIR__ . '/risk.php';
require __DIR__ . '/pagasa.php';
require __DIR__ . '/climate.php';
require __DIR__ . '/mailer.php';
require __DIR__ . '/notify.php';
require __DIR__ . '/signup.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/uploads.php';
require __DIR__ . '/backup.php';
require __DIR__ . '/health.php';
require __DIR__ . '/check.php';
require __DIR__ . '/refresh.php';
