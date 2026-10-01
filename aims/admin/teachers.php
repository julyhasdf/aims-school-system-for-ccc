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
        $empNum     = trim($_POST['employee_number'] ?? '');
        $firstName  = trim($_POST['first_name'] ?? '');
        $lastName   = trim($_POST['last_name'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $contact    = trim($_POST['contact_number'] ?? '');
        
        if ($empNum && $firstName && $lastName) {
            $mysqli->begin_transaction();
            try {
                // 1. Create User
                $username = $empNum; // Default username is employee number
                $password = $empNum; // Default password is employee number
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $fullName = $firstName . ' ' . $lastName;

                // Check if user exists
                $stmt = $mysqli->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->bind_param('s', $username);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    throw new Exception("User/Employee Number already exists.");
                }
                $stmt->close();

                $stmt = $mysqli->prepare("INSERT INTO users (full_name, username, password_hash, role, email, contact_number) VALUES (?, ?, ?, 'teacher', ?, ?)");
                $stmt->bind_param('sssss', $fullName, $username, $hash, $email, $contact);
                if (!$stmt->execute()) throw new Exception("Failed to create user: " . $stmt->error);
                $userId = $stmt->insert_id;
                $stmt->close();

                // 2. Create Teacher
                $stmt = $mysqli->prepare("INSERT INTO teachers (user_id, employee_number, first_name, last_name, department, email, contact_number) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('issssss', $userId, $empNum, $firstName, $lastName, $department, $email, $contact);
                if (!$stmt->execute()) throw new Exception("Failed to create teacher profile: " . $stmt->error);
                $stmt->close();

                $mysqli->commit();
                $success = "Teacher added successfully. Default password is the Employee Number.";
            } catch (Exception $e) {
                $mysqli->rollback();
                $error = $e->getMessage();
            }
        } else {
            $error = "Required fields missing.";
        }
    } elseif ($action === 'edit') {
        $id         = (int)($_POST['teacher_id'] ?? 0);
        $userId     = (int)($_POST['user_id'] ?? 0);
        $empNum     = trim($_POST['employee_number'] ?? '');
        $firstName  = trim($_POST['first_name'] ?? '');
        $lastName   = trim($_POST['last_name'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $contact    = trim($_POST['contact_number'] ?? '');

        if ($id && $userId && $empNum && $firstName && $lastName) {
            $mysqli->begin_transaction();
            try {
                $fullName = $firstName . ' ' . $lastName;
                
                // Update User
                $stmt = $mysqli->prepare("UPDATE users SET full_name=?, email=?, contact_number=? WHERE id=?");
                $stmt->bind_param('sssi', $fullName, $email, $contact, $userId);
                if (!$stmt->execute()) throw new Exception("Failed to update user info.");
                $stmt->close();

                // Update Teacher
                $stmt = $mysqli->prepare("UPDATE teachers SET employee_number=?, first_name=?, last_name=?, department=?, email=?, contact_number=? WHERE id=?");
                $stmt->bind_param('ssssssi', $empNum, $firstName, $lastName, $department, $email, $contact, $id);
                if (!$stmt->execute()) throw new Exception("Failed to update teacher profile.");
                $stmt->close();

                $mysqli->commit();
                $success = "Teacher updated successfully.";
            } catch (Exception $e) {
                $mysqli->rollback();
                $error = $e->getMessage();
            }
        } else {
            $error = "Required fields missing.";
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['teacher_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        
        if ($id) {
            $mysqli->begin_transaction();
            try {
                // If user_id is provided and valid, delete from users table (cascades)
                if ($userId > 0) {
                    $stmt = $mysqli->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->bind_param('i', $userId);
                    $stmt->execute();
                    $stmt->close();
                } 
                
                // Fallback: Delete from teachers table directly if user delete didn't happen or didn't cascade
                // Check if teacher still exists
                $check = $mysqli->query("SELECT id FROM teachers WHERE id = $id");
                if ($check && $check->num_rows > 0) {
                    $stmt = $mysqli->prepare("DELETE FROM teachers WHERE id = ?");
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $stmt->close();
                }

                $mysqli->commit();
                $success = "Teacher deleted successfully.";
            } catch (Exception $e) {
                $mysqli->rollback();
                $error = "Failed to delete teacher: " . $e->getMessage();
            }
        }
    }
}

// Fetch Teachers
$teachers = [];
$res = $mysqli->query("SELECT * FROM teachers ORDER BY created_at DESC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $teachers[] = $row;
    }
}

$sidebar = [
    ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'dashboard'],
    ['label' => 'Students', 'href' => 'students.php', 'icon' => 'students'],
    ['label' => 'Teachers', 'href' => 'teachers.php', 'active' => true, 'icon' => 'teachers'],
    ['label' => 'Subjects', 'href' => 'subjects.php', 'icon' => 'subjects'],
    ['label' => 'Schedules', 'href' => 'sections.php', 'icon' => 'sections'],
    ['label' => 'Users', 'href' => 'users.php', 'icon' => 'users'],
    ['label' => 'Announcements', 'href' => 'announcements.php', 'icon' => 'announcements'],
    ['label' => 'Reports', 'href' => 'reports.php', 'icon' => 'reports'],
];

start_layout('Teacher Management', 'AIMS - Admin', $_SESSION['username'] ?? 'Admin', $sidebar);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Teacher Management</h1>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-header bg-white py-3">
        <input type="text" id="searchInput" class="form-control" placeholder="Search teachers..." style="max-width: 300px;">
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="teachersTable">
                <thead class="table-light">
                    <tr>
                        <th>Employee ID</th>
                        <th>Full Name</th>
                        <th>Department</th>
                        <th>Email</th>
                        <th>Contact</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($teachers as $tch): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($tch['employee_number']); ?></td>
                        <td><?php echo htmlspecialchars($tch['first_name'] . ' ' . $tch['last_name']); ?></td>
                        <td><?php echo htmlspecialchars($tch['department'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($tch['email'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($tch['contact_number'] ?? ''); ?></td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary me-1 edit-teacher-btn" 
                                data-id="<?php echo $tch['id']; ?>"
                                data-userid="<?php echo $tch['user_id']; ?>"
                                data-empnum="<?php echo htmlspecialchars($tch['employee_number']); ?>"
                                data-firstname="<?php echo htmlspecialchars($tch['first_name']); ?>"
                                data-lastname="<?php echo htmlspecialchars($tch['last_name']); ?>"
                                data-department="<?php echo htmlspecialchars($tch['department'] ?? ''); ?>"
                                data-email="<?php echo htmlspecialchars($tch['email'] ?? ''); ?>"
                                data-contact="<?php echo htmlspecialchars($tch['contact_number'] ?? ''); ?>"
                                data-bs-toggle="modal" data-bs-target="#editTeacherModal">
                                Edit
                            </button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('WARNING: Are you sure you want to delete this teacher?\n\nThis action will PERMANENTLY DELETE:\n- The teacher account\n- Their profile information\n- Assigned subject connections\n\nThis cannot be undone.');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="teacher_id" value="<?php echo $tch['id']; ?>">
                                <input type="hidden" name="user_id" value="<?php echo $tch['user_id']; ?>">
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

<!-- Add Teacher Modal -->
<div class="modal fade" id="addTeacherModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="add">
            <div class="modal-header">
                <h5 class="modal-title">Add New Teacher</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Employee Number *</label>
                        <input type="text" name="employee_number" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Department</label>
                        <input type="text" name="department" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">First Name *</label>
                        <input type="text" name="first_name" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Last Name *</label>
                        <input type="text" name="last_name" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Contact Number</label>
                        <input type="text" name="contact_number" class="form-control">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-control">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Teacher</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Teacher Modal -->
<div class="modal fade" id="editTeacherModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="teacher_id" id="edit_teacher_id">
            <input type="hidden" name="user_id" id="edit_user_id">
            <div class="modal-header">
                <h5 class="modal-title">Edit Teacher</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Employee Number *</label>
                        <input type="text" name="employee_number" id="edit_employee_number" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Department</label>
                        <input type="text" name="department" id="edit_department" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">First Name *</label>
                        <input type="text" name="first_name" id="edit_first_name" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Last Name *</label>
                        <input type="text" name="last_name" id="edit_last_name" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Contact Number</label>
                        <input type="text" name="contact_number" id="edit_contact_number" class="form-control">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" id="edit_email" class="form-control">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Teacher</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Search Functionality
    const searchInput = document.getElementById('searchInput');
    const table = document.getElementById('teachersTable');
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

    // Edit Modal Handler (Bootstrap 5 Event)
    var editModal = document.getElementById('editTeacherModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            if (button) {
                document.getElementById('edit_teacher_id').value = button.getAttribute('data-id');
                document.getElementById('edit_user_id').value = button.getAttribute('data-userid');
                document.getElementById('edit_employee_number').value = button.getAttribute('data-empnum');
                document.getElementById('edit_first_name').value = button.getAttribute('data-firstname');
                document.getElementById('edit_last_name').value = button.getAttribute('data-lastname');
                document.getElementById('edit_department').value = button.getAttribute('data-dept');
                document.getElementById('edit_email').value = button.getAttribute('data-email');
                document.getElementById('edit_contact_number').value = button.getAttribute('data-contact');
            }
        });
    }
});
</script>

<?php end_layout(); ?>
