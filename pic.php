<?php
// ── Production error handling ──────────────────────────────────────────────
// Errors are logged + alerted by security_bootstrap.php, never shown to users.
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

session_start();
require __DIR__ . '/auth_check.php';
include 'db.php';
$conn = getDB();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pic') {
    header("Location: login.php");
    exit();
}
session_write_close();

// ── Smart migrations ───────────────────────────────────────────────────────
$checkCol = $conn->query("SHOW COLUMNS FROM `students` LIKE 'year'");
if ($checkCol->num_rows == 0) {
    $currentYear = date('Y');
    $conn->query("ALTER TABLE `students` ADD `year` VARCHAR(4) NOT NULL DEFAULT '$currentYear' AFTER `gender`");
}

$conn->query("CREATE TABLE IF NOT EXISTS medal_quotas (
    level_id INT PRIMARY KEY,
    gold_quota INT NOT NULL DEFAULT 0,
    silver_quota INT NOT NULL DEFAULT 0,
    bronze_quota INT NOT NULL DEFAULT 0
)");

$checkBronze = $conn->query("SHOW COLUMNS FROM `medal_quotas` LIKE 'bronze_quota'");
if ($checkBronze && $checkBronze->num_rows == 0) {
    $conn->query("ALTER TABLE `medal_quotas` ADD `bronze_quota` INT NOT NULL DEFAULT 0 AFTER `silver_quota`");
}

// ── Layout inclusion ───────────────────────────────────────────────────────
$pm_page = 'pic';
include 'layout.php';
?>

<?php
$pm_pic_css_v = @filemtime(__DIR__ . '/pic.css') ?: time();
?>
<link rel="stylesheet" href="pic.css?v=<?= $pm_pic_css_v ?>">

<?php
function getCountSafe($conn, $table) {
    $res = $conn->query("SELECT COUNT(*) AS total FROM `$table`");
    return $res ? (int)$res->fetch_assoc()['total'] : 0;
}

$active_siri = (int)($_SESSION['active_siri_id'] ?? 0);

if ($active_siri > 0) {
    $sessions     = $conn->query("SELECT COUNT(*) AS total FROM sessions WHERE siri_id = $active_siri")->fetch_assoc()['total'] ?? 0;
    $levels       = $conn->query("SELECT COUNT(*) AS total FROM levels l JOIN sessions s ON l.session_id = s.session_id WHERE s.siri_id = $active_siri")->fetch_assoc()['total'] ?? 0;
    $groups       = $conn->query("SELECT COUNT(*) AS total FROM `groups` g JOIN levels l ON g.level_id = l.level_id JOIN sessions s ON l.session_id = s.session_id WHERE s.siri_id = $active_siri")->fetch_assoc()['total'] ?? 0;
    $schools      = $conn->query("SELECT COUNT(DISTINCT school_id) AS total FROM siri_schools WHERE siri_id = $active_siri")->fetch_assoc()['total'] ?? 0;
    $tests        = $conn->query("SELECT COUNT(*) AS total FROM tests t JOIN levels l ON t.level_id = l.level_id JOIN sessions s ON l.session_id = s.session_id WHERE s.siri_id = $active_siri")->fetch_assoc()['total'] ?? 0;
    // Count students via UNION of level_id path and group_students path.
    // level_id path catches students with no group; group_students path catches
    // students whose level_id points to a different siri. UNION deduplicates.
    $students     = $conn->query("
        SELECT COUNT(DISTINCT student_id) AS total FROM (
            SELECT st.student_id
            FROM students st
            JOIN levels l ON st.level_id = l.level_id
            JOIN sessions s ON l.session_id = s.session_id
            WHERE s.siri_id = $active_siri
            UNION
            SELECT gs.student_id
            FROM group_students gs
            JOIN `groups` g ON gs.group_id = g.group_id
            JOIN levels gl ON g.level_id = gl.level_id
            JOIN sessions s ON gl.session_id = s.session_id
            WHERE s.siri_id = $active_siri
        ) combined
    ")->fetch_assoc()['total'] ?? 0;
    $judges_count = getCountSafe($conn, 'judges');
    $criteria     = getCountSafe($conn, 'criteria');
} else {
    $levels       = getCountSafe($conn, 'levels');
    $tests        = getCountSafe($conn, 'tests');
    $criteria     = getCountSafe($conn, 'criteria');
    $groups       = getCountSafe($conn, 'groups');
    $students     = getCountSafe($conn, 'students');
    $schools      = getCountSafe($conn, 'schools');
    $sessions     = getCountSafe($conn, 'sessions');
    $judges_count = getCountSafe($conn, 'judges');
}

$siri_count = 0;
$siriTblChk2 = $conn->query("SHOW TABLES LIKE 'siri'");
if ($siriTblChk2 && $siriTblChk2->num_rows > 0) {
    $siri_count = getCountSafe($conn, 'siri');
}

$tz      = new DateTimeZone('Asia/Kuala_Lumpur');
$now     = new DateTime('now', $tz);
$hourMY  = (int)$now->format('G');

if ($hourMY >= 0 && $hourMY < 12)      { $greeting = 'Selamat Pagi'; }
elseif ($hourMY >= 12 && $hourMY < 15) { $greeting = 'Selamat Tengahari'; }
elseif ($hourMY >= 15 && $hourMY < 19) { $greeting = 'Selamat Petang'; }
else                                    { $greeting = 'Selamat Malam'; }

$malayDays   = ['Sunday'=>'Ahad','Monday'=>'Isnin','Tuesday'=>'Selasa','Wednesday'=>'Rabu','Thursday'=>'Khamis','Friday'=>'Jumaat','Saturday'=>'Sabtu'];
$malayMonths = ['January'=>'Januari','February'=>'Februari','March'=>'Mac','April'=>'April','May'=>'Mei','June'=>'Jun','July'=>'Julai','August'=>'Ogos','September'=>'September','October'=>'Oktober','November'=>'November','December'=>'Disember'];

$dayMY   = $malayDays[$now->format('l')];
$monthMY = $malayMonths[$now->format('F')];
$dateMY  = $dayMY . ', ' . $now->format('j') . ' ' . $monthMY . ' ' . $now->format('Y');

/* Arrow SVG — reused in every footer */
$arr = '<svg class="arr" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>';
?>

<div class="dash-wrap">

<!-- ── Hero ── -->
<div class="dash-hero">
    <div class="dash-hero-left">
        <span class="dash-hero-role">Pengurus Sistem</span>
        <h2 class="dash-hero-greeting"><?= $greeting ?>, <em>Pengurus Sistem</em></h2>
        <span class="dash-hero-sub">Papan pemuka pentadbiran aktif</span>
    </div>
    <div class="dash-hero-clock">
        <span class="dash-clock-date">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <?= $dateMY ?>
        </span>
        <span class="dash-hero-time" id="live-clock"></span>
    </div>
</div>

<!-- ── Stats ── -->
<p class="dash-section-label">Ringkasan Sistem</p>

<div class="pm-stat-grid">

    <!-- 1. Pelajar — quick action: Daftar Pelajar Baharu -->
    <div class="pm-stat-card">
        <a href="pic_students.php" class="stat-body">
            <div class="stat-icon">🥋</div>
            <div class="stat-info">
                <span class="stat-value"><?= $students ?></span>
                <span class="stat-label">Jumlah Pelajar</span>
            </div>
        </a>
        <a href="upload_students.php" class="stat-action">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span class="stat-action-text">Daftar Pelajar Baharu</span>
            <?= $arr ?>
        </a>
    </div>

    <!-- 2. Cawangan -->
    <a href="pic_schools.php" class="pm-stat-card">
        <div class="stat-body">
            <div class="stat-icon">🏫</div>
            <div class="stat-info">
                <span class="stat-value"><?= $schools ?></span>
                <span class="stat-label">Cawangan</span>
            </div>
        </div>
        <span class="stat-action">
            <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <span class="stat-action-text">Urus Cawangan</span>
            <?= $arr ?>
        </span>
    </a>

    <!-- 3. Siri -->
    <a href="pic_siri.php" class="pm-stat-card">
        <div class="stat-body">
            <div class="stat-icon">📋</div>
            <div class="stat-info">
                <span class="stat-value"><?= $siri_count ?></span>
                <span class="stat-label">Siri</span>
            </div>
        </div>
        <span class="stat-action">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <span class="stat-action-text">Urus Siri</span>
            <?= $arr ?>
        </span>
    </a>

    <!-- 4. Sidang Aktif — Sahkan Kehadiran -->
    <div class="pm-stat-card">
        <a href="pic_sessions.php" class="stat-body">
            <div class="stat-icon">📅</div>
            <div class="stat-info">
                <span class="stat-value"><?= $sessions ?></span>
                <span class="stat-label">Sidang Aktif</span>
            </div>
        </a>
        <a href="manage_attendance.php" class="stat-action">
            <svg viewBox="0 0 24 24"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>
            <span class="stat-action-text">Sahkan Kehadiran</span>
            <?= $arr ?>
        </a>
    </div>

    <!-- 5. Juri -->
    <a href="pic_judges.php" class="pm-stat-card">
        <div class="stat-body">
            <div class="stat-icon">👨‍⚖️</div>
            <div class="stat-info">
                <span class="stat-value"><?= $judges_count ?></span>
                <span class="stat-label">Juri Berdaftar</span>
            </div>
        </div>
        <span class="stat-action">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <span class="stat-action-text">Urus Juri</span>
            <?= $arr ?>
        </span>
    </a>

    <!-- 6. Peringkat -->
    <a href="pic_levels.php" class="pm-stat-card">
        <div class="stat-body">
            <div class="stat-icon">🎯</div>
            <div class="stat-info">
                <span class="stat-value"><?= $levels ?></span>
                <span class="stat-label">Peringkat</span>
            </div>
        </div>
        <span class="stat-action">
            <svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            <span class="stat-action-text">Urus Peringkat</span>
            <?= $arr ?>
        </span>
    </a>

    <!-- 7. Ujian — Pantau Markah Langsung (black, same as all others) -->
    <div class="pm-stat-card">
        <a href="pic_tests.php" class="stat-body">
            <div class="stat-icon">🧪</div>
            <div class="stat-info">
                <span class="stat-value"><?= $tests ?></span>
                <span class="stat-label">Ujian</span>
            </div>
        </a>
        <a href="pic_view_marks.php" class="stat-action">
            <svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            <span class="stat-action-text">Pantau Markah Langsung</span>
            <?= $arr ?>
        </a>
    </div>

    <!-- 8. Kumpulan Juri — Tetapkan Kumpulan Juri -->
    <div class="pm-stat-card">
        <a href="pic_groups.php" class="stat-body">
            <div class="stat-icon">👥</div>
            <div class="stat-info">
                <span class="stat-value"><?= $groups ?></span>
                <span class="stat-label">Kumpulan Juri</span>
            </div>
        </a>
        <a href="pic_groups.php" class="stat-action">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
            <span class="stat-action-text">Tetapkan Kumpulan Juri</span>
            <?= $arr ?>
        </a>
    </div>

</div><!-- /.pm-stat-grid -->

<script>
(function () {
    function getMYTime() {
        var now = new Date();
        var utc = now.getTime() + now.getTimezoneOffset() * 60000;
        var my  = new Date(utc + 8 * 3600000);
        return [my.getHours(), my.getMinutes(), my.getSeconds()]
            .map(function(v){ return String(v).padStart(2, '0'); }).join(':');
    }
    var el = document.getElementById('live-clock');
    if (el) { el.textContent = getMYTime(); setInterval(function(){ el.textContent = getMYTime(); }, 1000); }
})();
</script>

</div><!-- /.dash-wrap -->
</main>
</body>
</html>