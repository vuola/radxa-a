import os
import sys
import psycopg2

print("Starting FMI radiation backfill (J/m² → W/m²)", file=sys.stderr, flush=True)

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

# Check current state before backfill
cur.execute("""
    SELECT 
        COUNT(*) as rows_affected,
        MIN(shortwave_radiation_w_m2) as min_before,
        MAX(shortwave_radiation_w_m2) as max_before,
        AVG(shortwave_radiation_w_m2) as avg_before
    FROM fmi_forecast 
    WHERE shortwave_radiation_w_m2 IS NOT NULL;
""")
before = cur.fetchone()
print(f"Before backfill: {before[0]} rows, min={before[1]:.2f}, max={before[2]:.2f}, avg={before[3]:.2f}", file=sys.stderr, flush=True)

# Only backfill if values look like J/m² (e.g., max > 10000 indicates unconverted data)
if before[2] and before[2] > 10000:
    print("Detected unconverted J/m² values (max > 10000), applying backfill...", file=sys.stderr, flush=True)
    
    # Convert J/m² to W/m² by dividing by 3600
    cur.execute("""
        UPDATE fmi_forecast 
        SET shortwave_radiation_w_m2 = shortwave_radiation_w_m2 / 3600.0,
            updated_at = now()
        WHERE shortwave_radiation_w_m2 IS NOT NULL;
    """)
    
    # Check after backfill
    cur.execute("""
        SELECT 
            COUNT(*) as rows_affected,
            MIN(shortwave_radiation_w_m2) as min_after,
            MAX(shortwave_radiation_w_m2) as max_after,
            AVG(shortwave_radiation_w_m2) as avg_after
        FROM fmi_forecast 
        WHERE shortwave_radiation_w_m2 IS NOT NULL;
    """)
    after = cur.fetchone()
    print(f"After backfill: {after[0]} rows, min={after[1]:.2f}, max={after[2]:.2f}, avg={after[3]:.2f}", file=sys.stderr, flush=True)
    print(f"Successfully backfilled {after[0]} rows", file=sys.stderr, flush=True)
else:
    print("Values already look correct (max <= 10000 W/m²), skipping backfill", file=sys.stderr, flush=True)

cur.close()
pg_conn.close()
print("FMI radiation backfill complete", file=sys.stderr, flush=True)
