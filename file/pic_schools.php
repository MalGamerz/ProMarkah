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
        $name = trim($_POST['school_name']);
        if ($name) {
            $stmt = $conn->prepare("INSERT INTO schools (school_name) VALUES (?)");
            $stmt->bind_param('s', $name); $stmt->execute(); $stmt->close();
        }
    }
    if ($_POST['action'] === 'delete') {
        $stmt = $conn->prepare("DELETE FROM schools WHERE school_id=?");
        $stmt->bind_param('i', $_POST['school_id']); $stmt->execute(); $stmt->close();
    }
    if ($_POST['action'] === 'save_all' && isset($_POST['schools'])) {
        $stmt = $conn->prepare("UPDATE schools SET school_name=? WHERE school_id=?");
        foreach ($_POST['schools'] as $id => $s) {
            $name = trim($s['school_name']); $id = (int)$id;
            if ($name) { $stmt->bind_param('si', $name, $id); $stmt->execute(); }
        }
        $stmt->close();
    }
    header("Location: pic_schools.php"); exit();
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
        echo "<tr><td colspan='3' style='text-align:center;padding:30px;color:var(--c-text-faint);'>Tiada cawangan dijumpai.</td></tr>";
        exit();
    }
    $no = 1;
    while ($s = $result->fetch_assoc()) {
        echo "<tr class='school-row' data-id='{$s['school_id']}'>
                <td class='col-no' style='text-align:center;color:var(--c-text-faint);'>{$no}</td>
                <td><input name='schools[{$s['school_id']}][school_name]' value='" . htmlspecialchars($s['school_name']) . "' form='saveAllForm' data-orig='" . htmlspecialchars($s['school_name']) . "'></td>
                <td class='col-actions' style='text-align:center;'>
                    <button type='button' class='pm-btn pm-btn-danger' style='font-size:0.78rem;padding:5px 12px;'
                        onclick=\"delRow('{$s['school_id']}')\">Padam</button>
                </td>
              </tr>";
        $no++;
    }
    exit();
}

$pm_page = 'schools';
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

    /* ── Custom Select-Search Component ── */
    .custom-search-wrapper {
        position: relative;
        width: 100%;
    }
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
    .custom-dropdown.show {
        display: block;
    }
    .dropdown-item {
        padding: 10px 12px;
        font-size: 0.82rem;
        color: var(--c-text-faint);
        cursor: pointer;
        transition: background .1s, color .1s;
    }
    .dropdown-item:hover {
        background: var(--c-surface-2);
        color: var(--c-white);
    }
    /* Scrollbar styling for dropdown */
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
    .pic-add-form input:focus {
        border-color: var(--c-red);
        box-shadow: 0 0 0 3px var(--c-red-dim);
    }
    .pic-add-form input::placeholder {
        color: var(--c-text-faint);
    }

    /* ── Main card ── */
    .schools-card {
        background: var(--c-surface-1);
        border: 1px solid var(--c-border-strong);
        border-radius: 10px;
        overflow: hidden;
        position: relative;
    }

    /* ── Sticky Dirty save bar ── */
    .schools-save-bar {
        display: flex; 
        align-items: center;
        gap: 12px;
        padding: 10px 16px;
        background: var(--c-surface-2);
        border-bottom: 2px solid var(--c-red);
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

    /* ── Table scroll container ── */
    .schools-table-wrap {
        max-height: 520px;
        overflow-y: auto;
        overflow-x: auto;
        scrollbar-width: thin;
        scrollbar-color: var(--c-surface-3) transparent;
    }

    /* ── Table ── */
    .schools-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.875rem;
        min-width: 400px;
    }
    .schools-table thead th {
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
    .schools-table td {
        padding: 9px 14px;
        color: var(--c-text-muted);
        background: var(--c-surface-1);
        border-bottom: 1px solid var(--c-border);
        vertical-align: middle;
        transition: background .1s;
    }
    .schools-table tbody tr:hover td {
        background: var(--c-surface-2);
    }
    .schools-table tbody tr.row-dirty td {
        background: rgba(214, 40, 40, 0.06) !important;
    }
    .schools-table td.col-no {
        width: 50px;
    }
    .schools-table td.col-actions {
        width: 100px;
        white-space: nowrap;
    }
    .schools-table input {
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
    .schools-table input:focus {
        border-color: var(--c-red) !important;
    }
    #ajaxSpinner {
        display: none;
        padding: 30px;
        text-align: center;
        color: var(--c-text-faint);
    }

    /* ══════════════════════════════════════════════════════════
       LIGHT MODE
    ══════════════════════════════════════════════════════════ */
    html.pm-light .pic-section-header h2 { color: #111; }
    html.pm-light .pic-section-sub { color: #555; }

    html.pm-light .filter-card {
        background: #fff;
        border-color: #e5e7eb;
        box-shadow: 0 1px 4px rgba(0,0,0,0.06);
    }
    html.pm-light .filter-card-title { color: #888; }
    html.pm-light .pic-filter-bar label { color: #555; }
    html.pm-light .pic-filter-bar input,
    html.pm-light .pic-filter-bar select {
        background: #f9fafb;
        border-color: #d1d5db;
        color: #111;
    }
    html.pm-light .pic-filter-bar input::placeholder { color: #9ca3af; }
    html.pm-light .pic-filter-bar select option {
        background: #fff;
        color: #111;
    }

    /* Light Mode Custom Dropdown */
    html.pm-light .custom-dropdown {
        background: #fff;
        border-color: #d1d5db;
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }
    html.pm-light .dropdown-item {
        color: #4b5563;
    }
    html.pm-light .dropdown-item:hover {
        background: #f3f4f6;
        color: #111;
    }
    html.pm-light .custom-dropdown::-webkit-scrollbar-thumb { background: #d1d5db; }

    html.pm-light .pic-add-card {
        background: #fff;
        border-color: var(--c-red-border);
    }
    html.pm-light .pic-add-card h3 { color: #111; }
    html.pm-light .pic-add-form input {
        background: #f9fafb;
        border-color: #d1d5db;
        color: #111;
    }
    html.pm-light .pic-add-form input::placeholder { color: #9ca3af; }

    html.pm-light .schools-card {
        background: #fff;
        border-color: #e5e7eb;
    }

    html.pm-light .schools-save-bar {
        background: #f3f4f6;
    }
    html.pm-light .schools-save-bar .save-msg { color: #555; }
    html.pm-light .schools-save-bar .save-msg strong { color: #111; }

    html.pm-light .schools-table thead th {
        background: #374151;
        color: #f9fafb;
    }
    html.pm-light .schools-table td {
        background: #fff;
        color: #222;
        border-bottom-color: #e5e7eb;
    }
    html.pm-light .schools-table tbody tr:hover td { background: #f9fafb; }
    html.pm-light .schools-table tbody tr.row-dirty td {
        background: rgba(214, 40, 40, 0.04) !important;
    }
    html.pm-light .schools-table input {
        background: #fff !important;
        border-color: #d1d5db !important;
        color: #111 !important;
    }
    html.pm-light .schools-table input:focus {
        border-color: var(--c-red) !important;
        box-shadow: 0 0 0 2px var(--c-red-dim) !important;
    }
</style>

<form id='saveAllForm' method='POST' style='display:none;'>
    <input type='hidden' name='action' value='save_all'>
</form>
<form id='delFrm' method='POST' style='display:none;'>
    <input type='hidden' name='action' value='delete'>
    <input type='hidden' name='school_id' value=''>
</form>

<div class='pic-section-header'>
    <div>
        <h2>🏫 Pengurusan Cawangan</h2>
        <div class='pic-section-sub'>Urus semua cawangan yang berdaftar</div>
    </div>
    <button type='button' class='pm-btn pm-btn-primary' onclick="toggleAddCard()">+ Tambah Cawangan</button>
</div>

<div class='filter-card'>
    <div class='filter-card-title'>🔍 Tapis Cawangan</div>
    <div class='pic-filter-bar'>
        <div style="width: 100%;">
            <label>Cari atau Pilih Cawangan</label>
            <div class='custom-search-wrapper'>
                <input type='text' id='f_search' placeholder='Taip untuk cari...' autocomplete='off'>
                <div id='schoolDropdown' class='custom-dropdown'>
                    <?php
                    $all_schs = $conn->query("SELECT school_name FROM schools ORDER BY school_name");
                    while ($ds = $all_schs->fetch_assoc()) {
                        $sch_name = htmlspecialchars($ds['school_name'], ENT_QUOTES);
                        echo "<div class='dropdown-item' onclick=\"selectDropdownItem('$sch_name')\">$sch_name</div>";
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div id='addSchool' class='pic-add-card'>
    <h3>+ Tambah Cawangan Baru</h3>
    <form method='POST' class='pic-add-form'>
        <input type='hidden' name='action' value='add'>
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
        <button type='button' class='pm-btn pm-btn-primary btn-sm' style='padding:5px 12px;' onclick="submitSaveAll()">💾 Simpan Semua</button>
        <button type='button' class='pm-btn btn-sm' style='padding:5px 12px;background:transparent;color:var(--c-text-faint);' onclick="discardAll()">Batal</button>
    </div>

    <div class='schools-table-wrap'>
        <table class='schools-table'>
            <thead>
                <tr>
                    <th style='width:50px;text-align:center;'>No</th>
                    <th>Nama Cawangan</th>
                    <th style='width:100px;text-align:center;'>Tindakan</th>
                </tr>
            </thead>
            <tbody id='schoolTbody'>
                <tr><td colspan='3' style='text-align:center;padding:20px;color:var(--c-text-faint);'>Memuatkan...</td></tr>
            </tbody>
        </table>
        <div id='ajaxSpinner'>⏳ Mencari...</div>
    </div>
</div>

<script>
let _t = null;
let _dirtyRows = new Set();

// ── Dropdown Logic ──
const searchInput = document.getElementById('f_search');
const dropdown = document.getElementById('schoolDropdown');
const dropdownItems = dropdown.querySelectorAll('.dropdown-item');

// Show dropdown on focus
searchInput.addEventListener('focus', () => {
    dropdown.classList.add('show');
});

// Hide dropdown when clicking outside
document.addEventListener('click', (e) => {
    if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
        dropdown.classList.remove('show');
    }
});

// Filter items and run AJAX on input
searchInput.addEventListener('input', () => {
    dropdown.classList.add('show'); // Ensure open if typing
    const filter = searchInput.value.toLowerCase();
    
    dropdownItems.forEach(item => {
        if (item.textContent.toLowerCase().includes(filter)) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
    ajaxFilter();
});

// Select item from dropdown
function selectDropdownItem(value) {
    searchInput.value = value;
    dropdown.classList.remove('show');
    ajaxFilter();
}


// ── Table Data Logic ──
function ajaxFilter() {
    clearTimeout(_t);
    _t = setTimeout(_load, 400);
}

function _load() {
    const params = new URLSearchParams({ 
        ajax: '1', 
        search: document.getElementById('f_search').value
        /* Removed the sort parameter to prevent JS errors */
    });
    
    const tbody = document.getElementById('schoolTbody');
    const spinner = document.getElementById('ajaxSpinner');
    
    tbody.style.display = 'none';
    spinner.style.display = 'block';

    fetch('pic_schools.php?' + params)
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
    document.querySelectorAll('#schoolTbody input').forEach(el => {
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
        el.closest('tr')?.classList.remove('row-dirty');
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
}

document.addEventListener('DOMContentLoaded', _load);
</script>

</main>
</body>
</html>