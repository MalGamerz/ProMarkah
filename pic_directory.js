// pic_directory.js — the PIC "Direktori" page (pic_directory.php). Split
// out of that file's inline <script> block, which had nothing to do with
// the PHP rendering logic around it. No PHP interpolation here, so this is
// a plain file move, no bootstrap-data object needed.

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

// Kumpulan is scoped server-side to whichever Peringkat is selected — so a
// Kumpulan chosen under a since-changed Peringkat is no longer one of the
// options being shown and needs to be cleared, not silently left applied as
// a stale hidden-field value the dropdown itself no longer reflects.
const DD_LEVEL_TO_GROUP = { level1: 'group1' };

function ddSelect(name, value, label) {
    document.getElementById('f_' + name).value = value;
    const dependentGroup = DD_LEVEL_TO_GROUP[name];
    if (dependentGroup) {
        const groupField = document.getElementById('f_' + dependentGroup);
        if (groupField) groupField.value = '';
    }
    document.getElementById('ddWrap_' + name).closest('form').submit();
}

document.addEventListener('click', e => {
    if (!e.target.closest('.dd-wrap')) {
        document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
        document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
    }
});

// ── Cari Pelajar autocomplete (students_group tab only) ────────────
// Suggestion pool is built once from the names already rendered on this
// page load — same source-of-truth approach as pic_master_list.php's
// Carian Nama dropdown. Caveat inherited from that pattern: if the page
// loaded with a search term already active, the pool only contains
// whatever matched that term (the full unfiltered list isn't fetched
// separately) — acceptable since by that point the user has already
// narrowed in on a name and is looking at results, not still browsing.
let dirStudentNames = [];
(function () {
    const wrapper = document.getElementById('nameDropdownWrapper');
    if (!wrapper) return; // not on the students_group tab

    dirStudentNames = [...new Set(
        Array.from(document.querySelectorAll('.dir-student-name')).map(el => el.textContent.trim())
    )].sort();

    document.addEventListener('click', e => {
        if (!wrapper.contains(e.target)) {
            document.getElementById('nameDropdownBox').classList.remove('open');
        }
    });
})();

function dirFilterNameOptions(keyword) {
    const box = document.getElementById('nameDropdownBox');
    const list = document.getElementById('nameDropdownList');
    const kw = keyword.trim().toLowerCase();

    const matches = kw === '' ? dirStudentNames : dirStudentNames.filter(n => n.toLowerCase().includes(kw));

    if (matches.length === 0) {
        list.innerHTML = kw === '' ? '' : `<div class="dir-name-empty">Tiada hasil ditemui</div>`;
    } else {
        list.innerHTML = matches.slice(0, 20).map(name =>
            `<div class="dd-option" role="option" tabindex="0" onclick="dirSelectName('${name.replace(/'/g, "\\'")}')">${name}</div>`
        ).join('');
    }
    box.classList.toggle('open', matches.length > 0 || kw !== '');
}

function dirSelectName(name) {
    const input = document.getElementById('dirNameSearch');
    input.value = name;
    document.getElementById('nameDropdownBox').classList.remove('open');
    input.form.submit();
}

// ── Accordion toggle ─────────────────────────────────────────
function dirToggle(id) {
    const body = document.getElementById(id);
    if (!body) return;
    const isOpen = body.style.display === 'block';
    body.style.display = isOpen ? 'none' : 'block';
    const header = body.previousElementSibling;
    if (header) {
        const arrow = header.querySelector('.dir-section-arrow');
        if (arrow) arrow.style.transform = isOpen ? '' : 'rotate(90deg)';
    }
    // Init pagination when first opened
    if (!isOpen && !body.__pagReady) {
        paginateGroupCards(body);
        body.__pagReady = true;
    }
}

// ── Collapsible filter (mobile) ──────────────────────────────
function dirFilterToggle(togId, bodyId) {
    const tog  = document.getElementById(togId);
    const body = document.getElementById(bodyId);
    if (!tog || !body) return;
    const collapsed = body.classList.contains('collapsed');
    body.classList.toggle('collapsed', !collapsed);
    tog.classList.toggle('open', collapsed);
}

// ── Universal paginator ──────────────────────────────────────
// Paginates an array of DOM elements inside a container, using the shared
// pmRenderPagination() button renderer (layout.js) so every list on this
// page — and every other paginated list in the app — looks/behaves the same.
function makePaginator(container, items, perPage, renderTarget, infoPrefix) {
    if (items.length <= perPage) return;
    let page = 1;
    const total = Math.ceil(items.length / perPage);

    function render() {
        const start = (page - 1) * perPage;
        items.forEach((el, i) => {
            el.style.display = (i >= start && i < start + perPage) ? '' : 'none';
        });
        const end = Math.min(start + perPage, items.length);

        let pag = container.querySelector(':scope > .dir-pag-el');
        if (!pag) {
            pag = document.createElement('div');
            pag.className = 'dir-pag-el vm-pagination';
            pag.innerHTML = '<span class="vm-page-info"></span><div class="vm-page-btns"></div>';
            container.appendChild(pag);
        }
        pag.querySelector('.vm-page-info').textContent = `${infoPrefix} ${start + 1}–${end} / ${items.length}`;
        pmRenderPagination(pag.querySelector('.vm-page-btns'), page, total, function (p) { page = p; render(); });
    }

    render();
}

// ── Paginate section (accordion) list ────────────────────────
// Groups the top-level .dir-section elements and paginates them.
function paginateSections() {
    const SECTIONS_PER_PAGE = 8;
    const wrapper = document.getElementById('dir-sections-wrapper');
    if (!wrapper) return;
    const sections = Array.from(wrapper.querySelectorAll(':scope > .dir-section'));
    if (sections.length <= SECTIONS_PER_PAGE) return;

    // Need a unique ID for the wrapper for paginator onclick
    if (!wrapper.id) wrapper.id = 'dir-sec-wrap-' + Date.now();
    makePaginator(wrapper, sections, SECTIONS_PER_PAGE, wrapper, 'Item');
}

// ── Paginate group cards within an open accordion body ───────
function paginateGroupCards(bodyEl) {
    const GROUPS_PER_PAGE = 5;
    const cards = Array.from(bodyEl.querySelectorAll(':scope > .dir-group-card'));
    if (cards.length <= GROUPS_PER_PAGE) return;
    if (!bodyEl.id) bodyEl.id = 'body-' + Math.random().toString(36).slice(2);
    makePaginator(bodyEl, cards, GROUPS_PER_PAGE, bodyEl, 'Kumpulan');
}

document.addEventListener('DOMContentLoaded', function () {
    // ── On mobile, start filters collapsed ──
    if (window.innerWidth <= 640) {
        ['fbody1','fbody2','fbody3'].forEach(function(id) {
            const el = document.getElementById(id);
            if (el) el.classList.add('collapsed');
        });
        // If active filter exists, keep filter open
        const hasFilter = location.search.match(/[&?](search|session_id|school_id|level_id|group_id|judge_id)=[^&]+/);
        if (hasFilter) {
            ['fbody1','fbody2','fbody3'].forEach(function(id) {
                const el = document.getElementById(id);
                if (el) { el.classList.remove('collapsed'); }
            });
            ['ftog1','ftog2','ftog3'].forEach(function(id) {
                const el = document.getElementById(id);
                if (el) el.classList.add('open');
            });
        }
    }

    // ── Do NOT auto-open any accordion sections ──
    // (previously there was auto-expand for single-section lists — removed)

    // ── Paginate the top-level sections list ──
    paginateSections();

    // ── Auto-submit for text inputs (debounced) ──
    let typingTimer;
    document.querySelectorAll('.pm-auto-input').forEach(function(input) {
        input.addEventListener('input', function() {
            clearTimeout(typingTimer);
            typingTimer = setTimeout(() => { this.form.submit(); }, 600);
        });
    });
});
