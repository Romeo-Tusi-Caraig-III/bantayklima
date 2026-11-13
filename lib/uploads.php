<?php
// PDFs uploaded by the admin (PAGASA monthly assessments and similar).
// Files live in uploads/pagasa and are handed to visitors only through file.php, so they open the same way on any server.

const BK_PDF_MAX = 20 * 1024 * 1024;

function uploads_dir(string $sub): string {
    $dir = __DIR__ . '/../uploads/' . $sub;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

/** Plain-words reason for a failed upload (PHP's own error codes are not friendly). */
function upload_error_text(int $code): string {
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than this server allows. In php.ini raise upload_max_filesize and post_max_size (for example to 20M), restart Apache, and try again.',
        UPLOAD_ERR_PARTIAL => 'The upload was cut off. Please try again.',
        UPLOAD_ERR_NO_FILE => 'Please choose a PDF file first.',
        default => 'The server could not receive the file (error ' . $code . ').',
    };
}

/**
 * Save an uploaded PDF and list it for residents. $file is an entry of $_FILES.
 * Returns ['ok' => true, 'id' => n] or ['ok' => false, 'error' => text].
 */
function doc_save(?array $file, string $title, string $month): array {
    if (!$file) return ['ok' => false, 'error' => upload_error_text(UPLOAD_ERR_NO_FILE)];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => upload_error_text((int) $file['error'])];
    if ($file['size'] > BK_PDF_MAX) return ['ok' => false, 'error' => 'That PDF is bigger than 20 MB.'];
    $head = (string) @file_get_contents($file['tmp_name'], false, null, 0, 5);
    if ($head !== '%PDF-') return ['ok' => false, 'error' => 'That file is not a PDF. Choose the PDF you downloaded from PAGASA.'];

    $title = trim($title);
    if ($title === '') $title = 'PAGASA ' . date('F Y', strtotime($month . '-01'));
    if (mb_strlen($title) > 120) return ['ok' => false, 'error' => 'The title is too long (120 characters at most).'];
    $month = preg_match('/^\d{4}-\d{2}$/', $month) ? $month : date('Y-m');

    $name = 'pagasa-' . $month . '-' . bin2hex(random_bytes(4)) . '.pdf';
    if (!move_uploaded_file($file['tmp_name'], uploads_dir('pagasa') . "/$name")) {
        // move_uploaded_file refuses files that were not uploaded over HTTP (tests, CLI); copy is the safe fallback there.
        if (PHP_SAPI !== 'cli' || !@copy($file['tmp_name'], uploads_dir('pagasa') . "/$name")) return ['ok' => false, 'error' => 'The PDF could not be saved. Check that the uploads folder is writable.'];
    }
    db()->prepare('INSERT INTO documents (title, doc_month, filename, original_name, size) VALUES (?, ?, ?, ?, ?)')
        ->execute([$title, $month, $name, mb_substr(basename((string) ($file['name'] ?? $name)), 0, 150), (int) filesize(uploads_dir('pagasa') . "/$name")]);
    return ['ok' => true, 'id' => (int) db()->lastInsertId()];
}

/** Documents, newest first. Files that went missing from disk are left out. */
function doc_list(int $limit = 50): array {
    $rows = db()->query('SELECT * FROM documents ORDER BY doc_month DESC, id DESC LIMIT ' . max(1, $limit))->fetchAll();
    return array_values(array_filter($rows, fn ($r) => is_file(uploads_dir('pagasa') . '/' . $r['filename'])));
}

function doc_delete(int $id): void {
    $st = db()->prepare('SELECT filename FROM documents WHERE id = ?');
    $st->execute([$id]);
    $name = $st->fetchColumn();
    if ($name === false) return;
    db()->prepare('DELETE FROM documents WHERE id = ?')->execute([$id]);
    if (preg_match('/^[A-Za-z0-9._-]+\.pdf$/', $name)) @unlink(uploads_dir('pagasa') . "/$name");
}

/** "1.2 MB" style size. */
function human_size(int $bytes): string {
    return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
}
