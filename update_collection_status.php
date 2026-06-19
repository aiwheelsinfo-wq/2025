<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

include 'db_connect.php';

$response = array();

// Decode JSON input if content-type is application/json or if $_POST is empty
$input = json_decode(file_get_contents('php://input'), true);
if (empty($_POST) && !empty($input)) {
    $_POST = $input;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" || isset($_POST['booking_id'])) {
    if (!isset($_POST['booking_id']) || empty($_POST['booking_id'])) {
        echo json_encode([
            "success" => false,
            "message" => "Missing booking_id parameter"
        ]);
        exit;
    }
    
    $booking_id = mysqli_real_escape_string($conn, trim($_POST['booking_id']));
    $collection_status = isset($_POST['collection_status']) ? mysqli_real_escape_string($conn, trim($_POST['collection_status'])) : 'Collected';
    $collection_date = isset($_POST['collection_date']) ? mysqli_real_escape_string($conn, trim($_POST['collection_date'])) : date('Y-m-d');
    
    // Update booking
    $sql = "UPDATE bookings SET 
                collection_status = '$collection_status', 
                collection_date = '$collection_date' 
            WHERE id = '$booking_id'";
            
    if (mysqli_query($conn, $sql)) {
        $response["success"] = true;
        $response["message"] = "Collection status updated successfully";
    } else {
        $response["success"] = false;
        $response["message"] = "Error updating collection status: " . mysqli_error($conn);
    }
} else {
    $response["success"] = false;
    $response["message"] = "Invalid request method";
}

echo json_encode($response);
mysqli_close($conn);
?>
