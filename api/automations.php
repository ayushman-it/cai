<?php
/**
 * CUBOIDPILOT — AUTOMATIONS & WORKFLOW ENGINE API
 * Manages automated lead routing, WhatsApp followups, stage triggers,
 * and AI copilot escalation workflows. Strictly tenant-isolated.
 */

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/entitlements.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = getDbConnection();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];

$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true) ?? $_POST;

try {
    switch ($action) {
        // 1. List Automations (Auto-seeds starter workflows if empty)
        case 'list':
            $stmt = $pdo->prepare("
                SELECT id, company_id, name, trigger_event, action_type, wait_minutes,
                       condition_key, condition_value, secondary_action, is_active,
                       DATE_FORMAT(created_at, '%b %e, %Y') as formatted_date
                FROM `automations`
                WHERE company_id = ?
                ORDER BY id ASC
            ");
            $stmt->execute([$companyId]);
            $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // If empty, auto-seed default recipes
            if (empty($rules)) {
                $defaults = [
                    [
                        'name' => 'Payment Stage Stalled Followup',
                        'trigger' => 'lead_stage_payment',
                        'action' => 'send_whatsapp_reminder',
                        'wait' => 15,
                        'cond_key' => 'stage_duration_min',
                        'cond_val' => '15',
                        'sec_action' => 'tag_escalated'
                    ],
                    [
                        'name' => 'Hinglish Trust Objection Escalation',
                        'trigger' => 'objection_trust_detected',
                        'action' => 'tag_high_priority',
                        'wait' => 0,
                        'cond_key' => 'intent_keyword',
                        'cond_val' => 'trust,guarantee,refund',
                        'sec_action' => 'assign_founder'
                    ],
                    [
                        'name' => 'High Ticket Lead Founder Routing',
                        'trigger' => 'deal_value_high',
                        'action' => 'notify_founder_whatsapp',
                        'wait' => 5,
                        'cond_key' => 'amount_inr_gt',
                        'cond_val' => '50000',
                        'sec_action' => 'lock_lead'
                    ]
                ];

                $ins = $pdo->prepare("
                    INSERT INTO `automations` 
                    (company_id, name, trigger_event, action_type, wait_minutes, condition_key, condition_value, secondary_action, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
                ");

                foreach ($defaults as $d) {
                    $ins->execute([
                        $companyId,
                        $d['name'],
                        $d['trigger'],
                        $d['action'],
                        $d['wait'],
                        $d['cond_key'],
                        $d['cond_val'],
                        $d['sec_action']
                    ]);
                }

                $stmt->execute([$companyId]);
                $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Map friendly labels and details
            $formatted = array_map(function($r) {
                $targetType = ($r['action_type'] === 'send_whatsapp_reminder' || $r['condition_key'] === 'customer') ? 'customer' : 'team';
                $targetLabel = ($targetType === 'customer') ? 'Customer WhatsApp' : 'Team / Counselor Phone';

                $summary = match($r['trigger_event']) {
                    'lead_stage_payment'       => 'When customer stops at payment or fee page, automatically dispatch WhatsApp reminder with 3-Month 0% EMI assistance.',
                    'objection_trust_detected' => 'When visitor asks for guarantees, refund terms, or human help, tag High Priority and alert on-call counselor.',
                    'deal_value_high'          => 'When opportunity value exceeds ₹50,000, trigger priority alert to Founder phone on WhatsApp.',
                    'chat_idle_2h', 'chat_idle_15m' => 'When visitor is silent in conversation for 15+ minutes, send polite re-engagement nudge.',
                    'first_touch_lead', 'lead_capture' => 'When new prospect enters contact info, instantly deliver welcome brochure on WhatsApp.',
                    'whatsapp_inbound'         => 'When an inbound WhatsApp message arrives from a prospect, trigger instant follow-up sequence.',
                    'stage_demo_booked'        => 'When counseling demo session is scheduled, send calendar confirmation and reminder.',
                    'deal_won'                 => 'When deal is won and payment received, trigger onboarding flow and notify team.',
                    'lead_no_reply_24h'        => 'When lead has been uncontacted for 24 hours, trigger counselor escalation reminder.',
                    'installment_overdue'      => 'When installment deadline approaches, dispatch payment link and reminder.',
                    'custom_event'             => 'Custom automated workflow triggered on ' . ($r['condition_value'] ?: 'custom business rule') . '.',
                    default                    => 'Automated workflow rule triggered on ' . ucwords(str_replace('_', ' ', $r['trigger_event']))
                };

                return [
                    'id'               => (int)$r['id'],
                    'name'             => $r['name'],
                    'target_type'      => $targetType,
                    'target_label'     => $targetLabel,
                    'plain_summary'    => $summary,
                    'trigger_event'    => $r['trigger_event'],
                    'trigger_label'    => match($r['trigger_event']) {
                        'lead_stage_payment'      => 'Customer Stops at Payment Page',
                        'objection_trust_detected'=> 'Trust / Guarantee / Human Help Needed',
                        'deal_value_high'         => 'High-Value Opportunity (> ₹50,000)',
                        'chat_idle_2h', 'chat_idle_15m' => 'Customer Silent in Chat (15m+)',
                        'first_touch_lead', 'lead_capture' => 'New Lead Ingestion / Phone Capture',
                        'whatsapp_inbound'        => 'Inbound WhatsApp Inquiry Received',
                        'stage_demo_booked'       => 'Demo / Counseling Session Booked',
                        'deal_won'                => 'Deal Won & Payment Confirmed',
                        'lead_no_reply_24h'       => 'Prospect Uncontacted for 24 Hours',
                        'installment_overdue'     => 'Fee / Installment Due or Overdue',
                        'custom_event'            => 'Custom System Trigger (' . ($r['condition_value'] ?: 'Custom') . ')',
                        default                   => ucwords(str_replace('_', ' ', $r['trigger_event']))
                    },
                    'action_type'      => $r['action_type'],
                    'action_label'     => match($r['action_type']) {
                        'send_whatsapp_reminder'  => 'Send WhatsApp Follow-up Message to Customer',
                        'tag_high_priority'       => 'Tag as High Priority & Alert Team',
                        'notify_founder_whatsapp' => 'Send Instant WhatsApp Reminder to Team / Founder Phone',
                        'assign_agent'            => 'Round-Robin Lead Assignment to Counselor',
                        'move_pipeline_stage'     => 'Auto-Advance Deal Pipeline Stage',
                        'send_webhook'            => 'Dispatch Webhook Payload (Zapier/Make/API)',
                        default                   => ucwords(str_replace('_', ' ', $r['action_type']))
                    },
                    'wait_minutes'     => (int)$r['wait_minutes'],
                    'condition_key'    => $r['condition_key'],
                    'condition_value'  => $r['condition_value'],
                    'secondary_action' => $r['secondary_action'],
                    'is_active'        => (bool)$r['is_active'],
                    'formatted_date'   => $r['formatted_date']
                ];
            }, $rules);

            echo json_encode([
                'success' => true,
                'count'   => count($formatted),
                'rules'   => $formatted
            ]);
            break;

        // 2. Toggle active state
        case 'toggle':
            $ruleId = (int)($data['id'] ?? ($_GET['id'] ?? 0));
            $isActive = isset($data['is_active']) ? (int)(bool)$data['is_active'] : null;

            if ($ruleId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Valid automation ID required']);
                exit;
            }

            if ($isActive === null) {
                $toggleStmt = $pdo->prepare("UPDATE `automations` SET is_active = NOT is_active WHERE id = ? AND company_id = ?");
                $toggleStmt->execute([$ruleId, $companyId]);
            } else {
                $toggleStmt = $pdo->prepare("UPDATE `automations` SET is_active = ? WHERE id = ? AND company_id = ?");
                $toggleStmt->execute([$isActive, $ruleId, $companyId]);
            }

            echo json_encode(['success' => true, 'message' => 'Automation state updated']);
            break;

        // 3. Add new automation recipe
        case 'add':
        case 'create':
            $name = trim($data['name'] ?? '');
            $triggerEvent = trim($data['trigger_event'] ?? '');
            $actionType = trim($data['action_type'] ?? '');
            $waitMinutes = max(0, (int)($data['wait_minutes'] ?? 0));
            $conditionKey = trim($data['condition_key'] ?? '');
            $conditionValue = trim($data['condition_value'] ?? '');
            $secondaryAction = trim($data['secondary_action'] ?? '');

            if (empty($name) || empty($triggerEvent) || empty($actionType)) {
                echo json_encode(['success' => false, 'error' => 'Workflow Name, Trigger Event, and Action are required']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO `automations` 
                (company_id, name, trigger_event, action_type, wait_minutes, condition_key, condition_value, secondary_action, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ");
            $stmt->execute([
                $companyId,
                $name,
                $triggerEvent,
                $actionType,
                $waitMinutes,
                $conditionKey,
                $conditionValue,
                $secondaryAction
            ]);

            echo json_encode([
                'success' => true,
                'id' => (int)$pdo->lastInsertId(),
                'message' => 'Automation recipe created successfully'
            ]);
            break;

        // 4. Delete automation
        case 'delete':
            $ruleId = (int)($data['id'] ?? ($_GET['id'] ?? 0));
            if ($ruleId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Valid automation ID required']);
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM `automations` WHERE id = ? AND company_id = ?");
            $stmt->execute([$ruleId, $companyId]);

            echo json_encode(['success' => true, 'message' => 'Automation recipe deleted']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action specified']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
