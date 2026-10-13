# BC Daily Report – self-hosted WhatsApp reporting

Business Correspondents (BCs) report their daily **APY, PMJJBY, PMSBY and PMJDY** numbers inside WhatsApp, using a
pre-filled form message. BCs never get a link or a login. Admins and Managers use a Bootstrap 5 web panel
(English + Hindi labels).

```
 BC's WhatsApp ──► WhatsApp ──► WAHA (Docker, 127.0.0.1:3000)
                                  │  webhook (HMAC-SHA512 signed)
                                  ▼
                 Nginx (HTTPS) ──► PHP 8 /webhook.php ──► parser ──► MySQL
                                  ▲                          │
              Admin panel  ───────┘                          └─► instant reply via WAHA API
 cron (every minute): bin/scheduler.php  → queues form / reminders / summary / escalation
                      bin/worker.php     → sends the queue one by one (8–20 s gaps, typing, limits)
```

## Folder structure

```
docker-compose.yml        WAHA gateway (localhost only)
.env.example              all secrets/config (copy to .env)
database/schema.sql       tables + default settings + message templates
database/sample_data.sql  demo branches, users, BCs, reports, targets
deploy/nginx/             Nginx HTTPS site config
deploy/cron/              /etc/cron.d entry (scheduler + worker)
bin/scheduler.php         cron job – daily form, reminders, summary, escalation, cleanup
bin/worker.php            cron job – queue sender (single instance via lock)
bin/create-admin.php      create an admin / reset a password
public/                   web root: admin pages, webhook.php, assets/
src/                      application code (no framework)
  ReportParser.php        tolerant WhatsApp form parser
  BotHandler.php          incoming messages, commands, confirmations, replies
  QueueWorker.php         anti-ban sending (delays, typing, limits, retries, pause/resume)
  Scheduler.php, Summary.php, Calendar.php, Templates.php, Reports.php, WahaClient.php, ...
storage/logs/             app + cron logs
```

## How BCs use it

At the form time (default 6 PM) every pending BC receives:

```
📋 Daily Report - 11-10-2026
Naam: Ramesh Kumar

APY:
PMJJBY:
PMSBY:
PMJDY:
APY OFFLINE:
JJBY OFFLINE:
SBY OFFLINE:

👆 Is message ko copy karke numbers bharein aur bhej dein. Kuch nahi kiya to 0 likhein.
```

The BC copies it, fills in the numbers and sends it back (or replies to the form message). The bot answers at once:
`Report mil gayi ✅ APY 2, PMJJBY 1, PMSBY 3, PMJDY 5. Galat ho to form dobara bhej dein.`

Accepted input (all equivalent):

| Input | Notes |
|---|---|
| `APY: 2` · `APY 2` · `APY-2` · `apy=2` · `*APY*: २` | separators, case, WhatsApp bold, Hindi/other Indian digits |
| `JJBY` / `PMJJBY`, `SBY` / `PMSBY`, `JDY` / `PMJDY` / `Jan Dhan` | aliases (also `Atal Pension`, `Jeevan Jyoti`, `Suraksha`, `जन धन`) |
| `PMSBY:` (empty) | counted as 0 and mentioned in the confirmation |
| `2 1 3 5` | short form, order APY PMJJBY PMSBY PMJDY |
| `APY 2 PMJJBY 1 PMSBY 3 PMJDY 5` | everything on one line |

Rules:

- Whole numbers only, no negatives, max value (default 500). Values above the confirmation threshold (default 50) need a
  `HAAN` reply before they're saved.
- If something can't be understood, the BC gets the blank form back plus one line saying what went wrong, for example
  `❌ PMSBY ka number samajh nahi aaya.`
- Sending again on the same day updates the report, and every version is kept in `report_versions`. After the cutoff
  (default 23:59) only the Admin can edit.
- Commands: `FORM` (resend form), `STATUS` (today's report or pending), `HELP`, `STOP` (no more reminders, flagged in
  the panel), `START`.
- Unknown numbers and inactive BCs get no reply. Messages like "ok" / "thanks" / 🙏 aren't answered either.

## Roles

| Role | Sees | Can do |
|---|---|---|
| Admin | everything | all settings, BC/user management, report edits |
| BC Supervisor | all BCs, CBC-wise table on the dashboard | reports, exports, reminders, targets, leave |
| Manager | BCs of assigned branches | same as Supervisor, for those branches |
| CBC | only BCs assigned to them (`bcs.cbc_id`) | today's pending list with **Call / WhatsApp** buttons, "Copy pending list", reminders, reports/exports of own BCs, leave |

Assign BCs to a CBC on **BCs** (bulk "Assign CBC"), in **Edit BC**, or with a `CBC Name` column in the Excel import
(missing CBC logins are created automatically and their passwords are shown once). CBCs and Supervisors with a phone
and "WhatsApp summary" ticked get the 9 PM summary – a CBC gets only their own BCs, with the mobile numbers of pending BCs.

## Offline fields

Besides APY, PMJJBY, PMSBY and PMJDY the form has **APY OFFLINE, JJBY OFFLINE, SBY OFFLINE**
(`APY OFF LINE`, `jjby offline`, `SBY ऑफलाइन` … are accepted). Short form: 4 numbers (offline = 0) or 7 numbers
`APY PMJJBY PMSBY PMJDY APY-OFF JJBY-OFF SBY-OFF`, e.g. `2 1 3 5 0 1 0`. Empty offline lines count as 0 without a warning.

## Deployment on Ubuntu 24.04 (AWS EC2)

**Instance:** t3.small (2 GB RAM) or larger. The default WEBJS engine runs Chromium. With 1 GB, set `WAHA_ENGINE=NOWEB`
or `GOWS`. **Security group:** allow only 22 (your IP), 80 and 443. Never open 3000.
**DNS:** point an A record (e.g. `reports.example.com`) to the instance's Elastic IP.

### 1. Packages

```bash
sudo apt update && sudo apt -y upgrade
sudo apt -y install nginx mysql-server git unzip certbot python3-certbot-nginx \
     php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-curl php8.3-zip php8.3-intl php8.3-xml \
     docker.io docker-compose-v2
sudo systemctl enable --now docker php8.3-fpm nginx mysql
sudo timedatectl set-timezone Asia/Kolkata      # optional, app uses APP_TIMEZONE anyway
```

### 2. Database

```bash
sudo mysql <<'SQL'
CREATE DATABASE bc_report CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bc_report'@'localhost' IDENTIFIED BY 'STRONG-DB-PASSWORD';
GRANT ALL PRIVILEGES ON bc_report.* TO 'bc_report'@'localhost';
SQL
```

Set `DB_HOST=localhost` in `.env` if you created the user `@'localhost'`.

### 3. Code and configuration

```bash
sudo git clone https://github.com/dhdhdh51/WhatsApp-api.git /var/www/bc-report
cd /var/www/bc-report
sudo cp .env.example .env
sudo nano .env        # APP_URL, DB_*, and new secrets for WAHA_API_KEY, WAHA_DASHBOARD_PASSWORD, WEBHOOK_SECRET
                      # generate each secret with: openssl rand -hex 32
sudo chown -R root:www-data /var/www/bc-report
sudo chmod 640 .env
sudo chown -R www-data:www-data storage
```

`.env` sits outside the web root (`public/`), and `docker compose` reads the same file.

### 4. Schema, demo data and admin user

```bash
mysql -u bc_report -p bc_report < database/schema.sql
mysql -u bc_report -p bc_report < database/sample_data.sql   # optional demo data (fake numbers)
sudo -u www-data php bin/create-admin.php admin "Your Name"    # set your own admin password
```

Demo logins from `sample_data.sql`: `admin / Admin@12345`, `manager1 / Manager@12345`. Change or deactivate them.

### 5. Nginx and HTTPS (Let's Encrypt)

```bash
sudo mkdir -p /var/www/letsencrypt
# first get the certificate with a temporary HTTP site:
sudo rm -f /etc/nginx/sites-enabled/default
echo 'server { listen 80; server_name reports.example.com; root /var/www/letsencrypt; }' | sudo tee /etc/nginx/sites-enabled/tmp.conf
sudo nginx -t && sudo systemctl reload nginx
sudo certbot certonly --webroot -w /var/www/letsencrypt -d reports.example.com -m you@example.com --agree-tos -n \
     --deploy-hook "systemctl reload nginx"
# then install the real site:
sudo rm /etc/nginx/sites-enabled/tmp.conf
sudo sed 's/reports.example.com/YOUR.DOMAIN/g' deploy/nginx/bc-report.conf | sudo tee /etc/nginx/sites-available/bc-report
sudo ln -s /etc/nginx/sites-available/bc-report /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

Certbot installs a renewal timer automatically (`systemctl list-timers | grep certbot`).

### 6. WAHA (WhatsApp gateway)

```bash
cd /var/www/bc-report
sudo docker compose up -d
sudo docker compose logs -f waha      # wait for "WAHA started"
curl -s -H "X-Api-Key: $(grep ^WAHA_API_KEY .env | cut -d= -f2)" http://127.0.0.1:3000/api/sessions
```

WAHA listens on `127.0.0.1:3000` only. To open its own dashboard, use a tunnel:
`ssh -L 3000:127.0.0.1:3000 ubuntu@server`, then go to `http://localhost:3000/dashboard`.

### 7. Cron (scheduler and queue worker)

```bash
sudo cp deploy/cron/bc-report /etc/cron.d/bc-report
sudo chmod 644 /etc/cron.d/bc-report
tail -f storage/logs/app-$(date +%F).log
```

### 8. Link WhatsApp (QR)

1. Use a **dedicated** WhatsApp number (a WhatsApp Business app on a spare phone works well). Ask every BC to save
   that number as a contact. Saved contacts make a ban much less likely.
2. In the panel open **WhatsApp**, then click **Create / start session**. The status changes to `SCAN_QR_CODE` and a QR
   code appears. It refreshes every few seconds.
3. On the phone go to WhatsApp → ⋮ / Settings → **Linked devices** → **Link a device**, and scan the QR code.
4. The status changes to **WORKING**. The warm-up start date is set automatically. For the first 7 days, at most 40
   scheduled messages go out per day (you can change this in Settings).
5. Keep the phone charged and online now and then. If the session drops, the queue pauses by itself and resumes once
   the status is WORKING again. Use **Restart session** if it gets stuck. **Logout / unlink** means you have to scan
   the QR code again.

### 9. First-day checklist

- Settings: form time, reminder times, cutoff, summary time, **admin WhatsApp number**, daily limit.
- Add BCs (or use **BCs → CSV import**, template on that page) and set holidays/weekly offs.
- Send `HELP` from a BC's phone. You should get an instant reply, and it shows up under **Messages**.

## WAHA webhook – payload and handling

WAHA posts every event to `APP_URL/webhook.php`. This is set through `WHATSAPP_HOOK_URL` and
`WHATSAPP_HOOK_EVENTS=message,session.status` in `docker-compose.yml`. The body is signed with HMAC-SHA512 using
`WEBHOOK_SECRET` (`WHATSAPP_HOOK_HMAC_KEY`) and sent in the `X-Webhook-Hmac` header.

Incoming message (WEBJS engine, shortened):

```json
{
  "id": "evt_01JXXXXXXXXXXXX",
  "event": "message",
  "session": "default",
  "engine": "WEBJS",
  "payload": {
    "id": "false_919876543210@c.us_3EB0C431C26A1916E07E",
    "timestamp": 1760187600,
    "from": "919876543210@c.us",
    "fromMe": false,
    "body": "📋 Daily Report - 11-10-2026\nNaam: Ramesh Kumar\n\nAPY: 2\nPMJJBY: 1\nPMSBY: 3\nPMJDY: 5",
    "hasMedia": false,
    "replyTo": { "id": "true_919876543210@c.us_3EB0AAAA", "body": "📋 Daily Report - 11-10-2026 ..." }
  }
}
```

What `public/webhook.php` and `src/BotHandler.php` do with it:

1. Reject anything that isn't a POST, has a bad `X-Webhook-Hmac` (or `X-Webhook-Secret`), or is for a different session.
2. Answer `200 {"ok":true}` right away (`fastcgi_finish_request`), then keep processing.
3. Ignore `fromMe`, groups (`@g.us`), status, broadcasts and channels.
4. Find the BC. `91XXXXXXXXXX@c.us` gives the 10-digit phone number. A hidden `xxx@lid` id is resolved with
   `GET /api/{session}/lids/{lid}`, and the result is remembered in `bcs.wa_lid`. Unknown or inactive senders are
   logged as `ignored` and get no reply.
5. Log the message. The unique `(direction, wa_message_id)` key turns WAHA's retries into no-ops.
6. Parse it as a command, `HAAN`/`NAHI`, a report, the short form, or unknown. If `replyTo.id` matches a form we sent
   for an older date, the reply says the report was recorded for today.
7. Save the report (new version row each time), then reply immediately: `sendSeen` → `startTyping` → `sendText`.

Session status events keep the panel status up to date and pause or resume the queue:

```json
{ "event": "session.status", "session": "default", "payload": { "status": "WORKING" } }
```

Statuses: `STARTING`, `SCAN_QR_CODE`, `WORKING`, `FAILED`, `STOPPED`.

Test the endpoint by hand:

```bash
SECRET=$(grep ^WEBHOOK_SECRET .env | cut -d= -f2)
BODY='{"event":"message","session":"default","payload":{"id":"test1","from":"919876500001@c.us","fromMe":false,"body":"STATUS"}}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha512 -hmac "$SECRET" | sed 's/^.*= //')
curl -s https://reports.example.com/webhook.php -H 'Content-Type: application/json' -H "X-Webhook-Hmac: $SIG" -d "$BODY"
```

## Scheduling, reminders and anti-ban

- **Form** at `form_time`. **Reminders** at each `reminder_times` entry, and the last one uses the *final reminder*
  templates. These only go to BCs who are active, haven't submitted, aren't on leave, don't have a holiday or weekly
  off, and haven't sent STOP. Each job runs once per day. If the server was down, only the latest due slot runs.
- **Templates** (Templates page) have 3–4 wording variants per type, rotated by day and BC. `{form}` is required.
  Scheme names outside `{form}` are rejected, because the BC copies the whole message back.
- **Daily summary** at `summary_time` goes to the admin numbers: submitted vs pending (with names) and totals per
  scheme. Managers with a phone and "receive summary" get a summary of their own branches.
- **Escalation** at `escalation_time`: a list of BCs who missed N (default 2) working days in a row. Holidays, leave and
  weekly offs don't break or extend the streak. The same list is on the dashboard.
- **Send reminder now** (Today's Status page) queues a reminder for the selected or all pending BCs.
- **Queue safety:** one message at a time, a random 8–20 s gap, a 1–3 s typing indicator, a daily limit, and a
  warm-up limit for the first 7 days. Each message is checked again right before sending, so a BC who submitted in the
  meantime gets nothing. There are at most 2 retries. A disconnect pauses the queue without using up retries.
  Messages for past dates are cancelled. Admin summaries and escalations are sent first and don't count toward the
  limit. Direct replies to a BC's own message are sent immediately and aren't queued.
- At about 16 s per message, 500 forms take around 2 hours. Pick `form_time` and reminder times with that in mind.

## Security

- Secrets live only in `.env` (outside the web root, mode 640). Every SQL query uses PDO prepared statements, with
  native prepares and no emulation.
- Webhooks need a valid HMAC-SHA512 signature. If `WEBHOOK_SECRET` is missing or short, the endpoint refuses to run.
- Passwords are hashed with `password_hash` and rehashed automatically. Every POST checks a CSRF token. Logins are
  rate-limited per IP and username (5 attempts / 15 min, plus an Nginx `limit_req`). Sessions use secure, HttpOnly,
  SameSite cookies with a 30-minute inactivity timeout, and the session id is regenerated on login.
- Managers only see BCs in their assigned branches (enforced on every page and export). Settings, templates, users,
  WhatsApp, holidays and report edits are admin-only.
- Audit trail: `report_versions` keeps every submission and edit, including the raw WhatsApp text. Admin edits need a
  reason. `audit_log` records logins, BC, settings, template, holiday and leave changes, STOP/START, and queue actions.

## Employer-managed phones (MDM / app uninstall block)

For a consented, organization-owned phone, BC Report supports **Android Device Owner** mode. In that mode the app is
blocked from normal uninstall through Android's device-policy system. This is not available for ordinary personal phones
or a normal APK install: Android requires Device Owner enrollment during the phone's initial setup / after a factory reset.

1. Open **Managed phones / मैनेज्ड फ़ोन** in the admin panel.
2. Back up permitted data and factory-reset the company phone.
3. During the first Android setup screen, start the OEM's Android Enterprise QR provisioning flow (on many phones, tap the
   Welcome screen six times) and scan the QR shown in the panel.
4. Android downloads the signed APK, enrolls it as Device Owner, and prevents normal removal. The panel then shows
   **Device Owner – blocked** after the BC logs in.

For an IT test device without QR provisioning, only on a reset device with no personal accounts:

```bash
adb install -r bc-report.apk
adb shell dpm set-device-owner site.bharatseo.bcreport/.MdmAdminReceiver
```

Device Owner removal must be performed through the authorized organization process (normally a factory reset). Keep the
written consent and make sure the BC knows the device is managed.

## Operations

- **Logs:** `storage/logs/app-YYYY-MM-DD.log`, `storage/logs/cron.log`, and `docker compose logs waha`.
- **Backups:** add a nightly dump to root's crontab, e.g.
  `mysqldump --single-transaction -u bc_report -p'...' bc_report | gzip > /var/backups/bc_report_$(date +\%F).sql.gz`.
  Also back up `waha/sessions` so you don't have to scan the QR code again after a rebuild.
- **Update WAHA:** `sudo docker compose pull && sudo docker compose up -d`. The session survives through the volume.
- **Troubleshooting**
  - Panel shows `UNREACHABLE`: WAHA isn't running, or `WAHA_URL` / `WAHA_API_KEY` is wrong.
  - No replies to BCs: look for `Webhook rejected` in the app log (secret mismatch). Make sure `APP_URL` is reachable
    from the server itself, and that the BC's number in the panel is correct.
  - Nothing is being sent: check **WhatsApp → Send queue** for "paused" or "daily_limit", and `cron.log`.
  - Events arrive twice: you configured the webhook both in `docker-compose.yml` and in the session
    (`WAHA_SESSION_WEBHOOK=1`). Use only one.

## Upgrading an existing install

```bash
git pull
for f in database/migrations/*.sql; do mysql -u bc_report -p bc_report < "$f"; done   # safe to re-run
```
