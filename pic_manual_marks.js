// pic_manual_marks.js — the PIC manual marks-entry page (pic_manual_marks.php).
// Split out of that file's inline <script> block. No PHP interpolation,
// so this is a plain file move.

// ── Searchable .dd- dropdown logic (matches the pic_view_marks benchmark) ──
function ddToggle(ddName){
    const t=document.getElementById('ddTrigger_'+ddName);
    if(!t || t.classList.contains('dd-trigger-disabled'))return;
    const p=document.getElementById('ddPanel_'+ddName);
    const open=p.classList.contains('open');
    document.querySelectorAll('.dd-panel.open').forEach(x=>x.classList.remove('open'));
    document.querySelectorAll('.dd-trigger.open').forEach(x=>x.classList.remove('open'));
    if(!open){ p.classList.add('open'); t.classList.add('open'); setTimeout(()=>p.querySelector('.dd-search-box input')?.focus(),50); }
}
function ddFilter(ddName,val){
    const opts=document.querySelectorAll('#ddOpts_'+ddName+' .dd-opt');
    const empty=document.getElementById('ddEmpty_'+ddName);
    let any=false;
    opts.forEach(o=>{const m=o.textContent.toLowerCase().includes(val.toLowerCase());o.classList.toggle('hidden',!m);if(m)any=true;});
    if(empty)empty.style.display=any?'none':'block';
}
function ddSelect(ddName,value,label){
    document.getElementById(ddName).value=value;
    const lbl=document.getElementById('ddLabel_'+ddName);
    lbl.textContent=label; lbl.style.color=value===''?'var(--c-text-faint)':'';
    document.querySelectorAll('#ddOpts_'+ddName+' .dd-opt').forEach(o=>o.classList.toggle('selected',o.dataset.value===value));
    document.getElementById('ddPanel_'+ddName).classList.remove('open');
    document.getElementById('ddTrigger_'+ddName).classList.remove('open');
    document.getElementById('mmParamForm').submit(); // cascade: rebuild dependents
}
document.addEventListener('click',e=>{
    if(!e.target.closest('.dd-wrap')){
        document.querySelectorAll('.dd-panel.open').forEach(p=>p.classList.remove('open'));
        document.querySelectorAll('.dd-trigger.open').forEach(t=>t.classList.remove('open'));
    }
});

// A locked (disabled) input is excluded from form submission entirely by
// the browser — unlocking is what makes it eligible to be saved/overwritten.
// Rebuilds the locked-state lock+abai button pair — used both on initial
// render (PHP-side) and here in JS when "cancel" re-locks a cell.
function mmLockedBadgesHTML(sid, cid) {
    return '<button type="button" class="mm-unlock-btn" onclick="mmUnlock(this)" title="Markah sedia ada — klik untuk buka kunci dan edit">🔒</button>'
         + '<button type="button" class="mm-abai-btn" onclick="mmAbai(this, ' + sid + ', ' + cid + ')" title="Abaikan — buang markah ini terus">🗑️</button>';
}
function mmParseCellIds(cell) {
    const m = /^mmRow_(\d+)_(\d+)$/.exec(cell.id || '');
    return m ? { sid: m[1], cid: m[2] } : null;
}

function mmUnlock(btn) {
    const cell = btn.closest('.mm-mark-cell');
    const input = cell.querySelector('.mm-mark-input');
    // Remember the saved value so "cancel" can restore it exactly, even if
    // the PIC types something in and then changes their mind.
    if (input.dataset.original === undefined) input.dataset.original = input.value;
    input.disabled = false;
    input.focus();
    input.select();
    cell.classList.add('mm-unlocked');
    const badges = cell.querySelector('.mm-cell-badges');
    if (badges) {
        badges.innerHTML = '<button type="button" class="mm-cancel-btn" onclick="mmCancelUnlock(this)" title="Batal — kembalikan markah asal dan kunci semula">↩</button>';
    }
}

// Backs out of an unlock without saving — restores the original value and
// re-locks the cell back to its normal display state.
function mmCancelUnlock(btn) {
    const cell = btn.closest('.mm-mark-cell');
    const input = cell.querySelector('.mm-mark-input');
    input.value = input.dataset.original ?? '';
    input.disabled = true;
    cell.classList.remove('mm-unlocked');
    const ids = mmParseCellIds(cell);
    const badges = cell.querySelector('.mm-cell-badges');
    if (badges && ids) badges.innerHTML = mmLockedBadgesHTML(ids.sid, ids.cid);
}

// "Abai" an existing mark — clears it and flags it via a hidden
// clear[student_id][criteria_id] input so pic_save_scores.php deletes that
// scores row outright (equivalent to a judge unchecking "Dinilai").
function mmAbai(btn, sid, cid) {
    const cell = document.getElementById(`mmRow_${sid}_${cid}`);
    if (!cell) return;
    if (!confirm('Abaikan markah ini? Markah sedia ada akan dibuang.')) return;

    const input = cell.querySelector('.mm-mark-input');
    input.value = '';
    input.disabled = true; // excluded from marks[] submission
    input.placeholder = 'Abai';

    const clearInput = document.createElement('input');
    clearInput.type = 'hidden';
    clearInput.name = `clear[${sid}][${cid}]`;
    clearInput.value = '1';
    cell.appendChild(clearInput);

    cell.querySelector('.mm-cell-badges')?.remove();
    cell.classList.remove('has-mark');
    cell.classList.add('mm-abaied');
}
