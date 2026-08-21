    let pmMasterCurrentPage = 1;
    const pmMasterRowsPerPage = 20;
    let pmMasterRows = [];
    let pmSortNameDir = '';
    let allStudentNames = [];

    // ── Searchable dropdown logic (matches pic_students.php's dd-wrap) ──
    function ddToggle(name) {
        const trigger = document.getElementById('ddTrigger_' + name);
        if (trigger.classList.contains('dd-trigger-disabled')) return;
        const panel = document.getElementById('ddPanel_' + name);
        const isOpen = panel.classList.contains('open');
        document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
        document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
        if (!isOpen) {
            panel.classList.add('open'); trigger.classList.add('open');
            setTimeout(() => panel.querySelector('.dd-search-box input')?.focus(), 50);
        }
    }

    function ddFilter(name, val) {
        const opts = document.querySelectorAll('#ddOpts_' + name + ' .dd-opt');
        const empty = document.getElementById('ddEmpty_' + name);
        let any = false;
        opts.forEach(o => {
            const m = o.textContent.toLowerCase().includes(val.toLowerCase());
            o.classList.toggle('hidden', !m);
            if (m) any = true;
        });
        if (empty) empty.style.display = any ? 'none' : 'block';
    }

    function ddSelect(name, value, label) {
        document.getElementById('f_' + name).value = value;
        const lbl = document.getElementById('ddLabel_' + name);
        lbl.textContent = label;
        lbl.style.color = value === '' ? 'var(--c-text-faint)' : '';
        document.querySelectorAll('#ddOpts_' + name + ' .dd-opt').forEach(o => o.classList.toggle('selected', o.dataset.value === value));
        document.getElementById('ddPanel_' + name).classList.remove('open');
        document.getElementById('ddTrigger_' + name).classList.remove('open');
        fetchMasterData(true);
    }

    document.addEventListener('click', e => {
        if (!e.target.closest('.dd-wrap')) {
            document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
            document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
        }
    });

    // ── Pagination render ─────────────────────────────────────────
    function updateMasterPagination() {
        const tbody = document.getElementById('master_tbody');
        pmMasterRows = tbody ? Array.from(tbody.querySelectorAll('tr')).filter(tr => tr.cells.length > 1) : [];

        const container = document.getElementById('masterPaginationContainer');
        if (pmMasterRows.length === 0) { container.style.display = 'none'; return; }
        container.style.display = 'flex';

        const totalRows  = pmMasterRows.length;
        const totalPages = Math.ceil(totalRows / pmMasterRowsPerPage) || 1;

        if (pmMasterCurrentPage > totalPages) pmMasterCurrentPage = totalPages;
        if (pmMasterCurrentPage < 1)          pmMasterCurrentPage = 1;

        const start = (pmMasterCurrentPage - 1) * pmMasterRowsPerPage;
        const end   = start + pmMasterRowsPerPage;

        pmMasterRows.forEach((row, i) => { row.style.display = (i >= start && i < end) ? '' : 'none'; });

        const startText = totalRows === 0 ? 0 : start + 1;
        const endText   = Math.min(end, totalRows);
        document.getElementById('masterPageInfo').innerHTML =
            `Memaparkan <b>${startText} - ${endText}</b> daripada <b>${totalRows}</b> rekod`;

        pmRenderPagination(document.getElementById('masterPaginationButtons'), pmMasterCurrentPage, totalPages, masterGoToPage);
    }

    window.masterGoToPage = function(page) {
        pmMasterCurrentPage = page;
        updateMasterPagination();
        document.querySelector('.table-scroll-wrapper').scrollTo({ top: 0, behavior: 'smooth' });
    };

    // ── Fit the master list table + pagination into the viewport, no page scroll ──
    function fitMasterListHeight() {
        const scrollEl = document.querySelector('.table-scroll-wrapper');
        const pagination = document.getElementById('masterPaginationContainer');
        if (!scrollEl || !pagination) return;
        if (window.innerWidth <= 640) {
            scrollEl.style.maxHeight = '';
            return;
        }
        const top = scrollEl.getBoundingClientRect().top;
        const paginationH = pagination.offsetHeight;
        const available = window.innerHeight - top - paginationH - 24; // 24px bottom breathing room
        scrollEl.style.maxHeight = Math.max(150, available) + 'px';
    }
    window.addEventListener('resize', fitMasterListHeight);

    // ── Sort ─────────────────────────────────────────────────────
    function toggleSortName() {
        if (pmSortNameDir === '') pmSortNameDir = 'ASC';
        else if (pmSortNameDir === 'ASC') pmSortNameDir = 'DESC';
        else pmSortNameDir = '';

        const icon = document.getElementById('sortIcon');
        if (pmSortNameDir === 'ASC') {
            icon.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--c-red)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m6 15 6-6 6 6"/></svg>';
        } else if (pmSortNameDir === 'DESC') {
            icon.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--c-red)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>';
        } else {
            icon.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg>';
        }
        fetchMasterData(true);
    }

    // ── Searchable name dropdown ──────────────────────────────────
    function toggleNameDropdown() {
        const box = document.getElementById('nameDropdownBox');
        box.classList.toggle('open');
        if (box.classList.contains('open')) {
            document.getElementById('nameDropdownSearch').focus();
            filterNameOptions('');
        }
    }

    function filterNameOptions(keyword) {
        const list = document.getElementById('nameDropdownList');
        const kw = keyword.toLowerCase();
        const current = document.getElementById('nameSearchValue').value;

        // "Semua Pesilat" always first
        let html = `<div class="dd-option ${current === '' ? 'selected' : ''}" role="option" tabindex="0" onclick="selectNameOption('', 'Semua Pesilat')">Semua Pesilat</div>`;

        const filtered = allStudentNames.filter(n => n.toLowerCase().includes(kw));
        if (filtered.length === 0 && kw !== '') {
            html += `<div class="dd-empty">Tiada hasil ditemui</div>`;
        } else {
            filtered.forEach(name => {
                const sel = current === name ? 'selected' : '';
                html += `<div class="dd-option ${sel}" role="option" tabindex="0" onclick="selectNameOption('${name.replace(/'/g, "\'")}', '${name.replace(/'/g, "\'")}')">${name}</div>`;
            });
        }
        list.innerHTML = html;
    }

    function selectNameOption(value, label) {
        document.getElementById('nameSearchValue').value = value;
        document.getElementById('nameDropdownTrigger').value = label;
        document.getElementById('nameDropdownBox').classList.remove('open');
        fetchMasterData(true);
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        const wrapper = document.getElementById('nameDropdownWrapper');
        if (wrapper && !wrapper.contains(e.target)) {
            document.getElementById('nameDropdownBox').classList.remove('open');
        }
    });

    // ── Fetch ─────────────────────────────────────────────────────
    // 8s base rather than 3s — this re-runs the full filtered/aggregated query
    // (JOINs + SUM + GROUP_CONCAT across every matching student), unlike the
    // sidebar's notification poll which only checks a cheap MAX(score_id)
    // before doing real work. Still feels live, at a third of the DB load.
    // Same backoff-on-failure + pause-when-hidden pattern as layout.php's
    // notification poller and leaderboard.php's live refresh, for consistency.
    const PM_MASTER_BASE_DELAY = 8000;
    const PM_MASTER_MAX_DELAY  = 60000;
    let pmMasterFailCount = 0;
    let pmMasterTimer = null;

    function fetchMasterData(isManual = false) {
        const form = document.getElementById('masterFilterForm');
        const params = new URLSearchParams(new FormData(form));
        if (pmSortNameDir !== '') params.append('sort_name', pmSortNameDir);

        pmFetch('pic_master_list.php?ajax_master_list=1&' + params.toString())
            .then(r => r.text())
            .then(html => {
                pmMasterFailCount = 0;
                document.getElementById('master_tbody').innerHTML = html;
                if (isManual) pmMasterCurrentPage = 1;

                // Rebuild name list from current results (unfiltered — fetch all names once)
                if (isManual && document.getElementById('nameSearchValue').value === '') {
                    const rows = document.querySelectorAll('#master_tbody tr.master-row');
                    const names = [];
                    rows.forEach(row => {
                        const nameEl = row.querySelector('.student-name');
                        if (nameEl) names.push(nameEl.textContent.trim());
                    });
                    allStudentNames = [...new Set(names)].sort();
                    filterNameOptions('');
                }

                updateMasterPagination();
                fitMasterListHeight();
            })
            .catch(err => { console.error(err); pmMasterFailCount++; })
            .finally(() => {
                if (isManual) return; // manual refreshes don't drive the recurring timer
                if (pmMasterTimer) clearTimeout(pmMasterTimer);
                const delay = Math.min(PM_MASTER_BASE_DELAY * Math.pow(2, pmMasterFailCount), PM_MASTER_MAX_DELAY);
                pmMasterTimer = setTimeout(() => fetchMasterData(false), delay);
            });
    }

    fetchMasterData(true);
    pmMasterTimer = setTimeout(() => fetchMasterData(false), PM_MASTER_BASE_DELAY);

    // Pause polling while the tab isn't visible, resume with an immediate
    // refresh when it is — no point re-running the aggregate query every 8s
    // for a tab nobody is looking at.
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            if (pmMasterTimer) clearTimeout(pmMasterTimer);
        } else {
            if (pmMasterTimer) clearTimeout(pmMasterTimer);
            fetchMasterData(false);
        }
    });
