<?php
/**
 * Migration: 006_add_discount_amount_to_bookings.php
 * Adds discount_amount and discount_name columns to the bookings table.
 */
function run($conn) {
    // Add discount_amount column if it doesn't exist
    $checkCol = $conn->query("SHOW COLUMNS FROM `bookings` LIKE 'discount_amount'");
    if ($checkCol && $checkCol->num_rows === 0) {
        $sql = "ALTER TABLE `bookings` 
                ADD COLUMN `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                ADD COLUMN `discount_name` VARCHAR(100) DEFAULT NULL";
        if (!$conn->query($sql)) {
            throw new Exception("Failed to add discount_amount column: " . $conn->error);
        }
    }
}
