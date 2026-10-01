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

    if ($action === 'add') {
        $code  = trim($_POST['subject_code'] ?? '');
        $name  = trim($_POST['subject_name'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $units = (float)($_POST['units'] ?? 0);
        $grade = trim($_POST['grade_level'] ?? '');
        
        if ($code && $name) {
            $stmt = $mysqli->prepare("INSERT INTO subjects (subject_code, subject_name, description, units, grade_level) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('sssds', $code, $name, $desc, $units, $grade);
            if ($stmt->execute()) {
                $success = "Subject added successfully.";
            } else {
                $error = "Failed to add subject: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error = "Subject Code and Name are required.";
        }
    } elseif ($action === 'edit') {
        $id    = (int)($_POST['subject_id'] ?? 0);
        $code  = trim($_POST['subject_code'] ?? '');
        $name  = trim($_POST['subject_name'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $units = (float)($_POST['units'] ?? 0);
        $grade = trim($_POST['grade_level'] ?? '');
        
        if ($id && $code && $name) {
            $stmt = $mysqli->prepare("UPDATE subjects SET subject_code=?, subject_name=?, description=?, units=?, grade_level=? WHERE id=?");
            $stmt->bind_param('sssdsi', $code, $name, $desc, $units, $grade, $id);
            if ($stmt->execute()) {
                $success = "Subject updated successfully.";
            } else {
                $error = "Failed to update subject: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error = "Required fields missing.";
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['subject_id'] ?? 0);
        if ($id) {
            $stmt = $mysqli->prepare("DELETE FROM subjects WHERE id = ?");
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
                $success = "Subject deleted successfully.";
            } else {
                $error = "Failed to delete subject (may be assigned to teachers).";
            }
            $stmt->close();
        }
    }
}

// Fetch Subjects
$subjects = [];
$res = $mysqli->query("SELECT * FROM subjects ORDER BY subject_code ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $subjects[] = $row;
    }
}

$sidebar = [
    ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'dashboard'],
    ['label' => 'Students', 'href' => 'students.php', 'icon' => 'students'],
    ['label' => 'Teachers', 'href' => 'teachers.php', 'icon' => 'teachers'],
    ['label' => 'Subjects', 'href' => 'subjects.php', 'active' => true, 'icon' => 'subjects'],
    ['label' => 'Schedules', 'href' => 'sections.php', 'icon' => 'sections'],
    ['label' => 'Users', 'href' => 'users.php', 'icon' => 'users'],
    ['label' => 'Announcements', 'href' => 'announcements.php', 'icon' => 'announcements'],
    ['label' => 'Reports', 'href' => 'reports.php', 'icon' => 'reports'],
];

start_layout('Subject Management', 'AIMS - Admin', $_SESSION['username'] ?? 'Admin', $sidebar);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Subject Management</h1>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSubjectModal">
        <?php echo icon('subjects'); ?> Add Subject
    </button>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-header bg-white py-3">
        <input type="text" id="searchInput" class="form-control" placeholder="Search subjects..." style="max-width: 300px;">
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="subjectsTable">
                <thead class="table-light">
                    <tr>
                        <th>Subject Code</th>
                        <th>Subject Name</th>
                        <th>Description</th>
                        <th>Units</th>
                        <th>Grade Level</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subjects as $sub): ?>
                    <tr>
                        <td class="fw-bold"><?php echo htmlspecialchars($sub['subject_code']); ?></td>
                        <td><?php echo htmlspecialchars($sub['subject_name']); ?></td>
                        <td><?php echo htmlspecialchars($sub['description'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars((string)$sub['units']); ?></td>
                        <td><?php echo htmlspecialchars($sub['grade_level'] ?? '—'); ?></td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary me-1 edit-subject-btn" 
                                data-id="<?php echo $sub['id']; ?>"
                                data-code="<?php echo htmlspecialchars($sub['subject_code']); ?>"
                                data-name="<?php echo htmlspecialchars($sub['subject_name']); ?>"
                                data-desc="<?php echo htmlspecialchars($sub['description'] ?? ''); ?>"
                                data-units="<?php echo htmlspecialchars((string)$sub['units']); ?>"
                                data-grade="<?php echo htmlspecialchars($sub['grade_level'] ?? ''); ?>"
                                data-bs-toggle="modal" data-bs-target="#editSubjectModal">
                                Edit
                            </button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('WARNING: Are you sure you want to delete this subject?\n\nThis will remove it from all curriculum lists.\n\nNote: If this subject is assigned to teachers or has grades, deletion might be restricted or affect those records.');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="subject_id" value="<?php echo $sub['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Subject Modal -->
<div class="modal fade" id="addSubjectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="add">
            <div class="modal-header">
                <h5 class="modal-title">Add New Subject</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Subject Code *</label>
                    <input type="text" name="subject_code" class="form-control" placeholder="e.g. MATH101" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Subject Name *</label>
                    <input type="text" name="subject_name" class="form-control" placeholder="e.g. Algebra" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="2"></textarea>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Units</label>
                        <input type="number" step="0.1" name="units" class="form-control" value="1.0">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Grade Level</label>
                        <select name="grade_level" class="form-select">
                            <option value="">Any</option>
                            <option value="Grade 7">Grade 7</option>
                            <option value="Grade 8">Grade 8</option>
                            <option value="Grade 9">Grade 9</option>
                            <option value="Grade 10">Grade 10</option>
                            <option value="Grade 11">Grade 11</option>
                            <option value="Grade 12">Grade 12</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Subject</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Subject Modal -->
<div class="modal fade" id="editSubjectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="subject_id" id="edit_subject_id">
            <div class="modal-header">
                <h5 class="modal-title">Edit Subject</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Subject Code *</label>
                    <input type="text" name="subject_code" id="edit_subject_code" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Subject Name *</label>
                    <input type="text" name="subject_name" id="edit_subject_name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="edit_description" class="form-control" rows="2"></textarea>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Units</label>
                        <input type="number" step="0.1" name="units" id="edit_units" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Grade Level</label>
                        <select name="grade_level" id="edit_grade_level" class="form-select">
                            <option value="">Any</option>
                            <option value="Grade 7">Grade 7</option>
                            <option value="Grade 8">Grade 8</option>
                            <option value="Grade 9">Grade 9</option>
                            <option value="Grade 10">Grade 10</option>
                            <option value="Grade 11">Grade 11</option>
                            <option value="Grade 12">Grade 12</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Subject</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Subject Modal -->
<div class="modal fade" id="deleteSubjectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="subject_id" id="delete_subject_id">
            <div class="modal-header">
                <h5 class="modal-title">Delete Subject</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete subject <strong id="delete_subject_code"></strong>?</p>
                <p class="text-danger small">This action cannot be undone. You cannot delete a subject that is currently assigned to a teacher/section.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Search Functionality
    const searchInput = document.getElementById('searchInput');
    const table = document.getElementById('subjectsTable');
    if (searchInput && table) {
        searchInput.addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const rows = table.getElementsByTagName('tr');
            for (let i = 1; i < rows.length; i++) {
                const cells = rows[i].getElementsByTagName('td');
                let match = false;
                for (let j = 0; j < cells.length - 1; j++) {
                    if (cells[j].textContent.toLowerCase().indexOf(filter) > -1) {
                        match = true;
                        break;
                    }
                }
                rows[i].style.display = match ? '' : 'none';
            }
        });
    }

    // Edit Subject Handler
    document.querySelectorAll('.edit-subject-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('edit_subject_id').value = this.dataset.id;
            document.getElementById('edit_subject_code').value = this.dataset.code;
            document.getElementById('edit_subject_name').value = this.dataset.name;
            document.getElementById('edit_description').value = this.dataset.desc;
            document.getElementById('edit_units').value = this.dataset.units;
            document.getElementById('edit_grade_level').value = this.dataset.grade;
        });
    });

    // Delete Subject Handler
    document.querySelectorAll('.delete-subject-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('delete_subject_id').value = this.dataset.id;
            document.getElementById('delete_subject_code').textContent = this.dataset.code;
        });
    });
});
</script>

<?php end_layout(); ?>
