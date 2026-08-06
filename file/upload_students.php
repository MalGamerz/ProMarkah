<?php
// ── Error handling & Session ──────────────────────────────────────────────
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

session_start();

// ── SECURITY: Only PIC and Admin may access this page ──────────────────────
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['pic', 'admin'])) {
    // Not logged in or wrong role — redirect to login
    header('Location: login.php');
    exit;
}
// ────────────────────────────────────────────────────────────────────────────

include 'db.php';
$conn = getDB();

// ── REQUIRE SIMPLEXLSX ────────────────────────────────────────────────────
require_once 'SimpleXLSX.php';

$stage   = $_POST['stage'] ?? 'upload';
$message = '';
$msgType = '';

// ── Helper: detect gender from bin/binti ─────────────────────────────────
function detectGenderFromName(string $name): string {
    $lower = strtolower($name);
    if (preg_match('/\bbinti\b/', $lower)) return 'Female';
    if (preg_match('/\bbin\b/',   $lower)) return 'Male';
    return '';
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
if ($stage === 'process') {
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
        $active_siri_id = $preconfig['siri_id'];
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

            $year = $student_year[$index] ?? $row['year'];
            if (empty($year)) $year = $current_year;

            $susunan_umur = $student_susunan_umur[$index] ?? $row['susunan_umur'];

            if ($s_id > 0 && $l_id > 0) {
                $stmtStu->bind_param("sssiis", $row['name'], $gender, $year, $s_id, $l_id, $susunan_umur);
                $stmtStu->execute();
                $new_student_id = $conn->insert_id;
                $successCount++;

                $chosen_group = $student_group[$index] ?? 'NONE';
                $group_id = 0;

                if ($chosen_group === 'NEW') {
                    $new_group_name = trim($student_new_group[$index] ?? '');
                    if (!empty($new_group_name)) {
                        if (isset($created_groups[$new_group_name])) {
                            $group_id = $created_groups[$new_group_name];
                        } else {
                            $sess_id = $resolved_sessions[$row['session']] ?? 0;
                            $stmtG = $conn->prepare("INSERT INTO `groups` (session_id, level_id, group_name, judge_id) VALUES (?, ?, ?, NULL)");
                            $stmtG->bind_param("iis", $sess_id, $l_id, $new_group_name);
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
        $message = "Ralat Pangkalan Data: " . $e->getMessage();
        $msgType = 'error';
        $stage   = 'upload';
    }
}

// ── STAGE: SELECT SHEETS (new intermediate step) ──────────────────────────
$sheetPickerData = null;
if ($stage === 'select_sheets') {
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
    } else {
        $message = "Sila muat naik fail yang sah."; $msgType = 'error'; $stage = 'upload';
    }
}

// ── STAGE: PARSE EXCEL/CSV → REVIEW ───────────────────────────────────────
$reviewData = null;
if ($stage === 'review') {
    $preconfig = [
        'siri_id'         => $_POST['siri_id']               ?? 0,
        'new_siri_name'   => trim($_POST['new_siri_name']    ?? ''),
        'new_siri_year'   => trim($_POST['new_siri_year']    ?? $current_year),
        'default_year'    => trim($_POST['default_year']     ?? $current_year),
        'default_session' => $_POST['default_session']       ?? 'NONE',
        'new_session_name'=> trim($_POST['new_session_name'] ?? ''),
    ];
    $_SESSION['staged_preconfig'] = $preconfig;

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
        $_SESSION['staged_preconfig'] = $preconfig;
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

            foreach ($sheets as $sheetName => $rows) {
                if (empty($rows)) continue;
                // No more hardcoded allowed_sheets filter — PIC chose the sheets

                $headerRowIndex = -1;
                $colMap = [];

                // 1. SMART HEADER DETECTION (SCANS FIRST 15 ROWS)
                // This prevents document titles from falsely triggering. Finds the row mapping the MOST columns.
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

                $headerRowIndex = $bestHeaderRowIndex;
                $colMap = $bestColMap;

                if ($headerRowIndex === -1 || !isset($colMap['name'])) {
                    continue;
                }

                // 2. EXTRACT DATA ROWS & CROSS-MERGE
                for ($i = $headerRowIndex + 1; $i < count($rows); $i++) {
                    $r = $rows[$i];
                    
                    $name   = isset($colMap['name']) ? trim((string)($r[$colMap['name']] ?? '')) : '';
                    $school = isset($colMap['school']) ? trim((string)($r[$colMap['school']] ?? '')) : '';

                    if (empty($name)) continue; 

                    $raw_gender = isset($colMap['gender']) ? strtoupper(trim((string)($r[$colMap['gender']] ?? ''))) : '';
                    $gender = '';
                    $gender_auto = false;
                    
                    if ($raw_gender === 'L' || $raw_gender === 'LELAKI' || $raw_gender === 'MALE' || $raw_gender === 'M' || $raw_gender === 'L/P') {
                        $gender = 'Male';
                    } elseif ($raw_gender === 'P' || $raw_gender === 'PEREMPUAN' || $raw_gender === 'FEMALE' || $raw_gender === 'F') {
                        $gender = 'Female';
                    } else {
                        $gender = detectGenderFromName($name);
                        $gender_auto = !empty($gender);
                    }

                    $year    = isset($colMap['year']) ? trim((string)($r[$colMap['year']] ?? '')) : '';
                    $session = isset($colMap['session']) ? trim((string)($r[$colMap['session']] ?? '')) : '';
                    $level   = isset($colMap['level']) ? trim((string)($r[$colMap['level']] ?? '')) : '';
                    $group   = isset($colMap['group']) ? trim((string)($r[$colMap['group']] ?? '')) : '';
                    $sus_umur= isset($colMap['susunan_umur']) ? trim((string)($r[$colMap['susunan_umur']] ?? '')) : '';

                    $year_auto = false;
                    if (empty($year)) {
                        $year      = $preconfig['default_year'];
                        $year_auto = true;
                    }
                    if (empty($session) && $default_session_name) {
                        $session = $default_session_name;
                    }

                    // ── Dedup key: normalized name only ──
                    // Same student appears across multiple sheets of the same competition file.
                    // Each sheet has different columns (one has school, another has level/group).
                    // We merge by name so one complete record is built from all sheets.
                    // School name variations across sheets would break a name+school key.
                    $dedupeKey = preg_replace('/\s+/', ' ', strtolower(trim($name)));

                    $newStudent = [
                        'name'         => $name,
                        'gender'       => $gender,
                        'year'         => $year,
                        'school'       => $school,
                        'session'      => $session,
                        'level'        => $level,
                        'group'        => $group,
                        'susunan_umur' => $sus_umur,
                        'gender_auto'  => $gender_auto,
                        'year_auto'    => $year_auto,
                    ];

                    if (!isset($parsed_students[$dedupeKey])) {
                        // New student — add fresh record
                        $parsed_students[$dedupeKey] = $newStudent;
                    } else {
                        // Already exists — fill in any missing fields from this sheet
                        foreach ($newStudent as $field => $val) {
                            if (!empty($val) && empty($parsed_students[$dedupeKey][$field])) {
                                $parsed_students[$dedupeKey][$field] = $val;
                            }
                        }
                        // School: prefer the longer/more complete spelling
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

$pm_page = 'upload_students';
include 'layout.php';
?>

<style>
/* ── PREVENT BODY-LEVEL HORIZONTAL SCROLL ── */
html, body { max-width: 100%; overflow-x: hidden; }

/* ── VIEWPORT SHELL: fills screen, no page scroll ── */
/* ── UPLOAD PAGE: pm-main becomes a flex column for this page only ── */
#pm-main {
    display: flex;
    flex-direction: column;
}
.upload-page-wrapper {
    flex: 1;
    min-height: 0;
    width: 100%;
    max-width: 100%;
    overflow-x: hidden;
    display: flex;
    flex-direction: column;
}

/* ── REVIEW FULLSCREEN MODE ──
   Activated when stage=review via: document.documentElement.classList.add('review-fullscreen')
   Locks pm-main to exactly the viewport height — no magic numbers.
   The flex chain distributes remaining space automatically. */
html.review-fullscreen #pm-main {
    height: 100vh;       /* fallback */
    height: 100dvh;      /* dynamic: adapts to mobile browser chrome show/hide */
    min-height: 0;       /* override pm-main's default min-height: 100vh */
    overflow: hidden;
    padding-bottom: 0;   /* remove padding so content fills to bottom edge */
}

html.review-fullscreen .upload-page-wrapper {
    overflow: hidden;
}

/* ── REVIEW SHELL — fills pm-main's remaining space via flex ── */
.review-shell {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

#confirmForm {
    display: flex;
    flex-direction: column;
    flex: 1 1 0%;
    min-height: 0;
    min-width: 0; /* Prevents flex content from blowing out screen width */
    width: 100%;
}

.review-header { 
    flex-shrink: 0; 
    padding-bottom: 10px; 
}

.pm-tabs       { 
    flex-shrink: 0; 
}

.pm-tab-pane.active {
    display: flex;
    flex-direction: column;
    flex: 1 1 0%;
    min-height: 0;
    min-width: 0; /* Prevents flex content from blowing out screen width */
    width: 100%;
    overflow: hidden;
}

.review-section-card {
    flex: 0 1 auto; /* Prevents the card from stretching */
    display: flex;
    flex-direction: column;
    min-height: 0;
    min-width: 0; 
    width: 100%;
    background: var(--c-surface-1);
    border: 1px solid var(--c-border);
    border-radius: 10px;
    max-height: 100%; /* Ensures long tables still scroll inside the card */
}

.pm-table-wrap {
    flex: 0 1 auto; /* Prevents the table wrapper from forcing empty space */
    overflow-y: auto;
    overflow-x: auto;
    min-height: 0;
    -webkit-overflow-scrolling: touch;
}

.wizard-footer {
    flex-shrink: 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 0 16px; 
    padding-bottom: calc(16px + env(safe-area-inset-bottom, 0px)); /* Safe area padding */
    border-top: 1px solid var(--c-border-strong);
    margin-top: auto; /* <--- PUSHES FOOTER TO THE BOTTOM OF THE SCREEN */
    gap: 12px;
}

/* ── UPLOAD ZONE ── */
.upload-zone {
    border: 2px dashed var(--c-border-strong);
    background: var(--c-surface-2);
    border-radius: 12px;
    padding: 48px 32px;
    text-align: center;
    transition: all 0.25s;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0;
    position: relative;
}
.upload-zone:hover, .upload-zone.dragover {
    border-color: var(--c-red);
    background: var(--c-red-dim);
}
.upload-zone.has-file {
    border-color: rgba(34,197,94,0.5);
    background: rgba(34,197,94,0.05);
}
/* Hide native file input completely */
.upload-zone input[type="file"] {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
    width: 100%;
    height: 100%;
    z-index: 2;
}
.upload-icon-wrap {
    width: 64px; height: 64px;
    background: var(--c-surface-1);
    border: 1px solid var(--c-border-strong);
    border-radius: 16px;
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 20px;
    transition: all 0.25s;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.upload-zone:hover .upload-icon-wrap,
.upload-zone.dragover .upload-icon-wrap {
    background: var(--c-red);
    border-color: var(--c-red);
    box-shadow: 0 4px 16px rgba(200,0,30,0.25);
}
.upload-zone:hover .upload-icon-wrap svg,
.upload-zone.dragover .upload-icon-wrap svg {
    stroke: #fff;
}
.upload-zone.has-file .upload-icon-wrap {
    background: rgba(34,197,94,0.15);
    border-color: rgba(34,197,94,0.4);
}
.upload-cta-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--c-red);
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 10px 24px;
    font-size: 0.88rem;
    font-weight: 700;
    cursor: pointer;
    pointer-events: none; /* clicks pass through to the file input above */
    transition: all 0.2s;
    margin-top: 20px;
    letter-spacing: 0.02em;
}
.upload-zone:hover .upload-cta-btn,
.upload-zone.dragover .upload-cta-btn {
    background: var(--c-red-hover, #a0001a);
    box-shadow: 0 4px 12px rgba(200,0,30,0.35);
}
.upload-zone.has-file .upload-cta-btn {
    background: rgba(34,197,94,0.8);
}
.upload-file-info {
    display: none;
    align-items: center;
    gap: 10px;
    background: var(--c-surface-1);
    border: 1px solid rgba(34,197,94,0.3);
    border-radius: 8px;
    padding: 10px 16px;
    margin-top: 16px;
    font-size: 0.84rem;
    color: var(--c-text);
    font-weight: 600;
    max-width: 100%;
    overflow: hidden;
    text-align: left;
}
.upload-file-info .file-name {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: #16a34a;
}
.upload-file-info .file-remove {
    flex-shrink: 0;
    background: none;
    border: none;
    color: var(--c-text-muted);
    cursor: pointer;
    font-size: 1rem;
    padding: 0 4px;
    pointer-events: all;
    z-index: 3;
    position: relative;
    line-height: 1;
}
.upload-file-info .file-remove:hover { color: var(--c-red); }

/* ── PRECONFIG PANEL ── */
.preconfig-panel {
    border-top: 1px solid var(--c-border-strong);
    margin-top: 28px;
    padding-top: 24px;
    display: none; 
}
.preconfig-panel.visible { display: block; }
.preconfig-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
    gap: 14px;
    margin-top: 14px;
    align-items: start;
}
.pc-field {
    display: flex;
    flex-direction: column;
}
.pc-field label {
    display: block;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--c-text-muted);
    margin-bottom: 6px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.pc-field select,
.pc-field input[type="number"],
.pc-field input[type="text"] {
    width: 100%;
    height: 38px;
    background: var(--c-surface-2);
    border: 1px solid var(--c-border-strong);
    border-radius: 8px;
    padding: 0 12px;
    color: var(--c-white);
    font-size: 0.86rem;
    box-sizing: border-box;
    transition: border-color 0.15s;
}
.pc-field select:focus,
.pc-field input:focus {
    outline: none;
    border-color: var(--c-red);
}
.pc-new-input { display: none; margin-top: 8px; }
.pc-new-input.visible { display: block; }

/* ── TABS ── */
.pm-tabs {
    display: flex;
    gap: 6px;
    margin-bottom: 8px;
    overflow-x: auto;
    scrollbar-width: none;
    -webkit-overflow-scrolling: touch;
}
.pm-tabs::-webkit-scrollbar { display: none; }
.pm-tab-btn {
    flex: 1;
    min-width: 80px; /* ensures text has min breathing room */
    background: var(--c-surface-2);
    color: var(--c-text-muted);
    border: 1px solid var(--c-border-strong);
    padding: 9px 10px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.82rem;
    cursor: pointer;
    white-space: nowrap;
    text-align: center;
    transition: all 0.15s;
    overflow: hidden;
    text-overflow: ellipsis;
}
.pm-tab-btn:hover { background: var(--c-surface-3); color: var(--c-white); }
.pm-tab-btn.active { background: var(--c-red-dim); color: var(--c-red); border-color: rgba(239,68,68,0.4); }
.pm-tab-pane { display: none; }
@keyframes fadeIn { from { opacity:0; transform:translateY(4px); } to { opacity:1; transform:translateY(0); } }

/* ════════════════════════════════════════════════════════
   UNIFIED TABLE SYSTEM
   Same structure, spacing, typography across ALL 5 tabs
════════════════════════════════════════════════════════ */

/* ── Section card title ── */
.review-section-title {
    font-size: 0.72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.13em;
    color: var(--c-text-muted);
    padding: 12px 18px;
    background: var(--c-surface-2);
    border-bottom: 1px solid var(--c-border-strong);
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}
.section-icon {
    width: 20px; height: 20px;
    border-radius: 5px;
    background: var(--c-red-dim);
    border: 1px solid rgba(200,0,30,0.2);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    font-size: 0.72rem;
}

/* ── Table base — ALL tables uniform ── */
.pm-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: auto;
}

/* ── Header row — identical across all tabs ── */
.pm-table th {
    font-size: 0.67rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: var(--c-text-muted);
    padding: 11px 16px;
    text-align: left;
    white-space: nowrap;
    background: var(--c-surface-2);
    border-bottom: 2px solid var(--c-border-strong);
    position: sticky;
    top: 0;
    z-index: 1;
}
.pm-table th:first-child { padding-left: 20px; }
.pm-table th:last-child  { padding-right: 20px; }

/* ── Data rows — identical across all tabs ── */
.pm-table tbody tr {
    border-left: 3px solid transparent;
    transition: background 0.15s, border-color 0.15s;
    display: none; /* pagination shows rows */
}
.pm-table tbody tr:hover {
    background: rgba(200,0,30,0.03);
    border-left-color: var(--c-red);
}
.pm-table td {
    padding: 10px 16px;
    border-bottom: 1px solid var(--c-border);
    vertical-align: middle;
    font-size: 0.84rem;
    color: var(--c-text);
    overflow: hidden;
}
.pm-table td:first-child { padding-left: 20px; }
.pm-table td:last-child  { padding-right: 20px; }
.pm-table tbody tr:last-child td { border-bottom: none; }

/* ── Document name cell ── */
.cell-docname {
    font-weight: 700;
    color: var(--c-white);
}

/* ── Column widths — mapping tables (Cawangan / Sidang / Peringkat) ── */
.map-table .col-doc    { width: 38%; min-width: 200px; }
.map-table .col-status { width: 160px; }
.map-table .col-action { width: auto; min-width: 220px; }

/* ── Column widths — student table (Senarai Pelajar) ── */
.col-skip   { width: 52px;  min-width: 52px;  text-align: center !important; }
.col-name   { width: 24%;   min-width: 170px; }
.col-umur   { width: 148px; min-width: 148px; }
.col-gender { width: 140px; min-width: 140px; }
.col-year   { width: 96px;  min-width: 96px;  }
.col-group  { width: auto;  min-width: 180px; }

/* ── Judge table ── */
.judge-table .col-group-name { width: 40%; }
.judge-table .col-judge      { width: auto; }

/* ── Form controls inside table cells — uniform ── */
.pm-select {
    width: 100%;
    height: 34px;
    background: var(--c-surface-2);
    border: 1px solid var(--c-border-strong);
    border-radius: 7px;
    padding: 0 30px 0 10px;
    color: var(--c-white);
    font-size: 0.82rem;
    box-sizing: border-box;
    cursor: pointer;
    transition: border-color 0.18s;
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 9px center;
}
.pm-select:hover  { border-color: rgba(200,0,30,0.35); }
.pm-select:focus  { outline: none; border-color: var(--c-red); box-shadow: 0 0 0 2px rgba(200,0,30,0.08); }
.pm-select option { background: #181818; color: var(--c-white); }

.pm-input-sm {
    width: 100%;
    height: 34px;
    background: var(--c-surface-2);
    border: 1px solid var(--c-border-strong);
    border-radius: 7px;
    padding: 0 10px;
    color: var(--c-white);
    font-size: 0.82rem;
    box-sizing: border-box;
    display: block;
    margin-top: 5px;
    transition: border-color 0.18s;
}
.pm-input-sm:hover { border-color: rgba(200,0,30,0.35); }
.pm-input-sm:focus { outline: none; border-color: var(--c-red); box-shadow: 0 0 0 2px rgba(200,0,30,0.08); }

/* ── Inline tags ── */
.tag { display:inline-flex; align-items:center; font-size:0.62rem; font-weight:700; border-radius:4px; padding:2px 6px; margin-left:4px; vertical-align:middle; letter-spacing:0.03em; }
.tag-missing { background:rgba(239,68,68,0.1); color:#dc2626; border:1px solid rgba(239,68,68,0.3); }
.tag-exists  { background:var(--c-surface-2); color:var(--c-text-muted); border:1px solid var(--c-border-strong); }
.tag-new     { background:rgba(200,0,30,0.1); color:var(--c-red); border:1px solid rgba(200,0,30,0.3); }

/* ── Status badges ── */
.pm-badge {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 0.7rem; font-weight: 700; letter-spacing: 0.04em;
    padding: 4px 11px; border-radius: 20px; white-space: nowrap;
}
.pm-badge::before { content:''; width:6px; height:6px; border-radius:50%; flex-shrink:0; }
.pm-badge-green { background:rgba(34,197,94,0.12); color:#16a34a; border:1px solid rgba(34,197,94,0.3); }
.pm-badge-green::before { background:#22c55e; }
.pm-badge-amber { background:rgba(120,120,120,0.12); color:#666; border:1px solid rgba(120,120,120,0.25); }
.pm-badge-amber::before { background:#999; }
.pm-badge-gray  { background:rgba(100,100,100,0.1); color:#5a5a5a; border:1px solid rgba(100,100,100,0.22); }
.pm-badge-gray::before  { background:#888; }
.pm-badge-red   { background:rgba(239,68,68,0.1); color:#dc2626; border:1px solid rgba(239,68,68,0.28); }
.pm-badge-red::before   { background:#ef4444; }

/* ── Pagination bar — uniform across all tabs ── */
.pm-pagination {
    flex-shrink: 0;
    display: flex; justify-content: space-between; align-items: center;
    padding: 10px 18px;
    background: var(--c-surface-2);
    border-top: 1px solid var(--c-border-strong);
    font-size: 0.78rem; color: var(--c-text-muted); font-weight: 600;
}
.pm-pagination-controls { display:flex; align-items:center; gap:6px; }
.pm-page-btn {
    background: var(--c-surface-1); border: 1px solid var(--c-border-strong);
    color: var(--c-white); padding: 5px 13px; border-radius: 6px;
    cursor: pointer; font-weight: 700; font-size: 0.8rem; transition: all 0.15s;
}
.pm-page-btn:hover:not(:disabled) { background:var(--c-red-dim); color:var(--c-red); border-color:rgba(200,0,30,0.35); }
.pm-page-btn:disabled { opacity:0.3; cursor:not-allowed; }
.pm-page-info { font-size:0.78rem; font-weight:700; color:var(--c-text); padding:0 8px; }

/* ── Notice banner ── */
.notice-banner { display:flex; align-items:center; gap:10px; background:rgba(161,161,170,0.07); border:1px solid var(--c-border-strong); border-radius:8px; padding:11px 16px; font-size:0.82rem; color:var(--c-text-muted); }

/* ── Sheet picker ── */
.sheet-picker-grid { display:grid; gap:10px; margin:14px 0; }
.sheet-card { display:flex; align-items:center; gap:14px; background:var(--c-surface-1); border:1.5px solid var(--c-border); border-radius:10px; padding:14px 16px; cursor:pointer; transition:all 0.18s; user-select:none; }
.sheet-card:hover { border-color:rgba(200,0,30,0.35); background:rgba(200,0,30,0.03); }
.sheet-card.selected { border-color:var(--c-red); background:var(--c-red-dim); }
.sheet-card.invalid  { opacity:0.5; cursor:not-allowed; }
.sheet-card input[type="checkbox"] { display:none; }
.sheet-check { width:20px; height:20px; border-radius:5px; border:2px solid var(--c-border-strong); display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:all 0.15s; background:var(--c-surface-2); }
.sheet-card.selected .sheet-check { background:var(--c-red); border-color:var(--c-red); }
.sheet-check svg { display:none; }
.sheet-card.selected .sheet-check svg { display:block; }
.sheet-name { font-weight:700; font-size:0.9rem; color:var(--c-white); }
.sheet-meta { font-size:0.75rem; color:var(--c-text-muted); margin-top:2px; }
.sheet-cols { display:flex; flex-wrap:wrap; gap:5px; margin-top:8px; }
.sheet-col-tag { font-size:0.64rem; font-weight:700; letter-spacing:0.05em; padding:2px 8px; border-radius:20px; background:var(--c-surface-2); border:1px solid var(--c-border-strong); color:var(--c-text-muted); }
.sheet-col-tag.key { background:rgba(200,0,30,0.1); border-color:rgba(200,0,30,0.25); color:var(--c-red); }
.sheet-badge-valid   { display:inline-block; padding:2px 9px; border-radius:20px; font-size:0.68rem; font-weight:700; background:rgba(34,197,94,0.12); color:#16a34a; border:1px solid rgba(34,197,94,0.3); }
.sheet-badge-invalid { display:inline-block; padding:2px 9px; border-radius:20px; font-size:0.68rem; font-weight:700; background:rgba(120,120,120,0.12); color:#777; border:1px solid rgba(120,120,120,0.25); }
.sp-section-label { font-size:0.68rem; font-weight:800; text-transform:uppercase; letter-spacing:0.12em; color:var(--c-text-muted); margin-bottom:10px; }
.sp-divider { height:1px; background:var(--c-border); margin:20px 0; }

/* ════════════════════════════════════════════════════════
   RESPONSIVE / MOBILE FIXES
════════════════════════════════════════════════════════ */

/* ── Tablets and below (< 900px) ── */
@media (max-width: 900px) {
    /* Upload zone: clip long filename */
    #fileChosen {
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* Preconfig grid: 2 columns on tablet */
    .preconfig-grid {
        grid-template-columns: repeat(2, 1fr) !important;
    }
    /* Sheet picker: also 2 col on tablet */
    .sp-preconfig-grid {
        grid-template-columns: repeat(2, 1fr) !important;
    }

    /* Wizard footer buttons: smaller padding */
    .wizard-footer .pm-btn { padding: 8px 14px; font-size: 0.8rem; }
    .wizard-footer { gap: 8px; }

    /* Sheet picker bottom row: allow wrap */
    #sheetPickerForm > div[style*="justify-content:space-between"] {
        flex-wrap: wrap;
        gap: 8px;
    }
}

/* ── Mobile (< 600px) ── */
@media (max-width: 600px) {
    /* Tabs: 2-column wrap grid — no horizontal scroll, readable labels */
    .pm-tabs {
        flex-wrap: wrap;
        overflow: visible;
        gap: 6px;
        padding-bottom: 4px;
    }
    .pm-tab-btn {
        flex: 1 1 calc(50% - 4px);
        min-width: 0;
        padding: 9px 8px;
        font-size: 0.76rem;
        white-space: normal;
        line-height: 1.3;
        text-align: center;
    }
    /* Last tab if odd count (5 tabs) → full width */
    .pm-tab-btn:last-child:nth-child(odd) {
        flex: 1 1 100%;
    }

    /* Preconfig grid: 1 column on mobile */
    .preconfig-grid {
        grid-template-columns: 1fr !important;
    }
    /* Sheet picker preconfig: single column on mobile */
    .sp-preconfig-grid {
        grid-template-columns: 1fr !important;
    }

    /* Upload zone filename: wrap */
    #fileChosen {
        white-space: normal;
        word-break: break-word;
    }

    /* Wizard footer: full-width buttons on mobile */
    .wizard-footer .pm-btn { 
        flex: 1; /* Makes "Kembali" and "Seterusnya" evenly split 50/50 */
        width: 100%; 
        text-align: center; 
        justify-content: center; 
    }

    /* Sheet picker bottom buttons: stack */
    #sheetPickerForm > div[style*="justify-content:space-between"] {
        flex-direction: column;
        gap: 8px;
    }
    #sheetPickerForm > div > .pm-btn { width: 100%; text-align: center; justify-content: center; }
    #importBtn { width: 100%; }

    /* Review header: compact */
    .review-header h2 { font-size: 1rem !important; }
    .review-header { gap: 8px; }

    /* Review shell: flex chain handles height via html.review-fullscreen,
       no overrides needed here. Wizard footer: full-width on mobile. */
    .wizard-footer { 
        gap: 10px; 
        padding-bottom: calc(20px + env(safe-area-inset-bottom, 0px)); 
    }
    
    .wizard-footer > div:empty { 
        display: none; 
    }

    /* Pagination: compact */
    .pm-pagination { padding: 8px 12px; font-size: 0.72rem; }
    .pm-page-btn { padding: 4px 10px; font-size: 0.76rem; }

    /* Badges: smaller on mobile */
    .pm-badge { padding: 3px 8px; font-size: 0.65rem; }

    /* Table: allow horizontal scroll */
    .pm-table-wrap { -webkit-overflow-scrolling: touch; }
}

/* ── Very small screens (< 400px) ── */
@media (max-width: 400px) {
    .pm-tab-btn { font-size: 0.68rem; padding: 7px 8px; }
    /* review-shell uses flex chain via html.review-fullscreen — no override needed */
}
.sp-preconfig-grid { grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)) !important; }
/* Hide number input spinners — they eat space in narrow columns */
input[type="number"]::-webkit-inner-spin-button,
input[type="number"]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
input[type="number"] { -moz-appearance: textfield; }

</style>

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
                <div style="color:var(--c-text-faint);font-size:0.85rem;">Menyokong fail .csv biasa serta .xlsx (Excel Pelbagai Tab) melalui SimpleXLSX.</div>
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
        </div>

        <div class="pm-card" style="padding:28px;">
            <form action="upload_students.php" method="POST" enctype="multipart/form-data" id="uploadForm">
                <input type="hidden" name="stage" value="select_sheets">
                <div class="upload-zone" id="uploadZone">
                    <!-- File input covers full zone, invisible -->
                    <input type="file" name="upload_file" id="uploadFileInput" accept=".csv, .xlsx" required>

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
                    <p style="color:var(--c-text-faint);font-size:0.83rem;margin:0;line-height:1.6;">Seret & lepas fail ke sini, atau klik untuk memilih<br><span style="color:var(--c-text-muted);font-size:0.76rem;">Menyokong .csv dan .xlsx (Excel berbilang tab)</span></p>

                    <!-- CTA button (visual only, clicks pass to input above) -->
                    <div class="upload-cta-btn" id="uploadCtaBtn">
                        <svg viewBox="0 0 24 24" width="15" height="15" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        Pilih Fail
                    </div>
                </div>

                <!-- File selected info + Submit (shown after file chosen) -->
                <div class="upload-file-info" id="fileChosen">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <span class="file-name" id="fileNameDisplay">—</span>
                    <button type="button" class="file-remove" id="fileClearBtn" title="Buang fail">✕</button>
                </div>

                <div style="margin-top:14px;display:none;" id="uploadSubmitWrap">
                    <button type="submit" class="pm-btn pm-btn-primary" style="padding:11px 32px;font-size:0.9rem;width:100%;">
                        Seterusnya: Pilih Lembaran →
                    </button>
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

<script>
// ── Upload zone ──
(function(){
    var inp        = document.getElementById('uploadFileInput');
    var zone       = document.getElementById('uploadZone');
    var submitWrap = document.getElementById('uploadSubmitWrap');
    var fileInfo   = document.getElementById('fileChosen');
    var fileName   = document.getElementById('fileNameDisplay');
    var clearBtn   = document.getElementById('fileClearBtn');
    var ctaBtn     = document.getElementById('uploadCtaBtn');

    function setFile(file) {
        if (file) {
            if (fileName)   fileName.textContent = file.name;
            if (fileInfo)   fileInfo.style.display = 'flex';
            if (submitWrap) submitWrap.style.display = 'block';
            if (zone)       zone.classList.add('has-file');
            if (ctaBtn)     ctaBtn.textContent = '✓ Fail Dipilih';
        } else {
            if (fileName)   fileName.textContent = '—';
            if (fileInfo)   fileInfo.style.display = 'none';
            if (submitWrap) submitWrap.style.display = 'none';
            if (zone)       zone.classList.remove('has-file');
            if (ctaBtn) {
                ctaBtn.innerHTML = '<svg viewBox="0 0 24 24" width="15" height="15" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Pilih Fail';
            }
        }
    }

    if (inp) {
        inp.addEventListener('change', function(){
            setFile(this.files && this.files.length > 0 ? this.files[0] : null);
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function(e){
            e.stopPropagation();
            if (inp) inp.value = '';
            setFile(null);
        });
    }

    // Drag & drop
    if (zone) {
        ['dragenter','dragover'].forEach(function(ev){
            zone.addEventListener(ev, function(e){ e.preventDefault(); zone.classList.add('dragover'); });
        });
        ['dragleave','dragend','drop'].forEach(function(ev){
            zone.addEventListener(ev, function(e){
                e.preventDefault();
                zone.classList.remove('dragover');
                if (ev === 'drop' && e.dataTransfer.files.length > 0) {
                    inp.files = e.dataTransfer.files;
                    setFile(e.dataTransfer.files[0]);
                }
            });
        });
    }
    var siriSel = document.getElementById('siriSelect');
    if (siriSel) siriSel.addEventListener('change', function(){ var w=document.getElementById('newSiriWrap'); if(w) w.classList.toggle('visible', this.value==='NEW'); });
    var sessSel = document.getElementById('sessionSelect');
    if (sessSel) sessSel.addEventListener('change', function(){ var w=document.getElementById('newSessionWrap'); if(w) w.classList.toggle('visible', this.value==='NEW'); });
})();

// ── Sheet picker toggle ──
function toggleSheet(label, sheetName) {
    var cb = label.querySelector('input[type="checkbox"]');
    if (!cb || cb.disabled) return;
    cb.checked = !cb.checked;
    label.classList.toggle('selected', cb.checked);
    updateImportBtn();
}
function updateImportBtn() {
    var btn = document.getElementById('importBtn');
    if (!btn) return;
    var checked = document.querySelectorAll('.sheet-picker-grid input[type="checkbox"]:checked');
    btn.disabled = checked.length === 0;
    btn.textContent = checked.length === 0
        ? 'Pilih sekurang-kurangnya 1 lembaran'
        : 'Semak & Sahkan Data (' + checked.length + ' lembaran) →';
}
// Init sheet cards on load
document.querySelectorAll('.sheet-card:not(.invalid)').forEach(function(card){
    var cb = card.querySelector('input[type="checkbox"]');
    if (cb && cb.checked) card.classList.add('selected');
});
updateImportBtn();

// ── Group selects: only show text input if user picks "NEW" with no preset name ──
document.querySelectorAll('.group-select').forEach(function(sel){
    var inp = document.querySelector('.new-group-input[data-idx="' + sel.dataset.idx + '"]');
    if (!inp) return; // hidden input (preset) — no text box exists
    sel.addEventListener('change', function(){
        inp.style.display = (this.value === 'NEW') ? 'block' : 'none';
        inp.required = (this.value === 'NEW');
    });
});

// ── Tabs ──
var tabBtns  = document.querySelectorAll('.pm-tab-btn');
var tabPanes = document.querySelectorAll('.pm-tab-pane');
function switchTab(id){
    tabBtns.forEach(function(b){ b.classList.toggle('active', b.dataset.target===id); });
    tabPanes.forEach(function(p){ p.classList.toggle('active', p.id===id); });
    sessionStorage.setItem('upload_students_active_tab', id);
}
tabBtns.forEach(function(b){ b.addEventListener('click', function(){ switchTab(this.dataset.target); }); });
document.querySelectorAll('.btn-next-tab').forEach(function(b){ b.addEventListener('click', function(){ switchTab(this.dataset.next); }); });
document.querySelectorAll('.btn-prev-tab').forEach(function(b){ b.addEventListener('click', function(){ switchTab(this.dataset.prev); }); });

var savedTab = sessionStorage.getItem('upload_students_active_tab');
if (savedTab && document.getElementById(savedTab)) {
    switchTab(savedTab);
}

// ── Lazy school dropdown: inject options only on first focus ──
var schoolOptionsCache = null;
document.querySelectorAll('.fast-school-dropdown').forEach(function(sel){
    sel.addEventListener('focus', function(){
        if (this.dataset.loaded) return;
        if (!schoolOptionsCache) {
            schoolOptionsCache = '';
            if (typeof masterSchools !== 'undefined') {
                for (var id in masterSchools) {
                    schoolOptionsCache += '<option value="'+id+'">→ '+masterSchools[id]+'</option>';
                }
            }
        }
        var v = this.value;
        this.insertAdjacentHTML('beforeend', schoolOptionsCache);
        this.value = v;
        this.dataset.loaded = 'true';
    });
});

// ── Lazy group dropdown: same trick — avoids duplicating every group option across 700+ rows ──
var groupOptionsCache = null;
document.querySelectorAll('.fast-group-dropdown').forEach(function(sel){
    sel.addEventListener('focus', function(){
        if (this.dataset.loaded) return;
        if (!groupOptionsCache) {
            groupOptionsCache = '';
            if (typeof masterGroups !== 'undefined') {
                for (var id in masterGroups) {
                    groupOptionsCache += '<option value="'+id+'">'+masterGroups[id]+'</option>';
                }
            }
        }
        var v = this.value;
        var newOpt = this.querySelector('option[value="NEW"]');
        if (newOpt) newOpt.insertAdjacentHTML('beforebegin', groupOptionsCache);
        else this.insertAdjacentHTML('beforeend', groupOptionsCache);
        this.value = v;
        this.dataset.loaded = 'true';
    });
});

// ── Smart pagination: render only visible rows + buffer for performance ──
(function(){
    var ROWS_PER_PAGE = 10;
    var BUFFER_ROWS = 5; // Pre-load a few extra rows
    document.querySelectorAll('.pm-table-wrap table.pm-auto-paginate').forEach(function(table){
        var tbody = table.querySelector('tbody');
        if (!tbody) return;
        var rows = Array.from(tbody.querySelectorAll('tr'));
        var total = parseInt(table.getAttribute('data-total')) || rows.length;
        if (rows.length <= ROWS_PER_PAGE) {
            // No pagination needed — just make all rendered rows visible
            for (var i = 0; i < rows.length; i++) rows[i].style.display = 'table-row';
            return;
        }

        var totalPages = Math.ceil(total / ROWS_PER_PAGE);
        var cur = 1;

        var bar = document.createElement('div');
        bar.className = 'pm-pagination';
        table.closest('.pm-table-wrap').insertAdjacentElement('afterend', bar);

        function render(){
            var start = (cur-1)*ROWS_PER_PAGE;
            var end   = Math.min(start + ROWS_PER_PAGE + BUFFER_ROWS, rows.length);
            var displayStart = start;
            var displayEnd = Math.min(start + ROWS_PER_PAGE, rows.length);
            
            // Show current page + buffer, hide rest
            for (var i=0; i<rows.length; i++) {
                rows[i].style.display = (i >= start && i < end) ? 'table-row' : 'none';
            }
            
            bar.innerHTML =
                '<span>Rekod <strong>' + (displayStart+1) + '–' + displayEnd + '</strong> / ' + total + '</span>' +
                '<div class="pm-pagination-controls">' +
                  '<button type="button" class="pm-page-btn" id="pprev" '+(cur===1?'disabled':'')+'>«</button>' +
                  '<span class="pm-page-info">'+cur+' / '+totalPages+'</span>' +
                  '<button type="button" class="pm-page-btn" id="pnext" '+(cur===totalPages?'disabled':'')+'>»</button>' +
                '</div>';
            bar.querySelector('#pprev').onclick = function(){ if(cur>1){ cur--; render(); } };
            bar.querySelector('#pnext').onclick = function(){ if(cur<totalPages){ cur++; render(); } };
        }
        // Pre-render first page (already hidden by CSS, pagination shows it)
        render();
    });
})();
</script>
</div><!-- /.upload-page-wrapper -->
</main>
</body>
</html>