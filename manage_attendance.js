let currentGroupId = 0;
let toastTimeout;

// ── Dropdown cascade ──────────────────────────────────────────────
function updateDropdownNumbers(siriHidden) {
    const lblSession = document.getElementById('lbl_session');
    const lblLevel   = document.getElementById('lbl_level');
    const lblGroup   = document.getElementById('lbl_group');
    if (lblSession) lblSession.textContent = siriHidden ? '1. Jadual Sidang'       : '2. Jadual Sidang';
    if (lblLevel)   lblLevel.textContent   = siriHidden ? '2. Struktur Peringkat'  : '3. Struktur Peringkat';
    if (lblGroup)   lblGroup.textContent   = siriHidden ? '3. Kumpulan Juri'       : '4. Kumpulan Juri';
}

function onSiriChange() {
    const siriId  = document.getElementById('sel_siri').value;
    const sessSel = document.getElementById('sel_session');

    if (siriId === '0') {
        // No siri chosen — disable session dropdown and reset cascade
        sessSel.disabled = true;
        sessSel.options[0].textContent = '-- Pilih Siri dahulu --';
        sessSel.value = '';
        onSessionChange();
        return;
    }

    // Filter sessions to the chosen siri
    Array.from(sessSel.options).forEach(opt => {
        if (!opt.value) return;
        opt.style.display = opt.dataset.siri === siriId ? '' : 'none';
    });

    // Reset session if the currently selected one no longer matches
    const selectedOpt = sessSel.options[sessSel.selectedIndex];
    if (selectedOpt && selectedOpt.value && selectedOpt.style.display === 'none') {
        sessSel.value = '';
        onSessionChange();
    }

    sessSel.options[0].textContent = '-- Pilih Sidang --';
    sessSel.disabled = false;
}

// On page load: if siri was pre-selected via sidebar, container is already hidden by PHP.
// Just sync the numbering to match whichever state PHP rendered.
document.addEventListener('DOMContentLoaded', function () {
    const container = document.getElementById('siri_container');
    const hidden    = container && container.style.display === 'none';
    updateDropdownNumbers(hidden);
});

function onSessionChange() {
    const sid = document.getElementById('sel_session').value;
    const lvlSel = document.getElementById('sel_level');
    const grpSel = document.getElementById('sel_group');

    lvlSel.innerHTML = '<option value="">-- Pilih Peringkat --</option>';
    grpSel.innerHTML = '<option value="">-- Pilih Kumpulan --</option>';
    lvlSel.disabled = true;
    grpSel.disabled = true;
    hideStudents();

    if (!sid) return;

    lvlSel.innerHTML = '<option value="">Memuatkan...</option>';
    pmFetch(`manage_attendance.php?ajax_levels=1&session_id=${sid}`)
        .then(r => r.json())
        .then(data => {
            lvlSel.innerHTML = '<option value="">-- Pilih Peringkat --</option>';
            data.forEach(l => {
                lvlSel.innerHTML += `<option value="${l.level_id}">${escHtml(l.level_name)}</option>`;
            });
            lvlSel.disabled = false;
        })
        .catch(() => {});
}

function onLevelChange() {
    const sid = document.getElementById('sel_session').value;
    const lid = document.getElementById('sel_level').value;
    const grpSel = document.getElementById('sel_group');

    grpSel.innerHTML = '<option value="">-- Pilih Kumpulan --</option>';
    grpSel.disabled = true;
    hideStudents();

    if (!lid) return;

    grpSel.innerHTML = '<option value="">Memuatkan...</option>';
    pmFetch(`manage_attendance.php?ajax_groups=1&session_id=${sid}&level_id=${lid}`)
        .then(r => r.json())
        .then(data => {
            grpSel.innerHTML = '<option value="">-- Pilih Kumpulan --</option>';
            data.forEach(g => {
                grpSel.innerHTML += `<option value="${g.group_id}">${escHtml(g.group_name)}</option>`;
            });
            grpSel.disabled = false;
        })
        .catch(() => {});
}

function onGroupChange() {
    const gid = document.getElementById('sel_group').value;
    if (!gid) { hideStudents(); return; }
    currentGroupId = parseInt(gid);
    loadStudents(gid);
}

function hideStudents() {
    document.getElementById('studentSection').style.display = 'none';
    currentGroupId = 0;
}

// ── Student list render ───────────────────────────────────────────
function loadStudents(gid) {
    const section = document.getElementById('studentSection');
    const tbody = document.getElementById('studentTbody');
    section.style.display = 'block';
    tbody.innerHTML = `<tr><td colspan="4"><div class="att-spinner">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
        Memuatkan data pesilat...
    </div></td></tr>`;

    pmFetch(`manage_attendance.php?ajax_students=1&group_id=${gid}`)
        .then(r => r.json())
        .then(students => {
            if (students.length === 0) {
                tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;padding:30px;color:var(--c-text-faint);">Tiada pesilat dalam kumpulan ini.</td></tr>`;
                return;
            }
            let html = '';
            students.forEach(s => {
                const sid   = s.student_id;
                // No recorded status yet defaults to Present/Hadir — attendance
                // is assumed unless explicitly marked absent.
                const status = s.last_status || 'Present';
                const badgeClass = status === 'Absent' ? 'pm-badge-absent' : 'pm-badge-present';
                const statusText = status === 'Absent' ? 'Tidak Hadir' : 'Hadir';
                const chkPresent = status === 'Present' ? 'checked' : '';
                const chkAbsent  = status === 'Absent'  ? 'checked' : '';
                const name   = escHtml(s.student_name);
                const school = escHtml(s.school_name || '-');
                html += `
                <tr class="att-row" data-student="${name.toLowerCase()}" data-school="${school.toLowerCase()}">
                    <td><span class="att-student-name">${name}</span></td>
                    <td><span class="att-school-name">${school}</span></td>
                    <td style="text-align:center;">
                        <span id="badge_${sid}" class="pm-badge ${badgeClass}">${statusText}</span>
                    </td>
                    <td style="text-align:right;">
                        <div class="att-toggle">
                            <label>
                                <input type="radio" name="att_${sid}" value="Present" ${chkPresent} onclick="saveAttendance(${sid},'Present')">
                                <span>Hadir</span>
                            </label>
                            <label>
                                <input type="radio" name="att_${sid}" value="Absent" ${chkAbsent} onclick="saveAttendance(${sid},'Absent')">
                                <span>Tiada</span>
                            </label>
                        </div>
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html;
        })
        .catch(() => {});
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Save attendance ───────────────────────────────────────────────
function saveAttendance(student_id, status) {
    const session_id = document.getElementById('sel_session').value;
    const level_id   = document.getElementById('sel_level').value;
    const group_id   = document.getElementById('sel_group').value;

    pmFetch("save_attendance.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `student_id=${student_id}&session_id=${session_id}&level_id=${level_id}&group_id=${group_id}&status=${status}`
    }).then(() => {
        showSuccess();
        const badge = document.getElementById("badge_" + student_id);
        badge.className = "pm-badge";
        if (status === "Present") { badge.classList.add("pm-badge-present"); badge.innerHTML = "Hadir"; }
        else                       { badge.classList.add("pm-badge-absent");  badge.innerHTML = "Tidak Hadir"; }
    }).catch(() => {});
}

function showSuccess() {
    const toast = document.getElementById("successToast");
    toast.style.opacity = "1";
    toast.style.transform = "translateY(0px)";
    clearTimeout(toastTimeout);
    toastTimeout = setTimeout(() => {
        toast.style.opacity = "0";
        toast.style.transform = "translateY(20px)";
    }, 2000);
}

// ── Search ────────────────────────────────────────────────────────
document.getElementById("searchInput")?.addEventListener("keyup", function() {
    const kw = this.value.toLowerCase();
    document.querySelectorAll("#studentTbody tr.att-row").forEach(row => {
        row.style.display = (row.dataset.student.includes(kw) || row.dataset.school.includes(kw)) ? "" : "none";
    });
});
