<?php
session_start(); // Always start the session

// Check if phone number exists
if (isset($_SESSION['phone_number'])) {
    echo "Phone number in session: " . $_SESSION['phone_number'];
} else {
    echo "No phone number stored in session.";
}

// Optional: See all session data
echo "<pre>";
print_r($_SESSION);
echo "</pre>";
?>
