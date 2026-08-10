<?php
// ── Error handling & Session ──────────────────────────────────────────────
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

session_start();
require __DIR__ . '/auth_check.php';

// ── SECURITY: Only PIC and Admin may access this page ──────────────────────
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['pic', 'admin'])) {
    // Not logged in or wrong role — redirect to login
    header('Location: login.php');
    exit;
}
// ────────────────────────────────────────────────────────────────────────────

include 'db.php';
$conn = getDB();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ── REQUIRE SIMPLEXLSX ────────────────────────────────────────────────────
require_once 'SimpleXLSX.php';

$stage   = $_POST['stage'] ?? 'upload';
$message = '';
$msgType = '';

// ── Helper: detect gender from bin/binti (also matches the common
// abbreviations b/b. for bin and bt/bt./bte/bte. for binti — each marker
// must end at a "." or a space/end-of-string so it doesn't fire on a name
// that merely starts with the same letters, e.g. "Baharuddin"/"Bakar";
// see the matching JS version in pic_students.php's autoDetectGender()) ──
function detectGenderFromName(string $name): string {
    $lower = strtolower($name);
    if (preg_match('/\b(?:binti|bte|bt)(?:\.|(?=\s|$))/', $lower)) return 'Female';
    if (preg_match('/\b(?:bin|b)(?:\.|(?=\s|$))/',         $lower)) return 'Male';
    return '';
}

// ── Helper: resolve siri + default sidang from the shared review preconfig
// fields (siri_id/new_siri_name/new_siri_year/default_year/default_session/
// new_session_name) — used by both the Excel 'review' stage and the PDF
// 'review_pdf' stage, since a PDF roster carries no siri/sidang info of its
// own and needs the exact same "pick existing or create new" step. Creates
// the siri/session rows immediately (rather than deferring to the 'process'
// stage) so $existing_siri/$existing_sessions already reflect them when the
// mapping tabs render right after this runs.
function pm_resolve_import_preconfig(
    mysqli $conn,
    string $current_year,
    array &$existing_siri,
    array &$existing_sessions,
    array &$existing_session_siri,
    array $existing_levels,
    array $existing_level_session
): array {
    $preconfig = [
        'siri_id'          => $_POST['siri_id']               ?? 0,
        'new_siri_name'    => trim($_POST['new_siri_name']    ?? ''),
        'new_siri_year'    => trim($_POST['new_siri_year']    ?? $current_year),
        'default_year'     => trim($_POST['default_year']     ?? $current_year),
        'default_session'  => $_POST['default_session']       ?? 'NONE',
        'new_session_name' => trim($_POST['new_session_name'] ?? ''),
    ];

    // Resolve which siri this batch targets — needed below so a brand-new
    // default "Sidang" actually gets linked to it (it previously left
    // siri_id NULL, and per-row "Sidang" text matching below previously
    // ignored siri scope entirely, both of which could silently attach new
    // uploads to an unrelated pre-existing session/siri of the same name).
    $review_target_siri_id = 0;
    if ($preconfig['siri_id'] === 'NEW' && !empty($preconfig['new_siri_name'])) {
        $stmt = $conn->prepare("INSERT INTO siri (siri_name, siri_year) VALUES (?, ?)");
        $s_year = !empty($preconfig['new_siri_year']) ? $preconfig['new_siri_year'] : $current_year;
        $stmt->bind_param("ss", $preconfig['new_siri_name'], $s_year);
        $stmt->execute();
        $review_target_siri_id = $conn->insert_id;
        $preconfig['siri_id'] = $review_target_siri_id; // so 'process' stage reuses this siri instead of creating a duplicate
        $existing_siri[$review_target_siri_id] = $preconfig['new_siri_name'] . ' ' . $s_year;
    } elseif (is_numeric($preconfig['siri_id']) && (int)$preconfig['siri_id'] > 0) {
        $review_target_siri_id = (int)$preconfig['siri_id'];
    }

    $default_session_id   = 0;
    $default_session_name = '';
    if ($preconfig['default_session'] === 'NEW' && !empty($preconfig['new_session_name'])) {
        $sn = $preconfig['new_session_name'];
        $stmt = $conn->prepare("INSERT INTO sessions (session_name, siri_id) VALUES (?, ?)");
        $linked_siri = $review_target_siri_id > 0 ? $review_target_siri_id : NULL;
        $stmt->bind_param("si", $sn, $linked_siri);
        $stmt->execute();
        $default_session_id   = $conn->insert_id;
        $default_session_name = $sn;
        $existing_sessions[$default_session_id] = $sn;
        $existing_session_siri[$default_session_id] = $review_target_siri_id;
    } elseif (is_numeric($preconfig['default_session']) && (int)$preconfig['default_session'] > 0) {
        $default_session_id   = (int)$preconfig['default_session'];
        $default_session_name = $existing_sessions[$default_session_id] ?? '';
    }

    // Sessions actually eligible for "exact match" auto-detection below:
    // only ones already belonging to the targeted siri. Without this, a
    // same-named session from a different siri (e.g. an old "Sidang 1")
    // would silently match and pull this whole upload into the wrong siri.
    $sessions_in_target_siri = [];
    if ($review_target_siri_id > 0) {
        foreach ($existing_sessions as $sid => $sname) {
            if ((int)($existing_session_siri[$sid] ?? 0) === $review_target_siri_id) {
                $sessions_in_target_siri[$sid] = $sname;
            }
        }
    }

    // Same scoping for levels — a level only counts as an "exact match" if
    // it belongs to one of the sessions already scoped to the target siri.
    $levels_in_target_siri = [];
    if (!empty($sessions_in_target_siri)) {
        foreach ($existing_levels as $lid => $lname) {
            if (isset($sessions_in_target_siri[$existing_level_session[$lid] ?? 0])) {
                $levels_in_target_siri[$lid] = $lname;
            }
        }
    }

    return [
        'preconfig'               => $preconfig,
        'review_target_siri_id'   => $review_target_siri_id,
        'default_session_id'      => $default_session_id,
        'default_session_name'    => $default_session_name,
        'sessions_in_target_siri' => $sessions_in_target_siri,
        'levels_in_target_siri'   => $levels_in_target_siri,
    ];
}

// ── Helper: PDF import group-name code — "000-CAWANGAN-PERINGKAT" ─────────
// e.g. Kumpulan 1 under branch "Kangkar Pulai Sesi 4", peringkat "AWAN
// PUTIH CULA HIJAU 2" becomes "001-KPS4-CH2". The branch code is the first
// letter of every word in the branch name; the peringkat code is the first
// letter of only its last 3 words (a peringkat heading's leading words are
// usually a fixed "AWAN PUTIH ..." prefix shared by every level, so only
// the tail actually distinguishes one from another — matches the existing
// hand-typed convention already used elsewhere in this system, e.g.
// "001-SKTU4-H1").
function pm_group_initials(string $text, int $maxWords = 0): string {
    $words = array_values(array_filter(preg_split('/\s+/', trim($text)), fn($w) => $w !== ''));
    if ($maxWords > 0 && count($words) > $maxWords) {
        $words = array_slice($words, -$maxWords);
    }
    $code = '';
    foreach ($words as $w) {
        $code .= mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8');
    }
    return $code;
}
function pm_derive_group_name(string $cawangan, string $peringkat, int $kumpulanNum): string {
    $cawanganCode  = pm_group_initials($cawangan);
    $peringkatCode = pm_group_initials($peringkat, 3);
    return sprintf('%03d-%s-%s', max(0, $kumpulanNum), $cawanganCode, $peringkatCode);
}

// ── Helper: fuzzy match ───────────────────────────────────────────────────
function findSimilar($searchStr, $existingArray) {
    $bestMatch      = null;
    $highestPercent = 0;
    $searchStr      = strtolower(trim($searchStr));
    foreach ($existingArray as $id => $name) {
        similar_text($searchStr, strtolower(trim($name)), $percent);
        if ($percent > 70 && $percent > $highestPercent) {
            $highestPercent = $percent;
            $bestMatch = ['id' => $id, 'name' => $name, 'percent' => round($percent)];
        }
    }
    return $bestMatch;
}

// ── Fetch reference data ──────────────────────────────────────────────────
$existing_schools  = [];
$existing_sessions = [];
$existing_levels   = [];
$existing_students = [];
$existing_groups   = [];
$existing_judges   = [];
$existing_siri     = [];

$res = $conn->query("SELECT school_id, school_name FROM schools");
while ($r = $res->fetch_assoc()) $existing_schools[$r['school_id']] = $r['school_name'];

$existing_session_siri = []; // session_id => siri_id (used to scope "exact match" detection to the targeted siri)
$res = $conn->query("SELECT session_id, session_name, siri_id FROM sessions ORDER BY session_name");
while ($r = $res->fetch_assoc()) {
    $existing_sessions[$r['session_id']] = $r['session_name'];
    $existing_session_siri[$r['session_id']] = (int)$r['siri_id'];
}

$existing_level_session = []; // level_id => session_id (used to scope level matching to the targeted siri's sessions)
$res = $conn->query("SELECT level_id, level_name, session_id FROM levels ORDER BY level_name");
while ($r = $res->fetch_assoc()) {
    $existing_levels[$r['level_id']] = $r['level_name'];
    $existing_level_session[$r['level_id']] = (int)$r['session_id'];
}

$res = $conn->query("SELECT student_id, student_name FROM students");
$existing_student_names_set = [];
while ($r = $res->fetch_assoc()) {
    $n = strtolower(trim($r['student_name']));
    $existing_students[$r['student_id']] = $n;
    $existing_student_names_set[$n] = true;
}

$res = $conn->query("SELECT group_id, group_name FROM `groups` ORDER BY group_name");
while ($r = $res->fetch_assoc()) $existing_groups[$r['group_id']] = $r['group_name'];

$res = $conn->query("SELECT id, name, judge_code FROM judges ORDER BY judge_code");
while ($r = $res->fetch_assoc()) $existing_judges[$r['id']] = $r['name'] . ' (' . $r['judge_code'] . ')';

$res = $conn->query("SELECT siri_id, siri_name, siri_year FROM siri ORDER BY siri_year DESC, siri_name");
while ($r = $res->fetch_assoc()) $existing_siri[$r['siri_id']] = $r['siri_name'] . ' ' . $r['siri_year'];

$current_year = date('Y');

// ── STAGE: PROCESS (EXECUTE IMPORT) ──────────────────────────────────────
if ($stage === 'process' && (!isset($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token']))) {
    $message = 'Ralat token keselamatan. Sila muat semula halaman.';
    $msgType = 'error';
    $stage   = 'upload';
} elseif ($stage === 'process') {
    $import_data          = $_SESSION['staged_data'] ?? [];
    $preconfig            = $_SESSION['staged_preconfig'] ?? [];
    $map_schools          = $_POST['map_school']        ?? [];
    $map_sessions         = $_POST['map_session']       ?? [];
    $map_levels           = $_POST['map_level']         ?? [];
    $skip_students        = $_POST['skip_student']      ?? [];
    $student_gender       = $_POST['student_gender']    ?? [];
    $student_year         = $_POST['student_year']      ?? [];
    $student_susunan_umur = $_POST['student_susunan_umur'] ?? [];
    $student_group        = $_POST['student_group']     ?? [];
    $student_new_group    = $_POST['student_new_group'] ?? [];
    $group_judge          = $_POST['group_judge']       ?? [];

    $successCount = 0;
    $conn->begin_transaction();
    try {
        // Resolve Schools
        $resolved_schools = [];
        foreach ($map_schools as $original_text => $decision) {
            if ($decision === 'NEW') {
                $stmt = $conn->prepare("INSERT INTO schools (school_name) VALUES (?)");
                $stmt->bind_param("s", $original_text);
                $stmt->execute();
                $resolved_schools[$original_text] = $conn->insert_id;
            } else {
                $resolved_schools[$original_text] = (int)$decision;
            }
        }

        // Resolve Siri
        $active_siri_id = $preconfig['siri_id'] ?? null;
        if ($active_siri_id === 'NEW' && !empty($preconfig['new_siri_name'])) {
            $stmt = $conn->prepare("INSERT INTO siri (siri_name, siri_year) VALUES (?, ?)");
            $s_year = !empty($preconfig['new_siri_year']) ? $preconfig['new_siri_year'] : $current_year;
            $stmt->bind_param("ss", $preconfig['new_siri_name'], $s_year);
            $stmt->execute();
            $active_siri_id = $conn->insert_id;
        }

        // Resolve Sessions
        $resolved_sessions = [];
        foreach ($map_sessions as $original_text => $decision) {
            if ($decision === 'NEW') {
                $stmt = $conn->prepare("INSERT INTO sessions (session_name, siri_id) VALUES (?, ?)");
                $linked_siri = is_numeric($active_siri_id) && $active_siri_id > 0 ? $active_siri_id : NULL;
                $stmt->bind_param("si", $original_text, $linked_siri);
                $stmt->execute();
                $resolved_sessions[$original_text] = $conn->insert_id;
            } else {
                $resolved_sessions[$original_text] = (int)$decision;
            }
        }

        // Resolve Levels
        $resolved_levels = [];
        foreach ($map_levels as $original_text => $decision) {
            if ($decision === 'NEW') {
                $linked_session_id = 0;
                foreach ($import_data as $row) {
                    if ($row['level'] === $original_text) {
                        $linked_session_id = $resolved_sessions[$row['session']] ?? 0;
                        break;
                    }
                }
                $stmt = $conn->prepare("INSERT INTO levels (session_id, level_name) VALUES (?, ?)");
                $stmt->bind_param("is", $linked_session_id, $original_text);
                $stmt->execute();
                $resolved_levels[$original_text] = $conn->insert_id;
            } else {
                $resolved_levels[$original_text] = (int)$decision;
            }
        }

        $stmtStu = $conn->prepare("INSERT INTO students (student_name, gender, year, school_id, level_id, susunan_umur) VALUES (?, ?, ?, ?, ?, ?)");
        $created_groups   = [];
        $resolved_groups  = [];

        foreach ($import_data as $index => $row) {
            if (isset($skip_students[$index]) && $skip_students[$index] === '1') continue;

            $s_id = $resolved_schools[$row['school']] ?? 0;
            $l_id = $resolved_levels[$row['level']]   ?? 0;

            $gender = $student_gender[$index] ?? $row['gender'];
            if (empty($gender)) $gender = detectGenderFromName($row['name']);
            if (empty($gender)) $gender = 'Male';
            $gender = ($gender === 'Female') ? 'Female' : 'Male';

            $year = trim((string)($student_year[$index] ?? $row['year']));
            // Anything that isn't a clean, plausible 4-digit year (empty, a
            // typo, stray text from a misread column, …) falls back to the
            // current year rather than being passed straight through to the
            // YEAR(4) column.
            if ($year === '' || !ctype_digit($year) || (int)$year < 2000 || (int)$year > 2100) {
                $year = (string)$current_year;
            }

            $susunan_umur = $student_susunan_umur[$index] ?? $row['susunan_umur'];

            if ($s_id > 0 && $l_id > 0) {
                $stmtStu->bind_param("sssiis", $row['name'], $gender, $year, $s_id, $l_id, $susunan_umur);
                $stmtStu->execute();
                $new_student_id = $conn->insert_id;
                $successCount++;

                // ── Insert attendance if kehadiran data is present ──
                $kehadiran_raw = strtoupper(trim($row['kehadiran'] ?? ''));
                if ($kehadiran_raw === 'YA' || $kehadiran_raw === 'TIDAK' || $kehadiran_raw === 'PRESENT' || $kehadiran_raw === 'ABSENT') {
                    $att_status = ($kehadiran_raw === 'YA' || $kehadiran_raw === 'PRESENT') ? 'Present' : 'Absent';
                    $att_sess_id = $resolved_sessions[$row['session']] ?? ($default_session_id > 0 ? $default_session_id : 1);
                    $stmtAtt = $conn->prepare("INSERT INTO attendance (student_id, status, session_id, updated_by) VALUES (?, ?, ?, NULL)");
                    $stmtAtt->bind_param("isi", $new_student_id, $att_status, $att_sess_id);
                    $stmtAtt->execute();
                    $stmtAtt->close();
                }

                $chosen_group = $student_group[$index] ?? 'NONE';
                $group_id = 0;

                if ($chosen_group === 'NEW') {
                    $new_group_name = trim($student_new_group[$index] ?? '');
                    if (!empty($new_group_name)) {
                        if (isset($created_groups[$new_group_name])) {
                            $group_id = $created_groups[$new_group_name];
                        } else {
                            // session_id intentionally omitted — it's derived
                            // from level_id via levels.session_id.
                            $stmtG = $conn->prepare("INSERT INTO `groups` (level_id, group_name, judge_id) VALUES (?, ?, NULL)");
                            $stmtG->bind_param("is", $l_id, $new_group_name);
                            $stmtG->execute();
                            $group_id = $conn->insert_id;
                            $created_groups[$new_group_name] = $group_id;
                            $stmtG->close();
                        }
                    }
                } elseif ($chosen_group !== 'NONE' && is_numeric($chosen_group)) {
                    $group_id = (int)$chosen_group;
                }

                if ($group_id > 0) {
                    $stmtGS = $conn->prepare("INSERT INTO group_students (group_id, student_id) VALUES (?, ?)");
                    $stmtGS->bind_param("ii", $group_id, $new_student_id);
                    $stmtGS->execute();
                    $stmtGS->close();
                    $resolved_groups[$index] = $group_id;
                }
            }
        }

        foreach (array_unique(array_values($resolved_groups)) as $gid) {
            $judge_decision = $group_judge[$gid] ?? null;
            if ($judge_decision !== null && $judge_decision !== 'NONE' && is_numeric($judge_decision)) {
                $jid    = (int)$judge_decision;
                $stmtJA = $conn->prepare("UPDATE `groups` SET judge_id = ? WHERE group_id = ?");
                $stmtJA->bind_param("ii", $jid, $gid);
                $stmtJA->execute();
                $stmtJA->close();
            }
        }

        $conn->commit();
        unset($_SESSION['staged_data'], $_SESSION['staged_preconfig']);
        $message = "Selesai! {$successCount} rekod pelajar berjaya disimpan ke pangkalan data.";
        $msgType = 'success';
        $stage   = 'upload';

    } catch (Exception $e) {
        $conn->rollback();
        promarkah_report('Caught', 'upload_students.php import failed — ' . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
        $message = "Ralat Pangkalan Data: " . $e->getMessage();
        $msgType = 'error';
        $stage   = 'upload';
    }
}

// ── STAGE: SELECT SHEETS (new intermediate step) ──────────────────────────
$sheetPickerData = null;
if ($stage === 'select_sheets' && (!isset($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token']))) {
    $message = 'Ralat token keselamatan. Sila muat semula halaman.';
    $msgType = 'error';
    $stage   = 'upload';
} elseif ($stage === 'select_sheets') {
    if (isset($_FILES['upload_file']) && $_FILES['upload_file']['error'] === UPLOAD_ERR_OK) {
        $origName = $_FILES['upload_file']['name'];
        $fileExt  = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $maxBytes = 10 * 1024 * 1024; // 10MB — generous for a student roster spreadsheet
        $allowedMime = [
            'csv'  => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
            'xlsx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream'],
        ];
        $detectedMime = function_exists('mime_content_type') ? mime_content_type($_FILES['upload_file']['tmp_name']) : '';
        if (!in_array($fileExt, ['csv', 'xlsx'])) {
            $message = "Format fail tidak disokong. Sila muat naik format .csv atau .xlsx";
            $msgType = 'error';
            $stage   = 'upload';
        } elseif ($_FILES['upload_file']['size'] > $maxBytes) {
            $message = "Fail terlalu besar. Saiz maksimum dibenarkan ialah 10MB.";
            $msgType = 'error';
            $stage   = 'upload';
        } elseif ($detectedMime && !in_array($detectedMime, $allowedMime[$fileExt])) {
            $message = "Kandungan fail tidak sepadan dengan jenis fail (.{$fileExt}). Sila muat naik fail yang sah.";
            $msgType = 'error';
            $stage   = 'upload';
        } else {
            // Persist file across requests
            $tmpPath = sys_get_temp_dir() . '/prosilat_' . session_id() . '.' . $fileExt;
            move_uploaded_file($_FILES['upload_file']['tmp_name'], $tmpPath);
            $_SESSION['staged_file'] = ['path' => $tmpPath, 'ext' => $fileExt, 'name' => $origName];

            if ($fileExt === 'csv') {
                // CSV has no sheets — go straight to review config
                $sheetPickerData = ['sheets' => ['CSV' => ['rows' => 0, 'cols' => ['nama'], 'valid' => true, 'auto' => true]], 'ext' => 'csv', 'name' => $origName];
            } else {
                // ── Zip-bomb guard ────────────────────────────────────────
                // .xlsx is a zip archive; a tiny, maliciously crafted one can
                // decompress to gigabytes and exhaust memory/CPU before
                // SimpleXLSX ever gets to parse it. Inspect the archive's own
                // uncompressed-size headers first — this is metadata only
                // (no decompression happens here), so it's cheap even on a
                // hostile file, and it runs before SimpleXLSX touches the
                // file at all, so it can't change behavior for a normal
                // roster spreadsheet.
                $zipBombLimit = 200 * 1024 * 1024; // 200MB uncompressed — generous for any real roster
                $zip = new \ZipArchive();
                $zipRejected = false;
                if ($zip->open($tmpPath) === true) {
                    $totalUncompressed = 0;
                    for ($zi = 0; $zi < $zip->numFiles; $zi++) {
                        $stat = $zip->statIndex($zi);
                        if ($stat) {
                            $totalUncompressed += $stat['size'];
                            if ($totalUncompressed > $zipBombLimit) {
                                $zipRejected = true;
                                break;
                            }
                        }
                    }
                    $zip->close();
                } else {
                    // Not a valid zip at all — let SimpleXLSX::parse() below
                    // produce its normal "Gagal membaca fail Excel" message
                    // instead of guessing here.
                }

                if ($zipRejected) {
                    $message = "Fail Excel ini mengandungi kandungan termampat yang terlalu besar untuk diproses. Sila semak fail anda.";
                    $msgType = 'error'; $stage = 'upload'; @unlink($tmpPath);
                } else {

                $xlsx = Shuchkin\SimpleXLSX::parse($tmpPath);
                if (!$xlsx) {
                    $message = "Gagal membaca fail Excel: " . Shuchkin\SimpleXLSX::parseError();
                    $msgType = 'error'; $stage = 'upload'; @unlink($tmpPath);
                } else {
                    $sheetInfos = [];
                    foreach ($xlsx->sheetNames() as $si => $sname) {
                        $rows = $xlsx->rows($si);
                        $dataRows = max(0, count($rows) - 1);
                        $found = [];
                        $maxScan = min(count($rows), 15);
                        for ($r = 0; $r < $maxScan; $r++) {
                            foreach ($rows[$r] as $v) {
                                $c = strtolower(trim(preg_replace('/\s+/',' ',(string)$v)));
                                if ($c === '') continue;
                                if (strpos($c,'nama')!==false||strpos($c,'peserta')!==false) $found['Nama']=true;
                                if (strpos($c,'cawangan')!==false||strpos($c,'sekolah')!==false||strpos($c,'kontinjen')!==false) $found['Cawangan']=true;
                                if (strpos($c,'sidang')!==false||strpos($c,'session')!==false) $found['Sidang']=true;
                                if (strpos($c,'pinggang')!==false||strpos($c,'peringkat')!==false||strpos($c,'bengkung')!==false) $found['Peringkat']=true;
                                if (strpos($c,'jantina')!==false||strpos($c,'gender')!==false) $found['Jantina']=true;
                                if (strpos($c,'kumpulan')!==false||strpos($c,'group')!==false) $found['Kumpulan']=true;
                                if (strpos($c,'umur')!==false||strpos($c,'susunan')!==false||strpos($c,'age')!==false) $found['Umur']=true;
                                if (strpos($c,'kehadiran')!==false||strpos($c,'hadir')!==false||strpos($c,'attendance')!==false) $found['Kehadiran']=true;
                            }
                        }
                        $sheetInfos[$sname] = [
                            'rows'  => $dataRows,
                            'cols'  => array_keys($found),
                            'valid' => isset($found['Nama']),
                        ];
                    }
                    $sheetPickerData = ['sheets' => $sheetInfos, 'ext' => 'xlsx', 'name' => $origName];
                }

                }
            }
        }
    } else {
        $message = "Sila muat naik fail yang sah."; $msgType = 'error'; $stage = 'upload';
    }
}

// ── STAGE: PARSE EXCEL/CSV → REVIEW ───────────────────────────────────────
$reviewData = null;
if ($stage === 'review' && (!isset($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token']))) {
    $message = 'Ralat token keselamatan. Sila muat semula halaman.';
    $msgType = 'error';
    $stage   = 'upload';
} elseif ($stage === 'review') {
    $pc = pm_resolve_import_preconfig($conn, $current_year, $existing_siri, $existing_sessions, $existing_session_siri, $existing_levels, $existing_level_session);
    $preconfig               = $pc['preconfig'];
    $review_target_siri_id   = $pc['review_target_siri_id'];
    $default_session_id      = $pc['default_session_id'];
    $default_session_name    = $pc['default_session_name'];
    $sessions_in_target_siri = $pc['sessions_in_target_siri'];
    $levels_in_target_siri   = $pc['levels_in_target_siri'];
    $_SESSION['staged_preconfig'] = $preconfig;

    $stagedFile   = $_SESSION['staged_file'] ?? null;
    $selected_sheets = $_POST['selected_sheets'] ?? [];

    if (!$stagedFile || !file_exists($stagedFile['path'])) {
        $message = "Sesi tamat atau fail hilang. Sila muat naik semula.";
        $msgType = 'error'; $stage = 'upload';
    } else {
        $fileTmpPath = $stagedFile['path'];
        $fileExt     = $stagedFile['ext'];

        $sheets = [];
        $parseSuccess = true;

        if ($fileExt === 'csv') {
            $csvRows = [];
            if (($handle = fopen($fileTmpPath, 'r')) !== false) {
                while (($data = fgetcsv($handle, 1000, ",")) !== false) $csvRows[] = $data;
                fclose($handle);
            }
            if (!empty($csvRows)) $sheets['CSV'] = $csvRows;
        } elseif ($fileExt === 'xlsx') {
            if ($xlsx = Shuchkin\SimpleXLSX::parse($fileTmpPath)) {
                foreach ($xlsx->sheetNames() as $sheetIndex => $sheetName) {
                    // Only process sheets the PIC selected
                    if (!empty($selected_sheets) && !in_array($sheetName, $selected_sheets)) continue;
                    $sheetRows = $xlsx->rows($sheetIndex);
                    if (!empty($sheetRows)) $sheets[$sheetName] = $sheetRows;
                }
            } else {
                $parseSuccess = false;
                $message = "Gagal membaca fail Excel: " . Shuchkin\SimpleXLSX::parseError();
                $msgType = 'error'; $stage = 'upload';
            }
        } else {
            $parseSuccess = false;
            $message = "Format fail tidak disokong.";
            $msgType = 'error'; $stage = 'upload';
        }

        if ($parseSuccess && !empty($sheets)) {
            $parsed_students = [];

            // ── TWO-PASS PARSING ──────────────────────────────────────────
            // Some sheets (e.g. "(SEMUA) PENERIMA T.PINGGANG") list every
            // belt level a student has ever earned — not just their competition
            // entry. Processing them as primary sources creates one record per
            // belt level, massively over-counting students.
            //
            // Pass 1 — PRIMARY sheets: create student records, keyed by name||level.
            //   These are competition entry lists (susunan/group sheets).
            // Pass 2 — SUPPLEMENTARY sheets: fill missing fields only, never
            //   create new records. Identified by sheet name patterns below.
            //
            // The dedup key is name||level so dual-category students (same name,
            // different level/tali pinggang) each get their own record.

            function isSupplementarySheet(string $name): bool {
                $n = strtolower(trim($name));
                return strpos($n, 'semua') !== false
                    || strpos($n, 'penerima') !== false
                    || strpos($n, 'all ') !== false;
            }

            // Separate sheets into primary and supplementary
            $primary_sheets       = [];
            $supplementary_sheets = [];
            foreach ($sheets as $sheetName => $rows) {
                if (isSupplementarySheet($sheetName)) {
                    $supplementary_sheets[$sheetName] = $rows;
                } else {
                    $primary_sheets[$sheetName] = $rows;
                }
            }
            // If everything was classified as supplementary (edge case: PIC only
            // selected supplementary sheets), fall back to treating all as primary.
            if (empty($primary_sheets)) {
                $primary_sheets       = $sheets;
                $supplementary_sheets = [];
            }

            // Helper: parse rows from a sheet given its rows array.
            // Returns array of student field arrays.
            $parseSheetRows = function(array $rows, array $preconfig, string $default_session_name, int $default_session_id, string $current_year) use (&$detectGenderFromName): array {
                $headerRowIndex = -1;
                $colMap = [];
                $maxRowsToCheck = min(count($rows), 15);
                $bestHeaderRowIndex = -1;
                $bestColMap = [];
                $maxMappedCols = 0;

                for ($rowIndex = 0; $rowIndex < $maxRowsToCheck; $rowIndex++) {
                    $row = $rows[$rowIndex];
                    $tempColMap = [];
                    foreach ($row as $colIndex => $colVal) {
                        $c = strtolower(trim(preg_replace('/\s+/', ' ', (string)$colVal)));
                        if ($c === '') continue;
                        if (!isset($tempColMap['susunan_umur']) && (strpos($c, 'umur') !== false || strpos($c, 'susunan') !== false || strpos($c, 'age') !== false)) {
                            $tempColMap['susunan_umur'] = $colIndex;
                        } elseif (!isset($tempColMap['kehadiran']) && (strpos($c, 'kehadiran') !== false || strpos($c, 'hadir') !== false || strpos($c, 'attendance') !== false)) {
                            $tempColMap['kehadiran'] = $colIndex;
                        } elseif (!isset($tempColMap['group']) && (strpos($c, 'kumpulan') !== false || strpos($c, 'group') !== false || strpos($c, 'kump') !== false)) {
                            $tempColMap['group'] = $colIndex;
                        } elseif (!isset($tempColMap['name']) && (strpos($c, 'nama') !== false || strpos($c, 'peserta') !== false)) {
                            $tempColMap['name'] = $colIndex;
                        } elseif (!isset($tempColMap['school']) && (strpos($c, 'cawangan') !== false || strpos($c, 'sekolah') !== false || strpos($c, 'gelanggang') !== false || strpos($c, 'kontinjen') !== false)) {
                            $tempColMap['school'] = $colIndex;
                        } elseif (!isset($tempColMap['gender']) && (strpos($c, 'jantina') !== false || strpos($c, 'gender') !== false || $c === 'j' || $c === 'l/p')) {
                            $tempColMap['gender'] = $colIndex;
                        } elseif (!isset($tempColMap['session']) && (strpos($c, 'sidang') !== false || strpos($c, 'session') !== false)) {
                            $tempColMap['session'] = $colIndex;
                        } elseif (!isset($tempColMap['level']) && (strpos($c, 'pinggang') !== false || strpos($c, 'bengkung') !== false || strpos($c, 'peringkat') !== false)) {
                            $tempColMap['level'] = $colIndex;
                        } elseif (!isset($tempColMap['year']) && (strpos($c, 'tahun') !== false || strpos($c, 'year') !== false)) {
                            $tempColMap['year'] = $colIndex;
                        }
                    }
                    $mappedCount = count($tempColMap);
                    if (isset($tempColMap['name']) && $mappedCount > $maxMappedCols) {
                        $maxMappedCols = $mappedCount;
                        $bestHeaderRowIndex = $rowIndex;
                        $bestColMap = $tempColMap;
                    }
                }

                if ($bestHeaderRowIndex === -1 || !isset($bestColMap['name'])) return [];

                $result = [];
                for ($i = $bestHeaderRowIndex + 1; $i < count($rows); $i++) {
                    $r = $rows[$i];
                    $name   = isset($bestColMap['name'])   ? trim((string)($r[$bestColMap['name']]   ?? '')) : '';
                    $school = isset($bestColMap['school'])  ? trim((string)($r[$bestColMap['school']]  ?? '')) : '';
                    if (empty($name)) continue;

                    $raw_gender = isset($bestColMap['gender']) ? strtoupper(trim((string)($r[$bestColMap['gender']] ?? ''))) : '';
                    $gender = '';
                    $gender_auto = false;
                    if ($raw_gender === 'L' || $raw_gender === 'LELAKI' || $raw_gender === 'MALE' || $raw_gender === 'M') {
                        $gender = 'Male';
                    } elseif ($raw_gender === 'P' || $raw_gender === 'PEREMPUAN' || $raw_gender === 'FEMALE' || $raw_gender === 'F') {
                        $gender = 'Female';
                    } else {
                        $gender = $detectGenderFromName($name);
                        $gender_auto = !empty($gender);
                    }

                    $year      = isset($bestColMap['year'])        ? trim((string)($r[$bestColMap['year']]        ?? '')) : '';
                    $session   = isset($bestColMap['session'])      ? trim((string)($r[$bestColMap['session']]      ?? '')) : '';
                    $level     = isset($bestColMap['level'])        ? trim((string)($r[$bestColMap['level']]        ?? '')) : '';
                    $group     = isset($bestColMap['group'])        ? trim((string)($r[$bestColMap['group']]        ?? '')) : '';
                    $sus_umur  = isset($bestColMap['susunan_umur']) ? trim((string)($r[$bestColMap['susunan_umur']] ?? '')) : '';
                    $kehadiran = isset($bestColMap['kehadiran'])    ? strtoupper(trim((string)($r[$bestColMap['kehadiran']] ?? ''))) : '';

                    $year_auto = false;
                    if (empty($year)) { $year = $preconfig['default_year']; $year_auto = true; }
                    if (empty($session) && $default_session_name) { $session = $default_session_name; }

                    $result[] = [
                        'name'         => $name,
                        'gender'       => $gender,
                        'year'         => $year,
                        'school'       => $school,
                        'session'      => $session,
                        'level'        => $level,
                        'group'        => $group,
                        'susunan_umur' => $sus_umur,
                        'kehadiran'    => $kehadiran,
                        'gender_auto'  => $gender_auto,
                        'year_auto'    => $year_auto,
                    ];
                }
                return $result;
            };

            // ── PASS 1: primary sheets — create records ───────────────────
            foreach ($primary_sheets as $sheetName => $rows) {
                if (empty($rows)) continue;
                $sheetStudents = $parseSheetRows($rows, $preconfig, $default_session_name, $default_session_id, $current_year);

                foreach ($sheetStudents as $newStudent) {
                    $name  = $newStudent['name'];
                    $level = $newStudent['level'];

                    // Key: name||level — dual-category students get separate records
                    $dedupeKey = preg_replace('/\s+/', ' ', strtolower(trim($name)));
                    if (!empty($level)) {
                        $dedupeKey .= '||' . preg_replace('/\s+/', ' ', strtolower(trim($level)));
                    }

                    if (!isset($parsed_students[$dedupeKey])) {
                        $parsed_students[$dedupeKey] = $newStudent;
                    } else {
                        // Merge: fill missing fields from this sheet
                        foreach ($newStudent as $field => $val) {
                            if (!empty($val) && empty($parsed_students[$dedupeKey][$field])) {
                                $parsed_students[$dedupeKey][$field] = $val;
                            }
                        }
                        // School: prefer longer/more complete spelling
                        $school = $newStudent['school'];
                        if (!empty($school) && strlen($school) > strlen($parsed_students[$dedupeKey]['school'] ?? '')) {
                            $parsed_students[$dedupeKey]['school'] = $school;
                        }
                    }
                }
            }

            // ── PASS 2: supplementary sheets — fill missing fields only ───
            foreach ($supplementary_sheets as $sheetName => $rows) {
                if (empty($rows)) continue;
                $sheetStudents = $parseSheetRows($rows, $preconfig, $default_session_name, $default_session_id, $current_year);

                foreach ($sheetStudents as $newStudent) {
                    $name  = $newStudent['name'];
                    $level = $newStudent['level'];

                    $dedupeKey = preg_replace('/\s+/', ' ', strtolower(trim($name)));
                    if (!empty($level)) {
                        $dedupeKey .= '||' . preg_replace('/\s+/', ' ', strtolower(trim($level)));
                    }

                    // Only fill — never create new records from supplementary sheets
                    if (isset($parsed_students[$dedupeKey])) {
                        foreach ($newStudent as $field => $val) {
                            if (!empty($val) && empty($parsed_students[$dedupeKey][$field])) {
                                $parsed_students[$dedupeKey][$field] = $val;
                            }
                        }
                        $school = $newStudent['school'];
                        if (!empty($school) && strlen($school) > strlen($parsed_students[$dedupeKey]['school'] ?? '')) {
                            $parsed_students[$dedupeKey]['school'] = $school;
                        }
                    }
                }
            }

            // 3. FINALIZE ARRAYS
            $import_data     = [];
            $unique_schools  = [];
            $unique_sessions = [];
            $unique_levels   = [];

            foreach ($parsed_students as $stu) {
                // Must have both name and school to be fully valid after all merging
                if (empty($stu['name']) || empty($stu['school'])) continue;
                
                $import_data[] = $stu;
                if (!empty($stu['school']))  $unique_schools[$stu['school']] = true;
                if (!empty($stu['session'])) $unique_sessions[$stu['session']] = true;
                if (!empty($stu['level']))   $unique_levels[$stu['level']] = true;
            }

            if (empty($import_data)) {
                $message = "Tiada data yang sah dijumpai dalam fail anda.";
                $msgType = 'error';
                $stage   = 'upload';
            } else {
                $_SESSION['staged_data'] = $import_data;
                $reviewData = [
                    'schools'  => array_keys($unique_schools),
                    'sessions' => array_keys($unique_sessions),
                    'levels'   => array_keys($unique_levels),
                    'students' => $import_data,
                ];
            }

        } elseif ($parseSuccess && empty($sheets)) {
            $message = "Tiada data dalam lembaran yang dipilih.";
            $msgType = 'error'; $stage = 'upload';
        }
    } // end file exists check
}

// ── STAGE: PARSE PDF ROSTER → REVIEW ──────────────────────────────────────
// The PDF itself is never uploaded to the server — pic_roster_check.php's
// same client-side pdf.js parsing (roster_pdf_parser.js) runs in the
// browser here too and posts back only the extracted { cawangan, groups[] }
// JSON. From there this builds the exact same $import_data shape the Excel
// path produces and reuses its review UI wholesale, so everything
// downstream (school/session/level mapping, duplicate detection, judge
// assignment, the 'process' stage) needs no PDF-specific handling at all —
// a PDF row and an Excel row look identical once they're both in
// $import_data.
if ($stage === 'review_pdf' && (!isset($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token']))) {
    $message = 'Ralat token keselamatan. Sila muat semula halaman.';
    $msgType = 'error';
    $stage   = 'upload';
} elseif ($stage === 'review_pdf') {
    $pc = pm_resolve_import_preconfig($conn, $current_year, $existing_siri, $existing_sessions, $existing_session_siri, $existing_levels, $existing_level_session);
    $preconfig               = $pc['preconfig'];
    $default_session_name    = $pc['default_session_name'];
    $sessions_in_target_siri = $pc['sessions_in_target_siri'];
    $levels_in_target_siri   = $pc['levels_in_target_siri'];
    $_SESSION['staged_preconfig'] = $preconfig;

    $pdfPayload = json_decode($_POST['pdf_data'] ?? '', true);
    $cawangan   = trim((string)($pdfPayload['cawangan'] ?? ''));
    $pdfGroups  = is_array($pdfPayload['groups'] ?? null) ? $pdfPayload['groups'] : [];

    if ($cawangan === '') {
        $message = "Nama cawangan tidak dapat disahkan. Sila kembali dan sahkan/isi nama cawangan sebelum meneruskan.";
        $msgType = 'error'; $stage = 'upload';
    } elseif (empty($pdfGroups)) {
        $message = "Tiada data pelajar dikesan dalam PDF ini.";
        $msgType = 'error'; $stage = 'upload';
    } else {
        $import_data     = [];
        $unique_schools  = [$cawangan => true];
        $unique_sessions = [];
        $unique_levels   = [];
        if ($default_session_name !== '') $unique_sessions[$default_session_name] = true;

        foreach ($pdfGroups as $grp) {
            $peringkat = trim((string)($grp['peringkat'] ?? ''));
            $kumNum    = (int)($grp['kumpulan'] ?? 0);
            $students  = is_array($grp['students'] ?? null) ? $grp['students'] : [];
            // Blank when peringkat/kumpulan is missing rather than a
            // malformed code — those rows simply land with "— Tiada
            // Kumpulan —" in the review table like an Excel row with no
            // Kumpulan column would, instead of a bogus "001--" group name.
            $groupName = ($peringkat !== '' && $kumNum > 0)
                ? pm_derive_group_name($cawangan, $peringkat, $kumNum)
                : '';

            if ($peringkat !== '') $unique_levels[$peringkat] = true;

            $pos = 0;
            foreach ($students as $name) {
                $name = trim((string)$name);
                if ($name === '') continue;
                $pos++;
                $gender = detectGenderFromName($name);
                $import_data[] = [
                    'name'         => $name,
                    'gender'       => $gender,
                    'year'         => $preconfig['default_year'] ?: $current_year,
                    'school'       => $cawangan,
                    'session'      => $default_session_name,
                    'level'        => $peringkat,
                    'group'        => $groupName,
                    // The PDF's own row numbering within a Kumpulan is the
                    // closest thing to an age-order sequence it carries —
                    // prefilled here but still a plain editable text field
                    // in the review table, same as an Excel-sourced value.
                    'susunan_umur' => (string)$pos,
                    'kehadiran'    => '',
                    'gender_auto'  => $gender !== '',
                    'year_auto'    => true,
                ];
            }
        }

        if (empty($import_data)) {
            $message = "Tiada pelajar yang sah dijumpai dalam PDF ini.";
            $msgType = 'error'; $stage = 'upload';
        } else {
            $_SESSION['staged_data'] = $import_data;
            $reviewData = [
                'schools'  => array_keys($unique_schools),
                'sessions' => array_keys($unique_sessions),
                'levels'   => array_keys($unique_levels),
                'students' => $import_data,
            ];
            $stage = 'review'; // reuse the exact same review/mapping UI as the Excel path
        }
    }
}

$pm_page = 'upload_students';
include 'layout.php';
?>

<?php
$pm_us_css_v = @filemtime(__DIR__ . '/upload_students.css') ?: time();
?>
<link rel="stylesheet" href="upload_students.css?v=<?= $pm_us_css_v ?>">

<div class="upload-page-wrapper" id="uploadPageWrapper">

    <?php if ($message): ?>
        <div style="background:var(--c-surface-2);border:1px solid var(--c-border-strong);border-left:4px solid <?= $msgType==='success' ? '#4ADE80' : 'var(--c-red)' ?>;color:<?= $msgType==='success' ? '#4ADE80' : '#F87171' ?>;padding:12px 16px;border-radius:6px;margin-bottom:20px;font-weight:600;font-size:0.9rem;">
            <?= $msgType === 'success' ? '✅' : '❌' ?> <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($stage === 'upload'): ?>

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
            <div>
                <h2 style="font-family:'Bebas Neue',sans-serif;font-size:1.8rem;color:var(--c-white);letter-spacing:0.05em;margin:0 0 4px 0;">📂 Muat Naik Pelajar</h2>
                <div style="color:var(--c-text-faint);font-size:0.85rem;">Menyokong fail .csv, .xlsx (Excel Pelbagai Tab) melalui SimpleXLSX, atau .pdf (Senarai Nama Peserta rasmi).</div>
            </div>
            <a href="pic.php" class="pm-btn pm-btn-ghost">
                <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Kembali
            </a>
        </div>

        <div style="background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:8px;padding:11px 16px;margin-bottom:16px;font-size:0.82rem;color:var(--c-text-muted);line-height:1.6;">
            <strong style="color:var(--c-white);">Format Diterima —</strong>
            <span style="color:#f87171;">Wajib:</span> Nama Pelajar, Nama Cawangan &nbsp;·&nbsp;
            <span>Pilihan:</span> Susunan Umur, Jantina, Tahun Kejohanan, Nama Sidang, Nama Peringkat, Nama Kumpulan.
            <br>Untuk fail PDF, cawangan, peringkat &amp; kumpulan dikesan secara automatik daripada tajuk dan kandungan dokumen.
        </div>

        <div class="pm-card" style="padding:28px;">
            <form action="upload_students.php" method="POST" enctype="multipart/form-data" id="uploadForm">
                <input type="hidden" name="stage" value="select_sheets">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <div class="upload-zone" id="uploadZone">
                    <!-- File input covers full zone, invisible -->
                    <input type="file" name="upload_file" id="uploadFileInput" accept=".csv, .xlsx, .pdf" required>

                    <!-- Icon -->
                    <div class="upload-icon-wrap">
                        <svg viewBox="0 0 24 24" width="28" height="28" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" style="color:var(--c-text-muted);transition:stroke 0.25s;">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="17 8 12 3 7 8"/>
                            <line x1="12" y1="3" x2="12" y2="15"/>
                        </svg>
                    </div>

                    <!-- Text -->
                    <h3 style="font-family:'Bebas Neue',sans-serif;font-size:1.4rem;color:var(--c-white);letter-spacing:0.06em;margin:0 0 8px;">Muat Naik Fail</h3>
                    <p style="color:var(--c-text-faint);font-size:0.83rem;margin:0;line-height:1.6;">Seret & lepas fail ke sini, atau klik untuk memilih<br><span style="color:var(--c-text-muted);font-size:0.76rem;">Menyokong .csv, .xlsx (Excel berbilang tab) dan .pdf (Senarai Rasmi)</span></p>

                    <!-- CTA button (visual only, clicks pass to input above) -->
                    <div class="upload-cta-btn" id="uploadCtaBtn">
                        <svg viewBox="0 0 24 24" width="15" height="15" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        Pilih Fail
                    </div>
                </div>

                <!-- File selected info (shown after file chosen) -->
                <div class="upload-file-info" id="fileChosen">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <span class="file-name" id="fileNameDisplay">—</span>
                    <button type="button" class="file-remove" id="fileClearBtn" title="Buang fail">✕</button>
                </div>

                <!-- Excel/CSV path: submits this form straight to the sheet-picker stage -->
                <div style="margin-top:14px;display:none;" id="uploadSubmitWrap">
                    <button type="submit" class="pm-btn pm-btn-primary" style="padding:11px 32px;font-size:0.9rem;width:100%;">
                        Seterusnya: Pilih Lembaran →
                    </button>
                </div>
            </form>

            <!-- PDF path: file is parsed client-side (never uploaded) — this form posts only the extracted JSON -->
            <form action="upload_students.php" method="POST" id="pdfForm">
                <input type="hidden" name="stage" value="review_pdf">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="pdf_data" id="pdfDataInput">

                <div id="pdfParseStatus" style="margin-top:10px;font-size:0.85rem;color:var(--c-text-faint);"></div>

                <div id="pdfPreviewWrap" style="display:none;margin-top:16px;">
                    <div class="pc-field" style="max-width:420px;margin-bottom:16px;">
                        <label>Cawangan Dikesan <span style="text-transform:none;letter-spacing:0;font-weight:400;color:var(--c-text-faint);">(sahkan/betulkan jika perlu)</span></label>
                        <input type="text" id="pdfCawanganInput" required placeholder="cth. Kangkar Pulai Sesi 4">
                    </div>
                    <div id="pdfSummaryText" style="background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:8px;padding:11px 16px;margin-bottom:16px;font-size:0.82rem;color:var(--c-text-muted);line-height:1.6;"></div>

                    <div class="preconfig-grid" style="margin-top:0;">
                        <div class="pc-field">
                            <label>Siri Pertandingan</label>
                            <select name="siri_id" id="pdfSiriSelect">
                                <?php foreach ($existing_siri as $id => $name):
                                    $isSiri1 = (stripos($name, 'siri 1') !== false); ?>
                                <option value="<?= $id ?>" <?= $isSiri1 ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                                <?php endforeach; ?>
                                <option value="NEW" <?= empty($existing_siri) ? 'selected' : '' ?>>+ Cipta Siri Baharu...</option>
                            </select>
                            <div class="pc-new-input" id="pdfNewSiriWrap" <?= empty($existing_siri) ? 'style="display:block;"' : '' ?>>
                                <input type="text" name="new_siri_name" placeholder="Nama Siri (cth. Siri 2)">
                                <input type="number" name="new_siri_year" placeholder="Tahun" value="<?= $current_year ?>" style="margin-top:6px;">
                            </div>
                        </div>
                        <div class="pc-field">
                            <label>Tahun Rekod Kejohanan</label>
                            <input type="number" name="default_year" value="<?= $current_year ?>" min="2020" max="2035">
                        </div>
                        <div class="pc-field">
                            <label>Sidang</label>
                            <select name="default_session" id="pdfSessionSelect">
                                <?php foreach ($existing_sessions as $id => $name):
                                    $isSidang1 = (stripos($name, 'sidang 1') !== false); ?>
                                <option value="<?= $id ?>" <?= $isSidang1 ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                                <?php endforeach; ?>
                                <option value="NEW" <?= empty($existing_sessions) ? 'selected' : '' ?>>+ Cipta Sidang Baharu...</option>
                            </select>
                            <div class="pc-new-input" id="pdfNewSessionWrap" <?= empty($existing_sessions) ? 'style="display:block;"' : '' ?>>
                                <input type="text" name="new_session_name" placeholder="cth. Sidang 2">
                            </div>
                        </div>
                    </div>

                    <div style="display:flex;justify-content:flex-end;margin-top:16px;">
                        <button type="submit" class="pm-btn pm-btn-primary" id="pdfSubmitBtn" style="padding:11px 32px;font-size:0.9rem;">
                            Seterusnya: Semak &amp; Sahkan Data →
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <?php if (empty($existing_judges)): ?>
        <div class="notice-banner" style="margin-top:16px;">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            Tiada juri dalam sistem. Tugasan juri kepada kumpulan tidak dapat dilakukan semasa import.
            <a href="pic_judges.php" class="pm-btn pm-btn-ghost" style="margin-left:auto;font-size:0.8rem;padding:5px 12px;">Tambah Juri →</a>
        </div>
        <?php endif; ?>

    <?php endif; ?>

    <?php if ($stage === 'select_sheets' && $sheetPickerData): ?>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px;">
        <div>
            <h2 style="font-size:1.25rem;font-weight:800;color:var(--c-white);margin:0 0 3px;letter-spacing:-0.02em;">📑 Pilih Lembaran untuk Diimport</h2>
            <div style="color:var(--c-text-faint);font-size:0.82rem;">
                📄 <strong style="color:var(--c-text-muted);"><?= htmlspecialchars($sheetPickerData['name']) ?></strong>
                &nbsp;·&nbsp;
                <?= count($sheetPickerData['sheets']) ?> lembaran dijumpai
            </div>
        </div>
        <a href="upload_students.php" class="pm-btn pm-btn-ghost" style="font-size:0.82rem;padding:8px 16px;">← Muat Naik Semula</a>
    </div>

    <form action="upload_students.php" method="POST" id="sheetPickerForm">
        <input type="hidden" name="stage" value="review">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

        <div class="pm-card" style="padding:24px;">
            <div class="sp-section-label">Lembaran dalam Fail</div>

            <div class="sheet-picker-grid">
            <?php foreach ($sheetPickerData['sheets'] as $sname => $sinfo):
                $isValid = $sinfo['valid'];
                $isAuto  = $sinfo['auto'] ?? false;
                $keyTags = ['Nama', 'Cawangan'];
                $optTags = ['Sidang', 'Peringkat', 'Jantina', 'Kumpulan', 'Umur'];
            ?>
            <label class="sheet-card <?= !$isValid && !$isAuto ? 'invalid' : '' ?>" id="card-<?= md5($sname) ?>" onclick="toggleSheet(this, '<?= addslashes($sname) ?>')">
                <input type="checkbox" name="selected_sheets[]" value="<?= htmlspecialchars($sname) ?>"
                    <?= ($isValid || $isAuto) ? 'checked' : '' ?>
                    <?= !$isValid && !$isAuto ? 'disabled' : '' ?>>
                <div class="sheet-check">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div style="flex:1; min-width:0;">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <span class="sheet-name"><?= htmlspecialchars($sname) ?></span>
                        <?php if ($isAuto): ?>
                            <span class="sheet-badge-valid">CSV</span>
                        <?php elseif ($isValid): ?>
                            <span class="sheet-badge-valid">● Data Ditemui</span>
                        <?php else: ?>
                            <span class="sheet-badge-invalid">Tiada Kolum Pelajar</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!$isAuto): ?>
                    <div class="sheet-meta"><?= number_format($sinfo['rows']) ?> baris dijangka</div>
                    <?php endif; ?>
                    <?php if (!empty($sinfo['cols'])): ?>
                    <div class="sheet-cols">
                        <?php foreach ($sinfo['cols'] as $col): ?>
                            <span class="sheet-col-tag <?= in_array($col, $keyTags) ? 'key' : '' ?>"><?= $col ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </label>
            <?php endforeach; ?>
            </div>

            <div class="sp-divider"></div>

            <div class="sp-section-label">Tetapan Import</div>
            <div class="preconfig-grid sp-preconfig-grid" style="margin-top:0;">
                <div class="pc-field">
                    <label>Siri Pertandingan</label>
                    <select name="siri_id" id="siriSelect">
                        <?php foreach ($existing_siri as $id => $name):
                            $isSiri1 = (stripos($name, 'siri 1') !== false); ?>
                        <option value="<?= $id ?>" <?= $isSiri1 ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                        <option value="NEW" <?= empty($existing_siri) ? 'selected' : '' ?>>+ Cipta Siri Baharu...</option>
                    </select>
                    <div class="pc-new-input" id="newSiriWrap" <?= empty($existing_siri) ? 'style="display:block;"' : '' ?>>
                        <input type="text" name="new_siri_name" placeholder="Nama Siri (cth. Siri 2)">
                        <input type="number" name="new_siri_year" placeholder="Tahun" value="<?= $current_year ?>" style="margin-top:6px;">
                    </div>
                </div>
                <div class="pc-field">
                    <label>Tahun Rekod Kejohanan</label>
                    <input type="number" name="default_year" value="<?= $current_year ?>" min="2020" max="2035">
                </div>
                <div class="pc-field">
                    <label>Sidang Lalai</label>
                    <select name="default_session" id="sessionSelect">
                        <?php foreach ($existing_sessions as $id => $name):
                            $isSidang1 = (stripos($name, 'sidang 1') !== false); ?>
                        <option value="<?= $id ?>" <?= $isSidang1 ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                        <option value="NEW" <?= empty($existing_sessions) ? 'selected' : '' ?>>+ Cipta Sidang Baharu...</option>
                    </select>
                    <div class="pc-new-input" id="newSessionWrap" <?= empty($existing_sessions) ? 'style="display:block;"' : '' ?>>
                        <input type="text" name="new_session_name" placeholder="cth. Sidang 2">
                    </div>
                </div>
            </div>
        </div>

        <div style="display:flex;justify-content:space-between;margin-top:16px;">
            <a href="upload_students.php" class="pm-btn pm-btn-ghost">← Kembali</a>
            <button type="submit" class="pm-btn pm-btn-primary" id="importBtn" style="padding:10px 28px;font-size:0.9rem;">
                Semak &amp; Sahkan Data →
            </button>
        </div>
    </form>

    <?php endif; ?>

    <?php if ($stage === 'review' && $reviewData):
        $groups_in_batch = [];
        foreach ($reviewData['students'] as $stu) {
            $g = trim($stu['group'] ?? '');
            if (!empty($g)) $groups_in_batch[$g] = true;
        }
        // Precompute lowercase lookup once instead of re-mapping per student row (700+ rows × N groups)
        $existing_groups_lower = array_map('strtolower', $existing_groups);
    ?>

        <script>document.documentElement.classList.add('review-fullscreen');</script>
        <div class="review-shell">
        <div class="review-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;padding:0 4px;margin-bottom:10px;flex-wrap:wrap;">
            <div style="flex:1;">
                <h2 style="font-size:1.25rem;font-weight:800;color:var(--c-white);margin:0 0 5px;letter-spacing:-0.02em;">⚠️ Semak &amp; Sahkan Data</h2>
                <div style="color:var(--c-text-faint);font-size:0.84rem;line-height:1.5;"><?= count($reviewData['students']) ?> pelajar dikesan dari fail anda. Sila lalui langkah di bawah untuk pengesahan.</div>
            </div>
            <a href="upload_students.php" class="pm-btn pm-btn-ghost" style="font-size:0.82rem;padding:8px 14px;flex-shrink:0;white-space:nowrap;">✕ Batal</a>
        </div>

        <div class="pm-tabs" id="reviewTabs">
            <button type="button" class="pm-tab-btn active" data-target="tab-school">1. Cawangan</button>
            <button type="button" class="pm-tab-btn" data-target="tab-session">2. Sidang</button>
            <button type="button" class="pm-tab-btn" data-target="tab-level">3. Peringkat</button>
            <button type="button" class="pm-tab-btn" data-target="tab-student">4. Senarai Pelajar</button>
            <button type="button" class="pm-tab-btn" data-target="tab-judge">5. Tugasan Juri</button>
        </div>

        <form action="upload_students.php" method="POST" id="confirmForm">
            <input type="hidden" name="stage" value="process">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

            <div class="pm-tab-pane active" id="tab-school">
                <div class="review-section-card">
                    <div class="review-section-title"><span class="section-icon">🏫</span>Pemetaan Cawangan</div>
                    <div class="pm-table-wrap">
                        <script>const masterSchools = <?= json_encode($existing_schools, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;</script>
                        <table class="pm-table pm-auto-paginate map-table" data-total="<?= count($reviewData['schools']) ?>">
                            <thead><tr><th class="col-doc">Teks dalam Dokumen</th><th class="col-status">Status</th><th class="col-action">Tindakan</th></tr></thead>
                            <tbody>
                            <?php $__ri=0; foreach ($reviewData['schools'] as $schoolName):
                                $exactId = array_search(strtolower($schoolName), array_map('strtolower', $existing_schools));
                                $sim = ($exactId === false) ? findSimilar($schoolName, $existing_schools) : null;
                            ?>
                            <tr>
                                <td class="cell-docname"><?= htmlspecialchars($schoolName) ?></td>
                                <td>
                                    <?php if ($exactId !== false): ?>
                                        <span class="pm-badge pm-badge-green">Padanan Tepat</span>
                                    <?php elseif ($sim): ?>
                                        <span class="pm-badge pm-badge-amber">~<?= $sim['percent'] ?>% Serupa</span>
                                    <?php else: ?>
                                        <span class="pm-badge pm-badge-gray">Rekod Baharu</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <select name="map_school[<?= htmlspecialchars($schoolName) ?>]" class="pm-select fast-school-dropdown">
                                        <?php if ($exactId !== false): ?>
                                            <option value="<?= $exactId ?>">Gunakan: <?= htmlspecialchars($existing_schools[$exactId]) ?></option>
                                        <?php else: ?>
                                            <option value="NEW">+ Cipta: "<?= htmlspecialchars($schoolName) ?>"</option>
                                            <?php if ($sim): ?>
                                                <option value="<?= $sim['id'] ?>" selected>→ <?= htmlspecialchars($sim['name']) ?></option>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </select>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="wizard-footer">
                    <div></div>
                    <button type="button" class="pm-btn pm-btn-primary btn-next-tab" data-next="tab-session">Seterusnya →</button>
                </div>
            </div>

            <div class="pm-tab-pane" id="tab-session">
                <div class="review-section-card">
                    <div class="review-section-title"><span class="section-icon">📅</span>Pemetaan Sidang</div>
                    <?php if (empty($reviewData['sessions'])): ?>
                        <div style="padding:14px 20px;color:var(--c-text-faint);font-size:0.875rem;">Tiada data sidang dikesan dalam fail anda.</div>
                    <?php else: ?>
                    <div class="pm-table-wrap">
                        <table class="pm-table pm-auto-paginate map-table" data-total="<?= count($reviewData['sessions']) ?>">
                            <thead><tr><th class="col-doc">Teks dalam Dokumen</th><th class="col-status">Status</th><th class="col-action">Tindakan</th></tr></thead>
                            <tbody>
                            <?php $__ri=0; foreach ($reviewData['sessions'] as $sessName):
                                // Match only within the targeted siri — a same-named session
                                // belonging to a different siri must NOT be auto-selected here,
                                // otherwise this upload silently lands in the wrong siri.
                                $exactId = array_search(strtolower($sessName), array_map('strtolower', $sessions_in_target_siri));
                            ?>
                            <tr>
                                <td class="cell-docname"><?= htmlspecialchars($sessName) ?></td>
                                <td>
                                    <?php if ($exactId !== false): ?>
                                        <span class="pm-badge pm-badge-green">Padanan Tepat</span>
                                    <?php else: ?>
                                        <span class="pm-badge pm-badge-gray">Rekod Baharu</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <select name="map_session[<?= htmlspecialchars($sessName) ?>]" class="pm-select">
                                        <?php if ($exactId !== false): ?>
                                            <option value="<?= $exactId ?>" selected>Gunakan: <?= htmlspecialchars($existing_sessions[$exactId]) ?></option>
                                        <?php else: ?>
                                            <option value="NEW">+ Cipta: "<?= htmlspecialchars($sessName) ?>"</option>
                                        <?php endif; ?>
                                        <?php foreach ($existing_sessions as $id => $name): if ($id === $exactId) continue; ?>
                                            <option value="<?= $id ?>">→ <?= htmlspecialchars($name) ?><?= isset($sessions_in_target_siri[$id]) ? '' : ' (siri lain)' ?></option>
                                        <?php endforeach; ?>
                                        <?php if ($exactId !== false): ?>
                                            <option value="NEW">+ Cipta Baharu...</option>
                                        <?php endif; ?>
                                    </select>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="wizard-footer">
                    <button type="button" class="pm-btn pm-btn-ghost btn-prev-tab" data-prev="tab-school">← Kembali</button>
                    <button type="button" class="pm-btn pm-btn-primary btn-next-tab" data-next="tab-level">Seterusnya →</button>
                </div>
            </div>

            <div class="pm-tab-pane" id="tab-level">
                <div class="review-section-card">
                    <div class="review-section-title"><span class="section-icon">🎯</span>Pemetaan Peringkat</div>
                    <?php if (empty($reviewData['levels'])): ?>
                        <div style="padding:14px 20px;color:#f87171;font-size:0.875rem;">Tiada data Tali Pinggang/Peringkat. Pelajar mungkin tidak dapat dinilai dengan betul.</div>
                    <?php else: ?>
                    <div class="pm-table-wrap">
                        <table class="pm-table pm-auto-paginate map-table" data-total="<?= count($reviewData['levels']) ?>">
                            <thead><tr><th class="col-doc">Teks dalam Dokumen</th><th class="col-status">Status</th><th class="col-action">Tindakan</th></tr></thead>
                            <tbody>
                            <?php $__ri=0; foreach ($reviewData['levels'] as $levelName):
                                // Match only within sessions already scoped to the targeted
                                // siri (see $sessions_in_target_siri above) — same reasoning
                                // as the session mapping: an identically-named level under a
                                // different siri must not be auto-selected.
                                $exactId = array_search(strtolower($levelName), array_map('strtolower', $levels_in_target_siri));
                            ?>
                            <tr>
                                <td class="cell-docname"><?= htmlspecialchars($levelName) ?></td>
                                <td>
                                    <?php if ($exactId !== false): ?>
                                        <span class="pm-badge pm-badge-green">Padanan Tepat</span>
                                    <?php else: ?>
                                        <span class="pm-badge pm-badge-gray">Rekod Baharu</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <select name="map_level[<?= htmlspecialchars($levelName) ?>]" class="pm-select">
                                        <?php if ($exactId !== false): ?>
                                            <option value="<?= $exactId ?>" selected>Gunakan: <?= htmlspecialchars($existing_levels[$exactId]) ?></option>
                                        <?php else: ?>
                                            <option value="NEW">+ Cipta: "<?= htmlspecialchars($levelName) ?>"</option>
                                        <?php endif; ?>
                                        <?php foreach ($existing_levels as $id => $name): if ($id === $exactId) continue; ?>
                                            <option value="<?= $id ?>">→ <?= htmlspecialchars($name) ?><?= isset($levels_in_target_siri[$id]) ? '' : ' (siri/sidang lain)' ?></option>
                                        <?php endforeach; ?>
                                        <?php if ($exactId !== false): ?>
                                            <option value="NEW">+ Cipta Baharu...</option>
                                        <?php endif; ?>
                                    </select>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="wizard-footer">
                    <button type="button" class="pm-btn pm-btn-ghost btn-prev-tab" data-prev="tab-session">← Kembali</button>
                    <button type="button" class="pm-btn pm-btn-primary btn-next-tab" data-next="tab-student">Seterusnya →</button>
                </div>
            </div>

            <div class="pm-tab-pane" id="tab-student">
                <div class="review-section-card">
                    <div class="review-section-title"><span class="section-icon">🥋</span>Senarai Pelajar</div>
                    <div class="pm-table-wrap">
                        <script>const masterGroups = <?= json_encode($existing_groups, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;</script>
                        <table class="pm-table pm-auto-paginate" data-total="<?= count($reviewData['students']) ?>">
                            <thead>
                                <tr>
                                    <th class="col-skip">Abaikan</th>
                                    <th class="col-name">Nama Pelajar</th>
                                    <th class="col-umur">Susunan Umur</th>
                                    <th class="col-gender">Jantina</th>
                                    <th class="col-year">Tahun</th>
                                    <th class="col-group">Kumpulan</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php $__ri=0; foreach ($reviewData['students'] as $index => $stu):
                                $isDuplicate    = isset($existing_student_names_set[strtolower(trim($stu['name']))]);
                                $genderDetected = $stu['gender_auto'] ?? false;
                                $yearFilled     = $stu['year_auto']   ?? false;
                                $missingGender  = empty($stu['gender']);
                                $csvGroup       = $stu['group'] ?? '';
                                $groupIsNew     = !empty($csvGroup) && !in_array(strtolower($csvGroup), $existing_groups_lower);
                            ?>
                            <tr style="<?= $isDuplicate ? 'opacity:0.45;' : '' ?>">
                                <td class="col-skip">
                                    <input type="checkbox" name="skip_student[<?= $index ?>]" value="1"
                                        <?= $isDuplicate ? 'checked' : '' ?>
                                        style="accent-color:var(--c-red);width:16px;height:16px;cursor:pointer;">
                                </td>
                                <td class="col-name cell-docname">
                                    <?= htmlspecialchars($stu['name']) ?>
                                    <?php if ($isDuplicate): ?><br><span class="pm-badge pm-badge-red" style="margin-top:4px;">Duplikat</span><?php endif; ?>
                                </td>
                                <td class="col-umur">
                                    <input type="text" name="student_susunan_umur[<?= $index ?>]"
                                        value="<?= htmlspecialchars($stu['susunan_umur'] ?? '') ?>"
                                        placeholder="Umur" class="pm-input-sm">
                                </td>
                                <td class="col-gender">
                                    <select name="student_gender[<?= $index ?>]" class="pm-select">
                                        <option value="Male"   <?= ($stu['gender'] !== 'Female') ? 'selected' : '' ?>>Lelaki</option>
                                        <option value="Female" <?= ($stu['gender'] === 'Female') ? 'selected' : '' ?>>Perempuan</option>
                                    </select>
                                    <?php if ($missingGender): ?>
                                        <span class="tag tag-missing">Tiada</span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-year">
                                    <input type="number" name="student_year[<?= $index ?>]"
                                        value="<?= htmlspecialchars($stu['year'] ?: $current_year) ?>"
                                        min="2020" max="2035" class="pm-input-sm">
                                </td>
                                <?php
                                    // Only render the matched/selected group option here instead of looping
                                    // every existing group for every one of 700+ rows — the full list of
                                    // hundreds of groups was being duplicated per row (lazy-loaded below instead).
                                    $matchedGid = (!$groupIsNew && !empty($csvGroup)) ? array_search(strtolower($csvGroup), $existing_groups_lower) : false;
                                ?>
                                <td class="col-group">
                                    <select name="student_group[<?= $index ?>]" class="pm-select group-select fast-group-dropdown" data-idx="<?= $index ?>">
                                        <option value="NONE" <?= ($matchedGid === false && !$groupIsNew) ? 'selected' : '' ?>>— Tiada Kumpulan —</option>
                                        <?php if ($matchedGid !== false): ?>
                                            <option value="<?= $matchedGid ?>" selected><?= htmlspecialchars($existing_groups[$matchedGid]) ?></option>
                                        <?php endif; ?>
                                        <?php if ($groupIsNew && !empty($csvGroup)): ?>
                                            <option value="NEW" selected>+ Cipta: "<?= htmlspecialchars($csvGroup) ?>"</option>
                                        <?php else: ?>
                                            <option value="NEW">+ Kumpulan Baharu...</option>
                                        <?php endif; ?>
                                    </select>
                                    <?php if ($groupIsNew && !empty($csvGroup)): ?>
                                        <!-- Value pre-set; no visible text box needed -->
                                        <input type="hidden" name="student_new_group[<?= $index ?>]" value="<?= htmlspecialchars($csvGroup) ?>">
                                    <?php else: ?>
                                        <input type="text" name="student_new_group[<?= $index ?>]"
                                            placeholder="Nama kumpulan"
                                            class="pm-input-sm new-group-input" data-idx="<?= $index ?>"
                                            style="display:none;">
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="wizard-footer">
                    <button type="button" class="pm-btn pm-btn-ghost btn-prev-tab" data-prev="tab-level">← Kembali</button>
                    <?php if (empty($groups_in_batch)): ?>
                        <button type="submit" class="pm-btn pm-btn-primary">💾 Sahkan &amp; Simpan</button>
                    <?php else: ?>
                        <button type="button" class="pm-btn pm-btn-primary btn-next-tab" data-next="tab-judge">Seterusnya →</button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="pm-tab-pane" id="tab-judge">
                <div class="review-section-card">
                    <div class="review-section-title"><span class="section-icon">⚖</span>Tugasan Juri</div>
                    
                    <?php if (empty($groups_in_batch)): ?>
                        <div style="padding:14px 20px;color:var(--c-text-faint);">Tiada rekod kumpulan baharu/sedia ada dijumpai dalam fail yang dimuat naik.</div>
                    <?php elseif (empty($existing_judges)): ?>
                        <div style="padding:14px 20px;">
                            <div class="notice-banner">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                Tiada juri dalam sistem. Anda boleh teruskan sekarang dan menugaskan juri kemudian di panel pengurusan.
                            </div>
                        </div>
                    <?php else: ?>
                    <?php
                        // Single batch query instead of one SELECT per group (was N+1)
                        $group_judge_map = [];
                        if (!empty($existing_groups)) {
                            $gids = array_map('intval', array_keys($existing_groups));
                            $jRes = $conn->query("SELECT group_id, judge_id FROM `groups` WHERE group_id IN (" . implode(',', $gids) . ")");
                            while ($jRow = $jRes->fetch_assoc()) $group_judge_map[$jRow['group_id']] = $jRow['judge_id'];
                        }
                    ?>
                    <div class="pm-table-wrap">
                        <table class="pm-table pm-auto-paginate judge-table" data-total="<?= count($groups_in_batch) ?>">
                            <thead><tr><th class="col-group-name">Kumpulan</th><th class="col-judge">Juri</th></tr></thead>
                            <tbody>
                            <?php $__ri=0; foreach ($groups_in_batch as $gname => $_):
                                $existingGid  = array_search(strtolower($gname), $existing_groups_lower);
                                $currentJudge = ($existingGid !== false) ? ($group_judge_map[$existingGid] ?? null) : null;
                                $field_key = $existingGid !== false ? $existingGid : 'new_' . md5($gname);
                            ?>
                            <tr>
                                <td class="cell-docname">
                                    <?= htmlspecialchars($gname) ?>
                                    <?php if ($existingGid !== false): ?>
                                        <span class="tag tag-exists">Sedia Ada</span>
                                    <?php else: ?>
                                        <span class="tag tag-new">Baharu</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <select name="group_judge[<?= $field_key ?>]" class="pm-select">
                                        <option value="NONE">— Tanpa Juri buat masa ini —</option>
                                        <?php foreach ($existing_judges as $jid => $jname): ?>
                                            <option value="<?= $jid ?>" <?= ($currentJudge == $jid) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($jname) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="wizard-footer">
                    <button type="button" class="pm-btn pm-btn-ghost btn-prev-tab" data-prev="tab-student">← Kembali</button>
                    <button type="submit" class="pm-btn pm-btn-primary">
                        💾 Sahkan &amp; Simpan
                    </button>
                </div>
            </div>

        </form>

        </div><!-- /.review-shell -->

    <?php endif; ?>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<?php $pm_rpp_v = @filemtime(__DIR__ . '/roster_pdf_parser.js') ?: time(); ?>
<script src="roster_pdf_parser.js?v=<?= $pm_rpp_v ?>"></script>
<?php
$pm_us_js_v = @filemtime(__DIR__ . '/upload_students.js') ?: time();
?>
<script src="upload_students.js?v=<?= $pm_us_js_v ?>"></script>
</div><!-- /.upload-page-wrapper -->
</main>
</body>
</html>