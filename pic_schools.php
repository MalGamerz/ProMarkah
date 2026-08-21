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
        header("Location: pic_schools.php?msg=Ralat+token+keselamatan.+Sila+muat+semula+halaman.&status=error"); exit();
    }
    session_write_close();
    $ok = false;
    try {
    if ($_POST['action'] === 'add') {
        $name = trim($_POST['school_name']);
        if ($name) {
            $stmt = $conn->prepare("INSERT INTO schools (school_name) VALUES (?)");
            $stmt->bind_param('s', $name); $ok = $stmt->execute(); $stmt->close();
            $msg = $ok ? 'Cawangan+berjaya+ditambah.' : 'Ralat+menambah+cawangan.+Sila+cuba+lagi.';
        } else {
            $msg = 'Sila+masukkan+nama+cawangan.';
        }
    } elseif ($_POST['action'] === 'delete') {
        $stmt = $conn->prepare("DELETE FROM schools WHERE school_id=?");
        $stmt->bind_param('i', $_POST['school_id']); $ok = $stmt->execute(); $stmt->close();
        $msg = $ok ? 'Cawangan+berjaya+dipadam.' : 'Ralat+memadam+cawangan.+Sila+cuba+lagi.';
    } elseif ($_POST['action'] === 'save_all' && isset($_POST['schools'])) {
        $stmt = $conn->prepare("UPDATE schools SET school_name=? WHERE school_id=?");
        $ok = true;
        foreach ($_POST['schools'] as $id => $s) {
            $name = trim($s['school_name']); $id = (int)$id;
            if ($name) { $stmt->bind_param('si', $name, $id); if (!$stmt->execute()) { $ok = false; } }
        }
        $stmt->close();
        $msg = $ok ? 'Perubahan+berjaya+disimpan.' : 'Ralat+menyimpan+sebahagian+perubahan.+Sila+semak+dan+cuba+lagi.';
    } else {
        $msg = 'Tindakan+tidak+sah.';
    }
    } catch (Throwable $e) {
        promarkah_report('Caught', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
        $ok  = false;
        $msg = 'Ralat+pangkalan+data.+Sila+cuba+lagi.';
    }
    header("Location: pic_schools.php?msg={$msg}&status=" . ($ok ? 'success' : 'error')); exit();
}

session_write_close();

// ── AJAX rows ─────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    $search = $_GET['search'] ?? '';
    $sort   = $_GET['sort']   ?? 'asc';

    // Scope to "Siri Aktif" — schools themselves aren't siri-specific, but
    // siri_schools records which ones are registered/participating in each
    // siri (see pic_siri.php), so use that to filter the list when one is selected.
    $active_siri = (int)($_SESSION['active_siri_id'] ?? 0);

    $whereArr = ["1=1"]; $schTypes = ''; $schVals = [];
    if ($search !== '') { $whereArr[] = "school_name LIKE ?"; $schTypes .= 's'; $schVals[] = '%'.$search.'%'; }
    if ($active_siri > 0) {
        $whereArr[] = "school_id IN (SELECT school_id FROM siri_schools WHERE siri_id = ?)";
        $schTypes .= 'i'; $schVals[] = $active_siri;
    }
    $whereSql = implode(" AND ", $whereArr);

    $orderBy = ($sort === 'desc') ? "school_name DESC" : "school_name ASC";

    $stmt = $conn->prepare("SELECT * FROM schools WHERE $whereSql ORDER BY $orderBy");
    if ($schTypes) $stmt->bind_param($schTypes, ...$schVals);
    $stmt->execute(); $result = $stmt->get_result(); $stmt->close();

    if ($result->num_rows === 0) {
        echo "<div style='text-align:center;padding:30px;color:var(--c-text-faint);'>Tiada cawangan dijumpai.</div>";
        exit();
    }
    $no = 1;
    while ($s = $result->fetch_assoc()) {
        $safeName = htmlspecialchars($s['school_name'], ENT_QUOTES);
        echo "<div class='school-row' data-id='{$s['school_id']}'>
                <div class='school-row-index'>{$no}</div>
                <input class='school-name-input' name='schools[{$s['school_id']}][school_name]' value='$safeName' form='saveAllForm' data-orig='$safeName'>
                <div class='school-row-actions'>
                    <button type='button' class='pm-btn pm-btn-danger btn-sm' onclick=\"delRow('{$s['school_id']}')\">Padam</button>
                </div>
              </div>";
        $no++;
    }
    exit();
}

$pm_page = 'schools';
include 'layout.php';
?>

<?php
$pm_schools_css_v = @filemtime(__DIR__ . '/pic_schools.css') ?: time();
?>
<link rel="stylesheet" href="pic_schools.css?v=<?= $pm_schools_css_v ?>">

<form id='saveAllForm' method='POST' style='display:none;'>
    <input type='hidden' name='action' value='save_all'>
    <input type='hidden' name='csrf_token' value='<?= htmlspecialchars($csrf) ?>'>
</form>
<form id='delFrm' method='POST' style='display:none;'>
    <input type='hidden' name='action' value='delete'>
    <input type='hidden' name='school_id' value=''>
    <input type='hidden' name='csrf_token' value='<?= htmlspecialchars($csrf) ?>'>
</form>

<div class='pic-section-header'>
    <div>
        <h2>🏫 Pengurusan Cawangan</h2>
        <div class='pic-section-sub'>Urus semua cawangan yang berdaftar</div>
    </div>
    <button type='button' class='pm-btn pm-btn-primary' onclick="toggleAddCard()">+ Tambah Cawangan</button>
</div>

<?php if (isset($_GET['msg'])):
    $isError = ($_GET['status'] ?? '') === 'error';
?>
<div class="pm-alert <?= $isError ? 'pm-alert-danger' : 'pm-alert-success' ?>">
    <?= $isError ? '⚠️' : '✅' ?> <?= htmlspecialchars($_GET['msg']) ?>
</div>
<?php endif; ?>

<div class='filter-card'>
    <div class='filter-card-title'>🔍 Tapis Cawangan</div>
    <div class='pic-filter-bar'>
        <div style="width: 100%;">
            <label>Cari atau Pilih Cawangan</label>
            <div class="dd-wrap" id="ddWrap_school">
                <div class="dd-trigger" id="ddTrigger_school" onclick="ddToggle('school')" role="button" tabindex="0" aria-haspopup="listbox">
                    <span id="ddLabel_school" style="color:var(--c-text-faint);">-- Semua Cawangan --</span>
                    <span class="dd-arrow">▼</span>
                </div>
                <div class="dd-panel" id="ddPanel_school" role="listbox">
                    <div class="dd-search-box">
                        <input type="text" id="f_search" placeholder="Taip untuk cari..." autocomplete="off" oninput="ddSchoolSearch(this.value)" onclick="event.stopPropagation()">
                    </div>
                    <div class="dd-options" id="ddOpts_school">
                        <div class="dd-opt selected" data-value="" role="option" tabindex="0" onclick="ddSelectSchool('','-- Semua Cawangan --')">-- Semua Cawangan --</div>
                        <?php
                        $all_schs = $conn->query("SELECT school_name FROM schools ORDER BY school_name");
                        while ($ds = $all_schs->fetch_assoc()) {
                            $sch_name = htmlspecialchars($ds['school_name'], ENT_QUOTES);
                            echo "<div class='dd-opt' role='option' tabindex='0' data-value='{$sch_name}' onclick=\"ddSelectSchool('{$sch_name}','{$sch_name}')\">{$sch_name}</div>";
                        }
                        ?>
                    </div>
                    <div class="dd-empty" id="ddEmpty_school">Tiada hasil</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div id='addSchool' class='pic-add-card'>
    <h3>+ Tambah Cawangan Baru</h3>
    <form method='POST' class='pic-add-form'>
        <input type='hidden' name='action' value='add'>
        <input type='hidden' name='csrf_token' value='<?= htmlspecialchars($csrf) ?>'>
        <div>
            <label style='display:block;font-size:0.75rem;color:var(--c-text-faint);margin-bottom:4px;text-transform:uppercase;font-weight:600;letter-spacing:.07em;'>Nama Cawangan</label>
            <input name='school_name' placeholder='Nama Cawangan' required>
        </div>
        <div style='display:flex;align-items:flex-end;'>
            <button class='pm-btn pm-btn-primary'>Tambah</button>
        </div>
    </form>
</div>

<div class='schools-card'>
    <div class='schools-save-bar' id='saveDirtyBar'>
        <span class='save-msg'>Ada <strong id='dirtyCount'>0</strong> perubahan belum disimpan</span>
        <button type='button' class='pm-btn pm-btn-ghost btn-sm' style='padding:5px 12px;' onclick="discardAll()">Batal</button>
        <button type='button' class='pm-btn pm-btn-primary btn-sm' style='padding:5px 12px;' onclick="submitSaveAll()">💾 Simpan Semua</button>
    </div>

    <div class='schools-list-wrap'>
        <div id='schoolListScroll'>
            <div id='schoolTbody'>
                <div style='text-align:center;padding:20px;color:var(--c-text-faint);'>Memuatkan...</div>
            </div>
            <div id='ajaxSpinner'>⏳ Mencari...</div>
        </div>
    </div>

    <div class="vm-pagination" id="schoolsPaginationContainer" style="display:none;">
        <div class="vm-page-info" id="schoolsPageInfo"></div>
        <div class="vm-page-btns" id="schoolsPaginationButtons"></div>
    </div>
</div>

<?php
$pm_schools_js_v = @filemtime(__DIR__ . '/pic_schools.js') ?: time();
?>
<script src="pic_schools.js?v=<?= $pm_schools_js_v ?>"></script>

</main>
</body>
</html>