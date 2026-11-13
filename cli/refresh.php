<?php
// Run from the command line or a scheduler:  php cli/refresh.php   or   php cli/refresh.php --demo=drought
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../lib/bootstrap.php';

$demo = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--demo=')) $demo = substr($arg, 7);
}
echo refresh_all($demo), "\n";
