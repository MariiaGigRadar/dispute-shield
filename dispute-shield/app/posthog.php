<?php
function posthogQuery(string $sql): array {
    $url  = POSTHOG_HOST . '/api/projects/' . POSTHOG_PROJECT_ID . '/query/';
    $body = json_encode(['query' => ['kind' => 'HogQLQuery', 'query' => $sql]]);
    $ctx  = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Authorization: Bearer " . POSTHOG_API_KEY . "\r\nContent-Type: application/json\r\n",
        'content'       => $body,
        'timeout'       => 20,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return [];
    $data = json_decode($resp, true);
    return $data['results'] ?? [];
}

function getPostHogUser(string $email): array {
    $e = str_replace("'", "\\'", $email);

    // Main stats — all key GigRadar events in one query
    $main = posthogQuery("
        SELECT
            min(if(event='sign_up', timestamp, null))                        AS signup_date,
            min(if(event='auto_bidder_configured', timestamp, null))         AS autobidder_setup_date,
            min(if(event='scanner_created', timestamp, null))                AS first_scanner_date,
            min(if(event='auto_bid_reply_received', timestamp, null))        AS first_reply_date,
            min(if(event='subscription_active', timestamp, null))            AS subscription_start,
            max(if(event='subscription_canceled', timestamp, null))          AS subscription_canceled,
            countIf(event='auto_bid_reply_received')                         AS total_replies,
            countIf(event='team_proposal_sent')                             AS proposals_sent,
            countIf(event='scanner_created')                                 AS scanners_created,
            countIf(event='auto_bidder_disabled_no_connects')               AS no_connects_events,
            countIf(event='auto_bidder_configured')                          AS autobidder_configs,
            countIf(event='\$pageview')                                      AS total_pageviews,
            max(timestamp)                                                   AS last_active,
            max(if(event='\$pageview', timestamp, null))                     AS last_pageview,
            any(person.properties.name)                                      AS name,
            any(person.properties.email)                                     AS p_email,
            any(person.properties.\$virt_revenue)                            AS revenue,
            any(person.properties.\$virt_mrr)                                AS mrr,
            countIf(event='dashboard_view_switched')                         AS dashboard_switches,
            countIf(event='user_lesson_completed')                           AS lessons_completed,
            countIf(event='get_gigs_time')                                   AS gigs_searches,
            any(person.properties.plan)                                      AS plan_prop,
            any(person.properties.\$geoip_country_name)                           AS geo_country,
            any(person.properties.\$geoip_city_name)                              AS geo_city,
            any(person.properties.\$initial_referring_domain)                     AS referring_domain,
            any(person.properties.\$initial_current_url)                          AS signup_url,
            countIf(event='\$pageview' AND timestamp > subscription_start)        AS sessions_after_payment
        FROM events
        WHERE person.properties.email = '$e'
        LIMIT 1
    ");

    $row = $main[0] ?? null;
    if (!$row || (empty($row[14]) && empty($row[15]))) {
        return ['found' => false];
    }

    // Recent activity — meaningful events only
    $recent = posthogQuery("
        SELECT timestamp, event
        FROM events
        WHERE person.properties.email = '$e'
          AND event NOT IN (
            '\$web_vitals', '\$pageleave', '\$feature_flag_called',
            '\$autocapture', '\$rageclick', '\$set', '\$identify'
          )
        ORDER BY timestamp DESC LIMIT 20
    ");

    // Team actions by gigradar.io staff on this user
    $adminActions = posthogQuery("
        SELECT timestamp, event, distinct_id
        FROM events
        WHERE person.properties.email = '$e'
          AND (
            event LIKE 'admins_%'
            OR event = 'subscription_active'
            OR event = 'subscription_canceled'
            OR event = 'subscription_resumed'
          )
        ORDER BY timestamp DESC LIMIT 10
    ");

    $mrr       = (float)($row[17] ?? 0);
    $revenue   = (float)($row[16] ?? 0);
    $planProp  = $row[21] ?? '';

    // Map Stripe price IDs to human plan names
    $planMap = [
        'price_1REyczICsmQmrsT0wlIUZJGH' => 'Agency Pro (Annual)',
        'price_1REyczICsmQmrsT0hyNvW'     => 'Agency Pro (Monthly)',
        'price_1abc'                       => 'Solo Pro (Annual)',
        // Add more price IDs as needed
    ];
    $planName = $planMap[$planProp] ?? (
        str_contains(strtolower($planProp), 'agency') ? 'Agency Pro' :
        (str_contains(strtolower($planProp), 'solo')  ? 'Solo Pro'   :
        ($planProp && !str_starts_with($planProp, 'price_') ? $planProp : 'Paid Plan'))
    );

    // signup_date = subscription_start (date of purchase)
    $signupDate = $row[4] ? substr($row[4], 0, 10) : 'unknown';

    return [
        'found'                  => true,
        'name'                   => $row[14] ?? '',
        'email'                  => $row[15] ?? $email,

        // Dates
        'signup_date'            => $signupDate,
        'autobidder_setup_date'  => $row[1]  ? substr($row[1], 0, 10)  : 'never',
        'first_scanner_date'     => $row[2]  ? substr($row[2], 0, 10)  : 'never',
        'first_reply_date'       => $row[3]  ? substr($row[3], 0, 10)  : 'never',
        'subscription_start'     => $row[4]  ? substr($row[4], 0, 10)  : 'unknown',
        'subscription_canceled'  => $row[5]  ? substr($row[5], 0, 10)  : null,
        'last_active'            => $row[12] ? substr($row[12], 0, 10) : 'unknown',
        'last_pageview'          => $row[13] ? substr($row[13], 0, 10) : 'unknown',

        // Usage counts
        'total_replies'          => (int)($row[6]  ?? 0),
        'proposals_sent'         => (int)($row[7]  ?? 0),
        'scanners_created'       => (int)($row[8]  ?? 0),
        'no_connects_events'     => (int)($row[9]  ?? 0),
        'autobidder_configs'     => (int)($row[10] ?? 0),
        'total_pageviews'        => (int)($row[11] ?? 0),
        'dashboard_switches'     => (int)($row[18] ?? 0),
        'lessons_completed'      => (int)($row[19] ?? 0),
        'gigs_searches'          => (int)($row[20] ?? 0),

        // Plan & payment
        'plan'                   => $planName,
        'total_paid_usd'         => $revenue,
        'mrr'                    => $mrr,
        'is_canceled'            => !empty($row[5]),
        'stripe_subscription_status' => '',  // filled by enrichWithStripe

        // Geo & device
        'geo_country'            => $row[22] ?? '',
        'geo_city'               => $row[23] ?? '',
        'referring_domain'       => $row[24] ?? '',
        'signup_url'             => $row[25] ?? '',
        'sessions_after_payment' => (int)($row[26] ?? 0),

        // Activity logs
        'recent_activity'        => array_map(fn($r) => [
            'date'  => str_replace('T', ' ', substr($r[0] ?? '', 0, 16)),
            'event' => $r[1] ?? '',
        ], $recent),

        'admin_actions'          => array_map(fn($r) => [
            'date'  => str_replace('T', ' ', substr($r[0] ?? '', 0, 16)),
            'event' => $r[1] ?? '',
        ], $adminActions),
    ];
}
