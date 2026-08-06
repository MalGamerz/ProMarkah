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

<style>
    /* ── SELECT2 appearance now comes from the shared filter_bar.css,
       loaded via layout.php — only page-specific width override stays. ── */
    .filter-bar .select2-container { width: 100% !important; }

    /* ── FILTER BAR ── */
    .filter-bar {
        display: flex;
        flex-wrap: nowrap; /* Forces a single line */
        gap: 8px; /* Uniform gap between every column, filters and export alike */
        align-items: flex-end;
        justify-content: space-between; /* Distribute spacing evenly across the row */
        overflow-x: auto; /* Allows smooth swipe on smaller laptops without breaking */
        padding-bottom: 4px; /* Room for focus rings */
    }
    .filter-bar::-webkit-scrollbar { height: 4px; }
    .filter-bar::-webkit-scrollbar-thumb { background: var(--c-border-strong); border-radius: 4px; }

    .filter-col {
        flex: 1 1 0; /* Equal basis so every column grows to the same width */
        display: flex;
        flex-direction: column;
        gap: 4px;
        min-width: 110px; /* Prevents Select2 from getting completely crushed */
    }

    /* EXPORT BUTTONS COLUMN */
    .filter-col.export-col {
        flex: 0 0 auto;
        display: flex;
        flex-direction: row;
        align-items: flex-end;
        gap: 8px; /* Same gap as the filter columns for uniform spacing */
        margin-bottom: 0;
    }

    .filter-label {
        font-size: 0.65rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--c-text-faint);
        white-space: nowrap;
    }
    
    /* Export button */
    .btn-export {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        height: 32px; /* Matches the 32px Select2 dropdown height */
        padding: 0 8px; /* Reduced padding */
        color: #ffffff !important;
        border-radius: 4px;
        font-size: 0.75rem; /* Smaller font to fit everything */
        font-weight: 700;
        letter-spacing: 0.02em;
        font-family: 'DM Sans', sans-serif;
        cursor: pointer;
        text-decoration: none;
        white-space: nowrap;
        transition: all 0.2s ease-in-out;
    }
    
    .btn-export:hover { 
        filter: brightness(0.9);
        transform: translateY(-1px);
    }

    .medal-cell {
        font-size: 1.15rem;
        font-weight: bold;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.5);
    }
    /* border-left accent eats into the cell's padding, so compensate the
       first cell's left padding to keep content aligned with the header
       (which has no border). */
    .gold-row   { background: rgba(255,215,0,  0.10) !important; border-left: 4px solid #ffd700; }
    .silver-row { background: rgba(192,192,192,0.08) !important; border-left: 4px solid #c0c0c0; }
    .bronze-row { background: rgba(205,127,50, 0.10) !important; border-left: 4px solid #cd7f32; }
    .gold-row   td:first-child,
    .silver-row td:first-child,
    .bronze-row td:first-child { padding-left: 14px !important; }
    .gold-row:hover   { background: rgba(255,215,0,  0.18) !important; }
    .silver-row:hover { background: rgba(192,192,192,0.15) !important; }
    .bronze-row:hover { background: rgba(205,127,50, 0.18) !important; }
    /* The site-wide ".pm-table tbody tr:hover td" rule paints a flat grey
       directly on the <td>, which visually overrides the tinted colors
       above (those are set on the <tr>, and a td's own background wins).
       That grey happens to look just like the perak/silver row tint,
       making every hovered row look silver regardless of its real medal.
       Repaint at the td level so hovering keeps each row's own medal color. */
    .gold-row:hover   td { background: rgba(255,215,0,  0.18) !important; }
    .silver-row:hover td { background: rgba(192,192,192,0.15) !important; }
    .bronze-row:hover td { background: rgba(205,127,50, 0.18) !important; }

    .quota-badge {
        display: inline-block;
        font-size: 0.68rem;
        font-family: 'DM Mono', monospace;
        font-weight: 600;
        letter-spacing: 0.04em;
        padding: 2px 7px;
        border-radius: 10px;
        vertical-align: middle;
        margin-left: 6px;
    }
    .quota-badge.school  { background: rgba(99,179,237,0.18);  color: #63b3ed;         border: 1px solid rgba(99,179,237,0.35); }
    .quota-badge.level   { background: rgba(214,40,40,0.15);   color: var(--c-red);    border: 1px solid rgba(214,40,40,0.3); }
    .quota-badge.session { background: rgba(154,230,180,0.15); color: #68d391;         border: 1px solid rgba(104,211,145,0.3); }
    .quota-badge.overall { background: rgba(183,148,244,0.15); color: #b794f4;         border: 1px solid rgba(183,148,244,0.3); }
    .quota-badge.auto    { background: rgba(160,160,160,0.12); color: var(--c-text-faint); border: 1px solid var(--c-border); }

    .pm-table-wrap {
        overflow: auto;
        -webkit-overflow-scrolling: touch;
        width: 100%;
    }
    /* border-collapse: collapse (the site-wide .pm-table default) merges
       adjacent cell borders into shared lines, which has well-documented
       Chrome/Safari rendering bugs when any cell in the table is
       position:sticky — border-collapse: separate sidesteps that merged
       model entirely. This page only has ONE sticky element left (the
       header row), which is the well-supported case for this pattern —
       unlike the earlier attempt that also made a body-row cell sticky,
       which is what actually caused the overlap bug. */
    .pm-table { border-collapse: separate; border-spacing: 0; }
    .pm-table-wrap thead th {
        position: sticky;
        top: 0;
        z-index: 4;
        background: #1f1f1f;
    }
    /* Non-sticky banner — position:sticky on a <td> proved too unreliable
       across browsers (caused a visible overlap with the row directly
       below it). It scrolls with the table now instead of staying pinned;
       less fancy, but it renders correctly, which the sticky version
       repeatedly did not. */
    .level-divider-row {
        pointer-events: none; /* row can never enter :hover state, so no hover rule anywhere can touch it */
    }
    tr.level-divider-row td {
        box-sizing: border-box;
        height: 38px;
        /* Subtle tinted fill (same family as the gold/silver/bronze row
           tints below, just quieter) so the section break reads as a clear
           band across the table instead of blending into the header/body
           chrome — matches the tahap-tinted group rows on Silibus. Falls
           back to the red accent below when the Peringkat name doesn't
           mention a known belt colour (.tahap-* classes override this). */
        background: var(--c-red-dim) !important;
        border-top: 1px solid var(--c-border-strong);
        border-bottom: 1px solid var(--c-border-strong);
        white-space: normal !important;
    }
    /* Belt-colour tints — same keyword convention as Silibus's tahap-*
       classes ("... Cula <Colour> <N>"), so a Peringkat's divider band
       matches the colour its own name names instead of always reading red. */
    tr.level-divider-row.tahap-hijau td  { background: rgba(34, 197, 94, 0.14) !important; }
    tr.level-divider-row.tahap-merah td  { background: rgba(248, 113, 113, 0.12) !important; }
    tr.level-divider-row.tahap-kuning td { background: rgba(234, 179, 8, 0.14) !important; }
    tr.level-divider-row.tahap-hitam td  { background: rgba(161, 161, 170, 0.12) !important; }
    html.pm-light tr.level-divider-row.tahap-hijau td  { background: rgba(22, 163, 74, 0.10) !important; }
    html.pm-light tr.level-divider-row.tahap-merah td  { background: rgba(220, 38, 38, 0.09) !important; }
    html.pm-light tr.level-divider-row.tahap-kuning td { background: rgba(202, 138, 4, 0.12) !important; }
    html.pm-light tr.level-divider-row.tahap-hitam td  { background: rgba(63, 63, 70, 0.08) !important; }
    /* NOT text-align:center — this table is wider than its viewport
       (.pm-table-wrap scrolls horizontally) and this cell spans
       colspan="10", so a centered label sits in the middle of the FULL
       table width, which lands far outside the visible scroll area on
       any narrow/mobile viewport (confirmed: label ended up ~860px into
       a 341px-wide viewport, completely off-screen with the row reading
       as a blank band). position:sticky keeps it pinned to the visible
       left edge of the scroll container at ANY scroll position instead —
       strictly better than a fixed center OR the original left-align,
       since it stays visible even mid-scroll. */
    .level-divider-row td:last-child {
        text-align: left !important;
    }
    /* Bolder + larger than a plain row label, with an icon accent that
       matches the belt colour, so it's unmistakably a section header
       rather than a quiet aside. position:sticky/left keeps it pinned to
       the visible left edge of .pm-table-wrap's horizontal scroll at ANY
       scroll position — see the comment above for why. */
    .level-divider-label {
        display: flex;
        align-items: center;
        justify-content: center; /* Centers the icon and text together */
        width: 100%;             /* Forces the label to span the full row width */
        gap: 7px;
        color: var(--c-text);
        font-family: 'DM Sans', sans-serif;
        font-weight: 700;
        font-size: 0.82rem;
        letter-spacing: 0.06em;
        text-transform: uppercase;
    }
    
    .level-divider-icon {
        width: 14px;
        height: 14px;
        flex: 0 0 auto;
        color: var(--c-red);
    }
    
    .level-divider-row.tahap-hijau  .level-divider-icon { color: #16a34a; }
    .level-divider-row.tahap-merah  .level-divider-icon { color: #dc2626; }
    .level-divider-row.tahap-kuning .level-divider-icon { color: #ca8a04; }
    .level-divider-row.tahap-hitam  .level-divider-icon { color: #71717a; }
    @media (max-width: 768px) {
        tr.level-divider-row td { height: 30px; }
        .level-divider-label { font-size: 0.75rem; letter-spacing: 0.05em; }
    }
    #leaderboard-tables {
        display: flex;
        flex-direction: column;
        min-height: 0;
    }
    
    #leaderboard-tables .pm-card {
        display: flex;
        flex-direction: column;
        min-height: 0;
        /* Safari/WebKit can let position:sticky children leak past a
           border-radius + overflow:hidden clip mask, showing the card's
           own background through a hairline seam. Forcing a mask image
           makes WebKit re-apply the clip correctly on every repaint. */
        -webkit-mask-image: -webkit-radial-gradient(white, black);
    }
    
    .pm-table { min-width: 100%; }

    /* 1. Auto layout — every column sizes to its own actual longest cell
       content instead of the fixed % widths below being treated as hard
       constraints. This is what actually fixes the "either overflow on
       edge-case content (3-digit scores, 'Gangsa') or dead space on the
       common case" tradeoff that hand-tuning percentages under
       table-layout:fixed kept bouncing between — auto layout doesn't have
       that tradeoff because column width tracks real content, not a
       manually guessed number. The width:X% attributes below still act as
       soft hints, not hard caps. */
    #lbTable { table-layout: auto; width: 100%; }

    /* 2. Unified spacing & alignment (Replaces all previous duplicates) */
    #lbTable td, #lbTable th {
        text-align: left !important;
        white-space: normal !important;
        padding: 8px 6px !important;
        vertical-align: middle !important;
        word-wrap: break-word;
        line-height: 1.3;
        text-transform: uppercase;
    }

    /* Ensure old truncate classes wrap instead of cutting off */
    #lbTable .lb-truncate {
        white-space: normal !important;
        word-wrap: break-word;
    }

    /* 3. Center align specific short-data columns to keep things tidy.
       Markah/Peratus were removed from this list — those two columns got
       widened to fit rare edge cases (3-digit scores, "100.00%"), so for
       the common short case ("54 / 70") centering left visible dead space
       on both sides instead of just on the right like every other
       left-aligned column. Left-align reads tighter without needing to
       shrink the column back down and reintroduce the overflow it was
       widened to fix. */
    #lbTable td:nth-child(1), #lbTable th:nth-child(1),   /* Ked. */
    #lbTable td:nth-child(3), #lbTable th:nth-child(3),   /* Tahun */
    #lbTable td:nth-child(4), #lbTable th:nth-child(4),   /* Siri */
    #lbTable td:nth-child(5), #lbTable th:nth-child(5),   /* Sidang */
    #lbTable td:nth-child(11), #lbTable th:nth-child(11)  /* Pingat */ {
        text-align: center !important;
    }

    /* 4. PREVENT awkward mid-word breaks / unwanted wrapping on short
       fixed-data columns. Under table-layout:auto (current), nowrap here
       is safe and correct — it tells the browser this column needs at
       least enough width for its content on one line, and auto layout
       grows the column to honor that instead of overflowing. (That's
       different from the old table-layout:fixed setup, where forcing
       nowrap on an already-too-narrow fixed column caused the text to
       visually bleed into the next cell instead of growing — Sidang was
       deliberately left out of this list for that reason back then. Now
       that layout is auto, that risk no longer applies, so it's back in.) */
    #lbTable td:nth-child(1), #lbTable th:nth-child(1),   /* Ked. */
    #lbTable td:nth-child(3), #lbTable th:nth-child(3),   /* Tahun */
    #lbTable td:nth-child(4), #lbTable th:nth-child(4),   /* Siri */
    #lbTable td:nth-child(5), #lbTable th:nth-child(5),   /* Sidang */
    #lbTable td:nth-child(9), #lbTable th:nth-child(9),   /* Markah */
    #lbTable td:nth-child(10), #lbTable th:nth-child(10), /* Peratus */
    #lbTable td:nth-child(11), #lbTable th:nth-child(11)  /* Pingat */ {
        white-space: nowrap !important;
        word-wrap: normal !important;
    }

    /* Allow the table header to draw outside its box if necessary */
    #lbTable thead th {
        overflow: visible;
    }

    /* ── IMPROVED RESPONSIVENESS ── */
    @media (max-width: 1200px) {
        .filter-bar { flex-wrap: wrap; overflow-x: visible; }
        .filter-col { flex: 1 1 calc(25% - 8px); }
        .filter-col.export-col { width: 100%; justify-content: flex-end; margin-top: 8px; }
    }
    @media (max-width: 768px) {
        .filter-col { flex: 1 1 calc(33.333% - 8px); }
    }
    @media (max-width: 480px) {
        .filter-col { flex: 1 1 100%; }
        .filter-col.export-col { flex-direction: column; width: 100%; align-items: stretch; }
        .btn-export { width: 100%; }
    }

    /* On mobile the rounded/clipped card box around the table was fighting
       with the horizontal scroll — drop the card chrome and let the table
       just be a plain long scrollable list, full width, no rounding/mask.
       The table itself keeps the same fixed 1400px layout as desktop
       (single-line, ellipsized, tooltip on tap-hold) and scrolls sideways —
       wrapping cells to multiple lines was tried and made every long name
       break letter-by-letter into unreadable vertical stacks, which is
       worse than a horizontal scrollbar. */
    @media (max-width: 1024px) {
        #leaderboard-tables .pm-card {
            border-radius: 0;
            -webkit-mask-image: none;
            overflow: visible;
        }
        #leaderboard-tables .pm-table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        /* 🚨 THE FIX: Force a minimum width on mobile/tablets so the table
           stays wide and readable, triggering the horizontal scrollbar!
           Bumped from 1050 to 1200 to give the wider Nama Pesilat/Juri
           columns more absolute room before names have to wrap. */
        .pm-table {
            min-width: 1200px !important;
        }
        #lbTable {
            min-width: 1200px !important;
        }
    }

</style>

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

<script>
    function escapeHtml(str) {
        return String(str ?? '').replace(/[&<>"']/g, function(c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // Maps a Peringkat name to its belt colour key, same keyword convention
    // as silibus_tahap_key() in silibus.php ("... Cula <Colour> <N>") — kept
    // in sync so a level's divider band and its Silibus row tint agree.
    function levelTahapKey(levelName) {
        const needle = String(levelName ?? '').toLowerCase();
        if (needle.includes('hijau'))  return 'hijau';
        if (needle.includes('merah'))  return 'merah';
        if (needle.includes('kuning')) return 'kuning';
        if (needle.includes('hitam'))  return 'hitam';
        return null;
    }

    // ── Quota source badge labels ──────────────────────────────────
    const quotaBadge = {
        school:  '<span class="quota-badge school"  title="Kuota ditetapkan mengikut sekolah">🏫 Sekolah</span>',
        level:   '<span class="quota-badge level"   title="Kuota ditetapkan mengikut peringkat">🏆 Peringkat</span>',
        session: '<span class="quota-badge session" title="Kuota ditetapkan mengikut sidang">📅 Sidang</span>',
        overall: '<span class="quota-badge overall" title="Kuota global keseluruhan">🌐 Global</span>',
        auto:    '' 
    };

    // ── Populate filter dropdowns — rebuildable so a live refresh can pick
    //    up newly-appeared years/siris/schools/etc without losing the
    //    student's current selection. ──────────────────────────────
    function populateFilterOptions() {
        const defs = [
            ['filter-year',    'Semua Tahun',     d => d.year,    (a, b) => String(b).localeCompare(String(a), undefined, { numeric: true })],
            ['filter-siri',    'Semua Siri',      d => d.siri,    (a, b) => a.localeCompare(b, undefined, { numeric: true })],
            ['filter-session', 'Semua Sidang',    d => d.session, (a, b) => a.localeCompare(b, undefined, { numeric: true })],
            ['filter-level',   'Semua Peringkat', d => d.level,   (a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' })],
            ['filter-school',  'Semua Cawangan',  d => d.school,  (a, b) => a.localeCompare(b, undefined, { numeric: true })],
            ['filter-judge',   'Semua Juri',      d => d.judge,   (a, b) => a.localeCompare(b, undefined, { numeric: true })],
        ];

        defs.forEach(([id, emptyLabel, getter, sorter]) => {
            const el = document.getElementById(id);

            // Don't rebuild a dropdown the user currently has open — replacing
            // its <option> list out from under Select2 mid-interaction is what
            // caused clicks to intermittently miss or close the dropdown on
            // the 3s live-refresh poll.
            const s2 = $(el).data('select2');
            if (s2 && s2.isOpen()) return;

            const current = el.value;
            const values = [...new Set(leaderboardData.map(getter))].filter(Boolean).sort(sorter);

            // Skip the DOM rebuild (and the Select2 resync below) entirely if
            // the option list hasn't actually changed — avoids needless churn
            // on every poll tick, which was another source of the same race.
            const existing = [...el.options].slice(1).map(o => o.value);
            const unchanged = existing.length === values.length && existing.every((v, i) => v === values[i]);
            if (unchanged) return;

            el.innerHTML = `<option value="">${emptyLabel}</option>` +
                values.map(v => `<option value="${escapeHtml(v)}">${escapeHtml(v)}</option>`).join('');

            // Keep the user's selection if that value still exists in the refreshed data
            if (current && values.includes(current)) el.value = current;

            if (s2) $(el).trigger('change.select2');
        });
    }

    populateFilterOptions();

    // ── Init Select2 then wire instant filtering ───────────────────
    $(function() {
        $('.lb-filter').select2({ width: '100%' });
        $('.lb-filter').on('change', applyFilters);
        applyFilters();
        startLiveRefresh();
    });

    // ── Main render ───────────────────────────────────────────────
    function applyFilters() {
        // Preserve vertical scroll position across re-renders (important
        // for the live auto-refresh, so it doesn't yank the view back to top).
        // On desktop the table scrolls internally (fitLeaderboardHeight caps
        // its height), so wrap.scrollTop is what matters. On mobile that cap
        // is removed and the list scrolls with the whole page instead — so
        // it's window.scrollY that actually needs preserving there. Save
        // both; whichever one is the real scroll container gets restored.
        const prevWrap = document.querySelector('#leaderboard-tables .pm-table-wrap');
        const prevScrollTop = prevWrap ? prevWrap.scrollTop : 0;
        const prevWindowScrollY = window.scrollY;

        const fy    = document.getElementById('filter-year').value.toLowerCase();
        const fsiri = document.getElementById('filter-siri').value.toLowerCase();
        const fs    = document.getElementById('filter-session').value.toLowerCase();
        const fl    = document.getElementById('filter-level').value.toLowerCase();
        const fsch  = document.getElementById('filter-school').value.toLowerCase();
        const fj    = document.getElementById('filter-judge').value.toLowerCase();
        const fm    = document.getElementById('filter-medal').value.toLowerCase();

        // A specific Cawangan is selected — scope medal/nisbah to that
        // school's own entrants per Peringkat instead of the cross-school
        // ranking (medal_school/quota_source_school, computed server-side).
        const scoped = fsch !== '';
        const medalOf = d => scoped ? d.medal_school : d.medal;
        const sourceOf = d => scoped ? d.quota_source_school : d.quota_source;

        const filtered = leaderboardData.filter(d =>
            (fy    === '' || String(d.year).toLowerCase() === fy) &&
            (fsiri === '' || d.siri.toLowerCase() === fsiri) &&
            (fs    === '' || d.session.toLowerCase() === fs) &&
            (fl    === '' || d.level.toLowerCase()   === fl) &&
            (fsch  === '' || d.school.toLowerCase()  === fsch) &&
            (fj    === '' || d.judge.toLowerCase()   === fj) &&
            (fm    === '' || medalOf(d).toLowerCase() === fm)
        );

        // Group by Peringkat first (medals are assigned per-Peringkat), then sort
        // by percentage within each Peringkat — so display order always matches
        // the medal ranking instead of interleaving separate Peringkat competitions.
        filtered.sort((a, b) => {
            if (a.level !== b.level) return a.level.localeCompare(b.level);
            return b.percentage - a.percentage;
        });

        // Group by level for showing quota source badge per level header
        const levelSources = {};
        filtered.forEach(d => { if (!levelSources[d.level]) levelSources[d.level] = sourceOf(d); });

        let html = `
        <div class="pm-card" style="padding:0; overflow:hidden;">
            <div style="padding:16px 22px; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--c-border);">
                <h3 style="margin:0; font-family:'Bebas Neue',sans-serif; font-size:1.5rem; letter-spacing:0.05em; color:var(--c-white);">Senarai Keputusan</h3>
                <span style="color:#fff; font-weight:600; background:var(--c-red); padding:4px 12px; border-radius:20px; font-size:0.85rem; box-shadow:0 2px 8px rgba(214,40,40,0.4);">${filtered.length} Rekod</span>
            </div>
            <div class="pm-table-wrap" style="border:none; border-radius:0;">
                <table class="pm-table" id="lbTable">
                    <thead>
                        <tr>
                            <th style="text-align:center;">Ked.</th>
                            <th style="text-align:left;">Nama Pesilat</th>
                            <th style="text-align:left;">Tahun</th>
                            <th style="text-align:left;">Siri</th>
                            <th style="text-align:left;">Sidang</th>
                            <th style="text-align:left;">Cawangan</th>
                            <th style="text-align:left;">Peringkat</th>
                            <th style="text-align:left;">Juri</th>
                            <th style="text-align:left;">Markah</th>
                            <th style="text-align:left;">Peratus</th>
                            <th style="text-align:center;">Pingat</th>
                        </tr>
                    </thead>
                    <tbody>`;

        if (filtered.length === 0) {
            html += `<tr><td colspan="11"><div class="pm-empty-state">
                <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <p>Tiada rekod ditemui untuk tapisan ini.</p></div></td></tr>`;
        } else {
            let rankCounter = 0;
            let prevLevel = null;
            filtered.forEach((s, i) => {
                const isNewLevel = s.level !== prevLevel;
                rankCounter = isNewLevel ? 1 : rankCounter + 1;

                if (isNewLevel) {
                    const tahapKey = levelTahapKey(s.level);
                    const tahapCls = tahapKey ? ` tahap-${tahapKey}` : '';
                    html += `
                    <tr class="level-divider-row${tahapCls}">
                            <td></td>
                            <td colspan="10">
                            <span class="level-divider-label">
                                <svg class="level-divider-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="15" r="6"></circle>
                                    <path d="M9 10.5 7 3h2l3 6 3-6h2l-2 7.5"></path>
                                </svg>
                                ${escapeHtml(s.level)}
                            </span>
                        </td>
                    </tr>`;
                }
                prevLevel = s.level;

                const src = levelSources[s.level] || 'auto';
                const badge = quotaBadge[src] || '';
                const medal = medalOf(s);
                const rowClass = medal.includes('Emas') ? 'gold-row' : medal.includes('Perak') ? 'silver-row' : 'bronze-row';

                html += `
                <tr class="${rowClass}">
                    <td style="text-align:justify; font-weight:bold; color:var(--c-text-faint);">${rankCounter}</td>
                    <td class="lb-truncate" style="text-align:justify; font-weight:600; font-size:0.9rem; color:var(--c-text);" title="${escapeHtml(s.student)}">${escapeHtml(s.student)}</td>
                    <td class="lb-truncate" style="text-align:justify; color:var(--c-text-muted);">${escapeHtml(s.year)}</td>
                    <td class="lb-truncate" style="text-align:justify; color:var(--c-text);" title="${escapeHtml(s.siri)}">${escapeHtml(s.siri)}</td>
                    <td class="lb-truncate" style="text-align:justify; color:var(--c-text);" title="${escapeHtml(s.session)}">${escapeHtml(s.session)}</td>
                    <td class="lb-truncate" style="text-align:justify; color:var(--c-text);" title="${escapeHtml(s.school)}">${escapeHtml(s.school)}</td>

                    <td class="lb-truncate" style="text-align:justify; color:var(--c-text);" title="${escapeHtml(s.level)}">${escapeHtml(s.level)}</td>

                    <td class="lb-truncate" style="text-align:justify; color:var(--c-text-muted);" title="${escapeHtml(s.judge)}">${escapeHtml(s.judge)}</td>
                    <td style="text-align:justify; font-family:monospace; font-size:1.05rem; font-weight:bold; color:var(--c-text-muted);">${s.total} / ${s.max}</td>
                    <td style="text-align:justify; font-weight:700; color:var(--c-red);">${s.percentage}%</td>

                    <td style="text-align:justify;" class="medal-cell">
                        <div style="display: flex; flex-direction: column; align-items: center; gap: 4px;">
                            <span>${escapeHtml(medal)}</span>
                            ${badge}
                        </div>
                    </td>
                </tr>`;
            });
        }

        html += `</tbody></table></div></div>`;
        document.getElementById('leaderboard-tables').innerHTML = html;
        fitLeaderboardHeight();

        const newWrap = document.querySelector('#leaderboard-tables .pm-table-wrap');
        if (newWrap) newWrap.scrollTop = prevScrollTop;
        window.scrollTo(0, prevWindowScrollY);
    }

    // ── Live refresh: AJAX-poll the same endpoint used for the initial
    //    load, the same pattern used for notifications elsewhere in the
    //    app (safe on shared hosting — no websockets needed). ──────────
    // Exponential backoff on failure (same rationale as layout.php's
    // notification poller): stays at 3s while healthy, doubles (capped at
    // 60s) per consecutive failure, resets to 3s on the next success —
    // so a DB hiccup doesn't turn into indefinite full-speed polling.
    const LB_BASE_DELAY = 3000;
    const LB_MAX_DELAY  = 60000;
    let lbFailCount = 0;
    let lbTimer = null;

    // Rebuilding the table mid-scroll (DOM replacement + the scroll-position
    // restore in applyFilters) kills an in-progress touch/momentum scroll —
    // it feels like scrolling randomly "stops". Track whether the user is
    // actively scrolling (page or the table's own internal scroll box) and
    // just skip that poll's re-render entirely while they are; the next
    // 3s tick picks it up once they've stopped. {capture:true} on document
    // catches scroll events from the inner .pm-table-wrap too, since scroll
    // events don't bubble but do fire during the capture phase.
    let lbScrolling = false;
    let lbScrollEndTimer = null;
    document.addEventListener('scroll', () => {
        lbScrolling = true;
        clearTimeout(lbScrollEndTimer);
        lbScrollEndTimer = setTimeout(() => { lbScrolling = false; }, 250);
    }, { passive: true, capture: true });
    document.addEventListener('touchmove', () => {
        lbScrolling = true;
        clearTimeout(lbScrollEndTimer);
        lbScrollEndTimer = setTimeout(() => { lbScrolling = false; }, 250);
    }, { passive: true });

    function fetchLeaderboard() {
        pmFetch('leaderboard.php?ajax=1')
            .then(res => {
                if (!res.ok) throw new Error('Network response was not ok');
                return res.json();
            })
            .then(data => {
                lbFailCount = 0;
                if (!Array.isArray(data)) return;
                leaderboardData = data;
                if (lbScrolling) return; // don't touch the DOM while the user is scrolling
                populateFilterOptions();
                applyFilters();
            })
            .catch(() => {
                // Silently ignore — try again on the next poll
                lbFailCount++;
            })
            .finally(() => {
                const delay = Math.min(LB_BASE_DELAY * Math.pow(2, lbFailCount), LB_MAX_DELAY);
                lbTimer = setTimeout(fetchLeaderboard, delay);
            });
    }

    function startLiveRefresh() {
        lbTimer = setTimeout(fetchLeaderboard, LB_BASE_DELAY);
    }

    // Pause polling while the tab/screen isn't visible (e.g. minimized
    // display), resume immediately (with an instant refresh) when it is.
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            if (lbTimer) clearTimeout(lbTimer);
        } else {
            if (lbTimer) clearTimeout(lbTimer);
            fetchLeaderboard();
        }
    });

    // ── Fit the table's scroll container to the remaining viewport height,
    //    so the page never needs to scroll — only the table body does. ──
    function fitLeaderboardHeight() {
        const wrap = document.querySelector('#leaderboard-tables .pm-table-wrap');
        if (!wrap) return;

        // On mobile, don't cap the table into its own internally-scrolling
        // box — let it flow as a normal long list and scroll with the page.
        if (window.innerWidth <= 768) {
            wrap.style.maxHeight = 'none';
        } else {
            const top = wrap.getBoundingClientRect().top;
            const available = window.innerHeight - top - 24; // bottom breathing room
            wrap.style.maxHeight = Math.max(200, available) + 'px';
        }

        // Match the divider row's sticky offset to the real header height,
        // instead of a guessed constant — avoids the jitter/gap when they
        // don't line up exactly.
        const headRow = wrap.querySelector('thead tr');
        if (headRow) {
            wrap.style.setProperty('--lb-header-h', headRow.getBoundingClientRect().height + 'px');
        }
    }
    window.addEventListener('resize', fitLeaderboardHeight);
    
    // ── EXPORT FUNCTIONS ──────────────────────────────────────────────
    function exportToExcel() {
        const table = document.getElementById('lbTable');
        if (!table) return alert("Tiada data untuk dieksport.");
        
        const wb = XLSX.utils.table_to_book(table, { sheet: "Papan Pendahulu" });
        XLSX.writeFile(wb, "Papan_Pendahulu_ProMarkah.xlsx");
    }
    
    function exportToPDF(orientation = 'landscape') {
        const table = document.getElementById('lbTable');
        if (!table) return alert("Tiada data untuk dieksport.");
    
        const { jsPDF } = window.jspdf;
        const isLandscape = orientation === 'landscape';
        const doc = new jsPDF({ orientation: orientation, unit: 'pt', format: 'a4' });

        // Build dynamic filename based on active filters
        const fYear = document.getElementById('filter-year').value;
        const fSiri = document.getElementById('filter-siri').value;
        const fSession = document.getElementById('filter-session').value;
        const fLevel = document.getElementById('filter-level').value;
        const fMedal = document.getElementById('filter-medal').value;

        let nameParts = ["PAPAN_KEDUDUKAN"];
        if (fSiri) nameParts.push(fSiri);
        if (fSession) nameParts.push(fSession);
        if (fLevel) nameParts.push(fLevel);
        if (fMedal) nameParts.push(fMedal);
        if (fYear) nameParts.push(fYear);

        let baseFileName = nameParts.join('_').toUpperCase().replace(/[^A-Z0-9_]/g, '_');
        doc.setProperties({ title: baseFileName });
        
        const pageWidth = doc.internal.pageSize.width;
        
        // --- 1. SLEEK HEADER SECTION ---
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(isLandscape ? 18 : 15);
        doc.setTextColor(30, 30, 30); 
        doc.text("PAPAN KEDUDUKAN KESELURUHAN", 40, 45);

        doc.setFont('helvetica', 'normal');
        doc.setFontSize(10);
        doc.setTextColor(120, 120, 120);
        doc.text("PROMARKAH — SISTEM PENILAIAN SILAT", 40, 60);

        const today = new Date();
        const dateString = "Dijana pada: " + today.toLocaleDateString('ms-MY') + " " + today.toLocaleTimeString('ms-MY');
        doc.setFontSize(8);
        doc.setTextColor(150, 150, 150);
        doc.text(dateString, pageWidth - 40, 60, { align: 'right' });

        doc.setDrawColor(214, 40, 40); 
        doc.setLineWidth(1.5);
        doc.line(40, 68, pageWidth - 40, 68);
    
        // --- 2. TABLE GENERATION ---

        // Solid accent color for every Peringkat section-divider row —
        // matches the site's red brand color, bold fill + white text, so it
        // reads unmistakably as a divider instead of blending in as a plain
        // white/pale row.
        const dividerColor = { fill: [214, 40, 40], text: [255, 255, 255] };
        const dividerColorFor = () => dividerColor;

        // Root-cause fix: `data.row.raw` is NOT the original <tr> DOM element
        // in this jspdf-autotable version (verified empirically — it has no
        // .classList), so checking its class name here always silently
        // returned false. That's why every previous attempt at coloring
        // this row (pastel-per-level, then solid red) never actually showed
        // up — didDrawCell's paint code was correct, it just never ran.
        // colSpan is a reliable structural signal instead: only the
        // divider's label cell has colSpan > 1 (it spans columns 1–10), and
        // its leading spacer cell (column 0) is the only Rank-column cell
        // that's ever empty — every real data row always has a rank number.
        const isDividerCell = (data) =>
            data.section === 'body' &&
            ((data.cell.colSpan && data.cell.colSpan > 1) ||
             (data.column.index === 0 && data.cell.raw && !data.cell.raw.textContent.trim()));

        // jsPDF's built-in fonts (helvetica/times/courier) use WinAnsi
        // encoding and simply cannot render emoji glyphs — that's why the
        // 🥇/🥈/🥉 medal icons were being stripped out entirely before
        // drawing. A <canvas> CAN render color emoji (browsers fall back to
        // the system emoji font automatically), so rasterize each one once
        // to a small PNG and place that as an image instead of text. Cached
        // per emoji+size so each unique medal is only rasterized once.
        const emojiImageCache = {};
        function emojiToImage(emoji, px) {
            const key = emoji + '@' + px;
            if (emojiImageCache[key]) return emojiImageCache[key];
            const canvas = document.createElement('canvas');
            canvas.width = px;
            canvas.height = px;
            const ctx = canvas.getContext('2d');
            ctx.font = Math.round(px * 0.82) + 'px sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(emoji, px / 2, px / 2 + px * 0.04);
            const url = canvas.toDataURL('image/png');
            emojiImageCache[key] = url;
            return url;
        }

        const EMOJI_RE = /[\u{1F000}-\u{1FFFF}\u{2600}-\u{27BF}\u{2B00}-\u{2BFF}\u{FE0F}]/gu;

        // The Pingat column ("🥇 Emas" etc.) — column index 10, only real
        // data rows (not the divider). Drawn manually in didDrawCell below,
        // same reasoning as the divider: full control over layout so the
        // word can never get force-wrapped ("GANG"/"SA") the way autoTable's
        // own linebreak was doing at this column's width.
        const isPingatCell = (data) =>
            data.section === 'body' && data.column.index === 10 && !isDividerCell(data);

        doc.autoTable({
            html: '#lbTable',
            startY: 85,
            // A wrapped multi-line cell (e.g. a long Cawangan/Peringkat
            // value) could otherwise get sliced in half by a page break —
            // half its lines on one page, the rest continuing alone at the
            // top of the next. 'avoid' keeps a row's lines together, moving
            // the whole row to the next page instead of splitting it.
            rowPageBreak: 'avoid',
            didParseCell: function(data) {
                if (data.cell.text && typeof data.cell.text[0] === 'string') {
                    // Strip emoji only (jsPDF's built-in helvetica font can't
                    // render them — e.g. the 🥇/🥈/🥉 medal icons) — NOT the
                    // old [^\x00-\x7F] blanket strip, which also deleted
                    // legitimate Latin-1 punctuation like en dashes ("Pulai
                    // Mutiara – Sesi 13" was silently losing its "–").
                    // jsPDF's standard fonts use WinAnsi encoding, which
                    // covers Latin-1 supplement (accents, dashes, curly
                    // quotes) fine — only actual emoji need removing.
                    let cleanText = data.cell.text[0]
                        .replace(EMOJI_RE, '')
                        .trim();
                    data.cell.text[0] = cleanText.toUpperCase();
                }
                // Blank the divider/Pingat cells' own text here — both get
                // redrawn manually in didDrawCell below instead (see that
                // hook for why: the default 'striped' theme reapplies its
                // own alternating-row fill/text color AFTER didParseCell
                // runs, silently overwriting any custom color set here).
                if (isDividerCell(data) || isPingatCell(data)) {
                    data.cell.text = [''];
                }
            },
            // Runs after ALL of autoTable's own rendering for this cell
            // (including the theme's striped-row coloring), so painting
            // here is guaranteed to be the last thing drawn — nothing can
            // overwrite it afterward the way didParseCell's styles were.
            didDrawCell: function(data) {
                if (isDividerCell(data)) {
                    // Uppercase to match every other cell in the table (see
                    // didParseCell above) — this custom-drawn label bypasses
                    // that step since its text comes straight from the DOM,
                    // not from data.cell.text.
                    const label = (data.cell.raw && data.cell.raw.textContent || '').trim().toUpperCase();
                    const color = dividerColorFor(label);
                    doc.setFillColor(color.fill[0], color.fill[1], color.fill[2]);
                    doc.rect(data.cell.x, data.cell.y, data.cell.width, data.cell.height, 'F');
                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(isLandscape ? 8 : 7);
                    doc.setTextColor(color.text[0], color.text[1], color.text[2]);
                    // Centered to match every other cell in the table (base
                    // styles below use halign:'center'/valign:'middle') —
                    // this custom-drawn label bypassed that since it's drawn
                    // manually rather than through autoTable's own text layout.
                    doc.text(label, data.cell.x + data.cell.width / 2, data.cell.y + data.cell.height / 2, { align: 'center', baseline: 'middle' });
                    return;
                }

                if (isPingatCell(data)) {
                    // Drawn as one manual, non-wrapping line (icon image +
                    // word, centered as a group) instead of letting
                    // autoTable's own linebreak wrap "GANGSA" mid-word.
                    const rawText = (data.cell.raw && data.cell.raw.textContent || '').trim();
                    const emojiMatch = rawText.match(EMOJI_RE);
                    const word = rawText.replace(EMOJI_RE, '').trim().toUpperCase();

                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(isLandscape ? 8 : 7);
                    doc.setTextColor(50, 50, 50);

                    const iconSize = isLandscape ? 9 : 8;
                    const gap = 3;
                    const textWidth = doc.getTextWidth(word);
                    const groupWidth = (emojiMatch ? iconSize + gap : 0) + textWidth;
                    const cy = data.cell.y + data.cell.height / 2;
                    let curX = data.cell.x + data.cell.width / 2 - groupWidth / 2;

                    if (emojiMatch) {
                        doc.addImage(emojiToImage(emojiMatch[0], 64), 'PNG', curX, cy - iconSize / 2, iconSize, iconSize);
                        curX += iconSize + gap;
                    }
                    doc.text(word, curX, cy, { baseline: 'middle' });
                    return;
                }
            },
            styles: {
                font: 'helvetica',
                fontSize: isLandscape ? 8 : 7,
                cellPadding: isLandscape ? 6 : 4,
                textColor: [50, 50, 50],
                lineColor: [235, 235, 235],
                lineWidth: { bottom: 0.5, top: 0, left: 0, right: 0 },
                valign: 'middle', // Vertically centers text
                halign: 'center', // Horizontally centers ALL text (fixes the messy left-aligned wrapping)
                overflow: 'linebreak' // Allows long names to wrap to the next line naturally
            },
            headStyles: {
                fillColor: [35, 35, 35],
                textColor: [255, 255, 255],
                fontStyle: 'bold',
                halign: 'center',
                lineWidth: 0,
                // Headers are single short words (RANK, SIRI, SIDANG...) — at
                // portrait's tighter column widths the normal 'linebreak'
                // wrap was breaking mid-word ("RAN"/"K", "SIDAN"/"G") since
                // there's no space to wrap at. A smaller, non-wrapping
                // header style keeps every header on one line instead.
                fontSize: isLandscape ? 8 : 6.5,
                overflow: 'visible'
            },
            alternateRowStyles: {
                fillColor: [252, 252, 252]
            },
            // Every column gets an explicit cellWidth (none left as 'auto')
            // so the total always fits within the printable page width —
            // Cawangan/Peringkat/Juri being left to autoTable's natural
            // 'auto' sizing let a single long value blow the table wider
            // than the page, which autoTable "fixes" by continuing the
            // overflowing columns onto a second page instead of wrapping
            // them. Pinned widths + overflow:'linebreak' (in styles above)
            // means long values wrap onto extra lines within their own
            // column instead of spilling onto another page.
            columnStyles: isLandscape ? {
                // Landscape usable width ≈ 762pt (842pt A4 landscape − 40pt
                // margins each side). These sum to 750pt. Cawangan/Peringkat/
                // Juri are sized generously (~20+ chars at this font size)
                // specifically so a single long unbroken word (e.g. a judge's
                // full name like "HAIRUNORFADZLINA") doesn't get force-split
                // mid-word — that space comes out of the short fixed-format
                // columns (Tahun/Siri/Sidang/Markah/Peratus/Pingat), which
                // never need more than a few characters.
                0:  { cellWidth: 28,  fontStyle: 'bold', textColor: [100, 100, 100] },
                1:  { cellWidth: 125 }, // Nama Pesilat
                2:  { cellWidth: 32  }, // Tahun
                3:  { cellWidth: 42  }, // Siri
                4:  { cellWidth: 46  }, // Sidang
                5:  { cellWidth: 95  }, // Cawangan
                6:  { cellWidth: 140 }, // Peringkat
                7:  { cellWidth: 114 }, // Juri
                8:  { cellWidth: 46, halign: 'center', fontStyle: 'bold' },
                9:  { cellWidth: 42, halign: 'center', fontStyle: 'bold', textColor: [214, 40, 40] },
                10: { cellWidth: 50, halign: 'center', fontStyle: 'bold' } // Pingat — icon+word needs a bit more room
            } : {
                // Portrait usable width ≈ 515pt (595pt A4 portrait − 40pt
                // margins each side). These sum to 500pt — same rationale
                // as landscape above, scaled down.
                0:  { cellWidth: 20,  fontStyle: 'bold', textColor: [100, 100, 100] },
                1:  { cellWidth: 70  }, // Nama Pesilat
                2:  { cellWidth: 24  }, // Tahun
                3:  { cellWidth: 30  }, // Siri
                4:  { cellWidth: 34  }, // Sidang
                5:  { cellWidth: 55  }, // Cawangan
                6:  { cellWidth: 90  }, // Peringkat
                7:  { cellWidth: 85  }, // Juri
                8:  { cellWidth: 32, halign: 'center', fontStyle: 'bold' },  // Markah
                9:  { cellWidth: 30, halign: 'center', fontStyle: 'bold', textColor: [214, 40, 40] }, // Peratus
                10: { cellWidth: 38, halign: 'center', fontStyle: 'bold' }   // Pingat — icon+word needs a bit more room
            },
            margin: { top: 85, left: 40, right: 40, bottom: 40 }
        });
        
        // --- 3. OUTPUT ---
        const pdfBlob = doc.output('blob', { type: 'application/pdf' });
        const pdfUrl = URL.createObjectURL(pdfBlob);

        // Mobile browsers (Chrome/Safari on Android/iOS) don't render a
        // blob: PDF inside an <iframe> the way desktop Chrome does — instead
        // of a real preview they show a bare "download this file" card with
        // a UUID filename and an "Open" button that has no viewer to hand
        // off to, so it silently does nothing. Skip the iframe there and
        // just trigger a real download instead, which mobile browsers
        // handle correctly (saved to Downloads, opens with whatever PDF
        // app/viewer the device has).
        const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
        if (isMobile) {
            const a = document.createElement('a');
            a.href = pdfUrl;
            a.download = baseFileName + '.pdf';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(() => URL.revokeObjectURL(pdfUrl), 10000);
            return;
        }

        // Create full-screen overlay
        const overlay = document.createElement('div');
        overlay.style.cssText = 'position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.85); z-index: 9999; display: flex; flex-direction: column;';
        
        // Create top bar with close button
        const topBar = document.createElement('div');
        topBar.style.cssText = 'width: 100%; padding: 12px 24px; background: #1f1f1f; display: flex; justify-content: space-between; align-items: center; box-sizing: border-box;';
        
        const titleSpan = document.createElement('span');
        titleSpan.innerText = baseFileName + ".pdf";
        titleSpan.style.cssText = 'color: white; font-family: "DM Sans", sans-serif; font-weight: bold; letter-spacing: 0.05em; font-size: 0.9rem;';
        
        const closeBtn = document.createElement('button');
        closeBtn.innerText = '✖ Tutup Preview';
        closeBtn.style.cssText = 'background: var(--c-red); color: white; border: none; padding: 8px 16px; border-radius: 4px; font-weight: bold; cursor: pointer; font-family: "DM Sans", sans-serif; font-size: 0.85rem;';
        closeBtn.onclick = () => {
            document.body.removeChild(overlay);
            URL.revokeObjectURL(pdfUrl); // Clean up memory
        };
        
        topBar.appendChild(titleSpan);
        topBar.appendChild(closeBtn);
        
        // Create iframe to render PDF natively
        const iframe = document.createElement('iframe');
        iframe.src = pdfUrl;
        iframe.style.cssText = 'width: 100%; flex-grow: 1; border: none;';
        
        overlay.appendChild(topBar);
        overlay.appendChild(iframe);
        document.body.appendChild(overlay);
    }
</script>
</main>
</body>
</html>