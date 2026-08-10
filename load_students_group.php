<?php
include 'db.php';
$conn = getDB();

$level_id = intval($_GET['level_id']);

// First find all group_ids in this level
$groups = $conn->query("SELECT group_id FROM groups WHERE level_id = $level_id");

$assigned_ids = [];

while ($g = $groups->fetch_assoc()) {

    $gid = $g['group_id'];

    // Find all students assigned in each group
    $rs = $conn->query("SELECT student_id FROM group_students WHERE group_id = $gid");

    while ($s = $rs->fetch_assoc()) {
        $assigned_ids[] = $s['student_id'];
    }
}

// Convert to CSV list for exclusion
$assigned_list = implode(",", $assigned_ids);

if ($assigned_list == "") {
    $assigned_list = "0"; // nothing to exclude
}

$res = $conn->query("
    SELECT student_id, student_name 
    FROM students 
    WHERE level_id = $level_id
    AND student_id NOT IN ($assigned_list)
    ORDER BY student_id ASC
");

if ($res->num_rows == 0) {
    echo "<p>No available students. All assigned.</p>";
    exit;
}

while ($row = $res->fetch_assoc()) {
    echo "<label><input type='checkbox' name='students[]' value='{$row['student_id']}'> {$row['student_name']}</label><br>";
}
?>
