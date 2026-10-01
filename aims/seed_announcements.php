<?php
require_once __DIR__ . '/config.php';

try {
    $mysqli->begin_transaction();

    // Clear existing announcements
    $mysqli->query("TRUNCATE TABLE announcements");
    echo "Cleared existing announcements.\n";

    // Get Admin ID for created_by
    $res = $mysqli->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
    if (!$res || $res->num_rows === 0) {
        throw new Exception("Admin user not found. Please run seed_data.php first.");
    }
    $adminId = (int)$res->fetch_assoc()['id'];

    // New Announcement: Howie Severino Event
    $announcements = [
        [
            'title' => 'BREAKING NEWS: HOWIE SEVERINO COMES TO ASIN AT ILAW 2026!',
            'message' => "The wait is over! We are proud to introduce our distinguished speaker for 𝗔𝗦𝗜𝗡 𝗔𝗧 𝗜𝗟𝗔𝗪 𝟮𝟬𝟮𝟲: 𝗨𝗻𝗰𝗼𝘃𝗲𝗿𝗶𝗻𝗴 𝗧𝗿𝘂𝘁𝗵𝘀 𝗳𝗿𝗼𝗺 𝗙𝗮𝗰𝘁𝘀 𝘁𝗼 𝗥𝗲𝗮𝗰𝘁𝗶𝗼𝗻 — 𝗔 𝗠𝗲𝗱𝗶𝗮 𝗮𝗻𝗱 𝗜𝗻𝗳𝗼𝗿𝗺𝗮𝘁𝗶𝗼𝗻 𝗟𝗶𝘁𝗲𝗿𝗮𝗰𝘆 𝗦𝗲𝗺𝗶𝗻𝗮𝗿.\n\nJoining us is 𝗠𝗥. 𝗛𝗢𝗪𝗜𝗘 𝗦𝗘𝗩𝗘𝗥𝗜𝗡𝗢, an award-winning journalist, documentary filmmaker, and respected media personality who has long championed truthful and responsible journalism. Be part of this meaningful conversation on truth, media, and responsible citizenship.",
            'target_role' => 'all',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+1 month'))
        ]
    ];

    $stmt = $mysqli->prepare("INSERT INTO announcements (title, message, target_role, expires_at, created_by) VALUES (?, ?, ?, ?, ?)");

    foreach ($announcements as $a) {
        $stmt->bind_param('ssssi', $a['title'], $a['message'], $a['target_role'], $a['expires_at'], $adminId);
        $stmt->execute();
    }

    $stmt->close();
    $mysqli->commit();

    echo "Successfully updated announcements.\n";

} catch (Exception $e) {
    $mysqli->rollback();
    echo "Error: " . $e->getMessage();
}
