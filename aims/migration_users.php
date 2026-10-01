<?php
require_once __DIR__ . '/config.php';

try {
    // Add columns to users table if they don't exist
    $check = $mysqli->query("SHOW COLUMNS FROM users LIKE 'full_name'");
    if ($check->num_rows === 0) {
        $mysqli->query("ALTER TABLE users ADD COLUMN full_name VARCHAR(100) AFTER username");
        echo "Added full_name to users.\n";
    }

    $check = $mysqli->query("SHOW COLUMNS FROM users LIKE 'contact_number'");
    if ($check->num_rows === 0) {
        $mysqli->query("ALTER TABLE users ADD COLUMN contact_number VARCHAR(50) AFTER email");
        echo "Added contact_number to users.\n";
    }

    // Sync data from students and teachers to users
    $mysqli->query("UPDATE users u JOIN students s ON u.id = s.user_id SET u.full_name = CONCAT(s.first_name, ' ', s.last_name), u.contact_number = s.contact_number WHERE u.full_name IS NULL");
    $mysqli->query("UPDATE users u JOIN teachers t ON u.id = t.user_id SET u.full_name = CONCAT(t.first_name, ' ', t.last_name), u.contact_number = t.contact_number WHERE u.full_name IS NULL");
    
    echo "Migration completed.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
