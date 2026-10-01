<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'aims_db';

header('Content-Type: text/html; charset=utf-8');
echo "<h2>AIMS — Account Reset</h2><hr>";

$mysqli = new mysqli($db_host, $db_user, $db_pass);
if ($mysqli->connect_error) {
    die('<p style="color:red">❌ DB conn failed: ' . htmlspecialchars($mysqli->connect_error) . '</p>');
}
$mysqli->query("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
if ($mysqli->error) { die('❌ CREATE DB: ' . htmlspecialchars($mysqli->error)); }
$mysqli->select_db($db_name);
$mysqli->set_charset('utf8mb4');
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');

echo "<h3>Step 1/4: Drop any existing tables (clean slate)</h3>";
$dropTables = [
    'attendance','grades','schedules','enrollments','teacher_subjects',
    'notifications','announcements','password_resets',
    'students','teachers','sections','grading_periods',
    'subjects','school_years','users',
];
foreach ($dropTables as $t) {
    $mysqli->query("DROP TABLE IF EXISTS `$t`");
}
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
echo "<p>✅ Cleaned all tables</p><hr>";

echo "<h3>Step 2/4: Re-create all tables (correct FK order)</h3>";

$ddl = [];

$ddl[] = "CREATE TABLE users (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    username       VARCHAR(50) NOT NULL UNIQUE,
    full_name      VARCHAR(100),
    password_hash  VARCHAR(255) NOT NULL,
    role           ENUM('admin','teacher','student') NOT NULL,
    email          VARCHAR(100),
    contact_number VARCHAR(50),
    status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$ddl[] = "CREATE TABLE school_years (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(20) NOT NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 0,
    start_date DATE,
    end_date   DATE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$ddl[] = "CREATE TABLE subjects (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    subject_code VARCHAR(50) NOT NULL UNIQUE,
    subject_name VARCHAR(150) NOT NULL,
    description  TEXT,
    units        DECIMAL(4,2) DEFAULT 0,
    grade_level  VARCHAR(20),
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$ddl[] = "CREATE TABLE teachers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNIQUE,
    employee_number VARCHAR(50) NOT NULL UNIQUE,
    first_name      VARCHAR(100) NOT NULL,
    middle_name     VARCHAR(100),
    last_name       VARCHAR(100) NOT NULL,
    gender          ENUM('Male','Female','Other') DEFAULT NULL,
    date_of_birth   DATE,
    address         VARCHAR(255),
    contact_number  VARCHAR(50),
    email           VARCHAR(100),
    department      VARCHAR(100),
    specialization  VARCHAR(100),
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_teachers_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$ddl[] = "CREATE TABLE sections (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    section_name   VARCHAR(100) NOT NULL,
    grade_level    VARCHAR(20) NOT NULL,
    adviser_id     INT NULL,
    school_year_id INT NOT NULL,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sections_school_year FOREIGN KEY (school_year_id) REFERENCES school_years(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_sections_adviser FOREIGN KEY (adviser_id) REFERENCES teachers(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$ddl[] = "CREATE TABLE students (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    user_id           INT UNIQUE,
    student_number    VARCHAR(50) NOT NULL UNIQUE,
    first_name        VARCHAR(100) NOT NULL,
    middle_name       VARCHAR(100),
    last_name         VARCHAR(100) NOT NULL,
    gender            ENUM('Male','Female','Other') DEFAULT NULL,
    date_of_birth     DATE,
    address           VARCHAR(255),
    contact_number    VARCHAR(50),
    guardian_name     VARCHAR(150),
    guardian_contact  VARCHAR(50),
    grade_level       VARCHAR(20) NOT NULL,
    section_id        INT NULL,
    enrollment_status ENUM('enrolled','graduated','transferred','dropped') NOT NULL DEFAULT 'enrolled',
    email             VARCHAR(100),
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_students_section FOREIGN KEY (section_id) REFERENCES sections(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$ddl[] = "CREATE TABLE teacher_subjects (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id     INT NOT NULL,
    subject_id     INT NOT NULL,
    section_id     INT NOT NULL,
    school_year_id INT NOT NULL,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ts_teacher    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_ts_subject    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_ts_section    FOREIGN KEY (section_id) REFERENCES sections(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_ts_sy         FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    UNIQUE KEY uq_ts_unique_load (teacher_id, subject_id, section_id, school_year_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$ddl[] = "CREATE TABLE schedules (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    teacher_subject_id INT NOT NULL,
    day_of_week        ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
    start_time         TIME NOT NULL,
    end_time           TIME NOT NULL,
    room               VARCHAR(50),
    created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_schedules_ts FOREIGN KEY (teacher_subject_id) REFERENCES teacher_subjects(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$ddl[] = "CREATE TABLE enrollments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    student_id      INT NOT NULL,
    section_id      INT NOT NULL,
    school_year_id  INT NOT NULL,
    enrollment_date DATE,
    status          ENUM('enrolled','dropped','completed') NOT NULL DEFAULT 'enrolled',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_enroll_student FOREIGN KEY (student_id) REFERENCES students(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_enroll_section FOREIGN KEY (section_id) REFERENCES sections(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_enroll_sy      FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    UNIQUE KEY uq_enroll_unique (student_id, section_id, school_year_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$ddl[] = "CREATE TABLE grading_periods (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(50) NOT NULL,
    school_year_id INT NOT NULL,
    start_date     DATE,
    end_date       DATE,
    weight         DECIMAL(5,2) DEFAULT 0,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_gp_sy FOREIGN KEY (school_year_id) REFERENCES school_years(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$ddl[] = "CREATE TABLE grades (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    student_id         INT NOT NULL,
    teacher_subject_id INT NOT NULL,
    grading_period_id  INT NOT NULL,
    grade              DECIMAL(5,2) NOT NULL,
    remarks            VARCHAR(50),
    date_encoded       DATETIME DEFAULT CURRENT_TIMESTAMP,
    encoded_by         INT NOT NULL,
    created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_grades_student   FOREIGN KEY (student_id)         REFERENCES students(id)         ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_grades_ts        FOREIGN KEY (teacher_subject_id) REFERENCES teacher_subjects(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_grades_gp        FOREIGN KEY (grading_period_id)  REFERENCES grading_periods(id)  ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_grades_encoded   FOREIGN KEY (encoded_by)         REFERENCES users(id)            ON UPDATE CASCADE ON DELETE RESTRICT,
    UNIQUE KEY uq_grade_unique (student_id, teacher_subject_id, grading_period_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$ddl[] = "CREATE TABLE attendance (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    student_id         INT NOT NULL,
    teacher_subject_id INT NOT NULL,
    attendance_date    DATE NOT NULL,
    status             ENUM('Present','Absent','Late','Excused') NOT NULL,
    remarks            VARCHAR(255),
    recorded_by        INT NOT NULL,
    created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_att_student  FOREIGN KEY (student_id)         REFERENCES students(id)         ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_att_ts       FOREIGN KEY (teacher_subject_id) REFERENCES teacher_subjects(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_att_recorded FOREIGN KEY (recorded_by)        REFERENCES users(id)            ON UPDATE CASCADE ON DELETE RESTRICT,
    UNIQUE KEY uq_att_unique (student_id, teacher_subject_id, attendance_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$ddl[] = "CREATE TABLE announcements (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(150) NOT NULL,
    message     TEXT NOT NULL,
    target_role ENUM('all','students','teachers') NOT NULL DEFAULT 'all',
    created_by  INT NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME NULL,
    CONSTRAINT fk_ann_created FOREIGN KEY (created_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$ddl[] = "CREATE TABLE notifications (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    title      VARCHAR(150) NOT NULL,
    message    TEXT NOT NULL,
    type       VARCHAR(50) NOT NULL DEFAULT 'system',
    related_id INT NULL,
    is_read    TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$ddl[] = "CREATE TABLE password_resets (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    token      VARCHAR(100) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$idx = [];
$idx[] = "CREATE INDEX idx_students_section   ON students(section_id)";
$idx[] = "CREATE INDEX idx_students_grade     ON students(grade_level)";
$idx[] = "CREATE INDEX idx_ts_teacher         ON teacher_subjects(teacher_id)";
$idx[] = "CREATE INDEX idx_ts_section         ON teacher_subjects(section_id)";
$idx[] = "CREATE INDEX idx_grades_student     ON grades(student_id)";
$idx[] = "CREATE INDEX idx_grades_ts          ON grades(teacher_subject_id)";
$idx[] = "CREATE INDEX idx_att_student_date   ON attendance(student_id, attendance_date)";
$idx[] = "CREATE INDEX idx_notif_user_read    ON notifications(user_id, is_read)";

$ok = 0;
foreach ($ddl as $i => $sql) {
    if ($mysqli->query($sql)) {
        $ok++;
    } else {
        echo "<p style='color:red'>❌ DDL #" . ($i+1) . " failed: " . htmlspecialchars($mysqli->error) . "</p>";
        echo "<p><small>" . htmlspecialchars(substr($sql, 0, 120)) . "...</small></p>";
    }
}
echo "<p>✅ Created $ok / " . count($ddl) . " tables</p>";

$idxOk = 0;
foreach ($idx as $i => $sql) {
    if ($mysqli->query($sql)) { $idxOk++; }
}
echo "<p>✅ Created $idxOk / " . count($idx) . " indexes</p><hr>";

echo "<h3>Step 3/4: Insert school year and grading periods</h3>";

$mysqli->query("INSERT INTO school_years (name, is_current, start_date, end_date)
                VALUES ('2025-2026', 1, '2025-08-01', '2026-05-31')");
if ($mysqli->error) { die('❌ school_years insert: ' . htmlspecialchars($mysqli->error)); }
$syId = (int) $mysqli->insert_id;
echo "<p>✅ SY 2025-2026 (id=$syId)</p>";

$periods = [
    ['1st Quarter', '2025-08-01', '2025-10-15'],
    ['2nd Quarter', '2025-10-16', '2025-12-20'],
    ['3rd Quarter', '2026-01-05', '2026-03-15'],
    ['4th Quarter', '2026-03-16', '2026-05-31'],
];
$stmtGp = $mysqli->prepare("INSERT INTO grading_periods (name, school_year_id, start_date, end_date) VALUES (?, ?, ?, ?)");
foreach ($periods as $p) {
    $stmtGp->bind_param('siss', $p[0], $syId, $p[1], $p[2]);
    $stmtGp->execute();
}
$stmtGp->close();
echo "<p>✅ 4 Grading Periods</p><hr>";

echo "<h3>Step 4/4: Create requested user accounts</h3>";

$accounts = [
    ['admin',   'july',    'July@1234',    'July',    'Carreon',   'July Carreon',    'july.carreon@aims.local',    null,        null,         null, null, null],
    ['teacher', 'brix',    'Brix@1234',    'Brix',    'Sierra',    'Brix Sierra',     'brix.sierra@aims.local',     'T-0001',    null,         'General Education', 'General', null],
    ['student', 'carissa', 'Carissa@1234', 'Carissa', 'Marasigan', 'Carissa Marasigan','carissa.marasigan@aims.local',null,         'S-2025-0001',null, null, 'Grade 10'],
    ['student', 'sean',    'Sean@1234',    'Sean',    'Dot',       'Sean Dot',        'sean.dot@aims.local',        null,         'S-2025-0002',null, null, 'Grade 10'],
];

$status = 'active';
$stmtUser = $mysqli->prepare("INSERT INTO users
    (username, full_name, password_hash, role, email, contact_number, status) VALUES (?,?,?,?,?,?,?)");

$stmtTeacher = $mysqli->prepare("INSERT INTO teachers
    (user_id, employee_number, first_name, last_name, department, specialization, email, status) VALUES (?,?,?,?,?,?,?,?)");

$stmtStudent = $mysqli->prepare("INSERT INTO students
    (user_id, student_number, first_name, last_name, grade_level, email, contact_number, enrollment_status)
    VALUES (?,?,?,?,?,?,?, 'enrolled')");

$credentials = [];

$mysqli->begin_transaction();
try {
    foreach ($accounts as $a) {
        [$role, $username, $password, $fname, $lname, $fullname, $email, $empNo, $studNo, $dept, $spec, $grade] = $a;
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $empty = '';
        $stmtUser->bind_param('sssssss', $username, $fullname, $hash, $role, $email, $empty, $status);
        $stmtUser->execute();
        $uid = (int) $stmtUser->insert_id;

        if ($role === 'teacher') {
            $stmtTeacher->bind_param('isssssss', $uid, $empNo, $fname, $lname, $dept, $spec, $email, $status);
            $stmtTeacher->execute();
            echo "<p>✅ Teacher: <b>$fullname</b> (uid=$uid, emp=$empNo)</p>";
        } elseif ($role === 'student') {
            $stmtStudent->bind_param('issssss', $uid, $studNo, $fname, $lname, $grade, $email, $empty);
            $stmtStudent->execute();
            echo "<p>✅ Student: <b>$fullname</b> (uid=$uid, stud=$studNo, $grade)</p>";
        } else {
            echo "<p>✅ Admin: <b>$fullname</b> (uid=$uid)</p>";
        }
        $credentials[] = ['role' => $role, 'name' => $fullname, 'username' => $username, 'password' => $password];
    }
    $mysqli->commit();
    echo "<p><b>🎯 All accounts inserted successfully!</b></p>";
} catch (Throwable $e) {
    $mysqli->rollback();
    die('<p style="color:red">❌ ROLLBACK: ' . htmlspecialchars($e->getMessage()) . '</p>');
}

$stmtUser->close();
$stmtTeacher->close();
$stmtStudent->close();

echo "<hr><h2>🎉 Login Credentials</h2>";
echo "<table border='1' cellpadding='10' cellspacing='0' style='border-collapse:collapse;font-family:Arial;margin-bottom:10px'>";
echo "<tr style='background:#f0f0f0'><th>Role</th><th>Full Name</th><th>Username</th><th>Password</th></tr>";
foreach ($credentials as $c) {
    echo "<tr><td>" . ucfirst($c['role']) . "</td><td><b>" . htmlspecialchars($c['name']) . "</b></td>
          <td><code>" . htmlspecialchars($c['username']) . "</code></td>
          <td><code>" . htmlspecialchars($c['password']) . "</code></td></tr>";
}
echo "</table>";

echo "<hr>";
echo "<h3 style='color:red'>⚠️ SECURITY NOTICE — DELETE THIS FILE NOW!</h3>";
echo "<p>Anyone visiting <code>/aims/reset_accounts.php</code> can wipe and recreate the database.</p>";
echo "<p>Delete this file: <code>" . __FILE__ . "</code></p>";
echo '<a href="/aims/login.php"><button style="font-size:18px;padding:10px 24px;margin-top:4px">👉 Proceed to Login</button></a>';
