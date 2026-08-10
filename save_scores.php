<?php
session_start();
require __DIR__ . '/auth_check.php';
include 'db.php';
$conn = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['marks'])) {
    header("Location: judge.php");
    exit();
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'judge') {
    header("Location: login.php");
    exit();
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    header("Location: judge.php?msg=invalid_request");
    exit();
}

$group_id = intval($_POST['group_id']);
$judge_id = intval($_SESSION['user_id']); // ✅ Always use session, never trust POST
$is_edit  = isset($_POST['edit_mode']) && $_POST['edit_mode'] == '1';

// Release the session lock — everything below is DB work (potentially many
// INSERTs, one per mark) and doesn't touch $_SESSION again. Holding the lock
// through all of it would block any concurrent draft-save or dropdown AJAX
// request from the same judge's session until this whole submit finishes.
session_write_close();

// ── GUARD: group_id and judge_id must be valid ───────────────────────────────
if ($group_id <= 0 || $judge_id <= 0) {
    header("Location: judge.php?msg=invalid_request");
    exit();
}

// ── STEP 1: Collect and validate marks for EXISTING criteria — skip blanks ──
$valid_marks = [];
foreach ($_POST['marks'] as $student_id => $criteria_marks) {
    $student_id = intval($student_id);
    if ($student_id <= 0) continue;

    foreach ($criteria_marks as $criteria_id => $mark_value) {
        if ($mark_value === '' || $mark_value === null) continue; // Skip unentered

        $criteria_id = intval($criteria_id);
        $mark        = intval($mark_value); // tinyint in DB — no floats needed
        if ($mark < 0 || $mark > 10) continue; // Sanity check against DB constraint

        $valid_marks["{$student_id}_{$criteria_id}"] = [
            'student_id'  => $student_id,
            'criteria_id' => $criteria_id,
            'mark'        => $mark,
            'status'      => 'scored',
        ];
    }
}

// ── Criteria the judge explicitly opted out of via the Abai toggle —
// judge.php's abai[student_id][criteria_id] = '1' hidden input, toggled
// alongside (and cleared together with) marks[]. Recorded as its own row
// (mark=0 placeholder, status='abaikan') so the PIC's manual-marks page can
// tell "a judge deliberately skipped this" apart from "nobody's touched
// this yet" — previously both looked identical (no row at all). A real
// entered mark always wins if both are somehow present for the same pair. ──
if (!empty($_POST['abai'])) {
    foreach ($_POST['abai'] as $student_id => $criteria_flags) {
        $student_id = intval($student_id);
        if ($student_id <= 0 || !is_array($criteria_flags)) continue;

        foreach ($criteria_flags as $criteria_id => $flag) {
            if ($flag !== '1') continue;
            $criteria_id = intval($criteria_id);
            $key = "{$student_id}_{$criteria_id}";
            if (isset($valid_marks[$key])) continue; // a real mark takes priority

            $valid_marks[$key] = [
                'student_id'  => $student_id,
                'criteria_id' => $criteria_id,
                'mark'        => 0,
                'status'      => 'abaikan',
            ];
        }
    }
}

// ── Marks entered for a criteria added via "Tambah Peringkat & Ujian"
// (still pending — see STEP 1.5 below). It has no real criteria_id yet at
// this point, so these arrive keyed by the client-side "pending key" (e.g.
// "p0") judge.php assigned when it was queued, rather than by criteria_id.
// They get resolved and merged into $valid_marks once STEP 1.5 has
// created/found the real rows — but their raw presence counts toward the
// empty-submission guard right below, since a submission consisting only of
// newly-added-criteria marks is legitimate. ──
$has_pending_marks = false;
if (!empty($_POST['pending_marks'])) {
    foreach ($_POST['pending_marks'] as $criteria_marks) {
        if (!is_array($criteria_marks)) continue;
        foreach ($criteria_marks as $mark_value) {
            if ($mark_value !== '' && $mark_value !== null) { $has_pending_marks = true; break 2; }
        }
    }
}
if (!$has_pending_marks && !empty($_POST['pending_abai'])) {
    foreach ($_POST['pending_abai'] as $criteria_flags) {
        if (!is_array($criteria_flags)) continue;
        foreach ($criteria_flags as $flag) {
            if ($flag === '1') { $has_pending_marks = true; break 2; }
        }
    }
}

// ── GUARD: Do NOT assign judge or save anything if no marks were entered ─────
if (empty($valid_marks) && !$has_pending_marks) {
    header("Location: judge.php?msg=empty_submission");
    exit();
}

// ── GUARD: Ownership check — fetch who currently owns this group ─────────────
$own_stmt = $conn->prepare("SELECT judge_id, edit_used FROM `groups` WHERE group_id = ?");
$own_stmt->bind_param("i", $group_id);
$own_stmt->execute();
$own_row = $own_stmt->get_result()->fetch_assoc();
$own_stmt->close();

$current_owner   = (int)($own_row['judge_id'] ?? 0);
$edit_used_flag  = (int)($own_row['edit_used'] ?? 0);

if ($is_edit) {
    // Edit mode: only the original owner can edit, and only if edit hasn't been used yet
    if ($current_owner !== 0 && $current_owner !== $judge_id) {
        // Different judge trying to edit — reject
        header("Location: judge.php?msg=access_denied");
        exit();
    }
    if ($edit_used_flag === 1) {
        // Edit token already consumed — reject even for the owner
        header("Location: judge.php?msg=edit_locked");
        exit();
    }
} else {
    // First submission: only allowed if group is unowned
    if ($current_owner !== 0 && $current_owner !== $judge_id) {
        // Another judge already owns this group — reject
        header("Location: judge.php?msg=access_denied");
        exit();
    }
}

// ── STEP 1.5: Persist any deferred Peringkat/Ujian/Kriteria additions from
// the modal ────────────────────────────────────────────────────────────────
// The judge may have used "Tambah Peringkat & Ujian" before submitting
// marks — picking an existing item at each of the 3 levels, or typing a
// brand new name at any of them (which also forces every level below it to
// be new, since e.g. a not-yet-created Peringkat can't have any existing
// Ujian under it). Nothing was written to the DB then — each selection
// arrived here as a pending_params[] JSON string carrying only NAMES (plus
// the session_id and a client-side "key"), never ids, since a newly-typed
// item never had one. Every level is resolved the same way regardless of
// whether the judge picked it or typed it: find by name within its parent
// scope, create only if it doesn't already exist. This also naturally
// de-duplicates concurrent additions of the same name.
$pending_key_to_criteria_id = [];
if (!empty($_POST['pending_params'])) {
    foreach ($_POST['pending_params'] as $raw) {
        $entry = json_decode($raw, true);
        if (!$entry) continue;

        $key        = trim(    $entry['key']          ?? '');
        $session_id = (int)   ($entry['sessionId']    ?? 0);
        $lvl_name   = trim(    $entry['levelName']     ?? '');
        $t_name     = trim(    $entry['testName']      ?? '');
        $c_name     = trim(    $entry['criteriaName']  ?? '');

        if ($key === '' || $session_id === 0 || $lvl_name === '' || $t_name === '' || $c_name === '') continue;

        // Resolve level — reuse if already exists for this session
        $lvl_id = 0;
        $chkL = $conn->prepare(
            "SELECT level_id FROM levels WHERE session_id = ? AND level_name = ? LIMIT 1"
        );
        $chkL->bind_param("is", $session_id, $lvl_name);
        $chkL->execute();
        $rL = $chkL->get_result();
        if ($rL && $rL->num_rows > 0) {
            $lvl_id = (int) $rL->fetch_assoc()['level_id'];
        } else {
            $insL = $conn->prepare(
                "INSERT INTO levels (session_id, level_name) VALUES (?, ?)"
            );
            $insL->bind_param("is", $session_id, $lvl_name);
            $insL->execute();
            $lvl_id = (int) $conn->insert_id;
            $insL->close();
        }
        $chkL->close();

        if ($lvl_id === 0) continue;

        // Resolve test — reuse if already exists for this level
        $tst_id = 0;
        $chkT = $conn->prepare(
            "SELECT test_id FROM tests WHERE level_id = ? AND test_name = ? LIMIT 1"
        );
        $chkT->bind_param("is", $lvl_id, $t_name);
        $chkT->execute();
        $rT = $chkT->get_result();
        if ($rT && $rT->num_rows > 0) {
            $tst_id = (int) $rT->fetch_assoc()['test_id'];
        } else {
            $insT = $conn->prepare(
                "INSERT INTO tests (level_id, test_name) VALUES (?, ?)"
            );
            $insT->bind_param("is", $lvl_id, $t_name);
            $insT->execute();
            $tst_id = (int) $conn->insert_id;
            $insT->close();
        }
        $chkT->close();

        if ($tst_id === 0) continue;

        // Resolve criteria — insert only if it doesn't already exist for this test
        $crit_id = 0;
        $chkC = $conn->prepare(
            "SELECT criteria_id FROM criteria WHERE test_id = ? AND criteria_name = ? LIMIT 1"
        );
        $chkC->bind_param("is", $tst_id, $c_name);
        $chkC->execute();
        $rC = $chkC->get_result();
        if ($rC && $rC->num_rows > 0) {
            $crit_id = (int) $rC->fetch_assoc()['criteria_id'];
        } else {
            $insC = $conn->prepare(
                "INSERT INTO criteria (test_id, criteria_name, max_mark) VALUES (?, ?, 10)"
            );
            $insC->bind_param("is", $tst_id, $c_name);
            $insC->execute();
            $crit_id = (int) $conn->insert_id;
            $insC->close();
        }
        $chkC->close();

        if ($crit_id > 0) {
            $pending_key_to_criteria_id[$key] = $crit_id;
        }
    }
}

// ── Fold pending_marks[] (entered against a criteria that didn't have a
// real id yet) into $valid_marks now that STEP 1.5 has resolved real
// criteria_ids for them. ──
if (!empty($_POST['pending_marks'])) {
    foreach ($_POST['pending_marks'] as $pending_key => $student_marks) {
        if (!isset($pending_key_to_criteria_id[$pending_key]) || !is_array($student_marks)) continue;
        $resolved_criteria_id = $pending_key_to_criteria_id[$pending_key];

        foreach ($student_marks as $student_id => $mark_value) {
            if ($mark_value === '' || $mark_value === null) continue;

            $student_id = intval($student_id);
            if ($student_id <= 0) continue;

            $mark = intval($mark_value);
            if ($mark < 0 || $mark > 10) continue;

            $valid_marks["{$student_id}_{$resolved_criteria_id}"] = [
                'student_id'  => $student_id,
                'criteria_id' => $resolved_criteria_id,
                'mark'        => $mark,
                'status'      => 'scored',
            ];
        }
    }
}

// Same Abai handling as the main abai[] block above, but for criteria that
// were still pending (newly added via the modal) at submit time.
if (!empty($_POST['pending_abai'])) {
    foreach ($_POST['pending_abai'] as $pending_key => $student_flags) {
        if (!isset($pending_key_to_criteria_id[$pending_key]) || !is_array($student_flags)) continue;
        $resolved_criteria_id = $pending_key_to_criteria_id[$pending_key];

        foreach ($student_flags as $student_id => $flag) {
            if ($flag !== '1') continue;
            $student_id = intval($student_id);
            if ($student_id <= 0) continue;

            $key = "{$student_id}_{$resolved_criteria_id}";
            if (isset($valid_marks[$key])) continue; // a real mark takes priority

            $valid_marks[$key] = [
                'student_id'  => $student_id,
                'criteria_id' => $resolved_criteria_id,
                'mark'        => 0,
                'status'      => 'abaikan',
            ];
        }
    }
}

// ── GUARD (defensive): if pending_marks referenced a key that never resolved
// to a real criteria_id (malformed/tampered submission), $valid_marks could
// still be empty here even though the earlier guard let this request
// through on the strength of raw pending_marks presence. Don't assign the
// judge or notify the PIC over a submission that ends up saving nothing. ──
if (empty($valid_marks)) {
    header("Location: judge.php?msg=empty_submission");
    exit();
}

// ── STEP 3: Save scores using INSERT ... ON DUPLICATE KEY UPDATE ─────────────
// scores no longer stores test_id directly — it's derived via
// criteria_id -> criteria.test_id, so there's no separate test lookup
// needed here anymore.
$score_stmt = $conn->prepare("
    INSERT INTO scores (student_id, group_id, criteria_id, mark, status, submitted)
    VALUES (?, ?, ?, ?, ?, 1)
    ON DUPLICATE KEY UPDATE mark = VALUES(mark), status = VALUES(status), submitted = 1
");

foreach ($valid_marks as $entry) {
    $score_stmt->bind_param(
        "iiiis",
        $entry['student_id'],
        $group_id,
        $entry['criteria_id'],
        $entry['mark'],
        $entry['status']
    );
    $score_stmt->execute();
}
$score_stmt->close();

// ── STEP 4: Assign judge to group ONLY after scores are saved ────────────────
if ($is_edit) {
    $grp_stmt = $conn->prepare("UPDATE `groups` SET judge_id = ?, edit_used = 1 WHERE group_id = ? AND judge_id = ?");
    $grp_stmt->bind_param("iii", $judge_id, $group_id, $judge_id);
} else {
    $grp_stmt = $conn->prepare("UPDATE `groups` SET judge_id = ? WHERE group_id = ? AND (judge_id IS NULL OR judge_id = 0)");
    $grp_stmt->bind_param("ii", $judge_id, $group_id);
}
$grp_stmt->execute();
$grp_stmt->close();


// ── STEP 5: Generate a single notification for the PIC ───────────────────────
// Fetch Judge Name and Group Name so the notification is highly readable
$notif_stmt = $conn->prepare("
    SELECT j.name as judge_name, g.group_name
    FROM `groups` g
    LEFT JOIN judges j ON j.id = ?
    WHERE g.group_id = ?
");
$notif_stmt->bind_param("ii", $judge_id, $group_id);
$notif_stmt->execute();
$notif_res = $notif_stmt->get_result()->fetch_assoc();
$notif_stmt->close();

$jname = htmlspecialchars($notif_res['judge_name'] ?? 'Juri');
$gname = htmlspecialchars($notif_res['group_name'] ?? 'Tidak Diketahui');

// Differentiate the message based on whether they are new marks or edits
if ($is_edit) {
    $message = "Juri <b style='color:#E25822;'>{$jname}</b> telah mengemaskini markah untuk Kumpulan <b>{$gname}</b>.";
} else {
    $message = "Juri <b style='color:#E25822;'>{$jname}</b> telah memasukkan markah untuk Kumpulan <b>{$gname}</b>.";
}

$ins_notif = $conn->prepare("INSERT INTO notifications (message) VALUES (?)");
$ins_notif->bind_param("s", $message);
$ins_notif->execute();
$ins_notif->close();


// ── STEP 6: Redirect back to Judge Dashboard ─────────────────────────────────
header("Location: judge.php?status=success");
exit();
?>
