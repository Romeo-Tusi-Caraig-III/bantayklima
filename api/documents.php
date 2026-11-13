<?php
// The PDFs residents can read (newest first). Each opens through file.php.
require __DIR__ . '/../lib/bootstrap.php';
header('Cache-Control: no-store');
$out = [];
foreach (doc_list(12) as $d) {
    $out[] = [
        'id' => (int) $d['id'], 'title' => $d['title'],
        'month' => $d['doc_month'] ? date('F Y', strtotime($d['doc_month'] . '-01')) : null,
        'size' => human_size((int) $d['size']),
        'url' => 'file.php?id=' . (int) $d['id'], 'download' => 'file.php?id=' . (int) $d['id'] . '&dl=1',
    ];
}
json_out(['documents' => $out]);
