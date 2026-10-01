<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_role('admin');
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/settings_store.php';
$settings = get_settings();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings['general']['site_name'] = trim($_POST['site_name'] ?? $settings['general']['site_name']);
    $settings['general']['logo_path'] = trim($_POST['logo_path'] ?? $settings['general']['logo_path']);
    $settings['theme']['student']['accent'] = trim($_POST['student_accent'] ?? $settings['theme']['student']['accent']);
    $settings['theme']['student']['hover']  = trim($_POST['student_hover'] ?? $settings['theme']['student']['hover']);
    $settings['theme']['teacher']['accent'] = trim($_POST['teacher_accent'] ?? $settings['theme']['teacher']['accent']);
    $settings['theme']['teacher']['hover']  = trim($_POST['teacher_hover'] ?? $settings['theme']['teacher']['hover']);
    $settings['theme']['admin']['accent']   = trim($_POST['admin_accent'] ?? $settings['theme']['admin']['accent']);
    $settings['theme']['admin']['hover']    = trim($_POST['admin_hover'] ?? $settings['theme']['admin']['hover']);
    $settings['features']['topbar_clock'] = isset($_POST['topbar_clock']);
    $settings['features']['student_sidebar_calendar'] = isset($_POST['student_sidebar_calendar']);
    $settings['features']['notifications'] = isset($_POST['notifications']);
    $settings['school']['current_year'] = trim($_POST['current_year'] ?? $settings['school']['current_year']);
    $settings['smtp']['host'] = trim($_POST['smtp_host'] ?? $settings['smtp']['host']);
    $settings['smtp']['port'] = trim($_POST['smtp_port'] ?? $settings['smtp']['port']);
    $settings['smtp']['username'] = trim($_POST['smtp_username'] ?? $settings['smtp']['username']);
    $settings['smtp']['password'] = trim($_POST['smtp_password'] ?? $settings['smtp']['password']);
    $settings['security']['password_min_length'] = (int) ($_POST['password_min_length'] ?? $settings['security']['password_min_length']);
    $saved = save_settings($settings);
    $status = $saved ? 'ok' : 'fail';
    header('Location: settings.php?status=' . $status);
    exit;
}
$sidebar = [
    ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'dashboard'],
    ['label' => 'Students', 'href' => 'students.php', 'icon' => 'students'],
    ['label' => 'Teachers', 'href' => 'teachers.php', 'icon' => 'teachers'],
    ['label' => 'Subjects', 'href' => 'subjects.php', 'icon' => 'subjects'],
    ['label' => 'Sections', 'href' => 'sections.php', 'icon' => 'sections'],
    ['label' => 'Users', 'href' => 'users.php', 'icon' => 'users'],
    ['label' => 'Reports', 'href' => 'reports.php', 'icon' => 'reports'],
    ['label' => 'Settings', 'href' => 'settings.php', 'active' => true, 'icon' => 'settings'],
];
start_layout('System Settings - AIMS', 'AIMS - Admin', $_SESSION['username'] ?? '', $sidebar);
?>
    <h1 class="h3 mb-4">System Settings</h1>
    <?php if (isset($_GET['status']) && $_GET['status'] === 'ok'): ?>
        <div class="alert alert-success">Settings saved.</div>
    <?php elseif (isset($_GET['status']) && $_GET['status'] === 'fail'): ?>
        <div class="alert alert-danger">Failed to save settings.</div>
    <?php endif; ?>
    <form method="post" action="settings.php" class="mb-4">
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-header">General</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Site Name</label>
                            <input type="text" class="form-control" name="site_name" value="<?php echo htmlspecialchars($settings['general']['site_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Logo Path</label>
                            <input type="text" class="form-control" name="logo_path" value="<?php echo htmlspecialchars($settings['general']['logo_path'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
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
                            <label class="form-check-label" for="student_sidebar_calendar">Show student sidebar calendar</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="notifications" name="notifications" <?php echo !empty($settings['features']['notifications']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="notifications">Enable notifications</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-12">
                <div class="card shadow-sm">
                    <div class="card-header">Theme</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <h6>Student</h6>
                                <div class="mb-2">
                                    <label class="form-label">Accent</label>
                                    <input type="text" class="form-control" name="student_accent" value="<?php echo htmlspecialchars($settings['theme']['student']['accent'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div>
                                    <label class="form-label">Hover</label>
                                    <input type="text" class="form-control" name="student_hover" value="<?php echo htmlspecialchars($settings['theme']['student']['hover'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <h6>Teacher</h6>
                                <div class="mb-2">
                                    <label class="form-label">Accent</label>
                                    <input type="text" class="form-control" name="teacher_accent" value="<?php echo htmlspecialchars($settings['theme']['teacher']['accent'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div>
                                    <label class="form-label">Hover</label>
                                    <input type="text" class="form-control" name="teacher_hover" value="<?php echo htmlspecialchars($settings['theme']['teacher']['hover'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <h6>Admin</h6>
                                <div class="mb-2">
                                    <label class="form-label">Accent</label>
                                    <input type="text" class="form-control" name="admin_accent" value="<?php echo htmlspecialchars($settings['theme']['admin']['accent'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div>
                                    <label class="form-label">Hover</label>
                                    <input type="text" class="form-control" name="admin_hover" value="<?php echo htmlspecialchars($settings['theme']['admin']['hover'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-header">School</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Current School Year</label>
                            <input type="text" class="form-control" name="current_year" value="<?php echo htmlspecialchars($settings['school']['current_year'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="2025-2026">
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-header">SMTP</div>
                    <div class="card-body">
                        <div class="mb-2">
                            <label class="form-label">Host</label>
                            <input type="text" class="form-control" name="smtp_host" value="<?php echo htmlspecialchars($settings['smtp']['host'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Port</label>
                            <input type="text" class="form-control" name="smtp_port" value="<?php echo htmlspecialchars($settings['smtp']['port'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" name="smtp_username" value="<?php echo htmlspecialchars($settings['smtp']['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div>
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" name="smtp_password" value="<?php echo htmlspecialchars($settings['smtp']['password'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-12">
                <div class="card shadow-sm">
                    <div class="card-header">Security</div>
                    <div class="card-body">
                        <div class="mb-3" style="max-width: 240px;">
                            <label class="form-label">Password Minimum Length</label>
                            <input type="number" min="6" class="form-control" name="password_min_length" value="<?php echo htmlspecialchars((string)($settings['security']['password_min_length'] ?? 8), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="d-flex justify-content-end mt-3">
            <button type="submit" class="btn btn-primary">Save Settings</button>
        </div>
    </form>
<?php
end_layout();
