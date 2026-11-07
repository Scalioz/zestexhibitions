<?php
// Reads the first sheet of an Excel (.xlsx) or CSV file into rows of text. No outside libraries needed.
// Returns ['sheet' => name, 'rows' => [[n => sheet row number, c => [cells]], ...]]

function sheet_read($path, $name) {
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if ($ext === 'csv' || $ext === 'txt') return sheet_csv($path);
  if ($ext === 'xls') throw new Exception('This is an old Excel file (.xls). Open it in Excel and use File → Save As → Excel Workbook (.xlsx), then upload that.');
  if ($ext !== 'xlsx' && $ext !== 'xlsm') throw new Exception('Please upload an Excel (.xlsx) or CSV file.');
  return sheet_xlsx($path);
}

function sheet_cell_text($v) {
  $v = trim((string) $v);
  // big whole numbers (phone numbers) are often stored as 9876543210 or 9.87654321E9 — keep all the digits
  if (preg_match('/^-?\d+(\.\d+)?E[+-]?\d+$/i', $v) && abs((float) $v) >= 1e6 && floor((float) $v) == (float) $v) return sprintf('%.0f', (float) $v);
  if (preg_match('/^\d{6,}\.0+$/', $v)) return preg_replace('/\.0+$/', '', $v);
  return $v;
}

function sheet_xlsx($path) {
  if (!class_exists('ZipArchive')) throw new Exception('The server cannot open Excel files (zip support is off). Save the sheet as CSV and upload that instead.');
  $z = new ZipArchive();
  if ($z->open($path) !== true) throw new Exception('Could not open this Excel file. It may be damaged — try saving it again.');
  $x = fn($p) => ($s = $z->getFromName($p)) === false ? null : @simplexml_load_string($s, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);

  // which file is the first sheet?
  $target = 'xl/worksheets/sheet1.xml'; $sheetName = 'Sheet1';
  if (($wb = $x('xl/workbook.xml')) && ($rels = $x('xl/_rels/workbook.xml.rels'))) {
    $wb->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $first = $wb->xpath('//m:sheets/m:sheet')[0] ?? null;
    if ($first) {
      $sheetName = (string) $first['name'];
      $rid = (string) ($first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
      foreach ($rels->Relationship as $r) if ((string) $r['Id'] === $rid) { $t = ltrim((string) $r['Target'], '/'); $target = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t; }
    }
  }
  // shared text
  $shared = [];
  if ($ss = $x('xl/sharedStrings.xml')) foreach ($ss->si as $si) {
    if (isset($si->t)) { $shared[] = (string) $si->t; continue; }
    $t = ''; foreach ($si->r as $run) $t .= (string) $run->t; $shared[] = $t;
  }
  $sh = $x($target); $z->close();
  if (!$sh) throw new Exception('Could not read the first sheet of this file.');

  $col = function ($ref) { $n = 0; foreach (str_split(preg_replace('/\d+/', '', $ref)) as $ch) $n = $n * 26 + (ord(strtoupper($ch)) - 64); return $n - 1; };
  $rows = []; $count = 0;
  foreach ($sh->sheetData->row as $row) {
    if (++$count > 20000) throw new Exception('This sheet has more than 20,000 rows. Please split it into smaller files.');
    $n = (int) $row['r'] ?: $count; $cells = []; $i = 0;
    foreach ($row->c as $c) {
      $j = isset($c['r']) ? $col((string) $c['r']) : $i; $i = $j + 1;
      $t = (string) $c['t'];
      if ($t === 's') $v = $shared[(int) $c->v] ?? '';
      elseif ($t === 'inlineStr') { $v = isset($c->is->t) ? (string) $c->is->t : ''; if ($v === '' && isset($c->is->r)) foreach ($c->is->r as $run) $v .= (string) $run->t; }
      elseif ($t === 'b') $v = ((string) $c->v) === '1' ? 'TRUE' : 'FALSE';
      else $v = (string) $c->v;
      if ($j < 200) $cells[$j] = sheet_cell_text($v);
    }
    if (!$cells) continue;
    $w = max(array_keys($cells)) + 1; $line = array_fill(0, $w, ''); foreach ($cells as $j => $v) $line[$j] = $v;
    if (implode('', $line) !== '') $rows[] = ['n' => $n, 'c' => $line];
  }
  return ['sheet' => $sheetName, 'rows' => $rows];
}

function sheet_csv($path) {
  $raw = file_get_contents($path);
  if ($raw === false || trim($raw) === '') throw new Exception('This file is empty.');
  $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
  if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
  $first = strtok($raw, "\n");
  $d = ','; $best = 0; foreach ([',', ';', "\t", '|'] as $c) if (substr_count($first, $c) > $best) { $best = substr_count($first, $c); $d = $c; }
  $fh = fopen('php://temp', 'r+'); fwrite($fh, $raw); rewind($fh);
  $rows = []; $n = 0;
  while (($r = fgetcsv($fh, 0, $d, '"', '')) !== false) {
    $n++; if ($n > 20000) throw new Exception('This file has more than 20,000 rows. Please split it into smaller files.');
    $r = array_map(fn($v) => sheet_cell_text($v ?? ''), array_slice($r, 0, 200));
    if (implode('', $r) !== '') $rows[] = ['n' => $n, 'c' => $r];
  }
  fclose($fh);
  return ['sheet' => 'CSV', 'rows' => $rows];
}
