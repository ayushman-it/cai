<?php
/**
 * CUBOIDPILOT — CONVERSATION & REVENUE ANALYTICS API
 * Strictly multi-tenant isolated.
 * Aggregates real operational telemetry, conversion rates, and pipeline attributions.
 */

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/entitlements.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = getDbConnection();

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$companyId = (int)($_SESSION['company_id'] ?? 0);

if ($companyId === 0) {
    $uStmt = $pdo->prepare("SELECT company_id, is_super_admin FROM users WHERE id = ? LIMIT 1");
    $uStmt->execute([$userId]);
    $u = $uStmt->fetch();
    if (!empty($u['company_id'])) {
        $companyId = (int)$u['company_id'];
        $_SESSION['company_id'] = $companyId;
    } elseif (!empty($u['is_super_admin'])) {
        $firstComp = $pdo->query("SELECT id FROM companies ORDER BY id ASC LIMIT 1")->fetchColumn();
        if ($firstComp) {
            $companyId = (int)$firstComp;
            $_SESSION['company_id'] = $companyId;
        }
    }
}

if ($companyId === 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Workspace context not found']);
    exit;
}

session_write_close();

try {
    // 0. Dataset Export to CSV
    if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="cuboidpilot_conversations_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Customer Name', 'Channel', 'Status', 'Ownership', 'Last Message', 'Created At']);
        
        $cStmt = $pdo->prepare("
            SELECT conv.id, c.name as customer_name, conv.channel, conv.status, conv.ownership, conv.last_message_preview, conv.created_at
            FROM `conversations` conv
            LEFT JOIN `customers` c ON c.id = conv.customer_id
            WHERE conv.company_id = ?
            ORDER BY conv.created_at DESC
        ");
        $cStmt->execute([$companyId]);
        while ($row = $cStmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [
                $row['id'],
                $row['customer_name'] ?: 'Prospect',
                $row['channel'],
                $row['status'],
                $row['ownership'],
                $row['last_message_preview'],
                $row['created_at']
            ]);
        }
        fclose($out);
        exit;
    }

    // 1. Total Conversations & Channel Split
    $convStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN channel = 'widget' THEN 1 ELSE 0 END) as widget_count,
            SUM(CASE WHEN channel = 'whatsapp' THEN 1 ELSE 0 END) as whatsapp_count,
            SUM(CASE WHEN ownership = 'ai' AND status != 'human_requested' THEN 1 ELSE 0 END) as ai_resolved,
            SUM(CASE WHEN ownership = 'human' OR status IN ('human_requested', 'human_active') THEN 1 ELSE 0 END) as human_handoff,
            SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as closed_count,
            SUM(CASE WHEN status != 'resolved' THEN 1 ELSE 0 END) as open_count,
            SUM(CASE WHEN status IN ('human_active', 'human_requested') THEN 1 ELSE 0 END) as replied_count
        FROM `conversations`
        WHERE company_id = ?
    ");
    $convStmt->execute([$companyId]);
    $convData = $convStmt->fetch(PDO::FETCH_ASSOC);

    $totalConvs   = (int)($convData['total'] ?? 0);
    $widgetConvs  = (int)($convData['widget_count'] ?? 0);
    $whatsappConvs= (int)($convData['whatsapp_count'] ?? 0);
    $aiResolved   = (int)($convData['ai_resolved'] ?? 0);
    $humanHandoff = (int)($convData['human_handoff'] ?? 0);
    $closedCount  = (int)($convData['closed_count'] ?? 0);
    $openCount    = (int)($convData['open_count'] ?? 0);
    $repliedCount = (int)($convData['replied_count'] ?? 0);

    // Total replies sent (messages from human or AI)
    $replyStmt = $pdo->prepare("
        SELECT COUNT(*) FROM `messages`
        WHERE company_id = ? AND sender_type IN ('human', 'ai')
    ");
    $replyStmt->execute([$companyId]);
    $repliesSent = (int)$replyStmt->fetchColumn();

    $reopenedCount = 0;
    $snoozedCount  = 0;

    // 2. Leads & Conversion Metrics
    $leadStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_leads,
            SUM(CASE WHEN priority IN ('HIGH', 'URGENT') OR UPPER(stage_name) IN ('QUALIFIED', 'HIGH_INTENT', 'PROPOSAL', 'PROPOSAL / DEMO', 'WON', 'WON / ENROLLED') OR intent_level IN ('high', 'urgent') THEN 1 ELSE 0 END) as qualified_leads,
            SUM(CASE WHEN status = 'won' OR UPPER(stage_name) LIKE '%WON%' OR UPPER(stage_name) LIKE '%ENROLL%' THEN 1 ELSE 0 END) as won_leads,
            SUM(opportunity_value) as pipeline_value,
            SUM(CASE WHEN status = 'won' THEN opportunity_value ELSE 0 END) as converted_value
        FROM `leads`
        WHERE company_id = ?
    ");
    $leadStmt->execute([$companyId]);
    $leadData = $leadStmt->fetch(PDO::FETCH_ASSOC);

    $totalLeads     = (int)($leadData['total_leads'] ?? 0);
    $qualifiedLeads = (int)($leadData['qualified_leads'] ?? 0);
    $wonLeads       = (int)($leadData['won_leads'] ?? 0);
    $pipelineVal    = (int)($leadData['pipeline_value'] ?? 0);
    $convertedVal   = (int)($leadData['converted_value'] ?? 0);

    // Percentages
    $qualificationRate = $totalConvs > 0 ? round(($qualifiedLeads / $totalConvs) * 100, 1) : 0;
    $aiResolutionRate  = $totalConvs > 0 ? round(($aiResolved / $totalConvs) * 100, 1) : 0;
    $humanHandoffRate  = $totalConvs > 0 ? round(($humanHandoff / $totalConvs) * 100, 1) : 0;
    $conversionRate    = $totalLeads > 0 ? round(($wonLeads / $totalLeads) * 100, 1) : 0;

    // 3. Top Inquiries / Topics
    $intentStmt = $pdo->prepare("
        SELECT detected_intent, COUNT(*) as count 
        FROM `messages` 
        WHERE company_id = ? AND sender_type = 'visitor' AND detected_intent IS NOT NULL AND detected_intent != ''
        GROUP BY detected_intent 
        ORDER BY count DESC 
        LIMIT 5
    ");
    $intentStmt->execute([$companyId]);
    $topIntents = $intentStmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Time Series for Bar Chart
    $bStmt = $pdo->prepare("
        SELECT DATE(created_at) as dt, COUNT(*) as cnt
        FROM `conversations`
        WHERE company_id = ?
        GROUP BY DATE(created_at)
        ORDER BY dt ASC
    ");
    $bStmt->execute([$companyId]);
    $dateCounts = $bStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $today = date('Y-m-d');
    $d1 = date('Y-m-d', strtotime('-28 days'));
    $d2 = date('Y-m-d', strtotime('-21 days'));
    $d3 = date('Y-m-d', strtotime('-14 days'));
    $d4 = date('Y-m-d', strtotime('-7 days'));
    $d5 = $today;

    $c1 = (int)($dateCounts[$d1] ?? 0);
    $c2 = (int)($dateCounts[$d2] ?? 0);
    $c3 = (int)($dateCounts[$d3] ?? 0);
    $c4 = (int)($dateCounts[$d4] ?? 0);
    $c5 = (int)($dateCounts[$d5] ?? 0);

    $timeSeries = [
        ['label' => date('M j', strtotime($d1)), 'count' => $c1],
        ['label' => date('M j', strtotime($d2)), 'count' => $c2],
        ['label' => date('M j', strtotime($d3)), 'count' => $c3],
        ['label' => date('M j', strtotime($d4)), 'count' => $c4],
        ['label' => date('M j', strtotime($d5)), 'count' => $c5]
    ];
    $maxCount = max(2, $c1, $c2, $c3, $c4, $c5);
    if ($maxCount % 2 !== 0) $maxCount++;

    echo json_encode([
        'success' => true,
        'cards'   => [
            'conversations' => $totalConvs,
            'replied'       => $repliedCount,
            'replies_sent'  => $repliesSent,
            'closed'        => $closedCount,
            'reopened'      => $reopenedCount,
            'open'          => $openCount,
            'snoozed'       => $snoozedCount,
            'qualified'     => $qualifiedLeads
        ],
        'chart'   => [
            'title'     => 'New conversations — by time',
            'intervals' => $timeSeries,
            'max_y'     => $maxCount,
            'color'     => '#111111'
        ],
        'metrics' => [
            'total_conversations'      => $totalConvs,
            'leads_captured'           => $totalLeads,
            'qualified_leads'          => $qualifiedLeads,
            'qualification_rate_pct'   => $qualificationRate,
            'ai_resolved_count'        => $aiResolved,
            'ai_resolution_pct'        => $aiResolutionRate,
            'human_handoff_count'      => $humanHandoff,
            'human_handoff_pct'        => $humanHandoffRate,
            'won_leads_count'          => $wonLeads,
            'conversion_rate_pct'      => $conversionRate,
            'pipeline_attributed_inr'  => $pipelineVal,
            'converted_revenue_inr'    => $convertedVal,
            'channels' => [
                'widget'   => $widgetConvs,
                'whatsapp' => $whatsappConvs
            ],
            'top_intents' => $topIntents
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
