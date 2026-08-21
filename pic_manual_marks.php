<?php
session_start();
require __DIR__ . '/auth_check.php';
include 'db.php';
$conn = getDB();

// Ensure only PIC can access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pic') {
    header("Location: login.php");
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Renders one searchable .dd- dropdown (matches the pic_view_marks benchmark).
// A hidden input carries the value; picking an option submits the param form
// so dependent dropdowns rebuild (same cascade behaviour as before).
function renderMMDD(string $fieldName, string $ddName, string $emptyLabel, string $disabledEmptyLabel, array $options, $currentVal, bool $isDisabled) {
    $currentVal    = (string)$currentVal;
    $shownEmpty    = $isDisabled ? $disabledEmptyLabel : $emptyLabel;
    $disabledClass = $isDisabled ? ' dd-trigger-disabled' : '';
    echo "<div class=\"dd-wrap\" id=\"ddWrap_{$ddName}\">";
    echo "<div class=\"dd-trigger{$disabledClass}\" id=\"ddTrigger_{$ddName}\" onclick=\"ddToggle('{$ddName}')\" role=\"button\" tabindex=\"0\" aria-haspopup=\"listbox\">";
    // Even when disabled (e.g. a value that's fixed/derived rather than
    // user-pickable), still show the matching option's label instead of the
    // generic placeholder — disabled only turns off interaction, it
    // shouldn't hide a value that's already known.
    $curLabel = $shownEmpty;
    foreach ($options as $opt) {
        if ((string)$opt['value'] === $currentVal && $currentVal !== '') { $curLabel = $opt['label']; break; }
    }
    $labelColor = ($currentVal === '' || $isDisabled) ? 'color:var(--c-text-faint);' : '';
    echo "<span id=\"ddLabel_{$ddName}\" style=\"{$labelColor}\">" . htmlspecialchars($curLabel) . "</span>";
    echo "<span class=\"dd-arrow\">▼</span></div>";
    echo "<div class=\"dd-panel\" id=\"ddPanel_{$ddName}\" role=\"listbox\">";
    echo "<div class=\"dd-search-box\"><input type=\"text\" placeholder=\"Cari...\" oninput=\"ddFilter('{$ddName}',this.value)\" onclick=\"event.stopPropagation()\"></div>";
    echo "<div class=\"dd-options\" id=\"ddOpts_{$ddName}\">";
    foreach ($options as $opt) {
        $sel    = ((string)$opt['value'] === $currentVal && $currentVal !== '') ? 'selected' : '';
        $valEsc = htmlspecialchars((string)$opt['value'], ENT_QUOTES);
        $lblEsc = htmlspecialchars($opt['label'], ENT_QUOTES);
        echo "<div class=\"dd-opt {$sel}\" role=\"option\" tabindex=\"0\" data-value=\"{$valEsc}\" onclick=\"ddSelect('{$ddName}','{$valEsc}','{$lblEsc}')\">" . htmlspecialchars($opt['label']) . "</div>";
    }
    echo "</div><div class=\"dd-empty\" id=\"ddEmpty_{$ddName}\">Tiada hasil</div></div>";
    echo "<input type=\"hidden\" name=\"{$fieldName}\" id=\"{$ddName}\" value=\"" . htmlspecialchars($currentVal) . "\">";
    echo "</div>";
}

$pm_page = 'manual_marks';
include 'layout.php';

$pm_mm_css_v = @filemtime(__DIR__ . '/pic_manual_marks.css') ?: time();
?>
<link rel="stylesheet" href="pic_manual_marks.css?v=<?= $pm_mm_css_v ?>">

<div class="pic-section-header">
    <div>
        <h2>✍️ Isi Markah Manual</h2>
        <div class="pic-section-sub">
            Tetapkan parameter dan pastikan markah yang dimasukkan tepat sebelum simpan.
        </div>
    </div>
</div>

<?php if (isset($_GET['msg'])):
    $isError = ($_GET['status'] ?? '') === 'error';
?>
<div class="pm-alert <?= $isError ? 'pm-alert-danger' : 'pm-alert-success' ?>">
    <?= $isError ? '⚠️' : '✅' ?> <?= htmlspecialchars($_GET['msg']) ?>
</div>
<?php endif; ?>

<div class="pm-card">
    <div style="margin-bottom: 20px;">
        <h3 style="margin: 0 0 4px; font-size: 1.1rem; font-weight: 500;">🎯 Pilih Parameter</h3>
        <p style="margin:0; font-size:0.8rem; color:var(--c-text-faint);">Pilih kumpulan — peringkat akan ditetapkan secara automatik.</p>
    </div>

    <?php
    // Peringkat is not an independent choice — every kumpulan already belongs
    // to exactly one peringkat, so it's always derived from the selected
    // group rather than picked separately. Re-resolved from the DB on every
    // request (not just when level_id is missing) so a stale/mismatched
    // level_id can never end up paired with the wrong group.
    $_POST['level_id'] = '';
    if (!empty($_POST['group_id'])) {
        $gid = (int)$_POST['group_id'];
        $gRes = $conn->query("SELECT level_id FROM `groups` WHERE group_id=$gid");
        if ($gRes && $gRes->num_rows > 0) $_POST['level_id'] = $gRes->fetch_assoc()['level_id'];
    }
    ?>

    <form method="POST" id="mmParamForm" class="context-grid">
        <?php
        $active_siri = (int)($_SESSION['active_siri_id'] ?? 0);

        // Kumpulan
        $groupOpts = [];
        $gq = $active_siri > 0
            ? $conn->query("SELECT group_id, group_name FROM `groups` WHERE level_id IN (SELECT level_id FROM levels WHERE session_id IN (SELECT session_id FROM sessions WHERE siri_id = $active_siri)) ORDER BY group_name")
            : $conn->query("SELECT group_id, group_name FROM `groups` ORDER BY group_name");
        while ($g = $gq->fetch_assoc()) $groupOpts[] = ['value' => $g['group_id'], 'label' => $g['group_name']];

        // Peringkat
        $levelOpts = [];
        $lq = $active_siri > 0
            ? $conn->query("SELECT level_id, level_name FROM levels WHERE session_id IN (SELECT session_id FROM sessions WHERE siri_id = $active_siri) ORDER BY level_name")
            : $conn->query("SELECT level_id, level_name FROM levels ORDER BY level_name");
        while ($l = $lq->fetch_assoc()) $levelOpts[] = ['value' => $l['level_id'], 'label' => $l['level_name']];
        ?>
        <div>
            <label style="font-size:0.75rem; color: var(--c-text-faint); margin-bottom:6px; display:block;">Kumpulan</label>
            <?php renderMMDD('group_id', 'mm_group', 'Pilih kumpulan...', 'Pilih kumpulan...', $groupOpts, $_POST['group_id'] ?? '', false); ?>
        </div>

        <div>
            <label style="font-size:0.75rem; color: var(--c-text-faint); margin-bottom:6px; display:block;">Peringkat <span style="text-transform:none;font-weight:400;color:var(--c-text-faint);">(ditetapkan mengikut kumpulan)</span></label>
            <?php
            $levelEmptyLabel = empty($_POST['group_id']) ? 'Pilih kumpulan dahulu...' : 'Tiada peringkat dijumpai';
            renderMMDD('level_id', 'mm_level', $levelEmptyLabel, $levelEmptyLabel, $levelOpts, $_POST['level_id'] ?? '', true);
            ?>
        </div>
    </form>
</div>

<?php if (!empty($_POST['group_id']) && !empty($_POST['level_id'])):
    $group_id_sel = (int)$_POST['group_id'];
    $level_id_sel = (int)$_POST['level_id'];

    $stuRes = $conn->query("SELECT st.student_id, st.student_name FROM group_students gs JOIN students st ON gs.student_id=st.student_id WHERE gs.group_id=$group_id_sel ORDER BY st.student_id");
    $groupStudents = [];
    while ($row = $stuRes->fetch_assoc()) $groupStudents[] = $row;

    // Every ujian under this peringkat — each becomes its own collapsible
    // section below (instead of the PIC picking exactly one Ujian up front),
    // so a whole peringkat's worth of tests can be marked in one visit.
    $levelTests = [];
    $testRes = $conn->query("SELECT test_id, test_name FROM tests WHERE level_id=$level_id_sel ORDER BY sort_order, test_name");
    while ($row = $testRes->fetch_assoc()) $levelTests[] = $row;

    // Criteria + existing marks, fetched per test (not once globally) since
    // each test's criteria are what get shown inside that test's own
    // dropdown section. pic_save_scores.php itself is test-agnostic (it only
    // ever sees student_id/criteria_id pairs), so every test's marks[] below
    // can be submitted together in one shared form/POST.
    $testCriteriaMap  = []; // test_id => [criteria rows]
    $existingMarksMap = []; // test_id => [student_id][criteria_id] => mark
    foreach ($levelTests as $t) {
        $tid = (int)$t['test_id'];
        $critRes = $conn->query("SELECT criteria_id, criteria_name FROM criteria WHERE test_id=$tid ORDER BY sort_order, criteria_name");
        $crit = [];
        while ($row = $critRes->fetch_assoc()) $crit[] = $row;
        $testCriteriaMap[$tid] = $crit;

        $existingMarksMap[$tid] = [];
        if (!empty($crit)) {
            $critIds = array_column($crit, 'criteria_id');
            $placeholders = implode(',', array_fill(0, count($critIds), '?'));
            $types = str_repeat('i', count($critIds) + 1);
            // status distinguishes a judge's real mark ('scored') from one
            // they explicitly opted out of ('abaikan') — both used to be
            // indistinguishable from "nobody's touched this" (no row at
            // all), which is exactly the gap this page needed to close.
            $stmt = $conn->prepare("SELECT student_id, criteria_id, mark, status FROM scores WHERE group_id = ? AND criteria_id IN ($placeholders)");
            $stmt->bind_param($types, $group_id_sel, ...$critIds);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $existingMarksMap[$tid][$row['student_id']][$row['criteria_id']] = [
                    'mark'   => $row['mark'],
                    'status' => $row['status'] ?? 'scored',
                ];
            }
            $stmt->close();
        }
    }
?>

<form action="pic_save_scores.php" method="POST">
    <input type="hidden" name="group_id" value="<?= $group_id_sel ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

    <div class="pm-card" style="margin-bottom:16px;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
            <h3 style="margin: 0; font-size: 1.1rem; font-weight: 500;">Masukkan Markah — Seluruh Kumpulan</h3>
            <div style="display: flex; gap: 8px;">
                <a href="pic.php?section=tests" class="btn-outline">+ Ujian Baru</a>
                <a href="pic.php?section=criteria" class="btn-outline">+ Kriteria Baru</a>
            </div>
        </div>
        <p style="margin:10px 0 0; font-size:0.8rem; color:var(--c-text-faint);">
            🔒 Kriteria yang sudah ada markah dikunci secara automatik supaya tidak tertindih secara tidak sengaja — klik 🔒 untuk buka kunci dan edit. Klik nama ujian di bawah untuk buka/tutup senarai kriterianya.
        </p>
    </div>

    <?php if (empty($groupStudents)): ?>
        <div class="pm-card"><p style="color:var(--c-text-faint); margin:0;">Tiada pelajar dalam kumpulan ini.</p></div>
    <?php elseif (empty($levelTests)): ?>
        <div class="pm-card"><p style="color:var(--c-text-faint); margin:0;">Tiada ujian untuk peringkat ini.</p></div>
    <?php else: ?>
        <?php foreach ($levelTests as $i => $t):
            $tid = (int)$t['test_id'];
            $testCriteria = $testCriteriaMap[$tid];
            $existingMarks = $existingMarksMap[$tid];

            $totalCells = count($groupStudents) * count($testCriteria);
            $markedCells = 0;
            foreach ($groupStudents as $gs) {
                foreach (($existingMarks[(int)$gs['student_id']] ?? []) as $entry) {
                    if ($entry['status'] === 'scored') $markedCells++;
                }
            }
        ?>
        <details class="pm-card mm-test-accordion" <?= $i === 0 ? 'open' : '' ?>>
            <summary class="mm-test-summary">
                <span class="mm-test-title-group">
                    <span class="mm-test-arrow">▶</span>
                    <span class="mm-test-name"><?= htmlspecialchars($t['test_name']) ?></span>
                </span>
                <span class="mm-test-badges">
                    <span class="mm-test-count"><?= count($testCriteria) ?> kriteria</span>
                    <?php if (!empty($testCriteria)): ?>
                    <span class="mm-test-progress<?= $markedCells === $totalCells ? ' complete' : '' ?>"><?= $markedCells ?>/<?= $totalCells ?> markah</span>
                    <?php endif; ?>
                </span>
            </summary>
            <div class="mm-test-body">
                <?php if (empty($testCriteria)): ?>
                    <p style="color:var(--c-text-faint); margin:0;">Tiada kriteria untuk ujian ini.</p>
                <?php else: ?>
                    <div class="mm-marks-table-wrap">
                    <table class="mm-marks-table">
                        <thead>
                            <tr>
                                <th>Pelajar</th>
                                <?php foreach ($testCriteria as $c): ?>
                                <th><?= htmlspecialchars($c['criteria_name']) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($groupStudents as $stu):
                                $sid = (int)$stu['student_id'];
                                $nameParts = preg_split('/\s+/', trim($stu['student_name']));
                                $initials = mb_strtoupper(mb_substr($nameParts[0], 0, 1));
                                if (count($nameParts) > 1) $initials .= mb_strtoupper(mb_substr(end($nameParts), 0, 1));
                            ?>
                            <tr>
                                <td>
                                    <div class="mm-cell-name">
                                        <span class="mm-avatar"><?= htmlspecialchars($initials) ?></span>
                                        <?= htmlspecialchars($stu['student_name']) ?>
                                    </div>
                                </td>
                                <?php foreach ($testCriteria as $c):
                                    $cid = (int)$c['criteria_id'];
                                    $entry = $existingMarks[$sid][$cid] ?? null;
                                    $hasMark = $entry !== null && $entry['status'] === 'scored';
                                    // A judge explicitly skipping a criteria and nobody having
                                    // touched it yet used to look identical (no row at all) —
                                    // this is what actually distinguishes them on screen now.
                                    $isJudgeAbaikan = $entry !== null && $entry['status'] === 'abaikan';
                                    $val = $hasMark ? $entry['mark'] : '';
                                ?>
                                <td class="mm-mark-cell<?= $hasMark ? ' has-mark' : '' ?><?= $isJudgeAbaikan ? ' mm-judge-abaikan' : '' ?>" id="mmRow_<?= $sid ?>_<?= $cid ?>" data-label="<?= htmlspecialchars($c['criteria_name']) ?>">
                                    <div class="mm-cell-inner">
                                        <input type="number" name="marks[<?= $sid ?>][<?= $cid ?>]" class="pm-input mm-mark-input"
                                               min="0" max="10" step="1" inputmode="numeric"
                                               placeholder="<?= $isJudgeAbaikan ? 'Abai' : '–' ?>"
                                               title="<?= $isJudgeAbaikan ? 'Juri menandakan kriteria ini Abai — taip markah untuk menggantikannya' : '' ?>"
                                               value="<?= htmlspecialchars((string)$val) ?>" <?= $hasMark ? 'disabled' : '' ?>>
                                        <?php if ($hasMark): ?>
                                        <div class="mm-cell-badges">
                                            <button type="button" class="mm-unlock-btn" onclick="mmUnlock(this)" title="Markah sedia ada — klik untuk buka kunci dan edit">🔒</button>
                                            <button type="button" class="mm-abai-btn" onclick="mmAbai(this, <?= $sid ?>, <?= $cid ?>)" title="Abaikan — buang markah ini terus">🗑️</button>
                                        </div>
                                        <?php elseif ($isJudgeAbaikan): ?>
                                        <div class="mm-cell-badges">
                                            <span class="mm-judge-abaikan-badge" title="Juri menandakan kriteria ini Abai">⚠️</span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
            </div>
        </details>
        <?php endforeach; ?>

        <div class="mm-save-footer">
            <span class="mm-save-hint">Semak semula sebelum menyimpan — markah yang disimpan akan menggantikan rekod sedia ada.</span>
            <button type="submit" class="pm-btn pm-btn-primary mm-save-btn" style="padding: 10px 24px; font-size: 0.95rem;">
                💾 Simpan Semua Markah
            </button>
        </div>
    <?php endif; ?>
</form>

<?php else: ?>
<div class="pm-card mm-empty-state">
    <div class="mm-empty-icon">🧭</div>
    <h3>Pilih kumpulan untuk mula</h3>
    <p>Peringkat akan ditetapkan secara automatik sebaik sahaja kumpulan dipilih di atas.</p>
</div>
<?php endif; ?>

<?php
$pm_mm_js_v = @filemtime(__DIR__ . '/pic_manual_marks.js') ?: time();
?>
<script src="pic_manual_marks.js?v=<?= $pm_mm_js_v ?>"></script>
</main>
</body>
</html>