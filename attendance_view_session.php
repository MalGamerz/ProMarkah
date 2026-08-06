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
<script>
let qrTimerInterval;

function showQR(schoolName, url) {
    // Parse expiry from the URL's exp param
    const expMatch = url.match(/[?&]exp=(\d+)/);
    const expTimestamp = expMatch ? parseInt(expMatch[1]) : 0;

    document.getElementById('qrSchoolName').textContent = '📱 QR — ' + schoolName;
    document.getElementById('qrDirectLink').href = url;

    const canvas = document.getElementById('qrCode');
    canvas.innerHTML = '';
    canvas.style.opacity = '1';

    if (typeof QRCode !== 'undefined') {
        new QRCode(canvas, {
            text:          url,
            width:         220,
            height:        220,
            colorDark:     '#000000',
            colorLight:    '#ffffff',
            correctLevel:  QRCode.CorrectLevel.M
        });
    }

    document.getElementById('qrModal').classList.add('visible');
    document.body.style.overflow = 'hidden';

    clearInterval(qrTimerInterval);
    const countdownEl = document.getElementById('qrCountdown');

    function updateTimer() {
        const diff = expTimestamp - Math.floor(Date.now() / 1000);
        if (diff <= 0) {
            countdownEl.textContent  = 'QR TAMAT TEMPOH';
            countdownEl.style.color  = '#737373';
            canvas.style.opacity     = '0.15';
            clearInterval(qrTimerInterval);
        } else {
            const h = Math.floor(diff / 3600);
            const m = Math.floor((diff % 3600) / 60);
            const s = diff % 60;
            countdownEl.textContent = `Sah Selama: ${h}j ${m}m ${s}s`;
            countdownEl.style.color = 'var(--c-red)';
        }
    }

    updateTimer();
    qrTimerInterval = setInterval(updateTimer, 1000);
}

function closeQR(e) {
    if (e && e.target !== document.getElementById('qrModal')) return;
    document.getElementById('qrModal').classList.remove('visible');
    document.body.style.overflow = '';
    clearInterval(qrTimerInterval);
}
</script>

<style>
/* ── Page hint ──────────────────────────────────────────────────── */
.pm-page-hint {
    color: var(--c-text-muted, #4b5563);
    font-size: 0.9rem;
    margin: -6px 0 22px;
}

/* ── Global QR button ───────────────────────────────────────────── */
.pm-global-qr-wrap {
    display: flex;
    justify-content: center;
    margin-bottom: 32px;
}
.pm-global-qr-btn  {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 16px 32px;
    font-size: 1rem;
    font-weight: 700;
    border: none;
    border-radius: 12px;
    white-space: nowrap;
    background: linear-gradient(135deg, var(--c-red, #b30000) 0%, #800000 100%);
    color: #fff;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    box-shadow: 0 6px 16px rgba(179,0,0,.25);
}
.pm-global-qr-btn:hover  { transform: translateY(-2px); box-shadow: 0 10px 22px rgba(179,0,0,.3); }
.pm-global-qr-btn:active { transform: translateY(1px);  box-shadow: 0 4px 10px rgba(179,0,0,.25); }

/* ── School selection grid ──────────────────────────────────────── */
.pm-grid-select {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 32px;
    align-items: stretch;
}

/* ── School card ────────────────────────────────────────────────── */
.pm-school-card {
    background: var(--c-surface-1, #fff);
    border: 1px solid var(--c-border, #e5e7eb);
    border-radius: 12px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 2px 4px rgba(0,0,0,.05);
    transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
}
.pm-school-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 12px rgba(0,0,0,.1);
    border-color: var(--c-red, #b30000);
}
.pm-school-card-body {
    padding: 16px;
    text-align: center;
    text-decoration: none;
    color: var(--c-text, #111827);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    flex: 1;
}
.pm-school-icon   { font-size: 1.6rem; margin-bottom: 2px; }
.pm-school-name   { font-weight: 700; font-size: 1rem; line-height: 1.3; }
.pm-school-stats  {
    font-size: 0.8rem;
    color: var(--c-text-muted, #4b5563);
    background: var(--c-surface-3, #f3f4f6);
    padding: 4px 10px;
    border-radius: 12px;
    margin-top: 4px;
}
.pm-school-action { font-size: 0.8rem; color: var(--c-red, #b30000); font-weight: 600; margin-top: auto; padding-top: 8px; }
.pm-school-qr-btn {
    width: 100%;
    background: var(--c-surface-2, #f9fafb);
    border: none;
    border-top: 1px solid var(--c-border, #e5e7eb);
    color: var(--c-text-muted, #4b5563);
    font-family: 'DM Sans', sans-serif;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    padding: 12px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: background 0.2s, color 0.2s;
}
.pm-school-qr-btn:hover {
    background: var(--c-red-dim, rgba(179,0,0,.1));
    color: var(--c-red, #b30000);
    border-top-color: var(--c-red, #b30000);
}

/* ── QR Modal ───────────────────────────────────────────────────── */
.pm-qr-modal       { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.8); z-index: 500; align-items: center; justify-content: center; }
.pm-qr-modal.visible { display: flex; }
.pm-qr-modal-inner {
    background: var(--c-surface-1, #fff);
    border: 1px solid var(--c-border-strong, #111827);
    border-radius: 12px;
    width: 100%;
    max-width: 320px;
    margin: 16px;
    overflow: hidden;
    animation: popIn .2s ease-out both;
}
@keyframes popIn { from { transform: scale(.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.pm-qr-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px;
    border-bottom: 1px solid var(--c-border, #e5e7eb);
}
.pm-qr-modal-title  { font-family: 'Bebas Neue', sans-serif; font-size: 1.1rem; color: var(--c-text, #111827); }
.pm-qr-close        { background: none; border: none; color: var(--c-text-muted, #6b7280); font-size: 1rem; cursor: pointer; transition: color .2s; }
.pm-qr-close:hover  { color: var(--c-red, #b30000); }
.pm-qr-modal-body   { padding: 24px; display: flex; flex-direction: column; align-items: center; gap: 12px; }
.pm-qr-canvas       {
    background: #fff;
    border-radius: 8px;
    border: 1px solid var(--c-border, #e5e7eb);
    padding: 12px;
    min-height: 220px;
    min-width: 220px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.pm-qr-countdown    { font-family: 'DM Mono', monospace; font-size: 1.25rem; font-weight: bold; text-align: center; }
.pm-qr-note         { font-size: 0.8rem; color: var(--c-text-muted, #4b5563); text-align: center; }
.pm-qr-direct-link  { font-size: 0.8rem; color: var(--c-red, #b30000); text-decoration: none; font-weight: 600; }
.pm-qr-direct-link:hover { text-decoration: underline; }

/* ── Responsive ─────────────────────────────────────────────────── */
@media (max-width: 768px) {
    .pm-grid-select   { display: flex; flex-direction: column; gap: 12px; }
    .pm-global-qr-wrap { width: 100%; }
    .pm-global-qr-btn { width: 100%; flex-direction: column; text-align: center; padding: 16px; white-space: normal; }
}
</style>
