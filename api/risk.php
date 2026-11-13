<?php
require __DIR__ . '/../lib/bootstrap.php';
$lang = ($_GET['lang'] ?? 'en') === 'fil' ? 'fil' : 'en';

$towns = db()->query('SELECT m.id, m.name, m.lat, m.lng, a.hazard, a.level, a.reasons, a.data_ok, a.created_at,
    a.source, a.recent_ratio, a.recent_90_ratio
    FROM municipalities m
    LEFT JOIN risk_assessments a ON a.id = (SELECT MAX(id) FROM risk_assessments WHERE municipality_id = m.id)
    ORDER BY m.name')->fetchAll();

$contacts = [];
foreach (db()->query('SELECT municipality_id, label, phone FROM contacts ORDER BY id')->fetchAll() as $c) {
    $contacts[$c['municipality_id'] ?? 0][] = ['label' => $c['label'], 'phone' => $c['phone']];
}

$rows = [];
$updated = null;
$incomplete = 0;
foreach ($towns as $t) {
    $assessed = $t['level'] !== null;
    if ($assessed && !(int) $t['data_ok']) $incomplete++;
    $rows[] = [
        'id' => (int) $t['id'], 'name' => $t['name'], 'lat' => (float) $t['lat'], 'lng' => (float) $t['lng'],
        'hazard' => $t['hazard'] ?? 'none', 'level' => $t['level'] ?? 'pending',
        'reasons' => $assessed ? json_decode($t['reasons'], true) : [],
        'advice' => $assessed ? risk_advice($t['hazard'], $t['level'], $lang) : 'Not assessed yet.',
        'assessment' => $assessed ? [
            'updated' => local_time($t['created_at']), 'demo' => $t['source'] === 'demo',
            'incomplete' => !(bool) $t['data_ok'],
            'stale' => time() - strtotime($t['created_at'] . ' UTC') >= (int) cfg('refresh_stale_hours') * 3600,
        ] : null,
        'rainfall' => [
            'days30' => $t['recent_ratio'] === null ? null : (float) $t['recent_ratio'],
            'days90' => $t['recent_90_ratio'] === null ? null : (float) $t['recent_90_ratio'],
            'source' => $t['source'], 'updated' => local_time($t['created_at']),
        ],
        'contacts' => array_merge($contacts[(int) $t['id']] ?? [], $contacts[0] ?? []), // the town's own first, then province-wide
    ];
    if ($t['created_at'] && (!$updated || $t['created_at'] > $updated)) $updated = $t['created_at'];
}

$st = db()->prepare('SELECT alert_status, rainfall_status, month, issued, created_at, notes FROM advisories WHERE province = ? ORDER BY id DESC LIMIT 1');
$st->execute([cfg('province')]);
$advisory = $st->fetch() ?: null;

$warnings = data_health_warnings();
if ($incomplete) $warnings[] = "Live climate data was incomplete for $incomplete places at the last refresh, so their risk levels may be understated.";
if ($advisory) $warnings = array_merge($warnings, advisory_warnings($advisory, false));
if ($advisory) {
    $advisory['stale'] = (bool) advisory_warnings($advisory, false);
    $advisory['recorded'] = local_time($advisory['created_at']);
    unset($advisory['created_at']);
}

json_out([
    'province' => cfg('province'), 'advisory' => $advisory, 'updated' => local_time($updated), 'rows' => $rows,
    'hotline' => '911', 'warning' => $warnings ? implode(' ', $warnings) : null,
]);
