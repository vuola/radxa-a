import json
import os
import shutil
import sqlite3

import psycopg2
from psycopg2.extras import execute_batch, Json

inbox = os.environ.get("INBOX_DIR", "/var/inbox")
processed_dir = os.path.join(inbox, "processed")
os.makedirs(processed_dir, exist_ok=True)

pg_conn = psycopg2.connect(
    host=os.environ["PGHOST"],
    dbname=os.environ["PGDATABASE"],
    user=os.environ["PGUSER"],
    password=os.environ["PGPASSWORD"],
)
pg_conn.autocommit = True
cur = pg_conn.cursor()
cur.execute(
    """
    CREATE TABLE IF NOT EXISTS weather (
      id BIGSERIAL PRIMARY KEY,
      ts TIMESTAMPTZ,
      temperature_c DOUBLE PRECISION,
      dew_point_c DOUBLE PRECISION,
      relative_humidity DOUBLE PRECISION,
      pressure_hpa DOUBLE PRECISION,
      wind_speed_ms DOUBLE PRECISION,
      wind_direction_deg DOUBLE PRECISION,
      precip_mmph DOUBLE PRECISION,
      energy_today_wh BIGINT,
      pv_feed_in_w INTEGER,
      battery_soc_pct INTEGER,
      active_power_pcc_w INTEGER,
      bat_charge_w INTEGER,
      bat_discharge_w INTEGER,
      sma_json JSONB,
      merged_at TIMESTAMPTZ,
      pushed_at TIMESTAMPTZ
    );
    CREATE UNIQUE INDEX IF NOT EXISTS weather_ts_unique ON weather (ts);
    """
)

insert_sql = """
INSERT INTO weather (
  ts, temperature_c, dew_point_c, relative_humidity, pressure_hpa,
  wind_speed_ms, wind_direction_deg, precip_mmph,
  energy_today_wh, pv_feed_in_w, battery_soc_pct, active_power_pcc_w,
  bat_charge_w, bat_discharge_w, sma_json, merged_at, pushed_at
) VALUES (
  %(ts)s, %(temperature_c)s, %(dew_point_c)s, %(relative_humidity)s, %(pressure_hpa)s,
  %(wind_speed_ms)s, %(wind_direction_deg)s, %(precip_mmph)s,
  %(energy_today_wh)s, %(pv_feed_in_w)s, %(battery_soc_pct)s, %(active_power_pcc_w)s,
  %(bat_charge_w)s, %(bat_discharge_w)s, %(sma_json)s, %(merged_at)s, %(pushed_at)s
)
ON CONFLICT (ts) DO NOTHING;
"""

files = sorted(
    f for f in os.listdir(inbox)
    if f.endswith(".db") and os.path.isfile(os.path.join(inbox, f))
)
for fname in files:
    fpath = os.path.join(inbox, fname)
    conn = sqlite3.connect(f"file:{fpath}?immutable=1", uri=True)
    try:
        scur = conn.cursor()
        tables = scur.execute(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='weather'"
        ).fetchall()
        if not tables:
            continue
        scur.execute(
            "SELECT ts, temperature_c, dew_point_c, relative_humidity, pressure_hpa, "
            "wind_speed_ms, wind_direction_deg, precip_mmph, energy_today_wh, pv_feed_in_w, "
            "battery_soc_pct, active_power_pcc_w, bat_charge_w, bat_discharge_w, sma_json, merged_at, pushed_at "
            "FROM weather"
        )
        rows = []
        for row in scur:
            rows.append({
                "ts": row[0],
                "temperature_c": row[1],
                "dew_point_c": row[2],
                "relative_humidity": row[3],
                "pressure_hpa": row[4],
                "wind_speed_ms": row[5],
                "wind_direction_deg": row[6],
                "precip_mmph": row[7],
                "energy_today_wh": row[8],
                "pv_feed_in_w": row[9],
                "battery_soc_pct": row[10],
                "active_power_pcc_w": row[11],
                "bat_charge_w": row[12],
                "bat_discharge_w": row[13],
                "sma_json": Json(json.loads(row[14])) if row[14] else None,
                "merged_at": row[15],
                "pushed_at": row[16],
            })
        if rows:
            execute_batch(cur, insert_sql, rows, page_size=500)
    finally:
        conn.close()
    shutil.move(fpath, os.path.join(processed_dir, fname))
cur.close()
pg_conn.close()
