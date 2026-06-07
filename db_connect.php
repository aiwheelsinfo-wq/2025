<?php
header("Content-Type: application/json");

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

// Set MySQL timezone
if (!mysqli_query($conn, "SET time_zone = '+05:30'")) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to set timezone: " . mysqli_error($conn)
    ]);
    exit;
}
?>