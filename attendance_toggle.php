<?php
include 'db.php';
$conn = getDB();

$student_id = intval($_POST['student_id']);
$session_id = intval($_POST['session_id']);
$status     = $_POST['status'] === 'Present' ? 'Present' : 'Absent'; // whitelist

$stmt = $conn->prepare("
    INSERT INTO attendance (student_id, session_id, status, timestamp)
    VALUES (?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE status = ?, timestamp = NOW()
");
$stmt->bind_param("isss", $student_id, $session_id, $status, $status);
$stmt->execute();
$stmt->close();

echo json_encode(["status" => "success"]);