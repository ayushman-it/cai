<?php
/**
 * CUBOIDPILOT — CONFIGURABLE LEAD SCORING & ARTIFACT SYNTHESIZER
 * Evaluates tenant-specific scoring rules, calculates 0-100 numerical lead score,
 * generates explainable reasoning, and manages structured AI Lead Artifacts.
 */

require_once __DIR__ . '/../config/db.php';

/**
 * Calculates lead score and explainable reason based on company-specific rules.
 *
 * @param PDO $pdo
 * @param int $companyId
 * @param array $signals
 * @return array ['score' => int, 'priority' => string, 'reason' => string, 'matched_signals' => array]
 */
function calculateLeadScore(PDO $pdo, int $companyId, array $signals): array {
    // 1. Fetch active company scoring rules
    $stmt = $pdo->prepare("
        SELECT signal_type, condition_value, score_delta, description 
        FROM `lead_scoring_rules` 
        WHERE `company_id` = ? AND `is_active` = 1
    ");
    $stmt->execute([$companyId]);
    $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fallback default rules if company has no custom rules configured
    if (empty($rules)) {
        $rules = [
            ['signal_type' => 'phone_shared', 'condition_value' => null, 'score_delta' => 20, 'description' => 'Contact info provided'],
            ['signal_type' => 'pricing_inquired', 'condition_value' => null, 'score_delta' => 15, 'description' => 'Pricing inquired'],
            ['signal_type' => 'budget_confirmed', 'condition_value' => '50000', 'score_delta' => 25, 'description' => 'Budget confirmed'],
            ['signal_type' => 'timeline_urgent', 'condition_value' => '30', 'score_delta' => 20, 'description' => 'Urgent timeline (<30d)'],
            ['signal_type' => 'whatsapp_continued', 'condition_value' => null, 'score_delta' => 15, 'description' => 'WhatsApp continued'],
            ['signal_type' => 'demo_requested', 'condition_value' => null, 'score_delta' => 20, 'description' => 'Demo requested'],
            ['signal_type' => 'human_requested', 'condition_value' => null, 'score_delta' => 25, 'description' => 'Human counselor requested']
        ];
    }

    $totalScore = 0;
    $reasonParts = [];
    $matched = [];

    // Parse budget
    $rawBudget = $signals['budget'] ?? 0;
    $budgetVal = is_numeric($rawBudget) ? (int)$rawBudget : (int)preg_replace('/[^0-9]/', '', (string)$rawBudget);

    // Parse timeline
    $timelineDays = isset($signals['timeline_days']) ? (int)$signals['timeline_days'] : 999;
    if (isset($signals['timeline'])) {
        $tlStr = strtolower((string)$signals['timeline']);
        if (str_contains($tlStr, 'immediate') || str_contains($tlStr, 'urgent') || str_contains($tlStr, '1 week') || str_contains($tlStr, '2 week')) {
            $timelineDays = 14;
        } elseif (str_contains($tlStr, '30') || str_contains($tlStr, 'month')) {
            $timelineDays = 30;
        }
    }

    foreach ($rules as $rule) {
        $sType = $rule['signal_type'];
        $cVal = $rule['condition_value'];
        $delta = (int)$rule['score_delta'];
        $desc = $rule['description'];

        $applies = false;

        switch ($sType) {
            case 'phone_shared':
                if (!empty($signals['phone_shared']) || !empty($signals['phone']) || !empty($signals['has_phone'])) {
                    $applies = true;
                }
                break;

            case 'email_shared':
                if (!empty($signals['email_shared']) || !empty($signals['email']) || !empty($signals['has_email'])) {
                    $applies = true;
                }
                break;

            case 'pricing_inquired':
                if (!empty($signals['pricing_inquired'])) {
                    $applies = true;
                }
                break;

            case 'budget_confirmed':
                $minBudget = $cVal ? (int)$cVal : 25000;
                if ($budgetVal >= $minBudget || !empty($signals['high_budget']) || !empty($signals['budget_confirmed'])) {
                    $applies = true;
                    $desc = "Budget confirmed (₹" . number_format($budgetVal ?: 50000) . ")";
                }
                break;

            case 'timeline_urgent':
                $maxDays = $cVal ? (int)$cVal : 30;
                if ($timelineDays <= $maxDays) {
                    $applies = true;
                    $desc = "Urgent timeline (≤{$maxDays} days)";
                }
                break;

            case 'whatsapp_continued':
                if (!empty($signals['whatsapp_continued'])) {
                    $applies = true;
                }
                break;

            case 'demo_requested':
                if (!empty($signals['demo_requested'])) {
                    $applies = true;
                }
                break;

            case 'human_requested':
                if (!empty($signals['human_requested'])) {
                    $applies = true;
                }
                break;

            case 'repeat_visit':
                if (($signals['interaction_count'] ?? 1) >= ($cVal ? (int)$cVal : 3)) {
                    $applies = true;
                }
                break;
        }

        if ($applies && !in_array($sType, $matched)) {
            $totalScore += $delta;
            $matched[] = $sType;
            $reasonParts[] = $desc;
        }
    }

    // Clamp score 0 - 100
    $totalScore = max(5, min(100, $totalScore));

    // Determine priority
    if ($totalScore >= 85 || !empty($signals['human_requested'])) {
        $priority = 'URGENT';
    } elseif ($totalScore >= 70) {
        $priority = 'HIGH';
    } elseif ($totalScore >= 40) {
        $priority = 'MEDIUM';
    } else {
        $priority = 'LOW';
    }

    $reason = !empty($reasonParts) ? implode(" + ", $reasonParts) : "General website engagement";

    return [
        'score'           => $totalScore,
        'priority'        => $priority,
        'reason'          => $reason,
        'matched_signals' => $matched
    ];
}

/**
 * Creates or updates the structured AI Lead Artifact and aligns the leads table.
 *
 * @param PDO $pdo
 * @param int $companyId
 * @param int $leadId
 * @param array $artifactData
 * @return array Full persisted artifact record
 */
function syncLeadArtifact(PDO $pdo, int $companyId, int $leadId, array $artifactData): array {
    // 1. Fetch current lead
    $stmt = $pdo->prepare("SELECT * FROM `leads` WHERE `id` = ? AND `company_id` = ? LIMIT 1");
    $stmt->execute([$leadId, $companyId]);
    $lead = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lead) {
        return ['success' => false, 'error' => 'Lead not found'];
    }

    $customerId = (int)$lead['customer_id'];
    $conversationId = !empty($artifactData['conversation_id']) ? (int)$artifactData['conversation_id'] : ($lead['conversation_id'] ? (int)$lead['conversation_id'] : null);

    // Fetch customer details
    $cStmt = $pdo->prepare("SELECT * FROM `customers` WHERE `id` = ? AND `company_id` = ? LIMIT 1");
    $cStmt->execute([$customerId, $companyId]);
    $customer = $cStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Extract fields
    $customerName = trim($artifactData['customer_name'] ?? $customer['name'] ?? $lead['title']);
    $phone = trim($artifactData['phone'] ?? $customer['phone'] ?? $customer['whatsapp_number'] ?? '');
    $email = trim($artifactData['email'] ?? $customer['email'] ?? '');
    $companyName = trim($artifactData['company_name'] ?? '');
    $requirement = trim($artifactData['requirement'] ?? '');
    $interestedService = trim($artifactData['interested_service'] ?? ($artifactData['interest'] ?? ''));
    $budget = trim($artifactData['budget'] ?? '');
    $timeline = trim($artifactData['timeline'] ?? '');
    $location = trim($artifactData['location'] ?? $customer['city'] ?? '');
    $intent = trim($artifactData['intent'] ?? 'commercial');
    $currentStage = trim($artifactData['current_stage'] ?? ($artifactData['stage'] ?? $lead['stage_name']));
    $summary = trim($artifactData['conversation_summary'] ?? ($artifactData['summary'] ?? $lead['ai_summary']));
    $recommendedAction = trim($artifactData['recommended_action'] ?? $lead['radar_recommended_action']);
    $assignedSalespersonId = !empty($artifactData['assigned_salesperson_id']) ? (int)$artifactData['assigned_salesperson_id'] : ($lead['assigned_user_id'] ? (int)$lead['assigned_user_id'] : null);
    $preferredChannel = trim($artifactData['preferred_channel'] ?? 'website');
    $aiConfidence = trim($artifactData['ai_confidence'] ?? 'HIGH');
    $humanAttentionStatus = !empty($artifactData['human_attention_required']) ? 'attention_required' : (!empty($lead['human_attention_required']) ? 'attention_required' : 'none');

    // Buying signals & objections arrays
    $buyingSignals = is_array($artifactData['buying_signals'] ?? null) 
        ? $artifactData['buying_signals'] 
        : (array)($artifactData['buying_signals_json'] ?? []);

    $objections = is_array($artifactData['objections'] ?? null) 
        ? $artifactData['objections'] 
        : (array)($artifactData['objections_json'] ?? []);

    $missingInfo = is_array($artifactData['missing_information'] ?? null) 
        ? $artifactData['missing_information'] 
        : (array)($artifactData['missing_information_json'] ?? []);

    // If missing info not explicitly passed, infer it
    if (empty($missingInfo)) {
        if (empty($phone)) $missingInfo[] = 'phone';
        if (empty($budget)) $missingInfo[] = 'budget';
        if (empty($timeline)) $missingInfo[] = 'timeline';
        if (empty($interestedService)) $missingInfo[] = 'interest';
    }

    // 2. Lead scoring calculation
    $scoringSignals = [
        'phone_shared'       => !empty($phone),
        'email_shared'       => !empty($email),
        'pricing_inquired'   => in_array('pricing_inquired', $buyingSignals) || str_contains(strtolower($summary), 'pricing') || str_contains(strtolower($summary), 'fee'),
        'budget'             => $budget ?: (int)$lead['opportunity_value'],
        'timeline'           => $timeline,
        'whatsapp_continued' => in_array('whatsapp_continued', $buyingSignals) || $preferredChannel === 'whatsapp',
        'demo_requested'     => in_array('demo_requested', $buyingSignals),
        'human_requested'    => !empty($artifactData['human_required']) || $humanAttentionStatus === 'attention_required',
    ];

    $scoreResult = calculateLeadScore($pdo, $companyId, $scoringSignals);
    $leadScore = $scoreResult['score'];
    $priority = $scoreResult['priority'];
    $priorityReason = $scoreResult['reason'];

    // 3. Upsert into lead_artifacts table
    $chkArt = $pdo->prepare("SELECT id FROM `lead_artifacts` WHERE `lead_id` = ? AND `company_id` = ? LIMIT 1");
    $chkArt->execute([$leadId, $companyId]);
    $artId = $chkArt->fetchColumn();

    if ($artId) {
        $pdo->prepare("
            UPDATE `lead_artifacts`
            SET `customer_name` = ?,
                `phone` = ?,
                `email` = ?,
                `company_name` = ?,
                `requirement` = ?,
                `interested_service` = ?,
                `budget` = ?,
                `timeline` = ?,
                `location` = ?,
                `intent` = ?,
                `current_stage` = ?,
                `buying_signals_json` = ?,
                `objections_json` = ?,
                `missing_information_json` = ?,
                `conversation_summary` = ?,
                `priority` = ?,
                `lead_score` = ?,
                `priority_reason` = ?,
                `recommended_action` = ?,
                `assigned_salesperson_id` = ?,
                `preferred_channel` = ?,
                `ai_confidence` = ?,
                `human_attention_status` = ?,
                `updated_at` = NOW()
            WHERE `id` = ? AND `company_id` = ?
        ")->execute([
            $customerName, $phone, $email, $companyName, $requirement, $interestedService,
            $budget, $timeline, $location, $intent, $currentStage,
            json_encode($buyingSignals, JSON_UNESCAPED_UNICODE),
            json_encode($objections, JSON_UNESCAPED_UNICODE),
            json_encode($missingInfo, JSON_UNESCAPED_UNICODE),
            $summary, $priority, $leadScore, $priorityReason, $recommendedAction,
            $assignedSalespersonId, $preferredChannel, $aiConfidence, $humanAttentionStatus,
            $artId, $companyId
        ]);
    } else {
        $pdo->prepare("
            INSERT INTO `lead_artifacts`
            (`company_id`, `lead_id`, `customer_id`, `conversation_id`, `customer_name`, `phone`, `email`, `company_name`,
             `requirement`, `interested_service`, `budget`, `timeline`, `location`, `intent`, `current_stage`,
             `buying_signals_json`, `objections_json`, `missing_information_json`, `conversation_summary`, `priority`,
             `lead_score`, `priority_reason`, `recommended_action`, `assigned_salesperson_id`, `preferred_channel`,
             `ai_confidence`, `human_attention_status`, `created_at`, `updated_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ")->execute([
            $companyId, $leadId, $customerId, $conversationId, $customerName, $phone, $email, $companyName,
            $requirement, $interestedService, $budget, $timeline, $location, $intent, $currentStage,
            json_encode($buyingSignals, JSON_UNESCAPED_UNICODE),
            json_encode($objections, JSON_UNESCAPED_UNICODE),
            json_encode($missingInfo, JSON_UNESCAPED_UNICODE),
            $summary, $priority, $leadScore, $priorityReason, $recommendedAction,
            $assignedSalespersonId, $preferredChannel, $aiConfidence, $humanAttentionStatus
        ]);
        $artId = (int)$pdo->lastInsertId();
    }

    // 4. Align leads table
    $isRadarActive = ($priority === 'HIGH' || $priority === 'URGENT') ? 1 : 0;
    $humanAttn = ($humanAttentionStatus === 'attention_required') ? 1 : 0;

    $finalTitle = !empty($customerName) ? $customerName : ($lead['title'] ?? ('Lead #' . $leadId));

    $pdo->prepare("
        UPDATE `leads`
        SET `title` = ?,
            `priority` = ?,
            `intent_level` = ?,
            `stage_name` = ?,
            `ai_summary` = ?,
            `radar_reason` = ?,
            `radar_recommended_action` = ?,
            `is_radar_active` = ?,
            `human_attention_required` = ?,
            `human_attention_reason` = ?,
            `assigned_user_id` = COALESCE(?, `assigned_user_id`),
            `last_activity_at` = NOW(),
            `updated_at` = NOW()
        WHERE `id` = ? AND `company_id` = ?
    ")->execute([
        $finalTitle,
        $priority,
        strtolower($priority),
        $currentStage,
        $summary,
        $priorityReason,
        $recommendedAction,
        $isRadarActive,
        $humanAttn,
        $priorityReason,
        $assignedSalespersonId,
        $leadId,
        $companyId
    ]);

    // 5. Automatic Sales Assignment (Round-Robin) if unassigned and qualified/high priority
    if (!$assignedSalespersonId && ($priority === 'HIGH' || $priority === 'URGENT' || in_array($currentStage, ['QUALIFIED', 'PROPOSAL']))) {
        assignLeadRoundRobin($pdo, $companyId, $leadId);
    } elseif ($assignedSalespersonId && ($priority === 'HIGH' || $priority === 'URGENT' || $humanAttentionStatus === 'attention_required')) {
        // Lead is already assigned, but escalated to HIGH/URGENT priority or requested human counselor!
        require_once __DIR__ . '/alerts.php';
        $alertType = ($humanAttentionStatus === 'attention_required') ? 'HUMAN_REQUIRED' : 'HIGH_INTENT_DETECTED';
        sendSalespersonAssignmentAlert($pdo, $companyId, $leadId, null, $alertType);
    }

    return [
        'id'                     => (int)$artId,
        'company_id'             => $companyId,
        'lead_id'                => $leadId,
        'customer_name'          => $customerName,
        'phone'                  => $phone,
        'email'                  => $email,
        'requirement'            => $requirement,
        'interested_service'     => $interestedService,
        'budget'                 => $budget,
        'timeline'               => $timeline,
        'location'               => $location,
        'intent'                 => $intent,
        'current_stage'          => $currentStage,
        'buying_signals'         => $buyingSignals,
        'objections'             => $objections,
        'missing_information'    => $missingInfo,
        'conversation_summary'   => $summary,
        'priority'               => $priority,
        'lead_score'             => $leadScore,
        'priority_reason'        => $priorityReason,
        'recommended_action'     => $recommendedAction,
        'human_attention_status' => $humanAttentionStatus
    ];
}

/**
 * Assigns a lead via Round-Robin to active company sales agents, logs event, and triggers WhatsApp alert brief.
 *
 * @param PDO $pdo
 * @param int $companyId
 * @param int $leadId
 * @return array|null Assigned user row
 */
function assignLeadRoundRobin(PDO $pdo, int $companyId, int $leadId): ?array {
    // Fetch active sales team members
    $stmt = $pdo->prepare("
        SELECT id, name, email, phone, role 
        FROM `users` 
        WHERE `company_id` = ? AND `is_active` = 1 AND `role` IN ('sales_agent', 'manager', 'owner')
        ORDER BY id ASC
    ");
    $stmt->execute([$companyId]);
    $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($agents)) {
        return null;
    }

    // Determine next agent by checking last assigned lead
    $lastStmt = $pdo->prepare("
        SELECT assigned_user_id 
        FROM `leads` 
        WHERE `company_id` = ? AND `assigned_user_id` IS NOT NULL 
        ORDER BY updated_at DESC LIMIT 1
    ");
    $lastStmt->execute([$companyId]);
    $lastAssignedId = (int)$lastStmt->fetchColumn();

    $assignedAgent = $agents[0];
    if ($lastAssignedId) {
        $found = false;
        foreach ($agents as $idx => $ag) {
            if ((int)$ag['id'] === $lastAssignedId) {
                $nextIdx = ($idx + 1) % count($agents);
                $assignedAgent = $agents[$nextIdx];
                $found = true;
                break;
            }
        }
    }

    $assignedId = (int)$assignedAgent['id'];

    // Update lead & conversation
    $pdo->prepare("UPDATE `leads` SET `assigned_user_id` = ?, `updated_at` = NOW() WHERE `id` = ? AND `company_id` = ?")
        ->execute([$assignedId, $leadId, $companyId]);

    $pdo->prepare("
        UPDATE `conversations` 
        SET `assigned_user_id` = ? 
        WHERE `company_id` = ? AND (`id` = (SELECT conversation_id FROM `leads` WHERE id = ?) OR `customer_id` = (SELECT customer_id FROM `leads` WHERE id = ?))
    ")->execute([$assignedId, $companyId, $leadId, $leadId]);

    // Update lead_artifacts
    $pdo->prepare("UPDATE `lead_artifacts` SET `assigned_salesperson_id` = ? WHERE `lead_id` = ? AND `company_id` = ?")
        ->execute([$assignedId, $leadId, $companyId]);

    // Log timeline event
    $pdo->prepare("
        INSERT INTO `lead_events` (`company_id`, `lead_id`, `event_type`, `description`, `event_data_json`, `created_at`)
        VALUES (?, ?, 'HUMAN_TAKEOVER', ?, ?, NOW())
    ")->execute([
        $companyId,
        $leadId,
        "Auto-assigned to {$assignedAgent['name']} ({$assignedAgent['role']}) via Round-Robin",
        json_encode(['assigned_user_id' => $assignedId, 'agent_name' => $assignedAgent['name']])
    ]);

    // Log audit log
    $pdo->prepare("
        INSERT INTO `audit_logs` (`company_id`, `user_id`, `action`, `target_type`, `target_id`, `details_json`, `created_at`)
        VALUES (?, ?, 'lead.assigned', 'lead', ?, ?, NOW())
    ")->execute([
        $companyId,
        $assignedId,
        $leadId,
        json_encode(['method' => 'round_robin', 'agent' => $assignedAgent['name']])
    ]);

    // Send WhatsApp Lead Brief Notification to Assigned Rep
    require_once __DIR__ . '/alerts.php';
    sendSalespersonAssignmentAlert($pdo, $companyId, $leadId, $assignedAgent);

    return $assignedAgent;
}
