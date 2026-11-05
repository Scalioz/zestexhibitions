<?php
// Business cards: read a photo of many cards with an AI vision service, save each card as a contact,
// and one Contacts directory that brings together every contact the company has (cards, clients,
// client contacts, expo data from research / telecalling, referral leads).

function cards_ai() {
  $c = (array) setting('cards_ai', []);
  return ['key' => (string) ($c['key'] ?? ''), 'model' => (string) (($c['model'] ?? '') ?: 'claude-sonnet-5-5'), 'url' => (string) (($c['url'] ?? '') ?: 'https://api.anthropic.com/v1/messages')];
}
function card_digits($p) { $d = preg_replace('/\D/', '', (string) $p); return strlen($d) >= 10 ? substr($d, -10) : ($d ?: null); }

// The photo as the AI will see it: the whole photo, plus zoomed quarters when it is large (small print on far-away cards).
function card_images($abs) {
  if (!function_exists('imagecreatefromstring')) return null;
  $bytes = file_get_contents($abs); $im = @imagecreatefromstring($bytes); if (!$im) return null;
  if (function_exists('exif_turn')) $im = exif_turn($im, $bytes);
  $w = imagesx($im); $h = imagesy($im); $out = [];
  $enc = function ($src, $x, $y, $cw, $ch) {
    $k = min(1, 1568 / max($cw, $ch)); $nw = max(1, (int) round($cw * $k)); $nh = max(1, (int) round($ch * $k));
    $t = imagecreatetruecolor($nw, $nh); imagecopyresampled($t, $src, 0, 0, $x, $y, $nw, $nh, $cw, $ch);
    ob_start(); imagejpeg($t, null, 88); imagedestroy($t); return base64_encode(ob_get_clean());
  };
  $out[] = $enc($im, 0, 0, $w, $h);
  if (max($w, $h) > 2000) {   // 2 x 2 zoomed parts with overlap, so a card on a dividing line is still whole in one part
    $ov = 0.18; $cw = (int) ($w * (0.5 + $ov / 2)); $ch = (int) ($h * (0.5 + $ov / 2));
    foreach ([[0, 0], [$w - $cw, 0], [0, $h - $ch], [$w - $cw, $h - $ch]] as [$x, $y]) $out[] = $enc($im, $x, $y, $cw, $ch);
  }
  imagedestroy($im);
  return $out;
}

const CARD_PROMPT = 'These images show business cards (visiting cards) photographed together, usually at an exhibition. '
  . 'The first image is the whole photo. Any further images are zoomed-in parts of the same photo, given only so small print can be read — the same card can appear in several images: list each physical card ONCE. '
  . 'Read every card that is visible, including the back of a card if it carries contact details. Ignore cards that are cut off so badly that no name, phone or email can be read. '
  . 'For each card give: name (the person), designation, company, phones (all numbers, mobile first, keep country code if printed), emails, website, city, address. '
  . 'Copy text exactly as printed; do not guess or invent anything; use "" or [] when a detail is not on the card. '
  . 'Reply with JSON only, no other text, in this shape: {"cards":[{"name":"","designation":"","company":"","phones":[],"emails":[],"website":"","city":"","address":""}]}';

// Returns ['cards' => [...], 'error' => null|string]
function card_read($abs) {
  $ai = cards_ai();
  if ($ai['key'] === '') return ['cards' => [], 'error' => 'nokey'];
  if (!function_exists('curl_init')) return ['cards' => [], 'error' => 'This server cannot connect out (PHP curl is missing).'];
  $imgs = card_images($abs); if (!$imgs) return ['cards' => [], 'error' => 'Could not open this photo. Please upload a JPG or PNG.'];
  $content = array_map(fn($b) => ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $b]], $imgs);
  $content[] = ['type' => 'text', 'text' => CARD_PROMPT];
  $body = json_encode(['model' => $ai['model'], 'max_tokens' => 16000, 'temperature' => 0, 'messages' => [['role' => 'user', 'content' => $content]]]);
  @set_time_limit(300);
  $ch = curl_init($ai['url']);
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 240, CURLOPT_CONNECTTIMEOUT => 20,
    CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $ai['key'], 'anthropic-version: 2023-06-01']]);
  $res = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch);
  if ($res === false) return ['cards' => [], 'error' => 'Could not reach the card-reading service (' . $cerr . ').'];
  $j = json_decode($res, true);
  if ($code !== 200) return ['cards' => [], 'error' => 'Card-reading service said: ' . ($j['error']['message'] ?? ('error ' . $code))];
  $text = ''; foreach ((array) ($j['content'] ?? []) as $b) if (($b['type'] ?? '') === 'text') $text .= $b['text'];
  $a = strpos($text, '{'); $z = strrpos($text, '}');
  $data = $a !== false && $z !== false ? json_decode(substr($text, $a, $z - $a + 1), true) : null;
  if (!is_array($data) || !isset($data['cards'])) return ['cards' => [], 'error' => 'The cards could not be read clearly. Try a sharper photo, closer, with fewer cards.'];
  $clean = [];
  foreach (array_slice((array) $data['cards'], 0, 80) as $c) {
    $c = (array) $c; $t = fn($k, $n = 200) => mb_substr(trim((string) ($c[$k] ?? '')), 0, $n);
    $ph = array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim(preg_replace('/[^\d+\-() ]/', '', (string) $x)), 0, 30), (array) ($c['phones'] ?? [])), fn($x) => strlen(preg_replace('/\D/', '', $x)) >= 6)));
    $em = array_values(array_unique(array_filter(array_map(fn($x) => strtolower(trim((string) $x)), (array) ($c['emails'] ?? [])), fn($x) => (bool) filter_var($x, FILTER_VALIDATE_EMAIL))));
    $row = ['name' => $t('name', 120), 'designation' => $t('designation', 120), 'company' => $t('company'), 'phone' => $ph[0] ?? '', 'phone2' => implode(', ', array_slice($ph, 1, 3)),
      'email' => $em[0] ?? '', 'email2' => implode(', ', array_slice($em, 1, 3)), 'website' => $t('website', 160), 'city' => $t('city', 80), 'address' => $t('address', 400)];
    if ($row['name'] === '' && $row['company'] === '' && $row['phone'] === '' && $row['email'] === '') continue;
    $clean[] = $row;
  }
  return ['cards' => $clean, 'error' => null];
}

// Save one card. Same phone or email already in contacts → the existing contact is updated, not duplicated.
function card_save($row, $uid, $uname, $tag, $scanId = null, $source = 'card') {
  $ph = card_digits($row['phone'] ?? ''); $em = strtolower(trim((string) ($row['email'] ?? '')));
  $ex = null;
  if ($ph && strlen($ph) === 10) $ex = one('SELECT * FROM ' . T('cards') . ' WHERE phone_key = ?', [$ph]);
  if (!$ex && $em) $ex = one('SELECT * FROM ' . T('cards') . ' WHERE LOWER(email) = ?', [$em]);
  if ($ex) {
    $upd = [];
    foreach (['name', 'designation', 'company', 'phone', 'phone2', 'email', 'email2', 'website', 'city', 'address'] as $k) if (trim((string) ($ex[$k] ?? '')) === '' && trim((string) ($row[$k] ?? '')) !== '') $upd[$k] = $row[$k];
    $upd['note'] = trim(($ex['note'] ?? '') . "\nAlso met by $uname" . ($tag ? " at $tag" : '') . ' on ' . date('d M Y'));
    $upd['updated_at'] = now(); upd('cards', $ex['id'], $upd);
    return ['id' => (int) $ex['id'], 'dup' => true, 'by' => (int) $ex['user_id']];
  }
  $id = ins('cards', ['name' => $row['name'] ?? '', 'designation' => $row['designation'] ?? '', 'company' => $row['company'] ?? '', 'phone' => $row['phone'] ?? '', 'phone2' => $row['phone2'] ?? '',
    'phone_key' => $ph && strlen($ph) === 10 ? $ph : null, 'email' => $em, 'email2' => $row['email2'] ?? '', 'website' => $row['website'] ?? '', 'city' => $row['city'] ?? '', 'address' => $row['address'] ?? '',
    'tag' => $tag, 'note' => $row['note'] ?? null, 'source' => $source, 'scan_id' => $scanId, 'user_id' => $uid, 'created_at' => now(), 'updated_at' => now()]);
  return ['id' => $id, 'dup' => false];
}

// Is this person / company already a client or in expo data? (shown as a hint)
function card_known($row) {
  $ph = card_digits($row['phone'] ?? ''); $em = strtolower(trim((string) ($row['email'] ?? ''))); $um = users_map();
  if ($ph && strlen($ph) === 10) {
    foreach (all('SELECT company, owner_id, phone FROM ' . T('clients') . ' WHERE phone LIKE ?', ['%' . substr($ph, -4) . '%']) as $c) if (card_digits($c['phone']) === $ph) return 'Already a client of ' . ($um[$c['owner_id']] ?? '—') . ': ' . $c['company'];
    foreach (all('SELECT l.phone, e.name AS expo FROM ' . T('leads') . ' l JOIN ' . T('expos') . ' e ON e.id = l.expo_id WHERE l.phone LIKE ?', ['%' . substr($ph, -4) . '%']) as $l) if (card_digits($l['phone']) === $ph) return 'Also in expo data: ' . $l['expo'];
  }
  if ($em && ($c = one('SELECT company, owner_id FROM ' . T('clients') . ' WHERE LOWER(email) = ?', [$em]))) return 'Already a client of ' . ($um[$c['owner_id']] ?? '—') . ': ' . $c['company'];
  if ($em && ($l = one('SELECT e.name AS expo FROM ' . T('leads') . ' l JOIN ' . T('expos') . ' e ON e.id = l.expo_id WHERE LOWER(l.email) = ?', [$em]))) return 'Also in expo data: ' . $l['expo'];
  return null;
}

// ---------- one directory of every contact ----------
// Each row: source, tag (where it came from), by (who pooled it in), name, designation, company, phone, email, city, link
function directory_rows($in, $admin, $uid) {
  $um = users_map(); $q = trim((string) ($in['q'] ?? '')); $src = $in['source'] ?? ''; $who = (int) ($in['user_id'] ?? 0); $tagf = trim((string) ($in['tag'] ?? ''));
  if (!$admin) $who = $uid;   // employees see what they pooled in
  $rows = [];
  $like = fn($cols) => $q === '' ? ['1=1', []] : ['(' . implode(' OR ', array_map(fn($c) => "$c LIKE ?", $cols)) . ')', array_fill(0, count($cols), "%$q%")];
  if (!$src || $src === 'card') {
    [$w, $p] = $like(['name', 'company', 'phone', 'email', 'city', 'tag']);
    if ($who) { $w .= ' AND user_id = ?'; $p[] = $who; } if ($tagf !== '') { $w .= ' AND tag = ?'; $p[] = $tagf; }
    foreach (all('SELECT * FROM ' . T('cards') . " WHERE $w ORDER BY id DESC LIMIT 3000", $p) as $r)
      $rows[] = ['source' => $r['source'] === 'manual' ? 'Added by hand' : 'Business card', 'kind' => 'card', 'id' => (int) $r['id'], 'tag' => $r['tag'], 'by' => $um[$r['user_id']] ?? '—', 'user_id' => (int) $r['user_id'],
        'name' => $r['name'], 'designation' => $r['designation'], 'company' => $r['company'], 'phone' => trim($r['phone'] . ($r['phone2'] ? ', ' . $r['phone2'] : ''), ', '), 'email' => trim($r['email'] . ($r['email2'] ? ', ' . $r['email2'] : ''), ', '),
        'city' => $r['city'], 'website' => $r['website'], 'address' => $r['address'], 'note' => $r['note'], 'scan_id' => $r['scan_id'] ? (int) $r['scan_id'] : null, 'at' => $r['created_at'], 'raw' => $r];
  }
  if ($tagf !== '') return $rows;   // tags belong to card contacts
  if (!$src || $src === 'client') {
    [$w, $p] = $like(['c.company', 'c.person', 'c.phone', 'c.email', 'c.city']); if ($who) { $w .= ' AND c.owner_id = ?'; $p[] = $who; }
    foreach (all('SELECT c.* FROM ' . T('clients') . " c WHERE $w ORDER BY c.id DESC LIMIT 3000", $p) as $r)
      $rows[] = ['source' => 'Client', 'kind' => 'client', 'id' => (int) $r['id'], 'tag' => 'Client', 'by' => $um[$r['owner_id']] ?? '—', 'user_id' => (int) $r['owner_id'], 'name' => $r['person'], 'designation' => $r['designation'], 'company' => $r['company'],
        'phone' => $r['phone'], 'email' => $r['email'], 'city' => $r['city'], 'at' => $r['created_at'], 'link' => '#client-' . $r['id']];
    [$w, $p] = $like(['k.name', 'k.phone', 'k.email', 'c.company']); if ($who) { $w .= ' AND c.owner_id = ?'; $p[] = $who; }
    foreach (all('SELECT k.*, c.company, c.owner_id, c.city, c.created_at FROM ' . T('contacts') . ' k JOIN ' . T('clients') . " c ON c.id = k.client_id WHERE $w ORDER BY k.id DESC LIMIT 3000", $p) as $r)
      $rows[] = ['source' => 'Client contact', 'kind' => 'client', 'id' => (int) $r['client_id'], 'tag' => 'Client', 'by' => $um[$r['owner_id']] ?? '—', 'user_id' => (int) $r['owner_id'], 'name' => $r['name'], 'designation' => $r['designation'], 'company' => $r['company'],
        'phone' => $r['phone'], 'email' => $r['email'], 'city' => $r['city'], 'at' => $r['created_at'], 'link' => '#client-' . $r['client_id']];
  }
  if (!$src || $src === 'expo') {
    [$w, $p] = $like(['l.company', 'l.person', 'l.phone', 'l.email', 'e.name']); if ($who) { $w .= ' AND l.user_id = ?'; $p[] = $who; }
    foreach (all('SELECT l.*, e.name AS expo, e.city FROM ' . T('leads') . ' l JOIN ' . T('expos') . " e ON e.id = l.expo_id WHERE $w ORDER BY l.id DESC LIMIT 5000", $p) as $r)
      $rows[] = ['source' => 'Expo data · ' . $r['dept'], 'kind' => 'expo', 'id' => (int) $r['id'], 'tag' => $r['expo'], 'by' => $um[$r['user_id']] ?? '—', 'user_id' => (int) $r['user_id'], 'name' => $r['person'], 'designation' => '', 'company' => $r['company'],
        'phone' => $r['phone'], 'email' => $r['email'], 'city' => $r['city'], 'note' => $r['notes'], 'at' => $r['created_at'], 'link' => '#lexpo-' . $r['expo_id']];
  }
  if (!$src || $src === 'referral') {
    [$w, $p] = $like(['name', 'company', 'phone']); if ($who) { $w .= ' AND (assigned_to = ? OR referred_by = ?)'; $p[] = $who; $p[] = $who; }
    foreach (all('SELECT * FROM ' . T('referrals') . " WHERE $w ORDER BY id DESC LIMIT 2000", $p) as $r)
      $rows[] = ['source' => 'Referral lead', 'kind' => 'referral', 'id' => (int) $r['id'], 'tag' => 'Referral · ' . ($um[$r['referred_by']] ?? ''), 'by' => $um[$r['assigned_to']] ?? '—', 'user_id' => (int) $r['assigned_to'], 'name' => $r['name'], 'designation' => '', 'company' => $r['company'],
        'phone' => $r['phone'], 'email' => '', 'city' => '', 'note' => $r['note'], 'at' => $r['created_at'], 'link' => '#refs'];
  }
  usort($rows, fn($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
  return $rows;
}
