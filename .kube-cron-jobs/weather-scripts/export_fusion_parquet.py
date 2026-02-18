#!/usr/bin/env python3
import os
import shutil
from datetime import datetime, timezone
from pathlib import Path

import pandas as pd
import psycopg2


def read_env(name: str, default: str) -> str:
    value = os.environ.get(name)
    return value if value is not None and value != "" else default


def main() -> int:
    out_dir = Path(read_env("OUT_DIR", "/var/exports"))
    retain = int(read_env("RETAIN_COUNT", "30"))
    out_dir.mkdir(parents=True, exist_ok=True)

    ts = datetime.now(timezone.utc).strftime("%Y%m%d_%H%M%S")
    tmp_path = out_dir / f".weather_fusion_{ts}.parquet.tmp"
    final_path = out_dir / f"weather_fusion_{ts}.parquet"

    conn = psycopg2.connect(
        host=read_env("PGHOST", "weather-postgres"),
        dbname=read_env("PGDATABASE", "weather"),
        user=read_env("PGUSER", "weather"),
        password=read_env("PGPASSWORD", ""),
    )

    try:
        df = pd.read_sql("SELECT * FROM weather_fusion ORDER BY ts ASC", conn)
    finally:
        conn.close()

    df.to_parquet(tmp_path, index=False)
    tmp_path.replace(final_path)

    latest = out_dir / "latest.parquet"
    if latest.exists() or latest.is_symlink():
        latest.unlink()
    try:
        latest.symlink_to(final_path.name)
    except OSError:
        shutil.copy2(final_path, latest)

    files = sorted(
        out_dir.glob("weather_fusion_*.parquet"),
        key=lambda path: path.stat().st_mtime,
        reverse=True,
    )
    for old in files[retain:]:
        old.unlink()

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
