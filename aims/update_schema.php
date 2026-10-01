<?php
require_once __DIR__ . '/config.php';

try {
    $mysqli->begin_transaction();

    // Create Notifications Table
    $query = "
    CREATE TABLE IF NOT EXISTS notifications (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        title       VARCHAR(150) NOT NULL,
        message     TEXT NOT NULL,
        type        VARCHAR(50) NOT NULL DEFAULT 'system',
        related_id  INT NULL,
        is_read     TINYINT(1) NOT NULL DEFAULT 0,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_notif_user
            FOREIGN KEY (user_id) REFERENCES users(id)
            ON UPDATE CASCADE ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    $mysqli->query($query);
    
    // Add index for performance
    $mysqli->query("CREATE INDEX IF NOT EXISTS idx_notif_user_read ON notifications(user_id, is_read)");

    $mysqli->commit();
    echo "Notifications table created successfully.\n";

} catch (Exception $e) {
    $mysqli->rollback();
    echo "Error: " . $e->getMessage();
}
