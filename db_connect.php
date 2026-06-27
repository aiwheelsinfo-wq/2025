<?php
header("Content-Type: application/json");

// Disable error displaying and ignore deprecations/notices to prevent JSON corruption
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', 0);

// Set PHP timezone
date_default_timezone_set('Asia/Kolkata');

// Database credentials
$host = "localhost";
$dbname = "agnicar2025";
$username = "agnicar";
$password = "dGwW(W8b237~";

// Create connection
$conn = mysqli_connect($host, $username, $password, $dbname);

// Check connection
if (!$conn) {
    echo json_encode([
        "success" => false,
        "message" => "Database connection failed: " . mysqli_connect_error()
    ]);
    exit;
}

// Set MySQL connection charset to utf8mb4
mysqli_set_charset($conn, "utf8mb4");

// Set MySQL timezone
if (!mysqli_query($conn, "SET time_zone = '+05:30'")) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to set timezone: " . mysqli_error($conn)
    ]);
    exit;
}

// Run database migrations automatically on application startup
require_once __DIR__ . '/MigrationRunner.php';
MigrationRunner::run($conn);
?>