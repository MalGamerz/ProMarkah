const sessionSelect = document.getElementById('sessionSelect');
const levelSelect = document.getElementById('levelSelect');
const testResults = document.getElementById('testResults');

sessionSelect.addEventListener('change', function() {
    testResults.innerHTML = '';
    const sessionId = this.value;
    if (!sessionId) {
        levelSelect.disabled = true;
        levelSelect.innerHTML = "<option value=''>-- Sila Pilih Sidang dahulu --</option>";
        return;
    }
    pmFetch('test_preview.php?ajax=levels&session_id=' + encodeURIComponent(sessionId))
        .then(r => r.text())
        .then(html => {
            levelSelect.innerHTML = html;
            levelSelect.disabled = false;
        })
        .catch(() => {});
});

levelSelect.addEventListener('change', function() {
    const levelId = this.value;
    if (!levelId) {
        testResults.innerHTML = '';
        return;
    }
    testResults.innerHTML = "<div class='pm-card' style='text-align:center;color:var(--c-text-faint);'>Memuatkan...</div>";
    pmFetch('test_preview.php?ajax=tests&level_id=' + encodeURIComponent(levelId))
        .then(r => r.text())
        .then(html => { testResults.innerHTML = html; })
        .catch(() => {});
});
