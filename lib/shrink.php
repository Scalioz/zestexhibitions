<?php
// Automatic file slimming. Photos, pictures inside Word / Excel / PowerPoint files and pictures inside PDFs
// are resized and re-compressed. Text, vector drawings and everything else in the file is left exactly as it is.
// Every step is "keep the original if anything looks wrong or the result is not clearly smaller".

const SHRINK_LEVELS = [
  'high' => ['max' => 2560, 'q' => 85, 'label' => 'High quality — larger files'],
  'balanced' => ['max' => 2000, 'q' => 80, 'label' => 'Balanced — recommended'],
  'small' => ['max' => 1600, 'q' => 72, 'label' => 'Smallest files'],
];
const SHRINK_DESIGN = ['final', 'wd', 'appr'];   // design files may be printed: kept at a higher resolution
function shrink_cfg($entity = '') {
  $l = setting('file_quality', 'balanced'); $c = SHRINK_LEVELS[$l] ?? SHRINK_LEVELS['balanced'];
  if (in_array($entity, SHRINK_DESIGN, true)) { $c['max'] = (int) round($c['max'] * 1.5); $c['q'] = min(90, $c['q'] + 5); }
  return $c;
}
function shrink_prepare() { @set_time_limit(240); $m = ini_get('memory_limit'); if ($m !== '-1' && (int) $m < 768) @ini_set('memory_limit', '768M'); }
function shrink_mem_ok($w, $h) { return $w > 0 && $h > 0 && $w * $h <= 45000000; }   // ~45 megapixels max (memory safety)

// ---------- images (GD) ----------
function gd_ok() { return function_exists('imagecreatefromstring') && function_exists('imagejpeg'); }
function gd_fit($im, $max) {   // scale down so the long side is at most $max
  $w = imagesx($im); $h = imagesy($im); $k = min(1, $max / max($w, $h));
  if ($k >= 1) return $im;
  $nw = max(1, (int) round($w * $k)); $nh = max(1, (int) round($h * $k));
  $out = imagecreatetruecolor($nw, $nh); imagealphablending($out, false); imagesavealpha($out, true);
  imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
  imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h); imagedestroy($im);
  return $out;
}
function gd_jpeg($im, $q) { ob_start(); imageinterlace($im, true); imagejpeg($im, null, $q); return ob_get_clean(); }
function gd_png($im) { ob_start(); imagesavealpha($im, true); imagepng($im, null, 9); return ob_get_clean(); }
function gd_flatten($im) {   // put transparent areas on white before saving as JPEG
  $w = imagesx($im); $h = imagesy($im); $o = imagecreatetruecolor($w, $h); imagefill($o, 0, 0, imagecolorallocate($o, 255, 255, 255));
  imagealphablending($o, true); imagecopy($o, $im, 0, 0, 0, 0, $w, $h); imagedestroy($im); return $o;
}
function gd_has_alpha($im) {   // any visibly transparent pixel? (checked on a small copy)
  $w = imagesx($im); $h = imagesy($im); $s = min(1, 96 / max($w, $h)); $tw = max(1, (int) ($w * $s)); $th = max(1, (int) ($h * $s));
  $t = imagecreatetruecolor($tw, $th); imagealphablending($t, false); imagesavealpha($t, true); imagecopyresampled($t, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
  for ($y = 0; $y < $th; $y++) for ($x = 0; $x < $tw; $x++) if (((imagecolorat($t, $x, $y) >> 24) & 0x7F) > 8) { imagedestroy($t); return true; }
  imagedestroy($t); return false;
}
function exif_turn($im, $bytes) {   // phone photos: apply the camera's rotation before resizing
  if (!function_exists('exif_read_data')) return $im;
  $fh = fopen('php://memory', 'r+'); fwrite($fh, $bytes); rewind($fh); $e = @exif_read_data($fh); fclose($fh);
  $o = (int) ($e['Orientation'] ?? 1);
  if (in_array($o, [3, 4], true)) $im = imagerotate($im, 180, 0); elseif (in_array($o, [5, 6], true)) $im = imagerotate($im, -90, 0); elseif (in_array($o, [7, 8], true)) $im = imagerotate($im, 90, 0);
  if (in_array($o, [2, 4, 5, 7], true)) imageflip($im, IMG_FLIP_HORIZONTAL);
  return $im;
}

// A photo / image file uploaded on its own. Returns [bytes, ext] or null to keep the original.
function shrink_image_bytes($bytes, $ext, $c) {
  if (!gd_ok() || $ext === 'gif') return null;   // GIFs can be animated: leave them
  $info = @getimagesizefromstring($bytes); if (!$info || !shrink_mem_ok($info[0], $info[1])) return null;
  [$w, $h] = $info; $len = strlen($bytes);
  $big = max($w, $h) > $c['max'];
  if ($ext === 'jpg' || $ext === 'jpeg') {
    if (($info['channels'] ?? 3) === 4) return null;                 // CMYK print files: leave
    if (!$big && $len / ($w * $h) < 0.3) return null;                // already a sensible size (e.g. compressed in the browser)
    $im = @imagecreatefromstring($bytes); if (!$im) return null;
    $out = gd_jpeg(gd_fit(exif_turn($im, $bytes), $c['max']), $c['q']);
    return strlen($out) < $len * 0.9 ? [$out, 'jpg'] : null;
  }
  if ($ext === 'png' || $ext === 'webp') {
    if (!$big && $len < 400 * 1024) return null;
    $im = @imagecreatefromstring($bytes); if (!$im) return null;
    imagealphablending($im, false); imagesavealpha($im, true);
    $im = gd_fit($im, $c['max']);
    if (gd_has_alpha($im)) { $out = $ext === 'webp' && function_exists('imagewebp') ? (function () use ($im, $c) { ob_start(); imagewebp($im, null, $c['q']); return ob_get_clean(); })() : gd_png($im); $oe = $ext; }
    else { $out = gd_jpeg(gd_flatten($im), $c['q']); $oe = 'jpg'; }   // screenshots / renders saved as PNG: JPEG is far smaller
    return strlen($out) < $len * 0.9 ? [$out, $oe] : null;
  }
  return null;
}

// ---------- Word / Excel / PowerPoint (zip files) ----------
function shrink_office($path, $c) {
  if (!class_exists('ZipArchive') || !gd_ok()) return false;
  $tmp = $path . '.tmp'; if (!@copy($path, $tmp)) return false;
  $z = new ZipArchive(); if ($z->open($tmp) !== true) { @unlink($tmp); return false; }
  $changed = 0; $t0 = time(); $renames = []; $count = $z->numFiles;
  for ($i = 0; $i < $count; $i++) {
    if (time() - $t0 > 150) break;
    $n = $z->getNameIndex($i);
    if (!preg_match('~^(word|ppt|xl)/media/[^/]+\.(jpe?g|png)$~i', $n, $m)) continue;
    $st = $z->statIndex($i); if ($st['size'] < 200 * 1024) continue;
    $b = $z->getFromIndex($i); if ($b === false) continue;
    $info = @getimagesizefromstring($b); if (!$info || !shrink_mem_ok($info[0], $info[1])) continue;
    $isJ = strtolower($m[2]) !== 'png';
    if ($isJ && ($info['channels'] ?? 3) === 4) continue;
    if (max($info[0], $info[1]) <= $c['max'] && (!$isJ || strlen($b) / ($info[0] * $info[1]) < 0.3)) continue;
    $im = @imagecreatefromstring($b); if (!$im) continue;
    $toJpg = false;
    if ($isJ) $out = gd_jpeg(gd_fit($im, $c['max']), $c['q']);
    else {
      imagealphablending($im, false); imagesavealpha($im, true); $im = gd_fit($im, $c['max']);
      if (!gd_has_alpha($im)) { $out = gd_jpeg(gd_flatten($im), $c['q']); $toJpg = true; }   // pasted screenshots / renders
      else $out = gd_png($im);
    }
    if (strlen($out) >= strlen($b) * 0.85) continue;
    if ($toJpg) { $nn = preg_replace('~\.png$~i', '.jpg', $n); if ($z->locateName($nn) !== false) { $toJpg = false; $out = null; continue; } $renames[basename($n)] = basename($nn); $z->deleteIndex($i); $z->addFromString($nn, $out); }
    else $z->addFromString($n, $out);
    $changed++;
  }
  if ($renames) {   // point the document's links at the new picture names
    for ($i = 0; $i < $z->numFiles; $i++) {
      $n = $z->getNameIndex($i); if (!$n || !preg_match('~(\.rels|\[Content_Types\]\.xml)$~', $n)) continue;
      $x = $z->getFromIndex($i); $y = $x;
      foreach ($renames as $o => $nw) $y = preg_replace('~(media/)' . preg_quote($o, '~') . '(?=["\'])~', '${1}' . $nw, $y);
      if ($n === '[Content_Types].xml' && !preg_match('~Extension="jpg"~i', $y)) $y = str_replace('</Types>', '<Default Extension="jpg" ContentType="image/jpeg"/></Types>', $y);
      if ($y !== $x) $z->addFromString($n, $y);
    }
  }
  $z->close();
  if ($changed && filesize($tmp) < filesize($path) * 0.95 && (new ZipArchive())->open($tmp) === true) { rename($tmp, $path); return true; }
  @unlink($tmp); return false;
}

// ---------- PDF: re-compress the big pictures inside, rebuild the file index ----------
function pdf_dict_end($s, $p) {   // $p points at "<<"; returns position just after the matching ">>"
  $n = strlen($s); $d = 0;
  while ($p < $n) {
    $ch = $s[$p];
    if ($ch === '<' && ($s[$p + 1] ?? '') === '<') { $d++; $p += 2; continue; }
    if ($ch === '>' && ($s[$p + 1] ?? '') === '>') { $d--; $p += 2; if ($d === 0) return $p; continue; }
    if ($ch === '(') { $lv = 0; while ($p < $n) { $c = $s[$p]; if ($c === '\\') { $p += 2; continue; } if ($c === '(') $lv++; elseif ($c === ')') { $lv--; if ($lv === 0) { $p++; break; } } $p++; } continue; }
    if ($ch === '%') { while ($p < $n && $s[$p] !== "\n" && $s[$p] !== "\r") $p++; continue; }
    $p++;
  }
  return false;
}
function a85_decode($d) {   // ASCII85 (used by some PDF makers)
  $d = preg_replace('~\s+~', '', $d); if (str_starts_with($d, '<~')) $d = substr($d, 2); $e = strpos($d, '~>'); if ($e !== false) $d = substr($d, 0, $e);
  $d = str_replace('z', '!!!!!', $d); $n = strlen($d); $out = ''; $pad = (5 - $n % 5) % 5; $d .= str_repeat('u', $pad); $n += $pad;
  $parts = [];
  for ($i = 0; $i < $n; $i += 5) {
    $v = ((((ord($d[$i]) - 33) * 85 + ord($d[$i + 1]) - 33) * 85 + ord($d[$i + 2]) - 33) * 85 + ord($d[$i + 3]) - 33) * 85 + ord($d[$i + 4]) - 33;
    if ($v > 0xFFFFFFFF) return false; $parts[] = pack('N', $v);
  }
  $out = implode('', $parts); return $pad ? substr($out, 0, -$pad) : $out;
}
function pdf_png_wrap($w, $h, $ctype, $zlib) {   // raw pixels (already zlib + PNG row filters) → a PNG GD can open
  $chunk = fn($t, $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
  return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, $ctype, 0, 0, 0)) . $chunk('IDAT', $zlib) . $chunk('IEND', '');
}
function pdf_image($dict, $data, $objs, $s, $c) {   // returns [newDict, newData] or null
  $origLen = strlen($data);
  if (!preg_match('~/Subtype\s*/Image\b~', $dict) || preg_match('~/ImageMask\s+true|/Decode\s*\[|/Mask\s*\[~', $dict)) return null;
  if (!preg_match('~/Width\s+(\d+)~', $dict, $mw) || !preg_match('~/Height\s+(\d+)~', $dict, $mh)) return null;
  $w = (int) $mw[1]; $h = (int) $mh[1]; if (!shrink_mem_ok($w, $h) || (preg_match('~/BitsPerComponent\s+(\d+)~', $dict, $mb) && (int) $mb[1] !== 8)) return null;
  // colour space: RGB or grey only (ICC profiles checked for 3 / 1 channels)
  $comps = 0;
  if (preg_match('~/ColorSpace\s*/DeviceRGB\b~', $dict)) $comps = 3;
  elseif (preg_match('~/ColorSpace\s*/DeviceGray\b~', $dict)) $comps = 1;
  elseif (preg_match('~/ColorSpace\s*\[\s*/ICCBased\s+(\d+)\s+\d+\s+R\s*\]|/ColorSpace\s+(\d+)\s+\d+\s+R~', $dict, $mc)) {
    $direct = ($mc[1] ?? '') !== ''; $ref = (int) ($direct ? $mc[1] : ($mc[2] ?? 0)); $o = $objs[$ref] ?? null; if (!$o) return null;
    $txt = substr($s, $o['start'], min(400, $o['end'] - $o['start']));
    if (!$direct && preg_match('~obj\s*/DeviceRGB\b~', $txt)) $comps = 3;
    elseif (!$direct && preg_match('~obj\s*/DeviceGray\b~', $txt)) $comps = 1;
    else {
      if (!$direct && !preg_match('~obj\s*\[\s*/ICCBased\s+(\d+)~', $txt, $mi)) return null;   // indirect colour space must be RGB, grey or ICC
      $icc = $direct ? $o : ($objs[(int) $mi[1]] ?? null); if (!$icc) return null;
      $it = substr($s, $icc['start'], min(400, $icc['end'] - $icc['start'])); if (!preg_match('~/N\s+(\d)~', $it, $mn)) return null; $comps = (int) $mn[1];
    }
  }
  if ($comps !== 3 && $comps !== 1) return null;
  // filters: optional text / zip layers first, then the picture itself (JPEG, or zipped raw pixels)
  if (!preg_match('~/Filter\s*(?:\[([^\]]*)\]|(/\w+))~', $dict, $fm)) return null;
  preg_match_all('~/(\w+)~', ($fm[1] ?? '') !== '' ? $fm[1] : $fm[2], $fl); $filters = $fl[1]; $last = array_pop($filters);
  $hasParms = (bool) preg_match('~/DecodeParms~', $dict);
  if ($filters && $hasParms) return null;
  foreach ($filters as $f) {
    if ($f === 'ASCII85Decode' || $f === 'A85') $data = a85_decode($data);
    elseif ($f === 'ASCIIHexDecode' || $f === 'AHx') $data = hex2bin(preg_replace('~[^0-9A-Fa-f]~', '', rtrim($data, "> \r\n")) . (strlen(preg_replace('~[^0-9A-Fa-f]~', '', rtrim($data, "> \r\n"))) % 2 ? '0' : '')) ?: false;
    elseif ($f === 'FlateDecode' || $f === 'Fl') $data = @gzuncompress($data);
    else return null;
    if ($data === false || $data === null) return null;
  }
  $len = $origLen; $big = max($w, $h) > $c['max'];
  if ($last === 'DCTDecode' || $last === 'DCT') {
    if ($hasParms || $len < 150 * 1024 || (!$big && strlen($data) / ($w * $h) < 0.3)) return null;
    $inf = @getimagesizefromstring($data); if (!$inf || ($inf['channels'] ?? 3) === 4) return null;
    $im = @imagecreatefromstring($data); if (!$im) return null;
  } elseif ($last === 'FlateDecode' || $last === 'Fl') {
    if ($len < 400 * 1024 || max($w, $h) < 800) return null;   // only big pictures (renders / photos); small graphics stay crisp
    if ($hasParms) {
      if (!preg_match('~/DecodeParms\s*<<(.*?)>>~s', $dict, $dp) || !preg_match('~/Predictor\s+1[0-5]\b~', $dp[1]) || (preg_match('~/Colors\s+(\d+)~', $dp[1], $cl) && (int) $cl[1] !== $comps) || (preg_match('~/Columns\s+(\d+)~', $dp[1], $co) && (int) $co[1] !== $w)) return null;
      $zlib = $data;   // rows already carry PNG filter bytes
    } else { $raw = @gzuncompress($data); if ($raw === false || strlen($raw) !== $w * $h * $comps) return null; $zlib = gzcompress(implode('', array_map(fn($r) => "\0" . $r, str_split($raw, $w * $comps))), 1); unset($raw); }
    $im = @imagecreatefromstring(pdf_png_wrap($w, $h, $comps === 3 ? 2 : 0, $zlib)); if (!$im) return null;
  } else return null;
  $im = gd_fit($im, $c['max']); $nw = imagesx($im); $nh = imagesy($im);
  $out = gd_jpeg($im, $c['q']); imagedestroy($im);
  if (strlen($out) > $origLen * 0.7) return null;   // not worth it — keep the original picture
  $nd = $dict;
  $nd = preg_replace('~/Width\s+\d+~', "/Width $nw", $nd, 1); $nd = preg_replace('~/Height\s+\d+~', "/Height $nh", $nd, 1);
  $nd = preg_replace('~/Filter\s*(\[[^\]]*\]|/\w+)~', '/Filter /DCTDecode', $nd, 1); $nd = preg_replace('~/DecodeParms\s*(<<.*?>>|\[[^\]]*\])~s', '', $nd, 1);
  if ($comps === 1) $nd = preg_replace('~/ColorSpace\s*(\[[^\]]*\]|/\w+|\d+\s+\d+\s+R)~', '/ColorSpace /DeviceRGB', $nd, 1);   // GD writes colour JPEGs
  $nd = preg_replace('~/Length\s+\d+(\s+\d+\s+R)?~', '/Length ' . strlen($out), $nd, 1);
  return [$nd, $out];
}
function shrink_pdf($path, $c) {
  if (!gd_ok() || !function_exists('gzcompress')) return false;
  $s = file_get_contents($path); if ($s === false || substr($s, 0, 5) !== '%PDF-') return false;
  if (preg_match('~/Encrypt\s+\d+\s+\d+\s+R|/Encrypt\s*<<~', $s)) return false;   // password-protected: leave
  $n = strlen($s);
  // simple "N 0 obj 1234 endobj" objects, used for indirect stream lengths
  $ints = []; if (preg_match_all('~(?<![0-9])(\d+)\s+(\d+)\s+obj\s*(\d+)\s*endobj~', $s, $mm, PREG_SET_ORDER)) foreach ($mm as $x) $ints[(int) $x[1]] = (int) $x[3];
  // walk the file object by object (stream data is skipped by its length, never searched)
  $objs = []; $p = 0; $trailers = [];
  while ($p < $n && preg_match('~(?<![0-9])(\d+)\s+(\d+)\s+obj\b|trailer\s*<<~', $s, $m, PREG_OFFSET_CAPTURE, $p)) {
    $at = $m[0][1];
    if ($m[0][0][0] === 't') { $ds = strpos($s, '<<', $at); $de = pdf_dict_end($s, $ds); if ($de === false) return false; $trailers[] = substr($s, $ds, $de - $ds); $p = $de; continue; }
    $num = (int) $m[1][0]; $gen = (int) $m[2][0]; $q = $at + strlen($m[0][0]);
    while ($q < $n && ctype_space($s[$q])) $q++;
    $dict = null; $ds = null; $data = null; $dataAt = null;
    if (substr($s, $q, 2) === '<<') {
      $de = pdf_dict_end($s, $q); if ($de === false) return false;
      $ds = $q; $dict = substr($s, $q, $de - $q); $q = $de;
      if (preg_match('~\G\s*stream(\r\n|\n|\r)~', $s, $sm, 0, $q)) {
        $dataAt = $q + strlen($sm[0]); $L = null;
        if (preg_match('~/Length\s+(\d+)\s+(\d+)\s+R~', $dict, $lm)) $L = $ints[(int) $lm[1]] ?? null; elseif (preg_match('~/Length\s+(\d+)~', $dict, $lm)) $L = (int) $lm[1];
        if ($L === null || !preg_match('~\G\s*endstream~', $s, $em, 0, $dataAt + $L)) { $e = strpos($s, 'endstream', $dataAt); if ($e === false) return false; $L = $e - $dataAt; while ($L > 0 && ($s[$dataAt + $L - 1] === "\n" || $s[$dataAt + $L - 1] === "\r")) $L--; }
        $data = [$dataAt, $L]; $q = strpos($s, 'endstream', $dataAt + $L) + 9;
      }
    }
    $e = strpos($s, 'endobj', $q); if ($e === false) return false; $end = $e + 6;
    $objs[$num] = ['gen' => $gen, 'start' => $at, 'end' => $end, 'ds' => $ds, 'dict' => $dict, 'data' => $data];   // later definitions win (edited PDFs)
    $p = $end;
  }
  if (!$objs) return false;
  // where to find Root / Info / ID: last trailer or last cross-reference stream
  $tr = ''; foreach ($objs as $o) if ($o['dict'] && preg_match('~/Type\s*/XRef\b~', $o['dict'])) $tr = $o['dict'];
  if ($trailers) $tr = end($trailers) . ' ' . $tr;
  if (!preg_match('~/Root\s+(\d+\s+\d+\s+R)~', $tr, $root)) return false;
  $info = preg_match('~/Info\s+(\d+\s+\d+\s+R)~', $tr, $im) ? $im[1] : null; $id = preg_match('~/ID\s*(\[[^\]]*\])~', $tr, $ii) ? $ii[1] : null;
  // objects packed inside object streams
  $inStm = [];
  foreach ($objs as $num => $o) {
    if (!$o['dict'] || !preg_match('~/Type\s*/ObjStm\b~', $o['dict'])) continue;
    if (!preg_match('~/Filter\s*/FlateDecode~', $o['dict']) || preg_match('~/DecodeParms~', $o['dict'])) return false;
    $raw = @gzuncompress(substr($s, $o['data'][0], $o['data'][1])); if ($raw === false || !preg_match('~/N\s+(\d+)~', $o['dict'], $nn) || !preg_match('~/First\s+(\d+)~', $o['dict'], $ff)) return false;
    $hd = preg_split('~\s+~', trim(substr($raw, 0, (int) $ff[1])));
    for ($k = 0; $k + 1 < count($hd) && $k / 2 < (int) $nn[1]; $k += 2) if (!isset($objs[(int) $hd[$k]]) || $objs[(int) $hd[$k]]['start'] < $o['start']) $inStm[(int) $hd[$k]] = [$num, $k / 2];
  }
  foreach ($inStm as $k => $v) if (isset($objs[$k]) && $objs[$k]['start'] < $objs[$v[0]]['start']) unset($objs[$k]);
  // write the new file
  $t0 = time(); $hit = 0; $outp = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n"; $off = [];
  ksort($objs);
  foreach ($objs as $num => $o) {
    if ($o['dict'] && preg_match('~/Type\s*/XRef\b~', $o['dict'])) continue;
    $off[$num] = [strlen($outp), $o['gen']];
    $r = ($o['data'] && time() - $t0 < 150) ? pdf_image($o['dict'], substr($s, $o['data'][0], $o['data'][1]), $objs, $s, $c) : null;
    if ($r) { $outp .= "$num {$o['gen']} obj\n{$r[0]}\nstream\n{$r[1]}\nendstream\nendobj\n"; $hit++; }
    else $outp .= substr($s, $o['start'], $o['end'] - $o['start']) . "\n";
  }
  if (!$hit) return false;
  $size = max(array_merge(array_keys($objs), array_keys($inStm))) + 2; $xn = $size - 1;
  $off[$xn] = [strlen($outp), 0];
  $rows = '';
  for ($k = 0; $k < $size; $k++) {
    if (isset($off[$k])) $rows .= pack('CNn', 1, $off[$k][0], $off[$k][1]);
    elseif (isset($inStm[$k])) $rows .= pack('CNn', 2, $inStm[$k][0], $inStm[$k][1]);
    else $rows .= pack('CNn', 0, 0, $k === 0 ? 65535 : 0);
  }
  $z = gzcompress($rows, 9);
  $outp .= "$xn 0 obj\n<< /Type /XRef /Size $size /W [1 4 2] /Root {$root[1]}" . ($info ? " /Info $info" : '') . ($id ? " /ID $id" : '') . ' /Filter /FlateDecode /Length ' . strlen($z) . " >>\nstream\n$z\nendstream\nendobj\nstartxref\n{$off[$xn][0]}\n%%EOF\n";
  if (strlen($outp) > $n * 0.9) return false;
  // self-check: every object the index points at must really be there
  foreach ($off as $k => [$o]) if (!preg_match('~\G' . $k . '\s+\d+\s+obj\b~', $outp, $x, 0, $o)) return false;
  file_put_contents($path . '.tmp', $outp); rename($path . '.tmp', $path);
  return true;
}

// One stored file. Returns ['ext' => final extension, 'size' => final size, 'changed' => bool]
function shrink_file($abs, $ext, $entity = '') {
  $c = shrink_cfg($entity); shrink_prepare(); $before = filesize($abs); $done = false; $ne = $ext;
  try {
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
      $r = shrink_image_bytes(file_get_contents($abs), $ext, $c);
      if ($r) { file_put_contents($abs, $r[0]); $ne = $r[1]; $done = true; }
    } elseif (in_array($ext, ['docx', 'pptx', 'xlsx'], true)) $done = shrink_office($abs, $c);
    elseif ($ext === 'pdf') $done = shrink_pdf($abs, $c);
  } catch (Throwable $e) { error_log('[zestcrm shrink] ' . $e->getMessage()); @unlink($abs . '.tmp'); }
  clearstatcache(true, $abs);
  return ['ext' => $ne, 'size' => filesize($abs), 'before' => $before, 'changed' => $done];
}
