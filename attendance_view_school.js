// attendance_view_school.js — admin table of one school's students for a
// session, with live present/absent toggling, search, filter pills, and
// pagination. Split out of attendance_view_school.php's inline <script>
// block — no PHP interpolation here (server data arrives via DOM data-*
// attributes and the #totalStudents span, not embedded values), so this
// is a plain file move, no bootstrap-data object needed.

// ════════════════════════════════════════════════════════
//  State
// ════════════════════════════════════════════════════════
let currentPage    = 1;
const rowsPerPage  = 20; // fixed, matching pic_view_marks.php/pic_master_list.php — no per-user page-size picker
let activeFilter   = 'all';
let searchQuery    = '';
let filteredRows   = [];

const allRows = Array.from(document.querySelectorAll('#studentList .avs-row'));

// ════════════════════════════════════════════════════════
//  Core render
// ════════════════════════════════════════════════════════
function applyAll() {
    // 1. Filter
    filteredRows = allRows.filter(row => {
        const matchFilter = activeFilter === 'all' || row.dataset.status === activeFilter;
        const matchSearch = !searchQuery || row.dataset.name.includes(searchQuery);
        return matchFilter && matchSearch;
    });

    // 2. Clamp page
    const totalPages = Math.max(1, Math.ceil(filteredRows.length / rowsPerPage));
    if (currentPage > totalPages) currentPage = totalPages;

    // 3. Show/hide rows
    const start = (currentPage - 1) * rowsPerPage;
    const end   = start + rowsPerPage;
    allRows.forEach(r => r.style.display = 'none');
    filteredRows.forEach((r, i) => {
        r.style.display = (i >= start && i < end) ? '' : 'none';
    });

    renderPagination(totalPages);
}

// ════════════════════════════════════════════════════════
//  Pagination UI  (same style as silibus)
// ════════════════════════════════════════════════════════
function renderPagination(totalPages) {
    const info    = document.getElementById('pageInfo');
    const btnWrap = document.getElementById('paginationButtons');

    const startNum = filteredRows.length === 0 ? 0 : (currentPage - 1) * rowsPerPage + 1;
    const endNum   = Math.min(currentPage * rowsPerPage, filteredRows.length);
    info.innerHTML = `Memaparkan <b>${startNum}–${endNum}</b> daripada <b>${filteredRows.length}</b> rekod`;

    let html = '';
    html += `<button class="vm-page-btn" ${currentPage===1?'disabled':''} onclick="goToPage(${currentPage-1})">&laquo;</button>`;

    let sp = Math.max(1, currentPage - 2);
    let ep = Math.min(totalPages, sp + 4);
    if (ep - sp < 4) sp = Math.max(1, ep - 4);

    if (sp > 1) {
        html += `<button class="vm-page-btn" onclick="goToPage(1)">1</button>`;
        if (sp > 2) html += `<span class="vm-page-ellipsis">&hellip;</span>`;
    }
    for (let i = sp; i <= ep; i++) {
        html += `<button class="vm-page-btn ${i===currentPage?'vm-page-active':''}" onclick="goToPage(${i})">${i}</button>`;
    }
    if (ep < totalPages) {
        if (ep < totalPages - 1) html += `<span class="vm-page-ellipsis">&hellip;</span>`;
        html += `<button class="vm-page-btn" onclick="goToPage(${totalPages})">${totalPages}</button>`;
    }
    html += `<button class="vm-page-btn" ${currentPage===totalPages||totalPages===0?'disabled':''} onclick="goToPage(${currentPage+1})">&raquo;</button>`;

    btnWrap.innerHTML = html;
}

window.goToPage = function(p) {
    currentPage = p;
    applyAll();
    // Smooth scroll back to the top of the list container, not the whole page
    document.getElementById('studentList').scrollTo({ top: 0, behavior: 'smooth' });
};

// ════════════════════════════════════════════════════════
//  Filter pills
// ════════════════════════════════════════════════════════
window.filterTable = function(filter, pillEl) {
    activeFilter = filter;
    currentPage  = 1;
    document.querySelectorAll('.avs-pill').forEach(p => p.classList.remove('avs-pill-active'));
    if (pillEl) pillEl.classList.add('avs-pill-active');
    applyAll();
};

// Re-filters/re-counts the list WITHOUT resetting currentPage — used after
// toggling a single student's attendance, where the active filter/pill
// selection needs refreshing but the user's current page shouldn't jump
// back to 1 just because one row's status changed.
function refreshFilterInPlace() {
    applyAll();
}

// ════════════════════════════════════════════════════════
//  Combo search + dropdown suggestions
// ════════════════════════════════════════════════════════
const searchInput    = document.getElementById('searchInput');
const searchDropdown = document.getElementById('searchDropdown');
const searchClear    = document.getElementById('searchClear');
const comboWrap      = document.getElementById('comboWrap');

searchInput.addEventListener('input', function() {
    const val = this.value.trim();
    searchQuery = val.toLowerCase();
    searchClear.style.display = val ? 'flex' : 'none';
    currentPage = 1;
    applyAll();
    renderDropdown(val);
});

searchInput.addEventListener('focus', function() {
    renderDropdown(this.value.trim());
});

searchInput.addEventListener('click', function(e) {
    e.stopPropagation();
    renderDropdown(this.value.trim());
});

window.toggleDropdownChevron = function(e) {
    e.stopPropagation();
    if (searchDropdown.style.display === 'block') {
        searchDropdown.style.display = 'none';
        comboWrap.classList.remove('is-open');
    } else {
        searchInput.focus();
        renderDropdown(searchInput.value.trim());
    }
};

document.addEventListener('click', function(e) {
    if (!comboWrap.contains(e.target)) {
        searchDropdown.style.display = 'none';
        comboWrap.classList.remove('is-open');
    }
});

function renderDropdown(val) {
    const q = val.toLowerCase();
    // Filters rows based on query. If query is empty, displays first 8 students.
    const matches = allRows
        .filter(r => !q || r.dataset.name.includes(q))
        .slice(0, 8);

    if (!matches.length) { 
        searchDropdown.style.display = 'none'; 
        comboWrap.classList.remove('is-open');
        return; 
    }

    searchDropdown.innerHTML = matches.map(r => {
        const name   = r.querySelector('.avs-name').textContent;
        const status = r.dataset.status;
        const badge  = status === 'present'
            ? `<span class="avs-dd-badge avs-dd-badge-present">Hadir</span>`
            : `<span class="avs-dd-badge avs-dd-badge-absent">Tidak Hadir</span>`;
        
        // Escape single quotes for safely passing string to selectStudent()
        const escapedName = name.replace(/'/g, "\\'");
        const highlighted = q 
            ? name.replace(new RegExp(`(${val.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&')})`, 'gi'), '<mark>$1</mark>')
            : name;

        return `<div class="avs-dd-item" onclick="selectStudent('${escapedName}')">
                    <span>${highlighted}</span>${badge}
                </div>`;
    }).join('');

    searchDropdown.style.display = 'block';
    comboWrap.classList.add('is-open');
}

window.selectStudent = function(name) {
    searchInput.value    = name;
    searchQuery          = name.toLowerCase();
    searchClear.style.display = 'flex';
    searchDropdown.style.display = 'none';
    comboWrap.classList.remove('is-open');
    currentPage = 1;
    applyAll();
};

window.clearSearch = function() {
    searchInput.value = '';
    searchQuery = '';
    searchClear.style.display = 'none';
    searchDropdown.style.display = 'none';
    comboWrap.classList.remove('is-open');
    currentPage = 1;
    applyAll();
    searchInput.focus();
};

// ════════════════════════════════════════════════════════
//  Toggle attendance
// ════════════════════════════════════════════════════════
window.toggleAttendance = function(studentId, sessionId, btn) {
    const isPresent = btn.dataset.present === '1';
    const newStatus = isPresent ? 'Absent' : 'Present';
    const row = btn.closest('.avs-row');
    const orig = btn.innerHTML;

    btn.disabled = true;
    btn.style.opacity = '0.5';

    fetch('save_attendance.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    `student_id=${studentId}&session_id=${sessionId}&status=${newStatus}`
    })
    .then(r => r.text())
    .then(data => {
        if (data.trim() === 'success') {
            if (newStatus === 'Present') {
                row.classList.replace('is-absent', 'is-present');
                row.dataset.status = 'present';
                row.querySelector('.avs-avatar').classList.add('avs-avatar-present');
                row.querySelector('.avs-status-badge').className = 'avs-status-badge avs-badge-present';
                row.querySelector('.avs-status-badge').textContent = '✔ Hadir';
                btn.className = 'avs-toggle-btn avs-toggle-undo';
                btn.dataset.present = '1';
                btn.innerHTML = `<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg> Tanda Tidak Hadir`;
                updateCounters(1);
            } else {
                row.classList.replace('is-present', 'is-absent');
                row.dataset.status = 'absent';
                row.querySelector('.avs-avatar').classList.remove('avs-avatar-present');
                row.querySelector('.avs-status-badge').className = 'avs-status-badge avs-badge-absent';
                row.querySelector('.avs-status-badge').textContent = 'Tidak Hadir';
                btn.className = 'avs-toggle-btn avs-toggle-mark';
                btn.dataset.present = '0';
                btn.innerHTML = `<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg> Tanda Hadir`;
                updateCounters(-1);
            }
            // Re-apply filter so pill counts stay correct after status change —
            // without resetting currentPage back to 1.
            const activePill = document.querySelector('.avs-pill-active');
            if (activePill) activeFilter = activePill.dataset.filter;
            refreshFilterInPlace();
        } else {
            alert('Ralat: ' + data);
            btn.innerHTML = orig;
        }
        btn.disabled = false;
        btn.style.opacity = '';
    })
    .catch(() => {
        alert('Ralat Rangkaian');
        btn.innerHTML = orig;
        btn.disabled = false;
        btn.style.opacity = '';
    });
};

// ════════════════════════════════════════════════════════
//  Live counters
// ════════════════════════════════════════════════════════
function updateCounters(delta) {
    const pe = document.getElementById('livePresent');
    const ae = document.getElementById('liveAbsent');
    const pc = document.getElementById('livePct');
    const ba = document.getElementById('liveBar');
    const tt = document.getElementById('totalStudents');
    const pp = document.getElementById('pillPresent');
    const pa = document.getElementById('pillAbsent');
    const lr = document.getElementById('liveRatio');
    if (!pe || !tt) return;

    const total   = parseInt(tt.textContent) || 0;
    const present = Math.max(0, parseInt(pe.textContent) + delta);
    const absent  = Math.max(0, total - present);
    const pct     = total > 0 ? Math.round((present / total) * 100) : 0;

    pe.textContent  = present;
    ae.textContent  = absent;
    pc.textContent  = pct + '%';
    ba.style.width  = pct + '%';
    if (pp) pp.textContent = present;
    if (pa) pa.textContent = absent;
    if (lr) lr.textContent = `${present} / ${total} pesilat`;
}

// ── Fit the student list card + pagination into the viewport, no page scroll ──
// .pm-main's own bottom padding is zeroed out for this page specifically
// (see the #pm-main override in the <style> block below), so this math
// doesn't need to account for it separately — top + height lands exactly
// at the viewport bottom minus the breathing-room constant.
function fitAvsCardHeight() {
    const card = document.querySelector('.avs-card');
    if (!card) return;
    if (window.innerWidth <= 640) {
        card.style.height = '';
        return;
    }
    const top = card.getBoundingClientRect().top;
    const available = window.innerHeight - top - 24; // 24px bottom breathing room
    card.style.height = Math.max(150, available) + 'px';
}
window.addEventListener('resize', fitAvsCardHeight);

// ── Init ──────────────────────────────────────────────
applyAll();
fitAvsCardHeight();
// Re-measure once more after the initial paint/font-swap settles, in case
// web fonts loading late shifted the topbar/stats/toolbar height above the
// card (which would otherwise leave the first computed height stale).
requestAnimationFrame(fitAvsCardHeight);
