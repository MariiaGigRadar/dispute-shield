<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/posthog.php';

// Simple auth via ?key=
if (($_GET['key'] ?? '') !== DASH_KEY) { http_response_code(403); exit('Forbidden'); }

$db     = getDb();
$stripe = new \Stripe\StripeClient(STRIPE_SECRET_KEY);
$action = $_GET['action'] ?? 'list';
$id     = $_GET['id'] ?? '';

// AJAX: preview evidence for an email
if ($action === 'preview') {
    header('Content-Type: application/json');
    echo json_encode(getPostHogUser($_POST['email'] ?? ''));
    exit;
}

// Stats
$total    = (int)$db->querySingle("SELECT count() FROM disputes");
$pending  = (int)$db->querySingle("SELECT count() FROM disputes WHERE status IN ('needs_response','warning_needs_response')");
$won      = (int)$db->querySingle("SELECT count() FROM disputes WHERE outcome='won'");
$lost     = (int)$db->querySingle("SELECT count() FROM disputes WHERE outcome='lost'");
$totalAmt = (float)($db->querySingle("SELECT sum(amount) FROM disputes") ?: 0) / 100;

// Disputes list
$rows = [];
$res = $db->query("SELECT * FROM disputes ORDER BY epoch_created DESC");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;

$key = DASH_KEY;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><title>DisputeShield</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#020617;color:#e2e8f0;font-family:system-ui,sans-serif;font-size:14px}
.hdr{background:#0a0f1e;border-bottom:1px solid #1e293b;padding:16px 32px;display:flex;align-items:center;gap:12px}
.logo{width:32px;height:32px;background:linear-gradient(135deg,#6366f1,#8b5cf6);border-radius:8px;display:grid;place-items:center;font-size:16px}
.wrap{padding:28px 32px;max-width:1200px;margin:0 auto}
.stats{display:flex;gap:12px;margin-bottom:24px;flex-wrap:wrap}
.stat{background:#0f172a;border-radius:12px;padding:20px 24px;flex:1;min-width:130px}
.sl{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;font-family:monospace;margin-bottom:8px}
.sv{font-size:26px;font-weight:800;font-family:monospace;color:#f1f5f9}
.card{background:#0a0f1e;border:1px solid #1e293b;border-radius:12px;overflow:hidden;margin-bottom:20px}
.ch{padding:14px 20px;border-bottom:1px solid #1e293b;font-size:12px;font-weight:700;color:#94a3b8}
table{width:100%;border-collapse:collapse}
th{padding:10px 16px;text-align:left;font-size:10px;font-weight:700;color:#475569;letter-spacing:.08em;text-transform:uppercase;font-family:monospace;border-bottom:1px solid #1e293b}
td{padding:11px 16px;border-bottom:1px solid #0f172a;font-size:12px;color:#94a3b8}
tr:last-child td{border-bottom:none}
tr:hover td{background:#0f172a55}
.b{display:inline-block;padding:2px 9px;border-radius:5px;font-size:10px;font-weight:700;text-transform:uppercase;font-family:monospace}
.bw{background:#fffbeb;color:#f59e0b}.bu{background:#fef2f2;color:#ef4444}
.br{background:#eff6ff;color:#3b82f6}.bg{background:#ecfdf5;color:#10b981}
.bl{background:#fef2f2;color:#ef4444}.bc{background:#f1f5f9;color:#64748b}
a{color:#818cf8;text-decoration:none}.a:hover{text-decoration:underline}
.btn{padding:4px 11px;border-radius:6px;font-size:11px;font-weight:600;border:1px solid #334155;background:#1e293b;color:#94a3b8;cursor:pointer;text-decoration:none;display:inline-block}
input[type=email]{background:#0f172a;border:1px solid #334155;border-radius:8px;padding:8px 14px;color:#f1f5f9;font-size:13px;width:300px}
button.go{padding:8px 18px;background:#6366f1;border:none;color:#fff;border-radius:8px;cursor:pointer;font-weight:700;margin-left:8px}
pre{background:#020617;border:1px solid #1e293b;border-radius:8px;padding:14px;font-size:11px;color:#94a3b8;white-space:pre-wrap;margin-top:12px;display:none}
</style>
</head>
<body>
<div class="hdr">
  <div class="logo">⚡</div>
  <div>
    <div style="font-weight:800;font-size:15px;color:#f1f5f9">DisputeShield</div>
    <div style="font-size:10px;color:#475569;font-family:monospace">PostHog · Stripe · Auto-evidence</div>
  </div>
</div>
<div class="wrap">

<div class="stats">
  <div class="stat" style="border:1px solid #6366f133"><div class="sl" style="color:#6366f1">Total</div><div class="sv"><?=$total?></div></div>
  <div class="stat" style="border:1px solid #f59e0b33"><div class="sl" style="color:#f59e0b">Pending</div><div class="sv"><?=$pending?></div></div>
  <div class="stat" style="border:1px solid #10b98133"><div class="sl" style="color:#10b981">Won</div><div class="sv"><?=$won?></div></div>
  <div class="stat" style="border:1px solid #ef444433"><div class="sl" style="color:#ef4444">Lost</div><div class="sv"><?=$lost?></div></div>
  <div class="stat" style="border:1px solid #8b5cf633"><div class="sl" style="color:#8b5cf6">Total $</div><div class="sv">$<?=number_format($totalAmt,0)?></div></div>
</div>

<!-- Preview form -->
<div class="card">
  <div class="ch">🔍 Test: Preview evidence for any email (no submission)</div>
  <div style="padding:16px 20px">
    <input type="email" id="em" placeholder="customer@email.com">
    <button class="go" onclick="preview()">Preview</button>
    <pre id="out"></pre>
  </div>
</div>

<!-- Table -->
<div class="card">
  <div class="ch">All Disputes (<?=count($rows)?>)</div>
  <table>
    <thead><tr><th>Date</th><th>Email</th><th>Amount</th><th>Reason</th><th>Status</th><th>Evidence</th><th>Links</th></tr></thead>
    <tbody>
    <?php foreach($rows as $r):
      $bc = match($r['status']) {
        'needs_response'=>'bw','warning_needs_response'=>'bu',
        'under_review'=>'br','won'=>'bg','lost'=>'bl',default=>'bc'
      };
      $bl = match($r['status']) {
        'needs_response'=>'Needs Response','warning_needs_response'=>'Urgent',
        'under_review'=>'Review','won'=>'Won ✓','lost'=>'Lost ✗',default=>$r['status']
      };
      $ev = json_decode($r['evidence_json']??'{}',true);
      $hasEv = !empty($ev['access_activity_log']);
      $date = $r['epoch_created'] ? date('Y-m-d',$r['epoch_created']) : '—';
      $amt  = '$'.number_format($r['amount']/100,2);
    ?>
    <tr>
      <td style="font-family:monospace;color:#64748b"><?=$date?></td>
      <td><?=htmlspecialchars($r['email']??'—')?></td>
      <td style="font-family:monospace;color:#f1f5f9;font-weight:700"><?=$amt?></td>
      <td style="font-family:monospace"><?=str_replace('_',' ',$r['reason']??'')?></td>
      <td><span class="b <?=$bc?>"><?=$bl?></span></td>
      <td><?=$hasEv?'<span style="color:#10b981">✓ Done</span>':'<span style="color:#f59e0b">○ Pending</span>'?></td>
      <td>
        <a class="btn" href="https://dashboard.stripe.com/disputes/<?=htmlspecialchars($r['dispute_id'])?>" target="_blank">Stripe ↗</a>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if(empty($rows)): ?>
    <tr><td colspan="7" style="text-align:center;color:#475569;padding:40px">
      No disputes yet — they'll appear here when Stripe sends a webhook.
    </td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

</div>
<script>
async function preview() {
  const em  = document.getElementById('em').value;
  const out = document.getElementById('out');
  out.style.display = 'block';
  out.textContent   = 'Querying PostHog…';
  const fd = new FormData();
  fd.append('email', em);
  const r = await fetch('?key=<?=$key?>&action=preview', {method:'POST',body:fd});
  out.textContent = JSON.stringify(await r.json(), null, 2);
}
</script>
</body></html>
