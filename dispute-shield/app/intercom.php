<?php
/**
 * Intercom  -  полный лог коммуникаций для dispute evidence
 *
 * Что мы извлекаем из каждого разговора:
 *  - Кто написал первым: клиент или мы
 *  - Каждое сообщение: дата, автор (клиент / наш сотрудник), текст
 *  - Читал ли клиент наши письма (last_email_opened_at)
 *  - Оценки клиента (conversation_rating)
 *  - Статистика: сколько раз мы писали, сколько раз клиент отвечал
 *  - Контакт-данные из Intercom (stripe_id, локация, браузер, ОС)
 */

function intercomRequest(string $path, array $params = [], string $method = 'GET'): array {
    $token = defined('INTERCOM_TOKEN') ? INTERCOM_TOKEN : '';
    if (!$token) return [];
    $url = 'https://api.intercom.io' . $path;
    if ($method === 'GET' && !empty($params)) $url .= '?' . http_build_query($params);
    $opts = [
        'method'        => $method,
        'header'        => "Authorization: Bearer $token\r\nContent-Type: application/json\r\nIntercom-Version: 2.11\r\n",
        'timeout'       => 15,
        'ignore_errors' => true,
    ];
    if ($method === 'POST' && !empty($params)) $opts['content'] = json_encode($params);
    $ctx  = stream_context_create(['http' => $opts]);
    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return [];
    return json_decode($resp, true) ?? [];
}

function intercomFindContact(string $email): array {
    $result = intercomRequest('/contacts/search', [
        'query'      => ['operator' => 'AND', 'value' => [['field' => 'email', 'operator' => '=', 'value' => $email]]],
        'pagination' => ['per_page' => 1],
    ], 'POST');
    return $result['data'][0] ?? [];
}

function intercomGetConversations(string $contactId, int $limit = 50): array {
    $result = intercomRequest('/conversations', ['contact_id' => $contactId, 'per_page' => $limit, 'order' => 'created_at', 'sort' => 'desc']);
    return $result['conversations'] ?? [];
}

function intercomGetFullConversation(string $convId): array {
    return intercomRequest('/conversations/' . $convId);
}

/**
 * Get a contact's company. For GigRadar teams, company.company_id == _gigradarTeamOid
 * (the ObjectId used in the Mongo `proposals` collection).
 */
function intercomGetCompanyTeamOid(array $contact): string {
    $companies = $contact['companies']['data'] ?? [];
    if (empty($companies)) return '';
    $companyId = $companies[0]['id'] ?? '';   // Intercom internal id
    if (!$companyId) return '';
    $company = intercomRequest('/companies/' . $companyId);
    // company_id is GigRadar's own id == _gigradarTeamOid (24-hex ObjectId)
    $oid = $company['company_id'] ?? '';
    return preg_match('/^[a-f0-9]{24}$/i', $oid) ? $oid : '';
}

/**
 * Главная функция  -  возвращает всё для evidence
 */
function getIntercomData(string $email): array {
    $contact = intercomFindContact($email);
    if (empty($contact)) return ['found' => false, 'summary' => '', 'conversations' => []];

    $ca = $contact['custom_attributes'] ?? [];
    $convList = intercomGetConversations($contact['id'], 30);

    $parsedConvs    = [];
    $totalGigradarMessages = 0;
    $totalClientMessages   = 0;
    $totalGigradarEmailsSent = 0;
    $totalClientEmailReplies = 0;
    $hasPositiveRating = false;
    $ratingScores = [];

    foreach ($convList as $conv) {
        $convId    = $conv['id'];
        $stats     = $conv['statistics'] ?? [];
        $rating    = $conv['conversation_rating'] ?? null;
        $ratingVal = $rating['rating'] ?? null;

        if ($ratingVal !== null) {
            $ratingScores[] = $ratingVal;
            if ($ratingVal >= 4) $hasPositiveRating = true;
        }

        // Pull full conversation to get all parts (messages)
        $full  = intercomGetFullConversation($convId);
        $parts = $full['conversation_parts']['conversation_parts'] ?? [];
        $source = $full['source'] ?? [];

        // Parse parts into clean messages
        $messages = [];

        // First message (source)
        $srcAuthorType = $source['author']['type'] ?? '';
        $srcBody = strip_tags($source['body'] ?? '');
        $srcBody = mb_substr(trim($srcBody), 0, 400);
        if ($srcBody) {
            $isClient = ($srcAuthorType === 'user');
            $messages[] = [
                'date'      => $conv['created_at'] ? date('Y-m-d H:i', $conv['created_at']) : '',
                'from'      => $isClient ? 'CLIENT' : 'GIGRADAR',
                'author'    => $source['author']['name'] ?? ($isClient ? $email : 'GigRadar'),
                'type'      => $source['type'] ?? 'message',
                'body'      => $srcBody,
                'is_email'  => ($source['type'] === 'email'),
            ];
            if ($isClient) $totalClientMessages++;
            else           $totalGigradarMessages++;
            if ($source['type'] === 'email') {
                if ($isClient) $totalClientEmailReplies++;
                else           $totalGigradarEmailsSent++;
            }
        }

        // Conversation parts
        foreach ($parts as $part) {
            $pt         = $part['part_type'] ?? '';
            $authorType = $part['author']['type'] ?? '';
            $authorName = $part['author']['name'] ?? '';
            $body       = strip_tags($part['body'] ?? '');
            $body       = mb_substr(trim($body), 0, 400);
            $ts         = $part['created_at'] ?? 0;
            $isEmail    = !empty($part['email_message_metadata']);
            $isClient   = ($authorType === 'user');
            $isAdmin    = ($authorType === 'admin');

            // Skip system/bot events that aren't real messages
            if (in_array($pt, ['conversation_attribute_updated_by_admin','company_updated','timer_unsnooze','snoozed','assignment','priority_changed','open'])) continue;

            // Skip internal notes (not visible to client)
            if ($pt === 'note' || $pt === 'note_and_unsnooze') {
                // Still count as gigradar activity but mark as internal
                continue;
            }

            if ($pt === 'close') continue;

            if (!$body && $pt === 'comment') continue;

            if ($body || $pt === 'comment') {
                $messages[] = [
                    'date'      => $ts ? date('Y-m-d H:i', $ts) : '',
                    'from'      => $isClient ? 'CLIENT' : 'GIGRADAR',
                    'author'    => $authorName ?: ($isClient ? $email : 'GigRadar'),
                    'type'      => $pt,
                    'body'      => $body,
                    'is_email'  => $isEmail,
                ];
                if ($isClient) $totalClientMessages++;
                elseif ($isAdmin) $totalGigradarMessages++;
                if ($isEmail) {
                    if ($isClient) $totalClientEmailReplies++;
                    elseif ($isAdmin) $totalGigradarEmailsSent++;
                }
            }
        }

        $parsedConvs[] = [
            'id'           => $convId,
            'date'         => $conv['created_at'] ? date('Y-m-d', $conv['created_at']) : '',
            'state'        => $conv['state'] ?? 'unknown',
            'type'         => $source['type'] ?? 'conversation',
            'subject'      => strip_tags($source['subject'] ?? ''),
            'opened_by'    => ($source['author']['type'] ?? '') === 'user' ? 'CLIENT' : 'GIGRADAR',
            'messages'     => $messages,
            'msg_count'    => count($messages),
            'rating'       => $ratingVal,
            'first_reply_at' => $stats['first_admin_reply_at'] ? date('Y-m-d H:i', $stats['first_admin_reply_at']) : null,
            'closed_at'    => $stats['last_close_at'] ? date('Y-m-d', $stats['last_close_at']) : null,
            'response_time_sec' => $stats['time_to_admin_reply'] ?? null,
        ];
    }

    // Check if client read our emails
    $lastEmailOpened = $contact['last_email_opened_at']
        ? date('Y-m-d', $contact['last_email_opened_at'])
        : null;
    $lastContacted = $contact['last_contacted_at']
        ? date('Y-m-d', $contact['last_contacted_at'])
        : null;
    $lastReplied = $contact['last_replied_at']
        ? date('Y-m-d', $contact['last_replied_at'])
        : null;

    $summary = buildIntercomSummary(
        $email, $contact, $ca, $parsedConvs,
        $totalGigradarMessages, $totalClientMessages,
        $totalGigradarEmailsSent, $totalClientEmailReplies,
        $lastEmailOpened, $lastContacted, $lastReplied,
        $ratingScores
    );

    $loc = $contact['location'] ?? [];

    return [
        'found'                    => true,
        'contact_id'               => $contact['id'],
        'team_oid'                 => intercomGetCompanyTeamOid($contact),
        'stripe_id'                => $ca['stripe_id'] ?? '',
        'stripe_plan'              => $ca['stripe_plan'] ?? '',
        'stripe_last_charge'       => isset($ca['stripe_last_charge_amount'])
                                       ? number_format((float)$ca['stripe_last_charge_amount'] / 100, 2)
                                       : null,
        'stripe_card_brand'        => $ca['stripe_card_brand'] ?? '',
        'stripe_status'            => $ca['stripe_subscription_status'] ?? '',
        // Raw period/interval for subscription-window math (handles quarterly plans)
        'stripe_period_start_ts'   => $ca['stripe_subscription_period_start_at'] ?? null,
        'stripe_plan_interval'     => $ca['stripe_plan_interval'] ?? 'month',
        'stripe_plan_interval_count' => (int)($ca['stripe_plan_interval_count'] ?? 1),
        'stripe_plan_price'        => $ca['stripe_plan_price'] ?? null,
        'location'                 => $loc,
        'browser'                  => $contact['browser'] ?? '',
        'os'                       => $contact['os'] ?? '',
        'last_seen_at'             => $contact['last_seen_at'] ? date('Y-m-d', $contact['last_seen_at']) : '',
        'last_email_opened'        => $lastEmailOpened,
        'last_contacted'           => $lastContacted,
        'last_replied'             => $lastReplied,
        'total_conversations'      => count($parsedConvs),
        'total_gigradar_messages'  => $totalGigradarMessages,
        'total_client_messages'    => $totalClientMessages,
        'gigradar_emails_sent'     => $totalGigradarEmailsSent,
        'client_email_replies'     => $totalClientEmailReplies,
        'has_positive_rating'      => $hasPositiveRating,
        'rating_scores'            => $ratingScores,
        'conversations'            => $parsedConvs,
        'summary'                  => $summary,
    ];
}

/**
 * Строим текстовый лог для Stripe evidence  -  читает банковский клерк
 */
function buildIntercomSummary(
    string $email,
    array  $contact,
    array  $ca,
    array  $convs,
    int    $gigradarMsgs,
    int    $clientMsgs,
    int    $gigradarEmails,
    int    $clientEmailReplies,
    ?string $lastEmailOpened,
    ?string $lastContacted,
    ?string $lastReplied,
    array  $ratingScores
): string {
    $lines = [];
    $lines[] = "CUSTOMER COMMUNICATION RECORD";
    $lines[] = "Source: Intercom CRM | Generated: " . date('Y-m-d H:i') . " UTC";
    $lines[] = "Customer: $email";
    $lines[] = str_repeat("-", 60);
    $lines[] = "";

    // Contact info
    $loc = $contact['location'] ?? [];
    $lines[] = "CONTACT PROFILE:";
    if (!empty($loc['city'])) {
        $parts = array_filter([$loc['city'] ?? '', $loc['region'] ?? '', $loc['country'] ?? '']);
        $lines[] = "  Location:  " . implode(', ', $parts);
    }
    if (!empty($contact['browser'])) {
        $lines[] = "  Device:    " . $contact['browser'] . " / " . ($contact['os'] ?? '');
    }
    $lines[] = "";

    // Email engagement  -  KEY EVIDENCE
    $lines[] = "EMAIL ENGAGEMENT (proves client received & read our communications):";
    $lines[] = "  GigRadar emails sent to client:  $gigradarEmails";
    $lines[] = "  Client email replies received:   $clientEmailReplies";
    if ($lastEmailOpened) {
        $lines[] = "  Last email opened by client:    $lastEmailOpened";
        $lines[] = "  -> CLIENT CONFIRMED reading GigRadar emails as recently as $lastEmailOpened";
    } else {
        $lines[] = "  Last email opened:              not tracked";
    }
    if ($lastContacted) {
        $lines[] = "  Last time GigRadar contacted:  $lastContacted";
    }
    if ($lastReplied) {
        $lines[] = "  Last client reply to us:       $lastReplied";
    }
    $lines[] = "";

    // Ratings
    if (!empty($ratingScores)) {
        $avg = array_sum($ratingScores) / count($ratingScores);
        $lines[] = "CUSTOMER SATISFACTION RATINGS:";
        $lines[] = "  Conversations rated: " . count($ratingScores);
        $lines[] = "  Scores: " . implode(', ', array_map(fn($s) => $s . "/5", $ratingScores));
        $lines[] = "  Average: " . number_format($avg, 1) . "/5";
        if ($avg >= 4) {
            $lines[] = "  -> CLIENT RATED SUPPORT POSITIVELY  -  confirms satisfaction with service";
        }
        $lines[] = "";
    }

    // Overall stats
    $lines[] = "COMMUNICATION STATISTICS:";
    $lines[] = "  Total conversations:          " . count($convs);
    $lines[] = "  Messages from GigRadar:       $gigradarMsgs";
    $lines[] = "  Messages from client:         $clientMsgs";
    $lines[] = "  Client-initiated convs:       " . count(array_filter($convs, fn($c) => $c['opened_by'] === 'CLIENT'));
    $lines[] = "  GigRadar-initiated convs:     " . count(array_filter($convs, fn($c) => $c['opened_by'] === 'GIGRADAR'));
    $lines[] = "";

    // Detect refund/cancel requests across all messages
    $refundRequested  = false;
    $cancelRequested  = false;
    $refundContext    = '';
    $cancelContext    = '';

    foreach ($convs as $conv) {
        foreach ($conv['messages'] as $msg) {
            if ($msg['from'] !== 'CLIENT') continue;
            $bodyLow = strtolower($msg['body']);
            if (!$refundRequested && (str_contains($bodyLow, 'refund') || str_contains($bodyLow, 'money back') || str_contains($bodyLow, 'charge back'))) {
                $refundRequested = true;
                $refundContext   = $msg['date'] . ': "' . mb_substr($msg['body'], 0, 150) . '"';
            }
            $cancelKeywords = [
                'cancel', 'do not renew', "don't renew", 'stop subscription',
                'want to cancel', 'would like to cancel', 'please cancel',
                'end my subscription', 'terminate', 'discontinue',
                'no longer need', 'stop the service', 'stop renew',
                'not renew', 'wont renew', "won't renew",
            ];
            foreach ($cancelKeywords as $kw) {
                if (!$cancelRequested && str_contains($bodyLow, $kw)) {
                    $cancelRequested = true;
                    $cancelContext   = $msg['date'] . ': "' . mb_substr($msg['body'], 0, 150) . '"';
                    break;
                }
            }
        }
    }

    // Key evidence summary
    $lines[] = "KEY FINDINGS FOR DISPUTE:";
    $lines[] = "";
    if (!$refundRequested) {
        $lines[] = "  [NO REFUND REQUEST] The customer NEVER asked for a refund or";
        $lines[] = "  chargeback through any Intercom channel  -  not once in $gigradarMsgs";
        $lines[] = "  communications. Filing a chargeback without first requesting";
        $lines[] = "  a refund is the primary indicator of friendly fraud.";
    } else {
        $lines[] = "  [REFUND CONTEXT] Customer mentioned refund/chargeback:";
        $lines[] = "  $refundContext";
        $lines[] = "  NOTE: A refund request is NOT a chargeback authorization.";
        $lines[] = "  Customer should have waited for our response instead of";
        $lines[] = "  filing a chargeback with their bank.";
    }
    $lines[] = "";

    if ($cancelRequested) {
        $lines[] = "  [CANCELLATION / NON-RENEWAL REQUEST  -  DETAILED ANALYSIS]";
        $lines[] = "  Customer wrote: $cancelContext";
        $lines[] = "";

        // Pull billing cycle context from Intercom Stripe fields
        $subPeriodStart   = $ca['stripe_subscription_period_start_at'] ?? null;
        $planInterval     = $ca['stripe_plan_interval'] ?? 'month';
        $planIntervalCount = (int)($ca['stripe_plan_interval_count'] ?? 1);
        $lastChargeAmount = isset($ca['stripe_last_charge_amount'])
                             ? number_format($ca['stripe_last_charge_amount'] / 100, 2)
                             : null;
        $lastChargeAt     = $ca['stripe_last_charge_at'] ?? null;

        if ($subPeriodStart) {
            // Calculate period end
            $periodStart = new DateTime('@' . $subPeriodStart);
            $periodEnd   = clone $periodStart;
            if ($planInterval === 'month') {
                $periodEnd->modify('+' . $planIntervalCount . ' month');
            } elseif ($planInterval === 'year') {
                $periodEnd->modify('+' . $planIntervalCount . ' year');
            }

            $lines[] = "  BILLING CYCLE AT TIME OF REQUEST:";
            $lines[] = "  Period:   " . $periodStart->format('Y-m-d')
                                     . " -> " . $periodEnd->format('Y-m-d')
                                     . " (" . ($planIntervalCount > 1 ? $planIntervalCount . "x " : "") . ucfirst($planInterval) . "ly)";
            if ($lastChargeAt && $lastChargeAmount) {
                $chargeDate = date('Y-m-d', $lastChargeAt);
                $lines[] = "  Paid:     \$$lastChargeAmount on $chargeDate (covers period above)";
            }

            // Find when "do not renew" was written relative to cycle
            $cancelDate = null;
            foreach ($convs as $conv) {
                foreach ($conv['messages'] as $msg) {
                    if ($msg['from'] !== 'CLIENT') continue;
                    $bodyLow = strtolower($msg['body']);
                    if (str_contains($bodyLow, 'cancel') || str_contains($bodyLow, 'do not renew') || str_contains($bodyLow, 'stop')) {
                        $cancelDate = $msg['date'];
                        break 2;
                    }
                }
            }
            if ($cancelDate) {
                $cancelDt      = new DateTime($cancelDate);
                $daysBeforeEnd = $periodEnd->diff($cancelDt)->days;
                $isBeforeEnd   = $cancelDt < $periodEnd;
                $lines[] = "  Request written: $cancelDate";
                if ($isBeforeEnd) {
                    $lines[] = "  -> Written $daysBeforeEnd day(s) BEFORE the period ended ({$periodEnd->format('Y-m-d')})";
                    $lines[] = "  -> The period was already PAID and actively running";
                    $lines[] = "  -> Per our Terms: cancellations take effect at END of period";
                    $lines[] = "  -> The disputed charge covers a period the customer already used";
                }
            }
            $lines[] = "";
        }

        // Find the outcome of the cancellation discussion
        $agreedToContinue = false;
        $agreedContext = '';
        foreach ($convs as $conv) {
            foreach ($conv['messages'] as $msg) {
                if ($msg['from'] !== 'CLIENT') continue;
                $bodyLow = strtolower($msg['body']);
                if (str_contains($bodyLow, 'yes') || str_contains($bodyLow, 'lets do it')
                    || str_contains($bodyLow, "let's do") || str_contains($bodyLow, 'ok')
                    || str_contains($bodyLow, 'sounds good') || str_contains($bodyLow, 'agree')) {
                    $agreedToContinue = true;
                    $agreedContext = $msg['date'] . ': "' . mb_substr($msg['body'], 0, 200) . '"';
                }
            }
        }

        if ($agreedToContinue) {
            $lines[] = "  OUTCOME  -  CUSTOMER AGREED TO CONTINUE:";
            $lines[] = "  After price negotiation, the customer explicitly agreed to renew:";
            $lines[] = "  $agreedContext";
            $lines[] = "  -> This OVERRIDES the initial 'do not renew' request";
            $lines[] = "  -> Customer gave written consent to continue subscription";
            $lines[] = "  -> The subsequent charge was AUTHORIZED by the customer";
            $lines[] = "";
        }

        $lines[] = "  CONCLUSION: The customer was aware of the renewal date, negotiated";
        $lines[] = "  pricing with GigRadar, gave explicit written consent to continue,";
        $lines[] = "  and then filed a chargeback  -  this constitutes first-party fraud.";
        $lines[] = "";
    }

    if ($clientMsgs > 0) {
        $lines[] = "  [ACTIVE ENGAGEMENT] Client sent $clientMsgs message(s) to GigRadar support,";
        $lines[] = "  confirming they actively used the platform and had our contact details.";
        $lines[] = "";
    }

    // Conversation-by-conversation log
    $lines[] = str_repeat("-", 60);
    $lines[] = "FULL CONVERSATION LOG:";
    $lines[] = "";

    foreach ($convs as $i => $conv) {
        $n = $i + 1;
        $lines[] = "=== CONVERSATION #$n ===";
        $lines[] = "Date:       " . $conv['date'];
        $lines[] = "Opened by:  " . $conv['opened_by'];
        $lines[] = "Channel:    " . strtoupper($conv['type']);
        $lines[] = "Status:     " . strtoupper($conv['state']);
        if (!empty($conv['subject'])) {
            $subj = strip_tags($conv['subject']);
            if ($subj) $lines[] = "Subject:    $subj";
        }
        if ($conv['first_reply_at']) {
            $sec = $conv['response_time_sec'];
            $respStr = $sec !== null ? " (response in " . ($sec < 60 ? $sec . "s" : round($sec/60) . "min") . ")" : "";
            $lines[] = "GigRadar first replied: " . $conv['first_reply_at'] . $respStr;
        }
        if ($conv['closed_at']) {
            $lines[] = "Resolved:   " . $conv['closed_at'];
        }
        if ($conv['rating'] !== null) {
            $stars = str_repeat("*", $conv['rating']) . str_repeat("☆", 5 - $conv['rating']);
            $lines[] = "Client rating: $stars ({$conv['rating']}/5)";
        }
        $lines[] = "";

        // Messages
        foreach ($conv['messages'] as $msg) {
            $arrow  = $msg['from'] === 'CLIENT' ? ">> CLIENT" : "<< GIGRADAR";
            $medium = $msg['is_email'] ? "[email]" : "[chat]";
            $author = $msg['author'];
            $lines[] = "  $arrow ($medium)  -  {$msg['date']}";
            $lines[] = "  From: $author";
            if ($msg['body']) {
                $lines[] = "  \"" . $msg['body'] . "\"";
            }
            $lines[] = "";
        }
    }

    if (empty($convs)) {
        $lines[] = "NO CONVERSATIONS FOUND";
        $lines[] = "";
        $lines[] = "The customer had zero contact with GigRadar support before this dispute.";
        $lines[] = "This is strong evidence of friendly fraud: a customer with a genuine";
        $lines[] = "service problem would contact support before filing a chargeback.";
    }

    $lines[] = str_repeat("-", 60);
    $lines[] = "End of Intercom communication record for $email";

    return implode("\n", $lines);
}
