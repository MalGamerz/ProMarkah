<?php
// ══════════════════════════════════════════════════════════════════
//  save_attendance.php — Save or update a student's attendance
// ══════════════════════════════════════════════════════════════════

include 'db.php';
$conn = getDB();
date_default_timezone_set('Asia/Kuala_Lumpur');
session_start();
require __DIR__ . '/auth_check.php';

header('Content-Type: text/plain; charset=utf-8');

// ── Input validation ─────────────────────────────────────────────
if (empty($_POST['student_id']) || empty($_POST['status']) || empty($_POST['session_id'])) {
    echo 'invalid_request';
    exit;
}

$student_id = (int) $_POST['student_id'];
$session_id = (int) $_POST['session_id'];
$status     = $conn->real_escape_string(trim($_POST['status']));

$allowed_statuses = ['Present', 'Absent'];
if (!in_array($status, $allowed_statuses, true)) {
    echo 'invalid_status';
    exit;
}

// ── Upsert attendance record ─────────────────────────────────────
$check = $conn->prepare("
    SELECT attendance_id
    FROM attendance
    WHERE student_id = ? AND session_id = ?
    LIMIT 1
");
$check->bind_param("ii", $student_id, $session_id);
$check->execute();
$existing = $check->get_result();

if ($existing->num_rows === 0) {
    $stmt = $conn->prepare("
        INSERT INTO attendance (student_id, session_id, status)
        VALUES (?, ?, ?)
    ");
    $stmt->bind_param("iis", $student_id, $session_id, $status);
} else {
    $stmt = $conn->prepare("
        UPDATE attendance
        SET status = ?, timestamp = NOW()
        WHERE student_id = ? AND session_id = ?
    ");
    $stmt->bind_param("sii", $status, $student_id, $session_id);
}

echo $stmt->execute() ? 'success' : 'database_error';
