<?php
ini_set('display_errors', 0); ini_set('display_startup_errors', 0);
error_reporting(E_ALL); ini_set('log_errors', 1);

session_start();
include 'db.php';
$conn = getDB();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pic') {
    header("Location: login.php"); exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_write_close();
    if ($_POST['action'] === 'add') {
        $name = trim($_POST['session_name']);
        if ($name) {
            $stmt = $conn->prepare("INSERT INTO sessions (session_name) VALUES (?)");
            $stmt->bind_param('s', $name); $stmt->execute(); $stmt->close();
        }
    }
    if ($_POST['action'] === 'delete') {
        $sid = (int)$_POST['session_id'];
        foreach (["DELETE FROM session_schools WHERE session_id=?","DELETE FROM `groups` WHERE session_id=?","DELETE FROM levels WHERE session_id=?","DELETE FROM sessions WHERE session_id=?"] as $sql) {
            $stmt = $conn->prepare($sql); $stmt->bind_param('i', $sid); $stmt->execute(); $stmt->close();
        }
    }
    if ($_POST['action'] === 'save_all' && isset($_POST['sessions'])) {
        $stmt = $conn->prepare("UPDATE sessions SET session_name=? WHERE session_id=?");
        foreach ($_POST['sessions'] as $id => $s) {
            $name = trim($s['session_name']); $id = (int)$id;
            if ($name) { $stmt->bind_param('si', $name, $id); $stmt->execute(); }
        }
        $stmt->close();
    }
    if ($_POST['action'] === 'assign_school') {
        $sid = (int)$_POST['session_id'];
        $stmt = $conn->prepare("DELETE FROM session_schools WHERE session_id=?");
        $stmt->bind_param('i', $sid); $stmt->execute(); $stmt->close();
        $schools_post = $_POST['school_ids'] ?? [];
        $stmt = $conn->prepare("INSERT INTO session_schools (session_id, school_id) VALUES (?, ?)");
        foreach ($schools_post as $sc) { $sc = (int)$sc; $stmt->bind_param('ii', $sid, $sc); $stmt->execute(); }
        $stmt->close();
    }
    header("Location: pic_sessions.php"); exit();
}

session_write_close();

// ── AJAX rows ─────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    $search = $_GET['search'] ?? '';

    // Scope to "Siri Aktif" — sessions carry siri_id directly.
    $active_siri = (int)($_SESSION['active_siri_id'] ?? 0);

    $whereArr = ["1=1"]; $sTypes = ''; $sVals = [];
    if ($search !== '') { $whereArr[] = "session_name LIKE ?"; $sTypes .= 's'; $sVals[] = '%'.$search.'%'; }
    if ($active_siri > 0) { $whereArr[] = "siri_id = ?"; $sTypes .= 'i'; $sVals[] = $active_siri; }
    $whereSql = implode(" AND ", $whereArr);

    $stmt = $conn->prepare("SELECT * FROM sessions WHERE $whereSql ORDER BY session_name ASC");
    if ($sTypes) $stmt->bind_param($sTypes, ...$sVals);
    $stmt->execute();
    $sessions_result = $stmt->get_result();
    $stmt->close();

    $rows = [];
    while ($row = $sessions_result->fetch_assoc()) $rows[] = $row;

    if (empty($rows)) {
        echo "<tr><td colspan='4' style='text-align:center;padding:30px;color:var(--c-text-faint);border-bottom:none;'>Tiada sidang dijumpai.</td></tr>";
        exit();
    }

    $no = 1;
    foreach ($rows as $row) {
        $sid = $row['session_id'];
        
        // Fetch assigned schools for this session
        $stmt2 = $conn->prepare("SELECT ss.school_id, s.school_name FROM session_schools ss JOIN schools s ON ss.school_id=s.school_id WHERE ss.session_id=? ORDER BY s.school_name");
        $stmt2->bind_param('i', $sid); $stmt2->execute();
        $assigned = $stmt2->get_result(); $stmt2->close();
        
        $names = [];
        $ids = [];
        while ($a = $assigned->fetch_assoc()) {
            $names[] = htmlspecialchars($a['school_name']);
            $ids[] = $a['school_id'];
        }
        $schoolText = $names ? implode(', ', $names) : "<span style='color:var(--c-text-faint);font-style:italic;'>Tiada cawangan ditugaskan</span>";
        $idStr = implode(',', $ids);
        $safeName = htmlspecialchars($row['session_name'], ENT_QUOTES);

        echo "<tr class='session-row' data-id='$sid'>
                <td class='col-no' style='text-align:center;color:var(--c-text-faint);'>$no</td>
                <td><input name='sessions[$sid][session_name]' value='$safeName' form='saveAllForm' data-orig='$safeName'></td>
                <td style='color:var(--c-text-muted);font-size:0.85rem;line-height:1.5;'>$schoolText</td>
                <td class='col-actions' style='text-align:center;'>
                    <button type='button' class='pm-btn pm-btn-outline' style='font-size:0.78rem;padding:5px 12px;' onclick=\"openAssignModal('$sid', '$safeName', '$idStr')\">Cawangan</button>
                    <button type='button' class='pm-btn pm-btn-danger' style='font-size:0.78rem;padding:5px 12px;' onclick=\"delRow('$sid')\">Padam</button>
                </td>
              </tr>";
        $no++;
    }
    exit();
}

$pm_page = 'sessions';
include 'layout.php';
?>

<style>
    /* ── Page header ── */
    .pic-section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 20px;
    }
    .pic-section-header h2 {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 1.8rem;
        color: var(--c-white);
        letter-spacing: 0.05em;
        margin: 0;
    }
    .pic-section-sub {
        color: var(--c-text-faint);
        font-size: 0.82rem;
        margin-top: 2px;
    }

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

    /* ── Custom Select-Search Component ── */
    .custom-search-wrapper { position: relative; width: 100%; }
    .custom-dropdown {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: var(--c-surface-1);
        border: 1px solid var(--c-border-strong);
        border-top: none;
        border-radius: 0 0 6px 6px;
        max-height: 200px;
        overflow-y: auto;
        z-index: 100;
        box-shadow: 0 4px 10px rgba(0,0,0,0.5);
    }
    .custom-dropdown.show { display: block; }
    .dropdown-item {
        padding: 10px 12px;
        font-size: 0.82rem;
        color: var(--c-text-faint);
        cursor: pointer;
        transition: background .1s, color .1s;
    }
    .dropdown-item:hover { background: var(--c-surface-2); color: var(--c-white); }
    .custom-dropdown::-webkit-scrollbar { width: 6px; }
    .custom-dropdown::-webkit-scrollbar-thumb { background: var(--c-surface-3); border-radius: 4px; }

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

    /* ── Main card & Table ── */
    .sessions-card {
        background: var(--c-surface-1);
        border: 1px solid var(--c-border-strong);
        border-radius: 10px;
        overflow: hidden;
        position: relative;
    }
    .sessions-save-bar {
        display: none;
        align-items: center;
        gap: 12px;
        padding: 10px 16px;
        background: var(--c-surface-2);
        border-bottom: 2px solid var(--c-red);
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

    .sessions-table-wrap {
        max-height: 520px;
        overflow-y: auto;
        overflow-x: auto;
        scrollbar-width: thin;
        scrollbar-color: var(--c-surface-3) transparent;
        margin: 0;
        padding: 0;
    }
    .sessions-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.875rem;
        min-width: 500px;
        margin: 0; 
    }
    .sessions-table thead th {
        background: var(--c-surface-3);
        color: var(--c-text-muted);
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        padding: 11px 14px;
        text-align: left;
        white-space: nowrap;
        border-bottom: 2px solid var(--c-red);
        position: sticky;
        top: 0; 
        z-index: 5;
    }
    .sessions-table td {
        padding: 9px 14px;
        color: var(--c-text-muted);
        background: var(--c-surface-1);
        border-bottom: 1px solid var(--c-border);
        vertical-align: middle;
        transition: background .1s;
    }
    
    .sessions-table tbody tr:last-child td {
        border-bottom: none;
    }
    
    .sessions-table tbody tr:hover td { background: var(--c-surface-2); }
    .sessions-table tbody tr.row-dirty td { background: rgba(214, 40, 40, 0.06) !important; }
    
    .sessions-table td.col-no { width: 50px; }
    .sessions-table td.col-actions { width: 170px; white-space: nowrap; }
    .sessions-table input {
        background: var(--c-surface-0) !important;
        border: 1px solid var(--c-border-strong) !important;
        color: var(--c-white) !important;
        border-radius: 4px !important;
        padding: 5px 8px !important;
        font-size: 0.82rem !important;
        outline: none !important;
        width: 100%;
        transition: border-color .2s;
    }
    .sessions-table input:focus { border-color: var(--c-red) !important; }
    .col-actions { display: flex; gap: 6px; align-items: center; justify-content: center; }

    #ajaxSpinner { display: none; padding: 30px; text-align: center; color: var(--c-text-faint); margin: 0; }

    /* ── Modal (Assign Schools) ── */
    .pm-modal-overlay { position:fixed;inset:0;background:rgba(0,0,0,0.75);backdrop-filter:blur(4px);z-index:9999;display:none;align-items:center;justify-content:center;opacity:0;transition:opacity 0.2s; }
    .pm-modal-overlay.show { opacity:1; }
    .pm-modal-box { background:var(--c-surface-1);border:1px solid var(--c-border-strong);border-radius:14px;width:100%;max-width:600px;margin:16px;padding:28px;box-shadow:0 20px 60px rgba(0,0,0,0.7);transform:translateY(24px);transition:transform 0.25s; }
    .pm-modal-overlay.show .pm-modal-box { transform:translateY(0); }
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
    
    html.pm-light .filter-card { background: #fff; border-color: #e5e7eb; box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
    html.pm-light .filter-card-title { color: #888; }
    html.pm-light .pic-filter-bar label { color: #555; }
    html.pm-light .pic-filter-bar input { background: #f9fafb; border-color: #d1d5db; color: #111; }
    html.pm-light .pic-filter-bar input::placeholder { color: #9ca3af; }
    
    html.pm-light .custom-dropdown { background: #fff; border-color: #d1d5db; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
    html.pm-light .dropdown-item { color: #4b5563; }
    html.pm-light .dropdown-item:hover { background: #f3f4f6; color: #111; }
    html.pm-light .custom-dropdown::-webkit-scrollbar-thumb { background: #d1d5db; }
    
    html.pm-light .pic-add-card { background: #fff; border-color: var(--c-red-border); }
    html.pm-light .pic-add-card h3 { color: #111; }
    html.pm-light .pic-add-form input { background: #f9fafb; border-color: #d1d5db; color: #111; }
    
    html.pm-light .sessions-card { background: #fff; border-color: #e5e7eb; }
    html.pm-light .sessions-save-bar { background: #f3f4f6; }
    html.pm-light .sessions-save-bar .save-msg { color: #555; }
    html.pm-light .sessions-save-bar .save-msg strong { color: #111; }
    
    html.pm-light .sessions-table thead th { background: #374151; color: #f9fafb; }
    html.pm-light .sessions-table td { background: #fff; color: #222; border-bottom-color: #e5e7eb; }
    html.pm-light .sessions-table tbody tr:hover td { background: #f9fafb; }
    html.pm-light .sessions-table tbody tr.row-dirty td { background: rgba(214, 40, 40, 0.04) !important; }
    html.pm-light .sessions-table input { background: #fff !important; border-color: #d1d5db !important; color: #111 !important; }
    html.pm-light .sessions-table input:focus { border-color: var(--c-red) !important; box-shadow: 0 0 0 2px var(--c-red-dim) !important; }
    
    html.pm-light .pm-modal-box { background: #fff; border-color: #e5e7eb; }
    html.pm-light .pm-modal-title { color: #111; }
    html.pm-light .assign-checkbox-grid { background: #f9fafb; border-color: #e5e7eb; }
    html.pm-light .assign-checkbox-grid label { color: #4b5563; }
    html.pm-light .assign-checkbox-grid label:hover { background: #fff; color: #111; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
</style>

<form id='saveAllForm' method='POST' style='display:none;'>
    <input type='hidden' name='action' value='save_all'>
</form>
<form id='delFrm' method='POST' style='display:none;'>
    <input type='hidden' name='action' value='delete'>
    <input type='hidden' name='session_id' value=''>
</form>

<div class='pic-section-header'>
    <div>
        <h2>📅 Pengurusan Sidang</h2>
        <div class='pic-section-sub'>Urus sidang dan cawangan yang ditugaskan</div>
    </div>
    <button type='button' class='pm-btn pm-btn-primary' onclick="toggleAddCard()">+ Tambah Sidang</button>
</div>

<div class='filter-card'>
    <div class='filter-card-title'>🔍 Tapis Sidang</div>
    <div class='pic-filter-bar'>
        <label>Cari atau Pilih Sidang</label>
        <div class='custom-search-wrapper'>
            <input type='text' id='f_search' placeholder='Taip untuk cari...' autocomplete='off'>
            <div id='sessionDropdown' class='custom-dropdown'>
                <?php
                $all_sessions = $conn->query("SELECT session_name FROM sessions ORDER BY session_name");
                while ($ds = $all_sessions->fetch_assoc()) {
                    $s_name = htmlspecialchars($ds['session_name'], ENT_QUOTES);
                    echo "<div class='dropdown-item' onclick=\"selectDropdownItem('$s_name')\">$s_name</div>";
                }
                ?>
            </div>
        </div>
    </div>
</div>

<div id='addSession' class='pic-add-card'>
    <h3>+ Tambah Sidang Baru</h3>
    <form method='POST' class='pic-add-form'>
        <input type='hidden' name='action' value='add'>
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
        <button type='button' class='pm-btn pm-btn-primary btn-sm' style='padding:5px 12px;' onclick="submitSaveAll()">💾 Simpan Semua</button>
        <button type='button' class='pm-btn btn-sm' style='padding:5px 12px;background:transparent;color:var(--c-text-faint);' onclick="discardAll()">Batal</button>
    </div>

    <div class='sessions-table-wrap'>
        <table class='sessions-table'>
            <thead>
                <tr>
                    <th style='width:50px;text-align:center;'>No</th>
                    <th>Nama Sidang</th>
                    <th>Cawangan Ditugaskan</th>
                    <th style='width:170px;text-align:center;'>Tindakan</th>
                </tr>
            </thead>
            <tbody id='sessionTbody'>
                <tr><td colspan='4' style='text-align:center;padding:20px;color:var(--c-text-faint);border-bottom:none;'>Memuatkan...</td></tr>
            </tbody>
        </table>
        <div id='ajaxSpinner'>⏳ Mencari...</div>
    </div>
</div>

<div class="pm-modal-overlay" id="modal-assign-schools">
    <div class="pm-modal-box">
        <div class="pm-modal-title">Cawangan: <span id="modalSessionName" style="color:var(--c-text-muted);font-size:1.2rem;"></span></div>
        <p style="font-size:0.85rem;color:var(--c-text-faint);margin-bottom:14px;">
            Pilih cawangan yang terlibat dalam sidang ini.
        </p>
        <form method='POST'>
            <input type='hidden' name='action' value='assign_school'>
            <input type='hidden' name='session_id' id='assignSessionId' value=''>
            
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

// ── Dropdown Logic ──
const searchInput = document.getElementById('f_search');
const dropdown = document.getElementById('sessionDropdown');
const dropdownItems = dropdown.querySelectorAll('.dropdown-item');

searchInput.addEventListener('focus', () => { dropdown.classList.add('show'); });
document.addEventListener('click', (e) => {
    if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) dropdown.classList.remove('show');
});
searchInput.addEventListener('input', () => {
    dropdown.classList.add('show');
    const filter = searchInput.value.toLowerCase();
    dropdownItems.forEach(item => {
        item.style.display = item.textContent.toLowerCase().includes(filter) ? 'block' : 'none';
    });
    ajaxFilter();
});

function selectDropdownItem(value) {
    searchInput.value = value;
    dropdown.classList.remove('show');
    ajaxFilter();
}

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

    fetch('pic_sessions.php?' + params)
        .then(r => r.text())
        .then(html => {
            tbody.innerHTML = html;
            tbody.style.display = 'table-row-group';
            spinner.style.display = 'none';
            _dirtyRows.clear();
            updateDirtyBar();
            attachListeners();
        });
}

function attachListeners() {
    document.querySelectorAll('#sessionTbody input').forEach(el => {
        if (el.dataset.listening) return;
        el.dataset.listening = '1';
        el.addEventListener('input', onInputChange);
    });
}

function onInputChange(e) {
    const row = e.target.closest('tr');
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
        el.closest('tr')?.classList.remove('row-dirty');
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