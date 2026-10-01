<?php
/**
 * CUBOIDPILOT — CURRENT SESSION CONTEXT & ENTITLEMENTS (API)
 * Validates session, loads tenant workspace, and returns capabilities.
 */

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/entitlements.php';

session_start();

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success'       => false,
        'authenticated' => false,
        'redirect'      => '../login.php'
    ]);
    exit;
}

try {
    $pdo = getDbConnection();
    $userId = (int)$_SESSION['user_id'];

    $userStmt = $pdo->prepare("
        SELECT id, uuid, company_id, name, email, role, is_super_admin, avatar_url, is_active
        FROM `users`
        WHERE id = ? AND is_active = 1
        LIMIT 1
    ");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();

    if (!$user) {
        session_destroy();
        http_response_code(401);
        echo json_encode([
            'success'       => false,
            'authenticated' => false,
            'redirect'      => '../login.php'
        ]);
        exit;
    }

    $companyId = (int)($user['company_id'] ?? $_SESSION['company_id'] ?? 0);
    $company = null;
    $entitlements = null;

    if ($companyId > 0) {
        $compStmt = $pdo->prepare("SELECT * FROM `companies` WHERE id = ? LIMIT 1");
        $compStmt->execute([$companyId]);
        $company = $compStmt->fetch();

        if ($company) {
            $entitlements = getCompanyEntitlements($pdo, $companyId);
            $wStmt = $pdo->prepare("SELECT assistant_name, brand_name, theme_mode, logo_dark_url, logo_light_url, greeting_heading, greeting_subheading FROM `widget_settings` WHERE company_id = ? LIMIT 1");
            $wStmt->execute([$companyId]);
            $widget = $wStmt->fetch();
        }
    }

    $themeMode = $widget['theme_mode'] ?? 'dark';
    $logoDark = $widget['logo_dark_url'] ?? ($company['logo_dark_url'] ?? null);
    $logoLight = $widget['logo_light_url'] ?? ($company['logo_light_url'] ?? null);

    echo json_encode([
        'success'       => true,
        'authenticated' => true,
        'user'          => [
            'id'             => (int)$user['id'],
            'uuid'           => $user['uuid'],
            'name'           => $user['name'],
            'email'          => $user['email'],
            'role'           => $user['role'],
            'is_super_admin' => (bool)$user['is_super_admin'],
            'avatar_url'     => $user['avatar_url']
        ],
        'company'       => $company ? [
            'id'                   => (int)$company['id'],
            'uuid'                 => $company['uuid'],
            'name'                 => $company['name'],
            'slug'                 => $company['slug'],
            'company_key'          => $company['company_key'],
            'industry'             => $company['industry'],
            'status'               => $company['status'],
            'onboarding_completed' => (bool)$company['onboarding_completed'],
            'whatsapp_connected'   => (bool)$company['whatsapp_connected'],
            'trial_ends_at'        => $company['trial_ends_at'],
            'logo_url'             => $company['logo_url'] ?? null,
            'logo_dark_url'        => $logoDark,
            'logo_light_url'       => $logoLight,
            'theme_mode'           => $themeMode,
            'assistant_name'       => $widget['assistant_name'] ?? 'Cai',
            'brand_name'           => $widget['brand_name'] ?? $company['name'],
            'greeting_heading'     => $widget['greeting_heading'] ?? "Hi there 👋\n\nYou are now speaking with Cai. How can I help?",
            'greeting_subheading'  => $widget['greeting_subheading'] ?? 'The team can also help'
        ] : null,
        'entitlements'  => $entitlements
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Server Error: ' . $e->getMessage()
    ]);
}
