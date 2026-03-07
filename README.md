# Radxa-A weather archive setup

This repository documents the weather archive stack running on radxa-a.local and the upload workflow from moxa.local.

## Table of Contents

- [Overview](#overview)
- [Quick Start: Maintenance & Deployment](#quick-start-maintenance--deployment)
  - [Editing and deploying code changes](#editing-and-deploying-code-changes)
  - [Common maintenance tasks](#common-maintenance-tasks)
- [Components on radxa-a.local](#components-on-radxa-a-local)
  - [Editing manifests (correct workflow)](#editing-manifests-correct-workflow)
  - [Weather namespace resources](#weather-namespace-resources)
  - [Storage paths](#storage-paths-radxa-a-local)
  - [Script locations](#script-locations-radxa-a-local)
  - [Endpoints](#endpoints)
  - [Health monitoring](#health-monitoring)
  - [Web UI](#web-ui-index-php)
- [moxa.local upload workflow](#moxa-local-upload-workflow-option-a)
- [DNS / mDNS notes](#dns--mdns-notes)
- [Quick checks](#quick-checks)
- [moxa.local REST API](#moxa-local-rest-api)
- [moxa.local system reference](#moxa-local-system-reference)
- [MQTT service (heating control)](#mqtt-service-heating-control)
- [Moxa weather 15-minute averages](#moxa-weather-15-minute-averages)
- [ENTSO-E day-ahead price retrieval](#entso-e-day-ahead-price-retrieval)
- [Weather fusion Parquet export](#weather-fusion-parquet-export)

## Overview

- **radxa-a.local** runs a k3s cluster that hosts:
  - PostgreSQL for weather data (persisted to the SSD at /media/ssd250)
  - NGINX + PHP for a simple web UI and ingest endpoint
  - Daily DB backups to /media/ssd250/weather/backups
- **moxa.local** keeps the live SQLite database and uploads an online backup once per day.
- **Backup retention**: keep the **latest 7 dumps** via retention-based rotation to balance disk usage and safety.

## Quick Start: Maintenance & Deployment

This section provides the essential workflows for software maintainers.

### Editing and deploying code changes

**Where to edit**:

- **Kubernetes manifests**: `/home/vuola/.kube-cron-jobs/local-manifests/` (e.g., weather.yaml)
- **Web files** (PHP): `/home/vuola/.kube-cron-jobs/weather-web/` (index.php, api/health.php, export.php, ingest.php)
- **Import scripts** (Python): `/home/vuola/.kube-cron-jobs/weather-scripts/` (entsoe_import.py, fmi_forecast_import.py, moxa_weather_import.py)

**How to deploy**:

```bash
# 1. Edit files in the locations above

# 2. Deploy changes (regenerates ConfigMaps and applies manifests)
sudo /home/vuola/.kube-cron-jobs/update-cluster.sh

# 3. Verify deployment
kubectl -n weather get pods
kubectl -n weather rollout status deployment/weather-web
```

The `update-cluster.sh` script:
- Generates ConfigMaps from weather-web/ and weather-scripts/ directories
- Substitutes HOSTNAME placeholders in manifests
- Copies rendered manifests to `/var/lib/rancher/k3s/server/manifests/` for k3s auto-apply

### Common maintenance tasks

**Check system health**:
```bash
# Web UI with health status indicator
curl http://radxa-a.local/

# Health API endpoint
curl http://radxa-a.local/api/health.php | jq

# Pod status
kubectl -n weather get pods
```

**View logs**:
```bash
# CronJob logs (replace job name)
kubectl -n weather logs job/moxa-weather-15min-import-<id>

# Web server logs
kubectl -n weather logs deployment/weather-web -c nginx
kubectl -n weather logs deployment/weather-web -c phpfpm

# PostgreSQL logs
kubectl -n weather logs statefulset/weather-postgres
```

**Database access**:
```bash
# Connect to PostgreSQL
kubectl -n weather exec -it statefulset/weather-postgres -- psql -U weather -d weather

# Check table sizes
kubectl -n weather exec statefulset/weather-postgres -- psql -U weather -d weather -c \
  "SELECT schemaname, tablename, pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) AS size \
   FROM pg_tables WHERE schemaname = 'public' ORDER BY pg_total_relation_size(schemaname||'.'||tablename) DESC;"
```

**Backup management**:
```bash
# List backups
ls -lh /media/ssd250/weather/backups/

# Manually trigger backup
kubectl -n weather create job --from=cronjob/weather-postgres-backup manual-backup-$(date +%s)

# Restore from backup
kubectl -n weather exec -it statefulset/weather-postgres -- psql -U weather -d weather < /path/to/backup.sql
```

**Restart services**:
```bash
# Restart web server
kubectl -n weather rollout restart deployment/weather-web

# Restart PostgreSQL (careful - brief downtime)
kubectl -n weather delete pod weather-postgres-0
```

## Components on radxa-a.local

### Editing manifests (correct workflow)

Where to edit:

- **Local/custom changes** belong in /home/vuola/.kube-cron-jobs/local-manifests/
  - Example: /home/vuola/.kube-cron-jobs/local-manifests/weather.yaml
- **Web files** live in /home/vuola/.kube-cron-jobs/weather-web/
- **Importer scripts** live in /home/vuola/.kube-cron-jobs/weather-scripts/

How updates are applied:

- /home/vuola/.kube-cron-jobs/update-cluster.sh renders HOSTNAME placeholders and copies the rendered YAML into
  /var/lib/rancher/k3s/server/manifests for auto-apply by k3s.
- The script also generates ConfigMaps from the files in weather-web/ and weather-scripts/ and writes them into
  /home/vuola/.kube-cron-jobs/local-manifests before applying.
- Local manifests are applied on every run.

Correct process:

1. Edit /home/vuola/.kube-cron-jobs/local-manifests/*.yaml (use HOSTNAME where needed).
2. Edit web files in /home/vuola/.kube-cron-jobs/weather-web/ and scripts in /home/vuola/.kube-cron-jobs/weather-scripts/.
3. Run /home/vuola/.kube-cron-jobs/update-cluster.sh (or wait for the cron run).

### Weather namespace resources

Deployed in namespace `weather`:

- **StatefulSet**: weather-postgres (PostgreSQL 16)
- **Service**: weather-postgres (ClusterIP:5432)
- **CronJob**: weather-postgres-backup (nightly backups)
- **CronJob**: weather-sqlite-import (imports uploaded SQLite to PostgreSQL)
- **CronJob**: entsoe-dayahead-import (day-ahead prices)
- **CronJob**: fmi-forecast-import (weather forecast)
- **CronJob**: moxa-weather-15min-import (15-minute weather averages from moxa.local)
- **CronJob**: create-fusion-view (refreshes SQL view for the web UI)
- **Deployment**: weather-web (NGINX + PHP-FPM)
- **Service**: weather-web (ClusterIP:80)
- **Ingress**: weather-web (Traefik)

### Storage paths (radxa-a.local)

- PostgreSQL data: /media/ssd250/weather/postgres
- Backups: /media/ssd250/weather/backups
- Upload inbox: /media/ssd250/weather/inbox

### Script locations (radxa-a.local)

- ENTSO-E importer: /home/vuola/.kube-cron-jobs/weather-scripts/entsoe_import.py
- FMI importer: /home/vuola/.kube-cron-jobs/weather-scripts/fmi_forecast_import.py
- Moxa weather importer: /home/vuola/.kube-cron-jobs/weather-scripts/moxa_weather_import.py
- SQLite import: /home/vuola/.kube-cron-jobs/weather-scripts/sqlite_import.py
- Fusion view SQL: /home/vuola/.kube-cron-jobs/weather-scripts/create_fusion_view.sql

### Endpoints

- Web UI: http://radxa-a.local/
- Health API: http://radxa-a.local/api/health.php
- Ingest endpoint:
  - POST JSON to http://radxa-a.local/ingest.php
  - Upload SQLite file as multipart field `sqlite` to the same endpoint

### Health monitoring

The weather stack includes a health monitoring system that provides real-time visibility into system status.

**Health API endpoint**: `/api/health.php`

Returns JSON with overall system status and any failing checks:

```json
{
  "overall_status": "ok",
  "checked_at": "2026-03-07T19:07:09Z",
  "failures": []
}
```

**Status levels**:
- `ok` — All checks passing
- `warn` — Minor issues detected (e.g., moxa data 20-60 minutes stale)
- `error` — Critical issues (e.g., database unreachable, backups failed)

**Health checks performed**:

1. **Database connectivity** — PostgreSQL responds to `SELECT 1`
2. **Moxa 15-min data freshness** — Latest `moxa_weather_15min` row age:
   - ≤20 minutes: OK
   - ≤60 minutes: WARN (data delayed)
   - >60 minutes: ERROR (import likely failing)
3. **FMI forecast horizon** — Forecast covers at least +6 hours ahead
4. **ENTSOE prices coverage** — Today's price data present
5. **Backup validity** — Latest backup:
   - Age ≤26 hours (nightly backup at 02:15)
   - File size ≥100KB (validates against zero-byte dumps)
6. **Parquet export freshness** — Latest export age ≤30 minutes

**Web UI integration**:

The main page displays a health status indicator at the top:
- **Green dot + "System OK"** — All checks passing
- **Yellow dot + "System WARNING"** — Non-critical issues detected
- **Red dot + "System ERROR"** — Critical failures present

When issues are detected, the indicator expands to show failure details. Auto-refreshes every 60 seconds.

**Manual health check**:
```bash
# JSON output
curl http://radxa-a.local/api/health.php | jq

# Check specific details
curl -s http://radxa-a.local/api/health.php | jq '.failures[]'
```

**Troubleshooting common failures**:

- **Moxa data stale**: Check moxa.local is reachable and `moxa-weather-15min-import` CronJob is running
- **Backup failed**: Check `/media/ssd250/weather/backups/` for recent dumps, view backup CronJob logs
- **FMI forecast stale**: Check `fmi-forecast-import` CronJob logs, verify FMI API is accessible
- **ENTSOE prices missing**: Check ENTSO-E API key secret, verify `entsoe-dayahead-import` ran successfully

### Web UI (index.php)

The web interface displays a curated selection of 13 columns from the 22-column `weather_fusion` view, showing electricity prices alongside forecasted and measured weather data.

**Display columns** (numbered 1-13):

1. **Time** - Timestamp in HH:MM format (Europe/Helsinki timezone)
2. **Price** - Electricity price (cent/kWh, including margin and VAT 25.5%)
3. **FC_Temp** - Forecast temperature (°C) from FMI
4. **Moxa_Temp** - Measured temperature (°C) from moxa.local weather station
5. **FC_Wind** - Forecast wind speed (m/s) from FMI
6. **Moxa_Wind** - Measured wind speed (m/s) from moxa.local
7. **FC_Dir** - Forecast wind direction (degrees) from FMI
8. **Moxa_Dir** - Measured wind direction (degrees) from moxa.local
9. **FC_Cloud** - Forecast cloud cover (%) from FMI
10. **FC_Rad** - Forecast solar radiation (W/m²) from FMI
11. **PV_Feed** - PV system feed-in power (W) from moxa.local
12. **Active_Power** - Active power at point of common coupling (W) from moxa.local
13. **Battery_SOC** - Battery state of charge (%) from moxa.local

**Features**:

- **Numbered headers**: Columns use numeric labels 1-13 for compact display
- **Legend**: Expandable legend above table explains what each column number represents
- **Date toggle**: Switch between "Today" and "Tomorrow" views via URL parameters
- **Center alignment**: All table cells use monospace font with center alignment for readability
- **Data formatting**: 
  - 1 decimal place for temperatures, wind speeds, and solar radiation
  - 0 decimals for integers (degrees, percentages, power values)
  - Missing data shown as "-"
- **Export links**: CSV export available at bottom of page

**Source columns from weather_fusion view**:

The view contains 22 columns total:
- 3 metadata: `ts`, `price_eur_per_mwh`, `price_updated_at`
- 5 forecast: `fc_temperature_c`, `fc_wind_speed_ms`, `fc_wind_direction_deg`, `fc_cloud_cover_pct`, `fc_shortwave_radiation_w_m2`
- 14 measured: `moxa_temperature_c`, `moxa_dew_point_c`, `moxa_humidity_pct`, `moxa_pressure_hpa`, `moxa_wind_speed_ms`, `moxa_wind_direction_deg`, `moxa_wind_gust_ms`, `moxa_precipitation_mm_h`, `moxa_pv_feed_in_w`, `moxa_pv_generation_w`, `moxa_grid_import_w`, `moxa_active_power_pcc_w`, `moxa_battery_soc_pct`, `moxa_load_power_w`

**File location**: `/home/vuola/.kube-cron-jobs/weather-web/index.php`

**Deployment**: Served via `weather-web` deployment (NGINX + PHP-FPM) with ConfigMap-based file mounting. Run `/home/vuola/.kube-cron-jobs/update-cluster.sh` after editing to regenerate ConfigMap and apply changes.

## moxa.local upload workflow (Option A)

**Goal**: upload a daily online SQLite backup at midnight without stopping weather services.

### Background: moxa.local weather services

Before setting up the upload, understand the existing services on moxa.local:

**Core weather collection services** (running as `www-data`):
- `weather.service` — reads serial weather station, writes `/var/www/html/data/weather.json`
- `moxa-sma-json.service` — reads SMA inverter via Modbus, writes `/var/www/html/data/sma.json`
- `moxa-insert.service` + `moxa-insert.timer` — merges JSON files into SQLite `/var/lib/moxa/weather.db`

**REST API**:
- `/api/avg.php` — provides averaged weather data for radxa-a.local's 15-minute import

### Script

Path on moxa.local:

- /usr/local/bin/moxa-upload-archive.sh

Behavior:

1. Creates an online SQLite backup of `/var/lib/moxa/weather.db`
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

### User and permissions

The moxa.local system runs weather services as `www-data` user (member of `dialout` group for serial access). The SQLite database is owned by `vuola:www-data` with mode `0660`, allowing both the owner and group members to read/write.

Recommended setup on moxa.local (run once as root):

```bash
# ensure www-data is in dialout group (for serial device access)
sudo usermod -aG dialout www-data

# create DB directory with setgid for group inheritance
sudo mkdir -p /var/lib/moxa /var/lib/moxa/archive
sudo chown vuola:www-data /var/lib/moxa
sudo chmod 2775 /var/lib/moxa /var/lib/moxa/archive

# restrict DB file access to owner+group
sudo chown vuola:www-data /var/lib/moxa/weather.db
sudo chmod 0660 /var/lib/moxa/weather.db
```

The upload service should run as `www-data` user (matching existing services) or as a dedicated `moxa-backup` user added to `www-data` group. Either approach works; `www-data` is simpler since it's already configured.

### Install commands (run on radxa-a.local)

Copy files to moxa.local:

```
scp /home/vuola/moxa-upload/moxa-upload-archive.sh vuola@moxa.local:/tmp/
scp /home/vuola/moxa-upload/moxa-archive-upload.service vuola@moxa.local:/tmp/
scp /home/vuola/moxa-upload/moxa-archive-upload.timer vuola@moxa.local:/tmp/
```

Install on moxa.local:

```bash
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

**Note**: The service file should specify `User=www-data` to match existing moxa.local services. Alternatively, create a dedicated `moxa-backup` user and add it to the `www-data` group.

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

```bash
# confirm the timer is active
systemctl status moxa-archive-upload.timer --no-pager

# see next scheduled run time
systemctl list-timers --all | grep moxa-archive-upload

# verify a fresh archive file was created and removed after upload
ls -l /var/lib/moxa/archive

# confirm the DB still has rows after pruning
sqlite3 -header -column /var/lib/moxa/weather.db "SELECT COUNT(*) AS rows FROM weather;"

# check that existing services are running
sudo systemctl status weather.service moxa-sma-json.service moxa-insert.timer --no-pager
```

Success verification (radxa-a.local):

```bash
# a new SQLite upload should appear here after a successful run
ls -l /media/ssd250/weather/inbox

# optional: check the ingest endpoint is live (GET returns 405)
curl -i http://radxa-a.local/ingest.php
```

If the service fails, view logs without sudo (requires systemd journal permissions for your user):

```bash
journalctl -u moxa-archive-upload.service -n 200 --no-pager
```

## DNS / mDNS notes

### K3s cluster and moxa.local

The k3s cluster cannot resolve mDNS names (like moxa.local) from within pods. To work around this, the moxa weather CronJob uses a `hostAlias` to map `moxa.local` to its static IP address `192.168.68.127`.

**Important**: Configure a DHCP reservation on your router to ensure moxa.local always gets IP `192.168.68.127`. Without a fixed reservation, the pod will fail to connect if moxa.local's IP changes.

### Host system (radxa-a.local)

If radxa-a.local cannot resolve moxa.local, enable mDNS:

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

```bash
ls -l /var/lib/moxa/weather.db
sqlite3 -header -column /var/lib/moxa/weather.db "SELECT COUNT(*) FROM weather;"
sudo systemctl status moxa-archive-upload.timer weather.service moxa-insert.timer --no-pager
```

## moxa.local REST API

The moxa.local server exposes a REST API for retrieving averaged weather data:

### Averaged weather endpoint

**Endpoint**: `/api/avg.php`

**Query parameters**:
- `n` (required): number of newest 1-minute rows to average
- `cols` (required): comma-separated column names to return

**Wind averaging**: Vector averaging is used for `wind_speed_ms` and `wind_direction_deg`

**SMA averaging**: When `sma_json` is requested, each JSON key is averaged separately

**Example request** (15-minute average used by radxa-a.local):
```bash
curl -s 'http://moxa.local/api/avg.php?n=900&cols=temperature_c,pressure_hpa,wind_speed_ms,wind_direction_deg,pv_feed_in_w,battery_soc_pct,active_power_pcc_w,sma_json'
```

This endpoint is called every 15 minutes by the `moxa-weather-15min-import` CronJob on radxa-a.local.

## moxa.local system reference

For detailed information about the weather station server configuration, see [MOXA.md](MOXA.md), which contains:

- Core service descriptions (weather.service, moxa-sma-json.service, moxa-insert.service)
- Binary locations and rebuild instructions
- SQLite database schema and table structure
- Directory ownership and permission model
- Deployment procedures and critical file locations
- Web UI structure and CSV export endpoint

The MOXA.md file is a copy from the actual weather station server and should be considered the authoritative reference for moxa.local configuration details.

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

## Moxa weather 15-minute averages

The weather stack retrieves 15-minute averaged weather data from moxa.local's local weather station and integrates it into the fusion view alongside FMI forecasts and ENTSO-E prices.

### Overview

- **Data source**: moxa.local `/api/avg.php` endpoint (retrieves 15-minute averaged measurements from the weather station)
- **Frequency**: Every 15 minutes at 00, 15, 30, 45 minute boundaries
- **Storage**: PostgreSQL `moxa_weather_15min` table (persisted to /media/ssd250/weather/postgres)
- **Update schedule**: CronJob runs at 00, 15, 30, 45 minute marks

### Database schema

Table: `moxa_weather_15min`

| Column | Type | Notes |
|--------|------|-------|
| `ts` | TIMESTAMPTZ PRIMARY KEY | UTC timestamp, aligned to 15-minute boundaries (minutes 0, 15, 30, 45) |
| `temperature_c` | DOUBLE PRECISION | Instantaneous temperature in °C |
| `dew_point_c` | DOUBLE PRECISION | Dew point in °C |
| `relative_humidity` | DOUBLE PRECISION | Relative humidity as percentage (0–100) |
| `pressure_hpa` | DOUBLE PRECISION | Atmospheric pressure in hPa |
| `wind_speed_ms` | DOUBLE PRECISION | Wind speed in m/s |
| `wind_direction_deg` | DOUBLE PRECISION | Wind direction in degrees (0–360) |
| `precip_mmph` | DOUBLE PRECISION | Precipitation rate in mm/h |
| `energy_today_wh` | BIGINT | PV energy generated today in Wh |
| `pv_feed_in_w` | INTEGER | PV feed-in power in W |
| `battery_soc_pct` | INTEGER | Battery state of charge in % |
| `active_power_pcc_w` | INTEGER | Active power at point of common coupling in W |
| `bat_charge_w` | INTEGER | Battery charge power in W |
| `bat_discharge_w` | INTEGER | Battery discharge power in W |
| `sma_json` | JSONB | SMA inverter register values as JSON (all keys averaged) |
| `created_at` | TIMESTAMPTZ | Auto-set on row creation |
| `updated_at` | TIMESTAMPTZ | Auto-set on row creation; updated on upsert |

Timestamps are validated with a CHECK constraint:
```sql
date_trunc('minute', ts) = ts AND date_part('minute', ts) IN (0, 15, 30, 45)
```

### CronJob configuration

**Resource**: CronJob `moxa-weather-15min-import` in namespace `weather`

**Schedule**: `0,15,30,45 * * * *` (every 15 minutes at exact boundaries)

**Behavior**:
1. Calls moxa.local's `/api/avg.php?n=900&cols=<weather-parameters>` endpoint to retrieve 15-minute averages
2. Aligns timestamps to current 15-minute boundary (00, 15, 30, or 45 minutes)
3. Upserts rows into `moxa_weather_15min` table (INSERT ... ON CONFLICT DO UPDATE)
4. Completes in ~2–5 seconds

**Environment variables** (from secrets):
- `PGHOST`, `PGDATABASE`, `PGUSER`, `PGPASSWORD`: PostgreSQL connection (from secret `weather-db`)
- `MOXA_API_URL`: Base URL of moxa.local (default: `http://moxa.local`)

### Integration with fusion view

The `weather_fusion` view now combines moxa instant weather data with FMI forecasts and ENTSO-E prices:

**FMI forecast columns** (prefixed with `fc_` to distinguish from moxa instant readings):
- `fc_temperature_c` — Interpolated FMI forecast temperature
- `fc_wind_speed_ms` — Interpolated FMI forecast wind speed
- `fc_wind_direction_deg` — Interpolated FMI forecast wind direction
- `fc_cloud_cover_pct` — Interpolated FMI forecast cloud cover
- `fc_shortwave_radiation_w_m2` — Interpolated FMI forecast radiation

**Moxa instant weather columns** (no prefix, at 15-minute boundaries):
- Temperature, dew point, humidity, pressure, wind speed, wind direction, precipitation
- PV energy, feed-in power, battery state, active power, charge/discharge power
- SMA inverter register values (as JSON)

Moxa data is aligned to the same 15-minute timestamps as ENTSO-E price points, allowing seamless time-series analysis of prices, forecasts, and actual weather conditions.

### Verification queries

Check table size:

```bash
kubectl -n weather exec statefulset/weather-postgres -- psql -U weather -d weather -c \
  "SELECT COUNT(*) as rows, MIN(ts) as earliest, MAX(ts) as latest FROM moxa_weather_15min;"
```

View recent 15-minute averages:

```bash
kubectl -n weather exec statefulset/weather-postgres -- psql -U weather -d weather -c \
  "SELECT ts AT TIME ZONE 'Europe/Helsinki' as local_time, temperature_c, wind_speed_ms, battery_soc_pct FROM moxa_weather_15min \
   ORDER BY ts DESC LIMIT 10;"
```

Check fusion view includes moxa data:

```bash
kubectl -n weather exec statefulset/weather-postgres -- psql -U weather -d weather -c \
  "SELECT ts, price_eur_per_mwh, fc_temperature_c, temperature_c, battery_soc_pct FROM weather_fusion \
   ORDER BY ts DESC LIMIT 5;"
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

## Weather fusion Parquet export

The weather stack exports the `weather_fusion` view to timestamped Parquet files for analysis and automated control scripts.

### Overview

- **Data source**: PostgreSQL `weather_fusion` view (combining ENTSO-E prices, FMI forecasts, and moxa 15-minute measurements)
- **Format**: Apache Parquet (columnar binary format)
- **Frequency**: Every 15 minutes (synchronized with `weather_fusion` refresh)
- **Storage**: `/media/ssd250/weather/exports/` on radxa-a.local host
- **Retention**: Latest 30 files

### CronJob configuration

**Resource**: CronJob `export-fusion-parquet` in namespace `weather`

**Schedule**: `*/15 * * * *` (every 15 minutes)

**Behavior**:
1. Queries entire `weather_fusion` view via `psql`
2. Writes to timestamped file: `weather_fusion_YYYYMMDD_HHMMSS.parquet`
3. Creates/updates `latest.parquet` symlink pointing to newest file
4. Prunes old exports, keeping latest 30 files

**Environment variables** (from secrets):
- `PGHOST`, `PGDATABASE`, `PGUSER`, `PGPASSWORD`: PostgreSQL connection (from secret `weather-db`)
- `OUT_DIR`: Output directory (default: `/var/exports`, mapped to `/media/ssd250/weather/exports`)
- `RETAIN_COUNT`: Number of files to retain (default: `30`)

### File naming and symlink

Files are named with UTC timestamp for unambiguous sorting:
```
weather_fusion_20260218_204423.parquet   # Feb 18, 2026 20:44:23 UTC
weather_fusion_20260218_210006.parquet   # Feb 18, 2026 21:00:06 UTC
latest.parquet -> weather_fusion_20260218_210006.parquet
```

The `latest.parquet` symlink always points to the most recent export.

### Reading in Python

```python
import pandas as pd
from pathlib import Path
from datetime import datetime, timedelta, timezone

# Read the latest export
df = pd.read_parquet("/media/ssd250/weather/exports/latest.parquet")

# Validate freshness before using for control decisions
export_dir = Path("/media/ssd250/weather/exports")
latest_file = export_dir / "latest.parquet"

if latest_file.is_symlink():
    target = latest_file.resolve()
    # Extract timestamp from filename: weather_fusion_20260218_204423.parquet
    timestamp_str = target.stem.split("_", 2)[2]  # "20260218_204423"
    file_time = datetime.strptime(timestamp_str, "%Y%m%d_%H%M%S").replace(tzinfo=timezone.utc)
    
    if datetime.now(timezone.utc) - file_time > timedelta(minutes=30):
        raise ValueError("Export data is stale, refusing to act on old data")

# Safe to proceed with fresh data
print(df.columns.tolist())
print(df.head())
```

### Verification queries

Check export directory:

```bash
ls -lh /media/ssd250/weather/exports/
```

Count retained files:

```bash
ls -1 /media/ssd250/weather/exports/weather_fusion_*.parquet | wc -l
```

View recent CronJob runs:

```bash
kubectl -n weather get jobs | grep export-fusion-parquet
```

### Use case: heat pump control

The Parquet export enables automated control of the geothermal heat pump based on electricity prices and weather conditions:

1. Analysis script reads `latest.parquet`
2. Validates file timestamp is current (within 30 minutes)
3. Applies control logic (e.g., disable heat pump when price > threshold and outdoor temp permits)
4. Writes decision (`ALLOW` or `PROHIBIT`) with matching `ts` timestamps
5. Publishes control commands to MQTT broker (`mqtt.weather.svc.cluster.local:1883`)

The 15-minute granularity aligns with ENTSO-E price intervals, allowing precise cost optimization.
