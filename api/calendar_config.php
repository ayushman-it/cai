<?php
/**
 * CUBOIDPILOT — COMPANY CALENDAR CONFIGURATION API (Sections 7, 8, 14 & 18)
 * Strictly multi-tenant isolated.
 * Gated by Premium Entitlements: rejected with 403 Forbidden if not premium.
 * Stores Google Calendar connection & working availability parameters.
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

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];

$pdo = getDbConnection();

// Strict Premium Feature Gating (Section 18)
checkEntitlement($pdo, $companyId, 'can_use_calendar_integration', true);

try {
    $action = $_GET['action'] ?? ($_POST['action'] ?? 'get');
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?? $_POST;
    if (!empty($data['action'])) {
        $action = $data['action'];
    }

    switch ($action) {
        // 1. Get Calendar Configuration
        case 'get':
            $stmt = $pdo->prepare("SELECT * FROM `company_calendar_configs` WHERE `company_id` = ? LIMIT 1");
            $stmt->execute([$companyId]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$config) {
                // Check if legacy widget_settings had google_calendar fields
                $wStmt = $pdo->prepare("SELECT google_calendar_id, google_client_id, google_client_secret, google_refresh_token, calendar_working_days, calendar_start_time, calendar_end_time, calendar_slot_duration, calendar_buffer_minutes, calendar_advance_days, calendar_meet_url FROM `widget_settings` WHERE company_id = ? LIMIT 1");
                $wStmt->execute([$companyId]);
                $w = $wStmt->fetch(PDO::FETCH_ASSOC) ?: [];

                $isConnected = !empty($w['google_refresh_token']);
                $workingDays = $w['calendar_working_days'] ?? '1,2,3,4,5,6';
                $startTime = !empty($w['calendar_start_time']) ? substr($w['calendar_start_time'], 0, 5) : '09:00';
                $endTime = !empty($w['calendar_end_time']) ? substr($w['calendar_end_time'], 0, 5) : '18:00';

                echo json_encode([
                    'success'    => true,
                    'configured' => $isConnected,
                    'config'     => [
                        'provider'                    => 'google_calendar',
                        'google_calendar_id'          => $w['google_calendar_id'] ?? 'primary',
                        'google_client_id'            => $w['google_client_id'] ?? '',
                        'google_client_secret_masked' => !empty($w['google_client_secret']) ? '••••••••' : '',
                        'has_client_secret'           => !empty($w['google_client_secret']),
                        'google_refresh_token_masked' => !empty($w['google_refresh_token']) ? '••••••••' : '',
                        'has_refresh_token'           => !empty($w['google_refresh_token']),
                        'is_connected'                => $isConnected,
                        'working_days'                => $workingDays,
                        'working_days_array'          => array_map('intval', explode(',', $workingDays)),
                        'working_hours_start'         => $startTime,
                        'working_hours_end'           => $endTime,
                        'slot_duration_minutes'       => (int)($w['calendar_slot_duration'] ?? 30),
                        'buffer_before_minutes'       => 0,
                        'buffer_after_minutes'        => (int)($w['calendar_buffer_minutes'] ?? 10),
                        'min_booking_notice_hours'    => 2,
                        'max_advance_booking_days'    => (int)($w['calendar_advance_days'] ?? 14),
                        'timezone'                    => 'Asia/Kolkata',
                        'meeting_title_template'      => 'Consultation: {customer_name}',
                        'meeting_description_template'=> 'Scheduled via CuboidPilot AI Appointment Booking',
                        'meet_url'                    => $w['calendar_meet_url'] ?? 'https://meet.google.com/cp-consult'
                    ]
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                exit;
            }

            $workingDays = $config['working_days'] ?? '1,2,3,4,5,6';
            $startTime = !empty($config['working_hours_start']) ? substr($config['working_hours_start'], 0, 5) : '09:00';
            $endTime = !empty($config['working_hours_end']) ? substr($config['working_hours_end'], 0, 5) : '18:00';

            echo json_encode([
                'success'    => true,
                'configured' => (bool)$config['is_connected'],
                'config'     => [
                    'provider'                    => $config['provider'] ?? 'google_calendar',
                    'google_calendar_id'          => $config['google_calendar_id'] ?? 'primary',
                    'google_client_id'            => $config['google_client_id'] ?? '',
                    'google_client_secret_masked' => !empty($config['google_client_secret_encrypted']) ? '••••••••' : '',
                    'has_client_secret'           => !empty($config['google_client_secret_encrypted']),
                    'google_refresh_token_masked' => !empty($config['google_refresh_token_encrypted']) ? '••••••••' : '',
                    'has_refresh_token'           => !empty($config['google_refresh_token_encrypted']),
                    'is_connected'                => (bool)$config['is_connected'],
                    'working_days'                => $workingDays,
                    'working_days_array'          => array_map('intval', explode(',', $workingDays)),
                    'working_hours_start'         => $startTime,
                    'working_hours_end'           => $endTime,
                    'slot_duration_minutes'       => (int)($config['slot_duration_minutes'] ?? 30),
                    'buffer_before_minutes'       => (int)($config['buffer_before_minutes'] ?? 0),
                    'buffer_after_minutes'        => (int)($config['buffer_after_minutes'] ?? 10),
                    'min_booking_notice_hours'    => (int)($config['min_booking_notice_hours'] ?? 2),
                    'max_advance_booking_days'    => (int)($config['max_advance_booking_days'] ?? 14),
                    'timezone'                    => $config['timezone'] ?? 'Asia/Kolkata',
                    'meeting_title_template'      => $config['meeting_title_template'] ?? 'Consultation: {customer_name}',
                    'meeting_description_template'=> $config['meeting_description_template'] ?? '',
                    'updated_at'                  => $config['updated_at']
                ]
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        // 2. Save Calendar Configuration
        case 'save':
            $provider             = trim($data['provider'] ?? 'google_calendar');
            $googleCalendarId     = trim($data['google_calendar_id'] ?? 'primary');
            $googleClientId       = trim($data['google_client_id'] ?? '');
            $googleClientSecret   = trim($data['google_client_secret'] ?? '');
            $googleRefreshToken   = trim($data['google_refresh_token'] ?? '');
            $workingDaysInput     = $data['working_days'] ?? '1,2,3,4,5,6';
            $workingDays          = is_array($workingDaysInput) ? implode(',', $workingDaysInput) : trim($workingDaysInput);
            $workingHoursStart    = trim($data['working_hours_start'] ?? '09:00:00');
            $workingHoursEnd      = trim($data['working_hours_end'] ?? '18:00:00');
            $slotDuration         = (int)($data['slot_duration_minutes'] ?? 30);
            $bufferBefore         = (int)($data['buffer_before_minutes'] ?? 0);
            $bufferAfter          = (int)($data['buffer_after_minutes'] ?? 10);
            $minNotice            = (int)($data['min_booking_notice_hours'] ?? 2);
            $maxAdvance           = (int)($data['max_advance_booking_days'] ?? 14);
            $timezone             = trim($data['timezone'] ?? 'Asia/Kolkata');
            $titleTemplate        = trim($data['meeting_title_template'] ?? 'Consultation: {customer_name}');
            $descTemplate         = trim($data['meeting_description_template'] ?? '');
            $isConnected          = !empty($data['is_connected']) ? 1 : 0;

            // Check existing
            $chk = $pdo->prepare("SELECT id, google_client_secret_encrypted, google_refresh_token_encrypted, is_connected FROM `company_calendar_configs` WHERE `company_id` = ? LIMIT 1");
            $chk->execute([$companyId]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);

            $encSecret = null;
            if (!empty($googleClientSecret) && $googleClientSecret !== '••••••••') {
                $encSecret = encryptSecret($googleClientSecret);
            } elseif ($existing) {
                $encSecret = $existing['google_client_secret_encrypted'];
            }

            $encRefresh = null;
            if (!empty($googleRefreshToken) && $googleRefreshToken !== '••••••••') {
                $encRefresh = encryptSecret($googleRefreshToken);
                $isConnected = 1;
            } elseif ($existing) {
                $encRefresh = $existing['google_refresh_token_encrypted'];
                if ($existing['is_connected']) $isConnected = 1;
            }

            if (!empty($encRefresh)) {
                $isConnected = 1;
            }

            if ($existing) {
                $upd = $pdo->prepare("
                    UPDATE `company_calendar_configs`
                    SET `provider` = ?,
                        `google_calendar_id` = ?,
                        `google_client_id` = ?,
                        `google_client_secret_encrypted` = ?,
                        `google_refresh_token_encrypted` = ?,
                        `working_days` = ?,
                        `working_hours_start` = ?,
                        `working_hours_end` = ?,
                        `slot_duration_minutes` = ?,
                        `buffer_before_minutes` = ?,
                        `buffer_after_minutes` = ?,
                        `min_booking_notice_hours` = ?,
                        `max_advance_booking_days` = ?,
                        `timezone` = ?,
                        `meeting_title_template` = ?,
                        `meeting_description_template` = ?,
                        `is_connected` = ?,
                        `updated_at` = NOW()
                    WHERE `company_id` = ?
                ");
                $upd->execute([
                    $provider, $googleCalendarId, $googleClientId, $encSecret, $encRefresh,
                    $workingDays, $workingHoursStart, $workingHoursEnd, $slotDuration,
                    $bufferBefore, $bufferAfter, $minNotice, $maxAdvance,
                    $timezone, $titleTemplate, $descTemplate, $isConnected, $companyId
                ]);
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO `company_calendar_configs`
                    (`company_id`, `provider`, `google_calendar_id`, `google_client_id`, `google_client_secret_encrypted`, `google_refresh_token_encrypted`, `working_days`, `working_hours_start`, `working_hours_end`, `slot_duration_minutes`, `buffer_before_minutes`, `buffer_after_minutes`, `min_booking_notice_hours`, `max_advance_booking_days`, `timezone`, `meeting_title_template`, `meeting_description_template`, `is_connected`, `created_at`, `updated_at`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $ins->execute([
                    $companyId, $provider, $googleCalendarId, $googleClientId, $encSecret, $encRefresh,
                    $workingDays, $workingHoursStart, $workingHoursEnd, $slotDuration,
                    $bufferBefore, $bufferAfter, $minNotice, $maxAdvance,
                    $timezone, $titleTemplate, $descTemplate, $isConnected
                ]);
            }

            // Sync with widget_settings for legacy backwards compatibility
            $pdo->prepare("
                UPDATE `widget_settings`
                SET `calendar_working_days` = ?,
                    `calendar_start_time` = ?,
                    `calendar_end_time` = ?,
                    `calendar_slot_duration` = ?,
                    `calendar_buffer_minutes` = ?,
                    `calendar_advance_days` = ?
                WHERE `company_id` = ?
            ")->execute([
                $workingDays, substr($workingHoursStart, 0, 5), substr($workingHoursEnd, 0, 5),
                $slotDuration, $bufferAfter, $maxAdvance, $companyId
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Calendar and availability configuration saved successfully.'
            ]);
            break;

        // 3. Disconnect Calendar
        case 'disconnect':
            $pdo->prepare("
                UPDATE `company_calendar_configs`
                SET `google_refresh_token_encrypted` = NULL,
                    `google_access_token_encrypted` = NULL,
                    `google_token_expires_at` = NULL,
                    `is_connected` = 0,
                    `updated_at` = NOW()
                WHERE `company_id` = ?
            ")->execute([$companyId]);

            $pdo->prepare("
                UPDATE `widget_settings`
                SET `google_refresh_token` = NULL,
                    `google_access_token` = NULL,
                    `google_token_expires_at` = NULL
                WHERE `company_id` = ?
            ")->execute([$companyId]);

            echo json_encode([
                'success' => true,
                'message' => 'Calendar disconnected successfully.'
            ]);
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
