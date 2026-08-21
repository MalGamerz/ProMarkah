// ── Sort ─────────────────────────────────────────────────────
function toggleSortNameJVM() {
    const field = document.getElementById('jvmSortNameField');
    if (field.value === '') field.value = 'ASC';
    else if (field.value === 'ASC') field.value = 'DESC';
    else field.value = '';
    document.getElementById('jvmFilterForm').submit();
}

$(document).ready(function() {
    $('.select-search').select2({ width: '100%' });

    // ── PAGINATION (paginate by student-block, not by raw row) ──────────────
    // Because the table uses rowspan, we group rows by their data-group index
    // and paginate those groups — never splitting a student block across pages.

    const rowsPerPageSelect = document.getElementById('rowsPerPageSelect');
    const paginationButtons = document.getElementById('paginationButtons');
    const pageInfo          = document.getElementById('pageInfo');

    let rowsPerPage = parseInt(rowsPerPageSelect.value);
    let currentPage = 1;

    // Build a map: groupIndex → [tr elements]
    function buildGroupMap() {
        const map = {};
        document.querySelectorAll('tr[data-group]').forEach(tr => {
            const g = tr.getAttribute('data-group');
            if (!map[g]) map[g] = [];
            map[g].push(tr);
        });
        return map;
    }

    function renderTable() {
        const groupMap   = buildGroupMap();
        const groupKeys  = Object.keys(groupMap);
        const totalGroups = groupKeys.length;
        const totalPages  = Math.ceil(totalGroups / rowsPerPage) || 1;

        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1)         currentPage = 1;

        const start = (currentPage - 1) * rowsPerPage;
        const end   = start + rowsPerPage;

        // Hide/show all rows
        groupKeys.forEach((key, idx) => {
            const display = (idx >= start && idx < end) ? '' : 'none';
            groupMap[key].forEach(tr => tr.style.display = display);
        });

        updatePaginationUI(totalPages, totalGroups, start, end);
    }

    function updatePaginationUI(totalPages, totalGroups, start, end) {
        const startText = totalGroups === 0 ? 0 : start + 1;
        const endText   = Math.min(end, totalGroups);
        pageInfo.innerHTML = `Memaparkan <b>${startText} – ${endText}</b> daripada <b>${totalGroups}</b> pelajar`;

        pmRenderPagination(paginationButtons, currentPage, totalPages, goToPage);
    }

    window.goToPage = function(page) {
        currentPage = page;
        renderTable();
        document.querySelector('.pm-table-wrap').scrollTo({ top: 0, behavior: 'smooth' });
    };

    rowsPerPageSelect.addEventListener('change', function() {
        rowsPerPage = parseInt(this.value);
        currentPage = 1;
        renderTable();
    });

    // ── Fit the marks table + pagination into the viewport, no page scroll ──
    function fitJvmTableHeight() {
        const scrollEl = document.querySelector('.jvm-table-scroll');
        const pagination = document.getElementById('paginationWrapper');
        if (!scrollEl || !pagination) return;
        if (window.innerWidth <= 600) {
            scrollEl.style.maxHeight = '';
            return;
        }
        const top = scrollEl.getBoundingClientRect().top;
        const paginationH = pagination.offsetHeight;
        const available = window.innerHeight - top - paginationH - 24; // 24px bottom breathing room
        scrollEl.style.maxHeight = Math.max(150, available) + 'px';
    }
    window.addEventListener('resize', fitJvmTableHeight);

    renderTable();
    fitJvmTableHeight();
});
