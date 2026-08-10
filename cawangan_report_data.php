<?php
// ── Shared data-fetching for the Ringkasan Cawangan report + its 3 exports.
// Not directly web-accessible (blocked in .htaccess, same as
// security_bootstrap/db/auth_check) — must be included AFTER
// session_start()/auth_check()/db.php by the caller, which is expected to
// already have a live $conn.

function pm_cawangan_report_title(mysqli $conn, int $active_siri): string {
    $siri_name = '';
    $siri_year = '';
    if ($active_siri > 0) {
        $stmt = $conn->prepare("SELECT siri_name, siri_year FROM siri WHERE siri_id = ?");
        $stmt->bind_param("i", $active_siri);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            $siri_name = $row['siri_name'];
            $siri_year = $row['siri_year'];
        }
        $stmt->close();
    }
    $title = "UJIAN KENAIKAN TALI PINGGANG PERINGKAT CAWANGAN";
    if ($siri_name !== '') $title .= " " . strtoupper($siri_name) . "/" . $siri_year;
    return $title;
}

// Turns the report title into a safe download filename — e.g. "SERI 1/2026"
// would otherwise put a literal "/" in the filename, which breaks on both
// Windows and as an HTTP header value.
function pm_cawangan_report_filename(string $title, string $ext): string {
    $safe = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $title);
    $safe = preg_replace('/\s+/', '_', trim($safe));
    return $safe . '.' . $ext;
}

// Peringkat/level names don't sort usefully alphabetically (e.g. "HITAM 1"
// would land before "MERAH 1"). Belt-test order is fixed: Hijau, then
// Merah, then Kuning, then Hitam, ascending by the trailing number within
// each colour. Anything that doesn't match a known colour sorts after all
// of them, alphabetically, rather than disappearing or erroring.
function pm_peringkat_sort_key(string $level_name): array {
    static $colourOrder = ['HIJAU' => 1, 'MERAH' => 2, 'KUNING' => 3, 'HITAM' => 4];
    $upper = strtoupper($level_name);
    $colourRank = 99;
    foreach ($colourOrder as $colour => $rank) {
        if (strpos($upper, $colour) !== false) {
            $colourRank = $rank;
            break;
        }
    }
    preg_match('/(\d+)/', $upper, $m);
    $num = isset($m[1]) ? (int)$m[1] : 0;
    return [$colourRank, $num, $upper];
}

// Returns rows1..rows4 — none of these join/filter on `scores` at all, so a
// cawangan/student with zero marks entered still appears in every table.
function pm_cawangan_report_data(mysqli $conn, int $active_siri): array {
    $where = ["1=1"];
    $types = "";
    $vals  = [];
    if ($active_siri > 0) {
        $where[] = "l.session_id IN (SELECT session_id FROM sessions WHERE siri_id = ?)";
        $types  .= "i";
        $vals[]  = $active_siri;
    }
    $whereSql = implode(" AND ", $where);

    $run = function (string $sql) use ($conn, $types, $vals) {
        if ($vals) {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$vals);
            $stmt->execute();
            return $stmt->get_result();
        }
        return $conn->query($sql);
    };

    // Jadual 1 — Senarai Cawangan: just the distinct branch list.
    $res1 = $run("
        SELECT DISTINCT sc.school_id, sc.school_name
        FROM schools sc
        JOIN students st       ON st.school_id = sc.school_id
        JOIN group_students gs ON gs.student_id = st.student_id
        JOIN `groups` g        ON gs.group_id = g.group_id
        JOIN levels l          ON g.level_id = l.level_id
        WHERE $whereSql
        ORDER BY sc.school_name
    ");

    // Jadual 2 — Senarai Pelajar Mengikut Cawangan: every student in a
    // group, one row each.
    $res2 = $run("
        SELECT DISTINCT sc.school_name, st.student_id, st.student_name
        FROM schools sc
        JOIN students st       ON st.school_id = sc.school_id
        JOIN group_students gs ON gs.student_id = st.student_id
        JOIN `groups` g        ON gs.group_id = g.group_id
        JOIN levels l          ON g.level_id = l.level_id
        WHERE $whereSql
        ORDER BY sc.school_name, st.student_name
    ");

    // Jadual 3 — Peringkat Mengikut Cawangan: fetched as flat
    // (cawangan, peringkat) rows, then folded below into one entry per
    // cawangan carrying a LIST of peringkat (rendered as a list, not a
    // comma-joined paragraph, by the caller).
    $res3 = $run("
        SELECT DISTINCT sc.school_id, sc.school_name, l.level_name
        FROM schools sc
        JOIN students st       ON st.school_id = sc.school_id
        JOIN group_students gs ON gs.student_id = st.student_id
        JOIN `groups` g        ON gs.group_id = g.group_id
        JOIN levels l          ON g.level_id = l.level_id
        WHERE $whereSql
        ORDER BY sc.school_name, l.level_name
    ");

    // Jadual 4 — Peringkat Setiap Pelajar: one row per student per
    // peringkat. A student entered in two groups/peringkat (dual-category)
    // correctly gets two rows here.
    $res4 = $run("
        SELECT DISTINCT sc.school_name, st.student_id, st.student_name, l.level_name
        FROM schools sc
        JOIN students st       ON st.school_id = sc.school_id
        JOIN group_students gs ON gs.student_id = st.student_id
        JOIN `groups` g        ON gs.group_id = g.group_id
        JOIN levels l          ON g.level_id = l.level_id
        WHERE $whereSql
        ORDER BY sc.school_name, st.student_name, l.level_name
    ");

    $rows1 = []; while ($r = $res1->fetch_assoc()) $rows1[] = $r;
    $rows2 = []; while ($r = $res2->fetch_assoc()) $rows2[] = $r;

    $rows3_grouped = [];
    while ($r = $res3->fetch_assoc()) {
        $sid = $r['school_id'];
        if (!isset($rows3_grouped[$sid])) {
            $rows3_grouped[$sid] = ['school_name' => $r['school_name'], 'peringkat' => []];
        }
        $rows3_grouped[$sid]['peringkat'][] = $r['level_name'];
    }
    foreach ($rows3_grouped as &$grp) {
        usort($grp['peringkat'], fn($a, $b) => pm_peringkat_sort_key($a) <=> pm_peringkat_sort_key($b));
    }
    unset($grp);
    $rows3 = array_values($rows3_grouped);

    // Belt-colour order (Hijau→Merah→Kuning→Hitam, ascending by number)
    // within each cawangan, not the SQL's alphabetical level_name order.
    $rows4 = []; while ($r = $res4->fetch_assoc()) $rows4[] = $r;
    usort($rows4, function ($a, $b) {
        if ($a['school_name'] !== $b['school_name']) return strcmp($a['school_name'], $b['school_name']);
        $cmp = pm_peringkat_sort_key($a['level_name']) <=> pm_peringkat_sort_key($b['level_name']);
        return $cmp !== 0 ? $cmp : strcmp($a['student_name'], $b['student_name']);
    });

    return ['rows1' => $rows1, 'rows2' => $rows2, 'rows3' => $rows3, 'rows4' => $rows4];
}

// Renders ONE <table> PER CAWANGAN (not one flat table with a merged
// column) — each with its own <thead> carrying a "CAWANGAN: X" banner row
// above the column headers. $rows must already be sorted by school_name
// (every query above already is). $extraHeaders is the column header
// labels after Bil (e.g. ['Nama Pelajar'] or ['Nama Pelajar','Peringkat']).
// $extraCols(row) returns the <td> cells after Bil for one row.
//
// Why per-cawangan tables instead of a merged/rowspan column: a rowspan
// cell physically cannot survive a page break landing in the middle of its
// group — the merged cell stays on the first page and every continuation
// row on the next page has nothing to show for Cawangan at all. A <thead>,
// on the other hand, is a genuine browser/Word feature that reprints
// itself at the top of the page whenever its table's rows spill across a
// break. Putting the cawangan name IN that thead means it's guaranteed to
// reappear if the list gets cut off — which a rowspan can never do.
// Bil numbering RESETS to 1 for each new cawangan (a fresh register per
// branch), matching the reference format — not a running count across
// the whole report.
function pm_render_grouped_tables(array $rows, array $extraHeaders, callable $extraCols): string {
    if (empty($rows)) return '';

    $groups = [];
    foreach ($rows as $r) {
        $groups[$r['school_name']][] = $r;
    }

    $colCount = 1 + count($extraHeaders);
    $html = '';
    $first = true;
    foreach ($groups as $school_name => $groupRows) {
        $no = 1;
        // border="1" AND page-break-inside:avoid as real inline attributes
        // (not just CSS rules) so both still apply in the Excel export,
        // which has no stylesheet to fall back on. Without the inline
        // page-break hint, a short table (e.g. 2 students) has no reason
        // not to split at whatever arbitrary point the page boundary
        // happens to land — this is what keeps a small cawangan's whole
        // table together instead of splitting it needlessly.
        //
        // Every cawangan (after the first) starts on its own fresh page —
        // confirmed against the reference PDF, where even a 2-student
        // cawangan sits alone on a page rather than packing in next to the
        // following cawangan's table.
        $pageBreak = $first ? '' : 'page-break-before:always;';
        $first = false;
        $html .= '<table border="1" class="pm-report-table pm-cawangan-table" style="page-break-inside:avoid;' . $pageBreak . '">';
        $html .= '<thead>';
        $html .= '<tr><th colspan="' . $colCount . '" class="pm-cawangan-banner" style="text-align:left;background:#ddd;">CAWANGAN: ' . htmlspecialchars(strtoupper($school_name)) . '</th></tr>';
        $html .= '<tr><th style="width:8%;text-align:center;">BIL</th>';
        foreach ($extraHeaders as $h) $html .= '<th>' . htmlspecialchars(strtoupper($h)) . '</th>';
        $html .= '</tr>';
        $html .= '</thead><tbody>';
        foreach ($groupRows as $r) {
            $html .= '<tr><td style="text-align:center;">' . $no++ . '</td>' . $extraCols($r) . '</tr>';
        }
        $html .= '</tbody></table>';
    }
    return $html;
}
