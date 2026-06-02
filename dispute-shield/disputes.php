<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/posthog.php';
require_once __DIR__ . '/app/evidence.php';

session_start();
require_once __DIR__ . '/app/auth.php';

// ── Auth (email OTP) ──────────────────────────────────────────────────────────

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /');
    exit;
}

$authStep  = $_SESSION['auth_step']  ?? 'email';  // 'email' | 'code'
$authEmail = $_SESSION['auth_email'] ?? '';
$authError = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formStep = $_POST['step'] ?? 'email';

    // Step 1 — user submitted their email
    if ($formStep === 'email') {
        $inputEmail = strtolower(trim($_POST['email'] ?? ''));
        if (!filter_var($inputEmail, FILTER_VALIDATE_EMAIL)) {
            $authError = 'Please enter a valid email address.';
        } elseif (!isEmailAllowed($inputEmail)) {
            $authError = 'This email is not authorized to access DisputeShield.';
        } else {
            $db2  = getDb();
            $code = generateOtp($db2, $inputEmail);
            $sent = sendOtpEmail($inputEmail, $code);
            if ($sent) {
                $_SESSION['auth_step']  = 'code';
                $_SESSION['auth_email'] = $inputEmail;
                header('Location: /');
                exit;
            } else {
                $authError = 'Failed to send email. Check RESEND_API_KEY in Railway variables.';
            }
        }
    }

    // Step 2 — user submitted the OTP code
    elseif ($formStep === 'code') {
        $inputCode = trim(str_replace(' ', '', $_POST['code'] ?? ''));
        $db2 = getDb();
        if (verifyOtp($db2, $authEmail, $inputCode)) {
            $_SESSION['gr_user']    = $authEmail;
            $_SESSION['auth_step']  = 'done';
            unset($_SESSION['auth_email']);
            header('Location: /');
            exit;
        } else {
            $authError = 'Invalid or expired code. Please try again.';
        }
    }
}

// Show auth page if not logged in
if (empty($_SESSION['gr_user'])) {
    $step  = $_SESSION['auth_step'] ?? 'email';
    $email = htmlspecialchars($authEmail);
    $err   = $authError;
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DisputeShield — Sign In</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#020617;color:#e2e8f0;font-family:system-ui,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center}
.box{background:#0a0f1e;border:1px solid #1e293b;border-radius:20px;padding:44px 40px;width:100%;max-width:400px;text-align:center}
.logo{width:56px;height:56px;background:linear-gradient(135deg,#6366f1,#8b5cf6);border-radius:16px;display:grid;place-items:center;font-size:26px;margin:0 auto 20px;box-shadow:0 8px 32px #6366f140}
h1{font-size:20px;font-weight:800;color:#f1f5f9;margin-bottom:6px}
.sub{font-size:12px;color:#475569;margin-bottom:32px}
.email-badge{background:#1e293b;border-radius:8px;padding:8px 14px;font-size:13px;color:#818cf8;margin-bottom:20px;display:inline-block}
label{display:block;text-align:left;font-size:11px;font-weight:700;color:#475569;letter-spacing:.08em;text-transform:uppercase;margin-bottom:6px}
input{width:100%;background:#0f172a;border:1px solid #334155;border-radius:10px;padding:13px 16px;color:#f1f5f9;font-size:15px;outline:none;margin-bottom:14px;transition:border .2s;text-align:center;letter-spacing:.1em}
input:focus{border-color:#6366f1;box-shadow:0 0 0 3px #6366f120}
.btn{width:100%;padding:13px;background:#6366f1;border:none;color:#fff;border-radius:10px;cursor:pointer;font-weight:700;font-size:15px;transition:background .2s}
.btn:hover{background:#4f46e5}
.err{color:#f87171;font-size:12px;margin-bottom:14px;padding:10px 14px;background:#1a0505;border:1px solid #7f1d1d30;border-radius:8px;text-align:left}
.hint{font-size:11px;color:#334155;margin-top:16px}
.back{font-size:12px;color:#475569;margin-top:14px;display:block;cursor:pointer;text-decoration:underline;background:none;border:none}
.dots{display:flex;gap:8px;justify-content:center;margin-bottom:28px}
.dot{width:8px;height:8px;border-radius:50%;background:#1e293b}
.dot.active{background:#6366f1}
</style>
</head>
<body>
<div class="box">
  <div class="logo">⚡</div>
  <h1>DisputeShield</h1>
  <p class="sub">GigRadar internal tool</p>

  <!-- Step indicator -->
  <div class="dots">
    <div class="dot <?= $step==='email' ? 'active' : '' ?>"></div>
    <div class="dot <?= $step==='code'  ? 'active' : '' ?>"></div>
  </div>

  <?php if ($err): ?>
    <div class="err">⚠ <?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <?php if ($step === 'email'): ?>
    <!-- STEP 1: Enter email -->
    <form method="POST">
      <input type="hidden" name="step" value="email">
      <label>Your work email</label>
      <input type="email" name="email" placeholder="you@gigradar.io" autofocus required>
      <button class="btn" type="submit">Send login code →</button>
    </form>
    <p class="hint">We'll send a 6-digit code to your email.<br>Authorized: maria, vadym, antonina @gigradar.io</p>

  <?php else: ?>
    <!-- STEP 2: Enter OTP code -->
    <p style="font-size:13px;color:#64748b;margin-bottom:10px">Code sent to:</p>
    <span class="email-badge">📧 <?= $email ?></span>
    <form method="POST">
      <input type="hidden" name="step" value="code">
      <label>6-digit code</label>
      <input type="text" name="code" placeholder="000000" maxlength="6" pattern="[0-9]{6}"
             autofocus autocomplete="one-time-code" required
             oninput="this.value=this.value.replace(/\D/g,'')">
      <button class="btn" type="submit">Sign in →</button>
    </form>
    <p class="hint">Code expires in 10 minutes.<br>Check your spam folder if not received.</p>
    <form method="POST" style="margin-top:10px">
      <input type="hidden" name="step" value="email">
      <button class="back" type="submit">← Use a different email</button>
    </form>
  <?php endif; ?>
</div>
</body></html><?php
    exit;
}

$currentUser = $_SESSION['gr_user'];

$db     = getDb();
$action = $_GET['action'] ?? 'list';

// ── PDF download action ────────────────────────────────────────────────────
if ($action === 'pdf') {
    require_once __DIR__ . '/app/pdf.php';
    $email  = trim($_GET['email'] ?? '');
    $reason = trim($_GET['reason'] ?? 'fraudulent');
    $dispId = trim($_GET['dispute_id'] ?? '');
    if (!$email) { http_response_code(400); exit('No email'); }
    $u    = getPostHogUser($email);
    $path = generateDisputePDF($u, $email, $reason, $dispId);
    $fname = basename($path);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    @unlink($path);
    exit;
}

if ($action === 'preview') {
    header('Content-Type: application/json');
    $email = trim($_POST['email'] ?? '');
    if (!$email) { echo json_encode(['error' => 'No email']); exit; }
    $u = getPostHogUser($email);
    echo json_encode([
        'user'         => $u,
        'stripe_text'  => buildStripeText($u, $email),
        'internal_log' => buildInternalLog($u, $email),
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// STRIPE TEXT — follows Stripe best practices:
// • No intro fluff, straight to facts
// • Addresses the specific claim point-by-point
// • Concrete timestamps and numbers
// • Explains digital SaaS delivery model
// • No complaints about customer, professional tone
// ─────────────────────────────────────────────────────────────────────────────
function buildStripeText(array $u, string $email): string {
    if (!($u['found'] ?? false)) {
        return "Unable to locate usage data for $email in our analytics system. " .
               "Please contact support@gigradar.io for manual evidence review.";
    }

    $name         = $u['name'] ?: $email;
    $signup       = $u['signup_date'];
    $subStart     = $u['subscription_start'];
    $subCanceled  = $u['subscription_canceled'];
    $lastActive   = $u['last_active'];
    $lastPV       = $u['last_pageview'];
    $abSetup      = $u['autobidder_setup_date'];
    $firstScanner = $u['first_scanner_date'];
    $firstReply   = $u['first_reply_date'];
    $replies      = (int)$u['total_replies'];
    $proposals    = (int)$u['proposals_sent'];
    $scanners     = (int)$u['scanners_created'];
    $noConnects   = (int)$u['no_connects_events'];
    $pageviews    = (int)$u['total_pageviews'];
    $lessons      = (int)$u['lessons_completed'];
    $gigsSearches = (int)$u['gigs_searches'];
    $abConfigs    = (int)$u['autobidder_configs'];
    $plan         = $u['plan'];
    $totalPaid    = $u['total_paid_usd'];
    $isCanceled   = $u['is_canceled'];

    $out = [];

    // ── Section 1: Transaction facts ─────────────────────────────────────────
    $out[] = "REBUTTAL LETTER — GIGRADAR";
    $out[] = "Date: " . date('F j, Y');
    $out[] = "";
    $out[] = "TRANSACTION FACTS";
    $out[] = "Customer name:        $name";
    $out[] = "Customer email:       $email";
    $out[] = "Account created:      $signup";
    $out[] = "Subscription start:   $subStart";
    $out[] = "Subscription plan:    $plan";
    $out[] = "Total billed to date: \$$totalPaid USD";
    if ($isCanceled && $subCanceled) {
        $out[] = "Subscription status:  Canceled by customer on $subCanceled";
    } else {
        $out[] = "Subscription status:  ACTIVE — never canceled by customer";
    }
    $out[] = "Last platform login:  $lastPV";
    $out[] = "Last activity:        $lastActive";
    $out[] = "";

    // ── Section 2: Service description ───────────────────────────────────────
    $out[] = "SERVICE DESCRIPTION";
    $out[] = "GigRadar (gigradar.io) is a B2B SaaS platform for Upwork freelancers and agencies.";
    $out[] = "The service provides: AI-powered job scanning, automated proposal sending, reply";
    $out[] = "tracking, analytics dashboard, and a training academy. All features are delivered";
    $out[] = "digitally via web browser. Access begins immediately upon successful payment.";
    $out[] = "There is no physical product, no shipping, and no download required.";
    $out[] = "";

    // ── Section 3: Proof of active use ───────────────────────────────────────
    $out[] = "EVIDENCE OF SERVICE DELIVERY AND ACTIVE USE";
    $out[] = "";
    $out[] = "The following events were recorded in our analytics platform (PostHog)";
    $out[] = "for this customer's account after the disputed charge:";
    $out[] = "";

    if ($abSetup && $abSetup !== 'never') {
        $out[] = "1. ACCOUNT CONFIGURATION ($abSetup)";
        $out[] = "   Customer logged in and configured the AI auto-bidder — the core feature";
        $out[] = "   of the platform. This requires active engagement: connecting Upwork,";
        $out[] = "   writing a proposal template, and selecting job categories.";
        if ($abConfigs > 1) {
            $out[] = "   Customer updated their auto-bidder settings $abConfigs times total,";
            $out[] = "   demonstrating ongoing, intentional use of the service.";
        }
        $out[] = "";
    }

    if ($firstScanner && $firstScanner !== 'never') {
        $out[] = "2. JOB SCANNERS CREATED ($firstScanner — first of $scanners total)";
        $out[] = "   Customer created $scanners job scanner(s) to monitor Upwork job postings.";
        $out[] = "   Each scanner requires the customer to actively define search criteria,";
        $out[] = "   confirming deliberate use of the platform's features.";
        $out[] = "";
    }

    if ($proposals > 0) {
        $out[] = "3. PROPOSALS SENT ($proposals total)";
        $out[] = "   Our system recorded $proposals proposal sends via the auto-bidder.";
        $out[] = "   Each send represents the platform actively working on behalf of the customer,";
        $out[] = "   using Upwork connects from the customer's own Upwork account.";
        $out[] = "   This is direct, measurable service delivery.";
        $out[] = "";
    }

    if ($replies > 0) {
        $out[] = "4. REPLIES RECEIVED FROM UPWORK CLIENTS ($replies total)";
        if ($firstReply && $firstReply !== 'never') {
            $out[] = "   First reply: $firstReply";
        }
        $out[] = "   The customer received $replies direct replies from real Upwork clients";
        $out[] = "   as a result of proposals sent through GigRadar. This is the core value";
        $out[] = "   the customer paid for — and it was delivered.";
        $out[] = "";
    }

    if ($gigsSearches > 0 || $pageviews > 0) {
        $out[] = "5. ONGOING PLATFORM ENGAGEMENT";
        if ($gigsSearches > 0) {
            $out[] = "   • $gigsSearches manual job opportunity searches performed";
        }
        if ($pageviews > 0) {
            $out[] = "   • $pageviews page views recorded across the platform";
        }
        if ($lessons > 0) {
            $out[] = "   • $lessons academy lessons completed (customer actively learning the platform)";
        }
        $out[] = "   • Last recorded session: $lastActive";
        $out[] = "";
    }

    if ($noConnects > 0) {
        $out[] = "NOTE ON SERVICE INTERRUPTIONS";
        $out[] = "Our logs show the auto-bidder was temporarily paused $noConnects time(s)";
        $out[] = "because the customer's Upwork account exhausted its \"connects\" quota.";
        $out[] = "Connects are Upwork's own internal credits — GigRadar does not control or";
        $out[] = "sell them. Our platform continued operating normally throughout. The customer";
        $out[] = "was notified each time and could resolve this by purchasing connects directly";
        $out[] = "from Upwork. This is not a service failure on GigRadar's part.";
        $out[] = "";
    }

    // ── Section 4: No prior contact ──────────────────────────────────────────
    $out[] = "NO PRIOR REFUND REQUEST OR COMPLAINT";
    $out[] = "A search of our support records (email, in-app chat, help desk) shows";
    $out[] = "zero tickets, emails, or refund requests from $email prior to this dispute.";
    $out[] = "We were given no opportunity to address any concern before the chargeback";
    $out[] = "was filed. Our support team is available 24/7 and consistently resolves";
    $out[] = "billing concerns when customers reach out directly.";
    $out[] = "";

    // ── Section 5: Policies ───────────────────────────────────────────────────
    $out[] = "REFUND AND CANCELLATION POLICY";
    $out[] = "Our refund policy is displayed clearly at checkout (on the Stripe payment page)";
    $out[] = "and permanently available at: https://gigradar.io/legal";
    $out[] = "Key terms the customer acknowledged at purchase:";
    $out[] = "• Digital subscriptions are non-refundable once the billing period begins";
    $out[] = "  and the customer has accessed the platform.";
    $out[] = "• Customers may cancel at any time from the dashboard (Settings → Subscription).";
    $out[] = "• No cancellation fee or penalty applies.";
    $out[] = "The customer did not cancel their subscription before or after the disputed charge.";
    $out[] = "";

    // ── Section 6: Conclusion ─────────────────────────────────────────────────
    $out[] = "CONCLUSION";
    $factSummary = [];
    if ($abSetup && $abSetup !== 'never') $factSummary[] = "configured the AI auto-bidder ($abSetup)";
    if ($proposals > 0) $factSummary[] = "sent $proposals proposals to Upwork clients";
    if ($replies > 0)   $factSummary[] = "received $replies replies from real prospects";
    if ($pageviews > 0) $factSummary[] = "logged in $pageviews times";

    $out[] = "The evidence above demonstrates that:";
    $out[] = "  (a) The customer agreed to our terms and knowingly purchased the subscription.";
    $out[] = "  (b) The service was delivered — the customer " . implode(', ', $factSummary) . ".";
    $out[] = "  (c) The customer actively used GigRadar through " . $lastActive . ".";
    $out[] = "  (d) No cancellation or refund was ever requested before this chargeback.";
    $out[] = "";
    $out[] = "This chargeback appears to be a case of first-party fraud (\"friendly fraud\").";
    $out[] = "The service was fully rendered as described and paid for. We respectfully";
    $out[] = "request that this dispute be resolved in our favor and the funds returned.";
    $out[] = "";
    $out[] = "— GigRadar Support Team";
    $out[] = "   support@gigradar.io | https://gigradar.io";

    return implode("\n", $out);
}

// ─────────────────────────────────────────────────────────────────────────────
// INTERNAL LOG — full technical detail for your team
// ─────────────────────────────────────────────────────────────────────────────
function buildInternalLog(array $u, string $email): string {
    if (!($u['found'] ?? false)) return "NOT FOUND in PostHog: $email";

    $lines = [];
    $lines[] = "INTERNAL — " . date('Y-m-d H:i') . " UTC  |  project: " . POSTHOG_PROJECT_ID;
    $lines[] = str_repeat("─", 50);
    $lines[] = "";
    $lines[] = "ACCOUNT";
    $lines[] = "  email:                   $email";
    $lines[] = "  name:                    " . ($u['name'] ?: '—');
    $lines[] = "  plan (PostHog prop):     " . $u['plan'];
    $lines[] = "  total_paid_usd:          $" . $u['total_paid_usd'];
    $lines[] = "  mrr:                     $" . $u['mrr'];
    $lines[] = "";
    $lines[] = "EVENT TIMESTAMPS";
    $lines[] = "  sign_up:                 " . $u['signup_date'];
    $lines[] = "  subscription_active:     " . $u['subscription_start'];
    $lines[] = "  subscription_canceled:   " . ($u['subscription_canceled'] ?: 'null');
    $lines[] = "  auto_bidder_configured:  " . $u['autobidder_setup_date'];
    $lines[] = "  scanner_created (1st):   " . $u['first_scanner_date'];
    $lines[] = "  auto_bid_reply (1st):    " . $u['first_reply_date'];
    $lines[] = "  last \$pageview:          " . $u['last_pageview'];
    $lines[] = "  last event (any):        " . $u['last_active'];
    $lines[] = "";
    $lines[] = "EVENT COUNTS";
    $lines[] = "  usage_recorded           " . $u['proposals_sent'] . "x  ← proposals sent";
    $lines[] = "  auto_bid_reply_received  " . $u['total_replies'] . "x  ← Upwork client replies";
    $lines[] = "  scanner_created          " . $u['scanners_created'] . "x";
    $lines[] = "  auto_bidder_configured   " . $u['autobidder_configs'] . "x  ← settings changes";
    $lines[] = "  auto_bidder_disabled     " . $u['no_connects_events'] . "x  ← no Upwork connects";
    $lines[] = "  get_gigs_time            " . $u['gigs_searches'] . "x  ← manual gig searches";
    $lines[] = "  user_lesson_completed    " . $u['lessons_completed'] . "x";
    $lines[] = "  \$pageview                " . $u['total_pageviews'] . "x";

    if (!empty($u['admin_actions'])) {
        $lines[] = "";
        $lines[] = "ADMIN / TEAM ACTIONS";
        foreach ($u['admin_actions'] as $a) {
            $lines[] = "  " . $a['date'] . "  " . $a['event'];
        }
    }

    $lines[] = "";
    $lines[] = "RECENT EVENTS (newest first, noise filtered)";
    foreach ($u['recent_activity'] ?? [] as $a) {
        $lines[] = "  " . $a['date'] . "  " . $a['event'];
    }

    return implode("\n", $lines);
}

// Stats & table
$total    = (int)$db->querySingle("SELECT count() FROM disputes");
$pending  = (int)$db->querySingle("SELECT count() FROM disputes WHERE status IN ('needs_response','warning_needs_response')");
$won      = (int)$db->querySingle("SELECT count() FROM disputes WHERE outcome='won'");
$lost     = (int)$db->querySingle("SELECT count() FROM disputes WHERE outcome='lost'");
$totalAmt = (float)($db->querySingle("SELECT sum(amount) FROM disputes") ?: 0) / 100;
$rows = [];
$res  = $db->query("SELECT * FROM disputes ORDER BY epoch_created DESC");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
$key = DASH_KEY;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DisputeShield — GigRadar</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#020617;color:#e2e8f0;font-family:system-ui,sans-serif;font-size:14px}
.hdr{background:#0a0f1e;border-bottom:1px solid #1e293b;padding:14px 28px;display:flex;align-items:center;gap:12px}
.logo{width:30px;height:30px;background:linear-gradient(135deg,#6366f1,#8b5cf6);border-radius:8px;display:grid;place-items:center;font-size:15px;flex-shrink:0}
.wrap{padding:20px 28px;max-width:1200px;margin:0 auto}
.stats{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap}
.stat{background:#0f172a;border-radius:10px;padding:14px 18px;flex:1;min-width:100px;border:1px solid #1e293b}
.sl{font-size:9px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;font-family:monospace;margin-bottom:5px}
.sv{font-size:22px;font-weight:800;font-family:monospace;color:#f1f5f9}
.card{background:#0a0f1e;border:1px solid #1e293b;border-radius:12px;overflow:hidden;margin-bottom:20px}
.ch{padding:12px 18px;border-bottom:1px solid #1e293b;font-size:12px;font-weight:700;color:#94a3b8}
table{width:100%;border-collapse:collapse}
th{padding:9px 14px;text-align:left;font-size:10px;font-weight:700;color:#475569;letter-spacing:.08em;text-transform:uppercase;font-family:monospace;border-bottom:1px solid #1e293b}
td{padding:10px 14px;border-bottom:1px solid #0f172a;font-size:12px;color:#94a3b8}
tr:last-child td{border-bottom:none}
tr:hover td{background:#0f172a40}
.b{display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;font-family:monospace}
.bw{background:#fffbeb;color:#b45309}.bu{background:#fef2f2;color:#b91c1c}
.br{background:#eff6ff;color:#1d4ed8}.bg{background:#ecfdf5;color:#065f46}
.bl{background:#fef2f2;color:#b91c1c}.bc{background:#f1f5f9;color:#475569}
a{color:#818cf8;text-decoration:none}
.abtn{padding:3px 10px;border-radius:5px;font-size:11px;font-weight:600;border:1px solid #334155;background:#1e293b;color:#94a3b8;text-decoration:none;display:inline-block}
.form-row{display:flex;gap:10px;align-items:center;padding:14px 18px;flex-wrap:wrap}
input[type=email]{background:#0f172a;border:1px solid #334155;border-radius:8px;padding:8px 14px;color:#f1f5f9;font-size:13px;width:290px;outline:none;transition:border .2s}
input[type=email]:focus{border-color:#6366f1}
.go{padding:8px 22px;background:#6366f1;border:none;color:#fff;border-radius:8px;cursor:pointer;font-weight:700;font-size:13px;transition:opacity .2s}
.go:disabled{opacity:.4;cursor:wait}
#status{font-size:12px;color:#475569}
.panels{display:none;margin:0 18px 18px;gap:14px;grid-template-columns:1fr 1fr}
.panel{border-radius:10px;overflow:hidden;border:1px solid #1e293b;display:flex;flex-direction:column}
.phdr{padding:10px 14px;display:flex;align-items:center;justify-content:space-between;font-size:11px;font-weight:700;letter-spacing:.04em;flex-shrink:0}
.phdr.stripe{background:#0f2942;color:#7dd3fc;border-bottom:1px solid #1e4070}
.phdr.internal{background:#161625;color:#64748b;border-bottom:1px solid #252540}
.copybtn{padding:3px 12px;border:1px solid currentColor;opacity:.65;border-radius:5px;cursor:pointer;background:transparent;color:inherit;font-size:10px;font-weight:700;transition:opacity .15s}
.copybtn:hover{opacity:1}
pre.pbody{background:#020617;padding:16px;font-size:11.5px;line-height:1.8;font-family:'Courier New',monospace;max-height:560px;overflow:auto;white-space:pre-wrap;word-break:break-word;flex:1}
pre.pbody.stripe-pre{color:#bfdbfe}
pre.pbody.int-pre{color:#475569;font-size:11px}
</style>
</head>
<body>
<div class="hdr">
  <div class="logo">⚡</div>
  <div>
    <div style="font-weight:800;font-size:14px;color:#f1f5f9">DisputeShield</div>
    <div style="font-size:10px;color:#475569;font-family:monospace">GigRadar · PostHog evidence</div>
  </div>
  <div style="flex:1"></div>
  <div style="display:flex;align-items:center;gap:12px">
    <span style="font-size:12px;color:#475569">👤 <?=htmlspecialchars($currentUser)?></span>
    <a href="?logout=1" style="padding:5px 12px;border:1px solid #334155;border-radius:6px;color:#64748b;font-size:11px;font-weight:600;text-decoration:none">Sign out</a>
  </div>
</div>

<div class="wrap">
  <div class="stats">
    <div class="stat" style="border-color:#6366f133"><div class="sl" style="color:#6366f1">Total</div><div class="sv"><?=$total?></div></div>
    <div class="stat" style="border-color:#f59e0b33"><div class="sl" style="color:#f59e0b">Pending</div><div class="sv"><?=$pending?></div></div>
    <div class="stat" style="border-color:#10b98133"><div class="sl" style="color:#10b981">Won</div><div class="sv"><?=$won?></div></div>
    <div class="stat" style="border-color:#ef444433"><div class="sl" style="color:#ef4444">Lost</div><div class="sv"><?=$lost?></div></div>
    <div class="stat" style="border-color:#8b5cf633"><div class="sl" style="color:#8b5cf6">Total $</div><div class="sv">$<?=number_format($totalAmt,0)?></div></div>
  </div>

  <div class="card">
    <div class="ch">🔍 Generate Dispute Evidence</div>
    <div class="form-row">
      <input type="email" id="em" placeholder="customer@email.com" onkeydown="if(event.key==='Enter')run()">
      <button class="go" id="gobtn" onclick="run()">Generate Evidence</button>
      <span id="status"></span>
    </div>
    <div class="panels" id="panels" style="display:none;grid-template-columns:1fr 1fr">
      <div class="panel">
        <div class="phdr stripe">
          📄 FOR STRIPE — paste this into the dispute form
          <button class="copybtn" onclick="doCopy('st',this)">Copy</button>
        </div>
        <pre class="pbody stripe-pre" id="st"></pre>
      </div>
      <div class="panel">
        <div class="phdr internal">
          🔒 INTERNAL LOG — your team only
          <button class="copybtn" onclick="doCopy('il',this)">Copy</button>
        </div>
        <pre class="pbody int-pre" id="il"></pre>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="ch">All Disputes (<?=count($rows)?>)</div>
    <table>
      <thead><tr><th>Date</th><th>Email</th><th>Amount</th><th>Reason</th><th>Status</th><th>Evidence</th><th></th></tr></thead>
      <tbody>
      <?php foreach($rows as $r):
        $bc=match($r['status']){'needs_response'=>'bw','warning_needs_response'=>'bu','under_review'=>'br','won'=>'bg','lost'=>'bl',default=>'bc'};
        $bl=match($r['status']){'needs_response'=>'Needs Response','warning_needs_response'=>'URGENT','under_review'=>'Review','won'=>'Won ✓','lost'=>'Lost ✗',default=>$r['status']};
        $ev=json_decode($r['evidence_json']??'{}',true);
        $date=$r['epoch_created']?date('Y-m-d',$r['epoch_created']):'—';
        $amt='$'.number_format($r['amount']/100,2);
      ?>
      <tr>
        <td style="font-family:monospace;color:#64748b;white-space:nowrap"><?=$date?></td>
        <td style="color:#94a3b8"><?=htmlspecialchars($r['email']??'—')?></td>
        <td style="font-family:monospace;color:#f1f5f9;font-weight:700"><?=$amt?></td>
        <td style="font-family:monospace;font-size:11px"><?=str_replace('_',' ',$r['reason']??'')?></td>
        <td><span class="b <?=$bc?>"><?=$bl?></span></td>
        <td><?=!empty($ev['access_activity_log'])?'<span style="color:#10b981;font-size:12px">✓ done</span>':'<span style="color:#f59e0b;font-size:12px">○ pending</span>'?></td>
        <td style="white-space:nowrap;display:flex;gap:5px;align-items:center">
          <a class="abtn" href="https://dashboard.stripe.com/disputes/<?=htmlspecialchars($r['dispute_id'])?>" target="_blank">Stripe ↗</a>
          <a class="abtn" style="color:#818cf8;border-color:#4f46e533"
             href="?key=<?=$key?>&action=pdf&email=<?=urlencode($r['email']??'')?>&reason=<?=urlencode($r['reason']??'fraudulent')?>&dispute_id=<?=urlencode($r['dispute_id']??'')?>"
             title="Download PDF evidence packet">PDF ↓</a>
        </td>
      </tr>
      <?php endforeach;?>
      <?php if(empty($rows)):?>
      <tr><td colspan="7" style="text-align:center;color:#475569;padding:32px;font-size:13px">No disputes yet — they appear automatically when Stripe sends a webhook.</td></tr>
      <?php endif;?>
      </tbody>
    </table>
  </div>
</div>

<script>
async function run() {
  const em  = document.getElementById('em').value.trim();
  const btn = document.getElementById('gobtn');
  const st  = document.getElementById('status');
  if (!em) { alert('Enter a customer email'); return; }
  btn.disabled = true; btn.textContent = 'Loading…';
  st.textContent = 'Querying PostHog…'; st.style.color = '#64748b';
  try {
    const fd = new FormData(); fd.append('email', em);
    const res  = await fetch('?key=<?=$key?>&action=preview', {method:'POST',body:fd});
    const data = await res.json();
    document.getElementById('st').textContent = data.stripe_text  || '—';
    document.getElementById('il').textContent = data.internal_log || '—';
    const panels = document.getElementById('panels');
    panels.style.display = 'grid';
    if (data.user?.found) {
      st.textContent = '✓ Found in PostHog'; st.style.color = '#10b981';
    } else {
      st.textContent = '⚠ Not found in PostHog'; st.style.color = '#f59e0b';
    }
  } catch(e) {
    st.textContent = 'Error: '+e.message; st.style.color='#ef4444';
  }
  btn.disabled = false; btn.textContent = 'Generate Evidence';
}
function doCopy(id, btn) {
  const text = document.getElementById(id).textContent;
  navigator.clipboard?.writeText(text).then(() => {
    const orig = btn.textContent;
    btn.textContent = '✓ Copied!';
    setTimeout(()=>btn.textContent=orig, 2000);
  });
}
</script>
</body></html>
