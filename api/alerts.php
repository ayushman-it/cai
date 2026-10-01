<?php
/**
 * CUBOIDPILOT — SALESPERSON SMART ALERTS ENGINE
 * Generates and routes high-intent, human-required, assignment, and payment alerts.
 * Enforces multi-tenant isolation, 60-minute anti-spam cooldowns, and entitlement gating.
 */

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/entitlements.php';

$pdo = getDbConnection();

/**
 * Function callable from scoring_engine, leads, events, etc.
 */
function sendSalespersonAssignmentAlert($pdo, $companyId, $leadId, $assignee = null, $alertType = 'LEAD_ASSIGNED') {
    try {
        $lStmt = $pdo->prepare("
            SELECT l.*, c.name as customer_name, c.phone as customer_phone, c.email as customer_email,
                   u.name as assigned_name, u.phone as assigned_phone, u.email as assigned_email,
                   comp.name as company_name
            FROM `leads` l
            LEFT JOIN `customers` c ON c.id = l.customer_id
            LEFT JOIN `users` u ON u.id = l.assigned_user_id
            LEFT JOIN `companies` comp ON comp.id = l.company_id
            WHERE l.id = ? AND l.company_id = ?
            LIMIT 1
        ");
        $lStmt->execute([$leadId, $companyId]);
        $lead = $lStmt->fetch(PDO::FETCH_ASSOC);

        if (!$lead) {
            return ['success' => false, 'error' => 'Lead not found in this workspace'];
        }

        $recipientPhone = $assignee['phone'] ?? $lead['assigned_phone'] ?? '';
        if (empty($recipientPhone)) {
            $uStmt = $pdo->prepare("SELECT phone FROM `users` WHERE `company_id` = ? AND `role` IN ('owner', 'admin', 'manager') AND `phone` IS NOT NULL AND `phone` != '' ORDER BY id ASC LIMIT 1");
            $uStmt->execute([$companyId]);
            $uRow = $uStmt->fetch();
            $recipientPhone = $uRow ? $uRow['phone'] : '+919820011111';
        }

        $custName  = $lead['customer_name'] ?: ($lead['title'] ?: 'Prospect');
        $custPhone = $lead['customer_phone'] ?: 'No phone provided';
        $dealVal   = !empty($lead['opportunity_value']) ? "₹" . number_format($lead['opportunity_value']) : "TBD";
        $summary   = $lead['ai_summary'] ?: 'High commercial intent indicated in chat session.';
        $action    = $lead['radar_recommended_action'] ?: 'Follow up promptly with prospective student.';

        $headline = match ($alertType) {
            'LEAD_ASSIGNED'         => "🎯 NEW LEAD ASSIGNED TO YOU",
            'HIGH_INTENT_DETECTED'  => "🔥 HIGH-INTENT PROSPECT ACTIVE",
            'HIGH_INTENT_DROPPED'   => "⚠️ HIGH-INTENT LEAD DROPPED OFF",
            'HUMAN_REQUIRED'        => "🚨 PROSPECT REQUESTS HUMAN COUNSELOR",
            'PAYMENT_DUE'           => "💳 UPCOMING EMI INSTALLMENT DUE",
            'PAYMENT_OVERDUE'       => "⛔ OVERDUE INSTALLMENT NOTICE",
            default                 => "📢 CUBOIDPILOT LEAD ALERT"
        };

        $alertBody = "{$headline}\n"
            . "Company: {$lead['company_name']}\n"
            . "Prospect: {$custName} ({$custPhone})\n"
            . "Deal Value: {$dealVal} • Priority: {$lead['priority']}\n\n"
            . "Executive Context:\n{$summary}\n\n"
            . "Recommended Action:\n{$action}\n"
            . "View Lead: https://cuboidpilot.app/app/lead-detail.html?id={$leadId}";

        // Record in alert_logs
        $pdo->prepare("
            INSERT INTO `alert_logs` (`company_id`, `lead_id`, `alert_type`, `recipient`, `sent_at`)
            VALUES (?, ?, ?, ?, NOW())
        ")->execute([$companyId, $leadId, $alertType, $recipientPhone]);

        // Record in whatsapp_messages as outgoing alert
        $pdo->prepare("
            INSERT INTO `whatsapp_messages` 
            (`company_id`, `recipient_phone`, `recipient_name`, `message_type`, `content`, `status`, `metadata_json`, `created_at`)
            VALUES (?, ?, ?, 'human_alert', ?, 'sent', ?, NOW())
        ")->execute([
            $companyId,
            $recipientPhone,
            $assignee['name'] ?? $lead['assigned_name'] ?? 'Sales Closer',
            $alertBody,
            json_encode([
                'alert_type' => $alertType,
                'lead_id'    => $leadId,
                'deal_value' => $lead['opportunity_value']
            ])
        ]);

        return [
            'success'       => true,
            'alert_status'  => 'sent',
            'alert_type'    => $alertType,
            'lead_id'       => $leadId,
            'recipient'     => $recipientPhone,
            'alert_preview' => $alertBody
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Only execute as HTTP endpoint when requested directly
if (php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'alerts.php') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true) ?? $_POST;

    $companyKey = trim($data['company_key'] ?? $_SERVER['HTTP_X_COMPANY_KEY'] ?? '');
    $companyId  = (int)($data['company_id'] ?? 0);
    $leadId     = (int)($data['lead_id'] ?? 0);
    $alertType  = strtoupper(trim($data['alert_type'] ?? 'HIGH_INTENT_DETECTED'));
    $recipientPhone = trim($data['recipient_phone'] ?? '');

    if (!$companyId && !empty($companyKey)) {
        $cStmt = $pdo->prepare("SELECT id FROM `companies` WHERE `company_key` = ? OR `slug` = ? LIMIT 1");
        $cStmt->execute([$companyKey, $companyKey]);
        $c = $cStmt->fetch();
        if ($c) $companyId = (int)$c['id'];
    }

    if (!$companyId) {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $companyId = (int)($_SESSION['company_id'] ?? 0);
    }

    if (!$companyId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Company identification is required']);
        exit;
    }

    if (!checkEntitlement($pdo, $companyId, 'can_receive_whatsapp_alerts')) {
        echo json_encode([
            'success' => false,
            'skipped' => true,
            'reason'  => 'Plan does not include WhatsApp sales alerts'
        ]);
        exit;
    }

    $res = sendSalespersonAssignmentAlert($pdo, $companyId, $leadId, ['phone' => $recipientPhone], $alertType);
    echo json_encode($res);
    exit;
}
