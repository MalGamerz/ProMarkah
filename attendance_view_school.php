<?php
// ══════════════════════════════════════════════════════════════════
//  attendance_view_school.php — Admin: student attendance table
//  for a specific school + session with live toggle and counters.
// ══════════════════════════════════════════════════════════════════

$school_id   = (int)$_GET['school_id'];
$session_id  = (int)($_GET['session_id'] ?? 0);
$school_name = getSchoolName($conn, $school_id);

// ── Attendance counts ─────────────────────────────────────────────
$total_q = $conn->prepare("SELECT COUNT(*) AS c FROM students WHERE school_id = ?");
$total_q->bind_param("i", $school_id);
$total_q->execute();
$total_c = (int)$total_q->get_result()->fetch_assoc()['c'];

$present_q = $conn->prepare("
    SELECT COUNT(DISTINCT a.student_id) AS c
    FROM attendance a
    JOIN students st ON a.student_id = st.student_id
    WHERE st.school_id = ? AND a.status = 'Present' AND a.session_id = ?
");
$present_q->bind_param("ii", $school_id, $session_id);
$present_q->execute();
$present_c = (int)$present_q->get_result()->fetch_assoc()['c'];
$absent_c  = $total_c - $present_c;
$pct_s     = $total_c > 0 ? round(($present_c / $total_c) * 100) : 0;

// ── Student list ──────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT st.*,
           (SELECT status FROM attendance
            WHERE student_id = st.student_id AND session_id = ? AND status = 'Present'
            LIMIT 1) AS is_present
    FROM students st
    WHERE st.school_id = ?
    ORDER BY st.student_name ASC
");
$stmt->bind_param("ii", $session_id, $school_id);
$stmt->execute();
$students = $stmt->get_result();

// Build student array for JS dropdown
$all_students = [];
while ($row = $students->fetch_assoc()) {
    $all_students[] = $row;
}
?>

<div class="avs-topbar">
    <a href="attendance.php?session_id=<?= $session_id ?>" class="avs-back-btn">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M19 12H5M5 12l7 7M5 12l7-7"/></svg>
        Kembali
    </a>
    <div class="avs-school-label">
        <span class="avs-school-dot"></span>
        <?= htmlspecialchars($school_name) ?>
    </div>
</div>

<div class="avs-stats-bar">
    <div class="avs-stat avs-stat-present">
        <div class="avs-stat-num" id="livePresent"><?= $present_c ?></div>
        <div class="avs-stat-lbl">Hadir</div>
    </div>
    <div class="avs-stat-divider"></div>
    <div class="avs-stat avs-stat-absent">
        <div class="avs-stat-num" id="liveAbsent"><?= $absent_c ?></div>
        <div class="avs-stat-lbl">Tidak Hadir</div>
    </div>
    <div class="avs-stat-divider"></div>
    <div class="avs-stat">
        <div class="avs-stat-num" id="livePct"><?= $pct_s ?>%</div>
        <div class="avs-stat-lbl">Kehadiran</div>
    </div>
    <div class="avs-progress-wrap">
        <div class="avs-progress-track">
            <div class="avs-progress-fill" id="liveBar" style="width:<?= $pct_s ?>%"></div>
        </div>
        <span class="avs-progress-label" id="liveRatio"><?= $present_c ?> / <?= $total_c ?> pesilat</span>
    </div>
</div>

<div class="avs-toolbar">
    <div class="avs-combo-wrap" id="comboWrap">
        <svg class="avs-search-icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        <input type="text" id="searchInput" class="avs-search" placeholder="Cari nama pesilat..." autocomplete="off">
        <button class="avs-search-clear" id="searchClear" onclick="clearSearch()">✕</button>
        <svg class="avs-chevron-icon" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" onclick="toggleDropdownChevron(event)"><path d="m6 9 6 6 6-6"/></svg>
        <div class="avs-dropdown" id="searchDropdown"></div>
    </div>

    <div class="avs-toolbar-right">
        <div class="avs-filter-pills">
            <button class="avs-pill avs-pill-active" data-filter="all"     onclick="filterTable('all',this)">Semua <span class="avs-pill-count" id="pillAll"><?= $total_c ?></span></button>
            <button class="avs-pill"                 data-filter="present" onclick="filterTable('present',this)">Hadir <span class="avs-pill-count avs-pill-count-green" id="pillPresent"><?= $present_c ?></span></button>
            <button class="avs-pill"                 data-filter="absent"  onclick="filterTable('absent',this)">Tidak Hadir <span class="avs-pill-count avs-pill-count-red" id="pillAbsent"><?= $absent_c ?></span></button>
        </div>
    </div>
</div>

<div class="avs-card">
    <div class="avs-list" id="studentList">
    <?php foreach ($all_students as $row):
        $is_present = !empty($row['is_present']);
        $sid = (int)$row['student_id'];
        $words    = array_filter(explode(' ', trim($row['student_name'])));
        $initials = strtoupper(implode('', array_map(fn($w) => $w[0], array_slice(array_values($words), 0, 2))));
    ?>
        <div class="avs-row <?= $is_present ? 'is-present' : 'is-absent' ?>"
             data-id="<?= $sid ?>"
             data-status="<?= $is_present ? 'present' : 'absent' ?>"
             data-name="<?= htmlspecialchars(strtolower($row['student_name'])) ?>">
            <div class="avs-avatar <?= $is_present ? 'avs-avatar-present' : '' ?>"><?= htmlspecialchars($initials) ?></div>
            <div class="avs-info">
                <div class="avs-name"><?= htmlspecialchars($row['student_name']) ?></div>
                <div class="avs-meta"><?= $row['gender'] === 'Male' ? 'Lelaki' : ($row['gender'] === 'Female' ? 'Perempuan' : '') ?></div>
            </div>
            <div class="avs-status-badge <?= $is_present ? 'avs-badge-present' : 'avs-badge-absent' ?>">
                <?= $is_present ? '✔ Hadir' : 'Tidak Hadir' ?>
            </div>
            <button class="avs-toggle-btn <?= $is_present ? 'avs-toggle-undo' : 'avs-toggle-mark' ?>"
                    onclick="toggleAttendance(<?= $sid ?>, <?= $session_id ?>, this)"
                    data-present="<?= $is_present ? '1' : '0' ?>">
                <?php if ($is_present): ?>
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg> Tanda Tidak Hadir
                <?php else: ?>
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg> Tanda Hadir
                <?php endif; ?>
            </button>
        </div>
    <?php endforeach; ?>
    <?php if (empty($all_students)): ?>
        <div class="avs-empty">Tiada pelajar didaftarkan untuk cawangan ini.</div>
    <?php endif; ?>
    </div>

    <div class="vm-pagination" id="paginationWrapper">
        <div class="vm-page-info" id="pageInfo">—</div>
        <div class="vm-page-btns" id="paginationButtons"></div>
    </div>
</div>

<span id="totalStudents" style="display:none"><?= $total_c ?></span>
<?php
// Cache-bust the same way layout.php/judge.php do for their own assets.
$pm_avs_css_v = @filemtime(__DIR__ . '/attendance_view_school.css') ?: time();
$pm_avs_js_v  = @filemtime(__DIR__ . '/attendance_view_school.js') ?: time();
?>
<link rel="stylesheet" href="attendance_view_school.css?v=<?= $pm_avs_css_v ?>">
<script src="attendance_view_school.js?v=<?= $pm_avs_js_v ?>"></script>
