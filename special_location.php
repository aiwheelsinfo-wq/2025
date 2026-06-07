<?php
header('Content-Type: application/json');

// Enable error reporting (for debugging)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include your DB connection
include 'db_connect.php';

if (!$conn) {
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Get parameters from GET request
$fromLat = isset($_GET['fromLat']) ? floatval($_GET['fromLat']) : null;
$fromLon = isset($_GET['fromLon']) ? floatval($_GET['fromLon']) : null;
$toLat   = isset($_GET['toLat']) ? floatval($_GET['toLat']) : null;
$toLon   = isset($_GET['toLon']) ? floatval($_GET['toLon']) : null;

if ($fromLat === null || $fromLon === null || $toLat === null || $toLon === null) {
    echo json_encode(['error' => 'Missing coordinates']);
    exit;
}

$sql = "SELECT tripType FROM special_routes
        WHERE ? BETWEEN minLat_from AND maxLat_from
          AND ? BETWEEN minLon_from AND maxLon_from
          AND ? BETWEEN minLat_to AND maxLat_to
          AND ? BETWEEN minLon_to AND maxLon_to
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("dddd", $fromLat, $fromLon, $toLat, $toLon);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo json_encode(['isSpecial' => true, 'tripType' => $row['tripType']]);
} else {
    echo json_encode(['isSpecial' => false]);
}

$stmt->close();
$conn->close();
?>
