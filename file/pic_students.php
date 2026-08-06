<?php
ini_set('display_errors', 0); ini_set('display_startup_errors', 0);
error_reporting(E_ALL); ini_set('log_errors', 1);

session_start();
include 'db.php';
$conn = getDB();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pic') {
    header("Location: login.php"); exit();
}

// ── POST actions ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_write_close();
    if ($_POST['action'] === 'add') {
        $stmt = $conn->prepare("INSERT INTO students (student_name,level_id,school_id,gender,year) VALUES (?,?,?,?,?)");
        $stmt->bind_param('siiss', $_POST['student_name'], $_POST['level_id'], $_POST['school_id'], $_POST['gender'], $_POST['year']);
        $stmt->execute(); $stmt->close();
    }
    if ($_POST['action'] === 'delete') {
        $stmt = $conn->prepare("DELETE FROM students WHERE student_id=?");
        $stmt->bind_param('i', $_POST['student_id']); $stmt->execute(); $stmt->close();
    }
    if ($_POST['action'] === 'save_all' && isset($_POST['students'])) {
        $stmt = $conn->prepare("UPDATE students SET student_name=?,level_id=?,gender=?,year=? WHERE student_id=?");
        foreach ($_POST['students'] as $id => $s) {
            $id = (int)$id;
            $stmt->bind_param('sissi', $s['student_name'], $s['level_id'], $s['gender'], $s['year'], $id);
            $stmt->execute();
        }
        $stmt->close();
    }
    header("Location: pic_students.php"); exit();
}

session_write_close();

// ── AJAX: return table rows ───────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    $search    = $_GET['search']    ?? '';
    $level     = $_GET['level']     ?? '';
    $gender    = $_GET['gender']    ?? '';
    $yearF     = $_GET['year']      ?? '';
    $school_id = $_GET['school_id'] ?? '';

    // Scope to the "Siri Aktif" picked in the sidebar — same filter pic.php's
    // Ringkasan dashboard uses (students -> level -> session -> siri).
    $active_siri = (int)($_SESSION['active_siri_id'] ?? 0);

    $whereArr = ["1=1"]; $bindTypes = ''; $bindVals = [];
    if ($search    !== '') { $whereArr[] = "st.student_name LIKE ?"; $bindTypes .= 's'; $bindVals[] = '%'.$search.'%'; }
    if ($level     !== '') { $whereArr[] = "st.level_id = ?";        $bindTypes .= 'i'; $bindVals[] = (int)$level;     }
    if ($gender    !== '') { $whereArr[] = "st.gender = ?";          $bindTypes .= 's'; $bindVals[] = $gender;         }
    if ($yearF     !== '') { $whereArr[] = "st.year = ?";            $bindTypes .= 's'; $bindVals[] = $yearF;          }
    if ($school_id !== '') { $whereArr[] = "st.school_id = ?";       $bindTypes .= 'i'; $bindVals[] = (int)$school_id; }
    if ($active_siri > 0)  { $whereArr[] = "se.siri_id = ?";         $bindTypes .= 'i'; $bindVals[] = $active_siri;    }

    $where = implode(" AND ", $whereArr);
    $stmt = $conn->prepare("SELECT st.student_id, st.student_name, st.gender, st.year, st.level_id, st.school_id, l.level_name, sc.school_name
        FROM students st
        JOIN levels l  ON st.level_id  = l.level_id
        JOIN schools sc ON st.school_id = sc.school_id
        LEFT JOIN sessions se ON l.session_id = se.session_id
        WHERE $where ORDER BY st.year DESC, sc.school_name, l.level_name, st.student_name");
    if ($bindTypes) $stmt->bind_param($bindTypes, ...$bindVals);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    // Fetched once and reused for every student row's level dropdown, instead
    // of re-querying `levels` on every iteration of the loop below.
    $all_levels = [];
    $lvls_q = $conn->query("SELECT level_id, level_name FROM levels ORDER BY level_name");
    while ($l = $lvls_q->fetch_assoc()) $all_levels[] = $l;

    $current_school = '';
    $no = 1;
    while ($st = $result->fetch_assoc()) {
        $sch = htmlspecialchars($st['school_name']);
        if ($sch !== $current_school) {
            if ($current_school !== '') echo "</tbody></table></div></div></div></div>";
            $uid = 'school_' . $st['school_id'] . '_' . rand(1000,9999);
            echo "<div class='accordion-card'>
        <div class='school-header' onclick=\"toggleBlock('$uid')\">
            <span class='arrow'>▶</span>
            <strong>$sch</strong>
        </div>
        <div id='$uid' style='display:none;'>
            <div class='accordion-body'>
                <div class='school-sticky-bar' id='stickyBar_{$st['school_id']}'>
                    <span class='sticky-msg'>Ada <strong class='dirty-count'>0</strong> perubahan belum disimpan</span>
                    <button type='button' class='pm-btn pm-btn-primary btn-sm' onclick=\"saveSchool('{$st['school_id']}')\">💾 Simpan</button>
                    <button type='button' class='pm-btn btn-sm' style='background:transparent;color:var(--c-text-faint);' onclick=\"discardSchool('{$st['school_id']}')\">Batal</button>
                </div>
                <div class='table-responsive'><table class='students-table' data-school='{$st['school_id']}'><thead>
                <tr>
                    <th style='width:40px;text-align:center;'>No</th>
                    <th>Nama</th>
                    <th>Peringkat</th>
                    <th>Jantina</th>
                    <th>Tahun</th>
                    <th style='width:80px;'></th>
                </tr>
                </thead><tbody>";
            $current_school = $sch;
            $no = 1;
        }
        $sel_opts = '';
        foreach ($all_levels as $l) {
            $sel = ($l['level_id'] == $st['level_id']) ? "selected" : "";
            $sel_opts .= "<option value='{$l['level_id']}' $sel>" . htmlspecialchars($l['level_name']) . "</option>";
        }
        echo "<tr data-id='{$st['student_id']}'>
                <td style='text-align:center;color:var(--c-text-faint);'>$no</td>
                <td><input form='masterSaveForm' name='students[{$st['student_id']}][student_name]' value='" . htmlspecialchars($st['student_name']) . "'></td>
                <td><select form='masterSaveForm' name='students[{$st['student_id']}][level_id]'>$sel_opts</select></td>
                <td><select form='masterSaveForm' name='students[{$st['student_id']}][gender]'>
                    <option value='Male'"   . ($st['gender']=='Male'   ? ' selected':'') . ">Lelaki</option>
                    <option value='Female'" . ($st['gender']=='Female' ? ' selected':'') . ">Perempuan</option>
                </select></td>
                <td><input type='number' form='masterSaveForm' name='students[{$st['student_id']}][year]' value='" . htmlspecialchars($st['year']) . "'></td>
                <td style='text-align:center;'>
                    <button type='button' class='pm-btn pm-btn-danger btn-sm'
                        onclick=\"deleteStu('{$st['student_id']}')\">Padam</button>
                </td>
              </tr>";
        $no++;
    }
    if ($current_school !== '') echo "</tbody></table></div></div></div></div>";
    if ($no === 1 && $current_school === '')
        echo "<p style='text-align:center;padding:30px;color:var(--c-text-faint);'>Tiada pelajar ditemui.</p>";
    exit();
}

$pm_page = 'students';
include 'layout.php';
?>

<style>
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
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 10px;
        align-items: end;
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
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 10px;
        align-items: end;
    }
    .pic-add-form input,
    .pic-add-form select {
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
    .pic-add-form input:focus,
    .pic-add-form select:focus {
        border-color: var(--c-red);
        box-shadow: 0 0 0 3px var(--c-red-dim);
    }
    .pic-add-form select option {
        background: var(--c-surface-2);
    }
    .pic-add-form label {
        display: block;
        font-size: 0.75rem;
        color: var(--c-text-faint);
        margin-bottom: 4px;
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 0.07em;
    }

    /* ── Accordion ── */
    .accordion-card {
        background: var(--c-surface-1);
        border: 1px solid var(--c-border);
        border-radius: 10px;
        margin-bottom: 12px;
    }
    .school-header {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 16px;
        background: var(--c-surface-1);
        border-bottom: 1px solid var(--c-border);
        cursor: pointer;
        color: var(--c-white);
        font-weight: 600;
        font-size: 0.92rem;
        transition: background .15s;
        border-radius: 10px;
    }
    .school-header:hover {
        background: var(--c-surface-2);
    }
    .school-header .arrow {
        color: var(--c-red);
        font-size: 0.9rem;
        transition: transform .2s ease;
        display: inline-block;
    }

    /* ── Accordion body ── */
    .accordion-body {
        max-height: 420px;
        overflow-y: auto;
        overflow-x: hidden;
    }
    .accordion-body .table-responsive {
        overflow-x: auto;
    }

    /* ── Save bar ── */
    .school-sticky-bar {
        display: flex;
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
    @keyframes slideDown {
        from { opacity: 0; transform: translateY(-6px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .school-sticky-bar .sticky-msg {
        font-size: 0.82rem;
        color: var(--c-text-faint);
        flex: 1;
    }
    .school-sticky-bar .sticky-msg strong {
        color: var(--c-white);
    }

    /* ── Students table ── */
    .table-responsive {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .students-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.875rem;
        min-width: 600px;
    }
    .students-table th {
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
    .students-table td {
        padding: 9px 14px;
        color: var(--c-text-muted);
        background: var(--c-surface-1);
        border-bottom: 1px solid var(--c-border);
        vertical-align: middle;
        transition: background .1s;
    }
    .students-table tbody tr:hover td {
        background: var(--c-surface-2);
    }
    .students-table tbody tr.row-dirty td {
        background: rgba(214, 40, 40, 0.06) !important;
    }
    .students-table input:not([type=hidden]),
    .students-table select {
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
    .students-table input:focus,
    .students-table select:focus {
        border-color: var(--c-red) !important;
    }
    .students-table select option {
        background: var(--c-surface-2);
        color: var(--c-white);
    }

    .btn-sm {
        font-size: 0.78rem !important;
        padding: 5px 10px !important;
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

    html.pm-light .pic-add-card {
        background: #fff;
        border-color: var(--c-red-border);
    }
    html.pm-light .pic-add-card h3 { color: #111; }
    html.pm-light .pic-add-form input,
    html.pm-light .pic-add-form select {
        background: #f9fafb;
        border-color: #d1d5db;
        color: #111;
    }
    html.pm-light .pic-add-form select option {
        background: #fff;
        color: #111;
    }
    html.pm-light .pic-add-form label { color: #555; }

    html.pm-light .accordion-card {
        background: #fff;
        border-color: #e5e7eb;
    }
    html.pm-light .school-header {
        background: #f3f4f6;
        border-bottom-color: #e5e7eb;
        color: #111;
    }
    html.pm-light .school-header:hover { background: #e9eaec; }
    html.pm-light .school-sticky-bar { background: #f3f4f6; }
    html.pm-light .school-sticky-bar .sticky-msg strong { color: #111; }

    html.pm-light .students-table th {
        background: #374151;
        color: #f9fafb;
    }
    html.pm-light .students-table td {
        background: #fff;
        color: #222;
        border-bottom-color: #e5e7eb;
    }
    html.pm-light .students-table tbody tr:hover td { background: #f9fafb; }
    html.pm-light .students-table tbody tr.row-dirty td {
        background: rgba(214, 40, 40, 0.05) !important;
    }
    html.pm-light .students-table input:not([type=hidden]),
    html.pm-light .students-table select {
        background: #fff !important;
        border-color: #d1d5db !important;
        color: #111 !important;
    }
    html.pm-light .students-table input:focus,
    html.pm-light .students-table select:focus {
        border-color: var(--c-red) !important;
        box-shadow: 0 0 0 2px var(--c-red-dim) !important;
    }
    html.pm-light .students-table select option {
        background: #fff;
        color: #111;
    }
</style>

<form id='masterSaveForm' method='POST'>
    <input type='hidden' name='action' value='save_all'>
</form>

<form id='delStuFrm' method='POST' style='display:none;'>
    <input type='hidden' name='action' value='delete'>
    <input type='hidden' name='student_id' value=''>
</form>

<div class='pic-section-header'>
    <div>
        <h2>🥋 Pengurusan Pelajar</h2>
        <div class='pic-section-sub'>Daftar dan kemaskini profil pelajar</div>
    </div>
    <button type='button' class='pm-btn pm-btn-primary' onclick="toggleBlock('addStudent')">+ Tambah Pelajar</button>
</div>

<div class='filter-card'>
    <div class='filter-card-title'>🔍 Tapis Pelajar</div>
    <div class='pic-filter-bar'>
        <div>
            <label>Cari Nama</label>
            <input id='f_search' placeholder='Cari nama pelajar...' oninput='ajaxFilter()'>
        </div>
        <div>
            <label>Tahun</label>
            <select id='f_year' onchange='ajaxFilter()'>
                <option value=''>Semua Tahun</option>
                <?php
                $years = $conn->query("SELECT DISTINCT year FROM students ORDER BY year DESC");
                while ($y = $years->fetch_assoc()) echo "<option value='{$y['year']}'>{$y['year']}</option>";
                ?>
            </select>
        </div>
        <div>
            <label>Peringkat</label>
            <select id='f_level' onchange='ajaxFilter()'>
                <option value=''>Semua Peringkat</option>
                <?php
                foreach ($all_levels as $l) echo "<option value='{$l['level_id']}'>" . htmlspecialchars($l['level_name']) . "</option>";
                ?>
            </select>
        </div>
        <div>
            <label>Jantina</label>
            <select id='f_gender' onchange='ajaxFilter()'>
                <option value=''>Semua Jantina</option>
                <option value='Male'>Lelaki</option>
                <option value='Female'>Perempuan</option>
            </select>
        </div>
    </div>
</div>

<div id='addStudent' class='pic-add-card'>
    <h3>+ Tambah Pelajar Baru</h3>
    <form method='POST' class='pic-add-form'>
        <input type='hidden' name='action' value='add'>
        <div>
            <label>Nama Penuh</label>
            <input name='student_name' placeholder='Nama Penuh' required>
        </div>
        <div>
            <label>Peringkat</label>
            <select name='level_id' required>
                <?php foreach ($all_levels as $l) echo "<option value='{$l['level_id']}'>" . htmlspecialchars($l['level_name']) . "</option>"; ?>
            </select>
        </div>
        <div>
            <label>Cawangan</label>
            <select name='school_id' required>
                <?php $schs = $conn->query("SELECT * FROM schools ORDER BY school_name"); while ($s=$schs->fetch_assoc()) echo "<option value='{$s['school_id']}'>" . htmlspecialchars($s['school_name']) . "</option>"; ?>
            </select>
        </div>
        <div>
            <label>Jantina</label>
            <select name='gender' required>
                <option value='Male'>Lelaki</option>
                <option value='Female'>Perempuan</option>
            </select>
        </div>
        <div>
            <label>Tahun</label>
            <input type='number' name='year' placeholder='Tahun' value='<?= date('Y') ?>' required>
        </div>
        <div style='display:flex;align-items:flex-end;'>
            <button class='pm-btn pm-btn-primary' style='width:100%;'>Tambah</button>
        </div>
    </form>
</div>

<div id='studentList'><p style='text-align:center;padding:30px;color:var(--c-text-faint);'>Memuatkan...</p></div>
<div id='ajaxSpinner'>⏳ Mencari...</div>

<script>
let _filterTimer = null;
let _dirtyMap = {};

function ajaxFilter() {
    clearTimeout(_filterTimer);
    _filterTimer = setTimeout(_doFilter, 400);
}

function _doFilter() {
    const params = new URLSearchParams({
        ajax:   '1',
        search: document.getElementById('f_search').value,
        year:   document.getElementById('f_year').value,
        level:  document.getElementById('f_level').value,
        gender: document.getElementById('f_gender').value,
    });
    document.getElementById('ajaxSpinner').style.display = 'block';
    document.getElementById('studentList').style.opacity = '0.4';

    fetch('pic_students.php?' + params)
    .then(r => r.text())
    .then(html => {
        document.getElementById('studentList').innerHTML = html;
        document.getElementById('studentList').style.opacity = '1';
        document.getElementById('ajaxSpinner').style.display = 'none';
        _dirtyMap = {};
        attachDirtyListeners();
        // accordions start closed so bars start hidden — nothing to do here
        // but reset dirty count display
        document.querySelectorAll('.dirty-count').forEach(el => el.textContent = '0');
    });
}

function onRowChange(e) {
    const row = e.target.closest('tr[data-id]');
    if (!row) return;
    const rowId    = row.dataset.id;
    const table    = row.closest('table[data-school]');
    if (!table) return;
    const schoolId = table.dataset.school;

    if (!_dirtyMap[schoolId]) _dirtyMap[schoolId] = new Set();

    const changed = Array.from(row.querySelectorAll('input:not([type=hidden]), select'))
        .some(el => el.dataset.orig !== undefined && el.value !== el.dataset.orig);

    if (changed) { _dirtyMap[schoolId].add(rowId); row.classList.add('row-dirty'); }
    else         { _dirtyMap[schoolId].delete(rowId); row.classList.remove('row-dirty'); }

    updateSchoolBar(schoolId);
}

function updateSchoolBar(schoolId) {
    const count   = _dirtyMap[schoolId]?.size ?? 0;
    const bar     = document.getElementById('stickyBar_' + schoolId);
    const counter = bar?.querySelector('.dirty-count');
    if (!bar) return;
    bar.style.display = 'flex';
    if (counter) counter.textContent = count;
}

function saveSchool(schoolId) {
    const form  = document.getElementById('masterSaveForm');
    const table = document.querySelector(`table[data-school="${schoolId}"]`);
    if (!table) return;

    form.querySelectorAll('.injected-input').forEach(el => el.remove());

    table.querySelectorAll('tbody tr[data-id]').forEach(row => {
        const id = row.dataset.id;
        ['student_name','level_id','gender','year'].forEach(key => {
            const el = document.querySelector(`[name="students[${id}][${key}]"]`);
            if (!el) return;
            const h = document.createElement('input');
            h.type = 'hidden';
            h.name = `students[${id}][${key}]`;
            h.value = el.value;
            h.className = 'injected-input';
            form.appendChild(h);
        });
    });

    form.submit();
}

function discardSchool(schoolId) {
    const table = document.querySelector(`table[data-school="${schoolId}"]`);
    if (!table) return;
    table.querySelectorAll('input:not([type=hidden]), select').forEach(el => {
        if (el.dataset.orig !== undefined) el.value = el.dataset.orig;
        el.closest('tr')?.classList.remove('row-dirty');
    });
    if (_dirtyMap[schoolId]) _dirtyMap[schoolId].clear();
    updateSchoolBar(schoolId);
}

function deleteStu(id) {
    if (!confirm('Padam pelajar ini?')) return;
    document.getElementById('delStuFrm').querySelector('[name=student_id]').value = id;
    document.getElementById('delStuFrm').submit();
}

function toggleBlock(id) {
    const el = document.getElementById(id);
    if (!el) return;
    const isOpen = el.style.display === 'block';
    el.style.display = isOpen ? 'none' : 'block';
    const card = el.closest('.accordion-card');
    const arrow = card?.querySelector('.school-header .arrow');
    if (arrow) arrow.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(90deg)';
}

function attachDirtyListeners() {
    document.querySelectorAll('.students-table input:not([type=hidden]), .students-table select').forEach(el => {
        if (el.dataset.orig === undefined) {
            el.dataset.orig = el.value;
            el.addEventListener('change', onRowChange);
            el.addEventListener('input',  onRowChange);
        }
    });
}

document.addEventListener('DOMContentLoaded', _doFilter);
</script>

</main>
</body>
</html>