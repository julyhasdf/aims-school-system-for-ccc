<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

/**
 * Create a notification for a specific user
 */
function create_notification(int $userId, string $title, string $message, string $type = 'system', ?int $relatedId = null): bool {
    global $mysqli;
    $stmt = $mysqli->prepare("INSERT INTO notifications (user_id, title, message, type, related_id) VALUES (?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param('isssi', $userId, $title, $message, $type, $relatedId);
        $res = $stmt->execute();
        $stmt->close();
        return $res;
    }
    return false;
}

/**
 * Broadcast a notification to a group of users based on role
 */
function broadcast_notification(string $role, string $title, string $message, string $type = 'announcement', ?int $relatedId = null): int {
    global $mysqli;
    
    $query = "";
    if ($role === 'all') {
        $query = "SELECT id FROM users WHERE status = 'active'";
    } else {
        $role = $mysqli->real_escape_string($role);
        $query = "SELECT id FROM users WHERE role = '$role' AND status = 'active'";
    }
    
    $res = $mysqli->query($query);
    $count = 0;
    
    if ($res) {
        $stmt = $mysqli->prepare("INSERT INTO notifications (user_id, title, message, type, related_id) VALUES (?, ?, ?, ?, ?)");
        if ($stmt) {
            while ($row = $res->fetch_assoc()) {
                $uid = (int)$row['id'];
                $stmt->bind_param('isssi', $uid, $title, $message, $type, $relatedId);
                if ($stmt->execute()) {
                    $count++;
                }
            }
            $stmt->close();
        }
    }
    return $count;
}

/**
 * Get unread notifications for a user
 */
function get_unread_notifications(int $userId, int $limit = 5): array {
    global $mysqli;
    $notifs = [];
    $stmt = $mysqli->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT ?");
    if ($stmt) {
        $stmt->bind_param('ii', $userId, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $notifs[] = $row;
        }
        $stmt->close();
    }
    return $notifs;
}

/**
 * Mark all notifications as read for a user
 */
function mark_notifications_read(int $userId): bool {
    global $mysqli;
    $stmt = $mysqli->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $res = $stmt->execute();
        $stmt->close();
        return $res;
    }
    return false;
}
