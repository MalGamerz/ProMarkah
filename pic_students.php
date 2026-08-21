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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ── POST actions ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token'])) {
        header("Location: pic_students.php?msg=Ralat+token+keselamatan.+Sila+muat+semula+halaman.&status=error"); exit();
    }
    session_write_close();
    $ok = false;

    try {

    // Shared validation: name required (matches varchar(100) NOT NULL), level
    // must be a positive int (matches the FK column), gender must be one of
    // the DB enum's two values, year must be a plausible 4-digit year.
    // school_id is checked separately below — 'add' submits it, but the bulk
    // save_all rows never include a school selector (only add's form does),
    // so requiring it here would reject every legitimate bulk save.
    // The form marks all of these required client-side, but that's trivially
    // bypassed by a raw POST — this is the real (server-side) enforcement.
    $validStudent = function (array $s): ?string {
        $name = trim((string)($s['student_name'] ?? ''));
        if ($name === '') return null;
        $level = (int)($s['level_id'] ?? 0);
        if ($level <= 0) return null;
        $gender = $s['gender'] ?? '';
        if (!in_array($gender, ['Male', 'Female'], true)) return null;
        $year = (int)($s['year'] ?? 0);
        if ($year < 2000 || $year > 2100) return null;
        return $name; // trimmed name is the one thing callers still need
    };

    if ($_POST['action'] === 'add') {
        // Peringkat/Cawangan/Tahun are shared by every row in this batch —
        // only the name (and its auto-detected gender) varies per student,
        // so several students in the same peringkat can be added at once.
        $level  = (int)($_POST['level_id']  ?? 0);
        $school = (int)($_POST['school_id'] ?? 0);
        $year   = (int)($_POST['year']      ?? 0);

        $namesRaw   = $_POST['student_name'] ?? [];
        $gendersRaw = $_POST['gender']       ?? [];
        if (!is_array($namesRaw))   { $namesRaw   = [$namesRaw]; }
        if (!is_array($gendersRaw)) { $gendersRaw = [$gendersRaw]; }

        $rows = [];
        foreach ($namesRaw as $i => $n) {
            $n = trim((string)$n);
            if ($n === '') { continue; }
            $g = $gendersRaw[$i] ?? '';
            if (!in_array($g, ['Male', 'Female'], true)) { continue; }
            $rows[] = ['name' => $n, 'gender' => $g];
        }

        if ($level > 0 && $school > 0 && $year >= 2000 && $year <= 2100 && !empty($rows)) {
            // Every level belongs to exactly one session (levels.session_id —
            // same lookup manage_attendance.php relies on), so a newly added
            // student's session is already pinned by the Peringkat picked
            // above; use it to seed their attendance as Present by default
            // instead of leaving them with no attendance row at all (which
            // every report/view then reads as Tidak Hadir).
            $sessStmt = $conn->prepare("SELECT session_id FROM levels WHERE level_id = ?");
            $sessStmt->bind_param('i', $level);
            $sessStmt->execute();
            $sessionId = (int)($sessStmt->get_result()->fetch_assoc()['session_id'] ?? 0);
            $sessStmt->close();
            $attStmt = $sessionId > 0
                ? $conn->prepare("INSERT INTO attendance (student_id, status, session_id) VALUES (?, 'Present', ?)")
                : null;

            $stmt = $conn->prepare("INSERT INTO students (student_name,level_id,school_id,gender,year) VALUES (?,?,?,?,?)");
            $ok = true;
            foreach ($rows as $r) {
                $stmt->bind_param('siisi', $r['name'], $level, $school, $r['gender'], $year);
                if (!$stmt->execute()) { $ok = false; continue; }
                if ($attStmt) {
                    $newId = $conn->insert_id;
                    $attStmt->bind_param('ii', $newId, $sessionId);
                    if (!$attStmt->execute()) {
                        promarkah_report('Caught', 'Failed to seed default attendance for new student', __FILE__, __LINE__, "student_id=$newId session_id=$sessionId");
                    }
                }
            }
            $stmt->close();
            if ($attStmt) $attStmt->close();
            $count = count($rows);
            $msg = $ok ? "{$count}+pelajar+berjaya+ditambah." : 'Ralat+menambah+pelajar.+Sila+cuba+lagi.';
        } else {
            $ok = false;
            $msg = 'Sila+lengkapkan+semua+medan+pelajar+dengan+nilai+yang+sah.';
        }
    } elseif ($_POST['action'] === 'delete') {
        // No FK constraints in this schema — deleting only the `students`
        // row left orphaned rows behind in every table keyed by
        // student_id (scores: both submitted marks and unsubmitted
        // drafts; group_students: kumpulan membership; attendance:
        // per-session records). Wrapped in a transaction so a mid-way
        // failure can't leave the student half-deleted.
        $studentId = (int) $_POST['student_id'];
        $conn->begin_transaction();
        try {
            foreach (['scores', 'group_students', 'attendance'] as $table) {
                $del = $conn->prepare("DELETE FROM `$table` WHERE student_id=?");
                $del->bind_param('i', $studentId);
                $del->execute();
                $del->close();
            }
            $stmt = $conn->prepare("DELETE FROM students WHERE student_id=?");
            $stmt->bind_param('i', $studentId);
            $stmt->execute();
            $stmt->close();
            $conn->commit();
            $ok = true;
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
        $msg = $ok ? 'Pelajar+berjaya+dipadam.' : 'Ralat+memadam+pelajar.+Sila+cuba+lagi.';
    } elseif ($_POST['action'] === 'save_all' && isset($_POST['students']) && is_array($_POST['students'])) {
        // Existing school_id per student, fetched up front, so a Cawangan
        // change can be detected (POST doesn't tell us the "before" value).
        $ids = array_map('intval', array_keys($_POST['students']));
        $currentSchools = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $sStmt = $conn->prepare("SELECT student_id, school_id FROM students WHERE student_id IN ($in)");
            $sStmt->bind_param(str_repeat('i', count($ids)), ...$ids);
            $sStmt->execute();
            $sRes = $sStmt->get_result();
            while ($row = $sRes->fetch_assoc()) $currentSchools[(int)$row['student_id']] = (int)$row['school_id'];
            $sStmt->close();
        }

        $stmt = $conn->prepare("UPDATE students SET student_name=?,level_id=?,school_id=?,gender=?,year=? WHERE student_id=?");
        // A cawangan change invalidates the student's current kumpulan (groups
        // are scoped per-cawangan — see pic_groups.php's visibility filter),
        // so drop their group membership rather than leave them silently
        // misassigned; the PIC re-assigns them under the new cawangan in
        // Kumpulan Juri.
        $stmtUngroup = $conn->prepare("DELETE FROM group_students WHERE student_id=?");
        $ok = true;
        $anyInvalid = false;
        foreach ($_POST['students'] as $id => $s) {
            if (!is_array($s)) { $anyInvalid = true; continue; }
            $name = $validStudent($s);
            if ($name === null) { $anyInvalid = true; continue; }
            $school = (int)($s['school_id'] ?? 0);
            if ($school <= 0) { $anyInvalid = true; continue; }
            $id     = (int)$id;
            $level  = (int)$s['level_id'];
            $year   = (int)$s['year'];
            $stmt->bind_param('siissi', $name, $level, $school, $s['gender'], $year, $id);
            if (!$stmt->execute()) { $ok = false; continue; }
            if (($currentSchools[$id] ?? $school) !== $school) {
                $stmtUngroup->bind_param('i', $id);
                $stmtUngroup->execute();
            }
        }
        $stmt->close();
        $stmtUngroup->close();
        $ok = $ok && !$anyInvalid;
        $msg = $ok ? 'Perubahan+berjaya+disimpan.' : 'Ralat+menyimpan+sebahagian+perubahan.+Sila+semak+dan+cuba+lagi.';
    } else {
        $ok = false;
        $msg = 'Tindakan+tidak+sah.';
    }

    } catch (Throwable $e) {
        promarkah_report('Caught', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
        $ok  = false;
        $msg = 'Ralat+pangkalan+data.+Sila+cuba+lagi.';
    }

    // save_all is submitted via AJAX (see saveSchool() in JS below) so its
    // success/failure can be shown right at the sticky bar being edited —
    // a full-page redirect+banner would land at the top of the page, out of
    // view if the school being edited is far down a long accordion list.
    if ($_POST['action'] === 'save_all' && (isset($_POST['ajax']) && $_POST['ajax'] === '1')) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok, 'msg' => str_replace('+', ' ', $msg)]);
        exit;
    }

    header("Location: pic_students.php?msg={$msg}&status=" . ($ok ? 'success' : 'error')); exit();
}

session_write_close();

// ── AJAX: return sessions (sidang) list scoped to a siri ──────────────────
if (isset($_GET['ajax_sessions'])) {
    header('Content-Type: application/json');
    $siriId = (int)($_GET['siri'] ?? 0);
    $out = [];
    if ($siriId > 0) {
        $stmt = $conn->prepare("SELECT session_id, session_name FROM sessions WHERE siri_id = ? ORDER BY session_name");
        $stmt->bind_param('i', $siriId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $out[] = $row;
        $stmt->close();
    }
    echo json_encode($out);
    exit();
}

// ── AJAX: Peringkat list scoped to one Siri (Tambah Pelajar's Siri picker,
//    only rendered when more than one Siri exists) ─────────────────────────
if (isset($_GET['ajax_levels_for_siri'])) {
    header('Content-Type: application/json');
    $siriId = (int)($_GET['siri'] ?? 0);
    $out = [];
    if ($siriId > 0) {
        $stmt = $conn->prepare("SELECT l.level_id, l.level_name FROM levels l JOIN sessions s ON l.session_id = s.session_id WHERE s.siri_id = ? ORDER BY l.level_name");
        $stmt->bind_param('i', $siriId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $out[] = $row;
        $stmt->close();
    }
    echo json_encode($out);
    exit();
}

// ── AJAX: existing student names for a level+school (duplicate check used
//    by the "paste a name list" generator on the Tambah Pelajar form) ──────
if (isset($_GET['ajax_existing_names'])) {
    header('Content-Type: application/json');
    $level  = (int)($_GET['level_id']  ?? 0);
    $school = (int)($_GET['school_id'] ?? 0);
    $out = [];
    if ($level > 0 && $school > 0) {
        $stmt = $conn->prepare("SELECT student_name FROM students WHERE level_id = ? AND school_id = ?");
        $stmt->bind_param('ii', $level, $school);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $out[] = $row['student_name'];
        $stmt->close();
    }
    echo json_encode($out);
    exit();
}

// ── AJAX: return table rows ───────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    $search    = $_GET['search']    ?? '';
    $level     = $_GET['level']     ?? '';
    $gender    = $_GET['gender']    ?? '';
    $yearF     = $_GET['year']      ?? '';
    $school_id = $_GET['school_id'] ?? '';
    $siriF     = $_GET['siri']      ?? '';
    $sessionF  = $_GET['session']   ?? '';
    $sort_name = $_GET['sort_name'] ?? '';

    // Scope to the "Siri Aktif" picked in the sidebar — same filter pic.php's
    // Ringkasan dashboard uses (students -> level -> session -> siri).
    $active_siri = (int)($_SESSION['active_siri_id'] ?? 0);

    $whereArr = ["1=1"]; $bindTypes = ''; $bindVals = [];
    if ($search    !== '') { $whereArr[] = "st.student_name LIKE ?"; $bindTypes .= 's'; $bindVals[] = '%'.$search.'%'; }
    if ($level     !== '') { $whereArr[] = "st.level_id = ?";        $bindTypes .= 'i'; $bindVals[] = (int)$level;     }
    if ($gender    !== '') { $whereArr[] = "st.gender = ?";          $bindTypes .= 's'; $bindVals[] = $gender;         }
    if ($yearF     !== '') { $whereArr[] = "st.year = ?";            $bindTypes .= 's'; $bindVals[] = $yearF;          }
    if ($school_id !== '') { $whereArr[] = "st.school_id = ?";       $bindTypes .= 'i'; $bindVals[] = (int)$school_id; }
    if ($sessionF  !== '') { $whereArr[] = "l.session_id = ?";       $bindTypes .= 'i'; $bindVals[] = (int)$sessionF;  }
    elseif ($siriF !== '') { $whereArr[] = "se.siri_id = ?";         $bindTypes .= 'i'; $bindVals[] = (int)$siriF;     }
    elseif ($active_siri > 0) { $whereArr[] = "se.siri_id = ?";     $bindTypes .= 'i'; $bindVals[] = $active_siri;    }

    $where = implode(" AND ", $whereArr);
    $sql = "SELECT st.student_id, st.student_name, st.gender, st.year, st.level_id, st.school_id, l.level_name, sc.school_name
        FROM students st
        JOIN levels l  ON st.level_id  = l.level_id
        JOIN schools sc ON st.school_id = sc.school_id
        LEFT JOIN sessions se ON l.session_id = se.session_id
        WHERE $where";
    // Same-school rows must stay consecutive (the accordion grouping below
    // relies on it), so year/school/level stay the leading sort keys even
    // when the user toggles name sorting — only the final tiebreaker changes.
    if ($sort_name === 'ASC') {
        $sql .= " ORDER BY st.year DESC, sc.school_name, l.level_name, st.student_name ASC";
    } elseif ($sort_name === 'DESC') {
        $sql .= " ORDER BY st.year DESC, sc.school_name, l.level_name, st.student_name DESC";
    } else {
        $sql .= " ORDER BY st.year DESC, sc.school_name, l.level_name, st.student_id";
    }
    $stmt = $conn->prepare($sql);
    if ($bindTypes) $stmt->bind_param($bindTypes, ...$bindVals);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    // Fetched ONCE up front and reused for every row's Peringkat <select> below
    // — this used to re-run the same query inside the per-student loop, once
    // per row, which is what made filtering slow as the result set grew.
    $all_levels_opts = [];
    $lvls_q2 = $active_siri > 0
        ? $conn->query("SELECT l.* FROM levels l JOIN sessions s ON l.session_id = s.session_id WHERE s.siri_id = $active_siri ORDER BY l.level_name")
        : $conn->query("SELECT l.*, si.siri_name FROM levels l LEFT JOIN sessions s ON l.session_id = s.session_id LEFT JOIN siri si ON s.siri_id = si.siri_id ORDER BY l.level_name");
    while ($l = $lvls_q2->fetch_assoc()) $all_levels_opts[] = $l;

    // Fetched once, reused for every row's Cawangan <select> below — same
    // rationale as $all_levels_opts above.
    $all_schools_opts = [];
    $schs_q2 = $conn->query("SELECT school_id, school_name FROM schools ORDER BY school_name");
    while ($s = $schs_q2->fetch_assoc()) $all_schools_opts[] = $s;

    // Sort-direction indicator icon for the "Nama" header, matching
    // pic_master_list.php's style — re-rendered server-side each fetch
    // since this partial's <thead> is regenerated per school accordion.
    $sortIconSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg>';
    if ($sort_name === 'ASC') {
        $sortIconSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--c-red)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m6 15 6-6 6 6"/></svg>';
    } elseif ($sort_name === 'DESC') {
        $sortIconSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--c-red)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>';
    }
    $nameHeaderHtml = "<th onclick='toggleSortNameStudents()' style='cursor:pointer; user-select:none;' title='Susun A-Z / Z-A'>
                        <div style='display:inline-flex; align-items:center; gap:6px;'>
                            Nama
                            <span style='display:inline-flex; align-items:center; color:var(--c-text-faint); margin-top:1px;'>$sortIconSvg</span>
                        </div>
                    </th>";

    $current_school = '';
    $no = 1;
    while ($st = $result->fetch_assoc()) {
        $sch = htmlspecialchars($st['school_name']);
        if ($sch !== $current_school) {
            if ($current_school !== '') echo "</tbody></table></div></div></div></div>";
            $uid = 'school_' . $st['school_id'] . '_' . rand(1000,9999);
            echo "<div class='accordion-card'>
        <div class='school-header' onclick=\"toggleBlock('$uid')\" role='button' tabindex='0'>
            <span class='arrow'>▶</span>
            <strong>$sch</strong>
            <button type='button' class='pm-btn pm-btn-primary btn-sm school-add-btn' onclick=\"event.stopPropagation(); tambahForSchool('{$st['school_id']}')\">+ Tambah</button>
        </div>
        <div id='$uid' style='display:none;'>
            <div class='accordion-body'>
                <div class='school-sticky-bar' id='stickyBar_{$st['school_id']}'>
                    <span class='sticky-msg'>Ada <strong class='dirty-count'>0</strong> perubahan belum disimpan</span>
                    <span class='sticky-feedback' id='stickyFeedback_{$st['school_id']}'></span>
                    <button type='button' class='pm-btn pm-btn-ghost btn-sm' onclick=\"discardSchool('{$st['school_id']}')\">Batal</button>
                    <button type='button' class='pm-btn pm-btn-primary btn-sm' onclick=\"saveSchool('{$st['school_id']}')\">💾 Simpan</button>
                </div>
                <div class='table-responsive'><table class='students-table' data-school='{$st['school_id']}'><thead>
                <tr>
                    <th style='width:40px;text-align:center;'>No</th>
                    <th style='width:70px;text-align:center;'>ID</th>
                    $nameHeaderHtml
                    <th>Peringkat</th>
                    <th>Cawangan</th>
                    <th>Jantina</th>
                    <th>Tahun</th>
                    <th style='width:80px;'></th>
                </tr>
                </thead><tbody>";
            $current_school = $sch;
            $no = 1;
        }
        $sel_opts = '';
        // Scope to "Siri Aktif"; when "Semua Siri" is active, tag each level
        // with its siri so same-named levels across siri aren't ambiguous.
        foreach ($all_levels_opts as $l) {
            $sel = ($l['level_id'] == $st['level_id']) ? "selected" : "";
            $optLabel = htmlspecialchars($l['level_name']);
            if ($active_siri === 0 && !empty($l['siri_name'])) {
                $optLabel .= " — " . htmlspecialchars($l['siri_name']);
            }
            $sel_opts .= "<option value='{$l['level_id']}' $sel>$optLabel</option>";
        }
        $sch_opts = '';
        foreach ($all_schools_opts as $sc2) {
            $sel2 = ($sc2['school_id'] == $st['school_id']) ? "selected" : "";
            $sch_opts .= "<option value='{$sc2['school_id']}' $sel2>" . htmlspecialchars($sc2['school_name']) . "</option>";
        }
        echo "<tr data-id='{$st['student_id']}'>
                <td style='text-align:center;color:var(--c-text-faint);'>$no</td>
                <td style='text-align:center;color:var(--c-text-faint);font-family:monospace;'>{$st['student_id']}</td>
                <td><input form='masterSaveForm' name='students[{$st['student_id']}][student_name]' id='studentName_{$st['student_id']}' value='" . htmlspecialchars($st['student_name']) . "' oninput=\"autoDetectGender(this.value, document.getElementById('studentGender_{$st['student_id']}'))\"></td>
                <td><select form='masterSaveForm' name='students[{$st['student_id']}][level_id]'>$sel_opts</select></td>
                <td><select form='masterSaveForm' name='students[{$st['student_id']}][school_id]' title='Menukar cawangan akan mengosongkan kumpulan semasa pelajar ini — agihkan semula di Kumpulan Juri.'>$sch_opts</select></td>
                <td><select form='masterSaveForm' name='students[{$st['student_id']}][gender]' id='studentGender_{$st['student_id']}'>
                    <option value='Male'"   . ($st['gender']=='Male'   ? ' selected':'') . ">Lelaki</option>
                    <option value='Female'" . ($st['gender']=='Female' ? ' selected':'') . ">Perempuan</option>
                </select></td>
                <td><input type='number' form='masterSaveForm' name='students[{$st['student_id']}][year]' value='" . htmlspecialchars($st['year']) . "'></td>
                <td style='text-align:center;'>
                    <button type='button' class='pm-btn pm-btn-danger btn-sm'
                        onclick=\"deleteStu('{$st['student_id']}')\">Padam</button>
                </td>
              </tr>";
        $no++;
    }
    if ($current_school !== '') echo "</tbody></table></div></div></div></div>";
    if ($no === 1 && $current_school === '')
        echo "<p style='text-align:center;padding:30px;color:var(--c-text-faint);'>Tiada pelajar ditemui.</p>";
    exit();
}

$pm_page = 'students';
include 'layout.php';
?>

<?php
$pm_st_css_v = @filemtime(__DIR__ . '/pic_students.css') ?: time();
?>
<link rel="stylesheet" href="pic_students.css?v=<?= $pm_st_css_v ?>">

<form id='masterSaveForm' method='POST'>
    <input type='hidden' name='action' value='save_all'>
    <input type='hidden' name='csrf_token' value='<?= htmlspecialchars($csrf) ?>'>
</form>

<form id='delStuFrm' method='POST' style='display:none;'>
    <input type='hidden' name='action' value='delete'>
    <input type='hidden' name='student_id' value=''>
    <input type='hidden' name='csrf_token' value='<?= htmlspecialchars($csrf) ?>'>
</form>

<?php
$active_siri_main = (int)($_SESSION['active_siri_id'] ?? 0);

// Every Siri in the system, regardless of the sidebar's "Siri Aktif" —
// used only to decide the Tambah Pelajar form's Peringkat scoping below.
// With exactly one Siri there's nothing to pick, so the picker stays
// hidden and that one Siri is used directly (no " — Siri Name" suffix
// needed since there's nothing to disambiguate). With more than one, the
// picker shows and the Peringkat list gets reloaded (ajax_levels_for_siri)
// whenever it changes — this keeps the pasted-header auto-match in
// pic_students.js always comparing against a plain, unsuffixed level_name.
$all_siri_for_add = [];
$siriRes_add = $conn->query("SELECT siri_id, siri_name FROM siri ORDER BY siri_year DESC, siri_name");
while ($sr = $siriRes_add->fetch_assoc()) $all_siri_for_add[(int)$sr['siri_id']] = $sr['siri_name'];

$default_add_siri_id = $active_siri_main > 0
    ? $active_siri_main
    : (!empty($all_siri_for_add) ? array_key_first($all_siri_for_add) : 0);
?>

<div class='pic-section-header'>
    <div>
        <h2>🥋 Pengurusan Pelajar</h2>
        <div class='pic-section-sub'>Daftar dan kemaskini profil pelajar</div>
    </div>
    <button type='button' class='pm-btn pm-btn-primary' onclick="openGenericAddForm()">+ Tambah Pelajar</button>
</div>

<?php if (isset($_GET['msg'])):
    $isError = ($_GET['status'] ?? '') === 'error';
?>
<div class="pm-alert <?= $isError ? 'pm-alert-danger' : 'pm-alert-success' ?>">
    <?= $isError ? '⚠️' : '✅' ?> <?= htmlspecialchars($_GET['msg']) ?>
</div>
<?php endif; ?>

<div class='filter-card'>
    <div class='filter-card-title'>🔍 Tapis Pelajar</div>
    <div class='pic-filter-bar'>
        <div>
            <label>Tahun</label>
            <div class="dd-wrap" id="ddWrap_year">
                <div class="dd-trigger" id="ddTrigger_year" onclick="ddToggle('year')" role="button" tabindex="0" aria-haspopup="listbox">
                    <span id="ddLabel_year" style="color:var(--c-text-faint);">Semua Tahun</span>
                    <span class="dd-arrow">▼</span>
                </div>
                <div class="dd-panel" id="ddPanel_year" role="listbox">
                    <div class="dd-search-box">
                        <input type="text" placeholder="Cari tahun..." oninput="ddFilter('year',this.value)" onclick="event.stopPropagation()">
                    </div>
                    <div class="dd-options" id="ddOpts_year">
                        <div class="dd-opt selected" data-value="" role="option" tabindex="0" onclick="ddSelect('year','','Semua Tahun')">Semua Tahun</div>
                        <?php
                        $years = $conn->query("SELECT DISTINCT year FROM students ORDER BY year DESC");
                        while ($y = $years->fetch_assoc()) {
                            $yr = htmlspecialchars($y['year']);
                            echo "<div class='dd-opt' role='option' tabindex='0' data-value='{$yr}' onclick=\"ddSelect('year','{$yr}','{$yr}')\">{$yr}</div>";
                        }
                        ?>
                    </div>
                    <div class="dd-empty" id="ddEmpty_year">Tiada hasil</div>
                </div>
                <input type="hidden" id="f_year" value="">
            </div>
        </div>
        <div>
            <label>Siri</label>
            <div class="dd-wrap" id="ddWrap_siri">
                <div class="dd-trigger" id="ddTrigger_siri" onclick="ddToggle('siri')" role="button" tabindex="0" aria-haspopup="listbox">
                    <span id="ddLabel_siri" style="color:var(--c-text-faint);">-- Semua Siri --</span>
                    <span class="dd-arrow">▼</span>
                </div>
                <div class="dd-panel" id="ddPanel_siri" role="listbox">
                    <div class="dd-search-box">
                        <input type="text" placeholder="Cari siri..." oninput="ddFilter('siri',this.value)" onclick="event.stopPropagation()">
                    </div>
                    <div class="dd-options" id="ddOpts_siri">
                        <div class="dd-opt selected" data-value="" role="option" tabindex="0" onclick="ddSelect('siri','','-- Semua Siri --')">-- Semua Siri --</div>
                        <?php
                        $siri_list = $conn->query("SELECT siri_id, siri_name FROM siri ORDER BY siri_year DESC, siri_name");
                        while ($sr = $siri_list->fetch_assoc()) {
                            $sid_  = $sr['siri_id'];
                            $sname = htmlspecialchars($sr['siri_name']);
                            echo "<div class='dd-opt' role='option' tabindex='0' data-value='{$sid_}' onclick=\"ddSelect('siri','{$sid_}','{$sname}')\">{$sname}</div>";
                        }
                        ?>
                    </div>
                    <div class="dd-empty" id="ddEmpty_siri">Tiada hasil</div>
                </div>
                <input type="hidden" id="f_siri" value="">
            </div>
        </div>
        <div>
            <label>Sidang</label>
            <div class="dd-wrap" id="ddWrap_session">
                <div class="dd-trigger dd-trigger-disabled" id="ddTrigger_session" onclick="ddToggle('session')" role="button" tabindex="0" aria-haspopup="listbox">
                    <span id="ddLabel_session" style="color:var(--c-text-faint);">-- Pilih Siri dahulu --</span>
                    <span class="dd-arrow">▼</span>
                </div>
                <div class="dd-panel" id="ddPanel_session" role="listbox">
                    <div class="dd-search-box">
                        <input type="text" placeholder="Cari sidang..." oninput="ddFilter('session',this.value)" onclick="event.stopPropagation()">
                    </div>
                    <div class="dd-options" id="ddOpts_session">
                        <div class="dd-opt selected" data-value="" role="option" tabindex="0" onclick="ddSelect('session','','-- Semua Sidang --')">-- Semua Sidang --</div>
                    </div>
                    <div class="dd-empty" id="ddEmpty_session">Tiada hasil</div>
                </div>
                <input type="hidden" id="f_session" value="">
            </div>
        </div>
        <div>
            <label>Nama Pelajar</label>
            <input id='f_search' placeholder='Cari nama pelajar...' oninput='ajaxFilter()'>
        </div>
        <div>
            <label>Jantina</label>
            <div class="dd-wrap" id="ddWrap_gender">
                <div class="dd-trigger" id="ddTrigger_gender" onclick="ddToggle('gender')" role="button" tabindex="0" aria-haspopup="listbox">
                    <span id="ddLabel_gender" style="color:var(--c-text-faint);">Semua Jantina</span>
                    <span class="dd-arrow">▼</span>
                </div>
                <div class="dd-panel" id="ddPanel_gender" role="listbox">
                    <div class="dd-search-box">
                        <input type="text" placeholder="Cari jantina..." oninput="ddFilter('gender',this.value)" onclick="event.stopPropagation()">
                    </div>
                    <div class="dd-options" id="ddOpts_gender">
                        <div class="dd-opt selected" data-value="" role="option" tabindex="0" onclick="ddSelect('gender','','Semua Jantina')">Semua Jantina</div>
                        <div class="dd-opt" data-value="Male" role="option" tabindex="0" onclick="ddSelect('gender','Male','Lelaki')">Lelaki</div>
                        <div class="dd-opt" data-value="Female" role="option" tabindex="0" onclick="ddSelect('gender','Female','Perempuan')">Perempuan</div>
                    </div>
                    <div class="dd-empty" id="ddEmpty_gender">Tiada hasil</div>
                </div>
                <input type="hidden" id="f_gender" value="">
            </div>
        </div>
        <div>
            <label>Nama Cawangan</label>
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
                        while ($sc = $schools_f->fetch_assoc()) {
                            $scid = $sc['school_id'];
                            $scname = htmlspecialchars($sc['school_name']);
                            echo "<div class='dd-opt' role='option' tabindex='0' data-value='{$scid}' onclick=\"ddSelect('school','{$scid}','{$scname}')\">{$scname}</div>";
                        }
                        ?>
                    </div>
                    <div class="dd-empty" id="ddEmpty_school">Tiada hasil</div>
                </div>
                <input type="hidden" id="f_school" value="">
            </div>
        </div>
    </div>
</div>

<div id='addStudent' class='pic-add-card'>
    <h3>+ Tambah Pelajar Baru</h3>
    <form method='POST' class='pic-add-form pic-add-form--multi'>
        <input type='hidden' name='action' value='add'>
        <input type='hidden' name='csrf_token' value='<?= htmlspecialchars($csrf) ?>'>
        <div class='pic-add-form-row'>
            <?php if (count($all_siri_for_add) > 1): ?>
            <div>
                <label>Siri</label>
                <select id='addSiriSelect' onchange="reloadPeringkatOptionsForSiri(this.value)">
                    <?php foreach ($all_siri_for_add as $sid => $sname): ?>
                        <option value='<?= $sid ?>' <?= $sid === $default_add_siri_id ? 'selected' : '' ?>><?= htmlspecialchars($sname) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div>
                <label>Peringkat</label>
                <select name='level_id' required>
                    <?php
                    $lvls = $default_add_siri_id > 0
                        ? $conn->query("SELECT l.* FROM levels l JOIN sessions s ON l.session_id = s.session_id WHERE s.siri_id = {$default_add_siri_id} ORDER BY l.level_name")
                        : $conn->query("SELECT l.*, si.siri_name FROM levels l LEFT JOIN sessions s ON l.session_id = s.session_id LEFT JOIN siri si ON s.siri_id = si.siri_id ORDER BY l.level_name");
                    while ($l = $lvls->fetch_assoc()) {
                        $optLabel = htmlspecialchars($l['level_name']);
                        if ($default_add_siri_id === 0 && !empty($l['siri_name'])) {
                            $optLabel .= " — " . htmlspecialchars($l['siri_name']);
                        }
                        echo "<option value='{$l['level_id']}'>$optLabel</option>";
                    }
                    ?>
                </select>
            </div>
            <div id='schoolFieldWrap'>
                <label>Cawangan</label>
                <select name='school_id' required>
                    <?php $schs = $conn->query("SELECT * FROM schools ORDER BY school_name"); while ($s=$schs->fetch_assoc()) echo "<option value='{$s['school_id']}'>" . htmlspecialchars($s['school_name']) . "</option>"; ?>
                </select>
            </div>
            <div>
                <label>Tahun</label>
                <input type='number' name='year' placeholder='Tahun' value='<?= date('Y') ?>' required>
            </div>
        </div>
        <div class='pic-paste-list-wrap'>
            <label>Nama Pelajar <span style="text-transform:none; font-weight:400; letter-spacing:0;">(satu nama, atau tampal senarai bernombor untuk ramai sekali gus — semuanya masuk peringkat &amp; cawangan yang sama di atas; baris tajuk di atas nombor diabaikan secara automatik)</span></label>
            <textarea id='pasteNameList' rows='6' placeholder="AWAN PUTIH CULA MERAH 2&#10;1. MUHAMMAD HAEL MIKAEL BIN MOHD HAFIZI&#10;2. MUHAMMAD SYAWAL MIKAEL BIN MOHD HAFIZI&#10;3. CHE NUR DHIA KAMALIA BINTI CHE HANAFIAH&#10;&#10;— atau, untuk seorang sahaja: taip satu nama —" oninput="tryAutoSelectPeringkatFromPastedHeader()"></textarea>
            <div class='paste-actions-row'>
                <button type='button' class='pm-btn pm-btn-ghost btn-sm' onclick="generateRowsFromPastedList()">Jana Senarai Pelajar</button>
                <span id='pasteStatusBadge'></span>
            </div>
        </div>
        <div>
            <div id='addRows_student'></div>
            <button type='button' class='add-row-btn add-row-add' onclick="addStudentRow()">+ Tambah Baris Kosong</button>
        </div>
        <div class='pic-add-form-actions'>
            <button class='pm-btn pm-btn-primary' onclick="return pmValidateAddStudentSubmit()">Tambah</button>
        </div>
    </form>
</div>

<div id='studentListScroll'>
    <div id='studentList'><p style='text-align:center;padding:30px;color:var(--c-text-faint);'>Memuatkan...</p></div>
    <div id='ajaxSpinner'>⏳ Mencari...</div>
</div>

<div class="vm-pagination" id="studentsPaginationContainer" style="display:none;">
    <div class="vm-page-info" id="studentsPageInfo"></div>
    <div class="vm-page-btns" id="studentsPaginationButtons"></div>
</div>

<?php
$pm_st_js_v = @filemtime(__DIR__ . '/pic_students.js') ?: time();
?>
<script src="pic_students.js?v=<?= $pm_st_js_v ?>"></script>

</main>
</body>
</html>