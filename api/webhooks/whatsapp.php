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

$pdo = getDbConnection();

// 1. Webhook Verification (GET request from Meta)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $hubMode        = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
    $hubChallenge   = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';
    $hubVerifyToken = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';

    // Verify token matches configured tenant or global secret
    if ($hubMode === 'subscribe' && !empty($hubChallenge)) {
        $chk = $pdo->prepare("SELECT 1 FROM `whatsapp_accounts` WHERE `webhook_verify_token` = ? AND `webhook_verify_token` IS NOT NULL AND `webhook_verify_token` != '' LIMIT 1");
        $chk->execute([$hubVerifyToken]);
        $matched = (bool)$chk->fetch();

        if (!$matched && !empty(getenv('META_VERIFY_TOKEN')) && hash_equals(getenv('META_VERIFY_TOKEN'), (string)$hubVerifyToken)) {
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
$data = json_decode($rawPayload, true) ?? [];

if (empty($data)) {
    echo json_encode(['status' => 'ignored', 'reason' => 'empty_payload']);
    exit;
}

try {
    // Extract incoming sender, text, waba_id, and recipient phone from payload
    // Supports both standard Meta Graph Webhook format and clean Direct format
    $senderPhone = '';
    $messageText = '';
    $wabaId      = '';
    $destPhone   = '';

    // Meta Webhook Format
    if (!empty($data['entry'][0]['changes'][0]['value'])) {
        $changeVal = $data['entry'][0]['changes'][0]['value'];
        $wabaId = $data['entry'][0]['id'] ?? '';
        $destPhone = $changeVal['metadata']['display_phone_number'] ?? '';
        if (!empty($changeVal['messages'][0])) {
            $msgObj = $changeVal['messages'][0];
            $senderPhone = $msgObj['from'] ?? '';
            $messageText = $msgObj['text']['body'] ?? ($msgObj['button']['text'] ?? '');
        }
    } else {
        // Direct / Simulated JSON Format
        $senderPhone = $data['sender_phone'] ?? $data['from'] ?? $data['phone'] ?? '';
        $messageText = $data['message'] ?? $data['text'] ?? $data['body'] ?? '';
        $wabaId      = $data['waba_id'] ?? '';
        $destPhone   = $data['to'] ?? $data['display_number'] ?? '';
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

        // If human is actively handling this conversation, silence AI and exit gracefully
        if ($activeConv && ($activeConv['ownership'] === 'human' || in_array($activeConv['status'], ['human_requested', 'human_active'], true))) {
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

    $pdo->prepare("
        INSERT INTO `whatsapp_messages`
        (`company_id`, `recipient_phone`, `recipient_name`, `message_type`, `content`, `status`, `metadata_json`, `created_at`)
        VALUES (?, ?, ?, 'customer_message', ?, 'sent', ?, NOW())
    ")->execute([
        $resolvedCompanyId,
        $senderPhone,
        $customerName,
        $aiReply,
        json_encode(['direction' => 'outgoing', 'conversation_id' => $conversationId])
    ]);

    // Update conversation preview
    $pdo->prepare("
        UPDATE `conversations`
        SET `last_message_preview` = ?, `last_message_at` = NOW()
        WHERE `id` = ?
    ")->execute([substr($aiReply, 0, 150), $conversationId]);

    // Log success in webhook_events
    $pdo->prepare("
        INSERT INTO `webhook_events` (`provider`, `event_name`, `payload_json`, `is_processed`, `created_at`)
        VALUES ('whatsapp', 'message_processed', ?, 1, NOW())
    ")->execute([$rawPayload]);

    echo json_encode([
        'status' => 'ok',
        'company_id' => $resolvedCompanyId,
        'conversation_id' => $conversationId,
        'reply' => $aiReply
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
