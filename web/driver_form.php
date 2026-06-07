<?php
header("Content-Type: application/json");
include 'db_connection.php'; // Ensure you have a database connection file




    
        $phone_number = $_GET['phone_number'];
        $query = "SELECT * FROM drivers WHERE phone_number = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $phone_number);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $driver = $result->fetch_assoc();
            echo json_encode(['status' => 'success', 'driversdata' => $driver]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Driver not found']);
        }
    

    

    $phone_number = $data['phone_number'];
    $full_name = $data['full_name'] ?? '';
    $email = $data['email'] ?? '';
    $vehicle_id = $data['vehicle_id'] ?? '';
    $driver_address = $data['driver_address'] ?? '';
    $license_no = $data['license_no'] ?? '';
    $license_doe = $data['license_doe'] ?? '';
    $license_type = $data['license_type'] ?? '';
    $adhaar_card_no = $data['adhaar_card_no'] ?? '';
    $pan_card_no = $data['pan_card_no'] ?? '';
    $photo = $data['photo'] ?? 'NO';
    $rc_no = $data['rc_no'] ?? '';
    $rc_name = $data['rc_name'] ?? '';
    $rc_manufecture_date = $data['rc_manufecture_date'] ?? '';
    $insurnce_number = $data['insurnce_number'] ?? '';
    $insurnce_doe = $data['insurnce_doe'] ?? '';
    $puc_doi = $data['puc_doi'] ?? '';
    $puc_doe = $data['puc_doe'] ?? '';
    $texi_permit_no = $data['texi_permit_no'] ?? '';
    $texi_permit_doi = $data['texi_permit_doi'] ?? '';
    $texi_permit_doe = $data['texi_permit_doe'] ?? '';
    $fitness_certificate_no = $data['fitness_certificate_no'] ?? '';
    $fitness_certificate_doi = $data['fitness_certificate_doi'] ?? '';
    $fitness_certificate_doe = $data['fitness_certificate_doe'] ?? '';

    $query = "INSERT INTO drivers (phone_number, full_name, email, vehicle_id, driver_address, 
                license_no, license_doe, license_type, adhaar_card_no, pan_card_no, photo, rc_no, rc_name, 
                rc_manufecture_date, insurnce_number, insurnce_doe, puc_doi, puc_doe, texi_permit_no, 
                texi_permit_doi, texi_permit_doe, fitness_certificate_no, fitness_certificate_doi, fitness_certificate_doe) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
              ON DUPLICATE KEY UPDATE 
                full_name = VALUES(full_name), email = VALUES(email), vehicle_id = VALUES(vehicle_id),
                driver_address = VALUES(driver_address), license_no = VALUES(license_no), license_doe = VALUES(license_doe),
                license_type = VALUES(license_type), adhaar_card_no = VALUES(adhaar_card_no), pan_card_no = VALUES(pan_card_no),
                photo = VALUES(photo), rc_no = VALUES(rc_no), rc_name = VALUES(rc_name), rc_manufecture_date = VALUES(rc_manufecture_date),
                insurnce_number = VALUES(insurnce_number), insurnce_doe = VALUES(insurnce_doe), puc_doi = VALUES(puc_doi),
                puc_doe = VALUES(puc_doe), texi_permit_no = VALUES(texi_permit_no), texi_permit_doi = VALUES(texi_permit_doi),
                texi_permit_doe = VALUES(texi_permit_doe), fitness_certificate_no = VALUES(fitness_certificate_no),
                fitness_certificate_doi = VALUES(fitness_certificate_doi), fitness_certificate_doe = VALUES(fitness_certificate_doe)";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("sssssssssssssssssssssssss", $phone_number, $full_name, $email, $vehicle_id, $driver_address, 
                      $license_no, $license_doe, $license_type, $adhaar_card_no, $pan_card_no, $photo, $rc_no, $rc_name, 
                      $rc_manufecture_date, $insurnce_number, $insurnce_doe, $puc_doi, $puc_doe, $texi_permit_no, 
                      $texi_permit_doi, $texi_permit_doe, $fitness_certificate_no, $fitness_certificate_doi, $fitness_certificate_doe);

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Driver details saved successfully']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to save driver details']);
    }

    $stmt->close();


$conn->close();
?>
