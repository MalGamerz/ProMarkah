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
<style>
    .rc-upload-card {
        background: var(--c-surface-1);
        border: 1px solid var(--c-border-strong);
        border-radius: 10px;
        padding: 18px 20px;
        margin-bottom: 18px;
        display: grid;
        /* Cawangan and the file picker share the same 1fr, so they render
           the same width — was minmax(200px,1fr) / minmax(220px,2fr) before,
           which deliberately made the file picker twice as wide as the
           Cawangan dropdown. Bandingkan stays auto-sized to its own text
           since it's a button, not a field that needs to visually match. */
        grid-template-columns: minmax(200px,1fr) minmax(200px,1fr) auto;
        gap: 14px;
        align-items: end;
    }
    .rc-upload-card label {
        display: block; margin-bottom: 6px; font-size: .75rem; font-weight: 600;
        text-transform: uppercase; letter-spacing: .08em; color: var(--c-text-faint);
    }
    /* dashboard.css's shared .dd-trigger ships at height:32px !important —
       overridden here (via a more specific selector + !important of our own,
       which wins the specificity tiebreak inside the !important tier) so
       the Cawangan dropdown lines up with the file picker / Bandingkan
       button, both 40px tall on this row. */
    .rc-upload-card .dd-trigger {
        height: 40px !important;
        padding: 0 10px !important;
        font-size: 0.85rem !important;
    }
    .rc-upload-card button.pm-btn {
        height: 40px;
        box-sizing: border-box;
    }
    /* Native <input type=file> ignores flex/height styling applied to the
       input itself — its internal "Choose file" button + filename text is
       browser-drawn and doesn't respect align-items on the input tag, which
       is what made the button render pinned to the top instead of centered.
       Hiding the real input (kept clickable via the <label for>, not
       display:none, so it still opens the file picker) and building the
       visible control from scratch is the only way to control its layout. */
    .rc-file-wrap { position: relative; height: 40px; }
    .rc-file-native {
        position: absolute; inset: 0; width: 100%; height: 100%;
        opacity: 0; cursor: pointer; margin: 0;
    }
    /* Specificity note: .rc-upload-card label (class+element, 0-1-1) would
       otherwise beat a bare .rc-file-label (class only, 0-1-0) and force
       this back to display:block regardless of source order, since this
       control is itself a <label>. Qualifying with .rc-upload-card here
       (0-2-0) keeps it winning without having to touch that other rule. */
    .rc-upload-card .rc-file-label {
        display: flex; align-items: center; gap: 10px; height: 40px; box-sizing: border-box;
        background: var(--c-surface-2); border: 1px solid var(--c-border-strong); border-radius: 6px;
        padding: 0 6px 0 10px; cursor: pointer; overflow: hidden;
        margin-bottom: 0;
        transition: border-color 0.15s ease, background 0.15s ease;
    }
    .rc-file-btn {
        flex: 0 0 auto; background: var(--c-surface-3); border: 1px solid var(--c-border-strong);
        border-radius: 4px; padding: 6px 12px; font-size: 0.78rem; font-weight: 600;
        color: var(--c-white); white-space: nowrap;
    }
    .rc-file-name {
        flex: 1; min-width: 0; font-size: 0.85rem; color: var(--c-text-faint);
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .rc-file-native:focus-visible + .rc-file-label { border-color: var(--c-red); box-shadow: 0 0 0 3px var(--c-red-dim); }
    .rc-file-native:hover + .rc-file-label { border-color: var(--c-red); background: var(--c-surface-3); }
    .rc-file-native:hover + .rc-file-label .rc-file-btn { border-color: var(--c-red); }
    html.pm-light .rc-file-label { background: #f9fafb; border-color: #d1d5db; }
    html.pm-light .rc-file-btn { background: #fff; border-color: #d1d5db; color: #111; }
    html.pm-light .rc-file-native:hover + .rc-file-label { background: #f3f4f6; }
    .rc-hint {
        grid-column: 1 / -1; font-size: 0.8rem; color: var(--c-text-faint);
    }
    .rc-status { margin: 10px 0; font-size: 0.85rem; color: var(--c-text-faint); }
    .rc-summary {
        display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 18px;
    }
    .rc-stat {
        background: var(--c-surface-1); border: 1px solid var(--c-border-strong);
        border-radius: 8px; padding: 10px 16px; min-width: 120px;
    }
    .rc-stat .n { font-size: 1.4rem; font-weight: 700; font-family: 'Bebas Neue', sans-serif; }
    .rc-stat .l { font-size: 0.72rem; color: var(--c-text-faint); text-transform: uppercase; letter-spacing: .06em; }
    .rc-stat.found .n { color: #4ade80; }
    .rc-stat.close .n { color: #facc15; }
    .rc-stat.missing .n { color: var(--c-red); }

    .rc-peringkat { margin-bottom: 16px; }
    .rc-peringkat-title {
        font-family: 'Bebas Neue', sans-serif; font-size: 1.05rem; letter-spacing: .04em;
        color: var(--c-white); margin-bottom: 8px;
    }
    .rc-kumpulan { margin-bottom: 10px; }
    .rc-kumpulan-title { font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--c-text-faint); margin-bottom: 4px; }
    .rc-row {
        display: flex; align-items: center; gap: 10px; padding: 7px 12px;
        border-bottom: 1px solid var(--c-border); font-size: 0.85rem;
    }
    .rc-row:last-child { border-bottom: none; }
    .rc-badge {
        flex: 0 0 auto; font-size: 0.7rem; font-weight: 700; padding: 2px 8px;
        border-radius: 99px; text-transform: uppercase; letter-spacing: .04em;
    }
    .rc-badge.found   { background: rgba(74,222,128,.15); color: #4ade80; }
    .rc-badge.close   { background: rgba(250,204,21,.15); color: #facc15; }
    .rc-badge.missing { background: rgba(214,40,40,.15); color: var(--c-red); }
    .rc-row .name { flex: 1; color: var(--c-white); }
    .rc-row .matchnote { color: var(--c-text-faint); font-size: 0.78rem; }
    .rc-unclaimed {
        margin-top: 24px; padding-top: 16px; border-top: 1px dashed var(--c-border);
    }
    .rc-unclaimed-title { font-size: 0.85rem; font-weight: 700; color: var(--c-text-faint); margin-bottom: 8px; }
    .rc-unclaimed ul { margin: 0; padding-left: 20px; color: var(--c-text-muted); font-size: 0.85rem; }
    html.pm-light .rc-upload-card, html.pm-light .rc-stat { background: #fff; border-color: #e5e7eb; }
    html.pm-light .rc-row { border-bottom-color: #e5e7eb; }
    html.pm-light .rc-row .name { color: #111; }

    @media (max-width: 640px) {
        .rc-upload-card { grid-template-columns: 1fr; }
    }
</style>

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
            <div class="dd-trigger" id="ddTrigger_rcschool" onclick="rcDdToggle()">
                <span id="ddLabel_rcschool" style="color:var(--c-text-faint);">-- Pilih Cawangan --</span>
                <span class="dd-arrow">▼</span>
            </div>
            <div class="dd-panel" id="ddPanel_rcschool">
                <div class="dd-search-box">
                    <input type="text" placeholder="Cari cawangan..." oninput="rcDdFilter(this.value)" onclick="event.stopPropagation()">
                </div>
                <div class="dd-options" id="ddOpts_rcschool">
                    <?php foreach ($schools as $s): ?>
                    <div class="dd-opt" data-value="<?= $s['school_id'] ?>" onclick="rcDdSelect('<?= $s['school_id'] ?>','<?= htmlspecialchars($s['school_name'], ENT_QUOTES) ?>')"><?= htmlspecialchars($s['school_name']) ?></div>
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
if (window['pdfjsLib']) {
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
}

const RC_CSRF = '<?= htmlspecialchars($csrf, ENT_QUOTES) ?>';

// ── Cawangan searchable dropdown (matches the .dd- component used on
//    pic_groups.php/pic_view_marks.php/pic_manual_marks.php) — replaces the
//    plain native <select> that looked out of place next to the custom file
//    picker and button. rcCompare() reads #rc_school.value exactly as
//    before; it's just a hidden input now instead of a <select>. ──
function rcDdToggle() {
    const t = document.getElementById('ddTrigger_rcschool');
    const p = document.getElementById('ddPanel_rcschool');
    const open = p.classList.contains('open');
    document.querySelectorAll('.dd-panel.open').forEach(x => x.classList.remove('open'));
    document.querySelectorAll('.dd-trigger.open').forEach(x => x.classList.remove('open'));
    if (!open) {
        p.classList.add('open'); t.classList.add('open');
        setTimeout(() => p.querySelector('.dd-search-box input')?.focus(), 50);
    }
}
function rcDdFilter(val) {
    const opts = document.querySelectorAll('#ddOpts_rcschool .dd-opt');
    const empty = document.getElementById('ddEmpty_rcschool');
    let any = false;
    opts.forEach(o => {
        const m = o.textContent.toLowerCase().includes(val.toLowerCase());
        o.classList.toggle('hidden', !m);
        if (m) any = true;
    });
    if (empty) empty.style.display = any ? 'none' : 'block';
}
function rcDdSelect(value, label) {
    document.getElementById('rc_school').value = value;
    const lbl = document.getElementById('ddLabel_rcschool');
    lbl.textContent = label;
    lbl.style.color = '';
    document.querySelectorAll('#ddOpts_rcschool .dd-opt').forEach(o => o.classList.toggle('selected', o.dataset.value === value));
    document.getElementById('ddPanel_rcschool').classList.remove('open');
    document.getElementById('ddTrigger_rcschool').classList.remove('open');
}
document.addEventListener('click', e => {
    if (!e.target.closest('.dd-wrap')) {
        document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
        document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
    }
});

function rcSetStatus(msg) {
    document.getElementById('rc_status').textContent = msg;
}

function rcFileChanged() {
    const files = document.getElementById('rc_file').files;
    document.getElementById('rc_file_name').textContent = files.length ? files[0].name : 'Tiada fail dipilih';
}

// PDF line-reconstruction + peringkat/kumpulan grouping (pmRosterExtractLines,
// pmRosterParseGroups, pmRosterDetectTailAnchor, pmRosterStripUsingAnchor)
// now lives in roster_pdf_parser.js, shared with upload_students.php's PDF
// import — see that file for the full reasoning behind each step.

async function rcCompare() {
    const schoolSel = document.getElementById('rc_school');
    const schoolId = schoolSel.value;
    const fileEl = document.getElementById('rc_file');
    const resultsEl = document.getElementById('rc_results');
    resultsEl.innerHTML = '';

    if (!schoolId) { rcSetStatus('Sila pilih cawangan dahulu.'); return; }
    if (!fileEl.files.length) { rcSetStatus('Sila muat naik fail PDF dahulu.'); return; }
    if (!window['pdfjsLib']) { rcSetStatus('Ralat: pustaka PDF gagal dimuatkan. Sila semak sambungan internet.'); return; }

    const goBtn = document.getElementById('rc_go');
    goBtn.disabled = true;
    rcSetStatus('Membaca PDF...');

    try {
        const buf = await fileEl.files[0].arrayBuffer();
        const pdf = await pdfjsLib.getDocument({ data: buf }).promise;
        const lines = await pmRosterExtractLines(pdf);
        const groups = pmRosterParseGroups(lines);

        const totalNames = groups.reduce((n, g) => n + g.students.length, 0);
        if (totalNames === 0) {
            rcSetStatus('Tidak dapat mengesan sebarang nama pelajar dalam PDF ini. Pastikan format PDF sama seperti contoh rasmi.');
            goBtn.disabled = false;
            return;
        }

        rcSetStatus('Membandingkan ' + totalNames + ' nama dengan rekod sistem...');
        const resp = await pmFetch('pic_roster_check.php?ajax_compare=1', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: RC_CSRF, school_id: schoolId, groups }),
        });
        const data = await resp.json();
        goBtn.disabled = false;
        if (!data.ok) { rcSetStatus(data.msg || 'Ralat semasa membandingkan.'); return; }
        rcSetStatus('');
        rcRender(data);
    } catch (err) {
        goBtn.disabled = false;
        rcSetStatus('Ralat membaca PDF: ' + err.message);
    }
}

function rcRender(data) {
    const resultsEl = document.getElementById('rc_results');
    let found = 0, close = 0, missing = 0;
    data.groups.forEach(g => g.students.forEach(s => {
        if (s.status === 'found') found++;
        else if (s.status === 'close') close++;
        else missing++;
    }));

    let html = `<div class="rc-summary">
        <div class="rc-stat found"><div class="n">${found}</div><div class="l">Ditemui</div></div>
        <div class="rc-stat close"><div class="n">${close}</div><div class="l">Hampir Sama</div></div>
        <div class="rc-stat missing"><div class="n">${missing}</div><div class="l">Tidak Ditemui</div></div>
        <div class="rc-stat"><div class="n">${data.db_total}</div><div class="l">Jumlah Dalam Sistem</div></div>
    </div>`;

    const badgeLabel = { found: 'Ditemui', close: 'Hampir Sama', missing: 'Tiada' };
    data.groups.forEach(g => {
        html += `<div class="rc-peringkat">`;
        if (g.peringkat) html += `<div class="rc-peringkat-title">${rcEsc(g.peringkat)}</div>`;
        html += `<div class="rc-kumpulan">`;
        if (g.kumpulan) html += `<div class="rc-kumpulan-title">${rcEsc(g.kumpulan)}</div>`;
        g.students.forEach(s => {
            const note = s.status === 'close'
                ? `Sepadan dengan "${rcEsc(s.match)}" dalam sistem (${s.pct}%)`
                : (s.status === 'found' && s.match.toUpperCase() !== s.name.toUpperCase()
                    ? `Rekod sistem: "${rcEsc(s.match)}"` : '');
            html += `<div class="rc-row">
                <span class="rc-badge ${s.status}">${badgeLabel[s.status]}</span>
                <span class="name">${rcEsc(s.name)}</span>
                ${note ? `<span class="matchnote">${note}</span>` : ''}
            </div>`;
        });
        html += `</div></div>`;
    });

    if (data.unclaimed.length) {
        html += `<div class="rc-unclaimed">
            <div class="rc-unclaimed-title">Dalam sistem tetapi tidak disebut dalam PDF ini (${data.unclaimed.length}) — semak jika ini pelajar peringkat lain atau perlu disemak semula:</div>
            <ul>${data.unclaimed.map(n => `<li>${rcEsc(n)}</li>`).join('')}</ul>
        </div>`;
    }

    resultsEl.innerHTML = html;
}

function rcEsc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
</script>

</main>
</body>
</html>
