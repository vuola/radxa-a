#!/usr/bin/env python3
"""
Baseline consumption forecaster.

Trains on baseline_actual_w using weather and calendar features.
Generates 15-min forecasts with p10, p50, p90 uncertainty bands.
"""
import os
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

    issue_ts = datetime.now(timezone.utc).replace(minute=0, second=0, microsecond=0)
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

                # Fetch training data: baseline_actual + weather features
                cur.execute(
                    """
                    SELECT
                      c.ts,
                      c.baseline_actual_w,
                      w.fc_temperature_c,
                      w.fc_wind_speed_ms,
                      w.fc_cloud_cover_pct,
                      w.fc_shortwave_radiation_w_m2
                    FROM home_consumption_components_15min c
                    JOIN weather_fusion w ON c.ts = w.ts
                    WHERE c.ts >= %s::timestamptz
                      AND c.ts < %s::timestamptz
                      AND c.baseline_actual_w IS NOT NULL
                      AND w.fc_temperature_c IS NOT NULL
                    ORDER BY c.ts ASC;
                    """,
                    (cutoff_ts, issue_ts),
                )
                train_rows = cur.fetchall()

                if len(train_rows) < min_train_samples:
                    print(
                        f"Insufficient training samples: {len(train_rows)} < {min_train_samples}",
                        file=sys.stderr,
                    )
                    return 1

                # Build training data
                X_train = []
                y_train = []
                for ts, baseline_w, temp, wind, cloud, radiation in train_rows:
                    if baseline_w is None or baseline_w < 0:
                        continue
                    features = build_feature_vector(ts, temp, wind, cloud, radiation)
                    X_train.append(features)
                    y_train.append(float(baseline_w))

                X_train = np.array(X_train)
                y_train = np.array(y_train)

                if len(X_train) < min_train_samples:
                    print(
                        f"After filtering, insufficient samples: {len(X_train)} < {min_train_samples}",
                        file=sys.stderr,
                    )
                    return 1

                # Train model
                try:
                    scaler = StandardScaler()
                    X_scaled = scaler.fit_transform(X_train)
                    model = Ridge(alpha=1.0)
                    model.fit(X_scaled, y_train)

                    # Estimate residual std (for uncertainty bands)
                    y_pred_train = model.predict(X_scaled)
                    residuals = y_train - y_pred_train
                    residual_std = float(np.std(residuals))
                    model_type = "ridge_regression"
                except Exception as e:
                    print(f"sklearn training failed: {e}, using persistence model", file=sys.stderr)
                    model = None
                    scaler = None
                    # Use historical mean at same hour as fallback
                    model_type = "persistence"
                    residual_std = float(np.std(y_train)) * 0.3

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
                            f"horizon_h={forecast_horizon_h}"
                        ),
                    ),
                )
                run_id = cur.fetchone()[0]

                # Fetch forecast weather data
                cur.execute(
                    """
                    SELECT
                      ts,
                      fc_temperature_c,
                      fc_wind_speed_ms,
                      fc_cloud_cover_pct,
                      fc_shortwave_radiation_w_m2
                    FROM weather_fusion
                    WHERE ts >= %s
                      AND ts < %s
                    ORDER BY ts ASC;
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
                    features = build_feature_vector(ts, temp, wind, cloud, radiation)

                    if model is not None:
                        X_scaled = scaler.transform(features.reshape(1, -1))
                        yhat_p50 = float(model.predict(X_scaled)[0])
                    else:
                        # Persistence: use mean at same hour from training data
                        hour = ts.astimezone(timezone.utc).hour
                        hour_samples = [y for t, y in zip(train_rows, y_train)
                                       if t[0].astimezone(timezone.utc).hour == hour]
                        if hour_samples:
                            yhat_p50 = float(np.mean(hour_samples))
                        else:
                            yhat_p50 = float(np.mean(y_train))

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
