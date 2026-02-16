CREATE OR REPLACE VIEW weather_fusion AS
SELECT 
  e.ts,
  e.price_eur_per_mwh,
  e.updated_at AS price_updated_at,
  CASE 
    WHEN EXTRACT(MINUTE FROM e.ts) = 0 THEN f1.temperature_c
    ELSE f1.temperature_c + (f2.temperature_c - f1.temperature_c) * (EXTRACT(MINUTE FROM e.ts) / 60.0)
  END AS temperature_c,
  CASE 
    WHEN EXTRACT(MINUTE FROM e.ts) = 0 THEN f1.wind_speed_ms
    ELSE f1.wind_speed_ms + (f2.wind_speed_ms - f1.wind_speed_ms) * (EXTRACT(MINUTE FROM e.ts) / 60.0)
  END AS wind_speed_ms,
  CASE 
    WHEN EXTRACT(MINUTE FROM e.ts) = 0 THEN f1.wind_direction_deg
    ELSE f1.wind_direction_deg + (f2.wind_direction_deg - f1.wind_direction_deg) * (EXTRACT(MINUTE FROM e.ts) / 60.0)
  END AS wind_direction_deg,
  CASE 
    WHEN EXTRACT(MINUTE FROM e.ts) = 0 THEN f1.cloud_cover_pct
    ELSE f1.cloud_cover_pct + (f2.cloud_cover_pct - f1.cloud_cover_pct) * (EXTRACT(MINUTE FROM e.ts) / 60.0)
  END AS cloud_cover_pct,
  CASE 
    WHEN EXTRACT(MINUTE FROM e.ts) = 0 THEN f1.shortwave_radiation_w_m2
    ELSE f1.shortwave_radiation_w_m2 + (f2.shortwave_radiation_w_m2 - f1.shortwave_radiation_w_m2) * (EXTRACT(MINUTE FROM e.ts) / 60.0)
  END AS shortwave_radiation_w_m2
FROM entsoe_prices e
LEFT JOIN fmi_forecast f1 ON date_trunc('hour', e.ts) = f1.ts
LEFT JOIN fmi_forecast f2 ON date_trunc('hour', e.ts) + interval '1 hour' = f2.ts
ORDER BY e.ts;
