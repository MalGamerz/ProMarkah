<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

session_start();
require __DIR__ . '/auth_check.php';
date_default_timezone_set('Asia/Kuala_Lumpur');
include 'db.php';
$conn = getDB();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pic') {
    header('Location: login.php'); exit;
}

$pm_page = 'judges';
$success = '';
$error   = '';

// json_encode() wraps strings in double quotes, which collides with
// onclick="..." attributes (also double-quoted) the instant a name/email
// contains one — the browser sees the attribute as closed early and the
// rest becomes garbage HTML, which is exactly what broke the Edit button.
// Escaping the JSON with ENT_QUOTES turns those into &quot;/&#039; entities,
// which the browser decodes back to real characters before running the JS.
function js_arg($val): string
{
    return htmlspecialchars(json_encode($val), ENT_QUOTES);
}

// ── SELF-HEALING SCHEMA: add judges.email if it doesn't exist yet ──
// Needed to link a judge's Google/Apple Sign-In to their existing account
// (see oauth_google_callback.php). Same defensive pattern used elsewhere
// in this app (e.g. judge.php's scores.judge_id check) instead of requiring
// a manual migration step on deploy.
//
// db.php runs mysqli in MYSQLI_REPORT_STRICT mode, so any SQL error here
// (e.g. the DB user lacking ALTER privilege on some hosting plans) would
// otherwise throw an uncaught exception and crash the whole page with a
// blank 500 — this file also has display_errors off, so that crash would
// be completely silent to whoever's testing it. Catch it and surface a
// real message instead.
$email_column_ready = true;
try {
    $col_check = $conn->query("SHOW COLUMNS FROM judges LIKE 'email'");
    if ($col_check && $col_check->num_rows === 0) {
        $conn->query("ALTER TABLE judges ADD COLUMN email VARCHAR(255) NULL UNIQUE AFTER name");
    }
} catch (Throwable $e) {
    // Throwable (not just mysqli_sql_exception) — a TypeError/Error is a
    // sibling class to Exception in PHP and would otherwise slip straight
    // past a narrower catch and crash the page with a raw 500.
    promarkah_report('Caught', 'failed to add judges.email column — ' . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    $email_column_ready = false;
    $error = 'Ciri e-mel juri belum tersedia (ralat pangkalan data semasa menetapkan lajur e-mel). Hubungi pentadbir sistem.';
}

// ── CSRF ──────────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── HANDLE POST ACTIONS ───────────────────────────────────────────────────
// Wrapped in try/catch: db.php runs mysqli in MYSQLI_REPORT_STRICT mode, so
// any SQL error (bad constraint, unexpected duplicate, etc.) throws instead
// of just returning false — and with display_errors off on this page, an
// uncaught one crashes silently into a blank 500 rather than showing
// anything useful. Turn that into a real (if generic) on-page message.
try {
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Token keselamatan tidak sah. Sila muat semula halaman.';
    } else {

        $action = $_POST['action'] ?? '';

        // ── ADD JUDGE ──
        if ($action === 'add_judge') {
            $name       = trim($_POST['name'] ?? '');
            $pin        = trim($_POST['pin'] ?? '');
            $judge_code = trim($_POST['judge_code'] ?? '');
            $email      = trim($_POST['email'] ?? '');
            $email      = $email === '' ? null : $email;

            if (!$name || !$pin || !$judge_code) {
                $error = 'Semua medan wajib diisi.';
            } elseif (!preg_match('/^\d{6}$/', $pin)) {
                $error = 'PIN mesti tepat 6 digit angka.';
            } elseif ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Format e-mel tidak sah.';
            } else {
                // Each prepared statement's result must be fully read
                // (get_result()) and closed BEFORE preparing the next one
                // on the same connection — mysqli throws "Commands out of
                // sync" otherwise, which is what broke this originally.
                $chk = $conn->prepare("SELECT id FROM judges WHERE judge_code = ? LIMIT 1");
                $chk->bind_param("s", $judge_code);
                $chk->execute();
                $judge_code_taken = $chk->get_result()->num_rows > 0;
                $chk->close();

                $email_taken = false;
                if ($email !== null) {
                    $chkEmail = $conn->prepare("SELECT id FROM judges WHERE email = ? LIMIT 1");
                    $chkEmail->bind_param("s", $email);
                    $chkEmail->execute();
                    $email_taken = $chkEmail->get_result()->num_rows > 0;
                    $chkEmail->close();
                }

                if ($judge_code_taken) {
                    $error = 'Kod Juri sudah wujud. Sila guna yang lain.';
                } elseif ($email_taken) {
                    $error = 'E-mel ini sudah digunakan oleh juri lain.';
                } else {
                    $pin_hash = password_hash($pin, PASSWORD_BCRYPT);
                    $stmt = $conn->prepare("INSERT INTO judges (name, pin_hash, role, judge_code, email) VALUES (?,?,'judge',?,?)");
                    $stmt->bind_param("ssss", $name, $pin_hash, $judge_code, $email);
                    if ($stmt->execute()) {
                        $success = "Juri <strong>" . htmlspecialchars($name) . "</strong> berjaya ditambah dengan kod <strong>" . htmlspecialchars($judge_code) . "</strong>.";
                    } else {
                        $error = 'Gagal menyimpan. Sila cuba lagi.';
                    }
                    $stmt->close();
                }
            }
        }

        // ── RESET PIN ──
        if ($action === 'reset_pin') {
            $judge_id = (int)($_POST['judge_id'] ?? 0);
            $new_pin  = trim($_POST['new_pin'] ?? '');
            $confirm  = trim($_POST['confirm_pin'] ?? '');

            if (!$judge_id) {
                $error = 'Juri tidak dijumpai.';
            } elseif (!preg_match('/^\d{6}$/', $new_pin)) {
                $error = 'PIN baharu mesti tepat 6 digit angka.';
            } elseif ($new_pin !== $confirm) {
                $error = 'PIN baharu dan pengesahan tidak sepadan.';
            } else {
                $pin_hash = password_hash($new_pin, PASSWORD_BCRYPT);
                $stmt = $conn->prepare("UPDATE judges SET pin_hash = ? WHERE id = ?");
                $stmt->bind_param("si", $pin_hash, $judge_id);
                if ($stmt->execute()) {
                    $success = 'PIN juri berjaya dikemaskini.';
                } else {
                    $error = 'Gagal mengemas kini PIN.';
                }
                $stmt->close();
            }
        }

        // ── EDIT JUDGE INFO ──
        if ($action === 'edit_judge') {
            $judge_id   = (int)($_POST['judge_id'] ?? 0);
            $name       = trim($_POST['name'] ?? '');
            $judge_code = trim($_POST['judge_code'] ?? '');
            $email      = trim($_POST['email'] ?? '');
            $email      = $email === '' ? null : $email;

            if (!$judge_id || !$name || !$judge_code) {
                $error = 'Semua medan wajib diisi.';
            } elseif ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Format e-mel tidak sah.';
            } else {
                $chk = $conn->prepare("SELECT id FROM judges WHERE judge_code = ? AND id != ? LIMIT 1");
                $chk->bind_param("si", $judge_code, $judge_id);
                $chk->execute();
                $judge_code_taken = $chk->get_result()->num_rows > 0;
                $chk->close();

                $email_taken = false;
                if ($email !== null) {
                    $chkEmail = $conn->prepare("SELECT id FROM judges WHERE email = ? AND id != ? LIMIT 1");
                    $chkEmail->bind_param("si", $email, $judge_id);
                    $chkEmail->execute();
                    $email_taken = $chkEmail->get_result()->num_rows > 0;
                    $chkEmail->close();
                }

                if ($judge_code_taken) {
                    $error = 'Kod Juri sudah digunakan oleh juri lain.';
                } elseif ($email_taken) {
                    $error = 'E-mel ini sudah digunakan oleh juri lain.';
                } else {
                    $stmt = $conn->prepare("UPDATE judges SET name = ?, judge_code = ?, email = ? WHERE id = ?");
                    $stmt->bind_param("sssi", $name, $judge_code, $email, $judge_id);
                    if ($stmt->execute()) {
                        $success = 'Maklumat juri berjaya dikemaskini.';
                    } else {
                        $error = 'Gagal mengemas kini maklumat.';
                    }
                    $stmt->close();
                }
            }
        }

        // ── DELETE JUDGE ──
        if ($action === 'delete_judge') {
            $judge_id = (int)($_POST['judge_id'] ?? 0);
            if ($judge_id) {
                $stmt = $conn->prepare("DELETE FROM judges WHERE id = ?");
                $stmt->bind_param("i", $judge_id);
                if ($stmt->execute()) {
                    $success = 'Juri berjaya dipadam.';
                } else {
                    $error = 'Gagal memadam juri.';
                }
                $stmt->close();
            }
        }
    }
}
} catch (Throwable $e) {
    promarkah_report('Caught', 'POST action failed — ' . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    $error = 'Ralat pangkalan data berlaku semasa menyimpan. Sila cuba lagi atau hubungi pentadbir sistem.';
}

// ── FETCH ALL JUDGES ──────────────────────────────────────────────────────
$judges = [];
$res = $conn->query("
    SELECT j.id, j.name, j.judge_code, j.email,
           COUNT(DISTINCT g.group_id) AS total_groups
    FROM judges j
    LEFT JOIN `groups` g ON g.judge_id = j.id
    GROUP BY j.id
    ORDER BY j.judge_code ASC
");

if ($res) {
    while ($r = $res->fetch_assoc()) $judges[] = $r;
}

include 'layout.php';
?>

<?php
// Auto-generate the next judge code (e.g., J001, J002)
$next_judge_code = 'J001';
$code_query = $conn->query("SELECT judge_code FROM judges WHERE judge_code LIKE 'J%' ORDER BY CAST(SUBSTRING(judge_code, 2) AS UNSIGNED) DESC LIMIT 1");
if ($code_query && $code_query->num_rows > 0) {
    $last_code = $code_query->fetch_assoc()['judge_code'];
    $last_num = (int)substr($last_code, 1);
    $next_judge_code = 'J' . str_pad($last_num + 1, 3, '0', STR_PAD_LEFT);
}
?>

<style>
    /* ── PAGE HEADER ── */
    .page-header-row {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        flex-wrap: wrap;
        gap: 20px;
        margin-bottom: 24px;
    }
    .page-title-group {
        display: flex;
        align-items: baseline;
        gap: 12px;
    }
    .page-title {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 2.2rem;
        color: var(--c-white);
        letter-spacing: 0.05em;
        margin: 0;
    }
    .badge-count {
        background: var(--c-red-dim);
        color: var(--c-red);
        font-family: sans-serif;
        font-size: 0.9rem;
        font-weight: 700;
        padding: 4px 12px;
        border-radius: 20px;
    }

    /* ── SEARCH BAR ── */
    .search-input {
        background: var(--c-surface-2);
        border: 1px solid var(--c-border-strong);
        border-radius: 8px;
        padding: 10px 16px 10px 40px;
        color: var(--c-white);
        font-size: 0.95rem;
        outline: none;
        min-width: 320px;
        transition: all 0.2s ease;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23a1a1aa' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='8'%3E%3C/circle%3E%3Cline x1='21' y1='21' x2='16.65' y2='16.65'%3E%3C/line%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: 12px center;
    }
    .search-input:focus {
        border-color: var(--c-red);
        box-shadow: 0 0 0 3px var(--c-red-dim);
    }

    /* ── ALERTS ── */
    .pm-alert {
        padding: 16px 20px;
        border-radius: 8px;
        margin-bottom: 24px;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .pm-alert-success { background: rgba(74,222,128,0.1); border: 1px solid rgba(74,222,128,0.4); color: #4ade80; }
    .pm-alert-danger { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.4); color: #f87171; }

    /* ── HORIZONTAL TOOLBAR FORM (ADD JUDGE) ── */
    .add-toolbar {
        background: var(--c-surface-1);
        border: 1px solid var(--c-border-strong);
        border-radius: 12px;
        padding: 20px 24px 28px;
        margin-bottom: 32px;
    }
    .toolbar-title {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 0.82rem;
        letter-spacing: 0.14em;
        color: var(--c-red);
        display: block;
        padding-bottom: 14px;
        margin-bottom: 16px;
        border-bottom: 1px solid var(--c-border-strong);
    }
    .toolbar-fields-row {
        display: flex;
        align-items: flex-end;
        flex-wrap: wrap;
        gap: 12px;
    }
    .toolbar-group {
        display: flex;
        flex-direction: column;
        margin: 0;
        min-width: 0;
    }
    .toolbar-group.flex-name { flex: 3; min-width: 200px; }
    .toolbar-group.flex-code { flex: 1; min-width: 120px; }
    .toolbar-group.flex-pin { flex: 0 0 auto; }
    .toolbar-group.flex-actions { 
        flex: 0 0 auto; 
        display: flex; 
        flex-direction: row; 
        gap: 8px;
    }

    /* PIN group: strength bar is absolute so it never affects row height */
    .pin-group-inner {
        position: relative;
        display: flex;
        flex-direction: column;
    }
    .strength-bar-wrap {
        position: absolute;
        bottom: -8px;
        left: 0;
        right: 0;
        height: 3px;
        background: var(--c-border-strong);
        border-radius: 4px;
        overflow: hidden;
    }
    .strength-bar { 
        height: 100%; 
        border-radius: 4px; 
        transition: width 0.3s ease, background 0.3s ease; 
    }

    .pm-form-label {
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: var(--c-text-muted);
        margin-bottom: 7px;
        display: block;
        line-height: 1;
    }
    .pm-form-label-hint {
        font-size: 0.65rem;
        color: var(--c-text-faint);
        text-transform: none;
        letter-spacing: 0;
        font-weight: 400;
        float: right;
        margin-top: 1px;
    }

    .pm-form-input {
        background: var(--c-surface-2);
        border: 1px solid var(--c-border-strong);
        border-radius: 8px;
        padding: 0 14px;
        color: var(--c-white);
        font-size: 0.9rem;
        outline: none;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
        width: 100%;
        height: 42px;
        box-sizing: border-box;
    }
    .pm-form-input:focus {
        border-color: var(--c-red);
        box-shadow: 0 0 0 3px var(--c-red-dim);
    }

    /* ── PIN INPUT ── */
    .pin-input-row { 
        display: flex; 
        gap: 6px; 
    }
    .pin-digit {
        width: 36px; 
        height: 42px;
        background: var(--c-surface-2);
        border: 1px solid var(--c-border-strong);
        border-radius: 8px;
        text-align: center;
        font-size: 1.1rem;
        font-weight: 700;
        font-family: monospace;
        color: var(--c-white);
        outline: none;
        padding: 0;
        transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
        box-sizing: border-box;
    }
    .pin-digit:focus {
        border-color: var(--c-red);
        box-shadow: 0 0 0 3px var(--c-red-dim);
    }
    .pin-digit.filled {
        border-color: var(--c-red);
        background: var(--c-red-dim);
    }

    /* ── BUTTONS ── */
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        padding: 0 18px;
        height: 42px;
        border-radius: 8px;
        font-weight: 600;
        font-size: 0.875rem;
        cursor: pointer;
        transition: all 0.15s ease;
        border: none;
        white-space: nowrap;
        box-sizing: border-box;
    }
    .btn-primary {
        background: var(--c-red);
        color: white;
    }
    .btn-primary:hover:not(:disabled) {
        background: #9f1239;
    }
    .btn-primary:disabled { 
        opacity: 0.5; 
        cursor: not-allowed;
    }
    .btn-outline {
        background: transparent;
        border: 1px solid var(--c-border-strong);
        color: var(--c-text);
    }
    
    .btn-outline:hover {
        background: var(--c-surface-2);
        border-color: var(--c-border);
        color: var(--c-white);
    }
    
    .btn-icon-square {
        width: 42px;
        padding: 0;
    }
    
    .btn-icon {
        padding: 10px;
        background: rgba(239,68,68,0.1);
        color: #f87171;
        border: 1px solid rgba(239,68,68,0.25);
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    
    .btn-icon:hover { 
        background: rgba(239,68,68,0.2); 
    }

    /* ── JUDGE TABLE ── */
    .juri-table-wrap {
        background: var(--c-surface-1);
        border: 1px solid var(--c-border-strong);
        border-radius: 10px;
        overflow: hidden;
    }
    .juri-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.875rem;
        /* Fixed column widths (see the <th> width= attributes below)
           instead of auto layout — auto layout was sizing each column to
           its own longest cell and dumping all the table's leftover width
           into whichever short-content column the browser's algorithm
           favored (PIN/Kumpulan here), instead of distributing it sensibly
           across the row. */
        table-layout: fixed;
    }
    .juri-table thead tr {
        border-bottom: 1px solid var(--c-border-strong);
    }
    .juri-table th {
        padding: 11px 16px;
        text-align: left;
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: var(--c-text-muted);
        white-space: nowrap;
    }
    .juri-table th:last-child { text-align: right; }
    .juri-table tbody tr {
        border-bottom: 1px solid var(--c-border-strong);
        transition: background 0.1s;
    }
    .juri-table tbody tr:last-child { border-bottom: none; }
    .juri-table tbody tr:hover { background: var(--c-surface-2); }
    .juri-table td {
        padding: 13px 16px;
        color: var(--c-text);
        vertical-align: middle;
    }
    .juri-table td:last-child {
        text-align: right;
        white-space: nowrap;
    }
    /* Single-line truncation with ellipsis instead of wrapping — a long
       judge name or email wrapping to a second line inflates that row's
       height, which looks worse than truncating with the full value still
       available via the title= tooltip on hover. */
    .juri-truncate {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 0; /* forces the cell to respect its table-layout:fixed column width instead of growing to fit content */
    }
    .td-name {
        font-weight: 600;
        color: var(--c-white);
    }
    .juri-code {
        font-size: 0.72rem;
        font-family: monospace;
        color: var(--c-red);
        background: var(--c-red-dim);
        border: 1px solid rgba(239,68,68,0.3);
        border-radius: 4px;
        padding: 2px 7px;
        letter-spacing: 0.05em;
        display: inline-block;
    }
    .td-pin {
        letter-spacing: 3px;
        color: var(--c-text-muted);
        font-size: 0.6rem;
    }
    .td-num {
        font-weight: 600;
        color: var(--c-white);
        font-variant-numeric: tabular-nums;
    }
    .row-actions { display: inline-flex; gap: 6px; align-items: center; }
    .row-actions .btn { height: 34px; padding: 0 12px; font-size: 0.8rem; }
    .btn-del {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        background: transparent;
        border: 1px solid rgba(239,68,68,0.3);
        border-radius: 6px;
        color: var(--c-red);
        cursor: pointer;
        transition: background 0.15s;
        flex-shrink: 0;
    }
    .btn-del:hover { background: rgba(239,68,68,0.12); }

    /* ── TABLE SCROLL: height is set dynamically in JS (fitJuriTableHeight)
       so it always leaves room for the pagination bar below it, instead of a
       static calc(100vh - Npx) that goes stale whenever the filter/search UI
       above the table changes height. ── */
    .juri-table-scroll {
        overflow-x: auto;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
    }
    @media (max-width: 640px) {
        .juri-table-scroll { max-height: none !important; overflow-y: visible !important; }
    }

    /* ── PAGINATION: shared .vm-pagination styles now live once in
       dashboard.css (loaded by layout.php), used by every paginated page. ── */

    /* ── MODALS ── */
    .pm-modal-overlay {
        position: fixed; inset: 0;
        background: rgba(0,0,0,0.75);
        backdrop-filter: blur(4px);
        z-index: 9999;
        display: none; align-items: center; justify-content: center;
        opacity: 0; transition: opacity 0.2s;
    }
    .pm-modal-overlay.show { opacity: 1; }
    .pm-modal-box {
        background: var(--c-surface-1);
        border: 1px solid var(--c-border-strong);
        border-radius: 14px;
        width: 100%; max-width: var(--pm-modal-w-sm);
        margin: 20px;
        padding: 32px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.7);
        transform: translateY(20px);
        transition: transform 0.3s;
    }
    .pm-modal-overlay.show .pm-modal-box { transform: translateY(0); }
    
    .pm-modal-title {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 1.6rem;
        letter-spacing: 0.05em;
        color: var(--c-white);
        border-bottom: 3px solid var(--c-red);
        display: inline-block;
        padding-bottom: 6px;
        margin-bottom: 24px;
    }
    
    .pm-modal-actions { display: flex; gap: 12px; margin-top: 28px; }
    .pm-modal-actions .btn { width: auto; flex: 1; }

    @media (max-width: 860px) {
        .search-input { min-width: 100%; }
        .toolbar-fields-row { flex-direction: column; align-items: stretch; }
        .toolbar-group.flex-actions { flex-direction: row; }
        .pin-input-row { justify-content: flex-start; }
        .juri-table th:nth-child(3),
        .juri-table td:nth-child(3) { display: none; }
    }
</style>

<div class="page-header-row">
    <div>
        <div class="page-title-group" style="margin-bottom: 6px;">
            <h2 class="page-title">👨‍⚖️ PENGURUSAN JURI</h2>
            <span class="badge-count"><?= count($judges) ?></span>
        </div>
        <p style="color: var(--c-text-muted); font-size: 0.9rem; margin: 0;">
            Urus senarai juri, kod juri, dan tetapan PIN keselamatan untuk sistem pemarkahan.
        </p>
    </div>
    <input type="text" id="judgeSearch" class="search-input" placeholder="Cari nama atau kod juri...">
</div>

<?php if ($success): ?>
<div class="pm-alert pm-alert-success">
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
    <?= $success ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="pm-alert pm-alert-danger">
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
    <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<form method="POST" class="add-toolbar">
    <div class="toolbar-title">+ TAMBAH JURI BAHARU</div>
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="action" value="add_judge">

    <div class="toolbar-fields-row">
        <div class="toolbar-group flex-name">
            <label class="pm-form-label">Nama Penuh *</label>
            <input type="text" name="name" class="pm-form-input" placeholder="cth. Ahmad Fauzi" required>
        </div>
        
        <div class="toolbar-group flex-code">
            <label class="pm-form-label">Kod Juri *</label>
            <input type="text" name="judge_code" class="pm-form-input" value="<?= $next_judge_code ?>" required readonly
                   style="cursor: not-allowed; opacity: 0.6; user-select: none;" title="Kod Juri dijana secara automatik">
        </div>

        <div class="toolbar-group flex-name">
            <label class="pm-form-label">
                E-mel <span class="pm-form-label-hint">Untuk Log Masuk Google</span>
            </label>
            <input type="email" name="email" class="pm-form-input" placeholder="cth. ahmad@gmail.com">
        </div>

        <div class="toolbar-group flex-pin">
            <label class="pm-form-label">
                PIN (6 Digit) * <span class="pm-form-label-hint">Elak 123456</span>
            </label>
            <div class="pin-group-inner">
                <div class="pin-input-row" id="addPinRow">
                    <?php for ($i = 0; $i < 6; $i++): ?>
                    <input type="text" inputmode="numeric" pattern="[0-9]" maxlength="1"
                        class="pin-digit add-pin-digit" data-idx="<?= $i ?>">
                    <?php endfor; ?>
                </div>
                <div class="strength-bar-wrap"><div class="strength-bar" id="pinStrengthBar" style="width:0;background:#ef4444;"></div></div>
            </div>
            <input type="hidden" name="pin" id="addPinHidden">
        </div>

        <div class="toolbar-group flex-actions">
            <button type="submit" class="btn btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                Simpan
            </button>
            <button type="reset" class="btn btn-outline btn-icon-square" title="Reset Borang">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path></svg>
            </button>
        </div>
    </div>
</form>

<?php if (empty($judges)): ?>
<div style="text-align:center;padding:60px 24px;color:var(--c-text-faint);background:var(--c-surface-1);border:1px solid var(--c-border-strong);border-radius:10px;">
    <p style="font-size:0.95rem;color:var(--c-text-muted);margin:0;">Belum ada juri didaftarkan.</p>
</div>
<?php else: ?>
<div class="juri-table-wrap">
    <div class="juri-table-scroll">
        <table class="juri-table" id="juriGrid">
            <thead>
                <tr>
                    <th style="width:4%;text-align:center;color:var(--c-text-faint);">No</th>
                    <th style="width:19%;">Juri</th>
                    <th style="width:8%;">Kod</th>
                    <th style="width:21%;">E-mel</th>
                    <th style="width:8%;">PIN</th>
                    <th style="width:10%;">Kumpulan</th>
                    <th style="width:30%;">Tindakan</th>
                </tr>
            </thead>
            <tbody id="juriTbody">
            <?php foreach ($judges as $idx => $j): ?>
            <tr class="juri-row" data-search="<?= strtolower($j['name'] . ' ' . $j['judge_code'] . ' ' . ($j['email'] ?? '')) ?>">
                <td style="text-align:center;color:var(--c-text-faint);"><?= $idx + 1 ?></td>
                <td class="td-name juri-truncate" title="<?= htmlspecialchars($j['name']) ?>"><?= htmlspecialchars($j['name']) ?></td>
                <td><span class="juri-code"><?= htmlspecialchars($j['judge_code']) ?></span></td>
                <td class="juri-truncate" style="color:var(--c-text-muted);font-size:0.82rem;" title="<?= htmlspecialchars($j['email'] ?? '') ?>">
                    <?= $j['email'] ? htmlspecialchars($j['email']) : '<span style="opacity:0.5;">— Tiada —</span>' ?>
                </td>
                <td><span class="td-pin">••••••</span></td>
                <td class="td-num"><?= $j['total_groups'] ?></td>
                <td>
                    <div class="row-actions">
                        <button class="btn btn-outline"
                            onclick="openEditModal(<?= (int)$j['id'] ?>, <?= js_arg($j['name']) ?>, <?= js_arg($j['judge_code']) ?>, <?= js_arg($j['email'] ?? '') ?>)">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                            Edit
                        </button>
                        <button class="btn btn-outline"
                            onclick="openPinModal(<?= (int)$j['id'] ?>, <?= js_arg($j['name']) ?>)">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 18v3c0 .6.4 1 1 1h4v-3h3v-3h2l1.4-1.4a6.5 6.5 0 1 0-4-4Z"></path><circle cx="16.5" cy="7.5" r=".5"></circle></svg>
                            Reset PIN
                        </button>
                        <button class="btn-del" title="Padam Juri"
                            onclick="confirmDelete(<?= (int)$j['id'] ?>, <?= js_arg($j['name']) ?>)">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="vm-pagination" id="juriPaginationContainer">
        <div class="vm-page-info" id="juriPageInfo"></div>
        <div class="vm-page-btns" id="juriPaginationButtons"></div>
    </div>
</div>
<?php endif; ?>

<div class="pm-modal-overlay" id="modal-edit">
    <div class="pm-modal-box">
        <div class="pm-modal-title">Edit Maklumat Juri</div>
        <form method="POST" id="editForm">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="action" value="edit_judge">
            <input type="hidden" name="judge_id" id="editJudgeId">
            <div class="pm-form-group" style="margin-bottom:16px;">
                <label class="pm-form-label">Nama Penuh</label>
                <input type="text" name="name" id="editName" class="pm-form-input" required>
            </div>
            <div class="pm-form-group" style="margin-bottom:16px;">
                <label class="pm-form-label">Kod Juri</label>
                <input type="text" name="judge_code" id="editJudgeCode" class="pm-form-input" required>
            </div>
            <div class="pm-form-group">
                <label class="pm-form-label">
                    E-mel <span class="pm-form-label-hint">Untuk Log Masuk Google</span>
                </label>
                <input type="email" name="email" id="editEmail" class="pm-form-input" placeholder="cth. ahmad@gmail.com">
            </div>
            <div class="pm-modal-actions">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-edit')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<div class="pm-modal-overlay" id="modal-pin">
    <div class="pm-modal-box">
        <div class="pm-modal-title">Reset PIN Juri</div>
        <p id="pinModalSubtitle" style="color:var(--c-text-muted);font-size:0.9rem;margin-bottom:24px;"></p>
        <form method="POST" id="pinForm">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="action" value="reset_pin">
            <input type="hidden" name="judge_id" id="pinJudgeId">
            <input type="hidden" name="new_pin" id="newPinHidden">
            <input type="hidden" name="confirm_pin" id="confirmPinHidden">

            <div class="pm-form-group" style="align-items:center; margin-bottom:16px;">
                <label class="pm-form-label">PIN Baharu (6 Digit)</label>
                <div class="pin-input-row" id="newPinRow">
                    <?php for ($i = 0; $i < 6; $i++): ?>
                    <input type="text" inputmode="numeric" pattern="[0-9]" maxlength="1"
                           class="pin-digit new-pin-digit" style="width:44px; height:42px; font-size:1.4rem;" data-idx="<?= $i ?>">
                    <?php endfor; ?>
                </div>
            </div>
            <div class="pm-form-group" style="align-items:center;">
                <label class="pm-form-label">Sahkan PIN Baharu</label>
                <div class="pin-input-row" id="confirmPinRow">
                    <?php for ($i = 0; $i < 6; $i++): ?>
                    <input type="text" inputmode="numeric" pattern="[0-9]" maxlength="1"
                           class="pin-digit confirm-pin-digit" style="width:44px; height:42px; font-size:1.4rem;" data-idx="<?= $i ?>">
                    <?php endfor; ?>
                </div>
            </div>
            <div id="pinMatchMsg" style="text-align:center;font-size:0.85rem;min-height:20px;margin-bottom:12px;font-weight:500;"></div>

            <div class="pm-modal-actions">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-pin')">Batal</button>
                <button type="submit" class="btn btn-primary" id="pinSaveBtn" disabled>Simpan PIN</button>
            </div>
        </form>
    </div>
</div>

<form method="POST" id="deleteForm" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="action" value="delete_judge">
    <input type="hidden" name="judge_id" id="deleteJudgeId">
</form>

<script>
// ── PIN INPUT HELPERS ──────────────────────────────────────────────────────
function setupPinRow(selector, hiddenInputId, onComplete) {
    const digits = document.querySelectorAll(selector);
    digits.forEach((inp, i) => {
        inp.addEventListener('input', function () {
            this.value = this.value.replace(/[^0-9]/g, '').slice(-1);
            this.classList.toggle('filled', this.value !== '');
            if (this.value && i < digits.length - 1) digits[i + 1].focus();
            collectPin(selector, hiddenInputId);
            if (onComplete) onComplete();
        });
        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace' && !this.value && i > 0) {
                digits[i - 1].focus();
                digits[i - 1].value = '';
                digits[i - 1].classList.remove('filled');
                collectPin(selector, hiddenInputId);
                if (onComplete) onComplete();
            }
        });
        inp.addEventListener('paste', function (e) {
            e.preventDefault();
            const paste = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
            [...paste].slice(0, 6).forEach((ch, j) => {
                if (digits[j]) {
                    digits[j].value = ch;
                    digits[j].classList.add('filled');
                }
            });
            collectPin(selector, hiddenInputId);
            if (onComplete) onComplete();
        });
    });
}

function isWeakPin(val) {
    if (val.length !== 6) return true;

    // All the same digit (000000, 111111, ...)
    if (/^(\d)\1{5}$/.test(val)) return true;

    // Strictly ascending or descending run (012345, 987654, ...)
    let ascending = true, descending = true;
    for (let i = 1; i < val.length; i++) {
        const diff = val.charCodeAt(i) - val.charCodeAt(i - 1);
        if (diff !== 1) ascending = false;
        if (diff !== -1) descending = false;
    }
    if (ascending || descending) return true;

    // Repeating block: "ab" x3 (123123) or "abc" x2 (123123 already covered, also 121212)
    if (val.slice(0, 2).repeat(3) === val) return true;
    if (val.slice(0, 3).repeat(2) === val) return true;

    // Fewer than 3 distinct digits used across the whole PIN (e.g. 121212, 112233 is fine with 3 though)
    if (new Set(val).size < 3) return true;

    // Palindrome (123321, 456654, ...)
    if (val === val.split('').reverse().join('')) return true;

    return false;
}

function collectPin(selector, hiddenId) {
    const digits = document.querySelectorAll(selector);
    const val = [...digits].map(d => d.value).join('');
    if (hiddenId) document.getElementById(hiddenId).value = val;

    if (hiddenId === 'addPinHidden') {
        const bar = document.getElementById('pinStrengthBar');
        const pct = (val.length / 6) * 100;
        bar.style.width = pct + '%';
        bar.style.background = val.length < 6 ? '#f87171' : isWeakPin(val) ? '#fb923c' : '#22c55e';
    }
    return val;
}

function checkPinMatch() {
    const np = document.getElementById('newPinHidden').value;
    const cp = document.getElementById('confirmPinHidden').value;
    const msg = document.getElementById('pinMatchMsg');
    const btn = document.getElementById('pinSaveBtn');
    if (!np || !cp) { msg.textContent = ''; btn.disabled = true; return; }
    if (np.length < 6 || cp.length < 6) { msg.textContent = ''; btn.disabled = true; return; }
    if (np === cp) {
        msg.textContent = '✅ PIN sepadan'; msg.style.color = '#4ade80';
        btn.disabled = false;
    } else {
        msg.textContent = '❌ PIN tidak sepadan'; msg.style.color = '#f87171';
        btn.disabled = true;
    }
}

setupPinRow('.add-pin-digit', 'addPinHidden');
setupPinRow('.new-pin-digit', 'newPinHidden', checkPinMatch);
setupPinRow('.confirm-pin-digit', 'confirmPinHidden', checkPinMatch);

document.querySelector('.add-toolbar')?.addEventListener('submit', function (e) {
    const pin = document.getElementById('addPinHidden').value;
    if (pin.length !== 6) { e.preventDefault(); alert('Sila masukkan PIN 6 digit yang lengkap.'); }
});

// ── MODALS ─────────────────────────────────────────────────────────────────
function openModal(id) {
    const m = document.getElementById(id);
    m.style.display = 'flex';
    setTimeout(() => m.classList.add('show'), 10);
}
function closeModal(id) {
    const m = document.getElementById(id);
    m.classList.remove('show');
    setTimeout(() => m.style.display = 'none', 300);
}

function openEditModal(id, name, code, email) {
    document.getElementById('editJudgeId').value   = id;
    document.getElementById('editName').value      = name;
    document.getElementById('editJudgeCode').value = code;
    document.getElementById('editEmail').value     = email || '';
    openModal('modal-edit');
}

function openPinModal(id, name) {
    document.getElementById('pinJudgeId').value = id;
    document.getElementById('pinModalSubtitle').textContent = 'Set PIN baharu untuk: ' + name;
    document.querySelectorAll('.new-pin-digit, .confirm-pin-digit').forEach(d => {
        d.value = ''; d.classList.remove('filled');
    });
    document.getElementById('newPinHidden').value = '';
    document.getElementById('confirmPinHidden').value = '';
    document.getElementById('pinMatchMsg').textContent = '';
    document.getElementById('pinSaveBtn').disabled = true;
    openModal('modal-pin');
    setTimeout(() => document.querySelector('.new-pin-digit').focus(), 300);
}

function confirmDelete(id, name) {
    if (confirm('⚠️ Padam juri "' + name + '"?\n\nTindakan ini tidak boleh dibuat alik. Rekod markah yang dikaitkan dengan juri ini akan dikekalkan.')) {
        document.getElementById('deleteJudgeId').value = id;
        document.getElementById('deleteForm').submit();
    }
}

document.querySelectorAll('.pm-modal-overlay').forEach(m => {
    m.addEventListener('click', function(e) {
        if (e.target === this) { this.classList.remove('show'); setTimeout(() => this.style.display='none', 300); }
    });
});

// ── SEARCH + PAGINATION ────────────────────────────────────────────────────
let juriCurrentPage = 1;
const juriRowsPerPage = 20;

function juriGetVisibleRows() {
    return Array.from(document.querySelectorAll('#juriTbody .juri-row'))
                .filter(r => r.style.display !== 'none' || !r.dataset.filtered);
}

function juriUpdatePagination() {
    const allRows = Array.from(document.querySelectorAll('#juriTbody .juri-row'));
    const filtered = allRows.filter(r => r.dataset.filtered !== '1');

    const total = filtered.length;
    const totalPages = Math.max(1, Math.ceil(total / juriRowsPerPage));
    if (juriCurrentPage > totalPages) juriCurrentPage = totalPages;
    if (juriCurrentPage < 1) juriCurrentPage = 1;

    const start = (juriCurrentPage - 1) * juriRowsPerPage;
    const end   = start + juriRowsPerPage;

    allRows.forEach(r => r.style.display = 'none');
    filtered.forEach((r, i) => { r.style.display = (i >= start && i < end) ? '' : 'none'; });

    const s = total === 0 ? 0 : start + 1;
    const e = Math.min(end, total);
    document.getElementById('juriPageInfo').innerHTML =
        `Memaparkan <b>${s}–${e}</b> daripada <b>${total}</b> juri`;

    pmRenderPagination(document.getElementById('juriPaginationButtons'), juriCurrentPage, totalPages, juriGoTo);
}

window.juriGoTo = function(page) {
    juriCurrentPage = page;
    juriUpdatePagination();
};

// ── Fit the judges table + pagination into the viewport, no page scroll ──
function fitJuriTableHeight() {
    const scrollEl = document.querySelector('.juri-table-scroll');
    const pagination = document.getElementById('juriPaginationContainer');
    if (!scrollEl || !pagination) return;
    if (window.innerWidth <= 640) {
        scrollEl.style.maxHeight = '';
        return;
    }
    const top = scrollEl.getBoundingClientRect().top;
    const paginationH = pagination.offsetHeight;
    const available = window.innerHeight - top - paginationH - 24; // 24px bottom breathing room
    scrollEl.style.maxHeight = Math.max(150, available) + 'px';
}
window.addEventListener('resize', fitJuriTableHeight);

document.getElementById('judgeSearch')?.addEventListener('input', function() {
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll('#juriTbody .juri-row').forEach(row => {
        row.dataset.filtered = row.dataset.search.includes(q) ? '' : '1';
    });
    juriCurrentPage = 1;
    juriUpdatePagination();
});

// Init pagination on load
juriUpdatePagination();
fitJuriTableHeight();

// ── AUTO-DISMISS ALERTS ────────────────────────────────────────────────────
setTimeout(() => {
    document.querySelectorAll('.pm-alert').forEach(alert => {
        alert.style.transition = 'opacity 0.5s ease';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 500); // Remove from DOM after fade
    });
}, 4000); // 4 seconds delay
</script>
</main>
</body>
</html>