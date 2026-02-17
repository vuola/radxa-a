import os
import sys
import requests
import json
import psycopg2
from psycopg2.extras import execute_batch, Json
from datetime import datetime, timezone
from zoneinfo import ZoneInfo

print("Starting Moxa weather 15-minute import", file=sys.stderr, flush=True)

moxa_api_url = os.environ.get("MOXA_API_URL", "http://moxa.local")
n_minutes = 900  # 15 minutes in seconds

# All weather columns to retrieve from moxa.local
weather_cols = [
    "temperature_c", "dew_point_c", "relative_humidity", "pressure_hpa",
    "wind_speed_ms", "wind_direction_deg", "precip_mmph",
    "energy_today_wh", "pv_feed_in_w", "battery_soc_pct", "active_power_pcc_w",
    "bat_charge_w", "bat_discharge_w", "sma_json"
]

cols_param = ",".join(weather_cols)
avg_url = f"{moxa_api_url}/api/avg.php?n={n_minutes}&cols={cols_param}"

try:
    resp = requests.get(avg_url, timeout=30)
    resp.raise_for_status()
    data = resp.json()
except Exception as e:
    print(f"Error fetching moxa weather data: {e}", file=sys.stderr, flush=True)
    sys.exit(1)

if "data" not in data:
    print(f"Invalid response structure: {data}", file=sys.stderr, flush=True)
    sys.exit(1)

weather_data = data.get("data", {})

# Determine the timestamp: align to current 15-minute boundary (00, 15, 30, 45)
now_utc = datetime.now(timezone.utc)
minute = now_utc.minute
aligned_minute = (minute // 15) * 15
ts_aligned = now_utc.replace(minute=aligned_minute, second=0, microsecond=0)

print(f"Retrieved {data.get('rows_used', 0)} rows; using timestamp {ts_aligned.isoformat()}", file=sys.stderr, flush=True)

try:
    pg_conn = psycopg2.connect(
        host=os.environ["PGHOST"],
        dbname=os.environ["PGDATABASE"],
        user=os.environ["PGUSER"],
        password=os.environ["PGPASSWORD"],
    )
    pg_conn.autocommit = True
    cur = pg_conn.cursor()
except Exception as e:
    print(f"DB connection failed: {e}", file=sys.stderr, flush=True)
    sys.exit(1)

# Create table if it doesn't exist
cur.execute(
    """
    CREATE TABLE IF NOT EXISTS moxa_weather_15min (
      ts TIMESTAMPTZ PRIMARY KEY,
      temperature_c DOUBLE PRECISION NULL,
      dew_point_c DOUBLE PRECISION NULL,
      relative_humidity DOUBLE PRECISION NULL,
      pressure_hpa DOUBLE PRECISION NULL,
      wind_speed_ms DOUBLE PRECISION NULL,
      wind_direction_deg DOUBLE PRECISION NULL,
      precip_mmph DOUBLE PRECISION NULL,
      energy_today_wh BIGINT NULL,
      pv_feed_in_w INTEGER NULL,
      battery_soc_pct INTEGER NULL,
      active_power_pcc_w INTEGER NULL,
      bat_charge_w INTEGER NULL,
      bat_discharge_w INTEGER NULL,
      sma_json JSONB NULL,
      created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
      updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
      CONSTRAINT moxa_weather_15min_ts_alignment CHECK (
        date_trunc('minute', ts) = ts AND date_part('minute', ts) IN (0, 15, 30, 45)
      )
    );
    CREATE UNIQUE INDEX IF NOT EXISTS moxa_weather_15min_ts_idx ON moxa_weather_15min (ts);
    """
)

# Prepare the insert/update statement
insert_sql = """
INSERT INTO moxa_weather_15min (
  ts, temperature_c, dew_point_c, relative_humidity, pressure_hpa,
  wind_speed_ms, wind_direction_deg, precip_mmph,
  energy_today_wh, pv_feed_in_w, battery_soc_pct, active_power_pcc_w,
  bat_charge_w, bat_discharge_w, sma_json
) VALUES (
  %(ts)s, %(temperature_c)s, %(dew_point_c)s, %(relative_humidity)s, %(pressure_hpa)s,
  %(wind_speed_ms)s, %(wind_direction_deg)s, %(precip_mmph)s,
  %(energy_today_wh)s, %(pv_feed_in_w)s, %(battery_soc_pct)s, %(active_power_pcc_w)s,
  %(bat_charge_w)s, %(bat_discharge_w)s, %(sma_json)s
)
ON CONFLICT (ts) DO UPDATE SET
  temperature_c = EXCLUDED.temperature_c,
  dew_point_c = EXCLUDED.dew_point_c,
  relative_humidity = EXCLUDED.relative_humidity,
  pressure_hpa = EXCLUDED.pressure_hpa,
  wind_speed_ms = EXCLUDED.wind_speed_ms,
  wind_direction_deg = EXCLUDED.wind_direction_deg,
  precip_mmph = EXCLUDED.precip_mmph,
  energy_today_wh = EXCLUDED.energy_today_wh,
  pv_feed_in_w = EXCLUDED.pv_feed_in_w,
  battery_soc_pct = EXCLUDED.battery_soc_pct,
  active_power_pcc_w = EXCLUDED.active_power_pcc_w,
  bat_charge_w = EXCLUDED.bat_charge_w,
  bat_discharge_w = EXCLUDED.bat_discharge_w,
  sma_json = EXCLUDED.sma_json,
  updated_at = now();
"""

# Build the row dict
row = {
    "ts": ts_aligned,
    "temperature_c": weather_data.get("temperature_c"),
    "dew_point_c": weather_data.get("dew_point_c"),
    "relative_humidity": weather_data.get("relative_humidity"),
    "pressure_hpa": weather_data.get("pressure_hpa"),
    "wind_speed_ms": weather_data.get("wind_speed_ms"),
    "wind_direction_deg": weather_data.get("wind_direction_deg"),
    "precip_mmph": weather_data.get("precip_mmph"),
    "energy_today_wh": weather_data.get("energy_today_wh"),
    "pv_feed_in_w": weather_data.get("pv_feed_in_w"),
    "battery_soc_pct": weather_data.get("battery_soc_pct"),
    "active_power_pcc_w": weather_data.get("active_power_pcc_w"),
    "bat_charge_w": weather_data.get("bat_charge_w"),
    "bat_discharge_w": weather_data.get("bat_discharge_w"),
    "sma_json": Json(weather_data.get("sma_json")) if weather_data.get("sma_json") else None,
}

try:
    cur.execute(insert_sql, row)
    print(f"Inserted/updated row for ts={ts_aligned.isoformat()}", file=sys.stderr, flush=True)
except Exception as e:
    print(f"Insert failed: {e}", file=sys.stderr, flush=True)
    sys.exit(1)

pg_conn.close()
print("Moxa weather import completed successfully", file=sys.stderr, flush=True)
