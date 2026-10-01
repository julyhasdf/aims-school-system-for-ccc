<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/notifications.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    mark_notifications_read((int)$_SESSION['user_id']);
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
} else {
    http_response_code(403);
    echo json_encode(['status' => 'error']);
}
