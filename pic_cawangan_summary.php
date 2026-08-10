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

<style>
/* ── Formal report styling — plain black/white, no icons or colour
   accents. This page IS the report (also what gets exported), not an app
   dashboard widget, so it's kept deliberately unadorned. ── */
.pm-report-title {
    text-align: center;
    font-family: Arial, sans-serif;
    font-weight: 700;
    font-size: 1.3rem;
    letter-spacing: 0.02em;
    color: var(--c-text);
    margin: 0 0 24px;
    text-transform: uppercase;
}
.pm-report-actions {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-bottom: 28px;
}
.pm-report-actions a {
    font-family: Arial, sans-serif;
    font-size: 0.82rem;
    padding: 7px 16px;
    border: 1px solid var(--c-border-strong);
    border-radius: 3px;
    color: var(--c-text);
    text-decoration: none;
    background: var(--c-surface-1);
}
.pm-report-actions a:hover { background: var(--c-surface-2); }

.pm-report-section { margin-bottom: 36px; }
.pm-report-section h3 {
    font-family: Arial, sans-serif;
    font-size: 1rem;
    font-weight: 700;
    color: var(--c-text);
    text-transform: uppercase;
    margin: 0 0 10px;
    border-bottom: 2px solid var(--c-text);
    padding-bottom: 4px;
    /* Keep the heading glued to whatever comes right after it — without
       this, a heading can land alone at the bottom of a page while its
       first table gets pushed to the next one. */
    page-break-after: avoid;
}
.pm-report-table {
    width: 100%;
    border-collapse: collapse;
    font-family: Arial, sans-serif;
    font-size: 0.85rem;
}
.pm-report-table th,
.pm-report-table td {
    border: 1px solid var(--c-border-strong);
    padding: 7px 10px;
    text-align: left;
    color: var(--c-text);
    vertical-align: top;
}
.pm-report-table th {
    background: var(--c-surface-2);
    font-weight: 700;
    text-transform: uppercase;
    font-size: 0.78rem;
}
.pm-report-table td.center { text-align: center; }

/* One <table> per cawangan (see pm_render_grouped_tables) — tight spacing
   between them so a run of cawangan tables still reads as one continuous
   list, not a series of oddly separated blocks. */
.pm-cawangan-table { margin-bottom: 2px; page-break-inside: avoid; }
.pm-cawangan-banner {
    text-align: left;
    background: var(--c-surface-3);
    font-size: 0.82rem;
}

/* A4 landscape if this page itself is printed directly (the actual PDF
   export has its own copy of this, since it's a separate document). */
@media print {
    @page { size: A4 landscape; margin: 12mm; }
    .pm-report-actions { display: none; }
    body { text-align: justify; }
}
</style>

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
