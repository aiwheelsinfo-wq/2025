<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db_connect.php';

try {
    if (!$conn) {
        throw new Exception("Database connection failed.");
    }

    $sql = "SELECT service_key, service_name, is_enabled, badge_text, coming_soon_title, coming_soon_message FROM trip_service_status";
    $result = $conn->query($sql);
    
    $services = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $key = $row['service_key'];
            $services[$key] = [
                'service_key' => $key,
                'service_name' => $row['service_name'],
                'is_enabled' => (intval($row['is_enabled']) === 1),
                'badge_text' => $row['badge_text'] ?? 'Coming Soon',
                'coming_soon_title' => $row['coming_soon_title'] ?? 'Coming Soon',
                'coming_soon_message' => $row['coming_soon_message'] ?? 'This service will be available in your area shortly.'
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'status' => 'success',
        'services' => $services
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>
