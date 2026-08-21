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
<?php
$pm_sb_css_v = @filemtime(__DIR__ . '/silibus_baru.css') ?: time();
?>
<link rel="stylesheet" href="silibus_baru.css?v=<?= $pm_sb_css_v ?>">
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
