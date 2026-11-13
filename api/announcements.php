<?php
require __DIR__ . '/../lib/bootstrap.php';
header('Cache-Control: no-store');

// ?town=ID shows that municipality's announcements plus the province-wide ones; without it, everything.
$town = (int) ($_GET['town'] ?? 0);
$sql = 'SELECT a.id, a.title, a.body, a.created_at, a.municipality_id, m.name AS town
    FROM announcements a LEFT JOIN municipalities m ON m.id = a.municipality_id'
    . ($town ? ' WHERE a.municipality_id IS NULL OR a.municipality_id = ?' : '') . ' ORDER BY a.id DESC LIMIT 12';
$st = db()->prepare($sql);
$st->execute($town ? [$town] : []);
$rows = $st->fetchAll();
foreach ($rows as &$r) { $r['created_at'] = local_time($r['created_at']); $r['id'] = (int) $r['id']; $r['municipality_id'] = $r['municipality_id'] ? (int) $r['municipality_id'] : null; }

json_out(['announcements' => $rows]);
