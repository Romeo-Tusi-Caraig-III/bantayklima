<?php
require __DIR__ . '/../lib/bootstrap.php';
header('Cache-Control: no-store');
$u = current_user();
json_out(['user' => $u ? ['name' => $u['name'], 'municipality_id' => (int) $u['municipality_id'], 'town' => $u['town'],
    'language' => $u['language'], 'sms_optin' => user_alerts_on((int) $u['id'])] : null]);
