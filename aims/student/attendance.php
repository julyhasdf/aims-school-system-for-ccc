<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_role('student');
require_once __DIR__ . '/../includes/layout.php';

$studentId = 0;
$attendance = [];
$counts = ['Present' => 0, 'Absent' => 0, 'Late' => 0, 'Excused' => 0];

if (isset($_SESSION['user_id'])) {
    $uid = (int) $_SESSION['user_id'];
    $stmt = $mysqli->prepare("SELECT id FROM students WHERE user_id = ?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $studentId = (int)$row['id'];
    }
    $stmt->close();
}

if ($studentId) {
    // Fetch Attendance
    $query = "
        SELECT a.attendance_date, a.status,
               sub.subject_code, sub.subject_name
        FROM attendance a
        JOIN teacher_subjects ts ON a.teacher_subject_id = ts.id
        JOIN subjects sub ON ts.subject_id = sub.id
        WHERE a.student_id = ?
        ORDER BY a.attendance_date DESC
        LIMIT 50
    ";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $attendance[] = $row;
        if (isset($counts[$row['status']])) {
            $counts[$row['status']]++;
        }
    }
    $stmt->close();
}

$sidebar = [
    ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'dashboard'],
    ['label' => 'My Grades', 'href' => 'grades.php', 'icon' => 'grades'],
    ['label' => 'My Schedule', 'href' => 'dashboard.php', 'icon' => 'classes'],
    ['label' => 'My Attendance', 'href' => 'attendance.php', 'active' => true, 'icon' => 'attendance'],
];

start_layout('My Attendance', 'AIMS - Student', $_SESSION['username'] ?? '', $sidebar);
?>

<h1 class="h3 mb-4">My Attendance</h1>

<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="card text-bg-success shadow-sm text-center">
            <div class="card-body">
                <h3><?php echo $counts['Present']; ?></h3>
                <span>Present</span>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-bg-danger shadow-sm text-center">
            <div class="card-body">
                <h3><?php echo $counts['Absent']; ?></h3>
                <span>Absent</span>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-bg-warning shadow-sm text-center">
            <div class="card-body">
                <h3><?php echo $counts['Late']; ?></h3>
                <span>Late</span>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-bg-info shadow-sm text-center">
            <div class="card-body">
                <h3><?php echo $counts['Excused']; ?></h3>
                <span>Excused</span>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white">
        <h5 class="mb-0">Recent Attendance Records</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Subject</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($attendance)): ?>
                        <tr><td colspan="3" class="text-center py-4">No attendance records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($attendance as $att): ?>
                        <tr>
                            <td><?php echo date('F d, Y', strtotime($att['attendance_date'])); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($att['subject_code']); ?></strong>
                                <small class="text-muted d-block"><?php echo htmlspecialchars($att['subject_name']); ?></small>
                            </td>
                            <td>
                                <?php 
                                    $cls = match($att['status']) {
                                        'Present' => 'bg-success',
                                        'Absent' => 'bg-danger',
                                        'Late' => 'bg-warning text-dark',
                                        'Excused' => 'bg-info text-dark',
                                        default => 'bg-secondary'
                                    };
                                    echo '<span class="badge '.$cls.'">'.$att['status'].'</span>';
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php end_layout(); ?>
