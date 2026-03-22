# Development Roadmap: Holistic Grid Usage Forecasting

This roadmap captures the next implementation steps after the completed PV feed-in forecasting baseline.

## Current Baseline (already implemented)

- PV forecast pipeline (`pv_forecast_baseline.py`) runs every 15 minutes
- Adaptive PV production window (day-specific start/stop behavior)
- Forecast storage in PostgreSQL (`forecast_run`, `forecast_value`)
- Mobile-friendly forecast vs actual page (`/forecast.php`)
- Kubernetes CronJob automation (`pv-forecast-15min`)

## Goal

Build a holistic forecasting stack that predicts:

- PV production
- Household consumption (load)
- Net grid usage (import/export)

Then use these forecasts for reliable heating-control decisions.

## Planned Phases

### Phase 1: Forecast Data Model Extension

1. Add support for multiple forecast targets in `forecast_value` usage patterns:
   - `pv_feed_in_w`
   - `consumption_w`
   - `grid_usage_w`
2. Keep `forecast_run` as shared run metadata table.
3. Standardize run metadata fields (`model_name`, `model_version`, `issued_at`, `notes`) across all forecast jobs.
4. Define naming conventions for model versions and notes to track experiments.

Deliverable:
- Stable schema/query conventions for storing all forecast types side by side.

### Phase 2: Consumption Forecast Pipeline

1. Define canonical measured power-balance equations (actuals):
   - Assume `active_power_pcc_w` sign convention: positive = export to grid, negative = import from grid.
   - `grid_net_w = -active_power_pcc_w` (positive = import, negative = export)
   - `consumption_actual_w = pv_feed_in_w + bat_discharge_w - bat_charge_w + grid_net_w`
   - Equivalent form: `consumption_actual_w = pv_feed_in_w - active_power_pcc_w + bat_discharge_w - bat_charge_w`
2. Derive household consumption forecast target from `consumption_actual_w`.
3. Build baseline consumption model at 15-minute resolution:
   - Time-of-day/day-of-week profile baseline
   - Optional weather sensitivity features (temperature, wind, cloud)
4. Store p10/p50/p90 forecasts in `forecast_value` with target `consumption_w`.
5. Add CronJob to run every 15 minutes and write runs to DB.

Deliverable:
- Automated consumption forecast runs with metadata and uncertainty bands.

### Phase 3: Net Grid Usage Forecast

1. Compute net grid usage forecast from full power balance (including battery):
   - `grid_usage_w = consumption_w - pv_feed_in_w - bat_discharge_w + bat_charge_w`
   - Equivalent relation to PCC measurement target: `grid_usage_w ~= -active_power_pcc_w`
2. Enforce clear sign convention:
   - Positive = import from grid
   - Negative = export to grid
3. Store grid usage p10/p50/p90 as first-class forecast target.
4. Validate forecast consistency across components:
   - `consumption_w ~= pv_feed_in_w + bat_discharge_w - bat_charge_w + grid_usage_w`
   - `grid_usage_actual_w = -active_power_pcc_w` and compare against modeled `grid_usage_w`
   - Optional split actuals: `grid_import_actual_w = GREATEST(-active_power_pcc_w, 0)`, `grid_export_actual_w = GREATEST(active_power_pcc_w, 0)`

Deliverable:
- End-to-end 24h grid import/export forecast updated every 15 minutes.

### Phase 4: Forecast Evaluation and Monitoring

1. Extend `forecast.php` to show all three targets (PV, consumption, grid).
2. Add per-horizon metrics (MAE, RMSE, bias) for each target.
3. Add rolling performance views in SQL for quick diagnostics.
4. Add stale-data and failed-run health checks to existing health monitoring.

Deliverable:
- Single monitoring view for model quality and operational status.

### Phase 5: Control Integration (Heating Logic)

1. Define decision features from forecasts:
   - expected import energy over upcoming intervals
   - high-price interval overlap
   - confidence-aware risk flags (using p10/p90)
2. Implement conservative control policy:
   - allow/prohibit geothermal usage based on forecasted import cost/risk
3. Add fail-safe rules:
   - automatic re-allow timeout
   - fallback to safe mode when forecast freshness is insufficient
4. Log each control decision with forecast snapshot and reason code.

Deliverable:
- Traceable, forecast-driven heating control with explicit safety constraints.

## Implementation Order for Next Session

1. Implement Phase 2 (consumption forecast) end to end.
2. Implement Phase 3 (grid usage forecast) from modeled components.
3. Upgrade `forecast.php` for tri-target forecast vs actual.
4. Add metrics + health checks (Phase 4).
5. Connect to control decision logic (Phase 5).

## Done Criteria

The holistic forecasting milestone is complete when:

- All three forecast targets (PV, consumption, grid usage) run automatically every 15 minutes
- Forecasts are stored with run metadata and uncertainty bands
- UI shows forecast vs actual and error metrics for all targets
- Health checks detect stale or failed forecast jobs
- Control decisions can be traced to concrete forecast evidence

## Notes for Continuation

- Keep the current PV baseline operational while adding new targets.
- Prefer incremental deployment and validation (one phase at a time).
- Preserve backward compatibility in DB queries and UI where possible.
