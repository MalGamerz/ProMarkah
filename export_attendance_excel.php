<?php
include 'db.php';
$conn = getDB();


// ── Read the SAME filter params attendance_view_all.php actually sends
// (this previously read $_POST['sidang_id'], but the link that calls this
// script is a plain GET <a href>, so that was always empty — and it
// hardcoded WHERE a.status='Present', silently dropping every absent or
// never-marked student instead of showing them as Tidak Hadir). ─────────
$f_siri    = isset($_GET['f_siri'])    && is_numeric($_GET['f_siri'])    ? (int)$_GET['f_siri']    : 0;
$f_sidang  = isset($_GET['f_sidang'])  && is_numeric($_GET['f_sidang'])  ? (int)$_GET['f_sidang']  : 0;
$f_tahun   = isset($_GET['f_tahun'])   && ctype_digit($_GET['f_tahun'])  ? $_GET['f_tahun']         : '';
$f_school  = isset($_GET['f_school'])  && is_numeric($_GET['f_school'])  ? (int)$_GET['f_school']  : 0;
$f_status  = isset($_GET['f_status'])  && in_array($_GET['f_status'], ['Present','Absent','']) ? $_GET['f_status'] : '';

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=Kehadiran_" . ($f_sidang ?: 'Semua') . ".xls");
header("Pragma: no-cache");
header("Expires: 0");

echo "<table border='1'>";
echo "<tr>
        <th>Bil</th>
        <th>Siri</th>
        <th>Sidang</th>
        <th>Cawangan</th>
        <th>Nama Pesilat</th>
        <th>ID Pesilat</th>
        <th>Status Kehadiran</th>
      </tr>";

$where      = ["1=1"];
$bind_types = "";
$bind_vals  = [];

if ($f_siri > 0)     { $where[] = "si.siri_id = ?";    $bind_types .= "i"; $bind_vals[] = $f_siri; }
if ($f_sidang > 0)   { $where[] = "s.session_id = ?";  $bind_types .= "i"; $bind_vals[] = $f_sidang; }
if ($f_tahun !== '')  { $where[] = "st.year = ?";       $bind_types .= "s"; $bind_vals[] = $f_tahun; }
if ($f_school > 0)   { $where[] = "sc.school_id = ?";  $bind_types .= "i"; $bind_vals[] = $f_school; }

$where_sql = 'AND ' . implode(' AND ', $where);

$status_having = '';
if ($f_status === 'Present') {
    $status_having = "HAVING attend_status = 'Present'";
} elseif ($f_status === 'Absent') {
    $status_having = "HAVING (attend_status IS NULL OR attend_status = 'Absent')";
}

// Starts from `students` (not `attendance`) so a student with no attendance
// row at all still appears — as Tidak Hadir — instead of being silently
// dropped, matching attendance_view_all.php's own listing exactly.
$query = "
    SELECT st.student_id,
           st.student_name,
           sc.school_name,
           si.siri_name,
           s.session_name,
           COALESCE(
               (SELECT a.status FROM attendance a
                WHERE a.student_id = st.student_id
                  AND a.session_id = s.session_id
                  AND a.status = 'Present' LIMIT 1),
               (SELECT a.status FROM attendance a
                WHERE a.student_id = st.student_id
                  AND a.status = 'Present' LIMIT 1)
           ) AS attend_status
    FROM students st
    JOIN schools sc ON st.school_id = sc.school_id
    JOIN (
        SELECT DISTINCT st2.student_id, l.session_id
        FROM students st2
        JOIN levels l ON st2.level_id = l.level_id
        UNION
        SELECT DISTINCT gs.student_id, gl.session_id
        FROM group_students gs
        JOIN `groups` g ON gs.group_id = g.group_id
        JOIN levels gl ON g.level_id = gl.level_id
    ) stu_sess ON stu_sess.student_id = st.student_id
    JOIN sessions s ON s.session_id = stu_sess.session_id
    JOIN siri si ON si.siri_id = s.siri_id
    WHERE 1=1 $where_sql
    GROUP BY st.student_id, s.session_id
    $status_having
    ORDER BY si.siri_name ASC, s.session_name ASC, sc.school_name ASC, st.student_name ASC
";

if ($bind_vals) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param($bind_types, ...$bind_vals);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($query);
}

if ($result && $result->num_rows > 0) {
    $no = 1;
    while ($row = $result->fetch_assoc()) {
        $statusText = $row['attend_status'] === 'Present' ? '✔ Hadir' : '✖ Tidak Hadir';
        echo "<tr>
                <td>{$no}</td>
                <td>" . htmlspecialchars($row['siri_name']) . "</td>
                <td>" . htmlspecialchars($row['session_name']) . "</td>
                <td>" . htmlspecialchars($row['school_name']) . "</td>
                <td>" . htmlspecialchars($row['student_name']) . "</td>
                <td>{$row['student_id']}</td>
                <td>{$statusText}</td>
              </tr>";
        $no++;
    }
} else {
    echo "<tr><td colspan='7' style='text-align:center;'>Tiada data kehadiran direkodkan.</td></tr>";
}

echo "</table>";
exit;
?>
