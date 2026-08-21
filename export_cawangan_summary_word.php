<?php
// ── Export (Word): Ujian Kenaikan Tali Pinggang — Peringkat Cawangan ─────
// Same 4 tables as pic_cawangan_summary.php — query logic shared via
// cawangan_report_data.php. Served as an HTML document with a .doc
// extension + application/msword MIME type — Word/LibreOffice Writer open
// that directly; no document-generation library needed.
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

session_start();
require __DIR__ . '/auth_check.php';
include 'db.php';
$conn = getDB();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pic') {
    header("Location: login.php");
    exit();
}

include 'cawangan_report_data.php';

$active_siri = (int)($_SESSION['active_siri_id'] ?? 0);
$reportTitle = pm_cawangan_report_title($conn, $active_siri);
$data = pm_cawangan_report_data($conn, $active_siri);

$filename = pm_cawangan_report_filename($reportTitle, 'doc');
header("Content-Type: application/msword");
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header("Pragma: no-cache");
header("Expires: 0");
?>
<!DOCTYPE html>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40" lang="ms">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($reportTitle) ?></title>
    <!--[if gte mso 9]>
    <xml>
        <w:WordDocument>
            <w:View>Print</w:View>
        </w:WordDocument>
    </xml>
    <![endif]-->
<?php
$pm_ecsw_css_v = @filemtime(__DIR__ . '/export_cawangan_summary_word.css') ?: time();
?>
<link rel="stylesheet" href="export_cawangan_summary_word.css?v=<?= $pm_ecsw_css_v ?>">
</head>
<body>
    <div class="Section1">
    <div class="title"><?= htmlspecialchars($reportTitle) ?></div>

    <h3>SENARAI CAWANGAN</h3>
    <table>
        <thead><tr><th style="text-align:center;">Bil</th><th>Cawangan</th></tr></thead>
        <tbody>
            <?php
            if (empty($data['rows1'])) {
                echo "<tr><td colspan='2'>Tiada cawangan dengan kumpulan pelajar ditemui.</td></tr>";
            } else {
                $no = 1;
                foreach ($data['rows1'] as $row) {
                    echo "<tr><td style='text-align:center;'>{$no}</td><td>" . htmlspecialchars($row['school_name']) . "</td></tr>";
                    $no++;
                }
            }
            ?>
        </tbody>
    </table>

    <h3>SENARAI PELAJAR MENGIKUT CAWANGAN</h3>
    <?php
    if (empty($data['rows2'])) {
        echo "<table><tr><td>Tiada pelajar ditemui.</td></tr></table>";
    } else {
        echo pm_render_grouped_tables($data['rows2'], ['Nama Pelajar'], function ($r) {
            return '<td>' . htmlspecialchars($r['student_name']) . '</td>';
        });
    }
    ?>

    <h3>PERINGKAT MENGIKUT CAWANGAN</h3>
    <table>
        <thead><tr><th style="text-align:center;">Bil</th><th style="text-align:center;">Cawangan</th><th style="text-align:center;">Peringkat</th></tr></thead>
        <tbody>
            <?php
            if (empty($data['rows3'])) {
                echo "<tr><td colspan='3'>Tiada cawangan dengan kumpulan pelajar ditemui.</td></tr>";
            } else {
                $no = 1;
                foreach ($data['rows3'] as $row) {
                    $peringkat = implode('<br>', array_map(fn($p) => htmlspecialchars(strtoupper($p)), $row['peringkat']));
                    echo "<tr><td style='text-align:center;'>{$no}</td><td style='text-align:center;'>" . htmlspecialchars($row['school_name']) . "</td><td style='text-align:center;'>{$peringkat}</td></tr>";
                    $no++;
                }
            }
            ?>
        </tbody>
    </table>

    <h3>PERINGKAT SETIAP PELAJAR</h3>
    <?php
    if (empty($data['rows4'])) {
        echo "<table><tr><td>Tiada pelajar ditemui.</td></tr></table>";
    } else {
        echo pm_render_grouped_tables($data['rows4'], ['Nama Pelajar', 'Peringkat'], function ($r) {
            return '<td>' . htmlspecialchars($r['student_name']) . '</td>'
                 . '<td>' . htmlspecialchars(strtoupper($r['level_name'])) . '</td>';
        });
    }
    ?>
    </div>
</body>
</html>
<?php
exit;
