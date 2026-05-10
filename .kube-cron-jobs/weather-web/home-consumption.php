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

$stmt = $pdo->prepare(
  "WITH latest_baseline_run AS (
     SELECT MAX(run_id) AS run_id
     FROM forecast_value
     WHERE target = 'baseline_w'
   ),
   slots AS (
     SELECT generate_series(
       :start::timestamptz,
       (:end::timestamptz - interval '15 minutes'),
       interval '15 minutes'
     ) AS target_ts
   )
   SELECT
     s.target_ts,
     c.home_consumption_actual_w,
     c.baseline_actual_w,
     fv.yhat_p50 AS baseline_forecast_w,
     c.ev_actual_w,
     c.sauna_actual_w,
     c.other_actual_w
   FROM slots s
   LEFT JOIN home_consumption_components_15min c ON c.ts = s.target_ts
   LEFT JOIN latest_baseline_run lr ON true
   LEFT JOIN forecast_value fv
     ON fv.target_ts = s.target_ts
    AND fv.target = 'baseline_w'
    AND fv.run_id = lr.run_id
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
$peakW = null;
foreach ($rows as $row) {
  if ($row['home_consumption_actual_w'] === null) {
    $missingPointCount++;
    continue;
  }
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
}

$avgText = $actualPointCount > 0 ? number_format($sumW / $actualPointCount, 0, '.', '') . ' W' : 'n/a';
$peakText = $peakW !== null ? number_format($peakW, 0, '.', '') . ' W' : 'n/a';
$energyText = number_format($sumW / 4000.0, 2, '.', '') . ' kWh';
$baseEnergyText = number_format($sumBaseW / 4000.0, 2, '.', '') . ' kWh';
$evEnergyText = number_format($sumEvW / 4000.0, 2, '.', '') . ' kWh';
$saunaEnergyText = number_format($sumSaunaW / 4000.0, 2, '.', '') . ' kWh';
$otherEnergyText = number_format($sumOtherW / 4000.0, 2, '.', '') . ' kWh';

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
