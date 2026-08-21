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
<?php
$pm_elb_css_v = @filemtime(__DIR__ . '/export_leaderboard.css') ?: time();
?>
<link rel="stylesheet" href="export_leaderboard.css?v=<?= $pm_elb_css_v ?>">
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
</script>
<?php
$pm_elb_js_v = @filemtime(__DIR__ . '/export_leaderboard.js') ?: time();
?>
<script src="export_leaderboard.js?v=<?= $pm_elb_js_v ?>"></script>
</body>
</html>