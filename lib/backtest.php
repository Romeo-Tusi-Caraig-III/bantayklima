<?php
/** Helpers for cli/backtest.php: replay past months through the risk rules and compare with PAGASA's tables. */

function season_code(int $month): string {
    return ['DJF', 'JFM', 'FMA', 'MAM', 'AMJ', 'MJJ', 'JJA', 'JAS', 'ASO', 'SON', 'OND', 'NDJ'][$month - 1];
}

/** 'YYYY-MM' => ONI from NOAA's oni.ascii.txt. Each 3-month season is filed under its middle month (DJF = January). */
function oni_by_month(string $body): array {
    $codes = array_flip(array_map('season_code', range(1, 12)));
    $out = [];
    foreach (preg_split('/\R/', trim($body)) as $line) {
        $p = preg_split('/\s+/', trim($line));
        if (count($p) >= 4 && isset($codes[$p[0]]) && is_numeric($p[1]) && is_numeric($p[3])) {
            $out[sprintf('%04d-%02d', (int) $p[1], $codes[$p[0]] + 1)] = (float) $p[3];
        }
    }
    return $out;
}

function pagasa_rank(string $status): int { return ['normal' => 0, 'dry_condition' => 1, 'dry_spell' => 2, 'drought' => 3][$status] ?? 0; }
function level_rank(string $level): int { return (int) array_search($level, ['normal', 'watch', 'warning', 'severe'], true); }

/** Months from $from to $to inclusive, as 'YYYY-MM'. */
function backtest_months(string $from, string $to): array {
    $d = new DateTime($from . '-01');
    $end = new DateTime($to . '-01');
    $out = [];
    while ($d <= $end) { $out[] = $d->format('Y-m'); $d->modify('+1 month'); }
    return $out;
}

/** Average rainfall per calendar day ("MM-DD") using only years before $beforeYear. */
function normals_from_daily(array $daily, int $beforeYear): array {
    $sum = []; $n = [];
    foreach ($daily as $date => $mm) {
        if ((int) substr($date, 0, 4) >= $beforeYear) continue;
        $k = substr($date, 5);
        $sum[$k] = ($sum[$k] ?? 0) + (float) $mm;
        $n[$k] = ($n[$k] ?? 0) + 1;
    }
    $out = [];
    foreach ($sum as $k => $s) $out[$k] = $s / $n[$k];
    return $out;
}

/** Rain in the 30 days ending on the last day of $month, divided by normal. Null if the data is missing or normal is too small. */
function month_rain_ratio(array $daily, array $normals, string $month): ?float {
    $end = new DateTime($month . '-01');
    $end->modify('last day of this month');
    $actual = 0.0; $expected = 0.0;
    for ($i = 0; $i < 30; $i++) {
        $d = (clone $end)->modify("-$i days")->format('Y-m-d');
        if (!array_key_exists($d, $daily)) return null;
        $actual += (float) $daily[$d];
        $expected += $normals[substr($d, 5)] ?? 0;
    }
    return $expected >= 10 ? $actual / $expected : null;
}

/** The middle assessment when ordered by score (so one odd grid cell does not decide for the whole province). */
function median_assessment(array $assessments): array {
    usort($assessments, fn ($a, $b) => $a['score'] <=> $b['score']);
    return $assessments[intdiv(count($assessments) - 1, 2)];
}

function median_of(array $values): ?float {
    $values = array_values(array_filter($values, fn ($v) => $v !== null));
    if (!$values) return null;
    sort($values);
    return $values[intdiv(count($values) - 1, 2)];
}

/** Months with a known PAGASA status for the province, from a CSV with columns month,status,source. */
function load_known_pagasa(string $path): array {
    $out = [];
    if (!is_file($path)) return $out;
    $h = fopen($path, 'r');
    fgetcsv($h, 0, ',', '"', ''); // header row
    while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
        if (count($r) >= 2 && preg_match('/^\d{4}-\d{2}$/', trim($r[0]))) $out[trim($r[0])] = trim($r[1]);
    }
    fclose($h);
    return $out;
}

/** Compare our drought level with PAGASA's status (both on a 0-3 scale). Other hazards count as 0 (no drought flagged). */
function compare_ranks(string $hazard, string $level, string $pagasaStatus): array {
    $ours = $hazard === 'drought' ? level_rank($level) : 0;
    $theirs = pagasa_rank($pagasaStatus);
    return ['ours' => $ours, 'theirs' => $theirs, 'diff' => $ours - $theirs];
}

/**
 * Replay each month since $from through the risk rules using only the ONI and the last 30 days of rain
 * (no forecast and no PAGASA input exist for the past), and compare with PAGASA's known statuses.
 */
function run_backtest(string $from, string $knownPath): array {
    $thresholds = array_merge(risk_default_thresholds(), (array) setting_get('thresholds', []));
    $body = http_get(cfg('oni_url'));
    if ($body === null) return ['error' => 'Could not download the ONI file from NOAA.'];
    $oni = oni_by_month($body);
    if (!$oni) return ['error' => 'The ONI file was downloaded but no values could be read from it.'];

    $cells = [];
    foreach (db()->query('SELECT lat, lng FROM municipalities')->fetchAll() as $m) {
        $cells[snap((float) $m['lat']) . ',' . snap((float) $m['lng'])] = [snap((float) $m['lat']), snap((float) $m['lng'])];
    }

    $fromYear = (int) substr($from, 0, 4);
    $perCell = [];
    foreach ($cells as $key => [$lat, $lng]) {
        $data = open_meteo(cfg('open_meteo_archive') . '?' . http_build_query([
            'latitude' => $lat, 'longitude' => $lng, 'daily' => 'precipitation_sum',
            'start_date' => ($fromYear - (int) cfg('normal_years')) . '-01-01', 'end_date' => date('Y-m-d', strtotime('-1 day')),
            'timezone' => 'Asia/Manila',
        ]), 90, (int) cfg('pause_archive'));
        if (!$data || empty($data['daily']['time'])) return ['error' => "Could not download rainfall history for the grid cell $key."];
        $mm = array_pad(array_map(fn ($v) => $v ?? 0, $data['daily']['precipitation_sum'] ?? []), count($data['daily']['time']), 0);
        $daily = array_combine($data['daily']['time'], array_slice($mm, 0, count($data['daily']['time'])));
        $perCell[$key] = ['daily' => $daily, 'normals' => normals_from_daily($daily, $fromYear)];
    }

    $known = load_known_pagasa($knownPath);
    $rows = [];
    foreach (backtest_months($from, date('Y-m', strtotime('first day of last month'))) as $month) {
        $ratios = []; $assessments = [];
        foreach ($perCell as $c) {
            $ratio = month_rain_ratio($c['daily'], $c['normals'], $month);
            $ratios[] = $ratio;
            $assessments[] = risk_assess($oni[$month] ?? null, null, $ratio, null, $thresholds);
        }
        $a = median_assessment($assessments);
        $row = ['month' => $month, 'oni' => $oni[$month] ?? null, 'rain' => median_of($ratios), 'hazard' => $a['hazard'], 'level' => $a['level'],
            'score' => $a['score'], 'pagasa' => $known[$month] ?? null, 'ours' => null, 'theirs' => null, 'diff' => null];
        if ($row['pagasa'] !== null) {
            $cmp = compare_ranks($a['hazard'], $a['level'], $row['pagasa']);
            $row['ours'] = $cmp['ours']; $row['theirs'] = $cmp['theirs']; $row['diff'] = $cmp['diff'];
        }
        $rows[] = $row;
    }

    $compared = array_values(array_filter($rows, fn ($r) => $r['diff'] !== null));
    return ['error' => null, 'rows' => $rows, 'summary' => [
        'months' => count($rows), 'compared' => count($compared),
        'exact' => count(array_filter($compared, fn ($r) => $r['diff'] === 0)),
        'within_one' => count(array_filter($compared, fn ($r) => abs($r['diff']) <= 1)),
        'we_missed' => count(array_filter($compared, fn ($r) => $r['diff'] < 0)),
        'we_overshot' => count(array_filter($compared, fn ($r) => $r['diff'] > 0)),
    ]];
}
