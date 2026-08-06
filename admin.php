<?php
// ── admin.php — ProMarkah Admin Panel — User Management
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Strict');
session_start();
require __DIR__ . '/auth_check.php';

header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Cache-Control: no-store");

include 'db.php';
$conn = getDB();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$flash = '';
$flash_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token'])) {
        $flash = 'Security token mismatch. Refresh and try again.';
        $flash_type = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        // ── Create user
        if ($action === 'create_user') {
            $new_username  = trim($_POST['new_username'] ?? '');
            $new_password  = trim($_POST['new_password'] ?? '');
            $new_role      = trim($_POST['new_role'] ?? '');
            $allowed_roles = ['pic', 'recorder', 'judge'];

            if ($new_username === '' || $new_password === '' || $new_role === '') {
                $flash = 'All fields are required.'; $flash_type = 'error';
            } elseif (!in_array($new_role, $allowed_roles, true)) {
                $flash = 'Invalid role.'; $flash_type = 'error';
            } elseif (strlen($new_password) < 8) {
                $flash = 'Password must be at least 8 characters.'; $flash_type = 'error';
            } else {
                $check = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                $check->bind_param("s", $new_username); $check->execute(); $check->store_result();
                if ($check->num_rows > 0) {
                    $flash = "Username \"{$new_username}\" is already taken."; $flash_type = 'error';
                } else {
                    $hash = password_hash($new_password, PASSWORD_BCRYPT);
                    $ins  = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
                    $ins->bind_param("sss", $new_username, $hash, $new_role);
                    $ins->execute()
                        ? $flash = "Account \"{$new_username}\" ({$new_role}) created."
                        : ($flash = 'Database error.' and $flash_type = 'error');
                    $ins->close();
                }
                $check->close();
            }
        }

        // ── Edit user
        if ($action === 'edit_user') {
            $target_id    = (int)($_POST['target_id'] ?? 0);
            $new_uname    = trim($_POST['new_username_edit'] ?? '');
            $new_role_edit = trim($_POST['new_role_edit'] ?? '');
            $allowed_roles = ['pic', 'recorder', 'judge'];

            if ($target_id <= 0 || $new_uname === '' || $new_role_edit === '') {
                $flash = 'All fields are required.'; $flash_type = 'error';
            } elseif (!in_array($new_role_edit, $allowed_roles, true)) {
                $flash = 'Invalid role.'; $flash_type = 'error';
            } else {
                $check = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
                $check->bind_param("si", $new_uname, $target_id); $check->execute(); $check->store_result();
                if ($check->num_rows > 0) {
                    $flash = "Username \"{$new_uname}\" is already taken."; $flash_type = 'error';
                } else {
                    $upd = $conn->prepare("UPDATE users SET username = ?, role = ? WHERE id = ? AND role != 'admin'");
                    $upd->bind_param("ssi", $new_uname, $new_role_edit, $target_id);
                    $upd->execute();
                    $upd->affected_rows > 0
                        ? $flash = 'User updated.'
                        : ($flash = 'No changes made or cannot edit admin.' and $flash_type = 'error');
                    $upd->close();
                }
                $check->close();
            }
        }

        // ── Reset password
        if ($action === 'reset_password') {
            $target_id = (int)($_POST['target_id'] ?? 0);
            $new_pw    = trim($_POST['reset_password'] ?? '');

            if ($target_id <= 0 || $new_pw === '') {
                $flash = 'Invalid request.'; $flash_type = 'error';
            } elseif (strlen($new_pw) < 8) {
                $flash = 'Password must be at least 8 characters.'; $flash_type = 'error';
            } else {
                $hash = password_hash($new_pw, PASSWORD_BCRYPT);
                $upd  = $conn->prepare("UPDATE users SET password = ? WHERE id = ? AND role != 'admin'");
                $upd->bind_param("si", $hash, $target_id);
                $upd->execute();
                $upd->affected_rows > 0
                    ? $flash = 'Password reset successfully.'
                    : ($flash = 'Cannot reset admin password via this form.' and $flash_type = 'error');
                $upd->close();
            }
        }

        // ── Change own password
        if ($action === 'change_own_password') {
            $current_pw = trim($_POST['current_password'] ?? '');
            $new_pw1    = trim($_POST['new_pw1'] ?? '');
            $new_pw2    = trim($_POST['new_pw2'] ?? '');

            if ($new_pw1 !== $new_pw2) {
                $flash = 'New passwords do not match.'; $flash_type = 'error';
            } elseif (strlen($new_pw1) < 8) {
                $flash = 'New password must be at least 8 characters.'; $flash_type = 'error';
            } else {
                $self = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
                $self->bind_param("i", $_SESSION['user_id']); $self->execute();
                $row = $self->get_result()->fetch_assoc(); $self->close();
                if (!$row || !password_verify($current_pw, $row['password'])) {
                    $flash = 'Current password is incorrect.'; $flash_type = 'error';
                } else {
                    $hash = password_hash($new_pw1, PASSWORD_BCRYPT);
                    $upd2 = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $upd2->bind_param("si", $hash, $_SESSION['user_id']); $upd2->execute();
                    $flash = 'Your password has been updated.';
                    $upd2->close();
                }
            }
        }
    }
}

// Fetch all non-admin users
$users_result = $conn->query("SELECT id, username, role FROM users WHERE role != 'admin' ORDER BY role, username");
$all_users = [];
while ($u = $users_result->fetch_assoc()) $all_users[] = $u;

// Count by role for stat bar
$role_counts = ['pic' => 0, 'recorder' => 0, 'judge' => 0];
foreach ($all_users as $u) {
    if (isset($role_counts[$u['role']])) $role_counts[$u['role']]++;
}

$pm_page = 'admin';
include 'layout.php';
?>

<style>
    .ap-wrap {
        padding: var(--sp-6);
        max-width: 100%; /* Updated to fill the screen */
        display: flex;
        flex-direction: column;
        gap: var(--sp-6);
    }

    /* ── Admin tab nav ── */
    .ap-tabnav {
        display: flex;
        gap: 4px;
        border-bottom: 1px solid var(--c-border);
        padding-bottom: 0;
        flex-wrap: wrap;
    }
    .ap-tab {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 9px 16px;
        font-size: var(--text-sm);
        font-weight: 600;
        font-family: 'DM Sans', sans-serif;
        color: var(--c-text-faint);
        border: 1px solid transparent;
        border-bottom: none;
        border-radius: var(--radius-sm) var(--radius-sm) 0 0;
        text-decoration: none;
        transition: color var(--fast) var(--ease), background var(--fast) var(--ease);
        position: relative;
        bottom: -1px;
    }
    .ap-tab svg {
        width: 14px; height: 14px;
        stroke: currentColor; fill: none;
        stroke-width: 1.75; stroke-linecap: round; stroke-linejoin: round;
        flex-shrink: 0;
    }
    .ap-tab:hover { color: var(--c-text); }
    .ap-tab.active {
        color: var(--c-text);
        background: var(--c-surface-1);
        border-color: var(--c-border);
        border-bottom-color: var(--c-surface-1);
    }

    .ap-page-title { margin: 0; }
    .ap-page-title h1 {
        font-family: 'Bebas Neue', sans-serif;
        font-size: var(--text-2xl);
        color: var(--c-text);
        letter-spacing: 0.04em;
        margin: 0 0 2px;
    }
    .ap-page-title p { font-size: var(--text-sm); color: var(--c-text-faint); margin: 0; }

    /* ── Stat bar ── */
    .ap-stat-bar {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: var(--sp-5);
    }
    .ap-stat {
        background: var(--c-surface-1);
        border: 1px solid var(--c-border);
        border-radius: var(--radius-lg);
        padding: var(--sp-4) var(--sp-5);
        display: flex;
        flex-direction: column;
        gap: 2px;
    }
    .ap-stat-num {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 2.5rem;
        line-height: 1;
        color: var(--c-text);
        letter-spacing: 0.03em;
    }
    .ap-stat-label { 
        font-size: var(--text-xs); 
        color: var(--c-text-faint); 
        text-transform: uppercase; 
        letter-spacing: 0.08em; 
    }

    /* ── Flash ── */
    .ap-flash {
        border-radius: var(--radius-sm);
        padding: var(--sp-3) var(--sp-4);
        font-size: var(--text-sm);
        font-weight: 500;
    }
    .ap-flash.success { background: rgba(255,255,255,0.06); border: 1px solid var(--c-border); color: var(--c-text-muted); }
    .ap-flash.error   { background: rgba(204,0,0,0.10); border: 1px solid rgba(204,0,0,0.30); color: #ff6b6b; }

    /* ── Card ── */
    .ap-card { background: var(--c-surface-1); border: 1px solid var(--c-border); border-radius: var(--radius-lg); overflow: hidden; }
    .ap-card-head { padding: var(--sp-4) var(--sp-5); border-bottom: 1px solid var(--c-border); display: flex; align-items: center; gap: var(--sp-3); }
    .ap-card-head h2 { font-family: 'Bebas Neue', sans-serif; font-size: var(--text-md); color: var(--c-text); letter-spacing: 0.06em; margin: 0; }
    .ap-card-head svg { width: 16px; height: 16px; color: var(--c-red); stroke: currentColor; fill: none; stroke-width: 1.75; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }
    .ap-card-body { padding: var(--sp-5); }

    .ap-top-row { display: grid; grid-template-columns: 1fr 1fr; gap: var(--sp-5); }
    @media (max-width: 680px) { .ap-top-row { grid-template-columns: 1fr; } }

    .ap-field { display: flex; flex-direction: column; gap: 5px; }
    .ap-field label { font-size: var(--text-xs); color: var(--c-text-faint); text-transform: uppercase; letter-spacing: 0.08em; }
    .ap-field input, .ap-field select {
        background: var(--c-surface-1);
        border: 1px solid var(--c-border);
        border-radius: var(--radius-sm);
        color: var(--c-text);
        padding: 9px var(--sp-3);
        font-size: var(--text-sm);
        font-family: 'DM Sans', sans-serif;
        outline: none;
        transition: border-color var(--fast) var(--ease);
        width: 100%;
        box-sizing: border-box;
        -webkit-appearance: none;
        appearance: none;
        color-scheme: light dark;
    }
    .ap-field select {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23a3a3a3' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 10px center;
        padding-right: 32px;
    }
    .ap-field select option { background: var(--c-surface-1); color: var(--c-text); }
    .ap-field select option:checked { background: var(--c-red); color: #fff; }
    .ap-field input:focus, .ap-field select:focus { border-color: var(--c-red); }

    .btn-red { background: var(--c-red); color: #fff; border: none; border-radius: var(--radius-sm); padding: 9px var(--sp-5); font-size: var(--text-sm); font-family: 'DM Sans', sans-serif; font-weight: 600; cursor: pointer; white-space: nowrap; transition: background var(--fast) var(--ease); width: 100%; }
    .btn-red:hover { background: var(--c-red-dark); }

    .btn-sm-ghost { background: transparent; color: var(--c-text-faint); border: 1px solid var(--c-border); border-radius: var(--radius-sm); padding: 5px 12px; font-size: var(--text-xs); font-family: 'DM Sans', sans-serif; font-weight: 600; cursor: pointer; white-space: nowrap; transition: all var(--fast) var(--ease); line-height: 1.4; }
    .btn-sm-ghost:hover { border-color: var(--c-border-strong); color: var(--c-text); }

    .btn-sm-red { background: var(--c-red); color: #fff; border: none; border-radius: var(--radius-sm); padding: 5px 12px; font-size: var(--text-xs); font-family: 'DM Sans', sans-serif; font-weight: 600; cursor: pointer; white-space: nowrap; transition: background var(--fast) var(--ease); }
    .btn-sm-red:hover { background: var(--c-red-dark); }

    /* ── User table ── */
    .ap-table { width: 100%; border-collapse: collapse; font-size: var(--text-sm); }
    .ap-table th { text-align: left; padding: var(--sp-3) var(--sp-4); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.08em; color: var(--c-text-faint); border-bottom: 1px solid var(--c-border); font-weight: 500; }
    .ap-table td { padding: var(--sp-3) var(--sp-4); color: var(--c-text-muted); border-bottom: 1px solid rgba(255,255,255,0.04); vertical-align: middle; }
    .ap-table tr:last-child td { border-bottom: none; }
    .ap-table tbody tr:hover td { background: rgba(255,255,255,0.015); }

    /* .role-badge and its role variants now live in dashboard.css — shared with admin_logs.php */

    .ap-drawer-tr { display: none; }
    .ap-drawer-tr.open { display: table-row; }
    .drawer-cell { padding: 0 var(--sp-4) var(--sp-4) var(--sp-4) !important; border-bottom: 1px solid var(--c-border) !important; }
    .ap-drawer-inner { background: var(--c-surface-2); border: 1px solid var(--c-border); border-radius: var(--radius-sm); padding: var(--sp-4); }
    .ap-drawer-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: var(--sp-4); align-items: end; margin-bottom: var(--sp-2); }
    @media (max-width: 600px) { .ap-drawer-grid { grid-template-columns: 1fr; } }
    .ap-drawer-actions { display: flex; gap: var(--sp-2); justify-content: flex-end; }
    .ap-drawer input, .ap-drawer select {
        background: var(--c-surface-2);
        border: 1px solid var(--c-border);
        border-radius: var(--radius-sm);
        color: var(--c-text);
        padding: 8px var(--sp-3);
        font-size: var(--text-sm);
        font-family: 'DM Sans', sans-serif;
        outline: none;
        width: 100%;
        box-sizing: border-box;
        transition: border-color var(--fast) var(--ease);
        -webkit-appearance: none;
        appearance: none;
        color-scheme: light dark;
    }
    .ap-drawer select { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23a3a3a3' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 10px center; padding-right: 32px; }
    .ap-drawer select option { background: var(--c-surface-2); color: var(--c-text); }
    .ap-drawer select option:checked { background: var(--c-red); color: #fff; }
    .ap-drawer input:focus, .ap-drawer select:focus { border-color: var(--c-red); }
    .ap-drawer-label { font-size: var(--text-xs); color: var(--c-text-faint); text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 5px; }
    .td-btn-group { display: flex; gap: var(--sp-2); flex-wrap: wrap; }
</style>

<div class="ap-wrap">

    <div class="ap-page-title">
        <h1>Admin Panel</h1>
        <p>System administration for ProMarkah.</p>
    </div>

    <!-- Stat bar -->
    <div class="ap-stat-bar">
        <div class="ap-stat">
            <span class="ap-stat-num"><?= count($all_users) ?></span>
            <span class="ap-stat-label">Total Users</span>
        </div>
        <div class="ap-stat">
            <span class="ap-stat-num"><?= $role_counts['pic'] ?></span>
            <span class="ap-stat-label">PIC Accounts</span>
        </div>
        <div class="ap-stat">
            <span class="ap-stat-num"><?= $role_counts['recorder'] ?></span>
            <span class="ap-stat-label">Recorders</span>
        </div>
        <div class="ap-stat">
            <span class="ap-stat-num"><?= $role_counts['judge'] ?></span>
            <span class="ap-stat-label">Judges</span>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="ap-flash <?= $flash_type ?>"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <!-- Top row: Create + Change own password -->
    <div class="ap-top-row">

        <div class="ap-card">
            <div class="ap-card-head">
                <svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                <h2>New Account</h2>
            </div>
            <div class="ap-card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="create_user">
                    <div style="display:flex; flex-direction:column; gap:var(--sp-3);">
                        <div class="ap-field">
                            <label>Username</label>
                            <input type="text" name="new_username" autocomplete="off" required placeholder="e.g. pic_ali">
                        </div>
                        <div class="ap-field">
                            <label>Role</label>
                            <select name="new_role" required>
                                <option value="" disabled selected>Select role</option>
                                <option value="pic">PIC</option>
                                <option value="recorder">Recorder</option>
                                <option value="judge">Judge</option>
                            </select>
                        </div>
                        <div class="ap-field">
                            <label>Password (min 8 chars)</label>
                            <input type="password" name="new_password" required placeholder="••••••••">
                        </div>
                        <button type="submit" class="btn-red">Create Account</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="ap-card">
            <div class="ap-card-head">
                <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <h2>Your Password</h2>
            </div>
            <div class="ap-card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="change_own_password">
                    <div style="display:flex; flex-direction:column; gap:var(--sp-3);">
                        <div class="ap-field">
                            <label>Current Password</label>
                            <input type="password" name="current_password" required placeholder="••••••••">
                        </div>
                        <div class="ap-field">
                            <label>New Password</label>
                            <input type="password" name="new_pw1" required placeholder="min 8 characters">
                        </div>
                        <div class="ap-field">
                            <label>Confirm New Password</label>
                            <input type="password" name="new_pw2" required placeholder="repeat new password">
                        </div>
                        <button type="submit" class="btn-red">Update Password</button>
                    </div>
                </form>
            </div>
        </div>

    </div>

    <!-- User list -->
    <div class="ap-card">
        <div class="ap-card-head">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <h2>All Users</h2>
        </div>
        <div style="overflow-x:auto;">
            <table class="ap-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_users as $u): $uid = $u['id']; ?>
                        <tr>
                            <td style="color:var(--c-text); font-weight:500; white-space:nowrap;">
                                <?= htmlspecialchars($u['username']) ?>
                            </td>
                            <td>
                                <span class="role-badge <?= $u['role'] ?>">
                                    <?= strtoupper($u['role']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="td-btn-group">
                                    <button type="button" class="btn-sm-ghost"
                                        onclick="toggleDrawer('edit-<?= $uid ?>', 'pw-<?= $uid ?>')">
                                        Edit
                                    </button>
                                    <button type="button" class="btn-sm-ghost"
                                        onclick="toggleDrawer('pw-<?= $uid ?>', 'edit-<?= $uid ?>')">
                                        Reset Password
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <tr class="ap-drawer-tr" id="edit-<?= $uid ?>">
                            <td colspan="3" class="drawer-cell">
                                <div class="ap-drawer-inner ap-drawer">
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="action" value="edit_user">
                                        <input type="hidden" name="target_id" value="<?= $uid ?>">
                                        <div class="ap-drawer-grid">
                                            <div>
                                                <div class="ap-drawer-label">Username</div>
                                                <input type="text" name="new_username_edit"
                                                    value="<?= htmlspecialchars($u['username']) ?>"
                                                    required autocomplete="off">
                                            </div>
                                            <div>
                                                <div class="ap-drawer-label">Role</div>
                                                <select name="new_role_edit" required>
                                                    <option value="pic"      <?= $u['role']==='pic'      ?'selected':'' ?>>PIC</option>
                                                    <option value="recorder" <?= $u['role']==='recorder' ?'selected':'' ?>>Recorder</option>
                                                    <option value="judge"    <?= $u['role']==='judge'    ?'selected':'' ?>>Judge</option>
                                                </select>
                                            </div>
                                            <div style="display:flex; align-items:flex-end;">
                                                <div style="width:100%;">
                                                    <div class="ap-drawer-label">&nbsp;</div>
                                                    <div class="ap-drawer-actions" style="justify-content:flex-start;">
                                                        <button type="submit" class="btn-sm-red">Save</button>
                                                        <button type="button" class="btn-sm-ghost"
                                                            onclick="closeDrawer('edit-<?= $uid ?>')">Cancel</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </td>
                        </tr>

                        <tr class="ap-drawer-tr" id="pw-<?= $uid ?>">
                            <td colspan="3" class="drawer-cell">
                                <div class="ap-drawer-inner ap-drawer">
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="action" value="reset_password">
                                        <input type="hidden" name="target_id" value="<?= $uid ?>">
                                        <div style="display:flex; gap:var(--sp-3); align-items:flex-end; flex-wrap:wrap;">
                                            <div style="flex:1; min-width:180px; max-width:300px;">
                                                <div class="ap-drawer-label">New Password (min 8)</div>
                                                <input type="password" name="reset_password" required minlength="8" placeholder="••••••••">
                                            </div>
                                            <div class="ap-drawer-actions">
                                                <button type="submit" class="btn-sm-red">Save</button>
                                                <button type="button" class="btn-sm-ghost"
                                                    onclick="closeDrawer('pw-<?= $uid ?>')">Cancel</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
    function toggleDrawer(openId, closeId) {
        const toOpen  = document.getElementById(openId);
        const toClose = document.getElementById(closeId);
        if (toClose) toClose.classList.remove('open');
        if (toOpen) {
            const wasOpen = toOpen.classList.contains('open');
            toOpen.classList.toggle('open', !wasOpen);
            if (!wasOpen) {
                const first = toOpen.querySelector('input:not([type="hidden"]), select');
                if (first) first.focus();
            }
        }
    }

    function closeDrawer(id) {
        const el = document.getElementById(id);
        if (el) el.classList.remove('open');
    }
</script>

</main>
</body>
</html>