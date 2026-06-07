document.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const phoneNumber = urlParams.get('phone_number');
        
        // Get elements
        const aadhaarInput = document.getElementById('adhaar_card_no');
        const panInput = document.getElementById('pan_card_no');
        const aadhaarCounter = document.getElementById('aadhaar_counter');
        const panCounter = document.getElementById('pan_counter');

        // Add event listeners
        aadhaarInput.addEventListener('input', updateAadhaarCounter);
        panInput.addEventListener('input', updatePanCounter);
        
        // Initialize counters
        updateAadhaarCounter();
        updatePanCounter();

        if (phoneNumber) {
            document.getElementById('phone_number').value = phoneNumber;
            document.getElementById('formTitle').innerText = `Edit Driver - ${phoneNumber}`;
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
                
                // Update counters after loading data
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