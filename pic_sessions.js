let _t = null;
let _dirtyRows = new Set();

// ── Searchable dropdown logic (matches pic_levels.php's dd-wrap) ──
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

function ddSessionSearch(val) {
    const opts = document.querySelectorAll('#ddOpts_session .dd-opt');
    const empty = document.getElementById('ddEmpty_session');
    let any = false;
    opts.forEach(o => {
        const m = o.textContent.toLowerCase().includes(val.toLowerCase());
        o.classList.toggle('hidden', !m);
        if (m) any = true;
    });
    empty.style.display = any ? 'none' : 'block';

    const lbl = document.getElementById('ddLabel_session');
    lbl.textContent = val || '-- Semua Sidang --';
    lbl.style.color = val ? '' : 'var(--c-text-faint)';
    ajaxFilter();
}

function ddSelectSession(value, label) {
    document.getElementById('f_search').value = value;
    const lbl = document.getElementById('ddLabel_session');
    lbl.textContent = label;
    lbl.style.color = value === '' ? 'var(--c-text-faint)' : '';
    document.querySelectorAll('#ddOpts_session .dd-opt').forEach(o => o.classList.toggle('selected', o.dataset.value === value));
    document.getElementById('ddPanel_session').classList.remove('open');
    document.getElementById('ddTrigger_session').classList.remove('open');
    ajaxFilter();
}

document.addEventListener('click', e => {
    if (!e.target.closest('.dd-wrap')) {
        document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
        document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
    }
});

// ── Table Logic ──
function ajaxFilter() {
    clearTimeout(_t);
    _t = setTimeout(_load, 400);
}

function _load() {
    const params = new URLSearchParams({ 
        ajax: '1', 
        search: document.getElementById('f_search').value
    });
    
    const tbody = document.getElementById('sessionTbody');
    const spinner = document.getElementById('ajaxSpinner');
    
    tbody.style.display = 'none';
    spinner.style.display = 'block';

    pmFetch('pic_sessions.php?' + params)
        .then(r => r.text())
        .then(html => {
            tbody.innerHTML = html;
            tbody.style.display = 'block';
            spinner.style.display = 'none';
            _dirtyRows.clear();
            updateDirtyBar();
            attachListeners();
            sessionsCurrentPage = 1;
            updateSessionsPagination();
        })
        .catch(() => {
            tbody.style.display = 'block';
            spinner.style.display = 'none';
        });
}

// ── PAGINATION (client-side, 20 per page — matches pic_view_marks.php) ──
let sessionsCurrentPage = 1;
const sessionsPerPage = 20;

function updateSessionsPagination() {
    const rows = Array.from(document.querySelectorAll('#sessionTbody .session-row'));
    const container = document.getElementById('sessionsPaginationContainer');
    const info = document.getElementById('sessionsPageInfo');
    const btns = document.getElementById('sessionsPaginationButtons');

    if (rows.length === 0) { container.style.display = 'none'; return; }

    const total = rows.length;
    const totalPages = Math.max(1, Math.ceil(total / sessionsPerPage));
    if (sessionsCurrentPage > totalPages) sessionsCurrentPage = totalPages;
    if (sessionsCurrentPage < 1) sessionsCurrentPage = 1;

    if (totalPages <= 1) { container.style.display = 'none'; }
    else { container.style.display = 'flex'; }

    const start = (sessionsCurrentPage - 1) * sessionsPerPage;
    const end   = start + sessionsPerPage;
    rows.forEach((r, i) => { r.style.display = (i >= start && i < end) ? '' : 'none'; });

    const s = start + 1;
    const e = Math.min(end, total);
    info.innerHTML = `Memaparkan <b>${s}–${e}</b> daripada <b>${total}</b> sidang`;

    pmRenderPagination(btns, sessionsCurrentPage, totalPages, sessionsGoToPage);
}

function sessionsGoToPage(page) {
    sessionsCurrentPage = page;
    updateSessionsPagination();
}

function attachListeners() {
    document.querySelectorAll('#sessionTbody input').forEach(el => {
        if (el.dataset.listening) return;
        el.dataset.listening = '1';
        el.addEventListener('input', onInputChange);
    });
}

function onInputChange(e) {
    const row = e.target.closest('.session-row');
    if (!row) return;
    const id = row.dataset.id;
    const orig = e.target.dataset.orig ?? '';
    
    if (e.target.value !== orig) {
        _dirtyRows.add(id);
        row.classList.add('row-dirty');
    } else {
        _dirtyRows.delete(id);
        row.classList.remove('row-dirty');
    }
    updateDirtyBar();
}

function updateDirtyBar() {
    const bar = document.getElementById('saveDirtyBar');
    const count = document.getElementById('dirtyCount');
    count.textContent = _dirtyRows.size;
    bar.classList.toggle('visible', _dirtyRows.size > 0);
}

function submitSaveAll() {
    const form = document.getElementById('saveAllForm');
    form.querySelectorAll('.dyn-input').forEach(el => el.remove());
    
    document.querySelectorAll('#sessionTbody input').forEach(el => {
        const h = document.createElement('input');
        h.type = 'hidden';
        h.name = el.name;
        h.value = el.value;
        h.className = 'dyn-input';
        form.appendChild(h);
    });
    form.submit();
}

function discardAll() {
    document.querySelectorAll('#sessionTbody input').forEach(el => {
        if (el.dataset.orig !== undefined) el.value = el.dataset.orig;
        el.closest('.session-row')?.classList.remove('row-dirty');
    });
    _dirtyRows.clear();
    updateDirtyBar();
}

function delRow(id) {
    if (!confirm('Padam sidang ini? Amaran: Semua data berkaitan kumpulan dan tugasan cawangan akan hilang.')) return;
    document.getElementById('delFrm').querySelector('[name=session_id]').value = id;
    document.getElementById('delFrm').submit();
}

function toggleAddCard() {
    const el = document.getElementById('addSession');
    el.style.display = (el.style.display === 'block') ? 'none' : 'block';
}

// ── Modal Logic ──
function openModal(id) {
    const m = document.getElementById(id);
    m.style.display = 'flex';
    setTimeout(() => m.classList.add('show'), 10);
}
function closeModal(id) {
    const m = document.getElementById(id);
    m.classList.remove('show');
    setTimeout(() => m.style.display = 'none', 200);
}
document.querySelectorAll('.pm-modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); });
});

function openAssignModal(sid, sessionName, assignedIdsString) {
    document.getElementById('assignSessionId').value = sid;
    document.getElementById('modalSessionName').innerText = sessionName;
    
    const assignedArray = assignedIdsString.split(',').filter(Boolean);
    document.querySelectorAll('.school-cb').forEach(cb => {
        cb.checked = assignedArray.includes(cb.value);
    });
    
    openModal('modal-assign-schools');
}

document.addEventListener('DOMContentLoaded', _load);
