# Running BantayKlima with XAMPP (Windows)

You only need **Apache** from XAMPP. BantayKlima keeps its data in one file, so you do not need MySQL.
Total time: about 15 minutes.

## Part A. One-time setup

**1. Install and start XAMPP**
- Install XAMPP from apachefriends.org. Open the **XAMPP Control Panel** and click **Start** next to **Apache**. It should turn green.
- If Apache will not start, see "Troubleshooting 1" at the end.

**2. Put the project in XAMPP's web folder**
- Unzip `bantayklima-v3-fixed.zip`.
- Create the folder `C:\xampp\htdocs\bantayklima` and copy everything from the unzipped folder into it.
- Use the name `bantayklima` (no spaces). Check that this file exists: `C:\xampp\htdocs\bantayklima\index.html`
  (not one folder deeper, like `...\bantayklima\bantayklima-v3\index.html`).

**3. Run guided setup**
Open PowerShell in `C:\xampp\htdocs\bantayklima` and run:
```
powershell -ExecutionPolicy Bypass -File .\setup.ps1
```
Enter your real contact email, `http://localhost/bantayklima` as the site address, and an admin password of at least 8 characters. The password is hidden while typing and stored as a hash. The script checks PHP extensions, creates settings and the database, and runs the self-check. Existing settings and passwords are preserved; edit `config.local.php` or use Admin > Settings to change them.

If an extension is missing, open the PHP configuration file shown by the error, enable `extension=pdo_sqlite` and `extension=mbstring`, then restart Apache and rerun setup. Enable `curl` or `openssl` for live downloads. MySQL is not needed.

**4. Load the demo data**
Open PowerShell (right-click Start, then Windows PowerShell) and run these two lines:
```
cd C:\xampp\htdocs\bantayklima
C:\xampp\php\php.exe cli\refresh.php --demo=normal
```
You should see: `Assessed 30 municipalities (demo: normal).`
Always use `C:\xampp\php\php.exe` (not just `php`): your computer may have a second PHP installed, and XAMPP's is the one the website uses.

**5. Open it**
- Dashboard: http://localhost/bantayklima/
- Admin: http://localhost/bantayklima/admin/ (user name `admin`, and the password you chose)
- Open them in the browser address bar. Do not double-click `index.html` in Explorer: the page needs Apache.

**6. Run the self-check**
In the admin page, click **System check**, then **Also test internet access and private files**.
Fix anything marked FAIL (the Troubleshooting section says how). WARN items are suggestions.

## Part B. Try the demo

1. Open `register.php` and create a resident account with your name, municipality, mobile number or email, and password. Choose SMS alerts if wanted.
2. Accounts with SMS alerts enabled are subscribed immediately. The separate subscription form uses confirmation codes; without delivery configured, confirm those subscriptions in Admin > Subscribers.
3. In Admin > Refresh, press **Demo: drought**, then **Demo: flood**, then **Demo: normal**. Nothing is sent to anyone in demo mode.
4. Look at the dashboard (it shows a DEMO DATA notice), click **History** on a municipality, and look at Admin > Recent messages.

## Part C. Real data

1. Import PAGASA's latest drought table (Admin > "Import the PAGASA drought table"; the README, section 4, explains it).
2. Run the first live refresh **from PowerShell**, because it takes a minute or two (it downloads rainfall history):
```
cd C:\xampp\htdocs\bantayklima
C:\xampp\php\php.exe cli\refresh.php
```
3. The dashboard now shows real conditions and the DEMO DATA notice disappears.

## Part D. Refresh every day automatically

Open **PowerShell** and paste these four lines (change 6am if you like):
```
$a = New-ScheduledTaskAction -Execute "C:\xampp\php\php.exe" -Argument '"C:\xampp\htdocs\bantayklima\cli\refresh.php"'
$t = New-ScheduledTaskTrigger -Daily -At 6am
$s = New-ScheduledTaskSettingsSet -StartWhenAvailable
Register-ScheduledTask -TaskName "BantayKlima daily refresh" -Action $a -Trigger $t -Settings $s
```
This runs the refresh every morning (or as soon as the computer is on, if it was off at 6am). To check it, open **Task Scheduler** and look for "BantayKlima daily refresh".
The computer must be on (and you signed in) for it to run.

## Part E. Open it on your phone

1. Phone and computer on the same Wi-Fi.
2. On the computer, run `ipconfig` and note the **IPv4 Address** (like 192.168.1.23).
3. If Windows asks whether to let Apache use the network, allow **Private networks**.
4. On the phone, open `http://192.168.1.23/bantayklima/` (use your own number).
5. The admin page stays closed to other devices until you set a strong password in `config.local.php`. That is on purpose.

## Troubleshooting

**1. Apache will not start (red, or "port 80 in use")**
Another program uses port 80 (often Skype, or the Windows "World Wide Web Publishing Service"). Either stop that program, or move Apache to another port:
open `C:\xampp\apache\conf\httpd.conf`, change `Listen 80` to `Listen 8080` and `ServerName localhost:80` to `ServerName localhost:8080`, save, and start Apache again.
Your addresses then become `http://localhost:8080/bantayklima/`, and `base_url` in `config.local.php` must say `http://localhost:8080/bantayklima`.

**2. "Not Found" in the browser**
The address must match the folder: folder `C:\xampp\htdocs\bantayklima` means `http://localhost/bantayklima/`. Check that `index.html` is directly inside that folder.

**3. The page loads but says "Could not load data"**
You opened the file directly (the address starts with `file:///`), or Apache is stopped. Use the `http://localhost/...` address with Apache running.

**4. A plain "Something went wrong" message**
Details are saved privately. Open `C:\xampp\htdocs\bantayklima\data\error.log` and `C:\xampp\apache\logs\error.log`, or run the System check. Paste the last lines to me if you are stuck.

**5. "Admin is locked" or the admin will not open from my phone**
The admin password is empty, "change-me", or shorter than 8 characters. Set a longer one in `config.local.php`. Until then the admin only opens on the computer running XAMPP.

**6. "Too many failed logins"**
Ten wrong passwords lock the admin for 15 minutes. Wait, then try again with the right password.

**7. I changed `config.local.php` but nothing changed**
Wait a few seconds (PHP caches files for about 2 seconds), or restart Apache from the Control Panel.

**8. Live data fails ("no connection", or the System check says it cannot download over HTTPS)**
Open `C:\xampp\php\php.ini` in Notepad. Search for `extension=curl` and `extension=openssl`. If a line starts with `;`, delete the `;`. Save, then **Stop and Start Apache**.
Then run `C:\xampp\php\php.exe cli\check.php --network` and read the lines marked FAIL.

**9. The System check says a private file is NOT protected**
Apache is ignoring the `.htaccess` files. Open `C:\xampp\apache\conf\httpd.conf`, find the block that starts `<Directory "C:/xampp/htdocs">`, make sure it says `AllowOverride All`, save, and restart Apache.
Do this before you put anything online: otherwise the database file could be downloaded.

**10. Emails are not sent**
Connect an SMTP account in Admin > Settings > Connect email, then send a test email. Without SMTP or configured PHP mail(), emails are only logged. The dashboard works without an email account.

**11. "Too many sign-ups from this connection"**
Testing many sign-ups from one computer hits the limit of 5 per hour. Add `'signup_per_hour' => 50,` to `config.local.php` while testing, and remove it afterwards.

**12. Something else**
Run `C:\xampp\php\php.exe cli\check.php --network` and paste the whole output here.

## Stopping and backing up

- Stop Apache in the Control Panel when you are done. Your data stays in `data\bantayklima.sqlite`.
- Copy that file (or use Admin > Backups > Download a backup) to keep a safe copy.
