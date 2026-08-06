<?php
// ══════════════════════════════════════════════════════════════════
//  attendance_student.php — Student self-registration view
//  Rendered standalone (no navbar/sidebar). Called internally
//  by attendance.php when ?mode=student is present.
// ══════════════════════════════════════════════════════════════════

// Expects these variables from the caller:
//   $conn, $school_id, $session_id, $school_name,
//   $session_name, $is_global,
//   $student_list (local), $schools_list (global),
//   $students_by_school (global)

?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Kehadiran — <?= htmlspecialchars($school_name) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&family=Bebas+Neue&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --red:          #b30000;
            --red-dark:     #800000;
            --red-dim:      rgba(179,0,0,.08);
            --red-border:   rgba(179,0,0,.2);
            --surface:      #f0f2f5;
            --surface-1:    #ffffff;
            --surface-2:    #f9fafb;
            --border:       #e5e7eb;
            --border-focus: #b30000;
            --text:         #111827;
            --muted:        #4b5563;
            --faint:        #9ca3af;
            --radius:       12px;
            --ease:         cubic-bezier(0.16, 1, 0.3, 1);
        }

        body {
            font-family: 'DM Sans', system-ui, sans-serif;
            background: var(--surface);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding: 40px 16px;
            -webkit-font-smoothing: antialiased;
        }

        /* ── Card ───────────────────────────────────────────────── */
        .form-card {
            width: 100%;
            max-width: 540px;
            background: var(--surface-1);
            border: 1px solid var(--border);
            border-top: 8px solid var(--red);
            border-radius: 8px;
            padding: 40px 32px;
            box-shadow: 0 4px 12px rgba(0,0,0,.05), 0 1px 2px rgba(0,0,0,.05);
            animation: slideUp .4s var(--ease) both;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── Branding ───────────────────────────────────────────── */
        .form-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 28px;
        }
        .form-brand-dot {
            width: 12px; height: 12px;
            border-radius: 50%;
            background: var(--red);
            flex-shrink: 0;
        }
        .form-brand-label {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.5rem;
            letter-spacing: .06em;
            color: var(--text);
        }
        .form-brand-sub {
            font-size: 0.72rem;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--red);
            font-weight: 700;
            margin-left: auto;
        }

        /* ── Headings & Badges ──────────────────────────────────── */
        .form-heading {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 2.4rem;
            letter-spacing: .02em;
            line-height: 1.1;
            margin-bottom: 10px;
        }
        .form-subheading {
            font-size: 0.9rem;
            color: var(--muted);
            margin-bottom: 32px;
        }
        .form-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }
        .form-badge {
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--muted);
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
            padding: 4px 10px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .form-badge-red {
            background: var(--red-dim);
            border-color: var(--red-border);
            color: var(--red);
        }

        /* ── Form Controls ──────────────────────────────────────── */
        .form-label {
            display: block;
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 8px;
        }
        .form-select-wrap {
            position: relative;
            margin-bottom: 24px;
        }
        .form-select {
            width: 100%;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text);
            font-family: 'DM Sans', sans-serif;
            font-size: 1rem;
            padding: 14px 42px 14px 16px;
            appearance: none;
            -webkit-appearance: none;
            outline: none;
            cursor: pointer;
            transition: border-color .2s, box-shadow .2s;
        }
        .form-select:focus {
            border-color: var(--border-focus);
            background: #fff;
            box-shadow: 0 0 0 3px var(--red-dim);
        }
        .form-select option { background: #fff; color: var(--text); }
        .form-select-arrow {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            color: var(--muted);
        }

        /* ── Searchable combobox ─────────────────────────────────── */
        .combo-wrap { position: relative; margin-bottom: 24px; }
        .combo-input { padding-right: 42px; }
        .combo-input:disabled { cursor: not-allowed; opacity: .6; }
        .combo-list {
            display: none;
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            right: 0;
            max-height: 240px;
            overflow-y: auto;
            background: var(--surface-1);
            border: 1px solid var(--border);
            border-radius: 8px;
            box-shadow: 0 8px 20px rgba(0,0,0,.1);
            z-index: 20;
        }
        .combo-list.open { display: block; }
        .combo-item {
            padding: 11px 16px;
            font-size: 0.95rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .combo-item:hover, .combo-item.active { background: var(--red-dim); }
        .combo-item-present { font-size: 0.72rem; color: #10b981; font-weight: 700; text-transform: uppercase; }
        .combo-empty { padding: 14px 16px; font-size: 0.88rem; color: var(--muted); text-align: center; }
        /* School name takes the available width; count pill sits at the END */
        .school-item-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        /* Student count as a uniform pill so every row lines up neatly */
        .combo-item-count {
            flex: none;
            min-width: 54px;
            text-align: center;
            font-size: 0.78rem;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
            color: var(--text);
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 3px 10px;
        }
        .combo-item-count.is-full  { color: #047857; background: #ecfdf5; border-color: #a7f3d0; }
        .combo-item-count.is-empty { opacity: .5; }
        /* Div acting as the select trigger for the custom school dropdown */
        #schoolTrigger { display: flex; align-items: center; }
        #schoolTrigger .school-trigger-placeholder { color: var(--muted); }

        /* ── Confirm Box ────────────────────────────────────────── */
        .confirm-box {
            display: none;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 18px 20px;
            margin-bottom: 24px;
            align-items: center;
            gap: 14px;
            animation: fadeIn .2s ease both;
        }
        .confirm-box.visible { display: flex; }
        .confirm-avatar {
            width: 44px; height: 44px;
            border-radius: 50%;
            background: #fff;
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
        }
        .confirm-name  { font-weight: 700; font-size: 1rem; margin-bottom: 2px; }
        .confirm-school { font-size: 0.85rem; color: var(--muted); }

        /* ── Already Present Notice ─────────────────────────────── */
        .already-present {
            display: none;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            border-radius: 8px;
            padding: 16px 20px;
            color: #065f46;
            font-weight: 600;
            font-size: 0.95rem;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
        }
        .already-present.visible { display: flex; }

        /* ── Submit Button ──────────────────────────────────────── */
        .btn-submit {
            width: 100%;
            background: var(--red);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-family: 'DM Sans', sans-serif;
            font-size: 1.05rem;
            font-weight: 700;
            letter-spacing: .02em;
            padding: 16px;
            cursor: pointer;
            transition: background .15s, transform .1s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-submit:hover    { background: var(--red-dark); }
        .btn-submit:active   { transform: scale(.98); }
        .btn-submit:disabled { background: #e5e7eb; color: #9ca3af; cursor: not-allowed; }

        /* ── Success State ──────────────────────────────────────── */
        .success-state {
            display: none;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 14px;
            padding: 20px 0;
        }
        .success-state.visible { display: flex; }
        .success-checkmark {
            width: 72px; height: 72px;
            border-radius: 50%;
            background: #10b981;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
        }
        .success-title { font-family: 'Bebas Neue', sans-serif; font-size: 2rem; letter-spacing: .03em; }
        .success-sub   { font-size: 0.95rem; color: var(--muted); line-height: 1.6; }

        /* ── Footer ─────────────────────────────────────────────── */
        .form-divider { border: none; border-top: 1px solid var(--border); margin: 28px 0 20px; }
        .form-note    { font-size: 0.82rem; color: var(--faint); line-height: 1.6; }

        @keyframes fadeIn {
            from { opacity: 0; transform: scale(.97); }
            to   { opacity: 1; transform: scale(1); }
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        @media (max-width: 480px) {
            .form-card    { padding: 28px 20px; }
            .form-heading { font-size: 2rem; }
        }
    </style>
</head>
<body>
<div class="form-card">

    <div class="form-brand">
        <div class="form-brand-dot"></div>
        <div class="form-brand-label">ProMarkah</div>
        <div class="form-brand-sub">Pendaftaran Kendiri</div>
    </div>

    <div class="form-heading">Daftar Kehadiran</div>

    <div class="form-badges">
        <?php if (!empty($siri_name)): ?>
        <span class="form-badge">🗂 <?= htmlspecialchars($siri_name) ?></span>
        <?php endif; ?>
        <span class="form-badge">📅 <?= htmlspecialchars($session_name) ?></span>
        <span class="form-badge form-badge-red">📍 <?= htmlspecialchars($school_name) ?></span>
    </div>
    <div class="form-subheading">Sila lengkapkan maklumat di bawah untuk merekodkan kehadiran anda.</div>

    <div id="formArea">

        <?php if ($is_global): ?>
        <label class="form-label" for="schoolTrigger">Pilih Cawangan Anda</label>
        <!-- Custom dropdown (not a native <select>) so each row can show the
             student count right-aligned at the END, matching the student combo. -->
        <div class="combo-wrap" id="schoolCombo">
            <div class="form-select" id="schoolTrigger" tabindex="0" role="button" aria-haspopup="listbox"
                 onclick="toggleSchoolList()"
                 onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();toggleSchoolList();}">
                <span id="schoolTriggerText" class="school-trigger-placeholder">— Sila Pilih Cawangan —</span>
            </div>
            <input type="hidden" id="schoolSelect" value="">
            <svg class="form-select-arrow" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
            <div class="combo-list" id="schoolComboList" role="listbox">
                <?php foreach ($schools_list as $sch): ?>
                <?php
                    // Student count for this school in this session — reuse the
                    // already-loaded $students_by_school (keyed by int school_id)
                    // so no extra query is needed.
                    $sch_students = $students_by_school[(int)$sch['school_id']] ?? [];
                    $sch_total    = count($sch_students);
                    $sch_present  = 0;
                    foreach ($sch_students as $s) { if (!empty($s['is_present'])) { $sch_present++; } }
                    // Status tint: green when everyone's checked in, faded when
                    // the branch has no students in this session.
                    $count_class = 'combo-item-count';
                    if ($sch_total > 0 && $sch_present >= $sch_total) { $count_class .= ' is-full'; }
                    elseif ($sch_total === 0)                        { $count_class .= ' is-empty'; }
                ?>
                <div class="combo-item school-item" role="option"
                     data-id="<?= (int)$sch['school_id'] ?>"
                     data-name="<?= htmlspecialchars($sch['school_name'], ENT_QUOTES) ?>">
                    <span class="school-item-name"><?= htmlspecialchars($sch['school_name']) ?></span>
                    <span class="<?= $count_class ?>"><?= $sch_present ?>/<?= $sch_total ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <label class="form-label" for="studentSearch">Nama Pesilat</label>
        <div class="combo-wrap" id="studentCombo">
            <input type="text" class="form-select combo-input" id="studentSearch"
                   placeholder="Taip untuk cari nama anda..."
                   autocomplete="off"
                   <?= $is_global ? 'disabled' : '' ?>>
            <svg class="form-select-arrow" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
            <input type="hidden" id="studentSelect" value="">
            <div class="combo-list" id="studentComboList"></div>
        </div>

        <div class="already-present" id="alreadyPresent">
            <span>✓</span>
            <span>Anda sudah pun didaftarkan hadir untuk sidang ini.</span>
        </div>

        <div class="confirm-box" id="confirmBox">
            <div class="confirm-avatar">👤</div>
            <div>
                <div class="confirm-name"  id="confirmName"></div>
                <div class="confirm-school" id="confirmSchool"><?= htmlspecialchars($school_name) ?></div>
            </div>
        </div>

        <button class="btn-submit" id="submitBtn" disabled onclick="submitAttendance()">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <polyline points="20 6 9 17 4 12"/>
            </svg>
            Sahkan Kehadiran
        </button>
    </div>

    <div class="success-state" id="successState">
        <div class="success-checkmark">✓</div>
        <div class="success-title">Kehadiran Direkod!</div>
        <div class="success-sub" id="successMsg">Terima kasih. Kehadiran anda telah berjaya didaftarkan ke dalam sistem.</div>
        <button type="button" class="btn-submit" id="anotherBtn" onclick="registerAnother()" style="margin-top:10px;">
            Daftar Pesilat Lain
        </button>
        <button type="button" class="btn-submit" id="closeBtn" onclick="closeTab()"
            style="margin-top:10px; background:transparent; color:var(--muted); border:1px solid var(--border);">
            Tutup
        </button>
    </div>

    <hr class="form-divider">
    <p class="form-note">Jika nama anda tidak tersenarai, sila hubungi PIC dengan segera.</p>
</div>

<script>
const isGlobal          = <?= $is_global ? 'true' : 'false' ?>;
const studentsBySchool  = <?= $is_global ? json_encode($students_by_school) : '{}' ?>;
const localStudentList  = <?= $is_global ? '[]' : json_encode($student_list) ?>;
const currentSessionId  = <?= (int)$session_id ?>;
let   currentSchoolName = <?= json_encode($school_name) ?>;
let   currentStudents   = isGlobal ? [] : localStudentList;

const searchInput = document.getElementById('studentSearch');
const comboList    = document.getElementById('studentComboList');
const hiddenSelect  = document.getElementById('studentSelect');

// Student/school names come straight from the database (set via
// pic_students.php / upload_students.php / pic_schools.php) and are used
// below via innerHTML — this page has no login, so anyone with the QR link
// can reach it, making unescaped user-controlled text here a real DOM-XSS
// risk. Matches the same escapeHtml() pattern already used in leaderboard.php.
function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

// ── School picker (global QR only) — custom dropdown so the student
//    count can sit right-aligned at the END of each row ─────────────
function toggleSchoolList() {
    document.getElementById('schoolComboList').classList.toggle('open');
}
function closeSchoolList() {
    const list = document.getElementById('schoolComboList');
    if (list) list.classList.remove('open');
}
function selectSchool(schoolId, schoolName) {
    document.getElementById('schoolSelect').value = schoolId;
    const trig = document.getElementById('schoolTriggerText');
    trig.textContent = schoolName;
    trig.classList.remove('school-trigger-placeholder');
    closeSchoolList();

    resetConfirmUI();
    searchInput.value = '';
    hiddenSelect.value = '';
    closeComboList();

    // Use the clean school name (NOT the row text, which also carries the count).
    currentSchoolName = schoolName;
    document.getElementById('confirmSchool').textContent = schoolName;
    searchInput.disabled = false;
    currentStudents = studentsBySchool[schoolId] || [];
}

if (isGlobal) {
    document.querySelectorAll('#schoolComboList .school-item').forEach(function (el) {
        el.addEventListener('click', function () {
            selectSchool(el.dataset.id, el.dataset.name);
        });
    });
    document.addEventListener('click', function (e) {
        const combo = document.getElementById('schoolCombo');
        if (combo && !combo.contains(e.target)) closeSchoolList();
    });
}

// ── Searchable combobox ────────────────────────────────────────────
function renderComboList(filter) {
    const term = (filter || '').trim().toLowerCase();
    const matches = currentStudents.filter(s => s.student_name.toLowerCase().includes(term));

    comboList.innerHTML = '';

    if (matches.length === 0) {
        comboList.innerHTML = '<div class="combo-empty">Tiada nama dijumpai.</div>';
    } else {
        matches.slice(0, 50).forEach(s => {
            const item = document.createElement('div');
            item.className = 'combo-item';
            item.innerHTML = `<span>${escapeHtml(s.student_name)}</span>` +
                (s.is_present ? '<span class="combo-item-present">✓ Hadir</span>' : '');
            item.addEventListener('click', () => selectStudent(s));
            comboList.appendChild(item);
        });
    }

    comboList.classList.add('open');
}

function closeComboList() {
    comboList.classList.remove('open');
}

function selectStudent(s) {
    hiddenSelect.value = s.student_id;
    searchInput.value  = s.student_name;
    closeComboList();

    resetConfirmUI();
    if (s.is_present) {
        document.getElementById('alreadyPresent').classList.add('visible');
    } else {
        document.getElementById('confirmName').textContent = s.student_name;
        document.getElementById('confirmBox').classList.add('visible');
        document.getElementById('submitBtn').disabled = false;
    }
}

searchInput.addEventListener('input', () => {
    hiddenSelect.value = '';
    resetConfirmUI();
    renderComboList(searchInput.value);
});

searchInput.addEventListener('focus', () => {
    if (!searchInput.disabled) renderComboList(searchInput.value);
});

document.addEventListener('click', (e) => {
    if (!document.getElementById('studentCombo').contains(e.target)) closeComboList();
});

// ── Submission ────────────────────────────────────────────────────
function submitAttendance() {
    if (!hiddenSelect.value) return;

    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation:spin .6s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Menghantar...';

    const name = searchInput.value;

    fetch('save_attendance.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    `student_id=${hiddenSelect.value}&session_id=${currentSessionId}&status=Present`
    })
    .then(r => r.text())
    .then(data => {
        if (data.trim() === 'success') {
            document.getElementById('formArea').style.display = 'none';
            document.getElementById('successMsg').innerHTML =
                `<strong>${escapeHtml(name)}</strong><br>Kehadiran berjaya didaftarkan untuk cawangan ${escapeHtml(currentSchoolName)}.`;
            document.getElementById('successState').classList.add('visible');
        } else {
            btn.disabled = false;
            btn.innerHTML = 'Cuba Semula';
            alert('Ralat: ' + data);
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = 'Cuba Semula';
        alert('Ralat rangkaian. Sila pastikan anda mempunyai sambungan internet.');
    });
}

// ── Register another student (same school for local QR, re-pick for global) ──
function registerAnother() {
    document.getElementById('successState').classList.remove('visible');
    document.getElementById('formArea').style.display = '';

    searchInput.value = '';
    hiddenSelect.value = '';
    closeComboList();
    resetConfirmUI();

    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = `<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> Sahkan Kehadiran`;

    if (isGlobal) {
        document.getElementById('schoolSelect').value = '';
        const trig = document.getElementById('schoolTriggerText');
        trig.textContent = '— Sila Pilih Cawangan —';
        trig.classList.add('school-trigger-placeholder');
        closeSchoolList();
        currentStudents = [];
        currentSchoolName = 'Cawangan';
        searchInput.disabled = true;
    }

    searchInput.focus();
}

// ── Close tab (browsers block this unless the tab was script-opened —
// fall back to telling the user to close it manually) ───────────────
function closeTab() {
    window.close();
    setTimeout(() => {
        if (!document.hidden) {
            // Browser blocked the close (tab wasn't script-opened) — tell the
            // user to close it manually instead of leaving a dead button.
            document.getElementById('successMsg').innerHTML +=
                '<br><span style="color:var(--faint);font-size:0.85rem;">Anda boleh tutup tab/pelayar ini secara manual.</span>';
            document.getElementById('closeBtn').style.display = 'none';
        }
    }, 300);
}

// ── UI helper ─────────────────────────────────────────────────────
function resetConfirmUI() {
    document.getElementById('alreadyPresent').classList.remove('visible');
    document.getElementById('confirmBox').classList.remove('visible');
    document.getElementById('submitBtn').disabled = true;
}
</script>
</body>
</html>
<?php exit(); ?>
