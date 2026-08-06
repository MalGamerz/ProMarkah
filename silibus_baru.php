<?php
// ══════════════════════════════════════════════════════════════════
//  silibus_baru.php — printable manual scoring sheet built from the
//  CURRENT live syllabus data (levels/tests/criteria tables), always
//  reflecting whatever peringkat/ujian/kriteria exist right now.
// ══════════════════════════════════════════════════════════════════
session_start();
require __DIR__ . '/auth_check.php';
include 'db.php';
$conn = getDB();

// Same audience as silibus.php (judge reference material).
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'judge') {
    header("Location: login.php");
    exit();
}

$levels   = $conn->query("SELECT level_id, level_name FROM levels ORDER BY sort_order, level_name")->fetch_all(MYSQLI_ASSOC);
$tests    = $conn->query("SELECT test_id, level_id, test_name FROM tests ORDER BY sort_order, test_name")->fetch_all(MYSQLI_ASSOC);
$criteria = $conn->query("SELECT criteria_id, test_id, criteria_name, max_mark FROM criteria ORDER BY sort_order, criteria_name")->fetch_all(MYSQLI_ASSOC);

$testsByLevel = [];
foreach ($tests as $test) $testsByLevel[$test['level_id']][] = $test;

$criteriaByTest = [];
foreach ($criteria as $c) $criteriaByTest[$c['test_id']][] = ['name' => $c['criteria_name'], 'max_mark' => (int)($c['max_mark'] ?? 10)];
?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Senarai Silibus (Semasa) — Export</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            color: #2b2b2b;
            margin: 24px;
            background: #f4f4f4;
        }
        .sheet {
            max-width: 1000px;
            margin: 0 auto;
            background: #fff;
            padding: 32px 36px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        .header-title {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 3px solid #b30000;
        }
        .header-title h2 {
            margin: 0 0 6px 0;
            color: #b30000;
            font-size: 1.4rem;
            letter-spacing: 0.02em;
        }
        .header-title p {
            margin: 2px 0;
            font-size: 0.82rem;
            color: #777;
        }
        .fill-in-fields {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
            background: #fafafa;
            border: 1px solid #e2e2e2;
            border-radius: 6px;
            overflow: hidden;
        }
        .fill-in-fields td {
            border: none;
            border-bottom: 1px solid #e8e8e8;
            padding: 10px 16px;
            font-size: 0.9rem;
            width: 50%;
        }
        .fill-in-fields tr:last-child td {
            border-bottom: none;
        }
        .fill-in-fields .fill-field {
            display: flex;
            align-items: baseline;
            white-space: nowrap;
        }
        .fill-in-fields td strong {
            color: #444;
            flex: 0 0 auto;
        }
        .fill-line {
            flex: 1 1 auto;
            min-width: 80px;
            border-bottom: 1px solid #999;
            margin-left: 8px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.87rem;
        }
        th, td {
            border: 1px solid #e0e0e0;
            padding: 10px 14px;
            text-align: left;
            vertical-align: middle;
        }
        th {
            background-color: #b30000;
            color: #ffffff;
            text-transform: uppercase;
            font-size: 0.72rem;
            letter-spacing: 0.05em;
            font-weight: 700;
        }
        thead {
            display: table-header-group; /* repeat header on every printed page */
        }
        #silibusBaruTable {
            border: 1px solid #ddd;
            border-radius: 6px;
            overflow: hidden;
        }
        /* Peringkat/Ujian labels stand out from the plain criteria rows */
        #silibusBaruTable td[rowspan] {
            font-weight: 700;
            color: #1a1a1a;
            background-color: #fbf3f3;
        }
        tbody.silibus-baru-group:nth-of-type(even) td:not([rowspan]) {
            background-color: #fafafa;
        }
        .empty-note {
            color: #999;
            font-style: italic;
        }
        /* Plain blank cell — no ruled-line background. A repeating-linear-
           gradient here used to draw a horizontal line every 28px, but a
           row's actual height varies with its criteria text (e.g. "Elakan,
           Tangkapan, Pukulan 1" wraps to two lines and grows past 28px),
           so the pattern kept drawing a stray extra line partway through
           taller cells — looked like a random cut through the middle of
           the mark box. Each row only needs one blank spot to write one
           number in, so there's no need for a multi-line ruled pattern
           at all. */
        .mark-cell {
            min-width: 100px;
            height: 34px;
        }
        .jumlah-row td {
            background-color: #fdecec !important;
            border-top: 2px solid #b30000;
            color: #b30000;
            font-size: 0.9rem;
        }
        /* Keep each Peringkat/Ujian block (rows + its JUMLAH row) together —
           never split across a page/PDF boundary, so JUMLAH is never
           stranded alone at the top of the next page. */
        .silibus-baru-group {
            break-inside: avoid;
            page-break-inside: avoid;
        }
        /* Hide controls/search when printing */
        @media print {
            body { margin: 0; background: #fff; }
            .sheet { box-shadow: none; padding: 0; max-width: none; }
            .no-print { display: none !important; }
        }
        .controls {
            margin-bottom: 20px;
            text-align: right;
        }
        .btn {
            padding: 9px 18px;
            background: #b30000;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
        }
        .btn-back {
            background: #666;
            margin-right: 10px;
        }
        .search-box {
            margin-bottom: 16px;
            text-align: left;
        }
        .search-box input {
            width: 100%;
            max-width: 400px;
            padding: 9px 14px;
            font-size: 0.9rem;
            border: 1px solid #ccc;
            border-radius: 20px;
        }
        /* Screen-only horizontal scroll for the criteria table — this page
           has no responsive breakpoints at all (it's a print-first design:
           table cells use min-width:100px per mark box across 5 columns,
           easily 500px+ total), so on a phone the table was just getting
           silently clipped past the screen edge with no way to reach the
           Markah Penuh/Markah columns. Scoped to screen only (not inside
           @media print) so the printed/PDF output is completely unaffected
           — it already gets max-width:none there anyway. */
        .table-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        @media print {
            .table-scroll { overflow: visible; }
        }
        /* Mobile: reclaim the fixed 24px body margin + 32px/36px sheet
           padding (72px+96px combined eaten from a 375px screen before any
           content even starts), and stack Nama Kumpulan/Nama Pelajar and
           Nama Juri/Tarikh into one column instead of two — each field's
           white-space:nowrap + 80px fill-line needs real room that a
           375px-wide 2-column layout doesn't have, which is what was
           truncating "Nama Pelajar:" down to "Nama Pe" before. */
        @media (max-width: 640px) {
            body { margin: 8px; }
            .sheet { padding: 16px; }
            .fill-in-fields tr { display: flex; flex-direction: column; }
            .fill-in-fields td { width: 100%; }
        }
    </style>
</head>
<body>

    <div class="controls no-print">
        <button class="btn btn-back" onclick="window.close()">Tutup</button>
        <button class="btn" onclick="window.print()">🖨️ Cetak / Simpan sebagai PDF</button>
    </div>

    <div class="sheet">

    <div class="header-title">
        <h2>Borang Markah Manual</h2>
    </div>

    <table class="fill-in-fields">
        <tr>
            <td><span class="fill-field"><strong>Nama Kumpulan:</strong> <span class="fill-line"></span></span></td>
            <td><span class="fill-field"><strong>Nama Pelajar:</strong> <span class="fill-line"></span></span></td>
        </tr>
        <tr>
            <td><span class="fill-field"><strong>Nama Juri:</strong> <span class="fill-line"></span></span></td>
            <td><span class="fill-field"><strong>Tarikh:</strong> <span class="fill-line"></span></span></td>
        </tr>
    </table>

    <div class="search-box no-print">
        <input type="text" id="silibusBaruSearch" placeholder="Cari tahap, ujian, kriteria...">
    </div>

    <div class="table-scroll no-print-scroll">
    <table id="silibusBaruTable">
        <thead>
            <tr>
                <th>Peringkat (Tahap)</th>
                <th>Nama Ujian</th>
                <th>Kriteria</th>
                <th>Markah Penuh</th>
                <th>Markah</th>
            </tr>
        </thead>
        <?php foreach ($levels as $level):
            $tahap_name = htmlspecialchars($level['level_name']);
            $levelTests = $testsByLevel[$level['level_id']] ?? [];
            // Peringkat with no ujian at all — skip entirely, don't print a placeholder row.
            if (empty($levelTests)) continue;
            foreach ($levelTests as $test):
                $ujian_name    = htmlspecialchars($test['test_name']);
                $testCriteria  = $criteriaByTest[$test['test_id']] ?? [];
                // Ujian with no kriteria — skip entirely too, same reason.
                if (empty($testCriteria)) continue;
                $rowspan       = count($testCriteria) + 1; // +1 for the JUMLAH row
                $totalMax      = array_sum(array_column($testCriteria, 'max_mark'));
        ?>
        <tbody class="silibus-baru-group">
        <?php
                foreach ($testCriteria as $ci => $crit):
                    $isFirst = $ci === 0;
        ?>
                <tr>
                    <?php if ($isFirst): ?>
                    <td rowspan="<?= $rowspan ?>"><?= $tahap_name ?></td>
                    <td rowspan="<?= $rowspan ?>"><?= $ujian_name ?></td>
                    <?php endif; ?>
                    <td><?= htmlspecialchars($crit['name']) ?></td>
                    <td style="text-align:center;"><?= $crit['max_mark'] ?></td>
                    <td class="mark-cell">&nbsp;</td>
                </tr>
        <?php endforeach; ?>
                <tr class="jumlah-row">
                    <td><strong>JUMLAH</strong></td>
                    <td style="text-align:center;"><strong><?= $totalMax ?></strong></td>
                    <td class="mark-cell">&nbsp;</td>
                </tr>
        </tbody>
        <?php
            endforeach;
        endforeach; ?>
    </table>
    </div>

    </div>

    <script>
        document.getElementById('silibusBaruSearch').addEventListener('input', function () {
            const keyword = this.value.toLowerCase();
            document.querySelectorAll('.silibus-baru-group').forEach(function (group) {
                group.style.display = group.innerText.toLowerCase().includes(keyword) ? '' : 'none';
            });
        });
    </script>

</body>
</html>
