<?php
require_once __DIR__ . '/vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;

if (!function_exists('getDistance')) {
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
}

function getFcmAccessToken() {
    $googleAccountKeyFilePath = __DIR__ . '/agni-car-app-firebase-adminsdk-fbsvc-4f70f7d1f2.json';
    if (!file_exists($googleAccountKeyFilePath)) {
        throw new Exception("Google Service Account Key file not found: " . $googleAccountKeyFilePath);
    }
    $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];
    $credentials = new ServiceAccountCredentials($scopes, $googleAccountKeyFilePath);
    $accessToken = $credentials->fetchAuthToken();
    return $accessToken['access_token'];
}

function sendSingleFcmNotification($accessToken, $projectId, $token, $notificationData) {
    $url = 'https://fcm.googleapis.com/v1/projects/' . $projectId . '/messages:send';
    
    $messagePayload = [
        'message' => [
            'token' => $token,
            'notification' => [
                'title' => $notificationData['title'],
                'body' => $notificationData['body'],
            ],
            'data' => $notificationData['data'],
            'android' => [
                'priority' => 'HIGH',
                'notification' => [
                    'channel_id' => 'rentox_ride_alert_channel',
                    'sound' => 'preview',
                    'notification_priority' => 'PRIORITY_MAX',
                    'visibility' => 'PUBLIC',
                    'default_vibrate_timings' => false,
                    'vibrate_timings' => [
                        '0.0s', '1.0s', '0.5s', '1.0s', '0.5s', '1.0s', '0.5s', '1.0s', '0.5s', '1.0s',
                        '0.5s', '1.0s', '0.5s', '1.0s', '0.5s', '1.0s', '0.5s', '1.0s', '0.5s', '1.5s'
                    ]
                ]
            ]
        ],
    ];

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($messagePayload));

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        error_log("FCM Error for token $token: " . $error_msg);
    } elseif ($httpCode !== 200) {
        error_log("FCM Error Response for token $token (HTTP $httpCode): " . $result);
    }
    
    curl_close($ch);
    return $result;
}

if (!function_exists('get_vendor_free_capacity')) {
    /**
     * Calculates the free driver/vehicle capacity for a vendor or driver.
     * Returns the count of available drivers/vehicles.
     * If <= 0, all fleet assets are currently busy on active trips (Accepted/Started/On-Duty).
     */
    function get_vendor_free_capacity($conn, $vendor_phone) {
        if (empty($vendor_phone)) {
            return 1;
        }
        
        $escaped_phone = mysqli_real_escape_string($conn, $vendor_phone);
        
        // 1. Get all driver phone numbers associated with this vendor (including vendor themselves)
        $linked_driver_phones = [$escaped_phone];
        $dv_sql = "SELECT driver_id FROM driver_vendor_join_Table WHERE vendor_id = '$escaped_phone'";
        $dv_res = mysqli_query($conn, $dv_sql);
        if ($dv_res) {
            while ($dv_row = mysqli_fetch_assoc($dv_res)) {
                if (!empty($dv_row['driver_id'])) {
                    $linked_driver_phones[] = mysqli_real_escape_string($conn, $dv_row['driver_id']);
                }
            }
        }
        $linked_driver_phones = array_unique($linked_driver_phones);
        $total_drivers = count($linked_driver_phones);
        
        // 2. Query active/busy trips currently assigned to this vendor or any of their linked drivers
        // Trips with status Accepted, Started, On-Duty, Arrived are currently running/busy.
        // Completed, Cancelled, and Customer Cancelled release the driver.
        $drivers_in_list = "'" . implode("','", $linked_driver_phones) . "'";
        $busy_sql = "SELECT COUNT(DISTINCT id) AS busy_count 
                     FROM bookings 
                     WHERE (vender_id = '$escaped_phone' OR driver_id IN ($drivers_in_list))
                       AND booking_status IN ('Accepted', 'Started', 'On-Duty', 'Arrived', 'On-Trip')";
        $busy_res = mysqli_query($conn, $busy_sql);
        $busy_count = 0;
        if ($busy_res) {
            $busy_row = mysqli_fetch_assoc($busy_res);
            $busy_count = intval($busy_row['busy_count'] ?? 0);
        }
        
        $free_capacity = $total_drivers - $busy_count;
        return $free_capacity;
    }
}

function trigger_new_booking_notification($booking_id, $ref_lat = null, $ref_lon = null) {
    global $conn; // Access the database connection from the parent scope
    
    if (empty($booking_id)) {
        error_log("Notification Error: Empty booking ID");
        return;
    }
    
    // 1. Fetch booking details
    $stmt = $conn->prepare("SELECT id, trip_type, from_address, to_address, vendor_amount FROM bookings WHERE id = ?");
    if (!$stmt) {
        error_log("Notification DB Error: " . $conn->error);
        return;
    }
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        error_log("Notification Error: Booking not found for ID " . $booking_id);
        $stmt->close();
        return;
    }
    
    $booking = $res->fetch_assoc();
    $stmt->close();
    
    $booking_id_str = (string)$booking['id'];
    $trip_type = $booking['trip_type'] ?? '';
    $pickup_location = $booking['from_address'] ?? '';
    $drop_location = $booking['to_address'] ?? '';
    $vendor_amount = $booking['vendor_amount'] ?? '0.00';

    // Geocode customer's pickup address using Google Geocoding API if coordinates are not provided
    if ($ref_lat === null || $ref_lon === null) {
        $googleMapsApiKey = 'AIzaSyC41U3p08LqY8G15ruxDCEfTvBLkG_OrsM';
        $geocodeUrl = "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($pickup_location) . "&key=$googleMapsApiKey";
        
        $ref_lat = null;
        $ref_lon = null;
        try {
            $geoResponse = file_get_contents($geocodeUrl);
            if ($geoResponse) {
                $geoData = json_decode($geoResponse, true);
                if ($geoData['status'] === 'OK') {
                    $ref_lat = $geoData['results'][0]['geometry']['location']['lat'];
                    $ref_lon = $geoData['results'][0]['geometry']['location']['lng'];
                }
            }
        } catch (Throwable $e) {
            error_log("Geocoding failed for notification: " . $e->getMessage());
        }
    }
    
    // 2. Fetch active vendors/drivers with FCM tokens, latitude, longitude, and city
    $vendors_sql = "SELECT driver_id, phone_number, fcm_token, latitude, longitude, driver_city FROM drivers WHERE status = 'active' AND (userType = 'vendor' OR userType = 'driver' OR userType = '' OR userType IS NULL) AND fcm_token IS NOT NULL AND fcm_token != ''";
    $vendors_res = mysqli_query($conn, $vendors_sql);
    if (!$vendors_res) {
        error_log("Notification DB Error fetching vendors: " . mysqli_error($conn));
        return;
    }
    
    $tokens = [];
    $is_local_taxi = (stripos($trip_type, 'Local-taxi') !== false || stripos($trip_type, 'taxi') !== false);
    $is_local_duty = (stripos($trip_type, 'Local-duty') !== false);

    if ($is_local_taxi) {
        $radius_km = 5;
    } elseif ($is_local_duty) {
        $radius_km = 10;
    } else {
        $radius_km = 20; // One-Way & Round-Trip outstation
    }

    $total_active_checked = 0;
    $matched_drivers = 0;

    while ($row = mysqli_fetch_assoc($vendors_res)) {
        $total_active_checked++;
        $d_lat = floatval($row['latitude'] ?? 0);
        $d_lon = floatval($row['longitude'] ?? 0);
        $has_valid_gps = ($d_lat != 0.0 && $d_lon != 0.0);

        if ($ref_lat !== null && $ref_lon !== null) {
            if ($has_valid_gps) {
                $distance = getDistance($ref_lat, $ref_lon, $d_lat, $d_lon);
                if ($distance > $radius_km) {
                    continue; // Skip drivers further than allowable radius
                }
            } else {
                // Driver has no valid GPS coordinates recorded
                if ($is_local_taxi || $is_local_duty) {
                    // Strict rule: Local taxi & local duty require verified nearby GPS (<= 5km / 10km)
                    continue;
                } else {
                    // For Outstation One-Way/Round-Trip: only include if driver's registered city matches pickup location
                    $driver_city = trim($row['driver_city'] ?? '');
                    if (empty($driver_city) || stripos($pickup_location, $driver_city) === false) {
                        continue; // Cannot verify driver is in the outstation pickup zone
                    }
                }
            }
        } elseif ($is_local_taxi || $is_local_duty) {
            // Cannot geocode pickup location for local trip, skip to prevent statewide broadcast
            continue;
        }

        // Check vendor/driver free fleet capacity
        // If solo driver is on an accepted trip, free_capacity will be <= 0 and notification is skipped.
        // If vendor has more drivers than active trips, free_capacity > 0 and notification is sent.
        $driver_phone = $row['phone_number'];
        $free_capacity = get_vendor_free_capacity($conn, $driver_phone);
        if ($free_capacity <= 0) {
            error_log("Notification Dispatch: Driver/Vendor $driver_phone has no free capacity ($free_capacity available). Skipping alert.");
            continue;
        }

        $tokens[] = $row['fcm_token'];
        $matched_drivers++;
    }

    error_log("Notification Dispatch: Trip [$trip_type], Radius [{$radius_km}km], Pickup: [$pickup_location]. Active checked: $total_active_checked, Dispatched: $matched_drivers.");
    
    if (empty($tokens)) {
        error_log("Notification Info: No active vendors within {$radius_km}km found with FCM tokens.");
        return;
    }
    
    // 3. Prepare FCM message
    $keyFileContent = json_decode(file_get_contents(__DIR__ . '/agni-car-app-firebase-adminsdk-fbsvc-4f70f7d1f2.json'), true);
    $projectId = $keyFileContent['project_id'] ?? 'agnicarrentaldriver-8fb07';
    
    $bodyText = "From: " . $pickup_location;
    if (!empty($drop_location)) {
        $bodyText .= "\nTo: " . $drop_location;
    }
    $bodyText .= "\nEarnings: ₹" . number_format((float)$vendor_amount, 2);

    $notificationData = [
        'title' => 'New Trip Available - ' . $trip_type,
        'body' => $bodyText,
        'data' => [
            'booking_id' => $booking_id_str,
            'booking_type' => $trip_type,
            'pickup_location' => $pickup_location,
            'drop_location' => $drop_location,
            'vendor_amount' => (string)$vendor_amount,
            'notification_type' => 'new_booking'
        ]
    ];
    
    // Get FCM access token
    try {
        $accessToken = getFcmAccessToken();
    } catch (Exception $e) {
        error_log("FCM Auth Error getting access token: " . $e->getMessage());
        return;
    }
    
    // 4. Send one request per vendor token, auto-cleanup invalid tokens
    foreach ($tokens as $token) {
        $token = trim($token);
        if (empty($token)) {
            continue; // Skip invalid or empty FCM tokens
        }
        try {
            $result = sendSingleFcmNotification($accessToken, $projectId, $token, $notificationData);
            // Auto-cleanup invalid tokens from database
            if ($result) {
                $decoded = json_decode($result, true);
                $errorCode = $decoded['error']['details'][0]['errorCode'] ?? null;
                $httpStatus = $decoded['error']['status'] ?? null;
                if ($errorCode === 'UNREGISTERED' || $errorCode === 'SENDER_ID_MISMATCH') {
                    // Clean up this invalid token to prevent future failed sends
                    $clean_stmt = $conn->prepare("UPDATE drivers SET fcm_token = NULL WHERE fcm_token = ?");
                    if ($clean_stmt) {
                        $clean_stmt->bind_param("s", $token);
                        $clean_stmt->execute();
                        $clean_stmt->close();
                        error_log("FCM: Cleaned up invalid token ($errorCode): " . substr($token, 0, 30) . "...");
                    }
                }
            }
        } catch (Throwable $e) {
            error_log("FCM Exception sending to token $token: " . $e->getMessage());
            // Continue sending notifications even if one vendor notification fails
        }
    }
}
?>
