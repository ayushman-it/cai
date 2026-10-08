<?php
/**
 * CUBOIDPILOT — UNIFIED META WEBHOOK ENDPOINT (Instagram & WhatsApp)
 * Official endpoint: https://cai.cuboidsoft.in/api/instagram_webhook.php
 * Also aliased as: /api/webhooks/meta.php and /api/meta_webhook.php
 * 
 * Supports:
 * 1. Meta Developer Console Webhook Verification (GET hub.mode=subscribe)
 * 2. Instagram Direct Messages & Stories (POST object=instagram)
 * 3. WhatsApp Business Cloud API (POST object=whatsapp_business_account)
 * 4. Meta Page / Messenger Webhooks (POST object=page)
 */

ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/../config/db.php';

// =========================================================================
// 1. META WEBHOOK VERIFICATION HANDSHAKE (GET)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
    $token     = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';
    $companyKey= trim($_GET['company_key'] ?? '');

    // Fallback if PHP didn't parse dotted query parameters
    if (empty($mode) || empty($challenge) || empty($token)) {
        parse_str($_SERVER['QUERY_STRING'] ?? '', $rawQs);
        if (empty($mode))      $mode      = $rawQs['hub_mode'] ?? $rawQs['hub.mode'] ?? '';
        if (empty($token))     $token     = $rawQs['hub_verify_token'] ?? $rawQs['hub.verify_token'] ?? '';
        if (empty($challenge)) $challenge = $rawQs['hub_challenge'] ?? $rawQs['hub.challenge'] ?? '';
    }

    if ($mode === 'subscribe' && !empty($challenge)) {
        $isMatched = false;

        // Recognized universal verify tokens
        $universalTokens = [
            'cuboidsoft',
            'cuboidpilot',
            'cai',
            'cai_ai',
            'cai_meta_verify',
            'cuboid_ig_verify',
            'cuboid_meta_verify',
            'cuboid_instagram_secret',
            'cai_webhook_secret',
            'cp_ig_verify_d12586b4c00064dd'
        ];

        // Check against universal list
        if (in_array((string)$token, $universalTokens, true)) {
            $isMatched = true;
        }

        // Check against environment variables
        $envTokens = array_filter([
            getenv('META_VERIFY_TOKEN') ?: '',
            getenv('INSTAGRAM_VERIFY_TOKEN') ?: '',
            getenv('WHATSAPP_VERIFY_TOKEN') ?: ''
        ]);
        if (!$isMatched) {
            foreach ($envTokens as $eTok) {
                if (!empty($eTok) && hash_equals($eTok, (string)$token)) {
                    $isMatched = true;
                    break;
                }
            }
        }

        // Check against company_instagram_configs & whatsapp_accounts DB tables
        if (!$isMatched && !empty($token)) {
            try {
                $pdo = getDbConnection();
                $igStmt = $pdo->prepare("SELECT id FROM `company_instagram_configs` WHERE `webhook_verify_token` = ? LIMIT 1");
                $igStmt->execute([$token]);
                if ($igStmt->fetch()) {
                    $isMatched = true;
                }

                if (!$isMatched) {
                    $waStmt = $pdo->prepare("SELECT id FROM `whatsapp_accounts` WHERE `webhook_verify_token` = ? AND `webhook_verify_token` != '' LIMIT 1");
                    $waStmt->execute([$token]);
                    if ($waStmt->fetch()) {
                        $isMatched = true;
                    }
                }

                if (!$isMatched && !empty($companyKey)) {
                    $cStmt = $pdo->prepare("
                        SELECT c.id FROM `companies` c
                        LEFT JOIN `company_instagram_configs` ig ON ig.company_id = c.id
                        LEFT JOIN `whatsapp_accounts` wa ON wa.company_id = c.id
                        WHERE (c.company_key = ? OR c.slug = ?)
                          AND (ig.webhook_verify_token = ? OR wa.webhook_verify_token = ?)
                        LIMIT 1
                    ");
                    $cStmt->execute([$companyKey, $companyKey, $token, $token]);
                    if ($cStmt->fetch()) {
                        $isMatched = true;
                    }
                }
            } catch (Throwable $e) {}
        }

        if ($isMatched) {
            header("Content-Type: text/plain; charset=UTF-8");
            http_response_code(200);
            echo (string)$challenge;
            exit;
        }
    }

    http_response_code(403);
    header("Content-Type: text/plain; charset=UTF-8");
    echo "Verification token mismatch";
    exit;
}

// =========================================================================
// 2. INCOMING META WEBHOOK EVENTS (POST)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    if (empty($data)) {
        header("Content-Type: text/plain; charset=UTF-8");
        http_response_code(200);
        echo "EVENT_RECEIVED";
        exit;
    }

    $object = $data['object'] ?? '';

    // Route WhatsApp events directly to the WhatsApp processor
    if ($object === 'whatsapp_business_account' || isset($data['entry'][0]['changes'][0]['value']['messaging_product'])) {
        require __DIR__ . '/webhooks/whatsapp.php';
        exit;
    }

    // Route Instagram / Messenger events
    if ($object === 'instagram' || isset($data['entry'][0]['messaging'])) {
        require __DIR__ . '/webhooks/instagram.php';
        exit;
    }

    // Default 200 acknowledgement for any other Meta events
    header("Content-Type: text/plain; charset=UTF-8");
    http_response_code(200);
    echo "EVENT_RECEIVED";
    exit;
}

// Default fallback
header("Content-Type: application/json; charset=UTF-8");
echo json_encode([
    'service' => 'CuboidPilot Meta Webhook Hub',
    'status'  => 'active',
    'endpoints' => [
        'instagram' => 'https://cai.cuboidsoft.in/api/instagram_webhook.php',
        'meta'      => 'https://cai.cuboidsoft.in/api/webhooks/meta.php'
    ],
    'verify_tokens' => [
        'recommended' => 'cuboidsoft',
        'accepted'    => ['cuboidsoft', 'cai_meta_verify', 'cuboid_ig_verify']
    ]
], JSON_PRETTY_PRINT);
