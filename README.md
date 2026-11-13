# BantayKlima (Laguna pilot)

El Niño and La Niña early-warning web app for Laguna's 30 cities and municipalities.
Plain PHP, HTML, CSS and JavaScript. No framework, no Composer. Data is stored in one SQLite file (`data/bantayklima.sqlite`).

## 1. Run it

**With Docker Desktop or Docker Engine and Compose v2:**

```sh
docker compose up --build -d --wait
```

Open http://localhost:8080/ and http://localhost:8080/admin/. Docker starts a separate, empty SQLite database with all 30 municipalities. The two named volumes retain SQLite, its backups, and uploaded PAGASA documents between container replacements. `docker compose down` stops the stack and keeps the volumes. `docker compose down --volumes` deletes its stored data.

The dashboard loads the Open-Meteo model forecast for Santa Cruz until a municipality is selected. It checks for updates every five minutes and caches each location on the server for ten minutes. The Refresh button checks again using that cache. If the upstream request fails, an earlier forecast may be shown with its model time and a notice for up to three hours after it was fetched. The weather panel works before the first climate assessment.

The Laguna weather map and town comparison share a cached 48-hour forecast for the 30 municipalities. The optional smooth overlay and wind particles use a sampled grid of model forecasts. RainViewer supplies radar where available, and NASA GIBS supplies dated Himawari-9 imagery and VIIRS thermal anomalies. Use the source links beneath the map to check coverage and newer frames. The rainfall tracker uses the latest climate assessment. Run a live assessment to populate its 90-day value on an existing database. The town briefing link opens a compact, map-free view in the selected language.

Use the existing CLI to generate an isolated demo or refresh the climate assessments with live data:

```sh
docker compose exec -T --user www-data web php cli/refresh.php --demo=normal
docker compose exec -T --user www-data web php cli/refresh.php
```

Run one command for the mode you want. For regular live climate assessments, schedule the second command daily from the project directory on the Docker host.

For a shared instance, set these values in a private project-root `.env` file before starting Compose:

```dotenv
BANTAYKLIMA_ADMIN_PASSWORD=replace-with-a-strong-password
BANTAYKLIMA_BASE_URL=https://your-site.example
BANTAYKLIMA_CONTACT_EMAIL=your-team@example.com
```

The Compose port is bound to localhost. For public hosting, put an HTTPS reverse proxy in front of it and set the base URL to the public address. Set `BANTAYKLIMA_PORT` and adjust the base URL if port 8080 is already in use. If you have an existing XAMPP installation, stop writes and back up its SQLite database and `uploads/pagasa` before planning a migration into the volumes.

### GitHub Actions and container delivery

For the classroom activity, follow [the CI/CD lab walkthrough](CICD-LAB.md) and configure the `DEMO_API_KEY` repository secret before your first push. A successful push of a `v*` tag publishes a matching version tag in GHCR.

Add this folder to a GitHub repository with Actions enabled. The workflow at `.github/workflows/ci-cd.yml` runs PHP linting and tests, JavaScript syntax checks, and Docker smoke tests on pushes and pull requests. Successful pushes to the repository's default branch publish `latest` and `sha-<commit>` tags to `ghcr.io/<owner>/<repository>` using GitHub's workflow token. The repository should contain source files only. The included ignore rules exclude the local configuration, live database, logs, backups, uploads and loose PDFs.

To deploy a published build on a Docker host, copy `compose.yaml` there, set `BANTAYKLIMA_IMAGE=ghcr.io/<owner>/<repository>:sha-<commit>` in its `.env`, and run:

```sh
docker compose pull web
docker compose up -d --no-build --wait
```

For a private package, authenticate Docker to GHCR with a credential that has package read access. The workflow automates continuous delivery. A server rollout remains an explicit step, and a previous commit tag can be used for a rollback. Back up the database before updating a live deployment.

**On Windows with XAMPP, follow `XAMPP-GUIDE.md`** (step by step, with troubleshooting). The summary below is for everyone else.

You need PHP 8.0 or newer with `mbstring` and `pdo_sqlite` (check with `php -m`). For live HTTPS downloads, enable `curl` or `openssl`. The `sqlite3` extension is optional.

**Guided Windows setup:** from the extracted project folder, run `powershell -ExecutionPolicy Bypass -File .\setup.ps1`. It finds PHP, asks for your contact email, site address and admin password, and runs the system check. It preserves an existing `config.local.php`. No SMS or email account is required for local testing.

**With XAMPP:** copy this folder to `C:\xampp\htdocs\bantayklima`, start Apache, then in Command Prompt:
```
cd C:\xampp\htdocs\bantayklima
C:\xampp\php\php.exe cli\refresh.php --demo=normal
```
Open http://localhost/bantayklima/ (dashboard) and http://localhost/bantayklima/admin/ (admin).

**Without XAMPP:** from this folder run
```
php cli/refresh.php --demo=normal
php -S localhost:8000 router.php
```
Open http://localhost:8000/ and http://localhost:8000/admin/. Always start the server with `router.php`: the built-in server ignores
`.htaccess`, and the router is what keeps `data/`, `lib/`, `cli/`, `tests/` and the config files private.
Works on PHP 8.0 through 8.5 (tested on 8.3 and 8.5.3), with or without the curl extension; without curl it needs `extension=openssl` for HTTPS downloads (the self-check tells you). Windows install tips: download PHP (Non-Thread Safe, x64) from windows.php.net, install the Visual C++ Redistributable it asks for, unzip to `C:\php`,
add it to PATH, copy `php.ini-development` to `php.ini`, and remove the `;` before `extension_dir = "ext"` and before
`extension=curl`, `mbstring`, `openssl`, `pdo_sqlite`, `sqlite3`. Mac: `brew install php`. Ubuntu: `sudo apt install php-cli php-sqlite3 php-curl php-mbstring`.

## 2. Set up before you share it (about 10 minutes)

Create `config.local.php` next to `config.php`. Anything in it overrides `config.php` (changes can take about 2 seconds to apply on some setups):
```php
<?php
return [
    'admin_password' => 'a-long-password-you-chose',   // at least 8 characters
    'contact_email'  => 'your-team@school.edu.ph',     // shown on the privacy notice
    'base_url'       => 'http://localhost:8000',       // used for the stop link in emails
    'semaphore_key'  => 'YOUR_KEY', 'semaphore_sender' => 'BantayKlima',   // when you have them
];
```
- **Admin password:** while it is still `change-me` (or under 8 characters), the admin page opens only on the computer running the server and shows a red warning.
- **Contact email:** the admin page warns until you set a real one.
- Admin uses a login form with a session cookie, so use it over HTTPS if it is ever online.

## 3. Demo flow

1. Dashboard: subscribe a test person. Because new sign-ups confirm with a code, press "Confirm" next to them in Admin > Subscribers (or set up SMS, section 5).
2. Admin > Refresh: "Demo: normal", then "Demo: drought", then "Demo: flood". Alerts are only simulated (see Admin > Recent messages), and the dashboard shows a "DEMO DATA" notice.
3. Click "History" on a municipality to show the chart. Press "Live data" to go back to real conditions.

## 4. Every month: the PAGASA status

PAGASA has no open API, but it publishes a "Drought and Dry Spell Assessment" PDF each month (usually in the first week).
1. Copy the text of the "As of <month> (ACTUAL)" page.
2. Admin > "Import the PAGASA drought table": paste it, enter the alert status (for example El Niño Advisory), click "Read table".
3. Check the preview (month, issue date, Laguna's status, province counts), click "Looks right: save it", then run "Live data".

The importer finds Laguna and checks the provinces it read against PAGASA's own counts, so a cut-off paste is refused instead of giving a wrong answer.
The admin page shows a reminder banner when a new table should be out, and the dashboard warns if the status is older than 45 days.
For an official automatic feed, ask PAGASA's Climatology and Agrometeorology Division (number printed on each advisory) about data access.

## 5. What each part does

**Sign-up, consent and spam protection.** The form needs a ticked consent box and links to the privacy notice (`privacy.php`). A hidden spam-trap field,
a limit of 5 sign-ups per connection per hour, and a one-per-contact rule stop abuse. New sign-ups stay "pending" until the person enters a 6-digit code sent
by SMS (or email if they gave no phone); only confirmed people get alerts. Codes last 30 minutes, lock after 5 wrong tries, are stored only as a hash, and
are capped at 300 texts a day. If codes cannot be delivered yet (no SMS key), press "Confirm" in Admin. Set `require_confirmation` to `false` to skip codes.
Unconfirmed sign-ups are deleted after a day.

**Stop alerts.** Every email ends with a stop link. `unsubscribe.php` also lets a person remove their number by confirming a code sent to it.
Admin > Subscribers can remove anyone. The privacy notice is a plain-language template: have your school or LGU review it before real use.

**SMS (Semaphore).** Create an account, copy the API key, and register a sender name today: Semaphore's docs say messages without one fail, approval takes up to
5 business days, and it may reject names that are not for a business. One credit is one 160-character text. Admin > "SMS status" shows credits and sender names and has a test button.
One call per language per municipality (up to 1,000 numbers), paced under Semaphore's 120 calls a minute. Failures are recorded with Semaphore's reason.
Demo buttons and `--demo` runs never send anything real. With no key, messages show as "logged only".

**Emergency contacts.** Admin > "Emergency contacts": add your MDRRMO or PDRRMO numbers. The dashboard always shows the national hotline (911) plus your numbers,
and Warning/Severe emails include them. No local numbers are built in: add the ones you have verified.

**Risk thresholds.** Admin > "Risk thresholds" changes the numbers behind Watch, Warning and Severe (validated, with a reset button). They are a reasonable starting
point, not scientifically validated values. Run "Live data" afterwards to apply them.

**Risk history.** "History" on each municipality shows a chart of its score with the three level lines (live: one point per day for 60 days; demo: one point per run).
Demo and live data are kept apart everywhere.

**Statistics and CSV exports.** Admin > "Statistics and exports": reach (subscribers, municipalities covered, SMS-reachable, Filipino, confirmation rate),
alerts sent, SMS credits used, risk levels now, level changes, and when each place first reached Warning. Downloads: subscribers, messages, risk history, level changes
(personal details masked; formulas neutralised for Excel). Demo data is excluded. Lead time needs a comparison with other sources, so the page shows first-Warning dates next to PAGASA's release date.

**Backups.** One automatic backup after each daily live refresh and one before each PAGASA update (newest 14 kept in `data/backups`). Admin has "Back up now" and "Download a backup".
To restore, stop the server and replace `data/bantayklima.sqlite` with a backup. Backups contain personal details, so store downloads safely.

**Heartbeat warnings.** The dashboard says "DEMO DATA" while demo data is showing, and warns if the daily refresh is more than 36 hours old or live data was incomplete.
Schedule the daily refresh: Windows Task Scheduler running `C:\xampp\php\php.exe C:\xampp\htdocs\bantayklima\cli\refresh.php` (or `php cli/refresh.php` from cron).
The first live run takes a minute or two (rainfall history download, paced for Open-Meteo's limits); later runs are fast and cached.

**Back-test.** `php cli/backtest.php --from=2023-01` replays past months through the rules (ONI and 30-day rain only) and compares them with PAGASA's tables in
`tests/pagasa_laguna_known.csv` (4 months so far: add more rows from PAGASA's monthly tables). It prints a table, a summary, and saves `data/backtest.csv`.
Change the thresholds and run it again to see the effect. Needs internet.

## 6. Phones and slow connections

The page is built for small screens: 16px inputs (no iPhone zoom), 44px tap targets, tables that scroll inside their box, and a map that is never required.
The map library loads on demand. On 2G or data-saver connections it asks first ("Show map"); if it cannot load, the full list of municipalities still works.
The page itself is about 35 KB of text. I could only test this in a simulated browser, so please also try it on a real phone:
run `php -S 0.0.0.0:8000 router.php`, find your computer's IP address, and open `http://<that-ip>:8000/` from a phone on the same Wi-Fi (allow PHP through the firewall).
In Chrome on a computer, open DevTools > Network and choose "Slow 4G" or "Slow 3G" to feel the slow-connection experience.
(With a weak admin password, the admin page stays closed to other devices.)

## 7. Settings (`config.php`)

`province`, `normal_years`, `admin_user`, `admin_password`, `contact_email`, `base_url`, `semaphore_key`, `semaphore_sender`, `send_email`, `mail_from`,
`require_confirmation`, `signup_per_hour`, `sms_code_daily_cap`, `sms_daily_cap` (most real texts per 24 hours, a guard for your credits; 0 = no limit),
`alert_cooldown_hours` (the same Warning is not texted again within this time), `message_keep_days` (message records are deleted after this many days),
`refresh_stale_hours`, `advisory_stale_days`, `backup_keep`, `debug` (show PHP errors on screen: only while developing),
plus URLs and pacing for NOAA, Open-Meteo and Semaphore.

## 8. Self-check and tests

**Self-check (run this before you submit, and again after you move the site):** Admin > "System check", or `php cli/check.php`
(add `--network` to also test internet access and, with a `base_url`, that private files cannot be downloaded). It lists setup, security, SMS and data-health checks as OK, WARN or FAIL.

```
php tests/risk_test.php   php tests/pagasa_test.php   php tests/app_test.php   php tests/contrast_test.php
```
`app_test.php` uses a throwaway database. `contrast_test.php` checks that every text colour meets WCAG AA contrast (4.5:1).

## 9. Security and privacy summary

- **Login:** a form with a PHP session cookie. Ten wrong guesses from one connection lock the admin for 15 minutes (even for the right password). A weak or default password keeps the admin closed to other computers.
  Admin pages send no-store, frame-deny and a strict content policy headers; the session cookie is HttpOnly and SameSite=Strict. Every admin form carries a CSRF token.
- **Input:** all database queries are prepared statements; all page output is escaped; the front end inserts text with `textContent` only. Exports neutralise spreadsheet formulas.
- **Private files:** `.htaccess` (Apache, including XAMPP) and `router.php` (built-in server) block `data/`, `lib/`, `cli/`, `tests/`, settings, database, log and backup files, dotfiles, and directory listings.
- **Errors:** visitors only ever see a plain message. Details go to `data/error.log`, which is not downloadable.
- **People's data:** consent time recorded; codes stored only as hashes; stopping alerts erases the person's details and message records; unconfirmed sign-ups are deleted after a day; message records after `message_keep_days`;
  exports mask personal details; demo runs send nothing and are kept out of history and statistics.
- **Money guards:** daily caps on real texts and on code texts, a limit of 3 codes per contact per hour, and no repeat alerts for the same level inside the cooldown.
- **No third-party code on the page:** Leaflet 1.9.4 is served from `assets/vendor/leaflet`. Map tiles come from OpenStreetMap (their usage policy applies).
- **Admin sessions:** PHP sessions must be enabled and their save directory writable.

## 10. Files

`index.html`, `assets/` (dashboard; `assets/vendor/leaflet` is the map library) &middot; `api/` (JSON endpoints: risk, history, announcements, subscribe, confirm, resend) &middot; `unsubscribe.php`, `privacy.php` &middot;
`admin/` (login guard, admin page, system check, statistics, exports, backup) &middot; `lib/` (database, risk rules, PAGASA importer, climate data, alerts, sign-up codes, backups, health, self-check, back-test) &middot;
`cli/` (daily refresh, self-check, back-test) &middot; `router.php` and `.htaccess` (private files).

## 11. Known limits

- Town coordinates are approximate, and weather data comes from a 0.25 degree grid (about 25 km), so nearby towns can share values.
- The sign-up limit uses the visitor's IP address as the server sees it; behind some proxies every visitor can look the same.
- Free web hosts often lack cron, outgoing connections or email; run "System check" with the internet tests on the real host before relying on it.
- A person who tries to sign up again with a number that is already subscribed is told so, which reveals that the number is on the list for that municipality (a small privacy trade-off for a clearer message).
- Texts are sent as plain SMS with no stop link (160-character limit). People stop SMS alerts on the Stop alerts page, which the privacy notice explains.
- Backups are not encrypted, and the sign-up limit uses the visitor's IP address (see above).

## What changed in version 3

- **Admin login:** `admin/` now has a normal login form (username `admin`, default password `change-me`) instead of the browser pop-up. While the password is still the default, the admin only opens on the computer running the server; log in there and choose a new password under Settings > Admin password, and it opens from anywhere. The new password is saved (hashed) in the database, so no file needs editing.
- **Admin layout:** five tabs: Home (a "what needs doing" checklist), Announcements, PAGASA, People, Settings.
- **PAGASA PDFs:** PAGASA tab > "Upload and publish". The PDF is saved in `uploads/pagasa`, listed in the `documents` table and shown on the dashboard immediately, whether or not the table inside can be parsed. Residents read it in the page (drawn with the bundled PDF.js, so it works on phones that cannot show PDFs inline) or download it. Files are handed out by `file.php`, never directly. To update the status from a PDF, press "Read status from it"; you check the result before it is saved. Raise `upload_max_filesize` and `post_max_size` in `php.ini` (XAMPP: 40M by default) if uploads are refused.
- **SMS:** Settings > "Connect SMS (Semaphore)": paste the API key and optional sender name; saved in the database (config.local.php still works as a fallback). Then "Send test text". Announcements show who will be texted and block texts over 306 characters instead of silently cutting them; each announcement remembers its result ("texted 12 of 14"). Without a key texts are only logged and the admin says so.
- **Residents:** register and log in with a mobile number or email and a password (no codes). Registering sends them to the dashboard with their town selected; announcements are filtered to their town plus province-wide notices.
- **Dashboard:** risk gauge for the chosen town, searchable and filterable municipality list, PAGASA documents, announcements. Fonts are bundled in `assets/fonts` (no third-party requests).
- **Tests:** `php tests/auth_test.php` now also covers PDFs, announcements, the saved SMS key and the admin password.

- **Email:** Settings > "Connect email" sends over SMTP (no Composer needed). Easiest free route: a Gmail account with 2-Step Verification and an App Password (smtp.gmail.com, port 465, SSL); the "Fill in for Gmail" button sets the server for you. Send a test email, then tick "Also send by email" on an announcement. Emails reach active subscribers who have an email address, include a stop link, and are capped by `email_daily_cap` (400/day). The password is stored in the database, so keep `data/` private and back-ups safe. Without an account, emails are only logged.

Older notes in this file about the admin browser pop-up login still describe the default password rule; the Settings page is now the easier way to change it.

## Accounts (version 2, still true)

- Residents register at `register.php` and log in at `login.php`; an account with "SMS alerts" ticked is automatically a subscriber, so risk alerts and announcements reach it. Residents manage this at `account.php`.
