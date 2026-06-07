<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agni Car</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Arial, sans-serif;
        }

        body {
            background: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }

        .container {
            max-width: 900px;
            margin: 40px auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        header {
            background: linear-gradient(135deg, #2b6cb0, #1e40af);
            padding: 20px 30px;
            color: white;
        }

        header h1 {
            font-size: 24px;
            font-weight: 600;
        }

        .form-content {
            padding: 30px;
        }

        .section {
            margin-bottom: 30px;
            padding: 20px;
            background: #fafafa;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .section:hover {
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        h2 {
            color: #2b6cb0;
            font-size: 20px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-weight: 500;
            margin-bottom: 6px;
            color: #4b5563;
        }

        input, select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.2s ease;
        }

        input:focus, select:focus {
            outline: none;
            border-color: #2b6cb0;
            box-shadow: 0 0 0 3px rgba(43, 108, 176, 0.2);
        }

        .counter-wrapper {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .counter {
            font-size: 13px;
            color: #6b7280;
            font-weight: 500;
            background: #f3f4f6;
            padding: 2px 8px;
            border-radius: 4px;
        }

        select {
            appearance: none;
            background-image: url('data:image/svg+xml;utf8,<svg fill="%236b7280" height="20" viewBox="0 0 20 20" width="20" xmlns="http://www.w3.org/2000/svg"><path d="M5 7l5 5 5-5H5z"/></svg>');
            background-repeat: no-repeat;
            background-position: right 10px center;
            padding-right: 36px;
        }

        button {
            background: #2b6cb0;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.3s ease;
            display: block;
            margin: 20px auto 0;
        }

        button:hover {
            background: #1e40af;
        }

        @media (max-width: 768px) {
            .container {
                margin: 20px;
            }
            .form-content {
                padding: 20px;
            }
            .section {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1 id="formTitle">Driver Registration Form</h1>
        </header>
        <div class="form-content">
            <form id="driverForm">
                <section class="section">
                    <h2>Personal Information</h2>
                    <div class="form-group">
                        <label for="phone_number">Phone Number</label>
                        <input type="text" id="phone_number" name="phone_number" value="<?php echo isset($_GET['phone_number']) ? htmlspecialchars($_GET['phone_number']) : ''; ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label for="full_name">Full Name</label>
                        <input type="text" id="full_name" name="full_name" placeholder="Enter full name" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" placeholder="Enter email" required>
                    </div>
                    <div class="form-group">
                        <label for="date_of_birth">Date of Birth</label>
                        <input type="date" id="date_of_birth" name="date_of_birth" required>
                    </div>
                    <div class="form-group">
                        <label for="driver_address">Address</label>
                        <input type="text" id="driver_address" name="driver_address" placeholder="Enter address" required>
                    </div>
                    <div class="form-group">
                        <label for="driver_city">City</label>
                        <input type="text" id="driver_city" name="driver_city" placeholder="Enter City" required>
                    </div>
                    <div class="form-group">
                        <label for="pin_code">Pin Code</label>
                        <input type="text" id="pin_code" name="pin_code" placeholder="Enter pin code" required>
                    </div>
                </section>

                <section class="section">
                    <h2>Vehicle Information</h2>
                    <div class="form-group">
                        <label for="vehicle_id">Vehicle Number</label>
                        <input type="text" id="vehicle_id" name="vehicle_id" placeholder="Enter vehicle number" required>
                    </div>
                    <div class="form-group">
                        <label for="vehicle_type">Vehicle Type (e.g:suv,sadden,hatchback)</label>
                        <input type="text" id="vehicle_type" name="vehicle_type" placeholder="Enter vehicle type" required>
                    </div>
                    <div class="form-group">
                        <label for="vehicle_name">Vehicle Name</label>
                        <input type="text" id="vehicle_name" name="vehicle_name" placeholder="Enter vehicle name" required>
                    </div>
                    <div class="form-group">
                        <label for="fuel_type">Fuel Type</label>
                        <select id="fuel_type" name="fuel_type">
                            <option value="">Select Fuel Type</option>
                            <option value="petrol">Petrol</option>
                            <option value="diesel">Diesel</option>
                            <option value="cng">CNG</option>
                            <option value="hybrid">Hybrid</option>
                            <option value="ev">EV</option>
                        </select>
                    </div>
                </section>

                <section class="section">
                    <h2>License Details</h2>
                    <div class="form-group">
                        <label for="license_no">License Number</label>
                        <input type="text" id="license_no" name="license_no" placeholder="Enter license number" required>
                    </div>
                    <div class="form-group">
                        <label for="license_doe">License DOE</label>
                        <input type="date" id="license_doe" name="license_doe" required>
                    </div>
                    <div class="form-group">
                        <label for="license_type">License Type</label>
                        <input type="text" id="license_type" name="license_type" placeholder="Enter license type" required>
                    </div>
                </section>

                <section class="section">
                    <h2>Identification</h2>
                    <div class="form-group counter-wrapper">
                        <label for="adhaar_card_no">Aadhar Number</label>
                        <input type="text" id="adhaar_card_no" name="adhaar_card_no" maxlength="12" placeholder="Enter Aadhar number" required>
                        <span id="aadhaar_counter" class="counter">0/12</span>
                    </div>
                    <div class="form-group counter-wrapper">
                        <label for="pan_card_no">PAN Card Number</label>
                        <input type="text" id="pan_card_no" name="pan_card_no" maxlength="10" placeholder="Enter PAN number" required>
                        <span id="pan_counter" class="counter">0/10</span>
                    </div>
                    <div class="form-group">
                        <label for="photo">Photo (YES/NO)</label>
                        <input type="text" id="photo" name="photo" value="NO" required>
                    </div>
                </section>

                <section class="section">
                    <h2>Registration Details</h2>
                    <div class="form-group">
                        <label for="rc_no">RC Number</label>
                        <input type="text" id="rc_no" name="rc_no" placeholder="Enter RC number" required>
                    </div>
                    <div class="form-group">
                        <label for="rc_name">RC Name</label>
                        <input type="text" id="rc_name" name="rc_name" placeholder="Enter RC name" required>
                    </div>
                    <div class="form-group">
                        <label for="rc_manufecture_date">RC Manufacture Date</label>
                        <input type="date" id="rc_manufecture_date" name="rc_manufecture_date" required>
                    </div>
                </section>

                <section class="section">
                    <h2>Insurance & Permits</h2>
                    <div class="form-group">
                        <label for="insurnce_number">Insurance Number</label>
                        <input type="text" id="insurnce_number" name="insurnce_number" placeholder="Enter insurance number" required>
                    </div>
                    <div class="form-group">
                        <label for="insurnce_doe">Insurance DOE</label>
                        <input type="date" id="insurnce_doe" name="insurnce_doe" required>
                    </div>
                    <div class="form-group">
                        <label for="puc_doi">PUC DOI</label>
                        <input type="date" id="puc_doi" name="puc_doi">
                    </div>
                    <div class="form-group">
                        <label for="puc_doe">PUC DOE</label>
                        <input type="date" id="puc_doe" name="puc_doe">
                    </div>
                    <div class="form-group">
                        <label for="texi_permit_no">Taxi Permit Number</label>
                        <input type="text" id="texi_permit_no" name="texi_permit_no" placeholder="Enter permit number" required>
                    </div>
                    <div class="form-group">
                        <label for="texi_permit_doi">Taxi Permit DOI</label>
                        <input type="date" id="texi_permit_doi" name="texi_permit_doi" required>
                    </div>
                    <div class="form-group">
                        <label for="texi_permit_doe">Taxi Permit DOE</label>
                        <input type="date" id="texi_permit_doe" name="texi_permit_doe" required>
                    </div>
                </section>

                <section class="section">
                    <h2>Fitness Certificate</h2>
                    <div class="form-group">
                        <label for="fitness_certificate_no">Certificate Number</label>
                        <input type="text" id="fitness_certificate_no" name="fitness_certificate_no" placeholder="Enter certificate number" >
                    </div>
                    <div class="form-group">
                        <label for="fitness_certificate_doi">DOI</label>
                        <input type="date" id="fitness_certificate_doi" name="fitness_certificate_doi" >
                    </div>
                    <div class="form-group">
                        <label for="fitness_certificate_doe">DOE</label>
                        <input type="date" id="fitness_certificate_doe" name="fitness_certificate_doe" >
                    </div>
                </section>

                <button type="submit" id="submitBtn">Update Profile</button>
            </form>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const phoneNumber = urlParams.get('phone_number');
        
        const aadhaarInput = document.getElementById('adhaar_card_no');
        const panInput = document.getElementById('pan_card_no');
        const aadhaarCounter = document.getElementById('aadhaar_counter');
        const panCounter = document.getElementById('pan_counter');

        aadhaarInput.addEventListener('input', updateAadhaarCounter);
        panInput.addEventListener('input', updatePanCounter);
        
        updateAadhaarCounter();
        updatePanCounter();

        if (phoneNumber) {
            document.getElementById('phone_number').value = phoneNumber;
            document.getElementById('formTitle').innerText = `Driver Registration Form - ${phoneNumber}`;
            fetchDriverDetails(phoneNumber);
        }

        const form = document.getElementById('driverForm');
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (confirm('Are you sure you want to update this driver\'s information?')) {
                await submitForm();
            }
        });
    });

    function updateAadhaarCounter() {
        const aadhaarInput = document.getElementById('adhaar_card_no');
        const aadhaarCounter = document.getElementById('aadhaar_counter');
        const length = aadhaarInput.value.length;
        aadhaarCounter.textContent = `${length}/12`;
    }

    function updatePanCounter() {
        const panInput = document.getElementById('pan_card_no');
        const panCounter = document.getElementById('pan_counter');
        const length = panInput.value.length;
        panCounter.textContent = `${length}/10`;
    }

    async function fetchDriverDetails(phoneNumber) {
        try {
            const response = await fetch(`https://agnicarrental.com/driver2025/register_driver.php?phone_number=${phoneNumber}`);
            const data = await response.json();
            if (data && data.driversdata) {
                const driver = data.driversdata[0];
                document.getElementById('full_name').value = driver.full_name ?? '';
                document.getElementById('email').value = driver.email ?? '';
                document.getElementById('date_of_birth').value = driver.date_of_birth ?? '';
                document.getElementById('vehicle_id').value = driver.vehicle_id ?? '';
                document.getElementById('vehicle_type').value = driver.vehicle_type ?? '';
                document.getElementById('vehicle_name').value = driver.vehicle_name ?? '';
                document.getElementById('fuel_type').value = driver.fuel_type ?? '';
                document.getElementById('driver_address').value = driver.driver_address ?? '';
                document.getElementById('driver_city').value = driver.driver_city ?? '';
                document.getElementById('pin_code').value = driver.pin_code ?? '';
                document.getElementById('license_no').value = driver.license_no ?? '';
                document.getElementById('license_doe').value = driver.license_doe ?? '';
                document.getElementById('license_type').value = driver.license_type ?? '';
                document.getElementById('adhaar_card_no').value = driver.adhaar_card_no ?? '';
                document.getElementById('pan_card_no').value = driver.pan_card_no ?? '';
                document.getElementById('photo').value = driver.photo ?? 'NO';
                document.getElementById('rc_no').value = driver.rc_no ?? '';
                document.getElementById('rc_name').value = driver.rc_name ?? '';
                document.getElementById('rc_manufecture_date').value = driver.rc_manufecture_date ?? '';
                document.getElementById('insurnce_number').value = driver.insurnce_number ?? '';
                document.getElementById('insurnce_doe').value = driver.insurnce_doe ?? '';
                document.getElementById('puc_doi').value = driver.puc_doi ?? '';
                document.getElementById('puc_doe').value = driver.puc_doe ?? '';
                document.getElementById('texi_permit_no').value = driver.texi_permit_no ?? '';
                document.getElementById('texi_permit_doi').value = driver.texi_permit_doi ?? '';
                document.getElementById('texi_permit_doe').value = driver.texi_permit_doe ?? '';
                document.getElementById('fitness_certificate_no').value = driver.fitness_certificate_no ?? '';
                document.getElementById('fitness_certificate_doi').value = driver.fitness_certificate_doi ?? '';
                document.getElementById('fitness_certificate_doe').value = driver.fitness_certificate_doe ?? '';
                
                updateAadhaarCounter();
                updatePanCounter();
            }
        } catch (error) {
            console.error('Fetch error:', error);
            alert('Failed to fetch driver details: ' + error.message);
        }
    }

    async function submitForm() {
        const form = document.getElementById('driverForm');
        const formData = new FormData(form);
        const data = Object.fromEntries(formData.entries());
        data.phone_number = document.getElementById('phone_number').value;

        try {
            const response = await fetch('https://agnicarrental.com/driver2025/register_driver.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });
            const result = await response.json();
            if (result.status === 'success') {
                alert(result.message || 'Driver updated successfully');
                window.location.reload();
            } else if (result.status === 'warning') {
                alert(result.message || 'No changes were detected');
            } else {
                alert(result.message || 'Failed to update driver');
            }
        } catch (error) {
            console.error('Submit error:', error);
            alert('Error: ' + error.message);
        }
    }
    </script>
</body>
</html>