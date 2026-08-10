<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

session_start();
require __DIR__ . '/auth_check.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pic') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

$pic_user_id = (int) $_SESSION['user_id'];
$last_max    = isset($_SESSION['last_score_max_id']) ? (int) $_SESSION['last_score_max_id'] : 0;

// Release the session lock now that we've read what we need. PHP's default
// file-based session handler holds an exclusive lock for the whole script,
// and this endpoint is polled every few seconds by every logged-in PIC user
// — without this, that lock blocks every other page load / click for the
// same user until the poll (DB queries + HTML building below) finishes.
session_write_close();

include 'db.php';
$conn = getDB();

$response = [
    'new'        => false,
    'messages'   => [],
    'unread_ids' => [],
    'is_catchup' => false
];

// ── 1. First connect: send unread notifications for this user ──
if ($last_max === 0) {
    $stmt = $conn->prepare("
        SELECT n.notification_id, n.message
        FROM notifications n
        WHERE NOT EXISTS (
            SELECT 1 FROM notification_reads r
            WHERE r.notification_id = n.notification_id
              AND r.user_id = ?
        )
        ORDER BY n.notification_id ASC
    ");
    $stmt->bind_param("i", $pic_user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $response['messages'][]   = $row['message'];
        $response['unread_ids'][] = $row['notification_id'];
    }
    $stmt->close();

    if (!empty($response['messages'])) {
        $response['new']        = true;
        $response['is_catchup'] = true;
    }

    $q       = $conn->query("SELECT MAX(score_id) as max_id FROM scores");
    $max_id  = (int) ($q->fetch_assoc()['max_id'] ?? 0);

    session_start();
    $_SESSION['last_score_max_id'] = $max_id;
    session_write_close();

    echo json_encode($response);
    exit();
}

// ── 2. Subsequent requests: check for new scores ──
$q           = $conn->query("SELECT MAX(score_id) as max_id FROM scores");
$current_max = (int) ($q->fetch_assoc()['max_id'] ?? 0);

if ($current_max > $last_max) {

    // Pull every new/updated score row with full context
    // is_edit = 1 when updated_at differs from created_at (i.e. this was an edit, not a first insert)
    $stmt = $conn->prepare("
        SELECT
            s.score_id,
            s.group_id,
            s.mark,
            s.criteria_id,
            (CASE WHEN s.updated_at > s.created_at THEN 1 ELSE 0 END) AS is_edit,
            st.student_name,
            sc.school_name,
            g.group_name,
            l.level_name,
            j.name  AS judge_name,
            j.id    AS judge_id,
            c.criteria_name,
            t.test_name
        FROM scores s
        JOIN students  st ON s.student_id  = st.student_id
        JOIN `groups`   g  ON s.group_id    = g.group_id
        JOIN levels     l  ON g.level_id    = l.level_id
        LEFT JOIN judges j  ON g.judge_id   = j.id
        LEFT JOIN schools sc ON st.school_id = sc.school_id
        JOIN criteria   c  ON s.criteria_id = c.criteria_id
        JOIN tests      t  ON c.test_id     = t.test_id
        WHERE s.score_id > ?
        ORDER BY s.group_id, st.student_name, t.test_id, c.criteria_id
    ");
    $stmt->bind_param("i", $last_max);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Group rows by group_id so we produce one notification per group batch
    $by_group = [];
    foreach ($rows as $r) {
        $gid = $r['group_id'];
        if (!isset($by_group[$gid])) {
            $by_group[$gid] = [
                'group_name'  => $r['group_name'],
                'level_name'  => $r['level_name'],
                'judge_name'  => $r['judge_name'] ?? 'Tidak Ditetapkan',
                'is_edit'     => (bool) $r['is_edit'],
                'students'    => []
            ];
        }
        // Track per-student: school, and per-criteria marks
        $sname = $r['student_name'];
        if (!isset($by_group[$gid]['students'][$sname])) {
            $by_group[$gid]['students'][$sname] = [
                'school' => $r['school_name'] ?? '—',
                'marks'  => []
            ];
        }
        $by_group[$gid]['students'][$sname]['marks'][] =
            htmlspecialchars($r['test_name']) . ' › ' . htmlspecialchars($r['criteria_name']) . ': <b>' . htmlspecialchars($r['mark']) . '/10</b>';

        // If any row in the group is an edit, flag the whole batch as edit
        if ($r['is_edit']) {
            $by_group[$gid]['is_edit'] = true;
        }
    }

    // Build one human-readable HTML message per group
    foreach ($by_group as $gid => $data) {
        $action     = $data['is_edit'] ? '✏️ Dikemaskini' : '✅ Dimasukkan';
        $judge      = htmlspecialchars($data['judge_name']);
        $group      = htmlspecialchars($data['group_name']);
        $level      = htmlspecialchars($data['level_name']);
        $total_pax  = count($data['students']);

        $msg  = "<div style='line-height:1.6;'>";
        $msg .= "<span style='font-size:0.8rem;text-transform:uppercase;letter-spacing:0.05em;color:#9ca3af;'>{$action}</span><br>";
        $msg .= "Juri <b style='color:#E25822;'>{$judge}</b> ";
        $msg .= ($data['is_edit'] ? "telah mengemaskini" : "telah memasukkan") . " markah bagi ";
        $msg .= "kumpulan <b>{$group}</b> ";
        $msg .= "<span style='color:#9ca3af;'>({$level})</span><br>";

        // Per-student breakdown
        foreach ($data['students'] as $sname => $sdata) {
            $school  = htmlspecialchars($sdata['school']);
            $student = htmlspecialchars($sname);
            $msg .= "<div style='margin:6px 0 2px; padding-left:10px; border-left:2px solid #374151;'>";
            $msg .= "<b>{$student}</b> <span style='color:#9ca3af;font-size:0.8rem;'>({$school})</span><br>";
            $msg .= "<span style='font-size:0.78rem;color:#d1d5db;'>" . implode(', ', $sdata['marks']) . "</span>";
            $msg .= "</div>";
        }

        $msg .= "</div>";

        // Persist to notifications table
        $ins = $conn->prepare("INSERT INTO notifications (message) VALUES (?)");
        $ins->bind_param("s", $msg);
        $ins->execute();
        $nid = $conn->insert_id;
        $ins->close();

        $response['messages'][]   = $msg;
        $response['unread_ids'][] = $nid;
    }

    if (!empty($response['messages'])) {
        $response['new'] = true;
    }

    session_start();
    $_SESSION['last_score_max_id'] = $current_max;
    session_write_close();
}

echo json_encode($response);
exit();