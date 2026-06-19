<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

include 'db_connect.php';

if (!isset($_GET['vendor_id']) || empty($_GET['vendor_id'])) {
    echo json_encode([
        "success" => false,
        "message" => "Missing vendor_id parameter"
    ]);
    exit;
}

$vendor_id = mysqli_real_escape_string($conn, trim($_GET['vendor_id']));

// 1. Calculate overview sums
$sums_query = "
    SELECT 
        COALESCE(SUM(remaining_balance), 0) AS total_remaining,
        COALESCE(SUM(CASE WHEN collection_status = 'Collected' THEN remaining_balance ELSE 0 END), 0) AS collected,
        COALESCE(SUM(CASE WHEN collection_status = 'Pending Collection' THEN remaining_balance ELSE 0 END), 0) AS pending_collection
    FROM bookings 
    WHERE vender_id = '$vendor_id' AND booking_status != 'Deleted' AND booking_status != 'Failed'
";

$sums_result = mysqli_query($conn, $sums_query);
$sums = mysqli_fetch_assoc($sums_result);

// 2. Fetch list of bookings
$list_query = "
    SELECT 
        b.id AS booking_id,
        u.name AS customer_name,
        b.from_address AS pickup,
        b.to_address AS `drop`,
        b.paid_amount AS advance_paid,
        b.remaining_balance,
        b.collection_status,
        b.total_amount,
        b.trip_type,
        b.collection_date
    FROM bookings b
    LEFT JOIN users u ON b.booker_id = u.phone_number
    WHERE b.vender_id = '$vendor_id' AND b.booking_status != 'Deleted' AND b.booking_status != 'Failed'
    ORDER BY b.id DESC
";

$list_result = mysqli_query($conn, $list_query);
$bookings = [];
while ($row = mysqli_fetch_assoc($list_result)) {
    // Standardize drop_location for local duties
    if (empty($row['drop'])) {
        $row['drop'] = 'Local Duty';
    }
    $bookings[] = $row;
}

echo json_encode([
    "success" => true,
    "overview" => [
        "total_remaining" => (double)$sums['total_remaining'],
        "collected" => (double)$sums['collected'],
        "pending_collection" => (double)$sums['pending_collection']
    ],
    "bookings" => $bookings
]);

mysqli_close($conn);
?>
