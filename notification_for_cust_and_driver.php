<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

require_once 'vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;

// 🔐 ACCESS TOKEN
function getAccessToken() {
    $keyFile = '/home/o96ayd7ennr5/public_html/2025/agni-car-app-firebase-adminsdk-fbsvc-4f70f7d1f2.json';
    $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];

    $credentials = new ServiceAccountCredentials($scopes, $keyFile);
    $token = $credentials->fetchAuthToken();

    if (!isset($token['access_token'])) {
        die(json_encode([
            "success" => false,
            "error" => "Access token failed"
        ]));
    }

    return $token['access_token'];
}

// 🚀 SEND FCM
function sendFCM($projectId, $message) {

    $url = "https://fcm.googleapis.com/v1/projects/$projectId/messages:send";

    $headers = [
        "Authorization: Bearer " . getAccessToken(),
        "Content-Type: application/json"
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => json_encode($message),
    ]);

    $result = curl_exec($ch);

    if ($result === false) {
        die(json_encode([
            "success" => false,
            "error" => curl_error($ch)
        ]));
    }

    curl_close($ch);

    return json_decode($result, true);
}

// ----------------------
// 🎯 INPUT
// ----------------------
$data = json_decode(file_get_contents("php://input"), true);

$title  = $data['title'] ?? "Rentox";
$body   = $data['body'] ?? "New notification";
$target = $data['target'] ?? null;

if (!$target) {
    die(json_encode([
        "success" => false,
        "error" => "target is required (driver/customer)"
    ]));
}

// ----------------------
// 🎯 TOPIC SELECT
// ----------------------
switch ($target) {

    case "driver":
        $topic = "rentox_driver";
        break;

    case "customer":
        $topic = "rentox_customer";
        break;

    default:
        die(json_encode([
            "success" => false,
            "error" => "Invalid target"
        ]));
}

// ----------------------
// 🚀 MESSAGE
// ----------------------
$message = [
    "message" => [
        "topic" => $topic,
        "notification" => [
            "title" => $title,
            "body"  => $body
        ],
        "data" => [
            "type" => $target,
            "click_action" => "FLUTTER_NOTIFICATION_CLICK"
        ]
    ]
];

// ----------------------
// 🚀 SEND
// ----------------------
$response = sendFCM("agni-car-app", $message);

// ----------------------
// ✅ RESPONSE
// ----------------------
echo json_encode([
    "success" => true,
    "target" => $target,
    "topic" => $topic,
    "response" => $response
]);
?>