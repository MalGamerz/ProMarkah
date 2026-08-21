let _t = null;
let _dirtyRows = new Set();

// ── Searchable dropdown logic (matches pic_levels.php / pic_sessions.php) ──
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

function ddSchoolSearch(val) {
    const opts = document.querySelectorAll('#ddOpts_school .dd-opt');
    const empty = document.getElementById('ddEmpty_school');
    let any = false;
    opts.forEach(o => {
        const m = o.textContent.toLowerCase().includes(val.toLowerCase());
        o.classList.toggle('hidden', !m);
        if (m) any = true;
    });
    empty.style.display = any ? 'none' : 'block';

    const lbl = document.getElementById('ddLabel_school');
    lbl.textContent = val || '-- Semua Cawangan --';
    lbl.style.color = val ? '' : 'var(--c-text-faint)';
    ajaxFilter();
}

function ddSelectSchool(value, label) {
    document.getElementById('f_search').value = value;
    const lbl = document.getElementById('ddLabel_school');
    lbl.textContent = label;
    lbl.style.color = value === '' ? 'var(--c-text-faint)' : '';
    document.querySelectorAll('#ddOpts_school .dd-opt').forEach(o => o.classList.toggle('selected', o.dataset.value === value));
    document.getElementById('ddPanel_school').classList.remove('open');
    document.getElementById('ddTrigger_school').classList.remove('open');
    ajaxFilter();
}

document.addEventListener('click', e => {
    if (!e.target.closest('.dd-wrap')) {
        document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
        document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
    }
});

// ── List Data Logic ──
function ajaxFilter() {
    clearTimeout(_t);
    _t = setTimeout(_load, 400);
}

function _load() {
    const params = new URLSearchParams({
        ajax: '1',
        search: document.getElementById('f_search').value
    });

    const tbody = document.getElementById('schoolTbody');
    const spinner = document.getElementById('ajaxSpinner');

    tbody.style.display = 'none';
    spinner.style.display = 'block';

    pmFetch('pic_schools.php?' + params)
        .then(r => r.text())
        .then(html => {
            tbody.innerHTML = html;
            tbody.style.display = 'block';
            spinner.style.display = 'none';
            _dirtyRows.clear();
            updateDirtyBar();
            attachListeners();
            schoolsCurrentPage = 1;
            updateSchoolsPagination();
            fitSchoolListHeight();
        })
        .catch(() => {
            tbody.style.display = 'block';
            spinner.style.display = 'none';
        });
}

// ── Fit the school list + pagination into the viewport, no page scroll ──
function fitSchoolListHeight() {
    if (window.innerWidth <= 640) {
        document.getElementById('schoolListScroll').style.maxHeight = '';
        return;
    }
    const scrollEl   = document.getElementById('schoolListScroll');
    const pagination = document.getElementById('schoolsPaginationContainer');
    const top = scrollEl.getBoundingClientRect().top;
    const paginationH = pagination.offsetHeight;
    const available = window.innerHeight - top - paginationH - 24; // 24px bottom breathing room
    scrollEl.style.maxHeight = Math.max(150, available) + 'px';
}
window.addEventListener('resize', fitSchoolListHeight);

// ── PAGINATION (client-side, 20 per page — matches pic_view_marks.php) ──
let schoolsCurrentPage = 1;
const schoolsPerPage = 20;

function updateSchoolsPagination() {
    const rows = Array.from(document.querySelectorAll('#schoolTbody .school-row'));
    const container = document.getElementById('schoolsPaginationContainer');
    const info = document.getElementById('schoolsPageInfo');
    const btns = document.getElementById('schoolsPaginationButtons');

    if (rows.length === 0) { container.style.display = 'none'; return; }

    const total = rows.length;
    const totalPages = Math.max(1, Math.ceil(total / schoolsPerPage));
    if (schoolsCurrentPage > totalPages) schoolsCurrentPage = totalPages;
    if (schoolsCurrentPage < 1) schoolsCurrentPage = 1;

    container.style.display = totalPages <= 1 ? 'none' : 'flex';

    const start = (schoolsCurrentPage - 1) * schoolsPerPage;
    const end   = start + schoolsPerPage;
    rows.forEach((r, i) => { r.style.display = (i >= start && i < end) ? '' : 'none'; });

    const s = start + 1;
    const e = Math.min(end, total);
    info.innerHTML = `Memaparkan <b>${s}–${e}</b> daripada <b>${total}</b> cawangan`;

    pmRenderPagination(btns, schoolsCurrentPage, totalPages, schoolsGoToPage);
}

function schoolsGoToPage(page) {
    schoolsCurrentPage = page;
    updateSchoolsPagination();
}

function attachListeners() {
    document.querySelectorAll('#schoolTbody input').forEach(el => {
        if (el.dataset.listening) return;
        el.dataset.listening = '1';
        el.addEventListener('input', onInputChange);
    });
}

function onInputChange(e) {
    const row = e.target.closest('.school-row');
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
    const count = document.getElementById('dirtyCount');
    count.textContent = _dirtyRows.size;
}

function submitSaveAll() {
    const form = document.getElementById('saveAllForm');
    form.querySelectorAll('.dyn-input').forEach(el => el.remove());

    document.querySelectorAll('#schoolTbody input').forEach(el => {
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
    document.querySelectorAll('#schoolTbody input').forEach(el => {
        if (el.dataset.orig !== undefined) el.value = el.dataset.orig;
        el.closest('.school-row')?.classList.remove('row-dirty');
    });
    _dirtyRows.clear();
    updateDirtyBar();
}

function delRow(id) {
    if (!confirm('Padam cawangan ini?')) return;
    document.getElementById('delFrm').querySelector('[name=school_id]').value = id;
    document.getElementById('delFrm').submit();
}

function toggleAddCard() {
    const el = document.getElementById('addSchool');
    el.style.display = (el.style.display === 'block') ? 'none' : 'block';
    setTimeout(fitSchoolListHeight, 0);
}

document.addEventListener('DOMContentLoaded', () => {
    _load();
    fitSchoolListHeight();
});
