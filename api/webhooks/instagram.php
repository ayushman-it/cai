<?php
/**
 * CUBOIDPILOT — INSTAGRAM MESSAGING WEBHOOK (Section 4, 5 & 6)
 * Multi-tenant webhook endpoint for Meta Instagram Graph API.
 * Maps incoming Instagram DMs to the correct tenant, session, customer, lead, and timeline.
 */

require_once __DIR__ . '/../../config/db.php';

$pdo = getDbConnection();

// 1. Webhook Verification (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode        = $_GET['hub_mode'] ?? '';
    $token       = $_GET['hub_verify_token'] ?? '';
    $challenge   = $_GET['hub_challenge'] ?? '';
    $companyKey  = trim($_GET['company_key'] ?? '');

    if ($mode === 'subscribe' && !empty($token)) {
        if (!empty($companyKey)) {
            $stmt = $pdo->prepare("
                SELECT c.id FROM `companies` c
                JOIN `company_instagram_configs` ig ON ig.company_id = c.id
                WHERE (c.company_key = ? OR c.slug = ?) AND ig.webhook_verify_token = ?
                LIMIT 1
            ");
            $stmt->execute([$companyKey, $companyKey, $token]);
            if ($stmt->fetch()) {
                http_response_code(200);
                echo $challenge;
                exit;
            }
        }

        // Global check across all tenant configs
        $stmt = $pdo->prepare("SELECT company_id FROM `company_instagram_configs` WHERE `webhook_verify_token` = ? LIMIT 1");
        $stmt->execute([$token]);
        if ($stmt->fetch()) {
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

            // 1. Resolve or Claim Handoff Token if referenced (e.g. #CP-IG-3-XYZ)
            $sessionId = null;
            $customerId = null;
            $leadId = null;

            if (preg_match('/(?:#|\b)(CP-IG-[0-9]+-[A-F0-9]+)\b/i', $messageText, $tMatch)) {
                $refToken = strtoupper($tMatch[1]);
                $hStmt = $pdo->prepare("
                    SELECT * FROM `instagram_handoffs`
                    WHERE `handoff_token` = ? AND `company_id` = ?
                    LIMIT 1
                ");
                $hStmt->execute([$refToken, $companyId]);
                $handoff = $hStmt->fetch(PDO::FETCH_ASSOC);

                if ($handoff) {
                    $sessionId = $handoff['session_id'];
                    $customerId = (int)$handoff['customer_id'];
                    $leadId = !empty($handoff['lead_id']) ? (int)$handoff['lead_id'] : null;

                    // Mark handoff claimed
                    $pdo->prepare("UPDATE `instagram_handoffs` SET `status` = 'claimed', `claimed_at` = NOW() WHERE `id` = ?")
                        ->execute([(int)$handoff['id']]);
                }
            }

            // 2. Resolve Customer
            if (!$customerId) {
                // Check if customer exists by instagram sender ID
                $custCheck = $pdo->prepare("SELECT id FROM `customers` WHERE `company_id` = ? AND (`customer_uuid` = ? OR `email` = ?) LIMIT 1");
                $custCheck->execute([$companyId, "ig_{$senderId}", "ig_{$senderId}@instagram.user"]);
                $customerId = (int)($custCheck->fetchColumn() ?: 0);

                if (!$customerId) {
                    $insCust = $pdo->prepare("
                        INSERT INTO `customers`
                        (`company_id`, `customer_uuid`, `name`, `email`, `first_seen_at`, `last_seen_at`)
                        VALUES (?, ?, ?, ?, NOW(), NOW())
                    ");
                    $insCust->execute([
                        $companyId,
                        "ig_{$senderId}",
                        "Instagram User " . substr($senderId, -4),
                        "ig_{$senderId}@instagram.user"
                    ]);
                    $customerId = (int)$pdo->lastInsertId();
                }
            }

            if (empty($sessionId)) {
                $sessionId = "sess_ig_" . substr(hash('sha256', "ig_{$senderId}_{$companyId}"), 0, 12);
            }

            // 3. Resolve or Create Conversation with channel = 'instagram'
            $convStmt = $pdo->prepare("
                SELECT id FROM `conversations`
                WHERE `company_id` = ? AND `customer_id` = ? AND `channel` = 'instagram'
                ORDER BY id DESC LIMIT 1
            ");
            $convStmt->execute([$companyId, $customerId]);
            $conversationId = (int)($convStmt->fetchColumn() ?: 0);

            if (!$conversationId) {
                $insConv = $pdo->prepare("
                    INSERT INTO `conversations`
                    (`company_id`, `customer_id`, `session_id`, `channel`, `status`, `ownership`, `last_message_preview`, `last_message_at`, `created_at`)
                    VALUES (?, ?, ?, 'instagram', 'ai_handling', 'ai', ?, NOW(), NOW())
                ");
                $insConv->execute([$companyId, $customerId, $sessionId, substr($messageText, 0, 150)]);
                $conversationId = (int)$pdo->lastInsertId();
            } else {
                $pdo->prepare("
                    UPDATE `conversations`
                    SET `last_message_preview` = ?,
                        `last_message_at` = NOW(),
                        `session_id` = COALESCE(`session_id`, ?)
                    WHERE `id` = ? AND `company_id` = ?
                ")->execute([substr($messageText, 0, 150), $sessionId, $conversationId, $companyId]);
            }

            // 4. Record Message with channel = 'instagram' and session_id
            $pdo->prepare("
                INSERT INTO `messages`
                (`company_id`, `conversation_id`, `channel`, `session_id`, `sender_type`, `message_text`, `created_at`)
                VALUES (?, ?, 'instagram', ?, 'visitor', ?, NOW())
            ")->execute([$companyId, $conversationId, $sessionId, $messageText]);

            // 5. Update CRM Timeline
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
        }
    }

    http_response_code(200);
    echo "EVENT_RECEIVED";
    exit;
}
