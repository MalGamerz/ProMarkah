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
        'kuning' => '#facc15', // yellow-400 — was #ca8a04 (amber-600), which read too close to gold-medal gold
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

<?php
$pm_sil_css_v = @filemtime(__DIR__ . '/silibus.css') ?: time();
?>
<link rel="stylesheet" href="silibus.css?v=<?= $pm_sil_css_v ?>">

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
const silibusExportData = <?php echo json_encode($silibusExportData, JSON_UNESCAPED_UNICODE); ?>;
</script>
<?php
$pm_sil_js_v = @filemtime(__DIR__ . '/silibus.js') ?: time();
?>
<script src="silibus.js?v=<?= $pm_sil_js_v ?>"></script>

</main>
</body>
</html>
