<?php
$readSecret = function(string $path, string $fallback = ''): string {
  if (is_readable($path)) {
    return trim((string)file_get_contents($path));
  }
  return $fallback;
};

$host = getenv('DB_HOST') ?: 'weather-postgres';
$db = getenv('DB_NAME') ?: $readSecret('/var/secret/MYSQL_DATABASE', 'weather');
$user = getenv('DB_USER') ?: $readSecret('/var/secret/MYSQL_USER', 'weather');
$pass = getenv('DB_PASS') ?: $readSecret('/var/secret/MYSQL_PASSWORD', '');
$dsn = "pgsql:host={$host};dbname={$db}";

try {
  $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
  http_response_code(500);
  echo "DB connection failed";
  exit;
}

$tz = new DateTimeZone('Europe/Helsinki');
$nowLocal = new DateTimeImmutable('now', $tz);
$dateParam = isset($_GET['date']) ? strtolower(trim((string)$_GET['date'])) : '';
$dayParam = isset($_GET['day']) ? trim((string)$_GET['day']) : '';

if ($dayParam !== '') {
  $date = DateTimeImmutable::createFromFormat('Y-m-d', $dayParam, $tz);
  if (!$date) {
    http_response_code(400);
    echo "Invalid day format. Use YYYY-MM-DD.";
    exit;
  }
  $startLocal = $date->setTime(0, 0, 0);
} elseif ($dateParam === 'tomorrow') {
  $startLocal = $nowLocal->modify('+1 day')->setTime(0, 0, 0);
} else {
  $startLocal = $nowLocal->setTime(0, 0, 0);
}

$endLocal = $startLocal->modify('+1 day');
$startUtc = $startLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
$endUtc = $endLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');

$runStmt = $pdo->query(
  "SELECT run_id, model_name, model_version, issued_at, notes
   FROM forecast_run
   WHERE target = 'baseline_w'
   ORDER BY issued_at DESC
   LIMIT 1"
);
$latestBaselineRun = $runStmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare(
  "WITH slots AS (
     SELECT generate_series(
       :start::timestamptz,
       (:end::timestamptz - interval '15 minutes'),
       interval '15 minutes'
     ) AS target_ts
   ),
   best_forecast AS (
     SELECT DISTINCT ON (fv.target_ts)
       fv.target_ts,
       fv.horizon_min,
       fv.yhat_p50,
       fv.yhat_p10,
       fv.yhat_p90,
       fr.run_id,
       fr.model_name,
       fr.model_version,
       fr.issued_at
     FROM forecast_value fv
     JOIN forecast_run fr ON fr.run_id = fv.run_id
     WHERE fv.target = 'baseline_w'
       AND fr.target = 'baseline_w'
       AND fv.target_ts >= :start
       AND fv.target_ts < :end
     ORDER BY fv.target_ts, fr.issued_at DESC, fr.run_id DESC
   )
   SELECT
     s.target_ts,
     c.home_consumption_actual_w,
     c.baseline_actual_w,
     bf.yhat_p50 AS baseline_forecast_w,
     bf.yhat_p10 AS baseline_forecast_p10_w,
     bf.yhat_p90 AS baseline_forecast_p90_w,
     bf.run_id AS baseline_forecast_run_id,
     bf.model_name AS baseline_forecast_model_name,
     bf.model_version AS baseline_forecast_model_version,
     bf.issued_at AS baseline_forecast_issued_at,
     c.ev_actual_w,
     c.sauna_actual_w,
     c.other_actual_w
   FROM slots s
   LEFT JOIN home_consumption_components_15min c ON c.ts = s.target_ts
   LEFT JOIN best_forecast bf ON bf.target_ts = s.target_ts
   ORDER BY s.target_ts ASC"
);
$stmt->execute([
  ':start' => $startUtc,
  ':end' => $endUtc,
]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$actualPointCount = 0;
$missingPointCount = 0;
$sumW = 0.0;
$sumBaseW = 0.0;
$sumEvW = 0.0;
$sumSaunaW = 0.0;
$sumOtherW = 0.0;
$forecastPointCount = 0;
$errorCount = 0;
$absErrorSum = 0.0;
$sqErrorSum = 0.0;
$errorSum = 0.0;
$runIdSet = [];
$modelSet = [];
$latestIssuedAt = null;
$peakW = null;
foreach ($rows as $row) {
  if ($row['baseline_forecast_w'] !== null) {
    $forecastPointCount++;
  }
  if ($row['baseline_forecast_run_id'] !== null) {
    $runIdSet[(string)$row['baseline_forecast_run_id']] = true;
  }
  if ($row['baseline_forecast_model_name'] !== null && $row['baseline_forecast_model_version'] !== null) {
    $modelSet[(string)$row['baseline_forecast_model_name'] . ' ' . (string)$row['baseline_forecast_model_version']] = true;
  }
  if ($row['baseline_forecast_issued_at'] !== null) {
    $ts = (new DateTimeImmutable((string)$row['baseline_forecast_issued_at']))->getTimestamp();
    if ($latestIssuedAt === null || $ts > $latestIssuedAt) {
      $latestIssuedAt = $ts;
    }
  }

  if ($row['home_consumption_actual_w'] === null) {
    $missingPointCount++;
  } else {
    $w = (float)$row['home_consumption_actual_w'];
    $baseW = (float)($row['baseline_actual_w'] ?? 0);
    $evW = (float)($row['ev_actual_w'] ?? 0);
    $saunaW = (float)($row['sauna_actual_w'] ?? 0);
    $otherW = (float)($row['other_actual_w'] ?? 0);

    $sumW += $w;
    $sumBaseW += $baseW;
    $sumEvW += $evW;
    $sumSaunaW += $saunaW;
    $sumOtherW += $otherW;
    $actualPointCount++;
    if ($peakW === null || $w > $peakW) {
      $peakW = $w;
    }

    if ($row['baseline_forecast_w'] !== null) {
      $errW = $baseW - (float)$row['baseline_forecast_w'];
      $absErrorSum += abs($errW);
      $sqErrorSum += $errW * $errW;
      $errorSum += $errW;
      $errorCount++;
    }
  }
}

$rollingStmt = $pdo->query(
  "WITH best_forecast AS (
     SELECT DISTINCT ON (fv.target_ts)
       fv.target_ts,
       fv.yhat_p50,
       fr.issued_at,
       fr.model_name,
       fr.model_version
     FROM forecast_value fv
     JOIN forecast_run fr ON fr.run_id = fv.run_id
     WHERE fv.target = 'baseline_w'
       AND fr.target = 'baseline_w'
     ORDER BY fv.target_ts, fr.issued_at DESC, fr.run_id DESC
   ),
   eval AS (
     SELECT
       bf.target_ts,
       (c.baseline_actual_w::double precision - bf.yhat_p50::double precision) AS err_w
     FROM best_forecast bf
     JOIN home_consumption_components_15min c ON c.ts = bf.target_ts
     WHERE c.baseline_actual_w IS NOT NULL
       AND bf.yhat_p50 IS NOT NULL
   )
   SELECT
     COUNT(*) FILTER (WHERE target_ts >= now() - interval '1 day') AS day_count,
     AVG(ABS(err_w)) FILTER (WHERE target_ts >= now() - interval '1 day') AS day_mae,
     SQRT(AVG(POWER(err_w, 2)) FILTER (WHERE target_ts >= now() - interval '1 day')) AS day_rmse,
     AVG(err_w) FILTER (WHERE target_ts >= now() - interval '1 day') AS day_bias,
     COUNT(*) FILTER (WHERE target_ts >= now() - interval '7 days') AS week_count,
     AVG(ABS(err_w)) FILTER (WHERE target_ts >= now() - interval '7 days') AS week_mae,
     SQRT(AVG(POWER(err_w, 2)) FILTER (WHERE target_ts >= now() - interval '7 days')) AS week_rmse,
     AVG(err_w) FILTER (WHERE target_ts >= now() - interval '7 days') AS week_bias
   FROM eval"
);
$rolling = $rollingStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$healthStmt = $pdo->query(
  "WITH latest AS (
     SELECT run_id, issued_at, model_name, model_version, notes
     FROM forecast_run
     WHERE target = 'baseline_w'
     ORDER BY issued_at DESC
     LIMIT 1
   )
   SELECT
     latest.run_id,
     latest.model_name,
     latest.model_version,
     latest.issued_at,
     latest.notes,
     EXTRACT(EPOCH FROM (now() - latest.issued_at))/60.0 AS age_min,
     COUNT(*) FILTER (
       WHERE fv.target_ts >= now()
         AND fv.target_ts < now() + interval '6 hours'
     ) AS future_6h_count
   FROM latest
   LEFT JOIN forecast_value fv
     ON fv.run_id = latest.run_id
    AND fv.target = 'baseline_w'
   GROUP BY latest.run_id, latest.model_name, latest.model_version, latest.issued_at, latest.notes"
);
$baselineHealth = $healthStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$avgText = $actualPointCount > 0 ? number_format($sumW / $actualPointCount, 0, '.', '') . ' W' : 'n/a';
$peakText = $peakW !== null ? number_format($peakW, 0, '.', '') . ' W' : 'n/a';
$energyText = number_format($sumW / 4000.0, 2, '.', '') . ' kWh';
$baseEnergyText = number_format($sumBaseW / 4000.0, 2, '.', '') . ' kWh';
$evEnergyText = number_format($sumEvW / 4000.0, 2, '.', '') . ' kWh';
$saunaEnergyText = number_format($sumSaunaW / 4000.0, 2, '.', '') . ' kWh';
$otherEnergyText = number_format($sumOtherW / 4000.0, 2, '.', '') . ' kWh';
$maeText = $errorCount > 0 ? number_format($absErrorSum / $errorCount, 1, '.', '') . ' W' : 'n/a';
$rmseText = $errorCount > 0 ? number_format(sqrt($sqErrorSum / $errorCount), 1, '.', '') . ' W' : 'n/a';
$biasText = $errorCount > 0 ? number_format($errorSum / $errorCount, 1, '.', '') . ' W' : 'n/a';
$rollingDayMaeText = !empty($rolling['day_count']) ? number_format((float)$rolling['day_mae'], 1, '.', '') . ' W' : 'n/a';
$rollingDayRmseText = !empty($rolling['day_count']) ? number_format((float)$rolling['day_rmse'], 1, '.', '') . ' W' : 'n/a';
$rollingDayBiasText = !empty($rolling['day_count']) ? number_format((float)$rolling['day_bias'], 1, '.', '') . ' W' : 'n/a';
$rollingWeekMaeText = !empty($rolling['week_count']) ? number_format((float)$rolling['week_mae'], 1, '.', '') . ' W' : 'n/a';
$rollingWeekRmseText = !empty($rolling['week_count']) ? number_format((float)$rolling['week_rmse'], 1, '.', '') . ' W' : 'n/a';
$rollingWeekBiasText = !empty($rolling['week_count']) ? number_format((float)$rolling['week_bias'], 1, '.', '') . ' W' : 'n/a';
$modelText = count($modelSet) > 0
  ? implode(', ', array_keys($modelSet))
  : ($latestBaselineRun ? ((string)$latestBaselineRun['model_name'] . ' ' . (string)$latestBaselineRun['model_version']) : 'n/a');
$issuedAtText = $latestIssuedAt !== null
  ? (new DateTimeImmutable('@' . (string)$latestIssuedAt))->setTimezone($tz)->format('Y-m-d H:i')
  : ($latestBaselineRun ? (new DateTimeImmutable((string)$latestBaselineRun['issued_at']))->setTimezone($tz)->format('Y-m-d H:i') : 'n/a');
$baselineHealthText = 'No baseline forecast run found';
if ($baselineHealth) {
  $ageText = number_format((float)$baselineHealth['age_min'], 0, '.', '') . ' min old';
  $coverageText = (string)$baselineHealth['future_6h_count'] . '/24 slots for next 6h';
  $baselineHealthText = $ageText . ' | ' . $coverageText;
}

header('Content-Type: text/html; charset=utf-8');
echo "<!doctype html>";
echo "<html lang=\"en\"><head><meta charset=\"utf-8\">";
echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">";
echo "<title>Home Consumption Actual</title>";
echo "<style>
:root{--bg:#f5f7fb;--card:#fff;--ink:#101624;--muted:#5f687a;--line:#d9dfeb;--brand:#0f6cbf}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
.wrap{max-width:820px;margin:0 auto;padding:14px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px;margin-bottom:10px}
h1{font-size:1.2rem;margin:.2rem 0 .5rem}
.meta{color:var(--muted);font-size:.88rem;line-height:1.45}
.kpi{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px}
.kpi .item{border:1px solid var(--line);border-radius:10px;padding:8px;background:#fafcff}
.kpi .label{font-size:.75rem;color:var(--muted)}
.kpi .value{font-size:1rem;font-weight:700}
a{color:var(--brand);text-decoration:none}
.tabs{display:flex;gap:12px;flex-wrap:wrap;font-size:.92rem}
.table-wrap{overflow:auto}
table{width:100%;border-collapse:collapse;min-width:400px}
th,td{padding:8px;border-bottom:1px solid var(--line);text-align:right;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.85rem}
th:first-child,td:first-child{text-align:left;font-family:inherit}
th{position:sticky;top:0;background:#f2f6ff;z-index:1}
@media (max-width:560px){
  .kpi{grid-template-columns:1fr}
}
</style>";
echo "</head><body><div class=\"wrap\">";

echo "<div class=\"card\">";
echo "<h1>Home Consumption Actual</h1>";
echo "<div class=\"tabs\"><a href=\"/\">Main dashboard</a><a href=\"?date=today\">Today</a><a href=\"?date=tomorrow\">Tomorrow</a></div>";
echo "<p class=\"meta\">Date: " . htmlspecialchars($startLocal->format('Y-m-d')) . " (Europe/Helsinki)</p>";
echo "<p class=\"meta\">Series: household consumption power excluding solar production and battery charging components.</p>";
echo "<p class=\"meta\">Component split uses canonical DB view `home_consumption_components_15min`: EV 4.5-8.5 kW excess, Sauna 9.0-13.0 kW excess, other residual events above 2.5 kW.</p>";
echo "<p class=\"meta\">Baseline forecast model(s): <strong>" . htmlspecialchars($modelText) . "</strong> | Runs used in selected day: " . count($runIdSet) . " | Newest issue time: " . htmlspecialchars($issuedAtText) . "</p>";
echo "<p class=\"meta\">Baseline forecast health: " . htmlspecialchars($baselineHealthText) . "</p>";
if ($latestBaselineRun && !empty($latestBaselineRun['notes'])) {
  echo "<p class=\"meta\">" . htmlspecialchars((string)$latestBaselineRun['notes']) . "</p>";
}

echo "<div class=\"kpi\">";
echo "<div class=\"item\"><div class=\"label\">Actual points in selected day</div><div class=\"value\">" . $actualPointCount . " / " . count($rows) . "</div></div>";
echo "<div class=\"item\"><div class=\"label\">Missing points</div><div class=\"value\">" . $missingPointCount . "</div></div>";
echo "<div class=\"item\"><div class=\"label\">Average consumption</div><div class=\"value\">" . htmlspecialchars($avgText) . "</div></div>";
echo "<div class=\"item\"><div class=\"label\">Peak consumption</div><div class=\"value\">" . htmlspecialchars($peakText) . "</div></div>";
echo "<div class=\"item\"><div class=\"label\">Total daily energy</div><div class=\"value\">" . htmlspecialchars($energyText) . "</div></div>";
echo "<div class=\"item\"><div class=\"label\">Baseline component</div><div class=\"value\">" . htmlspecialchars($baseEnergyText) . "</div></div>";
echo "<div class=\"item\"><div class=\"label\">EV charging</div><div class=\"value\">" . htmlspecialchars($evEnergyText) . "</div></div>";
echo "<div class=\"item\"><div class=\"label\">Sauna heating</div><div class=\"value\">" . htmlspecialchars($saunaEnergyText) . "</div></div>";
echo "<div class=\"item\"><div class=\"label\">Other load</div><div class=\"value\">" . htmlspecialchars($otherEnergyText) . "</div></div>";
echo "<div class=\"item\"><div class=\"label\">Forecast points in selected day</div><div class=\"value\">" . $forecastPointCount . " / " . count($rows) . "</div></div>";
echo "<div class=\"item\"><div class=\"label\">Selected day MAE</div><div class=\"value\">" . htmlspecialchars($maeText) . "</div></div>";
echo "<div class=\"item\"><div class=\"label\">Selected day RMSE</div><div class=\"value\">" . htmlspecialchars($rmseText) . "</div></div>";
echo "<div class=\"item\"><div class=\"label\">Selected day bias</div><div class=\"value\">" . htmlspecialchars($biasText) . "</div></div>";
echo "<div class=\"item\"><div class=\"label\">Rolling 24h MAE / RMSE</div><div class=\"value\">" . htmlspecialchars($rollingDayMaeText . ' / ' . $rollingDayRmseText) . "</div></div>";
echo "<div class=\"item\"><div class=\"label\">Rolling 24h bias</div><div class=\"value\">" . htmlspecialchars($rollingDayBiasText) . "</div></div>";
echo "<div class=\"item\"><div class=\"label\">Rolling 7d MAE / RMSE</div><div class=\"value\">" . htmlspecialchars($rollingWeekMaeText . ' / ' . $rollingWeekRmseText) . "</div></div>";
echo "<div class=\"item\"><div class=\"label\">Rolling 7d bias</div><div class=\"value\">" . htmlspecialchars($rollingWeekBiasText) . "</div></div>";
echo "</div>";
echo "</div>";

if (!$rows) {
  echo "<div class=\"card\"><p class=\"meta\">No rows found for this day.</p></div>";
  echo "</div></body></html>";
  exit;
}

echo "<div class=\"card table-wrap\">";
echo "<table><thead><tr><th>Time</th><th>Actual W</th><th>Base W</th><th>Base Fcst W</th><th>EV W</th><th>Sauna W</th><th>Other W</th></tr></thead><tbody>";
foreach ($rows as $row) {
  $tsLocal = (new DateTimeImmutable($row['target_ts']))->setTimezone($tz);
  $actual = $row['home_consumption_actual_w'] !== null ? (float)$row['home_consumption_actual_w'] : null;
  $base = $row['baseline_actual_w'] !== null ? (float)$row['baseline_actual_w'] : null;
  $baseForecast = $row['baseline_forecast_w'] !== null ? (float)$row['baseline_forecast_w'] : null;
  $ev = $row['ev_actual_w'] !== null ? (float)$row['ev_actual_w'] : null;
  $sauna = $row['sauna_actual_w'] !== null ? (float)$row['sauna_actual_w'] : null;
  $other = $row['other_actual_w'] !== null ? (float)$row['other_actual_w'] : null;
  $actualText = $actual === null ? '-' : number_format($actual, 0, '.', '');
  $baseText = $base === null ? '-' : number_format($base, 0, '.', '');
  $baseForecastText = $baseForecast === null ? '-' : number_format($baseForecast, 0, '.', '');
  $evText = $ev === null ? '-' : number_format($ev, 0, '.', '');
  $saunaText = $sauna === null ? '-' : number_format($sauna, 0, '.', '');
  $otherText = $other === null ? '-' : number_format($other, 0, '.', '');

  echo '<tr>';
  echo '<td>' . htmlspecialchars($tsLocal->format('H:i')) . '</td>';
  echo '<td>' . htmlspecialchars($actualText) . '</td>';
  echo '<td>' . htmlspecialchars($baseText) . '</td>';
  echo '<td>' . htmlspecialchars($baseForecastText) . '</td>';
  echo '<td>' . htmlspecialchars($evText) . '</td>';
  echo '<td>' . htmlspecialchars($saunaText) . '</td>';
  echo '<td>' . htmlspecialchars($otherText) . '</td>';
  echo '</tr>';
}
echo "</tbody></table></div>";

echo "</div></body></html>";
?>
