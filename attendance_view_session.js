// attendance_view_session.js — the school-picker + QR-generation modal
// for a session (attendance_view_session.php). Split out of that file's
// inline <script> block. No PHP interpolation here — showQR() receives
// the school name/QR URL as plain function arguments from onclick="..."
// attributes (themselves built server-side via json_encode), so this is
// a plain file move.

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
