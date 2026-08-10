<?php
session_start();
require __DIR__ . '/auth_check.php';
include 'db.php';
$conn = getDB();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pic') {
    header("Location: login.php");
    exit();
}

// ── AJAX: fetch levels for a session ─────────────────────────────
if (isset($_GET['ajax_levels'])) {
    header('Content-Type: application/json');
    $sid = intval($_GET['session_id']);
    $rows = [];
    $stmt = $conn->prepare("SELECT level_id, level_name FROM levels WHERE session_id = ? ORDER BY level_name ASC");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    echo json_encode($rows);
    exit();
}

// ── AJAX: fetch groups for a session + level ──────────────────────
if (isset($_GET['ajax_groups'])) {
    header('Content-Type: application/json');
    $lid = intval($_GET['level_id']);
    $rows = [];
    // No separate session_id check needed — level_id already pins the
    // session (a level belongs to exactly one session).
    $stmt = $conn->prepare("SELECT group_id, group_name FROM `groups` WHERE level_id = ? ORDER BY group_name ASC");
    $stmt->bind_param("i", $lid);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    echo json_encode($rows);
    exit();
}

// ── AJAX: fetch student list for a group ─────────────────────────
if (isset($_GET['ajax_students'])) {
    header('Content-Type: application/json');
    $gid = intval($_GET['group_id']);
    $rows = [];
    $stmt = $conn->prepare("
        SELECT st.student_id, st.student_name, sc.school_name,
        (SELECT status FROM attendance WHERE student_id = st.student_id ORDER BY attendance_id DESC LIMIT 1) AS last_status
        FROM group_students gs
        JOIN students st ON gs.student_id = st.student_id
        LEFT JOIN schools sc ON st.school_id = sc.school_id
        WHERE gs.group_id = ?
        ORDER BY st.student_name ASC
    ");
    $stmt->bind_param("i", $gid);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    echo json_encode($rows);
    exit();
}

$pm_page = 'attendance';
include 'layout.php';

// Pre-load siri list + all sessions (tagged with their siri_id) for the dropdowns
$active_siri_id = (int)($_SESSION['active_siri_id'] ?? 0);

$siri_list = [];
$r = $conn->query("SELECT siri_id, siri_name, siri_year FROM siri ORDER BY siri_year DESC, siri_name ASC");
while ($row = $r->fetch_assoc()) $siri_list[] = $row;

$sessions = [];
$r = $conn->query("SELECT session_id, session_name, siri_id FROM sessions ORDER BY session_name ASC");
while ($row = $r->fetch_assoc()) $sessions[] = $row;
?>

<style>
/* ── CONTROL BAR: theme-aware ── */
.att-control-bar {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
    background: var(--c-surface-1);
    padding: 16px 20px;
    border-radius: var(--radius-lg);
    border: 1px solid var(--c-border);
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
}

/* ── TOGGLE PILLS ── */
.att-toggle {
    display: inline-flex;
    gap: 4px;
    background: var(--c-surface-0);
    padding: 4px;
    border-radius: 8px;
    border: 1px solid var(--c-border-strong);
}
.att-toggle label { cursor: pointer; margin: 0; }
.att-toggle input { display: none; }
.att-toggle span {
    display: inline-block;
    padding: 6px 16px;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--c-text-faint);
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
.att-toggle input[value="Present"]:checked + span {
    background: rgba(74, 222, 128, 0.15);
    color: #4ade80;
    box-shadow: inset 0 0 0 1px rgba(74, 222, 128, 0.4);
}
.att-toggle input[value="Absent"]:checked + span {
    background: var(--c-red-dim);
    color: var(--c-red);
    box-shadow: inset 0 0 0 1px var(--c-red-border);
}
.att-toggle label:hover input:not(:checked) + span {
    color: var(--c-text);
}

/* ── SEARCH INPUT: theme-aware ── */
.att-search-wrapper { position: relative; width: 100%; max-width: 320px; }
.att-search-wrapper svg { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--c-text-faint); pointer-events: none; }
.att-search-wrapper input {
    width: 100%;
    background: var(--c-surface-2);
    border: 1px solid var(--c-border-strong);
    color: var(--c-text);
    padding: 10px 14px 10px 38px;
    border-radius: 6px;
    font-size: 0.85rem;
    outline: none;
    transition: all 0.2s;
}
.att-search-wrapper input::placeholder { color: var(--c-text-faint); }
.att-search-wrapper input:focus {
    border-color: var(--c-red);
    background: var(--c-surface-1);
    box-shadow: 0 0 0 3px var(--c-red-dim);
}

/* ── TABLE HEADER: theme-aware, matches canonical .pm-table th ── */
.att-table th {
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
    white-space: nowrap;
}
.att-table td { vertical-align: middle !important; border-bottom: 1px solid var(--c-border); }
.att-table tbody tr { transition: background 0.15s ease; }
.att-table tbody tr:hover td { background: var(--c-surface-2) !important; }

/* ── TABLE CELL TEXT: theme-aware ── */
.att-student-name { font-weight: 600; color: var(--c-text); font-size: 0.9rem; }
.att-school-name  { color: var(--c-text-muted); font-size: 0.85rem; }

/* ── CARD HEADER: theme-aware ── */
.att-card-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--c-border-strong);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    background: var(--c-surface-1);
}
.att-card-header h3 {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 1.2rem;
    color: var(--c-text);
    letter-spacing: 0.05em;
    margin: 0;
}

/* ── STATUS BADGES ── */
.pm-badge-present { background: rgba(74,222,128,0.15); color: #4ade80; border: 1px solid rgba(74,222,128,0.3); }
.pm-badge-absent  { background: var(--c-red-dim); color: var(--c-red); border: 1px solid var(--c-red-border); }
.pm-badge-null    { background: transparent; color: var(--c-text-faint); border: 1px dashed var(--c-border-strong); }

/* ── LOADING SPINNER ── */
.att-spinner {
    display: flex; align-items: center; justify-content: center;
    padding: 40px; gap: 12px; color: var(--c-text-muted); font-size: 0.9rem;
}
.att-spinner svg { animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <div>
        <h2 style="font-family:'Bebas Neue',sans-serif; font-size:1.8rem; color:var(--c-text); letter-spacing:0.05em; margin:0;">📋 Kawalan Kehadiran</h2>
        <div style="color:var(--c-text-faint); font-size:0.85rem; margin-top:2px;">Pilih kumpulan untuk merekod kehadiran pesilat.</div>
    </div>
</div>

<div class="att-control-bar">
    <?php if (!empty($siri_list)): ?>
    <div id="siri_container" <?= $active_siri_id > 0 ? 'style="display:none;"' : '' ?>>
        <label class="pm-form-label">1. Siri</label>
        <select id="sel_siri" class="pm-select" onchange="onSiriChange()">
            <option value="0" <?= $active_siri_id === 0 ? 'selected' : '' ?>>-- Semua Siri --</option>
            <?php foreach ($siri_list as $si): ?>
            <option value="<?= $si['siri_id'] ?>" <?= $active_siri_id === (int)$si['siri_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($si['siri_name'] . ' ' . $si['siri_year']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div>
        <label id="lbl_session" class="pm-form-label">2. Jadual Sidang</label>
        <select id="sel_session" class="pm-select" onchange="onSessionChange()" <?= $active_siri_id === 0 ? 'disabled' : '' ?>>
            <option value=""><?= $active_siri_id === 0 ? '-- Pilih Siri dahulu --' : '-- Pilih Sidang --' ?></option>
            <?php foreach ($sessions as $s): ?>
            <option value="<?= $s['session_id'] ?>" data-siri="<?= (int)$s['siri_id'] ?>"
                style="<?= ($active_siri_id > 0 && (int)$s['siri_id'] !== $active_siri_id) ? 'display:none;' : '' ?>">
                <?= htmlspecialchars($s['session_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label id="lbl_level" class="pm-form-label">3. Struktur Peringkat</label>
        <select id="sel_level" class="pm-select" onchange="onLevelChange()" disabled>
            <option value="">-- Pilih Peringkat --</option>
        </select>
    </div>
    <div>
        <label id="lbl_group" class="pm-form-label">4. Kumpulan Juri</label>
        <select id="sel_group" class="pm-select" onchange="onGroupChange()" disabled>
            <option value="">-- Pilih Kumpulan --</option>
        </select>
    </div>
</div>

<div id="studentSection" style="display:none;">
    <div class="pm-card" style="padding:0; overflow:hidden; border-color:var(--c-border-strong);">
        <div class="att-card-header">
            <h3>Senarai Pesilat</h3>
            <div class="att-search-wrapper">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="searchInput" placeholder="Cari nama atau cawangan...">
            </div>
        </div>
        <div class="pm-table-wrap" style="border:none; border-radius:0;">
            <table class="pm-table att-table">
                <thead>
                    <tr>
                        <th>Nama Pesilat</th>
                        <th>Cawangan</th>
                        <th style="text-align:center; width:140px;">Status Semasa</th>
                        <th style="text-align:right; width:220px;">Tindakan Kehadiran</th>
                    </tr>
                </thead>
                <tbody id="studentTbody">
                    <tr><td colspan="4"><div class="att-spinner">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                        Memuatkan...
                    </div></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="successToast" style="position:fixed; bottom:30px; right:30px; background:var(--c-surface-1); color:#4ade80; border:1px solid var(--c-border-strong); border-left:3px solid #4ade80; padding:12px 20px; border-radius:8px; font-size:0.9rem; font-weight:600; box-shadow:0 8px 25px rgba(0,0,0,0.3); opacity:0; transform:translateY(20px); transition:all 0.3s cubic-bezier(0.4,0,0.2,1); z-index:9999; display:flex; align-items:center; gap:8px; pointer-events:none;">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
    Kehadiran Disimpan
</div>

<script>
let currentGroupId = 0;
let toastTimeout;

// ── Dropdown cascade ──────────────────────────────────────────────
function updateDropdownNumbers(siriHidden) {
    const lblSession = document.getElementById('lbl_session');
    const lblLevel   = document.getElementById('lbl_level');
    const lblGroup   = document.getElementById('lbl_group');
    if (lblSession) lblSession.textContent = siriHidden ? '1. Jadual Sidang'       : '2. Jadual Sidang';
    if (lblLevel)   lblLevel.textContent   = siriHidden ? '2. Struktur Peringkat'  : '3. Struktur Peringkat';
    if (lblGroup)   lblGroup.textContent   = siriHidden ? '3. Kumpulan Juri'       : '4. Kumpulan Juri';
}

function onSiriChange() {
    const siriId  = document.getElementById('sel_siri').value;
    const sessSel = document.getElementById('sel_session');

    if (siriId === '0') {
        // No siri chosen — disable session dropdown and reset cascade
        sessSel.disabled = true;
        sessSel.options[0].textContent = '-- Pilih Siri dahulu --';
        sessSel.value = '';
        onSessionChange();
        return;
    }

    // Filter sessions to the chosen siri
    Array.from(sessSel.options).forEach(opt => {
        if (!opt.value) return;
        opt.style.display = opt.dataset.siri === siriId ? '' : 'none';
    });

    // Reset session if the currently selected one no longer matches
    const selectedOpt = sessSel.options[sessSel.selectedIndex];
    if (selectedOpt && selectedOpt.value && selectedOpt.style.display === 'none') {
        sessSel.value = '';
        onSessionChange();
    }

    sessSel.options[0].textContent = '-- Pilih Sidang --';
    sessSel.disabled = false;
}

// On page load: if siri was pre-selected via sidebar, container is already hidden by PHP.
// Just sync the numbering to match whichever state PHP rendered.
document.addEventListener('DOMContentLoaded', function () {
    const container = document.getElementById('siri_container');
    const hidden    = container && container.style.display === 'none';
    updateDropdownNumbers(hidden);
});

function onSessionChange() {
    const sid = document.getElementById('sel_session').value;
    const lvlSel = document.getElementById('sel_level');
    const grpSel = document.getElementById('sel_group');

    lvlSel.innerHTML = '<option value="">-- Pilih Peringkat --</option>';
    grpSel.innerHTML = '<option value="">-- Pilih Kumpulan --</option>';
    lvlSel.disabled = true;
    grpSel.disabled = true;
    hideStudents();

    if (!sid) return;

    lvlSel.innerHTML = '<option value="">Memuatkan...</option>';
    pmFetch(`manage_attendance.php?ajax_levels=1&session_id=${sid}`)
        .then(r => r.json())
        .then(data => {
            lvlSel.innerHTML = '<option value="">-- Pilih Peringkat --</option>';
            data.forEach(l => {
                lvlSel.innerHTML += `<option value="${l.level_id}">${escHtml(l.level_name)}</option>`;
            });
            lvlSel.disabled = false;
        })
        .catch(() => {});
}

function onLevelChange() {
    const sid = document.getElementById('sel_session').value;
    const lid = document.getElementById('sel_level').value;
    const grpSel = document.getElementById('sel_group');

    grpSel.innerHTML = '<option value="">-- Pilih Kumpulan --</option>';
    grpSel.disabled = true;
    hideStudents();

    if (!lid) return;

    grpSel.innerHTML = '<option value="">Memuatkan...</option>';
    pmFetch(`manage_attendance.php?ajax_groups=1&session_id=${sid}&level_id=${lid}`)
        .then(r => r.json())
        .then(data => {
            grpSel.innerHTML = '<option value="">-- Pilih Kumpulan --</option>';
            data.forEach(g => {
                grpSel.innerHTML += `<option value="${g.group_id}">${escHtml(g.group_name)}</option>`;
            });
            grpSel.disabled = false;
        })
        .catch(() => {});
}

function onGroupChange() {
    const gid = document.getElementById('sel_group').value;
    if (!gid) { hideStudents(); return; }
    currentGroupId = parseInt(gid);
    loadStudents(gid);
}

function hideStudents() {
    document.getElementById('studentSection').style.display = 'none';
    currentGroupId = 0;
}

// ── Student list render ───────────────────────────────────────────
function loadStudents(gid) {
    const section = document.getElementById('studentSection');
    const tbody = document.getElementById('studentTbody');
    section.style.display = 'block';
    tbody.innerHTML = `<tr><td colspan="4"><div class="att-spinner">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
        Memuatkan data pesilat...
    </div></td></tr>`;

    pmFetch(`manage_attendance.php?ajax_students=1&group_id=${gid}`)
        .then(r => r.json())
        .then(students => {
            if (students.length === 0) {
                tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;padding:30px;color:var(--c-text-faint);">Tiada pesilat dalam kumpulan ini.</td></tr>`;
                return;
            }
            let html = '';
            students.forEach(s => {
                const sid   = s.student_id;
                // No recorded status yet defaults to Present/Hadir — attendance
                // is assumed unless explicitly marked absent.
                const status = s.last_status || 'Present';
                const badgeClass = status === 'Absent' ? 'pm-badge-absent' : 'pm-badge-present';
                const statusText = status === 'Absent' ? 'Tidak Hadir' : 'Hadir';
                const chkPresent = status === 'Present' ? 'checked' : '';
                const chkAbsent  = status === 'Absent'  ? 'checked' : '';
                const name   = escHtml(s.student_name);
                const school = escHtml(s.school_name || '-');
                html += `
                <tr class="att-row" data-student="${name.toLowerCase()}" data-school="${school.toLowerCase()}">
                    <td><span class="att-student-name">${name}</span></td>
                    <td><span class="att-school-name">${school}</span></td>
                    <td style="text-align:center;">
                        <span id="badge_${sid}" class="pm-badge ${badgeClass}">${statusText}</span>
                    </td>
                    <td style="text-align:right;">
                        <div class="att-toggle">
                            <label>
                                <input type="radio" name="att_${sid}" value="Present" ${chkPresent} onclick="saveAttendance(${sid},'Present')">
                                <span>Hadir</span>
                            </label>
                            <label>
                                <input type="radio" name="att_${sid}" value="Absent" ${chkAbsent} onclick="saveAttendance(${sid},'Absent')">
                                <span>Tiada</span>
                            </label>
                        </div>
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html;
        })
        .catch(() => {});
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Save attendance ───────────────────────────────────────────────
function saveAttendance(student_id, status) {
    const session_id = document.getElementById('sel_session').value;
    const level_id   = document.getElementById('sel_level').value;
    const group_id   = document.getElementById('sel_group').value;

    pmFetch("save_attendance.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `student_id=${student_id}&session_id=${session_id}&level_id=${level_id}&group_id=${group_id}&status=${status}`
    }).then(() => {
        showSuccess();
        const badge = document.getElementById("badge_" + student_id);
        badge.className = "pm-badge";
        if (status === "Present") { badge.classList.add("pm-badge-present"); badge.innerHTML = "Hadir"; }
        else                       { badge.classList.add("pm-badge-absent");  badge.innerHTML = "Tidak Hadir"; }
    }).catch(() => {});
}

function showSuccess() {
    const toast = document.getElementById("successToast");
    toast.style.opacity = "1";
    toast.style.transform = "translateY(0px)";
    clearTimeout(toastTimeout);
    toastTimeout = setTimeout(() => {
        toast.style.opacity = "0";
        toast.style.transform = "translateY(20px)";
    }, 2000);
}

// ── Search ────────────────────────────────────────────────────────
document.getElementById("searchInput")?.addEventListener("keyup", function() {
    const kw = this.value.toLowerCase();
    document.querySelectorAll("#studentTbody tr.att-row").forEach(row => {
        row.style.display = (row.dataset.student.includes(kw) || row.dataset.school.includes(kw)) ? "" : "none";
    });
});
</script>

</main>
</body>
</html>