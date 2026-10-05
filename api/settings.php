<?php
/**
 * CUBOIDPILOT — WORKSPACE SETTINGS & BRANDING API
 * Manages company profile, dual-theme logos (light/dark), AI persona, and widget customization.
 */

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/entitlements.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

try {
    $pdo = getDbConnection();

    $companyId = (int)$_SESSION['company_id'];
    $userId = (int)$_SESSION['user_id'];

    // Release session lock for read requests so concurrent assets/APIs load instantly
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        session_write_close();
    }

    $action = $_GET['action'] ?? ($_POST['action'] ?? '');
    if ($action === 'delete_workspace') {
        $uStmt = $pdo->prepare("SELECT role FROM `users` WHERE id = ? AND company_id = ? LIMIT 1");
        $uStmt->execute([$userId, $companyId]);
        $u = $uStmt->fetch();
        if (!$u || $u['role'] !== 'owner') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Only the workspace Owner can delete this workspace.']);
            exit;
        }

        if ($companyId === 3) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'The master demonstration workspace cannot be deleted.']);
            exit;
        }

        $rawInput = file_get_contents('php://input');
        $jsonData = json_decode($rawInput, true) ?? $_POST;
        $confirmName = trim($jsonData['confirm_name'] ?? '');

        $cStmt = $pdo->prepare("SELECT name FROM `companies` WHERE id = ? LIMIT 1");
        $cStmt->execute([$companyId]);
        $comp = $cStmt->fetch();

        if (strtolower($confirmName) !== strtolower($comp['name'] ?? '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Company name does not match confirmation input.']);
            exit;
        }

        $pdo->beginTransaction();
        try { $pdo->prepare("DELETE m FROM `messages` m INNER JOIN `conversations` c ON c.id = m.conversation_id WHERE c.company_id = ?")->execute([$companyId]); } catch (Exception $ex) {}
        try { $pdo->prepare("DELETE FROM `conversations` WHERE `company_id` = ?")->execute([$companyId]); } catch (Exception $ex) {}
        try { $pdo->prepare("DELETE FROM `lead_events` WHERE `company_id` = ?")->execute([$companyId]); } catch (Exception $ex) {}
        try { $pdo->prepare("DELETE FROM `leads` WHERE `company_id` = ?")->execute([$companyId]); } catch (Exception $ex) {}
        try { $pdo->prepare("DELETE FROM `installments` WHERE `company_id` = ?")->execute([$companyId]); } catch (Exception $ex) {}
        try { $pdo->prepare("DELETE FROM `customers` WHERE `company_id` = ?")->execute([$companyId]); } catch (Exception $ex) {}
        try { $pdo->prepare("DELETE FROM `whatsapp_messages` WHERE `company_id` = ?")->execute([$companyId]); } catch (Exception $ex) {}
        try { $pdo->prepare("DELETE FROM `whatsapp_handoffs` WHERE `company_id` = ?")->execute([$companyId]); } catch (Exception $ex) {}
        try { $pdo->prepare("DELETE FROM `whatsapp_accounts` WHERE `company_id` = ?")->execute([$companyId]); } catch (Exception $ex) {}
        try { $pdo->prepare("DELETE FROM `knowledge_sources` WHERE `company_id` = ?")->execute([$companyId]); } catch (Exception $ex) {}
        try { $pdo->prepare("DELETE FROM `widget_settings` WHERE `company_id` = ?")->execute([$companyId]); } catch (Exception $ex) {}
        try { $pdo->prepare("DELETE FROM `pipeline_stages` WHERE `company_id` = ?")->execute([$companyId]); } catch (Exception $ex) {}
        try { $pdo->prepare("DELETE FROM `payments` WHERE `company_id` = ?")->execute([$companyId]); } catch (Exception $ex) {}
        try { $pdo->prepare("DELETE FROM `subscriptions` WHERE `company_id` = ?")->execute([$companyId]); } catch (Exception $ex) {}
        try { $pdo->prepare("DELETE FROM `users` WHERE `company_id` = ?")->execute([$companyId]); } catch (Exception $ex) {}
        $pdo->prepare("DELETE FROM `companies` WHERE `id` = ?")->execute([$companyId]);
        $pdo->commit();

        session_unset();
        session_destroy();

        echo json_encode([
            'success' => true,
            'message' => 'Workspace deleted successfully. Redirecting to login.',
            'redirect' => '../login.php'
        ]);
        exit;
    }

    // Helper to handle image file uploads
    $handleFileUpload = function($fileKey, $prefix) use ($companyId) {
        if (!empty($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES[$fileKey];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['png', 'jpg', 'jpeg', 'svg', 'webp'];

            if (!in_array($ext, $allowed)) {
                return null;
            }

            $uploadDir = __DIR__ . '/../assets/uploads/logos/';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0777, true);
            }

            $fileName = "{$prefix}_comp_{$companyId}_" . time() . ".{$ext}";
            $destPath = $uploadDir . $fileName;

            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                return "assets/uploads/logos/{$fileName}";
            }
        }
        return null;
    };

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // 1. GET Current Settings
    if ($method === 'GET') {
        $cStmt = $pdo->prepare("SELECT * FROM `companies` WHERE id = ? LIMIT 1");
        $cStmt->execute([$companyId]);
        $company = $cStmt->fetch();

        $wStmt = $pdo->prepare("SELECT * FROM `widget_settings` WHERE company_id = ? LIMIT 1");
        $wStmt->execute([$companyId]);
        $widget = $wStmt->fetch();

        $logoDark = $widget['logo_dark_url'] ?? ($company['logo_dark_url'] ?? '');
        $logoLight = $widget['logo_light_url'] ?? ($company['logo_light_url'] ?? '');
        $logoDefault = $widget['logo_url'] ?? ($company['logo_url'] ?? '');

        $uStmt = $pdo->prepare("SELECT id, name, email, phone, role, avatar_url FROM `users` WHERE id = ? LIMIT 1");
        $uStmt->execute([$userId]);
        $currentUser = $uStmt->fetch(PDO::FETCH_ASSOC);

        $aiStmt = $pdo->prepare("SELECT * FROM `ai_configs` WHERE company_id = ? LIMIT 1");
        $aiStmt->execute([$companyId]);
        $aiConfig = $aiStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $waStmt = $pdo->prepare("SELECT * FROM `whatsapp_accounts` WHERE `company_id` = ? LIMIT 1");
        $waStmt->execute([$companyId]);
        $waAccount = $waStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        echo json_encode([
            'success' => true,
            'user'    => $currentUser ? [
                'id'         => (int)$currentUser['id'],
                'name'       => $currentUser['name'],
                'email'      => $currentUser['email'],
                'phone'      => $currentUser['phone'] ?? '',
                'role'       => $currentUser['role'],
                'avatar_url' => $currentUser['avatar_url'] ?? null
            ] : null,
            'company' => [
                'id'             => (int)$company['id'],
                'name'           => $company['name'],
                'logo_url'       => $logoDefault,
                'logo_dark_url'  => $logoDark,
                'logo_light_url' => $logoLight,
                'slug'           => $company['slug'],
                'company_key'    => $company['company_key'],
                'industry'       => $company['industry'] ?? '',
                'city'           => $company['city'] ?? '',
                'country'        => $company['country'] ?? 'India',
            ],
            'ai_config' => [
                'ai_name'                  => !empty($aiConfig['ai_name']) ? $aiConfig['ai_name'] : ($widget['assistant_name'] ?? 'Cai'),
                'tone'                     => $aiConfig['tone'] ?? 'professional',
                'primary_objective'        => $aiConfig['primary_objective'] ?? 'generate_leads',
                'custom_instructions'      => !empty($aiConfig['custom_instructions']) ? $aiConfig['custom_instructions'] : "You are the official conversational advisor. Always maintain a professional, helpful tone. When prospective clients inquire about services, pricing or delivery timelines, answer using verified knowledge sources and encourage booking a consultation or continuing on WhatsApp for direct quotes.",
                'info_to_collect'          => json_decode($aiConfig['info_to_collect_json'] ?? '["name","phone","email","requirement"]', true) ?: ["name", "phone", "email", "requirement"],
                'qualification_rules'      => json_decode($aiConfig['qualification_rules_json'] ?? '["High purchase intent","Timeline under 30 days","Budget confirmed"]', true) ?: ["High purchase intent", "Timeline under 30 days", "Budget confirmed"],
                'appointment_instructions' => $aiConfig['appointment_instructions'] ?? "Offer 1-on-1 consultations with verified consultants for in-depth evaluations.",
                'payment_instructions'     => $aiConfig['payment_instructions'] ?? "Accept UPI, NEFT bank transfers, and online card payments securely."
            ],
            'widget' => [
                'brand_name'         => $widget['brand_name'] ?? $company['name'],
                'assistant_name'     => $widget['assistant_name'] ?? 'Cai',
                'logo_url'           => $logoDefault,
                'logo_dark_url'      => $logoDark,
                'logo_light_url'     => $logoLight,
                'accent_color'       => $widget['accent_color'] ?? '#111111',
                'theme_mode'         => $widget['theme_mode'] ?? 'dark',
                'greeting_heading'   => $widget['greeting_heading'] ?? 'Ask anything about our pricing, plans or EMI',
                'greeting_subheading'=> $widget['greeting_subheading'] ?? 'The team can also help',
                'whatsapp_number'    => $widget['whatsapp_number'] ?? '',
                'enable_appointments'=> (bool)($widget['enable_appointments'] ?? 1),
                'enable_human_help'  => (bool)($widget['enable_human_help'] ?? 1),
                'enable_payments'    => (bool)($widget['enable_payments'] ?? 1),
                'razorpay_key_id'    => $widget['razorpay_key_id'] ?? '',
                'razorpay_key_secret'=> $widget['razorpay_key_secret'] ?? '',
                'bank_name'          => $widget['bank_name'] ?? 'HDFC Bank',
                'bank_account_holder'=> $widget['bank_account_holder'] ?? 'CuboidSoft Technologies',
                'bank_account_no'    => $widget['bank_account_no'] ?? '50200088991122',
                'bank_ifsc'          => $widget['bank_ifsc'] ?? 'HDFC0001234',
                'bank_upi_id'        => $widget['bank_upi_id'] ?? 'cuboidsoft@hdfcbank',
                'bank_qr_url'        => $widget['bank_qr_url'] ?? '',
                'calendar_working_days'  => $widget['calendar_working_days'] ?? '1,2,3,4,5,6',
                'calendar_start_time'    => $widget['calendar_start_time'] ?? '10:00',
                'calendar_end_time'      => $widget['calendar_end_time'] ?? '18:00',
                'calendar_slot_duration' => (int)($widget['calendar_slot_duration'] ?? 30),
                'calendar_buffer_minutes'=> (int)($widget['calendar_buffer_minutes'] ?? 0),
                'calendar_advance_days'  => (int)($widget['calendar_advance_days'] ?? 7),
                'calendar_meet_url'      => $widget['calendar_meet_url'] ?? 'https://meet.google.com/cp-consult',
                'calendar_provider'      => $widget['calendar_provider'] ?? 'native',
                'calendar_api_key'       => $widget['calendar_api_key'] ?? '',
                'calendar_booking_url'   => $widget['calendar_booking_url'] ?? '',
                'calendar_webhook_url'   => $widget['calendar_webhook_url'] ?? '',
                'calendar_sync_enabled'  => (int)($widget['calendar_sync_enabled'] ?? 1),
                'google_calendar_id'     => $widget['google_calendar_id'] ?? 'primary',
                'google_client_id'       => $widget['google_client_id'] ?? '',
                'google_client_secret'   => !empty($widget['google_client_secret']) ? '••••••••' : '',
                'google_refresh_token'   => !empty($widget['google_refresh_token']) ? '••••••••' : '',
                'google_connected'       => !empty($widget['google_refresh_token']),
            ],
            'whatsapp' => [
                'waba_id'              => $waAccount['waba_id'] ?? '',
                'phone_number_id'      => $waAccount['phone_number_id'] ?? '',
                'display_number'       => $waAccount['display_number'] ?? ($widget['whatsapp_number'] ?? ''),
                'whatsapp_access_token'=> !empty($waAccount['whatsapp_access_token']) ? '••••••••' . substr($waAccount['whatsapp_access_token'], -4) : '',
                'app_secret'           => !empty($waAccount['app_secret']) ? '••••••••' : '',
                'webhook_verify_token' => $waAccount['webhook_verify_token'] ?? ('cp_wa_' . substr(md5($companyId . 'cp_secret'), 0, 12)),
                'status'               => $waAccount['status'] ?? 'not_connected',
                'is_configured'        => (!empty($waAccount['phone_number_id']) && !empty($waAccount['whatsapp_access_token']))
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. POST Update Settings & Dual Logo Upload
    if ($method === 'POST') {
        $rawInput = file_get_contents('php://input');
        $jsonData = json_decode($rawInput, true);
        if (is_array($jsonData)) {
            $_POST = array_merge($_POST, $jsonData);
        }

        $name = trim($_POST['name'] ?? '');
        $brandName = trim($_POST['brand_name'] ?? '');
        $assistantName = trim($_POST['assistant_name'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $industry = trim($_POST['industry'] ?? '');
        $greetingHeading = trim($_POST['greeting_heading'] ?? '');
        $greetingSubheading = trim($_POST['greeting_subheading'] ?? '');
        $whatsappNumber = trim($_POST['whatsapp_number'] ?? '');
        $accentColor = trim($_POST['accent_color'] ?? '#111111');
        $themeMode = trim($_POST['theme_mode'] ?? '');
        if (!in_array($themeMode, ['light', 'dark'])) {
            $themeMode = '';
        }

        // If assistant name is set, ensure default greeting reflects it
        if (!empty($assistantName) && empty($greetingHeading)) {
            $greetingHeading = "Hi there \u{1F44B}\n\nYou are now speaking with {$assistantName}. How can I help?";
        }

        // Handle Dark Theme Logo (file upload or URL)
        $uploadedDark = $handleFileUpload('logo_dark_file', 'logo_dark');
        $logoDarkUrl = $uploadedDark ?: trim($_POST['logo_dark_url'] ?? '');

        // Handle Light Theme Logo (file upload or URL)
        $uploadedLight = $handleFileUpload('logo_light_file', 'logo_light');
        $logoLightUrl = $uploadedLight ?: trim($_POST['logo_light_url'] ?? '');

        // Handle fallback generic logo (file upload or URL)
        $uploadedDefault = $handleFileUpload('logo_file', 'logo');
        $logoDefaultUrl = $uploadedDefault ?: trim($_POST['logo_url'] ?? '');
        if (empty($logoDefaultUrl)) {
            $logoDefaultUrl = $logoDarkUrl ?: $logoLightUrl;
        }

        // Update companies table
        $compUpdateFields = [];
        $compParams = [];

        if (!empty($name)) {
            $compUpdateFields[] = "`name` = ?";
            $compParams[] = $name;
            $_SESSION['company_name'] = $name;
        }
        if (!empty($city)) {
            $compUpdateFields[] = "`city` = ?";
            $compParams[] = $city;
        }
        if (!empty($industry)) {
            $compUpdateFields[] = "`industry` = ?";
            $compParams[] = $industry;
        }
        if (!empty($logoDefaultUrl)) {
            $compUpdateFields[] = "`logo_url` = ?";
            $compParams[] = $logoDefaultUrl;
        }
        if (!empty($logoDarkUrl)) {
            $compUpdateFields[] = "`logo_dark_url` = ?";
            $compParams[] = $logoDarkUrl;
        }
        if (!empty($logoLightUrl)) {
            $compUpdateFields[] = "`logo_light_url` = ?";
            $compParams[] = $logoLightUrl;
        }

        if (!empty($compUpdateFields)) {
            $compParams[] = $companyId;
            $sql = "UPDATE `companies` SET " . implode(', ', $compUpdateFields) . " WHERE id = ?";
            $pdo->prepare($sql)->execute($compParams);
        }

        // Update widget_settings table
        $wCheck = $pdo->prepare("SELECT id FROM `widget_settings` WHERE company_id = ? LIMIT 1");
        $wCheck->execute([$companyId]);
        $existingWidget = $wCheck->fetch();

        if ($existingWidget) {
            $wUpdateFields = [];
            $wParams = [];

            if ($brandName !== '') {
                $wUpdateFields[] = "`brand_name` = ?";
                $wParams[] = $brandName;
            }
            if ($assistantName !== '') {
                $wUpdateFields[] = "`assistant_name` = ?";
                $wParams[] = $assistantName;
            }
            if ($greetingHeading !== '') {
                $wUpdateFields[] = "`greeting_heading` = ?";
                $wParams[] = $greetingHeading;
            }
            if ($greetingSubheading !== '') {
                $wUpdateFields[] = "`greeting_subheading` = ?";
                $wParams[] = $greetingSubheading;
            }
            if ($whatsappNumber !== '') {
                $wUpdateFields[] = "`whatsapp_number` = ?";
                $wParams[] = $whatsappNumber;
            }
            if (!empty($accentColor)) {
                $wUpdateFields[] = "`accent_color` = ?";
                $wParams[] = $accentColor;
            }
            if (!empty($themeMode)) {
                $wUpdateFields[] = "`theme_mode` = ?";
                $wParams[] = $themeMode;
            }
            if (!empty($logoDefaultUrl)) {
                $wUpdateFields[] = "`logo_url` = ?";
                $wParams[] = $logoDefaultUrl;
            }
            if (!empty($logoDarkUrl)) {
                $wUpdateFields[] = "`logo_dark_url` = ?";
                $wParams[] = $logoDarkUrl;
            }
            if (!empty($logoLightUrl)) {
                $wUpdateFields[] = "`logo_light_url` = ?";
                $wParams[] = $logoLightUrl;
            }
            if (isset($_POST['enable_appointments'])) {
                $wUpdateFields[] = "`enable_appointments` = ?";
                $wParams[] = !empty($_POST['enable_appointments']) ? 1 : 0;
            }
            if (isset($_POST['enable_human_help'])) {
                $wUpdateFields[] = "`enable_human_help` = ?";
                $wParams[] = !empty($_POST['enable_human_help']) ? 1 : 0;
            }
            if (isset($_POST['enable_payments'])) {
                $wUpdateFields[] = "`enable_payments` = ?";
                $wParams[] = !empty($_POST['enable_payments']) ? 1 : 0;
            }
            if (isset($_POST['razorpay_key_id'])) {
                $wUpdateFields[] = "`razorpay_key_id` = ?";
                $wParams[] = trim($_POST['razorpay_key_id']);
            }
            if (isset($_POST['razorpay_key_secret'])) {
                $wUpdateFields[] = "`razorpay_key_secret` = ?";
                $wParams[] = trim($_POST['razorpay_key_secret']);
            }
            if (isset($_POST['bank_name'])) {
                $wUpdateFields[] = "`bank_name` = ?";
                $wParams[] = trim($_POST['bank_name']);
            }
            if (isset($_POST['bank_account_holder'])) {
                $wUpdateFields[] = "`bank_account_holder` = ?";
                $wParams[] = trim($_POST['bank_account_holder']);
            }
            if (isset($_POST['bank_account_no'])) {
                $wUpdateFields[] = "`bank_account_no` = ?";
                $wParams[] = trim($_POST['bank_account_no']);
            }
            if (isset($_POST['bank_ifsc'])) {
                $wUpdateFields[] = "`bank_ifsc` = ?";
                $wParams[] = trim($_POST['bank_ifsc']);
            }
            if (isset($_POST['bank_upi_id'])) {
                $wUpdateFields[] = "`bank_upi_id` = ?";
                $wParams[] = trim($_POST['bank_upi_id']);
            }
            if (isset($_POST['bank_qr_url'])) {
                $wUpdateFields[] = "`bank_qr_url` = ?";
                $wParams[] = trim($_POST['bank_qr_url']);
            }
            // Check QR File upload
            if (!empty($_FILES['bank_qr_file']) && $_FILES['bank_qr_file']['error'] === UPLOAD_ERR_OK) {
                $qrDir = __DIR__ . '/../assets/uploads/qr/';
                if (!is_dir($qrDir)) @mkdir($qrDir, 0777, true);
                $ext = strtolower(pathinfo($_FILES['bank_qr_file']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'svg'])) $ext = 'png';
                $qrFilename = 'qr_' . $companyId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                if (move_uploaded_file($_FILES['bank_qr_file']['tmp_name'], $qrDir . $qrFilename)) {
                    $wUpdateFields[] = "`bank_qr_url` = ?";
                    $wParams[] = 'assets/uploads/qr/' . $qrFilename;
                }
            }

            // Calendar & Scheduling Settings
            if (isset($_POST['calendar_working_days'])) {
                $wUpdateFields[] = "`calendar_working_days` = ?";
                $wParams[] = trim($_POST['calendar_working_days']);
            }
            if (isset($_POST['calendar_start_time'])) {
                $wUpdateFields[] = "`calendar_start_time` = ?";
                $wParams[] = trim($_POST['calendar_start_time']);
            }
            if (isset($_POST['calendar_end_time'])) {
                $wUpdateFields[] = "`calendar_end_time` = ?";
                $wParams[] = trim($_POST['calendar_end_time']);
            }
            if (isset($_POST['calendar_slot_duration'])) {
                $wUpdateFields[] = "`calendar_slot_duration` = ?";
                $wParams[] = max(15, min(120, (int)$_POST['calendar_slot_duration']));
            }
            if (isset($_POST['calendar_buffer_minutes'])) {
                $wUpdateFields[] = "`calendar_buffer_minutes` = ?";
                $wParams[] = max(0, min(60, (int)$_POST['calendar_buffer_minutes']));
            }
            if (isset($_POST['calendar_advance_days'])) {
                $wUpdateFields[] = "`calendar_advance_days` = ?";
                $wParams[] = max(1, min(60, (int)$_POST['calendar_advance_days']));
            }
            if (isset($_POST['calendar_meet_url'])) {
                $wUpdateFields[] = "`calendar_meet_url` = ?";
                $wParams[] = trim($_POST['calendar_meet_url']);
            }
            if (isset($_POST['calendar_provider'])) {
                $wUpdateFields[] = "`calendar_provider` = ?";
                $wParams[] = trim($_POST['calendar_provider']);
            }
            if (isset($_POST['calendar_api_key'])) {
                $wUpdateFields[] = "`calendar_api_key` = ?";
                $wParams[] = trim($_POST['calendar_api_key']);
            }
            if (isset($_POST['calendar_booking_url'])) {
                $wUpdateFields[] = "`calendar_booking_url` = ?";
                $wParams[] = trim($_POST['calendar_booking_url']);
            }
            if (isset($_POST['calendar_webhook_url'])) {
                $wUpdateFields[] = "`calendar_webhook_url` = ?";
                $wParams[] = trim($_POST['calendar_webhook_url']);
            }
            if (isset($_POST['google_calendar_id'])) {
                $wUpdateFields[] = "`google_calendar_id` = ?";
                $wParams[] = trim($_POST['google_calendar_id']);
            }
            if (isset($_POST['google_client_id'])) {
                $wUpdateFields[] = "`google_client_id` = ?";
                $wParams[] = trim($_POST['google_client_id']);
            }
            if (isset($_POST['google_client_secret']) && trim($_POST['google_client_secret']) !== '' && strpos($_POST['google_client_secret'], '••••') === false) {
                $wUpdateFields[] = "`google_client_secret` = ?";
                $wParams[] = trim($_POST['google_client_secret']);
            }
            if (isset($_POST['google_refresh_token']) && trim($_POST['google_refresh_token']) !== '' && strpos($_POST['google_refresh_token'], '••••') === false) {
                $wUpdateFields[] = "`google_refresh_token` = ?";
                $wParams[] = trim($_POST['google_refresh_token']);
            }

            if (!empty($wUpdateFields)) {
                $wUpdateFields[] = "`updated_at` = NOW()";
                $wParams[] = $companyId;
                $pdo->prepare("UPDATE `widget_settings` SET " . implode(', ', $wUpdateFields) . " WHERE `company_id` = ?")->execute($wParams);
            }

            // Update WhatsApp Meta Cloud API credentials in whatsapp_accounts
            if (isset($_POST['waba_id']) || isset($_POST['phone_number_id']) || isset($_POST['whatsapp_access_token']) || isset($_POST['app_secret'])) {
                $wabaId     = trim($_POST['waba_id'] ?? '');
                $phoneNumId = trim($_POST['phone_number_id'] ?? '');
                $waToken    = trim($_POST['whatsapp_access_token'] ?? '');
                $appSecret  = trim($_POST['app_secret'] ?? '');
                $dispNumber = trim($_POST['display_number'] ?? $_POST['whatsapp_number'] ?? '');

                $chkAcc = $pdo->prepare("SELECT id, whatsapp_access_token, app_secret FROM `whatsapp_accounts` WHERE `company_id` = ? LIMIT 1");
                $chkAcc->execute([$companyId]);
                $accRow = $chkAcc->fetch(PDO::FETCH_ASSOC);

                if ($waToken !== '' && strpos($waToken, '••••') !== false && $accRow) {
                    $waToken = $accRow['whatsapp_access_token'];
                }
                if ($appSecret !== '' && strpos($appSecret, '••••') !== false && $accRow) {
                    $appSecret = $accRow['app_secret'];
                }

                $waStatus = (!empty($phoneNumId) && !empty($waToken)) ? 'connected' : 'not_connected';

                if ($accRow) {
                    $waFields = ["`updated_at` = NOW()"];
                    $waP = [];
                    if ($wabaId !== '') { $waFields[] = "`waba_id` = ?"; $waP[] = $wabaId; }
                    if ($phoneNumId !== '') { $waFields[] = "`phone_number_id` = ?"; $waP[] = $phoneNumId; }
                    if ($dispNumber !== '') { $waFields[] = "`display_number` = ?"; $waP[] = $dispNumber; }
                    if ($waToken !== '') { $waFields[] = "`whatsapp_access_token` = ?"; $waP[] = $waToken; }
                    if ($appSecret !== '') { $waFields[] = "`app_secret` = ?"; $waP[] = $appSecret; }
                    $waFields[] = "`status` = ?"; $waP[] = $waStatus;
                    $waP[] = $companyId;

                    $pdo->prepare("UPDATE `whatsapp_accounts` SET " . implode(", ", $waFields) . " WHERE `company_id` = ?")->execute($waP);
                } else {
                    $pdo->prepare("
                        INSERT INTO `whatsapp_accounts` 
                        (`company_id`, `waba_id`, `phone_number_id`, `display_number`, `whatsapp_access_token`, `app_secret`, `status`, `created_at`, `updated_at`)
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ")->execute([$companyId, $wabaId, $phoneNumId, $dispNumber, $waToken, $appSecret, $waStatus]);
                }
            }
        } else {
            $pdo->prepare("
                INSERT INTO `widget_settings`
                (`company_id`, `brand_name`, `assistant_name`, `greeting_heading`, `greeting_subheading`, `whatsapp_number`, `accent_color`, `theme_mode`, `logo_url`, `logo_dark_url`, `logo_light_url`, `created_at`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $companyId,
                $brandName ?: $name,
                $assistantName ?: 'Cai',
                $greetingHeading,
                $greetingSubheading,
                $whatsappNumber,
                $accentColor,
                $themeMode ?: 'dark',
                $logoDefaultUrl,
                $logoDarkUrl,
                $logoLightUrl
            ]);
        }

        // Update or insert ai_configs (AI Persona, Directives, Tone, Objectives)
        if (isset($_POST['assistant_name']) || isset($_POST['system_prompt']) || isset($_POST['ai_tone']) || isset($_POST['primary_objective'])) {
            $aiName = trim($_POST['assistant_name'] ?? '');
            $customInst = trim($_POST['system_prompt'] ?? ($_POST['custom_instructions'] ?? ''));
            $tone = trim($_POST['ai_tone'] ?? 'professional');
            if (!in_array($tone, ['professional', 'friendly', 'casual', 'custom'])) $tone = 'professional';
            $primaryObj = trim($_POST['primary_objective'] ?? 'generate_leads');
            if (!in_array($primaryObj, ['generate_leads', 'book_appointments', 'sales', 'customer_support', 'qualification', 'custom'])) $primaryObj = 'generate_leads';
            $infoCollect = isset($_POST['info_to_collect']) ? (is_array($_POST['info_to_collect']) ? json_encode($_POST['info_to_collect']) : $_POST['info_to_collect']) : null;
            $qualRules = isset($_POST['qualification_rules']) ? (is_array($_POST['qualification_rules']) ? json_encode($_POST['qualification_rules']) : $_POST['qualification_rules']) : null;
            $apptInst = isset($_POST['appointment_instructions']) ? trim($_POST['appointment_instructions']) : null;
            $payInst = isset($_POST['payment_instructions']) ? trim($_POST['payment_instructions']) : null;

            $aiCheck = $pdo->prepare("SELECT id FROM `ai_configs` WHERE company_id = ? LIMIT 1");
            $aiCheck->execute([$companyId]);
            $existingAi = $aiCheck->fetch();

            if ($existingAi) {
                $pdo->prepare("
                    UPDATE `ai_configs`
                    SET `ai_name` = COALESCE(NULLIF(?, ''), `ai_name`),
                        `custom_instructions` = ?,
                        `tone` = ?,
                        `primary_objective` = ?,
                        `info_to_collect_json` = COALESCE(?, `info_to_collect_json`),
                        `qualification_rules_json` = COALESCE(?, `qualification_rules_json`),
                        `appointment_instructions` = COALESCE(?, `appointment_instructions`),
                        `payment_instructions` = COALESCE(?, `payment_instructions`),
                        `updated_at` = NOW()
                    WHERE company_id = ?
                ")->execute([$aiName, $customInst, $tone, $primaryObj, $infoCollect, $qualRules, $apptInst, $payInst, $companyId]);
            } else {
                $pdo->prepare("
                    INSERT INTO `ai_configs`
                    (`company_id`, `ai_name`, `custom_instructions`, `tone`, `primary_objective`, `info_to_collect_json`, `qualification_rules_json`, `appointment_instructions`, `payment_instructions`, `created_at`, `updated_at`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ")->execute([$companyId, $aiName ?: 'Cai', $customInst, $tone, $primaryObj, $infoCollect, $qualRules, $apptInst, $payInst]);
            }
        }

        // Handle Personal User Avatar & Name
        $userAvatarUrl = null;
        if (!empty($_FILES['user_avatar_file']) && $_FILES['user_avatar_file']['error'] === UPLOAD_ERR_OK) {
            $uUploadDir = __DIR__ . '/../assets/uploads/avatars/';
            if (!is_dir($uUploadDir)) @mkdir($uUploadDir, 0777, true);
            $ext = strtolower(pathinfo($_FILES['user_avatar_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) $ext = 'png';
            $uFileName = 'avatar_' . $companyId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (move_uploaded_file($_FILES['user_avatar_file']['tmp_name'], $uUploadDir . $uFileName)) {
                $userAvatarUrl = 'assets/uploads/avatars/' . $uFileName;
            }
        } elseif (!empty($_POST['user_avatar_data'])) {
            $rawAv = $_POST['user_avatar_data'];
            if (preg_match('/^data:image\/(\w+);base64,/', $rawAv, $matches)) {
                $uUploadDir = __DIR__ . '/../assets/uploads/avatars/';
                if (!is_dir($uUploadDir)) @mkdir($uUploadDir, 0777, true);
                $ext = strtolower($matches[1]);
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) $ext = 'png';
                $decoded = base64_decode(substr($rawAv, strpos($rawAv, ',') + 1));
                if ($decoded !== false) {
                    $uFileName = 'avatar_' . $companyId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    if (file_put_contents($uUploadDir . $uFileName, $decoded)) {
                        $userAvatarUrl = 'assets/uploads/avatars/' . $uFileName;
                    }
                }
            }
        }

        if ($userAvatarUrl) {
            $pdo->prepare("UPDATE `users` SET avatar_url = ? WHERE id = ?")->execute([$userAvatarUrl, $userId]);
            $_SESSION['avatar_url'] = $userAvatarUrl;
        }

        $userName = trim($_POST['user_name'] ?? '');
        if (!empty($userName)) {
            $pdo->prepare("UPDATE `users` SET name = ? WHERE id = ?")->execute([$userName, $userId]);
            $_SESSION['user_name'] = $userName;
        }

        echo json_encode([
            'success'         => true,
            'message'         => 'Workspace settings and user profile updated successfully.',
            'logo_url'        => $logoDefaultUrl,
            'logo_dark_url'   => $logoDarkUrl,
            'logo_light_url'  => $logoLightUrl,
            'user_avatar_url' => $userAvatarUrl,
            'user_name'       => $userName ?: null,
            'theme_mode'      => $themeMode
        ]);
        exit;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Settings API Error: ' . $e->getMessage()
    ]);
}