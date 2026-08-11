<?php
// ── Production error handling ──────────────────────────────────────────────
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

// ── AJAX endpoint for real-time master list updates ────────────────────────
if (isset($_GET['ajax_master_list'])) {
    $filter_session = $_GET['session_id'] ?? '';
    $filter_level = $_GET['level_id'] ?? '';
    $filter_school = $_GET['school_id'] ?? '';
    $filter_gender = $_GET['gender'] ?? '';
    $filter_year = $_GET['year'] ?? '';
    $search = $_GET['search'] ?? '';
    $sort_name = $_GET['sort_name'] ?? ''; // New sort variable

    // Scope to "Siri Aktif" — se (sessions) is already joined below.
    $active_siri = (int)($_SESSION['active_siri_id'] ?? 0);

    $whereArr = ["1=1"];
    $bindTypes = '';
    $bindVals = [];

    if ($filter_session !== '') {
        $whereArr[] = "l.session_id = ?";
        $bindTypes .= 'i';
        $bindVals[] = (int) $filter_session;
    }
    if ($filter_level !== '') {
        $whereArr[] = "st.level_id = ?";
        $bindTypes .= 'i';
        $bindVals[] = (int) $filter_level;
    }
    if ($filter_school !== '') {
        $whereArr[] = "st.school_id = ?";
        $bindTypes .= 'i';
        $bindVals[] = (int) $filter_school;
    }
    if ($filter_gender !== '') {
        $whereArr[] = "st.gender = ?";
        $bindTypes .= 's';
        $bindVals[] = $filter_gender;
    }
    if ($filter_year !== '') {
        $whereArr[] = "st.year = ?";
        $bindTypes .= 's';
        $bindVals[] = $filter_year;
    }
    if ($search !== '') {
        $whereArr[] = "st.student_name LIKE ?";
        $bindTypes .= 's';
        $bindVals[] = '%' . $search . '%';
    }
    if ($active_siri > 0) {
        // A student belongs to the active siri via EITHER their level's
        // session OR a competition group's session — a student with no
        // level (or no group) shouldn't be hidden just because one path
        // is empty.
        $whereArr[] = "(se.siri_id = ? OR gse.siri_id = ?)";
        $bindTypes .= 'ii';
        $bindVals[] = $active_siri;
        $bindVals[] = $active_siri;
    }

    $whereSql = implode(" AND ", $whereArr);

    $sql = "
        SELECT st.student_id, st.student_name, st.gender, st.year,
               COALESCE(sc.school_name,'Tiada Rekod') AS school_name,
               COALESCE(l.level_name,'Tiada Rekod')   AS level_name,
               COALESCE(se.session_name,'Tiada Rekod') AS session_name,
               COALESCE(si.siri_name, gsi.siri_name, 'Tiada Rekod') AS siri_name,
               SUM(scores.mark)  AS total_mark,
               SUM(c.max_mark)   AS max_mark,
               GROUP_CONCAT(DISTINCT j.name SEPARATOR ', ') AS judges
        FROM students st
        LEFT JOIN schools  sc ON st.school_id = sc.school_id
        LEFT JOIN levels   l  ON st.level_id  = l.level_id
        LEFT JOIN sessions se ON l.session_id = se.session_id
        LEFT JOIN siri     si ON se.siri_id   = si.siri_id
        LEFT JOIN group_students gs ON st.student_id = gs.student_id
        LEFT JOIN `groups` g ON gs.group_id = g.group_id
        LEFT JOIN levels   gl ON g.level_id = gl.level_id
        LEFT JOIN sessions gse ON gl.session_id = gse.session_id
        LEFT JOIN siri    gsi ON gse.siri_id  = gsi.siri_id
        LEFT JOIN judges   j  ON g.judge_id  = j.id
        LEFT JOIN scores      ON scores.student_id = st.student_id
        LEFT JOIN criteria c  ON scores.criteria_id = c.criteria_id
        WHERE $whereSql
        GROUP BY st.student_id
    ";

    // Conditionally apply sorting
    if ($sort_name === 'ASC') {
        $sql .= " ORDER BY st.student_name ASC";
    } elseif ($sort_name === 'DESC') {
        $sql .= " ORDER BY st.student_name DESC";
    } else {
        // Default sorting
        $sql .= " ORDER BY st.year DESC, se.session_name, l.level_name, sc.school_name, st.student_name";
    }

    $stmt = $conn->prepare($sql);
    if ($bindTypes)
        $stmt->bind_param($bindTypes, ...$bindVals);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    $no = 1;
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $tot = $row['total_mark'] ?: 0;
            $max = $row['max_mark'] ?: 0;
            $pct = $max > 0 ? round(($tot / $max) * 100, 1) : 0;
            $judges = htmlspecialchars($row['judges'] ?: '-');

            // Translate gender to Malay
            $jantina = $row['gender'];
            if ($jantina === 'Male') {
                $jantina = 'Lelaki';
            } elseif ($jantina === 'Female') {
                $jantina = 'Perempuan';
            }

            echo "<tr class='master-row'>
                    <td style='text-align:center; color: var(--c-text-faint);'>$no</td>
                    <td><span class='student-name'>" . htmlspecialchars($row['student_name']) . "</span></td>
                    <td style='color:var(--c-text-muted);'>" . htmlspecialchars($jantina) . "</td>
                    <td><span class='year-val'>" . htmlspecialchars($row['year']) . "</span></td>
                    <td style='color:var(--c-text-muted);'>" . htmlspecialchars($row['school_name']) . "</td>
                    <td style='color:var(--c-text-muted);'>" . htmlspecialchars($row['level_name']) . "</td>
                    <td><span class='session-badge'>" . htmlspecialchars($row['session_name']) . "</span></td>
                    <td style='color:var(--c-text-muted); font-size:0.85rem;'>" . htmlspecialchars($row['siri_name']) . "</td>
                    <td>
                        <div class='mark-pill'>
                            $tot / $max <span class='mark-pct'>($pct%)</span>
                        </div>
                    </td>
                    <td style='color:var(--c-text-muted); font-size:0.9rem;'>$judges</td>
                  </tr>";
            $no++;
        }
    } else {
        echo "<tr><td colspan='10' style='text-align:center;padding:30px;color:var(--c-text-faint);'>Tiada rekod ditemui untuk tapisan ini.</td></tr>";
    }
    exit();
}

$pm_page = 'master_list';
include 'layout.php';
?>

<style>
    /* ── TABLE HEADER: theme-aware, no hardcoded dark ── */
    /* Matches the canonical .pm-table th (dashboard.css) — Bebas Neue here
       made this page's table read bigger than the rest of the app. */
    .master-table th {
        background: var(--c-surface-2) !important;
        color: var(--c-text-muted) !important;
        font-family: 'DM Sans', sans-serif;
        font-size: var(--text-xs) !important;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        position: sticky;
        top: 0;
        z-index: 10;
        border: none !important;
        box-shadow: 0 2px 0 var(--c-red);
        white-space: nowrap;
    }

    /* ── TABLE CELLS: use theme text colors ── */
    .master-table td {
        vertical-align: middle !important;
        border-bottom: 1px solid var(--c-border);
        color: var(--c-text);
    }

    .master-table td .student-name {
        font-weight: 600;
        font-size: 0.9rem;
        letter-spacing: 0.3px;
        color: var(--c-text);
    }

    .master-table td .year-val {
        font-weight: 600;
        color: var(--c-text);
    }

    .master-table td .mark-pill {
        background: var(--c-surface-0);
        border: 1px solid var(--c-border-strong);
        color: var(--c-text);
        padding: 4px 12px;
        border-radius: 20px;
        font-weight: 700;
        font-size: 1rem;
        font-family: "DM Mono", monospace;
        display: inline-flex;
        align-items: baseline;
        gap: 4px;
        white-space: nowrap;
    }

    .master-table td .mark-pct {
        font-family: "DM Sans", sans-serif;
        font-size: 0.65rem;
        font-weight: 600;
        text-transform: uppercase;
        color: var(--c-text-faint);
    }

    .master-table td .session-badge {
        background: rgba(214,40,40,0.15);
        padding: 4px 8px;
        border-radius: 4px;
        color: var(--c-red);
        font-weight: 600;
        font-size: 0.85rem;
        white-space: nowrap;
        display: inline-block;
    }

    /* ── TABLE SCROLL: height is set dynamically in JS
       (fitMasterListHeight) so it always leaves room for the pagination bar
       below it instead of a static calc(100vh - Npx) that goes stale
       whenever the filter UI above the table changes height. ── */
    .table-scroll-wrapper {
        overflow-y: auto;
        position: relative;
        scrollbar-width: thin;
        scrollbar-color: var(--c-surface-3) transparent;
    }
    @media (max-width: 640px) {
        .table-scroll-wrapper { max-height: none !important; overflow-y: visible !important; }
    }

    /* ── PAGINATION: shared .vm-pagination styles now live once in
       dashboard.css (loaded by layout.php), used by every paginated page. ── */

    /* ── FILTER FORM ── */
    #masterFilterForm label {
        display: block;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        color: var(--c-text-faint);
        margin-bottom: 5px;
    }

    #masterFilterForm input,
    #masterFilterForm select {
        background: var(--c-surface-2);
        border: 1px solid var(--c-border-strong);
        color: var(--c-text);
        border-radius: 6px;
        padding: 8px 10px;
        font-size: 0.875rem;
        font-family: 'DM Sans', sans-serif;
        outline: none;
        width: 100%;
        transition: border-color 0.2s, box-shadow 0.2s;
    }

    #masterFilterForm input:focus,
    #masterFilterForm select:focus {
        border-color: var(--c-red);
        box-shadow: 0 0 0 3px var(--c-red-dim);
    }

    #masterFilterForm input::placeholder {
        color: var(--c-text-faint);
    }

    /* ── SEARCHABLE DROPDOWN ──
       #nameDropdownTrigger uses the shared .dd-trigger class (dashboard.css)
       directly instead of a hand-rolled .pm-input style, so it's pixel-
       identical (height:32px, padding, font-size, colors — all !important
       there) to the other 5 filters on this form, which all render through
       renderMasterDD()'s .dd-trigger markup. That component's arrow is
       normally a flex child of .dd-trigger; an <input> can't render a real
       child, so this arrow is a sibling span instead, absolutely positioned
       to land in the same visual spot. */
    .name-dropdown-wrapper {
        position: relative;
    }
    .name-dropdown-wrapper #nameDropdownTrigger {
        padding-right: 28px !important; /* clears the arrow so text can't run under it */
    }
    .name-dropdown-wrapper > .dd-arrow {
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--c-text-faint);
        font-size: 0.65rem;
        pointer-events: none;
        transition: transform .2s;
    }
    .name-dropdown-wrapper:has(.name-dropdown-box.open) > .dd-arrow {
        transform: translateY(-50%) rotate(180deg);
    }

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

    .name-dropdown-box .dd-search {
        position: sticky;
        top: 0;
        background: var(--c-surface-2);
        padding: 8px;
        border-bottom: 1px solid var(--c-border);
    }

    .name-dropdown-box .dd-search input {
        width: 100%;
        padding: 6px 10px;
        border-radius: 4px;
        border: 1px solid var(--c-border-strong);
        background: var(--c-surface-1);
        color: var(--c-text);
        font-size: 0.85rem;
        outline: none;
    }

    .name-dropdown-box .dd-option {
        padding: 8px 12px;
        cursor: pointer;
        font-size: 0.875rem;
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

    .name-dropdown-box .dd-empty {
        padding: 12px;
        font-size: 0.85rem;
        color: var(--c-text-faint);
        text-align: center;
    }
    
    @media (max-width: 768px) {
        .master-table th,
        .master-table td { font-size: 0.75rem; padding: 6px 8px; }
        .master-table td .student-name { font-weight: 600; font-size: 0.85rem; }
        .master-table td .mark-pill { font-size: 0.8rem; padding: 3px 8px; }
        .pm-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    }

    /* ── Searchable dropdown (avoids native <select> popup — Chromium/GPU
       renders the OS listbox solid black for a moment before painting) ── */
    .dd-wrap { position: relative; }
    .dd-trigger{
        display:flex;
        align-items:center;
        justify-content:space-between;
        height:46px;
        box-sizing:border-box;
        width:100%;
        padding:0 14px;
        background:var(--c-surface-2);
        border:1px solid var(--c-border-strong);
        border-radius:8px;
        color:var(--c-white);
        font-size:.875rem;
        cursor:pointer;
        user-select:none;
        transition: border-color .2s, box-shadow .2s, background .2s;
    }
    .dd-trigger:hover{ border-color:var(--c-red); }
    .dd-trigger.open{ border-color:var(--c-red); box-shadow:0 0 0 3px var(--c-red-dim); }
    .dd-trigger.dd-trigger-disabled{ opacity:.5; cursor:not-allowed; }
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
</style>

<div style='display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;'>
    <div>
        <h2
            style='color: var(--c-text); font-family: "Bebas Neue", sans-serif; font-size: 1.8rem; letter-spacing: 0.05em; margin-bottom: 4px;'>
            📋 Senarai Induk Pesilat <span
                style='font-size:0.4em; color:#fff; vertical-align:middle; background:var(--c-red); padding:2px 6px; border-radius:4px; letter-spacing:0.1em;'>LIVE</span>
        </h2>
        <div style='color:var(--c-text-faint); font-size:0.85rem;'>Markah dan Juri dikemaskini secara automatik.</div>
    </div>
</div>

<div class='pm-card' style='margin-bottom:20px; border-color: var(--c-border-strong);'>
    <form id='masterFilterForm' method='GET'
        style='display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; align-items:end;'>
        <div>
            <label>Carian Nama</label>
            <div class="name-dropdown-wrapper" id="nameDropdownWrapper">
                <input type="text" id="nameDropdownTrigger" class="dd-trigger" placeholder="Semua Pesilat"
                    readonly style="cursor:pointer; width:100%; box-sizing:border-box;"
                    onclick="toggleNameDropdown()">
                <span class="dd-arrow">▼</span>
                <!-- Hidden input carries the actual search value to the form -->
                <input type="hidden" name="search" id="nameSearchValue" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                <div class="name-dropdown-box" id="nameDropdownBox">
                    <div class="dd-search">
                        <input type="text" id="nameDropdownSearch" placeholder="Taip untuk cari..." oninput="filterNameOptions(this.value)" onclick="event.stopPropagation()">
                    </div>
                    <div id="nameDropdownList">
                        <!-- Options injected by JS after data loads -->
                    </div>
                </div>
            </div>
        </div>
        <?php
        $active_siri_main = (int)($_SESSION['active_siri_id'] ?? 0);

        // Renders one dd-wrap filter dropdown for masterFilterForm — a hidden
        // input keeps the form field name/value FormData() already reads,
        // while the visible UI is the JS-rendered dropdown (no native <select>
        // popup, which is what caused the black-flash lag).
        function renderMasterDD(string $fieldName, string $ddName, string $emptyLabel, array $options, $currentVal) {
            echo "<div class=\"dd-wrap\" id=\"ddWrap_{$ddName}\">";
            echo "<div class=\"dd-trigger\" id=\"ddTrigger_{$ddName}\" onclick=\"ddToggle('{$ddName}')\" role=\"button\" tabindex=\"0\" aria-haspopup=\"listbox\">";
            $curLabel = $emptyLabel;
            foreach ($options as $opt) {
                if ((string)$opt['value'] === (string)$currentVal && $currentVal !== '') { $curLabel = $opt['label']; break; }
            }
            $labelColor = $currentVal === '' ? 'color:var(--c-text-faint);' : '';
            echo "<span id=\"ddLabel_{$ddName}\" style=\"{$labelColor}\">" . htmlspecialchars($curLabel) . "</span>";
            echo "<span class=\"dd-arrow\">▼</span></div>";
            echo "<div class=\"dd-panel\" id=\"ddPanel_{$ddName}\">";
            echo "<div class=\"dd-search-box\"><input type=\"text\" placeholder=\"Cari...\" oninput=\"ddFilter('{$ddName}',this.value)\" onclick=\"event.stopPropagation()\"></div>";
            echo "<div class=\"dd-options\" id=\"ddOpts_{$ddName}\">";
            $emptySel = $currentVal === '' ? 'selected' : '';
            echo "<div class=\"dd-opt {$emptySel}\" data-value=\"\" onclick=\"ddSelect('{$ddName}','','" . htmlspecialchars($emptyLabel, ENT_QUOTES) . "')\">" . htmlspecialchars($emptyLabel) . "</div>";
            foreach ($options as $opt) {
                $sel = ((string)$opt['value'] === (string)$currentVal && $currentVal !== '') ? 'selected' : '';
                $valEsc = htmlspecialchars($opt['value'], ENT_QUOTES);
                $lblEsc = htmlspecialchars($opt['label'], ENT_QUOTES);
                echo "<div class=\"dd-opt {$sel}\" data-value=\"{$valEsc}\" onclick=\"ddSelect('{$ddName}','{$valEsc}','{$lblEsc}')\">" . htmlspecialchars($opt['label']) . "</div>";
            }
            echo "</div><div class=\"dd-empty\" id=\"ddEmpty_{$ddName}\">Tiada hasil</div></div>";
            echo "<input type=\"hidden\" name=\"{$fieldName}\" id=\"f_{$ddName}\" value=\"" . htmlspecialchars($currentVal) . "\">";
            echo "</div>";
        }

        // Tahun
        $yearOpts = [];
        $years = $conn->query("SELECT DISTINCT year FROM students ORDER BY year DESC");
        while ($y = $years->fetch_assoc()) $yearOpts[] = ['value' => $y['year'], 'label' => $y['year']];

        // Sidang — tag with siri when "Semua Siri" is active so same-named ones aren't ambiguous
        $sessionOpts = [];
        $sessions = $active_siri_main > 0
            ? $conn->query("SELECT * FROM sessions WHERE siri_id = $active_siri_main ORDER BY session_name")
            : $conn->query("SELECT se.*, si.siri_name FROM sessions se LEFT JOIN siri si ON se.siri_id = si.siri_id ORDER BY se.session_name");
        while ($s = $sessions->fetch_assoc()) {
            $optLabel = $s['session_name'];
            if ($active_siri_main === 0 && !empty($s['siri_name'])) $optLabel .= " — " . $s['siri_name'];
            $sessionOpts[] = ['value' => $s['session_id'], 'label' => $optLabel];
        }

        // Peringkat
        $levelOpts = [];
        $levels = $active_siri_main > 0
            ? $conn->query("SELECT l.* FROM levels l JOIN sessions s ON l.session_id = s.session_id WHERE s.siri_id = $active_siri_main ORDER BY l.level_name")
            : $conn->query("SELECT l.*, si.siri_name FROM levels l LEFT JOIN sessions s ON l.session_id = s.session_id LEFT JOIN siri si ON s.siri_id = si.siri_id ORDER BY l.level_name");
        while ($l = $levels->fetch_assoc()) {
            $optLabel = $l['level_name'];
            if ($active_siri_main === 0 && !empty($l['siri_name'])) $optLabel .= " — " . $l['siri_name'];
            $levelOpts[] = ['value' => $l['level_id'], 'label' => $optLabel];
        }

        // Cawangan
        $schoolOpts = [];
        $schools = $conn->query("SELECT * FROM schools ORDER BY school_name");
        while ($sch = $schools->fetch_assoc()) $schoolOpts[] = ['value' => $sch['school_id'], 'label' => $sch['school_name']];

        // Jantina
        $genderOpts = [['value' => 'Male', 'label' => 'Lelaki'], ['value' => 'Female', 'label' => 'Perempuan']];
        ?>
        <div>
            <label>Tahun</label>
            <?php renderMasterDD('year', 'year', 'Semua Tahun', $yearOpts, $_GET['year'] ?? ''); ?>
        </div>
        <div>
            <label>Sidang</label>
            <?php renderMasterDD('session_id', 'msession', 'Semua Sidang', $sessionOpts, $_GET['session_id'] ?? ''); ?>
        </div>
        <div>
            <label>Peringkat</label>
            <?php renderMasterDD('level_id', 'mlevel', 'Semua Peringkat', $levelOpts, $_GET['level_id'] ?? ''); ?>
        </div>
        <div>
            <label>Cawangan</label>
            <?php renderMasterDD('school_id', 'mschool', 'Semua Cawangan', $schoolOpts, $_GET['school_id'] ?? ''); ?>
        </div>
        <div>
            <label>Jantina</label>
            <?php renderMasterDD('gender', 'mgender', 'Semua', $genderOpts, $_GET['gender'] ?? ''); ?>
        </div>
    </form>
</div>

<div class='pm-card' style='padding:0; border-color: var(--c-border-strong); overflow:hidden;'>
    <div class='pm-table-wrap table-scroll-wrapper' style='border:none;'>
        <table class='pm-table master-table'>
            <thead>
                <tr>
                    <th style='width: 40px; text-align:center;'>No</th>
                    <th onclick='toggleSortName()' style='cursor:pointer; user-select:none;' title='Susun A-Z / Z-A'>
                        <div style='display:inline-flex; align-items:center; gap:6px;'>
                            Nama Pesilat 
                            <span id='sortIcon' style='display:inline-flex; align-items:center; color:var(--c-text-faint); margin-top:1px;'>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg>
                            </span>
                        </div>
                    </th>
                    <th>Jantina</th>
                    <th>Tahun</th>
                    <th>Cawangan</th>
                    <th>Peringkat</th>
                    <th>Sidang</th>
                    <th>Siri</th>
                    <th>Markah (%)</th>
                    <th>Juri Bertugas</th>
                </tr>
            </thead>
            <tbody id='master_tbody'>
                <tr>
                    <td colspan='10' style='text-align:center;padding:30px;color:var(--c-text-faint);'>Memuatkan data secara langsung...</td>
                </tr>
            </tbody>
        </table>
    </div>
    <!-- Pagination footer -->
    <div class="vm-pagination" id="masterPaginationContainer" style="display:none;">
        <div class="vm-page-info" id="masterPageInfo">Memaparkan 0 rekod</div>
        <div class="vm-page-btns" id="masterPaginationButtons">
            <!-- Injected by JS -->
        </div>
    </div>
</div>

<script>
    let pmMasterCurrentPage = 1;
    const pmMasterRowsPerPage = 20;
    let pmMasterRows = [];
    let pmSortNameDir = '';
    let allStudentNames = [];

    // ── Searchable dropdown logic (matches pic_students.php's dd-wrap) ──
    function ddToggle(name) {
        const trigger = document.getElementById('ddTrigger_' + name);
        if (trigger.classList.contains('dd-trigger-disabled')) return;
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

    function ddSelect(name, value, label) {
        document.getElementById('f_' + name).value = value;
        const lbl = document.getElementById('ddLabel_' + name);
        lbl.textContent = label;
        lbl.style.color = value === '' ? 'var(--c-text-faint)' : '';
        document.querySelectorAll('#ddOpts_' + name + ' .dd-opt').forEach(o => o.classList.toggle('selected', o.dataset.value === value));
        document.getElementById('ddPanel_' + name).classList.remove('open');
        document.getElementById('ddTrigger_' + name).classList.remove('open');
        fetchMasterData(true);
    }

    document.addEventListener('click', e => {
        if (!e.target.closest('.dd-wrap')) {
            document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
            document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
        }
    });

    // ── Pagination render ─────────────────────────────────────────
    function updateMasterPagination() {
        const tbody = document.getElementById('master_tbody');
        pmMasterRows = tbody ? Array.from(tbody.querySelectorAll('tr')).filter(tr => tr.cells.length > 1) : [];

        const container = document.getElementById('masterPaginationContainer');
        if (pmMasterRows.length === 0) { container.style.display = 'none'; return; }
        container.style.display = 'flex';

        const totalRows  = pmMasterRows.length;
        const totalPages = Math.ceil(totalRows / pmMasterRowsPerPage) || 1;

        if (pmMasterCurrentPage > totalPages) pmMasterCurrentPage = totalPages;
        if (pmMasterCurrentPage < 1)          pmMasterCurrentPage = 1;

        const start = (pmMasterCurrentPage - 1) * pmMasterRowsPerPage;
        const end   = start + pmMasterRowsPerPage;

        pmMasterRows.forEach((row, i) => { row.style.display = (i >= start && i < end) ? '' : 'none'; });

        const startText = totalRows === 0 ? 0 : start + 1;
        const endText   = Math.min(end, totalRows);
        document.getElementById('masterPageInfo').innerHTML =
            `Memaparkan <b>${startText} - ${endText}</b> daripada <b>${totalRows}</b> rekod`;

        pmRenderPagination(document.getElementById('masterPaginationButtons'), pmMasterCurrentPage, totalPages, masterGoToPage);
    }

    window.masterGoToPage = function(page) {
        pmMasterCurrentPage = page;
        updateMasterPagination();
        document.querySelector('.table-scroll-wrapper').scrollTo({ top: 0, behavior: 'smooth' });
    };

    // ── Fit the master list table + pagination into the viewport, no page scroll ──
    function fitMasterListHeight() {
        const scrollEl = document.querySelector('.table-scroll-wrapper');
        const pagination = document.getElementById('masterPaginationContainer');
        if (!scrollEl || !pagination) return;
        if (window.innerWidth <= 640) {
            scrollEl.style.maxHeight = '';
            return;
        }
        const top = scrollEl.getBoundingClientRect().top;
        const paginationH = pagination.offsetHeight;
        const available = window.innerHeight - top - paginationH - 24; // 24px bottom breathing room
        scrollEl.style.maxHeight = Math.max(150, available) + 'px';
    }
    window.addEventListener('resize', fitMasterListHeight);

    // ── Sort ─────────────────────────────────────────────────────
    function toggleSortName() {
        if (pmSortNameDir === '') pmSortNameDir = 'ASC';
        else if (pmSortNameDir === 'ASC') pmSortNameDir = 'DESC';
        else pmSortNameDir = '';

        const icon = document.getElementById('sortIcon');
        if (pmSortNameDir === 'ASC') {
            icon.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--c-red)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m6 15 6-6 6 6"/></svg>';
        } else if (pmSortNameDir === 'DESC') {
            icon.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--c-red)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>';
        } else {
            icon.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg>';
        }
        fetchMasterData(true);
    }

    // ── Searchable name dropdown ──────────────────────────────────
    function toggleNameDropdown() {
        const box = document.getElementById('nameDropdownBox');
        box.classList.toggle('open');
        if (box.classList.contains('open')) {
            document.getElementById('nameDropdownSearch').focus();
            filterNameOptions('');
        }
    }

    function filterNameOptions(keyword) {
        const list = document.getElementById('nameDropdownList');
        const kw = keyword.toLowerCase();
        const current = document.getElementById('nameSearchValue').value;

        // "Semua Pesilat" always first
        let html = `<div class="dd-option ${current === '' ? 'selected' : ''}" onclick="selectNameOption('', 'Semua Pesilat')">Semua Pesilat</div>`;

        const filtered = allStudentNames.filter(n => n.toLowerCase().includes(kw));
        if (filtered.length === 0 && kw !== '') {
            html += `<div class="dd-empty">Tiada hasil ditemui</div>`;
        } else {
            filtered.forEach(name => {
                const sel = current === name ? 'selected' : '';
                html += `<div class="dd-option ${sel}" onclick="selectNameOption('${name.replace(/'/g, "\'")}', '${name.replace(/'/g, "\'")}')">${name}</div>`;
            });
        }
        list.innerHTML = html;
    }

    function selectNameOption(value, label) {
        document.getElementById('nameSearchValue').value = value;
        document.getElementById('nameDropdownTrigger').value = label;
        document.getElementById('nameDropdownBox').classList.remove('open');
        fetchMasterData(true);
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        const wrapper = document.getElementById('nameDropdownWrapper');
        if (wrapper && !wrapper.contains(e.target)) {
            document.getElementById('nameDropdownBox').classList.remove('open');
        }
    });

    // ── Fetch ─────────────────────────────────────────────────────
    // 8s base rather than 3s — this re-runs the full filtered/aggregated query
    // (JOINs + SUM + GROUP_CONCAT across every matching student), unlike the
    // sidebar's notification poll which only checks a cheap MAX(score_id)
    // before doing real work. Still feels live, at a third of the DB load.
    // Same backoff-on-failure + pause-when-hidden pattern as layout.php's
    // notification poller and leaderboard.php's live refresh, for consistency.
    const PM_MASTER_BASE_DELAY = 8000;
    const PM_MASTER_MAX_DELAY  = 60000;
    let pmMasterFailCount = 0;
    let pmMasterTimer = null;

    function fetchMasterData(isManual = false) {
        const form = document.getElementById('masterFilterForm');
        const params = new URLSearchParams(new FormData(form));
        if (pmSortNameDir !== '') params.append('sort_name', pmSortNameDir);

        pmFetch('pic_master_list.php?ajax_master_list=1&' + params.toString())
            .then(r => r.text())
            .then(html => {
                pmMasterFailCount = 0;
                document.getElementById('master_tbody').innerHTML = html;
                if (isManual) pmMasterCurrentPage = 1;

                // Rebuild name list from current results (unfiltered — fetch all names once)
                if (isManual && document.getElementById('nameSearchValue').value === '') {
                    const rows = document.querySelectorAll('#master_tbody tr.master-row');
                    const names = [];
                    rows.forEach(row => {
                        const nameEl = row.querySelector('.student-name');
                        if (nameEl) names.push(nameEl.textContent.trim());
                    });
                    allStudentNames = [...new Set(names)].sort();
                    filterNameOptions('');
                }

                updateMasterPagination();
                fitMasterListHeight();
            })
            .catch(err => { console.error(err); pmMasterFailCount++; })
            .finally(() => {
                if (isManual) return; // manual refreshes don't drive the recurring timer
                if (pmMasterTimer) clearTimeout(pmMasterTimer);
                const delay = Math.min(PM_MASTER_BASE_DELAY * Math.pow(2, pmMasterFailCount), PM_MASTER_MAX_DELAY);
                pmMasterTimer = setTimeout(() => fetchMasterData(false), delay);
            });
    }

    fetchMasterData(true);
    pmMasterTimer = setTimeout(() => fetchMasterData(false), PM_MASTER_BASE_DELAY);

    // Pause polling while the tab isn't visible, resume with an immediate
    // refresh when it is — no point re-running the aggregate query every 8s
    // for a tab nobody is looking at.
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            if (pmMasterTimer) clearTimeout(pmMasterTimer);
        } else {
            if (pmMasterTimer) clearTimeout(pmMasterTimer);
            fetchMasterData(false);
        }
    });
</script>
</main>
</body>

</html>