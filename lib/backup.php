<?php
function backup_dir(): string {
    $dir = dirname(cfg('db_file')) . '/backups';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    return $dir;
}

/** Copy the database to data/backups and keep only the newest few. Returns the file path, or null on failure. */
function backup_db(string $label): ?string {
    $path = backup_dir() . '/bantayklima-' . date('Ymd-His') . '-' . (preg_replace('/[^a-z0-9]+/i', '', $label) ?: 'manual') . '.sqlite';
    try {
        db()->exec('VACUUM INTO ' . db()->quote($path)); // a consistent copy even while the app is running
    } catch (Throwable $e) {
        if (!@copy(cfg('db_file'), $path)) return null;
    }
    $files = glob(backup_dir() . '/bantayklima-*.sqlite') ?: [];
    rsort($files); // names start with the date, so newest first
    foreach (array_slice($files, (int) cfg('backup_keep')) as $old) @unlink($old);
    return $path;
}

/** One automatic backup per day (called after each live refresh). */
function backup_daily(): void {
    if (!glob(backup_dir() . '/bantayklima-' . date('Ymd') . '-*-daily.sqlite')) backup_db('daily');
}

function backup_list(int $limit = 5): array {
    $files = glob(backup_dir() . '/bantayklima-*.sqlite') ?: [];
    rsort($files);
    return array_map(fn ($f) => ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)], array_slice($files, 0, $limit));
}
