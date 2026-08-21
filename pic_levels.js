let _t = null;
let _dirtyRows = new Set();
let _orderDirty = false;

function ajaxFilter() {
    clearTimeout(_t);
    _t = setTimeout(_load, 400);
}

function _load() {
    const params = new URLSearchParams({
        ajax: '1',
        search: document.getElementById('f_search').value,
        session: document.getElementById('f_session').value
    });

    const wrapper = document.getElementById('levelList');
    const spinner = document.getElementById('ajaxSpinner');

    wrapper.style.display = 'none';
    spinner.style.display = 'block';

    pmFetch('pic_levels.php?' + params)
        .then(r => r.text())
        .then(html => {
            wrapper.innerHTML = html;
            wrapper.style.display = 'block';
            spinner.style.display = 'none';
            _dirtyRows.clear();
            _orderDirty = false;
            updateDirtyBar();
            attachListeners();
            levelsCurrentPage = 1;
            updateLevelsPagination();
            fitLevelsListHeight();
            restoreOpenAccordions();
        })
        .catch(err => {
            console.error(err);
            wrapper.style.display = 'block';
            spinner.style.display = 'none';
        });
}

// ── PAGINATION (client-side, 20 session-accordions per page) ──
let levelsCurrentPage = 1;
const levelsPerPage = 20;

function updateLevelsPagination() {
    const cards = Array.from(document.querySelectorAll('#levelList > .accordion-card'));
    const container = document.getElementById('levelsPaginationContainer');
    const info = document.getElementById('levelsPageInfo');
    const btns = document.getElementById('levelsPaginationButtons');

    if (cards.length === 0) { container.style.display = 'none'; return; }

    const total = cards.length;
    const totalPages = Math.max(1, Math.ceil(total / levelsPerPage));
    if (levelsCurrentPage > totalPages) levelsCurrentPage = totalPages;
    if (levelsCurrentPage < 1) levelsCurrentPage = 1;

    container.style.display = totalPages <= 1 ? 'none' : 'flex';

    const start = (levelsCurrentPage - 1) * levelsPerPage;
    const end   = start + levelsPerPage;
    cards.forEach((c, i) => { c.style.display = (i >= start && i < end) ? '' : 'none'; });

    const s = start + 1;
    const e = Math.min(end, total);
    info.innerHTML = `Memaparkan <b>${s}–${e}</b> daripada <b>${total}</b> sidang`;

    pmRenderPagination(btns, levelsCurrentPage, totalPages, levelsGoToPage);
}

function levelsGoToPage(page) {
    levelsCurrentPage = page;
    updateLevelsPagination();
}

// ── Fit the levels list + pagination into the viewport, no page scroll ──
function fitLevelsListHeight() {
    const scrollEl = document.getElementById('levelList');
    const pagination = document.getElementById('levelsPaginationContainer');
    if (!scrollEl || !pagination) return;
    if (window.innerWidth <= 640) {
        scrollEl.style.maxHeight = '';
        return;
    }
    const top = scrollEl.getBoundingClientRect().top;
    const paginationH = pagination.offsetHeight;
    const available = window.innerHeight - top - paginationH - 24; // 24px bottom breathing room
    scrollEl.style.maxHeight = Math.max(150, available) + 'px';
}
window.addEventListener('resize', fitLevelsListHeight);

function attachListeners() {
    document.querySelectorAll('.level-input').forEach(el => {
        if (el.dataset.listening) return;
        el.dataset.listening = '1';
        el.addEventListener('input', onInputChange);
    });
    attachDragListeners();
}

// ── Drag-and-drop reordering (per session tbody) ──
let _dragRow = null;

function attachDragListeners() {
    document.querySelectorAll('.tests-table tbody tr').forEach(row => {
        if (row.dataset.dragListening) return;
        row.dataset.dragListening = '1';
        row.addEventListener('dragstart', e => {
            _dragRow = row;
            row.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });
        row.addEventListener('dragend', () => {
            row.classList.remove('dragging');
            _dragRow = null;
        });
        row.addEventListener('dragover', e => {
            e.preventDefault();
            const tbody = row.parentElement;
            if (!_dragRow || _dragRow.parentElement !== tbody || _dragRow === row) return;
            const rect = row.getBoundingClientRect();
            const before = (e.clientY - rect.top) < rect.height / 2;
            tbody.insertBefore(_dragRow, before ? row : row.nextSibling);
        });
        row.addEventListener('drop', e => {
            e.preventDefault();
            renumberTable(row.closest('table'));
            markOrderDirty(row.closest('.accordion-card'));
        });
    });
}

function renumberTable(table) {
    table.querySelectorAll('tbody tr').forEach((row, i) => {
        const cell = row.querySelector('.col-no');
        if (cell) cell.textContent = i + 1;
    });
}

function markOrderDirty(accCard) {
    _orderDirty = true;
    if (accCard) accCard.classList.add('has-changes');
    updateDirtyBar();
}

function onInputChange(e) {
    const row = e.target.closest('tr');
    if (!row) return;
    const id = row.dataset.id;
    const orig = e.target.dataset.orig ?? '';
    const accCard = e.target.closest('.accordion-card');

    if (e.target.value !== orig) {
        _dirtyRows.add(id);
        row.classList.add('row-dirty');
        if(accCard) accCard.classList.add('has-changes');
    } else {
        _dirtyRows.delete(id);
        row.classList.remove('row-dirty');
        if(accCard && accCard.querySelectorAll('.row-dirty').length === 0) {
            accCard.classList.remove('has-changes');
        }
    }
    updateDirtyBar();
}

function updateDirtyBar() {
    const bar = document.getElementById('saveDirtyBar');
    const msg = document.getElementById('saveMsg');
    const total = _dirtyRows.size + (_orderDirty ? 1 : 0);
    bar.classList.toggle('dirty', total > 0);
    if (total === 0) {
        msg.innerHTML = 'Tiada perubahan';
    } else if (_dirtyRows.size > 0 && _orderDirty) {
        msg.innerHTML = `Ada <strong>${_dirtyRows.size}</strong> perubahan &amp; <strong>susunan</strong> belum disimpan`;
    } else if (_orderDirty) {
        msg.innerHTML = `Ada <strong>susunan</strong> belum disimpan`;
    } else {
        msg.innerHTML = `Ada <strong>${_dirtyRows.size}</strong> perubahan belum disimpan`;
    }
}

// ── Keep the accordion(s) the PIC had open across the save-triggered page
// reload (action=save_all redirects the whole page) instead of snapping
// everything back to collapsed. ──
const PM_OPEN_KEY = 'pm_levels_open_accordions';

function rememberOpenAccordions() {
    const openIds = Array.from(document.querySelectorAll('.acc-body'))
        .filter(el => el.style.display === 'block')
        .map(el => el.id);
    sessionStorage.setItem(PM_OPEN_KEY, JSON.stringify(openIds));
}

function restoreOpenAccordions() {
    let openIds = [];
    try { openIds = JSON.parse(sessionStorage.getItem(PM_OPEN_KEY) || '[]'); } catch (e) {}
    sessionStorage.removeItem(PM_OPEN_KEY);
    openIds.forEach(id => {
        const bodyEl = document.getElementById(id);
        if (!bodyEl) return;
        bodyEl.style.display = 'block';
        const iconEl = bodyEl.previousElementSibling?.querySelector('.acc-icon');
        if (iconEl) iconEl.style.transform = 'rotate(90deg)';
    });
}

function submitSaveAll() {
    rememberOpenAccordions();
    const form = document.getElementById('saveAllForm');
    form.querySelectorAll('.dyn-input').forEach(el => el.remove());
    document.querySelectorAll('.level-input').forEach(el => {
        const h = document.createElement('input');
        h.type = 'hidden';
        h.name = el.name;
        h.value = el.value;
        h.className = 'dyn-input';
        form.appendChild(h);
    });
    if (_orderDirty) {
        document.querySelectorAll('.tests-table').forEach(table => {
            const sid = table.dataset.session;
            table.querySelectorAll('tbody tr').forEach((row, i) => {
                const h = document.createElement('input');
                h.type = 'hidden';
                h.name = `order[${sid}][${i}]`;
                h.value = row.dataset.id;
                h.className = 'dyn-input';
                form.appendChild(h);
            });
        });
    }
    form.submit();
}

function discardAll() {
    document.querySelectorAll('.level-input').forEach(el => {
        if (el.dataset.orig !== undefined) el.value = el.dataset.orig;
        el.closest('tr')?.classList.remove('row-dirty');
    });
    document.querySelectorAll('.accordion-card').forEach(acc => {
        acc.classList.remove('has-changes');
    });
    _dirtyRows.clear();
    _orderDirty = false;
    updateDirtyBar();
    _load();
}

function delRow(id) {
    if (!confirm('Padam peringkat ini? Amaran: Semua data berkaitan dengan peringkat ini mungkin terpadam.')) return;
    rememberOpenAccordions();
    document.getElementById('delFrm').querySelector('[name=level_id]').value = id;
    document.getElementById('delFrm').submit();
}

function toggleAddCard() {
    const el = document.getElementById('addLevel');
    el.style.display = (el.style.display === 'block') ? 'none' : 'block';
    if(el.style.display === 'block'){
        setTimeout(() => { el.querySelector('input[name="level_name"]').focus(); }, 50);
    }
}

function toggleBlock(id, headerEl) {
    const bodyEl = document.getElementById(id);
    const iconEl = headerEl.querySelector('.acc-icon');
    if (bodyEl) {
        if (bodyEl.style.display === 'none' || bodyEl.style.display === '') {
            bodyEl.style.display = 'block';
            if (iconEl) iconEl.style.transform = 'rotate(90deg)';
            // Scroll the newly-opened body fully into view within the
            // scrollable list wrapper — otherwise its bottom rows stay
            // clipped by the fixed max-height until the user scrolls.
            requestAnimationFrame(() => {
                bodyEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            });
        } else {
            bodyEl.style.display = 'none';
            if (iconEl) iconEl.style.transform = 'rotate(0deg)';
        }
    }
}

function ddToggle(name) {
    const panel = document.getElementById('ddPanel_' + name);
    const trigger = document.getElementById('ddTrigger_' + name);
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
        if(m) any=true; 
    });
    if(empty) empty.style.display = any ? 'none' : 'block';
}

function ddSelect(name, value, label) {
    if (name === 'session') document.getElementById('f_session').value = value;
    else if (name === 'search') document.getElementById('f_search').value = value;

    const lbl = document.getElementById('ddLabel_' + name);
    lbl.textContent = label;
    lbl.style.color = value === '' ? 'var(--c-text-faint)' : '';
    document.querySelectorAll('#ddOpts_' + name + ' .dd-opt').forEach(o => o.classList.toggle('selected', o.dataset.value === value));
    document.getElementById('ddPanel_' + name).classList.remove('open');
    document.getElementById('ddTrigger_' + name).classList.remove('open');
    ajaxFilter();
}

function ddSearchInput(val) {
    const lbl = document.getElementById('ddLabel_search');
    lbl.textContent = val || '-- Semua Peringkat --';
    lbl.style.color = val ? '' : 'var(--c-text-faint)';
    ajaxFilter();
}

document.addEventListener('click', e => {
    if (!e.target.closest('.dd-wrap')) {
        document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
        document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
    }
});

document.addEventListener('DOMContentLoaded', _load);
