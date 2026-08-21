if (window['pdfjsLib']) {
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
}


// ── Cawangan searchable dropdown (matches the .dd- component used on
//    pic_groups.php/pic_view_marks.php/pic_manual_marks.php) — replaces the
//    plain native <select> that looked out of place next to the custom file
//    picker and button. rcCompare() reads #rc_school.value exactly as
//    before; it's just a hidden input now instead of a <select>. ──
function rcDdToggle() {
    const t = document.getElementById('ddTrigger_rcschool');
    const p = document.getElementById('ddPanel_rcschool');
    const open = p.classList.contains('open');
    document.querySelectorAll('.dd-panel.open').forEach(x => x.classList.remove('open'));
    document.querySelectorAll('.dd-trigger.open').forEach(x => x.classList.remove('open'));
    if (!open) {
        p.classList.add('open'); t.classList.add('open');
        setTimeout(() => p.querySelector('.dd-search-box input')?.focus(), 50);
    }
}
function rcDdFilter(val) {
    const opts = document.querySelectorAll('#ddOpts_rcschool .dd-opt');
    const empty = document.getElementById('ddEmpty_rcschool');
    let any = false;
    opts.forEach(o => {
        const m = o.textContent.toLowerCase().includes(val.toLowerCase());
        o.classList.toggle('hidden', !m);
        if (m) any = true;
    });
    if (empty) empty.style.display = any ? 'none' : 'block';
}
function rcDdSelect(value, label) {
    document.getElementById('rc_school').value = value;
    const lbl = document.getElementById('ddLabel_rcschool');
    lbl.textContent = label;
    lbl.style.color = '';
    document.querySelectorAll('#ddOpts_rcschool .dd-opt').forEach(o => o.classList.toggle('selected', o.dataset.value === value));
    document.getElementById('ddPanel_rcschool').classList.remove('open');
    document.getElementById('ddTrigger_rcschool').classList.remove('open');
}
document.addEventListener('click', e => {
    if (!e.target.closest('.dd-wrap')) {
        document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
        document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
    }
});

function rcSetStatus(msg) {
    document.getElementById('rc_status').textContent = msg;
}

function rcFileChanged() {
    const files = document.getElementById('rc_file').files;
    document.getElementById('rc_file_name').textContent = files.length ? files[0].name : 'Tiada fail dipilih';
}

// PDF line-reconstruction + peringkat/kumpulan grouping (pmRosterExtractLines,
// pmRosterParseGroups, pmRosterDetectTailAnchor, pmRosterStripUsingAnchor)
// now lives in roster_pdf_parser.js, shared with upload_students.php's PDF
// import — see that file for the full reasoning behind each step.

async function rcCompare() {
    const schoolSel = document.getElementById('rc_school');
    const schoolId = schoolSel.value;
    const fileEl = document.getElementById('rc_file');
    const resultsEl = document.getElementById('rc_results');
    resultsEl.innerHTML = '';

    if (!schoolId) { rcSetStatus('Sila pilih cawangan dahulu.'); return; }
    if (!fileEl.files.length) { rcSetStatus('Sila muat naik fail PDF dahulu.'); return; }
    if (!window['pdfjsLib']) { rcSetStatus('Ralat: pustaka PDF gagal dimuatkan. Sila semak sambungan internet.'); return; }

    const goBtn = document.getElementById('rc_go');
    goBtn.disabled = true;
    rcSetStatus('Membaca PDF...');

    try {
        const buf = await fileEl.files[0].arrayBuffer();
        const pdf = await pdfjsLib.getDocument({ data: buf }).promise;
        const lines = await pmRosterExtractLines(pdf);
        const groups = pmRosterParseGroups(lines);

        const totalNames = groups.reduce((n, g) => n + g.students.length, 0);
        if (totalNames === 0) {
            rcSetStatus('Tidak dapat mengesan sebarang nama pelajar dalam PDF ini. Pastikan format PDF sama seperti contoh rasmi.');
            goBtn.disabled = false;
            return;
        }

        rcSetStatus('Membandingkan ' + totalNames + ' nama dengan rekod sistem...');
        const resp = await pmFetch('pic_roster_check.php?ajax_compare=1', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: RC_CSRF, school_id: schoolId, groups }),
        });
        const data = await resp.json();
        goBtn.disabled = false;
        if (!data.ok) { rcSetStatus(data.msg || 'Ralat semasa membandingkan.'); return; }
        rcSetStatus('');
        rcRender(data);
    } catch (err) {
        goBtn.disabled = false;
        rcSetStatus('Ralat membaca PDF: ' + err.message);
    }
}

function rcRender(data) {
    const resultsEl = document.getElementById('rc_results');
    let found = 0, close = 0, missing = 0;
    data.groups.forEach(g => g.students.forEach(s => {
        if (s.status === 'found') found++;
        else if (s.status === 'close') close++;
        else missing++;
    }));

    let html = `<div class="rc-summary">
        <div class="rc-stat found"><div class="n">${found}</div><div class="l">Ditemui</div></div>
        <div class="rc-stat close"><div class="n">${close}</div><div class="l">Hampir Sama</div></div>
        <div class="rc-stat missing"><div class="n">${missing}</div><div class="l">Tidak Ditemui</div></div>
        <div class="rc-stat"><div class="n">${data.db_total}</div><div class="l">Jumlah Dalam Sistem</div></div>
    </div>`;

    const badgeLabel = { found: 'Ditemui', close: 'Hampir Sama', missing: 'Tiada' };
    data.groups.forEach(g => {
        html += `<div class="rc-peringkat">`;
        if (g.peringkat) html += `<div class="rc-peringkat-title">${rcEsc(g.peringkat)}</div>`;
        html += `<div class="rc-kumpulan">`;
        if (g.kumpulan) html += `<div class="rc-kumpulan-title">${rcEsc(g.kumpulan)}</div>`;
        g.students.forEach(s => {
            const note = s.status === 'close'
                ? `Sepadan dengan "${rcEsc(s.match)}" dalam sistem (${s.pct}%)`
                : (s.status === 'found' && s.match.toUpperCase() !== s.name.toUpperCase()
                    ? `Rekod sistem: "${rcEsc(s.match)}"` : '');
            html += `<div class="rc-row">
                <span class="rc-badge ${s.status}">${badgeLabel[s.status]}</span>
                <span class="name">${rcEsc(s.name)}</span>
                ${note ? `<span class="matchnote">${note}</span>` : ''}
            </div>`;
        });
        html += `</div></div>`;
    });

    if (data.unclaimed.length) {
        html += `<div class="rc-unclaimed">
            <div class="rc-unclaimed-title">Dalam sistem tetapi tidak disebut dalam PDF ini (${data.unclaimed.length}) — semak jika ini pelajar peringkat lain atau perlu disemak semula:</div>
            <ul>${data.unclaimed.map(n => `<li>${rcEsc(n)}</li>`).join('')}</ul>
        </div>`;
    }

    resultsEl.innerHTML = html;
}

function rcEsc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
