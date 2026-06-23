<?php
header("Content-Type: text/plain");
include 'db_connect.php';

// 1. Insert a mock booking via saveBooking logic but programmatic
$trip_type = 'One-way';
$car_type = 'Sedan';
$from_address = 'Mumbai';
$to_address = 'Pune';
$distance = '150';
$date = date('Y-m-d');
$tripTime = '12:00:00';
$mobile = '9999999999';
$total_amount = 3000;
$payment_type = 'Advance';

$sql = "INSERT INTO bookings (trip_type, car_type, from_address, to_address, distance, date, time, mobile, total_amount, payment_type, booking_status)
        VALUES ('$trip_type', '$car_type', '$from_address', '$to_address', '$distance', '$date', '$tripTime', '$mobile', '$total_amount', '$payment_type', 'temp')";

if (mysqli_query($conn, $sql)) {
    $booking_id = mysqli_insert_id($conn);
    echo "STEP 1: Created temp booking with ID $booking_id\n";
    
    // Check database status
    $res = mysqli_query($conn, "SELECT booking_status FROM bookings WHERE id = '$booking_id'");
    $row = mysqli_fetch_assoc($res);
    echo "Initial status: " . $row['booking_status'] . " (Expected: temp)\n";
    
    // 2. Simulate payment success by hitting updatePayment logic
    $payment_status_db = 'success';
    $booking_status_db = 'Pending';
    
    $update_sql = "UPDATE bookings 
            SET payment_id = 'pay_mock123', 
                payment_status = '$payment_status_db', 
                paid_amount = '300', 
                booking_status = '$booking_status_db'
            WHERE id = '$booking_id'";
            
    if (mysqli_query($conn, $update_sql)) {
        echo "STEP 2: Updated payment successfully\n";
        
        $res2 = mysqli_query($conn, "SELECT booking_status, payment_status FROM bookings WHERE id = '$booking_id'");
        $row2 = mysqli_fetch_assoc($res2);
        echo "Updated status: " . $row2['booking_status'] . " (Expected: Pending)\n";
        echo "Payment status: " . $row2['payment_status'] . " (Expected: success)\n";
        
        // Trigger notification
        echo "STEP 3: Triggering FCM notification...\n";
        require_once 'send_new_booking_notification.php';
        trigger_new_booking_notification($booking_id);
        echo "FCM trigger finished!\n";
    } else {
        echo "Failed to update: " . mysqli_error($conn) . "\n";
    }
    
    // Clean up
    mysqli_query($conn, "DELETE FROM bookings WHERE id = '$booking_id'");
    echo "STEP 4: Cleaned up test booking\n";
} else {
    echo "Failed to insert: " . mysqli_error($conn) . "\n";
}

mysqli_close($conn);
?>
