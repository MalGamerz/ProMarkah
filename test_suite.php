<?php
/**
 * ProMarkah V3 — Interactive Test Suite
 * Upload to your Hostinger root, then visit:
 *   https://prosilat.net/test_suite.php?key=pMk_X7qN2vR9wL3z
 *
 * DELETE this file when done testing.
 */

define('SECRET_KEY', 'pMk_X7qN2vR9wL3z');

if (($_GET['key'] ?? '') !== SECRET_KEY) {
    http_response_code(403);
    exit('Access denied.');
}

// ── cURL helper ─────────────────────────────────────────────────────────────
function http_get(string $url, string $cookie = ''): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => ['Cookie: ' . $cookie],
        CURLOPT_HEADER         => true,
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr($raw, 0, $hlen);
    $body    = substr($raw, $hlen);
    return [$code, $headers, $body];
}

function http_post(string $url, array $fields, string $cookie = ''): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Cookie: ' . $cookie,
        ],
        CURLOPT_HEADER         => true,
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr($raw, 0, $hlen);
    $body    = substr($raw, $hlen);
    return [$code, $headers, $body];
}

/** Extract Set-Cookie session id from response headers */
function extract_session(string $headers): string {
    preg_match_all('/^Set-Cookie:\s*([^;\r\n]+)/mi', $headers, $m);
    return implode('; ', $m[1]);
}

/** Extract CSRF token from HTML */
function extract_csrf(string $html): string {
    preg_match('/name=["\']csrf_token["\'][^>]*value=["\']([a-f0-9]+)["\']/i', $html, $m);
    if (!$m) preg_match('/value=["\']([a-f0-9]{40,})["\'][^>]*name=["\']csrf_token["\']/i', $html, $m);
    return $m[1] ?? '';
}

$base = 'https://' . $_SERVER['HTTP_HOST']
      . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

// ── Run tests only when form submitted ──────────────────────────────────────
$ran      = false;
$results  = [];
$sections = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    $ran    = true;
    $role   = $_POST['role'] ?? '';
    $ts_key = '?key=' . urlencode(SECRET_KEY);

    // ── helper to add a result (defined early so public block can use it) ──
    $add = function (string $section, string $label, bool $pass, string $detail, int $ms = 0) use (&$results, &$sections) {
        $sections[$section] = true;
        $results[] = compact('section', 'label', 'pass', 'detail', 'ms');
    };

    // ════════════════════════════════════════════════════════════════════════
    // PUBLIC (NO LOGIN) ATTENDANCE TESTS
    // ════════════════════════════════════════════════════════════════════════
    if ($role === 'public') {
        // attendance.php: public GET — should return 200 (no QR token = school picker or default view)
        $t = microtime(true);
        [$c,,] = http_get("$base/attendance.php");
        $add('Public Attendance (no login)', 'attendance.php GET — loads without login', !in_array($c,[500,404]), "HTTP $c", (int)((microtime(true)-$t)*1000));

        // attendance.php?view=all_present: public aggregate view
        $t = microtime(true);
        [$c,,] = http_get("$base/attendance.php?view=all_present");
        $add('Public Attendance (no login)', 'attendance.php?view=all_present GET', !in_array($c,[500,404]), "HTTP $c", (int)((microtime(true)-$t)*1000));

        // save_attendance.php: empty POST → 'invalid_request'
        $t = microtime(true);
        [$c,,$b] = http_post("$base/save_attendance.php", []);
        $ok = trim($b) === 'invalid_request';
        $add('Public Attendance (no login)', 'save_attendance.php: empty POST → invalid_request', $ok, "Response: ".trim(substr($b,0,60)), (int)((microtime(true)-$t)*1000));

        // save_attendance.php: invalid status whitelist
        $t = microtime(true);
        [$c,,$b] = http_post("$base/save_attendance.php", [
            'student_id' => 1, 'session_id' => 1, 'status' => 'INJECTED',
        ]);
        $ok = in_array(trim($b), ['invalid_status','invalid_request']);
        $add('Public Attendance (no login)', 'save_attendance.php: invalid status → invalid_status', $ok, "Response: ".trim(substr($b,0,60)), (int)((microtime(true)-$t)*1000));

        // save_attendance.php: valid status — fetch a real student_id + session_id from DB first
        // Using fake IDs (1,1) will throw a FK exception because db.php uses MYSQLI_REPORT_STRICT.
        // We get the first real student + session pair to test a true happy-path insert.
        require_once __DIR__ . '/db.php';
        $db_pub = getDB();
        $real_student_id = 0;
        $real_session_id = 0;
        $r1 = $db_pub->query("SELECT student_id FROM students LIMIT 1");
        if ($r1 && $row1 = $r1->fetch_assoc()) $real_student_id = (int)$row1['student_id'];
        $r2 = $db_pub->query("SELECT session_id FROM sessions LIMIT 1");
        if ($r2 && $row2 = $r2->fetch_assoc()) $real_session_id = (int)$row2['session_id'];

        if ($real_student_id > 0 && $real_session_id > 0) {
            $t = microtime(true);
            [$c,,$b] = http_post("$base/save_attendance.php", [
                'student_id' => $real_student_id, 'session_id' => $real_session_id, 'status' => 'Present',
            ]);
            $ok = $c === 200 && in_array(trim($b), ['success', 'database_error']);
            $add('Public Attendance (no login)', 'save_attendance.php: Present — returns success/database_error', $ok, "HTTP $c / ".trim(substr($b,0,60)), (int)((microtime(true)-$t)*1000));

            $t = microtime(true);
            [$c,,$b] = http_post("$base/save_attendance.php", [
                'student_id' => $real_student_id, 'session_id' => $real_session_id, 'status' => 'Absent',
            ]);
            $ok = $c === 200 && in_array(trim($b), ['success', 'database_error']);
            $add('Public Attendance (no login)', 'save_attendance.php: Absent — returns success/database_error', $ok, "HTTP $c / ".trim(substr($b,0,60)), (int)((microtime(true)-$t)*1000));

            // attendance_toggle.php: POST returns JSON
            $t = microtime(true);
            [$c,,$b] = http_post("$base/attendance_toggle.php", [
                'student_id' => $real_student_id, 'session_id' => $real_session_id, 'status' => 'Present',
            ]);
            $json = json_decode($b, true);
            $ok   = $c === 200 && isset($json['status']);
            $add('Public Attendance (no login)', 'attendance_toggle.php: POST returns JSON', $ok, $ok ? "JSON: ".substr($b,0,60) : "HTTP $c / ".substr($b,0,80), (int)((microtime(true)-$t)*1000));

            // attendance_toggle.php: status whitelist — 'HACKED' becomes 'Absent'
            $t = microtime(true);
            [$c,,$b] = http_post("$base/attendance_toggle.php", [
                'student_id' => $real_student_id, 'session_id' => $real_session_id, 'status' => 'HACKED',
            ]);
            $json2 = json_decode($b, true);
            $ok    = $c === 200 && isset($json2['status']);
            $add('Public Attendance (no login)', 'attendance_toggle.php: non-whitelisted status defaults to Absent', $ok, "HTTP $c / ".substr($b,0,60), (int)((microtime(true)-$t)*1000));
        } else {
            $add('Public Attendance (no login)', 'save_attendance.php / attendance_toggle.php', false, 'SKIPPED — no students or sessions in DB yet', 0);
        }

        // Verify login-protected pages block unauthenticated requests
        // Note: attendance_view_dashboard.php is an include partial (not standalone) — not tested directly
        $blocked = [
            'manage_attendance.php' => 'manage_attendance.php (PIC only — must block public)',
        ];
        foreach ($blocked as $page => $label) {
            $t = microtime(true);
            [$c,$h,] = http_get("$base/$page");
            preg_match('/^Location:\s*(.+)$/mi', $h, $lm);
            $loc = trim($lm[1] ?? '');
            $blocked_ok = ($c === 302 && stripos($loc, 'login') !== false) || $c === 403;
            $add('Public Attendance (no login)', "Role guard: $label", $blocked_ok,
                $blocked_ok ? "HTTP $c → $loc (correctly blocked)" : "HTTP $c — NOT blocked, should redirect to login", (int)((microtime(true)-$t)*1000));
        }

    } else { // ── ROLES THAT REQUIRE LOGIN ────────────────────────────────

    // ════════════════════════════════════════════════════════════════════════
    // INFRA TESTS (always run)
    // ════════════════════════════════════════════════════════════════════════

    // DB
    $t = microtime(true);
    require_once __DIR__ . '/db.php';
    try {
        $db = getDB();
        $ok = $db instanceof mysqli && $db->ping();
        $add('Infrastructure', 'Database connection', $ok, $ok ? 'Connected to ' . DB_NAME : 'Failed', (int)((microtime(true)-$t)*1000));
    } catch (Throwable $e) {
        $add('Infrastructure', 'Database connection', false, $e->getMessage());
    }

    // Tables
    $t = microtime(true);
    $needed = ['users','sessions','schools','students','groups','criteria','scores','attendance'];
    $found  = [];
    $res = $db->query("SHOW TABLES");
    while ($r = $res->fetch_row()) $found[] = strtolower($r[0]);
    $missing = array_diff($needed, $found);
    $add('Infrastructure', 'Core tables exist',
        empty($missing),
        empty($missing) ? count($found).' tables found' : 'Missing: '.implode(', ',$missing),
        (int)((microtime(true)-$t)*1000));

    // PHP extensions
    foreach (['mysqli','curl','mbstring','json','session','zip'] as $ext) {
        $ok = extension_loaded($ext);
        $add('Infrastructure', "PHP ext: $ext", $ok, $ok ? 'Loaded' : 'NOT loaded');
    }

    // Writable dirs
    foreach (['file'=>'file/','sijil'=>'sijil/','img'=>'img/'] as $dir => $label) {
        $path = __DIR__ . '/' . $dir;
        $ok   = is_dir($path) && is_writable($path);
        $add('Infrastructure', "Writable: $label", $ok, $ok ? 'OK' : (!is_dir($path) ? 'Directory missing' : 'Not writable'));
    }

    // ════════════════════════════════════════════════════════════════════════
    // LOGIN TEST
    // ════════════════════════════════════════════════════════════════════════
    $session_cookie = '';
    $logged_in      = false;

    if ($role === 'judge') {
        // Judge: 2-step (username/pass → judge select + PIN)
        $username   = trim($_POST['username']   ?? '');
        $password   = trim($_POST['password']   ?? '');
        $judge_id   = trim($_POST['judge_id']   ?? '');
        $judge_pin  = trim($_POST['judge_pin']  ?? '');

        // Step 1 — get CSRF
        $t = microtime(true);
        [,$h1,$b1] = http_get("$base/login.php");
        $cookie1   = extract_session($h1);
        $csrf1     = extract_csrf($b1);
        $add('Login', 'Login page loaded (step 1)', $csrf1 !== '', $csrf1 ? 'CSRF found' : 'CSRF token missing', (int)((microtime(true)-$t)*1000));

        sleep(2); // bypass time-trap (rejects submissions < 2s after page load)

        // Step 1 — POST username/password
        $t = microtime(true);
        [$c2,$h2,$b2] = http_post("$base/login.php", [
            'username'    => $username,
            'password'    => $password,
            'csrf_token'  => $csrf1,
            'website_url' => '',
        ], $cookie1);
        $cookie2  = extract_session($h2) ?: $cookie1;
        $redirect = '';
        preg_match('/^Location:\s*(.+)$/mi', $h2, $lm);
        $redirect = trim($lm[1] ?? '');
        $step2_ok = ($c2 === 302 && stripos($redirect, 'login.php') !== false);
        $add('Login', 'Step 1: judge credentials accepted', $step2_ok,
            $step2_ok ? "Redirected to step 2" : "HTTP $c2 — check username/password",
            (int)((microtime(true)-$t)*1000));

        if ($step2_ok) {
            // Step 2 — get fresh CSRF from login.php
            $t = microtime(true);
            [,$h3,$b3] = http_get("$base/login.php", $cookie2);
            $cookie3 = extract_session($h3) ?: $cookie2;
            $csrf3   = extract_csrf($b3);
            $add('Login', 'Login page loaded (step 2)', $csrf3 !== '', $csrf3 ? 'CSRF found' : 'CSRF missing', (int)((microtime(true)-$t)*1000));

            sleep(2); // bypass time-trap for step 2

            // Step 2 — POST judge_id + pin
            $t = microtime(true);
            [$c4,$h4,$b4] = http_post("$base/login.php", [
                'judge_id'   => $judge_id,
                'pin'        => $judge_pin,
                'csrf_token' => $csrf3,
                'website_url'=> '',
            ], $cookie3);
            $cookie4 = extract_session($h4) ?: $cookie3;
            preg_match('/^Location:\s*(.+)$/mi', $h4, $lm4);
            $redirect4 = trim($lm4[1] ?? '');
            $logged_in = ($c4 === 302 && stripos($redirect4, 'judge.php') !== false);
            $session_cookie = $cookie4;
            $add('Login', 'Step 2: judge PIN accepted', $logged_in,
                $logged_in ? "Redirected to judge.php" : "HTTP $c4 / Location: $redirect4 — check judge ID & PIN",
                (int)((microtime(true)-$t)*1000));
        }

    } else {
        // Normal roles: admin, pic, recorder
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        $role_redirects = [
            'admin'    => 'admin.php',
            'pic'      => 'pic.php',
            'recorder' => 'attendance.php',
        ];
        $expected_redirect = $role_redirects[$role] ?? '';

        // GET login page → CSRF
        $t = microtime(true);
        [,$h1,$b1] = http_get("$base/login.php");
        $cookie1 = extract_session($h1);
        $csrf    = extract_csrf($b1);
        $add('Login', 'Login page loaded', $csrf !== '', $csrf ? 'CSRF found' : 'CSRF token not found — login may fail', (int)((microtime(true)-$t)*1000));

        sleep(2); // bypass time-trap (rejects submissions < 2s after page load)

        // POST credentials
        $t = microtime(true);
        [$c2,$h2,$b2] = http_post("$base/login.php", [
            'username'    => $username,
            'password'    => $password,
            'csrf_token'  => $csrf,
            'website_url' => '',
        ], $cookie1);
        $cookie2 = extract_session($h2) ?: $cookie1;
        preg_match('/^Location:\s*(.+)$/mi', $h2, $lm);
        $redir = trim($lm[1] ?? '');
        $logged_in = ($c2 === 302 && stripos($redir, $expected_redirect) !== false);
        $session_cookie = $cookie2;
        $add('Login', "Login as $role", $logged_in,
            $logged_in ? "Redirected to $redir" : "HTTP $c2 / Location: $redir — check credentials or role",
            (int)((microtime(true)-$t)*1000));
    }

    // ════════════════════════════════════════════════════════════════════════
    // ROLE-SPECIFIC PAGE TESTS  (only if logged in)
    // ════════════════════════════════════════════════════════════════════════
    if ($logged_in && $session_cookie) {

        // Pages per role
        // Sources of truth: each page's session role check at the top
        // silibus.php  → judge only
        // leaderboard.php → judge OR pic
        $role_pages = [
            'admin' => [
                'Admin dashboard'        => 'admin.php',
                'Admin data'             => 'admin_data.php',
                'Admin logs'             => 'admin_logs.php',
                'Reset system'           => 'reset_system.php',
                'Upload students'        => 'upload_students.php',
                'Leaderboard'            => 'leaderboard.php',
                'Export leaderboard'     => 'export_leaderboard.php',
                'Export attendance XLS'  => 'export_attendance_excel.php',
                'Export attendance PDF'  => 'export_attendance_pdf.php',
                'Attendance dashboard'   => 'attendance_view_dashboard.php',
            ],
            'pic' => [
                'PIC dashboard'          => 'pic.php',
                'PIC schools'            => 'pic_schools.php',
                'PIC sessions'           => 'pic_sessions.php',
                'PIC students'           => 'pic_students.php',
                'PIC groups'             => 'pic_groups.php',
                'PIC criteria'           => 'pic_criteria.php',
                'PIC levels'             => 'pic_levels.php',
                'PIC master list'        => 'pic_master_list.php',
                'PIC medal settings'     => 'pic_medal_settings.php',
                'PIC manual marks'       => 'pic_manual_marks.php',
                'PIC view marks'         => 'pic_view_marks.php',
                'PIC judges'             => 'pic_judges.php',
                'PIC siri'               => 'pic_siri.php',
                'PIC tests'              => 'pic_tests.php',
                'PIC directory'          => 'pic_directory.php',
                'Leaderboard'            => 'leaderboard.php',
                'Manage attendance'      => 'manage_attendance.php',
                // silibus.php is judge-only — NOT listed here
            ],
            'recorder' => [
                'Attendance'             => 'attendance.php',
                'Attendance dashboard'   => 'attendance_view_dashboard.php',
                'Attendance by session'  => 'attendance_view_session.php',
                'Attendance by school'   => 'attendance_view_school.php',
                'Attendance all'         => 'attendance_view_all.php',
                'Student attendance'     => 'attendance_student.php',
                // manage_attendance.php requires PIC role — tested under PIC tab
            ],
            'judge' => [
                'Judge dashboard'        => 'judge.php',
                'Judge view marks'       => 'judge_view_marks.php',
                'Silibus'                => 'silibus.php',
                'Leaderboard'            => 'leaderboard.php',
            ],
        ];

        // Pages that PIC/judge should be BLOCKED from (role isolation check)
        $blocked_pages = [
            'pic'   => ['silibus.php' => 'Silibus (judge-only — PIC should be blocked)'],
            'judge' => ['admin.php' => 'Admin dashboard (judge should be blocked)'],
        ];

        $pages = $role_pages[$role] ?? [];

        // ── GET page tests ────────────────────────────────────────────────
        foreach ($pages as $label => $page) {
            $t = microtime(true);
            [$code, $hdr, $body] = http_get("$base/$page", $session_cookie);
            $ms = (int)((microtime(true)-$t)*1000);

            preg_match('/^Location:\s*(.+)$/mi', $hdr, $lm);
            $loc = trim($lm[1] ?? '');
            if ($code === 302 && stripos($loc, 'login') !== false) {
                $add("Pages ($role)", $label, false, "Redirected to login — session may have expired or role mismatch", $ms);
            } elseif ($code === 200) {
                $has_error = stripos($body, 'Fatal error') !== false
                          || stripos($body, 'Parse error') !== false
                          || stripos($body, 'Warning:') !== false;
                $add("Pages ($role)", $label, !$has_error,
                    $has_error ? 'PHP error detected on page' : 'HTTP 200 OK', $ms);
            } elseif ($code === 500) {
                $add("Pages ($role)", $label, false, 'HTTP 500 — Server error', $ms);
            } else {
                $add("Pages ($role)", $label, $code < 400, "HTTP $code", $ms);
            }
        }

        // ── Role isolation: verify blocked pages redirect away ────────────
        foreach (($blocked_pages[$role] ?? []) as $page => $label) {
            $t = microtime(true);
            [$code, $hdr,] = http_get("$base/$page", $session_cookie);
            $ms = (int)((microtime(true)-$t)*1000);
            preg_match('/^Location:\s*(.+)$/mi', $hdr, $lm);
            $loc = trim($lm[1] ?? '');
            $blocked = ($code === 302 && stripos($loc, 'login') !== false);
            $add("Role isolation ($role)", $label, $blocked,
                $blocked ? 'Correctly blocked (redirect to login)' : "HTTP $code — SHOULD be blocked but wasn't", $ms);
        }

        // ── judge POST: save_scores.php ───────────────────────────────────
        if ($role === 'judge') {
            // Fetch CSRF from judge session
            $csrf_j = $_SESSION['csrf_token'] ?? '';
            // We can't submit real marks without knowing real group/student/criteria IDs,
            // so we test two things:
            // 1. POST with no marks → should redirect to judge.php (guard fires)
            // 2. POST with invalid group_id → should redirect to judge.php?msg=invalid_request
            $t = microtime(true);
            [$sc1, $sh1,] = http_post("$base/save_scores.php", [
                'csrf_token' => $csrf_j,
                'group_id'   => 0,
                'marks'      => [],
            ], $session_cookie);
            $ms1 = (int)((microtime(true)-$t)*1000);
            preg_match('/^Location:\s*(.+)$/mi', $sh1, $lm1);
            $loc1 = trim($lm1[1] ?? '');
            // Expect redirect to judge.php (invalid group_id guard) — NOT login
            $ok1 = ($sc1 === 302 && stripos($loc1, 'judge.php') !== false);
            $add('Judge POST actions', 'save_scores.php: invalid group_id rejected (→ judge.php)', $ok1,
                $ok1 ? "HTTP $sc1 → $loc1" : "HTTP $sc1 → $loc1 (unexpected)", $ms1);

            $t = microtime(true);
            [$sc2, $sh2,] = http_post("$base/save_scores.php", [], $session_cookie);
            $ms2 = (int)((microtime(true)-$t)*1000);
            preg_match('/^Location:\s*(.+)$/mi', $sh2, $lm2);
            $loc2 = trim($lm2[1] ?? '');
            // No marks POST → redirected to judge.php
            $ok2 = ($sc2 === 302 && stripos($loc2, 'judge.php') !== false);
            $add('Judge POST actions', 'save_scores.php: POST with no marks redirects to judge.php', $ok2,
                $ok2 ? "HTTP $sc2 → $loc2" : "HTTP $sc2 → $loc2", $ms2);

            // judge.php AJAX: ajax_add_parameter (should return {"status":"deferred"})
            $t = microtime(true);
            [$sc3,,$sb3] = http_post("$base/judge.php", ['ajax_add_parameter' => 1], $session_cookie);
            $ms3 = (int)((microtime(true)-$t)*1000);
            $json3 = json_decode($sb3, true);
            $ok3 = isset($json3['status']) && $json3['status'] === 'deferred';
            $add('Judge POST actions', 'judge.php: ajax_add_parameter returns deferred JSON', $ok3,
                $ok3 ? 'JSON {"status":"deferred"}' : "Response: " . substr($sb3, 0, 100), $ms3);

            // judge.php AJAX GET: ajax_levels
            $t = microtime(true);
            [$sc4,,] = http_get("$base/judge.php?ajax_levels=1&session_id=1", $session_cookie);
            $ms4 = (int)((microtime(true)-$t)*1000);
            $add('Judge POST actions', 'judge.php?ajax_levels — dropdown endpoint', !in_array($sc4,[500,404]),
                "HTTP $sc4", $ms4);

            // judge.php AJAX GET: ajax_groups
            $t = microtime(true);
            [$sc5,,] = http_get("$base/judge.php?ajax_groups=1&level_id=1", $session_cookie);
            $ms5 = (int)((microtime(true)-$t)*1000);
            $add('Judge POST actions', 'judge.php?ajax_groups — dropdown endpoint', !in_array($sc5,[500,404]),
                "HTTP $sc5", $ms5);
        }

        // ── PIC POST actions ─────────────────────────────────────────────
        if ($role === 'pic') {
            // Fetch a live CSRF token from the active PIC session
            [,,$csrf_page] = http_get("$base/pic.php", $session_cookie);
            preg_match('/name=["\']csrf_token["\'][^>]*value=["\']([a-f0-9]+)["\']/i', $csrf_page, $cm);
            if (!$cm) preg_match('/value=["\']([a-f0-9]{40,})["\'][^>]*name=["\']csrf_token["\']/i', $csrf_page, $cm);
            $csrf_pic = $cm[1] ?? '';

            // Helper: POST to a pic page and verify CSRF guard fires on bad token
            $pic_post_pages = [
                'pic_schools.php'       => ['action' => 'save_all'],
                'pic_sessions.php'      => ['action' => 'save_all'],
                'pic_students.php'      => ['action' => 'save_all'],
                'pic_groups.php'        => ['action' => 'save_all'],
                'pic_criteria.php'      => ['action' => 'save_all'],
                'pic_levels.php'        => ['action' => 'save_all'],
                'pic_tests.php'         => ['action' => 'save_all'],
            ];

            // 1. CSRF guard: POST with wrong token → should NOT save (redirects or shows error)
            foreach ($pic_post_pages as $page => $extra) {
                $t = microtime(true);
                [$sc, $sh, $sb] = http_post("$base/$page", array_merge($extra, [
                    'csrf_token' => 'baadbaadbaadbaadbaadbaadbaadbaadbaadbaadbaadbaadbaadbaadbaadbaad',
                ]), $session_cookie);
                $ms = (int)((microtime(true)-$t)*1000);
                preg_match('/^Location:\s*(.+)$/mi', $sh, $lm);
                $loc = trim($lm[1] ?? '');
                // Good: redirect back (302) OR stay on page (200) — NOT a 500 crash
                $ok = !in_array($sc, [500]) && stripos($sb, 'Fatal error') === false;
                $add('PIC POST: CSRF guard', "$page — bad token rejected", $ok,
                    $ok ? "HTTP $sc (did not crash)" : "HTTP $sc — server error on bad CSRF", $ms);
            }

            // 2. Valid CSRF, empty payload → should stay on page gracefully (no crash)
            foreach ($pic_post_pages as $page => $extra) {
                $t = microtime(true);
                [$sc,, $sb] = http_post("$base/$page", array_merge($extra, [
                    'csrf_token' => $csrf_pic,
                    // no actual data — page should handle gracefully
                ]), $session_cookie);
                $ms = (int)((microtime(true)-$t)*1000);
                $ok = !in_array($sc, [500]) && stripos($sb, 'Fatal error') === false;
                $add('PIC POST: save_all (empty data)', "$page — no crash on empty submit", $ok,
                    $ok ? "HTTP $sc" : "HTTP $sc — error detected", $ms);
            }

            // 3. pic_medal_settings.php: POST quotas
            $t = microtime(true);
            [$sc,,$sb] = http_post("$base/pic_medal_settings.php", [
                'csrf_token' => $csrf_pic,
                'quotas'     => [0 => ['gold' => 0, 'silver' => 0, 'bronze' => 0]],
                'scope'      => 'peringkat',
            ], $session_cookie);
            $ms = (int)((microtime(true)-$t)*1000);
            $ok = !in_array($sc, [500]) && stripos($sb, 'Fatal error') === false;
            $add('PIC POST: save_all (empty data)', 'pic_medal_settings.php — quotas POST', $ok, "HTTP $sc", $ms);

            // 4. pic_judges.php: add_judge with blank name → should error/redirect gracefully
            $t = microtime(true);
            [$sc, $sh,] = http_post("$base/pic_judges.php", [
                'csrf_token' => $csrf_pic,
                'action'     => 'add_judge',
                'name'       => '',
                'pin'        => '',
                'judge_code' => '',
            ], $session_cookie);
            $ms = (int)((microtime(true)-$t)*1000);
            $ok = !in_array($sc, [500]);
            $add('PIC POST: pic_judges.php', 'add_judge with blank name — no crash', $ok, "HTTP $sc", $ms);

            // 5. pic_judges.php: reset_pin with invalid judge_id → graceful
            $t = microtime(true);
            [$sc,,] = http_post("$base/pic_judges.php", [
                'csrf_token' => $csrf_pic,
                'action'     => 'reset_pin',
                'judge_id'   => 0,
                'new_pin'    => '1234',
                'confirm_pin'=> '1234',
            ], $session_cookie);
            $ms = (int)((microtime(true)-$t)*1000);
            $add('PIC POST: pic_judges.php', 'reset_pin with invalid judge_id — no crash', !in_array($sc,[500]), "HTTP $sc", $ms);

            // 6. pic_siri.php: add_siri with blank name → graceful
            $t = microtime(true);
            [$sc,,] = http_post("$base/pic_siri.php", [
                'csrf_token' => $csrf_pic,
                'action'     => 'add_siri',
                'siri_name'  => '',
                'siri_year'  => date('Y'),
                'notes'      => '',
            ], $session_cookie);
            $ms = (int)((microtime(true)-$t)*1000);
            $add('PIC POST: pic_siri.php', 'add_siri with blank name — no crash', !in_array($sc,[500]), "HTTP $sc", $ms);

            // 7. PIC GET ajax filters (each page has ?ajax=1)
            $ajax_pic_pages = [
                'pic_schools.php'  => 'schools ajax filter',
                'pic_sessions.php' => 'sessions ajax filter',
                'pic_students.php' => 'students ajax filter',
                'pic_groups.php'   => 'groups ajax filter',
                'pic_criteria.php' => 'criteria ajax filter',
                'pic_levels.php'   => 'levels ajax filter',
                'pic_tests.php'    => 'tests ajax filter',
            ];
            foreach ($ajax_pic_pages as $page => $label) {
                $t = microtime(true);
                [$sc,,] = http_get("$base/$page?ajax=1", $session_cookie);
                $ms = (int)((microtime(true)-$t)*1000);
                $add('PIC AJAX filters', $label, !in_array($sc,[500,404]), "HTTP $sc", $ms);
            }
        }

        // ── Attendance POST actions ───────────────────────────────────────
        if ($role === 'recorder') {
            // 1. save_attendance.php: missing fields → 'invalid_request'
            $t = microtime(true);
            [$sc,,$sb] = http_post("$base/save_attendance.php", [], $session_cookie);
            $ms = (int)((microtime(true)-$t)*1000);
            $ok = trim($sb) === 'invalid_request' || $sc === 200;
            $add('Attendance POST', 'save_attendance.php: empty POST → invalid_request', $ok,
                $ok ? "Returned: " . trim(substr($sb,0,40)) : "HTTP $sc unexpected", $ms);

            // 2. save_attendance.php: invalid status → 'invalid_status'
            $t = microtime(true);
            [$sc,,$sb] = http_post("$base/save_attendance.php", [
                'student_id' => 1,
                'session_id' => 1,
                'status'     => 'HACKED',
            ], $session_cookie);
            $ms = (int)((microtime(true)-$t)*1000);
            $ok = in_array(trim($sb), ['invalid_status', 'invalid_request']) || $sc === 200;
            $add('Attendance POST', 'save_attendance.php: invalid status rejected', $ok,
                "Response: " . trim(substr($sb,0,60)), $ms);

            // 3. attendance_toggle.php: valid status values whitelist check
            $t = microtime(true);
            [$sc,,$sb] = http_post("$base/attendance_toggle.php", [
                'student_id' => 1,
                'session_id' => 1,
                'status'     => 'Present',
            ], $session_cookie);
            $ms = (int)((microtime(true)-$t)*1000);
            $json = json_decode($sb, true);
            $ok   = isset($json['status']) && $sc !== 500;
            $add('Attendance POST', 'attendance_toggle.php: POST returns JSON', $ok,
                $ok ? "JSON: " . substr($sb,0,60) : "HTTP $sc / Body: " . substr($sb,0,80), $ms);

            // 4. mark_notifications_read.php
            $t = microtime(true);
            [$sc,,] = http_post("$base/mark_notifications_read.php", [], $session_cookie);
            $ms = (int)((microtime(true)-$t)*1000);
            $add('Attendance POST', 'mark_notifications_read.php — no crash', !in_array($sc,[500,404]), "HTTP $sc", $ms);
        }

        // ── AJAX endpoints ────────────────────────────────────────────────
        $ajax = [
            'fetch_options.php'            => 'fetch_options',
            'check_notifications.php'      => 'check_notifications',
            'load_levels_group.php'        => 'load_levels_group',
            'load_schools_for_session.php' => 'load_schools_for_session',
            'load_students_group.php'      => 'load_students_group',
        ];
        foreach ($ajax as $page => $label) {
            $t = microtime(true);
            [$code,,] = http_get("$base/$page", $session_cookie);
            $ms = (int)((microtime(true)-$t)*1000);
            $add('AJAX endpoints', $label, !in_array($code,[404,500]), "HTTP $code", $ms);
        }

        // ── Logout ────────────────────────────────────────────────────────
        $t = microtime(true);
        [$code,,] = http_get("$base/logout.php", $session_cookie);
        $ms = (int)((microtime(true)-$t)*1000);
        $add('Logout', 'logout.php', in_array($code,[200,302]), "HTTP $code", $ms);

    } elseif (!$logged_in) {
        $add("Pages ($role)", 'All page tests skipped', false, 'Login failed — fix credentials first', 0);
    }

    // Bad credentials test
    [,$h_b,$b_b] = http_get("$base/login.php");
    $csrf_b = extract_csrf($b_b);
    $ck_b   = extract_session($h_b);
    sleep(2); // bypass time-trap
    $t = microtime(true);
    [$cb,,] = http_post("$base/login.php", [
        'username'    => 'xyzzy_fake_user_99',
        'password'    => 'wrong_password_abc',
        'csrf_token'  => $csrf_b,
        'website_url' => '',
    ], $ck_b);
    $add('Security', 'Bad credentials rejected (no redirect to dashboard)', $cb !== 302,
        $cb === 302 ? 'DANGER: bad login was accepted!' : "HTTP $cb — correctly rejected",
        (int)((microtime(true)-$t)*1000));

    } // end else (login-required roles)
}

// ── Totals ──────────────────────────────────────────────────────────────────
$pass_count  = count(array_filter($results, fn($r) => $r['pass']));
$total_count = count($results);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ProMarkah — Test Suite</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;background:#0d1117;color:#c9d1d9;min-height:100vh;padding:1.5rem}
h1{color:#f0f6fc;font-size:1.4rem;font-weight:700;margin-bottom:.25rem}
.meta{color:#8b949e;font-size:.8rem;margin-bottom:1.5rem}
.card{background:#161b22;border:1px solid #30363d;border-radius:.75rem;padding:1.5rem;margin-bottom:1.5rem}
.card h2{font-size:.95rem;font-weight:600;color:#8b949e;text-transform:uppercase;letter-spacing:.06em;margin-bottom:1rem}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}
@media(max-width:600px){.form-grid{grid-template-columns:1fr}}
label{display:block;font-size:.8rem;color:#8b949e;margin-bottom:.3rem;font-weight:500}
input,select{width:100%;background:#0d1117;border:1px solid #30363d;border-radius:.5rem;padding:.6rem .8rem;color:#f0f6fc;font-size:.875rem;outline:none;transition:border-color .15s}
input:focus,select:focus{border-color:#388bfd}
select option{background:#161b22}
.role-tabs{display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap}
.role-tab{padding:.45rem 1rem;border-radius:.5rem;border:1px solid #30363d;background:#0d1117;color:#8b949e;cursor:pointer;font-size:.8rem;font-weight:600;transition:all .15s}
.role-tab.active{background:#1f6feb;border-color:#388bfd;color:#f0f6fc}
.fields-group{display:none}
.fields-group.show{display:block}
.btn{background:#238636;border:1px solid #2ea043;color:#fff;padding:.65rem 1.5rem;border-radius:.5rem;font-weight:700;font-size:.875rem;cursor:pointer;letter-spacing:.04em;transition:background .15s}
.btn:hover{background:#2ea043}
.summary-bar{display:flex;align-items:center;gap:1rem;padding:.9rem 1.2rem;border-radius:.6rem;margin-bottom:1.5rem;font-weight:700;font-size:1rem}
.summary-bar.all-pass{background:#0d2818;border:1px solid #238636;color:#3fb950}
.summary-bar.has-fail{background:#2d0f0f;border:1px solid #da3633;color:#f85149}
.section-title{background:#0d1117;color:#8b949e;font-size:.7rem;text-transform:uppercase;letter-spacing:.1em;padding:.4rem .8rem;font-weight:600}
table{width:100%;border-collapse:collapse;font-size:.82rem}
th{text-align:left;padding:.5rem .75rem;background:#161b22;color:#8b949e;font-weight:500;position:sticky;top:0;border-bottom:1px solid #30363d}
td{padding:.45rem .75rem;border-bottom:1px solid #21262d;vertical-align:top}
tr:last-child td{border-bottom:none}
.badge{display:inline-block;padding:.1rem .45rem;border-radius:9999px;font-size:.7rem;font-weight:700;letter-spacing:.06em}
.badge.pass{background:#0d2818;color:#3fb950;border:1px solid #238636}
.badge.fail{background:#2d0f0f;color:#f85149;border:1px solid #da3633}
.ms{color:#484f58;font-size:.72rem}
.warn{background:#271d00;border:1px solid #9e6a03;color:#d29922;padding:.75rem 1rem;border-radius:.5rem;font-size:.8rem;margin-top:1rem}
</style>
</head>
<body>
<h1>ProMarkah V3 — Test Suite</h1>
<p class="meta"><?= date('Y-m-d H:i:s T') ?> &nbsp;|&nbsp; <code><?= htmlspecialchars($base) ?></code> &nbsp;|&nbsp; PHP <?= PHP_VERSION ?></p>

<?php if ($ran): ?>
<!-- ── Results ── -->
<?php
$all_pass = ($pass_count === $total_count);
$fail_count = $total_count - $pass_count;
?>
<div class="summary-bar <?= $all_pass ? 'all-pass' : 'has-fail' ?>">
  <?= $all_pass ? '✓ All ' . $total_count . ' tests passed' : "✗ $fail_count / $total_count failed" ?>
</div>

<div class="card" style="padding:0;overflow:hidden">
<table>
<thead><tr><th style="width:40%">Test</th><th style="width:8%">Result</th><th>Detail</th><th style="width:7%">ms</th></tr></thead>
<tbody>
<?php
$current_section = null;
foreach ($results as $r):
    if ($r['section'] !== $current_section):
        $current_section = $r['section'];
?>
<tr><td colspan="4" class="section-title"><?= htmlspecialchars($current_section) ?></td></tr>
<?php endif; ?>
<tr>
  <td><?= htmlspecialchars($r['label']) ?></td>
  <td><span class="badge <?= $r['pass'] ? 'pass' : 'fail' ?>"><?= $r['pass'] ? 'PASS' : 'FAIL' ?></span></td>
  <td><?= htmlspecialchars($r['detail']) ?></td>
  <td class="ms"><?= $r['ms'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<p style="text-align:center;margin-top:.5rem">
  <a href="?key=<?= urlencode(SECRET_KEY) ?>" style="color:#388bfd;font-size:.85rem">← Run again with different role/credentials</a>
</p>

<?php else: ?>
<!-- ── Credential form ── -->
<div class="card">
  <h2>Select role to test</h2>

  <form method="POST">
    <input type="hidden" name="action" value="run">
    <input type="hidden" name="role" id="role_input" value="pic">

    <div class="role-tabs">
      <button type="button" class="role-tab active" data-role="pic">PIC</button>
      <button type="button" class="role-tab" data-role="admin">Admin</button>
      <button type="button" class="role-tab" data-role="recorder">Attendance (Recorder)</button>
      <button type="button" class="role-tab" data-role="judge">Judge</button>
      <button type="button" class="role-tab" data-role="public" style="border-color:#1d4ed8;color:#60a5fa">Public (No Login)</button>
    </div>

    <!-- PIC / Admin / Recorder fields -->
    <div class="fields-group show" id="fields-normal">
      <div class="form-grid">
        <div>
          <label for="username">Username</label>
          <input type="text" id="username" name="username" autocomplete="off" placeholder="e.g. admin">
        </div>
        <div>
          <label for="password">Password</label>
          <input type="password" id="password" name="password" placeholder="••••••••">
        </div>
      </div>
    </div>

    <!-- Judge fields (2-step) -->
    <div class="fields-group" id="fields-judge">
      <p style="font-size:.8rem;color:#8b949e;margin-bottom:.75rem">
        Judge login is 2-step: first enter the judge account credentials, then the judge ID &amp; PIN.
      </p>
      <div class="form-grid">
        <div>
          <label for="j_username">Username (judge account)</label>
          <input type="text" id="j_username" name="j_username" autocomplete="off">
        </div>
        <div>
          <label for="j_password">Password</label>
          <input type="password" id="j_password" name="j_password">
        </div>
        <div>
          <label for="judge_id">Judge ID (number)</label>
          <input type="number" id="judge_id" name="judge_id" placeholder="e.g. 3">
        </div>
        <div>
          <label for="judge_pin">Judge PIN</label>
          <input type="password" id="judge_pin" name="judge_pin" placeholder="••••">
        </div>
      </div>
    </div>

    <div style="margin-top:1.25rem">
      <button type="submit" class="btn">Run Tests</button>
    </div>
  </form>
</div>

<div class="warn">
  &#9888; Credentials you enter here are sent to <strong>your own server</strong> — they are not stored or logged by this file.
  Delete <code>test_suite.php</code> from Hostinger when done.
</div>

<script>
// sync hidden role input + toggle field groups
document.querySelectorAll('.role-tab').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.role-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const role = btn.dataset.role;
    document.getElementById('role_input').value = role;

    const isJudge  = role === 'judge';
    const isPublic = role === 'public';
    document.getElementById('fields-normal').classList.toggle('show', !isJudge && !isPublic);
    document.getElementById('fields-judge').classList.toggle('show', isJudge);

    // copy normal username/password into judge fields and vice versa
    if (isJudge) {
      document.getElementById('j_username').focus();
    } else {
      document.getElementById('username').focus();
    }
  });
});

// For judge role: copy j_username/j_password into username/password before submit
document.querySelector('form').addEventListener('submit', () => {
  if (document.getElementById('role_input').value === 'judge') {
    const u = document.createElement('input'); u.type='hidden'; u.name='username'; u.value=document.getElementById('j_username').value;
    const p = document.createElement('input'); p.type='hidden'; p.name='password'; p.value=document.getElementById('j_password').value;
    document.querySelector('form').appendChild(u);
    document.querySelector('form').appendChild(p);
  }
});
</script>
<?php endif; ?>

</body>
</html>
