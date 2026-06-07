<?php
// Fetch driver data from API
$api_url = 'https://agnicarrental.com/driver2025/driver_list_Agni.php';
$response = file_get_contents($api_url);
$drivers = json_decode($response, true)['driversdata'] ?? [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver List</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script>
        function searchDrivers() {
            let query = document.getElementById("searchInput").value.toLowerCase();
            let rows = document.querySelectorAll("#driverTable tbody tr");

            rows.forEach(row => {
                let text = row.innerText.toLowerCase(); // Searches all content, including hidden
                row.style.display = text.includes(query) ? "" : "none";
            });
        }
    </script>
    <style>
        .hidden-data { display: none; } /* Hide extra data but still make it searchable */
    </style>
</head>
<body>
    <div class="container mt-4">
        <h2 class="text-center">Driver List</h2>
        <div class="mb-3">
            <input type="text" id="searchInput" class="form-control" placeholder="Search drivers..." onkeyup="searchDrivers()">
        </div>
        <table class="table table-striped table-bordered" id="driverTable">
            <thead class="table-dark">
                <tr>
                    <th>Phone Number</th>
                    <th>Full Name</th>
                    <th>Vehicle ID</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($drivers as $driver): ?>
                <tr>
                    <td><?= htmlspecialchars($driver['phone_number'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($driver['full_name'] ?? 'Unknown') ?></td>
                    <td><?= htmlspecialchars($driver['vehicle_id'] ?? 'Unknown') ?></td>
                    <td>
                        <a href="regForm.php?phone_number=<?= urlencode($driver['phone_number']) ?>" class="btn btn-primary btn-sm">
                            Edit
                        </a>
                    </td>
                    <!-- Hidden but searchable extra data -->
                    <td class="hidden-data"><?= htmlspecialchars($driver['email'] ?? '') ?></td>
                    <td class="hidden-data"><?= htmlspecialchars($driver['date_of_birth'] ?? '') ?></td>
                    <td class="hidden-data"><?= htmlspecialchars($driver['vehicle_type'] ?? '') ?></td>
                    <td class="hidden-data"><?= htmlspecialchars($driver['vehicle_name'] ?? '') ?></td>
                    <td class="hidden-data"><?= htmlspecialchars($driver['driver_address'] ?? '') ?></td>
                    <td class="hidden-data"><?= htmlspecialchars($driver['pin_code'] ?? '') ?></td>
                    <td class="hidden-data"><?= htmlspecialchars($driver['license_no'] ?? '') ?></td>
                    <td class="hidden-data"><?= htmlspecialchars($driver['license_doe'] ?? '') ?></td>
                    <td class="hidden-data"><?= htmlspecialchars($driver['license_type'] ?? '') ?></td>
                    <td class="hidden-data"><?= htmlspecialchars($driver['adhaar_card_no'] ?? '') ?></td>
                    <td class="hidden-data"><?= htmlspecialchars($driver['pan_card_no'] ?? '') ?></td>
                    <td class="hidden-data"><?= htmlspecialchars($driver['photo'] ?? '') ?></td>
                    <td class="hidden-data"><?= htmlspecialchars($driver['rc_no'] ?? '') ?></td>
                    <td class="hidden-data"><?= htmlspecialchars($driver['rc_name'] ?? '') ?></td>
                    <td class="hidden-data"><?= htmlspecialchars($driver['rc_manufecture_date'] ?? '') ?></td>
                    <td class="hidden-data"><?= htmlspecialchars($driver['insurnce_number'] ?? '') ?></td>
                    <td class="hidden-data"><?= htmlspecialchars($driver['insurnce_doe'] ?? '') ?></td>
                    <td class="hidden-data"><?= htmlspecialchars($driver['puc_doi'] ?? '') ?></td>
                    <td class="hidden-data"><?= htmlspecialchars($driver['puc_doe'] ?? '') ?></td>
                    <td class="hidden-data"><?= htmlspecialchars($driver['texi_permit_no'] ?? '') ?></td>
                    <td class="hidden-data"><?= htmlspecialchars($driver['texi_permit_doi'] ?? '') ?></td>
                    <td class="hidden-data"><?= htmlspecialchars($driver['texi_permit_doe'] ?? '') ?></td>
                    <td class="hidden-data"><?= htmlspecialchars($driver['fitness_certificate_no'] ?? '') ?></td>
                    <td class="hidden-data"><?= htmlspecialchars($driver['fitness_certificate_doi'] ?? '') ?></td>
                    <td class="hidden-data"><?= htmlspecialchars($driver['fitness_certificate_doe'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
