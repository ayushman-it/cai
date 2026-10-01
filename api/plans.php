<?php
/**
 * CUBOIDPILOT — PUBLIC PLANS API (INTERCOM PRICING STRUCTURE)
 * Serves commercial subscription tiers configured in Super Admin database.
 */

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: public, max-age=60");
header("Access-Control-Allow-Origin: *");

require_once __DIR__ . '/../config/db.php';

try {
    $pdo = getDbConnection();
    $stmt = $pdo->query("SELECT * FROM `plans` WHERE `is_active` = 1 ORDER BY `price_monthly_inr` ASC");
    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Map database plans to Intercom-aligned commercial tiers
    $tierMapping = [
        'starter' => [
            'display_name' => 'Essential',
            'subtitle' => 'Customer support for individuals, startups, and small businesses.',
            'monthly_usd' => 29,
            'annual_monthly_usd' => 23,
            'annual_total_usd' => 276,
            'monthly_inr' => 99,
            'annual_monthly_inr' => 79,
            'annual_total_inr' => 948,
            'ai_outcome_rate' => '$0.99 per outcome',
            'ai_chats_quota' => 500,
            'badge' => 'Self-serve',
            'has_demo_btn' => false,
            'features' => [
                'Messenger live chat',
                'Shared inbox and ticketing system',
                'Pre-built reports',
                'Public help center',
                'Basic automation'
            ]
        ],
        'growth' => [
            'display_name' => 'Advanced',
            'subtitle' => 'Powerful automation and assignment tools for growing support teams.',
            'monthly_usd' => 85,
            'annual_monthly_usd' => 68,
            'annual_total_usd' => 816,
            'monthly_inr' => 199,
            'annual_monthly_inr' => 159,
            'annual_total_inr' => 1908,
            'ai_outcome_rate' => '$0.99 per outcome',
            'ai_chats_quota' => 2500,
            'badge' => 'Most Popular',
            'has_demo_btn' => true,
            'features_header' => 'Every Essential feature, plus:',
            'features' => [
                'Workflow automation builder',
                'Multiple team Inboxes',
                'Round robin assignment',
                'Private and multilingual Help Center',
                'Full WhatsApp Cloud API automation'
            ]
        ],
        'pro' => [
            'display_name' => 'Expert',
            'subtitle' => 'Collaboration and security features for large support teams.',
            'monthly_usd' => 349,
            'annual_monthly_usd' => 279,
            'annual_total_usd' => 3348,
            'monthly_inr' => 349,
            'annual_monthly_inr' => 279,
            'annual_total_inr' => 3348,
            'ai_outcome_rate' => '$0.99 per outcome',
            'ai_chats_quota' => 10000,
            'badge' => 'Enterprise Grade',
            'has_demo_btn' => true,
            'features_header' => 'Every Advanced feature, plus:',
            'features' => [
                'SSO & identity management',
                'HIPAA & SOC2 security support',
                'Service level agreements (SLAs)',
                'Multibrand Messenger / Help Center',
                'Dedicated WhatsApp queue & SLA'
            ]
        ]
    ];

    $formatted = [];
    foreach ($plans as $p) {
        $code = $p['code'];
        $meta = $tierMapping[$code] ?? null;
        if ($meta) {
            $formatted[] = array_merge([
                'id' => (int)$p['id'],
                'code' => $code,
                'db_name' => $p['name'],
                'db_monthly_inr' => (int)$p['price_monthly_inr'],
                'db_annual_inr' => (int)$p['price_annual_inr'],
                'db_ai_limit' => (int)$p['ai_conversations_limit'],
                'db_seats_limit' => (int)$p['team_members_limit']
            ], $meta);
        }
    }

    $standaloneCai = [
        'name' => 'Cai AI Agent',
        'subtitle' => 'Use Cai with an existing helpdesk such as Salesforce, HubSpot, or Zendesk.',
        'helpdesk_status' => 'Not included',
        'ai_outcome_rate' => '$0.99 per outcome',
        'ai_outcome_rate_inr' => '₹1 per outcome',
        'features' => [
            'Works with most helpdesks',
            'Set up in under an hour',
            'Learns your support content',
            'Answers across all channels',
            'Transfers to agents in inbox'
        ]
    ];

    echo json_encode([
        'success' => true,
        'plans' => $formatted,
        'standalone_cai' => $standaloneCai,
        'count' => count($formatted)
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
