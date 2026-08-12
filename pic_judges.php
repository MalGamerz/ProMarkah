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
$pm_pj_css_v = @filemtime(__DIR__ . '/pic_judges.css') ?: time();
?>
<link rel="stylesheet" href="pic_judges.css?v=<?= $pm_pj_css_v ?>">

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
    <div class="pm-modal-box pm-modal-box--sm">
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
    <div class="pm-modal-box pm-modal-box--sm">
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

<?php
$pm_pj_js_v = @filemtime(__DIR__ . '/pic_judges.js') ?: time();
?>
<script src="pic_judges.js?v=<?= $pm_pj_js_v ?>"></script>
</main>
</body>
</html>