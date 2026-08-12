// pic_students.js — the PIC "Pengurusan Pelajar" (student management) page
// (pic_students.php). Split out of that file's inline <script> block, which
// had nothing to do with the PHP rendering logic around it. No PHP
// interpolation here, so this is a plain file move, no bootstrap-data
// object needed.

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
        sessOpts.innerHTML = "<div class='dd-opt selected' role='option' tabindex='0' data-value='' onclick=\"ddSelect('session','','-- Semua Sidang --')\">-- Semua Sidang --</div>";
        sessTrigger.classList.add('dd-trigger-disabled');
        return;
    }
    sessLbl.textContent = '-- Semua Sidang --';
    sessOpts.innerHTML = "<div class='dd-opt selected' role='option' tabindex='0' data-value='' onclick=\"ddSelect('session','','-- Semua Sidang --')\">-- Semua Sidang --</div>";
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
