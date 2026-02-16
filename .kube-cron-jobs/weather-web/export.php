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
  "SELECT ts, price_eur_per_mwh, temperature_c, wind_speed_ms, wind_direction_deg, cloud_cover_pct, shortwave_radiation_w_m2 FROM weather_fusion WHERE ts >= :start AND ts < :end ORDER BY ts ASC"
);
$stmt->execute([':start' => $startUtc, ':end' => $endUtc]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="weather_fusion_' . $startLocal->format('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['timestamp_utc', 'timestamp_local', 'price_eur_per_mwh', 'price_cent_per_kwh_incl_margin_vat', 'temperature_c', 'wind_speed_ms', 'wind_direction_deg', 'cloud_cover_pct', 'shortwave_radiation_w_m2']);

foreach ($rows as $row) {
    $tsLocal = (new DateTimeImmutable($row['ts']))->setTimezone($tz);
    $price = $row['price_eur_per_mwh'];
    $priceConverted = null;
    if ($price !== null) {
      $wholesaleCentPerKwh = ((float)$price) * 0.1;
      $withMargin = $wholesaleCentPerKwh + 0.46;
      $priceConverted = $withMargin * 1.255;
    }
    
    $temp = $row['temperature_c'] !== null ? number_format((float)$row['temperature_c'], 1, '.', '') : '';
    $wind = $row['wind_speed_ms'] !== null ? number_format((float)$row['wind_speed_ms'], 1, '.', '') : '';
    $dir = $row['wind_direction_deg'] !== null ? number_format((float)$row['wind_direction_deg'], 0, '.', '') : '';
    $cloud = $row['cloud_cover_pct'] !== null ? number_format((float)$row['cloud_cover_pct'], 0, '.', '') : '';
    $rad = $row['shortwave_radiation_w_m2'] !== null ? number_format((float)$row['shortwave_radiation_w_m2'], 0, '.', '') : '';
    
    fputcsv($out, [
      $row['ts'],
      $tsLocal->format('Y-m-d H:i:s'),
      $price,
      $priceConverted,
      $temp,
      $wind,
      $dir,
      $cloud,
      $rad
    ]);
}
fclose($out);
?>
