<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/notifications.php';
require_role('teacher');
require_once __DIR__ . '/../includes/layout.php';

$error = '';
$success = '';
$teacherId = 0;
$selectedTsId = (int)($_GET['ts_id'] ?? 0);
$classes = [];
$students = [];
$gradingPeriods = [];
$selectedPeriodId = (int)($_GET['period_id'] ?? 0);

if (isset($_SESSION['user_id'])) {
    $uid = (int) $_SESSION['user_id'];
    $stmt = $mysqli->prepare("SELECT id FROM teachers WHERE user_id = ?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $teacherId = (int)$row['id'];
    }
    $stmt->close();
}

if ($teacherId) {
    // 1. Fetch Teacher's Classes for Dropdown
    $query = "
        SELECT ts.id, sub.subject_code, sec.section_name 
        FROM teacher_subjects ts
        JOIN subjects sub ON ts.subject_id = sub.id
        JOIN sections sec ON ts.section_id = sec.id
        WHERE ts.teacher_id = ?
        ORDER BY sec.section_name
    ";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('i', $teacherId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $classes[] = $row;
    }
    $stmt->close();

    // 2. Fetch Grading Periods
    // Assuming active school year. For now fetch all or just create dummy if empty.
    // In schema, grading_periods linked to school_year.
    // Let's just fetch all for now or filter by current SY if implemented.
    $resGP = $mysqli->query("SELECT id, name FROM grading_periods ORDER BY id");
    while ($row = $resGP->fetch_assoc()) {
        $gradingPeriods[] = $row;
    }
    // If no grading periods, maybe seed them? (Skipping seed for now, assuming admin adds them)
    // Fallback if empty to avoid broken UI
    if (empty($gradingPeriods)) {
        // Just for demo purposes, assume 1st Quarter exists with ID 1 if DB is empty
        // But better to show message.
    } else if (!$selectedPeriodId && !empty($gradingPeriods)) {
        $selectedPeriodId = (int)$gradingPeriods[0]['id'];
    }

    // 3. Handle Save Grades
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_grades'])) {
        $tsId = (int)$_POST['ts_id'];
        $periodId = (int)$_POST['period_id'];
        $grades = $_POST['grades'] ?? []; // array of student_id => grade

        if ($tsId && $periodId && !empty($grades)) {
            $stmt = $mysqli->prepare("
                INSERT INTO grades (student_id, teacher_subject_id, grading_period_id, grade, encoded_by)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE grade = VALUES(grade), encoded_by = VALUES(encoded_by)
            ");
            $encoderId = $_SESSION['user_id'];
            $notifiedCount = 0;
            
            // Get Subject Name
            $subName = '';
            $subStmt = $mysqli->prepare("SELECT subject_code FROM subjects JOIN teacher_subjects ts ON ts.subject_id = subjects.id WHERE ts.id = ?");
            $subStmt->bind_param('i', $tsId);
            $subStmt->execute();
            $subStmt->bind_result($subName);
            $subStmt->fetch();
            $subStmt->close();

            foreach ($grades as $sid => $val) {
                $val = (float)$val;
                if ($val < 0) continue; // skip invalid
                $stmt->bind_param('iiidi', $sid, $tsId, $periodId, $val, $encoderId);
                if ($stmt->execute()) {
                    // Notify student
                    $msg = "Your grade for $subName has been updated to $val.";
                    create_notification($sid, "Grade Updated", $msg, "grade", $tsId);
                    $notifiedCount++;
                }
            }
            $stmt->close();
            $success = "Grades saved successfully.";
            $selectedTsId = $tsId;
            $selectedPeriodId = $periodId;
        }
    }

    // 4. Fetch Students and Grades if Class Selected
    if ($selectedTsId && $selectedPeriodId) {
        $query = "
            SELECT s.id, s.student_number, s.first_name, s.last_name,
                   g.grade
            FROM students s
            JOIN teacher_subjects ts ON ts.section_id = s.section_id
            LEFT JOIN grades g ON g.student_id = s.id 
                               AND g.teacher_subject_id = ts.id 
                               AND g.grading_period_id = ?
            WHERE ts.id = ? AND ts.teacher_id = ?
            ORDER BY s.last_name, s.first_name
        ";
        $stmt = $mysqli->prepare($query);
        $stmt->bind_param('iii', $selectedPeriodId, $selectedTsId, $teacherId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $students[] = $row;
        }
        $stmt->close();
    }
}

$sidebar = [
    ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'dashboard'],
    ['label' => 'My Classes', 'href' => 'classes.php', 'icon' => 'classes'],
    ['label' => 'Attendance', 'href' => 'attendance.php', 'icon' => 'attendance'],
    ['label' => 'Grades', 'href' => 'grades.php', 'active' => true, 'icon' => 'grade_book'],
    ['label' => 'Reports', 'href' => 'reports.php', 'icon' => 'reports'],
];

start_layout('Grade Management', 'AIMS - Teacher', $_SESSION['username'] ?? '', $sidebar);
?>

<h1 class="h3 mb-4">Grade Management</h1>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Select Class</label>
                <select name="ts_id" class="form-select" onchange="this.form.submit()">
                    <option value="">-- Select Class --</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo $selectedTsId === (int)$c['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['subject_code'] . ' - ' . $c['section_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Grading Period</label>
                <select name="period_id" class="form-select" onchange="this.form.submit()">
                    <?php if (empty($gradingPeriods)): ?>
                        <option value="">No grading periods defined</option>
                    <?php else: ?>
                        <?php foreach ($gradingPeriods as $gp): ?>
                            <option value="<?php echo $gp['id']; ?>" <?php echo $selectedPeriodId === (int)$gp['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($gp['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<?php if ($selectedTsId && $selectedPeriodId): ?>
    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Student Grades</h5>
        </div>
        <div class="card-body p-0">
            <form method="POST">
                <input type="hidden" name="save_grades" value="1">
                <input type="hidden" name="ts_id" value="<?php echo $selectedTsId; ?>">
                <input type="hidden" name="period_id" value="<?php echo $selectedPeriodId; ?>">
                
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 15%">Student No.</th>
                                <th style="width: 40%">Name</th>
                                <th style="width: 20%">Grade</th>
                                <th style="width: 25%">Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($students)): ?>
                                <tr><td colspan="4" class="text-center py-4">No students found in this class.</td></tr>
                            <?php else: ?>
                                <?php foreach ($students as $std): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($std['student_number']); ?></td>
                                    <td><?php echo htmlspecialchars($std['last_name'] . ', ' . $std['first_name']); ?></td>
                                    <td>
                                        <input type="number" step="0.01" min="0" max="100" 
                                               name="grades[<?php echo $std['id']; ?>]" 
                                               class="form-control form-control-sm" 
                                               value="<?php echo htmlspecialchars((string)($std['grade'] ?? '')); ?>"
                                               placeholder="0.00">
                                    </td>
                                    <td>
                                        <?php 
                                            $g = (float)($std['grade'] ?? 0);
                                            if ($std['grade'] !== null) {
                                                echo $g >= 75 ? '<span class="text-success">Passed</span>' : '<span class="text-danger">Failed</span>';
                                            }
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="p-3 text-end bg-light border-top">
                    <button type="submit" class="btn btn-primary">Save Grades</button>
                </div>
            </form>
        </div>
    </div>
<?php elseif ($selectedTsId && empty($gradingPeriods)): ?>
    <div class="alert alert-warning">Please ask the administrator to set up Grading Periods first.</div>
<?php endif; ?>

<?php end_layout(); ?>
