<?php
/**
 * CUBOIDPILOT — SALES PIPELINE & KANBAN API
 * Strictly multi-tenant isolated. Serves pipeline stages, grouped leads,
 * opportunity valuations, and drag-and-drop / stage update actions.
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
$jsonData = json_decode($rawInput, true);
$data = array_merge($_GET, $_POST, is_array($jsonData) ? $jsonData : []);

try {
    switch ($action) {
        // 1. Get Pipeline Stages & Grouped Leads
        case 'list':
            // Fetch company pipeline stages
            $stagesStmt = $pdo->prepare("
                SELECT id, name, slug, stage_order, color_code, is_default 
                FROM `pipeline_stages` 
                WHERE `company_id` = ? 
                ORDER BY `stage_order` ASC
            ");
            $stagesStmt->execute([$companyId]);
            $stages = $stagesStmt->fetchAll(PDO::FETCH_ASSOC);

            // If company has no custom stages, provision defaults
            if (empty($stages)) {
                $defaultStages = [
                    ['New Inquiry', 'new', 1, '#4F46E5'],
                    ['Contacted', 'contacted', 2, '#0EA5E9'],
                    ['Qualified', 'qualified', 3, '#F59E0B'],
                    ['Proposal / Demo', 'proposal', 4, '#8B5CF6'],
                    ['Won / Enrolled', 'won', 5, '#10B981'],
                    ['Lost', 'lost', 6, '#EF4444'],
                ];
                $insStg = $pdo->prepare("
                    INSERT INTO `pipeline_stages` (`company_id`, `name`, `slug`, `stage_order`, `color_code`, `is_default`)
                    VALUES (?, ?, ?, ?, ?, 1)
                ");
                foreach ($defaultStages as $s) {
                    $insStg->execute([$companyId, $s[0], $s[1], $s[2], $s[3]]);
                }
                $stagesStmt->execute([$companyId]);
                $stages = $stagesStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Fetch all open leads for this company
            $leadsStmt = $pdo->prepare("
                SELECT l.id, l.title, l.customer_id, l.conversation_id, l.stage_id, l.stage_name,
                       l.intent_level, l.priority, l.opportunity_value, l.total_amount, l.paid_amount,
                       l.source, l.status, l.ai_summary, l.radar_reason, l.radar_recommended_action,
                       l.last_activity_at, l.created_at,
                       c.name AS customer_name, c.phone AS customer_phone, c.email AS customer_email,
                       u.name AS assigned_user_name, u.avatar_url AS assigned_user_avatar
                FROM `leads` l
                LEFT JOIN `customers` c ON c.id = l.customer_id
                LEFT JOIN `users` u ON u.id = l.assigned_user_id
                WHERE l.company_id = ? AND (l.status = 'open' OR l.status = 'won')
                ORDER BY l.last_activity_at DESC
            ");
            $leadsStmt->execute([$companyId]);
            $allLeads = $leadsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Group leads by stage
            $stageMap = [];
            $totalPipelineValue = 0;
            $totalLeadsCount = count($allLeads);

            foreach ($stages as $stg) {
                $stgId = (int)$stg['id'];
                $stageMap[$stgId] = [
                    'id'          => $stgId,
                    'name'        => $stg['name'],
                    'slug'        => $stg['slug'],
                    'stage_order' => (int)$stg['stage_order'],
                    'color_code'  => $stg['color_code'] ?: '#4F46E5',
                    'leads'       => [],
                    'total_value' => 0,
                    'count'       => 0
                ];
            }

            $firstStageId = !empty($stages) ? (int)$stages[0]['id'] : null;

            foreach ($allLeads as $lead) {
                $val = (int)($lead['opportunity_value'] ?: ($lead['total_amount'] ?: 0));
                $totalPipelineValue += $val;

                $targetStageId = (int)$lead['stage_id'];
                if (!$targetStageId || !isset($stageMap[$targetStageId])) {
                    $matched = false;
                    $leadStageUpper = strtoupper($lead['stage_name'] ?? '');
                    foreach ($stageMap as $sid => $sData) {
                        if (strtoupper($sData['slug']) === $leadStageUpper || strtoupper($sData['name']) === $leadStageUpper) {
                            $targetStageId = $sid;
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) {
                        $targetStageId = $firstStageId;
                    }
                }

                $leadObj = [
                    'id'                 => (int)$lead['id'],
                    'title'              => $lead['title'] ?: ($lead['customer_name'] ?: 'Prospect'),
                    'customer_name'      => $lead['customer_name'] ?: $lead['title'],
                    'customer_phone'     => $lead['customer_phone'] ?? '',
                    'customer_email'     => $lead['customer_email'] ?? '',
                    'stage_name'         => $lead['stage_name'],
                    'stage_id'           => $targetStageId,
                    'priority'           => $lead['priority'] ?? 'MEDIUM',
                    'intent_level'       => $lead['intent_level'] ?? 'medium',
                    'opportunity_value'  => $val,
                    'source'             => $lead['source'] ?: 'Website',
                    'ai_summary'         => $lead['ai_summary'] ?: ($lead['radar_reason'] ?: ''),
                    'recommended_action' => $lead['radar_recommended_action'] ?: 'Follow up with prospect',
                    'assigned_user_name' => $lead['assigned_user_name'] ?: 'Unassigned',
                    'assigned_user_avatar' => $lead['assigned_user_avatar'] ?? null,
                    'last_activity_at'   => $lead['last_activity_at'],
                    'created_at'         => $lead['created_at']
                ];

                if (isset($stageMap[$targetStageId])) {
                    $stageMap[$targetStageId]['leads'][] = $leadObj;
                    $stageMap[$targetStageId]['total_value'] += $val;
                    $stageMap[$targetStageId]['count']++;
                }
            }

            echo json_encode([
                'success'              => true,
                'total_pipeline_value' => $totalPipelineValue,
                'total_leads_count'    => $totalLeadsCount,
                'stages'               => array_values($stageMap)
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        // 2. Move Lead to New Stage
        case 'update_stage':
            $leadId = (int)($data['lead_id'] ?? 0);
            $stageId = !empty($data['stage_id']) ? (int)$data['stage_id'] : null;
            $stageName = trim($data['stage_name'] ?? '');

            if (!$leadId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'lead_id is required']);
                exit;
            }

            // Verify lead ownership
            $lStmt = $pdo->prepare("SELECT id, stage_id, stage_name, title, customer_id FROM `leads` WHERE id = ? AND company_id = ? LIMIT 1");
            $lStmt->execute([$leadId, $companyId]);
            $lead = $lStmt->fetch();

            if (!$lead) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Lead not found in this workspace']);
                exit;
            }

            // Resolve stage
            if ($stageId) {
                $sCheck = $pdo->prepare("SELECT id, name FROM `pipeline_stages` WHERE id = ? AND company_id = ? LIMIT 1");
                $sCheck->execute([$stageId, $companyId]);
                $stgRow = $sCheck->fetch();
                if ($stgRow) {
                    $stageName = $stgRow['name'];
                }
            } elseif (!empty($stageName)) {
                $sCheck = $pdo->prepare("SELECT id, name FROM `pipeline_stages` WHERE company_id = ? AND (UPPER(name) = ? OR UPPER(slug) = ?) LIMIT 1");
                $sCheck->execute([$companyId, strtoupper($stageName), strtoupper($stageName)]);
                $stgRow = $sCheck->fetch();
                if ($stgRow) {
                    $stageId = (int)$stgRow['id'];
                    $stageName = $stgRow['name'];
                }
            }

            $pdo->prepare("
                UPDATE `leads` 
                SET `stage_id` = ?, `stage_name` = ?, `last_activity_at` = NOW(), `updated_at` = NOW() 
                WHERE `id` = ? AND `company_id` = ?
            ")->execute([$stageId, $stageName, $leadId, $companyId]);

            // Log timeline activity
            $pdo->prepare("
                INSERT INTO `lead_events`
                (`company_id`, `lead_id`, `customer_id`, `event_type`, `description`, `created_at`)
                VALUES (?, ?, ?, 'STAGE_CHANGED', ?, NOW())
            ")->execute([
                $companyId,
                $leadId,
                $lead['customer_id'],
                "Pipeline stage moved to {$stageName}"
            ]);

            echo json_encode([
                'success'    => true,
                'lead_id'    => $leadId,
                'stage_id'   => $stageId,
                'stage_name' => $stageName
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unsupported action']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
