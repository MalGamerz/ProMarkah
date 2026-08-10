<?php
// Autosaves a single mark as a DRAFT (submitted=0) while a judge is actively
// marking, so an accidental back-navigation, tab close, or crash doesn't
// wipe in-progress work. Drafts never lock the group and are excluded from
// every downstream total (leaderboard, completion stats) until the judge
// clicks "Selesai Pemarkahan", which flips submitted=1 via save_scores.php.
session_start();
require __DIR__ . '/auth_check.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'judge') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit();
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_token']);
    exit();
}

$judge_id = (int) ($_SESSION['user_id'] ?? 0);

// Release the session lock now that we've read what we need — this endpoint
// fires on nearly every field change while a judge is marking (with
// keepalive, so several can be in flight at once), and PHP's default
// file-based session handler serializes every request for the same session
// behind one exclusive lock. Without this, dropdown changes and the final
// submit (save_scores.php) queue up behind whichever draft-save is still
// running its DB work below.
session_write_close();

include 'db.php';
$conn = getDB();
$group_id    = (int) ($_POST['group_id'] ?? 0);
$student_id  = (int) ($_POST['student_id'] ?? 0);
$criteria_id = (int) ($_POST['criteria_id'] ?? 0);
$mark_raw    = $_POST['mark'] ?? '';

if ($judge_id <= 0 || $group_id <= 0 || $student_id <= 0 || $criteria_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_params']);
    exit();
}

// ── Ownership guard — same rule as save_scores.php: only the group's own
//    judge (or nobody yet) may write to it. ──
$own_stmt = $conn->prepare("SELECT judge_id, edit_used FROM `groups` WHERE group_id = ?");
$own_stmt->bind_param("i", $group_id);
$own_stmt->execute();
$own_row = $own_stmt->get_result()->fetch_assoc();
$own_stmt->close();

if (!$own_row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'group_not_found']);
    exit();
}

$current_owner  = (int) ($own_row['judge_id'] ?? 0);
$edit_used_flag = (int) ($own_row['edit_used'] ?? 0);

if ($current_owner !== 0 && $current_owner !== $judge_id) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'not_owner']);
    exit();
}

// ── Locked guard — a group with a finalized (submitted=1) mark can only
//    accept further writes while a fresh, unconsumed edit token is active.
//    Since this lightweight endpoint has no edit_mode context, the safest
//    rule is: refuse drafts once any submitted mark exists and the edit
//    token has already been consumed. ──
$sub_check = $conn->prepare("SELECT 1 FROM scores WHERE group_id = ? AND submitted = 1 LIMIT 1");
$sub_check->bind_param("i", $group_id);
$sub_check->execute();
$has_submitted = $sub_check->get_result()->num_rows > 0;
$sub_check->close();

if ($has_submitted && $edit_used_flag === 1) {
    http_response_code(423);
    echo json_encode(['ok' => false, 'error' => 'locked']);
    exit();
}

// ── Confirm the criterion actually exists ──
$ct = $conn->prepare("SELECT criteria_id FROM criteria WHERE criteria_id = ?");
$ct->bind_param("i", $criteria_id);
$ct->execute();
$ctRow = $ct->get_result()->fetch_assoc();
$ct->close();

if (!$ctRow) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_criteria']);
    exit();
}

// ── Clearing a mark: only remove drafts (submitted=0). A finalized mark
//    can't be wiped through this lightweight endpoint. ──
if ($mark_raw === '' || $mark_raw === null) {
    $del = $conn->prepare("DELETE FROM scores WHERE group_id = ? AND student_id = ? AND criteria_id = ? AND submitted = 0");
    $del->bind_param("iii", $group_id, $student_id, $criteria_id);
    $del->execute();
    $del->close();
    echo json_encode(['ok' => true, 'cleared' => true]);
    exit();
}

$mark = (int) $mark_raw;
if ($mark < 0 || $mark > 10) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_mark']);
    exit();
}

$stmt = $conn->prepare("
    INSERT INTO scores (student_id, group_id, criteria_id, mark, submitted)
    VALUES (?, ?, ?, ?, 0)
    ON DUPLICATE KEY UPDATE mark = VALUES(mark), submitted = IF(submitted = 1, submitted, 0)
");
$stmt->bind_param("iiii", $student_id, $group_id, $criteria_id, $mark);
$stmt->execute();
$stmt->close();

echo json_encode(['ok' => true, 'cleared' => false]);
