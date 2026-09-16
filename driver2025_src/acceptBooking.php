<?php
header('Content-Type: application/json');
include '../2025/db_connect.php'; 

$booking_id = $_POST['booking_id'] ?? '';
$vendor_id = $_POST['vendor_id'] ?? $_POST['driver_id'] ?? ''; // vendor's phone number
$driver_id = $_POST['driver_id'] ?? ''; // assigned driver's phone number
$vehicle_id = $_POST['vehicle_id'] ?? ''; // assigned vehicle number

// Log received parameters
error_log("Received Booking ID: " . $booking_id);
error_log("Received Vendor ID: " . $vendor_id);
error_log("Received Driver ID: " . $driver_id);
error_log("Received Vehicle ID: " . $vehicle_id);

if (empty($booking_id) || empty($vendor_id)) {
    echo json_encode(["success" => false, "message" => "Missing required parameters"]);
    exit;
}

// If no specific driver/vehicle is assigned, fallback to vendor_id
if (empty($driver_id)) {
    $driver_id = $vendor_id;
}

// Check if vendor or assigned driver is blocked in drivers or vendors table
$chk_phones = array_unique(array_filter([$vendor_id, $driver_id]));
foreach ($chk_phones as $phone_to_check) {
    error_log("Checking block status for phone: $phone_to_check");
    // 1. Check drivers table
    $blk_stmt = $conn->prepare("SELECT status, block_reason FROM drivers WHERE phone_number = ? LIMIT 1");
    if ($blk_stmt) {
        $blk_stmt->bind_param("s", $phone_to_check);
        $blk_stmt->execute();
        $blk_stmt->bind_result($bStatus, $bReason);
        if ($blk_stmt->fetch()) {
            error_log("Found in drivers: status=$bStatus, reason=$bReason");
            if (strtolower(trim($bStatus ?? '')) === 'blocked') {
                $blk_stmt->close();
                $reason = !empty($bReason) ? $bReason : "Administrative restriction";
                echo json_encode([
                    "success" => false,
                    "status" => "blocked",
                    "message" => "Account Blocked: You cannot accept bookings. Reason: $reason. Please contact support."
                ]);
                exit;
            }
        } else {
            error_log("Not found in drivers: $phone_to_check");
        }
        $blk_stmt->close();
    }

    // 2. Check vendors table
    $vblk_stmt = $conn->prepare("SELECT status, block_reason FROM vendors WHERE phone_number = ? LIMIT 1");
    if ($vblk_stmt) {
        $vblk_stmt->bind_param("s", $phone_to_check);
        $vblk_stmt->execute();
        $vblk_stmt->bind_result($vbStatus, $vbReason);
        if ($vblk_stmt->fetch()) {
            if (strtolower(trim($vbStatus ?? '')) === 'blocked') {
                $vblk_stmt->close();
                $reason = !empty($vbReason) ? $vbReason : "Administrative restriction";
                echo json_encode([
                    "success" => false,
                    "status" => "blocked",
                    "message" => "Account Blocked: You cannot accept bookings. Reason: $reason. Please contact support."
                ]);
                exit;
            }
        }
        $vblk_stmt->close();
    }
}

// Start transaction
$conn->begin_transaction();

try {
    // Select booking with FOR UPDATE to lock the row
    $stmt = $conn->prepare("SELECT booking_status, date, trip_type, from_address FROM bookings WHERE id = ? FOR UPDATE");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $conn->rollback();
        echo json_encode(["success" => false, "message" => "Booking not found."]);
        exit;
    }
    
    $booking = $result->fetch_assoc();
    $status = $booking['booking_status'];
    $selectedBookingDate = $booking['date'];
    $trip_type = $booking['trip_type'] ?? '';
    $from_address = $booking['from_address'] ?? '';
    
    if ($status !== 'Pending') {
        $conn->rollback();
        echo json_encode(["success" => false, "message" => "This booking has already been accepted by another vendor."]);
        exit;
    }

    // 🔒 Wallet Balance Validation for Local Taxi, Local-Duty, and One-Way rides
    $isLocalDuty = (stripos($trip_type, 'duty') !== false);
    $isLocalTaxi = !$isLocalDuty && (stripos($trip_type, 'Local') !== false || stripos($trip_type, 'taxi') !== false);
    $isOneWay = (stripos($trip_type, 'One-way') !== false || stripos($trip_type, 'one way') !== false || stripos($trip_type, 'oneway') !== false);

    if ($isLocalDuty || $isLocalTaxi || $isOneWay) {
        $minWalletBalance = 0.00;
        try {
            if ($isOneWay) {
                $setStmt = $conn->query("SELECT min_wallet_balance FROM one_way_global_settings WHERE id = 1 LIMIT 1");
                if ($setStmt && $sRow = $setStmt->fetch_assoc() && (float)($sRow['min_wallet_balance'] ?? 0) > 0) {
                    $minWalletBalance = (float)$sRow['min_wallet_balance'];
                } else {
                    $fbStmt = $conn->query("SELECT min_wallet_balance FROM local_taxi_global_settings WHERE id = 1 LIMIT 1");
                    if ($fbStmt && $fbRow = $fbStmt->fetch_assoc()) {
                        $minWalletBalance = (float)($fbRow['min_wallet_balance'] ?? 0.00);
                    }
                }
            } else if ($isLocalDuty) {
                $setStmt = $conn->query("SELECT min_wallet_balance FROM local_duty_global_settings WHERE id = 1 LIMIT 1");
                if ($setStmt && $sRow = $setStmt->fetch_assoc() && (float)($sRow['min_wallet_balance'] ?? 0) > 0) {
                    $minWalletBalance = (float)$sRow['min_wallet_balance'];
                } else {
                    $fbStmt = $conn->query("SELECT min_wallet_balance FROM local_taxi_global_settings WHERE id = 1 LIMIT 1");
                    if ($fbStmt && $fbRow = $fbStmt->fetch_assoc()) {
                        $minWalletBalance = (float)($fbRow['min_wallet_balance'] ?? 0.00);
                    }
                }
            } else {
                $setStmt = $conn->query("SELECT min_wallet_balance FROM local_taxi_global_settings WHERE id = 1 LIMIT 1");
                if ($setStmt && $sRow = $setStmt->fetch_assoc()) {
                    $minWalletBalance = (float)($sRow['min_wallet_balance'] ?? 0.00);
                }
            }
        } catch (Throwable $e) {
            $minWalletBalance = 0.00;
        }

        // Check wallet balance of vendor/driver
        $vendorWalletBal = 0.00;
        $wStmt = $conn->prepare("SELECT wallet_balance FROM drivers WHERE phone_number = ? LIMIT 1");
        if ($wStmt) {
            $wStmt->bind_param("s", $vendor_id);
            $wStmt->execute();
            $wStmt->bind_result($wbal);
            if ($wStmt->fetch()) {
                $vendorWalletBal = (float)$wbal;
            }
            $wStmt->close();
        }

        if ($vendorWalletBal <= 0) {
            $vwStmt = $conn->prepare("SELECT wallet_balance FROM vendors WHERE phone_number = ? LIMIT 1");
            if ($vwStmt) {
                $vwStmt->bind_param("s", $vendor_id);
                $vwStmt->execute();
                $vwStmt->bind_result($vwbal);
                if ($vwStmt->fetch() && (float)$vwbal > 0) {
                    $vendorWalletBal = (float)$vwbal;
                }
                $vwStmt->close();
            }
        }

        if ($vendorWalletBal <= $minWalletBalance) {
            $conn->rollback();
            $tripLabel = $isLocalDuty ? "Local-Duty" : ($isLocalTaxi ? "Local Taxi" : "One-Way");
            echo json_encode([
                "success" => false,
                "status" => "low_wallet_balance",
                "wallet_balance" => $vendorWalletBal,
                "min_required" => $minWalletBalance,
                "message" => "Insufficient wallet balance (₹" . number_format($vendorWalletBal, 2) . "). Minimum ₹" . number_format($minWalletBalance, 2) . " required to accept $tripLabel trips. Please recharge your wallet."
            ]);
            exit;
        }
    }

    // Check for 5 km radius limit on Local-taxi bookings
    if ($isLocalTaxi) {

        // 1. Get driver's location
        $driver_lat = null;
        $driver_lng = null;
        $driver_stmt = $conn->prepare("SELECT latitude, longitude FROM drivers WHERE phone_number = ? LIMIT 1");
        if ($driver_stmt) {
            $driver_stmt->bind_param("s", $driver_id);
            $driver_stmt->execute();
            $driver_res = $driver_stmt->get_result();
            if ($driver_res->num_rows > 0) {
                $driver_row = $driver_res->fetch_assoc();
                $driver_lat = !empty($driver_row['latitude']) ? floatval($driver_row['latitude']) : null;
                $driver_lng = !empty($driver_row['longitude']) ? floatval($driver_row['longitude']) : null;
            }
            $driver_stmt->close();
        }

        if ($driver_lat === null || $driver_lng === null || $driver_lat == 0 || $driver_lng == 0) {
            $conn->rollback();
            echo json_encode(["success" => false, "message" => "Could not verify your location. Please ensure your GPS/location tracking is active in the app."]);
            exit;
        }

        // 2. Geocode the booking's pickup address
        $pickup_lat = null;
        $pickup_lng = null;
        $googleMapsApiKey = 'AIzaSyC41U3p08LqY8G15ruxDCEfTvBLkG_OrsM';
        $geocodeUrl = "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($from_address) . "&key=$googleMapsApiKey";
        try {
            $geoResponse = file_get_contents($geocodeUrl);
            if ($geoResponse) {
                $geoData = json_decode($geoResponse, true);
                if ($geoData['status'] === 'OK') {
                    $pickup_lat = $geoData['results'][0]['geometry']['location']['lat'];
                    $pickup_lng = $geoData['results'][0]['geometry']['location']['lng'];
                }
            }
        } catch (Throwable $e) {
            error_log("Geocoding failed during booking accept: " . $e->getMessage());
        }

        if ($pickup_lat !== null && $pickup_lng !== null) {
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
            $allowed_radius = 5.0;
            if (stripos($trip_type, 'Local-duty') !== false) {
                $allowed_radius = 10.0;
            } elseif (stripos($trip_type, 'Local-taxi') !== false) {
                $allowed_radius = 5.0;
            }
            $distance = getDistance($pickup_lat, $pickup_lng, $driver_lat, $driver_lng);
            if ($distance > $allowed_radius) {
                $conn->rollback();
                echo json_encode(["success" => false, "message" => "You are too far from the pickup location (" . round($distance, 2) . " km). You must be within " . $allowed_radius . " km to accept this booking."]);
                exit;
            }
        }
    }

    // Check for driver or vehicle conflict within past 2 and next 2 days relative to selected booking date
    if (!empty($driver_id) || !empty($vehicle_id)) {
        $sqlConflict = "
            SELECT id, driver_id, vehicle_id, date, booking_status
            FROM bookings
            WHERE date BETWEEN DATE_SUB(?, INTERVAL 2 DAY) AND DATE_ADD(?, INTERVAL 2 DAY)
            AND id != ?
            AND booking_status != 'Completed'
            AND booking_status != 'Cancelled'
            AND booking_status != 'Customer Cancelled'
            AND (
                (? != '' AND driver_id = ?) 
                OR (? != '' AND vehicle_id = ?)
            )
        ";
        
        $stmtConflict = $conn->prepare($sqlConflict);
        $stmtConflict->bind_param("ssissss", 
            $selectedBookingDate, 
            $selectedBookingDate, 
            $booking_id, 
            $driver_id, 
            $driver_id, 
            $vehicle_id, 
            $vehicle_id
        );
        $stmtConflict->execute();
        $conflictResult = $stmtConflict->get_result();
        $conflicts = $conflictResult->fetch_all(MYSQLI_ASSOC);
        $stmtConflict->close();
        
        if (!empty($conflicts)) {
            $driverConflict = false;
            $vehicleConflict = false;
            
            foreach ($conflicts as $conflict) {
                if (!empty($driver_id) && $conflict['driver_id'] == $driver_id) {
                    $driverConflict = true;
                }
                if (!empty($vehicle_id) && $conflict['vehicle_id'] == $vehicle_id) {
                    $vehicleConflict = true;
                }
            }
            
            $messages = [];
            if ($driverConflict) $messages[] = "Selected driver is already booked for another trip within 2 days of this date.";
            if ($vehicleConflict) $messages[] = "Selected vehicle is already booked for another trip within 2 days of this date.";
            
            $conn->rollback();
            echo json_encode(["success" => false, "message" => implode(" ", $messages)]);
            exit;
        }
    }
    
    // Update booking status, driver_id, vender_id, and vehicle_id
    $updateStmt = $conn->prepare("UPDATE bookings SET driver_id = ?, vender_id = ?, vehicle_id = ?, booking_status = 'Accepted' WHERE id = ?");
    $updateStmt->bind_param("sssi", $driver_id, $vendor_id, $vehicle_id, $booking_id);
    
    if ($updateStmt->execute()) {
        $conn->commit();
        
        // Send WhatsApp confirmation notification to customer
        try {
            if (file_exists(__DIR__ . '/../notification_helper.php')) {
                require_once __DIR__ . '/../notification_helper.php';
            } else {
                require_once __DIR__ . '/../2025/notification_helper.php';
            }
            sendAcceptWhatsAppNotification($booking_id, $conn);
        } catch (Throwable $e) {
            error_log("WhatsApp Accept Notification error: " . $e->getMessage());
        }

        echo json_encode(["success" => true, "message" => "Booking accepted successfully"]);
    } else {
        $conn->rollback();
        echo json_encode(["success" => false, "message" => "Failed to accept booking"]);
    }
    
    $updateStmt->close();
    $stmt->close();
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}

$conn->close();
?>
