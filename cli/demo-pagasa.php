<?php
// Loads the October 2026 PAGASA table (text of drought-national-overview-2026-10.pdf) as the official status,
// then makes demo risk data, so you can see how the dashboard looks with it.
//   C:\xampp\php\php.exe cli\demo-pagasa.php
//   C:\xampp\php\php.exe cli\demo-pagasa.php --status=drought      (pretend the province is on that list: normal, dry_condition, dry_spell, drought)
//   C:\xampp\php\php.exe cli\demo-pagasa.php --scenario=drought    (map colors: normal, drought, flood)
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../lib/bootstrap.php';

$status = null; $scenario = 'normal';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--status=')) $status = substr($arg, 9);
    if (str_starts_with($arg, '--scenario=')) $scenario = substr($arg, 11);
}
if ($status !== null && !in_array($status, ['normal', 'dry_condition', 'dry_spell', 'drought'], true)) exit("Status must be normal, dry_condition, dry_spell or drought.\n");

$r = parse_dds((string) file_get_contents(__DIR__ . '/sample-pagasa-2026-10.txt'), cfg('province'));
if (!$r['ok']) exit('Could not read the sample: ' . $r['error'] . "\n");
$rain = $status ?? $r['status'];

backup_db('before-demo-pagasa');
db()->prepare('INSERT INTO advisories (province, alert_status, rainfall_status, month, issued, notes, source_url) VALUES (?, ?, ?, ?, ?, ?, ?)')
    ->execute([cfg('province'), 'El Niño / La Niña', $rain, $r['month'], null,
        'DEMO: sample from the PAGASA drought assessment PDF (October 2026). ' . $r['summary'], 'https://bagong.pagasa.dost.gov.ph/climate/el-nino-la-nina/advisories']);
echo cfg('province') . ' PAGASA status: ' . str_replace('_', ' ', $rain) . ($status ? ' (set by you)' : ' (read from the table)') . ".\n";
echo refresh_all($scenario), "\n";
