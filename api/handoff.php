<?php
/**
 * CUBOIDPILOT — WHATSAPP HANDOFF & CONTINUITY API
 * Generates verified short-lived handoff tokens and builds contextual WhatsApp URLs.
 * Strict multi-tenant isolation and entitlement enforcement.
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

try {
    $pdo = getDbConnection();

    $input = file_get_contents('php://input');
    $data = json_decode($input, true) ?? [];
    if (empty($data)) {
        $data = $_REQUEST;
    }

    $companyKey     = trim($data['company_key'] ?? $_SERVER['HTTP_X_COMPANY_KEY'] ?? '');
    $conversationId = (int)($data['conversation_id'] ?? 0);
    $leadId         = (int)($data['lead_id'] ?? 0);
    $customerId     = (int)($data['customer_id'] ?? 0);
    $action         = trim($data['action'] ?? 'create'); // 'create' or 'verify'
    $tokenParam     = trim($data['token'] ?? '');

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

    // Enforce WhatsApp Entitlements
    checkEntitlement($pdo, $companyId, 'can_use_whatsapp', true);

    if ($action === 'verify' && !empty($tokenParam)) {
        $stmt = $pdo->prepare("
            SELECT h.*, c.name as customer_name, c.phone as customer_phone
            FROM `whatsapp_handoffs` h
            LEFT JOIN `customers` c ON c.id = h.customer_id
            WHERE h.handoff_token = ? AND h.company_id = ? AND h.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$tokenParam, $companyId]);
        $handoff = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$handoff) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Invalid or expired handoff token']);
            exit;
        }

        echo json_encode(['success' => true, 'handoff' => $handoff]);
        exit;
    }

    // Create / fetch handoff token
    // Get WhatsApp account
    $accStmt = $pdo->prepare("SELECT display_number FROM `whatsapp_accounts` WHERE `company_id` = ? AND `status` != 'disconnected' ORDER BY id ASC LIMIT 1");
    $accStmt->execute([$companyId]);
    $acc = $accStmt->fetch();
    $displayPhone = $acc ? $acc['display_number'] : '+91 98201 12345';
    $cleanPhone = preg_replace('/[^0-9]/', '', $displayPhone);

    // If customerId is missing, resolve from conversation or lead
    if (!$customerId && $conversationId) {
        $conv = $pdo->query("SELECT customer_id FROM `conversations` WHERE id = $conversationId AND company_id = $companyId LIMIT 1")->fetch();
        if ($conv) $customerId = (int)$conv['customer_id'];
    }
    if (!$customerId && $leadId) {
        $ld = $pdo->query("SELECT customer_id FROM `leads` WHERE id = $leadId AND company_id = $companyId LIMIT 1")->fetch();
        if ($ld) $customerId = (int)$ld['customer_id'];
    }

    // Get customer phone if available
    $customerPhone = null;
    $customerName = 'Prospect';
    if ($customerId) {
        $cRow = $pdo->query("SELECT name, phone FROM `customers` WHERE id = $customerId AND company_id = $companyId LIMIT 1")->fetch();
        if ($cRow) {
            $customerPhone = $cRow['phone'];
            if (!empty($cRow['name'])) $customerName = $cRow['name'];
        }
    }

    // Generate unique short token
    $tokenCode = strtoupper(bin2hex(random_bytes(3)));
    $handoffToken = "CP-{$companyId}-{$tokenCode}";

    // Persist in whatsapp_handoffs table
    $ins = $pdo->prepare("
        INSERT INTO `whatsapp_handoffs`
        (`company_id`, `handoff_token`, `customer_id`, `lead_id`, `web_conversation_id`, `phone`, `status`, `expires_at`, `created_at`)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW() + INTERVAL 2 HOUR, NOW())
    ");
    $ins->execute([
        $companyId,
        $handoffToken,
        $customerId ?: 0,
        $leadId ?: null,
        $conversationId ?: 0,
        $customerPhone
    ]);

    // Build prefilled message
    $prefilled = "Hi, I'm {$customerName}. I was chatting on your website (Ref: #{$handoffToken}). Could you help me with enrollment & fee details?";
    $encodedText = urlencode($prefilled);
    $waUrl = "https://wa.me/{$cleanPhone}?text={$encodedText}";

    echo json_encode([
        'success' => true,
        'handoff_token' => $handoffToken,
        'whatsapp_url'  => $waUrl,
        'display_phone' => $displayPhone,
        'expires_in_minutes' => 120
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
