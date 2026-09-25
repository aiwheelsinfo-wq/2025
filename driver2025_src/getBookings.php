<?php
header("Content-Type: application/json");
require_once __DIR__ . '/db_connect.php'; // Database connection

$vendor_phone = $_POST['phone_number'] ?? null;
$driver_phone = $_POST['phone_number'] ?? null;
$currentDate = date('Y-m-d');

// Fetch driver's current coordinates for radius filtering
try {
    if (!$conn) {
        throw new Exception("Database connection failed.");
    }

    // Check if requesting partner/driver is blocked (check both drivers and vendors tables)
    if (!empty($vendor_phone)) {
        $chkStmt = $conn->prepare("SELECT status, block_reason FROM drivers WHERE phone_number = ? LIMIT 1");
        if ($chkStmt) {
            $chkStmt->bind_param("s", $vendor_phone);
            $chkStmt->execute();
            $chkStmt->bind_result($vStatus, $vReason);
            if ($chkStmt->fetch()) {
                if (strtolower(trim($vStatus ?? '')) === 'blocked') {
                    $chkStmt->close();
                    echo json_encode([
                        "status" => "blocked",
                        "is_blocked" => true,
                        "block_reason" => !empty($vReason) ? $vReason : "Administrative restriction",
                        "message" => "Your partner account has been suspended/blocked. Reason: " . (!empty($vReason) ? $vReason : "Administrative restriction") . ". Please contact support.",
                        "bookings" => [],
                        "acceptedBookings" => []
                    ]);
                    exit;
                }
            }
            $chkStmt->close();
        }

        $vChkStmt = $conn->prepare("SELECT status, block_reason FROM vendors WHERE phone_number = ? LIMIT 1");
        if ($vChkStmt) {
            $vChkStmt->bind_param("s", $vendor_phone);
            $vChkStmt->execute();
            $vChkStmt->bind_result($vndStatus, $vndReason);
            if ($vChkStmt->fetch()) {
                if (strtolower(trim($vndStatus ?? '')) === 'blocked') {
                    $vChkStmt->close();
                    echo json_encode([
                        "status" => "blocked",
                        "is_blocked" => true,
                        "block_reason" => !empty($vndReason) ? $vndReason : "Administrative restriction",
                        "message" => "Your partner account has been suspended/blocked. Reason: " . (!empty($vndReason) ? $vndReason : "Administrative restriction") . ". Please contact support.",
                        "bookings" => [],
                        "acceptedBookings" => []
                    ]);
                    exit;
                }
            }
            $vChkStmt->close();
        }
    }

    $driver_lat = null;
    $driver_lon = null;
    $driver_city = '';
    if (!empty($driver_phone)) {
        $drvStmt = $conn->prepare("SELECT latitude, longitude, driver_city FROM drivers WHERE phone_number = ? LIMIT 1");
        if ($drvStmt) {
            $drvStmt->bind_param("s", $driver_phone);
            $drvStmt->execute();
            $drvStmt->bind_result($dLat, $dLon, $dCity);
            if ($drvStmt->fetch()) {
                $driver_lat = !empty($dLat) ? floatval($dLat) : null;
                $driver_lon = !empty($dLon) ? floatval($dLon) : null;
                $driver_city = trim($dCity ?? '');
            }
            $drvStmt->close();
        }

        // Fetch driver's direct registered vehicle type and any fleet cars (for vendors or linked drivers)
        $driver_vehicle_str = '';
        $vTypeStmt = $conn->prepare("
            SELECT d.vehicle_type,
                   (
                       SELECT GROUP_CONCAT(DISTINCT c.vehicle_type SEPARATOR ',')
                       FROM cars c
                       LEFT JOIN driver_vendor_join_Table dv ON dv.vendor_id = c.owner_id
                       WHERE (c.owner_id = d.phone_number OR dv.driver_id = d.phone_number)
                         AND (c.status IN ('active', 'Notified') OR c.status = '')
                   ) AS fleet_cars
            FROM drivers d
            WHERE d.phone_number = ?
            LIMIT 1
        ");
        if ($vTypeStmt) {
            $vTypeStmt->bind_param("s", $driver_phone);
            $vTypeStmt->execute();
            $vTypeStmt->bind_result($dVType, $fCars);
            if ($vTypeStmt->fetch()) {
                $driver_vehicle_str = trim(($dVType ?? '') . ',' . ($fCars ?? ''), ',');
            }
            $vTypeStmt->close();
        }
    }

    // ===================== Fetch Accepted Bookings =====================
    // We remove the date filter for accepted bookings so they don't disappear in case they started in the past or timezone difference
    $sqlAccepted = "
        SELECT 
            b.id, 
            b.car_type, 
            b.from_address, 
            b.to_address, 
            b.distance, 
            b.date, 
            b.time, 
            b.return_date,
            b.return_time,
            CASE 
                WHEN b.trip_type = 'Local-taxi' THEN (b.total_amount - COALESCE(b.agent_commission, 0) - 100)
                ELSE (b.total_amount - COALESCE(b.agent_commission, 0))
            END AS total_amount,
            b.mobile, 
            b.driver_id,
            b.vehicle_id,
            b.booking_status,
            b.otp,
            b.trip_type,
            d.full_name AS driver_name,
            v.full_name AS vendor_name,
            d.latitude AS driver_lat, 
            d.longitude AS driver_lon,
            u.name AS customer_name,
            b.vendor_amount,
            d.phone_number AS driver_phone,
            b.starting_km,
            b.paid_amount,
            b.payment_type
        FROM 
            bookings AS b
        LEFT JOIN 
            drivers AS d ON b.driver_id = d.phone_number
        LEFT JOIN 
            drivers AS v ON b.vender_id = v.phone_number
        LEFT JOIN
            users AS u ON b.mobile = u.phone_number
        WHERE 
            (b.vender_id = ? OR b.driver_id = ?) 
            AND (b.booking_status = 'Accepted' OR b.booking_status = 'Started' OR b.booking_status = 'In-Transit')
        ORDER BY 
            b.date ASC
    ";

    $stmtAccepted = $conn->prepare($sqlAccepted);
    if (!$stmtAccepted) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $stmtAccepted->bind_param("ss", $vendor_phone, $driver_phone);
    $stmtAccepted->execute();

    $stmtAccepted->bind_result(
        $booking_id, $car_type, $from_address, $to_address, $distance, $date, $time,
        $return_date, $return_time, $total_amount, $customer_contact, $driver_id,
        $vehicle_id, $booking_status, $otp, $trip_type, $driver_name, $vendor_name,
        $driver_lat, $driver_lon, $customer_name, $vendor_amount, $driver_phone, $starting_km,
        $paid_amount, $payment_type
    );

    $acceptedBookings = [];
    while ($stmtAccepted->fetch()) {
        $acceptedBookings[] = [
            "booking_id" => $booking_id,
            "car_type" => $car_type,
            "pickup_location" => $from_address,
            "drop_location" => $to_address,
            "distance" => $distance,
            "date" => $date,
            "time" => $time,
            "return_date" => $return_date,
            "return_time" => $return_time,
            "total_amount" => round((float)$total_amount, 2),
            "customer_contact" => $customer_contact,
            "driver_id" => $driver_id,
            "vehicle_id" => $vehicle_id,
            "booking_status" => $booking_status,
            "otp" => $otp,
            "trip_type" => $trip_type,
            "driver_name" => $driver_name,
            "vendor_name" => $vendor_name,
            "driver_lat" => $driver_lat,
            "driver_lon" => $driver_lon,
            "customer_name" => $customer_name,
            "vendor_amount" => $vendor_amount,
            "driver_phone" => $driver_phone,
            "starting_km" => $starting_km,
            "paid_amount" => $paid_amount,
            "payment_type" => $payment_type
        ];
    }
    $stmtAccepted->close();

    // Fetch Local Duty global settings for dynamic commission and wallet balance threshold
    $ldCommission = 10.00;
    $ldActive = false;
    $ldType = 'percent';
    $ldMinWallet = 0.00;
    $ldQ = $conn->query("SELECT company_share_active, company_share_type, company_share_value, min_wallet_balance FROM local_duty_global_settings WHERE id = 1 LIMIT 1");
    if ($ldQ && $ldR = $ldQ->fetch_assoc()) {
        $ldActive = ((int)$ldR['company_share_active'] === 1);
        $ldType = $ldR['company_share_type'] ?? 'percent';
        $ldCommission = (float)($ldR['company_share_value'] ?? 10.00);
        $ldMinWallet = (float)($ldR['min_wallet_balance'] ?? 0.00);
    }

    // ===================== Fetch Pending Bookings =====================
    $sqlPending = "
        SELECT 
            b.id, 
            b.car_type, 
            b.from_address, 
            b.to_address, 
            b.distance, 
            b.date, 
            b.time, 
            CASE 
                WHEN b.trip_type = 'Local-taxi' THEN (b.total_amount - COALESCE(b.agent_commission, 0) - 100)
                ELSE (b.total_amount - COALESCE(b.agent_commission, 0))
            END AS total_amount,
            b.mobile, 
            b.trip_type, 
            t.kmRate, 
            t.packageKm,
            t.packageHours,
            b.return_date, 
            b.return_time,
            t.baseAmount,
            t.extraKMAmount,
            t.extraHoursAmount,
            b.agent_commission,
            t.driverRate,
            t.extraKMAmountFroDriver,
            t.extraHoursAmountForDriver,
            t.driver_allowance,
            b.vendor_amount,
            t.agni_share,
            d.full_name AS driver_name,
            v.full_name AS vendor_name,
            b.paid_amount,
            b.payment_type
        FROM 
            bookings b 
        LEFT JOIN 
            tripCostTable t 
            ON b.trip_type = t.tripType 
            AND b.car_type = t.carType 
            AND b.trip_type != 'One-way'
        LEFT JOIN 
            drivers AS d ON b.driver_id = d.phone_number
        LEFT JOIN 
            drivers AS v ON b.vender_id = v.phone_number
        WHERE 
            b.booking_status = 'Pending' 
            AND b.date >= ?
        ORDER BY 
            b.date ASC
    ";

    $stmtPending = $conn->prepare($sqlPending);
    $stmtPending->bind_param("s", $currentDate);
    $stmtPending->execute();
    $stmtPending->bind_result(
        $booking_id, $car_type, $from_address, $to_address, $distance, $date, $time, 
        $total_amount, $customer_contact, $trip_type, $kmRate, $packageKm, $packageHours, 
        $return_date, $return_time, $baseAmount, $extraKMAmount, $extraHoursAmount, 
        $agent_commission, $driverRate, $extraKMAmountFroDriver, $extraHoursAmountForDriver, 
        $driver_allowance, $vendor_amount, $agni_share, $driver_name, $vendor_name,
        $paid_amount, $payment_type
    );

    $bookings = [];
    $geocode_cache = [];
    $googleMapsApiKey = 'AIzaSyC41U3p08LqY8G15ruxDCEfTvBLkG_OrsM';
    
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

    if (!function_exists('is_driver_vehicle_match')) {
        /**
         * Checks if a driver's vehicle(s) match the requested booking car_type.
         * Handles category variations, sub-models, and multiple fleet vehicles.
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

    while ($stmtPending->fetch()) {
        // Dynamically compute vendor_amount for Local-Duty based on live admin commission on baseAmount (excluding 5% GST)
        if (stripos($trip_type, 'duty') !== false && $ldActive) {
            $tAmount = (float)$total_amount;
            $bAmount = (float)($baseAmount ?? 0);
            if ($bAmount <= 0 && $tAmount > 0) {
                $bAmount = round($tAmount / 1.05, 2);
            }
            if ($ldType === 'flat') {
                $vendor_amount = max(0, round($bAmount - $ldCommission, 2));
            } else {
                $vendor_amount = max(0, round($bAmount - ($bAmount * ($ldCommission / 100)), 2));
            }
        }

        $radius_km = 20;
        if (stripos($trip_type, 'Local-taxi') !== false) {
            $radius_km = 5;
        } elseif (stripos($trip_type, 'Local-duty') !== false) {
            $radius_km = 10;
        } elseif (stripos($trip_type, 'Local') !== false || stripos($trip_type, 'taxi') !== false) {
            $radius_km = 5;
        }

        // Apply distance radius filter for BOTH Today and Advance bookings when driver GPS or city is available
        $has_driver_gps = ($driver_lat !== null && $driver_lon !== null && $driver_lat != 0.0 && $driver_lon != 0.0);
        if ($has_driver_gps) {
            // Geocode the booking's pickup address (cached in memory to avoid duplicate API calls)
            $pickup_lat = null;
            $pickup_lng = null;
            if (isset($geocode_cache[$from_address])) {
                $pickup_lat = $geocode_cache[$from_address]['lat'];
                $pickup_lng = $geocode_cache[$from_address]['lng'];
            } else {
                $geocodeUrl = "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($from_address) . "&key=$googleMapsApiKey";
                try {
                    $geoResponse = @file_get_contents($geocodeUrl);
                    if ($geoResponse) {
                        $geoData = json_decode($geoResponse, true);
                        if ($geoData['status'] === 'OK') {
                            $pickup_lat = $geoData['results'][0]['geometry']['location']['lat'];
                            $pickup_lng = $geoData['results'][0]['geometry']['location']['lng'];
                            $geocode_cache[$from_address] = ['lat' => $pickup_lat, 'lng' => $pickup_lng];
                        }
                    }
                } catch (Throwable $e) {
                    error_log("Geocoding failed in getBookings: " . $e->getMessage());
                }
            }

            if ($pickup_lat !== null && $pickup_lng !== null) {
                $dist = getDistance($pickup_lat, $pickup_lng, $driver_lat, $driver_lon);
                if ($dist > $radius_km) {
                    continue; // Skip this booking because it's outside the driver's allowable radius
                }
            } elseif (!empty($driver_city)) {
                // If geocoding failed, fallback to verifying driver's city in pickup address
                if (stripos($from_address, $driver_city) === false) {
                    continue; // Cannot verify driver is in the pickup zone
                }
            }
        } elseif (!empty($driver_city)) {
            // Driver GPS is not recorded; verify driver's registered city is mentioned in pickup address
            if (stripos($from_address, $driver_city) === false) {
                continue; // Cannot verify driver is in the pickup zone
            }
        }

        // Filter by Vehicle Category: If booking specifies car_type (e.g. Sedan, Hatchback, SUV, Ertiga, Crysta),
        // only show this trip if the requesting driver possesses a matching vehicle.
        if (!empty($car_type) && !empty($driver_vehicle_str)) {
            if (!is_driver_vehicle_match($car_type, $driver_vehicle_str)) {
                continue; // Skip trip not matching driver's vehicle type
            }
        }

        $bookings[] = [
            "booking_id" => $booking_id,
            "car_type" => $car_type,
            "pickup_location" => $from_address,
            "drop_location" => $to_address,
            "distance" => $distance,
            "date" => $date,
            "time" => $time,
            "total_amount" => round((float)$total_amount, 2),
            "customer_contact" => $customer_contact,
            "trip_type" => $trip_type,
            "kmRate" => $kmRate,
            "packageKm" => $packageKm,
            "packageHours" => $packageHours,
            "return_date" => $return_date,
            "return_time" => $return_time,
            "baseAmount" => $baseAmount,
            "extraKMAmount" => $extraKMAmount,
            "extraHoursAmount" => $extraHoursAmount,
            "agent_commission" => $agent_commission,
            "driverRate" => $driverRate,
            "extraKMAmountFroDriver" => $extraKMAmountFroDriver,
            "extraHoursAmountForDriver" => $extraHoursAmountForDriver,
            "driver_allowance" => $driver_allowance,
            "vendor_amount" => $vendor_amount,
            "agni_share" => $agni_share,
            "driver_name" => $driver_name,
            "vendor_name" => $vendor_name,
            "paid_amount" => $paid_amount,
            "payment_type" => $payment_type
        ];
    }
    $stmtPending->close();

    // ===================== Final Response =====================
    $vPhone = !empty($vendor_phone) ? $vendor_phone : $driver_phone;
    $wallet_balance = 0.00;
    if (!empty($vPhone)) {
        $wStmt = $conn->prepare("SELECT wallet_balance FROM drivers WHERE phone_number = ? LIMIT 1");
        if ($wStmt) {
            $wStmt->bind_param("s", $vPhone);
            $wStmt->execute();
            $wStmt->bind_result($wb);
            if ($wStmt->fetch()) {
                $wallet_balance = (float)$wb;
            }
            $wStmt->close();
        }
    }
    $min_wallet_balance = 0.00;
    $mwQ = $conn->query("SELECT min_wallet_balance FROM local_taxi_global_settings WHERE id = 1 LIMIT 1");
    if ($mwQ && $mwR = $mwQ->fetch_assoc()) {
        $min_wallet_balance = (float)($mwR['min_wallet_balance'] ?? 0.00);
    }

    $rtMinWallet = 1000.00;
    $rtQ = $conn->query("SELECT min_wallet_balance FROM round_trip_global_settings WHERE id = 1 LIMIT 1");
    if ($rtQ && $rtR = $rtQ->fetch_assoc()) {
        $rtMinWallet = (float)($rtR['min_wallet_balance'] ?? 1000.00);
    }

    $response = [
        "success" => true,
        "wallet_balance" => $wallet_balance,
        "driver_vehicle_type" => $driver_vehicle_str,
        "min_wallet_balance" => $min_wallet_balance,
        "min_wallet_balance_local_duty" => $ldMinWallet,
        "min_wallet_balance_round_trip" => $rtMinWallet,
        "is_eligible_for_local_taxi" => ($wallet_balance > $min_wallet_balance),
        "is_eligible_for_local_duty" => ($wallet_balance > $ldMinWallet),
        "is_eligible_for_round_trip" => ($wallet_balance > $rtMinWallet),
        "acceptedBookings" => $acceptedBookings,
        "bookings" => $bookings
    ];

} catch (Exception $e) {
    $response = [
        "success" => false,
        "message" => "Error: " . $e->getMessage()
    ];
}

if ($conn) {
    $conn->close();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
