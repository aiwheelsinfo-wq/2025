<?php
header("Content-Type: application/json");
include '../2025/db_connect.php'; // Database connection

$vendor_phone = $_POST['phone_number'] ?? null;
$driver_phone = $_POST['phone_number'] ?? null;
$currentDate = date('Y-m-d');

try {
    if (!$conn) {
        throw new Exception("Database connection failed.");
    }

    // ===================== Fetch Accepted Bookings =====================
    // We remove the date filter for accepted bookings so they don't disappear in case they started in the past or timezone difference
    $sqlAccepted = "
        SELECT 
            b.id, 
            b.car_type, 
            b.from_address, 
            b.to_address, 
            b.distance, 
            b.date, 
            b.time, 
            b.return_date,
            b.return_time,
            CASE 
                WHEN b.trip_type = 'Local-taxi' THEN b.total_amount - 100 
                ELSE b.total_amount 
            END AS total_amount,
            b.mobile, 
            b.driver_id,
            b.vehicle_id,
            b.booking_status,
            b.otp,
            b.trip_type,
            d.full_name AS driver_name,
            v.full_name AS vendor_name,
            d.latitude AS driver_lat, 
            d.longitude AS driver_lon,
            u.name AS customer_name,
            b.vendor_amount,
            d.phone_number AS driver_phone,
            b.starting_km
        FROM 
            bookings AS b
        LEFT JOIN 
            drivers AS d ON b.driver_id = d.phone_number
        LEFT JOIN 
            drivers AS v ON b.vender_id = v.phone_number
        LEFT JOIN
            users AS u ON b.mobile = u.phone_number
        WHERE 
            (b.vender_id = ? OR b.driver_id = ?) 
            AND (b.booking_status = 'Accepted' OR b.booking_status = 'Started')
        ORDER BY 
            b.date ASC
    ";

    $stmtAccepted = $conn->prepare($sqlAccepted);
    if (!$stmtAccepted) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $stmtAccepted->bind_param("ss", $vendor_phone, $driver_phone);
    $stmtAccepted->execute();

    $stmtAccepted->bind_result(
        $booking_id, $car_type, $from_address, $to_address, $distance, $date, $time,
        $return_date, $return_time, $total_amount, $customer_contact, $driver_id,
        $vehicle_id, $booking_status, $otp, $trip_type, $driver_name, $vendor_name,
        $driver_lat, $driver_lon, $customer_name, $vendor_amount, $driver_phone, $starting_km
    );

    $acceptedBookings = [];
    while ($stmtAccepted->fetch()) {
        $acceptedBookings[] = [
            "booking_id" => $booking_id,
            "car_type" => $car_type,
            "pickup_location" => $from_address,
            "drop_location" => $to_address,
            "distance" => $distance,
            "date" => $date,
            "time" => $time,
            "return_date" => $return_date,
            "return_time" => $return_time,
            "total_amount" => round((float)$total_amount, 2),
            "customer_contact" => $customer_contact,
            "driver_id" => $driver_id,
            "vehicle_id" => $vehicle_id,
            "booking_status" => $booking_status,
            "otp" => $otp,
            "trip_type" => $trip_type,
            "driver_name" => $driver_name,
            "vendor_name" => $vendor_name,
            "driver_lat" => $driver_lat,
            "driver_lon" => $driver_lon,
            "customer_name" => $customer_name,
            "vendor_amount" => $vendor_amount,
            "driver_phone" => $driver_phone,
            "starting_km" => $starting_km
        ];
    }
    $stmtAccepted->close();

    // ===================== Fetch Pending Bookings =====================
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
    $stmtPending->bind_result(
        $booking_id, $car_type, $from_address, $to_address, $distance, $date, $time, 
        $total_amount, $customer_contact, $trip_type, $kmRate, $packageKm, $packageHours, 
        $return_date, $return_time, $baseAmount, $extraKMAmount, $extraHoursAmount, 
        $agent_commission, $driverRate, $extraKMAmountFroDriver, $extraHoursAmountForDriver, 
        $driver_allowance, $vendor_amount, $agni_share, $driver_name, $vendor_name
    );

    $bookings = [];
    while ($stmtPending->fetch()) {
        $bookings[] = [
            "booking_id" => $booking_id,
            "car_type" => $car_type,
            "pickup_location" => $from_address,
            "drop_location" => $to_address,
            "distance" => $distance,
            "date" => $date,
            "time" => $time,
            "total_amount" => round((float)$total_amount, 2),
            "customer_contact" => $customer_contact,
            "trip_type" => $trip_type,
            "kmRate" => $kmRate,
            "packageKm" => $packageKm,
            "packageHours" => $packageHours,
            "return_date" => $return_date,
            "return_time" => $return_time,
            "baseAmount" => $baseAmount,
            "extraKMAmount" => $extraKMAmount,
            "extraHoursAmount" => $extraHoursAmount,
            "agent_commission" => $agent_commission,
            "driverRate" => $driverRate,
            "extraKMAmountFroDriver" => $extraKMAmountFroDriver,
            "extraHoursAmountForDriver" => $extraHoursAmountForDriver,
            "driver_allowance" => $driver_allowance,
            "vendor_amount" => $vendor_amount,
            "agni_share" => $agni_share,
            "driver_name" => $driver_name,
            "vendor_name" => $vendor_name
        ];
    }
    $stmtPending->close();

    // ===================== Final Response =====================
    $response = [
        "success" => true,
        "acceptedBookings" => $acceptedBookings,
        "bookings" => $bookings
    ];

} catch (Exception $e) {
    $response = [
        "success" => false,
        "message" => "Error: " . $e->getMessage()
    ];
}

if ($conn) {
    $conn->close();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
