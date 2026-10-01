<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/notifications.php';
require_role('admin');
require_once __DIR__ . '/../includes/layout.php';

$error = '';
$success = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title   = trim($_POST['title'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $target  = $_POST['target_role'] ?? 'all';
        $expiry  = $_POST['expires_at'] ?? '';
        
        if ($title && $message) {
            $adminId = $_SESSION['user_id'];
            // If expiry is empty, set it to null or a far future date?
            // The database allows NULL for expires_at based on seed script, let's assume it.
            $expiryVal = $expiry ? $expiry : null;

            $stmt = $mysqli->prepare("INSERT INTO announcements (title, message, target_role, expires_at, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('ssssi', $title, $message, $target, $expiryVal, $adminId);
            
            if ($stmt->execute()) {
                $announcementId = $stmt->insert_id;
                $success = "Announcement published successfully.";
                
                // Broadcast notification
                $notifCount = broadcast_notification($target, "New Announcement: " . $title, "A new announcement has been posted.", "announcement", $announcementId);
                $success .= " Notified $notifCount users.";
            } else {
                $error = "Failed to publish announcement: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error = "Title and Message are required.";
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['announcement_id'] ?? 0);
        if ($id) {
            $stmt = $mysqli->prepare("DELETE FROM announcements WHERE id = ?");
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
                $success = "Announcement deleted successfully.";
            } else {
                $error = "Failed to delete announcement.";
            }
            $stmt->close();
        }
    }
}

// Fetch Announcements
$announcements = [];
$res = $mysqli->query("
    SELECT a.*, u.username as author 
    FROM announcements a 
    JOIN users u ON a.created_by = u.id 
    ORDER BY a.created_at DESC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $announcements[] = $row;
    }
}

$sidebar = [
    ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'dashboard'],
    ['label' => 'Students', 'href' => 'students.php', 'icon' => 'students'],
    ['label' => 'Teachers', 'href' => 'teachers.php', 'icon' => 'teachers'],
    ['label' => 'Subjects', 'href' => 'subjects.php', 'icon' => 'subjects'],
    ['label' => 'Schedules', 'href' => 'sections.php', 'icon' => 'sections'],
    ['label' => 'Users', 'href' => 'users.php', 'icon' => 'users'],
    ['label' => 'Announcements', 'href' => 'announcements.php', 'active' => true, 'icon' => 'announcements'],
    ['label' => 'Reports', 'href' => 'reports.php', 'icon' => 'reports'],
];

start_layout('Announcement Management', 'AIMS - Admin', $_SESSION['username'] ?? 'Admin', $sidebar);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Announcements</h1>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
        <?php echo icon('announcements'); ?> New Announcement
    </button>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 25%">Title</th>
                        <th style="width: 35%">Message</th>
                        <th style="width: 10%">Target</th>
                        <th style="width: 15%">Expires</th>
                        <th style="width: 15%">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($announcements)): ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted">No announcements found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($announcements as $ann): ?>
                        <tr>
                            <td class="fw-semibold"><?php echo htmlspecialchars($ann['title']); ?></td>
                            <td>
                                <div class="text-truncate" style="max-width: 300px;" title="<?php echo htmlspecialchars($ann['message']); ?>">
                                    <?php echo htmlspecialchars($ann['message']); ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-secondary"><?php echo htmlspecialchars(ucfirst($ann['target_role'])); ?></span>
                            </td>
                            <td>
                                <?php 
                                    if ($ann['expires_at']) {
                                        echo date('M d, Y', strtotime($ann['expires_at']));
                                    } else {
                                        echo '<span class="text-muted">Never</span>';
                                    }
                                ?>
                            </td>
                            <td>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this announcement?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="announcement_id" value="<?php echo $ann['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create Modal -->
<div class="modal fade" id="createModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="create">
            <div class="modal-header">
                <h5 class="modal-title">New Announcement</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Title *</label>
                    <input type="text" name="title" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Message *</label>
                    <textarea name="message" class="form-control" rows="4" required></textarea>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Target Audience</label>
                        <select name="target_role" class="form-select">
                            <option value="all">All Users</option>
                            <option value="students">Students Only</option>
                            <option value="teachers">Teachers Only</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Expiration Date</label>
                        <input type="date" name="expires_at" class="form-control">
                        <div class="form-text">Leave blank for no expiration</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Publish</button>
            </div>
        </form>
    </div>
</div>

<?php end_layout(); ?>
