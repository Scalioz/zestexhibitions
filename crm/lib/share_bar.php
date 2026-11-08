<?php
// Top bar on quote / invoice pages: WhatsApp (sends the PDF), Download, Send link, Print.
// $kind quote|invoice · $id · $fname PDF file name · $msg message text · $phone client's number · $back link back (null for clients opening a secure link)
function share_bar($kind, $id, $fname, $msg, $phone, $back) {
  $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
  $digits = preg_replace('/\D/', '', (string) $phone); if (strlen($digits) === 10) $digits = '91' . $digits;
  ob_start(); ?>
<style>.bar{flex-wrap:wrap;padding:0 12px}.bar button:disabled{opacity:.6}.wamsg{max-width:800px;margin:8px auto 0;padding:10px 14px;background:#eaf7ef;border:1px solid #bfe3cc;border-radius:8px;font:14px Arial;color:#1d4d32}@media (max-width:640px){.bar{position:sticky;top:0;z-index:5;background:#eef1f6;padding:10px 12px;margin:0}.bar button,.bar a{flex:1 1 auto;text-align:center;font-size:14px;padding:11px 10px}}</style>
<div class="bar">
  <?php if ($back): ?><a href="<?= $h($back) ?>">← Back</a><?php endif; ?>
  <?php if ($back): ?><button id="wa" style="background:#1fa855;border-color:#1fa855">WhatsApp</button><?php endif; ?>
  <button id="dl">⬇ Download PDF</button>
  <?php if ($back): ?><button id="lk" class="alt">🔗 Send link</button><?php endif; ?>
  <a href="#" onclick="window.print();return false">Print</a>
</div>
<div id="wamsg" class="wamsg" hidden></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
const FN = <?= json_encode($fname) ?>, MSG = <?= json_encode($msg) ?>, PH = <?= json_encode($digits) ?>, KIND = <?= json_encode($kind) ?>, ID = <?= (int) $id ?>;
const CSRF = <?= json_encode($back ? ($_SESSION['csrf'] ?? '') : '') ?>;
const note = t => { const n = document.getElementById('wamsg'); n.innerHTML = t; n.hidden = false; };
const wa = text => 'https://wa.me/' + PH + '?text=' + encodeURIComponent(text);
async function pdfBlob() { const PG = document.querySelector('.page'); PG.style.boxShadow = 'none'; PG.style.margin = '0';
  try { return await html2pdf().set(window.PDFOPT).from(PG).outputPdf('blob'); } finally { PG.style.boxShadow = ''; PG.style.margin = ''; } }
document.getElementById('dl').onclick = async () => { if (!window.html2pdf) { window.print(); return; } const b = await pdfBlob(); const a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = FN; a.click(); };
let READY = null;   // phones: make the PDF in the background so the share opens instantly on tap
if (window.html2pdf && navigator.canShare && navigator.canShare({ files: [new File([''], 'x.pdf', { type: 'application/pdf' })] })) addEventListener('load', () => setTimeout(() => { READY = pdfBlob().catch(() => null); }, 400));
const waBtn = document.getElementById('wa');
if (waBtn) waBtn.onclick = async () => {
  waBtn.disabled = true; waBtn.textContent = 'Preparing PDF…';
  try {
    if (!window.html2pdf) throw new Error('nolib');
    const file = new File([(READY && await READY) || await pdfBlob()], FN, { type: 'application/pdf' });
    if (navigator.canShare && navigator.canShare({ files: [file] })) {   // phone: share sheet — pick WhatsApp or WhatsApp Business, then the contact
      try { await navigator.share({ files: [file], text: MSG }); } catch (e) {}
    } else {   // computer: download the PDF and open WhatsApp Web with the message
      const a = document.createElement('a'); a.href = URL.createObjectURL(file); a.download = FN; a.click();
      window.open(wa(MSG), '_blank', 'noopener');
      note('The PDF is downloaded. In WhatsApp, attach it (📎) to the message — or use <b>🔗 Send link</b> to send a link instead.');
    }
  } catch (e) { note('Could not make the PDF here. Use <b>🔗 Send link</b> instead.'); }
  waBtn.disabled = false; waBtn.textContent = 'WhatsApp';
};
const lk = document.getElementById('lk');
if (lk) lk.onclick = async () => {
  lk.disabled = true;
  try {
    const r = await fetch('api.php?a=share_link', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF }, body: JSON.stringify({ kind: KIND, id: ID }), credentials: 'same-origin' });
    const j = await r.json(); if (!r.ok || j.error) throw new Error(j.error || 'Could not make the link');
    const text = MSG + '\n' + j.url;
    try { await navigator.clipboard.writeText(text); } catch (e) {}
    window.open(wa(text), '_blank', 'noopener');
    note('Secure link (valid ' + j.days + ' days) copied and opened in WhatsApp:<br><span style="word-break:break-all">' + j.url.replace(/</g, '') + '</span>');
  } catch (e) { note(e.message); }
  lk.disabled = false;
};
</script>
<?php return ob_get_clean();
}
