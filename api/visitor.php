<?php
/**
 * CUBOIDPILOT — VISITOR IDENTIFICATION & AI SESSION CREATION API (Section 1)
 * Collects visitor identity (Name + Phone required, Email optional),
 * provisions/re-identifies Visitor, generates unique Session ID (e.g. CP-8F42K91),
 * links Company + Visitor + Lead + Conversation + Session, and updates CRM timeline.
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Company-Key");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Generate human-readable unique Session ID in format CP-XXXXXXX (e.g. CP-8F42K91)
 */
function generateUniqueSessionId(PDO $pdo): string {
    $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $rand = '';
        for ($i = 0; $i < 7; $i++) {
            $rand .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $code = "CP-{$rand}";
        $chk = $pdo->prepare("SELECT id FROM `visitor_sessions` WHERE `session_id` = ? LIMIT 1");
        $chk->execute([$code]);
        if (!$chk->fetch()) {
            return $code;
        }
    }
    return 'CP-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 7));
}

try {
    $pdo = getDbConnection();

    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?? $_POST;

    $companyKey  = trim($data['company_key'] ?? $_SERVER['HTTP_X_COMPANY_KEY'] ?? '');
    $name        = trim($data['name'] ?? '');
    $phone       = trim($data['phone'] ?? '');
    $email       = strtolower(trim($data['email'] ?? ''));
    $clientSess  = trim($data['session_id'] ?? '');
    $channel     = strtolower(trim($data['channel'] ?? 'web'));
    if (!in_array($channel, ['web', 'instagram', 'whatsapp', 'email', 'widget'])) {
        $channel = 'web';
    }

    // Resolve tenant
    $company = null;
    if ($companyKey === 'cp_live_cuboidpilot' || $companyKey === 'cuboidpilot') {
        $companyKey = 'cp_live_cuboidsoft';
    }
    if (!empty($companyKey) && $companyKey !== 'default') {
        if (!empty($_SESSION['company_id']) && ($companyKey === 'cp_live_cuboidsoft' || $companyKey === 'default')) {
            $stmt = $pdo->prepare("SELECT id, name FROM `companies` WHERE `id` = ? LIMIT 1");
            $stmt->execute([(int)$_SESSION['company_id']]);
            $company = $stmt->fetch();
        }

        if (!$company) {
            $stmt = $pdo->prepare("
                SELECT id, name FROM `companies` 
                WHERE `company_key` = ? 
                   OR `slug` = ? 
                   OR (slug = 'cuboidsoft' AND ? IN ('cp_live_cuboidsoft', 'cp_live_cuboidpilot', 'cuboidsoft', 'cuboidpilot'))
                LIMIT 1
            ");
            $stmt->execute([$companyKey, $companyKey, $companyKey]);
            $company = $stmt->fetch();
        }
    }

    if (!$company && !empty($_SESSION['company_id'])) {
        $stmt = $pdo->prepare("SELECT id, name FROM `companies` WHERE `id` = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['company_id']]);
        $company = $stmt->fetch();
    }

    if (!$company) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Workspace not found. Check widget configuration.']);
        exit;
    }
    $companyId = (int)$company['id'];

    // 1. Validation (Section 1: Name Required, Phone Required, Email Optional)
    if (empty($name)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Please provide your full name.']);
        exit;
    }

    if (empty($phone)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Please provide your phone number so we can reach you.']);
        exit;
    }

    $cleanPhone = preg_replace('/[^0-9+]/', '', $phone);
    if (strlen(preg_replace('/[^0-9]/', '', $cleanPhone)) < 7) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Please provide a valid phone number.']);
        exit;
    }

    // 2. Identify existing visitor/customer to prevent duplicate leads
    $customer = null;
    if (!empty($cleanPhone)) {
        $cStmt = $pdo->prepare("SELECT id, name, phone, email FROM `customers` WHERE `company_id` = ? AND (`phone` = ? OR `whatsapp_number` = ?) LIMIT 1");
        $cStmt->execute([$companyId, $cleanPhone, $cleanPhone]);
        $customer = $cStmt->fetch();
    }
    if (!$customer && !empty($email)) {
        $cStmt = $pdo->prepare("SELECT id, name, phone, email FROM `customers` WHERE `company_id` = ? AND `email` = ? LIMIT 1");
        $cStmt->execute([$companyId, $email]);
        $customer = $cStmt->fetch();
    }
    if (!$customer && !empty($clientSess)) {
        $sStmt = $pdo->prepare("
            SELECT c.id, c.name, c.phone, c.email 
            FROM `customers` c
            JOIN `visitor_sessions` vs ON vs.customer_id = c.id
            WHERE (vs.session_id = ? OR vs.session_token = ?) AND vs.company_id = ?
            LIMIT 1
        ");
        $sStmt->execute([$clientSess, $clientSess, $companyId]);
        $customer = $sStmt->fetch();
    }

    $isReturningVisitor = (bool)$customer;

    if ($customer) {
        $customerId = (int)$customer['id'];
        $custUpdates = [];
        $custParams = [];
        if (!empty($name) && ($customer['name'] === 'Website Visitor' || $customer['name'] === 'Prospect')) {
            $custUpdates[] = "`name` = ?";
            $custParams[] = $name;
        }
        if (!empty($cleanPhone)) {
            $custUpdates[] = "`phone` = ?";
            $custUpdates[] = "`whatsapp_number` = ?";
            $custParams[] = $cleanPhone;
            $custParams[] = $cleanPhone;
        }
        if (!empty($email) && empty($customer['email'])) {
            $custUpdates[] = "`email` = ?";
            $custParams[] = $email;
        }
        $custUpdates[] = "`last_seen_at` = NOW()";
        $custParams[] = $customerId;
        $custParams[] = $companyId;

        $pdo->prepare("UPDATE `customers` SET " . implode(', ', $custUpdates) . " WHERE `id` = ? AND `company_id` = ?")
            ->execute($custParams);
    } else {
        $custUuid = 'cust_' . bin2hex(random_bytes(12));
        $insCust = $pdo->prepare("
            INSERT INTO `customers`
            (`company_id`, `customer_uuid`, `name`, `phone`, `email`, `whatsapp_number`, `first_seen_at`, `last_seen_at`)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $insCust->execute([
            $companyId,
            $custUuid,
            $name,
            $cleanPhone,
            $email ?: null,
            $cleanPhone
        ]);
        $customerId = (int)$pdo->lastInsertId();
    }

    $visitorId = $customerId; // visitor_id maps to customer_id

    // 3. Unique Session ID Generation (CP-8F42K91)
    // Reuse existing Session ID if valid format, else generate new CP-XXXXXXX
    $sessionId = null;
    if (!empty($clientSess) && preg_match('/^CP-[A-Z0-9]{5,10}$/i', $clientSess)) {
        $sessionId = strtoupper($clientSess);
    }

    if (!$sessionId && $isReturningVisitor) {
        // Check if customer already has a CP-XXXXXXX session in this company
        $sessCheck = $pdo->prepare("
            SELECT session_id FROM `visitor_sessions` 
            WHERE `customer_id` = ? AND `company_id` = ? AND `session_id` LIKE 'CP-%'
            ORDER BY id DESC LIMIT 1
        ");
        $sessCheck->execute([$customerId, $companyId]);
        $sessionId = $sessCheck->fetchColumn();
    }

    if (!$sessionId) {
        $sessionId = generateUniqueSessionId($pdo);
    }

    $sessionToken = "sess_" . substr(hash('sha256', "{$sessionId}_{$companyId}_{$customerId}"), 0, 16);

    // 4. Find or Create Conversation
    $convStmt = $pdo->prepare("
        SELECT id FROM `conversations` 
        WHERE `company_id` = ? AND `customer_id` = ? AND `status` IN ('ai_handling', 'human_requested', 'human_active')
        ORDER BY id DESC LIMIT 1
    ");
    $convStmt->execute([$companyId, $customerId]);
    $existingConv = $convStmt->fetch();

    if ($existingConv) {
        $conversationId = (int)$existingConv['id'];
        $pdo->prepare("UPDATE `conversations` SET `session_id` = ?, `last_message_at` = NOW() WHERE `id` = ? AND `company_id` = ?")
            ->execute([$sessionId, $conversationId, $companyId]);
    } else {
        $insConv = $pdo->prepare("
            INSERT INTO `conversations`
            (`company_id`, `customer_id`, `session_id`, `channel`, `status`, `ownership`, `last_message_preview`, `last_message_at`, `created_at`)
            VALUES (?, ?, ?, ?, 'ai_handling', 'ai', 'Visitor identified and session started', NOW(), NOW())
        ");
        $insConv->execute([$companyId, $customerId, $sessionId, $channel]);
        $conversationId = (int)$pdo->lastInsertId();
    }

    // 5. Find or Create Lead (Avoid duplicate leads for returning visitors)
    $leadStmt = $pdo->prepare("
        SELECT id FROM `leads` 
        WHERE `company_id` = ? AND `customer_id` = ? AND `status` = 'open' 
        ORDER BY id DESC LIMIT 1
    ");
    $leadStmt->execute([$companyId, $customerId]);
    $existingLead = $leadStmt->fetch();

    if ($existingLead) {
        $leadId = (int)$existingLead['id'];
        $pdo->prepare("
            UPDATE `leads`
            SET `conversation_id` = COALESCE(`conversation_id`, ?),
                `title` = ?,
                `last_activity_at` = NOW()
            WHERE `id` = ? AND `company_id` = ?
        ")->execute([$conversationId, $name, $leadId, $companyId]);
    } else {
        $stgStmt = $pdo->prepare("SELECT id, name FROM `pipeline_stages` WHERE `company_id` = ? ORDER BY `stage_order` ASC LIMIT 1");
        $stgStmt->execute([$companyId]);
        $firstStage = $stgStmt->fetch();
        $stageId = $firstStage ? (int)$firstStage['id'] : null;
        $stageName = $firstStage ? $firstStage['name'] : 'New Inquiry';

        $insLead = $pdo->prepare("
            INSERT INTO `leads`
            (`company_id`, `customer_id`, `conversation_id`, `title`, `stage_id`, `stage_name`, `intent_level`, `priority`, `opportunity_value`, `source`, `status`, `radar_reason`, `radar_recommended_action`, `last_activity_at`, `created_at`, `updated_at`)
            VALUES (?, ?, ?, ?, ?, ?, 'low', 'LOW', 0, 'WEBSITE_WIDGET', 'open', 'New website visitor identified', 'Awaiting first question', NOW(), NOW(), NOW())
        ");
        $insLead->execute([$companyId, $customerId, $conversationId, $name, $stageId, $stageName]);
        $leadId = (int)$pdo->lastInsertId();

        // Timeline Event: Visitor & Lead Created
        $pdo->prepare("
            INSERT INTO `lead_events`
            (`company_id`, `lead_id`, `customer_id`, `event_type`, `description`, `event_data_json`, `created_at`)
            VALUES (?, ?, ?, 'PHONE_SHARED', ?, ?, NOW())
        ")->execute([
            $companyId,
            $leadId,
            $customerId,
            "Visitor identified: {$name} ({$cleanPhone})",
            json_encode(['name' => $name, 'phone' => $cleanPhone, 'email' => $email, 'session_id' => $sessionId, 'channel' => $channel])
        ]);
    }

    // 6. Persist / Update visitor_sessions with full linking
    $pdo->prepare("
        INSERT INTO `visitor_sessions` 
        (`company_id`, `session_id`, `visitor_id`, `session_token`, `customer_id`, `lead_id`, `conversation_id`, `channel`, `created_at`, `updated_at`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE 
            `session_id` = VALUES(`session_id`),
            `visitor_id` = VALUES(`visitor_id`),
            `customer_id` = VALUES(`customer_id`),
            `lead_id` = VALUES(`lead_id`),
            `conversation_id` = VALUES(`conversation_id`),
            `channel` = VALUES(`channel`),
            `updated_at` = NOW()
    ")->execute([$companyId, $sessionId, $visitorId, $sessionToken, $customerId, $leadId, $conversationId, $channel]);

    // Timeline Event: Session Created
    if ($leadId) {
        $pdo->prepare("
            INSERT INTO `lead_events`
            (`company_id`, `lead_id`, `customer_id`, `event_type`, `description`, `event_data_json`, `created_at`)
            VALUES (?, ?, ?, 'SESSION_CREATED', ?, ?, NOW())
        ")->execute([
            $companyId,
            $leadId,
            $customerId,
            "AI Session established: {$sessionId}",
            json_encode(['session_id' => $sessionId, 'channel' => $channel, 'returning_visitor' => $isReturningVisitor])
        ]);
    }

    $firstName = explode(' ', $name)[0];

    echo json_encode([
        'success'           => true,
        'company_id'        => $companyId,
        'visitor_id'        => $visitorId,
        'customer_id'       => $customerId,
        'lead_id'           => $leadId,
        'conversation_id'   => $conversationId,
        'session_id'        => $sessionId,
        'session_token'     => $sessionToken,
        'visitor_name'      => $name,
        'returning_visitor' => $isReturningVisitor,
        'system_message'    => "Session created successfully\nYou can now continue your conversation.",
        'reply'             => "Hi {$firstName} 👋 Thanks for sharing your details. I'm Cai, your AI assistant for {$company['name']}. How can I help you today?"
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
