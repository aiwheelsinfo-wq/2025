<?php
/**
 * Standalone Sandbox Mock RC Verification Service
 * 
 * Used for testing driver vehicle registration without calling Surepass API.
 * Does not touch or affect production verify_rc.php or send_rc_otp.php.
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$rawInput = file_get_contents('php://input');
$inputData = json_decode($rawInput, true) ?? [];

$rc_number = strtoupper(trim(preg_replace('/[\s\-]/', '', $inputData['rc_number'] ?? $inputData['rc_no'] ?? $_GET['rc_number'] ?? 'KL72D5275')));
$mode = $inputData['mode'] ?? $_GET['mode'] ?? 'send_otp';
$otp = trim($inputData['otp'] ?? $_GET['otp'] ?? '');
$preset = strtoupper(trim($inputData['preset'] ?? $_GET['preset'] ?? 'SEDAN'));

if (empty($rc_number)) {
    $rc_number = "KL72D5275";
}

// Preset vehicle mock profiles
$profiles = [
    'SEDAN' => [
        'maker_model' => 'DZIRE VXI',
        'maker_description' => 'MARUTI SUZUKI INDIA LTD',
        'fuel_type' => 'PETROL',
        'seat_capacity' => '5',
        'vehicle_category' => 'MOTOR CAB',
        'vehicle_category_description' => 'SEDAN',
        'plate_color' => 'YELLOW PLATE'
    ],
    'SUV' => [
        'maker_model' => 'BREZZA ZXI / CRETA SX',
        'maker_description' => 'MARUTI SUZUKI / HYUNDAI',
        'fuel_type' => 'PETROL',
        'seat_capacity' => '5',
        'vehicle_category' => 'MOTOR CAB',
        'vehicle_category_description' => 'SUV',
        'plate_color' => 'YELLOW PLATE'
    ],
    'ERTIGA' => [
        'maker_model' => 'ERTIGA ZXI',
        'maker_description' => 'MARUTI SUZUKI INDIA LTD',
        'fuel_type' => 'PETROL / CNG',
        'seat_capacity' => '7',
        'vehicle_category' => 'MOTOR CAB',
        'vehicle_category_description' => 'ERTIGA',
        'plate_color' => 'YELLOW PLATE'
    ],
    'INNOVA' => [
        'maker_model' => 'INNOVA 2.5 V',
        'maker_description' => 'TOYOTA KIRLOSKAR MOTOR PVT LTD',
        'fuel_type' => 'DIESEL',
        'seat_capacity' => '7',
        'vehicle_category' => 'MAXI CAB',
        'vehicle_category_description' => 'INNOVA',
        'plate_color' => 'YELLOW PLATE'
    ],
    'CRYSTA' => [
        'maker_model' => 'INNOVA CRYSTA 2.4 GX',
        'maker_description' => 'TOYOTA KIRLOSKAR MOTOR PVT LTD',
        'fuel_type' => 'DIESEL',
        'seat_capacity' => '7',
        'vehicle_category' => 'MAXI CAB',
        'vehicle_category_description' => 'CRYSTA',
        'plate_color' => 'YELLOW PLATE'
    ],
    'HATCHBACK' => [
        'maker_model' => 'SWIFT VXI / WAGONR',
        'maker_description' => 'MARUTI SUZUKI INDIA LTD',
        'fuel_type' => 'PETROL / CNG',
        'seat_capacity' => '5',
        'vehicle_category' => 'MOTOR CAB',
        'vehicle_category_description' => 'HATCHBACK',
        'plate_color' => 'YELLOW PLATE'
    ],
    'TEMPO_TRAVELLER' => [
        'maker_model' => 'FORCE TRAVELLER 3350',
        'maker_description' => 'FORCE MOTORS LTD',
        'fuel_type' => 'DIESEL',
        'seat_capacity' => '17',
        'vehicle_category' => 'OMNIBUS / MAXI CAB',
        'vehicle_category_description' => 'TEMPO_TRAVELLER',
        'plate_color' => 'YELLOW PLATE'
    ]
];

$selectedProfile = $profiles[$preset] ?? $profiles['SEDAN'];

// 1. Send Mock OTP Mode
if ($mode === 'send_otp') {
    echo json_encode([
        "status" => "success",
        "success" => true,
        "message" => "TEST MODE: OTP sent to vehicle owner mobile (Test OTP: 123456)",
        "otp_required" => true,
        "client_id" => "mock_rc_session_" . uniqid(),
        "mobile_number" => "95*****142",
        "test_otp" => "123456",
        "rc_number" => $rc_number,
        "preset" => $preset
    ]);
    exit;
}

// 2. Verify Mock OTP Mode
if ($mode === 'verify_otp') {
    if (!empty($otp) && $otp !== '123456' && $otp !== '000000') {
        echo json_encode([
            "status" => "error",
            "success" => false,
            "message" => "Invalid Test OTP! Please enter 123456 for Sandbox mode."
        ]);
        exit;
    }
}

// 3. Return Mock RC Full Details (for verify_otp or quick_mock)
echo json_encode([
    "status" => "success",
    "success" => true,
    "message" => "RC Verified successfully via Sandbox Mock Service!",
    "data" => [
        "rc_number" => $rc_number,
        "owner_name" => "ANIL KUMAR M (SANDBOX TEST)",
        "maker_model" => $selectedProfile['maker_model'],
        "maker_description" => $selectedProfile['maker_description'],
        "registration_date" => "2021-04-12",
        "fit_up_to" => "2036-04-11",
        "insurance_policy_number" => "TEST-POL-99882211",
        "insurance_upto" => "2027-12-31",
        "insurance_company" => "National Insurance Co. Ltd.",
        "fuel_type" => $selectedProfile['fuel_type'],
        "color" => "WHITE",
        "seat_capacity" => $selectedProfile['seat_capacity'],
        "permit_number" => "KL/PERMIT/2021/7788",
        "permit_valid_upto" => "2029-04-11",
        "permit_type" => "CONTRACT CARRIAGE / MOTOR CAB",
        "vehicle_category" => $selectedProfile['vehicle_category'],
        "vehicle_category_description" => $selectedProfile['vehicle_category_description'],
        "pucc_number" => "KL-PUC-554433",
        "pucc_upto" => "2027-06-30",
        "rc_status" => "ACTIVE",
        "permanent_address" => "Kaloor, Kochi, Ernakulam, Kerala 682017",
        "verification_status" => "VERIFIED_SANDBOX"
    ]
]);
?>
