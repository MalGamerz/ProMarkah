<?php
ini_set("display_errors", 0);
error_reporting(E_ALL);
ini_set("log_errors", 1);

session_start();
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
$stmt = $conn->prepare("SELECT name FROM judges WHERE id = ?");
$stmt->bind_param("i", $judge_id);
$stmt->execute();
$judge_name = $stmt->get_result()->fetch_assoc()["name"] ?? "Judge";
$stmt->close();

// ── INTERNAL AJAX ENDPOINTS FOR FORM DROPDOWNS ──
if (isset($_GET["ajax_levels"])) {
    $sid = (int) $_GET["session_id"];
    $stmt = $conn->prepare(
        "SELECT * FROM levels WHERE session_id = ? ORDER BY level_name ASC",
    );
    if ($stmt) {
        $stmt->bind_param("i", $sid);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            echo "<option value='{$r["level_id"]}'>" .
                htmlspecialchars($r["level_name"]) .
                "</option>";
        }
        $stmt->close();
    }
    exit();
}

if (isset($_GET["ajax_groups"])) {
    $lid = (int) $_GET["level_id"];
    $stmt = $conn->prepare(
        "SELECT group_id, group_name, judge_id FROM `groups` WHERE level_id = ? ORDER BY group_name ASC"
    );
    if ($stmt) {
        $stmt->bind_param("i", $lid);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            $unassigned = empty($r["judge_id"]) ? "1" : "0";
            $ownedByMe  = (!empty($r["judge_id"]) && (int)$r["judge_id"] === $judge_id) ? "1" : "0";
            $label = htmlspecialchars($r["group_name"]);
            if ($unassigned === "1") $label .= " ⚠ (Belum Ditetapkan)";
            echo "<option value='{$r["group_id"]}' data-unassigned='{$unassigned}' data-own='{$ownedByMe}'>{$label}</option>";
        }
        $stmt->close();
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
$_SESSION["judge_name"] = $judge_name;

// ── JUDGE STATS FOR IDENTITY CARD ──
$stat_total = 0;
$stat_done = 0;
$stat_pending = 0;
$sqStats = $conn->prepare("
    SELECT
        COUNT(DISTINCT g.group_id) AS total_groups,
        SUM(CASE WHEN EXISTS (
            SELECT 1 FROM scores sc2 WHERE sc2.group_id = g.group_id LIMIT 1
        ) THEN 1 ELSE 0 END) AS done_groups
    FROM `groups` g
    WHERE g.judge_id = ?
");
if ($sqStats) {
    $sqStats->bind_param("i", $judge_id);
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
    
    @media (max-width: 860px) {
        .top-dashboard-row { grid-template-columns: 1fr; }
    }

    .identity-col, .summary-col {
        display: flex;
        flex-direction: column;
    }
    
    .identity-col .judge-card,
    .summary-col .judge-card {
        margin-bottom: 0;
        flex-grow: 1;
        height: 100%;
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

    /* ── Accordion summary ── */
    .summary-search-wrap { margin-bottom: 12px; }
    .summary-search-wrap input {
        width: 100%;
        background: var(--c-surface-2);
        border: 1px solid var(--c-border);
        border-radius: 4px;
        padding: 8px 12px;
        color: var(--c-text);
        font-size: 0.82rem;
        outline: none;
        transition: border-color 0.15s;
        box-sizing: border-box;
    }
    .summary-search-wrap input:focus {
        border-color: var(--c-red);
    }
    .summary-search-wrap input::placeholder {
        color: var(--c-text-muted);
    }

    .pm-accordion-container {
        min-height: 60px;
        max-height: 360px;
        overflow-y: auto;
        overflow-x: hidden;
        scrollbar-width: thin;
        scrollbar-color: var(--c-border-strong) transparent;
    }
    .pm-accordion-container::-webkit-scrollbar { width: 3px; }
    .pm-accordion-container::-webkit-scrollbar-thumb {
        background: var(--c-border-strong);
        border-radius: 3px;
    }

    .pm-accordion-block {
        border: 1px solid var(--c-border);
        border-radius: 4px;
        margin-bottom: 6px;
        overflow: visible;
        background: var(--c-surface-1);
    }
    .pm-accordion-block .pm-table-wrap {
        min-height: unset !important;
        padding-bottom: 0 !important;
    }

    .pm-accordion-header {
        position: sticky;
        top: 0;
        z-index: 20;
        background: var(--c-surface-2);
        padding: 9px 14px;
        display: flex;
        align-items: center;
        gap: 10px;
        cursor: pointer;
        font-size: 0.82rem;
        font-weight: 600;
        color: var(--c-text);
        border-bottom: 1px solid transparent;
        border-radius: 4px;
        transition: background 0.15s;
        box-shadow: none;
    }
    .pm-accordion-header:hover { background: var(--c-surface-3); }
    .pm-accordion-header.active {
        background: var(--c-surface-2);
        border-bottom: 1px solid var(--c-red);
        border-radius: 4px 4px 0 0;
        color: var(--c-red);
    }

    .pm-accordion-arrow {
        font-size: 0.6rem;
        color: var(--c-text-muted);
        transition: transform 0.2s ease;
    }

    .pm-accordion-content {
        display: none;
        padding: 10px;
        border-radius: 0 0 4px 4px;
    }

    /* ── Base table ── */
    .pm-table-wrap {
        background: var(--c-surface-1);
        border: 1px solid var(--c-border);
        border-radius: 4px;
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        min-height: 220px;
        padding-bottom: 4px;
    }
    .student-list-wrap {
        min-height: unset !important;
        padding-bottom: 0 !important;
    }
    .pm-table-wrap::-webkit-scrollbar { height: 4px; }
    .pm-table-wrap::-webkit-scrollbar-thumb {
        background: var(--c-border-strong);
        border-radius: 2px;
    }

    .pm-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 500px;
    }
    .pm-table th {
        background: var(--c-surface-2);
        color: var(--c-text-muted);
        border-bottom: 1px solid var(--c-border);
        padding: 8px 12px;
        text-align: left;
        white-space: nowrap;
        font-size: 0.65rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }
    .pm-table td {
        color: var(--c-text);
        border-bottom: 1px solid var(--c-border);
        padding: 9px 12px;
        font-size: 0.82rem;
    }
    .pm-table tr:hover td { background: var(--c-surface-2); }

    /* ── Sticky selection form ── */
    .sticky-form-card {
        position: sticky;
        top: 0;
        z-index: 50;
        background: var(--c-surface-1) !important;
        border-bottom: 1px solid var(--c-border) !important;
        margin-bottom: 16px;
        border-radius: 0;
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

    /* ── Marking table ── */
    .marking-table { min-width: 900px; }
    .marking-table th {
        text-align: center !important;
        vertical-align: middle !important;
        padding: 7px 6px !important;
    }

    .sticky-col {
        position: sticky !important;
        left: 0;
        z-index: 10 !important;
        background: var(--c-surface-1) !important;
        color: var(--c-text) !important;
        border-right: 1px solid var(--c-border-strong) !important;
        box-shadow: 2px 0 8px rgba(0,0,0,0.06);
        min-width: 160px;
        width: 200px;
        white-space: normal;
    }
    th.sticky-col {
        background: var(--c-surface-2) !important;
        z-index: 12 !important;
    }
    .marking-table tr:nth-child(even) td.sticky-col {
        background: var(--c-surface-2) !important;
    }

    /* ── Mark cell & toggle ── */
    .mark-cell {
        min-width: 120px;
        vertical-align: top !important;
        padding: 10px 6px !important;
    }

    .pm-nilai-wrap {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        margin-bottom: 10px;
        cursor: pointer;
        width: max-content;
        margin-left: auto;
        margin-right: auto;
    }
    .pm-nilai-checkbox { display: none; }
    .pm-toggle-switch {
        position: relative;
        width: 32px;
        height: 18px;
        background: var(--c-surface-3);
        border-radius: 18px;
        border: 1px solid var(--c-border-strong);
        transition: 0.2s;
    }
    .pm-toggle-switch::after {
        content: '';
        position: absolute;
        top: 1px;
        left: 1px;
        width: 14px;
        height: 14px;
        background: var(--c-text-muted);
        border-radius: 50%;
        transition: 0.2s;
    }
    .pm-nilai-checkbox:checked + .pm-toggle-switch {
        background: rgba(16,185,129,0.15);
        border-color: #10B981;
    }
    .pm-nilai-checkbox:checked + .pm-toggle-switch::after {
        transform: translateX(14px);
        background: #10B981;
    }
    .pm-toggle-label {
        font-size: 0.65rem;
        font-weight: 700;
        color: var(--c-text-muted);
        text-transform: uppercase;
        letter-spacing: 0.05em;
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
        gap: 3px;
        max-width: 104px;
        margin: 0 auto;
    }
    .pm-keypad.locked { pointer-events: none; opacity: 0.6; }

    .pm-key-btn {
        background: var(--c-surface-2);
        border: 1px solid var(--c-border);
        color: var(--c-text);
        padding: 5px 0;
        min-height: 30px;
        border-radius: 3px;
        font-family: 'DM Mono', monospace;
        font-size: 0.9rem;
        font-weight: 500;
        cursor: pointer;
        transition: background 0.1s, border-color 0.1s;
        touch-action: manipulation;
    }
    .pm-key-btn:hover:not(:disabled) {
        background: var(--c-surface-3);
        border-color: var(--c-border-strong);
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
        .sticky-col {
            min-width: 80px !important;
            width: 90px !important;
            font-size: 0.72rem !important;
            padding: 6px 5px !important;
            white-space: normal !important;
            word-break: break-word !important;
        }
        th.sticky-col { min-width: 80px !important; width: 90px !important; }
        .mark-cell    { min-width: 100px !important; padding: 6px 3px !important; }
        .pm-keypad    { max-width: 92px !important; }
        .pm-key-btn   { font-size: 0.82rem !important; min-height: 26px !important; padding: 3px 0 !important; }
        .mark-actions-row { flex-wrap: wrap !important; gap: 6px !important; }
        .mark-action-btn  { padding: 6px 12px !important; font-size: 0.78rem !important; }
    }
</style>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<h2 class="pm-page-heading">Sistem Pemarkahan</h2>

<?php if (isset($_GET["status"]) && $_GET["status"] === "success"): ?>
    <div id="toastNotification" class="pm-alert pm-toast-success" style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-radius: 8px;">
        <span>✅ <strong>Berjaya!</strong> Markah pemarkahan telah selamat disimpan ke dalam pangkalan data.</span>
        <button onclick="this.parentElement.style.display='none'" style="background:transparent; border:none; color:inherit; font-size:1.5rem; cursor:pointer; padding:0; line-height:1;">&times;</button>
    </div>
    <script>
        setTimeout(() => {
            const toast = document.getElementById('toastNotification');
            if(toast) toast.style.display = 'none';
        }, 5000);
        if (window.history.replaceState) {
            const url = new URL(window.location.href);
            url.searchParams.delete('status');
            window.history.replaceState({path: url.href}, '', url.href);
        }
    </script>
<?php endif; ?>

<?php if (isset($_GET["msg"]) && $_GET["msg"] === "access_denied"): ?>
    <div id="toastError" class="pm-alert pm-toast-danger" style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-radius: 8px;">
        <span>🔒 <strong>Akses Ditolak!</strong> Kumpulan ini telah ditetapkan kepada juri lain. Anda tidak dibenarkan mengubah markahnya.</span>
        <button onclick="this.parentElement.style.display='none'" style="background:transparent; border:none; color:inherit; font-size:1.5rem; cursor:pointer; padding:0; line-height:1;">&times;</button>
    </div>
<?php endif; ?>

<?php if (isset($_GET["msg"]) && $_GET["msg"] === "edit_locked"): ?>
    <div id="toastError" class="pm-alert pm-toast-danger" style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-radius: 8px;">
        <span>🔒 <strong>Dikunci Sepenuhnya!</strong> Hak mengedit telah digunakan. Markah tidak boleh diubah lagi.</span>
        <button onclick="this.parentElement.style.display='none'" style="background:transparent; border:none; color:inherit; font-size:1.5rem; cursor:pointer; padding:0; line-height:1;">&times;</button>
    </div>
<?php endif; ?>

<?php if (isset($_GET["msg"]) && $_GET["msg"] === "empty_submission"): ?>
    <div id="toastError" class="pm-alert pm-toast-danger" style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-radius: 8px;">
        <span>⚠️ <strong>Ralat!</strong> Tiada markah dimasukkan. Sila masukkan sekurang-kurangnya satu markah sebelum menyimpan.</span>
        <button onclick="this.parentElement.style.display='none'" style="background:transparent; border:none; color:inherit; font-size:1.5rem; cursor:pointer; padding:0; line-height:1;">&times;</button>
    </div>
    <script>
        if (window.history.replaceState) {
            const url = new URL(window.location.href);
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
                <span>Status</span> <b
                    style="color: <?= $marking_active
                        ? "#4ade80"
                        : "#f87171" ?>;"><?= $marking_active
    ? "🟢 Sedia"
    : "🔴 Belum" ?></b>
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
            <h3 class="pm-card-title">Kemajuan Pemarkahan Mengikut Sidang</h3>

            <div class="summary-search-wrap">
                <input type="text" id="summarySearchInput"
                    placeholder="Cari peringkat / ujian / kumpulan / cawangan / juri..." autocomplete="off">
            </div>

            <div class="pm-accordion-container" id="summaryAccordionContainer">
                <?php
                // Fetch and group data by Session
                $sqSummary = $conn->prepare("
                    SELECT s.session_name, l.level_name, g.group_name, j.name AS judge_name,
                           GROUP_CONCAT(
                               DISTINCT CONCAT(
                                   '• ', REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(t.test_name,'&','&amp;'),'<','&lt;'),'>','&gt;'),'\"','&quot;'),'\'','&#39;'),
                                   CASE WHEN EXISTS (
                                       SELECT 1 FROM scores sc WHERE sc.group_id = g.group_id AND sc.test_id = t.test_id
                                   ) THEN ' <span style=\"color:#10B981;font-weight:bold;\">(✅ Selesai)</span>'
                                   ELSE ' <span style=\"color:var(--c-text-muted);\">(🔓 Belum)</span>' END
                               ) SEPARATOR '<br>'
                           ) as status_list,
                           (SELECT GROUP_CONCAT(DISTINCT sc2.school_name SEPARATOR ', ')
                            FROM group_students gs2
                            JOIN students st2 ON gs2.student_id = st2.student_id
                            JOIN schools sc2 ON st2.school_id = sc2.school_id
                            WHERE gs2.group_id = g.group_id) as school_names
                    FROM sessions s
                    JOIN levels l ON s.session_id = l.session_id
                    JOIN `groups` g ON l.level_id = g.level_id
                    LEFT JOIN judges j ON g.judge_id = j.id
                    JOIN tests t ON l.level_id = t.level_id
                    JOIN criteria c ON t.test_id = c.test_id
                    WHERE g.judge_id = ?
                    GROUP BY s.session_id, s.session_name, l.level_id, g.group_id
                    ORDER BY s.session_name, l.level_name, g.group_name
                ");
                $sqSummary->bind_param("i", $judge_id);
                $sqSummary->execute();
                $summary = $sqSummary->get_result();
                $sqSummary->close();

                $groupedData = [];
                while ($row = $summary->fetch_assoc()) {
                    $groupedData[$row["session_name"]][] = $row;
                }

                if (empty($groupedData)) {
                    echo "<div style='color:#aaa; text-align:center; padding:20px;'>Tiada rekod ujian ditemui.</div>";
                } else {
                    foreach ($groupedData as $sessionName => $rows) {
                        $sidHash = md5($sessionName); // Unique ID for toggle

                        echo "<div class='pm-accordion-block' data-session='" .
                            strtolower(htmlspecialchars($sessionName)) .
                            "'>";

                        // Accordion Header
                        echo "<div class='pm-accordion-header' onclick=\"toggleSummaryAccordion('{$sidHash}')\" id='header_{$sidHash}'>";
                        echo "<span class='pm-accordion-arrow' id='arrow_{$sidHash}'>▶</span>";
                        echo "<span>" .
                            htmlspecialchars($sessionName) .
                            "</span>";
                        echo "</div>";

                        // Accordion Content (Table)
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
                            echo "<td style='font-weight: 500;'>" . htmlspecialchars($r["level_name"]) . "</td>";
                            echo "<td style='line-height: 1.6;'>{$r["status_list"]}</td>";
                            echo "<td>" . htmlspecialchars($r["group_name"]) . "</td>";
                            echo "<td style='color:var(--c-text-muted); font-size:var(--text-sm); max-width:180px; white-space:normal; line-height:1.2;'>{$cawanganText}</td>";
                            echo "<td style='font-weight: 600; color: var(--c-red);'>{$juriText}</td>";
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

        <div style="display:flex; flex-wrap:wrap; gap:16px; align-items:flex-end;">

            <div style="flex:1; min-width:200px;">
                <label
                    style="font-size: var(--text-xs); font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--c-text-muted); display: block; margin-bottom: 6px;">Sidang:</label>
                <select name="session_id" id="sessionSelect" required class="pm-input" style="width:100%;">
                    <option value="">-- Pilih Sidang --</option>
                    <?php
                    $sessions = $conn->query(
                        "SELECT * FROM sessions ORDER BY session_name ASC",
                    );
                    while ($s = $sessions->fetch_assoc()) {
                        $sel =
                            $current_session == $s["session_id"]
                                ? "selected"
                                : "";
                        echo "<option value='{$s["session_id"]}' $sel>{$s["session_name"]}</option>";
                    }
                    ?>
                </select>
            </div>

            <div style="flex:1; min-width:200px;">
                <label
                    style="font-size: var(--text-xs); font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--c-text-muted); display: block; margin-bottom: 6px;">Peringkat:</label>
                <select name="level_id" id="levelSelect" required class="pm-input" style="width:100%;">
                    <option value="">-- Sila Pilih Sidang Terlebih Dahulu --</option>
                    <?php if ($current_session) {
                        $levels = $conn->query(
                            "SELECT * FROM levels WHERE session_id = " .
                                (int) $current_session .
                                " ORDER BY level_name ASC",
                        );
                        while ($l = $levels->fetch_assoc()) {
                            $sel =
                                $current_level == $l["level_id"]
                                    ? "selected"
                                    : "";
                            echo "<option value='{$l["level_id"]}' $sel>{$l["level_name"]}</option>";
                        }
                    } ?>
                </select>
            </div>

            <div style="flex:2; min-width:250px;">
                <label
                    style="font-size: var(--text-xs); font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--c-text-muted); display: block; margin-bottom: 6px;">Kumpulan:</label>
                <select name="group_id" id="groupSelect" required class="pm-input" style="width:100%;">
                    <option value="">-- Sila Pilih Peringkat Dahulu --</option>
                    <?php if ($current_level) {
                        // Fetch all groups purely by level, no specific judge text required
                        $groups = $conn->query(
                            "SELECT * FROM `groups` WHERE level_id = " .
                                (int) $current_level .
                                " ORDER BY group_name ASC",
                        );
                        while ($g = $groups->fetch_assoc()) {
                            $sel =
                                $current_group == $g["group_id"]
                                    ? "selected"
                                    : "";
                            echo "<option value='{$g["group_id"]}' $sel>{$g["group_name"]}</option>";
                        }
                    } ?>
                </select>
            </div>

            <div>
                <button type="submit" class="pm-btn pm-btn-primary form-btn-submit">▶ Mula Menanda</button>
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

        // Initialize Select2 for Grading Form
        $('#sessionSelect, #levelSelect').select2({ width: '100%' });

        $('#groupSelect').select2({
            width: '100%',
            templateResult: function(opt) {
                if (!opt.id) return opt.text;
                const el = opt.element;
                const span = document.createElement('span');
                span.textContent = opt.text;
                if (el && el.dataset.unassigned === '1') {
                    span.style.color = 'var(--c-text-muted)';
                    span.style.fontStyle = 'italic';
                }
                return span;
            }
        });

        // Cascading Dropdowns via AJAX
        $('#sessionSelect').on('change', function () {
            const sessionId = $(this).val();
            const levelSelect = $('#levelSelect');
            const groupSelect = $('#groupSelect');

            levelSelect.empty().append('<option value="">Memuatkan...</option>').trigger('change.select2');
            groupSelect.empty().append('<option value="">-- Sila Pilih Peringkat Dahulu --</option>').trigger('change.select2');

            if (!sessionId) {
                levelSelect.empty().append('<option value="">-- Pilih Sidang --</option>').trigger('change.select2');
                return;
            }
            $.get('judge.php?ajax_levels=1&session_id=' + encodeURIComponent(sessionId), function (html) {
                levelSelect.empty().append('<option value="">-- Pilih Peringkat --</option>' + html).trigger('change.select2');
            });
        });

        $('#levelSelect').on('change', function () {
            const levelId = $(this).val();
            const groupSelect = $('#groupSelect');

            groupSelect.empty().append('<option value="">Memuatkan...</option>').trigger('change.select2');

            if (!levelId) {
                groupSelect.empty().append('<option value="">-- Pilih Peringkat --</option>').trigger('change.select2');
                return;
            }
            $.get('judge.php?ajax_groups=1&level_id=' + encodeURIComponent(levelId), function (html) {
                groupSelect.empty().append('<option value="">-- Pilih Kumpulan --</option>' + html).trigger('change.select2');
            });
        });

        // Totals are initialized in the bottom script block after criteriaByTest is populated
    });

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

    $stmt = $conn->prepare("
        SELECT student_id, criteria_id, mark
        FROM scores
        WHERE group_id = ?
    ");
    if ($stmt) {
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $sres = $stmt->get_result();
        if ($sres && $sres->num_rows > 0) {
            // Lock if scores exist, UNLESS edit token is valid AND not yet consumed
            $is_locked = !($is_edit_mode_form && $group_edit_used === 0);
            while ($sr = $sres->fetch_assoc()) {
                $existing_scores[$sr["student_id"]][$sr["criteria_id"]] =
                    $sr["mark"];
            }
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
        ORDER BY st.student_name
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
        echo "<h4 style='color:var(--pm-text-muted); margin-bottom:16px; font-size:1.15rem;'>Juri: <b style='color:#ff5252;'>$judge_name</b></h4>";

        if ($is_edit_mode_form) {
            echo "<div class='pm-alert pm-alert-warn'>✏️ <strong>Mod Edit Aktif</strong> — Sila berhati-hati. Kemaskini markah hanya boleh dilakukan <b>SEKALI SAHAJA</b> sebelum dikunci sepenuhnya. Simpan semula untuk mengunci.</div>";
        }

        if (!$is_locked) {
            echo "<div style='display: flex; gap: 8px; margin-bottom: 16px; justify-content: flex-end;'>
                    <button type='button' class='pm-btn pm-btn-primary' style='font-size:0.78rem; padding: 5px 12px; border-radius: 5px; line-height:1.4;' onclick='openParameterModal()'>+ Tambah Peringkat &amp; Ujian</button>
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

        // Added proper border and radius to the table wrapper
        echo "<div class='judge-card' style='padding: 0; overflow: hidden;'><div class='pm-table-wrap' style='border: none; margin: 0; border-radius: 0;'>";
        echo "<table class='pm-table marking-table'>";

        // Header row 1: test names (Using adaptive CSS variables)
        echo "<thead><tr><th class='sticky-col' rowspan='2' style='background: var(--c-surface-2); z-index: 15;'>Pelajar</th>";
        foreach ($criteria_by_test as $testName => $criteriaList) {
            $cnt = count($criteriaList);
            $testNameSafe = htmlspecialchars($testName);
            echo "<th colspan='$cnt' style='background: var(--c-red); color: #ffffff; text-align: center; border-bottom: 1px solid rgba(0,0,0,0.1);'>$testNameSafe</th>
          <th rowspan='2' data-test='$testNameSafe' style='background: var(--c-surface-3); color: var(--c-text); text-align: center;'>Jumlah $testNameSafe</th>";
        }
        echo "<th rowspan='2' style='background: var(--c-surface-3); color: var(--c-text); text-align: center;'>Jumlah Keseluruhan</th></tr>";

        // Header row 2: criteria
        echo "<tr>";
        if (!empty($criteria_by_test)) {
            foreach ($criteria_by_test as $criteriaList) {
                foreach ($criteriaList as $cid => $cname) {
                    echo "<th>" . htmlspecialchars($cname) . "</th>";
                }
            }
        }
        echo "</tr></thead><tbody>";

        // Student rows
        foreach ($student_rows as $s) {
            $sid = $s["student_id"];
            $is_present = $s["status"] === "Present";

            if ($is_present) {
                echo "<tr data-student='$sid'>";
                echo "<td class='sticky-col'>" . htmlspecialchars($s["student_name"]) . "</td>"; // Added class here
                foreach ($criteria_by_test as $testName => $criteriaList) {
                    $testNameAttr = htmlspecialchars($testName);
                    foreach ($criteriaList as $cid => $cname) {
                        $existingVal = $existing_scores[$sid][$cid] ?? "";
                        $hasValue = $existingVal !== "";
                        $isChecked = $hasValue || !$is_locked ? "checked" : "";
                        $gridDisplay =
                            $hasValue || !$is_locked ? "block" : "none";
                        $disabledAttr = $is_locked ? "disabled" : "";

                        echo "<td class='mark-cell' style='text-align: center;'>";

                        // True Toggle Switch
                        $toggleText = $isChecked ? "Dinilai" : "Abai";
                        echo "<label class='pm-nilai-wrap'>";
                        echo "<input type='checkbox' class='pm-nilai-checkbox' data-student='$sid' data-criteria='$cid' $isChecked $disabledAttr>";
                        echo "<div class='pm-toggle-switch'></div>";
                        echo "<span class='pm-toggle-label'>$toggleText</span>";
                        echo "</label>";

                        echo "<div class='keypad-wrapper' id='keypad_{$sid}_{$cid}' style='display:$gridDisplay;'>";

                        // Hidden input to store the actual score for submission
                        echo "<input type='hidden' id='mark_{$sid}_{$cid}' name='marks[{$sid}][{$cid}]' value='$existingVal'>";

                        // Render 0-10 Keypad
                        $lockedClass = $is_locked ? "locked" : "";
                        echo "<div class='pm-keypad $lockedClass'>";
                        $keys = [1, 2, 3, 4, 5, 6, 7, 8, 9, "X", 0, 10];

                        foreach ($keys as $k) {
                            $val = $k === "X" ? "" : $k;
                            $activeCls =
                                $hasValue &&
                                (string) $existingVal === (string) $val &&
                                $k !== "X"
                                    ? "active"
                                    : "";
                            $displayK = $k === "X" ? "✖" : $k;
                            $btnClass =
                                $k === "X"
                                    ? "pm-key-btn clear-btn"
                                    : "pm-key-btn";

                            echo "<button type='button' class='$btnClass $activeCls' data-target='mark_{$sid}_{$cid}' data-val='$val' data-student='$sid' data-criteria='$cid' $disabledAttr>$displayK</button>";
                        }
                        echo "</div></div></td>";
                    }
                    $maxTest = count($criteriaList) * 10;
                    echo "<td class='test-total' data-student='$sid' data-test='$testNameAttr'
                  style='background:var(--c-surface-2);color:var(--c-text);font-weight:700;text-align:center;'>0 / $maxTest</td>";
                }
                echo "<td class='overall-total' data-student='$sid'
      style='background:var(--c-surface-1);color:var(--c-text);font-weight:700;text-align:center;'>0 / 0</td>";
                echo "</tr>";
            } else {
                $total_cols = 1;
                foreach ($criteria_by_test as $criteriaList) {
                    $total_cols += count($criteriaList) + 1;
                }
                $total_cols += 1;
                echo "<tr style='opacity:0.4;background:rgba(214,40,40,0.06);' title='Tidak Hadir'>";
                echo "<td class='sticky-col'>" . htmlspecialchars($s["student_name"]) . " <br><em style='color:var(--c-red);font-size:0.85em;'>(Tidak Hadir)</em></td>"; // Added class here
                foreach ($criteria_by_test as $testName => $criteriaList) {
                    foreach ($criteriaList as $c) {
                        echo "<td style='color:#666;text-align:center;'>—</td>";
                    }
                    echo "<td style='background:var(--c-surface-2);color:#666;text-align:center;'>—</td>";
                }
                echo "<td style='background:var(--c-surface-1);color:#666;text-align:center;'>—</td></tr>";
            }
        }
        echo "</tbody></table></div>";
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
        // Fetch levels for current session (scoped, not global)
        $modal_levels = [];
        if ($session_id) {
            $mlvl = $conn->prepare("SELECT level_id, level_name FROM levels WHERE session_id = ? ORDER BY level_name ASC");
            if ($mlvl) {
                $mlvl->bind_param("i", $session_id);
                $mlvl->execute();
                $mlvl_res = $mlvl->get_result();
                while ($ml = $mlvl_res->fetch_assoc()) {
                    $modal_levels[] = $ml;
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
            .pm-modal-field select {
                width: 100%; background: var(--c-surface-2);
                border: 1px solid var(--c-border-strong);
                border-radius: 6px; padding: 7px 10px;
                color: var(--c-text); font-size: 0.82rem;
                outline: none; transition: border-color 0.15s, box-shadow 0.15s;
                appearance: auto;
            }
            .pm-modal-field select:focus {
                border-color: var(--c-red);
                box-shadow: 0 0 0 2px var(--c-red-dim);
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
                    <div class='pm-modal-title'>Tambah Peringkat &amp; Ujian</div>
                    <button type='button' class='pm-modal-close-btn' onclick='closeModal(\"modal-parameter\")' title='Tutup'>&times;</button>
                </div>
                <div class='pm-modal-body'>
                    <div class='pm-modal-grid'>
                        <div class='pm-modal-field'>
                            <label>1. Peringkat</label>
                            <select id='paramLevelSelect'>";
        foreach ($modal_levels as $ml) {
            echo "<option value='" . (int)$ml['level_id'] . "'>" . htmlspecialchars($ml['level_name']) . "</option>";
        }
        echo "              <option value='' disabled selected style='display:none'>-- Pilih Peringkat --</option>
                            </select>
                        </div>
                        <div class='pm-modal-field'>
                            <label>2. Ujian</label>
                            <select id='paramTestSelect' disabled>
                                <option value=''>-- Pilih Peringkat Dahulu --</option>
                            </select>
                        </div>
                        <div class='pm-modal-field' style='grid-column: 1 / -1;'>
                            <label>3. Kriteria Pemarkahan</label>
                            <select id='paramCriteriaSelect' disabled>
                                <option value=''>-- Pilih Ujian Dahulu --</option>
                            </select>
                        </div>
                    </div>
                    <div class='pm-modal-hint'>
                        ℹ️ Pilihan ini <b>tidak akan disimpan</b> ke pangkalan data sehingga anda klik <b>Selesai Pemarkahan</b>. Klik Batal pada bila-bila masa untuk membuang.
                    </div>
                </div>
                <div class='pm-modal-actions'>
                    <button type='button' class='pm-btn pm-btn-ghost' onclick='closeModal(\"modal-parameter\")'>Batal</button>
                    <button type='button' class='pm-btn pm-btn-primary' id='paramSaveBtn' onclick='submitNewParameter()'>Tambah ke Senarai</button>
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

    // --- KEYPAD & TOGGLE LOGIC ---
    document.addEventListener('change', function (e) {
        // 1. Toggle "Dinilai / Abai" Switch
        if (e.target.classList.contains('pm-nilai-checkbox')) {
            const cb = e.target;
            const sid = cb.dataset.student;
            const cid = cb.dataset.criteria;
            const keypad = document.getElementById(`keypad_${sid}_${cid}`);
            const input = document.getElementById(`mark_${sid}_${cid}`);
            const label = cb.closest('.pm-nilai-wrap').querySelector('.pm-toggle-label');

            if (cb.checked) {
                label.innerHTML = 'Dinilai';
                keypad.style.display = 'block';
            } else {
                label.innerHTML = 'Abai';
                keypad.style.display = 'none';
                input.value = ''; // Clear mark
                document.querySelectorAll(`.pm-key-btn[data-target="mark_${sid}_${cid}"]`).forEach(k => k.classList.remove('active'));
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

            updateTotals(keyBtn.dataset.student);
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
            const cell = document.querySelector(`.test-total[data-student='${studentId}'][data-test='${testName}']`);
            if (cell) cell.innerHTML = `${testSum} / ${testMax}`;
            overallSum += testSum; overallMax += testMax;
        }
        const oCell = document.querySelector(`.overall-total[data-student='${studentId}']`);
        if (oCell) oCell.innerHTML = `${overallSum} / ${overallMax}`;
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
                if (cb && keypad) {
                    const label = cb.closest('.pm-nilai-wrap').querySelector('.pm-toggle-label');
                    cb.checked = toggles[key];
                    if (cb.checked) {
                        label.innerHTML = 'Dinilai'; keypad.style.display = 'block';
                    } else {
                        label.innerHTML = 'Abai'; keypad.style.display = 'none';
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

        let pendingParams = []; // [{levelId, testId, testName, criteriaId, criteriaName}]

        function openParameterModal() {
            const modal = document.getElementById('modal-parameter');
            modal.style.display = 'flex';
            setTimeout(() => modal.classList.add('show'), 10);

            const lvlSel  = document.getElementById('paramLevelSelect');
            const testSel = document.getElementById('paramTestSelect');
            const critSel = document.getElementById('paramCriteriaSelect');

            lvlSel.value = '';
            testSel.innerHTML = '<option value="">-- Pilih Peringkat Dahulu --</option>';
            testSel.disabled = true;
            critSel.innerHTML = '<option value="">-- Pilih Ujian Dahulu --</option>';
            critSel.disabled = true;

            const saveBtn = document.getElementById('paramSaveBtn');
            saveBtn.innerHTML = 'Tambah ke Senarai';
            saveBtn.disabled = false;
        }

        // Cascade: Peringkat → Ujian
        document.getElementById('paramLevelSelect')?.addEventListener('change', function() {
            const levelId = this.value;
            const testSel = document.getElementById('paramTestSelect');
            const critSel = document.getElementById('paramCriteriaSelect');

            testSel.innerHTML = '<option value="">Memuatkan...</option>';
            testSel.disabled = true;
            critSel.innerHTML = '<option value="">-- Pilih Ujian Dahulu --</option>';
            critSel.disabled = true;

            if (!levelId) {
                testSel.innerHTML = '<option value="">-- Pilih Peringkat Dahulu --</option>';
                return;
            }
            fetch('judge.php?ajax_modal_tests=1&level_id=' + encodeURIComponent(levelId))
                .then(r => r.json())
                .then(data => {
                    testSel.innerHTML = '<option value="" disabled selected>-- Pilih Ujian --</option>';
                    data.forEach(t => {
                        const opt = document.createElement('option');
                        opt.value = t.id;
                        opt.textContent = t.name;
                        testSel.appendChild(opt);
                    });
                    testSel.disabled = data.length === 0;
                    if (data.length === 0) testSel.innerHTML = '<option value="">Tiada ujian untuk peringkat ini</option>';
                });
        });

        // Cascade: Ujian → Kriteria
        document.getElementById('paramTestSelect')?.addEventListener('change', function() {
            const testId = this.value;
            const critSel = document.getElementById('paramCriteriaSelect');

            critSel.innerHTML = '<option value="">Memuatkan...</option>';
            critSel.disabled = true;

            if (!testId) {
                critSel.innerHTML = '<option value="">-- Pilih Ujian Dahulu --</option>';
                return;
            }
            fetch('judge.php?ajax_modal_criteria=1&test_id=' + encodeURIComponent(testId))
                .then(r => r.json())
                .then(data => {
                    critSel.innerHTML = '<option value="" disabled selected>-- Pilih Kriteria --</option>';
                    data.forEach(c => {
                        const opt = document.createElement('option');
                        opt.value = c.id;
                        opt.textContent = c.name;
                        critSel.appendChild(opt);
                    });
                    critSel.disabled = data.length === 0;
                    if (data.length === 0) critSel.innerHTML = '<option value="">Tiada kriteria untuk ujian ini</option>';
                });
        });

        function closeModal(id) {
            const modal = document.getElementById(id);
            modal.classList.remove('show');
            setTimeout(() => modal.style.display = 'none', 200);
        }

        function submitNewParameter() {
            const lvlSel  = document.getElementById('paramLevelSelect');
            const testSel = document.getElementById('paramTestSelect');
            const critSel = document.getElementById('paramCriteriaSelect');

            const levelId   = lvlSel.value;
            const testId    = testSel.value;
            const criteriaId = critSel.value;

            if (!levelId || !testId || !criteriaId) {
                alert("Sila pilih Peringkat, Ujian, dan Kriteria terlebih dahulu.");
                return;
            }

            const testName     = testSel.options[testSel.selectedIndex].text;
            const criteriaName = critSel.options[critSel.selectedIndex].text;

            // Guard: don't add the same combo twice
            const alreadyQueued = pendingParams.some(
                p => p.testId == testId && p.criteriaId == criteriaId
            );
            if (alreadyQueued) {
                alert("Kombinasi ujian dan kriteria ini sudah ditambah.");
                return;
            }

            // Queue it — nothing touches the DB yet
            const param = { levelId, testId, testName, criteriaId, criteriaName };
            pendingParams.push(param);

            // Inject a hidden input into markForm so save_scores.php gets it on submit
            const container = document.getElementById('pendingParamsContainer');
            const inp = document.createElement('input');
            inp.type  = 'hidden';
            inp.name  = 'pending_params[]';
            inp.value = JSON.stringify({ levelId, testId, testName, criteriaId, criteriaName });
            container.appendChild(inp);

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