<?php
require_once 'config.php';
$tables = ['students', 'teachers', 'sections', 'teacher_subjects', 'schedules', 'grades', 'users'];
foreach ($tables as $table) {
    echo "--- $table ---\n";
    $res = $mysqli->query("DESCRIBE $table");
    while ($row = $res->fetch_assoc()) {
        echo "{$row['Field']} - {$row['Type']}\n";
    }
    echo "\n";
}
