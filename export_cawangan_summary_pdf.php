<?php
// ── Export (PDF): Ujian Kenaikan Tali Pinggang — Peringkat Cawangan ──────
// Same 4 tables as pic_cawangan_summary.php — query logic shared via
// cawangan_report_data.php. Rendered as a print-friendly HTML page (same
// "print / save as PDF" pattern export_attendance_pdf.php already uses —
// no PDF library dependency needed).
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
?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($reportTitle) ?></title>
<?php
$pm_ecspdf_css_v = @filemtime(__DIR__ . '/export_cawangan_summary_pdf.css') ?: time();
?>
<link rel="stylesheet" href="export_cawangan_summary_pdf.css?v=<?= $pm_ecspdf_css_v ?>">
</head>
<body>

    <div class="controls no-print">
        <button class="btn btn-back" onclick="window.close()">Tutup</button>
        <button class="btn" onclick="window.print()">Cetak / Simpan sebagai PDF</button>
    </div>

    <div class="title"><?= htmlspecialchars($reportTitle) ?></div>

    <div class="pm-report-section">
        <h3>SENARAI CAWANGAN</h3>
        <table>
            <thead><tr><th style="text-align:center;">Bil</th><th>Cawangan</th></tr></thead>
            <tbody>
                <?php
                if (empty($data['rows1'])) {
                    echo "<tr><td colspan='2' style='text-align:center;'>Tiada cawangan dengan kumpulan pelajar ditemui.</td></tr>";
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
    </div>

    <div class="pm-report-section">
        <h3>SENARAI PELAJAR MENGIKUT CAWANGAN</h3>
        <?php
        if (empty($data['rows2'])) {
            echo "<table><tr><td style='text-align:center;'>Tiada pelajar ditemui.</td></tr></table>";
        } else {
            echo pm_render_grouped_tables($data['rows2'], ['Nama Pelajar'], function ($r) {
                return '<td>' . htmlspecialchars($r['student_name']) . '</td>';
            });
        }
        ?>
    </div>

    <div class="pm-report-section">
        <h3>PERINGKAT MENGIKUT CAWANGAN</h3>
        <table>
            <thead><tr><th style="text-align:center;">Bil</th><th style="text-align:center;">Cawangan</th><th style="text-align:center;">Peringkat</th></tr></thead>
            <tbody>
                <?php
                if (empty($data['rows3'])) {
                    echo "<tr><td colspan='3' style='text-align:center;'>Tiada cawangan dengan kumpulan pelajar ditemui.</td></tr>";
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
    </div>

    <div class="pm-report-section">
        <h3>PERINGKAT SETIAP PELAJAR</h3>
        <?php
        if (empty($data['rows4'])) {
            echo "<table><tr><td style='text-align:center;'>Tiada pelajar ditemui.</td></tr></table>";
        } else {
            echo pm_render_grouped_tables($data['rows4'], ['Nama Pelajar', 'Peringkat'], function ($r) {
                return '<td>' . htmlspecialchars($r['student_name']) . '</td>'
                     . '<td>' . htmlspecialchars(strtoupper($r['level_name'])) . '</td>';
            });
        }
        ?>
    </div>

    <script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>

</body>
</html>
