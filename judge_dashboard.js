// judge_dashboard.js — the judge.php dashboard/session-selection screen:
// jQuery AJAX session-expiry handling, URL cleanup, the "Kemajuan
// Pemarkahan" accordion + live search, the live clock, and the
// Tahun/Siri/Sidang/Peringkat/Kumpulan cascade dropdowns.
//
// Split out of judge.php's inline <script> blocks since none of this reads
// a PHP-templated value directly — the two lines that used to be wrapped in
// <?php if (...) ?> now read window.pmJudgeDashboardData instead (set by a
// tiny inline snippet right before this file's <script src> tag in
// judge.php). The marking-table logic (draft autosave, keypad, add-ujian
// modal) lives in the separate judge_marking.js, only loaded once a group
// is actually being marked.

// The Sidang/Peringkat/Kumpulan dropdowns are filled via $.get() calls
// back to this same page. If the judge's session has quietly expired
// (15 min idle), auth_check.php now answers those with a 401 instead of
// redirecting — catch that here, once, for every AJAX call on this page,
// rather than each dropdown silently rendering empty with no explanation
// and the judge having no idea why until they refresh.
let pmSessionExpiredShown = false;
$(document).ajaxError(function (event, jqXHR) {
    if (jqXHR.status !== 401 || pmSessionExpiredShown) return;
    let sessionExpired = false;
    try { sessionExpired = JSON.parse(jqXHR.responseText || '{}').error === 'session_expired'; } catch (e) {}
    if (!sessionExpired) return;
    pmSessionExpiredShown = true;
    alert('Sesi anda telah tamat kerana tiada aktiviti. Sila log masuk semula.');
    window.location.href = 'login.php';
});

if (window.history.replaceState) {
    const url = new URL(window.location.href);
    url.searchParams.delete('status');
    url.searchParams.delete('msg');
    window.history.replaceState({path: url.href}, '', url.href);
}

// Global criteria data (populated after marking form renders — see judge_marking.js)
let criteriaByTest = {};

// ACCORDION UI LOGIC
function toggleSummaryAccordion(id) {
    const content = document.getElementById('content_' + id);
    const arrow = document.getElementById('arrow_' + id);
    const header = document.getElementById('header_' + id);

    if (content.style.display === 'block') {
        content.style.display = 'none';
        arrow.style.transform = 'rotate(0deg)';
        header.classList.remove('active');
    } else {
        content.style.display = 'block';
        arrow.style.transform = 'rotate(90deg)';
        header.classList.add('active');
    }
}

// LIVE SEARCH LOGIC FOR ACCORDION
document.getElementById('summarySearchInput').addEventListener('keyup', function () {
    const keyword = this.value.toLowerCase();
    const blocks = document.querySelectorAll('.pm-accordion-block');

    blocks.forEach(block => {
        let hasMatch = false;
        const sessionName = block.getAttribute('data-session');
        const rows = block.querySelectorAll('.summary-row');

        // Match at the Session name level
        const sessionMatch = sessionName.includes(keyword);

        rows.forEach(row => {
            const rowText = row.textContent.toLowerCase();
            if (rowText.includes(keyword) || sessionMatch) {
                row.style.display = '';
                hasMatch = true;
            } else {
                row.style.display = 'none';
            }
        });

        const content = block.querySelector('.pm-accordion-content');
        const arrow = block.querySelector('.pm-accordion-arrow');
        const header = block.querySelector('.pm-accordion-header');

        if (hasMatch) {
            block.style.display = '';
            // Auto expand if actively searching
            if (keyword.length > 0) {
                content.style.display = 'block';
                arrow.style.transform = 'rotate(90deg)';
                header.classList.add('active');
            } else {
                // Collapse back when search is empty
                content.style.display = 'none';
                arrow.style.transform = 'rotate(0deg)';
                header.classList.remove('active');
            }
        } else {
            block.style.display = 'none';
        }
    });
});

$(document).ready(function () {
    // Live Clock & Date
    function updateDateTime() {
        const now = new Date();
        const timeEl = document.getElementById('liveTime');
        const dateEl = document.getElementById('liveDate');

        if (timeEl) {
            timeEl.textContent = now.toLocaleTimeString('ms-MY', { hour: '2-digit', minute: '2-digit' });
        }
        if (dateEl) {
            dateEl.textContent = now.toLocaleDateString('ms-MY', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
        }
    }

    updateDateTime(); // Run immediately on load
    setInterval(updateDateTime, 1000);

    // Initialize Select2 — #groupSelect is deliberately NOT in this
    // batch: it gets its own .select2() call below with a
    // templateResult. Calling .select2() twice on the same element
    // (once here, once below) silently drops the second call's config
    // in some Select2 versions, which is why the italic "Belum
    // Ditetapkan" style previously stopped applying.
    const select2Fields = ['#sessionSelect', '#levelSelect'];
    if (window.pmJudgeDashboardData?.showYearDropdown) select2Fields.push('#yearSelect');
    if (window.pmJudgeDashboardData?.showSiriDropdown) select2Fields.push('#siriSelect');
    // minimumResultsForSearch: -1 removes the search box entirely — these
    // lists are short so search isn't needed, and without a search input
    // Select2 has nothing to auto-focus, so opening the dropdown no
    // longer pops the on-screen keyboard on mobile.
    $(select2Fields.join(',')).select2({ width: '100%', minimumResultsForSearch: -1 });

    $('#groupSelect').select2({
        width: '100%',
        minimumResultsForSearch: -1,
        templateResult: function(opt) {
            if (!opt.id) return opt.text;
            const el = opt.element;
            const span = document.createElement('span');
            span.textContent = opt.text;
            if (el && el.dataset.unassigned === '1') {
                span.style.color = 'var(--c-text-muted)';
                span.style.fontStyle = 'italic';
            } else if (el && el.dataset.ownedOther === '1') {
                span.style.color = 'var(--c-red)';
                span.style.opacity = '0.7';
                span.style.textDecoration = 'line-through';
            }
            return span;
        }
    });

    // Year → filter Siri options
    $('#yearSelect').on('change', function () {
        const year = $(this).val();
        const siriSelect = $('#siriSelect');
        siriSelect.find('option[data-year]').each(function () {
            $(this).toggle(!year || $(this).data('year') == year);
        });
        siriSelect.val('').trigger('change.select2');
        $('#sessionSelect').empty().append('<option value="">-- Pilih Siri Dahulu --</option>').trigger('change.select2');
        $('#levelSelect').empty().append('<option value="">-- Sila Pilih Sidang Dahulu --</option>').trigger('change.select2');
        $('#groupSelect').empty().append('<option value="">-- Sila Pilih Peringkat Dahulu --</option>').trigger('change.select2');
    });

    // Siri → Sessions
    $('#siriSelect').on('change', function () {
        const siriId = $(this).val();
        const sessionSelect = $('#sessionSelect');
        sessionSelect.empty().append('<option value="">Memuatkan...</option>').trigger('change.select2');
        $('#levelSelect').empty().append('<option value="">-- Sila Pilih Sidang Dahulu --</option>').trigger('change.select2');
        $('#groupSelect').empty().append('<option value="">-- Sila Pilih Peringkat Dahulu --</option>').trigger('change.select2');
        if (!siriId) {
            sessionSelect.empty().append('<option value="">-- Pilih Siri Dahulu --</option>').trigger('change.select2');
            return;
        }
        // Update the hidden siri_id input if no visible siri dropdown
        $('input[name="siri_id"]').val(siriId);
        $.get('judge.php?ajax_sessions=1&siri_id=' + encodeURIComponent(siriId), function (html) {
            sessionSelect.empty().append('<option value="">-- Pilih Sidang --</option>' + html).trigger('change.select2');
        });
    });

    // Session → Levels
    $('#sessionSelect').on('change', function () {
        const sessionId = $(this).val();
        const levelSelect = $('#levelSelect');
        levelSelect.empty().append('<option value="">Memuatkan...</option>').trigger('change.select2');
        $('#groupSelect').empty().append('<option value="">-- Sila Pilih Peringkat Dahulu --</option>').trigger('change.select2');
        if (!sessionId) {
            levelSelect.empty().append('<option value="">-- Pilih Sidang Dahulu --</option>').trigger('change.select2');
            return;
        }
        $.get('judge.php?ajax_levels=1&session_id=' + encodeURIComponent(sessionId), function (html) {
            levelSelect.empty().append('<option value="">-- Pilih Peringkat --</option>' + html).trigger('change.select2');
        });
    });

    // Level → Groups
    $('#levelSelect').on('change', function () {
        const levelId = $(this).val();
        const groupSelect = $('#groupSelect');
        groupSelect.empty().append('<option value="">Memuatkan...</option>').trigger('change.select2');
        if (!levelId) {
            groupSelect.empty().append('<option value="">-- Pilih Peringkat Dahulu --</option>').trigger('change.select2');
            return;
        }
        $.get('judge.php?ajax_groups=1&level_id=' + encodeURIComponent(levelId), function (html) {
            groupSelect.empty().append('<option value="">-- Pilih Kumpulan --</option>' + html).trigger('change.select2');
        });
    });

}); // <-- ONLY ONE CLOSING BRACKET FOR DOCUMENT.READY

// Marking UI Logic
document.addEventListener('change', function (e) {
    // 1. Handle Dropdown Value Change
    if (e.target.classList.contains('mark-select')) {
        const select = e.target;
        const { student, criteria } = select.dataset;

        // Update or create the hidden input that actually submits the data
        let inp = document.getElementById(`mark_${student}_${criteria}`);
        if (!inp) {
            inp = document.createElement('input'); inp.type = 'hidden';
            inp.id = `mark_${student}_${criteria}`; inp.name = `marks[${student}][${criteria}]`;
            document.getElementById('markForm').appendChild(inp);
        }
        inp.value = select.value;
        updateTotals(student);
    }

    // 2. Handle the "Nilai" Checkbox Toggle
    if (e.target.classList.contains('enable-marking')) {
        const cb = e.target;
        const wrapper = document.getElementById(`keypad_${cb.dataset.student}_${cb.dataset.criteria}`);
        const select = document.getElementById(`select_${cb.dataset.student}_${cb.dataset.criteria}`);
        const inp = document.getElementById(`mark_${cb.dataset.student}_${cb.dataset.criteria}`);

        wrapper.style.display = cb.checked ? 'block' : 'none';
        if (!cb.checked) {
            if (select) select.value = '';
            if (inp) inp.value = '';
        }
        updateTotals(cb.dataset.student);
    }
});

function updateTotals(studentId) {
    let overallSum = 0, overallMax = 0;
    for (const testName in criteriaByTest) {
        let testSum = 0, testMax = 0;
        Object.keys(criteriaByTest[testName]).forEach(cid => {
            const cb = document.querySelector(`.enable-marking[data-student='${studentId}'][data-criteria='${cid}']`);
            if (!cb || cb.checked) {
                testMax += 10;
                // Look for the dropdown value instead of the selected button
                const sel = document.querySelector(`.mark-select[data-student='${studentId}'][data-criteria='${cid}']`);
                if (sel && sel.value !== '') testSum += parseInt(sel.value);
            }
        });
        const cell = document.querySelector(`.test-total[data-student='${studentId}'][data-test='${testName}']`);
        if (cell) cell.innerHTML = `${testSum} / ${testMax}`;
        overallSum += testSum; overallMax += testMax;
    }
    const oCell = document.querySelector(`.overall-total[data-student='${studentId}']`);
    if (oCell) oCell.innerHTML = `${overallSum} / ${overallMax}`;
}
