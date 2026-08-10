<?php
// ══════════════════════════════════════════════════════════════════
//  attendance_view_session.php — Admin: pick a school for a session
//  and generate per-school or global QR codes.
// ══════════════════════════════════════════════════════════════════

$session_id   = (int)$_GET['session_id'];
$session_name = getSessionName($conn, $session_id);
$schools      = getSessionSchools($conn, $session_id);

// Pre-generate Global QR URL
$global_qr_url = makeQrUrl(0, $session_id);
?>

<h2 class="pm-page-heading">🏫 Pilih Cawangan</h2>
<p class="pm-page-hint">Pilih cawangan di bawah untuk tandakan kehadiran atau jana QR pendaftaran kendiri.</p>

<div class="pm-global-qr-wrap">
    <button class="pm-btn pm-btn-danger pm-global-qr-btn"
            onclick="showQR('Semua Cawangan (Global)', <?= htmlspecialchars(json_encode($global_qr_url)) ?>)">
        <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <rect x="3"  y="3"  width="7" height="7" rx="1"/>
            <rect x="14" y="3"  width="7" height="7" rx="1"/>
            <rect x="3"  y="14" width="7" height="7" rx="1"/>
            <path d="M14 14h7v7h-7z"/>
        </svg>
        <span>Jana QR Umum (Semua Cawangan)</span>
    </button>
</div>

<?php if (!empty($schools)): ?>
<div class="pm-grid-select">
    <?php foreach ($schools as $row):
        $qr_url = makeQrUrl((int)$row['school_id'], $session_id);
    ?>
    <div class="pm-school-card">
        <a class="pm-school-card-body"
           href="attendance.php?session_id=<?= $session_id ?>&school_id=<?= (int)$row['school_id'] ?>">
            <div class="pm-school-icon">🏫</div>
            <div class="pm-school-name"><?= htmlspecialchars($row['school_name']) ?></div>
            <div class="pm-school-stats">
                Hadir: <strong><?= (int)$row['present_students'] ?></strong> / <?= (int)$row['total_students'] ?>
            </div>
            <div class="pm-school-action">Urus Kehadiran →</div>
        </a>
        <button class="pm-school-qr-btn"
                onclick="showQR(<?= htmlspecialchars(json_encode($row['school_name'])) ?>, <?= htmlspecialchars(json_encode($qr_url)) ?>)">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <rect x="3"  y="3"  width="7" height="7" rx="1"/>
                <rect x="14" y="3"  width="7" height="7" rx="1"/>
                <rect x="3"  y="14" width="7" height="7" rx="1"/>
                <path d="M14 14h7v7h-7z"/>
            </svg>
            Jana QR
        </button>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
    <div class="pm-empty-state" style="grid-column:1/-1;">
        <p>Tiada cawangan ditetapkan untuk sidang ini.</p>
    </div>
<?php endif; ?>

<!-- QR Modal -->
<div id="qrModal" class="pm-qr-modal" onclick="closeQR(event)">
    <div class="pm-qr-modal-inner">
        <div class="pm-qr-modal-header">
            <div class="pm-qr-modal-title" id="qrSchoolName">QR Kehadiran</div>
            <button class="pm-qr-close" onclick="closeQR()">✕</button>
        </div>
        <div class="pm-qr-modal-body">
            <div id="qrCode" class="pm-qr-canvas"></div>
            <div id="qrCountdown" class="pm-qr-countdown"></div>
            <p class="pm-qr-note">Tunjukkan kod ini kepada pesilat untuk imbas dan daftar hadir sendiri.</p>
            <a id="qrDirectLink" href="#" target="_blank" class="pm-qr-direct-link">Atau buka pautan terus →</a>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<?php
$pm_avsess_js_v = @filemtime(__DIR__ . '/attendance_view_session.js') ?: time();
?>
<script src="attendance_view_session.js?v=<?= $pm_avsess_js_v ?>"></script>
<?php // JS moved to attendance_view_session.js ?>

<?php
$pm_avsess_css_v = @filemtime(__DIR__ . '/attendance_view_session.css') ?: time();
?>
<link rel="stylesheet" href="attendance_view_session.css?v=<?= $pm_avsess_css_v ?>">
