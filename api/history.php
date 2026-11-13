<?php
require __DIR__ . '/../lib/bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
$st = db()->prepare('SELECT name FROM municipalities WHERE id = ?');
$st->execute([$id]);
$name = $st->fetchColumn();
if ($name === false) json_out(['error' => 'Unknown municipality.'], 404);

// Show the same kind of data the dashboard is showing: demo history while demo data is on screen, otherwise live history.
$st = db()->prepare('SELECT source FROM risk_assessments WHERE municipality_id = ? ORDER BY id DESC LIMIT 1');
$st->execute([$id]);
$source = $st->fetchColumn() ?: 'live';

if ($source === 'live') { // one point per day (the day's last assessment), last 60 days
    $st = db()->prepare("SELECT * FROM risk_assessments WHERE id IN (
        SELECT MAX(id) FROM risk_assessments WHERE municipality_id = ? AND source = 'live' AND created_at >= datetime('now', '-60 days')
        GROUP BY date(created_at, '+8 hours')) ORDER BY id");
    $st->execute([$id]);
} else { // demo runs: one point per run, last 20
    $st = db()->prepare("SELECT * FROM (SELECT * FROM risk_assessments WHERE municipality_id = ? AND source = 'demo' ORDER BY id DESC LIMIT 20) ORDER BY id");
    $st->execute([$id]);
}

$points = [];
$n = 0;
foreach ($st->fetchAll() as $r) {
    $n++;
    $points[] = [
        'label' => $source === 'live' ? date('M j', strtotime(local_time($r['created_at']))) : 'Run ' . $n,
        'score' => (int) $r['score'], 'level' => $r['level'], 'hazard' => $r['hazard'],
        'when' => local_time($r['created_at']),
    ];
}

$t = array_merge(risk_default_thresholds(), (array) setting_get('thresholds', []));
json_out(['name' => $name, 'source' => $source, 'points' => $points,
    'levels' => ['watch' => $t['level_watch'], 'warning' => $t['level_warning'], 'severe' => $t['level_severe']]]);
