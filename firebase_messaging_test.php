<?php
require_once 'vendor/autoload.php';

use Google\Auth\Credentials\ServiceAccountCredentials; // Use the correct class






include 'db_connect.php';

// Fetch all device tokens from the database
$deviceTokens = [];
$sql = "SELECT fcm_token FROM drivers";
$result = mysqli_query($conn, $sql);

while ($row = mysqli_fetch_assoc($result)) {
    $deviceTokens[] = $row['fcm_token'];
}


   



// Function to get a service account access token.
function getAccessToken() {
    // Path to your service account JSON key file. **IMPORTANT: Keep this file secure!**
    $googleAccountKeyFilePath = '/home/o96ayd7ennr5/public_html/2025/agni-car-app-firebase-adminsdk-fbsvc-4f70f7d1f2.json';

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

// Function to send the FCM message.
function sendFcmMessage($projectId, $message) {
    // Get a service account access token.
    $accessToken = getAccessToken();

    // FCM URL
    $url = 'https://fcm.googleapis.com/v1/projects/' . $projectId . '/messages:send';

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
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));

    // Execute the request
    $result = curl_exec($ch);

    // Error handling
    

    curl_close($ch);
    return $result;
}
$projectId = 'agni-car-app';



foreach ($deviceTokens as $index => $token) {
        $deviceToken = htmlspecialchars($token);
       
    
// Replace with the recipient's device token (from Flutter app)
 


// Your message payload (Customize as needed)
$message = [
    'message' => [
        'token' => $deviceToken,
        'notification' => [
            'title' => 'Hello from Firebase!',
            'body' => 'This is a test notification from your PHP script Gooooooood.',
        ],
        'data' => [  // Optional: Add custom data to your notification
            'key1' => 'value1',
            'key2' => 'value2',
        ],
    ],
];



// Send the message and print the result.
$response = sendFcmMessage($projectId, $message);

}
?>
