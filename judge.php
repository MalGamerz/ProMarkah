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
// Small-form-dropdown AJAX endpoints (ajax_levels, ajax_groups,
// ajax_modal_tests, ajax_modal_criteria, ajax_sessions) — extracted to
// judge_ajax.php since each is self-contained and exit()s; that file
// requires $conn/$judge_id, both already set above. Exits if one of those
// GET params is present; otherwise falls through to the full page render
// below. The URL stays judge.php?ajax_levels=1&... either way.
require __DIR__ . '/judge_ajax.php';

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

// Cache-bust the same way layout.php does for its own stylesheets, so
// edits to judge.css take effect immediately instead of waiting out the
// browser's cache.
$pm_judge_css_v = @filemtime(__DIR__ . '/judge.css') ?: time();
?>
<link rel="stylesheet" href="judge.css?v=<?= $pm_judge_css_v ?>">
<?php /* ─────────────────────────────────────────────────
   JUDGE PAGE — REDESIGN styles moved to judge.css (~900 lines, had
   nothing to do with the PHP rendering logic around it).
───────────────────────────────────────────────── */ ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

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
<?php endif; ?>
<!-- URL query-param cleanup (was here inline) now runs unconditionally in
     judge_dashboard.js — harmless when there's nothing to clean up. -->

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
                        echo "<div class='pm-accordion-header' onclick=\"toggleSummaryAccordion('{$sidHash}')\" id='header_{$sidHash}' role='button' tabindex='0'>";
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
                        // first — see the matching ajax_levels endpoint in
                        // judge_ajax.php for why.
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
                        // Mirrors the ajax_groups endpoint in judge_ajax.php
                        // (same labeling/disabling logic) — this is the path used
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

<?php
// Bootstrap data for judge_dashboard.js — exposed via window.* instead of
// interpolating PHP directly into that now-static file.
$pm_judge_dashboard_js_v = @filemtime(__DIR__ . '/judge_dashboard.js') ?: time();
?>
<script>
    window.pmJudgeDashboardData = {
        showYearDropdown: <?= $show_year_dropdown ? 'true' : 'false' ?>,
        showSiriDropdown: <?= $show_siri_dropdown ? 'true' : 'false' ?>
    };
</script>
<script src="judge_dashboard.js?v=<?= $pm_judge_dashboard_js_v ?>"></script>

<?php // ── MARKING LOGIC ──────────────────────────────────────────────────────

if ($marking_active && $current_session && $current_group) {
    $session_id = (int) $current_session;
    $group_id = (int) $current_group;

    // Three distinct "judge id" variables follow — easy to conflate, so:
    //   $judge_id        (set near the top of this file) — whoever is
    //                     actually logged in and viewing this page right now.
    //   $group_judge_id  — whoever the group is ASSIGNED to (`groups.judge_id`),
    //                     i.e. who's supposed to mark it.
    //   $marker_judge_id — whoever ACTUALLY recorded scores for this group
    //                     (`scores.judge_id` on an existing row, if any),
    //                     which can differ from $group_judge_id if the
    //                     assignment changed after marking started. This is
    //                     the one used for the ownership/lock checks below,
    //                     since it reflects who really has marks on record.

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

        $render_mark_cell = function ($sid, $cid) use ($existing_scores, $existing_status, $is_locked) {
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
                            echo "<td class='mark-cell' id='desktopSlot_{$sid}_{$cid}' style='text-align: center;'>" . $render_mark_cell($sid, $cid) . "</td>";
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
            /* Background/border/radius/margin/box-shadow/transform all come
               from dashboard.css's shared .pm-modal-box now. Only these two
               are genuinely this page's own: padding is 0 because the
               header/body/actions bars below manage their own spacing, and
               overflow is clipped so .pm-modal-header's background respects
               the box's rounded corners. Width is set via the
               .pm-modal-box--lg modifier on modal-missing-marks below (md,
               the shared default, is used for modal-parameter). */
            .pm-modal-box { padding: 0; overflow: hidden; }
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
            <div class='pm-modal-box pm-modal-box--lg'>
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

<?php
// Bootstrap data for judge_marking.js — exposed via window.* instead of
// interpolating PHP directly into that now-static file.
$pm_judge_marking_js_v = @filemtime(__DIR__ . '/judge_marking.js') ?: time();
?>
<script>
    window.pmJudgeMarkingData = {
        criteriaByTest: <?= json_encode($criteria_by_test ?? []) ?>,
        testIdsMap: <?= json_encode($test_ids_map ?? []) ?>,
        currentLevelId: <?= $level_id ?? 0 ?>,
        currentLevelName: <?= json_encode($current_level_name ?? "") ?>,
        currentSessionId: <?= $session_id ?? 0 ?>
    };
</script>
<script src="judge_marking.js?v=<?= $pm_judge_marking_js_v ?>"></script>

</main>
</body>

</html>