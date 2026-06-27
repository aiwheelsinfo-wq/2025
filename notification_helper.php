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

/**
 * Send driver cancellation notification to customer
 */
if (!function_exists('sendCancelWhatsAppNotification')) {
    function sendCancelWhatsAppNotification($booking_id, $conn) {
        $stmt = $conn->prepare("SELECT mobile FROM bookings WHERE id = ?");
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

        $message = "*Trip Update* 🚖\n\n" .
                   "Dear *{$name}*,\n" .
                   "We regret to inform you that your assigned driver has cancelled the trip for booking *#{$booking_id}*.\n\n" .
                   "⚠️ *Do not worry:* We are currently assigning another professional driver/vehicle to your booking immediately. We will update you with new details shortly. Thank you for your patience!";

        return sendWhatsAppMessage($mobile, $message);
    }
}

/**
 * Send booking confirmation email via Brevo transactional email API
 */
if (!function_exists('sendAcceptEmailNotification')) {
    function sendAcceptEmailNotification($booking_id, $to_email, $conn) {
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

        $subject = "Trip Booking Confirmed — ID #$booking_id";
        $body = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 20px auto; padding: 25px; border: 1px solid #e5e7eb; border-radius: 12px; background-color: #ffffff; color: #1f2937;">
            <h2 style="color: #FFB300; margin-top: 0; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">Booking Confirmed! 🎉</h2>
            <p style="font-size: 15px; line-height: 1.5;">Dear ' . htmlspecialchars($name) . ',</p>
            <p style="font-size: 15px; line-height: 1.5;">Your booking <strong>#' . $booking_id . '</strong> with <strong>Agni Car Rental</strong> is officially confirmed! Here are the details of your upcoming trip:</p>
            
            <h3 style="color: #374151; margin-top: 20px; font-size: 16px;">📍 Trip Information:</h3>
            <table style="width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px;">
                <tr>
                    <td style="padding: 8px 0; font-weight: bold; color: #4b5563; border-bottom: 1px solid #f3f4f6;">Trip Type:</td>
                    <td style="padding: 8px 0; color: #1f2937; border-bottom: 1px solid #f3f4f6;">' . htmlspecialchars($trip_type) . '</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; font-weight: bold; color: #4b5563; border-bottom: 1px solid #f3f4f6;">Pickup Address:</td>
                    <td style="padding: 8px 0; color: #1f2937; border-bottom: 1px solid #f3f4f6;">' . htmlspecialchars($from) . '</td>
                </tr>';
        if (!empty($to)) {
            $body .= '
                <tr>
                    <td style="padding: 8px 0; font-weight: bold; color: #4b5563; border-bottom: 1px solid #f3f4f6;">Drop Address:</td>
                    <td style="padding: 8px 0; color: #1f2937; border-bottom: 1px solid #f3f4f6;">' . htmlspecialchars($to) . '</td>
                </tr>';
        }
        $body .= '
                <tr>
                    <td style="padding: 8px 0; font-weight: bold; color: #4b5563; border-bottom: 1px solid #f3f4f6;">Date & Time:</td>
                    <td style="padding: 8px 0; color: #1f2937; border-bottom: 1px solid #f3f4f6;">' . $date . ' at ' . $time . '</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; font-weight: bold; color: #4b5563; border-bottom: 1px solid #f3f4f6;">Total Amount:</td>
                    <td style="padding: 8px 0; font-weight: bold; color: #10b981; border-bottom: 1px solid #f3f4f6;">₹' . number_format($amount, 2) . '</td>
                </tr>
            </table>

            <h3 style="color: #374151; margin-top: 25px; font-size: 16px;">🚖 Driver & Vehicle Details:</h3>
            <table style="width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px;">
                <tr>
                    <td style="padding: 8px 0; font-weight: bold; color: #4b5563; border-bottom: 1px solid #f3f4f6;">Driver Name:</td>
                    <td style="padding: 8px 0; color: #1f2937; border-bottom: 1px solid #f3f4f6;">' . htmlspecialchars($driver_name) . '</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; font-weight: bold; color: #4b5563; border-bottom: 1px solid #f3f4f6;">Driver Phone:</td>
                    <td style="padding: 8px 0; color: #1f2937; border-bottom: 1px solid #f3f4f6;">' . htmlspecialchars($driver_phone) . '</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; font-weight: bold; color: #4b5563; border-bottom: 1px solid #f3f4f6;">Vehicle Number:</td>
                    <td style="padding: 8px 0; font-weight: bold; color: #4f46e5; border-bottom: 1px solid #f3f4f6;">' . htmlspecialchars($vehicle_id) . '</td>
                </tr>
            </table>
            
            <p style="font-size: 14px; margin-top: 25px; color: #6b7280; text-align: center;">Thank you for choosing Agni Car Rental. Have a safe and comfortable trip!</p>
            <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 20px 0;">
            <p style="font-size: 12px; color: #9ca3af; text-align: center;">&copy; 2026 Agni Car Rental. All rights reserved.</p>
        </div>
        ';

        // Send via Brevo API
        $brevo_url = 'https://api.brevo.com/v3/smtp/email';
        $brevo_key = '';
        
        $config_paths = [
            __DIR__ . '/../admin2025/restox-api-console/email_config.php',
            __DIR__ . '/../../admin2025/restox-api-console/email_config.php',
            '/var/www/html/admin2025/restox-api-console/email_config.php'
        ];
        
        foreach ($config_paths as $config_path) {
            if (file_exists($config_path)) {
                require_once $config_path;
                if (defined('EMAIL_API_KEY')) {
                    $brevo_key = EMAIL_API_KEY;
                    break;
                }
            }
        }
        
        if (empty($brevo_key)) {
            error_log("Brevo API key could not be loaded from email_config.php");
            return false;
        }
        
        $payload = [
            'sender' => ['name' => 'Agni Car Rental', 'email' => 'ai.wheels.info@gmail.com'],
            'to' => [['email' => $to_email, 'name' => $name]],
            'subject' => $subject,
            'htmlContent' => $body
        ];
        
        $headers = [
            'api-key: ' . $brevo_key,
            'Content-Type: application/json'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $brevo_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res;
    }
}
?>
