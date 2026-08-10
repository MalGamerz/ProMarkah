<?php
session_start();
require __DIR__ . '/auth_check.php';
include 'db.php';
$conn = getDB();


// PIC only
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pic') {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['group_id'])) {

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        header("Location: pic_manual_marks.php?msg=Ralat+Token+Keselamatan&status=error");
        exit();
    }

    $group_id = intval($_POST['group_id']);

    // marks[student_id][criteria_id] = mark — the whole group is submitted
    // in one grid. A locked (already-marked) input is disabled client-side,
    // which means the browser omits it from the POST entirely, so only
    // criteria the PIC explicitly unlocked or newly filled in ever arrive
    // here — nothing gets silently overwritten.
    $marks = $_POST['marks'] ?? [];

    // clear[student_id][criteria_id] = '1' — the PIC hit "Abai" on an
    // existing mark, so that scores row should be removed outright rather
    // than updated (equivalent to a judge unchecking "Dinilai").
    $clears = $_POST['clear'] ?? [];

    if ($group_id <= 0 || (empty($marks) && empty($clears))) {
        header("Location: pic_manual_marks.php?msg=Ralat+Data+Tidak+Lengkap&status=error");
        exit();
    }

    $valid_marks = [];
    if (is_array($marks)) {
        foreach ($marks as $student_id => $criteria_marks) {
            $student_id = intval($student_id);
            if ($student_id <= 0 || !is_array($criteria_marks)) continue;

            foreach ($criteria_marks as $criteria_id => $mark_value) {
                if ($mark_value === '' || $mark_value === null) continue; // Skip unentered

                $criteria_id = intval($criteria_id);
                $mark        = intval($mark_value); // tinyint in DB — no floats needed
                if ($criteria_id <= 0) continue;
                if ($mark < 0 || $mark > 10) continue; // sanity check against DB constraint

                $valid_marks[] = [
                    'student_id'  => $student_id,
                    'criteria_id' => $criteria_id,
                    'mark'        => $mark,
                ];
            }
        }
    }

    $valid_clears = [];
    if (is_array($clears)) {
        foreach ($clears as $student_id => $criteria_flags) {
            $student_id = intval($student_id);
            if ($student_id <= 0 || !is_array($criteria_flags)) continue;

            foreach ($criteria_flags as $criteria_id => $flag) {
                if (!$flag) continue;
                $criteria_id = intval($criteria_id);
                if ($criteria_id <= 0) continue;
                $valid_clears[] = ['student_id' => $student_id, 'criteria_id' => $criteria_id];
            }
        }
    }

    if (empty($valid_marks) && empty($valid_clears)) {
        header("Location: pic_manual_marks.php?msg=Tiada+markah+baharu+untuk+disimpan.&status=error");
        exit();
    }

    $savedCount = 0;
    $clearedCount = 0;

    try {
        // status = 'scored' explicitly on every write — a PIC typing a real
        // number always means a real mark, even when overriding a criteria
        // a judge had previously marked 'abaikan' (skipped). Without this,
        // that row would keep its old abaikan status forever even though a
        // real mark now sits in it.
        $score_stmt = $conn->prepare("
            INSERT INTO scores (student_id, group_id, criteria_id, mark, status, submitted)
            VALUES (?, ?, ?, ?, 'scored', 1)
            ON DUPLICATE KEY UPDATE mark = VALUES(mark), status = 'scored', submitted = 1
        ");

        foreach ($valid_marks as $entry) {
            $score_stmt->bind_param(
                "iiii",
                $entry['student_id'],
                $group_id,
                $entry['criteria_id'],
                $entry['mark']
            );
            if ($score_stmt->execute()) $savedCount++;
        }
        $score_stmt->close();

        $clear_stmt = $conn->prepare("DELETE FROM scores WHERE student_id = ? AND group_id = ? AND criteria_id = ?");
        foreach ($valid_clears as $entry) {
            $clear_stmt->bind_param("iii", $entry['student_id'], $group_id, $entry['criteria_id']);
            if ($clear_stmt->execute()) $clearedCount++;
        }
        $clear_stmt->close();

        if ($savedCount === 0 && $clearedCount === 0) {
            header("Location: pic_manual_marks.php?msg=Tiada+markah+disimpan.+Sila+semak+kriteria+yang+dipilih.&status=error");
            exit();
        }

    } catch (Throwable $e) {
        promarkah_report('Caught', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
        header("Location: pic_manual_marks.php?msg=Ralat+pangkalan+data.+Sila+cuba+lagi.&status=error");
        exit();
    }

    header("Location: pic_manual_marks.php?msg=Markah+Berjaya+Disimpan!&status=success");
    exit();
} else {
    header("Location: pic_manual_marks.php");
    exit();
}
