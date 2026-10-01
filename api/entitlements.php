<?php
/**
 * CUBOIDPILOT — CENTRALIZED PLAN CAPABILITIES & ENTITLEMENTS (Section 20 & 46)
 * Single source of truth for free trial vs. premium capabilities.
 * Enforces multi-tenant feature gating across the entire application.
 */

require_once __DIR__ . '/../config/db.php';

/**
 * Get comprehensive entitlement profile for a tenant workspace.
 * 
 * @param PDO $pdo
 * @param int $companyId
 * @return array
 */
function getCompanyEntitlements(PDO $pdo, int $companyId): array {
    $stmt = $pdo->prepare("
        SELECT c.*, s.status AS sub_status, s.plan_id AS sub_plan_id, s.current_period_end, p.code AS plan_code, p.name AS plan_name
        FROM `companies` c
        LEFT JOIN `subscriptions` s ON s.company_id = c.id
        LEFT JOIN `plans` p ON p.id = COALESCE(s.plan_id, c.plan_id)
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch();

    if (!$company) {
        return [
            'valid' => false,
            'error' => 'Company not found'
        ];
    }

    $now = time();
    $trialEndsAt = !empty($company['trial_ends_at']) ? strtotime($company['trial_ends_at']) : ($now + 14 * 86400);
    $isTrialActive = ($company['status'] === 'trial' || empty($company['status'])) && ($trialEndsAt >= $now);
    $isTrialExpired = ($company['status'] === 'trial') && ($trialEndsAt < $now);

    $isSubscriptionActive = (!empty($company['sub_status']) && $company['sub_status'] === 'active') ||
                           ($company['status'] === 'active' && !empty($company['plan_id'])) ||
                           (in_array($company['plan_tier'], ['starter', 'growth', 'pro', 'premium']));

    $trialDaysRemaining = $isTrialActive ? max(0, (int)ceil(($trialEndsAt - $now) / 86400)) : 0;

    // Determine effective plan tier
    if ($isSubscriptionActive) {
        $effectiveTier = !empty($company['plan_code']) ? $company['plan_code'] : 'premium';
        $statusLabel = 'ACTIVE';
        $isPremium = true;
    } elseif ($isTrialActive) {
        $effectiveTier = 'free_trial';
        $statusLabel = 'TRIALING';
        $isPremium = false;
    } elseif ($isTrialExpired) {
        $effectiveTier = 'expired_trial';
        $statusLabel = 'EXPIRED';
        $isPremium = false;
    } else {
        $effectiveTier = 'free_trial';
        $statusLabel = strtoupper($company['status'] ?: 'TRIALING');
        $isPremium = false;
    }

    return [
        'valid'                  => true,
        'company_id'             => (int)$company['id'],
        'company_name'           => $company['name'],
        'company_key'            => $company['company_key'],
        'status'                 => $statusLabel,
        'effective_tier'         => $effectiveTier,
        'is_premium'             => $isPremium,
        'is_trial'               => $isTrialActive,
        'trial_days_remaining'   => $trialDaysRemaining,
        'trial_ends_at'          => date('Y-m-d H:i:s', $trialEndsAt),
        'whatsapp_connected'     => (bool)($company['whatsapp_connected'] ?? 0),
        
        // Capabilities (Sections 11 & 20)
        'capabilities' => [
            // Core free / trial features
            'can_use_ai_assistant'          => true,
            'can_capture_leads'             => true,
            'can_use_crm'                   => true,
            'can_view_conversations'        => true,
            'can_use_lead_qualification'    => true,
            'can_use_lead_priority'         => true,
            'can_use_basic_pipeline'        => true,
            'can_use_basic_analytics'       => true,

            // Premium workflow features
            'can_use_whatsapp'              => $isPremium,
            'can_use_whatsapp_continuation' => $isPremium,
            'can_create_team'               => $isPremium,
            'can_assign_leads'              => $isPremium,
            'can_receive_whatsapp_alerts'   => $isPremium,
            'can_use_automations'           => $isPremium,
            'can_use_payment_reminders'     => $isPremium,
        ]
    ];
}

/**
 * Checks if a company has entitlement for a capability.
 * Exits with 403 JSON if not permitted when $exitOnError is true.
 * 
 * @param PDO $pdo
 * @param int $companyId
 * @param string $capability
 * @param bool $exitOnError
 * @return bool
 */
function checkEntitlement(PDO $pdo, int $companyId, string $capability, bool $exitOnError = false): bool {
    $entitlements = getCompanyEntitlements($pdo, $companyId);
    $allowed = !empty($entitlements['capabilities'][$capability]);

    if (!$allowed && $exitOnError) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success'     => false,
            'error'       => 'Feature Upgrade Required',
            'message'     => "The capability '{$capability}' requires an active CuboidPilot Premium subscription.",
            'upgrade_url' => 'billing.html',
            'capability'  => $capability
        ]);
        exit;
    }

    return $allowed;
}
