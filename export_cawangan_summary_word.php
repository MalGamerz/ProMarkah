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
    <style>
        /* Landscape A4 for Word specifically — the plain CSS `@page {size:
           ... landscape}` a browser/PDF export reads is NOT reliably
           honored by Word's HTML importer for orientation. This named
           "Section1" page + div.Section1{page:Section1} pairing is the
           actual mechanism Word's importer looks for (mso-page-orientation
           is the property that matters; `size` here just gives the
           matching landscape dimensions in points). */
        @page Section1 {
            size: 842.0pt 595.0pt;
            mso-page-orientation: landscape;
            margin: 1.5cm;
        }
        div.Section1 { page: Section1; }

        /* Same font/format as the PDF export (export_cawangan_summary_pdf.php)
           — kept identical on purpose so Word/PDF read as the same report. */
        body { font-family: Arial, sans-serif; color: #000; text-align: justify; line-height: 1.5; }
        .title { text-align: center; font-weight: bold; font-size: 15pt; text-transform: uppercase; margin-bottom: 24px; }
        /* page-break-after:avoid keeps a heading glued to whatever follows
           it — without this a heading can land alone at the bottom of a
           page while its first table gets pushed to the next one. */
        h3 { font-size: 12pt; text-transform: uppercase; border-bottom: 2px solid #000; padding-bottom: 3px; margin-top: 28px; page-break-after: avoid; }
        table { width: 100%; border-collapse: collapse; font-size: 10.5pt; margin-bottom: 20px; }
        th, td { border: 1px solid #000; padding: 6px 9px; text-align: left; vertical-align: top; }
        th { background: #eee; font-weight: bold; text-transform: uppercase; font-size: 9.5pt; }
        /* One <table> per cawangan (see pm_render_grouped_tables) — tight
           spacing so a run of them still reads as one continuous list. */
        .pm-cawangan-table { margin-bottom: 2px; page-break-inside: avoid; }
    </style>
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
