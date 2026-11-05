<?php
// Quote generator: fills a quotation from the order (expo, stall, production details) and works out the totals.
require_once __DIR__ . '/acc.php';   // fy_of, amount_words, inr2

const QUOTE_DEFAULTS = [
  'valid_days' => 7,
  'scope' => 'Turnkey Fabrication & Execution',
  'payment_terms' => ['70% Advance along with Work Order.', 'Balance 30% on the First Day of Exhibition.', 'Design work shall commence upon receipt of advance payment.', 'Any additional work beyond the approved design will be charged separately.'],
  'notes' => ['Quotation is valid for {valid} days.', 'Any modification in design after approval may attract additional charges.', 'Client shall provide artwork and logo files in high resolution.',
    'Any venue-specific charges are payable by the client unless otherwise mentioned.', 'This document serves as both a commercial quotation and a proforma invoice for advance payment.',
    'Any element incorporated in the design later will cost extra.', 'Any prints added on site will cost extra subject to availability.', 'Extra Furniture will be charged in excess subject to availability.', 'LS = Lumpsum'],
  'client_resp' => ['Branding soft copy (according to the size proposed by us) to be provided by the client.', 'All property belongs to Zest Exhibitions after the exhibition.'],
  'excludes' => ['Space rental', 'Electricity load', 'Poster Designing', 'Receptionist / Coffee Operator'],
  'items' => [
    ['desc' => 'Flooring Platform with Carpet and Lights', 'qty' => '', 'area' => '{area}'],
    ['desc' => 'Back Wall', 'qty' => '1', 'area' => 'As Per Design'],
    ['desc' => 'Reception Desk', 'qty' => '1', 'area' => 'As Per Design'],
    ['desc' => 'Product Display Podium', 'qty' => '', 'area' => 'As Per Design'],
    ['desc' => 'Fascia', 'qty' => '1', 'area' => 'As Per Design'],
    ['desc' => 'Branding and Graphics as per design', 'qty' => '', 'area' => '', 'head' => 1],
    ['desc' => 'Lit Acrylic Logo', 'qty' => '', 'area' => '', 'sub' => 1],
    ['desc' => 'Vinyl Cut', 'qty' => '{vinyl}', 'area' => '', 'sub' => 1],
    ['desc' => 'Backlit Poster', 'qty' => '{backlit}', 'area' => '', 'sub' => 1],
    ['desc' => '{furniture}', 'qty' => '', 'area' => ''],
    ['desc' => 'Paint work', 'qty' => '', 'area' => 'L.S'],
    ['desc' => 'Carpenter and Labour charges', 'qty' => '', 'area' => 'L.S'],
    ['desc' => 'Electricity, light, fixtures & fittings, etc.', 'qty' => '', 'area' => 'L.S'],
    ['desc' => 'Transportation, Installation and Dismantling', 'qty' => '', 'area' => 'L.S'],
  ],
];
function quote_defaults() { return array_merge(QUOTE_DEFAULTS, (array) setting('quote_defaults', [])); }

function q_date_range($a, $b) {   // "27 – 29 November 2026", "30 Nov – 2 Dec 2026"
  if (!$a) return '';
  $ta = strtotime($a); $tb = $b ? strtotime($b) : $ta;
  if (date('Y-m-d', $ta) === date('Y-m-d', $tb)) return date('j F Y', $ta);
  if (date('Y-m', $ta) === date('Y-m', $tb)) return date('j', $ta) . ' – ' . date('j F Y', $tb);
  if (date('Y', $ta) === date('Y', $tb)) return date('j M', $ta) . ' – ' . date('j M Y', $tb);
  return date('j M Y', $ta) . ' – ' . date('j M Y', $tb);
}
function q_dim($d) { return $d && ($d['v'] ?? null) !== null && $d['v'] !== '' ? rtrim(rtrim(number_format((float) $d['v'], 2, '.', ''), '0'), '.') . ' ' . ($d['u'] ?? 'ft') : ''; }

// A fresh quote for an order. $me = the person preparing it.
function quote_prefill($p, $c, $me) {
  $D = quote_defaults(); $ops = json_decode($p['ops_json'] ?? '', true) ?: [];
  $area = $p['area_sqm'] !== null && $p['area_sqm'] !== '' ? rtrim(rtrim(number_format((float) $p['area_sqm'], 2, '.', ''), '0'), '.') : '';
  $len = q_dim($ops['length'] ?? null); $wid = q_dim($ops['width'] ?? null);
  $sides = [1 => 'One Side Open', 2 => 'Two Sides Open', 3 => 'Three Sides Open', 4 => 'Island (Four Sides Open)'][(int) $p['sides_open']] ?? '';
  $items = [];
  foreach ($D['items'] as $it) {
    if ($it['desc'] === '{furniture}') { foreach ((array) ($ops['furniture'] ?? []) as $f) $items[] = ['desc' => $f['item'], 'qty' => (string) ($f['qty'] ?: ''), 'area' => '', 'amount' => '', 'sub' => 0, 'head' => 0]; continue; }
    $q = strtr((string) $it['qty'], ['{vinyl}' => ($ops['vinyl_total'] ?? 0) ?: '', '{backlit}' => ($ops['backlit_walls'] ?? 0) ?: '']);
    $items[] = ['desc' => $it['desc'], 'qty' => $q, 'area' => strtr((string) $it['area'], ['{area}' => $area ? "$area m²" : 'As Per Design']), 'amount' => '', 'sub' => (int) ($it['sub'] ?? 0), 'head' => (int) ($it['head'] ?? 0)];
  }
  $co = setting('company', []); $same = stripos(($c['location'] ?? '') . ' ' . ($c['city'] ?? '') . ' ' . ($p['location'] ?? ''), (string) ($co['state'] ?? 'Delhi')) !== false;
  $person = trim((string) $c['person']);
  return [
    'date' => today(), 'valid_days' => (int) $D['valid_days'],
    'to_company' => $c['company'], 'to_address' => trim((string) ($c['location'] ?: $c['city'])), 'to_gstin' => (string) $c['gst_no'],
    'salutation' => 'Mr.', 'attn' => $person,
    'expo' => $p['expo'], 'expo_dates' => q_date_range($p['start_date'], $p['end_date']), 'venue' => (string) $p['location'],
    'details' => [
      ['l' => 'Stall No', 'v' => (string) $p['stall_no']], ['l' => 'Stall Area', 'v' => $area ? "$area Sq. mts." : ''],
      ['l' => 'Dimensions', 'v' => $len && $wid ? "$len x $wid" : ''], ['l' => 'Stall Type', 'v' => $sides],
      ['l' => 'Wall Height', 'v' => q_dim($ops['wall_h'] ?? null)], ['l' => 'Stall Height', 'v' => ''],
      ['l' => 'Stall Status', 'v' => ['bare' => 'Raw Space', 'bare space' => 'Raw Space', 'shell' => 'Shell Scheme', 'shell scheme' => 'Shell Scheme'][strtolower((string) $p['space_type'])] ?? (string) $p['space_type']], ['l' => 'Scope of Work', 'v' => $D['scope']],
    ],
    'items' => $items, 'show_rates' => 0,
    'total' => (float) ($p['agreed'] ?: $p['offered'] ?: 0), 'discount' => 0, 'gst_rate' => (float) ($p['gst_rate'] ?: 18), 'gst_mode' => $same ? 'cgst_sgst' : 'igst',
    'payment_terms' => $D['payment_terms'], 'notes' => $D['notes'], 'client_resp' => $D['client_resp'], 'excludes' => $D['excludes'],
    'prepared_by' => $me['name'], 'prepared_phone' => (string) ($me['phone'] ?? ''),
  ];
}

// Clean what the form sent and work out the money. Returns the stored document.
function quote_clean($in) {
  $t = fn($v, $n = 300) => mb_substr(trim((string) ($v ?? '')), 0, $n);
  $lines = fn($v) => array_values(array_filter(array_map(fn($x) => $t($x, 400), is_array($v) ? $v : preg_split('~\r?\n~', (string) $v)), 'strlen'));
  $num = function ($v) { $v = str_replace([',', ' ', '₹'], '', (string) ($v ?? '')); if ($v === '') return 0.0; if (!is_numeric($v)) fail('Please enter valid amounts.'); return round((float) $v, 2); };
  $d = [
    'date' => preg_match('~^\d{4}-\d{2}-\d{2}$~', $in['date'] ?? '') ? $in['date'] : today(), 'valid_days' => max(1, min(365, (int) ($in['valid_days'] ?? 7))),
    'to_company' => $t($in['to_company'] ?? '', 200), 'to_address' => $t($in['to_address'] ?? '', 500), 'to_gstin' => $t($in['to_gstin'] ?? '', 30),
    'salutation' => in_array($in['salutation'] ?? '', ['Mr.', 'Ms.', 'Mrs.', 'Dr.', 'Sir / Madam'], true) ? $in['salutation'] : 'Mr.', 'attn' => $t($in['attn'] ?? '', 120),
    'expo' => $t($in['expo'] ?? '', 200), 'expo_dates' => $t($in['expo_dates'] ?? '', 80), 'venue' => $t($in['venue'] ?? '', 200),
    'details' => [], 'items' => [], 'show_rates' => !empty($in['show_rates']) ? 1 : 0,
    'discount' => $num($in['discount'] ?? 0), 'gst_rate' => max(0, min(28, (float) ($in['gst_rate'] ?? 18))), 'gst_mode' => ($in['gst_mode'] ?? '') === 'cgst_sgst' ? 'cgst_sgst' : 'igst',
    'payment_terms' => $lines($in['payment_terms'] ?? []), 'notes' => $lines($in['notes'] ?? []), 'client_resp' => $lines($in['client_resp'] ?? []), 'excludes' => $lines($in['excludes'] ?? []),
    'prepared_by' => $t($in['prepared_by'] ?? '', 120), 'prepared_phone' => $t($in['prepared_phone'] ?? '', 40),
  ];
  if (!$d['to_company']) fail('Enter the customer (company) name.');
  foreach (array_slice((array) ($in['details'] ?? []), 0, 20) as $x) { $x = (array) $x; if ($t($x['l'] ?? '') !== '') $d['details'][] = ['l' => $t($x['l'], 60), 'v' => $t($x['v'] ?? '', 120)]; }
  foreach (array_slice((array) ($in['items'] ?? []), 0, 80) as $x) { $x = (array) $x; $desc = $t($x['desc'] ?? '', 300); if ($desc === '') continue;
    $d['items'][] = ['desc' => $desc, 'qty' => $t($x['qty'] ?? '', 20), 'area' => $t($x['area'] ?? '', 40), 'amount' => ($x['amount'] ?? '') === '' ? '' : $num($x['amount']), 'sub' => !empty($x['sub']) ? 1 : 0, 'head' => !empty($x['head']) ? 1 : 0]; }
  if (!$d['items']) fail('Add at least one item.');
  $sum = array_sum(array_map(fn($x) => $x['amount'] === '' ? 0 : $x['amount'], $d['items']));
  $d['total'] = $d['show_rates'] ? round($sum, 2) : $num($in['total'] ?? 0);
  if ($d['total'] <= 0) fail($d['show_rates'] ? 'Enter the amount against the items.' : 'Enter the total amount.');
  if ($d['discount'] < 0 || $d['discount'] >= $d['total']) fail('Discount looks wrong.');
  $d['taxable'] = round($d['total'] - $d['discount'], 2);
  $d['gst'] = round($d['taxable'] * $d['gst_rate'] / 100, 2);
  $d['grand'] = round($d['taxable'] + $d['gst'], 2);
  return $d;
}
function quote_no_next($date) {   // QT/26-27/001 — given when admin approves
  $pre = 'QT/' . fy_of($date) . '/'; $mysql = (cfg()['driver'] ?? 'mysql') !== 'sqlite';
  $last = 0; foreach (all('SELECT quote_no FROM ' . T('approvals') . ' WHERE quote_no LIKE ?' . ($mysql && db()->inTransaction() ? ' FOR UPDATE' : ''), [$pre . '%']) as $r) $last = max($last, (int) substr($r['quote_no'], strlen($pre)));
  return $pre . str_pad($last + 1, 3, '0', STR_PAD_LEFT);
}
