<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

error_reporting(0);
ini_set('display_errors', 0);

if (file_exists('db_connect.php')) {
    include 'db_connect.php';
} else if (file_exists('../db_connect.php')) {
    include '../db_connect.php';
} else if (file_exists('../../2025/db_connect.php')) {
    include '../../2025/db_connect.php';
}

$driver_id = $_POST['driver_id'] ?? $_POST['phone_number'] ?? $_GET['driver_id'] ?? $_GET['phone_number'] ?? '';
$latitude = $_POST['latitude'] ?? $_GET['latitude'] ?? null;
$longitude = $_POST['longitude'] ?? $_GET['longitude'] ?? null;

if (empty($driver_id) || $latitude === null || $longitude === null) {
    echo json_encode([
        'status' => 'error',
        'message' => 'driver_id (phone number), latitude, and longitude are required'
    ]);
    exit;
}

$lat = floatval($latitude);
$lng = floatval($longitude);

$stmt = $conn->prepare("UPDATE drivers SET latitude = ?, longitude = ? WHERE phone_number = ?");
if ($stmt) {
    $stmt->bind_param("dds", $lat, $lng, $driver_id);
    if ($stmt->execute()) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Driver location updated successfully',
            'latitude' => $lat,
            'longitude' => $lng
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Update failed: ' . $stmt->error
        ]);
    }
    $stmt->close();
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'Prepare statement failed: ' . $conn->error
    ]);
}

$conn->close();
?>
