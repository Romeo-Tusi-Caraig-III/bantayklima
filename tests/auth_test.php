<?php
// Run with:  php tests/auth_test.php   (throwaway database in your temp folder)
$tmp = sys_get_temp_dir() . '/bk_auth_' . getmypid();
define('BK_TEST_OVERRIDES', ['db_file' => "$tmp/db.sqlite", 'admin_user' => 'admin', 'admin_password' => 'change-me',
    'semaphore_key' => '', 'send_email' => false, 'sms_pause' => 0, 'backup_keep' => 3]);
require __DIR__ . '/../lib/bootstrap.php';
$fail = 0;
function check(string $name, $expected, $actual): void {
    global $fail;
    if ($expected === $actual) { echo "PASS $name\n"; return; }
    $fail++; echo "FAIL $name: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n";
}
$town = (int) db()->query('SELECT id FROM municipalities ORDER BY id LIMIT 1')->fetchColumn();
$town2 = (int) db()->query('SELECT id FROM municipalities ORDER BY id LIMIT 1 OFFSET 1')->fetchColumn();
$base = ['name' => 'Ana Reyes', 'phone' => '0917 123 4567', 'password' => 'secret1', 'municipality_id' => $town, 'sms_optin' => 1];

$r = register_user($base);
check('register works with just name, mobile, town and password', true, $r['ok']);
$sub = db()->query('SELECT * FROM subscribers')->fetchAll();
check('SMS opt-in makes an active subscriber at once (no code)', [1, 'active', '09171234567'], [count($sub), $sub[0]['status'] ?? null, $sub[0]['phone'] ?? null]);
check('same number cannot register twice', false, register_user($base)['ok']);
check('short password is refused', false, register_user(['phone' => '09181234567', 'password' => '123'] + $base)['ok']);
check('bad mobile number is refused', false, register_user(['phone' => '12345'] + $base)['ok']);
check('unknown town is refused', false, register_user(['phone' => '09181234567', 'municipality_id' => 99999] + $base)['ok']);
check('bad email is refused', false, register_user(['phone' => '09181234567', 'email' => 'nope'] + $base)['ok']);

check('login by mobile (different format) works', (int) $r['id'], (int) (login_user('+63 917 123 4567', 'secret1')['id'] ?? 0));
check('wrong password is refused', null, login_user('09171234567', 'wrong'));
db()->prepare('UPDATE users SET email = ? WHERE id = ?')->execute(['ana@x.ph', $r['id']]);
check('login by email works, any letter case', (int) $r['id'], (int) (login_user('ANA@x.ph', 'secret1')['id'] ?? 0));
check('passwords are stored hashed', true, password_verify('secret1', db()->query('SELECT password_hash FROM users')->fetchColumn()));

db()->prepare('UPDATE users SET municipality_id = ? WHERE id = ?')->execute([$town2, $r['id']]);
sync_subscriber((int) $r['id']);
check('changing town moves the subscription', $town2, (int) db()->query('SELECT municipality_id FROM subscribers')->fetchColumn());
db()->prepare('UPDATE users SET sms_optin = 0 WHERE id = ?')->execute([$r['id']]);
sync_subscriber((int) $r['id']);
check('SMS off removes the subscription', 0, (int) db()->query('SELECT COUNT(*) FROM subscribers')->fetchColumn());
db()->prepare('UPDATE users SET sms_optin = 1 WHERE id = ?')->execute([$r['id']]);
sync_subscriber((int) $r['id']);

$before = last_message_id();
$n = send_announcement('Test', 'Hello residents', $town2);
check('announcement reaches the account', 1, $n);
check('without a key the texts are only logged, and the result says so', true, str_contains(sms_result_text(sms_summary_since($before)), 'only recorded'));
check('long announcement is kept up to 2 parts', 306, strlen(sms_text(str_repeat('a', 400), 306)));
check('sms parts: 160 is one, 161 is two', [1, 2], [sms_parts(str_repeat('a', 160)), sms_parts(str_repeat('a', 161))]);

forget_subscriber((int) db()->query('SELECT id FROM subscribers')->fetchColumn());
sync_subscriber((int) $r['id']);
check('removing a subscription stays opted out after a profile sync', false, user_alerts_on((int) $r['id']));

delete_user((int) $r['id']);
check('deleting an account removes subscription and user', [0, 0], [(int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn(), (int) db()->query('SELECT COUNT(*) FROM subscribers')->fetchColumn()]);

// ---- PDF documents ----
$pdf = "$tmp/a.pdf"; file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
$txt = "$tmp/a.txt"; file_put_contents($txt, 'not a pdf');
$r = doc_save(['name' => 'PAGASA.pdf', 'tmp_name' => $pdf, 'size' => filesize($pdf), 'error' => UPLOAD_ERR_OK], '', '2026-10');
check('a real PDF is saved and listed', [true, 1], [$r['ok'], count(doc_list())]);
check('the saved PDF gets a default title and sits in uploads/pagasa', ['PAGASA October 2026', true], [doc_list()[0]['title'], is_file(uploads_dir('pagasa') . '/' . doc_list()[0]['filename'])]);
$bad = doc_save(['name' => 'x.pdf', 'tmp_name' => $txt, 'size' => 9, 'error' => UPLOAD_ERR_OK], 'x', '2026-10');
check('a file that is not a PDF is refused', false, $bad['ok']);
check('no file chosen is refused with a plain message', false, doc_save(['error' => UPLOAD_ERR_NO_FILE], '', '2026-10')['ok']);
check('too-big upload gets a friendly reason', true, str_contains(upload_error_text(UPLOAD_ERR_INI_SIZE), 'upload_max_filesize'));
$docId = doc_list()[0]['id']; $docFile = doc_list()[0]['filename'];
doc_delete((int) $docId);
check('removing a PDF deletes the file and the listing', [0, false], [count(doc_list()), is_file(uploads_dir('pagasa') . "/$docFile")]);

// ---- announcements ----
$town = (int) db()->query('SELECT id FROM municipalities LIMIT 1')->fetchColumn();
check('announcement needs a title and message', false, announce('', 'x', null, false)['ok']);
$long = announce('Title', str_repeat('a', 400), null, true);
check('too-long SMS is refused instead of silently cut', [false, true], [$long['ok'], str_contains((string) $long['error'], 'Too long')]);
check('...and is accepted when not texted', true, announce('Title', str_repeat('a', 400), null, false)['ok']);
$u = register_user(['name' => 'Ana', 'phone' => '09171110000', 'password' => 'secret1', 'municipality_id' => $town, 'sms_optin' => 1]);
$a = announce('Class suspension', 'No classes tomorrow.', $town, true);
check('announcement is texted to the area and the result is saved', [true, 1, 'log'], [$a['ok'], $a['people'], $a['mode']]);
$row = db()->query('SELECT * FROM announcements WHERE id = ' . (int) $a['id'])->fetch();
check('SMS result is stored with the announcement', [1, 'log'], [(int) $row['sms_people'], $row['sms_mode']]);
check('audience count matches', [1, 1], [sms_audience($town), sms_audience(null)]);

// ---- SMS key saved from the admin page (database beats config) ----
check('no key by default', '', sms_setting('key'));
setting_set('sms', ['key' => 'abc123', 'sender' => 'BantayKlima']);
check('saved SMS key and sender are used', ['abc123', 'BantayKlima'], [sms_setting('key'), sms_setting('sender')]);
setting_set('sms', []);

// ---- admin login ----
check('default admin password works until changed', [true, false], [admin_credentials_ok('admin', 'change-me'), admin_credentials_ok('admin', 'nope')]);
setting_set('admin_password_hash', password_hash('a-long-password', PASSWORD_DEFAULT));
check('a password chosen in admin replaces the default and is no longer weak', [true, false, false], [admin_credentials_ok('admin', 'a-long-password'), admin_credentials_ok('admin', 'change-me'), admin_password_weak()]);
check('after that, other computers can log in', false, admin_locked('203.0.113.9'));

// ---- email ----
check('email is not connected by default', false, email_connected());
setting_set('smtp', ['host' => 'smtp.gmail.com', 'port' => 465, 'secure' => 'ssl', 'user' => 'me@gmail.com', 'pass' => 'app', 'from_email' => 'me@gmail.com', 'from_name' => 'BantayKlima']);
check('saved SMTP settings are used', [true, 'smtp.gmail.com', 465, 'ssl'], [email_connected(), smtp_cfg()['host'], smtp_cfg()['port'], smtp_cfg()['secure']]);
$checks = system_checks();
check('connected email preserves the list of system checks', true, array_keys($checks) === range(0, count($checks) - 1));
check('connected email check does not leak the SMTP password', false, str_contains(json_encode($checks), '"pass"'));
check('connected email keeps earlier checks', 'PHP version', $checks[0]['name']);
setting_set('smtp', []);
check('removing the account disconnects email', false, email_connected());
check('without an account email is only logged', 'logged only', send_email('a@b.co', 'Hi', 'Body'));
check('header injection is neutralised (no line breaks survive)', false, (bool) preg_match('/[\r\n]/', mail_clean("Hi\r\nBcc: x@y.z")));
check('non-ASCII subjects are encoded', true, str_starts_with(mime_words('Walang pasok ñ'), '=?UTF-8?B?'));
$e1 = register_user(['name' => 'Eli', 'phone' => '09172220001', 'email' => 'eli@example.com', 'password' => 'secret1', 'municipality_id' => $town, 'sms_optin' => 1]);
$e2 = register_user(['name' => 'Eva', 'phone' => '09172220002', 'password' => 'secret1', 'municipality_id' => $town, 'sms_optin' => 1]);
check('email audience counts only residents with an email', 1, email_audience($town));
$ea = announce('Notice', 'Short text.', $town, false, true);
check('email announcement result is stored and says log-only when not connected', [true, 'log'], [$ea['ok'], $ea['email_mode']]);
exit($fail ? 1 : 0);
