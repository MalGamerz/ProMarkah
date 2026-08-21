<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

session_start();
require __DIR__ . '/auth_check.php';
include 'db.php';
$conn = getDB();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'judge') {
    header('Location: login.php');
    exit();
}

$judge_id = (int) $_SESSION['user_id'];
$success = '';
$error   = '';

// ── SELF-HEALING SCHEMA: add judges.photo_path if it doesn't exist yet ──
// Same defensive pattern used in pic_judges.php for judges.email — avoids a
// manual migration step on deploy.
try {
    $col_check = $conn->query("SHOW COLUMNS FROM judges LIKE 'photo_path'");
    if ($col_check && $col_check->num_rows === 0) {
        $conn->query("ALTER TABLE judges ADD COLUMN photo_path VARCHAR(255) NULL AFTER email");
    }
} catch (Throwable $e) {
    promarkah_report('Caught', 'failed to add judges.photo_path column — ' . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$upload_dir_fs  = __DIR__ . '/uploads/judges';
$upload_dir_web = 'uploads/judges';

// ── HANDLE POST ACTIONS ───────────────────────────────────────────────────
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $error = 'Token keselamatan tidak sah. Sila muat semula halaman.';
        } else {
            $action = $_POST['action'] ?? '';

            // ── UPDATE PROFILE (name + photo) ──
            if ($action === 'update_profile') {
                $name = trim($_POST['name'] ?? '');

                if ($name === '') {
                    $error = 'Nama tidak boleh kosong.';
                } else {
                    $new_photo_path = null;

                    // Photo upload is optional — only touched if a file was
                    // actually chosen.
                    if (!empty($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
                        $file = $_FILES['photo'];

                        if ($file['error'] !== UPLOAD_ERR_OK) {
                            $error = 'Gagal memuat naik gambar. Sila cuba lagi.';
                        } elseif ($file['size'] > 2 * 1024 * 1024) {
                            $error = 'Saiz gambar mesti kurang daripada 2MB.';
                        } else {
                            $mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : '';
                            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

                            if (!isset($allowed[$mime])) {
                                $error = 'Format gambar tidak disokong. Gunakan JPG, PNG, atau WEBP.';
                            } else {
                                if (!is_dir($upload_dir_fs)) {
                                    mkdir($upload_dir_fs, 0755, true);
                                }
                                $ext      = $allowed[$mime];
                                $filename = "judge_{$judge_id}_" . time() . ".{$ext}";
                                $destFs   = $upload_dir_fs . '/' . $filename;

                                if (move_uploaded_file($file['tmp_name'], $destFs)) {
                                    $new_photo_path = $upload_dir_web . '/' . $filename;
                                } else {
                                    $error = 'Gagal menyimpan gambar. Sila cuba lagi.';
                                }
                            }
                        }
                    }

                    if ($error === '') {
                        // Fetch the old photo path first (so it can be
                        // deleted after a successful swap, not before —
                        // don't want to lose the old file if the update
                        // itself fails).
                        $old_photo_path = null;
                        if ($new_photo_path !== null) {
                            $oldStmt = $conn->prepare("SELECT photo_path FROM judges WHERE id = ? LIMIT 1");
                            $oldStmt->bind_param("i", $judge_id);
                            $oldStmt->execute();
                            $oldRow = $oldStmt->get_result()->fetch_assoc();
                            $oldStmt->close();
                            $old_photo_path = $oldRow['photo_path'] ?? null;
                        }

                        if ($new_photo_path !== null) {
                            $stmt = $conn->prepare("UPDATE judges SET name = ?, photo_path = ? WHERE id = ?");
                            $stmt->bind_param("ssi", $name, $new_photo_path, $judge_id);
                        } else {
                            $stmt = $conn->prepare("UPDATE judges SET name = ? WHERE id = ?");
                            $stmt->bind_param("si", $name, $judge_id);
                        }

                        if ($stmt->execute()) {
                            $_SESSION['judge_name'] = $name;
                            $success = 'Profil berjaya dikemaskini.';

                            if ($old_photo_path && $old_photo_path !== $new_photo_path) {
                                $oldFs = __DIR__ . '/' . $old_photo_path;
                                if (is_file($oldFs)) {
                                    @unlink($oldFs);
                                }
                            }
                        } else {
                            $error = 'Gagal mengemas kini profil.';
                        }
                        $stmt->close();
                    }
                }
            }

            // ── CHANGE PIN (requires current PIN — this is self-service,
            //    unlike PIC's "Reset PIN" which doesn't need it) ──
            if ($action === 'change_pin') {
                $current_pin = trim($_POST['current_pin'] ?? '');
                $new_pin     = trim($_POST['new_pin'] ?? '');
                $confirm_pin = trim($_POST['confirm_pin'] ?? '');

                if (!$current_pin || !$new_pin || !$confirm_pin) {
                    $error = 'Semua medan PIN wajib diisi.';
                } elseif (!preg_match('/^\d{6}$/', $new_pin)) {
                    $error = 'PIN baharu mesti tepat 6 digit angka.';
                } elseif ($new_pin !== $confirm_pin) {
                    $error = 'PIN baharu dan pengesahan tidak sepadan.';
                } else {
                    $stmt = $conn->prepare("SELECT pin_hash FROM judges WHERE id = ? LIMIT 1");
                    $stmt->bind_param("i", $judge_id);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$row || !password_verify($current_pin, $row['pin_hash'])) {
                        $error = 'PIN semasa tidak tepat.';
                    } else {
                        $new_hash = password_hash($new_pin, PASSWORD_BCRYPT);
                        $upd = $conn->prepare("UPDATE judges SET pin_hash = ? WHERE id = ?");
                        $upd->bind_param("si", $new_hash, $judge_id);
                        if ($upd->execute()) {
                            $success = 'PIN berjaya dikemaskini.';
                        } else {
                            $error = 'Gagal mengemas kini PIN.';
                        }
                        $upd->close();
                    }
                }
            }
        }
    }
} catch (Throwable $e) {
    promarkah_report('Caught', 'POST action failed — ' . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    $error = 'Ralat pangkalan data berlaku semasa menyimpan. Sila cuba lagi.';
}

// ── FETCH CURRENT JUDGE DATA ──────────────────────────────────────────────
$stmt = $conn->prepare("SELECT name, judge_code, email, photo_path FROM judges WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $judge_id);
$stmt->execute();
$judge = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$judge) {
    header('Location: logout.php');
    exit();
}

$pm_page = 'judge_settings';
include 'layout.php';
?>

<?php
$pm_js_css_v = @filemtime(__DIR__ . '/judge_settings.css') ?: time();
?>
<link rel="stylesheet" href="judge_settings.css?v=<?= $pm_js_css_v ?>">

<div class="settings-wrap">
    <h2 class="settings-page-title">⚙️ Tetapan Akaun</h2>

    <?php if ($success): ?>
        <div class="pm-alert pm-alert-success">✅ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="pm-alert pm-alert-danger">❌ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="settings-card">
        <div class="settings-card-title">Profil</div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="update_profile">

            <div class="settings-photo-row">
                <div class="settings-photo-circle">
                    <?php if (!empty($judge['photo_path'])): ?>
                        <img src="<?= htmlspecialchars($judge['photo_path']) ?>" alt="" id="photoPreview">
                    <?php else: ?>
                        <svg id="photoPreviewIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                        </svg>
                        <img src="" alt="" id="photoPreview" style="display:none;">
                    <?php endif; ?>
                </div>
                <div style="flex:1; min-width:0;">
                    <label class="settings-label">Gambar Profil</label>
                    <label for="photoInput" class="settings-file-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="15" height="15"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        <span id="photoFileLabel">Pilih Gambar</span>
                    </label>
                    <input type="file" name="photo" id="photoInput" accept="image/jpeg,image/png,image/webp" class="settings-file-input">
                    <div class="settings-hint">JPG, PNG, atau WEBP. Maksimum 2MB.</div>
                </div>
            </div>

            <div class="settings-form-group">
                <label class="settings-label">Nama Penuh</label>
                <input type="text" name="name" class="settings-input" value="<?= htmlspecialchars($judge['name']) ?>" required>
            </div>

            <div class="settings-form-group">
                <label class="settings-label">Kod Juri</label>
                <div class="settings-readonly">
                    <span><?= htmlspecialchars($judge['judge_code']) ?></span>
                    <span class="settings-readonly-hint">Hubungi PIC untuk ubah</span>
                </div>
            </div>

            <?php if (!empty($judge['email'])): ?>
            <div class="settings-form-group">
                <label class="settings-label">E-mel Log Masuk Google</label>
                <div class="settings-readonly">
                    <span><?= htmlspecialchars($judge['email']) ?></span>
                    <span class="settings-readonly-hint">Hubungi PIC untuk ubah</span>
                </div>
            </div>
            <?php endif; ?>

            <button type="submit" class="pm-btn pm-btn-primary" style="margin-top: 8px;">💾 Simpan Profil</button>
        </form>
    </div>

    <div class="settings-card">
        <div class="settings-card-title">Tukar PIN</div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="change_pin">

            <div class="settings-form-group">
                <label class="settings-label">PIN Semasa</label>
                <input type="password" name="current_pin" inputmode="numeric" maxlength="6" pattern="\d{6}" class="settings-input" required autocomplete="off">
            </div>

            <div class="settings-pin-row">
                <div class="settings-form-group">
                    <label class="settings-label">PIN Baharu (6 Digit)</label>
                    <input type="password" name="new_pin" inputmode="numeric" maxlength="6" pattern="\d{6}" class="settings-input" required autocomplete="off">
                </div>
                <div class="settings-form-group">
                    <label class="settings-label">Sahkan PIN Baharu</label>
                    <input type="password" name="confirm_pin" inputmode="numeric" maxlength="6" pattern="\d{6}" class="settings-input" required autocomplete="off">
                </div>
            </div>

            <button type="submit" class="pm-btn pm-btn-primary" style="margin-top: 16px;">🔒 Kemaskini PIN</button>
        </form>
    </div>
</div>

<?php
$pm_js_js_v = @filemtime(__DIR__ . '/judge_settings.js') ?: time();
?>
<script src="judge_settings.js?v=<?= $pm_js_js_v ?>"></script>

</main>
</body>
</html>
