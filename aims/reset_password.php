<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/icons.php';

$token = $_GET['token'] ?? '';
$demoMode = isset($_GET['demo']) && $_GET['demo'] === '1';
$error = '';
$success = '';
$validToken = false;
$userId = 0;

if ($token === '') {
    $error = 'Invalid or missing reset token.';
} else {
    // Validate token
    $stmt = $mysqli->prepare('
        SELECT user_id FROM password_resets 
        WHERE token = ? AND expires_at > NOW() 
        LIMIT 1
    ');
    if ($stmt) {
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if ($row) {
            $validToken = true;
            $userId = (int)$row['user_id'];
        } else {
            $error = 'Your reset link has expired or is invalid. Please request a new one.';
        }
    }
}

if ($validToken && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    
    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^a-zA-Z0-9]/', $password)) {
        $error = 'Password must contain at least one uppercase letter, one number, and one special character.';
    } else {
        // Update password
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $mysqli->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('si', $hash, $userId);
            if ($stmt->execute()) {
                // Delete the used token
                $mysqli->query("DELETE FROM password_resets WHERE user_id = $userId");
                $success = $demoMode ? 'Demo/Defense: password reset saved. You can now log in.' : 'Your password has been reset successfully! You can now log in.';
            } else {
                $error = 'Failed to reset password. Please try again.';
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password - AIMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --accent: #22c55e; --accent-hover: #16a34a; --font-sans:"Inter",system-ui,-apple-system,sans-serif; --font-head:"Manrope","Inter",system-ui,-apple-system,sans-serif; --text:#1f2937; --heading:#0f172a; }
        body { background: #f5f7fb; font-family:var(--font-sans); color:var(--text); font-size:.98rem; line-height:1.6; letter-spacing:.005em; -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale; text-rendering:optimizeLegibility; }
        h1,h2,h3,h4,h5,h6 { font-family:var(--font-head); color:var(--heading); letter-spacing:-.01em; }
        .card { border: 0; border-radius: 1.5rem; }
        .form-label { font-weight:600; color:#334155; font-size:.9rem; letter-spacing:.01em; }
        .form-control { border-radius: 1rem; padding: 0.75rem 1.25rem; }
        .form-control:focus { border-color: var(--accent); box-shadow: 0 0 0 .2rem rgba(34,197,94,.15); }
        .btn-accent { background: var(--accent); color:#fff; border: none; border-radius: 1rem; padding: 0.75rem; font-weight: 600; }
        .btn-accent:hover { background: var(--accent-hover); color:#fff; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5 col-lg-4">
            <div class="card shadow-lg">
                <div class="card-body p-4 p-md-5">
                    <div class="text-center mb-4">
                        <img src="/aims/assets/logo/makilinglogo.png" alt="" height="64" class="mb-3">
                        <h4 class="fw-bold">Create New Password</h4>
                    </div>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger small">
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($success !== ''): ?>
                        <div class="alert alert-success small">
                            <?php echo htmlspecialchars($success); ?>
                        </div>
                        <div class="d-grid mt-4">
                            <a href="login.php" class="btn btn-accent">Go to Login</a>
                        </div>
                    <?php elseif ($validToken): ?>
                        <form method="post" novalidate>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">New Password</label>
                                <input type="password" class="form-control" name="password" required autofocus>
                                <div class="form-text xsmall">At least 8 characters with upper, number, & special.</div>
                            </div>
                            <div class="mb-4">
                                <label class="form-label small fw-bold">Confirm Password</label>
                                <input type="password" class="form-control" name="confirm_password" required>
                            </div>
                            <div class="d-grid mb-3">
                                <button type="submit" class="btn btn-accent">Reset Password</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="text-center mt-4">
                            <a href="forgot_password.php" class="btn btn-outline-secondary btn-sm rounded-pill px-4">Request New Link</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
