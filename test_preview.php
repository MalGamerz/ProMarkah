<?php
session_start();
require __DIR__ . '/auth_check.php';
include 'db.php';
$conn = getDB();

// Restrict access only to PIC role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pic') {
    header("Location: login.php");
    exit();
}

// ── AJAX: levels for a given session (keeps "Pilih Peringkat" scoped to the
// chosen Sidang instead of listing every level from every session) ──
if (isset($_GET['ajax']) && $_GET['ajax'] === 'levels') {
    $session_id = (int)($_GET['session_id'] ?? 0);
    echo "<option value=''>-- Sila Pilih Peringkat --</option>";
    if ($session_id > 0) {
        $stmt = $conn->prepare("SELECT level_id, level_name FROM levels WHERE session_id = ? ORDER BY level_name");
        $stmt->bind_param('i', $session_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($l = $res->fetch_assoc()) {
            echo "<option value='{$l['level_id']}'>" . htmlspecialchars($l['level_name']) . "</option>";
        }
        $stmt->close();
    }
    exit();
}

// ── AJAX: test structure for a given level — renders immediately, no submit button needed ──
if (isset($_GET['ajax']) && $_GET['ajax'] === 'tests') {
    $level_id = (int)($_GET['level_id'] ?? 0);

    $levelNameStmt = $conn->prepare("SELECT level_name FROM levels WHERE level_id = ?");
    $levelNameStmt->bind_param('i', $level_id);
    $levelNameStmt->execute();
    $levelNameQ = $levelNameStmt->get_result();
    $levelName = $levelNameQ && $levelNameQ->num_rows > 0 ? $levelNameQ->fetch_assoc()['level_name'] : 'Peringkat Tidak Diketahui';
    $levelNameStmt->close();

    echo "<div class='pm-card'>
            <h3 class='pm-card-title' style='margin-bottom: 24px; font-size: 1.5rem; letter-spacing: 0.5px;'>
                Struktur Ujian: <span class='struktur-level-value'>" . htmlspecialchars($levelName) . "</span>
            </h3>";

    $testsStmt = $conn->prepare("SELECT test_id, test_name FROM tests WHERE level_id = ? ORDER BY test_name");
    $testsStmt->bind_param('i', $level_id);
    $testsStmt->execute();
    $tests = $testsStmt->get_result();

    if ($tests && $tests->num_rows > 0) {
        echo "<div class='pm-table-wrap'>
                <table class='pm-table'>
                    <thead>
                        <tr>
                            <th style='width: 60px; text-align: center;'>Bil.</th>
                            <th style='width: 280px;'>Nama Ujian</th>
                            <th>Senarai Kriteria Penilaian</th>
                        </tr>
                    </thead>
                    <tbody>";

        $i = 1;
        $criteriaStmt = $conn->prepare("SELECT criteria_name FROM criteria WHERE test_id = ? ORDER BY criteria_name");
        while ($t = $tests->fetch_assoc()) {
            $test_id = $t['test_id'];
            $criteriaStmt->bind_param('i', $test_id);
            $criteriaStmt->execute();
            $criteria = $criteriaStmt->get_result();

            echo "<tr>
                    <td style='vertical-align: top; text-align: center; padding-top: 12px;'>
                        <div class='test-number-badge'>{$i}</div>
                    </td>
                    <td style='vertical-align: top; padding-top: 14px; font-size: 1.05rem;' class='ujian-test-name'>
                        <strong>" . htmlspecialchars($t['test_name']) . "</strong>
                    </td>
                    <td style='padding: 12px 10px;'>";

            if ($criteria->num_rows > 0) {
                echo "<ul class='criteria-list'>";
                while ($c = $criteria->fetch_assoc()) {
                    echo "<li>" . htmlspecialchars($c['criteria_name']) . "</li>";
                }
                echo "</ul>";
            } else {
                echo "<span class='no-criteria-note'>
                        ⚠️ Tiada kriteria ditetapkan untuk ujian ini.
                      </span>";
            }

            echo "</td>
                  </tr>";
            $i++;
        }
        $criteriaStmt->close();
        $testsStmt->close();

        echo "</tbody></table></div>
              <div class='no-print print-bar' style='text-align: right; border-top: 1px solid rgba(255,255,255,0.05);'>
                  <button onclick='window.print()' class='btn-print'>
                      <span style='font-size: 1.2rem;'>🖨️</span> Cetak / Simpan PDF
                  </button>
              </div>
              </div>";
    } else {
        $testsStmt->close();
        echo "<div class='pm-alert pm-alert-warn' style='margin: 0;'>
                <strong>Perhatian:</strong> Tiada ujian dijumpai untuk peringkat ini di dalam pangkalan data.
              </div></div>";
    }
    exit();
}

// Set the active page for the sidebar highlighting
$pm_page = 'test_preview';
include 'layout.php';

// "Semua Siri" (0) is ambiguous across sessions with duplicate names, so show a
// Siri picker only in that case to let the PIC narrow down which siri's sessions to browse.
$active_siri_id = (int)($_SESSION['active_siri_id'] ?? 0);
$show_siri_picker = $active_siri_id === 0;
$filter_siri_id = $show_siri_picker ? (int)($_POST['siri_id'] ?? 0) : $active_siri_id;
?>

<?php
$pm_tp_css_v = @filemtime(__DIR__ . '/test_preview.css') ?: time();
?>
<link rel="stylesheet" href="test_preview.css?v=<?= $pm_tp_css_v ?>">

<h2 class="pm-page-heading">📄 Paparan Struktur Ujian</h2>

<div class="pm-card preview-filter-card no-print">
    <form method="POST" class="preview-form-grid">
        <?php if ($show_siri_picker): ?>
        <div>
            <label class="form-label">Pilih Siri</label>
            <select name="siri_id" class="pm-select" onchange="this.form.submit()" style="width: 100%;">
                <option value="">-- Semua Siri --</option>
                <?php
                $siris = $conn->query("SELECT siri_id, siri_name, siri_year FROM siri ORDER BY siri_year DESC, siri_name ASC");
                while ($si = $siris->fetch_assoc()) {
                    $sel = ($filter_siri_id === (int)$si['siri_id']) ? 'selected' : '';
                    echo "<option value='{$si['siri_id']}' $sel>" . htmlspecialchars($si['siri_name']) . " (" . htmlspecialchars($si['siri_year']) . ")</option>";
                }
                ?>
            </select>
        </div>
        <?php endif; ?>

        <div>
            <label class="form-label">Pilih Sidang</label>
            <select name="session_id" id="sessionSelect" class="pm-select" style="width: 100%;">
                <option value="">-- Sila Pilih Sidang --</option>
                <?php
                if ($filter_siri_id > 0) {
                    $stmt = $conn->prepare("SELECT * FROM sessions WHERE siri_id = ? ORDER BY session_name");
                    $stmt->bind_param('i', $filter_siri_id);
                    $stmt->execute();
                    $sessions = $stmt->get_result();
                } else {
                    $sessions = $conn->query("SELECT * FROM sessions ORDER BY session_name");
                }
                while ($s = $sessions->fetch_assoc()) {
                    echo "<option value='{$s['session_id']}'>" . htmlspecialchars($s['session_name']) . "</option>";
                }
                ?>
            </select>
        </div>

        <div>
            <label class="form-label">Pilih Peringkat</label>
            <select name="level_id" id="levelSelect" class="pm-select" style="width: 100%;" disabled>
                <option value="">-- Sila Pilih Sidang dahulu --</option>
            </select>
        </div>
    </form>
</div>

<div id="testResults"></div>

<?php
$pm_tp_js_v = @filemtime(__DIR__ . '/test_preview.js') ?: time();
?>
<script src="test_preview.js?v=<?= $pm_tp_js_v ?>"></script>

</main>
</body>
</html>