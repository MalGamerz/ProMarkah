// pic_judges.js — the PIC judges-management page (pic_judges.php).
// Split out of that file's inline <script> block. No PHP interpolation,
// so this is a plain file move.

// ── PIN INPUT HELPERS ──────────────────────────────────────────────────────
function setupPinRow(selector, hiddenInputId, onComplete) {
    const digits = document.querySelectorAll(selector);
    digits.forEach((inp, i) => {
        inp.addEventListener('input', function () {
            this.value = this.value.replace(/[^0-9]/g, '').slice(-1);
            this.classList.toggle('filled', this.value !== '');
            if (this.value && i < digits.length - 1) digits[i + 1].focus();
            collectPin(selector, hiddenInputId);
            if (onComplete) onComplete();
        });
        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace' && !this.value && i > 0) {
                digits[i - 1].focus();
                digits[i - 1].value = '';
                digits[i - 1].classList.remove('filled');
                collectPin(selector, hiddenInputId);
                if (onComplete) onComplete();
            }
        });
        inp.addEventListener('paste', function (e) {
            e.preventDefault();
            const paste = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
            [...paste].slice(0, 6).forEach((ch, j) => {
                if (digits[j]) {
                    digits[j].value = ch;
                    digits[j].classList.add('filled');
                }
            });
            collectPin(selector, hiddenInputId);
            if (onComplete) onComplete();
        });
    });
}

function isWeakPin(val) {
    if (val.length !== 6) return true;

    // All the same digit (000000, 111111, ...)
    if (/^(\d)\1{5}$/.test(val)) return true;

    // Strictly ascending or descending run (012345, 987654, ...)
    let ascending = true, descending = true;
    for (let i = 1; i < val.length; i++) {
        const diff = val.charCodeAt(i) - val.charCodeAt(i - 1);
        if (diff !== 1) ascending = false;
        if (diff !== -1) descending = false;
    }
    if (ascending || descending) return true;

    // Repeating block: "ab" x3 (123123) or "abc" x2 (123123 already covered, also 121212)
    if (val.slice(0, 2).repeat(3) === val) return true;
    if (val.slice(0, 3).repeat(2) === val) return true;

    // Fewer than 3 distinct digits used across the whole PIN (e.g. 121212, 112233 is fine with 3 though)
    if (new Set(val).size < 3) return true;

    // Palindrome (123321, 456654, ...)
    if (val === val.split('').reverse().join('')) return true;

    return false;
}

function collectPin(selector, hiddenId) {
    const digits = document.querySelectorAll(selector);
    const val = [...digits].map(d => d.value).join('');
    if (hiddenId) document.getElementById(hiddenId).value = val;

    if (hiddenId === 'addPinHidden') {
        const bar = document.getElementById('pinStrengthBar');
        const pct = (val.length / 6) * 100;
        bar.style.width = pct + '%';
        bar.style.background = val.length < 6 ? '#f87171' : isWeakPin(val) ? '#fb923c' : '#22c55e';
    }
    return val;
}

function checkPinMatch() {
    const np = document.getElementById('newPinHidden').value;
    const cp = document.getElementById('confirmPinHidden').value;
    const msg = document.getElementById('pinMatchMsg');
    const btn = document.getElementById('pinSaveBtn');
    if (!np || !cp) { msg.textContent = ''; btn.disabled = true; return; }
    if (np.length < 6 || cp.length < 6) { msg.textContent = ''; btn.disabled = true; return; }
    if (np === cp) {
        msg.textContent = '✅ PIN sepadan'; msg.style.color = '#4ade80';
        btn.disabled = false;
    } else {
        msg.textContent = '❌ PIN tidak sepadan'; msg.style.color = '#f87171';
        btn.disabled = true;
    }
}

setupPinRow('.add-pin-digit', 'addPinHidden');
setupPinRow('.new-pin-digit', 'newPinHidden', checkPinMatch);
setupPinRow('.confirm-pin-digit', 'confirmPinHidden', checkPinMatch);

document.querySelector('.add-toolbar')?.addEventListener('submit', function (e) {
    const pin = document.getElementById('addPinHidden').value;
    if (pin.length !== 6) { e.preventDefault(); alert('Sila masukkan PIN 6 digit yang lengkap.'); }
});

// ── MODALS ─────────────────────────────────────────────────────────────────
function openModal(id) {
    const m = document.getElementById(id);
    m.style.display = 'flex';
    setTimeout(() => m.classList.add('show'), 10);
}
function closeModal(id) {
    const m = document.getElementById(id);
    m.classList.remove('show');
    setTimeout(() => m.style.display = 'none', 300);
}

function openEditModal(id, name, code, email) {
    document.getElementById('editJudgeId').value   = id;
    document.getElementById('editName').value      = name;
    document.getElementById('editJudgeCode').value = code;
    document.getElementById('editEmail').value     = email || '';
    openModal('modal-edit');
}

function openPinModal(id, name) {
    document.getElementById('pinJudgeId').value = id;
    document.getElementById('pinModalSubtitle').textContent = 'Set PIN baharu untuk: ' + name;
    document.querySelectorAll('.new-pin-digit, .confirm-pin-digit').forEach(d => {
        d.value = ''; d.classList.remove('filled');
    });
    document.getElementById('newPinHidden').value = '';
    document.getElementById('confirmPinHidden').value = '';
    document.getElementById('pinMatchMsg').textContent = '';
    document.getElementById('pinSaveBtn').disabled = true;
    openModal('modal-pin');
    setTimeout(() => document.querySelector('.new-pin-digit').focus(), 300);
}

function confirmDelete(id, name) {
    if (confirm('⚠️ Padam juri "' + name + '"?\n\nTindakan ini tidak boleh dibuat alik. Rekod markah yang dikaitkan dengan juri ini akan dikekalkan.')) {
        document.getElementById('deleteJudgeId').value = id;
        document.getElementById('deleteForm').submit();
    }
}

document.querySelectorAll('.pm-modal-overlay').forEach(m => {
    m.addEventListener('click', function(e) {
        if (e.target === this) { this.classList.remove('show'); setTimeout(() => this.style.display='none', 300); }
    });
});

// ── SEARCH + PAGINATION ────────────────────────────────────────────────────
let juriCurrentPage = 1;
const juriRowsPerPage = 20;

function juriGetVisibleRows() {
    return Array.from(document.querySelectorAll('#juriTbody .juri-row'))
                .filter(r => r.style.display !== 'none' || !r.dataset.filtered);
}

function juriUpdatePagination() {
    const allRows = Array.from(document.querySelectorAll('#juriTbody .juri-row'));
    const filtered = allRows.filter(r => r.dataset.filtered !== '1');

    const total = filtered.length;
    const totalPages = Math.max(1, Math.ceil(total / juriRowsPerPage));
    if (juriCurrentPage > totalPages) juriCurrentPage = totalPages;
    if (juriCurrentPage < 1) juriCurrentPage = 1;

    const start = (juriCurrentPage - 1) * juriRowsPerPage;
    const end   = start + juriRowsPerPage;

    allRows.forEach(r => r.style.display = 'none');
    filtered.forEach((r, i) => { r.style.display = (i >= start && i < end) ? '' : 'none'; });

    const s = total === 0 ? 0 : start + 1;
    const e = Math.min(end, total);
    document.getElementById('juriPageInfo').innerHTML =
        `Memaparkan <b>${s}–${e}</b> daripada <b>${total}</b> juri`;

    pmRenderPagination(document.getElementById('juriPaginationButtons'), juriCurrentPage, totalPages, juriGoTo);
}

window.juriGoTo = function(page) {
    juriCurrentPage = page;
    juriUpdatePagination();
};

// ── Fit the judges table + pagination into the viewport, no page scroll ──
function fitJuriTableHeight() {
    const scrollEl = document.querySelector('.juri-table-scroll');
    const pagination = document.getElementById('juriPaginationContainer');
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
window.addEventListener('resize', fitJuriTableHeight);

document.getElementById('judgeSearch')?.addEventListener('input', function() {
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll('#juriTbody .juri-row').forEach(row => {
        row.dataset.filtered = row.dataset.search.includes(q) ? '' : '1';
    });
    juriCurrentPage = 1;
    juriUpdatePagination();
});

// Init pagination on load
juriUpdatePagination();
fitJuriTableHeight();

// ── AUTO-DISMISS ALERTS ────────────────────────────────────────────────────
setTimeout(() => {
    document.querySelectorAll('.pm-alert').forEach(alert => {
        alert.style.transition = 'opacity 0.5s ease';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 500); // Remove from DOM after fade
    });
}, 4000); // 4 seconds delay
