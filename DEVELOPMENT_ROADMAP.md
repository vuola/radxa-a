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
- Baseline consumption forecaster runs every 15 minutes, storing 48-hour forecasts in `forecast_value` table

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

### Phase 3: Baseline Forecast Pipeline ✓ DONE

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

**Completion Date**: 2026-05-10
- Script: `baseline_forecast.py` (Ridge regression)
- Training: 1070 samples over 14 days, residual_std=869.4 W
- Coverage: 48-hour forecast horizon with p10/p50/p90 bands
- Schedule: Every 15 minutes (0,15,30,45 * * * *)
- Storage: forecast_run (run_id=4727+), forecast_value with target='baseline_w'

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

### Phase 4: Merge Forecast and Actuals in Home Consumption UI ✓ DONE

1. Add forecasted baseline columns to `/home-consumption.php`:
   - forecast p50 (required)
   - forecast p10/p90 (optional but recommended)
2. Show side-by-side baseline actual vs baseline forecast.
3. Keep EV/sauna/other as actual components initially (event-driven, non-weather first).

Deliverable:

- Unified page showing real consumption, real components, and forecasted baseline.

**Completion Date**: 2026-05-10
- UI now shows `Base Fcst W` from the latest `forecast_value` baseline run alongside `Base W`
- Future slots render forecast-only baseline values when actual component rows are not yet available

### Phase 5: Evaluation and Operations ✓ DONE

1. Add baseline-specific metrics:
   - MAE, RMSE, bias
   - rolling daily and weekly summaries
2. Add freshness and failure checks for baseline forecast job.
3. Document model versions, feature set changes, and threshold changes.

Deliverable:

- Operable baseline forecasting system with traceable quality and health.

**Completion Date**: 2026-05-10
- Home Consumption UI now shows baseline forecast metadata plus selected-day and rolling 24h/7d MAE, RMSE, and bias
- Health API now checks baseline forecast freshness and next-6h horizon coverage
- README now documents baseline model version, feature set, and decomposition threshold contract

## Implementation Order

1. Phase 1 (decomposition SQL contract)
2. Phase 2 (UI components complete)
3. Phase 3 (baseline forecast job)
4. Phase 4 (forecasted baseline in UI)
5. Phase 5 (metrics and health hardening)

## Done Criteria

This roadmap milestone is complete when all of the following hold:

- ✓ Component decomposition is stable and identity-constrained at 15-minute resolution (Phase 1)
- ✓ Home Consumption page shows real total plus all real components (Phase 2)
- ✓ Baseline forecast runs automatically every 15 minutes and is versioned in DB (Phase 3)
- ✓ Home Consumption page shows baseline actual vs baseline forecast (Phase 4)
- ✓ Baseline forecast quality and freshness are monitored (Phase 5)

## Notes

- Keep the PV forecaster operational as-is.
- Do not mix discretionary event spikes into baseline training target.
- Prefer incremental deployment and validation phase by phase.

## Future Plans (Cost Optimization)

Live-system changes are paused for now. The following items define the next implementation stage focused on reducing electricity cost.

### Phase 6: EV Charging Start-Time Optimizer (Planned)

Objective:
- Propose the optimal EV charging start time between `06:30` and `22:30` based on expected cheapest effective energy source.

Decision inputs:
- Forecasted self-produced electricity availability
- Forecasted battery-stored energy availability
- Forecasted grid electricity price (lowest-cost windows)

Expected output:
- Recommended EV charging start time for the selected day
- Cost rationale for the recommendation (solar-first, battery-first, or grid-price-first)
- Optional ranked alternatives (top 2-3 start windows)

Initial constraints:
- Recommendation window limited to `06:30-22:30`
- Preserve user comfort and charging readiness constraints (minimum target state before departure)

### Phase 7: Geothermal Pump Price-Aware Deferral (Planned)

Objective:
- Delay geothermal pump operation when grid power is expected to be expensive and household import risk is high, while minimizing hot-water discomfort.

Decision inputs:
- Anticipated grid import need
- Forecasted grid electricity price
- Household load context (sauna, dishwasher, laundry, and other known warm-water-demand events)

Deferral policy limits (temperature-adaptive):
- Maximum delay `5 hours` when outside temperature is `>= +20°C`
- Maximum delay `1 hour` when outside temperature is `<= -20°C`
- For intermediate temperatures, use linear interpolation between those endpoints

Linear limit definition:
- Let outside temperature be `T` in `°C`, clamped to `[-20, +20]`
- Maximum deferral in hours:
   `max_delay_h = 1 + (T + 20) * (4 / 40)`
   (equivalently `max_delay_h = 1 + 0.1 * (T + 20)`)

Expected output:
- Recommended delay duration and restart time
- Risk/comfort score indicating likelihood of hot-water shortage
- Clear fallback rule: cancel delay if comfort risk exceeds threshold

### Phase 8: Supervisory Cost Controller and UX (Planned)

Objective:
- Combine EV start optimization and geothermal deferral into a single supervisory controller that can propose, explain, and later automate low-risk actions.

Planned capabilities:
- Day-ahead optimization summary (expected savings and confidence)
- Action log for accepted/rejected recommendations
- Safety guardrails and override controls
- Tracking of realized cost savings vs baseline behavior

## Phase 6-8: Prerequisite Capabilities Checklist

Before implementing the cost-optimization phases (6-8), the following intermediate capabilities must be developed. Each defines a specific contract and acceptance criteria.

### 1. Battery Availability Forecasting

**Purpose**: Provide time-series forecasts of available battery energy for cost optimization decisions.

**Acceptance Criteria**:
- [ ] Battery state-of-charge (SOC) forecast: 24-hour horizon, 15-minute resolution
- [ ] Discharge power capability: hourly max discharge rate (W) accounting for thermal limits
- [ ] Reserve floor constraint: system reserves at least 15% SOC for emergency discharge
- [ ] Round-trip efficiency model: account for charge/discharge cycle losses (≥93% round-trip assumed)
- [ ] API or view: `battery_forecast_15min` view with `target_ts`, `soc_p50`, `discharge_power_w`, `efficiency_ratio`

**Dependencies**: Battery telemetry, historical charge/discharge patterns, thermal model calibration

---

### 2. Event Probability Modeling

**Purpose**: Forecast discrete household events (sauna, dishwasher, laundry, etc.) to predict demand spikes and deferral opportunities.

**Acceptance Criteria**:
- [ ] Event occurrence probabilities: per-event per-15-minute-slot probability table for each weekday (Monday-Sunday)
- [ ] Events modeled: sauna, dishwasher, laundry, oven, water heating
- [ ] Probability input features: time-of-day, day-of-week, seasonal month, user presence/occupancy status (if available)
- [ ] Output table: `event_probability_15min` with columns `target_ts`, `event_type`, `probability_pct`, `expected_power_w`, `typical_duration_min`
- [ ] Training data: at least 8 weeks of component actuals to establish baseline rates
- [ ] Retrain trigger: weekly or monthly to capture seasonal shifts

**Dependencies**: `home_consumption_components_15min` (training target), occupancy signals (if available)

---

### 3. Thermal Buffer (Hot Water) Proxy Model

**Purpose**: Estimate comfort risk when deferring geothermal pump operation.

**Acceptance Criteria**:
- [ ] Depletion risk score: lightweight model predicting hot-water availability depletion over deferral window
- [ ] Comfort classification: low (safe to defer ≤4h), medium (safe ≤2h), high (unsafe, risk of shortage)
- [ ] Input features: current (or last-known) hot-water volume, ambient temperature, forecasted event probabilities (sauna, dishwasher, shower)
- [ ] Output: comfort_level (low/medium/high) and estimated recovery time if depleted
- [ ] Model requirement: simple heuristic or lightweight regression; must execute in <100ms
- [ ] Fallback: conservative assumption (high risk) when telemetry is missing

**Dependencies**: Hot-water tank temperature telemetry (if available), event probability model, ambient temperature

---

### 4. Net Import Risk Forecast

**Purpose**: Forecast the distribution of household grid power import, not just the mean, to enable safe deferral and charging decisions.

**Acceptance Criteria**:
- [ ] Import distribution forecast: p10, p50, p90 percentiles over 24-hour horizon at 15-min resolution
- [ ] Calculation basis: forecasted solar output, battery discharge limits, event probabilities, baseline consumption
- [ ] Formula: `net_import = baseline_w + (event_prob * event_power) - solar_forecast - available_battery_discharge`
- [ ] Output table: `net_import_forecast_15min` with columns `target_ts`, `import_p10_w`, `import_p50_w`, `import_p90_w`
- [ ] Percentile method: Monte Carlo sampling (≥1000 samples) across forecast uncertainty bands
- [ ] Validation: compare p50 vs actual import over past 30 days, RMSE <500W acceptable

**Dependencies**: Solar forecast, battery forecast, event probability model, baseline consumption forecast

---

### 5. Price-Aware Decision Engine

**Purpose**: Optimize EV charging start times and geothermal deferral decisions to minimize expected energy cost.

**Acceptance Criteria**:
- [ ] EV charging optimizer: algorithm that selects cheapest start-time window within 06:30-22:30
  - [ ] Objective function: minimize `sum(net_import[t] * price[t])` over charging window
  - [ ] Constraint: at least 24 kWh available from sources (solar + battery + grid) during selected window
  - [ ] Constraint: complete charge within 8 hours (22:30 latest start)
  - [ ] Output: recommended start time, confidence score, top 3 alternatives
  
- [ ] Geothermal deferral optimizer: maximize avoided cost subject to comfort and temperature constraints
  - [ ] Objective: minimize `sum(deferred_import[t] * price[t])`
  - [ ] Constraint: maximum deferral = `1 + 0.1 * (T + 20)` hours, where T ∈ [-20, +20]°C
  - [ ] Constraint: comfort_level must not exceed HIGH at any deferred timestep
  - [ ] Output: recommended delay duration, comfort risk level, estimated savings EUR/day
  
- [ ] API endpoint: `/api/cost-optimization` returning JSON with both recommendations and confidence intervals

**Dependencies**: Net import risk forecast, electricity price forecast, battery forecast, thermal buffer model, event probability model

---

### 6. Confidence and Explainability Layer

**Purpose**: Build user trust and enable safe automation by explaining recommendation drivers and predicted outcomes.

**Acceptance Criteria**:
- [ ] Recommendation confidence scores: each recommendation (EV start time, geothermal delay) includes 0-100% confidence
- [ ] Confidence factors:
  - [ ] Solar forecast uncertainty (clear days >90%, rainy days <60%)
  - [ ] Price forecast availability (FMI/ENTSOE API health)
  - [ ] Event probability coverage (weekend >85%, weekday >90%)
  - [ ] Battery state certainty (recent SOC telemetry <30min old)
  
- [ ] Explainability: recommendation includes top-3 decision drivers attributed as percentage contribution
  - Example: "Start EV at 13:00 for €2.15 savings (Solar 45% + Low Price 35% + Battery Risk 20%)"
  
- [ ] Savings forecast: predicted cost avoidance vs baseline (next 24h) with ±15% uncertainty band
- [ ] UI requirement: display confidence and drivers on recommendation card

**Dependencies**: All forecasting models, cost optimization engine, audit logging

---

### 7. Feedback Learning Loop

**Purpose**: Continuously improve event probabilities and system recommendations by learning from actual user behavior and outcomes.

**Acceptance Criteria**:
- [ ] Feedback pipeline:
  - [ ] Log all recommendations (accepted/rejected/timeout) with user action
  - [ ] Log realized outcomes: actual sauna use, dishwasher cycle, grid import, cost vs prediction
  - [ ] Weekly batch retraining of event probability models using last 8-week rolling window
  
- [ ] Metrics to track:
  - [ ] Event prediction accuracy (recall, precision per event type)
  - [ ] Cost savings actualization: predicted vs realized cost reduction (target >80% of predicted)
  - [ ] User acceptance rate: percentage of recommendations accepted by user
  - [ ] Recommendation diversity: ensure optimizer proposes varied windows over time (avoid local optima)
  
- [ ] Storage:
  - [ ] Table `recommendation_log` (timestamp, recommendation_id, type, payload_json, accepted, action_timestamp)
  - [ ] Table `outcome_log` (recommendation_id, actual_sauna, actual_dishwasher, actual_grid_import_wh, actual_cost_eur)
  
- [ ] Retraining automation:
  - [ ] Weekly CronJob that retrains event probability models from `outcome_log`
  - [ ] Validation: must not degrade baseline accuracy (event F1 score ≥ previous week)
  - [ ] Deployment: automated push to `event_probability_15min` view if validation passes

**Dependencies**: Recommendation and outcome logging infrastructure, event probability training pipeline, feedback database schema

---

## Implementation Sequence for Prerequisites

1. **Battery Availability Forecasting** (lowest dependency, enables rest)
2. **Event Probability Modeling** (enables thermal buffer, net import risk)
3. **Thermal Buffer Proxy** (enables geothermal deferral safety checks)
4. **Net Import Risk Forecast** (enables cost optimization)
5. **Price-Aware Decision Engine** (core optimization, depends on 1-4)
6. **Confidence and Explainability Layer** (depends on all prior, can parallelize with 5)
7. **Feedback Learning Loop** (depends on all prior, enables continuous improvement)

When all 7 prerequisites are complete and validated, proceed to Phase 6-8 implementation with high confidence.
