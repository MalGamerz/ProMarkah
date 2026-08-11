<?php
ini_set("display_errors", 0);
ini_set("display_startup_errors", 0);
error_reporting(E_ALL);
ini_set("log_errors", 1);

session_start();
require __DIR__ . '/auth_check.php';
include "db.php";
$conn = getDB();

// Add sort_order column to tests if not exists — lets tests be manually
// reordered per level instead of always showing alphabetically.
if (empty($_SESSION['pm_tests_schema_checked'])) {
  $colCheck = $conn->query("SHOW COLUMNS FROM `tests` LIKE 'sort_order'");
  if ($colCheck && $colCheck->num_rows == 0) {
    $conn->query("ALTER TABLE `tests` ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0");
    $conn->query("UPDATE `tests` SET `sort_order` = `test_id`");
  }
  $_SESSION['pm_tests_schema_checked'] = true;
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
    header("Location: pic_tests.php?msg=Ralat+token+keselamatan.+Sila+muat+semula+halaman.&status=error");
    exit();
  }
  session_write_close();
  $ok = false;

  try {
  if ($_POST["action"] === "add") {
    $lid = (int) ($_POST["level_id"] ?? 0);
    $namesRaw = $_POST["test_name"] ?? '';
    $names = [];
    foreach ((is_array($namesRaw) ? $namesRaw : [$namesRaw]) as $n) {
      $n = trim($n);
      if ($n !== '') { $names[] = $n; }
    }
    if ($lid > 0 && !empty($names)) {
      $order = (int) $conn->query("SELECT COALESCE(MAX(sort_order), -1) + 1 AS n FROM tests WHERE level_id = $lid")->fetch_assoc()["n"];
      $stmt = $conn->prepare(
        "INSERT INTO tests (level_id, test_name, sort_order) VALUES (?, ?, ?)",
      );
      $ok = true;
      foreach ($names as $name) {
        $stmt->bind_param("isi", $lid, $name, $order);
        if (!$stmt->execute()) { $ok = false; }
        $order++;
      }
      $stmt->close();
      $count = count($names);
      $msg = $ok ? "{$count}+ujian+berjaya+ditambah." : "Ralat+menambah+ujian.+Sila+cuba+lagi.";
    } else {
      $msg = $lid <= 0 ? "Sila+pilih+peringkat+yang+sah." : "Sila+masukkan+sekurang-kurangnya+satu+nama+ujian.";
    }
  } elseif ($_POST["action"] === "delete") {
    $tid = (int) $_POST["test_id"];
    $ok = true;
    foreach (
      [
        "DELETE FROM criteria WHERE test_id=?",
        "DELETE FROM tests WHERE test_id=?",
      ]
      as $sql
    ) {
      $stmt = $conn->prepare($sql);
      $stmt->bind_param("i", $tid);
      if (!$stmt->execute()) { $ok = false; }
      $stmt->close();
    }
    $msg = $ok ? "Ujian+berjaya+dipadam." : "Ralat+memadam+ujian.+Sila+cuba+lagi.";
  } elseif ($_POST["action"] === "save_all" && (isset($_POST["tests"]) || isset($_POST["order"]))) {
    $ok = true;
    if (isset($_POST["tests"])) {
      $stmt = $conn->prepare("UPDATE tests SET test_name=? WHERE test_id=?");
      foreach ($_POST["tests"] as $id => $t) {
        $name = trim($t["test_name"]);
        $id = (int) $id;
        if ($name) {
          $stmt->bind_param("si", $name, $id);
          if (!$stmt->execute()) { $ok = false; }
        }
      }
      $stmt->close();
    }
    if (isset($_POST["order"])) {
      $stmt = $conn->prepare("UPDATE tests SET sort_order=? WHERE test_id=?");
      foreach ($_POST["order"] as $levelTestIds) {
        foreach ($levelTestIds as $pos => $tid) {
          $pos = (int) $pos;
          $tid = (int) $tid;
          $stmt->bind_param("ii", $pos, $tid);
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
  header("Location: pic_tests.php?msg={$msg}&status=" . ($ok ? "success" : "error"));
  exit();
}

session_write_close();

// ── AJAX: return sessions (sidang) list scoped to a siri ──────────────────
if (isset($_GET["ajax_sessions"])) {
  header('Content-Type: application/json');
  $siriId = (int) ($_GET["siri"] ?? 0);
  $out = [];
  if ($siriId > 0) {
    $stmt = $conn->prepare("SELECT session_id, session_name FROM sessions WHERE siri_id = ? ORDER BY session_name");
    $stmt->bind_param("i", $siriId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $out[] = $row;
    $stmt->close();
  }
  echo json_encode($out);
  exit();
}

if (isset($_GET["ajax"])) {
  $search = $_GET["search"] ?? "";
  $levelFilter = $_GET["level"] ?? "";
  $siriF = $_GET["siri"] ?? "";
  $sessionF = $_GET["session"] ?? "";

  $tWhereArr = ["1=1"];
  $tTypes = "";
  $tVals = [];
  if ($search !== "") {
    $tWhereArr[] = "t.test_name LIKE ?";
    $tTypes .= "s";
    $tVals[] = "%" . $search . "%";
  }
  if ($levelFilter !== "") {
    $tWhereArr[] = "t.level_id = ?";
    $tTypes .= "i";
    $tVals[] = (int) $levelFilter;
  }

  // Scope to "Siri Aktif" — only iterate levels whose session belongs to it.
  // An explicit Sidang/Siri filter picked in the filter bar takes precedence
  // over the sidebar's "Siri Aktif" selection.
  $active_siri = (int) ($_SESSION["active_siri_id"] ?? 0);
  $lvlSelectBase = "SELECT l.*, s.session_name, si.siri_name FROM levels l
         LEFT JOIN sessions s ON l.session_id = s.session_id
         LEFT JOIN siri si ON s.siri_id = si.siri_id";
  if ($sessionF !== "") {
    $st_lvls = $conn->prepare("$lvlSelectBase WHERE l.session_id = ? ORDER BY l.sort_order, l.level_name");
    $st_lvls->bind_param("i", (int) $sessionF);
    $st_lvls->execute();
    $lvls = $st_lvls->get_result();
  } elseif ($siriF !== "") {
    $st_lvls = $conn->prepare("$lvlSelectBase WHERE s.siri_id = ? ORDER BY l.sort_order, l.level_name");
    $st_lvls->bind_param("i", (int) $siriF);
    $st_lvls->execute();
    $lvls = $st_lvls->get_result();
  } elseif ($active_siri > 0) {
    $st_lvls = $conn->prepare("$lvlSelectBase WHERE s.siri_id = ? ORDER BY l.sort_order, l.level_name");
    $st_lvls->bind_param("i", $active_siri);
    $st_lvls->execute();
    $lvls = $st_lvls->get_result();
  } else {
    $lvls = $conn->query("$lvlSelectBase ORDER BY l.sort_order, l.level_name");
  }
  $found = false;

  while ($lvl = $lvls->fetch_assoc()) {
    $lid = $lvl["level_id"];

    $iterArr = array_merge($tWhereArr, ["t.level_id = ?"]);
    $iterSql = implode(" AND ", $iterArr);
    $iterTypes = $tTypes . "i";
    $iterVals = array_merge($tVals, [$lid]);

    $stmt = $conn->prepare(
      "SELECT t.* FROM tests t WHERE $iterSql ORDER BY t.sort_order, t.test_name",
    );
    $stmt->bind_param($iterTypes, ...$iterVals);
    $stmt->execute();
    $tests = $stmt->get_result();
    $stmt->close();

    // With an active search/level filter, hide levels that have no matching
    // tests. Otherwise show every level — including ones with zero tests —
    // so PICs can see (and add tests to) a peringkat that's still empty.
    if ($tests->num_rows == 0 && ($search !== "" || $levelFilter !== "")) {
      continue;
    }
    $found = true;

    $levelLabel = htmlspecialchars($lvl["level_name"]);
    $siriLabel   = !empty($lvl["siri_name"])    ? htmlspecialchars($lvl["siri_name"])    : '— Tiada Siri —';
    $sidangLabel = !empty($lvl["session_name"]) ? htmlspecialchars($lvl["session_name"]) : '— Tiada Sidang —';
    echo "<div class='accordion-card'>
                <div class='level-header' onclick=\"toggleBlock('level_$lid', this)\">
                    <span class='acc-icon'>&#9658;</span>
                    <strong>" .
      $levelLabel .
      "</strong>
                    <div class='header-badges'>
                        <span class='badge-siri' title='Siri'><svg viewBox=\"0 0 24 24\" xmlns=\"http://www.w3.org/2000/svg\"><rect x=\"3\" y=\"4\" width=\"18\" height=\"18\" rx=\"2\" ry=\"2\"/><line x1=\"16\" y1=\"2\" x2=\"16\" y2=\"6\"/><line x1=\"8\" y1=\"2\" x2=\"8\" y2=\"6\"/><line x1=\"3\" y1=\"10\" x2=\"21\" y2=\"10\"/></svg> {$siriLabel}</span>
                        <span class='badge-sidang' title='Sidang'><svg viewBox=\"0 0 24 24\" xmlns=\"http://www.w3.org/2000/svg\"><path d=\"M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z\"/></svg> {$sidangLabel}</span>
                        <span class='count-badge'>{$tests->num_rows} Ujian</span>
                    </div>
                </div>
                <div id='level_$lid' class='acc-body' style='display:none;'>
                    <div class='table-responsive'>
                        <table class='tests-table' data-level='$lid'>
                            <thead>
                                <tr>
                                    <th style='width:36px;'></th>
                                    <th style='width:50px; text-align:center;'>No</th>
                                    <th>Nama Ujian</th>
                                    <th style='width:100px; text-align:center;'>Tindakan</th>
                                </tr>
                            </thead>
                            <tbody>";
    $no = 1;
    while ($t = $tests->fetch_assoc()) {
      $tid = $t["test_id"];
      $safeName = htmlspecialchars($t["test_name"], ENT_QUOTES);
      echo "<tr data-id='$tid' draggable='true'>
                    <td class='col-drag' style='text-align:center; cursor:grab; color:var(--c-text-faint);'>&#9776;</td>
                    <td class='col-no' style='text-align:center; color:var(--c-text-faint);'>$no</td>
                    <td><input name='tests[$tid][test_name]' value='$safeName' data-orig='$safeName' class='test-input'></td>
                    <td class='col-actions' style='text-align:center;'>
                        <button type='button' class='pm-btn pm-btn-danger btn-sm' style='padding:5px 12px; font-size:0.78rem;' onclick=\"delRow('$tid')\">Padam</button>
                    </td>
                  </tr>";
      $no++;
    }
    if ($no === 1) {
      echo "<tr class='row-empty'><td colspan='4' style='text-align:center;padding:16px;color:var(--c-text-faint);'>Tiada ujian lagi untuk peringkat ini.</td></tr>";
    }
    echo "          </tbody>
                        </table>
                    </div>
                </div>
              </div>";
  }

  if (!$found) {
    echo "<p style='text-align:center;padding:30px;color:var(--c-text-faint);'>Tiada ujian ditemui untuk carian ini.</p>";
  }
  exit();
}

$pm_page = "tests";
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

    /* ── Multi-row "add several at once" input group (used by Nama Ujian) ── */
    .pic-add-form--multi {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }
    .pic-add-form--multi > div { width: 100%; }
    .add-row { display: flex; gap: 8px; margin-bottom: 8px; }
    .add-row:last-child { margin-bottom: 0; }
    .add-row input { flex: 1; min-width: 0; }
    .add-row-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        border-radius: 6px;
        font-size: 0.8rem;
        font-weight: 600;
        transition: all .15s;
    }
    .add-row-remove {
        width: 40px;
        height: 40px;
        flex: 0 0 auto;
        background: var(--c-surface-2);
        border: 1px solid var(--c-border-strong);
        color: var(--c-text-faint);
    }
    .add-row-remove:hover { border-color: var(--c-red-300); color: var(--c-red); background: rgba(214, 40, 40, .08); }
    .add-row-add {
        height: 36px;
        padding: 0 14px;
        background: transparent;
        border: 1px dashed var(--c-border-strong);
        color: var(--c-text-faint);
    }
    .add-row-add:hover { border-color: var(--c-red); color: var(--c-red); }
    .pic-add-form-actions { display: flex; justify-content: flex-end; }
    html.pm-light .add-row-remove { background: var(--c-gray-50); border-color: var(--c-gray-300); }
    html.pm-light .add-row-add { border-color: var(--c-gray-300); color: var(--c-gray-500); }

    /* ── Main card & Accordions ── */
    .tests-card {
        background: var(--c-surface-1);
        border: 1px solid var(--c-border-strong);
        border-radius: 10px;
        overflow: hidden;
        position: relative;
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

    @keyframes slideDown {
        from { opacity: 0; transform: translateY(-6px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    /* Scrollable Container — height set dynamically in JS
       (fitTestsListHeight) so it always leaves room for the pagination bar
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
    /* Display peringkat/ujian names in caps — accordion titles (peringkat),
       the editable name field (ujian), and the Peringkat dropdown options —
       purely visual, the stored value keeps whatever case was typed. */
    .level-header strong,
    .tests-table input,
    #ddOpts_level .dd-opt,
    #ddLabel_level,
    .pic-add-form select {
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
    .level-header .header-badges {
        margin-left: auto;
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .level-header .count-badge {
        background: var(--c-surface-0);
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.75rem;
        color: var(--c-text-muted);
        border: 1px solid var(--c-border);
        white-space: nowrap;
    }
    .level-header .badge-siri,
    .level-header .badge-sidang {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.72rem;
        font-weight: 600;
        white-space: nowrap;
        background: var(--c-surface-0);
        border: 1px solid var(--c-border);
    }
    /* Uniform stroke-icon sizing (both badges use the same icon set as the
       rest of the app — see pm_icon() in layout.php — so Siri/Sidang render
       at identical size/weight instead of mismatched OS emoji). */
    .level-header .badge-siri svg,
    .level-header .badge-sidang svg {
        width: 12px;
        height: 12px;
        flex-shrink: 0;
        fill: none;
        stroke: currentColor;
        stroke-width: 2;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    .level-header .badge-siri {
        color: var(--c-red);
        border-color: var(--c-red-border);
    }
    .level-header .badge-sidang {
        color: var(--c-text-muted);
        border-color: var(--c-border-strong);
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
    html.pm-light .level-header .badge-siri { background: #fff; border-color: var(--c-red-300); }
    html.pm-light .level-header .badge-sidang { background: #fff; color: #555; border-color: var(--c-gray-300); }
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
    .tests-card{
    margin-top:20px;
    }
</style>

<form id='saveAllForm' method='POST' style='display:none;'>
    <input type='hidden' name='action' value='save_all'>
    <input type='hidden' name='csrf_token' value='<?= htmlspecialchars($csrf) ?>'>
</form>
<form id='delFrm' method='POST' style='display:none;'>
    <input type='hidden' name='action' value='delete'>
    <input type='hidden' name='test_id' value=''>
    <input type='hidden' name='csrf_token' value='<?= htmlspecialchars($csrf) ?>'>
</form>

<div class='pic-section-header'>
    <div>
        <h2>🧪 Pengurusan Ujian</h2>
        <div class='pic-section-sub'>Kawal selia dan tambah ujian mengikut peringkat</div>
    </div>
    <button type='button' class='pm-btn pm-btn-primary' onclick="toggleAddCard()">+ Tambah Ujian</button>
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
        Penapis Ujian
    </div>

    <div class="pic-filter-bar">

        <div>
            <label>Siri</label>

            <div class="dd-wrap" id="ddWrap_siri">
                <div class="dd-trigger" id="ddTrigger_siri" onclick="ddToggle('siri')" role="button" tabindex="0" aria-haspopup="listbox">
                    <span id="ddLabel_siri" style="color:var(--c-text-faint);">
                        -- Semua Siri --
                    </span>
                    <span class="dd-arrow">▼</span>
                </div>

                <div class="dd-panel" id="ddPanel_siri">
                    <div class="dd-search-box">
                        <input
                            type="text"
                            placeholder="Cari siri..."
                            oninput="ddFilter('siri',this.value)"
                            onclick="event.stopPropagation()">
                    </div>

                    <div class="dd-options" id="ddOpts_siri">
                        <div
                            class="dd-opt selected"
                            data-value=""
                            onclick="ddSelect('siri','','-- Semua Siri --')">
                            -- Semua Siri --
                        </div>

                        <?php
                        $siri_list_f = $conn->query("SELECT siri_id, siri_name FROM siri ORDER BY siri_year DESC, siri_name");
                        while ($sr = $siri_list_f->fetch_assoc()) {
                          $sid_  = $sr["siri_id"];
                          $sname = htmlspecialchars($sr["siri_name"]);
                          echo "<div class='dd-opt' data-value='{$sid_}' onclick=\"ddSelect('siri','{$sid_}','{$sname}')\">{$sname}</div>";
                        }
                        ?>
                    </div>

                    <div class="dd-empty" id="ddEmpty_siri">
                        Tiada hasil
                    </div>
                </div>

                <input type="hidden" id="f_siri" value="">
            </div>
        </div>

        <div>
            <label>Sidang</label>

            <div class="dd-wrap" id="ddWrap_session">
                <div class="dd-trigger dd-trigger-disabled" id="ddTrigger_session" onclick="ddToggle('session')" role="button" tabindex="0" aria-haspopup="listbox">
                    <span id="ddLabel_session" style="color:var(--c-text-faint);">
                        -- Pilih Siri dahulu --
                    </span>
                    <span class="dd-arrow">▼</span>
                </div>

                <div class="dd-panel" id="ddPanel_session">
                    <div class="dd-search-box">
                        <input
                            type="text"
                            placeholder="Cari sidang..."
                            oninput="ddFilter('session',this.value)"
                            onclick="event.stopPropagation()">
                    </div>

                    <div class="dd-options" id="ddOpts_session">
                        <div
                            class="dd-opt selected"
                            data-value=""
                            onclick="ddSelect('session','','-- Semua Sidang --')">
                            -- Semua Sidang --
                        </div>
                    </div>

                    <div class="dd-empty" id="ddEmpty_session">
                        Tiada hasil
                    </div>
                </div>

                <input type="hidden" id="f_session" value="">
            </div>
        </div>

        <div>
            <label>Peringkat</label>

            <div class="dd-wrap" id="ddWrap_level">
                <div class="dd-trigger" id="ddTrigger_level" onclick="ddToggle('level')" role="button" tabindex="0" aria-haspopup="listbox">
                    <span id="ddLabel_level" style="color:var(--c-text-faint);">
                        -- Semua Peringkat --
                    </span>
                    <span class="dd-arrow">▼</span>
                </div>

                <div class="dd-panel" id="ddPanel_level">
                    <div class="dd-search-box">
                        <input
                            type="text"
                            placeholder="Cari peringkat..."
                            oninput="ddFilter('level',this.value)"
                            onclick="event.stopPropagation()">
                    </div>

                    <div class="dd-options" id="ddOpts_level">

                        <div
                            class="dd-opt selected"
                            data-value=""
                            onclick="ddSelect('level','','-- Semua Peringkat --')">
                            -- Semua Peringkat --
                        </div>

                        <?php
                        // Scope to "Siri Aktif"; when "Semua Siri" is active, tag
                        // each level with its siri so same-named levels (e.g. two
                        // siri both having "Peringkat A") aren't ambiguous.
                        $active_siri_filter = (int) ($_SESSION["active_siri_id"] ?? 0);
                        $lvls = $active_siri_filter > 0
                          ? $conn->query("SELECT l.* FROM levels l JOIN sessions s ON l.session_id = s.session_id WHERE s.siri_id = $active_siri_filter ORDER BY l.sort_order, l.level_name")
                          : $conn->query("
                                SELECT l.*, si.siri_name
                                FROM levels l
                                LEFT JOIN sessions s ON l.session_id = s.session_id
                                LEFT JOIN siri si ON s.siri_id = si.siri_id
                                ORDER BY l.sort_order, l.level_name
                            ");

                        while ($l = $lvls->fetch_assoc()) {
                          $id = $l["level_id"];
                          $name = htmlspecialchars($l["level_name"]);
                          $label = $name;
                          if ($active_siri_filter === 0 && !empty($l["siri_name"])) {
                            $label .= " — " . htmlspecialchars($l["siri_name"]);
                          }

                          echo "
                            <div
                                class='dd-opt'
                                data-value='{$id}'
                                onclick=\"ddSelect('level','{$id}','{$label}')\">
                                {$label}
                            </div>";
                        }
                        ?>

                    </div>

                    <div class="dd-empty" id="ddEmpty_level">
                        Tiada hasil
                    </div>
                </div>

                <input type="hidden" id="f_level" value="">
            </div>
        </div>

        <div>
            <label>Cari Nama Ujian</label>

            <div class="dd-wrap" id="ddWrap_search">
                <div class="dd-trigger" id="ddTrigger_search" onclick="ddToggle('search')" role="button" tabindex="0" aria-haspopup="listbox">
                    <span id="ddLabel_search" style="color:var(--c-text-faint);">
                        -- Semua Ujian --
                    </span>
                    <span class="dd-arrow">▼</span>
                </div>

                <div class="dd-panel" id="ddPanel_search">
                    <div class="dd-search-box">
                        <input
                            type="text"
                            id="f_search"
                            placeholder="Taip nama ujian..."
                            oninput="ddSearchInput(this.value)"
                            onclick="event.stopPropagation()">
                    </div>

                    <div class="dd-options" id="ddOpts_search">

                        <div
                            class="dd-opt selected"
                            data-value=""
                            onclick="ddSelect('search','','-- Semua Ujian --')">
                            -- Semua Ujian --
                        </div>

                        <?php
                        $tests = $conn->query("
                            SELECT DISTINCT test_name
                            FROM tests
                            ORDER BY test_name
                        ");

                        while ($t = $tests->fetch_assoc()) {
                          $name = htmlspecialchars($t["test_name"]);

                          echo "
                            <div
                                class='dd-opt'
                                data-value='{$name}'
                                onclick=\"ddSelect('search','{$name}','{$name}')\">
                                {$name}
                            </div>";
                        }
                        ?>

                    </div>

                    <div class="dd-empty" id="ddEmpty_search">
                        Tiada hasil
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<div id='addTest' class='pic-add-card'>
    <h3>+ Tambah Ujian Baru</h3>
    <form method='POST' class='pic-add-form pic-add-form--multi' onsubmit="rememberOpenAccordions()">
        <input type='hidden' name='action' value='add'>
        <input type='hidden' name='csrf_token' value='<?= htmlspecialchars($csrf) ?>'>
        <div>
            <label>Pilih Peringkat</label>
            <select name='level_id' required>
                <?php
                // Scope to "Siri Aktif" — matches the listing AJAX above, so a
                // PIC can't accidentally attach a new test to another siri's
                // same-named level (e.g. two siri both having "Peringkat A").
                $active_siri_add = (int) ($_SESSION["active_siri_id"] ?? 0);
                $lvls = $active_siri_add > 0
                  ? $conn->query("SELECT l.* FROM levels l JOIN sessions s ON l.session_id = s.session_id WHERE s.siri_id = $active_siri_add ORDER BY l.sort_order, l.level_name")
                  : $conn->query(
                    "SELECT l.*, si.siri_name FROM levels l
                     LEFT JOIN sessions s ON l.session_id = s.session_id
                     LEFT JOIN siri si ON s.siri_id = si.siri_id
                     ORDER BY l.sort_order, l.level_name",
                  );
                while ($l = $lvls->fetch_assoc()) {
                  $optLabel = htmlspecialchars($l["level_name"]);
                  if ($active_siri_add === 0 && !empty($l["siri_name"])) {
                    $optLabel .= " — " . htmlspecialchars($l["siri_name"]);
                  }
                  echo "<option value='{$l["level_id"]}'>" .
                    $optLabel .
                    "</option>";
                }
                ?>
            </select>
        </div>
        <div>
            <label>Nama Ujian <span style="text-transform:none; font-weight:400; letter-spacing:0;">(boleh tambah lebih daripada satu)</span></label>
            <div id='addRows_test'>
                <div class='add-row'>
                    <input name='test_name[]' placeholder='Contoh: Ujian Jurus Tunggal' required>
                    <button type='button' class='add-row-btn add-row-remove' onclick="removeAddRow(this)" title='Buang baris'>&times;</button>
                </div>
            </div>
            <button type='button' class='add-row-btn add-row-add' onclick="addRow('addRows_test', 'test_name[]', 'Contoh: Ujian Jurus Silat')">+ Tambah Baris</button>
        </div>
        <div class='pic-add-form-actions'>
            <button class='pm-btn pm-btn-primary'>Tambah Ujian</button>
        </div>
    </form>
</div>

<div class='tests-card'>
    <div class='tests-save-bar' id='saveDirtyBar'>
        <span class='save-msg' id='saveMsg'>Tiada perubahan</span>
        <button type='button' class='pm-btn pm-btn-ghost btn-sm btn-discard' style='padding:5px 12px;' onclick="discardAll()">Batal</button>
        <button type='button' class='pm-btn pm-btn-primary btn-sm' style='padding:5px 12px;' onclick="submitSaveAll()">💾 Simpan Semua</button>
    </div>

    <div class='tests-table-wrap' id='testList'>
        <p style='text-align:center;padding:30px;color:var(--c-text-faint);'>Memuatkan...</p>
    </div>
    <div id='ajaxSpinner'>⏳ Mencari...</div>

    <div class="vm-pagination" id="testsPaginationContainer" style="display:none;">
        <div class="vm-page-info" id="testsPageInfo"></div>
        <div class="vm-page-btns" id="testsPaginationButtons"></div>
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
        level: document.getElementById('f_level').value,
        siri: document.getElementById('f_siri').value,
        session: document.getElementById('f_session').value
    });

    const wrapper = document.getElementById('testList');
    const spinner = document.getElementById('ajaxSpinner');

    wrapper.style.display = 'none';
    spinner.style.display = 'block';

    pmFetch('pic_tests.php?' + params)
        .then(r => r.text())
        .then(html => {
            wrapper.innerHTML = html;
            wrapper.style.display = 'block';
            spinner.style.display = 'none';
            _dirtyRows.clear();
            _orderDirty = false;
            updateDirtyBar();
            attachListeners();
            testsCurrentPage = 1;
            updateTestsPagination();
            fitTestsListHeight();
            restoreOpenAccordions();
        })
        .catch(() => {
            wrapper.style.display = 'block';
            spinner.style.display = 'none';
        });
}

// ── Add several test names to a level in one submission ──
function addRow(containerId, inputName, placeholder) {
    const container = document.getElementById(containerId);
    const row = document.createElement('div');
    row.className = 'add-row';
    const input = document.createElement('input');
    input.name = inputName;
    input.placeholder = placeholder;
    input.required = true;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'add-row-btn add-row-remove';
    btn.title = 'Buang baris';
    btn.innerHTML = '&times;';
    btn.onclick = () => removeAddRow(btn);
    row.appendChild(input);
    row.appendChild(btn);
    container.appendChild(row);
    input.focus();
}

function removeAddRow(btn) {
    const container = btn.closest('[id^="addRows_"]');
    const row = btn.closest('.add-row');
    if (container && container.querySelectorAll('.add-row').length > 1) {
        row.remove();
    } else if (row) {
        row.querySelector('input').value = '';
    }
}

// ── Keep the accordion(s) the PIC had open across a save/add/delete
// redirect instead of snapping everything back to collapsed. ──
const PM_OPEN_KEY = 'pm_tests_open_accordions';

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

// ── PAGINATION (client-side, 20 accordions per page) ──
let testsCurrentPage = 1;
const testsPerPage = 20;

function updateTestsPagination() {
    const cards = Array.from(document.querySelectorAll('#testList > .accordion-card'));
    const container = document.getElementById('testsPaginationContainer');
    const info = document.getElementById('testsPageInfo');
    const btns = document.getElementById('testsPaginationButtons');

    if (cards.length === 0) { container.style.display = 'none'; return; }

    const total = cards.length;
    const totalPages = Math.max(1, Math.ceil(total / testsPerPage));
    if (testsCurrentPage > totalPages) testsCurrentPage = totalPages;
    if (testsCurrentPage < 1) testsCurrentPage = 1;

    container.style.display = totalPages <= 1 ? 'none' : 'flex';

    const start = (testsCurrentPage - 1) * testsPerPage;
    const end   = start + testsPerPage;
    cards.forEach((c, i) => { c.style.display = (i >= start && i < end) ? '' : 'none'; });

    const s = start + 1;
    const e = Math.min(end, total);
    info.innerHTML = `Memaparkan <b>${s}–${e}</b> daripada <b>${total}</b> peringkat`;

    pmRenderPagination(btns, testsCurrentPage, totalPages, testsGoToPage);
}

function testsGoToPage(page) {
    testsCurrentPage = page;
    updateTestsPagination();
}

// ── Fit the tests list + pagination into the viewport, no page scroll ──
function fitTestsListHeight() {
    const scrollEl = document.getElementById('testList');
    const pagination = document.getElementById('testsPaginationContainer');
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
window.addEventListener('resize', fitTestsListHeight);

function attachListeners() {
    document.querySelectorAll('.test-input').forEach(el => {
        if (el.dataset.listening) return;
        el.dataset.listening = '1';
        el.addEventListener('input', onInputChange);
    });
    attachDragListeners();
}

// ── Drag-and-drop reordering (per level tbody) ──
let _dragRow = null;

function attachDragListeners() {
    document.querySelectorAll('.tests-table tbody tr[draggable="true"]').forEach(row => {
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

function submitSaveAll() {
    rememberOpenAccordions();
    const form = document.getElementById('saveAllForm');
    form.querySelectorAll('.dyn-input').forEach(el => el.remove());
    document.querySelectorAll('.test-input').forEach(el => {
        const h = document.createElement('input');
        h.type = 'hidden';
        h.name = el.name;
        h.value = el.value;
        h.className = 'dyn-input';
        form.appendChild(h);
    });
    if (_orderDirty) {
        document.querySelectorAll('.tests-table').forEach(table => {
            const lid = table.dataset.level;
            table.querySelectorAll('tbody tr[draggable="true"]').forEach((row, i) => {
                const h = document.createElement('input');
                h.type = 'hidden';
                h.name = `order[${lid}][${i}]`;
                h.value = row.dataset.id;
                h.className = 'dyn-input';
                form.appendChild(h);
            });
        });
    }
    form.submit();
}

function discardAll() {
    document.querySelectorAll('.test-input').forEach(el => {
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
    if (!confirm('Padam ujian ini? Amaran: Semua kriteria di dalam ujian ini akan terpadam.')) return;
    rememberOpenAccordions();
    document.getElementById('delFrm').querySelector('[name=test_id]').value = id;
    document.getElementById('delFrm').submit();
}

function toggleAddCard() {
    const el = document.getElementById('addTest');
    el.style.display = (el.style.display === 'block') ? 'none' : 'block';
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
    opts.forEach(o => { const m = o.textContent.toLowerCase().includes(val.toLowerCase()); o.classList.toggle('hidden', !m); if(m) any=true; });
    if(empty) empty.style.display = any ? 'none' : 'block';
}
function ddSelect(name, value, label) {
    if (name === 'level' || name === 'siri' || name === 'session') document.getElementById('f_' + name).value = value;
    const lbl = document.getElementById('ddLabel_' + name);
    lbl.textContent = label;
    lbl.style.color = value === '' ? 'var(--c-text-faint)' : '';
    document.querySelectorAll('#ddOpts_' + name + ' .dd-opt').forEach(o => o.classList.toggle('selected', o.dataset.value === value));
    document.getElementById('ddPanel_' + name).classList.remove('open');
    document.getElementById('ddTrigger_' + name).classList.remove('open');

    if (name === 'siri') {
        loadSidangOptions(value);
    }
    ajaxFilter();
}

function loadSidangOptions(siriId) {
    const sessTrigger = document.getElementById('ddTrigger_session');
    const sessOpts = document.getElementById('ddOpts_session');
    const sessLbl = document.getElementById('ddLabel_session');

    document.getElementById('f_session').value = '';
    sessLbl.style.color = 'var(--c-text-faint)';

    if (!siriId) {
        sessLbl.textContent = '-- Pilih Siri dahulu --';
        sessOpts.innerHTML = "<div class='dd-opt selected' data-value='' onclick=\"ddSelect('session','','-- Semua Sidang --')\">-- Semua Sidang --</div>";
        sessTrigger.classList.add('dd-trigger-disabled');
        return;
    }
    sessLbl.textContent = '-- Semua Sidang --';
    sessOpts.innerHTML = "<div class='dd-opt selected' data-value='' onclick=\"ddSelect('session','','-- Semua Sidang --')\">-- Semua Sidang --</div>";
    sessTrigger.classList.remove('dd-trigger-disabled');

    pmFetch('pic_tests.php?ajax_sessions=1&siri=' + encodeURIComponent(siriId))
        .then(r => r.json())
        .then(list => {
            list.forEach(s => {
                const opt = document.createElement('div');
                opt.className = 'dd-opt';
                opt.dataset.value = s.session_id;
                opt.textContent = s.session_name;
                opt.onclick = () => ddSelect('session', String(s.session_id), s.session_name);
                sessOpts.appendChild(opt);
            });
        })
        .catch(() => {});
}
function ddSearchInput(val) {
    const lbl = document.getElementById('ddLabel_search');
    lbl.textContent = val || '-- Semua Ujian --';
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