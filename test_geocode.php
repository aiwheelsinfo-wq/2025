<?php
function getCoordinates($address) {
    $apiKey = 'AIzaSyC41U3p08LqY8G15ruxDCEfTvBLkG_OrsM';
    $encodedAddress = urlencode($address);
    $url = "https://maps.googleapis.com/maps/api/geocode/json?address=$encodedAddress&key=$apiKey";
    $response = file_get_contents($url);
    $data = json_decode($response);

    if ($data->status === 'OK') {
        return [
            'lat' => $data->results[0]->geometry->location->lat,
            'lng' => $data->results[0]->geometry->location->lng,
            'formatted_address' => $data->results[0]->formatted_address
        ];
    }
    return [
        'error' => $data->status,
        'message' => isset($data->error_message) ? $data->error_message : 'No message'
    ];
}

$mumbai_min_lat = 18.89;
$mumbai_max_lat = 19.52;
$mumbai_min_lng = 72.74;
$mumbai_max_lng = 73.244964;

function isWithinMumbai($lat, $lng, $minLat, $maxLat, $minLng, $maxLng) {
    return ($lat >= $minLat && $lat <= $maxLat && $lng >= $minLng && $lng <= $maxLng);
}

$addresses = ['Mulund, Mumbai', 'Thane', 'Mulund', 'Thane, Maharashtra'];

foreach ($addresses as $addr) {
    echo "Address: $addr\n";
    $coords = getCoordinates($addr);
    if (isset($coords['error'])) {
        echo "Error: " . $coords['error'] . " - " . $coords['message'] . "\n\n";
    } else {
        echo "Coords: Lat=" . $coords['lat'] . ", Lng=" . $coords['lng'] . "\n";
        echo "Formatted: " . $coords['formatted_address'] . "\n";
        $inMumbai = isWithinMumbai($coords['lat'], $coords['lng'], $mumbai_min_lat, $mumbai_max_lat, $mumbai_min_lng, $mumbai_max_lng);
        echo "Within Mumbai? " . ($inMumbai ? "YES" : "NO") . "\n\n";
    }
}
