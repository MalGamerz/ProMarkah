<?php
ini_set('display_errors', 0); ini_set('display_startup_errors', 0);
error_reporting(E_ALL); ini_set('log_errors', 1);

session_start();
require __DIR__ . '/auth_check.php';
include 'db.php';
$conn = getDB();


if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pic') {
    header("Location: login.php"); exit();
}

// Groups didn't originally carry a cawangan of their own — see the
// visibility filter further down for why that made an empty group
// (created inside one cawangan's accordion) show up under every other
// cawangan too. This column lets a group be tagged with the cawangan it
// was created for / first assigned a student from, so it can stay
// exclusive from the moment it's created rather than only once it has a
// member. NULL means "untagged legacy group" — still handled by the old
// membership-based fallback for rows that predate this column.
if (empty($_SESSION['pm_groups_schema_checked'])) {
    $colCheck = $conn->query("SHOW COLUMNS FROM `groups` LIKE 'school_id'");
    if ($colCheck && $colCheck->num_rows == 0) {
        $conn->query("ALTER TABLE `groups` ADD COLUMN `school_id` INT NULL DEFAULT NULL");
    }
    // Backfill: every existing group that already has members but was
    // never tagged (created before this column existed) gets tagged now,
    // inferred from whichever cawangan its members actually belong to —
    // this is what was still leaking groups like "005-LA-CM1" into every
    // other cawangan's list even after the code fix above, since the fix
    // only takes effect once a group is either tagged or truly empty, and
    // these groups were neither. Idempotent — re-running just no-ops on
    // already-tagged rows (WHERE school_id IS NULL).
    $conn->query(
        "UPDATE `groups` g
         SET g.school_id = (
             SELECT st.school_id FROM group_students gs
             JOIN students st ON gs.student_id = st.student_id
             WHERE gs.group_id = g.group_id
             LIMIT 1
         )
         WHERE g.school_id IS NULL
           AND EXISTS (SELECT 1 FROM group_students gs2 WHERE gs2.group_id = g.group_id)"
    );
    $_SESSION['pm_groups_schema_checked'] = true;
}

// group_students had no explicit position — display order fell back to
// student_id, and judge.php independently did the same, so the two only
// ever agreed by coincidence. This column is the single source of truth
// for a member's position within their group (drag-and-drop below writes
// it; judge.php's marking screen reads it), so duos/partners stay in the
// same relative spot on both screens.
if (empty($_SESSION['pm_group_students_order_checked'])) {
    $colCheck = $conn->query("SHOW COLUMNS FROM `group_students` LIKE 'sort_order'");
    if ($colCheck && $colCheck->num_rows == 0) {
        $conn->query("ALTER TABLE `group_students` ADD COLUMN `sort_order` INT NULL DEFAULT NULL");
    }
    // Backfill existing memberships with the same order they've always
    // effectively displayed in (student_id ascending within each group),
    // so nothing visibly reshuffles before anyone drags a row. Plain PHP
    // loop rather than a SQL user-variable trick — this only ever runs
    // once per group_id and correctness here matters more than a saved
    // round trip; every read of sort_order elsewhere already falls back
    // to student_id on ties/NULLs anyway.
    $unordered = $conn->query("SELECT group_id, student_id FROM `group_students` WHERE sort_order IS NULL ORDER BY group_id, student_id");
    if ($unordered && $unordered->num_rows > 0) {
        $backfillStmt = $conn->prepare("UPDATE `group_students` SET sort_order=? WHERE group_id=? AND student_id=?");
        $pos = []; // group_id => next sort_order
        while ($row = $unordered->fetch_assoc()) {
            $gid = (int)$row['group_id'];
            $n = $pos[$gid] ?? 0;
            $pos[$gid] = $n + 1;
            $backfillStmt->bind_param('iii', $n, $gid, $row['student_id']);
            $backfillStmt->execute();
        }
        $backfillStmt->close();
    }
    $_SESSION['pm_group_students_order_checked'] = true;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Every mutating action here is a single, immediately-persisted unit (move
// one student, rename one group, create one group, delete one group) fired
// straight from the board's drag/drop and inline-edit handlers — there is
// no batch "Simpan" step anymore. Always responds JSON; nothing on this
// page submits a traditional <form> POST anymore, so there is no
// redirect-based fallback to maintain.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token'])) {
        echo json_encode(['ok' => false, 'msg' => 'Ralat token keselamatan. Sila muat semula halaman.']);
        exit();
    }
    session_write_close();
    $ok = false;
    $msg = '';
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'move_student') {
            // Moves one student into a (possibly different) group, and
            // rewrites the destination column's sort_order from scratch
            // using the client's post-drop DOM order — the same
            // "loop order becomes sort_order" approach the old batch save
            // used, just scoped to one column instead of a whole card.
            // Re-deleting/re-inserting every id in $order (not just the
            // moved student) is deliberately redundant for anyone who
            // didn't move, but it guarantees the resulting order always
            // matches exactly what the PIC sees on screen, with no
            // possibility of drifting from a partial position update.
            $sid = (int)($_POST['student_id'] ?? 0);
            $gid = (int)($_POST['group_id'] ?? 0);
            $order = isset($_POST['order']) && is_array($_POST['order']) ? array_map('intval', $_POST['order']) : [$sid];
            if ($sid > 0) {
                $ok = true;
                $stmtDel = $conn->prepare("DELETE FROM group_students WHERE student_id=?");
                $stmtDel->bind_param('i', $sid);
                if (!$stmtDel->execute()) { $ok = false; }
                $stmtDel->close();
                if ($gid > 0) {
                    $stmtDelOrder = $conn->prepare("DELETE FROM group_students WHERE student_id=?");
                    $stmtIns = $conn->prepare("INSERT INTO group_students (group_id, student_id, sort_order) VALUES (?, ?, ?)");
                    $stmtClaim = $conn->prepare("UPDATE `groups` SET school_id = (SELECT school_id FROM students WHERE student_id = ?) WHERE group_id = ? AND school_id IS NULL");
                    foreach ($order as $pos => $osid) {
                        if ($osid <= 0) continue;
                        $stmtDelOrder->bind_param('i', $osid);
                        if (!$stmtDelOrder->execute()) { $ok = false; }
                        $stmtIns->bind_param('iii', $gid, $osid, $pos);
                        if (!$stmtIns->execute()) { $ok = false; }
                        $stmtClaim->bind_param('ii', $osid, $gid);
                        $stmtClaim->execute();
                    }
                    $stmtDelOrder->close(); $stmtIns->close(); $stmtClaim->close();
                }
                $msg = $ok ? 'Pelajar dipindahkan.' : 'Ralat memindahkan pelajar. Sila cuba lagi.';
            } else {
                $msg = 'Pelajar tidak sah.';
            }
        } elseif ($action === 'create_group') {
            $level_id  = (int)($_POST['level_id'] ?? 0);
            $name      = trim($_POST['group_name'] ?? '');
            $judge_id  = !empty($_POST['judge_id'])  ? (int)$_POST['judge_id']  : NULL;
            $school_id = !empty($_POST['school_id']) ? (int)$_POST['school_id'] : NULL;
            if ($level_id > 0 && $name !== '') {
                $stmt = $conn->prepare("INSERT INTO `groups` (level_id, group_name, judge_id, school_id) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('isii', $level_id, $name, $judge_id, $school_id);
                $ok = $stmt->execute();
                $newGroupId = $ok ? $stmt->insert_id : 0;
                $stmt->close();
                // Optional: students dragged straight onto the "+" column
                // land in the new group immediately instead of needing a
                // second drag once the column exists.
                if ($ok && isset($_POST['student_ids']) && is_array($_POST['student_ids'])) {
                    $stmtDel = $conn->prepare("DELETE FROM group_students WHERE student_id=?");
                    $stmtIns = $conn->prepare("INSERT INTO group_students (group_id, student_id, sort_order) VALUES (?, ?, ?)");
                    foreach (array_values($_POST['student_ids']) as $pos => $osid) {
                        $osid = (int)$osid;
                        if ($osid <= 0) continue;
                        $stmtDel->bind_param('i', $osid);
                        $stmtDel->execute();
                        $stmtIns->bind_param('iii', $newGroupId, $osid, $pos);
                        if (!$stmtIns->execute()) { $ok = false; }
                    }
                    $stmtDel->close(); $stmtIns->close();
                }
                $msg = $ok ? 'Kumpulan berjaya ditambah.' : 'Ralat menambah kumpulan. Sila cuba lagi.';
            } else {
                $msg = 'Nama kumpulan diperlukan.';
            }
        } elseif ($action === 'update_group') {
            $gid = (int)($_POST['group_id'] ?? 0);
            $name = trim($_POST['group_name'] ?? '');
            $judge_id = !empty($_POST['judge_id']) ? (int)$_POST['judge_id'] : NULL;
            if ($gid > 0 && $name !== '') {
                $stmt = $conn->prepare("UPDATE `groups` SET group_name=?, judge_id=? WHERE group_id=?");
                $stmt->bind_param('sii', $name, $judge_id, $gid);
                $ok = $stmt->execute();
                $stmt->close();
                $msg = $ok ? 'Kumpulan dikemaskini.' : 'Ralat mengemaskini kumpulan. Sila cuba lagi.';
            } else {
                $msg = 'Nama kumpulan diperlukan.';
            }
        } elseif ($action === 'delete') {
            $gid = (int)($_POST['group_id'] ?? 0);
            $ok = true;
            foreach (["DELETE FROM group_students WHERE group_id=?", "DELETE FROM `groups` WHERE group_id=?"] as $sql) {
                $stmt = $conn->prepare($sql); $stmt->bind_param('i', $gid); if (!$stmt->execute()) { $ok = false; } $stmt->close();
            }
            $msg = $ok ? 'Kumpulan berjaya dipadam.' : 'Ralat memadam kumpulan. Sila cuba lagi.';
        } else {
            $msg = 'Tindakan tidak sah.';
        }
    } catch (Throwable $e) {
        promarkah_report('Caught', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
        $ok  = false;
        $msg = 'Ralat pangkalan data. Sila cuba lagi.';
    }
    echo json_encode(['ok' => $ok, 'msg' => $msg]);
    exit();
}

session_write_close();

// "Semua Siri" (0) is ambiguous across sessions with duplicate names — show a
// Siri picker only in that case so the PIC can narrow down which siri to browse.
$active_siri_id = (int)($_SESSION['active_siri_id'] ?? 0);
$show_siri_picker = $active_siri_id === 0;

// ── AJAX: returns accordion HTML ──────────────────────────────────────────
// Structured as Cawangan > Peringkat, not Sidang > Peringkat > Kumpulan —
// students (not peringkat) are what actually gets assigned to a kumpulan,
// and in practice a kumpulan is split by cawangan within a peringkat, so
// cawangan has to be the thing you navigate by to find "where do I assign
// this student". Each student gets a direct Kumpulan dropdown instead of
// hunting through a per-group checkbox grid to find them.
if (isset($_GET['ajax'])) {
    $filter_group   = $_GET['group_id']   ?? '';
    $filter_session = $_GET['session_id'] ?? '';
    $filter_level   = $_GET['level_id']   ?? '';
    $filter_school  = $_GET['school_id']  ?? '';
    $filter_siri    = $show_siri_picker ? (int)($_GET['siri_id'] ?? 0) : $active_siri_id;
    $filter_search  = trim($_GET['search'] ?? '');

    // Filters against the base `students` scope — reused (merged) for every
    // query below, all rooted at `students` so a cawangan/peringkat with no
    // groups yet still shows (a student's existence doesn't depend on a
    // group already existing for them).
    $stWhereArr = ["1=1"]; $stTypes = ''; $stVals = [];
    if ($filter_level   !== '') { $stWhereArr[] = "st.level_id = ?";  $stTypes .= 'i'; $stVals[] = (int)$filter_level;  }
    if ($filter_school  !== '') { $stWhereArr[] = "st.school_id = ?"; $stTypes .= 'i'; $stVals[] = (int)$filter_school; }
    if ($filter_session !== '') { $stWhereArr[] = "st.level_id IN (SELECT level_id FROM levels WHERE session_id = ?)"; $stTypes .= 'i'; $stVals[] = (int)$filter_session; }
    if ($filter_siri     > 0)    { $stWhereArr[] = "st.level_id IN (SELECT level_id FROM levels WHERE session_id IN (SELECT session_id FROM sessions WHERE siri_id = ?))"; $stTypes .= 'i'; $stVals[] = $filter_siri; }
    if ($filter_group   !== '') { $stWhereArr[] = "st.student_id IN (SELECT student_id FROM group_students WHERE group_id = ?)"; $stTypes .= 'i'; $stVals[] = (int)$filter_group; }
    // Name search — part of the same shared $stWhereArr used to build the
    // cawangan/peringkat lists below, so a search term also hides any
    // cawangan/peringkat that has no matching student, not just the student
    // rows themselves.
    if ($filter_search  !== '') { $stWhereArr[] = "st.student_name LIKE ?"; $stTypes .= 's'; $stVals[] = '%' . $filter_search . '%'; }
    $stWhereSql = implode(" AND ", $stWhereArr);

    $stmt = $conn->prepare("SELECT DISTINCT sc.school_id, sc.school_name FROM students st JOIN schools sc ON st.school_id = sc.school_id WHERE $stWhereSql ORDER BY sc.school_name");
    if ($stTypes) $stmt->bind_param($stTypes, ...$stVals);
    $stmt->execute(); $schools_g = $stmt->get_result(); $stmt->close();

    // Fetched once and reused everywhere below — was previously re-queried for
    // every single group row, which is what made the page lag.
    $judgesList = [];
    $jres = $conn->query("SELECT id, name FROM judges ORDER BY name");
    while ($j = $jres->fetch_assoc()) $judgesList[] = $j;
    $judgeOptionsHtml = "<option value=''>Pilih Juri</option>";
    foreach ($judgesList as $j) {
        $judgeOptionsHtml .= "<option value='{$j['id']}'>" . htmlspecialchars($j['name']) . "</option>";
    }

    $found = false;
    while ($sc = $schools_g->fetch_assoc()) {
        $cur_school_id = $sc['school_id'];
        $found = true;
        echo "<div class='accordion-card'>
                <div class='school-header' onclick=\"toggleBlock('school_grp_{$cur_school_id}')\" role='button' tabindex='0'>
                    <span class='arrow'>▶</span><span>" . htmlspecialchars($sc['school_name']) . "</span>
                </div>
                <div id='school_grp_{$cur_school_id}' style='display:none;padding:12px;'>";

        // Session/siri are pulled in here (not just level_id/level_name) so
        // each peringkat can still show which sidang it belongs to — that
        // used to be obvious for free since Sidang was the outer grouping;
        // now that Cawangan is, it has to be shown explicitly instead.
        $lWhereArr = array_merge($stWhereArr, ["st.school_id=?"]);
        $lTypes    = $stTypes . 'i';
        $lVals     = array_merge($stVals, [$cur_school_id]);
        $lStmt     = $conn->prepare("SELECT DISTINCT l.level_id, l.level_name, s.session_name, si.siri_name FROM students st JOIN levels l ON st.level_id=l.level_id LEFT JOIN sessions s ON l.session_id=s.session_id LEFT JOIN siri si ON s.siri_id=si.siri_id WHERE " . implode(" AND ", $lWhereArr) . " ORDER BY l.sort_order, l.level_name");
        $lStmt->bind_param($lTypes, ...$lVals); $lStmt->execute();
        $levels_g = $lStmt->get_result(); $lStmt->close();

        while ($l = $levels_g->fetch_assoc()) {
            $uid = "grp_lvl_{$cur_school_id}_{$l['level_id']}";

            // A group appears in this cawangan's accordion if:
            //  - it's explicitly tagged school_id = this cawangan (set at
            //    creation via the inline form, or auto-claimed on first
            //    student assignment — see the 'assign' handler above), OR
            //  - it's an untagged legacy group (school_id IS NULL, created
            //    before that column existed) that either already has a
            //    member from this cawangan, or has no members at all yet.
            // A group tagged to (or inferred as belonging to) a DIFFERENT
            // cawangan is excluded, so cawangan can no longer see/pick each
            // other's groups.
            $grpStmt = $conn->prepare(
                "SELECT g.* FROM `groups` g
                 WHERE g.level_id = ?
                   AND (
                       g.school_id = ?
                       OR (
                           g.school_id IS NULL
                           AND (
                               EXISTS (
                                   SELECT 1 FROM group_students gs
                                   JOIN students st2 ON gs.student_id = st2.student_id
                                   WHERE gs.group_id = g.group_id AND st2.school_id = ?
                               )
                               OR NOT EXISTS (
                                   SELECT 1 FROM group_students gs2 WHERE gs2.group_id = g.group_id
                               )
                           )
                       )
                   )
                 ORDER BY g.group_name"
            );
            $grpStmt->bind_param('iii', $l['level_id'], $cur_school_id, $cur_school_id); $grpStmt->execute();
            $groupRows = $grpStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $grpStmt->close();

            // Students for this cawangan + peringkat.
            $lsWhereArr = array_merge($stWhereArr, ["st.school_id=?", "st.level_id=?"]);
            $lsTypes    = $stTypes . 'ii';
            $lsVals     = array_merge($stVals, [$cur_school_id, $l['level_id']]);
            $stStmt = $conn->prepare("SELECT student_id, student_name FROM students st WHERE " . implode(" AND ", $lsWhereArr) . " ORDER BY student_id");
            $stStmt->bind_param($lsTypes, ...$lsVals); $stStmt->execute();
            $cellStudents = $stStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stStmt->close();

            // Current group assignment for those students, restricted to
            // groups in this peringkat (a student can't be in a group from
            // a different peringkat anyway, but the join keeps it explicit).
            $studentToGroup = [];
            $studentOrder   = [];
            if ($groupRows) {
                $gids = array_column($groupRows, 'group_id');
                $inPlaceholders = implode(',', array_fill(0, count($gids), '?'));
                $asStmt = $conn->prepare("SELECT group_id, student_id, sort_order FROM group_students WHERE group_id IN ($inPlaceholders)");
                $asStmt->bind_param(str_repeat('i', count($gids)), ...$gids);
                $asStmt->execute();
                $asRes = $asStmt->get_result();
                while ($r = $asRes->fetch_assoc()) {
                    $studentToGroup[$r['student_id']] = $r['group_id'];
                    $studentOrder[$r['student_id']]   = $r['sort_order'] !== null ? (int)$r['sort_order'] : null;
                }
                $asStmt->close();
            }

            // Sort so students already assigned to a group cluster together
            // by their group's name (making it easy to see each kumpulan's
            // members at a glance), with unassigned students listed last.
            // Within a cluster, the PIC's drag-and-drop order (sort_order)
            // wins — this is the same order judge.php's marking screen
            // reads, so duos/partners land in the same relative spot on
            // both screens instead of drifting back to student_id order.
            $groupNameById = array_column($groupRows, 'group_name', 'group_id');
            usort($cellStudents, function ($a, $b) use ($studentToGroup, $groupNameById, $studentOrder) {
                $gA = $studentToGroup[$a['student_id']] ?? null;
                $gB = $studentToGroup[$b['student_id']] ?? null;
                $nameA = $gA !== null ? ($groupNameById[$gA] ?? '') : null;
                $nameB = $gB !== null ? ($groupNameById[$gB] ?? '') : null;
                if ($nameA === null && $nameB === null) return $a['student_id'] <=> $b['student_id'];
                if ($nameA === null) return 1;
                if ($nameB === null) return -1;
                $cmp = strcasecmp($nameA, $nameB);
                if ($cmp !== 0) return $cmp;
                $oA = $studentOrder[$a['student_id']] ?? null;
                $oB = $studentOrder[$b['student_id']] ?? null;
                if ($oA === null && $oB === null) return $a['student_id'] <=> $b['student_id'];
                if ($oA === null) return 1;
                if ($oB === null) return -1;
                return $oA <=> $oB;
            });

            $sidangLabel = $l['session_name'] ? htmlspecialchars($l['session_name']) : 'Tiada Sidang';
            $siriTagLabel = $l['siri_name'] ? htmlspecialchars($l['siri_name']) : 'Tiada Siri';

            // Bucket students by their current group (0 = unassigned),
            // preserving the sort order already computed above — this is
            // what turns into each kanban column's card list below.
            $membersByGroup = [];
            foreach ($cellStudents as $st) {
                $gid = $studentToGroup[$st['student_id']] ?? 0;
                $membersByGroup[$gid][] = $st;
            }

            echo "<div class='accordion-sub-card' id='card_$uid'>
                    <div class='accordion-sub-header' onclick=\"toggleBlock('$uid')\" role='button' tabindex='0'>
                        <span class='arrow'>▶</span><span>" . htmlspecialchars($l['level_name']) . "</span>
                        <span class='siri-tag' title='Sidang / Siri'>{$sidangLabel} &middot; {$siriTagLabel}</span>
                    </div>
                    <div id='$uid' style='display:none;'>
                        <div class='kanban-board' id='board_$uid' data-level-id='{$l['level_id']}' data-school-id='{$cur_school_id}'>";

            // ── Unassigned pool — always present, never deletable, no judge. ──
            $unassigned = $membersByGroup[0] ?? [];
            echo "  <div class='kanban-col kanban-col-unassigned'>
                        <div class='kanban-col-header'>
                            <span class='kanban-col-title'>Belum Diagihkan</span>
                            <span class='kanban-col-count'>" . count($unassigned) . "</span>
                        </div>
                        <div class='kanban-col-body' data-group-id='0'>";
            foreach ($unassigned as $st) {
                echo "<div class='kanban-card' draggable='true' data-sid='{$st['student_id']}'>" . htmlspecialchars($st['student_name']) . "</div>";
            }
            if (!$unassigned) {
                echo "<div class='kanban-empty-hint'>Seret pelajar ke sini</div>";
            }
            echo "      </div>
                    </div>";

            // ── One column per kumpulan — name (inline-editable), judge
            // picker, and delete button live right on the column header;
            // dragging a student card in/out/between columns (or onto the
            // unassigned pool) is the entire "assign" interaction, saved
            // the instant it's dropped. No separate management table, no
            // separate save step. ──
            foreach ($groupRows as $g) {
                $gid = $g['group_id'];
                $members = $membersByGroup[$gid] ?? [];
                $judgeOptions = str_replace(
                    "value='{$g['judge_id']}'",
                    "value='{$g['judge_id']}' selected",
                    $judgeOptionsHtml
                );
                $gName = htmlspecialchars($g['group_name']);
                echo "  <div class='kanban-col' data-group-id='$gid'>
                            <div class='kanban-col-header'>
                                <input type='text' class='kanban-group-name' value='$gName' data-group-id='$gid' data-orig='$gName' maxlength='100'>
                                <button type='button' class='kanban-col-delete' data-group-id='$gid' data-group-name='$gName' title='Padam Kumpulan'>Padam</button>
                            </div>
                            <div class='kanban-col-judge'>
                                <span class='kanban-judge-label'>Juri</span>
                                <select class='kanban-judge-select' data-group-id='$gid' data-orig='{$g['judge_id']}'>$judgeOptions</select>
                            </div>
                            <div class='kanban-col-body' data-group-id='$gid'>";
                foreach ($members as $st) {
                    echo "<div class='kanban-card' draggable='true' data-sid='{$st['student_id']}'>" . htmlspecialchars($st['student_name']) . "</div>";
                }
                if (!$members) {
                    echo "<div class='kanban-empty-hint'>Seret pelajar ke sini</div>";
                }
                echo "      </div>
                        </div>";
            }

            // ── Add-column tile — creating a kumpulan happens right on the
            // board it belongs to, no detour to a separate form/page.
            // Also a valid drop target: dragging a student card straight
            // onto it opens this same form with that student pending, so
            // "make a new group for this kid" is one drag instead of
            // create-group-then-drag-again. ──
            echo "  <div class='kanban-col kanban-col-add'>
                        <button type='button' class='kanban-add-btn' onclick=\"showAddColumnForm('$uid')\">+ Kumpulan Baharu</button>
                        <div class='kanban-add-form' id='addColForm_$uid' style='display:none;'>
                            <div class='kanban-add-pending-hint' id='addColPendingHint_$uid'></div>
                            <input type='text' id='addColName_$uid' placeholder='Nama Kumpulan' maxlength='100'>
                            <select id='addColJudge_$uid'>$judgeOptionsHtml</select>
                            <div class='kanban-add-form-btns'>
                                <button type='button' class='pm-btn pm-btn-primary btn-sm' onclick=\"confirmAddColumn('$uid')\">Buat</button>
                                <button type='button' class='pm-btn btn-sm' style='background:transparent;border:1px solid var(--c-border-strong);color:var(--c-text-faint);' onclick=\"cancelAddColumn('$uid')\">Batal</button>
                            </div>
                        </div>
                    </div>
                    </div></div></div>";
        }
        echo "</div></div>";
    }
    if (!$found) echo "<p style='text-align:center;padding:30px;color:var(--c-text-faint);'>Tiada pelajar ditemui.</p>";
    exit();
}

$pm_page = 'groups';
include 'layout.php';
?>

<?php
$pm_pg_css_v = @filemtime(__DIR__ . '/pic_groups.css') ?: time();
?>
<link rel="stylesheet" href="pic_groups.css?v=<?= $pm_pg_css_v ?>">

<div class='pic-section-header'>
    <div>
        <h2>👥 Kumpulan Juri</h2>
        <div class='pic-section-sub'>Tetapkan juri kepada kumpulan pelajar mengikut peringkat</div>
    </div>
</div>

<!-- Filters (instant AJAX on change) -->
<div class='filter-card' style='margin-bottom:18px;'>
    <div class='filter-card-title'>Penapis Kumpulan</div>
    <div class='filter-grid'>
        <?php if ($show_siri_picker): ?>
        <div>
            <label class='filter-label'>Siri</label>
            <div class="dd-wrap" id="ddWrap_siri">
                <div class="dd-trigger" id="ddTrigger_siri" onclick="ddToggle('siri')" role="button" tabindex="0" aria-haspopup="listbox">
                    <span id="ddLabel_siri" style="color:var(--c-text-faint);">Semua Siri</span>
                    <span class="dd-arrow">▼</span>
                </div>
                <div class="dd-panel" id="ddPanel_siri" role="listbox">
                    <div class="dd-search-box">
                        <input type="text" placeholder="Cari siri..." oninput="ddFilter('siri',this.value)" onclick="event.stopPropagation()">
                    </div>
                    <div class="dd-options" id="ddOpts_siri">
                        <div class="dd-opt selected" data-value="" role="option" tabindex="0" onclick="ddSelectSiri('','Semua Siri')">Semua Siri</div>
                        <?php
                        $siris = $conn->query("SELECT siri_id, siri_name, siri_year FROM siri ORDER BY siri_year DESC, siri_name ASC");
                        while ($si = $siris->fetch_assoc()) {
                            $sid_ = $si['siri_id'];
                            $slabel = htmlspecialchars($si['siri_name']) . " (" . htmlspecialchars($si['siri_year']) . ")";
                            echo "<div class='dd-opt' role='option' tabindex='0' data-value='{$sid_}' onclick=\"ddSelectSiri('{$sid_}','{$slabel}')\">{$slabel}</div>";
                        }
                        ?>
                    </div>
                    <div class="dd-empty" id="ddEmpty_siri">Tiada hasil</div>
                </div>
                <input type="hidden" id="f_siri" value="">
            </div>
        </div>
        <?php endif; ?>
        <div>
            <label class='filter-label'>Kumpulan</label>
            <div class="dd-wrap" id="ddWrap_group">
                <div class="dd-trigger" id="ddTrigger_group" onclick="ddToggle('group')" role="button" tabindex="0" aria-haspopup="listbox">
                    <span id="ddLabel_group" style="color:var(--c-text-faint);">Semua Kumpulan</span>
                    <span class="dd-arrow">▼</span>
                </div>
                <div class="dd-panel" id="ddPanel_group" role="listbox">
                    <div class="dd-search-box">
                        <input type="text" placeholder="Cari kumpulan..." oninput="ddFilter('group',this.value)" onclick="event.stopPropagation()">
                    </div>
                    <div class="dd-options" id="ddOpts_group">
                        <div class="dd-opt selected" data-value="" role="option" tabindex="0" onclick="ddSelect('group','','Semua Kumpulan')">Semua Kumpulan</div>
                        <?php
                        // Tag each group with its sidang (and siri, when "Semua Siri" is
                        // active) since group names can repeat across sessions/siri. Also
                        // tag with its level_id (data-level) so selecting a Peringkat can
                        // narrow this list client-side — this whole option list is
                        // rendered once on page load (ajaxFilter() only ever refreshes the
                        // results panel, not the filter dropdowns), so scoping it has to
                        // happen in JS on Peringkat change, not via a SQL WHERE.
                        $grps = $conn->query("SELECT g.group_id, g.group_name, g.level_id, s.session_name, si.siri_name FROM `groups` g LEFT JOIN levels l ON g.level_id = l.level_id LEFT JOIN sessions s ON l.session_id = s.session_id LEFT JOIN siri si ON s.siri_id = si.siri_id ORDER BY g.group_name");
                        while ($g = $grps->fetch_assoc()) {
                            $gid_ = $g['group_id'];
                            $optLabel = $g['group_name'];
                            $ctx = array_filter([$g['session_name'], $show_siri_picker ? $g['siri_name'] : null]);
                            if ($ctx) $optLabel .= " — " . implode(' / ', $ctx);
                            $optLabel = htmlspecialchars($optLabel);
                            $glid_ = (int)$g['level_id'];
                            echo "<div class='dd-opt' role='option' tabindex='0' data-level='{$glid_}' onclick=\"ddSelect('group','{$gid_}','{$optLabel}')\">{$optLabel}</div>";
                        }
                        ?>
                    </div>
                    <div class="dd-empty" id="ddEmpty_group">Tiada hasil</div>
                </div>
                <input type="hidden" id="f_group" value="">
            </div>
        </div>
        <div>
            <label class='filter-label'>Sidang</label>
            <div class="dd-wrap" id="ddWrap_session">
                <div class="dd-trigger" id="ddTrigger_session" onclick="ddToggle('session')" role="button" tabindex="0" aria-haspopup="listbox">
                    <span id="ddLabel_session" style="color:var(--c-text-faint);">Semua Sidang</span>
                    <span class="dd-arrow">▼</span>
                </div>
                <div class="dd-panel" id="ddPanel_session" role="listbox">
                    <div class="dd-search-box">
                        <input type="text" placeholder="Cari sidang..." oninput="ddFilter('session',this.value)" onclick="event.stopPropagation()">
                    </div>
                    <div class="dd-options" id="ddOpts_session">
                        <div class="dd-opt selected" data-value="" role="option" tabindex="0" onclick="ddSelect('session','','Semua Sidang')">Semua Sidang</div>
                        <?php
                        $sessions = $conn->query("SELECT se.*, si.siri_name FROM sessions se LEFT JOIN siri si ON se.siri_id = si.siri_id ORDER BY se.session_name");
                        while ($s=$sessions->fetch_assoc()) {
                            $siriLbl = $s['siri_name'] ? $s['siri_name'] : 'Tiada Siri';
                            $sid2 = $s['session_id'];
                            $slabel2 = htmlspecialchars($s['session_name']) . " — " . htmlspecialchars($siriLbl);
                            echo "<div class='dd-opt' role='option' tabindex='0' data-value='{$sid2}' data-siri='{$s['siri_id']}' onclick=\"ddSelect('session','{$sid2}','{$slabel2}')\">{$slabel2}</div>";
                        }
                        ?>
                    </div>
                    <div class="dd-empty" id="ddEmpty_session">Tiada hasil</div>
                </div>
                <input type="hidden" id="f_session" value="">
            </div>
        </div>
        <div>
            <label class='filter-label'>Peringkat</label>
            <div class="dd-wrap" id="ddWrap_level">
                <div class="dd-trigger" id="ddTrigger_level" onclick="ddToggle('level')" role="button" tabindex="0" aria-haspopup="listbox">
                    <span id="ddLabel_level" style="color:var(--c-text-faint);">Semua Peringkat</span>
                    <span class="dd-arrow">▼</span>
                </div>
                <div class="dd-panel" id="ddPanel_level" role="listbox">
                    <div class="dd-search-box">
                        <input type="text" placeholder="Cari peringkat..." oninput="ddFilter('level',this.value)" onclick="event.stopPropagation()">
                    </div>
                    <div class="dd-options" id="ddOpts_level">
                        <div class="dd-opt selected" data-value="" role="option" tabindex="0" onclick="ddSelectLevel('','Semua Peringkat')">Semua Peringkat</div>
                        <?php
                        // A Peringkat with no Kumpulan under it is a dead end — nothing to
                        // narrow down to next, nothing to ever show in results — so it's
                        // excluded here the same way pic_directory.php's level list is.
                        $levelHasGroupSql = "EXISTS (SELECT 1 FROM `groups` lg WHERE lg.level_id = l.level_id)";
                        $lvls = $active_siri_id > 0
                            ? $conn->query("SELECT l.* FROM levels l JOIN sessions s ON l.session_id = s.session_id WHERE s.siri_id = $active_siri_id AND $levelHasGroupSql ORDER BY l.level_name")
                            : $conn->query("SELECT l.*, si.siri_name FROM levels l LEFT JOIN sessions s ON l.session_id = s.session_id LEFT JOIN siri si ON s.siri_id = si.siri_id WHERE $levelHasGroupSql ORDER BY l.level_name");
                        while ($l = $lvls->fetch_assoc()) {
                            $lid_ = $l['level_id'];
                            $optLabel2 = $l['level_name'];
                            if ($active_siri_id === 0 && !empty($l['siri_name'])) {
                                $optLabel2 .= " — " . $l['siri_name'];
                            }
                            $optLabel2 = htmlspecialchars($optLabel2);
                            echo "<div class='dd-opt' role='option' tabindex='0' data-value='{$lid_}' onclick=\"ddSelectLevel('{$lid_}','{$optLabel2}')\">{$optLabel2}</div>";
                        }
                        ?>
                    </div>
                    <div class="dd-empty" id="ddEmpty_level">Tiada hasil</div>
                </div>
                <input type="hidden" id="f_level" value="">
            </div>
        </div>
        <div>
            <label class='filter-label'>Cawangan</label>
            <div class="dd-wrap" id="ddWrap_school">
                <div class="dd-trigger" id="ddTrigger_school" onclick="ddToggle('school')" role="button" tabindex="0" aria-haspopup="listbox">
                    <span id="ddLabel_school" style="color:var(--c-text-faint);">Semua Cawangan</span>
                    <span class="dd-arrow">▼</span>
                </div>
                <div class="dd-panel" id="ddPanel_school" role="listbox">
                    <div class="dd-search-box">
                        <input type="text" placeholder="Cari cawangan..." oninput="ddFilter('school',this.value)" onclick="event.stopPropagation()">
                    </div>
                    <div class="dd-options" id="ddOpts_school">
                        <div class="dd-opt selected" data-value="" role="option" tabindex="0" onclick="ddSelect('school','','Semua Cawangan')">Semua Cawangan</div>
                        <?php
                        $schools_f = $conn->query("SELECT school_id, school_name FROM schools ORDER BY school_name");
                        while ($sc_f = $schools_f->fetch_assoc()) {
                            $scid_ = $sc_f['school_id'];
                            $sclabel = htmlspecialchars($sc_f['school_name']);
                            echo "<div class='dd-opt' role='option' tabindex='0' data-value='{$scid_}' onclick=\"ddSelect('school','{$scid_}','{$sclabel}')\">{$sclabel}</div>";
                        }
                        ?>
                    </div>
                    <div class="dd-empty" id="ddEmpty_school">Tiada hasil</div>
                </div>
                <input type="hidden" id="f_school" value="">
            </div>
        </div>
        <div>
            <label class='filter-label'>Cari Nama Pelajar</label>
            <input type="text" id="f_search" class="filter-select" placeholder="Taip nama pelajar..." oninput="onSearchInput()" autocomplete="off">
        </div>
    </div>
</div>

<div id='groupList'><p style='text-align:center;padding:30px;color:var(--c-text-faint);'>Memuatkan...</p></div>

<div class="vm-pagination" id="groupsPaginationContainer" style="display:none;">
    <div class="vm-page-info" id="groupsPageInfo"></div>
    <div class="vm-page-btns" id="groupsPaginationButtons"></div>
</div>

<?php
// Bootstrap for pic_groups.js — see that file's top comment for why this
// stays a plain const rather than a window.* assignment.
$pm_pg_js_v = @filemtime(__DIR__ . '/pic_groups.js') ?: time();
?>
<script>
const PM_GROUPS_CSRF = <?= json_encode($csrf) ?>;
</script>
<script src="pic_groups.js?v=<?= $pm_pg_js_v ?>"></script>

</main>
</body>
</html>
