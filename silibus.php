<?php
session_start();
require __DIR__ . '/auth_check.php';
include 'db.php';
$conn = getDB();

// ✅ Restrict access to judges only
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'judge') {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/pagination_helpers.php';
function silibus_page_url(int $p, array $extra): string {
    return pm_page_url($p, $extra);
}

// ── FILTER INPUTS — six independent filters, each usable on its own ──
// (No longer a Tahun → Siri → Sidang → Peringkat → Ujian → Kriteria cascade:
// any of these can be picked in any combination/order. The SQL below just
// ANDs together whichever ones are actually set.)
//
// Siri/Sidang/Peringkat/Ujian/Kriteria are matched by NAME, not id — same
// as leaderboard.php's filters, which match against a value string rather
// than a specific row's id. That's what makes deduping the dropdown lists
// possible at all: "Asas Elakan" appears under many different tests, each
// with its own criteria_id, so an id-based filter could only ever match one
// of them. Matching by name instead means picking the deduped "Asas Elakan"
// option correctly narrows to every occurrence of it, wherever it appears.
$f_year     = trim((string)($_GET['year'] ?? ''));
$f_siri     = trim((string)($_GET['siri'] ?? ''));
$f_session  = trim((string)($_GET['session'] ?? ''));
$f_level    = trim((string)($_GET['level'] ?? ''));
$f_test     = trim((string)($_GET['test'] ?? ''));
$f_criteria = trim((string)($_GET['criteria'] ?? ''));

$year_ok     = $f_year !== '';
$siri_ok     = $f_siri !== '';
$session_ok  = $f_session !== '';
$level_ok    = $f_level !== '';
$test_ok     = $f_test !== '';

// ── Filter dropdown option lists — each shows every DISTINCT value that
// exists, regardless of what's selected in the other dropdowns. ──
$yearOptions = $conn->query("SELECT DISTINCT siri_year FROM siri WHERE siri_year IS NOT NULL ORDER BY siri_year DESC")->fetch_all(MYSQLI_ASSOC);
$siriOptions = $conn->query("SELECT DISTINCT siri_name FROM siri ORDER BY siri_name ASC")->fetch_all(MYSQLI_ASSOC);
$sessionOptions = $conn->query("SELECT DISTINCT session_name FROM sessions ORDER BY session_name ASC")->fetch_all(MYSQLI_ASSOC);
// sort_order is the syllabus's own progression (e.g. Hijau 1-3, Merah 1-3,
// Kuning 1-3, Hitam) — not alphabetical. Grouped by name (MIN picks one
// representative sort_order per name) since the same Peringkat name should
// always sit at the same point in that progression regardless of which
// session it belongs to.
$levelOptions = $conn->query("SELECT level_name, MIN(sort_order) AS sort_order FROM levels GROUP BY level_name ORDER BY sort_order ASC, level_name ASC")->fetch_all(MYSQLI_ASSOC);
$testOptions = $conn->query("SELECT DISTINCT test_name FROM tests ORDER BY test_name ASC")->fetch_all(MYSQLI_ASSOC);
$criteriaOptions = $conn->query("SELECT DISTINCT criteria_name FROM criteria ORDER BY criteria_name ASC")->fetch_all(MYSQLI_ASSOC);

// ── Fetch all data efficiently to avoid N+1 query problem, scoped by whichever filters are active ──
$sessionsSql = "
    SELECT s.session_id, s.session_name, sr.siri_year, sr.siri_name
    FROM sessions s
    LEFT JOIN siri sr ON s.siri_id = sr.siri_id
    WHERE 1=1
";
if ($year_ok)    $sessionsSql .= " AND sr.siri_year = '" . $conn->real_escape_string($f_year) . "'";
if ($siri_ok)    $sessionsSql .= " AND sr.siri_name = '" . $conn->real_escape_string($f_siri) . "'";
if ($session_ok) $sessionsSql .= " AND s.session_name = '" . $conn->real_escape_string($f_session) . "'";
$sessionsSql .= " ORDER BY sr.siri_year DESC, sr.siri_name ASC, s.session_name ASC";
$sessions = $conn->query($sessionsSql)->fetch_all(MYSQLI_ASSOC);

$levelsSql = "SELECT level_id, session_id, level_name FROM levels WHERE 1=1";
if ($level_ok) $levelsSql .= " AND level_name = '" . $conn->real_escape_string($f_level) . "'";
$levelsSql .= " ORDER BY session_id, sort_order ASC, level_name ASC";
$levels = $conn->query($levelsSql)->fetch_all(MYSQLI_ASSOC);

$testsSql = "SELECT test_id, level_id, test_name FROM tests WHERE 1=1";
if ($test_ok) $testsSql .= " AND test_name = '" . $conn->real_escape_string($f_test) . "'";
$testsSql .= " ORDER BY level_id, test_name ASC";
$tests = $conn->query($testsSql)->fetch_all(MYSQLI_ASSOC);

$criteriaSql = "SELECT criteria_id, test_id, criteria_name FROM criteria WHERE 1=1";
if ($f_criteria !== '') $criteriaSql .= " AND criteria_name = '" . $conn->real_escape_string($f_criteria) . "'";
$criteriaSql .= " ORDER BY test_id, criteria_name ASC";
$criteria = $conn->query($criteriaSql)->fetch_all(MYSQLI_ASSOC);

// Organize data for easy lookup
$levelsBySession = [];
foreach ($levels as $level) $levelsBySession[$level['session_id']][] = $level;

$testsByLevel = [];
foreach ($tests as $test) $testsByLevel[$test['level_id']][] = $test;

$criteriaByTest = [];
foreach ($criteria as $criterion) $criteriaByTest[$criterion['test_id']][] = mb_strtoupper($criterion['criteria_name']);

// ── Flatten into one row per Ujian (or a placeholder row when a Sidang/Tahap has none) ──
// Uppercased at the source (not just via CSS) so it's baked into every
// consumer of this data — the on-screen table AND $silibusExportData below,
// which feeds the Excel/PDF export buttons.
$allSilibusRows = [];
foreach ($sessions as $session) {
    $sidang_name   = mb_strtoupper($session['session_name']);
    $session_id    = $session['session_id'];
    $year_text     = mb_strtoupper($session['siri_year'] ?? 'Tiada Tahun');
    $siri_text     = mb_strtoupper($session['siri_name'] ?? 'Tiada Siri');
    $sessionLevels = $levelsBySession[$session_id] ?? [];

    if (empty($sessionLevels)) {
        $allSilibusRows[] = ['year' => $year_text, 'siri' => $siri_text, 'sidang' => $sidang_name, 'tahap' => 'TIADA TAHAP', 'ujian' => '-', 'kriteria' => []];
        continue;
    }

    foreach ($sessionLevels as $level) {
        $tahap_name = mb_strtoupper($level['level_name']);
        $level_id   = $level['level_id'];
        $levelTests = $testsByLevel[$level_id] ?? [];

        if (empty($levelTests)) {
            $allSilibusRows[] = ['year' => $year_text, 'siri' => $siri_text, 'sidang' => $sidang_name, 'tahap' => $tahap_name, 'ujian' => 'TIADA UJIAN', 'kriteria' => []];
            continue;
        }

        foreach ($levelTests as $test) {
            $testCriteria = $criteriaByTest[$test['test_id']] ?? [];
            $allSilibusRows[] = ['year' => $year_text, 'siri' => $siri_text, 'sidang' => $sidang_name, 'tahap' => $tahap_name, 'ujian' => mb_strtoupper($test['test_name']), 'kriteria' => $testCriteria];
        }
    }
}

// ── Server-side pagination (same pattern as pic_view_marks.php) ──
$per_page    = 20;
$page        = max(1, (int)($_GET['page'] ?? 1));
$total_rows  = count($allSilibusRows);
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = min($page, $total_pages);
$page_rows   = array_slice($allSilibusRows, ($page - 1) * $per_page, $per_page);

$filter_params = array_filter([
    'year'     => $f_year !== '' ? $f_year : null,
    'siri'     => $f_siri ?: null,
    'session'  => $f_session ?: null,
    'level'    => $f_level ?: null,
    'test'     => $f_test ?: null,
    'criteria' => $f_criteria ?: null,
], function ($v) { return $v !== null; });

// Full filtered dataset (ignores pagination) for Excel/PDF export
$silibusExportData = array_map(function ($r) {
    return [
        'year'     => $r['year'],
        'siri'     => $r['siri'],
        'sidang'   => $r['sidang'],
        'tahap'    => $r['tahap'],
        'ujian'    => $r['ujian'],
        'kriteria' => $r['kriteria'] ? implode("\n", $r['kriteria']) : 'TIADA KRITERIA DIREKODKAN.',
    ];
}, $allSilibusRows);

// Renders one searchable dd-wrap filter dropdown (same component used across PIC pages, e.g. pic_view_marks.php)
function renderSilibusDD(string $fieldName, string $ddName, string $normalEmptyLabel, string $disabledEmptyLabel, array $options, $currentVal, bool $isDisabled) {
    $currentVal = (string)$currentVal;
    $emptyLabel = $isDisabled ? $disabledEmptyLabel : $normalEmptyLabel;
    $disabledClass = $isDisabled ? ' dd-trigger-disabled' : '';
    echo "<div class=\"dd-wrap\" id=\"ddWrap_{$ddName}\">";
    echo "<div class=\"dd-trigger{$disabledClass}\" id=\"ddTrigger_{$ddName}\" onclick=\"ddToggle('{$ddName}')\" role=\"button\" tabindex=\"0\" aria-haspopup=\"listbox\">";
    $curLabel = $emptyLabel;
    if (!$isDisabled) {
        foreach ($options as $opt) {
            if ((string)$opt['value'] === $currentVal && $currentVal !== '' && $currentVal !== '0') { $curLabel = $opt['label']; break; }
        }
    }
    $labelColor = ($currentVal === '' || $currentVal === '0' || $isDisabled) ? 'color:var(--c-text-faint);' : '';
    echo "<span id=\"ddLabel_{$ddName}\" style=\"{$labelColor}\">" . htmlspecialchars($curLabel) . "</span>";
    echo "<span class=\"dd-arrow\">▼</span></div>";
    echo "<div class=\"dd-panel\" id=\"ddPanel_{$ddName}\" role=\"listbox\">";
    echo "<div class=\"dd-search-box\"><input type=\"text\" placeholder=\"Cari...\" oninput=\"ddFilter('{$ddName}',this.value)\" onclick=\"event.stopPropagation()\"></div>";
    echo "<div class=\"dd-options\" id=\"ddOpts_{$ddName}\">";
    $emptySel = ($currentVal === '' || $currentVal === '0') ? 'selected' : '';
    echo "<div class=\"dd-opt {$emptySel}\" role=\"option\" tabindex=\"0\" data-value=\"\" onclick=\"ddSelect('{$ddName}','','" . htmlspecialchars($normalEmptyLabel, ENT_QUOTES) . "')\">" . htmlspecialchars($normalEmptyLabel) . "</div>";
    foreach ($options as $opt) {
        $sel = ((string)$opt['value'] === $currentVal && $currentVal !== '' && $currentVal !== '0') ? 'selected' : '';
        $valEsc = htmlspecialchars((string)$opt['value'], ENT_QUOTES);
        $lblEsc = htmlspecialchars($opt['label'], ENT_QUOTES);
        echo "<div class=\"dd-opt {$sel}\" role=\"option\" tabindex=\"0\" data-value=\"{$valEsc}\" onclick=\"ddSelect('{$ddName}','{$valEsc}','{$lblEsc}')\">" . htmlspecialchars($opt['label']) . "</div>";
    }
    echo "</div><div class=\"dd-empty\" id=\"ddEmpty_{$ddName}\">Tiada hasil</div></div>";
    echo "<input type=\"hidden\" name=\"{$fieldName}\" id=\"{$ddName}\" value=\"" . htmlspecialchars($currentVal === '0' ? '' : $currentVal) . "\">";
    echo "</div>";
}

// Maps a Peringkat (Tahap) name to its belt colour key, matched by keyword
// since names follow the pattern "... Cula <Colour> <N>" (e.g. "Cula Hijau
// 1"). Returns null for anything that doesn't mention a known belt colour,
// so callers can just skip the accent instead of guessing.
//
// Only the KEY is returned here — the actual row tint is a CSS class
// (.tahap-<key>, defined per light/dark theme below) rather than a single
// inline hex+alpha, because a translucent black blended onto an already-dark
// theme surface is indistinguishable from no accent at all. The solid
// (non-translucent) marker colour used for the left-edge stripe and dot is
// a separate map since those don't have that problem — full-opacity colour
// reads fine against either theme.
function silibus_tahap_key(string $tahap_name): ?string {
    $needle = mb_strtolower($tahap_name);
    if (strpos($needle, 'hijau')  !== false) return 'hijau';
    if (strpos($needle, 'merah')  !== false) return 'merah';
    if (strpos($needle, 'kuning') !== false) return 'kuning';
    if (strpos($needle, 'hitam')  !== false) return 'hitam';
    return null;
}

function silibus_tahap_marker_colour(string $key): string {
    return [
        'hijau'  => '#16a34a',
        'merah'  => '#dc2626',
        'kuning' => '#ca8a04',
        'hitam'  => '#71717a', // zinc-500 — reads against both a light and a dark surface, unlike pure black
    ][$key] ?? '#000000';
}

// ✅ Set active page for the sidebar navigation
$pm_page = 'silibus';
include 'layout.php';
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>

<style>
    /* ── PAGINATION: shared .vm-pagination styles now live once in
       dashboard.css (loaded by layout.php), used by every paginated page. ── */

    /* ── RESPONSIVE AUTO TABLE (CONTENT-DRIVEN WIDTHS) ── */
    .pm-table-wrap {
        overflow-x: auto;
        overflow-y: auto;
        max-height: calc(100vh - 280px);
        -webkit-overflow-scrolling: touch;
        width: 100%;
        background: var(--c-surface-1);
    }

    .silibus-table {
        display: table !important;
        width: 100% !important;
        table-layout: auto !important;  /* columns size based on content */
        border-collapse: collapse;
        border: 1px solid var(--c-border-strong);
    }

    /* Sticky Top Header — dark is the default theme here (this app's dark
       mode is the ABSENCE of the html.pm-light class, not a "pm-dark" class
       that gets added — there's no such class anywhere in the codebase), so
       the unprefixed rule below has to be the dark styling, with
       html.pm-light overriding it for light mode. Written the other way
       around (as it originally was), the "dark" variant was keyed off a
       class that's never actually applied, so it silently never took effect
       and the header stayed light-colored even in dark mode. */
    .silibus-table th {
        position: sticky;
        top: 0;
        z-index: 10;
        background: var(--c-surface-2) !important;
        color: var(--c-text-muted);
        border-bottom: 1px solid var(--c-border) !important;
        border-right: 1px solid var(--c-border);
        padding: 12px 16px !important;
        text-align: left;
        white-space: nowrap;
        font-size: 0.65rem !important;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.1em !important;
    }

    .silibus-table th:last-child {
        border-right: none;
    }

    html.pm-light .silibus-table th {
        background: #f8f9fa !important;
    }

    /* Base cell styling */
    .silibus-table td {
        color: var(--c-text);
        border-bottom: 1px solid var(--c-border);
        border-right: 1px solid var(--c-border);
        padding: 10px 16px !important;   /* was 12px — slightly tighter */
        font-size: 0.85rem !important;
        vertical-align: top;              /* change from top to top globally */
        word-wrap: break-word;
        line-height: 1.5;
    }

    .silibus-table td:last-child {
        border-right: none;
    }

    /* 🔥 FIX: Prevent wrapping in first 5 columns → no extra empty gaps inside them */
    .silibus-table td:nth-child(-n+5),
    .silibus-table th:nth-child(-n+5) {
        white-space: nowrap;
    }

    /* Allow the Kriteria column (6th) to wrap normally */
    .silibus-table td:nth-child(6),
    .silibus-table th:nth-child(6) {
        white-space: normal;
    }

    /* Clean hover effect — driven by a JS-toggled class rather than plain
       :hover, so hovering any row within a merged Tahun/Siri/Sidang/Peringkat
       group highlights the whole group, including the merged cell that only
       physically lives in that group's first <tr> (rowspan doesn't make it
       part of the other rows' DOM, so :hover on a later row alone can never
       reach it). */
    /* Fallback only — rows with no belt colour match (tahap-* class) still
       get a plain grey hover. Coloured rows override this below with a
       deeper version of their own tint instead, so hovering a Hijau row
       hovers green, not grey. */
    .silibus-table tbody tr.silibus-row-hover td {
        background-color: var(--c-surface-2) !important;
    }

    /* ── Belt-colour row tints ──
       Dark is the default theme in this app (see the header-rule note
       above — there's no "pm-dark" class, dark is just the absence of
       "pm-light"), so the unprefixed rules below carry the dark-mode
       values, with html.pm-light overriding them for light mode. Writing
       these the other way around (html.pm-dark as the dark variant) means
       they never match anything and silently never apply — which is
       exactly what happened here originally: none of the tahap-* row tints
       showed up at all in actual (default) dark mode.
       Deliberately split light/dark values instead of one alpha-blended
       hex: a translucent black (Hitam) over an already-dark theme surface
       would composite to basically the same colour as the surface —
       invisible. Text colour is untouched (still var(--c-text)/
       var(--c-text-muted)), and every value here is kept low enough that
       it can't meaningfully shift the text/background contrast ratio in
       either theme. */
    .silibus-table tr.tahap-hijau td  { background: rgba(34, 197, 94, 0.12); }
    .silibus-table tr.tahap-merah td  { background: rgba(248, 113, 113, 0.10); }
    .silibus-table tr.tahap-kuning td { background: rgba(234, 179, 8, 0.12); }
    /* Hitam on dark: a light neutral instead of a dark tint, so the row is
       actually distinguishable from the surrounding surface. */
    .silibus-table tr.tahap-hitam td  { background: rgba(161, 161, 170, 0.10); }

    html.pm-light .silibus-table tr.tahap-hijau td  { background: rgba(22, 163, 74, 0.08); }
    html.pm-light .silibus-table tr.tahap-merah td  { background: rgba(220, 38, 38, 0.07); }
    html.pm-light .silibus-table tr.tahap-kuning td { background: rgba(202, 138, 4, 0.10); }
    html.pm-light .silibus-table tr.tahap-hitam td  { background: rgba(63, 63, 70, 0.06); }

    /* ── Belt-colour hover — a deeper version of the same tint, not grey.
       These are more specific (3 classes vs the fallback's 2), so they win
       over the plain-grey hover rule above for any row that has a
       tahap-* class, despite both being !important. Same default-is-dark
       ordering as above. */
    .silibus-table tr.tahap-hijau.silibus-row-hover td  { background: rgba(34, 197, 94, 0.22) !important; }
    .silibus-table tr.tahap-merah.silibus-row-hover td  { background: rgba(248, 113, 113, 0.18) !important; }
    .silibus-table tr.tahap-kuning.silibus-row-hover td { background: rgba(234, 179, 8, 0.20) !important; }
    .silibus-table tr.tahap-hitam.silibus-row-hover td  { background: rgba(161, 161, 170, 0.18) !important; }

    html.pm-light .silibus-table tr.tahap-hijau.silibus-row-hover td  { background: rgba(22, 163, 74, 0.16) !important; }
    html.pm-light .silibus-table tr.tahap-merah.silibus-row-hover td  { background: rgba(220, 38, 38, 0.14) !important; }
    html.pm-light .silibus-table tr.tahap-kuning.silibus-row-hover td { background: rgba(202, 138, 4, 0.18) !important; }
    html.pm-light .silibus-table tr.tahap-hitam.silibus-row-hover td  { background: rgba(63, 63, 70, 0.12) !important; }

    /* Lists inside the criteria column */
    .silibus-table ul {
        margin: 0;
        padding-left: 18px;
        list-style-type: square;
        color: var(--c-text-muted);
    }

    .silibus-table li::marker {
        color: var(--c-red);
    }

    .silibus-table li {
        margin-bottom: 4px;
        line-height: 1.4;
    }

    /* Sleek Badges for Siri & Year */
    .pm-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 0.65rem;
        font-weight: 700;
        text-transform: uppercase;
        padding: 4px 8px;
        border-radius: 4px;
        letter-spacing: 0.05em;
        line-height: 1;
    }
    .pm-badge-year {
        background: var(--c-surface-1);
        color: var(--c-text-muted);
        border: 1px solid var(--c-border-strong);
    }
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
    .pm-badge-siri::before {
        content: '';
        display: inline-block;
        width: 5px;
        height: 5px;
        border-radius: 50%;
        background: var(--c-red);
    }

    /* ── FLEXBOX FIX: ELIMINATE GAPS AND CUTOFFS ──
       Height is set in JS (fills whatever space is left in the viewport below
       the filter bar/alert), since their height varies with content/screen
       width and a hardcoded calc(100vh - Npx) drifts out of sync with them. */
    .pm-silibus-card {
        display: flex !important;
        flex-direction: column !important;
        padding: 0 !important;
        border: none !important;
        overflow: hidden !important;
        background: var(--c-surface-1);
    }

    .pm-table-wrap {
        flex: 1 1 auto !important;
        overflow-y: auto !important;
        overflow-x: auto !important;
        min-height: 0 !important;
        max-height: none !important;
        width: 100% !important;
        margin: 0 !important;
    }

    .silibus-table {
        margin: 0 !important;
        border-bottom: 1px solid var(--c-border);
    }

    .vm-pagination {
        flex: 0 0 auto !important;
        margin: 0 !important;
        position: relative;
        z-index: 10;
    }

    /* ── FILTER BAR ── */
    .silibus-filter-bar {
        display: flex;
        align-items: flex-end;
        flex-wrap: wrap;
        gap: 12px;
        padding: 16px 20px;
        margin-bottom: 12px;
    }
    .silibus-filter-grid {
        display: grid;
        grid-template-columns: repeat(6, 1fr);
        gap: 10px;
        flex: 1 1 640px;
        /* Grid items default to min-width:auto, so a 6-column grid refuses
           to shrink below its content's min-content width and overflows
           its flex parent instead of actually respecting flex-shrink —
           this is what let it blow past the card edge on tablet widths. */
        min-width: 0;
    }
    .silibus-filter-grid label {
        display: block;
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: var(--c-text-muted);
        margin-bottom: 6px;
    }
    .silibus-export-actions {
        display: flex;
        gap: 8px;
        flex: 0 0 auto;
        flex-wrap: wrap;
    }
    /* 1180px (not 900px) so tablet widths — e.g. iPad 1024px landscape,
       narrowed further by the sidebar — already get 3 columns instead of
       staying stuck at 6 cramped-to-overflowing ones. */
    @media (max-width: 1180px) {
        .silibus-filter-grid { grid-template-columns: repeat(3, 1fr); }
    }
    @media (max-width: 560px) {
        .silibus-filter-grid {
            grid-template-columns: repeat(2, 1fr);
            /* .silibus-filter-grid's base rule sets flex:1 1 640px, meant as
               a WIDTH basis for the desktop row-direction .silibus-filter-bar.
               Once .silibus-filter-bar switches to flex-direction:column
               below, flex-basis applies to the main axis of THAT direction —
               height, not width — so that same 640px silently became a
               640px-tall flex-basis, which the grid's 3 auto-rows then
               stretched to fill (~207px per row of actual ~56px content).
               That's what pushed .silibus-filter-bar to ~716px tall and,
               since it's a flex:0 1 auto sibling that refuses to shrink
               below its own content size while .pm-silibus-card (the table
               card) is the only sibling with min-height:0, the table card
               absorbed the entire overflow and collapsed to 0 height —
               rendering the whole table invisible on mobile. Reset to a
               real auto basis so the grid sizes to its actual content. */
            flex: 1 1 auto;
        }
        /* Stack the dropdown grid and the export/reset buttons instead of
           keeping them side-by-side — at this width there's no longer
           room for both, and .silibus-export-actions was flex:0 0 auto
           (never shrinks) with nowrap buttons, so it just overflowed the
           card horizontally instead of wrapping. */
        .silibus-filter-bar { flex-direction: column; align-items: stretch; }
        .silibus-export-actions { width: 100%; flex: 1 1 auto; }
        .silibus-export-actions .pm-btn,
        .silibus-export-actions a.pm-btn {
            flex: 1 1 auto;
            white-space: normal !important;
            text-align: center;
            justify-content: center;
        }
    }

    /* ── Searchable dropdown (.dd-wrap/.dd-trigger/.dd-panel/etc.) now
       comes from the shared filter_bar.css, loaded via layout.php. ── */

    /* ── FIT-TO-SCREEN LAYOUT ──
       pm-main's own padding already reserves the header height, so the
       remaining space in this wrapper is exactly what's actually left on
       screen. The card takes whatever's left via flex, guaranteeing the
       pagination bar stays visible without needing to scroll the page. */
    .silibus-page-wrap {
        display: flex;
        flex-direction: column;
        height: calc(100vh - var(--header-h) - var(--sp-6) - var(--sp-10));
        max-height: calc(100vh - var(--header-h) - var(--sp-6) - var(--sp-10));
        overflow: hidden;
    }
    .silibus-page-wrap .pm-silibus-card {
        flex: 1 1 auto;
        min-height: 0;
    }
    /* Mobile override — MUST come after the base .silibus-page-wrap rule
       above (not inside the earlier @media(max-width:560px) block further
       up the file), because that earlier position lost the cascade to this
       later unconditional rule despite matching the same media condition;
       CSS source order breaks ties between rules of equal specificity
       regardless of which one is wrapped in a media query.
       The desktop "fit everything in one screen, no page scroll" layout
       (fixed height + overflow:hidden above) doesn't have room on a phone:
       a 6-field filter bar + the warning banner alone can eat most of the
       viewport, squeezing the actual data table down to a barely-visible
       sliver (~65px tall in one measured case) even with the flex-basis
       fix in the grid above. Let the page scroll normally on mobile
       instead: each section takes its natural height, and the user
       scrolls the page like every other page in the app. */
    @media (max-width: 560px) {
        .silibus-page-wrap {
            height: auto;
            max-height: none;
            overflow: visible;
        }
        .pm-silibus-card {
            flex: none;
        }
        .pm-table-wrap {
            flex: none;
            max-height: none;
        }
    }

    /* ── Reference notice — overrides the shared .pm-alert-warn look (which
       resolves to a washed-out red in light mode) with a higher-contrast
       amber treatment scoped to just this page, so other pages using
       .pm-alert-warn elsewhere in the app are untouched. ── */
    .silibus-notice {
        background: rgba(245, 158, 11, 0.16) !important;
        border: 1px solid rgba(245, 158, 11, 0.55) !important;
        color: #fde68a !important;
        font-weight: 600 !important;
    }
    html.pm-light .silibus-notice {
        background: #fffbeb !important;
        border-color: #f59e0b !important;
        color: #78350f !important;
    }
</style>

<div class="silibus-page-wrap">

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 12px;">
    <h2 class="pm-page-heading" style="margin: 0; font-size: 1.5rem;">📖 Senarai Silibus Mengikut Sidang dan Tahap</h2>
    <a href="silibus_baru.php" target="_blank" class="pm-btn" style="white-space: nowrap; margin-left:auto; background:var(--c-red); color:#fff; border-color:var(--c-red);">
        🖨️ Eksport Silibus
    </a>
</div>

<div class="pm-card silibus-filter-bar">
    <form method="GET" id="silibus-filter-form" class="silibus-filter-grid">
        <div>
            <label>Tahun</label>
            <?php
            $yearOpts = [];
            foreach ($yearOptions as $y) $yearOpts[] = ['value' => $y['siri_year'], 'label' => $y['siri_year']];
            renderSilibusDD('year', 'sb_year', 'Semua Tahun', 'Semua Tahun', $yearOpts, $f_year, false);
            ?>
        </div>
        <div>
            <label>Siri</label>
            <?php
            $siriOpts = [];
            foreach ($siriOptions as $s) $siriOpts[] = ['value' => $s['siri_name'], 'label' => $s['siri_name']];
            renderSilibusDD('siri', 'sb_siri', 'Semua Siri', 'Semua Siri', $siriOpts, $f_siri, false);
            ?>
        </div>
        <div>
            <label>Sidang</label>
            <?php
            $sessionOpts = [];
            foreach ($sessionOptions as $s) $sessionOpts[] = ['value' => $s['session_name'], 'label' => $s['session_name']];
            renderSilibusDD('session', 'sb_session', 'Semua Sidang', 'Semua Sidang', $sessionOpts, $f_session, false);
            ?>
        </div>
        <div>
            <label>Peringkat (Tahap)</label>
            <?php
            $levelOpts = [];
            foreach ($levelOptions as $l) $levelOpts[] = ['value' => $l['level_name'], 'label' => $l['level_name']];
            renderSilibusDD('level', 'sb_level', 'Semua Peringkat', 'Semua Peringkat', $levelOpts, $f_level, false);
            ?>
        </div>
        <div>
            <label>Nama Ujian</label>
            <?php
            $testOpts = [];
            foreach ($testOptions as $t) $testOpts[] = ['value' => $t['test_name'], 'label' => $t['test_name']];
            renderSilibusDD('test', 'sb_test', 'Semua Ujian', 'Semua Ujian', $testOpts, $f_test, false);
            ?>
        </div>
        <div>
            <label>Kriteria</label>
            <?php
            $criteriaOpts = [];
            foreach ($criteriaOptions as $c) $criteriaOpts[] = ['value' => $c['criteria_name'], 'label' => $c['criteria_name']];
            renderSilibusDD('criteria', 'sb_criteria', 'Semua Kriteria', 'Semua Kriteria', $criteriaOpts, $f_criteria, false);
            ?>
        </div>
    </form>

    <div class="silibus-export-actions">
        <button type="button" class="pm-btn" style="white-space: nowrap; background:#10b981; color:#fff; border:none; cursor:pointer;" onclick="exportSilibusExcel()">
            📊 Eksport Excel
        </button>
        <button type="button" class="pm-btn" style="white-space: nowrap; background:var(--c-red); color:#fff; border:none; cursor:pointer;" onclick="exportSilibusPDF()">
            📄 Eksport PDF
        </button>
    </div>
</div>

<div class="pm-alert pm-alert-warn silibus-notice" style="padding: 8px 12px; margin-bottom: 12px; font-size: 0.85rem; justify-content: center; text-align: center;">
    📌 Maklumat ini adalah rujukan rasmi untuk semua juri bagi memastikan pemarkahan konsisten mengikut silibus.
</div>

<div class="pm-card pm-silibus-card">
    <div class="pm-table-wrap">
        <table class="pm-table silibus-table" id="silibusTable">
            <thead>
                <tr>
                    <th>Tahun</th>
                    <th>Siri</th>
                    <th>Sidang</th>
                    <th>Peringkat (Tahap)</th>
                    <th>Nama Ujian</th>
                    <th>Kriteria / Deskripsi</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Rows for the same Tahun/Siri/Sidang/Peringkat are already
                // contiguous (they come from a nested loop over Sidang →
                // Peringkat → Ujian), so a group is just a run of consecutive
                // rows sharing that tuple — merge them into one rowspan'd cell
                // instead of repeating it once per Ujian.
                $pr_count = count($page_rows);
                $pr_i = 0;
                while ($pr_i < $pr_count):
                    $group_row = $page_rows[$pr_i];
                    $group_size = 1;
                    while (
                        $pr_i + $group_size < $pr_count
                        && $page_rows[$pr_i + $group_size]['year']   === $group_row['year']
                        && $page_rows[$pr_i + $group_size]['siri']   === $group_row['siri']
                        && $page_rows[$pr_i + $group_size]['sidang'] === $group_row['sidang']
                        && $page_rows[$pr_i + $group_size]['tahap']  === $group_row['tahap']
                    ) {
                        $group_size++;
                    }

                    $tahap_key     = silibus_tahap_key($group_row['tahap']);
                    $tahap_marker  = $tahap_key ? silibus_tahap_marker_colour($tahap_key) : null;
                    $tahap_rowCls  = $tahap_key ? " tahap-{$tahap_key}" : '';

                    for ($g = 0; $g < $group_size; $g++):
                        $row = $page_rows[$pr_i + $g];
                ?>
                <tr class="silibus-data-row<?= $tahap_rowCls ?>" data-group="<?= $pr_i ?>">
                    <?php if ($g === 0): ?>
                    <td rowspan="<?= $group_size ?>" style="vertical-align: top;<?= $tahap_marker ? " border-left: 3px solid {$tahap_marker};" : '' ?>"><span style="font-weight: 600; color: var(--c-text-muted); font-size: 0.85rem;"><?= htmlspecialchars($row['year']) ?></span></td>
                    <td rowspan="<?= $group_size ?>" style="vertical-align: top;"><span style="font-weight: 700; color: <?= $tahap_marker ?: 'var(--c-red)' ?>; font-size: 0.85rem;"><?= htmlspecialchars($row['siri']) ?></span></td>
                    <td rowspan="<?= $group_size ?>" style="vertical-align: top;"><span style="font-weight: 700; color: var(--c-text); font-size: 0.85rem;"><?= htmlspecialchars($row['sidang']) ?></span></td>
                    <td rowspan="<?= $group_size ?>" style="font-weight:600; color:var(--c-text-muted); vertical-align: top;">
                        <?php if ($tahap_marker): ?>
                        <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:<?= $tahap_marker ?>; box-shadow: 0 0 0 1px rgba(127,127,127,0.35); margin-right:8px; vertical-align:middle;"></span>
                        <?php endif; ?><?= htmlspecialchars($row['tahap']) ?>
                    </td>
                    <?php endif; ?>
                    <td style="font-weight:600; color:var(--c-text); vertical-align: top;"><?= htmlspecialchars($row['ujian']) ?></td>
                    <td>
                        <?php if (!empty($row['kriteria'])): ?>
                            <ul style="margin:0; padding-left:0; list-style-position:inside; list-style-type: disc; color: var(--c-text-muted);">
                                <?php foreach ($row['kriteria'] as $criteria_name): ?>
                                    <li style="margin-bottom: 4px;"><span style="color: var(--c-text);"><?= htmlspecialchars($criteria_name) ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <em style="color:var(--c-text-muted); opacity: 0.7;">TIADA KRITERIA DIREKODKAN.</em>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
                    endfor;
                    $pr_i += $group_size;
                endwhile;
                ?>
            </tbody>
        </table>
    </div>

    <?php pm_render_pagination($page, $total_pages, $total_rows, $per_page, 'rekod', fn($p) => silibus_page_url($p, $filter_params)); ?>
</div>

</div><!-- /.silibus-page-wrap -->

<script>

// ── Searchable dropdown logic (matches PIC pages, e.g. pic_view_marks.php) ──
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

function ddSelect(ddName, value, label) {
    document.getElementById(ddName).value = value;
    const lbl = document.getElementById('ddLabel_' + ddName);
    lbl.textContent = label;
    lbl.style.color = value === '' ? 'var(--c-text-faint)' : '';
    document.querySelectorAll('#ddOpts_' + ddName + ' .dd-opt').forEach(o => o.classList.toggle('selected', o.dataset.value === value));
    document.getElementById('ddPanel_' + ddName).classList.remove('open');
    document.getElementById('ddTrigger_' + ddName).classList.remove('open');
    sbCascade(ddName);
}

document.addEventListener('click', e => {
    if (!e.target.closest('.dd-wrap')) {
        document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
        document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
    }
});

// ── Group-wide row hover ──
// The Tahun/Siri/Sidang/Peringkat cell is merged (rowspan) into only the
// first <tr> of its group, so plain CSS :hover on a later row in that group
// can never reach it — it's simply not part of that row's DOM. Every row
// carries data-group="<index of the group's first row>"; hovering any one
// of them toggles the highlight class on all rows sharing that value.
(function () {
    const table = document.getElementById('silibusTable');
    if (!table) return;
    table.querySelectorAll('tbody tr[data-group]').forEach(tr => {
        const groupRows = table.querySelectorAll('tbody tr[data-group="' + tr.dataset.group + '"]');
        tr.addEventListener('mouseenter', () => groupRows.forEach(r => r.classList.add('silibus-row-hover')));
        tr.addEventListener('mouseleave', () => groupRows.forEach(r => r.classList.remove('silibus-row-hover')));
    });
})();

// Each of the 6 filters is independent — picking one no longer clears or
// gates any of the others. Just resubmit with whatever's currently selected.
function sbCascade(changedId) {
    document.getElementById('silibus-filter-form').submit();
}

// Full filtered dataset from PHP (ignores pagination — exports everything the filters matched, not just the current page)
const silibusExportData = <?php echo json_encode($silibusExportData, JSON_UNESCAPED_UNICODE); ?>;

function getSilibusExportRows() {
    return silibusExportData.map(r => [r.year, r.siri, r.sidang, r.tahap, r.ujian, r.kriteria]);
}

function exportSilibusExcel() {
    const headers = ["Tahun", "Siri", "Sidang", "Peringkat (Tahap)", "Nama Ujian", "Kriteria / Deskripsi"];
    const rows = getSilibusExportRows();
    const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Silibus");
    XLSX.writeFile(wb, "Silibus_Export.xlsx");
}

function exportSilibusPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(14);
    doc.text("Senarai Silibus Mengikut Sidang dan Tahap — ProMarkah", 40, 38);

    const rows = getSilibusExportRows().map(row => {
        const kriteria = row[5]
            .split('\n')
            .map(line => line.trim())
            .filter(line => line !== '')
            .map(line => '•  ' + line)
            .join('\n');
        return [...row.slice(0, 5), kriteria];
    });
    const isDark = !document.documentElement.classList.contains('pm-light');

    doc.autoTable({
        head: [["Tahun", "Siri", "Sidang", "Peringkat (Tahap)", "Nama Ujian", "Kriteria / Deskripsi"]],
        body: rows,
        startY: 52,
        rowPageBreak: 'avoid',
        styles: { fontSize: 8, cellPadding: 5, lineHeightFactor: 1.6,
                  fillColor: isDark ? [24,24,24] : [255,255,255],
                  textColor: isDark ? [255,255,255] : [30,30,30],
                  lineColor: isDark ? [60,60,60] : [220,220,220],
                  lineWidth: 0.5 },
        headStyles: { fillColor: [214,40,40], textColor: [255,255,255], fontStyle: 'bold' },
        alternateRowStyles: { fillColor: isDark ? [35,35,35] : [245,245,245] },
        columnStyles: { 5: { cellWidth: 260 } },
    });

    doc.setProperties({ title: 'SILIBUS_EXPORT' });

    // ── IN-PAGE PREVIEW (bypasses popup blockers — same pattern as leaderboard.php) ──
    const pdfBlob = doc.output('blob', { type: 'application/pdf' });
    const pdfUrl = URL.createObjectURL(pdfBlob);

    const overlay = document.createElement('div');
    overlay.style.cssText = 'position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.85); z-index: 9999; display: flex; flex-direction: column;';

    const topBar = document.createElement('div');
    topBar.style.cssText = 'width: 100%; padding: 12px 24px; background: #1f1f1f; display: flex; justify-content: space-between; align-items: center; box-sizing: border-box;';

    const titleSpan = document.createElement('span');
    titleSpan.innerText = 'SILIBUS_EXPORT.pdf';
    titleSpan.style.cssText = 'color: white; font-family: "DM Sans", sans-serif; font-weight: bold; letter-spacing: 0.05em; font-size: 0.9rem;';

    const closeBtn = document.createElement('button');
    closeBtn.innerText = '✖ Tutup Preview';
    closeBtn.style.cssText = 'background: var(--c-red); color: white; border: none; padding: 8px 16px; border-radius: 4px; font-weight: bold; cursor: pointer; font-family: "DM Sans", sans-serif; font-size: 0.85rem;';
    closeBtn.onclick = () => {
        document.body.removeChild(overlay);
        URL.revokeObjectURL(pdfUrl);
    };

    topBar.appendChild(titleSpan);
    topBar.appendChild(closeBtn);

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
