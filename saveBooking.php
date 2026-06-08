<?php
use Google\Auth\Credentials\ServiceAccountCredentials; // Use the correct class

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'vendor/autoload.php';



include 'db_connect.php';



$googleMapsApiKey = 'AIzaSyBz4vqQWuT-s_3UEWk6pnSMxSIt7QOZEqk';
$from_address = urlencode($_POST['from_address']);  // Customer's pickup address
$geocodeUrl = "https://maps.googleapis.com/maps/api/geocode/json?address=$from_address&key=$googleMapsApiKey";
$geoResponse = file_get_contents($geocodeUrl);
$geoData = json_decode($geoResponse, true);

if ($geoData['status'] == 'OK') {
    $ref_lat = $geoData['results'][0]['geometry']['location']['lat'];
    $ref_lon = $geoData['results'][0]['geometry']['location']['lng'];
} else {
    die("Error: Unable to get location coordinates.");
}
$radius_km = 20;


function getDistance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371; // Earth radius in km

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon / 2) * sin($dLon / 2);
         
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earth_radius * $c; // Distance in km
}


$deviceTokens = [];
$sql = "SELECT fcm_token, latitude, longitude FROM drivers";
$result = mysqli_query($conn, $sql);

while ($row = mysqli_fetch_assoc($result)) {
    $distance = getDistance($ref_lat, $ref_lon, $row['latitude'], $row['longitude']);
    if ($distance <= $radius_km) {
         $deviceTokens[] = $row['fcm_token'];
    }
}




    
    
    
$response = array();


 
$trip_type = $_POST['trip_type'];
$car_type = $_POST['car_type'];
$from_address = $_POST['from_address'];
$to_address = $_POST['to_address'];
$distance = $_POST['distance'];
$date = date('Y-m-d', strtotime($_POST['date']));
$tripTime = $_POST['tripTime']; 
$return_date = date('Y-m-d', strtotime($_POST['return_date']));
$return_time = $_POST['return_time']; 
$name = $_POST['name'];
$email = $_POST['email'];
$pincode = $_POST['pincode'];
$city = $_POST['city'];
$base_charge = $_POST['base_charge'];
$driver_ta = $_POST['driver_ta'];
$toll_charge = $_POST['toll_charge'];
$total_amount = $_POST['total_amount'];
$payment_type = $_POST['payment_type'];
$userNumber = $_POST['userNumber'];
$agent_commission = $_POST['agent_commission'];
$booking_authority = (!empty($commission) && $commission > 0) ? 'agent' : 'customer';
$agni_amount = $_POST['agni_amount'];
$vendor_amount = $_POST['vendor_amount'];
$user_type = $_POST['user_type'];
$customer_mob = $_POST['customer_mob'];
$gst =$_POST['gst'];
$gst_number=$_POST['gst_number'];
$business_name=$_POST['business_name'];
$business_address=$_POST['business_address'];
$business_pincode = $_POST['business_pincode'];
date_default_timezone_set('Asia/Kolkata');
$booked_at = date('Y-m-d H:i:s');
$bookingId = $_POST['bookingId'];



if($user_type == 'agent'){
    $contact_number = $customer_mob;
}else{
    $contact_number = $userNumber;
}



$otp = rand(1000, 9999);


// ðŸ”¹ First, insert/update user details into the `users` table..

    
    

$referred_by = null; // default

$referralSql = "SELECT referred_by FROM customer_referral WHERE customer_number = '$contact_number'";
$referralResult = mysqli_query($conn, $referralSql);

if ($referralResult && mysqli_num_rows($referralResult) > 0) {
    $referralRow = mysqli_fetch_assoc($referralResult);
    $referred_by = $referralRow['referred_by']; // store referred_by in variable
}

// 2️⃣ Check if user exists
$checkUserSql = "SELECT id FROM users WHERE phone_number = '$contact_number'";
$checkResult = mysqli_query($conn, $checkUserSql);

if (mysqli_num_rows($checkResult) > 0 ) {
    // User exists, update info
    $updateUserSql = "UPDATE users SET name='$name', email='$email', pincode='$pincode', city='$city' WHERE phone_number='$contact_number'";
    mysqli_query($conn, $updateUserSql);

    // 3️⃣ If referral exists, add 3 spins to the referrer
    if (!empty($referred_by)) {
        $updateSpinSql = "UPDATE users SET available_spin = available_spin + 3 WHERE phone_number = '$referred_by'";
        mysqli_query($conn, $updateSpinSql);
    }

} else {
    // Insert new user
    if (!empty($contact_number)) {
        $insertUserSql = "INSERT INTO users (name, email, phone_number, pincode, city, agent_id) 
                          VALUES ('$name', '$email', '$customer_mob', '$pincode', '$city', '$userNumber')";
        mysqli_query($conn, $insertUserSql);
    }
}



if($trip_type == 'One-way' && !empty($bookingId)){
        
        
        $updateBookingSql = "UPDATE bookings SET 
    trip_type = '$trip_type',
    car_type = '$car_type',
    from_address = '$from_address',
    to_address = '$to_address',
    distance = '$distance',
    date = '$date',
    time = '$tripTime',
    booked_at = '$booked_at',
    mobile = '$contact_number',
    agent_commission = '$agent_commission',
    base_charge = '$base_charge',
    driver_ta = '$driver_ta',
    toll_charge = '$toll_charge',
    total_amount = '$total_amount',
    payment_type = '$payment_type',
    return_date = '$return_date',
    return_time = '$return_time',
    otp = '$otp',
    agni_amount = '$agni_amount',
    vendor_amount = '$vendor_amount',
    booker_id = '$userNumber',
    gst = '$gst',
    gst_number = '$gst_number',
    business_name = '$business_name',
    business_address = '$business_address',
    business_pincode = '$business_pincode',
    booking_status = 'Pending'
WHERE id = '$bookingId'"; // Assumes 'id' is the primary key column name

if (mysqli_query($conn, $updateBookingSql)) {
    $response["success"] = true;
    $response["message"] = "Booking updated successfully";
    $response["booking_id"] = $bookingId;
} else {
    $response["success"] = false;
    $response["message"] = "Error: " . mysqli_error($conn);
}

echo json_encode($response);


    }
else{

// 🔽 Now insert booking info into `bookings` table (only relevant fields)
$insertBookingSql = "INSERT INTO bookings (trip_type, car_type, from_address, to_address, distance, date, time,booked_at, mobile, agent_commission, base_charge, driver_ta, toll_charge, total_amount, payment_type, return_date, return_time, otp, agni_amount, vendor_amount, booker_id, gst, gst_number, business_name, business_address, business_pincode, booking_status)
VALUES ('$trip_type', '$car_type', '$from_address', '$to_address', '$distance', '$date', '$tripTime', '$booked_at', '$contact_number', '$agent_commission', '$base_charge', '$driver_ta', '$toll_charge', '$total_amount', '$payment_type', '$return_date', '$return_time', '$otp', '$agni_amount', '$vendor_amount', '$userNumber', '$gst', '$gst_number', '$business_name', '$business_address', '$business_pincode', 'Pending')";


if (mysqli_query($conn, $insertBookingSql)) {
    $booking_id = mysqli_insert_id($conn); // get the auto-increment ID

    $response["success"] = true;
    $response["message"] = "Booking saved successfully";
    $response["booking_id"] = $booking_id;
} else {
    $response["success"] = false;
    $response["message"] = "Error: " . mysqli_error($conn);
}

echo json_encode($response);
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

// Fetch the fcm_token of the booking agent (booker)
$fcm_bookerToken = 'd14kUzAvRSiSGgFXgKr0ki:APA91bGnbj1b1aeMifNmY-l58bcvO1xluXIG_dSS1f4Ra7sN02IMfuU3HW032-JQu56PZHVn_7PUwO7l2DComSbXYP9f2o8epGDM0pV5ic8R3xRrUFkORe0';


if ($bookerTokenResult && mysqli_num_rows($bookerTokenResult) > 0) {
    $bookerRow = mysqli_fetch_assoc($bookerTokenResult);
    $fcm_bookerToken = $bookerRow['fcm_token'];
}


try {
    foreach ($deviceTokens as $index => $token) {
        $deviceToken = htmlspecialchars($token);
       


        $message = [
            'message' => [
                'token' => $deviceToken,
                'notification' => [
                    'title' => 'RENTOX RIDE',
                    'body' => 'New Trip Added in your app.'
                ]
            ]
        ];


        $response = sendFcmMessage($projectId, $message);
    }

    if (!empty($fcm_bookerToken)) {
        $message = [
            'message' => [
                'token' => $fcm_bookerToken,
                'notification' => [
                    'title' => 'AGNI RENTAL ADMIN',
                    'body' => 'We have a new Trip! Check the list'
                ]
            ]
        ];

        $response = sendFcmMessage($projectId, $message);
    }
} catch (Throwable $e) {
    // Ignore notification errors to ensure client receives the confirmation response
}


mysqli_close($conn);
?>
