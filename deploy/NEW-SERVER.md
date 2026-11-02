# Naya production server: live karne ka poora process

Ye guide **BC Monitoring App** (`bc.bharatseo.site`) aur **noidarankers.in** ko ek naye Ubuntu server par chalane ka poora tareeka hai. Purana server AWS par tha (aaPanel, IP `34.231.49.225`). AWS account suspend hone se wo band ho gaya.

- Saare commands server par **root** user se chalayein: `ssh root@NAYA_IP` (Windows PowerShell mein bhi chalta hai), ya Hostinger ka browser terminal.
- Code blocks jaise hain waise copy-paste karein. Sirf wahi badlein jahan likha ho.
- Is guide ki nginx config, backup script aur restore ke steps local par nginx, PHP 8.3, MariaDB 10.11 aur LiveKit 1.13.11 ke saath test kiye gaye hain.

## Ek nazar mein

| Step | Kaam | Time |
|---|---|---|
| 1 | Naya VPS lena | 15 min |
| 2 | DNS naye server par (sabse pehle karein) | 15 min (DNS update hone mein 1–24 ghante lag sakte hain) |
| 3 | Server ka basic setup | 30 min |
| 4 | Video meeting (LiveKit) aur 1:1 call (TURN) | 20 min |
| 5 | Purane server se backup (sirf jab AWS account chalu ho) | 30 min |
| 6 | BC Monitoring App aur uska data | 30 min |
| 7 | noidarankers.in | 20 min |
| 8 | SSL certificate aur dono site chalu | 15 min |
| 9 | WhatsApp (WAHA) aur cron | 15 min |
| 10 | Go-live checks | 30 min |
| 11 | Roz ka automatic backup | 15 min |
| 12 | Purana AWS band karna | 1–2 hafte baad |

Data ke do raaste hain:

- **Raasta A (backup hai):** AWS account dobara chalu ho gaya. Step 5 mein poora data nikalta hai: BC, reports, logins, settings, WhatsApp login, uploads. BCs ko dobara login nahi karna padta.
- **Raasta B (backup nahi):** Code GitHub se aata hai, data naye sire se bharna padta hai. BC list Excel se import hoti hai, branch aur users haath se bante hain, sab BCs dobara login karte hain, aur WhatsApp ka QR dobara scan hota hai.

## 1. Naya VPS lena

1. Hostinger → VPS → **KVM 4** (4 vCPU, 16 GB RAM). Kam se kam **KVM 2** lein. 100 BC camera ke saath broadcast ke liye KVM 4 behtar hai.
2. Location: **India**.
3. Operating system: **Ubuntu 24.04**, plain OS (bina control panel).
4. Root password strong rakhein aur safe jagah likh lein.
5. hPanel mein server ka **IPv4 address** note karein. Is guide mein use `NAYA_IP` likha hai.
6. hPanel mein weekly **backups** on rakhein.
7. hPanel ka firewall khaali rehne dein. Server ka apna firewall (ufw) step 3 mein lagta hai. hPanel firewall on karna ho to usme bhi step 3.4 wale ports kholein.

## 2. DNS naye server par

Dono domain ka DNS purane server (`ns1.bharatseo.site`, `ns2.bharatseo.site`) se chalta tha. Wo server band hai, isliye nameserver badalne honge. Ye kaam sabse pehle karein, kyunki naya DNS har jagah pahunchne mein 1–24 ghante lag sakte hain. SSL certificate (step 8) tabhi banega jab DNS naye IP par aa jaayega.

### bharatseo.site (Hostinger par registered)

1. hPanel → **Domains** → `bharatseo.site` → **DNS / Nameservers**.
2. **Change nameservers** → **Use Hostinger nameservers** → Save.
3. **DNS records** mein ye record daalein. `bc` ka purana record ho to use badlein.

| Type | Name | Value | TTL |
|---|---|---|---|
| A | `bc` | `NAYA_IP` | 300 |

4. `bharatseo.site` par khud koi website ya email chalta tha, to uske records (A `@`, `www`, MX) bhi daalein.

### noidarankers.in (GoDaddy par registered)

1. GoDaddy → **My Products** → `noidarankers.in` → **DNS** → **Nameservers** → **Change Nameservers** → **GoDaddy Nameservers** → Save. Domain par lock (Domain Protection) hai, isliye GoDaddy OTP ya verification maang sakta hai.
2. **DNS Records** mein:

| Type | Name | Value | TTL |
|---|---|---|---|
| A | `@` | `NAYA_IP` (GoDaddy ka "Parked" wala A record hata dein) | sabse kam (600 sec) |
| CNAME | `www` | `@` | 1 hour |
| TXT | `@` | `google-site-verification=Lzz6O_UUbD_02gOk2HUyCN321-eEPHdIEfyWLqYHKZc` | 1 hour |

TXT record Google Search Console ke liye hai. Is domain par email (jaise `info@noidarankers.in`) chalta tha, to uske MX records bhi daalein.

### Check

Browser mein kholein: `https://dns.google/resolve?name=bc.bharatseo.site&type=A` aur `https://dns.google/resolve?name=noidarankers.in&type=A`. `"data"` mein `NAYA_IP` dikhna chahiye. Step 3 ke baad server par bhi dekh sakte hain:

```bash
dig +short bc.bharatseo.site @8.8.8.8
dig +short noidarankers.in @8.8.8.8
```

## 3. Server ka basic setup

### 3.1 Settings file (sirf ek baar)

Neeche `NEW_IP=1.2.3.4` mein apna NAYA_IP aur `LE_EMAIL=` mein apna email likhein, phir poora block chalayein. Ye `/root/golive.env` banata hai, jisme naye random passwords bhi hain. Ye file kisi ko na dein.

```bash
if [ ! -f /root/golive.env ]; then
  cat > /root/golive.env <<EOF
NEW_IP=1.2.3.4
LE_EMAIL=aapka-email@example.com
LK_KEY=API$(openssl rand -hex 6)
LK_SECRET=$(openssl rand -hex 32)
TURN_PASS=$(openssl rand -hex 24)
EOF
  chmod 600 /root/golive.env
fi
set -a; . /root/golive.env; set +a
echo "IP=$NEW_IP  email=$LE_EMAIL"
```

Galat likha gaya ho to `nano /root/golive.env` se theek karein. **Har nayi SSH login ke baad** pehle ye line chalayein:

```bash
set -a; . /root/golive.env; set +a
```

### 3.2 Packages

```bash
export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a
apt update && apt -y upgrade
apt -y install nginx mariadb-server git unzip curl rsync ufw fail2ban dnsutils certbot \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-curl php8.3-zip php8.3-intl php8.3-xml php8.3-gd php8.3-opcache \
  docker.io docker-compose-v2 coturn rclone
systemctl enable --now nginx php8.3-fpm mariadb docker fail2ban
```

Server ka timezone **UTC** hi rehne dein, jaise purane server par tha. App khud IST mein kaam karta hai (`.env` mein `APP_TIMEZONE`).

### 3.3 PHP aur MariaDB settings

```bash
cat > /etc/php/8.3/fpm/conf.d/99-bc.ini <<'EOF'
upload_max_filesize = 10M
post_max_size = 12M
memory_limit = 256M
max_execution_time = 300
expose_php = Off
EOF
sed -i -e 's/^pm.max_children = .*/pm.max_children = 20/' -e 's/^pm.start_servers = .*/pm.start_servers = 4/' \
       -e 's/^pm.min_spare_servers = .*/pm.min_spare_servers = 2/' -e 's/^pm.max_spare_servers = .*/pm.max_spare_servers = 6/' \
       /etc/php/8.3/fpm/pool.d/www.conf
systemctl restart php8.3-fpm

cat > /etc/mysql/mariadb.conf.d/99-bc.cnf <<'EOF'
[mysqld]
# like the old server: the database clock is UTC (the app itself works in IST)
default-time-zone = '+00:00'
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
innodb_buffer_pool_size = 512M
EOF
systemctl restart mariadb
mariadb -e "SELECT @@global.time_zone, NOW(), UTC_TIMESTAMP();"
```

Aakhri command mein `+00:00` aana chahiye, aur dono time same hone chahiye.

### 3.4 Firewall

```bash
ufw allow OpenSSH
ufw allow 80,443/tcp
ufw allow 7881/tcp
ufw allow 7882/udp
ufw allow 3478
ufw allow 49160:49200/udp
ufw --force enable
ufw status
mkdir -p /var/www/letsencrypt
```

| Port | Kis liye |
|---|---|
| 22 (OpenSSH) | server login |
| 80, 443 | website, app, SSL |
| 7881 TCP, 7882 UDP | video meeting ki awaaz aur video (LiveKit) |
| 3478, 49160–49200 UDP | 1:1 call (coturn) |

## 4. Video meeting (LiveKit) aur 1:1 call (coturn)

### 4.1 LiveKit

```bash
set -a; . /root/golive.env; set +a
LKV=1.13.11
cd /tmp
curl -fsSLO https://github.com/livekit/livekit/releases/download/v$LKV/livekit_${LKV}_linux_amd64.tar.gz
curl -fsSLO https://github.com/livekit/livekit/releases/download/v$LKV/checksums.txt
grep " livekit_${LKV}_linux_amd64.tar.gz\$" checksums.txt | sha256sum -c -
tar -xzf livekit_${LKV}_linux_amd64.tar.gz livekit-server
install -m 755 livekit-server /usr/local/bin/livekit-server
livekit-server --version

id livekit >/dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin livekit
mkdir -p /etc/livekit
cat > /etc/livekit/livekit.yaml <<EOF
port: 7880
bind_addresses:
  - 127.0.0.1
rtc:
  tcp_port: 7881
  udp_port: 7882
  use_external_ip: false
  node_ip: $NEW_IP
keys:
  $LK_KEY: $LK_SECRET
room:
  empty_timeout: 300
logging:
  level: info
EOF
chown root:livekit /etc/livekit/livekit.yaml && chmod 640 /etc/livekit/livekit.yaml

cat > /etc/systemd/system/livekit.service <<'EOF'
[Unit]
Description=LiveKit SFU (video meetings)
After=network-online.target
Wants=network-online.target

[Service]
User=livekit
Group=livekit
ExecStart=/usr/local/bin/livekit-server --config /etc/livekit/livekit.yaml
Restart=always
RestartSec=3
LimitNOFILE=500000

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload && systemctl enable --now livekit
sleep 3
curl -s -o /dev/null -w 'LiveKit %{http_code}\n' http://127.0.0.1:7880/
```

`sha256sum` ki line mein `OK` aur aakhir mein `LiveKit 200` aana chahiye. LiveKit sirf `127.0.0.1` par sunta hai. Phone aur browser us se `wss://bc.bharatseo.site/rtc` par nginx ke through judte hain (step 8). Video ka data seedha port 7882/7881 se aata-jaata hai.

### 4.2 coturn (1:1 call ke liye TURN)

```bash
set -a; . /root/golive.env; set +a
cat > /etc/turnserver.conf <<EOF
# STUN/TURN for the 1:1 app calls (setting webrtc_ice)
listening-port=3478
min-port=49160
max-port=49200
fingerprint
lt-cred-mech
realm=bc.bharatseo.site
user=bcturn:$TURN_PASS
no-tls
no-dtls
no-cli
no-multicast-peers
denied-peer-ip=0.0.0.0-0.255.255.255
denied-peer-ip=10.0.0.0-10.255.255.255
denied-peer-ip=100.64.0.0-100.127.255.255
denied-peer-ip=127.0.0.0-127.255.255.255
denied-peer-ip=169.254.0.0-169.254.255.255
denied-peer-ip=172.16.0.0-172.31.255.255
denied-peer-ip=192.168.0.0-192.168.255.255
syslog
EOF
chown root:turnserver /etc/turnserver.conf && chmod 640 /etc/turnserver.conf
systemctl enable coturn && systemctl restart coturn
systemctl is-active coturn livekit
```

Dono ke liye `active` aana chahiye. `denied-peer-ip` wali lines kisi ko TURN ke zariye server ke andar ke network tak pahunchne nahi deti hain.

## 5. Purane server se backup (Raasta A)

Ye step sirf tab hai jab AWS account dobara chalu ho jaaye. Raasta B ho to seedha step 6 par jaayein.

1. AWS Console → EC2 → region **N. Virginia (us-east-1)** → Instances → purana server **Start** karein. Uska Public IPv4 dekhein, kyunki ye badal sakta hai. Yahan use `PURANA_IP` likha hai. Server list mein na dikhe ya `terminated` likha ho, to AWS ne use mita diya hai: Raasta B lein.
2. Browser mein aaPanel kholein: `https://PURANA_IP:8888/<security entrance>` (aaPanel ka purana login link, bas IP badlega). Browser "not secure" warning de to Advanced → Proceed. Login karke **Terminal** kholein.
3. Helper file banayein. Ye app ki apni config se database login padhti hai, isliye koi password haath se copy nahi karna padta. Poora block ek saath paste karein:

```bash
cat > /root/dbinfo.php <<'PHP'
<?php
// php /root/dbinfo.php bc|nr <site folder> name|cnf|sql
// Reads the database login from the site's own config (.env of the BC app, config.php of noidarankers).
//   name = database name   cnf = login file for mysqldump   sql = the same database + user on a new server
[, $kind, $dir, $what] = $argv + ['', '', '', ''];
if ($kind === 'bc') {
    require $dir . '/src/bootstrap.php';
    $db = ['host' => (string) App\Env::get('DB_HOST', '127.0.0.1'), 'port' => (string) App\Env::get('DB_PORT', '3306'),
           'name' => (string) App\Env::get('DB_NAME', ''), 'user' => (string) App\Env::get('DB_USER', ''),
           'pass' => (string) App\Env::get('DB_PASS', '')];
} elseif ($kind === 'nr') {
    $x = (require $dir . '/config.php')['db'] ?? [];
    $db = ['host' => (string) ($x['host'] ?? 'localhost'), 'port' => (string) ($x['port'] ?? 3306),
           'name' => (string) ($x['name'] ?? ''), 'user' => (string) ($x['user'] ?? ''), 'pass' => (string) ($x['pass'] ?? '')];
} else {
    fwrite(STDERR, "usage: php dbinfo.php bc|nr <site folder> name|cnf|sql\n");
    exit(1);
}
if ($db['name'] === '' || $db['user'] === '') {
    fwrite(STDERR, "no database settings found in $dir\n");
    exit(1);
}
$opt = fn (string $v): string => '"' . addcslashes($v, '\\"') . '"';                     // option-file value
$sql = fn (string $v): string => "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $v) . "'"; // SQL string
$name = str_replace('`', '', $db['name']);
switch ($what) {
    case 'name':
        echo $name, "\n";
        break;
    case 'cnf':
        echo "[client]\nhost=", $opt($db['host']), "\nport=", (int) $db['port'], "\nuser=", $opt($db['user']),
            "\npassword=", $opt($db['pass']), "\n";
        break;
    case 'sql':
        echo "CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
        foreach (['localhost', '127.0.0.1'] as $h) {
            $u = $sql($db['user']) . "@'$h'";
            echo "CREATE USER IF NOT EXISTS $u IDENTIFIED BY ", $sql($db['pass']), ";\n";
            echo "ALTER USER $u IDENTIFIED BY ", $sql($db['pass']), ";\n";
            echo "GRANT ALL PRIVILEGES ON `$name`.* TO $u;\n";
        }
        break;
    default:
        fwrite(STDERR, "last argument: name, cnf or sql\n");
        exit(1);
}
PHP
```

4. Backup script banakar chalayein:

```bash
cat > /root/backup-old-server.sh <<'SCRIPT'
#!/bin/bash
# Run on the OLD server as root (aaPanel -> Terminal). Puts everything that is not in GitHub into one file:
#   /root/migration-YYYYmmdd-HHMM.tar   (contains passwords: keep it private)
# Needs /root/dbinfo.php (written in the step before this one).
set -u
WWW=${WWW:-/www/wwwroot}
OUTDIR=${OUTDIR:-/root}
DBINFO=${DBINFO:-/root/dbinfo.php}
BC=$WWW/bc.bharatseo.site
NR=$WWW/noidarankers.in
TS=$(date +%Y%m%d-%H%M)
B=$OUTDIR/migration-$TS
umask 077
mkdir -p "$B"
DUMP=$(command -v mysqldump || command -v mariadb-dump || echo /www/server/mysql/bin/mysqldump)
SQLC=$(command -v mysql || command -v mariadb || echo /www/server/mysql/bin/mysql)

echo "[1] stopping the web server, cron and WhatsApp (WAHA), so nothing changes after the backup"
/usr/local/lsws/bin/lswsctrl stop >/dev/null 2>&1
systemctl stop nginx httpd cron crond >/dev/null 2>&1
docker info >/dev/null 2>&1 && docker ps -q | xargs -r docker stop >/dev/null

echo "[2] databases"
for site in "bc:$BC" "nr:$NR"; do
  kind=${site%%:*}; dir=${site#*:}
  if [ ! -d "$dir" ]; then echo "  skip $kind: $dir not found"; continue; fi
  if ! php "$DBINFO" "$kind" "$dir" cnf > "$B/.my-$kind.cnf"; then echo "  FAIL $kind: could not read its config"; continue; fi
  name=$(php "$DBINFO" "$kind" "$dir" name)
  if "$DUMP" --defaults-extra-file="$B/.my-$kind.cnf" --single-transaction --quick --hex-blob --no-tablespaces \
       --default-character-set=utf8mb4 --triggers "$name" > "$B/db-$kind.sql"; then
    gzip "$B/db-$kind.sql"
    for t in $("$SQLC" --defaults-extra-file="$B/.my-$kind.cnf" -N -e 'SHOW TABLES' "$name"); do
      echo "$t $("$SQLC" --defaults-extra-file="$B/.my-$kind.cnf" -N -e "SELECT COUNT(*) FROM \`$t\`" "$name")"
    done > "$B/counts-$kind.txt"
    "$SQLC" --defaults-extra-file="$B/.my-$kind.cnf" -e 'SELECT VERSION(), @@global.time_zone, @@system_time_zone, NOW(), UTC_TIMESTAMP()' > "$B/dbinfo-$kind.txt"
    echo "  ok $kind: database $name, $(wc -l < "$B/counts-$kind.txt") tables, $(du -h "$B/db-$kind.sql.gz" | cut -f1)"
  else
    echo "  FAIL $kind: mysqldump of $name"
  fi
done
rm -f "$B"/.my-*.cnf

echo "[3] website files (code, .env, uploads, storage, Firebase key)"
tar -czf "$B/wwwroot.tar.gz" --exclude='.git' -C "$(dirname "$WWW")" "$(basename "$WWW")" && echo "  ok $(du -h "$B/wwwroot.tar.gz" | cut -f1)"

echo "[4] WhatsApp login (WAHA) and other Docker data"
if docker info >/dev/null 2>&1; then
  docker ps -a --format '{{.Names}}  {{.Image}}  {{.Status}}' > "$B/docker-ps.txt"
  for c in $(docker ps -aq); do docker inspect -f '{{range .Mounts}}{{println .Source}}{{end}}' "$c"; done | grep -v '^$' | sort -u > "$B/docker-mounts.txt"
  [ -s "$B/docker-mounts.txt" ] && tar -czf "$B/docker-volumes.tar.gz" --ignore-failed-read -T "$B/docker-mounts.txt" 2>/dev/null
  echo "  $(wc -l < "$B/docker-ps.txt") containers, $(wc -l < "$B/docker-mounts.txt") folders"
else
  echo "  no Docker on this server"
fi

echo "[5] server settings (LiveKit, TURN, cron, web server, DNS zones) - for reference"
crontab -l > "$B/crontab-root.txt" 2>/dev/null
tar -czf "$B/etc.tar.gz" --ignore-failed-read /etc/livekit /etc/systemd/system/livekit.service /etc/turnserver.conf \
  /etc/default/coturn /etc/cron.d /var/spool/cron /usr/local/lsws/conf/vhosts /www/server/panel/vhost /var/named 2>/dev/null
{
  echo "created: $(date -u '+%F %T UTC') on $(cat /etc/hostname 2>/dev/null)"
  head -2 /etc/os-release 2>/dev/null
  php -v 2>/dev/null | head -1
  livekit-server --version 2>/dev/null
  du -sh "$WWW"/* 2>/dev/null
} > "$B/manifest.txt"

(cd "$B" && sha256sum -- * > SHA256SUMS)
tar -cf "$B.tar" -C "$OUTDIR" "migration-$TS" && rm -rf "$B"
echo
ls -lh "$B.tar"
echo "DONE. Next: copy it to the new server:  scp $B.tar root@NEW_IP:/root/"
SCRIPT
bash /root/backup-old-server.sh
```

Script pehle purane server par web server, cron aur WhatsApp (Docker) band karti hai. Isse backup ke baad wahan koi naya data nahi aata, aur do server ek saath WhatsApp message ya alarm nahi bhejte. Har database ke liye `ok bc` aur `ok nr` dikhna chahiye, aur aakhir mein `DONE`. Koi `FAIL` aaye to aage na badhein; wo line apne technical admin ko bhejein.

5. File naye server par bhejein (ye command purane server ke terminal mein chalegi; naye server ka root password maangegi):

```bash
scp /root/migration-*.tar /root/dbinfo.php root@NAYA_IP:/root/
```

6. Ek copy apne computer par bhi rakhein: aaPanel → **Files** → `/root` → `migration-….tar` → Download. Isme passwords hain, ise kisi ko na bhejein.
7. AWS Console mein purana server **Stop** karein. **Terminate abhi na karein** (step 12 dekhein).

## 6. BC Monitoring App

### 6.1 Code

```bash
set -a; . /root/golive.env; set +a
git clone --branch feature/live-broadcast https://github.com/dhdhdh51/WhatsApp-api.git /var/www/bc-report
```

Abhi production branch `feature/live-broadcast` hai. PR #1 merge hone ke baad `feature/bc-daily-reporting` lein.

### 6.2 Raasta A: purana data wapas lana

```bash
ls -lh /root/migration-*.tar        # sirf ek file honi chahiye; do hon to purani hata dein
mkdir -p /root/migration /root/old
tar -xf /root/migration-*.tar -C /root/migration --strip-components=1
cd /root/migration && sha256sum -c SHA256SUMS
tar -xzf /root/migration/wwwroot.tar.gz -C /root/old
OLD=/root/old/wwwroot/bc.bharatseo.site

cp -a $OLD/.env /var/www/bc-report/.env
sed -i 's|/www/wwwroot/bc.bharatseo.site|/var/www/bc-report|g' /var/www/bc-report/.env
[ -f $OLD/storage/firebase-sa.json ] && cp -a $OLD/storage/firebase-sa.json /var/www/bc-report/storage/
[ -d $OLD/public/uploads ] && rsync -a $OLD/public/uploads/ /var/www/bc-report/public/uploads/
[ -d $OLD/waha ] && rsync -a $OLD/waha/ /var/www/bc-report/waha/

php /root/dbinfo.php bc /var/www/bc-report sql | mariadb
BC_DB=$(php /root/dbinfo.php bc /var/www/bc-report name)
zcat /root/migration/db-bc.sql.gz \
  | sed -e 's/utf8mb4_uca1400_ai_ci/utf8mb4_unicode_ci/g' -e 's/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g' \
  | mariadb "$BC_DB"
for t in $(mariadb -N "$BC_DB" -e 'SHOW TABLES'); do echo "$t $(mariadb -N "$BC_DB" -e "SELECT COUNT(*) FROM \`$t\`")"; done > /root/counts-bc-new.txt
diff /root/migration/counts-bc.txt /root/counts-bc-new.txt && echo "OK: BC ka saara data aa gaya"
cat /root/migration/dbinfo-bc.txt
```

- `sha256sum` mein har file ke aage `OK` aur aakhir mein `OK: BC ka saara data aa gaya` aana chahiye. `diff` koi line dikhaye to us table ka data poora nahi aaya.
- Naye server par database user wahi naam aur password se banta hai jo purani `.env` mein hai, isliye `.env` mein database badalne ki zarurat nahi.
- `dbinfo-bc.txt` mein purane database ka time zone hai. Wahan `SYSTEM` aur `UTC` dikhe to step 3.3 theek hai. IST dikhe to `99-bc.cnf` mein `default-time-zone = '+05:30'` karke `systemctl restart mariadb` chalayein.
- WhatsApp login: purane server par WAHA kisi aur folder se chalta tha (`/root/migration/docker-mounts.txt` dekhein), to `docker-volumes.tar.gz` se `.sessions` wala folder nikaal kar `/var/www/bc-report/waha/sessions` mein rakhein.

### 6.3 Raasta B: khaali database (backup nahi)

```bash
cd /var/www/bc-report
BC_DB_PASS=$(openssl rand -hex 24); echo "BC_DB_PASS=$BC_DB_PASS" >> /root/golive.env
mariadb <<SQL
CREATE DATABASE bc_report CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bc_report'@'localhost' IDENTIFIED BY '$BC_DB_PASS';
GRANT ALL PRIVILEGES ON bc_report.* TO 'bc_report'@'localhost';
SQL
mariadb bc_report < database/schema.sql
for f in database/migrations/*.sql; do
  case "$f" in *2026_10_11_welcome.sql|*2026_10_12_target_msg.sql) continue ;; esac   # already inside schema.sql
  mariadb bc_report < "$f" || echo "CHECK: $f"
done

cp .env.example .env
setenv() { if grep -q "^$1=" .env; then sed -i "s|^$1=.*|$1=$2|" .env; else echo "$1=$2" >> .env; fi; }
setenv APP_URL https://bc.bharatseo.site
setenv DB_HOST localhost
setenv DB_NAME bc_report
setenv DB_USER bc_report
setenv DB_PASS "$BC_DB_PASS"
setenv WAHA_API_KEY "$(openssl rand -hex 32)"
setenv WAHA_DASHBOARD_PASSWORD "$(openssl rand -hex 16)"
setenv WEBHOOK_SECRET "$(openssl rand -hex 32)"
setenv FIREBASE_SA_FILE /var/www/bc-report/storage/firebase-sa.json
```

- Migration loop mein koi `CHECK:` nahi aana chahiye. Do purani migrations jaan-boojh kar chhodi gayi hain, kyunki unka kaam `schema.sql` mein pehle se hai. `schema.sql` ke baad ye "Data truncated" error deti hain, aur `--force` ke saath chalane par message queue ka "morning" type mita deti hain. Isliye `mysql --force` kabhi use na karein.
- **Firebase key** (push notification aur phone ring ke liye): Firebase console → Project settings → Service accounts → **Generate new private key**. Mili hui JSON file apne computer se bhejein: `scp file.json root@NAYA_IP:/var/www/bc-report/storage/firebase-sa.json`. Phir step 6.4 chalayein.
- Admin user step 6.4 ke baad banayein (wahan likha hai).

### 6.4 Permissions aur settings (Raasta A aur B dono)

```bash
set -a; . /root/golive.env; set +a
cd /var/www/bc-report
chown root:www-data .env && chmod 640 .env
mkdir -p public/uploads storage/logs
chown -R www-data:www-data storage public/uploads
[ -f storage/firebase-sa.json ] && chown root:www-data storage/firebase-sa.json && chmod 640 storage/firebase-sa.json

sudo -u www-data env LK_KEY="$LK_KEY" LK_SECRET="$LK_SECRET" TURN_PASS="$TURN_PASS" php -r '
require "src/bootstrap.php";
App\Settings::set("livekit_url", "wss://bc.bharatseo.site");
App\Settings::set("livekit_key", getenv("LK_KEY"));
App\Settings::set("livekit_secret", getenv("LK_SECRET"));
App\Settings::set("webrtc_ice", json_encode([["urls" => "stun:bc.bharatseo.site:3478"],
  ["urls" => ["turn:bc.bharatseo.site:3478?transport=udp", "turn:bc.bharatseo.site:3478?transport=tcp"],
   "username" => "bcturn", "credential" => getenv("TURN_PASS")]], JSON_UNESCAPED_SLASHES));
echo "settings saved\n";'
sudo -u www-data php -r 'require "src/bootstrap.php"; echo "BCs: ", App\DB::val("SELECT COUNT(*) FROM bcs"), "\n";'
```

`waha/` folder ka owner jaan-boojh kar nahi badla gaya: WhatsApp login ki files usi user ki rehni chahiye jo purane server par Docker ne banaya tha.

`settings saved` aur BC ki ginti aani chahiye (Raasta B mein `0`). Isse video ki nayi keys aur 1:1 call ka TURN password database mein aa jaate hain. Purana `webrtc_ice` purane IP par tha, wo bhi isse badal jaata hai.

Raasta B mein ab admin banayein (password 10+ akshar ka, do baar maangega):

```bash
cd /var/www/bc-report && sudo -u www-data php bin/create-admin.php admin "Aapka Naam"
```

## 7. noidarankers.in

### 7.1 Code aur alag PHP user

Website apne alag Linux user (`noidarankers`) se chalti hai. Isse website mein kabhi koi kamzori mili, tab bhi BC app ki `.env` aur data tak pahunch nahi hogi.

```bash
id noidarankers >/dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin noidarankers
git clone --branch feature/noidarankers-website https://github.com/dhdhdh51/Noidarankers.git /var/www/noidarankers
cat > /etc/php/8.3/fpm/pool.d/noidarankers.conf <<'EOF'
; noidarankers.in runs as its own Linux user, so it cannot read the BC app's files
[noidarankers]
user = noidarankers
group = noidarankers
listen = /run/php/php8.3-fpm-noidarankers.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 8
pm.process_idle_timeout = 30s
pm.max_requests = 500
EOF
```

### 7.2 Raasta A: purana data

```bash
OLDN=/root/old/wwwroot/noidarankers.in
cp -a $OLDN/config.php /var/www/noidarankers/config.php
[ -d $OLDN/public/uploads ] && rsync -a $OLDN/public/uploads/ /var/www/noidarankers/public/uploads/
php /root/dbinfo.php nr /var/www/noidarankers sql | mariadb
NR_DB=$(php /root/dbinfo.php nr /var/www/noidarankers name)
zcat /root/migration/db-nr.sql.gz \
  | sed -e 's/utf8mb4_uca1400_ai_ci/utf8mb4_unicode_ci/g' -e 's/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g' \
  | mariadb "$NR_DB"
for t in $(mariadb -N "$NR_DB" -e 'SHOW TABLES'); do echo "$t $(mariadb -N "$NR_DB" -e "SELECT COUNT(*) FROM \`$t\`")"; done > /root/counts-nr-new.txt
diff /root/migration/counts-nr.txt /root/counts-nr-new.txt && echo "OK: website ka saara data aa gaya"
```

### 7.3 Raasta B: naya database

```bash
cd /var/www/noidarankers
NR_DB_PASS=$(openssl rand -hex 24); NR_SETUP_KEY=$(openssl rand -hex 8)
echo "NR_DB_PASS=$NR_DB_PASS" >> /root/golive.env; echo "NR_SETUP_KEY=$NR_SETUP_KEY" >> /root/golive.env
mariadb <<SQL
CREATE DATABASE noidarankers CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'noidarankers'@'localhost' IDENTIFIED BY '$NR_DB_PASS';
GRANT ALL PRIVILEGES ON noidarankers.* TO 'noidarankers'@'localhost';
SQL
mariadb noidarankers < database.sql
mariadb noidarankers < database/noida-local-seo-posts.sql
sed -e "s#'your_database_name'#'noidarankers'#" -e "s#'your_database_user'#'noidarankers'#" \
    -e "s#'your_database_password'#'$NR_DB_PASS'#" -e "s#'CHANGE-ME-to-a-long-random-string'#'$(openssl rand -hex 32)'#" \
    -e "s#'setup_key' => 'CHANGE-ME'#'setup_key' => '$NR_SETUP_KEY'#" config.sample.php > config.php
```

Step 8 ke baad `https://noidarankers.in/admin/setup` kholein aur admin account banayein. Setup key yahan milegi: `grep NR_SETUP_KEY /root/golive.env`. Phone, email aur WhatsApp number Admin → Settings mein bharein.

### 7.4 Permissions, cron aur email (Raasta A aur B dono)

```bash
cd /var/www/noidarankers
chown -R root:noidarankers .
chmod 640 config.php
mkdir -p public/uploads storage/logs storage/cache storage/sessions
chown -R noidarankers:noidarankers public/uploads storage
systemctl restart php8.3-fpm
echo '15 3 * * * noidarankers php /var/www/noidarankers/cron/maintenance.php >/dev/null 2>&1' > /etc/cron.d/noidarankers
```

**Email:** `config.php` ke `mail` hisse mein `'transport' => 'mail'` ho to lead ki email nahi jaayegi, kyunki naye server par mail server nahi hai. Email chahiye to `'transport' => 'smtp'` karke apne mailbox ki SMTP details (jaise Hostinger email, ya Gmail app password) daalein. Email ke bina bhi har lead Admin → Leads mein dikhti hai.

## 8. SSL certificate aur dono site chalu

Pehle dekh lein ki DNS naye IP par aa gaya hai. Teeno mein `NAYA_IP` dikhna chahiye:

```bash
dig +short bc.bharatseo.site noidarankers.in www.noidarankers.in
```

Phir:

```bash
set -a; . /root/golive.env; set +a
rm -f /etc/nginx/sites-enabled/default
cat > /etc/nginx/sites-available/acme-temp <<'EOF'
server {
    listen 80;
    listen [::]:80;
    server_name bc.bharatseo.site noidarankers.in www.noidarankers.in;
    root /var/www/letsencrypt;
}
EOF
ln -sf /etc/nginx/sites-available/acme-temp /etc/nginx/sites-enabled/acme-temp
nginx -t && systemctl reload nginx
certbot certonly --webroot -w /var/www/letsencrypt -d bc.bharatseo.site \
  -m "$LE_EMAIL" --agree-tos -n --deploy-hook "systemctl reload nginx"
certbot certonly --webroot -w /var/www/letsencrypt -d noidarankers.in -d www.noidarankers.in \
  -m "$LE_EMAIL" --agree-tos -n --deploy-hook "systemctl reload nginx"
rm /etc/nginx/sites-enabled/acme-temp

cp /var/www/bc-report/deploy/nginx/bc-report.conf /etc/nginx/sites-available/bc-report
cat > /etc/nginx/sites-available/noidarankers <<'EOF'
# /etc/nginx/sites-available/noidarankers   (ln -s to sites-enabled)
# noidarankers.in (repo dhdhdh51/Noidarankers) on its own PHP-FPM pool (Linux user "noidarankers").
# The nginx version of the project's .htaccess rules. CSP and HSTS are sent by PHP.
server {
    listen 80;
    listen [::]:80;
    server_name noidarankers.in www.noidarankers.in;

    location /.well-known/acme-challenge/ { root /var/www/letsencrypt; }
    location / { return 301 https://noidarankers.in$request_uri; }
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name noidarankers.in www.noidarankers.in;

    ssl_certificate     /etc/letsencrypt/live/noidarankers.in/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/noidarankers.in/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:nrssl:10m;
    ssl_session_timeout 1d;

    root /var/www/noidarankers/public;
    index index.php;
    charset utf-8;
    client_max_body_size 8m;   # blog images (max 5 MB)
    server_tokens off;

    gzip on;
    gzip_types text/plain text/css text/xml text/javascript application/javascript application/json application/xml application/rss+xml application/manifest+json image/svg+xml image/x-icon;

    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=(), usb=(), browsing-topics=()" always;
    add_header Cross-Origin-Opener-Policy "same-origin" always;

    access_log /var/log/nginx/noidarankers.access.log;
    error_log  /var/log/nginx/noidarankers.error.log;

    # hidden files and folders (.htaccess, .git ...)
    location ~ /\.(?!well-known/) { deny all; }

    # uploaded images: never run or serve code from here
    location ^~ /uploads/ {
        location ~* \.(php\d?|phtml|phar|pl|py|cgi|sh|html?|js|svg)$ { deny all; }
        add_header X-Content-Type-Options "nosniff" always;
        add_header Cache-Control "public, max-age=31536000, immutable" always;
        try_files $uri =404;
    }

    location ^~ /assets/ {
        add_header X-Content-Type-Options "nosniff" always;
        add_header Cache-Control "public, max-age=31536000, immutable" always;
        try_files $uri =404;
    }

    # every other address goes to the front controller (clean URLs)
    location / { try_files $uri /index.php$is_args$args; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm-noidarankers.sock;
        fastcgi_read_timeout 120s;
    }
}
EOF
ln -sf /etc/nginx/sites-available/bc-report /etc/nginx/sites-enabled/bc-report
ln -sf /etc/nginx/sites-available/noidarankers /etc/nginx/sites-enabled/noidarankers
nginx -t && systemctl reload nginx
curl -sI https://bc.bharatseo.site/login.php | head -1
curl -sI https://noidarankers.in/ | head -1
```

Dono `curl` mein `HTTP/2 200` aana chahiye. Kisi ek domain ka DNS abhi nahi pahuncha aur uska `certbot` fail ho, to `nginx -t` bhi fail hoga. Tab us domain ki `ln -sf` line abhi chhod dein (dusri site chalu kar dein), aur DNS aane par uska `certbot` aur `ln -sf` dobara chalayein.

BC app ki nginx config repo mein hai: `deploy/nginx/bc-report.conf`. Isme video meeting ke liye `/rtc` (LiveKit) ka raasta, `/app/` pages, APK download aur security headers hain. Certbot har 60–90 din mein certificate apne aap renew karta hai.

## 9. WhatsApp (WAHA) aur cron

### 9.1 WhatsApp

Ye tabhi karein jab WhatsApp reporting chalani ho (purane server par WAHA tha, ya ab chahiye).

```bash
cd /var/www/bc-report
docker compose up -d
sleep 20; docker compose logs --tail 30 waha
```

- Raasta A mein WhatsApp ka login (`waha/sessions`) saath aaya hai, isliye status apne aap `WORKING` ho jaana chahiye. Panel → **WhatsApp** mein dekhein.
- Login nahi aaya ho to: Panel → **WhatsApp** → **Create / start session** → QR aayega → WhatsApp wale phone mein **Linked devices** → **Link a device** → QR scan karein.
- `set WAHA_API_KEY in .env` jaisa error aaye to purane server par WhatsApp (WAHA) chalta hi nahi tha. Ab chahiye to step 6.3 ki teen `setenv WAHA_… / WEBHOOK_SECRET` lines `/var/www/bc-report` mein chalayein, phir `docker compose up -d`.
- Purane server par WAHA band hona chahiye (step 5 ki script ise band karti hai). Ek WhatsApp login do server par ek saath na chalayein.
- Purane server wala WAHA version chahiye to `/root/migration/docker-ps.txt` mein image ka tag dekhein, aur `/var/www/bc-report/docker-compose.override.yml` mein `services: { waha: { image: "devlikeapro/waha:<tag>" } }` likhein.

### 9.2 Cron (report form, reminder, alarm, queue)

Cron sabse aakhir mein chalu karein, jab data aur site dono theek ho jaayein:

```bash
cp /var/www/bc-report/deploy/cron/bc-report /etc/cron.d/bc-report && chmod 644 /etc/cron.d/bc-report
sleep 70; tail -n 20 /var/www/bc-report/storage/logs/cron.log
```

Cron chalu hote hi aaj ke baaki kaam (jaise aakhri reminder ya alarm) ek baar chal sakte hain. Ye normal hai.

## 10. Go-live checks

| # | Kya check karna hai | Kya dikhna chahiye |
|---|---|---|
| 1 | `curl -sI https://bc.bharatseo.site/login.php \| head -1` | `HTTP/2 200` |
| 2 | Panel mein Admin login → Dashboard | Raasta A: BC, branch aur reports pehle jaise |
| 3 | BC ke phone par app kholein | Raasta A: bina login khul jaaye. Raasta B: naye password se login |
| 4 | Meetings → 📢 Broadcast shuru karein, 2 phone se judein | Dono taraf video, 👥 BC ginti, Chat, End sabke liye |
| 5 | CBC se BC ko 1:1 video call | Call jud jaaye, awaaz aaye |
| 6 | App alarm page se test ring | Phone par ring aaye (Firebase key theek hai) |
| 7 | Panel → WhatsApp (agar use hota hai) | `WORKING`; BC ke phone se `HELP` bhejne par jawab aaye |
| 8 | `tail -n 20 /var/www/bc-report/storage/logs/cron.log` | Har minute nayi line, koi error nahi |
| 9 | `tail -n 50 /var/www/bc-report/storage/logs/app-$(TZ=Asia/Kolkata date +%F).log` | Koi `Uncaught` ya `Fatal` nahi |
| 10 | `https://noidarankers.in`, ek blog, ek service page, `/admin` | Sab khule; admin mein ek test photo upload ho |
| 11 | Website ka contact form bhar kar dekhein | Lead Admin → Leads mein aaye |
| 12 | `dig +short TXT noidarankers.in` | `google-site-verification=…` |
| 13 | `certbot renew --dry-run` | `Congratulations, all simulated renewals succeeded` |
| 14 | `reboot`, 2 minute baad `systemctl is-active nginx php8.3-fpm mariadb livekit coturn docker` | Sab `active`; check 1 aur 4 dobara |

## 11. Roz ka automatic backup

Ye script roz raat 3:00 baje (IST) server ke saare database aur zaroori files `/var/backups/server` mein rakhti hai, 14 din tak.

```bash
cat > /usr/local/sbin/server-backup.sh <<'SCRIPT'
#!/bin/bash
# /usr/local/sbin/server-backup.sh - daily backup on the NEW server (root, from /etc/cron.d/server-backup).
# Every database + the files that are not in GitHub. Kept 14 days in /var/backups/server.
# If an rclone remote called "gdrive" exists, the new files are also copied to Google Drive (folder server-backups).
set -uo pipefail
DEST=${DEST:-/var/backups/server}
KEEP_DAYS=${KEEP_DAYS:-14}
ROOT=${ROOT:-/}
TS=$(date -u +%Y%m%d-%H%M)
umask 077
mkdir -p "$DEST"
fail=0

for db in $(mariadb -N -e 'SHOW DATABASES' | grep -vxE 'information_schema|performance_schema|mysql|sys'); do
  if mariadb-dump --single-transaction --quick --hex-blob --default-character-set=utf8mb4 --triggers "$db" | gzip > "$DEST/db-$db-$TS.sql.gz"; then
    echo "ok   db $db $(du -h "$DEST/db-$db-$TS.sql.gz" | cut -f1)"
  else
    echo "FAIL db $db"; fail=1
  fi
done

FILES=(var/www/bc-report/.env var/www/bc-report/storage/firebase-sa.json var/www/bc-report/public/uploads
       var/www/bc-report/waha/sessions var/www/noidarankers/config.php var/www/noidarankers/public/uploads
       etc/livekit etc/turnserver.conf)
EXIST=()
for f in "${FILES[@]}"; do [ -e "$ROOT/$f" ] && EXIST+=("$f"); done
if [ ${#EXIST[@]} -eq 0 ]; then
  echo "FAIL files: none of the expected files exist"; fail=1
elif tar -czf "$DEST/files-$TS.tar.gz" -C "$ROOT" "${EXIST[@]}"; then
  echo "ok   files $(du -h "$DEST/files-$TS.tar.gz" | cut -f1) (${#EXIST[@]} items)"
else
  echo "FAIL files"; fail=1
fi

find "$DEST" -type f -name '*.gz' -mtime +"$KEEP_DAYS" -delete

if command -v rclone >/dev/null 2>&1 && rclone listremotes 2>/dev/null | grep -qx 'gdrive:'; then
  # copy (never sync): an empty or damaged local folder must not delete the copies on Drive
  if rclone copy "$DEST" gdrive:server-backups --max-age 25h; then echo "ok   copied to Google Drive"; else echo "FAIL Google Drive copy"; fail=1; fi
  rclone delete gdrive:server-backups --min-age 30d >/dev/null 2>&1
fi
echo "$(date -u '+%F %T UTC') backup finished, status $fail"
exit $fail
SCRIPT
chmod 700 /usr/local/sbin/server-backup.sh
echo '30 21 * * * root /usr/local/sbin/server-backup.sh >> /var/log/server-backup.log 2>&1' > /etc/cron.d/server-backup
/usr/local/sbin/server-backup.sh && ls -lh /var/backups/server
```

Har database ke liye `ok   db …` aur `ok   files …` aana chahiye. Cron ka time `21:30` UTC hai, jo IST mein raat 3:00 hai.

**Server ke bahar copy (zaroori):** isi server par rakha backup server ke saath hi kho sakta hai, jaise AWS mein hua. Google Drive par roz copy ke liye `rclone config` chalayein: `n` → name `gdrive` → storage `drive` → client id/secret khaali → scope `3` (rclone sirf apni banayi files dekh sake) → baaki sawaalon par Enter → "Use auto config?" `n`. Rclone ek command dikhayega (`rclone authorize "drive" "…"`). Use apne computer par chalayein (rclone.org se rclone download karke), browser mein Google login karein, aur mila hua token server par paste karein. Uske baad script har raat backup Google Drive ke `server-backups` folder mein bhi copy karegi aur wahan 30 din se purani files hata degi. Check: `rclone ls gdrive:server-backups | tail`.

**Backup se wapas lana (example):**

```bash
# DBNAAM file ke naam mein hai: db-DBNAAM-YYYYmmdd-HHMM.sql.gz
zcat /var/backups/server/db-DBNAAM-YYYYmmdd-HHMM.sql.gz | mariadb DBNAAM
# sirf zarurat par: .env, uploads, WhatsApp login wagairah wapas (abhi wali files badal jaayengi)
tar -xzf /var/backups/server/files-YYYYmmdd-HHMM.tar.gz -C /
```

Mahine mein ek baar kisi test database mein restore karke dekh lein ki backup sach mein kaam karta hai. Website band hone par email alert ke liye UptimeRobot (free) par `https://bc.bharatseo.site/login.php` aur `https://noidarankers.in/` jod dein.

## 12. Purana AWS server band karna

Naya server 1–2 hafte bina dikkat chal jaaye, tab:

1. AWS Console → EC2 → purana instance → **Terminate**.
2. EC2 → Volumes, Snapshots aur Elastic IPs mein jo bacha ho, delete / release karein, warna unka bill aata rahega.
3. Aakhri bill bhar dein. Account ki zarurat na ho to Account settings se close karein.
4. Hostinger mein `bharatseo.site` ke "child nameservers" (`ns1`, `ns2`) ab kaam ke nahi hain. Chahein to hata dein.

**Wapas purane server par jaana (rollback):** naye server mein badi dikkat ho aur purana AWS server abhi zinda ho, to DNS ke A record purane IP par kar dein (TTL 300 hone se kuch minute lagte hain). Purane server par services chalu karein: `/usr/local/lsws/bin/lswsctrl start; systemctl start cron crond; docker start waha`. Dhyan rahe, naye server par beech mein aaya data purane server par nahi hoga.

## Aage code update kaise karein

```bash
cd /var/www/bc-report && git pull
git diff --name-only HEAD@{1} HEAD -- database/migrations   # is pull mein aayi nayi migrations
# har nayi file sirf ek baar chalayein:
# mariadb "$(php /root/dbinfo.php bc /var/www/bc-report name)" < database/migrations/NAYI_FILE.sql
systemctl reload php8.3-fpm
```

`meet.js`, `meet.css` ya `app.js` badle hon to phones par purana version cache mein reh sakta hai. Isliye inke `?v=` number badhaye jaate hain, aur logon ko app/meeting screen ek baar band karke kholni padti hai.

## Agar kuch galat ho

| Dikkat | Kya dekhein |
|---|---|
| `502 Bad Gateway` | `systemctl status php8.3-fpm`, aur `tail /var/log/nginx/bc-report.error.log` |
| Meeting "connect ho raha hai" par atki | `systemctl status livekit`; settings mein `livekit_url` = `wss://bc.bharatseo.site` aur key/secret wahi jo `/etc/livekit/livekit.yaml` mein; `node_ip` = server ka IP |
| Meeting judti hai par video/awaaz nahi | UDP 7882 aur TCP 7881 band hain: `ufw status`, aur hPanel ka firewall |
| 1:1 call nahi judti | `systemctl status coturn`; ports 3478 aur 49160–49200/udp; settings ka `webrtc_ice` |
| Database import par `Unknown collation` | Import wali `sed` line saath chalayein (step 6.2 / 7.2) |
| `certbot` fail | DNS abhi naye IP par nahi aaya, ya port 80 band hai |
| WhatsApp page par `UNREACHABLE` | `docker compose ps` (folder `/var/www/bc-report`); `.env` mein `WAHA_API_KEY` |
| Push / ring nahi aati | `storage/firebase-sa.json` hai ya nahi; `.env` mein `FIREBASE_SA_FILE` ka path |
| Phones par "Login expired" | Raasta B mein ye normal hai; BCs dobara login karein |
| Website ki lead email nahi aati | `config.php` mein SMTP (step 7.4) |
| App log mein `Permission denied` | Step 6.4 ki `chown` lines dobara chalayein |

## Kya kahan hai (naya server)

| Cheez | Jagah |
|---|---|
| BC app code | `/var/www/bc-report` (git) |
| BC app ke secrets | `/var/www/bc-report/.env`, `storage/firebase-sa.json` |
| BC app logs | `/var/www/bc-report/storage/logs/` (`app-YYYY-MM-DD.log`, `cron.log`) |
| WhatsApp login | `/var/www/bc-report/waha/sessions` |
| Website code aur config | `/var/www/noidarankers` (git), `config.php` |
| nginx | `/etc/nginx/sites-available/bc-report`, `noidarankers` |
| Video (LiveKit) | `/etc/livekit/livekit.yaml`, `systemctl status livekit` |
| 1:1 call (TURN) | `/etc/turnserver.conf`, `systemctl status coturn` |
| Cron | `/etc/cron.d/bc-report`, `noidarankers`, `server-backup` |
| Backup | `/var/backups/server`, log `/var/log/server-backup.log` |
| Is setup ke naye passwords | `/root/golive.env` |
