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
    
    // High-Priority Data-Only Message (Uber/Ola Industry Standard)
    // Trip alerts must NOT contain a top-level 'notification' block so Android/Google Play Services
    // does not bypass the app and play hardcoded ringtones/vibrations at the OS system level.
    // Instead, the app's Flutter background handler (_firebaseMessagingBackgroundHandler)
    // executes and strictly respects the vendor/driver's Profile vibration and siren sound toggles.
    $messagePayload = [
        'message' => [
            'token' => $token,
            'data' => $notificationData['data'],
            'android' => [
                'priority' => 'HIGH'
            ],
            'apns' => [
                'headers' => [
                    'apns-priority' => '10'
                ],
                'payload' => [
                    'aps' => [
                        'content-available' => 1
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
     * Differentiates between today's active trips and future advance reservations:
     * - Trips with status Started, On-Duty, Arrived, On-Trip are currently running.
     * - Trips with status Accepted only occupy today's capacity if scheduled for today.
     * - Trips scheduled for future dates (> today) do NOT block today's on-demand dispatches.
     */
    function get_vendor_free_capacity($conn, $vendor_phone, $target_booking_date = null, $target_return_date = null) {
        if (empty($vendor_phone)) {
            return 1;
        }
        
        $escaped_phone = mysqli_real_escape_string($conn, $vendor_phone);
        $today = date('Y-m-d');
        
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
        $drivers_in_list = "'" . implode("','", $linked_driver_phones) . "'";
        
        // If driver/vendor currently has a running trip or an accepted trip for TODAY, check current occupancy:
        $active_now_sql = "SELECT COUNT(DISTINCT id) AS active_now 
                           FROM bookings 
                           WHERE (vender_id = '$escaped_phone' OR driver_id IN ($drivers_in_list))
                             AND (
                                 booking_status IN ('Started', 'On-Duty', 'Arrived', 'On-Trip', 'In-Transit')
                                 OR (
                                     booking_status = 'Accepted' 
                                     AND date <= '$today' 
                                     AND (return_date IS NULL OR return_date = '1970-01-01' OR return_date = '0000-00-00' OR return_date >= '$today')
                                 )
                             )";
        $active_now_res = mysqli_query($conn, $active_now_sql);
        if ($active_now_res) {
            $an_row = mysqli_fetch_assoc($active_now_res);
            $active_now = intval($an_row['active_now'] ?? 0);
            if ($active_now >= $total_drivers && (empty($target_booking_date) || $target_booking_date <= $today)) {
                // Partner has no free capacity right now — currently on duty with an accepted/active trip today
                return 0;
            }
        }
        
        if (empty($target_booking_date) || $target_booking_date <= $today) {
            // Checking capacity for TODAY:
            // Driver is busy today only if they are on an active running trip (Started/On-Duty/Arrived/On-Trip),
            // OR have an Accepted trip for TODAY (or an ongoing multi-day trip spanning today).
            $busy_sql = "SELECT COUNT(DISTINCT id) AS busy_count 
                         FROM bookings 
                         WHERE (vender_id = '$escaped_phone' OR driver_id IN ($drivers_in_list))
                           AND (
                               booking_status IN ('Started', 'On-Duty', 'Arrived', 'On-Trip')
                               OR (
                                   booking_status = 'Accepted' 
                                   AND (
                                       date = '$today'
                                       OR (return_date IS NOT NULL AND return_date != '1970-01-01' AND return_date != '0000-00-00' AND date <= '$today' AND return_date >= '$today')
                                   )
                               )
                           )";
        } else {
            // Checking capacity for a FUTURE date ($target_booking_date > today):
            // Busy count checks if the driver already has an overlapping trip on that specific future date.
            $escaped_target_date = mysqli_real_escape_string($conn, $target_booking_date);
            $escaped_target_end = (!empty($target_return_date) && $target_return_date >= $target_booking_date && $target_return_date != '1970-01-01' && $target_return_date != '0000-00-00') 
                ? mysqli_real_escape_string($conn, $target_return_date) 
                : $escaped_target_date;
                
            $busy_sql = "SELECT COUNT(DISTINCT id) AS busy_count 
                         FROM bookings 
                         WHERE (vender_id = '$escaped_phone' OR driver_id IN ($drivers_in_list))
                           AND booking_status IN ('Accepted', 'Started', 'On-Duty', 'Arrived', 'On-Trip')
                           AND (
                               date <= '$escaped_target_end'
                               AND (CASE WHEN return_date IS NOT NULL AND return_date != '1970-01-01' AND return_date != '0000-00-00' AND return_date >= date THEN return_date ELSE date END) >= '$escaped_target_date'
                           )";
        }
        
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

if (!function_exists('is_driver_vehicle_match')) {
    /**
     * Checks if a driver's vehicle(s) match the requested booking car_type.
     * Handles category variations, sub-models, and multiple fleet vehicles.
     *
     * @param string $booking_car_type (e.g. 'Sedan', 'Hatchback', 'SUV', 'Ertiga', 'Crysta', 'Innova')
     * @param string $driver_vehicle_str Comma-separated or single string of driver's vehicle types/names
     * @return bool
     */
    function is_driver_vehicle_match($booking_car_type, $driver_vehicle_str) {
        if (empty($booking_car_type)) {
            return true;
        }
        $booking_type = strtolower(trim((string)$booking_car_type));
        if ($booking_type === 'all' || $booking_type === 'any' || $booking_type === '') {
            return true;
        }

        $driver_str = strtolower(trim((string)$driver_vehicle_str));
        if (empty($driver_str)) {
            return false;
        }

        $wants_sedan = (
            stripos($booking_type, 'sedan') !== false ||
            stripos($booking_type, 'sadan') !== false ||
            stripos($booking_type, 'sadden') !== false ||
            stripos($booking_type, 'dzire') !== false ||
            stripos($booking_type, 'aura') !== false ||
            stripos($booking_type, 'etios') !== false ||
            stripos($booking_type, 'amaze') !== false
        );

        $wants_hatchback = (
            stripos($booking_type, 'hatch') !== false ||
            stripos($booking_type, 'hack') !== false ||
            stripos($booking_type, 'hash') !== false ||
            stripos($booking_type, 'wagon') !== false ||
            stripos($booking_type, 'celerio') !== false ||
            stripos($booking_type, 'tiago') !== false ||
            stripos($booking_type, 'i10') !== false
        );

        $wants_ertiga = (
            stripos($booking_type, 'ertiga') !== false ||
            stripos($booking_type, 'ertigl') !== false ||
            stripos($booking_type, 'romiyon') !== false ||
            stripos($booking_type, 'rumion') !== false
        );

        $wants_crysta_innova = (
            stripos($booking_type, 'crysta') !== false ||
            stripos($booking_type, 'innova') !== false
        );

        $wants_suv = (
            stripos($booking_type, 'suv') !== false ||
            stripos($booking_type, 'auv') !== false ||
            stripos($booking_type, 'xuv') !== false ||
            stripos($booking_type, 'mpv') !== false ||
            $wants_ertiga ||
            $wants_crysta_innova
        );

        $wants_tempo = (
            stripos($booking_type, 'tempo') !== false ||
            stripos($booking_type, 'traveller') !== false ||
            stripos($booking_type, 'urabainia') !== false
        );

        $entries = preg_split('/[,\|\n\/]+/', $driver_str);

        foreach ($entries as $entry) {
            $e = trim(strtolower($entry));
            if (empty($e)) continue;

            $is_sedan = (
                stripos($e, 'sedan') !== false ||
                stripos($e, 'sadan') !== false ||
                stripos($e, 'sadden') !== false ||
                stripos($e, 'seden') !== false ||
                stripos($e, 'sedaan') !== false ||
                stripos($e, 'sudan') !== false ||
                stripos($e, 'dzire') !== false ||
                stripos($e, 'dizayr') !== false ||
                stripos($e, 'aura') !== false ||
                stripos($e, 'etios') !== false ||
                stripos($e, 'amaze') !== false ||
                stripos($e, 'bmw') !== false
            );

            $is_hatchback = (
                stripos($e, 'hatch') !== false ||
                stripos($e, 'hack back') !== false ||
                stripos($e, 'hashback') !== false ||
                stripos($e, 'wagon') !== false ||
                stripos($e, 'celerio') !== false ||
                stripos($e, 'tiago') !== false ||
                stripos($e, 'i10') !== false ||
                stripos($e, 'alto') !== false ||
                stripos($e, 'kwid') !== false ||
                (stripos($e, 'swift') !== false && stripos($e, 'dzire') === false && stripos($e, 'dizayr') === false)
            );

            $is_ertiga = (
                stripos($e, 'ertiga') !== false ||
                stripos($e, 'ertigl') !== false ||
                stripos($e, 'romiyon') !== false ||
                stripos($e, 'rumion') !== false
            );

            $is_crysta_innova = (
                stripos($e, 'crysta') !== false ||
                stripos($e, 'innova') !== false
            );

            $is_suv = (
                stripos($e, 'suv') !== false ||
                stripos($e, 'auv') !== false ||
                stripos($e, 'xuv') !== false ||
                stripos($e, 'mpv') !== false ||
                stripos($e, 'carens') !== false ||
                stripos($e, '7 seater') !== false ||
                stripos($e, 'scorpio') !== false ||
                stripos($e, 'bolero') !== false ||
                stripos($e, 'safari') !== false ||
                stripos($e, 'harrier') !== false ||
                stripos($e, 'marazzo') !== false ||
                $is_ertiga ||
                $is_crysta_innova
            );

            $is_tempo = (
                stripos($e, 'tempo') !== false ||
                stripos($e, 'traveller') !== false ||
                stripos($e, 'urabainia') !== false ||
                $e === '14'
            );

            if ($wants_sedan && $is_sedan) return true;
            if ($wants_hatchback && $is_hatchback) return true;
            if ($wants_crysta_innova) {
                if ($is_crysta_innova || ($is_suv && stripos($e, 'premium') !== false)) return true;
            } elseif ($wants_ertiga) {
                if ($is_ertiga || $is_suv) return true;
            } elseif ($wants_suv && $is_suv) {
                return true;
            }
            if ($wants_tempo && $is_tempo) return true;

            if ($e === $booking_type || (strlen($e) > 3 && stripos($booking_type, $e) !== false) || (strlen($booking_type) > 3 && stripos($e, $booking_type) !== false)) {
                return true;
            }
        }

        return false;
    }
}

function trigger_new_booking_notification($booking_id, $ref_lat = null, $ref_lon = null, $is_emergency_retry = false) {
    global $conn; // Access the database connection from the parent scope
    
    if (empty($booking_id)) {
        error_log("Notification Error: Empty booking ID");
        return;
    }

    // 0. Fetch dynamic alert settings from database
    $alert_enabled = 1;
    $ringtone_name = 'preview';
    $vibration_duration_sec = 15;
    $dialog_countdown_sec = 45;
    $title_template = 'New Trip Available - {trip_type}';
    $body_template = "From: {pickup_location}\nTo: {drop_location}\nEarnings: ₹{vendor_amount}";

    $settings_res = mysqli_query($conn, "SELECT * FROM driver_alert_settings WHERE id = 1");
    if ($settings_res && $settings_row = mysqli_fetch_assoc($settings_res)) {
        $alert_enabled = intval($settings_row['alert_enabled'] ?? 1);
        $ringtone_name = trim($settings_row['ringtone_name'] ?? 'preview');
        $vibration_duration_sec = intval($settings_row['vibration_duration_sec'] ?? 15);
        $dialog_countdown_sec = intval($settings_row['dialog_countdown_sec'] ?? 45);
        if (!empty($settings_row['notification_title_template'])) {
            $title_template = $settings_row['notification_title_template'];
        }
        if (!empty($settings_row['notification_body_template'])) {
            $body_template = $settings_row['notification_body_template'];
        }
    }

    if ($alert_enabled === 0) {
        error_log("Notification Info: Driver ride alerts disabled by Admin in driver_alert_settings. Notification skipped for booking #$booking_id.");
        return;
    }
    
    // 1. Fetch booking details (including date, time, distance, and return_date)
    $stmt = $conn->prepare("SELECT id, trip_type, car_type, from_address, to_address, distance, vendor_amount, total_amount, date, time, return_date FROM bookings WHERE id = ?");
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
    $car_type = $booking['car_type'] ?? '';
    $pickup_location = $booking['from_address'] ?? '';
    $drop_location = $booking['to_address'] ?? '';
    $distance = $booking['distance'] ?? '';
    $vendor_amount = $booking['vendor_amount'] ?? '0.00';

    // Look up kmRate for Round-Trip
    $km_rate = '';
    $isRoundTrip = (stripos($trip_type, 'round') !== false);
    if ($isRoundTrip && !empty($car_type)) {
        $tcStmt = $conn->prepare("SELECT kmRate FROM tripCostTable WHERE tripType = 'Round-Trip' AND carType = ? LIMIT 1");
        if ($tcStmt) {
            $tcStmt->bind_param("s", $car_type);
            $tcStmt->execute();
            $tcRes = $tcStmt->get_result();
            if ($tcRow = $tcRes->fetch_assoc()) {
                $km_rate = (string)$tcRow['kmRate'];
            }
            $tcStmt->close();
        }
    }

    // 🔥 Recalculate vendor_amount live for Local-Duty based on admin setting (Base fare minus dynamic commission)
    if ((stripos($trip_type, 'duty') !== false) && !empty($booking['total_amount'])) {
        $totAmt = floatval($booking['total_amount']);
        $baseAmt = floatval($booking['base_charge'] ?? 0);
        if ($baseAmt <= 0 && $totAmt > 0) {
            $baseAmt = round($totAmt / 1.05, 2);
        }
        
        $dShare = 5.00;
        $dActive = 1;
        $dType = 'percent';
        try {
            $gS = $conn->query("SELECT company_share_value, company_share_active, company_share_type FROM local_duty_global_settings WHERE id = 1 LIMIT 1");
            if ($gS && $gR = $gS->fetch_assoc()) {
                $dActive = (int)($gR['company_share_active'] ?? 1);
                $dShare = (float)($gR['company_share_value'] ?? 5.00);
                $dType = $gR['company_share_type'] ?? 'percent';
            }
        } catch (Throwable $e) {}
        
        if ($baseAmt > 0) {
            $commAmt = 0.00;
            if ($dActive) {
                if ($dType === 'flat') {
                    $commAmt = round($dShare, 2);
                } else {
                    $commAmt = round($baseAmt * ($dShare / 100.0), 2);
                }
            }
            $vendor_amount = (string)max(0.00, round($baseAmt - $commAmt, 2));
        }
    }

    $booking_date = trim($booking['date'] ?? '');
    $booking_time = trim($booking['time'] ?? '');
    $booking_return_date = trim($booking['return_date'] ?? '');


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
    
    // 2. Fetch active vendors/drivers with FCM tokens, coordinates, city, and vehicle types (own and fleet cars)
    $vendors_sql = "
        SELECT d.driver_id, d.phone_number, d.fcm_token, d.latitude, d.longitude, d.driver_city,
               d.vehicle_type,
               (
                   SELECT GROUP_CONCAT(DISTINCT c.vehicle_type SEPARATOR ',')
                   FROM cars c
                   LEFT JOIN driver_vendor_join_Table dv ON dv.vendor_id = c.owner_id
                   WHERE (c.owner_id = d.phone_number OR dv.driver_id = d.phone_number)
                     AND (c.status IN ('active', 'Notified') OR c.status = '')
               ) AS fleet_cars
        FROM drivers d
        WHERE d.status = 'active'
          AND (d.userType = 'vendor' OR d.userType = 'driver' OR d.userType = '' OR d.userType IS NULL)
          AND d.fcm_token IS NOT NULL AND d.fcm_token != ''
    ";
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

        // Filter by Vehicle Category: If booking specifies car_type (e.g. Sedan, Hatchback, SUV, Ertiga, Crysta),
        // only dispatch notifications to drivers who own/drive a matching vehicle.
        $driver_vehicle_str = trim(($row['vehicle_type'] ?? '') . ',' . ($row['fleet_cars'] ?? ''), ',');
        if (!empty($car_type)) {
            if (!is_driver_vehicle_match($car_type, $driver_vehicle_str)) {
                // Driver vehicle does not match booking car_type (e.g. Sedan driver skipping SUV/Hatchback trip)
                continue;
            }
        }

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
        $free_capacity = get_vendor_free_capacity($conn, $driver_phone, $booking_date, $booking_return_date);
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
    
    // 3. Prepare FCM message with dynamic templates and alert settings
    $is_advance_booking = (!empty($booking_date) && $booking_date > date('Y-m-d') && !$is_emergency_retry);

    if ($is_advance_booking) {
        // Calm Advance Booking notification (no urgent siren / 45s timer)
        $formatted_date = date('d M Y', strtotime($booking_date));
        $formatted_time = !empty($booking_time) ? date('h:i A', strtotime($booking_time)) : '';
        $date_display = !empty($formatted_time) ? "$formatted_date at $formatted_time" : $formatted_date;
        
        $titleText = "📅 Advance Booking: $trip_type";
        $bodyText = "Date: $date_display\nPickup: $pickup_location\nTo: " . (!empty($drop_location) ? $drop_location : 'As directed') . "\nEarnings: ₹" . number_format((float)$vendor_amount, 2) . "\nTap to view and claim in advance.";
        
        $channel_id = 'high_importance_channel';
        $sound_for_fcm = 'default';
        $vibrate_timings = ['0.0s', '0.4s', '0.2s', '0.4s'];
        $effective_countdown = 0;
    } else {
        // Today's urgent ride OR emergency scheduled retry!
        $formattedEarnings = ($isRoundTrip && !empty($km_rate)) 
            ? ("₹" . number_format((float)$km_rate, 0) . "/km") 
            : ("₹" . number_format((float)$vendor_amount, 2));

        $replacements = [
            '{trip_type}' => $trip_type,
            '{pickup_location}' => $pickup_location,
            '{drop_location}' => !empty($drop_location) ? $drop_location : 'As directed',
            '{vendor_amount}' => $formattedEarnings,
            '{booking_id}' => $booking_id_str
        ];
        $titleText = str_replace(array_keys($replacements), array_values($replacements), $title_template);
        if ($is_emergency_retry) {
            $titleText = "🚨 [URGENT TRIP] " . $titleText;
        }
        $bodyText = str_replace(array_keys($replacements), array_values($replacements), $body_template);
        $bodyText = str_replace(['\\r\\n', '\\n', '\\r'], "\n", $bodyText);

        // Build dynamic FCM vibration timings array for Android
        $vibrate_timings = ['0.0s'];
        $elapsed = 0.0;
        while ($elapsed < $vibration_duration_sec) {
            $vibrate_timings[] = '1.0s';
            $vibrate_timings[] = '0.5s';
            $elapsed += 1.5;
        }

        // Map to guaranteed registered notification channels in Android
        if ($ringtone_name === 'loud_alarm') {
            $channel_id = 'rentox_alert_loud_alarm';
        } elseif ($ringtone_name === 'uber_pulse') {
            $channel_id = 'rentox_alert_uber_pulse';
        } elseif ($ringtone_name === 'default') {
            $channel_id = 'high_importance_channel';
        } else {
            $channel_id = 'rentox_ride_alert_channel';
        }
        $sound_for_fcm = $ringtone_name;
        $effective_countdown = $dialog_countdown_sec;
    }

    $keyFileContent = json_decode(file_get_contents(__DIR__ . '/agni-car-app-firebase-adminsdk-fbsvc-4f70f7d1f2.json'), true);
    $projectId = $keyFileContent['project_id'] ?? 'agnicarrentaldriver-8fb07';

    $notificationData = [
        'title' => $titleText,
        'body' => $bodyText,
        'channel_id' => $channel_id,
        'sound' => $sound_for_fcm,
        'vibrate_timings' => $vibrate_timings,
        'data' => [
            'title' => $titleText,
            'body' => $bodyText,
            'booking_id' => $booking_id_str,
            'booking_type' => $trip_type,
            'car_type' => (string)$car_type,
            'pickup_location' => $pickup_location,
            'drop_location' => $drop_location,
            'distance' => (string)$distance,
            'vendor_amount' => (string)$vendor_amount,
            'km_rate' => (string)$km_rate,
            'countdown_seconds' => (string)$effective_countdown,
            'vibrate_seconds' => $is_advance_booking ? '1' : (string)$vibration_duration_sec,
            'ringtone_name' => $sound_for_fcm,
            'channel_id' => $channel_id,
            'notification_type' => 'new_booking',
            'is_advance_booking' => $is_advance_booking ? 'true' : 'false',
            'is_emergency_retry' => $is_emergency_retry ? 'true' : 'false',
            'booking_date' => $booking_date,
            'booking_time' => $booking_time
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
