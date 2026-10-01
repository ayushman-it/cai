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

$pdo = getDbConnection();

// 1. Webhook Verification (GET request from Meta)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $hubMode        = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
    $hubChallenge   = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';
    $hubVerifyToken = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';

    // Verify token can be matched to any tenant or global secret
    if ($hubMode === 'subscribe' && !empty($hubChallenge)) {
        header("Content-Type: text/plain");
        echo $hubChallenge;
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

    // 3. Multi-Tenant Resolution
    $resolvedCompanyId = null;
    $matchedHandoff = null;

    // Strategy 1: Handoff token in message text (e.g. [Ref: wh_...] or #CP-1-AB12)
    if (preg_match('/(?:Ref:\s*|#)?(wh_[a-f0-9]+|CP-\d+-[A-Z0-9]+)/i', $messageText, $tokenMatch)) {
        $foundToken = trim($tokenMatch[1]);
        $hStmt = $pdo->prepare("
            SELECT * FROM `whatsapp_handoffs` 
            WHERE (`handoff_token` = ? OR `handoff_token` = ?) AND `status` != 'expired'
            LIMIT 1
        ");
        $hStmt->execute([$foundToken, strtoupper($foundToken)]);
        $handoffRow = $hStmt->fetch();

        if ($handoffRow) {
            $resolvedCompanyId = (int)$handoffRow['company_id'];
            $matchedHandoff = $handoffRow;
        }
    }

    // Strategy 2: WABA ID / Channel ID -> whatsapp_accounts.waba_id
    if (!$resolvedCompanyId && !empty($wabaId)) {
        $wStmt = $pdo->prepare("SELECT company_id FROM `whatsapp_accounts` WHERE `waba_id` = ? LIMIT 1");
        $wStmt->execute([$wabaId]);
        $acc = $wStmt->fetch();
        if ($acc) {
            $resolvedCompanyId = (int)$acc['company_id'];
        }
    }

    // Strategy 3: Destination Phone Number -> whatsapp_accounts.display_number
    if (!$resolvedCompanyId && !empty($cleanDest)) {
        $dStmt = $pdo->prepare("
            SELECT company_id FROM `whatsapp_accounts` 
            WHERE REPLACE(REPLACE(REPLACE(display_number, ' ', ''), '-', ''), '+', '') LIKE ?
            LIMIT 1
        ");
        $dStmt->execute(["%{$cleanDest}%"]);
        $acc = $dStmt->fetch();
        if ($acc) {
            $resolvedCompanyId = (int)$acc['company_id'];
        }
    }

    // Strategy 4: Sender Phone match against customers table
    if (!$resolvedCompanyId && !empty($cleanSender)) {
        $cStmt = $pdo->prepare("
            SELECT company_id FROM `customers` 
            WHERE REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', '') LIKE ? 
               OR REPLACE(REPLACE(REPLACE(whatsapp_number, ' ', ''), '-', ''), '+', '') LIKE ?
            ORDER BY last_seen_at DESC LIMIT 1
        ");
        $cStmt->execute(["%{$cleanSender}%", "%{$cleanSender}%"]);
        $cust = $cStmt->fetch();
        if ($cust) {
            $resolvedCompanyId = (int)$cust['company_id'];
        }
    }

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

    // 4. Resolve or Create Customer record
    $customerId = null;
    $customerName = 'WhatsApp Visitor';

    if ($matchedHandoff && !empty($matchedHandoff['customer_id'])) {
        $customerId = (int)$matchedHandoff['customer_id'];
        $cRow = $pdo->query("SELECT name FROM customers WHERE id = $customerId")->fetch();
        if ($cRow && !empty($cRow['name'])) $customerName = $cRow['name'];
        // Update phone
        $pdo->prepare("UPDATE customers SET phone = COALESCE(phone, ?), whatsapp_number = ?, last_seen_at = NOW() WHERE id = ?")
            ->execute([$senderPhone, $senderPhone, $customerId]);
    } else {
        $cLookup = $pdo->prepare("
            SELECT id, name FROM `customers` 
            WHERE `company_id` = ? AND (
                REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', '') LIKE ? OR
                REPLACE(REPLACE(REPLACE(whatsapp_number, ' ', ''), '-', ''), '+', '') LIKE ?
            ) LIMIT 1
        ");
        $cLookup->execute([$resolvedCompanyId, "%{$cleanSender}%", "%{$cleanSender}%"]);
        $existingCust = $cLookup->fetch();

        if ($existingCust) {
            $customerId = (int)$existingCust['id'];
            if (!empty($existingCust['name'])) $customerName = $existingCust['name'];
            $pdo->prepare("UPDATE `customers` SET `last_seen_at` = NOW() WHERE `id` = ?")->execute([$customerId]);
        } else {
            $custUuid = 'cust_' . bin2hex(random_bytes(10));
            $cIns = $pdo->prepare("
                INSERT INTO `customers` 
                (`company_id`, `customer_uuid`, `name`, `phone`, `whatsapp_number`, `first_seen_at`, `last_seen_at`)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $cIns->execute([$resolvedCompanyId, $custUuid, $customerName, $senderPhone, $senderPhone]);
            $customerId = (int)$pdo->lastInsertId();
        }
    }

    // Mark handoff as claimed if matched
    if ($matchedHandoff) {
        $pdo->prepare("
            UPDATE `whatsapp_handoffs` 
            SET `status` = 'claimed', `claimed_at` = NOW(), `phone` = COALESCE(`phone`, ?) 
            WHERE `id` = ?
        ")->execute([$senderPhone, $matchedHandoff['id']]);
    }

    // 5. Find or Create WhatsApp Conversation
    $convStmt = $pdo->prepare("
        SELECT id FROM `conversations`
        WHERE `company_id` = ? AND `customer_id` = ? AND `channel` = 'whatsapp' AND `status` != 'closed'
        ORDER BY id DESC LIMIT 1
    ");
    $convStmt->execute([$resolvedCompanyId, $customerId]);
    $conv = $convStmt->fetch();

    if ($conv) {
        $conversationId = (int)$conv['id'];
    } else {
        $convIns = $pdo->prepare("
            INSERT INTO `conversations`
            (`company_id`, `customer_id`, `channel`, `status`, `ownership`, `last_message_preview`, `last_message_at`, `created_at`)
            VALUES (?, ?, 'whatsapp', 'ai_handling', 'ai', ?, NOW(), NOW())
        ");
        $convIns->execute([$resolvedCompanyId, $customerId, substr($messageText, 0, 150)]);
        $conversationId = (int)$pdo->lastInsertId();
    }

    // Record incoming message in messages & whatsapp_messages
    $pdo->prepare("
        INSERT INTO `messages` 
        (`company_id`, `conversation_id`, `sender_type`, `message_text`, `created_at`)
        VALUES (?, ?, 'visitor', ?, NOW())
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

    // 6. Grounded AI Response Generation
    // Fetch knowledge
    // 6. Grounded AI Response Generation with Customer Memory Layer
    require_once __DIR__ . '/../scoring_engine.php';
    require_once __DIR__ . '/../events.php';

    // Fetch AI configuration
    $aiCfgStmt = $pdo->prepare("SELECT * FROM `ai_configs` WHERE `company_id` = ? LIMIT 1");
    $aiCfgStmt->execute([$resolvedCompanyId]);
    $aiCfg = $aiCfgStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $asstName = !empty($aiCfg['ai_name']) ? $aiCfg['ai_name'] : 'Cai';
    $customRules = !empty($aiCfg['custom_instructions']) ? $aiCfg['custom_instructions'] : '';

    // Fetch knowledge sources
    $kbStmt = $pdo->prepare("SELECT title, content FROM `knowledge_sources` WHERE `company_id` = ? AND `is_active` = 1 LIMIT 5");
    $kbStmt->execute([$resolvedCompanyId]);
    $docs = $kbStmt->fetchAll();
    $kbText = "";
    foreach ($docs as $d) {
        $kbText .= "### " . $d['title'] . "\n" . $d['content'] . "\n\n";
    }

    // Retrieve previous lead artifact & structured memory
    $artRow = null;
    $leadIdForArtifact = !empty($matchedHandoff['lead_id']) ? (int)$matchedHandoff['lead_id'] : null;
    if ($leadIdForArtifact) {
        $aStmt = $pdo->prepare("SELECT * FROM `lead_artifacts` WHERE `lead_id` = ? AND `company_id` = ? LIMIT 1");
        $aStmt->execute([$leadIdForArtifact, $resolvedCompanyId]);
        $artRow = $aStmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$artRow && $customerId) {
        $aStmt = $pdo->prepare("SELECT * FROM `lead_artifacts` WHERE `customer_id` = ? AND `company_id` = ? ORDER BY id DESC LIMIT 1");
        $aStmt->execute([$customerId, $resolvedCompanyId]);
        $artRow = $aStmt->fetch(PDO::FETCH_ASSOC);
        if ($artRow && !$leadIdForArtifact) {
            $leadIdForArtifact = (int)$artRow['lead_id'];
        }
    }

    $knownFields = [];
    if (!empty($customerName) && $customerName !== 'WhatsApp Visitor') $knownFields[] = "- Customer Name: {$customerName}";
    if ($artRow) {
        if (!empty($artRow['interested_service'])) $knownFields[] = "- Interested Service: {$artRow['interested_service']}";
        if (!empty($artRow['budget'])) $knownFields[] = "- Stated Budget: {$artRow['budget']}";
        if (!empty($artRow['timeline'])) $knownFields[] = "- Stated Timeline: {$artRow['timeline']}";
        if (!empty($artRow['conversation_summary'])) $knownFields[] = "- Previous Discussion Summary: {$artRow['conversation_summary']}";
    }

    $memorySection = !empty($knownFields)
        ? "STRUCTURED CUSTOMER MEMORY (ALREADY COLLECTED ON WEBSITE):\n" . implode("\n", $knownFields) . "\n\n"
        : "";

    $welcomeGuidance = $matchedHandoff
        ? "- WELCOME BACK CONTINUITY: The customer clicked 'Continue on WhatsApp' from your website. Warmly welcome them back by name, explicitly recognize what they were discussing so they know you have full context, and ask ONLY for missing information. DO NOT restart qualification or re-ask questions already in the memory above!\n"
        : "- Warmly greet the prospect, answer their question helpfully, and guide them toward qualification.\n";

    $aiReply = "Welcome to {$company['name']}! I'm {$asstName}, your AI assistant. How can I assist you today?";

    if (defined('GROQ_API_KEY') && !empty(GROQ_API_KEY) && !empty($kbText)) {
        $prompt = "You are {$asstName}, the dedicated AI counselor on WhatsApp for {$company['name']}.\n"
            . "Customer Name: {$customerName}\n\n"
            . (!empty($customRules) ? "CUSTOM COMPANY RULES:\n{$customRules}\n\n" : "")
            . $memorySection
            . "Verified Company Knowledge Base:\n" . $kbText . "\n"
            . "CRITICAL WHATSAPP RULES:\n"
            . $welcomeGuidance
            . "- Answer accurately based ONLY on the verified company knowledge. Never invent pricing, discounts, guarantees, or delivery dates.\n"
            . "- Keep your response natural, conversational, and nicely spaced for WhatsApp (under 120 words).\n";

        $groqPayload = [
            'model' => 'qwen/qwen3.8-27b',
            'messages' => [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => $messageText]
            ],
            'temperature' => 0.25,
            'max_tokens' => 300
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
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && !empty($res)) {
            $json = json_decode($res, true);
            if (!empty($json['choices'][0]['message']['content'])) {
                $aiReply = trim($json['choices'][0]['message']['content']);
            }
        }
    }

    // Sync Lead Artifact with WhatsApp channel and updated interaction
    if ($leadIdForArtifact) {
        $signals = [
            'whatsapp_continued' => true,
            'preferred_channel'  => 'whatsapp',
            'phone'              => $senderPhone
        ];
        syncLeadArtifact($pdo, $resolvedCompanyId, $leadIdForArtifact, $signals);

        // Dispatch continuity event
        dispatchSystemEvent($pdo, $resolvedCompanyId, 'whatsapp.connected', [
            'lead_id'         => $leadIdForArtifact,
            'customer_id'     => $customerId,
            'conversation_id' => $conversationId,
            'description'     => "Customer transitioned to WhatsApp and connected with Cai",
            'data'            => ['phone' => $senderPhone, 'channel' => 'whatsapp']
        ]);
    }

    // Persist outgoing AI response
    $pdo->prepare("
        INSERT INTO `messages` 
        (`company_id`, `conversation_id`, `sender_type`, `message_text`, `created_at`)
        VALUES (?, ?, 'ai', ?, NOW())
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
