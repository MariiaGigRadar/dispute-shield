<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/posthog.php';
require_once __DIR__ . '/app/evidence.php';
require_once __DIR__ . '/app/intercom.php';
require_once __DIR__ . '/app/mongo.php';
define('POSTHOG_PHP_VERSION', '2026-06-17-v4-mongo');

session_start();

// ── Auth ──────────────────────────────────────────────────────────────────
$allowedEmails = ['maria@gigradar.io', 'vadym@gigradar.io', 'antonina@gigradar.io'];
$secretCode    = 'vadym27039';

if (isset($_POST['login_email'])) {
    $le = strtolower(trim($_POST['login_email'] ?? ''));
    $lc = trim($_POST['login_code'] ?? '');
    if (in_array($le, $allowedEmails) && $lc === $secretCode) {
        $_SESSION['gr_user'] = $le;
    } else {
        $loginError = 'Invalid email or access code.';
    }
}
if (isset($_POST['signout'])) {
    session_destroy();
    header('Location: /');
    exit;
}
if (empty($_SESSION['gr_user'])) {
    showLogin($loginError ?? null);
    exit;
}

$currentUser = $_SESSION['gr_user'];
$action      = $_GET['action'] ?? 'list';

// ── Helper: get Stripe customer ───────────────────────────────────────────
function getStripeCustomer(string $email): ?object {
    if (!STRIPE_SECRET_KEY) return null;
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
    $ic = getIntercomData($email);
    if (!empty($ic['stripe_id'])) {
        try { return \Stripe\Customer::retrieve($ic['stripe_id']); } catch (\Exception $e) {}
    }
    $res = \Stripe\Customer::search(['query' => 'email:"' . $email . '"', 'limit' => 1]);
    return $res->data[0] ?? null;
}

// ── Helper: enrich with Stripe ────────────────────────────────────────────
function enrichWithStripe(array &$u, string $email): void {
    if (!STRIPE_SECRET_KEY) return;
    try {
        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
        $cust = getStripeCustomer($email);
        if (!$cust) return;

        $subs = \Stripe\Subscription::all(['customer' => $cust->id, 'limit' => 1, 'status' => 'all']);
        foreach ($subs->data as $sub) {
            $u['stripe_subscription_status'] = $sub->status ?? '';
            $interval = $sub->items->data[0]->price->recurring->interval ?? '';
            $prodId   = $sub->items->data[0]->price->product ?? '';
            try {
                $prod = \Stripe\Product::retrieve($prodId);
                $u['plan'] = $prod->name . ($interval ? ' (' . ucfirst($interval) . 'ly)' : '');
            } catch (\Exception $e) {}
            if ($sub->start_date) {
                $u['signup_date']          = date('Y-m-d', $sub->start_date);
                $u['subscription_start']   = date('Y-m-d', $sub->start_date);
                $u['subscription_start_ts'] = $sub->start_date; // raw unix for window math
            }
            // Capture end/cancel for the full-subscription window upper bound
            $endTs = $sub->ended_at ?? $sub->canceled_at ?? null;
            if ($endTs) {
                $u['subscription_end_ts'] = $endTs;
                $u['subscription_canceled'] = date('Y-m-d', $endTs);
            }
        }

        $allCharges = \Stripe\Charge::all(['customer' => $cust->id, 'limit' => 100]);
        $total = 0;
        $prior = [];
        foreach ($allCharges->data as $ch) {
            if ($ch->status === 'succeeded') $total += $ch->amount; // include disputed/refunded — shows total billed
            if ($ch->status === 'succeeded' && empty($ch->dispute)) {
                $prior[] = ['date' => date('Y-m-d', $ch->created), 'amount' => number_format($ch->amount / 100, 2), 'id' => $ch->id];
            }
            if (!isset($u['card_last4']) && $ch->status === 'succeeded') {
                $ba = $ch->billing_details->address ?? null;
                if ($ba) {
                    $parts = array_filter([$ba->line1 ?? '', $ba->city ?? '', $ba->postal_code ?? '', $ba->country ?? '']);
                    $u['billing_address'] = implode(', ', $parts);
                }
                $checks = $ch->payment_method_details->card->checks ?? null;
                if ($checks) {
                    $u['avs_result']  = $checks->address_postal_code_check ?? '';
                    $u['cvc_result']  = $checks->cvc_check ?? '';
                    $u['avs_address'] = $checks->address_line1_check ?? '';
                }
                $card = $ch->payment_method_details->card ?? null;
                if ($card) {
                    $u['card_last4'] = $card->last4 ?? '';
                    $u['card_brand'] = $card->brand ?? '';
                    $u['card_exp']   = ($card->exp_month ?? '') . '/' . ($card->exp_year ?? '');
                }
            }
        }
        $u['total_paid_usd'] = $total > 0 ? number_format($total / 100, 2) : '0.00';
        if (count($prior) > 1) $u['prior_transactions'] = array_slice($prior, 1, 5);

        // Fallback: if charges returned nothing (restricted key without charge:read),
        // sum paid invoices instead so Total billed isn't shown as $0.
        if ($total === 0) {
            try {
                $invTotal = 0;
                $invoices = \Stripe\Invoice::all(['customer' => $cust->id, 'limit' => 100]);
                foreach ($invoices->data as $inv) {
                    // amount_paid covers succeeded payments incl. the disputed one
                    $invTotal += (int)($inv->amount_paid ?? 0);
                }
                if ($invTotal > 0) {
                    $u['total_paid_usd'] = number_format($invTotal / 100, 2);
                    $u['total_billed_source'] = 'invoices';
                }
            } catch (\Exception $e2) {
                error_log('[stripe] invoice fallback failed: ' . $e2->getMessage());
            }
        }

        $ic = getIntercomData($email);
        if (empty($u['geo_country']) && !empty($ic['location']['country'])) {
            $u['geo_country'] = $ic['location']['country'];
            $u['geo_city']    = $ic['location']['city'] ?? '';
        }
    } catch (\Exception $e) {}
}

// ── Helper: real proposal stats from Mongo (matches product dashboard) ──────
// PostHog under-reports proposals/replies. The canonical numbers live in the
// `proposals` collection. We pull them per-team over the subscription period
// and overwrite the PostHog-derived counts when Mongo is reachable.
function enrichWithMongo(array &$u, array $intercom): void {
    $teamOid = $intercom['team_oid'] ?? '';
    if (!$teamOid) return;

    // Determine the window. Prefer the paid subscription period; fall back to
    // "all time" (null bounds) so the lifetime total is shown if no period set.
    [$fromTs, $toTs] = subscriptionWindow($u, $intercom);

    $stats = getMongoProposalStats($teamOid, $fromTs, $toTs);
    if (empty($stats['found'])) {
        if (!empty($stats['error'])) error_log('[mongo] ' . $stats['error']);
        return;
    }

    // Overwrite with real, dashboard-accurate numbers.
    $u['proposals_sent']  = $stats['sent'];
    $u['total_replies']   = $stats['replies'];
    $u['proposal_views']  = $stats['views'];
    $u['reply_rate']      = $stats['reply_rate'];
    $u['stats_source']    = 'mongo';
    $u['stats_window']    = [
        'from' => $fromTs ? date('Y-m-d', $fromTs) : 'all-time',
        'to'   => $toTs   ? date('Y-m-d', $toTs)   : 'now',
    ];

    // Scanners: distinct scannerID in proposals over the same window.
    $scanners = getMongoScannerCount($teamOid, $fromTs, $toTs);
    if ($scanners !== null) {
        $u['scanners_created'] = $scanners;
    }
}

// Compute [fromTs, toTs] covering the FULL subscription period — from the
// subscription start through to now. For dispute evidence we want the customer's
// entire usage history; using now() as the upper bound guarantees we capture
// every proposal (including any sent after a cancellation, during the
// already-paid-through period) — no bid is ever excluded by an early cutoff.
function subscriptionWindow(array $u, array $intercom): array {
    // Start: prefer Stripe subscription start, then Intercom period start.
    $startTs = $u['subscription_start_ts']
        ?? $intercom['stripe_period_start_ts']
        ?? null;

    if (!$startTs) return [null, null]; // no start info → lifetime (all proposals)

    // Upper bound = now: counts everything from start onward. (Cowork verified
    // lifetime == window for canceled subs, so this never over-counts.)
    return [(int)$startTs, time()];
}

// ── PDF download ──────────────────────────────────────────────────────────
if ($action === 'pdf') {
    require_once __DIR__ . '/app/pdf.php';
    $email  = trim($_GET['email'] ?? '');
    $reason = trim($_GET['reason'] ?? 'fraudulent');
    if (!$email) { http_response_code(400); exit('No email'); }

    $u = getPostHogUser($email);
    enrichWithStripe($u, $email);

    $intercom    = getIntercomData($email);
    $intercomLog = $intercom['summary'] ?? '';

    // Fill missing fields from Intercom when PostHog has no data
    if (!empty($intercom['found'])) {
        if (empty($u['last_active']) || $u['last_active'] === 'unknown') {
            $u['last_active'] = $intercom['last_seen_at'] ?? 'unknown';
        }
        if (empty($u['last_pageview']) || $u['last_pageview'] === 'unknown') {
            $u['last_pageview'] = $intercom['last_seen_at'] ?? 'unknown';
        }
        if (empty($u['plan']) || $u['plan'] === 'Paid Plan') {
            $u['plan'] = $intercom['stripe_plan'] ?? $u['plan'];
        }
        if (empty($u['geo_country'])) {
            $u['geo_country'] = $intercom['location']['country'] ?? '';
            $u['geo_city']    = $intercom['location']['city'] ?? '';
        }
        if (empty($u['stripe_subscription_status'])) {
            $u['stripe_subscription_status'] = $intercom['stripe_status'] ?? '';
        }
        if (empty($u['card_brand'])) {
            $u['card_brand'] = $intercom['stripe_card_brand'] ?? '';
        }
        // Mark as found so evidence functions work
        if (!($u['found'] ?? false)) {
            $u['found'] = true;
            $u['name']  = $u['name'] ?: $email;
            $u['email'] = $email;
        }
    }

    // Real Sent/Reply/View from Mongo (dashboard-accurate) — overrides PostHog.
    enrichWithMongo($u, $intercom);

    $rebuttal    = buildRebuttalLetter($u, $email, $reason);
    $activityLog = buildActivityLog($u, $email);

    $path  = generateDisputePDF($email, $reason, $u, $rebuttal, $activityLog, $intercomLog, $intercom);
    $fname = 'GigRadar_Dispute_' . preg_replace('/[^a-z0-9]/i', '_', $email) . '_' . date('Ymd') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    @unlink($path);
    exit;
}

// ── Preview (AJAX) ────────────────────────────────────────────────────────
if ($action === 'preview') {
    // Never let PHP notices/warnings leak into the JSON body (causes
    // "Unexpected token '<'" on the frontend). Buffer + JSON-only output.
    ini_set('display_errors', '0');
    ob_start();
    header('Content-Type: application/json');

    // Convert fatal errors into a clean JSON error response
    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            if (ob_get_length() !== false) ob_clean();
            if (!headers_sent()) header('Content-Type: application/json');
            echo json_encode(['error' => 'Server error: ' . $err['message']]);
        }
    });

    try {
        $email = trim($_POST['email'] ?? '');
        if (!$email) { ob_clean(); echo json_encode(['error' => 'No email']); exit; }

        $u = getPostHogUser($email);
        enrichWithStripe($u, $email);

        $intercom    = getIntercomData($email);
        $intercomLog = $intercom['summary'] ?? '';

        // Fill missing fields from Intercom
        if (!empty($intercom['found'])) {
            if (empty($u['last_active']) || $u['last_active'] === 'unknown') $u['last_active'] = $intercom['last_seen_at'] ?? 'unknown';
            if (empty($u['plan']) || $u['plan'] === 'Paid Plan') $u['plan'] = $intercom['stripe_plan'] ?? $u['plan'];
            if (empty($u['geo_country'])) { $u['geo_country'] = $intercom['location']['country'] ?? ''; $u['geo_city'] = $intercom['location']['city'] ?? ''; }
            if (empty($u['stripe_subscription_status'])) $u['stripe_subscription_status'] = $intercom['stripe_status'] ?? '';
            if (!($u['found'] ?? false)) { $u['found'] = true; $u['name'] = $u['name'] ?: $email; $u['email'] = $email; }
        }

        // Real Sent/Reply/View from Mongo (dashboard-accurate) — overrides PostHog.
        enrichWithMongo($u, $intercom);

        $payload = [
            'user'         => $u,
            'intercom'     => $intercom,
            'stripe_text'  => buildRebuttalLetter($u, $email, $_POST['reason'] ?? 'fraudulent'),
            'internal_log' => buildActivityLog($u, $email),
            'intercom_log' => $intercomLog,
        ];
        ob_clean(); // drop any stray output before the JSON
        echo json_encode($payload);
    } catch (\Throwable $e) {
        ob_clean();
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// ── Dispute list from Stripe ──────────────────────────────────────────────
$disputes    = [];
$stats       = ['total' => 0, 'pending' => 0, 'won' => 0, 'lost' => 0, 'amount' => 0];
$stripeError = null;
if (STRIPE_SECRET_KEY) {
    try {
        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

        // Single call — expand charge + customer in one shot
        $list = \Stripe\Dispute::all([
            'limit'  => 100,
            'expand' => ['data.charge.customer'],
        ]);

        foreach ($list->data as $d) {
            $em    = '';
            $chAmt = $d->amount;

            $ch = $d->charge ?? null; // expanded Charge object
            if (is_object($ch)) {
                // 1. billing_details.email
                $em = $ch->billing_details->email ?? '';
                // 2. customer.email (already expanded)
                if (!$em) {
                    $cust = $ch->customer ?? null;
                    if (is_object($cust)) {
                        $em = $cust->email ?? '';
                    } elseif (is_string($cust) && $cust) {
                        // fallback: retrieve if not expanded
                        try { $em = \Stripe\Customer::retrieve($cust)->email ?? ''; } catch (\Exception $e2) {}
                    }
                }
                // 3. receipt_email
                if (!$em) $em = $ch->receipt_email ?? '';
            }

            $status = $d->status;
            $stats['total']++;
            $stats['amount'] += $chAmt;
            if (in_array($status, ['warning_needs_response', 'needs_response'])) $stats['pending']++;
            elseif (in_array($status, ['won', 'warning_closed'])) $stats['won']++;
            elseif (in_array($status, ['lost', 'warning_under_review', 'under_review'])) $stats['lost']++;

            $disputes[] = [
                'id'         => $d->id,
                'date'       => date('Y-m-d', $d->created),
                'email'      => $em,
                'amount'     => number_format($chAmt / 100, 2),
                'amount_raw' => number_format($chAmt / 100, 2),
                'reason'     => $d->reason,
                'status'     => $status,
            ];
        }
        usort($disputes, fn($a, $b) => strcmp($b['date'], $a['date']));

    } catch (\Exception $e) {
        $stripeError = $e->getMessage();
    }
}

// ── HTML output ───────────────────────────────────────────────────────────
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DisputeShield — GigRadar</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0a0a14;color:#e2e8f0;min-height:100vh}
.topbar{display:flex;align-items:center;justify-content:space-between;padding:12px 24px;border-bottom:1px solid #1e293b;background:#080812}
.logo{display:flex;align-items:center;gap:10px;font-weight:600;font-size:15px}
.logo-icon{width:32px;height:32px;background:linear-gradient(135deg,#6B4FBB,#8B6FDB);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px}
.subtitle{font-size:12px;color:#64748b;margin-top:1px}
.user-pill{font-size:12px;color:#94a3b8;background:#1e293b;padding:4px 12px;border-radius:20px;display:flex;align-items:center;gap:8px}
.signout-btn{background:none;border:none;color:#ef4444;cursor:pointer;font-size:12px;padding:0}
.container{max-width:1300px;margin:0 auto;padding:24px}
.stats-row{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:24px}
.stat-card{background:#111827;border:1px solid #1e293b;border-radius:10px;padding:16px 20px}
.stat-label{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-bottom:6px}
.stat-value{font-size:26px;font-weight:600;color:#e2e8f0}
.stat-value.green{color:#22c55e}.stat-value.red{color:#ef4444}.stat-value.amber{color:#f59e0b}
.card{background:#111827;border:1px solid #1e293b;border-radius:10px;padding:20px;margin-bottom:20px}
.card-title{font-size:14px;font-weight:600;color:#94a3b8;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.form-row{display:flex;gap:10px;align-items:center}
.input{background:#1e293b;border:1px solid #334155;border-radius:7px;padding:9px 14px;color:#e2e8f0;font-size:14px;outline:none;flex:1}
.input:focus{border-color:#6B4FBB}
.btn{padding:9px 20px;border-radius:7px;font-size:14px;font-weight:500;cursor:pointer;border:none;transition:.15s}
.btn-primary{background:#6B4FBB;color:#fff}
.btn-primary:hover{background:#7c5fcc}
.btn-sm{padding:5px 12px;font-size:12px;border-radius:5px}
.btn-stripe{background:#635bff;color:#fff}
.btn-pdf{background:#22c55e;color:#fff}
.error-box{background:#1a0a0a;border:1px solid #7f1d1d;border-radius:8px;padding:12px 16px;color:#fca5a5;font-size:13px;margin-bottom:16px}
.debug-box{background:#0a1a0a;border:1px solid #166534;border-radius:8px;padding:12px 16px;color:#86efac;font-family:monospace;font-size:11px;line-height:1.8;margin-bottom:16px}
.panels{display:none;margin:0;gap:12px;grid-template-columns:1fr 1fr 1fr}
.panel{border-radius:10px;overflow:hidden;border:1px solid #1e293b;display:flex;flex-direction:column}
.phdr{padding:10px 14px;display:flex;align-items:center;justify-content:space-between;font-size:12px;font-weight:600;border-bottom:1px solid #1e293b}
.phdr.stripe{background:#1a1a3a;color:#a78bfa}
.phdr.internal{background:#0f2218;color:#4ade80}
.pbody{padding:12px;font-family:'Courier New',monospace;font-size:11px;line-height:1.5;white-space:pre-wrap;flex:1;min-height:380px;background:#0a0a14;color:#94a3b8;overflow:auto;margin:0}
.copybtn{background:#334155;color:#e2e8f0;border:none;border-radius:4px;padding:3px 10px;font-size:11px;cursor:pointer}
.copybtn:hover{background:#475569}
table{width:100%;border-collapse:collapse;font-size:13px}
th{text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#64748b;border-bottom:1px solid #1e293b}
td{padding:10px 12px;border-bottom:1px solid #0f172a;color:#cbd5e1}
tr:hover td{background:#0f1a2e}
.badge{display:inline-block;padding:3px 9px;border-radius:12px;font-size:11px;font-weight:600}
.badge-pending{background:#422006;color:#fb923c}
.badge-won{background:#052e16;color:#4ade80}
.badge-lost{background:#1a0a0a;color:#f87171}
.badge-other{background:#1e293b;color:#94a3b8}
.actions-cell{display:flex;gap:6px;align-items:center}
.spinner{display:none;width:16px;height:16px;border:2px solid #334155;border-top-color:#6B4FBB;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
</style>
</head>
<body>

<div class="topbar">
  <div class="logo">
    <div class="logo-icon">⚡</div>
    <div>
      <div>DisputeShield</div>
      <div class="subtitle">GigRadar · PostHog evidence</div>
    </div>
  </div>
  <div class="user-pill">
    <?= htmlspecialchars($currentUser) ?>
    <form method="post" style="display:inline">
      <button name="signout" class="signout-btn">Sign out</button>
    </form>
  </div>
</div>

<div class="container">

  <?php if ($stripeError): ?>
    <div class="error-box">⚠ Stripe error: <?= htmlspecialchars($stripeError) ?></div>
  <?php endif; ?>
  <div style="font-size:10px;color:#334155;text-align:right;margin-bottom:8px">posthog.php: <?= POSTHOG_PHP_VERSION ?></div>

  <!-- Stats -->
  <div class="stats-row">
    <div class="stat-card"><div class="stat-label">Total</div><div class="stat-value"><?= $stats['total'] ?></div></div>
    <div class="stat-card"><div class="stat-label">Pending</div><div class="stat-value amber"><?= $stats['pending'] ?></div></div>
    <div class="stat-card"><div class="stat-label">Won</div><div class="stat-value green"><?= $stats['won'] ?></div></div>
    <div class="stat-card"><div class="stat-label">Lost</div><div class="stat-value red"><?= $stats['lost'] ?></div></div>
    <div class="stat-card"><div class="stat-label">Total $</div><div class="stat-value">$<?= number_format($stats['amount']/100,0) ?></div></div>
  </div>

  <!-- Evidence generator -->
  <div class="card">
    <div class="card-title">🔍 Generate Dispute Evidence</div>
    <div class="form-row">
      <input class="input" id="emailInput" type="email" placeholder="customer@email.com">
      <select class="input" id="reasonSelect" style="flex:0 0 200px">
        <option value="fraudulent">Fraudulent</option>
        <option value="subscription_canceled">Subscription canceled</option>
        <option value="product_not_received">Product not received</option>
        <option value="product_unacceptable">Product unacceptable</option>
        <option value="credit_not_processed">Credit not processed</option>
        <option value="unrecognized">Unrecognized</option>
      </select>
      <button class="btn btn-primary" onclick="generateEvidence()">Generate Evidence</button>
      <div class="spinner" id="spinner"></div>
    </div>
    <div id="errorMsg" style="color:#f87171;font-size:13px;margin-top:10px;display:none"></div>
    <div style="margin-top:16px">
      <div class="panels" id="panels">
        <div class="panel">
          <div class="phdr stripe">
            📄 FOR STRIPE — rebuttal letter
            <button class="copybtn" onclick="doCopy('st',this)">Copy</button>
          </div>
          <pre class="pbody" id="st"></pre>
        </div>
        <div class="panel">
          <div class="phdr internal">
            📊 ACTIVITY LOG — PostHog
            <button class="copybtn" onclick="doCopy('il',this)">Copy</button>
          </div>
          <pre class="pbody" id="il"></pre>
        </div>
        <div class="panel" style="border-color:#6B4FBB55">
          <div class="phdr" style="background:#1a0d33;color:#a78bfa;border-bottom:1px solid #6B4FBB44">
            💬 INTERCOM — customer communications
            <button class="copybtn" onclick="doCopy('icl',this)">Copy</button>
          </div>
          <pre class="pbody" id="icl" style="background:#0c0818"></pre>
        </div>
      </div>
    </div>
  </div>

  <!-- Disputes table -->
  <div class="card">
    <div class="card-title">
      All Disputes (<?= count($disputes) ?>)
      <?php if ($stats['pending'] > 0): ?>
        <span class="badge badge-pending"><?= $stats['pending'] ?> NEED RESPONSE</span>
      <?php endif; ?>
    </div>
    <table>
      <thead><tr><th>Date</th><th>Email</th><th>Amount</th><th>Reason</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($disputes as $r):
        $em = $r['email'];
        $st = $r['status'];
        $badgeClass = match(true) {
            in_array($st, ['warning_needs_response','needs_response']) => 'badge-pending',
            in_array($st, ['won','warning_closed']) => 'badge-won',
            in_array($st, ['lost']) => 'badge-lost',
            default => 'badge-other'
        };
        $badgeLabel = match($st) {
            'needs_response','warning_needs_response' => '⚡ NEEDS RESPONSE',
            'won' => '✓ WON', 'lost' => '✗ LOST',
            'under_review','warning_under_review' => 'UNDER REVIEW',
            default => strtoupper($st)
        };
      ?>
      <tr>
        <td><?= $r['date'] ?></td>
        <td><?= htmlspecialchars($em) ?></td>
        <td>$<?= $r['amount'] ?></td>
        <td><code style="font-size:11px;color:#94a3b8"><?= htmlspecialchars($r['reason']) ?></code></td>
        <td><span class="badge <?= $badgeClass ?>"><?= $badgeLabel ?></span></td>
        <td>
          <div class="actions-cell">
            <?php if ($em): ?>
              <button class="btn btn-sm btn-primary"
                onclick="fillAndGenerate('<?= htmlspecialchars(addslashes($em)) ?>','<?= htmlspecialchars(addslashes($r['reason'])) ?>')">
                Generate Evidence
              </button>
              <a class="btn btn-sm btn-stripe" target="_blank"
                href="https://dashboard.stripe.com/disputes/<?= urlencode($r['id']) ?>">Stripe ↗</a>
              <a class="btn btn-sm btn-pdf"
                href="?action=pdf&email=<?= urlencode($em) ?>&reason=<?= urlencode($r['reason']) ?>&dispute_id=<?= urlencode($r['id']) ?>">
                PDF ↓</a>
            <?php else: ?>
              <span style="color:#334155;font-size:12px">—</span>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($disputes)): ?>
        <tr><td colspan="6" style="text-align:center;color:#334155;padding:32px">No disputes found in Stripe.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>

<script>
function generateEvidence() {
    const email  = document.getElementById('emailInput').value.trim();
    const reason = document.getElementById('reasonSelect').value;
    if (!email) { showErr('Enter customer email'); return; }
    hideErr();
    document.getElementById('spinner').style.display = 'inline-block';
    document.getElementById('panels').style.display  = 'none';

    const fd = new FormData();
    fd.append('action', 'preview');
    fd.append('email',  email);
    fd.append('reason', reason);

    fetch('/', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.error) { showErr(data.error); return; }
            document.getElementById('st').textContent  = data.stripe_text  || '—';
            document.getElementById('il').textContent  = data.internal_log || '—';
            document.getElementById('icl').textContent = data.intercom_log || (data.intercom && !data.intercom.found ? '(Not in Intercom)' : '—');
            document.getElementById('panels').style.display = 'grid';
        })
        .catch(e => showErr('Request failed: ' + e.message))
        .finally(() => { document.getElementById('spinner').style.display = 'none'; });
}

function fillAndGenerate(email, reason) {
    document.getElementById('emailInput').value = email;
    document.getElementById('reasonSelect').value = reason;
    generateEvidence();
    document.getElementById('panels').scrollIntoView({ behavior: 'smooth' });
}

function doCopy(id, btn) {
    const text = document.getElementById(id).textContent;
    navigator.clipboard.writeText(text).then(() => {
        btn.textContent = 'Copied!';
        setTimeout(() => btn.textContent = 'Copy', 1500);
    });
}

function showErr(msg) { const el = document.getElementById('errorMsg'); el.textContent = msg; el.style.display = 'block'; }
function hideErr()    { document.getElementById('errorMsg').style.display = 'none'; }
</script>
</body>
</html>
<?php

function showLogin(?string $error): void { ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DisputeShield</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0a0a14;color:#e2e8f0;min-height:100vh;display:flex;align-items:center;justify-content:center}
.card{background:#111827;border:1px solid #1e293b;border-radius:16px;padding:40px;width:100%;max-width:420px}
.logo{text-align:center;margin-bottom:28px}
.logo-icon{width:60px;height:60px;background:linear-gradient(135deg,#6B4FBB,#8B6FDB);border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 12px}
h1{font-size:22px;font-weight:700;text-align:center}
.sub{font-size:13px;color:#64748b;text-align:center;margin-top:4px}
label{display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin:20px 0 6px}
input{width:100%;background:#1e293b;border:1px solid #334155;border-radius:8px;padding:11px 14px;color:#e2e8f0;font-size:14px;outline:none}
input:focus{border-color:#6B4FBB}
.btn{width:100%;margin-top:24px;padding:12px;background:#6B4FBB;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer}
.btn:hover{background:#7c5fcc}
.error{background:#1a0a0a;border:1px solid #7f1d1d;border-radius:8px;padding:10px 14px;color:#fca5a5;font-size:13px;margin-top:16px}
.note{font-size:12px;color:#475569;text-align:center;margin-top:16px}
</style>
</head>
<body>
<div class="card">
  <div class="logo">
    <div class="logo-icon">⚡</div>
    <h1>DisputeShield</h1>
    <div class="sub">GigRadar internal tool</div>
  </div>
  <form method="post">
    <label>Your work email</label>
    <input type="email" name="login_email" required placeholder="you@gigradar.io">
    <label>Access code</label>
    <input type="password" name="login_code" required>
    <button class="btn" type="submit">Sign in &rarr;</button>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  </form>
  <div class="note">Access restricted to authorized GigRadar team members.</div>
</div>
</body>
</html>
<?php }
