<?php
// ══════════════════════════════════════════════════════════════════
//  attendance_view_dashboard.php — Admin dashboard with global stats
//  and per-session breakdown cards.
// ══════════════════════════════════════════════════════════════════

$active_siri_id = (int)($_SESSION['active_siri_id'] ?? 0);

// ── Global attendance totals ──────────────────────────────────────
if ($active_siri_id > 0) {
    $total_students = $conn->query("
        SELECT COUNT(DISTINCT student_id) AS total FROM (
            SELECT st.student_id
            FROM students st
            JOIN levels l ON st.level_id = l.level_id
            JOIN sessions s ON l.session_id = s.session_id
            WHERE s.siri_id = $active_siri_id
            UNION
            SELECT gs.student_id
            FROM group_students gs
            JOIN `groups` g ON gs.group_id = g.group_id
            JOIN levels gl ON g.level_id = gl.level_id
            JOIN sessions s ON gl.session_id = s.session_id
            WHERE s.siri_id = $active_siri_id
        ) combined
    ")->fetch_assoc()['total'] ?? 0;

    $total_present = $conn->query("
        SELECT COUNT(DISTINCT a.student_id) AS total
        FROM attendance a
        JOIN sessions s ON a.session_id = s.session_id
        WHERE a.status = 'Present' AND s.siri_id = $active_siri_id
    ")->fetch_assoc()['total'] ?? 0;
} else {
    $total_students = $conn->query("SELECT COUNT(*) AS total FROM students")->fetch_assoc()['total'] ?? 0;
    $total_present  = $conn->query("SELECT COUNT(DISTINCT student_id) AS total FROM attendance WHERE status = 'Present'")->fetch_assoc()['total'] ?? 0;
}

$total_absent = $total_students - $total_present;
$pct          = $total_students > 0 ? round(($total_present / $total_students) * 100, 1) : 0;
$absent_pct   = $total_students > 0 ? round(($total_absent  / $total_students) * 100, 1) : 0;
?>

<!-- Hero banner -->
<div class="pm-card pm-dashboard-hero">
    <div>
        <h2 class="pm-dashboard-title">Papan Pemuka Kehadiran</h2>
        <p class="pm-dashboard-subtitle">Pantau dan urus statistik kehadiran pesilat secara masa nyata.</p>
    </div>
    <div class="pm-dashboard-actions">
        <a href="attendance.php?view=all_present" class="pm-btn pm-btn-ghost">📄 Senarai Penuh</a>
    </div>
</div>

<!-- Summary stat cards -->
<div class="pm-stat-grid">

    <div class="pm-stat-card">
        <div class="pm-stat-header">
            <div class="pm-stat-label">Jumlah Pesilat</div>
            <div class="pm-stat-icon">👥</div>
        </div>
        <div class="pm-stat-value"><?= $total_students ?></div>
    </div>

    <a href="attendance.php?view=all_present" class="pm-stat-card pm-stat-card-link">
        <div class="pm-stat-header">
            <div class="pm-stat-label">Hadir</div>
            <div class="pm-stat-icon" style="color:#4ADE80;">✔</div>
        </div>
        <div class="pm-stat-value" style="color:#4ADE80;"><?= $total_present ?></div>
        <div class="pm-progress-bg"><div class="pm-progress-fill" style="width:<?= $pct ?>%;background:#4ADE80;"></div></div>
    </a>

    <div class="pm-stat-card">
        <div class="pm-stat-header">
            <div class="pm-stat-label">Tidak Hadir</div>
            <div class="pm-stat-icon" style="color:#F87171;">✖</div>
        </div>
        <div class="pm-stat-value" style="color:#F87171;"><?= $total_absent ?></div>
        <div class="pm-progress-bg"><div class="pm-progress-fill" style="width:<?= $absent_pct ?>%;background:#F87171;"></div></div>
    </div>

    <div class="pm-stat-card">
        <div class="pm-stat-header">
            <div class="pm-stat-label">Kadar Kehadiran</div>
            <div class="pm-stat-icon">📈</div>
        </div>
        <div class="pm-stat-value"><?= $pct ?>%</div>
    </div>

</div>

<!-- Per-session breakdown -->
<div class="pm-section-header">
    <h3 class="pm-section-title">Pecahan Mengikut Sidang</h3>
    <span class="pm-section-hint">Pilih sidang di bawah untuk mengurus kehadiran</span>
</div>

<div class="pm-stat-grid pm-session-grid">
<?php
$siri_filter   = $active_siri_id > 0 ? "WHERE s.siri_id = $active_siri_id" : '';
$session_stats = $conn->query("
    SELECT s.session_id, s.session_name, si.siri_name,
           COUNT(DISTINCT all_stu.student_id) AS total_s,
           COUNT(DISTINCT CASE WHEN a.status = 'Present' THEN a.student_id END) AS present_s
    FROM sessions s
    LEFT JOIN siri si ON s.siri_id = si.siri_id
    LEFT JOIN (
        SELECT st.student_id, l.session_id
        FROM students st
        JOIN levels l ON st.level_id = l.level_id
        UNION
        SELECT gs.student_id, gl.session_id
        FROM group_students gs
        JOIN `groups` g ON gs.group_id = g.group_id
        JOIN levels gl ON g.level_id = gl.level_id
    ) all_stu ON all_stu.session_id = s.session_id
    LEFT JOIN attendance a ON a.student_id = all_stu.student_id AND a.session_id = s.session_id
    $siri_filter
    GROUP BY s.session_id, s.session_name, si.siri_name
    ORDER BY s.session_name ASC
");

while ($s = $session_stats->fetch_assoc()):
    $sid         = (int)$s['session_id'];
    $total_s     = (int)$s['total_s'];
    $present_s   = (int)$s['present_s'];
    $session_pct = $total_s > 0 ? round(($present_s / $total_s) * 100, 1) : 0;
?>
    <a href="attendance.php?session_id=<?= $sid ?>" class="pm-stat-card pm-session-card">
        <div class="pm-session-card-header">
            <div class="pm-session-name-wrap">
                <div class="pm-stat-label pm-session-name"><?= htmlspecialchars($s['session_name']) ?></div>
                <?php if (!empty($s['siri_name'])): ?>
                    <div class="pm-session-siri">🗂 <?= htmlspecialchars($s['siri_name']) ?></div>
                <?php endif; ?>
            </div>
            <div class="pm-session-badge">Urus ➔</div>
        </div>
        <div class="pm-session-count">
            <div class="pm-stat-value"><?= $present_s ?></div>
            <div class="pm-session-total">/ <?= $total_s ?> pesilat</div>
        </div>
        <div class="pm-progress-bg"><div class="pm-progress-fill" style="width:<?= $session_pct ?>%;"></div></div>
    </a>
<?php endwhile; ?>
</div>

<?php
$pm_avd_css_v = @filemtime(__DIR__ . '/attendance_view_dashboard.css') ?: time();
?>
<link rel="stylesheet" href="attendance_view_dashboard.css?v=<?= $pm_avd_css_v ?>">
