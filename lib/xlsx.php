<?php
// Minimal .xlsx writer (no PHP extensions needed). Sheets: ['Name' => [[header...], [row...], ...]]
function xlsx_col($i) { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; }
function xlsx_esc($v) { return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $v), ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

function xlsx_build($sheets) {
  $files = [];
  $names = array_keys($sheets);
  $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
  $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
  $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
  foreach ($names as $i => $n) {
    $k = $i + 1;
    $ct .= "<Override PartName=\"/xl/worksheets/sheet$k.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
    $wb .= '<sheet name="' . xlsx_esc(mb_substr(preg_replace('#[\\\\/?*\[\]:]#', ' ', $n), 0, 31)) . "\" sheetId=\"$k\" r:id=\"rId$k\"/>";
    $rels .= "<Relationship Id=\"rId$k\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet$k.xml\"/>";
    $rows = $sheets[$n];
    $widths = [];
    foreach ($rows as $r) foreach (array_values($r) as $c => $v) $widths[$c] = min(60, max($widths[$c] ?? 8, mb_strlen((string) $v) + 2));
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
    foreach ($widths as $c => $w) $x .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . "\" width=\"$w\" customWidth=\"1\"/>";
    $x .= '</cols><sheetData>';
    foreach ($rows as $ri => $r) {
      $x .= '<row r="' . ($ri + 1) . '">';
      foreach (array_values($r) as $c => $v) {
        $ref = xlsx_col($c) . ($ri + 1); $st = $ri === 0 ? ' s="1"' : '';
        if ($v === null || $v === '') continue;
        if ($ri > 0 && (is_int($v) || is_float($v))) $x .= "<c r=\"$ref\"$st><v>$v</v></c>";
        else $x .= "<c r=\"$ref\" t=\"inlineStr\"$st><is><t xml:space=\"preserve\">" . xlsx_esc($v) . '</t></is></c>';
      }
      $x .= '</row>';
    }
    $x .= '</sheetData>' . (count($rows) > 1 ? '<autoFilter ref="A1:' . xlsx_col(max(0, count($rows[0]) - 1)) . count($rows) . '"/>' : '') . '</worksheet>';
    $files["xl/worksheets/sheet$k.xml"] = $x;
  }
  $k = count($names) + 1;
  $rels .= "<Relationship Id=\"rId$k\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/></Relationships>";
  $files['[Content_Types].xml'] = $ct . '</Types>';
  $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
  $files['xl/workbook.xml'] = $wb . '</sheets></workbook>';
  $files['xl/_rels/workbook.xml.rels'] = $rels;
  $files['xl/styles.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1B2A5C"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
  return zip_store($files);
}

// Tiny ZIP writer (stored, no compression) so it works on any PHP host
function zip_store($files) {
  $data = ''; $cd = ''; $n = 0;
  $t = getdate(); $dt = (($t['year'] - 1980) << 25) | ($t['mon'] << 21) | ($t['mday'] << 16) | ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
  foreach ($files as $name => $body) {
    $crc = crc32($body); $len = strlen($body); $off = strlen($data);
    $h = pack('vvvVVVVvv', 20, 0x0800, 0, $dt, $crc, $len, $len, strlen($name), 0);
    $data .= "PK\x03\x04" . $h . $name . $body;
    $cd .= "PK\x01\x02" . pack('vvvvVVVVvvvvvVV', 20, 20, 0x0800, 0, $dt, $crc, $len, $len, strlen($name), 0, 0, 0, 0, 0, $off) . $name;
    $n++;
  }
  return $data . $cd . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $n, $n, strlen($cd), strlen($data), 0);
}
