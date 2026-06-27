<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'db_connect.php';
include 'notification_helper.php';

echo "Sending email alert directly to ansiansu27@gmail.com...\n";
$res = sendEmailAlert('ansiansu27@gmail.com', 'Direct Email Test', '<h1>This is a direct test email</h1>', 'Test User');
echo "Result:\n";
var_dump($res);
?>
