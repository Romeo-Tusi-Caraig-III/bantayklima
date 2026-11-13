<?php
require __DIR__ . '/../lib/bootstrap.php';

$town = filter_input(INPUT_GET, 'town', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$query = db()->prepare("SELECT 'announcement' AS kind, a.title, a.body AS detail, m.name AS area, a.created_at AS event_at,
        NULL AS published_at
    FROM announcements a LEFT JOIN municipalities m ON m.id = a.municipality_id
    WHERE (? IS NULL OR a.municipality_id IS NULL OR a.municipality_id = ?)
    UNION ALL
    SELECT 'pagasa', alert_status, rainfall_status, province, created_at, issued FROM advisories WHERE province = ?
    ORDER BY event_at DESC LIMIT 20");
$query->execute([$town, $town, cfg('province')]);
$events = $query->fetchAll();
foreach ($events as &$event) $event['event_at'] = local_time($event['event_at']);
json_out(['events' => $events]);
