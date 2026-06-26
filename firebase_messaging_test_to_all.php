<?php
require_once 'vendor/autoload.php';

use Google\Auth\Credentials\ServiceAccountCredentials; // Use the correct class

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

header('Content-Type:application/json');


include 'db_connect.php';

// Fetch all device tokens from the database
$deviceTokens = [];
$sql = "SELECT tocken FROM tockens"; // Replace with your actual table name and column
$result = mysqli_query($conn, $sql); // Assuming $conn is your MySQL connection

// Add each device token to the $deviceTokens array
while ($row = mysqli_fetch_assoc($result)) {
    $deviceTokens[] = $row['tocken'];
}


// Function to get a service account access token.
function getAccessToken() {
    // Path to your service account JSON key file. **IMPORTANT: Keep this file secure!**
    $googleAccountKeyFilePath = '/var/www/html/2025/agni-car-app-firebase-adminsdk-fbsvc-4f70f7d1f2.json';

    // Define the required scopes for Firebase messaging
    $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];

    // Create the ServiceAccountCredentials object
    $credentials = new ServiceAccountCredentials(
        $scopes, 
        $googleAccountKeyFilePath
    );

    // Fetch and return the access token
    $accessToken = $credentials->fetchAuthToken();
    return $accessToken['access_token'];
}

// Function to send the FCM message to multiple devices.
// Function to send the FCM message to multiple devices.
function sendFcmMessage($projectId, $deviceTokens, $message) {
    // Get a service account access token.
    $accessToken = getAccessToken();

    // FCM URL
    $url = 'https://fcm.googleapis.com/v1/projects/' . $projectId . '/messages:send';

    // Prepare the payload with multiple device tokens
    $messagePayload = [
        'message' => [
            'notification' => [
                'title' => $message['title'],
                'body' => $message['body'],
            ],
            'data' => $message['data'],
            'registration_ids' => $deviceTokens,  // Use 'registration_ids' instead of 'tokens'
        ],
    ];

    // Set the headers
    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ];

    // Initialize cURL session
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($messagePayload));

    // Execute the request
    $result = curl_exec($ch);

    // Error handling
    if (curl_errno($ch)) {
        echo 'Error: ' . curl_error($ch);
    }

    curl_close($ch);
    return $result;
}


// Replace with your Firebase project ID.
$projectId = 'agni-car-app';

// Your message payload (Customize as needed)
$message = [
    'title' => 'Drear!',
    'body' => 'You have new trip, Check the app',
    'data' => [
        'key1' => 'value1',
        'key2' => 'value2',
    ],
];

// Send the message and print the result.
$response = sendFcmMessage($projectId, $deviceTokens, $message);
echo "FCM Response: " . $response . "\n";

// Close the MySQL connection
$conn->close();
?>
