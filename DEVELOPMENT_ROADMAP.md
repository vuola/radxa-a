# Development Roadmap: Home Consumption Components and Baseline Forecasting

This roadmap captures the accepted next steps for the home-consumption feature set:

- show real consumption and model components in the Home Consumption UI
- add a weather-based baseline forecaster
- display forecasted baseline together with actual consumption/components

Obsolete roadmap items related to deferred PV forecaster redesign are removed.

## Current State (already implemented)

- PV forecasting pipeline is operational and stable
- Home consumption actual series is available via `home_consumption_actual_15min`
- Home consumption components decomposed via `home_consumption_components_15min` with event continuity detection
- Home Consumption page exists at `/home-consumption.php`
- Current Home Consumption UI shows:
  - `Actual W`, `Base W`, `EV W`, `Sauna W`, `Other W` (per 15-min timestamp)
  - KPI cards: total energy, baseline energy, EV energy, sauna energy, other energy (daily aggregates)

## Goal

Build a reliable, explainable decomposition and forecasting stack for home consumption where:

- actual demand is split into actionable components
- baseline component is forecasted from weather and calendar features
- UI shows both actual components and forecasted baseline clearly

## Canonical Power Definition

Assume PCC sign convention:

- `active_power_pcc_w > 0`: export
- `active_power_pcc_w < 0`: import

Measured home consumption actual:

- `home_consumption_actual_w = pv_feed_in_w - active_power_pcc_w + bat_discharge_w - bat_charge_w`

## Target Component Model

Target decomposition per 15-minute timestamp:

- `total_actual_w`
- `baseline_actual_w`
- `ev_actual_w`
- `sauna_actual_w`
- `other_actual_w`

Identity constraint:

- `total_actual_w = baseline_actual_w + ev_actual_w + sauna_actual_w + other_actual_w`

Interpretation:

- `baseline_actual_w`: weather-sensitive, non-discretionary background load
- `ev_actual_w`: EV charging component (around 6.5 kW excess over baseline)
- `sauna_actual_w`: sauna component (around 11 kW excess over baseline)
- `other_actual_w`: discretionary/event residual (dishwasher, laundry, oven, miscellaneous spikes)

## Phased Plan

### Phase 1: Lock Decomposition Contract ✓ DONE

1. Create a stable SQL decomposition view for home consumption components.
2. Ensure the decomposition identity is always satisfied.
3. Use dependency-safe view recreation order in `create-fusion-view` logic.

Deliverable:

- A single source of truth view for component actuals, reusable by UI and forecast training.

**Completion Date**: 2026-05-10

### Phase 2: UI for Actual Components ✓ DONE

1. Extend `/home-consumption.php` to include all actual components:
   - actual total
   - baseline actual
   - EV actual
   - sauna actual
   - other actual
2. Add daily KPI summaries per component (W and kWh aggregates).
3. Keep display readable on mobile.

Deliverable:

- Home Consumption page explains where total demand comes from at each timestamp and daily component energy breakdown.

**Completion Date**: 2026-05-10
- Event continuity detection: consecutive EV charging slots are now correctly grouped as single event
- Daily energy KPIs show baseline (33.97 kWh), EV (14.85 kWh), sauna, other loads

### Phase 3: Baseline Forecast Pipeline

1. Create a baseline forecast script (new target: `baseline_w`).
2. Train only on `baseline_actual_w` (not total load).
3. Use weather and calendar features:
   - temperature
   - wind speed
   - cloud cover
   - shortwave radiation
   - hour-of-day and day-of-week
   - weekend/holiday flags
4. Store runs in existing forecast tables (`forecast_run`, `forecast_value`) with clear metadata.
5. Schedule via CronJob every 15 minutes.

Deliverable:

- Automated baseline forecast with versioned metadata and uncertainty bands.

### Phase 4: Merge Forecast and Actuals in Home Consumption UI

1. Add forecasted baseline columns to `/home-consumption.php`:
   - forecast p50 (required)
   - forecast p10/p90 (optional but recommended)
2. Show side-by-side baseline actual vs baseline forecast.
3. Keep EV/sauna/other as actual components initially (event-driven, non-weather first).

Deliverable:

- Unified page showing real consumption, real components, and forecasted baseline.

### Phase 5: Evaluation and Operations

1. Add baseline-specific metrics:
   - MAE, RMSE, bias
   - rolling daily and weekly summaries
2. Add freshness and failure checks for baseline forecast job.
3. Document model versions, feature set changes, and threshold changes.

Deliverable:

- Operable baseline forecasting system with traceable quality and health.

## Implementation Order

1. Phase 1 (decomposition SQL contract)
2. Phase 2 (UI components complete)
3. Phase 3 (baseline forecast job)
4. Phase 4 (forecasted baseline in UI)
5. Phase 5 (metrics and health hardening)

## Done Criteria

This roadmap milestone is complete when all of the following hold:

- Component decomposition is stable and identity-constrained at 15-minute resolution.
- Home Consumption page shows real total plus all real components.
- Baseline forecast runs automatically every 15 minutes and is versioned in DB.
- Home Consumption page shows baseline actual vs baseline forecast.
- Baseline forecast quality and freshness are monitored.

## Notes

- Keep the PV forecaster operational as-is.
- Do not mix discretionary event spikes into baseline training target.
- Prefer incremental deployment and validation phase by phase.
