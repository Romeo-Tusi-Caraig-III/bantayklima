<?php
require __DIR__ . '/auth.php';

function mask_name(string $n): string { return $n === '' ? '' : mb_substr($n, 0, 1) . '***'; }
function mask_any(?string $v): string { return ($v === null || $v === '') ? '' : mask_target($v); }
/** Stop spreadsheets from running a cell as a formula (a risk with anything a visitor typed). */
function csv_cell($v): string {
    $v = (string) $v;
    return ($v !== '' && !is_numeric($v) && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
}

$changes = "WITH x AS (SELECT a.municipality_id, a.hazard, a.level, a.created_at,
        LAG(a.level) OVER (PARTITION BY a.municipality_id ORDER BY a.id) AS prev_level,
        LAG(a.hazard) OVER (PARTITION BY a.municipality_id ORDER BY a.id) AS prev_hazard
        FROM risk_assessments a WHERE a.source = 'live')
    SELECT m.name AS municipality, x.created_at, x.prev_level AS from_level, x.prev_hazard AS from_hazard, x.level AS to_level, x.hazard AS to_hazard
    FROM x JOIN municipalities m ON m.id = x.municipality_id
    WHERE x.prev_level IS NOT NULL AND (x.level <> x.prev_level OR x.hazard <> x.prev_hazard) ORDER BY x.created_at";

// What can be exported. Personal details are masked: these files are for reports, not for contacting people.
$sets = [
    'subscribers' => ["SELECT s.id, s.name, s.phone, s.email, s.language, s.status, m.name AS municipality, s.consented_at, s.created_at
        FROM subscribers s JOIN municipalities m ON m.id = s.municipality_id ORDER BY s.id", ['name' => 'mask_name', 'phone' => 'mask_any', 'email' => 'mask_any']],
    'messages' => ["SELECT id, channel, recipient, status, detail, body, created_at FROM messages WHERE status <> 'simulated' ORDER BY id", ['recipient' => 'mask_any']],
    'history' => ["SELECT a.id, m.name AS municipality, a.hazard, a.level, a.score, a.oni, a.recent_ratio, a.forecast_ratio, a.data_ok, a.created_at
        FROM risk_assessments a JOIN municipalities m ON m.id = a.municipality_id WHERE a.source = 'live' ORDER BY a.id", []],
    'changes' => [$changes, []],
];
$what = $_GET['what'] ?? '';
if (!isset($sets[$what])) { http_response_code(404); exit('Unknown export.'); }

[$sql, $mask] = $sets[$what];
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="bantayklima-' . $what . '-' . date('Ymd') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // so Excel reads ñ correctly
$first = true;
foreach (db()->query($sql) as $row) {
    if ($first) { fputcsv($out, array_keys($row), ',', '"', ''); $first = false; }
    foreach ($mask as $col => $fn) $row[$col] = $fn((string) ($row[$col] ?? ''));
    fputcsv($out, array_map('csv_cell', $row), ',', '"', '');
}
if ($first) fputcsv($out, ['no data yet'], ',', '"', '');
fclose($out);
