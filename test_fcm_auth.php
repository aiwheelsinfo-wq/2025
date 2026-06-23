<?php
require_once __DIR__ . '/vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;

header("Content-Type: text/plain");
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "Testing Google Service Account Auth...\n";

try {
    $googleAccountKeyFilePath = __DIR__ . '/agni-car-app-firebase-adminsdk-fbsvc-4f70f7d1f2.json';
    $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];
    $credentials = new ServiceAccountCredentials($scopes, $googleAccountKeyFilePath);
    $accessToken = $credentials->fetchAuthToken();
    echo "Access Token: " . substr($accessToken['access_token'], 0, 20) . "...\n";
    echo "SUCCESS!\n";
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}
?>
