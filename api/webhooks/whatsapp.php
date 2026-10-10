<?php
/**
 * CUBOIDPILOT — SHARED WHATSAPP WEBHOOK ROUTER
 * Handles incoming WhatsApp webhook events (Meta Cloud API / Simulated).
 * Resolves tenant safely without cross-tenant guessing.
 * Links handoff tokens, updates CRM leads, persists conversation messages,
 * and generates grounded AI responses.
 */

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../entitlements.php';
require_once __DIR__ . '/../../includes/customer_identity_resolver.php';
require_once __DIR__ . '/../../includes/customer_journey_service.php';
require_once __DIR__ . '/../../includes/channel_handoff_service.php';
require_once __DIR__ . '/../../includes/asset_helper.php';
require_once __DIR__ . '/../../includes/gemini_service.php';
require_once __DIR__ . '/../../includes/whatsapp_bridge.php';
require_once __DIR__ . '/../../includes/whatsapp_automation_service.php';

$pdo = getDbConnection();

// 1. Webhook Verification (GET request from Meta)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $hubMode        = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
    $hubChallenge   = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';
    $hubVerifyToken = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';

    // Verify token matches configured tenant or global secret
    if ($hubMode === 'subscribe' && !empty($hubChallenge)) {
        $universalTokens = [
            'cuboidsoft',
            'cuboidpilot',
            'cai',
            'cai_ai',
            'cai_meta_verify',
            'cuboid_ig_verify',
            'cuboid_meta_verify',
            'cuboid_instagram_secret',
            'cai_webhook_secret',
            'cp_ig_verify_d12586b4c00064dd'
        ];
        $matched = in_array((string)$hubVerifyToken, $universalTokens, true);

        if (!$matched) {
            $chk = $pdo->prepare("SELECT 1 FROM `whatsapp_accounts` WHERE `webhook_verify_token` = ? AND `webhook_verify_token` IS NOT NULL AND `webhook_verify_token` != '' LIMIT 1");
            $chk->execute([$hubVerifyToken]);
            $matched = (bool)$chk->fetch();
        }

        if (!$matched && !empty(getenv('META_VERIFY_TOKEN')) && hash_equals(getenv('META_VERIFY_TOKEN'), (string)$hubVerifyToken)) {
            $matched = true;
        }

        if (!$matched && !empty(getenv('WHATSAPP_VERIFY_TOKEN')) && hash_equals(getenv('WHATSAPP_VERIFY_TOKEN'), (string)$hubVerifyToken)) {
            $matched = true;
        }

        if ($matched) {
            header("Content-Type: text/plain");
            echo $hubChallenge;
            exit;
        }

        http_response_code(403);
        echo "Verification token mismatch";
        exit;
    }

    echo json_encode(['status' => 'active', 'service' => 'CuboidPilot WhatsApp Webhook Hub']);
    exit;
}

// 2. Incoming Event Processing (POST)
$rawPayload = file_get_contents('php://input');
if (empty($rawPayload) && !empty($GLOBALS['TEST_PAYLOAD'])) {
    $rawPayload = is_string($GLOBALS['TEST_PAYLOAD']) ? $GLOBALS['TEST_PAYLOAD'] : json_encode($GLOBALS['TEST_PAYLOAD']);
}
$data = json_decode($rawPayload, true) ?? [];

if (empty($data)) {
    echo json_encode(['status' => 'ignored', 'reason' => 'empty_payload']);
    exit;
}

try {
    // Extract incoming sender, text, waba_id, context_id, media and recipient phone from payload
    // Supports both standard Meta Graph Webhook format and clean Direct format
    $senderPhone = '';
    $messageText = '';
    $wabaId      = '';
    $destPhone   = '';
    $contextWaId = '';
    $mediaType   = 'text';
    $mediaObj    = null;

    // Handle Meta Status Updates (Sent, Delivered, Read)
    if (!empty($data['entry'][0]['changes'][0]['value']['statuses'][0])) {
        $stObj = $data['entry'][0]['changes'][0]['value']['statuses'][0];
        $stId = $stObj['id'] ?? '';
        $stVal = $stObj['status'] ?? '';
        if (!empty($stId) && !empty($stVal)) {
            $pdo->prepare("UPDATE `whatsapp_messages` SET `status` = ? WHERE `metadata_json` LIKE ?")
                ->execute([$stVal, '%"wamid":"' . $stId . '"%']);
            echo json_encode(['status' => 'status_updated', 'wa_id' => $stId, 'delivery_status' => $stVal]);
            exit;
        }
    }

    // Meta Webhook Format
    if (!empty($data['entry'][0]['changes'][0]['value'])) {
        $changeVal = $data['entry'][0]['changes'][0]['value'];
        $wabaId = $data['entry'][0]['id'] ?? '';
        $destPhone = $changeVal['metadata']['display_phone_number'] ?? '';
        if (!empty($changeVal['messages'][0])) {
            $msgObj = $changeVal['messages'][0];
            $senderPhone = $msgObj['from'] ?? '';
            $contextWaId = $msgObj['context']['id'] ?? '';
            $type = $msgObj['type'] ?? 'text';

            if ($type === 'text') {
                $messageText = $msgObj['text']['body'] ?? '';
            } elseif ($type === 'image') {
                $messageText = $msgObj['image']['caption'] ?? '[Attached Image]';
                $mediaType = 'image';
                $mediaObj = $msgObj['image'];
            } elseif ($type === 'document') {
                $messageText = $msgObj['document']['caption'] ?? ($msgObj['document']['filename'] ?? '[Attached Document]');
                $mediaType = 'document';
                $mediaObj = $msgObj['document'];
            } elseif ($type === 'button') {
                $messageText = $msgObj['button']['text'] ?? '';
            } elseif ($type === 'interactive') {
                $messageText = $msgObj['interactive']['button_reply']['title'] ?? ($msgObj['interactive']['list_reply']['title'] ?? '');
            } else {
                $messageText = $msgObj['text']['body'] ?? '';
            }
        }
    } else {
        // Direct / Simulated JSON Format
        $senderPhone = $data['sender_phone'] ?? $data['from'] ?? $data['phone'] ?? '';
        $messageText = $data['message'] ?? $data['text'] ?? $data['body'] ?? '';
        $wabaId      = $data['waba_id'] ?? '';
        $destPhone   = $data['to'] ?? $data['display_number'] ?? '';
        $contextWaId = $data['context_id'] ?? ($data['context']['id'] ?? '');
        $mediaType   = $data['media_type'] ?? 'text';
        $mediaObj    = $data['media'] ?? null;
    }

    $cleanSender = preg_replace('/[^0-9]/', '', $senderPhone);
    $cleanDest   = preg_replace('/[^0-9]/', '', $destPhone);

    // If no message text or sender, log and exit
    if (empty($cleanSender) || empty($messageText)) {
        $pdo->prepare("
            INSERT INTO `webhook_events` (`provider`, `event_name`, `payload_json`, `is_processed`, `created_at`)
            VALUES ('whatsapp', 'empty_message', ?, 1, NOW())
        ")->execute([$rawPayload]);
        echo json_encode(['status' => 'ok', 'note' => 'No message body to process']);
        exit;
    }

    // Deduplication Protection: Extract Meta Message ID if available
    $metaMessageId = $data['entry'][0]['changes'][0]['value']['messages'][0]['id'] ?? ($data['message_id'] ?? ($data['id'] ?? ''));
    if (!empty($metaMessageId)) {
        $chkDup = $pdo->prepare("SELECT id FROM `webhook_events` WHERE `provider` = 'whatsapp' AND `event_name` = ? LIMIT 1");
        $chkDup->execute(['msg_' . $metaMessageId]);
        if ($chkDup->fetch()) {
            echo json_encode(['status' => 'duplicate_ignored', 'message_id' => $metaMessageId]);
            exit;
        }
        try {
            $pdo->prepare("INSERT INTO `webhook_events` (`provider`, `event_name`, `payload_json`, `is_processed`, `created_at`) VALUES ('whatsapp', ?, ?, 0, NOW())")
                ->execute(['msg_' . $metaMessageId, $rawPayload]);
        } catch (Throwable $e) {}
    }

    // 2a. Priority Human Support Agent Resolve / Close Conversation via WhatsApp
    // Supports patterns: "RESOLVE #123", "RESOLVE 123", "CLOSE #123", "END #123"
    if (preg_match('/^(?:resolve|close|end)\s*#?\s*([0-9]+)$/is', trim($messageText), $resolveMatch)) {
        $targetConvId = (int)$resolveMatch[1];
        $tcStmt = $pdo->prepare("SELECT c.*, cust.name as customer_name, comp.name as company_name FROM `conversations` c JOIN `companies` comp ON comp.id = c.company_id LEFT JOIN `customers` cust ON cust.id = c.customer_id WHERE c.id = ? LIMIT 1");
        $tcStmt->execute([$targetConvId]);
        $targetConv = $tcStmt->fetch(PDO::FETCH_ASSOC);

        if ($targetConv) {
            $convCompanyId = (int)$targetConv['company_id'];
            $compName = $targetConv['company_name'] ?: 'CuboidSoft';
            $custName = $targetConv['customer_name'] ?: 'Visitor';

            // Mark conversation as resolved
            $pdo->prepare("
                UPDATE `conversations`
                SET `status` = 'closed',
                    `closure_reason` = 'resolved_by_agent',
                    `closed_at` = NOW(),
                    `last_message_at` = NOW()
                WHERE id = ? AND company_id = ?
            ")->execute([$targetConvId, $convCompanyId]);

            // Add system note
            $pdo->prepare("
                INSERT INTO `messages` (`company_id`, `conversation_id`, `sender_type`, `message_text`, `channel`, `created_at`)
                VALUES (?, ?, 'system', '[System Event] Conversation marked as resolved by support agent via WhatsApp.', 'whatsapp', NOW())
            ")->execute([$convCompanyId, $targetConvId]);

            // Close handoff
            try {
                $pdo->prepare("UPDATE `human_handoffs` SET `status` = 'completed', `call_completed_at` = NOW() WHERE `conversation_id` = ? AND `company_id` = ?")
                    ->execute([$targetConvId, $convCompanyId]);
            } catch (Throwable $hEx) {}

            // Send confirmation WhatsApp message back to agent
            try {
                $waStmt = $pdo->prepare("SELECT phone_number_id, whatsapp_access_token FROM `whatsapp_accounts` WHERE `company_id` = ? AND `status` = 'connected' LIMIT 1");
                $waStmt->execute([$convCompanyId]);
                $waAcc = $waStmt->fetch(PDO::FETCH_ASSOC);

                if ($waAcc && !empty($waAcc['phone_number_id']) && !empty($waAcc['whatsapp_access_token'])) {
                    $ackReply = "*{$compName} Support Desk*\n\n"
                        . "*Conversation Resolved*\n"
                        . "Conversation #CONV-{$targetConvId} with {$custName} has been closed.\n\n"
                        . "— Powered by Cai (CuboidSoft AI)";
                    $endpoint = "https://graph.facebook.com/v20.0/{$waAcc['phone_number_id']}/messages";
                    $payload = [
                        'messaging_product' => 'whatsapp',
                        'to'                => $cleanSender,
                        'type'              => 'text',
                        'text'              => ['body' => $ackReply]
                    ];
                    $ch = curl_init($endpoint);
                    curl_setopt_array($ch, [
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => json_encode($payload),
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPHEADER     => [
                            'Authorization: Bearer ' . $waAcc['whatsapp_access_token'],
                            'Content-Type: application/json'
                        ],
                        CURLOPT_TIMEOUT        => 4
                    ]);
                    @curl_exec($ch);
                    curl_close($ch);
                }
            } catch (Throwable $waAckEx) {}

            echo json_encode([
                'status' => 'conversation_resolved',
                'target_conversation_id' => $targetConvId
            ]);
            exit;
        }
    }

    // 2b. Two-Way WhatsApp Bridge for Team Members (Native Reply & Contextual Mapping)
    $context = WhatsAppBridge::resolveIncomingContext($pdo, $contextWaId, $cleanSender, $wabaId, $messageText);

    // If message is an automation trigger or user is testing bot automations, bypass agent bridge and process automation
    if (($context['status'] ?? '') === 'automation_trigger') {
        // Fall through cleanly to Customer Identity & Automation Engine
    } elseif ($context['status'] === 'ambiguous_sessions') {
        // If ambiguous sessions detected for unquoted agent message, do NOT guess or send to another visitor!
        WhatsAppBridge::sendAgentRejection(
            $pdo,
            $context['company_id'],
            $cleanSender,
            "⚠️ You have multiple active visitor conversations. Please use WhatsApp's native Reply feature (swipe or reply to the specific visitor message) to send your message to the correct session."
        );
        echo json_encode([
            'status' => 'ambiguous_reply_blocked',
            'error'  => 'Multiple active sessions found. Native Reply required to map to the correct visitor.'
        ]);
        exit;
    }

    if (in_array($context['status'], ['matched_reply', 'matched_single_active'], true)) {
        $targetConvId = (int)$context['conversation_id'];
        $convCompanyId = (int)$context['company_id'];
        $sessionId = $context['session_id'];
        $agentUserId = (int)$context['user_id'];
        $agentName = $context['user_name'];
        $agentReplyBody = trim($messageText);

        // ====================================================================
        // STRICT TEAM COMMAND: "STOP" (Hands off back to AI)
        // If assigned team member sends "STOP", end human handling, resume AI,
        // send AI feedback message to visitor, and NEVER display "STOP" to visitor.
        // ====================================================================
        if (strtoupper($agentReplyBody) === 'STOP') {
            $stopResult = WhatsAppBridge::executeHandoffStop(
                $pdo,
                $convCompanyId,
                $targetConvId,
                $agentUserId,
                $senderPhone
            );
            echo json_encode([
                'success' => true,
                'action'  => 'handoff_stopped',
                'details' => $stopResult
            ]);
            exit;
        }

        $attachmentMeta = null;
        if (!empty($mediaObj['id'])) {
            $dlResult = WhatsAppBridge::downloadWhatsAppMedia($pdo, $convCompanyId, $mediaObj['id']);
            if ($dlResult) {
                $attachmentMeta = $dlResult;
                $tagType = $dlResult['is_image'] ? 'Image' : 'Document';
                $attTag = "[Attached {$tagType}: {$dlResult['file_name']}]({$dlResult['url']})";
                $agentReplyBody = (!empty($agentReplyBody) && $agentReplyBody !== '[Attached Image]' && $agentReplyBody !== '[Attached Document]' && $agentReplyBody !== $dlResult['file_name'])
                    ? "{$agentReplyBody}\n{$attTag}"
                    : $attTag;
            }
        }

        // Insert message as human in canonical messages table with session_id
        $pdo->prepare("
            INSERT INTO `messages` (`company_id`, `conversation_id`, `session_id`, `sender_type`, `sender_id`, `message_text`, `channel`, `metadata_json`, `created_at`)
            VALUES (?, ?, ?, 'human', ?, ?, 'whatsapp', ?, NOW())
        ")->execute([
            $convCompanyId,
            $targetConvId,
            $sessionId,
            $agentUserId,
            $agentReplyBody,
            $attachmentMeta ? json_encode(['attachment' => $attachmentMeta], JSON_UNESCAPED_UNICODE) : null
        ]);
        $newMsgId = (int)$pdo->lastInsertId();

        // Update conversation to human_active and reopen if closed
        $pdo->prepare("
            UPDATE `conversations`
            SET `ownership` = 'human',
                `status` = 'human_active',
                `closure_reason` = NULL,
                `closed_at` = NULL,
                `assigned_user_id` = ?,
                `last_message_preview` = ?,
                `last_message_at` = NOW(),
                `unread_human` = 0
            WHERE id = ? AND company_id = ?
        ")->execute([$agentUserId, substr($agentReplyBody, 0, 150), $targetConvId, $convCompanyId]);

        // Update human_handoffs if pending
        try {
            $pdo->prepare("
                UPDATE `human_handoffs`
                SET `status` = 'active',
                    `assigned_to_user_id` = ?,
                    `accepted_at` = COALESCE(`accepted_at`, NOW())
                WHERE `conversation_id` = ? AND `status` = 'pending'
            ")->execute([$agentUserId, $targetConvId]);
        } catch (Throwable $hEx) {}

        // Record incoming message in whatsapp_message_mappings to support chaining replies
        if (!empty($metaMessageId)) {
            try {
                $pdo->prepare("
                    INSERT INTO `whatsapp_message_mappings`
                    (`company_id`, `session_id`, `conversation_id`, `message_id`, `wa_message_id`, `recipient_phone`, `direction`, `created_at`)
                    VALUES (?, ?, ?, ?, ?, ?, 'inbound', NOW())
                    ON DUPLICATE KEY UPDATE `message_id` = VALUES(`message_id`)
                ")->execute([$convCompanyId, $sessionId, $targetConvId, $newMsgId, $metaMessageId, $cleanSender]);
            } catch (Throwable $mEx) {}
        }

        echo json_encode([
            'status'                 => 'agent_reply_delivered',
            'target_conversation_id' => $targetConvId,
            'session_id'             => $sessionId,
            'message'                => $agentReplyBody,
            'agent'                  => $agentName
        ]);
        exit;
    }

    // 2c. If the sender is an authorized company user but has no active session:
    // Check if they typed a legacy #ID command to reply to a conversation
    if ($context['status'] === 'no_active_session') {
        if (preg_match('/^(?:reply\s*#?|#)\s*([0-9]+)\s+(.+)$/is', $messageText, $cmdMatch)) {
            $targetConvId = (int)$cmdMatch[1];
            $agentReplyBody = trim($cmdMatch[2]);
            $convCompanyId = $context['company_id'];
            $agentUserId = $context['user_id'];
            $agentName = $context['user_name'];

            $tcStmt = $pdo->prepare("SELECT id, session_id FROM `conversations` WHERE id = ? AND company_id = ? LIMIT 1");
            $tcStmt->execute([$targetConvId, $convCompanyId]);
            $tcRow = $tcStmt->fetch(PDO::FETCH_ASSOC);

            if ($tcRow) {
                $sessionId = $tcRow['session_id'] ?: (string)$targetConvId;
                $pdo->prepare("
                    INSERT INTO `messages` (`company_id`, `conversation_id`, `session_id`, `sender_type`, `sender_id`, `message_text`, `channel`, `created_at`)
                    VALUES (?, ?, ?, 'human', ?, ?, 'whatsapp', NOW())
                ")->execute([$convCompanyId, $targetConvId, $sessionId, $agentUserId, $agentReplyBody]);

                $pdo->prepare("
                    UPDATE `conversations`
                    SET `ownership` = 'human', `status` = 'human_active', `closure_reason` = NULL, `closed_at` = NULL,
                        `assigned_user_id` = ?, `last_message_preview` = ?, `last_message_at` = NOW(), `unread_human` = 0
                    WHERE id = ? AND company_id = ?
                ")->execute([$agentUserId, substr($agentReplyBody, 0, 150), $targetConvId, $convCompanyId]);

                echo json_encode(['status' => 'agent_reply_delivered', 'target_conversation_id' => $targetConvId]);
                exit;
            }
        }
        // If not a #ID reply command, the user (owner/agent) is testing or messaging the bot!
        // Allow execution to fall through to Customer Identity & Automation Engine below.
    }

    // 3. Multi-Tenant & Omnichannel Customer Identity Resolution
    $identity = CustomerIdentityResolver::resolveFromWhatsApp(
        $pdo,
        $cleanSender,
        $messageText,
        $wabaId,
        $cleanDest
    );

    $resolvedCompanyId = $identity['company_id'];
    $customerId = $identity['customer_id'];
    $customer = $identity['customer'];
    $matchedHandoff = $identity['handoff'];
    $leadId = $identity['lead_id'];

    // If resolution fails: Log to webhook_events with UNRESOLVED_TENANT, return HTTP 200
    if (!$resolvedCompanyId) {
        $pdo->prepare("
            INSERT INTO `webhook_events` (`provider`, `event_name`, `payload_json`, `is_processed`, `created_at`)
            VALUES ('whatsapp', 'UNRESOLVED_TENANT', ?, 0, NOW())
        ")->execute([$rawPayload]);

        echo json_encode([
            'status' => 'ok',
            'action' => 'logged_unresolved',
            'message' => 'Unable to determine tenant workspace without ambiguity'
        ]);
        exit;
    }

    // Verify company & entitlement
    $companyStmt = $pdo->prepare("SELECT id, name, slug FROM `companies` WHERE id = ? LIMIT 1");
    $companyStmt->execute([$resolvedCompanyId]);
    $company = $companyStmt->fetch();

    if (!checkEntitlement($pdo, $resolvedCompanyId, 'can_use_whatsapp')) {
        echo json_encode([
            'status' => 'ok',
            'action' => 'ignored_entitlement_disabled',
            'company_id' => $resolvedCompanyId
        ]);
        exit;
    }

    $customerName = !empty($customer['name']) && $customer['name'] !== 'Website Visitor' ? $customer['name'] : 'WhatsApp Prospect';

    // 4. Omnichannel Journey & Unified Conversation Resolution
    $conversationId = null;
    if ($matchedHandoff && !empty($matchedHandoff['web_conversation_id'])) {
        $conversationId = (int)$matchedHandoff['web_conversation_id'];
    }
    if (!$conversationId && !empty($identity['conversation_id'])) {
        $conversationId = (int)$identity['conversation_id'];
    }

    $journey = CustomerJourneyService::getOrCreateJourney(
        $pdo,
        $resolvedCompanyId,
        $customerId,
        $leadId,
        'whatsapp',
        null,
        $conversationId
    );

    if (!$conversationId && !empty($journey['conversation_id'])) {
        $conversationId = (int)$journey['conversation_id'];
    }

    if (!$conversationId) {
        $cStmt = $pdo->prepare("SELECT id FROM `conversations` WHERE `company_id` = ? AND `customer_id` = ? ORDER BY id DESC LIMIT 1");
        $cStmt->execute([$resolvedCompanyId, $customerId]);
        $conversationId = (int)($cStmt->fetchColumn() ?: 0);
    }

    if ($conversationId) {
        $convCheckStmt = $pdo->prepare("SELECT ownership, status FROM `conversations` WHERE id = ? AND company_id = ? LIMIT 1");
        $convCheckStmt->execute([$conversationId, $resolvedCompanyId]);
        $activeConv = $convCheckStmt->fetch(PDO::FETCH_ASSOC);

        $pdo->prepare("
            UPDATE `conversations`
            SET `channel` = 'whatsapp',
                `last_message_preview` = ?,
                `last_message_at` = NOW(),
                `unread_human` = IF(`ownership` = 'human', unread_human + 1, unread_human)
            WHERE id = ? AND company_id = ?
        ")->execute([substr($messageText, 0, 150), $conversationId, $resolvedCompanyId]);

        // Evaluate if incoming message explicitly matches an automation rule or reset command
        $earlyMatchedRule = WhatsAppAutomationService::matchIncomingMessage($pdo, $resolvedCompanyId, $messageText);
        $isRestartWord = in_array(strtolower(trim($messageText)), ['hi', 'hello', 'hey', 'start', 'menu', 'restart', 'cai'], true);

        if ($earlyMatchedRule && empty($earlyMatchedRule['is_high_intent'])) {
            // Customer explicitly triggered an automation rule — un-silence AI
            $pdo->prepare("UPDATE `conversations` SET `ownership` = 'ai', `status` = 'ai_handling' WHERE id = ? AND company_id = ?")->execute([$conversationId, $resolvedCompanyId]);
            $activeConv['ownership'] = 'ai';
            $activeConv['status'] = 'ai_handling';
        } elseif ($isRestartWord && $activeConv && ($activeConv['ownership'] === 'human' || in_array($activeConv['status'], ['human_requested', 'human_active'], true))) {
            // Customer wants to restart / view menu — switch back to AI
            $pdo->prepare("UPDATE `conversations` SET `ownership` = 'ai', `status` = 'ai_handling' WHERE id = ? AND company_id = ?")->execute([$conversationId, $resolvedCompanyId]);
            $activeConv['ownership'] = 'ai';
            $activeConv['status'] = 'ai_handling';
        }

        // If human is actively handling this conversation and no automation trigger was sent, silence AI and exit gracefully
        if ($activeConv && ($activeConv['ownership'] === 'human' || in_array($activeConv['status'], ['human_requested', 'human_active'], true)) && !$earlyMatchedRule) {
            $pdo->prepare("
                INSERT INTO `messages` 
                (`company_id`, `conversation_id`, `sender_type`, `message_text`, `channel`, `created_at`)
                VALUES (?, ?, 'visitor', ?, 'whatsapp', NOW())
            ")->execute([$resolvedCompanyId, $conversationId, $messageText]);

            $pdo->prepare("
                INSERT INTO `whatsapp_messages`
                (`company_id`, `recipient_phone`, `recipient_name`, `message_type`, `content`, `status`, `metadata_json`, `created_at`)
                VALUES (?, ?, ?, 'customer_message', ?, 'delivered', ?, NOW())
            ")->execute([
                $resolvedCompanyId,
                $senderPhone,
                $customerName,
                $messageText,
                json_encode(['direction' => 'incoming', 'conversation_id' => $conversationId, 'human_handling' => true])
            ]);

            echo json_encode([
                'status' => 'delivered_to_human_agent',
                'conversation_id' => $conversationId,
                'ownership' => 'human'
            ]);
            exit;
        }
    } else {
        $convIns = $pdo->prepare("
            INSERT INTO `conversations`
            (`company_id`, `customer_id`, `channel`, `status`, `ownership`, `last_message_preview`, `last_message_at`, `created_at`)
            VALUES (?, ?, 'whatsapp', 'ai_handling', 'ai', ?, NOW(), NOW())
        ");
        $convIns->execute([$resolvedCompanyId, $customerId, substr($messageText, 0, 150)]);
        $conversationId = (int)$pdo->lastInsertId();
        CustomerJourneyService::updateJourneyState($pdo, (int)$journey['id'], ['conversation_id' => $conversationId]);
    }

    // Cancel pending follow-ups immediately upon receiving any visitor response
    WhatsAppAutomationService::cancelFollowUps($pdo, $resolvedCompanyId, $conversationId);

    // Record incoming message with channel = 'whatsapp'
    $pdo->prepare("
        INSERT INTO `messages` 
        (`company_id`, `conversation_id`, `sender_type`, `message_text`, `channel`, `created_at`)
        VALUES (?, ?, 'visitor', ?, 'whatsapp', NOW())
    ")->execute([$resolvedCompanyId, $conversationId, $messageText]);

    $pdo->prepare("
        INSERT INTO `whatsapp_messages`
        (`company_id`, `recipient_phone`, `recipient_name`, `message_type`, `content`, `status`, `metadata_json`, `created_at`)
        VALUES (?, ?, ?, 'customer_message', ?, 'delivered', ?, NOW())
    ")->execute([
        $resolvedCompanyId,
        $senderPhone,
        $customerName,
        $messageText,
        json_encode(['direction' => 'incoming', 'conversation_id' => $conversationId])
    ]);

    // 4b. Check Remote Support Agent Reply via WhatsApp (Human Bridge)
    // Supports patterns: "REPLY #123 Hello" or "#123 Hello" or if sender is an authorized team member replying to an active conversation
    if (preg_match('/^(?:reply\s*#?|#)([0-9]+)\s+(.+)$/is', $messageText, $agentReplyMatch)) {
        $targetConvId = (int)$agentReplyMatch[1];
        $agentReplyBody = trim($agentReplyMatch[2]);

        $tcStmt = $pdo->prepare("SELECT c.*, cust.name as customer_name FROM `conversations` c LEFT JOIN `customers` cust ON cust.id = c.customer_id WHERE c.id = ? AND c.company_id = ? LIMIT 1");
        $tcStmt->execute([$targetConvId, $resolvedCompanyId]);
        $targetConv = $tcStmt->fetch(PDO::FETCH_ASSOC);

        if ($targetConv) {
            // Find which agent this sender phone belongs to
            $agStmt = $pdo->prepare("SELECT id, name, job_title FROM `users` WHERE `company_id` = ? AND REPLACE(REPLACE(phone, '+', ''), ' ', '') LIKE ? LIMIT 1");
            $agStmt->execute([$resolvedCompanyId, '%' . substr($cleanSender, -10)]);
            $matchedAgent = $agStmt->fetch(PDO::FETCH_ASSOC);
            $agentUserId = $matchedAgent ? (int)$matchedAgent['id'] : null;
            $agentName = $matchedAgent ? $matchedAgent['name'] : 'Support Specialist';

            // Insert message as human
            $pdo->prepare("
                INSERT INTO `messages` (`company_id`, `conversation_id`, `sender_type`, `sender_id`, `message_text`, `channel`, `created_at`)
                VALUES (?, ?, 'human', ?, ?, 'whatsapp', NOW())
            ")->execute([$resolvedCompanyId, $targetConvId, $agentUserId, $agentReplyBody]);

            // Update conversation to human active
            $pdo->prepare("
                UPDATE `conversations`
                SET `ownership` = 'human',
                    `status` = 'human_active',
                    `assigned_user_id` = COALESCE(?, `assigned_user_id`),
                    `last_message_preview` = ?,
                    `last_message_at` = NOW()
                WHERE id = ? AND company_id = ?
            ")->execute([$agentUserId, substr($agentReplyBody, 0, 150), $targetConvId, $resolvedCompanyId]);

            // If conversation originated on web widget, this is immediately visible via widget poll_messages!
            $ackReply = "✅ *Reply delivered to #CONV-{$targetConvId}* ({$targetConv['customer_name']}):\n\"{$agentReplyBody}\"";
            $pdo->prepare("
                INSERT INTO `messages` (`company_id`, `conversation_id`, `sender_type`, `message_text`, `channel`, `created_at`)
                VALUES (?, ?, 'ai', ?, 'whatsapp', NOW())
            ")->execute([$resolvedCompanyId, $conversationId, $ackReply]);

            echo json_encode([
                'status' => 'agent_reply_delivered',
                'target_conversation_id' => $targetConvId,
                'message' => $agentReplyBody
            ]);
            exit;
        }
    }

    // 5. Check Remote Payment Confirmation via WhatsApp
    // e.g. "Confirm PRQ-202610-ABC123" or "Payment Done PRQ-202610-ABC123" or "PRQ-202610-ABC123 paid"
    if (preg_match('/(?:confirm|payment\s*done|paid|approved|verify)\s*(PRQ-[0-9]{6}-[A-Za-z0-9]+)/i', $messageText, $prMatch) ||
        preg_match('/(PRQ-[0-9]{6}-[A-Za-z0-9]+)\s*(?:done|paid|confirm|verified)/i', $messageText, $prMatch)) {
        $targetPrCode = strtoupper(trim($prMatch[1]));
        $prStmt = $pdo->prepare("SELECT * FROM `payment_requests` WHERE `request_code` = ? AND `company_id` = ? LIMIT 1");
        $prStmt->execute([$targetPrCode, $resolvedCompanyId]);
        $prFound = $prStmt->fetch(PDO::FETCH_ASSOC);

        if ($prFound) {
            if ($prFound['status'] === 'completed') {
                $replyText = "ℹ️ Payment Request *{$targetPrCode}* is already marked as *Completed*.";
            } else {
                require_once __DIR__ . '/../payment_requests.php';
                settlePaymentRequest($pdo, $prFound, [
                    'confirmed_by' => "WhatsApp User ({$senderPhone})",
                    'notes'        => "Confirmed via WhatsApp chat reply",
                    'utr'          => 'WA-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
                    'method'       => 'whatsapp_verified'
                ]);
                $replyText = "✅ *Payment Request {$targetPrCode} Confirmed!*\n\n"
                    . "• Customer: {$prFound['customer_name']}\n"
                    . "• Amount: ₹" . number_format($prFound['amount_inr']) . "\n"
                    . "• Status: Completed\n\n"
                    . "Official tax receipt email has been dispatched to `{$prFound['customer_email']}` and CRM lead updated to WON.";
            }

            // Send confirmation WhatsApp message back to sender
            $pdo->prepare("
                INSERT INTO `messages` (`company_id`, `conversation_id`, `sender_type`, `message_text`, `channel`, `created_at`)
                VALUES (?, ?, 'ai', ?, 'whatsapp', NOW())
            ")->execute([$resolvedCompanyId, $conversationId, $replyText]);

            echo json_encode(['status' => 'payment_confirmed', 'request_code' => $targetPrCode]);
            exit;
        }
    }

    // 5b. Check Remote Appointment Management via WhatsApp
    // e.g. "Confirm APT-12" or "Cancel APT-12" or "Complete APT-12" or "Appointment 12 confirmed"
    if (preg_match('/(confirm|cancel|complete|completed|reschedule)\s*(?:apt|appointment)?\s*#?([0-9]+)/i', $messageText, $aptMatch) ||
        preg_match('/(?:apt|appointment)\s*#?([0-9]+)\s*(confirm|cancel|complete|completed|reschedule)/i', $messageText, $aptMatchRev)) {
        
        $actionCmd = !empty($aptMatch[1]) ? strtolower($aptMatch[1]) : strtolower($aptMatchRev[2] ?? '');
        $targetAptId = !empty($aptMatch[2]) ? (int)$aptMatch[2] : (int)($aptMatchRev[1] ?? 0);

        if ($targetAptId > 0) {
            $aStmt = $pdo->prepare("SELECT a.*, c.name as company_name FROM `appointments` a JOIN `companies` c ON c.id = a.company_id WHERE a.id = ? AND a.company_id = ? LIMIT 1");
            $aStmt->execute([$targetAptId, $resolvedCompanyId]);
            $aptFound = $aStmt->fetch(PDO::FETCH_ASSOC);

            if ($aptFound) {
                $newStatus = 'scheduled';
                $replyText = '';
                $custName = $aptFound['customer_name'] ?: 'Prospect';

                if ($actionCmd === 'confirm') {
                    $newStatus = 'scheduled';
                    $pdo->prepare("UPDATE `appointments` SET `status` = 'scheduled', `updated_at` = NOW() WHERE id = ?")->execute([$targetAptId]);
                    
                    try {
                        $pdo->prepare("
                            INSERT INTO `appointment_activities`
                            (`appointment_id`, `company_id`, `action`, `actor_type`, `actor_name`, `channel`, `details`, `created_at`)
                            VALUES (?, ?, 'confirmed_by_whatsapp', 'admin', ?, 'whatsapp', 'Confirmed via WhatsApp remote command', NOW())
                        ")->execute([$targetAptId, $resolvedCompanyId, "WhatsApp User ({$senderPhone})"]);
                    } catch (Exception $actEx) {}

                    $replyText = "✅ *Appointment #APT-{$targetAptId} Confirmed!*\n\n"
                        . "• Client: {$custName}\n"
                        . "• Slot: " . date('l, M j \a\t g:i A', strtotime($aptFound['slot_datetime'])) . "\n"
                        . "• Meeting: {$aptFound['meet_link']}\n"
                        . "• Status: Scheduled & Active";
                } elseif ($actionCmd === 'cancel') {
                    $newStatus = 'cancelled';
                    $pdo->prepare("UPDATE `appointments` SET `status` = 'cancelled', `updated_at` = NOW() WHERE id = ?")->execute([$targetAptId]);

                    try {
                        $pdo->prepare("
                            INSERT INTO `appointment_activities`
                            (`appointment_id`, `company_id`, `action`, `actor_type`, `actor_name`, `channel`, `details`, `created_at`)
                            VALUES (?, ?, 'cancelled_by_whatsapp', 'admin', ?, 'whatsapp', 'Cancelled via WhatsApp remote command', NOW())
                        ")->execute([$targetAptId, $resolvedCompanyId, "WhatsApp User ({$senderPhone})"]);
                    } catch (Exception $actEx) {}

                    $replyText = "❌ *Appointment #APT-{$targetAptId} Cancelled.*\n\nClient: {$custName}\nThe status has been updated in your Scheduled Meetings dashboard.";
                } elseif ($actionCmd === 'complete' || $actionCmd === 'completed') {
                    $newStatus = 'completed';
                    $pdo->prepare("UPDATE `appointments` SET `status` = 'completed', `updated_at` = NOW() WHERE id = ?")->execute([$targetAptId]);

                    try {
                        $pdo->prepare("
                            INSERT INTO `appointment_activities`
                            (`appointment_id`, `company_id`, `action`, `actor_type`, `actor_name`, `channel`, `details`, `created_at`)
                            VALUES (?, ?, 'completed_by_whatsapp', 'admin', ?, 'whatsapp', 'Marked completed via WhatsApp remote command', NOW())
                        ")->execute([$targetAptId, $resolvedCompanyId, "WhatsApp User ({$senderPhone})"]);
                    } catch (Exception $actEx) {}

                    $replyText = "🎉 *Appointment #APT-{$targetAptId} Marked Completed!*\n\nClient: {$custName}\nGreat job wrapping up the consultation!";
                }

                if (!empty($replyText)) {
                    $pdo->prepare("
                        INSERT INTO `messages` (`company_id`, `conversation_id`, `sender_type`, `message_text`, `channel`, `created_at`)
                        VALUES (?, ?, 'ai', ?, 'whatsapp', NOW())
                    ")->execute([$resolvedCompanyId, $conversationId, $replyText]);

                    echo json_encode(['status' => 'appointment_updated', 'appointment_id' => $targetAptId, 'new_status' => $newStatus]);
                    exit;
                }
            }
        }
    }

    // 6. Check Omnichannel Pending Action: SEND_ASSET_EMAIL
    $pendingAsset = null;
    if (!empty($journey['pending_asset_id'])) {
        $paStmt = $pdo->prepare("SELECT * FROM `company_assets` WHERE `id` = ? AND `company_id` = ? LIMIT 1");
        $paStmt->execute([(int)$journey['pending_asset_id'], $resolvedCompanyId]);
        $pendingAsset = $paStmt->fetch(PDO::FETCH_ASSOC);
    }

    $extractedEmail = '';
    if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $messageText, $emMatch)) {
        $extractedEmail = strtolower($emMatch[0]);
    }
    $targetEmail = $extractedEmail ?: ($customer['email'] ?? '');
    $isAffirmative = (bool)preg_match('/\b(haan|ha|yes|sure|okay|ok|bhej\s*do|send\s*kr\s*do|send\s*karo|bhejo|mail\s*kr\s*do|email\s*pe\s*bhej|please\s*send)\b/i', $messageText);

    $aiReply = '';
    if (!empty($journey['pending_action']) && $journey['pending_action'] === 'SEND_ASSET_EMAIL' && $pendingAsset) {
        if (!empty($targetEmail)) {
            AssetHelper::dispatchAssetEmail(
                $pdo,
                $resolvedCompanyId,
                $targetEmail,
                $customerName,
                $pendingAsset,
                $company['name']
            );
            CustomerJourneyService::updateJourneyState($pdo, (int)$journey['id'], [
                'pending_action'   => null,
                'pending_asset_id' => null,
                'offered_assets'   => array_unique(array_merge($journey['offered_assets'] ?? [], [(int)$pendingAsset['id']]))
            ]);
            $aiReply = "Maine **{$pendingAsset['title']}** aapki email (`{$targetEmail}`) par send kar diya hai! ✉️ Kripya apna inbox check karein.\n\nIske alawa aapko aur kis baare mein information chahiye?";
        } elseif ($isAffirmative) {
            $aiReply = "Zaroor! Kripya apna **email address** share karein, taaki main turant **{$pendingAsset['title']}** aapke inbox me send kar sakun. ✉️";
        }
    }

    // 6. Grounded AI Response Generation with Structured Omnichannel Memory
    require_once __DIR__ . '/../scoring_engine.php';
    require_once __DIR__ . '/../events.php';

    // Fetch AI configuration
    $aiCfgStmt = $pdo->prepare("SELECT * FROM `ai_configs` WHERE `company_id` = ? LIMIT 1");
    $aiCfgStmt->execute([$resolvedCompanyId]);
    $aiCfg = $aiCfgStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $asstName = !empty($aiCfg['ai_name']) ? $aiCfg['ai_name'] : 'Cai';
    $customRules = !empty($aiCfg['custom_instructions']) ? $aiCfg['custom_instructions'] : '';

    // Fetch knowledge sources
    $kbStmt = $pdo->prepare("SELECT title, content FROM `knowledge_sources` WHERE `company_id` = ? AND `is_active` = 1 ORDER BY id DESC LIMIT 15");
    $kbStmt->execute([$resolvedCompanyId]);
    $docs = $kbStmt->fetchAll();
    $kbText = "";
    foreach ($docs as $d) {
        $kbText .= "### " . $d['title'] . "\n" . $d['content'] . "\n\n";
    }

    // Fetch active commercial offerings & courses
    $pStmt = $pdo->prepare("SELECT name, category, price_inr, duration, emi_available, emi_starting_at_inr, description FROM `products` WHERE `company_id` = ? AND `is_active` = 1 LIMIT 10");
    $pStmt->execute([$resolvedCompanyId]);
    $waProds = $pStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($waProds)) {
        $kbText .= "### Verified Commercial Offerings & Courses\n";
        foreach ($waProds as $wp) {
            $emiInfo = $wp['emi_available'] ? "EMI starting at ₹" . number_format($wp['emi_starting_at_inr']) . "/mo" : "Full payment";
            $kbText .= "• {$wp['name']} ({$wp['category']}, {$wp['duration']}): Fee ₹" . number_format($wp['price_inr']) . " | {$emiInfo} | {$wp['description']}\n";
        }
        $kbText .= "\n";
    }

    // Load recent messages for conversational context
    $recentMsgs = [];
    if ($conversationId) {
        $recStmt = $pdo->prepare("SELECT sender_type, message_text FROM `messages` WHERE `conversation_id` = ? AND `company_id` = ? ORDER BY id DESC LIMIT 8");
        $recStmt->execute([$conversationId, $resolvedCompanyId]);
        $recRows = array_reverse($recStmt->fetchAll());
        foreach ($recRows as $rr) {
            $recentMsgs[] = ['role' => ($rr['sender_type'] === 'visitor' ? 'user' : 'assistant'), 'content' => $rr['message_text']];
        }
    }

    // Omnichannel Structured Customer Memory
    $memorySection = CustomerJourneyService::buildStructuredMemory($journey, $recentMsgs);

    $welcomeGuidance = $matchedHandoff
        ? "- WELCOME BACK CONTINUITY: The customer transitioned to WhatsApp from the website. Warmly welcome them back by name, recognize their ongoing inquiry, and do NOT restart qualification or ask for details already present in memory above!\n"
        : "- Warmly greet the prospect, answer their question helpfully, and guide them with verified facts.\n";

    // ====================================================================
    // TASK 3: AUTOMATION ENGINE — PHASES 1, 2 & 4
    // ====================================================================
    $autoConfig = WhatsAppAutomationService::getConfig($pdo, $resolvedCompanyId);
    $autoSettings = $autoConfig['settings'] ?? [];
    $matchedRule = null;
    $isHighIntent = false;

    // Phase 1: Greeting & Introduction when visitor first enters from website handoff
    if ($matchedHandoff && !empty($autoSettings['greeting_message'])) {
        $aiReply = str_replace(
            ['{{name}}', '{{company}}', '{{company_name}}'],
            [$customerName, $company['name'], $company['name']],
            $autoSettings['greeting_message']
        );
    }

    // Phase 2: Information Delivery — Keyword triggers and Number-based menus
    if (empty($aiReply)) {
        $matchedRule = !empty($earlyMatchedRule) ? $earlyMatchedRule : WhatsAppAutomationService::matchIncomingMessage($pdo, $resolvedCompanyId, $messageText);
        if ($matchedRule) {
            $aiReply = str_replace(
                ['{{name}}', '{{company}}', '{{company_name}}'],
                [$customerName, $company['name'], $company['name']],
                $matchedRule['response_text']
            );

            // Real attachments & links (Document, Image, External Link)
            if ($matchedRule['attachment_type'] !== 'none' && !empty($matchedRule['attachment_url'])) {
                $attLabel = !empty($matchedRule['attachment_name']) ? $matchedRule['attachment_name'] : 'Resource';
                if ($matchedRule['attachment_type'] === 'document') {
                    $aiReply .= "\n\n📄 [Attached Document: {$attLabel}]({$matchedRule['attachment_url']})";
                } elseif ($matchedRule['attachment_type'] === 'image') {
                    $aiReply .= "\n\n🖼️ [Attached Image: {$attLabel}]({$matchedRule['attachment_url']})";
                } elseif ($matchedRule['attachment_type'] === 'link') {
                    $aiReply .= "\n\n🔗 *{$attLabel}*: {$matchedRule['attachment_url']}";
                }
            }

            // Display available next reply options / numbers
            if (!empty($matchedRule['next_options_json'])) {
                $opts = json_decode($matchedRule['next_options_json'], true);
                if (!empty($opts) && is_array($opts)) {
                    $aiReply .= "\n\n" . implode("\n", $opts);
                }
            }

            if (!empty($matchedRule['is_high_intent'])) {
                $isHighIntent = true;
            }
        }
    }

    // Phase 4: Lead Qualification & Human Handoff Trigger Detection
    if (!$isHighIntent && !empty($autoSettings['high_intent_keywords'])) {
        $hiKeywords = array_map('trim', explode(',', strtolower($autoSettings['high_intent_keywords'])));
        $cleanMsgLower = strtolower($messageText);
        foreach ($hiKeywords as $hik) {
            if (!empty($hik) && (preg_match('/\b' . preg_quote($hik, '/') . '\b/i', $cleanMsgLower) || strpos($cleanMsgLower, $hik) !== false)) {
                $isHighIntent = true;
                break;
            }
        }
    }

    if ($isHighIntent) {
        // 1. Update lead record to HIGH PRIORITY
        $leadIdToUpdate = $leadId ?: ($journey['lead_id'] ?? 0);
        if ($leadIdToUpdate) {
            $pdo->prepare("
                UPDATE `leads` 
                SET `priority` = 'HIGH',
                    `intent_level` = 'high',
                    `human_attention_required` = 1,
                    `human_attention_reason` = ?,
                    `human_attention_at` = NOW()
                WHERE id = ? AND company_id = ?
            ")->execute([substr("High Intent Trigger: " . $messageText, 0, 200), $leadIdToUpdate, $resolvedCompanyId]);
        }

        // 2. Notify assigned team member immediately through WhatsApp
        $notifyUserId = !empty($autoSettings['notify_user_id']) ? (int)$autoSettings['notify_user_id'] : null;
        try {
            WhatsAppBridge::sendNotification(
                $pdo,
                $resolvedCompanyId,
                $conversationId,
                $identity['session_id'] ?? (string)$conversationId,
                $customerName,
                $messageText,
                $notifyUserId
            );
        } catch (Throwable $notifEx) {
            error_log("[Webhook] Team WhatsApp Notification failed: " . $notifEx->getMessage());
        }

        // 3. Pause automated replies for that session
        $pdo->prepare("
            UPDATE `conversations`
            SET `ownership` = 'human',
                `status` = 'human_requested'
            WHERE id = ? AND company_id = ?
        ")->execute([$conversationId, $resolvedCompanyId]);
    }

    if (empty($aiReply)) {
        $aiReply = "Welcome to {$company['name']}! I'm {$asstName}, your AI assistant. How can I assist you today?";

        if (!empty($kbText)) {
            $prompt = "=== IDENTITY & PERSONA ===\n"
                . "You are {$asstName}, the dedicated consultative AI counselor on WhatsApp for {$company['name']}.\n"
                . "Customer Name: {$customerName}\n\n"
                . (!empty($customRules) ? "CUSTOM COMPANY RULES:\n{$customRules}\n\n" : "")
                . $memorySection
                . "=== VERIFIED COMPANY KNOWLEDGE (FACTUAL GROUNDING ONLY) ===\n" . $kbText . "\n\n"
                . "CRITICAL WHATSAPP RULES:\n"
                . $welcomeGuidance
                . "- Answer accurately based ONLY on the verified company knowledge. NEVER invent pricing, discounts, guarantees, or delivery dates.\n"
                . "- If requested info is not in the knowledge base, politely acknowledge the limitation and offer to connect with a team member.\n"
                . "- Match the visitor's language (English, Hindi, or conversational Hinglish) naturally.\n"
                . "- Keep your response natural, conversational, and nicely spaced for WhatsApp (under 120 words).\n";

            // Primary: Google Gemini 3.8 Flash
            $geminiReply = GeminiService::generateResponse($prompt, array_merge(
                $recentMsgs,
                [['role' => 'user', 'content' => $messageText]]
            ), ['temperature' => 0.25, 'max_tokens' => 350]);

            if (!empty($geminiReply)) {
                $aiReply = $geminiReply;
            } elseif (defined('GROQ_API_KEY') && !empty(GROQ_API_KEY)) {
                $waModels = ['qwen/qwen3.8-27b', 'openai/gpt-oss-120b', 'openai/gpt-oss-20b'];
            foreach ($waModels as $wModel) {
                $groqPayload = [
                    'model' => $wModel,
                    'messages' => array_merge(
                        [['role' => 'system', 'content' => $prompt]],
                        $recentMsgs,
                        [['role' => 'user', 'content' => $messageText]]
                    ),
                    'temperature' => 0.25,
                    'max_tokens' => 350
                ];

                $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($groqPayload),
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . GROQ_API_KEY
                    ],
                    CURLOPT_TIMEOUT => 12,
                    CURLOPT_SSL_VERIFYPEER => false
                ]);
                $res = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($code === 200 && !empty($res)) {
                    $json = json_decode($res, true);
                    if (!empty($json['choices'][0]['message']['content'])) {
                        $aiReply = trim($json['choices'][0]['message']['content']);
                        break;
                    }
                }
            }
        }
    }
}


    // Sync Lead Artifact with WhatsApp channel
    $leadIdForArtifact = $leadId ?: (int)($journey['lead_id'] ?? 0);
    if ($leadIdForArtifact) {
        $signals = [
            'whatsapp_continued' => true,
            'preferred_channel'  => 'whatsapp',
            'phone'              => $senderPhone
        ];
        syncLeadArtifact($pdo, $resolvedCompanyId, $leadIdForArtifact, $signals);

        dispatchSystemEvent($pdo, $resolvedCompanyId, 'whatsapp.connected', [
            'lead_id'         => $leadIdForArtifact,
            'customer_id'     => $customerId,
            'conversation_id' => $conversationId,
            'description'     => "Customer transitioned to WhatsApp and connected with Cai",
            'data'            => ['phone' => $senderPhone, 'channel' => 'whatsapp']
        ]);
    }

    // Persist outgoing AI response with channel = 'whatsapp'
    $pdo->prepare("
        INSERT INTO `messages` 
        (`company_id`, `conversation_id`, `sender_type`, `message_text`, `channel`, `created_at`)
        VALUES (?, ?, 'ai', ?, 'whatsapp', NOW())
    ")->execute([$resolvedCompanyId, $conversationId, $aiReply]);

    // Live Outbound Meta Cloud API Dispatch
    $metaSent = false;
    $metaError = null;
    $metaMessageId = null;

    try {
        $waAccStmt = $pdo->prepare("SELECT phone_number_id, whatsapp_access_token FROM `whatsapp_accounts` WHERE `company_id` = ? AND `status` != 'disconnected' LIMIT 1");
        $waAccStmt->execute([$resolvedCompanyId]);
        $waAcc = $waAccStmt->fetch(PDO::FETCH_ASSOC);

        $outPhoneId = $waAcc['phone_number_id'] ?? (getenv('WHATSAPP_PHONE_NUMBER_ID') ?: '');
        $outToken   = $waAcc['whatsapp_access_token'] ?? (getenv('WHATSAPP_ACCESS_TOKEN') ?: '');

        if (!empty($outPhoneId) && !empty($outToken) && !empty($cleanSender)) {
            $endpoint = "https://graph.facebook.com/v20.0/{$outPhoneId}/messages";
            $postPayload = [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => $cleanSender,
                'type'              => 'text',
                'text'              => [
                    'preview_url' => false,
                    'body'        => $aiReply
                ]
            ];

            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($postPayload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $outToken,
                    'Content-Type: application/json'
                ],
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_SSL_VERIFYPEER => true
            ]);
            $res = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $metaSent = ($httpCode >= 200 && $httpCode < 300);
            if ($metaSent && !empty($res)) {
                $metaJson = json_decode($res, true);
                $metaMessageId = $metaJson['messages'][0]['id'] ?? null;
            } elseif (!$metaSent) {
                $metaError = "HTTP {$httpCode}: {$res}";
            }
        }
    } catch (Throwable $dispatchEx) {
        $metaError = $dispatchEx->getMessage();
    }

    $pdo->prepare("
        INSERT INTO `whatsapp_messages`
        (`company_id`, `recipient_phone`, `recipient_name`, `message_type`, `content`, `status`, `metadata_json`, `created_at`)
        VALUES (?, ?, ?, 'customer_message', ?, ?, ?, NOW())
    ")->execute([
        $resolvedCompanyId,
        $senderPhone,
        $customerName,
        $aiReply,
        $metaSent ? 'delivered' : ($metaError ? 'failed' : 'sent'),
        json_encode([
            'direction' => 'outgoing',
            'conversation_id' => $conversationId,
            'meta_sent' => $metaSent,
            'meta_message_id' => $metaMessageId,
            'meta_error' => $metaError
        ])
    ]);

    // Update conversation preview
    $pdo->prepare("
        UPDATE `conversations`
        SET `last_message_preview` = ?, `last_message_at` = NOW()
        WHERE `id` = ?
    ")->execute([substr($aiReply, 0, 150), $conversationId]);

    // Phase 3: Automated Inactivity Follow-Up Scheduling (if AI continues handling)
    if (empty($isHighIntent) && $conversationId) {
        WhatsAppAutomationService::scheduleFollowUp(
            $pdo,
            $resolvedCompanyId,
            $conversationId,
            $identity['session_id'] ?? null,
            $senderPhone
        );
    }

    // Log success in webhook_events
    $pdo->prepare("
        INSERT INTO `webhook_events` (`provider`, `event_name`, `payload_json`, `is_processed`, `created_at`)
        VALUES ('whatsapp', 'message_processed', ?, 1, NOW())
    ")->execute([$rawPayload]);

    echo json_encode([
        'status' => 'ok',
        'company_id' => $resolvedCompanyId,
        'conversation_id' => $conversationId,
        'reply' => $aiReply,
        'dispatched' => $metaSent,
        'meta_message_id' => $metaMessageId
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
