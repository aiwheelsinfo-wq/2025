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

    // Check for 5 km radius limit on Local-taxi bookings
    if (stripos($trip_type, 'Local') !== false || stripos($trip_type, 'taxi') !== false) {
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
