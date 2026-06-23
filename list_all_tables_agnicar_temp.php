<?php
header("Content-Type: application/json");

$host = "localhost";
$dbname = "agnicar_temp";
$username = "agnicar";
$password = "dGwW(W8b237~";

$conn = mysqli_connect($host, $username, $password, $dbname);
if (!$conn) {
    echo json_encode(["success" => false, "error" => "Connection failed: " . mysqli_connect_error()]);
    exit;
}

$result = mysqli_query($conn, "SHOW TABLES");
$tables = [];

if ($result) {
    while ($row = mysqli_fetch_row($result)) {
        $tableName = $row[0];
        $cntResult = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM `$tableName`");
        $count = 0;
        if ($cntResult) {
            $cntRow = mysqli_fetch_assoc($cntResult);
            $count = (int)$cntRow['cnt'];
        }
        $tables[$tableName] = $count;
    }
} else {
    echo json_encode(["success" => false, "error" => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode(["success" => true, "tables" => $tables]);
mysqli_close($conn);
?>
