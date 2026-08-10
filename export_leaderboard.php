<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

include 'db.php';
$conn = getDB();
session_start();
require __DIR__ . '/auth_check.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['judge', 'pic'])) {
    header("Location: login.php");
    exit();
}

// --- FILTER INPUTS ---
$siri_filter    = $_GET['siri'] ?? '';
$session_filter = $_GET['session'] ?? '';
$level_filter   = $_GET['level'] ?? '';
$judge_filter   = $_GET['judge'] ?? '';
$medal_filter   = $_GET['medal'] ?? '';

// --- FETCH AND CALCULATE DATA (40:30:30 Quota) ---
$allData = [];
// Modified to join siri early on the sessions loop sequence
$sessions = $conn->query("
    SELECT se.*, sr.siri_name 
    FROM sessions se 
    LEFT JOIN siri sr ON se.siri_id = sr.siri_id 
    ORDER BY se.session_id
");

while ($sidang = $sessions->fetch_assoc()) {
    $sidang_id = $sidang['session_id'];
    $sidang_name = htmlspecialchars($sidang['session_name']);
    $siri_name = $sidang['siri_name'] ?? 'Tiada Siri';

    $levels = $conn->query("SELECT * FROM levels WHERE session_id=$sidang_id ORDER BY level_name ASC");
    while ($level = $levels->fetch_assoc()) {
        $level_id = $level['level_id'];
        $level_name = htmlspecialchars($level['level_name']);

        $query = "
            SELECT st.student_name, sc.school_name, j.name AS judge_name,
                   SUM(s.mark) AS total_score, SUM(c.max_mark) AS max_score,
                   ROUND((SUM(s.mark)/SUM(c.max_mark))*100,2) AS percentage
            FROM scores s
            JOIN criteria c ON s.criteria_id = c.criteria_id
            JOIN students st ON s.student_id = st.student_id
            JOIN schools sc ON st.school_id = sc.school_id
            JOIN group_students gs ON st.student_id = gs.student_id
            JOIN `groups` g ON gs.group_id = g.group_id
            LEFT JOIN judges j ON g.judge_id = j.id
            WHERE st.level_id = $level_id AND s.submitted = 1
            GROUP BY st.student_id, j.name
            HAVING total_score > 0
            ORDER BY percentage DESC
        ";

        $result = $conn->query($query);
        
        if ($result && $result->num_rows > 0) {
            $total_students = $result->num_rows;
            
            // Calculate 40:30:30 Quotas per level
            $gold_limit = ceil($total_students * 0.40);
            $silver_limit = $gold_limit + ceil($total_students * 0.30);
            
            $level_rank = 1;
            while ($row = $result->fetch_assoc()) {
                
                if ($level_rank <= $gold_limit) $medal = "Emas";
                elseif ($level_rank <= $silver_limit) $medal = "Perak";
                else $medal = "Gangsa";

                $allData[] = [
                    'student' => $row['student_name'],
                    'school' => $row['school_name'],
                    'siri' => $siri_name,
                    'session' => $sidang_name,
                    'level' => $level_name,
                    'judge' => $row['judge_name'],
                    'total' => $row['total_score'],
                    'max' => $row['max_score'],
                    'percentage' => $row['percentage'],
                    'medal' => $medal
                ];
                
                $level_rank++;
            }
        }
    }
}

// Apply primary sorting engine
usort($allData, function($a, $b) {
    return $b['percentage'] <=> $a['percentage'];
});

$leaderboardData = [];
$rank = 1;
foreach ($allData as $d) {
    if ($siri_filter && $d['siri'] !== $siri_filter) continue;
    if ($session_filter && $d['session'] !== $session_filter) continue;
    if ($level_filter && $d['level'] !== $level_filter) continue;
    if ($judge_filter && $d['judge'] !== $judge_filter) continue;
    if ($medal_filter && $d['medal'] !== $medal_filter) continue;

    $d['rank'] = $rank++;
    $leaderboardData[] = $d;
}

// Distinct values lists for elements filters
$siriList     = $conn->query("SELECT DISTINCT siri_name FROM siri ORDER BY siri_name");
$sessionsList = $conn->query("SELECT DISTINCT session_name FROM sessions ORDER BY session_name");
$levelsList   = $conn->query("SELECT DISTINCT level_name FROM levels ORDER BY level_name");
$judgesList   = $conn->query("SELECT DISTINCT name AS judge_name FROM judges ORDER BY name");
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Papan Pendahulu — ProMarkah</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&family=Bebas+Neue&display=swap" rel="stylesheet">
<link rel="stylesheet" href="dashboard.css">

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
<style>
    /* Default dark theme variables in case layout.php CSS is missing */
    :root {
        --c-bg: #121212;
        --c-surface-0: #1e1e1e;
        --c-surface-1: #252525;
        --c-surface-2: #2e2e2e;
        --c-border: #333333;
        --c-border-strong: #444444;
        --c-text: #e0e0e0;
        --c-text-muted: #aaaaaa;
        --c-text-faint: #777777;
        --c-white: #ffffff;
        --c-red: #d62828;
        --c-red-dim: rgba(214, 40, 40, 0.2);
        --text-xs: 0.75rem;
        --text-sm: 0.875rem;
        --radius-sm: 4px;
        --radius-lg: 12px;
    }

    body {
        background-color: var(--c-bg);
        color: var(--c-text);
        font-family: 'DM Sans', sans-serif;
    }

    /* ── SELECT2 CUSTOM DARK/DYNAMIC MATCH THEME ── */
    .select2-container .select2-selection--single {
        background: var(--c-surface-2) !important;
        border: 1px solid var(--c-border-strong) !important;
        border-radius: 6px !important;
        height: 38px !important;
        transition: border-color 0.2s;
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        color: var(--c-text) !important;
        line-height: 36px !important;
        padding-left: 10px !important;
        font-size: 0.85rem !important;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 36px !important;
    }
    .select2-dropdown {
        background: var(--c-surface-1) !important;
        border: 1px solid var(--c-border-strong) !important;
        box-shadow: 0 8px 24px rgba(0,0,0,.25);
    }
    .select2-results__option {
        color: var(--c-text) !important;
        font-size: 0.85rem;
    }
    .select2-results__option--highlighted {
        background: var(--c-red) !important;
        color: #fff !important;
    }
    .select2-search__field {
        background: var(--c-surface-0) !important;
        color: var(--c-text) !important;
        border: 1px solid var(--c-border-strong) !important;
        border-radius: 4px;
    }

    .lb-page {
        max-width: 1280px;
        margin: 0 auto;
        padding: 40px 24px 60px;
        position: relative;
        z-index: 1;
    }
    .lb-heading {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 2rem;
        letter-spacing: .06em;
        color: var(--c-white);
        margin-bottom: 6px;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .lb-heading-dot {
        width: 8px; height: 8px;
        border-radius: 50%;
        background: var(--c-red);
        flex-shrink: 0;
    }
    .lb-subheading {
        font-size: var(--text-sm);
        color: var(--c-text-faint);
        margin-bottom: 28px;
        padding-left: 20px;
    }
    .lb-filter-card {
        background: var(--c-surface-1);
        border: 1px solid var(--c-border);
        border-radius: var(--radius-lg);
        padding: 20px 24px;
        margin-bottom: 20px;
    }
    .lb-filter-label {
        font-size: var(--text-xs);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .1em;
        color: var(--c-text-muted);
        margin-bottom: 12px;
    }
    .lb-filter-row {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        align-items: flex-end;
    }
    .filter-col {
        flex: 1 1 150px;
        min-width: 0;
    }
    .lb-filter-actions {
        display: flex;
        gap: 8px;
        flex: 0 0 auto;
    }
    
    .pm-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        height: 38px;
        padding: 0 16px;
        border: none;
        border-radius: 6px;
        font-size: 0.85rem;
        font-weight: 700;
        cursor: pointer;
        text-decoration: none;
        white-space: nowrap;
        font-family: 'DM Sans', sans-serif;
    }
    .pm-btn-primary { background: var(--c-red); color: white; }
    .pm-btn-ghost { background: transparent; border: 1px solid var(--c-border-strong); color: var(--c-text); }
    .pm-btn-success { background: #10b981; color: white; }
    .pm-btn-danger { background: var(--c-red); color: white; }

    .lb-result-strip {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 12px;
        flex-wrap: wrap;
        gap: 10px;
    }
    .lb-result-count {
        font-size: var(--text-xs);
        color: var(--c-text-faint);
        text-transform: uppercase;
        letter-spacing: .08em;
    }
    .lb-result-count strong {
        color: var(--c-white);
    }
    .lb-export-actions { display: flex; gap: 8px;}

    .lb-table-wrap {
        background: var(--c-surface-1);
        border: 1px solid var(--c-border);
        border-radius: var(--radius-lg);
        overflow-x: auto;
    }
    .lb-table {
        width: 100%;
        min-width: 950px;
        border-collapse: collapse;
        font-size: var(--text-sm);
    }
    .lb-table th, .lb-table td {
        text-transform: uppercase;
        padding: 12px 16px;
        white-space: nowrap;
    }
    .lb-table th {
        background: var(--c-surface-2);
        color: var(--c-text-muted);
        font-weight: 600;
        font-size: var(--text-xs);
        letter-spacing: .08em;
        text-align: left;
        border-bottom: 1px solid var(--c-border-strong);
    }
    .lb-table th.num, .lb-table td.num { text-align: center; }
    .lb-table td {
        color: var(--c-text);
        border-bottom: 1px solid var(--c-border);
        vertical-align: middle;
    }
    .lb-table tr:last-child td { border-bottom: none; }
    .lb-table tbody tr:hover td { background: var(--c-surface-2); }

    .lb-rank {
        font-family: 'DM Mono', monospace;
        color: var(--c-text-faint);
        text-align: center;
        width: 1%;
    }
    .lb-rank-top { color: var(--c-white); font-weight: 700; }
    .lb-name { font-weight: 600; color: var(--c-white); }
    .lb-school { color: var(--c-text-faint); font-size: var(--text-xs); }
    .lb-score { font-family: 'DM Mono', monospace; text-align: center; }
    .lb-score-pct { font-size: var(--text-xs); color: var(--c-text-faint); display: block; }

    .lb-medal {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 12px;
        border-radius: 999px;
        font-size: var(--text-xs);
        font-weight: 700;
    }
    .lb-medal-dot { width: 6px; height: 6px; border-radius: 50%; }
    .lb-medal-Emas   { background: rgba(255,215,0,.12);  color: #FFD700; border: 1px solid rgba(255,215,0,.30); }
    .lb-medal-Emas   .lb-medal-dot { background: #FFD700; }
    .lb-medal-Perak  { background: rgba(192,192,192,.12); color: #C8C8C8; border: 1px solid rgba(192,192,192,.30); }
    .lb-medal-Perak .lb-medal-dot { background: #C8C8C8; }
    .lb-medal-Gangsa { background: rgba(205,127,50,.12); color: #CD8E4F; border: 1px solid rgba(205,127,50,.30); }
    .lb-medal-Gangsa .lb-medal-dot{ background: #CD8E4F; }

    .lb-empty { padding: 60px 24px; text-align: center; color: var(--c-text-faint); }
    .lb-back { display: inline-flex; align-items: center; gap: 6px; color: var(--c-text-muted); text-decoration: none; margin-bottom: 20px; }
    .lb-back:hover { color: var(--c-white); }

    @media (max-width: 768px) {
        .filter-col { flex: 1 1 calc(50% - 12px); }
    }
    @media (max-width: 480px) {
        .filter-col { flex: 1 1 100%; }
        .lb-filter-actions { width: 100%; }
        .lb-filter-actions button, .lb-filter-actions a { flex: 1; text-align: center; justify-content: center; }
    }
</style>
</head>

<body class="pm-body">
<div class="pm-overlay"></div>

<div class="lb-page">

    <?php
    $back_href  = ($_SESSION['role'] === 'pic') ? 'pic.php' : 'judge.php';
    $back_label = ($_SESSION['role'] === 'pic') ? '← Kembali ke Papan Pemuka PIC' : '← Kembali ke Papan Pemuka Juri';
    ?>
    <a href="<?= $back_href ?>" class="lb-back"><?= $back_label ?></a>

    <div class="lb-heading">
        <span class="lb-heading-dot"></span>
        Papan Pendahulu
    </div>
    <p class="lb-subheading">Keputusan keseluruhan berdasarkan markah 40:30:30</p>

    <div class="lb-filter-card">
        <div class="lb-filter-label">Tapis Keputusan</div>
        <form method="GET">
            <div class="lb-filter-row">
                <div class="filter-col">
                    <select name="siri" class="lb-filter">
                        <option value="">Semua Siri</option>
                        <?php while ($siri = $siriList->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($siri['siri_name']) ?>" <?= ($siri_filter==$siri['siri_name'])?'selected':'' ?>>
                                <?= htmlspecialchars($siri['siri_name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="filter-col">
                    <select name="session" class="lb-filter">
                        <option value="">Semua Sidang</option>
                        <?php while ($s = $sessionsList->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($s['session_name']) ?>" <?= ($session_filter==$s['session_name'])?'selected':'' ?>>
                                <?= htmlspecialchars($s['session_name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="filter-col">
                    <select name="level" class="lb-filter">
                        <option value="">Semua Peringkat</option>
                        <?php while ($l = $levelsList->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($l['level_name']) ?>" <?= ($level_filter==$l['level_name'])?'selected':'' ?>>
                                <?= htmlspecialchars($l['level_name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="filter-col">
                    <select name="judge" class="lb-filter">
                        <option value="">Semua Juri</option>
                        <?php while ($j = $judgesList->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($j['judge_name']) ?>" <?= ($judge_filter==$j['judge_name'])?'selected':'' ?>>
                                <?= htmlspecialchars($j['judge_name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="filter-col">
                    <select name="medal" class="lb-filter">
                        <option value="">Semua Pingat</option>
                        <option value="Emas"   <?= ($medal_filter=='Emas')  ?'selected':'' ?>>🥇 Emas</option>
                        <option value="Perak"  <?= ($medal_filter=='Perak') ?'selected':'' ?>>🥈 Perak</option>
                        <option value="Gangsa" <?= ($medal_filter=='Gangsa')?'selected':'' ?>>🥉 Gangsa</option>
                    </select>
                </div>

                <div class="lb-filter-actions">
                    <button type="submit" class="pm-btn pm-btn-primary">Tapis</button>
                    <a href="export_leaderboard.php" class="pm-btn pm-btn-ghost">Padam Tapis</a>
                </div>
            </div>
        </form>
    </div>

    <div class="lb-result-strip">
        <div class="lb-result-count">
            Menunjukkan <strong><?= count($leaderboardData) ?></strong> peserta
        </div>
        <div class="lb-export-actions">
            <button class="pm-btn pm-btn-success" onclick="exportExcel()">↓ Excel</button>
            <button class="pm-btn pm-btn-danger" onclick="exportPDF()">↓ PDF</button>
        </div>
    </div>

    <div class="lb-table-wrap">
        <table class="lb-table" id="lbTable">
            <thead>
                <tr>
                    <th class="num" style="width:1%">#</th>
                    <th>Nama Pesilat</th>
                    <th>Siri</th>
                    <th>Sidang</th>
                    <th>Peringkat</th>
                    <th>Juri</th>
                    <th class="num">Markah</th>
                    <th class="num">Pingat</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($leaderboardData)): ?>
                    <tr>
                        <td colspan="8">
                            <div class="lb-empty">
                                <div class="lb-empty-icon">🏅</div>
                                Tiada keputusan dijumpai. Cuba ubah tetapan tapisan.
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($leaderboardData as $d):
                        $isTop3 = $d['rank'] <= 3;
                    ?>
                    <tr>
                        <td class="lb-rank <?= $isTop3 ? 'lb-rank-top' : '' ?>">
                            <?= $isTop3 ? ['🥇','🥈','🥉'][$d['rank']-1] : $d['rank'] ?>
                        </td>
                        <td>
                            <div class="lb-name"><?= htmlspecialchars($d['student']) ?></div>
                            <div class="lb-school"><?= htmlspecialchars($d['school']) ?></div>
                        </td>
                        <td><?= htmlspecialchars($d['siri']) ?></td>
                        <td><?= htmlspecialchars($d['session']) ?></td>
                        <td><?= htmlspecialchars($d['level']) ?></td>
                        <td><?= htmlspecialchars($d['judge']) ?></td>
                        <td class="lb-score num">
                            <?= $d['total'] ?> / <?= $d['max'] ?>
                            <span class="lb-score-pct"><?= $d['percentage'] ?>%</span>
                        </td>
                        <td class="num">
                            <span class="lb-medal lb-medal-<?= $d['medal'] ?>">
                                <span class="lb-medal-dot"></span>
                                <?= $d['medal'] ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<script>
const leaderboardData = <?php echo json_encode($leaderboardData); ?>;

$(function() {
    $('.lb-filter').select2({ width: '100%' });
});

function exportExcel() {
    const table = document.getElementById('lbTable');
    const wb = XLSX.utils.table_to_book(table, { sheet: "Leaderboard" });
    XLSX.writeFile(wb, "Leaderboard_Export.xlsx");
}

function exportPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(14);
    doc.text("Papan Pendahulu — ProMarkah", 40, 38);

    const rows = leaderboardData.map(d => [
        d.rank,
        d.student,
        d.siri,
        d.session,
        d.level,
        d.judge,
        `${d.total} / ${d.max}`,
        `${d.percentage}%`,
        d.medal
    ]);

    doc.autoTable({
        head: [["#","Nama Pesilat","Siri","Sidang","Peringkat","Juri","Markah","Peratus","Pingat"]],
        body: rows,
        startY: 52,
        styles: { fontSize: 8, halign: 'center', cellPadding: 5,
                  fillColor: [24,24,24], textColor: [255,255,255],
                  lineColor: [60,60,60], lineWidth: 0.5 },
        headStyles: { fillColor: [214,40,40], textColor: [255,255,255], fontStyle: 'bold' },
        alternateRowStyles: { fillColor: [35,35,35] },
        columnStyles: { 1: { halign: 'left' }, 4: { halign: 'left' } },
    });
    doc.save('Leaderboard_Export.pdf');
}
</script>
</body>
</html>