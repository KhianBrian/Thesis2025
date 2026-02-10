<?php
// Load .env if present (LOCAL ONLY)
$envPath = __DIR__ . "/.env";

if (file_exists($envPath)) {
  foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), "#")) continue;
    putenv(trim($line));
  }
}

$host = getenv("DB_HOST");
$port = getenv("DB_PORT") ?: "5432";
$dbname = getenv("DB_NAME");
$user = getenv("DB_USER");
$password = getenv("DB_PASSWORD");

if (!$host || !$dbname || !$user || !$password) {
  http_response_code(500);
  echo json_encode(["error" => "Database environment variables not set"]);
  exit;
}

try {
  $pdo = new PDO(
    "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require",
    $user,
    $password,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
  );
} catch (PDOException $e) {
  http_response_code(500);
  echo json_encode(["error" => "Database connection failed"]);
  exit;
}
