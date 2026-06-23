<?php
header("Content-Type: text/plain");
echo "Fetching latest from origin...\n";
echo shell_exec("git fetch origin 2>&1");
echo "Force resetting to origin/main...\n";
echo shell_exec("git reset --hard origin/main && php fix_json_key.php 2>&1");
echo "Status after reset:\n";
echo shell_exec("git log -1 --oneline 2>&1");

?>
