import os
import sys
import xml.etree.ElementTree as ET
from datetime import datetime, timedelta, timezone, time
from zoneinfo import ZoneInfo

import psycopg2
from psycopg2.extras import execute_batch
import requests

print("Starting ENTSO-E import script", file=sys.stderr, flush=True)

api_key = os.environ["ENTSOE_API_KEY"]
in_domain = os.environ.get("ENTSOE_IN_DOMAIN", "10YFI-1--------U")
out_domain = os.environ.get("ENTSOE_OUT_DOMAIN", "10YFI-1--------U")
market_agreement = os.environ.get("ENTSOE_MARKET_AGREEMENT", "A01")
local_tz = ZoneInfo(os.environ.get("ENTSOE_TZ", "Europe/Helsinki"))

now_local = datetime.now(local_tz)
target_day = (now_local + timedelta(days=1)).date()
start_local = datetime.combine(target_day, time(0, 0), tzinfo=local_tz)
end_local = start_local + timedelta(days=1)
start_utc = start_local.astimezone(timezone.utc)
end_utc = end_local.astimezone(timezone.utc)

def fmt(dt: datetime) -> str:
    return dt.strftime("%Y%m%d%H%M")

params = {
    "securityToken": api_key,
    "documentType": "A44",
    "in_Domain": in_domain,
    "out_Domain": out_domain,
    "contract_MarketAgreement.type": market_agreement,
    "periodStart": fmt(start_utc),
    "periodEnd": fmt(end_utc),
}

resp = requests.get("https://web-api.tp.entsoe.eu/api", params=params, timeout=60)
resp.raise_for_status()

root = ET.fromstring(resp.content)

def parse_resolution(res_text: str) -> timedelta:
    if res_text == "PT15M":
        return timedelta(minutes=15)
    if res_text == "PT30M":
        return timedelta(minutes=30)
    if res_text == "PT60M":
        return timedelta(minutes=60)
    raise ValueError(f"Unsupported resolution: {res_text}")

price_by_ts = {}
for ts_node in root.findall(".//{*}TimeSeries"):
    for period in ts_node.findall(".//{*}Period"):
        start_text = period.findtext("{*}timeInterval/{*}start")
        res_text = period.findtext("{*}resolution")
        if not start_text or not res_text:
            continue
        if start_text.endswith("Z"):
            start_text = start_text.replace("Z", "+00:00")
        period_start = datetime.fromisoformat(start_text)
        resolution = parse_resolution(res_text)
        for point in period.findall("{*}Point"):
            pos_text = point.findtext("{*}position")
            price_text = point.findtext("{*}price.amount")
            if not pos_text or price_text is None:
                continue
            pos = int(pos_text)
            ts = period_start + (pos - 1) * resolution
            ts_utc = ts.astimezone(timezone.utc)
            price_by_ts[ts_utc] = float(price_text)

if not price_by_ts:
    raise SystemExit("No price rows returned by ENTSO-E")

rows = []
ts = start_utc
last_price = None
while ts < end_utc:
    price = price_by_ts.get(ts)
    if price is None:
        price = last_price
    else:
        last_price = price
    rows.append((ts.isoformat(), price))
    ts += timedelta(minutes=15)

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
    CREATE TABLE IF NOT EXISTS entsoe_prices (
      ts TIMESTAMPTZ PRIMARY KEY,
      price_eur_per_mwh DOUBLE PRECISION NULL,
      created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
      updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
      CONSTRAINT entsoe_prices_ts_15m CHECK (
        date_trunc('minute', ts) = ts
        AND date_part('minute', ts) IN (0, 15, 30, 45)
      )
    );
    """
)
insert_sql = """
INSERT INTO entsoe_prices (ts, price_eur_per_mwh)
VALUES (%s, %s)
ON CONFLICT (ts) DO UPDATE
  SET price_eur_per_mwh = EXCLUDED.price_eur_per_mwh,
      updated_at = now();
"""
execute_batch(cur, insert_sql, rows, page_size=500)
print(f"Successfully imported {len(rows)} price rows", file=sys.stderr, flush=True)
cur.close()
pg_conn.close()
print("ENTSO-E import complete", file=sys.stderr, flush=True)
