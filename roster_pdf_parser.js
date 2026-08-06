// ── Shared PDF roster-list parser (pdf.js based) ────────────────────────────
// Used by both pic_roster_check.php (compare a PDF roster against registered
// students) and upload_students.php (import students straight from a PDF
// roster). Keeping this in one file means a parsing fix (e.g. handling a
// name that wraps onto a second physical line inside a table cell) benefits
// both pages instead of silently drifting apart between two copies.
//
// Expects window.pdfjsLib to already be loaded (each page includes its own
// <script src=".../pdf.min.js"> before this file, same as before).

// Reconstructs visual lines from pdf.js's flat text-item list by grouping
// items with (roughly) the same baseline y, then reading them left-to-right
// — the content stream order pdf.js hands back doesn't reliably match
// visual reading order for table layouts, but position does.
async function pmRosterExtractLines(pdf) {
    const lines = [];
    for (let p = 1; p <= pdf.numPages; p++) {
        const page = await pdf.getPage(p);
        const content = await page.getTextContent();

        // Cluster items into visual lines by y, with a tolerance — exact
        // rounding split real single rows apart when two runs in the same
        // row differed by a fraction of a point (a common PDF-table quirk,
        // e.g. the row number and the name cell baseline not being pixel-
        // identical), which then corrupted the peringkat/kumpulan parsing.
        const items = content.items
            .map(item => ({ x: item.transform[4], y: item.transform[5], w: item.width, str: item.str }))
            .filter(i => i.str.trim() !== '');
        items.sort((a, b) => b.y - a.y || a.x - b.x);

        const rowGroups = [];
        const yTol = 2.5;
        items.forEach(item => {
            let grp = rowGroups.find(g => Math.abs(g.y - item.y) <= yTol);
            if (!grp) { grp = { y: item.y, items: [] }; rowGroups.push(grp); }
            grp.items.push(item);
        });

        rowGroups.forEach(grp => {
            grp.items.sort((a, b) => a.x - b.x);
            let line = '';
            let prevEnd = null;
            grp.items.forEach(it => {
                const gap = prevEnd === null ? 0 : it.x - prevEnd;
                // Only insert a space when there's a real visual gap between
                // runs — otherwise two adjacent text runs that split a
                // single word (e.g. "T" + "AMAN") get glued back together
                // instead of gaining a false space.
                if (prevEnd !== null && gap > 1.2) line += ' ';
                line += it.str;
                prevEnd = it.x + (it.w || 0);
            });
            line = line.replace(/\s+/g, ' ').trim();
            if (line) lines.push(line);
        });
    }
    return lines;
}

// The PDF's Cawangan column isn't reliably one fixed string, even within a
// single file: one document read plain "TAMAN UNIVERSITI" throughout, but
// another mixed "HQ SEKOLAH MENENGAH" for most rows with a few "HQ KELAS
// DEWASA" rows — the same branch, but a session/class qualifier tacked on
// that varies. Requiring the *whole* trailing phrase to match one fixed
// sequence (matching against a full common tail, or against the PIC's
// selected school_name) misses every row whose qualifier differs from the
// majority. Instead this finds a single ANCHOR WORD — whichever token has
// the strongest agreement at some fixed distance from the end, even if
// what follows it differs row to row — and cuts from wherever that word
// appears, taking everything after it along regardless of content. "HQ"
// wins here because 100% of rows have it 3-from-the-end, beating
// "MENENGAH"/"DEWASA" which only agree ~89% one token further in.
function pmRosterDetectTailAnchor(rawNames) {
    const tokenized = rawNames.map(n => n.trim().split(/\s+/)).filter(t => t.length > 1);
    if (tokenized.length < 3) return null;
    const overallMax = Math.min(6, Math.max(...tokenized.map(t => t.length)) - 1); // cap how far back we'll look — beyond ~5 words we're into name territory, not a cawangan qualifier
    let anchor = null;
    for (let i = 1; i <= overallMax; i++) {
        const freq = new Map();
        let counted = 0;
        tokenized.forEach(t => {
            if (t.length > i) { // always leave at least one token as the actual name
                const tok = t[t.length - i].toUpperCase();
                freq.set(tok, (freq.get(tok) || 0) + 1);
                counted++;
            }
        });
        if (counted === 0) break;
        let bestTok = null, bestCount = 0;
        freq.forEach((cnt, tok) => { if (cnt > bestCount) { bestCount = cnt; bestTok = tok; } });
        const ratio = bestCount / tokenized.length;
        if (ratio < 0.5) break; // agreement collapsed — past the qualifier, into name territory
        anchor = { token: bestTok, ratio }; // keep extending; the furthest-back token with decent agreement wins
    }
    return anchor;
}

function pmRosterStripUsingAnchor(name, anchor) {
    if (!anchor) return name;
    const upper = name.toUpperCase();
    const idx = upper.lastIndexOf(anchor.token);
    if (idx <= 0) return name;
    const leftOk = upper[idx - 1] === ' ';
    const rightEnd = idx + anchor.token.length;
    const rightOk = rightEnd === upper.length || upper[rightEnd] === ' ';
    return (leftOk && rightOk) ? name.slice(0, idx).trim() : name;
}

function pmRosterNextMeaningfulLine(lines, fromIdx) {
    for (let j = fromIdx + 1; j < lines.length; j++) {
        const t = lines[j].trim();
        if (t) return t;
    }
    return null;
}

// Extracts the "BAGI CAWANGAN <name>" branch name from the document title.
// The title is a heading like any other, so on a long branch name it wraps
// across 1+ extra physical lines exactly the way a peringkat heading does —
// this reuses the same "is the *next* line a Kumpulan line" test as
// pmRosterParseGroups to know where the branch name actually ends: right
// before the first line that IS followed by "Kumpulan N", since that line
// is the real first peringkat heading and never part of the title. Capped
// at 4 joined segments as a safety net in case that never matches (e.g. an
// unexpected document layout) so this can't run away and swallow the whole
// document as "branch name".
function pmRosterExtractCawangan(lines) {
    let startIdx = -1;
    let startText = '';
    for (let i = 0; i < lines.length; i++) {
        const m = lines[i].match(/BAGI\s+CAWANGAN\s+(.*)$/i);
        if (m) { startIdx = i; startText = m[1].trim(); break; }
    }
    if (startIdx === -1) return '';

    const parts = startText ? [startText] : [];
    for (let i = startIdx + 1; i < lines.length && parts.length < 4; i++) {
        const line = lines[i].trim();
        if (!line) continue;
        const next = pmRosterNextMeaningfulLine(lines, i);
        const isHeading = next !== null && /^Kumpulan\s+\d+/i.test(next);
        if (isHeading) break; // this line is the first real peringkat heading — stop before it
        parts.push(line);
    }
    return parts.join(' ').replace(/\s+/g, ' ').trim();
}

// Splits the reconstructed lines into { peringkat, kumpulan, kumpulanNum,
// students[] } groups. A "peringkat" heading is any standalone all-caps
// line that isn't a recognized keyword/table-header, EXCEPT when it's
// actually the tail end of a long name that wrapped onto a second physical
// line inside the NAMA cell (e.g. "...BIN MOHAMMAD SHAH" / "KHALIFAH") —
// that wrap reconstructs as its own bare, all-caps, unnumbered line and
// would otherwise look exactly like a new heading. The two are told apart
// by what comes right after: every real peringkat heading in this document
// is immediately followed by its first "Kumpulan N" line, while a wrapped
// continuation is followed by more numbered rows (or the next
// Kumpulan/peringkat, but never opens one itself). So a bare line only
// counts as a heading when the next real line is "Kumpulan N"; otherwise,
// if there's already a student to attach it to, it's queued as a name
// continuation instead. "Kumpulan N" starts a new group; "N. Name ..."
// rows are students. The cawangan tail is stripped from each row's own
// first-line text in a second pass once pmRosterDetectTailAnchor has seen
// every row — continuations are appended only *after* that strip, since
// they come from a line positioned (and thus reconstructed) after the
// tail, not before it, and would otherwise get sliced off along with it.
function pmRosterParseGroups(lines) {
    const groups = [];
    let curPeringkat = '';
    let curKumpulan = '';
    let curKumpulanNum = 0;
    let curStudents = null;
    const rawEntries = [];

    function flush() {
        if (curStudents && curStudents.length) {
            groups.push({ peringkat: curPeringkat, kumpulan: curKumpulan, kumpulanNum: curKumpulanNum, students: curStudents });
        }
    }

    // Table-header row ("NO. NAMA CAWANGAN") can extract as one combined
    // line or fragments of it — match any line made up of just those
    // header words, in any order/combination, rather than a fixed list of
    // exact strings (a mismatch here used to fall through to the
    // peringkat-heading heuristic below and wipe out the real grouping).
    const headerWordRe = /^(NO\.?|NAMA|CAWANGAN)(\s+(NO\.?|NAMA|CAWANGAN))*$/i;
    for (let li = 0; li < lines.length; li++) {
        const line = lines[li].trim();
        if (!line) continue;
        if (headerWordRe.test(line)) continue;
        if (/^SENARAI NAMA PESERTA/i.test(line)) continue;

        const kumMatch = line.match(/^Kumpulan\s+(\d+)/i);
        if (kumMatch) {
            flush();
            curKumpulan = line;
            curKumpulanNum = parseInt(kumMatch[1], 10) || 0;
            curStudents = [];
            continue;
        }

        const rowMatch = line.match(/^(\d{1,3})[.\s]+(.+)$/);
        if (rowMatch) {
            const name = rowMatch[2].trim();
            if (name && curStudents) {
                const entry = { name, extra: [] };
                curStudents.push(entry);
                rawEntries.push(entry);
            }
            continue;
        }

        // Anything else, uppercase and not a table artefact, is either a
        // new peringkat heading or a wrapped-name continuation — see which
        // above. A stray continuation of the title (e.g. a wrapped
        // "SESI 1" line before the first real heading) still falls through
        // to the heading branch since there's no curStudents yet to attach
        // it to, but that's harmless: it's overwritten the moment the real
        // heading line follows, and flush() never records it since no
        // students were collected under it yet.
        if (line === line.toUpperCase() && /[A-Z]/.test(line)) {
            const next = pmRosterNextMeaningfulLine(lines, li);
            const isHeading = next !== null && /^Kumpulan\s+\d+/i.test(next);
            if (!isHeading && curStudents && curStudents.length) {
                curStudents[curStudents.length - 1].extra.push(line);
                continue;
            }
            flush();
            curPeringkat = line;
            curKumpulan = '';
            curKumpulanNum = 0;
            curStudents = [];
        }
    }
    flush();

    const anchor = pmRosterDetectTailAnchor(rawEntries.map(e => e.name));
    groups.forEach(g => {
        g.students = g.students.map(e => {
            const stripped = pmRosterStripUsingAnchor(e.name, anchor);
            return e.extra.length ? (stripped + ' ' + e.extra.join(' ')) : stripped;
        });
    });
    return groups;
}
