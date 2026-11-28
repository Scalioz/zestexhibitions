<?php
// Possible duplicate expenses: same order + same amount, any date.
// Looks at order expenses, vendor payments and expense claims still waiting for approval.
if (!defined('ZEST_API')) exit;

// $pid = order, $amts = amounts to match (e.g. with and without GST), $skipClaim / $skipExp = the entry being checked itself
function exp_dups($pid, array $amts, $skipClaim = 0, $skipExp = 0) {
  $pid = (int) $pid; if (!$pid) return [];
  $amts = array_values(array_unique(array_filter(array_map(fn($a) => round((float) $a, 2), $amts), fn($a) => $a > 0)));
  if (!$amts) return [];
  $hit = function ($v) use ($amts) { foreach ($amts as $a) if (abs($a - (float) $v) < 0.005) return true; return false; };
  $um = []; foreach (all('SELECT id, name FROM ' . T('users')) as $u) $um[$u['id']] = $u['name'];
  $out = [];

  // order expenses (from the order page, accounts entries, approved claims, partner advances) — skip ones whose voucher was reversed
  foreach (all('SELECT x.*, (SELECT COUNT(*) FROM ' . T('journal') . " j WHERE j.ref_type = 'pexp' AND j.ref_id = x.id) AS nj,
      (SELECT COUNT(*) FROM " . T('journal') . " j WHERE j.ref_type = 'pexp' AND j.ref_id = x.id AND j.reversed_by IS NULL) AS lj,
      (SELECT j.created_by FROM " . T('journal') . " j WHERE j.ref_type = 'pexp' AND j.ref_id = x.id ORDER BY j.id DESC LIMIT 1) AS jby
      FROM " . T('expenses') . ' x WHERE x.project_id = ? ORDER BY x.edate, x.id', [$pid]) as $x) {
    if ((int) $x['id'] === (int) $skipExp || !$hit($x['amount'])) continue;
    if ((int) $x['nj'] > 0 && (int) $x['lj'] === 0) continue;
    $cid = preg_match('/claim #(\d+)\)$/', (string) $x['description'], $m) ? (int) $m[1] : 0;
    if ($cid && $cid === (int) $skipClaim) continue;
    $by = $x['created_by'] ?? null; if (!$by) $by = $x['jby'];
    if (!$by && $cid) $by = val('SELECT user_id FROM ' . T('claims') . ' WHERE id = ?', [$cid]);
    $desc = $cid ? preg_replace('/ \([^()]*claim #\d+\)$/', '', (string) $x['description']) : (string) $x['description'];
    $out[] = ['date' => $x['edate'], 'amount' => (float) $x['amount'], 'by' => $by ? ($um[$by] ?? '—') : null,
      'what' => $desc ?: $x['category'], 'where' => $cid ? 'approved expense claim' : 'order expense'];
  }
  // vendor payments on the same order
  foreach (all('SELECT vp.*, v.name AS vendor FROM ' . T('vendor_payments') . ' vp LEFT JOIN ' . T('vendors') . ' v ON v.id = vp.vendor_id WHERE vp.project_id = ? ORDER BY vp.pdate, vp.id', [$pid]) as $x) {
    if (!$hit($x['amount'])) continue;
    $by = val('SELECT created_by FROM ' . T('journal') . " WHERE ref_type = 'vpay' AND ref_id = ? ORDER BY id DESC LIMIT 1", [$x['id']]);
    $out[] = ['date' => $x['pdate'], 'amount' => (float) $x['amount'], 'by' => $by ? ($um[$by] ?? '—') : null, 'what' => 'Paid to ' . ($x['vendor'] ?: 'vendor'), 'where' => 'vendor payment'];
  }
  // expense claims not yet decided
  foreach (all('SELECT * FROM ' . T('claims') . " WHERE project_id = ? AND status IN ('submitted','on_hold') AND id <> ? ORDER BY edate, id", [$pid, (int) $skipClaim]) as $c) {
    if (!$hit($c['total']) && !$hit($c['grand'])) continue;
    $out[] = ['date' => $c['edate'], 'amount' => (float) $c['grand'], 'by' => $um[$c['user_id']] ?? '—', 'what' => $c['particular'], 'where' => 'expense claim waiting for approval'];
  }
  return $out;
}
// stop and ask first: the screen shows the matches, and the person can save again with dup_ok
function dup_guard($pid, array $amts, $skipClaim = 0) {
  global $in; if (!empty($in['dup_ok'])) return;
  $d = exp_dups($pid, $amts, $skipClaim); if ($d) out(['dup_exp' => $d]);
}
