<?php
include 'db.php';
$conn = getDB();

$session_id = intval($_GET['session_id']);

$schools = $conn->query("SELECT * FROM schools ORDER BY school_name ASC");

$assigned = $conn->query("SELECT school_id FROM session_schools WHERE session_id=$session_id");
$assigned_ids = [];
while ($x = $assigned->fetch_assoc()) {
    $assigned_ids[] = $x['school_id'];
}

while ($s = $schools->fetch_assoc()) {
    $checked = in_array($s['school_id'], $assigned_ids) ? "checked" : "";
    echo "<label style='display:block;'>
            <input type='checkbox' name='school_ids[]' value='{$s['school_id']}' $checked>
            {$s['school_name']}
          </label>";
}
