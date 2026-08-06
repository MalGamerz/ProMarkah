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
                <div class='school-header' onclick=\"toggleBlock('school_grp_{$cur_school_id}')\">
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
                    <div class='accordion-sub-header' onclick=\"toggleBlock('$uid')\">
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

<style>
    .accordion-card {
         background:var(--c-surface-1);
         border:1px solid var(--c-border);
         border-radius:10px;
         margin-bottom:12px;
         overflow:hidden;
    }
     .school-header {
         display:flex;
         align-items:center;
         gap:10px;
         padding:12px 16px;
         background:var(--c-surface-1);
         border:1px solid var(--c-border);
         border-radius:8px;
         cursor:pointer;
         color:var(--c-white);
         font-weight:600;
         font-size:0.92rem;
         text-decoration:none !important;
         user-select:none;
    }
     .school-header:hover { text-decoration:none !important; }
     .school-header .arrow {
         color:var(--c-red);
         font-size:0.9rem;
         display:inline-block;
         transition:transform .2s ease;
         text-decoration:none !important;
    }
     .school-header .siri-tag,
     .accordion-sub-header .siri-tag {
         font-size:0.7rem;
         font-weight:600;
         text-transform:uppercase;
         letter-spacing:0.05em;
         color:var(--c-text-faint);
         background:rgba(214, 40, 40, 0.08);
         border:1px solid var(--c-red-border);
         padding:2px 8px;
         border-radius:10px;
         white-space:nowrap;
    }
     .school-header .siri-tag { margin-left:auto; }
     html.pm-light .school-header .siri-tag,
     html.pm-light .accordion-sub-header .siri-tag {
         background:#fdecec;
         color:var(--c-red-700);
         border-color:var(--c-red-300);
    }
     .accordion-sub-card {
         background:var(--c-surface-0);
         border:1px solid var(--c-border);
         border-left:3px solid var(--c-red);
         border-radius:8px;
         margin-bottom:12px;
         /* No `overflow:hidden` here — a sticky descendant (the peringkat
            header) computes its "stuck" offset relative to the nearest
            ancestor whose overflow isn't `visible`, so clipping this box
            was silently turning it into that reference frame instead of
            the page, breaking the sticky positioning and clipping/garbling
            the header against the table underneath it. */
    }
    .accordion-sub-header {
         display:flex;
         align-items:center;
         gap:10px;
         padding:10px 14px;
         cursor:pointer;
         color:var(--c-white);
         font-weight:600;
         font-size:0.88rem;
         transition:background .15s;
         user-select:none;
         flex-wrap:wrap;
         text-decoration:none !important;
         /* Stays pinned under the fixed page header while its student list
            scrolls underneath, so the Simpan button (and which peringkat
            you're editing) is always in view instead of scrolling away. */
         position:sticky;
         top:var(--header-h);
         z-index:20;
         background:var(--c-surface-0);
    }
     @media (max-width: 600px) {
         .accordion-sub-header { gap:6px; padding:8px 10px; }
         .accordion-sub-header > div[style*='margin-left:auto'] { margin-left:0 !important; width:100%; justify-content:flex-end; }
     }
     .accordion-sub-header:hover {
         background:var(--c-surface-1);
         text-decoration:none !important;
    }
     .accordion-sub-header .arrow {
         color:var(--c-red);
         font-size:0.8rem;
         display:inline-block;
         transition:transform .2s ease;
         text-decoration:none !important;
    }
     .filter-select {
         background:var(--c-surface-2);
         border:1px solid var(--c-border-strong);
         color:var(--c-white);
         border-radius:6px;
         padding:8px 10px;
         font-size:0.875rem;
         outline:none;
         width:100%;
         box-sizing:border-box;
         cursor:pointer;
         transition:border-color .2s;
    }
     .filter-select:focus {
         border-color:var(--c-red);
    }
     .filter-select option {
         background:var(--c-surface-2);
         color:var(--c-white);
    }
     /* Nama Pelajar search box — matches the dd-trigger dropdowns' REAL
        rendered box model. dashboard.css's shared .dd-trigger rule sets
        height:32px !important / padding:0 10px !important / font-size:.8rem
        !important, which beats this page's own local .dd-trigger{height:38px}
        rule outright (an !important rule always wins over a non-important
        one regardless of specificity or source order) — so 32px, not 38px,
        is the actual live height every dropdown renders at. This rule needs
        !important too, otherwise it loses the same way; #f_search's higher
        specificity (ID vs class) then wins the tie inside the !important tier. */
     #f_search {
         -webkit-appearance:none !important;
         appearance:none !important;
         margin:0 !important;
         height:32px !important;
         line-height:normal !important;
         padding:0 10px !important;
         font-size:.8rem !important;
         font-family:inherit !important;
         cursor:text !important;
         display:block !important;
    }
     #f_search:focus {
         border-color:var(--c-red) !important;
         box-shadow:0 0 0 3px var(--c-red-dim) !important;
    }
     .filter-label {
         display:block;
         font-size:0.75rem;
         font-weight:600;
         text-transform:uppercase;
         color:var(--c-text-faint);
         margin-bottom:4px;
    }
     .filter-grid {
         display:grid;
         grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
         gap:12px;
         align-items:end;
    }
     .filter-card {
         background:var(--c-surface-1);
         border:1px solid var(--c-border-strong);
         border-radius:12px;
         padding:20px;
         margin-bottom:24px;
         box-shadow:0 4px 12px rgba(0,0,0,.08);
    }
     .filter-card-title {
         font-size:.72rem;
         font-weight:700;
         text-transform:uppercase;
         letter-spacing:.12em;
         color:var(--c-text-faint);
         margin-bottom:16px;
    }
     html.pm-light .filter-card {
         background: #fff;
         border-color: var(--c-gray-200);
         box-shadow: 0 1px 4px rgba(0,0,0,0.06);
    }
     html.pm-light .filter-card-title {
         color: #888;
    }
    /* ── Light mode fixes ── */
     html.pm-light .pic-section-header h2 {
         color: #111;
    }
     html.pm-light .pic-section-sub {
         color: #555;
    }
     html.pm-light .card {
         background: #fff;
         border-color: var(--c-gray-200) !important;
    }
     html.pm-light .filter-label {
         color: #555;
    }
     html.pm-light .filter-select {
         background: var(--c-gray-50);
         border-color: var(--c-gray-300);
         color: #111;
    }
     html.pm-light .filter-select option {
         background: #fff;
         color: #111;
    }
     html.pm-light .accordion-card {
         background: #fff;
         border-color: var(--c-gray-200);
    }
     html.pm-light .school-header {
         background: var(--c-gray-50);
         border-color: var(--c-gray-200);
         color: #111;
    }
     html.pm-light .accordion-sub-card {
         background: #fff;
         border-color: var(--c-gray-200);
    }
     html.pm-light .accordion-sub-header {
         color: #111;
         background: #fff;
    }
     html.pm-light .accordion-sub-header:hover {
         background: var(--c-gray-100);
    }
    @media (max-width: 600px) {
         .school-header { font-size:0.82rem; padding:10px 12px; }
         .accordion-card > div[style*='padding:12px'] { padding:8px !important; }
         .accordion-sub-card { margin-bottom:8px; }
         .pic-section-header { gap:8px; }
         .pic-section-header h2 { font-size:1.4rem; }
         .filter-grid { grid-template-columns:1fr; }
     }

    /* ── PAGINATION (matches pic_view_marks.php exactly) ── */
    .vm-pagination {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 14px;
        padding-top: 16px;
        border-top: 1px solid var(--c-border);
    }
    .vm-page-info {
        font-size: 0.8rem;
        color: var(--c-text-faint);
        font-weight: 500;
    }
    .vm-page-btns {
        display: flex;
        align-items: center;
        gap: 4px;
        flex-wrap: wrap;
    }
    .vm-page-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 34px;
        height: 34px;
        padding: 0 10px;
        border-radius: 6px;
        font-size: 0.82rem;
        font-weight: 600;
        color: var(--c-text-muted);
        background: var(--c-surface-2);
        border: 1px solid var(--c-border-strong);
        text-decoration: none;
        cursor: pointer;
        transition: all 0.15s;
        white-space: nowrap;
    }
    .vm-page-btn:hover:not(:disabled) {
        color: #fff;
        border-color: var(--c-red);
        background: var(--c-red-dim);
    }
    .vm-page-btn.vm-page-active {
        background: var(--c-red);
        border-color: var(--c-red);
        color: #fff;
        cursor: default;
        pointer-events: none;
    }
    .vm-page-btn:disabled { opacity: 0.4; cursor: not-allowed; }
    .vm-page-ellipsis {
        display: inline-flex;
        align-items: center;
        height: 34px;
        color: var(--c-text-faint);
        font-size: 0.85rem;
        padding: 0 4px;
    }
    html.pm-light .vm-page-info { color: var(--c-gray-500); }
    html.pm-light .vm-page-btn { background: #ffffff; border-color: var(--c-gray-300); color: var(--c-gray-700); }
    html.pm-light .vm-page-btn:hover:not(:disabled) { background: var(--c-red-100); border-color: var(--c-orange-accent); color: var(--c-red-700); }
    html.pm-light .vm-page-btn.vm-page-active { background: var(--c-orange-accent); border-color: var(--c-orange-accent); color: #ffffff; }
    html.pm-light .vm-page-ellipsis { color: var(--c-gray-400); }
    html.pm-light .vm-pagination { border-top-color: var(--c-gray-200); }
    @media (max-width: 640px) {
        .vm-pagination { flex-direction: column; align-items: flex-start; }
        .vm-page-btns { width: 100%; }
    }

    /* ── Searchable dropdown (avoids native <select> popup — Chromium/GPU
       renders the OS listbox solid black for a moment before painting) ── */
    .dd-wrap { position: relative; }
    .dd-trigger{
        display:flex;
        align-items:center;
        justify-content:space-between;
        height:38px;
        box-sizing:border-box;
        width:100%;
        padding:0 10px;
        background:var(--c-surface-2);
        border:1px solid var(--c-border-strong);
        border-radius:6px;
        color:var(--c-white);
        font-size:.875rem;
        cursor:pointer;
        user-select:none;
        transition: border-color .2s, box-shadow .2s, background .2s;
    }
    .dd-trigger:hover{ border-color:var(--c-red); }
    .dd-trigger.open{ border-color:var(--c-red); box-shadow:0 0 0 3px var(--c-red-dim); }
    .dd-trigger .dd-arrow { color: var(--c-text-faint); font-size: 0.7rem; transition: transform .2s; }
    .dd-trigger.open .dd-arrow { transform: rotate(180deg); }

    .dd-panel{
        display:none;
        position:absolute;
        top:calc(100% + 6px);
        left:0;
        right:0;
        background:var(--c-surface-2);
        border:1px solid var(--c-red);
        border-radius:8px;
        overflow:hidden;
        z-index:999;
        box-shadow:0 10px 30px rgba(0,0,0,.25);
    }
    .dd-panel.open{ display:block; }
    .dd-search-box { padding: 8px; border-bottom: 1px solid var(--c-border-strong); }
    .dd-search-box input {
        width: 100%; background: var(--c-surface-0); border: 1px solid var(--c-border-strong);
        color: var(--c-white); border-radius: 4px; padding: 6px 8px; font-size: 0.8rem;
        outline: none; box-sizing: border-box; transition: border-color .2s;
    }
    .dd-search-box input:focus { border-color: var(--c-red); }
    .dd-search-box input::placeholder { color: var(--c-text-faint); }
    .dd-options { max-height: 220px; overflow-y: auto; scrollbar-width: thin; }
    .dd-opt { padding: 9px 12px; font-size: 0.875rem; color: var(--c-white); cursor: pointer; transition: background .1s; }
    .dd-opt:hover { background: var(--c-surface-3); }
    .dd-opt.selected { color: var(--c-red); font-weight: 600; }
    .dd-opt.hidden { display: none; }
    .dd-empty { padding: 10px 12px; color: var(--c-text-faint); font-size: 0.82rem; display: none; text-align: center; }

    html.pm-light .dd-trigger { background: var(--c-gray-50); border-color: var(--c-gray-300); color: #111; }
    html.pm-light .dd-panel { background: #fff; box-shadow: 0 6px 20px rgba(0,0,0,0.12); }
    html.pm-light .dd-search-box { border-bottom-color: var(--c-gray-200); }
    html.pm-light .dd-search-box input { background: var(--c-gray-50); border-color: var(--c-gray-300); color: #111; }
    html.pm-light .dd-opt { color: #111; }
    html.pm-light .dd-opt:hover { background: var(--c-gray-100); }

    /* ── Kanban board (Kumpulan Juri assignment) ──
       Columns = kumpulan (+ a permanent "Belum Diagihkan" pool and a
       trailing "+ Kumpulan Baharu" tile); cards = students. Dragging a
       card between columns IS the assign action, saved the instant it's
       dropped — no separate save button anywhere on this board. */
    .kanban-board {
        display: flex;
        gap: 12px;
        overflow-x: auto;
        padding: 14px;
        align-items: flex-start;
        scrollbar-width: thin;
    }
    .kanban-col {
        flex: 0 0 240px;
        width: 240px;
        background: var(--c-surface-1);
        border: 1px solid var(--c-border-strong);
        border-radius: 10px;
        display: flex;
        flex-direction: column;
        max-height: 480px;
    }
    .kanban-col-unassigned { border-style: dashed; }
    .kanban-col-header {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 10px 10px 6px;
    }
    .kanban-col-title {
        font-size: 0.82rem;
        font-weight: 700;
        color: var(--c-text-muted);
        flex: 1;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .kanban-col-count {
        font-size: 0.72rem;
        font-weight: 700;
        color: var(--c-text-faint);
        background: var(--c-surface-0);
        border-radius: 10px;
        padding: 1px 8px;
    }
    .kanban-group-name {
        flex: 1;
        min-width: 0;
        background: transparent;
        border: 1px solid transparent;
        color: var(--c-white);
        font-size: 0.85rem;
        font-weight: 700;
        padding: 4px 6px;
        border-radius: 5px;
        outline: none;
    }
    .kanban-group-name:hover { border-color: var(--c-border-strong); }
    .kanban-group-name:focus { border-color: var(--c-red); background: var(--c-surface-0); }
    /* A bare trash-emoji icon (even boxed) still read as decorative and
       got missed twice — a red-outlined text label is what this app
       already uses for every other destructive action (.pm-btn-danger:
       red text/border at rest, not just revealed on hover), so this
       matches that instead of inventing a quieter one-off. */
    .kanban-col-delete {
        background: transparent;
        border: 1px solid var(--c-red-border);
        color: var(--c-red);
        cursor: pointer;
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        padding: 6px 9px;
        border-radius: 6px;
        line-height: 1;
        flex-shrink: 0;
        white-space: nowrap;
    }
    .kanban-col-delete:hover { background: var(--c-red-dim); border-color: var(--c-red); }
    /* A distinct tinted band (small uppercase label + red-tinted
       background, matching the app's .grp-manage-title label language) so
       the judge picker reads as a clearly different kind of control from
       the plain student cards below it, instead of blending into the list
       as if it were just another card. */
    .kanban-col-judge {
        padding: 6px 10px 8px;
        background: var(--c-red-dim);
        border-bottom: 1px solid var(--c-border);
    }
    .kanban-judge-label {
        display: block;
        font-size: 0.62rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--c-red);
        margin-bottom: 4px;
    }
    /* Matches the site's established native-<select> benchmark (32px /
       6px radius / .8rem — same one .pm-select and the dd-wrap filters use)
       instead of a bespoke cramped size, plus a custom chevron since the
       bare OS arrow reads as noticeably unpolished at this size. */
    .kanban-judge-select {
        width: 100%;
        height: 32px;
        box-sizing: border-box;
        background: var(--c-surface-0);
        border: 1px solid var(--c-border-strong);
        color: var(--c-text);
        border-radius: 6px;
        padding: 0 22px 0 8px;
        font-size: 0.78rem;
        font-family: inherit;
        outline: none;
        cursor: pointer;
        -webkit-appearance: none;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 8'%3E%3Cpath fill='%23888' d='M6 8 0 0h12z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 7px center;
        background-size: 9px 6px;
        transition: border-color .2s, box-shadow .2s;
    }
    .kanban-judge-select:hover { border-color: var(--c-red); }
    .kanban-judge-select:focus { border-color: var(--c-red); box-shadow: 0 0 0 3px var(--c-red-dim); }
    .kanban-judge-select option { background: var(--c-surface-2); color: var(--c-text); }
    .kanban-col-body {
        flex: 1;
        min-height: 60px;
        overflow-y: auto;
        padding: 4px 8px 10px;
        display: flex;
        flex-direction: column;
        gap: 6px;
        scrollbar-width: thin;
    }
    .kanban-col-body.kanban-drag-over { background: var(--c-red-dim); border-radius: 6px; }
    .kanban-card {
        background: var(--c-surface-2);
        border: 1px solid var(--c-border-strong);
        border-radius: 6px;
        padding: 8px 10px;
        font-size: 0.82rem;
        font-weight: 600;
        color: var(--c-white);
        cursor: grab;
        user-select: none;
        transition: opacity .15s, box-shadow .15s;
    }
    .kanban-card:hover { border-color: var(--c-red); }
    .kanban-card.kanban-dragging { opacity: 0.35; }
    .kanban-card.kanban-saving { opacity: 0.55; cursor: wait; }
    .kanban-card.kanban-error { border-color: var(--c-red); box-shadow: 0 0 0 2px var(--c-red-dim); }
    /* Quiet per-card "this move actually saved" confirmation — a brief
       pulse rather than a color (this app's palette repurposes green to
       grayscale, so a color change alone wouldn't read as "success"). */
    .kanban-card.kanban-saved { animation: kanban-saved-pulse .9s ease; }
    @keyframes kanban-saved-pulse {
        0%   { box-shadow: 0 0 0 2px var(--c-red-dim); }
        100% { box-shadow: 0 0 0 2px transparent; }
    }
    .kanban-empty-hint {
        font-size: 0.74rem;
        color: var(--c-text-faint);
        text-align: center;
        padding: 14px 6px;
        border: 1px dashed var(--c-border-strong);
        border-radius: 6px;
    }
    .kanban-col-add {
        background: transparent;
        border: 1px dashed var(--c-border-strong);
        align-items: stretch;
        justify-content: flex-start;
        padding: 10px;
    }
    /* Dropping a student card here is a valid action (creates the group
       and assigns them in one go) — the same red highlight the real
       columns use on dragover, so it reads as an equally valid target
       instead of looking like the drag has nowhere to go. */
    .kanban-col-add.kanban-drag-over { background: var(--c-red-dim); border-color: var(--c-red); }
    .kanban-add-pending-hint {
        font-size: 0.7rem;
        color: var(--c-red);
        font-weight: 600;
    }
    .kanban-add-pending-hint:empty { display: none; }
    .kanban-add-btn {
        background: transparent;
        border: none;
        color: var(--c-text-faint);
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        padding: 8px;
        border-radius: 6px;
        text-align: left;
    }
    .kanban-add-btn:hover { color: var(--c-red); background: var(--c-red-dim); }
    .kanban-add-form { display: flex; flex-direction: column; gap: 8px; }
    .kanban-add-form input, .kanban-add-form select {
        background: var(--c-surface-2);
        border: 1px solid var(--c-border-strong);
        color: var(--c-white);
        border-radius: 6px;
        padding: 6px 8px;
        font-size: 0.8rem;
        outline: none;
    }
    .kanban-add-form input:focus, .kanban-add-form select:focus { border-color: var(--c-red); }
    .kanban-add-form-btns { display: flex; gap: 6px; }

    /* No separate html.pm-light overrides needed here — every kanban rule
       above uses the semantic --c-surface-*/--c-text*/--c-border* tokens,
       which html.pm-light already redefines site-wide (see dashboard.css),
       so light mode falls out automatically. */

    @media (max-width: 600px) {
        .kanban-col { flex-basis: 200px; width: 200px; max-height: 380px; }
        .kanban-board { padding: 10px; gap: 8px; }
    }
</style>

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
                <div class="dd-trigger" id="ddTrigger_siri" onclick="ddToggle('siri')">
                    <span id="ddLabel_siri" style="color:var(--c-text-faint);">Semua Siri</span>
                    <span class="dd-arrow">▼</span>
                </div>
                <div class="dd-panel" id="ddPanel_siri">
                    <div class="dd-search-box">
                        <input type="text" placeholder="Cari siri..." oninput="ddFilter('siri',this.value)" onclick="event.stopPropagation()">
                    </div>
                    <div class="dd-options" id="ddOpts_siri">
                        <div class="dd-opt selected" data-value="" onclick="ddSelectSiri('','Semua Siri')">Semua Siri</div>
                        <?php
                        $siris = $conn->query("SELECT siri_id, siri_name, siri_year FROM siri ORDER BY siri_year DESC, siri_name ASC");
                        while ($si = $siris->fetch_assoc()) {
                            $sid_ = $si['siri_id'];
                            $slabel = htmlspecialchars($si['siri_name']) . " (" . htmlspecialchars($si['siri_year']) . ")";
                            echo "<div class='dd-opt' data-value='{$sid_}' onclick=\"ddSelectSiri('{$sid_}','{$slabel}')\">{$slabel}</div>";
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
                <div class="dd-trigger" id="ddTrigger_group" onclick="ddToggle('group')">
                    <span id="ddLabel_group" style="color:var(--c-text-faint);">Semua Kumpulan</span>
                    <span class="dd-arrow">▼</span>
                </div>
                <div class="dd-panel" id="ddPanel_group">
                    <div class="dd-search-box">
                        <input type="text" placeholder="Cari kumpulan..." oninput="ddFilter('group',this.value)" onclick="event.stopPropagation()">
                    </div>
                    <div class="dd-options" id="ddOpts_group">
                        <div class="dd-opt selected" data-value="" onclick="ddSelect('group','','Semua Kumpulan')">Semua Kumpulan</div>
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
                            echo "<div class='dd-opt' data-value='{$gid_}' data-level='{$glid_}' onclick=\"ddSelect('group','{$gid_}','{$optLabel}')\">{$optLabel}</div>";
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
                <div class="dd-trigger" id="ddTrigger_session" onclick="ddToggle('session')">
                    <span id="ddLabel_session" style="color:var(--c-text-faint);">Semua Sidang</span>
                    <span class="dd-arrow">▼</span>
                </div>
                <div class="dd-panel" id="ddPanel_session">
                    <div class="dd-search-box">
                        <input type="text" placeholder="Cari sidang..." oninput="ddFilter('session',this.value)" onclick="event.stopPropagation()">
                    </div>
                    <div class="dd-options" id="ddOpts_session">
                        <div class="dd-opt selected" data-value="" onclick="ddSelect('session','','Semua Sidang')">Semua Sidang</div>
                        <?php
                        $sessions = $conn->query("SELECT se.*, si.siri_name FROM sessions se LEFT JOIN siri si ON se.siri_id = si.siri_id ORDER BY se.session_name");
                        while ($s=$sessions->fetch_assoc()) {
                            $siriLbl = $s['siri_name'] ? $s['siri_name'] : 'Tiada Siri';
                            $sid2 = $s['session_id'];
                            $slabel2 = htmlspecialchars($s['session_name']) . " — " . htmlspecialchars($siriLbl);
                            echo "<div class='dd-opt' data-value='{$sid2}' data-siri='{$s['siri_id']}' onclick=\"ddSelect('session','{$sid2}','{$slabel2}')\">{$slabel2}</div>";
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
                <div class="dd-trigger" id="ddTrigger_level" onclick="ddToggle('level')">
                    <span id="ddLabel_level" style="color:var(--c-text-faint);">Semua Peringkat</span>
                    <span class="dd-arrow">▼</span>
                </div>
                <div class="dd-panel" id="ddPanel_level">
                    <div class="dd-search-box">
                        <input type="text" placeholder="Cari peringkat..." oninput="ddFilter('level',this.value)" onclick="event.stopPropagation()">
                    </div>
                    <div class="dd-options" id="ddOpts_level">
                        <div class="dd-opt selected" data-value="" onclick="ddSelectLevel('','Semua Peringkat')">Semua Peringkat</div>
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
                            echo "<div class='dd-opt' data-value='{$lid_}' onclick=\"ddSelectLevel('{$lid_}','{$optLabel2}')\">{$optLabel2}</div>";
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
                <div class="dd-trigger" id="ddTrigger_school" onclick="ddToggle('school')">
                    <span id="ddLabel_school" style="color:var(--c-text-faint);">Semua Cawangan</span>
                    <span class="dd-arrow">▼</span>
                </div>
                <div class="dd-panel" id="ddPanel_school">
                    <div class="dd-search-box">
                        <input type="text" placeholder="Cari cawangan..." oninput="ddFilter('school',this.value)" onclick="event.stopPropagation()">
                    </div>
                    <div class="dd-options" id="ddOpts_school">
                        <div class="dd-opt selected" data-value="" onclick="ddSelect('school','','Semua Cawangan')">Semua Cawangan</div>
                        <?php
                        $schools_f = $conn->query("SELECT school_id, school_name FROM schools ORDER BY school_name");
                        while ($sc_f = $schools_f->fetch_assoc()) {
                            $scid_ = $sc_f['school_id'];
                            $sclabel = htmlspecialchars($sc_f['school_name']);
                            echo "<div class='dd-opt' data-value='{$scid_}' onclick=\"ddSelect('school','{$scid_}','{$sclabel}')\">{$sclabel}</div>";
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

<script>
const PM_GROUPS_CSRF = <?= json_encode($csrf) ?>;

// Debounced so typing a name doesn't fire a request per keystroke — waits
// for a short pause, same UX as the other dd-wrap filters which only filter
// once an option is actually picked.
let searchDebounceTimer = null;
function onSearchInput() {
    clearTimeout(searchDebounceTimer);
    searchDebounceTimer = setTimeout(ajaxFilter, 350);
}

function ajaxFilter() {
    const siriEl = document.getElementById('f_siri');
    const params = new URLSearchParams({
        ajax:'1',
        group_id:   document.getElementById('f_group').value,
        session_id: document.getElementById('f_session').value,
        level_id:   document.getElementById('f_level').value,
        school_id:  document.getElementById('f_school').value,
        siri_id:    siriEl ? siriEl.value : '',
        search:     document.getElementById('f_search').value,
    });
    const el = document.getElementById('groupList');
    el.style.opacity = '0.4';
    pmFetch('pic_groups.php?' + params)
        .then(r => r.text())
        .then(html => {
            el.innerHTML = html;
            el.style.opacity = '1';
            initKanbanBoards();
            groupsCurrentPage = 1;
            updateGroupsPagination();
            restoreGroupsState();
        })
        .catch(() => { el.style.opacity = '1'; });
}

// ── Keep whichever cawangan/peringkat sections were open, and the exact
// scroll position, across a save/add/delete redirect — otherwise the page
// reload snaps everything shut and jumps back to the top. ──
const PM_GROUPS_STATE_KEY = 'pm_groups_open_state';

// Toast function is now shared (spawnPmToast, defined once in layout.php)
// since pic_criteria.php/pic_tests.php/pic_levels.php all need the exact
// same behavior — see layout.php for the implementation.

function rememberGroupsState() {
    const openIds = [];
    document.querySelectorAll('[id^="school_grp_"], [id^="grp_lvl_"]').forEach(el => {
        if (el.style.display === 'block') openIds.push(el.id);
    });
    sessionStorage.setItem(PM_GROUPS_STATE_KEY, JSON.stringify({ openIds, scrollY: window.scrollY }));
}

function restoreGroupsState() {
    let state = null;
    try { state = JSON.parse(sessionStorage.getItem(PM_GROUPS_STATE_KEY) || 'null'); } catch (e) {}
    sessionStorage.removeItem(PM_GROUPS_STATE_KEY);
    if (!state) return;
    // Keep only the last school_grp_ and last grp_lvl_ id — toggleBlock
    // never lets more than one of each be open at a time going forward,
    // but this stays defensive against any older multi-id state left over
    // in sessionStorage from before that was true.
    const openIds = state.openIds || [];
    const lastSchool = [...openIds].reverse().find(id => id.startsWith('school_grp_'));
    const lastLevel  = [...openIds].reverse().find(id => id.startsWith('grp_lvl_'));
    [lastSchool, lastLevel].filter(Boolean).forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.style.display = 'block';
        const arrow = el.previousElementSibling?.querySelector('.arrow');
        if (arrow) arrow.style.transform = 'rotate(90deg)';
    });
    if (typeof state.scrollY === 'number') {
        requestAnimationFrame(() => requestAnimationFrame(() => window.scrollTo(0, state.scrollY)));
    }
}

// ── PAGINATION (client-side, 20 session-accordions per page) ──
let groupsCurrentPage = 1;
const groupsPerPage = 20;

function updateGroupsPagination() {
    const cards = Array.from(document.querySelectorAll('#groupList > .accordion-card'));
    const container = document.getElementById('groupsPaginationContainer');
    const info = document.getElementById('groupsPageInfo');
    const btns = document.getElementById('groupsPaginationButtons');

    if (cards.length === 0) { container.style.display = 'none'; return; }

    const total = cards.length;
    const totalPages = Math.max(1, Math.ceil(total / groupsPerPage));
    if (groupsCurrentPage > totalPages) groupsCurrentPage = totalPages;
    if (groupsCurrentPage < 1) groupsCurrentPage = 1;

    container.style.display = totalPages <= 1 ? 'none' : 'flex';

    const start = (groupsCurrentPage - 1) * groupsPerPage;
    const end   = start + groupsPerPage;
    cards.forEach((c, i) => { c.style.display = (i >= start && i < end) ? '' : 'none'; });

    const s = start + 1;
    const e = Math.min(end, total);
    info.innerHTML = `Memaparkan <b>${s}–${e}</b> daripada <b>${total}</b> cawangan`;

    let html = `<button class="vm-page-btn" ${groupsCurrentPage === 1 ? 'disabled' : ''} onclick="groupsGoToPage(${groupsCurrentPage - 1})">&laquo;</button>`;
    let sp = Math.max(1, groupsCurrentPage - 2);
    let ep = Math.min(totalPages, sp + 4);
    if (ep - sp < 4) sp = Math.max(1, ep - 4);
    if (sp > 1) {
        html += `<button class="vm-page-btn" onclick="groupsGoToPage(1)">1</button>`;
        if (sp > 2) html += `<span class="vm-page-ellipsis">&hellip;</span>`;
    }
    for (let i = sp; i <= ep; i++) {
        html += `<button class="vm-page-btn ${i === groupsCurrentPage ? 'vm-page-active' : ''}" onclick="groupsGoToPage(${i})">${i}</button>`;
    }
    if (ep < totalPages) {
        if (ep < totalPages - 1) html += `<span class="vm-page-ellipsis">&hellip;</span>`;
        html += `<button class="vm-page-btn" onclick="groupsGoToPage(${totalPages})">${totalPages}</button>`;
    }
    html += `<button class="vm-page-btn" ${groupsCurrentPage === totalPages ? 'disabled' : ''} onclick="groupsGoToPage(${groupsCurrentPage + 1})">&raquo;</button>`;
    btns.innerHTML = html;
}

function groupsGoToPage(page) {
    groupsCurrentPage = page;
    updateGroupsPagination();
}

// ── Searchable dropdown logic (matches pic_students.php's dd-wrap) ──
function ddToggle(name) {
    const trigger = document.getElementById('ddTrigger_' + name);
    const panel = document.getElementById('ddPanel_' + name);
    const isOpen = panel.classList.contains('open');
    document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
    document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
    if (!isOpen) {
        panel.classList.add('open'); trigger.classList.add('open');
        setTimeout(() => panel.querySelector('.dd-search-box input')?.focus(), 50);
    }
}

function ddFilter(name, val) {
    const opts = document.querySelectorAll('#ddOpts_' + name + ' .dd-opt');
    const empty = document.getElementById('ddEmpty_' + name);
    let any = false;
    opts.forEach(o => {
        const m = o.textContent.toLowerCase().includes(val.toLowerCase());
        o.classList.toggle('hidden', !m);
        if (m) any = true;
    });
    if (empty) empty.style.display = any ? 'none' : 'block';
}

function ddSelect(name, value, label) {
    document.getElementById('f_' + name).value = value;
    const lbl = document.getElementById('ddLabel_' + name);
    lbl.textContent = label;
    lbl.style.color = value === '' ? 'var(--c-text-faint)' : '';
    document.querySelectorAll('#ddOpts_' + name + ' .dd-opt').forEach(o => o.classList.toggle('selected', o.dataset.value === value));
    document.getElementById('ddPanel_' + name).classList.remove('open');
    document.getElementById('ddTrigger_' + name).classList.remove('open');
    ajaxFilter();
}

document.addEventListener('click', e => {
    if (!e.target.closest('.dd-wrap')) {
        document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
        document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
    }
});

// Selecting a Siri narrows the Sidang dropdown to that siri's sessions only,
// since session names can repeat across siri and would otherwise be ambiguous.
function ddSelectSiri(value, label) {
    document.getElementById('f_siri').value = value;
    const lbl = document.getElementById('ddLabel_siri');
    lbl.textContent = label;
    lbl.style.color = value === '' ? 'var(--c-text-faint)' : '';
    document.querySelectorAll('#ddOpts_siri .dd-opt').forEach(o => o.classList.toggle('selected', o.dataset.value === value));
    document.getElementById('ddPanel_siri').classList.remove('open');
    document.getElementById('ddTrigger_siri').classList.remove('open');

    const sessionOpts = document.querySelectorAll('#ddOpts_session .dd-opt');
    let currentStillValid = true;
    const currentSessionValue = document.getElementById('f_session').value;
    sessionOpts.forEach(opt => {
        if (!opt.dataset.value) { opt.classList.remove('hidden'); return; }
        const matches = !value || opt.dataset.siri === value;
        opt.classList.toggle('hidden', !matches);
        if (opt.dataset.value === currentSessionValue && !matches) currentStillValid = false;
    });
    if (!currentStillValid) {
        document.getElementById('f_session').value = '';
        document.getElementById('ddLabel_session').textContent = 'Semua Sidang';
        document.getElementById('ddLabel_session').style.color = 'var(--c-text-faint)';
    }
    ajaxFilter();
}

// Selecting a Peringkat narrows the Kumpulan dropdown to that level's groups
// only, same pattern as ddSelectSiri narrowing Sidang above.
function ddSelectLevel(value, label) {
    document.getElementById('f_level').value = value;
    const lbl = document.getElementById('ddLabel_level');
    lbl.textContent = label;
    lbl.style.color = value === '' ? 'var(--c-text-faint)' : '';
    document.querySelectorAll('#ddOpts_level .dd-opt').forEach(o => o.classList.toggle('selected', o.dataset.value === value));
    document.getElementById('ddPanel_level').classList.remove('open');
    document.getElementById('ddTrigger_level').classList.remove('open');

    const groupOpts = document.querySelectorAll('#ddOpts_group .dd-opt');
    let currentStillValid = true;
    const currentGroupValue = document.getElementById('f_group').value;
    groupOpts.forEach(opt => {
        if (!opt.dataset.value) { opt.classList.remove('hidden'); return; }
        const matches = !value || opt.dataset.level === value;
        opt.classList.toggle('hidden', !matches);
        if (opt.dataset.value === currentGroupValue && !matches) currentStillValid = false;
    });
    if (!currentStillValid) {
        document.getElementById('f_group').value = '';
        document.getElementById('ddLabel_group').textContent = 'Semua Kumpulan';
        document.getElementById('ddLabel_group').style.color = 'var(--c-text-faint)';
    }
    ajaxFilter();
}

// ── Kanban board: every card drag, inline rename, judge change, column
// add/delete is its own immediately-persisted action — POSTed the instant
// it happens, no separate save step anywhere on this board. ──
function pmPost(action, params) {
    const body = new URLSearchParams({ action, csrf_token: PM_GROUPS_CSRF, ...params });
    return pmFetch('pic_groups.php', { method: 'POST', body })
        .then(r => r.json())
        .catch(() => ({ ok: false, msg: 'Ralat rangkaian. Sila cuba lagi.' }));
}

function initKanbanBoards() {
    document.querySelectorAll('.kanban-board').forEach(board => {
        if (board.dataset.klisten) return;
        board.dataset.klisten = '1';
        attachKanbanDrag(board);
        attachKanbanEdit(board);
        attachKanbanDelete(board);
    });
}

// ── Drag a student card between (or within) columns. The dropped-on
// column's full resulting card order is sent along with the move so the
// server can rewrite sort_order for that whole column in one pass — the
// same "loop order becomes sort_order" approach the page always used,
// just scoped to one column per drag instead of a whole card. ──
function attachKanbanDrag(board) {
    let draggedCard = null;
    let sourceBody = null;

    board.addEventListener('dragstart', e => {
        const card = e.target.closest('.kanban-card');
        if (!card) return;
        draggedCard = card;
        sourceBody = card.closest('.kanban-col-body');
        card.classList.add('kanban-dragging');
        e.dataTransfer.effectAllowed = 'move';
        // Firefox refuses to start a drag at all without data actually set
        // on the transfer — the value itself is unused, only the presence
        // of a payload matters (the move logic reads draggedCard/sourceBody
        // via closure, not dataTransfer).
        e.dataTransfer.setData('text/plain', card.dataset.sid || '');
    });

    board.addEventListener('dragend', () => {
        if (draggedCard) draggedCard.classList.remove('kanban-dragging');
        board.querySelectorAll('.kanban-drag-over').forEach(b => b.classList.remove('kanban-drag-over'));
        draggedCard = null;
        sourceBody = null;
    });

    board.addEventListener('dragover', e => {
        const addTile = e.target.closest('.kanban-col-add');
        if (addTile && draggedCard) {
            e.preventDefault();
            board.querySelectorAll('.kanban-drag-over').forEach(b => { if (b !== addTile) b.classList.remove('kanban-drag-over'); });
            addTile.classList.add('kanban-drag-over');
            return;
        }

        const body = e.target.closest('.kanban-col-body');
        if (!body || !draggedCard) return;
        e.preventDefault();
        board.querySelectorAll('.kanban-drag-over').forEach(b => { if (b !== body) b.classList.remove('kanban-drag-over'); });
        body.classList.add('kanban-drag-over');

        const after = [...body.querySelectorAll('.kanban-card:not(.kanban-dragging)')].find(c => {
            const r = c.getBoundingClientRect();
            return e.clientY < r.top + r.height / 2;
        });
        if (after) body.insertBefore(draggedCard, after);
        else body.appendChild(draggedCard);
    });

    board.addEventListener('drop', e => {
        // Dropping a student straight onto "+ Kumpulan Baharu" opens that
        // same add-group form with this student pending, instead of moving
        // any card — the actual assignment only happens once the group is
        // created (confirmAddColumn includes the pending student id).
        const addTile = e.target.closest('.kanban-col-add');
        if (addTile && draggedCard) {
            e.preventDefault();
            addTile.classList.remove('kanban-drag-over');
            const uid = board.id.replace('board_', '');
            showAddColumnForm(uid, draggedCard.dataset.sid, draggedCard.textContent.trim());
            return;
        }

        const body = e.target.closest('.kanban-col-body');
        if (!body || !draggedCard) return;
        e.preventDefault();
        body.classList.remove('kanban-drag-over');

        const sid = draggedCard.dataset.sid;
        const targetGroupId = body.dataset.groupId;
        const order = [...body.querySelectorAll('.kanban-card')].map(c => c.dataset.sid);
        const emptyHint = body.querySelector('.kanban-empty-hint');
        if (emptyHint) emptyHint.remove();
        if (sourceBody && sourceBody !== body && !sourceBody.querySelector('.kanban-card')) {
            const hint = document.createElement('div');
            hint.className = 'kanban-empty-hint';
            hint.textContent = 'Seret pelajar ke sini';
            sourceBody.appendChild(hint);
        }
        updateKanbanCounts(board);

        // Captured into a local const — draggedCard/sourceBody are shared
        // variables that dragend resets to null right after this handler
        // returns (and a later drag would overwrite them again before this
        // fetch resolves). Without this, the async .then()/.catch() below
        // would run against whatever draggedCard happens to be by the time
        // the server responds — null, or a completely different card —
        // instead of the one actually dragged here, which is exactly what
        // left the "saving" cursor stuck on the card forever.
        const cardRef = draggedCard;
        cardRef.classList.add('kanban-saving');
        const moveBody = new URLSearchParams({ action: 'move_student', csrf_token: PM_GROUPS_CSRF, student_id: sid, group_id: targetGroupId });
        order.forEach(id => moveBody.append('order[]', id));
        pmFetch('pic_groups.php', { method: 'POST', body: moveBody })
            .then(r => r.json())
            .then(res => {
                cardRef.classList.remove('kanban-saving');
                if (res.ok) {
                    // Quiet per-card confirmation instead of a toast on every
                    // single drag (which would get noisy fast) — a brief
                    // flash on the card that just landed is enough to show
                    // the move actually saved.
                    cardRef.classList.add('kanban-saved');
                    setTimeout(() => cardRef.classList.remove('kanban-saved'), 900);
                } else {
                    cardRef.classList.add('kanban-error');
                    setTimeout(() => cardRef.classList.remove('kanban-error'), 1500);
                    spawnPmToast(res.msg || 'Ralat memindahkan pelajar.', true);
                    rememberGroupsState();
                    ajaxFilter();
                }
            })
            .catch(() => {
                cardRef.classList.remove('kanban-saving');
                spawnPmToast('Ralat rangkaian. Sila cuba lagi.', true);
                rememberGroupsState();
                ajaxFilter();
            });
    });
}

function updateKanbanCounts(board) {
    board.querySelectorAll('.kanban-col').forEach(col => {
        const countEl = col.querySelector('.kanban-col-count');
        if (!countEl) return;
        const body = col.querySelector('.kanban-col-body');
        countEl.textContent = body ? body.querySelectorAll('.kanban-card').length : 0;
    });
}

// ── Inline rename / re-judge — saved on blur (or Enter/change), not on
// every keystroke, and only if the value actually changed. ──
function attachKanbanEdit(board) {
    board.addEventListener('focusout', e => {
        if (e.target.classList.contains('kanban-group-name')) saveKanbanGroup(e.target);
    });
    board.addEventListener('keydown', e => {
        if (e.target.classList.contains('kanban-group-name') && e.key === 'Enter') e.target.blur();
    });
    board.addEventListener('change', e => {
        if (e.target.classList.contains('kanban-judge-select')) saveKanbanGroup(e.target);
    });
}

function saveKanbanGroup(el) {
    const gid = el.dataset.groupId;
    const col = el.closest('.kanban-col');
    const nameInput = col.querySelector('.kanban-group-name');
    const judgeSelect = col.querySelector('.kanban-judge-select');
    const name = nameInput.value.trim();
    if (!name) { nameInput.value = nameInput.dataset.orig; return; }
    if (name === nameInput.dataset.orig && judgeSelect.value === (judgeSelect.dataset.orig || '')) return;

    const judgeChanged = judgeSelect.value !== (judgeSelect.dataset.orig || '');
    const judgeLabel = judgeSelect.options[judgeSelect.selectedIndex]?.text || '';

    pmPost('update_group', { group_id: gid, group_name: name, judge_id: judgeSelect.value }).then(res => {
        if (res.ok) {
            nameInput.dataset.orig = name;
            judgeSelect.dataset.orig = judgeSelect.value;
            spawnPmToast(judgeChanged ? ('Juri ditetapkan: ' + judgeLabel + '.') : ('Kumpulan dinamakan semula: "' + name + '".'), false);
        } else {
            nameInput.value = nameInput.dataset.orig;
            judgeSelect.value = judgeSelect.dataset.orig || '';
            spawnPmToast(res.msg || 'Ralat mengemaskini kumpulan.', true);
        }
    });
}

// ── Delete a column. Members become unassigned server-side, so the board
// is simplest to just refresh in place (keeping the open sections/scroll
// position via remember/restoreGroupsState) rather than hand-patch the DOM. ──
function attachKanbanDelete(board) {
    board.addEventListener('click', e => {
        const btn = e.target.closest('.kanban-col-delete');
        if (!btn) return;
        const gid = btn.dataset.groupId;
        const name = btn.dataset.groupName || '';
        if (!confirm('Padam kumpulan "' + name + '"? Pelajar di dalamnya akan menjadi Belum Diagihkan.')) return;
        pmPost('delete', { group_id: gid }).then(res => {
            spawnPmToast(res.msg || (res.ok ? 'Kumpulan dipadam.' : 'Ralat memadam kumpulan.'), !res.ok);
            rememberGroupsState();
            ajaxFilter();
        });
    });
}

// ── Add-column tile: name + optional judge, created (and saved) right on
// the board it belongs to — no detour to a separate form/page. ──
// pendingStudentId/pendingStudentName are only passed when this form was
// opened by dropping a card onto the "+" tile (see attachKanbanDrag) —
// clicking the "+ Kumpulan Baharu" button itself calls this with no args.
function showAddColumnForm(uid, pendingStudentId, pendingStudentName) {
    const form = document.getElementById('addColForm_' + uid);
    if (!form) return;
    form.style.display = 'flex';
    form.dataset.pendingStudentId = pendingStudentId || '';
    const hint = document.getElementById('addColPendingHint_' + uid);
    if (hint) hint.textContent = pendingStudentName ? ('+ ' + pendingStudentName) : '';
    const nameInput = document.getElementById('addColName_' + uid);
    if (nameInput) { nameInput.value = ''; setTimeout(() => nameInput.focus(), 50); }
}

function cancelAddColumn(uid) {
    const form = document.getElementById('addColForm_' + uid);
    if (form) { form.style.display = 'none'; delete form.dataset.pendingStudentId; }
    const hint = document.getElementById('addColPendingHint_' + uid);
    if (hint) hint.textContent = '';
}

function confirmAddColumn(uid) {
    const board = document.getElementById('board_' + uid);
    const form = document.getElementById('addColForm_' + uid);
    const nameInput = document.getElementById('addColName_' + uid);
    const judgeSelect = document.getElementById('addColJudge_' + uid);
    if (!board || !nameInput) return;
    const name = nameInput.value.trim();
    if (!name) { nameInput.focus(); return; }
    const pendingStudentId = form ? form.dataset.pendingStudentId : '';

    // Built as a raw request (not pmPost) since student_ids needs a real
    // repeated student_ids[] param, which a plain object can't express.
    const body = new URLSearchParams({
        action: 'create_group',
        csrf_token: PM_GROUPS_CSRF,
        level_id: board.dataset.levelId,
        school_id: board.dataset.schoolId,
        group_name: name,
        judge_id: judgeSelect ? judgeSelect.value : '',
    });
    if (pendingStudentId) body.append('student_ids[]', pendingStudentId);

    pmFetch('pic_groups.php', { method: 'POST', body })
        .then(r => r.json())
        .then(res => {
            if (res.ok) {
                cancelAddColumn(uid);
                spawnPmToast('Kumpulan "' + name + '" berjaya dicipta.', false);
                rememberGroupsState();
                ajaxFilter();
            } else {
                spawnPmToast(res.msg || 'Ralat menambah kumpulan.', true);
            }
        })
        .catch(() => spawnPmToast('Ralat rangkaian. Sila cuba lagi.', true));
}

function closeBlock(el) {
    el.style.display = 'none';
    const arrow = el.previousElementSibling?.querySelector('.arrow');
    if (arrow) arrow.style.transform = 'rotate(0deg)';
}

function toggleBlock(id) {
    const el = document.getElementById(id);
    if (!el) return;
    const isOpen = el.style.display === 'block';

    // True accordion: opening a cawangan (or a peringkat within one)
    // collapses whichever other one at the same level was already open,
    // instead of letting them all stack up into one long pile of
    // simultaneously-open panels.
    if (!isOpen) {
        if (id.startsWith('school_grp_')) {
            document.querySelectorAll('[id^="school_grp_"]').forEach(other => {
                if (other.id !== id && other.style.display === 'block') closeBlock(other);
            });
        } else if (id.startsWith('grp_lvl_')) {
            const scope = el.closest('[id^="school_grp_"]') || document;
            scope.querySelectorAll('[id^="grp_lvl_"]').forEach(other => {
                if (other.id !== id && other.style.display === 'block') closeBlock(other);
            });
        }
    }

    el.style.display = isOpen ? 'none' : 'block';
    // The header (.school-header or .accordion-sub-header) is always the
    // panel's immediately preceding sibling — rotate its ▶ arrow to point
    // down while open, back to the right when closed.
    const arrow = el.previousElementSibling?.querySelector('.arrow');
    if (arrow) arrow.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(90deg)';
}

document.addEventListener('DOMContentLoaded', ajaxFilter);
</script>

</main>
</body>
</html>
