$(function() {
    $('.lb-filter').select2({ width: '100%' });
});

function exportExcel() {
    const table = document.getElementById('lbTable');
    const wb = XLSX.utils.table_to_book(table, { sheet: "Leaderboard" });
    XLSX.writeFile(wb, "Leaderboard_Export.xlsx");
}

function exportPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(14);
    doc.text("Papan Pendahulu — ProMarkah", 40, 38);

    const rows = leaderboardData.map(d => [
        d.rank,
        d.student,
        d.siri,
        d.session,
        d.level,
        d.judge,
        `${d.total} / ${d.max}`,
        `${d.percentage}%`,
        d.medal
    ]);

    doc.autoTable({
        head: [["#","Nama Pesilat","Siri","Sidang","Peringkat","Juri","Markah","Peratus","Pingat"]],
        body: rows,
        startY: 52,
        styles: { fontSize: 8, halign: 'center', cellPadding: 5,
                  fillColor: [24,24,24], textColor: [255,255,255],
                  lineColor: [60,60,60], lineWidth: 0.5 },
        headStyles: { fillColor: [214,40,40], textColor: [255,255,255], fontStyle: 'bold' },
        alternateRowStyles: { fillColor: [35,35,35] },
        columnStyles: { 1: { halign: 'left' }, 4: { halign: 'left' } },
    });
    doc.save('Leaderboard_Export.pdf');
}
