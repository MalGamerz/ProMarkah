<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

session_start();
require __DIR__ . '/auth_check.php';
include 'db.php';
$conn = getDB();


if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pic') {
    header("Location: login.php");
    exit();
}

session_write_close();

$pm_page = 'directory';
include 'layout.php';

// Active tab: students_group | judges_school | groups_judge
$tab = $_GET['tab'] ?? 'students_group';

// Shared filter values
$filter_session = (int)($_GET['session_id'] ?? 0);
$filter_level   = (int)($_GET['level_id']   ?? 0);
$filter_school  = (int)($_GET['school_id']  ?? 0);
$filter_judge   = (int)($_GET['judge_id']   ?? 0);
$filter_group   = (int)($_GET['group_id']   ?? 0);
$search         = trim($_GET['search']      ?? '');

// Sort-by-name toggle (students_group + groups_judge tabs) — same cycle as
// pic_master_list.php ('' -> ASC -> DESC -> ''), but since this page has no
// AJAX partial refresh it's driven by a GET param + full page reload instead
// of client-side JS state.
$sort_name = $_GET['sort_name'] ?? '';
$sort_next = ($sort_name === '') ? 'ASC' : (($sort_name === 'ASC') ? 'DESC' : '');
$sortQ = $_GET;
$sortQ['tab'] = $tab;
$sortQ['sort_name'] = $sort_next;
$sortToggleUrl = 'pic_directory.php?' . http_build_query($sortQ);
$sortIconSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg>';
if ($sort_name === 'ASC') {
    $sortIconSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--c-red)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m6 15 6-6 6 6"/></svg>';
} elseif ($sort_name === 'DESC') {
    $sortIconSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--c-red)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>';
}
$sortToggleHtml = '<a href="' . htmlspecialchars($sortToggleUrl) . '" style="display:inline-flex;align-items:center;gap:6px;color:inherit;text-decoration:none;cursor:pointer;user-select:none;" title="Susun A-Z / Z-A">'
    . '<span style="font-size:0.72rem;font-weight:700;letter-spacing:.02em;">Nama Pelajar</span>'
    . '<span style="display:inline-flex;align-items:center;color:var(--c-text-faint);margin-top:1px;">' . $sortIconSvg . '</span>'
    . '</a>';

// Scope to "Siri Aktif" — schools/judges stay global (not siri-specific),
// but sessions/levels/groups all belong to one siri. When the sidebar has
// "Semua Siri" selected, show a Siri filter dropdown so the PIC can still
// narrow down to one siri (previously only Sidang could disambiguate,
// silently, via a label suffix — this makes the scoping explicit).
$active_siri      = (int)($_SESSION['active_siri_id'] ?? 0);
$show_siri_picker = ($active_siri === 0);
$filter_siri      = $show_siri_picker ? (int)($_GET['siri_id'] ?? 0) : $active_siri;
$eff_siri         = $filter_siri; // effective siri used for all scoping below

$siri_cond_s = $eff_siri > 0 ? "WHERE siri_id = $eff_siri" : "";
$siri_cond_l = $eff_siri > 0 ? "WHERE s.siri_id = $eff_siri" : "";
$siri_cond_g = $eff_siri > 0 ? "WHERE s.siri_id = $eff_siri" : "";

// Siri dropdown options (only rendered when $show_siri_picker is true)
$siriOpts = [];
$all_siri = $conn->query("SELECT siri_id, siri_name, siri_year FROM siri ORDER BY siri_year DESC, siri_name ASC");
while ($sr = $all_siri->fetch_assoc()) {
    $siriOpts[] = ['value' => $sr['siri_id'], 'label' => $sr['siri_name'] . ' (' . $sr['siri_year'] . ')'];
}

// Fetch dropdown data (used across tabs). When "Semua Siri" is active, tag
// each session with its siri so same-named ones aren't ambiguous.
$all_sessions = $eff_siri > 0
    ? $conn->query("SELECT * FROM sessions $siri_cond_s ORDER BY session_name")
    : $conn->query("SELECT se.*, si.siri_name FROM sessions se LEFT JOIN siri si ON se.siri_id = si.siri_id ORDER BY se.session_name");
// Only levels that actually have at least one group — a Peringkat with no
// Kumpulan under it is a dead end in every one of this page's tabs (nothing
// to select in the Kumpulan filter next, nothing to list in the results),
// so it's just noise in the dropdown.
$levelHasGroup = "EXISTS (SELECT 1 FROM `groups` lg WHERE lg.level_id = l.level_id)";
$levelWhere    = $siri_cond_l ? "$siri_cond_l AND $levelHasGroup" : "WHERE $levelHasGroup";
$all_levels   = $conn->query("SELECT l.*, s.session_name FROM levels l JOIN sessions s ON l.session_id=s.session_id $levelWhere ORDER BY s.session_name, l.level_name");
$all_schools  = $conn->query("SELECT * FROM schools ORDER BY school_name");
$all_judges   = $conn->query("SELECT * FROM judges ORDER BY name");
// Kumpulan options are scoped to the selected Peringkat (when one is
// chosen) instead of always listing every group in the siri/session —
// picking a Peringkat that has no groups of its own showing up in this
// list was the actual bug being fixed here.
$groupWhere = $siri_cond_g;
if ($filter_level) {
    $groupWhere = $groupWhere ? "$groupWhere AND g.level_id = $filter_level" : "WHERE g.level_id = $filter_level";
}
$all_groups   = $conn->query("SELECT g.*, s.session_name, l.level_name FROM `groups` g JOIN levels l ON g.level_id=l.level_id JOIN sessions s ON l.session_id=s.session_id $groupWhere ORDER BY s.session_name, l.level_name, g.group_name");

// Pre-built option-label arrays for the dd-wrap filter dropdowns below —
// built once here, alongside the mysqli results the rest of the page
// already reuses via data_seek(0).
$sessionOpts = [];
$all_sessions->data_seek(0);
while ($s = $all_sessions->fetch_assoc()) {
    $label = $s['session_name'];
    if ($active_siri === 0 && !empty($s['siri_name'])) $label .= ' — ' . $s['siri_name'];
    $sessionOpts[] = ['value' => $s['session_id'], 'label' => $label];
}
$all_sessions->data_seek(0);

$levelOpts = [];
$all_levels->data_seek(0);
while ($l = $all_levels->fetch_assoc()) {
    $levelOpts[] = ['value' => $l['level_id'], 'label' => $l['session_name'] . ' — ' . $l['level_name']];
}
$all_levels->data_seek(0);

$schoolOpts = [];
$all_schools->data_seek(0);
while ($sch = $all_schools->fetch_assoc()) {
    $schoolOpts[] = ['value' => $sch['school_id'], 'label' => $sch['school_name']];
}
$all_schools->data_seek(0);

$judgeOpts = [];
$all_judges->data_seek(0);
while ($j = $all_judges->fetch_assoc()) {
    $judgeOpts[] = ['value' => $j['id'], 'label' => $j['name']];
}
$all_judges->data_seek(0);

$groupOpts = [];
$all_groups->data_seek(0);
while ($g = $all_groups->fetch_assoc()) {
    $groupOpts[] = ['value' => $g['group_id'], 'label' => $g['session_name'] . ' / ' . $g['level_name'] . ' — ' . $g['group_name']];
}
$all_groups->data_seek(0);

// Renders one dd-wrap filter dropdown — a hidden input keeps the GET field
// name/value the form already submits, while the visible UI is the
// JS-rendered dropdown (no native <select> popup, which is what caused the
// black-flash lag).
function renderDirDD(string $fieldName, string $ddName, string $emptyLabel, array $options, $currentVal) {
    $currentVal = (string)$currentVal;
    echo "<div class=\"dd-wrap\" id=\"ddWrap_{$ddName}\">";
    echo "<div class=\"dd-trigger\" id=\"ddTrigger_{$ddName}\" onclick=\"ddToggle('{$ddName}')\">";
    $curLabel = $emptyLabel;
    foreach ($options as $opt) {
        if ((string)$opt['value'] === $currentVal && $currentVal !== '' && $currentVal !== '0') { $curLabel = $opt['label']; break; }
    }
    $labelColor = ($currentVal === '' || $currentVal === '0') ? 'color:var(--c-text-faint);' : '';
    echo "<span id=\"ddLabel_{$ddName}\" style=\"{$labelColor}\">" . htmlspecialchars($curLabel) . "</span>";
    echo "<span class=\"dd-arrow\">▼</span></div>";
    echo "<div class=\"dd-panel\" id=\"ddPanel_{$ddName}\">";
    echo "<div class=\"dd-search-box\"><input type=\"text\" placeholder=\"Cari...\" oninput=\"ddFilter('{$ddName}',this.value)\" onclick=\"event.stopPropagation()\"></div>";
    echo "<div class=\"dd-options\" id=\"ddOpts_{$ddName}\">";
    $emptySel = ($currentVal === '' || $currentVal === '0') ? 'selected' : '';
    echo "<div class=\"dd-opt {$emptySel}\" data-value=\"\" onclick=\"ddSelect('{$ddName}','','" . htmlspecialchars($emptyLabel, ENT_QUOTES) . "')\">" . htmlspecialchars($emptyLabel) . "</div>";
    foreach ($options as $opt) {
        $sel = ((string)$opt['value'] === $currentVal && $currentVal !== '' && $currentVal !== '0') ? 'selected' : '';
        $valEsc = htmlspecialchars((string)$opt['value'], ENT_QUOTES);
        $lblEsc = htmlspecialchars($opt['label'], ENT_QUOTES);
        echo "<div class=\"dd-opt {$sel}\" data-value=\"{$valEsc}\" onclick=\"ddSelect('{$ddName}','{$valEsc}','{$lblEsc}')\">" . htmlspecialchars($opt['label']) . "</div>";
    }
    echo "</div><div class=\"dd-empty\" id=\"ddEmpty_{$ddName}\">Tiada hasil</div></div>";
    echo "<input type=\"hidden\" name=\"{$fieldName}\" id=\"f_{$ddName}\" value=\"" . htmlspecialchars($currentVal === '0' ? '' : $currentVal) . "\">";
    echo "</div>";
}
?>

<style>
/* ── DIRECTORY PAGE ──────────────────────────────────────────── */

/* ── Tabs ── */
.dir-tabs-desktop {
    display: flex;
    gap: 4px;
    margin-bottom: 24px;
    background: var(--c-surface-1);
    border: 1px solid var(--c-border);
    border-radius: 10px;
    padding: 5px;
    flex-wrap: wrap;
}
.dir-tabs-mobile { display: none; }
.dir-tab {
    flex: 1;
    min-width: 140px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 14px;
    border-radius: 7px;
    border: none;
    background: transparent;
    color: var(--c-text-muted);
    font-family: 'DM Sans', sans-serif;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.18s ease;
    white-space: nowrap;
}
.dir-tab:hover { background: var(--c-surface-2); color: var(--c-white); }
.dir-tab.active {
    background: var(--c-red);
    color: #fff;
    box-shadow: 0 2px 12px rgba(214,40,40,0.35);
}
.dir-tab svg {
    width: 15px; height: 15px;
    stroke: currentColor; fill: none;
    stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
    flex-shrink: 0;
}

/* ── Filter bar ── */
.dir-filter-wrap {
    background: var(--c-surface-1);
    border: 1px solid var(--c-border-strong);
    border-radius: 10px;
    margin-bottom: 18px;
    /* No overflow:hidden here — it used to clip the dd-panel dropdown,
       which opens below its trigger via position:absolute and is outside
       this container's normal-flow height. The mobile collapse toggle
       button below still respects the rounded corners on its own. */
}
.dir-filter-toggle {
    display: none; /* hidden on desktop */
    width: 100%;
    background: none;
    border: none;
    padding: 12px 16px;
    color: var(--c-white);
    font-family: 'DM Sans', sans-serif;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    text-align: left;
    align-items: center;
    gap: 8px;
    user-select: none;
}
.dir-filter-toggle svg { width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;transition:transform 0.2s; }
.dir-filter-toggle.open svg.chevron { transform: rotate(180deg); }
.dir-filter-body { padding: 16px 20px; }
/* Same flex-equal-share layout as leaderboard.php's .filter-bar/.filter-col
   — every field (including the search input) gets an identical flex-basis
   of 0 with the same flex-grow, so all columns end up exactly the same
   width no matter how long their label or content is, instead of the old
   grid's minmax(160px,1fr) tracks drifting uneven widths.
   NOTE: no overflow-x:auto here (unlike leaderboard.php's .filter-bar) —
   this component's dropdown panel (.dd-panel) is position:absolute *inside*
   each .dd-wrap column, not appended to <body> like leaderboard's Select2
   panels are. Setting overflow-x forces overflow-y non-visible too (CSS
   spec: the two can't be visible/non-visible mismatched), which clips
   .dd-panel into a tiny scrollable box instead of letting it drop open
   normally — that's the "scrolling box" glitch under the search field. */
.dir-filter {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: flex-end;
}
.dir-filter > div {
    flex: 1 1 0;
    min-width: 110px;
}
.dir-filter label {
    display: block;
    font-size: 0.72rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--c-text-faint);
    margin-bottom: 4px;
}
.dir-filter input,
.dir-filter select {
    background: var(--c-surface-2);
    border: 1px solid var(--c-border-strong);
    color: var(--c-white);
    border-radius: 6px;
    padding: 0 10px;
    /* Must match .dd-trigger's *actual* rendered height, which is the
       global canonical rule in dashboard.css (32px !important) — this
       page's own local .dd-trigger{height:38px} below is dead, silently
       overridden by that !important, so 38px here looked right on paper
       but was visibly taller than the real dropdowns. */
    height: 32px;
    font-size: 0.8rem;
    font-family: 'DM Sans', sans-serif;
    outline: none;
    width: 100%;
    box-sizing: border-box;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.dir-filter input:focus, .dir-filter select:focus {
    border-color: var(--c-red);
    box-shadow: 0 0 0 3px var(--c-red-dim);
}
.dir-filter input::placeholder { color: var(--c-text-faint); }
.dir-filter select option { background: var(--c-surface-2); color: var(--c-white); }

/* ── Cari Pelajar autocomplete — live name suggestions while typing,
   same visual pattern as pic_master_list.php's Carian Nama dropdown.
   Unlike that one (readonly trigger, click-to-open, type-inside-the-box),
   this one is typed into directly — the field itself IS the form's
   name="search" input, so no separate hidden field/sync step is needed. ── */
.name-dropdown-wrapper { position: relative; }
.name-dropdown-box {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    background: var(--c-surface-2);
    border: 1px solid var(--c-border-strong);
    border-radius: 6px;
    z-index: 100;
    max-height: 220px;
    overflow-y: auto;
    box-shadow: 0 8px 24px rgba(0,0,0,0.25);
    display: none;
}
.name-dropdown-box.open { display: block; }
.name-dropdown-box .dd-option {
    padding: 8px 12px;
    cursor: pointer;
    font-size: 0.85rem;
    color: var(--c-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.name-dropdown-box .dd-option:hover,
.name-dropdown-box .dd-option.selected {
    background: var(--c-red-dim);
    color: var(--c-red);
}
.name-dropdown-box .dir-name-empty {
    /* Own class, not .dd-empty — that name is already used by the
       unrelated dd-panel system further down with display:none baked in,
       which would leak into (and hide) this element too since this rule
       never redeclares display. */
    display: block;
    padding: 10px 12px;
    font-size: 0.82rem;
    color: var(--c-text-faint);
    text-align: center;
}
html.pm-light .name-dropdown-box { background: #fff; border-color: var(--c-gray-300); box-shadow: 0 6px 20px rgba(0,0,0,0.12); }
html.pm-light .name-dropdown-box .dd-option { color: #111; }

/* ── Section (accordion) ── */
.dir-section {
    background: var(--c-surface-1);
    border: 1px solid var(--c-border);
    border-radius: 10px;
    margin-bottom: 14px;
    overflow: hidden;
}
.dir-section-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 18px;
    cursor: pointer;
    user-select: none;
    transition: background 0.15s;
    -webkit-tap-highlight-color: transparent;
}
.dir-section-header:hover { background: var(--c-surface-2); }
.dir-section-icon {
    width: 34px; height: 34px;
    background: var(--c-red-dim);
    border: 1px solid var(--c-red-border);
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.dir-section-icon svg {
    width: 16px; height: 16px;
    stroke: var(--c-red); fill: none;
    stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
}
.dir-section-info { flex: 1; min-width: 0; }
.dir-section-title {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 1.05rem;
    letter-spacing: 0.06em;
    color: var(--c-white);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.dir-section-meta {
    font-size: 0.75rem;
    color: var(--c-text-faint);
    font-weight: 400;
    font-family: 'DM Sans', sans-serif;
    margin-top: 1px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.dir-section-badge {
    background: var(--c-red-dim);
    border: 1px solid var(--c-red-border);
    color: var(--c-red);
    font-size: 0.72rem;
    font-weight: 700;
    padding: 2px 10px;
    border-radius: 999px;
    white-space: nowrap;
    flex-shrink: 0;
}
.dir-section-arrow {
    color: var(--c-text-faint);
    font-size: 0.8rem;
    transition: transform 0.2s;
    flex-shrink: 0;
}
.dir-section-body { display: none; }

/* ── PAGINATION: shared .vm-pagination styles now live once in
   dashboard.css (loaded by layout.php), used by every paginated page. ── */

/* ── Group card ── */
.dir-group-card {
    margin: 0 14px 12px;
    background: var(--c-surface-0);
    border: 1px solid var(--c-border);
    border-left: 3px solid var(--c-red);
    border-radius: 8px;
    overflow: hidden;
}
.dir-group-header {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    background: rgba(214,40,40,0.04);
    border-bottom: 1px solid var(--c-border);
    flex-wrap: wrap;
}
.dir-group-name {
    font-weight: 700;
    color: var(--c-white);
    font-size: 0.9rem;
    flex: 1;
    min-width: 100px;
}
.dir-group-judge {
    font-size: 0.78rem;
    color: var(--c-text-faint);
    display: flex;
    align-items: center;
    gap: 5px;
}
.dir-group-judge svg {
    width: 12px; height: 12px;
    stroke: var(--c-red); fill: none; stroke-width: 2;
    stroke-linecap: round; stroke-linejoin: round;
    flex-shrink: 0;
}

/* ── Student list ── */
.dir-student-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 6px;
    padding: 10px 12px 12px;
}
.dir-student-chip {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 7px 10px;
    background: var(--c-surface-1);
    border: 1px solid var(--c-border);
    border-radius: 6px;
    font-size: 0.83rem;
    color: var(--c-text-muted);
    transition: background 0.14s;
}
.dir-student-chip:hover { background: var(--c-surface-2); color: var(--c-white); }
.dir-student-name {
    font-weight: 600;
    font-size: 0.9rem;
}
.dir-student-no {
    font-family: 'DM Mono', monospace;
    font-size: 0.72rem;
    color: var(--c-text-faint);
    background: var(--c-surface-2);
    border-radius: 4px;
    padding: 1px 5px;
    min-width: 22px;
    text-align: center;
    flex-shrink: 0;
}
.dir-gender-badge {
    font-size: 0.68rem;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: 999px;
    flex-shrink: 0;
}
.dir-gender-m { background: rgba(96,165,250,0.15); color: #93C5FD; border: 1px solid rgba(96,165,250,0.2); }
.dir-gender-f { background: rgba(244,114,182,0.15); color: #F9A8D4; border: 1px solid rgba(244,114,182,0.2); }

/* ── Judge cards (Tab 2) ── */
.dir-judge-card {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 14px 16px;
    border-bottom: 1px solid var(--c-border);
}
.dir-judge-card:last-child { border-bottom: none; }
.dir-judge-avatar {
    width: 38px; height: 38px;
    background: var(--c-surface-2);
    border: 1px solid var(--c-border-strong);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}
.dir-judge-name {
    font-weight: 700;
    color: var(--c-white);
    font-size: 0.9rem;
    margin-bottom: 3px;
}
.dir-judge-detail {
    font-size: 0.8rem;
    color: var(--c-text-faint);
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.dir-judge-pill {
    background: var(--c-surface-2);
    border: 1px solid var(--c-border);
    color: var(--c-text-muted);
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
}

/* ── Empty state ── */
.dir-empty {
    text-align: center;
    padding: 48px 24px;
    color: var(--c-text-faint);
    font-size: 0.9rem;
}
.dir-empty-icon { font-size: 2.5rem; margin-bottom: 12px; }

/* ── Summary bar ── */
.dir-summary {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    padding: 10px 16px;
    background: var(--c-surface-1);
    border: 1px solid var(--c-border);
    border-radius: 8px;
    margin-bottom: 18px;
    font-size: 0.83rem;
    color: var(--c-text-muted);
}
.dir-summary-item { display: flex; align-items: center; gap: 6px; }
.dir-summary-val { font-family: 'DM Mono', monospace; color: var(--c-white); font-size: 1rem; font-weight: 700; }
.dir-print-btn { margin-left: auto; display: flex; align-items: center; gap: 6px; }

/* ── Responsive ── */
@media (max-width: 640px) {
    /* Tabs stack vertically */
    .dir-tabs-desktop { flex-direction: column; gap: 3px; margin-bottom: 16px; }
    .dir-tab { flex: unset; width: 100%; justify-content: flex-start; }

    /* Collapsible filter on mobile */
    .dir-filter-toggle { display: flex; }
    .dir-filter-body { padding: 0 16px 16px; }
    .dir-filter-body.collapsed { display: none; }

    .dir-filter { gap: 10px; }
    .dir-filter > div { flex: 1 1 100%; min-width: 0; }

    /* Sections: tighter */
    .dir-section-header { padding: 12px 14px; gap: 10px; }
    .dir-section-icon { width: 30px; height: 30px; }
    .dir-section-icon svg { width: 14px; height: 14px; }
    .dir-section-title { font-size: 0.95rem; }
    .dir-section-badge { display: none; } /* badge shown in meta on mobile */
    .dir-section-meta::after { content: ''; }

    /* Group cards */
    .dir-group-card { margin: 0 10px 10px; }
    .dir-group-header { padding: 8px 12px; gap: 6px; }
    .dir-group-judge { display: none; } /* save space */

    /* Student grid: 1 column on small phones */
    .dir-student-list { grid-template-columns: 1fr; padding: 8px 10px 10px; gap: 4px; }

    /* Summary */
    .dir-summary { padding: 8px 12px; gap: 10px; }
    .dir-print-btn { display: none; } /* hide print on mobile */

}

@media (min-width: 641px) and (max-width: 1024px) {
    .dir-filter > div { flex: 1 1 calc(50% - 8px); }
    .dir-student-list { grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); }
}

@media print {
    .pm-sidebar, .pm-header, .dir-tabs-desktop, .dir-tabs-mobile,
    .dir-filter-wrap, .dir-print-btn, .vm-pagination,
    .pm-backdrop, .pm-overlay { display: none !important; }
    .pm-main { margin-left: 0 !important; padding: 16px !important; }
    .dir-section-body { display: block !important; }
    .dir-section { break-inside: avoid; }
    .dir-group-card { break-inside: avoid; }
}

/* ── Searchable dropdown (avoids native <select> popup — Chromium/GPU
   renders the OS listbox solid black for a moment before painting) ── */
.dd-wrap { position: relative; }
.dd-trigger{
    display:flex;
    align-items:center;
    justify-content:space-between;
    /* dashboard.css's canonical .dd-trigger rule overrides this with
       height:32px !important — kept in sync here so this local copy isn't
       misleading about the actual rendered size. */
    height:32px;
    box-sizing:border-box;
    width:100%;
    padding:0 10px;
    background:var(--c-surface-2);
    border:1px solid var(--c-border-strong);
    border-radius:6px;
    color:var(--c-white);
    font-size:.8rem;
    cursor:pointer;
    user-select:none;
    transition: border-color .2s, box-shadow .2s, background .2s;
}
.dd-trigger:hover{ border-color:var(--c-red); }
.dd-trigger.open{ border-color:var(--c-red); box-shadow:0 0 0 3px var(--c-red-dim); }
.dd-trigger .dd-arrow { color: var(--c-text-faint); font-size: 0.7rem; transition: transform .2s; }
.dd-trigger.open .dd-arrow { transform: rotate(180deg); }

.dd-panel{
    display:none;
    position:absolute;
    top:calc(100% + 6px);
    left:0;
    right:0;
    background:var(--c-surface-2);
    border:1px solid var(--c-red);
    border-radius:8px;
    overflow:hidden;
    z-index:999;
    box-shadow:0 10px 30px rgba(0,0,0,.25);
}
.dd-panel.open{ display:block; }
.dd-search-box { padding: 8px; border-bottom: 1px solid var(--c-border-strong); }
.dd-search-box input {
    width: 100%; background: var(--c-surface-0); border: 1px solid var(--c-border-strong);
    color: var(--c-white); border-radius: 4px; padding: 6px 8px; font-size: 0.8rem;
    outline: none; box-sizing: border-box; transition: border-color .2s;
}
.dd-search-box input:focus { border-color: var(--c-red); }
.dd-search-box input::placeholder { color: var(--c-text-faint); }
.dd-options { max-height: 220px; overflow-y: auto; scrollbar-width: thin; }
.dd-opt { padding: 9px 12px; font-size: 0.875rem; color: var(--c-white); cursor: pointer; transition: background .1s; }
.dd-opt:hover { background: var(--c-surface-3); }
.dd-opt.selected { color: var(--c-red); font-weight: 600; }
.dd-opt.hidden { display: none; }
.dd-empty { padding: 10px 12px; color: var(--c-text-faint); font-size: 0.82rem; display: none; text-align: center; }

html.pm-light .dd-trigger { background: var(--c-gray-50); border-color: var(--c-gray-300); color: #111; }
html.pm-light .dd-panel { background: #fff; box-shadow: 0 6px 20px rgba(0,0,0,0.12); }
html.pm-light .dd-search-box { border-bottom-color: var(--c-gray-200); }
html.pm-light .dd-search-box input { background: var(--c-gray-50); border-color: var(--c-gray-300); color: #111; }
html.pm-light .dd-opt { color: #111; }
html.pm-light .dd-opt:hover { background: var(--c-gray-100); }
/* Cari Pelajar text input was left on its dark-theme surface colour in
   light mode (no override existed here, unlike .dd-trigger just above),
   so it read as a greyed-out/disabled box next to the properly-themed
   dropdown triggers beside it. */
html.pm-light .dir-filter input { background: var(--c-gray-50); border-color: var(--c-gray-300); color: #111; }
html.pm-light .dir-filter input::placeholder { color: var(--c-gray-400); }
</style>

<div class="pic-overview-header" style="margin-bottom:20px;">
    <h2 style="font-family:'Bebas Neue',sans-serif;font-size:1.8rem;color:var(--c-white);letter-spacing:0.05em;margin-bottom:4px;">
        📁 Direktori &amp; Rujukan
    </h2>
    <p style="color:var(--c-text-faint);font-size:0.875rem;margin:0;">
        Senarai pelajar mengikut kumpulan, juri mengikut cawangan, dan kumpulan mengikut juri.
    </p>
</div>

<div class="dir-tabs-desktop">
    <a href="?tab=students_group" class="dir-tab <?= $tab === 'students_group' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        Pelajar Mengikut Kumpulan
    </a>
    <a href="?tab=judges_school" class="dir-tab <?= $tab === 'judges_school' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24"><path d="M2 22V10l10-8 10 8v12"/><path d="M12 22V15"/><path d="M7 22v-4h10v4"/><path d="M7 11h10"/></svg>
        Juri Mengikut Cawangan
    </a>
    <a href="?tab=groups_judge" class="dir-tab <?= $tab === 'groups_judge' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24"><path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1z"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1z"/><path d="M7 21h10"/><line x1="12" y1="3" x2="12" y2="21"/><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"/></svg>
        Kumpulan Mengikut Juri
    </a>
</div>

<div class="dir-tabs-mobile">
    <select onchange="location.href=this.value">
        <option value="?tab=students_group" <?= $tab === 'students_group' ? 'selected' : '' ?>>👥 Pelajar Mengikut Kumpulan</option>
        <option value="?tab=judges_school"  <?= $tab === 'judges_school'  ? 'selected' : '' ?>>🏫 Juri Mengikut Cawangan</option>
        <option value="?tab=groups_judge"   <?= $tab === 'groups_judge'   ? 'selected' : '' ?>>⚖️ Kumpulan Mengikut Juri</option>
    </select>
</div>

<?php

// ══════════════════════════════════════════════════════════════════
//  TAB 1 — STUDENTS BY GROUP
// ══════════════════════════════════════════════════════════════════
if ($tab === 'students_group'):

    // Build WHERE for groups
    $gWhere = ["1=1"];
    $gTypes = ''; $gVals = [];
    if ($filter_session) { $gWhere[] = "g.level_id IN (SELECT level_id FROM levels WHERE session_id = ?)"; $gTypes .= 'i'; $gVals[] = $filter_session; }
    if ($filter_level)   { $gWhere[] = "g.level_id = ?";   $gTypes .= 'i'; $gVals[] = $filter_level; }
    if ($filter_group)   { $gWhere[] = "g.group_id = ?";   $gTypes .= 'i'; $gVals[] = $filter_group; }
    if ($eff_siri)    { $gWhere[] = "g.level_id IN (SELECT level_id FROM levels WHERE session_id IN (SELECT session_id FROM sessions WHERE siri_id = ?))"; $gTypes .= 'i'; $gVals[] = $eff_siri; }
    $gWhereSql = implode(' AND ', $gWhere);

    // Counts for summary
    $cntSql = "SELECT COUNT(DISTINCT gs.student_id) as stu, COUNT(DISTINCT g.group_id) as grp, COUNT(DISTINCT gl.session_id) as sess
               FROM `groups` g LEFT JOIN group_students gs ON g.group_id=gs.group_id LEFT JOIN levels gl ON g.level_id=gl.level_id WHERE $gWhereSql";
    $cntStmt = $conn->prepare($cntSql);
    if ($gTypes) $cntStmt->bind_param($gTypes, ...$gVals);
    $cntStmt->execute();
    $cnt = $cntStmt->get_result()->fetch_assoc();
    $cntStmt->close();
?>

<div class="dir-filter-wrap">
<button class="dir-filter-toggle" id="ftog1" onclick="dirFilterToggle('ftog1','fbody1')">
    <svg viewBox="0 0 24 24"><line x1="4" y1="6" x2="20" y2="6"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="11" y1="18" x2="13" y2="18"/></svg>
    Tapisan &amp; Carian
    <svg class="chevron" viewBox="0 0 24 24" style="margin-left:auto;"><polyline points="6 9 12 15 18 9"/></svg>
</button>
<div class="dir-filter-body" id="fbody1">
<form method="GET" class="dir-filter">
    <input type="hidden" name="tab" value="students_group">
    <div class="name-dropdown-wrapper" id="nameDropdownWrapper">
        <label>Cari Pelajar</label>
        <input type="text" name="search" id="dirNameSearch" class="pm-auto-input" placeholder="Nama pelajar..." value="<?= htmlspecialchars($search) ?>" autocomplete="off"
            oninput="dirFilterNameOptions(this.value)" onfocus="dirFilterNameOptions(this.value)">
        <div class="name-dropdown-box" id="nameDropdownBox">
            <div id="nameDropdownList"></div>
        </div>
    </div>
    <?php if ($show_siri_picker): ?>
    <div>
        <label>Siri</label>
        <?php renderDirDD('siri_id', 'siri1', 'Semua Siri', $siriOpts, $filter_siri); ?>
    </div>
    <?php endif; ?>
    <div>
        <label>Sidang</label>
        <?php renderDirDD('session_id', 'session1', 'Semua Sidang', $sessionOpts, $filter_session); ?>
    </div>
    <div>
        <label>Peringkat</label>
        <?php renderDirDD('level_id', 'level1', 'Semua Peringkat', $levelOpts, $filter_level); ?>
    </div>
    <div>
        <label>Kumpulan</label>
        <?php renderDirDD('group_id', 'group1', 'Semua Kumpulan', $groupOpts, $filter_group); ?>
    </div>
    </form>
</div>
</div>

<div class="dir-summary">
    <div class="dir-summary-item">
        <span class="dir-summary-val"><?= (int)$cnt['stu'] ?></span>
        <span>Pelajar</span>
    </div>
    <div class="dir-summary-item" style="color:var(--c-border-strong);">|</div>
    <div class="dir-summary-item">
        <span class="dir-summary-val"><?= (int)$cnt['grp'] ?></span>
        <span>Kumpulan</span>
    </div>
    <div class="dir-summary-item" style="color:var(--c-border-strong);">|</div>
    <div class="dir-summary-item">
        <span class="dir-summary-val"><?= (int)$cnt['sess'] ?></span>
        <span>Sidang</span>
    </div>
    <div class="dir-summary-item" style="color:var(--c-border-strong);">|</div>
    <div class="dir-summary-item"><?= $sortToggleHtml ?></div>
    <div class="dir-print-btn">
        <button onclick="window.print()" class="pm-btn pm-btn-ghost" style="font-size:0.8rem;">🖨 Cetak</button>
    </div>
</div>

<?php
    // Load all session+level combos that have groups matching filter (flat, no hierarchy)
    $lvlSql = "SELECT DISTINCT s.session_id, s.session_name, l.level_id, l.level_name, si.siri_name
               FROM `groups` g
               JOIN levels l ON g.level_id = l.level_id
               JOIN sessions s ON l.session_id = s.session_id
               LEFT JOIN siri si ON s.siri_id = si.siri_id
               WHERE $gWhereSql
               ORDER BY s.session_name, l.level_name";
    $lvlStmt = $conn->prepare($lvlSql);
    if ($gTypes) $lvlStmt->bind_param($gTypes, ...$gVals);
    $lvlStmt->execute();
    $levels_list = $lvlStmt->get_result();
    $lvlStmt->close();

    if ($levels_list->num_rows === 0):
?>
    <div class="dir-empty">
        <div class="dir-empty-icon">📭</div>
        Tiada kumpulan ditemui untuk tapisan ini.
    </div>
<?php
    else:
?>
<div id="dir-sections-wrapper">
<?php
    while ($lvl = $levels_list->fetch_assoc()):
        $sess_id = $lvl['session_id'];
        $lvl_id  = $lvl['level_id'];

        // Groups in this level — no separate session_id check needed since
        // g.level_id=? already pins the session (a level belongs to exactly
        // one session).
        $grpSql = "SELECT g.*, j.name as judge_name
                   FROM `groups` g
                   LEFT JOIN judges j ON g.judge_id=j.id
                   WHERE g.level_id=?
                   " . ($filter_group ? " AND g.group_id=?" : "") . "
                   ORDER BY g.group_name";
        $grpBind = 'i'; $grpVals2 = [$lvl_id];
        if ($filter_group) { $grpBind .= 'i'; $grpVals2[] = $filter_group; }
        $grpStmt = $conn->prepare($grpSql);
        $grpStmt->bind_param($grpBind, ...$grpVals2);
        $grpStmt->execute();
        $groups_list = $grpStmt->get_result();
        $grpStmt->close();

        // Badge count reuses the result set already fetched above — no need
        // for a second COUNT(*) query with the exact same WHERE conditions.
        $grpCount = $groups_list->num_rows;

        $lvl_uid = "lvl_{$sess_id}_{$lvl_id}";
?>
    <div class="dir-section">
        <div class="dir-section-header" onclick="dirToggle('<?= $lvl_uid ?>')">
            <div class="dir-section-icon">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
            </div>
            <div class="dir-section-info">
                <div class="dir-section-title"><?= htmlspecialchars($lvl['level_name']) ?></div>
                <div class="dir-section-meta">📅 <?= htmlspecialchars($lvl['session_name']) ?><?php if ($show_siri_picker): ?> · 🗓️ <?= htmlspecialchars($lvl['siri_name'] ?? '— Tiada Siri —') ?><?php endif; ?></div>
            </div>
            <span class="dir-section-badge"><?= $grpCount ?> Kumpulan</span>
            <span class="dir-section-arrow">▶</span>
        </div>
        <div class="dir-section-body" id="<?= $lvl_uid ?>" style="padding-bottom:8px;">
<?php
        while ($g = $groups_list->fetch_assoc()):
            $gid = $g['group_id'];

            // Students in this group
            $stOrderBy = $sort_name === 'ASC' ? "st.student_name ASC" : ($sort_name === 'DESC' ? "st.student_name DESC" : "st.student_id");
            $stSql = "SELECT st.student_id, st.student_name, st.gender, st.year
                      FROM group_students gs
                      JOIN students st ON gs.student_id=st.student_id
                      WHERE gs.group_id=?
                      " . ($search ? " AND st.student_name LIKE ?" : "") . "
                      ORDER BY $stOrderBy";
            $stBind = 'i'; $stVals = [$gid];
            if ($search) { $stBind .= 's'; $stVals[] = '%' . $search . '%'; }
            $stStmt = $conn->prepare($stSql);
            $stStmt->bind_param($stBind, ...$stVals);
            $stStmt->execute();
            $students_list = $stStmt->get_result();
            $stStmt->close();

            if ($students_list->num_rows === 0 && $search) continue; // hide empty groups when searching
?>
            <div class="dir-group-card">
                <div class="dir-group-header">
                    <span class="dir-group-name">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--c-red)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;margin-right:4px;"><line x1="6" y1="3" x2="6" y2="15"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M18 9a9 9 0 0 1-9 9"/></svg>
                        <?= htmlspecialchars($g['group_name']) ?>
                    </span>
                    <span class="dir-group-judge">
                        <svg viewBox="0 0 24 24"><path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1z"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1z"/><path d="M7 21h10"/><line x1="12" y1="3" x2="12" y2="21"/><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"/></svg>
                        <?= $g['judge_name'] ? htmlspecialchars($g['judge_name']) : '<span style="color:var(--c-text-faint);font-style:italic;">Tiada Juri</span>' ?>
                    </span>
                    <span style="background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:999px;font-size:0.72rem;font-weight:700;color:var(--c-text-muted);padding:2px 8px;white-space:nowrap;">
                        <?= $students_list->num_rows ?> pelajar
                    </span>
                </div>
                <?php if ($students_list->num_rows > 0): ?>
                <div class="dir-student-list">
                    <?php $no = 1; while ($st = $students_list->fetch_assoc()): ?>
                    <div class="dir-student-chip">
                        <span class="dir-student-no"><?= $no++ ?></span>
                        <span class="dir-student-name" style="flex:1;color:var(--c-white);"><?= htmlspecialchars($st['student_name']) ?></span>
                        <span class="dir-gender-badge <?= $st['gender'] === 'Male' ? 'dir-gender-m' : 'dir-gender-f' ?>">
                            <?= $st['gender'] === 'Male' ? 'L' : 'P' ?>
                        </span>
                        <span style="font-size:0.72rem;color:var(--c-text-faint);font-family:'DM Mono',monospace;"><?= htmlspecialchars($st['year']) ?></span>
                    </div>
                    <?php endwhile; ?>
                </div>
                <?php else: ?>
                <div style="padding:14px 16px;color:var(--c-text-faint);font-size:0.83rem;font-style:italic;">Tiada pelajar dalam kumpulan ini.</div>
                <?php endif; ?>
            </div>
<?php       endwhile; /* groups */ ?>
        </div>
    </div>
<?php
    endwhile; /* levels */
?>
</div><!-- /dir-sections-wrapper -->
<?php
    endif;

// ══════════════════════════════════════════════════════════════════
//  TAB 2 — JUDGES BY SCHOOL (CAWANGAN)
// ══════════════════════════════════════════════════════════════════
elseif ($tab === 'judges_school'):
?>

<div class="dir-filter-wrap">
<button class="dir-filter-toggle" id="ftog2" onclick="dirFilterToggle('ftog2','fbody2')">
    <svg viewBox="0 0 24 24"><line x1="4" y1="6" x2="20" y2="6"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="11" y1="18" x2="13" y2="18"/></svg>
    Tapisan &amp; Carian
    <svg class="chevron" viewBox="0 0 24 24" style="margin-left:auto;"><polyline points="6 9 12 15 18 9"/></svg>
</button>
<div class="dir-filter-body" id="fbody2">
<form method="GET" class="dir-filter">
    <input type="hidden" name="tab" value="judges_school">
    <div>
        <label>Cari Cawangan / Juri</label>
        <input type="text" name="search" class="pm-auto-input" placeholder="Nama cawangan atau juri..." value="<?= htmlspecialchars($search) ?>" autocomplete="off">
    </div>
    <?php if ($show_siri_picker): ?>
    <div>
        <label>Siri</label>
        <?php renderDirDD('siri_id', 'siri2', 'Semua Siri', $siriOpts, $filter_siri); ?>
    </div>
    <?php endif; ?>
    <div>
        <label>Sidang</label>
        <?php renderDirDD('session_id', 'session2', 'Semua Sidang', $sessionOpts, $filter_session); ?>
    </div>
    <div>
        <label>Cawangan</label>
        <?php renderDirDD('school_id', 'school2', 'Semua Cawangan', $schoolOpts, $filter_school); ?>
    </div>
    </form>
</div>
</div>


<?php
    /*
     * Logic Fix: A judge is connected to a school IF they are judging a group 
     * that contains a student from that school.
     * Path: Judge -> Group -> Group_Students -> Student -> School
     */
    $schWhere = ["j.id IS NOT NULL"];
    $schTypes = ''; $schVals = [];
    if ($filter_session) { $schWhere[] = "g.level_id IN (SELECT level_id FROM levels WHERE session_id = ?)"; $schTypes .= 'i'; $schVals[] = $filter_session; }
    if ($filter_school)  { $schWhere[] = "sc.school_id = ?";  $schTypes .= 'i'; $schVals[] = $filter_school; }
    if ($search) {
        $schWhere[] = "(sc.school_name LIKE ? OR j.name LIKE ?)";
        $schTypes .= 'ss'; $schVals[] = '%'.$search.'%'; $schVals[] = '%'.$search.'%';
    }
    if ($eff_siri) { $schWhere[] = "g.level_id IN (SELECT level_id FROM levels WHERE session_id IN (SELECT session_id FROM sessions WHERE siri_id = ?))"; $schTypes .= 'i'; $schVals[] = $eff_siri; }
    $schWhereSql = implode(' AND ', $schWhere);

    // Get distinct schools that have students in groups assigned to a judge
    $schoolsSql = "SELECT DISTINCT sc.school_id, sc.school_name
                   FROM schools sc
                   JOIN students st ON sc.school_id = st.school_id
                   JOIN group_students gs ON st.student_id = gs.student_id
                   JOIN `groups` g ON gs.group_id = g.group_id
                   JOIN judges j ON g.judge_id = j.id
                   WHERE $schWhereSql
                   ORDER BY sc.school_name";
    $schoolsStmt = $conn->prepare($schoolsSql);
    if ($schTypes) $schoolsStmt->bind_param($schTypes, ...$schVals);
    $schoolsStmt->execute();
    $schools_result = $schoolsStmt->get_result();
    $schoolsStmt->close();

    if ($schools_result->num_rows === 0):
?>
    <div class="dir-empty">
        <div class="dir-empty-icon">🏫</div>
        Tiada cawangan ditemui atau belum ada penugasan juri.
    </div>
<?php
    else:
        // Buffer all school rows and batch the "total students in this school"
        // count into one query (grouped by school_id) instead of running a
        // separate count query per school inside the loop below.
        $schoolRows = [];
        while ($r = $schools_result->fetch_assoc()) $schoolRows[] = $r;

        $stuCountBySchool = [];
        $schoolIds = array_column($schoolRows, 'school_id');
        if (!empty($schoolIds)) {
            $scPlaceholders = implode(',', array_fill(0, count($schoolIds), '?'));
            $scSql = "SELECT st.school_id, COUNT(DISTINCT st.student_id) as t
                      FROM students st
                      WHERE st.school_id IN ($scPlaceholders)" . ($filter_session ? " AND st.student_id IN (
                          SELECT st2.student_id FROM students st2 JOIN levels l ON st2.level_id = l.level_id WHERE l.session_id = ?
                          UNION
                          SELECT gs2.student_id FROM group_students gs2 JOIN `groups` g2 ON gs2.group_id = g2.group_id JOIN levels gl2 ON g2.level_id = gl2.level_id WHERE gl2.session_id = ?
                      )" : "") . "
                      GROUP BY st.school_id";
            $scTypes = str_repeat('i', count($schoolIds));
            $scVals = $schoolIds;
            if ($filter_session) { $scTypes .= 'ii'; $scVals[] = $filter_session; $scVals[] = $filter_session; }
            $scStmt = $conn->prepare($scSql);
            $scStmt->bind_param($scTypes, ...$scVals);
            $scStmt->execute();
            $scRes = $scStmt->get_result();
            $scStmt->close();
            while ($row = $scRes->fetch_assoc()) $stuCountBySchool[$row['school_id']] = $row['t'];
        }
?>
<div id="dir-sections-wrapper">
<?php
    foreach ($schoolRows as $sch):
        $sch_id = $sch['school_id'];

        // Judges assigned to groups that contain students from this specific school
        $judgeWhere = ["st.school_id = ?"];
        $judgeTypes = 'i'; $judgeVals = [$sch_id];
        if ($filter_session) { $judgeWhere[] = "l.session_id = ?"; $judgeTypes .= 'i'; $judgeVals[] = $filter_session; }
        if ($eff_siri)    { $judgeWhere[] = "l.session_id IN (SELECT session_id FROM sessions WHERE siri_id = ?)"; $judgeTypes .= 'i'; $judgeVals[] = $eff_siri; }

        $judgesSql = "SELECT j.id as judge_id, j.name as judge_name,
                             GROUP_CONCAT(DISTINCT se.session_name ORDER BY se.session_name SEPARATOR ', ') as sessions,
                             GROUP_CONCAT(DISTINCT si.siri_name ORDER BY si.siri_name SEPARATOR ', ') as siris,
                             GROUP_CONCAT(DISTINCT l.level_name ORDER BY l.level_name SEPARATOR ', ') as levels,
                             COUNT(DISTINCT g.group_id) as group_count,
                             COUNT(DISTINCT st.student_id) as student_count
                      FROM judges j
                      JOIN `groups` g ON g.judge_id = j.id
                      JOIN levels l ON g.level_id = l.level_id
                      JOIN sessions se ON l.session_id = se.session_id
                      LEFT JOIN siri si ON se.siri_id = si.siri_id
                      JOIN group_students gs ON g.group_id = gs.group_id
                      JOIN students st ON gs.student_id = st.student_id
                      WHERE " . implode(' AND ', $judgeWhere) . "
                      GROUP BY j.id
                      ORDER BY j.name";
        $judgesStmt = $conn->prepare($judgesSql);
        $judgesStmt->bind_param($judgeTypes, ...$judgeVals);
        $judgesStmt->execute();
        $judges_result = $judgesStmt->get_result();
        $judgesStmt->close();

        // Total students in this school — pre-computed once for all schools
        // above instead of a separate query per school here.
        $stuCount = $stuCountBySchool[$sch_id] ?? 0;
?>
    <div class="dir-section">
        <div class="dir-section-header" onclick="dirToggle('school_j_<?= $sch_id ?>')">
            <div class="dir-section-icon">
                <svg viewBox="0 0 24 24"><path d="M2 22V10l10-8 10 8v12"/><path d="M12 22V15"/><path d="M7 22v-4h10v4"/><path d="M7 11h10"/></svg>
            </div>
            <div class="dir-section-info">
                <div class="dir-section-title"><?= htmlspecialchars($sch['school_name']) ?></div>
                <div class="dir-section-meta"><?= $stuCount ?> pelajar · <?= $judges_result->num_rows ?> juri</div>
            </div>
            <span class="dir-section-badge"><?= $judges_result->num_rows ?> Juri</span>
            <span class="dir-section-arrow">▶</span>
        </div>
        <div class="dir-section-body" id="school_j_<?= $sch_id ?>">
<?php       while ($j = $judges_result->fetch_assoc()): ?>
            <div class="dir-judge-card">
                <div class="dir-judge-avatar">⚖️</div>
                <div style="flex:1;">
                    <div class="dir-judge-name"><?= htmlspecialchars($j['judge_name']) ?></div>
                    <div class="dir-judge-detail">
                        <span class="dir-judge-pill">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;margin-right:3px;"><line x1="6" y1="3" x2="6" y2="15"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M18 9a9 9 0 0 1-9 9"/></svg>
                            <?= $j['group_count'] ?> Kumpulan
                        </span>
                        <span class="dir-judge-pill">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;margin-right:3px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                            <?= $j['student_count'] ?> Pelajar Dinilai
                        </span>
                        <?php foreach (explode(', ', $j['levels']) as $lv): ?>
                        <span class="dir-judge-pill" style="color:#ff8a8a;font-weight:600;border-color:var(--c-red-border);background:var(--c-red-dim);"><?= htmlspecialchars(trim($lv)) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-top:6px;font-size:0.78rem;color:var(--c-text-faint);">
                        Sidang: <?= htmlspecialchars($j['sessions']) ?><?php if ($show_siri_picker): ?> · Siri: <?= htmlspecialchars($j['siris'] ?: '— Tiada Siri —') ?><?php endif; ?>
                    </div>
                </div>
            </div>
<?php       endwhile; ?>
        </div>
    </div>
<?php
    endforeach;
?>
</div><!-- /dir-sections-wrapper -->
<?php
    endif;

// ══════════════════════════════════════════════════════════════════
//  TAB 3 — GROUPS BY JUDGE
// ══════════════════════════════════════════════════════════════════
elseif ($tab === 'groups_judge'):
?>

<div class="dir-filter-wrap">
<button class="dir-filter-toggle" id="ftog3" onclick="dirFilterToggle('ftog3','fbody3')">
    <svg viewBox="0 0 24 24"><line x1="4" y1="6" x2="20" y2="6"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="11" y1="18" x2="13" y2="18"/></svg>
    Tapisan &amp; Carian
    <svg class="chevron" viewBox="0 0 24 24" style="margin-left:auto;"><polyline points="6 9 12 15 18 9"/></svg>
</button>
<div class="dir-filter-body" id="fbody3">
<form method="GET" class="dir-filter">
    <input type="hidden" name="tab" value="groups_judge">
    <div>
        <label>Cari Juri / Kumpulan</label>
        <input type="text" name="search" class="pm-auto-input" placeholder="Nama juri atau kumpulan..." value="<?= htmlspecialchars($search) ?>" autocomplete="off">
    </div>
    <?php if ($show_siri_picker): ?>
    <div>
        <label>Siri</label>
        <?php renderDirDD('siri_id', 'siri3', 'Semua Siri', $siriOpts, $filter_siri); ?>
    </div>
    <?php endif; ?>
    <div>
        <label>Sidang</label>
        <?php renderDirDD('session_id', 'session3', 'Semua Sidang', $sessionOpts, $filter_session); ?>
    </div>
    <div>
        <label>Peringkat</label>
        <?php renderDirDD('level_id', 'level3', 'Semua Peringkat', $levelOpts, $filter_level); ?>
    </div>
    <div>
        <label>Juri</label>
        <?php renderDirDD('judge_id', 'judge3', 'Semua Juri', $judgeOpts, $filter_judge); ?>
    </div>
    </form>
</div>
</div>

<div class="dir-summary" style="justify-content:flex-end;">
    <div class="dir-summary-item"><?= $sortToggleHtml ?></div>
</div>

<?php
    $jWhere = ["j.id IS NOT NULL"];
    $jTypes = ''; $jVals = [];
    if ($filter_judge)   { $jWhere[] = "j.id = ?";            $jTypes .= 'i'; $jVals[] = $filter_judge; }
    if ($filter_session) { $jWhere[] = "g.level_id IN (SELECT level_id FROM levels WHERE session_id = ?)"; $jTypes .= 'i'; $jVals[] = $filter_session; }
    if ($filter_level)   { $jWhere[] = "g.level_id = ?";      $jTypes .= 'i'; $jVals[] = $filter_level; }
    if ($search) {
        $jWhere[] = "(j.name LIKE ? OR g.group_name LIKE ?)";
        $jTypes .= 'ss'; $jVals[] = '%'.$search.'%'; $jVals[] = '%'.$search.'%';
    }
    if ($eff_siri) { $jWhere[] = "g.level_id IN (SELECT level_id FROM levels WHERE session_id IN (SELECT session_id FROM sessions WHERE siri_id = ?))"; $jTypes .= 'i'; $jVals[] = $eff_siri; }
    $jWhereSql = implode(' AND ', $jWhere);

    // Get judges that have groups
    $judgesSql = "SELECT DISTINCT j.id as judge_id, j.name as judge_name,
                         COUNT(DISTINCT g.group_id) as group_count,
                         COUNT(DISTINCT gs.student_id) as student_count
                  FROM judges j
                  JOIN `groups` g ON g.judge_id = j.id
                  LEFT JOIN group_students gs ON g.group_id = gs.group_id
                  WHERE $jWhereSql
                  GROUP BY j.id
                  ORDER BY j.name";
    $judgesStmt = $conn->prepare($judgesSql);
    if ($jTypes) $judgesStmt->bind_param($jTypes, ...$jVals);
    $judgesStmt->execute();
    $judges_result = $judgesStmt->get_result();
    $judgesStmt->close();

    if ($judges_result->num_rows === 0):
?>
    <div class="dir-empty">
        <div class="dir-empty-icon">⚖️</div>
        Tiada juri dengan kumpulan ditemui untuk tapisan ini.
    </div>
<?php
    else:
?>
<div id="dir-sections-wrapper">
<?php
    while ($judge = $judges_result->fetch_assoc()):
        $judge_id = $judge['judge_id'];
?>
    <div class="dir-section">
        <div class="dir-section-header" onclick="dirToggle('judge_grp_<?= $judge_id ?>')">
            <div class="dir-section-icon">
                <svg viewBox="0 0 24 24"><path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1z"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1z"/><path d="M7 21h10"/><line x1="12" y1="3" x2="12" y2="21"/><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"/></svg>
            </div>
            <div class="dir-section-info">
                <div class="dir-section-title"><?= htmlspecialchars($judge['judge_name']) ?></div>
                <div class="dir-section-meta"><?= $judge['group_count'] ?> kumpulan · <?= $judge['student_count'] ?> pelajar</div>
            </div>
            <span class="dir-section-badge"><?= $judge['group_count'] ?> Kumpulan</span>
            <span class="dir-section-arrow">▶</span>
        </div>
        <div class="dir-section-body" id="judge_grp_<?= $judge_id ?>">
<?php
        // Groups for this judge
        $grpWhere = ["g.judge_id = ?"];
        $grpTypes = 'i'; $grpVals = [$judge_id];
        if ($filter_session) { $grpWhere[] = "l.session_id = ?"; $grpTypes .= 'i'; $grpVals[] = $filter_session; }
        if ($filter_level)   { $grpWhere[] = "g.level_id = ?";   $grpTypes .= 'i'; $grpVals[] = $filter_level; }
        if ($search)         { $grpWhere[] = "g.group_name LIKE ?"; $grpTypes .= 's'; $grpVals[] = '%'.$search.'%'; }

        $grpSql = "SELECT g.group_id, g.group_name, se.session_name, l.level_name, si.siri_name,
                          COUNT(gs.student_id) as student_count
                   FROM `groups` g
                   JOIN levels l ON g.level_id = l.level_id
                   JOIN sessions se ON l.session_id = se.session_id
                   LEFT JOIN siri si ON se.siri_id = si.siri_id
                   LEFT JOIN group_students gs ON g.group_id = gs.group_id
                   WHERE " . implode(' AND ', $grpWhere) . "
                   GROUP BY g.group_id
                   ORDER BY se.session_name, l.level_name, g.group_name";
        $grpStmt = $conn->prepare($grpSql);
        $grpStmt->bind_param($grpTypes, ...$grpVals);
        $grpStmt->execute();
        $groups_list = $grpStmt->get_result();
        $grpStmt->close();

        while ($g = $groups_list->fetch_assoc()):
            $gid = $g['group_id'];

            // Students in group
            $stOrderBy2 = $sort_name === 'ASC' ? "st.student_name ASC" : ($sort_name === 'DESC' ? "st.student_name DESC" : "st.student_id");
            $stSql = "SELECT st.student_id, st.student_name, st.gender, st.year,
                             sc.school_name
                      FROM group_students gs
                      JOIN students st ON gs.student_id = st.student_id
                      LEFT JOIN schools sc ON st.school_id = sc.school_id
                      WHERE gs.group_id = ?
                      ORDER BY $stOrderBy2";
            $stStmt = $conn->prepare($stSql);
            $stStmt->bind_param('i', $gid);
            $stStmt->execute();
            $students_list = $stStmt->get_result();
            $stStmt->close();
?>
            <div class="dir-group-card">
                <div class="dir-group-header">
                    <span class="dir-group-name">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--c-red)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;margin-right:4px;"><line x1="6" y1="3" x2="6" y2="15"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M18 9a9 9 0 0 1-9 9"/></svg>
                        <?= htmlspecialchars($g['group_name']) ?>
                    </span>
                    <span class="dir-judge-pill"><?= htmlspecialchars($g['session_name']) ?></span>
                    <span class="dir-judge-pill" style="color:#ff8a8a;font-weight:600;border-color:var(--c-red-border);background:var(--c-red-dim);"><?= htmlspecialchars($g['level_name']) ?></span>
                    <?php if ($show_siri_picker): ?>
                    <span class="dir-judge-pill"><?= htmlspecialchars($g['siri_name'] ?? '— Tiada Siri —') ?></span>
                    <?php endif; ?>
                    <span style="background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:999px;font-size:0.72rem;font-weight:700;color:var(--c-text-muted);padding:2px 8px;white-space:nowrap;margin-left:auto;">
                        <?= $g['student_count'] ?> pelajar
                    </span>
                </div>
                <?php if ($students_list->num_rows > 0): ?>
                <div class="dir-student-list">
                    <?php $no = 1; while ($st = $students_list->fetch_assoc()): ?>
                    <div class="dir-student-chip">
                        <span class="dir-student-no"><?= $no++ ?></span>
                        <span style="flex:1;">
                            <span class="dir-student-name" style="color:var(--c-white);display:block;line-height:1.2;"><?= htmlspecialchars($st['student_name']) ?></span>
                            <?php if ($st['school_name']): ?>
                            <span style="font-size:0.7rem;color:var(--c-text-faint);"><?= htmlspecialchars($st['school_name']) ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="dir-gender-badge <?= $st['gender'] === 'Male' ? 'dir-gender-m' : 'dir-gender-f' ?>">
                            <?= $st['gender'] === 'Male' ? 'L' : 'P' ?>
                        </span>
                    </div>
                    <?php endwhile; ?>
                </div>
                <?php else: ?>
                <div style="padding:12px 16px;color:var(--c-text-faint);font-size:0.83rem;font-style:italic;">Tiada pelajar dalam kumpulan ini.</div>
                <?php endif; ?>
            </div>
<?php
        endwhile; /* groups */
?>
        </div>
    </div>
<?php
    endwhile; /* judges */
?>
</div><!-- /dir-sections-wrapper -->
<?php
    endif;

endif; /* tab switch */
?>

<script>
// ── Searchable dropdown logic (matches pic_students.php's dd-wrap) ──
// Selecting an option submits the enclosing GET form (this page reloads
// on filter change, same as the plain <select>s it replaces).
function ddToggle(name) {
    const trigger = document.getElementById('ddTrigger_' + name);
    const panel = document.getElementById('ddPanel_' + name);
    const isOpen = panel.classList.contains('open');
    document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
    document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
    if (!isOpen) {
        panel.classList.add('open'); trigger.classList.add('open');
        setTimeout(() => panel.querySelector('.dd-search-box input')?.focus(), 50);
    }
}

function ddFilter(name, val) {
    const opts = document.querySelectorAll('#ddOpts_' + name + ' .dd-opt');
    const empty = document.getElementById('ddEmpty_' + name);
    let any = false;
    opts.forEach(o => {
        const m = o.textContent.toLowerCase().includes(val.toLowerCase());
        o.classList.toggle('hidden', !m);
        if (m) any = true;
    });
    if (empty) empty.style.display = any ? 'none' : 'block';
}

// Kumpulan is scoped server-side to whichever Peringkat is selected — so a
// Kumpulan chosen under a since-changed Peringkat is no longer one of the
// options being shown and needs to be cleared, not silently left applied as
// a stale hidden-field value the dropdown itself no longer reflects.
const DD_LEVEL_TO_GROUP = { level1: 'group1' };

function ddSelect(name, value, label) {
    document.getElementById('f_' + name).value = value;
    const dependentGroup = DD_LEVEL_TO_GROUP[name];
    if (dependentGroup) {
        const groupField = document.getElementById('f_' + dependentGroup);
        if (groupField) groupField.value = '';
    }
    document.getElementById('ddWrap_' + name).closest('form').submit();
}

document.addEventListener('click', e => {
    if (!e.target.closest('.dd-wrap')) {
        document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
        document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
    }
});

// ── Cari Pelajar autocomplete (students_group tab only) ────────────
// Suggestion pool is built once from the names already rendered on this
// page load — same source-of-truth approach as pic_master_list.php's
// Carian Nama dropdown. Caveat inherited from that pattern: if the page
// loaded with a search term already active, the pool only contains
// whatever matched that term (the full unfiltered list isn't fetched
// separately) — acceptable since by that point the user has already
// narrowed in on a name and is looking at results, not still browsing.
let dirStudentNames = [];
(function () {
    const wrapper = document.getElementById('nameDropdownWrapper');
    if (!wrapper) return; // not on the students_group tab

    dirStudentNames = [...new Set(
        Array.from(document.querySelectorAll('.dir-student-name')).map(el => el.textContent.trim())
    )].sort();

    document.addEventListener('click', e => {
        if (!wrapper.contains(e.target)) {
            document.getElementById('nameDropdownBox').classList.remove('open');
        }
    });
})();

function dirFilterNameOptions(keyword) {
    const box = document.getElementById('nameDropdownBox');
    const list = document.getElementById('nameDropdownList');
    const kw = keyword.trim().toLowerCase();

    const matches = kw === '' ? dirStudentNames : dirStudentNames.filter(n => n.toLowerCase().includes(kw));

    if (matches.length === 0) {
        list.innerHTML = kw === '' ? '' : `<div class="dir-name-empty">Tiada hasil ditemui</div>`;
    } else {
        list.innerHTML = matches.slice(0, 20).map(name =>
            `<div class="dd-option" onclick="dirSelectName('${name.replace(/'/g, "\\'")}')">${name}</div>`
        ).join('');
    }
    box.classList.toggle('open', matches.length > 0 || kw !== '');
}

function dirSelectName(name) {
    const input = document.getElementById('dirNameSearch');
    input.value = name;
    document.getElementById('nameDropdownBox').classList.remove('open');
    input.form.submit();
}

// ── Accordion toggle ─────────────────────────────────────────
function dirToggle(id) {
    const body = document.getElementById(id);
    if (!body) return;
    const isOpen = body.style.display === 'block';
    body.style.display = isOpen ? 'none' : 'block';
    const header = body.previousElementSibling;
    if (header) {
        const arrow = header.querySelector('.dir-section-arrow');
        if (arrow) arrow.style.transform = isOpen ? '' : 'rotate(90deg)';
    }
    // Init pagination when first opened
    if (!isOpen && !body.__pagReady) {
        paginateGroupCards(body);
        body.__pagReady = true;
    }
}

// ── Collapsible filter (mobile) ──────────────────────────────
function dirFilterToggle(togId, bodyId) {
    const tog  = document.getElementById(togId);
    const body = document.getElementById(bodyId);
    if (!tog || !body) return;
    const collapsed = body.classList.contains('collapsed');
    body.classList.toggle('collapsed', !collapsed);
    tog.classList.toggle('open', collapsed);
}

// ── Universal paginator ──────────────────────────────────────
// Paginates an array of DOM elements inside a container, using the shared
// pmRenderPagination() button renderer (layout.js) so every list on this
// page — and every other paginated list in the app — looks/behaves the same.
function makePaginator(container, items, perPage, renderTarget, infoPrefix) {
    if (items.length <= perPage) return;
    let page = 1;
    const total = Math.ceil(items.length / perPage);

    function render() {
        const start = (page - 1) * perPage;
        items.forEach((el, i) => {
            el.style.display = (i >= start && i < start + perPage) ? '' : 'none';
        });
        const end = Math.min(start + perPage, items.length);

        let pag = container.querySelector(':scope > .dir-pag-el');
        if (!pag) {
            pag = document.createElement('div');
            pag.className = 'dir-pag-el vm-pagination';
            pag.innerHTML = '<span class="vm-page-info"></span><div class="vm-page-btns"></div>';
            container.appendChild(pag);
        }
        pag.querySelector('.vm-page-info').textContent = `${infoPrefix} ${start + 1}–${end} / ${items.length}`;
        pmRenderPagination(pag.querySelector('.vm-page-btns'), page, total, function (p) { page = p; render(); });
    }

    render();
}

// ── Paginate section (accordion) list ────────────────────────
// Groups the top-level .dir-section elements and paginates them.
function paginateSections() {
    const SECTIONS_PER_PAGE = 8;
    const wrapper = document.getElementById('dir-sections-wrapper');
    if (!wrapper) return;
    const sections = Array.from(wrapper.querySelectorAll(':scope > .dir-section'));
    if (sections.length <= SECTIONS_PER_PAGE) return;

    // Need a unique ID for the wrapper for paginator onclick
    if (!wrapper.id) wrapper.id = 'dir-sec-wrap-' + Date.now();
    makePaginator(wrapper, sections, SECTIONS_PER_PAGE, wrapper, 'Item');
}

// ── Paginate group cards within an open accordion body ───────
function paginateGroupCards(bodyEl) {
    const GROUPS_PER_PAGE = 5;
    const cards = Array.from(bodyEl.querySelectorAll(':scope > .dir-group-card'));
    if (cards.length <= GROUPS_PER_PAGE) return;
    if (!bodyEl.id) bodyEl.id = 'body-' + Math.random().toString(36).slice(2);
    makePaginator(bodyEl, cards, GROUPS_PER_PAGE, bodyEl, 'Kumpulan');
}

document.addEventListener('DOMContentLoaded', function () {
    // ── On mobile, start filters collapsed ──
    if (window.innerWidth <= 640) {
        ['fbody1','fbody2','fbody3'].forEach(function(id) {
            const el = document.getElementById(id);
            if (el) el.classList.add('collapsed');
        });
        // If active filter exists, keep filter open
        const hasFilter = location.search.match(/[&?](search|session_id|school_id|level_id|group_id|judge_id)=[^&]+/);
        if (hasFilter) {
            ['fbody1','fbody2','fbody3'].forEach(function(id) {
                const el = document.getElementById(id);
                if (el) { el.classList.remove('collapsed'); }
            });
            ['ftog1','ftog2','ftog3'].forEach(function(id) {
                const el = document.getElementById(id);
                if (el) el.classList.add('open');
            });
        }
    }

    // ── Do NOT auto-open any accordion sections ──
    // (previously there was auto-expand for single-section lists — removed)

    // ── Paginate the top-level sections list ──
    paginateSections();

    // ── Auto-submit for text inputs (debounced) ──
    let typingTimer;
    document.querySelectorAll('.pm-auto-input').forEach(function(input) {
        input.addEventListener('input', function() {
            clearTimeout(typingTimer);
            typingTimer = setTimeout(() => { this.form.submit(); }, 600);
        });
    });
});
</script>

</main>
</body>
</html>