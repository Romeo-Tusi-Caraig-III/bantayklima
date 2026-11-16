<?php
// Run with:  php tests/risk_test.php
require __DIR__ . '/../lib/risk.php';
require __DIR__ . '/../lib/notify.php'; // these two only define functions, so they are safe to load here
require __DIR__ . '/../lib/climate.php';

$fail = 0;
function check(string $name, $expected, $actual): void {
    global $fail;
    if ($expected === $actual) { echo "PASS $name\n"; return; }
    $fail++;
    echo "FAIL $name: expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n";
}

$r = risk_assess(0.1, 'normal', 1.0, 1.0);
check('normal conditions', ['none', 'normal'], [$r['hazard'], $r['level']]);

$r = risk_assess(1.2, 'drought', 0.4, 0.4);
check('El Niño + drought + dry weather', ['drought', 'severe'], [$r['hazard'], $r['level']]);

$r = risk_assess(-1.1, 'above_normal', 2.2, 2.3);
check('La Niña + heavy rain', ['flood', 'severe'], [$r['hazard'], $r['level']]);

$r = risk_assess(0.6, 'dry_condition', null, null);
check('moderate signals give a watch', ['drought', 'watch'], [$r['hazard'], $r['level']]);

$r = risk_assess(null, null, null, null);
check('missing data does not crash', 'normal', $r['level']);

// Filipino alerts
check('Filipino advice', true, str_starts_with(risk_advice('drought', 'warning', 'fil'), 'Mataas ang panganib ng tagtuyot'));
check('unknown language falls back to English', risk_advice('flood', 'severe'), risk_advice('flood', 'severe', 'xx'));
check('Filipino normal advice', true, str_starts_with(risk_advice('none', 'normal', 'fil'), 'Walang nakitang panganib'));
check('English headline', 'BantayKlima Calamba: DROUGHT SEVERE.', risk_head('Calamba', ['hazard' => 'drought', 'level' => 'severe'], 'en'));
check('Filipino headline', 'BantayKlima Calamba: MATINDING BABALA - TAGTUYOT.', risk_head('Calamba', ['hazard' => 'drought', 'level' => 'severe'], 'fil'));
check('all clear (Filipino)', 'BantayKlima Bay: NORMAL NA ANG KALAGAYAN.', risk_head('Bay', ['hazard' => 'none', 'level' => 'normal'], 'fil'));
check('SMS drops ñ', 'BantayKlima Binan: El Nino', sms_text('BantayKlima Biñan: El Niño'));
check('SMS is one message', 160, strlen(sms_text(str_repeat('a', 300))));

// Live-data helpers
$oniSample = "SEAS  YR   TOTAL   ANOM\nDJF 1950   24.72  -1.53\nJFM 1950   25.17  -1.34\nJJA 2026   28.91   1.80\n";
check('ONI parser takes the latest season', 1.8, parse_oni($oniSample));
check('ONI parser ignores junk', null, parse_oni("<html>not found</html>"));
check('snap to 0.25 degree grid', [14.0, 14.25, 121.25], [snap(14.0683), snap(14.2117), snap(121.2430)]);

$dates = [];
for ($i = 0; $i < 44; $i++) $dates[] = date('Y-m-d', strtotime("2026-09-01 +$i days"));
$normal = [];
foreach ($dates as $d) $normal[substr($d, 5)] = 5.0; // 5 mm a day is "normal"
$mm = array_merge(array_fill(0, 30, 2.5), array_fill(0, 14, 10.0)); // dry past month, wet forecast
[$recent, $forecast] = window_ratios($dates, $mm, $normal);
check('recent rainfall ratio', 0.5, $recent);
check('forecast rainfall ratio', 2.0, $forecast);
[$r2] = window_ratios($dates, $mm, array_map(fn ($v) => 0.1, $normal));
check('tiny normals give no ratio', null, $r2);

// SMS helpers
foreach (['09171234567', '+639171234567', '639171234567', '9171234567', '0917 123 4567', '+63 917-123-4567'] as $raw) {
    check("phone format $raw", '09171234567', normalize_ph_phone($raw));
}
foreach (['12345', '0817123456', '09171234', 'abc', ''] as $raw) check("bad phone '$raw'", null, normalize_ph_phone($raw));

$ok = parse_semaphore_response('[{"message_id":1,"recipient":"639171234567","status":"Pending"},{"message_id":2,"recipient":"639181234567","status":"Queued"}]');
check('Semaphore success reply', [true, 2, 'pending', 'queued'], [$ok['ok'], count($ok['items']), $ok['items'][0]['status'], $ok['items'][1]['status']]);
$bad = parse_semaphore_response('{"apikey":["The selected apikey is invalid."]}');
check('Semaphore error reply', [false, true], [$bad['ok'], str_contains($bad['error'], 'invalid')]);
check('Semaphore empty reply', false, parse_semaphore_response(null)['ok']);

// Adjustable thresholds
$d = risk_default_thresholds();
check('defaults give the same result as before', 'severe', risk_assess(1.2, 'drought', 0.4, 0.4, $d)['level']);
check('ONI 0.6 alone is below Watch by default', 'normal', risk_assess(0.6, null, null, null)['level']);
check('a lower Watch score makes it a Watch', 'watch', risk_assess(0.6, null, null, null, ['level_watch' => 1])['level']);
check('a higher ONI threshold removes the ONI points', 'normal', risk_assess(0.6, 'dry_condition', null, null, ['oni_moderate' => 0.8, 'level_watch' => 2])['level']);
check('a stricter "wet" cut-off changes a flood call', 'normal', risk_assess(null, null, 1.8, 1.8, ['recent_wet' => 2.5, 'recent_wet_strong' => 3.0, 'forecast_wet' => 2.5, 'forecast_wet_strong' => 3.0])['level']);
check('PAGASA points can be changed', 'severe', risk_assess(null, 'drought', null, null, ['pagasa_drought' => 6])['level']);

[$clean, $errs] = risk_validate_thresholds([]);
check('empty form falls back to defaults and is valid', [$d, []], [$clean, $errs]);
[$clean, $errs] = risk_validate_thresholds(['oni_moderate' => '1.5', 'oni_strong' => '1.0']);
check('strong ONI must exceed the normal one', true, count($errs) === 1 && str_contains($errs[0], 'strong ONI'));
[$clean, $errs] = risk_validate_thresholds(['recent_dry' => 'abc']);
check('non-numbers are rejected', true, count($errs) >= 1);
[$clean, $errs] = risk_validate_thresholds(['level_watch' => '5', 'level_warning' => '4']);
check('levels must go up in order', true, count($errs) === 1 && str_contains($errs[0], 'order'));
[$clean, $errs] = risk_validate_thresholds(['level_watch' => '2.5']);
check('whole numbers only for scores', true, count($errs) >= 1);
[$clean, $errs] = risk_validate_thresholds(['recent_dry' => '1.2']);
check('"dry" must be below normal', true, count($errs) >= 1);

// HTTP status reading (used when PHP has no curl)
check('status: simple', 200, status_from_headers(['HTTP/1.1 200 OK', 'Content-Type: text/plain']));
check('status: the final status after a redirect', 200, status_from_headers(['HTTP/1.1 301 Moved Permanently', 'Location: /x', 'HTTP/1.1 200 OK']));
check('status: rate limited', 429, status_from_headers(['HTTP/1.1 429 Too Many Requests']));
check('status: HTTP/2 style', 200, status_from_headers(['HTTP/2 200']));
check('status: no headers means no connection', 0, status_from_headers([]));

exit($fail ? 1 : 0);
