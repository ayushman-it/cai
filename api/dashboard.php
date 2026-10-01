<?php
/**
 * CUBOIDPILOT — WORKSPACE DASHBOARD TELEMETRY (API)
 * Aggregates live leads, Closing Radar, Needs Attention, and Activity Feed.
 * Strictly multi-tenant isolated by logged-in company_id.
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

try {
    $entitlements = getCompanyEntitlements($pdo, $companyId);

    // 1. Core Metrics
    $leadsCountStmt = $pdo->prepare("SELECT COUNT(*) FROM `leads` WHERE `company_id` = ?");
    $leadsCountStmt->execute([$companyId]);
    $totalLeads = (int)$leadsCountStmt->fetchColumn();

    $hotLeadsStmt = $pdo->prepare("SELECT COUNT(*) FROM `leads` WHERE `company_id` = ? AND (`priority` IN ('HIGH', 'URGENT') OR `intent_level` IN ('high', 'urgent')) AND `status` = 'open'");
    $hotLeadsStmt->execute([$companyId]);
    $hotLeads = (int)$hotLeadsStmt->fetchColumn();

    $convsCountStmt = $pdo->prepare("SELECT COUNT(*) FROM `conversations` WHERE `company_id` = ?");
    $convsCountStmt->execute([$companyId]);
    $totalConversations = (int)$convsCountStmt->fetchColumn();

    $pipelineValStmt = $pdo->prepare("SELECT SUM(`opportunity_value`) FROM `leads` WHERE `company_id` = ? AND `status` = 'open'");
    $pipelineValStmt->execute([$companyId]);
    $totalPipelineValue = (int)$pipelineValStmt->fetchColumn();

    $convertedValStmt = $pdo->prepare("SELECT SUM(`opportunity_value`) FROM `leads` WHERE `company_id` = ? AND `status` = 'won'");
    $convertedValStmt->execute([$companyId]);
    $convertedValue = (int)$convertedValStmt->fetchColumn();

    // 2. Needs Attention List (Section 19)
    // Driven by: HIGH/URGENT priority, HUMAN_REQUIRED, HIGH_INTENT inactive, PAYMENT friction
    $attentionStmt = $pdo->prepare("
        SELECT l.*, c.name AS customer_name, c.phone AS customer_phone, c.email AS customer_email,
               u.name AS assigned_user_name, u.avatar_url AS assigned_user_avatar,
               TIMESTAMPDIFF(MINUTE, l.last_activity_at, NOW()) AS inactive_minutes
        FROM `leads` l
        LEFT JOIN `customers` c ON c.id = l.customer_id
        LEFT JOIN `users` u ON u.id = l.assigned_user_id
        WHERE l.company_id = ? AND l.status = 'open'
          AND (
            l.priority IN ('HIGH', 'URGENT')
            OR l.stage_name = 'HUMAN_REQUIRED'
            OR l.is_radar_active = 1
            OR l.is_revenue_at_risk = 1
          )
        ORDER BY 
            CASE 
                WHEN l.priority = 'URGENT' THEN 1
                WHEN l.priority = 'HIGH' THEN 2
                ELSE 3 
            END,
            l.last_activity_at DESC
        LIMIT 6
    ");
    $attentionStmt->execute([$companyId]);
    $needsAttentionLeads = $attentionStmt->fetchAll();

    // 3. Recent Real Activity Feed from lead_events
    $activityStmt = $pdo->prepare("
        SELECT le.*, l.title AS lead_title, c.name AS customer_name
        FROM `lead_events` le
        LEFT JOIN `leads` l ON l.id = le.lead_id
        LEFT JOIN `customers` c ON c.id = le.customer_id
        WHERE le.company_id = ?
        ORDER BY le.id DESC
        LIMIT 10
    ");
    $activityStmt->execute([$companyId]);
    $recentEvents = $activityStmt->fetchAll();

    echo json_encode([
        'success'      => true,
        'entitlements' => $entitlements,
        'metrics'      => [
            'total_leads'          => $totalLeads,
            'hot_leads'            => $hotLeads,
            'total_conversations'  => $totalConversations,
            'pipeline_value_inr'   => $totalPipelineValue,
            'converted_value_inr'  => $convertedValue,
            'is_empty_state'       => ($totalLeads === 0 && $totalConversations === 0)
        ],
        'needs_attention' => array_map(function($row) {
            return [
                'id'                 => (int)$row['id'],
                'title'              => $row['title'] ?: ($row['customer_name'] ?: 'Prospect'),
                'customer_name'      => $row['customer_name'] ?: $row['title'],
                'customer_phone'     => $row['customer_phone'] ?? '',
                'customer_email'     => $row['customer_email'] ?? '',
                'stage'              => $row['stage_name'] ?? 'NEW',
                'priority'           => $row['priority'] ?? 'MEDIUM',
                'opportunity_value'  => (int)$row['opportunity_value'],
                'reason'             => $row['radar_reason'] ?: ($row['ai_summary'] ?: 'High commercial intent'),
                'recommended_action' => $row['radar_recommended_action'] ?: 'Follow up via call or chat',
                'assigned_to'        => $row['assigned_user_name'] ?: 'Unassigned',
                'assigned_user_avatar' => $row['assigned_user_avatar'] ?? null,
                'inactive_minutes'   => (int)($row['inactive_minutes'] ?? 0),
                'last_activity_at'   => $row['last_activity_at']
            ];
        }, $needsAttentionLeads),
        'activity_feed' => array_map(function($ev) {
            return [
                'id'          => (int)$ev['id'],
                'lead_id'     => (int)$ev['lead_id'],
                'event_type'  => $ev['event_type'],
                'description' => $ev['description'],
                'lead_title'  => $ev['lead_title'] ?? $ev['customer_name'],
                'created_at'  => $ev['created_at'],
                'time_ago'    => date('h:i A', strtotime($ev['created_at']))
            ];
        }, $recentEvents)
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Dashboard Error: ' . $e->getMessage()
    ]);
}
