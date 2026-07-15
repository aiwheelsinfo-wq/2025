<?php
// Centralized Razorpay Configuration
define('RAZORPAY_USE_LIVE', false); // Set to true to switch the entire application to live mode

// Credentials
define('RAZORPAY_TEST_KEY', 'rzp_test_GIqSfPJk12gAgz');
define('RAZORPAY_LIVE_KEY', 'rzp_live_q9eMvidQ7LrwVQ');

define('RAZORPAY_ACTIVE_KEY', RAZORPAY_USE_LIVE ? RAZORPAY_LIVE_KEY : RAZORPAY_TEST_KEY);
?>
