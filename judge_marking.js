// judge_marking.js — the actual marking-table screen in judge.php: desktop
// per-ujian tab switching, server-side draft autosave, the keypad/toggle
// mark-entry UI, the "Tambah Ujian & Kriteria" modal (deferred add — nothing
// touches the DB until "Selesai Pemarkahan"), and the missing-marks
// pre-submit validation.
//
// Only loaded when a group is actively being marked (judge.php only prints
// this <script src> tag inside its marking-active branch). Split out of
// judge.php's inline <script> since none of this logic itself needs a
// PHP-templated value directly — the 5 values it does need (criteriaByTest,
// testIdsMap, currentLevelId, currentLevelName, currentSessionId) are set
// on window.pmJudgeMarkingData by a tiny inline snippet right before this
// file's <script src> tag in judge.php, instead of being interpolated here.
//
// criteriaByTest itself stays a bare global (not read off
// window.pmJudgeMarkingData) because judge_dashboard.js, loaded earlier on
// every page load, already declares `let criteriaByTest = {}` — this file
// reassigns that same global rather than shadowing it, exactly as the
// original single-file version did.

// Populate variables from PHP (via window.pmJudgeMarkingData — see judge.php)
criteriaByTest = window.pmJudgeMarkingData.criteriaByTest;
const testIdsMap = window.pmJudgeMarkingData.testIdsMap;
const currentLevelId = window.pmJudgeMarkingData.currentLevelId;
const currentLevelName = window.pmJudgeMarkingData.currentLevelName;
const currentSessionId = window.pmJudgeMarkingData.currentSessionId;

// --- DESKTOP UJIAN TAB SWITCHING ---
// Same one-panel-at-a-time behavior as the mobile ribbon tabs, but for
// the desktop table split by ujian. Matched by test name (not index) so
// it stays correct even after a new ujian is appended mid-session.
document.addEventListener('click', function (e) {
    if (!e.target.classList.contains('desktop-ujian-tab-btn')) return;
    const testName = e.target.dataset.testName;

    document.querySelectorAll('.desktop-ujian-tab-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.testName === testName);
    });
    document.querySelectorAll('.desktop-ujian-tab-panel').forEach(panel => {
        panel.style.display = panel.dataset.testName === testName ? 'block' : 'none';
    });
});

// --- SERVER-SIDE DRAFT AUTOSAVE ---
// Persists each mark to the DB as submitted=0 so an accidental back-nav,
// tab close, or crash doesn't lose in-progress marking. Debounced per
// cell, with an immediate flush of any pending saves when the tab is
// hidden/closed so nothing is lost to the debounce delay.
const draftDebounce = {};
const draftDirty = {};

function queueDraftSave(studentId, criteriaId, value) {
    const key = studentId + '_' + criteriaId;
    draftDirty[key] = { studentId, criteriaId, value };
    clearTimeout(draftDebounce[key]);
    draftDebounce[key] = setTimeout(() => flushDraftSave(key), 500);
}

function flushDraftSave(key, useBeacon) {
    const entry = draftDirty[key];
    if (!entry) return;
    delete draftDirty[key];
    clearTimeout(draftDebounce[key]);

    const form = document.getElementById('markForm');
    if (!form) return;
    const body = new URLSearchParams({
        group_id: form.group_id.value,
        student_id: entry.studentId,
        criteria_id: entry.criteriaId,
        mark: entry.value,
        csrf_token: form.csrf_token.value
    });

    if (useBeacon && navigator.sendBeacon) {
        navigator.sendBeacon('save_draft_score.php', body);
    } else {
        // Fires on nearly every keystroke, so this also doubles as the
        // fastest possible signal that the session has expired — the
        // judge finds out within one field edit instead of only when
        // they next touch a dropdown or refresh.
        pmFetch('save_draft_score.php', { method: 'POST', body, keepalive: true }).catch(() => {});
    }
}

function flushAllDrafts(useBeacon) {
    Object.keys(draftDirty).forEach(key => flushDraftSave(key, useBeacon));
}

document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') flushAllDrafts(true);
});
window.addEventListener('pagehide', function () { flushAllDrafts(true); });

// --- KEYPAD & TOGGLE LOGIC ---
document.addEventListener('change', function (e) {
    // 1. Toggle "Dinilai / Abai" Switch
    if (e.target.classList.contains('pm-nilai-checkbox')) {
        const cb = e.target;
        const sid = cb.dataset.student;
        const cid = cb.dataset.criteria;
        const keypad = document.getElementById(`keypad_${sid}_${cid}`);
        const input = document.getElementById(`mark_${sid}_${cid}`);
        const abaiInput = document.getElementById(`abai_${sid}_${cid}`);
        const label = cb.closest('.pm-nilai-wrap').querySelector('.pm-toggle-label');

        if (cb.checked) {
            label.innerHTML = 'Dinilai';
            keypad.style.display = 'block';
            if (abaiInput) abaiInput.value = ''; // no longer an explicit skip
        } else {
            label.innerHTML = 'Abai';
            keypad.style.display = 'none';
            input.value = ''; // Clear mark
            if (abaiInput) abaiInput.value = '1'; // explicit skip — save_scores.php records this distinctly from "untouched"
            document.querySelectorAll(`.pm-key-btn[data-target="mark_${sid}_${cid}"]`).forEach(k => k.classList.remove('active'));
            queueDraftSave(sid, cid, '');
        }
        updateTotals(sid);
    }
});

document.addEventListener('click', function (e) {
    // 2. Keypad Number Click (With Deselect Logic)
    if (e.target.classList.contains('pm-key-btn')) {
        if (e.target.disabled) return;
        const keyBtn = e.target;
        const targetId = keyBtn.dataset.target;
        const val = keyBtn.dataset.val;
        const input = document.getElementById(targetId);

        // Check if the button was already active BEFORE we strip classes
        const wasActive = keyBtn.classList.contains('active');

        // Remove active state from all siblings
        document.querySelectorAll(`.pm-key-btn[data-target="${targetId}"]`).forEach(k => k.classList.remove('active'));

        if (val === '') {
            // Clicked 'X' to clear
            input.value = '';
        } else if (wasActive) {
            // Clicked the already active number -> Deselect it
            input.value = '';
        } else {
            // Clicked a new number
            keyBtn.classList.add('active');
            input.value = val;
        }

        queueDraftSave(keyBtn.dataset.student, keyBtn.dataset.criteria, input.value);
        updateTotals(keyBtn.dataset.student);
        keyBtn.blur();
    }
});

function updateTotals(studentId) {
    let overallSum = 0, overallMax = 0;
    for (const testName in criteriaByTest) {
        let testSum = 0, testMax = 0;
        Object.keys(criteriaByTest[testName]).forEach(cid => {
            const cb = document.querySelector(`.pm-nilai-checkbox[data-student='${studentId}'][data-criteria='${cid}']`);
            if (!cb || cb.checked) {
                testMax += 10;
                const inp = document.getElementById(`mark_${studentId}_${cid}`);
                if (inp && inp.value !== '') {
                    testSum += parseInt(inp.value);
                }
            }
        });
        // update every instance (querySelectorAll), not just the first
        // one found in the DOM, in case a test name is ever rendered in
        // more than one place.
        document.querySelectorAll(`.test-total[data-student='${studentId}'][data-test='${testName}']`).forEach(cell => {
            cell.innerHTML = `${testSum} / ${testMax}`;
        });
        overallSum += testSum; overallMax += testMax;
    }
    document.querySelectorAll(`.overall-total[data-student='${studentId}']`).forEach(oCell => {
        oCell.innerHTML = `${overallSum} / ${overallMax}`;
    });
}

// --- DRAFT PROTECTION SYSTEM ---
function saveDraftMarks() {
    const drafts = {};
    document.querySelectorAll('input[type="hidden"][name^="marks["]').forEach(inp => {
        if (inp.value !== '') drafts[inp.id] = inp.value;
    });

    const toggles = {};
    document.querySelectorAll('.pm-nilai-checkbox').forEach(cb => {
        toggles[cb.dataset.student + '_' + cb.dataset.criteria] = cb.checked;
    });

    sessionStorage.setItem('pm_draft_marks', JSON.stringify(drafts));
    sessionStorage.setItem('pm_draft_toggles', JSON.stringify(toggles));
}

function restoreDraftMarks() {
    const draftsStr = sessionStorage.getItem('pm_draft_marks');
    const togglesStr = sessionStorage.getItem('pm_draft_toggles');

    if (togglesStr) {
        const toggles = JSON.parse(togglesStr);
        for (const key in toggles) {
            const cb = document.querySelector(`.pm-nilai-checkbox[data-student="${key.split('_')[0]}"][data-criteria="${key.split('_')[1]}"]`);
            const keypad = document.getElementById('keypad_' + key);
            const abaiInput = document.getElementById('abai_' + key);
            if (cb && keypad) {
                const label = cb.closest('.pm-nilai-wrap').querySelector('.pm-toggle-label');
                cb.checked = toggles[key];
                if (cb.checked) {
                    label.innerHTML = 'Dinilai'; keypad.style.display = 'block';
                    if (abaiInput) abaiInput.value = '';
                } else {
                    label.innerHTML = 'Abai'; keypad.style.display = 'none';
                    if (abaiInput) abaiInput.value = '1';
                }
            }
        }
        sessionStorage.removeItem('pm_draft_toggles');
    }

    if (draftsStr) {
        const drafts = JSON.parse(draftsStr);
        for (const id in drafts) {
            const inp = document.getElementById(id);
            if (inp) {
                inp.value = drafts[id];
                const btn = document.querySelector(`.pm-key-btn[data-target="${id}"][data-val="${drafts[id]}"]`);
                if (btn) btn.classList.add('active');
            }
        }
        sessionStorage.removeItem('pm_draft_marks');
    }
}

// --- PARAMETER MODAL: DEFERRED ADD LOGIC ---
// Nothing is written to the DB until the judge clicks "Selesai Pemarkahan".
// The modal only queues the (test, criteria) pair in memory and
// injects hidden inputs into markForm so save_scores.php can persist
// them atomically alongside the actual marks.

let pendingParams = []; // [{testId, testName, criteriaId, criteriaName}]

// The Peringkat a judge marks is fixed by their assigned group — this
// modal only ever adds Ujian/Kriteria under currentLevelId, so the
// Ujian list loads immediately instead of waiting on a Peringkat pick.
// Cached list of {id, name} options for whichever Ujian is currently
// selected in the modal — populated once per Ujian pick so each
// criteria row can be filled without a fresh fetch per row.
let currentCriteriaOptions = [];

function openParameterModal() {
    const modal = document.getElementById('modal-parameter');
    modal.style.display = 'flex';
    setTimeout(() => modal.classList.add('show'), 10);

    const testSel = document.getElementById('paramTestSelect');
    const testNew = document.getElementById('paramTestNewInput');

    testSel.innerHTML = '<option value="">Memuatkan...</option>';
    testSel.disabled = true;
    testSel.style.display = '';
    testNew.style.display = 'none';
    testNew.value = '';

    currentCriteriaOptions = [];
    const rowsWrap = document.getElementById('paramCriteriaRows');
    rowsWrap.innerHTML = '';
    document.getElementById('paramAddCriteriaRowBtn').disabled = true;
    addCriteriaRow('-- Pilih Ujian Dahulu --');

    const saveBtn = document.getElementById('paramSaveBtn');
    saveBtn.innerHTML = 'Tambah ke Senarai';
    saveBtn.disabled = false;

    loadTestsForCurrentLevel();
}

// Builds one Kriteria row (select of existing criteria + "new name"
// text input, toggled the same way the Ujian field is) and appends
// it to #paramCriteriaRows. `placeholderLabel` is shown as the
// select's disabled first option before any Ujian is chosen.
function addCriteriaRow(placeholderLabel) {
    const rowsWrap = document.getElementById('paramCriteriaRows');
    const row = document.createElement('div');
    row.className = 'param-criteria-row';
    row.style.cssText = 'display:flex; gap:6px; margin-bottom:6px; align-items:center;';

    const isFirstRow = rowsWrap.children.length === 0;
    const disabled = currentCriteriaOptions.length === 0 && !placeholderLabel;

    row.innerHTML = `
        <select class='paramCriteriaSelect' style='flex:1;' ${(!currentCriteriaOptions.length) ? 'disabled' : ''}>
            <option value="">${placeholderLabel || 'Memuatkan...'}</option>
        </select>
        <input type='text' class='paramCriteriaNewInput pm-modal-new-input' style='flex:1; display:none;' placeholder='Nama kriteria baru'>
        <button type='button' class='pm-modal-close-btn param-criteria-remove-btn' title='Buang baris ini' style='${isFirstRow ? 'visibility:hidden;' : ''}'>&times;</button>
    `;

    const critSel = row.querySelector('.paramCriteriaSelect');
    const critNew = row.querySelector('.paramCriteriaNewInput');
    populateCriteriaSelect(critSel);

    critSel.addEventListener('change', function() {
        if (this.value === '__new__') {
            critNew.style.display = '';
            critNew.focus();
        } else {
            critNew.style.display = 'none';
            critNew.value = '';
        }
    });

    row.querySelector('.param-criteria-remove-btn').addEventListener('click', function() {
        if (rowsWrap.children.length > 1) row.remove();
    });

    rowsWrap.appendChild(row);
    return row;
}

// Fills a single criteria <select> from the cached currentCriteriaOptions
// list (plus the "+ Tambah Kriteria Baru…" sentinel), or leaves it in
// its loading/placeholder state if the list isn't ready yet.
function populateCriteriaSelect(critSel) {
    if (!currentCriteriaOptions.length) return;
    critSel.innerHTML = '<option value="" disabled selected>-- Pilih Kriteria --</option>';
    currentCriteriaOptions.forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = c.name;
        critSel.appendChild(opt);
    });
    const newOpt = document.createElement('option');
    newOpt.value = '__new__';
    newOpt.textContent = '+ Tambah Kriteria Baru…';
    critSel.appendChild(newOpt);
    critSel.disabled = false;
}

// Forces every current + future Kriteria row into "type a new name"
// mode — used when the Ujian itself is new (so it can't have any
// real Kriteria yet).
function forceNewCriteriaMode() {
    currentCriteriaOptions = [];
    document.getElementById('paramAddCriteriaRowBtn').disabled = false;
    document.querySelectorAll('#paramCriteriaRows .param-criteria-row').forEach(row => {
        const critSel = row.querySelector('.paramCriteriaSelect');
        const critNew = row.querySelector('.paramCriteriaNewInput');
        critSel.style.display = 'none';
        critSel.disabled = true;
        critNew.style.display = '';
    });
}

// Loads the Ujian list for the fixed currentLevelId. A judge can type
// a brand new Ujian name instead of only picking an existing one —
// picking "__new__" (or a Peringkat that has zero Ujian yet) reveals
// a text field instead, which also forces Kriteria into new-entry
// mode since a not-yet-created Ujian can't have any real Kriteria.
function loadTestsForCurrentLevel() {
    const testSel = document.getElementById('paramTestSelect');
    testSel.innerHTML = '<option value="">Memuatkan...</option>';
    testSel.disabled = true;
    pmFetch('judge.php?ajax_modal_tests=1&level_id=' + encodeURIComponent(currentLevelId))
        .then(r => r.json())
        .then(data => {
            testSel.innerHTML = '<option value="" disabled selected>-- Pilih Ujian --</option>';
            data.forEach(t => {
                const opt = document.createElement('option');
                opt.value = t.id;
                opt.textContent = t.name;
                testSel.appendChild(opt);
            });
            const newOpt = document.createElement('option');
            newOpt.value = '__new__';
            newOpt.textContent = '+ Tambah Ujian Baru…';
            testSel.appendChild(newOpt);
            testSel.disabled = false;
            if (data.length === 0) {
                testSel.value = '__new__';
                testSel.dispatchEvent(new Event('change'));
            }
        })
        .catch(() => {});
}

// Cascade: Ujian → Kriteria. Resets all Kriteria rows back down to
// one, then re-fills that row (and caches the option list for any
// further rows the judge adds via "+ Tambah Kriteria Lain") once the
// chosen Ujian's existing criteria have loaded.
document.getElementById('paramTestSelect')?.addEventListener('change', function() {
    const testId = this.value;
    const testNew = document.getElementById('paramTestNewInput');
    const rowsWrap = document.getElementById('paramCriteriaRows');
    const addRowBtn = document.getElementById('paramAddCriteriaRowBtn');

    rowsWrap.innerHTML = '';
    currentCriteriaOptions = [];
    addRowBtn.disabled = true;

    if (testId === '__new__') {
        testNew.style.display = '';
        testNew.focus();
        addCriteriaRow();
        forceNewCriteriaMode();
        return;
    }
    testNew.style.display = 'none';

    if (!testId) {
        addCriteriaRow('-- Pilih Ujian Dahulu --');
        return;
    }

    addCriteriaRow();
    pmFetch('judge.php?ajax_modal_criteria=1&test_id=' + encodeURIComponent(testId))
        .then(r => r.json())
        .then(data => {
            currentCriteriaOptions = data;
            document.querySelectorAll('#paramCriteriaRows .paramCriteriaSelect').forEach(populateCriteriaSelect);
            addRowBtn.disabled = false;
            if (data.length === 0) {
                const firstSel = rowsWrap.querySelector('.paramCriteriaSelect');
                firstSel.value = '__new__';
                firstSel.dispatchEvent(new Event('change'));
            }
        })
        .catch(() => {});
});

function closeModal(id) {
    const modal = document.getElementById(id);
    modal.classList.remove('show');
    setTimeout(() => modal.style.display = 'none', 200);
}

// Resolves each field to a name — either the text of the selected
// existing option, or whatever the judge typed into that field's
// "new" input. Peringkat is fixed (currentLevelName), never entered
// here — an Ujian that's new forces Kriteria into new-entry mode too
// (see the cascade listener above), which this mirrors.
function submitNewParameter() {
    const testSel = document.getElementById('paramTestSelect');
    const testNew = document.getElementById('paramTestNewInput');
    const rows = Array.from(document.querySelectorAll('#paramCriteriaRows .param-criteria-row'));

    const levelName = currentLevelName;
    const isNewTest = testSel.value === '__new__';

    let testName;
    if (isNewTest) {
        testName = testNew.value.trim();
        if (!testName) { alert("Sila masukkan nama Ujian baru."); testNew.focus(); return; }
    } else {
        if (!testSel.value) { alert("Sila pilih Ujian terlebih dahulu."); return; }
        testName = testSel.options[testSel.selectedIndex].text;
    }

    // Resolve every Kriteria row to a name first (validating all of
    // them) before queuing anything, so a mistake partway down the
    // list doesn't leave earlier rows already queued.
    const criteriaNames = [];
    for (const row of rows) {
        const critSel = row.querySelector('.paramCriteriaSelect');
        const critNew = row.querySelector('.paramCriteriaNewInput');
        const isNewCriteria = isNewTest || critSel.value === '__new__';

        let criteriaName;
        if (isNewCriteria) {
            criteriaName = critNew.value.trim();
            if (!criteriaName) { alert("Sila masukkan nama Kriteria baru untuk setiap baris."); critNew.focus(); return; }
        } else {
            if (!critSel.value) { alert("Sila pilih Kriteria untuk setiap baris."); return; }
            criteriaName = critSel.options[critSel.selectedIndex].text;
        }
        if (criteriaNames.includes(criteriaName)) {
            alert(`Kriteria "${criteriaName}" ditambah lebih daripada sekali dalam senarai ini.`);
            return;
        }
        criteriaNames.push(criteriaName);
    }

    // Guard: don't add the same combo twice — compared by name since
    // a newly-typed entry has no id yet to compare.
    const dupe = criteriaNames.find(criteriaName => pendingParams.some(
        p => p.levelName === levelName && p.testName === testName && p.criteriaName === criteriaName
    ));
    if (dupe) {
        alert(`Kombinasi "${testName}" → "${dupe}" sudah ditambah.`);
        return;
    }

    const container = document.getElementById('pendingParamsContainer');
    criteriaNames.forEach(criteriaName => {
        // Queue it — nothing touches the DB yet. "key" is what ties
        // this entry's live mark input (pending_marks[key][student_id])
        // back to the real criteria_id that save_scores.php
        // resolves/creates for it on submit (find-by-name, create
        // only if it doesn't already exist — same rule whether the
        // judge picked an existing item or typed a new one).
        const key = 'p' + pendingParams.length;
        const param = { key, sessionId: currentSessionId, levelName, testName, criteriaName };
        pendingParams.push(param);

        // Inject a hidden input into markForm so save_scores.php gets it on submit
        const inp = document.createElement('input');
        inp.type  = 'hidden';
        inp.name  = 'pending_params[]';
        inp.value = JSON.stringify(param);
        container.appendChild(inp);

        // Make it markable right away instead of only after submit+reload
        liveAddCriteriaToUI(param);
    });

    // Show a pending badge so the judge knows what's queued
    refreshPendingBadge();

    closeModal('modal-parameter');
}

function refreshPendingBadge() {
    let badge = document.getElementById('pendingParamsBadge');
    if (pendingParams.length === 0) {
        if (badge) badge.remove();
        return;
    }
    if (!badge) {
        badge = document.createElement('div');
        badge.id = 'pendingParamsBadge';
        badge.style.cssText = 'margin-top:10px; padding:8px 12px; background:rgba(234,179,8,0.12); border-left:3px solid #eab308; border-radius:0 6px 6px 0; font-size:0.78rem; color:#b45309;';
        // Insert above the marking table (inside judge-card, before pm-table-wrap)
        const markCard = document.querySelector('#markForm .judge-card');
        if (markCard) markCard.insertBefore(badge, markCard.firstChild);
    }
    const lines = pendingParams.map(p =>
        `• <b>${p.testName}</b> → ${p.criteriaName}`
    ).join('<br>');
    badge.innerHTML = `⏳ <b>${pendingParams.length} parameter tertangguh</b> — akan disimpan apabila anda klik "Selesai Pemarkahan":<br>${lines}<br><span style="font-size:0.72rem;color:#92400e;">Klik Batal untuk membuang semuanya.</span>`;
}

// ── LIVE UI INJECTION for a newly-queued criteria ────────────────────
// "Tambah Ujian & Kriteria" lets a judge either pick an existing
// Ujian/Kriteria under the fixed Peringkat OR type a brand new one —
// so there usually isn't a real criteria_id (or even test_id) yet at
// the moment this runs. Every criteria added through this modal is
// therefore identified purely by its client-side "key" (param.key,
// e.g. "p0") on this side, and its markctrl's hidden input is named
// pending_marks[key][student_id] instead of
// marks[student_id][criteria_id]. save_scores.php's STEP 1.5
// resolves each key to a real criteria_id on submit — creating
// whichever of Ujian/Kriteria don't already exist by that name
// under the (always-existing) Peringkat, reusing whichever do — and
// folds pending_marks into the normal save using that mapping.
// Nothing touches the DB until then.
//
// Scope: this live-updates the desktop per-ujian tabbed table
// (#desktopMarkingTable) so a newly-queued ujian/kriteria is
// markable immediately, without waiting for submit+reload. Since
// the Peringkat is always the one currently being marked, it
// always belongs on this page's table.
function liveAddCriteriaToUI(param) {
    const { key, testName, criteriaName } = param;

    criteriaByTest[testName] = criteriaByTest[testName] || {};
    if (criteriaByTest[testName][key]) {
        // Already live (added earlier this session) — just jump to its tab.
        switchToDesktopUjianTabByName(testName);
        return;
    }
    criteriaByTest[testName][key] = criteriaName;

    liveAddCriteriaToDesktopTable(testName, key, criteriaName);

    getPresentStudents().forEach(s => updateTotals(s.sid));
    switchToDesktopUjianTabByName(testName);
}

// Adds a new kriteria column to an existing ujian's table, or a
// whole new tab + table for a brand new ujian.
function liveAddCriteriaToDesktopTable(testName, key, criteriaName) {
    const tabsContainer = document.getElementById('desktopUjianTabsContainer');
    const desktopUI = document.getElementById('desktopMarkingTable');
    if (!tabsContainer || !desktopUI) return; // Peringkat started with zero criteria — falls back to reload-after-submit.

    let panel = desktopUI.querySelector(`.desktop-ujian-tab-panel[data-test-name="${cssEscape(testName)}"]`);

    if (panel) {
        // Existing ujian — add a new <th> + one <td> markctrl per present student row.
        const table = panel.querySelector('table.marking-table');
        if (!table) return;
        const headRow = table.querySelector('thead tr');
        const jumlahTh = headRow.querySelector(`th[data-test="${cssEscape(testName)}"]`);
        const newTh = document.createElement('th');
        newTh.textContent = criteriaName;
        headRow.insertBefore(newTh, jumlahTh);

        table.querySelectorAll('tbody tr[data-student]').forEach(tr => {
            const sid = tr.dataset.student;
            const testTotalTd = tr.querySelector(`.test-total[data-test="${cssEscape(testName)}"]`);
            const newTd = document.createElement('td');
            newTd.className = 'mark-cell';
            newTd.id = `desktopSlot_${sid}_${key}`;
            newTd.style.textAlign = 'center';
            newTd.innerHTML = buildMarkctrlHtml(sid, key);
            tr.insertBefore(newTd, testTotalTd);
        });
    } else {
        // Brand new ujian — new tab button + a fresh single-ujian table.
        const newTabIndex = tabsContainer.querySelectorAll('.desktop-ujian-tab-btn').length;

        const tabBtn = document.createElement('button');
        tabBtn.type = 'button';
        tabBtn.className = 'desktop-ujian-tab-btn';
        tabBtn.dataset.tabIndex = newTabIndex;
        tabBtn.dataset.testName = testName;
        tabBtn.textContent = testName;
        tabsContainer.appendChild(tabBtn);

        panel = document.createElement('div');
        panel.className = 'desktop-ujian-tab-panel';
        panel.dataset.tabIndex = newTabIndex;
        panel.dataset.testName = testName;
        panel.style.display = 'none';

        const wrap = document.createElement('div');
        wrap.className = 'pm-table-wrap';
        wrap.style.cssText = 'border:none;margin:0;border-radius:0;';
        const table = document.createElement('table');
        table.className = 'pm-table marking-table';
        table.dataset.testName = testName;

        const thead = document.createElement('thead');
        const headRow = document.createElement('tr');
        headRow.innerHTML = `<th class='sticky-col' style='background: var(--c-surface-2); z-index: 15;'>Pelajar</th>
            <th>${escapeHtml(criteriaName)}</th>
            <th data-test='${escapeHtml(testName)}' style='background: var(--c-surface-3); color: var(--c-text); text-align: center;'>Jumlah ${escapeHtml(testName)}</th>
            <th style='background: var(--c-surface-3); color: var(--c-text); text-align: center;'>Jumlah Keseluruhan</th>`;
        thead.appendChild(headRow);
        table.appendChild(thead);

        const tbody = document.createElement('tbody');
        getPresentStudents().forEach(s => {
            const tr = document.createElement('tr');
            tr.dataset.student = s.sid;
            tr.innerHTML = `<td class='sticky-col'>${escapeHtml(s.name)}</td>
                <td class='mark-cell' id='desktopSlot_${s.sid}_${key}' style='text-align:center;'>${buildMarkctrlHtml(s.sid, key)}</td>
                <td class='test-total' data-student='${s.sid}' data-test='${escapeHtml(testName)}' style='background:var(--c-surface-2);color:var(--c-text);font-weight:700;text-align:center;'>0 / 0</td>
                <td class='overall-total' data-student='${s.sid}' style='background:var(--c-surface-1);color:var(--c-text);font-weight:700;text-align:center;'>0 / 0</td>`;
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        wrap.appendChild(table);
        panel.appendChild(wrap);
        desktopUI.appendChild(panel);

        const alert = desktopUI.querySelector('.pm-alert-warn');
        if (alert) alert.remove();
    }
}

// `cid` here is sometimes a client-side pending key (e.g. "p0")
// rather than a real criteria_id, for modal-added criteria. The
// hidden input's NAME (what actually gets submitted) is
// pending_marks[key][sid], resolved to a real criteria_id
// server-side in save_scores.php; its id/data-criteria stay keyed
// by the same string throughout so every other piece of JS
// (updateTotals, validation) can address it exactly like a normal
// criteria without special-casing.
function buildMarkctrlHtml(sid, cid) {
    const keys = [1, 2, 3, 4, 5, 6, 7, 8, 9, 'X', 0, 10];
    const keyBtns = keys.map(k => {
        const val = k === 'X' ? '' : k;
        const displayK = k === 'X' ? '✖' : k;
        const btnClass = k === 'X' ? 'pm-key-btn clear-btn' : 'pm-key-btn';
        return `<button type='button' class='${btnClass}' data-target='mark_${sid}_${cid}' data-val='${val}' data-student='${sid}' data-criteria='${cid}'>${displayK}</button>`;
    }).join('');
    return `<div class='markctrl' id='markctrl_${sid}_${cid}' data-student='${sid}' data-criteria='${cid}'>
        <label class='pm-nilai-wrap'>
            <input type='checkbox' class='pm-nilai-checkbox' data-student='${sid}' data-criteria='${cid}' checked>
            <div class='pm-toggle-switch'></div>
            <span class='pm-toggle-label'>Dinilai</span>
        </label>
        <div class='keypad-wrapper' id='keypad_${sid}_${cid}' style='display:block;'>
            <input type='hidden' id='mark_${sid}_${cid}' name='pending_marks[${cid}][${sid}]' value=''>
            <input type='hidden' id='abai_${sid}_${cid}' name='pending_abai[${cid}][${sid}]' value=''>
            <div class='pm-keypad'>${keyBtns}</div>
        </div>
    </div>`;
}

function switchToDesktopUjianTabByName(testName) {
    const btn = document.querySelector(`.desktop-ujian-tab-btn[data-test-name="${cssEscape(testName)}"]`);
    if (btn) btn.click();
}

// Present students, deduped by student id — read from the desktop
// table (present-student rows carry data-student; absent rows don't).
function getPresentStudents() {
    const seen = new Set();
    const list = [];
    document.querySelectorAll('#desktopMarkingTable tr[data-student]').forEach(tr => {
        const sid = tr.dataset.student;
        if (seen.has(sid)) return;
        seen.add(sid);
        const nameEl = tr.querySelector('.sticky-col');
        list.push({ sid, name: nameEl ? nameEl.textContent : '' });
    });
    return list;
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Escapes for use inside a quoted CSS attribute-selector string, e.g.
// `[data-test-name="${cssEscape(name)}"]` — NOT CSS.escape(), which
// is for bare identifiers (class/id names) and over-escapes spaces
// and other characters that are perfectly fine inside a quoted
// string, which test names routinely contain.
function cssEscape(str) {
    return String(str).replace(/["\\]/g, '\\$&');
}

// ── REQUIRE A MARK FOR EVERY "DINILAI" CRITERIA BEFORE SUBMIT ────────
// A criteria left checked (Dinilai) but with no number actually
// tapped would otherwise silently save as if it were worth 0 — this
// blocks "Selesai Pemarkahan" and shows exactly which ones are
// missing until the judge either enters a mark or explicitly flips
// it to Abai (an intentional exclusion, which is allowed through).
function findMissingMarks() {
    const studentNames = {};
    getPresentStudents().forEach(s => { studentNames[s.sid] = s.name; });

    const missing = [];
    document.querySelectorAll('.markctrl').forEach(ctrl => {
        const sid = ctrl.dataset.student;
        const cid = ctrl.dataset.criteria;
        const cb  = ctrl.querySelector('.pm-nilai-checkbox');
        const inp = ctrl.querySelector('input[type="hidden"]');
        if (!cb || !inp) return;
        if (cb.checked && inp.value === '') {
            let criteriaName = cid, testName = '';
            for (const t in criteriaByTest) {
                if (criteriaByTest[t][cid]) { criteriaName = criteriaByTest[t][cid]; testName = t; break; }
            }
            missing.push({
                sid,
                studentName: studentNames[sid] || ('Pelajar #' + sid),
                testName,
                criteriaName,
            });
        }
    });
    return missing;
}

function showMissingMarksModal(missing) {
    const list = document.getElementById('missingMarksList');
    list.innerHTML = missing.map((m, i) => `
        <button type='button' class='pm-btn pm-btn-ghost' style='justify-content:flex-start; text-align:left; font-size:0.8rem; padding:8px 10px;'
            onclick="jumpToCriteria(${JSON.stringify(m.testName)}, '${m.sid}')">
            <b>${escapeHtml(m.studentName)}</b> — ${escapeHtml(m.testName)} → ${escapeHtml(m.criteriaName)}
        </button>
    `).join('');

    const modal = document.getElementById('modal-missing-marks');
    modal.style.display = 'flex';
    setTimeout(() => modal.classList.add('show'), 10);
}

function jumpToCriteria(testName, sid) {
    closeModal('modal-missing-marks');
    switchToDesktopUjianTabByName(testName);
    const panel = document.querySelector(`.desktop-ujian-tab-panel[data-test-name="${cssEscape(testName)}"]`);
    const row = panel && panel.querySelector(`tr[data-student="${sid}"]`);
    if (!row) return;
    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    const cell = row.querySelector('.sticky-col');
    if (cell) {
        cell.classList.add('flash-highlight');
        setTimeout(() => cell.classList.remove('flash-highlight'), 1100);
    }
}

document.getElementById('markForm')?.addEventListener('submit', function (e) {
    const missing = findMissingMarks();
    if (missing.length > 0) {
        e.preventDefault();
        showMissingMarksModal(missing);
    }
});

// Initialize Page
document.addEventListener('DOMContentLoaded', function () {
    const pos = localStorage.getItem('judge_scroll_pos');
    if (pos) { window.scrollTo(0, parseInt(pos)); localStorage.removeItem('judge_scroll_pos'); }
    document.querySelectorAll('form').forEach(f => {
        f.addEventListener('submit', () => localStorage.setItem('judge_scroll_pos', window.scrollY));
    });

    restoreDraftMarks(); // Restore marks if a criteria was just added!

    const students = [...new Set(Array.from(document.querySelectorAll('.pm-key-btn')).map(b => b.dataset.student))];
    students.forEach(id => updateTotals(id));

    // Guarantee all accordions start collapsed
    document.querySelectorAll('.pm-accordion-content').forEach(c => c.style.display = 'none');
    document.querySelectorAll('.pm-accordion-arrow').forEach(a => a.style.transform = 'rotate(0deg)');
    document.querySelectorAll('.pm-accordion-header').forEach(h => h.classList.remove('active'));
});
