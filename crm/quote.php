<?php
// Printable / downloadable quotation (generated in the CRM), in Zest's quotation format.
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/acc.php';
$me = current_user();
if (!$me) { header('Location: login.php'); exit; }
$mods = setting('dept_modules', []); $mod = $mods[$me['department']] ?? '';
$a = one('SELECT a.*, p.owner_id, p.id AS pid FROM ' . T('approvals') . ' a JOIN ' . T('projects') . ' p ON p.id = a.project_id WHERE a.id = ?', [(int) ($_GET['id'] ?? 0)]);
if (!$a || !$a['doc']) { http_response_code(404); exit('Quote not found.'); }
if (!($me['role'] === 'admin' || has_mod($me, 'accounts') || (int) $a['owner_id'] === (int) $me['id'])) { http_response_code(403); exit('This quote is not yours.'); }
$d = json_decode($a['doc'], true); $co = setting('company', []);
$ok = $a['status'] === 'approved';
$h = fn($s) => htmlspecialchars((string) ($s ?? ''), ENT_QUOTES);
$m = fn($n) => inr2($n);
$valid = (int) ($d['valid_days'] ?? 7);
$fname = preg_replace('~[^A-Za-z0-9]+~', '-', 'Quotation ' . ($a['quote_no'] ?: 'V' . $a['version']) . ' ' . $d['to_company']) . '.pdf';
$half = round($d['gst'] / 2, 2); $rate = rtrim(rtrim(number_format($d['gst_rate'], 2, '.', ''), '0'), '.');
$list = fn($xs) => $xs ? '<ul>' . implode('', array_map(fn($x) => '<li>' . $h(str_replace('{valid}', $valid, $x)) . '</li>', $xs)) . '</ul>' : '';
$sn = 0; $sub = 0;
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h(($a['quote_no'] ?: 'Draft quote V' . $a['version']) . ' · ' . $d['to_company']) ?></title><link rel="icon" href="assets/mark.svg">
<style>
@page{size:A4;margin:14mm}
body{font-family:Arial,Helvetica,sans-serif;color:#1d1d1f;font-size:12px;margin:0;background:#e9ebf0;line-height:1.45}
.page{background:#fff;max-width:800px;margin:20px auto;padding:34px 44px;box-shadow:0 2px 14px rgba(0,0,0,.08);position:relative}
.bar{max-width:800px;margin:16px auto 0;display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;padding:0 12px}
.bar button,.bar a{font:600 13px Arial;padding:8px 14px;border-radius:6px;border:1px solid #1b2a5c;background:#1b2a5c;color:#fff;cursor:pointer;text-decoration:none}.bar a{background:#fff;color:#1b2a5c}
.warnbar{font:600 13px Arial;color:#8a5300;background:#fff3d6;border:1px solid #f0d08a;padding:8px 12px;border-radius:6px}
.logo{text-align:center}.logo img{height:74px}
h1{text-align:center;color:#1f3a6e;font-size:24px;margin:14px 0 18px;letter-spacing:.5px}
h2{color:#2a4a85;font-size:15px;margin:22px 0 6px}
.ref{display:flex;justify-content:space-between;gap:12px}
ul{margin:4px 0 0;padding-left:20px}li{margin:2px 0}
table{width:100%;border-collapse:collapse;margin-top:14px}
th{background:#0b2160;color:#fff;font:bold 12px 'Times New Roman',serif;padding:4px 8px;text-transform:uppercase}
td{padding:2px 8px;vertical-align:top}td.sn{width:34px;font-family:'Times New Roman',serif}td.c{text-align:center;white-space:nowrap}td.n{text-align:right;white-space:nowrap}
tr.subrow td.d{padding-left:34px}
.tot td{padding:4px 8px}.tot .bar2 td{background:#0b2160;color:#fff;font-weight:bold;font-size:13px}
.words{margin:16px 0 0}.bank td{padding:0 8px 0 0}.bank td:first-child{width:150px}
.sig{display:flex;justify-content:space-between;align-items:flex-end;margin-top:30px;gap:20px}.sig .ln{border-top:1px solid #333;margin-top:46px;padding-top:4px;text-align:center;min-width:200px}
.foot{text-align:center;color:#1f3a6e;font-weight:bold;margin-top:26px;border-top:1px solid #d5dbe8;padding-top:10px}
.wm{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;font:bold 64px Arial;color:rgba(180,35,44,.10);transform:rotate(-30deg);letter-spacing:6px}
.keep{break-inside:avoid;page-break-inside:avoid}
@media print{body{background:#fff}.page{box-shadow:none;margin:0;padding:0;max-width:none}.bar{display:none}}
@media (max-width:640px){.page{padding:22px 18px;margin:10px}}
</style></head><body>
<div class="bar"><a href="index.php#project-<?= (int) $a['pid'] ?>-order">← Back</a><?php if ($ok): ?><button id="dl">⬇ Download PDF</button><button id="sh" hidden>Share (WhatsApp / email)</button><a href="#" onclick="window.print();return false">Print</a><?php else: ?><span class="warnbar"><?= $a['status'] === 'rejected' ? 'Rejected by admin — make a new version.' : 'Not approved yet — download is available after admin approves it.' ?></span><?php endif; ?></div>
<div class="page">
<?php if (!$ok): ?><div class="wm">NOT APPROVED</div><?php endif; ?>
<div class="logo"><img src="assets/logo.png" alt="<?= $h($co['name'] ?? 'Zest Exhibitions') ?>"></div>
<h1>QUOTATION</h1>
<div class="ref"><div>Date: <?= date('d/m/Y', strtotime($d['date'])) ?></div><div><?= $a['quote_no'] ? 'Ref: ' . $h($a['quote_no']) : 'Draft V' . (int) $a['version'] ?></div></div>
<p>To,</p>
<p><b>M/S <?= $h(mb_strtoupper($d['to_company'])) ?></b><?= $d['to_address'] ? '<br>' . nl2br($h($d['to_address'])) : '' ?><?= $d['to_gstin'] ? '<br>GSTIN: ' . $h($d['to_gstin']) : '' ?></p>
<p>Dear <?= $h($d['salutation'] === 'Sir / Madam' ? 'Sir / Madam' : trim($d['salutation'] . ' ' . $d['attn'])) ?>,</p>
<p>This refers to our discussion regarding the design submission, fabrication and execution of your stall in <b><?= $h($d['expo']) ?></b><?= $d['expo_dates'] ? ', scheduled to be held from <b>' . $h($d['expo_dates']) . '</b>' : '' ?><?= $d['venue'] ? ', at the <b>' . $h($d['venue']) . '</b>' : '' ?>.</p>
<?php $det = array_filter($d['details'], fn($x) => trim($x['v']) !== ''); if ($det): ?><p style="margin-bottom:0"><b>General Details:</b></p><ul><?php foreach ($det as $x): ?><li><?= $h($x['l']) ?>: <?= $h($x['v']) ?></li><?php endforeach; ?></ul><?php endif; ?>
<table><tr><th style="width:34px">S.N.</th><th>Particulars</th><th style="width:60px">Qty</th><th style="width:110px">Area</th><?php if ($d['show_rates']): ?><th style="width:110px">Amount (₹)</th><?php endif; ?></tr>
<?php foreach ($d['items'] as $it): if ($it['sub']) { $sub++; $lab = chr(96 + $sub) . '.'; } else { $sn++; $sub = 0; $lab = $sn . '.'; } ?>
<tr class="<?= $it['sub'] ? 'subrow' : '' ?>"><td class="sn"><?= $it['sub'] ? '' : $lab ?></td><td class="d"><?= $it['sub'] ? $lab . ' ' : '' ?><?= $h($it['desc']) ?></td><td class="c"><?= $h($it['qty']) ?></td><td class="c"><?= $h($it['area']) ?></td><?php if ($d['show_rates']): ?><td class="n"><?= $it['amount'] === '' ? '' : $m($it['amount']) ?></td><?php endif; ?></tr>
<?php endforeach; ?></table>
<table class="tot keep">
<tr class="bar2"><td style="width:60%"></td><td class="n">Total</td><td class="n"><?= $m($d['total']) ?></td></tr>
<?php if ($d['discount'] > 0): ?><tr><td></td><td class="n">Discount</td><td class="n"><?= $m($d['discount']) ?></td></tr><?php endif; ?>
<?php if ($d['gst_mode'] === 'igst'): ?><tr><td></td><td class="n">IGST @ <?= $rate ?>%</td><td class="n"><?= $m($d['gst']) ?></td></tr>
<?php else: ?><tr><td></td><td class="n">CGST @ <?= rtrim(rtrim(number_format($d['gst_rate'] / 2, 2, '.', ''), '0'), '.') ?>%</td><td class="n"><?= $m($half) ?></td></tr><tr><td></td><td class="n">SGST @ <?= rtrim(rtrim(number_format($d['gst_rate'] / 2, 2, '.', ''), '0'), '.') ?>%</td><td class="n"><?= $m($d['gst'] - $half) ?></td></tr><?php endif; ?>
<tr class="bar2"><td></td><td class="n">Grand Total</td><td class="n"><?= $m($d['grand']) ?></td></tr></table>
<p class="words"><b>AMOUNT IN WORDS:</b> Indian Rupees <?= amount_words($d['grand']) ?> Only</p>
<?php if ($d['payment_terms']): ?><div class="keep"><h2>Payment Terms:</h2><?= $list($d['payment_terms']) ?></div><?php endif; ?>
<?php if (!empty($co['bank_acno'])): ?><div class="keep"><h2>Bank Details:</h2><table class="bank" style="margin-top:4px">
<?php foreach (['Beneficiary Name' => $co['bank_benef'] ?? $co['name'] ?? '', 'Bank Name' => $co['bank_name'] ?? '', 'Branch Address' => $co['bank_branch'] ?? '', 'A/c No.' => $co['bank_acno'] ?? '', 'IFS Code' => $co['bank_ifsc'] ?? '', 'SWIFT Code' => $co['bank_swift'] ?? '', 'MICR Code No.' => $co['bank_micr'] ?? '', 'GSTIN' => $co['gstin'] ?? '', 'PAN' => $co['pan'] ?? ''] as $k => $v) if (trim((string) $v) !== ''): ?><tr><td><?= $k ?></td><td><?= $h($v) ?></td></tr><?php endif; ?>
</table></div><?php endif; ?>
<?php if ($d['notes']): ?><div class="keep"><h2>Important Notes &amp; Terms:</h2><?= $list($d['notes']) ?></div><?php endif; ?>
<?php if ($d['client_resp']): ?><div class="keep"><h2>Client Responsibilities &amp; Ownership:</h2><?= $list($d['client_resp']) ?></div><?php endif; ?>
<?php if ($d['excludes']): ?><div class="keep"><h2>Quotation Does Not Include:</h2><?= $list($d['excludes']) ?></div><?php endif; ?>
<div class="sig keep"><div><?= $d['prepared_by'] ? 'Prepared by: <b>' . $h($d['prepared_by']) . '</b>' . ($d['prepared_phone'] ? '<br>' . $h($d['prepared_phone']) : '') : '' ?></div><div class="ln">For <?= $h($co['name'] ?? 'Zest Exhibitions') ?><br>Authorised Signatory</div></div>
<div class="foot">☎ <?= $h($co['phone'] ?? '') ?><?= !empty($co['email']) ? ' &nbsp;|&nbsp; ✉ ' . $h($co['email']) : '' ?><?= !empty($co['website']) ? ' &nbsp;|&nbsp; 🌐 ' . $h($co['website']) : '' ?></div>
</div>
<?php if ($ok): ?><script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
const FN = <?= json_encode($fname) ?>, PG = document.querySelector('.page');
const opt = { margin: [10, 8, 10, 8], filename: FN, image: { type: 'jpeg', quality: 0.95 }, html2canvas: { scale: 2, useCORS: true }, jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }, pagebreak: { mode: ['css', 'legacy'], avoid: ['tr', '.keep'] } };
const prep = () => { PG.style.boxShadow = 'none'; PG.style.margin = '0'; }, done = () => { PG.style.boxShadow = ''; PG.style.margin = ''; };
document.getElementById('dl').onclick = async () => { if (!window.html2pdf) { window.print(); return; } prep(); try { await html2pdf().set(opt).from(PG).save(); } finally { done(); } };
const sh = document.getElementById('sh');
if (window.html2pdf && navigator.canShare && navigator.canShare({ files: [new File([''], 'x.pdf', { type: 'application/pdf' })] })) {
  sh.hidden = false;
  sh.onclick = async () => { prep(); try { const b = await html2pdf().set(opt).from(PG).outputPdf('blob'); await navigator.share({ files: [new File([b], FN, { type: 'application/pdf' })], title: FN }); } catch (e) {} finally { done(); } };
}
</script><?php endif; ?>
</body></html>
