# Resume notes — Local Moxa weather station

Quick pointers
- `read` binary and source: `read` (workspace root), `read.cpp`
- `readip` binary and source: `readip` (workspace root), `readip.cpp`
- Web UI: `/var/www/html/index.html`, `/var/www/html/data/weather.json`, `/var/www/html/data/sma.json`
- Download CSV endpoint: `/download_csv.php` (exports the SQLite DB to CSV)
- Systemd units (repo): `/home/vuola/moxa/systemd/weather.service`, `/home/vuola/moxa/systemd/moxa-serial.service`, `/home/vuola/moxa/systemd/moxa-sma-json.service`, `/home/vuola/moxa/systemd/moxa-insert.service`, `/home/vuola/moxa/systemd/moxa-insert.timer`
- Installed systemd units: `/etc/systemd/system/weather.service`, `/etc/systemd/system/moxa-serial.service`, `/etc/systemd/system/moxa-sma-json.service`, `/etc/systemd/system/moxa-insert.service`, `/etc/systemd/system/moxa-insert.timer`
- Serial setup log: `/home/vuola/moxa/moxa-serial.log`

Quick checks to run after returning
```
sudo systemctl status weather.service moxa-serial.service --no-pager
sudo journalctl -u weather.service -n 200 --no-pager
tail -n 200 /home/vuola/moxa/moxa-serial.log
curl -sS http://localhost/data/weather.json
sudo systemctl status moxa-sma-json.service moxa-insert.service moxa-insert.timer --no-pager
sudo journalctl -u moxa-insert.service -n 200 --no-pager
```

Check the SQLite database and recent inserts
```
# use the system DB location
ls -l /var/lib/moxa/weather.db
sqlite3 -header -column /var/lib/moxa/weather.db ".tables"
sqlite3 -header -column /var/lib/moxa/weather.db "PRAGMA table_info(weather);"
sqlite3 -header -column /var/lib/moxa/weather.db "SELECT COUNT(*) AS rows FROM weather;"
sqlite3 -header -column /var/lib/moxa/weather.db "SELECT id, ts, temperature_c, pv_feed_in_w, merged_at FROM weather ORDER BY id DESC LIMIT 10;"
# Quick Python alternative if `sqlite3` isn't installed:
python3 - <<'PY'
import sqlite3
db='/var/lib/moxa/weather.db'
try:
  c=sqlite3.connect(db).cursor()
  print('rows:', c.execute("SELECT COUNT(*) FROM weather").fetchone()[0])
  print('last:', c.execute("SELECT id,ts FROM weather ORDER BY id DESC LIMIT 1").fetchone())
except Exception as e:
  print('DB check failed:', e)
PY
```

Apply changes to a systemd unit (after editing a `*.service` file)

If you edit a unit file under `/etc/systemd/system` (or `/home/vuola/moxa/systemd` and then copy it into `/etc/systemd/system`), run:
```
sudo systemctl daemon-reload
sudo systemctl restart moxa-sma-json.service    # or the unit you changed
sudo systemctl status moxa-sma-json.service --no-pager
sudo journalctl -u moxa-sma-json.service -n 200 --no-pager
```
If you changed the unit file but do not want to restart the service immediately, use `systemctl daemon-reload` to make systemd aware of the change; subsequent `restart`/`reload` will use the new definition.

Rebuild and deploy `moxa-readip` (SMA reader)
```
g++ -O2 -std=c++17 /home/vuola/moxa/readip.cpp -o /home/vuola/moxa/readip -lmodbus
sudo systemctl stop moxa-sma-json.service
sudo cp /home/vuola/moxa/readip /usr/local/bin/moxa-readip
sudo systemctl start moxa-sma-json.service
sudo journalctl -u moxa-sma-json.service -n 50 --no-pager
```


Create a local SQLite database (recommended) — commands

1. Create DB and table:
```
sqlite3 /var/lib/moxa/weather.db <<'SQL'
PRAGMA journal_mode=WAL;
CREATE TABLE IF NOT EXISTS weather (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ts TEXT DEFAULT (datetime('now')),
  temperature_c REAL,
  dew_point_c REAL,
  relative_humidity REAL,
  pressure_hpa REAL,
  wind_speed_ms REAL,
  wind_direction_deg REAL,
  precip_mmph REAL,
  -- SMA typed columns (match register sizes):
  energy_today_wh INTEGER,    -- register 30517 (U64 -> store as INTEGER)
  pv_feed_in_w INTEGER,       -- register 30775 (S32)
  battery_soc_pct INTEGER,    -- register 30845 (U32)
  active_power_pcc_w INTEGER, -- register 31249 (S32)
  bat_charge_w INTEGER,       -- register 31393 (U32)
  bat_discharge_w INTEGER,    -- register 31395 (U32)
  -- store the SMA JSON blob (addresses as keys only) for provenance
  sma_json TEXT,
  merged_at TEXT, -- ISO8601 UTC timestamp when weather and SMA were merged
  pushed_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_weather_ts ON weather(ts);
SQL
```

2. Insert latest `/var/www/html/data/weather.json` and `/var/www/html/data/sma.json` into SQLite (non-redundant) — Python example (`insert_json_to_db.py`):
```python
#!/usr/bin/env python3
import json, sqlite3, os
from datetime import datetime

p_weather = '/var/www/html/data/weather.json'
p_sma = '/var/www/html/data/sma.json'
db = '/var/lib/moxa/weather.db'

# Read weather (parsed fields) and SMA (blob) separately to avoid duplication
with open(p_weather,'r') as f:
  j_weather = json.load(f)

with open(p_sma,'r') as f:
  j_sma = json.load(f)

merged_at = datetime.utcnow().isoformat() + 'Z'

conn = sqlite3.connect(db)
cur = conn.cursor()
cur.execute('''
INSERT INTO weather (
  ts, temperature_c, dew_point_c, relative_humidity, pressure_hpa,
  wind_speed_ms, wind_direction_deg, precip_mmph,
  energy_today_wh, pv_feed_in_w, battery_soc_pct, active_power_pcc_w, bat_charge_w, bat_discharge_w,
  sma_json, merged_at
)
VALUES (datetime('now'),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
''', (
  j_weather.get('temperature_c'), j_weather.get('dew_point_c'), j_weather.get('relative_humidity'), j_weather.get('pressure_hpa'),
  j_weather.get('wind_speed_ms'), j_weather.get('wind_direction_deg'), j_weather.get('precip_mmph'),
  # SMA typed columns: extract by register number (fall back to 0)
  (int(j_sma.get('30517')) if '30517' in j_sma else 0),
  (int(j_sma.get('30775')) if '30775' in j_sma else 0),
  (int(j_sma.get('30845')) if '30845' in j_sma else 0),
  (int(j_sma.get('31249')) if '31249' in j_sma else 0),
  (int(j_sma.get('31393')) if '31393' in j_sma else 0),
  (int(j_sma.get('31395')) if '31395' in j_sma else 0),
  # sma_json: keep only numeric register keys (no human-readable descriptions)
  json.dumps({k: v for k, v in j_sma.items() if k.isdigit()}), merged_at
))
conn.commit()
conn.close()
```

Rationale: the table stores parsed weather columns (for querying/indexing) and stores the full SMA JSON blob in `sma_json`. This avoids storing the same numeric weather fields twice (as columns and inside a raw JSON column). If you later need the full original weather JSON, you can either keep the filesystem `weather.json` files or add a compact `weather_json` column that excludes duplicated fields.

Make the script executable and run it from cron/systemd or call it from `read` (if you modify `read`):
```
chmod +x insert_json_to_db.py
./insert_json_to_db.py
```

Push-worker design (high level)
- A small script reads rows with `pushed_at IS NULL` and POSTs JSON to your hosted endpoint `https://www.vuolahti.com/api/receive.php` with `Authorization: Bearer <TOKEN>`.
- On HTTP 2xx mark `pushed_at = datetime('now')` for those rows. Use batching and retries.
- Run the worker via `systemd` timer (1min–5min) or as a persistent process with exponential backoff.

Next steps (suggested)
- If you want, I can generate `insert_json_to_db.py`, a `moxa-push.service` and `moxa-push.timer` plus a sample `receive.php` and SQL for the hosted DB.

Changes made in this repo (2026-01-28)
- Database moved from `/home/vuola/moxa/weather.db` -> `/var/lib/moxa/weather.db` (backup created at `/var/lib/moxa/weather.db.bak`).
- `insert_json_to_db.py` updated to use `/var/lib/moxa/weather.db`.
- New export endpoint added: `/var/www/html/download_csv.php` (also deployed to `/var/www/html`). This endpoint returns CSV (first column `ts`) for an optional `start`/`end` date range (YYYY-MM-DD). If both dates are omitted the full DB is returned.
- `index.html` appended with a bottom-right "Download CSV" button that opens a date-picker modal and submits to `/download_csv.php`.

Deployment & permissions notes
- Recommended DB location: `/var/lib/moxa` (system data dir). Keep DB owner/group and permissions restrictive.
- Example commands used to secure the DB (run as root):
```bash
mkdir -p /var/lib/moxa
cp /home/vuola/moxa/weather.db /var/lib/moxa/weather.db.bak
mv /home/vuola/moxa/weather.db /var/lib/moxa/weather.db
# allow the writer user (here `vuola`) and webserver group to access
chown vuola:www-data /var/lib/moxa/weather.db
chmod 0660 /var/lib/moxa/weather.db
# allow WAL files to be created in the directory
chown vuola:www-data /var/lib/moxa
chmod 2775 /var/lib/moxa
```

Webserver
- Ensure the webserver user (e.g. `www-data`) can read `/var/lib/moxa/weather.db`.
- If you deployed the updated `/download_csv.php` to `/var/www/html`, reload Apache/Nginx after installing any missing PHP modules (e.g. `php-sqlite3`).

Quick test commands (no GUI)
```bash
# Full CSV download
curl -OJ 'http://moxa.local/download_csv.php'

# Date filtered (example)
curl -OJ 'http://moxa.local/download_csv.php?start=2024-01-01&end=2024-12-31'

# Check DB
sqlite3 -header -column /var/lib/moxa/weather.db 'SELECT COUNT(*) FROM weather;'
```
---
Last edited: 2026-02-08

Repo copy of server files
-------------------------
- The live web root `/var/www/html/` is mirrored in this repo under `./html/`.
- When deploying, copy **contents** of `./html/` into `/var/www/html/` (no nesting):

```bash
sudo cp -a /home/vuola/moxa/html/. /var/www/html/
```

Safe deploy script (recommended)
-------------------------------
Use the repo script to deploy the web UI without touching `/var/www/html/data`:

```bash
/home/vuola/moxa/scripts/deploy-web.sh
```

Notes:
- The script syncs `./html/` to `/var/www/html/` and **excludes** the `data/` directory.
- It enforces `root:www-data` ownership and `2775` perms on `/var/www/html` and `/var/www/html/data`.
- Existing JSON files under `/var/www/html/data` are preserved (ownership remains with the writer user).
- It runs a quick post-deploy check (service active + sample `weather.json` read).
---
Last edited: 2026-01-25

Deployment — critical files & ownership
-------------------------------------

This system uses systemd services that must run under the `www-data` user so they can write JSON files the webserver serves and access serial devices (via `dialout`). The minimal set of locations, ownership and recommended settings to recreate the system are:

- Services (installed):
  - `weather.service` — runs `/usr/local/bin/moxa-read` as `User=www-data` and writes `/var/www/html/data/weather.json`.
  - `moxa-sma-json.service` — runs `/usr/local/bin/moxa-readip` as `User=www-data` and writes `/var/www/html/data/sma.json`.
  - `moxa-insert.service` + `moxa-insert.timer` — oneshot inserter triggered by timer; runs `/usr/local/bin/moxa-insert.py` as `User=www-data` and writes to `/var/lib/moxa/weather.db`.

- Binaries (system-wide):
  - Copy the repo executables into `/usr/local/bin` and make them executable, e.g. `cp read /usr/local/bin/moxa-read && chmod 755 /usr/local/bin/moxa-read`.

- Data directories and files (recommended ownership & perms):
  - `/var/www/html/data` — `chown root:www-data` and `chmod 2775` (setgid so new files inherit `www-data` group).
  - JSON files `/var/www/html/data/*.json` — `chown www-data:www-data` and `chmod 0644` (service writes, webserver reads).
  - `/var/lib/moxa` — `chown vuola:www-data` and `chmod 2775` (DB directory; allows WAL files by the owner and group).
  - `/var/lib/moxa/weather.db` — `chown vuola:www-data` and `chmod 0660` (restrict DB to writer + webserver group).

- Groups & device access:
  - Add `www-data` to `dialout` so services running as `www-data` may access serial devices: `sudo usermod -aG dialout www-data`.

- Systemd notes to recreate units:
  - Place canonical unit files under `/etc/systemd/system/` (e.g. `/etc/systemd/system/weather.service`).
  - If you need to run a unit as `www-data`, either include `User=www-data` in the unit or create a drop-in at `/etc/systemd/system/<unit>.service.d/override.conf` with the `User=` line.
  - After changes: `sudo systemctl daemon-reload && sudo systemctl restart <unit>`.

Quick recreate checklist
1. Copy binaries to `/usr/local/bin` and set `root:root 755`.
2. Ensure `/var/www/html/data` exists and is `root:www-data` with mode `2775`.
3. Ensure `/var/lib/moxa` exists and is `vuola:www-data` with mode `2775`.
4. Install unit files to `/etc/systemd/system/` and set `User=www-data` for the reader/inserter services.
5. Add `www-data` to `dialout` and reload systemd.


REST API — averaged weather row
-------------------------------

Endpoint (Apache/PHP):
- `/api/avg.php`

Query parameters:
- `n` (required): number of newest 1‑minute rows used for averaging.
- `cols` (required): comma‑separated column names to return.

Wind averaging:
- If `wind_speed_ms` or `wind_direction_deg` is requested, vector averaging is used:
  - convert to (x,y), average x/y, then convert back to (WS, WD).

SMA averaging:
- If `sma_json` is requested, each key in the JSON is averaged separately and returned in the same JSON shape.

Allowed columns:
- `temperature_c`, `dew_point_c`, `relative_humidity`, `pressure_hpa`,
  `wind_speed_ms`, `wind_direction_deg`, `precip_mmph`,
  `energy_today_wh`, `pv_feed_in_w`, `battery_soc_pct`, `active_power_pcc_w`,
  `bat_charge_w`, `bat_discharge_w`, `sma_json`

Example:
```
curl -s 'http://moxa.local/api/avg.php?n=60&cols=temperature_c,pressure_hpa,wind_speed_ms,wind_direction_deg,sma_json'
```

Response (example):
```json
{
  "n": 60,
  "rows_used": 60,
  "data": {
    "temperature_c": 2.31,
    "pressure_hpa": 1009.8,
    "wind_speed_ms": 3.4,
    "wind_direction_deg": 278.2,
    "sma_json": {
      "30517": 1234.0,
      "30775": 456.0
    }
  }
}
```