<?php
/** The final HTTP status in a list of response headers (after any redirects), or 0 if there is none. */
function status_from_headers(array $headers): int {
    $code = 0;
    foreach ($headers as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $h, $m)) $code = (int) $m[1];
    return $code;
}

/**
 * Fetch a URL with PHP's own streams (used when the curl extension is not enabled). Works on every PHP version:
 * the headers are read from the open stream, not from the old $http_response_header variable (deprecated in PHP 8.5).
 */
function stream_request(string $url, array $http): array {
    $fp = @fopen($url, 'rb', false, stream_context_create(['http' => $http + ['ignore_errors' => true]]));
    if (!$fp) return [0, null];
    $code = status_from_headers((array) (stream_get_meta_data($fp)['wrapper_data'] ?? []));
    $body = stream_get_contents($fp);
    fclose($fp);
    return [$code, $body === false ? null : $body];
}

/** Returns [HTTP status, body]. Status 0 means no connection at all. */
function http_request(string $url, int $timeout = 20): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => true]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$code, $body === false ? null : $body];
    }
    return stream_request($url, ['timeout' => $timeout]);
}

/** POST a form. Returns [HTTP status, body]; status 0 means no connection at all. */
/** Plain-words reason the last curl request failed ('' when it did not). */
function http_last_error(?string $set = null): string { static $e = ''; if ($set !== null) $e = $set; return $e; }

function http_post(string $url, array $fields, int $timeout = 20): array {
    http_last_error('');
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) {
            $err = curl_error($ch);
            http_last_error(stripos($err, 'certificate') !== false || stripos($err, 'SSL') !== false
                ? $err . '. Fix: download cacert.pem from curl.se/docs/caextract.html, save it in C:\\xampp\\php\\extras\\ssl\\, and set curl.cainfo to it in php.ini, then restart Apache.'
                : $err);
        }
        return [$code, $body === false ? null : $body];
    }
    return stream_request($url, ['method' => 'POST', 'timeout' => $timeout,
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => http_build_query($fields)]);
}

function http_get(string $url, int $timeout = 20): ?string {
    [$code, $body] = http_request($url, $timeout);
    return $code === 200 ? $body : null;
}

function cache_path(string $key): string {
    $dir = __DIR__ . '/../data/cache';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    return $dir . '/' . md5($key) . '.json';
}
function cache_get(string $key, int $ttl) {
    $p = cache_path($key);
    return (is_file($p) && time() - filemtime($p) < $ttl) ? json_decode(file_get_contents($p), true) : null;
}
function cache_put(string $key, $value): void { file_put_contents(cache_path($key), json_encode($value)); }

/** Call the free Open-Meteo API politely: pause after each call and retry if it says "slow down" (429) or has a server error. */
function open_meteo(string $url, int $timeout, int $pauseAfter): ?array {
    foreach ((array) cfg('retry_waits') as $wait) {
        if ($wait) sleep((int) $wait);
        [$code, $body] = http_request($url, $timeout);
        if ($code === 200 && $body !== null) {
            if ($pauseAfter > 0) sleep($pauseAfter);
            return json_decode($body, true);
        }
        if ($code !== 429 && $code < 500) return null; // no connection or a real error: retrying will not help
    }
    return null;
}

/** Snap a coordinate to a 0.25 degree grid (about 25 km, the resolution of the weather data), so nearby towns share one download. */
function snap(float $v): float { return round($v * 4) / 4; }

/** Latest value from NOAA CPC's oni.ascii.txt (columns: SEAS YR TOTAL ANOM, one row per 3-month season). */
function parse_oni(string $body): ?float {
    $last = null;
    foreach (preg_split('/\R/', trim($body)) as $line) {
        $p = preg_split('/\s+/', trim($line));
        if (count($p) >= 4 && is_numeric($p[1]) && is_numeric($p[3])) $last = (float) $p[3];
    }
    return $last;
}

function oni(): ?float {
    $cached = cache_get('oni', 86400);
    if ($cached !== null) return (float) $cached;

    $body = http_get(cfg('oni_url'));
    $value = $body === null ? null : parse_oni($body);
    if ($value !== null) cache_put('oni', $value);
    return $value;
}

/** Rainfall over a 44-day series (30 past days then 14 forecast days) as ratios of normal: [recent, forecast]. */
function window_ratios(array $dates, array $mm, array $normal): array {
    $actual = ['recent' => 0.0, 'forecast' => 0.0];
    $expected = ['recent' => 0.0, 'forecast' => 0.0];
    foreach ($dates as $i => $date) {
        $part = $i < 30 ? 'recent' : 'forecast';
        $actual[$part] += (float) ($mm[$i] ?? 0);
        $expected[$part] += $normal[substr($date, 5)] ?? 0;
    }
    // Ignore windows where "normal" is so small that a ratio would be meaningless.
    $ratio = fn ($p) => $expected[$p] >= 10 ? $actual[$p] / $expected[$p] : null;
    return [$ratio('recent'), $ratio('forecast')];
}

/** Rainfall vs normal for a place. 'ok' is false when the weather data could not be fetched. */
function rainfall_ratios(float $lat, float $lng): array {
    static $memo = [];
    $lat = snap($lat);
    $lng = snap($lng);
    $key = "$lat,$lng";
    if (isset($memo[$key])) return $memo[$key];
    if ($hit = cache_get('ratios-v2.' . $key, 3 * 3600)) return $memo[$key] = $hit;

    $fail = ['recent' => null, 'recent_90' => null, 'forecast' => null, 'ok' => false];
    $normal = daily_normals($lat, $lng);
    if (!$normal) return $memo[$key] = $fail;

    $data = open_meteo(cfg('open_meteo_forecast') . '?' . http_build_query([
        'latitude' => $lat, 'longitude' => $lng, 'daily' => 'precipitation_sum',
        'past_days' => 90, 'forecast_days' => 14, 'timezone' => 'Asia/Manila',
    ]), 30, (int) cfg('pause_forecast'));
    if (!$data || empty($data['daily']['time'])) return $memo[$key] = $fail;

    $dates = $data['daily']['time'];
    $mm = $data['daily']['precipitation_sum'] ?? [];
    if (count($dates) !== 104 || count($mm) !== 104) return $memo[$key] = $fail;
    [$recent, $forecast] = window_ratios(array_slice($dates, 60), array_slice($mm, 60), $normal);
    $actual = $expected = 0.0;
    $complete = true;
    foreach (array_slice($dates, 0, 90) as $i => $date) {
        $typical = $normal[substr($date, 5)] ?? null;
        if (!is_numeric($mm[$i]) || !is_numeric($typical)) { $complete = false; break; }
        $actual += $mm[$i];
        $expected += $typical;
    }
    $result = ['recent' => $recent, 'recent_90' => $complete && $expected >= 10 ? $actual / $expected : null,
        'forecast' => $forecast, 'ok' => $recent !== null && $forecast !== null];
    cache_put('ratios-v2.' . $key, $result);
    return $memo[$key] = $result;
}

/** Average rainfall per calendar day (keyed "MM-DD") from Open-Meteo's historical archive. */
function daily_normals(float $lat, float $lng): array {
    $key = 'normals.' . $lat . '.' . $lng;
    if ($cached = cache_get($key, 30 * 86400)) return $cached;

    $years = (int) cfg('normal_years');
    $data = open_meteo(cfg('open_meteo_archive') . '?' . http_build_query([
        'latitude' => $lat, 'longitude' => $lng, 'daily' => 'precipitation_sum',
        'start_date' => (date('Y') - $years) . '-01-01', 'end_date' => (date('Y') - 1) . '-12-31',
        'timezone' => 'Asia/Manila',
    ]), 60, (int) cfg('pause_archive'));
    if (!$data || empty($data['daily']['time'])) return [];

    $sum = []; $count = [];
    foreach ($data['daily']['time'] as $i => $d) {
        $k = substr($d, 5);
        $sum[$k] = ($sum[$k] ?? 0) + (float) ($data['daily']['precipitation_sum'][$i] ?? 0);
        $count[$k] = ($count[$k] ?? 0) + 1;
    }
    $normals = [];
    foreach ($sum as $k => $s) $normals[$k] = $s / $count[$k];
    cache_put($key, $normals);
    return $normals;
}
