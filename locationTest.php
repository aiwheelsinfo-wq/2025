<?php

$lat = isset($_POST['latitude']) ? floatval($_POST['latitude']) : 0;
$lng = isset($_POST['longitude']) ? floatval($_POST['longitude']) : 0;

// Define Mumbai boundary (simple rectangle)
$mumbai_min_lat = 18.89;
$mumbai_max_lat = 19.52;
$mumbai_min_lng = 72.74;
$mumbai_max_lng = 73.244964;

function isWithinMumbai($lat, $lng, $minLat, $maxLat, $minLng, $maxLng) {
    return ($lat >= $minLat && $lat <= $maxLat && $lng >= $minLng && $lng <= $maxLng);
}

if (!isWithinMumbai($lat, $lng, $mumbai_min_lat, $mumbai_max_lat, $mumbai_min_lng, $mumbai_max_lng)) {
    echo json_encode([
        "status" => "error",
        "message" => "Your booking is outside Mumbai. Service not available."
    ]);
    exit;
}

// Proceed with booking logic here...
echo json_encode([
    "status" => "success",
    "message" => "Booking confirmed!"
]);

?>
