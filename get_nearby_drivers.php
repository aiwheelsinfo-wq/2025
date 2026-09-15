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
}

$lat = isset($_GET['lat']) ? floatval($_GET['lat']) : (isset($_POST['lat']) ? floatval($_POST['lat']) : 0.0);
$lng = isset($_GET['lng']) ? floatval($_GET['lng']) : (isset($_POST['lng']) ? floatval($_POST['lng']) : 0.0);
$radius = isset($_GET['radius']) ? floatval($_GET['radius']) : (isset($_POST['radius']) ? floatval($_POST['radius']) : 35.0); // radius in KM

if ($lat == 0.0 || $lng == 0.0) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Valid latitude and longitude are required',
        'count' => 0,
        'drivers' => []
    ]);
    exit;
}

// Query active drivers within $radius km using Haversine distance
$sql = "
    SELECT 
        phone_number,
        full_name,
        vehicle_name,
        vehicle_type,
        latitude,
        longitude,
        status,
        (6371 * acos(
            cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + 
            sin(radians(?)) * sin(radians(latitude))
        )) AS distance
    FROM drivers
    WHERE status = 'active'
      AND latitude IS NOT NULL 
      AND longitude IS NOT NULL 
      AND latitude != 0 
      AND longitude != 0
    HAVING distance <= ?
    ORDER BY distance ASC
    LIMIT 20
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Query preparation failed: ' . $conn->error,
        'count' => 0,
        'drivers' => []
    ]);
    exit;
}

$stmt->bind_param("dddd", $lat, $lng, $lat, $radius);
$stmt->execute();
$result = $stmt->get_result();

$drivers = [];
while ($row = $result->fetch_assoc()) {
    $drivers[] = [
        'id' => $row['phone_number'],
        'name' => $row['full_name'] ?? 'Driver',
        'vehicle_name' => $row['vehicle_name'] ?? 'Cab',
        'vehicle_type' => $row['vehicle_type'] ?? 'Sedan',
        'latitude' => floatval($row['latitude']),
        'longitude' => floatval($row['longitude']),
        'distance_km' => round(floatval($row['distance']), 2)
    ];
}

$stmt->close();
$conn->close();

echo json_encode([
    'status' => 'success',
    'count' => count($drivers),
    'radius_km' => $radius,
    'drivers' => $drivers
]);
?>
