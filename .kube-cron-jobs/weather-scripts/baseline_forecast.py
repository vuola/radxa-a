#!/usr/bin/env python3
"""
Baseline consumption forecaster.

Trains on baseline_actual_w using weather and calendar features.
Generates 15-min forecasts with p10, p50, p90 uncertainty bands.
"""
import os
import re
import sys
from datetime import datetime, timedelta, timezone
import warnings

import psycopg2
from psycopg2.extras import execute_batch
import numpy as np

warnings.filterwarnings("ignore")

try:
    from sklearn.preprocessing import StandardScaler
    from sklearn.linear_model import Ridge
except ImportError:
    print("sklearn not available; using fallback model", file=sys.stderr)


def read_env(name: str, default: str) -> str:
    value = os.environ.get(name)
    return value if value is not None and value != "" else default


def read_schema(name: str, default: str) -> str:
    value = read_env(name, default)
    if not re.match(r"^[A-Za-z_][A-Za-z0-9_]*$", value):
        raise ValueError(f"Invalid schema name for {name}: {value}")
    return value


def align_to_15m(ts: datetime) -> datetime:
    aligned_minute = (ts.minute // 15) * 15
    return ts.replace(minute=aligned_minute, second=0, microsecond=0)


def get_time_features(ts: datetime) -> dict:
    """Extract calendar and time-of-day features from a timestamp."""
    ts_utc = ts.astimezone(timezone.utc)
    ts_hel = ts.astimezone(timezone(timedelta(hours=3)))  # Europe/Helsinki = UTC+3 (winter) or UTC+2 (summer)

    hour = ts_hel.hour
    dow = ts_hel.weekday()  # 0=Monday, 6=Sunday
    is_weekend = 1 if dow >= 5 else 0
    month = ts_hel.month
    moy = ts_hel.timetuple().tm_yday / 365.0  # month-of-year normalized

    return {
        "hour": hour,
        "dow": dow,
        "is_weekend": is_weekend,
        "month": month,
        "moy": moy,
    }


def build_feature_vector(
    ts: datetime,
    temp_c: float,
    wind_speed_ms: float,
    cloud_cover_pct: float,
    shortwave_radiation: float,
) -> np.ndarray:
    """Build feature vector for model prediction."""
    time_features = get_time_features(ts)

    # Normalize inputs
    temp_norm = (temp_c + 10.0) / 35.0  # Typical range -10 to +25°C
    wind_norm = min(wind_speed_ms / 15.0, 1.0)
    cloud_norm = cloud_cover_pct / 100.0
    radiation_norm = min(shortwave_radiation / 1000.0, 1.0)

    # One-hot encode hour of day (24 features)
    hour_oh = np.zeros(24)
    hour_oh[time_features["hour"]] = 1.0

    # One-hot encode day of week (7 features)
    dow_oh = np.zeros(7)
    dow_oh[time_features["dow"]] = 1.0

    features = np.concatenate(
        [
            [temp_norm, wind_norm, cloud_norm, radiation_norm, time_features["is_weekend"]],
            hour_oh,
            dow_oh,
        ]
    )
    return features


def main() -> int:
    host = read_env("PGHOST", "weather-postgres")
    db = read_env("PGDATABASE", "weather")
    user = read_env("PGUSER", "weather")
    password = read_env("PGPASSWORD", "")

    conn = psycopg2.connect(
        host=host,
        database=db,
        user=user,
        password=password,
        connect_timeout=10,
    )

    train_days = int(read_env("TRAIN_DAYS", "14"))
    forecast_horizon_h = int(read_env("FORECAST_HORIZON_H", "48"))
    model_version = read_env("MODEL_VERSION", "baseline-v1")
    model_name = "baseline_consumption"
    min_train_samples = int(read_env("MIN_TRAIN_SAMPLES", "50"))
    source_schema = read_schema("SOURCE_SCHEMA", "public")

    issue_ts = align_to_15m(datetime.now(timezone.utc))
    cutoff_ts = issue_ts - timedelta(days=train_days)
    end_ts = issue_ts + timedelta(hours=forecast_horizon_h)

    try:
        with conn:
            with conn.cursor() as cur:
                # Ensure forecast tables exist
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

                                # Fetch training data: baseline_actual + optional weather features.
                                # Keep a LEFT JOIN so missing FMI/ENTSOE does not drop all rows.
                cur.execute(
                                        f"""
                    SELECT
                      c.ts,
                      c.baseline_actual_w,
                      w.fc_temperature_c,
                      w.fc_wind_speed_ms,
                      w.fc_cloud_cover_pct,
                      w.fc_shortwave_radiation_w_m2
                                        FROM {source_schema}.home_consumption_components_15min c
                                        LEFT JOIN {source_schema}.weather_fusion w ON c.ts = w.ts
                    WHERE c.ts >= %s::timestamptz
                      AND c.ts < %s::timestamptz
                      AND c.baseline_actual_w IS NOT NULL
                    ORDER BY c.ts ASC;
                    """,
                    (cutoff_ts, issue_ts),
                )
                train_rows = cur.fetchall()

                temp_values = [float(r[2]) for r in train_rows if r[2] is not None]
                wind_values = [float(r[3]) for r in train_rows if r[3] is not None]
                cloud_values = [float(r[4]) for r in train_rows if r[4] is not None]
                radiation_values = [float(r[5]) for r in train_rows if r[5] is not None]

                default_temp = float(np.median(temp_values)) if temp_values else 5.0
                default_wind = float(np.median(wind_values)) if wind_values else 3.0
                default_cloud = float(np.median(cloud_values)) if cloud_values else 70.0
                default_radiation = float(np.median(radiation_values)) if radiation_values else 80.0

                # Build training data
                X_train = []
                y_train = []
                hourly_baseline = {}
                for ts, baseline_w, temp, wind, cloud, radiation in train_rows:
                    if baseline_w is None or baseline_w < 0:
                        continue
                    ts_hel = ts.astimezone(timezone(timedelta(hours=3)))
                    hour_key = ts_hel.hour
                    hourly_baseline.setdefault(hour_key, []).append(float(baseline_w))

                    features = build_feature_vector(
                        ts,
                        float(temp) if temp is not None else default_temp,
                        float(wind) if wind is not None else default_wind,
                        float(cloud) if cloud is not None else default_cloud,
                        float(radiation) if radiation is not None else default_radiation,
                    )
                    X_train.append(features)
                    y_train.append(float(baseline_w))

                X_train = np.array(X_train)
                y_train = np.array(y_train)

                hourly_profile = {
                    hour: float(np.mean(values))
                    for hour, values in hourly_baseline.items()
                    if values
                }

                if not hourly_profile:
                    cur.execute(
                        """
                        SELECT
                          EXTRACT(HOUR FROM target_ts AT TIME ZONE 'Europe/Helsinki')::int AS hour_local,
                          AVG(yhat_p50)::double precision AS avg_yhat
                        FROM forecast_value
                        WHERE target = 'baseline_w'
                          AND target_ts >= %s::timestamptz - interval '7 days'
                          AND yhat_p50 IS NOT NULL
                        GROUP BY 1;
                        """,
                        (issue_ts,),
                    )
                    for hour_local, avg_yhat in cur.fetchall():
                        if avg_yhat is not None:
                            hourly_profile[int(hour_local)] = float(avg_yhat)

                # Train model
                model_type = "persistence"
                try:
                    if len(X_train) >= min_train_samples and len(y_train) >= min_train_samples:
                        scaler = StandardScaler()
                        X_scaled = scaler.fit_transform(X_train)
                        model = Ridge(alpha=1.0)
                        model.fit(X_scaled, y_train)

                        # Estimate residual std (for uncertainty bands)
                        y_pred_train = model.predict(X_scaled)
                        residuals = y_train - y_pred_train
                        residual_std = float(np.std(residuals))
                        model_type = "ridge_regression"
                    else:
                        model = None
                        scaler = None
                        residual_std = float(np.std(y_train)) * 0.3 if len(y_train) > 1 else 700.0
                except Exception as e:
                    print(f"sklearn training failed: {e}, using persistence model", file=sys.stderr)
                    model = None
                    scaler = None
                    residual_std = float(np.std(y_train)) * 0.3 if len(y_train) > 1 else 700.0

                if not hourly_profile and len(y_train) > 0:
                    global_mean = float(np.mean(y_train))
                    hourly_profile = {h: global_mean for h in range(24)}
                elif not hourly_profile:
                    hourly_profile = {h: 2200.0 for h in range(24)}

                # Record run
                cur.execute(
                    """
                    INSERT INTO forecast_run (
                      model_name, model_version, target, issued_at, trained_from_ts, trained_to_ts, notes
                    ) VALUES (%s, %s, %s, %s, %s, %s, %s)
                    RETURNING run_id;
                    """,
                    (
                        model_name,
                        model_version,
                        "baseline_w",
                        issue_ts,
                        cutoff_ts,
                        issue_ts,
                        (
                            f"model_type={model_type}, samples={len(X_train)}, "
                            f"train_days={train_days}, residual_std={residual_std:.1f}, "
                                                        f"horizon_h={forecast_horizon_h}, defaults_temp={default_temp:.1f}, "
                                                        f"defaults_wind={default_wind:.1f}, defaults_cloud={default_cloud:.1f}, "
                            f"defaults_rad={default_radiation:.1f}, profile_hours={len(hourly_profile)}, "
                            f"source_schema={source_schema}"
                        ),
                    ),
                )
                run_id = cur.fetchone()[0]

                                # Fetch forecast weather data on generated slots so ENTSOE gaps still produce rows.
                cur.execute(
                                        f"""
                                        WITH slots AS (
                                            SELECT generate_series(
                                                %s::timestamptz,
                                                (%s::timestamptz - interval '15 minutes'),
                                                interval '15 minutes'
                                            ) AS target_ts
                                        )
                    SELECT
                                            s.target_ts,
                                            w.fc_temperature_c,
                                            w.fc_wind_speed_ms,
                                            w.fc_cloud_cover_pct,
                                            w.fc_shortwave_radiation_w_m2
                                        FROM slots s
                                        LEFT JOIN {source_schema}.weather_fusion w ON w.ts = s.target_ts
                                        ORDER BY s.target_ts ASC;
                    """,
                    (issue_ts, end_ts),
                )
                forecast_rows = cur.fetchall()

                if not forecast_rows:
                    print("No weather_fusion rows for forecast horizon", file=sys.stderr)
                    return 1

                # Generate forecasts
                payload = []
                for ts, temp, wind, cloud, radiation in forecast_rows:
                    temp_val = float(temp) if temp is not None else default_temp
                    wind_val = float(wind) if wind is not None else default_wind
                    cloud_val = float(cloud) if cloud is not None else default_cloud
                    radiation_val = float(radiation) if radiation is not None else default_radiation

                    features = build_feature_vector(ts, temp_val, wind_val, cloud_val, radiation_val)

                    if model is not None:
                        X_scaled = scaler.transform(features.reshape(1, -1))
                        yhat_p50 = float(model.predict(X_scaled)[0])
                    else:
                        hour = ts.astimezone(timezone(timedelta(hours=3))).hour
                        yhat_p50 = float(hourly_profile.get(hour, np.mean(list(hourly_profile.values()))))

                    yhat_p50 = max(0.0, yhat_p50)

                    # Uncertainty bands (percentiles)
                    band = residual_std * 1.28  # ~80% confidence (±1.28 std)
                    yhat_p10 = max(0.0, yhat_p50 - band)
                    yhat_p90 = yhat_p50 + band

                    horizon_min = int((ts - issue_ts).total_seconds() // 60)
                    payload.append(
                        (
                            run_id,
                            "baseline_w",
                            ts,
                            horizon_min,
                            yhat_p50,
                            yhat_p10,
                            yhat_p90,
                        )
                    )

                execute_batch(
                    cur,
                    """
                    INSERT INTO forecast_value (
                      run_id, target, target_ts, horizon_min, yhat_p50, yhat_p10, yhat_p90
                    ) VALUES (%s, %s, %s, %s, %s, %s, %s)
                    ON CONFLICT (run_id, target_ts) DO UPDATE
                    SET yhat_p50 = EXCLUDED.yhat_p50,
                        yhat_p10 = EXCLUDED.yhat_p10,
                        yhat_p90 = EXCLUDED.yhat_p90;
                    """,
                    payload,
                    page_size=200,
                )

        print(
            f"Baseline forecast run {run_id} created: {len(payload)} points, "
            f"model={model_type}, samples={len(X_train)}, residual_std={residual_std:.1f}",
            file=sys.stderr,
            flush=True,
        )
        return 0

    finally:
        conn.close()


if __name__ == "__main__":
    sys.exit(main())
