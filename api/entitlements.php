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
        SELECT c.*, s.status AS sub_status, s.plan_id AS sub_plan_id, s.current_period_end,
               p.code AS plan_code, p.name AS plan_name, p.price_monthly_inr
        FROM `companies` c
        LEFT JOIN (
            SELECT s1.*
            FROM `subscriptions` s1
            INNER JOIN (
                SELECT company_id, MAX(id) AS id
                FROM `subscriptions`
                GROUP BY company_id
            ) latest ON latest.id = s1.id
        ) s ON s.company_id = c.id
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
    $status = strtolower(trim((string)($company['status'] ?? 'trial')));
    $tier = strtolower(trim((string)($company['plan_tier'] ?? 'free_trial')));
    $trialEndsAt = !empty($company['trial_ends_at']) ? strtotime($company['trial_ends_at']) : ($now + 14 * 86400);
    $manualProOverride = !empty($company['manual_pro_override']);
    $isSuspended = in_array($status, ['suspended', 'expired', 'cancelled', 'past_due'], true);
    $isTrialActive = (($status === 'trial' || empty($status)) && ($trialEndsAt >= $now));
    $isTrialExpired = (($status === 'trial' || empty($status)) && ($trialEndsAt < $now));

    $isPaidTier = in_array($tier, ['starter', 'growth', 'advanced', 'scale', 'pro', 'expert', 'premium', 'enterprise'], true);
    $isSubscriptionActive = $manualProOverride ||
                           (!empty($company['sub_status']) && $company['sub_status'] === 'active') ||
                           ($status === 'active' && !empty($company['plan_id'])) ||
                           ($status === 'active' && $isPaidTier);

    $trialDaysRemaining = $isTrialActive ? max(0, (int)ceil(($trialEndsAt - $now) / 86400)) : 0;

    // Determine effective plan tier and feature unlock
    $isFullAccess = $manualProOverride || (!$isSuspended && ($isSubscriptionActive || $isTrialActive));

    if ($manualProOverride) {
        $effectiveTier = 'pro';
        $statusLabel = 'ACTIVE';
        $isPremium = true;
    } elseif (!$isSuspended && $isSubscriptionActive) {
        $effectiveTier = !empty($company['plan_code']) ? $company['plan_code'] : ($tier ?: 'premium');
        $statusLabel = 'ACTIVE';
        $isPremium = true;
    } elseif ($isTrialActive) {
        $effectiveTier = 'free_trial';
        $statusLabel = 'TRIALING';
        $isPremium = false; // Flag to indicate on trial
    } elseif ($isTrialExpired) {
        $effectiveTier = 'expired_trial';
        $statusLabel = 'EXPIRED';
        $isPremium = false;
    } else {
        $effectiveTier = $isSuspended ? 'locked' : 'free_trial';
        $statusLabel = strtoupper($company['status'] ?: 'TRIALING');
        $isPremium = false;
    }

        // Advanced & Omnichannel features (Gated for free_trial/starter; Unlocked on Growth/Pro/Active Premium)
        $canAdvancedFeatures = $isFullAccess && ($manualProOverride || $isSubscriptionActive || ($isTrialActive && !in_array($tier, ['free_trial', 'starter', 'free'], true))) && !$isTrialExpired;

        return [
            'valid'                  => true,
            'company_id'             => (int)$company['id'],
            'company_name'           => $company['name'],
            'company_key'            => $company['company_key'],
            'status'                 => $statusLabel,
            'effective_tier'         => $effectiveTier,
            'is_premium'             => $isPremium,
            'is_trial'               => $isTrialActive,
            'is_trial_expired'       => $isTrialExpired,
            'is_full_access'         => $isFullAccess,
            'manual_pro_override'    => $manualProOverride,
            'is_workspace_locked'     => !$isFullAccess,
            'trial_days_remaining'   => $trialDaysRemaining,
            'trial_started_at'       => $company['trial_started_at'] ?? $company['created_at'],
            'trial_ends_at'          => date('Y-m-d H:i:s', $trialEndsAt),
            'formatted_trial_end'    => date('M j, Y', $trialEndsAt),
            'whatsapp_connected'     => (bool)($company['whatsapp_connected'] ?? 0),
            'current_plan_name'      => $manualProOverride ? 'Manual Pro Access' : ($company['plan_name'] ?? '14-Day Free Trial'),
            'current_cycle_amount'   => ($manualProOverride || ($isTrialActive && !$isSubscriptionActive)) ? 0 : (int)($company['price_monthly_inr'] ?? 199),
            
            'capabilities' => [
                // Core capabilities
                'can_use_ai_assistant'          => $isFullAccess,
                'can_capture_leads'             => $isFullAccess,
                'can_use_crm'                   => $isFullAccess,
                'can_view_conversations'        => $isFullAccess,
                'can_use_lead_qualification'    => $isFullAccess,
                'can_use_lead_priority'         => $isFullAccess,
                'can_use_basic_pipeline'        => $isFullAccess,
                'can_use_basic_analytics'       => $isFullAccess,

                // Advanced & Omnichannel features
                'can_use_whatsapp'              => $canAdvancedFeatures,
                'can_use_whatsapp_continuation' => $canAdvancedFeatures,
                'can_create_team'               => $isFullAccess,
                'can_assign_leads'              => $isFullAccess,
                'can_receive_whatsapp_alerts'   => $canAdvancedFeatures,
                'can_use_automations'           => $canAdvancedFeatures,
                'can_use_payment_reminders'     => $canAdvancedFeatures,
                'can_use_closing_radar'         => $isFullAccess,
                'can_use_calendar_integration'  => $isFullAccess,
                'can_use_ai_appointment_booking'=> $isFullAccess,
                'can_use_advanced_reminders'    => $canAdvancedFeatures,
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
