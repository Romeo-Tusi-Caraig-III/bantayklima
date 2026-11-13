<?php
/**
 * Should a change of risk level send alerts? Yes for Warning or Severe, and for the all-clear after one.
 * Going from Warning down to Watch stays quiet, and the same Warning/Severe is not re-sent inside the cooldown
 * (so a level that hovers around a line does not flood people with texts).
 */
function alert_decision(?string $prevLevel, ?string $prevHazard, array $a, ?array $lastAlert, int $now, int $cooldownHours): bool {
    $levels = ['normal', 'watch', 'warning', 'severe'];
    $idx = fn ($l) => (int) array_search($l, $levels, true);
    $changed = $prevLevel === null || $prevLevel !== $a['level'] || $prevHazard !== $a['hazard'];
    if (!$changed) return false;
    $serious = $idx($a['level']) >= 2 || ($a['level'] === 'normal' && $prevLevel !== null && $idx($prevLevel) >= 2);
    if (!$serious) return false;
    if ($lastAlert && $lastAlert['hazard'] === $a['hazard'] && $lastAlert['level'] === $a['level']
        && $now - (int) $lastAlert['at'] < $cooldownHours * 3600) return false;
    return true;
}

/** Assess every municipality and alert subscribers when the level changes. $demo = drought|flood|normal for offline demo data. */
function refresh_all(?string $demo = null): string {
    $dir = dirname((string) cfg('db_file'));
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $lock = fopen($dir . '/refresh.lock', 'c');
    // Only one refresh at a time (the scheduler and the admin button could otherwise overlap and send alerts twice).
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return 'A refresh is already running. Please wait for it to finish, then try again.';
    try {
        return refresh_now($demo);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function refresh_now(?string $demo): string {
    set_time_limit(600); // the first live run downloads rainfall history, paced to respect Open-Meteo's limits
    if ($demo && !in_array($demo, ['drought', 'flood', 'normal'], true)) return 'Demo must be drought, flood or normal.';

    $pdo = db();
    $thresholds = array_merge(risk_default_thresholds(), (array) setting_get('thresholds', []));
    $source = $demo ? 'demo' : 'live';
    $alerted = $demo ? [] : (array) setting_get('alerted', []); // last alert sent for each town (live only)
    if ($demo) {
        $oni = ['drought' => 1.2, 'flood' => -1.1, 'normal' => 0.1][$demo];
        $status = ['drought' => 'drought', 'flood' => 'above_normal', 'normal' => 'normal'][$demo];
    } else {
        $st = $pdo->prepare('SELECT rainfall_status, issued, created_at FROM advisories WHERE province = ? ORDER BY id DESC LIMIT 1');
        $st->execute([cfg('province')]);
        $adv = $st->fetch() ?: null;
        $status = $adv['rainfall_status'] ?? null;
        $oni = oni();
    }

    // The previous level to compare with comes from the same kind of run, so demo runs never trigger real alerts.
    $towns = $pdo->prepare('SELECT m.*,
        (SELECT level FROM risk_assessments WHERE municipality_id = m.id AND source = ? ORDER BY id DESC LIMIT 1) AS prev_level,
        (SELECT hazard FROM risk_assessments WHERE municipality_id = m.id AND source = ? ORDER BY id DESC LIMIT 1) AS prev_hazard
        FROM municipalities m');
    $towns->execute([$source, $source]);
    $towns = $towns->fetchAll();
    $insert = $pdo->prepare('INSERT INTO risk_assessments (municipality_id, hazard, level, score, reasons, oni, recent_ratio, forecast_ratio, data_ok, source, recent_90_ratio)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $incomplete = 0;

    foreach ($towns as $m) {
        $ok = true;
        $recent90 = null;
        if ($demo) {
            $k = $m['id'] % 5; // spread results so the demo map is not one solid color
            [$recent, $forecast] = match ($demo) {
                'drought' => [0.45 + $k * 0.1, 0.4 + $k * 0.1],
                'flood'   => [1.4 + $k * 0.2, 1.5 + $k * 0.2],
                default   => [1.0, 1.0],
            };
        } else {
            $r = rainfall_ratios((float) $m['lat'], (float) $m['lng']);
            [$recent, $forecast] = [$r['recent'], $r['forecast']];
            $recent90 = $r['recent_90'];
            $ok = $r['ok'] && $oni !== null; // missing data must never look like "normal" without a warning
        }
        if (!$ok) $incomplete++;

        $a = risk_assess($oni, $status, $recent, $forecast, $thresholds);
        $insert->execute([$m['id'], $a['hazard'], $a['level'], $a['score'],
            json_encode($a['reasons'], JSON_UNESCAPED_UNICODE), $oni, $recent, $forecast, $ok ? 1 : 0, $source, $recent90]);

        // Only alert on complete data, so a failed download can never send a false "all clear".
        if ($ok && alert_decision($m['prev_level'], $m['prev_hazard'], $a, $alerted[(string) $m['id']] ?? null, time(), (int) cfg('alert_cooldown_hours'))) {
            send_alerts($m, $a, (bool) $demo); // demo runs only simulate
            if (!$demo) $alerted[(string) $m['id']] = ['hazard' => $a['hazard'], 'level' => $a['level'], 'at' => time()];
        }
    }
    if (!$demo) setting_set('alerted', $alerted);

    // Heartbeat: the dashboard uses this to say when the data is demo data or the daily update has stopped.
    setting_set('last_refresh', ['at' => time(), 'mode' => $demo ? "demo:$demo" : 'live', 'complete' => $incomplete === 0]);
    if (!$demo) backup_daily();
    cleanup_signups();

    $msg = 'Assessed ' . count($towns) . ' municipalities' . ($demo ? " (demo: $demo)." : '.');
    if ($incomplete) $msg .= " Warning: live data was incomplete for $incomplete of them" . ($oni === null ? ' (ONI unavailable)' : '') . ', so their levels may be understated.';
    foreach (advisory_warnings($adv ?? null, !$demo) as $w) $msg .= ' Note: ' . $w;
    return $msg;
}
