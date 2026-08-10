<?php
session_start();
require __DIR__ . '/auth_check.php';
include 'db.php';
$conn = getDB();

// ✅ ONLY JUDGE CAN ACCESS
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'judge') {
    header("Location: login.php");
    exit();
}

// ── CLEAN URL: strip empty params so URL stays tidy ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET)) {
    $clean = array_filter($_GET, fn($v) => $v !== '' && $v !== '0' && $v !== null);
    $current_query = http_build_query($_GET);
    $clean_query   = http_build_query($clean);
    if ($current_query !== $clean_query) {
        $base = strtok($_SERVER['REQUEST_URI'], '?');
        header('Location: ' . $base . ($clean_query ? '?' . $clean_query : ''));
        exit();
    }
}

// ✅ Set active page for the sidebar navigation
$pm_page = 'view_marks';
include 'layout.php';

// ── GET FILTERS ─────────────────────────────────────────────────────────────
$filter_siri     = (int)($_GET['siri_id'] ?? 0);
$filter_year     = $_GET['year'] ?? '';
$filter_session  = (int)($_GET['session_id'] ?? 0);
$filter_group    = (int)($_GET['group_id'] ?? 0);
$filter_student  = (int)($_GET['student_id'] ?? 0);
$filter_test     = (int)($_GET['test_id'] ?? 0);
$filter_criteria = (int)($_GET['criteria_id'] ?? 0);
// Sort-by-name toggle — same cycle as pic_master_list.php ('' -> ASC -> DESC
// -> ''). This page reloads via a plain GET form (no AJAX), so the toggle is
// a hidden form field flipped by JS then submitted, mirroring how the
// existing filter <select>s auto-submit via this.form.submit().
$sort_name = in_array($_GET['sort_name'] ?? '', ['ASC', 'DESC'], true) ? $_GET['sort_name'] : '';

// SMART FILTER: If a ujian (test) is selected, it auto-narrows kriteria.
// If a kriteria is selected, auto-derive its parent test for the query.
// If a siri is selected, auto-narrow sessions.
if ($filter_criteria > 0 && $filter_test === 0) {
    $c_stmt = $conn->prepare("SELECT test_id FROM criteria WHERE criteria_id = ?");
    $c_stmt->bind_param("i", $filter_criteria);
    $c_stmt->execute();
    $c_res = $c_stmt->get_result();
    if ($c_res && $c_res->num_rows > 0) {
        $filter_test = (int)$c_res->fetch_assoc()['test_id'];
    }
    $c_stmt->close();
}

// ── FETCH DYNAMIC DROPDOWN LISTS (cascading) ────────────────────────────────
// Hierarchy: Siri → Tahun → Sidang → Kumpulan → Pelajar → Kriteria → Ujian

// SIRI — always full list
$siris = $conn->query("SELECT siri_id, siri_name, siri_year FROM siri ORDER BY siri_year DESC, siri_name ASC");

// TAHUN — filtered by siri if set
if ($filter_siri > 0) {
    $yr_stmt = $conn->prepare("SELECT DISTINCT s.year FROM students s JOIN group_students gs ON s.student_id = gs.student_id JOIN `groups` g ON g.group_id = gs.group_id JOIN levels gl ON g.level_id = gl.level_id JOIN sessions se ON se.session_id = gl.session_id WHERE se.siri_id = ? ORDER BY s.year DESC");
    $yr_stmt->bind_param("i", $filter_siri);
    $yr_stmt->execute();
    $years = $yr_stmt->get_result();
} else {
    $years = $conn->query("SELECT DISTINCT year FROM students ORDER BY year DESC");
}

// SIDANG — filtered by siri and/or year
if ($filter_siri > 0 && $filter_year !== '') {
    $ses_stmt = $conn->prepare("SELECT DISTINCT se.session_id, se.session_name FROM sessions se JOIN levels gl ON gl.session_id = se.session_id JOIN `groups` g ON g.level_id = gl.level_id JOIN group_students gs ON gs.group_id = g.group_id JOIN students s ON s.student_id = gs.student_id WHERE se.siri_id = ? AND s.year = ? ORDER BY se.session_name ASC");
    $ses_stmt->bind_param("is", $filter_siri, $filter_year);
    $ses_stmt->execute();
    $sessions = $ses_stmt->get_result();
} elseif ($filter_siri > 0) {
    $ses_stmt = $conn->prepare("SELECT session_id, session_name FROM sessions WHERE siri_id = ? ORDER BY session_name ASC");
    $ses_stmt->bind_param("i", $filter_siri);
    $ses_stmt->execute();
    $sessions = $ses_stmt->get_result();
} elseif ($filter_year !== '') {
    $ses_stmt = $conn->prepare("SELECT DISTINCT se.session_id, se.session_name FROM sessions se JOIN levels gl ON gl.session_id = se.session_id JOIN `groups` g ON g.level_id = gl.level_id JOIN group_students gs ON gs.group_id = g.group_id JOIN students s ON s.student_id = gs.student_id WHERE s.year = ? ORDER BY se.session_name ASC");
    $ses_stmt->bind_param("s", $filter_year);
    $ses_stmt->execute();
    $sessions = $ses_stmt->get_result();
} else {
    $sessions = $conn->query("SELECT session_id, session_name FROM sessions ORDER BY session_name ASC");
}

// KUMPULAN — filtered by session and/or year and/or siri
if ($filter_session > 0 && $filter_year !== '') {
    $grp_stmt = $conn->prepare("SELECT DISTINCT g.group_id, g.group_name FROM `groups` g JOIN levels gl ON g.level_id = gl.level_id JOIN group_students gs ON gs.group_id = g.group_id JOIN students s ON s.student_id = gs.student_id WHERE gl.session_id = ? AND s.year = ? ORDER BY g.group_name ASC");
    $grp_stmt->bind_param("is", $filter_session, $filter_year);
    $grp_stmt->execute();
    $groups = $grp_stmt->get_result();
} elseif ($filter_session > 0) {
    $grp_stmt = $conn->prepare("SELECT g.group_id, g.group_name FROM `groups` g JOIN levels gl ON g.level_id = gl.level_id WHERE gl.session_id = ? ORDER BY g.group_name ASC");
    $grp_stmt->bind_param("i", $filter_session);
    $grp_stmt->execute();
    $groups = $grp_stmt->get_result();
} elseif ($filter_siri > 0 && $filter_year !== '') {
    $grp_stmt = $conn->prepare("SELECT DISTINCT g.group_id, g.group_name FROM `groups` g JOIN levels gl ON g.level_id = gl.level_id JOIN sessions se ON se.session_id = gl.session_id JOIN group_students gs ON gs.group_id = g.group_id JOIN students s ON s.student_id = gs.student_id WHERE se.siri_id = ? AND s.year = ? ORDER BY g.group_name ASC");
    $grp_stmt->bind_param("is", $filter_siri, $filter_year);
    $grp_stmt->execute();
    $groups = $grp_stmt->get_result();
} elseif ($filter_siri > 0) {
    $grp_stmt = $conn->prepare("SELECT DISTINCT g.group_id, g.group_name FROM `groups` g JOIN levels gl ON g.level_id = gl.level_id JOIN sessions se ON se.session_id = gl.session_id WHERE se.siri_id = ? ORDER BY g.group_name ASC");
    $grp_stmt->bind_param("i", $filter_siri);
    $grp_stmt->execute();
    $groups = $grp_stmt->get_result();
} elseif ($filter_year !== '') {
    $grp_stmt = $conn->prepare("SELECT DISTINCT g.group_id, g.group_name FROM `groups` g JOIN group_students gs ON gs.group_id = g.group_id JOIN students s ON s.student_id = gs.student_id WHERE s.year = ? ORDER BY g.group_name ASC");
    $grp_stmt->bind_param("s", $filter_year);
    $grp_stmt->execute();
    $groups = $grp_stmt->get_result();
} else {
    $groups = $conn->query("SELECT group_id, group_name FROM `groups` ORDER BY group_name ASC");
}

// PELAJAR — filtered by group, session, siri, and/or year
$stu_where = []; $stu_params = []; $stu_types = '';
if ($filter_group > 0)   { $stu_where[] = "gs.group_id = ?";    $stu_params[] = $filter_group;   $stu_types .= 'i'; }
if ($filter_year !== '')  { $stu_where[] = "s.year = ?";         $stu_params[] = $filter_year;    $stu_types .= 's'; }
if ($filter_session > 0) { $stu_where[] = "gl.session_id = ?";  $stu_params[] = $filter_session; $stu_types .= 'i'; }
if ($filter_siri > 0)    { $stu_where[] = "se.siri_id = ?";     $stu_params[] = $filter_siri;    $stu_types .= 'i'; }
$stu_where_sql = $stu_where ? "WHERE " . implode(" AND ", $stu_where) : "";
$stu_sql = "SELECT DISTINCT s.student_id, s.student_name FROM students s JOIN group_students gs ON s.student_id = gs.student_id JOIN `groups` g ON g.group_id = gs.group_id JOIN levels gl ON g.level_id = gl.level_id JOIN sessions se ON se.session_id = gl.session_id $stu_where_sql ORDER BY s.student_name ASC";
if ($stu_params) {
    $stu_stmt = $conn->prepare($stu_sql);
    $stu_stmt->bind_param($stu_types, ...$stu_params);
    $stu_stmt->execute();
    $students = $stu_stmt->get_result();
} else {
    $students = $conn->query($stu_sql);
}

// KRITERIA — filtered by student, group, session, siri, year if set
$krit_where = []; $krit_params = []; $krit_types = '';
if ($filter_student > 0) { $krit_where[] = "sc.student_id = ?"; $krit_params[] = $filter_student; $krit_types .= 'i'; }
if ($filter_group > 0)   { $krit_where[] = "sc.group_id = ?";   $krit_params[] = $filter_group;   $krit_types .= 'i'; }
if ($filter_session > 0) { $krit_where[] = "gl.session_id = ?"; $krit_params[] = $filter_session;  $krit_types .= 'i'; }
if ($filter_siri > 0)    { $krit_where[] = "se.siri_id = ?";    $krit_params[] = $filter_siri;     $krit_types .= 'i'; }
if ($filter_year !== '')  { $krit_where[] = "s.year = ?";        $krit_params[] = $filter_year;     $krit_types .= 's'; }
$krit_where_sql = $krit_where ? "WHERE " . implode(" AND ", $krit_where) : "";
$krit_sql = "SELECT DISTINCT c.criteria_id, c.criteria_name FROM criteria c JOIN scores sc ON sc.criteria_id = c.criteria_id JOIN students s ON s.student_id = sc.student_id JOIN `groups` g ON g.group_id = sc.group_id JOIN levels gl ON g.level_id = gl.level_id JOIN sessions se ON se.session_id = gl.session_id $krit_where_sql ORDER BY c.criteria_name ASC";
if ($krit_params) {
    $krit_stmt = $conn->prepare($krit_sql);
    $krit_stmt->bind_param($krit_types, ...$krit_params);
    $krit_stmt->execute();
    $criteria_list = $krit_stmt->get_result();
} else {
    $criteria_list = $conn->query($krit_sql);
}

// UJIAN — filtered by criteria and everything above it
$tst_where = []; $tst_params = []; $tst_types = '';
if ($filter_criteria > 0) { $tst_where[] = "sc.criteria_id = ?"; $tst_params[] = $filter_criteria; $tst_types .= 'i'; }
if ($filter_student > 0)  { $tst_where[] = "sc.student_id = ?";  $tst_params[] = $filter_student;  $tst_types .= 'i'; }
if ($filter_group > 0)    { $tst_where[] = "sc.group_id = ?";    $tst_params[] = $filter_group;    $tst_types .= 'i'; }
if ($filter_session > 0)  { $tst_where[] = "gl.session_id = ?";  $tst_params[] = $filter_session;  $tst_types .= 'i'; }
if ($filter_siri > 0)     { $tst_where[] = "se.siri_id = ?";     $tst_params[] = $filter_siri;     $tst_types .= 'i'; }
if ($filter_year !== '')   { $tst_where[] = "s.year = ?";         $tst_params[] = $filter_year;     $tst_types .= 's'; }
$tst_where_sql = $tst_where ? "WHERE " . implode(" AND ", $tst_where) : "";
// scores no longer stores test_id directly (derived via criteria_id ->
// criteria.test_id), so join tests through criteria instead of scores.
$tst_sql = "SELECT DISTINCT t.test_id, t.test_name FROM tests t JOIN criteria tc ON tc.test_id = t.test_id JOIN scores sc ON sc.criteria_id = tc.criteria_id JOIN students s ON s.student_id = sc.student_id JOIN `groups` g ON g.group_id = sc.group_id JOIN levels gl ON g.level_id = gl.level_id JOIN sessions se ON se.session_id = gl.session_id $tst_where_sql ORDER BY t.test_name ASC";
if ($tst_params) {
    $tst_stmt = $conn->prepare($tst_sql);
    $tst_stmt->bind_param($tst_types, ...$tst_params);
    $tst_stmt->execute();
    $tests = $tst_stmt->get_result();
} else {
    $tests = $conn->query($tst_sql);
}

// ── BUILD MAIN QUERY ────────────────────────────────────────────────────────
$where  = [];
$params = [];
$types  = '';

if ($filter_siri > 0)    { $where[] = "se.siri_id = ?";     $params[] = $filter_siri;     $types .= 'i'; }
if ($filter_session > 0)  { $where[] = "gl.session_id = ?"; $params[] = $filter_session; $types .= 'i'; }
if ($filter_year !== '')  { $where[] = "s.year = ?";         $params[] = $filter_year;    $types .= 's'; }
if ($filter_group > 0)   { $where[] = "sc.group_id = ?";    $params[] = $filter_group;   $types .= 'i'; }
if ($filter_student > 0) { $where[] = "sc.student_id = ?";  $params[] = $filter_student; $types .= 'i'; }
if ($filter_test > 0)    { $where[] = "c.test_id = ?";      $params[] = $filter_test;    $types .= 'i'; }
if ($filter_criteria > 0){ $where[] = "sc.criteria_id = ?"; $params[] = $filter_criteria;$types .= 'i'; }

$where_sql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

$sql = "
SELECT
    si.siri_name,
    se.session_name,
    g.group_name,
    s.student_name,
    s.year,
    t.test_name,
    c.criteria_name,
    sc.mark
FROM scores sc
JOIN students s ON sc.student_id = s.student_id
JOIN `groups` g ON sc.group_id = g.group_id
JOIN levels gl ON g.level_id = gl.level_id
JOIN sessions se ON gl.session_id = se.session_id
LEFT JOIN siri si ON se.siri_id = si.siri_id
JOIN criteria c ON sc.criteria_id = c.criteria_id
JOIN tests t ON c.test_id = t.test_id
$where_sql
ORDER BY si.siri_name, se.session_name, g.group_name, " . ($sort_name === 'ASC' ? "s.student_name ASC" : ($sort_name === 'DESC' ? "s.student_name DESC" : "s.year DESC, s.student_id")) . ", c.criteria_name, t.test_name
";

$main_stmt = $conn->prepare($sql);
if ($params) {
    $main_stmt->bind_param($types, ...$params);
}
$main_stmt->execute();
$result = $main_stmt->get_result();

// ── GROUP DATA FOR ROWSPAN ──────────────────────────────────────────────────
$marks = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $group_key   = ($row['siri_name'] ?? '') . '|||' . $row['session_name'] . '|||' . $row['group_name'];
        $student_key = $row['student_name'] . '|||' . $row['year'];
        $marks[$group_key][$student_key][$row['criteria_name']][] = [
            'test' => $row['test_name'],
            'mark' => $row['mark']
        ];
    }
}
$main_stmt->close();
?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
/* ── SELECT2 appearance now comes from the shared filter_bar.css, loaded
   via layout.php, pinned to the same 32px/0.8rem benchmark as
   pic_view_marks.php / leaderboard.php / silibus.php — this page's local
   copy had drifted to 42px before that got centralized. ── */

/* ── FILTER GRID ──
   Flexbox + wrap + justify-content:center instead of CSS Grid: with
   auto-fit grid columns, a partial last row (e.g. 7 fields wrapping to
   6+1) leaves the lone leftover item stuck in the first column with empty
   space beside it — grid rows share column tracks, so they can't
   individually recenter. Flexbox wraps line-by-line and centers each
   line's items independently, so this reads as centered at any field
   count and at every viewport width without separate breakpoints per
   screen size. */
.filter-grid {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 14px;
    margin-bottom: 22px;
}
.filter-col {
    display: flex;
    flex-direction: column;
    gap: 5px;
    flex: 1 1 0; /* Equal basis so every column grows to the same width, same as leaderboard.php's .filter-col — the old flex:1 1 180px + max-width:220px let some columns hit the cap while others kept growing, producing visibly uneven widths */
    min-width: 110px; /* Prevents Select2 from getting completely crushed, matching the reference pattern */
}
.filter-col label {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--c-text-faint);
}

/* ── MARKS TABLE ── */
.view-marks-table {
    min-width: 900px;
    table-layout: auto;
    border-collapse: collapse;
    width: 100%;
}
.view-marks-table th {
    position: sticky;
    top: 0;
    z-index: 10;
    background: var(--c-surface-2) !important;
    color: var(--c-text-muted);
    padding: 8px 12px !important;
    font-size: 0.75rem !important;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    text-align: left;
    white-space: nowrap;
    border-bottom: 2px solid var(--c-red);
    border-right: 1px solid var(--c-border);
}
.view-marks-table td {
    padding: 8px 12px !important;
    vertical-align: middle !important;
    border-bottom: 1px solid var(--c-border);
    border-right: 1px solid var(--c-border);
    color: var(--c-text);
    font-size: 0.85rem;
}

/* ── COLUMN ROLES — use CSS vars so light/dark both work ── */
.col-group {
    font-weight: 700;
    color: var(--c-red) !important;
    background: var(--c-surface-2) !important;
    border-right: 2px solid var(--c-border-strong) !important;
}
.col-group .col-siri-label {
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: var(--c-red);
    display: block;
    margin-bottom: 2px;
    opacity: 0.85;
}
.col-group .col-session-label {
    font-size: 0.72rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--c-text-faint);
    display: block;
    margin-bottom: 3px;
}
.col-student {
    font-weight: 600;
    font-size: 0.9rem;
    color: var(--c-text) !important;
    background: var(--vm-student-bg, var(--c-surface-1)) !important;
    border-right: 1px solid var(--c-border-strong) !important;
}
.col-student .col-year-label {
    font-size: 0.78rem;
    color: var(--c-text-faint);
    display: block;
    margin-top: 3px;
}
.col-student .col-year-val {
    color: var(--c-red);
    font-weight: 700;
}
.col-criteria {
    color: var(--c-text-muted) !important;
    background: var(--c-surface-1) !important;
    font-size: 0.85rem;
    border-right: 1px solid var(--c-border) !important;
}
.col-test {
    color: var(--c-text-faint) !important;
    font-size: 0.85rem;
}
.col-mark {
    font-family: 'DM Mono', monospace;
    font-size: 0.95rem !important;
    font-weight: 700;
    color: var(--c-text) !important;
    text-align: center !important;
    background: var(--c-surface-0) !important;
    letter-spacing: 0.04em;
}

/* Row hover — consistent wash */
.view-marks-table tbody tr:hover td { background: var(--c-red-dim) !important; }
.view-marks-table tbody tr:hover td.col-group    { background: var(--c-surface-2) !important; }
.view-marks-table tbody tr:hover td.col-student  { background: var(--c-surface-1) !important; }
.view-marks-table tbody tr:hover td.col-criteria { background: var(--c-surface-1) !important; }
.view-marks-table tbody tr:hover td.col-mark     { background: var(--c-red-dim)   !important; }

/* Empty state row */
.vm-empty-row td {
    text-align: center;
    padding: 48px !important;
    color: var(--c-text-faint);
    font-style: italic;
}

/* ── PAGINATION ── */
.pagination-container {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    padding: 12px 20px;
    background: var(--c-surface-2);
    border-top: 1px solid var(--c-border);
    gap: 16px;
}
.pagination-left {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.pagination-info {
    font-size: 0.85rem;
    color: var(--c-text-muted);
    font-weight: 600;
}
.pagination-controls {
    display: flex;
    gap: 6px;
}
.page-btn {
    padding: 4px 12px;
    font-size: 0.85rem;
    border: 1px solid var(--c-border-strong);
    background: var(--c-surface-1);
    color: var(--c-text);
    border-radius: 4px;
    cursor: pointer;
    font-weight: 600;
    transition: 0.2s ease;
}
.page-btn:hover:not(:disabled) {
    background: var(--c-surface-3);
    border-color: var(--c-text-muted);
}
.page-btn.active {
    background: var(--c-red);
    color: #fff;
    border-color: var(--c-red);
}
.page-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
@media (max-width: 600px) {
    .pagination-container { flex-direction: column; justify-content: center; text-align: center; }
    .pagination-left { justify-content: center; }
    .pagination-controls { width: 100%; justify-content: center; flex-wrap: wrap; }
    .jvm-table-scroll { max-height: none !important; overflow-y: visible !important; }
}
</style>

<h2 class="pm-page-heading">📊 Senarai Markah Lengkap</h2>

<div class="pm-card">
    
    <form method="GET" class="filter-grid" id="jvmFilterForm">
        <input type="hidden" name="sort_name" id="jvmSortNameField" value="<?= htmlspecialchars($sort_name) ?>">
        <div class="filter-col">
            <label>Siri</label>
            <select name="siri_id" class="select-search" onchange="this.form.submit()">
                <option value="">Semua Siri</option>
                <?php while ($si = $siris->fetch_assoc()): ?>
                    <option value="<?= $si['siri_id'] ?>" <?= $filter_siri == $si['siri_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($si['siri_name']) ?> (<?= htmlspecialchars($si['siri_year']) ?>)
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="filter-col">
            <label>Tahun</label>
            <select name="year" class="select-search" onchange="this.form.submit()">
                <option value="">Semua Tahun</option>
                <?php while ($y = $years->fetch_assoc()): ?>
                    <option value="<?= $y['year'] ?>" <?= $filter_year == $y['year'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($y['year']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="filter-col">
            <label>Sidang</label>
            <select name="session_id" class="select-search" onchange="this.form.submit()">
                <option value="">Semua Sidang</option>
                <?php while ($s = $sessions->fetch_assoc()): ?>
                    <option value="<?= $s['session_id'] ?>" <?= $filter_session == $s['session_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['session_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="filter-col">
            <label>Kumpulan</label>
            <select name="group_id" class="select-search" onchange="this.form.submit()">
                <option value="">Semua Kumpulan</option>
                <?php while ($g = $groups->fetch_assoc()): ?>
                    <option value="<?= $g['group_id'] ?>" <?= $filter_group == $g['group_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($g['group_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="filter-col">
            <label>Pelajar</label>
            <select name="student_id" class="select-search" onchange="this.form.submit()">
                <option value="">Semua Pelajar</option>
                <?php while ($s = $students->fetch_assoc()): ?>
                    <option value="<?= $s['student_id'] ?>" <?= $filter_student == $s['student_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['student_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="filter-col">
            <label>Kriteria</label>
            <select name="criteria_id" class="select-search" onchange="this.form.submit()">
                <option value="">Semua Kriteria</option>
                <?php while ($cr = $criteria_list->fetch_assoc()): ?>
                    <option value="<?= $cr['criteria_id'] ?>" <?= $filter_criteria == $cr['criteria_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cr['criteria_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="filter-col">
            <label>Ujian</label>
            <select name="test_id" class="select-search" onchange="this.form.submit()">
                <option value="">Semua Ujian</option>
                <?php while ($t = $tests->fetch_assoc()): ?>
                    <option value="<?= $t['test_id'] ?>" <?= $filter_test == $t['test_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($t['test_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>
    </form>

    <div class="pm-table-wrap jvm-table-scroll" style="padding: 0; margin: 0; border: none; border-radius: 0; overflow-y: auto;">
        <table class="pm-table view-marks-table">
            <thead>
                <?php
                    $jvmSortIconSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg>';
                    if ($sort_name === 'ASC') {
                        $jvmSortIconSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--c-red)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m6 15 6-6 6 6"/></svg>';
                    } elseif ($sort_name === 'DESC') {
                        $jvmSortIconSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--c-red)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>';
                    }
                ?>
                <tr>
                    <th>Sidang & Kumpulan</th>
                    <th onclick='toggleSortNameJVM()' style='cursor:pointer; user-select:none;' title='Susun A-Z / Z-A'>
                        <div style='display:inline-flex; align-items:center; gap:6px;'>
                            Pelajar
                            <span style='display:inline-flex; align-items:center; color:var(--c-text-faint); margin-top:1px;'><?= $jvmSortIconSvg ?></span>
                        </div>
                    </th>
                    <th>Kriteria</th>
                    <th>Ujian</th>
                    <th style="text-align: center;">Markah</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($marks)): ?>
                <?php 
                    $groupIndex = 0;
                    foreach ($marks as $group => $studentsArr): ?>
                    <?php foreach ($studentsArr as $student => $criteriaArr): ?>
                        <?php 
                            $totalRows = 0;
                            foreach ($criteriaArr as $testsArr) {
                                $totalRows += count($testsArr);
                            }
                            $groupPrinted = false;
                            $studentPrinted = false;
                            
                            $g_parts   = explode('|||', $group);
                            // g_parts[0]=siri_name, g_parts[1]=session_name, g_parts[2]=group_name
                            $g_siri    = $g_parts[0] ?? '';
                            $g_session = $g_parts[1] ?? '';
                            $g_group   = $g_parts[2] ?? '';
                            $s_parts   = explode('|||', $student);
                        ?>
                        <?php foreach ($criteriaArr as $criteria => $testsArr): ?>
                            <?php 
                                $rows = count($testsArr); 
                                $criteriaPrinted = false;
                            ?>
                            <?php foreach ($testsArr as $t): ?>
                                <tr data-group="<?= $groupIndex ?>">
                                    <?php if (!$groupPrinted): ?>
                                        <td class="col-group" rowspan="<?= $totalRows ?>">
                                            <?php if ($g_siri !== ''): ?>
                                                <span class="col-siri-label"><?= htmlspecialchars($g_siri) ?></span>
                                            <?php endif; ?>
                                            <span class="col-session-label"><?= htmlspecialchars($g_session) ?></span>
                                            <?= htmlspecialchars($g_group) ?>
                                        </td>
                                        <?php $groupPrinted = true; ?>
                                    <?php endif; ?>

                                    <?php if (!$studentPrinted): ?>
                                        <td class="col-student" rowspan="<?= $totalRows ?>">
                                            <?= htmlspecialchars($s_parts[0]) ?>
                                            <span class="col-year-label">Tahun: <span class="col-year-val"><?= htmlspecialchars($s_parts[1]) ?></span></span>
                                        </td>
                                        <?php $studentPrinted = true; ?>
                                    <?php endif; ?>

                                    <?php if (!$criteriaPrinted): ?>
                                        <td class="col-criteria" rowspan="<?= $rows ?>"><?= htmlspecialchars($criteria) ?></td>
                                        <?php $criteriaPrinted = true; ?>
                                    <?php endif; ?>

                                    <td class="col-test"><?= htmlspecialchars($t['test']) ?></td>
                                    <td class="col-mark"><?= htmlspecialchars($t['mark']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        <?php $groupIndex++; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <tr class="vm-empty-row">
                    <td colspan="5">
                        <div style="font-size:2rem;margin-bottom:10px;opacity:0.4;">📊</div>
                        Tiada data markah ditemui untuk carian anda.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination Footer -->
    <div class="pagination-container" id="paginationWrapper">
        <div class="pagination-left">
            <select id="rowsPerPageSelect" class="pm-select" style="padding: 4px 8px; height: auto; width: auto; font-size: 0.8rem;">
                <option value="10">10 / mukasurat</option>
                <option value="15" selected>15 / mukasurat</option>
                <option value="20">20 / mukasurat</option>
                <option value="50">50 / mukasurat</option>
                <option value="100">100 / mukasurat</option>
            </select>
            <div class="pagination-info" id="pageInfo">Memaparkan 0 rekod</div>
        </div>
        <div class="pagination-controls" id="paginationButtons"></div>
    </div>

</div>

<script>
// ── Sort ─────────────────────────────────────────────────────
function toggleSortNameJVM() {
    const field = document.getElementById('jvmSortNameField');
    if (field.value === '') field.value = 'ASC';
    else if (field.value === 'ASC') field.value = 'DESC';
    else field.value = '';
    document.getElementById('jvmFilterForm').submit();
}
</script>
<script>
$(document).ready(function() {
    $('.select-search').select2({ width: '100%' });

    // ── PAGINATION (paginate by student-block, not by raw row) ──────────────
    // Because the table uses rowspan, we group rows by their data-group index
    // and paginate those groups — never splitting a student block across pages.

    const rowsPerPageSelect = document.getElementById('rowsPerPageSelect');
    const paginationButtons = document.getElementById('paginationButtons');
    const pageInfo          = document.getElementById('pageInfo');

    let rowsPerPage = parseInt(rowsPerPageSelect.value);
    let currentPage = 1;

    // Build a map: groupIndex → [tr elements]
    function buildGroupMap() {
        const map = {};
        document.querySelectorAll('tr[data-group]').forEach(tr => {
            const g = tr.getAttribute('data-group');
            if (!map[g]) map[g] = [];
            map[g].push(tr);
        });
        return map;
    }

    function renderTable() {
        const groupMap   = buildGroupMap();
        const groupKeys  = Object.keys(groupMap);
        const totalGroups = groupKeys.length;
        const totalPages  = Math.ceil(totalGroups / rowsPerPage) || 1;

        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1)         currentPage = 1;

        const start = (currentPage - 1) * rowsPerPage;
        const end   = start + rowsPerPage;

        // Hide/show all rows
        groupKeys.forEach((key, idx) => {
            const display = (idx >= start && idx < end) ? '' : 'none';
            groupMap[key].forEach(tr => tr.style.display = display);
        });

        updatePaginationUI(totalPages, totalGroups, start, end);
    }

    function updatePaginationUI(totalPages, totalGroups, start, end) {
        const startText = totalGroups === 0 ? 0 : start + 1;
        const endText   = Math.min(end, totalGroups);
        pageInfo.innerHTML = `Memaparkan <b>${startText} – ${endText}</b> daripada <b>${totalGroups}</b> pelajar`;

        let html = '';
        html += `<button class="page-btn" ${currentPage === 1 ? 'disabled' : ''} onclick="goToPage(${currentPage - 1})">«</button>`;

        let startPage = Math.max(1, currentPage - 2);
        let endPage   = Math.min(totalPages, startPage + 4);
        if (endPage - startPage < 4) startPage = Math.max(1, endPage - 4);

        if (startPage > 1) {
            html += `<button class="page-btn" onclick="goToPage(1)">1</button>`;
            if (startPage > 2) html += `<span style="color:var(--c-text-faint);margin:0 2px;align-self:end;">…</span>`;
        }
        for (let i = startPage; i <= endPage; i++) {
            html += `<button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="goToPage(${i})">${i}</button>`;
        }
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) html += `<span style="color:var(--c-text-faint);margin:0 2px;align-self:end;">…</span>`;
            html += `<button class="page-btn" onclick="goToPage(${totalPages})">${totalPages}</button>`;
        }
        html += `<button class="page-btn" ${currentPage === totalPages || totalPages === 0 ? 'disabled' : ''} onclick="goToPage(${currentPage + 1})">»</button>`;

        paginationButtons.innerHTML = html;
    }

    window.goToPage = function(page) {
        currentPage = page;
        renderTable();
        document.querySelector('.pm-table-wrap').scrollTo({ top: 0, behavior: 'smooth' });
    };

    rowsPerPageSelect.addEventListener('change', function() {
        rowsPerPage = parseInt(this.value);
        currentPage = 1;
        renderTable();
    });

    // ── Fit the marks table + pagination into the viewport, no page scroll ──
    function fitJvmTableHeight() {
        const scrollEl = document.querySelector('.jvm-table-scroll');
        const pagination = document.getElementById('paginationWrapper');
        if (!scrollEl || !pagination) return;
        if (window.innerWidth <= 600) {
            scrollEl.style.maxHeight = '';
            return;
        }
        const top = scrollEl.getBoundingClientRect().top;
        const paginationH = pagination.offsetHeight;
        const available = window.innerHeight - top - paginationH - 24; // 24px bottom breathing room
        scrollEl.style.maxHeight = Math.max(150, available) + 'px';
    }
    window.addEventListener('resize', fitJvmTableHeight);

    renderTable();
    fitJvmTableHeight();
});
</script>

</main>
</body>
</html>