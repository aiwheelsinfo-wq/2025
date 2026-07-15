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
        closing_date,
        cancelled_at,
        vendor_compensation,
        vendor_compensation_status,
        trip_type,
        vendor_amount,
        base_charge,
        driver_ta,
        from_address,
        to_address,
        return_date
    FROM bookings
    WHERE vender_id = ?
      AND (
          ((trip_type = 'One-way' OR trip_type = 'Round-Trip') AND payment_type = 'Advance' AND (payment_status = 'success' OR payment_status = 'Advance Paid') AND booking_status != 'Cancelled')
          OR (booking_status = 'Cancelled' AND vendor_compensation > 0)
      )
    ORDER BY id DESC
");

$stmt->bind_param("s", $phone_number);
$stmt->execute();
$result = $stmt->get_result();

$settlements = [];
while ($row = $result->fetch_assoc()) {
    $advance = floatval($row['paid_amount']);
    $trip_type = $row['trip_type'] ?? '';
    
    if ($row['booking_status'] === 'Cancelled') {
        $eligible = floatval($row['vendor_compensation']);
        $settlement_status = $row['vendor_compensation_status'] ?: 'Pending';
        // expected date is 7 days after cancellation
        $cancellation_base = (!empty($row['cancelled_at']) && $row['cancelled_at'] !== '0000-00-00 00:00:00') ? $row['cancelled_at'] : $row['date'];
        $expected_ts = strtotime($cancellation_base) + (7 * 24 * 60 * 60);
    } else {
        if ($trip_type === 'Round-Trip') {
            // For Round-Trip:
            // Settlement Amount = vendor_amount + paid_amount - (base_charge + driver_ta + (base_charge * 0.05))
            $vendor_amount = floatval($row['vendor_amount']);
            $base_charge = floatval($row['base_charge']);
            $driver_ta = floatval($row['driver_ta']);
            $gst_val = $base_charge * 0.05;
            $eligible = $vendor_amount + $advance - ($base_charge + $driver_ta + $gst_val);
            if ($eligible < 0) $eligible = 0; // Guard against negative values
        } else {
            // For One-way:
            $eligible = $advance * 0.60;
        }
        $settlement_status = $row['settlement_status'] ?: 'Pending';
        // completion date defaults to closing_date if set, else travel date
        $completion_base = (!empty($row['closing_date']) && $row['closing_date'] !== '0000-00-00') ? $row['closing_date'] : $row['date'];
        $expected_ts = strtotime($completion_base) + (7 * 24 * 60 * 60);
    }
    
    $expected_settlement_date = date('Y-m-d', $expected_ts);

    $settlements[] = [
        "booking_id" => $row['booking_id'],
        "advance_paid" => $advance,
        "eligible_amount" => $eligible,
        "trip_status" => $row['booking_status'],
        "settlement_status" => $settlement_status,
        "settlement_date" => $row['settlement_date'],
        "transaction_reference" => $row['transaction_reference'],
        "expected_settlement_date" => $expected_settlement_date,
        "trip_type" => $trip_type,
        "vendor_amount" => floatval($row['vendor_amount']),
        "from_address" => $row['from_address'] ?? '',
        "to_address" => $row['to_address'] ?? '',
        "starting_date" => $row['date'] ?? '',
        "return_date" => $row['return_date'] ?? ''
    ];
}

echo json_encode([
    "success" => true,
    "settlements" => $settlements
]);

$stmt->close();
$conn->close();
?>
