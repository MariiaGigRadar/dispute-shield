<?php
// Run from CLI: php workers/sync.php
if (php_sapi_name() !== 'cli') exit('CLI only');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/evidence.php';
require_once __DIR__ . '/../app/telegram.php';

$stripe = new \Stripe\StripeClient(STRIPE_SECRET_KEY);
$db     = getDb();
$synced = 0; $submitted = 0;

echo "Syncing disputes from Stripe...\n";
$after = null;
do {
    $params = ['limit' => 100];
    if ($after) $params['starting_after'] = $after;
    $page = $stripe->disputes->all($params);

    foreach ($page->data as $dispute) {
        $email = '';
        try {
            $charge = $stripe->charges->retrieve($dispute->charge, ['expand'=>['customer']]);
            $email  = $charge->customer->email ?? $charge->billing_details->email ?? '';
        } catch(\Exception $e) {}

        upsertDispute($db, $dispute, $email);
        $synced++;

        // Submit evidence if pending & not yet done
        $row   = $db->querySingle("SELECT evidence_json FROM disputes WHERE dispute_id='" . SQLite3::escapeString($dispute->id) . "'", true);
        $ev    = json_decode($row['evidence_json'] ?? '{}', true);
        $needs = in_array($dispute->status, ['needs_response','warning_needs_response']);

        if ($needs && empty($ev['access_activity_log']) && $email) {
            echo "  Submitting evidence for $email...\n";
            try {
                $charge   = $charge ?? $stripe->charges->retrieve($dispute->charge);
                $evidence = buildEvidence($charge, $email);
                $stripe->disputes->update($dispute->id, ['evidence' => $evidence]);
                $db->exec("UPDATE disputes SET
                    evidence_json='" . SQLite3::escapeString(json_encode($evidence)) . "',
                    epoch_evidence_submitted=" . time() . "
                    WHERE dispute_id='" . SQLite3::escapeString($dispute->id) . "'");
                echo "    ✓ Done\n";
                $submitted++;
            } catch(\Exception $e) {
                echo "    ✗ " . $e->getMessage() . "\n";
            }
        }
    }
    $after = $page->has_more ? end($page->data)->id : null;
    echo "  Batch done, total synced: $synced\n";
} while ($after);

echo "\n✅ Synced: $synced | Evidence submitted: $submitted\n";
