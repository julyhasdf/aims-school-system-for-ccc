<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (is_logged_in()) {
    switch ($_SESSION['role']) {
        case 'admin':
            header('Location: admin/dashboard.php');
            break;
        case 'teacher':
            header('Location: teacher/dashboard.php');
            break;
        case 'student':
            header('Location: student/dashboard.php');
            break;
        default:
            header('Location: logout.php');
            break;
    }
    exit;
}

header('Location: login.php');
exit;

