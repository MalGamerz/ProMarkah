<?php
// ── Export (Excel): Ujian Kenaikan Tali Pinggang — Peringkat Cawangan ────
// Same 4 tables as pic_cawangan_summary.php — query logic shared via
// cawangan_report_data.php so this can't drift from the on-screen page.
//
// This builds a genuine .xlsx (the zipped Open XML Spreadsheet format) by
// hand, using PHP's built-in ZipArchive — no external library needed.
// Two earlier attempts both got the "format and extension don't match"
// warning in modern Excel: the old HTML-table-as-.xls trick this codebase's
// other Excel exports use, and even a real SpreadsheetML XML file served
// with a .xls extension (Excel's file-validation treats .xls as strictly
// the old binary BIFF8 format — anything else under that extension gets
// flagged, XML included). A real .xlsx zip container is unambiguous: the
// extension IS what the bytes actually are, so there's nothing to warn
// about.
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

session_start();
require __DIR__ . '/auth_check.php';
include 'db.php';
$conn = getDB();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pic') {
    header("Location: login.php");
    exit();
}

include 'cawangan_report_data.php';

$active_siri = (int)($_SESSION['active_siri_id'] ?? 0);
$reportTitle = pm_cawangan_report_title($conn, $active_siri);
$data = pm_cawangan_report_data($conn, $active_siri);

// ── Minimal .xlsx builder ────────────────────────────────────────────────
// Style indices into the cellXfs array defined in pmz_styles_xml() below —
// keep the two in sync if either changes.
const PMZ_S_DEFAULT       = 0;
const PMZ_S_TITLE         = 1;
const PMZ_S_SECTION       = 2;
const PMZ_S_BANNER        = 3;
const PMZ_S_COLHDR_CENTER = 4;
const PMZ_S_COLHDR_LEFT   = 5;
const PMZ_S_CELL_CENTER   = 6;
const PMZ_S_CELL_CENTER_WRAP = 7;
const PMZ_S_CELL_LEFT     = 8;

function pmz_col(int $n): string {
    $s = '';
    while ($n > 0) {
        $n--;
        $s = chr(65 + ($n % 26)) . $s;
        $n = intdiv($n, 26);
    }
    return $s;
}
function pmz_esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}
function pmz_str_cell(int $col, int $row, int $style, string $value): string {
    $ref = pmz_col($col) . $row;
    return "<c r=\"{$ref}\" s=\"{$style}\" t=\"inlineStr\"><is><t xml:space=\"preserve\">" . pmz_esc($value) . "</t></is></c>";
}
function pmz_num_cell(int $col, int $row, int $style, int $value): string {
    $ref = pmz_col($col) . $row;
    return "<c r=\"{$ref}\" s=\"{$style}\"><v>{$value}</v></c>";
}

$sheetRows = []; // rowNum => [cell xml, ...]
$merges = [];    // "A1:C1"
$rowNum = 0;

function pmz_add_row(array &$sheetRows, int &$rowNum, string $cellsXml): int {
    $rowNum++;
    $sheetRows[] = "<row r=\"{$rowNum}\">{$cellsXml}</row>";
    return $rowNum;
}

// SENARAI CAWANGAN — 2 real columns (Bil, Cawangan)
$r = pmz_add_row($sheetRows, $rowNum, pmz_str_cell(1, $rowNum + 1, PMZ_S_TITLE, $reportTitle));
$merges[] = "A{$r}:C{$r}";
pmz_add_row($sheetRows, $rowNum, '');
$r = pmz_add_row($sheetRows, $rowNum, pmz_str_cell(1, $rowNum + 1, PMZ_S_SECTION, 'SENARAI CAWANGAN'));
$merges[] = "A{$r}:C{$r}";
pmz_add_row($sheetRows, $rowNum, pmz_str_cell(1, $rowNum + 1, PMZ_S_COLHDR_CENTER, 'BIL') . pmz_str_cell(2, $rowNum + 1, PMZ_S_COLHDR_LEFT, 'CAWANGAN'));
if (empty($data['rows1'])) {
    $r = pmz_add_row($sheetRows, $rowNum, pmz_str_cell(1, $rowNum + 1, PMZ_S_CELL_CENTER, 'Tiada cawangan dengan kumpulan pelajar ditemui.'));
    $merges[] = "A{$r}:B{$r}";
} else {
    $no = 1;
    foreach ($data['rows1'] as $row) {
        pmz_add_row($sheetRows, $rowNum, pmz_num_cell(1, $rowNum + 1, PMZ_S_CELL_CENTER, $no) . pmz_str_cell(2, $rowNum + 1, PMZ_S_CELL_LEFT, $row['school_name']));
        $no++;
    }
}
pmz_add_row($sheetRows, $rowNum, '');

// Shared renderer for the "one table per cawangan, Bil resets per group"
// sections (SENARAI PELAJAR MENGIKUT CAWANGAN / PERINGKAT SETIAP PELAJAR).
function pmz_grouped_rows(array &$sheetRows, int &$rowNum, array &$merges, array $rowsIn, array $extraHeaders, callable $extraCells, int $lastCol): void {
    if (empty($rowsIn)) {
        $r = pmz_add_row($sheetRows, $rowNum, pmz_str_cell(1, $rowNum + 1, PMZ_S_CELL_CENTER, 'Tiada pelajar ditemui.'));
        $merges[] = "A{$r}:" . pmz_col($lastCol) . "{$r}";
        return;
    }
    $groups = [];
    foreach ($rowsIn as $row) {
        $groups[$row['school_name']][] = $row;
    }
    foreach ($groups as $school_name => $groupRows) {
        $r = pmz_add_row($sheetRows, $rowNum, pmz_str_cell(1, $rowNum + 1, PMZ_S_BANNER, 'CAWANGAN: ' . strtoupper($school_name)));
        $merges[] = "A{$r}:" . pmz_col($lastCol) . "{$r}";
        $headerCells = pmz_str_cell(1, $rowNum + 1, PMZ_S_COLHDR_CENTER, 'BIL');
        $c = 2;
        foreach ($extraHeaders as $h) {
            $headerCells .= pmz_str_cell($c, $rowNum + 1, PMZ_S_COLHDR_LEFT, strtoupper($h));
            $c++;
        }
        pmz_add_row($sheetRows, $rowNum, $headerCells);
        $no = 1;
        foreach ($groupRows as $row) {
            pmz_add_row($sheetRows, $rowNum, pmz_num_cell(1, $rowNum + 1, PMZ_S_CELL_CENTER, $no) . $extraCells($row, $rowNum + 1));
            $no++;
        }
    }
}

// SENARAI PELAJAR MENGIKUT CAWANGAN — 2 real columns (Bil, Nama Pelajar)
$r = pmz_add_row($sheetRows, $rowNum, pmz_str_cell(1, $rowNum + 1, PMZ_S_SECTION, 'SENARAI PELAJAR MENGIKUT CAWANGAN'));
$merges[] = "A{$r}:C{$r}";
pmz_grouped_rows($sheetRows, $rowNum, $merges, $data['rows2'], ['Nama Pelajar'], function ($row, $rowN) {
    return pmz_str_cell(2, $rowN, PMZ_S_CELL_LEFT, $row['student_name']);
}, 2);
pmz_add_row($sheetRows, $rowNum, '');

// PERINGKAT MENGIKUT CAWANGAN — 3 columns (Bil, Cawangan, Peringkat)
$r = pmz_add_row($sheetRows, $rowNum, pmz_str_cell(1, $rowNum + 1, PMZ_S_SECTION, 'PERINGKAT MENGIKUT CAWANGAN'));
$merges[] = "A{$r}:C{$r}";
pmz_add_row($sheetRows, $rowNum, pmz_str_cell(1, $rowNum + 1, PMZ_S_COLHDR_CENTER, 'BIL') . pmz_str_cell(2, $rowNum + 1, PMZ_S_COLHDR_CENTER, 'CAWANGAN') . pmz_str_cell(3, $rowNum + 1, PMZ_S_COLHDR_CENTER, 'PERINGKAT'));
if (empty($data['rows3'])) {
    $r = pmz_add_row($sheetRows, $rowNum, pmz_str_cell(1, $rowNum + 1, PMZ_S_CELL_CENTER, 'Tiada cawangan dengan kumpulan pelajar ditemui.'));
    $merges[] = "A{$r}:C{$r}";
} else {
    $no = 1;
    foreach ($data['rows3'] as $row) {
        $peringkat = implode("\n", array_map('strtoupper', $row['peringkat']));
        pmz_add_row(
            $sheetRows, $rowNum,
            pmz_num_cell(1, $rowNum + 1, PMZ_S_CELL_CENTER, $no)
            . pmz_str_cell(2, $rowNum + 1, PMZ_S_CELL_CENTER, $row['school_name'])
            . pmz_str_cell(3, $rowNum + 1, PMZ_S_CELL_CENTER_WRAP, $peringkat)
        );
        $no++;
    }
}
pmz_add_row($sheetRows, $rowNum, '');

// PERINGKAT SETIAP PELAJAR — 3 columns (Bil, Nama Pelajar, Peringkat)
$r = pmz_add_row($sheetRows, $rowNum, pmz_str_cell(1, $rowNum + 1, PMZ_S_SECTION, 'PERINGKAT SETIAP PELAJAR'));
$merges[] = "A{$r}:C{$r}";
pmz_grouped_rows($sheetRows, $rowNum, $merges, $data['rows4'], ['Nama Pelajar', 'Peringkat'], function ($row, $rowN) {
    return pmz_str_cell(2, $rowN, PMZ_S_CELL_LEFT, $row['student_name']) . pmz_str_cell(3, $rowN, PMZ_S_CELL_LEFT, strtoupper($row['level_name']));
}, 3);

$maxRow = $rowNum;
$sheetDataXml = implode('', $sheetRows);
$mergeCellsXml = '';
if (!empty($merges)) {
    $mergeCellsXml = '<mergeCells count="' . count($merges) . '">';
    foreach ($merges as $m) $mergeCellsXml .= "<mergeCell ref=\"{$m}\"/>";
    $mergeCellsXml .= '</mergeCells>';
}

// Sheet (tab) name: Excel forbids : \ / ? * [ ] and caps at 31 chars.
$sheetName = preg_replace('/[:\\\\\/\?\*\[\]]/', '-', $reportTitle);
$sheetName = mb_substr($sheetName, 0, 31);

$contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
    . '</Types>';

$rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    . '</Relationships>';

$workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets><sheet name="' . pmz_esc($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
    . '</workbook>';

$workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    . '</Relationships>';

$stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<fonts count="4">'
    . '<font><sz val="10"/><name val="Arial"/></font>'
    . '<font><b/><sz val="14"/><name val="Arial"/></font>'
    . '<font><b/><sz val="11"/><name val="Arial"/></font>'
    . '<font><b/><sz val="10"/><name val="Arial"/></font>'
    . '</fonts>'
    . '<fills count="4">'
    . '<fill><patternFill patternType="none"/></fill>'
    . '<fill><patternFill patternType="gray125"/></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFDDDDDD"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFEEEEEE"/><bgColor indexed="64"/></patternFill></fill>'
    . '</fills>'
    . '<borders count="3">'
    . '<border><left/><right/><top/><bottom/><diagonal/></border>'
    . '<border><left style="thin"><color auto="1"/></left><right style="thin"><color auto="1"/></right><top style="thin"><color auto="1"/></top><bottom style="thin"><color auto="1"/></bottom><diagonal/></border>'
    . '<border><left/><right/><top/><bottom style="medium"><color auto="1"/></bottom><diagonal/></border>'
    . '</borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="9">'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' // 0 default
    . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 1 title
    . '<xf numFmtId="0" fontId="2" fillId="0" borderId="2" xfId="0" applyFont="1" applyBorder="1"/>' // 2 section
    . '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>' // 3 banner
    . '<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 4 colhdr center
    . '<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>' // 5 colhdr left
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="top"/></xf>' // 6 cell center
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="top" wrapText="1"/></xf>' // 7 cell center wrap
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="top" wrapText="1"/></xf>' // 8 cell left
    . '</cellXfs>'
    . '</styleSheet>';

$sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . "<dimension ref=\"A1:C{$maxRow}\"/>"
    . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
    . '<sheetFormatPr defaultRowHeight="15"/>'
    . '<cols><col min="1" max="1" width="8" customWidth="1"/><col min="2" max="2" width="46" customWidth="1"/><col min="3" max="3" width="38" customWidth="1"/></cols>'
    . "<sheetData>{$sheetDataXml}</sheetData>"
    . $mergeCellsXml
    . '</worksheet>';

$filename = pm_cawangan_report_filename($reportTitle, 'xlsx');
$tmpFile = tempnam(sys_get_temp_dir(), 'pmxlsx');

$zip = new ZipArchive();
$zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('[Content_Types].xml', $contentTypesXml);
$zip->addFromString('_rels/.rels', $rootRelsXml);
$zip->addFromString('xl/workbook.xml', $workbookXml);
$zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
$zip->addFromString('xl/styles.xml', $stylesXml);
$zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
$zip->close();

header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header("Content-Length: " . filesize($tmpFile));
header("Pragma: no-cache");
header("Expires: 0");
readfile($tmpFile);
unlink($tmpFile);
exit;
