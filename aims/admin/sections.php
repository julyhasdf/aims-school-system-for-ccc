<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_role('admin');
require_once __DIR__ . '/../includes/layout.php';

$error = '';
$success = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_section') {
        $name    = trim($_POST['section_name'] ?? '');
        $grade   = trim($_POST['grade_level'] ?? '');
        $adviser = (int)($_POST['adviser_id'] ?? 0);
        $sy      = (int)($_POST['school_year_id'] ?? 0);
        
        if ($name && $grade && $sy) {
            $adviserVal = $adviser ?: null;

            // Prevent assigning a teacher as adviser to more than one section
            // in the same school year.
            $dupError = null;
            if ($adviserVal) {
                $stmtDup = $mysqli->prepare("
                    SELECT sec.section_name, sec.grade_level, CONCAT(t.first_name, ' ', t.last_name) AS teacher_name
                    FROM sections sec
                    JOIN teachers t ON t.id = ?
                    WHERE sec.adviser_id = ? AND sec.school_year_id = ?
                    LIMIT 1
                ");
                $stmtDup->bind_param('iii', $adviserVal, $adviserVal, $sy);
                $stmtDup->execute();
                $dupRow = $stmtDup->get_result()->fetch_assoc();
                $stmtDup->close();
                if ($dupRow) {
                    $dupError = "{$dupRow['teacher_name']} is already the adviser of {$dupRow['section_name']} ({$dupRow['grade_level']}). A teacher can only advise one section per school year.";
                }
            }

            if ($dupError) {
                $error = $dupError;
            } else {
                $stmt = $mysqli->prepare("INSERT INTO sections (section_name, grade_level, adviser_id, school_year_id) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('ssii', $name, $grade, $adviserVal, $sy);
                if ($stmt->execute()) {
                    $success = "Section created successfully.";
                } else {
                    $error = "Failed to create section: " . $stmt->error;
                }
                $stmt->close();
            }
        } else {
            $error = "Section Name, Grade Level and School Year are required.";
        }
    } elseif ($action === 'edit_section') {
        $id      = (int)($_POST['section_id'] ?? 0);
        $name    = trim($_POST['section_name'] ?? '');
        $grade   = trim($_POST['grade_level'] ?? '');
        $adviser = (int)($_POST['adviser_id'] ?? 0);
        $sy      = (int)($_POST['school_year_id'] ?? 0);
        
        if ($id && $name && $grade && $sy) {
            $adviserVal = $adviser ?: null;

            // Same duplicate-adviser check, excluding this section itself.
            $dupError = null;
            if ($adviserVal) {
                $stmtDup = $mysqli->prepare("
                    SELECT sec.section_name, sec.grade_level, CONCAT(t.first_name, ' ', t.last_name) AS teacher_name
                    FROM sections sec
                    JOIN teachers t ON t.id = ?
                    WHERE sec.adviser_id = ? AND sec.school_year_id = ? AND sec.id != ?
                    LIMIT 1
                ");
                $stmtDup->bind_param('iiii', $adviserVal, $adviserVal, $sy, $id);
                $stmtDup->execute();
                $dupRow = $stmtDup->get_result()->fetch_assoc();
                $stmtDup->close();
                if ($dupRow) {
                    $dupError = "{$dupRow['teacher_name']} is already the adviser of {$dupRow['section_name']} ({$dupRow['grade_level']}). A teacher can only advise one section per school year.";
                }
            }

            if ($dupError) {
                $error = $dupError;
            } else {
                $stmt = $mysqli->prepare("UPDATE sections SET section_name=?, grade_level=?, adviser_id=?, school_year_id=? WHERE id=?");
                $stmt->bind_param('ssiii', $name, $grade, $adviserVal, $sy, $id);
                if ($stmt->execute()) {
                    $success = "Section updated successfully.";
                } else {
                    $error = "Failed to update section: " . $stmt->error;
                }
                $stmt->close();
            }
        } else {
            $error = "Required fields missing.";
        }
    } elseif ($action === 'add_schedule') {
        // Assign Subject + Teacher + Schedule
        $sectionId = (int)($_POST['section_id'] ?? 0);
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $syId      = (int)($_POST['school_year_id'] ?? 0);
        $day       = $_POST['day_of_week'] ?? '';
        $start     = $_POST['start_time'] ?? '';
        $end       = $_POST['end_time'] ?? '';
        $room      = trim($_POST['room'] ?? '');
        
        if ($sectionId && $subjectId && $teacherId && $syId && $day && $start && $end) {
            $mysqli->begin_transaction();
            try {
                // 1. Ensure teacher_subject (load) exists or create it
                // We use INSERT IGNORE or ON DUPLICATE KEY UPDATE to handle "get or create"
                // But we have a unique constraint: (teacher_id, subject_id, section_id, school_year_id)
                
                // Check if load exists
                $tsId = 0;
                $stmt = $mysqli->prepare("SELECT id FROM teacher_subjects WHERE teacher_id=? AND subject_id=? AND section_id=? AND school_year_id=?");
                $stmt->bind_param('iiii', $teacherId, $subjectId, $sectionId, $syId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $tsId = (int)$row['id'];
                }
                $stmt->close();
                
                if (!$tsId) {
                    $stmt = $mysqli->prepare("INSERT INTO teacher_subjects (teacher_id, subject_id, section_id, school_year_id) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param('iiii', $teacherId, $subjectId, $sectionId, $syId);
                    if (!$stmt->execute()) throw new Exception("Failed to assign subject load: " . $stmt->error);
                    $tsId = $stmt->insert_id;
                    $stmt->close();
                }
                
                // 2. Prevent double-booking: the same teacher can't teach two classes
                //    at an overlapping time, and the same section can't have two
                //    classes running at the same time either.
                $conflictSql = "
                    SELECT s.day_of_week, s.start_time, s.end_time,
                           subj.subject_code, sec.section_name,
                           ts2.teacher_id AS c_teacher_id, ts2.section_id AS c_section_id,
                           t.first_name AS t_fname, t.last_name AS t_lname
                    FROM schedules s
                    JOIN teacher_subjects ts2 ON s.teacher_subject_id = ts2.id
                    JOIN subjects subj ON ts2.subject_id = subj.id
                    JOIN sections sec ON ts2.section_id = sec.id
                    JOIN teachers t ON ts2.teacher_id = t.id
                    WHERE ts2.school_year_id = ?
                      AND s.day_of_week = ?
                      AND s.start_time < ?
                      AND s.end_time   > ?
                      AND (ts2.teacher_id = ? OR ts2.section_id = ?)
                ";
                $stmtConflict = $mysqli->prepare($conflictSql);
                $stmtConflict->bind_param('isssii', $syId, $day, $end, $start, $teacherId, $sectionId);
                $stmtConflict->execute();
                $conflicts = $stmtConflict->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmtConflict->close();

                if (!empty($conflicts)) {
                    $lines = [];
                    foreach ($conflicts as $c) {
                        $range = date('h:i A', strtotime($c['start_time'])) . '–' . date('h:i A', strtotime($c['end_time']));
                        $teacherName = $c['t_fname'] . ' ' . $c['t_lname'];
                        $reasons = [];
                        if ((int)$c['c_teacher_id'] === $teacherId) {
                            $reasons[] = "{$teacherName} already teaches {$c['subject_code']} to {$c['section_name']}";
                        }
                        if ((int)$c['c_section_id'] === $sectionId) {
                            $reasons[] = "this section already has {$c['subject_code']} with {$teacherName}";
                        }
                        $lines[] = "{$c['day_of_week']} {$range} — " . implode(' and ', $reasons);
                    }
                    throw new Exception("Schedule conflict: " . implode('; ', $lines) . ". Pick a different day, time, teacher, or section.");
                }

                // 3. Add Schedule
                $stmt = $mysqli->prepare("INSERT INTO schedules (teacher_subject_id, day_of_week, start_time, end_time, room) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param('issss', $tsId, $day, $start, $end, $room);
                if (!$stmt->execute()) throw new Exception("Failed to add schedule: " . $stmt->error);
                $stmt->close();
                
                $mysqli->commit();
                $success = "Schedule added successfully.";
            } catch (Exception $e) {
                $mysqli->rollback();
                $error = $e->getMessage();
            }
        } else {
            $error = "All schedule fields are required.";
        }
    } elseif ($action === 'delete_section') {
        $id = (int)($_POST['section_id'] ?? 0);
        if ($id) {
            $mysqli->begin_transaction();
            try {
                // Delete related schedules first (via teacher_subjects)
                // Actually teacher_subjects has foreign key to sections.
                // We need to delete teacher_subjects rows for this section.
                // Schedules depend on teacher_subjects.
                // teacher_subjects might have ON DELETE RESTRICT or CASCADE?
                // The error message said: CONSTRAINT `fk_ts_section` ... ON UPDATE CASCADE (but failed on delete?)
                // Actually, if it's ON DELETE RESTRICT (default if not specified or specified as such), it fails.
                // Let's manually delete dependencies.
                
                // 1. Get all teacher_subject_ids for this section
                $tsIds = [];
                $res = $mysqli->query("SELECT id FROM teacher_subjects WHERE section_id = $id");
                while ($row = $res->fetch_assoc()) {
                    $tsIds[] = $row['id'];
                }
                
                if (!empty($tsIds)) {
                    $idsStr = implode(',', $tsIds);
                    // 2. Delete schedules for these teacher_subjects
                    $mysqli->query("DELETE FROM schedules WHERE teacher_subject_id IN ($idsStr)");
                    
                    // 3. Delete teacher_subjects
                    $mysqli->query("DELETE FROM teacher_subjects WHERE section_id = $id");
                }
                
                // 4. Update students (set section_id = NULL)
                $mysqli->query("UPDATE students SET section_id = NULL WHERE section_id = $id");

                // 5. Delete Section
                $stmt = $mysqli->prepare("DELETE FROM sections WHERE id = ?");
                $stmt->bind_param('i', $id);
                if (!$stmt->execute()) throw new Exception("Failed to delete section.");
                $stmt->close();

                $mysqli->commit();
                $success = "Section deleted successfully.";
            } catch (Exception $e) {
                $mysqli->rollback();
                $error = "Failed to delete section: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_schedule') {
        $id = (int)($_POST['schedule_id'] ?? 0);
        if ($id) {
            $stmt = $mysqli->prepare("DELETE FROM schedules WHERE id = ?");
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
                $success = "Schedule entry removed.";
            } else {
                $error = "Failed to remove schedule.";
            }
            $stmt->close();
        }
    }
}

// Fetch Active School Year
$activeSyId = 0;
$resSy = $mysqli->query("SELECT id, name FROM school_years WHERE is_current = 1 LIMIT 1");
if ($resSy && $row = $resSy->fetch_assoc()) {
    $activeSyId = (int)$row['id'];
    $activeSyName = $row['name'];
} else {
    // Fallback: get latest or create dummy
    $resSy = $mysqli->query("SELECT id, name FROM school_years ORDER BY id DESC LIMIT 1");
    if ($resSy && $row = $resSy->fetch_assoc()) {
        $activeSyId = (int)$row['id'];
        $activeSyName = $row['name'];
    } else {
        $activeSyName = "N/A";
    }
}

// Fetch Sections
$sections = [];
if ($activeSyId) {
    $stmt = $mysqli->prepare("
        SELECT s.*, CONCAT(t.first_name, ' ', t.last_name) as adviser_name 
        FROM sections s 
        LEFT JOIN teachers t ON s.adviser_id = t.id 
        WHERE s.school_year_id = ? 
        ORDER BY s.grade_level, s.section_name
    ");
    $stmt->bind_param('i', $activeSyId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        // Fetch schedules for this section
        $secId = (int)$row['id'];
        $scheds = [];
        $stmt2 = $mysqli->prepare("
            SELECT sch.*, sub.subject_name, sub.subject_code, CONCAT(t.first_name, ' ', t.last_name) as teacher_name
            FROM schedules sch
            JOIN teacher_subjects ts ON sch.teacher_subject_id = ts.id
            JOIN subjects sub ON ts.subject_id = sub.id
            JOIN teachers t ON ts.teacher_id = t.id
            WHERE ts.section_id = ?
            ORDER BY FIELD(sch.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), sch.start_time
        ");
        $stmt2->bind_param('i', $secId);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        while ($r2 = $res2->fetch_assoc()) {
            $scheds[] = $r2;
        }
        $stmt2->close();
        $row['schedules'] = $scheds;
        $sections[] = $row;
    }
    $stmt->close();
}

// Fetch Teachers & Subjects for dropdowns
$teachers = [];
$resT = $mysqli->query("SELECT id, first_name, last_name FROM teachers ORDER BY last_name");
while ($row = $resT->fetch_assoc()) $teachers[] = $row;

$subjects = [];
$resS = $mysqli->query("SELECT id, subject_code, subject_name FROM subjects ORDER BY subject_code");
while ($row = $resS->fetch_assoc()) $subjects[] = $row;


$sidebar = [
    ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'dashboard'],
    ['label' => 'Students', 'href' => 'students.php', 'icon' => 'students'],
    ['label' => 'Teachers', 'href' => 'teachers.php', 'icon' => 'teachers'],
    ['label' => 'Subjects', 'href' => 'subjects.php', 'icon' => 'subjects'],
    ['label' => 'Schedules', 'href' => 'sections.php', 'active' => true, 'icon' => 'sections'],
    ['label' => 'Users', 'href' => 'users.php', 'icon' => 'users'],
    ['label' => 'Announcements', 'href' => 'announcements.php', 'icon' => 'announcements'],
    ['label' => 'Reports', 'href' => 'reports.php', 'icon' => 'reports'],
];

start_layout('Schedule Management', 'AIMS - Admin', $_SESSION['username'] ?? 'Admin', $sidebar);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">Sections & Schedules</h1>
        <small class="text-muted">School Year: <strong><?php echo htmlspecialchars($activeSyName); ?></strong></small>
    </div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createSectionModal">
        <?php echo icon('sections'); ?> Create Section
    </button>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <div class="row g-3">
            <div class="col-md-4">
                <input type="text" id="sectionSearchInput" class="form-control" placeholder="Search sections, advisers, teachers, subjects...">
            </div>
        </div>
    </div>
</div>

<?php if (empty($sections)): ?>
    <div class="alert alert-info">No sections found for the current school year.</div>
<?php else: ?>
    <div class="row g-4" id="sectionsCardsRow">
        <?php foreach ($sections as $sec): ?>
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center bg-light">
                    <div>
                        <h5 class="mb-0"><?php echo htmlspecialchars($sec['section_name']); ?> <span class="badge bg-secondary ms-2"><?php echo htmlspecialchars($sec['grade_level']); ?></span></h5>
                        <small class="text-muted">Adviser: <?php echo htmlspecialchars($sec['adviser_name'] ?? 'None'); ?></small>
                    </div>
                    <div>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addScheduleModal" data-sectionid="<?php echo $sec['id']; ?>" data-sectionname="<?php echo htmlspecialchars($sec['section_name']); ?>">
                             Add Schedule
                        </button>
                        <button class="btn btn-sm btn-outline-secondary ms-1" data-bs-toggle="modal" data-bs-target="#editSectionModal" 
                            data-id="<?php echo $sec['id']; ?>"
                            data-name="<?php echo htmlspecialchars($sec['section_name']); ?>"
                            data-grade="<?php echo htmlspecialchars($sec['grade_level']); ?>"
                            data-adviser="<?php echo $sec['adviser_id']; ?>"
                            data-sy="<?php echo $sec['school_year_id']; ?>">
                             Edit
                        </button>
                        <form method="POST" class="d-inline ms-1" onsubmit="return confirm('WARNING: Delete this section?\n\nThis will remove:\n- All class schedules\n- Teacher assignments for this section\n- Student enrollments in this section');">
                            <input type="hidden" name="action" value="delete_section">
                            <input type="hidden" name="section_id" value="<?php echo $sec['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($sec['schedules'])): ?>
                        <div class="p-3 text-muted text-center">No class schedules added yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Day</th>
                                        <th>Time</th>
                                        <th>Subject</th>
                                        <th>Teacher</th>
                                        <th>Room</th>
                                        <th style="width:50px"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sec['schedules'] as $sch): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($sch['day_of_week']); ?></td>
                                        <td><?php echo date('h:i A', strtotime($sch['start_time'])) . ' - ' . date('h:i A', strtotime($sch['end_time'])); ?></td>
                                        <td class="fw-semibold"><?php echo htmlspecialchars($sch['subject_code']); ?></td>
                                        <td><?php echo htmlspecialchars($sch['teacher_name']); ?></td>
                                        <td><?php echo htmlspecialchars($sch['room'] ?? '—'); ?></td>
                                        <td>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Remove this schedule?');">
                                                <input type="hidden" name="action" value="delete_schedule">
                                                <input type="hidden" name="schedule_id" value="<?php echo $sch['id']; ?>">
                                                <button type="submit" class="btn btn-link text-danger p-0" title="Remove">&times;</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Create Section Modal -->
<div class="modal fade" id="createSectionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="create_section">
            <input type="hidden" name="school_year_id" value="<?php echo $activeSyId; ?>">
            <div class="modal-header">
                <h5 class="modal-title">Create Section</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Section Name *</label>
                    <input type="text" name="section_name" class="form-control" placeholder="e.g. Einstein" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Grade Level *</label>
                    <select name="grade_level" class="form-select" required>
                        <option value="Grade 7">Grade 7</option>
                        <option value="Grade 8">Grade 8</option>
                        <option value="Grade 9">Grade 9</option>
                        <option value="Grade 10">Grade 10</option>
                        <option value="Grade 11">Grade 11</option>
                        <option value="Grade 12">Grade 12</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Adviser</label>
                    <select name="adviser_id" class="form-select">
                        <option value="0">None</option>
                        <?php foreach ($teachers as $t): ?>
                            <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['first_name'] . ' ' . $t['last_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Create</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Section Modal -->
<div class="modal fade" id="editSectionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="edit_section">
            <input type="hidden" name="section_id" id="edit_section_id">
            <input type="hidden" name="school_year_id" id="edit_school_year_id">
            <div class="modal-header">
                <h5 class="modal-title">Edit Section</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Section Name *</label>
                    <input type="text" name="section_name" id="edit_section_name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Grade Level *</label>
                    <select name="grade_level" id="edit_grade_level" class="form-select" required>
                        <option value="Grade 7">Grade 7</option>
                        <option value="Grade 8">Grade 8</option>
                        <option value="Grade 9">Grade 9</option>
                        <option value="Grade 10">Grade 10</option>
                        <option value="Grade 11">Grade 11</option>
                        <option value="Grade 12">Grade 12</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Adviser</label>
                    <select name="adviser_id" id="edit_adviser_id" class="form-select">
                        <option value="0">None</option>
                        <?php foreach ($teachers as $tch): ?>
                            <option value="<?php echo $tch['id']; ?>"><?php echo htmlspecialchars($tch['first_name'] . ' ' . $tch['last_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Section</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Schedule Modal -->
<div class="modal fade" id="addScheduleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="add_schedule">
            <input type="hidden" name="section_id" id="sched_section_id">
            <input type="hidden" name="school_year_id" value="<?php echo $activeSyId; ?>">
            <div class="modal-header">
                <h5 class="modal-title">Add Schedule to <span id="sched_section_name"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Subject *</label>
                    <select name="subject_id" class="form-select" required>
                        <option value="">Select Subject</option>
                        <?php foreach ($subjects as $s): ?>
                            <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['subject_code'] . ' - ' . $s['subject_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Teacher *</label>
                    <select name="teacher_id" class="form-select" required>
                        <option value="">Select Teacher</option>
                        <?php foreach ($teachers as $t): ?>
                            <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['first_name'] . ' ' . $t['last_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Day *</label>
                        <select name="day_of_week" class="form-select" required>
                            <option value="Monday">Monday</option>
                            <option value="Tuesday">Tuesday</option>
                            <option value="Wednesday">Wednesday</option>
                            <option value="Thursday">Thursday</option>
                            <option value="Friday">Friday</option>
                            <option value="Saturday">Saturday</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Room</label>
                        <input type="text" name="room" class="form-control" placeholder="e.g. 101">
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Start Time *</label>
                        <input type="time" name="start_time" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">End Time *</label>
                        <input type="time" name="end_time" class="form-control" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Schedule</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Search Functionality - filters whole section cards by any text they contain
    // (section name, grade, adviser, subject, teacher, day, room, etc.)
    var searchInput = document.getElementById('sectionSearchInput');
    var cardsRow = document.getElementById('sectionsCardsRow');
    if (searchInput && cardsRow) {
        searchInput.addEventListener('keyup', function() {
            var filter = this.value.toLowerCase();
            var cards = cardsRow.children;
            for (var i = 0; i < cards.length; i++) {
                var text = cards[i].textContent.toLowerCase();
                cards[i].style.display = text.indexOf(filter) > -1 ? '' : 'none';
            }
        });
    }

    var addSchedModal = document.getElementById('addScheduleModal');
    addSchedModal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        var sectionId = button.getAttribute('data-sectionid');
        var sectionName = button.getAttribute('data-sectionname');
        
        var modalTitle = addSchedModal.querySelector('#sched_section_name');
        var modalInput = addSchedModal.querySelector('#sched_section_id');
        
        modalTitle.textContent = sectionName;
        modalInput.value = sectionId;
    });

    var editSectionModal = document.getElementById('editSectionModal');
    if (editSectionModal) {
        editSectionModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            document.getElementById('edit_section_id').value = button.getAttribute('data-id');
            document.getElementById('edit_school_year_id').value = button.getAttribute('data-sy');
            document.getElementById('edit_section_name').value = button.getAttribute('data-name');
            document.getElementById('edit_grade_level').value = button.getAttribute('data-grade');
            document.getElementById('edit_adviser_id').value = button.getAttribute('data-adviser') || 0;
        });
    }
});
</script>

<?php end_layout(); ?>
