<?php

/**
 * Migration: 008_cancellation_and_refund_system.php
 * Creates the cancellation policy table, inserts default settings,
 * adds refund/cancellation tracking columns to bookings,
 * and sets up a trigger for driver_assigned_at tracking.
 */
return function (mysqli $conn) {
    // 1. Create table 'cancellation_policy'
    $policyTableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'cancellation_policy'");
    if (mysqli_num_rows($policyTableCheck) === 0) {
        $sql = "CREATE TABLE `cancellation_policy` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `cancellation_enabled` TINYINT DEFAULT 1,
            `free_cancellation_hours` INT DEFAULT 48,
            `refund_above_48h` DECIMAL(5,2) DEFAULT 100.00,
            `refund_24_48h` DECIMAL(5,2) DEFAULT 75.00,
            `refund_12_24h` DECIMAL(5,2) DEFAULT 50.00,
            `refund_6_12h` DECIMAL(5,2) DEFAULT 25.00,
            `refund_below_6h` DECIMAL(5,2) DEFAULT 0.00,
            `vendor_comp_above_24h` DECIMAL(5,2) DEFAULT 0.00,
            `vendor_comp_6_24h` DECIMAL(5,2) DEFAULT 50.00,
            `vendor_comp_below_6h` DECIMAL(5,2) DEFAULT 100.00,
            `auto_refund` TINYINT DEFAULT 1,
            `manual_approval` TINYINT DEFAULT 0,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        if (!mysqli_query($conn, $sql)) {
            throw new Exception("Failed to create table cancellation_policy: " . mysqli_error($conn));
        }

        // Insert initial default policy
        $insertSql = "INSERT INTO `cancellation_policy` (
            `cancellation_enabled`, `free_cancellation_hours`, 
            `refund_above_48h`, `refund_24_48h`, `refund_12_24h`, `refund_6_12h`, `refund_below_6h`,
            `vendor_comp_above_24h`, `vendor_comp_6_24h`, `vendor_comp_below_6h`,
            `auto_refund`, `manual_approval`
        ) VALUES (1, 48, 100.00, 75.00, 50.00, 25.00, 0.00, 0.00, 50.00, 100.00, 1, 0)";
        if (!mysqli_query($conn, $insertSql)) {
            throw new Exception("Failed to insert default cancellation policy: " . mysqli_error($conn));
        }
    }

    // Helper function to add column if it does not exist
    $addColumn = function (mysqli $conn, $table, $column, $definition) {
        $check = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
        if (mysqli_num_rows($check) === 0) {
            $sql = "ALTER TABLE `$table` ADD `$column` $definition";
            if (!mysqli_query($conn, $sql)) {
                throw new Exception("Failed to add column $column to $table: " . mysqli_error($conn));
            }
        }
    };

    // 2. Add cancellation tracking columns to bookings table
    $addColumn($conn, 'bookings', 'driver_assigned_at', 'DATETIME DEFAULT NULL');
    $addColumn($conn, 'bookings', 'cancellation_reason', 'VARCHAR(255) DEFAULT NULL');
    $addColumn($conn, 'bookings', 'cancelled_at', 'DATETIME DEFAULT NULL');
    $addColumn($conn, 'bookings', 'cancellation_charge', 'DECIMAL(10, 2) DEFAULT 0.00');
    $addColumn($conn, 'bookings', 'refund_amount', 'DECIMAL(10, 2) DEFAULT 0.00');
    $addColumn($conn, 'bookings', 'refund_status', "VARCHAR(50) DEFAULT NULL");
    $addColumn($conn, 'bookings', 'vendor_compensation', 'DECIMAL(10, 2) DEFAULT 0.00');
    $addColumn($conn, 'bookings', 'vendor_compensation_status', "VARCHAR(50) DEFAULT NULL");

    // 3. Create Trigger to set driver_assigned_at when driver_id is assigned
    mysqli_query($conn, "DROP TRIGGER IF EXISTS `trg_bookings_driver_assigned`");
    $triggerSql = "CREATE TRIGGER `trg_bookings_driver_assigned`
        BEFORE UPDATE ON bookings
        FOR EACH ROW
        BEGIN
            IF NEW.driver_id IS NOT NULL AND NEW.driver_id != '' AND (OLD.driver_id IS NULL OR OLD.driver_id = '') THEN
                SET NEW.driver_assigned_at = NOW();
            END IF;
        END;";
    if (!mysqli_query($conn, $triggerSql)) {
        // Fallback: If DB permissions don't allow triggers, log warning but don't crash
        error_log("WARNING: Could not create MySQL trigger for driver_assigned_at. DB user may lack TRIGGER privilege.");
    }
};
