<?php
header("Content-Type: text/plain");
echo "PHP Default Timezone: " . date_default_timezone_get() . "\n";
echo "PHP Date: " . date("Y-m-d H:i:s T") . "\n";
echo "System Date: " . shell_exec("date") . "\n";
?>
