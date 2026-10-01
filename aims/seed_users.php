<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'disabled'], JSON_PRETTY_PRINT);
    exit;
}

$users = [
    [
        'username' => 'admin1',
        'password' => 'AdminPass123!',
        'role'     => 'admin',
        'email'    => 'admin1@example.com',
    ],
    [
        'username' => 'teacher1',
        'password' => 'TeacherPass123!',
        'role'     => 'teacher',
        'email'    => 'teacher1@example.com',
    ],
];

$results = [];

foreach ($users as $u) {
    $stmt = $mysqli->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    if (! $stmt) {
        $results[] = ['username' => $u['username'], 'status' => 'error', 'message' => 'prepare failed'];
        continue;
    }
    $stmt->bind_param('s', $u['username']);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->bind_result($existingId);
        $stmt->fetch();
        $stmt->close();
        $results[] = ['username' => $u['username'], 'status' => 'exists', 'user_id' => $existingId];
        continue;
    }
    $stmt->close();

    $passwordHash = password_hash($u['password'], PASSWORD_DEFAULT);
    $insert = $mysqli->prepare('INSERT INTO users (username, password_hash, role, email, status) VALUES (?, ?, ?, ?, ?)');
    if (! $insert) {
        $results[] = ['username' => $u['username'], 'status' => 'error', 'message' => 'insert prepare failed'];
        continue;
    }
    $status = 'active';
    $insert->bind_param('sssss', $u['username'], $passwordHash, $u['role'], $u['email'], $status);
    if (! $insert->execute()) {
        $results[] = ['username' => $u['username'], 'status' => 'error', 'message' => 'insert execute failed'];
        $insert->close();
        continue;
    }
    $newUserId = $insert->insert_id;
    $insert->close();

    if ($u['role'] === 'teacher') {
        $checkT = $mysqli->prepare('SELECT id FROM teachers WHERE user_id = ? LIMIT 1');
        if ($checkT) {
            $checkT->bind_param('i', $newUserId);
            $checkT->execute();
            $checkT->store_result();
            if ($checkT->num_rows === 0) {
                $emp = 'EMP-' . str_pad((string) $newUserId, 4, '0', STR_PAD_LEFT);
                $first = 'Alice';
                $last = 'Teacher';
                $tins = $mysqli->prepare('INSERT INTO teachers (user_id, employee_number, first_name, last_name, email, status) VALUES (?, ?, ?, ?, ?, ?)');
                if ($tins) {
                    $tstatus = 'active';
                    $tins->bind_param('isssss', $newUserId, $emp, $first, $last, $u['email'], $tstatus);
                    if (! $tins->execute()) {
                        $results[] = ['username' => $u['username'], 'status' => 'warning', 'message' => 'teacher profile insert failed', 'user_id' => $newUserId];
                    }
                    $tins->close();
                } else {
                    $results[] = ['username' => $u['username'], 'status' => 'warning', 'message' => 'teacher profile prepare failed', 'user_id' => $newUserId];
                }
            }
            $checkT->close();
        }
    }

    $results[] = ['username' => $u['username'], 'status' => 'created', 'user_id' => $newUserId];
}

header('Content-Type: application/json');
echo json_encode([
    'ok' => true,
    'results' => $results,
    'credentials' => [],
], JSON_PRETTY_PRINT);
