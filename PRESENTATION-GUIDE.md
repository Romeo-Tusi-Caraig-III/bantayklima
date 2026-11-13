# BantayKlima study and presentation guide

Updated November 12, 2026. This guide covers the local Docker build, the changes made during development, and the current resident and admin flows.

## 1. Project overview

BantayKlima is a student-built weather and climate information dashboard for Laguna’s 30 cities and municipalities. Residents can find a town’s short-term forecast, see the app’s drought or flood risk assessment, and read official PAGASA advisories and local announcements. Administrators publish information, maintain local contacts, run assessments, and manage resident alert subscriptions.

**Opening script:** “BantayKlima helps Laguna residents answer two questions. What weather might affect my plans in the next few hours, and what longer-term climate conditions should I pay attention to? It brings together forecasts, a rule-based climate assessment, and official advisories, with each source clearly labeled.”

## 2. Run the project

1. Start Docker Desktop and wait for its engine.
2. In PowerShell, run the commands below.
3. Open the [resident dashboard](http://localhost:8080/) and [admin portal](http://localhost:8080/admin/) in two tabs. They use separate sessions, so a resident and the admin can both be signed in.
4. For Admin, use the username `admin` and the password in your private `.env` file. Keep that file off screen while presenting.

```powershell
cd C:\xampp\htdocs\bantayklima
docker compose up --build -d --wait
```

`docker compose ps` shows whether the container is healthy. `docker compose down` stops it while keeping SQLite, backups, and uploads in named volumes. The Docker instance has its own database, separate from the XAMPP copy.

## 3. What was added and changed

| Area | What the project now includes |
| --- | --- |
| Resident interface | A wider dashboard, local Poppins typography, light and dark modes, a remembered theme, English and Filipino dashboard labels, mobile layouts, quick links, and municipality search and risk sorting. |
| Local weather | Current model temperature, feels-like temperature, humidity and wind, a rain-chance chart for the next 24 hours, and a five-day outlook. Santa Cruz is the weather fallback until a town is selected. |
| Today’s action card | The first available forecast hour with at least a 60% rain chance, or the peak rain chance when all available hours are below that threshold. The card separately shows the climate assessment, PAGASA status, local emergency numbers, and source timestamps. It labels unavailable, older, incomplete, and demo data where applicable. |
| Laguna map | Weather and climate-risk layers, a 48-hour forecast timeline, play/pause, an optional smooth forecast overlay and animated wind, radar, satellite, fire hotspots, and full screen. |
| Latest map refinement | A larger map with a default view fitted to Laguna’s municipalities, a town picker, Reset view, keyboard-focusable markers, a highlighted selected municipality, and readable popups that stay open as the forecast plays. The risk legend shows town counts, and hollow markers indicate pending assessments. |
| Local insights | A 30- and 90-day rainfall comparison, risk history, side-by-side forecasts for two towns, an advisory timeline, and a shareable town briefing that loads without the map. |
| Resident accounts | Registration carries over the selected town. Residents can update their profile, town, alert language and consent, change their password, and delete their account. The dashboard reflects the actual subscription status. |
| Admin portal | An overview, sidebar, direct links to setup tasks, subscriber management, emergency contacts, and the same Poppins typography and theme setting as the public dashboard. |
| Statistics and exports | Compact subscriber and messaging metrics, an empty state when there is no chart data, live risk levels and first warning dates, and a dedicated panel for four CSV exports. |
| System check | Counts for failures, items to review, and passes. Failures and warnings appear first, and passed checks can be expanded. Extended checks can test external connectivity and private-file accessibility. |
| Layout and spacing fixes | Admin cards stack independently in Announcements, People, and Settings. The resident dashboard groups rainfall with climate risk, the timeline with advisories, and town comparison beneath the municipality list beside the map. Map layers stay below the sticky header. |
| Admin publishing | Publish a PAGASA PDF, preview extracted Laguna status before saving, or enter it manually. Preview an announcement with its area and estimated recipients, publish it, and review SMS and email results separately. Successful submissions redirect so a page refresh does not repeat the action. |
| Admin accountability | Activity history records the signed-in admin username, time, action and outcome for new portal actions. It includes publications, assessments, and changes to settings. |
| Delivery | A Docker image, Compose setup, persistent volumes, a health check, and a GitHub Actions workflow for testing and publishing images. |

### Problems found and fixed

| Before | Current behavior |
| --- | --- |
| Dashboard copy could say alerts were on after a resident opted out. | The dashboard and account page use the actual subscription status. Removing a subscription also clears the account’s opt-in so saving its profile does not recreate it. |
| Registration could lose the selected municipality. | The selected town is carried into registration and the welcome screen. |
| Reloading after an admin form submission could repeat the action. | Successful admin submissions redirect to the relevant section with a one-time status message. PAGASA preview remains a separate step before saving. |
| Publishing an announcement and sending messages felt like one action. | The dashboard is the default destination. SMS and email are explicit choices, and preview shows the audience and channel settings before confirmation. |
| Hollow or gray map markers gave little context. | The risk legend includes counts and explains pending assessments. A town picker opens its popup, and forecast playback updates the popup in place. |
| Long cards forced empty space below shorter neighbors. | Related panels stack inside independent columns, then become a single column on narrow screens. |
| Statistics showed a long list of empty bars, and failed system checks were buried. | Empty reports use short explanations. System Check puts failures and warnings ahead of passed checks. |
| Docker could be installed while its engine was stopped, or run with an outdated admin configuration. | The local setup was verified with Docker Desktop running and Compose applying the private `.env`. Both portals use separate cookies on the same site. |

## 4. Resident flow

1. **Open the dashboard.** Browsing weather, risk, advisories, and the map works without an account.
2. **Select a municipality.** The weather, action card, assessment, local announcements, timeline and rainfall tracker follow the selection. The province-wide overview can show counts for all municipalities before a specific town is chosen.
3. **Read the action card.** Use it to identify a rain window and check the climate and advisory status. Tap an emergency number to dial it from a supported device.
4. **Explore the map.** Under **Find on map**, choose a town to center the map and open its popup. **For your town** applies that town to the dashboard. **Reset view** returns to the province view. In the forecast layers, scrub or play the timeline and open a marker to see the selected hour’s value.
5. **Compare or share.** Select two municipalities in **Compare municipalities**. Each row shows the same time for both towns. Use **Copy briefing link** to share the current town and language. A link copied from `localhost` works only on the machine serving that site.
6. **Create an account for alerts.** Register with a name, mobile number, password and municipality. Email is optional. In **My account**, change alert consent or language. Delivery depends on whether the corresponding service is configured and whether the resident is eligible for that channel.

**Behind the resident flow:** The browser loads the municipality list and latest assessment from `api/risk.php`. It then requests the selected town’s weather and advisory timeline separately. The action card combines these responses while keeping the sources and timestamps visible. The map and town comparison share a forecast request, while the low-data briefing skips the map. Signing in establishes a resident session, and the dashboard queries `api/me.php` for account and subscription status.

## 5. Admin flow

1. **Overview.** Check the latest assessment and setup tasks. Confirm the settings, messaging connections and contacts before a public rollout.
2. **PAGASA.** Upload the official PDF so residents can read it. Then choose **Read status from it**, paste the table text if needed, or enter the status manually. Review the month, issue date, Laguna status and the province counts before saving. Publishing a PDF and importing its status are separate actions.
3. **Assessment.** Under **Settings → Update risk levels**, run a live assessment after importing a new advisory. The admin can also run normal, drought and flood demos. Demo data is labeled on the public dashboard, and demo runs do not deliver real alerts.
4. **Announcement.** Enter a title and message, choose a town or the whole province, and select the delivery channels. The dashboard is the default. Preview the resident-facing text and recipient estimates before pressing **Publish**. The published entry and Recent messages show delivery outcomes. Acceptance or queueing by a provider does not confirm that a person received or read the message.
5. **People.** Manage residents and subscriptions and add verified local emergency contact numbers. Removing a linked subscription changes the resident’s alert status to off.
6. **Activity and maintenance.** Review the latest admin actions. Check statistics, run the system check, and back up SQLite before major updates. The activity view shows the latest 50 recorded actions and starts collecting from when the feature was installed.

**Behind the admin flow:** Admin login starts a separate session. The server verifies the admin session and a CSRF token before applying a form submission. Publishing saves an announcement for the chosen area, so it appears in the relevant resident timeline. If messaging is selected, the system looks up eligible subscribers and records the results by channel. The activity history records the actor and outcome without storing passwords or messaging credentials in the activity entry.

**What runs automatically:** The browser refreshes its dashboard data periodically, and the weather endpoint caches recent responses. A live climate assessment has to be triggered by an admin or by a scheduled job you configure on the host. PAGASA PDF publication and status import are admin tasks.

## 6. Data sources and what they mean

| Source | Role in BantayKlima |
| --- | --- |
| Open-Meteo forecast and historical archive | Local model conditions, hourly and daily forecasts, the map and comparison data, recent rainfall estimates, and the rainfall baseline. |
| NOAA Climate Prediction Center | Oceanic Niño Index (ONI), an input to BantayKlima’s climate scoring. |
| PAGASA | Official advisories and monthly province-level status, which an admin adds to the application. |
| RainViewer | Recent radar observations where coverage is available. A blank region can also mean missing coverage. |
| NASA GIBS | Dated Himawari-9 imagery and VIIRS satellite thermal anomalies. A hotspot marks detected heat and can have other causes besides fire. |
| OpenStreetMap and Leaflet | The basemap and interactive map controls. |

**Weather forecast** shows what a model estimates at a location and hour. **BantayKlima climate risk** is the project’s longer-term scoring system. **PAGASA advisory** is official guidance, displayed with its own source and date. Always use official PAGASA and local government instructions for emergency decisions.

### Explain the climate assessment

The scoring rules separately add drought and flood points using four inputs: the ONI, the imported PAGASA province status, the last 30 days of rainfall versus typical, and the next 14 days of forecast rainfall versus typical. The higher score sets the risk level and the hazard when the result exceeds Normal. Default levels are **Normal** at 0–1, **Watch** at 2–3, **Warning** at 4–5, and **Severe** at 6 or higher. **Pending** means a municipality has no assessment yet. Admins can change the cutoffs. The thresholds are project rules and have not been scientifically validated.

Typical rainfall uses average daily values from the historical archive. The 90-day tracker adds context alongside the 30-day result. The 90-day value is populated by a live assessment and does not change the scoring rules. For example, 70% of typical rainfall means 30% below typical rainfall. It is a rainfall ratio, distinct from the forecast’s probability of rain.

**Worked example with hypothetical data:** An ONI of +1.2 adds 2 drought points, a PAGASA dry-spell entry adds 2, 40% of typical rainfall over the previous 30 days adds 2, and a forecast of 45% of typical rainfall over the next 14 days adds 1. If the flood score is lower, the total of 7 gives **Severe drought risk** under the default cutoffs.

## 7. How the system is built

```text
Resident or admin browser
    |
    | HTML, CSS, JavaScript and JSON requests
    v
Apache + PHP in Docker
    |-- PHP APIs serve weather, risk, advisories and account state
    |-- SQLite stores accounts, assessments, announcements and admin activity
    |-- File volumes hold SQLite, caches, backups and uploaded PDFs
    |-- Open-Meteo / NOAA requests supply model and climate inputs
    '-- Optional Semaphore and email integrations send selected alerts

Browser map layers also request external basemap and observation tiles.
```

- `index.html` is the resident entry point. `assets/app.js` connects the dashboard, risk view and municipality selection. `assets/weather.js`, `assets/today.js`, `assets/forecast-map.js` and `assets/local-insights.js` handle the weather, action card, map and local insights.
- `admin/index.php` is the admin workspace. `assets/admin-ui.js` handles navigation and announcement preview.
- `api/weather.php` serves one town’s cached forecast. `api/map-forecast.php` serves a shared 48-hour forecast for the town markers and sampled grid. The smooth layer interpolates that grid, so the colors indicate model estimates between sampled points.
- `lib/climate.php`, `lib/risk.php` and `lib/refresh.php` obtain and score inputs and record assessment history. `lib/db.php` creates and updates the SQLite schema.
- Resident and admin sessions use separate cookies. Passwords stored in the database are hashed, and write forms use CSRF tokens. The container excludes `.env`, the existing local database and uploads from the image. Keep backups and configuration private.
- The workflow in `.github/workflows/ci-cd.yml` checks PHP and JavaScript and smoke-tests the container on GitHub pushes and pull requests. A successful default-branch push publishes an image to GitHub Container Registry. Deployment to a host remains a separate step. A local Docker run alone does not activate GitHub Actions.

### What was verified

- Docker built the application and reported it healthy. The local admin sign-in and resident dashboard were checked with separate sessions.
- Browser checks covered registration, login, profile updates, subscriber removal, announcement preview and publication, reloads without duplicate posts, PAGASA text preview and save, and demo assessment history. Messaging tests used disposable data and did not verify real SMS or email delivery.
- The running map returned a live 48-hour forecast for 30 municipalities. Chrome checks covered town selection, timeline playback, wind animation, comparison, map controls, and the mobile briefing. Radar, satellite, and hotspot services returned image tiles during testing. A successful tile response does not establish coverage for every place or time.
- Both live rainfall calculations were checked for Santa Cruz. The displayed tracker depends on whether an assessment has run and whether data was available.
- The last full PHP suite passed 247 assertions. Later presentation-layer changes were checked in Chrome, including Poppins, responsive layouts, Statistics, CSV links, and System Check.
- The GitHub Actions workflow is included in the project. A run on a GitHub repository has not been verified from this local folder.

## 8. Suggested live demo

| Time | Show | Say |
| --- | --- | --- |
| 0:00–0:45 | Public home and town selector | “The dashboard is public. I’ll choose a municipality to show how the information changes.” |
| 0:45–1:45 | Action card, weather chart and risk status | “The forecast, climate assessment and PAGASA advisory have different sources and update times.” |
| 1:45–3:00 | Map town picker, temperature, playback, reset and comparison | “The map fits Laguna, shows the forecast for the selected hour, and lets me open a town’s details.” |
| 3:00–4:30 | Admin in a separate tab, then announce preview | “The admin chooses the area and previews the message. SMS and email are optional.” |
| 4:30–5:30 | Activity history and demo assessment | “The portal records admin actions. This example is labeled demo data.” |
| 5:30–6:00 | Docker and workflow overview | “Docker packages the app. The repository workflow checks changes and can publish the tested build.” |

Before presenting, open both tabs, check the forecast and map tiles, and prepare a sample municipality and announcement. On a demo instance, use **Demo: drought** or **Demo: flood** to show different risk colors, and keep SMS and email off for the practice announcement. On a live instance, avoid switching the public dashboard to demo mode during a presentation.

## 9. Questions you may get

- **Why are some markers hollow?** Those towns are Pending. Run an assessment to assign levels. Switch to a weather layer to see forecasts even while the risk assessment is pending.
- **Does the map predict exact street conditions?** It shows model estimates for town coordinates and interpolated grid cells. Site conditions can vary within a municipality.
- **Is this an official PAGASA warning system?** BantayKlima is a student project. PAGASA guidance is linked and displayed separately from the app’s estimates and scoring.
- **What happens if an external service is down?** A recent cached forecast may appear with a label. If the forecast or tile is unavailable, other dashboard sections and the municipality list can still be used.
- **Why can I use Admin and the resident dashboard at once?** They use separate session cookies and can be open in two tabs.
- **Why do Statistics and the dashboard sometimes differ?** Statistics uses live assessments and excludes simulated messages. The public dashboard can display a clearly labeled demo assessment.
- **Why might a check still need attention on a healthy Docker container?** The container health check confirms the app responds. System Check also reviews configuration, data freshness, backups, and optional services, which can still require setup.
- **What should I do if Admin works on one machine but someone else cannot open my briefing?** A localhost link points to the device opening it. A briefing intended for other people needs the URL of a hosted instance.
- **What would you improve before public use?** Validate risk thresholds against local historical events, verify contact numbers, test real messaging, schedule and monitor refreshes, and deploy with HTTPS.

See [README.md](README.md) for technical setup, configuration, and maintenance commands.
