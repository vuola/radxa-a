# Weather System Audit - Corrective Actions

**Audit Date**: March 7, 2026  
**System**: radxa-a.local weather archive stack

---

## Item #1: ENTSO-E Prices Table

**Status**: ✅ PASS  
**Finding**: All 10 sampled rows match ENTSO-E API exactly (0.00 €/MWh difference)  
**Action Required**: None

---

## Item #2: FMI Forecast Table

**Status**: ❌ FAIL - Critical data corruption

**Issue**: Solar radiation values are incorrect by a factor of 3,600×.

**Root Cause**: The FMI importer stores accumulated radiation energy (J/m²) directly into `shortwave_radiation_w_m2` without converting to instantaneous power (W/m²).

**Evidence**:
- FMI API returns `RadiationGlobalAccumulation` in J/m² (accumulated over 1 hour)
- Database stores values as if they were W/m²
- Correct conversion is accumulated J/m² ÷ 3600 = W/m²

**Impact**:
- `fmi_forecast` radiation values are incorrect
- `weather_fusion` inherits corrupted radiation
- Web UI and parquet exports include wrong radiation values
- Control logic may use wrong solar forecast values

**Corrective Actions (brief)**:
1. Fix importer conversion (accumulation → W/m²)
2. Backfill historical `fmi_forecast` radiation values
3. Re-verify web/UI/parquet outputs after fix
4. Review control logic using forecast radiation

---

## Item #3: Moxa Weather 15-Minute Table

**Status**: ⚠️ SUSPICIOUS - Requires online source verification at moxa.local

**Issue**: `moxa_weather_15min` temperatures on radxa-a appeared significantly lower than current instantaneous values observed from moxa.local API.

**Impact**:
- Possible mismatch between averaged and instantaneous value semantics
- Potential risk of incorrect downstream temperature-based logic

**Corrective Actions (brief)**:
1. Compare radxa-a `moxa_weather_15min` rows with moxa.local local SQLite source rows for the same timestamps
2. Verify moxa.local averaging semantics (`/api/avg.php`) against raw 1-minute SQLite rows
3. Verify radxa-a importer parameter usage and column mapping
4. Perform full lineage check online at moxa.local after radxa-a audit is complete

---

## Item #4: SQLite Backup Files

**Status**: ✅ FIXED at moxa.local

**Update (2026-03-07)**: `moxa-archive-upload` on moxa.local was corrected and backup upload resumed.

**Issue**: No fresh SQLite backup files visible in `/media/ssd250/weather/inbox/` (only `processed/` directory), and PostgreSQL `weather.max(ts)` is stale at `2026-02-09 21:59:33+00` even though sqlite import jobs complete.

**Impact**:
- SQLite-derived source dataset on radxa-a is outdated
- Source-level reconciliation and history completeness are compromised

**Corrective Actions (brief)**:
1. ✅ Verify `moxa-archive-upload` timer/service on moxa.local is running and successful daily
2. ✅ Verify uploads reach radxa-a ingest path
3. ✅ Verify sqlite import consumes new files and advances `weather.max(ts)`
4. ⏳ Add alert if no new SQLite backup is imported within 24h

---

## Item #5: PostgreSQL Backup Dumps

**Status**: ⚠️ PASS with reliability issue

**Finding**: Latest SQL dump is valid and contains expected core tables/data.

**Issue**: Intermittent zero-byte dump artifact detected (`weather_2026-03-03_001501.sql`).

**Additional finding during Item #5 work (radxa-a.local)**:
- Bug in moxa importer parameter semantics: `/home/vuola/.kube-cron-jobs/weather-scripts/moxa_weather_import.py` used `n_minutes = 900` even though moxa.local API `n` expects sample count (1-minute rows), not seconds.
- This caused 900-sample (~15-hour) averaging instead of 15-sample (15-minute) averaging, producing stale moxa temperatures.
- ✅ Local fix applied: `n_minutes = 15`.

**Impact**:
- Silent backup failure risk despite file presence

**Corrective Actions (brief)**:
1. Add post-backup validation to fail on zero-byte/too-small dumps
2. Add alerting for missing/invalid daily dump
3. Ensure retention logic does not treat failed empty dumps as valid backups

---

## Item #6: Weather Fusion View

**Status**: ⚠️ PARTIAL PASS - View logic sound, upstream data quality issues inherited

**Finding**: View correctly implements ENTSOE-M spine with FMI interpolation and Moxa matching. However, data coverage gaps and upstream corruption propagate.

**Issues**:
1. FMI forecast coverage: 1840/2012 rows (91.4%) — forecast horizon limits expected
2. Moxa data coverage: 1550/2012 rows (77%) — moxa_weather_15min stale after 2026-03-07 00:00 UTC
3. Recent rows (2026-03-07 19:30-21:45 UTC) show NULL moxa columns due to upstream staleness
4. FMI radiation values (9.3M W/m²) propagate corrupted data to fusion view

**Impact**:
- Fusion view is missing recent moxa data (since Feb 9 cutoff in upstream)
- Radiation-dependent analytics inherit Item #2 corruption
- Web/parquet exports include NULL moxa columns in recent rows

**Corrective Actions (brief)**:
1. No view-level changes needed; issues are upstream
2. Once Item #2 (FMI radiation) is fixed, radiation column will auto-correct
3. Once Item #3 (moxa verification) completes, moxa JOIN validation will be updated
4. Once Item #4 (SQLite ingest) resumes, moxa coverage gap will close automatically


## Item #7: Parquet Export Files

**Status**: ✅ PASS - Export pipeline functioning correctly

**Finding**: Parquet exports are valid, current, and schema-aligned with `weather_fusion` view.

**Details**:
- Latest file: `weather_fusion_20260307_104559.parquet` (169 KB)
- Row count: 2012 (matches weather_fusion exactly)
- Columns: 34 (all expected fields + sma_json struct)
- Export frequency: Every ~15 minutes (31 files on 2026-03-07)
- Schema integrity: ✅ Matches view definition

**Impact**:
- Parquet export pipeline is healthy and reliable
- Inherits upstream data quality issues (Item #2 radiation, Item #3/4 moxa staleness)

**Corrective Actions (brief)**:
1. No export-level fixes needed
2. Once Items #2, #3, #4 are fixed, parquet quality will automatically improve
3. Monitor export file sizes remain in 165-170 KB range (indicates consistent data volume)

---

## Audit Progress

- [x] Item #1: ENTSO-E prices table
- [x] Item #2: FMI forecast table
- [x] Item #3: Moxa weather 15-min table (flagged for follow-up at moxa.local)
- [x] Item #4: SQLite backup files
- [x] Item #5: PostgreSQL backup dumps
- [x] Item #6: Weather fusion view
- [x] Item #7: Parquet export files

---

## Recommended Work Order (Upstream → Downstream)

To minimize rework and ensure downstream validation reflects corrected inputs, execute corrective actions in this order:

1. **Item #4 — Restore SQLite backup ingest chain**
   - Re-establish daily moxa.local backup upload and successful ingestion on radxa-a.
   - Reason: most upstream operational data source is currently stale.

2. **Item #3 — Validate MOXA 15-minute averaging vs moxa.local SQLite raw data**
   - Confirm semantic correctness (instantaneous vs averaged), mapping, and timestamp alignment.
   - Reason: resolves source-data interpretation before downstream quality checks.

3. **Item #2 — Fix FMI radiation unit conversion and backfill history**
   - Correct importer conversion (J/m² accumulation → W/m²) and repair stored historical values.
   - Reason: major upstream data-quality defect propagating to fusion and exports.

4. **Item #5 — Harden PostgreSQL backup reliability checks**
   - Enforce non-empty/size validation and alerting for failed dumps.
   - Reason: improves operational resilience once primary data fixes are in place.

5. **Item #6 — Re-validate weather_fusion outputs**
   - Verify completeness and correctness after upstream fixes (Items #2, #3, #4).
   - Reason: fusion view is downstream and currently inherits upstream defects.

6. **Item #7 — Re-validate parquet exports**
   - Confirm exported values and coverage are correct after upstream remediation.
   - Reason: parquet layer is final downstream artifact.

### Execution Phases

- **Phase A (Source integrity):** Item #4 → Item #3  
- **Phase B (Forecast integrity):** Item #2  
- **Phase C (Operational resilience):** Item #5  
- **Phase D (Downstream verification):** Item #6 → Item #7

