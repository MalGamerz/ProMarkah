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
        $stmt = $conn->prepare("DELETE FROM students WHERE student_id=?");
        $stmt->bind_param('i', $_POST['student_id']); $ok = $stmt->execute(); $stmt->close();
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
        <div class='school-header' onclick=\"toggleBlock('$uid')\">
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

<style>
    /* ── Filter card ── */
    .filter-card {
        background: var(--c-surface-1);
        border: 1px solid var(--c-border-strong);
        border-radius: 10px;
        padding: 16px 20px;
        margin-bottom: 18px;
    }
    .filter-card-title {
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: var(--c-text-faint);
        margin-bottom: 12px;
    }
    .pic-filter-bar {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 16px;
        align-items: end;
    }
    .pic-filter-bar > div {
        min-width: 0;
    }
    .pic-filter-bar label {
        display: block;
        margin-bottom: 6px;
        font-size: .75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: var(--c-text-faint);
    }
    .pic-filter-bar input,
    .pic-filter-bar select {
        background: var(--c-surface-2);
        border: 1px solid var(--c-border-strong);
        color: var(--c-white);
        border-radius: 6px;
        height: 32px;
        padding: 0 10px;
        font-size: .8rem;
        outline: none;
        width: 100%;
        transition: border-color .2s;
        box-sizing: border-box;
    }
    .pic-filter-bar input:focus,
    .pic-filter-bar select:focus {
        border-color: var(--c-red);
    }
    .pic-filter-bar select option {
        background: var(--c-surface-2);
        color: var(--c-white);
    }
    .pic-filter-bar input::placeholder {
        color: var(--c-text-faint);
    }

    /* ── Add card ── */
    .pic-add-card {
        display: none;
        background: var(--c-surface-1);
        border: 1px solid var(--c-red-border);
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 18px;
    }
    .pic-add-card h3 {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 1.1rem;
        color: var(--c-white);
        margin-bottom: 14px;
        letter-spacing: 0.05em;
    }
    .pic-add-form {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 10px;
        align-items: end;
    }
    .pic-add-form input,
    .pic-add-form select {
        background: var(--c-surface-2);
        border: 1px solid var(--c-border-strong);
        color: var(--c-white);
        border-radius: 6px;
        padding: 9px 12px;
        font-size: 0.875rem;
        outline: none;
        width: 100%;
        box-sizing: border-box;
        transition: border-color .2s;
    }
    .pic-add-form input:focus,
    .pic-add-form select:focus {
        border-color: var(--c-red);
        box-shadow: 0 0 0 3px var(--c-red-dim);
    }
    .pic-add-form select option {
        background: var(--c-surface-2);
    }
    .pic-add-form label {
        display: block;
        font-size: 0.75rem;
        color: var(--c-text-faint);
        margin-bottom: 4px;
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 0.07em;
    }
    /* Button in the add-form grid was shorter than the input/select next to
       it (pm-btn's tight line-height vs. the input's padded box) — pin both
       to the same height so the row looks uniform. */
    .pic-add-form button.pm-btn {
        height: 40px;
        padding: 0 16px;
        box-sizing: border-box;
    }

    /* ── Multi-row "add several students at once" group ── */
    .pic-add-form--multi {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }
    .pic-add-form--multi > div { width: 100%; }
    .pic-add-form-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 10px;
    }
    .add-row { display: flex; gap: 8px; margin-bottom: 8px; }
    .add-row:last-child { margin-bottom: 0; }
    .add-row input { flex: 1; min-width: 0; }
    .add-row .student-gender-select { flex: 0 0 140px; }
    .add-row-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        border-radius: 6px;
        font-size: 0.8rem;
        font-weight: 600;
        transition: all .15s;
    }
    .add-row-remove {
        width: 40px;
        height: 40px;
        flex: 0 0 auto;
        background: var(--c-surface-2);
        border: 1px solid var(--c-border-strong);
        color: var(--c-text-faint);
    }
    .add-row-remove:hover { border-color: var(--c-red-300); color: var(--c-red); background: rgba(214, 40, 40, .08); }
    .add-row-add {
        height: 36px;
        padding: 0 14px;
        background: transparent;
        border: 1px dashed var(--c-border-strong);
        color: var(--c-text-faint);
    }
    .add-row-add:hover { border-color: var(--c-red); color: var(--c-red); }
    .pic-add-form-actions { display: flex; justify-content: flex-end; }
    html.pm-light .add-row-remove { background: var(--c-gray-50); border-color: var(--c-gray-300); }
    html.pm-light .add-row-add { border-color: var(--c-gray-300); color: var(--c-gray-500); }

    /* Display student/peringkat/cawangan data in caps — the accordion
       titles (Cawangan), the editable Nama/Peringkat fields in the table,
       the add-form's Peringkat/Cawangan selects, and the Cawangan filter
       dropdown — purely visual, the stored value keeps whatever case was
       typed. */
    .school-header strong,
    .students-table input:not([type=hidden]),
    .students-table select,
    .pic-add-form select,
    #ddOpts_school .dd-opt,
    #ddLabel_school {
        text-transform: uppercase;
    }

    /* ── Accordion ── */
    .accordion-card {
        background: var(--c-surface-1);
        border: 1px solid var(--c-border);
        border-radius: 10px;
        margin-bottom: 12px;
    }
    .school-header {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 16px;
        background: var(--c-surface-1);
        border-bottom: 1px solid var(--c-border);
        cursor: pointer;
        color: var(--c-white);
        font-weight: 600;
        font-size: 0.92rem;
        transition: background .15s;
        border-radius: 10px;
    }
    .school-header:hover {
        background: var(--c-surface-2);
    }
    .school-header .arrow {
        color: var(--c-red);
        font-size: 0.9rem;
        transition: transform .2s ease;
        display: inline-block;
    }
    .school-header .school-add-btn {
        margin-left: auto;
        text-transform: none;
        flex-shrink: 0;
    }

    /* ── Accordion body ── */
    .accordion-body {
        max-height: 420px;
        overflow-y: auto;
        overflow-x: hidden;
    }
    .accordion-body .table-responsive {
        overflow-x: auto;
    }

    /* ── Save bar ── */
    .school-sticky-bar {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 16px;
        background: var(--c-surface-2);
        border-bottom: 2px solid var(--c-red);
        position: sticky;
        top: 0;
        z-index: 10;
        animation: slideDown .2s ease;
    }
    @keyframes slideDown {
        from { opacity: 0; transform: translateY(-6px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .school-sticky-bar .sticky-msg {
        font-size: 0.82rem;
        color: var(--c-text-faint);
        flex: 1;
    }
    .school-sticky-bar .sticky-msg strong {
        color: var(--c-white);
    }
    /* Inline save feedback, shown right at the sticky bar being edited —
       not a page-top banner, which would be scrolled out of view on a long
       accordion list. */
    .sticky-feedback {
        font-size: 0.82rem;
        font-weight: 700;
        opacity: 0;
        transition: opacity .2s ease;
        white-space: nowrap;
    }
    .sticky-feedback.show { opacity: 1; }
    .sticky-feedback.is-success { color: #4ade80; }
    .sticky-feedback.is-error   { color: var(--c-red); }
    html.pm-light .sticky-feedback.is-success { color: #16a34a; }

    /* ── Students table ── */
    .table-responsive {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .students-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.875rem;
        min-width: 600px;
    }
    .students-table th {
        background: var(--c-surface-3);
        color: var(--c-text-muted);
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        padding: 11px 14px;
        text-align: left;
        white-space: nowrap;
        border-bottom: 2px solid var(--c-red);
        position: sticky;
        top: 0;
        z-index: 5;
    }
    .students-table td {
        padding: 9px 14px;
        color: var(--c-text-muted);
        background: var(--c-surface-1);
        border-bottom: 1px solid var(--c-border);
        vertical-align: middle;
        transition: background .1s;
    }
    .students-table tbody tr:hover td {
        background: var(--c-surface-2);
    }
    .students-table tbody tr.row-dirty td {
        background: rgba(214, 40, 40, 0.06) !important;
    }
    .students-table input:not([type=hidden]),
    .students-table select {
        background: var(--c-surface-0) !important;
        border: 1px solid var(--c-border-strong) !important;
        color: var(--c-white) !important;
        border-radius: 4px !important;
        padding: 5px 8px !important;
        font-size: 0.82rem !important;
        outline: none !important;
        width: 100%;
        transition: border-color .2s;
    }
    .students-table input:focus,
    .students-table select:focus {
        border-color: var(--c-red) !important;
    }
    .students-table select option {
        background: var(--c-surface-2);
        color: var(--c-white);
    }

    .btn-sm {
        font-size: 0.78rem !important;
        padding: 5px 10px !important;
    }

    /* ── PAGINATION: shared .vm-pagination styles now live once in
       dashboard.css (loaded by layout.php), used by every paginated page. ── */

    #ajaxSpinner {
        display: none;
        padding: 30px;
        text-align: center;
        color: var(--c-text-faint);
    }

    /* ── STUDENT LIST: scrollable so pagination always stays on screen ── */
    #studentListScroll {
        overflow-y: auto;
        padding-right: 4px;
        scrollbar-width: thin;
        scrollbar-color: var(--c-border-strong) transparent;
    }
    #studentListScroll::-webkit-scrollbar { width: 4px; }
    #studentListScroll::-webkit-scrollbar-track { background: transparent; }
    #studentListScroll::-webkit-scrollbar-thumb { background: var(--c-border-strong); border-radius: 2px; }
    @media (max-width: 640px) {
        #studentListScroll { max-height: none !important; overflow-y: visible !important; }
    }

    /* ══════════════════════════════════════════════════════════
       LIGHT MODE
    ══════════════════════════════════════════════════════════ */
    html.pm-light .pic-section-header h2 { color: #111; }
    html.pm-light .pic-section-sub { color: #555; }

    html.pm-light .filter-card {
        background: #fff;
        border-color: var(--c-gray-200);
        box-shadow: 0 1px 4px rgba(0,0,0,0.06);
    }
    html.pm-light .filter-card-title { color: #888; }
    html.pm-light .pic-filter-bar label { color: #555; }
    html.pm-light .pic-filter-bar input,
    html.pm-light .pic-filter-bar select {
        background: var(--c-gray-50);
        border-color: var(--c-gray-300);
        color: #111;
    }
    html.pm-light .pic-filter-bar input::placeholder { color: var(--c-gray-400); }
    html.pm-light .pic-filter-bar select option {
        background: #fff;
        color: #111;
    }

    html.pm-light .pic-add-card {
        background: #fff;
        border-color: var(--c-red-border);
    }
    html.pm-light .pic-add-card h3 { color: #111; }
    html.pm-light .pic-add-form input,
    html.pm-light .pic-add-form select {
        background: var(--c-gray-50);
        border-color: var(--c-gray-300);
        color: #111;
    }
    html.pm-light .pic-add-form select option {
        background: #fff;
        color: #111;
    }
    html.pm-light .pic-add-form label { color: #555; }

    html.pm-light .accordion-card {
        background: #fff;
        border-color: var(--c-gray-200);
    }
    html.pm-light .school-header {
        background: var(--c-gray-100);
        border-bottom-color: var(--c-gray-200);
        color: #111;
    }
    html.pm-light .school-header:hover { background: #e9eaec; }
    html.pm-light .school-sticky-bar { background: var(--c-gray-100); }
    html.pm-light .school-sticky-bar .sticky-msg strong { color: #111; }

    html.pm-light .students-table th {
        background: var(--c-gray-700);
        color: var(--c-gray-50);
    }
    html.pm-light .students-table td {
        background: #fff;
        color: #222;
        border-bottom-color: var(--c-gray-200);
    }
    html.pm-light .students-table tbody tr:hover td { background: var(--c-gray-50); }
    html.pm-light .students-table tbody tr.row-dirty td {
        background: rgba(214, 40, 40, 0.05) !important;
    }
    html.pm-light .students-table input:not([type=hidden]),
    html.pm-light .students-table select {
        background: #fff !important;
        border-color: var(--c-gray-300) !important;
        color: #111 !important;
    }
    html.pm-light .students-table input:focus,
    html.pm-light .students-table select:focus {
        border-color: var(--c-red) !important;
        box-shadow: 0 0 0 2px var(--c-red-dim) !important;
    }
    html.pm-light .students-table select option {
        background: #fff;
        color: #111;
    }
</style>

<form id='masterSaveForm' method='POST'>
    <input type='hidden' name='action' value='save_all'>
    <input type='hidden' name='csrf_token' value='<?= htmlspecialchars($csrf) ?>'>
</form>

<form id='delStuFrm' method='POST' style='display:none;'>
    <input type='hidden' name='action' value='delete'>
    <input type='hidden' name='student_id' value=''>
    <input type='hidden' name='csrf_token' value='<?= htmlspecialchars($csrf) ?>'>
</form>

<?php $active_siri_main = (int)($_SESSION['active_siri_id'] ?? 0); ?>

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
                <div class="dd-trigger" id="ddTrigger_year" onclick="ddToggle('year')">
                    <span id="ddLabel_year" style="color:var(--c-text-faint);">Semua Tahun</span>
                    <span class="dd-arrow">▼</span>
                </div>
                <div class="dd-panel" id="ddPanel_year">
                    <div class="dd-search-box">
                        <input type="text" placeholder="Cari tahun..." oninput="ddFilter('year',this.value)" onclick="event.stopPropagation()">
                    </div>
                    <div class="dd-options" id="ddOpts_year">
                        <div class="dd-opt selected" data-value="" onclick="ddSelect('year','','Semua Tahun')">Semua Tahun</div>
                        <?php
                        $years = $conn->query("SELECT DISTINCT year FROM students ORDER BY year DESC");
                        while ($y = $years->fetch_assoc()) {
                            $yr = htmlspecialchars($y['year']);
                            echo "<div class='dd-opt' data-value='{$yr}' onclick=\"ddSelect('year','{$yr}','{$yr}')\">{$yr}</div>";
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
                <div class="dd-trigger" id="ddTrigger_siri" onclick="ddToggle('siri')">
                    <span id="ddLabel_siri" style="color:var(--c-text-faint);">-- Semua Siri --</span>
                    <span class="dd-arrow">▼</span>
                </div>
                <div class="dd-panel" id="ddPanel_siri">
                    <div class="dd-search-box">
                        <input type="text" placeholder="Cari siri..." oninput="ddFilter('siri',this.value)" onclick="event.stopPropagation()">
                    </div>
                    <div class="dd-options" id="ddOpts_siri">
                        <div class="dd-opt selected" data-value="" onclick="ddSelect('siri','','-- Semua Siri --')">-- Semua Siri --</div>
                        <?php
                        $siri_list = $conn->query("SELECT siri_id, siri_name FROM siri ORDER BY siri_year DESC, siri_name");
                        while ($sr = $siri_list->fetch_assoc()) {
                            $sid_  = $sr['siri_id'];
                            $sname = htmlspecialchars($sr['siri_name']);
                            echo "<div class='dd-opt' data-value='{$sid_}' onclick=\"ddSelect('siri','{$sid_}','{$sname}')\">{$sname}</div>";
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
                <div class="dd-trigger dd-trigger-disabled" id="ddTrigger_session" onclick="ddToggle('session')">
                    <span id="ddLabel_session" style="color:var(--c-text-faint);">-- Pilih Siri dahulu --</span>
                    <span class="dd-arrow">▼</span>
                </div>
                <div class="dd-panel" id="ddPanel_session">
                    <div class="dd-search-box">
                        <input type="text" placeholder="Cari sidang..." oninput="ddFilter('session',this.value)" onclick="event.stopPropagation()">
                    </div>
                    <div class="dd-options" id="ddOpts_session">
                        <div class="dd-opt selected" data-value="" onclick="ddSelect('session','','-- Semua Sidang --')">-- Semua Sidang --</div>
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
                <div class="dd-trigger" id="ddTrigger_gender" onclick="ddToggle('gender')">
                    <span id="ddLabel_gender" style="color:var(--c-text-faint);">Semua Jantina</span>
                    <span class="dd-arrow">▼</span>
                </div>
                <div class="dd-panel" id="ddPanel_gender">
                    <div class="dd-search-box">
                        <input type="text" placeholder="Cari jantina..." oninput="ddFilter('gender',this.value)" onclick="event.stopPropagation()">
                    </div>
                    <div class="dd-options" id="ddOpts_gender">
                        <div class="dd-opt selected" data-value="" onclick="ddSelect('gender','','Semua Jantina')">Semua Jantina</div>
                        <div class="dd-opt" data-value="Male" onclick="ddSelect('gender','Male','Lelaki')">Lelaki</div>
                        <div class="dd-opt" data-value="Female" onclick="ddSelect('gender','Female','Perempuan')">Perempuan</div>
                    </div>
                    <div class="dd-empty" id="ddEmpty_gender">Tiada hasil</div>
                </div>
                <input type="hidden" id="f_gender" value="">
            </div>
        </div>
        <div>
            <label>Nama Cawangan</label>
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
                        while ($sc = $schools_f->fetch_assoc()) {
                            $scid = $sc['school_id'];
                            $scname = htmlspecialchars($sc['school_name']);
                            echo "<div class='dd-opt' data-value='{$scid}' onclick=\"ddSelect('school','{$scid}','{$scname}')\">{$scname}</div>";
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
            <div>
                <label>Peringkat</label>
                <select name='level_id' required>
                    <?php
                    $lvls = $active_siri_main > 0
                        ? $conn->query("SELECT l.* FROM levels l JOIN sessions s ON l.session_id = s.session_id WHERE s.siri_id = $active_siri_main ORDER BY l.level_name")
                        : $conn->query("SELECT l.*, si.siri_name FROM levels l LEFT JOIN sessions s ON l.session_id = s.session_id LEFT JOIN siri si ON s.siri_id = si.siri_id ORDER BY l.level_name");
                    while ($l = $lvls->fetch_assoc()) {
                        $optLabel = htmlspecialchars($l['level_name']);
                        if ($active_siri_main === 0 && !empty($l['siri_name'])) {
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
        <div>
            <label>Nama Pelajar <span style="text-transform:none; font-weight:400; letter-spacing:0;">(boleh tambah lebih daripada satu — semuanya masuk peringkat &amp; cawangan yang sama di atas)</span></label>
            <div id='addRows_student'>
                <div class='add-row add-row-student'>
                    <input name='student_name[]' placeholder='Nama Penuh' required oninput="autoDetectGender(this.value, this.nextElementSibling)">
                    <select name='gender[]' class='student-gender-select'>
                        <option value='Male'>Lelaki</option>
                        <option value='Female'>Perempuan</option>
                    </select>
                    <button type='button' class='add-row-btn add-row-remove' onclick="removeStudentRow(this)" title='Buang baris'>&times;</button>
                </div>
            </div>
            <button type='button' class='add-row-btn add-row-add' onclick="addStudentRow()">+ Tambah Pelajar</button>
        </div>
        <div class='pic-add-form-actions'>
            <button class='pm-btn pm-btn-primary'>Tambah</button>
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

<script>
let _filterTimer = null;
let _dirtyMap = {};
let psSortNameDir = '';

// ── Sort ─────────────────────────────────────────────────────
function toggleSortNameStudents() {
    if (psSortNameDir === '') psSortNameDir = 'ASC';
    else if (psSortNameDir === 'ASC') psSortNameDir = 'DESC';
    else psSortNameDir = '';
    ajaxFilterNow();
}

// Debounced — used by the free-text search box, so it doesn't fire a
// request on every single keystroke.
function ajaxFilter() {
    clearTimeout(_filterTimer);
    _filterTimer = setTimeout(_doFilter, 400);
}

// Immediate — used by dropdowns/selects, since a discrete choice (unlike
// typing) never fires rapidly and shouldn't wait out the text-search debounce.
function ajaxFilterNow() {
    clearTimeout(_filterTimer);
    _doFilter();
}

function _doFilter() {
    const params = new URLSearchParams({
        ajax:      '1',
        search:    document.getElementById('f_search').value,
        year:      document.getElementById('f_year').value,
        gender:    document.getElementById('f_gender').value,
        siri:      document.getElementById('f_siri').value,
        session:   document.getElementById('f_session').value,
        school_id: document.getElementById('f_school').value,
        sort_name: psSortNameDir,
    });
    document.getElementById('ajaxSpinner').style.display = 'block';
    document.getElementById('studentList').style.opacity = '0.4';

    pmFetch('pic_students.php?' + params)
    .then(r => r.text())
    .then(html => {
        document.getElementById('studentList').innerHTML = html;
        document.getElementById('studentList').style.opacity = '1';
        document.getElementById('ajaxSpinner').style.display = 'none';
        _dirtyMap = {};
        attachDirtyListeners();
        // accordions start closed so bars start hidden — nothing to do here
        // but reset dirty count display
        document.querySelectorAll('.dirty-count').forEach(el => el.textContent = '0');
        studentsCurrentPage = 1;
        updateStudentsPagination();
        fitStudentListHeight();
    })
    .catch(() => {
        document.getElementById('studentList').style.opacity = '1';
        document.getElementById('ajaxSpinner').style.display = 'none';
    });
}

// ── PAGINATION (client-side, 20 school-accordions per page) ──
let studentsCurrentPage = 1;
const studentsPerPage = 20;

function updateStudentsPagination() {
    const cards = Array.from(document.querySelectorAll('#studentList > .accordion-card'));
    const container = document.getElementById('studentsPaginationContainer');
    const info = document.getElementById('studentsPageInfo');
    const btns = document.getElementById('studentsPaginationButtons');

    if (cards.length === 0) { container.style.display = 'none'; return; }

    const total = cards.length;
    const totalPages = Math.max(1, Math.ceil(total / studentsPerPage));
    if (studentsCurrentPage > totalPages) studentsCurrentPage = totalPages;
    if (studentsCurrentPage < 1) studentsCurrentPage = 1;

    container.style.display = totalPages <= 1 ? 'none' : 'flex';

    const start = (studentsCurrentPage - 1) * studentsPerPage;
    const end   = start + studentsPerPage;
    cards.forEach((c, i) => { c.style.display = (i >= start && i < end) ? '' : 'none'; });

    const s = start + 1;
    const e = Math.min(end, total);
    info.innerHTML = `Memaparkan <b>${s}–${e}</b> daripada <b>${total}</b> cawangan`;

    pmRenderPagination(btns, studentsCurrentPage, totalPages, studentsGoToPage);
}

function studentsGoToPage(page) {
    studentsCurrentPage = page;
    updateStudentsPagination();
}

function onRowChange(e) {
    const row = e.target.closest('tr[data-id]');
    if (!row) return;
    const rowId    = row.dataset.id;
    const table    = row.closest('table[data-school]');
    if (!table) return;
    const schoolId = table.dataset.school;

    if (!_dirtyMap[schoolId]) _dirtyMap[schoolId] = new Set();

    const changed = Array.from(row.querySelectorAll('input:not([type=hidden]), select'))
        .some(el => el.dataset.orig !== undefined && el.value !== el.dataset.orig);

    if (changed) { _dirtyMap[schoolId].add(rowId); row.classList.add('row-dirty'); }
    else         { _dirtyMap[schoolId].delete(rowId); row.classList.remove('row-dirty'); }

    updateSchoolBar(schoolId);
}

function updateSchoolBar(schoolId) {
    const count   = _dirtyMap[schoolId]?.size ?? 0;
    const bar     = document.getElementById('stickyBar_' + schoolId);
    const counter = bar?.querySelector('.dirty-count');
    if (!bar) return;
    bar.style.display = 'flex';
    if (counter) counter.textContent = count;
}

function saveSchool(schoolId) {
    const form  = document.getElementById('masterSaveForm');
    const table = document.querySelector(`table[data-school="${schoolId}"]`);
    const feedback = document.getElementById('stickyFeedback_' + schoolId);
    if (!table) return;

    const saveBtn = document.querySelector(`#stickyBar_${schoolId} .pm-btn-primary`);
    if (saveBtn) saveBtn.disabled = true;

    // A Cawangan change means this row belongs under a different accordion
    // after saving — the accordions are grouped by school server-side, so
    // patching values in place would leave the row stranded in the old
    // (now-wrong) cawangan's list until something reloads it.
    let schoolChanged = false;

    const params = new URLSearchParams();
    params.set('action', form.querySelector('[name=action]').value);
    params.set('csrf_token', form.querySelector('[name=csrf_token]').value);
    table.querySelectorAll('tbody tr[data-id]').forEach(row => {
        const id = row.dataset.id;
        ['student_name','level_id','school_id','gender','year'].forEach(key => {
            const el = document.querySelector(`[name="students[${id}][${key}]"]`);
            if (el) {
                params.set(`students[${id}][${key}]`, el.value);
                if (key === 'school_id' && el.dataset.orig !== undefined && el.value !== el.dataset.orig) {
                    schoolChanged = true;
                }
            }
        });
    });
    params.set('ajax', '1');

    pmFetch('pic_students.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            if (schoolChanged) {
                // Re-fetch the whole list so the moved student(s) render
                // under their new cawangan's accordion instead of lingering
                // in the old one — a full save+refetch is simplest here
                // since a school move can affect two accordions at once
                // (the old one loses a row, the new one gains it).
                spawnPmToast('✅ ' + data.msg + ' Pelajar telah dipindah ke cawangan baharu.', false);
                _doFilter();
                return;
            }
            // Bake the now-saved values in as the new "original" baseline so
            // Batal reverts to this state, not the pre-edit one, and clear
            // this school's dirty tracking.
            table.querySelectorAll('input:not([type=hidden]), select').forEach(el => {
                el.dataset.orig = el.value;
                el.closest('tr')?.classList.remove('row-dirty');
            });
            if (_dirtyMap[schoolId]) _dirtyMap[schoolId].clear();
            updateSchoolBar(schoolId);
        }
        if (feedback) {
            feedback.textContent = (data.ok ? '✅ ' : '⚠️ ') + data.msg;
            feedback.className = 'sticky-feedback show ' + (data.ok ? 'is-success' : 'is-error');
            setTimeout(() => feedback.classList.remove('show'), 3000);
        }
    })
    .catch(() => {
        if (feedback) {
            feedback.textContent = '⚠️ Ralat rangkaian. Sila cuba lagi.';
            feedback.className = 'sticky-feedback show is-error';
            setTimeout(() => feedback.classList.remove('show'), 3000);
        }
    })
    .finally(() => {
        if (saveBtn) saveBtn.disabled = false;
    });
}

function discardSchool(schoolId) {
    const table = document.querySelector(`table[data-school="${schoolId}"]`);
    if (!table) return;
    table.querySelectorAll('input:not([type=hidden]), select').forEach(el => {
        if (el.dataset.orig !== undefined) el.value = el.dataset.orig;
        el.closest('tr')?.classList.remove('row-dirty');
    });
    if (_dirtyMap[schoolId]) _dirtyMap[schoolId].clear();
    updateSchoolBar(schoolId);
}

function deleteStu(id) {
    if (!confirm('Padam pelajar ini?')) return;
    document.getElementById('delStuFrm').querySelector('[name=student_id]').value = id;
    document.getElementById('delStuFrm').submit();
}

function toggleBlock(id) {
    const el = document.getElementById(id);
    if (!el) return;
    const isOpen = el.style.display === 'block';
    el.style.display = isOpen ? 'none' : 'block';
    const card = el.closest('.accordion-card');
    const arrow = card?.querySelector('.school-header .arrow');
    if (arrow) arrow.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(90deg)';
    if (id === 'addStudent') setTimeout(fitStudentListHeight, 0);
}

// ── Add several students to one peringkat/cawangan/tahun in one submission ──
function addStudentRow() {
    const container = document.getElementById('addRows_student');
    const row = document.createElement('div');
    row.className = 'add-row add-row-student';

    const input = document.createElement('input');
    input.name = 'student_name[]';
    input.placeholder = 'Nama Penuh';
    input.required = true;

    const select = document.createElement('select');
    select.name = 'gender[]';
    select.className = 'student-gender-select';
    select.innerHTML = "<option value='Male'>Lelaki</option><option value='Female'>Perempuan</option>";

    input.addEventListener('input', () => autoDetectGender(input.value, select));

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'add-row-btn add-row-remove';
    btn.title = 'Buang baris';
    btn.innerHTML = '&times;';
    btn.onclick = () => removeStudentRow(btn);

    row.appendChild(input);
    row.appendChild(select);
    row.appendChild(btn);
    container.appendChild(row);
    input.focus();
}

function removeStudentRow(btn) {
    const container = document.getElementById('addRows_student');
    const row = btn.closest('.add-row-student');
    if (container.querySelectorAll('.add-row-student').length > 1) {
        row.remove();
    } else {
        row.querySelector('input').value = '';
        row.querySelector('select').value = 'Male';
    }
}

// Opens the "Tambah Pelajar Baru" form pre-set to a given cawangan, so
// adding another student to a school already expanded in the list doesn't
// require re-picking it from the Cawangan dropdown every time. Since the
// cawangan is already implied by which "+ Tambah" button was clicked, the
// dropdown itself is hidden — school_id still submits via the select's
// (now-set) value, it's just not shown as a redundant field to fill in.
function tambahForSchool(schoolId) {
    const el = document.getElementById('addStudent');
    if (el.style.display !== 'block') {
        toggleBlock('addStudent');
    }
    const schoolSelect = document.querySelector("#addStudent select[name='school_id']");
    if (schoolSelect) schoolSelect.value = schoolId;
    document.getElementById('schoolFieldWrap').style.display = 'none';
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    setTimeout(() => {
        document.querySelector("#addStudent input[name='student_name[]']")?.focus();
    }, 300);
}

// Opens the "Tambah Pelajar Baru" form from the page-level "+ Tambah
// Pelajar" button — unlike tambahForSchool, no cawangan is implied here,
// so the Cawangan field must be shown (undoing any hide left over from a
// previous per-school "+ Tambah" click).
function openGenericAddForm() {
    document.getElementById('schoolFieldWrap').style.display = '';
    toggleBlock('addStudent');
}

// "bin"/"binti" are the standard Malay patronymic markers, but PICs also
// commonly abbreviate them: "b"/"b." for bin, "bt"/"bt."/"bte"/"bte." for
// binti — auto-fill Jantina from whichever form appears so the PIC doesn't
// have to pick it manually for every student. Each marker must end the
// word (either a "." right after it, or a space/end-of-string) so it
// doesn't fire on a name that merely starts with the same letters (e.g.
// "Baharuddin", "Bakar") — a bare period with no following separator is
// still accepted since fathers' names are sometimes glued straight onto
// the marker ("Bt.Ahmad", "B.Ahmad"). Female markers are checked first
// since "bin" is a substring of "binti". Dispatches a real change event
// (rather than just setting .value) so the existing dirty-row tracking
// still picks up the auto-set gender as an unsaved change.
function autoDetectGender(name, selectEl) {
    if (!selectEl) return;
    let detected = null;
    if (/\b(?:binti|bte|bt)(?:\.|(?=\s|$))/i.test(name)) detected = 'Female';
    else if (/\b(?:bin|b)(?:\.|(?=\s|$))/i.test(name)) detected = 'Male';
    if (!detected || selectEl.value === detected) return;
    selectEl.value = detected;
    selectEl.dispatchEvent(new Event('change', { bubbles: true }));
}

function attachDirtyListeners() {
    document.querySelectorAll('.students-table input:not([type=hidden]), .students-table select').forEach(el => {
        if (el.dataset.orig === undefined) {
            el.dataset.orig = el.value;
            el.addEventListener('change', onRowChange);
            el.addEventListener('input',  onRowChange);
        }
    });
}

function ddToggle(name) {
    const trigger = document.getElementById('ddTrigger_' + name);
    if (trigger.classList.contains('dd-trigger-disabled')) return;
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

    if (name === 'siri') {
        loadSidangOptions(value);
    }
    ajaxFilterNow();
}

function loadSidangOptions(siriId) {
    const sessTrigger = document.getElementById('ddTrigger_session');
    const sessOpts = document.getElementById('ddOpts_session');
    const sessLbl = document.getElementById('ddLabel_session');

    document.getElementById('f_session').value = '';
    sessLbl.style.color = 'var(--c-text-faint)';

    if (!siriId) {
        sessLbl.textContent = '-- Pilih Siri dahulu --';
        sessOpts.innerHTML = "<div class='dd-opt selected' data-value='' onclick=\"ddSelect('session','','-- Semua Sidang --')\">-- Semua Sidang --</div>";
        sessTrigger.classList.add('dd-trigger-disabled');
        return;
    }
    sessLbl.textContent = '-- Semua Sidang --';
    sessOpts.innerHTML = "<div class='dd-opt selected' data-value='' onclick=\"ddSelect('session','','-- Semua Sidang --')\">-- Semua Sidang --</div>";
    sessTrigger.classList.remove('dd-trigger-disabled');

    pmFetch('pic_students.php?ajax_sessions=1&siri=' + encodeURIComponent(siriId))
        .then(r => r.json())
        .then(list => {
            list.forEach(s => {
                const opt = document.createElement('div');
                opt.className = 'dd-opt';
                opt.dataset.value = s.session_id;
                opt.textContent = s.session_name;
                opt.onclick = () => ddSelect('session', String(s.session_id), s.session_name);
                sessOpts.appendChild(opt);
            });
        })
        .catch(() => {});
}

document.addEventListener('click', e => {
    if (!e.target.closest('.dd-wrap')) {
        document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
        document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
    }
});

// ── Fit the student list + pagination into the viewport, no page scroll ──
function fitStudentListHeight() {
    if (window.innerWidth <= 640) {
        document.getElementById('studentListScroll').style.maxHeight = '';
        return;
    }
    const scrollEl   = document.getElementById('studentListScroll');
    const pagination = document.getElementById('studentsPaginationContainer');
    const top = scrollEl.getBoundingClientRect().top;
    const paginationH = pagination.offsetHeight;
    const available = window.innerHeight - top - paginationH - 24; // 24px bottom breathing room
    scrollEl.style.maxHeight = Math.max(150, available) + 'px';
}
window.addEventListener('resize', fitStudentListHeight);

document.addEventListener('DOMContentLoaded', () => {
    _doFilter();
    fitStudentListHeight();
});
</script>

</main>
</body>
</html>