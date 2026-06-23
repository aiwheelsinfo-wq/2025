<?php
header("Content-Type: text/plain");
echo "Discarding local changes to credentials JSON...\n";
echo shell_exec("git checkout -- agni-car-app-firebase-adminsdk-fbsvc-4f70f7d1f2.json 2>&1");
echo "Pulling latest main branch...\n";
echo shell_exec("git pull origin main 2>&1");
echo "New MD5 hash: " . md5_file('./agni-car-app-firebase-adminsdk-fbsvc-4f70f7d1f2.json') . "\n";
?>
