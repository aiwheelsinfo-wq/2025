<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db_connect.php';

if (!$conn) {
    echo json_encode([
        "status" => "error",
        "success" => false,
        "message" => "Database connection failed"
    ]);
    exit;
}

// Support GET query, POST form, or raw JSON
$booking_id = '';
if (!empty($_GET['booking_id'])) {
    $booking_id = trim($_GET['booking_id']);
} elseif (!empty($_POST['booking_id'])) {
    $booking_id = trim($_POST['booking_id']);
} else {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    if (!empty($data['booking_id'])) {
        $booking_id = trim($data['booking_id']);
    }
}

if (empty($booking_id)) {
    echo json_encode([
        "status" => "error",
        "success" => false,
        "message" => "booking_id parameter is required"
    ]);
    exit;
}

// First, find canonical booking_id if numeric id was passed
$canonical_id = $booking_id;
$chk_stmt = $conn->prepare("SELECT id, booking_id FROM bookings WHERE id = ? OR booking_id = ? LIMIT 1");
if ($chk_stmt) {
    $chk_stmt->bind_param("ss", $booking_id, $booking_id);
    $chk_stmt->execute();
    $res = $chk_stmt->get_result();
    if ($b_row = $res->fetch_assoc()) {
        $canonical_id = !empty($b_row['booking_id']) ? $b_row['booking_id'] : (string)$b_row['id'];
    }
    $chk_stmt->close();
}

// Query trip_reviews by canonical_id or raw booking_id
$stmt = $conn->prepare("SELECT id, booking_id, customer_number, driver_id, rating, tags, review_text, created_at 
                       FROM trip_reviews 
                       WHERE booking_id = ? OR booking_id = ? 
                       LIMIT 1");

if (!$stmt) {
    echo json_encode([
        "status" => "error",
        "success" => false,
        "message" => "Database prepare statement failed: " . $conn->error
    ]);
    exit;
}

$stmt->bind_param("ss", $canonical_id, $booking_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $review = $result->fetch_assoc();
    echo json_encode([
        "status" => "success",
        "success" => true,
        "has_reviewed" => true,
        "review" => [
            "id" => (int)$review['id'],
            "booking_id" => $review['booking_id'],
            "rating" => (int)$review['rating'],
            "tags" => $review['tags'] ?? '',
            "review_text" => $review['review_text'] ?? '',
            "created_at" => $review['created_at']
        ]
    ]);
} else {
    echo json_encode([
        "status" => "success",
        "success" => true,
        "has_reviewed" => false,
        "review" => null
    ]);
}

$stmt->close();
