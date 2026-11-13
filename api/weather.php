<?php
require __DIR__ . '/../lib/bootstrap.php';

$townId = filter_input(INPUT_GET, 'town', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$townId) json_out(['error' => 'Choose a Laguna municipality.'], 400);

$query = db()->prepare('SELECT lat, lng FROM municipalities WHERE id = ?');
$query->execute([$townId]);
$town = $query->fetch();
if (!$town) json_out(['error' => 'Municipality not found.'], 404);

$key = 'local-weather-v1-' . $townId;
$cached = cache_get($key, 600);
if ($cached) json_out(['stale' => false, 'forecast' => $cached]);

// One upstream request per municipality at a time. Use a recent copy during outages.
$lock = fopen(cache_path($key) . '.lock', 'c');
if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
    $cached = cache_get($key, 600);
    if ($cached) json_out(['stale' => false, 'forecast' => $cached]);

    // Back off after a failed attempt to avoid sending every refresh to a failing upstream.
    $retryKey = $key . '-attempt';
    if (!cache_get($retryKey, 60)) {
        cache_put($retryKey, true);
        $url = cfg('open_meteo_forecast') . '?' . http_build_query([
            'latitude' => $town['lat'], 'longitude' => $town['lng'], 'timezone' => 'Asia/Manila',
            'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,wind_speed_10m',
            'hourly' => 'precipitation_probability',
            'daily' => 'temperature_2m_min,temperature_2m_max,precipitation_probability_max',
            'forecast_days' => 5,
        ]);
        $body = http_get($url, 6);
        $forecast = $body === null ? null : json_decode($body, true);
        if (is_string($forecast['current']['time'] ?? null)
            && is_array($forecast['hourly']['time'] ?? null)
            && is_array($forecast['hourly']['precipitation_probability'] ?? null)
            && is_array($forecast['daily']['time'] ?? null)
            && is_array($forecast['daily']['temperature_2m_min'] ?? null)
            && is_array($forecast['daily']['temperature_2m_max'] ?? null)
            && is_array($forecast['daily']['precipitation_probability_max'] ?? null)) {
            cache_put($key, $forecast);
            json_out(['stale' => false, 'forecast' => $forecast]);
        }
    }
    flock($lock, LOCK_UN);
}
if ($lock) fclose($lock);

$cached = cache_get($key, 3 * 3600);
if ($cached) json_out(['stale' => true, 'forecast' => $cached]);
json_out(['error' => 'Local weather is temporarily unavailable.'], 503);
