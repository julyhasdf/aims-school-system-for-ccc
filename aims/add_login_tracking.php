<?php
require_once 'config.php';
$sql = "ALTER TABLE users ADD COLUMN login_attempts INT DEFAULT 0, ADD COLUMN last_attempt_time DATETIME DEFAULT NULL";
if ($mysqli->query($sql)) {
    echo "Columns added successfully.";
} else {
    echo "Error adding columns: " . $mysqli->error;
}
?>
