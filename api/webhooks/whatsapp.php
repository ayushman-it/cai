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
        $pdo->prepare("
            UPDATE `conversations`
            SET `channel` = 'whatsapp',
                `last_message_preview` = ?,
                `last_message_at` = NOW()
            WHERE id = ? AND company_id = ?
        ")->execute([substr($messageText, 0, 150), $conversationId, $resolvedCompanyId]);
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

    // 5. Check Omnichannel Pending Action: SEND_ASSET_EMAIL
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

        if (defined('GROQ_API_KEY') && !empty(GROQ_API_KEY) && !empty($kbText)) {
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
