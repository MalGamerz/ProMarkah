<?php
session_start();
require __DIR__ . '/auth_check.php';
include 'db.php';
$conn = getDB();

// Ensure only PIC can access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pic') {
    header("Location: login.php");
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Renders one searchable .dd- dropdown (matches the pic_view_marks benchmark).
// A hidden input carries the value; picking an option submits the param form
// so dependent dropdowns rebuild (same cascade behaviour as before).
function renderMMDD(string $fieldName, string $ddName, string $emptyLabel, string $disabledEmptyLabel, array $options, $currentVal, bool $isDisabled) {
    $currentVal    = (string)$currentVal;
    $shownEmpty    = $isDisabled ? $disabledEmptyLabel : $emptyLabel;
    $disabledClass = $isDisabled ? ' dd-trigger-disabled' : '';
    echo "<div class=\"dd-wrap\" id=\"ddWrap_{$ddName}\">";
    echo "<div class=\"dd-trigger{$disabledClass}\" id=\"ddTrigger_{$ddName}\" onclick=\"ddToggle('{$ddName}')\" role=\"button\" tabindex=\"0\" aria-haspopup=\"listbox\">";
    // Even when disabled (e.g. a value that's fixed/derived rather than
    // user-pickable), still show the matching option's label instead of the
    // generic placeholder — disabled only turns off interaction, it
    // shouldn't hide a value that's already known.
    $curLabel = $shownEmpty;
    foreach ($options as $opt) {
        if ((string)$opt['value'] === $currentVal && $currentVal !== '') { $curLabel = $opt['label']; break; }
    }
    $labelColor = ($currentVal === '' || $isDisabled) ? 'color:var(--c-text-faint);' : '';
    echo "<span id=\"ddLabel_{$ddName}\" style=\"{$labelColor}\">" . htmlspecialchars($curLabel) . "</span>";
    echo "<span class=\"dd-arrow\">▼</span></div>";
    echo "<div class=\"dd-panel\" id=\"ddPanel_{$ddName}\" role=\"listbox\">";
    echo "<div class=\"dd-search-box\"><input type=\"text\" placeholder=\"Cari...\" oninput=\"ddFilter('{$ddName}',this.value)\" onclick=\"event.stopPropagation()\"></div>";
    echo "<div class=\"dd-options\" id=\"ddOpts_{$ddName}\">";
    foreach ($options as $opt) {
        $sel    = ((string)$opt['value'] === $currentVal && $currentVal !== '') ? 'selected' : '';
        $valEsc = htmlspecialchars((string)$opt['value'], ENT_QUOTES);
        $lblEsc = htmlspecialchars($opt['label'], ENT_QUOTES);
        echo "<div class=\"dd-opt {$sel}\" role=\"option\" tabindex=\"0\" data-value=\"{$valEsc}\" onclick=\"ddSelect('{$ddName}','{$valEsc}','{$lblEsc}')\">" . htmlspecialchars($opt['label']) . "</div>";
    }
    echo "</div><div class=\"dd-empty\" id=\"ddEmpty_{$ddName}\">Tiada hasil</div></div>";
    echo "<input type=\"hidden\" name=\"{$fieldName}\" id=\"{$ddName}\" value=\"" . htmlspecialchars($currentVal) . "\">";
    echo "</div>";
}

$pm_page = 'manual_marks';
include 'layout.php';
?>

<style> /* ── Searchable dropdown — matches the pic_view_marks benchmark (.dd-) ── */ .context-grid .dd-wrap  {
  position: relative;
}

.context-grid .dd-trigger {
  display:flex;
  align-items:center;
  justify-content:space-between;
  height:46px;
  box-sizing:border-box;
  width:100%;
  padding:0 14px;
  background:var(--c-surface-2);
  border:1px solid var(--c-border-strong);
  border-radius:8px;
  color:var(--c-white);
  font-size:.875rem;
  cursor:pointer;
  user-select:none;
  transition: border-color .2s, box-shadow .2s, background .2s;
}

.context-grid .dd-trigger:hover {
  border-color:var(--c-red);
}

.context-grid .dd-trigger.open {
  border-color:var(--c-red);
  box-shadow:0 0 0 3px var(--c-red-dim);
}

.context-grid .dd-trigger.dd-trigger-disabled {
  opacity:.45;
  cursor:not-allowed;
}

.context-grid .dd-trigger .dd-arrow  {
  color: var(--c-text-faint);
  font-size:0.65rem;
  transition: transform .2s;
}

.context-grid .dd-trigger.open .dd-arrow  {
  transform: rotate(180deg);
}

.context-grid .dd-panel {
  display:none;
  position:absolute;
  top:calc(100% + 6px);
  left:0;
  right:0;
  background:var(--c-surface-2);
  border:1px solid var(--c-red);
  border-radius:8px;
  overflow:hidden;
  z-index:999;
  box-shadow:0 10px 30px rgba(0,0,0,.25);
}

.context-grid .dd-panel.open {
  display:block;
}

.context-grid .dd-search-box  {
  padding:8px;
  border-bottom:1px solid var(--c-border-strong);
}

.context-grid .dd-search-box input  {
  width:100%;
  background:var(--c-surface-0);
  border:1px solid var(--c-border-strong);
  color:var(--c-white);
  border-radius:4px;
  padding:6px 8px;
  font-size:0.8rem;
  outline:none;
  box-sizing:border-box;
  transition:border-color .2s;
}

.context-grid .dd-search-box input:focus  {
  border-color:var(--c-red);
}

.context-grid .dd-search-box input::placeholder  {
  color:var(--c-text-faint);
}

.context-grid .dd-options  {
  max-height:220px;
  overflow-y:auto;
  scrollbar-width:thin;
}

.context-grid .dd-opt  {
  padding:9px 12px;
  font-size:0.85rem;
  color:var(--c-white);
  cursor:pointer;
  transition:background .1s;
}

.context-grid .dd-opt:hover  {
  background:var(--c-surface-3);
}

.context-grid .dd-opt.selected  {
  color:var(--c-red);
  font-weight:600;
}

.context-grid .dd-opt.hidden  {
  display:none;
}

.context-grid .dd-empty  {
  padding:10px 12px;
  color:var(--c-text-faint);
  font-size:0.82rem;
  display:none;
  text-align:center;
}

html.pm-light .context-grid .dd-trigger  {
  background:#f9fafb;
  border-color:#d1d5db;
  color:#111;
}

html.pm-light .context-grid .dd-panel  {
  background:#fff;
  box-shadow:0 6px 20px rgba(0,0,0,0.12);
}

html.pm-light .context-grid .dd-search-box  {
  border-bottom-color:#e5e7eb;
}

html.pm-light .context-grid .dd-search-box input  {
  background:#f9fafb;
  border-color:#d1d5db;
  color:#111;
}

html.pm-light .context-grid .dd-opt  {
  color:#111;
}

html.pm-light .context-grid .dd-opt:hover  {
  background:#f3f4f6;
}

/* ── LAYOUT TWEAKS ── */ .pic-section-header  {
  margin-bottom: 24px;
}

.pic-section-header h2  {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 1.8rem;
  color: var(--c-text);
  letter-spacing: 0.05em;
  margin: 0;
  text-transform: uppercase;
  display: flex;
  align-items: center;
  gap: 8px;
}

.pic-section-sub  {
  color: var(--c-text-faint);
  font-size: 0.85rem;
  margin-top: 4px;
  max-width: 600px;
}

.context-grid  {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 16px;
}

.criteria-row  {
  display: grid;
  grid-template-columns: 1fr 120px 48px;
  gap: 12px;
  align-items: center;
  margin-bottom: 12px;
}

/* ── CUSTOM BUTTONS ── */ .btn-outline  {
  background: transparent;
  border: 1px solid var(--c-border-strong);
  color: var(--c-text);
  padding: 6px 12px;
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-size: 0.8rem;
  font-weight: 500;
  transition: all 0.2s;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  text-decoration: none;
}

.btn-outline:hover  {
  background: var(--c-surface-2);
  border-color: var(--c-text-muted);
}

.btn-delete-row  {
  background: transparent;
  border: 1px solid #fca5a5;
  /* Soft red border */ color: var(--c-red);
  height: 42px;
  width: 100%;
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-size: 1.1rem;
  transition: all 0.2s;
  display: flex;
  align-items: center;
  justify-content: center;
}

.btn-delete-row:hover  {
  background: var(--c-red);
  color: #fff;
  border-color: var(--c-red);
}

.btn-add-row  {
  background: transparent;
  border: 1px solid var(--c-border-strong);
  color: var(--c-text-muted);
  width: 100%;
  padding: 10px;
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-size: 0.85rem;
  font-weight: 600;
  transition: all 0.2s;
  margin-top: 8px;
}

.btn-add-row:hover  {
  background: var(--c-surface-2);
  color: var(--c-text);
}

@media (max-width: 600px)  {
  .criteria-row  {
    grid-template-columns: 1fr 90px 42px;
  }

}

/* ── 1. FIX TABLE SCROLLBAR CLIPPING BORDER ── */
.mm-marks-table-wrap {
    overflow: auto;
    max-width: 100%;
    max-height: 60vh;
    border-radius: var(--radius);
    /* Remove standard border so scrollbar doesn't overlap it */
    border: none; 
    /* Use a sharp box-shadow to fake the border on the outside */
    box-shadow: 0 0 0 1px var(--c-border-strong); 
}

.mm-marks-table  {
  border-collapse: separate;
  border-spacing: 0;
  width: 100%;
}

.mm-marks-table th, .mm-marks-table td  {
  padding: var(--sp-3) var(--sp-4);
  border-bottom: 1px solid var(--c-border);
}

.mm-marks-table thead th  {
  position: sticky;
  top: 0;
  z-index: 2;
  background: var(--c-surface-2);
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .05em;
  color: var(--c-text-faint);
  text-align: center;
  white-space: nowrap;
  border-bottom: 1px solid var(--c-border-strong);
}

.mm-marks-table tbody tr:last-child td  {
  border-bottom: none;
}

.mm-marks-table tbody tr:hover  {
  background: var(--c-surface-2);
}

.mm-marks-table th:first-child, .mm-marks-table td:first-child  {
  position: sticky;
  left: 0;
  z-index: 1;
  background: var(--c-surface-1);
  text-align: left;
  white-space: nowrap;
  box-shadow: 1px 0 0 var(--c-border);
}

.mm-marks-table thead th:first-child  {
  z-index: 3;
}

.mm-marks-table tbody tr:hover td:first-child  {
  background: var(--c-surface-2);
}

html.pm-light .mm-marks-table th:first-child, html.pm-light .mm-marks-table td:first-child  {
  background: #fff;
}

html.pm-light .mm-marks-table tbody tr:hover td:first-child  {
  background: var(--c-gray-100);
}

html.pm-light .mm-marks-table thead th  {
  background: var(--c-gray-100);
}

.mm-cell-name  {
  display: flex;
  align-items: center;
  gap: var(--sp-3);
  font-size: 0.85rem;
  font-weight: 500;
}

.mm-avatar  {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: var(--c-red-dim);
  color: var(--c-red);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.7rem;
  font-weight: 700;
  flex-shrink: 0;
}

/* Deterministic color rotation (by row order) purely for visual scannability in a long roster — carries no semantic meaning. */ .mm-marks-table tbody tr:nth-child(6n+2) .mm-avatar  {
  background: rgba(59,130,246,.15);
  color: #3b82f6;
}

.mm-marks-table tbody tr:nth-child(6n+3) .mm-avatar  {
  background: rgba(168,85,247,.15);
  color: #a855f7;
}

.mm-marks-table tbody tr:nth-child(6n+4) .mm-avatar  {
  background: rgba(217,119,6,.18);
  color: #d97706;
}

.mm-marks-table tbody tr:nth-child(6n+5) .mm-avatar  {
  background: rgba(5,150,105,.15);
  color: #059669;
}

.mm-marks-table tbody tr:nth-child(6n+6) .mm-avatar  {
  background: rgba(219,39,119,.15);
  color: #db2777;
}

.mm-mark-cell  {
  text-align: center;
}

/* ── 2. FIX FUSED INPUT AND BUTTON WIDTHS & GAPS ── */
.mm-cell-inner { 
    display: inline-flex; 
    align-items: stretch; /* Forces all children to exact same height */
    height: 42px;         /* Explicit height */
    box-sizing: border-box;
}

.mm-mark-cell.has-mark .mm-cell-inner {
    border: 1px solid var(--c-border-strong);
    border-radius: var(--radius-sm);
    overflow: hidden;
    background: var(--c-surface-1); /* Fills any micro-gaps */
}

/* Strip global .pm-input styles and force exact widths */
.mm-mark-cell.has-mark .mm-mark-input { 
    border: none !important; 
    border-radius: 0 !important; 
    box-shadow: none !important;
}

.mm-mark-input {
    width: 42px; /* EXACT same width as buttons */
    height: 100%;
    box-sizing: border-box;
    text-align: center;
    font-weight: bold;
    font-size: 1rem;
    padding: 0;
    margin: 0;
    outline: none;
}

.mm-mark-input:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    background: var(--c-surface-3);
    color: var(--c-text);
}

/* Remove default browser number arrows to keep text perfectly centered */
.mm-mark-input::-webkit-outer-spin-button,
.mm-mark-input::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

.mm-mark-input[type=number] {
    -moz-appearance: textfield;
}

.mm-mark-cell.mm-unlocked .mm-cell-inner { 
    border-color: var(--c-red); 
}

.mm-mark-cell.mm-abaied .mm-mark-input {
    opacity: 0.35;
}

/* A judge explicitly marked this criteria "Abai" (skipped) — distinct from
   a plain empty/untouched cell so the PIC can tell the two apart at a
   glance. Still a normal editable input: typing a mark here overrides the
   judge's skip (pic_save_scores.php writes it back as status='scored'). */
.mm-mark-cell.mm-judge-abaikan .mm-mark-input {
    background: var(--c-amber-dim, rgba(217,119,6,0.08));
    border-color: rgba(217,119,6,0.4);
}
.mm-mark-cell.mm-judge-abaikan .mm-mark-input::placeholder {
    color: #d97706;
    font-weight: 600;
    opacity: 1;
}
.mm-judge-abaikan-badge {
    width: 42px;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85rem;
    border-left: 1px solid rgba(217,119,6,0.4);
    background: var(--c-amber-dim, rgba(217,119,6,0.08));
    flex-shrink: 0;
}

.mm-cell-badges { 
    display: flex; 
    align-items: stretch; 
    height: 100%;
    margin: 0;
    padding: 0;
}

.mm-cell-badges button {
    width: 42px; /* EXACT same width as input */
    height: 100%; 
    box-sizing: border-box;
    padding: 0; 
    margin: 0;
    line-height: 1;
    font-size: 0.85rem; 
    border: none; 
    border-left: 1px solid var(--c-border-strong);
    border-radius: 0; 
    background: var(--c-surface-2); 
    color: var(--c-text-muted); 
    cursor: pointer;
    display: flex; 
    align-items: center; 
    justify-content: center;
    transition: all .15s;
}

.mm-cell-badges .mm-unlock-btn:hover { 
    background: var(--c-red-dim); 
    color: var(--c-red); 
}

.mm-cell-badges .mm-abai-btn:hover { 
    background: var(--c-red); 
    color: #fff; 
}

.mm-cell-badges .mm-cancel-btn:hover { 
    background: var(--c-surface-3); 
    color: var(--c-text); 
}

/* ── Mobile: table → stacked cards, one per student, kriteria as label/value rows (data-label supplies the row label via ::before) ── */ @media (max-width: 720px)  {
  .mm-marks-table-wrap  {
    max-height: none;
    overflow: visible;
    border: none;
  }

  .mm-marks-table, .mm-marks-table tbody  {
    display: block;
    width: 100%;
  }

  .mm-marks-table thead  {
    display: none;
  }

  .mm-marks-table tr  {
    display: block;
    background: var(--c-surface-1);
    border: 1px solid var(--c-border-strong);
    border-radius: var(--radius);
    margin-bottom: var(--sp-3);
    padding: var(--sp-1) var(--sp-4);
  }

  .mm-marks-table td  {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--sp-3);
    padding: var(--sp-3) 0;
    border-bottom: 1px solid var(--c-border);
    box-shadow: none;
    position: static;
    background: transparent !important;
  }

  .mm-marks-table tr:last-child td:last-child  {
    border-bottom: none;
  }

  .mm-marks-table td:first-child  {
    border-bottom: 1px solid var(--c-border-strong);
    padding: var(--sp-3) 0;
    /* Overrides the desktop th/td:first-child rule above (nowrap + the 1px sticky-column separator shadow) — on mobile there's no sticky column anymore, so a long name must wrap instead of clipping, and that leftover shadow would otherwise show up as a stray vertical line next to the name. */ white-space: normal;
    align-items: flex-start;
    box-shadow: none;
  }

  .mm-cell-name  {
    align-items: flex-start;
    overflow-wrap: anywhere;
  }

  .mm-mark-cell  {
    text-align: left;
  }

  .mm-mark-cell::before  {
    content: attr(data-label);
    font-size: 0.8rem;
    color: var(--c-text-muted);
    font-weight: 500;
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  /* Same fused pill as desktop, same input width/font sizes — only the control's height and button width grow for a real touch target, the text inside doesn't jump around between breakpoints. */ .mm-cell-inner  {
    height: 44px;
  }

  .mm-cell-badges button  {
    width: 40px;
  }

}

@media (max-width: 480px)  {
  .mm-test-badges  {
    flex-basis: 100%;
    margin-left: 0;
  }

}

/* ── Per-Ujian dropdown (native <details>/<summary>) — one collapsible section per test under the selected Peringkat, each containing its own criteria + mark form for every student in the kumpulan. ── */ .mm-test-accordion  {
  padding: 0;
  margin-bottom: var(--sp-3);
  overflow: hidden;
}

.mm-test-summary  {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  row-gap: var(--sp-2);
  column-gap: var(--sp-3);
  padding: var(--sp-4) var(--sp-5);
  cursor: pointer;
  user-select: none;
  list-style: none;
  font-weight: 600;
  color: var(--c-text);
}

.mm-test-summary::-webkit-details-marker  {
  display: none;
}

.mm-test-summary:hover  {
  background: var(--c-surface-2);
}

/* Arrow + name are grouped together so they never wrap apart from each other — only the badge pills wrap to their own line when space is tight, via .mm-test-badges below. Same gap as .mm-cell-name above: both are an "icon/avatar + label" pattern and should match. */ .mm-test-title-group  {
  display: flex;
  align-items: center;
  gap: var(--sp-3);
  min-width: 0;
}

.mm-test-arrow  {
  color: var(--c-red);
  font-size: 0.75rem;
  display: inline-block;
  transition: transform 0.2s ease;
  flex-shrink: 0;
}

.mm-test-accordion[open] .mm-test-arrow  {
  transform: rotate(90deg);
}

.mm-test-name  {
  font-size: 0.95rem;
}

.mm-test-badges  {
  display: flex;
  align-items: center;
  gap: var(--sp-2);
  flex-wrap: wrap;
  margin-left: auto;
}

.mm-test-count  {
  font-size: 0.7rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--c-text-faint);
  background: rgba(214, 40, 40, 0.08);
  border: 1px solid var(--c-red-border);
  padding: 2px 10px;
  border-radius: var(--radius);
  white-space: nowrap;
}

.mm-test-body  {
  padding: var(--sp-4) var(--sp-5) var(--sp-5);
  border-top: 1px solid var(--c-border);
}

html.pm-light .mm-test-summary:hover  {
  background: var(--c-gray-100);
}

html.pm-light .mm-test-count  {
  background: #fdecec;
  color: var(--c-red-700);
  border-color: var(--c-red-300);
}

html.pm-light .mm-test-body  {
  border-top-color: var(--c-gray-200);
}

/* ── Per-test progress badge (marked cells / total cells) — same size and shape as .mm-test-count, they always appear side by side. ── */ .mm-test-progress  {
  font-size: 0.7rem;
  font-weight: 600;
  padding: 2px 10px;
  border-radius: var(--radius);
  white-space: nowrap;
  border: 1px solid var(--c-border-strong);
  color: var(--c-text-faint);
}

.mm-test-progress.complete  {
  color: #16a34a;
  border-color: rgba(34,197,94,0.4);
  background: rgba(34,197,94,0.1);
}

/* ── Empty state — shown before a kumpulan is chosen ── */ .mm-empty-state  {
  text-align: center;
  padding: var(--sp-10) var(--sp-6);
  color: var(--c-text-faint);
}

.mm-empty-icon  {
  font-size: 2.4rem;
  margin-bottom: var(--sp-3);
}

.mm-empty-state h3  {
  margin: 0 0 6px;
  font-size: 0.95rem;
  color: var(--c-text);
  font-weight: 600;
}

.mm-empty-state p  {
  margin: 0;
  font-size: 0.85rem;
  max-width: 380px;
  margin-inline: auto;
}

/* ── Sticky save bar — stays reachable while scrolling long test lists. Same padding/radius as .mm-test-summary/.pm-card so it reads as part of the same card system instead of a differently-proportioned bar bolted on the end (it previously referenced a --radius-md token that doesn't exist in dashboard.css, so it was silently rendering with square corners while every other container here is rounded). ── */ .mm-save-footer  {
  position: sticky;
  bottom: 0;
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  align-items: center;
  gap: var(--sp-3);
  padding: var(--sp-4) var(--sp-5);
  background: var(--c-surface-1);
  border: 1px solid var(--c-border-strong);
  border-radius: var(--radius);
  box-shadow: 0 -6px 16px rgba(0,0,0,0.15);
  z-index: 5;
}

html.pm-light .mm-save-footer  {
  box-shadow: 0 -6px 16px rgba(0,0,0,0.06);
}

.mm-save-hint  {
  font-size: 0.85rem;
  color: var(--c-text-faint);
  margin-right: auto;
  flex: 1 1 260px;
  min-width: 0;
}

@media (max-width: 560px)  {
  /* At narrow widths, sharing one row with the button squeezed the hint text into a sliver-thin column that wrapped one word per line — give each its own full-width row instead. */ .mm-save-footer  {
    justify-content: center;
  }

  .mm-save-hint  {
    flex-basis: 100%;
    margin-right: 0;
    text-align: left;
  }

  .mm-save-btn  {
    width: 100%;
  }

}

</style>

<div class="pic-section-header">
    <div>
        <h2>✍️ Isi Markah Manual</h2>
        <div class="pic-section-sub">
            Tetapkan parameter dan pastikan markah yang dimasukkan tepat sebelum simpan.
        </div>
    </div>
</div>

<?php if (isset($_GET['msg'])):
    $isError = ($_GET['status'] ?? '') === 'error';
?>
<div class="pm-alert <?= $isError ? 'pm-alert-danger' : 'pm-alert-success' ?>">
    <?= $isError ? '⚠️' : '✅' ?> <?= htmlspecialchars($_GET['msg']) ?>
</div>
<?php endif; ?>

<div class="pm-card">
    <div style="margin-bottom: 20px;">
        <h3 style="margin: 0 0 4px; font-size: 1.1rem; font-weight: 500;">🎯 Pilih Parameter</h3>
        <p style="margin:0; font-size:0.8rem; color:var(--c-text-faint);">Pilih kumpulan — peringkat akan ditetapkan secara automatik.</p>
    </div>

    <?php
    // Peringkat is not an independent choice — every kumpulan already belongs
    // to exactly one peringkat, so it's always derived from the selected
    // group rather than picked separately. Re-resolved from the DB on every
    // request (not just when level_id is missing) so a stale/mismatched
    // level_id can never end up paired with the wrong group.
    $_POST['level_id'] = '';
    if (!empty($_POST['group_id'])) {
        $gid = (int)$_POST['group_id'];
        $gRes = $conn->query("SELECT level_id FROM `groups` WHERE group_id=$gid");
        if ($gRes && $gRes->num_rows > 0) $_POST['level_id'] = $gRes->fetch_assoc()['level_id'];
    }
    ?>

    <form method="POST" id="mmParamForm" class="context-grid">
        <?php
        $active_siri = (int)($_SESSION['active_siri_id'] ?? 0);

        // Kumpulan
        $groupOpts = [];
        $gq = $active_siri > 0
            ? $conn->query("SELECT group_id, group_name FROM `groups` WHERE level_id IN (SELECT level_id FROM levels WHERE session_id IN (SELECT session_id FROM sessions WHERE siri_id = $active_siri)) ORDER BY group_name")
            : $conn->query("SELECT group_id, group_name FROM `groups` ORDER BY group_name");
        while ($g = $gq->fetch_assoc()) $groupOpts[] = ['value' => $g['group_id'], 'label' => $g['group_name']];

        // Peringkat
        $levelOpts = [];
        $lq = $active_siri > 0
            ? $conn->query("SELECT level_id, level_name FROM levels WHERE session_id IN (SELECT session_id FROM sessions WHERE siri_id = $active_siri) ORDER BY level_name")
            : $conn->query("SELECT level_id, level_name FROM levels ORDER BY level_name");
        while ($l = $lq->fetch_assoc()) $levelOpts[] = ['value' => $l['level_id'], 'label' => $l['level_name']];
        ?>
        <div>
            <label style="font-size:0.75rem; color: var(--c-text-faint); margin-bottom:6px; display:block;">Kumpulan</label>
            <?php renderMMDD('group_id', 'mm_group', 'Pilih kumpulan...', 'Pilih kumpulan...', $groupOpts, $_POST['group_id'] ?? '', false); ?>
        </div>

        <div>
            <label style="font-size:0.75rem; color: var(--c-text-faint); margin-bottom:6px; display:block;">Peringkat <span style="text-transform:none;font-weight:400;color:var(--c-text-faint);">(ditetapkan mengikut kumpulan)</span></label>
            <?php
            $levelEmptyLabel = empty($_POST['group_id']) ? 'Pilih kumpulan dahulu...' : 'Tiada peringkat dijumpai';
            renderMMDD('level_id', 'mm_level', $levelEmptyLabel, $levelEmptyLabel, $levelOpts, $_POST['level_id'] ?? '', true);
            ?>
        </div>
    </form>
</div>

<?php if (!empty($_POST['group_id']) && !empty($_POST['level_id'])):
    $group_id_sel = (int)$_POST['group_id'];
    $level_id_sel = (int)$_POST['level_id'];

    $stuRes = $conn->query("SELECT st.student_id, st.student_name FROM group_students gs JOIN students st ON gs.student_id=st.student_id WHERE gs.group_id=$group_id_sel ORDER BY st.student_id");
    $groupStudents = [];
    while ($row = $stuRes->fetch_assoc()) $groupStudents[] = $row;

    // Every ujian under this peringkat — each becomes its own collapsible
    // section below (instead of the PIC picking exactly one Ujian up front),
    // so a whole peringkat's worth of tests can be marked in one visit.
    $levelTests = [];
    $testRes = $conn->query("SELECT test_id, test_name FROM tests WHERE level_id=$level_id_sel ORDER BY sort_order, test_name");
    while ($row = $testRes->fetch_assoc()) $levelTests[] = $row;

    // Criteria + existing marks, fetched per test (not once globally) since
    // each test's criteria are what get shown inside that test's own
    // dropdown section. pic_save_scores.php itself is test-agnostic (it only
    // ever sees student_id/criteria_id pairs), so every test's marks[] below
    // can be submitted together in one shared form/POST.
    $testCriteriaMap  = []; // test_id => [criteria rows]
    $existingMarksMap = []; // test_id => [student_id][criteria_id] => mark
    foreach ($levelTests as $t) {
        $tid = (int)$t['test_id'];
        $critRes = $conn->query("SELECT criteria_id, criteria_name FROM criteria WHERE test_id=$tid ORDER BY sort_order, criteria_name");
        $crit = [];
        while ($row = $critRes->fetch_assoc()) $crit[] = $row;
        $testCriteriaMap[$tid] = $crit;

        $existingMarksMap[$tid] = [];
        if (!empty($crit)) {
            $critIds = array_column($crit, 'criteria_id');
            $placeholders = implode(',', array_fill(0, count($critIds), '?'));
            $types = str_repeat('i', count($critIds) + 1);
            // status distinguishes a judge's real mark ('scored') from one
            // they explicitly opted out of ('abaikan') — both used to be
            // indistinguishable from "nobody's touched this" (no row at
            // all), which is exactly the gap this page needed to close.
            $stmt = $conn->prepare("SELECT student_id, criteria_id, mark, status FROM scores WHERE group_id = ? AND criteria_id IN ($placeholders)");
            $stmt->bind_param($types, $group_id_sel, ...$critIds);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $existingMarksMap[$tid][$row['student_id']][$row['criteria_id']] = [
                    'mark'   => $row['mark'],
                    'status' => $row['status'] ?? 'scored',
                ];
            }
            $stmt->close();
        }
    }
?>

<form action="pic_save_scores.php" method="POST">
    <input type="hidden" name="group_id" value="<?= $group_id_sel ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

    <div class="pm-card" style="margin-bottom:16px;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
            <h3 style="margin: 0; font-size: 1.1rem; font-weight: 500;">Masukkan Markah — Seluruh Kumpulan</h3>
            <div style="display: flex; gap: 8px;">
                <a href="pic.php?section=tests" class="btn-outline">+ Ujian Baru</a>
                <a href="pic.php?section=criteria" class="btn-outline">+ Kriteria Baru</a>
            </div>
        </div>
        <p style="margin:10px 0 0; font-size:0.8rem; color:var(--c-text-faint);">
            🔒 Kriteria yang sudah ada markah dikunci secara automatik supaya tidak tertindih secara tidak sengaja — klik 🔒 untuk buka kunci dan edit. Klik nama ujian di bawah untuk buka/tutup senarai kriterianya.
        </p>
    </div>

    <?php if (empty($groupStudents)): ?>
        <div class="pm-card"><p style="color:var(--c-text-faint); margin:0;">Tiada pelajar dalam kumpulan ini.</p></div>
    <?php elseif (empty($levelTests)): ?>
        <div class="pm-card"><p style="color:var(--c-text-faint); margin:0;">Tiada ujian untuk peringkat ini.</p></div>
    <?php else: ?>
        <?php foreach ($levelTests as $i => $t):
            $tid = (int)$t['test_id'];
            $testCriteria = $testCriteriaMap[$tid];
            $existingMarks = $existingMarksMap[$tid];

            $totalCells = count($groupStudents) * count($testCriteria);
            $markedCells = 0;
            foreach ($groupStudents as $gs) {
                foreach (($existingMarks[(int)$gs['student_id']] ?? []) as $entry) {
                    if ($entry['status'] === 'scored') $markedCells++;
                }
            }
        ?>
        <details class="pm-card mm-test-accordion" <?= $i === 0 ? 'open' : '' ?>>
            <summary class="mm-test-summary">
                <span class="mm-test-title-group">
                    <span class="mm-test-arrow">▶</span>
                    <span class="mm-test-name"><?= htmlspecialchars($t['test_name']) ?></span>
                </span>
                <span class="mm-test-badges">
                    <span class="mm-test-count"><?= count($testCriteria) ?> kriteria</span>
                    <?php if (!empty($testCriteria)): ?>
                    <span class="mm-test-progress<?= $markedCells === $totalCells ? ' complete' : '' ?>"><?= $markedCells ?>/<?= $totalCells ?> markah</span>
                    <?php endif; ?>
                </span>
            </summary>
            <div class="mm-test-body">
                <?php if (empty($testCriteria)): ?>
                    <p style="color:var(--c-text-faint); margin:0;">Tiada kriteria untuk ujian ini.</p>
                <?php else: ?>
                    <div class="mm-marks-table-wrap">
                    <table class="mm-marks-table">
                        <thead>
                            <tr>
                                <th>Pelajar</th>
                                <?php foreach ($testCriteria as $c): ?>
                                <th><?= htmlspecialchars($c['criteria_name']) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($groupStudents as $stu):
                                $sid = (int)$stu['student_id'];
                                $nameParts = preg_split('/\s+/', trim($stu['student_name']));
                                $initials = mb_strtoupper(mb_substr($nameParts[0], 0, 1));
                                if (count($nameParts) > 1) $initials .= mb_strtoupper(mb_substr(end($nameParts), 0, 1));
                            ?>
                            <tr>
                                <td>
                                    <div class="mm-cell-name">
                                        <span class="mm-avatar"><?= htmlspecialchars($initials) ?></span>
                                        <?= htmlspecialchars($stu['student_name']) ?>
                                    </div>
                                </td>
                                <?php foreach ($testCriteria as $c):
                                    $cid = (int)$c['criteria_id'];
                                    $entry = $existingMarks[$sid][$cid] ?? null;
                                    $hasMark = $entry !== null && $entry['status'] === 'scored';
                                    // A judge explicitly skipping a criteria and nobody having
                                    // touched it yet used to look identical (no row at all) —
                                    // this is what actually distinguishes them on screen now.
                                    $isJudgeAbaikan = $entry !== null && $entry['status'] === 'abaikan';
                                    $val = $hasMark ? $entry['mark'] : '';
                                ?>
                                <td class="mm-mark-cell<?= $hasMark ? ' has-mark' : '' ?><?= $isJudgeAbaikan ? ' mm-judge-abaikan' : '' ?>" id="mmRow_<?= $sid ?>_<?= $cid ?>" data-label="<?= htmlspecialchars($c['criteria_name']) ?>">
                                    <div class="mm-cell-inner">
                                        <input type="number" name="marks[<?= $sid ?>][<?= $cid ?>]" class="pm-input mm-mark-input"
                                               min="0" max="10" step="1" inputmode="numeric"
                                               placeholder="<?= $isJudgeAbaikan ? 'Abai' : '–' ?>"
                                               title="<?= $isJudgeAbaikan ? 'Juri menandakan kriteria ini Abai — taip markah untuk menggantikannya' : '' ?>"
                                               value="<?= htmlspecialchars((string)$val) ?>" <?= $hasMark ? 'disabled' : '' ?>>
                                        <?php if ($hasMark): ?>
                                        <div class="mm-cell-badges">
                                            <button type="button" class="mm-unlock-btn" onclick="mmUnlock(this)" title="Markah sedia ada — klik untuk buka kunci dan edit">🔒</button>
                                            <button type="button" class="mm-abai-btn" onclick="mmAbai(this, <?= $sid ?>, <?= $cid ?>)" title="Abaikan — buang markah ini terus">🗑️</button>
                                        </div>
                                        <?php elseif ($isJudgeAbaikan): ?>
                                        <div class="mm-cell-badges">
                                            <span class="mm-judge-abaikan-badge" title="Juri menandakan kriteria ini Abai">⚠️</span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
            </div>
        </details>
        <?php endforeach; ?>

        <div class="mm-save-footer">
            <span class="mm-save-hint">Semak semula sebelum menyimpan — markah yang disimpan akan menggantikan rekod sedia ada.</span>
            <button type="submit" class="pm-btn pm-btn-primary mm-save-btn" style="padding: 10px 24px; font-size: 0.95rem;">
                💾 Simpan Semua Markah
            </button>
        </div>
    <?php endif; ?>
</form>

<?php else: ?>
<div class="pm-card mm-empty-state">
    <div class="mm-empty-icon">🧭</div>
    <h3>Pilih kumpulan untuk mula</h3>
    <p>Peringkat akan ditetapkan secara automatik sebaik sahaja kumpulan dipilih di atas.</p>
</div>
<?php endif; ?>

<script>
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
</script>

</main>
</body>
</html>