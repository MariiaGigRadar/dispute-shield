<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/evidence.php';
require_once __DIR__ . '/app/telegram.php';

// Verify Stripe signature
$payload = file_get_contents('php://input');
$sig     = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

try {
    $event = \Stripe\Webhook::constructEvent($payload, $sig, STRIPE_WEBHOOK_SECRET);
} catch (\Exception $e) {
    http_response_code(400);
    exit('Invalid signature');
}

$stripe   = new \Stripe\StripeClient(STRIPE_SECRET_KEY);
$db       = getDb();
$dispute  = $event->data->object;

if ($event->type === 'charge.dispute.created') {

    // Get email
    $charge = $stripe->charges->retrieve($dispute->charge, ['expand' => ['customer']]);
    $email  = $charge->customer->email ?? $charge->billing_details->email ?? '';

    upsertDispute($db, $dispute, $email);

    // Collect evidence & submit
    try {
        $evidence = buildEvidence($charge, $email);
        $stripe->disputes->update($dispute->id, ['evidence' => $evidence]);

        $db->exec("UPDATE disputes SET
            evidence_json='" . SQLite3::escapeString(json_encode($evidence)) . "',
            epoch_evidence_submitted=" . time() . "
            WHERE dispute_id='" . SQLite3::escapeString($dispute->id) . "'");

        $amt = '$' . number_format($dispute->amount / 100, 2);
        tg(SITE_NAME . " — New dispute from $email for $amt ({$dispute->reason})\nEvidence auto-submitted ✅\nhttps://dashboard.stripe.com/disputes/{$dispute->id}");

    } catch (\Exception $e) {
        $amt = '$' . number_format($dispute->amount / 100, 2);
        tg(SITE_NAME . " — New dispute from $email for $amt\nEvidence FAILED ❌: " . $e->getMessage());
    }

} elseif ($event->type === 'charge.dispute.updated') {
    upsertDispute($db, $dispute);

} elseif ($event->type === 'charge.dispute.closed') {
    upsertDispute($db, $dispute);

    $row   = $db->querySingle("SELECT email, amount, reason FROM disputes WHERE dispute_id='" . SQLite3::escapeString($dispute->id) . "'", true);
    $email = $row['email'] ?? '?';
    $amt   = '$' . number_format(($row['amount'] ?? 0) / 100, 2);

    $emoji = match($dispute->status) { 'won' => '✅', 'lost' => '❌', default => '⚠️' };
    $label = strtoupper($dispute->status);
    tg("$emoji " . SITE_NAME . " — Dispute $label\n$email · $amt · {$row['reason']}\nhttps://dashboard.stripe.com/disputes/{$dispute->id}");
}

http_response_code(200);
echo 'ok';
