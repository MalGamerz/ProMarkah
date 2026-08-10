<?php
include 'db.php';
$conn = getDB();
session_start();
require __DIR__ . '/auth_check.php';
if (!isset($_SESSION['role'])) { http_response_code(403); exit; }


$type       = $_GET['type'] ?? '';
$session_id = (int)($_GET['session_id'] ?? 0);
$level_id   = (int)($_GET['level_id'] ?? 0);

if ($type === 'levels' && $session_id > 0) {
    $stmt = $conn->prepare("SELECT level_id, level_name FROM levels WHERE session_id = ? ORDER BY level_name ASC");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        echo "<option value='{$row['level_id']}'>" . htmlspecialchars($row['level_name']) . "</option>";
    }
    $stmt->close();
}
elseif ($type === 'groups' && $level_id > 0) {
    $stmt = $conn->prepare("SELECT group_id, group_name FROM `groups` WHERE level_id = ? ORDER BY group_name ASC");
    $stmt->bind_param("i", $level_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        echo "<option value='{$row['group_id']}'>" . htmlspecialchars($row['group_name']) . "</option>";
    }
    $stmt->close();
}
?>