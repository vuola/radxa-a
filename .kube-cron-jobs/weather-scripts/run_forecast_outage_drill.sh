#!/usr/bin/env bash
set -euo pipefail

NAMESPACE="${NAMESPACE:-weather}"
PG_POD="${PG_POD:-weather-postgres-0}"

TS="$(date +%s)"
FMI_SCHEMA="forecast_drill_fmi_${TS}"
ENTSOE_SCHEMA="forecast_drill_entsoe_${TS}"
RUN_ID_BEFORE=""

log() {
  printf '%s\n' "$*"
}

cleanup() {
  log "Cleaning up drill schemas..."
  kubectl exec -n "$NAMESPACE" "$PG_POD" -- \
    psql -U weather -d weather -v ON_ERROR_STOP=1 -c \
    "DROP SCHEMA IF EXISTS ${FMI_SCHEMA} CASCADE; DROP SCHEMA IF EXISTS ${ENTSOE_SCHEMA} CASCADE;" >/dev/null || true
}
trap cleanup EXIT

log "Creating outage drill schemas: ${FMI_SCHEMA}, ${ENTSOE_SCHEMA}"
RUN_ID_BEFORE="$(kubectl exec -n "$NAMESPACE" "$PG_POD" -- psql -U weather -d weather -tA -c "SELECT COALESCE(MAX(run_id), 0) FROM forecast_run;" | tr -d '[:space:]')"
if [[ -z "$RUN_ID_BEFORE" ]]; then
  RUN_ID_BEFORE="0"
fi

kubectl exec -n "$NAMESPACE" "$PG_POD" -- \
  psql -U weather -d weather -v ON_ERROR_STOP=1 -c "
  CREATE SCHEMA ${FMI_SCHEMA};
  CREATE SCHEMA ${ENTSOE_SCHEMA};

  CREATE VIEW ${FMI_SCHEMA}.home_consumption_components_15min AS
    SELECT * FROM public.home_consumption_components_15min;
  CREATE VIEW ${ENTSOE_SCHEMA}.home_consumption_components_15min AS
    SELECT * FROM public.home_consumption_components_15min;

  -- FMI outage simulation: keep timestamps and measured data, null-out forecast weather fields.
  CREATE VIEW ${FMI_SCHEMA}.weather_fusion AS
  SELECT
    ts,
    price_eur_per_mwh,
    price_updated_at,
    NULL::double precision AS fc_temperature_c,
    NULL::double precision AS fc_wind_speed_ms,
    NULL::double precision AS fc_wind_direction_deg,
    NULL::double precision AS fc_cloud_cover_pct,
    NULL::double precision AS fc_shortwave_radiation_w_m2,
    moxa_temperature_c,
    moxa_dew_point_c,
    moxa_relative_humidity,
    moxa_pressure_hpa,
    moxa_wind_speed_ms,
    moxa_wind_direction_deg,
    moxa_precip_mmph,
    moxa_energy_today_wh,
    moxa_pv_feed_in_w,
    moxa_battery_soc_pct,
    moxa_active_power_pcc_w,
    moxa_bat_charge_w,
    moxa_bat_discharge_w,
    moxa_sma_json
  FROM public.weather_fusion;

  -- ENTSOE outage simulation: no weather_fusion rows at all.
  CREATE VIEW ${ENTSOE_SCHEMA}.weather_fusion AS
  SELECT * FROM public.weather_fusion WHERE false;
  "

run_job_for_schema() {
  local cronjob="$1"
  local schema="$2"
  local job_name="$3"

  log "Creating job ${job_name} from ${cronjob}"
  kubectl create job --from="cronjob/${cronjob}" "${job_name}" -n "$NAMESPACE" --dry-run=client -o yaml \
    | kubectl set env --local -f - "SOURCE_SCHEMA=${schema}" -o yaml \
    | kubectl apply -f - >/dev/null

  kubectl -n "$NAMESPACE" wait --for=condition=complete "job/${job_name}" --timeout=420s >/dev/null

  log "Logs for ${job_name}:"
  kubectl -n "$NAMESPACE" logs "job/${job_name}"
}

log "Running FMI outage drill jobs"
run_job_for_schema "pv-forecast-15min" "$FMI_SCHEMA" "pv-drill-fmi-${TS}"
run_job_for_schema "baseline-forecast-15min" "$FMI_SCHEMA" "baseline-drill-fmi-${TS}"

log "Running ENTSOE outage drill jobs"
run_job_for_schema "pv-forecast-15min" "$ENTSOE_SCHEMA" "pv-drill-entsoe-${TS}"
run_job_for_schema "baseline-forecast-15min" "$ENTSOE_SCHEMA" "baseline-drill-entsoe-${TS}"

log "Validating drill runs were written"
NEW_RUN_COUNT="$(kubectl exec -n "$NAMESPACE" "$PG_POD" -- psql -U weather -d weather -tA -c "
  SELECT COUNT(*)
  FROM forecast_run
  WHERE run_id > ${RUN_ID_BEFORE}
    AND target IN ('pv_feed_in_w', 'baseline_w');
" | tr -d '[:space:]')"

kubectl exec -n "$NAMESPACE" "$PG_POD" -- psql -U weather -d weather -P pager=off -c "
  SELECT run_id, target, issued_at, model_name, model_version, notes
  FROM forecast_run
  WHERE run_id > ${RUN_ID_BEFORE}
    AND target IN ('pv_feed_in_w', 'baseline_w')
  ORDER BY run_id DESC
  LIMIT 12;
"

if [[ -z "$NEW_RUN_COUNT" || "$NEW_RUN_COUNT" -lt 4 ]]; then
  log "Outage drill validation failed: expected >=4 new runs, got ${NEW_RUN_COUNT:-0}"
  exit 1
fi

log "Outage drill completed successfully"
