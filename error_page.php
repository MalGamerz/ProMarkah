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
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html {
            /* Dark palette (default) — matches dashboard.css's dark --c-* tokens */
            --bg: #000000;
            --card-bg: #181818;
            --border: rgba(255,255,255,0.15);
            --border-dashed: rgba(255,255,255,0.2);
            --text: #ffffff;
            --text-muted: #9ca3af;
            --ref-text: #d1d5db;
            --ref-bg: #111111;
            --red: #cc0000;
            --red-hover: #990000;
            --accent: #f87171;
        }
        html.pm-light {
            /* Light palette — matches dashboard.css's html.pm-light --c-* tokens */
            --bg: #f5f4f0;
            --card-bg: #ffffff;
            --border: rgba(0,0,0,0.11);
            --border-dashed: rgba(0,0,0,0.18);
            --text: #1a1a1a;
            --text-muted: #6b6b6b;
            --ref-text: #3d3d3d;
            --ref-bg: #f5f4f0;
            --red: #b30000;
            --red-hover: #800000;
            --accent: #b30000;
        }
        body {
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            text-align: center;
            transition: background .15s, color .15s;
        }
        .card {
            max-width: 440px;
            width: 100%;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 40px 32px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.35);
        }
        .icon { font-size: 3rem; line-height: 1; margin-bottom: 16px; }
        h1 {
            font-size: 1.35rem;
            font-weight: 800;
            letter-spacing: 0.02em;
            color: var(--text);
            margin-bottom: 12px;
        }
        p { font-size: 0.95rem; line-height: 1.6; color: var(--text-muted); margin-bottom: 10px; }
        .ref {
            display: inline-block;
            margin-top: 18px;
            padding: 8px 14px;
            background: var(--ref-bg);
            border: 1px dashed var(--border-dashed);
            border-radius: 10px;
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
            font-size: 0.85rem;
            color: var(--ref-text);
            letter-spacing: 0.08em;
        }
        .ref b { color: var(--accent); }
        .actions { margin-top: 26px; }
        .btn {
            display: inline-block;
            background: var(--red);
            color: #fff;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            padding: 12px 26px;
            border-radius: 12px;
            transition: background 0.2s;
        }
        .btn:hover { background: var(--red-hover); }
        .muted { margin-top: 22px; font-size: 0.75rem; color: var(--text-muted); }
    </style>
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
