<?php

/**
 * Migration: 007_fix_payment_settlement_columns.php
 * Safe recovery/addition of settlement columns to bookings table.
 */
return function (mysqli $conn) {
    // Check if settlement_status column already exists in bookings table
    $columnCheck = mysqli_query($conn, "SHOW COLUMNS FROM `bookings` LIKE 'settlement_status'");
    if (mysqli_num_rows($columnCheck) === 0) {
        $sql = "ALTER TABLE `bookings` 
                ADD COLUMN `settlement_status` VARCHAR(50) NOT NULL DEFAULT 'Pending',
                ADD COLUMN `settlement_date` DATETIME NULL,
                ADD COLUMN `transaction_reference` VARCHAR(255) NULL";
        
        if (!mysqli_query($conn, $sql)) {
            throw new Exception("Failed to add settlement columns to bookings table: " . mysqli_error($conn));
        }
    }
};
