<?php
header("Content-Type: application/json");
include 'db_connect.php'; // your DB connection file

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', 0);


$response = [];

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['booking_id']) || !isset($data['payment_id']) || !isset($data['status']) || !isset($data['amount'])) {
    $response['success'] = false;
    $response['message'] = "Missing required parameters";
    echo json_encode($response);
    exit;
}

$booking_id = $data['booking_id'];
$payment_id = $data['payment_id'];
$status = $data['status']; // "success", "failed", etc.
$amount = $data['amount'];

// Optional: sanitize input to prevent SQL injection
$booking_id = mysqli_real_escape_string($conn, $booking_id);
$payment_id = mysqli_real_escape_string($conn, $payment_id);
$status = mysqli_real_escape_string($conn, $status);
$amount = mysqli_real_escape_string($conn, $amount);

// Add columns to your `bookings` table if not already there:
// ALTER TABLE bookings ADD payment_id VARCHAR(255), ADD payment_status VARCHAR(100), ADD paid_amount DECIMAL(10,2);

// Fetch booking details
$booking_query = mysqli_query($conn, "SELECT trip_type FROM bookings WHERE id = '$booking_id'");
$booking_info = mysqli_fetch_assoc($booking_query);
$trip_type = $booking_info['trip_type'] ?? '';

if ($status === 'success') {
    if ($trip_type === 'Round-Trip') {
        $payment_status_db = 'Advance Paid';
        $booking_status_db = 'Pending';
    } else {
        $payment_status_db = 'success';
        $booking_status_db = 'Pending';
    }
} else {
    $payment_status_db = 'Failed';
    $booking_status_db = 'Failed';
}

$sql = "UPDATE bookings 
        SET payment_id = '$payment_id', 
            payment_status = '$payment_status_db', 
            paid_amount = '$amount', 
            booking_status = '$booking_status_db'
        WHERE id = '$booking_id'";

if (mysqli_query($conn, $sql)) {
    $response['success'] = true;
    $response['message'] = "Payment updated successfully";

    if ($status === 'success') {
        try {
            require_once __DIR__ . '/send_new_booking_notification.php';
            trigger_new_booking_notification($booking_id);

            // Send WhatsApp notification to the customer
            try {
                require_once __DIR__ . '/notification_helper.php';
                sendBookingWhatsAppNotification($booking_id, $conn);
            } catch (Throwable $e) {
                error_log("WhatsApp Booking Notification error: " . $e->getMessage());
            }

            // Notify booker (admin/agent)
            $fcm_bookerToken = 'd14kUzAvRSiSGgFXgKr0ki:APA91bGnbj1b1aeMifNmY-l58bcvO1xluXIG_dSS1f4Ra7sN02IMfuU3HW032-JQu56PZHVn_7PUwO7l2DComSbXYP9f2o8epGDM0pV5ic8R3xRrUFkORe0';
            
            // Try to fetch booker token from the database
            $booker_query = mysqli_query($conn, "SELECT fcm_token FROM users WHERE phone_number = (SELECT booker_id FROM bookings WHERE id = '$booking_id')");
            if ($booker_query && mysqli_num_rows($booker_query) > 0) {
                $booker_row = mysqli_fetch_assoc($booker_query);
                if (!empty($booker_row['fcm_token'])) {
                    $fcm_bookerToken = $booker_row['fcm_token'];
                }
            }

            if (!empty($fcm_bookerToken)) {
                $accessToken = getFcmAccessToken();
                $projectId = 'agnicarrentaldriver-8fb07';
                $notificationData = [
                    'title' => 'AGNI RENTAL ADMIN',
                    'body' => 'We have a new Trip! Check the list',
                    'data' => [
                        'booking_id' => (string)$booking_id,
                        'notification_type' => 'new_booking'
                    ]
                ];
                sendSingleFcmNotification($accessToken, $projectId, $fcm_bookerToken, $notificationData);
            }
        } catch (Throwable $e) {
            error_log("FCM Notification error in updatePayment: " . $e->getMessage());
        }
    }
} else {
    $response['success'] = false;
    $response['message'] = "Database error: " . mysqli_error($conn);
}

echo json_encode($response);
?>
