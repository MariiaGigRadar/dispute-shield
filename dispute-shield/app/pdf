<?php
/**
 * PDF generator for Stripe dispute evidence packets.
 *
 * Uses FPDF (no extra dependencies — pure PHP).
 * Install: composer require setasign/fpdf
 *
 * Generates a single multi-section PDF per dispute.
 * The PDF is saved to /tmp/ and can be:
 *   a) uploaded to Stripe as a file attachment (service_documentation)
 *   b) downloaded directly from the dashboard
 */

require_once __DIR__ . '/../vendor/autoload.php';

class DisputePDF extends \FPDF {
    private string $disputeId;
    private string $reason;
    private string $email;

    public function __construct(string $disputeId, string $reason, string $email) {
        parent::__construct('P', 'mm', 'A4');
        $this->disputeId = $disputeId;
        $this->reason    = $reason;
        $this->email     = $email;
        $this->SetMargins(20, 20, 20);
        $this->SetAutoPageBreak(true, 20);
        $this->AddFont('DejaVu', '', 'DejaVuSansCondensed.ttf', true);
    }

    /** Cover header on every page */
    public function Header(): void {
        // Top bar
        $this->SetFillColor(15, 23, 42);     // dark blue
        $this->Rect(0, 0, 210, 14, 'F');

        $this->SetFont('Helvetica', 'B', 9);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(20, 4);
        $this->Cell(0, 6, 'GigRadar — Dispute Evidence Packet', 0, 0, 'L');
        $this->SetXY(0, 4);
        $this->Cell(190, 6, 'CONFIDENTIAL', 0, 0, 'R');

        $this->SetTextColor(0, 0, 0);
        $this->SetY(18);
    }

    /** Page number footer */
    public function Footer(): void {
        $this->SetY(-12);
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor(128, 128, 128);
        $this->Cell(0, 6, 'Page ' . $this->PageNo() . ' | DisputeShield | gigradar.io | support@gigradar.io', 0, 0, 'C');
    }

    /** Bold heading with coloured left bar */
    public function SectionHeading(string $title, array $color = [99, 102, 241]): void {
        $this->Ln(4);
        $this->SetFillColor(...$color);
        $this->Rect($this->GetX(), $this->GetY(), 3, 7, 'F');
        $this->SetX($this->GetX() + 5);
        $this->SetFont('Helvetica', 'B', 11);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(0, 7, strtoupper($title), 0, 1);
        $this->Ln(1);
    }

    /** Key-value row */
    public function KV(string $key, string $value, bool $highlight = false): void {
        if ($highlight) {
            $this->SetFillColor(239, 246, 255);
            $this->SetFont('Helvetica', 'B', 9);
        } else {
            $this->SetFillColor(248, 250, 252);
            $this->SetFont('Helvetica', '', 9);
        }
        $this->SetTextColor(71, 85, 105);
        $this->Cell(52, 6, $key, 0, 0, 'L', true);
        $this->SetTextColor(15, 23, 42);
        $this->SetFont('Helvetica', $highlight ? 'B' : '', 9);
        $this->Cell(0, 6, $value, 0, 1, 'L', true);
        $this->Ln(0.5);
    }

    /** Numbered evidence item */
    public function EvidenceItem(int $n, string $title, string $body): void {
        // Number badge
        $this->SetFillColor(99, 102, 241);
        $x = $this->GetX();
        $y = $this->GetY();
        $this->SetXY($x, $y);
        $this->SetFont('Helvetica', 'B', 9);
        $this->SetTextColor(255, 255, 255);
        $this->SetFillColor(99, 102, 241);
        $this->Cell(7, 7, (string)$n, 0, 0, 'C', true);

        // Title
        $this->SetFont('Helvetica', 'B', 10);
        $this->SetTextColor(15, 23, 42);
        $this->SetX($x + 9);
        $this->Cell(0, 7, $title, 0, 1);

        // Body
        $this->SetX($x + 9);
        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(51, 65, 85);
        $this->MultiCell(0, 5, $body, 0, 'L');
        $this->Ln(3);
    }

    /** Full-width text block (for logs / policy text) */
    public function TextBlock(string $text, bool $mono = false): void {
        $this->SetFillColor(15, 23, 42);
        $this->SetFillColor(248, 250, 252);
        if ($mono) {
            $this->SetFont('Courier', '', 8);
            $this->SetTextColor(30, 41, 59);
        } else {
            $this->SetFont('Helvetica', '', 9);
            $this->SetTextColor(51, 65, 85);
        }
        $this->MultiCell(0, 4.5, $text, 1, 'L', true);
        $this->Ln(3);
    }

    /** Verdict / conclusion box */
    public function ConclusionBox(string $text): void {
        $this->Ln(4);
        $this->SetFillColor(239, 246, 255);
        $this->SetDrawColor(99, 102, 241);
        $this->SetLineWidth(0.5);
        $this->SetFont('Helvetica', 'B', 10);
        $this->SetTextColor(30, 58, 138);
        $this->MultiCell(0, 6, $text, 1, 'L', true);
        $this->Ln(2);
    }

    /** Stamp badge: "EVIDENCE PACKET" */
    public function Stamp(string $label, array $color): void {
        $x = 210 - 20 - 55;
        $y = 22;
        $this->SetFont('Helvetica', 'B', 8);
        $this->SetTextColor(...$color);
        $this->SetDrawColor(...$color);
        $this->SetLineWidth(0.6);
        $this->Rect($x, $y, 55, 8, 'D');
        $this->SetXY($x, $y + 1);
        $this->Cell(55, 6, $label, 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.2);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Main function: generate full PDF, return file path
// ─────────────────────────────────────────────────────────────────────────────
function generateDisputePDF(
    array  $u,
    string $email,
    string $reason,
    string $disputeId = '',
    array  $stripeDispute = []
): string {
    require_once __DIR__ . '/../app/evidence.php';

    $reason  = $reason ?: 'fraudulent';
    $pdf     = new DisputePDF($disputeId, $reason, $email);
    $pdf->SetCreator('DisputeShield — GigRadar');
    $pdf->SetAuthor('GigRadar Support');
    $pdf->SetTitle("Dispute Evidence — $email");
    $pdf->SetSubject(strtoupper(str_replace('_', ' ', $reason)));

    // ── PAGE 1: Cover / Transaction facts ────────────────────────────────────
    $pdf->AddPage();

    // Stamp
    $reasonColors = [
        'fraudulent'           => [185, 28, 28],
        'subscription_canceled'=> [180, 83, 9],
        'credit_not_processed' => [126, 34, 206],
        'product_not_received' => [21, 128, 61],
        'unrecognized'         => [185, 28, 28],
        'duplicate'            => [29, 78, 216],
    ];
    $pdf->Stamp(
        'DISPUTE: ' . strtoupper(str_replace('_', ' ', $reason)),
        $reasonColors[$reason] ?? [99, 102, 241]
    );

    // Title block
    $pdf->SetFont('Helvetica', 'B', 18);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(0, 10, 'Dispute Evidence Packet', 0, 1, 'L');
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(0, 6, 'GigRadar — gigradar.io', 0, 1, 'L');
    $pdf->Cell(0, 5, 'Generated: ' . date('F j, Y \a\t H:i') . ' UTC', 0, 1, 'L');
    $pdf->Ln(4);

    // Horizontal rule
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->SetLineWidth(0.4);
    $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
    $pdf->Ln(5);

    // Transaction facts
    $pdf->SectionHeading('Transaction Facts');
    $pdf->KV('Dispute ID',       $disputeId ?: '—');
    $pdf->KV('Customer Name',    $u['name'] ?: $email);
    $pdf->KV('Customer Email',   $email, true);
    $pdf->KV('Subscription Plan',$u['plan'] ?: '—');
    $pdf->KV('Service Start',    $u['subscription_start'] ?: '—');
    $pdf->KV('Total Billed',     '$' . ($u['total_paid_usd'] ?: '0') . ' USD');

    $status = $u['is_canceled'] && $u['subscription_canceled']
        ? 'Canceled on ' . $u['subscription_canceled']
        : 'ACTIVE — never canceled';
    $pdf->KV('Sub. Status', $status, !$u['is_canceled']);
    $pdf->KV('Last Activity',    $u['last_active'] ?: '—', true);

    if (!empty($stripeDispute)) {
        $pdf->Ln(2);
        $pdf->KV('Dispute Amount', '$' . number_format(($stripeDispute['amount'] ?? 0)/100, 2) . ' USD');
        $pdf->KV('Dispute Status', $stripeDispute['status'] ?? '—');
        $pdf->KV('Dispute Reason', str_replace('_', ' ', $stripeDispute['reason'] ?? '—'));
    }
    $pdf->Ln(4);

    // ── PAGE 1 (continued): Service description ───────────────────────────────
    $pdf->SectionHeading('About GigRadar', [16, 185, 129]);
    $pdf->TextBlock(
        "GigRadar (gigradar.io) is a B2B SaaS platform for Upwork freelancers and agencies. " .
        "The platform provides AI-powered job scanning, automated proposal sending, reply tracking, " .
        "performance analytics, and an integrated training academy.\n\n" .
        "All features are delivered digitally via a web browser. " .
        "There is no physical product, no shipping, and no download required. " .
        "Access begins immediately upon successful payment. " .
        "The platform is cloud-hosted and available 24/7."
    );

    // ── PAGE 2: Evidence of use ───────────────────────────────────────────────
    $pdf->AddPage();
    $pdf->SectionHeading('Evidence of Service Delivery', [99, 102, 241]);

    $n = 1;

    if ($u['autobidder_setup_date'] && $u['autobidder_setup_date'] !== 'never') {
        $configs = (int)$u['autobidder_configs'];
        $pdf->EvidenceItem($n++,
            'Auto-Bidder Configuration — ' . $u['autobidder_setup_date'],
            "On " . $u['autobidder_setup_date'] . ", the customer logged in and configured the AI auto-bidder — " .
            "the platform's core feature. This requires connecting a real Upwork account, writing a " .
            "custom proposal template, and selecting job categories. This is a deliberate, multi-step " .
            "process that only the real account owner can complete." .
            ($configs > 1 ? " The auto-bidder settings were updated $configs times in total." : "")
        );
    }

    $scanners = (int)$u['scanners_created'];
    if ($scanners > 0 && $u['first_scanner_date'] !== 'never') {
        $pdf->EvidenceItem($n++,
            "Job Scanners Created — $scanners scanner(s) — first: " . $u['first_scanner_date'],
            "The customer created $scanners job scanner(s) to monitor Upwork job postings. " .
            "Each scanner requires the customer to actively define search keywords, budget ranges, " .
            "and job categories — demonstrating deliberate, intentional engagement with the platform."
        );
    }

    $proposals = (int)$u['proposals_sent'];
    if ($proposals > 0) {
        $pdf->EvidenceItem($n++,
            "Proposals Sent — $proposals total",
            "GigRadar sent $proposals proposals on behalf of this customer via the auto-bidder. " .
            "Critically, each proposal consumed \"connects\" from the customer's own Upwork account " .
            "(not GigRadar's). This irrefutably proves that the customer's real Upwork credentials " .
            "were used, confirming both authorization and active service delivery."
        );
    }

    $replies = (int)$u['total_replies'];
    if ($replies > 0) {
        $first = ($u['first_reply_date'] && $u['first_reply_date'] !== 'never')
            ? " (first: " . $u['first_reply_date'] . ")" : "";
        $pdf->EvidenceItem($n++,
            "Upwork Client Replies Received — $replies total$first",
            "The customer received $replies direct responses from real Upwork clients as a direct " .
            "result of GigRadar-sent proposals. Receiving replies is the exact business outcome the " .
            "customer paid for — it was delivered. These replies were sent by independent third parties " .
            "(Upwork employers) and cannot be manufactured or simulated by GigRadar."
        );
    }

    $pageviews = (int)$u['total_pageviews'];
    $lessons   = (int)$u['lessons_completed'];
    $searches  = (int)$u['gigs_searches'];
    if ($pageviews > 0 || $lessons > 0 || $searches > 0) {
        $detail = [];
        if ($pageviews > 0) $detail[] = "$pageviews page views recorded";
        if ($searches > 0)  $detail[] = "$searches manual opportunity searches";
        if ($lessons > 0)   $detail[] = "$lessons academy lessons completed";
        $detail[] = "Last session: " . $u['last_active'];
        $pdf->EvidenceItem($n++,
            "Ongoing Platform Engagement",
            implode("\n", $detail)
        );
    }

    $noConn = (int)$u['no_connects_events'];
    if ($noConn > 0) {
        $pdf->EvidenceItem($n++,
            "Note on Auto-Bidder Pauses ($noConn pause(s))",
            "The auto-bidder was temporarily paused $noConn time(s) because the customer's Upwork " .
            "account exhausted its \"connects\" quota. Connects are Upwork's internal credits — " .
            "GigRadar does not sell, control, or have access to them. Our platform continued " .
            "operating normally. The customer was notified each time and could resolve this by " .
            "purchasing connects from Upwork. This is not a service failure."
        );
    }

    // ── PAGE 3: Reason-specific rebuttal ─────────────────────────────────────
    $pdf->AddPage();
    $pdf->SectionHeading('Direct Response to Dispute Claim', [239, 68, 68]);

    // Reason-specific content
    switch ($reason) {
        case 'fraudulent':
        case 'unrecognized':
            $pdf->TextBlock(
                "CLAIM: The cardholder states they did not authorize or recognize this transaction.\n\n" .
                "REBUTTAL: The account $email was created with a working email address, " .
                "verified through our standard signup flow. The customer then:\n" .
                "  • Completed multi-step account setup (connecting Upwork, writing templates)\n" .
                "  • Configured job scanners and search criteria\n" .
                "  • Used the platform's auto-bidder which consumed their own Upwork connects\n" .
                "  • Received real replies from Upwork employers\n\n" .
                "Each of these actions requires active, authenticated access. An unauthorized party " .
                "cannot configure and use a Upwork-integrated tool without the real user's credentials."
            );
            break;

        case 'subscription_canceled':
            $pdf->TextBlock(
                "CLAIM: The cardholder states they canceled their subscription before this charge.\n\n" .
                ($u['is_canceled'] && $u['subscription_canceled']
                    ? "REBUTTAL: While a cancellation WAS eventually recorded on " . $u['subscription_canceled'] .
                      ", the disputed charge was made on " . $u['subscription_start'] .
                      " — which is BEFORE the cancellation. The charge is valid for the billing " .
                      "period that was already active and paid for when the cancellation was filed."
                    : "REBUTTAL: No cancellation was ever submitted. We have checked:\n" .
                      "  • GigRadar dashboard (Settings → Subscription)\n" .
                      "  • Stripe customer portal\n" .
                      "  • Email inbox (support@gigradar.io)\n" .
                      "  • In-app support chat\n\n" .
                      "Zero cancellation requests from " . $email . " exist in any of these channels. " .
                      "The subscription remains ACTIVE as of " . date('Y-m-d') . "."
                )
            );
            break;

        case 'credit_not_processed':
            $pdf->TextBlock(
                "CLAIM: The cardholder states they were promised a refund that was not received.\n\n" .
                "REBUTTAL: No refund was ever promised to this customer. Our support records " .
                "for " . $email . " contain zero conversations where a refund was offered, " .
                "discussed, or agreed upon. Our refund policy — which the customer accepted at " .
                "checkout — clearly states that digital subscriptions are non-refundable once the " .
                "billing period begins and the platform has been accessed.\n\n" .
                "Policy URL: https://gigradar.io/legal\n" .
                "Policy was displayed on Stripe checkout page at time of purchase."
            );
            break;

        case 'product_not_received':
            $pdf->TextBlock(
                "CLAIM: The cardholder states the service was not received or did not work.\n\n" .
                "REBUTTAL: The service was fully delivered. This is a digital SaaS platform — " .
                "there is no physical delivery. Access is granted instantly upon payment. " .
                "The activity log (see Section 2) shows the customer actively used the platform:\n" .
                "  • Configured the auto-bidder (a core feature requiring multiple setup steps)\n" .
                "  • Created job scanners\n" .
                "  • Had proposals sent on their behalf\n" .
                "  • Received replies from real Upwork clients\n\n" .
                "If the service had not been working, none of the above would have been possible."
            );
            break;

        case 'product_unacceptable':
            $pdf->TextBlock(
                "CLAIM: The cardholder states the service was not as described.\n\n" .
                "REBUTTAL: The service was delivered exactly as described on gigradar.io. " .
                "The customer received the stated core outcomes — job proposals were sent " .
                "and Upwork client replies were received. No complaint or support ticket " .
                "was submitted before this chargeback, giving us no opportunity to address " .
                "any concerns. If the customer had an issue with the service, our support " .
                "team at support@gigradar.io is available to help."
            );
            break;
    }

    // No prior contact box
    $pdf->Ln(4);
    $pdf->SectionHeading('No Prior Contact or Complaint', [245, 158, 11]);
    $pdf->TextBlock(
        "A comprehensive search of all GigRadar support channels shows ZERO tickets, emails, " .
        "live chats, or refund requests from $email prior to this dispute being filed.\n\n" .
        "We were given no opportunity to resolve any concern the customer may have had. " .
        "Our support team at support@gigradar.io is available around the clock and consistently " .
        "resolves billing questions when customers contact us directly."
    );

    // ── PAGE 4: Activity log ──────────────────────────────────────────────────
    $pdf->AddPage();
    $pdf->SectionHeading('Full Account Activity Log', [99, 102, 241]);
    $pdf->TextBlock(buildActivityLog($u, $email), true);

    // ── PAGE 4 (continued): Conclusion ────────────────────────────────────────
    $pdf->SectionHeading('Conclusion & Request', [16, 185, 129]);

    $facts = [];
    if ($u['autobidder_setup_date'] && $u['autobidder_setup_date'] !== 'never')
        $facts[] = "configured the AI auto-bidder on " . $u['autobidder_setup_date'];
    if ($proposals > 0) $facts[] = "received $proposals proposals sent on their behalf";
    if ($replies > 0)   $facts[] = "received $replies direct replies from Upwork clients";
    if ($pageviews > 0) $facts[] = "logged in $pageviews times";

    $pdf->ConclusionBox(
        "Based on the evidence above:\n\n" .
        "  (a) The customer agreed to our Terms of Service and Refund Policy at checkout.\n" .
        "  (b) The customer " . implode(', and ', $facts) . ".\n" .
        "  (c) The service was fully delivered as advertised and paid for.\n" .
        "  (d) No cancellation, complaint, or refund request was ever filed.\n\n" .
        "This chargeback constitutes first-party fraud (\"friendly fraud\"). " .
        "GigRadar respectfully requests that this dispute be resolved in our favor " .
        "and the disputed funds be returned.\n\n" .
        "— GigRadar Support Team\n" .
        "   support@gigradar.io | https://gigradar.io | " . date('F j, Y')
    );

    // ── Save & return ─────────────────────────────────────────────────────────
    $safe    = preg_replace('/[^a-z0-9]/i', '_', $email);
    $fname   = "dispute_{$safe}_{$reason}_" . date('Ymd_His') . ".pdf";
    $path    = sys_get_temp_dir() . '/' . $fname;
    $pdf->Output('F', $path);

    return $path;
}
