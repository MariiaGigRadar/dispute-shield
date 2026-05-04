<?php
require_once __DIR__ . '/posthog.php';

function buildEvidence(object $charge, string $email): array {
    $user       = getPostHogUser($email);
    $chargeDate = date('Y-m-d', $charge->created);
    $amountUsd  = number_format($charge->amount / 100, 2);

    $uploads    = $user['profiles_uploaded'] ?? 0;
    $analyses   = $user['profiles_analyzed'] ?? 0;
    $lastActive = $user['last_active'] ?? 'unknown';
    $signupDate = $user['signup_date'] ?? 'unknown';
    $plan       = $user['plan'] ?? 'unknown';
    $totalPaid  = $user['total_paid_usd'] ?? 0;
    $name       = $user['name'] ?? '';

    $activityLog = "Signup date:        $signupDate\n"
                 . "Profiles uploaded:  $uploads\n"
                 . "Profiles analyzed:  $analyses\n"
                 . "Last active:        $lastActive\n"
                 . "Subscription plan:  $plan\n"
                 . "Total amount paid:  \$$totalPaid USD\n\n"
                 . "Recent activity:\n";
    foreach ($user['recent_activity'] ?? [] as $a) {
        $activityLog .= "  {$a['date']} — {$a['event']}\n";
    }

    return [
        'product_description' =>
            "AI-powered LinkedIn profile analysis SaaS. Customers upload LinkedIn profiles " .
            "and receive instant AI-generated insights and optimization recommendations. " .
            "Service is delivered entirely digitally and instantly upon payment.",

        'customer_name'          => $name ?: 'N/A',
        'customer_email_address' => $email,
        'service_date'           => $chargeDate,

        'access_activity_log' => $activityLog,

        'uncategorized_text' =>
            "Customer $email signed up on $signupDate and actively used the service. " .
            "They uploaded $uploads LinkedIn profiles and ran $analyses AI analyses. " .
            "Total paid: \$$totalPaid USD. Last active: $lastActive. " .
            "Service was delivered digitally and instantly — no physical product, no shipping. " .
            "Customer never contacted us requesting a refund before filing this dispute.",

        'refund_policy_disclosure' =>
            "Refund policy is presented at checkout and permanently accessible at /legal. " .
            "Digital services are non-refundable once AI analysis has been delivered.",

        'cancellation_policy_disclosure' =>
            "Cancellation policy is shown at checkout and at /legal. " .
            "Customers can cancel anytime from their dashboard. No penalty.",

        'refund_refusal_explanation' =>
            "The customer did not contact our support team to request a refund " .
            "before filing this chargeback. We have no record of any refund request or support ticket.",

        'cancellation_rebuttal' =>
            "PostHog analytics confirm active usage: $uploads profiles uploaded, " .
            "$analyses analyses run, last active $lastActive. " .
            "Customer never requested cancellation.",
    ];
}
