<?php
// Hands an uploaded PDF to a visitor:  file.php?id=3  (opens in the browser)  or  file.php?id=3&dl=1  (downloads).
require __DIR__ . '/lib/bootstrap.php';

$st = db()->prepare('SELECT * FROM documents WHERE id = ?');
$st->execute([(int) ($_GET['id'] ?? 0)]);
$d = $st->fetch();
$path = $d && preg_match('/^[A-Za-z0-9._-]+\.pdf$/', $d['filename']) ? uploads_dir('pagasa') . '/' . $d['filename'] : '';
if (!$path || !is_file($path)) { http_response_code(404); exit('This PDF is no longer available.'); }

$nice = preg_replace('/[^A-Za-z0-9._-]+/', '-', $d['title']) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (!empty($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . $nice . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=300');
readfile($path);
