<?php

/**
 * Migration: 014_add_advance_payment_to_oneway.php
 * Safe non-destructive addition of Customer Advance Payment parameters:
 * - one_way_global_settings: advance_payment_active, advance_payment_type, advance_payment_value
 * Default is strictly advance_payment_active = 0 (Mode 1: Driver Wallet / 0% Advance remains default).
 */
return function (mysqli $conn) {
    // 1. Update one_way_global_settings table
    $tableCheck1 = mysqli_query($conn, "SHOW TABLES LIKE 'one_way_global_settings'");
    if ($tableCheck1 && mysqli_num_rows($tableCheck1) > 0) {
        $cols = [
            'advance_payment_active' => "ALTER TABLE `one_way_global_settings` ADD COLUMN `advance_payment_active` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Master ON/OFF for Customer Advance Payment (Mode 2)' AFTER `company_share_basis`",
            'advance_payment_type'   => "ALTER TABLE `one_way_global_settings` ADD COLUMN `advance_payment_type` ENUM('percentage', 'fixed') NOT NULL DEFAULT 'percentage' COMMENT 'Advance calculation type: percentage or fixed' AFTER `advance_payment_active`",
            'advance_payment_value'  => "ALTER TABLE `one_way_global_settings` ADD COLUMN `advance_payment_value` DECIMAL(10,2) NOT NULL DEFAULT 10.00 COMMENT 'Advance percentage (e.g. 10%) or fixed Rs' AFTER `advance_payment_type`"
        ];

        foreach ($cols as $colName => $alterSql) {
            $colCheck = mysqli_query($conn, "SHOW COLUMNS FROM `one_way_global_settings` LIKE '$colName'");
            if ($colCheck && mysqli_num_rows($colCheck) === 0) {
                if (!mysqli_query($conn, $alterSql)) {
                    throw new Exception("Failed to add column $colName to one_way_global_settings: " . mysqli_error($conn));
                }
            }
        }
    }
};
