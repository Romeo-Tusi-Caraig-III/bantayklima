<?php
// Replays past months through the risk rules and compares them with PAGASA's tables.
//   php cli/backtest.php                  (from 2023-01)
//   php cli/backtest.php --from=2024-01
//   php cli/backtest.php --known=path/to/your.csv
// Needs internet. The first run downloads rainfall history for each grid cell (about 6 downloads, paced for Open-Meteo's limits).
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/backtest.php';

$from = '2023-01';
$known = __DIR__ . '/../tests/pagasa_laguna_known.csv';
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--from=(\d{4}-\d{2})$/', $arg, $m)) $from = $m[1];
    elseif (str_starts_with($arg, '--known=')) $known = substr($arg, 8);
}

echo "Back-test from $from (this can take a few minutes the first time)...\n";
$r = run_backtest($from, $known);
if ($r['error']) { fwrite(STDERR, 'Back-test stopped: ' . $r['error'] . "\n"); exit(1); }

$fmt = fn ($v, $f) => $v === null ? '-' : sprintf($f, $v);
printf("\n%-8s %6s %9s  %-18s %-14s %s\n", 'Month', 'ONI', 'Rain %', 'BantayKlima says', 'PAGASA (known)', 'Match');
$csv = fopen(dirname(cfg('db_file')) . '/backtest.csv', 'w');
fputcsv($csv, ['month', 'oni', 'rain_pct_of_normal', 'hazard', 'level', 'score', 'pagasa_status', 'our_drought_rank', 'pagasa_rank', 'difference'], ',', '"', '');
foreach ($r['rows'] as $row) {
    $says = $row['hazard'] === 'none' ? 'normal' : $row['level'] . ' ' . $row['hazard'];
    $match = $row['diff'] === null ? '' : ($row['diff'] === 0 ? 'exact' : ($row['diff'] < 0 ? 'we were lower' : 'we were higher'));
    printf("%-8s %6s %9s  %-18s %-14s %s\n", $row['month'], $fmt($row['oni'], '%+.1f'), $fmt($row['rain'] === null ? null : $row['rain'] * 100, '%.0f%%'), $says, $row['pagasa'] ?? '', $match);
    fputcsv($csv, [$row['month'], $row['oni'], $row['rain'] === null ? '' : round($row['rain'] * 100), $row['hazard'], $row['level'], $row['score'],
        $row['pagasa'] ?? '', $row['ours'] ?? '', $row['theirs'] ?? '', $row['diff'] ?? ''], ',', '"', '');
}
fclose($csv);

$s = $r['summary'];
echo "\nMonths replayed: {$s['months']}. Months with a known PAGASA status: {$s['compared']}.\n";
if ($s['compared']) {
    echo "Exactly the same step: {$s['exact']}. Within one step: {$s['within_one']}. BantayKlima lower: {$s['we_missed']}. Higher: {$s['we_overshot']}.\n";
}
echo <<<TXT

How to read this:
- The back-test only has the ONI and the last 30 days of rain. Live use also has PAGASA's status and a 14-day forecast, so live results can differ.
- PAGASA's table is an official reference, not a perfect truth about local impacts, and a handful of months is a small sample.
  Add more rows to tests/pagasa_laguna_known.csv (month,status,source) from PAGASA's monthly tables for a stronger test.
- Change the thresholds in Admin and run this again to see the effect. A full list was saved to data/backtest.csv.

TXT;
