<?php
// Self-audit from the command line:  php cli/check.php          (setup, security settings, data health)
//                                    php cli/check.php --network (also tests internet access and private-file protection)
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../lib/bootstrap.php';

$counts = ['ok' => 0, 'warn' => 0, 'fail' => 0];
foreach (system_checks(in_array('--network', $argv, true)) as $c) {
    $counts[$c['status']]++;
    printf("%-5s %-46s %s\n", strtoupper($c['status']), $c['name'], $c['detail']);
}
echo "\n{$counts['ok']} ok, {$counts['warn']} to look at, {$counts['fail']} to fix.\n";
exit($counts['fail'] ? 1 : 0);
