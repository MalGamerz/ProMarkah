<?php
/**
 * layout.php — ProMarkah v2 Dashboard Shell
 * ------------------------------------------
 * Include at the TOP of every dashboard page BEFORE any HTML output.
 * Renders: <head>, fixed header, fixed sidebar, opens <main id="pm-main">.
 * You close </main></body></html> yourself.
 *
 * Required session vars:
 * $_SESSION['role']      — 'pic' | 'recorder' | 'judge'
 * $_SESSION['username']  — display name
 *
 * Set before including:
 * $pm_page = 'attendance';   // highlights the active nav link
 */
 
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Several pages (pic.php, pic_students.php, etc.) call session_write_close()
// before including this file, to avoid blocking concurrent AJAX requests
// (notification polling) while the page renders. But that meant any write
// to $_SESSION here — like saving the "Siri Aktif" choice below — was
// silently dropped: PHP won't persist changes made after the session was
// already closed. Reopen it just for this block, then restore the caller's
// original closed state so their performance intent is preserved.
$pm_session_was_closed = (session_status() !== PHP_SESSION_ACTIVE);
if ($pm_session_was_closed) {
    session_start();
}

$pm_role = $_SESSION["role"] ?? "guest";

// Centralized CSRF token — generated here (not per-page) so it's always
// available for layout-level AJAX (notifications) regardless of whether
// the including page set one up before or after requiring layout.php.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$pm_csrf = $_SESSION['csrf_token'];

// ── CATCH & SAVE SIRI CHANGE GLOBALLY ──────────────────────────────────────
// Previously this did a header("Location: ...") redirect to strip ?siri_id=
// from the URL, which forced the browser to make a SECOND full request —
// re-running every bit of PHP the calling page does before it even includes
// layout.php (schema-check queries, dashboard aggregates, etc.). That doubled
// the page's cost on every single siri switch, which is what made the
// sidebar dropdown feel like it hung. Instead, just continue rendering this
// same request and clean the URL bar client-side afterwards (no reload).
$pm_clean_siri_url = null;
if (isset($_GET['siri_id'])) {
    if ($_GET['siri_id'] === '') {
        $_SESSION['active_siri_id'] = 0; // 0 means "Semua Siri"
    } elseif (is_numeric($_GET['siri_id'])) {
        $_SESSION['active_siri_id'] = (int)$_GET['siri_id'];
    }
    $pm_clean_siri_url = strtok($_SERVER["REQUEST_URI"], '?');
}

if ($pm_session_was_closed) {
    session_write_close();
}

$is_simple_mode = ($pm_page === "attendance" && $pm_role !== "pic" && $pm_role !== "admin");
$pm_page = $pm_page ?? "";

// Dynamically pull the actual name for ANY role.
// It checks common session variables where the real name might be stored before falling back to the login ID.
$pm_username =
    $_SESSION["name"] ??
    ($_SESSION["full_name"] ??
        ($_SESSION["judge_name"] ?? ($_SESSION["username"] ?? "Pengguna"))); // Changed from "User"

// Judge profile photo for the header avatar (see judge_settings.php).
// Guarded with a column-existence check since a judge could be mid-session
// from before that page's self-healing ALTER TABLE has ever run.
$pm_judge_photo = null;
if ($pm_role === 'judge' && isset($_SESSION['user_id']) && isset($conn)) {
    $colCheck = $conn->query("SHOW COLUMNS FROM judges LIKE 'photo_path'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $photoStmt = $conn->prepare("SELECT photo_path FROM judges WHERE id = ? LIMIT 1");
        $photoStmt->bind_param("i", $_SESSION['user_id']);
        $photoStmt->execute();
        $photoRow = $photoStmt->get_result()->fetch_assoc();
        $photoStmt->close();
        $pm_judge_photo = $photoRow['photo_path'] ?? null;
    }
}

// ── Role meta ──────────────────────────────────────────────────────────────
$role_meta = [
    "pic"      => ["label" => "PIC",      "role_class" => "pm-role-pic"],
    "recorder" => ["label" => "Pencatat", "role_class" => "pm-role-recorder"], // Changed from "Recorder"
    "judge"    => ["label" => "Juri",     "role_class" => "pm-role-judge"], // Changed from "Judge"
    "admin"    => ["label" => "Pentadbir", "role_class" => "pm-role-pic"], // Changed from "Admin"
    "guest"    => ["label" => "Urusetia (Tetamu)", "role_class" => "pm-role-recorder"], // Changed from "Urusetia (Guest)"
];

$meta = $role_meta[$pm_role] ?? [
    "label" => "Ahli", // Changed from "Member"
    "role_class" => "pm-role-recorder",
];

// ── Page title ─────────────────────────────────────────────────────────────
$page_titles = [
    "recorder" => "Sistem Kehadiran",
    "judge"    => "Panel Juri",
    "pic"      => "Papan Pemuka PIC",
    "admin"    => "Panel Pentadbir", // Changed from "Admin Panel"
];

$pm_title = $page_titles[$pm_role] ?? "ProMarkah";

// Inline SVG icon library (pm_icon()) — static data, split into its own file.
require_once __DIR__ . '/layout_icons.php';

// ── Nav items per role (Refactored to typed arrays) ────────────────────────
$nav_items = [];

// Guest (not logged in) — show attendance only
if ($pm_role === "guest") {
    $nav_items = [
        [
            "type"  => "link",
            "url"   => "attendance.php",
            "icon"  => "layout-dashboard",
            "label" => "Kehadiran",
            "id"    => "attendance",
        ],
    ];
    if (isset($conn)) {
        $pm_sessions_rs = $conn->query("SELECT se.*, si.siri_name FROM sessions se LEFT JOIN siri si ON se.siri_id = si.siri_id ORDER BY se.session_name");
        if ($pm_sessions_rs && $pm_sessions_rs->num_rows > 0) {
            $nav_items[] = ["type" => "divider"];
            $nav_items[] = ["type" => "label", "text" => "SIDANG"];
            while ($pm_session_row = $pm_sessions_rs->fetch_assoc()) {
                $nav_items[] = [
                    "type"  => "link",
                    "url"   => "attendance.php?session_id=" . $pm_session_row["session_id"],
                    "icon"  => "bookmark",
                    "label" => $pm_session_row["session_name"],
                    "sublabel" => $pm_session_row["siri_name"] ?? null,
                    "id"    => "session_" . $pm_session_row["session_id"],
                ];
            }
        }
    }
}

if ($pm_role === "recorder") {
    $nav_items = [
        [
            "type" => "link",
            "url" => "attendance.php",
            "icon" => "layout-dashboard",
            "label" => "Papan Pemuka",
            "id" => "attendance",
        ],
        [
            "type" => "link",
            "url" => "attendance.php?view=all_present",
            "icon" => "check-square",
            "label" => "Senarai Penuh",
            "id" => "all_present",
        ],
    ];
    if (isset($conn)) {
        $pm_sessions_rs = $conn->query(
            "SELECT se.*, si.siri_name FROM sessions se LEFT JOIN siri si ON se.siri_id = si.siri_id ORDER BY se.session_name",
        );
        if ($pm_sessions_rs && $pm_sessions_rs->num_rows > 0) {
            $nav_items[] = ["type" => "divider"];
            $nav_items[] = ["type" => "label", "text" => "SIDANG"];
            while ($pm_session_row = $pm_sessions_rs->fetch_assoc()) {
                $nav_items[] = [
                    "type" => "link",
                    "url" => "attendance.php?session_id=" . $pm_session_row["session_id"],
                    "icon" => "bookmark",
                    "label" => $pm_session_row["session_name"],
                    "sublabel" => $pm_session_row["siri_name"] ?? null,
                    "id" => "session_" . $pm_session_row["session_id"],
                ];
            }
        }
    }
}

if ($pm_role === "pic") {
    $nav_items = [
        ["type" => "label", "text" => "PAPAN PEMUKA"],
        [
            "type" => "link",
            "url" => "pic.php",
            "icon" => "home",
            "label" => "Ringkasan",
            "id" => "pic",
        ],
        ["type" => "divider"],

        ["type" => "label", "text" => "DATA"],
        [
            "type" => "link",
            "url" => "pic_students.php",
            "icon" => "users",
            "label" => "Pelajar",
            "id" => "students",
        ],
        [
            "type" => "link",
            "url" => "pic_roster_check.php",
            "icon" => "check-square",
            "label" => "Semak Senarai",
            "id" => "roster_check",
        ],
        [
            "type" => "link",
            "url" => "pic_schools.php",
            "icon" => "school",
            "label" => "Cawangan",
            "id" => "schools",
        ],
        [
            "type" => "link",
            "url" => "pic_siri.php",
            "icon" => "git-branch",
            "label" => "Siri",
            "id" => "siri",
        ],
        [
            "type" => "link",
            "url" => "pic_sessions.php",
            "icon" => "calendar",
            "label" => "Sidang",
            "id" => "sessions",
        ],
        [
            "type" => "link",
            "url" => "pic_judges.php",
            "icon" => "scale",
            "label" => "Juri",
            "id" => "judges",
        ],
        ["type" => "divider"],

        ["type" => "label", "text" => "STRUKTUR"],
        [
            "type" => "link",
            "url" => "pic_levels.php",
            "icon" => "target",
            "label" => "Peringkat",
            "id" => "levels",
        ],
        [
            "type" => "link",
            "url" => "pic_tests.php",
            "icon" => "flask",
            "label" => "Ujian",
            "id" => "tests",
        ],
        [
            "type" => "link",
            "url" => "pic_criteria.php",
            "icon" => "list",
            "label" => "Kriteria",
            "id" => "criteria",
        ],
        [
            "type" => "link",
            "url" => "test_preview.php",
            "icon" => "file-text",
            "label" => "Papar Ujian",
            "id" => "test_preview",
        ],
        ["type" => "divider"],

        ["type" => "label", "text" => "PERTANDINGAN"],
        [
            "type" => "link",
            "url" => "pic_groups.php",
            "icon" => "git-branch",
            "label" => "Kumpulan Juri",
            "id" => "groups",
        ],
        [
            "type" => "link",
            "url" => "pic_manual_marks.php",
            "icon" => "pen-line",
            "label" => "Isi Markah",
            "id" => "manual_marks",
        ],
        [
            "type" => "link",
            "url" => "pic_view_marks.php",
            "icon" => "bar-chart-2",
            "label" => "Lihat Markah",
            "id" => "view_marks",
        ],
        ["type" => "divider"],

        ["type" => "label", "text" => "LAPORAN"],
        [
            "type" => "link",
            "url" => "pic_directory.php",
            "icon" => "folder-open",
            "label" => "Direktori",
            "id" => "directory",
        ],
        [
            "type" => "link",
            "url" => "pic_medal_settings.php",
            "icon" => "medal",
            "label" => "Tetapan Pingat",
            "id" => "medal_settings",
        ],
        [
            "type" => "link",
            "url" => "leaderboard.php",
            "icon" => "trophy",
            "label" => "Papan Kedudukan",
            "id" => "leaderboard",
        ],
        [
            "type" => "link",
            "url" => "pic_master_list.php",
            "icon" => "clipboard-list",
            "label" => "Senarai Induk",
            "id" => "master_list",
        ],
        [
            "type" => "link",
            "url" => "manage_attendance.php",
            "icon" => "user-check",
            "label" => "Kehadiran",
            "id" => "attendance",
        ],
        [
            "type" => "link",
            "url" => "pic_cawangan_summary.php",
            "icon" => "building-2",
            "label" => "Cawangan & Peringkat",
            "id" => "cawangan_summary",
        ],
    ];
}

if ($pm_role === "judge") {
    $nav_items = [
        [
            "type" => "link",
            "url" => "judge.php",
            "icon" => "scale",
            "label" => "Panel Markah",
            "id" => "judge",
        ],
        [
            "type" => "link",
            "url" => "silibus.php",
            "icon" => "book-open",
            "label" => "Silibus",
            "id" => "silibus",
        ],
        [
            "type" => "link",
            "url" => "leaderboard.php",
            "icon" => "trophy",
            "label" => "Papan Kedudukan",
            "id" => "leaderboard",
        ],
        [
            "type" => "link",
            "url" => "judge_view_marks.php",
            "icon" => "eye",
            "label" => "Lihat Markah",
            "id" => "view_marks",
        ],
    ];
}

if ($pm_role === "admin") {
    $nav_items = [
        ["type" => "label", "text" => "PENTADBIR"], // Changed from "ADMIN"
        [
            "type"  => "link",
            "url"   => "admin.php",
            "icon"  => "shield",
            "label" => "Pengguna", // Changed from "Users"
            "id"    => "admin",
        ],
        [
            "type"  => "link",
            "url"   => "admin_data.php",
            "icon"  => "folder-open",
            "label" => "Data & Eksport", // Changed from "Data & Export"
            "id"    => "admin_data",
        ],
        [
            "type"  => "link",
            "url"   => "admin_logs.php",
            "icon"  => "file-text",
            "label" => "Log & Statistik", // Changed from "Logs & Stats"
            "id"    => "admin_logs",
        ],
    ];

    // Normalise $pm_page so each sub-page highlights the correct sidebar link.
    if (!in_array($pm_page, ["admin", "admin_data", "admin_logs"], true)) {
        $pm_page = "admin";
    }
}

// ── ATTENDANCE PAGE OVERRIDE: simple sidebar for recorder/guest only ───
// PIC/admin land here too (manage_attendance.php also sets $pm_page =
// 'attendance' so the nav highlights "Kehadiran"), but they must keep their
// full sidebar — otherwise clicking Kehadiran trapped them in the
// recorder-style nav with no way back to the rest of the PIC dashboard.
if ($is_simple_mode) {
    $attendance_nav = [
        ["type" => "link", "url" => "attendance.php", "icon" => "layout-dashboard", "label" => "Papan Pemuka", "id" => "attendance"],
        ["type" => "link", "url" => "attendance.php?view=all_present", "icon" => "check-square", "label" => "Senarai Penuh", "id" => "all_present"]
    ];

    // 3. FETCH SESSIONS (Filtered by Active Siri)
    if (isset($conn)) {
        $active_siri_id = (int)($_SESSION["active_siri_id"] ?? 0);
        $siri_query_addon = $active_siri_id > 0 ? "WHERE se.siri_id = $active_siri_id" : "";
        
        $pm_sessions_rs = $conn->query("SELECT se.*, si.siri_name FROM sessions se LEFT JOIN siri si ON se.siri_id = si.siri_id $siri_query_addon ORDER BY se.session_name");

        if ($pm_sessions_rs && $pm_sessions_rs->num_rows > 0) {
            $attendance_nav[] = ["type" => "divider"];
            $attendance_nav[] = ["type" => "label", "text" => "SIDANG"];
            while ($pm_session_row = $pm_sessions_rs->fetch_assoc()) {
                $attendance_nav[] = [
                    "type" => "link",
                    "url" => "attendance.php?session_id=" . $pm_session_row["session_id"],
                    "icon" => "bookmark",
                    "label" => $pm_session_row["session_name"],
                    "sublabel" => $pm_session_row["siri_name"] ?? null,
                    "id" => "session_" . $pm_session_row["session_id"],
                ];
            }
        }
    }

    $nav_items = $attendance_nav;
}
?>

<!DOCTYPE html>
<html lang="ms" data-pm-role="<?= htmlspecialchars($pm_role) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ProMarkah — <?= htmlspecialchars($pm_title) ?></title>

    <!-- SEO: this is a private, login-gated dashboard — keep it out of search results -->
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="ProMarkah — sistem pengurusan dan penilaian markah silat.">

    <!-- Browser tab icon -->
    <link rel="icon" type="image/png" href="img/logo_silat_1.png">
    <link rel="apple-touch-icon" href="img/logo_silat_1.png">

    <!--
        PERFORMANCE: Preload the header logo — it's the LCP element on every
        dashboard page. Browser fetches it at the highest priority before
        rendering even begins. WebP is preferred; PNG is the fallback.
    -->
    <link rel="preload" as="image" href="img/logo_silat_1.webp" type="image/webp">

    <!-- Preconnect reduces DNS + TCP latency for Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <!--
        PERFORMANCE: Google Fonts CSS is loaded as a non-blocking preload
        and swapped to a real stylesheet once fetched, so it never sits on
        the critical render path (a plain <link rel="stylesheet"> here would
        block first paint AND chain into a second cross-origin request for
        the font files). font-display=swap (in the URL) avoids invisible
        text once the font loads. <noscript> covers JS-disabled fallback.
    -->
    <link
        rel="preload" as="style"
        href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap"
        onload="this.onload=null;this.rel='stylesheet'">
    <noscript>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap">
    </noscript>

    <!--
        PERFORMANCE: Tailwind CDN loaded with defer so it never blocks
        the HTML parser or the critical rendering path.
    -->
    <!--<script src="https://cdn.tailwindcss.com" defer></script>-->
    <!--<script>-->
    <!--    tailwind.config = {-->
    <!--        theme: {-->
    <!--            extend: {-->
    <!--                colors: {-->
    <!--                    'pm-red': '#cc0000',-->
    <!--                    'pm-dark': '#0A0A0A',-->
    <!--                },-->
    <!--                fontFamily: {-->
    <!--                    sans: ['DM Sans', 'system-ui', 'sans-serif'],-->
    <!--                    display: ['Bebas Neue', 'sans-serif'],-->
    <!--                    mono: ['DM Mono', 'monospace'],-->
    <!--                },-->
    <!--            }-->
    <!--        },-->
    <!--        corePlugins: { preflight: false }-->
    <!--    }-->
    <!--</script>-->

    <!--
        PERFORMANCE: explicit preload puts these critical local stylesheets
        at the front of the request queue instead of waiting for the parser
        to reach the <link> tags below — shortens the dependency chain.
    -->
    <?php
    // Cache-bust on file change so edits to these stylesheets take effect
    // immediately instead of waiting out the browser's CSS cache.
    $pm_dashboard_css_v  = @filemtime(__DIR__ . '/dashboard.css') ?: time();
    $pm_output_css_v     = @filemtime(__DIR__ . '/output.css') ?: time();
    $pm_filter_bar_css_v = @filemtime(__DIR__ . '/filter_bar.css') ?: time();
    ?>
    <link rel="preload" as="style" href="dashboard.css?v=<?= $pm_dashboard_css_v ?>">
    <link rel="preload" as="style" href="output.css?v=<?= $pm_output_css_v ?>">
    <link rel="stylesheet" href="dashboard.css?v=<?= $pm_dashboard_css_v ?>">
    <link rel="stylesheet" href="output.css?v=<?= $pm_output_css_v ?>">
    <!-- Shared filter-bar dropdown styles (.dd-trigger + Select2) — pinned
         to one canonical 32px/0.8rem benchmark so pages stop drifting from
         each other; see filter_bar.css for details. -->
    <link rel="stylesheet" href="filter_bar.css?v=<?= $pm_filter_bar_css_v ?>">

    <?php
    // Cache-bust the same way as the stylesheets above (see their comment).
    $pm_layout_js_v = @filemtime(__DIR__ . '/layout.js') ?: time();
    ?>
    <!--
        No defer/async: the theme pre-paint IIFE inside layout.js must run
        synchronously here, before first paint, to avoid a flash of the
        wrong theme (see that IIFE's own comment in layout.js). Everything
        else in layout.js (pmFetch, keep-alive, sidebar, dropdowns, alert
        auto-dismiss) has no PHP interpolation, which is why it could move
        out of this inline block at all — the PIC-only notification/toast
        script further down still can't, since it embeds the CSRF token.
    -->
    <script src="layout.js?v=<?= $pm_layout_js_v ?>"></script>
</head>

<body class="pm-body">

    <?php if ($pm_clean_siri_url !== null): ?>
    <script>history.replaceState(null, '', <?= json_encode($pm_clean_siri_url) ?>);</script>
    <?php endif; ?>

    <div class="pm-overlay"></div>

    <header class="pm-header">

        <button class="pm-hamburger" aria-label="Toggle menu" onclick="pmToggleSidebar()">
            <span></span><span></span><span></span>
        </button>

        <a href="<?= $pm_role === "pic"
            ? "pic.php"
            : ($pm_role === "judge"
                ? "judge.php"
                : ($pm_role === "admin"
                    ? "admin.php"
                    : "attendance.php")) ?>">
            <!--
                PERFORMANCE: <picture> serves WebP to modern browsers (25-35%
                smaller). Explicit width/height prevent layout shift (CLS).
                fetchpriority="high" = maximum network priority (it's LCP).
                decoding="async" frees the main thread during decode.
            -->
            <picture>
                <source srcset="img/logo_silat_1.webp" type="image/webp">
                <img src="img/logo_silat_1.png"
                     alt="ProMarkah"
                     width="40"
                     height="40"
                     fetchpriority="high"
                     decoding="async"
                     class="pm-logo">
            </picture>
        </a>

        <div class="pm-header-text">
            <span class="pm-header-sub">ProMarkah</span>
            <h1 class="pm-header-title"><?= htmlspecialchars($pm_title) ?></h1>
        </div>

        <div class="pm-header-actions">

            <button class="pm-theme-toggle" onclick="pmToggleTheme()" data-tip="Tukar Tema" aria-label="Tukar tema terang/gelap">
                <svg class="tt-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
                <svg class="tt-moon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
            </button>

            <?php if ($pm_role === "pic"): ?>
                <div class="pm-notif-wrapper">
                    <button class="pm-icon-btn" id="pm-notif-btn" onclick="pmToggleNotifMenu(event)" aria-label="Notifikasi" aria-haspopup="true" aria-expanded="false">
                        <?= pm_icon("bell") ?>
                        <span class="pm-notif-badge" id="pm-notif-badge" style="display:none;">0</span>
                    </button>
                    <div class="pm-dropdown-menu" id="pm-notif-menu">
                        <div class="pm-dropdown-header"
                            style="display:flex; justify-content:space-between; align-items:center;">
                            <strong style="color:var(--c-text); font-size:var(--text-sm);">Notifikasi Terkini</strong>
                            <button class="pm-btn-ghost" style="font-size:0.7rem; padding:2px 6px;"
                                onclick="pmClearNotifs(event)">Bersihkan</button>
                        </div>
                        <div class="pm-notif-list" id="pm-notif-list">
                            <div class="pm-notif-empty">Tiada notifikasi baharu.</div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="pm-user-dropdown">
                <button class="pm-user-chip" id="pm-user-btn" onclick="pmToggleUserMenu(event)" aria-haspopup="true" aria-expanded="false">
                    <div class="pm-user-avatar">
                        <?php if ($pm_judge_photo): ?>
                            <img src="<?= htmlspecialchars($pm_judge_photo) ?>" alt="" style="width:100%; height:100%; object-fit:cover; border-radius:inherit;">
                        <?php else: ?>
                            <?= pm_icon($pm_role === 'guest' ? 'users' : 'shield') ?>
                        <?php endif; ?>
                    </div>
                    <div class="pm-user-info">
                        <span class="pm-user-name">
                            <?= htmlspecialchars($pm_role === 'guest' ? 'Akaun Umum' : $pm_username) ?>
                        </span>
                        <span class="pm-user-role <?= $meta["role_class"] ?>"><?= $meta["label"] ?></span>
                    </div>
                    <svg style="width: 14px; height: 14px; stroke: var(--c-text-muted); fill: none; stroke-width: 2; margin-left: 2px;"
                        viewBox="0 0 24 24">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                </button>
            
                <div class="pm-dropdown-menu" id="pm-user-menu">
                    <?php if ($pm_role === 'guest'): ?>
                        <div class="pm-dropdown-header"
                            style="color: var(--c-text-muted); font-size: 0.85rem; padding-bottom: 8px; border-bottom: 1px solid var(--c-border); margin-bottom: 4px;">
                            Mod Kehadiran <br>
                            <strong style="color: var(--c-text); font-size: 1.05rem; display: inline-block; margin-top: 4px;">
                                Akaun Umum
                            </strong>
                        </div>
                        <a href="index.php" class="pm-dropdown-item" style="color: var(--c-red); font-weight: 700;">
                            <span style="color: var(--c-red); display: flex; align-items: center; fill: none; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round;">
                                <?= pm_icon("log-out") ?>
                            </span>
                            Kembali ke Log Masuk
                        </a>
            
                    <?php else: ?>
                        <div class="pm-dropdown-header"
                            style="color: var(--c-text-muted); font-size: 0.85rem; padding-bottom: 8px; border-bottom: 1px solid var(--c-border); margin-bottom: 4px;">
                            Log masuk sebagai <br>
                            <strong style="color: var(--c-text); font-size: 1.05rem; display: inline-block; margin-top: 4px;">
                                <?= htmlspecialchars($pm_username) ?>
                            </strong>
                        </div>
                        <?php if ($pm_role === 'judge'): ?>
                        <a href="judge_settings.php" class="pm-dropdown-item">
                            <span style="color: var(--c-text); display: flex; align-items: center; fill: none; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round;">
                                <?= pm_icon("settings") ?>
                            </span>
                            Tetapan Akaun
                        </a>
                        <?php endif; ?>
                        <a href="logout.php" class="pm-dropdown-item" style="color: var(--c-red); font-weight: 700;">
                            <span style="color: var(--c-text); display: flex; align-items: center; fill: none; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round;">
                                <?= pm_icon("log-out") ?>
                            </span>
                            Log Keluar
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <aside class="pm-sidebar" id="pm-sidebar">
        <div class="pm-sidebar-inner">

            <?php if (($pm_role === "pic" || $pm_role === "admin") && isset($conn)):
                $siriTblChk = $conn->query("SHOW TABLES LIKE 'siri'");
                if ($siriTblChk && $siriTblChk->num_rows > 0):
                    $siriListNav = $conn->query(
                        "SELECT siri_id, siri_name, siri_year FROM siri ORDER BY siri_year DESC, siri_name ASC"
                    );
                    $activeSiriIdNav = $_SESSION["active_siri_id"] ?? 0;
                    if ($siriListNav && $siriListNav->num_rows > 0): ?>
            <div class="pm-siri-selector">
                <div class="pm-siri-label">
                    <svg viewBox="0 0 24 24" style="width:11px;height:11px;stroke:currentColor;fill:none;stroke-width:2.5;vertical-align:middle;"><line x1="6" y1="3" x2="6" y2="15"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M18 9a9 9 0 0 1-9 9"/></svg>
                    Siri Aktif
                </div>
                <?php
                // Custom dropdown instead of a native <select> — the native
                // OS popup for this control was painting solid black for a
                // few seconds before showing options (a known Chromium/GPU
                // rendering issue with native <select> listboxes). Every
                // other dropdown in the app already avoids this by using a
                // JS-rendered popup (select2 elsewhere, this lightweight
                // version here) instead of relying on the OS-native one.
                $activeSiriLabel = "-- Semua Siri --";
                $siriOptionsHtml = "";
                while ($siriRow = $siriListNav->fetch_assoc()) {
                    $isActive = $siriRow["siri_id"] == $activeSiriIdNav;
                    $optLabel = htmlspecialchars($siriRow["siri_name"]) . " (" . htmlspecialchars($siriRow["siri_year"]) . ")";
                    if ($isActive) $activeSiriLabel = $optLabel;
                    $siriOptionsHtml .= "<div class='pm-siri-dd-opt" . ($isActive ? " selected" : "") . "' data-value='" . (int)$siriRow["siri_id"] . "' onclick=\"pmSiriDdSelect(this)\">{$optLabel}</div>";
                }
                ?>
                <div class="pm-siri-dd" id="pmSiriDd">
                    <button type="button" class="pm-siri-dd-trigger" id="pmSiriDdTrigger" onclick="pmSiriDdToggle()">
                        <span id="pmSiriDdLabel"><?= $activeSiriLabel ?></span>
                        <span class="pm-siri-dd-arrow">▾</span>
                    </button>
                    <div class="pm-siri-dd-panel" id="pmSiriDdPanel">
                        <div class="pm-siri-dd-opt<?= $activeSiriIdNav == 0 ? " selected" : "" ?>" data-value="" onclick="pmSiriDdSelect(this)">-- Semua Siri --</div>
                        <?= $siriOptionsHtml ?>
                    </div>
                </div>
            </div>
            <div class="pm-nav-divider"></div>
            <?php endif;
                endif;
            endif; ?>

            <?php foreach ($nav_items as $item): ?>
                <?php if ($item["type"] === "divider"): ?>
                    <div class="pm-nav-divider"></div>
                <?php elseif ($item["type"] === "label"): ?>
                    <p class="pm-nav-label"><?= htmlspecialchars(
                        $item["text"],
                    ) ?></p>
                <?php elseif ($item["type"] === "link"):

                    $is_active = ($pm_page && $item["id"] === $pm_page);
                    
                    // Handle ?session_id= parameter
                    if (isset($_GET["session_id"])) {
                        if ($item["id"] === "session_" . $_GET["session_id"]) {
                            $is_active = true;
                        } elseif ($item["id"] === $pm_page) {
                            $is_active = false;
                        }
                    }
                    
                    // Handle ?view= parameter (Fixes "Semua Hadir" highlighting)
                    if (isset($_GET["view"])) {
                        if ($item["id"] === $_GET["view"]) {
                            $is_active = true;
                        } elseif ($item["id"] === $pm_page) {
                            $is_active = false;
                        }
                    }
                    ?>
                    <a href="<?= htmlspecialchars($item["url"]) ?>"
                        class="pm-nav-link <?= $is_active ? "pm-nav-active" : "" ?>">
                        <span class="pm-nav-icon"><?= pm_icon($item["icon"]) ?></span>
                        <span class="pm-nav-text">
                            <span class="pm-nav-label-text"><?= htmlspecialchars($item["label"]) ?></span>
                            <?php if (!empty($item["sublabel"])): ?>
                                <span class="pm-nav-sublabel"><?= htmlspecialchars($item["sublabel"]) ?></span>
                            <?php endif; ?>
                        </span>
                    </a>
                <?php
                endif; ?>
            <?php endforeach; ?>
        </div>
    </aside>

    <div class="pm-backdrop" id="pm-backdrop" onclick="pmToggleSidebar()"></div>

    <main class="pm-main" id="pm-main">

        <!-- pmToggleTheme, pmToggleSidebar, pmSiriDdToggle/Select, sidebar
             scroll persistence, pmToggleUserMenu, dropdown outside-click
             close, and alert auto-dismiss all now live in layout.js
             (loaded in <head> above) — moved there since none of them
             interpolate a PHP value. -->

        <?php if ($pm_role === "pic"): ?>
            <div id="pm-global-toasts"></div>

            <style>
                #pm-global-toasts {
                    position: fixed;
                    top: 90px;
                    right: 20px;
                    z-index: 9999;
                    display: flex;
                    flex-direction: column;
                    gap: 12px;
                    max-width: calc(100vw - 40px);
                    pointer-events: none;
                }
                .pm-toast-notification {
                    background: var(--c-surface-1);
                    border-left: 4px solid var(--c-red);
                    border: 1px solid var(--c-border-strong);
                    border-left: 4px solid var(--c-red);
                    border-radius: 8px;
                    padding: 16px 20px;
                    box-shadow: 0 8px 24px rgba(0,0,0,0.3);
                    color: var(--c-text);
                    font-size: 0.92rem;
                    width: 100%;
                    min-width: 280px;
                    max-width: 400px;
                    backdrop-filter: blur(10px);
                    pointer-events: auto;
                    cursor: pointer;
                    transform: translateX(120%);
                    opacity: 0;
                    transition: all 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
                }
                .pm-toast-notification.show { transform: translateX(0); opacity: 1; }
                .pm-toast-header {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    font-weight: 700;
                    color: var(--c-text);
                    margin-bottom: 6px;
                    font-size: 1rem;
                    font-family: 'Bebas Neue', sans-serif;
                    letter-spacing: 0.5px;
                }
                .pm-toast-close { color: var(--c-text-faint); font-size: 0.8rem; font-family: sans-serif; }

                /* Bell dropdown notification items */
                .pm-notif-item {
                    padding: 10px 14px;
                    font-size: 0.82rem;
                    line-height: 1.45;
                    color: var(--c-text);
                    border-bottom: 1px solid var(--c-border);
                    background: var(--c-red-dim);
                }
                .pm-notif-item.read {
                    background: transparent;
                    color: var(--c-text-muted);
                }
                .pm-notif-time {
                    display: block;
                    font-size: 0.72rem;
                    color: var(--c-text-faint);
                    margin-top: 4px;
                }
                .pm-notif-list {
                    max-height: 320px;
                    overflow-y: auto;
                }
                .pm-notif-empty {
                    padding: 20px 14px;
                    color: var(--c-text-faint);
                    font-size: 0.85rem;
                    text-align: center;
                }

                @media (max-width: 480px) {
                    #pm-global-toasts { top: 75px; right: 16px; left: 16px; max-width: none; }
                    .pm-toast-notification { min-width: 100%; max-width: 100%; padding: 14px 16px; }
                }
            </style>

            <script>
                // ── State ──────────────────────────────────────────────────
                let pmPendingIds = [];   // IDs received but not yet marked read

                // ── AJAX Polling Connection (Hostinger Safe) ───────────────
                // Exponential backoff on failure: stays at 3s while healthy,
                // but doubles (capped at 60s) on each consecutive failure so a
                // DB hiccup/outage doesn't turn into indefinite full-speed
                // hammering. Resets to 3s the moment a request succeeds again.
                const PM_NOTIF_BASE_DELAY = 3000;
                const PM_NOTIF_MAX_DELAY  = 60000;
                let pmNotifFailCount = 0;
                let pmNotifTimer = null;

                function fetchNotifications() {
                    pmFetch('check_notifications.php')
                        .then(response => {
                            if (!response.ok) throw new Error('Network response was not ok');
                            return response.json();
                        })
                        .then(data => {
                            pmNotifFailCount = 0;
                            if (!data || !data.new || !data.messages) return;

                            // Track IDs so we can mark them read later
                            if (data.unread_ids && data.unread_ids.length) {
                                pmPendingIds = pmPendingIds.concat(data.unread_ids);
                            }

                            // Add to bell dropdown
                            data.messages.forEach(msg => addToNotifList(msg));
                            updateBadge();

                            // Catchup (missed while offline): show a single grouped toast
                            if (data.is_catchup) {
                                const count = data.messages.length;
                                spawnNotification(`Terdapat <b>${count}</b> markah yang dimasukkan semasa anda tiada dalam talian.`);
                            } else {
                                // Live: toast each individually
                                data.messages.forEach(spawnNotification);
                            }
                        })
                        .catch(error => {
                            // Silently catch errors so we don't spam the console if the network drops temporarily
                            // console.log('Notification check failed:', error);
                            pmNotifFailCount++;
                        })
                        .finally(() => {
                            const delay = Math.min(PM_NOTIF_BASE_DELAY * Math.pow(2, pmNotifFailCount), PM_NOTIF_MAX_DELAY);
                            pmNotifTimer = setTimeout(fetchNotifications, delay);
                        });
                }

                // Pause polling while the tab is hidden, resume (with an
                // immediate check) when it becomes visible again — no point
                // hitting the DB every few seconds for a tab nobody is looking at.
                document.addEventListener('visibilitychange', () => {
                    if (document.hidden) {
                        if (pmNotifTimer) clearTimeout(pmNotifTimer);
                    } else {
                        if (pmNotifTimer) clearTimeout(pmNotifTimer);
                        fetchNotifications();
                    }
                });

                // Start polling when the script loads
                fetchNotifications();

                // ── Bell dropdown ──────────────────────────────────────────
                function addToNotifList(message) {
                    const list = document.getElementById('pm-notif-list');
                    // Remove empty placeholder
                    const empty = list.querySelector('.pm-notif-empty');
                    if (empty) empty.remove();

                    const item = document.createElement('div');
                    item.className = 'pm-notif-item';
                    const now = new Date().toLocaleTimeString('ms-MY', { hour: '2-digit', minute: '2-digit' });
                    item.innerHTML = `<span>${message}</span><span class="pm-notif-time">${now}</span>`;
                    list.prepend(item);
                }

                function updateBadge() {
                    const badge = document.getElementById('pm-notif-badge');
                    const unread = document.querySelectorAll('#pm-notif-list .pm-notif-item:not(.read)').length;
                    if (unread > 0) {
                        badge.style.display = 'flex';
                        badge.textContent = unread > 99 ? '99+' : unread;
                    } else {
                        badge.style.display = 'none';
                    }
                }

                // Mark all as read when bell is opened
                function pmToggleNotifMenu(event) {
                    event.stopPropagation();
                    const menu = document.getElementById('pm-notif-menu');
                    const open = menu.classList.toggle('show');
                    document.getElementById('pm-notif-btn')?.setAttribute('aria-expanded', open ? 'true' : 'false');

                    if (menu.classList.contains('show') && pmPendingIds.length > 0) {
                        // Mark items visually as read
                        document.querySelectorAll('#pm-notif-list .pm-notif-item').forEach(el => el.classList.add('read'));
                        updateBadge();

                        // Persist to DB
                        const ids = [...pmPendingIds];
                        pmPendingIds = [];
                        
                        const formData = new URLSearchParams();
                        ids.forEach(id => formData.append('ids[]', id));
                        formData.append('csrf_token', <?= json_encode($pm_csrf) ?>);

                        pmFetch('mark_notifications_read.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: formData.toString()
                        }).catch(() => {});
                    }
                }

                // Clear all
                function pmClearNotifs(event) {
                    event.stopPropagation();
                    document.getElementById('pm-notif-list').innerHTML = '<div class="pm-notif-empty">Tiada notifikasi baharu.</div>';
                    pmPendingIds = [];
                    updateBadge();
                    pmFetch('mark_notifications_read.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'all=1&csrf_token=' + encodeURIComponent(<?= json_encode($pm_csrf) ?>)
                    }).catch(() => {});
                }

                // ── Toast ─────────────────────────────────────────────────
                function spawnNotification(message) {
                    const container = document.getElementById('pm-global-toasts');
                    const toast = document.createElement('div');
                    toast.className = 'pm-toast-notification';
                    toast.innerHTML = `
                        <div class="pm-toast-header">
                            <span style="display:flex; align-items:center; gap:8px;">
                                <span style="font-size:1.2rem;">🔔</span> Markah Masuk!
                            </span>
                            <span class="pm-toast-close">✕</span>
                        </div>
                        <div style="line-height:1.4;">${message}</div>`;
                    toast.addEventListener('click', () => {
                        toast.classList.remove('show');
                        setTimeout(() => toast.remove(), 400);
                    });
                    container.appendChild(toast);
                    
                    // Request an animation frame to ensure the DOM is updated before adding the class
                    requestAnimationFrame(() => {
                        requestAnimationFrame(() => {
                            toast.classList.add('show');
                        });
                    });
                    
                    setTimeout(() => {
                        if (toast.parentNode) {
                            toast.classList.remove('show');
                            setTimeout(() => { if (toast.parentNode) toast.remove(); }, 400);
                        }
                    }, 6000);
                }

                // ── Generic save/action result toast ────────────────────────
                // Shared by every PIC page that shows a "?msg=...&status=..."
                // result after a POST redirect (pic_groups.php, pic_criteria.php,
                // pic_tests.php, pic_levels.php, ...). Several of those pages have
                // an accordion open/scroll-position restore (sessionStorage-based)
                // that puts the user back wherever they were editing — a static
                // alert block at the top of the page would be scrolled out of
                // view in that case, so this floats instead, regardless of
                // scroll position.
                function spawnPmToast(message, isError) {
                    const container = document.getElementById('pm-global-toasts');
                    if (!container) return;
                    const toast = document.createElement('div');
                    toast.className = 'pm-toast-notification';
                    toast.innerHTML = `
                        <div class="pm-toast-header">
                            <span style="display:flex; align-items:center; gap:8px;">
                                <span style="font-size:1.2rem;">${isError ? '⚠️' : '✅'}</span> ${isError ? 'Ralat' : 'Berjaya'}
                            </span>
                            <span class="pm-toast-close">✕</span>
                        </div>
                        <div style="line-height:1.4;">${message}</div>`;
                    toast.addEventListener('click', () => {
                        toast.classList.remove('show');
                        setTimeout(() => toast.remove(), 400);
                    });
                    container.appendChild(toast);
                    requestAnimationFrame(() => requestAnimationFrame(() => toast.classList.add('show')));
                    setTimeout(() => {
                        if (toast.parentNode) {
                            toast.classList.remove('show');
                            setTimeout(() => { if (toast.parentNode) toast.remove(); }, 400);
                        }
                    }, 5000);
                }
            </script>

        <?php endif; ?>