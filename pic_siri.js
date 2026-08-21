function openModal(id) {
    const m = document.getElementById(id);
    m.style.display = 'flex';
    setTimeout(() => m.classList.add('show'), 10);
}
function closeModal(id) {
    const m = document.getElementById(id);
    m.classList.remove('show');
    setTimeout(() => m.style.display = 'none', 200);
}
document.querySelectorAll('.pm-modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); });
});

function openEditSiri(id, name, year, notes) {
    document.getElementById('editSiriId').value    = id;
    document.getElementById('editSiriName').value  = name;
    document.getElementById('editSiriYear').value  = year;
    document.getElementById('editSiriNotes').value = notes;
    openModal('modal-edit-siri');
}

function deleteSiri(id, name) {
    if (confirm('Padam siri "' + name + '"?\n\nSemua sidang dalam siri ini akan dilepaskan (tidak dipadam). Tindakan ini tidak boleh dibuat alik.')) {
        document.getElementById('deleteSiriId').value = id;
        document.getElementById('deleteSiriForm').submit();
    }
}
