<?php
require __DIR__ . '/auth.php';
$path = backup_db('download');
if (!$path) { http_response_code(500); exit('The backup could not be created.'); }
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
