CREATE OR REPLACE VIEW weather_fusion AS
SELECT 
  e.ts,
  e.price_eur_per_mwh,
  e.updated_at AS price_updated_at,
  CASE 
    WHEN EXTRACT(MINUTE FROM e.ts) = 0 THEN f1.temperature_c
    ELSE f1.temperature_c + (f2.temperature_c - f1.temperature_c) * (EXTRACT(MINUTE FROM e.ts) / 60.0)
  END AS fc_temperature_c,
  CASE 
    WHEN EXTRACT(MINUTE FROM e.ts) = 0 THEN f1.wind_speed_ms
    ELSE f1.wind_speed_ms + (f2.wind_speed_ms - f1.wind_speed_ms) * (EXTRACT(MINUTE FROM e.ts) / 60.0)
  END AS fc_wind_speed_ms,
  CASE 
    WHEN EXTRACT(MINUTE FROM e.ts) = 0 THEN f1.wind_direction_deg
    ELSE f1.wind_direction_deg + (f2.wind_direction_deg - f1.wind_direction_deg) * (EXTRACT(MINUTE FROM e.ts) / 60.0)
  END AS fc_wind_direction_deg,
  CASE 
    WHEN EXTRACT(MINUTE FROM e.ts) = 0 THEN f1.cloud_cover_pct
    ELSE f1.cloud_cover_pct + (f2.cloud_cover_pct - f1.cloud_cover_pct) * (EXTRACT(MINUTE FROM e.ts) / 60.0)
  END AS fc_cloud_cover_pct,
  CASE 
    WHEN EXTRACT(MINUTE FROM e.ts) = 0 THEN f1.shortwave_radiation_w_m2
    ELSE f1.shortwave_radiation_w_m2 + (f2.shortwave_radiation_w_m2 - f1.shortwave_radiation_w_m2) * (EXTRACT(MINUTE FROM e.ts) / 60.0)
  END AS fc_shortwave_radiation_w_m2,
  m.temperature_c,
  m.dew_point_c,
  m.relative_humidity,
  m.pressure_hpa,
  m.wind_speed_ms,
  m.wind_direction_deg,
  m.precip_mmph,
  m.energy_today_wh,
  m.pv_feed_in_w,
  m.battery_soc_pct,
  m.active_power_pcc_w,
  m.bat_charge_w,
  m.bat_discharge_w,
  m.sma_json
FROM entsoe_prices e
LEFT JOIN fmi_forecast f1 ON date_trunc('hour', e.ts) = f1.ts
LEFT JOIN fmi_forecast f2 ON date_trunc('hour', e.ts) + interval '1 hour' = f2.ts
LEFT JOIN moxa_weather_15min m ON e.ts = m.ts
ORDER BY e.ts;

CREATE OR REPLACE VIEW home_consumption_actual_15min AS
SELECT
  ts,
  GREATEST(
    0,
    pv_feed_in_w
    - active_power_pcc_w
    + bat_discharge_w
    - bat_charge_w
  )::INTEGER AS home_consumption_actual_w
FROM weather_fusion
WHERE ts IS NOT NULL
  AND pv_feed_in_w IS NOT NULL
  AND active_power_pcc_w IS NOT NULL
  AND bat_discharge_w IS NOT NULL
  AND bat_charge_w IS NOT NULL
ORDER BY ts;

CREATE OR REPLACE VIEW home_consumption_components_15min AS
WITH base_ref AS (
  SELECT percentile_cont(0.25) WITHIN GROUP (ORDER BY home_consumption_actual_w)::numeric AS base_w
  FROM home_consumption_actual_15min
  WHERE EXTRACT(HOUR FROM ts AT TIME ZONE 'Europe/Helsinki') BETWEEN 0 AND 5
), raw AS (
  SELECT
    h.ts,
    h.home_consumption_actual_w::numeric AS total_w,
    b.base_w,
    (h.home_consumption_actual_w::numeric - b.base_w) AS excess_w
  FROM home_consumption_actual_15min h
  CROSS JOIN base_ref b
), with_nominal_class AS (
  -- Classify each slot by its nominal power band
  SELECT
    ts,
    total_w,
    excess_w,
    CASE
      WHEN excess_w BETWEEN 4500 AND 8500 THEN 'EV'
      WHEN excess_w BETWEEN 9000 AND 13000 THEN 'SAUNA'
      WHEN excess_w > 2500 THEN 'OTHER'
      ELSE 'BASE'
    END AS nominal_class
  FROM raw
), with_event_windows AS (
  -- Detect if slot is within ±60 min of nominal EV or SAUNA event
  -- (handles ramp-up/down phases as part of the same charging session)
  SELECT
    ts,
    total_w,
    excess_w,
    nominal_class,
    MAX(CASE WHEN nominal_class = 'EV' THEN 1 ELSE 0 END) 
      OVER (ORDER BY ts ROWS BETWEEN 4 PRECEDING AND 4 FOLLOWING) AS has_ev_event_in_window,
    MAX(CASE WHEN nominal_class = 'SAUNA' THEN 1 ELSE 0 END) 
      OVER (ORDER BY ts ROWS BETWEEN 4 PRECEDING AND 4 FOLLOWING) AS has_sauna_event_in_window
  FROM with_nominal_class
), classified AS (
  SELECT
    ts,
    total_w,
    CASE
      -- EV: either nominal EV, OR within 60 min of EV event AND high power but under SAUNA band
      WHEN nominal_class = 'EV' THEN ROUND(excess_w)
      WHEN has_ev_event_in_window = 1 AND excess_w > 2500 AND excess_w < 9000 THEN ROUND(excess_w)
      ELSE 0
    END AS ev_w,
    CASE
      -- SAUNA: either nominal SAUNA, OR within 60 min of SAUNA event AND high power but not EV range (and no EV event active)
      WHEN nominal_class = 'SAUNA' THEN ROUND(excess_w)
      WHEN has_sauna_event_in_window = 1 AND has_ev_event_in_window = 0 AND excess_w > 2500 AND excess_w < 4500 THEN ROUND(excess_w)
      ELSE 0
    END AS sauna_w,
    CASE
      -- OTHER: high power but not part of active EV or SAUNA event windows
      WHEN nominal_class = 'OTHER' AND has_ev_event_in_window = 0 AND has_sauna_event_in_window = 0 THEN ROUND(excess_w)
      ELSE 0
    END AS other_w
  FROM with_event_windows
)
SELECT
  ts,
  ROUND(total_w)::INTEGER AS home_consumption_actual_w,
  GREATEST(0, ROUND(total_w - ev_w - sauna_w - other_w))::INTEGER AS baseline_actual_w,
  ev_w::INTEGER AS ev_actual_w,
  sauna_w::INTEGER AS sauna_actual_w,
  other_w::INTEGER AS other_actual_w
FROM classified
ORDER BY ts;
