<?php
header("Content-Type: text/plain");
echo "Git Status:\n";
echo shell_exec("git status 2>&1");
echo "\nGit Diff:\n";
echo shell_exec("git diff 2>&1");
?>
