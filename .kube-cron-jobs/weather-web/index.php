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

$pdo->exec("CREATE TABLE IF NOT EXISTS weather (id BIGSERIAL PRIMARY KEY, ts TIMESTAMPTZ DEFAULT now(), temperature_c DOUBLE PRECISION NULL, dew_point_c DOUBLE PRECISION NULL, relative_humidity DOUBLE PRECISION NULL, pressure_hpa DOUBLE PRECISION NULL, wind_speed_ms DOUBLE PRECISION NULL, wind_direction_deg DOUBLE PRECISION NULL, precip_mmph DOUBLE PRECISION NULL, energy_today_wh BIGINT NULL, pv_feed_in_w INTEGER NULL, battery_soc_pct INTEGER NULL, active_power_pcc_w INTEGER NULL, bat_charge_w INTEGER NULL, bat_discharge_w INTEGER NULL, sma_json JSONB NULL, merged_at TIMESTAMPTZ NULL, pushed_at TIMESTAMPTZ NULL)");
$pdo->exec("CREATE TABLE IF NOT EXISTS entsoe_prices (ts TIMESTAMPTZ PRIMARY KEY, price_eur_per_mwh DOUBLE PRECISION NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(), CONSTRAINT entsoe_prices_ts_15m CHECK (date_trunc('minute', ts) = ts AND date_part('minute', ts) IN (0, 15, 30, 45)) )");

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
  "SELECT ts, price_eur_per_mwh, temperature_c, wind_speed_ms, wind_direction_deg, cloud_cover_pct, shortwave_radiation_w_m2, price_updated_at FROM weather_fusion WHERE ts >= :start AND ts < :end ORDER BY ts ASC"
);
$stmt->execute([':start' => $startUtc, ':end' => $endUtc]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/html; charset=utf-8');
echo "<!doctype html>";
echo "<html lang=\"en\"><head><meta charset=\"utf-8\">";
echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">";
echo "<title>Electricity & Weather Fusion (Finland)</title>";
echo "<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,sans-serif;margin:2rem;color:#111}table{border-collapse:collapse;width:100%;max-width:1200px}th,td{padding:.5rem .75rem;border-bottom:1px solid #ddd;text-align:left}th{background:#f6f6f6}caption{caption-side:top;text-align:left;font-weight:600;margin-bottom:.5rem}td.num{text-align:right}</style>";
echo "</head><body>";
echo "<h1>Electricity & Weather Fusion (Finland)</h1>";
$todayLink = '?date=today';
$tomorrowLink = '?date=tomorrow';
echo "<p>Date: " . htmlspecialchars($startLocal->format('Y-m-d')) . " (Europe/Helsinki)</p>";
echo "<p>Toggle: <a href=\"{$todayLink}\">Today</a> | <a href=\"{$tomorrowLink}\">Tomorrow</a></p>";

if (!$rows) {
  echo "<p>No data found for this day.</p>";
} else {
  echo "<table><caption>Electricity prices with interpolated weather forecast (15-min resolution)</caption>";
  echo "<thead><tr><th>Time</th><th>Price (cent/kWh)</th><th>Temp (°C)</th><th>Wind (m/s)</th><th>Dir (°)</th><th>Cloud (%)</th><th>Rad (W/m²)</th></tr></thead><tbody>";
  foreach ($rows as $row) {
    $tsLocal = (new DateTimeImmutable($row['ts']))->setTimezone($tz);
    $price = $row['price_eur_per_mwh'];
    if ($price === null) {
      $priceText = '-';
    } else {
      $wholesaleCentPerKwh = ((float)$price) * 0.1;
      $withMargin = $wholesaleCentPerKwh + 0.46;
      $withVat = $withMargin * 1.255;
      $priceText = number_format($withVat, 1, '.', '');
    }
    
    $temp = $row['temperature_c'];
    $wind = $row['wind_speed_ms'];
    $dir = $row['wind_direction_deg'];
    $cloud = $row['cloud_cover_pct'];
    $rad = $row['shortwave_radiation_w_m2'];
    
    $tempText = $temp === null ? '-' : number_format((float)$temp, 1, '.', '');
    $windText = $wind === null ? '-' : number_format((float)$wind, 1, '.', '');
    $dirText = $dir === null ? '-' : number_format((float)$dir, 0, '.', '');
    $cloudText = $cloud === null ? '-' : number_format((float)$cloud, 0, '.', '');
    $radText = $rad === null ? '-' : number_format((float)$rad, 0, '.', '');
    
    echo "<tr>";
    echo "<td>" . htmlspecialchars($tsLocal->format('H:i')) . "</td>";
    echo "<td class=\"num\">" . htmlspecialchars($priceText) . "</td>";
    echo "<td class=\"num\">" . htmlspecialchars($tempText) . "</td>";
    echo "<td class=\"num\">" . htmlspecialchars($windText) . "</td>";
    echo "<td class=\"num\">" . htmlspecialchars($dirText) . "</td>";
    echo "<td class=\"num\">" . htmlspecialchars($cloudText) . "</td>";
    echo "<td class=\"num\">" . htmlspecialchars($radText) . "</td>";
    echo "</tr>";
  }
  echo "</tbody></table>";
}

echo "<p style=\"margin-top:1rem;color:#555\">POST JSON to /ingest.php or upload a sqlite file using field name 'sqlite'.</p>";
echo "<p style=\"color:#555\">Export data: <a href=\"/export.php\">CSV (today)</a> | <a href=\"/export.php?date=tomorrow\">CSV (tomorrow)</a></p>";
echo "</body></html>";
?>
