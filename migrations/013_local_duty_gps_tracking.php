<?php
return function(mysqli $conn) {
    // 1. Add tracking and OTP columns to bookings table
    $alterQueries = [
        "ALTER TABLE bookings ADD COLUMN end_otp VARCHAR(10) DEFAULT NULL",
        "ALTER TABLE bookings ADD COLUMN gps_accumulated_km DECIMAL(10, 2) NOT NULL DEFAULT 0.00",
        "ALTER TABLE bookings ADD COLUMN gps_start_lat DECIMAL(10, 8) DEFAULT NULL",
        "ALTER TABLE bookings ADD COLUMN gps_start_lng DECIMAL(11, 8) DEFAULT NULL",
        "ALTER TABLE bookings ADD COLUMN gps_start_time DATETIME DEFAULT NULL",
        "ALTER TABLE bookings ADD COLUMN gps_end_lat DECIMAL(10, 8) DEFAULT NULL",
        "ALTER TABLE bookings ADD COLUMN gps_end_lng DECIMAL(11, 8) DEFAULT NULL",
        "ALTER TABLE bookings ADD COLUMN gps_end_time DATETIME DEFAULT NULL",
        "ALTER TABLE bookings ADD COLUMN tracking_mode ENUM('manual_km', 'gps_live') NOT NULL DEFAULT 'gps_live'"
    ];

    foreach ($alterQueries as $q) {
        if (!mysqli_query($conn, $q)) {
            $err = mysqli_error($conn);
            if (strpos(strtolower($err), 'duplicate column') === false) {
                error_log("Migration 013 column notice: " . $err);
            }
        }
    }

    // 2. Create trip_gps_breadcrumbs table
    $sqlBreadcrumbs = "CREATE TABLE IF NOT EXISTS trip_gps_breadcrumbs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        booking_id INT NOT NULL,
        latitude DECIMAL(10, 8) NOT NULL,
        longitude DECIMAL(11, 8) NOT NULL,
        speed_kmh DECIMAL(5, 2) DEFAULT 0.00,
        accuracy_meters DECIMAL(6, 2) DEFAULT NULL,
        recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_booking_time (booking_id, recorded_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!mysqli_query($conn, $sqlBreadcrumbs)) {
        throw new Exception("Migration 013 failed on trip_gps_breadcrumbs: " . mysqli_error($conn));
    }
};
