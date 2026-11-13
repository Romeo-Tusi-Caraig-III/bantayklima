<?php
// BantayKlima settings. Change the admin password before sharing the site.
return [
    'province'         => 'Laguna',
    'db_file'          => __DIR__ . '/data/bantayklima.sqlite',
    // NOAA CPC Oceanic Niño Index (plain text, updated monthly). Check NOAA for the current official index.
    'oni_url'          => 'https://www.cpc.ncep.noaa.gov/data/indices/oni.ascii.txt',
    'normal_years'     => 15,           // years of history used to work out "normal" rainfall
    'advisory_stale_days' => 45,        // warn when the PAGASA province status is older than this
    'open_meteo_forecast' => 'https://api.open-meteo.com/v1/forecast',
    'open_meteo_archive'  => 'https://archive-api.open-meteo.com/v1/archive',
    // Open-Meteo's free tier allows 600 weighted calls a minute, so pause between history downloads.
    'pause_archive'    => 5,            // seconds to wait after each history download
    'pause_forecast'   => 1,            // seconds to wait after each forecast call
    'retry_waits'      => [0, 20, 40],  // seconds to wait before each try when the API says "slow down" (HTTP 429)
    'admin_user'       => 'admin',
    'admin_password'   => getenv('BANTAYKLIMA_ADMIN_PASSWORD') ?: 'change-me',
    'semaphore_key'    => '',           // Semaphore SMS API key (optional)
    'semaphore_sender' => '',           // leave empty to use Semaphore's default sender name
    'semaphore_url'    => 'https://api.semaphore.co/api/v4', // Semaphore's endpoint (rarely needs changing)
    'sms_pause'        => 0.6,          // seconds between Semaphore calls (its limit is 120 calls a minute)
    'base_url'         => getenv('BANTAYKLIMA_BASE_URL') ?: '', // used for unsubscribe links in emails
    'contact_email'    => getenv('BANTAYKLIMA_CONTACT_EMAIL') ?: 'your-team@example.com',
    'require_confirmation' => true,     // new subscribers must confirm with a 6-digit code
    'signup_per_hour'  => 5,            // sign-ups allowed per IP address per hour
    'sms_code_daily_cap' => 300,        // most confirmation texts sent per day (protects your SMS credits)
    'refresh_stale_hours' => 36,        // warn on the dashboard when the daily update is older than this
    'backup_keep'      => 14,           // how many database backups to keep
    'debug'            => false,        // true shows PHP errors on screen (only while developing)
    'message_keep_days' => 90,          // message records are deleted after this many days
    'alert_cooldown_hours' => 48,       // the same Warning/Severe is not texted again within this time
    'sms_daily_cap'    => 1000,         // most real texts per rolling 24 hours (0 = no limit): a guard for your credits
    'email_daily_cap'  => 400,          // most real emails per rolling 24 hours (Gmail allows about 500; 0 = no limit)
    'send_email'       => false,        // true only if PHP mail() is set up on your machine
    'mail_from'        => 'alerts@bantayklima.local',
];
