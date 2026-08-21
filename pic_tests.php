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
                <div class='level-header' onclick=\"toggleBlock('level_$lid', this)\" role='button' tabindex='0'>
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

<?php
$pm_ts_css_v = @filemtime(__DIR__ . '/pic_tests.css') ?: time();
?>
<link rel="stylesheet" href="pic_tests.css?v=<?= $pm_ts_css_v ?>">

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

                <div class="dd-panel" id="ddPanel_siri" role="listbox">
                    <div class="dd-search-box">
                        <input
                            type="text"
                            placeholder="Cari siri..."
                            oninput="ddFilter('siri',this.value)"
                            onclick="event.stopPropagation()">
                    </div>

                    <div class="dd-options" id="ddOpts_siri">
                        <div
                            class="dd-opt selected" role="option" tabindex="0"
                            data-value=""
                            onclick="ddSelect('siri','','-- Semua Siri --')">
                            -- Semua Siri --
                        </div>

                        <?php
                        $siri_list_f = $conn->query("SELECT siri_id, siri_name FROM siri ORDER BY siri_year DESC, siri_name");
                        while ($sr = $siri_list_f->fetch_assoc()) {
                          $sid_  = $sr["siri_id"];
                          $sname = htmlspecialchars($sr["siri_name"]);
                          echo "<div class='dd-opt' role='option' tabindex='0' data-value='{$sid_}' onclick=\"ddSelect('siri','{$sid_}','{$sname}')\">{$sname}</div>";
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

                <div class="dd-panel" id="ddPanel_session" role="listbox">
                    <div class="dd-search-box">
                        <input
                            type="text"
                            placeholder="Cari sidang..."
                            oninput="ddFilter('session',this.value)"
                            onclick="event.stopPropagation()">
                    </div>

                    <div class="dd-options" id="ddOpts_session">
                        <div
                            class="dd-opt selected" role="option" tabindex="0"
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

                <div class="dd-panel" id="ddPanel_level" role="listbox">
                    <div class="dd-search-box">
                        <input
                            type="text"
                            placeholder="Cari peringkat..."
                            oninput="ddFilter('level',this.value)"
                            onclick="event.stopPropagation()">
                    </div>

                    <div class="dd-options" id="ddOpts_level">

                        <div
                            class="dd-opt selected" role="option" tabindex="0"
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
                                class='dd-opt' role='option' tabindex='0'
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

                <div class="dd-panel" id="ddPanel_search" role="listbox">
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
                            class="dd-opt selected" role="option" tabindex="0"
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
                                class='dd-opt' role='option' tabindex='0'
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

<?php
$pm_ts_js_v = @filemtime(__DIR__ . '/pic_tests.js') ?: time();
?>
<script src="pic_tests.js?v=<?= $pm_ts_js_v ?>"></script>

</main>
</body>
</html>