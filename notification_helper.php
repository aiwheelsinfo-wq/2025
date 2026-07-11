<?php
// ==========================================
// notification_helper.php — Agni Car Rental Alerts (WhatsApp, Email & SMS)
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
 * Send Transactional Email via Brevo API or fallback PHPMailer SMTP
 */
if (!function_exists('sendEmailAlert')) {
    function sendEmailAlert($to_email, $subject, $html_body, $to_name = 'Customer') {
        if (empty($to_email) || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Dynamically resolve Brevo credentials from server config to prevent repository secret leaks
        $config_paths = [
            __DIR__ . '/admin2025/restox-api-console/email_config.php',
            __DIR__ . '/../admin2025/restox-api-console/email_config.php',
            __DIR__ . '/../../admin2025/restox-api-console/email_config.php',
            '/var/www/html/admin2025/restox-api-console/email_config.php'
        ];

        $api_key = '';
        $sender_name = 'Agni Car Rental';
        $sender_email = 'ai.wheels.info@gmail.com';

        foreach ($config_paths as $config_path) {
            if (file_exists($config_path)) {
                require_once $config_path;
                if (defined('EMAIL_API_KEY')) {
                    $api_key = EMAIL_API_KEY;
                    $sender_name = EMAIL_SENDER_NAME;
                    $sender_email = EMAIL_SENDER_EMAIL;
                    break;
                }
            }
        }

        if (empty($api_key)) {
            error_log("sendEmailAlert: Could not locate EMAIL_API_KEY in configuration paths.");
            return false;
        }

        // Tier 0: Send via Brevo API (port 443 HTTPS - fast and bypasses local blocks)
        $url = 'https://api.brevo.com/v3/smtp/email';
        $headers = [
            'api-key: ' . $api_key,
            'Content-Type: application/json'
        ];
        $payload = [
            'sender' => ['name' => $sender_name, 'email' => $sender_email],
            'to' => [['email' => $to_email, 'name' => $to_name]],
            'subject' => $subject,
            'htmlContent' => $html_body
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code >= 200 && $http_code < 300) {
            return true;
        }

        // Tier 1 Fallback: SMTP via Gmail/PHPMailer if API fails
        try {
            $mailer_path = '/var/www/html/admin2025/restox-api-console/mailer.php';
            if (file_exists($mailer_path)) {
                require_once $mailer_path;
                if (function_exists('send_email_via_api')) {
                    return send_email_via_api($to_email, $subject, $html_body, $to_name);
                }
            }
        } catch (Throwable $e) {
            error_log("Email fallback error: " . $e->getMessage());
        }

        return false;
    }
}

/**
 * Send Transactional SMS via Fast2SMS Dev API
 */
if (!function_exists('sendSMSAlert')) {
    function sendSMSAlert($to, $message) {
        $formatted_phone = preg_replace('/[^0-9]/', '', $to);
        if (strlen($formatted_phone) > 10) {
            $formatted_phone = substr($formatted_phone, -10);
        }
        if (strlen($formatted_phone) !== 10) {
            return false;
        }

        $apiKey = 'p9J1ofaxrnDXePcsUTdlRu630Vg7KQiWMC24OEmjwFSByh8AH5R5n6sSBzCuvQATbf2g87hV9mtqd0GD';
        $url = 'https://www.fast2sms.com/dev/bulkV2';
        
        $payload = [
            'route' => 'q',
            'message' => $message,
            'language' => 'english',
            'flash' => 0,
            'numbers' => $formatted_phone
        ];

        $headers = [
            'authorization: ' . $apiKey,
            'Content-Type: application/json'
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res;
    }
}

/**
 * Send booking placement notification (WhatsApp, Email & SMS) to customer
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

        // Fetch user name and email
        $name = "Customer";
        $email = "";
        $user_stmt = $conn->prepare("SELECT name, email FROM users WHERE phone_number = ? LIMIT 1");
        if ($user_stmt) {
            $user_stmt->bind_param("s", $mobile);
            $user_stmt->execute();
            $user_res = $user_stmt->get_result();
            if ($user_res->num_rows > 0) {
                $user = $user_res->fetch_assoc();
                if (!empty($user['name'])) {
                    $name = $user['name'];
                }
                if (!empty($user['email']) && strpos($user['email'], '@') !== false) {
                    $email = $user['email'];
                }
            }
            $user_stmt->close();
        }

        // WhatsApp message
        $wa_message = "*Booking Received!* 🚗\n\n" .
                      "Dear *{$name}*,\n" .
                      "Thank you for booking with *Rentox Car Rental*! We have received your trip request.\n\n" .
                      "📍 *Booking Details:*\n" .
                      "• *Booking ID:* #{$booking_id}\n" .
                      "• *Trip Type:* {$trip_type}\n" .
                      "• *Pickup:* {$from}\n";
        if (!empty($to)) {
            $wa_message .= "• *Drop:* {$to}\n";
        }
        $wa_message .= "• *Date & Time:* {$date} at {$time}\n" .
                       "• *Amount:* ₹{$amount}\n\n" .
                       "We are currently assigning the nearest professional driver to your ride and will send you confirmation details shortly. Thank you!";

        // Email body (HTML)
        $email_subject = "Booking Received! - ID #{$booking_id}";
        $email_body = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 20px auto; padding: 25px; border: 1px solid #e5e7eb; border-radius: 12px; background-color: #ffffff; color: #1f2937;">
            <h2 style="color: #FFB300; margin-top: 0; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">Booking Received! 🚗</h2>
            <p style="font-size: 15px; line-height: 1.5;">Dear ' . htmlspecialchars($name) . ',</p>
            <p style="font-size: 15px; line-height: 1.5;">Thank you for booking with <strong>Rentox Car Rental</strong>! We have received your trip request. Here are your booking details:</p>
            
            <h3 style="color: #374151; margin-top: 20px; font-size: 16px;">📍 Booking Details:</h3>
            <table style="width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px;">
                <tr>
                    <td style="padding: 8px 0; font-weight: bold; color: #4b5563; border-bottom: 1px solid #f3f4f6; width: 130px;">Booking ID:</td>
                    <td style="padding: 8px 0; color: #1f2937; border-bottom: 1px solid #f3f4f6;">#' . $booking_id . '</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; font-weight: bold; color: #4b5563; border-bottom: 1px solid #f3f4f6;">Trip Type:</td>
                    <td style="padding: 8px 0; color: #1f2937; border-bottom: 1px solid #f3f4f6;">' . htmlspecialchars($trip_type) . '</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; font-weight: bold; color: #4b5563; border-bottom: 1px solid #f3f4f6;">Pickup Address:</td>
                    <td style="padding: 8px 0; color: #1f2937; border-bottom: 1px solid #f3f4f6;">' . htmlspecialchars($from) . '</td>
                </tr>';
        if (!empty($to)) {
            $email_body .= '
                <tr>
                    <td style="padding: 8px 0; font-weight: bold; color: #4b5563; border-bottom: 1px solid #f3f4f6;">Drop Address:</td>
                    <td style="padding: 8px 0; color: #1f2937; border-bottom: 1px solid #f3f4f6;">' . htmlspecialchars($to) . '</td>
                </tr>';
        }
        $email_body .= '
                <tr>
                    <td style="padding: 8px 0; font-weight: bold; color: #4b5563; border-bottom: 1px solid #f3f4f6;">Date & Time:</td>
                    <td style="padding: 8px 0; color: #1f2937; border-bottom: 1px solid #f3f4f6;">' . $date . ' at ' . $time . '</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; font-weight: bold; color: #4b5563; border-bottom: 1px solid #f3f4f6;">Total Amount:</td>
                    <td style="padding: 8px 0; font-weight: bold; color: #10b981; border-bottom: 1px solid #f3f4f6;">₹' . number_format($amount, 2) . '</td>
                </tr>
            </table>

            <p style="font-size: 15px; line-height: 1.5; margin-top: 25px;">We are currently assigning the nearest professional driver to your ride and will send you confirmation details shortly.</p>
            <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 20px 0;">
            <p style="font-size: 12px; color: #9ca3af; text-align: center;">&copy; 2026 Rentox Car Rental. All rights reserved.</p>
        </div>
        ';

        // SMS Message (concise)
        $sms_message = "Booking Received! ID: #{$booking_id}. Trip: {$trip_type}. Pickup: " . substr($from, 0, 30) . "... on {$date} at {$time}. Thanks for booking with Rentox Car Rental!";

        // Trigger notifications
        $wa_res = false;
        try {
            $wa_res = sendWhatsAppMessage($mobile, $wa_message);
        } catch (Throwable $e) {
            error_log("WhatsApp Booking Send error: " . $e->getMessage());
        }

        // sendSMSAlert SMS notification removed as requested
        /*
        try {
            sendSMSAlert($mobile, $sms_message);
        } catch (Throwable $e) {
            error_log("SMS Booking Send error: " . $e->getMessage());
        }
        */

        if (!empty($email)) {
            try {
                sendEmailAlert($email, $email_subject, $email_body, $name);
            } catch (Throwable $e) {
                error_log("Email Booking Send error: " . $e->getMessage());
            }
        }

        return $wa_res;
    }
}

/**
 * Send driver assignment/confirmation notification (WhatsApp, Email & SMS) to customer
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

        // Fetch user name and email
        $name = "Customer";
        $email = "";
        $user_stmt = $conn->prepare("SELECT name, email FROM users WHERE phone_number = ? LIMIT 1");
        if ($user_stmt) {
            $user_stmt->bind_param("s", $mobile);
            $user_stmt->execute();
            $user_res = $user_stmt->get_result();
            if ($user_res->num_rows > 0) {
                $user = $user_res->fetch_assoc();
                if (!empty($user['name'])) {
                    $name = $user['name'];
                }
                if (!empty($user['email']) && strpos($user['email'], '@') !== false) {
                    $email = $user['email'];
                }
            }
            $user_stmt->close();
        }

        // WhatsApp message
        $wa_message = "*Booking Confirmed!* 🎉\n\n" .
                      "Dear *{$name}*,\n" .
                      "Your booking *#{$booking_id}* with *Rentox Car Rental* is officially confirmed!\n\n" .
                      "📍 *Trip Details:*\n" .
                      "• *Trip Type:* {$trip_type}\n" .
                      "• *Pickup:* {$from}\n";
        if (!empty($to)) {
            $wa_message .= "• *Drop:* {$to}\n";
        }
        $wa_message .= "• *Date & Time:* {$date} at {$time}\n" .
                       "• *Amount:* ₹{$amount}\n\n" .
                       "🚖 *Driver & Vehicle Details:*\n" .
                       "• *Driver Name:* {$driver_name}\n" .
                       "• *Driver Phone:* {$driver_phone}\n" .
                       "• *Vehicle Plate:* {$vehicle_id}\n\n" .
                       "The driver will reach your location on time. Please contact them if needed. Have a safe and happy journey! 🙏";

        // Email body (HTML)
        $email_subject = "Booking Confirmed! - ID #{$booking_id}";
        $email_body = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 20px auto; padding: 25px; border: 1px solid #e5e7eb; border-radius: 12px; background-color: #ffffff; color: #1f2937;">
            <h2 style="color: #FFB300; margin-top: 0; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">Booking Confirmed! 🎉</h2>
            <p style="font-size: 15px; line-height: 1.5;">Dear ' . htmlspecialchars($name) . ',</p>
            <p style="font-size: 15px; line-height: 1.5;">Your booking <strong>#' . $booking_id . '</strong> with <strong>Rentox Car Rental</strong> is officially confirmed! Here are the details of your upcoming trip:</p>
            
            <h3 style="color: #374151; margin-top: 20px; font-size: 16px;">📍 Trip Information:</h3>
            <table style="width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px;">
                <tr>
                    <td style="padding: 8px 0; font-weight: bold; color: #4b5563; border-bottom: 1px solid #f3f4f6; width: 130px;">Trip Type:</td>
                    <td style="padding: 8px 0; color: #1f2937; border-bottom: 1px solid #f3f4f6;">' . htmlspecialchars($trip_type) . '</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; font-weight: bold; color: #4b5563; border-bottom: 1px solid #f3f4f6;">Pickup Address:</td>
                    <td style="padding: 8px 0; color: #1f2937; border-bottom: 1px solid #f3f4f6;">' . htmlspecialchars($from) . '</td>
                </tr>';
        if (!empty($to)) {
            $email_body .= '
                <tr>
                    <td style="padding: 8px 0; font-weight: bold; color: #4b5563; border-bottom: 1px solid #f3f4f6;">Drop Address:</td>
                    <td style="padding: 8px 0; color: #1f2937; border-bottom: 1px solid #f3f4f6;">' . htmlspecialchars($to) . '</td>
                </tr>';
        }
        $email_body .= '
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
                    <td style="padding: 8px 0; font-weight: bold; color: #4b5563; border-bottom: 1px solid #f3f4f6; width: 130px;">Driver Name:</td>
                    <td style="padding: 8px 0; color: #1f2937; border-bottom: 1px solid #f3f4f6;">' . htmlspecialchars($driver_name) . '</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; font-weight: bold; color: #4b5563; border-bottom: 1px solid #f3f4f6;">Driver Phone:</td>
                    <td style="padding: 8px 0; color: #1f2937; border-bottom: 1px solid #f3f4f6;">' . htmlspecialchars($driver_phone) . '</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; font-weight: bold; color: #4b5563; border-bottom: 1px solid #f3f4f6; width: 130px;">Vehicle Plate:</td>
                    <td style="padding: 8px 0; font-weight: bold; color: #4f46e5; border-bottom: 1px solid #f3f4f6;">' . htmlspecialchars($vehicle_id) . '</td>
                </tr>
            </table>
            
            <p style="font-size: 15px; line-height: 1.5; margin-top: 25px;">The driver will reach your location on time. Please contact them if needed. Have a safe and happy journey! 🙏</p>
            <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 20px 0;">
            <p style="font-size: 12px; color: #9ca3af; text-align: center;">&copy; 2026 Rentox Car Rental. All rights reserved.</p>
        </div>
        ';

        // SMS Message
        $sms_message = "Driver assigned for Booking #{$booking_id}! Driver: {$driver_name} ({$driver_phone}), Vehicle: {$vehicle_id}. Have a safe journey! - Rentox Car Rental";

        // Trigger notifications
        $wa_res = false;
        try {
            $wa_res = sendWhatsAppMessage($mobile, $wa_message);
        } catch (Throwable $e) {
            error_log("WhatsApp Accept Send error: " . $e->getMessage());
        }

        // sendSMSAlert SMS notification removed as requested
        /*
        try {
            sendSMSAlert($mobile, $sms_message);
        } catch (Throwable $e) {
            error_log("SMS Accept Send error: " . $e->getMessage());
        }
        */

        if (!empty($email)) {
            try {
                sendEmailAlert($email, $email_subject, $email_body, $name);
            } catch (Throwable $e) {
                error_log("Email Accept Send error: " . $e->getMessage());
            }
        }

        return $wa_res;
    }
}

/**
 * Send driver cancellation notification (WhatsApp, Email & SMS) to customer
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

        // Fetch user name and email
        $name = "Customer";
        $email = "";
        $user_stmt = $conn->prepare("SELECT name, email FROM users WHERE phone_number = ? LIMIT 1");
        if ($user_stmt) {
            $user_stmt->bind_param("s", $mobile);
            $user_stmt->execute();
            $user_res = $user_stmt->get_result();
            if ($user_res->num_rows > 0) {
                $user = $user_res->fetch_assoc();
                if (!empty($user['name'])) {
                    $name = $user['name'];
                }
                if (!empty($user['email']) && strpos($user['email'], '@') !== false) {
                    $email = $user['email'];
                }
            }
            $user_stmt->close();
        }

        // WhatsApp message
        $wa_message = "*Trip Update* 🚖\n\n" .
                      "Dear *{$name}*,\n" .
                      "We regret to inform you that your assigned driver has cancelled the trip for booking *#{$booking_id}*.\n\n" .
                      "⚠️ *Do not worry:* We are currently assigning another professional driver/vehicle to your booking immediately. We will update you with new details shortly. Thank you for your patience!";

        // Email body (HTML)
        $email_subject = "Trip Update - Booking #{$booking_id}";
        $email_body = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 20px auto; padding: 25px; border: 1px solid #e5e7eb; border-radius: 12px; background-color: #ffffff; color: #1f2937;">
            <h2 style="color: #D32F2F; margin-top: 0; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">Trip Update 🚖</h2>
            <p style="font-size: 15px; line-height: 1.5;">Dear ' . htmlspecialchars($name) . ',</p>
            <p style="font-size: 15px; line-height: 1.5;">We regret to inform you that your assigned driver has cancelled the trip for booking <strong>#' . $booking_id . '</strong>.</p>
            
            <p style="font-size: 15px; line-height: 1.6; color: #b45309; background-color: #fffbeb; padding: 15px; border-radius: 8px; border-left: 4px solid #f59e0b; font-weight: 500; margin: 20px 0;">
                ⚠️ <strong>Do not worry:</strong> We are currently assigning another professional driver/vehicle to your booking immediately. We will update you with new details shortly.
            </p>
            
            <p style="font-size: 14px; color: #6b7280; text-align: center; margin-top: 25px;">Thank you for your patience and cooperation.</p>
            <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 20px 0;">
            <p style="font-size: 12px; color: #9ca3af; text-align: center;">&copy; 2026 Rentox Car Rental. All rights reserved.</p>
        </div>
        ';

        // SMS Message
        $sms_message = "Trip Alert: Your driver cancelled booking #{$booking_id}. We are assigning another driver immediately and will update you shortly. - Rentox Car Rental";

        // Trigger notifications
        $wa_res = false;
        try {
            $wa_res = sendWhatsAppMessage($mobile, $wa_message);
        } catch (Throwable $e) {
            error_log("WhatsApp Cancel Send error: " . $e->getMessage());
        }

        // sendSMSAlert SMS notification removed as requested
        /*
        try {
            sendSMSAlert($mobile, $sms_message);
        } catch (Throwable $e) {
            error_log("SMS Cancel Send error: " . $e->getMessage());
        }
        */

        if (!empty($email)) {
            try {
                sendEmailAlert($email, $email_subject, $email_body, $name);
            } catch (Throwable $e) {
                error_log("Email Cancel Send error: " . $e->getMessage());
            }
        }

        return $wa_res;
    }
}
?>
