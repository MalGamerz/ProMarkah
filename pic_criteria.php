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
                <div class='level-header' onclick=\"toggleBlock('level_$lid', this)\" role='button' tabindex='0'>
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
                    <div class='level-header level-header-sub' onclick=\"toggleBlock('test_$tid', this)\" role='button' tabindex='0'>
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

<?php
$pm_crit_css_v = @filemtime(__DIR__ . '/pic_criteria.css') ?: time();
?>
<link rel="stylesheet" href="pic_criteria.css?v=<?= $pm_crit_css_v ?>">

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

<?php
$pm_crit_js_v = @filemtime(__DIR__ . '/pic_criteria.js') ?: time();
?>
<script src="pic_criteria.js?v=<?= $pm_crit_js_v ?>"></script>

</main>
</body>
</html>