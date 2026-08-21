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

// Malay name shorthand normalized to one form (BT/BT./BINTI, B./BIN) so a
// PDF's abbreviated style doesn't read as a mismatch against however the
// name was typed into the system, then loosely fuzzy-matched — PDFs and
// manual data entry rarely agree byte-for-byte (extra spaces, a dropped
// hyphen, "AL-JUFRI" vs "AL JUFRI") even when they mean the same student.
function pm_normalize_name(string $s): string {
    $s = mb_strtoupper(trim($s), 'UTF-8');
    $s = preg_replace('/\bBT\.?\b/', 'BINTI', $s);
    $s = preg_replace('/\bB\.?\b/', 'BIN', $s);
    $s = str_replace(['.', ',', '-'], ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

function pm_best_match(string $target, array $normalizedCandidates): array {
    $bestIdx = null; $bestPct = 0.0;
    foreach ($normalizedCandidates as $i => $c) {
        similar_text($target, $c, $pct);
        if ($pct > $bestPct) { $bestPct = $pct; $bestIdx = $i; }
    }
    return [$bestIdx, round($bestPct, 1)];
}

// ── AJAX: compare a parsed PDF roster (sent as JSON) against this
// cawangan's students ── the actual PDF text extraction happens client-side
// (pdf.js) — this endpoint only ever sees plain student names, never the
// file itself, so no PDF-parsing library is needed server-side.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax_compare'])) {
    header('Content-Type: application/json');
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload) || !isset($payload['csrf_token']) || !hash_equals($csrf, $payload['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'Token keselamatan tidak sah. Sila muat semula halaman.']);
        exit;
    }
    $schoolId = (int)($payload['school_id'] ?? 0);
    $groups   = is_array($payload['groups'] ?? null) ? $payload['groups'] : [];
    if ($schoolId <= 0 || !$groups) {
        echo json_encode(['ok' => false, 'msg' => 'Sila pilih cawangan dan muat naik PDF yang sah.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT student_id, student_name FROM students WHERE school_id = ?");
    $stmt->bind_param('i', $schoolId);
    $stmt->execute();
    $dbStudents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $dbNorm = [];
    foreach ($dbStudents as $s) { $dbNorm[] = pm_normalize_name($s['student_name']); }

    $claimed = []; // db index => true, once matched at "found" or "close" confidence
    $outGroups = [];
    foreach ($groups as $g) {
        $peringkat = trim((string)($g['peringkat'] ?? ''));
        $kumpulan  = trim((string)($g['kumpulan'] ?? ''));
        $students  = is_array($g['students'] ?? null) ? $g['students'] : [];
        $outStudents = [];
        foreach ($students as $rawName) {
            $rawName = trim((string)$rawName);
            if ($rawName === '') continue;
            $norm = pm_normalize_name($rawName);
            [$idx, $pct] = pm_best_match($norm, $dbNorm);
            if ($idx !== null && $pct >= 92) {
                $status = 'found'; $matchName = $dbStudents[$idx]['student_name']; $claimed[$idx] = true;
            } elseif ($idx !== null && $pct >= 65) {
                $status = 'close'; $matchName = $dbStudents[$idx]['student_name']; $claimed[$idx] = true;
            } else {
                $status = 'missing'; $matchName = null;
            }
            $outStudents[] = [
                'name' => $rawName, 'status' => $status,
                'match' => $matchName, 'pct' => $idx !== null ? $pct : 0,
            ];
        }
        $outGroups[] = ['peringkat' => $peringkat, 'kumpulan' => $kumpulan, 'students' => $outStudents];
    }

    $unclaimed = [];
    foreach ($dbStudents as $i => $s) {
        if (!isset($claimed[$i])) $unclaimed[] = $s['student_name'];
    }
    sort($unclaimed);

    echo json_encode(['ok' => true, 'groups' => $outGroups, 'unclaimed' => $unclaimed, 'db_total' => count($dbStudents)]);
    exit;
}

session_write_close();

$schools = $conn->query("SELECT school_id, school_name FROM schools ORDER BY school_name")->fetch_all(MYSQLI_ASSOC);

$pm_page = 'roster_check';
include 'layout.php';
?>
<?php
$pm_rc_css_v = @filemtime(__DIR__ . '/pic_roster_check.css') ?: time();
?>
<link rel="stylesheet" href="pic_roster_check.css?v=<?= $pm_rc_css_v ?>">

<div class="pic-section-header">
    <div>
        <h2>Semak Senarai Peserta (PDF)</h2>
        <div class="pic-section-sub">Bandingkan senarai nama daripada PDF rasmi dengan pelajar yang sudah didaftarkan bagi satu cawangan — untuk pastikan tidak ada nama tertinggal.</div>
    </div>
</div>

<div class="rc-upload-card">
    <div>
        <label>Cawangan</label>
        <div class="dd-wrap" id="ddWrap_rcschool">
            <div class="dd-trigger" id="ddTrigger_rcschool" onclick="rcDdToggle()" role="button" tabindex="0" aria-haspopup="listbox">
                <span id="ddLabel_rcschool" style="color:var(--c-text-faint);">-- Pilih Cawangan --</span>
                <span class="dd-arrow">▼</span>
            </div>
            <div class="dd-panel" id="ddPanel_rcschool" role="listbox">
                <div class="dd-search-box">
                    <input type="text" placeholder="Cari cawangan..." oninput="rcDdFilter(this.value)" onclick="event.stopPropagation()">
                </div>
                <div class="dd-options" id="ddOpts_rcschool">
                    <?php foreach ($schools as $s): ?>
                    <div class="dd-opt" role="option" tabindex="0" data-value="<?= $s['school_id'] ?>" onclick="rcDdSelect('<?= $s['school_id'] ?>','<?= htmlspecialchars($s['school_name'], ENT_QUOTES) ?>')"><?= htmlspecialchars($s['school_name']) ?></div>
                    <?php endforeach; ?>
                </div>
                <div class="dd-empty" id="ddEmpty_rcschool">Tiada hasil</div>
            </div>
        </div>
        <input type="hidden" id="rc_school" value="">
    </div>
    <div>
        <label>Fail PDF Senarai Peserta</label>
        <div class="rc-file-wrap">
            <input type="file" id="rc_file" accept="application/pdf" class="rc-file-native" onchange="rcFileChanged()">
            <label for="rc_file" class="rc-file-label">
                <span class="rc-file-btn">Pilih Fail</span>
                <span class="rc-file-name" id="rc_file_name">Tiada fail dipilih</span>
            </label>
        </div>
    </div>
    <div>
        <button type="button" class="pm-btn pm-btn-primary" id="rc_go" onclick="rcCompare()">Bandingkan</button>
    </div>
    <div class="rc-hint">
        Ini hanya menyemak <strong>nama pelajar</strong> berbanding rekod cawangan yang dipilih. Peringkat/Kumpulan dalam PDF dipaparkan untuk rujukan sahaja — ia tidak disemak/diubah dalam sistem.
    </div>
</div>

<div class="rc-status" id="rc_status"></div>
<div id="rc_results"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<?php $pm_rpp_v = @filemtime(__DIR__ . '/roster_pdf_parser.js') ?: time(); ?>
<script src="roster_pdf_parser.js?v=<?= $pm_rpp_v ?>"></script>
<script>
const RC_CSRF = '<?= htmlspecialchars($csrf, ENT_QUOTES) ?>';
</script>
<?php
$pm_rc_js_v = @filemtime(__DIR__ . '/pic_roster_check.js') ?: time();
?>
<script src="pic_roster_check.js?v=<?= $pm_rc_js_v ?>"></script>

</main>
</body>
</html>
