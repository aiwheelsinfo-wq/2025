<?php
return function(mysqli $conn) {
    // Add bank account details and razorpay fund account/contact ids to users table
    $sql = "ALTER TABLE users 
            ADD COLUMN bank_account_no VARCHAR(100) DEFAULT NULL,
            ADD COLUMN bank_ifsc VARCHAR(20) DEFAULT NULL,
            ADD COLUMN bank_holder_name VARCHAR(255) DEFAULT NULL,
            ADD COLUMN upi_id VARCHAR(100) DEFAULT NULL,
            ADD COLUMN razorpay_fund_account_id VARCHAR(100) DEFAULT NULL,
            ADD COLUMN razorpay_contact_id VARCHAR(100) DEFAULT NULL";
    
    if (!mysqli_query($conn, $sql)) {
        // If columns already exist (e.g. from manual schema alterations), catch the error to prevent breaking migrations
        $err = mysqli_error($conn);
        if (strpos(strtolower($err), 'duplicate column') === false) {
            throw new Exception("Migration failed: " . $err);
        }
    }
};
