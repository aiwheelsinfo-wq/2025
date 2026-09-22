<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

if (file_exists(__DIR__ . '/db_connect.php')) {
    require_once __DIR__ . '/db_connect.php';
} elseif (file_exists(__DIR__ . '/../admin2025/db_connect.php')) {
    require_once __DIR__ . '/../admin2025/db_connect.php';
} elseif (file_exists('/var/www/html/admin2025/db_connect.php')) {
    require_once '/var/www/html/admin2025/db_connect.php';
} else {
    $conn = mysqli_connect("localhost", "agnicar", "dGwW(W8b237~", "agnicar2025");
}

$response = ["status" => "error", "categories" => []];

$result = mysqli_query($conn, "SELECT DISTINCT car_type FROM car_categories WHERE status = 'active' ORDER BY car_type ASC");
if ($result) {
    $categories = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $catName = strtoupper(trim($row['car_type']));
        if (!empty($catName) && !in_array($catName, $categories)) {
            $categories[] = $catName;
        }
    }
    $response = [
        "status" => "success",
        "categories" => $categories
    ];
} else {
    $response["message"] = "Database error: " . mysqli_error($conn);
}

echo json_encode($response);
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
exit;
?>
