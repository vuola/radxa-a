# Radxa-A weather archive setup

This repository documents the weather archive stack running on radxa-a.local and the upload workflow from moxa.local.

## Overview

- **radxa-a.local** runs a k3s cluster that hosts:
  - PostgreSQL for weather data (persisted to the SSD at /media/ssd250)
  - NGINX + PHP for a simple web UI and ingest endpoint
  - Daily DB backups to /media/ssd250/weather/backups
- **moxa.local** keeps the live SQLite database and uploads an online backup once per day.
- **Backup retention**: keep the **latest 3 dumps** (today + 2 previous) via retention-based rotation to balance disk usage and safety.

## Components on radxa-a.local

### Editing manifests (correct workflow)

Where to edit:

- **Local/custom changes** belong in /home/vuola/.kube-cron-jobs/local-manifests/
  - Example: /home/vuola/.kube-cron-jobs/local-manifests/weather.yaml
- **Upstream/base manifests** are pulled into /home/vuola/.kube-cron-jobs/pubcluster/ and should not be edited locally.

How updates are applied:

- /home/vuola/.kube-cron-jobs/update-cluster.sh renders HOSTNAME placeholders and copies the rendered YAML into
  /var/lib/rancher/k3s/server/manifests for auto-apply by k3s.
- Local manifests are applied on every run and override pubcluster files with the same filename.

Correct process:

1. Edit /home/vuola/.kube-cron-jobs/local-manifests/*.yaml (use HOSTNAME where needed).
2. Run /home/vuola/.kube-cron-jobs/update-cluster.sh (or wait for the cron run).

### Weather namespace resources

Deployed in namespace `weather`:

- **StatefulSet**: weather-postgres (PostgreSQL 16)
- **Service**: weather-postgres (ClusterIP:5432)
- **CronJob**: weather-postgres-backup (nightly backups)
- **CronJob**: weather-sqlite-import (imports uploaded SQLite to PostgreSQL)
- **Deployment**: weather-web (NGINX + PHP-FPM)
- **Service**: weather-web (ClusterIP:80)
- **Ingress**: weather-web (Traefik)

### Storage paths (radxa-a.local)

- PostgreSQL data: /media/ssd250/weather/postgres
- Backups: /media/ssd250/weather/backups
- Upload inbox: /media/ssd250/weather/inbox

### Endpoints

-- Web UI: http://radxa-a.local/
-- Ingest endpoint:
  - POST JSON to http://radxa-a.local/ingest.php
  - Upload SQLite file as multipart field `sqlite` to the same endpoint

## moxa.local upload workflow (Option A)

**Goal**: upload a daily online SQLite backup at midnight without stopping weather services.

### Script

Path on moxa.local:

- /usr/local/bin/moxa-upload-archive.sh

Behavior:

1. Creates an online SQLite backup of /var/lib/moxa/weather.db
2. Uploads the backup to http://radxa-a.local/ingest.php
3. On success, deletes rows older than 24 hours and truncates WAL

### Systemd unit and timer

- /etc/systemd/system/moxa-archive-upload.service
- /etc/systemd/system/moxa-archive-upload.timer

Timer schedule:

- Daily at 00:00:00 (midnight)

### SQLite import to PostgreSQL

Uploaded SQLite files are imported into PostgreSQL by a nightly CronJob at 00:10.
Duplicates are prevented using a unique constraint on `ts` (timestamp) and `ON CONFLICT DO NOTHING`.

### Best-practice (least privilege)

To avoid interactive sudo and minimize root usage, run the backup uploader as a dedicated, unprivileged service user.

Recommended setup on moxa.local (run once as root):

```
# create a service user with no shell login
sudo useradd --system --home /var/lib/moxa --shell /usr/sbin/nologin moxa-backup

# allow moxa-backup to read/write the DB and archive dir via www-data group
sudo usermod -aG www-data moxa-backup

# ensure group ownership and setgid on the DB directory
sudo mkdir -p /var/lib/moxa /var/lib/moxa/archive
sudo chown -R vuola:www-data /var/lib/moxa
sudo chmod 2775 /var/lib/moxa /var/lib/moxa/archive

# restrict DB file access to owner+group
sudo chown vuola:www-data /var/lib/moxa/weather.db
sudo chmod 0660 /var/lib/moxa/weather.db
```

With this in place, the service runs as `moxa-backup` (see service file below) and no interactive sudo is needed for nightly runs.

### Install commands (run on radxa-a.local)

Copy files to moxa.local:

```
scp /home/vuola/moxa-upload/moxa-upload-archive.sh vuola@moxa.local:/tmp/
scp /home/vuola/moxa-upload/moxa-archive-upload.service vuola@moxa.local:/tmp/
scp /home/vuola/moxa-upload/moxa-archive-upload.timer vuola@moxa.local:/tmp/
```

Install on moxa.local:

```
ssh vuola@moxa.local <<'EOF'
sudo mv /tmp/moxa-upload-archive.sh /usr/local/bin/moxa-upload-archive.sh
sudo chmod 755 /usr/local/bin/moxa-upload-archive.sh

sudo mv /tmp/moxa-archive-upload.service /etc/systemd/system/moxa-archive-upload.service
sudo mv /tmp/moxa-archive-upload.timer /etc/systemd/system/moxa-archive-upload.timer

sudo systemctl daemon-reload
sudo systemctl enable --now moxa-archive-upload.timer
sudo systemctl status moxa-archive-upload.timer --no-pager
EOF
```

Optional immediate run:

```
# NOTE: starting the system unit requires root or polkit authorization.
# If you don't have sudoers rights, skip this and let the timer run at midnight.
ssh vuola@moxa.local "sudo systemctl start moxa-archive-upload.service"
```

Admin-only immediate test (run on moxa.local):

```
sudo systemctl start moxa-archive-upload.service
sudo systemctl status moxa-archive-upload.service --no-pager
sudo journalctl -u moxa-archive-upload.service -n 200 --no-pager
```

### First-run verification (sudoers-free)

Run these checks as the normal user (no sudo required):

```
# confirm the timer is active
systemctl status moxa-archive-upload.timer --no-pager

# see next scheduled run time
systemctl list-timers --all | grep moxa-archive-upload

# verify a fresh archive file was created and removed after upload
ls -l /var/lib/moxa/archive

# confirm the DB still has rows after pruning
sqlite3 -header -column /var/lib/moxa/weather.db "SELECT COUNT(*) AS rows FROM weather;"
```

Success verification (radxa-a.local):

```
# a new SQLite upload should appear here after a successful run
ls -l /media/ssd250/weather/inbox

# optional: check the ingest endpoint is live (GET returns 405)
curl -i http://radxa-a.local/ingest.php
```

If the service fails, view logs without sudo (requires systemd journal permissions for your user):

```
journalctl -u moxa-archive-upload.service -n 200 --no-pager
```

## DNS / mDNS notes

If radxa-a.local cannot resolve moxa.local, enable mDNS on radxa-a.local:

- Install `avahi-daemon` and `libnss-mdns`
- Start and enable avahi

If you need a quick workaround, add moxa’s IP to /etc/hosts on radxa-a.local.

## Quick checks

On radxa-a.local:

```
kubectl -n weather get pods,svc,ingress
curl -sSf http://192.168.68.111/ -H "Host: radxa-a.local"
```

On moxa.local:

```
ls -l /var/lib/moxa/weather.db
sqlite3 -header -column /var/lib/moxa/weather.db "SELECT COUNT(*) FROM weather;"
sudo systemctl status moxa-archive-upload.timer --no-pager
```

## MQTT service (heating control)

The weather stack also exposes an MQTT broker (Eclipse Mosquitto) in the `weather` namespace. A NodePort service is published on the LAN at port 31884 (TCP) and is used to switch the geothermal pump & water warmer between **ALLOW** (external grid power allowed) and **PROHIBIT** (external grid power disallowed during peak tariff hours).

Switching commands to test manually in the home domain:

```
mosquitto_pub -h 192.168.68.111 -p 31884 -t ivt/heating/mode/set -m PROHIBIT -q 1 -r
mosquitto_pub -h 192.168.68.111 -p 31884 -t ivt/heating/mode/set -m ALLOW -q 1 -r
```
This works also from radxa-a.local command prompt in case the mosquitto services are installed:
```
sudo apt update
sudo apt install mosquitto-clients -y
```

## ENTSO-E day-ahead price retrieval

The weather stack maintains a local copy of day-ahead energy prices for Finland from the ENTSO-E (European Network of Transmission System Operators for Electricity) API.

### Overview

- **Data source**: ENTSO-E Transparency Platform API (day-ahead prices, document type A44)
- **Region**: Finland (domain 10YFI-1--------U)
- **Frequency**: 15-minute intervals aligned to 00, 15, 30, 45 minutes past the hour
- **Storage**: PostgreSQL `entsoe_prices` table (persisted to /media/ssd250/weather/postgres)
- **Update schedule**: Daily at 14:10, 15:10, 16:10 CET (with retries for delayed API publication)

### Database schema

Table: `entsoe_prices`

| Column | Type | Notes |
|--------|------|-------|
| `ts` | TIMESTAMPTZ PRIMARY KEY | UTC timestamp, must align to 15-minute boundaries (minutes 0, 15, 30, 45) |
| `price_eur_per_mwh` | DOUBLE PRECISION NULL | Day-ahead electricity price in €/MWh; forward-filled with previous value if ENTSO-E has gaps |
| `created_at` | TIMESTAMPTZ | Auto-set on row creation |
| `updated_at` | TIMESTAMPTZ | Auto-set on row creation; updated on upsert |

15-minute alignment is enforced via CHECK constraint:
```sql
date_trunc('minute', ts) = ts AND date_part('minute', ts) IN (0, 15, 30, 45)
```

### CronJob configuration

**Resource**: CronJob `entsoe-dayahead-import` in namespace `weather`

**Schedule**: `"10 14,15,16 * * *"` (daily at 14:10, 15:10, 16:10 CET)

**Behavior**:
1. Fetches next day's forecast prices from ENTSO-E API
2. Parses XML response and extracts prices by 15-minute timestamp
3. Forward-fills any gaps (missing time slots) with the previous 15-minute slot's price
4. Upserts rows into `entsoe_prices` table (INSERT ... ON CONFLICT DO UPDATE)
5. Completes in ~17 seconds

**Environment variables** (from secrets):
- `PGHOST`, `PGDATABASE`, `PGUSER`, `PGPASSWORD`: PostgreSQL connection (from secret `weather-db`)
- `ENTSOE_API_KEY`: ENTSO-E API token (from secret `entsoe-api`)
- `ENTSOE_IN_DOMAIN`, `ENTSOE_OUT_DOMAIN`: Market area code (10YFI-1--------U for Finland)
- `ENTSOE_MARKET_AGREEMENT`: Contract type (A01 for day-ahead)
- `ENTSOE_TZ`: Local timezone (Europe/Helsinki)

### API key setup

The ENTSO-E API requires authentication. To set up:

1. Register at https://www.entsoe.eu/data/energy-identification-codes-eic/
2. Request API access and receive your security token
3. Store it in the Kubernetes secret:

```bash
kubectl -n weather create secret generic entsoe-api \
  --from-literal=API_KEY='<your-token-here>' \
  --dry-run=client -o yaml | kubectl apply -f -
```

### Manual import test

To manually trigger an import (useful for testing or backfilling):

```bash
kubectl -n weather create job --from=cronjob/entsoe-dayahead-import entsoe-test-import-$(date +%s)
```

Monitor the job:

```bash
kubectl -n weather logs job/entsoe-test-import-<timestamp>
```

### Verification queries

Check table size:

```bash
kubectl -n weather exec statefulset/weather-postgres -- psql -U weather -d weather -c \
  "SELECT COUNT(*) as rows, MIN(ts) as earliest, MAX(ts) as latest FROM entsoe_prices;"
```

Check for gaps (NULL prices):

```bash
kubectl -n weather exec statefulset/weather-postgres -- psql -U weather -d weather -c \
  "SELECT COUNT(*) as missing FROM entsoe_prices WHERE price_eur_per_mwh IS NULL;"
```

View recent prices (in local timezone):

```bash
kubectl -n weather exec statefulset/weather-postgres -- psql -U weather -d weather -c \
  "SELECT ts AT TIME ZONE 'Europe/Helsinki' as local_time, price_eur_per_mwh FROM entsoe_prices \
   ORDER BY ts DESC LIMIT 96;"
```

### Gap-filling strategy

If ENTSO-E publishes data with missing time slots (which occasionally occurs due to data feed delays), the importer applies forward-fill: missing 15-minute slots receive the price of the most recent preceding slot with data.

Example:
- 14:30 = 130.00 €/MWh (from ENTSO-E)
- 14:45 = NULL in ENTSO-E → forward-filled to 130.00 €/MWh
- 15:00 = 130.01 €/MWh (from ENTSO-E)

This matches ENTSO-E's own behavior and ensures continuous data coverage.
