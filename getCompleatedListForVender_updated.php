<?php
include '../2025/db_connect.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

$phone_number = $_GET['phone_number'] ?? '';

if (empty($phone_number)) {
    echo json_encode(['success' => false, 'message' => 'Phone number is required', 'compleatedBookings' => []]);
    exit;
}

// Fetch completed bookings for this vendor, with tripCostTable JOIN for fare fields
$query = "
    SELECT 
        b.*,
        t.kmRate,
        t.daily_limit,
        t.gstPercent,
        t.driver_allowance,
        t.baseAmount,
        t.agni_share,
        t.driverRate,
        t.packageKm,
        t.packageHours,
        t.extraKMAmount,
        t.extraHoursAmount,
        d.full_name AS driver_name,
        b.date AS starting_date
    FROM bookings b
    LEFT JOIN tripCostTable t 
        ON b.trip_type = t.tripType 
        AND b.car_type = t.carType
    LEFT JOIN drivers d ON b.driver_id = d.phone_number
    WHERE b.vender_id = ?
      AND b.booking_status = 'Completed'
    ORDER BY b.id DESC
";

$stmt = $conn->prepare($query);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Query prepare failed: ' . $conn->error, 'compleatedBookings' => []]);
    exit;
}

$stmt->bind_param("s", $phone_number);
$stmt->execute();
$result = $stmt->get_result();

$compleatedBookings = [];
while ($row = $result->fetch_assoc()) {
    // Format starting_date for display
    if (!empty($row['date'])) {
        $row['starting_date'] = date('Y-m-d', strtotime($row['date']));
    }
    // Ensure numeric defaults for fare fields
    $row['kmRate']           = floatval($row['kmRate']           ?? 0);
    $row['daily_limit']      = floatval($row['daily_limit']      ?? 0);
    $row['gstPercent']       = floatval($row['gstPercent']       ?? 0);
    $row['driver_allowance'] = floatval($row['driver_allowance'] ?? 0);
    $row['agent_commission'] = floatval($row['agent_commission'] ?? 0);
    $row['parking_charge']   = floatval($row['parking_charge']   ?? 0);
    $row['toll_charge']      = floatval($row['toll_charge']      ?? 0);
    $row['permit_charge']    = floatval($row['permit_charge']    ?? 0);
    $row['paid_amount']      = floatval($row['paid_amount']      ?? 0);
    $row['starting_km']      = floatval($row['starting_km']      ?? 0);
    $row['closing_km']       = floatval($row['closing_km']       ?? 0);

    $compleatedBookings[] = $row;
}

$stmt->close();
$conn->close();

if (count($compleatedBookings) > 0) {
    echo json_encode(['success' => true, 'compleatedBookings' => $compleatedBookings]);
} else {
    echo json_encode(['success' => false, 'compleatedBookings' => []]);
}
?>
