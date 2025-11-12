<?php
// public/api/config.php

// --------------- Autoload (Stripe SDK via Composer) ---------------
$autoload = __DIR__ . '/../../vendor/autoload.php';
if (!file_exists($autoload)) {
  http_response_code(500);
  header('Content-Type: application/json');
  echo json_encode(['error' => 'Composer autoload not found. Run `composer require stripe/stripe-php`.']);
  exit;
}
require_once $autoload;

// --------------- Load .env (flat INI style) ---------------
$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
  $vars = parse_ini_file($envFile, false, INI_SCANNER_RAW);
  if (is_array($vars)) {
    foreach ($vars as $k => $v) {
      // Keep both $_ENV and putenv for compatibility
      $_ENV[$k] = $v;
      putenv("$k=$v");
    }
  }
}

// --------------- Small helpers ---------------
function env($key, $default = null) {
  return $_ENV[$key] ?? getenv($key) ?: $default;
}

function json_response($data, int $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function json_input(): array {
  $raw = file_get_contents('php://input') ?: '';
  if ($raw === '') return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

// --------------- Error visibility ---------------
$debug = filter_var(env('APP_DEBUG', 'true'), FILTER_VALIDATE_BOOLEAN);
if ($debug) {
  ini_set('display_errors', '1');
  error_reporting(E_ALL);
} else {
  ini_set('display_errors', '0');
  error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
}

// --------------- Timezone ---------------
date_default_timezone_set(env('APP_TZ', 'Asia/Manila'));

// --------------- CORS (safe defaults for local dev) ---------------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowOrigins = [
  'http://localhost',
  'http://127.0.0.1',
  env('APP_URL', 'http://localhost/fili-booking'),
];
if ($origin && in_array($origin, $allowOrigins, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header('Vary: Origin');
  header('Access-Control-Allow-Credentials: true');
  header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
  header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

// --------------- Database (PDO, MySQL) ---------------
try {
  $dbHost = env('DB_HOST', '127.0.0.1');
  $dbPort = (int) env('DB_PORT', 3306);
  $dbName = env('DB_NAME', 'fili');
  $dbUser = env('DB_USER', 'root');
  $dbPass = env('DB_PASS', '');

  $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
  $pdo = new PDO($dsn, $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
  ]);
} catch (Throwable $e) {
  json_response(['error' => 'DB connection failed', 'detail' => $debug ? $e->getMessage() : null], 500);
}

// --------------- Stripe SDK init ---------------
try {
  \Stripe\Stripe::setApiKey(env('STRIPE_SECRET', 'sk_test_xxx'));
} catch (Throwable $e) {
  json_response(['error' => 'Stripe init failed', 'detail' => $debug ? $e->getMessage() : null], 500);
}

// --------------- Default response header ---------------
header('Content-Type: application/json; charset=utf-8');

// --------------- Optional: common guard (POST-only endpoints) ---------------
// Uncomment in files that include config.php if needed:
// if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
//   json_response(['error' => 'Method not allowed'], 405);
// }

// --------------- You now have: $pdo, env(), json_input(), json_response() ---------------
