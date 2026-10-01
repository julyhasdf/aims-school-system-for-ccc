<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_role('student');
require_once __DIR__ . '/../includes/layout.php';

$studentInfo = null;
$schedules = [];

if (isset($_SESSION['user_id'])) {
    $userId = (int) $_SESSION['user_id'];
    $stmt   = $mysqli->prepare(
        'SELECT s.student_number, s.first_name, s.last_name, s.grade_level, s.section_id, sec.section_name 
         FROM students s 
         LEFT JOIN sections sec ON s.section_id = sec.id 
         WHERE s.user_id = ? 
         LIMIT 1'
    );
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result      = $stmt->get_result();
        $studentInfo = $result->fetch_assoc() ?: null;
        $stmt->close();
    }

    // Fetch Schedule if student has a section
    if ($studentInfo && !empty($studentInfo['section_id'])) {
        $secId = (int)$studentInfo['section_id'];
        $schedStmt = $mysqli->prepare(
            "SELECT sch.day_of_week, sch.start_time, sch.end_time, sch.room,
                    subj.subject_code, subj.subject_name, 
                    t.first_name AS teacher_fname, t.last_name AS teacher_lname
             FROM schedules sch
             JOIN teacher_subjects ts ON sch.teacher_subject_id = ts.id
             JOIN subjects subj ON ts.subject_id = subj.id
             JOIN teachers t ON ts.teacher_id = t.id
             WHERE ts.section_id = ?
             ORDER BY FIELD(sch.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), sch.start_time"
        );
        if ($schedStmt) {
            $schedStmt->bind_param('i', $secId);
            $schedStmt->execute();
            $res = $schedStmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $schedules[] = $row;
            }
            $schedStmt->close();
        }
    }
}

$sidebar = [
    ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'dashboard'],
    ['label' => 'My Grades', 'href' => 'grades.php', 'icon' => 'grades'],
    ['label' => 'My Schedule', 'href' => 'classes.php', 'active' => true, 'icon' => 'classes'],
    ['label' => 'My Attendance', 'href' => 'attendance.php', 'icon' => 'attendance'],
];

start_layout('My Schedule', 'AIMS - Student', $_SESSION['username'] ?? '', $sidebar);
?>

<h1 class="h3 mb-4">My Class Schedule</h1>

<?php if ($studentInfo): ?>
    <div class="alert alert-info">
        Section: <strong><?php echo htmlspecialchars($studentInfo['section_name'] ?? 'N/A'); ?></strong> | 
        Grade: <strong><?php echo htmlspecialchars($studentInfo['grade_level'] ?? 'N/A'); ?></strong>
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Day</th>
                        <th>Time</th>
                        <th>Subject</th>
                        <th>Teacher</th>
                        <th>Room</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($schedules)): ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted">No class schedule available.</td></tr>
                    <?php else: ?>
                        <?php foreach ($schedules as $sch): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($sch['day_of_week']); ?></td>
                            <td><?php echo date('h:i A', strtotime($sch['start_time'])) . ' - ' . date('h:i A', strtotime($sch['end_time'])); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($sch['subject_code']); ?></strong>
                                <small class="text-muted d-block"><?php echo htmlspecialchars($sch['subject_name']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($sch['teacher_fname'] . ' ' . $sch['teacher_lname']); ?></td>
                            <td><?php echo htmlspecialchars($sch['room'] ?? '—'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php end_layout(); ?>
