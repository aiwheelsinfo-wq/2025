<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

// Include DB connection
include 'db_connect.php';



 
// (Premature update removed to prevent silencing alerts before notification)


// Load Composer's autoloader
require_once 'vendor/autoload.php';

use Google\Auth\Credentials\ServiceAccountCredentials;

// Firebase Project ID
$projectId = 'agni-car-app';

// Get Firebase access token
function getAccessToken() {
    $googleAccountKeyFilePath = '/home/o96ayd7ennr5/public_html/2025/agni-car-app-firebase-adminsdk-fbsvc-4f70f7d1f2.json';
    $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];
    $credentials = new ServiceAccountCredentials($scopes, $googleAccountKeyFilePath);
    $accessToken = $credentials->fetchAuthToken();
    return $accessToken['access_token'];
}

// Send FCM message
function sendFcmMessage($projectId, $message) {
    $accessToken = getAccessToken();
    $url = 'https://fcm.googleapis.com/v1/projects/' . $projectId . '/messages:send';

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));

    $result = curl_exec($ch);

    if (curl_errno($ch)) {
        return json_encode(["status" => "error", "message" => curl_error($ch)]);
    }

    curl_close($ch);
    return $result;
}

// Step 1: Fetch drivers with expiring/expired licenses and status not yet 'Notified'
$deviceTokens = [];

$sql = "SELECT DISTINCT drivers.fcm_token, drivers.phone_number, driver_vendor_join_Table.vendor_id FROM drivers JOIN driver_vendor_join_Table ON drivers.phone_number = driver_vendor_join_Table.driver_id WHERE DATEDIFF(drivers.license_doe, CURDATE()) BETWEEN 0 AND 30 AND drivers.fcm_token IS NOT NULL AND drivers.status != 'Notified' AND drivers.fcm_token != '' AND DATE(drivers.notification_date) != CURDATE()";



$result = mysqli_query($conn, $sql);

if (!$result) {
    echo json_encode(["status" => "error", "message" => "Query failed: " . mysqli_error($conn)]);
    exit;
}

while ($row = mysqli_fetch_assoc($result)) {
    $deviceTokens[] = ['fcm_token' => $row['fcm_token'], 'vendor_id' => $row['vendor_id']];
}



// Step 2: Send notifications
$responses = [];

foreach ($deviceTokens as $data) {
   $token = $data['fcm_token'];
   $phone = $data['vendor_id'];

    $message = [
        'message' => [
            'token' => $token,
            'notification' => [
                'title' => 'License Expiry Alert',
                'body' => 'Your driver license is expired or about to expire. Please update it in the app.'
            ]
        ]
    ];

    $response = sendFcmMessage($projectId, $message);





    // Update the driver's status to 'Notified'
   




if (!empty($token)) {
    $notifyDateSql = "UPDATE drivers SET status = 'Notified', notification_date = CURDATE() WHERE fcm_token = ?";
    $stmtt = $conn->prepare($notifyDateSql);
    $stmtt->bind_param("s", $token);
    $stmtt->execute();
}


    // Log response
    $responses[] = [
        "phone_number" => $phone,
        "token" => $token,
        "response" => json_decode($response, true)
    ];
}

echo json_encode([
    "status" => "success",
    "notifications_sent" => count($responses),
    "data" => $responses
]);

mysqli_close($conn);
?>
