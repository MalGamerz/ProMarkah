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

$pm_role = $_SESSION["role"] ?? "guest";

// ── CATCH & SAVE SIRI CHANGE GLOBALLY ──────────────────────────────────────
if (isset($_GET['siri_id'])) {
    if ($_GET['siri_id'] === '') {
        $_SESSION['active_siri_id'] = 0; // 0 means "Semua Siri"
    } elseif (is_numeric($_GET['siri_id'])) {
        $_SESSION['active_siri_id'] = (int)$_GET['siri_id'];
    }
    
    // Clean the URL so it doesn't get stuck with ?siri_id=
    $clean_url = strtok($_SERVER["REQUEST_URI"], '?');
    header("Location: " . $clean_url);
    exit();
}

$is_simple_mode = ($pm_page === "attendance" && $pm_role !== "pic" && $pm_role !== "admin");
$pm_page = $pm_page ?? "";

// Dynamically pull the actual name for ANY role.
// It checks common session variables where the real name might be stored before falling back to the login ID.
$pm_username =
    $_SESSION["name"] ??
    ($_SESSION["full_name"] ??
        ($_SESSION["judge_name"] ?? ($_SESSION["username"] ?? "Pengguna"))); // Changed from "User"

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

// ── Inline SVG icon library (Lucide subset) ────────────────────────────────
// Usage in nav: icon('layout-dashboard')
// All icons share: stroke=currentColor, fill=none, stroke-width=1.75
function pm_icon(string $name): string
{
    $paths = [
        // Layout
        "layout-dashboard" =>
            '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        "check-square" =>
            '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        "bookmark" =>
            '<path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/>',
        "users" =>
            '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        "school" =>
            '<path d="M2 22V10l10-8 10 8v12"/><path d="M12 22V15"/><path d="M7 22v-4h10v4"/><path d="M7 11h10"/>',
        "calendar" =>
            '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        "target" =>
            '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
        "flask" =>
            '<path d="M9 3h6"/><path d="M10 3v5L5 19a1 1 0 0 0 .94 1.35h12.12A1 1 0 0 0 19 19l-5-11V3"/>',
        "list" =>
            '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
        "file-text" =>
            '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
        "git-branch" =>
            '<line x1="6" y1="3" x2="6" y2="15"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M18 9a9 9 0 0 1-9 9"/>',
        "pen-line" =>
            '<path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 19.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/>',
        "bar-chart-2" =>
            '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        "medal" =>
            '<path d="M7.21 15 2.66 7.14a2 2 0 0 1 .13-2.2L4.4 2.8A2 2 0 0 1 6 2h12a2 2 0 0 1 1.6.8l1.6 2.14a2 2 0 0 1 .14 2.2L16.79 15"/><path d="M11 12 5.12 2.2"/><path d="m13 12 5.88-9.8"/><path d="M8 7h8"/><circle cx="12" cy="17" r="5"/><path d="M12 18v-2h-.5"/>',
        "trophy" =>
            '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2z"/>',
        "clipboard-list" =>
            '<rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="11" x2="9" y2="17"/><line x1="15" y1="11" x2="15" y2="17"/>',
        "user-check" =>
            '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/>',
        // Judge
        "scale" =>
            '<path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1z"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1z"/><path d="M7 21h10"/><line x1="12" y1="3" x2="12" y2="21"/><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"/>',
        "book-open" =>
            '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
        "eye" =>
            '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        // Common
        "log-out" =>
            '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        "home" =>
            '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        "shield" => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        "bell" =>
            '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        "folder-open" =>
            '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="12" y1="11" x2="12" y2="17"/><polyline points="9 14 12 17 15 14"/>',
    ];

    $inner = $paths[$name] ?? '<circle cx="12" cy="12" r="10"/>';
    return '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">' .
        $inner .
        "</svg>";
}

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
        $_sessions = $conn->query("SELECT * FROM sessions ORDER BY session_name");
        if ($_sessions && $_sessions->num_rows > 0) {
            $nav_items[] = ["type" => "divider"];
            $nav_items[] = ["type" => "label", "text" => "SIDANG"];
            while ($_s = $_sessions->fetch_assoc()) {
                $nav_items[] = [
                    "type"  => "link",
                    "url"   => "attendance.php?session_id=" . $_s["session_id"],
                    "icon"  => "bookmark",
                    "label" => $_s["session_name"],
                    "id"    => "session_" . $_s["session_id"],
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
        $_sessions = $conn->query(
            "SELECT * FROM sessions ORDER BY session_name",
        );
        if ($_sessions && $_sessions->num_rows > 0) {
            $nav_items[] = ["type" => "divider"];
            $nav_items[] = ["type" => "label", "text" => "SIDANG"];
            while ($_s = $_sessions->fetch_assoc()) {
                $nav_items[] = [
                    "type" => "link",
                    "url" => "attendance.php?session_id=" . $_s["session_id"],
                    "icon" => "bookmark",
                    "label" => $_s["session_name"],
                    "id" => "session_" . $_s["session_id"],
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

// ── ATTENDANCE PAGE OVERRIDE: always show simple sidebar ──────────────
if ($pm_page === "attendance") {
    $attendance_nav = [
        ["type" => "link", "url" => "attendance.php", "icon" => "layout-dashboard", "label" => "Papan Pemuka", "id" => "attendance"],
        ["type" => "link", "url" => "attendance.php?view=all_present", "icon" => "check-square", "label" => "Senarai Penuh", "id" => "all_present"]
    ];

    // 3. FETCH SESSIONS (Filtered by Active Siri)
    if (isset($conn)) {
        $active_siri_id = (int)($_SESSION["active_siri_id"] ?? 0);
        $siri_query_addon = $active_siri_id > 0 ? "WHERE siri_id = $active_siri_id" : "";
        
        $_sessions = $conn->query("SELECT * FROM sessions $siri_query_addon ORDER BY session_name");
        
        if ($_sessions && $_sessions->num_rows > 0) {
            $attendance_nav[] = ["type" => "divider"];
            $attendance_nav[] = ["type" => "label", "text" => "SIDANG"];
            while ($_s = $_sessions->fetch_assoc()) {
                $attendance_nav[] = [
                    "type" => "link",
                    "url" => "attendance.php?session_id=" . $_s["session_id"],
                    "icon" => "bookmark",
                    "label" => $_s["session_name"],
                    "id" => "session_" . $_s["session_id"],
                ];
            }
        }
    }

    $nav_items = $attendance_nav;
}
?>

<!DOCTYPE html>
<html lang="ms">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ProMarkah — <?= htmlspecialchars($pm_title) ?></title>

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
    <link rel="preload" as="style" href="dashboard.css">
    <link rel="preload" as="style" href="output.css">
    <link rel="stylesheet" href="dashboard.css">
    <link rel="stylesheet" href="output.css">

    <script>
        (function() {
            let savedTheme = localStorage.getItem('pm-theme');
            const isJudge = <?= $pm_role === "judge" ? "true" : "false" ?>;

            // If no theme is saved OR if we want to enforce defaults, set it
            if (!savedTheme) {
                savedTheme = isJudge ? 'light' : 'dark';
                localStorage.setItem('pm-theme', savedTheme);
            }

            if (savedTheme === 'light') {
                document.documentElement.classList.add('pm-light');
            } else {
                document.documentElement.classList.remove('pm-light');
            }
        })();
    </script>
</head>

<body class="pm-body">

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

            <button class="pm-theme-toggle" onclick="pmToggleTheme()" data-tip="Tukar Tema">
                <svg class="tt-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
                <svg class="tt-moon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
            </button>

            <?php if ($pm_role === "pic"): ?>
                <div class="pm-notif-wrapper">
                    <button class="pm-icon-btn" id="pm-notif-btn" onclick="pmToggleNotifMenu(event)">
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
                <button class="pm-user-chip" id="pm-user-btn" onclick="pmToggleUserMenu(event)">
                    <div class="pm-user-avatar">
                        <?= pm_icon($pm_role === 'guest' ? 'users' : 'shield') ?>
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
                        <a href="index.php" class="pm-dropdown-item" style="color: var(--c-text); font-weight: 700;">
                            <span style="color: var(--c-text); display: flex; align-items: center; fill: none; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round;">
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
                <form method="GET" action="" id="siriContextForm">
                    <select name="siri_id" class="pm-siri-select" onchange="document.getElementById('siriContextForm').submit()">
                        <option value="">-- Semua Siri --</option>
                        <?php while ($siriRow = $siriListNav->fetch_assoc()): ?>
                        <option value="<?= $siriRow["siri_id"] ?>" <?= $siriRow[
    "siri_id"
] == $activeSiriIdNav
    ? "selected"
    : "" ?>>
                            <?= htmlspecialchars(
                                $siriRow["siri_name"],
                            ) ?> (<?= $siriRow["siri_year"] ?>)
                        </option>
                        <?php endwhile; ?>
                    </select>
                </form>
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
                        <span><?= htmlspecialchars($item["label"]) ?></span>
                    </a>
                <?php
                endif; ?>
            <?php endforeach; ?>
        </div>
    </aside>

    <div class="pm-backdrop" id="pm-backdrop" onclick="pmToggleSidebar()"></div>

    <main class="pm-main" id="pm-main">

        <script>
            // Theme Toggle Functionality
            function pmToggleTheme() {
                const html = document.documentElement;
                html.classList.toggle('pm-light');
                localStorage.setItem('pm-theme', html.classList.contains('pm-light') ? 'light' : 'dark');
            }

            // Sidebar and Menu Toggles
            function pmToggleSidebar() {
                document.getElementById('pm-sidebar').classList.toggle('pm-sidebar-open');
                document.getElementById('pm-backdrop').classList.toggle('pm-backdrop-show');
            }

            function pmToggleUserMenu(e) {
                e.stopPropagation();
                document.getElementById('pm-user-menu').classList.toggle('show');
            }

            // Notification toggle/clear defined in the PIC notification block below
            // (pmToggleNotifMenu, pmClearNotifs)

            // Close dropdowns when clicking outside
            document.addEventListener('click', function (e) {
                const userMenu = document.getElementById('pm-user-menu');
                const userBtn = document.getElementById('pm-user-btn');
                if (userMenu && userMenu.classList.contains('show') && !userMenu.contains(e.target) && !userBtn.contains(e.target)) {
                    userMenu.classList.remove('show');
                }

                const notifMenu = document.getElementById('pm-notif-menu');
                const notifBtn = document.getElementById('pm-notif-btn');
                if (notifMenu && notifMenu.classList.contains('show') && !notifMenu.contains(e.target) && !notifBtn.contains(e.target)) {
                    notifMenu.classList.remove('show');
                }
            });
        </script>

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
                function fetchNotifications() {
                    fetch('check_notifications.php')
                        .then(response => {
                            if (!response.ok) throw new Error('Network response was not ok');
                            return response.json();
                        })
                        .then(data => {
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
                        })
                        .finally(() => {
                            // Wait 3 seconds, then check again
                            setTimeout(fetchNotifications, 3000);
                        });
                }

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
                    menu.classList.toggle('show');

                    if (menu.classList.contains('show') && pmPendingIds.length > 0) {
                        // Mark items visually as read
                        document.querySelectorAll('#pm-notif-list .pm-notif-item').forEach(el => el.classList.add('read'));
                        updateBadge();

                        // Persist to DB
                        const ids = [...pmPendingIds];
                        pmPendingIds = [];
                        
                        const formData = new URLSearchParams();
                        ids.forEach(id => formData.append('ids[]', id));
                        
                        fetch('mark_notifications_read.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: formData.toString()
                        });
                    }
                }

                // Clear all
                function pmClearNotifs(event) {
                    event.stopPropagation();
                    document.getElementById('pm-notif-list').innerHTML = '<div class="pm-notif-empty">Tiada notifikasi baharu.</div>';
                    pmPendingIds = [];
                    updateBadge();
                    fetch('mark_notifications_read.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'all=1'
                    });
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
            </script>

        <?php endif; ?>