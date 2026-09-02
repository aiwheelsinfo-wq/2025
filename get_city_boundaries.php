<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db_connect.php';

$action = $_GET['action'] ?? 'list';

if ($action === 'active_only') {
    $sql = "SELECT id, city_name, min_lat, max_lat, min_lng, max_lng, status, polygon_coords, updated_at 
            FROM `city_boundaries` 
            WHERE `status` = 'active' 
            ORDER BY `city_name` ASC";
} else {
    $sql = "SELECT id, city_name, min_lat, max_lat, min_lng, max_lng, status, polygon_coords, updated_at 
            FROM `city_boundaries` 
            ORDER BY `status` ASC, `city_name` ASC";
}

$res = mysqli_query($conn, $sql);
$cities = [];

if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $polygon = [];
        if (!empty($row['polygon_coords'])) {
            $decoded = json_decode($row['polygon_coords'], true);
            if (is_array($decoded)) {
                $polygon = $decoded;
            }
        }

        $cities[] = [
            'id' => (int)$row['id'],
            'city_name' => $row['city_name'],
            'min_lat' => (float)$row['min_lat'],
            'max_lat' => (float)$row['max_lat'],
            'min_lng' => (float)$row['min_lng'],
            'max_lng' => (float)$row['max_lng'],
            'status' => $row['status'],
            'polygon_coords' => $polygon,
            'updated_at' => $row['updated_at']
        ];
    }
}

echo json_encode([
    'status' => 'success',
    'total_cities' => count($cities),
    'cities' => $cities
], JSON_PRETTY_PRINT);
