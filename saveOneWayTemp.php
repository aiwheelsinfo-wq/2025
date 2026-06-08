<?php
// Allow CORS and set content type
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
header("Access-Control-Allow-Methods: POST");

require_once 'vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials; // Use the correct class

include 'db_connect.php'; // Make sure this file connects to MySQL and defines $conn

// Read and decode the raw POST body as JSON
$data = json_decode(file_get_contents("php://input"), true);

// Validate required fields
if (
    isset($data['from']) && isset($data['to']) &&
    isset($data['date']) && isset($data['time']) &&
    isset($data['savedNumber'])
) {
    // Escape input values to prevent SQL injection
    $from = $conn->real_escape_string($data['from']);
    $to = $conn->real_escape_string($data['to']);
    $date = $conn->real_escape_string($data['date']);
    $time = $conn->real_escape_string($data['time']);
    $trip_type = 'One-way'; // Static value
    $booker_id = $conn->real_escape_string($data['savedNumber']);

    // Prepare the SQL insert statement
    $sql = "INSERT INTO bookings (from_address, to_address, date, time, trip_type, booker_id,booking_status)
            VALUES ('$from', '$to', '$date', '$time', '$trip_type', '$booker_id','temp')";

    // Execute and respond
    if ($conn->query($sql) === TRUE) {
        $newBookingId = $conn->insert_id; // Get the auto-incremented id
        echo json_encode([
            "success" => true,
            "message" => "Booking saved successfully",
            "booking_id" => $newBookingId
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "message" => "Error: " . $conn->error
        ]);
    }
} else {
    // If any required field is missing
    echo json_encode([
        "success" => false,
        "message" => "Missing required fields"
    ]);
}

//this below part is for notification to piyush sir
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
$fcm_bookerToken = 'd14kUzAvRSiSGgFXgKr0ki:APA91bGnbj1b1aeMifNmY-l58bcvO1xluXIG_dSS1f4Ra7sN02IMfuU3HW032-JQu56PZHVn_7PUwO7l2DComSbXYP9f2o8epGDM0pV5ic8R3xRrUFkORe0';

try {
    if (!empty($fcm_bookerToken)) {
        $message = [
            'message' => [
                'token' => $fcm_bookerToken,
                'notification' => [
                    'title' => 'AGNI RENTAL ADMIN',
                    'body' => 'We have a Enquiry! Check the Admin Panel'
                ]
            ]
        ];

        $response = sendFcmMessage($projectId, $message);
    }
} catch (Throwable $e) {
    // Ignore notification errors to ensure client receives the booking response
}
// Close DB connection
$conn->close();
?>
