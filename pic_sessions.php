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

<?php
$pm_sessions_css_v = @filemtime(__DIR__ . '/pic_sessions.css') ?: time();
?>
<link rel="stylesheet" href="pic_sessions.css?v=<?= $pm_sessions_css_v ?>">

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
                <div class="dd-trigger" id="ddTrigger_session" onclick="ddToggle('session')" role="button" tabindex="0" aria-haspopup="listbox">
                    <span id="ddLabel_session" style="color:var(--c-text-faint);">-- Semua Sidang --</span>
                    <span class="dd-arrow">▼</span>
                </div>
                <div class="dd-panel" id="ddPanel_session" role="listbox">
                    <div class="dd-search-box">
                        <input type="text" id="f_search" placeholder="Taip untuk cari..." autocomplete="off" oninput="ddSessionSearch(this.value)" onclick="event.stopPropagation()">
                    </div>
                    <div class="dd-options" id="ddOpts_session">
                        <div class="dd-opt selected" data-value="" role="option" tabindex="0" onclick="ddSelectSession('','-- Semua Sidang --')">-- Semua Sidang --</div>
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
                            echo "<div class='dd-opt' role='option' tabindex='0' data-value='{$s_name}' onclick=\"ddSelectSession('{$s_name}','{$s_name}')\"><span>{$s_name}</span>{$siriHtml}</div>";
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

<?php
$pm_sessions_js_v = @filemtime(__DIR__ . '/pic_sessions.js') ?: time();
?>
<script src="pic_sessions.js?v=<?= $pm_sessions_js_v ?>"></script>

</main>
</body>
</html>