<?php
/**
 * CUBOIDPILOT — TWO-WAY WHATSAPP BRIDGE SERVICE
 * Handles clean WhatsApp notification dispatch, Meta message ID mapping,
 * native reply context resolution, media downloads, and tenant isolation.
 */

require_once __DIR__ . '/../config/db.php';

class WhatsAppBridge {

    /**
     * Send clean WhatsApp notification to assigned team member.
     * Content is strictly:
     * Session ID: 1042
     * Name: Rahul Sharma
     * Message: I want to know about your pricing.
     */
    public static function sendNotification(
        PDO $pdo,
        int $companyId,
        int $conversationId,
        string $sessionId,
        string $customerName,
        string $messageText,
        ?int $targetUserId = null,
        ?array $attachment = null
    ): array {
        try {
            // 1. Fetch connected WhatsApp Account for tenant
            $waStmt = $pdo->prepare("
                SELECT phone_number_id, whatsapp_access_token 
                FROM `whatsapp_accounts` 
                WHERE `company_id` = ? AND `status` = 'connected' 
                LIMIT 1
            ");
            $waStmt->execute([$companyId]);
            $waAcc = $waStmt->fetch(PDO::FETCH_ASSOC);

            if (!$waAcc || empty($waAcc['phone_number_id']) || empty($waAcc['whatsapp_access_token'])) {
                return [
                    'success' => false,
                    'error'   => 'WhatsApp account not connected for this company'
                ];
            }

            // 2. Resolve Target Team Member Phone
            $targetPhone = '';
            $agentName = '';
            if ($targetUserId) {
                $uStmt = $pdo->prepare("SELECT phone, name FROM `users` WHERE `id` = ? AND `company_id` = ? AND `is_active` = 1 LIMIT 1");
                $uStmt->execute([$targetUserId, $companyId]);
                $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                if ($uRow) {
                    $targetPhone = $uRow['phone'] ?? '';
                    $agentName = $uRow['name'] ?? '';
                }
            }

            if (empty($targetPhone)) {
                $uStmt = $pdo->prepare("
                    SELECT phone, name FROM `users` 
                    WHERE `company_id` = ? AND `role` IN ('owner', 'admin', 'manager') AND `phone` IS NOT NULL AND `phone` != '' AND `is_active` = 1 
                    ORDER BY id ASC LIMIT 1
                ");
                $uStmt->execute([$companyId]);
                $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                if ($uRow) {
                    $targetPhone = $uRow['phone'] ?? '';
                    $agentName = $uRow['name'] ?? '';
                }
            }

            if (empty($targetPhone)) {
                return [
                    'success' => false,
                    'error'   => 'No authorized team member phone number found'
                ];
            }

            $cleanRecipient = preg_replace('/[^0-9]/', '', $targetPhone);
            if (strlen($cleanRecipient) === 10) {
                $cleanRecipient = '91' . $cleanRecipient;
            }

            // 3. Format STRICT Notification:
            // Session ID: 1042
            // Name: Rahul Sharma
            // Message: I want to know about your pricing.
            $cleanName = !empty($customerName) && $customerName !== 'Website Visitor' ? trim($customerName) : 'Website Visitor';
            $cleanMsg = trim($messageText);
            if (empty($cleanMsg)) {
                $cleanMsg = 'I want to speak with a sales person / counselor.';
            }

            $waBody = "Session ID: {$sessionId}\n"
                    . "Name: {$cleanName}\n"
                    . "Message: {$cleanMsg}";

            // 4. Build Meta Graph API Payload
            $payload = [
                'messaging_product' => 'whatsapp',
                'to'                => $cleanRecipient,
            ];

            if ($attachment && !empty($attachment['url'])) {
                $fileUrl = $attachment['url'];
                $isImg = !empty($attachment['is_image']) || preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $fileUrl);
                if ($isImg) {
                    $payload['type'] = 'image';
                    $payload['image'] = [
                        'link'    => $fileUrl,
                        'caption' => $waBody
                    ];
                } else {
                    $payload['type'] = 'document';
                    $payload['document'] = [
                        'link'     => $fileUrl,
                        'caption'  => $waBody,
                        'filename' => $attachment['name'] ?? 'attachment.pdf'
                    ];
                }
            } else {
                $payload['type'] = 'text';
                $payload['text'] = ['body' => $waBody];
            }

            $endpoint = "https://graph.facebook.com/v20.0/{$waAcc['phone_number_id']}/messages";
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $waAcc['whatsapp_access_token'],
                    'Content-Type: application/json'
                ],
                CURLOPT_TIMEOUT        => 8
            ]);

            $rawRes = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            $resJson = json_decode($rawRes, true);
            $wamid = $resJson['messages'][0]['id'] ?? null;

            if ($httpCode >= 200 && $httpCode < 300 && !empty($wamid)) {
                // Record in whatsapp_message_mappings for native reply context resolution
                $pdo->prepare("
                    INSERT INTO `whatsapp_message_mappings`
                    (`company_id`, `session_id`, `conversation_id`, `wa_message_id`, `recipient_phone`, `direction`, `created_at`)
                    VALUES (?, ?, ?, ?, ?, 'outbound', NOW())
                    ON DUPLICATE KEY UPDATE `created_at` = NOW()
                ")->execute([$companyId, $sessionId, $conversationId, $wamid, $cleanRecipient]);

                // Record in whatsapp_messages log
                try {
                    $pdo->prepare("
                        INSERT INTO `whatsapp_messages`
                        (`company_id`, `recipient_phone`, `recipient_name`, `message_type`, `content`, `status`, `metadata_json`, `created_at`)
                        VALUES (?, ?, ?, 'human_alert', ?, 'sent', ?, NOW())
                    ")->execute([
                        $companyId,
                        $cleanRecipient,
                        $agentName,
                        $waBody,
                        json_encode(['wamid' => $wamid, 'session_id' => $sessionId, 'conversation_id' => $conversationId])
                    ]);
                } catch (Throwable $e) {}

                return [
                    'success'       => true,
                    'wa_message_id' => $wamid,
                    'recipient'     => $cleanRecipient
                ];
            } else {
                $errorMsg = $resJson['error']['message'] ?? ($curlErr ?: "HTTP {$httpCode}");
                error_log("[WhatsAppBridge sendNotification error] {$errorMsg}");
                return [
                    'success' => false,
                    'error'   => $errorMsg,
                    'raw'     => $rawRes
                ];
            }
        } catch (Throwable $e) {
            error_log("[WhatsAppBridge Exception] " . $e->getMessage());
            return [
                'success' => false,
                'error'   => $e->getMessage()
            ];
        }
    }

    /**
     * Resolve incoming WhatsApp message context using stored mappings or strict agent checks.
     */
    public static function resolveIncomingContext(
        PDO $pdo,
        ?string $contextWaId,
        string $cleanSender,
        string $wabaId = '',
        ?string $messageText = null
    ): array {
        $sender10 = substr($cleanSender, -10);

        // 1. Precise Match via Native Reply context.id
        if (!empty($contextWaId)) {
            $mapStmt = $pdo->prepare("
                SELECT m.*, c.status as conv_status, c.ownership, c.assigned_user_id,
                       cust.name as customer_name, cust.phone as customer_phone,
                       comp.name as company_name
                FROM `whatsapp_message_mappings` m
                JOIN `conversations` c ON c.id = m.conversation_id
                JOIN `companies` comp ON comp.id = m.company_id
                LEFT JOIN `customers` cust ON cust.id = c.customer_id
                WHERE m.wa_message_id = ?
                LIMIT 1
            ");
            $mapStmt->execute([$contextWaId]);
            $mapping = $mapStmt->fetch(PDO::FETCH_ASSOC);

            if ($mapping) {
                $companyId = (int)$mapping['company_id'];

                // Verify sender is authorized for this tenant
                $authStmt = $pdo->prepare("
                    SELECT id, name, role FROM `users`
                    WHERE `company_id` = ? AND REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', '') LIKE ? AND `is_active` = 1
                    LIMIT 1
                ");
                $authStmt->execute([$companyId, '%' . $sender10]);
                $user = $authStmt->fetch(PDO::FETCH_ASSOC);

                if ($user || substr($mapping['recipient_phone'], -10) === $sender10) {
                    return [
                        'status'          => 'matched_reply',
                        'company_id'      => $companyId,
                        'conversation_id' => (int)$mapping['conversation_id'],
                        'session_id'      => $mapping['session_id'],
                        'user_id'         => $user ? (int)$user['id'] : (int)($mapping['assigned_user_id'] ?? 0),
                        'user_name'       => $user['name'] ?? 'Support Specialist',
                        'customer_name'   => $mapping['customer_name'] ?: 'Visitor',
                        'company_name'    => $mapping['company_name'] ?: 'Support Desk'
                    ];
                }
            }
        }

        // 2. Sender Identification
        $uStmt = $pdo->prepare("
            SELECT u.id, u.company_id, u.name, u.role, comp.name as company_name
            FROM `users` u
            JOIN `companies` comp ON comp.id = u.company_id
            WHERE REPLACE(REPLACE(REPLACE(u.phone, '+', ''), ' ', ''), '-', '') LIKE ? AND u.is_active = 1
            ORDER BY u.id ASC
        ");
        $uStmt->execute(['%' . $sender10]);
        $matchingUsers = $uStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($matchingUsers)) {
            return [
                'status' => 'unauthorized_sender',
                'error'  => 'Sender phone is not registered with any company account.'
            ];
        }

        $targetUser = $matchingUsers[0];
        $companyId = (int)$targetUser['company_id'];

        // If unquoted message, first check if it explicitly matches an automation rule or greeting trigger!
        if (!empty($messageText)) {
            require_once __DIR__ . '/whatsapp_automation_service.php';
            $autoMatch = WhatsAppAutomationService::matchIncomingMessage($pdo, $companyId, $messageText);
            $cleanLower = strtolower(trim($messageText));
            $isGreetingOrMenu = in_array($cleanLower, ['hi', 'hello', 'hey', 'start', 'menu', 'restart', 'help', 'info', '1', '2', '3', '1️⃣', '2️⃣', '3️⃣'], true);
            if (!empty($autoMatch) || $isGreetingOrMenu) {
                return [
                    'status'     => 'automation_trigger',
                    'company_id' => $companyId,
                    'rule'       => $autoMatch
                ];
            }
        }

        // 3. Fallback without context.id: Check active handoff sessions specifically assigned to this user in the last 24h
        $activeConvStmt = $pdo->prepare("
            SELECT c.*, cust.name as customer_name, comp.name as company_name
            FROM `conversations` c
            JOIN `companies` comp ON comp.id = c.company_id
            LEFT JOIN `customers` cust ON cust.id = c.customer_id
            WHERE c.company_id = ? 
              AND c.status IN ('human_requested', 'human_active')
              AND c.status != 'closed'
              AND c.assigned_user_id = ?
              AND c.last_message_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY c.last_message_at DESC
            LIMIT 5
        ");
        $activeConvStmt->execute([$companyId, (int)$targetUser['id']]);
        $activeConvs = $activeConvStmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($activeConvs) === 1) {
            $singleConv = $activeConvs[0];
            return [
                'status'          => 'matched_single_active',
                'company_id'      => $companyId,
                'conversation_id' => (int)$singleConv['id'],
                'session_id'      => $singleConv['session_id'] ?: (string)$singleConv['id'],
                'user_id'         => (int)$targetUser['id'],
                'user_name'       => $targetUser['name'],
                'customer_name'   => $singleConv['customer_name'] ?: 'Visitor',
                'company_name'    => $singleConv['company_name'] ?: 'Support Desk'
            ];
        }

        if (count($activeConvs) > 1) {
            return [
                'status'          => 'ambiguous_sessions',
                'company_id'      => $companyId,
                'user_id'         => (int)$targetUser['id'],
                'user_name'       => $targetUser['name'],
                'active_count'    => count($activeConvs),
                'error'           => 'Multiple active sessions found. Native Reply required to map to the correct visitor.'
            ];
        }

        return [
            'status'     => 'no_active_session',
            'company_id' => $companyId,
            'user_id'    => (int)$targetUser['id'],
            'user_name'  => $targetUser['name'],
            'error'      => 'No active human support session found waiting for reply.'
        ];
    }

    /**
     * Download media sent by agent from WhatsApp (images, PDFs, documents)
     */
    public static function downloadWhatsAppMedia(PDO $pdo, int $companyId, string $mediaId): ?array {
        try {
            $waStmt = $pdo->prepare("SELECT whatsapp_access_token FROM `whatsapp_accounts` WHERE `company_id` = ? AND `status` = 'connected' LIMIT 1");
            $waStmt->execute([$companyId]);
            $token = $waStmt->fetchColumn();

            if (empty($token) || empty($mediaId)) {
                return null;
            }

            // 1. Get Media URL from Meta
            $ch = curl_init("https://graph.facebook.com/v20.0/{$mediaId}");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
                CURLOPT_TIMEOUT        => 8
            ]);
            $res = curl_exec($ch);
            curl_close($ch);

            $metaInfo = json_decode($res, true);
            if (empty($metaInfo['url'])) {
                return null;
            }

            $mediaUrl = $metaInfo['url'];
            $mimeType = $metaInfo['mime_type'] ?? 'application/octet-stream';
            $fileSize = (int)($metaInfo['file_size'] ?? 0);

            // Determine extension
            $ext = 'bin';
            if (strpos($mimeType, 'image/jpeg') !== false) $ext = 'jpg';
            elseif (strpos($mimeType, 'image/png') !== false) $ext = 'png';
            elseif (strpos($mimeType, 'image/webp') !== false) $ext = 'webp';
            elseif (strpos($mimeType, 'image/gif') !== false) $ext = 'gif';
            elseif (strpos($mimeType, 'pdf') !== false) $ext = 'pdf';
            elseif (strpos($mimeType, 'msword') !== false) $ext = 'doc';
            elseif (strpos($mimeType, 'wordprocessing') !== false) $ext = 'docx';

            $uploadDir = dirname(__DIR__) . '/assets/uploads/attachments';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }

            $fileName = 'wa_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $targetPath = $uploadDir . '/' . $fileName;

            // 2. Download File Content with Auth Header
            $fp = fopen($targetPath, 'wb');
            $chDl = curl_init($mediaUrl);
            curl_setopt_array($chDl, [
                CURLOPT_FILE           => $fp,
                CURLOPT_HTTPHEADER     => [
                    "Authorization: Bearer {$token}",
                    "User-Agent: Mozilla/5.0"
                ],
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_FOLLOWLOCATION => true
            ]);
            curl_exec($chDl);
            $httpCode = curl_getinfo($chDl, CURLINFO_HTTP_CODE);
            curl_close($chDl);
            fclose($fp);

            if ($httpCode >= 200 && $httpCode < 300 && file_exists($targetPath) && filesize($targetPath) > 0) {
                $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
                $rawHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $isLocal = ($rawHost === 'localhost' || strpos($rawHost, '127.0.0.1') !== false || empty($rawHost));
                $baseUrl = $isLocal ? 'http://localhost/cuboidpilot' : 'https://cai.cuboidsoft.in';
                $webUrl = "{$baseUrl}/assets/uploads/attachments/{$fileName}";
                return [
                    'url'         => $webUrl,
                    'file_name'   => $fileName,
                    'file_size'   => filesize($targetPath),
                    'mime_type'   => $mimeType,
                    'is_image'    => $isImage
                ];
            }
        } catch (Throwable $e) {
            error_log("[WhatsAppBridge downloadWhatsAppMedia error] " . $e->getMessage());
        }
        return null;
    }

    /**
     * Send error or instruction message back to agent on WhatsApp
     */
    public static function sendAgentRejection(
        PDO $pdo,
        int $companyId,
        string $cleanRecipient,
        string $reasonText
    ): void {
        try {
            $waStmt = $pdo->prepare("SELECT phone_number_id, whatsapp_access_token FROM `whatsapp_accounts` WHERE `company_id` = ? AND `status` = 'connected' LIMIT 1");
            $waStmt->execute([$companyId]);
            $waAcc = $waStmt->fetch(PDO::FETCH_ASSOC);

            if (!$waAcc || empty($waAcc['phone_number_id']) || empty($waAcc['whatsapp_access_token'])) {
                return;
            }

            $endpoint = "https://graph.facebook.com/v20.0/{$waAcc['phone_number_id']}/messages";
            $payload = [
                'messaging_product' => 'whatsapp',
                'to'                => $cleanRecipient,
                'type'              => 'text',
                'text'              => ['body' => $reasonText]
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
            curl_exec($ch);
            curl_close($ch);
        } catch (Throwable $e) {}
    }

    /**
     * Centralized execution for Human -> AI Handoff via "STOP" command
     *
     * 1. Validates conversation is in human handling
     * 2. Ends human handoff phase: ownership='ai', status='ai_handling'
     * 3. Sets human_handoffs status to 'completed'
     * 4. NEVER logs "STOP" as a visitor-facing message
     * 5. Injects automated AI resolution check message with interactive chips
     * 6. Optionally sends confirmation to agent on WhatsApp
     */
    public static function executeHandoffStop(
        PDO $pdo,
        int $companyId,
        int $conversationId,
        ?int $agentUserId = null,
        ?string $agentPhone = null
    ): array {
        try {
            // 1. Fetch conversation details & session ID
            $stmt = $pdo->prepare("
                SELECT c.id, c.session_id, c.ownership, c.status, c.channel, cust.name as customer_name
                FROM `conversations` c
                LEFT JOIN `customers` cust ON cust.id = c.customer_id
                WHERE c.id = ? AND c.company_id = ?
                LIMIT 1
            ");
            $stmt->execute([$conversationId, $companyId]);
            $conv = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$conv) {
                return ['success' => false, 'error' => 'Conversation not found'];
            }

            $sessionId = $conv['session_id'] ?: '';
            if (empty($sessionId)) {
                // Look up latest message session_id
                $sStmt = $pdo->prepare("SELECT session_id FROM `messages` WHERE conversation_id = ? AND session_id IS NOT NULL ORDER BY id DESC LIMIT 1");
                $sStmt->execute([$conversationId]);
                $sessionId = $sStmt->fetchColumn() ?: '';
            }

            // 2. Update conversation to AI handling
            $feedbackText = "Thank you for connecting with our team! Was your query resolved? Is there anything else I can help you with?";

            $uConv = $pdo->prepare("
                UPDATE `conversations`
                SET `ownership` = 'ai',
                    `status` = 'ai_handling',
                    `last_message_preview` = ?,
                    `last_message_at` = NOW(),
                    `unread_human` = 0
                WHERE `id` = ? AND `company_id` = ?
            ");
            $uConv->execute([$feedbackText, $conversationId, $companyId]);

            // 3. Complete any active human handoffs
            $pdo->prepare("
                UPDATE `human_handoffs`
                SET `status` = 'completed',
                    `call_completed_at` = NOW()
                WHERE `conversation_id` = ? AND `status` IN ('queued', 'accepted', 'assigned', 'in_progress')
            ")->execute([$conversationId]);

            // 4. Construct Chips metadata
            $chips = [
                [
                    'label' => 'Yes, Resolved',
                    'action_type' => 'SEND_TEXT_RESPONSE',
                    'text' => 'Yes, my query was resolved. Thank you!'
                ],
                [
                    'label' => 'Need More Help',
                    'action_type' => 'SEND_TEXT_RESPONSE',
                    'text' => 'I need more help with something else.'
                ],
                [
                    'label' => 'Talk to Our Team',
                    'action_type' => 'START_HUMAN_HANDOFF'
                ]
            ];
            $metadataJson = json_encode([
                'type' => 'handoff_resolution_feedback',
                'chips' => $chips,
                'source' => 'system_handoff_stop'
            ], JSON_UNESCAPED_UNICODE);

            // 5. Insert AI feedback message into messages table (Never logs "STOP")
            $pdo->prepare("
                INSERT INTO `messages` (`company_id`, `conversation_id`, `session_id`, `sender_type`, `sender_id`, `message_text`, `channel`, `metadata_json`, `created_at`)
                VALUES (?, ?, ?, 'ai', NULL, ?, 'web', ?, NOW())
            ")->execute([
                $companyId,
                $conversationId,
                $sessionId,
                $feedbackText,
                $metadataJson
            ]);
            $feedbackMsgId = (int)$pdo->lastInsertId();

            // 6. Send acknowledgement to team member on WhatsApp if agentPhone provided
            if (!empty($agentPhone)) {
                $cleanRecipient = preg_replace('/[^0-9]/', '', $agentPhone);
                if (strlen($cleanRecipient) === 10) {
                    $cleanRecipient = '91' . $cleanRecipient;
                }
                $ackText = "Conversation #{$conversationId}" . (!empty($sessionId) ? " (Session: {$sessionId})" : "") . " handed back to AI. Follow-up query sent to visitor.";
                self::sendAgentRejection($pdo, $companyId, $cleanRecipient, $ackText);
            }

            return [
                'success' => true,
                'conversation_id' => $conversationId,
                'session_id' => $sessionId,
                'feedback_message_id' => $feedbackMsgId,
                'message' => 'Handoff successfully stopped. Conversation resumed by AI.'
            ];

        } catch (Throwable $e) {
            error_log("[WhatsAppBridge executeHandoffStop error] " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
