<?php
// ── admin_logs.php — ProMarkah Admin — Audit Logs & System Stats
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Strict');
session_start();
require __DIR__ . '/auth_check.php';

header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Cache-Control: no-store");

include 'db.php';
$conn = getDB();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ─────────────────────────────────────────────────────────────────────────────
// CLEAR LOGS
// ─────────────────────────────────────────────────────────────────────────────
$flash = '';
$flash_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_logs') {
    if (!isset($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token'])) {
        $flash = 'Security token mismatch.'; $flash_type = 'error';
    } else {
        $conn->query("DELETE FROM audit_log");
        $flash = 'Audit log cleared.';
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// DATA
// ─────────────────────────────────────────────────────────────────────────────

// Audit log — join username
$log_limit  = 100;
$log_result = $conn->query(
    "SELECT al.id, u.username, al.role, al.ip, al.login_time
     FROM audit_log al
     LEFT JOIN users u ON al.user_id = u.id
     ORDER BY al.login_time DESC
     LIMIT {$log_limit}"
);
$logs = [];
while ($row = $log_result->fetch_assoc()) $logs[] = $row;

// Total log entries
$total_log_count = (int)$conn->query("SELECT COUNT(*) c FROM audit_log")->fetch_assoc()['c'];

// Login frequency per role (last 30 days)
$freq_result = $conn->query(
    "SELECT role, COUNT(*) cnt
     FROM audit_log
     WHERE login_time >= NOW() - INTERVAL 30 DAY
     GROUP BY role
     ORDER BY cnt DESC"
);
$freq = [];
while ($f = $freq_result->fetch_assoc()) $freq[] = $f;
$freq_max = $freq ? max(array_column($freq, 'cnt')) : 1;

// System counts
function c($conn, $t) { $r = $conn->query("SELECT COUNT(*) c FROM `{$t}`"); return $r ? (int)$r->fetch_assoc()['c'] : 0; }
$sys = [
    'siri'       => c($conn, 'siri'),
    'sessions'   => c($conn, 'sessions'),
    'schools'    => c($conn, 'schools'),
    'students'   => c($conn, 'students'),
    'tests'      => c($conn, 'tests'),
    'criteria'   => c($conn, 'criteria'),
    'groups'     => c($conn, 'groups'),
    'scores'     => c($conn, 'scores'),
    'attendance' => c($conn, 'attendance'),
    'judges'     => c($conn, 'judges'),
    'users'      => c($conn, 'users') - 1, // exclude admin
    'notifs'     => c($conn, 'notifications'),
];

// Top scorers (most marks submitted)
$top_result = $conn->query(
    "SELECT st.student_name, sc.school_name, COUNT(s.score_id) total_marks, SUM(s.mark) total_score
     FROM scores s
     LEFT JOIN students st ON s.student_id = st.student_id
     LEFT JOIN schools sc ON st.school_id = sc.school_id
     WHERE s.submitted = 1
     GROUP BY s.student_id
     ORDER BY total_score DESC
     LIMIT 5"
);
$top_students = [];
while ($t = $top_result->fetch_assoc()) $top_students[] = $t;

$pm_page = 'admin_logs';
include 'layout.php';
?>

<?php
$pm_alog_css_v = @filemtime(__DIR__ . '/admin_logs.css') ?: time();
?>
<link rel="stylesheet" href="admin_logs.css?v=<?= $pm_alog_css_v ?>">

<div class="ap-wrap">

    <div class="ap-page-title">
        <h1>Logs &amp; Stats</h1>
        <p>Login audit trail and system-wide record counts.</p>
    </div>

    <nav class="ap-tabnav">
        <a class="ap-tab" href="admin.php">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Users
        </a>
        <a class="ap-tab" href="admin_data.php">
            <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Data &amp; Export
        </a>
        <a class="ap-tab active" href="admin_logs.php">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
            Logs &amp; Stats
        </a>
    </nav>

    <?php if ($flash): ?>
        <div class="ap-flash <?= $flash_type ?>"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <!-- System counts -->
    <div class="ap-card">
        <div class="ap-card-head">
            <div class="ap-card-head-left">
                <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                <h2>System Overview</h2>
            </div>
        </div>
        <div class="ap-card-body">
            <div class="sys-grid">
                <div class="sys-stat"><div class="sys-stat-num"><?= $sys['siri'] ?></div><div class="sys-stat-label">Siri</div></div>
                <div class="sys-stat"><div class="sys-stat-num"><?= $sys['sessions'] ?></div><div class="sys-stat-label">Sessions</div></div>
                <div class="sys-stat"><div class="sys-stat-num"><?= $sys['schools'] ?></div><div class="sys-stat-label">Schools</div></div>
                <div class="sys-stat"><div class="sys-stat-num"><?= $sys['students'] ?></div><div class="sys-stat-label">Students</div></div>
                <div class="sys-stat"><div class="sys-stat-num"><?= $sys['tests'] ?></div><div class="sys-stat-label">Tests</div></div>
                <div class="sys-stat"><div class="sys-stat-num"><?= $sys['criteria'] ?></div><div class="sys-stat-label">Criteria</div></div>
                <div class="sys-stat"><div class="sys-stat-num"><?= $sys['groups'] ?></div><div class="sys-stat-label">Judge Groups</div></div>
                <div class="sys-stat"><div class="sys-stat-num"><?= number_format($sys['scores']) ?></div><div class="sys-stat-label">Score Records</div></div>
                <div class="sys-stat"><div class="sys-stat-num"><?= number_format($sys['attendance']) ?></div><div class="sys-stat-label">Attendance Records</div></div>
                <div class="sys-stat"><div class="sys-stat-num"><?= $sys['judges'] ?></div><div class="sys-stat-label">Judges (pin)</div></div>
                <div class="sys-stat"><div class="sys-stat-num"><?= $sys['users'] ?></div><div class="sys-stat-label">User Accounts</div></div>
                <div class="sys-stat"><div class="sys-stat-num"><?= $sys['notifs'] ?></div><div class="sys-stat-label">Notifications</div></div>
            </div>
        </div>
    </div>

    <div class="two-col">

        <!-- Login frequency -->
        <div class="ap-card">
            <div class="ap-card-head">
                <div class="ap-card-head-left">
                    <svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    <h2>Logins (Last 30 Days)</h2>
                </div>
            </div>
            <div class="ap-card-body">
                <?php if ($freq): ?>
                    <div class="freq-list">
                        <?php foreach ($freq as $f): ?>
                            <div class="freq-row">
                                <span class="freq-label"><?= htmlspecialchars($f['role']) ?></span>
                                <div class="freq-bar-wrap">
                                    <div class="freq-bar" style="width:<?= round(($f['cnt'] / $freq_max) * 100) ?>%"></div>
                                </div>
                                <span class="freq-count"><?= $f['cnt'] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="font-size:var(--text-sm); color:var(--c-text-faint); margin:0;">No logins recorded in the last 30 days.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Top students by score -->
        <div class="ap-card">
            <div class="ap-card-head">
                <div class="ap-card-head-left">
                    <svg viewBox="0 0 24 24"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2z"/></svg>
                    <h2>Top 5 by Score</h2>
                </div>
            </div>
            <div style="overflow-x:auto;">
                <?php if ($top_students): ?>
                    <table class="ap-table">
                        <thead><tr><th>#</th><th>Student</th><th>School</th><th style="text-align:right;">Score</th></tr></thead>
                        <tbody>
                            <?php foreach ($top_students as $i => $ts): ?>
                                <tr>
                                    <td style="color:var(--c-text-faint); font-size:var(--text-xs);"><?= $i + 1 ?></td>
                                    <td style="color:var(--c-text); font-weight:500;"><?= htmlspecialchars($ts['student_name']) ?></td>
                                    <td style="font-size:var(--text-xs);"><?= htmlspecialchars($ts['school_name'] ?? '—') ?></td>
                                    <td style="text-align:right; font-family:'DM Mono',monospace; font-size:var(--text-sm); color:var(--c-text);"><?= number_format((float)$ts['total_score'], 1) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div style="padding:var(--sp-5); font-size:var(--text-sm); color:var(--c-text-faint);">No submitted scores yet.</div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- Audit log -->
    <div class="ap-card">
        <div class="ap-card-head">
            <div class="ap-card-head-left">
                <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                <h2>Login Audit Log</h2>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="clear_logs">
                <button type="submit" class="btn-sm-ghost"
                    onclick="return confirm('Clear all audit log entries?')">
                    Clear Log
                </button>
            </form>
        </div>
        <div style="overflow-x:auto;">
            <?php if ($logs): ?>
                <table class="ap-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>User</th>
                            <th>Role</th>
                            <th>IP Address</th>
                            <th>Login Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td style="color:var(--c-text-faint); font-size:var(--text-xs);"><?= $log['id'] ?></td>
                                <td style="color:var(--c-text); font-weight:500;"><?= htmlspecialchars($log['username'] ?? '[deleted]') ?></td>
                                <td><span class="role-badge <?= htmlspecialchars($log['role']) ?>"><?= strtoupper(htmlspecialchars($log['role'])) ?></span></td>
                                <td class="log-ip"><?= htmlspecialchars($log['ip']) ?></td>
                                <td class="log-time"><?= htmlspecialchars($log['login_time']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="padding:var(--sp-5); font-size:var(--text-sm); color:var(--c-text-faint);">No log entries.</div>
            <?php endif; ?>
        </div>
        <?php if ($total_log_count > $log_limit): ?>
            <div class="log-note">Showing the <?= $log_limit ?> most recent entries. <?= number_format($total_log_count) ?> total entries in the log.</div>
        <?php endif; ?>
    </div>

</div>

</main>
</body>
</html>