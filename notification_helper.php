<?php
// ==========================================
// notification_helper.php — Agni Car Rental WhatsApp Alerts
// ==========================================

define('ULTRAMSG_INSTANCE', 'instance182608');
define('ULTRAMSG_TOKEN', 'h4ltyv2brjcj63jz');

/**
 * Send raw WhatsApp Message via UltraMsg API
 */
if (!function_exists('sendWhatsAppMessage')) {
    function sendWhatsAppMessage($to, $message) {
        $formatted_phone = preg_replace('/[^0-9]/', '', $to);
        if (strlen($formatted_phone) === 10) {
            $formatted_phone = '91' . $formatted_phone;
        }

        $url = "https://api.ultramsg.com/" . ULTRAMSG_INSTANCE . "/messages/chat";
        $payload = [
            'token' => ULTRAMSG_TOKEN,
            'to' => $formatted_phone,
            'body' => $message,
            'priority' => 10
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res;
    }
}

/**
 * Send booking placement notification to customer
 */
if (!function_exists('sendBookingWhatsAppNotification')) {
    function sendBookingWhatsAppNotification($booking_id, $conn) {
        $stmt = $conn->prepare("SELECT trip_type, from_address, to_address, date, time, mobile, total_amount FROM bookings WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows === 0) {
            $stmt->close();
            return false;
        }
        $booking = $res->fetch_assoc();
        $stmt->close();

        $mobile = $booking['mobile'];
        $trip_type = $booking['trip_type'];
        $from = $booking['from_address'];
        $to = $booking['to_address'];
        $date = date('d-m-Y', strtotime($booking['date']));
        $time = date('h:i A', strtotime($booking['time']));
        $amount = $booking['total_amount'];

        // Fetch user name
        $name = "Customer";
        $user_stmt = $conn->prepare("SELECT name FROM users WHERE phone_number = ? LIMIT 1");
        if ($user_stmt) {
            $user_stmt->bind_param("s", $mobile);
            $user_stmt->execute();
            $user_res = $user_stmt->get_result();
            if ($user_res->num_rows > 0) {
                $user = $user_res->fetch_assoc();
                if (!empty($user['name'])) {
                    $name = $user['name'];
                }
            }
            $user_stmt->close();
        }

        $message = "*Booking Received!* 🚗\n\n" .
                   "Dear *{$name}*,\n" .
                   "Thank you for booking with *Agni Car Rental*! We have received your trip request.\n\n" .
                   "📍 *Booking Details:*\n" .
                   "• *Booking ID:* #{$booking_id}\n" .
                   "• *Trip Type:* {$trip_type}\n" .
                   "• *Pickup:* {$from}\n";
        if (!empty($to)) {
            $message .= "• *Drop:* {$to}\n";
        }
        $message .= "• *Date & Time:* {$date} at {$time}\n" .
                    "• *Amount:* ₹{$amount}\n\n" .
                    "We are currently assigning the nearest professional driver to your ride and will send you confirmation details shortly. Thank you!";

        return sendWhatsAppMessage($mobile, $message);
    }
}

/**
 * Send driver assignment/confirmation notification to customer
 */
if (!function_exists('sendAcceptWhatsAppNotification')) {
    function sendAcceptWhatsAppNotification($booking_id, $conn) {
        $stmt = $conn->prepare("SELECT trip_type, from_address, to_address, date, time, mobile, driver_id, vehicle_id, total_amount FROM bookings WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows === 0) {
            $stmt->close();
            return false;
        }
        $booking = $res->fetch_assoc();
        $stmt->close();

        $mobile = $booking['mobile'];
        $driver_id = $booking['driver_id']; // driver phone number
        $vehicle_id = $booking['vehicle_id']; // vehicle plate
        $trip_type = $booking['trip_type'];
        $from = $booking['from_address'];
        $to = $booking['to_address'];
        $date = date('d-m-Y', strtotime($booking['date']));
        $time = date('h:i A', strtotime($booking['time']));
        $amount = $booking['total_amount'];

        // Fetch driver details
        $driver_name = "Assigned Driver";
        $driver_phone = $driver_id;
        $driver_stmt = $conn->prepare("SELECT full_name, phone_number FROM drivers WHERE phone_number = ? LIMIT 1");
        if ($driver_stmt) {
            $driver_stmt->bind_param("s", $driver_id);
            $driver_stmt->execute();
            $driver_res = $driver_stmt->get_result();
            if ($driver_res->num_rows > 0) {
                $drv = $driver_res->fetch_assoc();
                $driver_name = $drv['full_name'];
                $driver_phone = $drv['phone_number'];
            }
            $driver_stmt->close();
        }

        // Fetch user name
        $name = "Customer";
        $user_stmt = $conn->prepare("SELECT name FROM users WHERE phone_number = ? LIMIT 1");
        if ($user_stmt) {
            $user_stmt->bind_param("s", $mobile);
            $user_stmt->execute();
            $user_res = $user_stmt->get_result();
            if ($user_res->num_rows > 0) {
                $user = $user_res->fetch_assoc();
                if (!empty($user['name'])) {
                    $name = $user['name'];
                }
            }
            $user_stmt->close();
        }

        $message = "*Booking Confirmed!* 🎉\n\n" .
                   "Dear *{$name}*,\n" .
                   "Your booking *#{$booking_id}* with *Agni Car Rental* is officially confirmed!\n\n" .
                   "📍 *Trip Details:*\n" .
                   "• *Trip Type:* {$trip_type}\n" .
                   "• *Pickup:* {$from}\n";
        if (!empty($to)) {
            $message .= "• *Drop:* {$to}\n";
        }
        $message .= "• *Date & Time:* {$date} at {$time}\n" .
                    "• *Amount:* ₹{$amount}\n\n" .
                    "🚖 *Driver & Vehicle Details:*\n" .
                    "• *Driver Name:* {$driver_name}\n" .
                    "• *Driver Phone:* {$driver_phone}\n" .
                    "• *Vehicle Plate:* {$vehicle_id}\n\n" .
                    "The driver will reach your location on time. Please contact them if needed. Have a safe and happy journey! 🙏";

        return sendWhatsAppMessage($mobile, $message);
    }
}
?>
