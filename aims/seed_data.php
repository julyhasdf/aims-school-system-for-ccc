<?php
require_once __DIR__ . '/config.php';

try {
    $mysqli->begin_transaction();

    // 1. Clear existing data (optional, but good for clean state)
    // Be careful with this in production!
    $mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
    $tables = ['users', 'students', 'teachers', 'subjects', 'sections', 'school_years', 'teacher_subjects', 'schedules', 'enrollments', 'grades', 'attendance', 'grading_periods'];
    foreach ($tables as $table) {
        $mysqli->query("TRUNCATE TABLE $table");
    }
    $mysqli->query("SET FOREIGN_KEY_CHECKS = 1");

    echo "Cleared existing data.\n";

    // 2. Create School Year
    $mysqli->query("INSERT INTO school_years (name, is_current, start_date, end_date) VALUES ('2025-2026', 1, '2025-08-01', '2026-05-31')");
    $syId = $mysqli->insert_id;
    echo "Created School Year: 2025-2026\n";

    // 3. Create Grading Periods
    $periods = [
        ['1st Quarter', '2025-08-01', '2025-10-15'],
        ['2nd Quarter', '2025-10-16', '2025-12-20'],
        ['3rd Quarter', '2026-01-05', '2026-03-15'],
        ['4th Quarter', '2026-03-16', '2026-05-31']
    ];
    $periodIds = [];
    $stmt = $mysqli->prepare("INSERT INTO grading_periods (name, school_year_id, start_date, end_date) VALUES (?, ?, ?, ?)");
    foreach ($periods as $p) {
        $stmt->bind_param('siss', $p[0], $syId, $p[1], $p[2]);
        $stmt->execute();
        $periodIds[] = $stmt->insert_id;
    }
    $stmt->close();
    echo "Created 4 Grading Periods.\n";

    // 4. Create Admin User
    $adminPass = password_hash('admin123', PASSWORD_DEFAULT);
    $mysqli->query("INSERT INTO users (username, password_hash, role, email, full_name, status) VALUES ('admin', '$adminPass', 'admin', 'admin@aims.edu', 'System Administrator', 'active')");
    echo "Created Admin: admin / admin123\n";

    // 5. Create Teachers
    $teachersData = [
        ['T001', 'John', 'Doe', 'Science', 'Physics', 'john.doe@aims.edu'],
        ['T002', 'Jane', 'Smith', 'Math', 'Algebra', 'jane.smith@aims.edu'],
        ['T003', 'Robert', 'Brown', 'English', 'Literature', 'robert.brown@aims.edu'],
        ['T004', 'Emily', 'Davis', 'History', 'World History', 'emily.davis@aims.edu']
    ];
    
    $teacherIds = [];
    $stmtUser = $mysqli->prepare("INSERT INTO users (username, password_hash, role, email, full_name) VALUES (?, ?, 'teacher', ?, ?)");
    $stmtTeacher = $mysqli->prepare("INSERT INTO teachers (user_id, employee_number, first_name, last_name, department, specialization, email) VALUES (?, ?, ?, ?, ?, ?, ?)");

    foreach ($teachersData as $t) {
        $pass = password_hash($t[0], PASSWORD_DEFAULT); // Password is Emp ID
        $fullname = $t[1] . ' ' . $t[2];
        
        $stmtUser->bind_param('ssss', $t[0], $pass, $t[5], $fullname);
        $stmtUser->execute();
        $uid = $stmtUser->insert_id;

        $stmtTeacher->bind_param('issssss', $uid, $t[0], $t[1], $t[2], $t[3], $t[4], $t[5]);
        $stmtTeacher->execute();
        $teacherIds[] = $stmtTeacher->insert_id;
    }
    $stmtUser->close();
    $stmtTeacher->close();
    echo "Created " . count($teachersData) . " Teachers.\n";

    // 6. Create Sections
    // Assign T001 and T002 as advisers
    $sectionsData = [
        ['Grade 10 - Einstein', 'Grade 10', $teacherIds[0]],
        ['Grade 9 - Newton', 'Grade 9', $teacherIds[1]],
        ['Grade 8 - Darwin', 'Grade 8', null]
    ];
    $sectionIds = [];
    $stmtSec = $mysqli->prepare("INSERT INTO sections (section_name, grade_level, adviser_id, school_year_id) VALUES (?, ?, ?, ?)");
    foreach ($sectionsData as $s) {
        $stmtSec->bind_param('ssii', $s[0], $s[1], $s[2], $syId);
        $stmtSec->execute();
        $sectionIds[] = $stmtSec->insert_id;
    }
    $stmtSec->close();
    echo "Created " . count($sectionsData) . " Sections.\n";

    // 7. Create Subjects
    $subjectsData = [
        ['MATH101', 'Algebra I', 1.0, 'Grade 9'],
        ['SCI101', 'General Science', 1.0, 'Grade 9'],
        ['ENG101', 'English Literature', 1.0, 'Grade 9'],
        ['MATH201', 'Geometry', 1.0, 'Grade 10'],
        ['SCI201', 'Physics', 1.0, 'Grade 10'],
        ['HIST101', 'World History', 1.0, 'Grade 10']
    ];
    $subjectIds = []; // Map code -> id
    $stmtSub = $mysqli->prepare("INSERT INTO subjects (subject_code, subject_name, units, grade_level) VALUES (?, ?, ?, ?)");
    foreach ($subjectsData as $s) {
        $stmtSub->bind_param('ssds', $s[0], $s[1], $s[2], $s[3]);
        $stmtSub->execute();
        $subjectIds[$s[0]] = $stmtSub->insert_id;
    }
    $stmtSub->close();
    echo "Created " . count($subjectsData) . " Subjects.\n";

    // 8. Create Students
    // Create 10 students for Grade 10 - Einstein ($sectionIds[0])
    $stmtStdUser = $mysqli->prepare("INSERT INTO users (username, password_hash, role, email, full_name) VALUES (?, ?, 'student', ?, ?)");
    $stmtStd = $mysqli->prepare("INSERT INTO students (user_id, student_number, first_name, last_name, grade_level, section_id) VALUES (?, ?, ?, ?, ?, ?)");

    for ($i = 1; $i <= 10; $i++) {
        $stdNum = 'S2025-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
        $fname = 'Student' . $i;
        $lname = 'Test';
        $fullname = "$fname $lname";
        $email = strtolower("student$i@aims.edu");
        $pass = password_hash($stdNum, PASSWORD_DEFAULT);

        $stmtStdUser->bind_param('ssss', $stdNum, $pass, $email, $fullname);
        $stmtStdUser->execute();
        $uid = $stmtStdUser->insert_id;

        $grade = 'Grade 10';
        $secId = $sectionIds[0];

        $stmtStd->bind_param('issssi', $uid, $stdNum, $fname, $lname, $grade, $secId);
        $stmtStd->execute();
    }
    $stmtStdUser->close();
    $stmtStd->close();
    echo "Created 10 Students in Grade 10 - Einstein.\n";

    // 9. Assign Subjects to Teachers & Sections (Class Load)
    // Grade 10 - Einstein gets: Math (T002), Physics (T001), History (T004)
    $loads = [
        [$teacherIds[1], $subjectIds['MATH201'], $sectionIds[0]], // Jane Smith teaches Geometry to G10
        [$teacherIds[0], $subjectIds['SCI201'], $sectionIds[0]],  // John Doe teaches Physics to G10
        [$teacherIds[3], $subjectIds['HIST101'], $sectionIds[0]]  // Emily teaches History to G10
    ];
    $tsIds = [];
    $stmtLoad = $mysqli->prepare("INSERT INTO teacher_subjects (teacher_id, subject_id, section_id, school_year_id) VALUES (?, ?, ?, ?)");
    foreach ($loads as $l) {
        $stmtLoad->bind_param('iiii', $l[0], $l[1], $l[2], $syId);
        $stmtLoad->execute();
        $tsIds[] = $stmtLoad->insert_id;
    }
    $stmtLoad->close();
    echo "Assigned 3 Subjects to Grade 10 - Einstein.\n";

    // 10. Create Schedules
    // Math: Mon/Wed 8-9am, Physics: Tue/Thu 8-9am, History: Fri 9-10am
    $schedulesData = [
        [$tsIds[0], 'Monday', '08:00:00', '09:00:00', 'Room 101'],
        [$tsIds[0], 'Wednesday', '08:00:00', '09:00:00', 'Room 101'],
        [$tsIds[1], 'Tuesday', '08:00:00', '09:00:00', 'Lab 1'],
        [$tsIds[1], 'Thursday', '08:00:00', '09:00:00', 'Lab 1'],
        [$tsIds[2], 'Friday', '09:00:00', '10:00:00', 'Room 102']
    ];
    $stmtSched = $mysqli->prepare("INSERT INTO schedules (teacher_subject_id, day_of_week, start_time, end_time, room) VALUES (?, ?, ?, ?, ?)");
    foreach ($schedulesData as $s) {
        $stmtSched->bind_param('issss', $s[0], $s[1], $s[2], $s[3], $s[4]);
        $stmtSched->execute();
    }
    $stmtSched->close();
    echo "Created Class Schedules.\n";

    // 11. Seed Attendance (Random)
    // For today and yesterday
    $dates = [date('Y-m-d', strtotime('-1 day')), date('Y-m-d')];
    // Get students in G10
    $resStd = $mysqli->query("SELECT id FROM students WHERE section_id = " . $sectionIds[0]);
    $g10Students = [];
    while ($row = $resStd->fetch_assoc()) $g10Students[] = $row['id'];

    $stmtAtt = $mysqli->prepare("INSERT INTO attendance (student_id, teacher_subject_id, attendance_date, status, recorded_by) VALUES (?, ?, ?, ?, ?)");
    $statuses = ['Present', 'Present', 'Present', 'Absent', 'Late'];
    
    foreach ($dates as $date) {
        foreach ($g10Students as $sid) {
            // For Math Class (tsIds[0]) - Teacher is T002 (teacherIds[1])
            // Need user_id of T002
            $resU = $mysqli->query("SELECT user_id FROM teachers WHERE id = " . $teacherIds[1]);
            $recBy = $resU->fetch_assoc()['user_id'];
            
            $status = $statuses[array_rand($statuses)];
            $stmtAtt->bind_param('iissi', $sid, $tsIds[0], $date, $status, $recBy);
            $stmtAtt->execute();
        }
    }
    $stmtAtt->close();
    echo "Seeded Attendance records.\n";

    // 12. Seed Grades (1st Quarter)
    // For Math Class
    $stmtG = $mysqli->prepare("INSERT INTO grades (student_id, teacher_subject_id, grading_period_id, grade, encoded_by) VALUES (?, ?, ?, ?, ?)");
    // T002 user id
    $resU = $mysqli->query("SELECT user_id FROM teachers WHERE id = " . $teacherIds[1]);
    $recBy = $resU->fetch_assoc()['user_id'];

    foreach ($g10Students as $sid) {
        $grade = rand(70, 98) + (rand(0, 99) / 100);
        $stmtG->bind_param('iiidi', $sid, $tsIds[0], $periodIds[0], $grade, $recBy);
        $stmtG->execute();
    }
    $stmtG->close();
    echo "Seeded Grades for 1st Quarter.\n";

    $mysqli->commit();
    echo "\n------------------------------------------------\n";
    echo "DATABASE SEEDING COMPLETED SUCCESSFULLY!\n";
    echo "------------------------------------------------\n";
    echo "Login Credentials:\n";
    echo "Admin:   admin / admin123\n";
    echo "Teacher: T001 / T001 (John Doe)\n";
    echo "Teacher: T002 / T002 (Jane Smith)\n";
    echo "Student: S2025-001 / S2025-001 (Student1 Test)\n";

} catch (Exception $e) {
    $mysqli->rollback();
    echo "Error: " . $e->getMessage();
}
