<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Force browsers/proxies to always fetch a fresh copy of this page — this
// page has broken more than once on stale cached HTML/JS after a deploy
// (old logic kept running silently until a hard-refresh), so don't rely on
// default caching behavior here.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

session_start();
require __DIR__ . '/auth_check.php';
include 'db.php';
$conn = getDB();

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['judge', 'pic'])) {
    header("Location: login.php");
    exit();
}

// ══════════════════════════════════════════════════════════════════
// Build the leaderboard data (quotas, ranking, medal assignment).
// Runs once — used both for the live AJAX poll and the initial page load.
// ══════════════════════════════════════════════════════════════════
function build_leaderboard_data(mysqli $conn): array
{
    // 0. Peringkat display order — mirrors pic_levels.php's drag-reorder.
    // sort_order is NOT globally unique — pic_levels.php assigns it (and
    // lets the PIC drag-reorder it) per session, restarting at 0 for every
    // session (see the MAX(sort_order)+1 WHERE session_id=... insert and
    // the per-session "order" loop in pic_levels.php's POST handler), and
    // its own display order iterates sessions first (by session_name),
    // THEN each session's levels by sort_order. Ordering levels by
    // sort_order alone (ignoring session) collides those per-session
    // sequences together and comes out essentially alphabetical instead —
    // sessions must be the primary sort key here too.
    $level_order = [];
    $r = $conn->query(
        "SELECT l.level_id FROM levels l
         JOIN sessions se ON l.session_id = se.session_id
         ORDER BY se.session_name, l.sort_order, l.level_name, l.level_id"
    );
    if ($r) { $i = 0; while ($row = $r->fetch_assoc()) $level_order[$row['level_id']] = $i++; }

    // 1. Load ALL quota tables in parallel
    $quotas_level = [];
    $r = $conn->query("SELECT level_id, gold_quota, silver_quota, bronze_quota FROM medal_quotas WHERE level_id != 0");
    if ($r) while ($row = $r->fetch_assoc())
        $quotas_level[$row['level_id']] = [(int)$row['gold_quota'], (int)$row['silver_quota'], (int)$row['bronze_quota']];

    $quotas_session = [];
    $r = $conn->query("SELECT session_id, gold_quota, silver_quota, bronze_quota FROM medal_quotas_session");
    if ($r) while ($row = $r->fetch_assoc())
        $quotas_session[$row['session_id']] = [(int)$row['gold_quota'], (int)$row['silver_quota'], (int)$row['bronze_quota']];

    $quotas_school = [];
    $r = $conn->query("SELECT school_id, gold_quota, silver_quota, bronze_quota FROM medal_quotas_school");
    if ($r) while ($row = $r->fetch_assoc())
        $quotas_school[$row['school_id']] = [(int)$row['gold_quota'], (int)$row['silver_quota'], (int)$row['bronze_quota']];

    $quota_overall = [0, 0, 0];
    $r = $conn->query("SELECT gold_quota, silver_quota, bronze_quota FROM medal_quotas WHERE level_id = 0");
    if ($r && $row = $r->fetch_assoc())
        $quota_overall = [(int)$row['gold_quota'], (int)$row['silver_quota'], (int)$row['bronze_quota']];

    // 2. Resolve quota for a student with priority cascade
    $resolve_quota = function (int $school_id, int $level_id, int $session_id, int $total,
                                array $qs_school, array $qs_level, array $qs_session, array $q_overall): array
    {
        $build = function(array $q, string $label) {
            return [$q[0], $q[0] + $q[1], $label];
        };

        if (isset($qs_school[$school_id]) && ($qs_school[$school_id][0] || $qs_school[$school_id][1]))
            return $build($qs_school[$school_id], 'school');

        if (isset($qs_level[$level_id]) && ($qs_level[$level_id][0] || $qs_level[$level_id][1]))
            return $build($qs_level[$level_id], 'level');

        if (isset($qs_session[$session_id]) && ($qs_session[$session_id][0] || $qs_session[$session_id][1]))
            return $build($qs_session[$session_id], 'session');

        if ($q_overall[0] || $q_overall[1])
            return $build($q_overall, 'overall');

        // Nisbah 40:30:30, distributed fairly via the largest-remainder method
        // so small fields (e.g. 4 students) don't get skewed to 0 in a tier —
        // each tier gets its floor share, then leftover seats go to whichever
        // tier has the largest fractional remainder.
        $exact = [$total * 0.40, $total * 0.30, $total * 0.30];
        $floor = array_map('intval', $exact);
        $seats_left = $total - array_sum($floor);

        $remainders = [];
        foreach ($exact as $i => $v) $remainders[$i] = $v - $floor[$i];
        arsort($remainders); // largest fractional remainder first

        foreach (array_keys($remainders) as $i) {
            if ($seats_left <= 0) break;
            $floor[$i]++;
            $seats_left--;
        }

        $g = $floor[0];
        $s = $g + $floor[1];
        return [$g, $s, 'auto'];
    };

    // 3. Main data query (Includes Siri Table Join)
    $sql = "
        SELECT st.student_id, st.student_name, st.year, st.level_id,
               sc.school_id, sc.school_name,
               j.name AS judge_name,
               se.session_id, se.session_name,
               sr.siri_name,
               l.level_name,
               SUM(s.mark)           AS total_score,
               SUM(c.max_mark)       AS max_score,
               ROUND((SUM(s.mark)/SUM(c.max_mark))*100, 2) AS percentage
        FROM scores s
        JOIN criteria c        ON s.criteria_id   = c.criteria_id
        JOIN students st       ON s.student_id     = st.student_id
        JOIN schools sc        ON st.school_id     = sc.school_id
        JOIN levels l          ON st.level_id      = l.level_id
        JOIN sessions se       ON l.session_id     = se.session_id
        LEFT JOIN siri sr      ON se.siri_id       = sr.siri_id
        JOIN group_students gs ON st.student_id    = gs.student_id
        JOIN `groups` g      ON gs.group_id      = g.group_id
        LEFT JOIN judges j     ON g.judge_id       = j.id
        WHERE s.submitted = 1
        GROUP BY st.student_id, j.name, se.session_id, se.session_name, sr.siri_name, l.level_name, st.level_id
        HAVING total_score > 0
        ORDER BY st.level_id ASC, percentage DESC
    ";

    $result = $conn->query($sql);
    $leaderboard_data = [];

    if ($result && $result->num_rows > 0) {

        // First pass — collect all rows and count per level, and per
        // level+school (the latter powers the per-Cawangan nisbah below).
        $all_rows = [];
        $students_per_level = [];
        $students_per_level_school = [];
        while ($row = $result->fetch_assoc()) {
            $all_rows[] = $row;
            $lid = $row['level_id'];
            $scid = $row['school_id'];
            $students_per_level[$lid] = ($students_per_level[$lid] ?? 0) + 1;
            $students_per_level_school[$lid][$scid] = ($students_per_level_school[$lid][$scid] ?? 0) + 1;
        }

        // Per-student, per-test subtotal marks (ordered alphabetically by test_name)
        // — used to break ties between students with the same overall percentage.
        $student_test_scores = [];
        $tie_sql = "
            SELECT s.student_id, t.test_name, SUM(s.mark) AS test_score
            FROM scores s
            JOIN criteria c ON s.criteria_id = c.criteria_id
            JOIN tests t    ON c.test_id     = t.test_id
            WHERE s.submitted = 1
            GROUP BY s.student_id, t.test_id, t.test_name
            ORDER BY t.test_name ASC
        ";
        $tie_result = $conn->query($tie_sql);
        if ($tie_result) {
            while ($trow = $tie_result->fetch_assoc()) {
                $student_test_scores[$trow['student_id']][] = (float) $trow['test_score'];
            }
        }

        // Rank students within each level: overall percentage DESC, then tie-break
        // by comparing test scores in order (ujian pertama, kedua, ...) until a
        // difference is found — the student with the higher mark ranks above.
        usort($all_rows, function ($a, $b) use ($student_test_scores, $level_order) {
            if ($a['level_id'] != $b['level_id']) {
                $oa = $level_order[$a['level_id']] ?? PHP_INT_MAX;
                $ob = $level_order[$b['level_id']] ?? PHP_INT_MAX;
                return $oa <=> $ob;
            }
            if ($a['percentage'] != $b['percentage']) {
                return $b['percentage'] <=> $a['percentage'];
            }
            $ta = $student_test_scores[$a['student_id']] ?? [];
            $tb = $student_test_scores[$b['student_id']] ?? [];
            $n  = max(count($ta), count($tb));
            for ($i = 0; $i < $n; $i++) {
                $va = $ta[$i] ?? 0;
                $vb = $tb[$i] ?? 0;
                if ($va != $vb) {
                    return $vb <=> $va;
                }
            }
            return 0;
        });

        // Second pass — assign medals using priority cascade
        $current_level  = null;
        $rank           = 1;
        $gold_limit     = 0;
        $silver_limit   = 0;
        $quota_source   = 'auto';

        // Per-Cawangan (level+school) rank/quota — same priority cascade as
        // above, but when it falls through to 'auto' the 40:30:30 nisbah is
        // computed against that school's own entrant count within the level,
        // not the whole level's cross-school total. rows aren't contiguous
        // by school (all_rows is sorted level-then-percentage), so ranks are
        // tracked per group key rather than reset on a "current" pointer.
        $group_rank  = [];
        $group_quota = [];

        foreach ($all_rows as $row) {
            $lid = $row['level_id'];
            $sid = $row['session_id'];
            $scid = $row['school_id'];

            if ($current_level !== $lid) {
                $current_level = $lid;
                $rank = 1;
                $total = $students_per_level[$lid];
                [$gold_limit, $silver_limit, $quota_source] = $resolve_quota(
                    $scid, $lid, $sid, $total,
                    $quotas_school, $quotas_level, $quotas_session, $quota_overall
                );
            }

            if ($quota_source !== 'school' && isset($quotas_school[$scid])
                && ($quotas_school[$scid][0] || $quotas_school[$scid][1])) {
                [$gold_limit, $silver_limit, $quota_source] = $resolve_quota(
                    $scid, $lid, $sid, $students_per_level[$lid],
                    $quotas_school, $quotas_level, $quotas_session, $quota_overall
                );
            }

            if ($rank <= $gold_limit)        { $medal = "🥇 Emas"; }
            elseif ($rank <= $silver_limit)  { $medal = "🥈 Perak"; }
            else                             { $medal = "🥉 Gangsa"; }

            $gkey = $lid . ':' . $scid;
            if (!isset($group_quota[$gkey])) {
                $group_quota[$gkey] = $resolve_quota(
                    $scid, $lid, $sid, $students_per_level_school[$lid][$scid],
                    $quotas_school, $quotas_level, $quotas_session, $quota_overall
                );
                $group_rank[$gkey] = 1;
            }
            [$gold_limit_s, $silver_limit_s, $quota_source_s] = $group_quota[$gkey];
            $rank_s = $group_rank[$gkey];

            if ($rank_s <= $gold_limit_s)       { $medal_school = "🥇 Emas"; }
            elseif ($rank_s <= $silver_limit_s) { $medal_school = "🥈 Perak"; }
            else                                { $medal_school = "🥉 Gangsa"; }

            $group_rank[$gkey]++;

            $leaderboard_data[] = [
                'year'                => $row['year'],
                'siri'                => $row['siri_name'] ?? 'Tiada Siri',
                'session'             => $row['session_name'],
                'level'               => $row['level_name'],
                'student'             => $row['student_name'],
                'school'              => $row['school_name'],
                'judge'               => $row['judge_name'],
                'total'               => $row['total_score'],
                'max'                 => $row['max_score'],
                'percentage'          => $row['percentage'],
                'medal'               => $medal,
                'quota_source'        => $quota_source,
                'medal_school'        => $medal_school,
                'quota_source_school' => $quota_source_s,
            ];

            $rank++;
        }
    }

    return $leaderboard_data;
}

// ── Live AJAX poll endpoint: leaderboard.php?ajax=1 ──
// Returns just the JSON data so the page can refresh itself without a
// full reload, the same "AJAX polling" approach used for notifications
// in layout.php (safe on shared hosting — no websockets required).
if (($_GET['ajax'] ?? '') === '1') {
    header('Content-Type: application/json');
    echo json_encode(build_leaderboard_data($conn));
    exit();
}

$leaderboard_data = build_leaderboard_data($conn);

$pm_page = 'leaderboard';
include 'layout.php';
?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>

<?php
$pm_lb_css_v = @filemtime(__DIR__ . '/leaderboard.css') ?: time();
?>
<link rel="stylesheet" href="leaderboard.css?v=<?= $pm_lb_css_v ?>">

<h2 class="pm-page-heading">🏆 Papan Kedudukan Keseluruhan</h2>

<div class="pm-card" style="margin-bottom: 20px;">
    <div class="filter-bar">
        <div class="filter-col">
            <label class="filter-label">Tahun</label>
            <select id="filter-year" class="lb-filter"><option value="">Semua Tahun</option></select>
        </div>
        <div class="filter-col">
            <label class="filter-label">Siri</label>
            <select id="filter-siri" class="lb-filter"><option value="">Semua Siri</option></select>
        </div>
        <div class="filter-col">
            <label class="filter-label">Sidang</label>
            <select id="filter-session" class="lb-filter"><option value="">Semua Sidang</option></select>
        </div>
        <div class="filter-col">
            <label class="filter-label">Peringkat</label>
            <select id="filter-level" class="lb-filter"><option value="">Semua Peringkat</option></select>
        </div>
        <div class="filter-col">
            <label class="filter-label">Cawangan</label>
            <select id="filter-school" class="lb-filter"><option value="">Semua Cawangan</option></select>
        </div>
        <div class="filter-col">
            <label class="filter-label">Juri</label>
            <select id="filter-judge" class="lb-filter"><option value="">Semua Juri</option></select>
        </div>
        <div class="filter-col">
            <label class="filter-label">Pingat</label>
            <select id="filter-medal" class="lb-filter">
                <option value="">Semua Pingat</option>
                <option>🥇 Emas</option>
                <option>🥈 Perak</option>
                <option>🥉 Gangsa</option>
            </select>
        </div>
        
        <div class="filter-col export-col">
            <button onclick="exportToExcel()" class="btn-export" style="background: #10b981; border: 1px solid #059669;">📥 Excel</button>
            <button onclick="exportToPDF('landscape')" class="btn-export" style="background: #b91c1c; border: 1px solid #991b1b;">📄 PDF (L)</button>
            <button onclick="exportToPDF('portrait')" class="btn-export" style="background: #991b1b; border: 1px solid #7f1d1d;">📄 PDF (P)</button>
        </div>
    </div>
</div>

<?php
echo "<script>let leaderboardData = " . json_encode($leaderboard_data) . ";</script>";
?>

<div id="leaderboard-tables"></div>

<?php
$pm_lb_js_v = @filemtime(__DIR__ . '/leaderboard.js') ?: time();
?>
<script src="leaderboard.js?v=<?= $pm_lb_js_v ?>"></script>
</main>
</body>
</html>