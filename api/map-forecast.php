<?php
require __DIR__ . '/../lib/bootstrap.php';

$key = 'laguna-map-forecast-v1';
$result = cache_get($key, 900);
if ($result) json_out($result + ['stale' => false]);

$lock = fopen(cache_path($key) . '.lock', 'c');
if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
    $result = cache_get($key, 900);
    if ($result) json_out($result + ['stale' => false]);

    if (!cache_get($key . '-attempt', 60)) {
        cache_put($key . '-attempt', true);
        $towns = db()->query('SELECT id, lat, lng FROM municipalities ORDER BY id')->fetchAll();
        $south = min(array_column($towns, 'lat')) - .12;
        $north = max(array_column($towns, 'lat')) + .12;
        $west = min(array_column($towns, 'lng')) - .12;
        $east = max(array_column($towns, 'lng')) + .12;
        $size = 6;
        $points = $towns;
        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size; $col++) {
                $points[] = ['lat' => $north - ($north - $south) * $row / ($size - 1),
                    'lng' => $west + ($east - $west) * $col / ($size - 1)];
            }
        }
        $fields = ['temperature_2m', 'precipitation', 'precipitation_probability', 'wind_speed_10m', 'wind_direction_10m'];
        $url = cfg('open_meteo_forecast') . '?' . http_build_query([
            'latitude' => implode(',', array_map(fn ($p) => round($p['lat'], 5), $points)),
            'longitude' => implode(',', array_map(fn ($p) => round($p['lng'], 5), $points)),
            'hourly' => implode(',', $fields), 'forecast_hours' => 48, 'timezone' => 'Asia/Manila',
        ]);
        $data = json_decode(http_get($url, 15) ?? '', true);
        $time = $data[0]['hourly']['time'] ?? [];
        if (is_array($data) && count($data) === count($points) && count($time) === 48) {
            $townSeries = $grid = [];
            foreach ($points as $i => $point) {
                $hourly = $data[$i]['hourly'] ?? [];
                if (($hourly['time'] ?? null) !== $time) break;
                $series = [];
                foreach ($fields as $field) {
                    if (!isset($hourly[$field]) || count($hourly[$field]) !== count($time)) break 2;
                    $series[$field] = $hourly[$field];
                }
                if (isset($point['id'])) $townSeries[(int) $point['id']] = $series;
                else $grid[] = $series;
            }
            if (count($grid) === $size * $size) {
                $result = ['time' => $time, 'towns' => $townSeries, 'grid' => $grid, 'size' => $size,
                    'bounds' => [[$south, $west], [$north, $east]], 'fetched' => time()];
                cache_put($key, $result);
                json_out($result + ['stale' => false]);
            }
        }
    }
}
if ($lock) fclose($lock);
$result = cache_get($key, 3 * 3600);
if ($result) json_out($result + ['stale' => true]);
json_out(['error' => 'The forecast map is temporarily unavailable.'], 503);
