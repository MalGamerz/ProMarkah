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

$pm_page = 'medal_settings';

include 'layout.php';



// ── Determine active scope ─────────────────────────────────────────────────

// Scope: 'overall' | 'sidang' | 'peringkat' | 'sekolah'

$scope = $_GET['scope'] ?? 'peringkat';

$filter_session = $_GET['session_id'] ?? '';

$filter_level   = $_GET['level_id']   ?? '';



$validScopes = ['overall', 'sidang', 'peringkat', 'sekolah'];

if (!in_array($scope, $validScopes)) $scope = 'peringkat';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ── Handle POST save ───────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quotas'])) {
    // The POST form has no action="" so it submits back to this same URL —
    // its query string (scope/session_id/level_id, the current tab/filter
    // the user was on) is still readable via $_GET even on this POST request.
    // Carry those through the redirect so saving doesn't bounce the user back
    // to the default scope/filter view.
    $returnParams = array_filter([
        'scope'      => $_GET['scope']      ?? null,
        'session_id' => $_GET['session_id'] ?? null,
        'level_id'   => $_GET['level_id']   ?? null,
    ], fn($v) => $v !== null && $v !== '');

    if (!isset($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token'])) {
        $qs = http_build_query($returnParams + ['msg' => 'Ralat token keselamatan. Sila muat semula halaman.', 'status' => 'error']);
        header("Location: pic_medal_settings.php?{$qs}"); exit();
    }



    // overall  → medal_quotas_overall (gold_quota, silver_quota, bronze_quota) — single row

    // sidang   → medal_quotas_session (session_id, gold_quota, …)

    // peringkat→ medal_quotas          (level_id,   gold_quota, …)

    // sekolah  → medal_quotas_school  (school_id,  session_id?, gold_quota, …)



    $postedScope = $_POST['scope'] ?? 'peringkat';

    try {

    if ($postedScope === 'overall') {

        // Single global row

        $q = $_POST['quotas'][0] ?? ['gold' => 0, 'silver' => 0, 'bronze' => 0];

        // Clamped to >=0 — gold_quota/silver_quota/bronze_quota are plain
        // signed int columns (not UNSIGNED), so a negative submission would
        // otherwise persist silently with nothing else to catch it.
        $g = max(0, (int)$q['gold']);

        $s = max(0, (int)$q['silver']);

        $b = max(0, (int)$q['bronze']);

        // Use a config table; fall back to medal_quotas with level_id = 0 as sentinel

        $conn->query("DELETE FROM medal_quotas WHERE level_id = 0");

        if ($g || $s || $b) {

            $stmt = $conn->prepare("INSERT INTO medal_quotas (level_id, gold_quota, silver_quota, bronze_quota) VALUES (0, ?, ?, ?)");

            $stmt->bind_param('iii', $g, $s, $b);

            $stmt->execute();

            $stmt->close();

        }



    } elseif ($postedScope === 'sidang') {

        $stmtDel = $conn->prepare("DELETE FROM medal_quotas_session WHERE session_id = ?");

        $stmtIns = $conn->prepare("INSERT INTO medal_quotas_session (session_id, gold_quota, silver_quota, bronze_quota)

                                   VALUES (?, ?, ?, ?)

                                   ON DUPLICATE KEY UPDATE gold_quota=VALUES(gold_quota), silver_quota=VALUES(silver_quota), bronze_quota=VALUES(bronze_quota)");

        foreach ($_POST['quotas'] as $sid => $q) {

            $sid = (int)$sid;

            $g   = max(0, (int)$q['gold']);

            $s   = max(0, (int)$q['silver']);

            $b   = max(0, (int)$q['bronze']);

            if ($g === 0 && $s === 0 && $b === 0) {

                $stmtDel->bind_param('i', $sid);

                $stmtDel->execute();

            } else {

                $stmtIns->bind_param('iiii', $sid, $g, $s, $b);

                $stmtIns->execute();

            }

        }

        if ($stmtDel) $stmtDel->close();

        if ($stmtIns) $stmtIns->close();



    } elseif ($postedScope === 'peringkat') {

        $stmtDel = $conn->prepare("DELETE FROM medal_quotas WHERE level_id = ?");

        $stmtIns = $conn->prepare("INSERT INTO medal_quotas (level_id, gold_quota, silver_quota, bronze_quota)

                                   VALUES (?, ?, ?, ?)

                                   ON DUPLICATE KEY UPDATE gold_quota=VALUES(gold_quota), silver_quota=VALUES(silver_quota), bronze_quota=VALUES(bronze_quota)");

        foreach ($_POST['quotas'] as $lid => $q) {

            $lid = (int)$lid;

            if ($lid === 0) continue; // skip global sentinel

            $g   = max(0, (int)$q['gold']);

            $s   = max(0, (int)$q['silver']);

            $b   = max(0, (int)$q['bronze']);

            if ($g === 0 && $s === 0 && $b === 0) {

                $stmtDel->bind_param('i', $lid);

                $stmtDel->execute();

            } else {

                $stmtIns->bind_param('iiii', $lid, $g, $s, $b);

                $stmtIns->execute();

            }

        }

        if ($stmtDel) $stmtDel->close();

        if ($stmtIns) $stmtIns->close();



    } elseif ($postedScope === 'sekolah') {

        $stmtDel = $conn->prepare("DELETE FROM medal_quotas_school WHERE school_id = ?");

        $stmtIns = $conn->prepare("INSERT INTO medal_quotas_school (school_id, gold_quota, silver_quota, bronze_quota)

                                   VALUES (?, ?, ?, ?)

                                   ON DUPLICATE KEY UPDATE gold_quota=VALUES(gold_quota), silver_quota=VALUES(silver_quota), bronze_quota=VALUES(bronze_quota)");

        foreach ($_POST['quotas'] as $scid => $q) {

            $scid = (int)$scid;

            $g    = max(0, (int)$q['gold']);

            $s    = max(0, (int)$q['silver']);

            $b    = max(0, (int)$q['bronze']);

            if ($g === 0 && $s === 0 && $b === 0) {

                $stmtDel->bind_param('i', $scid);

                $stmtDel->execute();

            } else {

                $stmtIns->bind_param('iiii', $scid, $g, $s, $b);

                $stmtIns->execute();

            }

        }

        if ($stmtDel) $stmtDel->close();

        if ($stmtIns) $stmtIns->close();

    }

    $qs = http_build_query($returnParams + ['msg' => 'Tetapan pingat berjaya dikemaskini.', 'status' => 'success']);

    } catch (Throwable $e) {
        promarkah_report('Caught', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
        $qs = http_build_query($returnParams + ['msg' => 'Ralat pangkalan data. Sila cuba lagi.', 'status' => 'error']);
    }

    header("Location: pic_medal_settings.php?{$qs}"); exit();

}

// Renders one dd-wrap filter dropdown — a hidden input keeps the GET field
// name/value the form already submits, while the visible UI is the
// JS-rendered dropdown (no native <select> popup, which is what caused the
// black-flash lag).
function renderMedalDD(string $fieldName, string $ddName, string $emptyLabel, array $options, $currentVal) {
    $currentVal = (string)$currentVal;
    echo "<div class=\"dd-wrap\" id=\"ddWrap_{$ddName}\">";
    echo "<div class=\"dd-trigger\" id=\"ddTrigger_{$ddName}\" onclick=\"ddToggle('{$ddName}')\" role=\"button\" tabindex=\"0\" aria-haspopup=\"listbox\">";
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

    /* ── Scope tab strip (Full Width) ── */
    .scope-strip {
        display: flex;
        gap: 0;
        background: var(--c-surface-2);
        border: 1px solid var(--c-border-strong);
        border-radius: 8px;
        padding: 4px;
        width: 100%; /* Spans full width */
        box-sizing: border-box;
        flex-wrap: wrap;
    }
    
    .scope-tab {
        flex: 1; /* Makes tabs stretch equally */
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 7px;
        padding: 10px 18px;
        border-radius: 6px;
        border: none;
        background: transparent;
        color: var(--c-text-muted);
        font-family: 'DM Mono', monospace;
        font-size: 0.85rem;
        font-weight: 600;
        letter-spacing: 0.04em;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.18s ease;
        white-space: nowrap;
    }

    .scope-tab:hover {
        color: var(--c-white);
        background: var(--c-surface-3);
    }

    .scope-tab.active {
        background: var(--c-red);
        color: #fff;
        box-shadow: 0 2px 10px rgba(214, 40, 40, 0.45);
    }

    .scope-tab .scope-icon {
        font-size: 1.1rem;
    }



    /* ── Inline siri disambiguation tag (Semua Siri mode) ── */
    .siri-inline-tag {
        display: inline-block;
        margin-left: 6px;
        font-size: 0.65rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--c-red);
        background: var(--c-red-dim);
        border: 1px solid var(--c-red-border);
        padding: 2px 8px;
        border-radius: 999px;
    }
    html.pm-light .siri-inline-tag { background: #fdecec; color: var(--c-red-700); border-color: var(--c-red-300); }

    /* ── Scope description badge ── */

    .scope-desc {

        background: var(--c-surface-1);

        border: 1px solid var(--c-border);

        border-left: 3px solid var(--c-red);

        border-radius: 6px;

        padding: 10px 14px;

        color: var(--c-text-muted);

        font-size: 0.82rem;

        line-height: 1.5;

        margin-bottom: 16px;

    }



    /* ── Number inputs ── */

    /* Sized to the 32px control benchmark; DM Sans like every other input. */
    .sleek-num-input {

        width: 56px;

        height: 32px;

        background: var(--c-surface-0);

        border: 1px solid var(--c-border-strong);

        border-radius: 6px;

        color: var(--c-white);

        font-family: 'DM Sans', sans-serif;

        font-size: 0.9rem;

        font-weight: 700;

        text-align: center;

        transition: all 0.2s ease;

        outline: none;

    }



    .sleek-num-input:focus {

        border-color: var(--c-red);

        box-shadow: 0 0 12px rgba(214, 40, 40, 0.4);

        background: var(--c-surface-1);

    }



    .sleek-num-input::-webkit-outer-spin-button,

    .sleek-num-input::-webkit-inner-spin-button {

        -webkit-appearance: none;

        margin: 0;

    }



    /* ── Overall scope single card ── */

    .overall-card {

        display: flex;

        align-items: center;

        justify-content: space-between;

        flex-wrap: wrap;

        gap: 24px;

        background: var(--c-surface-2);

        border: 1px solid var(--c-border-strong);

        border-radius: 10px;

        padding: 28px 32px;

        margin-bottom: 24px;

    }



    .overall-card .label-block {

        display: flex;

        flex-direction: column;

        gap: 4px;

    }



    .overall-card .label-block strong {

        font-family: 'Bebas Neue', sans-serif;

        font-size: 1.4rem;

        letter-spacing: 0.06em;

        color: var(--c-white);

    }



    .overall-card .label-block span {

        font-size: 0.8rem;

        color: var(--c-text-faint);

    }



    .overall-medal-row {

        display: flex;

        gap: 20px;

        align-items: center;

        flex-wrap: wrap;

    }



    .overall-medal-row .medal-cell {

        display: flex;

        flex-direction: column;

        align-items: center;

        gap: 6px;

    }



    .overall-medal-row .medal-cell label {

        font-size: 0.75rem;

        font-family: 'DM Mono', monospace;

        letter-spacing: 0.05em;

        color: var(--c-text-muted);

        font-weight: 600;

    }



    /* ── Table ── */

    /* Matches the canonical .pm-table th (dashboard.css) — was Bebas Neue
       1.15rem, which made this page's table read visibly bigger than every
       other page's. */
    .medal-table th {

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

    }



    .medal-table td {

        vertical-align: middle !important;

    }



    /* ── PAGINATION: shared .vm-pagination styles now live once in
       dashboard.css (loaded by layout.php), used by every paginated page. ── */

    @media (max-width: 640px) {
        .scope-tab { padding: 8px 5px; font-size: 0.75rem; flex-direction: column; gap: 2px; }
    }

    /* ── Scroll Wrapper (Hugs Content & Fits Screen) ──
       Height is set dynamically in JS (fitMedalTableHeight) so it always
       leaves room for the pagination bar below it instead of a static
       calc(100vh - Npx) that goes stale whenever the scope tabs/filter UI
       above the table changes height. ── */
    .table-scroll-wrapper {
        min-height: auto; /* REMOVED fixed minimum height to fix the huge gap */
        overflow-y: auto;
        position: relative;
        scrollbar-width: thin;
        scrollbar-color: var(--c-surface-3) transparent;
        border: none;
    }
    @media (max-width: 640px) {
        .table-scroll-wrapper { max-height: none !important; overflow-y: visible !important; }
    }
    
    /* ── Unified Card Container ── */
    /* Add this to ensure the table and pagination wrap together perfectly */
    .pm-card {
        display: flex;
        flex-direction: column;
        padding: 0 !important; 
        border: 1px solid var(--c-border-strong) !important;
        border-radius: 10px;
        overflow: hidden; /* Clips the table header and pagination corners */
        margin-bottom: 0;
    }

    @media (max-width: 640px) {

        .scope-strip { width: 100%; }

        .scope-tab { flex: 1; justify-content: center; padding: 8px 10px; font-size: 0.75rem; }

        .overall-card { flex-direction: column; }

        .overall-medal-row { width: 100%; justify-content: space-around; }

        .vm-page-btns { flex-direction: column; align-items: stretch; }

    }
    
    /* ── Filter Card (Matches pic_tests.php layout) ── */
    .filter-card {
        background: var(--c-surface-1);
        border: 1px solid var(--c-border-strong);
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 24px;
        box-shadow: 0 4px 12px rgba(0,0,0,.08);
    }

    .filter-card-title {
        font-size: .72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .12em;
        color: var(--c-text-faint);
        margin-bottom: 16px;
    }

    .pic-filter-bar {
        display: flex; /* Same flex-equal-share layout as leaderboard.php's
           .filter-bar/.filter-col and pic_directory.php's .dir-filter —
           every column gets an identical flex-basis of 0 so widths stay
           equal, instead of the old grid's minmax(Npx,1fr) tracks which
           differed page to page. */
        flex-wrap: wrap;
        gap: 8px;
        align-items: flex-end;
    }

    .pic-filter-bar > div {
        flex: 1 1 0;
        min-width: 110px;
    }
    @media (max-width: 640px) {
        .pic-filter-bar { gap: 10px; }
        .pic-filter-bar > div { flex: 1 1 100%; min-width: 0; }
    }
    @media (min-width: 641px) and (max-width: 1024px) {
        .pic-filter-bar > div { flex: 1 1 calc(50% - 8px); }
    }

    .pic-filter-bar label {
        display: block;
        margin-bottom: 6px;
        font-size: .75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: var(--c-text-faint);
    }

    .pic-filter-bar select {
        background: var(--c-surface-2);
        border: 1px solid var(--c-border-strong);
        color: var(--c-white);
        border-radius: 6px;
        padding: 8px 10px;
        font-size: 0.8rem; /* matches the app-wide 32px/0.8rem filter-bar benchmark */
        outline: none;
        width: 100%;
        transition: border-color .2s;
        box-sizing: border-box;
        cursor: pointer;
    }

    .pic-filter-bar select:focus {
        border-color: var(--c-red);
    }
    .pic-filter-bar select option { background: var(--c-surface-2); color: var(--c-white); }

    /* Light mode sync for filter card */
    html.pm-light .filter-card { background: #fff; border-color: var(--c-gray-200); border-top-color: var(--c-red); box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
    html.pm-light .filter-card-title { color: #888; }
    html.pm-light .pic-filter-bar label { color: #555; }
    html.pm-light .pic-filter-bar select { background: var(--c-gray-50); border-color: var(--c-gray-300); color: #111; }
    html.pm-light .pic-filter-bar select option { background: #fff; color: #111; }

    /* Mobile overrides */
    @media (max-width: 640px) {
        .filter-form {
            grid-template-columns: 1fr; /* Stack vertically on mobile */
            gap: 10px;
        }

        .filter-form.single-filter {
            max-width: 100% !important; /* Full width on mobile */
        }
    }

    /* ── Searchable dropdown (avoids native <select> popup — Chromium/GPU
       renders the OS listbox solid black for a moment before painting) ── */
    .dd-wrap { position: relative; }
    .dd-trigger{
        display:flex;
        align-items:center;
        justify-content:space-between;
        /* Must match .dd-trigger's *actual* rendered height, which is the
           global canonical rule in dashboard.css (32px !important) — this
           page's own local copy previously said 38px, silently overridden
           by that !important, so it looked right on paper but was
           misleading versus what actually renders everywhere else. */
        height:32px;
        box-sizing:border-box;
        width:100%;
        padding:0 10px;
        background:var(--c-surface-2);
        border:1px solid var(--c-border-strong);
        border-radius:6px;
        color:var(--c-white);
        font-size:.875rem;
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
</style>



<!-- ── Page header ── -->

<div style='display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:18px;flex-wrap:wrap;gap:12px;'>

    <div>

        <h2 style='color: var(--c-white); font-family: "Bebas Neue", sans-serif; font-size: 1.8rem; letter-spacing: 0.05em;'>

            🏆 Tetapan Kuota Pingat

        </h2>

        <div style='color:var(--c-text-faint);font-size:0.85rem;'>

            Tetapkan jumlah maksimum pingat mengikut skop yang dipilih. Nilai 0 bermaksud penentuan automatik.

        </div>

    </div>

</div>



<?php if (isset($_GET['msg'])):
    $isError = ($_GET['status'] ?? '') === 'error';
?>
<div class="pm-alert <?= $isError ? 'pm-alert-danger' : 'pm-alert-success' ?>">
    <?= $isError ? '⚠️' : '✅' ?> <?= htmlspecialchars($_GET['msg']) ?>
</div>
<?php endif; ?>



<!-- ── Scope selector strip ── -->

<div style='margin-bottom:16px;'>

    <div class='scope-strip'>

        <?php

        $scopes = [

            'overall'   => ['icon' => '🌐', 'label' => 'Keseluruhan'],

            'sidang'    => ['icon' => '📅', 'label' => 'Mengikut Sidang'],

            'peringkat' => ['icon' => '🏆', 'label' => 'Mengikut Peringkat'],

            'sekolah'   => ['icon' => '🏫', 'label' => 'Mengikut Sekolah'],

        ];

        foreach ($scopes as $key => $info) {

            $active = ($scope === $key) ? 'active' : '';

            $url    = "?scope={$key}";

            echo "<a href='{$url}' class='scope-tab {$active}'>

                    <span class='scope-icon'>{$info['icon']}</span>

                    {$info['label']}

                  </a>";

        }

        ?>

    </div>

</div>



<!-- ── Scope description ── -->

<?php

$scopeDesc = [

    'overall'   => '🌐 <strong>Keseluruhan</strong> — Satu kuota global yang digunakan sebagai lalai untuk semua sidang, peringkat, dan sekolah jika tiada tetapan khusus ditemui.',

    'sidang'    => '📅 <strong>Mengikut Sidang</strong> — Tetapkan kuota berasingan bagi setiap sidang. Mengatasi tetapan Keseluruhan jika ditetapkan.',

    'peringkat' => '🏆 <strong>Mengikut Peringkat</strong> — Tetapkan kuota berasingan bagi setiap peringkat dalam sidang. Mengatasi tetapan Sidang dan Keseluruhan.',

    'sekolah'   => '🏫 <strong>Mengikut Sekolah</strong> — Tetapkan kuota berasingan bagi setiap sekolah peserta. Mengatasi semua tetapan lain.',

];

echo "<div class='scope-desc'>{$scopeDesc[$scope]}</div>";

?>

<?php
// ── Peringkat / Sekolah filter dropdowns ────────────────────────────────
// Rendered as their OWN <form method='GET'> here, OUTSIDE and BEFORE the
// <form method='POST'> below (previously these were nested INSIDE the POST
// form, which is invalid HTML — browsers auto-close an outer form when they
// hit a nested one, silently detaching every quota input that followed from
// the real form. That meant clicking "Simpan Tetapan Pingat" on these two
// tabs never actually submitted their values. Moving the filter form here,
// before the POST form opens, fixes the nesting while keeping the exact same
// visual position — it was already the first thing rendered in each tab).
if ($scope === 'peringkat'):
    // Scope to "Siri Aktif"; tag each session with its siri when
    // "Semua Siri" is active so same-named ones aren't ambiguous.
    $active_siri = (int)($_SESSION['active_siri_id'] ?? 0);
    $sessions = $active_siri > 0
        ? $conn->query("SELECT * FROM sessions WHERE siri_id = $active_siri ORDER BY session_name")
        : $conn->query("SELECT se.*, si.siri_name FROM sessions se LEFT JOIN siri si ON se.siri_id = si.siri_id ORDER BY se.session_name");
    $sessionOptsP = [];
    while ($s = $sessions->fetch_assoc()) {
        $optLabel = $s['session_name'];
        if ($active_siri === 0 && !empty($s['siri_name'])) $optLabel .= " — " . $s['siri_name'];
        $sessionOptsP[] = ['value' => $s['session_id'], 'label' => $optLabel];
    }
    $lvlWhere = $filter_session
        ? " WHERE session_id=" . (int)$filter_session
        : ($active_siri > 0 ? " WHERE session_id IN (SELECT session_id FROM sessions WHERE siri_id = $active_siri)" : "");
    $levels = $conn->query("SELECT * FROM levels{$lvlWhere} ORDER BY level_name");
    $levelOptsP = [];
    while ($l = $levels->fetch_assoc()) {
        $levelOptsP[] = ['value' => $l['level_id'], 'label' => $l['level_name']];
    }
    ?>
    <form method='GET'>
        <input type='hidden' name='scope' value='peringkat'>
        <div class="filter-card">
            <div class="filter-card-title">Penapis Peringkat</div>
            <div class="pic-filter-bar">
                <div>
                    <label>Sidang</label>
                    <?php renderMedalDD('session_id', 'psession', '-- Semua Sidang --', $sessionOptsP, $filter_session); ?>
                </div>
                <div>
                    <label>Peringkat</label>
                    <?php renderMedalDD('level_id', 'plevel', '-- Semua Peringkat --', $levelOptsP, $filter_level); ?>
                </div>
            </div>
        </div>
    </form>
    <?php
elseif ($scope === 'sekolah'):
    $active_siri = (int)($_SESSION['active_siri_id'] ?? 0);
    $sessions = $active_siri > 0
        ? $conn->query("SELECT * FROM sessions WHERE siri_id = $active_siri ORDER BY session_name")
        : $conn->query("SELECT se.*, si.siri_name FROM sessions se LEFT JOIN siri si ON se.siri_id = si.siri_id ORDER BY se.session_name");
    $sessionOptsS = [];
    while ($s = $sessions->fetch_assoc()) {
        $optLabel = $s['session_name'];
        if ($active_siri === 0 && !empty($s['siri_name'])) $optLabel .= " — " . $s['siri_name'];
        $sessionOptsS[] = ['value' => $s['session_id'], 'label' => $optLabel];
    }
    ?>
    <form method='GET'>
        <input type='hidden' name='scope' value='sekolah'>
        <div class="filter-card">
            <div class="filter-card-title">Penapis Sekolah</div>
            <div class="pic-filter-bar">
                <div>
                    <label>Sidang</label>
                    <?php renderMedalDD('session_id', 'ssession', '-- Semua Sidang --', $sessionOptsS, $filter_session); ?>
                </div>
                <div></div>
            </div>
        </div>
    </form>
    <?php
endif;
?>

<form method='POST'>

    <input type='hidden' name='scope' value='<?= htmlspecialchars($scope) ?>'>
    <input type='hidden' name='csrf_token' value='<?= htmlspecialchars($csrf) ?>'>



    <?php if ($scope === 'overall'): ?>

    <!-- ╔══════════════════════════════════════╗ -->

    <!-- ║         OVERALL — single card         ║ -->

    <!-- ╚══════════════════════════════════════╝ -->

    <?php

        $globalRow = $conn->query("SELECT * FROM medal_quotas WHERE level_id = 0")->fetch_assoc();

        $gG = $globalRow['gold_quota']   ?? 0;

        $gS = $globalRow['silver_quota'] ?? 0;

        $gB = $globalRow['bronze_quota'] ?? 0;

    ?>

    <div class='overall-card'>

        <div class='label-block'>

            <strong>🌐 Kuota Global</strong>

            <span>Digunakan sebagai nilai lalai jika tiada tetapan khusus.</span>

        </div>

        <div class='overall-medal-row'>

            <div class='medal-cell'>

                <label>EMAS 🥇</label>

                <input type='number' min='0' name='quotas[0][gold]'   value='<?= $gG ?>' class='sleek-num-input'>

            </div>

            <div class='medal-cell'>

                <label>PERAK 🥈</label>

                <input type='number' min='0' name='quotas[0][silver]' value='<?= $gS ?>' class='sleek-num-input'>

            </div>

            <div class='medal-cell'>

                <label>GANGSA 🥉</label>

                <input type='number' min='0' name='quotas[0][bronze]' value='<?= $gB ?>' class='sleek-num-input'>

            </div>

        </div>

    </div>



    <?php elseif ($scope === 'sidang'): ?>

    <!-- ╔══════════════════════════════════════╗ -->

    <!-- ║         SIDANG — per-session           ║ -->

    <!-- ╚══════════════════════════════════════╝ -->

    <div class='pm-card' style='padding:0; border-color: var(--c-border-strong);'>

        <div class='pm-table-wrap table-scroll-wrapper' style='border:none;'>

            <table class='pm-table medal-table' id='medalTable'>

                <thead>

                    <tr>

                        <th style='width:60px; text-align:center;'>No</th>

                        <th>Sidang</th>

                        <th style='width:130px; text-align:center;'>Emas 🥇</th>

                        <th style='width:130px; text-align:center;'>Perak 🥈</th>

                        <th style='width:130px; text-align:center;'>Gangsa 🥉</th>

                    </tr>

                </thead>

                <tbody id='medalTableBody'>

                    <?php

                    // Ensure table exists (graceful fallback) — gated behind a
                    // session flag so this DDL check only runs once per
                    // session instead of every time this tab is visited.
                    if (empty($_SESSION['pm_medal_session_table_checked'])) {
                        $conn->query("CREATE TABLE IF NOT EXISTS medal_quotas_session (
                            session_id INT PRIMARY KEY,
                            gold_quota INT DEFAULT 0,
                            silver_quota INT DEFAULT 0,
                            bronze_quota INT DEFAULT 0
                        )");
                        $_SESSION['pm_medal_session_table_checked'] = true;
                    }



                    // Scope to "Siri Aktif".
                    $active_siri = (int)($_SESSION['active_siri_id'] ?? 0);
                    $siriWhere = $active_siri > 0 ? "WHERE s.siri_id = $active_siri" : "";

                    $sql = "SELECT s.session_id, s.session_name, si.siri_name,

                                   COALESCE(mqs.gold_quota,   0) AS gold,

                                   COALESCE(mqs.silver_quota, 0) AS silver,

                                   COALESCE(mqs.bronze_quota, 0) AS bronze

                            FROM sessions s

                            LEFT JOIN medal_quotas_session mqs ON s.session_id = mqs.session_id

                            LEFT JOIN siri si ON s.siri_id = si.siri_id

                            $siriWhere

                            ORDER BY s.session_name";

                    $result = $conn->query($sql);

                    $no = 1;

                    while ($row = $result->fetch_assoc()):

                        $sid = $row['session_id'];
                        $sessionLabel = htmlspecialchars($row['session_name']);
                        if ($active_siri === 0) {
                            $siriLbl = !empty($row['siri_name']) ? htmlspecialchars($row['siri_name']) : 'Tiada Siri';
                            $sessionLabel .= " <span class='siri-inline-tag'>$siriLbl</span>";
                        }

                    ?>

                    <tr class='medal-row'>

                        <td style='text-align:center; color: var(--c-text-faint);'><?= $no++ ?></td>

                        <td style='font-weight:600; color: var(--c-white);'><?= $sessionLabel ?></td>

                        <td style='text-align:center;'><input type='number' min='0' name='quotas[<?= $sid ?>][gold]'   value='<?= $row['gold'] ?>'   class='sleek-num-input'></td>

                        <td style='text-align:center;'><input type='number' min='0' name='quotas[<?= $sid ?>][silver]' value='<?= $row['silver'] ?>' class='sleek-num-input'></td>

                        <td style='text-align:center;'><input type='number' min='0' name='quotas[<?= $sid ?>][bronze]' value='<?= $row['bronze'] ?>' class='sleek-num-input'></td>

                    </tr>

                    <?php endwhile; ?>

                </tbody>

            </table>

        </div>

    </div>



    <?php elseif ($scope === 'peringkat'): ?>

    <!-- ╔══════════════════════════════════════╗ -->

    <!-- ║    PERINGKAT — original behaviour      ║ -->

    <!-- ╚══════════════════════════════════════╝ -->

    <!-- Filters now render ABOVE the <form method='POST'> (see near the top
         of the file) — a <form method='GET'> here would illegally nest
         inside the POST form and break real submission of every quota input
         that follows it. -->

    <div class='pm-card' style='padding:0; border-color: var(--c-border-strong);'>

        <div class='pm-table-wrap table-scroll-wrapper' style='border:none;'>

            <table class='pm-table medal-table' id='medalTable'>

                <thead>

                    <tr>

                        <th style='width:60px; text-align:center;'>No</th>

                        <th>Sidang</th>

                        <th>Peringkat</th>

                        <th style='width:120px; text-align:center;'>Emas 🥇</th>

                        <th style='width:120px; text-align:center;'>Perak 🥈</th>

                        <th style='width:120px; text-align:center;'>Gangsa 🥉</th>

                    </tr>

                </thead>

                <tbody id='medalTableBody'>

                    <?php

                    $whereArr = ["l.level_id != 0"]; // exclude global sentinel

                    if ($filter_session !== '') $whereArr[] = "s.session_id = " . (int)$filter_session;

                    if ($filter_level   !== '') $whereArr[] = "l.level_id = "   . (int)$filter_level;

                    if ($active_siri    > 0)    $whereArr[] = "s.siri_id = " . $active_siri;

                    $whereSql = implode(" AND ", $whereArr);



                    $sql = "SELECT l.level_id, l.level_name, s.session_name,

                                   COALESCE(mq.gold_quota,   0) AS gold,

                                   COALESCE(mq.silver_quota, 0) AS silver,

                                   COALESCE(mq.bronze_quota, 0) AS bronze

                            FROM levels l

                            JOIN sessions s ON l.session_id = s.session_id

                            LEFT JOIN medal_quotas mq ON l.level_id = mq.level_id

                            WHERE $whereSql

                            ORDER BY s.session_name, l.level_name";



                    $result = $conn->query($sql);

                    $no = 1;

                    if ($result->num_rows > 0) {

                        while ($row = $result->fetch_assoc()):

                            $lid = $row['level_id'];

                    ?>

                    <tr class='medal-row'>

                        <td style='text-align:center; color: var(--c-text-faint);'><?= $no++ ?></td>

                        <td style='color: var(--c-text-muted);'><?= htmlspecialchars($row['session_name']) ?></td>

                        <td style='font-weight:600; color: var(--c-white);'><?= htmlspecialchars($row['level_name']) ?></td>

                        <td style='text-align:center;'><input type='number' min='0' name='quotas[<?= $lid ?>][gold]'   value='<?= $row['gold'] ?>'   class='sleek-num-input'></td>

                        <td style='text-align:center;'><input type='number' min='0' name='quotas[<?= $lid ?>][silver]' value='<?= $row['silver'] ?>' class='sleek-num-input'></td>

                        <td style='text-align:center;'><input type='number' min='0' name='quotas[<?= $lid ?>][bronze]' value='<?= $row['bronze'] ?>' class='sleek-num-input'></td>

                    </tr>

                    <?php

                        endwhile;

                    } else {

                        echo "<tr><td colspan='6' style='text-align:center;padding:30px;color:var(--c-text-faint);'>Tiada peringkat dijumpai untuk tapisan ini.</td></tr>";

                    }

                    ?>

                </tbody>

            </table>

        </div>

    </div>



    <?php elseif ($scope === 'sekolah'): ?>

    <!-- ╔══════════════════════════════════════╗ -->

    <!-- ║         SEKOLAH — per-school           ║ -->

    <!-- ╚══════════════════════════════════════╝ -->

    <!-- Filters now render ABOVE the <form method='POST'> (see near the top
         of the file) — a <form method='GET'> here would illegally nest
         inside the POST form and break real submission of every quota input
         that follows it. -->

    <div class='pm-card' style='padding:0; border-color: var(--c-border-strong);'>

        <div class='pm-table-wrap table-scroll-wrapper' style='border:none;'>

            <table class='pm-table medal-table' id='medalTable'>

                <thead>

                    <tr>

                        <th style='width:60px; text-align:center;'>No</th>

                        <th>Sekolah</th>

                        <th style='width:130px; text-align:center;'>Emas 🥇</th>

                        <th style='width:130px; text-align:center;'>Perak 🥈</th>

                        <th style='width:130px; text-align:center;'>Gangsa 🥉</th>

                    </tr>

                </thead>

                <tbody id='medalTableBody'>

                    <?php

                    // Ensure table exists — gated behind a session flag so
                    // this DDL check only runs once per session instead of
                    // every time this tab is visited.
                    if (empty($_SESSION['pm_medal_school_table_checked'])) {
                        $conn->query("CREATE TABLE IF NOT EXISTS medal_quotas_school (
                            school_id INT PRIMARY KEY,
                            gold_quota INT DEFAULT 0,
                            silver_quota INT DEFAULT 0,
                            bronze_quota INT DEFAULT 0
                        )");
                        $_SESSION['pm_medal_school_table_checked'] = true;
                    }



                    // Scope to "Siri Aktif" via siri_schools (schools aren't
                    // siri-specific themselves, but siri_schools records which
                    // ones participate in each siri — see pic_siri.php).
                    $schoolWhere = $active_siri > 0
                        ? "WHERE sc.school_id IN (SELECT school_id FROM siri_schools WHERE siri_id = $active_siri)"
                        : "";

                    $sql = "SELECT sc.school_id, sc.school_name,

                                   COALESCE(msc.gold_quota,   0) AS gold,

                                   COALESCE(msc.silver_quota, 0) AS silver,

                                   COALESCE(msc.bronze_quota, 0) AS bronze

                            FROM schools sc

                            LEFT JOIN medal_quotas_school msc ON sc.school_id = msc.school_id

                            $schoolWhere

                            ORDER BY sc.school_name";



                    $result = $conn->query($sql);

                    $no = 1;

                    if ($result && $result->num_rows > 0) {

                        while ($row = $result->fetch_assoc()):

                            $scid = $row['school_id'];

                    ?>

                    <tr class='medal-row'>

                        <td style='text-align:center; color: var(--c-text-faint);'><?= $no++ ?></td>

                        <td style='font-weight:600; color: var(--c-white);'><?= htmlspecialchars($row['school_name']) ?></td>

                        <td style='text-align:center;'><input type='number' min='0' name='quotas[<?= $scid ?>][gold]'   value='<?= $row['gold'] ?>'   class='sleek-num-input'></td>

                        <td style='text-align:center;'><input type='number' min='0' name='quotas[<?= $scid ?>][silver]' value='<?= $row['silver'] ?>' class='sleek-num-input'></td>

                        <td style='text-align:center;'><input type='number' min='0' name='quotas[<?= $scid ?>][bronze]' value='<?= $row['bronze'] ?>' class='sleek-num-input'></td>

                    </tr>

                    <?php

                        endwhile;

                    } else {

                        echo "<tr><td colspan='5' style='text-align:center;padding:30px;color:var(--c-text-faint);'>Tiada sekolah dijumpai. Pastikan jadual <code>schools</code> wujud dan mempunyai rekod.</td></tr>";

                    }

                    ?>

                </tbody>

            </table>

        </div>

    </div>

    <?php endif; ?>



    <?php if ($scope !== 'overall'): ?>
    <div class="vm-pagination" id="paginationControls">
        <div class="vm-page-info" id="pageInfo">Memaparkan 0 rekod</div>
        <div class="vm-page-btns" id="paginationButtons">
            </div>
    </div>
    <?php endif; ?>

    <div style='text-align:right; margin-top:16px;'>
        <button type='submit' class='pm-btn pm-btn-primary'
            style='padding: 12px 28px; font-size: 1rem; letter-spacing: 0.05em; text-transform: uppercase;'>
            💾 Simpan Tetapan Pingat
        </button>
    </div>
</form>



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

    function ddSelect(name, value, label) {
        document.getElementById('f_' + name).value = value;
        document.getElementById('ddWrap_' + name).closest('form').submit();
    }

    document.addEventListener('click', e => {
        if (!e.target.closest('.dd-wrap')) {
            document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
            document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
        }
    });

    const pmMedalTbody = document.getElementById('medalTableBody');
    const pmMedalRows  = pmMedalTbody ? Array.from(pmMedalTbody.getElementsByClassName('medal-row')) : [];
    let pmCurrentPage  = 1;
    const pmRowsPerPage = 20;

    function updateMedalPagination() {
        const ctrl = document.getElementById('paginationControls');
        const btnContainer = document.getElementById('paginationButtons');
        const infoText = document.getElementById('pageInfo');

        if (!ctrl || !pmMedalTbody || pmMedalRows.length === 0) {
            if (ctrl) ctrl.style.display = 'none';
            return;
        }

        const totalRows = pmMedalRows.length;

        const rpp        = pmRowsPerPage;
        const totalPages = Math.ceil(totalRows / rpp) || 1;
        
        if (pmCurrentPage > totalPages) pmCurrentPage = totalPages;
        if (pmCurrentPage < 1)          pmCurrentPage = 1;

        const start = (pmCurrentPage - 1) * rpp;
        const end   = start + rpp;

        // Hide/Show Rows
        pmMedalRows.forEach((r, i) => { 
            r.style.display = (i >= start && i < end) ? '' : 'none'; 
        });

        // Update Info Text
        const endDisplay = Math.min(end, totalRows);
        const startDisplay = totalRows === 0 ? 0 : start + 1;
        infoText.innerHTML = `Memaparkan <b>${startDisplay} - ${endDisplay}</b> daripada <b>${totalRows}</b> rekod`;

        pmRenderPagination(btnContainer, pmCurrentPage, totalPages, goToMedalPage);
    }

    function goToMedalPage(page) {
        pmCurrentPage = page;
        updateMedalPagination();
        
        // Scroll to the top of the TABLE container only, avoiding full page scroll
        const wrapper = document.querySelector('.table-scroll-wrapper');
        if (wrapper) wrapper.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // ── Fit the medal table + pagination into the viewport, no page scroll ──
    function fitMedalTableHeight() {
        const scrollEl = document.querySelector('.table-scroll-wrapper');
        const pagination = document.getElementById('paginationControls');
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
    window.addEventListener('resize', fitMedalTableHeight);

    // Initialize on page load
    updateMedalPagination();
    fitMedalTableHeight();
</script>

</main>

</body>

</html>