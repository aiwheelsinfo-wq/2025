<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'db_connect.php';
include 'notification_helper.php';

echo "Sending booking notification for ID 1...\n";
$res = sendBookingWhatsAppNotification(1, $conn);
echo "Result:\n";
var_dump($res);
?>
