<?php
ini_set('display_errors', 0); // errors are logged + Telegram-alerted, never shown
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

$pm_page = 'siri';
$success = '';
$error   = '';

// ── AUTO-MIGRATE: ensure siri tables exist ────────────────────────────────
// Once these succeed, the tables/column exist for good — re-running these
// DDL/metadata checks on every single page view (as this did before) adds
// avoidable overhead to every request. Gate behind a session flag so each
// session only pays this cost once (still self-healing on a fresh deploy).
if (empty($_SESSION['pm_siri_schema_checked'])) {
    $conn->query("CREATE TABLE IF NOT EXISTS `siri` (
        `siri_id`    INT          NOT NULL AUTO_INCREMENT,
        `siri_name`  VARCHAR(100) NOT NULL,
        `siri_year`  YEAR         NOT NULL DEFAULT (YEAR(CURDATE())),
        `notes`      TEXT         NULL,
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`siri_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS `siri_schools` (
        `id`        INT NOT NULL AUTO_INCREMENT,
        `siri_id`   INT NOT NULL,
        `school_id` INT NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_siri_school` (`siri_id`, `school_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Add siri_id column to sessions if not exists
    $colCheck = $conn->query("SHOW COLUMNS FROM `sessions` LIKE 'siri_id'");
    if ($colCheck && $colCheck->num_rows == 0) {
        $conn->query("ALTER TABLE `sessions` ADD COLUMN `siri_id` INT NULL DEFAULT NULL AFTER `session_name`");
    }

    $_SESSION['pm_siri_schema_checked'] = true;
}

// ── CSRF ──────────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── HANDLE POST ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Token keselamatan tidak sah.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
        if ($action === 'add_siri') {
            $name  = trim($_POST['siri_name'] ?? '');
            $year  = (int)($_POST['siri_year'] ?? date('Y'));
            $notes = trim($_POST['notes'] ?? '');
            if (!$name) {
                $error = 'Nama siri wajib diisi.';
            } else {
                $stmt = $conn->prepare("INSERT INTO siri (siri_name, siri_year, notes) VALUES (?,?,?)");
                $stmt->bind_param("sis", $name, $year, $notes);
                if ($stmt->execute()) {
                    $success = "Siri <strong>" . htmlspecialchars($name) . "</strong> berjaya ditambah.";
                } else {
                    $error = 'Gagal menyimpan siri.';
                }
                $stmt->close();
            }
        }

        if ($action === 'edit_siri') {
            $siri_id = (int)($_POST['siri_id'] ?? 0);
            $name    = trim($_POST['siri_name'] ?? '');
            $year    = (int)($_POST['siri_year'] ?? date('Y'));
            $notes   = trim($_POST['notes'] ?? '');
            if ($siri_id && $name) {
                $stmt = $conn->prepare("UPDATE siri SET siri_name=?, siri_year=?, notes=? WHERE siri_id=?");
                $stmt->bind_param("sisi", $name, $year, $notes, $siri_id);
                if ($stmt->execute()) $success = 'Siri berjaya dikemaskini.';
                else $error = 'Gagal mengemaskini siri.';
                $stmt->close();
            }
        }

        if ($action === 'delete_siri') {
            $siri_id = (int)($_POST['siri_id'] ?? 0);
            if ($siri_id) {
                // Unlink sessions first
                $stmt = $conn->prepare("UPDATE sessions SET siri_id = NULL WHERE siri_id = ?");
                $stmt->bind_param("i", $siri_id);
                $stmt->execute();
                $stmt->close();
                $stmt = $conn->prepare("DELETE FROM siri WHERE siri_id = ?");
                $stmt->bind_param("i", $siri_id);
                if ($stmt->execute()) $success = 'Siri berjaya dipadam.';
                else $error = 'Gagal memadam siri.';
                $stmt->close();
            }
        }

        if ($action === 'assign_session') {
            $siri_id    = (int)($_POST['siri_id'] ?? 0);
            $session_id = (int)($_POST['session_id'] ?? 0);
            if ($siri_id && $session_id) {
                $stmt = $conn->prepare("UPDATE sessions SET siri_id = ? WHERE session_id = ?");
                $stmt->bind_param("ii", $siri_id, $session_id);
                if ($stmt->execute()) $success = 'Sidang berjaya dikaitkan dengan siri.';
                else $error = 'Gagal mengaitkan sidang.';
                $stmt->close();
            }
        }

        if ($action === 'unassign_session') {
            $session_id = (int)($_POST['session_id'] ?? 0);
            if ($session_id) {
                $stmt = $conn->prepare("UPDATE sessions SET siri_id = NULL WHERE session_id = ?");
                $stmt->bind_param("i", $session_id);
                if ($stmt->execute()) {
                    $success = 'Sidang berjaya dilepaskan dari siri.';
                } else {
                    $error = 'Gagal melepaskan sidang: ' . $stmt->error;
                }
                $stmt->close();
            }
        }

        if ($action === 'toggle_siri_school') {
            $siri_id   = (int)($_POST['siri_id'] ?? 0);
            $school_id = (int)($_POST['school_id'] ?? 0);
            $mode      = $_POST['mode'] ?? 'add';
            if ($siri_id && $school_id) {
                if ($mode === 'add') {
                    $stmt = $conn->prepare("INSERT IGNORE INTO siri_schools (siri_id, school_id) VALUES (?,?)");
                    $stmt->bind_param("ii", $siri_id, $school_id);
                    if ($stmt->execute()) {
                        $stmt->close();

                        // Cascade: enroll this school into every Sidang already
                        // linked to this Siri, so it immediately shows up for
                        // attendance/session management — not just tagged for
                        // report/leaderboard scoping.
                        $ok_cascade = true;
                        $stmt2 = $conn->prepare("SELECT session_id FROM sessions WHERE siri_id = ?");
                        $stmt2->bind_param("i", $siri_id);
                        $stmt2->execute();
                        $res_sessions = $stmt2->get_result();
                        $session_ids = [];
                        while ($row = $res_sessions->fetch_assoc()) $session_ids[] = (int)$row['session_id'];
                        $stmt2->close();

                        if ($session_ids) {
                            $stmt3 = $conn->prepare("INSERT IGNORE INTO session_schools (session_id, school_id) VALUES (?, ?)");
                            foreach ($session_ids as $sess_id) {
                                $stmt3->bind_param("ii", $sess_id, $school_id);
                                if (!$stmt3->execute()) $ok_cascade = false;
                            }
                            $stmt3->close();
                        }

                        $success = $ok_cascade
                            ? 'Cawangan ditambah ke siri dan semua sidang di dalamnya.'
                            : 'Cawangan ditambah ke siri, tetapi gagal dikaitkan ke sebahagian sidang.';
                    } else {
                        $error = 'Gagal menambah cawangan ke siri: ' . $stmt->error;
                        $stmt->close();
                    }
                } else {
                    $stmt = $conn->prepare("DELETE FROM siri_schools WHERE siri_id=? AND school_id=?");
                    $stmt->bind_param("ii", $siri_id, $school_id);
                    if ($stmt->execute()) {
                        $stmt->close();

                        // Mirror the cascade on removal — unenroll the school
                        // from every Sidang in this Siri too, so the toggle
                        // stays symmetric in both directions.
                        $ok_cascade = true;
                        $stmt2 = $conn->prepare("SELECT session_id FROM sessions WHERE siri_id = ?");
                        $stmt2->bind_param("i", $siri_id);
                        $stmt2->execute();
                        $res_sessions = $stmt2->get_result();
                        $session_ids = [];
                        while ($row = $res_sessions->fetch_assoc()) $session_ids[] = (int)$row['session_id'];
                        $stmt2->close();

                        if ($session_ids) {
                            $placeholders = implode(',', array_fill(0, count($session_ids), '?'));
                            $types = str_repeat('i', count($session_ids)) . 'i';
                            $stmt3 = $conn->prepare("DELETE FROM session_schools WHERE session_id IN ($placeholders) AND school_id = ?");
                            $bindArgs = $session_ids;
                            $bindArgs[] = $school_id;
                            $stmt3->bind_param($types, ...$bindArgs);
                            if (!$stmt3->execute()) $ok_cascade = false;
                            $stmt3->close();
                        }

                        $success = $ok_cascade
                            ? 'Cawangan dilepaskan dari siri dan semua sidang di dalamnya.'
                            : 'Cawangan dilepaskan dari siri, tetapi gagal dilepaskan dari sebahagian sidang.';
                    } else {
                        $error = 'Gagal melepaskan cawangan dari siri: ' . $stmt->error;
                        $stmt->close();
                    }
                }
            } else {
                $error = 'Siri atau cawangan tidak sah.';
            }
        }
        } catch (Throwable $e) {
            promarkah_report('Caught', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
            $error = 'Ralat pangkalan data. Sila cuba lagi.';
        }
    }
}

// ── FETCH DATA ────────────────────────────────────────────────────────────
$all_siri = [];
$res = $conn->query("SELECT s.*, COUNT(DISTINCT ss.session_id) AS total_sessions,
                     COUNT(DISTINCT sch.school_id) AS total_schools
                     FROM siri s
                     LEFT JOIN sessions ss ON ss.siri_id = s.siri_id
                     LEFT JOIN siri_schools sch ON sch.siri_id = s.siri_id
                     GROUP BY s.siri_id ORDER BY s.siri_year DESC, s.siri_id ASC");
if ($res) while ($r = $res->fetch_assoc()) $all_siri[] = $r;

// All sessions with their siri assignment
$all_sessions = [];
$res2 = $conn->query("SELECT s.session_id, s.session_name, s.siri_id, sr.siri_name
                      FROM sessions s
                      LEFT JOIN siri sr ON sr.siri_id = s.siri_id
                      ORDER BY s.session_name ASC");
if ($res2) while ($r = $res2->fetch_assoc()) $all_sessions[] = $r;

// All schools
$all_schools = [];
$res3 = $conn->query("SELECT school_id, school_name FROM schools ORDER BY school_name ASC");
if ($res3) while ($r = $res3->fetch_assoc()) $all_schools[] = $r;

// Active siri shown on THIS page's tabs/detail panel. Deliberately kept in
// its own session key AND its own query param (view_siri, not siri_id) —
// layout.php globally intercepts any ?siri_id= on ANY page to drive the
// sidebar's "Siri Aktif" dropdown, so reusing that name here would silently
// reassign the siri every other page (Students, Attendance, etc.) filters
// by. The sidebar's current value is only read once as a sensible initial
// default; it's never written back to.
$active_siri_id = (int)($_GET['view_siri'] ?? $_SESSION['pic_siri_local_id'] ?? $_SESSION['active_siri_id'] ?? 0);
if (isset($_GET['view_siri'])) $_SESSION['pic_siri_local_id'] = $active_siri_id;
if ($active_siri_id === 0 && !empty($all_siri)) {
    $active_siri_id = (int)$all_siri[0]['siri_id'];
}

// Siri-specific session & school logic
$siri_sessions = [];
$schools_via_session = []; 
$schools_direct = [];      

if ($active_siri_id) {
    // 1. Get sessions in this siri
    $st4 = $conn->prepare("SELECT session_id, session_name FROM sessions WHERE siri_id = ? ORDER BY session_name ASC");
    $st4->bind_param("i", $active_siri_id);
    $st4->execute();
    $res4 = $st4->get_result();
    while ($r = $res4->fetch_assoc()) $siri_sessions[] = $r;
    $st4->close();

    // 2. Get schools attached to those specific sessions (Auto-locked)
    $st_ss = $conn->prepare("SELECT DISTINCT school_id FROM session_schools ss JOIN sessions s ON ss.session_id = s.session_id WHERE s.siri_id = ?");
    $st_ss->bind_param("i", $active_siri_id);
    $st_ss->execute();
    $res_ss = $st_ss->get_result();
    while ($r = $res_ss->fetch_assoc()) $schools_via_session[] = $r['school_id'];
    $st_ss->close();

    // 3. Get schools manually assigned strictly to the Siri
    $st5 = $conn->prepare("SELECT school_id FROM siri_schools WHERE siri_id = ?");
    $st5->bind_param("i", $active_siri_id);
    $st5->execute();
    $res5 = $st5->get_result();
    while ($r = $res5->fetch_assoc()) $schools_direct[] = $r['school_id'];
    $st5->close();
}

$current_year = date('Y');

include 'layout.php';
?>

<style>
    .siri-tabs {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 24px;
    }
    .siri-tab {
        padding: 8px 20px;
        border-radius: 20px;
        border: 1px solid var(--c-border-strong);
        background: var(--c-surface-1);
        color: var(--c-text-muted);
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.15s;
    }
    .siri-tab:hover { background: var(--c-surface-2); color: var(--c-white); }
    .siri-tab.active {
        background: var(--c-red);
        border-color: var(--c-red);
        color: #fff;
        box-shadow: 0 4px 12px rgba(204,0,0,0.25);
    }

    .two-col {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    }
    /* Sidebar (~248px) stays visible for any viewport above 768px, so a
       two-column grid right above that point (e.g. 800-1000px) still has
       its content squeezed by the sidebar even though it's "above" the
       collapse breakpoint — stack one step earlier to give it room. */
    @media (max-width: 1000px) { .two-col { grid-template-columns: 1fr; } }

    .section-card {
        background: var(--c-surface-1);
        border: 1px solid var(--c-border-strong);
        border-radius: 12px;
        padding: 20px;
    }
    .section-card h4 {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 1.1rem;
        letter-spacing: 0.05em;
        color: var(--c-white);
        border-bottom: 1px solid var(--c-border);
        padding-bottom: 10px;
        margin-bottom: 14px;
    }

    .school-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: var(--c-surface-2);
        border: 1px solid var(--c-border);
        border-radius: 20px;
        padding: 4px 12px;
        font-size: 0.78rem;
        color: var(--c-text-muted);
        margin: 3px;
        cursor: pointer;
        transition: all 0.15s;
    }
    .school-pill:hover:not(.locked) { border-color: var(--c-red); color: var(--c-white); }
    
    .school-pill.enrolled {
        background: rgba(74,222,128,0.1);
        border-color: rgba(74,222,128,0.4);
        color: #4ade80;
    }
    .school-pill.enrolled:hover:not(.locked) {
        background: rgba(239,68,68,0.1);
        border-color: rgba(239,68,68,0.4);
        color: #f87171;
    }
    
    .school-pill.locked {
        cursor: not-allowed;
        opacity: 0.85;
    }

    .session-pill {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: var(--c-surface-2);
        border: 1px solid var(--c-border);
        border-radius: 8px;
        padding: 8px 12px;
        margin-bottom: 6px;
        font-size: 0.85rem;
    }
    .session-pill.linked {
        border-color: rgba(74,222,128,0.35);
        background: rgba(74,222,128,0.05);
    }

    .pm-form-label { display:block;font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--c-text-muted);margin-bottom:7px; }
    .pm-form-input { width:100%;background:var(--c-surface-2);border:1px solid var(--c-border-strong);border-radius:8px;padding:10px 14px;color:var(--c-white);font-size:0.9rem;outline:none;transition:border-color 0.15s;box-sizing:border-box; }
    .pm-form-input:focus { border-color:var(--c-red);box-shadow:0 0 0 3px var(--c-red-dim); }
    .pm-form-group { margin-bottom:14px; }

    .pm-modal-overlay { position:fixed;inset:0;background:rgba(0,0,0,0.75);backdrop-filter:blur(4px);z-index:9999;display:none;align-items:center;justify-content:center;opacity:0;transition:opacity 0.2s; }
    .pm-modal-overlay.show { opacity:1; }
    .pm-modal-box { max-width:460px; }
    .pm-modal-title { font-family:'Bebas Neue',sans-serif;font-size:1.5rem;letter-spacing:0.05em;color:var(--c-white);border-bottom:2px solid var(--c-red);display:inline-block;padding-bottom:6px;margin-bottom:18px; }
    .pm-modal-actions { display:flex;gap:10px;justify-content:flex-end;margin-top:18px; }

    .siri-stat-row { display:flex;gap:16px;margin-bottom:20px;flex-wrap:wrap; }
    .siri-stat { background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:10px;padding:14px 18px;text-align:center;flex:1;min-width:100px; }
    .siri-stat .v { font-size:1.6rem;font-weight:700;font-family:'Bebas Neue',sans-serif;color:var(--c-white); }
    .siri-stat .l { font-size:0.65rem;color:var(--c-text-faint);text-transform:uppercase;letter-spacing:0.08em;margin-top:2px; }

    html.pm-light .pic-section-header h2 { color: #111; }
    html.pm-light .pic-section-sub { color: #555; }
</style>

<div class="pic-section-header">
    <div>
        <h2>📋 Pengurusan Siri</h2>
        <div class="pic-section-sub">Urus semua siri pertandingan yang berdaftar</div>
    </div>
</div>

<?php if ($success): ?>
<div class="pm-alert pm-alert-success" style="margin-bottom:20px;">✅ <?= $success ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="pm-alert pm-alert-danger" style="margin-bottom:20px;">❌ <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!--<div style="background:var(--c-surface-2);border:1px solid var(--c-border-strong);border-left:4px solid var(--c-red);border-radius:10px;padding:14px 18px;margin-bottom:24px;font-size:0.88rem;color:var(--c-text);">-->
<!--    <strong style="color:var(--c-red);">Apa itu Siri?</strong> &nbsp;Siri adalah satu kitaran pertandingan tahunan. Setiap siri boleh mempunyai beberapa <em style="color:var(--c-text-muted);">sidang</em>. Cawangan (sekolah) boleh didaftarkan ke dalam siri secara langsung — ataupun secara automatik jika mereka diletakkan dalam sidang.-->
<!--</div>-->

<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
    <span style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--c-text-faint);font-weight:700;">Siri:</span>
    <div class="siri-tabs" style="margin-bottom:0;">
        <?php foreach ($all_siri as $s): ?>
        <a href="?view_siri=<?= $s['siri_id'] ?>" class="siri-tab <?= $s['siri_id'] == $active_siri_id ? 'active' : '' ?>">
            <?= htmlspecialchars($s['siri_name']) ?>
            <span style="opacity:0.7;font-size:0.7rem;">(<?= $s['siri_year'] ?>)</span>
        </a>
        <?php endforeach; ?>
        <button class="siri-tab" onclick="openModal('modal-add-siri')" style="border-style:dashed;">
            + Siri Baharu
        </button>
    </div>
</div>

<?php if (!empty($all_siri) && $active_siri_id):
    $active_siri = array_values(array_filter($all_siri, fn($s) => $s['siri_id'] == $active_siri_id))[0] ?? null;
    if ($active_siri):
?>

<div style="background:var(--c-surface-1);border:1px solid var(--c-border-strong);border-radius:14px;padding:24px;margin-bottom:24px;">

    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
        <div>
            <h3 style="font-family:'Bebas Neue',sans-serif;font-size:1.8rem;color:var(--c-white);letter-spacing:0.05em;margin-bottom:2px;">
                <?= htmlspecialchars($active_siri['siri_name']) ?>
                <span style="font-size:1rem;color:var(--c-red);margin-left:8px;"><?= $active_siri['siri_year'] ?></span>
            </h3>
            <?php if ($active_siri['notes']): ?>
            <p style="color:var(--c-text-muted);font-size:0.85rem;"><?= htmlspecialchars($active_siri['notes']) ?></p>
            <?php endif; ?>
        </div>
        <div style="display:flex;gap:8px;">
            <button class="pm-btn pm-btn-ghost" style="font-size:0.8rem;padding:6px 14px;"
                onclick="openEditSiri(<?= (int)$active_siri['siri_id'] ?>, <?= htmlspecialchars(json_encode($active_siri['siri_name']), ENT_QUOTES) ?>, <?= (int)$active_siri['siri_year'] ?>, <?= htmlspecialchars(json_encode($active_siri['notes'] ?? ''), ENT_QUOTES) ?>)">
                ✏️ Edit Siri
            </button>
            <button class="pm-btn" style="font-size:0.8rem;padding:6px 12px;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);color:#f87171;"
                onclick="deleteSiri(<?= (int)$active_siri['siri_id'] ?>, <?= htmlspecialchars(json_encode($active_siri['siri_name']), ENT_QUOTES) ?>)">
                🗑 Padam
            </button>
        </div>
    </div>

    <div class="siri-stat-row">
        <div class="siri-stat">
            <div class="v"><?= $active_siri['total_sessions'] ?></div>
            <div class="l">Sidang</div>
        </div>
        <div class="siri-stat">
            <div class="v"><?= count(array_unique(array_merge($schools_via_session, $schools_direct))) ?></div>
            <div class="l">Jumlah Cawangan (Siri)</div>
        </div>
    </div>

    <div class="two-col">

        <div class="section-card">
            <h4>📅 Sidang dalam Siri ini</h4>

            <?php if (empty($siri_sessions)): ?>
            <p style="color:var(--c-text-faint);font-size:0.85rem;">Tiada sidang dikaitkan lagi.</p>
            <?php else: ?>
            <?php foreach ($siri_sessions as $sess): ?>
            <div class="session-pill linked">
                <span>📌 <?= htmlspecialchars($sess['session_name']) ?></span>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="action" value="unassign_session">
                    <input type="hidden" name="session_id" value="<?= $sess['session_id'] ?>">
                    <button type="submit" style="background:none;border:none;color:#f87171;cursor:pointer;font-size:0.75rem;padding:2px 6px;" title="Lepaskan dari siri">✕</button>
                </form>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php
            $unassigned = array_filter($all_sessions, fn($s) => !$s['siri_id'] || $s['siri_id'] != $active_siri_id);
            ?>
            <?php if (!empty($unassigned)): ?>
            <form method="POST" style="margin-top:14px;display:flex;gap:8px;align-items:flex-end;">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="assign_session">
                <input type="hidden" name="siri_id" value="<?= $active_siri_id ?>">
                <div style="flex:1;">
                    <label class="pm-form-label">Tambah Sidang</label>
                    <select name="session_id" class="pm-form-input" required>
                        <option value="">-- Pilih Sidang --</option>
                        <?php foreach ($unassigned as $us): ?>
                        <option value="<?= $us['session_id'] ?>">
                            <?= htmlspecialchars($us['session_name']) ?>
                            <?= $us['siri_id'] ? '(dalam ' . htmlspecialchars($us['siri_name']) . ')' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="pm-btn pm-btn-primary" style="padding:10px 14px;font-size:0.82rem;">+ Tambah</button>
            </form>
            <?php endif; ?>
        </div>

        <div class="section-card">
            <h4>🏫 Cawangan dalam Siri ini</h4>
            <p style="font-size:0.78rem;color:var(--c-text-faint);margin-bottom:12px;">
                🔒 Cawangan dari Sidang (Auto)<br>
                ✅/➕ Cawangan Siri Sahaja (Manual klik)
            </p>
            <div>
                <?php foreach ($all_schools as $sch):
                    $in_siri = in_array($sch['school_id'], $schools_direct);
                    // Ticking this pill now also cascades the school into every
                    // Sidang's session_schools (so it shows up for attendance
                    // immediately) — that means "in_session" alone is no longer
                    // proof it was added independently via pic_sessions.php, so
                    // only treat it as the locked/auto case when it's in a
                    // session WITHOUT also being a manual siri_schools entry.
                    $in_session = !$in_siri && in_array($sch['school_id'], $schools_via_session);
                ?>

                <?php if ($in_session): ?>
                    <div class="school-pill enrolled locked" title="Telah dimasukkan automatik kerana wujud di dalam sidang">
                        🔒 <?= htmlspecialchars($sch['school_name']) ?>
                    </div>
                <?php else: ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="action" value="toggle_siri_school">
                        <input type="hidden" name="siri_id" value="<?= $active_siri_id ?>">
                        <input type="hidden" name="school_id" value="<?= $sch['school_id'] ?>">
                        <input type="hidden" name="mode" value="<?= $in_siri ? 'remove' : 'add' ?>">
                        <button type="submit" class="school-pill <?= $in_siri ? 'enrolled' : '' ?>" title="Klik untuk tambah/buang dari Siri ini sahaja">
                            <?= $in_siri ? '✅' : '➕' ?>
                            <?= htmlspecialchars($sch['school_name']) ?>
                        </button>
                    </form>
                <?php endif; ?>
                
                <?php endforeach; ?>
                
                <?php if (empty($all_schools)): ?>
                <p style="color:var(--c-text-faint);font-size:0.82rem;">Tiada cawangan didaftarkan dalam sistem.</p>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<?php endif; ?>
<?php else: ?>
<div style="text-align:center;padding:60px;color:var(--c-text-faint);">
    <div style="font-size:3rem;margin-bottom:16px;">📋</div>
    <h3 style="color:var(--c-text-muted);margin-bottom:8px;">Belum ada siri</h3>
    <p style="font-size:0.9rem;margin-bottom:20px;">Klik butang <strong>"+ Siri Baharu"</strong> di atas untuk mencipta siri pertama.</p>
</div>
<?php endif; ?>

<?php $orphan = array_filter($all_sessions, fn($s) => !$s['siri_id']); ?>
<?php if (!empty($orphan)): ?>
<div style="background:var(--c-red-dim);border:1px solid var(--c-red-border);border-radius:12px;padding:16px 20px;margin-top:8px;">
    <h4 style="color:var(--c-red);font-size:0.88rem;font-weight:700;margin-bottom:10px;">⚠️ Sidang Tanpa Siri (<?= count($orphan) ?>)</h4>
    <div style="display:flex;flex-wrap:wrap;gap:6px;">
        <?php foreach ($orphan as $os): ?>
        <span style="background:var(--c-surface-1);border:1px solid var(--c-red-border);border-radius:6px;padding:4px 12px;font-size:0.8rem;color:var(--c-text);">
            <?= htmlspecialchars($os['session_name']) ?>
        </span>
        <?php endforeach; ?>
    </div>
    <p style="font-size:0.75rem;color:var(--c-text-muted);margin-top:8px;">Sidang-sidang ini belum dikaitkan dengan mana-mana siri. Pergi ke panel siri di atas untuk menghubungkannya.</p>
</div>
<?php endif; ?>

<div class="pm-modal-overlay" id="modal-add-siri">
    <div class="pm-modal-box">
        <div class="pm-modal-title">Tambah Siri Baharu</div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="action" value="add_siri">
            
            <div class="pm-form-group">
                <label class="pm-form-label">Nama Siri *</label>
                <input type="text" name="siri_name" class="pm-form-input" placeholder="cth. Siri 1, Siri Perdana, dll." required>
            </div>
            
            <div class="pm-form-group">
                <label class="pm-form-label">Tahun</label>
                <input type="number" name="siri_year" class="pm-form-input" value="<?= $current_year ?>" min="2020" max="2099">
            </div>
            
            <div class="pm-form-group">
                <label class="pm-form-label">Nota / Keterangan (Pilihan)</label>
                <textarea name="notes" class="pm-form-input" rows="2" placeholder="Maklumat tambahan..."></textarea>
            </div>
            <div class="pm-modal-actions">
                <button type="button" class="pm-btn pm-btn-ghost" onclick="closeModal('modal-add-siri')">Batal</button>
                <button type="submit" class="pm-btn pm-btn-primary">Simpan Siri</button>
            </div>
        </form>
    </div>
</div>

<div class="pm-modal-overlay" id="modal-edit-siri">
    <div class="pm-modal-box">
        <div class="pm-modal-title">Edit Siri</div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="action" value="edit_siri">
            <input type="hidden" name="siri_id" id="editSiriId">
            
            <div class="pm-form-group">
                <label class="pm-form-label">Nama Siri</label>
                <input type="text" name="siri_name" id="editSiriName" class="pm-form-input" required>
            </div>
            
            <div class="pm-form-group">
                <label class="pm-form-label">Tahun</label>
                <input type="number" name="siri_year" id="editSiriYear" class="pm-form-input" min="2020" max="2099">
            </div>
            
            <div class="pm-form-group">
                <label class="pm-form-label">Nota</label>
                <textarea name="notes" id="editSiriNotes" class="pm-form-input" rows="2"></textarea>
            </div>
            
            <div class="pm-modal-actions">
                <button type="button" class="pm-btn pm-btn-ghost" onclick="closeModal('modal-edit-siri')">Batal</button>
                <button type="submit" class="pm-btn pm-btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<form method="POST" id="deleteSiriForm" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="action" value="delete_siri">
    <input type="hidden" name="siri_id" id="deleteSiriId">
</form>

<script>
function openModal(id) {
    const m = document.getElementById(id);
    m.style.display = 'flex';
    setTimeout(() => m.classList.add('show'), 10);
}
function closeModal(id) {
    const m = document.getElementById(id);
    m.classList.remove('show');
    setTimeout(() => m.style.display = 'none', 200);
}
document.querySelectorAll('.pm-modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); });
});

function openEditSiri(id, name, year, notes) {
    document.getElementById('editSiriId').value    = id;
    document.getElementById('editSiriName').value  = name;
    document.getElementById('editSiriYear').value  = year;
    document.getElementById('editSiriNotes').value = notes;
    openModal('modal-edit-siri');
}

function deleteSiri(id, name) {
    if (confirm('Padam siri "' + name + '"?\n\nSemua sidang dalam siri ini akan dilepaskan (tidak dipadam). Tindakan ini tidak boleh dibuat alik.')) {
        document.getElementById('deleteSiriId').value = id;
        document.getElementById('deleteSiriForm').submit();
    }
}
</script>

</main>
</body>
</html>