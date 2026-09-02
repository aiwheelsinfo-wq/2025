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

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?? $_POST;

if (empty($data)) {
    echo json_encode(['status' => 'error', 'message' => 'No data provided']);
    exit;
}

$action = $data['action'] ?? 'save';

if ($action === 'delete') {
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid City ID']);
        exit;
    }
    $stmt = $conn->prepare("DELETE FROM `city_boundaries` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['status' => 'success', 'message' => 'City boundary deleted successfully']);
    exit;
}

if ($action === 'toggle_status') {
    $id = (int)($data['id'] ?? 0);
    $newStatus = ($data['status'] ?? '') === 'active' ? 'active' : 'inactive';
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid City ID']);
        exit;
    }
    $stmt = $conn->prepare("UPDATE `city_boundaries` SET `status` = ?, `updated_at` = NOW() WHERE `id` = ?");
    $stmt->bind_param("si", $newStatus, $id);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['status' => 'success', 'message' => "City status updated to $newStatus"]);
    exit;
}

// Add or Update Boundary
$id = (int)($data['id'] ?? 0);
$cityName = trim($data['city_name'] ?? '');
$status = ($data['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
$minLat = (float)($data['min_lat'] ?? 0);
$maxLat = (float)($data['max_lat'] ?? 0);
$minLng = (float)($data['min_lng'] ?? 0);
$maxLng = (float)($data['max_lng'] ?? 0);
$polygonCoords = $data['polygon_coords'] ?? [];

if (empty($cityName)) {
    echo json_encode(['status' => 'error', 'message' => 'City name is required']);
    exit;
}

$polyJson = is_array($polygonCoords) && !empty($polygonCoords) ? json_encode($polygonCoords) : null;

// Calculate bounding box from polygon if coords given
if (is_array($polygonCoords) && count($polygonCoords) >= 3) {
    $lats = array_column($polygonCoords, 'lat');
    $lngs = array_column($polygonCoords, 'lng');
    if (!empty($lats) && !empty($lngs)) {
        $minLat = min($lats);
        $maxLat = max($lats);
        $minLng = min($lngs);
        $maxLng = max($lngs);
    }
}

if ($id > 0) {
    // Update existing
    $stmt = $conn->prepare("UPDATE `city_boundaries` SET 
        `city_name` = ?, 
        `min_lat` = ?, 
        `max_lat` = ?, 
        `min_lng` = ?, 
        `max_lng` = ?, 
        `status` = ?, 
        `polygon_coords` = ?,
        `updated_at` = NOW()
        WHERE `id` = ?
    ");
    $stmt->bind_param("sddddssi", $cityName, $minLat, $maxLat, $minLng, $maxLng, $status, $polyJson, $id);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['status' => 'success', 'message' => "City boundary for $cityName updated successfully"]);
} else {
    // Insert new
    $stmt = $conn->prepare("INSERT INTO `city_boundaries` 
        (`city_name`, `min_lat`, `max_lat`, `min_lng`, `max_lng`, `status`, `polygon_coords`, `updated_at`)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param("sddddss", $cityName, $minLat, $maxLat, $minLng, $maxLng, $status, $polyJson);
    $stmt->execute();
    $insertId = $conn->insert_id;
    $stmt->close();

    echo json_encode(['status' => 'success', 'message' => "New City boundary for $cityName created successfully", 'id' => $insertId]);
}
