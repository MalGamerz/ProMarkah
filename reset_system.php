<?php
include 'db.php';
$conn = getDB();
session_start();
require __DIR__ . '/auth_check.php';

// ------------------------------
// ✅ Only allow judges to reset
// ------------------------------
if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'judge') {
    header("Location: login.php");
    exit();
}

// ------------------------------
// ✅ Confirm reset request
// ------------------------------
if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
    header("Location: judge.php");
    exit();
}

// ------------------------------
// ✅ Function to safely add 'archived' column if missing
// ------------------------------
function addArchivedColumn($conn, $table) {
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'archived'");
    if ($res && $res->num_rows === 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `archived` TINYINT(1) DEFAULT 0");
    }
}

// ------------------------------
// ✅ Tables to archive
// ------------------------------
$tables_to_archive = ['scores', 'marking_logs', 'summary'];

$conn->begin_transaction();
try {
    foreach ($tables_to_archive as $table) {
        $result = $conn->query("SHOW TABLES LIKE '$table'");
        if ($result && $result->num_rows > 0) {
            addArchivedColumn($conn, $table);
            $conn->query("UPDATE `$table` SET archived=1 WHERE archived=0");
        }
    }

    // Reset judge assignments — use judge_id (int column) not the stale judge_name string
    $conn->query("UPDATE `groups` SET judge_id = NULL");

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log('reset_system error: ' . $e->getMessage());
    header("Location: judge.php?msg=reset_failed");
    exit();
}

// ------------------------------
// ✅ Set session flag for fresh start
// ------------------------------
$_SESSION['start_fresh'] = true;

// ------------------------------
// ✅ Redirect back to judge panel
// ------------------------------
header("Location: judge.php?msg=reset_success");
exit();
?>
