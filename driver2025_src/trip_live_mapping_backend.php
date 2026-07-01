<?php
header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 1);

file_put_contents("log.txt", print_r($_POST, true));
include 'db_connect.php';

$action = $_POST['action'] ?? '';

// Fetch Booking Details
if ($action === 'get_booking_details') {
    $booking_id = $_POST['booking_id'] ?? '';

    if (empty($booking_id)) {
        echo json_encode(['success' => false, 'message' => 'Booking ID is required.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT from_address, to_address FROM bookings WHERE id = ?");
    $stmt->bind_param("s", $booking_id);

    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            echo json_encode([
                'success' => true,
                'from_address' => $row['from_address'],
                'to_address' => $row['to_address'],
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => $stmt->error]);
    }

    $stmt->close();
}

// Fetch Driver Location
else if ($action === 'get_driver_location') {
    $phone_number = $_POST['phone_number'] ?? '';

    if (empty($phone_number)) {
        echo json_encode(['success' => false, 'message' => 'Phone number is required.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT latitude, longitude FROM drivers WHERE phone_number = ?");
    $stmt->bind_param("s", $phone_number);

    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            echo json_encode([
                'success' => true,
                'latitude' => floatval($row['latitude']),
                'longitude' => floatval($row['longitude']),
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Driver not found.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => $stmt->error]);
    }

    $stmt->close();
}

// Fetch OTP for Booking
else if ($action === 'get_booking_otp') {
    $booking_id = $_POST['booking_id'] ?? '';

    if (empty($booking_id)) {
        echo json_encode(['success' => false, 'message' => 'Booking ID is required.']);
        exit;
    }

    // Fetch OTP from the bookings table
    $stmt = $conn->prepare("
    SELECT 
    b.trip_type, 
    b.otp, 
    b.date, 
    b.time, 
    b.return_date, 
    b.return_time, 
    b.starting_km, 
    b.starting_date,
    b.closing_date,
    b.starting_time, 
    b.closing_time,
    b.total_amount,
    b.agni_amount,
    b.vendor_amount,
    b.distance,
    b.toll_charge,
    b.parking_charge,
    b.permit_charge,
    b.event_duty_gst,
    t.kmRate, 
    t.driver_allowance, 
    t.agni_share, 
    t.daily_limit, 
    t.extraKMAmount,
    t.extraHoursAmount,
    t.extraKMAmountFroDriver,
    t.extraHoursAmountForDriver,
    t.packageKm,
    t.packageHours,
    t.baseAmount,
    t.driverRate,
    b.agent_commission,
    t.gstPercent
    FROM 
    bookings AS b 
    JOIN tripCostTable AS t 
    ON t.tripType = b.trip_type 
    AND t.carType = b.car_type 
    AND t.minLat IS NULL
    WHERE b.id = ?");
    $stmt->bind_param("i", $booking_id);

    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            echo json_encode([
                'success' => true,
                'trip_type' => $row['trip_type'],
                'otp' => $row['otp'],
                'date' => $row['date'],
                'time' => $row['time'],
                'return_date' => $row['return_date'],
                'return_time' => $row['return_time'],
                'starting_km' => $row['starting_km'],
                'starting_date' =>$row['starting_date'],
                'closing_date' =>$row['closing_date'],
                'starting_time' => $row['starting_time'],
                'closing_time' =>$row['closing_time'],
                'kmRate' => $row['kmRate'],
                'driver_allowance' => $row['driver_allowance'],
                'agni_share' => $row['agni_share'],
                'daily_limit' => $row['daily_limit'],
                'extraKMAmount' => $row['extraKMAmount'],
                'extraHoursAmount' => $row['extraHoursAmount'],
                'extraKMAmountFroDriver' => $row['extraKMAmountFroDriver'],
                'extraHoursAmountForDriver' => $row['extraHoursAmountForDriver'],
                'packageKm' => $row['packageKm'],
                'packageHours' => $row['packageHours'],
                'baseAmount' => $row['baseAmount'],
                'driverRate' => $row['driverRate'],
                'total_amount' => $row['total_amount'],
                'agni_amount' => $row['agni_amount'],
                'vendor_amount' => $row['vendor_amount'],
                'distance' => $row['distance'],
                'toll_charge' => $row['toll_charge'],
                'parking_charge' => $row['parking_charge'],
                'permit_charge' => $row['permit_charge'],
                'agent_commission' => $row['agent_commission'],
                'gstPercent' => $row['gstPercent'],
                'event_duty_gst'=>$row['event_duty_gst']
                
                
                
              
              
     
                
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => $stmt->error]);
    }

    $stmt->close();
}


$conn->close();
?>
