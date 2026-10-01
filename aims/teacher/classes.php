<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_role('teacher');
require_once __DIR__ . '/../includes/layout.php';

$error = '';
$classes = [];
$teacherId = 0;

if (isset($_SESSION['user_id'])) {
    $uid = (int) $_SESSION['user_id'];
    // Get Teacher ID
    $stmt = $mysqli->prepare("SELECT id FROM teachers WHERE user_id = ?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $teacherId = (int)$row['id'];
    }
    $stmt->close();

    if ($teacherId) {
        // Fetch Assigned Subjects (Classes)
        // Groups students count per class
        $query = "
            SELECT ts.id as ts_id, 
                   sub.subject_code, sub.subject_name, 
                   sec.section_name, sec.grade_level,
                   (SELECT COUNT(*) FROM students WHERE section_id = sec.id) as student_count
            FROM teacher_subjects ts
            JOIN subjects sub ON ts.subject_id = sub.id
            JOIN sections sec ON ts.section_id = sec.id
            WHERE ts.teacher_id = ?
            ORDER BY sec.section_name, sub.subject_code
        ";
        $stmt = $mysqli->prepare($query);
        $stmt->bind_param('i', $teacherId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $classes[] = $row;
        }
        $stmt->close();
    }
}

$sidebar = [
    ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'dashboard'],
    ['label' => 'My Classes', 'href' => 'classes.php', 'active' => true, 'icon' => 'classes'],
    ['label' => 'Attendance', 'href' => 'attendance.php', 'icon' => 'attendance'],
    ['label' => 'Grades', 'href' => 'grades.php', 'icon' => 'grade_book'],
    ['label' => 'Reports', 'href' => 'reports.php', 'icon' => 'reports'],
];

start_layout('My Classes', 'AIMS - Teacher', $_SESSION['username'] ?? '', $sidebar);
?>

<h1 class="h3 mb-4">My Classes</h1>

<?php if (empty($classes)): ?>
    <div class="alert alert-info">You have no assigned classes yet.</div>
<?php else: ?>
    <div class="row g-4">
        <?php foreach ($classes as $class): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 text-primary"><?php echo htmlspecialchars($class['subject_code']); ?></h5>
                    <span class="badge bg-secondary"><?php echo htmlspecialchars($class['grade_level']); ?></span>
                </div>
                <div class="card-body">
                    <h6 class="card-title"><?php echo htmlspecialchars($class['subject_name']); ?></h6>
                    <p class="card-text mb-1">Section: <strong><?php echo htmlspecialchars($class['section_name']); ?></strong></p>
                    <p class="card-text text-muted">Students: <?php echo $class['student_count']; ?></p>
                </div>
                <div class="card-footer bg-white border-top-0 d-flex justify-content-between">
                    <a href="grades.php?ts_id=<?php echo $class['ts_id']; ?>" class="btn btn-sm btn-outline-success">Grades</a>
                    <a href="attendance.php?ts_id=<?php echo $class['ts_id']; ?>" class="btn btn-sm btn-outline-warning">Attendance</a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php end_layout(); ?>
