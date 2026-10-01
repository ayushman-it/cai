<?php
/**
 * CUBOIDPILOT — VISITOR LEAD CAPTURE API (Sections 7, 8 & 9)
 * Collects visitor identity (Name + Phone/Email), establishes session,
 * and provisions Lead + Conversation in MySQL before chat begins.
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

try {
    $pdo = getDbConnection();

    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?? $_POST;

    $companyKey = trim($data['company_key'] ?? $_SERVER['HTTP_X_COMPANY_KEY'] ?? '');
    $name       = trim($data['name'] ?? '');
    $phone      = trim($data['phone'] ?? '');
    $email      = strtolower(trim($data['email'] ?? ''));
    $sessionId  = trim($data['session_id'] ?? '');

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

    if (empty($name)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Please share your name so we know who we are speaking with.']);
        exit;
    }

    if (empty($phone) && empty($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Please provide at least one contact method (phone number or email) so we can reach you.']);
        exit;
    }

    // Clean phone number
    $cleanPhone = !empty($phone) ? preg_replace('/[^0-9+]/', '', $phone) : null;

    if (empty($sessionId)) {
        $sessionId = 'sess_' . bin2hex(random_bytes(12));
    }

    // 1. Customer deduplication within this company
    $customer = null;
    if (!empty($cleanPhone)) {
        $cStmt = $pdo->prepare("SELECT id, name, phone, email FROM `customers` WHERE `company_id` = ? AND `phone` = ? LIMIT 1");
        $cStmt->execute([$companyId, $cleanPhone]);
        $customer = $cStmt->fetch();
    }
    if (!$customer && !empty($email)) {
        $cStmt = $pdo->prepare("SELECT id, name, phone, email FROM `customers` WHERE `company_id` = ? AND `email` = ? LIMIT 1");
        $cStmt->execute([$companyId, $email]);
        $customer = $cStmt->fetch();
    }

    if ($customer) {
        $customerId = (int)$customer['id'];
        $custUpdates = [];
        $custParams = [];
        if (!empty($name)) {
            $custUpdates[] = "`name` = ?";
            $custParams[] = $name;
        }
        if (!empty($cleanPhone)) {
            $custUpdates[] = "`phone` = ?";
            $custUpdates[] = "`whatsapp_number` = ?";
            $custParams[] = $cleanPhone;
            $custParams[] = $cleanPhone;
        }
        if (!empty($email)) {
            $custUpdates[] = "`email` = ?";
            $custParams[] = $email;
        }
        $custUpdates[] = "`last_seen_at` = NOW()";
        $custParams[] = $customerId;

        $pdo->prepare("UPDATE `customers` SET " . implode(', ', $custUpdates) . " WHERE `id` = ?")->execute($custParams);
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

    // 2. Register or update visitor session (Atomic ON DUPLICATE KEY UPDATE prevents 1062 duplicate key error)
    $pdo->prepare("
        INSERT INTO `visitor_sessions` (`company_id`, `session_token`, `customer_id`, `created_at`)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE 
            `customer_id` = VALUES(`customer_id`),
            `company_id` = VALUES(`company_id`)
    ")->execute([$companyId, $sessionId, $customerId]);

    // 3. Find or Create initial Conversation
    $convStmt = $pdo->prepare("
        SELECT id FROM `conversations` 
        WHERE `company_id` = ? AND `customer_id` = ? AND `channel` = 'widget' AND `status` = 'ai_handling'
        ORDER BY id DESC LIMIT 1
    ");
    $convStmt->execute([$companyId, $customerId]);
    $existingConv = $convStmt->fetch();

    if ($existingConv) {
        $conversationId = (int)$existingConv['id'];
    } else {
        $insConv = $pdo->prepare("
            INSERT INTO `conversations`
            (`company_id`, `customer_id`, `channel`, `status`, `ownership`, `last_message_preview`, `last_message_at`, `created_at`)
            VALUES (?, ?, 'widget', 'ai_handling', 'ai', 'Lead details captured', NOW(), NOW())
        ");
        $insConv->execute([$companyId, $customerId]);
        $conversationId = (int)$pdo->lastInsertId();
    }

    // 4. Find or Create Lead (Section 8: Initial State NEW, Priority LOW / UNASSESSED, Source WEBSITE_WIDGET)
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
            WHERE `id` = ?
        ")->execute([$conversationId, $name, $leadId]);
    } else {
        // Resolve first stage
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
        $insLead->execute([
            $companyId,
            $customerId,
            $conversationId,
            $name,
            $stageId,
            $stageName
        ]);
        $leadId = (int)$pdo->lastInsertId();

        // 5. Activity Timeline Event (Section 44)
        $pdo->prepare("
            INSERT INTO `lead_events`
            (`company_id`, `lead_id`, `customer_id`, `event_type`, `description`, `event_data_json`, `created_at`)
            VALUES (?, ?, ?, 'PHONE_SHARED', ?, ?, NOW())
        ")->execute([
            $companyId,
            $leadId,
            $customerId,
            "Lead created from website widget: {$name} ({$cleanPhone})",
            json_encode(['name' => $name, 'phone' => $cleanPhone, 'email' => $email, 'source' => 'WEBSITE_WIDGET'])
        ]);
    }

    $firstName = explode(' ', $name)[0];

    echo json_encode([
        'success'         => true,
        'customer_id'     => $customerId,
        'lead_id'         => $leadId,
        'conversation_id' => $conversationId,
        'session_id'      => $sessionId,
        'visitor_name'    => $name,
        'reply'           => "Hi {$firstName} 👋 Thanks for sharing your details. I'm Cai, your AI assistant for {$company['name']}. What can I help you with today?"
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("[CuboidPilot Visitor API Error] " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Please provide a valid phone number or email address so we can get in touch.'
    ]);
}
