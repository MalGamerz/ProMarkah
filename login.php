<?php
// Never show raw errors to visitors; security_bootstrap.php logs them and
// alerts instead. (Kept explicit here in case the bootstrap isn't loaded.)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// -------------------------------------------------------------------
// FORCE HTTPS (before session cookie is set)
// -------------------------------------------------------------------
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    exit();
}

// -------------------------------------------------------------------
// SESSION HARDENING & IDLE TIMEOUT
// -------------------------------------------------------------------
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Strict');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', 1);
}
session_start();
require 'auth_check.php';

// -------------------------------------------------------------------
// SECURITY HEADERS
// -------------------------------------------------------------------
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("X-Content-Type-Options: nosniff");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// -------------------------------------------------------------------
// DATABASE
// -------------------------------------------------------------------
include 'db.php';
$conn = getDB();

// -------------------------------------------------------------------
// CSRF TOKEN
// -------------------------------------------------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// -------------------------------------------------------------------
// GOOGLE SIGN-IN NONCE (judge login only — see oauth_google_callback.php)
// -------------------------------------------------------------------
include_once 'oauth_config.php';
// Refreshed on every GET (a fresh page load), but must also exist on a POST
// that falls through to re-render this same form (failed login, or — as
// seen in error alerts — a bot/scanner POSTing straight to login.php or a
// bogus route without ever loading the page first). The old GET-only
// condition below left $_SESSION['google_oauth_nonce'] completely unset in
// that case, and the Google Sign-In script tag further down reads it
// unconditionally, throwing "Undefined array key" once it tried to render.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['google_oauth_nonce'])) {
    $_SESSION['google_oauth_nonce'] = bin2hex(random_bytes(16));
}

$oauth_error_messages = [
    'invalid_request'   => 'Permintaan log masuk tidak lengkap. Sila cuba lagi.',
    'not_configured'    => 'Log masuk ini belum ditetapkan lagi. Hubungi pentadbir sistem.',
    'google_unreachable' => 'Tidak dapat menghubungi Google. Sila cuba lagi.',
    'apple_unreachable' => 'Tidak dapat menghubungi Apple. Sila cuba lagi.',
    'wrong_audience'    => 'Token log masuk tidak sah untuk aplikasi ini.',
    'wrong_issuer'      => 'Token log masuk tidak sah.',
    'email_not_verified' => 'E-mel akaun anda belum disahkan oleh penyedia log masuk.',
    'invalid_nonce'     => 'Sesi log masuk telah tamat tempoh. Sila cuba lagi.',
    'invalid_state'     => 'Sesi log masuk telah tamat tempoh. Sila cuba lagi.',
    'invalid_token'     => 'Log masuk gagal disahkan. Sila cuba lagi.',
    'no_judge_linked'   => 'E-mel ini tidak dikaitkan dengan mana-mana akaun juri. Hubungi PIC anda untuk menambah e-mel ini pada akaun juri anda.',
];
$oauth_error = '';
if (!empty($_GET['oauth_error'])) {
    $oauth_error = $oauth_error_messages[$_GET['oauth_error']] ?? 'Log masuk gagal. Sila cuba lagi.';
}

// -------------------------------------------------------------------
// TIME TRAP (prevent instant form submission)
// -------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['form_load_time'] = time();
}

// -------------------------------------------------------------------
// RATE LIMITING (IP‑based, persisted in DB — survives cookie/session
// resets, unlike the old session-based counter which an attacker could
// bypass simply by dropping their cookie between attempts)
// -------------------------------------------------------------------
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ip_attempts = 0;
$ip_last_attempt = 0;
try {
    $stmtT = $conn->prepare("SELECT attempts, last_attempt FROM login_throttle WHERE ip = ? LIMIT 1");
    $stmtT->bind_param("s", $client_ip);
    $stmtT->execute();
    $rowT = $stmtT->get_result()->fetch_assoc();
    $stmtT->close();
    if ($rowT) {
        $ip_attempts = (int)$rowT['attempts'];
        $ip_last_attempt = strtotime($rowT['last_attempt']);
    }
} catch (mysqli_sql_exception $e) {
    // Table doesn't exist yet (fresh install) — create it once, then
    // treat this request as the first attempt from this IP.
    $conn->query("CREATE TABLE IF NOT EXISTS login_throttle (
        ip VARCHAR(45) NOT NULL PRIMARY KEY,
        attempts INT NOT NULL DEFAULT 0,
        last_attempt DATETIME NOT NULL
    )");
}

if ($ip_attempts >= 5 && (time() - $ip_last_attempt) < 300) {
    $wait_minutes = ceil((300 - (time() - $ip_last_attempt)) / 60);
    die("<!DOCTYPE html><html><body style='background:#111;color:#ff5252;display:flex;justify-content:center;align-items:center;height:100vh;text-align:center;'><div><h1>SEKATAN KESELAMATAN</h1><p>Terlalu banyak percubaan log masuk tidak sah daripada rangkaian anda ($ip_attempts percubaan). Sila cuba lagi dalam masa lebih kurang $wait_minutes minit.</p></div></body></html>");
}

$error = '';
$step = 1;

if (!empty($_SESSION['step']) && $_SESSION['step'] === 2) {
    $step = 2;
}

// -------------------------------------------------------------------
// FORM SUBMISSION HANDLING
// -------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Token keselamatan telah tamat tempoh. Sila muat semula halaman.";
    } else {
        // Honeypot
        if (!empty($_POST['website_url'])) {
            die("Berjaya");
        }
        // Time trap
        if (isset($_SESSION['form_load_time']) && (time() - $_SESSION['form_load_time']) < 2) {
            die("Borang dihantar terlalu pantas. Bot dikesan.");
        }

        $stmtUp = $conn->prepare("INSERT INTO login_throttle (ip, attempts, last_attempt) VALUES (?, 1, NOW())
            ON DUPLICATE KEY UPDATE
                attempts = IF(last_attempt < (NOW() - INTERVAL 300 SECOND), 1, attempts + 1),
                last_attempt = NOW()");
        $stmtUp->bind_param("s", $client_ip);
        $stmtUp->execute();
        $stmtUp->close();

        // ---------- STEP 1: normal user login ----------
        if (isset($_POST['username'], $_POST['password']) && $step === 1) {
            $username = trim($_POST['username']);
            $password_input = trim($_POST['password']);

            if ($username === '' && $password_input === '') {
                $error = "Sila masukkan nama pengguna dan kata laluan anda.";
            } elseif ($username === '') {
                $error = "Sila masukkan nama pengguna anda.";
            } elseif ($password_input === '') {
                $error = "Sila masukkan kata laluan anda.";
            } else {

            $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username=? LIMIT 1");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows === 1) {
                $user = $result->fetch_assoc();
                if (password_verify($password_input, $user['password'])) {
                    // success
                    $stmtReset = $conn->prepare("DELETE FROM login_throttle WHERE ip = ?");
                    $stmtReset->bind_param("s", $client_ip);
                    $stmtReset->execute();
                    $stmtReset->close();
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];

                    // Audit log
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $stmtAudit = $conn->prepare("INSERT INTO audit_log (user_id, role, ip, login_time) VALUES (?, ?, ?, NOW())");
                    $stmtAudit->bind_param("iss", $user['id'], $user['role'], $ip);
                    $stmtAudit->execute();
                    $stmtAudit->close();

                    if ($user['role'] === 'pic') {
                        header('Location: pic.php'); exit;
                    } elseif ($user['role'] === 'recorder') {
                        header('Location: attendance.php'); exit;
                    } elseif ($user['role'] === 'admin') {
                        header('Location: admin.php'); exit;
                    } elseif ($user['role'] === 'judge') {
                        session_regenerate_id(true);
                        $_SESSION['step'] = 2;
                        header('Location: login.php'); exit;
                    }
                } else {
                    $error = "Nama pengguna atau kata laluan tidak sah.";
                }
            } else {
                $error = "Nama pengguna atau kata laluan tidak sah.";
            }
            $stmt->close();
            }

        // ---------- STEP 2: judge login (unified error message for wrong PIN/judge) ----------
        } elseif (isset($_POST['judge_id'], $_POST['pin']) && $step === 2) {
            $judge_id = (int)$_POST['judge_id'];
            $pin = trim($_POST['pin']);

            if ($judge_id <= 0 && $pin === '') {
                $error = "Sila pilih nama anda dan masukkan PIN.";
            } elseif ($judge_id <= 0) {
                $error = "Sila pilih nama anda daripada senarai.";
            } elseif ($pin === '') {
                $error = "Sila masukkan PIN anda.";
            } else {

            $stmt = $conn->prepare("SELECT id, pin_hash, name FROM judges WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $judge_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows === 1) {
                $judge = $result->fetch_assoc();
                if (password_verify($pin, $judge['pin_hash'])) {
                    // success
                    $stmtReset = $conn->prepare("DELETE FROM login_throttle WHERE ip = ?");
                    $stmtReset->bind_param("s", $client_ip);
                    $stmtReset->execute();
                    $stmtReset->close();
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $judge['id'];
                    $_SESSION['role'] = 'judge';
                    $_SESSION['judge_name'] = $judge['name'];
                    unset($_SESSION['step']);

                    // Audit log
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $stmtAudit = $conn->prepare("INSERT INTO audit_log (user_id, role, ip, login_time) VALUES (?, 'judge', ?, NOW())");
                    $stmtAudit->bind_param("is", $judge['id'], $ip);
                    $stmtAudit->execute();
                    $stmtAudit->close();

                    header('Location: judge.php');
                    exit;
                } else {
                    // Unified error message – same for invalid PIN or wrong judge
                    $error = "Kelayakan tidak sah.";
                }
            } else {
                $error = "Kelayakan tidak sah.";
            }
            $stmt->close();
            }
        }
    }
}

// -------------------------------------------------------------------
// Fetch judges list for step 2 (only if needed)
// -------------------------------------------------------------------
$judges_list = [];
if ($step === 2) {
    $j_res = $conn->query("SELECT id, name FROM judges ORDER BY name ASC");
    if ($j_res) {
        while ($r = $j_res->fetch_assoc()) {
            $judges_list[] = $r;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Masuk | ProMarkah — Sistem Pengurusan Pertandingan Silat</title>

    <meta name="robots" content="index, follow">
    <meta name="description" content="ProMarkah ialah platform digital untuk pengurusan pertandingan, penjurian, dan penilaian markah silat secara masa nyata. Log masuk untuk mengakses panel anda.">
    <link rel="canonical" href="https://www.prosilat.net/">
    <meta name="theme-color" content="#111111">

    <meta property="og:site_name" content="ProMarkah">
    <meta property="og:title" content="ProMarkah — Sistem Pengurusan Pertandingan Silat">
    <meta property="og:description" content="Platform digital untuk pengurusan pertandingan, penjurian, dan penilaian markah silat secara masa nyata.">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="ms_MY">
    <meta property="og:url" content="https://www.prosilat.net/">
    <meta property="og:image" content="https://www.prosilat.net/img/logo_silat_1.png">
    <meta property="og:image:width" content="403">
    <meta property="og:image:height" content="216">

    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="ProMarkah — Sistem Pengurusan Pertandingan Silat">
    <meta name="twitter:description" content="Platform digital untuk pengurusan pertandingan, penjurian, dan penilaian markah silat secara masa nyata.">
    <meta name="twitter:image" content="https://www.prosilat.net/img/logo_silat_1.png">

    <!-- Browser tab icon -->
    <link rel="icon" type="image/png" href="img/logo_silat_1.png">
    <link rel="apple-touch-icon" href="img/logo_silat_1.png">

    <!--
    ===================================================================
    PERFORMANCE: Non‑blocking Google Fonts
    - Preload the stylesheet, then apply on load (no render blocking)
    ===================================================================
    -->
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Barlow:wght@400;500;700;900&display=swap" onload="this.onload=null;this.rel='stylesheet'">
    <noscript>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Barlow:wght@400;500;700;900&display=swap">
    </noscript>

    <!-- Preconnect for faster font origin connection -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <!--
    ===================================================================
    PERFORMANCE: Preload only the desktop hero image (LCP)
    - media="(min-width: 1024px)" ensures mobile devices do NOT preload the large image
    ===================================================================
    -->
    <link rel="preload" as="image" href="img/promarkah.webp" type="image/webp" media="(min-width: 1024px)">

    <!-- Optimised Tailwind CSS (purged) -->
    <link rel="stylesheet" href="output.css?v=<?= @filemtime(__DIR__ . '/output.css') ?: time() ?>">

    <style>
        @keyframes fadeIn { from { opacity: 0; transform: scale(1.02); } to { opacity: 1; transform: scale(1); } }
        @keyframes fadeSlide { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .visually-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        /* This project's output.css is a stale, pre-built/purged Tailwind
           file (no build pipeline exists to regenerate it) — several
           classes used on this page (bg-[#111], lg:block, lg:w-[55%],
           lg:w-[45%], max-w-[400px], and both shadow-[...] arbitrary
           values) were confirmed MISSING from it and therefore do
           nothing. Re-declared here as plain CSS so the hero/form split
           and background are guaranteed correct regardless of what's
           actually compiled. */
        /* Tailwind's overflow-hidden class (still on <body> below) sets
           overflow:hidden on BOTH axes. On mobile this page's stacked
           content is taller than the viewport, so that clips the bottom
           of the page (the KEHADIRAN button) with literally no way to
           scroll down to it — not an image bleeding through, the page
           itself refusing to scroll. Only the horizontal axis needs to
           stay clipped (for the hero image's scale/animate transform not
           to cause a horizontal scrollbar on desktop); vertical scroll
           must always be allowed. */
        body.pm-login-body {
            background: #111 !important;
            overflow-x: hidden !important;
            overflow-y: auto !important;
        }
        .pm-login-hero { display: none !important; width: 0 !important; }
        /* output.css (stale/purged Tailwind — see note above) was dropping
           min-height/flex/justify-content on this panel, so on tall
           viewports (tablets in portrait) the card hugged the top with a
           dead white gap below instead of filling/centering in the
           screen. Guarantee it here. */
        .pm-login-form-panel {
            width: 100% !important;
            /* No height/max-height of its own — it stretches (flex default
               align-self: stretch) to fill .pm-login-outer below, which is
               the single source of truth for the viewport-height boundary.
               overflow: hidden is still required though: transform: scale()
               (fitLoginCard, further down) shrinks the card's visual
               footprint but NOT its DOM layout box, so without this the
               unscaled box would overflow/scroll before (or if) the script
               runs. */
            overflow: hidden !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: center !important;
            align-items: center !important;
        }
        .pm-login-form-inner { transform-origin: center center; }
        /* Tailwind's min-h-screen on this outer wrapper is a plain 100vh —
           on mobile that can be taller than the panel's 100dvh above,
           leaving a thin empty strip below the panel that the body's
           overflow-y:auto would then let you scroll to. Pin it to the same
           real-visible-height value so the whole page is exactly one
           screen, nothing more. */
        .pm-login-outer {
            min-height: 100vh !important;
            min-height: 100dvh !important;
            max-height: 100vh !important;
            max-height: 100dvh !important;
            overflow: hidden !important;
        }
        @media (min-width: 1024px) {
            .pm-login-hero { display: block !important; width: 55% !important; }
            .pm-login-form-panel { width: 45% !important; }
            /* The actual bug: a FIXED px cap (400, then 560) can never fill
               a panel whose own width is a VIEWPORT PERCENTAGE (45%) — on
               any real monitor the panel is much wider than any reasonable
               fixed cap, so the card is still a small island with huge dead
               margins either side no matter how many more pixels you add to
               a static number. Sizing the card itself as a percentage of
               its panel (with a generous upper cap only to stop it getting
               silly on an ultrawide) is what actually fills the container. */
            .pm-login-form-inner { max-width: 640px !important; width: 88% !important; }
            .pm-login-logo-img { height: 76px !important; }
            .pm-login-arabic { font-size: 1.75rem !important; }
            .pm-login-h1 { font-size: 1.75rem !important; }
            .pm-login-h2 { font-size: 2.25rem !important; }
            .pm-login-form input {
                font-size: 1rem !important;
                padding-top: 28px !important;
                padding-bottom: 14px !important;
            }
            .pm-login-form label { font-size: 1rem !important; }
            .pm-login-submit {
                font-size: 1rem !important;
                padding-top: 16px !important;
                padding-bottom: 16px !important;
            }
        }
        .pm-login-form-inner { max-width: 400px; }
        /* Tablet portrait (e.g. iPad 768–1023px) was falling into the same
           "compact for phones" rules below, which shrink fonts/padding and
           cap the form at 400px — on a much wider tablet screen that reads
           as a tiny card floating in empty space, like the page got zoomed
           out. Give this range its own wider card so it actually fills the
           screen instead. */
        @media (min-width: 641px) and (max-width: 1023px) {
            .pm-login-form-inner { max-width: 560px !important; }
        }
        .pm-login-hero img {
            box-shadow: 10px 0 30px rgba(0,0,0,0.8);
        }
        .pm-login-form-panel {
            box-shadow: -15px 0 40px rgba(0,0,0,0.5);
        }
        /* Deliberately styled to NOT look like the text inputs above it
           (previously identical white-fill + light-border recipe, which read
           as another field to type into) — filled gray "secondary button"
           look instead, so it clearly reads as a navigation action. */
        .pm-login-attendance {
            border: none !important;
            border-radius: 0.75rem !important;
            background: #e5e7eb !important;
            font-weight: 700 !important;
        }
        .pm-login-attendance:hover {
            background: #d1d5db !important;
        }

        /* ── Fit everything on one screen on mobile/tablet — no scrolling ──
           Below 1024px the hero image is hidden and this becomes a single
           tall column; the goal here is compacting every gap/size enough
           that the whole form (logo through footer) fits within a typical
           phone viewport height without needing to scroll to reach
           KEHADIRAN. Uses !important throughout since these override
           existing Tailwind utility classes already on each element. */
        @media (max-width: 640px) {
            .pm-login-form-panel { padding-top: 20px !important; padding-bottom: 20px !important; }
            .pm-login-title-block { margin-bottom: 14px !important; }
            .pm-login-logo-box { padding: 6px !important; margin-bottom: 8px !important; }
            .pm-login-logo-img { height: 40px !important; }
            .pm-login-arabic { font-size: 1rem !important; margin-bottom: 0 !important; }
            .pm-login-h1 { font-size: 1rem !important; margin-bottom: 0 !important; }
            .pm-login-h2 { font-size: 1.3rem !important; margin-bottom: 0 !important; padding-bottom: 0 !important; }
            .pm-login-form.space-y-5 > * + * { margin-top: 10px !important; }
            /* Was 14px/6px — that aggressive, lopsided shrink both made the
               box look tiny and pushed the typed text visibly above center
               (the floating-label layout needs headroom above the text, not
               almost none below it). Closer to the desktop 24px/10px split
               keeps the field a comfortable tap target and the text
               actually centered, while still trimming some height. */
            .pm-login-form input { padding-top: 20px !important; padding-bottom: 12px !important; }
            .pm-login-submit { margin-top: 14px !important; padding-top: 10px !important; padding-bottom: 10px !important; }
            .pm-login-divider { margin-top: 14px !important; margin-bottom: 14px !important; }
            .pm-login-quick-label { margin-bottom: 8px !important; }
            .pm-login-quick-buttons { gap: 8px !important; }
            .pm-login-attendance-divider { margin-top: 12px !important; margin-bottom: 8px !important; }
            .pm-login-footer { margin-top: 10px !important; }
        }
    </style>

    <?php if (GOOGLE_CLIENT_ID !== '' && $step === 1): ?>
        <script src="https://accounts.google.com/gsi/client?hl=ms" async defer></script>
        <script>
            // Chrome's back/forward cache can restore this page from memory
            // even with Cache-Control: no-store, leaving the Google button
            // wired to a stale/already-consumed nonce from $_SESSION. That
            // causes the first sign-in attempt after a bfcache restore to
            // fail nonce validation (oauth_google_callback.php) and bounce
            // back to login — forcing a real reload keeps the nonce fresh.
            window.addEventListener('pageshow', function (event) {
                if (event.persisted) {
                    window.location.reload();
                }
            });
        </script>
    <?php endif; ?>
</head>
<body class="pm-login-body min-h-screen overflow-hidden font-sans text-gray-800">

    <div class="pm-login-outer flex min-h-screen w-full">

        <!--
        ===================================================================
        PERFORMANCE: Responsive Hero Image
        - Serves mobile-optimised WebP/PNG on screens ≤768px
        - Falls back gracefully if mobile variants are missing
        ===================================================================
        -->
        <div class="pm-login-hero relative overflow-hidden animate-[fadeIn_0.8s_ease-out] bg-black">
            <picture>
                <!-- Mobile (≤768px) -->
                <source media="(max-width: 768px)" srcset="img/promarkah-mobile.webp" type="image/webp">
                <source media="(max-width: 768px)" srcset="img/promarkah-mobile.png" type="image/png">
                <!-- Desktop (default) -->
                <source srcset="img/promarkah.webp" type="image/webp">
                <img src="img/promarkah.png"
                     alt="ProMarkah Poster"
                     width="1080"
                     height="1440"
                     fetchpriority="high"
                     decoding="async"
                     class="absolute inset-0 w-full h-full object-cover object-center z-10">
            </picture>
        </div>

        <div class="pm-login-form-panel flex flex-col justify-center items-center relative px-6 py-12 z-20 bg-gray-50 border-l border-gray-200">

            <div class="pm-login-form-inner w-full z-10 animate-[fadeSlide_0.5s_ease-out]">

                <div class="pm-login-title-block text-center mb-8 flex flex-col items-center">
                    <div class="pm-login-logo-box p-3 rounded-xl mb-8 hover:scale-105 transition-transform duration-300" style="background:#111;">
                        <picture>
                            <source srcset="img/logo_silat_1.webp" type="image/webp">
                            <img src="img/logo_silat_1.png"
                                 alt="ProMarkah Logo"
                                 width="64"
                                 height="64"
                                 fetchpriority="high"
                                 decoding="async"
                                 class="pm-login-logo-img h-16 w-auto">
                        </picture>
                    </div>
                    <p class="pm-login-arabic text-2xl font-normal text-gray-500 tracking-widest mb-1" dir="rtl">ڤروسيلت</p>
                    <h1 class="pm-login-h1 text-2xl font-bold text-gray-800 uppercase tracking-widest mb-1">PROSILAT</h1>
                    <h2 class="pm-login-h2 text-3xl text-gray-900 font-black uppercase tracking-widest mb-2 pb-2">PROMARKAH</h2>
                </div>

                <?php if ($error): ?>
                    <div class="bg-red-50 border border-red-200 text-red-600 text-sm font-medium px-4 py-3 rounded-lg mb-8 text-center shadow-sm">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($oauth_error): ?>
                    <div class="bg-red-50 border border-red-200 text-red-600 text-sm font-medium px-4 py-3 rounded-lg mb-8 text-center shadow-sm">
                        <?= htmlspecialchars($oauth_error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($step === 1): ?>
                    <form method="POST" class="pm-login-form space-y-5">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                        <div class="visually-hidden" aria-hidden="true">
                            <label for="website_url">Biarkan medan ini kosong jika anda manusia</label>
                            <input type="text" id="website_url" name="website_url" tabindex="-1" autocomplete="off">
                        </div>

                        <div class="relative">
                            <input type="text" id="username" name="username"
                                class="block px-4 pb-2.5 pt-6 w-full text-base text-gray-900 bg-white rounded-xl border border-gray-300 appearance-none focus:outline-none focus:border-pmRed focus:ring-1 focus:ring-pmRed peer transition-all shadow-sm"
                                placeholder=" " required autocomplete="off">
                            <label for="username"
                                class="absolute text-base text-gray-700 duration-300 transform -translate-y-3 scale-75 top-4 z-10 origin-[0] left-4 peer-focus:text-pmRed peer-placeholder-shown:scale-100 peer-placeholder-shown:translate-y-0 peer-focus:scale-75 peer-focus:-translate-y-3 select-none pointer-events-none">
                                Nama Pengguna
                            </label>
                        </div>

                        <div class="relative">
                            <input type="password" id="password" name="password"
                                class="block px-4 pr-12 pb-2.5 pt-6 w-full text-base text-gray-900 bg-white rounded-xl border border-gray-300 appearance-none focus:outline-none focus:border-pmRed focus:ring-1 focus:ring-pmRed peer transition-all shadow-sm"
                                placeholder=" " required>
                            <label for="password"
                                class="absolute text-base text-gray-700 duration-300 transform -translate-y-3 scale-75 top-4 z-10 origin-[0] left-4 peer-focus:text-pmRed peer-placeholder-shown:scale-100 peer-placeholder-shown:translate-y-0 peer-focus:scale-75 peer-focus:-translate-y-3 select-none pointer-events-none">
                                Kata Laluan
                            </label>
                            <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 flex items-center pr-4 text-gray-400 hover:text-pmRed focus:outline-none transition-colors">
                                <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>

                        <button type="submit"
                            class="pm-login-submit w-full bg-pmRed hover:bg-[#B01F1F] active:scale-[0.98] text-white font-bold py-3.5 px-4 rounded-xl transition-all duration-200 mt-10 tracking-widest shadow-[0_8px_24px_-4px_rgba(214,40,40,0.3)] hover:shadow-[0_12px_28px_-6px_rgba(214,40,40,0.4)] border border-transparent uppercase text-sm">
                            LOG MASUK
                        </button>
                    </form>

                    <?php if (GOOGLE_CLIENT_ID !== '' || APPLE_CLIENT_ID !== ''): ?>
                    <div class="pm-login-divider" style="position:relative; display:flex; align-items:center; justify-content:center; margin-top:32px; margin-bottom:32px;">
                        <div style="position:absolute; left:0; right:0; height:1px; background:#d1d5db;"></div>
                        <span style="position:relative; background:#f9fafb; padding:0 12px; font-size:12px; color:#374151; font-weight:500;">atau</span>
                    </div>

                    <p class="pm-login-quick-label" style="text-align:center; font-size:13px; color:#1f2937; font-weight:700; text-transform:none; letter-spacing:0.01em; margin:0 0 16px;">
                        Log masuk pantas untuk juri
                    </p>

                    <div class="pm-login-quick-buttons" style="display:flex; flex-direction:column; gap:12px;">
                        <?php if (GOOGLE_CLIENT_ID !== ''): ?>
                        <!--
                        Google's own renderButton() only speaks the language its
                        ?hl= script param resolves to (falls back to English for
                        locales it doesn't support) and ignores our card's
                        pill/border styling. google.accounts.id.prompt() isn't a
                        substitute — it's the One Tap flow, which Google
                        throttles/suppresses after a dismissal and won't reliably
                        open on a button click.
                        So the REAL Google button is rendered here but made fully
                        transparent and stretched to cover this whole box — clicks
                        land on the genuine Google button (a real, working,
                        cross-origin iframe click that can't be faked with JS), so
                        the actual sign-in flow behaves exactly as Google intends.
                        Behind it (z-index below) sits a purely visual, pointer-events:none
                        div with our own Malay label and matching border, which is
                        all the user actually sees.
                        -->
                        <div class="relative w-full" style="height:48px;">
                            <div id="googleBtnContainer" class="absolute inset-0 w-full h-full" style="opacity:0; overflow:hidden; z-index:2;"></div>
                            <div class="absolute inset-0 w-full h-full flex items-center justify-center gap-3 bg-white text-gray-700 font-bold rounded-xl text-sm shadow-sm"
                                style="border:1px solid #d1d5db; z-index:1; pointer-events:none;">
                                <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
                                    <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.9 29.3 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.1 8 3l5.7-5.7C34.5 6.1 29.5 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.7-.4-3.5z"/>
                                    <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.6 15.9 18.9 13 24 13c3.1 0 5.8 1.1 8 3l5.7-5.7C34.5 6.1 29.5 4 24 4c-7.7 0-14.4 4.4-17.7 10.7z"/>
                                    <path fill="#4CAF50" d="M24 44c5.4 0 10.3-2.1 14-5.4l-6.5-5.5C29.4 34.7 26.8 36 24 36c-5.3 0-9.7-3.1-11.3-7.5l-6.6 5.1C9.5 39.6 16.2 44 24 44z"/>
                                    <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.3-2.3 4.3-4.2 5.7l6.5 5.5C39.9 37.6 44 32.4 44 24c0-1.3-.1-2.7-.4-3.5z"/>
                                </svg>
                                Log masuk dengan Google
                            </div>
                        </div>
                        <script>
                            // Uses the default popup/FedCM flow (NOT ux_mode:
                            // 'redirect') — redirect mode requires registering a
                            // "redirect_uri" in Google Cloud Console separately
                            // from "Authorized JavaScript origins", which this
                            // app isn't set up for. Popup mode needs no redirect
                            // URI at all; the ID token comes back via `callback`
                            // here in JS, which then forwards it to
                            // oauth_google_callback.php as a normal same-origin
                            // form POST (that endpoint already expects `credential`
                            // in $_POST either way).
                            window.addEventListener('load', function () {
                                if (!window.google?.accounts?.id) return;
                                const container = document.getElementById('googleBtnContainer');
                                google.accounts.id.initialize({
                                    client_id: <?= json_encode(GOOGLE_CLIENT_ID) ?>,
                                    nonce: <?= json_encode($_SESSION['google_oauth_nonce'] ?? '') ?>,
                                    callback: function (response) {
                                        const form = document.createElement('form');
                                        form.method = 'POST';
                                        form.action = 'oauth_google_callback.php';
                                        const input = document.createElement('input');
                                        input.type = 'hidden';
                                        input.name = 'credential';
                                        input.value = response.credential;
                                        form.appendChild(input);
                                        document.body.appendChild(form);
                                        form.submit();
                                    }
                                });
                                google.accounts.id.renderButton(container, {
                                    type: 'standard',
                                    shape: 'pill',
                                    theme: 'outline',
                                    text: 'signin_with',
                                    size: 'large',
                                    logo_alignment: 'center',
                                    // Google's API hard-caps `width` at 400px and offers
                                    // no way to set an exact height (size presets only,
                                    // 'large' ≈ 40px) — so on wider cards (tablet's
                                    // 560px form) the real button (invisible, but still
                                    // the actual click target) rendered visibly smaller
                                    // than this container. The resize observer below
                                    // scales it post-render to exactly match/cover the
                                    // container instead, so every part of the visible
                                    // overlay is actually clickable.
                                    width: Math.min(container.offsetWidth, 400)
                                });

                                const fitGoogleButton = function () {
                                    const rendered = container.firstElementChild;
                                    if (!rendered || !rendered.offsetWidth || !rendered.offsetHeight) return;
                                    const scaleX = container.offsetWidth / rendered.offsetWidth;
                                    const scaleY = container.offsetHeight / rendered.offsetHeight;
                                    rendered.style.transform = 'scale(' + scaleX + ',' + scaleY + ')';
                                    rendered.style.transformOrigin = 'center center';
                                };
                                new MutationObserver(fitGoogleButton).observe(container, { childList: true, subtree: true });
                                window.addEventListener('resize', fitGoogleButton);
                            });
                        </script>
                        <?php endif; ?>

                        <?php if (APPLE_CLIENT_ID !== ''): ?>
                        <a href="oauth_apple_start.php"
                            class="w-full flex items-center justify-center gap-3 bg-black text-white font-bold py-3 px-4 rounded-xl transition-all duration-200 text-sm shadow-sm">
                            <svg width="16" height="16" viewBox="0 0 384 512" fill="currentColor" aria-hidden="true"><path d="M318.7 268.7c-.2-36.7 16.4-64.4 50-84.8-18.8-26.9-47.2-41.7-84.7-44.6-35.5-2.8-74.3 20.7-88.5 20.7-15 0-49.4-19.7-76.4-19.7C63.3 141 4 184.8 4 273.5q0 39.3 14.4 81.2c12.8 36.7 59 126.7 107.2 125.2 25.2-.6 43-17.9 75.8-17.9 31.8 0 48.3 17.9 76.4 17.9 48.6-.7 90.4-82.5 102.6-119.3-65.2-30.7-61.7-90-61.7-91.9zm-56.6-164.2c27.3-32.4 24.8-61.9 24-72.5-24.1 1.4-52 16.4-67.9 34.9-17.5 19.8-27.8 44.3-25.6 71.9 26.1 2 49.9-11.4 69.5-34.3z"/></svg>
                            Log Masuk dengan Apple
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="pm-login-attendance-divider" style="position:relative; display:flex; align-items:center; justify-content:center; margin-top:24px; margin-bottom:16px;">
                        <div style="position:absolute; left:0; right:0; height:1px; background:#e5e7eb;"></div>
                    </div>

                    <a href="attendance.php"
                        class="pm-login-attendance w-full flex items-center justify-center text-gray-700 hover:text-pmRed font-semibold transition-all duration-200 text-xs uppercase tracking-widest"
                        style="height:44px; border-radius:0.75rem;">
                        Kehadiran
                    </a>

                <?php elseif ($step === 2): ?>
                    <div class="text-center mb-6">
                        <p class="text-sm text-gray-600 font-medium">Selamat datang, Juri. Sila pilih nama anda dan masukkan PIN.</p>
                    </div>
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                        <div class="relative">
                            <select id="judge_id" name="judge_id" required
                                class="block px-4 pb-2.5 pt-6 w-full text-base text-gray-900 bg-white rounded-xl border border-gray-300 appearance-none focus:outline-none focus:border-pmRed focus:ring-1 focus:ring-pmRed peer transition-all shadow-sm">
                                <option value="" disabled selected></option>
                                <?php foreach ($judges_list as $j): ?>
                                    <option value="<?= $j['id'] ?>"><?= htmlspecialchars($j['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label for="judge_id"
                                class="absolute text-base text-gray-700 duration-300 transform -translate-y-3 scale-75 top-4 z-10 origin-[0] left-4 peer-focus:text-pmRed peer-placeholder-shown:scale-100 peer-placeholder-shown:translate-y-0 peer-focus:scale-75 peer-focus:-translate-y-3 select-none pointer-events-none">
                                Pilih Nama Anda
                            </label>
                            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-4 text-gray-500">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M9.293 12.95l.707.707L15.657 8l-1.414-1.414L10 10.828 5.757 6.586 4.343 8z"/></svg>
                            </div>
                        </div>

                        <div class="relative">
                            <input type="password" id="pin" name="pin"
                                class="block px-4 pr-12 pb-2.5 pt-6 w-full text-center tracking-[0.5em] text-2xl text-gray-900 bg-white rounded-xl border border-gray-300 appearance-none focus:outline-none focus:border-pmRed focus:ring-1 focus:ring-pmRed peer transition-all shadow-sm"
                                placeholder=" " required autofocus>
                            <label for="pin"
                                class="absolute text-base text-gray-700 duration-300 transform -translate-y-3 scale-75 top-4 z-10 origin-[0] left-4 peer-focus:text-pmRed peer-placeholder-shown:scale-100 peer-placeholder-shown:translate-y-0 peer-focus:scale-75 peer-focus:-translate-y-3 select-none pointer-events-none">
                                PIN
                            </label>
                            <button type="button" id="togglePin" class="absolute inset-y-0 right-0 flex items-center pr-4 text-gray-400 hover:text-pmRed focus:outline-none transition-colors">
                                <svg id="eyeIconPin" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>

                        <button type="submit"
                            class="w-full bg-pmRed hover:bg-[#B01F1F] active:scale-[0.98] text-white font-bold py-3.5 px-4 rounded-xl transition-all duration-200 mt-6 tracking-widest shadow-[0_4px_15px_rgba(214,40,40,0.25)] hover:shadow-[0_6px_20px_rgba(214,40,40,0.4)] border border-transparent uppercase text-sm">
                            SAHKAN PIN
                        </button>

                        <div class="text-center mt-6">
                            <a href="logout.php" class="text-xs text-gray-500 hover:text-gray-800 transition-colors border-b border-transparent hover:border-gray-800 pb-0.5 font-medium">Batal & Kembali</a>
                        </div>
                    </form>
                <?php endif; ?>

                <div class="pm-login-footer mt-12 text-center">
                    <div class="text-[11px] text-gray-700 font-medium uppercase tracking-widest">
                        &copy; <?= date('Y') ?> ProMarkah
                    </div>
                </div>

            </div>
        </div>

    </div>

    <script>
        function setupPasswordToggle(toggleBtnId, inputId, iconId) {
            const toggleBtn = document.getElementById(toggleBtnId);
            if (!toggleBtn) return;
            toggleBtn.addEventListener('click', function () {
                const input = document.getElementById(inputId);
                const icon = document.getElementById(iconId);
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
                } else {
                    input.type = 'password';
                    icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
                }
            });
        }
        setupPasswordToggle('togglePassword', 'password', 'eyeIcon');
        setupPasswordToggle('togglePin', 'pin', 'eyeIconPin');

        // ── GUARANTEED fit-to-screen (no scrolling, any device height) ──
        // .pm-login-form-panel has no height of its own — it stretches to
        // fill .pm-login-outer, which is hard-clipped to the real visible
        // viewport (see CSS). Breakpoint media queries can only ever guess
        // at specific device sizes and still leave gaps — this instead
        // directly MEASURES the card's actual rendered height against that
        // available height and shrinks it with transform: scale() until it
        // fits, so it's correct for literally any viewport height,
        // including ones no breakpoint anticipated.
        function fitLoginCard() {
            const panel = document.querySelector('.pm-login-form-panel');
            const inner = document.querySelector('.pm-login-form-inner');
            if (!panel || !inner) return;

            // Reset to natural size first so scrollHeight reflects the
            // card's real, unscaled content height rather than whatever
            // scale was last applied.
            inner.style.transform = 'scale(1)';

            const availableHeight = panel.clientHeight;
            const neededHeight = inner.scrollHeight;
            // Small safety margin (0.97) so the card never touches the
            // panel's exact edge — reads as cramped otherwise. No lower
            // floor on the scale itself: this must fit no matter how short
            // the viewport is, by design.
            const scale = Math.min(1, (availableHeight / neededHeight) * 0.97);

            inner.style.transform = 'scale(' + scale + ')';
        }

        window.addEventListener('DOMContentLoaded', fitLoginCard);
        window.addEventListener('load', fitLoginCard); // re-check after images/fonts settle
        window.addEventListener('resize', fitLoginCard);
        // Mobile browsers fire 'resize' late (or not at all) when the
        // address bar shows/hides on scroll — orientationchange plus a
        // short delayed re-check covers that gap.
        window.addEventListener('orientationchange', function () {
            setTimeout(fitLoginCard, 300);
        });
    </script>
</body>
</html>