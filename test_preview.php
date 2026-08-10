<?php
session_start();
require __DIR__ . '/auth_check.php';
include 'db.php';
$conn = getDB();

// Restrict access only to PIC role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pic') {
    header("Location: login.php");
    exit();
}

// ── AJAX: levels for a given session (keeps "Pilih Peringkat" scoped to the
// chosen Sidang instead of listing every level from every session) ──
if (isset($_GET['ajax']) && $_GET['ajax'] === 'levels') {
    $session_id = (int)($_GET['session_id'] ?? 0);
    echo "<option value=''>-- Sila Pilih Peringkat --</option>";
    if ($session_id > 0) {
        $stmt = $conn->prepare("SELECT level_id, level_name FROM levels WHERE session_id = ? ORDER BY level_name");
        $stmt->bind_param('i', $session_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($l = $res->fetch_assoc()) {
            echo "<option value='{$l['level_id']}'>" . htmlspecialchars($l['level_name']) . "</option>";
        }
        $stmt->close();
    }
    exit();
}

// ── AJAX: test structure for a given level — renders immediately, no submit button needed ──
if (isset($_GET['ajax']) && $_GET['ajax'] === 'tests') {
    $level_id = (int)($_GET['level_id'] ?? 0);

    $levelNameStmt = $conn->prepare("SELECT level_name FROM levels WHERE level_id = ?");
    $levelNameStmt->bind_param('i', $level_id);
    $levelNameStmt->execute();
    $levelNameQ = $levelNameStmt->get_result();
    $levelName = $levelNameQ && $levelNameQ->num_rows > 0 ? $levelNameQ->fetch_assoc()['level_name'] : 'Peringkat Tidak Diketahui';
    $levelNameStmt->close();

    echo "<div class='pm-card'>
            <h3 class='pm-card-title' style='margin-bottom: 24px; font-size: 1.5rem; letter-spacing: 0.5px;'>
                Struktur Ujian: <span class='struktur-level-value'>" . htmlspecialchars($levelName) . "</span>
            </h3>";

    $testsStmt = $conn->prepare("SELECT test_id, test_name FROM tests WHERE level_id = ? ORDER BY test_name");
    $testsStmt->bind_param('i', $level_id);
    $testsStmt->execute();
    $tests = $testsStmt->get_result();

    if ($tests && $tests->num_rows > 0) {
        echo "<div class='pm-table-wrap'>
                <table class='pm-table'>
                    <thead>
                        <tr>
                            <th style='width: 60px; text-align: center;'>Bil.</th>
                            <th style='width: 280px;'>Nama Ujian</th>
                            <th>Senarai Kriteria Penilaian</th>
                        </tr>
                    </thead>
                    <tbody>";

        $i = 1;
        $criteriaStmt = $conn->prepare("SELECT criteria_name FROM criteria WHERE test_id = ? ORDER BY criteria_name");
        while ($t = $tests->fetch_assoc()) {
            $test_id = $t['test_id'];
            $criteriaStmt->bind_param('i', $test_id);
            $criteriaStmt->execute();
            $criteria = $criteriaStmt->get_result();

            echo "<tr>
                    <td style='vertical-align: top; text-align: center; padding-top: 12px;'>
                        <div class='test-number-badge'>{$i}</div>
                    </td>
                    <td style='vertical-align: top; padding-top: 14px; font-size: 1.05rem;' class='ujian-test-name'>
                        <strong>" . htmlspecialchars($t['test_name']) . "</strong>
                    </td>
                    <td style='padding: 12px 10px;'>";

            if ($criteria->num_rows > 0) {
                echo "<ul class='criteria-list'>";
                while ($c = $criteria->fetch_assoc()) {
                    echo "<li>" . htmlspecialchars($c['criteria_name']) . "</li>";
                }
                echo "</ul>";
            } else {
                echo "<span class='no-criteria-note'>
                        ⚠️ Tiada kriteria ditetapkan untuk ujian ini.
                      </span>";
            }

            echo "</td>
                  </tr>";
            $i++;
        }
        $criteriaStmt->close();
        $testsStmt->close();

        echo "</tbody></table></div>
              <div class='no-print print-bar' style='text-align: right; border-top: 1px solid rgba(255,255,255,0.05);'>
                  <button onclick='window.print()' class='btn-print'>
                      <span style='font-size: 1.2rem;'>🖨️</span> Cetak / Simpan PDF
                  </button>
              </div>
              </div>";
    } else {
        $testsStmt->close();
        echo "<div class='pm-alert pm-alert-warn' style='margin: 0;'>
                <strong>Perhatian:</strong> Tiada ujian dijumpai untuk peringkat ini di dalam pangkalan data.
              </div></div>";
    }
    exit();
}

// Set the active page for the sidebar highlighting
$pm_page = 'test_preview';
include 'layout.php';

// "Semua Siri" (0) is ambiguous across sessions with duplicate names, so show a
// Siri picker only in that case to let the PIC narrow down which siri's sessions to browse.
$active_siri_id = (int)($_SESSION['active_siri_id'] ?? 0);
$show_siri_picker = $active_siri_id === 0;
$filter_siri_id = $show_siri_picker ? (int)($_POST['siri_id'] ?? 0) : $active_siri_id;
?>

<style>
/* ================= PREMIUM PREVIEW UI ================= */

/* 1. Filter Card Styling */
.preview-filter-card {
    background: linear-gradient(145deg, rgba(20,20,20,0.9), rgba(15,15,15,0.95));
    border-top: 3px solid var(--pm-orange);
}
.preview-form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
    align-items: end;
}
.form-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #e0e0e0;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}

/* 2. Interactive Criteria List */
.criteria-list {
    list-style: none;
    margin: 0;
    padding: 0;
    column-count: 2;
    column-gap: 20px;
}
.criteria-list li {
    position: relative;
    padding: 10px 14px 10px 28px;
    margin-bottom: 10px;
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 8px;
    color: var(--c-text-muted);
    font-size: 0.92rem;
    line-height: 1.4;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);

    /* Prevents the card from breaking in half between columns */
    break-inside: avoid-column;
    page-break-inside: avoid;
}
.criteria-list li::before {
    content: '';
    position: absolute;
    left: 12px;
    top: 16px;
    width: 6px;
    height: 6px;
    background: var(--pm-orange);
    border-radius: 50%;
    box-shadow: 0 0 8px var(--pm-orange);
    transition: transform 0.2s;
}
.criteria-list li:hover {
    background: rgba(226,88,34,0.08);
    border-color: rgba(226,88,34,0.3);
    color: var(--c-white);
    transform: translateX(4px);
}
.criteria-list li:hover::before {
    transform: scale(1.5);
}

/* Level name / test name headers — themed instead of hardcoded white */
.struktur-level-value,
.ujian-test-name {
    color: var(--c-white);
}
.no-criteria-note {
    color: var(--c-text-faint);
    font-style: italic;
    background: rgba(0,0,0,0.2);
    padding: 8px 12px;
    border-radius: 6px;
    display: inline-block;
}

/* ── Light mode: the gradient/dark defaults above go invisible on a white card ── */
html.pm-light .struktur-level-value,
html.pm-light .ujian-test-name {
    color: #111;
}
html.pm-light .criteria-list li {
    background: var(--c-gray-50);
    border-color: var(--c-gray-200);
    color: var(--c-gray-700);
}
html.pm-light .criteria-list li:hover {
    background: #fef2f2;
    border-color: var(--c-red-300);
    color: #111;
}
html.pm-light .no-criteria-note {
    color: var(--c-gray-500);
    background: var(--c-gray-100);
}

/* 3. Number Badge */
.test-number-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    background: rgba(226,88,34,0.15);
    color: var(--pm-orange);
    border: 1px solid rgba(226,88,34,0.3);
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.95rem;
}

/* 4. Action Button */
.btn-print {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    padding: 12px 24px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.95rem;
    border: none;
    cursor: pointer;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.25);
    transition: all 0.3s ease;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.btn-print:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
    background: linear-gradient(135deg, #34d399, #10b981);
}

/* ── Fit-to-screen: scroll happens inside the table, not the whole page,
   so the "Cetak / Simpan PDF" button always stays in view ── */
#testResults .pm-card {
    padding: 16px 20px;
    margin-bottom: 0;
}
#testResults .pm-card-title {
    margin-bottom: 12px !important;
    font-size: 1.25rem !important;
}
#testResults .pm-table-wrap {
    max-height: calc(100vh - 340px);
    min-height: 160px;
    overflow-y: auto;
    overflow-x: auto;
    scrollbar-width: thin;
    scrollbar-color: var(--c-surface-3) transparent;
}
#testResults .pm-table thead th { position: sticky; top: 0; z-index: 2; }
#testResults .pm-table td { padding-top: 10px !important; padding-bottom: 10px !important; }
#testResults .test-number-badge { width: 28px; height: 28px; font-size: 0.85rem; }
#testResults .criteria-list li { padding: 7px 10px 7px 22px; margin-bottom: 6px; }
#testResults .print-bar {
    margin-top: 14px !important;
    padding-top: 14px !important;
}
#testResults .btn-print { padding: 9px 18px; font-size: 0.85rem; }

/* Responsive adjustments for mobile */
@media (max-width: 768px) {
    .criteria-list { column-count: 1; }
    #testResults .pm-table-wrap { max-height: calc(100vh - 300px); }
}
@media (max-width: 480px) {
    #testResults .pm-card { padding: 12px 14px; }
    #testResults .pm-card-title { font-size: 1.05rem !important; }
    #testResults .pm-table th { padding: 8px 6px !important; font-size: 0.68rem !important; }
    #testResults .pm-table td { padding: 6px !important; font-size: 0.8rem; }
    #testResults .test-number-badge { width: 24px; height: 24px; font-size: 0.75rem; }
    #testResults .ujian-test-name { font-size: 0.9rem !important; padding-top: 6px !important; }
    #testResults .criteria-list li { font-size: 0.8rem; padding: 6px 8px 6px 18px; }
    #testResults .pm-table-wrap { max-height: calc(100vh - 260px); }
    #testResults .btn-print { padding: 8px 14px; font-size: 0.78rem; width: 100%; justify-content: center; }
}

/* ================= PRINT CSS (Red, White, Gray, Black Theme) ================= */
@media print {
    /* Force browser to print background colors and colored text */
    * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    html, body, .pm-body { 
        height: auto !important; 
        overflow: visible !important; 
        background: #ffffff !important; 
        color: #000000 !important; 
    }
    
    .pm-header, .pm-sidebar, .pm-overlay, .pm-hamburger, .pm-page-heading, .no-print { display: none !important; }
    
    .pm-main { margin: 0 !important; padding: 10px !important; }
    
    .pm-table-wrap {
        overflow: visible !important;
        display: block !important;
        border: none !important;
        margin: 2px !important; 
    }

    tr { page-break-inside: avoid !important; }
    
    .pm-card {
        border: none !important;
        box-shadow: none !important;
        background: transparent !important;
        padding: 0 !important;
        margin-bottom: 20px !important;
    }
    
    .pm-card h3 {
        color: #1a1a1a !important; /* Dark Black/Gray */
        border-bottom: 2px solid #cc0000 !important; /* Red accent */
        padding-bottom: 10px;
    }
    
    .pm-table { border-collapse: collapse !important; width: 100% !important; }
    
    /* FIX: Dark Black/Gray header, White text, Red accent border */
    .pm-table th { 
        background: #27272a !important; /* Dark Zinc/Black */
        color: #ffffff !important; /* Pure White text */
        border: 1px solid #3f3f46 !important; /* Dark Gray border */
        border-top: 3px solid #cc0000 !important; /* Red accent top line */
        padding: 12px 10px !important;
    }
    
    .pm-table td { 
        border: 1px solid #d4d4d8 !important; /* Light Gray */
        color: #1a1a1a !important; 
        background: #ffffff !important; 
    }
    
    .criteria-list { column-count: 1 !important; }
    .criteria-list li {
        background: transparent !important;
        border: none !important;
        color: #1a1a1a !important;
        padding: 4px 0 4px 16px !important;
        margin-bottom: 4px !important;
    }
    
    /* Red bullet points */
    .criteria-list li::before {
        background: #cc0000 !important; 
        box-shadow: none !important;
        width: 5px; height: 5px;
        left: 4px; top: 12px;
    }
    
    /* Subtle Gray badge with Red text */
    .test-number-badge {
        background: #f4f4f5 !important; 
        border: 1px solid #e4e4e7 !important; 
        color: #cc0000 !important; 
    }
}
</style>

<h2 class="pm-page-heading">📄 Paparan Struktur Ujian</h2>

<div class="pm-card preview-filter-card no-print">
    <form method="POST" class="preview-form-grid">
        <?php if ($show_siri_picker): ?>
        <div>
            <label class="form-label">Pilih Siri</label>
            <select name="siri_id" class="pm-select" onchange="this.form.submit()" style="width: 100%;">
                <option value="">-- Semua Siri --</option>
                <?php
                $siris = $conn->query("SELECT siri_id, siri_name, siri_year FROM siri ORDER BY siri_year DESC, siri_name ASC");
                while ($si = $siris->fetch_assoc()) {
                    $sel = ($filter_siri_id === (int)$si['siri_id']) ? 'selected' : '';
                    echo "<option value='{$si['siri_id']}' $sel>" . htmlspecialchars($si['siri_name']) . " (" . htmlspecialchars($si['siri_year']) . ")</option>";
                }
                ?>
            </select>
        </div>
        <?php endif; ?>

        <div>
            <label class="form-label">Pilih Sidang</label>
            <select name="session_id" id="sessionSelect" class="pm-select" style="width: 100%;">
                <option value="">-- Sila Pilih Sidang --</option>
                <?php
                if ($filter_siri_id > 0) {
                    $stmt = $conn->prepare("SELECT * FROM sessions WHERE siri_id = ? ORDER BY session_name");
                    $stmt->bind_param('i', $filter_siri_id);
                    $stmt->execute();
                    $sessions = $stmt->get_result();
                } else {
                    $sessions = $conn->query("SELECT * FROM sessions ORDER BY session_name");
                }
                while ($s = $sessions->fetch_assoc()) {
                    echo "<option value='{$s['session_id']}'>" . htmlspecialchars($s['session_name']) . "</option>";
                }
                ?>
            </select>
        </div>

        <div>
            <label class="form-label">Pilih Peringkat</label>
            <select name="level_id" id="levelSelect" class="pm-select" style="width: 100%;" disabled>
                <option value="">-- Sila Pilih Sidang dahulu --</option>
            </select>
        </div>
    </form>
</div>

<div id="testResults"></div>

<script>
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
</script>

</main>
</body>
</html>