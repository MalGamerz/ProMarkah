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

// "Nama Pelajar" no longer starts with a pre-existing blank row (see
// #addRows_student in pic_students.php) — it's populated only once the PIC
// actually pastes/generates a list or clicks "+ Tambah Baris Kosong", so a
// bare form submit could otherwise post zero student_name[] fields with no
// browser-side "required" validation to catch it (nothing exists yet to be
// required). This is that catch, wired to the "Tambah" button's onclick.
function pmValidateAddStudentSubmit() {
    const container = document.getElementById('addRows_student');
    const hasAnyName = Array.from(container.querySelectorAll('.add-row-student input'))
        .some(input => input.value.trim() !== '');
    if (hasAnyName) return true;

    pmSetStatusBadge('Sila taip atau tampal sekurang-kurangnya satu nama pelajar dahulu.', 'amber');
    document.getElementById('pasteNameList')?.focus();
    return false;
}

// ── Turn one-or-many pasted names into rows in one go ───────────────────────
// This is the same "Nama Pelajar" field for a single student or a whole
// list — Peringkat/Cawangan are already single-select fields on this form
// (one per submission), so this deliberately does NOT try to detect or
// split multiple Peringkat out of the pasted text: paste one block (for the
// Peringkat currently selected above), generate its rows, submit, then
// repeat for the next block with a different Peringkat selected.
//
// Accepts "N. Name" / "N Name" numbered lines (the format student rosters
// normally get pasted in) and strips the leading number. A single line with
// no number is always treated as one plain name — never discarded — since
// that's exactly the "just one student" case. Only with two or more lines
// is a leading unnumbered line treated as a header/title that came along
// with the copy-paste (e.g. "AWAN PUTIH CULA MERAH 2") and discarded;
// every other unnumbered line is still accepted as a plain name, in case
// the list has no numbering at all.
function parsePastedNameList(text) {
    const rawLines = (text || '').split(/\r\n|\r|\n/).map(l => l.trim()).filter(l => l !== '');
    if (rawLines.length === 1) {
        const m = rawLines[0].match(/^(\d{1,3})[.\s]+(.+)$/);
        return [m ? m[2].trim() : rawLines[0]];
    }
    const names = [];
    rawLines.forEach((line, i) => {
        const m = line.match(/^(\d{1,3})[.\s]+(.+)$/);
        if (m) {
            names.push(m[2].trim());
        } else if (i === 0) {
            return; // discard a leading unnumbered header/title line
        } else {
            names.push(line);
        }
    });
    return names;
}

// Reloads the Peringkat <select> to only the levels under the chosen Siri
// (see #addSiriSelect, only rendered when more than one Siri exists) — this
// is what keeps the pasted-header auto-match below unambiguous: once a
// single Siri is picked, level_name is compared as plain text with no
// " — Siri Name" suffix to strip.
async function reloadPeringkatOptionsForSiri(siriId) {
    const levelSelect = document.querySelector("#addStudent select[name='level_id']");
    if (!levelSelect || !siriId) return;
    try {
        const res = await fetch(`pic_students.php?ajax_levels_for_siri=1&siri=${encodeURIComponent(siriId)}`, { credentials: 'same-origin' });
        const levels = await res.json();
        levelSelect.innerHTML = (Array.isArray(levels) ? levels : [])
            .map(l => `<option value="${l.level_id}">${String(l.level_name).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}</option>`)
            .join('');
        // A header may already be pasted (Siri switched after pasting) — re-run
        // the match now that the Peringkat list has actually changed.
        tryAutoSelectPeringkatFromPastedHeader();
    } catch (e) {
        // Best-effort — leaves the previous Peringkat list in place on failure.
    }
}

// Strips a level <option>'s " — Siri Name" suffix — still relevant when no
// Siri picker is shown at all (a fresh install with zero Siri records, so
// pic_students.php falls back to its unscoped "every level, every siri"
// query) — and a leading "Ujian " word, which turned out to be how this
// installation's real level_name values are actually stored (e.g. "Ujian
// Awan Putih Cula Merah 2"), even though a PIC's own roster text never
// includes it (e.g. "AWAN PUTIH CULA MERAH 2") — confirmed against the
// live DB rather than assumed, after the first two attempts at this
// guessed wrong. Both strips make a pasted header match by Peringkat text
// alone, regardless of which naming convention happens to be in the DB.
function pmNormalizePeringkatText(s) {
    return (s || '')
        .replace(/\s+—\s+.*$/, '')
        .replace(/^\s*ujian\s+/i, '')
        .trim()
        .toUpperCase()
        .replace(/\s+/g, ' ');
}

function pmSetStatusBadge(text, variant) {
    const badgeEl = document.getElementById('pasteStatusBadge');
    if (!badgeEl) return;
    if (!text) { badgeEl.innerHTML = ''; return; }
    badgeEl.innerHTML = `<span class="pm-badge pm-badge-${variant}">${text}</span>`;
}

// Runs on every keystroke/paste into the "Nama Pelajar" textarea — with two
// or more lines pasted, the first is treated as a Peringkat header (see
// parsePastedNameList's discard rule) and matched against the Peringkat
// <select>'s options. A single exact match auto-selects it (dispatching
// change, same as picking it manually) so the PIC doesn't have to pick
// Peringkat AND paste separately; anything ambiguous or unmatched is left
// for manual selection, never guessed. A lone single line is always just
// one student's name (see parsePastedNameList) — never run through the
// Peringkat matcher.
function tryAutoSelectPeringkatFromPastedHeader() {
    const textarea = document.getElementById('pasteNameList');
    const levelSelect = document.querySelector("#addStudent select[name='level_id']");
    if (!textarea || !levelSelect) return;

    const lines = textarea.value.split(/\r\n|\r|\n/).map(l => l.trim()).filter(l => l !== '');
    if (lines.length < 2) { pmSetStatusBadge('', null); return; }

    const firstLine = lines[0];
    if (/^\d{1,3}[.\s]+/.test(firstLine)) { pmSetStatusBadge('', null); return; } // already a numbered name, no header pasted

    const target = pmNormalizePeringkatText(firstLine);
    if (!target) return;

    let matchedOption = null;
    let ambiguous = false;
    Array.from(levelSelect.options).forEach((opt) => {
        if (pmNormalizePeringkatText(opt.textContent) === target) {
            if (matchedOption && matchedOption !== opt) ambiguous = true;
            matchedOption = opt;
        }
    });

    if (matchedOption && !ambiguous) {
        if (levelSelect.value !== matchedOption.value) {
            levelSelect.value = matchedOption.value;
            levelSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }
        pmSetStatusBadge(`Peringkat dipilih automatik: ${firstLine}`, 'green');
    } else if (ambiguous) {
        pmSetStatusBadge(`"${firstLine}" sepadan lebih daripada satu Peringkat — pilih secara manual`, 'amber');
    } else {
        pmSetStatusBadge(`Tiada Peringkat sepadan dengan "${firstLine}" — pilih secara manual`, 'amber');
    }
}

// Fetches existing student names for the currently-selected Peringkat +
// Cawangan (see ajax_existing_names in pic_students.php), so pasted names
// that already exist can be flagged before submit — this is a WARNING only,
// the backend still has no duplicate-name constraint, so a flagged row can
// still be submitted deliberately (e.g. two students who really do share a
// name).
async function fetchExistingNamesForCurrentSelection() {
    const levelSelect  = document.querySelector("#addStudent select[name='level_id']");
    const schoolSelect = document.querySelector("#addStudent select[name='school_id']");
    const levelId  = levelSelect  ? levelSelect.value  : '';
    const schoolId = schoolSelect ? schoolSelect.value : '';
    if (!levelId || !schoolId) return new Set();
    try {
        const res = await fetch(`pic_students.php?ajax_existing_names=1&level_id=${encodeURIComponent(levelId)}&school_id=${encodeURIComponent(schoolId)}`, { credentials: 'same-origin' });
        const names = await res.json();
        return new Set((Array.isArray(names) ? names : []).map(n => String(n).trim().toLowerCase()));
    } catch (e) {
        return new Set(); // duplicate check is best-effort — a network hiccup shouldn't block generating rows
    }
}

async function generateRowsFromPastedList() {
    const textarea = document.getElementById('pasteNameList');
    const names = parsePastedNameList(textarea.value);
    if (!names.length) {
        textarea.focus();
        return;
    }

    pmSetStatusBadge('Menyemak pertindihan nama…', 'gray');
    const existingNames = await fetchExistingNamesForCurrentSelection();

    const container = document.getElementById('addRows_student');
    const existingRows = Array.from(container.querySelectorAll('.add-row-student'));

    const seenInBatch = new Set();
    let dbDupCount = 0;
    let batchDupCount = 0;

    names.forEach((name, i) => {
        // Reuse a single still-empty row if one already exists (e.g. from a
        // manual "+ Tambah Baris Kosong" click before pasting) instead of
        // leaving it as a stray empty entry alongside the generated names.
        let row = i === 0 && existingRows.length === 1 && existingRows[0].querySelector('input').value.trim() === ''
            ? existingRows[0]
            : null;
        if (!row) {
            addStudentRow();
            row = container.lastElementChild;
        }
        const input = row.querySelector('input');
        const select = row.querySelector('select');
        input.value = name;
        autoDetectGender(name, select);

        const key = name.trim().toLowerCase();
        const isDbDup    = existingNames.has(key);
        const isBatchDup = seenInBatch.has(key);
        seenInBatch.add(key);

        if (isDbDup || isBatchDup) {
            input.classList.add('name-duplicate');
            input.title = isDbDup
                ? 'Kemungkinan sudah wujud dalam Peringkat/Cawangan ini — semak sebelum hantar.'
                : 'Nama ini berulang dalam senarai yang ditampal — semak sebelum hantar.';
            if (isDbDup) dbDupCount++; else batchDupCount++;
        } else {
            input.classList.remove('name-duplicate');
            input.removeAttribute('title');
        }
    });

    const dupTotal = dbDupCount + batchDupCount;
    if (dupTotal === 0) {
        pmSetStatusBadge(`${names.length} pelajar dijana`, 'green');
    } else {
        const parts = [];
        if (dbDupCount)    parts.push(`${dbDupCount} sepadan pelajar sedia ada`);
        if (batchDupCount) parts.push(`${batchDupCount} berulang dalam senarai`);
        pmSetStatusBadge(`${names.length} pelajar dijana — ${dupTotal} kemungkinan pertindihan (${parts.join(', ')})`, 'amber');
    }

    textarea.value = '';
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
    if (/\b(?:binti|bte|bt|bnt)(?:\.|(?=\s|$))/i.test(name)) detected = 'Female';
    else if (/\b(?:bin|b|bn)(?:\.|(?=\s|$))/i.test(name)) detected = 'Male';
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
