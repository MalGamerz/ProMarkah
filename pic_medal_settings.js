// pic_medal_settings.js — the PIC "Tetapan Pingat" (medal settings) page
// (pic_medal_settings.php). Split out of that file's inline <script> block,
// which had nothing to do with the PHP rendering logic around it. No PHP
// interpolation here, so this is a plain file move, no bootstrap-data
// object needed.

// ── Searchable dropdown logic (matches pic_students.php's dd-wrap) ──
// Selecting an option submits the enclosing GET form (this page reloads
// on filter change, same as the plain <select>s it replaces).
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
    document.getElementById('ddWrap_' + name).closest('form').submit();
}

document.addEventListener('click', e => {
    if (!e.target.closest('.dd-wrap')) {
        document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
        document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
    }
});

const pmMedalTbody = document.getElementById('medalTableBody');
const pmMedalRows  = pmMedalTbody ? Array.from(pmMedalTbody.getElementsByClassName('medal-row')) : [];
let pmCurrentPage  = 1;
const pmRowsPerPage = 20;

function updateMedalPagination() {
    const ctrl = document.getElementById('paginationControls');
    const btnContainer = document.getElementById('paginationButtons');
    const infoText = document.getElementById('pageInfo');

    if (!ctrl || !pmMedalTbody || pmMedalRows.length === 0) {
        if (ctrl) ctrl.style.display = 'none';
        return;
    }

    const totalRows = pmMedalRows.length;

    const rpp        = pmRowsPerPage;
    const totalPages = Math.ceil(totalRows / rpp) || 1;

    if (pmCurrentPage > totalPages) pmCurrentPage = totalPages;
    if (pmCurrentPage < 1)          pmCurrentPage = 1;

    const start = (pmCurrentPage - 1) * rpp;
    const end   = start + rpp;

    // Hide/Show Rows
    pmMedalRows.forEach((r, i) => {
        r.style.display = (i >= start && i < end) ? '' : 'none';
    });

    // Update Info Text
    const endDisplay = Math.min(end, totalRows);
    const startDisplay = totalRows === 0 ? 0 : start + 1;
    infoText.innerHTML = `Memaparkan <b>${startDisplay} - ${endDisplay}</b> daripada <b>${totalRows}</b> rekod`;

    pmRenderPagination(btnContainer, pmCurrentPage, totalPages, goToMedalPage);
}

function goToMedalPage(page) {
    pmCurrentPage = page;
    updateMedalPagination();

    // Scroll to the top of the TABLE container only, avoiding full page scroll
    const wrapper = document.querySelector('.table-scroll-wrapper');
    if (wrapper) wrapper.scrollTo({ top: 0, behavior: 'smooth' });
}

// ── Fit the medal table + pagination into the viewport, no page scroll ──
function fitMedalTableHeight() {
    const scrollEl = document.querySelector('.table-scroll-wrapper');
    const pagination = document.getElementById('paginationControls');
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
window.addEventListener('resize', fitMedalTableHeight);

// Initialize on page load
updateMedalPagination();
fitMedalTableHeight();
