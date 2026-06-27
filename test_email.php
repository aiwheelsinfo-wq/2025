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
$sender_name = 'Agni Car Rental';
$sender_email = 'ai.wheels.info@gmail.com';

foreach ($config_paths as $config_path) {
    if (file_exists($config_path)) {
        require_once $config_path;
        if (defined('EMAIL_API_KEY')) {
            $api_key = EMAIL_API_KEY;
            $sender_name = EMAIL_SENDER_NAME;
            $sender_email = EMAIL_SENDER_EMAIL;
            break;
        }
    }
}

if (empty($api_key)) {
    echo "Error: Could not load EMAIL_API_KEY from email_config.php\n";
    exit(1);
}

$to_email = 'ansiansu27@gmail.com';
$to_name = 'Test User';
$subject = 'Direct Debug Email';
$html_body = '<h1>This is a direct test email</h1>';

$url = 'https://api.brevo.com/v3/smtp/email';
$headers = [
    'api-key: ' . $api_key,
    'Content-Type: application/json'
];
$payload = [
    'sender' => ['name' => $sender_name, 'email' => $sender_email],
    'to' => [['email' => $to_email, 'name' => $to_name]],
    'subject' => $subject,
    'htmlContent' => $html_body
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $http_code\n";
echo "Response: $response\n";
?>
