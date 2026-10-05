<?php
/**
 * CUBOIDPILOT — COMPANY EMAIL CONFIGURATION API (Section 2)
 * Strictly multi-tenant isolated: each company manages its own email account.
 * Passwords are encrypted at rest and never exposed back in plain text.
 */

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/mailer.php';

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
        // 1. Get Company Email Configuration
        case 'get':
            $stmt = $pdo->prepare("SELECT * FROM `company_email_configs` WHERE `company_id` = ? LIMIT 1");
            $stmt->execute([$companyId]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$config) {
                // Return default empty state
                echo json_encode([
                    'success'    => true,
                    'configured' => false,
                    'config'     => [
                        'sender_name'     => '',
                        'sender_email'    => '',
                        'smtp_host'       => 'smtp.gmail.com',
                        'smtp_port'       => 587,
                        'smtp_username'   => '',
                        'encryption_type' => 'tls',
                        'reply_to_email'  => '',
                        'has_password'    => false,
                        'is_verified'     => false,
                        'last_tested_at'  => null
                    ]
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                exit;
            }

            echo json_encode([
                'success'    => true,
                'configured' => true,
                'config'     => [
                    'sender_name'     => $config['sender_name'],
                    'sender_email'    => $config['sender_email'],
                    'smtp_host'       => $config['smtp_host'],
                    'smtp_port'       => (int)$config['smtp_port'],
                    'smtp_username'   => $config['smtp_username'],
                    'encryption_type' => $config['encryption_type'],
                    'reply_to_email'  => $config['reply_to_email'] ?? '',
                    'has_password'    => !empty($config['smtp_password_encrypted']),
                    'password_masked' => !empty($config['smtp_password_encrypted']) ? '••••••••' : '',
                    'is_verified'     => (bool)$config['is_verified'],
                    'last_tested_at'  => $config['last_tested_at']
                ]
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        // 2. Save Company Email Configuration
        case 'save':
            $senderName     = trim($data['sender_name'] ?? '');
            $senderEmail    = strtolower(trim($data['sender_email'] ?? ''));
            $smtpHost       = trim($data['smtp_host'] ?? '');
            $smtpPort       = (int)($data['smtp_port'] ?? 587);
            $smtpUsername   = trim($data['smtp_username'] ?? '');
            $smtpPassword   = trim($data['smtp_password'] ?? '');
            $encryptionType = strtolower(trim($data['encryption_type'] ?? 'tls'));
            $replyToEmail   = strtolower(trim($data['reply_to_email'] ?? ''));

            if (empty($senderName) || empty($senderEmail) || empty($smtpHost) || empty($smtpUsername)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Sender Name, Sender Email, SMTP Host, and SMTP Username are required.']);
                exit;
            }

            if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid Sender Email format.']);
                exit;
            }

            if (!empty($replyToEmail) && !filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid Reply-To Email format.']);
                exit;
            }

            if (!in_array($encryptionType, ['tls', 'ssl', 'none'])) {
                $encryptionType = 'tls';
            }

            // Check existing config
            $chk = $pdo->prepare("SELECT id, smtp_password_encrypted FROM `company_email_configs` WHERE `company_id` = ? LIMIT 1");
            $chk->execute([$companyId]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);

            $encryptedPass = '';
            if (!empty($smtpPassword) && $smtpPassword !== '••••••••') {
                $encryptedPass = encryptSecret($smtpPassword);
            } elseif ($existing && !empty($existing['smtp_password_encrypted'])) {
                $encryptedPass = $existing['smtp_password_encrypted'];
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'App Password or SMTP password is required.']);
                exit;
            }

            if ($existing) {
                $upd = $pdo->prepare("
                    UPDATE `company_email_configs`
                    SET `sender_name` = ?,
                        `sender_email` = ?,
                        `smtp_host` = ?,
                        `smtp_port` = ?,
                        `smtp_username` = ?,
                        `smtp_password_encrypted` = ?,
                        `encryption_type` = ?,
                        `reply_to_email` = ?,
                        `updated_at` = NOW()
                    WHERE `company_id` = ?
                ");
                $upd->execute([
                    $senderName, $senderEmail, $smtpHost, $smtpPort, $smtpUsername, $encryptedPass, $encryptionType, $replyToEmail ?: null, $companyId
                ]);
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO `company_email_configs`
                    (`company_id`, `sender_name`, `sender_email`, `smtp_host`, `smtp_port`, `smtp_username`, `smtp_password_encrypted`, `encryption_type`, `reply_to_email`, `created_at`, `updated_at`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $ins->execute([
                    $companyId, $senderName, $senderEmail, $smtpHost, $smtpPort, $smtpUsername, $encryptedPass, $encryptionType, $replyToEmail ?: null
                ]);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Company email configuration saved successfully.'
            ]);
            break;

        // 3. Test Connection
        case 'test_connection':
            // Can test with provided payload or existing saved credentials
            $host       = trim($data['smtp_host'] ?? '');
            $port       = (int)($data['smtp_port'] ?? 587);
            $username   = trim($data['smtp_username'] ?? '');
            $password   = trim($data['smtp_password'] ?? '');
            $encryption = strtolower(trim($data['encryption_type'] ?? 'tls'));

            if (empty($host) || empty($username) || empty($password) || $password === '••••••••') {
                $saved = CompanyMailer::getCompanyConfig($pdo, $companyId);
                if (!$saved) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Please fill in SMTP credentials to test connection.']);
                    exit;
                }
                $host = $saved['smtp_host'];
                $port = (int)$saved['smtp_port'];
                $username = $saved['smtp_username'];
                $password = $saved['smtp_password'];
                $encryption = $saved['encryption_type'];
            }

            $testResult = CompanyMailer::testConnection([
                'smtp_host'       => $host,
                'smtp_port'       => $port,
                'smtp_username'   => $username,
                'smtp_password'   => $password,
                'encryption_type' => $encryption
            ]);

            if ($testResult['success']) {
                $pdo->prepare("UPDATE `company_email_configs` SET `is_verified` = 1, `last_tested_at` = NOW() WHERE `company_id` = ?")
                    ->execute([$companyId]);
            }

            echo json_encode($testResult);
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
