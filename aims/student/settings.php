<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_role('student');
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/settings_store.php';
$settings = get_settings();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings['theme']['student']['accent'] = trim($_POST['student_accent'] ?? $settings['theme']['student']['accent']);
    $settings['theme']['student']['hover']  = trim($_POST['student_hover'] ?? $settings['theme']['student']['hover']);
    $settings['features']['topbar_clock'] = isset($_POST['topbar_clock']);
    $settings['features']['student_sidebar_calendar'] = isset($_POST['student_sidebar_calendar']);
    $settings['features']['notifications'] = isset($_POST['notifications']);
    if (!empty($_FILES['profile_photo']) && ($_FILES['profile_photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $tmp = $_FILES['profile_photo']['tmp_name'];
        $name = $_FILES['profile_photo']['name'];
        $size = (int) $_FILES['profile_photo']['size'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp) ?: '';
        if ($size <= 2 * 1024 * 1024 && ($mime === 'image/jpeg' || $mime === 'image/png')) {
            $ext = $mime === 'image/png' ? 'png' : 'jpg';
            $dir = __DIR__ . '/../assets/profile';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            // Remove existing photo if any
            $base = $dir . '/' . (int)($_SESSION['user_id'] ?? 0);
            if (file_exists($base . '.jpg')) unlink($base . '.jpg');
            if (file_exists($base . '.png')) unlink($base . '.png');
            
            $dest = $base . '.' . $ext;
            @move_uploaded_file($tmp, $dest);
        }
    }
    $pwdStatus = '';
    $cur = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $conf = $_POST['confirm_password'] ?? '';
    if ($cur !== '' || $new !== '' || $conf !== '') {
        if ($new !== '' && $new === $conf) {
            $minLen = (int) ($settings['security']['password_min_length'] ?? 8);
            if (strlen($new) >= $minLen) {
                $uid = (int) ($_SESSION['user_id'] ?? 0);
                $stmt = $mysqli->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
                if ($stmt) {
                    $stmt->bind_param('i', $uid);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $row = $res->fetch_assoc();
                    $stmt->close();
                    if ($row && password_verify($cur, $row['password_hash'])) {
                        $newHash = password_hash($new, PASSWORD_DEFAULT);
                        $upd = $mysqli->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                        if ($upd) {
                            $upd->bind_param('si', $newHash, $uid);
                            if ($upd->execute()) {
                                $pwdStatus = 'ok';
                            } else {
                                $pwdStatus = 'fail';
                            }
                            $upd->close();
                        } else {
                            $pwdStatus = 'fail';
                        }
                    } else {
                        $pwdStatus = 'fail';
                    }
                } else {
                    $pwdStatus = 'fail';
                }
            } else {
                $pwdStatus = 'fail';
            }
        } else {
            $pwdStatus = 'fail';
        }
    }
    $saved = save_settings($settings);
    $status = $saved ? 'ok' : 'fail';
    if ($pwdStatus !== '') {
        $status = ($status === 'ok' && $pwdStatus === 'ok') ? 'ok' : 'fail';
        header('Location: settings.php?status=' . $status . '&pwd=' . $pwdStatus . '#password-section');
    } else {
        header('Location: settings.php?status=' . $status);
    }
    exit;
}
$sidebar = [
    ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'dashboard'],
    ['label' => 'My Grades', 'href' => '#', 'icon' => 'grades'],
    ['label' => 'My Schedule', 'href' => '#', 'icon' => 'classes'],
    ['label' => 'My Attendance', 'href' => '#', 'icon' => 'attendance'],
    ['label' => 'Settings', 'href' => 'settings.php', 'active' => true, 'icon' => 'settings'],
];
start_layout('Student Settings - AIMS', 'AIMS - Student', $_SESSION['username'] ?? '', $sidebar);
?>
    <h1 class="h3 mb-4">Settings</h1>
    <?php if (isset($_GET['status']) && $_GET['status'] === 'ok'): ?>
        <div class="alert alert-success">Settings saved.</div>
    <?php elseif (isset($_GET['status']) && $_GET['status'] === 'fail'): ?>
        <div class="alert alert-danger">Failed to save settings.</div>
    <?php endif; ?>
    <form method="post" action="settings.php" enctype="multipart/form-data">
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-header">Theme (Student)</div>
                    <div class="card-body">
                        <div class="mb-2">
                            <label class="form-label">Accent</label>
                            <input type="text" class="form-control" name="student_accent" value="<?php echo htmlspecialchars($settings['theme']['student']['accent'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div>
                            <label class="form-label">Hover</label>
                            <input type="text" class="form-control" name="student_hover" value="<?php echo htmlspecialchars($settings['theme']['student']['hover'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-header">Features</div>
                    <div class="card-body">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="topbar_clock" name="topbar_clock" <?php echo !empty($settings['features']['topbar_clock']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="topbar_clock">Show top bar clock</label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="student_sidebar_calendar" name="student_sidebar_calendar" <?php echo !empty($settings['features']['student_sidebar_calendar']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="student_sidebar_calendar">Show sidebar calendar</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="notifications" name="notifications" <?php echo !empty($settings['features']['notifications']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="notifications">Enable notifications</label>
                        </div>
                        <hr>
                        <div class="mb-2" id="profile-section">
                            <label class="form-label">Profile Photo</label>
                            <input type="file" class="form-control" name="profile_photo" accept="image/jpeg,image/png">
                            <small class="text-muted">Max 2MB; JPG or PNG.</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-header">Password</div>
                    <div class="card-body">
                        <?php if (isset($_GET['pwd'])): ?>
                            <?php if ($_GET['pwd'] === 'ok'): ?>
                                <div class="alert alert-success">Password changed successfully.</div>
                            <?php else: ?>
                                <div class="alert alert-danger">Failed to change password. Check your current password and requirements.</div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div id="password-section"></div>
                        <div class="mb-3">
                            <label class="form-label">Current Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="curPwd" name="current_password" autocomplete="current-password">
                                <button type="button" class="btn btn-outline-secondary pw-toggle" data-target="curPwd"><?php echo icon('eye'); ?></button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="newPwd" name="new_password" autocomplete="new-password">
                                <button type="button" class="btn btn-outline-secondary pw-toggle" data-target="newPwd"><?php echo icon('eye'); ?></button>
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Confirm New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="confPwd" name="confirm_password" autocomplete="new-password">
                                <button type="button" class="btn btn-outline-secondary pw-toggle" data-target="confPwd"><?php echo icon('eye'); ?></button>
                            </div>
                        </div>
                        <small class="text-muted">Minimum length: <?php echo htmlspecialchars((string)($settings['security']['password_min_length'] ?? 8), ENT_QUOTES, 'UTF-8'); ?> characters.</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="d-flex justify-content-end mt-3">
            <button type="submit" class="btn btn-primary">Save Settings</button>
        </div>
    </form>
    <script>
    (function(){
      var toggles = document.querySelectorAll('.pw-toggle');
      toggles.forEach(function(btn){
        btn.addEventListener('click', function(){
          var id = btn.getAttribute('data-target');
          var input = document.getElementById(id);
          if (!input) return;
          input.type = (input.type === 'password') ? 'text' : 'password';
        });
      });
    })();
    </script>
<?php
end_layout();
