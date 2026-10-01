<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

// Only allow authenticated users
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit(json_encode([]));
}

$query = $_GET['q'] ?? '';
$results = [];

if (strlen($query) >= 2) {
    $search = "%{$query}%";
    $role = $_SESSION['role'];

    // 1. Search Students
    $stmt = $mysqli->prepare("SELECT id, first_name, last_name, student_number FROM students WHERE first_name LIKE ? OR last_name LIKE ? OR student_number LIKE ? LIMIT 5");
    if ($stmt) {
        $stmt->bind_param('sss', $search, $search, $search);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $url = ($role === 'admin') ? "../admin/students.php?id=" . $row['id'] : '#';
            $results[] = [
                'type' => 'student',
                'title' => $row['first_name'] . ' ' . $row['last_name'],
                'subtitle' => 'Student - ' . $row['student_number'],
                'url' => $url
            ];
        }
        $stmt->close();
    }

    // 2. Search Teachers
    $stmt = $mysqli->prepare("SELECT id, first_name, last_name, employee_number FROM teachers WHERE first_name LIKE ? OR last_name LIKE ? OR employee_number LIKE ? LIMIT 5");
    if ($stmt) {
        $stmt->bind_param('sss', $search, $search, $search);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $url = ($role === 'admin') ? "../admin/teachers.php?id=" . $row['id'] : '#';
            $results[] = [
                'type' => 'teacher',
                'title' => $row['first_name'] . ' ' . $row['last_name'],
                'subtitle' => 'Teacher - ' . $row['employee_number'],
                'url' => $url
            ];
        }
        $stmt->close();
    }

    // 3. Search Subjects
    $stmt = $mysqli->prepare("SELECT id, subject_code, subject_name FROM subjects WHERE subject_code LIKE ? OR subject_name LIKE ? LIMIT 5");
    if ($stmt) {
        $stmt->bind_param('ss', $search, $search);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $url = ($role === 'admin') ? "../admin/subjects.php?id=" . $row['id'] : '#';
            $results[] = [
                'type' => 'subject',
                'title' => $row['subject_code'],
                'subtitle' => $row['subject_name'],
                'url' => $url
            ];
        }
        $stmt->close();
    }
}

header('Content-Type: application/json');
echo json_encode($results);
