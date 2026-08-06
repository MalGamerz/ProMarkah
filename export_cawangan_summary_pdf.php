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
    <style>
        /* A4 landscape — applies both on screen (so the print preview
           already shows the right shape) and when actually printed. */
        @page { size: A4 landscape; margin: 12mm; }
        body { font-family: Arial, sans-serif; color: #000; margin: 24px; text-align: justify; line-height: 1.5; }
        .title { text-align: center; font-weight: bold; font-size: 15pt; text-transform: uppercase; margin-bottom: 24px; }
        /* page-break-after:avoid keeps a heading glued to whatever follows
           it — without this a heading can land alone at the bottom of a
           page while its first table gets pushed to the next one. */
        h3 { font-size: 12pt; text-transform: uppercase; border-bottom: 2px solid #000; padding-bottom: 3px; margin-top: 28px; page-break-after: avoid; }
        table { width: 100%; border-collapse: collapse; font-size: 10.5pt; }
        th, td { border: 1px solid #000; padding: 6px 9px; text-align: left; vertical-align: top; }
        th { background: #eee; font-weight: bold; text-transform: uppercase; font-size: 9.5pt; }
        /* One <table> per cawangan (see pm_render_grouped_tables) — tight
           spacing so a run of them still reads as one continuous list. */
        .pm-cawangan-table { margin-bottom: 2px; page-break-inside: avoid; }
        @media print {
            .no-print { display: none !important; }
            body { margin: 0; }
        }
        .controls { margin-bottom: 24px; text-align: right; }
        .btn { padding: 8px 16px; background: #333; color: white; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 0.9rem; }
        .btn-back { background: #999; margin-right: 10px; }
    </style>
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
