// pic_groups.js — the PIC "Kumpulan Juri" (judge group) management page
// (pic_groups.php): searchable-dropdown filtering, group create/edit,
// student assignment, and pagination.
//
// Split out of that file's inline <script> block. The one PHP value it
// needed (the CSRF token, PM_GROUPS_CSRF below) is no longer interpolated
// directly here — a small inline bootstrap snippet right before this
// file's <script src> tag declares `const PM_GROUPS_CSRF = ...` instead.
// That works because top-level let/const bindings share one lexical scope
// across sequential classic <script> tags in the same page (same pattern
// already used for judge_dashboard.js/judge_marking.js's criteriaByTest).


// Debounced so typing a name doesn't fire a request per keystroke — waits
// for a short pause, same UX as the other dd-wrap filters which only filter
// once an option is actually picked.
let searchDebounceTimer = null;
function onSearchInput() {
    clearTimeout(searchDebounceTimer);
    searchDebounceTimer = setTimeout(ajaxFilter, 350);
}

function ajaxFilter() {
    const siriEl = document.getElementById('f_siri');
    const params = new URLSearchParams({
        ajax:'1',
        group_id:   document.getElementById('f_group').value,
        session_id: document.getElementById('f_session').value,
        level_id:   document.getElementById('f_level').value,
        school_id:  document.getElementById('f_school').value,
        siri_id:    siriEl ? siriEl.value : '',
        search:     document.getElementById('f_search').value,
    });
    const el = document.getElementById('groupList');
    el.style.opacity = '0.4';
    pmFetch('pic_groups.php?' + params)
        .then(r => r.text())
        .then(html => {
            el.innerHTML = html;
            el.style.opacity = '1';
            initKanbanBoards();
            groupsCurrentPage = 1;
            updateGroupsPagination();
            restoreGroupsState();
        })
        .catch(() => { el.style.opacity = '1'; });
}

// ── Keep whichever cawangan/peringkat sections were open, and the exact
// scroll position, across a save/add/delete redirect — otherwise the page
// reload snaps everything shut and jumps back to the top. ──
const PM_GROUPS_STATE_KEY = 'pm_groups_open_state';

// Toast function is now shared (spawnPmToast, defined once in layout.php)
// since pic_criteria.php/pic_tests.php/pic_levels.php all need the exact
// same behavior — see layout.php for the implementation.

function rememberGroupsState() {
    const openIds = [];
    document.querySelectorAll('[id^="school_grp_"], [id^="grp_lvl_"]').forEach(el => {
        if (el.style.display === 'block') openIds.push(el.id);
    });
    sessionStorage.setItem(PM_GROUPS_STATE_KEY, JSON.stringify({ openIds, scrollY: window.scrollY }));
}

function restoreGroupsState() {
    let state = null;
    try { state = JSON.parse(sessionStorage.getItem(PM_GROUPS_STATE_KEY) || 'null'); } catch (e) {}
    sessionStorage.removeItem(PM_GROUPS_STATE_KEY);
    if (!state) return;
    // Keep only the last school_grp_ and last grp_lvl_ id — toggleBlock
    // never lets more than one of each be open at a time going forward,
    // but this stays defensive against any older multi-id state left over
    // in sessionStorage from before that was true.
    const openIds = state.openIds || [];
    const lastSchool = [...openIds].reverse().find(id => id.startsWith('school_grp_'));
    const lastLevel  = [...openIds].reverse().find(id => id.startsWith('grp_lvl_'));
    [lastSchool, lastLevel].filter(Boolean).forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.style.display = 'block';
        const arrow = el.previousElementSibling?.querySelector('.arrow');
        if (arrow) arrow.style.transform = 'rotate(90deg)';
    });
    if (typeof state.scrollY === 'number') {
        requestAnimationFrame(() => requestAnimationFrame(() => window.scrollTo(0, state.scrollY)));
    }
}

// ── PAGINATION (client-side, 20 session-accordions per page) ──
let groupsCurrentPage = 1;
const groupsPerPage = 20;

function updateGroupsPagination() {
    const cards = Array.from(document.querySelectorAll('#groupList > .accordion-card'));
    const container = document.getElementById('groupsPaginationContainer');
    const info = document.getElementById('groupsPageInfo');
    const btns = document.getElementById('groupsPaginationButtons');

    if (cards.length === 0) { container.style.display = 'none'; return; }

    const total = cards.length;
    const totalPages = Math.max(1, Math.ceil(total / groupsPerPage));
    if (groupsCurrentPage > totalPages) groupsCurrentPage = totalPages;
    if (groupsCurrentPage < 1) groupsCurrentPage = 1;

    container.style.display = totalPages <= 1 ? 'none' : 'flex';

    const start = (groupsCurrentPage - 1) * groupsPerPage;
    const end   = start + groupsPerPage;
    cards.forEach((c, i) => { c.style.display = (i >= start && i < end) ? '' : 'none'; });

    const s = start + 1;
    const e = Math.min(end, total);
    info.innerHTML = `Memaparkan <b>${s}–${e}</b> daripada <b>${total}</b> cawangan`;

    pmRenderPagination(btns, groupsCurrentPage, totalPages, groupsGoToPage);
}

function groupsGoToPage(page) {
    groupsCurrentPage = page;
    updateGroupsPagination();
}

// ── Searchable dropdown logic (matches pic_students.php's dd-wrap) ──
function ddToggle(name) {
    const trigger = document.getElementById('ddTrigger_' + name);
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
    ajaxFilter();
}

document.addEventListener('click', e => {
    if (!e.target.closest('.dd-wrap')) {
        document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
        document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
    }
});

// Selecting a Siri narrows the Sidang dropdown to that siri's sessions only,
// since session names can repeat across siri and would otherwise be ambiguous.
function ddSelectSiri(value, label) {
    document.getElementById('f_siri').value = value;
    const lbl = document.getElementById('ddLabel_siri');
    lbl.textContent = label;
    lbl.style.color = value === '' ? 'var(--c-text-faint)' : '';
    document.querySelectorAll('#ddOpts_siri .dd-opt').forEach(o => o.classList.toggle('selected', o.dataset.value === value));
    document.getElementById('ddPanel_siri').classList.remove('open');
    document.getElementById('ddTrigger_siri').classList.remove('open');

    const sessionOpts = document.querySelectorAll('#ddOpts_session .dd-opt');
    let currentStillValid = true;
    const currentSessionValue = document.getElementById('f_session').value;
    sessionOpts.forEach(opt => {
        if (!opt.dataset.value) { opt.classList.remove('hidden'); return; }
        const matches = !value || opt.dataset.siri === value;
        opt.classList.toggle('hidden', !matches);
        if (opt.dataset.value === currentSessionValue && !matches) currentStillValid = false;
    });
    if (!currentStillValid) {
        document.getElementById('f_session').value = '';
        document.getElementById('ddLabel_session').textContent = 'Semua Sidang';
        document.getElementById('ddLabel_session').style.color = 'var(--c-text-faint)';
    }
    ajaxFilter();
}

// Selecting a Peringkat narrows the Kumpulan dropdown to that level's groups
// only, same pattern as ddSelectSiri narrowing Sidang above.
function ddSelectLevel(value, label) {
    document.getElementById('f_level').value = value;
    const lbl = document.getElementById('ddLabel_level');
    lbl.textContent = label;
    lbl.style.color = value === '' ? 'var(--c-text-faint)' : '';
    document.querySelectorAll('#ddOpts_level .dd-opt').forEach(o => o.classList.toggle('selected', o.dataset.value === value));
    document.getElementById('ddPanel_level').classList.remove('open');
    document.getElementById('ddTrigger_level').classList.remove('open');

    const groupOpts = document.querySelectorAll('#ddOpts_group .dd-opt');
    let currentStillValid = true;
    const currentGroupValue = document.getElementById('f_group').value;
    groupOpts.forEach(opt => {
        if (!opt.dataset.value) { opt.classList.remove('hidden'); return; }
        const matches = !value || opt.dataset.level === value;
        opt.classList.toggle('hidden', !matches);
        if (opt.dataset.value === currentGroupValue && !matches) currentStillValid = false;
    });
    if (!currentStillValid) {
        document.getElementById('f_group').value = '';
        document.getElementById('ddLabel_group').textContent = 'Semua Kumpulan';
        document.getElementById('ddLabel_group').style.color = 'var(--c-text-faint)';
    }
    ajaxFilter();
}

// ── Kanban board: every card drag, inline rename, judge change, column
// add/delete is its own immediately-persisted action — POSTed the instant
// it happens, no separate save step anywhere on this board. ──
function pmPost(action, params) {
    const body = new URLSearchParams({ action, csrf_token: PM_GROUPS_CSRF, ...params });
    return pmFetch('pic_groups.php', { method: 'POST', body })
        .then(r => r.json())
        .catch(() => ({ ok: false, msg: 'Ralat rangkaian. Sila cuba lagi.' }));
}

function initKanbanBoards() {
    document.querySelectorAll('.kanban-board').forEach(board => {
        if (board.dataset.klisten) return;
        board.dataset.klisten = '1';
        attachKanbanDrag(board);
        attachKanbanEdit(board);
        attachKanbanDelete(board);
    });
}

// ── Drag a student card between (or within) columns. The dropped-on
// column's full resulting card order is sent along with the move so the
// server can rewrite sort_order for that whole column in one pass — the
// same "loop order becomes sort_order" approach the page always used,
// just scoped to one column per drag instead of a whole card. ──
function attachKanbanDrag(board) {
    let draggedCard = null;
    let sourceBody = null;

    board.addEventListener('dragstart', e => {
        const card = e.target.closest('.kanban-card');
        if (!card) return;
        draggedCard = card;
        sourceBody = card.closest('.kanban-col-body');
        card.classList.add('kanban-dragging');
        e.dataTransfer.effectAllowed = 'move';
        // Firefox refuses to start a drag at all without data actually set
        // on the transfer — the value itself is unused, only the presence
        // of a payload matters (the move logic reads draggedCard/sourceBody
        // via closure, not dataTransfer).
        e.dataTransfer.setData('text/plain', card.dataset.sid || '');
    });

    board.addEventListener('dragend', () => {
        if (draggedCard) draggedCard.classList.remove('kanban-dragging');
        board.querySelectorAll('.kanban-drag-over').forEach(b => b.classList.remove('kanban-drag-over'));
        draggedCard = null;
        sourceBody = null;
    });

    board.addEventListener('dragover', e => {
        const addTile = e.target.closest('.kanban-col-add');
        if (addTile && draggedCard) {
            e.preventDefault();
            board.querySelectorAll('.kanban-drag-over').forEach(b => { if (b !== addTile) b.classList.remove('kanban-drag-over'); });
            addTile.classList.add('kanban-drag-over');
            return;
        }

        const body = e.target.closest('.kanban-col-body');
        if (!body || !draggedCard) return;
        e.preventDefault();
        board.querySelectorAll('.kanban-drag-over').forEach(b => { if (b !== body) b.classList.remove('kanban-drag-over'); });
        body.classList.add('kanban-drag-over');

        const after = [...body.querySelectorAll('.kanban-card:not(.kanban-dragging)')].find(c => {
            const r = c.getBoundingClientRect();
            return e.clientY < r.top + r.height / 2;
        });
        if (after) body.insertBefore(draggedCard, after);
        else body.appendChild(draggedCard);
    });

    board.addEventListener('drop', e => {
        // Dropping a student straight onto "+ Kumpulan Baharu" opens that
        // same add-group form with this student pending, instead of moving
        // any card — the actual assignment only happens once the group is
        // created (confirmAddColumn includes the pending student id).
        const addTile = e.target.closest('.kanban-col-add');
        if (addTile && draggedCard) {
            e.preventDefault();
            addTile.classList.remove('kanban-drag-over');
            const uid = board.id.replace('board_', '');
            showAddColumnForm(uid, draggedCard.dataset.sid, draggedCard.textContent.trim());
            return;
        }

        const body = e.target.closest('.kanban-col-body');
        if (!body || !draggedCard) return;
        e.preventDefault();
        body.classList.remove('kanban-drag-over');

        const sid = draggedCard.dataset.sid;
        const targetGroupId = body.dataset.groupId;
        const order = [...body.querySelectorAll('.kanban-card')].map(c => c.dataset.sid);
        const emptyHint = body.querySelector('.kanban-empty-hint');
        if (emptyHint) emptyHint.remove();
        if (sourceBody && sourceBody !== body && !sourceBody.querySelector('.kanban-card')) {
            const hint = document.createElement('div');
            hint.className = 'kanban-empty-hint';
            hint.textContent = 'Seret pelajar ke sini';
            sourceBody.appendChild(hint);
        }
        updateKanbanCounts(board);

        // Captured into a local const — draggedCard/sourceBody are shared
        // variables that dragend resets to null right after this handler
        // returns (and a later drag would overwrite them again before this
        // fetch resolves). Without this, the async .then()/.catch() below
        // would run against whatever draggedCard happens to be by the time
        // the server responds — null, or a completely different card —
        // instead of the one actually dragged here, which is exactly what
        // left the "saving" cursor stuck on the card forever.
        const cardRef = draggedCard;
        cardRef.classList.add('kanban-saving');
        const moveBody = new URLSearchParams({ action: 'move_student', csrf_token: PM_GROUPS_CSRF, student_id: sid, group_id: targetGroupId });
        order.forEach(id => moveBody.append('order[]', id));
        pmFetch('pic_groups.php', { method: 'POST', body: moveBody })
            .then(r => r.json())
            .then(res => {
                cardRef.classList.remove('kanban-saving');
                if (res.ok) {
                    // Quiet per-card confirmation instead of a toast on every
                    // single drag (which would get noisy fast) — a brief
                    // flash on the card that just landed is enough to show
                    // the move actually saved.
                    cardRef.classList.add('kanban-saved');
                    setTimeout(() => cardRef.classList.remove('kanban-saved'), 900);
                } else {
                    cardRef.classList.add('kanban-error');
                    setTimeout(() => cardRef.classList.remove('kanban-error'), 1500);
                    spawnPmToast(res.msg || 'Ralat memindahkan pelajar.', true);
                    rememberGroupsState();
                    ajaxFilter();
                }
            })
            .catch(() => {
                cardRef.classList.remove('kanban-saving');
                spawnPmToast('Ralat rangkaian. Sila cuba lagi.', true);
                rememberGroupsState();
                ajaxFilter();
            });
    });
}

function updateKanbanCounts(board) {
    board.querySelectorAll('.kanban-col').forEach(col => {
        const countEl = col.querySelector('.kanban-col-count');
        if (!countEl) return;
        const body = col.querySelector('.kanban-col-body');
        countEl.textContent = body ? body.querySelectorAll('.kanban-card').length : 0;
    });
}

// ── Inline rename / re-judge — saved on blur (or Enter/change), not on
// every keystroke, and only if the value actually changed. ──
function attachKanbanEdit(board) {
    board.addEventListener('focusout', e => {
        if (e.target.classList.contains('kanban-group-name')) saveKanbanGroup(e.target);
    });
    board.addEventListener('keydown', e => {
        if (e.target.classList.contains('kanban-group-name') && e.key === 'Enter') e.target.blur();
    });
    board.addEventListener('change', e => {
        if (e.target.classList.contains('kanban-judge-select')) saveKanbanGroup(e.target);
    });
}

function saveKanbanGroup(el) {
    const gid = el.dataset.groupId;
    const col = el.closest('.kanban-col');
    const nameInput = col.querySelector('.kanban-group-name');
    const judgeSelect = col.querySelector('.kanban-judge-select');
    const name = nameInput.value.trim();
    if (!name) { nameInput.value = nameInput.dataset.orig; return; }
    if (name === nameInput.dataset.orig && judgeSelect.value === (judgeSelect.dataset.orig || '')) return;

    const judgeChanged = judgeSelect.value !== (judgeSelect.dataset.orig || '');
    const judgeLabel = judgeSelect.options[judgeSelect.selectedIndex]?.text || '';

    pmPost('update_group', { group_id: gid, group_name: name, judge_id: judgeSelect.value }).then(res => {
        if (res.ok) {
            nameInput.dataset.orig = name;
            judgeSelect.dataset.orig = judgeSelect.value;
            spawnPmToast(judgeChanged ? ('Juri ditetapkan: ' + judgeLabel + '.') : ('Kumpulan dinamakan semula: "' + name + '".'), false);
        } else {
            nameInput.value = nameInput.dataset.orig;
            judgeSelect.value = judgeSelect.dataset.orig || '';
            spawnPmToast(res.msg || 'Ralat mengemaskini kumpulan.', true);
        }
    });
}

// ── Delete a column. Members become unassigned server-side, so the board
// is simplest to just refresh in place (keeping the open sections/scroll
// position via remember/restoreGroupsState) rather than hand-patch the DOM. ──
function attachKanbanDelete(board) {
    board.addEventListener('click', e => {
        const btn = e.target.closest('.kanban-col-delete');
        if (!btn) return;
        const gid = btn.dataset.groupId;
        const name = btn.dataset.groupName || '';
        if (!confirm('Padam kumpulan "' + name + '"? Pelajar di dalamnya akan menjadi Belum Diagihkan.')) return;
        pmPost('delete', { group_id: gid }).then(res => {
            spawnPmToast(res.msg || (res.ok ? 'Kumpulan dipadam.' : 'Ralat memadam kumpulan.'), !res.ok);
            rememberGroupsState();
            ajaxFilter();
        });
    });
}

// ── Add-column tile: name + optional judge, created (and saved) right on
// the board it belongs to — no detour to a separate form/page. ──
// pendingStudentId/pendingStudentName are only passed when this form was
// opened by dropping a card onto the "+" tile (see attachKanbanDrag) —
// clicking the "+ Kumpulan Baharu" button itself calls this with no args.
function showAddColumnForm(uid, pendingStudentId, pendingStudentName) {
    const form = document.getElementById('addColForm_' + uid);
    if (!form) return;
    form.style.display = 'flex';
    form.dataset.pendingStudentId = pendingStudentId || '';
    const hint = document.getElementById('addColPendingHint_' + uid);
    if (hint) hint.textContent = pendingStudentName ? ('+ ' + pendingStudentName) : '';
    const nameInput = document.getElementById('addColName_' + uid);
    if (nameInput) { nameInput.value = ''; setTimeout(() => nameInput.focus(), 50); }
}

function cancelAddColumn(uid) {
    const form = document.getElementById('addColForm_' + uid);
    if (form) { form.style.display = 'none'; delete form.dataset.pendingStudentId; }
    const hint = document.getElementById('addColPendingHint_' + uid);
    if (hint) hint.textContent = '';
}

function confirmAddColumn(uid) {
    const board = document.getElementById('board_' + uid);
    const form = document.getElementById('addColForm_' + uid);
    const nameInput = document.getElementById('addColName_' + uid);
    const judgeSelect = document.getElementById('addColJudge_' + uid);
    if (!board || !nameInput) return;
    const name = nameInput.value.trim();
    if (!name) { nameInput.focus(); return; }
    const pendingStudentId = form ? form.dataset.pendingStudentId : '';

    // Built as a raw request (not pmPost) since student_ids needs a real
    // repeated student_ids[] param, which a plain object can't express.
    const body = new URLSearchParams({
        action: 'create_group',
        csrf_token: PM_GROUPS_CSRF,
        level_id: board.dataset.levelId,
        school_id: board.dataset.schoolId,
        group_name: name,
        judge_id: judgeSelect ? judgeSelect.value : '',
    });
    if (pendingStudentId) body.append('student_ids[]', pendingStudentId);

    pmFetch('pic_groups.php', { method: 'POST', body })
        .then(r => r.json())
        .then(res => {
            if (res.ok) {
                cancelAddColumn(uid);
                spawnPmToast('Kumpulan "' + name + '" berjaya dicipta.', false);
                rememberGroupsState();
                ajaxFilter();
            } else {
                spawnPmToast(res.msg || 'Ralat menambah kumpulan.', true);
            }
        })
        .catch(() => spawnPmToast('Ralat rangkaian. Sila cuba lagi.', true));
}

function closeBlock(el) {
    el.style.display = 'none';
    const arrow = el.previousElementSibling?.querySelector('.arrow');
    if (arrow) arrow.style.transform = 'rotate(0deg)';
}

function toggleBlock(id) {
    const el = document.getElementById(id);
    if (!el) return;
    const isOpen = el.style.display === 'block';

    // True accordion: opening a cawangan (or a peringkat within one)
    // collapses whichever other one at the same level was already open,
    // instead of letting them all stack up into one long pile of
    // simultaneously-open panels.
    if (!isOpen) {
        if (id.startsWith('school_grp_')) {
            document.querySelectorAll('[id^="school_grp_"]').forEach(other => {
                if (other.id !== id && other.style.display === 'block') closeBlock(other);
            });
        } else if (id.startsWith('grp_lvl_')) {
            const scope = el.closest('[id^="school_grp_"]') || document;
            scope.querySelectorAll('[id^="grp_lvl_"]').forEach(other => {
                if (other.id !== id && other.style.display === 'block') closeBlock(other);
            });
        }
    }

    el.style.display = isOpen ? 'none' : 'block';
    // The header (.school-header or .accordion-sub-header) is always the
    // panel's immediately preceding sibling — rotate its ▶ arrow to point
    // down while open, back to the right when closed.
    const arrow = el.previousElementSibling?.querySelector('.arrow');
    if (arrow) arrow.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(90deg)';
}

document.addEventListener('DOMContentLoaded', ajaxFilter);
