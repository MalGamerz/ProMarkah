<?php
// ══════════════════════════════════════════════════════════════════
//  attendance_student.php — Student self-registration view
//  Rendered standalone (no navbar/sidebar). Called internally
//  by attendance.php when ?mode=student is present.
// ══════════════════════════════════════════════════════════════════

// Expects these variables from the caller:
//   $conn, $school_id, $session_id, $school_name,
//   $session_name, $is_global,
//   $student_list (local), $schools_list (global),
//   $students_by_school (global)

?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Kehadiran — <?= htmlspecialchars($school_name) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&family=Bebas+Neue&display=swap" rel="stylesheet">
    <?php $pm_as_css_v = @filemtime(__DIR__ . '/attendance_student.css') ?: time(); ?>
    <link rel="stylesheet" href="attendance_student.css?v=<?= $pm_as_css_v ?>">
</head>
<body>
<div class="form-card">

    <div class="form-brand">
        <div class="form-brand-dot"></div>
        <div class="form-brand-label">ProMarkah</div>
        <div class="form-brand-sub">Pendaftaran Kendiri</div>
    </div>

    <div class="form-heading">Daftar Kehadiran</div>

    <div class="form-badges">
        <?php if (!empty($siri_name)): ?>
        <span class="form-badge">🗂 <?= htmlspecialchars($siri_name) ?></span>
        <?php endif; ?>
        <span class="form-badge">📅 <?= htmlspecialchars($session_name) ?></span>
        <span class="form-badge form-badge-red">📍 <?= htmlspecialchars($school_name) ?></span>
    </div>
    <div class="form-subheading">Sila lengkapkan maklumat di bawah untuk merekodkan kehadiran anda.</div>

    <div id="formArea">

        <?php if ($is_global): ?>
        <label class="form-label" for="schoolTrigger">Pilih Cawangan Anda</label>
        <!-- Custom dropdown (not a native <select>) so each row can show the
             student count right-aligned at the END, matching the student combo. -->
        <div class="combo-wrap" id="schoolCombo">
            <div class="form-select" id="schoolTrigger" tabindex="0" role="button" aria-haspopup="listbox"
                 onclick="toggleSchoolList()"
                 onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();toggleSchoolList();}">
                <span id="schoolTriggerText" class="school-trigger-placeholder">— Sila Pilih Cawangan —</span>
            </div>
            <input type="hidden" id="schoolSelect" value="">
            <svg class="form-select-arrow" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
            <div class="combo-list" id="schoolComboList" role="listbox">
                <?php foreach ($schools_list as $sch): ?>
                <?php
                    // Student count for this school in this session — reuse the
                    // already-loaded $students_by_school (keyed by int school_id)
                    // so no extra query is needed.
                    $sch_students = $students_by_school[(int)$sch['school_id']] ?? [];
                    $sch_total    = count($sch_students);
                    $sch_present  = 0;
                    foreach ($sch_students as $s) { if (!empty($s['is_present'])) { $sch_present++; } }
                    // Status tint: green when everyone's checked in, faded when
                    // the branch has no students in this session.
                    $count_class = 'combo-item-count';
                    if ($sch_total > 0 && $sch_present >= $sch_total) { $count_class .= ' is-full'; }
                    elseif ($sch_total === 0)                        { $count_class .= ' is-empty'; }
                ?>
                <div class="combo-item school-item" role="option"
                     data-id="<?= (int)$sch['school_id'] ?>"
                     data-name="<?= htmlspecialchars($sch['school_name'], ENT_QUOTES) ?>">
                    <span class="school-item-name"><?= htmlspecialchars($sch['school_name']) ?></span>
                    <span class="<?= $count_class ?>"><?= $sch_present ?>/<?= $sch_total ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <label class="form-label" for="studentSearch">Nama Pesilat</label>
        <div class="combo-wrap" id="studentCombo">
            <input type="text" class="form-select combo-input" id="studentSearch"
                   placeholder="Taip untuk cari nama anda..."
                   autocomplete="off"
                   <?= $is_global ? 'disabled' : '' ?>>
            <svg class="form-select-arrow" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
            <input type="hidden" id="studentSelect" value="">
            <div class="combo-list" id="studentComboList"></div>
        </div>

        <div class="already-present" id="alreadyPresent">
            <span>✓</span>
            <span>Anda sudah pun didaftarkan hadir untuk sidang ini.</span>
        </div>

        <div class="confirm-box" id="confirmBox">
            <div class="confirm-avatar">👤</div>
            <div>
                <div class="confirm-name"  id="confirmName"></div>
                <div class="confirm-school" id="confirmSchool"><?= htmlspecialchars($school_name) ?></div>
            </div>
        </div>

        <button class="btn-submit" id="submitBtn" disabled onclick="submitAttendance()">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <polyline points="20 6 9 17 4 12"/>
            </svg>
            Sahkan Kehadiran
        </button>
    </div>

    <div class="success-state" id="successState">
        <div class="success-checkmark">✓</div>
        <div class="success-title">Kehadiran Direkod!</div>
        <div class="success-sub" id="successMsg">Terima kasih. Kehadiran anda telah berjaya didaftarkan ke dalam sistem.</div>
        <button type="button" class="btn-submit" id="anotherBtn" onclick="registerAnother()" style="margin-top:10px;">
            Daftar Pesilat Lain
        </button>
        <button type="button" class="btn-submit" id="closeBtn" onclick="closeTab()"
            style="margin-top:10px; background:transparent; color:var(--muted); border:1px solid var(--border);">
            Tutup
        </button>
    </div>

    <hr class="form-divider">
    <p class="form-note">Jika nama anda tidak tersenarai, sila hubungi PIC dengan segera.</p>
</div>

<?php
// Bootstrap data for attendance_student.js — exposed via window.* instead
// of interpolating PHP directly into that now-static file.
$pm_as_js_v = @filemtime(__DIR__ . '/attendance_student.js') ?: time();
?>
<script>
    window.pmAttendanceStudentData = {
        isGlobal: <?= $is_global ? 'true' : 'false' ?>,
        studentsBySchool: <?= $is_global ? json_encode($students_by_school) : '{}' ?>,
        localStudentList: <?= $is_global ? '[]' : json_encode($student_list) ?>,
        currentSessionId: <?= (int)$session_id ?>,
        currentSchoolName: <?= json_encode($school_name) ?>
    };
</script>
<script src="attendance_student.js?v=<?= $pm_as_js_v ?>"></script>
</body>
</html>
<?php exit(); ?>
