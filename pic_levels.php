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

<?php
$pm_levels_css_v = @filemtime(__DIR__ . '/pic_levels.css') ?: time();
?>
<link rel="stylesheet" href="pic_levels.css?v=<?= $pm_levels_css_v ?>">

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

<?php
$pm_levels_js_v = @filemtime(__DIR__ . '/pic_levels.js') ?: time();
?>
<script src="pic_levels.js?v=<?= $pm_levels_js_v ?>"></script>

</main>
</body>
</html>