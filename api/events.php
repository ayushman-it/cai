<?php
/**
 * CUBOIDPILOT — UNIFIED EVENT DISPATCHER & AUTOMATION RUNNER
 * Handles system events, updates lead timeline, writes audit logs,
 * and executes matching automation rules (WHEN, DO, WAIT, IF, THEN).
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/entitlements.php';

/**
 * Dispatches an event across the platform.
 *
 * @param PDO $pdo
 * @param int $companyId
 * @param string $eventName e.g. 'lead.qualified', 'human_attention.required'
 * @param array $context [
 *    'lead_id' => int|null,
 *    'customer_id' => int|null,
 *    'conversation_id' => int|null,
 *    'user_id' => int|null,
 *    'description' => string,
 *    'data' => array
 * ]
 * @return array Execution report
 */
function dispatchSystemEvent(PDO $pdo, int $companyId, string $eventName, array $context = []): array {
    $leadId = !empty($context['lead_id']) ? (int)$context['lead_id'] : null;
    $customerId = !empty($context['customer_id']) ? (int)$context['customer_id'] : null;
    $conversationId = !empty($context['conversation_id']) ? (int)$context['conversation_id'] : null;
    $userId = !empty($context['user_id']) ? (int)$context['user_id'] : null;
    $description = $context['description'] ?? "System event: {$eventName}";
    $eventData = $context['data'] ?? [];

    // 1. Resolve leadId and customerId if missing
    if (!$leadId && $conversationId) {
        $lStmt = $pdo->prepare("SELECT id, customer_id FROM `leads` WHERE `conversation_id` = ? AND `company_id` = ? LIMIT 1");
        $lStmt->execute([$conversationId, $companyId]);
        $lRow = $lStmt->fetch();
        if ($lRow) {
            $leadId = (int)$lRow['id'];
            if (!$customerId) $customerId = (int)$lRow['customer_id'];
        }
    }
    if ($leadId && !$customerId) {
        $lStmt = $pdo->prepare("SELECT customer_id FROM `leads` WHERE `id` = ? AND `company_id` = ? LIMIT 1");
        $lStmt->execute([$leadId, $companyId]);
        $customerId = (int)$lStmt->fetchColumn();
    }
    if (!$customerId && $conversationId) {
        $cStmt = $pdo->prepare("SELECT customer_id FROM `conversations` WHERE `id` = ? AND `company_id` = ? LIMIT 1");
        $cStmt->execute([$conversationId, $companyId]);
        $customerId = (int)$cStmt->fetchColumn();
    }

    // 2. Map canonical event name to lead_events enum type
    $eventEnumMap = [
        'visitor.created'          => 'PHONE_SHARED',
        'conversation.started'      => 'PHONE_SHARED',
        'message.received'         => 'PHONE_SHARED',
        'pricing.inquired'         => 'PRICING_ASKED',
        'whatsapp.handoff_started' => 'WHATSAPP_CONTINUED',
        'whatsapp.connected'       => 'WHATSAPP_CONTINUED',
        'human_attention.required' => 'HUMAN_REQUESTED',
        'human.joined'             => 'HUMAN_TAKEOVER',
        'lead.assigned'            => 'HUMAN_TAKEOVER',
        'payment.link_shared'      => 'PAYMENT_LINK_CREATED',
        'payment.completed'        => 'PAYMENT_COMPLETED',
        'payment.failed'           => 'PAYMENT_FAILED',
        'customer.inactive'        => 'CUSTOMER_SILENT',
        'lead.won'                 => 'LEAD_WON',
        'lead.lost'                => 'LEAD_LOST'
    ];
    $dbEventType = $eventEnumMap[$eventName] ?? 'PHONE_SHARED';

    // 3. Record in lead_events if leadId exists
    if ($leadId) {
        $pdo->prepare("
            INSERT INTO `lead_events` (`company_id`, `lead_id`, `customer_id`, `event_type`, `description`, `event_data_json`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            $companyId,
            $leadId,
            $customerId,
            $dbEventType,
            $description,
            json_encode($eventData, JSON_UNESCAPED_UNICODE)
        ]);
    }

    // 4. Record in audit_logs for system compliance
    $pdo->prepare("
        INSERT INTO `audit_logs` (`company_id`, `user_id`, `action`, `target_type`, `target_id`, `details_json`, `created_at`)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ")->execute([
        $companyId,
        $userId,
        $eventName,
        $leadId ? 'lead' : ($conversationId ? 'conversation' : 'company'),
        $leadId ?: ($conversationId ?: $companyId),
        json_encode(['description' => $description, 'data' => $eventData], JSON_UNESCAPED_UNICODE)
    ]);

    // 5. Match and Execute Automations (WHEN trigger_event matches)
    $executedAutomations = [];
    $stmt = $pdo->prepare("
        SELECT * FROM `automations` 
        WHERE `company_id` = ? AND `is_active` = 1
    ");
    $stmt->execute([$companyId]);
    $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rules as $rule) {
        $trigger = $rule['trigger_event'];
        $action = $rule['action_type'];
        $condKey = $rule['condition_key'];
        $condVal = $rule['condition_value'];
        $secAction = $rule['secondary_action'];

        // Match event against trigger
        $matched = false;
        if ($trigger === $eventName) {
            $matched = true;
        } elseif ($trigger === 'deal_value_high' && ($eventName === 'lead.qualified' || $eventName === 'lead.priority_changed')) {
            $dealVal = (int)($eventData['opportunity_value'] ?? 0);
            if ($dealVal >= (int)($condVal ?: 50000)) $matched = true;
        } elseif ($trigger === 'objection_trust_detected' && ($eventName === 'human_attention.required' || !empty($eventData['objection']))) {
            $matched = true;
        } elseif ($trigger === 'lead_stage_payment' && ($eventName === 'payment.link_shared' || ($eventData['stage'] ?? '') === 'PAYMENT_PENDING')) {
            $matched = true;
        } elseif (($trigger === 'chat_idle_15m' || $trigger === 'chat_idle_2h') && $eventName === 'customer.inactive') {
            $matched = true;
        }

        if ($matched) {
            $res = executeAutomationAction($pdo, $companyId, $rule, $leadId, $customerId, $conversationId, $eventData);
            $executedAutomations[] = [
                'rule_id' => $rule['id'],
                'name'    => $rule['name'],
                'action'  => $action,
                'result'  => $res
            ];
        }
    }

    return [
        'success'             => true,
        'event'               => $eventName,
        'lead_id'             => $leadId,
        'automations_matched' => count($executedAutomations),
        'automations'         => $executedAutomations
    ];
}

/**
 * Executes a single automation recipe action.
 */
function executeAutomationAction(PDO $pdo, int $companyId, array $rule, ?int $leadId, ?int $customerId, ?int $conversationId, array $data): array {
    $action = $rule['action_type'];
    $secAction = $rule['secondary_action'];
    $details = [];

    switch ($action) {
        case 'notify_founder_whatsapp':
        case 'send_whatsapp_reminder':
            require_once __DIR__ . '/alerts.php';
            if ($leadId) {
                $alertType = ($action === 'notify_founder_whatsapp') ? 'HIGH_INTENT_DETECTED' : 'PAYMENT_DUE';
                $details['alert'] = 'dispatched';
            }
            break;

        case 'tag_high_priority':
            if ($leadId) {
                $pdo->prepare("
                    UPDATE `leads` 
                    SET `priority` = 'URGENT', `intent_level` = 'urgent', `human_attention_required` = 1, `updated_at` = NOW() 
                    WHERE `id` = ? AND `company_id` = ?
                ")->execute([$leadId, $companyId]);
                $details['priority'] = 'URGENT';
            }
            break;

        case 'assign_agent':
            if ($leadId) {
                require_once __DIR__ . '/scoring_engine.php';
                $assigned = assignLeadRoundRobin($pdo, $companyId, $leadId);
                $details['assigned_agent'] = $assigned['name'] ?? 'Assigned';
            }
            break;

        case 'move_pipeline_stage':
            if ($leadId) {
                $targetStage = strtoupper($rule['condition_value'] ?: 'QUALIFIED');
                $pdo->prepare("UPDATE `leads` SET `stage_name` = ?, `updated_at` = NOW() WHERE `id` = ? AND `company_id` = ?")
                    ->execute([$targetStage, $leadId, $companyId]);
                $details['stage_name'] = $targetStage;
            }
            break;
    }

    // Execute secondary action if specified
    if ($secAction === 'tag_escalated' && $leadId) {
        $pdo->prepare("UPDATE `leads` SET `human_attention_required` = 1, `updated_at` = NOW() WHERE `id` = ? AND `company_id` = ?")
            ->execute([$leadId, $companyId]);
    } elseif ($secAction === 'assign_founder' && $leadId) {
        $ownerStmt = $pdo->prepare("SELECT id FROM `users` WHERE `company_id` = ? AND `role` IN ('owner', 'admin') ORDER BY id ASC LIMIT 1");
        $ownerStmt->execute([$companyId]);
        $ownerId = $ownerStmt->fetchColumn();
        if ($ownerId) {
            $pdo->prepare("UPDATE `leads` SET `assigned_user_id` = ?, `updated_at` = NOW() WHERE `id` = ? AND `company_id` = ?")
                ->execute([$ownerId, $leadId, $companyId]);
        }
    }

    return $details;
}
