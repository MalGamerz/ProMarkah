<?php
include 'db.php';
$conn = getDB();

$session_id = intval($_GET['session_id']);

$res = $conn->query("SELECT * FROM levels WHERE session_id=$session_id ORDER BY level_name ASC");

echo "<option value=''>-- Select Level --</option>";
while ($row = $res->fetch_assoc()) {
    echo "<option value='{$row['level_id']}'>{$row['level_name']}</option>";
}
?>
