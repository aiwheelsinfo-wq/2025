<?php
/**
 * Rentox Automated Scheduled Trip Dispatcher & Auto-Retry Engine
 * 
 * Runs via CLI / Cron every 1-2 minutes.
 * Promotes pending scheduled bookings for today to Urgent High-Priority Siren Alerts
 * as pickup time approaches (within advance_lead_time_min).
 * Retries sirens if no driver accepts within retry_interval_min (up to max_retries).
 */

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/send_new_booking_notification.php';

// Ensure script is run from CLI or internal call
$is_cli = (php_sapi_name() === 'cli' || empty($_SERVER['REMOTE_ADDR']));
if (!$is_cli && ($_GET['token'] ?? '') !== 'rentox_cron_secure_dispatch_2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

date_default_timezone_set('Asia/Kolkata');

$now_ts = time();
$current_date = date('Y-m-d');
$current_time = date('H:i:s');

echo "[" . date('Y-m-d H:i:s') . "] Starting Rentox Scheduled Trip Dispatcher...\n";

// 1. Fetch alert settings
$alert_enabled = 1;
$advance_lead_time_min = 45;
$auto_retry_enabled = 1;
$retry_interval_min = 2;
$max_retries = 3;

$settings_res = mysqli_query($conn, "SELECT * FROM driver_alert_settings WHERE id = 1");
if ($settings_res && $row = mysqli_fetch_assoc($settings_res)) {
    $alert_enabled = intval($row['alert_enabled'] ?? 1);
    $advance_lead_time_min = intval($row['advance_lead_time_min'] ?? 45);
    $auto_retry_enabled = intval($row['auto_retry_enabled'] ?? 1);
    $retry_interval_min = intval($row['retry_interval_min'] ?? 2);
    $max_retries = intval($row['max_retries'] ?? 3);
}

if ($alert_enabled === 0) {
    echo "Driver alerts globally disabled in driver_alert_settings. Exiting.\n";
    exit;
}

// 2. Mark accepted/cancelled trips in logs to stop tracking
$resolve_sql = "UPDATE booking_dispatch_logs l
                JOIN bookings b ON l.booking_id = b.id
                SET l.status = 'accepted'
                WHERE b.booking_status IN ('Accepted', 'Started', 'On-Duty', 'Cancelled', 'Completed')
                  AND l.status NOT IN ('accepted', 'cancelled')";
mysqli_query($conn, $resolve_sql);

// 3. Query all pending bookings for today
$pending_sql = "SELECT id, trip_type, from_address, to_address, vendor_amount, date, time
                FROM bookings
                WHERE booking_status = 'Pending' AND date = '$current_date'
                ORDER BY time ASC";
$pending_res = mysqli_query($conn, $pending_sql);

if (!$pending_res) {
    echo "DB Query Error: " . mysqli_error($conn) . "\n";
    exit;
}

$processed_count = 0;

while ($booking = mysqli_fetch_assoc($pending_res)) {
    $booking_id = intval($booking['id']);
    $b_date = trim($booking['date']);
    $b_time = trim($booking['time']);
    
    // Parse scheduled pickup time
    $pickup_ts = strtotime("$b_date $b_time");
    if (!$pickup_ts) {
        continue;
    }
    
    // Minutes remaining until pickup
    $diff_minutes = round(($pickup_ts - $now_ts) / 60);
    
    // Check if within the lead-time alert window:
    // e.g. between -60 min (1 hr past pickup) and +advance_lead_time_min (e.g. 45 min ahead)
    if ($diff_minutes > $advance_lead_time_min || $diff_minutes < -60) {
        continue; // Not yet time, or more than 1 hour expired
    }
    
    // Check dispatch log
    $log_stmt = $conn->prepare("SELECT id, dispatch_count, last_dispatched_at, status FROM booking_dispatch_logs WHERE booking_id = ?");
    $log_stmt->bind_param("i", $booking_id);
    $log_stmt->execute();
    $log_res = $log_stmt->get_result();
    $log_row = $log_res->fetch_assoc();
    $log_stmt->close();
    
    if (!$log_row) {
        // CASE A: First-time emergency promotion!
        echo "Promoting Booking #$booking_id to Urgent Siren Alert (Attempt 1). Pickup in $diff_minutes min.\n";
        
        $insert_stmt = $conn->prepare("INSERT INTO booking_dispatch_logs (booking_id, dispatch_count, last_dispatched_at, status) VALUES (?, 1, NOW(), 'dispatched')");
        if ($insert_stmt) {
            $insert_stmt->bind_param("i", $booking_id);
            $insert_stmt->execute();
            $insert_stmt->close();
        }
        
        trigger_new_booking_notification($booking_id, null, null, true);
        $processed_count++;
        
    } else {
        // CASE B: Already dispatched previously, still Pending
        $dispatch_count = intval($log_row['dispatch_count']);
        $last_dispatched_ts = strtotime($log_row['last_dispatched_at']);
        $elapsed_min = round(($now_ts - $last_dispatched_ts) / 60);
        $status = $log_row['status'];
        
        if ($status === 'accepted') {
            continue; // Already claimed by a driver
        }
        
        if ($auto_retry_enabled && $elapsed_min >= $retry_interval_min && $dispatch_count < $max_retries) {
            $new_count = $dispatch_count + 1;
            echo "Re-alerting Booking #$booking_id with Loud Siren (Attempt $new_count of $max_retries). Elapsed: $elapsed_min min.\n";
            
            $upd_stmt = $conn->prepare("UPDATE booking_dispatch_logs SET dispatch_count = ?, last_dispatched_at = NOW(), status = 'dispatched' WHERE booking_id = ?");
            if ($upd_stmt) {
                $upd_stmt->bind_param("ii", $new_count, $booking_id);
                $upd_stmt->execute();
                $upd_stmt->close();
            }
            
            trigger_new_booking_notification($booking_id, null, null, true);
            $processed_count++;
            
        } elseif ($dispatch_count >= $max_retries && $status !== 'max_retries_reached') {
            echo "Booking #$booking_id has reached maximum retry count ($max_retries). Siren halted.\n";
            $stop_stmt = $conn->prepare("UPDATE booking_dispatch_logs SET status = 'max_retries_reached' WHERE booking_id = ?");
            if ($stop_stmt) {
                $stop_stmt->bind_param("i", $booking_id);
                $stop_stmt->execute();
                $stop_stmt->close();
            }
        }
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Dispatcher run complete. Processed $processed_count urgent/retry booking(s).\n";
