<?php
// Printable proforma / tax invoice with Zest letterhead.
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/acc.php';
$me = current_user();
if (!$me) { header('Location: login.php'); exit; }
$isAcc = $me['role'] === 'admin' || has_mod($me, 'accounts');
$i = one('SELECT i.*, c.company, c.location, c.city, c.gst_no, c.person, c.phone, c.email, p.expo, p.owner_id FROM ' . T('invoices') . ' i JOIN ' . T('clients') . ' c ON c.id = i.client_id LEFT JOIN ' . T('projects') . ' p ON p.id = i.project_id WHERE i.id = ?', [(int) ($_GET['id'] ?? 0)]);
if (!$i) { http_response_code(404); exit('Invoice not found.'); }
if (!$isAcc && (int) $i['requested_by'] !== (int) $me['id'] && (int) $i['owner_id'] !== (int) $me['id']) { http_response_code(403); exit('This invoice is not yours.'); }
$ok = $i['status'] === 'issued';
$fname = preg_replace('/[^A-Za-z0-9]+/', '-', ($i['inv_no'] ?: 'Draft') . ' ' . $i['company']) . '.pdf';
$L = json_decode($i['lines_json'], true) ?: []; $co = setting('company', []);
$h = fn($s) => htmlspecialchars((string) ($s ?? ''), ENT_QUOTES);
$m = fn($n) => inr2($n);
$title = $i['kind'] === 'tax' ? 'TAX INVOICE' : 'PROFORMA INVOICE';
$half = round($i['gst'] / 2, 2);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h(($i['inv_no'] ?: 'Draft') . ' · ' . $i['company']) ?></title><link rel="icon" href="assets/mark.svg">
<style>
@page{size:A4;margin:12mm}
body{font-family:Arial,Helvetica,sans-serif;color:#1b2340;font-size:11.5px;margin:0;background:#e9ebf0}
.page{background:#fff;max-width:820px;margin:20px auto;padding:28px 32px;box-shadow:0 2px 14px rgba(0,0,0,.08)}
.bar{max-width:820px;margin:16px auto 0;display:flex;gap:8px;justify-content:flex-end}
.bar button,.bar a{font:600 13px Arial;padding:8px 14px;border-radius:6px;border:1px solid #1b2a5c;background:#1b2a5c;color:#fff;cursor:pointer;text-decoration:none}.bar a{background:#fff;color:#1b2a5c}
.hdr{display:flex;align-items:center;justify-content:space-between;border-bottom:3px solid #1b2a5c;padding-bottom:10px;gap:16px}
.hdr img{height:56px}.addr{text-align:right;font-size:10.5px;line-height:1.55;color:#333}.addr b{font-size:14px;color:#1b2a5c}
h1{text-align:center;font-size:16px;letter-spacing:3px;margin:16px 0 12px;color:#1b2a5c}
.draft{color:#b4232c;font-size:12px;letter-spacing:2px}
.warnbar{font:600 13px Arial;color:#8a5300;background:#fff3d6;border:1px solid #f0d08a;padding:8px 12px;border-radius:6px}
.page{position:relative}.wm{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;font:bold 70px Arial;color:rgba(180,35,44,.10);transform:rotate(-30deg);letter-spacing:6px}
.meta{display:grid;grid-template-columns:1fr 1fr;gap:0;border:1px solid #9aa3b5;margin-bottom:12px}
.meta div{padding:8px 12px;line-height:1.6}.meta div+div{border-left:1px solid #9aa3b5}
table{width:100%;border-collapse:collapse}th{background:#1b2a5c;color:#fff;padding:7px 6px;font-size:11px;border:1px solid #1b2a5c}
td{border:1px solid #9aa3b5;padding:7px 6px;vertical-align:top}td.n{text-align:right;white-space:nowrap}td.c{text-align:center}
tr.t td{font-weight:bold;background:#eef1f7}tr.g td{font-weight:bold;background:#fff7c2;font-size:13px}
.words{margin:10px 0;font-weight:bold}.small{color:#555;font-size:10.5px;line-height:1.6}
.foot{display:flex;justify-content:space-between;gap:20px;margin-top:30px}.sig{text-align:center;min-width:220px}.sig .ln{border-top:1px solid #333;margin-top:46px;padding-top:5px}
@media print{body{background:#fff}.page{box-shadow:none;margin:0;padding:0;max-width:none}.bar{display:none}}
.bank{margin-top:12px;font-size:13.5px;line-height:1.6;border:1px solid #c9d3df;padding:10px 12px;border-radius:4px}
.terms{margin-top:12px;font-size:13.5px;line-height:1.6;font-weight:700}
</style></head><body>
<div class="bar"><a href="index.php#invd-<?= (int) $i['id'] ?>">← Back</a><?php if ($ok): ?><button id="dl">⬇ Download PDF</button><button id="sh" hidden>Share (WhatsApp / email)</button><a href="#" onclick="window.print();return false">Print</a><?php else: ?><span class="warnbar">Not approved yet — download is available after admin approves it.</span><?php endif; ?></div>
<div class="page">
<div class="hdr"><img src="assets/logo.png" alt="<?= $h($co['name'] ?? 'Zest Exhibitions') ?>">
<div class="addr"><b><?= $h(strtoupper($co['name'] ?? 'ZEST EXHIBITIONS')) ?></b><br><?= nl2br($h($co['address'] ?? '')) ?><br>Tel: <?= $h($co['phone'] ?? '') ?><?= !empty($co['gstin']) ? '<br>GSTIN: ' . $h($co['gstin']) : '' ?><?= !empty($co['pan']) ? ' · PAN: ' . $h($co['pan']) : '' ?></div></div>
<?php if (!$ok): ?><div class="wm"><?= $i['status'] === 'cancelled' ? 'CANCELLED' : 'NOT APPROVED' ?></div><?php endif; ?>
<h1><?= $title ?><?= $i['status'] === 'cancelled' ? ' <span class="draft">(CANCELLED)</span>' : (!$ok ? ' <span class="draft">(DRAFT — NOT APPROVED)</span>' : '') ?></h1>
<div class="meta">
 <div><b>Bill to</b><br><?= $h($i['company']) ?><br><?= nl2br($h($i['location'] ?: $i['city'])) ?><?= $i['gst_no'] ? '<br>GSTIN: ' . $h($i['gst_no']) : '' ?><?= $i['person'] ? '<br>Attn: ' . $h($i['person']) : '' ?></div>
 <div><b><?= $i['kind'] === 'tax' ? 'Invoice' : 'Proforma' ?> no.:</b> <?= $h($i['inv_no'] ?: '—') ?><br><b>Date:</b> <?= date('d-M-Y', strtotime($i['inv_date'])) ?><br><b>Place of supply:</b> <?= $h($i['place_state']) ?><?= $i['expo'] ? '<br><b>Event:</b> ' . $h($i['expo']) : '' ?></div>
</div>
<table><tr><th style="width:5%">Sl</th><th>Description</th><th style="width:10%">SAC</th><th style="width:7%">Qty</th><th style="width:14%">Rate (₹)</th><th style="width:15%">Amount (₹)</th></tr>
<?php foreach ($L as $k => $l): ?><tr><td class="c"><?= $k + 1 ?></td><td><?= $h($l['desc']) ?><?= empty($l['tax']) ? ' <span class="small">(no GST)</span>' : '' ?></td><td class="c"><?= $h($l['sac']) ?></td><td class="c"><?= rtrim(rtrim(number_format($l['qty'], 2, '.', ''), '0'), '.') ?></td><td class="n"><?= $m($l['rate']) ?></td><td class="n"><?= $m($l['amount']) ?></td></tr><?php endforeach; ?>
<?php $disc = (float) ($i['discount'] ?? 0); $ro = (float) ($i['round_off'] ?? 0); $sgn = fn($v) => ($v < 0 ? '− ' : '+ ') . $m(abs($v)); ?>
<?php if ($disc > 0): ?><tr class="t"><td></td><td colspan="4">Total</td><td class="n"><?= $m($i['taxable'] + $disc) ?></td></tr><tr><td></td><td colspan="4">Less: discount</td><td class="n">− <?= $m($disc) ?></td></tr><?php endif; ?>
<tr class="t"><td></td><td colspan="4">Total before tax</td><td class="n"><?= $m($i['taxable']) ?></td></tr>
<?php if ($i['gst_mode'] === 'igst'): ?><tr><td></td><td colspan="4">IGST @ <?= rtrim(rtrim($i['gst_rate'], '0'), '.') ?>%</td><td class="n"><?= $m($i['gst']) ?></td></tr>
<?php else: ?><tr><td></td><td colspan="4">CGST @ <?= rtrim(rtrim($i['gst_rate'] / 2, '0'), '.') ?>%</td><td class="n"><?= $m($half) ?></td></tr><tr><td></td><td colspan="4">SGST @ <?= rtrim(rtrim($i['gst_rate'] / 2, '0'), '.') ?>%</td><td class="n"><?= $m($i['gst'] - $half) ?></td></tr><?php endif; ?>
<?php if ($ro != 0): ?><tr><td></td><td colspan="4">Round off</td><td class="n"><?= $sgn($ro) ?></td></tr><?php endif; ?>
<tr class="g"><td></td><td colspan="4">Grand total</td><td class="n">₹<?= $m($i['total']) ?></td></tr></table>
<div class="words">Amount in words: Rupees <?= amount_words($i['total']) ?> Only</div>
<?php if (!empty($co['bank_name']) || !empty($co['bank_acno'])): ?><div class="bank"><b>Bank details</b><br>Account name: <b><?= $h(($co['bank_benef'] ?? '') ?: 'Zest Exhibitions') ?></b><br>Bank: <?= $h($co['bank_name']) ?><?= !empty($co['bank_branch']) ? ', ' . $h($co['bank_branch']) : '' ?><br>A/c no.: <b><?= $h($co['bank_acno']) ?></b> &nbsp;·&nbsp; IFSC: <b><?= $h($co['bank_ifsc']) ?></b><?= !empty($co['bank_swift']) ? ' &nbsp;·&nbsp; SWIFT: ' . $h($co['bank_swift']) : '' ?></div><?php endif; ?>
<?php if ($i['notes'] || !empty($co['terms'])): ?><div class="terms"><b>Payment terms</b><br><?= nl2br($h(trim(($i['notes'] ?? '') . "\n" . ($co['terms'] ?? '')))) ?></div><?php endif; ?>
<div class="foot"><div class="small">This is a computer-generated <?= $i['kind'] === 'tax' ? 'invoice' : 'proforma invoice' ?>.</div><div class="sig">For <?= $h($co['name'] ?? 'Zest Exhibitions') ?><div class="ln">Authorised Signatory</div></div></div>
</div>
<?php if ($ok): ?><script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
const FN = <?= json_encode($fname) ?>, PG = document.querySelector('.page');
const opt = { margin: [8, 8, 8, 8], filename: FN, image: { type: 'jpeg', quality: 0.96 }, html2canvas: { scale: 2, useCORS: true }, jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' } };
const prep = () => { PG.style.boxShadow = 'none'; PG.style.margin = '0'; };
const done = () => { PG.style.boxShadow = ''; PG.style.margin = ''; };
document.getElementById('dl').onclick = async () => {
  if (!window.html2pdf) { window.print(); return; }   // library not reachable: use the browser's Save as PDF
  prep(); try { await html2pdf().set(opt).from(PG).save(); } finally { done(); }
};
const sh = document.getElementById('sh');
if (window.html2pdf && navigator.canShare && navigator.canShare({ files: [new File([''], 'x.pdf', { type: 'application/pdf' })] })) {
  sh.hidden = false;
  sh.onclick = async () => { prep(); try { const b = await html2pdf().set(opt).from(PG).outputPdf('blob'); await navigator.share({ files: [new File([b], FN, { type: 'application/pdf' })], title: FN }); } catch (e) {} finally { done(); } };
}
</script><?php endif; ?>
</body></html>
