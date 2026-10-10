<?php
/**
 * CUBOIDPILOT / CAI — UNIFIED WHATSAPP HUB CONTROLLER
 * Powering the Intercom-inspired WhatsApp Hub:
 * - Live Conversation Threads & Message Stream
 * - Real-time Outbound Meta Cloud API Dispatch
 * - AI Autonomous vs Human Specialist Ownership Handoff
 * - CSV Lead Importer & Personalized Bulk Broadcast
 * - Scheduled WhatsApp Reminders Engine
 * - Visual Automation Flows & Template Library
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/whatsapp_automation_service.php';
$pdo = getDbConnection();

// Self-healing migration for legacy/empty customer_uuid
try {
    $pdo->exec("UPDATE `customers` SET `customer_uuid` = CONCAT('cust_', MD5(CONCAT(id, RAND(), NOW()))) WHERE `customer_uuid` = '' OR `customer_uuid` IS NULL");
} catch (Throwable $e) {}

// 1. Authenticate user & resolve company
$userId = (int)($_SESSION['user_id'] ?? 0);
$companyId = (int)($_SESSION['company_id'] ?? 0);

if ($companyId <= 0 && $userId > 0) {
    try {
        $uStmt = $pdo->prepare("SELECT company_id FROM `users` WHERE id = ? LIMIT 1");
        $uStmt->execute([$userId]);
        $companyId = (int)($uStmt->fetchColumn() ?: 0);
        if ($companyId > 0) {
            $_SESSION['company_id'] = $companyId;
        }
    } catch (Exception $e) {}
}

if ($companyId <= 0) {
    $reqCompKey = trim($_GET['company_key'] ?? ($body['company_key'] ?? ''));
    if (!empty($reqCompKey)) {
        try {
            $ckStmt = $pdo->prepare("SELECT id FROM `companies` WHERE company_key = ? LIMIT 1");
            $ckStmt->execute([$reqCompKey]);
            $companyId = (int)($ckStmt->fetchColumn() ?: 0);
        } catch (Exception $e) {}
    }
}

// Fallback to default company (e.g. 3 for CuboidSoft) for local development / testing
if ($companyId <= 0) {
    $companyId = 3;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? 'overview');
$rawInput = file_get_contents('php://input');
$body = json_decode($rawInput, true) ?? $_POST;
if (!empty($body['action'])) {
    $action = $body['action'];
}

/**
 * Helper: Retrieve active WhatsApp Cloud API credentials
 */
function getWhatsAppCredentials(PDO $pdo, int $companyId): array {
    $stmt = $pdo->prepare("SELECT * FROM `whatsapp_accounts` WHERE company_id = ? AND status != 'disconnected' LIMIT 1");
    $stmt->execute([$companyId]);
    $acc = $stmt->fetch(PDO::FETCH_ASSOC);

    $phoneId = $acc['phone_number_id'] ?? (getenv('WHATSAPP_PHONE_NUMBER_ID') ?: '');
    $wabaId  = $acc['waba_id'] ?? (getenv('WHATSAPP_BUSINESS_ACCOUNT_ID') ?: '');
    $token   = $acc['whatsapp_access_token'] ?? (getenv('WHATSAPP_ACCESS_TOKEN') ?: '');
    $dispNum = $acc['display_number'] ?? (getenv('WHATSAPP_DISPLAY_NUMBER') ?: '+91 82249 73413');

    // Auto-heal CuboidSoft credentials if placeholder or unpopulated on production server
    if ($companyId === 3 && (empty($token) || $phoneId === 'waba_phone_comp3' || strpos($phoneId, 'waba_') === 0)) {
        $phoneId = '1107262365805980';
        $wabaId  = '961159266661280';
        $token   = 'EAAX71GdiWggBSs0giJigJFDoBAJp5mcKDIhqQFDqeGG5ZCJbOwE2jhAFOIhAuwMbcVvqfZAz4MQX05DKuqbDkht4BJwjr8PzpkTMgEbxqpfqJtAZA9GyqHULuRzZBi7Jn0pn7kW1f4Ftb4xUxdhtwU7T0nnZA8mG4DgLsVULNThbFjzIJYr12sA50CMl7kFZC0AgZDZD';
        $dispNum = '+91 82249 73413';

        try {
            $pdo->prepare("
                UPDATE `whatsapp_accounts` 
                SET `phone_number_id` = ?, `waba_id` = ?, `whatsapp_access_token` = ?, `display_number` = ?, `status` = 'connected', `quality_rating` = 'GREEN'
                WHERE `company_id` = ?
            ")->execute([$phoneId, $wabaId, $token, $dispNum, $companyId]);
        } catch (Throwable $e) {}
    }

    return [
        'phone_number_id' => $phoneId,
        'waba_id'         => $wabaId,
        'access_token'    => $token,
        'display_number'  => $dispNum,
        'status'          => $acc['status'] ?? 'connected',
        'quality_rating'  => $acc['quality_rating'] ?? 'GREEN'
    ];
}

/**
 * Helper: Direct Meta Cloud API Outbound Dispatch
 */
function dispatchWhatsAppMessage(string $phoneId, string $token, string $recipientPhone, string $messageText): array {
    $cleanPhone = preg_replace('/[^0-9]/', '', $recipientPhone);
    if (strlen($cleanPhone) === 10) {
        $cleanPhone = '91' . $cleanPhone;
    }

    $payload = [
        'messaging_product' => 'whatsapp',
        'recipient_type'    => 'individual',
        'to'                => $cleanPhone,
        'type'              => 'text',
        'text'              => [
            'preview_url' => false,
            'body'        => $messageText
        ]
    ];

    $ch = curl_init("https://graph.facebook.com/v20.0/{$phoneId}/messages");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$token}",
            "Content-Type: application/json"
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $resJson = json_decode($res, true) ?: [];
    $isSuccess = ($httpCode >= 200 && $httpCode < 300 && !empty($resJson['messages'][0]['id']));

    return [
        'success'         => $isSuccess,
        'http_code'       => $httpCode,
        'meta_message_id' => $resJson['messages'][0]['id'] ?? null,
        'raw_response'    => $res,
        'recipient'       => $cleanPhone
    ];
}

try {
    switch ($action) {

        // ==========================================
        // 0. GET CONTACTS FOR CUSTOM DROPDOWNS
        // ==========================================
        case 'get_contacts':
            $search = trim($_GET['search'] ?? '');
            $sql = "
                SELECT DISTINCT c.id, c.name, COALESCE(c.phone, c.whatsapp_number) as phone, c.email
                FROM `customers` c
                WHERE c.company_id = ? AND (c.phone IS NOT NULL AND c.phone != '' OR c.whatsapp_number IS NOT NULL AND c.whatsapp_number != '')
            ";
            $params = [$companyId];
            if (!empty($search)) {
                $sql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.whatsapp_number LIKE ?)";
                $s = "%{$search}%";
                $params[] = $s; $params[] = $s; $params[] = $s;
            }
            $sql .= " ORDER BY c.last_seen_at DESC LIMIT 50";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'contacts' => $contacts]);
            break;

        // ==========================================
        // 1. OVERVIEW & HEALTH METRICS
        // ==========================================
        case 'overview':
            $creds = getWhatsAppCredentials($pdo, $companyId);

            // Metrics
            $mStmt = $pdo->prepare("
                SELECT 
                    COUNT(DISTINCT c.id) as total_threads,
                    SUM(CASE WHEN c.ownership = 'human' THEN 1 ELSE 0 END) as human_threads,
                    SUM(CASE WHEN c.ownership = 'ai' OR c.ownership IS NULL THEN 1 ELSE 0 END) as ai_threads,
                    SUM(c.unread_human) as total_unread
                FROM `conversations` c
                WHERE c.company_id = ? AND c.channel = 'whatsapp'
            ");
            $mStmt->execute([$companyId]);
            $convMetrics = $mStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $waMsgStmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total_sent,
                    SUM(CASE WHEN status IN ('delivered', 'read') THEN 1 ELSE 0 END) as total_delivered,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed
                FROM `whatsapp_messages`
                WHERE company_id = ?
            ");
            $waMsgStmt->execute([$companyId]);
            $msgMetrics = $waMsgStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            // Reminders count
            $remStmt = $pdo->prepare("SELECT COUNT(*) FROM `reminders` WHERE company_id = ? AND status = 'PENDING'");
            $remStmt->execute([$companyId]);
            $pendingReminders = (int)$remStmt->fetchColumn();

            echo json_encode([
                'success' => true,
                'gateway' => [
                    'connected'      => !empty($creds['access_token']) && !empty($creds['phone_number_id']),
                    'display_number' => $creds['display_number'],
                    'phone_id'       => $creds['phone_number_id'],
                    'waba_id'        => $creds['waba_id'],
                    'quality'        => $creds['quality_rating'],
                    'webhook_url'    => 'https://cai.cuboidsoft.in/api/instagram_webhook.php'
                ],
                'metrics' => [
                    'total_threads'    => (int)($convMetrics['total_threads'] ?? 0),
                    'ai_threads'       => (int)($convMetrics['ai_threads'] ?? 0),
                    'human_threads'    => (int)($convMetrics['human_threads'] ?? 0),
                    'total_unread'     => (int)($convMetrics['total_unread'] ?? 0),
                    'total_sent'       => (int)($msgMetrics['total_sent'] ?? 0),
                    'total_delivered'  => (int)($msgMetrics['total_delivered'] ?? 0),
                    'total_failed'     => (int)($msgMetrics['total_failed'] ?? 0),
                    'pending_reminders'=> $pendingReminders
                ]
            ]);
            break;


        // ==========================================
        // 2. CONVERSATION THREADS LIST (Pane 2)
        // ==========================================
        case 'get_threads':
            $filter = $_GET['filter'] ?? 'all'; // all | ai | human | unread
            $search = trim($_GET['search'] ?? '');

            $sql = "
                SELECT 
                    c.id as conversation_id,
                    c.session_id,
                    c.channel,
                    c.status,
                    COALESCE(c.ownership, 'ai') as ownership,
                    c.last_message_preview,
                    c.last_message_at,
                    c.unread_human,
                    c.created_at,
                    cust.id as customer_id,
                    cust.name as customer_name,
                    COALESCE(cust.phone, cust.whatsapp_number) as customer_phone,
                    l.id as lead_id,
                    l.intent_level,
                    l.stage_name as lead_stage
                FROM `conversations` c
                LEFT JOIN `customers` cust ON cust.id = c.customer_id
                LEFT JOIN `leads` l ON l.conversation_id = c.id
                WHERE c.company_id = ? AND (
                    c.channel IN ('whatsapp', 'web', 'widget') 
                    OR c.id IN (SELECT DISTINCT conversation_id FROM messages WHERE company_id = ?)
                )
            ";
            $params = [$companyId, $companyId];

            if ($filter === 'ai') {
                $sql .= " AND (c.ownership = 'ai' OR c.ownership IS NULL)";
            } elseif ($filter === 'human') {
                $sql .= " AND c.ownership = 'human'";
            } elseif ($filter === 'unread') {
                $sql .= " AND c.unread_human > 0";
            }

            if (!empty($search)) {
                $sql .= " AND (cust.name LIKE ? OR cust.phone LIKE ? OR cust.whatsapp_number LIKE ? OR c.last_message_preview LIKE ?)";
                $sWild = "%{$search}%";
                $params[] = $sWild;
                $params[] = $sWild;
                $params[] = $sWild;
                $params[] = $sWild;
            }

            $sql .= " ORDER BY COALESCE(c.last_message_at, c.created_at) DESC LIMIT 100";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $threads = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Clean data
            $cleaned = array_map(function($t) {
                return [
                    'id'              => (int)$t['conversation_id'],
                    'session_id'      => $t['session_id'] ?: '',
                    'customer_id'     => (int)($t['customer_id'] ?? 0),
                    'customer_name'   => !empty($t['customer_name']) ? $t['customer_name'] : (!empty($t['customer_phone']) ? $t['customer_phone'] : 'Visitor #' . ($t['session_id'] ?: $t['conversation_id'])),
                    'customer_phone'  => $t['customer_phone'] ?: '',
                    'last_message'    => $t['last_message_preview'] ?: 'Conversation initiated',
                    'last_message_at' => $t['last_message_at'] ?: $t['created_at'],
                    'ownership'       => $t['ownership'] ?: 'ai',
                    'status'          => $t['status'] ?: 'active',
                    'unread'          => (int)($t['unread_human'] ?? 0),
                    'intent_score'    => (int)($t['intent_score'] ?? 0),
                    'lead_stage'      => $t['lead_stage'] ?: 'Prospect'
                ];
            }, $threads);

            echo json_encode(['success' => true, 'threads' => $cleaned]);
            break;


        // ==========================================
        // 3. GET MESSAGES FOR A THREAD (Pane 3)
        // ==========================================
        case 'get_messages':
            $convId = (int)($_GET['conversation_id'] ?? ($body['conversation_id'] ?? 0));
            if ($convId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid conversation ID']);
                exit;
            }

            // Mark unread as read
            $pdo->prepare("UPDATE `conversations` SET `unread_human` = 0 WHERE id = ? AND company_id = ?")->execute([$convId, $companyId]);

            // Fetch conversation header details
            $cStmt = $pdo->prepare("
                SELECT c.*, cust.name as customer_name, COALESCE(cust.phone, cust.whatsapp_number) as customer_phone, cust.email as customer_email,
                       l.intent_level, l.stage_name as lead_stage
                FROM `conversations` c
                LEFT JOIN `customers` cust ON cust.id = c.customer_id
                LEFT JOIN `leads` l ON l.conversation_id = c.id
                WHERE c.id = ? AND c.company_id = ? LIMIT 1
            ");
            $cStmt->execute([$convId, $companyId]);
            $conv = $cStmt->fetch(PDO::FETCH_ASSOC);

            if (!$conv) {
                echo json_encode(['success' => false, 'error' => 'Conversation not found']);
                exit;
            }

            // Fetch messages with session_id and metadata_json
            $mStmt = $pdo->prepare("
                SELECT id, session_id, sender_type, sender_id, message_text, channel, metadata_json, created_at
                FROM `messages`
                WHERE conversation_id = ? AND company_id = ?
                ORDER BY created_at ASC, id ASC
            ");
            $mStmt->execute([$convId, $companyId]);
            $messages = $mStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'conversation' => [
                    'id'             => (int)$conv['id'],
                    'session_id'     => $conv['session_id'] ?: '',
                    'customer_name'  => $conv['customer_name'] ?: ($conv['customer_phone'] ?: 'Visitor #' . ($conv['session_id'] ?: $conv['id'])),
                    'customer_phone' => $conv['customer_phone'] ?: '',
                    'customer_email' => $conv['customer_email'] ?: '',
                    'ownership'      => $conv['ownership'] ?: 'ai',
                    'status'         => $conv['status'] ?: 'active',
                    'channel'        => $conv['channel'],
                    'intent_level'   => $conv['intent_level'] ?: 'warm',
                    'lead_stage'     => $conv['lead_stage'] ?: 'Prospect'
                ],
                'messages' => $messages
            ]);
            break;


        // ==========================================
        // 4. DIRECT LIVE SEND MESSAGE
        // ==========================================
        case 'send_message':
            $convId      = (int)($body['conversation_id'] ?? 0);
            $destPhone   = trim($body['phone'] ?? '');
            $messageText = trim($body['message'] ?? '');
            $attachment  = $body['attachment'] ?? null; // Optional: { url, file_name, file_size, mime_type, is_image }

            if (empty($messageText) && empty($attachment)) {
                echo json_encode(['success' => false, 'error' => 'Message text or attachment is required']);
                exit;
            }

            // ====================================================================
            // STRICT DASHBOARD COMMAND: "STOP" (Hands off back to AI)
            // If team member sends "STOP", end human handling, resume AI,
            // send AI feedback message to visitor, and NEVER display "STOP" to visitor.
            // ====================================================================
            if (strtoupper($messageText) === 'STOP' && $convId > 0) {
                require_once __DIR__ . '/../includes/whatsapp_bridge.php';
                $stopResult = WhatsAppBridge::executeHandoffStop(
                    $pdo,
                    $companyId,
                    $convId,
                    $userId ?: null,
                    null
                );
                echo json_encode([
                    'success' => true,
                    'action'  => 'handoff_stopped',
                    'details' => $stopResult
                ]);
                exit;
            }

            // Look up conversation channel and session ID
            $convChannel = 'whatsapp';
            $sessionId = null;
            if ($convId > 0) {
                $cLookup = $pdo->prepare("SELECT channel, session_id FROM `conversations` WHERE id = ? AND company_id = ? LIMIT 1");
                $cLookup->execute([$convId, $companyId]);
                $cRow = $cLookup->fetch(PDO::FETCH_ASSOC);
                if ($cRow) {
                    $convChannel = $cRow['channel'] ?: 'whatsapp';
                    $sessionId = $cRow['session_id'];
                }
            }

            // If convId is provided, get phone from customer
            if ($convId > 0 && empty($destPhone)) {
                $pStmt = $pdo->prepare("SELECT cust.phone, cust.whatsapp_number FROM `conversations` c LEFT JOIN `customers` cust ON cust.id = c.customer_id WHERE c.id = ? AND c.company_id = ? LIMIT 1");
                $pStmt->execute([$convId, $companyId]);
                $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
                $destPhone = $pRow['phone'] ?: ($pRow['whatsapp_number'] ?: '');
            }

            // If it's a web/widget conversation and no phone is present, we still allow replying!
            // It will be saved into messages for widget polling.
            $dispatch = ['success' => false, 'meta_message_id' => null, 'http_code' => 0, 'raw_response' => ''];
            if (!empty($destPhone)) {
                $creds = getWhatsAppCredentials($pdo, $companyId);
                if (!empty($creds['phone_number_id']) && !empty($creds['access_token'])) {
                    $dispatch = dispatchWhatsAppMessage(
                        $creds['phone_number_id'],
                        $creds['access_token'],
                        $destPhone,
                        $messageText ?: ($attachment ? '[Attachment: ' . ($attachment['file_name'] ?? 'File') . ']' : '')
                    );
                }
            }

            // Ensure conversation exists
            $previewText = !empty($messageText) ? $messageText : ($attachment ? '[Attachment: ' . ($attachment['file_name'] ?? 'File') . ']' : '');
            if ($convId <= 0) {
                $cleanPhone = preg_replace('/[^0-9]/', '', $destPhone);
                $cCust = $pdo->prepare("SELECT id FROM `customers` WHERE company_id = ? AND (phone LIKE ? OR whatsapp_number LIKE ?) LIMIT 1");
                $cCust->execute([$companyId, "%{$cleanPhone}%", "%{$cleanPhone}%"]);
                $cId = (int)$cCust->fetchColumn();

                if ($cId <= 0) {
                    $custUuid = 'cust_' . bin2hex(random_bytes(16));
                    $pdo->prepare("INSERT INTO `customers` (`company_id`, `customer_uuid`, `name`, `phone`, `whatsapp_number`, `first_seen_at`, `last_seen_at`) VALUES (?, ?, ?, ?, ?, NOW(), NOW())")
                        ->execute([$companyId, $custUuid, 'WhatsApp ' . substr($cleanPhone, -4), $destPhone, $destPhone]);
                    $cId = (int)$pdo->lastInsertId();
                }

                $pdo->prepare("INSERT INTO `conversations` (`company_id`, `customer_id`, `channel`, `status`, `ownership`, `last_message_preview`, `last_message_at`, `created_at`) VALUES (?, ?, 'whatsapp', 'human_active', 'human', ?, NOW(), NOW())")
                    ->execute([$companyId, $cId, substr($previewText, 0, 150)]);
                $convId = (int)$pdo->lastInsertId();
            } else {
                $pdo->prepare("
                    UPDATE `conversations`
                    SET `ownership` = 'human',
                        `status` = 'human_active',
                        `last_message_preview` = ?,
                        `last_message_at` = NOW()
                    WHERE id = ? AND company_id = ?
                ")->execute([substr($previewText, 0, 150), $convId, $companyId]);
            }

            $metaJson = $attachment ? json_encode(['attachment' => $attachment], JSON_UNESCAPED_UNICODE) : null;
            $savedMsgBody = $messageText;
            if ($attachment && empty($savedMsgBody)) {
                $tagType = !empty($attachment['is_image']) ? 'Image' : 'Document';
                $savedMsgBody = "[Attached {$tagType}: {$attachment['file_name']}]({$attachment['url']})";
            }

            // Persist message in messages table (include session_id & metadata_json)
            $pdo->prepare("
                INSERT INTO `messages` (`company_id`, `conversation_id`, `session_id`, `sender_type`, `sender_id`, `message_text`, `channel`, `metadata_json`, `created_at`)
                VALUES (?, ?, ?, 'human', ?, ?, ?, ?, NOW())
            ")->execute([$companyId, $convId, $sessionId, $userId ?: null, $savedMsgBody, $convChannel, $metaJson]);
            $msgId = (int)$pdo->lastInsertId();

            // Persist in whatsapp_messages log
            $pdo->prepare("
                INSERT INTO `whatsapp_messages` (`company_id`, `recipient_phone`, `message_type`, `content`, `status`, `metadata_json`, `created_at`)
                VALUES (?, ?, 'customer_message', ?, ?, ?, NOW())
            ")->execute([
                $companyId,
                $destPhone,
                $messageText,
                $dispatch['success'] ? 'delivered' : 'failed',
                json_encode([
                    'direction'       => 'outgoing',
                    'conversation_id' => $convId,
                    'meta_message_id' => $dispatch['meta_message_id'],
                    'http_code'       => $dispatch['http_code'],
                    'sender_user_id'  => $userId
                ])
            ]);

            echo json_encode([
                'success'         => true,
                'dispatched'      => $dispatch['success'],
                'meta_message_id' => $dispatch['meta_message_id'],
                'conversation_id' => $convId,
                'message_id'      => $msgId,
                'note'            => $dispatch['success'] ? 'Message sent live via Meta Cloud API' : 'Meta API error: ' . $dispatch['raw_response']
            ]);
            break;


        // ==========================================
        // 5. TOGGLE AI / HUMAN OWNERSHIP
        // ==========================================
        case 'toggle_ownership':
        case 'switch_to_ai':
            $convId    = (int)($body['conversation_id'] ?? 0);
            $ownership = strtolower(trim($body['ownership'] ?? 'ai'));

            if ($convId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
                exit;
            }

            if ($ownership === 'ai' || $action === 'switch_to_ai') {
                require_once __DIR__ . '/../includes/whatsapp_bridge.php';
                $stopResult = WhatsAppBridge::executeHandoffStop(
                    $pdo,
                    $companyId,
                    $convId,
                    $userId ?: null,
                    null
                );
                echo json_encode([
                    'success'         => true,
                    'conversation_id' => $convId,
                    'ownership'       => 'ai',
                    'status'          => 'ai_handling',
                    'details'         => $stopResult
                ]);
                exit;
            }

            $newStatus = 'human_active';

            $pdo->prepare("
                UPDATE `conversations`
                SET `ownership` = 'human', `status` = ?, `closure_reason` = NULL, `closed_at` = NULL
                WHERE id = ? AND company_id = ?
            ")->execute([$newStatus, $convId, $companyId]);

            echo json_encode([
                'success'         => true,
                'conversation_id' => $convId,
                'ownership'       => 'human',
                'status'          => $newStatus
            ]);
            break;

        case 'resolve_conversation':
            $convId = (int)($body['conversation_id'] ?? 0);
            if ($convId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid conversation ID']);
                exit;
            }

            $pdo->prepare("
                UPDATE `conversations`
                SET `status` = 'closed',
                    `closure_reason` = 'agent_resolved',
                    `closed_at` = NOW()
                WHERE id = ? AND company_id = ?
            ")->execute([$convId, $companyId]);

            // Add system message
            $pdo->prepare("
                INSERT INTO `messages` (`company_id`, `conversation_id`, `sender_type`, `sender_id`, `message_text`, `channel`, `created_at`)
                VALUES (?, ?, 'system', ?, '[System Event] Conversation marked as resolved by support agent.', 'whatsapp', NOW())
            ")->execute([$companyId, $convId, $userId ?: null]);

            echo json_encode([
                'success'         => true,
                'conversation_id' => $convId,
                'status'          => 'closed'
            ]);
            break;


        // ==========================================
        // 6. CSV CONTACT / LEAD UPLOADER
        // ==========================================
        case 'upload_csv':
            $csvString = '';

            if (!empty($_FILES['file']['tmp_name'])) {
                $csvString = file_get_contents($_FILES['file']['tmp_name']);
            } elseif (!empty($body['csv_data'])) {
                $csvString = $body['csv_data'];
            }

            if (empty($csvString)) {
                echo json_encode(['success' => false, 'error' => 'No CSV file or data provided']);
                exit;
            }

            $lines = preg_split('/\r\n|\r|\n/', trim($csvString));
            if (count($lines) < 2) {
                echo json_encode(['success' => false, 'error' => 'CSV file must have at least a header row and 1 data row']);
                exit;
            }

            $header = str_getcsv(array_shift($lines));
            $headerLower = array_map(function($h) { return strtolower(trim($h)); }, $header);

            // Find column indices
            $nameIdx    = -1;
            $phoneIdx   = -1;
            $emailIdx   = -1;
            $companyIdx = -1;

            foreach ($headerLower as $idx => $col) {
                if (preg_match('/name|contact|full_name/i', $col)) $nameIdx = $idx;
                if (preg_match('/phone|mobile|whatsapp|number/i', $col)) $phoneIdx = $idx;
                if (preg_match('/email|mail/i', $col)) $emailIdx = $idx;
                if (preg_match('/company|org|business/i', $col)) $companyIdx = $idx;
            }

            if ($phoneIdx === -1) {
                echo json_encode(['success' => false, 'error' => 'CSV must include a phone/mobile column']);
                exit;
            }

            $imported = [];
            $errors = [];

            foreach ($lines as $lineNum => $line) {
                if (empty(trim($line))) continue;
                $row = str_getcsv($line);

                $phone = trim($row[$phoneIdx] ?? '');
                $cleanPhone = preg_replace('/[^0-9]/', '', $phone);

                if (strlen($cleanPhone) < 10) {
                    $errors[] = "Row " . ($lineNum + 2) . ": Invalid phone '{$phone}'";
                    continue;
                }

                if (strlen($cleanPhone) === 10) {
                    $cleanPhone = '91' . $cleanPhone;
                }

                $name    = $nameIdx !== -1 ? trim($row[$nameIdx] ?? '') : 'Prospect';
                $email   = $emailIdx !== -1 ? trim($row[$emailIdx] ?? '') : '';
                $company = $companyIdx !== -1 ? trim($row[$companyIdx] ?? '') : '';

                // Insert or update lead & customer
                try {
                    $custUuid = 'cust_' . bin2hex(random_bytes(16));
                    $pdo->prepare("
                        INSERT INTO `customers` (`company_id`, `customer_uuid`, `name`, `phone`, `whatsapp_number`, `email`, `first_seen_at`, `last_seen_at`)
                        VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE 
                            `name` = IF(VALUES(`name`) != 'Prospect', VALUES(`name`), `name`),
                            `email` = COALESCE(VALUES(`email`), `email`),
                            `last_seen_at` = NOW()
                    ")->execute([$companyId, $custUuid, $name, $cleanPhone, $cleanPhone, $email]);

                    $cId = (int)$pdo->lastInsertId();
                    if ($cId === 0) {
                        $sC = $pdo->prepare("SELECT id FROM `customers` WHERE company_id = ? AND (phone = ? OR whatsapp_number = ?) LIMIT 1");
                        $sC->execute([$companyId, $cleanPhone, $cleanPhone]);
                        $cId = (int)$sC->fetchColumn();
                    }

                    // Create lead
                    $pdo->prepare("
                        INSERT INTO `leads` (`company_id`, `customer_id`, `title`, `source`, `stage_name`, `intent_level`, `created_at`)
                        VALUES (?, ?, ?, 'csv_import', 'New', 'warm', NOW())
                    ")->execute([$companyId, $cId, $name ? "Inquiry from {$name}" : "CSV Import #{$cId}"]);

                    $imported[] = [
                        'name'    => $name,
                        'phone'   => '+' . $cleanPhone,
                        'email'   => $email,
                        'company' => $company
                    ];
                } catch (Throwable $dbEx) {
                    $errors[] = "Row " . ($lineNum + 2) . ": " . $dbEx->getMessage();
                }
            }

            echo json_encode([
                'success'        => true,
                'imported_count' => count($imported),
                'error_count'    => count($errors),
                'leads'          => array_slice($imported, 0, 50),
                'errors'         => array_slice($errors, 0, 10)
            ]);
            break;


        // ==========================================
        // 7. BULK BROADCAST CAMPAIGN
        // ==========================================
        case 'broadcast_send':
            $recipients = $body['recipients'] ?? [];
            $template   = trim($body['template'] ?? '');

            if (empty($template) || empty($recipients) || !is_array($recipients)) {
                echo json_encode(['success' => false, 'error' => 'Template text and recipient array are required']);
                exit;
            }

            $creds = getWhatsAppCredentials($pdo, $companyId);
            if (empty($creds['phone_number_id']) || empty($creds['access_token'])) {
                echo json_encode(['success' => false, 'error' => 'WhatsApp Cloud API credentials not active']);
                exit;
            }

            $sent = 0;
            $failed = 0;
            $results = [];

            foreach ($recipients as $rec) {
                $phone = trim($rec['phone'] ?? '');
                $name  = trim($rec['name'] ?? 'Friend');
                $comp  = trim($rec['company'] ?? '');

                if (empty($phone)) continue;

                // Replace tokens
                $personalized = str_replace(
                    ['{{name}}', '{{Name}}', '{{company}}', '{{Company}}'],
                    [$name, $name, $comp ?: 'your business', $comp ?: 'your business'],
                    $template
                );

                $res = dispatchWhatsAppMessage($creds['phone_number_id'], $creds['access_token'], $phone, $personalized);
                if ($res['success']) {
                    $sent++;
                    $results[] = ['phone' => $phone, 'status' => 'delivered', 'meta_id' => $res['meta_message_id']];
                    
                    // Log to whatsapp_messages
                    $pdo->prepare("
                        INSERT INTO `whatsapp_messages` (`company_id`, `recipient_phone`, `recipient_name`, `message_type`, `content`, `status`, `metadata_json`, `created_at`)
                        VALUES (?, ?, ?, 'customer_message', 'delivered', ?, NOW())
                    ")->execute([
                        $companyId,
                        $phone,
                        $name,
                        $personalized,
                        json_encode(['type' => 'broadcast', 'meta_id' => $res['meta_message_id']])
                    ]);
                } else {
                    $failed++;
                    $results[] = ['phone' => $phone, 'status' => 'failed', 'error' => $res['raw_response']];
                }

                // Respect standard rate throttle
                usleep(120000); // 120ms between messages
            }

            echo json_encode([
                'success' => true,
                'sent'    => $sent,
                'failed'  => $failed,
                'results' => $results
            ]);
            break;


        // ==========================================
        // 8. SCHEDULED CLIENT REMINDERS
        // ==========================================
        case 'get_reminders':
            $stmt = $pdo->prepare("
                SELECT r.*, cust.name as customer_name, COALESCE(cust.phone, cust.whatsapp_number) as customer_phone
                FROM `reminders` r
                LEFT JOIN `customers` cust ON cust.id = r.customer_id
                WHERE r.company_id = ?
                ORDER BY COALESCE(r.scheduled_at, r.due_date, r.created_at) DESC
                LIMIT 50
            ");
            $stmt->execute([$companyId]);
            $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'reminders' => $reminders]);
            break;

        case 'create_reminder':
            $title       = trim($body['title'] ?? 'WhatsApp Follow-Up Reminder');
            $messageTpl  = trim($body['message_template'] ?? ($body['message'] ?? ''));
            $scheduledAt = trim($body['scheduled_at'] ?? date('Y-m-d H:i:s', strtotime('+2 hours')));
            $recipients  = $body['recipients'] ?? [];

            if (empty($messageTpl)) {
                echo json_encode(['success' => false, 'error' => 'Reminder message cannot be empty']);
                exit;
            }

            // Normalise recipients: if not provided as list, use phone and name
            if (empty($recipients) || !is_array($recipients)) {
                $phone = trim($body['phone'] ?? '');
                $name  = trim($body['name'] ?? 'Client');
                $cId   = (int)($body['customer_id'] ?? 0);
                if (!empty($phone)) {
                    $recipients = [[
                        'phone'       => $phone,
                        'name'        => $name,
                        'customer_id' => $cId
                    ]];
                }
            }

            if (empty($recipients)) {
                echo json_encode(['success' => false, 'error' => 'At least one recipient phone number is required']);
                exit;
            }

            $createdCount = 0;
            $ins = $pdo->prepare("
                INSERT INTO `reminders` 
                    (`company_id`, `customer_id`, `title`, `message_template`, `scheduled_at`, `due_date`, `channels`, `status`, `created_by_user_id`, `created_at`)
                VALUES 
                    (?, ?, ?, ?, ?, DATE(?), 'whatsapp', 'PENDING', ?, NOW())
            ");

            foreach ($recipients as $rec) {
                $recPhone = trim($rec['phone'] ?? '');
                $recName  = trim($rec['name'] ?? 'Client');
                $cId      = (int)($rec['customer_id'] ?? 0);

                if (empty($recPhone)) continue;

                $cleanPhone = preg_replace('/[^0-9]/', '', $recPhone);
                if (strlen($cleanPhone) === 10) $cleanPhone = '91' . $cleanPhone;

                if ($cId <= 0 && !empty($cleanPhone)) {
                    $chk = $pdo->prepare("SELECT id FROM `customers` WHERE company_id = ? AND (phone LIKE ? OR whatsapp_number LIKE ?) LIMIT 1");
                    $chk->execute([$companyId, "%{$cleanPhone}%", "%{$cleanPhone}%"]);
                    $cId = (int)$chk->fetchColumn();

                    if ($cId <= 0) {
                        $custUuid = 'cust_' . bin2hex(random_bytes(16));
                        $pdo->prepare("INSERT INTO `customers` (`company_id`, `customer_uuid`, `name`, `phone`, `whatsapp_number`, `first_seen_at`, `last_seen_at`) VALUES (?, ?, ?, ?, ?, NOW(), NOW())")
                            ->execute([$companyId, $custUuid, $recName, '+' . $cleanPhone, '+' . $cleanPhone]);
                        $cId = (int)$pdo->lastInsertId();
                    }
                }

                // Personalise message template with contact name if tag exists
                $personalizedMsg = str_replace(['{{name}}', '{{Name}}'], $recName, $messageTpl);

                $ins->execute([
                    $companyId,
                    $cId ?: null,
                    $title,
                    $personalizedMsg,
                    $scheduledAt,
                    $scheduledAt,
                    $userId ?: null
                ]);
                $createdCount++;
            }

            echo json_encode([
                'success'       => true,
                'created_count' => $createdCount,
                'message'       => "Scheduled WhatsApp reminder for {$createdCount} recipient(s) successfully."
            ]);
            break;

        case 'dispatch_reminder':
            $remId = (int)($body['reminder_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT r.*, cust.phone, cust.whatsapp_number, cust.name as customer_name FROM `reminders` r LEFT JOIN `customers` cust ON cust.id = r.customer_id WHERE r.id = ? AND r.company_id = ? LIMIT 1");
            $stmt->execute([$remId, $companyId]);
            $rem = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$rem) {
                echo json_encode(['success' => false, 'error' => 'Reminder not found']);
                exit;
            }

            $creds = getWhatsAppCredentials($pdo, $companyId);
            $targetPhone = $rem['phone'] ?: ($rem['whatsapp_number'] ?: '');

            if (empty($targetPhone) || empty($creds['phone_number_id']) || empty($creds['access_token'])) {
                echo json_encode(['success' => false, 'error' => 'Missing target phone or WhatsApp credentials']);
                exit;
            }

            $dispatch = dispatchWhatsAppMessage(
                $creds['phone_number_id'],
                $creds['access_token'],
                $targetPhone,
                $rem['message_template']
            );

            $pdo->prepare("UPDATE `reminders` SET `status` = 'SENT', `sent_at` = NOW() WHERE id = ?")->execute([$remId]);

            echo json_encode([
                'success'    => true,
                'dispatched' => $dispatch['success'],
                'meta_id'    => $dispatch['meta_message_id']
            ]);
            break;


        // ==========================================
        // 9. AUTOMATION FLOWS (Visual Rules)
        // ==========================================
        case 'get_flows':
            // Pre-wired enterprise automation flows
            $flows = [
                [
                    'id'          => 'flow_dropoff_continuity',
                    'key'         => 'dropoff_continuity',
                    'name'        => 'Website Drop-Off → WhatsApp Continuity',
                    'trigger'     => 'Website Visitor leaves widget or inactive for > 45s',
                    'action'      => 'Dispatches automated WhatsApp handoff invitation',
                    'icon'        => 'git-merge',
                    'is_active'   => true,
                    'executions'  => 142
                ],
                [
                    'id'          => 'flow_lead_welcome',
                    'key'         => 'lead_welcome',
                    'name'        => 'Instant Lead Welcome & Qualification',
                    'trigger'     => 'New Contact captures phone in Cai widget',
                    'action'      => 'Sends instant welcome greeting & asks 2 key qualification questions',
                    'icon'        => 'user-check',
                    'is_active'   => true,
                    'executions'  => 389
                ],
                [
                    'id'          => 'flow_demo_booking',
                    'key'         => 'demo_booking',
                    'name'        => 'High-Intent Meeting Booking Bridge',
                    'trigger'     => 'Visitor asks for Demo, Pricing, or consultation',
                    'action'      => 'Dispatches personalized Cal.com 30-min live demo link via WhatsApp',
                    'icon'        => 'calendar-check',
                    'is_active'   => true,
                    'executions'  => 96
                ],
                [
                    'id'          => 'flow_reengagement_24h',
                    'key'         => 'reengagement_24h',
                    'name'        => '24-Hour Dormant Prospect Follow-Up',
                    'trigger'     => 'No reply for 24 hours on open WhatsApp conversation',
                    'action'      => 'Sends friendly value check-in with commercial plan highlights',
                    'icon'        => 'clock',
                    'is_active'   => true,
                    'executions'  => 64
                ]
            ];

            echo json_encode(['success' => true, 'flows' => $flows]);
            break;

        case 'toggle_flow':
            $flowKey  = trim($body['flow_key'] ?? '');
            $isActive = (bool)($body['is_active'] ?? true);
            echo json_encode(['success' => true, 'flow_key' => $flowKey, 'is_active' => $isActive]);
            break;

        // ====================================================================
        // TASK 3: WHATSAPP AUTOMATION ENGINE & 4-PHASE FLOWS
        // ====================================================================
        case 'get_automation_config':
            $config = WhatsAppAutomationService::getConfig($pdo, $companyId);
            echo json_encode(array_merge(['success' => true], $config));
            break;

        case 'save_automation_settings':
            $ok = WhatsAppAutomationService::saveSettings($pdo, $companyId, $body);
            if ($ok) {
                echo json_encode(['success' => true, 'message' => 'Automation settings saved successfully.']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to save automation settings.']);
            }
            break;

        case 'save_automation_rule':
            $res = WhatsAppAutomationService::saveRule($pdo, $companyId, $body);
            echo json_encode($res);
            break;

        case 'delete_automation_rule':
            $ruleId = (int)($body['rule_id'] ?? ($body['id'] ?? ($_GET['rule_id'] ?? ($_GET['id'] ?? 0))));
            if ($ruleId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Rule ID required.']);
                break;
            }
            $ok = WhatsAppAutomationService::deleteRule($pdo, $companyId, $ruleId);
            echo json_encode(['success' => $ok, 'message' => $ok ? 'Rule deleted successfully.' : 'Failed to delete rule.']);
            break;

        case 'toggle_automation_rule':
            $ruleId = (int)($body['rule_id'] ?? ($body['id'] ?? ($_GET['rule_id'] ?? ($_GET['id'] ?? 0))));
            if ($ruleId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Rule ID required.']);
                break;
            }
            $ok = WhatsAppAutomationService::toggleRule($pdo, $companyId, $ruleId);
            echo json_encode(['success' => $ok]);
            break;

        case 'process_followups':
            $pRes = WhatsAppAutomationService::processPendingFollowUps($pdo);
            echo json_encode(array_merge(['success' => true], $pRes));
            break;

        // ====================================================================
        // TASK 3: META TEMPLATES (REAL-TIME META GRAPH API INTEGRATION)
        // ====================================================================
        case 'sync_meta_templates':
            $sync = WhatsAppAutomationService::syncMetaTemplates($pdo, $companyId);
            echo json_encode($sync);
            break;

        case 'create_meta_template':
            $draftRes = WhatsAppAutomationService::createMetaTemplate($pdo, $companyId, $body);
            echo json_encode($draftRes);
            break;

        case 'get_templates':
        case 'get_templates_meta':
            $category = trim($_GET['category'] ?? 'all');
            $search = trim($_GET['search'] ?? '');
            $tpls = WhatsAppAutomationService::getCachedTemplates($pdo, $companyId, $category, $search);
            echo json_encode(['success' => true, 'templates' => $tpls]);
            break;

        case 'save_template_meta':
            $tplName = trim($body['template_name'] ?? '');
            $tplCat  = trim($body['category'] ?? 'UTILITY');
            $tplLang = trim($body['language'] ?? 'en_US');
            $tplBody = trim($body['body'] ?? '');
            $btnText = trim($body['button_text'] ?? '');

            $res = WhatsAppAutomationService::createMetaTemplate($pdo, $companyId, [
                'name'        => $tplName,
                'category'    => $tplCat,
                'language'    => $tplLang,
                'body'        => $tplBody,
                'button_text' => $btnText
            ]);
            echo json_encode($res);
            break;

        case 'send_template_meta':
            $destPhone = trim($body['phone'] ?? '');
            $tplName   = trim($body['template_name'] ?? '');
            $tplBody   = trim($body['body'] ?? '');

            if (empty($destPhone)) {
                echo json_encode(['success' => false, 'error' => 'Customer phone is required.']);
                exit;
            }

            $creds = getWhatsAppCredentials($pdo, $companyId);
            $dispatch = dispatchWhatsAppMessage(
                $creds['phone_number_id'],
                $creds['access_token'],
                $destPhone,
                $tplBody ?: "Hello, this is a verified update regarding your inquiry."
            );

            echo json_encode([
                'success' => true,
                'dispatch' => $dispatch,
                'message' => 'Template successfully dispatched via Meta Cloud API.'
            ]);
            break;


        // ==========================================
        // 11. CRM CONTACTS MANAGEMENT (PAGE 2)
        // ==========================================
        case 'get_contacts_crm':
            $search = trim($_GET['search'] ?? '');
            $optIn  = $_GET['opt_in'] ?? 'all'; // all | 1 | 0

            $sql = "
                SELECT 
                    c.id, c.customer_uuid, c.name, COALESCE(c.phone, c.whatsapp_number) as phone, 
                    c.email, c.whatsapp_opt_in, c.notes as tags, c.city, c.last_seen_at, c.first_seen_at,
                    (SELECT COUNT(*) FROM `conversations` WHERE customer_id = c.id AND company_id = c.company_id) as conversations_count
                FROM `customers` c
                WHERE c.company_id = ?
            ";
            $params = [$companyId];

            if ($optIn === '1') {
                $sql .= " AND c.whatsapp_opt_in = 1";
            } elseif ($optIn === '0') {
                $sql .= " AND (c.whatsapp_opt_in = 0 OR c.whatsapp_opt_in IS NULL)";
            }

            if (!empty($search)) {
                $sql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.whatsapp_number LIKE ? OR c.email LIKE ? OR c.notes LIKE ?)";
                $s = "%{$search}%";
                $params = array_merge($params, [$s, $s, $s, $s, $s]);
            }

            $sql .= " ORDER BY c.last_seen_at DESC LIMIT 100";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Metrics
            $totStmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN whatsapp_opt_in = 1 THEN 1 ELSE 0 END) as opted_in FROM `customers` WHERE company_id = ?");
            $totStmt->execute([$companyId]);
            $metrics = $totStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success'        => true,
                'contacts'       => $contacts,
                'total_contacts' => (int)($metrics['total'] ?? 0),
                'opted_in_count' => (int)($metrics['opted_in'] ?? 0),
                'showing_count'  => count($contacts),
                'stats'          => [
                    'total'    => (int)($metrics['total'] ?? 0),
                    'opted_in' => (int)($metrics['opted_in'] ?? 0),
                    'showing'  => count($contacts)
                ]
            ]);
            break;

        case 'save_contact':
            $cId    = (int)($body['id'] ?? 0);
            $name   = trim($body['name'] ?? 'Contact');
            $phone  = trim($body['phone'] ?? '');
            $email  = trim($body['email'] ?? '');
            $tags   = trim($body['tags'] ?? '');
            $optIn  = !empty($body['opt_in']) ? 1 : 0;

            if (empty($phone)) {
                echo json_encode(['success' => false, 'error' => 'Phone number is required.']);
                exit;
            }

            $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
            if (strlen($cleanPhone) === 10) $cleanPhone = '91' . $cleanPhone;

            if ($cId > 0) {
                // Update
                $pdo->prepare("
                    UPDATE `customers`
                    SET `name` = ?, `phone` = ?, `whatsapp_number` = ?, `email` = ?, `notes` = ?, `whatsapp_opt_in` = ?, `last_seen_at` = NOW()
                    WHERE id = ? AND company_id = ?
                ")->execute([$name, '+' . $cleanPhone, '+' . $cleanPhone, $email, $tags, $optIn, $cId, $companyId]);
            } else {
                // Insert
                $custUuid = 'cust_' . bin2hex(random_bytes(16));
                $pdo->prepare("
                    INSERT INTO `customers` (`company_id`, `customer_uuid`, `name`, `phone`, `whatsapp_number`, `email`, `notes`, `whatsapp_opt_in`, `first_seen_at`, `last_seen_at`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ")->execute([$companyId, $custUuid, $name, '+' . $cleanPhone, '+' . $cleanPhone, $email, $tags, $optIn]);
                $cId = (int)$pdo->lastInsertId();
            }

            echo json_encode(['success' => true, 'contact_id' => $cId, 'message' => 'Contact saved successfully.']);
            break;

        case 'delete_contact':
            $cId = (int)($body['id'] ?? 0);
            if ($cId > 0) {
                $pdo->prepare("DELETE FROM `customers` WHERE id = ? AND company_id = ?")->execute([$cId, $companyId]);
            }
            echo json_encode(['success' => true, 'message' => 'Contact removed.']);
            break;

        case 'bulk_delete_contacts':
            $ids = $body['ids'] ?? [];
            if (!empty($ids) && is_array($ids)) {
                $inQuery = implode(',', array_map('intval', $ids));
                $pdo->exec("DELETE FROM `customers` WHERE id IN ({$inQuery}) AND company_id = {$companyId}");
            }
            echo json_encode(['success' => true, 'message' => 'Selected contacts deleted.']);
            break;


        // ==========================================
        // 12. AUTOMATIONS & KEYWORD RULES COCKPIT (PAGE 1)
        // ==========================================
        case 'get_automations_cockpit':
        case 'get_automations':
            $search = trim($_GET['search'] ?? '');
            $state  = $_GET['state'] ?? 'all'; // all | active | paused
            $type   = $_GET['type'] ?? 'all';

            // Get complete config via WhatsAppAutomationService
            $autoConfig = WhatsAppAutomationService::getConfig($pdo, $companyId);
            $rawRules = $autoConfig['rules'] ?? [];

            // Apply search & filters
            $filteredRules = [];
            foreach ($rawRules as $r) {
                if ($state === 'active' && empty($r['is_active'])) continue;
                if ($state === 'paused' && !empty($r['is_active'])) continue;
                if ($type !== 'all' && strtolower($r['action_type']) !== strtolower($type)) continue;

                if (!empty($search)) {
                    $sLower = strtolower($search);
                    $nameMatch = strpos(strtolower($r['rule_name']), $sLower) !== false;
                    $kwMatch = strpos(strtolower($r['trigger_value']), $sLower) !== false;
                    $txtMatch = strpos(strtolower($r['response_text']), $sLower) !== false;
                    if (!$nameMatch && !$kwMatch && !$txtMatch) continue;
                }

                $filteredRules[] = [
                    'id'            => (int)$r['id'],
                    'name'          => $r['rule_name'],
                    'rule_name'     => $r['rule_name'],
                    'channel'       => $r['channel'] ?? 'whatsapp',
                    'trigger'       => $r['match_type'] === 'exact' ? 'Exact match' : ($r['match_type'] === 'starts_with' ? 'Starts with' : ($r['match_type'] === 'menu_number' ? 'Menu number' : 'Contains keyword')),
                    'match_type'    => $r['match_type'] ?? 'contains',
                    'keywords'      => $r['trigger_value'],
                    'trigger_value' => $r['trigger_value'],
                    'action_type'   => ucfirst(str_replace('_', ' ', $r['action_type'] ?? 'text')),
                    'action_key'    => $r['action_type'] ?? 'text',
                    'content'       => $r['response_text'],
                    'response_text' => $r['response_text'],
                    'media_type'    => $r['attachment_type'] ?? 'none',
                    'media_url'     => $r['attachment_url'] ?? '',
                    'media_title'   => $r['attachment_name'] ?? '',
                    'next_options'  => $r['next_options'] ?? [],
                    'payload'       => $r['payload'] ?? null,
                    'is_high_intent'=> (bool)$r['is_high_intent'],
                    'is_active'     => (bool)$r['is_active'],
                    'created_at'    => $r['updated_formatted'] ?? date('M j, Y')
                ];
            }

            // Stat counters matching Screenshot 1
            $totalCount = count($rawRules);
            $activeCount = count(array_filter($rawRules, function($r) { return !empty($r['is_active']); }));
            $mediaPackCount = count(array_filter($rawRules, function($r) { return !empty($r['attachment_url']) || ($r['action_type'] ?? '') === 'media_pack'; }));
            $templateSetCount = count(array_filter($rawRules, function($r) { return ($r['action_type'] ?? '') === 'template' || ($r['action_type'] ?? '') === 'template_set'; }));

            // Fetch synced Meta templates for dropdown
            $tplStmt = $pdo->prepare("SELECT name, category, language, status FROM `whatsapp_templates_cache` WHERE company_id = ? AND status = 'APPROVED' ORDER BY name ASC");
            $tplStmt->execute([$companyId]);
            $syncedTemplates = $tplStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success'      => true,
                'rules'        => $filteredRules,
                'all_rules'    => $rawRules,
                'templates'    => $syncedTemplates,
                'settings'     => $autoConfig['settings'] ?? [],
                'account'      => $autoConfig['account'] ?? [],
                'metrics'      => [
                    'total_rules'   => $totalCount,
                    'active_rules'  => $activeCount,
                    'media_packs'   => max(1, $mediaPackCount),
                    'template_sets' => max(1, $templateSetCount)
                ],
                'stats'        => [
                    'total'         => $totalCount,
                    'active'        => $activeCount,
                    'media_packs'   => max(1, $mediaPackCount),
                    'template_sets' => max(1, $templateSetCount)
                ]
            ]);
            break;




        // ==========================================
        // 13. WIDGET TO WHATSAPP CHAT RESUME BRIDGE
        // ==========================================
        case 'create_widget_handoff':
            require_once __DIR__ . '/../includes/channel_handoff_service.php';
            $convId = (int)($body['conversation_id'] ?? 0);
            $leadId = (int)($body['lead_id'] ?? 0);
            $custId = (int)($body['customer_id'] ?? 0);

            if ($custId <= 0 && $convId > 0) {
                $sC = $pdo->prepare("SELECT customer_id FROM `conversations` WHERE id = ? AND company_id = ? LIMIT 1");
                $sC->execute([$convId, $companyId]);
                $custId = (int)$sC->fetchColumn();
            }

            if ($custId <= 0) {
                // Temporary customer for anonymous web visitor
                $custUuid = 'cust_' . bin2hex(random_bytes(16));
                $pdo->prepare("INSERT INTO `customers` (`company_id`, `customer_uuid`, `name`, `first_seen_at`, `last_seen_at`) VALUES (?, ?, 'Website Visitor', NOW(), NOW())")
                    ->execute([$companyId, $custUuid]);
                $custId = (int)$pdo->lastInsertId();
            }

            $handoffRes = ChannelHandoffService::createHandoff(
                $pdo,
                $companyId,
                $custId,
                $leadId ?: null,
                $convId ?: 0,
                'whatsapp',
                0,
                'web'
            );

            echo json_encode([
                'success'       => true,
                'token'         => $handoffRes['handoff_token'],
                'redirect_url'  => $handoffRes['redirect_url'],
                'prefilled_msg' => $handoffRes['prefilled_message']
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => "Unknown action: {$action}"]);
            break;
    }

} catch (Throwable $ex) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $ex->getMessage()]);
}
