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

<style>
/* Dashboard hero banner */
.pm-dashboard-hero {
    background: linear-gradient(135deg, var(--c-surface-2) 0%, var(--c-surface-1) 100%);
    border-left: 4px solid var(--c-red);
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    align-items: center;
    justify-content: space-between;
    padding: 24px 28px;
    margin-bottom: 28px;
}
.pm-dashboard-title {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 2rem;
    letter-spacing: 0.03em;
    margin: 0 0 6px;
}
.pm-dashboard-subtitle { color: var(--c-text-muted); font-size: 0.95rem; margin: 0; }
.pm-dashboard-actions  { display: flex; gap: 12px; flex-wrap: wrap; }

/* Stat card layout */
.pm-stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 36px;
}
.pm-stat-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}
.pm-stat-icon   { font-size: 1.3rem; color: var(--c-text-faint); }
.pm-stat-card   { padding: 20px; }
.pm-stat-card-link { text-decoration: none; transition: transform 0.2s; }
.pm-stat-card-link:hover { transform: translateY(-2px); }

/* Section heading */
.pm-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 16px;
}
.pm-section-title { font-size: 1.3rem; margin: 0; }
.pm-section-hint  { font-size: 0.85rem; color: var(--c-text-muted); font-weight: 500; }

/* Session cards */
.pm-session-grid { grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
.pm-session-card {
    text-decoration: none;
    padding: 20px;
    border-left: 3px solid var(--c-border-strong);
}
.pm-session-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 16px;
}
.pm-session-name-wrap { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.pm-session-name  { font-size: 1.05rem; color: var(--c-text); font-weight: 700; letter-spacing: 0.02em; }
.pm-session-siri  { font-size: 0.78rem; color: var(--c-text-muted); font-weight: 500; }
.pm-session-badge {
    background: var(--c-surface-0);
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 0.75rem;
    color: var(--c-text-muted);
    border: 1px solid var(--c-border);
    font-weight: 600;
    white-space: nowrap;
}
.pm-session-count {
    display: flex;
    align-items: baseline;
    gap: 8px;
    margin-bottom: 14px;
}
.pm-session-total { color: var(--c-text-muted); font-size: 0.95rem; font-weight: 500; }

@media (max-width: 480px) {
    .pm-stat-grid, .pm-session-grid { grid-template-columns: 1fr; }
}
</style>
