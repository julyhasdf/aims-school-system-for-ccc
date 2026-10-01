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
        $studentNum = trim($_POST['student_number'] ?? '');
        $firstName  = trim($_POST['first_name'] ?? '');
        $lastName   = trim($_POST['last_name'] ?? '');
        $gradeLevel = trim($_POST['grade_level'] ?? '');
        $sectionId  = (int)($_POST['section_id'] ?? 0);
        $email      = trim($_POST['email'] ?? '');
        $contact    = trim($_POST['contact_number'] ?? '');
        
        if ($studentNum && $firstName && $lastName && $gradeLevel) {
            $mysqli->begin_transaction();
            try {
                // 1. Create User
                $username = $studentNum; // Default username is student number
                $password = $studentNum; // Default password is student number
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $fullName = $firstName . ' ' . $lastName;
                
                // Check if user exists
                $stmt = $mysqli->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->bind_param('s', $username);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    throw new Exception("User/Student Number already exists.");
                }
                $stmt->close();

                $stmt = $mysqli->prepare("INSERT INTO users (full_name, username, password_hash, role, email, contact_number) VALUES (?, ?, ?, 'student', ?, ?)");
                $stmt->bind_param('sssss', $fullName, $username, $hash, $email, $contact);
                if (!$stmt->execute()) throw new Exception("Failed to create user: " . $stmt->error);
                $userId = $stmt->insert_id;
                $stmt->close();

                // 2. Create Student
                $stmt = $mysqli->prepare("INSERT INTO students (user_id, student_number, first_name, last_name, grade_level, section_id, contact_number) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('issssis', $userId, $studentNum, $firstName, $lastName, $gradeLevel, $sectionId, $contact);
                if (!$stmt->execute()) throw new Exception("Failed to create student profile: " . $stmt->error);
                $stmt->close();

                $mysqli->commit();
                $success = "Student added successfully. Default password is the Student Number.";
            } catch (Exception $e) {
                $mysqli->rollback();
                $error = $e->getMessage();
            }
        } else {
            $error = "Required fields missing.";
        }
    } elseif ($action === 'edit') {
        $id         = (int)($_POST['student_id'] ?? 0);
        $userId     = (int)($_POST['user_id'] ?? 0);
        $studentNum = trim($_POST['student_number'] ?? '');
        $firstName  = trim($_POST['first_name'] ?? '');
        $lastName   = trim($_POST['last_name'] ?? '');
        $gradeLevel = trim($_POST['grade_level'] ?? '');
        $sectionId  = (int)($_POST['section_id'] ?? 0);
        $email      = trim($_POST['email'] ?? '');
        $contact    = trim($_POST['contact_number'] ?? '');
        
        if ($id && $userId && $studentNum && $firstName && $lastName) {
            $mysqli->begin_transaction();
            try {
                $fullName = $firstName . ' ' . $lastName;
                
                // Update User
                $stmt = $mysqli->prepare("UPDATE users SET full_name=?, email=?, contact_number=? WHERE id=?");
                $stmt->bind_param('sssi', $fullName, $email, $contact, $userId);
                if (!$stmt->execute()) throw new Exception("Failed to update user info.");
                $stmt->close();

                // Update Student
                $stmt = $mysqli->prepare("UPDATE students SET student_number=?, first_name=?, last_name=?, grade_level=?, section_id=?, contact_number=? WHERE id=?");
                $stmt->bind_param('ssssisi', $studentNum, $firstName, $lastName, $gradeLevel, $sectionId, $contact, $id);
                if (!$stmt->execute()) throw new Exception("Failed to update student profile.");
                $stmt->close();

                $mysqli->commit();
                $success = "Student updated successfully.";
            } catch (Exception $e) {
                $mysqli->rollback();
                $error = $e->getMessage();
            }
        } else {
            $error = "Required fields missing.";
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['student_id'] ?? 0);
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
                
                // Fallback: Delete from students table directly if user delete didn't happen or didn't cascade
                // Check if student still exists
                $check = $mysqli->query("SELECT id FROM students WHERE id = $id");
                if ($check && $check->num_rows > 0) {
                    $stmt = $mysqli->prepare("DELETE FROM students WHERE id = ?");
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $stmt->close();
                }

                $mysqli->commit();
                $success = "Student deleted successfully.";
            } catch (Exception $e) {
                $mysqli->rollback();
                $error = "Failed to delete student: " . $e->getMessage();
            }
        }
    }
}

// Fetch Students
$students = [];
$res = $mysqli->query("
    SELECT s.*, u.email, sec.section_name 
    FROM students s 
    LEFT JOIN users u ON s.user_id = u.id 
    LEFT JOIN sections sec ON s.section_id = sec.id 
    ORDER BY s.created_at DESC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $students[] = $row;
    }
}

// Fetch Sections for dropdown (include grade_level so we can filter client-side)
$sections = [];
$resSec = $mysqli->query("SELECT id, section_name, grade_level FROM sections ORDER BY grade_level ASC, section_name ASC");
if ($resSec) {
    while ($row = $resSec->fetch_assoc()) {
        $sections[] = $row;
    }
}

$sidebar = [
    ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'dashboard'],
    ['label' => 'Students', 'href' => 'students.php', 'active' => true, 'icon' => 'students'],
    ['label' => 'Teachers', 'href' => 'teachers.php', 'icon' => 'teachers'],
    ['label' => 'Subjects', 'href' => 'subjects.php', 'icon' => 'subjects'],
    ['label' => 'Schedules', 'href' => 'sections.php', 'icon' => 'sections'],
    ['label' => 'Users', 'href' => 'users.php', 'icon' => 'users'],
    ['label' => 'Announcements', 'href' => 'announcements.php', 'icon' => 'announcements'],
    ['label' => 'Reports', 'href' => 'reports.php', 'icon' => 'reports'],
];

start_layout('Student Management', 'AIMS - Admin', $_SESSION['username'] ?? 'Admin', $sidebar);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Student Management</h1>
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
                <input type="text" id="searchInput" class="form-control" placeholder="Search students...">
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="studentsTable">
                <thead class="table-light">
                    <tr>
                        <th>Student No.</th>
                        <th>Full Name</th>
                        <th>Grade</th>
                        <th>Section</th>
                        <th>Email</th>
                        <th>Contact</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $std): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($std['student_number']); ?></td>
                        <td><?php echo htmlspecialchars($std['first_name'] . ' ' . $std['last_name']); ?></td>
                        <td><?php echo htmlspecialchars($std['grade_level']); ?></td>
                        <td><?php echo htmlspecialchars($std['section_name'] ?? 'None'); ?></td>
                        <td><?php echo htmlspecialchars($std['email'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($std['contact_number'] ?? ''); ?></td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary me-1 edit-student-btn" 
                                data-id="<?php echo $std['id']; ?>"
                                data-userid="<?php echo $std['user_id']; ?>"
                                data-stdnum="<?php echo htmlspecialchars($std['student_number']); ?>"
                                data-firstname="<?php echo htmlspecialchars($std['first_name']); ?>"
                                data-lastname="<?php echo htmlspecialchars($std['last_name']); ?>"
                                data-grade="<?php echo htmlspecialchars($std['grade_level']); ?>"
                                data-section="<?php echo $std['section_id']; ?>"
                                data-email="<?php echo htmlspecialchars($std['email'] ?? ''); ?>"
                                data-contact="<?php echo htmlspecialchars($std['contact_number'] ?? ''); ?>"
                                data-bs-toggle="modal" data-bs-target="#editStudentModal">
                                Edit
                            </button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('WARNING: Are you sure you want to delete this student?\n\nThis action will PERMANENTLY DELETE:\n- The student account\n- Their profile information\n- All their grades and records\n\nThis cannot be undone.');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="student_id" value="<?php echo $std['id']; ?>">
                                <input type="hidden" name="user_id" value="<?php echo $std['user_id']; ?>">
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


<!-- Add Student Modal -->
<div class="modal fade" id="addStudentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="add">
            <div class="modal-header">
                <h5 class="modal-title">Add New Student</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Student Number *</label>
                        <input type="text" name="student_number" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Grade Level *</label>
                        <select name="grade_level" id="add_grade_level" class="form-select" required>
                            <option value="">Select Grade</option>
                            <option value="Grade 7">Grade 7</option>
                            <option value="Grade 8">Grade 8</option>
                            <option value="Grade 9">Grade 9</option>
                            <option value="Grade 10">Grade 10</option>
                            <option value="Grade 11">Grade 11</option>
                            <option value="Grade 12">Grade 12</option>
                        </select>
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
                        <label class="form-label">Section</label>
                        <select name="section_id" id="add_section_id" class="form-select" disabled>
                            <option value="0">Select a grade level first</option>
                        </select>
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
                <button type="submit" class="btn btn-primary">Save Student</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Student Modal -->
<div class="modal fade" id="editStudentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="student_id" id="edit_student_id">
            <input type="hidden" name="user_id" id="edit_user_id">
            <div class="modal-header">
                <h5 class="modal-title">Edit Student</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Student Number *</label>
                        <input type="text" name="student_number" id="edit_student_number" class="form-control" required>
                    </div>
                    <div class="col-md-6">
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
                    <div class="col-md-6">
                        <label class="form-label">First Name *</label>
                        <input type="text" name="first_name" id="edit_first_name" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Last Name *</label>
                        <input type="text" name="last_name" id="edit_last_name" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Section</label>
                        <select name="section_id" id="edit_section_id" class="form-select">
                            <option value="0">None</option>
                        </select>
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
                <button type="submit" class="btn btn-primary">Update Student</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Student Modal -->
<div class="modal fade" id="deleteStudentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="student_id" id="delete_student_id">
            <input type="hidden" name="user_id" id="delete_student_userid">
            <div class="modal-header">
                <h5 class="modal-title">Delete Student</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete student <strong id="delete_student_name"></strong>?</p>
                <p class="text-danger small">This will also delete the associated user account. This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
// All sections with their grade_level, used to filter the Section dropdowns client-side
const allSections = <?php echo json_encode($sections, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

// Rebuilds a Section <select>'s options to only the sections belonging to the given grade.
// selectedId (optional) will be preselected if it still belongs to that grade.
function populateSectionSelect(selectEl, gradeLevel, selectedId) {
    selectEl.innerHTML = '';

    if (!gradeLevel) {
        selectEl.disabled = true;
        const opt = document.createElement('option');
        opt.value = '0';
        opt.textContent = 'Select a grade level first';
        selectEl.appendChild(opt);
        return;
    }

    selectEl.disabled = false;

    const noneOpt = document.createElement('option');
    noneOpt.value = '0';
    noneOpt.textContent = 'None';
    selectEl.appendChild(noneOpt);

    const matches = allSections.filter(sec => sec.grade_level === gradeLevel);
    matches.forEach(sec => {
        const opt = document.createElement('option');
        opt.value = sec.id;
        opt.textContent = sec.section_name;
        selectEl.appendChild(opt);
    });

    if (selectedId !== undefined && selectedId !== null) {
        const stillValid = matches.some(sec => String(sec.id) === String(selectedId));
        selectEl.value = stillValid ? String(selectedId) : '0';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Add modal: filter sections whenever grade level changes
    const addGrade = document.getElementById('add_grade_level');
    const addSection = document.getElementById('add_section_id');
    if (addGrade && addSection) {
        addGrade.addEventListener('change', function() {
            populateSectionSelect(addSection, this.value, 0);
        });
    }

    // Edit modal: filter sections whenever grade level changes
    const editGrade = document.getElementById('edit_grade_level');
    const editSection = document.getElementById('edit_section_id');
    if (editGrade && editSection) {
        editGrade.addEventListener('change', function() {
            populateSectionSelect(editSection, this.value, 0);
        });
    }

    // Search Functionality
    const searchInput = document.getElementById('searchInput');
    const table = document.getElementById('studentsTable');
    if (searchInput && table && table.tBodies[0]) {
        const tbody = table.tBodies[0];
        searchInput.addEventListener('keyup', function() {
            const filter = this.value.toLowerCase().trim();
            Array.from(tbody.rows).forEach(row => {
                const cells = Array.from(row.cells).slice(0, -1); // exclude Actions column
                const match = cells.some(cell => cell.textContent.toLowerCase().indexOf(filter) > -1);
                row.style.display = match ? '' : 'none';
            });
        });
    }

    // Edit Student Handler
    document.querySelectorAll('.edit-student-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('edit_student_id').value = this.dataset.id;
            document.getElementById('edit_user_id').value = this.dataset.userid;
            document.getElementById('edit_student_number').value = this.dataset.stdnum;
            document.getElementById('edit_first_name').value = this.dataset.firstname;
            document.getElementById('edit_last_name').value = this.dataset.lastname;
            document.getElementById('edit_grade_level').value = this.dataset.grade;
            populateSectionSelect(document.getElementById('edit_section_id'), this.dataset.grade, this.dataset.section);
            document.getElementById('edit_email').value = this.dataset.email;
            document.getElementById('edit_contact_number').value = this.dataset.contact;
        });
    });

    // Delete Student Handler
    document.querySelectorAll('.delete-student-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('delete_student_id').value = this.dataset.id;
            document.getElementById('delete_student_userid').value = this.dataset.userid;
            document.getElementById('delete_student_name').textContent = this.dataset.name;
        });
    });
});
</script>

<?php end_layout(); ?>
