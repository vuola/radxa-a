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
  "SELECT ts, price_eur_per_mwh, fc_temperature_c, moxa_temperature_c, fc_wind_speed_ms, moxa_wind_speed_ms, fc_wind_direction_deg, moxa_wind_direction_deg, fc_cloud_cover_pct, fc_shortwave_radiation_w_m2, moxa_pv_feed_in_w, moxa_active_power_pcc_w, moxa_battery_soc_pct FROM weather_fusion WHERE ts >= :start AND ts < :end ORDER BY ts ASC"
);
$stmt->execute([':start' => $startUtc, ':end' => $endUtc]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/html; charset=utf-8');
echo "<!doctype html>";
echo "<html lang=\"en\"><head><meta charset=\"utf-8\">";
echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">";
echo "<title>Electricity & Weather Fusion (Finland)</title>";
echo "<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,sans-serif;margin:2rem;color:#111}table{border-collapse:collapse;width:100%;max-width:1600px}th,td{padding:.4rem .6rem;border-bottom:1px solid #ddd;text-align:center}th{background:#f6f6f6;font-size:.85rem}td{font-family:monospace;font-size:.9rem}.legend{margin:1.5rem 0;padding:1rem;background:#f9f9f9;border-left:3px solid #666;max-width:1600px}.legend h3{margin:0 0 .5rem;font-size:1rem}.legend ol{margin:.5rem 0;padding-left:1.5rem;line-height:1.6;font-size:.9rem}</style>";
echo "</head><body>";
echo "<h1>Electricity & Weather Fusion (Finland)</h1>";
$todayLink = '?date=today';
$tomorrowLink = '?date=tomorrow';
echo "<p>Date: " . htmlspecialchars($startLocal->format('Y-m-d')) . " (Europe/Helsinki)</p>";
echo "<p>Toggle: <a href=\"{$todayLink}\">Today</a> | <a href=\"{$tomorrowLink}\">Tomorrow</a></p>";

echo "<div class=\"legend\"><h3>Column Legend</h3><ol>";
echo "<li>Time (HH:MM)</li>";
echo "<li>Price (cent/kWh, incl. margin & VAT)</li>";
echo "<li>Forecast Temperature (°C)</li>";
echo "<li>Measured Temperature (°C)</li>";
echo "<li>Forecast Wind Speed (m/s)</li>";
echo "<li>Measured Wind Speed (m/s)</li>";
echo "<li>Forecast Wind Direction (°)</li>";
echo "<li>Measured Wind Direction (°)</li>";
echo "<li>Forecast Cloud Cover (%)</li>";
echo "<li>Forecast Solar Radiation (kW/m²)</li>";
echo "<li>PV Feed-in Power (W)</li>";
echo "<li>Active Power at PCC (W)</li>";
echo "<li>Battery SOC (%)</li>";
echo "</ol></div>";

if (!$rows) {
  echo "<p>No data found for this day.</p>";
} else {
  echo "<table>";
  echo "<thead><tr><th>1</th><th>2</th><th>3</th><th>4</th><th>5</th><th>6</th><th>7</th><th>8</th><th>9</th><th>10</th><th>11</th><th>12</th><th>13</th></tr></thead><tbody>";
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
    
    $fcTemp = $row['fc_temperature_c'];
    $moxaTemp = $row['moxa_temperature_c'];
    $fcWind = $row['fc_wind_speed_ms'];
    $moxaWind = $row['moxa_wind_speed_ms'];
    $fcDir = $row['fc_wind_direction_deg'];
    $moxaDir = $row['moxa_wind_direction_deg'];
    $fcCloud = $row['fc_cloud_cover_pct'];
    $fcRad = $row['fc_shortwave_radiation_w_m2'];
    $pvFeed = $row['moxa_pv_feed_in_w'];
    $activePower = $row['moxa_active_power_pcc_w'];
    $batterySoc = $row['moxa_battery_soc_pct'];
    
    $fcTempText = $fcTemp === null ? '-' : number_format((float)$fcTemp, 1, '.', '');
    $moxaTempText = $moxaTemp === null ? '-' : number_format((float)$moxaTemp, 1, '.', '');
    $fcWindText = $fcWind === null ? '-' : number_format((float)$fcWind, 1, '.', '');
    $moxaWindText = $moxaWind === null ? '-' : number_format((float)$moxaWind, 1, '.', '');
    $fcDirText = $fcDir === null ? '-' : number_format((float)$fcDir, 0, '.', '');
    $moxaDirText = $moxaDir === null ? '-' : number_format((float)$moxaDir, 0, '.', '');
    $fcCloudText = $fcCloud === null ? '-' : number_format((float)$fcCloud, 0, '.', '');
    $fcRadText = $fcRad === null ? '-' : number_format((float)$fcRad / 1000, 1, '.', '');
    $pvFeedText = $pvFeed === null ? '-' : number_format((float)$pvFeed, 0, '.', '');
    $activePowerText = $activePower === null ? '-' : number_format((float)$activePower, 0, '.', '');
    $batterySocText = $batterySoc === null ? '-' : number_format((float)$batterySoc, 0, '.', '');
    
    echo "<tr>";
    echo "<td>" . htmlspecialchars($tsLocal->format('H:i')) . "</td>";
    echo "<td>" . htmlspecialchars($priceText) . "</td>";
    echo "<td>" . htmlspecialchars($fcTempText) . "</td>";
    echo "<td>" . htmlspecialchars($moxaTempText) . "</td>";
    echo "<td>" . htmlspecialchars($fcWindText) . "</td>";
    echo "<td>" . htmlspecialchars($moxaWindText) . "</td>";
    echo "<td>" . htmlspecialchars($fcDirText) . "</td>";
    echo "<td>" . htmlspecialchars($moxaDirText) . "</td>";
    echo "<td>" . htmlspecialchars($fcCloudText) . "</td>";
    echo "<td>" . htmlspecialchars($fcRadText) . "</td>";
    echo "<td>" . htmlspecialchars($pvFeedText) . "</td>";
    echo "<td>" . htmlspecialchars($activePowerText) . "</td>";
    echo "<td>" . htmlspecialchars($batterySocText) . "</td>";
    echo "</tr>";
  }
  echo "</tbody></table>";
}

echo "<p style=\"margin-top:1.5rem;color:#555\">POST JSON to /ingest.php or upload a sqlite file using field name 'sqlite'.</p>";
echo "<p style=\"color:#555\">Export data: <a href=\"/export.php\">CSV (today)</a> | <a href=\"/export.php?date=tomorrow\">CSV (tomorrow)</a></p>";
echo "</body></html>";
?>
