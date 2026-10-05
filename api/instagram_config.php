<?php
/**
 * CUBOIDPILOT — INSTAGRAM INTEGRATION API (Section 4)
 * Strictly multi-tenant isolated: each company configures its own Meta/Instagram account.
 * Tokens are encrypted at rest and never exposed back in plain text.
 */

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];

try {
    $pdo = getDbConnection();

    $action = $_GET['action'] ?? ($_POST['action'] ?? 'get');
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?? $_POST;
    if (!empty($data['action'])) {
        $action = $data['action'];
    }

    switch ($action) {
        // 1. Get Instagram Configuration
        case 'get':
            $stmt = $pdo->prepare("SELECT * FROM `company_instagram_configs` WHERE `company_id` = ? LIMIT 1");
            $stmt->execute([$companyId]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);

            $isLocal = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1']);
            $baseWebhookPath = $isLocal ? '/cuboidpilot/api/webhooks/instagram.php' : '/api/webhooks/instagram.php';
            $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http');
            $callbackUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'cai.cuboidsoft.in') . $baseWebhookPath;

            if (!$config) {
                // Generate default webhook verify token if not yet created
                $defaultVerifyToken = 'cp_ig_' . substr(hash('sha256', "ig_salt_{$companyId}"), 0, 16);
                echo json_encode([
                    'success'    => true,
                    'configured' => false,
                    'config'     => [
                        'page_id'               => '',
                        'instagram_account_id'  => '',
                        'instagram_username'    => '',
                        'access_token_masked'   => '',
                        'has_access_token'      => false,
                        'app_secret_masked'     => '',
                        'has_app_secret'        => false,
                        'webhook_verify_token'  => $defaultVerifyToken,
                        'webhook_callback_url'  => $callbackUrl,
                        'status'                => 'disconnected'
                    ]
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                exit;
            }

            echo json_encode([
                'success'    => true,
                'configured' => ($config['status'] === 'connected'),
                'config'     => [
                    'page_id'              => $config['page_id'] ?? '',
                    'instagram_account_id' => $config['instagram_account_id'] ?? '',
                    'instagram_username'   => $config['instagram_username'] ?? '',
                    'access_token_masked'  => !empty($config['access_token_encrypted']) ? '••••••••' : '',
                    'has_access_token'     => !empty($config['access_token_encrypted']),
                    'app_secret_masked'    => !empty($config['app_secret_encrypted']) ? '••••••••' : '',
                    'has_app_secret'       => !empty($config['app_secret_encrypted']),
                    'webhook_verify_token' => $config['webhook_verify_token'] ?? '',
                    'webhook_callback_url' => $callbackUrl,
                    'status'               => $config['status'] ?? 'disconnected',
                    'updated_at'           => $config['updated_at']
                ]
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        // 2. Save Instagram Configuration
        case 'save':
            $pageId       = trim($data['page_id'] ?? '');
            $accountId    = trim($data['instagram_account_id'] ?? '');
            $username     = trim(preg_replace('/^@/', '', $data['instagram_username'] ?? ''));
            $accessToken  = trim($data['access_token'] ?? '');
            $appSecret    = trim($data['app_secret'] ?? '');
            $verifyToken  = trim($data['webhook_verify_token'] ?? '');
            $status       = trim($data['status'] ?? 'connected');

            if (empty($verifyToken)) {
                $verifyToken = 'cp_ig_' . substr(hash('sha256', "ig_salt_{$companyId}"), 0, 16);
            }

            $chk = $pdo->prepare("SELECT id, access_token_encrypted, app_secret_encrypted FROM `company_instagram_configs` WHERE `company_id` = ? LIMIT 1");
            $chk->execute([$companyId]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);

            // Handle access token encryption
            $encToken = null;
            if (!empty($accessToken) && $accessToken !== '••••••••') {
                $encToken = encryptSecret($accessToken);
            } elseif ($existing) {
                $encToken = $existing['access_token_encrypted'];
            }

            // Handle app secret encryption
            $encSecret = null;
            if (!empty($appSecret) && $appSecret !== '••••••••') {
                $encSecret = encryptSecret($appSecret);
            } elseif ($existing) {
                $encSecret = $existing['app_secret_encrypted'];
            }

            if ($existing) {
                $upd = $pdo->prepare("
                    UPDATE `company_instagram_configs`
                    SET `page_id` = ?,
                        `instagram_account_id` = ?,
                        `instagram_username` = ?,
                        `access_token_encrypted` = ?,
                        `app_secret_encrypted` = ?,
                        `webhook_verify_token` = ?,
                        `status` = ?,
                        `updated_at` = NOW()
                    WHERE `company_id` = ?
                ");
                $upd->execute([$pageId, $accountId, $username, $encToken, $encSecret, $verifyToken, $status, $companyId]);
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO `company_instagram_configs`
                    (`company_id`, `page_id`, `instagram_account_id`, `instagram_username`, `access_token_encrypted`, `app_secret_encrypted`, `webhook_verify_token`, `status`, `created_at`, `updated_at`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $ins->execute([$companyId, $pageId, $accountId, $username, $encToken, $encSecret, $verifyToken, $status]);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Instagram integration configuration saved successfully.'
            ]);
            break;

        // 3. Test Connection with Meta Graph API
        case 'test_connection':
            $stmt = $pdo->prepare("SELECT * FROM `company_instagram_configs` WHERE `company_id` = ? LIMIT 1");
            $stmt->execute([$companyId]);
            $cfg = $stmt->fetch(PDO::FETCH_ASSOC);

            $tokenToTest = trim($data['access_token'] ?? '');
            if (empty($tokenToTest) || $tokenToTest === '••••••••') {
                if ($cfg && !empty($cfg['access_token_encrypted'])) {
                    $tokenToTest = decryptSecret($cfg['access_token_encrypted']);
                }
            }

            if (empty($tokenToTest)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'No access token provided to test.']);
                exit;
            }

            // Ping Meta Graph API endpoint
            $ch = curl_init("https://graph.facebook.com/v20.0/me?access_token=" . urlencode($tokenToTest));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $metaData = json_decode($resp, true);

            if ($httpCode === 200 && !empty($metaData['id'])) {
                $pdo->prepare("UPDATE `company_instagram_configs` SET `status` = 'connected', `updated_at` = NOW() WHERE `company_id` = ?")
                    ->execute([$companyId]);

                echo json_encode([
                    'success' => true,
                    'message' => "Successfully connected to Meta/Instagram (Account: " . ($metaData['name'] ?? $metaData['id']) . ").",
                    'meta'    => $metaData
                ]);
            } else {
                $errMsg = $metaData['error']['message'] ?? "Meta API returned HTTP {$httpCode}";
                echo json_encode([
                    'success' => false,
                    'error'   => "Instagram connection verification failed: {$errMsg}"
                ]);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
