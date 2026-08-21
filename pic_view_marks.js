    // ── Searchable dropdown logic (matches pic_students.php's dd-wrap) ──
    // ddName here IS the hidden input's own id (vm_siri, vm_school, ...) —
    // kept that way so vmCascade's reset logic below can address it directly.
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

    // Kumpulan (vm_group) is the one exception to "every filter is
    // independent" — its dropdown options are now scoped server-side to
    // the selected Peringkat (vm_level), so a Kumpulan value chosen under
    // a since-changed Peringkat has to be cleared here too, not just left
    // as a stale hidden-field value the dropdown no longer shows selected.
    const VM_LEVEL_TO_GROUP = { vm_level: 'vm_group' };

    function ddSelect(ddName, value, label) {
        document.getElementById(ddName).value = value;
        const lbl = document.getElementById('ddLabel_' + ddName);
        lbl.textContent = label;
        lbl.style.color = value === '' ? 'var(--c-text-faint)' : '';
        document.querySelectorAll('#ddOpts_' + ddName + ' .dd-opt').forEach(o => o.classList.toggle('selected', o.dataset.value === value));
        document.getElementById('ddPanel_' + ddName).classList.remove('open');
        document.getElementById('ddTrigger_' + ddName).classList.remove('open');
        const dependentGroup = VM_LEVEL_TO_GROUP[ddName];
        if (dependentGroup) {
            const groupField = document.getElementById(dependentGroup);
            if (groupField) groupField.value = '';
        }
        // Every other filter is independent — no parent/child resetting.
        // Submitting the form carries forward all currently-set hidden
        // input values (including the one that was just changed) as GET
        // params.
        document.getElementById('vm-filter-form').submit();
    }

    document.addEventListener('click', e => {
        if (!e.target.closest('.dd-wrap')) {
            document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
            document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
        }
    });

// ── Fit the marks list + pagination into the viewport, no page scroll ──
function fitMarksListHeight() {
    const scrollEl = document.querySelector('.marks-list');
    if (!scrollEl) return;
    if (window.innerWidth <= 640) {
        scrollEl.style.maxHeight = '';
        return;
    }
    const pagination = document.querySelector('.vm-pagination');
    const top = scrollEl.getBoundingClientRect().top;
    const paginationH = pagination ? pagination.offsetHeight : 0;
    const available = window.innerHeight - top - paginationH - 24; // 24px bottom breathing room
    scrollEl.style.maxHeight = Math.max(150, available) + 'px';
}
window.addEventListener('resize', fitMarksListHeight);
document.addEventListener('DOMContentLoaded', fitMarksListHeight);
