<?php
function getDb(): SQLite3 {
    $path = __DIR__ . '/../data/disputes.db';
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
    $db = new SQLite3($path);
    $db->exec("CREATE TABLE IF NOT EXISTS disputes (
        dispute_id               TEXT PRIMARY KEY,
        charge_id                TEXT,
        stripe_customer_id       TEXT,
        email                    TEXT,
        amount                   INTEGER,
        currency                 TEXT DEFAULT 'usd',
        reason                   TEXT,
        status                   TEXT,
        epoch_created            INTEGER,
        epoch_evidence_submitted INTEGER,
        evidence_json            TEXT DEFAULT '{}',
        outcome                  TEXT DEFAULT ''
    )");
    return $db;
}

function upsertDispute(SQLite3 $db, object $dispute, string $email = ''): void {
    $id = SQLite3::escapeString($dispute->id);
    $exists = $db->querySingle("SELECT 1 FROM disputes WHERE dispute_id='$id'");
    if ($exists) {
        $db->exec("UPDATE disputes SET
            status  = '" . SQLite3::escapeString($dispute->status) . "',
            outcome = '" . SQLite3::escapeString($dispute->status === 'won' || $dispute->status === 'lost' ? $dispute->status : '') . "'
            WHERE dispute_id='$id'");
    } else {
        $db->exec("INSERT OR IGNORE INTO disputes
            (dispute_id, charge_id, stripe_customer_id, email, amount, currency, reason, status, epoch_created)
            VALUES (
                '$id',
                '" . SQLite3::escapeString($dispute->charge ?? '') . "',
                '" . SQLite3::escapeString($dispute->metadata['customer_id'] ?? '') . "',
                '" . SQLite3::escapeString($email) . "',
                " . (int)$dispute->amount . ",
                '" . SQLite3::escapeString($dispute->currency ?? 'usd') . "',
                '" . SQLite3::escapeString($dispute->reason ?? '') . "',
                '" . SQLite3::escapeString($dispute->status) . "',
                " . (int)$dispute->created . "
            )");
    }
}
