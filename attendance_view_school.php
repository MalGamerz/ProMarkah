<?php
// ══════════════════════════════════════════════════════════════════
//  attendance_view_school.php — Admin: student attendance table
//  for a specific school + session with live toggle and counters.
// ══════════════════════════════════════════════════════════════════

$school_id   = (int)$_GET['school_id'];
$session_id  = (int)($_GET['session_id'] ?? 0);
$school_name = getSchoolName($conn, $school_id);

// ── Attendance counts ─────────────────────────────────────────────
$total_q = $conn->prepare("SELECT COUNT(*) AS c FROM students WHERE school_id = ?");
$total_q->bind_param("i", $school_id);
$total_q->execute();
$total_c = (int)$total_q->get_result()->fetch_assoc()['c'];

$present_q = $conn->prepare("
    SELECT COUNT(DISTINCT a.student_id) AS c
    FROM attendance a
    JOIN students st ON a.student_id = st.student_id
    WHERE st.school_id = ? AND a.status = 'Present' AND a.session_id = ?
");
$present_q->bind_param("ii", $school_id, $session_id);
$present_q->execute();
$present_c = (int)$present_q->get_result()->fetch_assoc()['c'];
$absent_c  = $total_c - $present_c;
$pct_s     = $total_c > 0 ? round(($present_c / $total_c) * 100) : 0;

// ── Student list ──────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT st.*,
           (SELECT status FROM attendance
            WHERE student_id = st.student_id AND session_id = ? AND status = 'Present'
            LIMIT 1) AS is_present
    FROM students st
    WHERE st.school_id = ?
    ORDER BY st.student_name ASC
");
$stmt->bind_param("ii", $session_id, $school_id);
$stmt->execute();
$students = $stmt->get_result();

// Build student array for JS dropdown
$all_students = [];
while ($row = $students->fetch_assoc()) {
    $all_students[] = $row;
}
?>

<div class="avs-topbar">
    <a href="attendance.php?session_id=<?= $session_id ?>" class="avs-back-btn">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M19 12H5M5 12l7 7M5 12l7-7"/></svg>
        Kembali
    </a>
    <div class="avs-school-label">
        <span class="avs-school-dot"></span>
        <?= htmlspecialchars($school_name) ?>
    </div>
</div>

<div class="avs-stats-bar">
    <div class="avs-stat avs-stat-present">
        <div class="avs-stat-num" id="livePresent"><?= $present_c ?></div>
        <div class="avs-stat-lbl">Hadir</div>
    </div>
    <div class="avs-stat-divider"></div>
    <div class="avs-stat avs-stat-absent">
        <div class="avs-stat-num" id="liveAbsent"><?= $absent_c ?></div>
        <div class="avs-stat-lbl">Tidak Hadir</div>
    </div>
    <div class="avs-stat-divider"></div>
    <div class="avs-stat">
        <div class="avs-stat-num" id="livePct"><?= $pct_s ?>%</div>
        <div class="avs-stat-lbl">Kehadiran</div>
    </div>
    <div class="avs-progress-wrap">
        <div class="avs-progress-track">
            <div class="avs-progress-fill" id="liveBar" style="width:<?= $pct_s ?>%"></div>
        </div>
        <span class="avs-progress-label" id="liveRatio"><?= $present_c ?> / <?= $total_c ?> pesilat</span>
    </div>
</div>

<div class="avs-toolbar">
    <div class="avs-combo-wrap" id="comboWrap">
        <svg class="avs-search-icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        <input type="text" id="searchInput" class="avs-search" placeholder="Cari nama pesilat..." autocomplete="off">
        <button class="avs-search-clear" id="searchClear" onclick="clearSearch()">✕</button>
        <svg class="avs-chevron-icon" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" onclick="toggleDropdownChevron(event)"><path d="m6 9 6 6 6-6"/></svg>
        <div class="avs-dropdown" id="searchDropdown"></div>
    </div>

    <div class="avs-toolbar-right">
        <div class="avs-filter-pills">
            <button class="avs-pill avs-pill-active" data-filter="all"     onclick="filterTable('all',this)">Semua <span class="avs-pill-count" id="pillAll"><?= $total_c ?></span></button>
            <button class="avs-pill"                 data-filter="present" onclick="filterTable('present',this)">Hadir <span class="avs-pill-count avs-pill-count-green" id="pillPresent"><?= $present_c ?></span></button>
            <button class="avs-pill"                 data-filter="absent"  onclick="filterTable('absent',this)">Tidak Hadir <span class="avs-pill-count avs-pill-count-red" id="pillAbsent"><?= $absent_c ?></span></button>
        </div>
    </div>
</div>

<div class="avs-card">
    <div class="avs-list" id="studentList">
    <?php foreach ($all_students as $row):
        $is_present = !empty($row['is_present']);
        $sid = (int)$row['student_id'];
        $words    = array_filter(explode(' ', trim($row['student_name'])));
        $initials = strtoupper(implode('', array_map(fn($w) => $w[0], array_slice(array_values($words), 0, 2))));
    ?>
        <div class="avs-row <?= $is_present ? 'is-present' : 'is-absent' ?>"
             data-id="<?= $sid ?>"
             data-status="<?= $is_present ? 'present' : 'absent' ?>"
             data-name="<?= htmlspecialchars(strtolower($row['student_name'])) ?>">
            <div class="avs-avatar <?= $is_present ? 'avs-avatar-present' : '' ?>"><?= htmlspecialchars($initials) ?></div>
            <div class="avs-info">
                <div class="avs-name"><?= htmlspecialchars($row['student_name']) ?></div>
                <div class="avs-meta"><?= $row['gender'] === 'Male' ? 'Lelaki' : ($row['gender'] === 'Female' ? 'Perempuan' : '') ?></div>
            </div>
            <div class="avs-status-badge <?= $is_present ? 'avs-badge-present' : 'avs-badge-absent' ?>">
                <?= $is_present ? '✔ Hadir' : 'Tidak Hadir' ?>
            </div>
            <button class="avs-toggle-btn <?= $is_present ? 'avs-toggle-undo' : 'avs-toggle-mark' ?>"
                    onclick="toggleAttendance(<?= $sid ?>, <?= $session_id ?>, this)"
                    data-present="<?= $is_present ? '1' : '0' ?>">
                <?php if ($is_present): ?>
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg> Tanda Tidak Hadir
                <?php else: ?>
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg> Tanda Hadir
                <?php endif; ?>
            </button>
        </div>
    <?php endforeach; ?>
    <?php if (empty($all_students)): ?>
        <div class="avs-empty">Tiada pelajar didaftarkan untuk cawangan ini.</div>
    <?php endif; ?>
    </div>

    <div class="vm-pagination" id="paginationWrapper">
        <div class="vm-page-info" id="pageInfo">—</div>
        <div class="vm-page-btns" id="paginationButtons"></div>
    </div>
</div>

<span id="totalStudents" style="display:none"><?= $total_c ?></span>

<script>
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
</script>

<style>
/* fitAvsCardHeight()'s math assumes the card's bottom edge lands exactly
   `24px` above the viewport bottom — .pm-main's own bottom padding
   (var(--sp-10), shared across every page) would otherwise add its own gap
   on top of that, since it renders below the card inside the same wrapper.
   Zeroed out here, scoped to this page only. */
#pm-main { padding-bottom: 0; }
@media (max-width: 640px) {
    #pm-main { padding-bottom: var(--sp-5); } /* restore normal spacing when the card reverts to natural (auto) height on mobile */
}

/* ── Topbar ───────────────────────────────────────────────────────── */
.avs-topbar {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.avs-back-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border-radius: 8px;
    border: 1px solid var(--c-border, #e5e7eb);
    background: var(--c-surface-1, #fff);
    color: var(--c-text-muted, #4b5563);
    font-size: 0.85rem;
    font-weight: 600;
    text-decoration: none;
    transition: border-color .15s, color .15s;
    white-space: nowrap;
}
.avs-back-btn:hover { border-color: var(--c-red, #b30000); color: var(--c-red, #b30000); }
.avs-school-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-family: 'Bebas Neue', sans-serif;
    font-size: 1.6rem;
    letter-spacing: .04em;
    color: var(--c-text, #111827);
}
.avs-school-dot {
    width: 10px; height: 10px;
    border-radius: 50%;
    background: var(--c-red, #b30000);
    flex-shrink: 0;
}

/* ── Stats bar ────────────────────────────────────────────────────── */
.avs-stats-bar {
    display: flex;
    align-items: center;
    background: var(--c-surface-1, #fff);
    border: 1px solid var(--c-border, #e5e7eb);
    border-radius: 12px;
    padding: 18px 24px;
    margin-bottom: 8px;
    flex-wrap: wrap;
    gap: 16px;
}
.avs-stat { display: flex; flex-direction: column; align-items: center; min-width: 64px; }
.avs-stat-num {
    font-size: 2rem; font-weight: 800; line-height: 1;
    font-variant-numeric: tabular-nums; transition: color .3s;
}
.avs-stat-lbl { font-size: 0.72rem; color: var(--c-text-muted, #6b7280); margin-top: 3px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
.avs-stat-present .avs-stat-num { color: #16a34a; }
.avs-stat-absent  .avs-stat-num { color: var(--c-text-muted, #9ca3af); }
.avs-stat-divider { width: 1px; height: 36px; background: var(--c-border, #e5e7eb); align-self: center; }
.avs-progress-wrap { flex: 1 1 160px; display: flex; flex-direction: column; gap: 6px; margin-left: 8px; }
.avs-progress-track { height: 8px; background: var(--c-surface-3, #f3f4f6); border-radius: 4px; overflow: hidden; }
.avs-progress-fill { height: 100%; background: linear-gradient(90deg, #16a34a, #4ade80); border-radius: 4px; transition: width .4s cubic-bezier(.16,1,.3,1); }
.avs-progress-label { font-size: 0.75rem; color: var(--c-text-muted, #6b7280); font-weight: 500; }

/* ── Toolbar ──────────────────────────────────────────────────────── */
.avs-toolbar {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
    flex-wrap: wrap;
}
.avs-toolbar-right {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    flex: 1;
    justify-content: flex-end;
}

/* ── Combo search + Dropdown Integrated ───────────────────────────── */
/* 1. LOCK THE WRAPPER HEIGHT */
.avs-combo-wrap {
    position: relative;
    flex: 1 1 200px;
    min-width: 180px;
    max-width: 340px;
    display: flex; 
    align-items: center;
    /* Force the container to be exactly 38px tall, no exceptions */
    height: 38px !important; 
    min-height: 38px !important;
    max-height: 38px !important;
}

/* 2. FORCE THE INPUT TO OBEY THE WRAPPER */
.avs-search {
    width: 100%;
    height: 100% !important; /* Take up exactly the 38px of the wrapper */
    margin: 0 !important;
    /* The !important below destroys global top/bottom padding */
    padding-top: 0 !important;
    padding-bottom: 0 !important;
    padding-left: 36px !important;
    padding-right: 36px !important;
    border: 1px solid var(--c-border, #e5e7eb);
    border-radius: 8px;
    background: var(--c-surface-1, #fff);
    color: var(--c-text, #111827);
    font-family: 'DM Sans', sans-serif;
    font-size: 0.9rem;
    outline: none;
    transition: border-color .15s;
    box-sizing: border-box !important;
    line-height: 1 !important; /* Stops weird text alignment issues */
    -webkit-appearance: none; /* Stops iOS Safari from adding weird form styles */
}
.avs-search-icon {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
    color: var(--c-text-faint, #9ca3af); pointer-events: none;
    z-index: 2;
}

.avs-search:focus { border-color: var(--c-red, #b30000); box-shadow: 0 0 0 3px rgba(179,0,0,.08); }
.avs-search-clear {
    position: absolute; right: 32px; top: 50%; transform: translateY(-50%);
    background: none; border: none; color: var(--c-text-faint, #9ca3af);
    font-size: 0.8rem; cursor: pointer; display: none; align-items: center; padding: 2px 4px;
    z-index: 2;
}
.avs-search-clear:hover { color: var(--c-red, #b30000); }

.avs-chevron-icon {
    position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
    color: var(--c-text-faint, #9ca3af); pointer-events: auto; cursor: pointer;
    transition: transform .2s ease;
    z-index: 2;
}
.avs-combo-wrap.is-open .avs-chevron-icon {
    transform: translateY(-50%) rotate(180deg);
}

/* Dropdown suggestions */
.avs-dropdown {
    display: none;
    position: absolute;
    top: calc(100% + 4px);
    left: 0; right: 0;
    background: var(--c-surface-1, #fff);
    border: 1px solid var(--c-border, #e5e7eb);
    border-radius: 8px;
    box-shadow: 0 8px 24px rgba(0,0,0,.12);
    z-index: 100;
    overflow: hidden;
    max-height: 260px;
    overflow-y: auto;
}
.avs-dd-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 9px 14px;
    font-size: 0.875rem;
    color: var(--c-text, #111827);
    cursor: pointer;
    gap: 10px;
    transition: background .1s;
}
.avs-dd-item:hover { background: var(--c-surface-2, #f9fafb); }
.avs-dd-item mark { background: rgba(179,0,0,.15); color: var(--c-red, #b30000); border-radius: 2px; padding: 0 1px; }
.avs-dd-badge { font-size: 0.7rem; font-weight: 700; padding: 2px 8px; border-radius: 10px; white-space: nowrap; flex-shrink: 0; }
.avs-dd-badge-present { background: #dcfce7; color: #15803d; }
.avs-dd-badge-absent  { background: var(--c-surface-3, #f3f4f6); color: var(--c-text-faint, #9ca3af); }

/* ── Filter pills ─────────────────────────────────────────────────── */
.avs-filter-pills { display: flex; gap: 6px; flex-wrap: wrap; }
.avs-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; border-radius: 20px;
    border: 1px solid var(--c-border, #e5e7eb);
    background: var(--c-surface-1, #fff);
    color: var(--c-text-muted, #4b5563);
    font-family: 'DM Sans', sans-serif; font-size: 0.82rem; font-weight: 600;
    cursor: pointer; transition: all .15s; white-space: nowrap;
}
.avs-pill:hover { border-color: var(--c-red, #b30000); color: var(--c-red, #b30000); }
.avs-pill-active { background: var(--c-text, #111827); border-color: var(--c-text, #111827); color: #fff; }
.avs-pill-active:hover { background: var(--c-red, #b30000); border-color: var(--c-red, #b30000); color: #fff; }
.avs-pill-count { background: rgba(255,255,255,.2); border-radius: 10px; padding: 1px 7px; font-size: 0.75rem; font-weight: 700; }
.avs-pill:not(.avs-pill-active) .avs-pill-count { background: var(--c-surface-3, #f3f4f6); color: var(--c-text-muted); }
.avs-pill-count-green { color: #16a34a !important; }
.avs-pill-count-red   { color: #dc2626 !important; }

/* ── Student list card ────────────────────────────────────────────── */
.avs-card {
    background: var(--c-surface-1, #fff);
    border: 1px solid var(--c-border, #e5e7eb);
    border-radius: 12px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    /* Height is set dynamically in JS (fitAvsCardHeight) so it always leaves
       room for the pagination bar below it instead of a static
       calc(100vh - Npx) that goes stale whenever the filter pills above the
       card wrap to more rows. */
}
@media (max-width: 640px) {
    .avs-card { height: auto !important; }
}
.avs-list { 
    overflow-x: hidden; 
    overflow-y: auto; 
    flex: 1; /* Automatically takes up all remaining space inside the card */
    -webkit-overflow-scrolling: touch; 
}

.avs-row {
    display: flex; align-items: center; gap: 14px;
    padding: 13px 18px;
    border-bottom: 1px solid var(--c-border, #f3f4f6);
    transition: background .15s;
}
.avs-row:last-child { border-bottom: none; }
.avs-row:hover { background: var(--c-surface-2, #f9fafb); }

.avs-avatar {
    width: 38px; height: 38px; border-radius: 50%;
    background: var(--c-surface-3, #f3f4f6);
    border: 2px solid var(--c-border, #e5e7eb);
    display: flex; align-items: center; justify-content: center;
    font-size: 0.75rem; font-weight: 800;
    color: var(--c-text-muted, #6b7280);
    flex-shrink: 0; transition: all .2s;
}
.avs-avatar-present { background: #dcfce7; border-color: #86efac; color: #15803d; }
.avs-info { flex: 1; min-width: 0; }
.avs-name { font-weight: 600; font-size: 0.9rem; color: var(--c-text, #111827); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.avs-meta { font-size: 0.75rem; color: var(--c-text-faint, #9ca3af); margin-top: 1px; }

.avs-status-badge { font-size: 0.75rem; font-weight: 700; padding: 3px 10px; border-radius: 12px; white-space: nowrap; flex-shrink: 0; }
.avs-badge-present { background: #dcfce7; color: #15803d; }
.avs-badge-absent  { background: var(--c-surface-3, #f3f4f6); color: var(--c-text-faint, #9ca3af); }

.avs-toggle-btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 7px 14px; border-radius: 8px; border: 1px solid transparent;
    font-family: 'DM Sans', sans-serif; font-size: 0.82rem; font-weight: 700;
    cursor: pointer; white-space: nowrap; flex-shrink: 0; transition: all .15s;
}
.avs-toggle-mark { background: var(--c-red, #b30000); color: #fff; border-color: var(--c-red, #b30000); }
.avs-toggle-mark:hover { background: #900000; border-color: #900000; }
.avs-toggle-undo { background: var(--c-surface-1, #fff); color: var(--c-text-muted, #6b7280); border-color: var(--c-border, #e5e7eb); }
.avs-toggle-undo:hover { border-color: #dc2626; color: #dc2626; }

.avs-empty { text-align: center; color: var(--c-text-muted, #6b7280); padding: 48px 24px; font-size: 0.9rem; }

/* ── Pagination — identical markup/classes to pic_view_marks.php /
   pic_master_list.php's .vm-pagination system, so every paginated page in
   the app looks and behaves identically. ── */
.vm-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    padding: 16px;
    background: var(--c-surface-2, #f9fafb);
    border-top: 1px solid var(--c-border, #e5e7eb);
}
.vm-page-info {
    font-size: 0.8rem;
    color: var(--c-text-faint);
    font-weight: 500;
}
.vm-page-btns { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.vm-page-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 34px;
    height: 34px;
    padding: 0 10px;
    border-radius: 6px;
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--c-text-muted);
    background: var(--c-surface-2, #f3f4f6);
    border: 1px solid var(--c-border-strong, #d1d5db);
    text-decoration: none;
    cursor: pointer;
    transition: all 0.15s;
    white-space: nowrap;
}
.vm-page-btn:hover:not(:disabled) { color: #fff; border-color: var(--c-red, #b30000); background: var(--c-red-dim, #b30000); }
.vm-page-btn.vm-page-active { background: var(--c-red, #b30000); border-color: var(--c-red, #b30000); color: #fff; cursor: default; pointer-events: none; }
.vm-page-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.vm-page-ellipsis { display: inline-flex; align-items: center; height: 34px; color: var(--c-text-faint, #9ca3af); font-size: 0.85rem; padding: 0 4px; }

html.pm-light .vm-page-info { color: #6b7280; }
html.pm-light .vm-page-btn { background: #ffffff; border-color: #d1d5db; color: #374151; }
html.pm-light .vm-page-btn:hover:not(:disabled) { background: #fee2e2; border-color: #e2581e; color: #b91c1c; }
html.pm-light .vm-page-btn.vm-page-active { background: #e2581e; border-color: #e2581e; color: #ffffff; }
html.pm-light .vm-page-ellipsis { color: #9ca3af; }
html.pm-light .vm-pagination { border-top-color: #e5e7eb; }

/* ── Mobile ───────────────────────────────────────────────────────── */
@media (max-width: 600px) {
    .avs-stats-bar   { padding: 14px 16px; gap: 12px; }
    .avs-stat-num    { font-size: 1.6rem; }
    .avs-row         { padding: 11px 14px; gap: 10px; }
    .avs-status-badge { display: none; }
    .avs-toolbar { flex-direction: column; align-items: stretch; gap: 16px; }
    .avs-combo-wrap { max-width: 100%; width: 100%; }
    .avs-toolbar-right { justify-content: flex-end; }
    .vm-pagination { flex-direction: column; justify-content: center; text-align: center; }
    .vm-page-btns { width: 100%; justify-content: center; }
    .avs-toggle-btn  { padding: 6px 10px; font-size: 0.78rem; }
}
</style>