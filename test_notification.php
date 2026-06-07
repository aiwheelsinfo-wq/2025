<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
require_once 'vendor/autoload.php';

use Google\Auth\Credentials\ServiceAccountCredentials;

// =====================
// CONFIGURATION
// =====================
$projectId = 'agni-car-app';
$googleAccountKeyFilePath = '/home/o96ayd7ennr5/public_html/2025/agni-car-app-firebase-adminsdk-fbsvc-4f70f7d1f2.json';

// Your target FCM token
$deviceToken = 'fhuWBakfS6ynqnWS80pP0x:APA91bEJqithx-OA-scIunwD1NKWMgewY6g6a1eRAygWvpXvY1goH_RxtJjVQ0dIPdYS4KRUGNQNKpdmPK8wVKztUI4ilIDM-FUh-BNB8vvncfNO3oWE-Cw';

// Notification content
$title = "Hello from Agni 🚗";
$body  = "Your FCM test notification is working perfectly!";

// =====================
// FUNCTION: Get Access Token
// =====================
function getAccessToken($jsonPath) {
    $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];
    $credentials = new ServiceAccountCredentials($scopes, $jsonPath);
    $accessToken = $credentials->fetchAuthToken();
    return $accessToken['access_token'];
}

// =====================
// FUNCTION: Send FCM Message (V1)
// =====================
function sendFcmMessage($projectId, $deviceToken, $title, $body, $accessToken) {
    $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

    $headers = [
        "Authorization: Bearer {$accessToken}",
        "Content-Type: application/json"
    ];

    $message = [
        "message" => [
            "token" => $deviceToken,
            "notification" => [
                "title" => $title,
                "body"  => $body
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
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ["success" => false, "error" => $error];
    }

    return json_decode($result, true);
}

// =====================
// EXECUTE
// =====================
try {
    $accessToken = getAccessToken($googleAccountKeyFilePath);
    $response = sendFcmMessage($projectId, $deviceToken, $title, $body, $accessToken);

    echo json_encode([
        "success" => true,
        "project_id" => $projectId,
        "target_token" => $deviceToken,
        "response" => $response
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
?>
