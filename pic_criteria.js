// pic_criteria.js — the PIC "Kriteria Pemarkahan" (test criteria) page
// (pic_criteria.php). Split out of that file's inline <script> block, which
// had nothing to do with the PHP rendering logic around it. No PHP
// interpolation here, so this is a plain file move, no bootstrap-data
// object needed. Relies on jQuery/Select2, already loaded by this page
// before this script tag.

let _t = null;
let _dirtyRows = new Set();
let _orderDirty = false;

// Cache the server-rendered "Semua Ujian" option list (all ujian, all
// peringkat) so it can be restored when the Peringkat filter is cleared.
const _origUjianFilterHTML = document.getElementById('f_test').innerHTML;

function ajaxFilter() {
    clearTimeout(_t);
    _t = setTimeout(_load, 400);
}

// ── Nested Peringkat → Ujian filter: picking a Peringkat narrows the
// Ujian dropdown to just that peringkat's ujian instead of the full list. ──
function onLevelFilterChange() {
    const levelId = document.getElementById('f_level').value;
    const testSel = $('#f_test');
    if (!levelId) {
        testSel[0].innerHTML = _origUjianFilterHTML;
        testSel.val('').trigger('change');
        ajaxFilter();
        return;
    }
    pmFetch('pic_criteria.php?ajax_tests_for_level=1&level=' + encodeURIComponent(levelId))
        .then(r => r.json())
        .then(list => {
            testSel.empty().append('<option value="">-- Semua Ujian --</option>');
            list.forEach(t => testSel.append(new Option(t.test_name, t.test_id)));
            testSel.val('').trigger('change');
            ajaxFilter();
        })
        .catch(() => {});
}

function _load() {
    const params = new URLSearchParams({
        ajax: '1',
        search: document.getElementById('f_search').value,
        level: document.getElementById('f_level').value,
        test: document.getElementById('f_test').value,
        siri: document.getElementById('f_siri').value,
        session: document.getElementById('f_session').value
    });

    const wrapper = document.getElementById('criteriaList');
    const spinner = document.getElementById('ajaxSpinner');

    wrapper.style.display = 'none';
    spinner.style.display = 'block';

    pmFetch('pic_criteria.php?' + params)
        .then(r => r.text())
        .then(html => {
            wrapper.innerHTML = html;
            wrapper.style.display = 'block';
            spinner.style.display = 'none';
            _dirtyRows.clear();
            _orderDirty = false;
            updateDirtyBar();
            attachListeners();
            criteriaCurrentPage = 1;
            updateCriteriaPagination();
            fitCriteriaListHeight();
            restoreOpenAccordions();
        })
        .catch(() => {
            wrapper.style.display = 'block';
            spinner.style.display = 'none';
        });
}

// ── Add several criteria names to a test in one submission ──
function addRow(containerId, inputName, placeholder) {
    const container = document.getElementById(containerId);
    const row = document.createElement('div');
    row.className = 'add-row';
    const input = document.createElement('input');
    input.name = inputName;
    input.placeholder = placeholder;
    input.required = true;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'add-row-btn add-row-remove';
    btn.title = 'Buang baris';
    btn.innerHTML = '&times;';
    btn.onclick = () => removeAddRow(btn);
    row.appendChild(input);
    row.appendChild(btn);
    container.appendChild(row);
    input.focus();
}

function removeAddRow(btn) {
    const container = btn.closest('[id^="addRows_"]');
    const row = btn.closest('.add-row');
    if (container && container.querySelectorAll('.add-row').length > 1) {
        row.remove();
    } else if (row) {
        const field = row.querySelector('input, select');
        if (field) field.value = '';
    }
}

// ── Keep the accordion(s) the PIC had open across a save/add/delete
// redirect instead of snapping everything back to collapsed. ──
const PM_OPEN_KEY = 'pm_criteria_open_accordions';

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

// ── PAGINATION (client-side, 20 accordions per page) ──
let criteriaCurrentPage = 1;
const criteriaPerPage = 20;

function updateCriteriaPagination() {
    const cards = Array.from(document.querySelectorAll('#criteriaList > .accordion-card'));
    const container = document.getElementById('criteriaPaginationContainer');
    const info = document.getElementById('criteriaPageInfo');
    const btns = document.getElementById('criteriaPaginationButtons');

    if (cards.length === 0) { container.style.display = 'none'; return; }

    const total = cards.length;
    const totalPages = Math.max(1, Math.ceil(total / criteriaPerPage));
    if (criteriaCurrentPage > totalPages) criteriaCurrentPage = totalPages;
    if (criteriaCurrentPage < 1) criteriaCurrentPage = 1;

    container.style.display = totalPages <= 1 ? 'none' : 'flex';

    const start = (criteriaCurrentPage - 1) * criteriaPerPage;
    const end   = start + criteriaPerPage;
    cards.forEach((c, i) => { c.style.display = (i >= start && i < end) ? '' : 'none'; });

    const s = start + 1;
    const e = Math.min(end, total);
    info.innerHTML = `Memaparkan <b>${s}–${e}</b> daripada <b>${total}</b> peringkat`;

    pmRenderPagination(btns, criteriaCurrentPage, totalPages, criteriaGoToPage);
}

function criteriaGoToPage(page) {
    criteriaCurrentPage = page;
    updateCriteriaPagination();
}

// ── Fit the criteria list + pagination into the viewport, no page scroll ──
function fitCriteriaListHeight() {
    const scrollEl = document.getElementById('criteriaList');
    const pagination = document.getElementById('criteriaPaginationContainer');
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
window.addEventListener('resize', fitCriteriaListHeight);

function attachListeners() {
    document.querySelectorAll('.criteria-input').forEach(el => {
        if (el.dataset.listening) return;
        el.dataset.listening = '1';
        el.addEventListener('input', onInputChange);
    });
    attachDragListeners();
}

// ── Drag-and-drop reordering (per test tbody) ──
let _dragRow = null;

function attachDragListeners() {
    document.querySelectorAll('.tests-table tbody tr[draggable="true"]').forEach(row => {
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

function submitSaveAll() {
    rememberOpenAccordions();
    const form = document.getElementById('saveAllForm');
    form.querySelectorAll('.dyn-input').forEach(el => el.remove());
    document.querySelectorAll('.criteria-input').forEach(el => {
        const h = document.createElement('input');
        h.type = 'hidden';
        h.name = el.name;
        h.value = el.value;
        h.className = 'dyn-input';
        form.appendChild(h);
    });
    if (_orderDirty) {
        document.querySelectorAll('.tests-table').forEach(table => {
            const tid = table.dataset.test;
            table.querySelectorAll('tbody tr[draggable="true"]').forEach((row, i) => {
                const h = document.createElement('input');
                h.type = 'hidden';
                h.name = `order[${tid}][${i}]`;
                h.value = row.dataset.id;
                h.className = 'dyn-input';
                form.appendChild(h);
            });
        });
    }
    form.submit();
}

function discardAll() {
    document.querySelectorAll('.criteria-input').forEach(el => {
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
    if (!confirm('Padam kriteria ini?')) return;
    rememberOpenAccordions();
    document.getElementById('delFrm').querySelector('[name=criteria_id]').value = id;
    document.getElementById('delFrm').submit();
}

function toggleAddCard() {
    const el = document.getElementById('addCriteria');
    el.style.display = (el.style.display === 'block') ? 'none' : 'block';
}

function toggleBlock(id, headerEl) {
    const bodyEl = document.getElementById(id);
    const iconEl = headerEl.querySelector('.acc-icon');
    if (bodyEl) {
        if (bodyEl.style.display === 'none' || bodyEl.style.display === '') {
            bodyEl.style.display = 'block';
            if (iconEl) iconEl.style.transform = 'rotate(90deg)';
            requestAnimationFrame(() => {
                bodyEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            });
        } else {
            bodyEl.style.display = 'none';
            if (iconEl) iconEl.style.transform = 'rotate(0deg)';
        }
    }
}

// Custom Dropdown Functions
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
        if(m) any = true;
    });
    if(empty) empty.style.display = any ? 'none' : 'block';
}

function ddSelect(name, value, label) {
    if (name === 'level' || name === 'test') {
        document.getElementById('f_' + name).value = value;
    }
    const lbl = document.getElementById('ddLabel_' + name);
    lbl.textContent = label;
    lbl.style.color = value === '' ? 'var(--c-text-faint)' : '';
    document.querySelectorAll('#ddOpts_' + name + ' .dd-opt').forEach(o => o.classList.toggle('selected', o.dataset.value === value));
    document.getElementById('ddPanel_' + name).classList.remove('open');
    document.getElementById('ddTrigger_' + name).classList.remove('open');
    ajaxFilter();
}

function onSiriChange() {
    const siriId = document.getElementById('f_siri').value;
    const sessSel = $('#f_session');
    sessSel.empty().append('<option value="">-- Semua Sidang --</option>');
    if (!siriId) { sessSel.trigger('change'); ajaxFilter(); return; }
    pmFetch('pic_criteria.php?ajax_sessions=1&siri=' + encodeURIComponent(siriId))
        .then(r => r.json())
        .then(list => {
            list.forEach(s => sessSel.append(new Option(s.session_name, s.session_id)));
            sessSel.trigger('change');
            ajaxFilter();
        })
        .catch(() => {});
}

// ── Tambah Kriteria form: cascading "Pilih Ujian" rows scoped to the
// chosen Peringkat — one native dropdown per row, "+ Tambah Ujian" adds
// another so the same criteria names can be added to several ujian at
// once. ──
let _ujianOptionsCache = [];

function loadUjianForPeringkat(levelId) {
    _ujianOptionsCache = [];
    if (!levelId) {
        refreshUjianSelects();
        return;
    }
    pmFetch('pic_criteria.php?ajax_tests_for_level=1&level=' + encodeURIComponent(levelId))
        .then(r => r.json())
        .then(list => {
            _ujianOptionsCache = list;
            refreshUjianSelects();
        })
        .catch(() => {});
}

// $select is a jQuery-wrapped <select> — kept as Select2 the whole time so
// every "Pilih Ujian" row looks identical to "Pilih Peringkat" instead of
// falling back to an unstyled native dropdown.
function populateUjianSelect($select, selectedValue) {
    $select.empty();
    if (_ujianOptionsCache.length === 0) {
        $select.append(new Option('-- Pilih peringkat dahulu --', ''));
        $select.prop('disabled', true);
    } else {
        $select.prop('disabled', false);
        $select.append(new Option('-- Pilih Ujian --', ''));
        _ujianOptionsCache.forEach(t => {
            $select.append(new Option(t.test_name, t.test_id, false, String(t.test_id) === String(selectedValue)));
        });
    }
    $select.trigger('change'); // refresh the Select2 UI to match the new options
}

function refreshUjianSelects() {
    $('.addUjianSelect').each(function () {
        populateUjianSelect($(this), $(this).val());
    });
}

let _addUjianRowSeq = 1;

function addUjianRow() {
    const container = document.getElementById('addRows_ujian');
    const row = document.createElement('div');
    row.className = 'add-row';
    const select = document.createElement('select');
    select.name = 'test_id[]';
    select.id = 'addUjianSelect_' + (_addUjianRowSeq++);
    select.className = 'addUjianSelect select-search';
    select.required = true;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'add-row-btn add-row-remove';
    btn.title = 'Buang baris';
    btn.innerHTML = '&times;';
    btn.onclick = () => removeAddRow(btn);
    row.appendChild(select);
    row.appendChild(btn);
    container.appendChild(row);
    $(select).select2({ width: '100%', matcher: alwaysShowAll });
    populateUjianSelect($(select), '');
}

function ddSearchInput(val) {
    const lbl = document.getElementById('ddLabel_search');
    lbl.textContent = val || '-- Semua Kriteria --';
    lbl.style.color = val ? '' : 'var(--c-text-faint)';
    ajaxFilter();
}

document.addEventListener('click', e => {
    if (!e.target.closest('.dd-wrap')) {
        document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
        document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
    }
});

// Custom Matcher: Ensures the empty/default option is always visible during
// search. Global scope (not just inside $(document).ready) so dynamically
// added rows (addUjianRow) can initialize Select2 with the same matcher.
function alwaysShowAll(params, data) {
    // If there's no search term, return all data
    if ($.trim(params.term) === '') {
        return data;
    }
    // If this is the "Semua" option (value is empty string), NEVER hide it
    if (data.id === '') {
        return data;
    }
    // Otherwise, do the standard text matching
    if (data.text.toLowerCase().indexOf(params.term.toLowerCase()) > -1) {
        return data;
    }
    // No match
    return null;
}

$(document).ready(function() {
    // Initialize Select2 with the custom matcher
    $('.select-search').select2({
        width: '100%',
        matcher: alwaysShowAll
    });

    // Bind Select2 changes to your ajaxFilter function for ALL filters
    $('#f_level, #f_test, #f_search, #f_session').on('select2:select', function (e) {
        ajaxFilter();
    });
});

document.addEventListener('DOMContentLoaded', _load);
