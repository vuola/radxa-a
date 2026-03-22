#!/usr/bin/env python3
import os
import sys
from datetime import datetime, timedelta, timezone

import psycopg2
from psycopg2.extras import execute_batch


def read_env(name: str, default: str) -> str:
    value = os.environ.get(name)
    return value if value is not None and value != "" else default


def align_to_15m(ts: datetime) -> datetime:
    aligned_minute = (ts.minute // 15) * 15
    return ts.replace(minute=aligned_minute, second=0, microsecond=0)


def main() -> int:
    cutoff_ts = read_env("FORECAST_CUTOFF_TS", "2026-03-06 17:45:00+00")
    horizon_hours = int(read_env("FORECAST_HORIZON_HOURS", "24"))
    train_days = int(read_env("FORECAST_TRAIN_DAYS", "30"))
    min_radiation = float(read_env("FORECAST_MIN_RADIATION", "20"))
    active_pv_threshold_w = float(read_env("FORECAST_PV_ACTIVE_THRESHOLD_W", "100"))
    local_tz = read_env("FORECAST_LOCAL_TZ", "Europe/Helsinki")
    model_name = read_env("FORECAST_MODEL_NAME", "pv_radiation_ratio")
    model_version = read_env("FORECAST_MODEL_VERSION", "v1")

    now_utc = datetime.now(timezone.utc)
    issue_ts = align_to_15m(now_utc)
    end_ts = issue_ts + timedelta(hours=horizon_hours)

    conn = psycopg2.connect(
        host=read_env("PGHOST", "weather-postgres"),
        dbname=read_env("PGDATABASE", "weather"),
        user=read_env("PGUSER", "weather"),
        password=read_env("PGPASSWORD", ""),
    )

    try:
        with conn:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    CREATE TABLE IF NOT EXISTS forecast_run (
                      run_id BIGSERIAL PRIMARY KEY,
                      model_name TEXT NOT NULL,
                      model_version TEXT NOT NULL,
                      target TEXT NOT NULL,
                      issued_at TIMESTAMPTZ NOT NULL,
                      trained_from_ts TIMESTAMPTZ NULL,
                      trained_to_ts TIMESTAMPTZ NULL,
                      notes TEXT NULL,
                      created_at TIMESTAMPTZ NOT NULL DEFAULT now()
                    );

                    CREATE TABLE IF NOT EXISTS forecast_value (
                      run_id BIGINT NOT NULL REFERENCES forecast_run(run_id) ON DELETE CASCADE,
                      target TEXT NOT NULL,
                      target_ts TIMESTAMPTZ NOT NULL,
                      horizon_min INTEGER NOT NULL,
                      yhat_p50 DOUBLE PRECISION NULL,
                      yhat_p10 DOUBLE PRECISION NULL,
                      yhat_p90 DOUBLE PRECISION NULL,
                      created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                      PRIMARY KEY (run_id, target_ts)
                    );

                    CREATE INDEX IF NOT EXISTS forecast_value_target_ts_idx
                      ON forecast_value (target, target_ts);
                    """
                )

                cur.execute(
                    """
                    SELECT
                      COALESCE(
                        SUM(fc_shortwave_radiation_w_m2 * moxa_pv_feed_in_w)
                        / NULLIF(SUM(fc_shortwave_radiation_w_m2 * fc_shortwave_radiation_w_m2), 0),
                        0
                      ) AS ratio,
                      COUNT(*) AS sample_count
                    FROM weather_fusion
                    WHERE ts >= %s::timestamptz
                      AND ts >= now() - (%s || ' days')::interval
                      AND moxa_pv_feed_in_w IS NOT NULL
                      AND fc_shortwave_radiation_w_m2 IS NOT NULL
                      AND fc_shortwave_radiation_w_m2 >= %s;
                    """,
                    (cutoff_ts, str(train_days), min_radiation),
                )
                ratio, sample_count = cur.fetchone()

                cur.execute(
                    """
                    WITH daily AS (
                      SELECT
                        (ts AT TIME ZONE %s)::date AS d,
                        MIN(EXTRACT(EPOCH FROM (ts AT TIME ZONE %s)::time))
                          FILTER (WHERE pv_feed_in_w >= %s) AS start_sec,
                        MAX(EXTRACT(EPOCH FROM (ts AT TIME ZONE %s)::time))
                          FILTER (WHERE pv_feed_in_w >= %s) AS stop_sec
                      FROM moxa_weather_15min
                      WHERE ts >= %s::timestamptz
                        AND ts >= now() - (%s || ' days')::interval
                      GROUP BY 1
                    ), valid AS (
                      SELECT * FROM daily WHERE start_sec IS NOT NULL AND stop_sec IS NOT NULL
                    )
                    SELECT
                      percentile_cont(0.5) WITHIN GROUP (ORDER BY start_sec) AS start_sec_median,
                      percentile_cont(0.5) WITHIN GROUP (ORDER BY stop_sec) AS stop_sec_median,
                      COUNT(*) AS valid_days
                    FROM valid;
                    """,
                    (
                        local_tz,
                        local_tz,
                        active_pv_threshold_w,
                        local_tz,
                        active_pv_threshold_w,
                        cutoff_ts,
                        str(train_days),
                    ),
                )
                start_sec_median, stop_sec_median, valid_days = cur.fetchone()

                if start_sec_median is None or stop_sec_median is None:
                    start_sec_median = 0
                    stop_sec_median = 24 * 3600 - 1

                # Keep coefficient in a sane range for first-pass production estimates.
                ratio = float(max(0.0, min(20.0, ratio or 0.0)))
                window_start_sec = int(start_sec_median)
                window_stop_sec = int(stop_sec_median)

                cur.execute(
                    """
                    INSERT INTO forecast_run (
                      model_name,
                      model_version,
                      target,
                      issued_at,
                      trained_from_ts,
                      trained_to_ts,
                      notes
                    ) VALUES (%s, %s, 'pv_feed_in_w', %s, %s::timestamptz, %s, %s)
                    RETURNING run_id;
                    """,
                    (
                        model_name,
                        model_version,
                        issue_ts,
                        cutoff_ts,
                        issue_ts,
                        (
                            f"ratio={ratio:.6f}, samples={sample_count}, train_days={train_days}, "
                            f"window={window_start_sec//3600:02d}:{(window_start_sec%3600)//60:02d}-"
                            f"{window_stop_sec//3600:02d}:{(window_stop_sec%3600)//60:02d} {local_tz}, "
                            f"active_days={valid_days}, active_threshold_w={active_pv_threshold_w:.0f}"
                        ),
                    ),
                )
                run_id = cur.fetchone()[0]

                cur.execute(
                    """
                    SELECT
                      ts,
                      fc_shortwave_radiation_w_m2,
                      EXTRACT(EPOCH FROM (ts AT TIME ZONE %s)::time)::int AS local_sec
                    FROM weather_fusion
                    WHERE ts >= %s
                      AND ts < %s
                    ORDER BY ts ASC;
                    """,
                    (local_tz, issue_ts, end_ts),
                )
                rows = cur.fetchall()

                if not rows:
                    print("No weather_fusion rows found for forecast horizon", file=sys.stderr)
                    return 1

                payload = []
                for ts, radiation, local_sec in rows:
                    radiation_val = float(radiation or 0.0)
                    in_window = window_start_sec <= int(local_sec) <= window_stop_sec
                    if in_window and radiation_val >= min_radiation:
                        yhat = max(0.0, ratio * radiation_val)
                    else:
                        yhat = 0.0
                    band = max(150.0, 0.2 * yhat)
                    payload.append(
                        (
                            run_id,
                            "pv_feed_in_w",
                            ts,
                            int((ts - issue_ts).total_seconds() // 60),
                            yhat,
                            max(0.0, yhat - band),
                            yhat + band,
                        )
                    )

                execute_batch(
                    cur,
                    """
                    INSERT INTO forecast_value (
                      run_id, target, target_ts, horizon_min, yhat_p50, yhat_p10, yhat_p90
                    ) VALUES (%s, %s, %s, %s, %s, %s, %s);
                    """,
                    payload,
                    page_size=200,
                )

        print(
            f"Forecast run {run_id} created with {len(payload)} points (ratio={ratio:.6f}, samples={sample_count})",
            file=sys.stderr,
            flush=True,
        )
        return 0
    finally:
        conn.close()


if __name__ == "__main__":
    raise SystemExit(main())
