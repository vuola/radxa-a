<?php
header('Content-Type: application/json');

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

$result = [
  'overall_status' => 'ok',
  'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
  'failures' => []
];

// 1. Database connectivity
try {
  $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
  $pdo->query('SELECT 1')->fetch();
} catch (Throwable $e) {
  $result['overall_status'] = 'error';
  $result['failures'][] = [
    'check' => 'database',
    'status' => 'error',
    'message' => 'Database connection failed'
  ];
  echo json_encode($result, JSON_PRETTY_PRINT);
  exit;
}

// 2. Moxa 15min freshness
try {
  $stmt = $pdo->query("SELECT EXTRACT(EPOCH FROM (now() - MAX(ts)))/60 AS age_min FROM moxa_weather_15min");
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  $age_min = $row ? (float)$row['age_min'] : 999999;
  
  if ($age_min > 60) {
    $result['overall_status'] = 'error';
    $result['failures'][] = [
      'check' => 'moxa_15min',
      'status' => 'error',
      'message' => sprintf('Moxa data stale (%.0f min old, expected ≤60)', $age_min)
    ];
  } elseif ($age_min > 20) {
    if ($result['overall_status'] === 'ok') $result['overall_status'] = 'warn';
    $result['failures'][] = [
      'check' => 'moxa_15min',
      'status' => 'warn',
      'message' => sprintf('Moxa data aging (%.0f min old, expected ≤20)', $age_min)
    ];
  }
} catch (Throwable $e) {
  $result['overall_status'] = 'error';
  $result['failures'][] = [
    'check' => 'moxa_15min',
    'status' => 'error',
    'message' => 'Failed to check moxa_weather_15min'
  ];
}

// 3. FMI forecast freshness (should cover now + 6h)
try {
  $stmt = $pdo->query("SELECT COUNT(*) AS future_count FROM fmi_forecast WHERE ts > now() + interval '6 hours'");
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  $future_count = $row ? (int)$row['future_count'] : 0;
  
  if ($future_count < 1) {
    if ($result['overall_status'] === 'ok') $result['overall_status'] = 'warn';
    $result['failures'][] = [
      'check' => 'fmi_forecast',
      'status' => 'warn',
      'message' => 'FMI forecast does not cover +6h horizon'
    ];
  }
} catch (Throwable $e) {
  $result['overall_status'] = 'error';
  $result['failures'][] = [
    'check' => 'fmi_forecast',
    'status' => 'error',
    'message' => 'Failed to check fmi_forecast'
  ];
}

// 4. ENTSOE prices freshness (should cover current day)
try {
  $stmt = $pdo->query("SELECT COUNT(*) AS today_count FROM entsoe_prices WHERE ts >= date_trunc('day', now()) AND ts < date_trunc('day', now()) + interval '1 day'");
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  $today_count = $row ? (int)$row['today_count'] : 0;
  
  if ($today_count < 24) {
    if ($result['overall_status'] === 'ok') $result['overall_status'] = 'warn';
    $result['failures'][] = [
      'check' => 'entsoe_prices',
      'status' => 'warn',
      'message' => sprintf('ENTSOE prices incomplete today (%d/96 rows)', $today_count)
    ];
  }
} catch (Throwable $e) {
  $result['overall_status'] = 'error';
  $result['failures'][] = [
    'check' => 'entsoe_prices',
    'status' => 'error',
    'message' => 'Failed to check entsoe_prices'
  ];
}

// 5. Backup validity
$backup_dir = '/media/ssd250/weather/backups';
$backup_files = glob($backup_dir . '/weather_*.sql');
if ($backup_files) {
  usort($backup_files, function($a, $b) { return filemtime($b) - filemtime($a); });
  $latest = $backup_files[0];
  $age_hours = (time() - filemtime($latest)) / 3600;
  $size_kb = filesize($latest) / 1024;
  
  if ($age_hours > 26 || $size_kb < 100) {
    $result['overall_status'] = 'error';
    $msg = [];
    if ($age_hours > 26) $msg[] = sprintf('%.1fh old', $age_hours);
    if ($size_kb < 100) $msg[] = sprintf('only %.0fKB', $size_kb);
    $result['failures'][] = [
      'check' => 'backup',
      'status' => 'error',
      'message' => 'Backup invalid: ' . implode(', ', $msg)
    ];
  }
} else {
  $result['overall_status'] = 'error';
  $result['failures'][] = [
    'check' => 'backup',
    'status' => 'error',
    'message' => 'No backup files found'
  ];
}

// 6. Parquet freshness
$parquet_dir = '/media/ssd250/weather/exports';
$parquet_files = glob($parquet_dir . '/weather_fusion_*.parquet');
if ($parquet_files) {
  usort($parquet_files, function($a, $b) { return filemtime($b) - filemtime($a); });
  $latest = $parquet_files[0];
  $age_min = (time() - filemtime($latest)) / 60;
  
  if ($age_min > 30) {
    if ($result['overall_status'] === 'ok') $result['overall_status'] = 'warn';
    $result['failures'][] = [
      'check' => 'parquet',
      'status' => 'warn',
      'message' => sprintf('Parquet export stale (%.0f min old, expected ≤30)', $age_min)
    ];
  }
} else {
  if ($result['overall_status'] === 'ok') $result['overall_status'] = 'warn';
  $result['failures'][] = [
    'check' => 'parquet',
    'status' => 'warn',
    'message' => 'No parquet files found'
  ];
}

echo json_encode($result, JSON_PRETTY_PRINT);
?>
