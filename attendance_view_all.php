<?php
// ══════════════════════════════════════════════════════════════════
//  attendance_view_all.php — Admin: full student attendance list
//  Shows ALL students with ✔ Hadir / ✖ Tidak Hadir status
//  Filters: Siri, Sidang, Tahun, Cawangan, Status
// ══════════════════════════════════════════════════════════════════

$active_siri_id = (int)($_SESSION['active_siri_id'] ?? 0);

// ── Read filter values from GET ───────────────────────────────────
$f_siri    = isset($_GET['f_siri'])    && is_numeric($_GET['f_siri'])    ? (int)$_GET['f_siri']    : ($active_siri_id ?: 0);
$f_sidang  = isset($_GET['f_sidang'])  && is_numeric($_GET['f_sidang'])  ? (int)$_GET['f_sidang']  : 0;
$f_tahun   = isset($_GET['f_tahun'])   && ctype_digit($_GET['f_tahun'])  ? $_GET['f_tahun']         : '';
$f_school  = isset($_GET['f_school'])  && is_numeric($_GET['f_school'])  ? (int)$_GET['f_school']  : 0;
$f_status  = isset($_GET['f_status'])  && in_array($_GET['f_status'], ['Present','Absent','']) ? $_GET['f_status'] : '';

// ── Build filter options ──────────────────────────────────────────
$opt_siri   = $conn->query("SELECT siri_id, siri_name, siri_year FROM siri ORDER BY siri_year DESC, siri_name ASC");
$opt_sidang = $conn->query("SELECT session_id, session_name, siri_id FROM sessions ORDER BY siri_id ASC, session_name ASC");
$opt_tahun  = $conn->query("SELECT DISTINCT year FROM students WHERE year IS NOT NULL AND year != '' ORDER BY year DESC");
$opt_school = $conn->query("SELECT school_id, school_name FROM schools ORDER BY school_name ASC");

// ── Build WHERE clauses using prepared statement params ───────────
$where      = ["1=1"];
$bind_types = "";
$bind_vals  = [];

if ($f_siri > 0)     { $where[] = "si.siri_id = ?";    $bind_types .= "i"; $bind_vals[] = $f_siri; }
if ($f_sidang > 0)   { $where[] = "s.session_id = ?";  $bind_types .= "i"; $bind_vals[] = $f_sidang; }
if ($f_tahun !== '')  { $where[] = "st.year = ?";       $bind_types .= "s"; $bind_vals[] = $f_tahun; }
if ($f_school > 0)   { $where[] = "sc.school_id = ?";  $bind_types .= "i"; $bind_vals[] = $f_school; }

$where_sql = 'AND ' . implode(' AND ', $where);

// Status filter applied after — correlated subquery needs it separately
$status_having = '';
if ($f_status === 'Present') {
    $status_having = "HAVING attend_status = 'Present'";
} elseif ($f_status === 'Absent') {
    $status_having = "HAVING (attend_status IS NULL OR attend_status = 'Absent')";
}

// ── Main query — uses UNION path (level_id + group_students) ─────
$sql = "
    SELECT st.student_id,
           st.student_name,
           st.year,
           sc.school_name,
           si.siri_name,
           si.siri_year,
           s.session_name,
           COALESCE(
               (SELECT a.status FROM attendance a
                WHERE a.student_id = st.student_id
                  AND a.session_id = s.session_id
                  AND a.status = 'Present' LIMIT 1),
               (SELECT a.status FROM attendance a
                WHERE a.student_id = st.student_id
                  AND a.status = 'Present' LIMIT 1)
           ) AS attend_status
    FROM students st
    JOIN schools sc ON st.school_id = sc.school_id
    JOIN (
        SELECT DISTINCT st2.student_id, l.session_id
        FROM students st2
        JOIN levels l ON st2.level_id = l.level_id
        UNION
        SELECT DISTINCT gs.student_id, gl.session_id
        FROM group_students gs
        JOIN `groups` g ON gs.group_id = g.group_id
        JOIN levels gl ON g.level_id = gl.level_id
    ) stu_sess ON stu_sess.student_id = st.student_id
    JOIN sessions s ON s.session_id = stu_sess.session_id
    JOIN siri si ON si.siri_id = s.siri_id
    WHERE 1=1 $where_sql
    GROUP BY st.student_id, s.session_id
    $status_having
    ORDER BY si.siri_name ASC, s.session_name ASC, sc.school_name ASC, st.student_name ASC
";

if ($bind_vals) {
    $stmt_all = $conn->prepare($sql);
    $stmt_all->bind_param($bind_types, ...$bind_vals);
    $stmt_all->execute();
    $result = $stmt_all->get_result();
} else {
    $result = $conn->query($sql);
}

$total = $result ? $result->num_rows : 0;
?>

<h2 class="pm-page-heading">Senarai Penuh Kehadiran Pesilat</h2>

<div class="pm-card" style="margin-bottom:16px;">
    <form method="GET" action="attendance.php" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
        <input type="hidden" name="view" value="all_present">

        <div class="avf-field">
            <label class="avf-label">Siri</label>
            <select name="f_siri" class="avf-select" onchange="this.form.submit()">
                <option value="0">— Semua Siri —</option>
                <?php $opt_siri->data_seek(0); while ($r = $opt_siri->fetch_assoc()): ?>
                <option value="<?= $r['siri_id'] ?>" <?= $f_siri == $r['siri_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($r['siri_name'] . ' ' . $r['siri_year']) ?>
                </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="avf-field">
            <label class="avf-label">Sidang</label>
            <select name="f_sidang" class="avf-select" onchange="this.form.submit()">
                <option value="0">— Semua Sidang —</option>
                <?php $opt_sidang->data_seek(0); while ($r = $opt_sidang->fetch_assoc()):
                    // only show sidang belonging to selected siri
                    if ($f_siri > 0 && $r['siri_id'] != $f_siri) continue;
                ?>
                <option value="<?= $r['session_id'] ?>" <?= $f_sidang == $r['session_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($r['session_name']) ?>
                </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="avf-field">
            <label class="avf-label">Tahun</label>
            <select name="f_tahun" class="avf-select" onchange="this.form.submit()">
                <option value="">— Semua Tahun —</option>
                <?php $opt_tahun->data_seek(0); while ($r = $opt_tahun->fetch_assoc()): ?>
                <option value="<?= $r['year'] ?>" <?= $f_tahun == $r['year'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($r['year']) ?>
                </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="avf-field">
            <label class="avf-label">Cawangan</label>
            <select name="f_school" class="avf-select" onchange="this.form.submit()">
                <option value="0">— Semua Cawangan —</option>
                <?php $opt_school->data_seek(0); while ($r = $opt_school->fetch_assoc()): ?>
                <option value="<?= $r['school_id'] ?>" <?= $f_school == $r['school_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($r['school_name']) ?>
                </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="avf-field">
            <label class="avf-label">Status</label>
            <select name="f_status" class="avf-select" onchange="this.form.submit()">
                <option value="">— Semua Status —</option>
                <option value="Present" <?= $f_status === 'Present' ? 'selected' : '' ?>>✔ Hadir</option>
                <option value="Absent"  <?= $f_status === 'Absent'  ? 'selected' : '' ?>>✖ Tidak Hadir</option>
            </select>
        </div>

        <?php if ($f_siri || $f_sidang || $f_tahun !== '' || $f_school || $f_status !== ''): ?>
        <a href="attendance.php?view=all_present" class="avf-clear">✕ Kosongkan</a>
        <?php endif; ?>
    </form>
</div>

<div class="pm-card">
    <div class="flex flex-wrap gap-3 mb-5" style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:18px;align-items:center;">
        <a href="attendance.php" class="pm-btn pm-btn-ghost">← Kembali</a>
        <a href="export_attendance_pdf.php?<?= http_build_query(['f_siri'=>$f_siri,'f_sidang'=>$f_sidang,'f_tahun'=>$f_tahun,'f_school'=>$f_school,'f_status'=>$f_status]) ?>" target="_blank" class="pm-btn pm-btn-danger">📄 PDF</a>
        <a href="export_attendance_excel.php?<?= http_build_query(['f_siri'=>$f_siri,'f_sidang'=>$f_sidang,'f_tahun'=>$f_tahun,'f_school'=>$f_school,'f_status'=>$f_status]) ?>" class="pm-btn pm-btn-success">📊 Excel</a>
        <span style="margin-left:auto;font-size:0.82rem;color:var(--c-text-muted);font-weight:600;">
            <?= number_format($total) ?> rekod dijumpai
        </span>
    </div>

    <?php if ($result && $total > 0): ?>
    <div class="pm-table-wrap">
        <table class="pm-table">
            <thead>
                <tr>
                    <th>No</th>
                    <th>Nama Pesilat</th>
                    <th>Cawangan</th>
                    <th>Siri</th>
                    <th>Sidang</th>
                    <th>Tahun</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $no = 1;
            while ($row = $result->fetch_assoc()):
                $is_present = ($row['attend_status'] === 'Present');
            ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td><?= htmlspecialchars($row['student_name']) ?></td>
                    <td><?= htmlspecialchars($row['school_name']) ?></td>
                    <td><?= htmlspecialchars($row['siri_name'] . ' ' . $row['siri_year']) ?></td>
                    <td><?= htmlspecialchars($row['session_name']) ?></td>
                    <td><?= htmlspecialchars($row['year'] ?? 'Tiada') ?></td>
                    <td>
                        <?php if ($is_present): ?>
                            <span style="color:#4ADE80;font-weight:600;">✔ Hadir</span>
                        <?php else: ?>
                            <span style="color:#F87171;font-weight:600;">✖ Tidak Hadir</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <div class="pm-empty-state">
            <p>Tiada pesilat dijumpai untuk penapis yang dipilih.</p>
        </div>
    <?php endif; ?>
</div>

<style>
.avf-field {
    display: flex;
    flex-direction: column;
    gap: 5px;
    min-width: 150px;
    flex: 1;
}
.avf-label {
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: var(--c-text-muted);
}
.avf-select {
    height: 32px; /* match .dd- benchmark (pic_view_marks) */
    background: var(--c-surface-2);
    border: 1px solid var(--c-border-strong);
    border-radius: 6px;
    padding: 0 28px 0 10px;
    color: var(--c-white);
    font-size: 0.8rem;
    cursor: pointer;
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 8px center;
    transition: border-color 0.15s;
    width: 100%;
    box-sizing: border-box;
}
.avf-select:focus {
    outline: none;
    border-color: var(--c-red);
    box-shadow: 0 0 0 2px rgba(200,0,30,0.08);
}
.avf-select option { background: #181818; }
.avf-clear {
    display: inline-flex;
    align-items: center;
    height: 36px;
    padding: 0 14px;
    border-radius: 8px;
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--c-text-muted);
    border: 1px solid var(--c-border-strong);
    background: var(--c-surface-2);
    text-decoration: none;
    white-space: nowrap;
    align-self: flex-end;
    transition: color 0.15s, border-color 0.15s;
}
.avf-clear:hover { color: var(--c-red); border-color: var(--c-red); }
@media (max-width: 600px) {
    .avf-field { min-width: calc(50% - 5px); flex: 1 1 calc(50% - 5px); }
}
</style>
