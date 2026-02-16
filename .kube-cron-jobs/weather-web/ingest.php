<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Use POST";
    exit;
}

if (!empty($_FILES['sqlite']['tmp_name'])) {
    $targetDir = '/var/inbox';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0775, true);
    }
    $target = $targetDir . '/' . basename($_FILES['sqlite']['name']);
    if (!move_uploaded_file($_FILES['sqlite']['tmp_name'], $target)) {
        http_response_code(500);
        echo "Failed to save upload";
        exit;
    }
    echo "Saved to inbox";
    exit;
}

$payload = file_get_contents('php://input');
$items = json_decode($payload, true);
if (!is_array($items)) {
    http_response_code(400);
    echo "Invalid JSON";
    exit;
}

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
$stmtWithId = $pdo->prepare("INSERT INTO weather (id, ts, temperature_c, dew_point_c, relative_humidity, pressure_hpa, wind_speed_ms, wind_direction_deg, precip_mmph, energy_today_wh, pv_feed_in_w, battery_soc_pct, active_power_pcc_w, bat_charge_w, bat_discharge_w, sma_json, merged_at, pushed_at) VALUES (:id, :ts, :temperature_c, :dew_point_c, :relative_humidity, :pressure_hpa, :wind_speed_ms, :wind_direction_deg, :precip_mmph, :energy_today_wh, :pv_feed_in_w, :battery_soc_pct, :active_power_pcc_w, :bat_charge_w, :bat_discharge_w, :sma_json, :merged_at, :pushed_at) ON CONFLICT (id) DO UPDATE SET ts=EXCLUDED.ts, temperature_c=EXCLUDED.temperature_c, dew_point_c=EXCLUDED.dew_point_c, relative_humidity=EXCLUDED.relative_humidity, pressure_hpa=EXCLUDED.pressure_hpa, wind_speed_ms=EXCLUDED.wind_speed_ms, wind_direction_deg=EXCLUDED.wind_direction_deg, precip_mmph=EXCLUDED.precip_mmph, energy_today_wh=EXCLUDED.energy_today_wh, pv_feed_in_w=EXCLUDED.pv_feed_in_w, battery_soc_pct=EXCLUDED.battery_soc_pct, active_power_pcc_w=EXCLUDED.active_power_pcc_w, bat_charge_w=EXCLUDED.bat_charge_w, bat_discharge_w=EXCLUDED.bat_discharge_w, sma_json=EXCLUDED.sma_json, merged_at=EXCLUDED.merged_at, pushed_at=EXCLUDED.pushed_at");
$stmtNoId = $pdo->prepare("INSERT INTO weather (ts, temperature_c, dew_point_c, relative_humidity, pressure_hpa, wind_speed_ms, wind_direction_deg, precip_mmph, energy_today_wh, pv_feed_in_w, battery_soc_pct, active_power_pcc_w, bat_charge_w, bat_discharge_w, sma_json, merged_at, pushed_at) VALUES (:ts, :temperature_c, :dew_point_c, :relative_humidity, :pressure_hpa, :wind_speed_ms, :wind_direction_deg, :precip_mmph, :energy_today_wh, :pv_feed_in_w, :battery_soc_pct, :active_power_pcc_w, :bat_charge_w, :bat_discharge_w, :sma_json, :merged_at, :pushed_at)");

$inserted = 0;
foreach ($items as $row) {
    if (!is_array($row)) {
        continue;
    }
    $payload = [
      ':ts' => $row['ts'] ?? null,
      ':temperature_c' => $row['temperature_c'] ?? null,
      ':dew_point_c' => $row['dew_point_c'] ?? null,
      ':relative_humidity' => $row['relative_humidity'] ?? null,
      ':pressure_hpa' => $row['pressure_hpa'] ?? null,
      ':wind_speed_ms' => $row['wind_speed_ms'] ?? null,
      ':wind_direction_deg' => $row['wind_direction_deg'] ?? null,
      ':precip_mmph' => $row['precip_mmph'] ?? null,
      ':energy_today_wh' => $row['energy_today_wh'] ?? null,
      ':pv_feed_in_w' => $row['pv_feed_in_w'] ?? null,
      ':battery_soc_pct' => $row['battery_soc_pct'] ?? null,
      ':active_power_pcc_w' => $row['active_power_pcc_w'] ?? null,
      ':bat_charge_w' => $row['bat_charge_w'] ?? null,
      ':bat_discharge_w' => $row['bat_discharge_w'] ?? null,
      ':sma_json' => isset($row['sma_json']) ? json_encode($row['sma_json']) : null,
      ':merged_at' => $row['merged_at'] ?? null,
      ':pushed_at' => $row['pushed_at'] ?? null,
    ];
    if (!empty($row['id'])) {
      $payload[':id'] = $row['id'];
      $stmtWithId->execute($payload);
    } else {
      $stmtNoId->execute($payload);
    }
    $inserted++;
}

echo "Inserted {$inserted} rows";
?>
