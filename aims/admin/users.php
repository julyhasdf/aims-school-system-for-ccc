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
        $fullname = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? 'student';
        $email    = trim($_POST['email'] ?? '');
        $contact  = trim($_POST['contact_number'] ?? '');
        
        if ($fullname && $username && $password && $role) {
            $stmt = $mysqli->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->bind_param('s', $username);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = "Username already exists.";
            } else {
                $stmt->close();
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $mysqli->prepare("INSERT INTO users (full_name, username, password_hash, role, email, contact_number) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('ssssss', $fullname, $username, $hash, $role, $email, $contact);
                if ($stmt->execute()) {
                    $success = "User added successfully.";
                } else {
                    $error = "Failed to add user: " . $stmt->error;
                }
            }
            if(isset($stmt)) $stmt->close();
        } else {
            $error = "All required fields must be filled.";
        }
    } elseif ($action === 'edit') {
        $id       = (int)($_POST['user_id'] ?? 0);
        $fullname = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $role     = $_POST['role'] ?? 'student';
        $email    = trim($_POST['email'] ?? '');
        $contact  = trim($_POST['contact_number'] ?? '');
        $password = $_POST['password'] ?? ''; // Optional
        
        if ($id && $fullname && $username && $role) {
            // Check unique username excluding self
            $stmt = $mysqli->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->bind_param('si', $username, $id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = "Username already exists.";
            } else {
                $stmt->close();
                
                // Get current role before update
                $currentRole = '';
                $roleStmt = $mysqli->prepare("SELECT role FROM users WHERE id = ?");
                $roleStmt->bind_param('i', $id);
                $roleStmt->execute();
                $roleStmt->bind_result($currentRole);
                $roleStmt->fetch();
                $roleStmt->close();

                if (!empty($password)) {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $mysqli->prepare("UPDATE users SET full_name=?, username=?, password_hash=?, role=?, email=?, contact_number=? WHERE id=?");
                    $stmt->bind_param('ssssssi', $fullname, $username, $hash, $role, $email, $contact, $id);
                } else {
                    $stmt = $mysqli->prepare("UPDATE users SET full_name=?, username=?, role=?, email=?, contact_number=? WHERE id=?");
                    $stmt->bind_param('sssssi', $fullname, $username, $role, $email, $contact, $id);
                }
                
                if ($stmt->execute()) {
                    $success = "User updated successfully.";
                    
                    // Handle Role Change Logic
                    if ($currentRole !== $role) {
                        // 1. Delete old profile
                        if ($currentRole === 'student') {
                            $delOld = $mysqli->prepare("DELETE FROM students WHERE user_id = ?");
                            $delOld->bind_param('i', $id);
                            $delOld->execute();
                            $delOld->close();
                        } elseif ($currentRole === 'teacher') {
                            $delOld = $mysqli->prepare("DELETE FROM teachers WHERE user_id = ?");
                            $delOld->bind_param('i', $id);
                            $delOld->execute();
                            $delOld->close();
                        }

                        // 2. Create new profile if needed
                        $nameParts = explode(' ', $fullname, 2);
                        $fName = $nameParts[0];
                        $lName = $nameParts[1] ?? 'User';

                        if ($role === 'student') {
                            // Check if already exists (shouldn't, but safe check)
                            $chk = $mysqli->query("SELECT id FROM students WHERE user_id = $id");
                            if ($chk->num_rows === 0) {
                                // Generate Student Number
                                $year = date('Y');
                                $seq = 1;
                                $seqRes = $mysqli->query("SELECT student_number FROM students WHERE student_number LIKE '$year-%' ORDER BY student_number DESC LIMIT 1");
                                if ($row = $seqRes->fetch_assoc()) {
                                    $parts = explode('-', $row['student_number']);
                                    $seq = (int)($parts[1] ?? 0) + 1;
                                }
                                $sNum = $year . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
                                
                                $newP = $mysqli->prepare("INSERT INTO students (user_id, student_number, first_name, last_name, grade_level, email, contact_number) VALUES (?, ?, ?, ?, 'Grade 7', ?, ?)");
                                $newP->bind_param('isssss', $id, $sNum, $fName, $lName, $email, $contact);
                                $newP->execute();
                                $newP->close();
                            }
                        } elseif ($role === 'teacher') {
                            $chk = $mysqli->query("SELECT id FROM teachers WHERE user_id = $id");
                            if ($chk->num_rows === 0) {
                                // Generate Employee Number (E + Year + Random/Seq)
                                // Simple approach: E-YYYY-ID
                                $eNum = 'E-' . date('Y') . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
                                
                                $newP = $mysqli->prepare("INSERT INTO teachers (user_id, employee_number, first_name, last_name, email, contact_number) VALUES (?, ?, ?, ?, ?, ?)");
                                $newP->bind_param('isssss', $id, $eNum, $fName, $lName, $email, $contact);
                                $newP->execute();
                                $newP->close();
                            }
                        }
                    }
                } else {
                    $error = "Failed to update user: " . $stmt->error;
                }
            }
            if(isset($stmt)) $stmt->close();
        } else {
            $error = "Required fields missing.";
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['user_id'] ?? 0);
        if ($id) {
            // Prevent deleting self
            if ($id === (int)$_SESSION['user_id']) {
                $error = "You cannot delete your own account.";
            } else {
                $stmt = $mysqli->prepare("DELETE FROM users WHERE id = ?");
                $stmt->bind_param('i', $id);
                if ($stmt->execute()) {
                    $success = "User deleted successfully.";
                } else {
                    $error = "Failed to delete user.";
                }
                $stmt->close();
            }
        }
    }
}

// Fetch Users
$users = [];
$res = $mysqli->query("SELECT * FROM users ORDER BY created_at DESC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $users[] = $row;
    }
}

$sidebar = [
    ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'dashboard'],
    ['label' => 'Students', 'href' => 'students.php', 'icon' => 'students'],
    ['label' => 'Teachers', 'href' => 'teachers.php', 'icon' => 'teachers'],
    ['label' => 'Subjects', 'href' => 'subjects.php', 'icon' => 'subjects'],
    ['label' => 'Schedules', 'href' => 'sections.php', 'icon' => 'sections'],
    ['label' => 'Users', 'href' => 'users.php', 'active' => true, 'icon' => 'users'],
    ['label' => 'Announcements', 'href' => 'announcements.php', 'icon' => 'announcements'],
    ['label' => 'Reports', 'href' => 'reports.php', 'icon' => 'reports'],
];

start_layout('User Management', 'AIMS - Admin', $_SESSION['username'] ?? 'Admin', $sidebar);
?>

<style>
    .sortable-col { cursor: pointer; user-select: none; white-space: nowrap; }
    .sortable-col:hover { color: #0d6efd; }
    .sort-arrow { display: inline-block; width: 1em; opacity: .5; font-size: .75em; }
    .sortable-col.sort-asc .sort-arrow, .sortable-col.sort-desc .sort-arrow { opacity: 1; color: #0d6efd; }
    .sortable-col.sort-asc .sort-arrow::after { content: '\25B2'; }   /* ▲ */
    .sortable-col.sort-desc .sort-arrow::after { content: '\25BC'; } /* ▼ */
    .sortable-col:not(.sort-asc):not(.sort-desc) .sort-arrow::after { content: '\25B4\25BE'; } /* neutral hint */
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">User Management</h1>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-header bg-white py-3">
        <div class="row g-3">
            <div class="col-md-4">
                <input type="text" id="searchInput" class="form-control" placeholder="Search users...">
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="usersTable">
                <thead>
                    <tr>
                        <th class="sortable-col" data-sort-col="0" data-sort-type="text">Full Name <span class="sort-arrow"></span></th>
                        <th class="sortable-col" data-sort-col="1" data-sort-type="text">Username <span class="sort-arrow"></span></th>
                        <th class="sortable-col" data-sort-col="2" data-sort-type="text">Role <span class="sort-arrow"></span></th>
                        <th>Email</th>
                        <th>Contact</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($user['full_name'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                        <td>
                            <?php 
                            $role = $user['role'];
                            $badgeClass = match($role) {
                                'admin' => 'bg-primary',
                                'teacher' => 'bg-warning text-dark',
                                'student' => 'bg-success',
                                default => 'bg-secondary'
                            };
                            ?>
                            <span class="badge <?php echo $badgeClass; ?>"><?php echo ucfirst($role); ?></span>
                        </td>
                        <td><?php echo htmlspecialchars($user['email'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($user['contact_number'] ?? ''); ?></td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary me-1 edit-user-btn" 
                                data-id="<?php echo $user['id']; ?>"
                                data-fullname="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>"
                                data-username="<?php echo htmlspecialchars($user['username']); ?>"
                                data-role="<?php echo $user['role']; ?>"
                                data-email="<?php echo htmlspecialchars($user['email'] ?? ''); ?>"
                                data-contact="<?php echo htmlspecialchars($user['contact_number'] ?? ''); ?>"
                                data-bs-toggle="modal" data-bs-target="#editUserModal">
                                Edit
                            </button>
                            <button class="btn btn-sm btn-outline-danger delete-user-btn"
                                data-id="<?php echo $user['id']; ?>"
                                data-username="<?php echo htmlspecialchars($user['username']); ?>"
                                data-bs-toggle="modal" data-bs-target="#deleteUserModal">
                                Delete
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="add">
            <div class="modal-header">
                <h5 class="modal-title">Add New User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Full Name *</label>
                    <input type="text" name="full_name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Username *</label>
                    <input type="text" name="username" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Password *</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Role *</label>
                    <select name="role" class="form-select" required>
                        <option value="student">Student</option>
                        <option value="teacher">Teacher</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label">Contact Number</label>
                    <input type="text" name="contact_number" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save User</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="user_id" id="edit_user_id">
            <div class="modal-header">
                <h5 class="modal-title">Edit User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Full Name *</label>
                    <input type="text" name="full_name" id="edit_full_name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Username *</label>
                    <input type="text" name="username" id="edit_username" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Password (Leave blank to keep current)</label>
                    <input type="password" name="password" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label">Role *</label>
                    <select name="role" id="edit_role" class="form-select" required>
                        <option value="student">Student</option>
                        <option value="teacher">Teacher</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" id="edit_email" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label">Contact Number</label>
                    <input type="text" name="contact_number" id="edit_contact" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Update User</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete User Modal -->
<div class="modal fade" id="deleteUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user_id" id="delete_user_id">
            <div class="modal-header">
                <h5 class="modal-title">Delete User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete user <strong id="delete_username"></strong>?</p>
                <p class="text-danger small">This action cannot be undone.</p>
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
    const table = document.getElementById('usersTable');
    if (searchInput && table) {
        searchInput.addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const rows = table.getElementsByTagName('tr');
            for (let i = 1; i < rows.length; i++) { // Skip header
                const cells = rows[i].getElementsByTagName('td');
                let match = false;
                for (let j = 0; j < cells.length - 1; j++) { // Skip Actions col
                    if (cells[j].textContent.toLowerCase().indexOf(filter) > -1) {
                        match = true;
                        break;
                    }
                }
                rows[i].style.display = match ? '' : 'none';
            }
        });
    }

    // Edit User Handler
    document.querySelectorAll('.edit-user-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('edit_user_id').value = this.dataset.id;
            document.getElementById('edit_full_name').value = this.dataset.fullname;
            document.getElementById('edit_username').value = this.dataset.username;
            document.getElementById('edit_role').value = this.dataset.role;
            document.getElementById('edit_email').value = this.dataset.email;
            document.getElementById('edit_contact').value = this.dataset.contact;
        });
    });

    // Delete User Handler
    document.querySelectorAll('.delete-user-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('delete_user_id').value = this.dataset.id;
            document.getElementById('delete_username').textContent = this.dataset.username;
        });
    });

    // Sortable Columns (Full Name, Username, Role)
    var usersTable = document.getElementById('usersTable');
    if (usersTable) {
        var tbody = usersTable.querySelector('tbody');
        var headers = usersTable.querySelectorAll('.sortable-col');

        headers.forEach(function(th) {
            th.addEventListener('click', function() {
                var colIndex = parseInt(th.dataset.sortCol, 10);
                var isAsc = !th.classList.contains('sort-asc');

                // Reset arrow state on all sortable headers, then mark the active one
                headers.forEach(function(h) { h.classList.remove('sort-asc', 'sort-desc'); });
                th.classList.add(isAsc ? 'sort-asc' : 'sort-desc');

                var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
                rows.sort(function(rowA, rowB) {
                    var cellA = rowA.children[colIndex].textContent.trim().toLowerCase();
                    var cellB = rowB.children[colIndex].textContent.trim().toLowerCase();
                    if (cellA < cellB) return isAsc ? -1 : 1;
                    if (cellA > cellB) return isAsc ? 1 : -1;
                    return 0;
                });

                rows.forEach(function(row) { tbody.appendChild(row); });
            });
        });
    }
});
</script>

<?php end_layout(); ?>
