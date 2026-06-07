<?php
header('Content-Type: application/json');

// 🔴 Toggle (later move to DB)
$showNotification = true;

if (!$showNotification) {
    echo json_encode([
        "success" => false,
        "notification" => null
    ]);
    exit;
}

// 🔴 Notification payload
$notification = [
    "id" => 1, // unique ID (VERY IMPORTANT for showOnce)
    "title" => "Premium Offer 🚀",
    "message" => "You unlocked exclusive rewards!",
    "imageUrl" => "https://agnicarrental.com/2025/pop_up_image/pop_up_2.png",
    "showOnce" => true // control repetition
];

// 🔴 Final response
echo json_encode([
    "success" => true,
    "notification" => $notification
]);
?>