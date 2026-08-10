// upload_students.js — the student roster upload wizard
// (upload_students.php): sheet-picker toggling, the "Import" button
// enable/disable logic, and the review-stage tab switcher.
//
// Split out of that file's inline <script> block. No PHP interpolation
// here — the wizard's per-stage data (sheet lists, row mappings, etc.)
// is rendered into the DOM as real HTML/form fields by PHP, not passed
// through this script, so this is a plain file move.

if (window['pdfjsLib']) {
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
}

// ── Unified upload zone ──
// One dropzone/file input feeds two different flows depending on the chosen
// extension: .csv/.xlsx submit uploadForm straight to the server (parsed by
// SimpleXLSX), while .pdf is parsed client-side here (pdf.js +
// roster_pdf_parser.js, shared with pic_roster_check.php) so the PIC can
// confirm the detected cawangan + pick siri/sidang before pdfForm posts only
// the extracted JSON to stage=review_pdf — the file itself is never
// uploaded. Both stages build the same $import_data shape and hand off to
// the exact same review UI.
(function(){
    var inp           = document.getElementById('uploadFileInput');
    var zone           = document.getElementById('uploadZone');
    var fileInfo       = document.getElementById('fileChosen');
    var fileName       = document.getElementById('fileNameDisplay');
    var clearBtn       = document.getElementById('fileClearBtn');
    var ctaBtn         = document.getElementById('uploadCtaBtn');
    var excelSubmitWrap = document.getElementById('uploadSubmitWrap');

    var statusEl      = document.getElementById('pdfParseStatus');
    var previewWrap    = document.getElementById('pdfPreviewWrap');
    var cawanganInput  = document.getElementById('pdfCawanganInput');
    var summaryEl      = document.getElementById('pdfSummaryText');
    var dataInput      = document.getElementById('pdfDataInput');
    var pdfForm        = document.getElementById('pdfForm');

    var parsedGroups = null; // [{peringkat, kumpulan, kumpulanNum, students[]}]

    function fileExt(name) {
        var m = /\.([a-z0-9]+)$/i.exec(name || '');
        return m ? m[1].toLowerCase() : '';
    }

    function setStatus(msg, isError) {
        if (!statusEl) return;
        statusEl.textContent = msg || '';
        statusEl.style.color = isError ? '#f87171' : 'var(--c-text-faint)';
    }

    function resetPdfPreview() {
        parsedGroups = null;
        if (previewWrap)   previewWrap.style.display = 'none';
        if (cawanganInput) cawanganInput.value = '';
        if (summaryEl)     summaryEl.textContent = '';
    }

    function setFile(file) {
        if (file) {
            if (fileName) fileName.textContent = file.name;
            if (fileInfo) fileInfo.style.display = 'flex';
            if (zone)     zone.classList.add('has-file');
            if (ctaBtn)   ctaBtn.textContent = '✓ Fail Dipilih';
        } else {
            if (fileName) fileName.textContent = '—';
            if (fileInfo) fileInfo.style.display = 'none';
            if (zone)     zone.classList.remove('has-file');
            if (ctaBtn) {
                ctaBtn.innerHTML = '<svg viewBox="0 0 24 24" width="15" height="15" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Pilih Fail';
            }
            if (excelSubmitWrap) excelSubmitWrap.style.display = 'none';
            resetPdfPreview();
            setStatus('');
        }
    }

    async function parsePdf(file) {
        resetPdfPreview();
        if (!window['pdfjsLib']) { setStatus('Ralat: pustaka PDF gagal dimuatkan. Sila semak sambungan internet.', true); return; }
        setStatus('Membaca PDF...');
        try {
            const buf = await file.arrayBuffer();
            const pdf = await pdfjsLib.getDocument({ data: buf }).promise;
            const lines = await pmRosterExtractLines(pdf);
            const cawangan = pmRosterExtractCawangan(lines);
            const groups = pmRosterParseGroups(lines);
            const totalStudents = groups.reduce((n, g) => n + g.students.length, 0);

            if (totalStudents === 0) {
                setStatus('Tidak dapat mengesan sebarang nama pelajar dalam PDF ini. Pastikan format PDF sama seperti contoh rasmi.', true);
                return;
            }

            parsedGroups = groups;
            if (cawanganInput) cawanganInput.value = cawangan;
            const peringkatCount = new Set(groups.map(g => g.peringkat)).size;
            if (summaryEl) summaryEl.innerHTML = `📋 <strong style="color:var(--c-white);">${peringkatCount}</strong> peringkat &nbsp;·&nbsp; <strong style="color:var(--c-white);">${groups.length}</strong> kumpulan &nbsp;·&nbsp; <strong style="color:var(--c-white);">${totalStudents}</strong> pelajar dikesan`;
            if (!cawangan) {
                setStatus('Nama cawangan tidak dapat dikesan secara automatik daripada tajuk PDF ini — sila isikan secara manual di bawah.', true);
            } else {
                setStatus('');
            }
            if (previewWrap) previewWrap.style.display = 'block';
        } catch (err) {
            setStatus('Ralat membaca PDF: ' + err.message, true);
        }
    }

    function handleFile(file) {
        setFile(file);
        if (!file) return;
        if (fileExt(file.name) === 'pdf') {
            if (excelSubmitWrap) excelSubmitWrap.style.display = 'none';
            parsePdf(file);
        } else {
            resetPdfPreview();
            setStatus('');
            if (excelSubmitWrap) excelSubmitWrap.style.display = 'block';
        }
    }

    if (inp) {
        inp.addEventListener('change', function(){
            handleFile(this.files && this.files.length > 0 ? this.files[0] : null);
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function(e){
            e.stopPropagation();
            if (inp) inp.value = '';
            setFile(null);
        });
    }

    // Drag & drop
    if (zone) {
        ['dragenter','dragover'].forEach(function(ev){
            zone.addEventListener(ev, function(e){ e.preventDefault(); zone.classList.add('dragover'); });
        });
        ['dragleave','dragend','drop'].forEach(function(ev){
            zone.addEventListener(ev, function(e){
                e.preventDefault();
                zone.classList.remove('dragover');
                if (ev === 'drop' && e.dataTransfer.files.length > 0) {
                    inp.files = e.dataTransfer.files;
                    handleFile(e.dataTransfer.files[0]);
                }
            });
        });
    }

    var pdfSiriSel = document.getElementById('pdfSiriSelect');
    if (pdfSiriSel) pdfSiriSel.addEventListener('change', function(){ var w=document.getElementById('pdfNewSiriWrap'); if(w) w.classList.toggle('visible', this.value==='NEW'); });
    var pdfSessSel = document.getElementById('pdfSessionSelect');
    if (pdfSessSel) pdfSessSel.addEventListener('change', function(){ var w=document.getElementById('pdfNewSessionWrap'); if(w) w.classList.toggle('visible', this.value==='NEW'); });

    if (pdfForm) {
        pdfForm.addEventListener('submit', function(e){
            if (!parsedGroups || !parsedGroups.length) { e.preventDefault(); setStatus('Sila muat naik dan baca fail PDF dahulu.', true); return; }
            const cawangan = cawanganInput.value.trim();
            if (!cawangan) { e.preventDefault(); setStatus('Sila isikan nama cawangan.', true); return; }
            dataInput.value = JSON.stringify({
                cawangan: cawangan,
                groups: parsedGroups.map(function(g){
                    return { peringkat: g.peringkat, kumpulan: g.kumpulanNum, students: g.students };
                }),
            });
        });
    }

    var siriSel = document.getElementById('siriSelect');
    if (siriSel) siriSel.addEventListener('change', function(){ var w=document.getElementById('newSiriWrap'); if(w) w.classList.toggle('visible', this.value==='NEW'); });
    var sessSel = document.getElementById('sessionSelect');
    if (sessSel) sessSel.addEventListener('change', function(){ var w=document.getElementById('newSessionWrap'); if(w) w.classList.toggle('visible', this.value==='NEW'); });
})();

// ── Sheet picker toggle ──
function toggleSheet(label, sheetName) {
    var cb = label.querySelector('input[type="checkbox"]');
    if (!cb || cb.disabled) return;
    cb.checked = !cb.checked;
    label.classList.toggle('selected', cb.checked);
    updateImportBtn();
}
function updateImportBtn() {
    var btn = document.getElementById('importBtn');
    if (!btn) return;
    var checked = document.querySelectorAll('.sheet-picker-grid input[type="checkbox"]:checked');
    btn.disabled = checked.length === 0;
    btn.textContent = checked.length === 0
        ? 'Pilih sekurang-kurangnya 1 lembaran'
        : 'Semak & Sahkan Data (' + checked.length + ' lembaran) →';
}
// Init sheet cards on load
document.querySelectorAll('.sheet-card:not(.invalid)').forEach(function(card){
    var cb = card.querySelector('input[type="checkbox"]');
    if (cb && cb.checked) card.classList.add('selected');
});
updateImportBtn();

// ── Group selects: only show text input if user picks "NEW" with no preset name ──
document.querySelectorAll('.group-select').forEach(function(sel){
    var inp = document.querySelector('.new-group-input[data-idx="' + sel.dataset.idx + '"]');
    if (!inp) return; // hidden input (preset) — no text box exists
    sel.addEventListener('change', function(){
        inp.style.display = (this.value === 'NEW') ? 'block' : 'none';
        inp.required = (this.value === 'NEW');
    });
});

// ── Tabs ──
var tabBtns  = document.querySelectorAll('.pm-tab-btn');
var tabPanes = document.querySelectorAll('.pm-tab-pane');
function switchTab(id){
    tabBtns.forEach(function(b){ b.classList.toggle('active', b.dataset.target===id); });
    tabPanes.forEach(function(p){ p.classList.toggle('active', p.id===id); });
    sessionStorage.setItem('upload_students_active_tab', id);
}
tabBtns.forEach(function(b){ b.addEventListener('click', function(){ switchTab(this.dataset.target); }); });
document.querySelectorAll('.btn-next-tab').forEach(function(b){ b.addEventListener('click', function(){ switchTab(this.dataset.next); }); });
document.querySelectorAll('.btn-prev-tab').forEach(function(b){ b.addEventListener('click', function(){ switchTab(this.dataset.prev); }); });

var savedTab = sessionStorage.getItem('upload_students_active_tab');
if (savedTab && document.getElementById(savedTab)) {
    switchTab(savedTab);
}

// ── Lazy school dropdown: inject options only on first focus ──
var schoolOptionsCache = null;
document.querySelectorAll('.fast-school-dropdown').forEach(function(sel){
    sel.addEventListener('focus', function(){
        if (this.dataset.loaded) return;
        if (!schoolOptionsCache) {
            schoolOptionsCache = '';
            if (typeof masterSchools !== 'undefined') {
                for (var id in masterSchools) {
                    schoolOptionsCache += '<option value="'+id+'">→ '+masterSchools[id]+'</option>';
                }
            }
        }
        var v = this.value;
        this.insertAdjacentHTML('beforeend', schoolOptionsCache);
        this.value = v;
        this.dataset.loaded = 'true';
    });
});

// ── Lazy group dropdown: same trick — avoids duplicating every group option across 700+ rows ──
var groupOptionsCache = null;
document.querySelectorAll('.fast-group-dropdown').forEach(function(sel){
    sel.addEventListener('focus', function(){
        if (this.dataset.loaded) return;
        if (!groupOptionsCache) {
            groupOptionsCache = '';
            if (typeof masterGroups !== 'undefined') {
                for (var id in masterGroups) {
                    groupOptionsCache += '<option value="'+id+'">'+masterGroups[id]+'</option>';
                }
            }
        }
        var v = this.value;
        var newOpt = this.querySelector('option[value="NEW"]');
        if (newOpt) newOpt.insertAdjacentHTML('beforebegin', groupOptionsCache);
        else this.insertAdjacentHTML('beforeend', groupOptionsCache);
        this.value = v;
        this.dataset.loaded = 'true';
    });
});

// ── Smart pagination: render only visible rows + buffer for performance ──
(function(){
    var ROWS_PER_PAGE = 10;
    var BUFFER_ROWS = 5; // Pre-load a few extra rows
    document.querySelectorAll('.pm-table-wrap table.pm-auto-paginate').forEach(function(table){
        var tbody = table.querySelector('tbody');
        if (!tbody) return;
        var rows = Array.from(tbody.querySelectorAll('tr'));
        var total = parseInt(table.getAttribute('data-total')) || rows.length;
        if (rows.length <= ROWS_PER_PAGE) {
            // No pagination needed — just make all rendered rows visible
            for (var i = 0; i < rows.length; i++) rows[i].style.display = 'table-row';
            return;
        }

        var totalPages = Math.ceil(total / ROWS_PER_PAGE);
        var cur = 1;

        var bar = document.createElement('div');
        bar.className = 'vm-pagination';
        bar.innerHTML = '<span class="vm-page-info"></span><div class="vm-page-btns"></div>';
        table.closest('.pm-table-wrap').insertAdjacentElement('afterend', bar);
        var info = bar.querySelector('.vm-page-info');
        var btns = bar.querySelector('.vm-page-btns');

        function goToPage(p) { cur = p; render(); }

        function render(){
            var start = (cur-1)*ROWS_PER_PAGE;
            var end   = Math.min(start + ROWS_PER_PAGE + BUFFER_ROWS, rows.length);
            var displayStart = start;
            var displayEnd = Math.min(start + ROWS_PER_PAGE, rows.length);

            // Show current page + buffer, hide rest
            for (var i=0; i<rows.length; i++) {
                rows[i].style.display = (i >= start && i < end) ? 'table-row' : 'none';
            }

            info.innerHTML = 'Memaparkan <b>' + (displayStart+1) + '–' + displayEnd + '</b> daripada <b>' + total + '</b> rekod';
            pmRenderPagination(btns, cur, totalPages, goToPage);
        }
        // Pre-render first page (already hidden by CSS, pagination shows it)
        render();
    });
})();
