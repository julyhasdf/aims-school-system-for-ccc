<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/icons.php';

if (is_logged_in()) {
    // If already logged in, send to respective dashboard.
    switch ($_SESSION['role']) {
        case 'admin':
            header('Location: admin/dashboard.php');
            break;
        case 'teacher':
            header('Location: teacher/dashboard.php');
            break;
        case 'student':
            header('Location: student/dashboard.php');
            break;
        default:
            header('Location: logout.php');
            break;
    }
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name       = trim($_POST['first_name'] ?? '');
    $last_name        = trim($_POST['last_name'] ?? '');
    $username         = trim($_POST['username'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $password         = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $terms_agreed     = isset($_POST['terms_agreed']);

    if ($first_name === '' || $last_name === '' || $username === '' || $email === '' || $password === '' || $confirm_password === '') {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/@[^@]+\.[a-zA-Z]{2,}$/', $email)) {
        $error = 'Please enter a valid email address (e.g., user@example.com).';
    } elseif (!$terms_agreed) {
        $error = 'You must agree to the Terms and Conditions to register.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^a-zA-Z0-9]/', $password)) {
        $error = 'Password must contain at least one uppercase letter, one number, and one special character.';
    } else {
        // Check if username already exists.
        $stmt = $mysqli->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        if (! $stmt) {
            $error = 'Registration failed. Please try again later.';
        } else {
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $error = 'Username is already taken. Please choose another one.';
                $stmt->close();
            } else {
                $stmt->close();

                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $role          = 'student'; // Self-registration creates student accounts.
                $status        = 'active';
                $full_name     = $first_name . ' ' . $last_name;

                $insert = $mysqli->prepare(
                    'INSERT INTO users (full_name, username, password_hash, role, email, status) VALUES (?, ?, ?, ?, ?, ?)'
                );

                if (! $insert) {
                    $error = 'Registration failed. Please try again later.';
                } else {
                    $insert->bind_param('ssssss', $full_name, $username, $password_hash, $role, $email, $status);
                    if ($insert->execute()) {
                        $new_user_id = $insert->insert_id;
                        $insert->close();

                        // Automatically generate student record with student number
                        $currentYear = date('Y');
                        
                        // Get the latest student number for the current year to determine sequence
                        $seq = 1;
                        $seqStmt = $mysqli->prepare("SELECT student_number FROM students WHERE student_number LIKE ? ORDER BY student_number DESC LIMIT 1");
                        if ($seqStmt) {
                            $likePattern = $currentYear . '-%';
                            $seqStmt->bind_param('s', $likePattern);
                            $seqStmt->execute();
                            $seqRes = $seqStmt->get_result();
                            if ($row = $seqRes->fetch_assoc()) {
                                // Format: YYYY-XXXX
                                $parts = explode('-', $row['student_number']);
                                if (isset($parts[1])) {
                                    $seq = (int)$parts[1] + 1;
                                }
                            }
                            $seqStmt->close();
                        }
                        
                        // Format: YYYY-XXXX (e.g., 2026-0001)
                        $studentNumber = $currentYear . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
                        
                        // Insert into students table with default/empty values for other fields
                        $studInsert = $mysqli->prepare("INSERT INTO students (user_id, student_number, first_name, last_name, grade_level) VALUES (?, ?, ?, ?, ?)");
                        if ($studInsert) {
                            // Use username as placeholder for name until profile is updated
                            $gradeLevel = 'Grade 7'; // Default grade
                            
                            $studInsert->bind_param('issss', $new_user_id, $studentNumber, $first_name, $last_name, $gradeLevel);
                            $studInsert->execute();
                            $studInsert->close();
                        }

                        header('Location: login.php?registered=1');
                        exit;
                    } else {
                        $error = 'Registration failed. Please try again.';
                        $insert->close();
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AIMS Registration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">
    <style>
        :root { --accent: #22c55e; --accent-hover: #16a34a; }
        body { background: #f5f7fb; }
        .auth-topbar { display:none; }
        .brand-title { font-weight:800; font-size:2.2rem; letter-spacing:.6px; color: var(--accent); text-shadow: 0 2px 4px rgba(34,197,94,0.2); }
        .icon-btn{border:none;background:transparent;padding:.4rem;border-radius:.5rem}
        .icon-btn:hover{background:#f0f2f6}
        .card { border: 0; border-radius: 1.5rem; overflow: hidden; }
        .card .card-body { padding: 2.5rem 2rem; }
        .form-control { border-radius: 1rem; padding: 0.75rem 1rem; }
        .form-control:focus { border-color: var(--accent); box-shadow: 0 0 0 .2rem rgba(34,197,94,.15); }
        .btn-accent { background: var(--accent); color:#fff; border: none; border-radius: 1rem; padding: 0.75rem; font-weight: 600; letter-spacing: 0.5px; }
        .btn-accent:hover { background: var(--accent-hover); color:#fff; }
        .link-hover-highlight { cursor:pointer; color: var(--accent); text-decoration:none; transition: color .15s ease-in-out; }
        .link-hover-highlight:hover { color: var(--accent-hover); text-decoration: underline; }
        .bg-cover { position: fixed; inset: 0; background: url('/aims/assets/logo/makilingcover.jpg') center/cover no-repeat; filter: blur(20px); transform: scale(1.1); z-index: -2; width: 100vw; height: 100vh; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 position-relative">
<div class="bg-cover"></div>
<div class="container" style="z-index: 1;">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card shadow-lg">
                <div class="card-body">
                    <div class="text-center mb-3">
                        <img src="/aims/assets/logo/makilinglogo.png" alt="" height="86" class="mb-2">
                        <div class="brand-title">AIMS</div>
                    </div>
                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger">
                            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($success !== ''): ?>
                        <div class="alert alert-success">
                            <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="register.php" novalidate>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label for="first_name" class="form-label">First Name</label>
                                <input type="text" class="form-control" id="first_name" name="first_name" required>
                            </div>
                            <div class="col-6">
                                <label for="last_name" class="form-label">Last Name</label>
                                <input type="text" class="form-control" id="last_name" name="last_name" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text"
                                   class="form-control"
                                   id="username"
                                   name="username"
                                   required>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email"
                                   class="form-control"
                                   id="email"
                                   name="email"
                                   placeholder="user@example.com"
                                   pattern="[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$"
                                   title="Please enter a valid email address (e.g., user@example.com)"
                                   required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <div class="input-group">
                                <input type="password"
                                       class="form-control"
                                       id="password"
                                       name="password"
                                       required>
                                <button type="button" class="btn btn-outline-secondary pw-toggle" data-target="password"><?php echo icon('eye'); ?></button>
                            </div>
                            <div class="form-text small text-muted">
                                Password must contain at least one uppercase letter, one number, and one special character.
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm Password</label>
                            <div class="input-group">
                                <input type="password"
                                       class="form-control"
                                       id="confirm_password"
                                       name="confirm_password"
                                       required>
                                <button type="button" class="btn btn-outline-secondary pw-toggle" data-target="confirm_password"><?php echo icon('eye'); ?></button>
                            </div>
                        </div>

                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="termsCheck" name="terms_agreed" value="1" required>
                            <label class="form-check-label small" for="termsCheck">
                                I agree to the <a href="#" class="link-hover-highlight" data-bs-toggle="modal" data-bs-target="#termsModal">Terms and Conditions</a>
                            </label>
                        </div>

                        <div class="d-grid mb-3">
                            <button type="submit" class="btn btn-accent btn-lg w-100">Sign Up</button>
                        </div>
                    </form>
                    <div class="text-center mt-3">
                        <small>Already have an account? <a href="login.php" class="link-hover-highlight">Login here</a></small>
                    </div>
                </div>
                <div class="card-footer text-center text-muted">
                    <small>&copy; <?php echo date('Y'); ?> AIMS</small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Terms and Conditions Modal -->
<div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="termsModalLabel">Terms and Conditions</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <h6>1. Introduction</h6>
                <p class="small text-muted">Welcome to AIMS (Academic Information Management System). By registering, you agree to comply with and be bound by the following terms and conditions.</p>
                
                <h6>2. User Responsibilities</h6>
                <p class="small text-muted">You are responsible for maintaining the confidentiality of your account credentials. You agree to use the system only for lawful academic purposes.</p>
                
                <h6>3. Data Privacy</h6>
                <p class="small text-muted">We value your privacy. Your personal information will be used solely for academic administration and will not be shared with unauthorized third parties.</p>
                
                <h6>4. Code of Conduct</h6>
                <p class="small text-muted">Users must not engage in any activity that disrupts the system or compromises its security. Harassment, plagiarism, and unauthorized access are strictly prohibited.</p>
                
                <h6>5. Modifications</h6>
                <p class="small text-muted">The administration reserves the right to modify these terms at any time. Continued use of the system constitutes acceptance of the new terms.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">I Understand</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>
<script>(function(){var b=document.getElementById('authNotifBtn');var m=document.getElementById('authNotifMenu');if(b&&m){b.addEventListener('click',function(e){e.stopPropagation();m.style.display=(m.style.display==='none'||m.style.display==='')?'block':'none';});document.addEventListener('click',function(){m.style.display='none';});}var t=document.querySelectorAll('.pw-toggle');t.forEach(function(btn){btn.addEventListener('click',function(){var id=btn.getAttribute('data-target');var input=document.getElementById(id);if(!input)return;input.type=(input.type==='password')?'text':'password';});});})();</script>
</body>
</html>

