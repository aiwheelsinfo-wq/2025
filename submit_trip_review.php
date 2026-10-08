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

// Parse request data (supporting both JSON and form data)
$rawInput = file_get_contents("php://input");
$input = json_decode($rawInput, true);

if (!is_array($input)) {
    $input = $_POST;
}

$booking_id = trim($input['booking_id'] ?? '');
$rating = isset($input['rating']) ? (int)$input['rating'] : 0;
$tags = $input['tags'] ?? '';
$review_text = trim($input['review_text'] ?? $input['comment'] ?? '');
$customer_number = trim($input['customer_number'] ?? $input['phone_number'] ?? '');

// Format tags if provided as array
if (is_array($tags)) {
    $tags = implode(', ', array_filter(array_map('trim', $tags)));
} else {
    $tags = trim((string)$tags);
}

// Validation
if (empty($booking_id)) {
    echo json_encode([
        "status" => "error",
        "success" => false,
        "message" => "Booking ID is required."
    ]);
    exit;
}

if ($rating < 1 || $rating > 5) {
    echo json_encode([
        "status" => "error",
        "success" => false,
        "message" => "Rating must be between 1 and 5 stars."
    ]);
    exit;
}

// Lookup booking record to get canonical booking_id, driver_id, and customer_number
$canonical_booking_id = $booking_id;
$driver_id = null;

$stmt = $conn->prepare("SELECT id, booking_id, customer_number, driver_id, booking_status FROM bookings WHERE id = ? OR booking_id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param("ss", $booking_id, $booking_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        // Use the consistent booking_id or fallback to numeric id
        $canonical_booking_id = !empty($row['booking_id']) ? $row['booking_id'] : (string)$row['id'];
        $driver_id = !empty($row['driver_id']) ? $row['driver_id'] : null;
        if (empty($customer_number) && !empty($row['customer_number'])) {
            $customer_number = $row['customer_number'];
        }
    }
    $stmt->close();
}

if (empty($customer_number)) {
    $customer_number = "Customer";
}

// Insert or update review record
$save_stmt = $conn->prepare("INSERT INTO trip_reviews (booking_id, customer_number, driver_id, rating, tags, review_text) 
    VALUES (?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE 
      rating = VALUES(rating),
      tags = VALUES(tags),
      review_text = VALUES(review_text),
      driver_id = COALESCE(VALUES(driver_id), driver_id),
      created_at = CURRENT_TIMESTAMP");

if (!$save_stmt) {
    echo json_encode([
        "status" => "error",
        "success" => false,
        "message" => "Database prepare statement failed: " . $conn->error
    ]);
    exit;
}

$save_stmt->bind_param("sssiss", $canonical_booking_id, $customer_number, $driver_id, $rating, $tags, $review_text);

if ($save_stmt->execute()) {
    $save_stmt->close();

    // Recalculate Driver Average Rating if driver_id is present
    if (!empty($driver_id)) {
        $calc_stmt = $conn->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as cnt FROM trip_reviews WHERE driver_id = ?");
        if ($calc_stmt) {
            $calc_stmt->bind_param("s", $driver_id);
            $calc_stmt->execute();
            $calc_res = $calc_stmt->get_result();
            if ($calc_row = $calc_res->fetch_assoc()) {
                $avg_val = round((float)$calc_row['avg_rating'], 2);
                $cnt_val = (int)$calc_row['cnt'];

                // Attempt update on drivers table
                $upd_stmt = $conn->prepare("UPDATE drivers SET rating = ?, total_ratings = ? WHERE driver_id = ? OR phone_number = ?");
                if ($upd_stmt) {
                    $upd_stmt->bind_param("diss", $avg_val, $cnt_val, $driver_id, $driver_id);
                    $upd_stmt->execute();
                    $upd_stmt->close();
                }
            }
            $calc_stmt->close();
        }
    }

    echo json_encode([
        "status" => "success",
        "success" => true,
        "message" => "Thank you! Your feedback has been saved successfully.",
        "data" => [
            "booking_id" => $canonical_booking_id,
            "rating" => $rating,
            "tags" => $tags,
            "review_text" => $review_text
        ]
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "success" => false,
        "message" => "Failed to save review: " . $conn->error
    ]);
}
