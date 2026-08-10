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
    
    .pic-filter-bar input,
    .pic-filter-bar select {
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
    .pic-filter-bar input:focus,
    .pic-filter-bar select:focus {
        border-color: var(--c-red);
    }
    .pic-filter-bar select option {
        background: var(--c-surface-2);
        color: var(--c-white);
    }
    .pic-filter-bar input::placeholder {
        color: var(--c-text-faint);
    }

    /* ── Searchable dropdown (matches pic_levels.php / pic_sessions.php) ── */
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
    .pic-add-form input:focus {
        border-color: var(--c-red);
        box-shadow: 0 0 0 3px var(--c-red-dim);
    }
    .pic-add-form input::placeholder {
        color: var(--c-text-faint);
    }

    /* ── Main card ── */
    .schools-card {
        background: transparent;
        border: none;
        overflow: visible;
        position: relative;
    }

    /* ── Sticky Dirty save bar ── */
    .schools-save-bar {
        display: flex;
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
    }
    .schools-save-bar .save-msg {
        font-size: 0.82rem;
        color: var(--c-text-faint);
        flex: 1;
    }
    .schools-save-bar .save-msg strong {
        color: var(--c-white);
    }

    .schools-list-wrap {
        overflow-x: hidden;
        padding: 2px;
    }

    /* ── School row card ── */
    .school-row {
        display: flex;
        align-items: center;
        gap: 14px;
        background: var(--c-surface-1);
        border: 1px solid var(--c-border);
        border-radius: 12px;
        padding: 12px 16px;
        margin-bottom: 10px;
        transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
    }
    .school-row:last-child { margin-bottom: 0; }
    .school-row:hover {
        border-color: var(--c-border-strong);
        box-shadow: 0 4px 16px rgba(0,0,0,0.18);
    }
    .school-row.row-dirty {
        border-color: var(--c-red-border);
        background: rgba(214, 40, 40, 0.04);
    }
    .school-row-index {
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
    }
    .school-name-input {
        flex: 1;
        min-width: 0;
        background: var(--c-surface-0) !important;
        border: 1px solid var(--c-border-strong) !important;
        color: var(--c-white) !important;
        border-radius: 6px !important;
        padding: 8px 12px !important;
        font-size: 0.9rem !important;
        font-weight: 600;
        outline: none !important;
        transition: border-color .2s;
    }
    .school-name-input:focus { border-color: var(--c-red) !important; }
    .school-row-actions {
        flex-shrink: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .btn-sm { font-size: 0.78rem !important; padding: 5px 12px !important; }

    /* ── PAGINATION: shared .vm-pagination styles now live once in
       dashboard.css (loaded by layout.php), used by every paginated page. ── */

    #ajaxSpinner {
        display: none;
        padding: 30px;
        text-align: center;
        color: var(--c-text-faint);
    }

    /* ── SCHOOL LIST: scrollable so pagination always stays on screen ── */
    #schoolListScroll {
        overflow-y: auto;
        padding-right: 4px;
        scrollbar-width: thin;
        scrollbar-color: var(--c-border-strong) transparent;
    }
    #schoolListScroll::-webkit-scrollbar { width: 4px; }
    #schoolListScroll::-webkit-scrollbar-track { background: transparent; }
    #schoolListScroll::-webkit-scrollbar-thumb { background: var(--c-border-strong); border-radius: 2px; }
    @media (max-width: 640px) {
        #schoolListScroll { max-height: none !important; overflow-y: visible !important; }
    }

    /* ══════════════════════════════════════════════════════════
       LIGHT MODE
    ══════════════════════════════════════════════════════════ */
    html.pm-light .pic-section-header h2 { color: #111; }
    html.pm-light .pic-section-sub { color: #555; }

    html.pm-light .filter-card {
        background: #fff;
        border-color: var(--c-gray-200);
        box-shadow: 0 1px 4px rgba(0,0,0,0.06);
    }
    html.pm-light .filter-card-title { color: #888; }
    html.pm-light .pic-filter-bar label { color: #555; }
    html.pm-light .pic-filter-bar input,
    html.pm-light .pic-filter-bar select {
        background: var(--c-gray-50);
        border-color: var(--c-gray-300);
        color: #111;
    }
    html.pm-light .pic-filter-bar input::placeholder { color: var(--c-gray-400); }
    html.pm-light .pic-filter-bar select option {
        background: #fff;
        color: #111;
    }

    html.pm-light .pic-add-card {
        background: #fff;
        border-color: var(--c-red-border);
    }
    html.pm-light .pic-add-card h3 { color: #111; }
    html.pm-light .pic-add-form input {
        background: var(--c-gray-50);
        border-color: var(--c-gray-300);
        color: #111;
    }
    html.pm-light .pic-add-form input::placeholder { color: var(--c-gray-400); }

    html.pm-light .schools-save-bar { background: #fdecec; border-color: var(--c-red-300); }
    html.pm-light .schools-save-bar .save-msg { color: #555; }
    html.pm-light .schools-save-bar .save-msg strong { color: #111; }

    html.pm-light .school-row { background: #fff; border-color: var(--c-gray-200); }
    html.pm-light .school-row:hover { border-color: var(--c-gray-300); box-shadow: 0 4px 14px rgba(0,0,0,0.08); }
    html.pm-light .school-row.row-dirty { border-color: var(--c-red-300); background: rgba(214, 40, 40, 0.03); }
    html.pm-light .school-row-index { background: var(--c-gray-100); color: var(--c-gray-500); }
    html.pm-light .school-name-input { background: #fff !important; border-color: var(--c-gray-300) !important; color: #111 !important; }
    html.pm-light .school-name-input:focus { border-color: var(--c-red) !important; box-shadow: 0 0 0 2px var(--c-red-dim) !important; }
</style>

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
                <div class="dd-trigger" id="ddTrigger_school" onclick="ddToggle('school')">
                    <span id="ddLabel_school" style="color:var(--c-text-faint);">-- Semua Cawangan --</span>
                    <span class="dd-arrow">▼</span>
                </div>
                <div class="dd-panel" id="ddPanel_school">
                    <div class="dd-search-box">
                        <input type="text" id="f_search" placeholder="Taip untuk cari..." autocomplete="off" oninput="ddSchoolSearch(this.value)" onclick="event.stopPropagation()">
                    </div>
                    <div class="dd-options" id="ddOpts_school">
                        <div class="dd-opt selected" data-value="" onclick="ddSelectSchool('','-- Semua Cawangan --')">-- Semua Cawangan --</div>
                        <?php
                        $all_schs = $conn->query("SELECT school_name FROM schools ORDER BY school_name");
                        while ($ds = $all_schs->fetch_assoc()) {
                            $sch_name = htmlspecialchars($ds['school_name'], ENT_QUOTES);
                            echo "<div class='dd-opt' data-value='{$sch_name}' onclick=\"ddSelectSchool('{$sch_name}','{$sch_name}')\">{$sch_name}</div>";
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

<script>
let _t = null;
let _dirtyRows = new Set();

// ── Searchable dropdown logic (matches pic_levels.php / pic_sessions.php) ──
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

function ddSchoolSearch(val) {
    const opts = document.querySelectorAll('#ddOpts_school .dd-opt');
    const empty = document.getElementById('ddEmpty_school');
    let any = false;
    opts.forEach(o => {
        const m = o.textContent.toLowerCase().includes(val.toLowerCase());
        o.classList.toggle('hidden', !m);
        if (m) any = true;
    });
    empty.style.display = any ? 'none' : 'block';

    const lbl = document.getElementById('ddLabel_school');
    lbl.textContent = val || '-- Semua Cawangan --';
    lbl.style.color = val ? '' : 'var(--c-text-faint)';
    ajaxFilter();
}

function ddSelectSchool(value, label) {
    document.getElementById('f_search').value = value;
    const lbl = document.getElementById('ddLabel_school');
    lbl.textContent = label;
    lbl.style.color = value === '' ? 'var(--c-text-faint)' : '';
    document.querySelectorAll('#ddOpts_school .dd-opt').forEach(o => o.classList.toggle('selected', o.dataset.value === value));
    document.getElementById('ddPanel_school').classList.remove('open');
    document.getElementById('ddTrigger_school').classList.remove('open');
    ajaxFilter();
}

document.addEventListener('click', e => {
    if (!e.target.closest('.dd-wrap')) {
        document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
        document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
    }
});

// ── List Data Logic ──
function ajaxFilter() {
    clearTimeout(_t);
    _t = setTimeout(_load, 400);
}

function _load() {
    const params = new URLSearchParams({
        ajax: '1',
        search: document.getElementById('f_search').value
    });

    const tbody = document.getElementById('schoolTbody');
    const spinner = document.getElementById('ajaxSpinner');

    tbody.style.display = 'none';
    spinner.style.display = 'block';

    pmFetch('pic_schools.php?' + params)
        .then(r => r.text())
        .then(html => {
            tbody.innerHTML = html;
            tbody.style.display = 'block';
            spinner.style.display = 'none';
            _dirtyRows.clear();
            updateDirtyBar();
            attachListeners();
            schoolsCurrentPage = 1;
            updateSchoolsPagination();
            fitSchoolListHeight();
        })
        .catch(() => {
            tbody.style.display = 'block';
            spinner.style.display = 'none';
        });
}

// ── Fit the school list + pagination into the viewport, no page scroll ──
function fitSchoolListHeight() {
    if (window.innerWidth <= 640) {
        document.getElementById('schoolListScroll').style.maxHeight = '';
        return;
    }
    const scrollEl   = document.getElementById('schoolListScroll');
    const pagination = document.getElementById('schoolsPaginationContainer');
    const top = scrollEl.getBoundingClientRect().top;
    const paginationH = pagination.offsetHeight;
    const available = window.innerHeight - top - paginationH - 24; // 24px bottom breathing room
    scrollEl.style.maxHeight = Math.max(150, available) + 'px';
}
window.addEventListener('resize', fitSchoolListHeight);

// ── PAGINATION (client-side, 20 per page — matches pic_view_marks.php) ──
let schoolsCurrentPage = 1;
const schoolsPerPage = 20;

function updateSchoolsPagination() {
    const rows = Array.from(document.querySelectorAll('#schoolTbody .school-row'));
    const container = document.getElementById('schoolsPaginationContainer');
    const info = document.getElementById('schoolsPageInfo');
    const btns = document.getElementById('schoolsPaginationButtons');

    if (rows.length === 0) { container.style.display = 'none'; return; }

    const total = rows.length;
    const totalPages = Math.max(1, Math.ceil(total / schoolsPerPage));
    if (schoolsCurrentPage > totalPages) schoolsCurrentPage = totalPages;
    if (schoolsCurrentPage < 1) schoolsCurrentPage = 1;

    container.style.display = totalPages <= 1 ? 'none' : 'flex';

    const start = (schoolsCurrentPage - 1) * schoolsPerPage;
    const end   = start + schoolsPerPage;
    rows.forEach((r, i) => { r.style.display = (i >= start && i < end) ? '' : 'none'; });

    const s = start + 1;
    const e = Math.min(end, total);
    info.innerHTML = `Memaparkan <b>${s}–${e}</b> daripada <b>${total}</b> cawangan`;

    pmRenderPagination(btns, schoolsCurrentPage, totalPages, schoolsGoToPage);
}

function schoolsGoToPage(page) {
    schoolsCurrentPage = page;
    updateSchoolsPagination();
}

function attachListeners() {
    document.querySelectorAll('#schoolTbody input').forEach(el => {
        if (el.dataset.listening) return;
        el.dataset.listening = '1';
        el.addEventListener('input', onInputChange);
    });
}

function onInputChange(e) {
    const row = e.target.closest('.school-row');
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
    const count = document.getElementById('dirtyCount');
    count.textContent = _dirtyRows.size;
}

function submitSaveAll() {
    const form = document.getElementById('saveAllForm');
    form.querySelectorAll('.dyn-input').forEach(el => el.remove());

    document.querySelectorAll('#schoolTbody input').forEach(el => {
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
    document.querySelectorAll('#schoolTbody input').forEach(el => {
        if (el.dataset.orig !== undefined) el.value = el.dataset.orig;
        el.closest('.school-row')?.classList.remove('row-dirty');
    });
    _dirtyRows.clear();
    updateDirtyBar();
}

function delRow(id) {
    if (!confirm('Padam cawangan ini?')) return;
    document.getElementById('delFrm').querySelector('[name=school_id]').value = id;
    document.getElementById('delFrm').submit();
}

function toggleAddCard() {
    const el = document.getElementById('addSchool');
    el.style.display = (el.style.display === 'block') ? 'none' : 'block';
    setTimeout(fitSchoolListHeight, 0);
}

document.addEventListener('DOMContentLoaded', () => {
    _load();
    fitSchoolListHeight();
});
</script>

</main>
</body>
</html>