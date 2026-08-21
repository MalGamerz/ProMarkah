<?php
/**
 * error_page.php — the ONLY thing a visitor sees when something breaks.
 *
 * Rendered by promarkah_render_error_page() in security_bootstrap.php. It is
 * deliberately self-contained (no DB, no external CSS/fonts/images) so it
 * still displays correctly even when the database or network is the very
 * thing that failed. It exposes NOTHING technical — just an apology and the
 * random incident id ($safeId, already html-escaped by the caller).
 *
 * THEME: follows the user's already-chosen theme, read from the same
 * localStorage key ('pm-theme') that layout.php's theme toggle writes to —
 * no dashboard.css needed, just the two palettes below kept in sync with
 * dashboard.css's --c-* tokens. Applied via an inline pre-paint script
 * (same pattern as layout.php) so there is no flash of the wrong theme.
 * Defaults to light when nothing is saved yet, matching the app-wide default.
 */
if (!isset($safeId)) { $safeId = 'UNKNOWN'; }
?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Ralat Sistem | ProMarkah</title>
    <script>
        (function () {
            // Default is light (app-wide default — see layout.php), unless the
            // user has explicitly switched to dark.
            var t = localStorage.getItem('pm-theme');
            if (t !== 'dark') { document.documentElement.classList.add('pm-light'); }
        })();
    </script>
<?php
$pm_ep_css_v = @filemtime(__DIR__ . '/error_page.css') ?: time();
?>
<link rel="stylesheet" href="error_page.css?v=<?= $pm_ep_css_v ?>">
</head>
<body>
    <div class="card">
        <div class="icon">⚠️</div>
        <h1>Maaf, sistem menghadapi masalah</h1>
        <p>Berlaku ralat teknikal yang tidak dijangka. Pasukan teknikal telah dimaklumkan secara automatik.</p>
        <p>Sila cuba semula sebentar lagi.</p>

        <div class="ref">Rujukan: <b><?= $safeId ?></b></div>

        <div class="actions">
            <a class="btn" href="login.php">Kembali ke Log Masuk</a>
        </div>

        <div class="muted">Jika masalah berterusan, berikan nombor rujukan di atas kepada pentadbir sistem.</div>
    </div>
</body>
</html>
