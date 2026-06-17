<?php
/**
 * Mongo — real proposal stats for dispute evidence.
 *
 * Source of truth for Sent / Reply / View — matches the GigRadar product
 * dashboard (StatsRepository / benchmark-stats workflow), NOT PostHog.
 * PostHog under-reports (e.g. Luke: 55 of 361 sent), so for evidence we
 * read the canonical `proposals` collection directly.
 *
 * Canonical formulas (from customer-audit/references/metrics.md):
 *   Sent   = count of proposals matching the base filter
 *   Reply  = dashroomUID non-empty  (mirror: meta.chat.chatId)
 *   View   = dashroomUID non-empty OR status==7 OR otherAnnotations contains 12
 *   Base filter:
 *     _gigradarTeamOid: ObjectId(teamId)
 *     meta.createdAt:   { $gte: from, $lt: to }
 *     meta.inviteToInterviewUid: null      // exclude invite-replies
 *
 * Requires PHP ext-mongodb (native driver, no composer lib needed).
 * Env: MONGO_URI (full URI incl. creds + ?authSource=admin), MONGO_DB (default gigradar-dev).
 */

/**
 * Run the canonical proposal-stats aggregation for one team over a window.
 *
 * @param string   $teamOid 24-hex ObjectId of the GigRadar team
 * @param int|null $fromTs  unix seconds, inclusive ($gte). Null = no lower bound.
 * @param int|null $toTs    unix seconds, exclusive ($lt).  Null = no upper bound.
 * @return array{found:bool,sent:int,replies:int,views:int,reply_rate:float,error?:string}
 */
function getMongoProposalStats(string $teamOid, ?int $fromTs = null, ?int $toTs = null): array {
    $uri = getenv('MONGO_URI') ?: '';
    $db  = getenv('MONGO_DB') ?: 'gigradar-dev';

    $empty = ['found' => false, 'sent' => 0, 'replies' => 0, 'views' => 0, 'reply_rate' => 0.0];

    if (!$uri) { $empty['error'] = 'MONGO_URI not set'; return $empty; }
    if (!class_exists(\MongoDB\Driver\Manager::class)) {
        $empty['error'] = 'ext-mongodb not installed';
        return $empty;
    }
    if (!preg_match('/^[a-f0-9]{24}$/i', $teamOid)) {
        $empty['error'] = 'invalid team oid';
        return $empty;
    }

    try {
        $manager = new \MongoDB\Driver\Manager($uri);

        // Base filter — exact match to the canonical product formula.
        $match = [
            '_gigradarTeamOid'          => new \MongoDB\BSON\ObjectId($teamOid),
            'meta.inviteToInterviewUid' => null,
        ];
        $createdAt = [];
        if ($fromTs !== null) $createdAt['$gte'] = new \MongoDB\BSON\UTCDateTime($fromTs * 1000);
        if ($toTs   !== null) $createdAt['$lt']  = new \MongoDB\BSON\UTCDateTime($toTs   * 1000);
        if ($createdAt) $match['meta.createdAt'] = $createdAt;

        // Reply / View use $exists + $nin:[null,""] — NOT $ne:null (missing-field
        // semantics would return zero). dashroomUID is the primary signal.
        $hasDashroom = ['$and' => [
            ['$ne' => [['$type' => '$dashroomUID'], 'missing']],
            ['$not' => ['$in' => ['$dashroomUID', [null, '']]]],
        ]];

        $pipeline = [
            ['$match' => $match],
            ['$group' => [
                '_id'      => null,
                'sent'     => ['$sum' => 1],
                'replies'  => ['$sum' => ['$cond' => [$hasDashroom, 1, 0]]],
                'views'    => ['$sum' => ['$cond' => [
                    ['$or' => [
                        $hasDashroom,
                        ['$eq' => ['$status', 7]],
                        ['$in' => [12, ['$ifNull' => ['$otherAnnotations', []]]]],
                    ]], 1, 0,
                ]]],
            ]],
        ];

        // Single-team query → compound tenant-first index is correct; no hint needed.
        $cmd = new \MongoDB\Driver\Command([
            'aggregate' => 'proposals',
            'pipeline'  => $pipeline,
            'cursor'    => new \stdClass(),
            'maxTimeMS' => 60000,
        ]);

        $cursor = $manager->executeCommand($db, $cmd);
        $row    = current($cursor->toArray());

        if (!$row) {
            // No matching proposals — valid result (zero), still "found".
            return ['found' => true, 'sent' => 0, 'replies' => 0, 'views' => 0, 'reply_rate' => 0.0];
        }

        $sent    = (int)($row->sent ?? 0);
        $replies = (int)($row->replies ?? 0);
        $views   = (int)($row->views ?? 0);

        return [
            'found'      => true,
            'sent'       => $sent,
            'replies'    => $replies,
            'views'      => $views,
            'reply_rate' => $sent > 0 ? round($replies / $sent * 100, 1) : 0.0,
        ];
    } catch (\Throwable $e) {
        $empty['error'] = $e->getMessage();
        error_log('[mongo] proposal stats failed: ' . $e->getMessage());
        return $empty;
    }
}

/**
 * Count distinct job scanners used by a team. The scanner reference lives on
 * each proposal in the `scannerID` field (format: "scanner/<oid>"). So the
 * number of scanners the customer set up = distinct scannerID across their
 * proposals. Returns null on any error so the caller can fall back.
 */
function getMongoScannerCount(string $teamOid, ?int $fromTs = null, ?int $toTs = null): ?int {
    $uri = getenv('MONGO_URI') ?: '';
    $db  = getenv('MONGO_DB') ?: 'gigradar-dev';
    if (!$uri || !class_exists(\MongoDB\Driver\Manager::class)) return null;
    if (!preg_match('/^[a-f0-9]{24}$/i', $teamOid)) return null;

    try {
        $manager = new \MongoDB\Driver\Manager($uri);

        $match = [
            '_gigradarTeamOid'          => new \MongoDB\BSON\ObjectId($teamOid),
            'meta.inviteToInterviewUid' => null,
            'scannerID'                 => ['$exists' => true, '$nin' => [null, '']],
        ];
        $createdAt = [];
        if ($fromTs !== null) $createdAt['$gte'] = new \MongoDB\BSON\UTCDateTime($fromTs * 1000);
        if ($toTs   !== null) $createdAt['$lt']  = new \MongoDB\BSON\UTCDateTime($toTs   * 1000);
        if ($createdAt) $match['meta.createdAt'] = $createdAt;

        $cmd = new \MongoDB\Driver\Command([
            'aggregate' => 'proposals',
            'pipeline'  => [
                ['$match' => $match],
                ['$group' => ['_id' => '$scannerID']],
                ['$count' => 'distinct_scanners'],
            ],
            'cursor'    => new \stdClass(),
            'maxTimeMS' => 30000,
        ]);
        $res = $manager->executeCommand($db, $cmd);
        $row = current($res->toArray());
        return isset($row->distinct_scanners) ? (int)$row->distinct_scanners : 0;
    } catch (\Throwable $e) {
        error_log('[mongo] scanner count failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Resolve a GigRadar team ObjectId from an Intercom company_id when available.
 * Intercom company.company_id == _gigradarTeamOid for GigRadar teams.
 */
function resolveTeamOid(array $intercom): string {
    return $intercom['team_oid'] ?? '';
}
