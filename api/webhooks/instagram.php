<?php
/**
 * CUBOIDPILOT — INSTAGRAM MESSAGING WEBHOOK (Section 4, 5 & 6)
 * Multi-tenant webhook endpoint for Meta Instagram Graph API.
 * Maps incoming Instagram DMs to the correct tenant, session, customer, lead, and timeline.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/customer_identity_resolver.php';
require_once __DIR__ . '/../../includes/customer_journey_service.php';
require_once __DIR__ . '/../../includes/channel_handoff_service.php';
require_once __DIR__ . '/../../includes/asset_helper.php';

$pdo = getDbConnection();

// 1. Webhook Verification (GET request from Meta)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode        = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
    $token       = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
    $challenge   = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';
    $companyKey  = trim($_GET['company_key'] ?? '');

    if ($mode === 'subscribe' && !empty($challenge)) {
        $isMatched = false;

        // 1. Allow standard universal, user-specified or env verify tokens
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
        if (in_array((string)$token, $universalTokens, true) ||
            (!empty(getenv('INSTAGRAM_VERIFY_TOKEN')) && hash_equals(getenv('INSTAGRAM_VERIFY_TOKEN'), (string)$token)) ||
            (!empty(getenv('META_VERIFY_TOKEN')) && hash_equals(getenv('META_VERIFY_TOKEN'), (string)$token))) {
            $isMatched = true;
        }

        // 2. Check company-specific configuration if company_key is provided
        if (!$isMatched && !empty($companyKey)) {
            $stmt = $pdo->prepare("
                SELECT c.id FROM `companies` c
                JOIN `company_instagram_configs` ig ON ig.company_id = c.id
                WHERE (c.company_key = ? OR c.slug = ?) AND ig.webhook_verify_token = ?
                LIMIT 1
            ");
            $stmt->execute([$companyKey, $companyKey, $token]);
            if ($stmt->fetch()) {
                $isMatched = true;
            }
        }

        // 3. Global check across all tenant configs
        if (!$isMatched && !empty($token)) {
            $stmt = $pdo->prepare("SELECT company_id FROM `company_instagram_configs` WHERE `webhook_verify_token` = ? LIMIT 1");
            $stmt->execute([$token]);
            if ($stmt->fetch()) {
                $isMatched = true;
            }
        }

        if ($isMatched) {
            header("Content-Type: text/plain; charset=UTF-8");
            http_response_code(200);
            echo $challenge;
            exit;
        }
    }

    http_response_code(403);
    echo "Verification token mismatch";
    exit;
}

// 2. Incoming Messages Event (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    if (empty($data) || empty($data['entry'])) {
        http_response_code(200);
        echo "EVENT_RECEIVED";
        exit;
    }

    foreach ($data['entry'] as $entry) {
        $recipientId = $entry['id'] ?? ''; // Page ID or Instagram Account ID
        if (empty($recipientId)) continue;

        // Strict Tenant Resolution by recipient account / page ID
        $cStmt = $pdo->prepare("
            SELECT company_id, instagram_username 
            FROM `company_instagram_configs` 
            WHERE (`page_id` = ? OR `instagram_account_id` = ?) AND `status` = 'connected'
            LIMIT 1
        ");
        $cStmt->execute([$recipientId, $recipientId]);
        $igConfig = $cStmt->fetch(PDO::FETCH_ASSOC);

        if (!$igConfig) {
            // Check if company_key query param exists
            $companyKey = trim($_GET['company_key'] ?? '');
            if (!empty($companyKey)) {
                $compStmt = $pdo->prepare("SELECT id FROM `companies` WHERE `company_key` = ? OR `slug` = ? LIMIT 1");
                $compStmt->execute([$companyKey, $companyKey]);
                $cId = $compStmt->fetchColumn();
                if ($cId) {
                    $igConfig = ['company_id' => (int)$cId, 'instagram_username' => ''];
                }
            }
        }

        if (!$igConfig) continue;

        $companyId = (int)$igConfig['company_id'];

        $messagingEvents = $entry['messaging'] ?? [];
        foreach ($messagingEvents as $event) {
            $senderId = $event['sender']['id'] ?? '';
            $message = $event['message'] ?? null;
            if (!$senderId || !$message || empty($message['text'])) continue;

            $messageText = trim($message['text']);

            // 1. Resolve Identity via Central Omnichannel Resolver
            $identity = CustomerIdentityResolver::resolveFromInstagram($pdo, $companyId, $senderId, $messageText);
            $customerId = (int)$identity['customer_id'];
            $customer = $identity['customer'];
            $matchedHandoff = $identity['handoff'];
            $leadId = !empty($identity['lead_id']) ? (int)$identity['lead_id'] : null;

            if (empty($sessionId)) {
                $sessionId = "sess_ig_" . substr(hash('sha256', "ig_{$senderId}_{$companyId}"), 0, 12);
            }

            // 2. Resolve or Link Unified Conversation
            $conversationId = null;
            if ($matchedHandoff && !empty($matchedHandoff['web_conversation_id'])) {
                $conversationId = (int)$matchedHandoff['web_conversation_id'];
            }
            if (!$conversationId && !empty($identity['conversation_id'])) {
                $conversationId = (int)$identity['conversation_id'];
            }

            $journey = CustomerJourneyService::getOrCreateJourney(
                $pdo,
                $companyId,
                $customerId,
                $leadId,
                'instagram',
                $sessionId,
                $conversationId
            );

            if (!$conversationId && !empty($journey['conversation_id'])) {
                $conversationId = (int)$journey['conversation_id'];
            }

            if (!$conversationId) {
                $convStmt = $pdo->prepare("SELECT id FROM `conversations` WHERE `company_id` = ? AND `customer_id` = ? ORDER BY id DESC LIMIT 1");
                $convStmt->execute([$companyId, $customerId]);
                $conversationId = (int)($convStmt->fetchColumn() ?: 0);
            }

            if (!$conversationId) {
                $insConv = $pdo->prepare("
                    INSERT INTO `conversations`
                    (`company_id`, `customer_id`, `session_id`, `channel`, `status`, `ownership`, `last_message_preview`, `last_message_at`, `created_at`)
                    VALUES (?, ?, ?, 'instagram', 'ai_handling', 'ai', ?, NOW(), NOW())
                ");
                $insConv->execute([$companyId, $customerId, $sessionId, substr($messageText, 0, 150)]);
                $conversationId = (int)$pdo->lastInsertId();
                CustomerJourneyService::updateJourneyState($pdo, (int)$journey['id'], ['conversation_id' => $conversationId]);
            } else {
                $pdo->prepare("
                    UPDATE `conversations`
                    SET `channel` = 'instagram',
                        `last_message_preview` = ?,
                        `last_message_at` = NOW(),
                        `session_id` = COALESCE(`session_id`, ?)
                    WHERE `id` = ? AND `company_id` = ?
                ")->execute([substr($messageText, 0, 150), $sessionId, $conversationId, $companyId]);
            }

            // 3. Record Message with channel = 'instagram'
            $pdo->prepare("
                INSERT INTO `messages`
                (`company_id`, `conversation_id`, `channel`, `session_id`, `sender_type`, `message_text`, `created_at`)
                VALUES (?, ?, 'instagram', ?, 'visitor', ?, NOW())
            ")->execute([$companyId, $conversationId, $sessionId, $messageText]);

            // 4. Update CRM Timeline
            if ($leadId) {
                $pdo->prepare("
                    INSERT INTO `lead_events`
                    (`company_id`, `lead_id`, `customer_id`, `event_type`, `description`, `event_data_json`, `created_at`)
                    VALUES (?, ?, ?, 'MESSAGE_RECEIVED', ?, ?, NOW())
                ")->execute([
                    $companyId,
                    $leadId,
                    $customerId,
                    "Received message on Instagram: " . substr($messageText, 0, 80),
                    json_encode(['channel' => 'instagram', 'session_id' => $sessionId, 'text' => $messageText])
                ]);
            }

            // 5. Check Pending Action (SEND_ASSET_EMAIL)
            $pendingAsset = null;
            if (!empty($journey['pending_asset_id'])) {
                $paStmt = $pdo->prepare("SELECT * FROM `company_assets` WHERE `id` = ? AND `company_id` = ? LIMIT 1");
                $paStmt->execute([(int)$journey['pending_asset_id'], $companyId]);
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
                        $companyId,
                        $targetEmail,
                        $customer['name'] ?? 'there',
                        $pendingAsset,
                        $igConfig['instagram_username'] ?: 'Cai'
                    );
                    CustomerJourneyService::updateJourneyState($pdo, (int)$journey['id'], [
                        'pending_action'   => null,
                        'pending_asset_id' => null,
                        'offered_assets'   => array_unique(array_merge($journey['offered_assets'] ?? [], [(int)$pendingAsset['id']]))
                    ]);
                    $aiReply = "Maine **{$pendingAsset['title']}** aapki email (`{$targetEmail}`) par send kar diya hai! ✉️ Kripya apna inbox check karein.";
                } elseif ($isAffirmative) {
                    $aiReply = "Zaroor! Kripya apna **email address** yahan send karein, taaki main turant **{$pendingAsset['title']}** dispatch kar sakun. ✉️";
                }
            }

            if (!empty($aiReply)) {
                $pdo->prepare("
                    INSERT INTO `messages`
                    (`company_id`, `conversation_id`, `channel`, `session_id`, `sender_type`, `message_text`, `created_at`)
                    VALUES (?, ?, 'instagram', ?, 'ai', ?, NOW())
                ")->execute([$companyId, $conversationId, $sessionId, $aiReply]);
            }
        }
    }

    http_response_code(200);
    echo "EVENT_RECEIVED";
    exit;
}
