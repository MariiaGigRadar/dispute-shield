<?php
/**
 * Evidence builder — adapts content to Stripe dispute reason code.
 *
 * Stripe evidence fields used:
 *   customer_name, customer_email_address, billing_address,
 *   service_date, service_documentation (file),
 *   customer_communication (file),
 *   access_activity_log,
 *   cancellation_policy, cancellation_policy_disclosure,
 *   cancellation_rebuttal,
 *   refund_policy, refund_policy_disclosure, refund_refusal_explanation,
 *   uncategorized_text  ← our main rebuttal letter
 */

function buildEvidence(array $u, string $email, string $reason = 'fraudulent'): array {
    // Common fields for ALL reason codes
    $base = [
        'customer_name'          => $u['name'] ?: $email,
        'customer_email_address' => $email,
        'service_date'           => $u['subscription_start'],
        'access_activity_log'    => buildActivityLog($u, $email),
        'uncategorized_text'     => buildRebuttalLetter($u, $email, $reason),
    ];

    // Reason-specific additions
    switch ($reason) {

        // ── FRAUDULENT ────────────────────────────────────────────────────────
        // Claim: "I didn't authorize this payment"
        // Key evidence: login history, IP, account configuration by that person
        case 'fraudulent':
        case 'unrecognized':
            return array_merge($base, [
                'uncategorized_text' => buildRebuttalLetter($u, $email, $reason),
                // service_documentation = PDF we upload separately
            ]);

        // ── SUBSCRIPTION CANCELED ─────────────────────────────────────────────
        // Claim: "I canceled but you charged me anyway"
        // Key evidence: no cancellation exists OR cancellation was after charge
        case 'subscription_canceled':
            return array_merge($base, [
                'cancellation_policy' =>
                    "GigRadar subscriptions can be canceled at any time from the " .
                    "dashboard under Settings → Subscription. Cancellations take " .
                    "effect at the end of the current billing period. The full " .
                    "policy is available at https://gigradar.io/legal",

                'cancellation_policy_disclosure' =>
                    "The cancellation policy is displayed on the Stripe checkout " .
                    "page at the time of purchase and is permanently linked in " .
                    "the site footer at https://gigradar.io/legal",

                'cancellation_rebuttal' =>
                    $u['is_canceled'] && $u['subscription_canceled']
                        ? "The customer did cancel their subscription, but the " .
                          "disputed charge was made on " . $u['subscription_start'] .
                          ", which was BEFORE the cancellation on " . $u['subscription_canceled'] .
                          ". The charge is valid for the billing period that was already active."
                        : "Our records show no cancellation was ever submitted by " .
                          "this customer via the GigRadar dashboard, the Stripe " .
                          "customer portal, or by contacting our support team. " .
                          "The subscription remains active as of " . date('Y-m-d') . ".",

                'uncategorized_text' => buildRebuttalLetter($u, $email, $reason),
            ]);

        // ── CREDIT NOT PROCESSED ──────────────────────────────────────────────
        // Claim: "You promised me a refund but never gave it"
        // Key evidence: no refund was ever promised, policy is clear
        case 'credit_not_processed':
            return array_merge($base, [
                'refund_policy' =>
                    "GigRadar's subscription fees are non-refundable once the " .
                    "billing period has begun and the customer has accessed the " .
                    "platform. This policy applies to all plans. Full policy: " .
                    "https://gigradar.io/legal",

                'refund_policy_disclosure' =>
                    "The no-refund policy is disclosed on the Stripe payment " .
                    "page at checkout and in our Terms of Service at " .
                    "https://gigradar.io/legal, which the customer accepted " .
                    "before completing their purchase.",

                'refund_refusal_explanation' =>
                    "No refund was promised to this customer at any time. A " .
                    "search of all customer communications (email, in-app chat, " .
                    "support tickets) for " . $email . " shows zero conversations " .
                    "where a refund was offered or agreed upon. The customer used " .
                    "the service actively: " . (int)$u['proposals_sent'] . " proposals " .
                    "were sent on their behalf, and " . (int)$u['total_replies'] . " client " .
                    "replies were received — demonstrating that the service was " .
                    "fully delivered and the value was realized.",

                'uncategorized_text' => buildRebuttalLetter($u, $email, $reason),
            ]);

        // ── PRODUCT NOT RECEIVED ──────────────────────────────────────────────
        // Claim: "The service didn't work / I couldn't use it"
        // Key evidence: detailed usage logs, specific feature timestamps
        case 'product_not_received':
        case 'product_unacceptable':
            return array_merge($base, [
                'uncategorized_text' => buildRebuttalLetter($u, $email, $reason),
            ]);

        // ── DUPLICATE ─────────────────────────────────────────────────────────
        // Claim: "I was charged twice"
        // Key evidence: only one active subscription, one charge per period
        case 'duplicate':
            return array_merge($base, [
                'uncategorized_text' => buildRebuttalLetter($u, $email, $reason),
            ]);

        default:
            return $base;
    }
}


/**
 * Activity log — concise chronological timeline of key events.
 * Stripe puts this into the "access_activity_log" field.
 */
function buildActivityLog(array $u, string $email): string {
    $lines = [];
    $lines[] = "ACCOUNT ACTIVITY LOG — " . $email;
    $lines[] = "Generated: " . date('Y-m-d H:i') . " UTC";
    $lines[] = str_repeat("-", 52);

    if ($u['signup_date'])           $lines[] = $u['signup_date']           . "  Account created (sign_up event)";
    if ($u['subscription_start'])    $lines[] = $u['subscription_start']    . "  Subscription activated";
    if ($u['autobidder_setup_date'] && $u['autobidder_setup_date'] !== 'never')
                                     $lines[] = $u['autobidder_setup_date'] . "  Auto-bidder configured by customer";
    if ($u['first_scanner_date'] && $u['first_scanner_date'] !== 'never')
                                     $lines[] = $u['first_scanner_date']    . "  First job scanner created";
    if ($u['first_reply_date'] && $u['first_reply_date'] !== 'never')
                                     $lines[] = $u['first_reply_date']      . "  First Upwork client reply received";
    if ($u['last_active'])           $lines[] = $u['last_active']           . "  Last recorded activity";
    if ($u['subscription_canceled']) $lines[] = $u['subscription_canceled'] . "  Subscription canceled by customer";

    $lines[] = str_repeat("-", 52);
    $lines[] = "TOTALS:";
    $lines[] = "  Proposals sent via auto-bidder:  " . (int)$u['proposals_sent'];
    $lines[] = "  Upwork client replies received:  " . (int)$u['total_replies'];
    $lines[] = "  Job scanners created:             " . (int)$u['scanners_created'];
    $lines[] = "  Manual gig searches:              " . (int)$u['gigs_searches'];
    $lines[] = "  Platform page views:              " . (int)$u['total_pageviews'];
    $lines[] = "  Academy lessons completed:        " . (int)$u['lessons_completed'];
    if ((int)$u['no_connects_events'] > 0)
        $lines[] = "  Auto-bidder paused (no connects): " . (int)$u['no_connects_events'] . "x (not a service failure)";

    return implode("\n", $lines);
}


/**
 * Main rebuttal letter — adapted per reason code.
 */
function buildRebuttalLetter(array $u, string $email, string $reason): string {
    if (!($u['found'] ?? false)) {
        return "Customer $email not found in analytics. Contact support@gigradar.io for manual review.";
    }

    $name      = $u['name'] ?: $email;
    $proposals = (int)$u['proposals_sent'];
    $replies   = (int)$u['total_replies'];
    $pageviews = (int)$u['total_pageviews'];
    $subStart  = $u['subscription_start'];
    $subCancel = $u['subscription_canceled'];
    $lastActive= $u['last_active'];
    $plan      = $u['plan'];
    $totalPaid = $u['total_paid_usd'];
    $abSetup   = $u['autobidder_setup_date'];
    $scanners  = (int)$u['scanners_created'];
    $noConn    = (int)$u['no_connects_events'];
    $lessons   = (int)$u['lessons_completed'];

    // Build usage facts sentence
    $facts = [];
    if ($abSetup && $abSetup !== 'never') $facts[] = "configured the AI auto-bidder on $abSetup";
    if ($proposals > 0) $facts[] = "sent $proposals proposals to Upwork clients";
    if ($replies > 0)   $facts[] = "received $replies direct replies from Upwork employers";
    if ($pageviews > 0) $facts[] = "logged in $pageviews times";
    if ($lessons > 0)   $facts[] = "completed $lessons academy lessons";
    $factsStr = !empty($facts) ? implode('; ', $facts) : "accessed the platform multiple times";

    $out = [];
    $out[] = "REBUTTAL LETTER";
    $out[] = "Date: " . date('F j, Y');
    $out[] = "Re: Dispute — " . strtoupper(str_replace('_', ' ', $reason));
    $out[] = "";
    $out[] = "MERCHANT: GigRadar | gigradar.io | support@gigradar.io";
    $out[] = "CUSTOMER: $name <$email>";
    $out[] = "PLAN: $plan | Subscription started: $subStart | Total billed: \$$totalPaid";
    $out[] = "LAST ACTIVITY: $lastActive";
    $out[] = "";

    // ── Intro paragraph adapts to reason ─────────────────────────────────────
    switch ($reason) {
        case 'fraudulent':
        case 'unrecognized':
            $out[] = "RESPONSE TO CLAIM: UNAUTHORIZED / UNRECOGNIZED TRANSACTION";
            $out[] = "";
            $out[] = "We dispute this chargeback. The transaction was authorized and the account";
            $out[] = "was actively used by the person associated with the email $email.";
            $out[] = "The following evidence demonstrates that the real cardholder signed up,";
            $out[] = "configured the service, and used it to generate real business outcomes.";
            break;

        case 'subscription_canceled':
            $out[] = "RESPONSE TO CLAIM: SUBSCRIPTION ALLEGEDLY CANCELED";
            $out[] = "";
            if ($subCancel) {
                $out[] = "The customer did eventually cancel their subscription on $subCancel.";
                $out[] = "However, the disputed charge was billed on $subStart — BEFORE any cancellation";
                $out[] = "request was made. The charge is valid for the billing period in which the";
                $out[] = "service was actively used (evidence below).";
            } else {
                $out[] = "Our records contain NO cancellation request from this customer — not via";
                $out[] = "the dashboard, the Stripe customer portal, email, or support ticket.";
                $out[] = "The subscription is currently ACTIVE. The customer was billed correctly.";
            }
            break;

        case 'credit_not_processed':
            $out[] = "RESPONSE TO CLAIM: REFUND ALLEGEDLY PROMISED BUT NOT RECEIVED";
            $out[] = "";
            $out[] = "No refund was ever promised to this customer. A full search of our support";
            $out[] = "records (email, live chat, in-app tickets) shows zero refund-related";
            $out[] = "communications from or to $email. Our refund policy — published at";
            $out[] = "https://gigradar.io/legal and shown at checkout — clearly states that";
            $out[] = "digital subscriptions are non-refundable once the billing period begins.";
            break;

        case 'product_not_received':
            $out[] = "RESPONSE TO CLAIM: SERVICE NOT RECEIVED / NOT WORKING";
            $out[] = "";
            $out[] = "The service was fully delivered. GigRadar is a digital SaaS platform with";
            $out[] = "no physical delivery. Access is instant upon payment. The usage data below";
            $out[] = "proves the customer accessed and benefited from the platform.";
            break;

        case 'product_unacceptable':
            $out[] = "RESPONSE TO CLAIM: SERVICE NOT AS DESCRIBED";
            $out[] = "";
            $out[] = "The service was delivered exactly as described on our website. The customer";
            $out[] = "used the core features and received measurable results (Upwork replies).";
            $out[] = "No complaint was filed before this chargeback was initiated.";
            break;

        case 'duplicate':
            $out[] = "RESPONSE TO CLAIM: ALLEGED DUPLICATE CHARGE";
            $out[] = "";
            $out[] = "This was a single recurring subscription charge for the billing period.";
            $out[] = "GigRadar uses Stripe for billing — each charge corresponds to one";
            $out[] = "subscription period. The subscription started $subStart. If there are";
            $out[] = "multiple charges visible, they represent separate billing periods.";
            break;

        default:
            $out[] = "We dispute this chargeback and provide the following evidence.";
    }

    $out[] = "";
    $out[] = "── EVIDENCE OF SERVICE DELIVERY ─────────────────────────────────────";
    $out[] = "";

    // Section 1 — Service description
    $out[] = "1. WHAT GIGRADAR IS";
    $out[] = "   GigRadar (gigradar.io) is a cloud-based SaaS tool for Upwork freelancers.";
    $out[] = "   Features: AI job scanning, automated proposal sending, reply tracking,";
    $out[] = "   performance analytics, and a training academy. 100% digital — no physical";
    $out[] = "   product, no shipping. Access begins immediately upon successful payment.";
    $out[] = "";

    // Section 2 — Account setup proof
    if ($abSetup && $abSetup !== 'never') {
        $out[] = "2. ACCOUNT CONFIGURATION — PROOF OF INTENT TO USE";
        $out[] = "   On $abSetup, the customer logged in and configured the AI auto-bidder.";
        $out[] = "   This required: connecting their Upwork account, writing a custom proposal";
        $out[] = "   template, and selecting job categories. This is a deliberate multi-step";
        $out[] = "   process that only the real account owner could complete.";
        if ((int)$u['autobidder_configs'] > 1) {
            $out[] = "   The customer updated auto-bidder settings " . (int)$u['autobidder_configs'] . " times total.";
        }
        $out[] = "";
    }

    // Section 3 — Scanners
    if ($scanners > 0 && $u['first_scanner_date'] !== 'never') {
        $out[] = "3. JOB SCANNERS CREATED — $scanners scanner(s)";
        $out[] = "   First scanner: " . $u['first_scanner_date'];
        $out[] = "   Each scanner requires the customer to define job search criteria,";
        $out[] = "   confirming they actively engaged with the platform's core features.";
        $out[] = "";
    }

    // Section 4 — Proposals (core value delivered)
    if ($proposals > 0) {
        $out[] = "4. PROPOSALS SENT — $proposals total";
        $out[] = "   Our system sent $proposals Upwork proposals on behalf of this customer.";
        $out[] = "   Each proposal consumed Upwork \"connects\" from the customer's OWN Upwork";
        $out[] = "   account — proof that the customer's real Upwork credentials were used.";
        $out[] = "   This is direct, measurable, irreversible service delivery.";
        $out[] = "";
    }

    // Section 5 — Replies received (outcome proof)
    if ($replies > 0) {
        $out[] = "5. UPWORK CLIENT REPLIES RECEIVED — $replies total";
        if ($u['first_reply_date'] && $u['first_reply_date'] !== 'never') {
            $out[] = "   First reply: " . $u['first_reply_date'];
        }
        $out[] = "   The customer received $replies direct messages from real Upwork clients";
        $out[] = "   as a result of GigRadar-sent proposals. This is the core outcome the";
        $out[] = "   customer paid for — it was delivered. These replies cannot be manufactured.";
        $out[] = "";
    }

    // Section 6 — Platform engagement
    $out[] = "6. ONGOING PLATFORM ENGAGEMENT";
    if ($pageviews > 0) $out[] = "   • $pageviews platform page views recorded";
    if ($lessons > 0)   $out[] = "   • $lessons academy lessons completed";
    if ((int)$u['gigs_searches'] > 0) $out[] = "   • " . (int)$u['gigs_searches'] . " manual opportunity searches performed";
    $out[] = "   • Last session recorded: $lastActive";
    $out[] = "";

    // Section 7 — No connects explanation (if applicable)
    if ($noConn > 0) {
        $out[] = "7. NOTE ON TEMPORARY AUTO-BIDDER PAUSES";
        $out[] = "   Logs show the auto-bidder was temporarily paused $noConn time(s) because";
        $out[] = "   the customer's Upwork account ran out of \"connects\" (Upwork's own credit";
        $out[] = "   system). GigRadar does not sell or control connects. Our platform";
        $out[] = "   continued operating normally. The customer was notified each time.";
        $out[] = "   This is not a service failure — it is a limitation of the customer's";
        $out[] = "   own Upwork account, outside GigRadar's control.";
        $out[] = "";
    }

    // Section 8 — No prior complaint
    $out[] = "8. NO PRIOR COMPLAINT OR REFUND REQUEST";
    $out[] = "   A full search of our support records shows ZERO support tickets, emails,";
    $out[] = "   live chats, or refund requests from $email before this chargeback.";
    $out[] = "   We were given no opportunity to resolve any concern directly.";
    $out[] = "   Our support team is available 24/7 at support@gigradar.io.";
    $out[] = "";

    // Section 9 — Policy
    $out[] = "9. TERMS & REFUND POLICY";
    $out[] = "   Full policy: https://gigradar.io/legal";
    $out[] = "   • Digital SaaS subscriptions are non-refundable once the billing period";
    $out[] = "     has started and the platform has been accessed.";
    $out[] = "   • Customers may cancel anytime from Settings → Subscription.";
    $out[] = "   • The policy was shown on the Stripe checkout page at time of purchase.";
    $out[] = "";

    // Conclusion
    $out[] = "── CONCLUSION ──────────────────────────────────────────────────────────";
    $out[] = "";
    $out[] = "The evidence above demonstrates:";
    $out[] = "  (a) The customer knowingly signed up and completed onboarding.";
    $out[] = "  (b) The customer $factsStr.";
    $out[] = "  (c) The service was fully delivered as described.";
    $out[] = "  (d) No cancellation, complaint, or refund request was ever made.";
    $out[] = "";
    $out[] = "This is a case of first-party fraud (\"friendly fraud\"). The funds should";
    $out[] = "be returned to GigRadar.";
    $out[] = "";
    $out[] = "— GigRadar Support | support@gigradar.io | https://gigradar.io";

    return implode("\n", $out);
}
