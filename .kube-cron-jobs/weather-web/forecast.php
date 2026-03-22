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

$pdo->exec("CREATE TABLE IF NOT EXISTS forecast_run (
  run_id BIGSERIAL PRIMARY KEY,
  model_name TEXT NOT NULL,
  model_version TEXT NOT NULL,
  target TEXT NOT NULL,
  issued_at TIMESTAMPTZ NOT NULL,
  trained_from_ts TIMESTAMPTZ NULL,
  trained_to_ts TIMESTAMPTZ NULL,
  notes TEXT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS forecast_value (
  run_id BIGINT NOT NULL REFERENCES forecast_run(run_id) ON DELETE CASCADE,
  target TEXT NOT NULL,
  target_ts TIMESTAMPTZ NOT NULL,
  horizon_min INTEGER NOT NULL,
  yhat_p50 DOUBLE PRECISION NULL,
  yhat_p10 DOUBLE PRECISION NULL,
  yhat_p90 DOUBLE PRECISION NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (run_id, target_ts)
)");

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
   WHERE target = 'pv_feed_in_w'
   ORDER BY issued_at DESC
   LIMIT 1"
);
$latestRun = $runStmt->fetch(PDO::FETCH_ASSOC);

$rows = [];
if ($latestRun) {
  $stmt = $pdo->prepare(
    "SELECT
       fv.target_ts,
       fv.horizon_min,
       fv.yhat_p50,
       fv.yhat_p10,
       fv.yhat_p90,
       wf.moxa_pv_feed_in_w AS actual_pv_feed_in_w
     FROM forecast_value fv
     LEFT JOIN weather_fusion wf ON wf.ts = fv.target_ts
     WHERE fv.run_id = :run_id
       AND fv.target_ts >= :start
       AND fv.target_ts < :end
     ORDER BY fv.target_ts ASC"
  );
  $stmt->execute([
    ':run_id' => $latestRun['run_id'],
    ':start' => $startUtc,
    ':end' => $endUtc,
  ]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$errorCount = 0;
$mae = 0.0;
foreach ($rows as $row) {
  if ($row['actual_pv_feed_in_w'] === null || $row['yhat_p50'] === null) {
    continue;
  }
  $mae += abs((float)$row['actual_pv_feed_in_w'] - (float)$row['yhat_p50']);
  $errorCount++;
}
$maeText = $errorCount > 0 ? number_format($mae / $errorCount, 1, '.', '') . ' W' : 'n/a';

header('Content-Type: text/html; charset=utf-8');
echo "<!doctype html>";
echo "<html lang=\"en\"><head><meta charset=\"utf-8\">";
echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">";
echo "<title>PV Forecast vs Actual</title>";
echo "<style>
:root{--bg:#f5f7fb;--card:#fff;--ink:#101624;--muted:#5f687a;--line:#d9dfeb;--brand:#0f6cbf;--ok:#0b8f59;--warn:#a96300}
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
table{width:100%;border-collapse:collapse;min-width:560px}
th,td{padding:8px;border-bottom:1px solid var(--line);text-align:right;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.85rem}
th:first-child,td:first-child{text-align:left;font-family:inherit}
th{position:sticky;top:0;background:#f2f6ff;z-index:1}
.err-good{color:var(--ok)}
.err-bad{color:var(--warn)}
@media (max-width:560px){
  .kpi{grid-template-columns:1fr}
}
</style>";
echo "</head><body><div class=\"wrap\">";

echo "<div class=\"card\">";
echo "<h1>PV Forecast vs Actual</h1>";
echo "<div class=\"tabs\"><a href=\"/\">Main dashboard</a><a href=\"?date=today\">Today</a><a href=\"?date=tomorrow\">Tomorrow</a></div>";
echo "<p class=\"meta\">Date: " . htmlspecialchars($startLocal->format('Y-m-d')) . " (Europe/Helsinki)</p>";

if (!$latestRun) {
  echo "<p class=\"meta\">No forecast runs found yet. Run <code>pv_forecast_baseline.py</code> to generate the first run.</p>";
  echo "</div></div></body></html>";
  exit;
}

$issuedAtLocal = (new DateTimeImmutable($latestRun['issued_at']))->setTimezone($tz)->format('Y-m-d H:i');
echo "<p class=\"meta\">Model: <strong>" . htmlspecialchars((string)$latestRun['model_name']) . "</strong> " . htmlspecialchars((string)$latestRun['model_version']) . " | Run: " . htmlspecialchars((string)$latestRun['run_id']) . " | Issued: " . htmlspecialchars($issuedAtLocal) . "</p>";
if (!empty($latestRun['notes'])) {
  echo "<p class=\"meta\">" . htmlspecialchars((string)$latestRun['notes']) . "</p>";
}

echo "<div class=\"kpi\">";
echo "<div class=\"item\"><div class=\"label\">Points in selected day</div><div class=\"value\">" . count($rows) . "</div></div>";
echo "<div class=\"item\"><div class=\"label\">MAE (where actual exists)</div><div class=\"value\">" . htmlspecialchars($maeText) . "</div></div>";
echo "</div>";
echo "</div>";

if (!$rows) {
  echo "<div class=\"card\"><p class=\"meta\">No forecast rows found for this day in the latest run.</p></div>";
  echo "</div></body></html>";
  exit;
}

echo "<div class=\"card table-wrap\">";
echo "<table><thead><tr><th>Time</th><th>Forecast W</th><th>Actual W</th><th>Error W</th><th>P10..P90 W</th></tr></thead><tbody>";
foreach ($rows as $row) {
  $tsLocal = (new DateTimeImmutable($row['target_ts']))->setTimezone($tz);
  $yhat = $row['yhat_p50'] !== null ? (float)$row['yhat_p50'] : null;
  $actual = $row['actual_pv_feed_in_w'] !== null ? (float)$row['actual_pv_feed_in_w'] : null;
  $p10 = $row['yhat_p10'] !== null ? (float)$row['yhat_p10'] : null;
  $p90 = $row['yhat_p90'] !== null ? (float)$row['yhat_p90'] : null;

  $yhatText = $yhat === null ? '-' : number_format($yhat, 0, '.', '');
  $actualText = $actual === null ? '-' : number_format($actual, 0, '.', '');

  $errorText = '-';
  $errorClass = '';
  if ($yhat !== null && $actual !== null) {
    $err = $actual - $yhat;
    $errorText = number_format($err, 0, '.', '');
    $errorClass = abs($err) <= 300.0 ? 'err-good' : 'err-bad';
  }

  $bandText = '-';
  if ($p10 !== null && $p90 !== null) {
    $bandText = number_format($p10, 0, '.', '') . ' .. ' . number_format($p90, 0, '.', '');
  }

  echo '<tr>';
  echo '<td>' . htmlspecialchars($tsLocal->format('H:i')) . '</td>';
  echo '<td>' . htmlspecialchars($yhatText) . '</td>';
  echo '<td>' . htmlspecialchars($actualText) . '</td>';
  echo '<td class="' . htmlspecialchars($errorClass) . '">' . htmlspecialchars($errorText) . '</td>';
  echo '<td>' . htmlspecialchars($bandText) . '</td>';
  echo '</tr>';
}
echo "</tbody></table></div>";

echo "</div></body></html>";
?>
