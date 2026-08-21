<?php
// ── Laporan: Ujian Kenaikan Tali Pinggang — Peringkat Cawangan ───────────
// Four separate report tables:
//   SENARAI CAWANGAN
//   SENARAI PELAJAR MENGIKUT CAWANGAN
//   PERINGKAT MENGIKUT CAWANGAN
//   PERINGKAT SETIAP PELAJAR
// Query logic lives in cawangan_report_data.php, shared with the 3 exports
// (Excel/PDF/Word) so all four outputs can never drift apart.
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

session_start();
require __DIR__ . '/auth_check.php';
include 'db.php';
$conn = getDB();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pic') {
    header("Location: login.php");
    exit();
}
session_write_close();

$pm_page = 'cawangan_summary';
include 'layout.php';
include 'cawangan_report_data.php';

$active_siri = (int)($_SESSION['active_siri_id'] ?? 0);
$reportTitle = pm_cawangan_report_title($conn, $active_siri);
$data = pm_cawangan_report_data($conn, $active_siri);
?>

<?php
$pm_pcs_css_v = @filemtime(__DIR__ . '/pic_cawangan_summary.css') ?: time();
?>
<link rel="stylesheet" href="pic_cawangan_summary.css?v=<?= $pm_pcs_css_v ?>">

<div class="pm-report-title"><?= htmlspecialchars($reportTitle) ?></div>

<div class="pm-report-actions">
    <a href="export_cawangan_summary.php">Muat Turun (Excel)</a>
    <a href="export_cawangan_summary_pdf.php" target="_blank">Muat Turun (PDF)</a>
    <a href="export_cawangan_summary_word.php">Muat Turun (Word)</a>
</div>

<div class="pm-report-section">
    <h3>SENARAI CAWANGAN</h3>
    <table class="pm-report-table">
        <thead>
            <tr>
                <th style="width:10%;text-align:center;">Bil</th>
                <th>Cawangan</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($data['rows1'])): ?>
            <tr><td colspan="2" class="center">Tiada cawangan dengan kumpulan pelajar ditemui.</td></tr>
            <?php else: $no = 1; foreach ($data['rows1'] as $row): ?>
            <tr>
                <td class="center"><?= $no++ ?></td>
                <td><?= htmlspecialchars($row['school_name']) ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div class="pm-report-section">
    <h3>SENARAI PELAJAR MENGIKUT CAWANGAN</h3>
    <?php if (empty($data['rows2'])): ?>
    <table class="pm-report-table"><tbody><tr><td class="center">Tiada pelajar ditemui.</td></tr></tbody></table>
    <?php else: ?>
    <?= pm_render_grouped_tables($data['rows2'], ['Nama Pelajar'], function ($r) {
        return '<td>' . htmlspecialchars($r['student_name']) . '</td>';
    }) ?>
    <?php endif; ?>
</div>

<div class="pm-report-section">
    <h3>PERINGKAT MENGIKUT CAWANGAN</h3>
    <table class="pm-report-table">
        <thead>
            <tr>
                <th style="width:8%;text-align:center;">Bil</th>
                <th style="width:32%;text-align:center;">Cawangan</th>
                <th style="text-align:center;">Peringkat</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($data['rows3'])): ?>
            <tr><td colspan="3" class="center">Tiada cawangan dengan kumpulan pelajar ditemui.</td></tr>
            <?php else: $no = 1; foreach ($data['rows3'] as $row): ?>
            <tr>
                <td class="center"><?= $no++ ?></td>
                <td class="center"><?= htmlspecialchars($row['school_name']) ?></td>
                <td class="center"><?= implode('<br>', array_map(fn($p) => htmlspecialchars(strtoupper($p)), $row['peringkat'])) ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div class="pm-report-section">
    <h3>PERINGKAT SETIAP PELAJAR</h3>
    <?php if (empty($data['rows4'])): ?>
    <table class="pm-report-table"><tbody><tr><td class="center">Tiada pelajar ditemui.</td></tr></tbody></table>
    <?php else: ?>
    <?= pm_render_grouped_tables($data['rows4'], ['Nama Pelajar', 'Peringkat'], function ($r) {
        return '<td>' . htmlspecialchars($r['student_name']) . '</td>'
             . '<td>' . htmlspecialchars(strtoupper($r['level_name'])) . '</td>';
    }) ?>
    <?php endif; ?>
</div>

</main>
</body>
</html>
