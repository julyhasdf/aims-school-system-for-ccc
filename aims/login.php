<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (is_logged_in()) {
    // Already logged in; redirect based on role.
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

$error = '';
$registered_success = isset($_GET['registered']) && $_GET['registered'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $stmt = $mysqli->prepare('SELECT id, username, password_hash, role, status FROM users WHERE username = ? LIMIT 1');

        if ($stmt) {
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $result = $stmt->get_result();
            $user   = $result->fetch_assoc();
            $stmt->close();

            if ($user && $user['status'] === 'active' && password_verify($password, $user['password_hash'])) {
                $_SESSION['user_id']  = (int) $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role']     = $user['role'];

                switch ($user['role']) {
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
            } else {
                $error = 'Invalid credentials or inactive account.';
            }
        } else {
            $error = 'Login failed. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AIMS Login</title>
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
        .bg-cover { position: fixed; inset: 0; background: url('/aims/assets/logo/makilingcover.jpg') center/cover no-repeat; filter: blur(8px); transform: scale(1.1); z-index: -2; width: 100vw; height: 100vh; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 position-relative">
<div class="bg-cover"></div>
<div class="container" style="z-index: 1;">
    <div class="row justify-content-center">
        <div class="col-md-5 col-lg-4">
            <div class="card shadow-lg">
                <div class="card-body">
                    <div class="text-center mb-3">
                        <img src="/aims/assets/logo/makilinglogo.png" alt="" height="86" class="mb-2">
                        <div class="brand-title">AIMS</div>
                    </div>
                    <?php if ($registered_success): ?>
                        <div class="alert alert-success">
                            Registration successful! You can now log in.
                        </div>
                    <?php endif; ?>
                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger">
                            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>
                    <form method="post" action="login.php" novalidate>
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text"
                                   class="form-control"
                                   id="username"
                                   name="username"
                                   required
                                   autofocus>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password"
                                   class="form-control"
                                   id="password"
                                   name="password"
                                   required>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <a href="forgot_password.php" class="link-hover-highlight">Forgot password?</a>
                        </div>
                        <div class="d-grid mb-3">
                            <button type="submit" class="btn btn-accent">Login</button>
                        </div>
                        <div class="text-center mt-2">
                            <span>Don't have an account yet?</span>
                            <a href="register.php" class="link-hover-highlight">Sign up here</a>
                        </div>
                    </form>
                </div>
                <div class="card-footer text-center text-muted">
                    <small>&copy; <?php echo date('Y'); ?> AIMS</small>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>
<script>(function(){var b=document.getElementById('authNotifBtn');var m=document.getElementById('authNotifMenu');if(b&&m){b.addEventListener('click',function(e){e.stopPropagation();m.style.display=(m.style.display==='none'||m.style.display==='')?'block':'none';});document.addEventListener('click',function(){m.style.display='none';});}})();</script>
</body>
</html>

