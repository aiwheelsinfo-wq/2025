<?php

include 'db_connect.php';

/**
 * Base64 URL Encode
 */
function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Get Access Token (FIXED)
 */
function getAccessToken() {

    $cacheFile = "token_cache.json";

    // ✅ Check cache
    if (file_exists($cacheFile)) {
        $cache = json_decode(file_get_contents($cacheFile), true);

        if ($cache && $cache['expires_at'] > time()) {
            return $cache['access_token'];
        }
    }

    // ✅ Load service account
    $serviceAccount = json_decode(file_get_contents(__DIR__ . "/google-services.json"), true);

    if (!$serviceAccount) {
        die("Invalid service-account.json");
    }

    $header = base64UrlEncode(json_encode([
        "alg" => "RS256",
        "typ" => "JWT"
    ]));

    $now = time();

    $payload = base64UrlEncode(json_encode([
        "iss" => $serviceAccount['client_email'],
        "scope" => "https://www.googleapis.com/auth/firebase.messaging",
        "aud" => $serviceAccount['token_uri'],
        "exp" => $now + 3600,
        "iat" => $now
    ]));

    // ✅ Sign JWT
    openssl_sign(
        "$header.$payload",
        $signature,
        $serviceAccount['private_key'],
        'sha256WithRSAEncryption'
    );

    $jwt = "$header.$payload." . base64UrlEncode($signature);

    // ✅ Request token
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $serviceAccount['token_uri']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        "grant_type" => "urn:ietf:params:oauth:grant-type:jwt-bearer",
        "assertion" => $jwt
    ]));

    $response = curl_exec($ch);

    if ($response === false) {
        die("Token cURL Error: " . curl_error($ch));
    }

    curl_close($ch);

    $data = json_decode($response, true);

    if (!isset($data['access_token'])) {
        die("Access Token Error: " . $response);
    }

    // ✅ Cache token
    file_put_contents($cacheFile, json_encode([
        "access_token" => $data['access_token'],
        "expires_at" => time() + 3500
    ]));

    return $data['access_token'];
}

/**
 * Send FCM Notification
 */
function sendFCM($message) {

    $projectId = "agni-car-app";
    $url = "https://fcm.googleapis.com/v1/projects/" . trim($projectId) . "/messages:send";

    $accessToken = getAccessToken();

    $headers = [
        "Authorization: Bearer " . $accessToken,
        "Content-Type: application/json"
    ];

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));

    $result = curl_exec($ch);

    if ($result === false) {
        die("FCM cURL Error: " . curl_error($ch));
    }

    curl_close($ch);

    return $result;
}

/**
 * ✅ Send to ALL users (Topic)
 */
function sendToAllUsers($title, $body) {

    $message = [
        "message" => [
            "topic" => "all_users",
            "notification" => [
                "title" => $title,
                "body" => $body
            ],
            "data" => [
                "type" => "offer",
                "screen" => "gift_page"
            ]
        ]
    ];

    return sendFCM($message);
}

/**
 * 🔥 EXECUTE
 */

$response = sendToAllUsers(
    "Rentox Customer",
    "🎁 You have a new gift offer! Open the app now to claim it."
);

echo $response;

?>