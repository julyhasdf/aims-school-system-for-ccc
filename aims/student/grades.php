<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_role('student');
require_once __DIR__ . '/../includes/layout.php';

$studentId = 0;
$grades = [];

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
    // Fetch Grades per Subject
    // We want Subject Name, Grading Period, Grade
    $query = "
        SELECT sub.subject_code, sub.subject_name, 
               gp.name as period_name, 
               g.grade, g.remarks
        FROM grades g
        JOIN teacher_subjects ts ON g.teacher_subject_id = ts.id
        JOIN subjects sub ON ts.subject_id = sub.id
        JOIN grading_periods gp ON g.grading_period_id = gp.id
        WHERE g.student_id = ?
        ORDER BY sub.subject_code, gp.id
    ";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $grades[$row['subject_code']][] = $row;
    }
    $stmt->close();
}

$sidebar = [
    ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'dashboard'],
    ['label' => 'My Grades', 'href' => 'grades.php', 'active' => true, 'icon' => 'grades'],
    ['label' => 'My Schedule', 'href' => 'dashboard.php', 'icon' => 'classes'],
    ['label' => 'My Attendance', 'href' => 'attendance.php', 'icon' => 'attendance'],
];

start_layout('My Grades', 'AIMS - Student', $_SESSION['username'] ?? '', $sidebar);
?>

<h1 class="h3 mb-4">My Academic Performance</h1>

<?php if (empty($grades)): ?>
    <div class="alert alert-info">No grades recorded yet.</div>
<?php else: ?>
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Subject</th>
                            <th>Grading Period</th>
                            <th>Grade</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grades as $subjCode => $items): ?>
                            <?php foreach ($items as $idx => $g): ?>
                            <tr>
                                <?php if ($idx === 0): ?>
                                <td rowspan="<?php echo count($items); ?>" class="align-middle border-end bg-light">
                                    <strong><?php echo htmlspecialchars($g['subject_code']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($g['subject_name']); ?></small>
                                </td>
                                <?php endif; ?>
                                <td><?php echo htmlspecialchars($g['period_name']); ?></td>
                                <td class="fw-bold">
                                    <?php 
                                        echo number_format((float)$g['grade'], 2); 
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                        if ((float)$g['grade'] >= 75) {
                                            echo '<span class="badge bg-success">Passed</span>';
                                        } else {
                                            echo '<span class="badge bg-danger">Failed</span>';
                                        }
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php end_layout(); ?>
