// ── Searchable dropdown logic (matches PIC pages, e.g. pic_view_marks.php) ──
function ddToggle(ddName) {
    const trigger = document.getElementById('ddTrigger_' + ddName);
    if (trigger.classList.contains('dd-trigger-disabled')) return;
    const panel = document.getElementById('ddPanel_' + ddName);
    const isOpen = panel.classList.contains('open');
    document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
    document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
    if (!isOpen) {
        panel.classList.add('open'); trigger.classList.add('open');
        setTimeout(() => panel.querySelector('.dd-search-box input')?.focus(), 50);
    }
}

function ddFilter(ddName, val) {
    const opts = document.querySelectorAll('#ddOpts_' + ddName + ' .dd-opt');
    const empty = document.getElementById('ddEmpty_' + ddName);
    let any = false;
    opts.forEach(o => {
        const m = o.textContent.toLowerCase().includes(val.toLowerCase());
        o.classList.toggle('hidden', !m);
        if (m) any = true;
    });
    if (empty) empty.style.display = any ? 'none' : 'block';
}

function ddSelect(ddName, value, label) {
    document.getElementById(ddName).value = value;
    const lbl = document.getElementById('ddLabel_' + ddName);
    lbl.textContent = label;
    lbl.style.color = value === '' ? 'var(--c-text-faint)' : '';
    document.querySelectorAll('#ddOpts_' + ddName + ' .dd-opt').forEach(o => o.classList.toggle('selected', o.dataset.value === value));
    document.getElementById('ddPanel_' + ddName).classList.remove('open');
    document.getElementById('ddTrigger_' + ddName).classList.remove('open');
    sbCascade(ddName);
}

document.addEventListener('click', e => {
    if (!e.target.closest('.dd-wrap')) {
        document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
        document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
    }
});

// ── Group-wide row hover ──
// The Tahun/Siri/Sidang/Peringkat cell is merged (rowspan) into only the
// first <tr> of its group, so plain CSS :hover on a later row in that group
// can never reach it — it's simply not part of that row's DOM. Every row
// carries data-group="<index of the group's first row>"; hovering any one
// of them toggles the highlight class on all rows sharing that value.
(function () {
    const table = document.getElementById('silibusTable');
    if (!table) return;
    table.querySelectorAll('tbody tr[data-group]').forEach(tr => {
        const groupRows = table.querySelectorAll('tbody tr[data-group="' + tr.dataset.group + '"]');
        tr.addEventListener('mouseenter', () => groupRows.forEach(r => r.classList.add('silibus-row-hover')));
        tr.addEventListener('mouseleave', () => groupRows.forEach(r => r.classList.remove('silibus-row-hover')));
    });
})();

// Each of the 6 filters is independent — picking one no longer clears or
// gates any of the others. Just resubmit with whatever's currently selected.
function sbCascade(changedId) {
    document.getElementById('silibus-filter-form').submit();
}

// Full filtered dataset from PHP (ignores pagination — exports everything the filters matched, not just the current page)

function getSilibusExportRows() {
    return silibusExportData.map(r => [r.year, r.siri, r.sidang, r.tahap, r.ujian, r.kriteria]);
}

function exportSilibusExcel() {
    const headers = ["Tahun", "Siri", "Sidang", "Peringkat (Tahap)", "Nama Ujian", "Kriteria / Deskripsi"];
    const rows = getSilibusExportRows();
    const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Silibus");
    XLSX.writeFile(wb, "Silibus_Export.xlsx");
}

function exportSilibusPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(14);
    doc.text("Senarai Silibus Mengikut Sidang dan Tahap — ProMarkah", 40, 38);

    const rows = getSilibusExportRows().map(row => {
        const kriteria = row[5]
            .split('\n')
            .map(line => line.trim())
            .filter(line => line !== '')
            .map(line => '•  ' + line)
            .join('\n');
        return [...row.slice(0, 5), kriteria];
    });
    const isDark = !document.documentElement.classList.contains('pm-light');

    doc.autoTable({
        head: [["Tahun", "Siri", "Sidang", "Peringkat (Tahap)", "Nama Ujian", "Kriteria / Deskripsi"]],
        body: rows,
        startY: 52,
        rowPageBreak: 'avoid',
        styles: { fontSize: 8, cellPadding: 5, lineHeightFactor: 1.6,
                  fillColor: isDark ? [24,24,24] : [255,255,255],
                  textColor: isDark ? [255,255,255] : [30,30,30],
                  lineColor: isDark ? [60,60,60] : [220,220,220],
                  lineWidth: 0.5 },
        headStyles: { fillColor: [214,40,40], textColor: [255,255,255], fontStyle: 'bold' },
        alternateRowStyles: { fillColor: isDark ? [35,35,35] : [245,245,245] },
        columnStyles: { 5: { cellWidth: 260 } },
    });

    doc.setProperties({ title: 'SILIBUS_EXPORT' });

    // ── IN-PAGE PREVIEW (bypasses popup blockers — same pattern as leaderboard.php) ──
    const pdfBlob = doc.output('blob', { type: 'application/pdf' });
    const pdfUrl = URL.createObjectURL(pdfBlob);

    const overlay = document.createElement('div');
    overlay.style.cssText = 'position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.85); z-index: 9999; display: flex; flex-direction: column;';

    const topBar = document.createElement('div');
    topBar.style.cssText = 'width: 100%; padding: 12px 24px; background: #1f1f1f; display: flex; justify-content: space-between; align-items: center; box-sizing: border-box;';

    const titleSpan = document.createElement('span');
    titleSpan.innerText = 'SILIBUS_EXPORT.pdf';
    titleSpan.style.cssText = 'color: white; font-family: "DM Sans", sans-serif; font-weight: bold; letter-spacing: 0.05em; font-size: 0.9rem;';

    const closeBtn = document.createElement('button');
    closeBtn.innerText = '✖ Tutup Preview';
    closeBtn.style.cssText = 'background: var(--c-red); color: white; border: none; padding: 8px 16px; border-radius: 4px; font-weight: bold; cursor: pointer; font-family: "DM Sans", sans-serif; font-size: 0.85rem;';
    closeBtn.onclick = () => {
        document.body.removeChild(overlay);
        URL.revokeObjectURL(pdfUrl);
    };

    topBar.appendChild(titleSpan);
    topBar.appendChild(closeBtn);

    const iframe = document.createElement('iframe');
    iframe.src = pdfUrl;
    iframe.style.cssText = 'width: 100%; flex-grow: 1; border: none;';

    overlay.appendChild(topBar);
    overlay.appendChild(iframe);
    document.body.appendChild(overlay);
}
