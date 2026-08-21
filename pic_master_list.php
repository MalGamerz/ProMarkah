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

<?php
$pm_pml_css_v = @filemtime(__DIR__ . '/pic_master_list.css') ?: time();
?>
<link rel="stylesheet" href="pic_master_list.css?v=<?= $pm_pml_css_v ?>">

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
            echo "<div class=\"dd-panel\" id=\"ddPanel_{$ddName}\" role=\"listbox\">";
            echo "<div class=\"dd-search-box\"><input type=\"text\" placeholder=\"Cari...\" oninput=\"ddFilter('{$ddName}',this.value)\" onclick=\"event.stopPropagation()\"></div>";
            echo "<div class=\"dd-options\" id=\"ddOpts_{$ddName}\">";
            $emptySel = $currentVal === '' ? 'selected' : '';
            echo "<div class=\"dd-opt {$emptySel}\" role=\"option\" tabindex=\"0\" data-value=\"\" onclick=\"ddSelect('{$ddName}','','" . htmlspecialchars($emptyLabel, ENT_QUOTES) . "')\">" . htmlspecialchars($emptyLabel) . "</div>";
            foreach ($options as $opt) {
                $sel = ((string)$opt['value'] === (string)$currentVal && $currentVal !== '') ? 'selected' : '';
                $valEsc = htmlspecialchars($opt['value'], ENT_QUOTES);
                $lblEsc = htmlspecialchars($opt['label'], ENT_QUOTES);
                echo "<div class=\"dd-opt {$sel}\" role=\"option\" tabindex=\"0\" data-value=\"{$valEsc}\" onclick=\"ddSelect('{$ddName}','{$valEsc}','{$lblEsc}')\">" . htmlspecialchars($opt['label']) . "</div>";
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

<?php
$pm_pml_js_v = @filemtime(__DIR__ . '/pic_master_list.js') ?: time();
?>
<script src="pic_master_list.js?v=<?= $pm_pml_js_v ?>"></script>
</main>
</body>

</html>