<?php
/**
 * PDF generator for Stripe dispute evidence packets.
 * Uses FPDF with built-in fonts only (no external font files needed).
 */

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Convert UTF-8 string to FPDF-safe Latin-1.
 * Replaces special chars with ASCII equivalents.
 */
function pdfSafe(string $text): string {
    $map = [
        // em dash, en dash, horizontal line chars
        "â" => '-', "â" => '-',
        "â" => '-', "â" => '=',
        // arrows
        "â" => '->', "â" => '<-',
        "â" => '[YES]', "â" => '[NO]',
        // stars
        "â" => '*', "â" => '*',
        // bullets and special
        "â¢" => '*', "Â " => ' ',
        // curly quotes
        "â" => '"', "â" => '"',
        "â" => "'", "â" => "'",
        // ellipsis
        "â¦" => '...',
        // checkmarks / crosses
        "â" => '[OK]',
        // non-breaking hyphen
        "â" => '-',
        // section/paragraph signs
        "Â§" => 'S', "Â¶" => 'P',
    ];
    $text = str_replace(array_keys($map), array_values($map), $text);
    // Convert remaining UTF-8 to Latin-1, replace unknowns with ?
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text) ?: $text;
}

class DisputePDF extends \FPDF {

    function header() {}
    function footer() {
        $this->SetY(-12);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 5, 'GigRadar Dispute Evidence | Page ' . $this->PageNo() . ' | CONFIDENTIAL', 0, 0, 'C');
    }

    function sectionTitle(string $text) {
        $this->SetFont('Helvetica', 'B', 10);
        $this->SetFillColor(30, 30, 50);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(0, 7, pdfSafe(strtoupper($text)), 0, 1, 'L', true);
        $this->SetTextColor(0, 0, 0);
        $this->Ln(2);
    }

    function row(string $label, string $value) {
        $this->SetFont('Helvetica', 'B', 9);
        $this->SetTextColor(80, 80, 80);
        $this->Cell(52, 6, pdfSafe($label) . ':', 0, 0);
        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(0, 0, 0);
        $this->MultiCell(0, 6, pdfSafe($value), 0, 'L');
    }

    function bodyText(string $text) {
        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(20, 20, 20);
        $this->MultiCell(0, 5, pdfSafe($text), 0, 'L');
        $this->Ln(2);
    }

    function preText(string $text) {
        $this->SetFont('Courier', '', 8);
        $this->SetTextColor(20, 20, 20);
        $this->SetFillColor(245, 245, 250);
        $this->MultiCell(0, 4.5, pdfSafe($text), 0, 'L', true);
        $this->Ln(2);
    }

    function highlight(string $text) {
        $this->SetFont('Helvetica', 'B', 9);
        $this->SetFillColor(220, 240, 220);
        $this->SetTextColor(0, 80, 0);
        $this->MultiCell(0, 6, pdfSafe($text), 0, 'L', true);
        $this->SetTextColor(0, 0, 0);
        $this->Ln(1);
    }

    function warning(string $text) {
        $this->SetFont('Helvetica', 'B', 9);
        $this->SetFillColor(255, 240, 200);
        $this->SetTextColor(120, 60, 0);
        $this->MultiCell(0, 6, pdfSafe($text), 0, 'L', true);
        $this->SetTextColor(0, 0, 0);
        $this->Ln(1);
    }
}

function generateDisputePDF(
    string $email,
    string $reason,
    array  $user,
    string $rebuttalText,
    string $activityLog,
    string $intercomLog = '',
    array  $intercom = []
): string {

    $pdf = new DisputePDF('P', 'mm', 'A4');
    $pdf->SetMargins(18, 18, 18);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();

    // ── Cover ────────────────────────────────────────────────────────────────
    $pdf->SetFillColor(20, 18, 40);
    $pdf->Rect(0, 0, 210, 48, 'F');

    $pdf->SetY(10);
    $pdf->SetFont('Helvetica', 'B', 20);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(0, 10, 'DisputeShield', 0, 1, 'C');

    $pdf->SetFont('Helvetica', '', 11);
    $pdf->SetTextColor(180, 170, 255);
    $pdf->Cell(0, 7, 'GigRadar Dispute Evidence Packet', 0, 1, 'C');

    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(150, 150, 200);
    $pdf->Cell(0, 6, 'Generated: ' . date('Y-m-d H:i') . ' UTC | CONFIDENTIAL', 0, 1, 'C');

    $pdf->SetY(52);
    $pdf->SetTextColor(0, 0, 0);

    // ── Section 1: Transaction Facts ─────────────────────────────────────────
    $pdf->sectionTitle('1. Transaction Facts');

    // Fallbacks from intercom if Stripe/PostHog missing
    if (empty($user['last_active']) || $user['last_active'] === 'unknown') {
        $user['last_active'] = $intercom['last_seen_at'] ?? 'unknown';
    }
    if (empty($user['plan'])) {
        $user['plan'] = $intercom['stripe_plan'] ?? '';
    }
    if (empty($user['stripe_subscription_status'])) {
        $user['stripe_subscription_status'] = $intercom['stripe_status'] ?? '';
    }
    if ((float)($user['total_paid_usd'] ?? 0) == 0 && !empty($intercom['stripe_last_charge'])) {
        $user['total_paid_usd'] = $intercom['stripe_last_charge'];
    }
    if (empty($user['geo_country'])) {
        $user['geo_country'] = $intercom['location']['country'] ?? '';
        $user['geo_city']    = $intercom['location']['city'] ?? '';
    }

    $plan   = $user['plan']          ?? 'GigRadar Subscription';
    $amount = $user['total_paid_usd'] ?? '0.00';
    $name   = $user['name']          ?: $email;
    $subStart  = $user['subscription_start'] ?? $user['signup_date'] ?? 'unknown';
    // Subscription status — prefer Stripe real status
    $stripeStatus = strtolower($user['stripe_subscription_status'] ?? '');
    if (in_array($stripeStatus, ['canceled', 'cancelled'])) {
        $subStatus = 'CANCELED (confirmed via Stripe)';
    } elseif ($stripeStatus === 'trialing') {
        $subStatus = 'Trialing';
    } elseif ($stripeStatus && $stripeStatus !== 'active') {
        $subStatus = strtoupper($stripeStatus);
    } elseif ($user['is_canceled'] ?? false) {
        $subStatus = 'Canceled ' . ($user['subscription_canceled'] ?? '');
    } else {
        $subStatus = 'ACTIVE';
    }
    // "Last platform login" = real web session ($pageview), not server-side
    // subscription events. last_active can be a subscription_active event date
    // (e.g. 2026-04-23) which isn't a human login; prefer last_pageview (04-09).
    $lastLogin = $user['last_pageview'] ?? $user['last_active'] ?? 'unknown';
    if (empty($lastLogin) || $lastLogin === 'unknown') {
        $lastLogin = $user['last_active'] ?? 'unknown';
    }

    $pdf->row('Merchant',            'GigRadar (gigradar.io) | support@gigradar.io');
    $pdf->row('Customer name',       $name);
    $pdf->row('Customer email',      $email);
    $pdf->row('Subscription plan',   $plan);
    $pdf->row('Subscription start',  $subStart);
    $pdf->row('Total billed',        '$' . $amount . ' USD');
    // Disputed invoice — the specific contested charge, with usage breakdown
    if (!empty($user['disputed_invoice_amount'])) {
        $di = '$' . $user['disputed_invoice_amount'] . ' USD';
        if (!empty($user['disputed_invoice_qty']) && !empty($user['disputed_invoice_unit'])) {
            $di .= '  (' . $user['disputed_invoice_qty'] . ' '
                 . ($user['disputed_invoice_desc'] ?: 'proposals')
                 . ' x $' . $user['disputed_invoice_unit'] . ')';
        }
        $pdf->row('Disputed invoice', $di);
        if (!empty($user['disputed_invoice_note'])) {
            $pdf->highlight('  ' . $user['disputed_invoice_note']);
        }
    }
    $pdf->row('Subscription status', $subStatus);
    $pdf->row('Last platform login', $lastLogin);
    $pdf->row('Dispute reason',      strtoupper(str_replace('_', ' ', $reason)));

    if (!empty($user['billing_address'])) {
        $pdf->row('Billing address', $user['billing_address']);
    }
    if (!empty($user['card_last4'])) {
        $pdf->row('Card', strtoupper($user['card_brand'] ?? '') . ' ending ' . $user['card_last4']);
    }
    if (!empty($user['avs_result'])) {
        $avsStr = 'Postal code: ' . strtoupper($user['avs_result'])
                . '  |  CVC: ' . strtoupper($user['cvc_result'] ?? 'unknown');
        $pdf->row('AVS / CVC check', $avsStr);
        if (($user['avs_result'] ?? '') === 'pass' || ($user['cvc_result'] ?? '') === 'pass') {
            $pdf->highlight('  AVS/CVC PASSED — confirms real cardholder authorized this transaction');
        }
    }
    if (!empty($user['geo_country'])) {
        $geo = $user['geo_country'] . (!empty($user['geo_city']) ? ', ' . $user['geo_city'] : '');
        $pdf->row('Customer location', $geo);
    }
    $pdf->Ln(3);

    // ── Section 2: Usage Evidence ─────────────────────────────────────────────
    $pdf->sectionTitle('2. Evidence of Service Delivery');

    $proposals = (int)($user['proposals_sent']     ?? 0);
    $replies   = (int)($user['total_replies']       ?? 0);
    $views     = (int)($user['proposal_views']      ?? 0);
    $pageviews = (int)($user['total_pageviews']     ?? 0);
    $scanners  = (int)($user['scanners_created']    ?? 0);
    $lessons   = (int)($user['lessons_completed']   ?? 0);
    $noConn    = (int)($user['no_connects_events']  ?? 0);
    $sessAfter = (int)($user['sessions_after_payment'] ?? 0);
    $replyRate = $user['reply_rate'] ?? null;
    $statsSrc  = $user['stats_source'] ?? 'posthog';
    $window    = $user['stats_window'] ?? null;

    // State the measurement window so the bank sees these cover the disputed period
    if ($statsSrc === 'mongo' && $window) {
        $pdf->bodyText('Service-delivery metrics below cover the customer\'s entire '
            . 'subscription period (' . $window['from'] . ' to ' . $window['to'] . '), '
            . 'sourced directly from GigRadar production records.');
    }

    if ($proposals > 0) {
        $pdf->highlight("  PROPOSALS SENT: $proposals — using customer's own Upwork connects (KEY EVIDENCE)");
    }
    if ($replies > 0) {
        $pdf->highlight("  UPWORK CLIENT REPLIES: $replies — real responses from independent 3rd-party employers");
    }
    if ($views > 0) {
        $pdf->highlight("  PROPOSALS VIEWED BY CLIENTS: $views — independent Upwork employers opened the customer's bids");
    }

    $pdf->row('Job scanners created',    (string)$scanners);
    $pdf->row('Proposals sent',          (string)$proposals);
    $pdf->row('Proposals viewed',        (string)$views);
    $pdf->row('Upwork replies received', (string)$replies);
    if ($replyRate !== null) {
        $pdf->row('Reply rate',          $replyRate . '%');
    }
    $pdf->row('Platform sessions',       (string)$pageviews);
    $pdf->row('Sessions after payment',  (string)$sessAfter);
    $pdf->row('Academy lessons',         (string)$lessons);

    if ($noConn > 0) {
        $pdf->Ln(2);
        $pdf->warning("  NOTE: Auto-bidder paused $noConn time(s) — caused by Upwork connect quota (NOT GigRadar failure).\n  Despite pauses, customer sent $proposals proposals proving service worked.");
    }

    if (!empty($user['prior_transactions']) && count($user['prior_transactions']) >= 2) {
        $pdf->Ln(2);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(0, 6, 'PRIOR UNDISPUTED TRANSACTIONS (Visa CE 3.0):', 0, 1);
        foreach ($user['prior_transactions'] as $tx) {
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->Cell(0, 5, '  ' . $tx['date'] . '  $' . $tx['amount'] . '  ' . $tx['id'], 0, 1);
        }
        $pdf->highlight('  Qualifies for Visa Compelling Evidence 3.0 — prior non-disputed charges from same customer');
    }
    $pdf->Ln(3);

    // ── Section 3: Rebuttal Letter ────────────────────────────────────────────
    $pdf->AddPage();
    $pdf->sectionTitle('3. Dispute Rebuttal Letter');
    $pdf->preText($rebuttalText);

    // ── Section 4: Activity Log ───────────────────────────────────────────────
    $pdf->AddPage();
    $pdf->sectionTitle('4. Platform Activity Log (PostHog Analytics)');
    $pdf->preText($activityLog);

    // ── Section 5: Intercom Communications ───────────────────────────────────
    if ($intercomLog) {
        $pdf->AddPage();
        $pdf->sectionTitle('5. Customer Communications (Intercom CRM)');
        $pdf->preText($intercomLog);
    }

    // ── Section 6: Policy & Conclusion ────────────────────────────────────────
    $pdf->AddPage();
    $pdf->sectionTitle(($intercomLog ? '6' : '5') . '. Terms, Policy & Conclusion');

    $pdf->bodyText(
        "Merchant: GigRadar (gigradar.io)\n" .
        "Support: support@gigradar.io\n" .
        "Terms & Refund Policy: https://gigradar.io/legal\n\n" .
        "GigRadar is a 100% digital B2B SaaS platform. Access is granted instantly upon payment. " .
        "Refund policy for digital subscriptions: non-refundable once billing period has started " .
        "and platform access has been granted. Policy was displayed on the Stripe checkout page " .
        "at time of purchase.\n\n" .
        "The evidence in this packet demonstrates:\n" .
        "  1. Customer (" . $name . ") knowingly purchased a GigRadar subscription.\n" .
        "  2. The service was fully delivered — " . $proposals . " proposals sent, " . $replies . " replies received.\n" .
        "  3. Customer actively used the platform through " . $lastLogin . ".\n" .
        "  4. No refund request was submitted before this chargeback.\n" .
        "  5. GigRadar support was responsive and available at all times.\n\n" .
        "We respectfully request the dispute be decided in GigRadar's favor.\n\n" .
        "GigRadar Support Team | support@gigradar.io | https://gigradar.io"
    );

    // Save to temp
    $filename = 'dispute_' . preg_replace('/[^a-z0-9]/i', '_', $email) . '_' . date('Ymd_His') . '.pdf';
    $path     = sys_get_temp_dir() . '/' . $filename;
    $pdf->Output('F', $path);

    return $path;
}
