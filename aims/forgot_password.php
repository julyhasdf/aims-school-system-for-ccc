<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/settings_store.php';
$error = '';
$success = '';
$settings = get_settings();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $new      = $_POST['new_password'] ?? '';
    $conf     = $_POST['confirm_password'] ?? '';
    if ($username === '' || $email === '' || $new === '' || $conf === '') {
        $error = 'Please fill in all fields.';
    } elseif ($new !== $conf) {
        $error = 'New passwords do not match.';
    } else {
        $minLen = (int) ($settings['security']['password_min_length'] ?? 8);
        if (strlen($new) < $minLen) {
            $error = 'Password must be at least ' . $minLen . ' characters.';
        } else {
            $stmt = $mysqli->prepare('SELECT id FROM users WHERE username = ? AND email = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('ss', $username, $email);
                $stmt->execute();
                $res = $stmt->get_result();
                $user = $res->fetch_assoc();
                $stmt->close();
                if ($user) {
                    $hash = password_hash($new, PASSWORD_DEFAULT);
                    $upd = $mysqli->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                    if ($upd) {
                        $uid = (int) $user['id'];
                        $upd->bind_param('si', $hash, $uid);
                        if ($upd->execute()) {
                            $success = 'Password has been reset. You can login now.';
                        } else {
                            $error = 'Failed to reset password. Please try again.';
                        }
                        $upd->close();
                    } else {
                        $error = 'Failed to reset password. Please try later.';
                    }
                } else {
                    $error = 'No matching account found for the provided username and email.';
                }
            } else {
                $error = 'Request failed. Please try again later.';
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
    <title>Forgot Password - AIMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">
    <style>
        :root { --accent: #22c55e; --accent-hover: #16a34a; }
        body { background: #f5f7fb; }
        .auth-topbar { background:#fff; border-bottom:1px solid #e5e7eb; box-shadow:0 2px 8px rgba(0,0,0,0.04); min-height:72px; }
        .brand-title { font-weight:800; font-size:1.7rem; letter-spacing:.6px; color: var(--accent); }
        .card { border: 0; }
        .form-control { border-radius: .6rem; }
        .form-control:focus { border-color: var(--accent); box-shadow: 0 0 0 .2rem rgba(34,197,94,.15); }
        .btn-accent { background: var(--accent); color:#fff; border: none; }
        .btn-accent:hover { background: var(--accent-hover); color:#fff; }
    </style>
    </head>
<body>
<nav class="auth-topbar">
    <div class="container d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center"><img src="/aims/assets/logo/makilinglogo.png" alt="" height="44" class="me-2"><div class="brand-title">AIMS</div></div>
    </div>
    </nav>
<div class="container d-flex align-items-center justify-content-center" style="min-height: calc(100vh - 72px);">
    <div class="row w-100 justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body">
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
                    <form method="post" action="forgot_password.php" novalidate>
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="fpNew" name="new_password" required>
                                <button type="button" class="btn btn-outline-secondary pw-toggle" data-target="fpNew"><?php echo icon('eye'); ?></button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="fpConf" name="confirm_password" required>
                                <button type="button" class="btn btn-outline-secondary pw-toggle" data-target="fpConf"><?php echo icon('eye'); ?></button>
                            </div>
                        </div>
                        <div class="d-grid mb-3">
                            <button type="submit" class="btn btn-accent">Reset Password</button>
                        </div>
                        <div class="text-center">
                            <a href="login.php" class="link-hover-highlight">Back to Login</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>
<script>(function(){var t=document.querySelectorAll('.pw-toggle');t.forEach(function(btn){btn.addEventListener('click',function(){var id=btn.getAttribute('data-target');var input=document.getElementById(id);if(!input)return;input.type=(input.type==='password')?'text':'password';});});})();</script>
</body>
</html>
