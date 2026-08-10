<?php
ini_set('display_errors', 0); ini_set('display_startup_errors', 0);
error_reporting(E_ALL); ini_set('log_errors', 1);

session_start();
require __DIR__ . '/auth_check.php';
include 'db.php';
$conn = getDB();

// Add sort_order column to criteria if not exists — lets criteria be
// manually reordered per test instead of always showing alphabetically.
if (empty($_SESSION['pm_criteria_schema_checked'])) {
    $colCheck = $conn->query("SHOW COLUMNS FROM `criteria` LIKE 'sort_order'");
    if ($colCheck && $colCheck->num_rows == 0) {
        $conn->query("ALTER TABLE `criteria` ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0");
        $conn->query("UPDATE `criteria` SET `sort_order` = `criteria_id`");
    }
    $_SESSION['pm_criteria_schema_checked'] = true;
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pic') {
    header("Location: login.php"); exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token'])) {
        header("Location: pic_criteria.php?msg=Ralat+token+keselamatan.+Sila+muat+semula+halaman.&status=error"); exit();
    }
    session_write_close();
    $ok = false;

    try {
    if ($_POST['action'] === 'add') {
        // test_id[] is NOT NULL with a FOREIGN KEY to tests — bogus/non-numeric
        // values would otherwise coerce to 0 and hard-fail the FK constraint
        // (shown to the user as a scary generic error instead of a clean one).
        $testIdsRaw = $_POST['test_id'] ?? [];
        $testIds = [];
        foreach ((is_array($testIdsRaw) ? $testIdsRaw : [$testIdsRaw]) as $tid) {
            $tid = (int)$tid;
            if ($tid > 0) { $testIds[] = $tid; }
        }
        $testIds = array_values(array_unique($testIds));

        $namesRaw = $_POST['criteria_name'] ?? '';
        $names = [];
        foreach ((is_array($namesRaw) ? $namesRaw : [$namesRaw]) as $n) {
            $n = trim($n);
            if ($n !== '') { $names[] = $n; }
        }

        if (!empty($testIds) && !empty($names)) {
            $stmt = $conn->prepare("INSERT INTO criteria (test_id, criteria_name, sort_order) VALUES (?, ?, ?)");
            $ok = true;
            $inserted = 0;
            foreach ($testIds as $tid) {
                $order = (int)$conn->query("SELECT COALESCE(MAX(sort_order), -1) + 1 AS n FROM criteria WHERE test_id = $tid")->fetch_assoc()['n'];
                foreach ($names as $name) {
                    $stmt->bind_param('isi', $tid, $name, $order);
                    if (!$stmt->execute()) { $ok = false; }
                    $order++;
                    $inserted++;
                }
            }
            $stmt->close();
            $msg = $ok ? "{$inserted}+kriteria+berjaya+ditambah." : 'Ralat+menambah+kriteria.+Sila+cuba+lagi.';
        } else {
            $msg = empty($testIds) ? 'Sila+pilih+sekurang-kurangnya+satu+ujian.' : 'Sila+masukkan+sekurang-kurangnya+satu+nama+kriteria.';
        }
    } elseif ($_POST['action'] === 'delete') {
        $stmt = $conn->prepare("DELETE FROM criteria WHERE criteria_id=?");
        $stmt->bind_param('i', $_POST['criteria_id']);
        $ok = $stmt->execute();
        $stmt->close();
        $msg = $ok ? 'Kriteria+berjaya+dipadam.' : 'Ralat+memadam+kriteria.+Sila+cuba+lagi.';
    } elseif ($_POST['action'] === 'save_all' && ((isset($_POST['criteria']) && is_array($_POST['criteria'])) || isset($_POST['order']))) {
        $ok = true;
        $anyInvalid = false;
        if (isset($_POST['criteria']) && is_array($_POST['criteria'])) {
            $stmt = $conn->prepare("UPDATE criteria SET criteria_name=? WHERE criteria_id=?");
            foreach ($_POST['criteria'] as $id => $c) {
                if (!is_array($c)) { $anyInvalid = true; continue; }
                $name = trim($c['criteria_name'] ?? '');
                $id = (int)$id;
                if ($name === '') { $anyInvalid = true; continue; }
                $stmt->bind_param('si', $name, $id);
                if (!$stmt->execute()) { $ok = false; }
            }
            $stmt->close();
        }
        if (isset($_POST['order'])) {
            $stmt = $conn->prepare("UPDATE criteria SET sort_order=? WHERE criteria_id=?");
            foreach ($_POST['order'] as $testCriteriaIds) {
                foreach ($testCriteriaIds as $pos => $cid) {
                    $pos = (int)$pos;
                    $cid = (int)$cid;
                    $stmt->bind_param('ii', $pos, $cid);
                    if (!$stmt->execute()) { $ok = false; }
                }
            }
            $stmt->close();
        }
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
    header("Location: pic_criteria.php?msg={$msg}&status=" . ($ok ? 'success' : 'error')); exit();
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

// ── AJAX: return tests (ujian) list scoped to a level — used by the
// Tambah Kriteria form so "Pilih Ujian" only lists tests under the chosen
// peringkat instead of every test in the system. ──
if (isset($_GET['ajax_tests_for_level'])) {
    header('Content-Type: application/json');
    $lvlId = (int)($_GET['level'] ?? 0);
    $out = [];
    if ($lvlId > 0) {
        $stmt = $conn->prepare("SELECT test_id, test_name FROM tests WHERE level_id = ? ORDER BY sort_order, test_name");
        $stmt->bind_param('i', $lvlId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $out[] = $row;
        $stmt->close();
    }
    echo json_encode($out);
    exit();
}

// ── AJAX rows ─────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    $search  = $_GET['search'] ?? '';
    $level   = $_GET['level']  ?? '';
    $testF   = $_GET['test']   ?? '';
    $siriF     = $_GET['siri']    ?? '';
    $sessionF  = $_GET['session'] ?? '';

    // Scope to "Siri Aktif" — both queries below (and the merged $iterArr
    // copy further down) always have "l" (levels) joined, so filter via
    // l.session_id rather than a sessions-table alias that isn't always present.
    $active_siri = (int)($_SESSION['active_siri_id'] ?? 0);

    // Filters against the outer test/level scope. Kept separate from the
    // criteria-name search below so the test list query can be rooted at
    // `tests` (LEFT-joinable) instead of `criteria` — otherwise a test with
    // zero criteria could never appear at all (INNER JOIN from criteria).
    $tWhereArr = ["1=1"]; $tTypes = ''; $tVals = [];
    if ($testF  !== '') { $tWhereArr[] = "t.test_id = ?";           $tTypes .= 'i'; $tVals[] = (int)$testF;    }
    if ($level  !== '') { $tWhereArr[] = "l.level_id = ?";          $tTypes .= 'i'; $tVals[] = (int)$level;    }
    if ($sessionF !== '') { $tWhereArr[] = "l.session_id = ?";      $tTypes .= 'i'; $tVals[] = (int)$sessionF; }
    elseif ($siriF !== '') { $tWhereArr[] = "l.session_id IN (SELECT session_id FROM sessions WHERE siri_id = ?)"; $tTypes .= 'i'; $tVals[] = (int)$siriF; }
    elseif ($active_siri > 0) { $tWhereArr[] = "l.session_id IN (SELECT session_id FROM sessions WHERE siri_id = ?)"; $tTypes .= 'i'; $tVals[] = $active_siri; }
    $tWhereSql = implode(" AND ", $tWhereArr);

    $stmt = $conn->prepare("
        SELECT DISTINCT t.test_id, t.test_name, l.level_id, l.level_name, s.session_name, si.siri_name
        FROM tests t
        JOIN levels l ON t.level_id=l.level_id
        LEFT JOIN sessions s ON l.session_id = s.session_id
        LEFT JOIN siri si ON s.siri_id = si.siri_id
        WHERE $tWhereSql
        ORDER BY l.sort_order, l.level_name, t.sort_order, t.test_name
    ");

    if ($tTypes) $stmt->bind_param($tTypes, ...$tVals);
    $stmt->execute();
    $tests_list = $stmt->get_result();
    $stmt->close();

    $found = false;

    // Criteria filters reuse the same test/level scoping plus the
    // criteria-name search (only meaningful once criteria rows exist).
    $cWhereArr = $tWhereArr; $cTypes = $tTypes; $cVals = $tVals;
    if ($search !== '') { $cWhereArr[] = "c.criteria_name LIKE ?"; $cTypes .= 's'; $cVals[] = '%'.$search.'%'; }
    $cWhereSql = implode(" AND ", $cWhereArr);

    // Fetch ALL matching criteria in one query and group by test_id, instead
    // of running a separate "criteria for this test" query per test in the
    // loop below — that used to re-hit the DB once per test row.
    $stmtAll = $conn->prepare("
        SELECT c.*
        FROM criteria c
        JOIN tests t ON c.test_id=t.test_id
        JOIN levels l ON t.level_id=l.level_id
        WHERE $cWhereSql
        ORDER BY c.test_id, c.sort_order, c.criteria_name
    ");
    if ($cTypes) $stmtAll->bind_param($cTypes, ...$cVals);
    $stmtAll->execute();
    $allCriteriaRes = $stmtAll->get_result();
    $stmtAll->close();
    $criteriaByTest = [];
    while ($c = $allCriteriaRes->fetch_assoc()) {
        $criteriaByTest[$c['test_id']][] = $c;
    }

    // Group tests by their peringkat first, so the accordion nests as
    // Peringkat > Ujian > Kriteria instead of one flat "Level – Test" row
    // per ujian (which repeated the peringkat name in every header).
    $levelsData = [];
    while ($t = $tests_list->fetch_assoc()) {
        $lid = $t['level_id'];
        if (!isset($levelsData[$lid])) {
            $levelsData[$lid] = [
                'level_name'   => $t['level_name'],
                'siri_name'    => $t['siri_name'],
                'session_name' => $t['session_name'],
                'tests'        => [],
            ];
        }
        $levelsData[$lid]['tests'][] = $t;
    }

    foreach ($levelsData as $lid => $lvlInfo) {
        // With an active criteria-name search, hide ujian (and, if every
        // ujian under it is hidden, the whole peringkat) that have no
        // matching criteria. Otherwise show everything — including ujian
        // with zero criteria — so PICs can see (and add to) an empty one.
        $visibleTests = [];
        foreach ($lvlInfo['tests'] as $t) {
            $criteriaRows = $criteriaByTest[$t['test_id']] ?? [];
            if (empty($criteriaRows) && $search !== '') { continue; }
            $visibleTests[] = ['t' => $t, 'criteria' => $criteriaRows];
        }
        if (empty($visibleTests)) { continue; }
        $found = true;

        $totalCriteria = 0;
        foreach ($visibleTests as $vt) { $totalCriteria += count($vt['criteria']); }
        $siriLabel   = !empty($lvlInfo['siri_name'])    ? htmlspecialchars($lvlInfo['siri_name'])    : '— Tiada Siri —';
        $sidangLabel = !empty($lvlInfo['session_name']) ? htmlspecialchars($lvlInfo['session_name']) : '— Tiada Sidang —';

        echo "<div class='accordion-card'>
                <div class='level-header' onclick=\"toggleBlock('level_$lid', this)\">
                    <span class='acc-icon'>&#9658;</span>
                    <strong>" . htmlspecialchars($lvlInfo['level_name']) . "</strong>
                    <div class='header-badges'>
                        <span class='badge-siri' title='Siri'><svg viewBox=\"0 0 24 24\" xmlns=\"http://www.w3.org/2000/svg\"><rect x=\"3\" y=\"4\" width=\"18\" height=\"18\" rx=\"2\" ry=\"2\"/><line x1=\"16\" y1=\"2\" x2=\"16\" y2=\"6\"/><line x1=\"8\" y1=\"2\" x2=\"8\" y2=\"6\"/><line x1=\"3\" y1=\"10\" x2=\"21\" y2=\"10\"/></svg> {$siriLabel}</span>
                        <span class='badge-sidang' title='Sidang'><svg viewBox=\"0 0 24 24\" xmlns=\"http://www.w3.org/2000/svg\"><path d=\"M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z\"/></svg> {$sidangLabel}</span>
                        <span class='count-badge'>" . count($visibleTests) . " Ujian &middot; {$totalCriteria} Kriteria</span>
                    </div>
                </div>
                <div id='level_$lid' class='acc-body' style='display:none;'>";

        foreach ($visibleTests as $vt) {
            $t = $vt['t'];
            $criteriaRows = $vt['criteria'];
            $tid = $t['test_id'];
            $criteriaCount = count($criteriaRows);

            echo "<div class='accordion-card accordion-sub'>
                    <div class='level-header level-header-sub' onclick=\"toggleBlock('test_$tid', this)\">
                        <span class='acc-icon'>&#9658;</span>
                        <strong>" . htmlspecialchars($t['test_name']) . "</strong>
                        <div class='header-badges'>
                            <span class='count-badge'>{$criteriaCount} Kriteria</span>
                        </div>
                    </div>
                    <div id='test_$tid' class='acc-body' style='display:none;'>
                        <div class='table-responsive'>
                            <table class='tests-table' data-test='$tid'>
                                <thead>
                                    <tr>
                                        <th style='width:36px;'></th>
                                        <th style='width:50px; text-align:center;'>No</th>
                                        <th>Nama Kriteria</th>
                                        <th style='width:100px; text-align:center;'>Tindakan</th>
                                    </tr>
                                </thead>
                                <tbody>";
            $no = 1;
            foreach ($criteriaRows as $c) {
                $cid = $c['criteria_id'];
                $safeName = htmlspecialchars($c['criteria_name'], ENT_QUOTES);

                echo "<tr data-id='$cid' draggable='true'>
                        <td class='col-drag' style='text-align:center; cursor:grab; color:var(--c-text-faint);'>&#9776;</td>
                        <td class='col-no' style='text-align:center; color:var(--c-text-faint);'>$no</td>
                        <td><input name='criteria[$cid][criteria_name]' value='$safeName' data-orig='$safeName' class='criteria-input'></td>
                        <td class='col-actions' style='text-align:center;'>
                            <button type='button' class='pm-btn pm-btn-danger btn-sm' style='padding:5px 12px; font-size:0.78rem;' onclick=\"delRow('$cid')\">Padam</button>
                        </td>
                      </tr>";
                $no++;
            }
            if ($no === 1) {
                echo "<tr class='row-empty'><td colspan='4' style='text-align:center;padding:16px;color:var(--c-text-faint);'>Tiada kriteria lagi untuk ujian ini.</td></tr>";
            }
            echo "              </tbody>
                            </table>
                        </div>
                    </div>
                  </div>";
        }

        echo "      </div>
              </div>";
    }

    if (!$found) {
        echo "<p style='text-align:center;padding:30px;color:var(--c-text-faint);'>Tiada kriteria ditemui untuk carian ini.</p>";
    }
    exit();
}

$pm_page = 'criteria';
include 'layout.php';
?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
    /* ── Filter card ── */
    .filter-card{
        background:var(--c-surface-1);
        border:1px solid var(--c-border-strong);
        border-radius:12px;
        padding:20px;
        margin-bottom:24px;
        box-shadow:0 4px 12px rgba(0,0,0,.08);
    }

    .filter-card-title{
        font-size:.72rem;
        font-weight:700;
        text-transform:uppercase;
        letter-spacing:.12em;
        color:var(--c-text-faint);
        margin-bottom:16px;
    }

    .pic-filter-bar{
        display:flex; /* Same flex-equal-share layout as leaderboard.php's
           .filter-bar/.filter-col and pic_directory.php's .dir-filter —
           every column gets an identical flex-basis of 0 so widths stay
           equal, instead of the old grid's minmax(Npx,1fr) tracks which
           differed page to page. */
        flex-wrap:wrap;
        gap:8px;
        align-items:flex-end;
    }

    .pic-filter-bar > div{
        flex:1 1 0;
        min-width:110px;
    }
    @media (max-width: 640px) {
        .pic-filter-bar { gap: 10px; }
        .pic-filter-bar > div { flex: 1 1 100%; min-width: 0; }
    }
    @media (min-width: 641px) and (max-width: 1024px) {
        .pic-filter-bar > div { flex: 1 1 calc(50% - 8px); }
    }

    .pic-filter-bar label{
        display:block;
        margin-bottom:6px;
        font-size:.75rem;
        font-weight:600;
        text-transform:uppercase;
        letter-spacing:.08em;
        color:var(--c-text-faint);
    }
    .pic-filter-bar input,
    .pic-filter-bar select {
        background: var(--c-surface-2);
        border: 1px solid var(--c-border-strong);
        color: var(--c-white);
        border-radius: 6px;
        padding: 8px 10px;
        height: 32px; /* Matches the app-wide 32px dropdown/input benchmark
           (leaderboard.php's Select2, pic_directory.php's .dd-trigger) —
           the raw <select> here is hidden and re-skinned by Select2 at
           runtime (whose own height is pinned globally in filter_bar.css),
           but this keeps the pre-JS/no-JS fallback height honest too. */
        font-size: 0.8rem; /* matches the same 32px/0.8rem benchmark */
        outline: none;
        width: 100%;
        transition: border-color .2s;
        box-sizing: border-box;
    }
    .pic-filter-bar input:focus,
    .pic-filter-bar select:focus { border-color: var(--c-red); }
    .pic-filter-bar select option { background: var(--c-surface-2); color: var(--c-white); }
    .pic-filter-bar input::placeholder { color: var(--c-text-faint); }

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
        grid-template-columns: 1fr 2fr auto;
        gap: 10px;
        align-items: end;
    }
    @media (max-width: 768px) {
        .pic-add-form { grid-template-columns: 1fr; }
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
        transition: border-color .2s;
    }
    .pic-add-form input:focus,
    .pic-add-form select:focus { border-color: var(--c-red); box-shadow: 0 0 0 3px var(--c-red-dim); }
    .pic-add-form select option { background: var(--c-surface-2); }
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

    /* ── Multi-row "add several at once" input group (used by Nama Kriteria) ── */
    .pic-add-form--multi {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }
    .pic-add-form--multi > div { width: 100%; }
    .add-row { display: flex; gap: 8px; margin-bottom: 8px; }
    .add-row:last-child { margin-bottom: 0; }
    .add-row input, .add-row select { flex: 1; min-width: 0; }
    /* Select2 hides the native <select> and renders its own container next
       to it — flex the visible container instead, so a Select2-based row
       (Pilih Ujian) sizes the same as a plain-input row (Nama Kriteria). */
    .add-row .select2-container { flex: 1; min-width: 0; }
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

    /* ── Main card & Accordions ── */
    .tests-card {
        background: var(--c-surface-1);
        border: 1px solid var(--c-border-strong);
        border-radius: 10px;
        overflow: hidden;
        position: relative;
        margin-top:20px;
    }

    /* Save bar — always visible */
    .tests-save-bar {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 16px;
        background: var(--c-surface-2);
        border-bottom: 2px solid var(--c-border-strong);
        position: sticky;
        top: 0;
        z-index: 10;
        transition: border-bottom-color .25s;
    }
    .tests-save-bar.dirty { border-bottom-color: var(--c-red); }
    .tests-save-bar .save-msg { font-size: 0.82rem; color: var(--c-text-faint); flex: 1; }
    .tests-save-bar .save-msg strong { color: var(--c-white); }
    /* Box styling now comes from the shared .pm-btn.pm-btn-ghost classes on
       the element itself, matching Simpan's box model instead of a bespoke
       one-off that had no visible border (unlike Simpan's), making it read
       as a different size/weight. Only the dirty-state toggle stays here. */
    .tests-save-bar .btn-discard {
        display: none;
    }
    .tests-save-bar.dirty .btn-discard { display: inline-flex; }

    /* Scrollable Container — height set dynamically in JS
       (fitCriteriaListHeight) so it always leaves room for the pagination
       bar below it instead of a static max-height that goes stale whenever
       the filter UI above the list changes height. */
    .tests-table-wrap {
        overflow-y: auto;
        overflow-x: hidden;
        scrollbar-width: thin;
        scrollbar-color: var(--c-surface-3) transparent;
        padding: 12px;
    }
    @media (max-width: 640px) {
        .tests-table-wrap { max-height: none !important; overflow-y: visible !important; }
    }

    /* Accordion Style */
    .accordion-card {
        background: var(--c-surface-1);
        border: 1px solid var(--c-border);
        border-radius: 8px;
        margin-bottom: 8px;
        overflow: hidden;
        transition: border-color 0.2s;
    }
    .accordion-card.has-changes {
        border-color: var(--c-red);
        box-shadow: 0 0 0 1px var(--c-red);
    }
    /* Nested Ujian accordion inside a Peringkat accordion — indented and a
       touch smaller so the hierarchy (Peringkat > Ujian > Kriteria) reads
       clearly instead of looking like another top-level card. */
    #criteriaList > .accordion-card > .acc-body { padding: 10px 10px 10px 20px; }
    .accordion-sub {
        margin-bottom: 6px;
        border-color: var(--c-border);
    }
    .accordion-sub:last-child { margin-bottom: 0; }
    .level-header-sub {
        padding: 10px 14px;
        font-size: 0.85rem;
        background: var(--c-surface-1);
    }
    .level-header-sub:hover { background: var(--c-surface-2); }
    html.pm-light .level-header-sub { background: #fff; }
    html.pm-light .level-header-sub:hover { background: var(--c-gray-50); }
    /* Display peringkat/ujian/kriteria names in caps — accordion titles
       (level-header covers both the top-level Peringkat and, via
       level-header-sub, the nested Ujian), the editable Nama Kriteria
       field, and the Peringkat/Ujian dropdowns (Select2 renders its own
       DOM next to the hidden <select>, so target that instead) — purely
       visual, the stored value keeps whatever case was typed. */
    .level-header strong,
    .criteria-input,
    #f_level + .select2-container .select2-selection__rendered,
    #f_test + .select2-container .select2-selection__rendered,
    #addCriteriaLevel + .select2-container .select2-selection__rendered,
    .addUjianSelect + .select2-container .select2-selection__rendered {
        text-transform: uppercase;
    }
    /* The open dropdown/search-results list is rendered by Select2 into a
       floating panel outside the select's own DOM, so it can't be reached
       via a sibling selector — target its generated results-list id
       instead (Select2 names it "select2-<select id>-results"). The
       addUjianSelect_N rows get incrementing ids in JS specifically so
       this attribute-selector pattern can match all of them. */
    #select2-f_level-results .select2-results__option,
    #select2-f_test-results .select2-results__option,
    #select2-addCriteriaLevel-results .select2-results__option,
    [id^="select2-addUjianSelect_"][id$="-results"] .select2-results__option {
        text-transform: uppercase;
    }
    .level-header {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 16px;
        background: var(--c-surface-2);
        cursor: pointer;
        color: var(--c-white);
        font-weight: 600;
        font-size: 0.92rem;
        transition: background .15s;
    }
    .level-header:hover { background: var(--c-surface-3); }
    .level-header .acc-icon {
        color: var(--c-text-muted);
        font-size: 0.8rem;
        transition: transform .2s ease;
        display: inline-block;
    }
    .level-header .header-badges {
        margin-left: auto;
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .level-header .count-badge {
        background: var(--c-surface-0);
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.75rem;
        color: var(--c-text-muted);
        border: 1px solid var(--c-border);
        white-space: nowrap;
    }
    .level-header .badge-siri,
    .level-header .badge-sidang {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.72rem;
        font-weight: 600;
        white-space: nowrap;
        background: var(--c-surface-0);
        border: 1px solid var(--c-border);
    }
    /* Uniform stroke-icon sizing (both badges use the same icon set as the
       rest of the app — see pm_icon() in layout.php — so Siri/Sidang render
       at identical size/weight instead of mismatched OS emoji). */
    .level-header .badge-siri svg,
    .level-header .badge-sidang svg {
        width: 12px;
        height: 12px;
        flex-shrink: 0;
        fill: none;
        stroke: currentColor;
        stroke-width: 2;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    .level-header .badge-siri {
        color: var(--c-red);
        border-color: var(--c-red-border);
    }
    .level-header .badge-sidang {
        color: var(--c-text-muted);
        border-color: var(--c-border-strong);
    }

    /* ── Table inside Accordion ── */
    .table-responsive { width: 100%; overflow-x: auto; }
    .tests-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.875rem;
        min-width: 400px;
    }
    .tests-table thead th {
        background: var(--c-surface-3);
        color: var(--c-text-muted);
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        padding: 10px 14px;
        text-align: left;
        white-space: nowrap;
        border-bottom: 1px solid var(--c-border-strong);
    }
    .tests-table td {
        padding: 8px 14px;
        color: var(--c-text-muted);
        background: var(--c-surface-1);
        border-bottom: 1px solid var(--c-border);
        vertical-align: middle;
        transition: background .1s;
    }
    .tests-table tbody tr:last-child td { border-bottom: none; }
    .tests-table tbody tr:hover td { background: var(--c-surface-2); }
    .tests-table tbody tr.row-dirty td { background: rgba(214, 40, 40, 0.06) !important; }
    .tests-table tbody tr.dragging { opacity: 0.4; }
    .tests-table td.col-drag { width: 36px; cursor: grab; user-select: none; }
    .tests-table td.col-drag:active { cursor: grabbing; }
    .tests-table td.col-no { width: 50px; }
    .tests-table td.col-actions { width: 100px; white-space: nowrap; }
    .tests-table input {
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
    .tests-table input:focus { border-color: var(--c-red) !important; }

    #ajaxSpinner { display: none; padding: 30px; text-align: center; color: var(--c-text-faint); margin: 0; }

    /* ── PAGINATION: shared .vm-pagination styles now live once in
       dashboard.css (loaded by layout.php), used by every paginated page. ── */

    /* ── LIGHT MODE ── */
    html.pm-light .pic-section-header h2 { color: #111; }
    html.pm-light .pic-section-sub { color: #555; }
    html.pm-light .filter-card { background: #fff; border-color: var(--c-gray-200); border-top-color: var(--c-red); box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
    html.pm-light .filter-card-title { color: #888; }
    html.pm-light .pic-filter-bar label { color: #555; }
    html.pm-light .pic-filter-bar input, html.pm-light .pic-filter-bar select { background: var(--c-gray-50); border-color: var(--c-gray-300); color: #111; }
    html.pm-light .pic-filter-bar input::placeholder { color: var(--c-gray-400); }
    html.pm-light .pic-filter-bar select option { background: #fff; color: #111; }
    html.pm-light .pic-add-card { background: #fff; border-color: var(--c-red-border); }
    html.pm-light .pic-add-card h3 { color: #111; }
    html.pm-light .pic-add-form label { color: #555; }
    html.pm-light .pic-add-form input, html.pm-light .pic-add-form select { background: var(--c-gray-50); border-color: var(--c-gray-300); color: #111; }
    html.pm-light .pic-add-form select option { background: #fff; }
    html.pm-light .tests-card { background: #fff; border-color: var(--c-gray-200); }
    html.pm-light .tests-save-bar { background: var(--c-gray-100); border-bottom-color: var(--c-gray-200); }
    html.pm-light .tests-save-bar.dirty { border-bottom-color: var(--c-red); }
    html.pm-light .tests-save-bar .save-msg { color: #555; }
    html.pm-light .tests-save-bar .save-msg strong { color: #111; }
    html.pm-light .accordion-card { background: #fff; border-color: var(--c-gray-200); }
    html.pm-light .level-header { background: var(--c-gray-50); color: #111; }
    html.pm-light .level-header:hover { background: var(--c-gray-100); }
    html.pm-light .level-header .count-badge { background: #fff; color: #555; border-color: var(--c-gray-300); }
    html.pm-light .level-header .badge-siri { background: #fff; border-color: var(--c-red-300); }
    html.pm-light .level-header .badge-sidang { background: #fff; color: #555; border-color: var(--c-gray-300); }
    html.pm-light .tests-table thead th { background: var(--c-gray-700); color: var(--c-gray-50); border-bottom-color: var(--c-gray-200); }
    html.pm-light .tests-table td { background: #fff; color: #222; border-bottom-color: var(--c-gray-200); }
    html.pm-light .tests-table tbody tr:hover td { background: var(--c-gray-50); }
    html.pm-light .tests-table tbody tr.row-dirty td { background: rgba(214, 40, 40, 0.04) !important; }
    html.pm-light .tests-table input { background: #fff !important; border-color: var(--c-gray-300) !important; color: #111 !important; }
    html.pm-light .tests-table input:focus { border-color: var(--c-red) !important; box-shadow: 0 0 0 2px var(--c-red-dim) !important; }

    /* .dd-wrap / .dd-trigger / .dd-panel and the Select2 theme now come
       from the shared filter_bar.css (loaded globally via layout.php) so
       this page's filter dropdowns match pic_view_marks.php / silibus.php
       / leaderboard.php / judge_view_marks.php exactly instead of
       drifting with their own local (taller) size. */
</style>

<form id='saveAllForm' method='POST' style='display:none;'>
    <input type='hidden' name='action' value='save_all'>
    <input type='hidden' name='csrf_token' value='<?= htmlspecialchars($csrf) ?>'>
</form>
<form id='delFrm' method='POST' style='display:none;'>
    <input type='hidden' name='action' value='delete'>
    <input type='hidden' name='criteria_id' value=''>
    <input type='hidden' name='csrf_token' value='<?= htmlspecialchars($csrf) ?>'>
</form>

<div class='pic-section-header'>
    <div>
        <h2>📋 Pengurusan Kriteria</h2>
        <div class='pic-section-sub'>Kriteria pecahan markah bagi setiap ujian</div>
    </div>
    <button type='button' class='pm-btn pm-btn-primary' onclick="toggleAddCard()">+ Tambah Kriteria</button>
</div>

<?php if (isset($_GET['msg'])):
    $isError = ($_GET['status'] ?? '') === 'error';
    // Shown as a floating toast (spawnPmToast, shared in layout.php), not an
    // inline block at the top of the page — the accordion open/scroll
    // restore below already scrolls back to whichever card was being
    // edited, so a static banner up here would be scrolled out of view.
?>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        spawnPmToast(<?= json_encode($_GET['msg']) ?>, <?= $isError ? 'true' : 'false' ?>);
    });
</script>
<?php endif; ?>

<div class="filter-card">
    <div class="filter-card-title">
        Penapis Kriteria
    </div>

    <div class="pic-filter-bar">

        <div>
            <label>Siri</label>
            <select id="f_siri" class="select-search" onchange="onSiriChange()">
                <option value="">-- Semua Siri --</option>
                <?php
                $siri_list_f = $conn->query("SELECT siri_id, siri_name FROM siri ORDER BY siri_year DESC, siri_name");
                while ($sr = $siri_list_f->fetch_assoc()) {
                    echo "<option value='{$sr['siri_id']}'>" . htmlspecialchars($sr['siri_name']) . "</option>";
                }
                ?>
            </select>
        </div>

        <div>
            <label>Sidang</label>
            <select id="f_session" class="select-search" onchange="ajaxFilter()">
                <option value="">-- Semua Sidang --</option>
            </select>
        </div>

        <div>
            <label>Peringkat</label>
            <select id="f_level" class="select-search" onchange="onLevelFilterChange()">
                <option value="">-- Semua Peringkat --</option>
                <?php
                // Scope to "Siri Aktif"; tag levels/tests with their siri when
                // "Semua Siri" is active so same-named levels aren't ambiguous.
                $active_siri_main = (int)($_SESSION['active_siri_id'] ?? 0);
                $lvls = $active_siri_main > 0
                    ? $conn->query("SELECT l.* FROM levels l JOIN sessions s ON l.session_id = s.session_id WHERE s.siri_id = $active_siri_main ORDER BY l.sort_order, l.level_name")
                    : $conn->query("SELECT l.*, si.siri_name FROM levels l LEFT JOIN sessions s ON l.session_id = s.session_id LEFT JOIN siri si ON s.siri_id = si.siri_id ORDER BY l.sort_order, l.level_name");
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

        <div>
            <label>Ujian</label>
            <select id="f_test" class="select-search" onchange="ajaxFilter()">
                <option value="">-- Semua Ujian --</option>
                <?php
                $tests_all = $active_siri_main > 0
                    ? $conn->query("SELECT t.test_id, t.test_name, l.level_name FROM tests t JOIN levels l ON t.level_id=l.level_id JOIN sessions s ON l.session_id = s.session_id WHERE s.siri_id = $active_siri_main ORDER BY l.sort_order, l.level_name, t.sort_order, t.test_name")
                    : $conn->query("SELECT t.test_id, t.test_name, l.level_name, si.siri_name FROM tests t JOIN levels l ON t.level_id=l.level_id LEFT JOIN sessions s ON l.session_id = s.session_id LEFT JOIN siri si ON s.siri_id = si.siri_id ORDER BY l.sort_order, l.level_name, t.sort_order, t.test_name");
                while ($t = $tests_all->fetch_assoc()) {
                    $name = htmlspecialchars($t['level_name']) . " - " . htmlspecialchars($t['test_name']);
                    if ($active_siri_main === 0 && !empty($t['siri_name'])) {
                        $name .= " — " . htmlspecialchars($t['siri_name']);
                    }
                    echo "<option value='{$t['test_id']}'>{$name}</option>";
                }
                ?>
            </select>
        </div>

        <div>
            <label>Cari Kriteria</label>
            <select id="f_search" class="select-search" onchange="ajaxFilter()">
                <option value="">-- Semua Kriteria --</option>
                <?php
                $crit = $conn->query("SELECT DISTINCT criteria_name FROM criteria ORDER BY criteria_name");
                while ($c = $crit->fetch_assoc()) {
                    $name = htmlspecialchars($c['criteria_name']);
                    echo "<option value='{$name}'>{$name}</option>";
                }
                ?>
            </select>
        </div>

    </div>
</div>

<div id='addCriteria' class='pic-add-card'>
    <h3>+ Tambah Kriteria Baru</h3>
    <form method='POST' class='pic-add-form pic-add-form--multi' onsubmit="rememberOpenAccordions()">
        <input type='hidden' name='action' value='add'>
        <input type='hidden' name='csrf_token' value='<?= htmlspecialchars($csrf) ?>'>
        <div>
            <label>Pilih Peringkat</label>
            <select id='addCriteriaLevel' class="select-search" onchange="loadUjianForPeringkat(this.value)" required>
                <option value="">-- Pilih Peringkat --</option>
                <?php
                // Unlike the filter bar / listing (intentionally scoped to
                // "Siri Aktif" so PICs aren't distracted by other siri while
                // browsing), adding a criteria should let a PIC reach every
                // peringkat that exists — so this one is never siri-scoped.
                $lvls_add = $conn->query("SELECT l.*, si.siri_name FROM levels l LEFT JOIN sessions s ON l.session_id = s.session_id LEFT JOIN siri si ON s.siri_id = si.siri_id ORDER BY l.sort_order, l.level_name");
                while ($l = $lvls_add->fetch_assoc()) {
                    $optLabel = htmlspecialchars($l['level_name']);
                    if (!empty($l['siri_name'])) {
                        $optLabel .= " — " . htmlspecialchars($l['siri_name']);
                    }
                    echo "<option value='{$l['level_id']}'>$optLabel</option>";
                }
                ?>
            </select>
        </div>
        <div>
            <label>Pilih Ujian <span style="text-transform:none; font-weight:400; letter-spacing:0;">(boleh tambah lebih daripada satu)</span></label>
            <div id='addRows_ujian'>
                <div class='add-row'>
                    <select name='test_id[]' id='addUjianSelect_0' class='addUjianSelect select-search' required disabled>
                        <option value="">-- Pilih peringkat dahulu --</option>
                    </select>
                    <button type='button' class='add-row-btn add-row-remove' onclick="removeAddRow(this)" title='Buang baris'>&times;</button>
                </div>
            </div>
            <button type='button' class='add-row-btn add-row-add' onclick="addUjianRow()">+ Tambah Ujian</button>
        </div>
        <div>
            <label>Nama Kriteria <span style="text-transform:none; font-weight:400; letter-spacing:0;">(boleh tambah lebih daripada satu)</span></label>
            <div id='addRows_criteria'>
                <div class='add-row'>
                    <input name='criteria_name[]' placeholder='Contoh: Ketepatan Masa' required>
                    <button type='button' class='add-row-btn add-row-remove' onclick="removeAddRow(this)" title='Buang baris'>&times;</button>
                </div>
            </div>
            <button type='button' class='add-row-btn add-row-add' onclick="addRow('addRows_criteria', 'criteria_name[]', 'Contoh: Kekemasan Pakaian')">+ Tambah Baris</button>
        </div>
        <div class='pic-add-form-actions'>
            <button class='pm-btn pm-btn-primary'>Tambah Kriteria</button>
        </div>
    </form>
</div>

<div class='tests-card'>
    <div class='tests-save-bar' id='saveDirtyBar'>
        <span class='save-msg' id='saveMsg'>Tiada perubahan</span>
        <button type='button' class='pm-btn pm-btn-ghost btn-sm btn-discard' style='padding:5px 12px;' onclick="discardAll()">Batal</button>
        <button type='button' class='pm-btn pm-btn-primary btn-sm' style='padding:5px 12px;' onclick="submitSaveAll()">💾 Simpan Semua</button>
    </div>

    <div class='tests-table-wrap' id='criteriaList'>
        <p style='text-align:center;padding:30px;color:var(--c-text-faint);'>Memuatkan...</p>
    </div>
    <div id='ajaxSpinner'>⏳ Mencari...</div>

    <div class="vm-pagination" id="criteriaPaginationContainer" style="display:none;">
        <div class="vm-page-info" id="criteriaPageInfo"></div>
        <div class="vm-page-btns" id="criteriaPaginationButtons"></div>
    </div>
</div>

<script>
let _t = null;
let _dirtyRows = new Set();
let _orderDirty = false;

// Cache the server-rendered "Semua Ujian" option list (all ujian, all
// peringkat) so it can be restored when the Peringkat filter is cleared.
const _origUjianFilterHTML = document.getElementById('f_test').innerHTML;

function ajaxFilter() {
    clearTimeout(_t);
    _t = setTimeout(_load, 400);
}

// ── Nested Peringkat → Ujian filter: picking a Peringkat narrows the
// Ujian dropdown to just that peringkat's ujian instead of the full list. ──
function onLevelFilterChange() {
    const levelId = document.getElementById('f_level').value;
    const testSel = $('#f_test');
    if (!levelId) {
        testSel[0].innerHTML = _origUjianFilterHTML;
        testSel.val('').trigger('change');
        ajaxFilter();
        return;
    }
    pmFetch('pic_criteria.php?ajax_tests_for_level=1&level=' + encodeURIComponent(levelId))
        .then(r => r.json())
        .then(list => {
            testSel.empty().append('<option value="">-- Semua Ujian --</option>');
            list.forEach(t => testSel.append(new Option(t.test_name, t.test_id)));
            testSel.val('').trigger('change');
            ajaxFilter();
        })
        .catch(() => {});
}

function _load() {
    const params = new URLSearchParams({
        ajax: '1',
        search: document.getElementById('f_search').value,
        level: document.getElementById('f_level').value,
        test: document.getElementById('f_test').value,
        siri: document.getElementById('f_siri').value,
        session: document.getElementById('f_session').value
    });

    const wrapper = document.getElementById('criteriaList');
    const spinner = document.getElementById('ajaxSpinner');

    wrapper.style.display = 'none';
    spinner.style.display = 'block';

    pmFetch('pic_criteria.php?' + params)
        .then(r => r.text())
        .then(html => {
            wrapper.innerHTML = html;
            wrapper.style.display = 'block';
            spinner.style.display = 'none';
            _dirtyRows.clear();
            _orderDirty = false;
            updateDirtyBar();
            attachListeners();
            criteriaCurrentPage = 1;
            updateCriteriaPagination();
            fitCriteriaListHeight();
            restoreOpenAccordions();
        })
        .catch(() => {
            wrapper.style.display = 'block';
            spinner.style.display = 'none';
        });
}

// ── Add several criteria names to a test in one submission ──
function addRow(containerId, inputName, placeholder) {
    const container = document.getElementById(containerId);
    const row = document.createElement('div');
    row.className = 'add-row';
    const input = document.createElement('input');
    input.name = inputName;
    input.placeholder = placeholder;
    input.required = true;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'add-row-btn add-row-remove';
    btn.title = 'Buang baris';
    btn.innerHTML = '&times;';
    btn.onclick = () => removeAddRow(btn);
    row.appendChild(input);
    row.appendChild(btn);
    container.appendChild(row);
    input.focus();
}

function removeAddRow(btn) {
    const container = btn.closest('[id^="addRows_"]');
    const row = btn.closest('.add-row');
    if (container && container.querySelectorAll('.add-row').length > 1) {
        row.remove();
    } else if (row) {
        const field = row.querySelector('input, select');
        if (field) field.value = '';
    }
}

// ── Keep the accordion(s) the PIC had open across a save/add/delete
// redirect instead of snapping everything back to collapsed. ──
const PM_OPEN_KEY = 'pm_criteria_open_accordions';

function rememberOpenAccordions() {
    const openIds = Array.from(document.querySelectorAll('.acc-body'))
        .filter(el => el.style.display === 'block')
        .map(el => el.id);
    sessionStorage.setItem(PM_OPEN_KEY, JSON.stringify(openIds));
}

function restoreOpenAccordions() {
    let openIds = [];
    try { openIds = JSON.parse(sessionStorage.getItem(PM_OPEN_KEY) || '[]'); } catch (e) {}
    sessionStorage.removeItem(PM_OPEN_KEY);
    openIds.forEach(id => {
        const bodyEl = document.getElementById(id);
        if (!bodyEl) return;
        bodyEl.style.display = 'block';
        const iconEl = bodyEl.previousElementSibling?.querySelector('.acc-icon');
        if (iconEl) iconEl.style.transform = 'rotate(90deg)';
    });
}

// ── PAGINATION (client-side, 20 accordions per page) ──
let criteriaCurrentPage = 1;
const criteriaPerPage = 20;

function updateCriteriaPagination() {
    const cards = Array.from(document.querySelectorAll('#criteriaList > .accordion-card'));
    const container = document.getElementById('criteriaPaginationContainer');
    const info = document.getElementById('criteriaPageInfo');
    const btns = document.getElementById('criteriaPaginationButtons');

    if (cards.length === 0) { container.style.display = 'none'; return; }

    const total = cards.length;
    const totalPages = Math.max(1, Math.ceil(total / criteriaPerPage));
    if (criteriaCurrentPage > totalPages) criteriaCurrentPage = totalPages;
    if (criteriaCurrentPage < 1) criteriaCurrentPage = 1;

    container.style.display = totalPages <= 1 ? 'none' : 'flex';

    const start = (criteriaCurrentPage - 1) * criteriaPerPage;
    const end   = start + criteriaPerPage;
    cards.forEach((c, i) => { c.style.display = (i >= start && i < end) ? '' : 'none'; });

    const s = start + 1;
    const e = Math.min(end, total);
    info.innerHTML = `Memaparkan <b>${s}–${e}</b> daripada <b>${total}</b> peringkat`;

    pmRenderPagination(btns, criteriaCurrentPage, totalPages, criteriaGoToPage);
}

function criteriaGoToPage(page) {
    criteriaCurrentPage = page;
    updateCriteriaPagination();
}

// ── Fit the criteria list + pagination into the viewport, no page scroll ──
function fitCriteriaListHeight() {
    const scrollEl = document.getElementById('criteriaList');
    const pagination = document.getElementById('criteriaPaginationContainer');
    if (!scrollEl || !pagination) return;
    if (window.innerWidth <= 640) {
        scrollEl.style.maxHeight = '';
        return;
    }
    const top = scrollEl.getBoundingClientRect().top;
    const paginationH = pagination.offsetHeight;
    const available = window.innerHeight - top - paginationH - 24; // 24px bottom breathing room
    scrollEl.style.maxHeight = Math.max(150, available) + 'px';
}
window.addEventListener('resize', fitCriteriaListHeight);

function attachListeners() {
    document.querySelectorAll('.criteria-input').forEach(el => {
        if (el.dataset.listening) return;
        el.dataset.listening = '1';
        el.addEventListener('input', onInputChange);
    });
    attachDragListeners();
}

// ── Drag-and-drop reordering (per test tbody) ──
let _dragRow = null;

function attachDragListeners() {
    document.querySelectorAll('.tests-table tbody tr[draggable="true"]').forEach(row => {
        if (row.dataset.dragListening) return;
        row.dataset.dragListening = '1';
        row.addEventListener('dragstart', e => {
            _dragRow = row;
            row.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });
        row.addEventListener('dragend', () => {
            row.classList.remove('dragging');
            _dragRow = null;
        });
        row.addEventListener('dragover', e => {
            e.preventDefault();
            const tbody = row.parentElement;
            if (!_dragRow || _dragRow.parentElement !== tbody || _dragRow === row) return;
            const rect = row.getBoundingClientRect();
            const before = (e.clientY - rect.top) < rect.height / 2;
            tbody.insertBefore(_dragRow, before ? row : row.nextSibling);
        });
        row.addEventListener('drop', e => {
            e.preventDefault();
            renumberTable(row.closest('table'));
            markOrderDirty(row.closest('.accordion-card'));
        });
    });
}

function renumberTable(table) {
    table.querySelectorAll('tbody tr').forEach((row, i) => {
        const cell = row.querySelector('.col-no');
        if (cell) cell.textContent = i + 1;
    });
}

function markOrderDirty(accCard) {
    _orderDirty = true;
    if (accCard) accCard.classList.add('has-changes');
    updateDirtyBar();
}

function onInputChange(e) {
    const row = e.target.closest('tr');
    if (!row) return;
    const id = row.dataset.id;
    const orig = e.target.dataset.orig ?? '';
    const accCard = e.target.closest('.accordion-card');

    if (e.target.value !== orig) {
        _dirtyRows.add(id);
        row.classList.add('row-dirty');
        if(accCard) accCard.classList.add('has-changes');
    } else {
        _dirtyRows.delete(id);
        row.classList.remove('row-dirty');
        if(accCard && accCard.querySelectorAll('.row-dirty').length === 0) {
            accCard.classList.remove('has-changes');
        }
    }
    updateDirtyBar();
}

function updateDirtyBar() {
    const bar = document.getElementById('saveDirtyBar');
    const msg = document.getElementById('saveMsg');
    const total = _dirtyRows.size + (_orderDirty ? 1 : 0);
    bar.classList.toggle('dirty', total > 0);
    if (total === 0) {
        msg.innerHTML = 'Tiada perubahan';
    } else if (_dirtyRows.size > 0 && _orderDirty) {
        msg.innerHTML = `Ada <strong>${_dirtyRows.size}</strong> perubahan &amp; <strong>susunan</strong> belum disimpan`;
    } else if (_orderDirty) {
        msg.innerHTML = `Ada <strong>susunan</strong> belum disimpan`;
    } else {
        msg.innerHTML = `Ada <strong>${_dirtyRows.size}</strong> perubahan belum disimpan`;
    }
}

function submitSaveAll() {
    rememberOpenAccordions();
    const form = document.getElementById('saveAllForm');
    form.querySelectorAll('.dyn-input').forEach(el => el.remove());
    document.querySelectorAll('.criteria-input').forEach(el => {
        const h = document.createElement('input');
        h.type = 'hidden';
        h.name = el.name;
        h.value = el.value;
        h.className = 'dyn-input';
        form.appendChild(h);
    });
    if (_orderDirty) {
        document.querySelectorAll('.tests-table').forEach(table => {
            const tid = table.dataset.test;
            table.querySelectorAll('tbody tr[draggable="true"]').forEach((row, i) => {
                const h = document.createElement('input');
                h.type = 'hidden';
                h.name = `order[${tid}][${i}]`;
                h.value = row.dataset.id;
                h.className = 'dyn-input';
                form.appendChild(h);
            });
        });
    }
    form.submit();
}

function discardAll() {
    document.querySelectorAll('.criteria-input').forEach(el => {
        if (el.dataset.orig !== undefined) el.value = el.dataset.orig;
        el.closest('tr')?.classList.remove('row-dirty');
    });
    document.querySelectorAll('.accordion-card').forEach(acc => {
        acc.classList.remove('has-changes');
    });
    _dirtyRows.clear();
    _orderDirty = false;
    updateDirtyBar();
    _load();
}

function delRow(id) {
    if (!confirm('Padam kriteria ini?')) return;
    rememberOpenAccordions();
    document.getElementById('delFrm').querySelector('[name=criteria_id]').value = id;
    document.getElementById('delFrm').submit();
}

function toggleAddCard() {
    const el = document.getElementById('addCriteria');
    el.style.display = (el.style.display === 'block') ? 'none' : 'block';
}

function toggleBlock(id, headerEl) {
    const bodyEl = document.getElementById(id);
    const iconEl = headerEl.querySelector('.acc-icon');
    if (bodyEl) {
        if (bodyEl.style.display === 'none' || bodyEl.style.display === '') {
            bodyEl.style.display = 'block';
            if (iconEl) iconEl.style.transform = 'rotate(90deg)';
            requestAnimationFrame(() => {
                bodyEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            });
        } else {
            bodyEl.style.display = 'none';
            if (iconEl) iconEl.style.transform = 'rotate(0deg)';
        }
    }
}

// Custom Dropdown Functions
function ddToggle(name) {
    const panel = document.getElementById('ddPanel_' + name);
    const trigger = document.getElementById('ddTrigger_' + name);
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
        if(m) any = true; 
    });
    if(empty) empty.style.display = any ? 'none' : 'block';
}

function ddSelect(name, value, label) {
    if (name === 'level' || name === 'test') {
        document.getElementById('f_' + name).value = value;
    }
    const lbl = document.getElementById('ddLabel_' + name);
    lbl.textContent = label;
    lbl.style.color = value === '' ? 'var(--c-text-faint)' : '';
    document.querySelectorAll('#ddOpts_' + name + ' .dd-opt').forEach(o => o.classList.toggle('selected', o.dataset.value === value));
    document.getElementById('ddPanel_' + name).classList.remove('open');
    document.getElementById('ddTrigger_' + name).classList.remove('open');
    ajaxFilter();
}

function onSiriChange() {
    const siriId = document.getElementById('f_siri').value;
    const sessSel = $('#f_session');
    sessSel.empty().append('<option value="">-- Semua Sidang --</option>');
    if (!siriId) { sessSel.trigger('change'); ajaxFilter(); return; }
    pmFetch('pic_criteria.php?ajax_sessions=1&siri=' + encodeURIComponent(siriId))
        .then(r => r.json())
        .then(list => {
            list.forEach(s => sessSel.append(new Option(s.session_name, s.session_id)));
            sessSel.trigger('change');
            ajaxFilter();
        })
        .catch(() => {});
}

// ── Tambah Kriteria form: cascading "Pilih Ujian" rows scoped to the
// chosen Peringkat — one native dropdown per row, "+ Tambah Ujian" adds
// another so the same criteria names can be added to several ujian at
// once. ──
let _ujianOptionsCache = [];

function loadUjianForPeringkat(levelId) {
    _ujianOptionsCache = [];
    if (!levelId) {
        refreshUjianSelects();
        return;
    }
    pmFetch('pic_criteria.php?ajax_tests_for_level=1&level=' + encodeURIComponent(levelId))
        .then(r => r.json())
        .then(list => {
            _ujianOptionsCache = list;
            refreshUjianSelects();
        })
        .catch(() => {});
}

// $select is a jQuery-wrapped <select> — kept as Select2 the whole time so
// every "Pilih Ujian" row looks identical to "Pilih Peringkat" instead of
// falling back to an unstyled native dropdown.
function populateUjianSelect($select, selectedValue) {
    $select.empty();
    if (_ujianOptionsCache.length === 0) {
        $select.append(new Option('-- Pilih peringkat dahulu --', ''));
        $select.prop('disabled', true);
    } else {
        $select.prop('disabled', false);
        $select.append(new Option('-- Pilih Ujian --', ''));
        _ujianOptionsCache.forEach(t => {
            $select.append(new Option(t.test_name, t.test_id, false, String(t.test_id) === String(selectedValue)));
        });
    }
    $select.trigger('change'); // refresh the Select2 UI to match the new options
}

function refreshUjianSelects() {
    $('.addUjianSelect').each(function () {
        populateUjianSelect($(this), $(this).val());
    });
}

let _addUjianRowSeq = 1;

function addUjianRow() {
    const container = document.getElementById('addRows_ujian');
    const row = document.createElement('div');
    row.className = 'add-row';
    const select = document.createElement('select');
    select.name = 'test_id[]';
    select.id = 'addUjianSelect_' + (_addUjianRowSeq++);
    select.className = 'addUjianSelect select-search';
    select.required = true;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'add-row-btn add-row-remove';
    btn.title = 'Buang baris';
    btn.innerHTML = '&times;';
    btn.onclick = () => removeAddRow(btn);
    row.appendChild(select);
    row.appendChild(btn);
    container.appendChild(row);
    $(select).select2({ width: '100%', matcher: alwaysShowAll });
    populateUjianSelect($(select), '');
}

function ddSearchInput(val) {
    const lbl = document.getElementById('ddLabel_search');
    lbl.textContent = val || '-- Semua Kriteria --';
    lbl.style.color = val ? '' : 'var(--c-text-faint)';
    ajaxFilter();
}

document.addEventListener('click', e => {
    if (!e.target.closest('.dd-wrap')) {
        document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
        document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
    }
});

// Custom Matcher: Ensures the empty/default option is always visible during
// search. Global scope (not just inside $(document).ready) so dynamically
// added rows (addUjianRow) can initialize Select2 with the same matcher.
function alwaysShowAll(params, data) {
    // If there's no search term, return all data
    if ($.trim(params.term) === '') {
        return data;
    }
    // If this is the "Semua" option (value is empty string), NEVER hide it
    if (data.id === '') {
        return data;
    }
    // Otherwise, do the standard text matching
    if (data.text.toLowerCase().indexOf(params.term.toLowerCase()) > -1) {
        return data;
    }
    // No match
    return null;
}

$(document).ready(function() {
    // Initialize Select2 with the custom matcher
    $('.select-search').select2({
        width: '100%',
        matcher: alwaysShowAll
    });

    // Bind Select2 changes to your ajaxFilter function for ALL filters
    $('#f_level, #f_test, #f_search, #f_session').on('select2:select', function (e) {
        ajaxFilter();
    });
});

document.addEventListener('DOMContentLoaded', _load);
</script>

</main>
</body>
</html>