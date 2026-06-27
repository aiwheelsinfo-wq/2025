<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Dynamically resolve Brevo credentials from server config to prevent repository secret leaks
$config_paths = [
    __DIR__ . '/admin2025/restox-api-console/email_config.php',
    __DIR__ . '/../admin2025/restox-api-console/email_config.php',
    __DIR__ . '/../../admin2025/restox-api-console/email_config.php',
    '/var/www/html/admin2025/restox-api-console/email_config.php'
];

$api_key = '';
foreach ($config_paths as $config_path) {
    if (file_exists($config_path)) {
        require_once $config_path;
        if (defined('EMAIL_API_KEY')) {
            $api_key = EMAIL_API_KEY;
            break;
        }
    }
}

if (empty($api_key)) {
    echo "Error: Could not load EMAIL_API_KEY from email_config.php\n";
    exit(1);
}

$url = 'https://api.brevo.com/v3/smtp/statistics/events?limit=5&email=ansiansu27@gmail.com';
$headers = [
    'api-key: ' . $api_key,
    'Content-Type: application/json'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $http_code\n";
echo "Response:\n";
echo json_encode(json_decode($response), JSON_PRETTY_PRINT) . "\n";
?>
