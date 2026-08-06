<?php
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Strict');
session_start();
require __DIR__ . '/auth_check.php';

include 'db.php';
$conn = getDB();

// ── Read the SAME filter params attendance_view_all.php actually sends ────
// (this previously read a nonexistent `sidang_id` param and queried FROM
// attendance directly, which silently dropped every student who has no
// attendance row at all instead of counting them as Tidak Hadir — see
// attendance_view_all.php for the query this mirrors.)
$f_siri    = isset($_GET['f_siri'])    && is_numeric($_GET['f_siri'])    ? (int)$_GET['f_siri']    : 0;
$f_sidang  = isset($_GET['f_sidang'])  && is_numeric($_GET['f_sidang'])  ? (int)$_GET['f_sidang']  : 0;
$f_tahun   = isset($_GET['f_tahun'])   && ctype_digit($_GET['f_tahun'])  ? $_GET['f_tahun']         : '';
$f_school  = isset($_GET['f_school'])  && is_numeric($_GET['f_school'])  ? (int)$_GET['f_school']  : 0;
$f_status  = isset($_GET['f_status'])  && in_array($_GET['f_status'], ['Present','Absent','']) ? $_GET['f_status'] : '';

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
// dropped, exactly like attendance_view_all.php's own listing.
$query = "
    SELECT st.student_id,
           st.student_name,
           st.year,
           sc.school_name,
           si.siri_name,
           si.siri_year,
           s.session_name,
           COALESCE(
               (SELECT a.status FROM attendance a
                WHERE a.student_id = st.student_id
                  AND a.session_id = s.session_id
                  AND a.status = 'Present' LIMIT 1),
               (SELECT a.status FROM attendance a
                WHERE a.student_id = st.student_id
                  AND a.status = 'Present' LIMIT 1)
           ) AS attend_status,
           (SELECT a.timestamp FROM attendance a
            WHERE a.student_id = st.student_id
              AND a.session_id = s.session_id
            ORDER BY a.timestamp DESC LIMIT 1) AS timestamp
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
?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <title>Laporan Kehadiran</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #333;
            margin: 20px;
        }
        .header-title {
            text-align: center;
            margin-bottom: 20px;
        }
        .header-title h2 {
            margin: 0 0 5px 0;
            color: #b30000;
        }
        .header-title p {
            margin: 0;
            font-size: 0.9rem;
            color: #666;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px 10px;
            text-align: left;
        }
        th {
            background-color: #b30000;
            color: #ffffff;
            text-transform: uppercase;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .status-hadir {
            color: #059669;
            font-weight: bold;
        }
        /* Hide buttons when printing */
        @media print {
            .no-print { display: none !important; }
            body { margin: 0; }
        }
        .controls {
            margin-bottom: 20px;
            text-align: right;
        }
        .btn {
            padding: 8px 16px;
            background: #b30000;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.9rem;
        }
        .btn-back {
            background: #666;
            margin-right: 10px;
        }
    </style>
</head>
<body>

    <div class="controls no-print">
        <button class="btn btn-back" onclick="window.close()">Tutup Maklumat</button>
        <button class="btn" onclick="window.print()">🖨️ Cetak / Simpan sebagai PDF</button>
    </div>

    <div class="header-title">
        <h2>Laporan Penuh Kehadiran Pesilat</h2>
        <p>Tarikh Dijana: <?= date('d/m/Y H:i:s') ?></p>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 5%;">Bil</th>
                <th style="width: 15%;">Sidang</th>
                <th style="width: 20%;">Cawangan</th>
                <th style="width: 30%;">Nama Pesilat</th>
                <th style="width: 10%;">Tahun</th>
                <th style="width: 10%;">Status</th>
                <th style="width: 10%;">Tarikh / Masa</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $no = 1;
            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $date = $row['timestamp'] ? date('d/m/Y H:i', strtotime($row['timestamp'])) : '-';
                    $status = $row['attend_status'] === 'Present' ? '<span class="status-hadir">Hadir</span>' : 'Tidak Hadir';

                    echo "<tr>
                            <td>{$no}</td>
                            <td>" . htmlspecialchars($row['session_name']) . "</td>
                            <td>" . htmlspecialchars($row['school_name'] ?? 'Tiada') . "</td>
                            <td>" . htmlspecialchars($row['student_name']) . "</td>
                            <td>" . htmlspecialchars($row['year'] ?? '-') . "</td>
                            <td>{$status}</td>
                            <td>{$date}</td>
                          </tr>";
                    $no++;
                }
            } else {
                echo "<tr><td colspan='7' style='text-align:center; padding: 20px;'>Tiada rekod kehadiran dijumpai.</td></tr>";
            }
            ?>
        </tbody>
    </table>

    <script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>

</body>
</html>