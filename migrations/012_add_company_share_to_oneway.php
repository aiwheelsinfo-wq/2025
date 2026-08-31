<?php

/**
 * Migration: 012_add_company_share_to_oneway.php
 * Safe non-destructive addition of Company Share / Platform Commission parameters:
 * - one_way_global_settings: company_share_active, company_share_type, company_share_value, company_share_basis
 * - one_way_vehicle_rules: company_share_percent
 */
return function (mysqli $conn) {
    // 1. Update one_way_global_settings table
    $tableCheck1 = mysqli_query($conn, "SHOW TABLES LIKE 'one_way_global_settings'");
    if ($tableCheck1 && mysqli_num_rows($tableCheck1) > 0) {
        $cols = [
            'company_share_active' => "ALTER TABLE `one_way_global_settings` ADD COLUMN `company_share_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Master ON/OFF for Company Share/Commission' AFTER `historical_lookback_days`",
            'company_share_type'   => "ALTER TABLE `one_way_global_settings` ADD COLUMN `company_share_type` ENUM('percentage', 'fixed') NOT NULL DEFAULT 'percentage' COMMENT 'Commission type: percentage or fixed amount' AFTER `company_share_active`",
            'company_share_value'  => "ALTER TABLE `one_way_global_settings` ADD COLUMN `company_share_value` DECIMAL(10,2) NOT NULL DEFAULT 15.00 COMMENT 'Commission value (e.g. 15% or fixed Rs)' AFTER `company_share_type`",
            'company_share_basis'  => "ALTER TABLE `one_way_global_settings` ADD COLUMN `company_share_basis` ENUM('subtotal', 'base_km') NOT NULL DEFAULT 'subtotal' COMMENT 'Basis: subtotal (pre-tax) or base_km charge' AFTER `company_share_value`"
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

    // 2. Update one_way_vehicle_rules table
    $tableCheck2 = mysqli_query($conn, "SHOW TABLES LIKE 'one_way_vehicle_rules'");
    if ($tableCheck2 && mysqli_num_rows($tableCheck2) > 0) {
        $vehCols = [
            'company_share_percent' => "ALTER TABLE `one_way_vehicle_rules` ADD COLUMN `company_share_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Optional vehicle commission override (0 = use global)' AFTER `max_rate_multiplier`"
        ];

        foreach ($vehCols as $colName => $alterSql) {
            $colCheck = mysqli_query($conn, "SHOW COLUMNS FROM `one_way_vehicle_rules` LIKE '$colName'");
            if ($colCheck && mysqli_num_rows($colCheck) === 0) {
                if (!mysqli_query($conn, $alterSql)) {
                    throw new Exception("Failed to add column $colName to one_way_vehicle_rules: " . mysqli_error($conn));
                }
            }
        }
    }
};
