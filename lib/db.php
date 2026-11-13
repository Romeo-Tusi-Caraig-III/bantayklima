<?php
function db(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    $file = cfg('db_file');
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0775, true);
    $pdo = new PDO('sqlite:' . $file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    init_schema($pdo);
    return $pdo;
}

function add_column(PDO $pdo, string $table, string $column, string $definition): void {
    $cols = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    if (!in_array($column, $cols, true)) $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
}

/** Small key/value store for app settings (JSON values). */
function setting_get(string $name, $default = null) {
    $st = db()->prepare('SELECT value FROM settings WHERE name = ?');
    $st->execute([$name]);
    $v = $st->fetchColumn();
    return $v === false ? $default : json_decode($v, true);
}
function setting_set(string $name, $value): void {
    db()->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value')
        ->execute([$name, json_encode($value, JSON_UNESCAPED_UNICODE)]);
}

function init_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS municipalities (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, province TEXT NOT NULL DEFAULT 'Laguna',
        type TEXT NOT NULL, lat REAL NOT NULL, lng REAL NOT NULL)");
    // Province-level status copied from PAGASA's monthly drought table.
    $pdo->exec('CREATE TABLE IF NOT EXISTS advisories (
        id INTEGER PRIMARY KEY AUTOINCREMENT, province TEXT NOT NULL, alert_status TEXT NOT NULL,
        rainfall_status TEXT NOT NULL, month TEXT NOT NULL, issued TEXT, notes TEXT, source_url TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    // source is "live" for real data and "demo" for demo runs, so demo data never pollutes history or statistics.
    $pdo->exec("CREATE TABLE IF NOT EXISTS risk_assessments (
        id INTEGER PRIMARY KEY AUTOINCREMENT, municipality_id INTEGER NOT NULL REFERENCES municipalities(id),
        hazard TEXT NOT NULL, level TEXT NOT NULL, score INTEGER NOT NULL, reasons TEXT NOT NULL,
        oni REAL, recent_ratio REAL, forecast_ratio REAL, data_ok INTEGER NOT NULL DEFAULT 1,
        source TEXT NOT NULL DEFAULT 'live', created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    // status is "pending" until the person confirms with a code, then "active". Only active subscribers get alerts.
    $pdo->exec("CREATE TABLE IF NOT EXISTS subscribers (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, phone TEXT, email TEXT,
        language TEXT NOT NULL DEFAULT 'en', status TEXT NOT NULL DEFAULT 'active', consented_at TEXT, unsub_token TEXT,
        municipality_id INTEGER NOT NULL REFERENCES municipalities(id), created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec('CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT, channel TEXT NOT NULL, recipient TEXT NOT NULL, body TEXT NOT NULL,
        status TEXT NOT NULL, detail TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    // municipality_id is NULL for province-wide announcements.
    $pdo->exec('CREATE TABLE IF NOT EXISTS announcements (
        id INTEGER PRIMARY KEY AUTOINCREMENT, municipality_id INTEGER REFERENCES municipalities(id),
        title TEXT NOT NULL, body TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    // One-time codes for confirming a sign-up or stopping alerts. Only a hash of the code is stored.
    $pdo->exec('CREATE TABLE IF NOT EXISTS codes (
        id INTEGER PRIMARY KEY AUTOINCREMENT, purpose TEXT NOT NULL, target TEXT NOT NULL, code_hash TEXT NOT NULL,
        attempts INTEGER NOT NULL DEFAULT 0, expires_at TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_activity (
        id INTEGER PRIMARY KEY, actor TEXT NOT NULL, action TEXT NOT NULL, outcome TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    // municipality_id is NULL for province-wide contacts.
    $pdo->exec('CREATE TABLE IF NOT EXISTS contacts (
        id INTEGER PRIMARY KEY AUTOINCREMENT, municipality_id INTEGER REFERENCES municipalities(id), label TEXT NOT NULL, phone TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS signup_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT, bucket TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');

    // Website accounts (plain login, no one-time codes). A user who ticks SMS alerts also gets a row in subscribers.
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, phone TEXT UNIQUE, email TEXT, password_hash TEXT NOT NULL,
        municipality_id INTEGER NOT NULL REFERENCES municipalities(id), language TEXT NOT NULL DEFAULT 'en',
        sms_optin INTEGER NOT NULL DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email ON users(lower(email)) WHERE email IS NOT NULL AND email <> ''");

    // PAGASA (and other) PDFs the admin uploads. Residents read them on the dashboard through file.php.
    $pdo->exec("CREATE TABLE IF NOT EXISTS documents (
        id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, doc_month TEXT, filename TEXT NOT NULL UNIQUE,
        original_name TEXT, size INTEGER NOT NULL DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");

    // Older installs: add columns that did not exist yet.
    add_column($pdo, 'announcements', 'sms_people', 'INTEGER NOT NULL DEFAULT 0');
    add_column($pdo, 'announcements', 'sms_sent', 'INTEGER NOT NULL DEFAULT 0');
    add_column($pdo, 'announcements', 'sms_failed', 'INTEGER NOT NULL DEFAULT 0');
    add_column($pdo, 'announcements', 'sms_mode', "TEXT NOT NULL DEFAULT ''");
    add_column($pdo, 'announcements', 'email_people', 'INTEGER NOT NULL DEFAULT 0');
    add_column($pdo, 'announcements', 'email_sent', 'INTEGER NOT NULL DEFAULT 0');
    add_column($pdo, 'announcements', 'email_failed', 'INTEGER NOT NULL DEFAULT 0');
    add_column($pdo, 'announcements', 'email_mode', "TEXT NOT NULL DEFAULT ''");
    add_column($pdo, 'announcements', 'sms_requested', 'INTEGER');
    add_column($pdo, 'announcements', 'email_requested', 'INTEGER');
    add_column($pdo, 'advisories', 'issued', 'TEXT');
    add_column($pdo, 'advisories', 'pdf_file', 'TEXT');
    add_column($pdo, 'risk_assessments', 'recent_90_ratio', 'REAL');
    add_column($pdo, 'subscribers', 'user_id', 'INTEGER');
    add_column($pdo, 'risk_assessments', 'data_ok', 'INTEGER NOT NULL DEFAULT 1');
    add_column($pdo, 'risk_assessments', 'source', "TEXT NOT NULL DEFAULT 'live'");
    add_column($pdo, 'subscribers', 'language', "TEXT NOT NULL DEFAULT 'en'");
    add_column($pdo, 'subscribers', 'status', "TEXT NOT NULL DEFAULT 'active'");
    add_column($pdo, 'subscribers', 'consented_at', 'TEXT');
    add_column($pdo, 'subscribers', 'unsub_token', 'TEXT');
    $pdo->exec("UPDATE subscribers SET unsub_token = lower(hex(randomblob(16))) WHERE unsub_token IS NULL OR unsub_token = ''");
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_subscribers_token ON subscribers(unsub_token)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_assess_town ON risk_assessments(municipality_id, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_signup_bucket ON signup_log(bucket, created_at)');

    if ((int) $pdo->query('SELECT COUNT(*) FROM municipalities')->fetchColumn() === 0) seed_municipalities($pdo);

    // PDFs saved by version 2 lived only in advisories.pdf_file: list them as documents too, so residents still see them.
    foreach ($pdo->query("SELECT month, pdf_file FROM advisories WHERE pdf_file IS NOT NULL AND pdf_file <> ''")->fetchAll() as $a) {
        $name = basename((string) $a['pdf_file']);
        if (!preg_match('/^[A-Za-z0-9._-]+\.pdf$/', $name) || !is_file(__DIR__ . '/../uploads/pagasa/' . $name)) continue;
        $pdo->prepare('INSERT OR IGNORE INTO documents (title, doc_month, filename, original_name, size) VALUES (?, ?, ?, ?, ?)')
            ->execute(['PAGASA ' . date('F Y', strtotime($a['month'])), substr((string) $a['month'], 0, 7), $name, $name, (int) filesize(__DIR__ . '/../uploads/pagasa/' . $name)]);
    }
}

// Laguna: 6 cities + 24 municipalities. Coordinates are approximate town centers.
function seed_municipalities(PDO $pdo): void {
    $rows = [
        ['San Pablo','city',14.0683,121.3255], ['Calamba','city',14.2117,121.1653], ['Santa Rosa','city',14.3122,121.1114],
        ['Biñan','city',14.3333,121.0833], ['San Pedro','city',14.3583,121.0583], ['Cabuyao','city',14.2724,121.1251],
        ['Los Baños','municipality',14.1699,121.2430], ['Bay','municipality',14.1833,121.2833], ['Calauan','municipality',14.1500,121.3167],
        ['Alaminos','municipality',14.0639,121.2458], ['Victoria','municipality',14.2250,121.3283], ['Pila','municipality',14.2333,121.3667],
        ['Santa Cruz','municipality',14.2789,121.4161], ['Pagsanjan','municipality',14.2736,121.4569], ['Lumban','municipality',14.2989,121.4617],
        ['Cavinti','municipality',14.2433,121.5117], ['Kalayaan','municipality',14.3250,121.4750], ['Paete','municipality',14.3647,121.4836],
        ['Pakil','municipality',14.3786,121.4783], ['Pangil','municipality',14.4033,121.4686], ['Siniloan','municipality',14.4247,121.4486],
        ['Famy','municipality',14.4389,121.4528], ['Mabitac','municipality',14.4311,121.4286], ['Santa Maria','municipality',14.4667,121.4256],
        ['Luisiana','municipality',14.1833,121.5100], ['Majayjay','municipality',14.1469,121.4750], ['Magdalena','municipality',14.2000,121.4333],
        ['Liliw','municipality',14.1269,121.4372], ['Nagcarlan','municipality',14.1364,121.4153], ['Rizal','municipality',14.1100,121.3933],
    ];
    $st = $pdo->prepare('INSERT INTO municipalities (name, type, lat, lng) VALUES (?, ?, ?, ?)');
    foreach ($rows as $r) $st->execute($r);
}
