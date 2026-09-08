<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db_connect.php';

$available_ringtones = [
    [
        'id' => 'preview',
        'name' => 'Rentox Signature (Default)',
        'description' => 'Crisp energetic alert chime with prominent pulse',
        'is_system' => false
    ],
    [
        'id' => 'loud_alarm',
        'name' => 'High Alert Siren',
        'description' => 'Loud repeating alarm tone for high urgency orders',
        'is_system' => false
    ],
    [
        'id' => 'uber_pulse',
        'name' => 'Radar Pulse Tone',
        'description' => 'Continuous sonar radar pulse sound',
        'is_system' => false
    ],
    [
        'id' => 'default',
        'name' => 'System Default Notification',
        'description' => 'Standard Android device notification sound',
        'is_system' => true
    ]
];

$available_tags = [
    '{trip_type}' => 'Booking category (e.g. One-Way, Local Taxi)',
    '{pickup_location}' => 'Customer pickup address or city',
    '{drop_location}' => 'Drop destination address (if applicable)',
    '{vendor_amount}' => 'Earnings payable to driver / vendor (e.g. 1450.00)',
    '{booking_id}' => 'Unique numeric booking reference ID'
];

if ($method === 'GET') {
    $stmt = $conn->prepare("SELECT * FROM driver_alert_settings WHERE id = 1");
    if (!$stmt) {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $conn->error
        ]);
        exit;
    }
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res->num_rows === 0) {
        // Insert default row if not present
        $default_body = "From: {pickup_location}\nTo: {drop_location}\nEarnings: ₹{vendor_amount}";
        $insert = $conn->prepare("INSERT INTO driver_alert_settings (id, alert_enabled, ringtone_name, vibration_duration_sec, dialog_countdown_sec, notification_title_template, notification_body_template) VALUES (1, 1, 'preview', 15, 45, 'New Trip Available - {trip_type}', ?)");
        $insert->bind_param("s", $default_body);
        $insert->execute();
        $insert->close();
        
        $stmt->execute();
        $res = $stmt->get_result();
    }
    
    $settings = $res->fetch_assoc();
    $stmt->close();
    
    // Format types
    $settings['id'] = (int)$settings['id'];
    $settings['alert_enabled'] = (int)$settings['alert_enabled'];
    $settings['vibration_duration_sec'] = (int)$settings['vibration_duration_sec'];
    $settings['dialog_countdown_sec'] = (int)$settings['dialog_countdown_sec'];
    $settings['advance_lead_time_min'] = isset($settings['advance_lead_time_min']) ? (int)$settings['advance_lead_time_min'] : 45;
    $settings['auto_retry_enabled'] = isset($settings['auto_retry_enabled']) ? (int)$settings['auto_retry_enabled'] : 1;
    $settings['retry_interval_min'] = isset($settings['retry_interval_min']) ? (int)$settings['retry_interval_min'] : 2;
    $settings['max_retries'] = isset($settings['max_retries']) ? (int)$settings['max_retries'] : 3;
    
    echo json_encode([
        'success' => true,
        'data' => $settings,
        'available_ringtones' => $available_ringtones,
        'available_tags' => $available_tags
    ]);
    exit;
}

if ($method === 'POST') {
    // Read input either from JSON body or POST form data
    $rawInput = file_get_contents('php://input');
    $data = [];
    if (!empty($rawInput)) {
        $data = json_decode($rawInput, true) ?? [];
    }
    if (empty($data)) {
        $data = $_POST;
    }
    
    $alert_enabled = isset($data['alert_enabled']) ? (int)$data['alert_enabled'] : 1;
    $ringtone_name = trim($data['ringtone_name'] ?? 'preview');
    $vibration_duration_sec = isset($data['vibration_duration_sec']) ? (int)$data['vibration_duration_sec'] : 15;
    $dialog_countdown_sec = isset($data['dialog_countdown_sec']) ? (int)$data['dialog_countdown_sec'] : 45;
    $notification_title_template = trim($data['notification_title_template'] ?? 'New Trip Available - {trip_type}');
    $notification_body_template = trim($data['notification_body_template'] ?? "From: {pickup_location}\nTo: {drop_location}\nEarnings: ₹{vendor_amount}");
    $advance_lead_time_min = isset($data['advance_lead_time_min']) ? (int)$data['advance_lead_time_min'] : 45;
    $auto_retry_enabled = isset($data['auto_retry_enabled']) ? (int)$data['auto_retry_enabled'] : 1;
    $retry_interval_min = isset($data['retry_interval_min']) ? (int)$data['retry_interval_min'] : 2;
    $max_retries = isset($data['max_retries']) ? (int)$data['max_retries'] : 3;
    
    // Clamping values to safe ranges
    $vibration_duration_sec = max(5, min(60, $vibration_duration_sec));
    $dialog_countdown_sec = max(10, min(120, $dialog_countdown_sec));
    $advance_lead_time_min = max(15, min(120, $advance_lead_time_min));
    $retry_interval_min = max(1, min(10, $retry_interval_min));
    $max_retries = max(1, min(5, $max_retries));
    
    if (empty($notification_title_template)) {
        $notification_title_template = 'New Trip Available - {trip_type}';
    }
    if (empty($notification_body_template)) {
        $notification_body_template = "From: {pickup_location}\nTo: {drop_location}\nEarnings: ₹{vendor_amount}";
    }
    
    $update_stmt = $conn->prepare("UPDATE driver_alert_settings SET alert_enabled = ?, ringtone_name = ?, vibration_duration_sec = ?, dialog_countdown_sec = ?, notification_title_template = ?, notification_body_template = ?, advance_lead_time_min = ?, auto_retry_enabled = ?, retry_interval_min = ?, max_retries = ? WHERE id = 1");
    if (!$update_stmt) {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to prepare update statement: ' . $conn->error
        ]);
        exit;
    }
    
    $update_stmt->bind_param("isiissiiii", $alert_enabled, $ringtone_name, $vibration_duration_sec, $dialog_countdown_sec, $notification_title_template, $notification_body_template, $advance_lead_time_min, $auto_retry_enabled, $retry_interval_min, $max_retries);
    
    if (!$update_stmt->execute()) {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update alert settings: ' . $update_stmt->error
        ]);
        $update_stmt->close();
        exit;
    }
    $update_stmt->close();
    
    // Fetch updated settings
    $stmt = $conn->prepare("SELECT * FROM driver_alert_settings WHERE id = 1");
    $stmt->execute();
    $res = $stmt->get_result();
    $updated = $res->fetch_assoc();
    $stmt->close();
    
    $updated['id'] = (int)$updated['id'];
    $updated['alert_enabled'] = (int)$updated['alert_enabled'];
    $updated['vibration_duration_sec'] = (int)$updated['vibration_duration_sec'];
    $updated['dialog_countdown_sec'] = (int)$updated['dialog_countdown_sec'];
    $updated['advance_lead_time_min'] = (int)$updated['advance_lead_time_min'];
    $updated['auto_retry_enabled'] = (int)$updated['auto_retry_enabled'];
    $updated['retry_interval_min'] = (int)$updated['retry_interval_min'];
    $updated['max_retries'] = (int)$updated['max_retries'];
    
    echo json_encode([
        'success' => true,
        'message' => 'Driver alert settings updated successfully',
        'data' => $updated
    ]);
    exit;

}

echo json_encode([
    'success' => false,
    'message' => 'Method not allowed'
]);
