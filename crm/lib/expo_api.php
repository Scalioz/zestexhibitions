<?php
// Expo calendar: the AI reads expo web pages into the expo list, and exhibitor lists (PDF / Excel / Word / photo / web link)
// into exhibitor rows; admin assigns each expo to people in the team. Included from api.php for actions starting with xp_.
if (!defined('ZEST_API')) exit;
require_once __DIR__ . '/cards.php';   // the AI key and model are the ones set for business-card reading

// ---------- helpers ----------
function xp_row($id) { $e = one('SELECT * FROM ' . T('expos') . ' WHERE id = ?', [(int) $id]); if (!$e) fail('Expo not found.', 404); return $e; }
function xp_assigned($eid, $uid) { return (bool) val('SELECT COUNT(*) FROM ' . T('expo_assign') . ' WHERE expo_id = ? AND user_id = ?', [(int) $eid, (int) $uid]); }
function xp_can($e) { global $ADMIN, $MID; if (!$ADMIN && !xp_assigned($e['id'], $MID)) fail('This expo is not assigned to you.', 403); }
function xp_date($v) { $v = trim((string) $v); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null; }
function xp_url($v) { $v = trim((string) $v); if ($v === '') return null; if (!preg_match('#^https?://#i', $v)) $v = 'https://' . $v; return filter_var($v, FILTER_VALIDATE_URL) ? mb_substr($v, 0, 500) : null; }

// One call to the AI. $content = message content blocks. Returns the reply text.
function xp_ai($content, $maxTokens = 8000) {
  $ai = cards_ai();
  if ($ai['key'] === '') fail('Reading pages needs the AI key. Add it in Settings → Business card reading (the same key is used here).');
  if (!function_exists('curl_init')) fail('This server cannot connect out (PHP curl is missing).');
  @set_time_limit(300);
  $ch = curl_init($ai['url']);
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['model' => $ai['model'], 'max_tokens' => $maxTokens, 'messages' => [['role' => 'user', 'content' => $content]]]),
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 250, CURLOPT_CONNECTTIMEOUT => 20,
    CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $ai['key'], 'anthropic-version: 2023-06-01']]);
  $res = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch);
  if ($res === false) fail('Could not reach the AI service (' . $cerr . ').');
  $j = json_decode($res, true);
  if ($code !== 200) fail('AI service said: ' . ($j['error']['message'] ?? ('error ' . $code)));
  $t = ''; foreach ((array) ($j['content'] ?? []) as $b) if (($b['type'] ?? '') === 'text') $t .= $b['text'];
  return $t;
}

// Fetch a public web page. Blocks addresses inside the server's own network. Returns ['type' => mime, 'body' => bytes, 'url' => final url]
function xp_fetch($url) {
  if (!function_exists('curl_init')) fail('This server cannot connect out (PHP curl is missing).');
  for ($hop = 0; $hop < 5; $hop++) {
    $u = parse_url($url); $host = $u['host'] ?? '';
    if (!in_array(strtolower($u['scheme'] ?? ''), ['http', 'https'], true) || $host === '') fail('This link does not look right: ' . $url);
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
    if (empty(cfg()['test_local_fetch']) && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) fail('Could not open ' . $host . ' — the site could not be found.');
    $port = (int) ($u['port'] ?? (strtolower($u['scheme']) === 'https' ? 443 : 80));
    $ch = curl_init($url); $body = ''; $big = false;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_ENCODING => '',
      CURLOPT_RESOLVE => ["$host:$port:$ip"], CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
      CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36', 'Accept: text/html,application/xhtml+xml,application/pdf,*/*;q=0.8', 'Accept-Language: en-IN,en;q=0.9'],
      CURLOPT_WRITEFUNCTION => function ($c, $d) use (&$body, &$big) { $body .= $d; if (strlen($body) > 25 * 1048576) { $big = true; return 0; } return strlen($d); }]);
    curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $type = strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE)); $loc = curl_getinfo($ch, CURLINFO_REDIRECT_URL); $err = curl_error($ch); curl_close($ch);
    if ($big) fail('This file is too large to read (over 25 MB).');
    if ($code >= 300 && $code < 400 && $loc) { $url = $loc; continue; }
    if ($code === 0) fail('Could not open the page (' . ($err ?: 'no answer') . '). Copy the page text and use “Paste page text” instead.');
    if ($code >= 400) fail("The site refused to share this page (error $code). Open it in your browser, copy the text (Ctrl+A, Ctrl+C) and use “Paste page text”.");
    return ['type' => $type, 'body' => $body, 'url' => $url];
  }
  fail('The link kept redirecting. Open it in your browser and paste the page text instead.');
}

// Web page → readable text. Links are kept as "text [url]" so the AI can find exhibitor-list and expo-website links.
function xp_html_text($html, $base) {
  if (!mb_check_encoding($html, 'UTF-8')) $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
  $ld = ''; if (preg_match_all('~<script[^>]+application/ld\+json[^>]*>(.*?)</script>~is', $html, $m)) $ld = implode("\n", array_map(fn($x) => mb_substr(trim($x), 0, 20000), $m[1]));
  $title = preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
  $html = preg_replace('~<(script|style|noscript|svg|template|iframe|canvas)\b.*?</\1>~is', ' ', $html);
  $html = preg_replace('~<!--.*?-->~s', ' ', $html);
  $abs = function ($h) use ($base) {
    $h = html_entity_decode(trim($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($h === '' || $h[0] === '#' || preg_match('~^(javascript|mailto|tel|data):~i', $h)) return '';
    if (preg_match('~^https?://~i', $h)) return $h;
    $b = parse_url($base); $root = ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($h, '//')) return ($b['scheme'] ?? 'https') . ':' . $h;
    if ($h[0] === '/') return $root . $h;
    $dir = preg_replace('~/[^/]*$~', '/', $b['path'] ?? '/'); return $root . $dir . $h;
  };
  $html = preg_replace_callback('~<a\b[^>]*href\s*=\s*["\']([^"\']*)["\'][^>]*>(.*?)</a>~is', function ($m) use ($abs) {
    $t = trim(preg_replace('/\s+/', ' ', strip_tags($m[2]))); $u = $abs($m[1]);
    return ' ' . $t . ($u && $t !== '' && mb_strlen($u) < 300 ? " [$u]" : '') . ' ';
  }, $html);
  $html = preg_replace('~<(br|/p|/div|/li|/tr|/h[1-6]|/section|/article|/table|/ul|/ol|/header|/footer)\b[^>]*>~i', "\n", $html);
  $html = preg_replace('~<(td|th)\b[^>]*>~i', " \t ", $html);
  $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $t = preg_replace("/[ \t\x{00A0}]+/u", ' ', $t); $t = preg_replace("/ *\n[ \n]*/", "\n", $t);
  return trim(($title ? "PAGE TITLE: $title\n" : '') . ($ld ? "STRUCTURED DATA:\n$ld\n" : '') . "PAGE TEXT:\n" . trim($t));
}
// Links on the same website that may lead to more exhibitions (events page, calendar, next page of a list, single show pages)
function xp_site_links($html, $base) {
  $bh = preg_replace('/^www\./', '', strtolower((string) parse_url($base, PHP_URL_HOST))); $root = implode('.', array_slice(explode('.', $bh), -2));
  if (preg_match('/\.(co|com|org|net|gov|ac)\.[a-z]{2}$/', $bh)) $root = implode('.', array_slice(explode('.', $bh), -3));
  $out = [];
  if (!preg_match_all('~<a\b[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)</a>~is', $html, $m, PREG_SET_ORDER)) return [];
  foreach ($m as $a) {
    $h = html_entity_decode(trim($a[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'); if ($h === '' || $h[0] === '#' || preg_match('~^(javascript|mailto|tel|data):~i', $h)) continue;
    if (!preg_match('~^https?://~i', $h)) { $b = parse_url($base); $r = ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : ''); $h = str_starts_with($h, '//') ? ($b['scheme'] ?? 'https') . ':' . $h : ($h[0] === '/' ? $r . $h : $r . preg_replace('~/[^/]*$~', '/', $b['path'] ?? '/') . $h); }
    $h = preg_replace('~#.*$~', '', $h); $host = preg_replace('/^www\./', '', strtolower((string) parse_url($h, PHP_URL_HOST)));
    if ($host === '' || ($host !== $root && !str_ends_with($host, '.' . $root))) continue;
    $t = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($a[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))); $path = strtolower((string) parse_url($h, PHP_URL_PATH) . '?' . (string) parse_url($h, PHP_URL_QUERY));
    if (preg_match('~(login|signin|register|registration|contact|career|privacy|terms|cookie|gallery|photo|video|news|blog|press|media|download|sitemap|tender|feedback|faq|book-?(a)?-?stand|visitor-?reg|\.(pdf|jpe?g|png|zip|docx?|xlsx?))~', $path)) continue;
    if (!preg_match('~(event|exhibit|expo|fair|calendar|upcoming|show|trade|edition|schedule|programme|program|forthcoming|next|page[=/-]?\d|^\d+$)~i', $t . ' ' . $path)) continue;
    if (preg_match('~(conference|summit|symposium|seminar|webinar|award|past|archive|previous|post-?show)~i', $t . ' ' . $path)) continue;
    $out[$h] = ['url' => mb_substr($h, 0, 500), 'title' => mb_substr($t, 0, 100)];
    if (count($out) >= 30) break;
  }
  unset($out[$base]);
  return array_values($out);
}
function xp_chunks($text, $size) {
  $out = []; $cur = '';
  foreach (preg_split("/\n/", $text) as $line) {
    if (mb_strlen($line) > $size) { foreach (mb_str_split($line, $size) as $p) { if ($cur !== '') $out[] = $cur; $cur = $p; } continue; }
    if (mb_strlen($cur) + mb_strlen($line) + 1 > $size && $cur !== '') { $out[] = $cur; $cur = ''; }
    $cur .= ($cur === '' ? '' : "\n") . $line;
  }
  if (trim($cur) !== '') $out[] = $cur;
  return $out;
}
function xp_json($text) { $a = strpos($text, '{'); $z = strrpos($text, '}'); return $a !== false && $z !== false ? json_decode(substr($text, $a, $z - $a + 1), true) : null; }
function xp_docx_text($path) {
  if (!class_exists('ZipArchive')) fail('This server cannot open Word files. Save it as PDF and upload that.');
  $z = new ZipArchive(); if ($z->open($path) !== true) fail('Could not open this Word file. Save it as PDF and upload that.');
  $xml = (string) $z->getFromName('word/document.xml'); $z->close();
  $xml = preg_replace(['~</w:p>~', '~<w:tab/>~', '~<w:br/>~', '~</w:tc>~'], ["\n", "\t", "\n", "\t"], $xml);
  return trim(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
}
function xp_sheet_text($path, $name) {
  require_once __DIR__ . '/sheet.php';
  try { $d = sheet_read($path, $name); } catch (Exception $e) { fail($e->getMessage()); }
  $rows = [];
  foreach ((array) $d['rows'] as $r) { $cells = (array) ($r['c'] ?? $r); $line = implode("\t", array_map(fn($c) => trim(preg_replace('/\s+/', ' ', is_array($c) ? '' : (string) $c)), $cells)); if (trim($line) !== '') $rows[] = $line; }
  return implode("\n", $rows);
}

const XP_EXPO_PROMPT = 'Below is text taken from a web page about trade exhibitions (expos / trade fairs / shows). Today is %s. Page address: %s.' . "\n"
  . 'List EVERY exhibition the page describes (one page can be a single expo, or a calendar listing many).' . "\n"
  . 'IMPORTANT: many shows run several editions in different cities on different dates (e.g. ACETECH Mumbai, ACETECH Delhi, ACETECH Bengaluru, ACETECH Hyderabad). '
  . 'List EACH city edition as its own entry with its own dates and venue, and put the city in the name (e.g. "ACETECH Mumbai 2026"). Look at the whole text, including menus, "upcoming shows" lists, tickers, banners and footers. Include future editions; skip editions that are clearly over.' . "\n"
  . 'For each give:' . "\n"
  . '- name: the official exhibition name with the city (for multi-city shows) and the year or edition if shown' . "\n"
  . '- venue: the exhibition centre / venue name' . "\n"
  . '- city, country' . "\n"
  . '- start_date, end_date: YYYY-MM-DD. Work out the year from the page and today\'s date. If the exact day is not given leave it ""' . "\n"
  . '- halls: hall numbers or names if the page mentions them (e.g. "Hall 1-5, 7")' . "\n"
  . '- organiser' . "\n"
  . '- industry: the sectors / products shown, in a few words' . "\n"
  . '- website: the official website of that exhibition, if a link is given' . "\n"
  . '- exhibitor_list_url: a link to its exhibitor list / exhibitor directory / floor plan, if the page has one' . "\n"
  . '- description: one or two short sentences, from the page' . "\n"
  . '- conference: if a conference / summit / seminar runs alongside this exhibition, its name and dates in a few words; otherwise ""' . "\n"
  . 'ONLY list exhibitions where companies take stalls / booths to show products (expo, exhibition, trade fair, trade show, mela). Do NOT list stand-alone conferences, summits, symposiums, seminars, webinars, workshops, award nights, meetings or B2B meet-ups — even if the page lists them as events. If a conference is attached to an exhibition, list the exhibition and put the conference in "conference".' . "\n"
  . 'Use only what the text says. Never invent details; use "" when something is not on the page.' . "\n"
  . 'Also list in "other_pages" up to 12 links from this text (they appear as [https://...] after link words) that are likely to list MORE exhibitions or give their full details: the events / exhibitions / trade-fair calendar page, "upcoming events", the next page of an events list, or the page of a single exhibition or city edition whose dates and venue are not given here. Do not include conference-only, news, gallery, contact or registration pages. Leave it empty if there are none.' . "\n"
  . 'Reply with JSON only: {"expos":[{"name":"","venue":"","city":"","country":"","start_date":"","end_date":"","halls":"","organiser":"","industry":"","website":"","exhibitor_list_url":"","description":"","conference":""}],"other_pages":[{"url":"","title":""}]}';

const XP_ROWS_PROMPT = 'This is %s of the exhibitor list for the exhibition "%s". Pick out EVERY exhibitor company in it.' . "\n"
  . 'Reply with one line per exhibitor and nothing else — no header, no numbering, no notes. Separate the columns with a TAB character:' . "\n"
  . 'company<TAB>contact person<TAB>phone<TAB>email<TAB>hall / stall no.<TAB>city / country<TAB>website<TAB>products' . "\n"
  . 'Leave a column empty when it is not given. Copy names, numbers and emails exactly; never invent anything. Each company once. If there are no exhibitors in this part, reply NONE.';

function xp_rows_parse($text) {
  $out = []; $seen = [];
  foreach (preg_split("/\r?\n/", $text) as $line) {
    if (trim($line) === '' || strtoupper(trim($line)) === 'NONE') continue;
    $c = strpos($line, "\t") !== false ? explode("\t", $line) : (substr_count($line, '|') >= 2 ? explode('|', trim($line, " |")) : null);
    if (!$c) continue;
    $c = array_map(fn($x) => trim(preg_replace('/\s+/', ' ', $x), " \"'*"), $c);
    $co = mb_substr($c[0] ?? '', 0, 200); if ($co === '' || preg_match('/^(company|exhibitor|name)s?$/i', $co)) continue;
    $k = mb_strtolower($co); if (isset($seen[$k])) continue; $seen[$k] = 1;
    $em = strtolower($c[3] ?? ''); if ($em && !filter_var($em, FILTER_VALIDATE_EMAIL)) { preg_match('/[\w.+-]+@[\w-]+\.[\w.-]+/', $em, $mm); $em = $mm[0] ?? ''; }
    $out[] = ['company' => $co, 'person' => mb_substr($c[1] ?? '', 0, 120), 'phone' => mb_substr($c[2] ?? '', 0, 60), 'email' => $em, 'stall' => mb_substr($c[4] ?? '', 0, 80),
      'place' => mb_substr($c[5] ?? '', 0, 120), 'website' => mb_substr($c[6] ?? '', 0, 200), 'products' => mb_substr($c[7] ?? '', 0, 300)];
  }
  return $out;
}
// Fills empty details of an expo already in the list; returns [id, 'new' | 'updated' | 'same']
function xp_upsert($x) {
  global $MID;
  $d = ['name' => s($x['name'] ?? null, 200), 'venue' => s($x['venue'] ?? null, 200), 'city' => s($x['city'] ?? null, 80), 'country' => s($x['country'] ?? null, 60),
    'edate' => xp_date($x['start_date'] ?? ''), 'end_date' => xp_date($x['end_date'] ?? ''), 'halls' => s($x['halls'] ?? null, 200), 'organiser' => s($x['organiser'] ?? null, 200),
    'industry' => s($x['industry'] ?? null, 250), 'website' => xp_url($x['website'] ?? ''), 'list_url' => xp_url($x['exhibitor_list_url'] ?? ''), 'description' => s($x['description'] ?? null, 2000),
    'source_url' => xp_url($x['source_url'] ?? '')];
  if (!$d['name']) return [0, 'skip'];
  if ($d['end_date'] && $d['edate'] && $d['end_date'] < $d['edate']) $d['end_date'] = null;
  $ex = $d['edate'] ? one('SELECT * FROM ' . T('expos') . ' WHERE LOWER(name) = LOWER(?) AND (edate = ? OR edate IS NULL) ORDER BY edate DESC', [$d['name'], $d['edate']])
                    : one('SELECT * FROM ' . T('expos') . ' WHERE LOWER(name) = LOWER(?) ORDER BY edate DESC', [$d['name']]);
  if ($ex) {
    $fill = []; foreach ($d as $k => $v) if ($k !== 'name' && $v !== null && (string) ($ex[$k] ?? '') === '') $fill[$k] = $v;
    if ($fill) { upd('expos', $ex['id'], $fill + ['updated_at' => now()]); return [(int) $ex['id'], 'updated']; }
    return [(int) $ex['id'], 'same'];
  }
  return [ins('expos', array_filter($d, fn($v) => $v !== null) + ['name' => $d['name'], 'created_by' => $MID, 'created_at' => now()]), 'new'];
}
function xp_people($eid) {
  return all('SELECT a.*, u.name, u.department FROM ' . T('expo_assign') . ' a JOIN ' . T('users') . ' u ON u.id = a.user_id WHERE a.expo_id = ? ORDER BY u.name', [(int) $eid]);
}

switch ($A) {

// ---------- reading expo pages (admin) ----------
case 'xp_fetch':   // a web page → text parts for the AI (the browser sends each part to xp_extract)
  need_admin();
  $url = xp_url($in['url'] ?? '') ?? fail('Enter a web link.');
  $r = xp_fetch($url);
  if (str_contains($r['type'], 'pdf') || str_starts_with($r['body'], '%PDF')) fail('This link is a PDF file. Download it and use “Paste page text”, or add it under an expo’s exhibitor list.');
  $text = xp_html_text($r['body'], $r['url']);
  if (mb_strlen($text) < 200) fail('The page loaded but had almost no text (the site probably builds the page in the browser). Open it, select all (Ctrl+A), copy, and use “Paste page text”.');
  $parts = xp_chunks($text, 45000);
  out(['url' => $r['url'], 'parts' => array_slice($parts, 0, 12), 'more' => max(0, count($parts) - 12), 'chars' => mb_strlen($text), 'links' => xp_site_links($r['body'], $r['url'])]);

case 'xp_extract':   // one part of page text → expos found
  need_admin();
  $text = s($in['text'] ?? null, 60000) ?? fail('No text to read.');
  $url = xp_url($in['url'] ?? '') ?? 'pasted text';
  $d = xp_json(xp_ai([['type' => 'text', 'text' => sprintf(XP_EXPO_PROMPT, today(), $url) . "\n\n----- PAGE -----\n" . $text]], 12000));
  if (!is_array($d) || !isset($d['expos'])) fail('Could not make out the expo details on this page. Try “Paste page text” with just the part about the expo.');
  $out = [];
  foreach (array_slice((array) $d['expos'], 0, 150) as $x) { $x = (array) $x; if (trim((string) ($x['name'] ?? '')) === '') continue;
    $row = []; foreach (['name', 'venue', 'city', 'country', 'start_date', 'end_date', 'halls', 'organiser', 'industry', 'website', 'exhibitor_list_url', 'description'] as $k) $row[$k] = mb_substr(trim((string) ($x[$k] ?? '')), 0, $k === 'description' ? 2000 : 300);
    foreach (['start_date', 'end_date'] as $k) $row[$k] = xp_date($row[$k]) ?? '';
    if ($cf = mb_substr(trim((string) ($x['conference'] ?? '')), 0, 300)) $row['description'] = trim($row['description'] . ' Alongside: ' . $cf . '.');
    if (preg_match('/\b(conference|summit|symposium|seminar|webinar|conclave|awards?)\b/i', $row['name']) && !preg_match('/\b(expo|exhibition|fair|show|mela|exhibitions?)\b/i', $row['name'])) continue;   // safety net: conference-only events
    $row['website'] = xp_url($row['website']) ?? ''; $row['exhibitor_list_url'] = xp_url($row['exhibitor_list_url']) ?? '';
    $ex = $row['start_date'] ? val('SELECT id FROM ' . T('expos') . ' WHERE LOWER(name) = LOWER(?) AND (edate = ? OR edate IS NULL)', [$row['name'], $row['start_date']]) : val('SELECT id FROM ' . T('expos') . ' WHERE LOWER(name) = LOWER(?)', [$row['name']]);
    $out[] = $row + ['source_url' => $url === 'pasted text' ? '' : $url, 'existing_id' => $ex ? (int) $ex : null];
  }
  $more = [];
  foreach (array_slice((array) ($d['other_pages'] ?? []), 0, 12) as $m) { $u = xp_url(((array) $m)['url'] ?? ''); if ($u && $u !== $url) $more[$u] = ['url' => $u, 'title' => mb_substr(trim((string) (((array) $m)['title'] ?? '')), 0, 120)]; }
  out(['expos' => $out, 'other_pages' => array_values($more)]);

case 'xp_save':   // ticked expos from the preview → expo list (an expo already there only gets its empty details filled)
  need_admin();
  $res = ['new' => 0, 'updated' => 0, 'same' => 0, 'ids' => []];
  tx();
  foreach (array_slice((array) ($in['expos'] ?? []), 0, 300) as $x) { [$id, $how] = xp_upsert((array) $x); if (!$id) continue; $res[$how]++; $res['ids'][] = $id; }
  db()->commit();
  out($res);

// ---------- calendar + one expo ----------
case 'xp_list':
  need_admin();
  $w = []; $p = []; $when = $in['when'] ?? 'upcoming'; $t = today();
  if ($when === 'upcoming') { $w[] = '(COALESCE(end_date, edate) >= ? OR edate IS NULL)'; $p[] = $t; }
  elseif ($when === 'past') { $w[] = 'COALESCE(end_date, edate) < ?'; $p[] = $t; }
  if ($q = s($in['q'] ?? null, 100)) { $w[] = '(name LIKE ? OR city LIKE ? OR venue LIKE ? OR industry LIKE ? OR organiser LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%"); }
  if (!empty($in['india'])) { $w[] = "(country IS NULL OR country = '' OR LOWER(country) = 'india')"; }
  $rows = all('SELECT * FROM ' . T('expos') . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY ' . ($when === 'past' ? 'edate DESC' : 'CASE WHEN edate IS NULL THEN 1 ELSE 0 END, edate') . ', name LIMIT 1000', $p);
  $ids = array_map('intval', array_column($rows, 'id')); $cnt = []; $asg = []; $fl = [];
  if ($ids) { $in_ = implode(',', $ids);
    foreach (all('SELECT expo_id, COUNT(*) AS n FROM ' . T('leads') . " WHERE expo_id IN ($in_) GROUP BY expo_id") as $r) $cnt[$r['expo_id']] = (int) $r['n'];
    foreach (all('SELECT a.expo_id, a.done_at, u.name FROM ' . T('expo_assign') . ' a JOIN ' . T('users') . " u ON u.id = a.user_id WHERE a.expo_id IN ($in_) ORDER BY u.name") as $r) $asg[$r['expo_id']][] = ['name' => $r['name'], 'done' => $r['done_at'] ? 1 : 0];
    foreach (all('SELECT entity_id, COUNT(*) AS n FROM ' . T('files') . " WHERE entity = 'expo' AND entity_id IN ($in_) GROUP BY entity_id") as $r) $fl[$r['entity_id']] = (int) $r['n'];
    foreach (all('SELECT expo_id, COUNT(*) AS n FROM ' . T('expo_links') . " WHERE expo_id IN ($in_) GROUP BY expo_id") as $r) $fl[$r['expo_id']] = ($fl[$r['expo_id']] ?? 0) + (int) $r['n'];
  }
  foreach ($rows as &$r) $r += ['exhibitors' => $cnt[$r['id']] ?? 0, 'people' => $asg[$r['id']] ?? [], 'lists' => $fl[$r['id']] ?? 0]; unset($r);
  out(['expos' => $rows, 'counts' => ['upcoming' => (int) val('SELECT COUNT(*) FROM ' . T('expos') . ' WHERE COALESCE(end_date, edate) >= ? OR edate IS NULL', [$t]), 'all' => (int) val('SELECT COUNT(*) FROM ' . T('expos'))]]);

case 'xp_get':
  $e = xp_row($in['id'] ?? 0); xp_can($e);
  if (!$ADMIN) q('UPDATE ' . T('expo_assign') . ' SET seen_at = ? WHERE expo_id = ? AND user_id = ? AND seen_at IS NULL', [now(), $e['id'], $MID]);
  $um = users_map();
  $files = all('SELECT id, orig_name, size, mime, uploaded_by, created_at FROM ' . T('files') . " WHERE entity = 'expo' AND entity_id = ? ORDER BY id", [$e['id']]);
  foreach ($files as &$f) $f['by'] = $um[$f['uploaded_by']] ?? '—'; unset($f);
  $links = all('SELECT * FROM ' . T('expo_links') . ' WHERE expo_id = ? ORDER BY id', [$e['id']]);
  foreach ($links as &$l) $l['by'] = $um[$l['added_by']] ?? '—'; unset($l);
  $rows = all('SELECT l.id, l.company, l.person, l.phone, l.email, l.notes, l.heat, l.user_id, l.created_at FROM ' . T('leads') . ' l WHERE l.expo_id = ? ORDER BY l.id', [$e['id']]);
  foreach ($rows as &$r) $r['by'] = $um[$r['user_id']] ?? '—'; unset($r);
  $e['created_by_name'] = $um[$e['created_by']] ?? '—';
  $orders = all('SELECT p.id, p.status, c.company FROM ' . T('projects') . ' p JOIN ' . T('clients') . ' c ON c.id = p.client_id WHERE LOWER(p.expo) = LOWER(?)' . ($ADMIN ? '' : ' AND p.owner_id = ' . (int) $MID) . ' ORDER BY p.id DESC LIMIT 200', [$e['name']]);
  out(['expo' => $e, 'people' => xp_people($e['id']), 'files' => $files, 'links' => $links, 'rows' => $rows, 'orders' => $orders, 'mine' => $ADMIN ? null : one('SELECT * FROM ' . T('expo_assign') . ' WHERE expo_id = ? AND user_id = ?', [$e['id'], $MID])]);

case 'xp_update':
  need_admin(); $e = xp_row($in['id'] ?? 0); $f = (array) ($in['fields'] ?? []);
  $d = []; foreach (['name' => 200, 'venue' => 200, 'city' => 80, 'country' => 60, 'halls' => 200, 'organiser' => 200, 'industry' => 250, 'description' => 2000] as $k => $n) if (array_key_exists($k, $f)) $d[$k] = s($f[$k], $n);
  foreach (['edate', 'end_date'] as $k) if (array_key_exists($k, $f)) $d[$k] = d($f[$k]);
  foreach (['website', 'list_url'] as $k) if (array_key_exists($k, $f)) $d[$k] = xp_url($f[$k]);
  if (array_key_exists('name', $d) && !$d['name']) fail('Expo name cannot be empty.');
  if ($d) upd('expos', $e['id'], $d + ['updated_at' => now()]);
  out(['ok' => true]);

case 'xp_del':
  need_admin(); $e = xp_row($in['id'] ?? 0);
  if ($n = (int) val('SELECT COUNT(*) FROM ' . T('leads') . ' WHERE expo_id = ?', [$e['id']])) fail("This expo has $n exhibitor entries. Remove them first (Expo data log) or keep the expo.");
  tx(); q('DELETE FROM ' . T('expo_assign') . ' WHERE expo_id = ?', [$e['id']]); q('DELETE FROM ' . T('expo_links') . ' WHERE expo_id = ?', [$e['id']]);
  q('DELETE FROM ' . T('files') . " WHERE entity = 'expo' AND entity_id = ?", [$e['id']]); q('DELETE FROM ' . T('expos') . ' WHERE id = ?', [$e['id']]); db()->commit();
  out(['ok' => true]);

case 'xp_assign':   // set who works on this expo; people already on it keep their progress
  need_admin(); $e = xp_row($in['id'] ?? 0);
  $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['user_ids'] ?? [])))));
  $valid = array_map('intval', array_column(all('SELECT id FROM ' . T('users') . ' WHERE active = 1'), 'id'));
  foreach ($ids as $u) if (!in_array($u, $valid, true)) fail('One of the people picked is not an active user.');
  $note = s($in['note'] ?? null, 2000); $due = d($in['due'] ?? null);
  tx();
  $have = array_map('intval', array_column(all('SELECT user_id FROM ' . T('expo_assign') . ' WHERE expo_id = ?', [$e['id']]), 'user_id'));
  foreach (array_diff($have, $ids) as $u) q('DELETE FROM ' . T('expo_assign') . ' WHERE expo_id = ? AND user_id = ?', [$e['id'], $u]);
  foreach ($ids as $u) {
    if (in_array($u, $have, true)) q('UPDATE ' . T('expo_assign') . ' SET note = ?, due = ? WHERE expo_id = ? AND user_id = ?', [$note, $due, $e['id'], $u]);
    else ins('expo_assign', ['expo_id' => $e['id'], 'user_id' => $u, 'note' => $note, 'due' => $due, 'assigned_by' => $MID, 'assigned_at' => now()]);
  }
  db()->commit();
  out(['ok' => true, 'people' => xp_people($e['id'])]);

case 'xp_done':   // the person assigned marks their work on the expo done (or not done)
  $e = xp_row($in['id'] ?? 0); $uid = $ADMIN && !empty($in['user_id']) ? (int) $in['user_id'] : $MID;
  if (!xp_assigned($e['id'], $uid)) fail('This expo is not assigned to you.', 403);
  q('UPDATE ' . T('expo_assign') . ' SET done_at = ?, done_note = ? WHERE expo_id = ? AND user_id = ?', [!empty($in['done']) ? now() : null, s($in['note'] ?? null, 2000), $e['id'], $uid]);
  if (!$ADMIN) touch_activity();
  out(['ok' => true]);

case 'xp_mine':
  $rows = all('SELECT e.*, a.note AS a_note, a.due, a.assigned_at, a.seen_at, a.done_at, a.done_note FROM ' . T('expo_assign') . ' a JOIN ' . T('expos') . ' e ON e.id = a.expo_id WHERE a.user_id = ? ORDER BY CASE WHEN a.done_at IS NULL THEN 0 ELSE 1 END, CASE WHEN e.edate IS NULL THEN 1 ELSE 0 END, e.edate', [$MID]);
  $ids = array_map('intval', array_column($rows, 'id')); $cnt = [];
  if ($ids) foreach (all('SELECT expo_id, COUNT(*) AS n FROM ' . T('leads') . ' WHERE expo_id IN (' . implode(',', $ids) . ') GROUP BY expo_id') as $r) $cnt[$r['expo_id']] = (int) $r['n'];
  foreach ($rows as &$r) $r['exhibitors'] = $cnt[$r['id']] ?? 0; unset($r);
  out(['expos' => $rows]);

// ---------- exhibitor lists ----------
case 'xp_link_add':
  $e = xp_row($in['id'] ?? 0); xp_can($e);
  $url = xp_url($in['url'] ?? '') ?? fail('Enter the web link of the exhibitor list.');
  $id = ins('expo_links', ['expo_id' => $e['id'], 'url' => $url, 'label' => s($in['label'] ?? null, 200), 'added_by' => $MID, 'created_at' => now()]);
  out(['id' => $id]);

case 'xp_link_del':
  $l = one('SELECT * FROM ' . T('expo_links') . ' WHERE id = ?', [(int) ($in['id'] ?? 0)]) ?? fail('Not found.', 404);
  if (!$ADMIN && (int) $l['added_by'] !== $MID) fail('Only admin or the person who added it can remove this link.', 403);
  q('DELETE FROM ' . T('expo_links') . ' WHERE id = ?', [$l['id']]); out(['ok' => true]);

case 'xp_src':   // an exhibitor-list file or link → what the browser should send to the AI
  $e = xp_row($in['expo_id'] ?? 0); xp_can($e);
  if (!empty($in['file_id'])) {
    $f = one('SELECT * FROM ' . T('files') . " WHERE id = ? AND entity = 'expo' AND entity_id = ?", [(int) $in['file_id'], $e['id']]) ?? fail('File not found.', 404);
    $path = __DIR__ . '/../uploads/' . $f['stored_name']; if (!is_file($path)) fail('File missing on server.');
    $ext = strtolower(pathinfo($f['orig_name'], PATHINFO_EXTENSION));
    if ($ext === 'pdf') out(['kind' => 'pdf', 'file_id' => (int) $f['id']]);
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic', 'gif'], true)) out(['kind' => 'image', 'file_id' => (int) $f['id']]);
    if (in_array($ext, ['xlsx', 'xlsm', 'csv', 'txt'], true)) $text = $ext === 'txt' ? (string) file_get_contents($path) : xp_sheet_text($path, $f['orig_name']);
    elseif ($ext === 'docx') $text = xp_docx_text($path);
    elseif (in_array($ext, ['doc', 'xls'], true)) fail('This is an old Office file (.' . $ext . '). Open it and save as .' . ($ext === 'doc' ? 'docx' : 'xlsx') . ' or PDF, then upload that.');
    else fail('The AI cannot read this kind of file. Upload a PDF, Excel, Word or photo.');
  } else {
    $l = one('SELECT * FROM ' . T('expo_links') . ' WHERE id = ? AND expo_id = ?', [(int) ($in['link_id'] ?? 0), $e['id']]) ?? fail('Link not found.', 404);
    $r = xp_fetch($l['url']);
    $ext = strtolower(pathinfo(parse_url($r['url'], PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
    $isPdf = str_contains($r['type'], 'pdf') || str_starts_with($r['body'], '%PDF');
    $isSheet = str_contains($r['type'], 'spreadsheet') || in_array($ext, ['xlsx', 'csv'], true);
    $isDoc = str_contains($r['type'], 'wordprocessing') || $ext === 'docx';
    if ($isPdf || $isSheet || $isDoc) {   // a file behind the link: keep a copy with the expo, then read it like an upload
      $x = $isPdf ? 'pdf' : ($isDoc ? 'docx' : ($ext === 'csv' ? 'csv' : 'xlsx'));
      $dir = __DIR__ . '/../uploads/' . date('Y/m'); if (!is_dir($dir)) mkdir($dir, 0755, true);
      $stored = date('Y/m/') . bin2hex(random_bytes(12)) . '.' . $x; file_put_contents(__DIR__ . '/../uploads/' . $stored, $r['body']);
      $nm = mb_substr(basename(parse_url($r['url'], PHP_URL_PATH) ?: 'exhibitor-list') ?: 'exhibitor-list', 0, 200); if (!preg_match('/\.' . $x . '$/i', $nm)) $nm .= '.' . $x;
      $fid = ins('files', ['entity' => 'expo', 'entity_id' => $e['id'], 'orig_name' => $nm, 'stored_name' => $stored, 'mime' => $isPdf ? 'application/pdf' : 'application/octet-stream',
        'size' => strlen($r['body']), 'orig_size' => strlen($r['body']), 'shrunk' => 1, 'uploaded_by' => $MID, 'created_at' => now()]);
      if ($isPdf) out(['kind' => 'pdf', 'file_id' => $fid, 'saved' => $nm]);
      $text = $isDoc ? xp_docx_text(__DIR__ . '/../uploads/' . $stored) : xp_sheet_text(__DIR__ . '/../uploads/' . $stored, $nm);
    } else {
      $text = xp_html_text($r['body'], $r['url']);
      if (mb_strlen($text) < 300) fail('The page loaded but had almost no text (the site builds the list in the browser, or it needs a login). Open it, select all (Ctrl+A), copy and use “Paste list text”.');
    }
  }
  $text = trim((string) $text); if ($text === '') fail('No text found in this file. If it is a scanned list, upload it as a PDF or photo instead.');
  $parts = xp_chunks($text, 28000);
  out(['kind' => 'text', 'parts' => array_slice($parts, 0, 40), 'more' => max(0, count($parts) - 40)]);

case 'xp_rows':   // one part of an exhibitor list (text, page photos, or an uploaded photo) → exhibitor rows
  $e = xp_row($in['expo_id'] ?? 0); xp_can($e);
  $label = s($in['label'] ?? null, 60) ?: 'a part';
  $prompt = sprintf(XP_ROWS_PROMPT, $label, $e['name']);
  if (!empty($in['file_id'])) {   // a photo uploaded to this expo
    $f = one('SELECT * FROM ' . T('files') . " WHERE id = ? AND entity = 'expo' AND entity_id = ?", [(int) $in['file_id'], $e['id']]) ?? fail('File not found.', 404);
    $imgs = card_images(__DIR__ . '/../uploads/' . $f['stored_name']) ?: fail('Could not open this photo.');
    $content = array_map(fn($b) => ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $b]], $imgs);
    $content[] = ['type' => 'text', 'text' => $prompt . "\nThe first image is the whole photo; any others are zoomed-in parts of the same photo — list each company once."];
  } elseif (!empty($in['images'])) {   // scanned PDF pages rendered by the browser
    $content = [];
    foreach (array_slice((array) $in['images'], 0, 4) as $b) { $b = preg_replace('~^data:image/\w+;base64,~', '', (string) $b); if (strlen($b) > 6 * 1048576 || !base64_decode($b, true)) fail('A page image is too large or damaged.'); $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $b]]; }
    $content[] = ['type' => 'text', 'text' => $prompt];
  } else {
    $text = s($in['text'] ?? null, 40000) ?? fail('Nothing to read.');
    $content = [['type' => 'text', 'text' => $prompt . "\n\n----- LIST -----\n" . $text]];
  }
  out(['rows' => xp_rows_parse(xp_ai($content, 16000))]);

case 'xp_rows_add':   // checked rows → exhibitor entries of this expo (same company already there is skipped)
  $e = xp_row($in['expo_id'] ?? 0); xp_can($e);
  $dept = $me['department'] ?? 'Admin'; $added = 0; $skipped = 0; $seen = [];
  tx();
  foreach (array_slice((array) ($in['rows'] ?? []), 0, 3000) as $r) { $r = (array) $r;
    $co = s($r['company'] ?? null, 200); if (!$co) continue; $k = mb_strtolower($co);
    if (isset($seen[$k]) || val('SELECT COUNT(*) FROM ' . T('leads') . ' WHERE expo_id = ? AND LOWER(company) = LOWER(?)', [$e['id'], $co])) { $skipped++; continue; }
    $em = strtolower(s($r['email'] ?? null, 160) ?? ''); if ($em && !filter_var($em, FILTER_VALIDATE_EMAIL)) $em = '';
    $notes = implode(' · ', array_filter([($r['stall'] ?? '') !== '' ? 'Hall/stall: ' . $r['stall'] : '', ($r['place'] ?? '') !== '' ? $r['place'] : '',
      ($r['website'] ?? '') !== '' ? 'Web: ' . $r['website'] : '', ($r['products'] ?? '') !== '' ? 'Products: ' . $r['products'] : '']));
    ins('leads', ['expo_id' => $e['id'], 'user_id' => $MID, 'dept' => $dept, 'company' => $co, 'person' => s($r['person'] ?? null, 120), 'phone' => s($r['phone'] ?? null, 60),
      'email' => $em ?: null, 'notes' => s($notes, 4000), 'created_at' => now()]);
    $seen[$k] = 1; $added++;
  }
  db()->commit();
  if ($added) touch_activity();
  out(['added' => $added, 'skipped' => $skipped]);

default: fail('Unknown action.');
}
