-- SQL Schema for AIMS (MySQL)

-- OPTIONAL: create database
CREATE DATABASE IF NOT EXISTS aims_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE aims_db;

-- USERS (authentication)
CREATE TABLE users (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    username       VARCHAR(50) NOT NULL UNIQUE,
    password_hash  VARCHAR(255) NOT NULL,
    role           ENUM('admin','teacher','student') NOT NULL,
    email          VARCHAR(100),
    status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SCHOOL YEARS
CREATE TABLE school_years (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(20) NOT NULL,  -- e.g. "2025-2026"
    is_current TINYINT(1) NOT NULL DEFAULT 0,
    start_date DATE,
    end_date   DATE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SECTIONS / CLASSES
CREATE TABLE sections (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    section_name   VARCHAR(100) NOT NULL,  -- e.g. "Grade 10 - A"
    grade_level    VARCHAR(20) NOT NULL,   -- or INT if you prefer
    adviser_id     INT NULL,               -- FK to teachers (defined later)
    school_year_id INT NOT NULL,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sections_school_year
        FOREIGN KEY (school_year_id) REFERENCES school_years(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TEACHERS
CREATE TABLE teachers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNIQUE, -- one user per teacher
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
    CONSTRAINT fk_teachers_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add FK from sections to teachers (adviser_id) now that teachers exists
ALTER TABLE sections
  ADD CONSTRAINT fk_sections_adviser
    FOREIGN KEY (adviser_id) REFERENCES teachers(id)
    ON UPDATE CASCADE ON DELETE SET NULL;

-- STUDENTS
CREATE TABLE students (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNIQUE, -- one user per student
    student_number   VARCHAR(50) NOT NULL UNIQUE,
    first_name       VARCHAR(100) NOT NULL,
    middle_name      VARCHAR(100),
    last_name        VARCHAR(100) NOT NULL,
    gender           ENUM('Male','Female','Other') DEFAULT NULL,
    date_of_birth    DATE,
    address          VARCHAR(255),
    contact_number   VARCHAR(50),
    guardian_name    VARCHAR(150),
    guardian_contact VARCHAR(50),
    grade_level      VARCHAR(20) NOT NULL, -- or INT
    section_id       INT NULL,  -- current section
    enrollment_status ENUM('enrolled','graduated','transferred','dropped')
                      NOT NULL DEFAULT 'enrolled',
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_students_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_students_section
        FOREIGN KEY (section_id) REFERENCES sections(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SUBJECTS
CREATE TABLE subjects (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    subject_code VARCHAR(50) NOT NULL UNIQUE,
    subject_name VARCHAR(150) NOT NULL,
    description  TEXT,
    units        DECIMAL(4,2) DEFAULT 0,
    grade_level  VARCHAR(20),  -- or INT
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TEACHER SUBJECT ASSIGNMENT (teacher loads)
CREATE TABLE teacher_subjects (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id     INT NOT NULL,
    subject_id     INT NOT NULL,
    section_id     INT NOT NULL,
    school_year_id INT NOT NULL,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ts_teacher
        FOREIGN KEY (teacher_id) REFERENCES teachers(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_ts_subject
        FOREIGN KEY (subject_id) REFERENCES subjects(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_ts_section
        FOREIGN KEY (section_id) REFERENCES sections(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_ts_school_year
        FOREIGN KEY (school_year_id) REFERENCES school_years(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT uq_ts_unique_load
        UNIQUE (teacher_id, subject_id, section_id, school_year_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SCHEDULES (timetable)
CREATE TABLE schedules (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    teacher_subject_id INT NOT NULL,
    day_of_week      ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
    start_time       TIME NOT NULL,
    end_time         TIME NOT NULL,
    room             VARCHAR(50),
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_schedules_ts
        FOREIGN KEY (teacher_subject_id) REFERENCES teacher_subjects(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ENROLLMENTS (history per school year)
CREATE TABLE enrollments (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    student_id     INT NOT NULL,
    section_id     INT NOT NULL,
    school_year_id INT NOT NULL,
    enrollment_date DATE,
    status         ENUM('enrolled','dropped','completed') NOT NULL DEFAULT 'enrolled',
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_enroll_student
        FOREIGN KEY (student_id) REFERENCES students(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_enroll_section
        FOREIGN KEY (section_id) REFERENCES sections(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_enroll_sy
        FOREIGN KEY (school_year_id) REFERENCES school_years(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT uq_enroll_unique
        UNIQUE (student_id, section_id, school_year_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- GRADING PERIODS
CREATE TABLE grading_periods (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(50) NOT NULL, -- e.g. "1st Quarter"
    school_year_id INT NOT NULL,
    start_date     DATE,
    end_date       DATE,
    weight         DECIMAL(5,2) DEFAULT 0, -- percentage (optional)
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_gp_sy
        FOREIGN KEY (school_year_id) REFERENCES school_years(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- GRADES
CREATE TABLE grades (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    student_id        INT NOT NULL,
    teacher_subject_id INT NOT NULL,
    grading_period_id INT NOT NULL,
    grade             DECIMAL(5,2) NOT NULL,
    remarks           VARCHAR(50),
    date_encoded      DATETIME DEFAULT CURRENT_TIMESTAMP,
    encoded_by        INT NOT NULL, -- users.id (teacher/admin)
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_grades_student
        FOREIGN KEY (student_id) REFERENCES students(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_grades_ts
        FOREIGN KEY (teacher_subject_id) REFERENCES teacher_subjects(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_grades_gp
        FOREIGN KEY (grading_period_id) REFERENCES grading_periods(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_grades_encoded_by
        FOREIGN KEY (encoded_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT uq_grade_unique
        UNIQUE (student_id, teacher_subject_id, grading_period_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ATTENDANCE
CREATE TABLE attendance (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    student_id        INT NOT NULL,
    teacher_subject_id INT NOT NULL,
    attendance_date   DATE NOT NULL,
    status            ENUM('Present','Absent','Late','Excused') NOT NULL,
    remarks           VARCHAR(255),
    recorded_by       INT NOT NULL,  -- users.id (teacher)
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_att_student
        FOREIGN KEY (student_id) REFERENCES students(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_att_ts
        FOREIGN KEY (teacher_subject_id) REFERENCES teacher_subjects(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_att_recorded_by
        FOREIGN KEY (recorded_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT uq_att_unique
        UNIQUE (student_id, teacher_subject_id, attendance_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ANNOUNCEMENTS (optional)
CREATE TABLE announcements (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(150) NOT NULL,
    message     TEXT NOT NULL,
    target_role ENUM('all','students','teachers') NOT NULL DEFAULT 'all',
    created_by  INT NOT NULL,  -- users.id
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME NULL,
    CONSTRAINT fk_ann_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Helpful indexes
CREATE INDEX idx_students_section   ON students(section_id);
CREATE INDEX idx_students_grade     ON students(grade_level);
CREATE INDEX idx_ts_teacher         ON teacher_subjects(teacher_id);
CREATE INDEX idx_ts_section         ON teacher_subjects(section_id);
CREATE INDEX idx_grades_student     ON grades(student_id);
CREATE INDEX idx_grades_ts          ON grades(teacher_subject_id);
CREATE INDEX idx_att_student_date   ON attendance(student_id, attendance_date);

