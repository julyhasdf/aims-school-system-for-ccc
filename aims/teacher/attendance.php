<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_role('teacher');
require_once __DIR__ . '/../includes/layout.php';

$error = '';
$success = '';
$teacherId = 0;
$selectedTsId = (int)($_GET['ts_id'] ?? 0);
$classes = [];
$students = [];
$attendanceDate = $_GET['date'] ?? date('Y-m-d');

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
    // 1. Fetch Teacher's Classes
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

    // 2. Handle Save Attendance
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {
        $tsId = (int)$_POST['ts_id'];
        $date = $_POST['date'];
        $statusArr = $_POST['status'] ?? []; // student_id => status

        if ($tsId && $date && !empty($statusArr)) {
            $stmt = $mysqli->prepare("
                INSERT INTO attendance (student_id, teacher_subject_id, attendance_date, status, recorded_by)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), recorded_by = VALUES(recorded_by)
            ");
            $recorderId = $_SESSION['user_id'];
            foreach ($statusArr as $sid => $stat) {
                $stmt->bind_param('iissi', $sid, $tsId, $date, $stat, $recorderId);
                $stmt->execute();
            }
            $stmt->close();
            $success = "Attendance recorded for $date.";
            $selectedTsId = $tsId;
            $attendanceDate = $date;
        }
    }

    // 3. Fetch Students and Attendance
    if ($selectedTsId) {
        $query = "
            SELECT s.id, s.student_number, s.first_name, s.last_name,
                   a.status
            FROM students s
            JOIN teacher_subjects ts ON ts.section_id = s.section_id
            LEFT JOIN attendance a ON a.student_id = s.id 
                                   AND a.teacher_subject_id = ts.id 
                                   AND a.attendance_date = ?
            WHERE ts.id = ? AND ts.teacher_id = ?
            ORDER BY s.last_name, s.first_name
        ";
        $stmt = $mysqli->prepare($query);
        $stmt->bind_param('sii', $attendanceDate, $selectedTsId, $teacherId);
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
    ['label' => 'Attendance', 'href' => 'attendance.php', 'active' => true, 'icon' => 'attendance'],
    ['label' => 'Grades', 'href' => 'grades.php', 'icon' => 'grade_book'],
    ['label' => 'Reports', 'href' => 'reports.php', 'icon' => 'reports'],
];

start_layout('Attendance Management', 'AIMS - Teacher', $_SESSION['username'] ?? '', $sidebar);
?>

<h1 class="h3 mb-4">Attendance Management</h1>

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
                        <option value="<?php echo $c['id']; ?>" <?php echo $selectedTsId === $c['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['subject_code'] . ' - ' . $c['section_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Date</label>
                <input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($attendanceDate); ?>" onchange="this.form.submit()">
            </div>
        </form>
    </div>
</div>

<?php if ($selectedTsId): ?>
    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Class Attendance</h5>
            <span class="badge bg-info text-dark"><?php echo date('F d, Y', strtotime($attendanceDate)); ?></span>
        </div>
        <div class="card-body p-0">
            <form method="POST">
                <input type="hidden" name="save_attendance" value="1">
                <input type="hidden" name="ts_id" value="<?php echo $selectedTsId; ?>">
                <input type="hidden" name="date" value="<?php echo $attendanceDate; ?>">
                
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 20%">Student No.</th>
                                <th style="width: 40%">Name</th>
                                <th style="width: 40%">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($students)): ?>
                                <tr><td colspan="3" class="text-center py-4">No students found in this class.</td></tr>
                            <?php else: ?>
                                <?php foreach ($students as $std): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($std['student_number']); ?></td>
                                    <td><?php echo htmlspecialchars($std['last_name'] . ', ' . $std['first_name']); ?></td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <?php 
                                            $curr = $std['status'] ?? 'Present'; // Default to Present
                                            $statuses = ['Present', 'Absent', 'Late', 'Excused'];
                                            foreach ($statuses as $s) {
                                                $checked = ($curr === $s) ? 'checked' : '';
                                                $cls = match($s) {
                                                    'Present' => 'btn-outline-success',
                                                    'Absent' => 'btn-outline-danger',
                                                    'Late' => 'btn-outline-warning',
                                                    'Excused' => 'btn-outline-info',
                                                };
                                                echo '<input type="radio" class="btn-check" name="status['.$std['id'].']" id="btnradio_'.$std['id'].'_'.$s.'" value="'.$s.'" '.$checked.'>';
                                                echo '<label class="btn btn-sm '.$cls.'" for="btnradio_'.$std['id'].'_'.$s.'">'.$s.'</label>';
                                            }
                                            ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="p-3 text-end bg-light border-top">
                    <button type="submit" class="btn btn-primary">Save Attendance</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php end_layout(); ?>
