<?php
ini_set("display_errors", 0);
error_reporting(E_ALL);
ini_set("log_errors", 1);

session_start();
require __DIR__ . '/auth_check.php';
date_default_timezone_set("Asia/Kuala_Lumpur");

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'judge') {
    header("Location: login.php");
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

include "db.php";
$conn = getDB();

// ── AJAX ENDPOINTS FOR ADDING NEW UJIAN/KRITERIA ──
// ── ajax_add_parameter is now a NO-OP — parameter inserts happen only
//    inside save_scores.php when the judge actually submits marks.
//    We keep this endpoint so old JS calls don't 404, but it returns
//    immediately without touching the database.
if (isset($_POST["ajax_add_parameter"])) {
    header("Content-Type: application/json");
    echo json_encode(["status" => "deferred"]);
    exit();
}

$judge_id = (int) $_SESSION["user_id"];

// Release the session lock now that we've read what we need from it. PHP's
// default file-based session handler holds an exclusive lock for the whole
// script, and while a judge is actively marking, save_draft_score.php fires
// on nearly every field change — if this page (dropdown AJAX or the full
// render) has to wait behind an in-flight draft save's lock, every click
// feels laggy. Re-opened below only on the full-page-render path, which is
// the only path past this point that still needs to write to $_SESSION.
session_write_close();

$stmt = $conn->prepare("SELECT name FROM judges WHERE id = ?");
$stmt->bind_param("i", $judge_id);
$stmt->execute();
$judge_name = $stmt->get_result()->fetch_assoc()["name"] ?? "Judge";
$stmt->close();
// ── INTERNAL AJAX ENDPOINTS FOR FORM DROPDOWNS ──
if (isset($_GET["ajax_levels"])) {
    $sid = (int) $_GET["session_id"];
    // Peringkat this judge already has a group in surface first — those are
    // the ones they're actually going to keep coming back to, so burying
    // them alphabetically among every other peringkat in the sidang just
    // adds friction each time they pick up marking again.
    //
    // A peringkat is only listed if it still has at least one group this
    // judge could actually pick in the Kumpulan dropdown next — unassigned,
    // or already theirs. Otherwise every group under it belongs to another
    // judge (ajax_groups below filters those out entirely), so picking this
    // peringkat would always land on an empty Kumpulan list.
    $stmt = $conn->prepare(
        "SELECT l.*, EXISTS(
            SELECT 1 FROM `groups` g WHERE g.level_id = l.level_id AND g.judge_id = ?
         ) AS is_mine
         FROM levels l
         WHERE l.session_id = ?
           AND EXISTS (
               SELECT 1 FROM `groups` g2
               WHERE g2.level_id = l.level_id
                 AND (g2.judge_id IS NULL OR g2.judge_id = 0 OR g2.judge_id = ?)
           )
         ORDER BY is_mine DESC, l.level_name ASC",
    );
    if ($stmt) {
        $stmt->bind_param("iii", $judge_id, $sid, $judge_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            $label = htmlspecialchars($r["level_name"]);
            if ($r["is_mine"]) { $label = "★ " . $label; }
            echo "<option value='{$r["level_id"]}'>{$label}</option>";
        }
        $stmt->close();
    }
    exit();
}

if (isset($_GET["ajax_groups"])) {
    $lid = (int) $_GET["level_id"];
    // Groups owned by another judge are excluded from this list entirely —
    // a judge only ever needs to see groups they can actually act on
    // (unassigned, pickable groups; or their own). has_marks/edit_used are
    // pulled in so the list can be ordered "unmarked first, done last" and
    // so a fully-locked group (marks submitted + one-time edit consumed)
    // can be shown disabled rather than removed, so the judge still sees
    // it exists and can't be confused about where it went.
    $stmt = $conn->prepare(
        "SELECT g.group_id, g.group_name, g.judge_id, g.edit_used, j.name AS judge_name,
                EXISTS(SELECT 1 FROM scores s WHERE s.group_id = g.group_id AND s.submitted = 1) AS has_marks
         FROM `groups` g
         LEFT JOIN judges j ON j.id = g.judge_id
         WHERE g.level_id = ? ORDER BY g.group_name ASC"
    );
    if ($stmt) {
        $stmt->bind_param("i", $lid);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($r = $result->fetch_assoc()) {
            $unassigned   = empty($r["judge_id"]);
            $ownedByMe    = !empty($r["judge_id"]) && (int)$r["judge_id"] === $judge_id;
            $ownedByOther = !$unassigned && !$ownedByMe;

            if ($ownedByOther) continue; // not this judge's to see

            $hasMarks    = (bool) $r["has_marks"];
            $fullyLocked = $hasMarks && (int)$r["edit_used"] === 1;

            $label = htmlspecialchars($r["group_name"]);
            if ($unassigned) {
                $label .= " ⚠ (Belum Ditetapkan)";
            } elseif ($fullyLocked) {
                $label .= " 🔒 (Selesai — Dikunci)";
            } elseif ($hasMarks) {
                $label .= " ✅ (Anda — Selesai)";
            } else {
                $label .= " (Anda)";
            }

            $rows[] = [
                'id'          => $r['group_id'],
                'label'       => $label,
                'unassigned'  => $unassigned ? '1' : '0',
                'own'         => $ownedByMe ? '1' : '0',
                'has_marks'   => $hasMarks,
                'disabled'    => $fullyLocked,
            ];
        }
        $stmt->close();

        // Groups already assigned to this judge surface first (mirrors the
        // "★ is_mine" ordering on the Peringkat dropdown above) — those are
        // the ones they're actually here to mark. Within that, unmarked
        // groups surface before ones already marked, and especially before
        // fully-locked ones, which sink to the very bottom.
        usort($rows, function ($a, $b) {
            if ($a['own'] !== $b['own']) return $b['own'] <=> $a['own'];
            if ($a['has_marks'] !== $b['has_marks']) return $a['has_marks'] <=> $b['has_marks'];
            if ($a['disabled'] !== $b['disabled']) return $a['disabled'] <=> $b['disabled'];
            return 0; // keep original group_name order within each bucket
        });

        foreach ($rows as $row) {
            $disabledAttr = $row['disabled'] ? "disabled" : "";
            echo "<option value='{$row['id']}' data-unassigned='{$row['unassigned']}' data-own='{$row['own']}' data-owned-other='0' $disabledAttr>{$row['label']}</option>";
        }
    }
    exit();
}

// ── AJAX: Modal cascade — tests for a level ──
if (isset($_GET["ajax_modal_tests"])) {
    $lid = (int) $_GET["level_id"];
    $stmt = $conn->prepare(
        "SELECT test_id, test_name FROM tests WHERE level_id = ? ORDER BY test_name ASC",
    );
    if ($stmt) {
        $stmt->bind_param("i", $lid);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($r = $result->fetch_assoc()) {
            $rows[] = ["id" => $r["test_id"], "name" => $r["test_name"]];
        }
        $stmt->close();
        header("Content-Type: application/json");
        echo json_encode($rows);
    }
    exit();
}

// ── AJAX: Modal cascade — criteria for a test ──
if (isset($_GET["ajax_modal_criteria"])) {
    $tid = (int) $_GET["test_id"];
    $stmt = $conn->prepare(
        "SELECT criteria_id, criteria_name FROM criteria WHERE test_id = ? ORDER BY criteria_name ASC",
    );
    if ($stmt) {
        $stmt->bind_param("i", $tid);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($r = $result->fetch_assoc()) {
            $rows[] = ["id" => $r["criteria_id"], "name" => $r["criteria_name"]];
        }
        $stmt->close();
        header("Content-Type: application/json");
        echo json_encode($rows);
    }
    exit();
}

// ── AJAX: Sessions filtered by siri_id ──
if (isset($_GET['ajax_sessions'])) {
    $sid = (int) $_GET['siri_id'];
    $stmt = $conn->prepare(
        "SELECT session_id, session_name FROM sessions WHERE siri_id = ? ORDER BY session_name ASC"
    );
    if ($stmt) {
        $stmt->bind_param("i", $sid);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            echo "<option value='{$r["session_id"]}'>" . htmlspecialchars($r["session_name"]) . "</option>";
        }
        $stmt->close();
    }
    exit();
}

$current_session = $_POST["session_id"] ?? null;
$current_level = $_POST["level_id"] ?? null;
$current_group = $_POST["group_id"] ?? null;
$marking_active = !empty($_POST["start_marking"]);

$session_label = "-";
$group_label = "-";
if ($current_session) {
    $stmt = $conn->prepare(
        "SELECT session_name FROM sessions WHERE session_id = ?",
    );
    if ($stmt) {
        $stmt->bind_param("i", $current_session);
        $stmt->execute();
        $session_label =
            $stmt->get_result()->fetch_assoc()["session_name"] ?? "-";
        $stmt->close();
    }
}
if ($current_group) {
    $stmt = $conn->prepare(
        "SELECT group_name FROM `groups` WHERE group_id = ?",
    );
    if ($stmt) {
        $stmt->bind_param("i", $current_group);
        $stmt->execute();
        $group_label = $stmt->get_result()->fetch_assoc()["group_name"] ?? "-";
        $stmt->close();
    }
}

$pm_page = "judge";
session_start();
$_SESSION["judge_name"] = $judge_name;
session_write_close();

// ── SIRI / YEAR CASCADE LOGIC ──
// Moved above the stats query below so the identity-card numbers can be
// scoped to the siri the judge currently has selected, instead of summing
// groups across every siri the judge has ever been assigned to.
$all_siri = [];
$siri_res = $conn->query("SELECT siri_id, siri_name, siri_year FROM siri ORDER BY siri_year DESC, siri_name ASC");
while ($sr = $siri_res->fetch_assoc()) {
    $all_siri[] = $sr;
}

// Distinct years from siri table
$all_years = array_unique(array_column($all_siri, 'siri_year'));
rsort($all_years);

$show_year_dropdown = count($all_years) > 1;
$show_siri_dropdown = count($all_siri) > 1;

// Determine active siri_id for session filtering
// Priority: POST (user just selected) > session > auto (only one siri)
$current_siri_id = null;
if (isset($_POST['siri_id']) && is_numeric($_POST['siri_id'])) {
    $current_siri_id = (int) $_POST['siri_id'];
} elseif (isset($_POST['start_marking']) && isset($_POST['siri_id'])) {
    $current_siri_id = (int) $_POST['siri_id'];
}

// If only one siri exists, auto-select it
if (!$current_siri_id && count($all_siri) === 1) {
    $current_siri_id = (int) $all_siri[0]['siri_id'];
}

$current_year = null;
if ($current_siri_id) {
    foreach ($all_siri as $sr) {
        if ($sr['siri_id'] === $current_siri_id) {
            $current_year = $sr['siri_year'];
            break;
        }
    }
}

// ── JUDGE STATS FOR IDENTITY CARD — scoped to the selected siri ──
$stat_total = 0;
$stat_done = 0;
$stat_pending = 0;
$statsSql = "
    SELECT
        COUNT(DISTINCT g.group_id) AS total_groups,
        SUM(CASE WHEN EXISTS (
            SELECT 1 FROM scores sc2 WHERE sc2.group_id = g.group_id AND sc2.submitted = 1 LIMIT 1
        ) THEN 1 ELSE 0 END) AS done_groups
    FROM `groups` g
    WHERE g.judge_id = ?
";
if ($current_siri_id) {
    $statsSql .= " AND g.level_id IN (SELECT level_id FROM levels WHERE session_id IN (SELECT session_id FROM sessions WHERE siri_id = ?))";
}
$sqStats = $conn->prepare($statsSql);
if ($sqStats) {
    if ($current_siri_id) {
        $sqStats->bind_param("ii", $judge_id, $current_siri_id);
    } else {
        $sqStats->bind_param("i", $judge_id);
    }
    $sqStats->execute();
    $statRow = $sqStats->get_result()->fetch_assoc();
    $sqStats->close();
    $stat_total = (int) ($statRow["total_groups"] ?? 0);
    $stat_done = (int) ($statRow["done_groups"] ?? 0);
    $stat_pending = $stat_total - $stat_done;
}
$stat_pct = $stat_total > 0 ? round(($stat_done / $stat_total) * 100) : 0;

include "layout.php";
?>

<style>
    /* ─────────────────────────────────────────────────
       JUDGE PAGE — REDESIGN
       Philosophy: scorecard, not dashboard.
       One accent colour, used only for meaning.
       Cards are ledger surfaces, not chrome.
    ───────────────────────────────────────────────── */

    /* Light mode token overrides */
    html.pm-light {
        --c-surface-0:    #f0f0f0;
        --c-surface-1:    #ffffff;
        --c-surface-2:    #f5f5f5;
        --c-surface-3:    #ebebeb;
        --c-red:          #b30000;
        --c-red-dark:     #800000;
        --c-red-dim:      rgba(179,0,0,0.08);
        --c-red-border:   rgba(179,0,0,0.25);
        --c-text:         #111111;
        --c-text-muted:   #555555;
        --c-border:       #e0e0e0;
        --c-border-strong:#cccccc;
    }

    /* ── Toast / alert colour tokens ── */
    .pm-toast-success, .pm-lock-alert {
        background: rgba(16,185,129,0.1);
        border-left: 3px solid #10B981;
        color: #059669;
    }
    .pm-toast-danger {
        background: rgba(239,68,68,0.1);
        border-left: 3px solid #EF4444;
        color: #DC2626;
    }
    html.pm-light .pm-toast-success,
    html.pm-light .pm-lock-alert {
        background: rgba(16,185,129,0.12);
        border-left: 3px solid #059669;
        color: #065F46;
    }
    html.pm-light .pm-toast-danger {
        background: rgba(239,68,68,0.12);
        border-left: 3px solid #DC2626;
        color: #991B1B;
    }

    /* ── Page heading ── */
    .pm-page-heading {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 1.5rem;
        letter-spacing: 0.08em;
        color: var(--c-text);
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 1px solid var(--c-border);
    }

    /* ── Section card — minimal frame ── */
    .judge-card {
        background: var(--c-surface-1);
        border: 1px solid var(--c-border);
        border-radius: 6px;
        padding: 20px 24px;
        margin-bottom: 16px;
        color: var(--c-text);
    }

    .judge-card h3.pm-card-title {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 1rem;
        letter-spacing: 0.12em;
        color: var(--c-text-muted);
        text-transform: uppercase;
        margin-bottom: 16px;
        padding-bottom: 8px;
        border-bottom: 2px solid var(--c-red);
        display: inline-block;
    }

    /* ── Top dashboard row ── */
    .top-dashboard-row {
        display: grid;
        grid-template-columns: 1fr 2fr;  /* was: 260px 1fr */
        gap: 16px;
        margin-bottom: 16px;
        align-items: stretch;
    }
    
    /* The sidebar now collapses into the hamburger menu below 1100px
       (see dashboard.css), so below that point content already gets the
       full viewport width with no sidebar to deduct — this can safely
       stay two-column down to a much narrower point than before. Only
       stack below 700px, where a phone-width screen genuinely doesn't
       have room for two columns regardless of the sidebar. */
    @media (max-width: 700px) {
        .top-dashboard-row { grid-template-columns: 1fr; }
    }

    .identity-col, .summary-col {
        display: flex;
        flex-direction: column;
        /* Grid items default to min-width:auto, which lets a deeply-nested
           table's min-width (see .pm-table below) force this whole column —
           and the page along with it — to grow past the viewport instead of
           the table's own overflow-x:auto scrolling locally. This is what
           caused the summary table to bleed outside the card/screen. */
        min-width: 0;
    }
    
    .identity-col .judge-card,
    .summary-col .judge-card {
        margin-bottom: 0;
        flex-grow: 1;
        height: 100%;
        min-width: 0; /* breaks the same overflow chain one level further in */
        box-sizing: border-box;
    }

    /* ── Identity card ── */
    .judge-identity-card {
        border-left: none;
        padding-left: 20px;
    }

    .identity-top {
        font-size: 0.65rem;
        font-weight: 700;
        letter-spacing: 0.15em;
        text-transform: uppercase;
        color: var(--c-text-muted);
    }

    .identity-name {
        font-size: 1.4rem;
        font-weight: 700;
        color: var(--c-text);
        margin: 4px 0 12px;
        line-height: 1.2;
    }

    .identity-meta {
        display: grid;
        grid-template-columns: auto 1fr;
        gap: 4px 16px;
        font-size: 0.8rem;
    }
    .identity-meta span { color: var(--c-text-muted); }
    .identity-meta b    { color: var(--c-text); font-weight: 500; }

    .identity-divider {
        border: none;
        border-top: 1px solid var(--c-border);
        margin: 14px 0;
    }

    /* Stat row — numbers only, no boxes */
    /* ── Stat row ── */
    .identity-stats {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0;
        border: 1px solid var(--c-border);
        border-radius: 4px;
        overflow: hidden;
        margin-top: 14px;   /* consistent with divider spacing */
    }
    
    .identity-stat-box {
        padding: 10px 8px;
        text-align: center;
        border-right: 1px solid var(--c-border);
        background: var(--c-surface-2);
    }
    .identity-stat-box:last-child { border-right: none; }
    
    .identity-stat-box .stat-val {
        font-family: 'DM Mono', monospace;
        font-size: 1.3rem;
        font-weight: 600;
        line-height: 1;
        display: block;
    }
    .identity-stat-box:nth-child(1) .stat-val { color: var(--c-text); }
    .identity-stat-box:nth-child(2) .stat-val { color: #16a34a; }
    .identity-stat-box:nth-child(3) .stat-val { color: var(--c-red); }
    
    .identity-stat-box .stat-label {
        font-size: 0.58rem;
        color: var(--c-text-muted);
        text-transform: uppercase;
        letter-spacing: 0.08em;
        margin-top: 4px;
        display: block;
        line-height: 1.3;
    }

    /* Progress bar */
    .identity-progress-wrap { margin-top: 12px; }
    .identity-progress-label {
        display: flex;
        justify-content: space-between;
        font-size: 0.72rem;
        color: var(--c-text-muted);
        margin-bottom: 5px;
    }
    .identity-progress-bar {
        height: 3px;
        background: var(--c-border);
        border-radius: 99px;
        overflow: hidden;
    }
    .identity-progress-fill {
        height: 100%;
        background: var(--c-red);
        border-radius: 99px;
        transition: width 0.5s ease;
    }

    /* ── Accordion summary & Search ── */
    .summary-search-wrap { 
        position: relative;
        margin-bottom: 16px; 
    }
    .summary-search-wrap::before {
        content: '🔍';
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 0.85rem;
        color: var(--c-text-muted);
        pointer-events: none;
        opacity: 0.6;
    }
    .summary-search-wrap input {
        width: 100%;
        background: var(--c-surface-1);
        border: 1px solid var(--c-border);
        border-radius: 6px;
        padding: 10px 14px 10px 38px; /* Extra left padding for the icon */
        color: var(--c-text);
        font-size: 0.85rem;
        outline: none;
        transition: all 0.2s ease;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        box-sizing: border-box;
    }
    .summary-search-wrap input:focus {
        border-color: var(--c-red);
        box-shadow: 0 0 0 3px var(--c-red-dim);
    }
    .summary-search-wrap input::placeholder {
        color: var(--c-text-muted);
        opacity: 0.7;
    }

    .pm-accordion-container {
        min-height: 60px;
        max-height: 380px;
        overflow-y: auto;
        overflow-x: hidden;
        scrollbar-width: thin;
        scrollbar-color: var(--c-border-strong) transparent;
        padding-right: 4px;
    }
    .pm-accordion-container::-webkit-scrollbar { width: 4px; }
    .pm-accordion-container::-webkit-scrollbar-thumb {
        background: var(--c-border-strong);
        border-radius: 4px;
    }

    .pm-accordion-block {
        border: 1px solid var(--c-border);
        border-radius: 6px;
        margin-bottom: 8px;
        overflow: visible;
        background: var(--c-surface-1);
        transition: box-shadow 0.2s ease;
    }
    .pm-accordion-block:hover {
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }
    .pm-accordion-block .pm-table-wrap {
        min-height: unset !important;
        padding-bottom: 0 !important;
        border: none;
        border-top: 1px solid var(--c-border);
        border-radius: 0 0 6px 6px;
    }

    /* Upgraded Header Layout */
    .pm-accordion-header {
        position: sticky;
        top: 0;
        z-index: 20;
        background: var(--c-surface-2);
        padding: 12px 16px;
        display: flex;
        align-items: center;
        justify-content: space-between; /* Pushes count badge to the right */
        cursor: pointer;
        border-bottom: 1px solid transparent;
        border-radius: 6px;
        transition: background 0.15s;
        /* On a narrow column (badges + title + count badge don't all fit on
           one line) this used to just squeeze everything into an overlapping
           mess instead of reflowing — wrapping lets the count badge drop to
           its own line instead. row-gap only adds space when it actually
           wraps, so full-width desktop headers look exactly the same. */
        flex-wrap: wrap;
        row-gap: 6px;
    }
    .pm-accordion-header:hover { background: var(--c-surface-3); }
    .pm-accordion-header.active {
        background: var(--c-surface-2);
        border-bottom: 1px solid var(--c-border);
        border-radius: 6px 6px 0 0;
    }

    .pm-accordion-header-left {
        display: flex;
        align-items: center;
        gap: 8px 12px;
        /* Lets this side shrink/wrap its own badges+title instead of
           forcing .pm-count-badge to share the line no matter how little
           room is left. min-width:0 is what actually allows a flex child
           to shrink below its content size in the first place. */
        flex: 1 1 auto;
        min-width: 0;
        flex-wrap: wrap;
    }

    .pm-accordion-arrow {
        font-size: 0.65rem;
        color: var(--c-text-muted);
        transition: transform 0.2s ease;
    }
    .pm-accordion-header.active .pm-accordion-arrow {
        color: var(--c-red);
    }

    /* New Badges */
    /* New Badges */
    .pm-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px; /* Space between dot and text */
        font-size: 0.65rem;
        font-weight: 700;
        text-transform: uppercase;
        padding: 4px 8px;
        border-radius: 4px;
        letter-spacing: 0.05em;
    }
    .pm-badge-year {
        background: var(--c-surface-1);
        color: var(--c-text-muted);
        border: 1px solid var(--c-border-strong);
    }
    /* Generates the grey dot inside the Year badge */
    .pm-badge-year::before {
        content: '';
        display: inline-block;
        width: 5px;
        height: 5px;
        border-radius: 50%;
        background: var(--c-text-muted);
    }
    .pm-badge-siri {
        background: var(--c-red-dim);
        color: var(--c-red);
        border: 1px solid var(--c-red-border);
    }
    /* Generates the red dot inside the Siri badge */
    .pm-badge-siri::before {
        content: '';
        display: inline-block;
        width: 5px;
        height: 5px;
        border-radius: 50%;
        background: var(--c-red);
    }
    .pm-accordion-title {
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--c-text);
        /* No ellipsis here on purpose — with a flex-wrap parent and
           min-width:0, an ellipsis + nowrap combo lets the browser shrink
           this down to a near-invisible sliver instead of wrapping it to
           its own line, which is what actually happened (an empty-looking
           pill next to the Siri badge). A min-width floor guarantees it
           always wraps to a new line onto full-width instead of collapsing. */
        min-width: 120px;
        flex: 1 1 auto;
    }
    .pm-count-badge {
        font-size: 0.72rem;
        font-weight: 600;
        color: var(--c-text-muted);
        background: var(--c-surface-1);
        border: 1px solid var(--c-border);
        padding: 4px 12px;
        border-radius: 99px; /* Perfect pill shape */
        flex-shrink: 0;
        white-space: nowrap;
        box-shadow: 0 1px 2px rgba(0,0,0,0.02); /* Slight shadow for depth */
    }

    /* ── Base table ── */
    .pm-table-wrap {
        background: var(--c-surface-1);
        border-radius: 0 0 6px 6px;
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        min-height: unset;
    }
    .student-list-wrap {
        min-height: unset !important;
        padding-bottom: 0 !important;
        border: 1px solid var(--c-border);
        border-radius: 4px;
    }
    .pm-table-wrap::-webkit-scrollbar { height: 4px; }
    .pm-table-wrap::-webkit-scrollbar-thumb {
        background: var(--c-border-strong);
        border-radius: 2px;
    }

    .pm-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 600px; /* Slightly wider to accommodate spacious rows */
    }
    .pm-table th {
        background: var(--c-surface-2); /* Was hardcoded #f8f9fa — stayed light-grey even in dark mode */
        color: var(--c-text-muted);
        border-bottom: 1px solid var(--c-border);
        padding: 10px 16px; /* More breathing room */
        text-align: left;
        white-space: nowrap;
        font-size: 0.65rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.1em; /* Wider tracking for that modern look */
    }
    .pm-table td {
        color: var(--c-text);
        border-bottom: 1px solid var(--c-border);
        padding: 16px 16px; /* Spacious vertical padding */
        font-size: 0.85rem;
        vertical-align: middle;
    }
    .pm-table tr:hover td { background: var(--c-surface-2); }
    
    /* Remove bottom border on the very last row so it doesn't double up with the container border */
    .pm-accordion-content .pm-table tbody tr:last-child td {
        border-bottom: none;
    }

    /* ── Kemajuan Pemarkahan summary table — uniform typography ──
       Every cell used to carry its own one-off inline font-size/weight/
       line-height (Cawangan was a smaller font entirely, Peringkat used
       weight 500, Kumpulan had no styling at all, status badges had their
       own line-height) which is what made the columns look inconsistent
       side by side. All five columns now share one base size/line-height;
       only weight and color vary, and only to signal actual meaning
       (Peringkat/Kumpulan = identifying label, Juri = accent, Cawangan =
       secondary detail). */
    .summaryTable td {
        font-size: 0.85rem;
        line-height: 1.5;
    }
    .summaryTable .sc-peringkat,
    .summaryTable .sc-kumpulan {
        font-weight: 600;
        color: var(--c-text);
    }
    .summaryTable .sc-status {
        color: var(--c-text);
    }
    .summaryTable .sc-status > span {
        line-height: 1.5;
    }
    .summaryTable .sc-cawangan {
        color: var(--c-text-muted);
        max-width: 180px;
        white-space: normal;
    }
    .summaryTable .sc-juri {
        font-weight: 700;
        color: var(--c-red);
    }
    .summaryTable th:nth-child(2),
    .summaryTable td:nth-child(2) {
        min-width: 280px;
    }

    /* ── Kemajuan Pemarkahan summary table on small screens ──
       At full width this is a normal 5-column table, but a fixed 600px
       min-width table inside a phone-width card just forces every cell
       (especially "Status Ujian", which holds a whole bulleted list) into
       a narrow squeeze with awkward mid-word wrapping. Below the
       breakpoint it reflows into one stacked card per row instead, with
       each cell's own label (from data-label, set server-side) shown
       above its value — no horizontal scrolling or cramped columns. */
    @media (max-width: 700px) {
        .summaryTable { min-width: 0; }
        .summaryTable thead { display: none; }
        .summaryTable, .summaryTable tbody, .summaryTable tr, .summaryTable td {
            display: block;
            width: 100%;
        }
        .summaryTable tbody tr.summary-row {
            border: 1px solid var(--c-border);
            border-radius: 8px;
            margin-bottom: 10px;
            padding: 4px 0;
            background: var(--c-surface-1);
        }
        .summaryTable tbody tr.summary-row:last-child { margin-bottom: 0; }
        .pm-accordion-content .pm-table.summaryTable tbody tr:last-child td {
            border-bottom: 1px dashed var(--c-border);
        }
        .summaryTable td {
            padding: 10px 14px !important;
            border-bottom: 1px dashed var(--c-border) !important;
            max-width: none !important;
            white-space: normal !important;
        }
        .summaryTable tr.summary-row td:last-child {
            border-bottom: none !important;
        }
        .summaryTable td::before {
            content: attr(data-label);
            display: block;
            font-size: 0.62rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--c-text-muted);
            margin-bottom: 4px;
        }
    }
    
    /* Make the active header background slightly distinct to match the open state */
    .pm-accordion-header.active {
        background: #fafafa;
        border-bottom: 1px solid var(--c-border);
        border-radius: 6px 6px 0 0;
    }
    
    /* Fix Arrow centering during rotation */
    .pm-accordion-arrow {
        display: inline-block;
        font-size: 0.55rem;
        color: var(--c-text-muted);
        transition: transform 0.2s ease, color 0.2s ease;
        transform-origin: center;
    }

    /* ── Select2 theme ── */
    .select2-container .select2-selection--single {
        background: var(--c-surface-2) !important;
        border: 1px solid var(--c-border-strong) !important;
        border-radius: 4px !important;
        height: 38px !important;
    }
    .select2-container--open .select2-selection--single {
        border-color: var(--c-red) !important;
        box-shadow: none !important;
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        color: var(--c-text) !important;
        line-height: 36px !important;
        font-size: 0.82rem !important;
        padding-left: 10px !important;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 36px !important;
        right: 8px !important;
    }
    .select2-dropdown {
        background: var(--c-surface-1) !important;
        border: 1px solid var(--c-border-strong) !important;
        border-radius: 4px !important;
        box-shadow: 0 4px 16px rgba(0,0,0,0.12) !important;
    }
    .select2-results__option {
        padding: 8px 12px !important;
        font-size: 0.82rem !important;
        color: var(--c-text) !important;
    }
    .select2-container--default .select2-results__option--highlighted[aria-selected] {
        background-color: var(--c-surface-3) !important;
        color: var(--c-text) !important;
    }
    .select2-container--default .select2-results__option[aria-selected="true"] {
        background-color: var(--c-red) !important;
        color: #fff !important;
        font-weight: 600;
    }
    .form-btn-submit {
        height: 38px !important;
        padding: 0 18px !important;
        font-size: 0.82rem !important;
    }

    /* ── Desktop ujian tabs — one table per ujian instead of one giant
       table with every ujian's columns side by side. Each tab's table can
       still scroll horizontally on its own if that ujian has many kriteria
       — this only cuts down how many columns are on screen at once, it
       doesn't remove scrolling entirely. ── */
    .desktop-ujian-tabs {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 6px;
        padding: 12px 16px;
        background: var(--c-surface-2);
        border-bottom: 1px solid var(--c-border);
    }

    .desktop-ujian-tab-btn {
        background: var(--c-surface-1);
        border: 1px solid var(--c-border-strong);
        color: var(--c-text-muted);
        font-weight: 700;
        font-size: 0.82rem;
        padding: 9px 18px;
        border-radius: 999px;
        cursor: pointer;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        transition: background 0.15s, color 0.15s, border-color 0.15s;
    }
    .desktop-ujian-tab-btn:hover { background: var(--c-surface-3); }
    .desktop-ujian-tab-btn.active {
        background: var(--c-red);
        color: #fff;
        border-color: var(--c-red-dark, var(--c-red));
        box-shadow: 0 2px 6px rgba(214,40,40,0.25);
    }

    /* ── Marking table (desktop only — see #desktopMarkingTable below) ──
       No fixed min-width on the table itself: with a fixed keypad size the
       table's real width is however many kriteria columns it has, times
       their own min-width below — a blanket 900px either did nothing (more
       columns) or forced scroll that wasn't needed (few columns). Sticky
       name column and per-cell padding are trimmed to the minimum that
       still fits a wrapped name / the keypad, so as many kriteria columns
       as possible fit on screen before overflow-x kicks in — the keypad
       itself (.pm-keypad max-width below) is left untouched. */
    .marking-table th {
        text-align: center !important;
        vertical-align: middle !important;
        padding: 7px 4px !important;
    }
    /* .pm-main has 32px of padding on each side (var(--sp-8)) meant for
       normal cards — this table sits in its own dedicated, borderless card
       (.marking-card-bleed) with nothing else to align to, so it bleeds
       back out to reclaim that 64px of dead space for kriteria columns
       instead of leaving it unused on both edges. */
    .marking-card-bleed {
        margin-inline: calc(-1 * var(--sp-8));
        width: calc(100% + var(--sp-8) * 2);
    }
    .sticky-col {
        position: sticky !important;
        left: 0;
        z-index: 10 !important;
        font-weight: 600;
        font-size: 0.9rem;
        background: var(--c-surface-1) !important;
        color: var(--c-text) !important;
        border-right: 1px solid var(--c-border-strong) !important;
        box-shadow: 2px 0 8px rgba(0,0,0,0.06);
        min-width: 90px;
        width: 100px;
        white-space: normal;
    }
    th.sticky-col {
        background: var(--c-surface-2) !important;
        z-index: 12 !important;
    }
    .marking-table tr:nth-child(even) td.sticky-col {
        background: var(--c-surface-2) !important;
    }
    /* ── Fit-to-screen: sized to its content up to the viewport, then
       scrolls internally past that — few students/kriteria sit fully on
       screen with no scrollbar at all, many of either scroll (horizontally
       for kriteria, vertically for students) inside this box instead of
       stretching the page. The header row stays pinned to the top of that
       scroll so column labels never scroll out of view; .sticky-col (left)
       already pins the student-name column the same way. */
    #desktopMarkingTable .pm-table-wrap {
        max-height: calc(100vh - var(--header-h, 64px) - 180px);
        overflow-y: auto;
    }
    .marking-table thead th {
        position: sticky !important;
        top: 0;
        z-index: 14 !important;
        background: var(--c-surface-2);
    }
    .marking-table thead th.sticky-col {
        z-index: 25 !important;
    }
    /* .test-total / .overall-total inherit the base .pm-table td rule's
       16px horizontal padding, meant for looser tables elsewhere — that's
       two more columns' worth of dead space fighting for room against the
       kriteria columns here. */
    .marking-table td.test-total,
    .marking-table td.overall-total {
        padding: 8px 4px !important;
    }
    .mark-cell {
        min-width: 162px;
        vertical-align: top !important;
        padding: 8px 0 !important;
    }

    /* ── Marking table: rendered once, for all viewports. On narrow
       screens it scrolls horizontally (see the overflow-x:auto on its
       wrapper) rather than switching to a different layout. ── */
    #desktopMarkingTable { display: block; }

    /* Brief highlight when a "missing mark" jump lands on a student row. */
    .flash-highlight {
        animation: flashHighlight 1.1s ease;
    }
    @keyframes flashHighlight {
        0%   { box-shadow: 0 0 0 3px var(--c-red); }
        100% { box-shadow: 0 0 0 0 transparent; }
    }

    .pm-nilai-wrap {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        margin-bottom: 8px;
        cursor: pointer;
        width: max-content;
        margin-left: auto;
        margin-right: auto;
    }
    .pm-nilai-checkbox { display: none; }
    .pm-toggle-switch {
        position: relative;
        width: 44px;
        height: 24px;
        background: var(--c-surface-3);
        border-radius: 24px;
        border: 1px solid var(--c-border-strong);
        transition: 0.2s;
    }
    .pm-toggle-switch::after {
        content: '';
        position: absolute;
        top: 2px;
        left: 2px;
        width: 18px;
        height: 18px;
        background: var(--c-text-muted);
        border-radius: 50%;
        transition: 0.2s;
    }
    .pm-nilai-checkbox:checked + .pm-toggle-switch {
        background: rgba(16,185,129,0.15);
        border-color: #10B981;
    }
    .pm-nilai-checkbox:checked + .pm-toggle-switch::after {
        transform: translateX(20px);
        background: #10B981;
    }
    .pm-toggle-label {
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--c-text-muted);
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }
    .pm-nilai-checkbox:checked ~ .pm-toggle-label { color: #10B981; }
    .pm-nilai-checkbox:disabled ~ .pm-toggle-switch,
    .pm-nilai-checkbox:disabled ~ .pm-toggle-label {
        opacity: 0.45;
        cursor: not-allowed;
    }

    /* ── Keypad ── */
    .pm-keypad {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 4px; /* still enough to prevent accidental adjacent taps */
        max-width: 200px; /* Much wider, comfortable thumb reach — kept as-is,
                              this is what sets button size, don't shrink it */
        margin: 6px auto;
    }
    .pm-keypad.locked { pointer-events: none; opacity: 0.6; }

    .pm-key-btn {
        /* Without this, mobile browsers (notably iOS Safari) paint their own
           native gray "pressed button" chrome on top of our custom colors
           whenever the button is tapped — this is what made the keypad look
           grayed-out every time a mark was pressed. */
        -webkit-appearance: none;
        appearance: none;
        -webkit-tap-highlight-color: transparent;
        background: var(--c-surface-2);
        border: 1px solid var(--c-border);
        color: var(--c-text);
        padding: 0;
        min-height: 48px; /* Standard mobile touch target height */
        border-radius: 6px; /* Slightly rounder to look native */
        font-family: 'DM Mono', monospace;
        font-size: 1.25rem; /* Larger, clearer numbers */
        font-weight: 600;
        cursor: pointer;
        transition: background 0.1s, border-color 0.1s;
        touch-action: manipulation;
    }
    
    .pm-key-btn:hover:not(:disabled) {
        background: var(--c-surface-3);
        border-color: var(--c-border-strong);
    }
    .pm-key-btn:focus,
    .pm-key-btn:focus-visible {
        outline: none;
    }
    .pm-key-btn:focus:not(.active):not(:hover) {
        background: var(--c-surface-2);
        border-color: var(--c-border);
    }
    .pm-key-btn.active {
        background: var(--c-red);
        color: #fff;
        border-color: var(--c-red);
        box-shadow: none;
        transform: none;
        z-index: 1;
    }
    .pm-key-btn.clear-btn {
        color: var(--c-red);
        background: transparent;
        border-color: var(--c-border);
    }
    .pm-key-btn:disabled { opacity: 0.4; cursor: not-allowed; }

    /* ── Desktop table: shrink the toggle/keypad back down ──
       The base sizes above (48px buttons, 200px keypad, 44px toggle) are
       sized for touch input. The markctrl node physically sits inside
       #desktopMarkingTable's <td>, so scoping by that ancestor shrinks it
       back to a size that doesn't blow out every table column. */
    #desktopMarkingTable .pm-nilai-wrap { gap: 6px; margin-bottom: 8px; }
    #desktopMarkingTable .pm-toggle-switch { width: 37px; height: 20px; }
    #desktopMarkingTable .pm-toggle-switch::after { width: 15px; height: 15px; }
    #desktopMarkingTable .pm-nilai-checkbox:checked + .pm-toggle-switch::after { transform: translateX(17px); }
    #desktopMarkingTable .pm-toggle-label { font-size: 0.72rem; letter-spacing: 0.06em; }
    #desktopMarkingTable .pm-keypad { max-width: 156px; gap: 4px; margin: 6px auto; }
    #desktopMarkingTable .pm-key-btn { min-height: 40px; font-size: 1.05rem; border-radius: 4px; }

    /* ── Buttons ── */
    .mark-action-btn {
        padding: 7px 16px !important;
        font-size: 0.82rem !important;
        line-height: 1.4 !important;
    }

    /* ── Mobile overrides ── */
    .pm-accordion-container { overflow-x: hidden !important; }
    .pm-accordion-block .pm-table-wrap { overflow-x: auto !important; }

    @media (max-width: 768px) {
        .mark-actions-row { flex-wrap: wrap !important; gap: 6px !important; justify-content: center; }
        .mark-action-btn  { padding: 6px 12px !important; font-size: 0.78rem !important; }
    }
    
    /* ── Parameter form grid ── */
    .param-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        align-items: flex-end;
        width: 100%;
    }
    
    .param-field {
        flex: 1 1 160px; /* Grow to fill space, shrink if needed, base width 160px */
        display: flex;
        flex-direction: column;
        min-width: 0; /* Prevents Select2 from breaking the layout */
    }
    
    .param-field--wide {
        flex: 2 1 240px; /* Grows twice as fast for the 'Kumpulan' dropdown so it has more room */
    }
    
    .param-field--btn {
        flex: 1 1 140px; 
    }
    
    .param-label {
        font-size: 0.65rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--c-text-muted);
        margin-bottom: 6px;
        display: block;
    }
    
    @media (max-width: 640px) {
        /* On mobile, stack everything vertically */
        .param-field, .param-field--wide, .param-field--btn {
            flex: 1 1 100%; 
        }
        .param-field--btn {
            margin-top: 4px;
        }
    }
</style>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
    // The Sidang/Peringkat/Kumpulan dropdowns are filled via $.get() calls
    // back to this same page. If the judge's session has quietly expired
    // (15 min idle), auth_check.php now answers those with a 401 instead of
    // redirecting — catch that here, once, for every AJAX call on this page,
    // rather than each dropdown silently rendering empty with no explanation
    // and the judge having no idea why until they refresh.
    let pmSessionExpiredShown = false;
    $(document).ajaxError(function (event, jqXHR) {
        if (jqXHR.status !== 401 || pmSessionExpiredShown) return;
        let sessionExpired = false;
        try { sessionExpired = JSON.parse(jqXHR.responseText || '{}').error === 'session_expired'; } catch (e) {}
        if (!sessionExpired) return;
        pmSessionExpiredShown = true;
        alert('Sesi anda telah tamat kerana tiada aktiviti. Sila log masuk semula.');
        window.location.href = 'login.php';
    });
</script>

<h2 class="pm-page-heading">Sistem Pemarkahan</h2>

<?php
// ── Save/reset feedback ──────────────────────────────────────────────────
// Unified with the rest of the app's pm-alert-success/pm-alert-danger
// pattern (was 4 separate one-off pm-toast-* blocks, only 3 of the 6 real
// codes save_scores.php/reset_system.php can send were ever displayed —
// 'invalid_request' and 'reset_failed' silently showed nothing at all).
// Using the standard classes also means layout.php's global auto-dismiss
// script (fades out after ~4.5s) now covers these automatically.
$judgeMsgMap = [
    'access_denied'    => ['danger',  '🔒 Akses Ditolak! Kumpulan ini telah ditetapkan kepada juri lain. Anda tidak dibenarkan mengubah markahnya.'],
    'edit_locked'      => ['danger',  '🔒 Dikunci Sepenuhnya! Hak mengedit telah digunakan. Markah tidak boleh diubah lagi.'],
    'empty_submission' => ['danger',  '⚠️ Ralat! Tiada markah dimasukkan. Sila masukkan sekurang-kurangnya satu markah sebelum menyimpan.'],
    'invalid_request'  => ['danger',  '⚠️ Ralat! Permintaan tidak sah atau sesi telah tamat tempoh. Sila muat semula halaman dan cuba lagi.'],
    'reset_failed'     => ['danger',  '⚠️ Ralat! Set semula sistem gagal. Sila cuba lagi atau hubungi pentadbir.'],
    'reset_success'    => ['success', '✅ Sistem berjaya diset semula. Semua markah telah diarkibkan dan tugasan juri dikosongkan.'],
];
$judgeSeverity = null;
$judgeText     = null;
if (($_GET['status'] ?? '') === 'success') {
    $judgeSeverity = 'success';
    $judgeText     = '✅ Berjaya! Markah pemarkahan telah selamat disimpan ke dalam pangkalan data.';
} elseif (isset($_GET['msg']) && isset($judgeMsgMap[$_GET['msg']])) {
    [$judgeSeverity, $judgeText] = $judgeMsgMap[$_GET['msg']];
} elseif (isset($_GET['msg'])) {
    // Any future/unrecognised code still shows something instead of nothing.
    $judgeSeverity = 'danger';
    $judgeText     = '⚠️ Ralat. Sila cuba lagi.';
}
?>
<?php if ($judgeSeverity): ?>
    <div class="pm-alert pm-alert-<?= $judgeSeverity ?>"><?= $judgeText ?></div>
    <script>
        if (window.history.replaceState) {
            const url = new URL(window.location.href);
            url.searchParams.delete('status');
            url.searchParams.delete('msg');
            window.history.replaceState({path: url.href}, '', url.href);
        }
    </script>
<?php endif; ?>

<div class="top-dashboard-row">
    <div class="identity-col">
        <div class="judge-card judge-identity-card">
            <div class="identity-top">Selamat Datang</div>
            <div class="identity-name"><?= htmlspecialchars(
                $judge_name,
            ) ?></div>
            <div class="identity-meta">
                <span>Sidang</span> <b><?= htmlspecialchars(
                    $session_label,
                ) ?></b>
                <span>Status</span>
                <?php
                // Derived from actual marking progress (groups assigned vs.
                // groups fully submitted), not just whether a marking
                // session happens to be active on this page load. Only
                // three states: Selesai requires EVERY assigned group to
                // be submitted — any amount short of that (including
                // partial progress) still reads as Belum Menanda.
                if ($stat_total === 0) {
                    $overallStatusColor = '#9ca3af';
                    $overallStatusLabel = '⚪ Tiada Kumpulan';
                } elseif ($stat_done < $stat_total) {
                    $overallStatusColor = '#f87171';
                    $overallStatusLabel = '🔴 Belum Menanda';
                } else {
                    $overallStatusColor = '#4ade80';
                    $overallStatusLabel = '🟢 Selesai';
                }
                ?>
                <b style="color: <?= $overallStatusColor ?>;"><?= $overallStatusLabel ?></b>
                <span>Tarikh</span> <b><span id="liveDate"></span></b>
                <span>Masa</span> <b><span id="liveTime"></span></b>
            </div>

            <hr class="identity-divider">

            <div class="identity-stats">
                <div class="identity-stat-box">
                    <div class="stat-val"><?= $stat_total ?></div>
                    <div class="stat-label">Jumlah Kumpulan</div>
                </div>
                <div class="identity-stat-box">
                    <div class="stat-val"><?= $stat_done ?></div>
                    <div class="stat-label">Selesai</div>
                </div>
                <div class="identity-stat-box">
                    <div class="stat-val"><?= $stat_pending ?></div>
                    <div class="stat-label">Belum Ditanda</div>
                </div>
            </div>

            <div class="identity-progress-wrap">
                <div class="identity-progress-label">
                    <span>Kemajuan Pemarkahan</span>
                    <span><?= $stat_pct ?>%</span>
                </div>
                <div class="identity-progress-bar">
                    <div class="identity-progress-fill" style="width:<?= $stat_pct ?>%;"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="summary-col">
        <div class="judge-card">
            <h3 class="pm-card-title">Kemajuan Pemarkahan Mengikut Tahun, Siri & Sidang</h3>

            <div class="summary-search-wrap">
                <input type="text" id="summarySearchInput"
                    placeholder="Cari tahun / siri / sidang / peringkat / kumpulan / juri..." autocomplete="off">
            </div>

            <div class="pm-accordion-container" id="summaryAccordionContainer">
                <?php
                // Fetch and group data by Year, Siri, and Session
                //
                // Optimized from the original version, which:
                //   1. Joined `criteria` directly into the main FROM clause
                //      even though no column of it was ever selected outside
                //      the EXISTS subquery — that join existed purely to
                //      fan every row out to (level × test × criteria) before
                //      GROUP BY collapsed it back down, multiplying the cost
                //      of every subquery below by the criteria count for no
                //      reason. Removed entirely.
                //   2. Ran the school_names lookup as a scalar subquery in
                //      the SELECT list, which MySQL evaluates once per raw
                //      pre-aggregation row (i.e. once per test, not once per
                //      group, since the value doesn't change across a
                //      group's tests) — moved to a derived table computed
                //      once per group and LEFT JOINed in instead, itself
                //      scoped to only this judge's groups.
                $sqSummary = $conn->prepare("
                    SELECT sr.siri_year, sr.siri_name, s.session_name, l.level_name, g.group_name, j.name AS judge_name,
                           GROUP_CONCAT(
                                DISTINCT CONCAT(
                                    '<div style=\"display: flex; align-items: center; justify-content: space-between; width: 100%; gap: 16px; margin-bottom: 8px;\">',
                                    
                                    '<div style=\"flex: 1; min-width: 0; word-wrap: break-word; line-height: 1.4;\">• ', 
                                    REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(t.test_name,'&','&amp;'),'<','&lt;'),'>','&gt;'),'\"','&quot;'),'\'','&#39;'), 
                                    '</div>',
                                    
                                    CASE WHEN EXISTS (
                                        SELECT 1 FROM scores sc
                                        JOIN criteria c3 ON sc.criteria_id = c3.criteria_id
                                        WHERE sc.group_id = g.group_id AND c3.test_id = t.test_id AND sc.submitted = 1
                                    ) 
                                    THEN '<div style=\"flex: 0 0 auto; display: inline-flex; align-items: center; gap: 4px; background: rgba(16, 185, 129, 0.15); color: #10B981; padding: 4px 10px; border-radius: 99px; font-size: 0.75rem; font-weight: 700; white-space: nowrap;\"><svg viewBox=\"0 0 24 24\" style=\"width:13px;height:13px;\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"3\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><polyline points=\"20 6 9 17 4 12\"></polyline></svg> Selesai</div>'
                                    
                                    ELSE '<div style=\"flex: 0 0 auto; display: inline-flex; align-items: center; gap: 4px; background: rgba(239, 68, 68, 0.15); color: #EF4444; padding: 4px 10px; border-radius: 99px; font-size: 0.75rem; font-weight: 700; white-space: nowrap;\"><svg viewBox=\"0 0 24 24\" style=\"width:13px;height:13px;\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"3\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><line x1=\"18\" y1=\"6\" x2=\"6\" y2=\"18\"></line><line x1=\"6\" y1=\"6\" x2=\"18\" y2=\"18\"></line></svg> Belum</div>' END,
                                    
                                    '</div>'
                                ) SEPARATOR ''
                            ) as status_list,
                           sn.school_names
                    FROM sessions s
                    LEFT JOIN siri sr ON s.siri_id = sr.siri_id
                    JOIN levels l ON s.session_id = l.session_id
                    JOIN `groups` g ON l.level_id = g.level_id
                    LEFT JOIN judges j ON g.judge_id = j.id
                    JOIN tests t ON l.level_id = t.level_id
                    LEFT JOIN (
                        SELECT gs2.group_id, GROUP_CONCAT(DISTINCT sc2.school_name SEPARATOR ', ') AS school_names
                        FROM group_students gs2
                        JOIN students st2 ON gs2.student_id = st2.student_id
                        JOIN schools sc2 ON st2.school_id = sc2.school_id
                        WHERE gs2.group_id IN (SELECT group_id FROM `groups` WHERE judge_id = ?)
                        GROUP BY gs2.group_id
                    ) sn ON sn.group_id = g.group_id
                    WHERE g.judge_id = ?
                    GROUP BY sr.siri_year, sr.siri_name, s.session_id, s.session_name, l.level_id, g.group_id, sn.school_names
                    ORDER BY sr.siri_year DESC, sr.siri_name ASC, s.session_name ASC, l.level_name, g.group_name
                ");
                $sqSummary->bind_param("ii", $judge_id, $judge_id);
                $sqSummary->execute();
                $summary = $sqSummary->get_result();
                $sqSummary->close();

                $groupedData = [];
                while ($row = $summary->fetch_assoc()) {
                    // Create a clean layout for the accordion header
                    $yearText = $row["siri_year"] ? $row["siri_year"] : "Tiada Tahun";
                    $siriText = $row["siri_name"] ? $row["siri_name"] : "Tiada Siri";
                    
                    $headerTitle = "{$yearText} | {$siriText} | {$row['session_name']}";
                    $groupedData[$headerTitle][] = $row;
                }

                if (empty($groupedData)) {
                    echo "<div style='color:#aaa; text-align:center; padding:30px; background:var(--c-surface-2); border-radius:6px; border: 1px dashed var(--c-border);'>Tiada rekod ujian ditemui.</div>";
                } else {
                    foreach ($groupedData as $sessionName => $rows) {
                        $sidHash = md5($sessionName); // Unique ID for toggle
                        $groupCount = count($rows);

                        // Split the string "2026 | Siri 1 | Sidang 1" into an array
                        $parts = explode(" | ", $sessionName);
                        $hasParts = count($parts) === 3;
                        
                        $yearBadge = $hasParts ? $parts[0] : "";
                        $siriBadge = $hasParts ? $parts[1] : "";
                        $sidangName = $hasParts ? $parts[2] : $sessionName;

                        // Ensure search functionality still works with the full combined string
                        echo "<div class='pm-accordion-block' data-session='" . strtolower(htmlspecialchars($sessionName)) . "'>";

                        // Enhanced Accordion Header
                        echo "<div class='pm-accordion-header' onclick=\"toggleSummaryAccordion('{$sidHash}')\" id='header_{$sidHash}'>";
                        echo "<div class='pm-accordion-header-left'>";
                        echo "<span class='pm-accordion-arrow' id='arrow_{$sidHash}'>▶</span>";
                        
                        if ($hasParts) {
                            echo "<span class='pm-badge pm-badge-year'>" . htmlspecialchars($yearBadge) . "</span>";
                            echo "<span class='pm-badge pm-badge-siri'>" . htmlspecialchars($siriBadge) . "</span>";
                            echo "<span class='pm-accordion-title'>" . htmlspecialchars($sidangName) . "</span>";
                        } else {
                            // Fallback just in case the string format doesn't match
                            echo "<span class='pm-accordion-title'>" . htmlspecialchars($sessionName) . "</span>";
                        }
                        
                        echo "</div>";
                        
                        // Right side counter
                        echo "<div class='pm-count-badge'>{$groupCount} Kumpulan</div>";
                        echo "</div>";

                        // Accordion Content (Table) - No changes to the table itself
                        echo "<div class='pm-accordion-content' id='content_{$sidHash}'>";
                        echo "<div class='pm-table-wrap'><table class='pm-table summaryTable'>";
                        echo "<thead><tr><th>Peringkat</th><th>Status Ujian</th><th>Kumpulan</th><th>Cawangan</th><th>Juri Bertugas</th></tr></thead>";
                        echo "<tbody>";

                        foreach ($rows as $r) {
                            $juriText = $r["judge_name"]
                                ? htmlspecialchars($r["judge_name"])
                                : "<i style='color:var(--c-text-faint);'>Tiada Juri</i>";
                            $cawanganText = $r["school_names"]
                                ? htmlspecialchars($r["school_names"])
                                : "<i style='color:var(--c-text-faint);'>Tiada Pelajar</i>";

                            echo "<tr class='summary-row'>";
                            echo "<td data-label='Peringkat' class='sc-peringkat'>" . htmlspecialchars($r["level_name"]) . "</td>";
                            echo "<td data-label='Status Ujian' class='sc-status'>{$r["status_list"]}</td>";
                            echo "<td data-label='Kumpulan' class='sc-kumpulan'>" . htmlspecialchars($r["group_name"]) . "</td>";
                            echo "<td data-label='Cawangan' class='sc-cawangan'>{$cawanganText}</td>";
                            echo "<td data-label='Juri Bertugas' class='sc-juri'>{$juriText}</td>";
                            echo "</tr>";
                        }

                        echo "</tbody></table></div></div></div>";
                    }
                }
                ?>
            </div>
        </div>
    </div>
</div>

<div class="judge-card">
    <h3 class="pm-card-title">Pilih Parameter Pemarkahan</h3>
    <form method="POST" action="judge.php" id="judgeSelection">
        <input type="hidden" name="start_marking" value="1">
        <?php if ($current_siri_id): ?>
            <input type="hidden" name="siri_id" value="<?= $current_siri_id ?>">
        <?php endif; ?>

        <div class="param-grid">

            <?php if ($show_year_dropdown): ?>
            <div class="param-field">
                <label class="param-label">Tahun:</label>
                <select id="yearSelect" class="pm-input" style="width:100%;">
                    <option value="">-- Pilih Tahun --</option>
                    <?php foreach ($all_years as $yr): ?>
                        <option value="<?= $yr ?>" <?= $current_year == $yr ? 'selected' : '' ?>>
                            <?= htmlspecialchars($yr) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <?php if ($show_siri_dropdown): ?>
            <div class="param-field">
                <label class="param-label">Siri:</label>
                <select name="siri_id" id="siriSelect" class="pm-input" style="width:100%;">
                    <option value="">-- <?= $show_year_dropdown ? 'Pilih Tahun Dahulu' : 'Pilih Siri' ?> --</option>
                    <?php foreach ($all_siri as $sr):
                        $hidden = $show_year_dropdown && $current_year && $sr['siri_year'] != $current_year;
                    ?>
                        <option value="<?= $sr['siri_id'] ?>"
                            data-year="<?= $sr['siri_year'] ?>"
                            <?= $current_siri_id == $sr['siri_id'] ? 'selected' : '' ?>
                            <?= $hidden ? 'style="display:none"' : '' ?>>
                            <?= htmlspecialchars($sr['siri_name']) ?> (<?= $sr['siri_year'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="param-field">
                <label class="param-label">Sidang:</label>
                <select name="session_id" id="sessionSelect" required class="pm-input" style="width:100%;">
                    <option value="">-- <?= ($show_siri_dropdown && !$current_siri_id) ? 'Pilih Siri Dahulu' : 'Pilih Sidang' ?> --</option>
                    <?php
                    // If siri is already determined, pre-populate sessions
                    if ($current_siri_id) {
                        $stmt = $conn->prepare("SELECT * FROM sessions WHERE siri_id = ? ORDER BY session_name ASC");
                        $stmt->bind_param("i", $current_siri_id);
                        $stmt->execute();
                        $sess_res = $stmt->get_result();
                        while ($s = $sess_res->fetch_assoc()) {
                            $sel = $current_session == $s["session_id"] ? "selected" : "";
                            echo "<option value='{$s["session_id"]}' $sel>{$s["session_name"]}</option>";
                        }
                        $stmt->close();
                    } elseif (!$show_siri_dropdown) {
                        // No siri dropdown shown — show all sessions
                        $sess_res = $conn->query("SELECT * FROM sessions ORDER BY session_name ASC");
                        while ($s = $sess_res->fetch_assoc()) {
                            $sel = $current_session == $s["session_id"] ? "selected" : "";
                            echo "<option value='{$s["session_id"]}' $sel>{$s["session_name"]}</option>";
                        }
                    }
                    ?>
                </select>
            </div>

            <div class="param-field">
                <label class="param-label">Peringkat:</label>
                <select name="level_id" id="levelSelect" required class="pm-input" style="width:100%;">
                    <option value="">-- Sila Pilih Sidang Dahulu --</option>
                    <?php if ($current_session) {
                        // Peringkat this judge already has a group in surface
                        // first — see the matching ajax_levels endpoint above
                        // for why.
                        $lvlStmt = $conn->prepare(
                            "SELECT l.*, EXISTS(
                                SELECT 1 FROM `groups` g WHERE g.level_id = l.level_id AND g.judge_id = ?
                             ) AS is_mine
                             FROM levels l
                             WHERE l.session_id = ?
                             ORDER BY is_mine DESC, l.level_name ASC"
                        );
                        $lvlStmt->bind_param("ii", $judge_id, $current_session);
                        $lvlStmt->execute();
                        $levels = $lvlStmt->get_result();
                        while ($l = $levels->fetch_assoc()) {
                            $sel = $current_level == $l["level_id"] ? "selected" : "";
                            $lvlLabel = htmlspecialchars($l["level_name"]);
                            if ($l["is_mine"]) { $lvlLabel = "★ " . $lvlLabel; }
                            echo "<option value='{$l["level_id"]}' $sel>{$lvlLabel}</option>";
                        }
                        $lvlStmt->close();
                    } ?>
                </select>
            </div>

            <div class="param-field param-field--wide">
                <label class="param-label">Kumpulan:</label>
                <select name="group_id" id="groupSelect" required class="pm-input" style="width:100%;">
                    <option value="">-- Sila Pilih Peringkat Dahulu --</option>
                    <?php if ($current_level) {
                        // Mirrors the ajax_groups endpoint below (same
                        // labeling/disabling logic) — this is the path used
                        // when the marking table is already showing on page
                        // load (group preselected via GET/POST) instead of
                        // picked live through the cascading dropdowns.
                        $groups = $conn->query(
                            "SELECT g.group_id, g.group_name, g.judge_id, g.edit_used, j.name AS judge_name,
                                    EXISTS(SELECT 1 FROM scores s WHERE s.group_id = g.group_id AND s.submitted = 1) AS has_marks
                             FROM `groups` g
                             LEFT JOIN judges j ON j.id = g.judge_id
                             WHERE g.level_id = " . (int)$current_level . " ORDER BY g.group_name ASC"
                        );
                        $gRows = [];
                        while ($g = $groups->fetch_assoc()) {
                            $sel          = $current_group == $g["group_id"];
                            $gUnassigned  = empty($g["judge_id"]);
                            $gOwnedByMe   = !empty($g["judge_id"]) && (int)$g["judge_id"] === $judge_id;
                            $gOwnedOther  = !$gUnassigned && !$gOwnedByMe;

                            // Other-judges' groups are excluded — except the
                            // currently-selected one, which must stay so the
                            // form doesn't submit an unselectable value (the
                            // access_denied message elsewhere handles that case).
                            if ($gOwnedOther && !$sel) continue;

                            $gHasMarks    = (bool) $g["has_marks"];
                            $gFullyLocked = $gHasMarks && (int)$g["edit_used"] === 1;

                            $gLabel = htmlspecialchars($g["group_name"]);
                            if ($gUnassigned) {
                                $gLabel .= " ⚠ (Belum Ditetapkan)";
                            } elseif ($gOwnedOther) {
                                $gLabel .= " 🔒 (Juri: " . htmlspecialchars($g["judge_name"] ?? "Tidak Diketahui") . ")";
                            } elseif ($gFullyLocked) {
                                $gLabel .= " 🔒 (Selesai — Dikunci)";
                            } elseif ($gHasMarks) {
                                $gLabel .= " ✅ (Anda — Selesai)";
                            } else {
                                $gLabel .= " (Anda)";
                            }

                            // Never disable the currently-selected option, for
                            // the same form-integrity reason as above.
                            $gDisabledAttr = ($gFullyLocked && !$sel) ? "disabled" : "";

                            $gRows[] = [
                                'id' => $g['group_id'], 'sel' => $sel, 'label' => $gLabel,
                                'unassigned' => $gUnassigned ? '1' : '0', 'own' => $gOwnedByMe ? '1' : '0',
                                'owned_other' => $gOwnedOther ? '1' : '0',
                                'has_marks' => $gHasMarks, 'disabled' => $gDisabledAttr,
                            ];
                        }

                        // Unmarked groups first, already-marked groups last.
                        usort($gRows, function ($a, $b) {
                            if ($a['has_marks'] !== $b['has_marks']) return $a['has_marks'] <=> $b['has_marks'];
                            return 0;
                        });

                        foreach ($gRows as $row) {
                            $selAttr = $row['sel'] ? 'selected' : '';
                            echo "<option value='{$row['id']}' $selAttr data-unassigned='{$row['unassigned']}' data-own='{$row['own']}' data-owned-other='{$row['owned_other']}' {$row['disabled']}>{$row['label']}</option>";
                        }
                    } ?>
                </select>
            </div>

            <div class="param-field param-field--btn">
                <label class="param-label" style="visibility:hidden;">-</label>
                <button type="submit" class="pm-btn pm-btn-primary form-btn-submit" style="width:100%;">▶ Mula Menanda</button>
            </div>

        </div>
    </form>
</div>

<script>
    // Global criteria data (populated after marking form renders — see bottom script block)
    let criteriaByTest = {};

    // ACCORDION UI LOGIC
    function toggleSummaryAccordion(id) {
        const content = document.getElementById('content_' + id);
        const arrow = document.getElementById('arrow_' + id);
        const header = document.getElementById('header_' + id);

        if (content.style.display === 'block') {
            content.style.display = 'none';
            arrow.style.transform = 'rotate(0deg)';
            header.classList.remove('active');
        } else {
            content.style.display = 'block';
            arrow.style.transform = 'rotate(90deg)';
            header.classList.add('active');
        }
    }

    // LIVE SEARCH LOGIC FOR ACCORDION
    document.getElementById('summarySearchInput').addEventListener('keyup', function () {
        const keyword = this.value.toLowerCase();
        const blocks = document.querySelectorAll('.pm-accordion-block');

        blocks.forEach(block => {
            let hasMatch = false;
            const sessionName = block.getAttribute('data-session');
            const rows = block.querySelectorAll('.summary-row');

            // Match at the Session name level
            const sessionMatch = sessionName.includes(keyword);

            rows.forEach(row => {
                const rowText = row.textContent.toLowerCase();
                if (rowText.includes(keyword) || sessionMatch) {
                    row.style.display = '';
                    hasMatch = true;
                } else {
                    row.style.display = 'none';
                }
            });

            const content = block.querySelector('.pm-accordion-content');
            const arrow = block.querySelector('.pm-accordion-arrow');
            const header = block.querySelector('.pm-accordion-header');

            if (hasMatch) {
                block.style.display = '';
                // Auto expand if actively searching
                if (keyword.length > 0) {
                    content.style.display = 'block';
                    arrow.style.transform = 'rotate(90deg)';
                    header.classList.add('active');
                } else {
                    // Collapse back when search is empty
                    content.style.display = 'none';
                    arrow.style.transform = 'rotate(0deg)';
                    header.classList.remove('active');
                }
            } else {
                block.style.display = 'none';
            }
        });
    });

    $(document).ready(function () {
        // Live Clock & Date
        function updateDateTime() {
            const now = new Date();
            const timeEl = document.getElementById('liveTime');
            const dateEl = document.getElementById('liveDate');

            if (timeEl) {
                timeEl.textContent = now.toLocaleTimeString('ms-MY', { hour: '2-digit', minute: '2-digit' });
            }
            if (dateEl) {
                dateEl.textContent = now.toLocaleDateString('ms-MY', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
            }
        }

        updateDateTime(); // Run immediately on load
        setInterval(updateDateTime, 1000);

        // Initialize Select2 — #groupSelect is deliberately NOT in this
        // batch: it gets its own .select2() call below with a
        // templateResult. Calling .select2() twice on the same element
        // (once here, once below) silently drops the second call's config
        // in some Select2 versions, which is why the italic "Belum
        // Ditetapkan" style previously stopped applying.
        const select2Fields = ['#sessionSelect', '#levelSelect'];
        <?php if ($show_year_dropdown): ?> select2Fields.push('#yearSelect'); <?php endif; ?>
        <?php if ($show_siri_dropdown): ?> select2Fields.push('#siriSelect'); <?php endif; ?>
        // minimumResultsForSearch: -1 removes the search box entirely — these
        // lists are short so search isn't needed, and without a search input
        // Select2 has nothing to auto-focus, so opening the dropdown no
        // longer pops the on-screen keyboard on mobile.
        $(select2Fields.join(',')).select2({ width: '100%', minimumResultsForSearch: -1 });

        $('#groupSelect').select2({
            width: '100%',
            minimumResultsForSearch: -1,
            templateResult: function(opt) {
                if (!opt.id) return opt.text;
                const el = opt.element;
                const span = document.createElement('span');
                span.textContent = opt.text;
                if (el && el.dataset.unassigned === '1') {
                    span.style.color = 'var(--c-text-muted)';
                    span.style.fontStyle = 'italic';
                } else if (el && el.dataset.ownedOther === '1') {
                    span.style.color = 'var(--c-red)';
                    span.style.opacity = '0.7';
                    span.style.textDecoration = 'line-through';
                }
                return span;
            }
        });

        // Year → filter Siri options
        $('#yearSelect').on('change', function () {
            const year = $(this).val();
            const siriSelect = $('#siriSelect');
            siriSelect.find('option[data-year]').each(function () {
                $(this).toggle(!year || $(this).data('year') == year);
            });
            siriSelect.val('').trigger('change.select2');
            $('#sessionSelect').empty().append('<option value="">-- Pilih Siri Dahulu --</option>').trigger('change.select2');
            $('#levelSelect').empty().append('<option value="">-- Sila Pilih Sidang Dahulu --</option>').trigger('change.select2');
            $('#groupSelect').empty().append('<option value="">-- Sila Pilih Peringkat Dahulu --</option>').trigger('change.select2');
        });

        // Siri → Sessions
        $('#siriSelect').on('change', function () {
            const siriId = $(this).val();
            const sessionSelect = $('#sessionSelect');
            sessionSelect.empty().append('<option value="">Memuatkan...</option>').trigger('change.select2');
            $('#levelSelect').empty().append('<option value="">-- Sila Pilih Sidang Dahulu --</option>').trigger('change.select2');
            $('#groupSelect').empty().append('<option value="">-- Sila Pilih Peringkat Dahulu --</option>').trigger('change.select2');
            if (!siriId) {
                sessionSelect.empty().append('<option value="">-- Pilih Siri Dahulu --</option>').trigger('change.select2');
                return;
            }
            // Update the hidden siri_id input if no visible siri dropdown
            $('input[name="siri_id"]').val(siriId);
            $.get('judge.php?ajax_sessions=1&siri_id=' + encodeURIComponent(siriId), function (html) {
                sessionSelect.empty().append('<option value="">-- Pilih Sidang --</option>' + html).trigger('change.select2');
            });
        });

        // Session → Levels
        $('#sessionSelect').on('change', function () {
            const sessionId = $(this).val();
            const levelSelect = $('#levelSelect');
            levelSelect.empty().append('<option value="">Memuatkan...</option>').trigger('change.select2');
            $('#groupSelect').empty().append('<option value="">-- Sila Pilih Peringkat Dahulu --</option>').trigger('change.select2');
            if (!sessionId) {
                levelSelect.empty().append('<option value="">-- Pilih Sidang Dahulu --</option>').trigger('change.select2');
                return;
            }
            $.get('judge.php?ajax_levels=1&session_id=' + encodeURIComponent(sessionId), function (html) {
                levelSelect.empty().append('<option value="">-- Pilih Peringkat --</option>' + html).trigger('change.select2');
            });
        });

        // Level → Groups
        $('#levelSelect').on('change', function () {
            const levelId = $(this).val();
            const groupSelect = $('#groupSelect');
            groupSelect.empty().append('<option value="">Memuatkan...</option>').trigger('change.select2');
            if (!levelId) {
                groupSelect.empty().append('<option value="">-- Pilih Peringkat Dahulu --</option>').trigger('change.select2');
                return;
            }
            $.get('judge.php?ajax_groups=1&level_id=' + encodeURIComponent(levelId), function (html) {
                groupSelect.empty().append('<option value="">-- Pilih Kumpulan --</option>' + html).trigger('change.select2');
            });
        });

    }); // <-- ONLY ONE CLOSING BRACKET FOR DOCUMENT.READY

    // Marking UI Logic
    document.addEventListener('change', function (e) {
        // 1. Handle Dropdown Value Change
        if (e.target.classList.contains('mark-select')) {
            const select = e.target;
            const { student, criteria } = select.dataset;

            // Update or create the hidden input that actually submits the data
            let inp = document.getElementById(`mark_${student}_${criteria}`);
            if (!inp) {
                inp = document.createElement('input'); inp.type = 'hidden';
                inp.id = `mark_${student}_${criteria}`; inp.name = `marks[${student}][${criteria}]`;
                document.getElementById('markForm').appendChild(inp);
            }
            inp.value = select.value;
            updateTotals(student);
        }

        // 2. Handle the "Nilai" Checkbox Toggle
        if (e.target.classList.contains('enable-marking')) {
            const cb = e.target;
            const wrapper = document.getElementById(`keypad_${cb.dataset.student}_${cb.dataset.criteria}`);
            const select = document.getElementById(`select_${cb.dataset.student}_${cb.dataset.criteria}`);
            const inp = document.getElementById(`mark_${cb.dataset.student}_${cb.dataset.criteria}`);

            wrapper.style.display = cb.checked ? 'block' : 'none';
            if (!cb.checked) {
                if (select) select.value = '';
                if (inp) inp.value = '';
            }
            updateTotals(cb.dataset.student);
        }
    });

    function updateTotals(studentId) {
        let overallSum = 0, overallMax = 0;
        for (const testName in criteriaByTest) {
            let testSum = 0, testMax = 0;
            Object.keys(criteriaByTest[testName]).forEach(cid => {
                const cb = document.querySelector(`.enable-marking[data-student='${studentId}'][data-criteria='${cid}']`);
                if (!cb || cb.checked) {
                    testMax += 10;
                    // Look for the dropdown value instead of the selected button
                    const sel = document.querySelector(`.mark-select[data-student='${studentId}'][data-criteria='${cid}']`);
                    if (sel && sel.value !== '') testSum += parseInt(sel.value);
                }
            });
            const cell = document.querySelector(`.test-total[data-student='${studentId}'][data-test='${testName}']`);
            if (cell) cell.innerHTML = `${testSum} / ${testMax}`;
            overallSum += testSum; overallMax += testMax;
        }
        const oCell = document.querySelector(`.overall-total[data-student='${studentId}']`);
        if (oCell) oCell.innerHTML = `${overallSum} / ${overallMax}`;
    }
</script>

<?php // ── MARKING LOGIC ──────────────────────────────────────────────────────

if ($marking_active && $current_session && $current_group) {
    $session_id = (int) $current_session;
    $group_id = (int) $current_group;

    // SECURED: Fetch level and associated judge
    $level_id = 0;
    $group_judge_id = 0;
    $group_edit_used = 0;
    $stmt = $conn->prepare(
        "SELECT level_id, judge_id, edit_used FROM `groups` WHERE group_id = ?",
    );

    if ($stmt) {
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $r = $stmt->get_result();
        if ($r && $r->num_rows === 1) {
            $grpData = $r->fetch_assoc();
            $level_id = (int) $grpData["level_id"];
            $group_judge_id = (int) $grpData["judge_id"];
            $group_edit_used = (int) ($grpData["edit_used"] ?? 0); // Check if edit was used
        }
        $stmt->close();
    }

    // SECURED: Check scores table directly to see if someone already marked it
    // Failsafe: only query judge_id from scores if the column actually exists
    $marker_judge_id = $group_judge_id;
    $col_check = $conn->query("SHOW COLUMNS FROM scores LIKE 'judge_id'");
    if ($col_check && $col_check->num_rows > 0) {
        $stmt = $conn->prepare(
            "SELECT judge_id FROM scores WHERE group_id = ? LIMIT 1",
        );
        if ($stmt) {
            $stmt->bind_param("i", $group_id);
            $stmt->execute();
            $rj = $stmt->get_result();
            if ($rj && $rj->num_rows > 0) {
                $rowj = $rj->fetch_assoc();
                if (!empty($rowj["judge_id"])) {
                    $marker_judge_id = (int) $rowj["judge_id"];
                }
            }
            $stmt->close();
        }
    }

    // SECURED: Check existing scores
    $existing_scores = [];
    $is_locked = false;
    // edit_mode=1 only works if the edit token hasn't been consumed yet.
    // This is the core fix: a judge who already edited once cannot re-open
    // edit mode by re-posting edit_mode=1, because group_edit_used will be 1.
    $raw_edit_requested = isset($_POST["edit_mode"]) && $_POST["edit_mode"] == "1";
    $is_edit_mode_form  = $raw_edit_requested && ($group_edit_used === 0);

    // status distinguishes a real entered mark ('scored') from a criteria
    // the judge explicitly opted out of via the Abai toggle ('abaikan') —
    // both used to look identical (no row at all for Abai), which is
    // exactly what made it indistinguishable from "nobody's touched this
    // yet" on the PIC's manual-marks page.
    $existing_status = [];
    $stmt = $conn->prepare("
        SELECT student_id, criteria_id, mark, status, submitted
        FROM scores
        WHERE group_id = ?
    ");
    if ($stmt) {
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $sres = $stmt->get_result();
        if ($sres && $sres->num_rows > 0) {
            $has_submitted = false;
            while ($sr = $sres->fetch_assoc()) {
                // Draft marks (submitted=0) still prefill the form so the judge
                // sees their in-progress work, but they must NOT lock the group —
                // only a finalized (submitted=1) mark counts as "saved".
                $existing_scores[$sr["student_id"]][$sr["criteria_id"]] =
                    $sr["mark"];
                $existing_status[$sr["student_id"]][$sr["criteria_id"]] =
                    $sr["status"] ?? "scored";
                if ((int) $sr["submitted"] === 1) {
                    $has_submitted = true;
                }
            }
            // Lock if a submitted mark exists, UNLESS edit token is valid AND not yet consumed
            $is_locked = $has_submitted && !($is_edit_mode_form && $group_edit_used === 0);
        }
        $stmt->close();
    }

    // TAMPER PROTECTION: reject if a different judge posts edit_mode=1
    if (
        $is_edit_mode_form &&
        $marker_judge_id !== 0 &&
        $marker_judge_id !== $judge_id
    ) {
        $is_locked = true;
        $is_edit_mode_form = false;
    }

    // SECURED: Students in group (Correlated Subquery Fix to prevent duplicate rows from attendance)
    $student_rows = [];
    $stmt = $conn->prepare("
        SELECT st.student_id, st.student_name, sc.school_name,
               COALESCE((SELECT status FROM attendance WHERE student_id = st.student_id ORDER BY attendance_id DESC LIMIT 1), 'Absent') AS status
        FROM group_students gs
        JOIN students st ON gs.student_id = st.student_id
        LEFT JOIN schools sc ON st.school_id = sc.school_id
        WHERE gs.group_id = ?
        ORDER BY COALESCE(gs.sort_order, 999999), st.student_id
    ");
    if ($stmt) {
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($r2 = $res->fetch_assoc()) {
                $student_rows[] = $r2;
            }
        }
        $stmt->close();
    }

    // SECURED: Criteria by test
    $criteria_by_test = [];
    $test_ids_map = []; // Added for dynamic JS creation
    $stmt = $conn->prepare("
    SELECT c.criteria_id, c.criteria_name, t.test_id, t.test_name
    FROM criteria c
    JOIN tests t ON c.test_id = t.test_id
    WHERE t.level_id = ?
    ORDER BY t.test_id, c.criteria_id
");
    if ($stmt) {
        $stmt->bind_param("i", $level_id);
        $stmt->execute();
        $cres = $stmt->get_result();
        if ($cres) {
            while ($cr = $cres->fetch_assoc()) {
                $criteria_by_test[$cr["test_name"]][$cr["criteria_id"]] =
                    $cr["criteria_name"];
                $test_ids_map[$cr["test_name"]] = $cr["test_id"]; // Map the ID
            }
        }
        $stmt->close();
    }

    // ── FETCH EXISTING DATA (no longer needed for modal — cascade AJAX used instead) ──

    // // SECURED: Criteria by test
    // $criteria_by_test = [];
    // $stmt = $conn->prepare("
    //     SELECT c.criteria_id, c.criteria_name, t.test_name
    //     FROM criteria c
    //     JOIN tests t ON c.test_id = t.test_id
    //     WHERE t.level_id = ?
    //     ORDER BY t.test_id, c.criteria_id
    // ");
    // if ($stmt) {
    //     $stmt->bind_param("i", $level_id);
    //     $stmt->execute();
    //     $cres = $stmt->get_result();
    //     if ($cres)
    //         while ($cr = $cres->fetch_assoc()) {
    //             $criteria_by_test[$cr['test_name']][$cr['criteria_id']] = $cr['criteria_name'];
    //         }
    //     $stmt->close();
    // }

    // Student list table
    $student_list_html =
        "<div class='pm-table-wrap student-list-wrap'><table class='pm-table'>";

    // Removed the "spring" width hacks so the table balances naturally and uniformly.
    $student_list_html .= "<thead><tr>
        <th style='text-align: center; width: 60px;'>ID</th>
        <th>Nama</th>
        <th>Cawangan</th>
        <th style='text-align: center; width: 140px;'>Status</th>
    </tr></thead><tbody>";

    foreach ($student_rows as $r) {
        if ($r["status"] === "Present") {
            $row_style = "";
            // Emerald Green pill badge - visible in both Dark & Light modes
            $status_cell = "<td style='text-align: center;'>
                <span style='display: inline-block; color: #10B981; font-weight: 700; background: rgba(16, 185, 129, 0.15); padding: 4px 12px; border-radius: 99px; font-size: 0.85rem;'>✔ Hadir</span>
            </td>";
        } else {
            $row_style = "style='opacity: 0.6;'";
            // Red pill badge - visible in both Dark & Light modes
            $status_cell = "<td style='text-align: center;'>
                <span style='display: inline-block; color: #EF4444; font-weight: 700; background: rgba(239, 68, 68, 0.15); padding: 4px 12px; border-radius: 99px; font-size: 0.85rem;'>❌ Tidak Hadir</span>
            </td>";
        }
        $student_list_html .= "<tr $row_style>
            <td style='text-align: center; color: var(--c-text-faint);'>{$r["student_id"]}</td>
            <td style='font-weight: 600; color: var(--c-text);'>" . htmlspecialchars($r["student_name"]) . "</td>
            <td style='color: var(--c-text-muted);'>" . htmlspecialchars($r["school_name"] ?? '') . "</td>
            $status_cell
        </tr>";
    }
    $student_list_html .= "</tbody></table></div>";

    // Added pm-card-title class to ensure the heading styling matches the rest of the page
    echo "<div class='judge-card'><h3 class='pm-card-title'>Senarai Pesilat</h3>$student_list_html</div>";

    if (count($student_rows) === 0) {
        echo "<div class='pm-alert pm-alert-danger'>❌ Tiada pelajar dalam kumpulan ini.</div>";
    } else {
        if ($is_edit_mode_form) {
            echo "<div class='pm-alert pm-alert-warn'>✏️ <strong>Mod Edit Aktif</strong> — Sila berhati-hati. Kemaskini markah hanya boleh dilakukan <b>SEKALI SAHAJA</b> sebelum dikunci sepenuhnya. Simpan semula untuk mengunci.</div>";
        }

        if (!$is_locked) {
            echo "<div style='display: flex; gap: 8px; margin-bottom: 16px; justify-content: flex-end;'>
                    <button type='button' class='pm-btn pm-btn-primary' style='font-size:0.78rem; padding: 5px 12px; border-radius: 5px; line-height:1.4;' onclick='openParameterModal()'>+ Tambah Ujian &amp; Kriteria</button>
                    </div>";
        }

        // --- OWNERSHIP LOCK LOGIC ---
        if ($is_locked) {
            if ($marker_judge_id === 0 || $marker_judge_id === $judge_id) {
                if ($group_edit_used === 1) {
                    // The judge has used their edit. Hide button, show locked message.
                    echo "<div class='pm-alert pm-lock-alert'>
                            🔒 <strong>Dikunci Sepenuhnya:</strong> Markah telah disimpan dan disahkan. Pengemaskinian tidak lagi dibenarkan.
                          </div>";
                } else {
                    // This judge owns the marks AND hasn't used their edit yet
                    echo "<div class='pm-alert pm-alert-success'>🔒 Markah telah disimpan.
                                  <form method='POST' style='display:inline;margin-left:10px;'>
                                    <input type='hidden' name='session_id' value='$session_id'>
                                    <input type='hidden' name='level_id'   value='$level_id'>
                                    <input type='hidden' name='group_id'   value='$group_id'>
                                    <input type='hidden' name='start_marking' value='1'>
                                    <input type='hidden' name='edit_mode'  value='1'>
                                    <button class='pm-btn pm-btn-ghost' style='font-size:0.8rem;padding:4px 10px;'>✏️ Edit</button>
                                  </form></div>";
                }
            } else {
                // Another judge owns the marks. Hide edit button, show warning.
                $marker_name = "Juri Lain";
                if ($marker_judge_id) {
                    $q = $conn->query(
                        "SELECT name FROM judges WHERE id = $marker_judge_id",
                    );
                    if ($q && $q->num_rows) {
                        $marker_name = htmlspecialchars(
                            $q->fetch_assoc()["name"],
                        );
                    }
                }
                echo "<div class='pm-alert pm-alert-danger'>
                        🔒 <strong>Akses Ditolak:</strong> Markah kumpulan ini telah direkodkan oleh <b>$marker_name</b>. Anda tidak dibenarkan mengeditnya.
                      </div>";
            }
        }

        echo "<form method='POST' action='save_scores.php' id='markForm'>
        <input type='hidden' name='session_id' value='$session_id'>
        <input type='hidden' name='level_id'   value='$level_id'>
        <input type='hidden' name='group_id'   value='$group_id'>
        <input type='hidden' name='judge_id'   value='$judge_id'>
        <input type='hidden' name='csrf_token' value='" . htmlspecialchars($csrf) . "'>
        <input type='hidden' name='edit_mode'  value='" .
            ($is_edit_mode_form ? "1" : "0") .
            "'>
        <div id='pendingParamsContainer'><!-- pending test/criteria pairs injected here by JS --></div>
     ";

        if (!empty($existing_scores)) {
            foreach ($existing_scores as $s_id => $crits) {
                foreach ($crits as $c_id => $val) {
                    echo "<input type='hidden' id='mark_{$s_id}_{$c_id}' name='marks[{$s_id}][{$c_id}]' value='$val'>";
                }
            }
        }

        // ── MARKING TABLE ──────────────────────────────────────────────
        // Single table for all viewports; it scrolls horizontally on
        // narrow screens instead of switching to a card/stepper layout.
        //
        // The checkbox + keypad + hidden input for each student×criteria is
        // rendered exactly ONCE (as a "markctrl" unit) directly into its
        // table cell.

        $render_markctrl = function ($sid, $cid) use ($existing_scores, $existing_status, $is_locked) {
            $existingStatus = $existing_status[$sid][$cid] ?? "";
            $isAbaikan = $existingStatus === "abaikan";
            // An abaikan row's mark is just a DB placeholder (0) — never show
            // it as if the judge actually keyed a 0.
            $existingVal = $isAbaikan ? "" : ($existing_scores[$sid][$cid] ?? "");
            $hasValue = $existingVal !== "";
            $isChecked = (!$isAbaikan) && ($hasValue || !$is_locked) ? "checked" : "";
            $gridDisplay = (!$isAbaikan) && ($hasValue || !$is_locked) ? "block" : "none";
            $disabledAttr = $is_locked ? "disabled" : "";
            $toggleText = $isChecked ? "Dinilai" : "Abai";
            $abaiVal = $isAbaikan ? "1" : "";

            $html = "<div class='markctrl' id='markctrl_{$sid}_{$cid}' data-student='$sid' data-criteria='$cid'>";
            $html .= "<label class='pm-nilai-wrap'>";
            $html .= "<input type='checkbox' class='pm-nilai-checkbox' data-student='$sid' data-criteria='$cid' $isChecked $disabledAttr>";
            $html .= "<div class='pm-toggle-switch'></div>";
            $html .= "<span class='pm-toggle-label'>$toggleText</span>";
            $html .= "</label>";
            $html .= "<div class='keypad-wrapper' id='keypad_{$sid}_{$cid}' style='display:$gridDisplay;'>";
            $html .= "<input type='hidden' id='mark_{$sid}_{$cid}' name='marks[{$sid}][{$cid}]' value='$existingVal'>";
            // Carries the Abai decision to save_scores.php — marks[] alone
            // can't, since an empty mark means "abai" and "never touched"
            // identically. Toggled alongside the checkbox in JS below.
            $html .= "<input type='hidden' id='abai_{$sid}_{$cid}' name='abai[{$sid}][{$cid}]' value='$abaiVal'>";
            $lockedClass = $is_locked ? "locked" : "";
            $html .= "<div class='pm-keypad $lockedClass'>";
            $keys = [1, 2, 3, 4, 5, 6, 7, 8, 9, "X", 0, 10];
            foreach ($keys as $k) {
                $val = $k === "X" ? "" : $k;
                $activeCls = $hasValue && (string) $existingVal === (string) $val && $k !== "X" ? "active" : "";
                $displayK = $k === "X" ? "✖" : $k;
                $btnClass = $k === "X" ? "pm-key-btn clear-btn" : "pm-key-btn";
                $html .= "<button type='button' class='$btnClass $activeCls' data-target='mark_{$sid}_{$cid}' data-val='$val' data-student='$sid' data-criteria='$cid' $disabledAttr>$displayK</button>";
            }
            $html .= "</div></div></div>"; // .pm-keypad, .keypad-wrapper, .markctrl
            return $html;
        };

        // NOTE: overflow is intentionally NOT hidden on .judge-card itself —
        // position:sticky only sticks within its nearest ancestor that clips
        // overflow, so an overflow:hidden wrapper here would trap the mobile
        // summary strip inside the card instead of letting it stick to the
        // viewport while the judge scrolls through student cards. The
        // desktop table keeps its own clipped/rounded corners via
        // #desktopMarkingTable below instead.
        echo "<div class='judge-card marking-card-bleed' style='padding: 0;'>";

        // ── Desktop: one table PER UJIAN, switched by tabs instead of one
        // giant table scrolling sideways through every ujian at once. Each
        // tab's table can still scroll horizontally if that ujian alone has
        // many kriteria columns — the client asked for tabs on top of the
        // existing table/scrolling, not a replacement for it. ──
        echo "<div id='desktopMarkingTable' style='overflow-x: auto; overflow-y: hidden; border-radius: 6px;'>";

        if (empty($criteria_by_test)) {
            echo "<div class='pm-alert pm-alert-warn' style='margin:16px;'>⚠️ Tiada ujian/kriteria ditetapkan untuk peringkat ini.</div>";
        } else {
            echo "<div class='desktop-ujian-tabs' role='tablist' id='desktopUjianTabsContainer'>";
            $tabIndex = 0;
            foreach ($criteria_by_test as $testName => $criteriaList) {
                $activeCls = $tabIndex === 0 ? "active" : "";
                $testNameAttrEsc = htmlspecialchars($testName, ENT_QUOTES);
                echo "<button type='button' class='desktop-ujian-tab-btn $activeCls' data-tab-index='$tabIndex' data-test-name='{$testNameAttrEsc}'>" . htmlspecialchars($testName) . "</button>";
                $tabIndex++;
            }
            echo "</div>";

            $tabIndex = 0;
            foreach ($criteria_by_test as $testName => $criteriaList) {
                $testNameAttr = htmlspecialchars($testName);
                $testNameAttrEsc = htmlspecialchars($testName, ENT_QUOTES);
                $panelDisplay = $tabIndex === 0 ? "block" : "none";
                $maxTest = count($criteriaList) * 10;

                echo "<div class='desktop-ujian-tab-panel' data-tab-index='$tabIndex' data-test-name='{$testNameAttrEsc}' style='display:$panelDisplay;'>";
                echo "<div class='pm-table-wrap' style='border: none; margin: 0; border-radius: 0;'>";
                echo "<table class='pm-table marking-table' data-test-name='{$testNameAttrEsc}'>";

                echo "<thead><tr><th class='sticky-col' style='background: var(--c-surface-2); z-index: 15;'>Pelajar</th>";
                foreach ($criteriaList as $cid => $cname) {
                    echo "<th>" . htmlspecialchars($cname) . "</th>";
                }
                echo "<th data-test='$testNameAttr' style='background: var(--c-surface-3); color: var(--c-text); text-align: center;'>Jumlah $testNameAttr</th>";
                echo "<th style='background: var(--c-surface-3); color: var(--c-text); text-align: center;'>Jumlah Keseluruhan</th></tr></thead><tbody>";

                foreach ($student_rows as $s) {
                    $sid = $s["student_id"];
                    $is_present = $s["status"] === "Present";

                    if ($is_present) {
                        echo "<tr data-student='$sid'>";
                        echo "<td class='sticky-col'>" . htmlspecialchars($s["student_name"]) . "</td>";
                        foreach ($criteriaList as $cid => $cname) {
                            echo "<td class='mark-cell' id='desktopSlot_{$sid}_{$cid}' style='text-align: center;'>" . $render_markctrl($sid, $cid) . "</td>";
                        }
                        echo "<td class='test-total' data-student='$sid' data-test='$testNameAttr'
                      style='background:var(--c-surface-2);color:var(--c-text);font-weight:700;text-align:center;'>0 / $maxTest</td>";
                        echo "<td class='overall-total' data-student='$sid'
          style='background:var(--c-surface-1);color:var(--c-text);font-weight:700;text-align:center;'>0 / 0</td>";
                        echo "</tr>";
                    } else {
                        echo "<tr style='opacity:0.4;background:rgba(214,40,40,0.06);' title='Tidak Hadir'>";
                        echo "<td class='sticky-col'>" . htmlspecialchars($s["student_name"]) . " <br><em style='color:var(--c-red);font-size:0.85em;'>(Tidak Hadir)</em></td>";
                        foreach ($criteriaList as $c) {
                            echo "<td style='color:#666;text-align:center;'>—</td>";
                        }
                        echo "<td style='background:var(--c-surface-2);color:#666;text-align:center;'>—</td>";
                        echo "<td style='background:var(--c-surface-1);color:#666;text-align:center;'>—</td></tr>";
                    }
                }
                echo "</tbody></table></div></div>"; // .marking-table, .pm-table-wrap, .desktop-ujian-tab-panel
                $tabIndex++;
            }
        }
        echo "</div>"; // #desktopMarkingTable
        echo "</div>"; // .judge-card
            echo "<div style='margin-top: 20px; padding: 0 20px 20px 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;' class='mark-actions-row'>";

        if (!$is_locked) {
            // Buttons to show when actively marking or editing
            echo "<button type='submit' class='pm-btn pm-btn-primary mark-action-btn' style='padding: 8px 18px; font-size: 0.85rem;'>💾 Selesai Pemarkahan</button>";
            echo "<button type='button' class='pm-btn pm-btn-ghost mark-action-btn' style='padding: 8px 18px; font-size: 0.85rem;' onclick='pendingParams=[]; document.getElementById(\"pendingParamsContainer\").innerHTML=\"\"; const b=document.getElementById(\"pendingParamsBadge\"); if(b)b.remove(); window.location.href=\"judge.php\";'>Batal</button>";
        } else {
            // Button to show when the table is locked (View Only)
            echo "<button type='button' class='pm-btn pm-btn-ghost mark-action-btn' style='padding: 8px 18px; font-size: 0.85rem; background: var(--c-surface-2); color: var(--c-text); border: 1px solid var(--c-border-strong);' onclick='window.location.href=\"judge.php\";'>⬅ Kembali ke Dashboard</button>";
        }

        echo "</div>";
        echo "</form></div>";

        // ── CUSTOM MODALS HTML & CSS ──
        // The Peringkat (level) a judge marks is fixed by which group they
        // were assigned — this modal only adds Ujian/Kriteria under that
        // same, already-existing Peringkat, never a different or new one.
        $current_level_name = "";
        if ($level_id) {
            $mlvl = $conn->prepare("SELECT level_name FROM levels WHERE level_id = ? LIMIT 1");
            if ($mlvl) {
                $mlvl->bind_param("i", $level_id);
                $mlvl->execute();
                $mlvl_res = $mlvl->get_result();
                if ($mlvl_res && $mlvl_res->num_rows > 0) {
                    $current_level_name = $mlvl_res->fetch_assoc()["level_name"];
                }
                $mlvl->close();
            }
        }

        echo "
        <style>
            .pm-modal-overlay {
                position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
                background: rgba(0,0,0,0.65); backdrop-filter: blur(3px);
                z-index: 9999; display: none; align-items: center; justify-content: center;
                opacity: 0; transition: opacity 0.18s ease;
            }
            .pm-modal-overlay.show { opacity: 1; }
            .pm-modal-box {
                background: var(--c-surface-1); border: 1px solid var(--c-border-strong);
                border-radius: 10px; width: 100%; max-width: 480px;
                box-shadow: 0 10px 36px rgba(0,0,0,0.55);
                transform: translateY(14px); transition: transform 0.18s ease;
                overflow: hidden;
            }
            .pm-modal-overlay.show .pm-modal-box { transform: translateY(0); }
            .pm-modal-header {
                display: flex; align-items: center; justify-content: space-between;
                padding: 12px 16px 11px;
                border-bottom: 1px solid var(--c-border-strong);
                background: var(--c-surface-2);
            }
            .pm-modal-title {
                font-family: 'Bebas Neue', sans-serif; font-size: 1.15rem;
                color: var(--c-white); letter-spacing: 0.06em;
                border-left: 3px solid var(--c-red); padding-left: 8px;
                line-height: 1.2;
            }
            .pm-modal-close-btn {
                background: none; border: none; color: var(--c-text-muted);
                font-size: 1.1rem; cursor: pointer; padding: 0 2px; line-height: 1;
                transition: color 0.15s;
            }
            .pm-modal-close-btn:hover { color: var(--c-red); }
            .pm-modal-body { padding: 14px 16px; }
            .pm-modal-grid {
                display: grid; grid-template-columns: 1fr 1fr; gap: 10px;
            }
            .pm-modal-field label {
                display: block; font-size: 0.68rem; font-weight: 700;
                text-transform: uppercase; letter-spacing: 0.08em;
                color: var(--c-text-muted); margin-bottom: 5px;
            }
            .pm-modal-field select, .pm-modal-field input.pm-modal-new-input {
                width: 100%; background: var(--c-surface-2);
                border: 1px solid var(--c-border-strong);
                border-radius: 6px; padding: 7px 10px;
                color: var(--c-text); font-size: 0.82rem;
                outline: none; transition: border-color 0.15s, box-shadow 0.15s;
                appearance: auto;
                box-sizing: border-box;
            }
            .pm-modal-field select:focus, .pm-modal-field input.pm-modal-new-input:focus {
                border-color: var(--c-red);
                box-shadow: 0 0 0 2px var(--c-red-dim);
            }
            .pm-modal-field input.pm-modal-new-input {
                margin-top: 6px;
            }
            .pm-modal-field select:disabled {
                opacity: 0.45; cursor: not-allowed;
            }
            .pm-modal-hint {
                margin-top: 10px; font-size: 0.75rem; color: var(--c-text-muted);
                background: var(--c-surface-2); border-left: 2px solid #eab308;
                padding: 7px 10px; border-radius: 0 5px 5px 0;
            }
            .pm-modal-actions {
                display: flex; gap: 8px; justify-content: flex-end;
                padding: 10px 16px 12px; border-top: 1px solid var(--c-border-strong);
                background: var(--c-surface-2);
            }
            .pm-modal-actions .pm-btn {
                font-size: 0.78rem; padding: 6px 14px; border-radius: 5px;
            }
        </style>

        <div class='pm-modal-overlay' id='modal-parameter'>
            <div class='pm-modal-box'>
                <div class='pm-modal-header'>
                    <div class='pm-modal-title'>Tambah Ujian &amp; Kriteria</div>
                    <button type='button' class='pm-modal-close-btn' onclick='closeModal(\"modal-parameter\")' title='Tutup'>&times;</button>
                </div>
                <div class='pm-modal-body'>
                    <div class='pm-modal-hint' style='margin-bottom:12px;'>
                        Peringkat: <b>" . htmlspecialchars($current_level_name) . "</b>
                    </div>
                    <div class='pm-modal-grid'>
                        <div class='pm-modal-field' style='grid-column: 1 / -1;'>
                            <label>1. Ujian</label>
                            <select id='paramTestSelect' disabled>
                                <option value=''>Memuatkan...</option>
                            </select>
                            <input type='text' id='paramTestNewInput' class='pm-modal-new-input' style='display:none;' placeholder='Nama ujian baru'>
                        </div>
                        <div class='pm-modal-field' style='grid-column: 1 / -1;'>
                            <label>2. Kriteria Pemarkahan</label>
                            <div id='paramCriteriaRows'></div>
                            <button type='button' class='pm-btn pm-btn-ghost' id='paramAddCriteriaRowBtn' style='margin-top:4px; font-size:0.75rem; padding:5px 10px;' onclick='addCriteriaRow()' disabled>+ Tambah Kriteria Lain</button>
                        </div>
                    </div>
                    <div class='pm-modal-hint'>
                        ℹ️ Anda boleh tambah lebih daripada satu Kriteria untuk Ujian yang sama sebelum klik <b>Tambah ke Senarai</b>. Pilihan ini <b>tidak akan disimpan</b> ke pangkalan data sehingga anda klik <b>Selesai Pemarkahan</b>. Klik Batal pada bila-bila masa untuk membuang.
                    </div>
                </div>
                <div class='pm-modal-actions'>
                    <button type='button' class='pm-btn pm-btn-ghost' onclick='closeModal(\"modal-parameter\")'>Batal</button>
                    <button type='button' class='pm-btn pm-btn-primary' id='paramSaveBtn' onclick='submitNewParameter()'>Tambah ke Senarai</button>
                </div>
            </div>
        </div>

        <div class='pm-modal-overlay' id='modal-missing-marks'>
            <div class='pm-modal-box' style='max-width:520px;'>
                <div class='pm-modal-header'>
                    <div class='pm-modal-title'>Markah Belum Lengkap</div>
                    <button type='button' class='pm-modal-close-btn' onclick=\"closeModal('modal-missing-marks')\" title='Tutup'>&times;</button>
                </div>
                <div class='pm-modal-body'>
                    <p style='margin:0 0 10px; font-size:0.85rem; color:var(--c-text);'>
                        Kriteria berikut ditanda <b>Dinilai</b> tetapi belum ada markah dipilih. Sila pilih markah (atau tukar kepada <b>Abai</b>) sebelum simpan.
                    </p>
                    <div id='missingMarksList' style='max-height:280px; overflow-y:auto; display:flex; flex-direction:column; gap:6px;'></div>
                </div>
                <div class='pm-modal-actions'>
                    <button type='button' class='pm-btn pm-btn-primary' onclick=\"closeModal('modal-missing-marks')\">OK, Saya Faham</button>
                </div>
            </div>
        </div>";
    }
} ?>

<script>
    // Populate variables from PHP
    criteriaByTest = <?= json_encode($criteria_by_test ?? []) ?>;
    const testIdsMap = <?= json_encode($test_ids_map ?? []) ?>;
    const currentLevelId = <?= $level_id ?? 0 ?>;
    const currentLevelName = <?= json_encode($current_level_name ?? "") ?>;
    const currentSessionId = <?= $session_id ?? 0 ?>;

    // --- DESKTOP UJIAN TAB SWITCHING ---
    // Same one-panel-at-a-time behavior as the mobile ribbon tabs, but for
    // the desktop table split by ujian. Matched by test name (not index) so
    // it stays correct even after a new ujian is appended mid-session.
    document.addEventListener('click', function (e) {
        if (!e.target.classList.contains('desktop-ujian-tab-btn')) return;
        const testName = e.target.dataset.testName;

        document.querySelectorAll('.desktop-ujian-tab-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.testName === testName);
        });
        document.querySelectorAll('.desktop-ujian-tab-panel').forEach(panel => {
            panel.style.display = panel.dataset.testName === testName ? 'block' : 'none';
        });
    });

    // --- SERVER-SIDE DRAFT AUTOSAVE ---
    // Persists each mark to the DB as submitted=0 so an accidental back-nav,
    // tab close, or crash doesn't lose in-progress marking. Debounced per
    // cell, with an immediate flush of any pending saves when the tab is
    // hidden/closed so nothing is lost to the debounce delay.
    const draftDebounce = {};
    const draftDirty = {};

    function queueDraftSave(studentId, criteriaId, value) {
        const key = studentId + '_' + criteriaId;
        draftDirty[key] = { studentId, criteriaId, value };
        clearTimeout(draftDebounce[key]);
        draftDebounce[key] = setTimeout(() => flushDraftSave(key), 500);
    }

    function flushDraftSave(key, useBeacon) {
        const entry = draftDirty[key];
        if (!entry) return;
        delete draftDirty[key];
        clearTimeout(draftDebounce[key]);

        const form = document.getElementById('markForm');
        if (!form) return;
        const body = new URLSearchParams({
            group_id: form.group_id.value,
            student_id: entry.studentId,
            criteria_id: entry.criteriaId,
            mark: entry.value,
            csrf_token: form.csrf_token.value
        });

        if (useBeacon && navigator.sendBeacon) {
            navigator.sendBeacon('save_draft_score.php', body);
        } else {
            // Fires on nearly every keystroke, so this also doubles as the
            // fastest possible signal that the session has expired — the
            // judge finds out within one field edit instead of only when
            // they next touch a dropdown or refresh.
            pmFetch('save_draft_score.php', { method: 'POST', body, keepalive: true }).catch(() => {});
        }
    }

    function flushAllDrafts(useBeacon) {
        Object.keys(draftDirty).forEach(key => flushDraftSave(key, useBeacon));
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') flushAllDrafts(true);
    });
    window.addEventListener('pagehide', function () { flushAllDrafts(true); });

    // --- KEYPAD & TOGGLE LOGIC ---
    document.addEventListener('change', function (e) {
        // 1. Toggle "Dinilai / Abai" Switch
        if (e.target.classList.contains('pm-nilai-checkbox')) {
            const cb = e.target;
            const sid = cb.dataset.student;
            const cid = cb.dataset.criteria;
            const keypad = document.getElementById(`keypad_${sid}_${cid}`);
            const input = document.getElementById(`mark_${sid}_${cid}`);
            const abaiInput = document.getElementById(`abai_${sid}_${cid}`);
            const label = cb.closest('.pm-nilai-wrap').querySelector('.pm-toggle-label');

            if (cb.checked) {
                label.innerHTML = 'Dinilai';
                keypad.style.display = 'block';
                if (abaiInput) abaiInput.value = ''; // no longer an explicit skip
            } else {
                label.innerHTML = 'Abai';
                keypad.style.display = 'none';
                input.value = ''; // Clear mark
                if (abaiInput) abaiInput.value = '1'; // explicit skip — save_scores.php records this distinctly from "untouched"
                document.querySelectorAll(`.pm-key-btn[data-target="mark_${sid}_${cid}"]`).forEach(k => k.classList.remove('active'));
                queueDraftSave(sid, cid, '');
            }
            updateTotals(sid);
        }
    });

    document.addEventListener('click', function (e) {
        // 2. Keypad Number Click (With Deselect Logic)
        if (e.target.classList.contains('pm-key-btn')) {
            if (e.target.disabled) return;
            const keyBtn = e.target;
            const targetId = keyBtn.dataset.target;
            const val = keyBtn.dataset.val;
            const input = document.getElementById(targetId);

            // Check if the button was already active BEFORE we strip classes
            const wasActive = keyBtn.classList.contains('active');

            // Remove active state from all siblings
            document.querySelectorAll(`.pm-key-btn[data-target="${targetId}"]`).forEach(k => k.classList.remove('active'));

            if (val === '') {
                // Clicked 'X' to clear
                input.value = '';
            } else if (wasActive) {
                // Clicked the already active number -> Deselect it
                input.value = '';
            } else {
                // Clicked a new number
                keyBtn.classList.add('active');
                input.value = val;
            }

            queueDraftSave(keyBtn.dataset.student, keyBtn.dataset.criteria, input.value);
            updateTotals(keyBtn.dataset.student);
            keyBtn.blur();
        }
    });

    function updateTotals(studentId) {
        let overallSum = 0, overallMax = 0;
        for (const testName in criteriaByTest) {
            let testSum = 0, testMax = 0;
            Object.keys(criteriaByTest[testName]).forEach(cid => {
                const cb = document.querySelector(`.pm-nilai-checkbox[data-student='${studentId}'][data-criteria='${cid}']`);
                if (!cb || cb.checked) {
                    testMax += 10;
                    const inp = document.getElementById(`mark_${studentId}_${cid}`);
                    if (inp && inp.value !== '') {
                        testSum += parseInt(inp.value);
                    }
                }
            });
            // update every instance (querySelectorAll), not just the first
            // one found in the DOM, in case a test name is ever rendered in
            // more than one place.
            document.querySelectorAll(`.test-total[data-student='${studentId}'][data-test='${testName}']`).forEach(cell => {
                cell.innerHTML = `${testSum} / ${testMax}`;
            });
            overallSum += testSum; overallMax += testMax;
        }
        document.querySelectorAll(`.overall-total[data-student='${studentId}']`).forEach(oCell => {
            oCell.innerHTML = `${overallSum} / ${overallMax}`;
        });
    }

    // --- DRAFT PROTECTION SYSTEM ---
    function saveDraftMarks() {
        const drafts = {};
        document.querySelectorAll('input[type="hidden"][name^="marks["]').forEach(inp => {
            if (inp.value !== '') drafts[inp.id] = inp.value;
        });

        const toggles = {};
        document.querySelectorAll('.pm-nilai-checkbox').forEach(cb => {
            toggles[cb.dataset.student + '_' + cb.dataset.criteria] = cb.checked;
        });

        sessionStorage.setItem('pm_draft_marks', JSON.stringify(drafts));
        sessionStorage.setItem('pm_draft_toggles', JSON.stringify(toggles));
    }

    function restoreDraftMarks() {
        const draftsStr = sessionStorage.getItem('pm_draft_marks');
        const togglesStr = sessionStorage.getItem('pm_draft_toggles');

        if (togglesStr) {
            const toggles = JSON.parse(togglesStr);
            for (const key in toggles) {
                const cb = document.querySelector(`.pm-nilai-checkbox[data-student="${key.split('_')[0]}"][data-criteria="${key.split('_')[1]}"]`);
                const keypad = document.getElementById('keypad_' + key);
                const abaiInput = document.getElementById('abai_' + key);
                if (cb && keypad) {
                    const label = cb.closest('.pm-nilai-wrap').querySelector('.pm-toggle-label');
                    cb.checked = toggles[key];
                    if (cb.checked) {
                        label.innerHTML = 'Dinilai'; keypad.style.display = 'block';
                        if (abaiInput) abaiInput.value = '';
                    } else {
                        label.innerHTML = 'Abai'; keypad.style.display = 'none';
                        if (abaiInput) abaiInput.value = '1';
                    }
                }
            }
            sessionStorage.removeItem('pm_draft_toggles');
        }

        if (draftsStr) {
            const drafts = JSON.parse(draftsStr);
            for (const id in drafts) {
                const inp = document.getElementById(id);
                if (inp) {
                    inp.value = drafts[id];
                    const btn = document.querySelector(`.pm-key-btn[data-target="${id}"][data-val="${drafts[id]}"]`);
                    if (btn) btn.classList.add('active');
                }
            }
            sessionStorage.removeItem('pm_draft_marks');
        }
    }

        // --- PARAMETER MODAL: DEFERRED ADD LOGIC ---
        // Nothing is written to the DB until the judge clicks "Selesai Pemarkahan".
        // The modal only queues the (test, criteria) pair in memory and
        // injects hidden inputs into markForm so save_scores.php can persist
        // them atomically alongside the actual marks.

        let pendingParams = []; // [{testId, testName, criteriaId, criteriaName}]

        // The Peringkat a judge marks is fixed by their assigned group — this
        // modal only ever adds Ujian/Kriteria under currentLevelId, so the
        // Ujian list loads immediately instead of waiting on a Peringkat pick.
        // Cached list of {id, name} options for whichever Ujian is currently
        // selected in the modal — populated once per Ujian pick so each
        // criteria row can be filled without a fresh fetch per row.
        let currentCriteriaOptions = [];

        function openParameterModal() {
            const modal = document.getElementById('modal-parameter');
            modal.style.display = 'flex';
            setTimeout(() => modal.classList.add('show'), 10);

            const testSel = document.getElementById('paramTestSelect');
            const testNew = document.getElementById('paramTestNewInput');

            testSel.innerHTML = '<option value="">Memuatkan...</option>';
            testSel.disabled = true;
            testSel.style.display = '';
            testNew.style.display = 'none';
            testNew.value = '';

            currentCriteriaOptions = [];
            const rowsWrap = document.getElementById('paramCriteriaRows');
            rowsWrap.innerHTML = '';
            document.getElementById('paramAddCriteriaRowBtn').disabled = true;
            addCriteriaRow('-- Pilih Ujian Dahulu --');

            const saveBtn = document.getElementById('paramSaveBtn');
            saveBtn.innerHTML = 'Tambah ke Senarai';
            saveBtn.disabled = false;

            loadTestsForCurrentLevel();
        }

        // Builds one Kriteria row (select of existing criteria + "new name"
        // text input, toggled the same way the Ujian field is) and appends
        // it to #paramCriteriaRows. `placeholderLabel` is shown as the
        // select's disabled first option before any Ujian is chosen.
        function addCriteriaRow(placeholderLabel) {
            const rowsWrap = document.getElementById('paramCriteriaRows');
            const row = document.createElement('div');
            row.className = 'param-criteria-row';
            row.style.cssText = 'display:flex; gap:6px; margin-bottom:6px; align-items:center;';

            const isFirstRow = rowsWrap.children.length === 0;
            const disabled = currentCriteriaOptions.length === 0 && !placeholderLabel;

            row.innerHTML = `
                <select class='paramCriteriaSelect' style='flex:1;' ${(!currentCriteriaOptions.length) ? 'disabled' : ''}>
                    <option value="">${placeholderLabel || 'Memuatkan...'}</option>
                </select>
                <input type='text' class='paramCriteriaNewInput pm-modal-new-input' style='flex:1; display:none;' placeholder='Nama kriteria baru'>
                <button type='button' class='pm-modal-close-btn param-criteria-remove-btn' title='Buang baris ini' style='${isFirstRow ? 'visibility:hidden;' : ''}'>&times;</button>
            `;

            const critSel = row.querySelector('.paramCriteriaSelect');
            const critNew = row.querySelector('.paramCriteriaNewInput');
            populateCriteriaSelect(critSel);

            critSel.addEventListener('change', function() {
                if (this.value === '__new__') {
                    critNew.style.display = '';
                    critNew.focus();
                } else {
                    critNew.style.display = 'none';
                    critNew.value = '';
                }
            });

            row.querySelector('.param-criteria-remove-btn').addEventListener('click', function() {
                if (rowsWrap.children.length > 1) row.remove();
            });

            rowsWrap.appendChild(row);
            return row;
        }

        // Fills a single criteria <select> from the cached currentCriteriaOptions
        // list (plus the "+ Tambah Kriteria Baru…" sentinel), or leaves it in
        // its loading/placeholder state if the list isn't ready yet.
        function populateCriteriaSelect(critSel) {
            if (!currentCriteriaOptions.length) return;
            critSel.innerHTML = '<option value="" disabled selected>-- Pilih Kriteria --</option>';
            currentCriteriaOptions.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = c.name;
                critSel.appendChild(opt);
            });
            const newOpt = document.createElement('option');
            newOpt.value = '__new__';
            newOpt.textContent = '+ Tambah Kriteria Baru…';
            critSel.appendChild(newOpt);
            critSel.disabled = false;
        }

        // Forces every current + future Kriteria row into "type a new name"
        // mode — used when the Ujian itself is new (so it can't have any
        // real Kriteria yet).
        function forceNewCriteriaMode() {
            currentCriteriaOptions = [];
            document.getElementById('paramAddCriteriaRowBtn').disabled = false;
            document.querySelectorAll('#paramCriteriaRows .param-criteria-row').forEach(row => {
                const critSel = row.querySelector('.paramCriteriaSelect');
                const critNew = row.querySelector('.paramCriteriaNewInput');
                critSel.style.display = 'none';
                critSel.disabled = true;
                critNew.style.display = '';
            });
        }

        // Loads the Ujian list for the fixed currentLevelId. A judge can type
        // a brand new Ujian name instead of only picking an existing one —
        // picking "__new__" (or a Peringkat that has zero Ujian yet) reveals
        // a text field instead, which also forces Kriteria into new-entry
        // mode since a not-yet-created Ujian can't have any real Kriteria.
        function loadTestsForCurrentLevel() {
            const testSel = document.getElementById('paramTestSelect');
            testSel.innerHTML = '<option value="">Memuatkan...</option>';
            testSel.disabled = true;
            pmFetch('judge.php?ajax_modal_tests=1&level_id=' + encodeURIComponent(currentLevelId))
                .then(r => r.json())
                .then(data => {
                    testSel.innerHTML = '<option value="" disabled selected>-- Pilih Ujian --</option>';
                    data.forEach(t => {
                        const opt = document.createElement('option');
                        opt.value = t.id;
                        opt.textContent = t.name;
                        testSel.appendChild(opt);
                    });
                    const newOpt = document.createElement('option');
                    newOpt.value = '__new__';
                    newOpt.textContent = '+ Tambah Ujian Baru…';
                    testSel.appendChild(newOpt);
                    testSel.disabled = false;
                    if (data.length === 0) {
                        testSel.value = '__new__';
                        testSel.dispatchEvent(new Event('change'));
                    }
                })
                .catch(() => {});
        }

        // Cascade: Ujian → Kriteria. Resets all Kriteria rows back down to
        // one, then re-fills that row (and caches the option list for any
        // further rows the judge adds via "+ Tambah Kriteria Lain") once the
        // chosen Ujian's existing criteria have loaded.
        document.getElementById('paramTestSelect')?.addEventListener('change', function() {
            const testId = this.value;
            const testNew = document.getElementById('paramTestNewInput');
            const rowsWrap = document.getElementById('paramCriteriaRows');
            const addRowBtn = document.getElementById('paramAddCriteriaRowBtn');

            rowsWrap.innerHTML = '';
            currentCriteriaOptions = [];
            addRowBtn.disabled = true;

            if (testId === '__new__') {
                testNew.style.display = '';
                testNew.focus();
                addCriteriaRow();
                forceNewCriteriaMode();
                return;
            }
            testNew.style.display = 'none';

            if (!testId) {
                addCriteriaRow('-- Pilih Ujian Dahulu --');
                return;
            }

            addCriteriaRow();
            pmFetch('judge.php?ajax_modal_criteria=1&test_id=' + encodeURIComponent(testId))
                .then(r => r.json())
                .then(data => {
                    currentCriteriaOptions = data;
                    document.querySelectorAll('#paramCriteriaRows .paramCriteriaSelect').forEach(populateCriteriaSelect);
                    addRowBtn.disabled = false;
                    if (data.length === 0) {
                        const firstSel = rowsWrap.querySelector('.paramCriteriaSelect');
                        firstSel.value = '__new__';
                        firstSel.dispatchEvent(new Event('change'));
                    }
                })
                .catch(() => {});
        });

        function closeModal(id) {
            const modal = document.getElementById(id);
            modal.classList.remove('show');
            setTimeout(() => modal.style.display = 'none', 200);
        }

        // Resolves each field to a name — either the text of the selected
        // existing option, or whatever the judge typed into that field's
        // "new" input. Peringkat is fixed (currentLevelName), never entered
        // here — an Ujian that's new forces Kriteria into new-entry mode too
        // (see the cascade listener above), which this mirrors.
        function submitNewParameter() {
            const testSel = document.getElementById('paramTestSelect');
            const testNew = document.getElementById('paramTestNewInput');
            const rows = Array.from(document.querySelectorAll('#paramCriteriaRows .param-criteria-row'));

            const levelName = currentLevelName;
            const isNewTest = testSel.value === '__new__';

            let testName;
            if (isNewTest) {
                testName = testNew.value.trim();
                if (!testName) { alert("Sila masukkan nama Ujian baru."); testNew.focus(); return; }
            } else {
                if (!testSel.value) { alert("Sila pilih Ujian terlebih dahulu."); return; }
                testName = testSel.options[testSel.selectedIndex].text;
            }

            // Resolve every Kriteria row to a name first (validating all of
            // them) before queuing anything, so a mistake partway down the
            // list doesn't leave earlier rows already queued.
            const criteriaNames = [];
            for (const row of rows) {
                const critSel = row.querySelector('.paramCriteriaSelect');
                const critNew = row.querySelector('.paramCriteriaNewInput');
                const isNewCriteria = isNewTest || critSel.value === '__new__';

                let criteriaName;
                if (isNewCriteria) {
                    criteriaName = critNew.value.trim();
                    if (!criteriaName) { alert("Sila masukkan nama Kriteria baru untuk setiap baris."); critNew.focus(); return; }
                } else {
                    if (!critSel.value) { alert("Sila pilih Kriteria untuk setiap baris."); return; }
                    criteriaName = critSel.options[critSel.selectedIndex].text;
                }
                if (criteriaNames.includes(criteriaName)) {
                    alert(`Kriteria "${criteriaName}" ditambah lebih daripada sekali dalam senarai ini.`);
                    return;
                }
                criteriaNames.push(criteriaName);
            }

            // Guard: don't add the same combo twice — compared by name since
            // a newly-typed entry has no id yet to compare.
            const dupe = criteriaNames.find(criteriaName => pendingParams.some(
                p => p.levelName === levelName && p.testName === testName && p.criteriaName === criteriaName
            ));
            if (dupe) {
                alert(`Kombinasi "${testName}" → "${dupe}" sudah ditambah.`);
                return;
            }

            const container = document.getElementById('pendingParamsContainer');
            criteriaNames.forEach(criteriaName => {
                // Queue it — nothing touches the DB yet. "key" is what ties
                // this entry's live mark input (pending_marks[key][student_id])
                // back to the real criteria_id that save_scores.php
                // resolves/creates for it on submit (find-by-name, create
                // only if it doesn't already exist — same rule whether the
                // judge picked an existing item or typed a new one).
                const key = 'p' + pendingParams.length;
                const param = { key, sessionId: currentSessionId, levelName, testName, criteriaName };
                pendingParams.push(param);

                // Inject a hidden input into markForm so save_scores.php gets it on submit
                const inp = document.createElement('input');
                inp.type  = 'hidden';
                inp.name  = 'pending_params[]';
                inp.value = JSON.stringify(param);
                container.appendChild(inp);

                // Make it markable right away instead of only after submit+reload
                liveAddCriteriaToUI(param);
            });

            // Show a pending badge so the judge knows what's queued
            refreshPendingBadge();

            closeModal('modal-parameter');
        }

        function refreshPendingBadge() {
            let badge = document.getElementById('pendingParamsBadge');
            if (pendingParams.length === 0) {
                if (badge) badge.remove();
                return;
            }
            if (!badge) {
                badge = document.createElement('div');
                badge.id = 'pendingParamsBadge';
                badge.style.cssText = 'margin-top:10px; padding:8px 12px; background:rgba(234,179,8,0.12); border-left:3px solid #eab308; border-radius:0 6px 6px 0; font-size:0.78rem; color:#b45309;';
                // Insert above the marking table (inside judge-card, before pm-table-wrap)
                const markCard = document.querySelector('#markForm .judge-card');
                if (markCard) markCard.insertBefore(badge, markCard.firstChild);
            }
            const lines = pendingParams.map(p =>
                `• <b>${p.testName}</b> → ${p.criteriaName}`
            ).join('<br>');
            badge.innerHTML = `⏳ <b>${pendingParams.length} parameter tertangguh</b> — akan disimpan apabila anda klik "Selesai Pemarkahan":<br>${lines}<br><span style="font-size:0.72rem;color:#92400e;">Klik Batal untuk membuang semuanya.</span>`;
        }

        // ── LIVE UI INJECTION for a newly-queued criteria ────────────────────
        // "Tambah Ujian & Kriteria" lets a judge either pick an existing
        // Ujian/Kriteria under the fixed Peringkat OR type a brand new one —
        // so there usually isn't a real criteria_id (or even test_id) yet at
        // the moment this runs. Every criteria added through this modal is
        // therefore identified purely by its client-side "key" (param.key,
        // e.g. "p0") on this side, and its markctrl's hidden input is named
        // pending_marks[key][student_id] instead of
        // marks[student_id][criteria_id]. save_scores.php's STEP 1.5
        // resolves each key to a real criteria_id on submit — creating
        // whichever of Ujian/Kriteria don't already exist by that name
        // under the (always-existing) Peringkat, reusing whichever do — and
        // folds pending_marks into the normal save using that mapping.
        // Nothing touches the DB until then.
        //
        // Scope: this live-updates the desktop per-ujian tabbed table
        // (#desktopMarkingTable) so a newly-queued ujian/kriteria is
        // markable immediately, without waiting for submit+reload. Since
        // the Peringkat is always the one currently being marked, it
        // always belongs on this page's table.
        function liveAddCriteriaToUI(param) {
            const { key, testName, criteriaName } = param;

            criteriaByTest[testName] = criteriaByTest[testName] || {};
            if (criteriaByTest[testName][key]) {
                // Already live (added earlier this session) — just jump to its tab.
                switchToDesktopUjianTabByName(testName);
                return;
            }
            criteriaByTest[testName][key] = criteriaName;

            liveAddCriteriaToDesktopTable(testName, key, criteriaName);

            getPresentStudents().forEach(s => updateTotals(s.sid));
            switchToDesktopUjianTabByName(testName);
        }

        // Adds a new kriteria column to an existing ujian's table, or a
        // whole new tab + table for a brand new ujian.
        function liveAddCriteriaToDesktopTable(testName, key, criteriaName) {
            const tabsContainer = document.getElementById('desktopUjianTabsContainer');
            const desktopUI = document.getElementById('desktopMarkingTable');
            if (!tabsContainer || !desktopUI) return; // Peringkat started with zero criteria — falls back to reload-after-submit.

            let panel = desktopUI.querySelector(`.desktop-ujian-tab-panel[data-test-name="${cssEscape(testName)}"]`);

            if (panel) {
                // Existing ujian — add a new <th> + one <td> markctrl per present student row.
                const table = panel.querySelector('table.marking-table');
                if (!table) return;
                const headRow = table.querySelector('thead tr');
                const jumlahTh = headRow.querySelector(`th[data-test="${cssEscape(testName)}"]`);
                const newTh = document.createElement('th');
                newTh.textContent = criteriaName;
                headRow.insertBefore(newTh, jumlahTh);

                table.querySelectorAll('tbody tr[data-student]').forEach(tr => {
                    const sid = tr.dataset.student;
                    const testTotalTd = tr.querySelector(`.test-total[data-test="${cssEscape(testName)}"]`);
                    const newTd = document.createElement('td');
                    newTd.className = 'mark-cell';
                    newTd.id = `desktopSlot_${sid}_${key}`;
                    newTd.style.textAlign = 'center';
                    newTd.innerHTML = buildMarkctrlHtml(sid, key);
                    tr.insertBefore(newTd, testTotalTd);
                });
            } else {
                // Brand new ujian — new tab button + a fresh single-ujian table.
                const newTabIndex = tabsContainer.querySelectorAll('.desktop-ujian-tab-btn').length;

                const tabBtn = document.createElement('button');
                tabBtn.type = 'button';
                tabBtn.className = 'desktop-ujian-tab-btn';
                tabBtn.dataset.tabIndex = newTabIndex;
                tabBtn.dataset.testName = testName;
                tabBtn.textContent = testName;
                tabsContainer.appendChild(tabBtn);

                panel = document.createElement('div');
                panel.className = 'desktop-ujian-tab-panel';
                panel.dataset.tabIndex = newTabIndex;
                panel.dataset.testName = testName;
                panel.style.display = 'none';

                const wrap = document.createElement('div');
                wrap.className = 'pm-table-wrap';
                wrap.style.cssText = 'border:none;margin:0;border-radius:0;';
                const table = document.createElement('table');
                table.className = 'pm-table marking-table';
                table.dataset.testName = testName;

                const thead = document.createElement('thead');
                const headRow = document.createElement('tr');
                headRow.innerHTML = `<th class='sticky-col' style='background: var(--c-surface-2); z-index: 15;'>Pelajar</th>
                    <th>${escapeHtml(criteriaName)}</th>
                    <th data-test='${escapeHtml(testName)}' style='background: var(--c-surface-3); color: var(--c-text); text-align: center;'>Jumlah ${escapeHtml(testName)}</th>
                    <th style='background: var(--c-surface-3); color: var(--c-text); text-align: center;'>Jumlah Keseluruhan</th>`;
                thead.appendChild(headRow);
                table.appendChild(thead);

                const tbody = document.createElement('tbody');
                getPresentStudents().forEach(s => {
                    const tr = document.createElement('tr');
                    tr.dataset.student = s.sid;
                    tr.innerHTML = `<td class='sticky-col'>${escapeHtml(s.name)}</td>
                        <td class='mark-cell' id='desktopSlot_${s.sid}_${key}' style='text-align:center;'>${buildMarkctrlHtml(s.sid, key)}</td>
                        <td class='test-total' data-student='${s.sid}' data-test='${escapeHtml(testName)}' style='background:var(--c-surface-2);color:var(--c-text);font-weight:700;text-align:center;'>0 / 0</td>
                        <td class='overall-total' data-student='${s.sid}' style='background:var(--c-surface-1);color:var(--c-text);font-weight:700;text-align:center;'>0 / 0</td>`;
                    tbody.appendChild(tr);
                });
                table.appendChild(tbody);
                wrap.appendChild(table);
                panel.appendChild(wrap);
                desktopUI.appendChild(panel);

                const alert = desktopUI.querySelector('.pm-alert-warn');
                if (alert) alert.remove();
            }
        }

        // `cid` here is sometimes a client-side pending key (e.g. "p0")
        // rather than a real criteria_id, for modal-added criteria. The
        // hidden input's NAME (what actually gets submitted) is
        // pending_marks[key][sid], resolved to a real criteria_id
        // server-side in save_scores.php; its id/data-criteria stay keyed
        // by the same string throughout so every other piece of JS
        // (updateTotals, validation) can address it exactly like a normal
        // criteria without special-casing.
        function buildMarkctrlHtml(sid, cid) {
            const keys = [1, 2, 3, 4, 5, 6, 7, 8, 9, 'X', 0, 10];
            const keyBtns = keys.map(k => {
                const val = k === 'X' ? '' : k;
                const displayK = k === 'X' ? '✖' : k;
                const btnClass = k === 'X' ? 'pm-key-btn clear-btn' : 'pm-key-btn';
                return `<button type='button' class='${btnClass}' data-target='mark_${sid}_${cid}' data-val='${val}' data-student='${sid}' data-criteria='${cid}'>${displayK}</button>`;
            }).join('');
            return `<div class='markctrl' id='markctrl_${sid}_${cid}' data-student='${sid}' data-criteria='${cid}'>
                <label class='pm-nilai-wrap'>
                    <input type='checkbox' class='pm-nilai-checkbox' data-student='${sid}' data-criteria='${cid}' checked>
                    <div class='pm-toggle-switch'></div>
                    <span class='pm-toggle-label'>Dinilai</span>
                </label>
                <div class='keypad-wrapper' id='keypad_${sid}_${cid}' style='display:block;'>
                    <input type='hidden' id='mark_${sid}_${cid}' name='pending_marks[${cid}][${sid}]' value=''>
                    <input type='hidden' id='abai_${sid}_${cid}' name='pending_abai[${cid}][${sid}]' value=''>
                    <div class='pm-keypad'>${keyBtns}</div>
                </div>
            </div>`;
        }

        function switchToDesktopUjianTabByName(testName) {
            const btn = document.querySelector(`.desktop-ujian-tab-btn[data-test-name="${cssEscape(testName)}"]`);
            if (btn) btn.click();
        }

        // Present students, deduped by student id — read from the desktop
        // table (present-student rows carry data-student; absent rows don't).
        function getPresentStudents() {
            const seen = new Set();
            const list = [];
            document.querySelectorAll('#desktopMarkingTable tr[data-student]').forEach(tr => {
                const sid = tr.dataset.student;
                if (seen.has(sid)) return;
                seen.add(sid);
                const nameEl = tr.querySelector('.sticky-col');
                list.push({ sid, name: nameEl ? nameEl.textContent : '' });
            });
            return list;
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        // Escapes for use inside a quoted CSS attribute-selector string, e.g.
        // `[data-test-name="${cssEscape(name)}"]` — NOT CSS.escape(), which
        // is for bare identifiers (class/id names) and over-escapes spaces
        // and other characters that are perfectly fine inside a quoted
        // string, which test names routinely contain.
        function cssEscape(str) {
            return String(str).replace(/["\\]/g, '\\$&');
        }

        // ── REQUIRE A MARK FOR EVERY "DINILAI" CRITERIA BEFORE SUBMIT ────────
        // A criteria left checked (Dinilai) but with no number actually
        // tapped would otherwise silently save as if it were worth 0 — this
        // blocks "Selesai Pemarkahan" and shows exactly which ones are
        // missing until the judge either enters a mark or explicitly flips
        // it to Abai (an intentional exclusion, which is allowed through).
        function findMissingMarks() {
            const studentNames = {};
            getPresentStudents().forEach(s => { studentNames[s.sid] = s.name; });

            const missing = [];
            document.querySelectorAll('.markctrl').forEach(ctrl => {
                const sid = ctrl.dataset.student;
                const cid = ctrl.dataset.criteria;
                const cb  = ctrl.querySelector('.pm-nilai-checkbox');
                const inp = ctrl.querySelector('input[type="hidden"]');
                if (!cb || !inp) return;
                if (cb.checked && inp.value === '') {
                    let criteriaName = cid, testName = '';
                    for (const t in criteriaByTest) {
                        if (criteriaByTest[t][cid]) { criteriaName = criteriaByTest[t][cid]; testName = t; break; }
                    }
                    missing.push({
                        sid,
                        studentName: studentNames[sid] || ('Pelajar #' + sid),
                        testName,
                        criteriaName,
                    });
                }
            });
            return missing;
        }

        function showMissingMarksModal(missing) {
            const list = document.getElementById('missingMarksList');
            list.innerHTML = missing.map((m, i) => `
                <button type='button' class='pm-btn pm-btn-ghost' style='justify-content:flex-start; text-align:left; font-size:0.8rem; padding:8px 10px;'
                    onclick="jumpToCriteria(${JSON.stringify(m.testName)}, '${m.sid}')">
                    <b>${escapeHtml(m.studentName)}</b> — ${escapeHtml(m.testName)} → ${escapeHtml(m.criteriaName)}
                </button>
            `).join('');

            const modal = document.getElementById('modal-missing-marks');
            modal.style.display = 'flex';
            setTimeout(() => modal.classList.add('show'), 10);
        }

        function jumpToCriteria(testName, sid) {
            closeModal('modal-missing-marks');
            switchToDesktopUjianTabByName(testName);
            const panel = document.querySelector(`.desktop-ujian-tab-panel[data-test-name="${cssEscape(testName)}"]`);
            const row = panel && panel.querySelector(`tr[data-student="${sid}"]`);
            if (!row) return;
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            const cell = row.querySelector('.sticky-col');
            if (cell) {
                cell.classList.add('flash-highlight');
                setTimeout(() => cell.classList.remove('flash-highlight'), 1100);
            }
        }

        document.getElementById('markForm')?.addEventListener('submit', function (e) {
            const missing = findMissingMarks();
            if (missing.length > 0) {
                e.preventDefault();
                showMissingMarksModal(missing);
            }
        });

    // Initialize Page
    document.addEventListener('DOMContentLoaded', function () {
        const pos = localStorage.getItem('judge_scroll_pos');
        if (pos) { window.scrollTo(0, parseInt(pos)); localStorage.removeItem('judge_scroll_pos'); }
        document.querySelectorAll('form').forEach(f => {
            f.addEventListener('submit', () => localStorage.setItem('judge_scroll_pos', window.scrollY));
        });

        restoreDraftMarks(); // Restore marks if a criteria was just added!

        const students = [...new Set(Array.from(document.querySelectorAll('.pm-key-btn')).map(b => b.dataset.student))];
        students.forEach(id => updateTotals(id));

        // Guarantee all accordions start collapsed
        document.querySelectorAll('.pm-accordion-content').forEach(c => c.style.display = 'none');
        document.querySelectorAll('.pm-accordion-arrow').forEach(a => a.style.transform = 'rotate(0deg)');
        document.querySelectorAll('.pm-accordion-header').forEach(h => h.classList.remove('active'));
    });
</script>

</main>
</body>

</html>