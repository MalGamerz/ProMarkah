// attendance_student.js — the public QR self-registration page
// (attendance_student.php): school picker (global QR only), searchable
// student combobox, and the attendance submit flow.
//
// Split out of that file's inline <script> block. The 5 values this used
// to interpolate directly from PHP are now read off window.pmAttendanceStudentData
// instead, set by a small inline bootstrap snippet right before this file's
// <script src> tag — same pattern used for judge.php's judge_marking.js.

const isGlobal          = window.pmAttendanceStudentData.isGlobal;
const studentsBySchool  = window.pmAttendanceStudentData.studentsBySchool;
const localStudentList  = window.pmAttendanceStudentData.localStudentList;
const currentSessionId  = window.pmAttendanceStudentData.currentSessionId;
let   currentSchoolName = window.pmAttendanceStudentData.currentSchoolName;
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
