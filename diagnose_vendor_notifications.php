<?php
/**
 * Vendor Notification Diagnostic & Cleanup Tool
 * 
 * This script:
 * 1. Fetches all vendor FCM tokens from DB
 * 2. Tests each token via FCM API
 * 3. Reports results and optionally cleans up invalid tokens
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

require_once 'vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;

include 'db_connect.php';

$cleanup = isset($_GET['cleanup']) && $_GET['cleanup'] === '1';
$test_token = isset($_GET['token']) ? trim($_GET['token']) : null;

// Get access token
function getFcmAccessToken() {
    $keyFile = __DIR__ . '/agni-car-app-firebase-adminsdk-fbsvc-4f70f7d1f2.json';
    $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];
    $credentials = new ServiceAccountCredentials($scopes, $keyFile);
    $token = $credentials->fetchAuthToken();
    return $token['access_token'];
}

// Test a single FCM token
function testFcmToken($accessToken, $projectId, $token) {
    $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
    $payload = [
        'message' => [
            'token' => $token,
            'notification' => [
                'title' => '✅ Test - New Trip Available',
                'body' => 'A new booking is waiting. Please open the vendor app.'
            ],
            'data' => [
                'notification_type' => 'new_booking',
                'booking_id' => '0',
                'test' => 'true'
            ]
        ]
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($result, true);
    return ['http_code' => $httpCode, 'response' => $decoded, 'raw' => $result];
}

try {
    $accessToken = getFcmAccessToken();
    $keyData = json_decode(file_get_contents(__DIR__ . '/agni-car-app-firebase-adminsdk-fbsvc-4f70f7d1f2.json'), true);
    $projectId = $keyData['project_id'] ?? 'agnicarrentaldriver-8fb07';

    // If testing a specific token
    if ($test_token) {
        $result = testFcmToken($accessToken, $projectId, $test_token);
        echo json_encode([
            'mode' => 'single_token_test',
            'token' => substr($test_token, 0, 20) . '...',
            'project_id' => $projectId,
            'http_code' => $result['http_code'],
            'status' => $result['http_code'] === 200 ? 'SUCCESS' : 'FAILED',
            'response' => $result['response']
        ], JSON_PRETTY_PRINT);
        exit;
    }

    // Fetch ALL vendor tokens from database
    $sql = "SELECT id, name, phone, fcm_token, userType, status 
            FROM drivers 
            WHERE userType = 'vendor' 
            AND fcm_token IS NOT NULL 
            AND fcm_token != '' 
            ORDER BY id DESC";
    
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        echo json_encode(['error' => 'DB query failed: ' . mysqli_error($conn)]);
        exit;
    }

    $vendors = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $vendors[] = $row;
    }

    $report = [];
    $valid_tokens = 0;
    $unregistered_tokens = 0;
    $sender_mismatch_tokens = 0;
    $other_errors = 0;
    $cleaned_up = [];

    foreach ($vendors as $vendor) {
        $token = trim($vendor['fcm_token']);
        if (empty($token)) continue;

        $fcm_result = testFcmToken($accessToken, $projectId, $token);
        $httpCode = $fcm_result['http_code'];
        $response = $fcm_result['response'];

        $status = 'UNKNOWN';
        $error_code = null;

        if ($httpCode === 200) {
            $status = 'SUCCESS';
            $valid_tokens++;
        } elseif ($httpCode === 404) {
            $error_code = $response['error']['details'][0]['errorCode'] ?? 'NOT_FOUND';
            if ($error_code === 'UNREGISTERED') {
                $status = 'UNREGISTERED';
                $unregistered_tokens++;
                // Clean up this token if cleanup mode is on
                if ($cleanup) {
                    $clean_sql = "UPDATE drivers SET fcm_token = NULL WHERE id = " . intval($vendor['id']);
                    mysqli_query($conn, $clean_sql);
                    $cleaned_up[] = $vendor['id'];
                }
            } else {
                $status = 'NOT_FOUND';
                $other_errors++;
            }
        } elseif ($httpCode === 403) {
            $error_code = $response['error']['details'][0]['errorCode'] ?? 'PERMISSION_DENIED';
            if ($error_code === 'SENDER_ID_MISMATCH') {
                $status = 'SENDER_ID_MISMATCH';
                $sender_mismatch_tokens++;
                // Also clean these - they're from wrong Firebase project
                if ($cleanup) {
                    $clean_sql = "UPDATE drivers SET fcm_token = NULL WHERE id = " . intval($vendor['id']);
                    mysqli_query($conn, $clean_sql);
                    $cleaned_up[] = $vendor['id'];
                }
            } else {
                $status = 'PERMISSION_DENIED';
                $other_errors++;
            }
        } else {
            $status = 'ERROR_' . $httpCode;
            $other_errors++;
        }

        $report[] = [
            'vendor_id' => $vendor['id'],
            'name' => $vendor['name'],
            'phone' => $vendor['phone'],
            'db_status' => $vendor['status'],
            'token_preview' => substr($token, 0, 25) . '...',
            'fcm_status' => $status,
            'error_code' => $error_code
        ];
    }

    echo json_encode([
        'project_id' => $projectId,
        'fcm_auth' => 'SUCCESS',
        'total_vendors_with_tokens' => count($vendors),
        'summary' => [
            'valid_tokens' => $valid_tokens,
            'unregistered_expired' => $unregistered_tokens,
            'sender_id_mismatch' => $sender_mismatch_tokens,
            'other_errors' => $other_errors
        ],
        'cleanup_mode' => $cleanup,
        'cleaned_up_vendor_ids' => $cleaned_up,
        'details' => $report,
        'note' => $sender_mismatch_tokens > 0 
            ? "SENDER_ID_MISMATCH means these devices registered with a different Firebase project. The vendor app needs to be rebuilt with the correct google-services.json (package: com.AgniDriver.agnidriver2025) and vendors must re-login."
            : ""
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}
?>
