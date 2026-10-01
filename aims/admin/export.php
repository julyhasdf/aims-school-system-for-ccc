<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_role('admin');

/**
 * Helper to clean data for CSV
 */
function clean_data(string $data): string
{
    $data = str_replace('"', '""', $data);
    if (str_contains($data, ',') || str_contains($data, '"') || str_contains($data, "\n")) {
        return '"' . $data . '"';
    }
    return $data;
}

$type = $_GET['type'] ?? '';

if (empty($type)) {
    header("Location: reports.php");
    exit;
}

$filename = "aims_report_" . $type . "_" . date('Y-m-d_H-i-s') . ".csv";

// Start CSV Output
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');

// Add UTF-8 BOM for Excel
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

switch ($type) {
    case 'students':
        fputcsv($output, ['ID', 'Student Number', 'Full Name', 'Grade Level', 'Section', 'Email', 'Contact', 'Created At']);
        $res = $mysqli->query("
            SELECT s.id, s.student_number, u.full_name, s.grade_level, sec.section_name, u.email, s.contact_number, u.created_at
            FROM students s
            JOIN users u ON s.user_id = u.id
            LEFT JOIN sections sec ON s.section_id = sec.id
            ORDER BY s.grade_level ASC, sec.section_name ASC, u.full_name ASC
        ");
        while ($row = $res->fetch_assoc()) {
            fputcsv($output, [
                $row['id'],
                $row['student_number'],
                $row['full_name'],
                $row['grade_level'],
                $row['section_name'] ?? 'N/A',
                $row['email'],
                $row['contact_number'],
                $row['created_at']
            ]);
        }
        break;

    case 'teachers':
        fputcsv($output, ['ID', 'Employee Number', 'Full Name', 'Department', 'Email', 'Contact', 'Created At']);
        $res = $mysqli->query("
            SELECT t.id, t.employee_number, u.full_name, t.department, u.email, t.contact_number, u.created_at
            FROM teachers t
            JOIN users u ON t.user_id = u.id
            ORDER BY u.full_name ASC
        ");
        while ($row = $res->fetch_assoc()) {
            fputcsv($output, [
                $row['id'],
                $row['employee_number'],
                $row['full_name'],
                $row['department'] ?? 'N/A',
                $row['email'],
                $row['contact_number'],
                $row['created_at']
            ]);
        }
        break;

    case 'sections':
        fputcsv($output, ['Section ID', 'Section Name', 'Grade Level', 'Adviser', 'Subject', 'Teacher', 'Schedule (Day)', 'Start Time', 'End Time', 'Room']);
        $res = $mysqli->query("
            SELECT 
                sec.id as section_id,
                sec.section_name,
                sec.grade_level,
                adv.full_name as adviser_name,
                sub.subject_name,
                tch_u.full_name as teacher_name,
                sch.day_of_week,
                sch.start_time,
                sch.end_time,
                sch.room
            FROM sections sec
            LEFT JOIN teachers t_adv ON sec.adviser_id = t_adv.id
            LEFT JOIN users adv ON t_adv.user_id = adv.id
            LEFT JOIN teacher_subjects ts ON sec.id = ts.section_id
            LEFT JOIN subjects sub ON ts.subject_id = sub.id
            LEFT JOIN teachers tch ON ts.teacher_id = tch.id
            LEFT JOIN users tch_u ON tch.user_id = tch_u.id
            LEFT JOIN schedules sch ON ts.id = sch.teacher_subject_id
            ORDER BY sec.grade_level ASC, sec.section_name ASC
        ");
        while ($row = $res->fetch_assoc()) {
            fputcsv($output, [
                $row['section_id'],
                $row['section_name'],
                $row['grade_level'],
                $row['adviser_name'] ?? 'None',
                $row['subject_name'] ?? 'N/A',
                $row['teacher_name'] ?? 'N/A',
                $row['day_of_week'] ?? 'N/A',
                $row['start_time'] ?? 'N/A',
                $row['end_time'] ?? 'N/A',
                $row['room'] ?? 'N/A'
            ]);
        }
        break;

    case 'grades':
        fputcsv($output, ['Grade ID', 'Student Name', 'Student ID', 'Subject', 'Grade', 'Teacher', 'Date Recorded']);
        $res = $mysqli->query("
            SELECT 
                g.id,
                u.full_name as student_name,
                s.student_number,
                sub.subject_name,
                g.grade,
                tch_u.full_name as teacher_name,
                g.created_at
            FROM grades g
            JOIN students s ON g.student_id = s.id
            JOIN users u ON s.user_id = u.id
            JOIN teacher_subjects ts ON g.teacher_subject_id = ts.id
            JOIN subjects sub ON ts.subject_id = sub.id
            JOIN teachers tch ON ts.teacher_id = tch.id
            JOIN users tch_u ON tch.user_id = tch_u.id
            ORDER BY u.full_name ASC, sub.subject_name ASC
        ");
        while ($row = $res->fetch_assoc()) {
            fputcsv($output, [
                $row['id'],
                $row['student_name'],
                $row['student_number'],
                $row['subject_name'],
                $row['grade'],
                $row['teacher_name'],
                $row['created_at']
            ]);
        }
        break;

    case 'subjects':
        fputcsv($output, ['ID', 'Subject Code', 'Subject Name', 'Description', 'Units', 'Grade Level']);
        $res = $mysqli->query("SELECT * FROM subjects ORDER BY subject_code ASC");
        while ($row = $res->fetch_assoc()) {
            fputcsv($output, [
                $row['id'],
                $row['subject_code'],
                $row['subject_name'],
                $row['description'] ?? '',
                $row['units'],
                $row['grade_level'] ?? 'N/A'
            ]);
        }
        break;

    case 'users':
        fputcsv($output, ['ID', 'Full Name', 'Username', 'Role', 'Email', 'Contact Number', 'Created At']);
        $res = $mysqli->query("SELECT id, full_name, username, role, email, contact_number, created_at FROM users ORDER BY created_at DESC");
        while ($row = $res->fetch_assoc()) {
            fputcsv($output, [
                $row['id'],
                $row['full_name'],
                $row['username'],
                $row['role'],
                $row['email'],
                $row['contact_number'],
                $row['created_at']
            ]);
        }
        break;

    default:
        die("Invalid report type.");
}

fclose($output);
exit;
