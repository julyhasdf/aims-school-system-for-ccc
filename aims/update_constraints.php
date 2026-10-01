<?php
require_once __DIR__ . '/config.php';

try {
    $mysqli->begin_transaction();

    // 1. Drop existing foreign keys that are ON DELETE SET NULL or RESTRICT
    // Note: We need to know the exact constraint names. Assuming standard naming from schema or auto-generated.
    // Since we control the schema, we know: fk_students_user, fk_teachers_user
    
    // Check if constraints exist before dropping to avoid errors (or just try/catch)
    // MySQL doesn't support IF EXISTS for DROP FOREIGN KEY directly in all versions nicely without dynamic SQL.
    // But we can just try to drop them.

    // Drop fk_students_user
    try {
        $mysqli->query("ALTER TABLE students DROP FOREIGN KEY fk_students_user");
    } catch (Exception $e) { /* Ignore if not exists */ }

    // Drop fk_teachers_user
    try {
        $mysqli->query("ALTER TABLE teachers DROP FOREIGN KEY fk_teachers_user");
    } catch (Exception $e) { /* Ignore if not exists */ }

    // Drop fk_notif_user (if it exists from previous steps)
    try {
        $mysqli->query("ALTER TABLE notifications DROP FOREIGN KEY fk_notif_user");
    } catch (Exception $e) { /* Ignore if not exists */ }


    // 2. Re-add constraints with ON DELETE CASCADE
    
    // Students
    $mysqli->query("ALTER TABLE students ADD CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE");
    
    // Teachers
    $mysqli->query("ALTER TABLE teachers ADD CONSTRAINT fk_teachers_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE");

    // Notifications (ensure it is also cascade)
    $mysqli->query("ALTER TABLE notifications ADD CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE");


    $mysqli->commit();
    echo "Successfully updated database constraints to ON DELETE CASCADE for users.\n";

} catch (Exception $e) {
    $mysqli->rollback();
    echo "Error updating constraints: " . $e->getMessage();
}
