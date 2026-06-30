<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

include('db_connect.php');
require_once(__DIR__ . '/notification_helper.php');

// Determine if we are handling a GET request from the mobile apps (re-routing Fast2SMS)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['numbers']) && isset($_GET['variables_values'])) {
    $phoneNumber = $_GET['numbers'];
    $otp = $_GET['variables_values'];
    $authorization = $_GET['authorization'] ?? '';
    $route = $_GET['route'] ?? 'dlt';
    $sender_id = $_GET['sender_id'] ?? 'agni';
    $message = $_GET['message'] ?? '170275';
    $flash = $_GET['flash'] ?? 0;

    // 1. Forward the SMS request to Fast2SMS
    $fast2smsUrl = "https://www.fast2sms.com/dev/bulkV2";
    $payload = [
        'route' => $route,
        'sender_id' => $sender_id,
        'message' => $message,
        'variables_values' => $otp,
        'flash' => $flash,
        'numbers' => $phoneNumber
    ];

    $headers = [
        'authorization: ' . $authorization,
        'Content-Type: application/json'
    ];

    $ch = curl_init($fast2smsUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $smsResponse = curl_exec($ch);
    $smsHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 2. Send the WhatsApp notification via UltraMsg
    $whatsappMessage = "Your OTP for Agni Car Rental is: $otp. Please do not share it with anyone.";
    try {
        sendWhatsAppMessage($phoneNumber, $whatsappMessage);
    } catch (Throwable $e) {
        error_log("WhatsApp OTP Send error: " . $e->getMessage());
    }

    // 3. Save OTP in the database for tracking/auditing
    $otp_sent_time = date('Y-m-d H:i:s');
    $query = "INSERT INTO users (phone_number, otp, otp_sent_time) 
              VALUES (?, ?, ?) 
              ON DUPLICATE KEY UPDATE otp = ?, otp_sent_time = ?";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("sssss", $phoneNumber, $otp, $otp_sent_time, $otp, $otp_sent_time);
        $stmt->execute();
        $stmt->close();
    }

    // Return the Fast2SMS response so the mobile apps parse it successfully
    if ($smsHttpCode === 200 && $smsResponse) {
        echo $smsResponse;
    } else {
        // Fallback response if Fast2SMS failed or timed out
        echo json_encode([
            "return" => true,
            "message" => "OTP sent via backup channel",
            "sms_error" => $smsResponse
        ]);
    }
    mysqli_close($conn);
    exit;
}

// Handle POST request (original send_otp.php behavior)
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_POST['phone_number'])) {
    $phoneNumber = $_POST['phone_number'] ?? '';
    if (empty($phoneNumber)) {
        echo json_encode(["return" => false, "message" => "Phone number required"]);
        exit;
    }

    // Generate a 6-digit OTP
    $otp = rand(100000, 999999);

    // 1. Send SMS via Fast2SMS using standard route
    $apiKey = 'p9J1ofaxrnDXePcsUTdlRu630Vg7KQiWMC24OEmjwFSByh8AH5R5n6sSBzCuvQATbf2g87hV9mtqd0GD';
    $fast2smsUrl = "https://www.fast2sms.com/dev/bulkV2";
    $payload = [
        'route' => 'q',
        'message' => "Your OTP is: $otp",
        'language' => 'english',
        'flash' => 0,
        'numbers' => $phoneNumber
    ];

    $headers = [
        'authorization: ' . $apiKey,
        'Content-Type: application/json'
    ];

    $ch = curl_init($fast2smsUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $smsResponse = curl_exec($ch);
    curl_close($ch);

    // 2. Send the WhatsApp notification via UltraMsg
    $whatsappMessage = "Your OTP for Agni Car Rental is: $otp. Please do not share it with anyone.";
    try {
        sendWhatsAppMessage($phoneNumber, $whatsappMessage);
    } catch (Throwable $e) {
        error_log("WhatsApp OTP Send error: " . $e->getMessage());
    }

    // 3. Save the phone number and OTP in the database
    $otp_sent_time = date('Y-m-d H:i:s');
    $query = "INSERT INTO users (phone_number, otp, otp_sent_time) 
              VALUES (?, ?, ?) 
              ON DUPLICATE KEY UPDATE otp = ?, otp_sent_time = ?";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("sssss", $phoneNumber, $otp, $otp_sent_time, $otp, $otp_sent_time);
        $result = $stmt->execute();
        if ($result) {
            echo json_encode(["return" => true, "message" => "OTP sent successfully"]);
        } else {
            echo json_encode(["return" => false, "message" => "Error: " . $stmt->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(["return" => false, "message" => "Error: " . $conn->error]);
    }

    mysqli_close($conn);
    exit;
}

echo json_encode(["return" => false, "message" => "Invalid request method"]);
exit;
?>
