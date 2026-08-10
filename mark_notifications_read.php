<?php
session_start();
require __DIR__ . '/auth_check.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pic') {
    http_response_code(403);
    exit();
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    http_response_code(403);
    exit();
}

$pic_user_id = (int) $_SESSION['user_id'];
session_write_close();

include 'db.php';
$conn = getDB();

// Mark specific IDs read for this user only
$ids = $_POST['ids'] ?? [];
if (!empty($ids)) {
    $ids = array_filter(array_map('intval', $ids));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '(?,?)'));
        // Build INSERT IGNORE into notification_reads
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO notification_reads (user_id, notification_id)
             VALUES " . implode(',', array_fill(0, count($ids), '(?,?)'))
        );
        // Interleave user_id with each notification_id
        $params = [];
        $types  = '';
        foreach ($ids as $nid) {
            $params[] = $pic_user_id;
            $params[] = $nid;
            $types   .= 'ii';
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    }
}

// Mark ALL unread as read for this user
if (isset($_POST['all']) && $_POST['all'] === '1') {
    $stmt = $conn->prepare("
        INSERT IGNORE INTO notification_reads (user_id, notification_id)
        SELECT ?, notification_id FROM notifications
    ");
    $stmt->bind_param("i", $pic_user_id);
    $stmt->execute();
    $stmt->close();
}

echo json_encode(['ok' => true]);