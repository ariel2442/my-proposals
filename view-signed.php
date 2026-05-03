<?php
require_once __DIR__ . '/config.php';
if (empty($_SESSION['auth'])) { header('Location: /price/login.html'); exit; }

$id = safeId($_GET['id'] ?? '');
if (!$id) { http_response_code(400); echo 'Missing id'; exit; }

// Read from file storage
$signedFile = DATA_DIR . $id . '_signed.json';
if (!file_exists($signedFile)) {
    echo '<!DOCTYPE html><html lang="he" dir="rtl"><head><meta charset="UTF-8"><title>לא נמצא</title></head><body style="font-family:sans-serif;text-align:center;padding:60px;color:#64748b">ההסכם החתום לא נמצא.</body></html>';
    exit;
}

$signed   = json_decode(file_get_contents($signedFile), true) ?? [];
$proposal = readProposal($id) ?? [];

$signedDate = isset($signed['signedAt'])
    ? date('d/m/Y H:i', intval($signed['signedAt']) / 1000)
    : '—';
$payLabel = ($signed['paymentMethod'] ?? '') === 'credit' ? 'תשלום באשראי' : 'העברה בנקאית';
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>הסכם חתום — <?= htmlspecialchars($signed['clientName'] ?? '') ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;background:#f1f5f9;color:#1e293b;direction:rtl}
.toolbar{background:#0a2456;color:white;padding:14px 24px;display:flex;align-items:center;justify-content:space-between;gap:12px;print-color-adjust:exact}
.toolbar h1{font-size:16px;font-weight:700}
.toolbar-meta{font-size:12px;opacity:0.7}
.btn-print{background:#428EFF;color:white;border:none;padding:9px 20px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px}
.btn-print:hover{background:#60a5fa}
.page{max-width:700px;margin:30px auto;padding:0 16px 60px}
.doc{background:white;border-radius:14px;box-shadow:0 4px 24px rgba(0,0,0,0.1);overflow:hidden}
.doc-header{background:linear-gradient(135deg,#0a2456,#02132C);color:white;padding:36px 40px}
.doc-header h2{font-size:26px;font-weight:900;margin-bottom:6px}
.doc-header .meta{font-size:13px;opacity:0.7}
.signed-banner{background:#d1fae5;border:1px solid #6ee7b7;padding:14px 24px;display:flex;align-items:center;gap:10px;font-size:14px;font-weight:600;color:#065f46}
.signed-banner svg{flex-shrink:0}
.section{padding:24px 40px;border-bottom:1px solid #f1f5f9}
.section:last-child{border-bottom:none}
.section-title{font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:14px}
.field-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.field label{display:block;font-size:11px;color:#94a3b8;font-weight:600;margin-bottom:3px}
.field .val{font-size:15px;font-weight:600;color:#1e293b}
.total-row{display:flex;justify-content:space-between;padding:7px 0;font-size:14px;color:#64748b}
.total-final{font-size:18px;font-weight:900;color:#1e293b}
.sig-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:18px;margin-top:10px}
.sig-img{display:block;max-width:340px;max-height:120px;object-fit:contain;background:white;border:1px solid #e2e8f0;border-radius:6px;padding:6px}
.payment-badge{display:inline-flex;align-items:center;gap:6px;background:#eff6ff;color:#1d4ed8;padding:6px 14px;border-radius:100px;font-size:13px;font-weight:700;border:1px solid #bfdbfe}
@media print{
  .toolbar{display:none}
  body{background:white}
  .page{margin:0;padding:0}
  .doc{box-shadow:none;border-radius:0}
}
</style>
</head>
<body>

<div class="toolbar">
  <div>
    <h1>הסכם חתום</h1>
    <div class="toolbar-meta"><?= htmlspecialchars($signed['clientName'] ?? '—') ?> · <?= $signedDate ?></div>
  </div>
  <button class="btn-print" onclick="window.print()">
    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
    הדפס / שמור PDF
  </button>
</div>

<div class="page">
<div class="doc">

  <div class="doc-header">
    <h2>הצעת מחיר חתומה</h2>
    <div class="meta"><?= htmlspecialchars($proposal['bizName'] ?? $proposal['biz']['name'] ?? '') ?> · הצעה #<?= htmlspecialchars($proposal['proposalNum'] ?? '—') ?></div>
  </div>

  <div class="signed-banner">
    <svg width="18" height="18" fill="none" stroke="#059669" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
    אושר וחתום ב-<?= $signedDate ?> · <?= $payLabel ?>
  </div>

  <div class="section">
    <div class="section-title">פרטי לקוח</div>
    <div class="field-grid">
      <div class="field"><label>שם לקוח</label><div class="val"><?= htmlspecialchars($signed['clientName'] ?? '—') ?></div></div>
      <div class="field"><label>טלפון</label><div class="val" dir="ltr"><?= htmlspecialchars($proposal['clientPhone'] ?? '—') ?></div></div>
      <?php if (!empty($proposal['projectType'])): ?>
      <div class="field"><label>סוג פרויקט</label><div class="val"><?= htmlspecialchars($proposal['projectType']) ?></div></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="section">
    <div class="section-title">סכום ותשלום</div>
    <div style="padding-top:4px">
      <?php
        $subtotal = $proposal['subtotal'] ?? $proposal['total'] ?? 0;
        $vat      = $proposal['vat']      ?? 0;
        $total    = $proposal['total']    ?? ($subtotal + $vat);
      ?>
      <?php if ($vat > 0): ?>
      <div class="total-row"><span>סכום לפני מע"מ</span><span>₪<?= number_format($subtotal, 0, '.', ',') ?></span></div>
      <div class="total-row"><span>מע"מ (18%)</span><span>₪<?= number_format($vat, 0, '.', ',') ?></span></div>
      <?php endif; ?>
      <div class="total-row total-final"><span>סה"כ לתשלום</span><span>₪<?= number_format($total, 0, '.', ',') ?></span></div>
    </div>
  </div>

  <?php if (!empty($proposal['notes'])): ?>
  <div class="section">
    <div class="section-title">הערות</div>
    <div style="font-size:14px;line-height:1.7;color:#334155;white-space:pre-wrap"><?= htmlspecialchars($proposal['notes']) ?></div>
  </div>
  <?php endif; ?>

  <div class="section">
    <div class="section-title">פרטי חתימה</div>
    <div class="field-grid" style="margin-bottom:16px">
      <div class="field"><label>חתום על ידי</label><div class="val"><?= htmlspecialchars($signed['signerName'] ?? '—') ?></div></div>
      <div class="field"><label>תאריך חתימה</label><div class="val"><?= $signedDate ?></div></div>
      <div class="field"><label>אמצעי תשלום</label><div class="val"><span class="payment-badge"><?= $payLabel ?></span></div></div>
    </div>
    <?php if (!empty($signed['signature'])): ?>
    <div>
      <div class="section-title" style="margin-bottom:8px">חתימה דיגיטלית</div>
      <div class="sig-box">
        <img class="sig-img" src="<?= htmlspecialchars($signed['signature']) ?>" alt="חתימה">
      </div>
    </div>
    <?php endif; ?>
  </div>

</div>
</div>

</body>
</html>
