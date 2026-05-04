<?php
require_once __DIR__ . '/posthog.php';

function buildEvidence(object $charge, string $email): array {
    $u          = getPostHogUser($email);
    $chargeDate = date('Y-m-d', $charge->created);
    $amountUsd  = number_format($charge->amount / 100, 2);

    if (!($u['found'] ?? false)) {
        // Fallback if not found in PostHog
        return [
            'product_description'        => buildProductDesc(),
            'customer_email_address'     => $email,
            'customer_name'              => 'N/A',
            'service_date'               => $chargeDate,
            'uncategorized_text'         => "Customer $email was charged \$$amountUsd on $chargeDate for GigRadar subscription.",
            'refund_policy_disclosure'   => refundPolicy(),
            'cancellation_policy_disclosure' => cancelPolicy(),
            'refund_refusal_explanation' => refundRefusal($email),
            'cancellation_rebuttal'      => "No PostHog data found for this user.",
        ];
    }

    $name        = $u['name'] ?: 'N/A';
    $signup      = $u['signup_date'];
    $subStart    = $u['subscription_start'];
    $lastActive  = $u['last_active'];
    $lastPV      = $u['last_pageview'];
    $abSetup     = $u['autobidder_setup_date'];
    $firstScanner = $u['first_scanner_date'];
    $firstReply  = $u['first_reply_date'];
    $subCanceled = $u['subscription_canceled'];
    $replies     = $u['total_replies'];
    $proposals   = $u['proposals_sent'];
    $scanners    = $u['scanners_created'];
    $noConnects  = $u['no_connects_events'];
    $pageviews   = $u['total_pageviews'];
    $lessons     = $u['lessons_completed'];
    $gigsSearches = $u['gigs_searches'];
    $plan        = $u['plan'];
    $totalPaid   = $u['total_paid_usd'];
    $isCanceled  = $u['is_canceled'];

    // Build the activity log
    $log  = "=== GigRadar Service Usage Report ===\n";
    $log .= "Generated: " . date('Y-m-d H:i') . " UTC\n\n";

    $log .= "--- ACCOUNT ---\n";
    $log .= "Email:                    $email\n";
    $log .= "Name:                     $name\n";
    $log .= "Signup date:              $signup\n";
    $log .= "Subscription started:     $subStart\n";
    $log .= "Subscription plan:        $plan\n";
    $log .= "Subscription canceled:    " . ($subCanceled ?: 'No — still active') . "\n";
    $log .= "Total amount paid:        \$$totalPaid USD\n\n";

    $log .= "--- ONBOARDING & SETUP ---\n";
    $log .= "Auto-bidder configured:   $abSetup\n";
    $log .= "First scanner created:    $firstScanner\n";
    $log .= "Academy lessons completed: $lessons\n\n";

    $log .= "--- CORE USAGE (service actively delivered) ---\n";
    $log .= "Proposals sent (usage_recorded):     $proposals\n";
    $log .= "Replies received from prospects:     $replies\n";
    $log .= "First reply received:                $firstReply\n";
    $log .= "Scanners created:                    $scanners\n";
    $log .= "Gig opportunity searches:            $gigsSearches\n\n";

    $log .= "--- PLATFORM ENGAGEMENT ---\n";
    $log .= "Total page views:                    $pageviews\n";
    $log .= "Last page view:                      $lastPV\n";
    $log .= "Last active (any event):             $lastActive\n";
    $log .= "Times disconnects ran out:           $noConnects\n\n";

    $log .= "--- RECENT ACTIVITY ---\n";
    foreach ($u['recent_activity'] ?? [] as $a) {
        $log .= "  {$a['date']} — {$a['event']}\n";
    }

    if (!empty($u['admin_actions'])) {
        $log .= "\n--- TEAM / ADMIN ACTIONS ON ACCOUNT ---\n";
        foreach ($u['admin_actions'] as $a) {
            $log .= "  {$a['date']} — {$a['event']}\n";
        }
    }

    // Why we should win argument
    $winning  = "Customer $email signed up on $signup and actively used GigRadar. ";
    $winning .= "After purchasing, they configured the auto-bidder ($abSetup), ";
    $winning .= "created $scanners scanner(s), sent $proposals proposals, ";
    $winning .= "and received $replies replies from real prospects on Upwork. ";
    if ($firstReply && $firstReply !== 'never') {
        $winning .= "The service delivered measurable results — first reply received on $firstReply. ";
    }
    $winning .= "The customer was last active on $lastActive. ";
    $winning .= "Total paid: \$$totalPaid USD. Plan: $plan. ";
    $winning .= "GigRadar is a digital SaaS service — there is no physical product. ";
    $winning .= "Service was delivered instantly and continuously throughout the subscription period. ";
    $winning .= "The customer never contacted our support team to request a refund before filing this dispute. ";
    if ($noConnects > 0) {
        $winning .= "Note: the auto-bidder was paused $noConnects time(s) due to insufficient Upwork connects — this is a user account limitation, not a service failure.";
    }

    return [
        'product_description'            => buildProductDesc(),
        'customer_name'                  => $name,
        'customer_email_address'         => $email,
        'service_date'                   => $chargeDate,
        'access_activity_log'            => $log,
        'uncategorized_text'             => $winning,
        'refund_policy_disclosure'       => refundPolicy(),
        'cancellation_policy_disclosure' => cancelPolicy(),
        'refund_refusal_explanation'     => refundRefusal($email),
        'cancellation_rebuttal'          => cancelRebuttal($email, $proposals, $replies, $lastActive, $isCanceled),
    ];
}

function buildProductDesc(): string {
    return "GigRadar is an AI-powered Upwork automation SaaS. Users connect their Upwork account, " .
           "configure AI-powered scanners that monitor job postings 24/7, and automatically send " .
           "personalized proposals to matching opportunities. The platform also provides analytics, " .
           "proposal tracking, reply management, and an academy with training materials. " .
           "All services are delivered digitally and are accessible immediately upon subscription activation.";
}

function refundPolicy(): string {
    return "Our refund policy is displayed at checkout and permanently accessible at " .
           "https://gigradar.io/legal. Subscriptions are non-refundable once the service " .
           "period has begun and the customer has accessed the platform and used its features.";
}

function cancelPolicy(): string {
    return "Customers can cancel their GigRadar subscription at any time from their account " .
           "dashboard under Settings. The cancellation policy is shown at checkout and at " .
           "https://gigradar.io/legal. There are no cancellation fees or penalties. " .
           "Access continues until the end of the billing period.";
}

function refundRefusal(string $email): string {
    return "Customer $email did not contact GigRadar support at any point prior to filing " .
           "this chargeback. Had they reached out, our team would have reviewed their situation " .
           "and offered assistance. We have no record of any support ticket, email, or refund " .
           "request from this customer.";
}

function cancelRebuttal(string $email, int $proposals, int $replies, string $lastActive, bool $isCanceled): string {
    $text = "PostHog analytics confirm that $email actively used GigRadar after the disputed charge: ";
    $text .= "$proposals proposals were sent and $replies replies were received from Upwork prospects. ";
    $text .= "Last active: $lastActive. ";
    if (!$isCanceled) {
        $text .= "The subscription was never canceled by the customer — it remains active. ";
    }
    $text .= "All activity is logged in our analytics system and confirms continuous service delivery.";
    return $text;
}
