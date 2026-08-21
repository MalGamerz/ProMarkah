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

<?php
$pm_ma_css_v = @filemtime(__DIR__ . '/manage_attendance.css') ?: time();
?>
<link rel="stylesheet" href="manage_attendance.css?v=<?= $pm_ma_css_v ?>">

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

<?php
$pm_ma_js_v = @filemtime(__DIR__ . '/manage_attendance.js') ?: time();
?>
<script src="manage_attendance.js?v=<?= $pm_ma_js_v ?>"></script>

</main>
</body>
</html>