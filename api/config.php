<?php
/**
 * CUBOIDPILOT — WIDGET CONFIGURATION API (Section 6 & 11)
 * Returns tenant identity, widget appearance, persona, and features.
 * Enforces strict multi-tenant resolution via public company key.
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Company-Key, X-Widget-Token");

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/entitlements.php';
require_once __DIR__ . '/../includes/widget_security.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
    session_write_close();
}

try {
    $pdo = getDbConnection();
    
    // Retrieve company key from GET, POST, or Header
    $companyKey = $_GET['company_key'] 
        ?? $_POST['company_key'] 
        ?? $_SERVER['HTTP_X_COMPANY_KEY'] 
        ?? '';

    $companyKey = trim($companyKey);
    $hasExplicitCompanyKey = ($companyKey !== '' && $companyKey !== 'default');
    $company = null;

    if ($hasExplicitCompanyKey) {
        $stmt = $pdo->prepare("
            SELECT * FROM `companies` 
            WHERE `company_key` = ? 
               OR `slug` = ? 
               OR (slug = 'cuboidsoft' AND ? IN ('cp_live_cuboidsoft', 'cp_live_cuboidpilot', 'cuboidsoft', 'cuboidpilot'))
            LIMIT 1
        ");
        $stmt->execute([$companyKey, $companyKey, $companyKey]);
        $company = $stmt->fetch();
    }

    if (!$company && !$hasExplicitCompanyKey && !empty($_SESSION['user_id']) && !empty($_SESSION['company_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM `companies` WHERE `id` = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['company_id']]);
        $company = $stmt->fetch();
    }

    if (!$company) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error'   => 'Workspace not found. Check your widget data-company attribute.'
        ]);
        exit;
    }
    cpValidateWidgetInstallToken($company, cpGetWidgetRequestToken());

    $companyId = (int)$company['id'];
    $entitlements = getCompanyEntitlements($pdo, $companyId);
    $isWidgetEnabled = !empty($entitlements['is_full_access']);
    $widgetDisabledReason = $isWidgetEnabled
        ? ''
        : (!empty($entitlements['is_trial_expired'])
            ? 'Your 14-day free trial has ended. Please upgrade or contact sales to reactivate the widget.'
            : 'This workspace is not currently active. Please contact the workspace owner or CuboidPilot sales.');

    $wStmt = $pdo->prepare("SELECT * FROM `widget_settings` WHERE `company_id` = ? LIMIT 1");
    $wStmt->execute([$companyId]);
    $widget = $wStmt->fetch();

    $brandName = !empty($widget['brand_name']) ? $widget['brand_name'] : $company['name'];
    $assistantName = !empty($widget['assistant_name']) ? $widget['assistant_name'] : 'Cai';
    $greetingHeading = !empty($widget['greeting_heading']) 
        ? $widget['greeting_heading'] 
        : "Hi there \u{1F44B} Welcome to {$brandName}!\n\nPlease share your Name and WhatsApp / Phone Number to get started:";
    $greetingSubheading = !empty($widget['greeting_subheading']) ? $widget['greeting_subheading'] : "The team can also help";
    if (strlen($greetingSubheading) > 28) {
        $greetingSubheading = "The team can also help";
    }
    $accentColor = $widget['accent_color'] ?? '#111215';
    $position = $widget['position'] ?? 'bottom_right';

    // WhatsApp continuation is enabled ONLY if the tenant has premium entitlement AND widget setting allows it
    $canWhatsapp = !empty($entitlements['capabilities']['can_use_whatsapp_continuation']);
    $whatsappEnabled = $canWhatsapp && (bool)($widget['enable_whatsapp_continue'] ?? 1);
    $whatsappNumber = $widget['whatsapp_number'] ?? '';

    $themeMode = !empty($widget['theme_mode']) ? $widget['theme_mode'] : 'dark';
    $logoDark = !empty($widget['logo_dark_url']) ? $widget['logo_dark_url'] : ($company['logo_dark_url'] ?? '');
    $logoLight = !empty($widget['logo_light_url']) ? $widget['logo_light_url'] : ($company['logo_light_url'] ?? '');
    $defaultLogo = !empty($widget['logo_url']) ? $widget['logo_url'] : ($company['logo_url'] ?? '');

    // Select active logo based on current theme mode
    $activeLogo = ($themeMode === 'light')
        ? ($logoLight ?: $defaultLogo ?: $logoDark)
        : ($logoDark ?: $defaultLogo ?: $logoLight);

    // Dynamic Quick Action Chips (Client-Controlled from quick_chips Registry)
    $quickActions = [];
    try {
        $chipsStmt = $pdo->prepare("
            SELECT id, label, display_order, action_type, response_text, linked_category_id, linked_catalog_id, action_payload_json
            FROM `quick_chips`
            WHERE `company_id` = ? AND `is_active` = 1 AND `is_starter` = 1 AND `status` = 'published'
            ORDER BY `display_order` ASC, `id` ASC
        ");
        $chipsStmt->execute([$companyId]);
        $dbChips = $chipsStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($dbChips as $chip) {
            $quickActions[] = [
                'id'                  => (int)$chip['id'],
                'label'               => $chip['label'],
                'text'                => !empty($chip['response_text']) ? $chip['response_text'] : $chip['label'],
                'action_type'         => $chip['action_type'] ?: 'SEND_TEXT_RESPONSE',
                'linked_category_id'  => $chip['linked_category_id'] ? (int)$chip['linked_category_id'] : null,
                'linked_catalog_id'   => $chip['linked_catalog_id'] ? (int)$chip['linked_catalog_id'] : null,
                'action_payload'      => !empty($chip['action_payload_json']) ? json_decode($chip['action_payload_json'], true) : null
            ];
        }
    } catch (Throwable $e) {
        // Fallback to widget settings json if quick_chips fails
        if (!empty($widget['quick_actions_json'])) {
            $decodedQa = json_decode($widget['quick_actions_json'], true);
            if (is_array($decodedQa) && count($decodedQa) > 0) {
                $quickActions = $decodedQa;
            }
        }
    }

    // Tenant Business Meta (Categories & Catalogs)
    $businessCategories = [];
    try {
        $catStmt = $pdo->prepare("SELECT id, name, slug, description, icon FROM `company_business_categories` WHERE `company_id` = ? AND `is_active` = 1 ORDER BY `display_order` ASC");
        $catStmt->execute([$companyId]);
        $businessCategories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    $publishedCatalogsCount = 0;
    try {
        $catCountStmt = $pdo->prepare("SELECT COUNT(*) FROM `offering_catalogs` WHERE `company_id` = ? AND `is_published` = 1");
        $catCountStmt->execute([$companyId]);
        $publishedCatalogsCount = (int)$catCountStmt->fetchColumn();
    } catch (Throwable $e) {}

    $publishedBrochuresCount = 0;
    try {
        $broCountStmt = $pdo->prepare("SELECT COUNT(*) FROM `company_assets` WHERE `company_id` = ? AND `asset_type` = 'brochure' AND `is_published` = 1");
        $broCountStmt->execute([$companyId]);
        $publishedBrochuresCount = (int)$broCountStmt->fetchColumn();
    } catch (Throwable $e) {}

    $featuredTeamMember = null;
    try {
        $teamStmt = $pdo->prepare("
            SELECT id, name, job_title, department, availability_status, avatar_url, is_instant_help_enabled, is_appointment_enabled
            FROM `users`
            WHERE `company_id` = ?
              AND `is_active` = 1
              AND `is_instant_help_enabled` = 1
              AND `availability_status` != 'OFFLINE'
            ORDER BY FIELD(availability_status, 'AVAILABLE', 'BUSY', 'APPOINTMENT_ONLY', 'OFFLINE'), id ASC
            LIMIT 1
        ");
        $teamStmt->execute([$companyId]);
        $member = $teamStmt->fetch(PDO::FETCH_ASSOC);
        if ($member) {
            $featuredTeamMember = [
                'id'                      => (int)$member['id'],
                'name'                    => $member['name'],
                'job_title'               => $member['job_title'] ?: 'Consultant',
                'department'              => $member['department'] ?: 'support',
                'availability_status'     => $member['availability_status'] ?: 'AVAILABLE',
                'avatar_url'              => $member['avatar_url'] ?: 'assets/uploads/avatars/avatar_default.svg',
                'is_instant_help_enabled' => (bool)$member['is_instant_help_enabled'],
                'is_appointment_enabled'  => (bool)$member['is_appointment_enabled'],
            ];
        }
    } catch (Throwable $e) {}

    echo json_encode([
        'success' => true,
        'company' => [
            'id'             => $companyId,
            'name'           => $company['name'],
            'logo_url'       => $activeLogo,
            'logo_dark_url'  => $logoDark ?: $defaultLogo,
            'logo_light_url' => $logoLight ?: $defaultLogo,
            'company_key'    => $company['company_key'],
            'is_premium'     => (bool)($entitlements['is_premium'] || ($entitlements['is_trial'] && !$entitlements['is_trial_expired'])),
            'is_trial'       => (bool)($entitlements['is_trial'] ?? false),
            'is_trial_expired' => (bool)($entitlements['is_trial_expired'] ?? false),
            'is_full_access' => (bool)($entitlements['is_full_access'] ?? false),
            'manual_pro_override' => (bool)($entitlements['manual_pro_override'] ?? false),
            'trial_days_remaining' => (int)($entitlements['trial_days_remaining'] ?? 0),
            'trial_ends_at'  => $entitlements['trial_ends_at'] ?? null,
        ],
        'widget' => [
            'is_widget_enabled' => $isWidgetEnabled,
            'widget_disabled'   => !$isWidgetEnabled,
            'widget_disabled_reason' => $widgetDisabledReason,
            'trial_ends_at'      => $entitlements['trial_ends_at'] ?? null,
            'brand_name'         => $brandName,
            'logo_url'           => $activeLogo,
            'logo_dark_url'      => $logoDark ?: $defaultLogo,
            'logo_light_url'     => $logoLight ?: $defaultLogo,
            'assistant_name'     => $assistantName,
            'greeting_heading'   => $greetingHeading,
            'greeting_subheading'=> $greetingSubheading,
            'quick_actions'      => $quickActions,
            'accent_color'       => $accentColor,
            'theme_mode'         => $themeMode,
            'position'           => $position,
            'whatsapp_enabled'   => $whatsappEnabled,
            'whatsapp_number'    => $whatsappNumber,
            'is_premium'         => (bool)($entitlements['is_premium'] || ($entitlements['is_trial'] && !$entitlements['is_trial_expired'])),
            'can_use_whatsapp_continuation' => (bool)($entitlements['capabilities']['can_use_whatsapp_continuation'] ?? false),
            'enable_inactivity_chips' => (bool)($widget['enable_inactivity_chips'] ?? 1),
            'inactivity_timeout_seconds' => (int)($widget['inactivity_timeout_seconds'] ?? 20),
            'require_phone'      => (bool)($widget['require_phone_for_pricing'] ?? 1),
            'enable_appointments'=> (bool)($widget['enable_appointments'] ?? 1),
            'enable_human_help'  => (bool)($widget['enable_human_help'] ?? 1),
            'enable_payments'    => (bool)($widget['enable_payments'] ?? 1),
            'razorpay_key_id'    => $widget['razorpay_key_id'] ?? '',
            'bank_name'          => $widget['bank_name'] ?? '',
            'bank_account_holder'=> $widget['bank_account_holder'] ?? '',
            'bank_account_no'    => $widget['bank_account_no'] ?? '',
            'bank_ifsc'          => $widget['bank_ifsc'] ?? '',
            'bank_upi_id'        => $widget['bank_upi_id'] ?? '',
            'bank_qr_url'        => $widget['bank_qr_url'] ?? '',
            'business_categories'=> $businessCategories,
            'catalogs_count'     => $publishedCatalogsCount,
            'brochures_count'    => $publishedBrochuresCount,
            'featured_team_member' => $featuredTeamMember,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("api/config.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Internal server error'
    ]);
}
