<?php
// ── admin_data.php — ProMarkah Admin — Data, Export & Reset
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

// ─────────────────────────────────────────────────────────────────────────────
// FULL BACKUP — every table as one CSV each, bundled into a ZIP
// ─────────────────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['full_backup_action'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token'])) {
        die('Security token mismatch.');
    }

    // Sensitive hash columns (password/pin_hash) are deliberately excluded —
    // a backup doesn't need them to be useful, and there's no reason to let
    // credential hashes leave the server in a downloadable file.
    $backup_tables = [
        'siri'                  => null,
        'sessions'               => null,
        'levels'                 => null,
        'tests'                  => null,
        'criteria'               => null,
        'schools'                => null,
        'session_schools'        => null,
        'siri_schools'           => null,
        'students'               => null,
        'groups'                 => null,
        'group_students'         => null,
        'judges'                 => 'SELECT id, name, email, role, judge_code FROM judges',
        'scores'                 => null,
        'attendance'             => null,
        'users'                  => "SELECT id, username, role FROM users WHERE role != 'admin'",
        'notifications'          => null,
        'notification_reads'     => null,
        'medal_quotas'           => null,
        'medal_quotas_school'    => null,
        'medal_quotas_session'   => null,
    ];

    $tmpZip = tempnam(sys_get_temp_dir(), 'pmbackup_');
    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        die('Could not create backup archive.');
    }

    foreach ($backup_tables as $table => $customQuery) {
        $query  = $customQuery ?? "SELECT * FROM `{$table}`";
        $result = $conn->query($query);
        if (!$result) continue;

        $csv = fopen('php://temp', 'w+');
        // BOM for Excel UTF-8
        fwrite($csv, "\xEF\xBB\xBF");
        $fields = $result->fetch_fields();
        fputcsv($csv, array_map(fn($f) => $f->name, $fields));
        while ($row = $result->fetch_assoc()) {
            fputcsv($csv, $row);
        }
        rewind($csv);
        $zip->addFromString($table . '.csv', stream_get_contents($csv));
        fclose($csv);
    }

    $zip->close();

    $fname = 'promarkah_full_backup_' . date('Ymd_His') . '.zip';
    header('Content-Type: application/zip');
    header("Content-Disposition: attachment; filename=\"{$fname}\"");
    header('Content-Length: ' . filesize($tmpZip));
    readfile($tmpZip);
    unlink($tmpZip);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// EXPORT HANDLERS — output file and exit before any HTML
// ─────────────────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_action'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token'])) {
        die('Security token mismatch.');
    }

    $export_type   = $_POST['export_type']   ?? 'scores';
    $export_format = $_POST['export_format'] ?? 'csv';

    // Map export type to query + columns
    $export_map = [
        'scores' => [
            'label' => 'scores',
            'query' => "SELECT s.score_id, st.student_name, sc.school_name, se.session_name,
                               t.test_name, c.criteria_name, s.mark, s.submitted, s.created_at
                        FROM scores s
                        LEFT JOIN students st ON s.student_id = st.student_id
                        LEFT JOIN schools sc ON st.school_id = sc.school_id
                        LEFT JOIN criteria c ON s.criteria_id = c.criteria_id
                        LEFT JOIN tests t ON c.test_id = t.test_id
                        LEFT JOIN sessions se ON EXISTS (
                            SELECT 1 FROM `groups` g JOIN levels lv ON g.level_id = lv.level_id
                            WHERE g.group_id = s.group_id AND lv.session_id = se.session_id
                        )
                        ORDER BY s.created_at DESC",
            'cols' => ['score_id','student_name','school_name','session_name','test_name','criteria_name','mark','submitted','created_at'],
        ],
        'attendance' => [
            'label' => 'attendance',
            'query' => "SELECT a.attendance_id, st.student_name, sc.school_name,
                               se.session_name, a.status, a.timestamp, a.updated_by
                        FROM attendance a
                        LEFT JOIN students st ON a.student_id = st.student_id
                        LEFT JOIN schools sc ON st.school_id = sc.school_id
                        LEFT JOIN sessions se ON a.session_id = se.session_id
                        ORDER BY a.timestamp DESC",
            'cols' => ['attendance_id','student_name','school_name','session_name','status','timestamp','updated_by'],
        ],
        'students' => [
            'label' => 'students',
            'query' => "SELECT st.student_id, st.student_name, sc.school_name,
                               l.level_name, st.gender, st.year, st.created_at
                        FROM students st
                        LEFT JOIN schools sc ON st.school_id = sc.school_id
                        LEFT JOIN levels l ON st.level_id = l.level_id
                        ORDER BY sc.school_name, st.student_name",
            'cols' => ['student_id','student_name','school_name','level_name','gender','year','created_at'],
        ],
        'users' => [
            'label' => 'users',
            'query' => "SELECT id, username, role FROM users WHERE role != 'admin' ORDER BY role, username",
            'cols' => ['id','username','role'],
        ],
    ];

    if (!array_key_exists($export_type, $export_map)) die('Invalid export type.');

    $def    = $export_map[$export_type];
    $result = $conn->query($def['query']);
    $rows   = [];
    while ($r = $result->fetch_assoc()) $rows[] = $r;
    $cols   = $def['cols'];
    $fname  = 'promarkah_' . $def['label'] . '_' . date('Ymd_His');

    if ($export_format === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"{$fname}.csv\"");
        // BOM for Excel UTF-8
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, $cols);
        foreach ($rows as $row) {
            $line = [];
            foreach ($cols as $c) $line[] = $row[$c] ?? '';
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }

    if ($export_format === 'pdf') {
        // Lightweight HTML-to-print PDF fallback (no external library required)
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
        <title>' . htmlspecialchars($fname) . '</title>
        <style>
            body { font-family: Arial, sans-serif; font-size: 11px; margin: 20px; }
            h2 { font-size: 14px; margin-bottom: 8px; }
            p { font-size: 10px; color: #666; margin-bottom: 12px; }
            table { border-collapse: collapse; width: 100%; }
            th { background: #cc0000; color: #fff; padding: 5px 8px; text-align: left; font-size: 10px; text-transform: uppercase; }
            td { padding: 4px 8px; border-bottom: 1px solid #ddd; }
            tr:nth-child(even) td { background: #f9f9f9; }
            @media print { @page { margin: 15mm; } }
        </style>
        </head><body onload="window.print()">
        <h2>ProMarkah — ' . htmlspecialchars(ucfirst($def['label'])) . ' Export</h2>
        <p>Generated: ' . date('d M Y, H:i:s') . ' &nbsp;|&nbsp; ' . count($rows) . ' records</p>
        <table><thead><tr>';
        foreach ($cols as $c) echo '<th>' . htmlspecialchars(ucwords(str_replace('_', ' ', $c))) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($cols as $c) echo '<td>' . htmlspecialchars((string)($row[$c] ?? '')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></body></html>';
        exit;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// RESET HANDLERS
// ─────────────────────────────────────────────────────────────────────────────

$flash = '';
$flash_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_action'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token'])) {
        $flash = 'Security token mismatch.'; $flash_type = 'error';
    } else {
        $confirm_text = trim($_POST['confirm_text'] ?? '');
        $scope        = $_POST['reset_scope'] ?? '';

        $scope_map = [
            'scores'     => ['RESET', 'DELETE FROM scores',                         'All scores deleted.'],
            'attendance' => ['RESET', 'DELETE FROM attendance',                      'All attendance records deleted.'],
            'groups'     => ['RESET', ['DELETE FROM group_students', 'DELETE FROM groups'], 'All groups and group assignments deleted.'],
            'siri_reset' => ['RESET SIRI', null,                                     'Students, groups, scores and attendance wiped. Schools (cawangan) were kept. Every siri other than "Siri 1" was removed entirely; Siri 1 and its silibus (levels/tests/criteria) were kept intact.'],
            'full'       => ['WIPE',  null,                                           'Full data wipe completed.'],
        ];

        if (!array_key_exists($scope, $scope_map)) {
            $flash = 'Invalid reset scope.'; $flash_type = 'error';
        } else {
            [$required_word, $queries, $success_msg] = $scope_map[$scope];

            if ($confirm_text !== $required_word) {
                $flash = "Type \"{$required_word}\" exactly to confirm."; $flash_type = 'error';
            } else {
                $conn->begin_transaction();
                try {
                    $conn->query("SET FOREIGN_KEY_CHECKS = 0");

                    if ($scope === 'full') {
                        $wipe_tables = [
                            'scores', 'group_students', 'groups', 'attendance',
                            'notifications', 'students', 'levels', 'criteria',
                            'tests', 'sessions', 'session_schools', 'siri',
                            'siri_schools', 'schools', 'judges',
                            'medal_quotas', 'medal_quotas_school', 'medal_quotas_session',
                        ];
                        foreach ($wipe_tables as $t) {
                            $conn->query("DELETE FROM `{$t}`");
                        }
                    } elseif ($scope === 'siri_reset') {
                        // Find the siri row to preserve — "Siri 1" by name,
                        // falling back to the lowest siri_id if that name
                        // isn't found. This row (and its sessions/silibus)
                        // is never deleted.
                        $keepSiriId = null;
                        $r = $conn->query("SELECT siri_id FROM siri WHERE siri_name = 'Siri 1' LIMIT 1");
                        if ($r && $row = $r->fetch_assoc()) {
                            $keepSiriId = (int)$row['siri_id'];
                        } else {
                            $r2 = $conn->query("SELECT MIN(siri_id) AS id FROM siri");
                            $row2 = $r2 ? $r2->fetch_assoc() : null;
                            $keepSiriId = ($row2 && $row2['id'] !== null) ? (int)$row2['id'] : null;
                        }
                        if ($keepSiriId === null) {
                            throw new Exception('No siri record found to preserve — aborting reset.');
                        }

                        // Sessions under the kept siri — their levels/tests/
                        // criteria (silibus) are never touched.
                        $keptSessionIds = [];
                        $rs = $conn->query("SELECT session_id FROM sessions WHERE siri_id = {$keepSiriId}");
                        if ($rs) while ($row = $rs->fetch_assoc()) $keptSessionIds[] = (int)$row['session_id'];
                        $keptSessionsSql = $keptSessionIds ? implode(',', $keptSessionIds) : '0';

                        // Full data wipe — every siri. Schools (cawangan) are
                        // deliberately excluded — only the siri/session
                        // associations (session_schools, siri_schools) are
                        // cleared, the school records themselves stay.
                        $wipe_tables = [
                            'scores', 'attendance', 'group_students', 'groups',
                            'notification_reads', 'notifications', 'students', 'judges',
                            'medal_quotas', 'medal_quotas_school', 'medal_quotas_session',
                            'session_schools', 'siri_schools',
                        ];
                        foreach ($wipe_tables as $t) {
                            $conn->query("DELETE FROM `{$t}`");
                        }

                        // Silibus + sessions + siri belonging to every OTHER
                        // siri — removed entirely. Kept siri's silibus is
                        // untouched since it's excluded via NOT IN(...).
                        $conn->query("DELETE FROM criteria WHERE test_id IN (
                            SELECT test_id FROM tests WHERE level_id IN (
                                SELECT level_id FROM levels WHERE session_id NOT IN ({$keptSessionsSql})
                            )
                        )");
                        $conn->query("DELETE FROM tests WHERE level_id IN (
                            SELECT level_id FROM levels WHERE session_id NOT IN ({$keptSessionsSql})
                        )");
                        $conn->query("DELETE FROM levels WHERE session_id NOT IN ({$keptSessionsSql})");
                        $conn->query("DELETE FROM sessions WHERE siri_id != {$keepSiriId}");
                        $conn->query("DELETE FROM siri WHERE siri_id != {$keepSiriId}");
                    } elseif (is_array($queries)) {
                        foreach ($queries as $q) $conn->query($q);
                    } else {
                        $conn->query($queries);
                    }

                    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                    $conn->commit();
                    $flash = $success_msg;
                } catch (Exception $e) {
                    $conn->rollback();
                    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                    promarkah_report('Caught', 'admin_data.php reset failed (scope=' . $scope . ') — ' . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
                    $flash = 'Reset failed: ' . $e->getMessage();
                    $flash_type = 'error';
                }
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// RECORD COUNTS for display
// ─────────────────────────────────────────────────────────────────────────────
function tbl_count($conn, $table) {
    $r = $conn->query("SELECT COUNT(*) c FROM `{$table}`");
    return $r ? (int)$r->fetch_assoc()['c'] : 0;
}
$counts = [
    'scores'     => tbl_count($conn, 'scores'),
    'attendance' => tbl_count($conn, 'attendance'),
    'students'   => tbl_count($conn, 'students'),
    'groups'     => tbl_count($conn, 'groups'),
];

$pm_page = 'admin_data';
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

    .ap-tabnav { display: flex; gap: 4px; border-bottom: 1px solid var(--c-border); flex-wrap: wrap; }
    .ap-tab { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; font-size: var(--text-sm); font-weight: 600; font-family: 'DM Sans', sans-serif; color: var(--c-text-faint); border: 1px solid transparent; border-bottom: none; border-radius: var(--radius-sm) var(--radius-sm) 0 0; text-decoration: none; transition: color var(--fast) var(--ease), background var(--fast) var(--ease); position: relative; bottom: -1px; }
    .ap-tab svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 1.75; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }
    .ap-tab:hover { color: var(--c-text); }
    .ap-tab.active { color: var(--c-text); background: var(--c-surface-1); border-color: var(--c-border); border-bottom-color: var(--c-surface-1); }

    .ap-page-title h1 { font-family: 'Bebas Neue', sans-serif; font-size: var(--text-2xl); color: var(--c-text); letter-spacing: 0.04em; margin: 0 0 2px; }
    .ap-page-title p { font-size: var(--text-sm); color: var(--c-text-faint); margin: 0; }

    .ap-flash { border-radius: var(--radius-sm); padding: var(--sp-3) var(--sp-4); font-size: var(--text-sm); font-weight: 500; }
    .ap-flash.success { background: rgba(255,255,255,0.06); border: 1px solid var(--c-border); color: var(--c-text-muted); }
    .ap-flash.error { background: rgba(204,0,0,0.10); border: 1px solid rgba(204,0,0,0.30); color: #ff6b6b; }

    .ap-card { background: var(--c-surface-1); border: 1px solid var(--c-border); border-radius: var(--radius-lg); overflow: hidden; }
    .ap-card-head { padding: var(--sp-4) var(--sp-5); border-bottom: 1px solid var(--c-border); display: flex; align-items: center; gap: var(--sp-3); }
    .ap-card-head h2 { font-family: 'Bebas Neue', sans-serif; font-size: var(--text-md); color: var(--c-text); letter-spacing: 0.06em; margin: 0; }
    .ap-card-head svg { width: 16px; height: 16px; color: var(--c-red); stroke: currentColor; fill: none; stroke-width: 1.75; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }
    .ap-card-body { padding: var(--sp-5); }

    /* Export grid */
    .export-grid { display: grid; grid-template-columns: 1fr 1fr; gap: var(--sp-4); }
    @media (max-width: 560px) { .export-grid { grid-template-columns: 1fr; } }

    .export-item {
        border: 1px solid var(--c-border);
        border-radius: var(--radius-sm);
        padding: var(--sp-4);
        display: flex;
        flex-direction: column;
        gap: var(--sp-3);
    }
    .export-item-header { display: flex; align-items: center; gap: var(--sp-3); }
    .export-item-header svg { width: 18px; height: 18px; stroke: var(--c-red); fill: none; stroke-width: 1.75; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }
    .export-item-title { font-weight: 600; font-size: var(--text-sm); color: var(--c-text); }
    .export-item-count { font-size: var(--text-xs); color: var(--c-text-faint); }
    .export-btn-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: var(--sp-2);
        margin: 0;
        padding: 0;
    }

    .btn-export-csv {
        background: transparent;
        border: 1px solid var(--c-border);
        border-radius: var(--radius-sm);
        padding: 8px 10px;
        font-size: var(--text-xs);
        font-weight: 600;
        font-family: 'DM Sans', sans-serif;
        color: var(--c-text-muted);
        cursor: pointer;
        transition: all var(--fast) var(--ease);
        width: 100%;
    }
    .btn-export-csv:hover { border-color: var(--c-border-strong); color: var(--c-text); }

    .btn-export-pdf {
        background: var(--c-red);
        border: none;
        border-radius: var(--radius-sm);
        padding: 8px 10px;
        font-size: var(--text-xs);
        font-weight: 600;
        font-family: 'DM Sans', sans-serif;
        color: #fff;
        cursor: pointer;
        transition: background var(--fast) var(--ease);
        width: 100%;
    }
    .btn-export-pdf:hover { background: var(--c-red-dark); }

    /* Reset section */
    .reset-grid { display: grid; grid-template-columns: 1fr 1fr; gap: var(--sp-4); }
    @media (max-width: 560px) { .reset-grid { grid-template-columns: 1fr; } }

    .reset-item { border: 1px solid var(--c-border); border-radius: var(--radius-sm); padding: var(--sp-4); display: flex; flex-direction: column; gap: var(--sp-3); }
    .reset-item.danger { border-color: rgba(204,0,0,0.3); background: rgba(204,0,0,0.04); }

    .reset-item-title { font-weight: 600; font-size: var(--text-sm); color: var(--c-text); }
    .reset-item-desc { font-size: var(--text-xs); color: var(--c-text-faint); line-height: 1.5; }

    .reset-confirm-label { font-size: var(--text-xs); color: var(--c-text-faint); text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 5px; }
    .reset-confirm-input {
        background: var(--c-surface-1);
        border: 1px solid var(--c-border);
        border-radius: var(--radius-sm);
        color: var(--c-text);
        padding: 8px var(--sp-3);
        font-size: var(--text-sm);
        font-family: 'DM Mono', monospace;
        outline: none;
        width: 100%;
        box-sizing: border-box;
        transition: border-color var(--fast) var(--ease);
    }
    .reset-confirm-input:focus { border-color: var(--c-red); }

    .btn-reset { background: transparent; border: 1px solid rgba(204,0,0,0.4); color: var(--c-red); border-radius: var(--radius-sm); padding: 8px var(--sp-4); font-size: var(--text-sm); font-weight: 600; font-family: 'DM Sans', sans-serif; cursor: pointer; width: 100%; transition: all var(--fast) var(--ease); }
    .btn-reset:hover { background: rgba(204,0,0,0.1); border-color: var(--c-red); }

    .btn-reset-full { background: var(--c-red); border: none; color: #fff; border-radius: var(--radius-sm); padding: 8px var(--sp-4); font-size: var(--text-sm); font-weight: 600; font-family: 'DM Sans', sans-serif; cursor: pointer; width: 100%; transition: background var(--fast) var(--ease); }
    .btn-reset-full:hover { background: var(--c-red-dark); }

    .warning-badge { display: inline-flex; align-items: center; gap: 5px; background: rgba(204,0,0,0.1); border: 1px solid rgba(204,0,0,0.3); color: var(--c-red); border-radius: 99px; padding: 3px 10px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; }
</style>

<div class="ap-wrap">

    <div class="ap-page-title">
        <h1>Data &amp; Export</h1>
        <p>Export competition data or reset records.</p>
    </div>

    <nav class="ap-tabnav">
        <a class="ap-tab" href="admin.php">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Users
        </a>
        <a class="ap-tab active" href="admin_data.php">
            <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Data &amp; Export
        </a>
        <a class="ap-tab" href="admin_logs.php">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
            Logs &amp; Stats
        </a>
    </nav>

    <?php if ($flash): ?>
        <div class="ap-flash <?= $flash_type ?>"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <!-- Export -->
    <div class="ap-card">
        <div class="ap-card-head">
            <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            <h2>Export Data</h2>
        </div>
        <div class="ap-card-body" style="display:flex; flex-direction:column; gap:var(--sp-4);">

            <div class="export-item" style="border-color:var(--c-border-strong);">
                <div class="export-item-header">
                    <svg viewBox="0 0 24 24" style="stroke:var(--c-red);"><path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M10 12h4"/></svg>
                    <div>
                        <div class="export-item-title">Full Backup (All Data)</div>
                        <div class="export-item-count">Every table as one CSV, bundled into a single ZIP</div>
                    </div>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="full_backup_action" value="1">
                    <button type="submit" class="btn-export-pdf" style="width:100%;">Download Full Backup (ZIP)</button>
                </form>
            </div>

            <div class="export-grid">

                <?php
                $export_cards = [
                    [
                        'type'  => 'scores',
                        'title' => 'Scores',
                        'count' => number_format($counts['scores']) . ' records',
                        'icon'  => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
                    ],
                    [
                        'type'  => 'attendance',
                        'title' => 'Attendance',
                        'count' => number_format($counts['attendance']) . ' records',
                        'icon'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/>',
                    ],
                    [
                        'type'  => 'students',
                        'title' => 'Students',
                        'count' => number_format($counts['students']) . ' records',
                        'icon'  => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
                    ],
                    [
                        'type'  => 'users',
                        'title' => 'User Accounts',
                        'count' => 'PIC / Recorder / Judge',
                        'icon'  => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
                    ],
                ];
                foreach ($export_cards as $card): ?>
                <div class="export-item">
                    <div class="export-item-header">
                        <svg viewBox="0 0 24 24"><?= $card['icon'] ?></svg>
                        <div>
                            <div class="export-item-title"><?= htmlspecialchars($card['title']) ?></div>
                            <div class="export-item-count"><?= htmlspecialchars($card['count']) ?></div>
                        </div>
                    </div>
                    <form method="POST" class="export-btn-row">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="export_action" value="1">
                        <input type="hidden" name="export_type" value="<?= $card['type'] ?>">
                        <button type="submit" name="export_format" value="csv" class="btn-export-csv">CSV</button>
                        <button type="submit" name="export_format" value="pdf" class="btn-export-pdf">Print / PDF</button>
                    </form>
                </div>
                <?php endforeach; ?>

            </div>
        </div>
    </div>

    <!-- Reset -->
    <div class="ap-card">
        <div class="ap-card-head">
            <svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.88"/></svg>
            <h2>Reset Data</h2>
        </div>
        <div class="ap-card-body" style="display:flex; flex-direction:column; gap:var(--sp-4);">

            <p style="font-size:var(--text-sm); color:var(--c-text-faint); margin:0;">
                All resets are permanent and cannot be undone. Export a backup first if needed.
                Type the confirmation word exactly as shown before submitting.
            </p>

            <div class="reset-grid">

                <!-- Scores only -->
                <div class="reset-item">
                    <div class="reset-item-title">Scores Only</div>
                    <div class="reset-item-desc">Deletes all score records. Students, groups and sessions are kept intact. Confirmation word: <strong>RESET</strong></div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="reset_action" value="1">
                        <input type="hidden" name="reset_scope" value="scores">
                        <div class="reset-confirm-label">Type RESET to confirm</div>
                        <input type="text" name="confirm_text" class="reset-confirm-input" autocomplete="off" placeholder="RESET" style="margin-bottom:var(--sp-3);">
                        <button type="submit" class="btn-reset">Delete All Scores</button>
                    </form>
                </div>

                <!-- Attendance only -->
                <div class="reset-item">
                    <div class="reset-item-title">Attendance Only</div>
                    <div class="reset-item-desc">Deletes all attendance records. Everything else is untouched. Confirmation word: <strong>RESET</strong></div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="reset_action" value="1">
                        <input type="hidden" name="reset_scope" value="attendance">
                        <div class="reset-confirm-label">Type RESET to confirm</div>
                        <input type="text" name="confirm_text" class="reset-confirm-input" autocomplete="off" placeholder="RESET" style="margin-bottom:var(--sp-3);">
                        <button type="submit" class="btn-reset">Delete All Attendance</button>
                    </form>
                </div>

                <!-- Groups only -->
                <div class="reset-item">
                    <div class="reset-item-title">Groups &amp; Assignments</div>
                    <div class="reset-item-desc">Deletes all judge group assignments and groups. Scores are NOT deleted but will be orphaned. Confirmation word: <strong>RESET</strong></div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="reset_action" value="1">
                        <input type="hidden" name="reset_scope" value="groups">
                        <div class="reset-confirm-label">Type RESET to confirm</div>
                        <input type="text" name="confirm_text" class="reset-confirm-input" autocomplete="off" placeholder="RESET" style="margin-bottom:var(--sp-3);">
                        <button type="submit" class="btn-reset">Delete All Groups</button>
                    </form>
                </div>

                <!-- Siri reset (keep Siri 1 + silibus) -->
                <div class="reset-item danger">
                    <div style="display:flex; align-items:center; gap:var(--sp-2); flex-wrap:wrap;">
                        <div class="reset-item-title">Reset Data, Keep Siri 1</div>
                        <span class="warning-badge">
                            <svg viewBox="0 0 24 24" style="width:10px;height:10px;stroke:currentColor;fill:none;stroke-width:2.5;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            Destructive
                        </span>
                    </div>
                    <div class="reset-item-desc">Deletes all students, groups, scores and attendance (both siri). Schools (cawangan) are kept. Any siri other than "Siri 1" is removed entirely. Siri 1 and its silibus (levels, tests, criteria) are kept intact — only rows are deleted, tables themselves are never dropped. Confirmation word: <strong>RESET SIRI</strong></div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="reset_action" value="1">
                        <input type="hidden" name="reset_scope" value="siri_reset">
                        <div class="reset-confirm-label">Type RESET SIRI to confirm</div>
                        <input type="text" name="confirm_text" class="reset-confirm-input" autocomplete="off" placeholder="RESET SIRI" style="margin-bottom:var(--sp-3);">
                        <button type="submit" class="btn-reset-full">Reset Data, Keep Siri 1</button>
                    </form>
                </div>

                <!-- Full wipe -->
                <div class="reset-item danger">
                    <div style="display:flex; align-items:center; gap:var(--sp-2); flex-wrap:wrap;">
                        <div class="reset-item-title">Full Data Wipe</div>
                        <span class="warning-badge">
                            <svg viewBox="0 0 24 24" style="width:10px;height:10px;stroke:currentColor;fill:none;stroke-width:2.5;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            Destructive
                        </span>
                    </div>
                    <div class="reset-item-desc">Deletes all competition data: scores, attendance, groups, students, sessions, schools, siri, tests, criteria, judges, and medal settings. User accounts are kept. Confirmation word: <strong>WIPE</strong></div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="reset_action" value="1">
                        <input type="hidden" name="reset_scope" value="full">
                        <div class="reset-confirm-label">Type WIPE to confirm</div>
                        <input type="text" name="confirm_text" class="reset-confirm-input" autocomplete="off" placeholder="WIPE" style="margin-bottom:var(--sp-3);">
                        <button type="submit" class="btn-reset-full">Wipe All Competition Data</button>
                    </form>
                </div>

            </div>
        </div>
    </div>

</div>

</main>
</body>
</html>