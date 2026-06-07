<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');
require_once 'vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;

// Database connection (if needed to fetch driver tokens)
include 'db_connect.php';

// Function to get a service account access token
function getAccessToken() {
    $googleAccountKeyFilePath = '/home/o96ayd7ennr5/public_html/2025/agni-car-app-firebase-adminsdk-fbsvc-4f70f7d1f2.json';
    $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];
    $credentials = new ServiceAccountCredentials($scopes, $googleAccountKeyFilePath);
    $accessToken = $credentials->fetchAuthToken();
    return $accessToken['access_token'];
}

// Function to send FCM message
function sendFcmMessage($projectId, $deviceToken, $title, $body) {
    $accessToken = getAccessToken();
    $url = 'https://fcm.googleapis.com/v1/projects/' . $projectId . '/messages:send';

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ];

    $message = [
        'message' => [
            'token' => $deviceToken,
            'notification' => [
                'title' => $title,
                'body'  => $body,
            ]
        ]
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
    $result = curl_exec($ch);
    curl_close($ch);

    return $result;
}

// ----------------------
// Accept input (POST JSON or GET query)
// ----------------------
$title = "RENTOX";
$body  = "अपने अग्नि (ट्रैकॉन) ऐप को अभी अपडेट करें और नई सुविधा का तुरंत उपयोग शुरू करें।";

// POST JSON
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!empty($data['title'])) $title = $data['title'];
    if (!empty($data['body']))  $body  = $data['body'];
}

// GET query (for quick browser testing)
if (isset($_GET['title'])) $title = $_GET['title'];
if (isset($_GET['body']))  $body  = $_GET['body'];

// ----------------------
// Fetch all driver tokens
// ----------------------
$deviceTokens = [];
$sql = "SELECT fcm_token 
        FROM drivers 
        WHERE fcm_token IS NOT NULL 
          AND fcm_token != ''  ";

$result = mysqli_query($conn, $sql);

while ($row = mysqli_fetch_assoc($result)) {
    $deviceTokens[] = $row['fcm_token'];
}

$projectId = 'agni-car-app';

// ----------------------
// Send notifications
// ----------------------
$sent = 0;
foreach ($deviceTokens as $token) {
    sendFcmMessage($projectId, $token, $title, $body);
    $sent++;
}

// ----------------------
// Response
// ----------------------
echo json_encode([
    'success' => true,
    'message' => 'Notification sent to drivers',
    'sent_tokens' => $sent,
    'title' => $title,
    'body' => $body
]);

mysqli_close($conn);
?>
