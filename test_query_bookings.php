<?php
include 'db_connect.php';

header("Content-Type: application/json");

$currentDate = date('Y-m-d');

try {
    $sqlPending = "
        SELECT 
            b.id, 
            b.car_type, 
            b.from_address, 
            b.to_address, 
            b.distance, 
            b.date, 
            b.time, 
            CASE 
                WHEN b.trip_type = 'Local-taxi' THEN b.total_amount - 100 
                ELSE b.total_amount 
            END AS total_amount,
            b.mobile, 
            b.trip_type, 
            t.kmRate, 
            t.packageKm,
            t.packageHours,
            b.return_date, 
            b.return_time,
            t.baseAmount,
            t.extraKMAmount,
            t.extraHoursAmount,
            b.agent_commission,
            t.driverRate,
            t.extraKMAmountFroDriver,
            t.extraHoursAmountForDriver,
            t.driver_allowance,
            b.vendor_amount,
            t.agni_share,
            d.full_name AS driver_name,
            v.full_name AS vendor_name
        FROM 
            bookings b 
        LEFT JOIN 
            tripCostTable t 
            ON b.trip_type = t.tripType 
            AND b.car_type = t.carType 
            AND b.trip_type != 'One-way'
        LEFT JOIN 
            drivers AS d ON b.driver_id = d.phone_number
        LEFT JOIN 
            drivers AS v ON b.vender_id = v.phone_number
        WHERE 
            b.booking_status = 'Pending' 
            AND b.date >= ?
        ORDER BY 
            b.date ASC
    ";

    $stmtPending = $conn->prepare($sqlPending);
    $stmtPending->bind_param("s", $currentDate);
    $stmtPending->execute();
    
    $result = $stmtPending->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    echo json_encode($rows, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(["error" => $e->getMessage()]);
}
?>
