// leaderboard.js — the "Papan Pendahulu" leaderboard page (leaderboard.php).
// Split out of that file's inline <script> block, which had nothing to do
// with the PHP rendering logic around it. No PHP interpolation here, so
// this is a plain file move — relies on the global `leaderboardData`
// array, set by a small inline bootstrap <script> (window.leaderboardData
// = <?= json_encode($leaderboard_data) ?>) that stays in leaderboard.php,
// same pattern as pic_groups.js's PM_GROUPS_CSRF.

function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, function(c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

// Maps a Peringkat name to its belt colour key, same keyword convention
// as silibus_tahap_key() in silibus.php ("... Cula <Colour> <N>") — kept
// in sync so a level's divider band and its Silibus row tint agree.
function levelTahapKey(levelName) {
    const needle = String(levelName ?? '').toLowerCase();
    if (needle.includes('hijau'))  return 'hijau';
    if (needle.includes('merah'))  return 'merah';
    if (needle.includes('kuning')) return 'kuning';
    if (needle.includes('hitam'))  return 'hitam';
    return null;
}

// ── Quota source badge labels ──────────────────────────────────
const quotaBadge = {
    school:  '<span class="quota-badge school"  title="Kuota ditetapkan mengikut sekolah">🏫 Sekolah</span>',
    level:   '<span class="quota-badge level"   title="Kuota ditetapkan mengikut peringkat">🏆 Peringkat</span>',
    session: '<span class="quota-badge session" title="Kuota ditetapkan mengikut sidang">📅 Sidang</span>',
    overall: '<span class="quota-badge overall" title="Kuota global keseluruhan">🌐 Global</span>',
    auto:    ''
};

// ── Populate filter dropdowns — rebuildable so a live refresh can pick
//    up newly-appeared years/siris/schools/etc without losing the
//    student's current selection. ──────────────────────────────
function populateFilterOptions() {
    const defs = [
        ['filter-year',    'Semua Tahun',     d => d.year,    (a, b) => String(b).localeCompare(String(a), undefined, { numeric: true })],
        ['filter-siri',    'Semua Siri',      d => d.siri,    (a, b) => a.localeCompare(b, undefined, { numeric: true })],
        ['filter-session', 'Semua Sidang',    d => d.session, (a, b) => a.localeCompare(b, undefined, { numeric: true })],
        ['filter-level',   'Semua Peringkat', d => d.level,   (a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' })],
        ['filter-school',  'Semua Cawangan',  d => d.school,  (a, b) => a.localeCompare(b, undefined, { numeric: true })],
        ['filter-judge',   'Semua Juri',      d => d.judge,   (a, b) => a.localeCompare(b, undefined, { numeric: true })],
    ];

    defs.forEach(([id, emptyLabel, getter, sorter]) => {
        const el = document.getElementById(id);

        // Don't rebuild a dropdown the user currently has open — replacing
        // its <option> list out from under Select2 mid-interaction is what
        // caused clicks to intermittently miss or close the dropdown on
        // the 3s live-refresh poll.
        const s2 = $(el).data('select2');
        if (s2 && s2.isOpen()) return;

        const current = el.value;
        const values = [...new Set(leaderboardData.map(getter))].filter(Boolean).sort(sorter);

        // Skip the DOM rebuild (and the Select2 resync below) entirely if
        // the option list hasn't actually changed — avoids needless churn
        // on every poll tick, which was another source of the same race.
        const existing = [...el.options].slice(1).map(o => o.value);
        const unchanged = existing.length === values.length && existing.every((v, i) => v === values[i]);
        if (unchanged) return;

        el.innerHTML = `<option value="">${emptyLabel}</option>` +
            values.map(v => `<option value="${escapeHtml(v)}">${escapeHtml(v)}</option>`).join('');

        // Keep the user's selection if that value still exists in the refreshed data
        if (current && values.includes(current)) el.value = current;

        if (s2) $(el).trigger('change.select2');
    });
}

populateFilterOptions();

// ── Init Select2 then wire instant filtering ───────────────────
$(function() {
    $('.lb-filter').select2({ width: '100%' });
    $('.lb-filter').on('change', applyFilters);
    applyFilters();
    startLiveRefresh();
});

// ── Main render ───────────────────────────────────────────────
function applyFilters() {
    // Preserve vertical scroll position across re-renders (important
    // for the live auto-refresh, so it doesn't yank the view back to top).
    // On desktop the table scrolls internally (fitLeaderboardHeight caps
    // its height), so wrap.scrollTop is what matters. On mobile that cap
    // is removed and the list scrolls with the whole page instead — so
    // it's window.scrollY that actually needs preserving there. Save
    // both; whichever one is the real scroll container gets restored.
    const prevWrap = document.querySelector('#leaderboard-tables .pm-table-wrap');
    const prevScrollTop = prevWrap ? prevWrap.scrollTop : 0;
    const prevWindowScrollY = window.scrollY;

    const fy    = document.getElementById('filter-year').value.toLowerCase();
    const fsiri = document.getElementById('filter-siri').value.toLowerCase();
    const fs    = document.getElementById('filter-session').value.toLowerCase();
    const fl    = document.getElementById('filter-level').value.toLowerCase();
    const fsch  = document.getElementById('filter-school').value.toLowerCase();
    const fj    = document.getElementById('filter-judge').value.toLowerCase();
    const fm    = document.getElementById('filter-medal').value.toLowerCase();

    // A specific Cawangan is selected — scope medal/nisbah to that
    // school's own entrants per Peringkat instead of the cross-school
    // ranking (medal_school/quota_source_school, computed server-side).
    const scoped = fsch !== '';
    const medalOf = d => scoped ? d.medal_school : d.medal;
    const sourceOf = d => scoped ? d.quota_source_school : d.quota_source;

    const filtered = leaderboardData.filter(d =>
        (fy    === '' || String(d.year).toLowerCase() === fy) &&
        (fsiri === '' || d.siri.toLowerCase() === fsiri) &&
        (fs    === '' || d.session.toLowerCase() === fs) &&
        (fl    === '' || d.level.toLowerCase()   === fl) &&
        (fsch  === '' || d.school.toLowerCase()  === fsch) &&
        (fj    === '' || d.judge.toLowerCase()   === fj) &&
        (fm    === '' || medalOf(d).toLowerCase() === fm)
    );

    // Group by Peringkat first (medals are assigned per-Peringkat), then sort
    // by percentage within each Peringkat — so display order always matches
    // the medal ranking instead of interleaving separate Peringkat competitions.
    filtered.sort((a, b) => {
        if (a.level !== b.level) return a.level.localeCompare(b.level);
        return b.percentage - a.percentage;
    });

    // Group by level for showing quota source badge per level header
    const levelSources = {};
    filtered.forEach(d => { if (!levelSources[d.level]) levelSources[d.level] = sourceOf(d); });

    let html = `
    <div class="pm-card" style="padding:0; overflow:hidden;">
        <div style="padding:16px 22px; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--c-border);">
            <h3 style="margin:0; font-family:'Bebas Neue',sans-serif; font-size:1.5rem; letter-spacing:0.05em; color:var(--c-white);">Senarai Keputusan</h3>
            <span style="color:#fff; font-weight:600; background:var(--c-red); padding:4px 12px; border-radius:20px; font-size:0.85rem; box-shadow:0 2px 8px rgba(214,40,40,0.4);">${filtered.length} Rekod</span>
        </div>
        <div class="pm-table-wrap" style="border:none; border-radius:0;">
            <table class="pm-table" id="lbTable">
                <thead>
                    <tr>
                        <th style="text-align:center;">Ked.</th>
                        <th style="text-align:left;">Nama Pesilat</th>
                        <th style="text-align:left;">Tahun</th>
                        <th style="text-align:left;">Siri</th>
                        <th style="text-align:left;">Sidang</th>
                        <th style="text-align:left;">Cawangan</th>
                        <th style="text-align:left;">Peringkat</th>
                        <th style="text-align:left;">Juri</th>
                        <th style="text-align:left;">Markah</th>
                        <th style="text-align:left;">Peratus</th>
                        <th style="text-align:center;">Pingat</th>
                    </tr>
                </thead>
                <tbody>`;

    if (filtered.length === 0) {
        html += `<tr><td colspan="11"><div class="pm-empty-state">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
            <circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
            <path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <p>Tiada rekod ditemui untuk tapisan ini.</p></div></td></tr>`;
    } else {
        let rankCounter = 0;
        let prevLevel = null;
        filtered.forEach((s, i) => {
            const isNewLevel = s.level !== prevLevel;
            rankCounter = isNewLevel ? 1 : rankCounter + 1;

            if (isNewLevel) {
                const tahapKey = levelTahapKey(s.level);
                const tahapCls = tahapKey ? ` tahap-${tahapKey}` : '';
                html += `
                <tr class="level-divider-row${tahapCls}">
                        <td></td>
                        <td colspan="10">
                        <span class="level-divider-label">
                            <svg class="level-divider-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="15" r="6"></circle>
                                <path d="M9 10.5 7 3h2l3 6 3-6h2l-2 7.5"></path>
                            </svg>
                            ${escapeHtml(s.level)}
                        </span>
                    </td>
                </tr>`;
            }
            prevLevel = s.level;

            const src = levelSources[s.level] || 'auto';
            const badge = quotaBadge[src] || '';
            const medal = medalOf(s);
            const rowClass = medal.includes('Emas') ? 'gold-row' : medal.includes('Perak') ? 'silver-row' : 'bronze-row';

            html += `
            <tr class="${rowClass}">
                <td style="text-align:justify; font-weight:bold; color:var(--c-text-faint);">${rankCounter}</td>
                <td class="lb-truncate" style="text-align:justify; font-weight:600; font-size:0.9rem; color:var(--c-text);" title="${escapeHtml(s.student)}">${escapeHtml(s.student)}</td>
                <td class="lb-truncate" style="text-align:justify; color:var(--c-text-muted);">${escapeHtml(s.year)}</td>
                <td class="lb-truncate" style="text-align:justify; color:var(--c-text);" title="${escapeHtml(s.siri)}">${escapeHtml(s.siri)}</td>
                <td class="lb-truncate" style="text-align:justify; color:var(--c-text);" title="${escapeHtml(s.session)}">${escapeHtml(s.session)}</td>
                <td class="lb-truncate" style="text-align:justify; color:var(--c-text);" title="${escapeHtml(s.school)}">${escapeHtml(s.school)}</td>

                <td class="lb-truncate" style="text-align:justify; color:var(--c-text);" title="${escapeHtml(s.level)}">${escapeHtml(s.level)}</td>

                <td class="lb-truncate" style="text-align:justify; color:var(--c-text-muted);" title="${escapeHtml(s.judge)}">${escapeHtml(s.judge)}</td>
                <td style="text-align:justify; font-family:monospace; font-size:1.05rem; font-weight:bold; color:var(--c-text-muted);">${s.total} / ${s.max}</td>
                <td style="text-align:justify; font-weight:700; color:var(--c-red);">${s.percentage}%</td>

                <td style="text-align:justify;" class="medal-cell">
                    <div style="display: flex; flex-direction: column; align-items: center; gap: 4px;">
                        <span>${escapeHtml(medal)}</span>
                        ${badge}
                    </div>
                </td>
            </tr>`;
        });
    }

    html += `</tbody></table></div></div>`;
    document.getElementById('leaderboard-tables').innerHTML = html;
    fitLeaderboardHeight();

    const newWrap = document.querySelector('#leaderboard-tables .pm-table-wrap');
    if (newWrap) newWrap.scrollTop = prevScrollTop;
    window.scrollTo(0, prevWindowScrollY);
}

// ── Live refresh: AJAX-poll the same endpoint used for the initial
//    load, the same pattern used for notifications elsewhere in the
//    app (safe on shared hosting — no websockets needed). ──────────
// Exponential backoff on failure (same rationale as layout.php's
// notification poller): stays at 3s while healthy, doubles (capped at
// 60s) per consecutive failure, resets to 3s on the next success —
// so a DB hiccup doesn't turn into indefinite full-speed polling.
const LB_BASE_DELAY = 3000;
const LB_MAX_DELAY  = 60000;
let lbFailCount = 0;
let lbTimer = null;

// Rebuilding the table mid-scroll (DOM replacement + the scroll-position
// restore in applyFilters) kills an in-progress touch/momentum scroll —
// it feels like scrolling randomly "stops". Track whether the user is
// actively scrolling (page or the table's own internal scroll box) and
// just skip that poll's re-render entirely while they are; the next
// 3s tick picks it up once they've stopped. {capture:true} on document
// catches scroll events from the inner .pm-table-wrap too, since scroll
// events don't bubble but do fire during the capture phase.
let lbScrolling = false;
let lbScrollEndTimer = null;
document.addEventListener('scroll', () => {
    lbScrolling = true;
    clearTimeout(lbScrollEndTimer);
    lbScrollEndTimer = setTimeout(() => { lbScrolling = false; }, 250);
}, { passive: true, capture: true });
document.addEventListener('touchmove', () => {
    lbScrolling = true;
    clearTimeout(lbScrollEndTimer);
    lbScrollEndTimer = setTimeout(() => { lbScrolling = false; }, 250);
}, { passive: true });

function fetchLeaderboard() {
    pmFetch('leaderboard.php?ajax=1')
        .then(res => {
            if (!res.ok) throw new Error('Network response was not ok');
            return res.json();
        })
        .then(data => {
            lbFailCount = 0;
            if (!Array.isArray(data)) return;
            leaderboardData = data;
            if (lbScrolling) return; // don't touch the DOM while the user is scrolling
            populateFilterOptions();
            applyFilters();
        })
        .catch(() => {
            // Silently ignore — try again on the next poll
            lbFailCount++;
        })
        .finally(() => {
            const delay = Math.min(LB_BASE_DELAY * Math.pow(2, lbFailCount), LB_MAX_DELAY);
            lbTimer = setTimeout(fetchLeaderboard, delay);
        });
}

function startLiveRefresh() {
    lbTimer = setTimeout(fetchLeaderboard, LB_BASE_DELAY);
}

// Pause polling while the tab/screen isn't visible (e.g. minimized
// display), resume immediately (with an instant refresh) when it is.
document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        if (lbTimer) clearTimeout(lbTimer);
    } else {
        if (lbTimer) clearTimeout(lbTimer);
        fetchLeaderboard();
    }
});

// ── Fit the table's scroll container to the remaining viewport height,
//    so the page never needs to scroll — only the table body does. ──
function fitLeaderboardHeight() {
    const wrap = document.querySelector('#leaderboard-tables .pm-table-wrap');
    if (!wrap) return;

    // On mobile, don't cap the table into its own internally-scrolling
    // box — let it flow as a normal long list and scroll with the page.
    if (window.innerWidth <= 768) {
        wrap.style.maxHeight = 'none';
    } else {
        const top = wrap.getBoundingClientRect().top;
        const available = window.innerHeight - top - 24; // bottom breathing room
        wrap.style.maxHeight = Math.max(200, available) + 'px';
    }

    // Match the divider row's sticky offset to the real header height,
    // instead of a guessed constant — avoids the jitter/gap when they
    // don't line up exactly.
    const headRow = wrap.querySelector('thead tr');
    if (headRow) {
        wrap.style.setProperty('--lb-header-h', headRow.getBoundingClientRect().height + 'px');
    }
}
window.addEventListener('resize', fitLeaderboardHeight);

// ── EXPORT FUNCTIONS ──────────────────────────────────────────────
function exportToExcel() {
    const table = document.getElementById('lbTable');
    if (!table) return alert("Tiada data untuk dieksport.");

    const wb = XLSX.utils.table_to_book(table, { sheet: "Papan Pendahulu" });
    XLSX.writeFile(wb, "Papan_Pendahulu_ProMarkah.xlsx");
}

function exportToPDF(orientation = 'landscape') {
    const table = document.getElementById('lbTable');
    if (!table) return alert("Tiada data untuk dieksport.");

    const { jsPDF } = window.jspdf;
    const isLandscape = orientation === 'landscape';
    const doc = new jsPDF({ orientation: orientation, unit: 'pt', format: 'a4' });

    // Build dynamic filename based on active filters
    const fYear = document.getElementById('filter-year').value;
    const fSiri = document.getElementById('filter-siri').value;
    const fSession = document.getElementById('filter-session').value;
    const fLevel = document.getElementById('filter-level').value;
    const fMedal = document.getElementById('filter-medal').value;

    let nameParts = ["PAPAN_KEDUDUKAN"];
    if (fSiri) nameParts.push(fSiri);
    if (fSession) nameParts.push(fSession);
    if (fLevel) nameParts.push(fLevel);
    if (fMedal) nameParts.push(fMedal);
    if (fYear) nameParts.push(fYear);

    let baseFileName = nameParts.join('_').toUpperCase().replace(/[^A-Z0-9_]/g, '_');
    doc.setProperties({ title: baseFileName });

    const pageWidth = doc.internal.pageSize.width;

    // --- 1. SLEEK HEADER SECTION ---
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(isLandscape ? 18 : 15);
    doc.setTextColor(30, 30, 30);
    doc.text("PAPAN KEDUDUKAN KESELURUHAN", 40, 45);

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(10);
    doc.setTextColor(120, 120, 120);
    doc.text("PROMARKAH — SISTEM PENILAIAN SILAT", 40, 60);

    const today = new Date();
    const dateString = "Dijana pada: " + today.toLocaleDateString('ms-MY') + " " + today.toLocaleTimeString('ms-MY');
    doc.setFontSize(8);
    doc.setTextColor(150, 150, 150);
    doc.text(dateString, pageWidth - 40, 60, { align: 'right' });

    doc.setDrawColor(214, 40, 40);
    doc.setLineWidth(1.5);
    doc.line(40, 68, pageWidth - 40, 68);

    // --- 2. TABLE GENERATION ---

    // Solid accent color for every Peringkat section-divider row —
    // matches the site's red brand color, bold fill + white text, so it
    // reads unmistakably as a divider instead of blending in as a plain
    // white/pale row.
    const dividerColor = { fill: [214, 40, 40], text: [255, 255, 255] };
    const dividerColorFor = () => dividerColor;

    // Root-cause fix: `data.row.raw` is NOT the original <tr> DOM element
    // in this jspdf-autotable version (verified empirically — it has no
    // .classList), so checking its class name here always silently
    // returned false. That's why every previous attempt at coloring
    // this row (pastel-per-level, then solid red) never actually showed
    // up — didDrawCell's paint code was correct, it just never ran.
    // colSpan is a reliable structural signal instead: only the
    // divider's label cell has colSpan > 1 (it spans columns 1–10), and
    // its leading spacer cell (column 0) is the only Rank-column cell
    // that's ever empty — every real data row always has a rank number.
    const isDividerCell = (data) =>
        data.section === 'body' &&
        ((data.cell.colSpan && data.cell.colSpan > 1) ||
         (data.column.index === 0 && data.cell.raw && !data.cell.raw.textContent.trim()));

    // jsPDF's built-in fonts (helvetica/times/courier) use WinAnsi
    // encoding and simply cannot render emoji glyphs — that's why the
    // 🥇/🥈/🥉 medal icons were being stripped out entirely before
    // drawing. A <canvas> CAN render color emoji (browsers fall back to
    // the system emoji font automatically), so rasterize each one once
    // to a small PNG and place that as an image instead of text. Cached
    // per emoji+size so each unique medal is only rasterized once.
    const emojiImageCache = {};
    function emojiToImage(emoji, px) {
        const key = emoji + '@' + px;
        if (emojiImageCache[key]) return emojiImageCache[key];
        const canvas = document.createElement('canvas');
        canvas.width = px;
        canvas.height = px;
        const ctx = canvas.getContext('2d');
        ctx.font = Math.round(px * 0.82) + 'px sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(emoji, px / 2, px / 2 + px * 0.04);
        const url = canvas.toDataURL('image/png');
        emojiImageCache[key] = url;
        return url;
    }

    const EMOJI_RE = /[\u{1F000}-\u{1FFFF}\u{2600}-\u{27BF}\u{2B00}-\u{2BFF}\u{FE0F}]/gu;

    // The Pingat column ("🥇 Emas" etc.) — column index 10, only real
    // data rows (not the divider). Drawn manually in didDrawCell below,
    // same reasoning as the divider: full control over layout so the
    // word can never get force-wrapped ("GANG"/"SA") the way autoTable's
    // own linebreak was doing at this column's width.
    const isPingatCell = (data) =>
        data.section === 'body' && data.column.index === 10 && !isDividerCell(data);

    doc.autoTable({
        html: '#lbTable',
        startY: 85,
        // A wrapped multi-line cell (e.g. a long Cawangan/Peringkat
        // value) could otherwise get sliced in half by a page break —
        // half its lines on one page, the rest continuing alone at the
        // top of the next. 'avoid' keeps a row's lines together, moving
        // the whole row to the next page instead of splitting it.
        rowPageBreak: 'avoid',
        didParseCell: function(data) {
            if (data.cell.text && typeof data.cell.text[0] === 'string') {
                // Strip emoji only (jsPDF's built-in helvetica font can't
                // render them — e.g. the 🥇/🥈/🥉 medal icons) — NOT the
                // old [^\x00-\x7F] blanket strip, which also deleted
                // legitimate Latin-1 punctuation like en dashes ("Pulai
                // Mutiara – Sesi 13" was silently losing its "–").
                // jsPDF's standard fonts use WinAnsi encoding, which
                // covers Latin-1 supplement (accents, dashes, curly
                // quotes) fine — only actual emoji need removing.
                let cleanText = data.cell.text[0]
                    .replace(EMOJI_RE, '')
                    .trim();
                data.cell.text[0] = cleanText.toUpperCase();
            }
            // Blank the divider/Pingat cells' own text here — both get
            // redrawn manually in didDrawCell below instead (see that
            // hook for why: the default 'striped' theme reapplies its
            // own alternating-row fill/text color AFTER didParseCell
            // runs, silently overwriting any custom color set here).
            if (isDividerCell(data) || isPingatCell(data)) {
                data.cell.text = [''];
            }
        },
        // Runs after ALL of autoTable's own rendering for this cell
        // (including the theme's striped-row coloring), so painting
        // here is guaranteed to be the last thing drawn — nothing can
        // overwrite it afterward the way didParseCell's styles were.
        didDrawCell: function(data) {
            if (isDividerCell(data)) {
                // Uppercase to match every other cell in the table (see
                // didParseCell above) — this custom-drawn label bypasses
                // that step since its text comes straight from the DOM,
                // not from data.cell.text.
                const label = (data.cell.raw && data.cell.raw.textContent || '').trim().toUpperCase();
                const color = dividerColorFor(label);
                doc.setFillColor(color.fill[0], color.fill[1], color.fill[2]);
                doc.rect(data.cell.x, data.cell.y, data.cell.width, data.cell.height, 'F');
                doc.setFont('helvetica', 'bold');
                doc.setFontSize(isLandscape ? 8 : 7);
                doc.setTextColor(color.text[0], color.text[1], color.text[2]);
                // Centered to match every other cell in the table (base
                // styles below use halign:'center'/valign:'middle') —
                // this custom-drawn label bypassed that since it's drawn
                // manually rather than through autoTable's own text layout.
                doc.text(label, data.cell.x + data.cell.width / 2, data.cell.y + data.cell.height / 2, { align: 'center', baseline: 'middle' });
                return;
            }

            if (isPingatCell(data)) {
                // Drawn as one manual, non-wrapping line (icon image +
                // word, centered as a group) instead of letting
                // autoTable's own linebreak wrap "GANGSA" mid-word.
                const rawText = (data.cell.raw && data.cell.raw.textContent || '').trim();
                const emojiMatch = rawText.match(EMOJI_RE);
                const word = rawText.replace(EMOJI_RE, '').trim().toUpperCase();

                doc.setFont('helvetica', 'bold');
                doc.setFontSize(isLandscape ? 8 : 7);
                doc.setTextColor(50, 50, 50);

                const iconSize = isLandscape ? 9 : 8;
                const gap = 3;
                const textWidth = doc.getTextWidth(word);
                const groupWidth = (emojiMatch ? iconSize + gap : 0) + textWidth;
                const cy = data.cell.y + data.cell.height / 2;
                let curX = data.cell.x + data.cell.width / 2 - groupWidth / 2;

                if (emojiMatch) {
                    doc.addImage(emojiToImage(emojiMatch[0], 64), 'PNG', curX, cy - iconSize / 2, iconSize, iconSize);
                    curX += iconSize + gap;
                }
                doc.text(word, curX, cy, { baseline: 'middle' });
                return;
            }
        },
        styles: {
            font: 'helvetica',
            fontSize: isLandscape ? 8 : 7,
            cellPadding: isLandscape ? 6 : 4,
            textColor: [50, 50, 50],
            lineColor: [235, 235, 235],
            lineWidth: { bottom: 0.5, top: 0, left: 0, right: 0 },
            valign: 'middle', // Vertically centers text
            halign: 'center', // Horizontally centers ALL text (fixes the messy left-aligned wrapping)
            overflow: 'linebreak' // Allows long names to wrap to the next line naturally
        },
        headStyles: {
            fillColor: [35, 35, 35],
            textColor: [255, 255, 255],
            fontStyle: 'bold',
            halign: 'center',
            lineWidth: 0,
            // Headers are single short words (RANK, SIRI, SIDANG...) — at
            // portrait's tighter column widths the normal 'linebreak'
            // wrap was breaking mid-word ("RAN"/"K", "SIDAN"/"G") since
            // there's no space to wrap at. A smaller, non-wrapping
            // header style keeps every header on one line instead.
            fontSize: isLandscape ? 8 : 6.5,
            overflow: 'visible'
        },
        alternateRowStyles: {
            fillColor: [252, 252, 252]
        },
        // Every column gets an explicit cellWidth (none left as 'auto')
        // so the total always fits within the printable page width —
        // Cawangan/Peringkat/Juri being left to autoTable's natural
        // 'auto' sizing let a single long value blow the table wider
        // than the page, which autoTable "fixes" by continuing the
        // overflowing columns onto a second page instead of wrapping
        // them. Pinned widths + overflow:'linebreak' (in styles above)
        // means long values wrap onto extra lines within their own
        // column instead of spilling onto another page.
        columnStyles: isLandscape ? {
            // Landscape usable width ≈ 762pt (842pt A4 landscape − 40pt
            // margins each side). These sum to 750pt. Cawangan/Peringkat/
            // Juri are sized generously (~20+ chars at this font size)
            // specifically so a single long unbroken word (e.g. a judge's
            // full name like "HAIRUNORFADZLINA") doesn't get force-split
            // mid-word — that space comes out of the short fixed-format
            // columns (Tahun/Siri/Sidang/Markah/Peratus/Pingat), which
            // never need more than a few characters.
            0:  { cellWidth: 28,  fontStyle: 'bold', textColor: [100, 100, 100] },
            1:  { cellWidth: 125 }, // Nama Pesilat
            2:  { cellWidth: 32  }, // Tahun
            3:  { cellWidth: 42  }, // Siri
            4:  { cellWidth: 46  }, // Sidang
            5:  { cellWidth: 95  }, // Cawangan
            6:  { cellWidth: 140 }, // Peringkat
            7:  { cellWidth: 114 }, // Juri
            8:  { cellWidth: 46, halign: 'center', fontStyle: 'bold' },
            9:  { cellWidth: 42, halign: 'center', fontStyle: 'bold', textColor: [214, 40, 40] },
            10: { cellWidth: 50, halign: 'center', fontStyle: 'bold' } // Pingat — icon+word needs a bit more room
        } : {
            // Portrait usable width ≈ 515pt (595pt A4 portrait − 40pt
            // margins each side). These sum to 500pt — same rationale
            // as landscape above, scaled down.
            0:  { cellWidth: 20,  fontStyle: 'bold', textColor: [100, 100, 100] },
            1:  { cellWidth: 70  }, // Nama Pesilat
            2:  { cellWidth: 24  }, // Tahun
            3:  { cellWidth: 30  }, // Siri
            4:  { cellWidth: 34  }, // Sidang
            5:  { cellWidth: 55  }, // Cawangan
            6:  { cellWidth: 90  }, // Peringkat
            7:  { cellWidth: 85  }, // Juri
            8:  { cellWidth: 32, halign: 'center', fontStyle: 'bold' },  // Markah
            9:  { cellWidth: 30, halign: 'center', fontStyle: 'bold', textColor: [214, 40, 40] }, // Peratus
            10: { cellWidth: 38, halign: 'center', fontStyle: 'bold' }   // Pingat — icon+word needs a bit more room
        },
        margin: { top: 85, left: 40, right: 40, bottom: 40 }
    });

    // --- 3. OUTPUT ---
    const pdfBlob = doc.output('blob', { type: 'application/pdf' });
    const pdfUrl = URL.createObjectURL(pdfBlob);

    // Mobile browsers (Chrome/Safari on Android/iOS) don't render a
    // blob: PDF inside an <iframe> the way desktop Chrome does — instead
    // of a real preview they show a bare "download this file" card with
    // a UUID filename and an "Open" button that has no viewer to hand
    // off to, so it silently does nothing. Skip the iframe there and
    // just trigger a real download instead, which mobile browsers
    // handle correctly (saved to Downloads, opens with whatever PDF
    // app/viewer the device has).
    const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
    if (isMobile) {
        const a = document.createElement('a');
        a.href = pdfUrl;
        a.download = baseFileName + '.pdf';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(() => URL.revokeObjectURL(pdfUrl), 10000);
        return;
    }

    // Create full-screen overlay
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.85); z-index: 9999; display: flex; flex-direction: column;';

    // Create top bar with close button
    const topBar = document.createElement('div');
    topBar.style.cssText = 'width: 100%; padding: 12px 24px; background: #1f1f1f; display: flex; justify-content: space-between; align-items: center; box-sizing: border-box;';

    const titleSpan = document.createElement('span');
    titleSpan.innerText = baseFileName + ".pdf";
    titleSpan.style.cssText = 'color: white; font-family: "DM Sans", sans-serif; font-weight: bold; letter-spacing: 0.05em; font-size: 0.9rem;';

    const closeBtn = document.createElement('button');
    closeBtn.innerText = '✖ Tutup Preview';
    closeBtn.style.cssText = 'background: var(--c-red); color: white; border: none; padding: 8px 16px; border-radius: 4px; font-weight: bold; cursor: pointer; font-family: "DM Sans", sans-serif; font-size: 0.85rem;';
    closeBtn.onclick = () => {
        document.body.removeChild(overlay);
        URL.revokeObjectURL(pdfUrl); // Clean up memory
    };

    topBar.appendChild(titleSpan);
    topBar.appendChild(closeBtn);

    // Create iframe to render PDF natively
    const iframe = document.createElement('iframe');
    iframe.src = pdfUrl;
    iframe.style.cssText = 'width: 100%; flex-grow: 1; border: none;';

    overlay.appendChild(topBar);
    overlay.appendChild(iframe);
    document.body.appendChild(overlay);
}
