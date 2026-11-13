<?php
// Run with:  php tests/app_test.php
// Uses a throwaway database in your temp folder, never the real one.
$tmp = sys_get_temp_dir() . '/bk_test_' . getmypid();
define('BK_TEST_OVERRIDES', ['db_file' => "$tmp/db.sqlite", 'semaphore_key' => '', 'send_email' => false, 'sms_pause' => 0, 'backup_keep' => 3,
    'sms_code_daily_cap' => 5, 'admin_password' => 'change-me', 'base_url' => 'http://x.test', 'contact_email' => 'help@x.test']);
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/backtest.php';

$fail = 0;
function check(string $name, $expected, $actual): void {
    global $fail;
    if ($expected === $actual) { echo "PASS $name\n"; return; }
    $fail++;
    echo "FAIL $name: expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n";
}
$pdo = db();
$q = fn (string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s; })();
$one = fn (string $sql, array $p = []) => $q($sql, $p)->fetchColumn();

// settings
setting_set('x', ['a' => 1]);
check('settings round trip', ['a' => 1], setting_get('x'));
check('settings default', 'none', setting_get('missing', 'none'));

// rate limiting
check('rate limit: first two pass, third blocked', [false, false, true], [rate_limited('t1', 2, 60), rate_limited('t1', 2, 60), rate_limited('t1', 2, 60)]);
check('rate limit buckets are separate', false, rate_limited('t2', 2, 60));

// one-time codes
$phone = '09171234567';
$r = issue_code('confirm', $phone);
check('code issued', [true, 6], [$r['ok'], strlen($r['code'])]);
check('code is never saved in the message log', 0, (int) $one("SELECT COUNT(*) FROM messages WHERE body LIKE ?", ['%' . $r['code'] . '%']));
check('code message is logged with the code hidden', 1, (int) $one("SELECT COUNT(*) FROM messages WHERE body LIKE '%******%'"));
check('only a hash is stored', false, str_contains((string) $one('SELECT code_hash FROM codes'), $r['code']));
$again = issue_code('confirm', $phone);
check('a second code within a minute is refused', false, $again['ok']);
$wrong = $r['code'] === '111111' ? '222222' : '111111';
$w = check_code('confirm', $phone, $wrong);
check('wrong code is refused', [false, 'That code is not right.'], [$w['ok'], $w['error']]);
check('wrong code counts an attempt', 1, (int) $one('SELECT attempts FROM codes'));
check('right code works once', [true, false], [check_code('confirm', $phone, $r['code'])['ok'], check_code('confirm', $phone, $r['code'])['ok']]);

$pdo->exec("UPDATE codes SET created_at = datetime('now', '-120 seconds')");
$r = issue_code('confirm', $phone);
$pdo->exec("UPDATE codes SET created_at = datetime('now', '-120 seconds')");
$bad = $r['code'] === '123456' ? '654321' : '123456';
for ($i = 0; $i < 5; $i++) check_code('confirm', $phone, $bad);
$locked = check_code('confirm', $phone, $r['code']);
check('five wrong tries lock the code, even for the right one', [false, true], [$locked['ok'], str_contains($locked['error'], 'Too many wrong tries')]);
$r = issue_code('confirm', $phone);
check('asking again after the cooldown gives a fresh code', true, $r['ok']);
$pdo->exec("UPDATE codes SET created_at = datetime('now', '-120 seconds')");
$fourth = issue_code('confirm', $phone);
check('a fourth code to the same contact within an hour is refused (stops text-bombing a stranger)', [false, true], [$fourth['ok'], str_contains($fourth['error'], 'Too many codes were requested')]);
$pdo->exec("UPDATE codes SET expires_at = datetime('now', '-1 minute')");
check('expired code', false, check_code('confirm', $phone, $r['code'])['ok']);
check('purposes are separate', false, check_code('unsubscribe', $phone, $r['code'])['ok']);

$pdo->exec('DELETE FROM codes');
for ($i = 0; $i < 5; $i++) $pdo->prepare("INSERT INTO codes (purpose, target, code_hash, expires_at) VALUES ('confirm', ?, 'x', '2099-01-01 00:00:00')")->execute(['0900000000' . $i]);
$capped = issue_code('confirm', '09189999999');
check('daily cap on confirmation texts', [false, true], [$capped['ok'], str_contains($capped['error'], 'Too many codes')]);
$pdo->exec('DELETE FROM codes');

// subscribers: only confirmed people get alerts
$ins = $pdo->prepare("INSERT INTO subscribers (name, phone, language, status, unsub_token, municipality_id) VALUES (?, ?, 'en', ?, ?, 1)");
$ins->execute(['Active', '09170000001', 'active', 'tok1']);
$ins->execute(['Pending', '09170000002', 'pending', 'tok2']);
$pdo->exec('DELETE FROM messages');
send_alerts(['id' => 1, 'name' => 'San Pablo'], ['hazard' => 'drought', 'level' => 'warning', 'score' => 4, 'reasons' => []]);
check('alerts go only to confirmed subscribers', ['09170000001'], array_column($pdo->query('SELECT recipient FROM messages')->fetchAll(), 'recipient'));
$pdo->exec('DELETE FROM messages');
send_alerts(['id' => 1, 'name' => 'San Pablo'], ['hazard' => 'drought', 'level' => 'warning', 'score' => 4, 'reasons' => []], true);
check('demo alerts are only simulated', ['simulated'], array_column($pdo->query('SELECT DISTINCT status FROM messages')->fetchAll(), 'status'));
check('announcements reach confirmed subscribers only', 1, send_announcement('Test title', 'Body', 1));

// unsubscribe footer and contacts
check('unsubscribe link in emails', true, str_contains(unsub_footer(['unsub_token' => 'abc']), 'http://x.test/unsubscribe.php?t=abc'));
check('footer without a token falls back to the contact email', true, str_contains(unsub_footer([]), 'help@x.test'));
$pdo->exec("INSERT INTO contacts (municipality_id, label, phone) VALUES (NULL, 'PDRRMO', '0495-111-1111'), (1, 'MDRRMO', '0917 222 2222')");
check('contacts: national hotline first, then the town, then the province', 'Emergency contacts: National emergency hotline: 911; MDRRMO: 0917 222 2222; PDRRMO: 0495-111-1111.', contacts_text(1));
check('other towns only get the province-wide contact', 'Emergency contacts: National emergency hotline: 911; PDRRMO: 0495-111-1111.', contacts_text(2));

// masking
check('mask phone', '0917***567', mask_target('09171234567'));
check('mask email', 'a***@x.com', mask_target('ana@x.com'));

// cleanup
$pdo->exec("INSERT INTO subscribers (name, phone, status, unsub_token, municipality_id, created_at) VALUES ('Old pending', '09170000003', 'pending', 'tok3', 1, datetime('now', '-2 days'))");
cleanup_signups();
check('stale unconfirmed sign-ups are deleted, confirmed ones kept', [0, 1], [(int) $one("SELECT COUNT(*) FROM subscribers WHERE name = 'Old pending'"), (int) $one("SELECT COUNT(*) FROM subscribers WHERE name = 'Active'")]);

// backups
$b = backup_db('first');
$copy = new PDO('sqlite:' . $b);
check('backup is a working copy of the database', 30, (int) $copy->query('SELECT COUNT(*) FROM municipalities')->fetchColumn());
foreach (['b', 'c', 'd', 'e'] as $l) backup_db($l);
check('old backups are pruned to the limit', 3, count(glob("$tmp/backups/bantayklima-*.sqlite")));
backup_daily(); backup_daily();
check('only one automatic daily backup per day', 1, count(glob("$tmp/backups/bantayklima-*-daily.sqlite")));

// heartbeat and admin safety
check('no warnings before any refresh', [], data_health_warnings());
setting_set('last_refresh', ['at' => time(), 'mode' => 'demo:drought', 'complete' => true]);
check('demo data is announced', true, str_contains(data_health_warnings()[0], 'DEMO DATA'));
setting_set('last_refresh', ['at' => time() - 3600, 'mode' => 'live', 'complete' => true]);
check('fresh live data: no warning', [], data_health_warnings());
setting_set('last_refresh', ['at' => time() - 40 * 3600, 'mode' => 'live', 'complete' => true]);
check('stale live data: warning mentions the hours', true, str_contains(data_health_warnings()[0], '40 hours'));
check('refresh status line', true, str_contains(refresh_status_line(), 'live data'));
check('default password is weak', true, admin_password_weak());
check('default password: locked for other computers', [true, false, false], [admin_locked('203.0.113.9'), admin_locked('127.0.0.1'), admin_locked('::1')]);

// demo refresh: nothing real is sent, history is marked demo, heartbeat says demo
$pdo->exec('DELETE FROM messages');
$msg = refresh_all('drought');
check('demo refresh ran', true, str_contains($msg, 'demo: drought'));
check('demo runs are marked as demo data', [0, 30], [(int) $one("SELECT COUNT(*) FROM risk_assessments WHERE source = 'live'"), (int) $one("SELECT COUNT(*) FROM risk_assessments WHERE source = 'demo'")]);
check('demo refresh sends nothing real', 0, (int) $one("SELECT COUNT(*) FROM messages WHERE status NOT IN ('simulated')"));
check('heartbeat says demo', true, str_contains(data_health_warnings()[0], 'DEMO DATA'));

// thresholds saved in Admin are used by refresh
setting_set('thresholds', ['level_watch' => 9, 'level_warning' => 10, 'level_severe' => 11]);
$before = (int) $one('SELECT MAX(id) FROM risk_assessments');
refresh_all('drought');
check('very high thresholds turn a demo drought into all-normal', 0, (int) $one("SELECT COUNT(*) FROM risk_assessments WHERE id > ? AND level <> 'normal'", [$before]));
setting_set('thresholds', []);
$before = (int) $one('SELECT MAX(id) FROM risk_assessments');
refresh_all('drought');
check('default thresholds flag the same demo drought', true, (int) $one("SELECT COUNT(*) FROM risk_assessments WHERE id > ? AND level <> 'normal'", [$before]) > 20);

// back-test helpers
check('season code', ['DJF', 'NDJ'], [season_code(1), season_code(12)]);
$oni = oni_by_month("SEAS YR TOTAL ANOM\nDJF 2024 27.0 1.8\nJFM 2024 27.3 1.5\nNDJ 2024 26.0 -0.3\n");
check('ONI is filed by middle month', [1.8, 1.5, -0.3], [$oni['2024-01'], $oni['2024-02'], $oni['2024-12']]);
check('PAGASA rank and level rank', [0, 2, 3, 0, 2], [pagasa_rank('normal'), pagasa_rank('dry_spell'), pagasa_rank('drought'), level_rank('normal'), level_rank('warning')]);
check('months list', ['2023-11', '2023-12', '2024-01'], backtest_months('2023-11', '2024-01'));
$daily = []; for ($d = strtotime('2023-01-01'); $d <= strtotime('2024-12-31'); $d += 86400) $daily[date('Y-m-d', $d)] = date('Y', $d) === '2024' ? 2.0 : 5.0;
$norm = normals_from_daily($daily, 2024);
check('normals use only earlier years', 5.0, $norm['03-15']);
check('month rain ratio', 0.4, round(month_rain_ratio($daily, $norm, '2024-03'), 6));
check('month rain ratio is null when data is missing', null, month_rain_ratio($daily, $norm, '2025-03'));
check('median assessment', 2, median_assessment([['score' => 5], ['score' => 1], ['score' => 2]])['score']);
check('compare ranks', [['ours' => 2, 'theirs' => 3, 'diff' => -1], ['ours' => 0, 'theirs' => 2, 'diff' => -2]], [compare_ranks('drought', 'warning', 'drought'), compare_ranks('flood', 'severe', 'dry_spell')]);
$known = load_known_pagasa(__DIR__ . '/pagasa_laguna_known.csv');
check('known PAGASA statuses load', ['dry_spell', 'drought'], [$known['2024-03'], $known['2026-05']]);


// rate counters
rate_hit('x1'); rate_hit('x1');
check('rate_count counts hits', [2, 0], [rate_count('x1', 60), rate_count('other', 60)]);

// erasing a person: their messages and codes go too, unless another subscription still uses the contact
$mk = $pdo->prepare("INSERT INTO subscribers (name, phone, email, status, unsub_token, municipality_id) VALUES (?, '09170000009', 'f@x.com', 'active', ?, ?)");
$mk->execute(['Fay', 'f1f1f1f1', 1]); $idA = (int) $pdo->lastInsertId();
$mk->execute(['Fay again', 'f2f2f2f2', 2]); $idB = (int) $pdo->lastInsertId();
$pdo->exec("DELETE FROM messages");
$im = $pdo->prepare("INSERT INTO messages (channel, recipient, body, status) VALUES (?, ?, 'x', 'sent')");
foreach ([['sms', '09170000009'], ['sms', '639170000009'], ['email', 'f@x.com'], ['sms', '09170000001']] as $m) $im->execute($m);
$pdo->exec("INSERT INTO codes (purpose, target, code_hash, expires_at) VALUES ('confirm', '09170000009', 'h', '2099-01-01 00:00:00')");
forget_subscriber($idA);
check('another subscription still uses the contact: records stay', 4, (int) $one('SELECT COUNT(*) FROM messages'));
forget_subscriber($idB);
check('last subscription removed: that person\'s records are erased, others untouched', [1, '09170000001', 0], [(int) $one('SELECT COUNT(*) FROM messages'), $one('SELECT recipient FROM messages'), (int) $one("SELECT COUNT(*) FROM codes WHERE target = '09170000009'")]);

// retention and stale pending sign-ups
$pdo->exec("DELETE FROM messages");
$pdo->exec("INSERT INTO messages (channel, recipient, body, status, created_at) VALUES ('sms', '09170000001', 'old', 'sent', datetime('now', '-100 days')), ('sms', '09170000001', 'recent', 'sent', datetime('now', '-10 days'))");
$pdo->exec("INSERT INTO subscribers (name, phone, status, unsub_token, municipality_id, created_at) VALUES ('Stale', '09170000077', 'pending', 'e1e1e1e1', 1, datetime('now', '-2 days'))");
$pdo->exec("INSERT INTO messages (channel, recipient, body, status) VALUES ('sms', '09170000077', 'hidden code', 'sent')");
cleanup_signups();
check('messages older than the retention time are deleted, recent ones kept', ['recent'], array_column($pdo->query("SELECT body FROM messages WHERE recipient = '09170000001'")->fetchAll(), 'body'));
check('a stale unconfirmed sign-up is deleted with its messages', [0, 0], [(int) $one("SELECT COUNT(*) FROM subscribers WHERE name = 'Stale'"), (int) $one("SELECT COUNT(*) FROM messages WHERE recipient = '09170000077'")]);

// when to alert
$a = fn ($level, $hazard = 'drought') => ['level' => $level, 'hazard' => $hazard];
$now = 1000000; $h = 3600;
$cases = [
    'first assessment already at Warning alerts' => [alert_decision(null, null, $a('warning'), null, $now, 48), true],
    'first assessment normal is quiet' => [alert_decision(null, null, $a('normal', 'none'), null, $now, 48), false],
    'Watch to Warning alerts' => [alert_decision('watch', 'drought', $a('warning'), null, $now, 48), true],
    'Warning to Watch stays quiet' => [alert_decision('warning', 'drought', $a('watch'), null, $now, 48), false],
    'Warning back to normal sends the all-clear' => [alert_decision('warning', 'drought', $a('normal', 'none'), null, $now, 48), true],
    'Watch to normal is quiet' => [alert_decision('watch', 'drought', $a('normal', 'none'), null, $now, 48), false],
    'no change is quiet' => [alert_decision('warning', 'drought', $a('warning'), null, $now, 48), false],
    'Warning to Severe alerts' => [alert_decision('warning', 'drought', $a('severe'), ['hazard' => 'drought', 'level' => 'warning', 'at' => $now - $h], $now, 48), true],
    'Severe back to Warning alerts (still serious)' => [alert_decision('severe', 'drought', $a('warning'), ['hazard' => 'drought', 'level' => 'severe', 'at' => $now - $h], $now, 48), true],
    'a hovering level is not re-sent within 48 hours' => [alert_decision('watch', 'drought', $a('warning'), ['hazard' => 'drought', 'level' => 'warning', 'at' => $now - 10 * $h], $now, 48), false],
    'but is re-sent after 48 hours' => [alert_decision('watch', 'drought', $a('warning'), ['hazard' => 'drought', 'level' => 'warning', 'at' => $now - 49 * $h], $now, 48), true],
    'a different hazard at the same level alerts' => [alert_decision('warning', 'drought', $a('warning', 'flood'), ['hazard' => 'drought', 'level' => 'warning', 'at' => $now - $h], $now, 48), true],
];
foreach ($cases as $name => [$got, $want]) check("alert rule: $name", $want, $got);

// SMS credit guard
check('SMS allowance', [1000, 10, 0, 0, PHP_INT_MAX], [sms_allowance(0, 1000), sms_allowance(990, 1000), sms_allowance(1000, 1000), sms_allowance(2000, 1000), sms_allowance(5, 0)]);

// only one refresh at a time
$holder = fopen("$tmp/refresh.lock", 'c');
flock($holder, LOCK_EX | LOCK_NB);
check('a second refresh is refused while one is running', true, str_contains(refresh_all('normal'), 'already running'));
flock($holder, LOCK_UN); fclose($holder);
check('refresh works once the first one finishes', true, str_contains(refresh_all('normal'), 'Assessed 30'));

// self-check and private error log
$names = array_column(system_checks(), 'status', 'name');
check('system check knows whether HTTPS downloads are possible', 'ok', $names['Can download over HTTPS (live data, SMS)']);
check('system check runs and sees the weak admin password', ['fail', 'ok', 'ok'], [$names['Admin password'], $names['Contact email for the privacy notice'], $names['SQLite version']]);
bk_log_error('test entry');
check('errors are logged privately', true, str_contains((string) file_get_contents("$tmp/error.log"), 'test entry'));

// tidy up the throwaway files
foreach (array_merge(glob("$tmp/backups/*") ?: [], glob("$tmp/cache/*") ?: [], glob("$tmp/*") ?: []) as $f) { if (is_file($f)) @unlink($f); }
@rmdir("$tmp/backups"); @rmdir("$tmp/cache"); @rmdir($tmp);
exit($fail ? 1 : 0);
