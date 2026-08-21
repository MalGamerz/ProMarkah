<?php
/**
 * judge_ajax.php — the small-form-dropdown AJAX endpoints for judge.php.
 *
 * Not a standalone URL — judge.php requires this file inline (same scope,
 * so it sees $conn/$judge_id already set up by that point) after its own
 * ajax_add_parameter no-op and $judge_id/$judge_name setup. Each branch
 * below exits() if its query param is present; if none match, this file
 * just falls through and judge.php continues rendering the full page.
 * Called from the JS cascade handlers in judge_dashboard.js as
 * judge.php?ajax_levels=1&session_id=..., etc. — the URL stays judge.php,
 * this file only supplies the code that answers it.
 */

// ── INTERNAL AJAX ENDPOINTS FOR FORM DROPDOWNS ──
if (isset($_GET["ajax_levels"])) {
    $session_id_param = (int) $_GET["session_id"];
    // Peringkat this judge already has a group in surface first — those are
    // the ones they're actually going to keep coming back to, so burying
    // them alphabetically among every other peringkat in the sidang just
    // adds friction each time they pick up marking again.
    //
    // A peringkat is only listed if it still has at least one group this
    // judge could actually pick in the Kumpulan dropdown next — unassigned,
    // or already theirs. Otherwise every group under it belongs to another
    // judge (ajax_groups below filters those out entirely), so picking this
    // peringkat would always land on an empty Kumpulan list.
    // mine_total/mine_done = how many of the judge's own groups in this
    // Peringkat exist vs. already have submitted marks — shown as a plain
    // "(done/total)" fraction so partial progress is just as visible as a
    // fully-marked Peringkat, no separate done/not-done state needed.
    $stmt = $conn->prepare(
        "SELECT l.*,
            (SELECT COUNT(*) FROM `groups` g
              WHERE g.level_id = l.level_id AND g.judge_id = ?) AS mine_total,
            (SELECT COUNT(*) FROM `groups` g
              WHERE g.level_id = l.level_id AND g.judge_id = ?
                AND EXISTS(SELECT 1 FROM scores s WHERE s.group_id = g.group_id AND s.submitted = 1)
            ) AS mine_done
         FROM levels l
         WHERE l.session_id = ?
           AND EXISTS (
               SELECT 1 FROM `groups` g2
               WHERE g2.level_id = l.level_id
                 AND (g2.judge_id IS NULL OR g2.judge_id = 0 OR g2.judge_id = ?)
           )
         ORDER BY (mine_total > 0) DESC,
                  (mine_total > 0 AND mine_done = mine_total) ASC,
                  l.level_name ASC",
    );
    if ($stmt) {
        $stmt->bind_param("iiii", $judge_id, $judge_id, $session_id_param, $judge_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            $label = htmlspecialchars($r["level_name"]);
            if ($r["mine_total"] > 0) {
                $progress = ($r["mine_done"] == $r["mine_total"])
                    ? "✅"
                    : "{$r["mine_done"]}/{$r["mine_total"]}";
                $label = "★ " . $label . " ({$progress})";
            }
            echo "<option value='{$r["level_id"]}'>{$label}</option>";
        }
        $stmt->close();
    }
    exit();
}

if (isset($_GET["ajax_groups"])) {
    $level_id_param = (int) $_GET["level_id"];
    // Groups owned by another judge are excluded from this list entirely —
    // a judge only ever needs to see groups they can actually act on
    // (unassigned, pickable groups; or their own). has_marks/edit_used are
    // pulled in so the list can be ordered "unmarked first, done last" and
    // so a fully-locked group (marks submitted + one-time edit consumed)
    // can be shown disabled rather than removed, so the judge still sees
    // it exists and can't be confused about where it went.
    $stmt = $conn->prepare(
        "SELECT g.group_id, g.group_name, g.judge_id, g.edit_used, j.name AS judge_name,
                EXISTS(SELECT 1 FROM scores s WHERE s.group_id = g.group_id AND s.submitted = 1) AS has_marks
         FROM `groups` g
         LEFT JOIN judges j ON j.id = g.judge_id
         WHERE g.level_id = ? ORDER BY g.group_name ASC"
    );
    if ($stmt) {
        $stmt->bind_param("i", $level_id_param);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($r = $result->fetch_assoc()) {
            $unassigned   = empty($r["judge_id"]);
            $ownedByMe    = !empty($r["judge_id"]) && (int)$r["judge_id"] === $judge_id;
            $ownedByOther = !$unassigned && !$ownedByMe;

            if ($ownedByOther) continue; // not this judge's to see

            $hasMarks    = (bool) $r["has_marks"];
            $fullyLocked = $hasMarks && (int)$r["edit_used"] === 1;

            $label = htmlspecialchars($r["group_name"]);
            if ($unassigned) {
                $label .= " ⚠ (Belum Ditetapkan)";
            } elseif ($fullyLocked) {
                $label .= " 🔒 (Selesai — Dikunci)";
            } elseif ($hasMarks) {
                $label .= " ✅ (Anda — Selesai)";
            } else {
                $label .= " (Anda)";
            }

            $rows[] = [
                'id'          => $r['group_id'],
                'label'       => $label,
                'unassigned'  => $unassigned ? '1' : '0',
                'own'         => $ownedByMe ? '1' : '0',
                'has_marks'   => $hasMarks,
                'disabled'    => $fullyLocked,
            ];
        }
        $stmt->close();

        // Groups already assigned to this judge surface first (mirrors the
        // "★ is_mine" ordering on the Peringkat dropdown above) — those are
        // the ones they're actually here to mark. Within that, unmarked
        // groups surface before ones already marked, and especially before
        // fully-locked ones, which sink to the very bottom.
        usort($rows, function ($a, $b) {
            if ($a['own'] !== $b['own']) return $b['own'] <=> $a['own'];
            if ($a['has_marks'] !== $b['has_marks']) return $a['has_marks'] <=> $b['has_marks'];
            if ($a['disabled'] !== $b['disabled']) return $a['disabled'] <=> $b['disabled'];
            return 0; // keep original group_name order within each bucket
        });

        foreach ($rows as $row) {
            $disabledAttr = $row['disabled'] ? "disabled" : "";
            echo "<option value='{$row['id']}' data-unassigned='{$row['unassigned']}' data-own='{$row['own']}' data-owned-other='0' $disabledAttr>{$row['label']}</option>";
        }
    }
    exit();
}

// ── AJAX: Modal cascade — tests for a level ──
if (isset($_GET["ajax_modal_tests"])) {
    $level_id_param = (int) $_GET["level_id"];
    $stmt = $conn->prepare(
        "SELECT test_id, test_name FROM tests WHERE level_id = ? ORDER BY test_name ASC",
    );
    if ($stmt) {
        $stmt->bind_param("i", $level_id_param);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($r = $result->fetch_assoc()) {
            $rows[] = ["id" => $r["test_id"], "name" => $r["test_name"]];
        }
        $stmt->close();
        header("Content-Type: application/json");
        echo json_encode($rows);
    }
    exit();
}

// ── AJAX: Modal cascade — criteria for a test ──
if (isset($_GET["ajax_modal_criteria"])) {
    $test_id_param = (int) $_GET["test_id"];
    $stmt = $conn->prepare(
        "SELECT criteria_id, criteria_name FROM criteria WHERE test_id = ? ORDER BY criteria_name ASC",
    );
    if ($stmt) {
        $stmt->bind_param("i", $test_id_param);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($r = $result->fetch_assoc()) {
            $rows[] = ["id" => $r["criteria_id"], "name" => $r["criteria_name"]];
        }
        $stmt->close();
        header("Content-Type: application/json");
        echo json_encode($rows);
    }
    exit();
}

// ── AJAX: Sessions filtered by siri_id ──
if (isset($_GET['ajax_sessions'])) {
    $siri_id_param = (int) $_GET['siri_id'];
    $stmt = $conn->prepare(
        "SELECT session_id, session_name FROM sessions WHERE siri_id = ? ORDER BY session_name ASC"
    );
    if ($stmt) {
        $stmt->bind_param("i", $siri_id_param);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            echo "<option value='{$r["session_id"]}'>" . htmlspecialchars($r["session_name"]) . "</option>";
        }
        $stmt->close();
    }
    exit();
}
