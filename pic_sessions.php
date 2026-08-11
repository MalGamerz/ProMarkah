<?php
ini_set('display_errors', 0); ini_set('display_startup_errors', 0);
error_reporting(E_ALL); ini_set('log_errors', 1);

session_start();
require __DIR__ . '/auth_check.php';
include 'db.php';
$conn = getDB();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pic') {
    header("Location: login.php"); exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token'])) {
        header("Location: pic_sessions.php?msg=Ralat+token+keselamatan.+Sila+muat+semula+halaman.&status=error"); exit();
    }
    session_write_close();
    $ok = false;
    try {
    if ($_POST['action'] === 'add') {
        $name = trim($_POST['session_name']);
        if ($name) {
            $stmt = $conn->prepare("INSERT INTO sessions (session_name) VALUES (?)");
            $stmt->bind_param('s', $name); $ok = $stmt->execute(); $stmt->close();
            $msg = $ok ? 'Sidang+berjaya+ditambah.' : 'Ralat+menambah+sidang.+Sila+cuba+lagi.';
        } else {
            $msg = 'Sila+masukkan+nama+sidang.';
        }
    } elseif ($_POST['action'] === 'delete') {
        $sid = (int)$_POST['session_id'];
        $ok = true;
        // groups is deleted via a levels subquery (must run before levels
        // itself is deleted below) since groups no longer stores session_id
        // directly — it's derived from level_id.
        foreach (["DELETE FROM session_schools WHERE session_id=?","DELETE FROM `groups` WHERE level_id IN (SELECT level_id FROM levels WHERE session_id=?)","DELETE FROM levels WHERE session_id=?","DELETE FROM sessions WHERE session_id=?"] as $sql) {
            $stmt = $conn->prepare($sql); $stmt->bind_param('i', $sid); if (!$stmt->execute()) { $ok = false; } $stmt->close();
        }
        $msg = $ok ? 'Sidang+berjaya+dipadam.' : 'Ralat+memadam+sidang.+Sila+cuba+lagi.';
    } elseif ($_POST['action'] === 'save_all' && isset($_POST['sessions'])) {
        $stmt = $conn->prepare("UPDATE sessions SET session_name=? WHERE session_id=?");
        $ok = true;
        foreach ($_POST['sessions'] as $id => $s) {
            $name = trim($s['session_name']); $id = (int)$id;
            if ($name) { $stmt->bind_param('si', $name, $id); if (!$stmt->execute()) { $ok = false; } }
        }
        $stmt->close();
        $msg = $ok ? 'Perubahan+berjaya+disimpan.' : 'Ralat+menyimpan+sebahagian+perubahan.+Sila+semak+dan+cuba+lagi.';
    } elseif ($_POST['action'] === 'assign_school') {
        $sid = (int)$_POST['session_id'];
        $ok = true;
        $stmt = $conn->prepare("DELETE FROM session_schools WHERE session_id=?");
        $stmt->bind_param('i', $sid); if (!$stmt->execute()) { $ok = false; } $stmt->close();
        $schools_post = $_POST['school_ids'] ?? [];
        $stmt = $conn->prepare("INSERT INTO session_schools (session_id, school_id) VALUES (?, ?)");
        foreach ($schools_post as $sc) { $sc = (int)$sc; $stmt->bind_param('ii', $sid, $sc); if (!$stmt->execute()) { $ok = false; } }
        $stmt->close();
        $msg = $ok ? 'Cawangan+sidang+berjaya+dikemaskini.' : 'Ralat+mengemaskini+cawangan+sidang.+Sila+cuba+lagi.';
    } else {
        $msg = 'Tindakan+tidak+sah.';
    }
    } catch (Throwable $e) {
        promarkah_report('Caught', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
        $ok  = false;
        $msg = 'Ralat+pangkalan+data.+Sila+cuba+lagi.';
    }
    header("Location: pic_sessions.php?msg={$msg}&status=" . ($ok ? 'success' : 'error')); exit();
}

session_write_close();

// ── AJAX rows ─────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    $search = $_GET['search'] ?? '';

    // Scope to "Siri Aktif" — sessions carry siri_id directly.
    $active_siri = (int)($_SESSION['active_siri_id'] ?? 0);

    $whereArr = ["1=1"]; $sTypes = ''; $sVals = [];
    if ($search !== '') { $whereArr[] = "se.session_name LIKE ?"; $sTypes .= 's'; $sVals[] = '%'.$search.'%'; }
    if ($active_siri > 0) { $whereArr[] = "se.siri_id = ?"; $sTypes .= 'i'; $sVals[] = $active_siri; }
    $whereSql = implode(" AND ", $whereArr);

    $stmt = $conn->prepare("SELECT se.*, si.siri_name FROM sessions se LEFT JOIN siri si ON se.siri_id = si.siri_id WHERE $whereSql ORDER BY se.session_name ASC");
    if ($sTypes) $stmt->bind_param($sTypes, ...$sVals);
    $stmt->execute();
    $sessions_result = $stmt->get_result();
    $stmt->close();

    $rows = [];
    while ($row = $sessions_result->fetch_assoc()) $rows[] = $row;

    if (empty($rows)) {
        echo "<div style='text-align:center;padding:30px;color:var(--c-text-faint);'>Tiada sidang dijumpai.</div>";
        exit();
    }

    // Fetch assigned schools for ALL sessions in one query, grouped by
    // session_id in PHP — this used to run a separate query per session
    // inside the loop below, which got slower as the session list grew.
    $sessionIds = array_column($rows, 'session_id');
    $schoolsBySession = [];
    if (!empty($sessionIds)) {
        $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
        $typesStr = str_repeat('i', count($sessionIds));
        $stmt2 = $conn->prepare("SELECT ss.session_id, ss.school_id, s.school_name FROM session_schools ss JOIN schools s ON ss.school_id=s.school_id WHERE ss.session_id IN ($placeholders) ORDER BY s.school_name");
        $stmt2->bind_param($typesStr, ...$sessionIds);
        $stmt2->execute();
        $allAssigned = $stmt2->get_result();
        $stmt2->close();
        while ($a = $allAssigned->fetch_assoc()) {
            $schoolsBySession[$a['session_id']][] = $a;
        }
    }

    $no = 1;
    foreach ($rows as $row) {
        $sid = $row['session_id'];

        $chips = [];
        $ids = [];
        foreach (($schoolsBySession[$sid] ?? []) as $a) {
            $chips[] = "<span class='school-chip'>" . htmlspecialchars($a['school_name']) . "</span>";
            $ids[] = $a['school_id'];
        }
        $schoolHtml = $chips ? implode('', $chips) : "<span class='school-chip-empty'>Tiada cawangan ditugaskan</span>";
        $idStr = implode(',', $ids);
        $safeName = htmlspecialchars($row['session_name'], ENT_QUOTES);
        $siriLabel = $row['siri_name'] ? htmlspecialchars($row['siri_name']) : 'Tiada Siri';

        echo "<div class='session-row' data-id='$sid'>
                <div class='session-row-index'>$no</div>
                <div class='session-row-main'>
                    <div class='session-row-name-group'>
                        <input class='session-name-input' name='sessions[$sid][session_name]' value='$safeName' form='saveAllForm' data-orig='$safeName'>
                        <span class='siri-chip'>$siriLabel</span>
                    </div>
                    <div class='session-row-schools'>$schoolHtml</div>
                </div>
                <div class='session-row-actions'>
                    <button type='button' class='pm-btn pm-btn-ghost btn-sm' onclick=\"openAssignModal('$sid', '$safeName', '$idStr')\">Cawangan</button>
                    <button type='button' class='pm-btn pm-btn-danger btn-sm' onclick=\"delRow('$sid')\">Padam</button>
                </div>
              </div>";
        $no++;
    }
    exit();
}

$pm_page = 'sessions';
include 'layout.php';
?>

<style>
    /* ── Filter card ── */
    .filter-card {
        background: var(--c-surface-1);
        border: 1px solid var(--c-border-strong);
        border-radius: 10px;
        padding: 16px 20px;
        margin-bottom: 18px;
    }
    .filter-card-title {
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: var(--c-text-faint);
        margin-bottom: 12px;
    }
    .pic-filter-bar {
        display: block;
        width: 100%;
    }
    .pic-filter-bar label {
        display: block;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        color: var(--c-text-faint);
        margin-bottom: 4px;
    }
    .pic-filter-bar input {
        background: var(--c-surface-2);
        border: 1px solid var(--c-border-strong);
        color: var(--c-white);
        border-radius: 6px;
        padding: 8px 10px;
        font-size: 0.875rem;
        outline: none;
        width: 100%;
        transition: border-color .2s;
    }
    .pic-filter-bar input:focus { border-color: var(--c-red); }
    .pic-filter-bar input::placeholder { color: var(--c-text-faint); }

    /* ── Searchable dropdown (matches pic_levels.php) ── */
    .dd-wrap { position: relative; }
    .dd-trigger{
        display:flex;
        align-items:center;
        justify-content:space-between;
        min-height:46px;
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
    .dd-opt {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 9px 12px;
        font-size: 0.875rem;
        color: var(--c-white);
        cursor: pointer;
        transition: background .1s;
    }
    .dd-opt:hover { background: var(--c-surface-3); }
    .dd-opt.selected { color: var(--c-red); font-weight: 600; }
    .dd-opt.hidden { display: none; }
    .dd-empty { padding: 10px 12px; color: var(--c-text-faint); font-size: 0.82rem; display: none; text-align: center; }
    .dd-opt-siri {
        flex-shrink: 0;
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

    html.pm-light .dd-trigger { background: var(--c-gray-50); border-color: var(--c-gray-300); color: #111; }
    html.pm-light .dd-panel { background: #fff; box-shadow: 0 6px 20px rgba(0,0,0,0.12); }
    html.pm-light .dd-search-box { border-bottom-color: var(--c-gray-200); }
    html.pm-light .dd-search-box input { background: var(--c-gray-50); border-color: var(--c-gray-300); color: #111; }
    html.pm-light .dd-opt { color: #111; }
    html.pm-light .dd-opt:hover { background: var(--c-gray-100); }
    html.pm-light .dd-opt-siri { background: #fdecec; color: var(--c-red-700); border-color: var(--c-red-300); }

    /* ── Add card ── */
    .pic-add-card {
        display: none;
        background: var(--c-surface-1);
        border: 1px solid var(--c-red-border);
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 18px;
    }
    .pic-add-card h3 {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 1.1rem;
        color: var(--c-white);
        margin-bottom: 14px;
        letter-spacing: 0.05em;
    }
    .pic-add-form {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 10px;
        align-items: end;
    }
    .pic-add-form input {
        background: var(--c-surface-2);
        border: 1px solid var(--c-border-strong);
        color: var(--c-white);
        border-radius: 6px;
        padding: 9px 12px;
        font-size: 0.875rem;
        outline: none;
        width: 100%;
        transition: border-color .2s;
    }
    .pic-add-form input:focus { border-color: var(--c-red); box-shadow: 0 0 0 3px var(--c-red-dim); }
    .pic-add-form input::placeholder { color: var(--c-text-faint); }

    /* ── Main card & session rows ── */
    .sessions-card {
        background: transparent;
        border: none;
        overflow: visible;
        position: relative;
    }
    .sessions-save-bar {
        display: none;
        align-items: center;
        gap: 12px;
        padding: 10px 16px;
        background: var(--c-surface-2);
        border: 1px solid var(--c-red-border);
        border-radius: 10px;
        margin-bottom: 12px;
        position: sticky;
        top: 0;
        z-index: 10;
        animation: slideDown .2s ease;
    }
    .sessions-save-bar.visible { display: flex; }
    @keyframes slideDown {
        from { opacity: 0; transform: translateY(-6px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .sessions-save-bar .save-msg { font-size: 0.82rem; color: var(--c-text-faint); flex: 1; }
    .sessions-save-bar .save-msg strong { color: var(--c-white); }

    .sessions-list-wrap {
        overflow-x: hidden;
        padding: 2px;
    }

    /* ── PAGINATION: shared .vm-pagination styles now live once in
       dashboard.css (loaded by layout.php), used by every paginated page. ── */

    /* ── Session row card ── */
    .session-row {
        display: flex;
        align-items: flex-start;
        gap: 16px;
        background: var(--c-surface-1);
        border: 1px solid var(--c-border);
        border-radius: 12px;
        padding: 14px 16px;
        margin-bottom: 10px;
        transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
    }
    .session-row:last-child { margin-bottom: 0; }
    .session-row:hover {
        border-color: var(--c-border-strong);
        box-shadow: 0 4px 16px rgba(0,0,0,0.18);
    }
    .session-row.row-dirty {
        border-color: var(--c-red-border);
        background: rgba(214, 40, 40, 0.04);
    }

    .session-row-index {
        flex-shrink: 0;
        width: 26px;
        height: 26px;
        border-radius: 50%;
        background: var(--c-surface-3);
        color: var(--c-text-faint);
        font-size: 0.72rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-top: 2px;
    }

    .session-row-main {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .session-row-name-group {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .session-name-input {
        background: var(--c-surface-0) !important;
        border: 1px solid var(--c-border-strong) !important;
        color: var(--c-white) !important;
        border-radius: 6px !important;
        padding: 6px 10px !important;
        font-size: 0.88rem !important;
        font-weight: 600;
        outline: none !important;
        width: auto;
        max-width: 260px;
        min-width: 120px;
        transition: border-color .2s;
    }
    .session-name-input:focus { border-color: var(--c-red) !important; }

    .siri-chip {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--c-red);
        background: var(--c-red-dim);
        border: 1px solid var(--c-red-border);
        padding: 3px 10px;
        border-radius: 999px;
        white-space: nowrap;
    }
    .siri-chip::before {
        content: '';
        width: 5px;
        height: 5px;
        border-radius: 50%;
        background: var(--c-red);
        flex-shrink: 0;
    }

    .session-row-schools {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }
    .school-chip {
        font-size: 0.78rem;
        color: var(--c-text-muted);
        background: var(--c-surface-2);
        border: 1px solid var(--c-border);
        border-radius: 999px;
        padding: 3px 11px;
        white-space: nowrap;
    }
    .school-chip-empty {
        font-size: 0.82rem;
        color: var(--c-text-faint);
        font-style: italic;
    }

    .session-row-actions {
        flex-shrink: 0;
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 2px;
    }

    .btn-sm { font-size: 0.78rem !important; padding: 5px 12px !important; }

    #ajaxSpinner { display: none; padding: 30px; text-align: center; color: var(--c-text-faint); margin: 0; }

    /* ── Modal (Assign Schools) ── */
    .pm-modal-overlay { position:fixed;inset:0;background:rgba(0,0,0,0.75);backdrop-filter:blur(4px);z-index:9999;display:none;align-items:center;justify-content:center;opacity:0;transition:opacity 0.2s; }
    .pm-modal-overlay.show { opacity:1; }
    .pm-modal-title { font-family:'Bebas Neue',sans-serif;font-size:1.5rem;letter-spacing:0.05em;color:var(--c-white);border-bottom:2px solid var(--c-red);display:inline-block;padding-bottom:6px;margin-bottom:18px; }
    .pm-modal-actions { display:flex;gap:10px;justify-content:flex-end;margin-top:18px; }

    .assign-checkbox-grid { 
        display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:8px; 
        max-height:300px; overflow-y:auto; padding:12px; background:var(--c-surface-0); 
        border:1px solid var(--c-border); border-radius:8px; 
        scrollbar-width:thin; scrollbar-color:var(--c-surface-3) transparent; 
        margin-bottom:16px; 
    }
    .assign-checkbox-grid label { 
        display:flex; align-items:center; gap:8px; font-size:0.85rem; color:var(--c-text-muted); 
        cursor:pointer; margin:0; padding:6px 8px; border-radius:4px; transition: background .15s;
    }
    .assign-checkbox-grid label:hover { background:var(--c-surface-2); color:var(--c-white); }
    .assign-checkbox-grid input[type=checkbox] { accent-color:var(--c-red); width:16px; height:16px; margin:0; flex-shrink:0; cursor:pointer;}

    /* ══════════════════════════════════════════════════════════
       LIGHT MODE
    ══════════════════════════════════════════════════════════ */
    html.pm-light .pic-section-header h2 { color: #111; }
    html.pm-light .pic-section-sub { color: #555; }
    
    html.pm-light .filter-card { background: #fff; border-color: var(--c-gray-200); box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
    html.pm-light .filter-card-title { color: #888; }
    html.pm-light .pic-filter-bar label { color: #555; }
    html.pm-light .pic-filter-bar input { background: var(--c-gray-50); border-color: var(--c-gray-300); color: #111; }
    html.pm-light .pic-filter-bar input::placeholder { color: var(--c-gray-400); }
    
    html.pm-light .pic-add-card { background: #fff; border-color: var(--c-red-border); }
    html.pm-light .pic-add-card h3 { color: #111; }
    html.pm-light .pic-add-form input { background: var(--c-gray-50); border-color: var(--c-gray-300); color: #111; }
    
    html.pm-light .sessions-save-bar { background: #fdecec; border-color: var(--c-red-300); }
    html.pm-light .sessions-save-bar .save-msg { color: #555; }
    html.pm-light .sessions-save-bar .save-msg strong { color: #111; }

    html.pm-light .session-row { background: #fff; border-color: var(--c-gray-200); }
    html.pm-light .session-row:hover { border-color: var(--c-gray-300); box-shadow: 0 4px 14px rgba(0,0,0,0.08); }
    html.pm-light .session-row.row-dirty { border-color: var(--c-red-300); background: rgba(214, 40, 40, 0.03); }
    html.pm-light .session-row-index { background: var(--c-gray-100); color: var(--c-gray-500); }
    html.pm-light .session-name-input { background: #fff !important; border-color: var(--c-gray-300) !important; color: #111 !important; }
    html.pm-light .session-name-input:focus { border-color: var(--c-red) !important; box-shadow: 0 0 0 2px var(--c-red-dim) !important; }
    html.pm-light .siri-chip { background: #fdecec; color: var(--c-red-700); border-color: var(--c-red-300); }
    html.pm-light .siri-chip::before { background: var(--c-red-700); }
    html.pm-light .school-chip { background: var(--c-gray-100); border-color: var(--c-gray-200); color: var(--c-gray-700); }
    html.pm-light .school-chip-empty { color: var(--c-gray-400); }
    
    html.pm-light .pm-modal-box { background: #fff; border-color: var(--c-gray-200); }
    html.pm-light .pm-modal-title { color: #111; }
    html.pm-light .assign-checkbox-grid { background: var(--c-gray-50); border-color: var(--c-gray-200); }
    html.pm-light .assign-checkbox-grid label { color: #4b5563; }
    html.pm-light .assign-checkbox-grid label:hover { background: #fff; color: #111; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
</style>

<form id='saveAllForm' method='POST' style='display:none;'>
    <input type='hidden' name='action' value='save_all'>
    <input type='hidden' name='csrf_token' value='<?= htmlspecialchars($csrf) ?>'>
</form>
<form id='delFrm' method='POST' style='display:none;'>
    <input type='hidden' name='action' value='delete'>
    <input type='hidden' name='session_id' value=''>
    <input type='hidden' name='csrf_token' value='<?= htmlspecialchars($csrf) ?>'>
</form>

<div class='pic-section-header'>
    <div>
        <h2>📅 Pengurusan Sidang</h2>
        <div class='pic-section-sub'>Urus sidang dan cawangan yang ditugaskan</div>
    </div>
    <button type='button' class='pm-btn pm-btn-primary' onclick="toggleAddCard()">+ Tambah Sidang</button>
</div>

<?php if (isset($_GET['msg'])):
    $isError = ($_GET['status'] ?? '') === 'error';
?>
<div class="pm-alert <?= $isError ? 'pm-alert-danger' : 'pm-alert-success' ?>">
    <?= $isError ? '⚠️' : '✅' ?> <?= htmlspecialchars($_GET['msg']) ?>
</div>
<?php endif; ?>

<div class='filter-card'>
    <div class='filter-card-title'>🔍 Tapis Sidang</div>
    <div class='pic-filter-bar'>
        <div>
            <label>Cari atau Pilih Sidang</label>
            <div class="dd-wrap" id="ddWrap_session">
                <div class="dd-trigger" id="ddTrigger_session" onclick="ddToggle('session')">
                    <span id="ddLabel_session" style="color:var(--c-text-faint);">-- Semua Sidang --</span>
                    <span class="dd-arrow">▼</span>
                </div>
                <div class="dd-panel" id="ddPanel_session">
                    <div class="dd-search-box">
                        <input type="text" id="f_search" placeholder="Taip untuk cari..." autocomplete="off" oninput="ddSessionSearch(this.value)" onclick="event.stopPropagation()">
                    </div>
                    <div class="dd-options" id="ddOpts_session">
                        <div class="dd-opt selected" data-value="" onclick="ddSelectSession('','-- Semua Sidang --')">-- Semua Sidang --</div>
                        <?php
                        // Scope to "Siri Aktif"; when "Semua Siri" is active, tag each
                        // session with its siri so same-named sessions aren't ambiguous
                        // (e.g. two different siri can each have a "Sidang 1").
                        $active_siri_dd = (int)($_SESSION['active_siri_id'] ?? 0);
                        $all_sessions = $active_siri_dd > 0
                            ? $conn->query("SELECT session_name FROM sessions WHERE siri_id = $active_siri_dd ORDER BY session_name")
                            : $conn->query("SELECT se.session_name, si.siri_name FROM sessions se LEFT JOIN siri si ON se.siri_id = si.siri_id ORDER BY se.session_name");
                        while ($ds = $all_sessions->fetch_assoc()) {
                            $s_name = htmlspecialchars($ds['session_name'], ENT_QUOTES);
                            $siriHtml = '';
                            if ($active_siri_dd === 0) {
                                $siriLbl = !empty($ds['siri_name']) ? htmlspecialchars($ds['siri_name']) : 'Tiada Siri';
                                $siriHtml = "<span class='dd-opt-siri'>$siriLbl</span>";
                            }
                            echo "<div class='dd-opt' data-value='{$s_name}' onclick=\"ddSelectSession('{$s_name}','{$s_name}')\"><span>{$s_name}</span>{$siriHtml}</div>";
                        }
                        ?>
                    </div>
                    <div class="dd-empty" id="ddEmpty_session">Tiada hasil</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div id='addSession' class='pic-add-card'>
    <h3>+ Tambah Sidang Baru</h3>
    <form method='POST' class='pic-add-form'>
        <input type='hidden' name='action' value='add'>
        <input type='hidden' name='csrf_token' value='<?= htmlspecialchars($csrf) ?>'>
        <div>
            <label style='display:block;font-size:0.75rem;color:var(--c-text-faint);margin-bottom:4px;text-transform:uppercase;font-weight:600;letter-spacing:.07em;'>Nama Sidang</label>
            <input name='session_name' placeholder='Nama Sidang' required>
        </div>
        <div style='display:flex;align-items:flex-end;'>
            <button class='pm-btn pm-btn-primary'>Tambah</button>
        </div>
    </form>
</div>

<div class='sessions-card'>
    <div class='sessions-save-bar' id='saveDirtyBar'>
        <span class='save-msg'>Ada <strong id='dirtyCount'>0</strong> perubahan belum disimpan</span>
        <button type='button' class='pm-btn pm-btn-ghost btn-sm' style='padding:5px 12px;' onclick="discardAll()">Batal</button>
        <button type='button' class='pm-btn pm-btn-primary btn-sm' style='padding:5px 12px;' onclick="submitSaveAll()">💾 Simpan Semua</button>
    </div>

    <div class='sessions-list-wrap'>
        <div id='sessionTbody'>
            <div style='text-align:center;padding:20px;color:var(--c-text-faint);'>Memuatkan...</div>
        </div>
        <div id='ajaxSpinner'>⏳ Mencari...</div>
    </div>

    <div class="vm-pagination" id="sessionsPaginationContainer" style="display:none;">
        <div class="vm-page-info" id="sessionsPageInfo"></div>
        <div class="vm-page-btns" id="sessionsPaginationButtons"></div>
    </div>
</div>

<div class="pm-modal-overlay" id="modal-assign-schools">
    <div class="pm-modal-box pm-modal-box--lg">
        <div class="pm-modal-title">Cawangan: <span id="modalSessionName" style="color:var(--c-text-muted);font-size:1.2rem;"></span></div>
        <p style="font-size:0.85rem;color:var(--c-text-faint);margin-bottom:14px;">
            Pilih cawangan yang terlibat dalam sidang ini.
        </p>
        <form method='POST'>
            <input type='hidden' name='action' value='assign_school'>
            <input type='hidden' name='session_id' id='assignSessionId' value=''>
            <input type='hidden' name='csrf_token' value='<?= htmlspecialchars($csrf) ?>'>
            
            <div class='assign-checkbox-grid'>
                <?php
                // Fetch all schools once for the modal
                $schs = $conn->query("SELECT school_id, school_name FROM schools ORDER BY school_name ASC");
                while ($sc = $schs->fetch_assoc()) {
                    $sc_id = $sc['school_id'];
                    $sc_name = htmlspecialchars($sc['school_name']);
                    echo "<label><input type='checkbox' name='school_ids[]' value='$sc_id' class='school-cb'> $sc_name</label>";
                }
                ?>
            </div>
            
            <div class="pm-modal-actions">
                <button type="button" class="pm-btn pm-btn-ghost" onclick="closeModal('modal-assign-schools')">Batal</button>
                <button type="submit" class="pm-btn pm-btn-primary">Simpan Cawangan</button>
            </div>
        </form>
    </div>
</div>

<script>
let _t = null;
let _dirtyRows = new Set();

// ── Searchable dropdown logic (matches pic_levels.php's dd-wrap) ──
function ddToggle(name) {
    const panel = document.getElementById('ddPanel_' + name);
    const trigger = document.getElementById('ddTrigger_' + name);
    const isOpen = panel.classList.contains('open');
    document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
    document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
    if (!isOpen) {
        panel.classList.add('open'); trigger.classList.add('open');
        setTimeout(() => panel.querySelector('.dd-search-box input')?.focus(), 50);
    }
}

function ddSessionSearch(val) {
    const opts = document.querySelectorAll('#ddOpts_session .dd-opt');
    const empty = document.getElementById('ddEmpty_session');
    let any = false;
    opts.forEach(o => {
        const m = o.textContent.toLowerCase().includes(val.toLowerCase());
        o.classList.toggle('hidden', !m);
        if (m) any = true;
    });
    empty.style.display = any ? 'none' : 'block';

    const lbl = document.getElementById('ddLabel_session');
    lbl.textContent = val || '-- Semua Sidang --';
    lbl.style.color = val ? '' : 'var(--c-text-faint)';
    ajaxFilter();
}

function ddSelectSession(value, label) {
    document.getElementById('f_search').value = value;
    const lbl = document.getElementById('ddLabel_session');
    lbl.textContent = label;
    lbl.style.color = value === '' ? 'var(--c-text-faint)' : '';
    document.querySelectorAll('#ddOpts_session .dd-opt').forEach(o => o.classList.toggle('selected', o.dataset.value === value));
    document.getElementById('ddPanel_session').classList.remove('open');
    document.getElementById('ddTrigger_session').classList.remove('open');
    ajaxFilter();
}

document.addEventListener('click', e => {
    if (!e.target.closest('.dd-wrap')) {
        document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
        document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
    }
});

// ── Table Logic ──
function ajaxFilter() {
    clearTimeout(_t);
    _t = setTimeout(_load, 400);
}

function _load() {
    const params = new URLSearchParams({ 
        ajax: '1', 
        search: document.getElementById('f_search').value
    });
    
    const tbody = document.getElementById('sessionTbody');
    const spinner = document.getElementById('ajaxSpinner');
    
    tbody.style.display = 'none';
    spinner.style.display = 'block';

    pmFetch('pic_sessions.php?' + params)
        .then(r => r.text())
        .then(html => {
            tbody.innerHTML = html;
            tbody.style.display = 'block';
            spinner.style.display = 'none';
            _dirtyRows.clear();
            updateDirtyBar();
            attachListeners();
            sessionsCurrentPage = 1;
            updateSessionsPagination();
        })
        .catch(() => {
            tbody.style.display = 'block';
            spinner.style.display = 'none';
        });
}

// ── PAGINATION (client-side, 20 per page — matches pic_view_marks.php) ──
let sessionsCurrentPage = 1;
const sessionsPerPage = 20;

function updateSessionsPagination() {
    const rows = Array.from(document.querySelectorAll('#sessionTbody .session-row'));
    const container = document.getElementById('sessionsPaginationContainer');
    const info = document.getElementById('sessionsPageInfo');
    const btns = document.getElementById('sessionsPaginationButtons');

    if (rows.length === 0) { container.style.display = 'none'; return; }

    const total = rows.length;
    const totalPages = Math.max(1, Math.ceil(total / sessionsPerPage));
    if (sessionsCurrentPage > totalPages) sessionsCurrentPage = totalPages;
    if (sessionsCurrentPage < 1) sessionsCurrentPage = 1;

    if (totalPages <= 1) { container.style.display = 'none'; }
    else { container.style.display = 'flex'; }

    const start = (sessionsCurrentPage - 1) * sessionsPerPage;
    const end   = start + sessionsPerPage;
    rows.forEach((r, i) => { r.style.display = (i >= start && i < end) ? '' : 'none'; });

    const s = start + 1;
    const e = Math.min(end, total);
    info.innerHTML = `Memaparkan <b>${s}–${e}</b> daripada <b>${total}</b> sidang`;

    pmRenderPagination(btns, sessionsCurrentPage, totalPages, sessionsGoToPage);
}

function sessionsGoToPage(page) {
    sessionsCurrentPage = page;
    updateSessionsPagination();
}

function attachListeners() {
    document.querySelectorAll('#sessionTbody input').forEach(el => {
        if (el.dataset.listening) return;
        el.dataset.listening = '1';
        el.addEventListener('input', onInputChange);
    });
}

function onInputChange(e) {
    const row = e.target.closest('.session-row');
    if (!row) return;
    const id = row.dataset.id;
    const orig = e.target.dataset.orig ?? '';
    
    if (e.target.value !== orig) {
        _dirtyRows.add(id);
        row.classList.add('row-dirty');
    } else {
        _dirtyRows.delete(id);
        row.classList.remove('row-dirty');
    }
    updateDirtyBar();
}

function updateDirtyBar() {
    const bar = document.getElementById('saveDirtyBar');
    const count = document.getElementById('dirtyCount');
    count.textContent = _dirtyRows.size;
    bar.classList.toggle('visible', _dirtyRows.size > 0);
}

function submitSaveAll() {
    const form = document.getElementById('saveAllForm');
    form.querySelectorAll('.dyn-input').forEach(el => el.remove());
    
    document.querySelectorAll('#sessionTbody input').forEach(el => {
        const h = document.createElement('input');
        h.type = 'hidden';
        h.name = el.name;
        h.value = el.value;
        h.className = 'dyn-input';
        form.appendChild(h);
    });
    form.submit();
}

function discardAll() {
    document.querySelectorAll('#sessionTbody input').forEach(el => {
        if (el.dataset.orig !== undefined) el.value = el.dataset.orig;
        el.closest('.session-row')?.classList.remove('row-dirty');
    });
    _dirtyRows.clear();
    updateDirtyBar();
}

function delRow(id) {
    if (!confirm('Padam sidang ini? Amaran: Semua data berkaitan kumpulan dan tugasan cawangan akan hilang.')) return;
    document.getElementById('delFrm').querySelector('[name=session_id]').value = id;
    document.getElementById('delFrm').submit();
}

function toggleAddCard() {
    const el = document.getElementById('addSession');
    el.style.display = (el.style.display === 'block') ? 'none' : 'block';
}

// ── Modal Logic ──
function openModal(id) {
    const m = document.getElementById(id);
    m.style.display = 'flex';
    setTimeout(() => m.classList.add('show'), 10);
}
function closeModal(id) {
    const m = document.getElementById(id);
    m.classList.remove('show');
    setTimeout(() => m.style.display = 'none', 200);
}
document.querySelectorAll('.pm-modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); });
});

function openAssignModal(sid, sessionName, assignedIdsString) {
    document.getElementById('assignSessionId').value = sid;
    document.getElementById('modalSessionName').innerText = sessionName;
    
    const assignedArray = assignedIdsString.split(',').filter(Boolean);
    document.querySelectorAll('.school-cb').forEach(cb => {
        cb.checked = assignedArray.includes(cb.value);
    });
    
    openModal('modal-assign-schools');
}

document.addEventListener('DOMContentLoaded', _load);
</script>

</main>
</body>
</html>