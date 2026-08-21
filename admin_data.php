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

    // Full restore fidelity: this backup includes password/pin hash columns
    // and admin accounts, so a matching Import can fully recreate every
    // login without anyone re-typing a password. That means this ZIP is as
    // sensitive as raw DB credentials — treat it that way when storing or
    // sharing it.
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
        'judges'                 => null,
        'scores'                 => null,
        'attendance'             => null,
        'users'                  => null,
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
// FULL RESTORE — read a Full Backup ZIP back and reload every table from it
// ─────────────────────────────────────────────────────────────────────────────

$flash = '';
$flash_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_action'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token'])) {
        $flash = 'Security token mismatch.'; $flash_type = 'error';
    } elseif (trim($_POST['import_confirm'] ?? '') !== 'IMPORT') {
        $flash = 'Type "IMPORT" exactly to confirm.'; $flash_type = 'error';
    } elseif (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        $flash = 'No backup file uploaded, or the upload failed.'; $flash_type = 'error';
    } elseif (strtolower(pathinfo($_FILES['backup_file']['name'], PATHINFO_EXTENSION)) !== 'zip') {
        $flash = 'The uploaded file must be a .zip backup produced by "Download Full Backup".'; $flash_type = 'error';
    } else {
        $zip = new ZipArchive();
        if ($zip->open($_FILES['backup_file']['tmp_name']) !== true) {
            $flash = 'Could not read the uploaded ZIP file — it may be corrupted.'; $flash_type = 'error';
        } else {
            // Fixed, known table names only — the ZIP entry name is never used
            // to build a path, so there's no path-traversal surface here.
            // Parent tables first, so FK-check-enabled installs still load
            // cleanly even though we also disable checks during the import.
            $import_order = [
                'siri', 'sessions', 'levels', 'tests', 'criteria', 'schools',
                'session_schools', 'siri_schools', 'students', 'groups',
                'group_students', 'judges', 'scores', 'attendance', 'users',
                'notifications', 'notification_reads', 'medal_quotas',
                'medal_quotas_school', 'medal_quotas_session',
            ];

            $conn->begin_transaction();
            try {
                $conn->query("SET FOREIGN_KEY_CHECKS = 0");
                $imported = [];
                $skipped  = [];

                foreach ($import_order as $table) {
                    $csvContent = $zip->getFromName($table . '.csv');
                    if ($csvContent === false) { $skipped[] = $table; continue; }

                    $fh = fopen('php://temp', 'r+');
                    fwrite($fh, $csvContent);
                    rewind($fh);
                    if (fread($fh, 3) !== "\xEF\xBB\xBF") rewind($fh); // strip export's UTF-8 BOM if present

                    $header = fgetcsv($fh);
                    if (!$header) { fclose($fh); $skipped[] = $table; continue; }

                    // Only import columns that actually exist on this install —
                    // production DBs may be a schema version behind/ahead.
                    $tableCols = [];
                    $colsResult = $conn->query("SHOW COLUMNS FROM `{$table}`");
                    while ($c = $colsResult->fetch_assoc()) $tableCols[] = $c['Field'];

                    $useCols = array_values(array_intersect($header, $tableCols));
                    if (empty($useCols)) { fclose($fh); $skipped[] = $table; continue; }

                    $headerIndex = array_flip($header);

                    $conn->query("DELETE FROM `{$table}`");

                    $colList      = implode(',', array_map(fn($c) => "`{$c}`", $useCols));
                    $placeholders = implode(',', array_fill(0, count($useCols), '?'));
                    $stmt  = $conn->prepare("INSERT INTO `{$table}` ({$colList}) VALUES ({$placeholders})");
                    $types = str_repeat('s', count($useCols));

                    $rowCount = 0;
                    while (($row = fgetcsv($fh)) !== false) {
                        if ($row === [null]) continue; // trailing blank line
                        $vals = [];
                        foreach ($useCols as $c) {
                            $v = $row[$headerIndex[$c]] ?? null;
                            $vals[] = ($v === '') ? null : $v;
                        }
                        $stmt->bind_param($types, ...$vals);
                        $stmt->execute();
                        $rowCount++;
                    }
                    $stmt->close();
                    fclose($fh);
                    $imported[$table] = $rowCount;
                }

                $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                $conn->commit();

                $flash = 'Restore complete — ' . number_format(array_sum($imported)) . ' rows reloaded across '
                        . count($imported) . ' tables.'
                        . (!empty($skipped) ? ' Not found in the ZIP (left untouched): ' . implode(', ', $skipped) . '.' : '')
                        . ' If the restored data includes different admin/PIC accounts, log out and back in to refresh your session.';
            } catch (Exception $e) {
                $conn->rollback();
                $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                promarkah_report('Caught', 'admin_data.php import failed — ' . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
                $flash = 'Restore failed: ' . $e->getMessage();
                $flash_type = 'error';
            }
            $zip->close();
        }
    }
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

<?php
$pm_adata_css_v = @filemtime(__DIR__ . '/admin_data.css') ?: time();
?>
<link rel="stylesheet" href="admin_data.css?v=<?= $pm_adata_css_v ?>">

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
                        <div class="export-item-count">Every table as one CSV — scores, groups, students, judges, admin/PIC/judge accounts — bundled into a single ZIP</div>
                    </div>
                </div>
                <p style="font-size:var(--text-xs); color:var(--c-red); margin:0;">Contains password/PIN hashes so it can fully restore logins. Store and share this file as securely as your database credentials.</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="full_backup_action" value="1">
                    <button type="submit" class="btn-export-pdf" style="width:100%;">Download Full Backup (ZIP)</button>
                </form>
            </div>

            <div class="export-item" style="border-color:rgba(204,0,0,0.3); background:rgba(204,0,0,0.04);">
                <div class="export-item-header">
                    <svg viewBox="0 0 24 24" style="stroke:var(--c-red);"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <div>
                        <div class="export-item-title">Import Full Backup (Restore)</div>
                        <div class="export-item-count">Reloads every table in the ZIP from a Full Backup download</div>
                    </div>
                </div>
                <p style="font-size:var(--text-xs); color:var(--c-text-faint); margin:0; line-height:1.5;">
                    This <strong>replaces</strong> all current data in every table found in the ZIP — existing rows are deleted first, then the backup's rows are reloaded with their original IDs. Tables missing from the ZIP are left untouched. This cannot be undone; export a fresh backup first if you want to keep the current state.
                </p>
                <form method="POST" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:var(--sp-3);">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="import_action" value="1">
                    <input type="file" name="backup_file" accept=".zip" required class="reset-confirm-input">
                    <div>
                        <div class="reset-confirm-label">Type IMPORT to confirm</div>
                        <input type="text" name="import_confirm" class="reset-confirm-input" autocomplete="off" placeholder="IMPORT">
                    </div>
                    <button type="submit" class="btn-reset-full">Restore From Backup</button>
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