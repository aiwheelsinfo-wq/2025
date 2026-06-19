<?php
// Already has header("Content-Type: application/json") set in db_connect.php
include 'db_connect.php';

// Support both POST and GET
$phone_number = $_REQUEST['phone_number'] ?? null;

if (!$phone_number) {
    echo json_encode(["success" => false, "message" => "Phone number is required"]);
    exit;
}

$stmt = $conn->prepare("
    SELECT 
        id AS booking_id,
        paid_amount,
        booking_status,
        settlement_status,
        settlement_date,
        transaction_reference,
        date,
        closing_date
    FROM bookings
    WHERE vender_id = ?
      AND trip_type = 'One-way'
      AND payment_type = 'Advance'
      AND (payment_status = 'success' OR payment_status = 'Advance Paid')
    ORDER BY id DESC
");

$stmt->bind_param("s", $phone_number);
$stmt->execute();
$result = $stmt->get_result();

$settlements = [];
while ($row = $result->fetch_assoc()) {
    $advance = floatval($row['paid_amount']);
    $eligible = $advance * 0.90;
    
    // Calculate expected settlement date: 7 days after completion
    // completion date defaults to closing_date if set, else travel date
    $completion_base = (!empty($row['closing_date']) && $row['closing_date'] !== '0000-00-00') ? $row['closing_date'] : $row['date'];
    $expected_ts = strtotime($completion_base) + (7 * 24 * 60 * 60);
    $expected_settlement_date = date('Y-m-d', $expected_ts);

    $settlements[] = [
        "booking_id" => $row['booking_id'],
        "advance_paid" => $advance,
        "eligible_amount" => $eligible,
        "trip_status" => $row['booking_status'],
        "settlement_status" => $row['settlement_status'] ?: 'Pending',
        "settlement_date" => $row['settlement_date'],
        "transaction_reference" => $row['transaction_reference'],
        "expected_settlement_date" => $expected_settlement_date
    ];
}

echo json_encode([
    "success" => true,
    "settlements" => $settlements
]);

$stmt->close();
$conn->close();
?>
