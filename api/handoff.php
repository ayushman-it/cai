<?php
/**
 * CUBOIDPILOT — OMNICHANNEL HANDOFF & CONTINUITY API (Section 5 & 6)
 * Supports seamless handoffs from Web Chat to WhatsApp and Instagram.
 * Strict multi-tenant isolation, session preservation, and CRM timeline linking.
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
require_once __DIR__ . '/entitlements.php';
require_once __DIR__ . '/../includes/customer_journey_service.php';
require_once __DIR__ . '/../includes/channel_handoff_service.php';

try {
    $pdo = getDbConnection();

    $input = file_get_contents('php://input');
    $data = json_decode($input, true) ?? [];
    if (empty($data)) {
        $data = $_REQUEST;
    }

    $companyKey     = trim($data['company_key'] ?? $_SERVER['HTTP_X_COMPANY_KEY'] ?? '');
    $channel        = strtolower(trim($data['channel'] ?? 'whatsapp')); // 'whatsapp' or 'instagram'
    $conversationId = (int)($data['conversation_id'] ?? 0);
    $leadId         = (int)($data['lead_id'] ?? 0);
    $customerId     = (int)($data['customer_id'] ?? 0);
    $sessionId      = trim($data['session_id'] ?? '');
    $action         = trim($data['action'] ?? 'create'); // 'create', 'verify', 'create_instagram_handoff'
    $tokenParam     = trim($data['token'] ?? '');

    if ($action === 'create_instagram_handoff') {
        $channel = 'instagram';
    }

    if (empty($companyKey) || $companyKey === 'default' || $companyKey === 'cp_live_cuboidpilot' || $companyKey === 'cuboidpilot') {
        $companyKey = 'cp_live_cuboidsoft';
    }

    // Resolve tenant
    $stmt = $pdo->prepare("
        SELECT id, name, slug, company_key, plan_tier FROM `companies` 
        WHERE `company_key` = ? 
           OR `slug` = ? 
           OR (slug = 'cuboidsoft' AND ? IN ('cp_live_cuboidsoft', 'cp_live_cuboidpilot', 'cuboidsoft', 'cuboidpilot'))
        LIMIT 1
    ");
    $stmt->execute([$companyKey, $companyKey, $companyKey]);
    $company = $stmt->fetch();

    if (!$company) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Workspace not found. Check handoff configuration.']);
        exit;
    }
    $companyId = (int)$company['id'];

    // Resolve customer and session if missing
    if (!$customerId && !empty($sessionId)) {
        $sStmt = $pdo->prepare("SELECT customer_id, lead_id, conversation_id FROM `visitor_sessions` WHERE `session_id` = ? OR `session_token` = ? AND `company_id` = ? LIMIT 1");
        $sStmt->execute([$sessionId, $sessionId, $companyId]);
        $sRow = $sStmt->fetch();
        if ($sRow) {
            $customerId = (int)$sRow['customer_id'];
            if (!$leadId && !empty($sRow['lead_id'])) $leadId = (int)$sRow['lead_id'];
            if (!$conversationId && !empty($sRow['conversation_id'])) $conversationId = (int)$sRow['conversation_id'];
        }
    }

    if (!$customerId && $conversationId) {
        $conv = $pdo->query("SELECT customer_id FROM `conversations` WHERE id = {$conversationId} AND company_id = {$companyId} LIMIT 1")->fetch();
        if ($conv) $customerId = (int)$conv['customer_id'];
    }
    if (!$customerId && $leadId) {
        $ld = $pdo->query("SELECT customer_id FROM `leads` WHERE id = {$leadId} AND company_id = {$companyId} LIMIT 1")->fetch();
        if ($ld) $customerId = (int)$ld['customer_id'];
    }

    $customerName = 'Prospect';
    $customerPhone = null;
    if ($customerId) {
        $cRow = $pdo->query("SELECT name, phone FROM `customers` WHERE id = {$customerId} AND company_id = {$companyId} LIMIT 1")->fetch();
        if ($cRow) {
            $customerPhone = $cRow['phone'];
            if (!empty($cRow['name']) && $cRow['name'] !== 'Website Visitor') $customerName = $cRow['name'];
        }
    }

    // Resolve Omnichannel Journey
    $journey = CustomerJourneyService::getOrCreateJourney($pdo, $companyId, $customerId, $leadId, 'web', $sessionId, $conversationId);
    $journeyId = (int)$journey['id'];

    // =========================================================================
    // 1. INSTAGRAM HANDOFF FLOW (Sections 5 & 6)
    // =========================================================================
    if ($channel === 'instagram') {
        $igStmt = $pdo->prepare("SELECT * FROM `company_instagram_configs` WHERE `company_id` = ? LIMIT 1");
        $igStmt->execute([$companyId]);
        $igConfig = $igStmt->fetch(PDO::FETCH_ASSOC);

        $igUsername = $igConfig['instagram_username'] ?? '';
        if (empty($igUsername)) {
            $wRow = $pdo->query("SELECT brand_name FROM `widget_settings` WHERE `company_id` = {$companyId} LIMIT 1")->fetch();
            $igUsername = strtolower(preg_replace('/[^a-zA-Z0-9_.]/', '', $wRow['brand_name'] ?? $company['slug']));
        }

        $handoffRes = ChannelHandoffService::createHandoff(
            $pdo,
            $companyId,
            $customerId,
            $leadId,
            $conversationId,
            'instagram',
            $journeyId
        );
        $handoffToken = $handoffRes['handoff_token'];
        $igUrl = !empty($igUsername) ? "https://ig.me/m/{$igUsername}" : "https://instagram.com";

        echo json_encode([
            'success'            => true,
            'channel'            => 'instagram',
            'handoff_token'      => $handoffToken,
            'instagram_username' => $igUsername,
            'instagram_url'      => $igUrl,
            'prefilled_message'  => "Hi, I was chatting on your website (Ref: {$handoffToken}) and want to continue here.",
            'expires_in_minutes' => 120
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =========================================================================
    // 2. WHATSAPP HANDOFF FLOW
    // =========================================================================
    checkEntitlement($pdo, $companyId, 'can_use_whatsapp', true);

    if ($action === 'verify' && !empty($tokenParam)) {
        $stmt = $pdo->prepare("
            SELECT h.*, c.name as customer_name, c.phone as customer_phone
            FROM `channel_handoffs` h
            LEFT JOIN `customers` c ON c.id = h.customer_id
            WHERE h.handoff_token = ? AND h.company_id = ? AND h.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$tokenParam, $companyId]);
        $handoff = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$handoff) {
            // Check legacy table
            $stmt = $pdo->prepare("
                SELECT h.*, c.name as customer_name, c.phone as customer_phone
                FROM `whatsapp_handoffs` h
                LEFT JOIN `customers` c ON c.id = h.customer_id
                WHERE h.handoff_token = ? AND h.company_id = ? AND h.expires_at > NOW()
                LIMIT 1
            ");
            $stmt->execute([$tokenParam, $companyId]);
            $handoff = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$handoff) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Invalid or expired handoff token']);
            exit;
        }

        echo json_encode(['success' => true, 'handoff' => $handoff]);
        exit;
    }

    // Get WhatsApp account
    $accStmt = $pdo->prepare("SELECT display_number FROM `whatsapp_accounts` WHERE `company_id` = ? AND `status` != 'disconnected' ORDER BY id ASC LIMIT 1");
    $accStmt->execute([$companyId]);
    $acc = $accStmt->fetch();
    $displayPhone = $acc ? $acc['display_number'] : '+91 98201 12345';
    $cleanPhone = preg_replace('/[^0-9]/', '', $displayPhone);

    $handoffRes = ChannelHandoffService::createHandoff(
        $pdo,
        $companyId,
        $customerId,
        $leadId,
        $conversationId,
        'whatsapp',
        $journeyId
    );
    $handoffToken = $handoffRes['handoff_token'];

    // Build prefilled message
    $prefilled = "Hi, I'm {$customerName}. I was chatting on your website (Ref: {$handoffToken}). Could you help me with enrollment & fee details?";
    $encodedText = urlencode($prefilled);
    $waUrl = "https://wa.me/{$cleanPhone}?text={$encodedText}";

    echo json_encode([
        'success'            => true,
        'channel'            => 'whatsapp',
        'handoff_token'      => $handoffToken,
        'whatsapp_url'       => $waUrl,
        'display_phone'      => $displayPhone,
        'expires_in_minutes' => 120
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
