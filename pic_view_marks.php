<?php
session_start();
require __DIR__ . '/auth_check.php';
include 'db.php';
$conn = getDB();


// PIC only
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pic') {
    header("Location: login.php");
    exit();
}

$pm_page = 'view_marks';
include 'layout.php';

require_once __DIR__ . '/pagination_helpers.php';
function page_url(int $p, array $extra): string {
    return pm_page_url($p, $extra);
}

/* FILTER DATA — scoped to "Siri Aktif" */
$active_siri = (int)($_SESSION['active_siri_id'] ?? 0);

// Define a broad membership rule: Students whose default level is in this Siri OR who are in a group in this Siri.
if ($active_siri > 0) {
    $siri_sessions_sub = "(SELECT session_id FROM sessions WHERE siri_id = $active_siri)";
    $membership_cond = "st.student_id IN (
        SELECT st2.student_id FROM students st2
        LEFT JOIN levels l2 ON st2.level_id = l2.level_id
        WHERE l2.session_id IN $siri_sessions_sub
        UNION
        SELECT gs2.student_id FROM group_students gs2
        JOIN `groups` g2 ON gs2.group_id = g2.group_id
        JOIN levels l2b ON g2.level_id = l2b.level_id
        WHERE l2b.session_id IN $siri_sessions_sub
    )";
} else {
    $siri_sessions_sub = "(SELECT session_id FROM sessions)";
    $membership_cond = "1=1";
}

$show_siri_picker = ($active_siri === 0);
$f_siri    = $show_siri_picker ? (int)($_GET['siri_id'] ?? 0) : $active_siri;
$eff_siri  = $f_siri; // effective siri for all scoping

$f_school  = isset($_GET['school_id'])  ? (int)$_GET['school_id']  : 0;
$f_group   = isset($_GET['group_id'])   ? (int)$_GET['group_id']   : 0;
$f_level   = isset($_GET['level_id'])   ? (int)$_GET['level_id']   : 0;
$f_test    = isset($_GET['test_id'])    ? (int)$_GET['test_id']    : 0;
$f_student = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
// 'evaluated' | 'not_evaluated' | '' (all) — whether the student has at
// least one submitted score row (see $isEvaluated below).
$f_status  = in_array($_GET['eval_status'] ?? '', ['evaluated', 'not_evaluated'], true)
    ? $_GET['eval_status'] : '';
// Sort-by-name toggle — same cycle as pic_master_list.php ('' -> ASC -> DESC
// -> ''), driven by a GET param + full page reload since this page already
// uses that architecture (see page_url()/$filter_params below).
$sort_name = in_array($_GET['sort_name'] ?? '', ['ASC', 'DESC'], true) ? $_GET['sort_name'] : '';

// ── Filters are independently selectable — no parent/child gating —
//    EXCEPT Kumpulan, which is narrowed to the selected Peringkat below.
//    That link is safe to add without losing the "spot a mismatch" use
//    case the independence was for: the marks query itself still matches
//    a student by group membership OR by their own default level (see
//    $where below), so a student whose group's level differs from their
//    own default level is still findable — this only affects which
//    options the Kumpulan *dropdown* offers, not the query logic. ──

// ── Siri list (only needed when picker is shown) ──
$siri_list = [];
if ($show_siri_picker) {
    $sr = $conn->query("SELECT siri_id, siri_name FROM siri ORDER BY siri_year DESC, siri_name");
    if ($sr) while ($row = $sr->fetch_assoc()) $siri_list[] = $row;
}

// ── Cawangan: schools scoped to eff_siri ──
$sql_schools = $eff_siri > 0
    ? "SELECT sc.school_id, sc.school_name FROM schools sc JOIN siri_schools ss ON sc.school_id = ss.school_id WHERE ss.siri_id = $eff_siri ORDER BY sc.school_name"
    : "SELECT school_id, school_name FROM schools ORDER BY school_name";
$schools = $conn->query($sql_schools);

// ── Kumpulan: groups scoped to eff_siri, and to the selected Peringkat
//    when one is chosen (a group under a different level is never a valid
//    pick alongside that Peringkat filter, so it shouldn't be offered). ──
$groupWhereParts = [];
if ($eff_siri > 0) $groupWhereParts[] = "level_id IN (SELECT level_id FROM levels WHERE session_id IN (SELECT session_id FROM sessions WHERE siri_id = $eff_siri))";
if ($f_level > 0)  $groupWhereParts[] = "level_id = $f_level";
$sql_groups = "SELECT group_id, group_name FROM `groups`"
    . ($groupWhereParts ? " WHERE " . implode(' AND ', $groupWhereParts) : "")
    . " ORDER BY group_name";
$groups = $conn->query($sql_groups);

// ── Peringkat: levels scoped to eff_siri, and excluding any level with
//    zero groups — nothing to narrow Kumpulan down to, so it's a dead
//    end in the dropdown. ──
$levelHasGroupSql = "EXISTS (SELECT 1 FROM `groups` lg WHERE lg.level_id = levels.level_id)";
$sql_levels = $eff_siri > 0
    ? "SELECT level_id, level_name FROM levels WHERE session_id IN (SELECT session_id FROM sessions WHERE siri_id = $eff_siri) AND $levelHasGroupSql ORDER BY level_name"
    : "SELECT level_id, level_name FROM levels WHERE $levelHasGroupSql ORDER BY level_name";
$levels = $conn->query($sql_levels);

// ── Ujian: tests scoped to eff_siri ──
$sql_tests = $eff_siri > 0
    ? "SELECT t.test_id, t.test_name FROM tests t JOIN levels l ON t.level_id = l.level_id WHERE l.session_id IN (SELECT session_id FROM sessions WHERE siri_id = $eff_siri) ORDER BY t.test_name"
    : "SELECT test_id, test_name FROM tests ORDER BY test_name";
$tests = $conn->query($sql_tests);

// ── Pelajar: students scoped to eff_siri ──
$sql_students = $eff_siri > 0
    ? "SELECT student_id, student_name FROM students WHERE school_id IN (SELECT school_id FROM siri_schools WHERE siri_id = $eff_siri) ORDER BY student_name"
    : "SELECT student_id, student_name FROM students ORDER BY student_name";
$students = $conn->query($sql_students);

// Renders one dd-wrap cascading filter dropdown for the marks form — a
// hidden input (id kept as vm_X so vmCascade()'s existing reset logic still
// works) carries the GET field value, while the visible UI is the
// JS-rendered dropdown (no native <select> popup, which is what caused the
// black-flash lag).
function renderVMDD(string $fieldName, string $ddName, string $normalEmptyLabel, string $disabledEmptyLabel, array $options, $currentVal, bool $isDisabled) {
    $currentVal = (string)$currentVal;
    $emptyLabel = $isDisabled ? $disabledEmptyLabel : $normalEmptyLabel;
    $disabledClass = $isDisabled ? ' dd-trigger-disabled' : '';
    echo "<div class=\"dd-wrap\" id=\"ddWrap_{$ddName}\">";
    echo "<div class=\"dd-trigger{$disabledClass}\" id=\"ddTrigger_{$ddName}\" onclick=\"ddToggle('{$ddName}')\">";
    $curLabel = $emptyLabel;
    if (!$isDisabled) {
        foreach ($options as $opt) {
            if ((string)$opt['value'] === $currentVal && $currentVal !== '' && $currentVal !== '0') { $curLabel = $opt['label']; break; }
        }
    }
    $labelColor = ($currentVal === '' || $currentVal === '0' || $isDisabled) ? 'color:var(--c-text-faint);' : '';
    echo "<span id=\"ddLabel_{$ddName}\" style=\"{$labelColor}\">" . htmlspecialchars($curLabel) . "</span>";
    echo "<span class=\"dd-arrow\">▼</span></div>";
    echo "<div class=\"dd-panel\" id=\"ddPanel_{$ddName}\">";
    echo "<div class=\"dd-search-box\"><input type=\"text\" placeholder=\"Cari...\" oninput=\"ddFilter('{$ddName}',this.value)\" onclick=\"event.stopPropagation()\"></div>";
    echo "<div class=\"dd-options\" id=\"ddOpts_{$ddName}\">";
    $emptySel = ($currentVal === '' || $currentVal === '0') ? 'selected' : '';
    echo "<div class=\"dd-opt {$emptySel}\" data-value=\"\" onclick=\"ddSelect('{$ddName}','','" . htmlspecialchars($normalEmptyLabel, ENT_QUOTES) . "')\">" . htmlspecialchars($normalEmptyLabel) . "</div>";
    foreach ($options as $opt) {
        $sel = ((string)$opt['value'] === $currentVal && $currentVal !== '' && $currentVal !== '0') ? 'selected' : '';
        $valEsc = htmlspecialchars((string)$opt['value'], ENT_QUOTES);
        $lblEsc = htmlspecialchars($opt['label'], ENT_QUOTES);
        echo "<div class=\"dd-opt {$sel}\" data-value=\"{$valEsc}\" onclick=\"ddSelect('{$ddName}','{$valEsc}','{$lblEsc}')\">" . htmlspecialchars($opt['label']) . "</div>";
    }
    echo "</div><div class=\"dd-empty\" id=\"ddEmpty_{$ddName}\">Tiada hasil</div></div>";
    echo "<input type=\"hidden\" name=\"{$fieldName}\" id=\"{$ddName}\" value=\"" . htmlspecialchars($currentVal === '0' ? '' : $currentVal) . "\">";
    echo "</div>";
}

/* FETCH MARKS — applies all five filters together */
$where = [];
if ($f_school)  $where[] = "s.school_id = $f_school";
if ($f_level)   $where[] = "s.level_id = $f_level";
if ($f_student) $where[] = "s.student_id = $f_student";

// 1. Broad filter for the effective Siri
if ($eff_siri > 0) {
    $where[] = "(
        s.school_id IN (SELECT school_id FROM siri_schools WHERE siri_id = $eff_siri)
        OR
        EXISTS (SELECT 1 FROM group_students gs JOIN `groups` g ON gs.group_id = g.group_id JOIN levels gl ON g.level_id = gl.level_id WHERE gs.student_id = s.student_id AND gl.session_id IN (SELECT session_id FROM sessions WHERE siri_id = $eff_siri))
    )";
}

// 2. Specific Kumpulan filter
if ($f_group) {
    $where[] = "(
        EXISTS (SELECT 1 FROM group_students gsx WHERE gsx.student_id = s.student_id AND gsx.group_id = $f_group)
        OR s.level_id = (SELECT level_id FROM `groups` WHERE group_id = $f_group)
    )";
}

$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

// Enumerate the tests/criteria expected for the student's level (not just
// whichever ones happen to already have a scores row), then LEFT JOIN
// scores to fill in the mark if a judge has submitted one. This way a
// student whose judge hasn't graded yet still shows their real ujian/
// kriteria list (with mark 0) instead of a single "Belum Dinilai" placeholder.
// Test filter MUST be in the LEFT JOIN condition (not WHERE), otherwise it
// would turn the LEFT JOIN into an INNER JOIN and drop ungraded students.
$test_join = $f_test ? "AND t.test_id = $f_test" : "";

$sql = "
SELECT
    COALESCE(
        " . ($f_group ? "(SELECT group_name FROM `groups` WHERE group_id = $f_group)," : "") . "
        sg.group_name,
        (SELECT gr.group_name FROM group_students gst JOIN `groups` gr ON gst.group_id = gr.group_id WHERE gst.student_id = s.student_id LIMIT 1),
        'Tiada Kumpulan'
    ) AS group_name,
    s.student_name,
    COALESCE(t.test_name, 'Belum Dinilai') AS test_name,
    COALESCE(c.criteria_name, '-') AS criteria_name,
    COALESCE(sc.mark, 0) AS mark,
    sc.score_id AS score_id
FROM students s
LEFT JOIN tests t ON t.level_id = s.level_id $test_join
LEFT JOIN criteria c ON c.test_id = t.test_id
LEFT JOIN scores sc ON sc.student_id = s.student_id AND sc.criteria_id = c.criteria_id
LEFT JOIN `groups` sg ON sg.group_id = sc.group_id
$where_sql
ORDER BY group_name, " . ($sort_name === 'ASC' ? "s.student_name ASC" : ($sort_name === 'DESC' ? "s.student_name DESC" : "s.student_id")) . ", t.test_name, c.criteria_name
";

$res = $conn->query($sql);

$marks = [];
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $marks[$r['group_name']][$r['student_name']][] = $r;
    }
}

// ── Status filter (Sudah Dinilai / Belum Dinilai) ──────────────────
// Applied here (post-query, on the grouped structure) rather than in SQL —
// "evaluated" means at least one of the student's rows has a real score_id
// (a judge has submitted a mark), which is the same rule the status badges
// below use, so this filter and what you see per student always agree.
// Must run before the pagination flatten below so the page count/slicing
// reflects the filtered set, not the full one.
if ($f_status !== '') {
    foreach ($marks as $grp => $studentsArr) {
        foreach ($studentsArr as $stu => $rows) {
            $isEvaluated = false;
            foreach ($rows as $r) {
                if ($r['score_id'] !== null) { $isEvaluated = true; break; }
            }
            $keep = ($f_status === 'evaluated') ? $isEvaluated : !$isEvaluated;
            if (!$keep) unset($marks[$grp][$stu]);
        }
        if (empty($marks[$grp])) unset($marks[$grp]);
    }
}

// ── Pagination ────────────────────────────────────────────────────
$per_page    = 20;
$page        = max(1, (int)($_GET['page'] ?? 1));

// Flatten to ordered list of [group, student] pairs for slicing
$all_pairs   = [];
foreach ($marks as $grp => $students_arr) {
    foreach ($students_arr as $stu => $_) {
        $all_pairs[] = [$grp, $stu];
    }
}

$total_students   = count($all_pairs);
$db_student_count = $conn->query("SELECT COUNT(*) AS c FROM students")->fetch_assoc()['c'];

// Fixed typo: was previously `$total_pages = $total_pages = max(...)`
$total_pages    = max(1, (int)ceil($total_students / $per_page));
$page           = min($page, $total_pages);
$page_pairs     = array_slice($all_pairs, ($page - 1) * $per_page, $per_page);

// Build a paginated subset of $marks containing only the current page's students
$marks_page = [];
foreach ($page_pairs as [$grp, $stu]) {
    $marks_page[$grp][$stu] = $marks[$grp][$stu];
}
?>

<style>
/* ── PAGINATION (matches pic_judges.php / pic_students.php etc.) ── */
.vm-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 0;
    padding: 16px;
    border-top: 1px solid var(--c-border);
}
.vm-page-info {
    font-size: 0.8rem;
    color: var(--c-text-faint);
    font-weight: 500;
}
.vm-page-btns {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
}
.vm-page-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 34px;
    height: 34px;
    padding: 0 10px;
    border-radius: 6px;
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--c-text-muted);
    background: var(--c-surface-2);
    border: 1px solid var(--c-border-strong);
    text-decoration: none;
    cursor: pointer;
    transition: all 0.15s;
    white-space: nowrap;
}
.vm-page-btn:hover:not(.vm-page-disabled) {
    color: #fff;
    border-color: var(--c-red);
    background: var(--c-red-dim);
}
.vm-page-btn.vm-page-active {
    background: var(--c-red);
    border-color: var(--c-red);
    color: #fff;
    cursor: default;
    pointer-events: none;
}
.vm-page-btn.vm-page-disabled {
    opacity: 0.4;
    cursor: not-allowed;
    pointer-events: none;
}
.vm-page-ellipsis {
    display: inline-flex;
    align-items: center;
    height: 34px;
    color: var(--c-text-faint);
    font-size: 0.85rem;
    padding: 0 4px;
}
html.pm-light .vm-page-info { color: #6b7280; }
html.pm-light .vm-page-btn { background: #ffffff; border-color: #d1d5db; color: #374151; }
html.pm-light .vm-page-btn:hover:not(.vm-page-disabled) { background: #fee2e2; border-color: #e2581e; color: #b91c1c; }
html.pm-light .vm-page-btn.vm-page-active { background: #e2581e; border-color: #e2581e; color: #ffffff; }
html.pm-light .vm-page-ellipsis { color: #9ca3af; }
html.pm-light .vm-pagination { border-top-color: #e5e7eb; }
@media (max-width: 640px) {
    .vm-pagination { flex-direction: column; align-items: center; text-align: center; }
    .vm-page-btns { width: 100%; justify-content: center; }
}

/* ── LAYOUT ── */
.filter-grid {
    display: grid;
    grid-template-columns: repeat(var(--fg-cols, 5), 1fr);
    gap: 8px;
    margin-bottom: 12px;
}
.filter-grid > div {
    min-width: 0;
}
select:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}
.filter-grid label {
    font-size: 0.7rem !important;
    margin-bottom: 3px !important;
}
.filter-grid .pm-select {
    height: 32px;
    font-size: 0.8rem;
    padding-top: 0;
    padding-bottom: 0;
}
@media (max-width: 640px) {
    .filter-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 400px) {
    .filter-grid { grid-template-columns: 1fr; }
}

/* ── Searchable dropdown (.dd-wrap/.dd-trigger/.dd-panel/etc.) is now
   defined once in the shared filter_bar.css, loaded via layout.php — kept
   local here only if a page needs to override it. ── */

/* ── STUDENT LIST: scrollable so pagination is always visible.
   Height set dynamically in JS (fitMarksListHeight) so it always leaves
   room for the pagination bar below it instead of a static
   calc(100vh - Npx) that goes stale whenever the filter grid above the
   list changes height. ── */
.marks-list {
    overflow-y: auto;
    min-height: 80px;
    padding-right: 4px;
    scrollbar-width: thin;
    scrollbar-color: var(--c-border-strong) transparent;
}
@media (max-width: 640px) {
    .marks-list { max-height: none !important; overflow-y: visible !important; }
}
.marks-list::-webkit-scrollbar { width: 4px; }
.marks-list::-webkit-scrollbar-track { background: transparent; }
.marks-list::-webkit-scrollbar-thumb { background: var(--c-border-strong); border-radius: 2px; }

/* ── MODERN ACCORDION STYLES (COMPACT) ── */
.group-title {
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--c-text-muted);
    text-transform: uppercase;
    letter-spacing: 1.5px;
    margin: 10px 0 6px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.group-title svg {
    stroke: var(--c-text-muted);
    width: 14px;
    height: 14px;
}
.group-title::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--c-border-strong);
}

.student-card {
    background: var(--c-surface-2);
    border: 1px solid var(--c-border-strong);
    border-radius: 6px;
    margin-bottom: 4px;
    overflow: hidden;
    transition: all 0.2s ease;
}
.student-card:hover {
    border-color: var(--c-red);
}

.student-header {
    padding: 7px 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    background: transparent;
    gap: 8px;
}
@media (max-width: 640px) {
    .student-header { flex-wrap: wrap; }
    .student-header > div { flex-wrap: wrap; }
}

.student-name {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--c-text);
    letter-spacing: 0.2px;
}

.chevron {
    transition: transform 0.2s ease;
    color: var(--c-text-muted);
    font-size: 0.7rem;
}
.student-card.is-open .chevron {
    transform: rotate(180deg);
    color: var(--c-red);
}

/* ── COMPACT BADGES ── */
.score-badge {
    background: var(--c-surface-0);
    border: 1px solid var(--c-border-strong);
    color: var(--c-text);
    padding: 3px 12px;
    border-radius: 20px; 
    font-weight: 700;
    font-size: 1rem;
    font-family: 'DM Mono', monospace;
    display: flex;
    align-items: baseline;
    gap: 4px;
}
.score-badge span {
    font-family: 'DM Sans', sans-serif;
    font-size: 0.65rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--c-text-muted);
}

/* Unrated: Red tint (Still indicates action needed) */
.status-badge {
    background: rgba(214, 40, 40, 0.1);
    color: #F87171;
    border: 1px solid rgba(214, 40, 40, 0.3);
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Rated: High-contrast (Indicates completion) */
.status-badge-success {
    background: rgba(74, 222, 128, 0.12);
    color: #16a34a;
    border: 1px solid var(--c-border-strong);
    box-shadow: 0 0 8px rgba(255, 255, 255, 0.15);
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.student-details {
    display: none;
    padding: 0 14px 14px 14px;
    border-top: 1px solid var(--c-border-strong);
    margin-top: 4px;
}

/* ── COMPACT INNER TABLE ── */
.modern-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0 4px;
}
.modern-table th {
    color: var(--c-text-muted);
    font-weight: 600;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 0 10px 6px 10px;
    border-bottom: 1px solid var(--c-border);
    text-align: left;
}
.modern-table td {
    padding: 8px 10px;
    background: var(--c-surface-2);
    color: var(--c-text-muted);
    font-size: 0.85rem;
}
.modern-table tr td:first-child { border-top-left-radius: 6px; border-bottom-left-radius: 6px; }
.modern-table tr td:last-child {
    border-top-right-radius: 6px;
    border-bottom-right-radius: 6px;
    text-align: center;
    font-weight: 700;
    color: var(--c-text);
    font-family: 'DM Mono', monospace;
    font-size: 0.95rem;
}

/* ── DRILLDOWN PICKER ── */
.drilldown-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
}
.drilldown-grid label {
    color: var(--pm-text-muted);
    font-size: 0.8rem;
    margin-bottom: 4px;
    display: block;
    text-transform: uppercase;
    font-weight: 600;
}
.drilldown-empty {
    text-align: center;
    padding: 40px 20px;
    color: var(--c-text-muted);
    background: var(--c-surface-2);
    border-radius: 8px;
    border: 1px dashed var(--c-border-strong);
    margin-top: 20px;
}
/* ── LIGHT MODE OVERRIDES ── */
html.pm-light .group-title {
    color: #6b7280;
}
html.pm-light .group-title svg {
    stroke: #6b7280;
}
html.pm-light .group-title::after {
    background: #e5e7eb;
}
html.pm-light .student-card {
    background: #ffffff;
    border-color: #d1d5db;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}
html.pm-light .student-card:hover {
    border-color: #e2581e;
    box-shadow: 0 2px 8px rgba(226,88,30,0.12);
}
html.pm-light .student-name {
    color: #111827;
}
html.pm-light .score-badge {
    background: #f3f4f6;
    border-color: #d1d5db;
    color: #111827;
}
html.pm-light .score-badge span {
    color: #6b7280;
}
html.pm-light .chevron {
    color: #9ca3af;
}
html.pm-light .student-details {
    border-top-color: #e5e7eb;
}
html.pm-light .modern-table th {
    color: #6b7280;
    border-bottom-color: #e5e7eb;
}
html.pm-light .modern-table td {
    background: #f9fafb;
    color: #374151;
}
html.pm-light .modern-table tr td:last-child {
    color: #111827;
}
html.pm-light .drilldown-empty {
    background: #f9fafb;
    border-color: #d1d5db;
    color: #6b7280;
}
html.pm-light .status-badge-success {
    box-shadow: none;
    border-color: #86efac;
}
</style>

<h2 class="pm-page-heading" style="margin-bottom:10px;">📊 Semak Markah</h2>

<div class="pm-card" style="padding:12px 16px;">
    <?php $fg_cols = $show_siri_picker ? 7 : 6; ?>
    <form method="GET" id="vm-filter-form" class="filter-grid" style="--fg-cols:<?= $fg_cols ?>;">

        <?php if ($show_siri_picker):
            $siriOptsVM = [];
            foreach ($siri_list as $sr) $siriOptsVM[] = ['value' => $sr['siri_id'], 'label' => $sr['siri_name']];
        ?>
        <div>
            <label style="color:var(--c-text-muted); font-size:0.8rem; margin-bottom:4px; display:block; text-transform: uppercase; font-weight: 600;">Siri</label>
            <?php renderVMDD('siri_id', 'vm_siri', '-- Pilih Siri --', '-- Pilih Siri --', $siriOptsVM, $f_siri, false); ?>
        </div>
        <?php endif; ?>

        <?php
        $schoolOptsVM = [];
        while ($sc = $schools->fetch_assoc()) $schoolOptsVM[] = ['value' => $sc['school_id'], 'label' => $sc['school_name']];

        $groupOptsVM = [];
        while ($g = $groups->fetch_assoc()) $groupOptsVM[] = ['value' => $g['group_id'], 'label' => $g['group_name']];

        $levelOptsVM = [];
        while ($l = $levels->fetch_assoc()) $levelOptsVM[] = ['value' => $l['level_id'], 'label' => $l['level_name']];

        $testOptsVM = [];
        while ($t = $tests->fetch_assoc()) $testOptsVM[] = ['value' => $t['test_id'], 'label' => $t['test_name']];

        $studentOptsVM = [];
        while ($s = $students->fetch_assoc()) $studentOptsVM[] = ['value' => $s['student_id'], 'label' => $s['student_name']];
        ?>

        <div>
            <label style="color:var(--c-text-muted); font-size:0.8rem; margin-bottom:4px; display:block; text-transform: uppercase; font-weight: 600;">Cawangan</label>
            <?php renderVMDD('school_id', 'vm_school', 'Semua Cawangan', 'Semua Cawangan', $schoolOptsVM, $f_school, false); ?>
        </div>

        <div>
            <label style="color:var(--c-text-muted); font-size:0.8rem; margin-bottom:4px; display:block; text-transform: uppercase; font-weight: 600;">Kumpulan</label>
            <?php renderVMDD('group_id', 'vm_group', 'Semua Kumpulan', 'Semua Kumpulan', $groupOptsVM, $f_group, false); ?>
        </div>

        <div>
            <label style="color:var(--c-text-muted); font-size:0.8rem; margin-bottom:4px; display:block; text-transform: uppercase; font-weight: 600;">Peringkat</label>
            <?php renderVMDD('level_id', 'vm_level', 'Semua Peringkat', 'Semua Peringkat', $levelOptsVM, $f_level, false); ?>
        </div>

        <div>
            <label style="color:var(--c-text-muted); font-size:0.8rem; margin-bottom:4px; display:block; text-transform: uppercase; font-weight: 600;">Ujian</label>
            <?php renderVMDD('test_id', 'vm_test', 'Semua Ujian', 'Semua Ujian', $testOptsVM, $f_test, false); ?>
        </div>

        <div>
            <label style="color:var(--c-text-muted); font-size:0.8rem; margin-bottom:4px; display:block; text-transform: uppercase; font-weight: 600;">Pelajar</label>
            <?php renderVMDD('student_id', 'vm_student', 'Semua Pelajar', 'Semua Pelajar', $studentOptsVM, $f_student, false); ?>
        </div>

        <div>
            <label style="color:var(--c-text-muted); font-size:0.8rem; margin-bottom:4px; display:block; text-transform: uppercase; font-weight: 600;">Status</label>
            <?php
            $statusOptsVM = [
                ['value' => 'evaluated',     'label' => 'Sudah Dinilai'],
                ['value' => 'not_evaluated', 'label' => 'Belum Dinilai'],
            ];
            renderVMDD('eval_status', 'vm_status', 'Semua Status', 'Semua Status', $statusOptsVM, $f_status, false);
            ?>
        </div>
    </form>

    <script>
    // ── Searchable dropdown logic (matches pic_students.php's dd-wrap) ──
    // ddName here IS the hidden input's own id (vm_siri, vm_school, ...) —
    // kept that way so vmCascade's reset logic below can address it directly.
    function ddToggle(ddName) {
        const trigger = document.getElementById('ddTrigger_' + ddName);
        if (trigger.classList.contains('dd-trigger-disabled')) return;
        const panel = document.getElementById('ddPanel_' + ddName);
        const isOpen = panel.classList.contains('open');
        document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
        document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
        if (!isOpen) {
            panel.classList.add('open'); trigger.classList.add('open');
            setTimeout(() => panel.querySelector('.dd-search-box input')?.focus(), 50);
        }
    }

    function ddFilter(ddName, val) {
        const opts = document.querySelectorAll('#ddOpts_' + ddName + ' .dd-opt');
        const empty = document.getElementById('ddEmpty_' + ddName);
        let any = false;
        opts.forEach(o => {
            const m = o.textContent.toLowerCase().includes(val.toLowerCase());
            o.classList.toggle('hidden', !m);
            if (m) any = true;
        });
        if (empty) empty.style.display = any ? 'none' : 'block';
    }

    // Kumpulan (vm_group) is the one exception to "every filter is
    // independent" — its dropdown options are now scoped server-side to
    // the selected Peringkat (vm_level), so a Kumpulan value chosen under
    // a since-changed Peringkat has to be cleared here too, not just left
    // as a stale hidden-field value the dropdown no longer shows selected.
    const VM_LEVEL_TO_GROUP = { vm_level: 'vm_group' };

    function ddSelect(ddName, value, label) {
        document.getElementById(ddName).value = value;
        const lbl = document.getElementById('ddLabel_' + ddName);
        lbl.textContent = label;
        lbl.style.color = value === '' ? 'var(--c-text-faint)' : '';
        document.querySelectorAll('#ddOpts_' + ddName + ' .dd-opt').forEach(o => o.classList.toggle('selected', o.dataset.value === value));
        document.getElementById('ddPanel_' + ddName).classList.remove('open');
        document.getElementById('ddTrigger_' + ddName).classList.remove('open');
        const dependentGroup = VM_LEVEL_TO_GROUP[ddName];
        if (dependentGroup) {
            const groupField = document.getElementById(dependentGroup);
            if (groupField) groupField.value = '';
        }
        // Every other filter is independent — no parent/child resetting.
        // Submitting the form carries forward all currently-set hidden
        // input values (including the one that was just changed) as GET
        // params.
        document.getElementById('vm-filter-form').submit();
    }

    document.addEventListener('click', e => {
        if (!e.target.closest('.dd-wrap')) {
            document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
            document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
        }
    });
    </script>

    <?php
    // Filters to preserve across pagination links (see page_url() above)
    $filter_params = array_filter([
        'siri_id'    => ($show_siri_picker && $f_siri)   ? $f_siri   : null,
        'school_id'  => $f_school  ?: null,
        'group_id'   => $f_group   ?: null,
        'level_id'   => $f_level   ?: null,
        'test_id'    => $f_test    ?: null,
        'student_id' => $f_student ?: null,
        'eval_status' => $f_status ?: null,
        'sort_name'  => $sort_name ?: null,
    ]);

    // Sort-by-name toggle control — visual style matches pic_master_list.php.
    $sort_next = ($sort_name === '') ? 'ASC' : (($sort_name === 'ASC') ? 'DESC' : '');
    $sortToggleParams = $filter_params;
    $sortToggleParams['sort_name'] = $sort_next;
    $sortToggleUrl = page_url(1, array_filter($sortToggleParams));
    $sortIconSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg>';
    if ($sort_name === 'ASC') {
        $sortIconSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--c-red)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m6 15 6-6 6 6"/></svg>';
    } elseif ($sort_name === 'DESC') {
        $sortIconSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--c-red)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>';
    }
    ?>

    <div style="display:flex; justify-content:flex-end; margin-bottom:10px;">
        <a href="<?= htmlspecialchars($sortToggleUrl) ?>" style="display:inline-flex;align-items:center;gap:6px;color:var(--c-text-muted);text-decoration:none;cursor:pointer;user-select:none;font-size:0.8rem;font-weight:600;" title="Susun A-Z / Z-A">
            Nama Pelajar
            <span style="display:inline-flex;align-items:center;color:var(--c-text-faint);margin-top:1px;"><?= $sortIconSvg ?></span>
        </a>
    </div>

    <div class="marks-list">
        <?php if (!$marks_page): ?>
            <div class="drilldown-empty" style="color:var(--c-text-muted);">
                <p style="margin:0 0 6px; font-size:0.95rem; font-weight:600;">Tiada pelajar ditemui.</p>
                <p style="margin:0; font-size:0.82rem; opacity:0.7;">
                    Jumlah pesilat dalam sistem: <strong><?= (int)$db_student_count ?></strong> &nbsp;|&nbsp;
                    Siri aktif: <strong><?= $active_siri > 0 ? 'Siri #'.$active_siri : 'Semua Siri' ?></strong>
                </p>
            </div>
        <?php elseif ($f_student): ?>
            <?php foreach($marks_page as $group => $studentsArr): foreach($studentsArr as $student => $rows):
                $totalMark = 0; $isEvaluated = false;
                foreach ($rows as $r) {
                    $totalMark += (float)$r['mark'];
                    if ($r['score_id'] !== null) $isEvaluated = true;
                }
            ?>
            <div class="student-card is-open">
                <div class="student-header" style="cursor:default;">
                    <div style="display:flex; align-items:center; gap:12px;">
                        <span class="student-name"><?= htmlspecialchars($student) ?></span>
                        <?php if ($isEvaluated): ?>
                        <span class="status-badge-success"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>Sudah Dinilai</span>
                        <?php else: ?>
                        <span class="status-badge">Belum Dinilai</span>
                        <?php endif; ?>
                    </div>
                    <div class="score-badge"><?= $totalMark ?> <span>Markah</span></div>
                </div>
                <div class="student-details" style="display:block;">
                    <table class="modern-table">
                        <thead><tr><th>Ujian</th><th>Kriteria</th><th style="width:80px; text-align:center;">Markah</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td style="color:var(--c-text-faint);"><?= htmlspecialchars($r['test_name']) ?></td>
                            <td><?= htmlspecialchars($r['criteria_name']) ?></td>
                            <td><?= htmlspecialchars($r['mark']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; endforeach; ?>
        <?php else: ?>
            <?php foreach($marks_page as $group => $studentsArr): ?>
            <div class="group-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 7v10l9 4 9-4V7"/></svg>
                <?= htmlspecialchars($group) ?> (<?= count($studentsArr) ?>)
            </div>
            <?php foreach($studentsArr as $student => $rows):
                $totalMark = 0; $isEvaluated = false;
                foreach ($rows as $r) {
                    $totalMark += (float)$r['mark'];
                    if ($r['score_id'] !== null) $isEvaluated = true;
                }
            ?>
            <div class="student-card">
                <div class="student-header" onclick="this.parentElement.classList.toggle('is-open'); var d=this.nextElementSibling; d.style.display = d.style.display==='block' ? 'none' : 'block';">
                    <div style="display:flex; align-items:center; gap:12px;">
                        <span class="student-name"><?= htmlspecialchars($student) ?></span>
                        <?php if ($isEvaluated): ?>
                        <span class="status-badge-success"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>Sudah Dinilai</span>
                        <?php else: ?>
                        <span class="status-badge">Belum Dinilai</span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <div class="score-badge"><?= $totalMark ?> <span>Markah</span></div>
                        <span class="chevron">▼</span>
                    </div>
                </div>
                <div class="student-details">
                    <table class="modern-table">
                        <thead><tr><th>Ujian</th><th>Kriteria</th><th style="width:80px; text-align:center;">Markah</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td style="color:var(--c-text-faint);"><?= htmlspecialchars($r['test_name']) ?></td>
                            <td><?= htmlspecialchars($r['criteria_name']) ?></td>
                            <td><?= htmlspecialchars($r['mark']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div><!-- end .marks-list -->

    <?php if ($total_pages > 1):
        $sp_ = max(1, $page - 2);
        $ep_ = min($total_pages, $sp_ + 4);
        if ($ep_ - $sp_ < 4) $sp_ = max(1, $ep_ - 4);
        $start_num_ = $total_students === 0 ? 0 : (($page - 1) * $per_page) + 1;
        $end_num_   = min($page * $per_page, $total_students);
    ?>
    <div class="vm-pagination">
        <span class="vm-page-info">
            Memaparkan <b><?= $start_num_ ?>–<?= $end_num_ ?></b> daripada <b><?= $total_students ?></b> pesilat
        </span>
        <div class="vm-page-btns">
            <?php if ($page > 1): ?>
            <a href="<?= page_url($page - 1, $filter_params) ?>" class="vm-page-btn">&laquo;</a>
            <?php else: ?>
            <span class="vm-page-btn vm-page-disabled">&laquo;</span>
            <?php endif; ?>

            <?php if ($sp_ > 1): ?>
            <a href="<?= page_url(1, $filter_params) ?>" class="vm-page-btn">1</a>
            <?php if ($sp_ > 2): ?><span class="vm-page-ellipsis">&hellip;</span><?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $sp_; $i <= $ep_; $i++): ?>
            <a href="<?= page_url($i, $filter_params) ?>"
               class="vm-page-btn <?= $i === $page ? 'vm-page-active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>

            <?php if ($ep_ < $total_pages): ?>
            <?php if ($ep_ < $total_pages - 1): ?><span class="vm-page-ellipsis">&hellip;</span><?php endif; ?>
            <a href="<?= page_url($total_pages, $filter_params) ?>" class="vm-page-btn"><?= $total_pages ?></a>
            <?php endif; ?>

            <?php if ($page < $total_pages): ?>
            <a href="<?= page_url($page + 1, $filter_params) ?>" class="vm-page-btn">&raquo;</a>
            <?php else: ?>
            <span class="vm-page-btn vm-page-disabled">&raquo;</span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
// ── Fit the marks list + pagination into the viewport, no page scroll ──
function fitMarksListHeight() {
    const scrollEl = document.querySelector('.marks-list');
    if (!scrollEl) return;
    if (window.innerWidth <= 640) {
        scrollEl.style.maxHeight = '';
        return;
    }
    const pagination = document.querySelector('.vm-pagination');
    const top = scrollEl.getBoundingClientRect().top;
    const paginationH = pagination ? pagination.offsetHeight : 0;
    const available = window.innerHeight - top - paginationH - 24; // 24px bottom breathing room
    scrollEl.style.maxHeight = Math.max(150, available) + 'px';
}
window.addEventListener('resize', fitMarksListHeight);
document.addEventListener('DOMContentLoaded', fitMarksListHeight);
</script>

</main>
</body>
</html>