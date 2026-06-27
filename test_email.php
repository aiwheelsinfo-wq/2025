<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'notification_helper.php';

echo "Sending SMS alert directly to +917977098244...\n";
$res = sendSMSAlert('+917977098244', 'SMS Diagnostic Test - Agni Car Rental');
echo "Result:\n";
var_dump($res);
?>
