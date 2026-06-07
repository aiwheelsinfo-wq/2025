<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

// Include DB connection
include 'db_connect.php';

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

// Step 1: Get FCM tokens for drivers with expiring documents (10 days or fewer)
$deviceTokens = [];

$sql = "SELECT DISTINCT drivers.fcm_token, cars.vehicle_number
FROM cars
JOIN drivers ON cars.owner_id = drivers.phone_number
WHERE (
    DATEDIFF(cars.insurance_doe, CURDATE()) BETWEEN 0 AND 10
OR DATEDIFF(cars.puc_doe, CURDATE()) BETWEEN 0 AND 10
OR DATEDIFF(cars.texi_permit_doe, CURDATE()) BETWEEN 0 AND 10
OR DATEDIFF(cars.fitness_certificate_doe, CURDATE()) BETWEEN 0 AND 10

)
AND drivers.fcm_token IS NOT NULL 
AND drivers.fcm_token != '' AND DATE(cars.notification_date) != CURDATE()";

$result = mysqli_query($conn, $sql);

if (!$result) {
    echo json_encode(["status" => "error", "message" => "Query failed: " . mysqli_error($conn)]);
    exit;
}

while ($row = mysqli_fetch_assoc($result)) {
    $deviceTokens[] = ['fcm_token' => $row['fcm_token'], 'vehicle_number' => $row['vehicle_number']];
}

// Step 2: Send notifications to the drivers whose documents are expiring soon
$responses = [];

foreach ($deviceTokens as $data) {
    $token = $data['fcm_token'];
    $carId = $data['vehicle_number'];

    // Message to be sent to the driver
    $message = [
        'message' => [
            'token' => $token,
            'notification' => [
                'title' => 'Document Expiry Alert',
                'body' => "Your car ($carId) document will expire soon. Please update it in the app."
            ]
        ]
    ];

    // Send the FCM message (send notification if document is expiring in 10 days or less)
    $response = sendFcmMessage($projectId, $message);

    // Collect the responses for logging purposes
    $responses[] = [
        "token" => $token,
        "response" => json_decode($response, true)
    ];
}

// Step 3: After the document has expired (remaining days <= 0), update the status to 'Notified'
$updateSql = "UPDATE cars SET status = 'Notified' 
WHERE (
    DATEDIFF(cars.insurance_doe, CURDATE()) <= 0
    OR DATEDIFF(cars.puc_doe, CURDATE()) <= 0
    OR DATEDIFF(cars.texi_permit_doe, CURDATE()) <= 0
    OR DATEDIFF(cars.fitness_certificate_doe, CURDATE()) <= 0
)

              ";

mysqli_query($conn, $updateSql);


if (!empty($token)) {
    $notifyDateSql = "UPDATE cars SET notification_date = CURDATE() WHERE fcm_token = ?";
    $stmtt = $conn->prepare($notifyDateSql);
    $stmtt->bind_param("s", $token);
    $stmtt->execute();
}

// Step 4: Return the results
echo json_encode([
    "status" => "success",
    "notifications_sent" => count($responses),
    "data" => $responses
]);

mysqli_close($conn);
?>
