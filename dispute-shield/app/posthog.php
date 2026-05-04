<?php
function posthogQuery(string $sql): array {
    $url  = POSTHOG_HOST . '/api/projects/' . POSTHOG_PROJECT_ID . '/query/';
    $body = json_encode(['query' => ['kind' => 'HogQLQuery', 'query' => $sql]]);
    $ctx  = stream_context_create(['http' => [
        'method'         => 'POST',
        'header'         => "Authorization: Bearer " . POSTHOG_API_KEY . "\r\nContent-Type: application/json\r\n",
        'content'        => $body,
        'timeout'        => 15,
        'ignore_errors'  => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return [];
    $data = json_decode($resp, true);
    return $data['results'] ?? [];
}

function getPostHogUser(string $email): array {
    $e = str_replace("'", "\\'", $email);

    $main = posthogQuery("
        SELECT
            min(if(event='user_signed_up', timestamp, null)),
            countIf(event='profile_uploaded'),
            countIf(event='profiles_list_viewed'),
            max(timestamp),
            any(person.properties.name),
            any(person.properties.email),
            any(person.properties.\$virt_revenue),
            any(person.properties.\$virt_mrr)
        FROM events
        WHERE person.properties.email = '$e'
        LIMIT 1
    ");

    $row = $main[0] ?? null;
    if (!$row || ($row[4] === null && $row[5] === null && (int)$row[1] === 0)) {
        return ['found' => false];
    }

    $purchases = posthogQuery("
        SELECT timestamp, properties.transaction_id
        FROM events
        WHERE person.properties.email = '$e'
          AND event = 'profile_analysis_purchased'
        ORDER BY timestamp DESC LIMIT 20
    ");

    $recent = posthogQuery("
        SELECT timestamp, event
        FROM events
        WHERE person.properties.email = '$e'
        ORDER BY timestamp DESC LIMIT 10
    ");

    $totalPaid = (float)($row[6] ?? 0);

    return [
        'found'             => true,
        'name'              => $row[4] ?? '',
        'email'             => $row[5] ?? $email,
        'signup_date'       => $row[0] ? substr($row[0], 0, 10) : 'unknown',
        'profiles_uploaded' => (int)($row[1] ?? 0),
        'profiles_analyzed' => (int)($row[2] ?? 0),
        'last_active'       => $row[3] ? substr($row[3], 0, 10) : 'unknown',
        'plan'              => (float)($row[7] ?? 0) > 0 ? 'paid' : 'free',
        'total_paid_usd'    => $totalPaid,
        'purchases'         => array_map(fn($r) => [
            'date'           => substr($r[0] ?? '', 0, 10),
            'transaction_id' => $r[1] ?? '',
        ], $purchases),
        'recent_activity'   => array_map(fn($r) => [
            'date'  => str_replace('T', ' ', substr($r[0] ?? '', 0, 16)),
            'event' => $r[1] ?? '',
        ], $recent),
    ];
}
