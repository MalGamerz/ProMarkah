<?php
ini_set("display_errors", 0);
ini_set("display_startup_errors", 0);
error_reporting(E_ALL);
ini_set("log_errors", 1);

session_start();
require __DIR__ . '/auth_check.php';
include "db.php";
$conn = getDB();

// Add sort_order column to levels if not exists — lets levels be manually
// reordered per session instead of always showing alphabetically.
if (empty($_SESSION['pm_levels_schema_checked'])) {
  $colCheck = $conn->query("SHOW COLUMNS FROM `levels` LIKE 'sort_order'");
  if ($colCheck && $colCheck->num_rows == 0) {
    $conn->query("ALTER TABLE `levels` ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0");
    $conn->query("UPDATE `levels` SET `sort_order` = `level_id`");
  }
  $_SESSION['pm_levels_schema_checked'] = true;
}

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "pic") {
  header("Location: login.php");
  exit();
}

if (empty($_SESSION["csrf_token"])) {
  $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION["csrf_token"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  if (!isset($_POST["csrf_token"]) || !hash_equals($csrf, $_POST["csrf_token"])) {
    header("Location: pic_levels.php?msg=Ralat+token+keselamatan.+Sila+muat+semula+halaman.&status=error");
    exit();
  }
  session_write_close();
  $ok = false;

  try {
  if ($_POST["action"] === "add") {
    $name = trim($_POST["level_name"]);
    if ($name) {
      $sid = (int) $_POST["session_id"];
      $maxOrder = $conn->query("SELECT COALESCE(MAX(sort_order), -1) + 1 AS n FROM levels WHERE session_id = $sid")->fetch_assoc()["n"];
      $stmt = $conn->prepare(
        "INSERT INTO levels (session_id, level_name, sort_order) VALUES (?, ?, ?)",
      );
      $stmt->bind_param("isi", $sid, $name, $maxOrder);
      $ok = $stmt->execute();
      $stmt->close();
      $msg = $ok ? "Peringkat+berjaya+ditambah." : "Ralat+menambah+peringkat.+Sila+cuba+lagi.";
    } else {
      $msg = "Sila+masukkan+nama+peringkat.";
    }
  } elseif ($_POST["action"] === "delete") {
    $lid = (int) $_POST["level_id"];
    $stmt = $conn->prepare("DELETE FROM levels WHERE level_id=?");
    $stmt->bind_param("i", $lid);
    $ok = $stmt->execute();
    $stmt->close();
    $msg = $ok ? "Peringkat+berjaya+dipadam." : "Ralat+memadam+peringkat.+Sila+cuba+lagi.";
  } elseif ($_POST["action"] === "save_all" && (isset($_POST["levels"]) || isset($_POST["order"]))) {
    $ok = true;
    if (isset($_POST["levels"])) {
      $stmt = $conn->prepare("UPDATE levels SET level_name=? WHERE level_id=?");
      foreach ($_POST["levels"] as $id => $l) {
        $name = trim($l["level_name"]);
        $id = (int) $id;
        if ($name) {
          $stmt->bind_param("si", $name, $id);
          if (!$stmt->execute()) { $ok = false; }
        }
      }
      $stmt->close();
    }
    if (isset($_POST["order"])) {
      $stmt = $conn->prepare("UPDATE levels SET sort_order=? WHERE level_id=?");
      foreach ($_POST["order"] as $sessionLevelIds) {
        foreach ($sessionLevelIds as $pos => $lid) {
          $pos = (int) $pos;
          $lid = (int) $lid;
          $stmt->bind_param("ii", $pos, $lid);
          if (!$stmt->execute()) { $ok = false; }
        }
      }
      $stmt->close();
    }
    $msg = $ok ? "Perubahan+berjaya+disimpan." : "Ralat+menyimpan+sebahagian+perubahan.+Sila+semak+dan+cuba+lagi.";
  } else {
    $msg = "Tindakan+tidak+sah.";
  }
  } catch (Throwable $e) {
    promarkah_report('Caught', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    $ok  = false;
    $msg = "Ralat+pangkalan+data.+Sila+cuba+lagi.";
  }
  header("Location: pic_levels.php?msg={$msg}&status=" . ($ok ? "success" : "error"));
  exit();
}

session_write_close();

if (isset($_GET["ajax"])) {
  $search = $_GET["search"] ?? "";
  $sessionFilter = $_GET["session"] ?? "";

  $lWhereArr = ["1=1"];
  $lTypes = "";
  $lVals = [];
  if ($search !== "") {
    $lWhereArr[] = "l.level_name LIKE ?";
    $lTypes .= "s";
    $lVals[] = "%" . $search . "%";
  }
  if ($sessionFilter !== "") {
    $lWhereArr[] = "l.session_id = ?";
    $lTypes .= "i";
    $lVals[] = (int) $sessionFilter;
  }

  // Scope to "Siri Aktif" — only iterate sessions belonging to it.
  $active_siri = (int) ($_SESSION["active_siri_id"] ?? 0);
  if ($active_siri > 0) {
    $st_sess = $conn->prepare("SELECT se.*, si.siri_name FROM sessions se LEFT JOIN siri si ON se.siri_id = si.siri_id WHERE se.siri_id = ? ORDER BY se.session_name");
    $st_sess->bind_param("i", $active_siri);
    $st_sess->execute();
    $sessions = $st_sess->get_result();
  } else {
    $sessions = $conn->query("SELECT se.*, si.siri_name FROM sessions se LEFT JOIN siri si ON se.siri_id = si.siri_id ORDER BY se.session_name");
  }
  $found = false;

  while ($sess = $sessions->fetch_assoc()) {
    $sid = $sess["session_id"];

    $iterArr = array_merge($lWhereArr, ["l.session_id = ?"]);
    $iterSql = implode(" AND ", $iterArr);
    $iterTypes = $lTypes . "i";
    $iterVals = array_merge($lVals, [$sid]);

    $stmt = $conn->prepare(
      "SELECT l.* FROM levels l WHERE $iterSql ORDER BY l.sort_order, l.level_name",
    );
    $stmt->bind_param($iterTypes, ...$iterVals);
    $stmt->execute();
    $levels = $stmt->get_result();
    $stmt->close();

    if ($levels->num_rows == 0) {
      continue;
    }
    $found = true;

    $siriLabel = $sess["siri_name"] ? htmlspecialchars($sess["siri_name"]) : "Tiada Siri";
    echo "<div class='accordion-card'>
                <div class='level-header' onclick=\"toggleBlock('session_$sid', this)\" role='button' tabindex='0'>
                    <span class='acc-icon'>&#9658;</span>
                    <strong>" .
      htmlspecialchars($sess["session_name"]) .
      "</strong>
                    <span class='siri-label'>$siriLabel</span>
                    <span class='count-badge'>{$levels->num_rows} Peringkat</span>
                </div>
                <div id='session_$sid' class='acc-body' style='display:none;'>
                    <div class='table-responsive'>
                        <table class='tests-table' data-session='$sid'>
                            <thead>
                                <tr>
                                    <th style='width:36px;'></th>
                                    <th style='width:50px; text-align:center;'>NO</th>
                                    <th>NAMA PERINGKAT</th>
                                    <th style='width:100px; text-align:center;'>TINDAKAN</th>
                                </tr>
                            </thead>
                            <tbody>";
    $no = 1;
    while ($l = $levels->fetch_assoc()) {
      $lid = $l["level_id"];
      $safeName = htmlspecialchars($l["level_name"], ENT_QUOTES);

      // Inline styles to exactly match the white red-bordered 'Padam' button from your image
      echo "<tr data-id='$lid' draggable='true'>
                    <td class='col-drag' style='text-align:center; cursor:grab; color:var(--c-text-faint);'>&#9776;</td>
                    <td class='col-no' style='text-align:center; color:var(--c-text-faint);'>$no</td>
                    <td><input name='levels[$lid][level_name]' value='$safeName' data-orig='$safeName' class='level-input'></td>
                    <td class='col-actions' style='text-align:center;'>
                        <button type='button' class='pm-btn btn-sm' style='padding:5px 12px; font-size:0.78rem; background:#fff; color:var(--c-red); border:1px solid var(--c-red-300); border-radius:4px;' onclick=\"delRow('$lid')\">Padam</button>
                    </td>
                  </tr>";
      $no++;
    }
    echo "          </tbody>
                        </table>
                    </div>
                </div>
              </div>";
  }

  if (!$found) {
    echo "<p style='text-align:center;padding:30px;color:var(--c-text-faint);'>Tiada peringkat ditemui untuk carian ini.</p>";
  }
  exit();
}

$pm_page = "levels";
include "layout.php";
?>

<style>
    /* ── Filter card ── */
    .filter-card{
        background:var(--c-surface-1);
        border:1px solid var(--c-border-strong);
        border-radius:12px;
        padding:20px;
        margin-bottom:24px;
        box-shadow:0 4px 12px rgba(0,0,0,.08);
    }

    .filter-card-title{
        font-size:.72rem;
        font-weight:700;
        text-transform:uppercase;
        letter-spacing:.12em;
        color:var(--c-text-faint);
        margin-bottom:16px;
    }

    .pic-filter-bar{
        display:flex; /* Same flex-equal-share layout as leaderboard.php's
           .filter-bar/.filter-col and pic_directory.php's .dir-filter —
           every column gets an identical flex-basis of 0 so widths stay
           equal, instead of the old grid's minmax(Npx,1fr) tracks which
           differed page to page. */
        flex-wrap:wrap;
        gap:8px;
        align-items:flex-end;
    }

    .pic-filter-bar > div{
        flex:1 1 0;
        min-width:110px;
    }
    @media (max-width: 640px) {
        .pic-filter-bar { gap: 10px; }
        .pic-filter-bar > div { flex: 1 1 100%; min-width: 0; }
    }
    @media (min-width: 641px) and (max-width: 1024px) {
        .pic-filter-bar > div { flex: 1 1 calc(50% - 8px); }
    }

    .pic-filter-bar label{
        display:block;
        margin-bottom:6px;
        font-size:.75rem;
        font-weight:600;
        text-transform:uppercase;
        letter-spacing:.08em;
        color:var(--c-text-faint);
    }
    .pic-filter-bar input,
    .pic-filter-bar select {
        background: var(--c-surface-2);
        border: 1px solid var(--c-border-strong);
        color: var(--c-white);
        border-radius: 6px;
        padding: 8px 10px;
        font-size: 0.8rem; /* matches the app-wide 32px/0.8rem filter-bar benchmark */
        outline: none;
        width: 100%;
        transition: border-color .2s;
        box-sizing: border-box;
    }
    .pic-filter-bar input:focus,
    .pic-filter-bar select:focus { border-color: var(--c-red); }
    .pic-filter-bar select option { background: var(--c-surface-2); color: var(--c-white); }
    .pic-filter-bar input::placeholder { color: var(--c-text-faint); }

    /* ── Add card ── */
    .pic-add-card {
        display: none;
        background: var(--c-surface-1);
        border: 1px solid var(--c-red-border);
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 18px;
    }
    .pic-add-card h3 {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 1.1rem;
        color: var(--c-white);
        margin-bottom: 14px;
        letter-spacing: 0.05em;
    }
    .pic-add-form {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 10px;
        align-items: end;
    }
    .pic-add-form input,
    .pic-add-form select {
        background: var(--c-surface-2);
        border: 1px solid var(--c-border-strong);
        color: var(--c-white);
        border-radius: 6px;
        padding: 9px 12px;
        font-size: 0.875rem;
        outline: none;
        width: 100%;
        transition: border-color .2s;
    }
    .pic-add-form input:focus,
    .pic-add-form select:focus { border-color: var(--c-red); box-shadow: 0 0 0 3px var(--c-red-dim); }
    .pic-add-form select option { background: var(--c-surface-2); }
    .pic-add-form label {
        display: block;
        font-size: 0.75rem;
        color: var(--c-text-faint);
        margin-bottom: 4px;
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 0.07em;
    }
    /* Button in the add-form grid was shorter than the input/select next to
       it (pm-btn's tight line-height vs. the input's padded box) — pin both
       to the same height so the row looks uniform. */
    .pic-add-form button.pm-btn {
        height: 40px;
        padding: 0 16px;
        box-sizing: border-box;
    }

    /* ── Main card & Accordions ── */
    .tests-card {
        background: var(--c-surface-1);
        border: 1px solid var(--c-border-strong);
        border-radius: 10px;
        overflow: hidden;
        position: relative;
        margin-top: 20px;
    }

    /* Save bar — always visible */
    .tests-save-bar {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 16px;
        background: var(--c-surface-2);
        border-bottom: 2px solid var(--c-border-strong);
        position: sticky;
        top: 0;
        z-index: 10;
        transition: border-bottom-color .25s;
    }
    .tests-save-bar.dirty {
        border-bottom-color: var(--c-red);
    }
    .tests-save-bar .save-msg { font-size: 0.82rem; color: var(--c-text-faint); flex: 1; }
    .tests-save-bar .save-msg strong { color: var(--c-white); }
    /* Box styling now comes from the shared .pm-btn.pm-btn-ghost classes on
       the element itself, matching Simpan's box model instead of a bespoke
       one-off with no visible border (unlike Simpan's). */
    .tests-save-bar .btn-discard {
        display: none;
    }
    .tests-save-bar.dirty .btn-discard { display: inline-flex; }

    /* Scrollable Container — height set dynamically in JS
       (fitLevelsListHeight) so it always leaves room for the pagination bar
       below it instead of a static max-height that goes stale whenever the
       filter UI above the list changes height. */
    .tests-table-wrap {
        overflow-y: auto;
        overflow-x: hidden;
        scrollbar-width: thin;
        scrollbar-color: var(--c-surface-3) transparent;
        padding: 12px;
    }
    @media (max-width: 640px) {
        .tests-table-wrap { max-height: none !important; overflow-y: visible !important; }
    }

    /* Accordion Style */
    .accordion-card {
        background: var(--c-surface-1);
        border: 1px solid var(--c-border);
        border-radius: 8px;
        margin-bottom: 8px;
        overflow: hidden;
        transition: border-color 0.2s;
    }
    .accordion-card.has-changes {
        border-color: var(--c-red);
        box-shadow: 0 0 0 1px var(--c-red);
    }
    /* Display every peringkat name in caps — accordion titles and the
       editable name field — purely visual, the stored value keeps
       whatever case was typed. */
    .level-header strong,
    .tests-table input {
        text-transform: uppercase;
    }
    .level-header {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 16px;
        background: var(--c-surface-2);
        cursor: pointer;
        color: var(--c-white);
        font-weight: 600;
        font-size: 0.92rem;
        transition: background .15s;
    }
    .level-header:hover { background: var(--c-surface-3); }
    .level-header .acc-icon {
        color: var(--c-text-muted);
        font-size: 0.8rem;
        transition: transform .2s ease;
        display: inline-block;
    }
    .level-header .siri-label {
        margin-left: 10px;
        font-size: 0.72rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--c-text-faint);
        background: rgba(214, 40, 40, 0.08);
        border: 1px solid var(--c-red-border);
        padding: 2px 8px;
        border-radius: 10px;
    }
    .level-header .count-badge {
        margin-left: auto;
        background: var(--c-surface-0);
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.75rem;
        color: var(--c-text-muted);
        border: 1px solid var(--c-border);
    }

    /* ── Table inside Accordion ── */
    .table-responsive { width: 100%; overflow-x: auto; }
    .tests-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.875rem;
        min-width: 400px;
    }
    .tests-table thead th {
        background: var(--c-surface-3);
        color: var(--c-text-muted);
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        padding: 10px 14px;
        text-align: left;
        white-space: nowrap;
        border-bottom: 1px solid var(--c-border-strong);
    }
    .tests-table td {
        padding: 8px 14px;
        color: var(--c-text-muted);
        background: var(--c-surface-1);
        border-bottom: 1px solid var(--c-border);
        vertical-align: middle;
        transition: background .1s;
    }
    .tests-table tbody tr:last-child td { border-bottom: none; }
    .tests-table tbody tr:hover td { background: var(--c-surface-2); }
    .tests-table tbody tr.row-dirty td { background: rgba(214, 40, 40, 0.06) !important; }
    .tests-table tbody tr.dragging { opacity: 0.4; }
    .tests-table td.col-drag { width: 36px; cursor: grab; user-select: none; }
    .tests-table td.col-drag:active { cursor: grabbing; }
    .tests-table td.col-no { width: 50px; }
    .tests-table td.col-actions { width: 100px; white-space: nowrap; }
    .tests-table input {
        background: var(--c-surface-0) !important;
        border: 1px solid var(--c-border-strong) !important;
        color: var(--c-white) !important;
        border-radius: 4px !important;
        padding: 5px 8px !important;
        font-size: 0.82rem !important;
        outline: none !important;
        width: 100%;
        transition: border-color .2s;
    }
    .tests-table input:focus { border-color: var(--c-red) !important; }

    #ajaxSpinner { display: none; padding: 30px; text-align: center; color: var(--c-text-faint); margin: 0; }

    /* ── PAGINATION: shared .vm-pagination styles now live once in
       dashboard.css (loaded by layout.php), used by every paginated page. ── */

    /* ── LIGHT MODE ── */
    html.pm-light .pic-section-header h2 { color: #111; }
    html.pm-light .pic-section-sub { color: #555; }
    html.pm-light .filter-card { background: #fff; border-color: var(--c-gray-200); border-top-color: var(--c-red); box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
    html.pm-light .filter-card-title { color: #888; }
    html.pm-light .pic-filter-bar label { color: #555; }
    html.pm-light .pic-filter-bar input, html.pm-light .pic-filter-bar select { background: var(--c-gray-50); border-color: var(--c-gray-300); color: #111; }
    html.pm-light .pic-filter-bar input::placeholder { color: var(--c-gray-400); }
    html.pm-light .pic-filter-bar select option { background: #fff; color: #111; }
    html.pm-light .pic-add-card { background: #fff; border-color: var(--c-red-border); }
    html.pm-light .pic-add-card h3 { color: #111; }
    html.pm-light .pic-add-form label { color: #555; }
    html.pm-light .pic-add-form input, html.pm-light .pic-add-form select { background: var(--c-gray-50); border-color: var(--c-gray-300); color: #111; }
    html.pm-light .pic-add-form select option { background: #fff; }
    html.pm-light .tests-card { background: #fff; border-color: var(--c-gray-200); }
    html.pm-light .tests-save-bar { background: var(--c-gray-100); border-bottom-color: var(--c-gray-200); }
    html.pm-light .tests-save-bar.dirty { border-bottom-color: var(--c-red); }
    html.pm-light .tests-save-bar .save-msg { color: #555; }
    html.pm-light .tests-save-bar .save-msg strong { color: #111; }
    html.pm-light .accordion-card { background: #fff; border-color: var(--c-gray-200); }
    html.pm-light .level-header { background: var(--c-gray-50); color: #111; }
    html.pm-light .level-header:hover { background: var(--c-gray-100); }
    html.pm-light .level-header .count-badge { background: #fff; color: #555; border-color: var(--c-gray-300); }
    html.pm-light .level-header .siri-label { background: #fdecec; color: var(--c-red-700); border-color: var(--c-red-300); }
    html.pm-light .tests-table thead th { background: var(--c-gray-700); color: var(--c-gray-50); border-bottom-color: var(--c-gray-200); }
    html.pm-light .tests-table td { background: #fff; color: #222; border-bottom-color: var(--c-gray-200); }
    html.pm-light .tests-table tbody tr:hover td { background: var(--c-gray-50); }
    html.pm-light .tests-table tbody tr.row-dirty td { background: rgba(214, 40, 40, 0.04) !important; }
    html.pm-light .tests-table input { background: #fff !important; border-color: var(--c-gray-300) !important; color: #111 !important; }
    html.pm-light .tests-table input:focus { border-color: var(--c-red) !important; box-shadow: 0 0 0 2px var(--c-red-dim) !important; }

    /* .dd-wrap / .dd-trigger / .dd-panel etc. now come from the shared
       filter_bar.css (loaded globally via layout.php) so this page's
       filter dropdowns match pic_view_marks.php / silibus.php /
       leaderboard.php / judge_view_marks.php exactly instead of drifting
       with their own local size. */
</style>

<form id='saveAllForm' method='POST' style='display:none;'>
    <input type='hidden' name='action' value='save_all'>
    <input type='hidden' name='csrf_token' value='<?= htmlspecialchars($csrf) ?>'>
</form>
<form id='delFrm' method='POST' style='display:none;'>
    <input type='hidden' name='action' value='delete'>
    <input type='hidden' name='level_id' value=''>
    <input type='hidden' name='csrf_token' value='<?= htmlspecialchars($csrf) ?>'>
</form>

<div class='pic-section-header'>
    <div>
        <h2>🎯 Pengurusan Peringkat</h2>
        <div class='pic-section-sub'>Kawal selia dan tambah peringkat mengikut sidang</div>
    </div>
    <button type='button' class='pm-btn pm-btn-primary' onclick="toggleAddCard()">+ Tambah Peringkat</button>
</div>

<?php if (isset($_GET['msg'])):
    $isError = ($_GET['status'] ?? '') === 'error';
    // Shown as a floating toast (spawnPmToast, shared in layout.php), not an
    // inline block at the top of the page — the accordion open/scroll
    // restore below already scrolls back to whichever card was being
    // edited, so a static banner up here would be scrolled out of view.
?>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        spawnPmToast(<?= json_encode($_GET['msg']) ?>, <?= $isError ? 'true' : 'false' ?>);
    });
</script>
<?php endif; ?>

<div class="filter-card">
    <div class="filter-card-title">
        Penapis Peringkat
    </div>

    <div class="pic-filter-bar">

        <div>
            <label>Pilih Sidang</label>
            <div class="dd-wrap" id="ddWrap_session">
                <div class="dd-trigger" id="ddTrigger_session" onclick="ddToggle('session')" role="button" tabindex="0" aria-haspopup="listbox">
                    <span id="ddLabel_session" style="color:var(--c-text-faint);">
                        -- Semua Sidang --
                    </span>
                    <span class="dd-arrow">▼</span>
                </div>

                <div class="dd-panel" id="ddPanel_session" role="listbox">
                    <div class="dd-search-box">
                        <input type="text" placeholder="Cari sidang..." oninput="ddFilter('session',this.value)" onclick="event.stopPropagation()">
                    </div>

                    <div class="dd-options" id="ddOpts_session">
                        <div class="dd-opt selected" data-value="" role="option" tabindex="0" onclick="ddSelect('session','','-- Semua Sidang --')">
                            -- Semua Sidang --
                        </div>
                        <?php
                        // Scope to "Siri Aktif"; tag sessions with their siri when
                        // "Semua Siri" is active so same-named ones aren't ambiguous.
                        $active_siri_main = (int) ($_SESSION["active_siri_id"] ?? 0);
                        $sessions = $active_siri_main > 0
                          ? $conn->query("SELECT * FROM sessions WHERE siri_id = $active_siri_main ORDER BY session_name")
                          : $conn->query("SELECT se.*, si.siri_name FROM sessions se LEFT JOIN siri si ON se.siri_id = si.siri_id ORDER BY se.session_name");
                        while ($s = $sessions->fetch_assoc()) {
                          $id = $s["session_id"];
                          $name = htmlspecialchars($s["session_name"]);
                          if ($active_siri_main === 0 && !empty($s["siri_name"])) {
                            $name .= " — " . htmlspecialchars($s["siri_name"]);
                          }
                          echo "<div class='dd-opt' role='option' tabindex='0' data-value='{$id}' onclick=\"ddSelect('session','{$id}','{$name}')\">{$name}</div>";
                        }
                        ?>
                    </div>
                    <div class="dd-empty" id="ddEmpty_session">Tiada hasil</div>
                </div>
                <input type="hidden" id="f_session" value="">
            </div>
        </div>

        <div>
            <label>Cari Nama Peringkat</label>
            <div class="dd-wrap" id="ddWrap_search">
                <div class="dd-trigger" id="ddTrigger_search" onclick="ddToggle('search')" role="button" tabindex="0" aria-haspopup="listbox">
                    <span id="ddLabel_search" style="color:var(--c-text-faint);">
                        -- Semua Peringkat --
                    </span>
                    <span class="dd-arrow">▼</span>
                </div>

                <div class="dd-panel" id="ddPanel_search" role="listbox">
                    <div class="dd-search-box">
                        <input type="text" id="f_search" placeholder="Taip nama peringkat..." oninput="ddSearchInput(this.value)" onclick="event.stopPropagation()">
                    </div>

                    <div class="dd-options" id="ddOpts_search">
                        <div class="dd-opt selected" data-value="" role="option" tabindex="0" onclick="ddSelect('search','','-- Semua Peringkat --')">
                            -- Semua Peringkat --
                        </div>
                        <?php
                        $dist_levels = $conn->query("SELECT DISTINCT level_name FROM levels ORDER BY level_name");
                        while ($dl = $dist_levels->fetch_assoc()) {
                          $name = htmlspecialchars($dl["level_name"]);
                          echo "<div class='dd-opt' role='option' tabindex='0' data-value='{$name}' onclick=\"ddSelect('search','{$name}','{$name}')\">{$name}</div>";
                        }
                        ?>
                    </div>
                    <div class="dd-empty" id="ddEmpty_search">Tiada hasil</div>
                </div>
            </div>
        </div>

    </div>
</div>

<div id='addLevel' class='pic-add-card'>
    <h3>+ Tambah Peringkat Baru</h3>
    <form method='POST' class='pic-add-form' onsubmit="rememberOpenAccordions()">
        <input type='hidden' name='action' value='add'>
        <input type='hidden' name='csrf_token' value='<?= htmlspecialchars($csrf) ?>'>
        <div>
            <label>Pilih Sidang</label>
            <select name='session_id' required>
                <?php
                $sessions = $active_siri_main > 0
                  ? $conn->query("SELECT * FROM sessions WHERE siri_id = $active_siri_main ORDER BY session_name")
                  : $conn->query("SELECT se.*, si.siri_name FROM sessions se LEFT JOIN siri si ON se.siri_id = si.siri_id ORDER BY se.session_name");
                while ($s = $sessions->fetch_assoc()) {
                  $optLabel = htmlspecialchars($s["session_name"]);
                  if ($active_siri_main === 0 && !empty($s["siri_name"])) {
                    $optLabel .= " — " . htmlspecialchars($s["siri_name"]);
                  }
                  echo "<option value='{$s["session_id"]}'>$optLabel</option>";
                }
                ?>
            </select>
        </div>
        <div>
            <label>Nama Peringkat</label>
            <input name='level_name' placeholder='Contoh: Ujian Awan Putih Cula Hijau 1' required>
        </div>
        <div style='display:flex;align-items:flex-end;'>
            <button class='pm-btn pm-btn-primary' style="width: 100%;">Tambah Peringkat</button>
        </div>
    </form>
</div>

<div class='tests-card'>
    <div class='tests-save-bar' id='saveDirtyBar'>
        <span class='save-msg' id='saveMsg'>Tiada perubahan</span>
        <button type='button' class='pm-btn pm-btn-ghost btn-sm btn-discard' style='padding:5px 12px;' onclick="discardAll()">Batal</button>
        <button type='button' class='pm-btn pm-btn-primary btn-sm' style='padding:5px 12px;' onclick="submitSaveAll()">💾 Simpan Semua</button>
    </div>

    <div class='tests-table-wrap' id='levelList'>
        <p style='text-align:center;padding:30px;color:var(--c-text-faint);'>Memuatkan...</p>
    </div>
    <div id='ajaxSpinner'>⏳ Mencari...</div>

    <div class="vm-pagination" id="levelsPaginationContainer" style="display:none;">
        <div class="vm-page-info" id="levelsPageInfo"></div>
        <div class="vm-page-btns" id="levelsPaginationButtons"></div>
    </div>
</div>

<script>
let _t = null;
let _dirtyRows = new Set();
let _orderDirty = false;

function ajaxFilter() {
    clearTimeout(_t);
    _t = setTimeout(_load, 400);
}

function _load() {
    const params = new URLSearchParams({
        ajax: '1',
        search: document.getElementById('f_search').value,
        session: document.getElementById('f_session').value
    });

    const wrapper = document.getElementById('levelList');
    const spinner = document.getElementById('ajaxSpinner');

    wrapper.style.display = 'none';
    spinner.style.display = 'block';

    pmFetch('pic_levels.php?' + params)
        .then(r => r.text())
        .then(html => {
            wrapper.innerHTML = html;
            wrapper.style.display = 'block';
            spinner.style.display = 'none';
            _dirtyRows.clear();
            _orderDirty = false;
            updateDirtyBar();
            attachListeners();
            levelsCurrentPage = 1;
            updateLevelsPagination();
            fitLevelsListHeight();
            restoreOpenAccordions();
        })
        .catch(err => {
            console.error(err);
            wrapper.style.display = 'block';
            spinner.style.display = 'none';
        });
}

// ── PAGINATION (client-side, 20 session-accordions per page) ──
let levelsCurrentPage = 1;
const levelsPerPage = 20;

function updateLevelsPagination() {
    const cards = Array.from(document.querySelectorAll('#levelList > .accordion-card'));
    const container = document.getElementById('levelsPaginationContainer');
    const info = document.getElementById('levelsPageInfo');
    const btns = document.getElementById('levelsPaginationButtons');

    if (cards.length === 0) { container.style.display = 'none'; return; }

    const total = cards.length;
    const totalPages = Math.max(1, Math.ceil(total / levelsPerPage));
    if (levelsCurrentPage > totalPages) levelsCurrentPage = totalPages;
    if (levelsCurrentPage < 1) levelsCurrentPage = 1;

    container.style.display = totalPages <= 1 ? 'none' : 'flex';

    const start = (levelsCurrentPage - 1) * levelsPerPage;
    const end   = start + levelsPerPage;
    cards.forEach((c, i) => { c.style.display = (i >= start && i < end) ? '' : 'none'; });

    const s = start + 1;
    const e = Math.min(end, total);
    info.innerHTML = `Memaparkan <b>${s}–${e}</b> daripada <b>${total}</b> sidang`;

    pmRenderPagination(btns, levelsCurrentPage, totalPages, levelsGoToPage);
}

function levelsGoToPage(page) {
    levelsCurrentPage = page;
    updateLevelsPagination();
}

// ── Fit the levels list + pagination into the viewport, no page scroll ──
function fitLevelsListHeight() {
    const scrollEl = document.getElementById('levelList');
    const pagination = document.getElementById('levelsPaginationContainer');
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
window.addEventListener('resize', fitLevelsListHeight);

function attachListeners() {
    document.querySelectorAll('.level-input').forEach(el => {
        if (el.dataset.listening) return;
        el.dataset.listening = '1';
        el.addEventListener('input', onInputChange);
    });
    attachDragListeners();
}

// ── Drag-and-drop reordering (per session tbody) ──
let _dragRow = null;

function attachDragListeners() {
    document.querySelectorAll('.tests-table tbody tr').forEach(row => {
        if (row.dataset.dragListening) return;
        row.dataset.dragListening = '1';
        row.addEventListener('dragstart', e => {
            _dragRow = row;
            row.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });
        row.addEventListener('dragend', () => {
            row.classList.remove('dragging');
            _dragRow = null;
        });
        row.addEventListener('dragover', e => {
            e.preventDefault();
            const tbody = row.parentElement;
            if (!_dragRow || _dragRow.parentElement !== tbody || _dragRow === row) return;
            const rect = row.getBoundingClientRect();
            const before = (e.clientY - rect.top) < rect.height / 2;
            tbody.insertBefore(_dragRow, before ? row : row.nextSibling);
        });
        row.addEventListener('drop', e => {
            e.preventDefault();
            renumberTable(row.closest('table'));
            markOrderDirty(row.closest('.accordion-card'));
        });
    });
}

function renumberTable(table) {
    table.querySelectorAll('tbody tr').forEach((row, i) => {
        const cell = row.querySelector('.col-no');
        if (cell) cell.textContent = i + 1;
    });
}

function markOrderDirty(accCard) {
    _orderDirty = true;
    if (accCard) accCard.classList.add('has-changes');
    updateDirtyBar();
}

function onInputChange(e) {
    const row = e.target.closest('tr');
    if (!row) return;
    const id = row.dataset.id;
    const orig = e.target.dataset.orig ?? '';
    const accCard = e.target.closest('.accordion-card');

    if (e.target.value !== orig) {
        _dirtyRows.add(id);
        row.classList.add('row-dirty');
        if(accCard) accCard.classList.add('has-changes');
    } else {
        _dirtyRows.delete(id);
        row.classList.remove('row-dirty');
        if(accCard && accCard.querySelectorAll('.row-dirty').length === 0) {
            accCard.classList.remove('has-changes');
        }
    }
    updateDirtyBar();
}

function updateDirtyBar() {
    const bar = document.getElementById('saveDirtyBar');
    const msg = document.getElementById('saveMsg');
    const total = _dirtyRows.size + (_orderDirty ? 1 : 0);
    bar.classList.toggle('dirty', total > 0);
    if (total === 0) {
        msg.innerHTML = 'Tiada perubahan';
    } else if (_dirtyRows.size > 0 && _orderDirty) {
        msg.innerHTML = `Ada <strong>${_dirtyRows.size}</strong> perubahan &amp; <strong>susunan</strong> belum disimpan`;
    } else if (_orderDirty) {
        msg.innerHTML = `Ada <strong>susunan</strong> belum disimpan`;
    } else {
        msg.innerHTML = `Ada <strong>${_dirtyRows.size}</strong> perubahan belum disimpan`;
    }
}

// ── Keep the accordion(s) the PIC had open across the save-triggered page
// reload (action=save_all redirects the whole page) instead of snapping
// everything back to collapsed. ──
const PM_OPEN_KEY = 'pm_levels_open_accordions';

function rememberOpenAccordions() {
    const openIds = Array.from(document.querySelectorAll('.acc-body'))
        .filter(el => el.style.display === 'block')
        .map(el => el.id);
    sessionStorage.setItem(PM_OPEN_KEY, JSON.stringify(openIds));
}

function restoreOpenAccordions() {
    let openIds = [];
    try { openIds = JSON.parse(sessionStorage.getItem(PM_OPEN_KEY) || '[]'); } catch (e) {}
    sessionStorage.removeItem(PM_OPEN_KEY);
    openIds.forEach(id => {
        const bodyEl = document.getElementById(id);
        if (!bodyEl) return;
        bodyEl.style.display = 'block';
        const iconEl = bodyEl.previousElementSibling?.querySelector('.acc-icon');
        if (iconEl) iconEl.style.transform = 'rotate(90deg)';
    });
}

function submitSaveAll() {
    rememberOpenAccordions();
    const form = document.getElementById('saveAllForm');
    form.querySelectorAll('.dyn-input').forEach(el => el.remove());
    document.querySelectorAll('.level-input').forEach(el => {
        const h = document.createElement('input');
        h.type = 'hidden';
        h.name = el.name;
        h.value = el.value;
        h.className = 'dyn-input';
        form.appendChild(h);
    });
    if (_orderDirty) {
        document.querySelectorAll('.tests-table').forEach(table => {
            const sid = table.dataset.session;
            table.querySelectorAll('tbody tr').forEach((row, i) => {
                const h = document.createElement('input');
                h.type = 'hidden';
                h.name = `order[${sid}][${i}]`;
                h.value = row.dataset.id;
                h.className = 'dyn-input';
                form.appendChild(h);
            });
        });
    }
    form.submit();
}

function discardAll() {
    document.querySelectorAll('.level-input').forEach(el => {
        if (el.dataset.orig !== undefined) el.value = el.dataset.orig;
        el.closest('tr')?.classList.remove('row-dirty');
    });
    document.querySelectorAll('.accordion-card').forEach(acc => {
        acc.classList.remove('has-changes');
    });
    _dirtyRows.clear();
    _orderDirty = false;
    updateDirtyBar();
    _load();
}

function delRow(id) {
    if (!confirm('Padam peringkat ini? Amaran: Semua data berkaitan dengan peringkat ini mungkin terpadam.')) return;
    rememberOpenAccordions();
    document.getElementById('delFrm').querySelector('[name=level_id]').value = id;
    document.getElementById('delFrm').submit();
}

function toggleAddCard() {
    const el = document.getElementById('addLevel');
    el.style.display = (el.style.display === 'block') ? 'none' : 'block';
    if(el.style.display === 'block'){
        setTimeout(() => { el.querySelector('input[name="level_name"]').focus(); }, 50);
    }
}

function toggleBlock(id, headerEl) {
    const bodyEl = document.getElementById(id);
    const iconEl = headerEl.querySelector('.acc-icon');
    if (bodyEl) {
        if (bodyEl.style.display === 'none' || bodyEl.style.display === '') {
            bodyEl.style.display = 'block';
            if (iconEl) iconEl.style.transform = 'rotate(90deg)';
            // Scroll the newly-opened body fully into view within the
            // scrollable list wrapper — otherwise its bottom rows stay
            // clipped by the fixed max-height until the user scrolls.
            requestAnimationFrame(() => {
                bodyEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            });
        } else {
            bodyEl.style.display = 'none';
            if (iconEl) iconEl.style.transform = 'rotate(0deg)';
        }
    }
}

function ddToggle(name) {
    const panel = document.getElementById('ddPanel_' + name);
    const trigger = document.getElementById('ddTrigger_' + name);
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
        if(m) any=true; 
    });
    if(empty) empty.style.display = any ? 'none' : 'block';
}

function ddSelect(name, value, label) {
    if (name === 'session') document.getElementById('f_session').value = value;
    else if (name === 'search') document.getElementById('f_search').value = value;

    const lbl = document.getElementById('ddLabel_' + name);
    lbl.textContent = label;
    lbl.style.color = value === '' ? 'var(--c-text-faint)' : '';
    document.querySelectorAll('#ddOpts_' + name + ' .dd-opt').forEach(o => o.classList.toggle('selected', o.dataset.value === value));
    document.getElementById('ddPanel_' + name).classList.remove('open');
    document.getElementById('ddTrigger_' + name).classList.remove('open');
    ajaxFilter();
}

function ddSearchInput(val) {
    const lbl = document.getElementById('ddLabel_search');
    lbl.textContent = val || '-- Semua Peringkat --';
    lbl.style.color = val ? '' : 'var(--c-text-faint)';
    ajaxFilter();
}

document.addEventListener('click', e => {
    if (!e.target.closest('.dd-wrap')) {
        document.querySelectorAll('.dd-panel.open').forEach(p => p.classList.remove('open'));
        document.querySelectorAll('.dd-trigger.open').forEach(t => t.classList.remove('open'));
    }
});

document.addEventListener('DOMContentLoaded', _load);
</script>

</main>
</body>
</html>