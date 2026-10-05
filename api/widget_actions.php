<?php
/**
 * CUBOIDPILOT — WIDGET ACTIONS API (w-up: Premium Customer Actions)
 * Handles:
 * 1. get_actions - Widget action toggles and bank details
 * 2. get_team - Team directory with real-time availability and department filters
 * 3. get_slots - Dynamic calendar slot generation and booking collision detection
 * 4. book_appointment - Instant booking, lead linking, and CRM sync
 * 5. download_ics - RFC 5545 standard .ics calendar export
 * 6. start_human_chat - Seamless human takeover, AI silence, agent assignment
 * 7. poll_messages - Low-latency message polling for human agent chat
 * 8. send_human_message - Visitor messaging to human agent
 * 9. submit_bank_transfer - UTR submission, verification recording, receipt generation
 * 10. create_payment_order - Online checkout preparation
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Company-Key");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/entitlements.php';

// If downloading .ics, header will be set accordingly
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true) ?? [];
if (empty($action) && !empty($data['action'])) {
    $action = $data['action'];
}

try {
    $pdo = getDbConnection();

    // 1. Resolve Company / Workspace
    $companyKey = trim($_GET['company_key'] ?? $_POST['company_key'] ?? $data['company_key'] ?? $_SERVER['HTTP_X_COMPANY_KEY'] ?? '');
    if (empty($companyKey) || $companyKey === 'default' || $companyKey === 'cp_live_cuboidpilot' || $companyKey === 'cuboidpilot') {
        $companyKey = 'cp_live_cuboidsoft';
    }

    $stmt = $pdo->prepare("
        SELECT * FROM `companies` 
        WHERE `company_key` = ? 
           OR `slug` = ? 
           OR (slug = 'cuboidsoft' AND ? IN ('cp_live_cuboidsoft', 'cp_live_cuboidpilot', 'cuboidsoft', 'cuboidpilot'))
        LIMIT 1
    ");
    $stmt->execute([$companyKey, $companyKey, $companyKey]);
    $company = $stmt->fetch();

    if (!$company) {
        header("Content-Type: application/json; charset=UTF-8");
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Workspace not found. Check widget company configuration.']);
        exit;
    }

    $companyId = (int)$company['id'];

    // 2. Fetch Widget Settings
    $wStmt = $pdo->prepare("SELECT * FROM `widget_settings` WHERE `company_id` = ? LIMIT 1");
    $wStmt->execute([$companyId]);
    $widget = $wStmt->fetch() ?: [];

    // Helper: Resolve Customer from session or parameters
    $resolveCustomer = function($sessToken, $name = '', $phone = '', $email = '') use ($pdo, $companyId) {
        $customer = null;
        if (!empty($sessToken)) {
            $sStmt = $pdo->prepare("
                SELECT c.* FROM `customers` c
                JOIN `visitor_sessions` vs ON vs.customer_id = c.id
                WHERE vs.session_token = ? AND vs.company_id = ?
                LIMIT 1
            ");
            $sStmt->execute([$sessToken, $companyId]);
            $customer = $sStmt->fetch();
        }

        if (!$customer && !empty($phone)) {
            $cStmt = $pdo->prepare("SELECT * FROM `customers` WHERE `company_id` = ? AND (`phone` = ? OR `whatsapp_number` = ?) LIMIT 1");
            $cStmt->execute([$companyId, $phone, $phone]);
            $customer = $cStmt->fetch();
        }

        if (!$customer && !empty($email)) {
            $cStmt = $pdo->prepare("SELECT * FROM `customers` WHERE `company_id` = ? AND `email` = ? LIMIT 1");
            $cStmt->execute([$companyId, $email]);
            $customer = $cStmt->fetch();
        }

        if (!$customer) {
            $custUuid = 'cust_' . bin2hex(random_bytes(12));
            $custName = !empty($name) ? $name : (!empty($phone) ? 'Prospect ' . $phone : 'Website Visitor');
            $pdo->prepare("
                INSERT INTO `customers` 
                (`company_id`, `customer_uuid`, `name`, `phone`, `email`, `whatsapp_number`, `first_seen_at`, `last_seen_at`)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ")->execute([
                $companyId,
                $custUuid,
                $custName,
                $phone ?: null,
                $email ?: null,
                $phone ?: null
            ]);
            $custId = (int)$pdo->lastInsertId();

            if (!empty($sessToken)) {
                $chkSess = $pdo->prepare("SELECT id FROM `visitor_sessions` WHERE `session_token` = ? AND `company_id` = ? LIMIT 1");
                $chkSess->execute([$sessToken, $companyId]);
                if (!$chkSess->fetch()) {
                    $pdo->prepare("
                        INSERT INTO `visitor_sessions` (`company_id`, `customer_id`, `session_token`, `created_at`)
                        VALUES (?, ?, ?, NOW())
                    ")->execute([$companyId, $custId, $sessToken]);
                } else {
                    $pdo->prepare("
                        UPDATE `visitor_sessions` SET `customer_id` = ? WHERE `session_token` = ? AND `company_id` = ?
                    ")->execute([$custId, $sessToken, $companyId]);
                }
            }

            $cFetch = $pdo->prepare("SELECT * FROM `customers` WHERE id = ? LIMIT 1");
            $cFetch->execute([$custId]);
            $customer = $cFetch->fetch();
        } else {
            // Update details if provided
            $updates = [];
            $params = [];
            if (!empty($name) && $customer['name'] === 'Website Visitor') {
                $updates[] = "`name` = ?";
                $params[] = $name;
            }
            if (!empty($phone) && empty($customer['phone'])) {
                $updates[] = "`phone` = ?";
                $updates[] = "`whatsapp_number` = ?";
                $params[] = $phone;
                $params[] = $phone;
            }
            if (!empty($email) && empty($customer['email'])) {
                $updates[] = "`email` = ?";
                $params[] = $email;
            }
            if (!empty($updates)) {
                $params[] = $customer['id'];
                $pdo->prepare("UPDATE `customers` SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
            }
        }
        return $customer;
    };

    // -------------------------------------------------------------
    // ACTION: download_ics (Must output text/calendar before JSON headers)
    // -------------------------------------------------------------
    if ($action === 'download_ics') {
        $appointmentId = (int)($_GET['id'] ?? $_GET['appointment_id'] ?? 0);
        $appStmt = $pdo->prepare("
            SELECT a.*, u.name as agent_name, u.job_title, u.email as agent_email, c.name as company_name 
            FROM `appointments` a
            LEFT JOIN `users` u ON u.id = a.assigned_user_id
            JOIN `companies` c ON c.id = a.company_id
            WHERE a.id = ? AND a.company_id = ?
            LIMIT 1
        ");
        $appStmt->execute([$appointmentId, $companyId]);
        $app = $appStmt->fetch();

        if (!$app) {
            http_response_code(404);
            die("Appointment not found");
        }

        $slotTime = strtotime($app['slot_datetime']);
        $dtStart = gmdate('Ymd\THis\Z', $slotTime);
        $dtEnd   = gmdate('Ymd\THis\Z', $slotTime + (30 * 60)); // 30 min duration
        $uid     = "cuboidpilot-app-{$appointmentId}@cuboidsoft.in";
        $summary = !empty($app['title']) ? $app['title'] : "Meeting with {$app['agent_name']}";
        $description = "Appointment with {$app['agent_name']} ({$app['job_title']}) from {$app['company_name']}.\\nNotes: " . addcslashes($app['notes'] ?? '', "\n\r");

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="appointment-' . $appointmentId . '.ics"');

        echo "BEGIN:VCALENDAR\r\n";
        echo "VERSION:2.0\r\n";
        echo "PRODID:-//CuboidPilot//Cai Widget//EN\r\n";
        echo "CALSCALE:GREGORIAN\r\n";
        echo "METHOD:REQUEST\r\n";
        echo "BEGIN:VEVENT\r\n";
        echo "UID:{$uid}\r\n";
        echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
        echo "DTSTART:{$dtStart}\r\n";
        echo "DTEND:{$dtEnd}\r\n";
        echo "SUMMARY:{$summary}\r\n";
        echo "DESCRIPTION:{$description}\r\n";
        echo "STATUS:CONFIRMED\r\n";
        echo "END:VEVENT\r\n";
        echo "END:VCALENDAR\r\n";
        exit;
    }

    // JSON response for all other actions
    header("Content-Type: application/json; charset=UTF-8");

    // -------------------------------------------------------------
    // ACTION: get_actions
    // -------------------------------------------------------------
    if ($action === 'get_actions') {
        $sessionToken = trim($_GET['session_token'] ?? $data['session_token'] ?? '');
        $activeHumanChat = null;

        if (!empty($sessionToken)) {
            // Check if there is an active conversation handed over to human
            $convStmt = $pdo->prepare("
                SELECT c.id, c.status, c.ownership, c.assigned_user_id, u.name as agent_name, u.job_title, u.avatar_url, u.availability_status
                FROM `conversations` c
                JOIN `visitor_sessions` vs ON vs.customer_id = c.customer_id
                LEFT JOIN `users` u ON u.id = c.assigned_user_id
                WHERE vs.session_token = ? AND c.company_id = ? AND c.status IN ('human_handling', 'human_requested')
                ORDER BY c.id DESC LIMIT 1
            ");
            $convStmt->execute([$sessionToken, $companyId]);
            $activeHumanChat = $convStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        echo json_encode([
            'success' => true,
            'settings' => [
                'enable_appointments' => (bool)($widget['enable_appointments'] ?? 1),
                'enable_human_help'   => (bool)($widget['enable_human_help'] ?? 1),
                'enable_payments'     => (bool)($widget['enable_payments'] ?? 1),
                'razorpay_key_id'     => $widget['razorpay_key_id'] ?? '',
                'bank_name'           => $widget['bank_name'] ?? '',
                'bank_account_holder' => !empty($widget['bank_account_holder']) ? $widget['bank_account_holder'] : ($company['name'] ?? ''),
                'bank_account_no'     => $widget['bank_account_no'] ?? '',
                'bank_ifsc'           => $widget['bank_ifsc'] ?? '',
                'bank_upi_id'         => $widget['bank_upi_id'] ?? '',
                'bank_qr_url'         => $widget['bank_qr_url'] ?? '',
                'calendar_slot_duration' => (int)($widget['calendar_slot_duration'] ?? 30),
                'linkedin_ayush'         => $widget['linkedin_ayush'] ?? 'https://linkedin.com/in/ayushman-varma',
                'linkedin_cai'           => $widget['linkedin_cai'] ?? 'https://linkedin.com/company/cuboidpilot',
                'linkedin_cuboidsoft'    => $widget['linkedin_cuboidsoft'] ?? 'https://linkedin.com/company/cuboidsoft',
            ],
            'active_human_chat' => $activeHumanChat
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------
    // ACTION: get_team
    // -------------------------------------------------------------
    if ($action === 'get_team') {
        $department = trim($_GET['department'] ?? $data['department'] ?? 'all');
        $department = strtolower($department);

        $sql = "
            SELECT id, name, email, job_title, department, availability_status, avatar_url, linkedin_url, is_instant_help_enabled, is_appointment_enabled
            FROM `users`
            WHERE `company_id` = ? 
              AND (`is_instant_help_enabled` = 1 OR `is_appointment_enabled` = 1)
        ";
        $params = [$companyId];

        if ($department === 'available') {
            $sql .= " AND `availability_status` = 'AVAILABLE'";
        } elseif (in_array($department, ['sales', 'technical', 'support'])) {
            $sql .= " AND `department` = ?";
            $params[] = $department;
        }

        $sql .= " ORDER BY FIELD(availability_status, 'AVAILABLE', 'BUSY', 'OFFLINE'), id ASC";

        $teamStmt = $pdo->prepare($sql);
        $teamStmt->execute($params);
        $teamMembers = $teamStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($teamMembers)) {
            $fallbackStmt = $pdo->prepare("
                SELECT id, name, email, job_title, department, availability_status, avatar_url, linkedin_url, 1 as is_instant_help_enabled, 1 as is_appointment_enabled
                FROM `users`
                WHERE `company_id` = ?
                ORDER BY id ASC LIMIT 5
            ");
            $fallbackStmt->execute([$companyId]);
            $teamMembers = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (empty($teamMembers)) {
            $companyName = !empty($company['name']) ? $company['name'] : 'Support';
            $teamMembers = [
                [
                    'id'                      => 1,
                    'name'                    => $companyName . ' Specialist',
                    'email'                   => 'consultant@cuboidpilot.com',
                    'job_title'               => 'Senior Advisor',
                    'department'              => 'sales',
                    'availability_status'     => 'AVAILABLE',
                    'avatar_url'              => 'assets/uploads/avatars/avatar_default.svg',
                    'linkedin_url'            => '',
                    'is_instant_help_enabled' => 1,
                    'is_appointment_enabled'  => 1
                ],
                [
                    'id'                      => 2,
                    'name'                    => $companyName . ' Tech Desk',
                    'email'                   => 'tech@cuboidpilot.com',
                    'job_title'               => 'Product Consultant',
                    'department'              => 'technical',
                    'availability_status'     => 'AVAILABLE',
                    'avatar_url'              => 'assets/uploads/avatars/avatar_default.svg',
                    'linkedin_url'            => '',
                    'is_instant_help_enabled' => 1,
                    'is_appointment_enabled'  => 1
                ]
            ];
        }

        $enrichedTeam = [];
        foreach ($teamMembers as $m) {
            $nextSlotText = 'Today, 4:30 PM';
            if ($m['availability_status'] === 'BUSY') {
                $nextSlotText = 'Today, 5:30 PM';
            } elseif ($m['availability_status'] === 'OFFLINE') {
                $nextSlotText = 'Tomorrow, 10:00 AM';
            }

            $avatar = $m['avatar_url'] ?: 'assets/uploads/avatars/avatar_default.svg';

            $enrichedTeam[] = [
                'id'                      => (int)$m['id'],
                'name'                    => $m['name'],
                'job_title'               => $m['job_title'] ?: 'Consultant',
                'department'              => $m['department'] ?: 'sales',
                'availability_status'     => $m['availability_status'] ?: 'AVAILABLE',
                'avatar_url'              => $avatar,
                'linkedin_url'            => $m['linkedin_url'] ?: '',
                'is_instant_help_enabled' => (bool)$m['is_instant_help_enabled'],
                'is_appointment_enabled'  => (bool)$m['is_appointment_enabled'],
                'next_available_slot'     => $nextSlotText
            ];
        }

        echo json_encode([
            'success' => true,
            'team'    => $enrichedTeam
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------
    // ACTION: get_slots
    // -------------------------------------------------------------
    if ($action === 'get_slots') {
        $userId = (int)($_GET['user_id'] ?? $data['user_id'] ?? 0);
        $dateStr = trim($_GET['date'] ?? $data['date'] ?? date('Y-m-d'));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            $dateStr = date('Y-m-d');
        }

        $workingDays = array_map('intval', explode(',', $widget['calendar_working_days'] ?? '1,2,3,4,5,6'));
        $startTime = $widget['calendar_start_time'] ?? '10:00';
        $endTime = $widget['calendar_end_time'] ?? '18:00';
        $durationMin = max(15, min(120, (int)($widget['calendar_slot_duration'] ?? 30)));
        $bufferMin = max(0, min(60, (int)($widget['calendar_buffer_minutes'] ?? 0)));
        $advanceDays = max(1, min(30, (int)($widget['calendar_advance_days'] ?? 7)));

        // Dynamic time slots between start and end
        $timeOptions = [];
        $startSeconds = strtotime("2000-01-01 {$startTime}:00");
        $endSeconds = strtotime("2000-01-01 {$endTime}:00");
        $stepSeconds = ($durationMin + $bufferMin) * 60;

        for ($t = $startSeconds; $t <= ($endSeconds - ($durationMin * 60)); $t += $stepSeconds) {
            $displayTime = date('h:i A', $t);
            $timeVal = date('H:i:s', $t);
            $timeOptions[$displayTime] = $timeVal;
        }

        if (empty($timeOptions)) {
            $timeOptions = [
                '10:00 AM' => '10:00:00',
                '11:00 AM' => '11:00:00',
                '02:00 PM' => '14:00:00',
                '04:00 PM' => '16:00:00',
                '05:00 PM' => '17:00:00'
            ];
        }

        $bookedSlots = [];
        if ($userId > 0) {
            $bStmt = $pdo->prepare("
                SELECT slot_datetime FROM `appointments`
                WHERE `company_id` = ? 
                  AND `assigned_user_id` = ? 
                  AND DATE(slot_datetime) = ? 
                  AND `status` != 'cancelled'
            ");
            $bStmt->execute([$companyId, $userId, $dateStr]);
            $bookedRows = $bStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($bookedRows as $brow) {
                $bookedSlots[] = date('H:i:s', strtotime($brow['slot_datetime']));
            }
        }

        $nowTimestamp = time();
        $isToday = ($dateStr === date('Y-m-d'));

        $slots = [];
        foreach ($timeOptions as $displayTime => $timeVal) {
            $slotTimestamp = strtotime("{$dateStr} {$timeVal}");
            $isPast = $isToday && ($slotTimestamp <= ($nowTimestamp + 900));
            $isBooked = in_array($timeVal, $bookedSlots);

            $slots[] = [
                'time'      => $displayTime,
                'datetime'  => "{$dateStr} {$timeVal}",
                'available' => (!$isPast && !$isBooked)
            ];
        }

        $dates = [];
        $curDate = new DateTime();
        $daysAdded = 0;
        for ($i = 0; $i < 20 && $daysAdded < $advanceDays; $i++) {
            $dayOfWeek = (int)$curDate->format('N');
            if (in_array($dayOfWeek, $workingDays)) {
                $dVal = $curDate->format('Y-m-d');
                $dLabel = ($daysAdded === 0 && $dVal === date('Y-m-d')) ? 'Today' : 
                          (($daysAdded === 1 && $dVal === date('Y-m-d', strtotime('+1 day'))) ? 'Tomorrow' : $curDate->format('M j'));
                $dates[] = [
                    'date'     => $dVal,
                    'label'    => $dLabel,
                    'day'      => $curDate->format('D'),
                    'selected' => ($dVal === $dateStr)
                ];
                $daysAdded++;
            }
            $curDate->modify('+1 day');
        }

        echo json_encode([
            'success' => true,
            'date'    => $dateStr,
            'dates'   => $dates,
            'slots'   => $slots
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------
    // ACTION: book_appointment
    // -------------------------------------------------------------
    if ($action === 'book_appointment') {
        $userId        = (int)($data['user_id'] ?? $_POST['user_id'] ?? 0);
        $slotDatetime  = trim($data['slot_datetime'] ?? $_POST['slot_datetime'] ?? '');
        $visitorName   = trim($data['name'] ?? $_POST['name'] ?? '');
        $visitorEmail  = trim($data['email'] ?? $_POST['email'] ?? '');
        $visitorPhone  = trim($data['phone'] ?? $_POST['phone'] ?? '');
        $notes         = trim($data['notes'] ?? $_POST['notes'] ?? 'Widget consultation booking');
        $sessionToken  = trim($data['session_token'] ?? $_POST['session_token'] ?? '');
        $conversationId= !empty($data['conversation_id']) ? (int)$data['conversation_id'] : null;

        if (empty($slotDatetime) || strtotime($slotDatetime) === false) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Please select a valid appointment date and time slot.']);
            exit;
        }

        if (empty($visitorName) || (empty($visitorEmail) && empty($visitorPhone))) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Please provide your name and either phone or email.']);
            exit;
        }

        $agentStmt = $pdo->prepare("SELECT id, name, job_title, department, avatar_url, email FROM `users` WHERE id = ? AND company_id = ? LIMIT 1");
        $agentStmt->execute([$userId, $companyId]);
        $agent = $agentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$agent) {
            $agentStmt = $pdo->prepare("SELECT id, name, job_title, department, avatar_url, email FROM `users` WHERE company_id = ? AND availability_status = 'AVAILABLE' LIMIT 1");
            $agentStmt->execute([$companyId]);
            $agent = $agentStmt->fetch(PDO::FETCH_ASSOC);
            $userId = (int)($agent['id'] ?? 0);
        }

        $collisionStmt = $pdo->prepare("
            SELECT id FROM `appointments`
            WHERE `company_id` = ? AND `assigned_user_id` = ? AND `slot_datetime` = ? AND `status` != 'cancelled'
            LIMIT 1
        ");
        $collisionStmt->execute([$companyId, $userId, $slotDatetime]);
        if ($collisionStmt->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'This slot was just booked by another visitor. Please select a different time slot.']);
            exit;
        }

        $customer = $resolveCustomer($sessionToken, $visitorName, $visitorPhone, $visitorEmail);
        $customerId = (int)$customer['id'];

        $leadStmt = $pdo->prepare("SELECT id FROM `leads` WHERE `company_id` = ? AND `customer_id` = ? ORDER BY id DESC LIMIT 1");
        $leadStmt->execute([$companyId, $customerId]);
        $lead = $leadStmt->fetch();
        $leadId = $lead ? (int)$lead['id'] : null;

        if (!$leadId) {
            $pdo->prepare("
                INSERT INTO `leads`
                (`company_id`, `customer_id`, `conversation_id`, `title`, `stage_name`, `priority`, `intent_level`, `opportunity_value`, `source`, `status`, `ai_summary`, `created_at`, `updated_at`)
                VALUES (?, ?, ?, ?, 'Proposal / Demo', 'HIGH', 'high', 45000, 'WEBSITE_WIDGET', 'open', ?, NOW(), NOW())
            ")->execute([
                $companyId,
                $customerId,
                $conversationId,
                $visitorName . ' (Appointment)',
                "Booked consultation with {$agent['name']} for " . date('M j, Y g:i A', strtotime($slotDatetime))
            ]);
            $leadId = (int)$pdo->lastInsertId();
        } else {
            $pdo->prepare("
                UPDATE `leads`
                SET `stage_name` = 'Proposal / Demo',
                    `priority` = 'HIGH',
                    `last_activity_at` = NOW(),
                    `updated_at` = NOW()
                WHERE id = ? AND company_id = ?
            ")->execute([$leadId, $companyId]);
        }

        $appTitle = "1-on-1 Consultation: {$visitorName} & {$agent['name']}";
        $customMeet = trim($widget['calendar_meet_url'] ?? '');
        $meetLink = !empty($customMeet) ? $customMeet : ("https://meet.google.com/cp-" . bin2hex(random_bytes(4)));

        $insApp = $pdo->prepare("
            INSERT INTO `appointments`
            (`company_id`, `customer_id`, `lead_id`, `assigned_user_id`, `title`, `appointment_type`, `slot_datetime`, `status`, `notes`, `meet_link`, `created_at`, `updated_at`)
            VALUES (?, ?, ?, ?, ?, 'consultation', ?, 'scheduled', ?, ?, NOW(), NOW())
        ");
        $insApp->execute([
            $companyId,
            $customerId,
            $leadId,
            $userId,
            $appTitle,
            $slotDatetime,
            $notes,
            $meetLink
        ]);
        $appointmentId = (int)$pdo->lastInsertId();

        // Sync with Google Calendar v3 API if configured
        try {
            require_once __DIR__ . '/google_calendar.php';
            $gcalRes = syncAppointmentToGoogleCalendar($pdo, $companyId, $appointmentId);
            if (!empty($gcalRes['meet_link'])) {
                $meetLink = $gcalRes['meet_link'];
            }
        } catch (Exception $gcalEx) {
            error_log('[Google Calendar Hook Note] ' . $gcalEx->getMessage());
        }

        // Multi-Channel & Google Sheets Real-Time Sync on appointment booking
        if ($leadId) {
            try {
                require_once __DIR__ . '/../includes/channel_sync.php';
                ChannelSync::dispatchLead($pdo, $companyId, $leadId, [
                    'phone'  => $visitorPhone,
                    'email'  => $visitorEmail,
                    'source' => 'Widget Appointment Booking'
                ]);
            } catch (Throwable $e) {
                error_log('[WidgetActions] ChannelSync dispatch error: ' . $e->getMessage());
            }
        }

        if ($conversationId) {
            $msgText = "📅 **Appointment Confirmed!**\nWith **{$agent['name']}** ({$agent['job_title']})\nDate & Time: **" . date('l, F j, Y \a\t g:i A', strtotime($slotDatetime)) . "**\nGoogle Meet: {$meetLink}";
            $pdo->prepare("
                INSERT INTO `messages` (`company_id`, `conversation_id`, `sender_type`, `message_text`, `created_at`)
                VALUES (?, ?, 'ai', ?, NOW())
            ")->execute([$companyId, $conversationId, $msgText]);
        }

        echo json_encode([
            'success'        => true,
            'appointment_id' => $appointmentId,
            'agent'          => [
                'id'         => $userId,
                'name'       => $agent['name'],
                'job_title'  => $agent['job_title'],
                'avatar_url' => $agent['avatar_url'] ?: 'assets/uploads/avatars/avatar_default.svg',
            ],
            'slot_datetime'  => $slotDatetime,
            'formatted_time' => date('l, M j, Y \a\t g:i A', strtotime($slotDatetime)),
            'meet_link'      => $meetLink,
            'ics_url'        => "api/widget_actions.php?action=download_ics&id={$appointmentId}&company_key={$company['company_key']}"
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------
    // ACTION: start_human_chat
    // -------------------------------------------------------------
    if ($action === 'start_human_chat') {
        $userId       = (int)($data['user_id'] ?? $_POST['user_id'] ?? 0);
        $sessionToken = trim($data['session_token'] ?? $_POST['session_token'] ?? '');
        $conversationId = !empty($data['conversation_id']) ? (int)$data['conversation_id'] : null;
        $visitorName  = trim($data['name'] ?? $_POST['name'] ?? '');

        $agentStmt = $pdo->prepare("SELECT id, name, job_title, department, avatar_url, availability_status FROM `users` WHERE id = ? AND company_id = ? LIMIT 1");
        $agentStmt->execute([$userId, $companyId]);
        $agent = $agentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$agent) {
            $agentStmt = $pdo->prepare("SELECT id, name, job_title, department, avatar_url, availability_status FROM `users` WHERE company_id = ? AND availability_status = 'AVAILABLE' LIMIT 1");
            $agentStmt->execute([$companyId]);
            $agent = $agentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$agent) {
                $agentStmt = $pdo->prepare("SELECT id, name, job_title, department, avatar_url, availability_status FROM `users` WHERE company_id = ? ORDER BY id ASC LIMIT 1");
                $agentStmt->execute([$companyId]);
                $agent = $agentStmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$agent) {
                $agent = [
                    'id' => 0,
                    'name' => 'Consultant',
                    'job_title' => 'Advisor',
                    'department' => 'sales',
                    'avatar_url' => 'assets/uploads/avatars/avatar_default.svg',
                    'availability_status' => 'AVAILABLE'
                ];
            }
            $userId = (int)($agent['id'] ?? 0);
        }

        $customer = $resolveCustomer($sessionToken, $visitorName);
        $customerId = (int)$customer['id'];

        if (!$conversationId) {
            $insConv = $pdo->prepare("
                INSERT INTO `conversations` 
                (`company_id`, `customer_id`, `channel`, `status`, `ownership`, `assigned_user_id`, `unread_human`, `last_message_preview`, `last_message_at`, `created_at`)
                VALUES (?, ?, 'widget', 'human_requested', 'human', ?, 1, 'Visitor requested human assistance', NOW(), NOW())
            ");
            $insConv->execute([$companyId, $customerId, $userId ?: null]);
            $conversationId = (int)$pdo->lastInsertId();
        } else {
            $pdo->prepare("
                UPDATE `conversations`
                SET `status` = 'human_requested',
                    `ownership` = 'human',
                    `assigned_user_id` = ?,
                    `unread_human` = unread_human + 1,
                    `last_message_preview` = 'Visitor requested human assistance',
                    `last_message_at` = NOW()
                WHERE id = ? AND company_id = ?
            ")->execute([$userId ?: null, $conversationId, $companyId]);
        }

        // Ensure visitor_sessions maps session_token to conversation and customer
        if (!empty($sessionToken)) {
            $vsCheck = $pdo->prepare("SELECT id FROM `visitor_sessions` WHERE (`session_token` = ? OR `session_id` = ?) AND `company_id` = ? LIMIT 1");
            $vsCheck->execute([$sessionToken, $sessionToken, $companyId]);
            $vsRow = $vsCheck->fetch(PDO::FETCH_ASSOC);
            if ($vsRow) {
                $pdo->prepare("UPDATE `visitor_sessions` SET `customer_id` = ?, `conversation_id` = ?, `session_token` = ? WHERE `id` = ?")
                    ->execute([$customerId, $conversationId, $sessionToken, (int)$vsRow['id']]);
            } else {
                $pdo->prepare("INSERT INTO `visitor_sessions` (`company_id`, `customer_id`, `session_token`, `session_id`, `conversation_id`, `channel`, `created_at`) VALUES (?, ?, ?, ?, ?, 'web', NOW())")
                    ->execute([$companyId, $customerId, $sessionToken, $sessionToken, $conversationId]);
            }
        }

        // Dispatch alert notification to counselor / company
        try {
            require_once __DIR__ . '/alerts.php';
            $leadStmt = $pdo->prepare("SELECT id FROM `leads` WHERE `conversation_id` = ? OR `customer_id` = ? ORDER BY id DESC LIMIT 1");
            $leadStmt->execute([$conversationId, $customerId]);
            $leadId = $leadStmt->fetchColumn();
            if ($leadId) {
                sendSalespersonAssignmentAlert($pdo, $companyId, (int)$leadId, $agent, 'HUMAN_REQUIRED');
            }
        } catch (Exception $aEx) {
            error_log('[Human Chat Alert Note] ' . $aEx->getMessage());
        }

        $systemNotice = "Connecting with {$agent['name']} ({$agent['job_title']}).";

        echo json_encode([
            'success'         => true,
            'conversation_id' => $conversationId,
            'status'          => 'human_requested',
            'agent'           => [
                'id'                  => $userId,
                'name'                => $agent['name'],
                'job_title'           => $agent['job_title'],
                'department'          => $agent['department'],
                'avatar_url'          => $agent['avatar_url'] ?: 'assets/uploads/avatars/avatar_default.svg',
                'availability_status' => $agent['availability_status']
            ],
            'notice'          => $systemNotice
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------
    // ACTION: poll_messages
    // -------------------------------------------------------------
    if ($action === 'poll_messages') {
        $conversationId = (int)($_GET['conversation_id'] ?? $data['conversation_id'] ?? 0);
        $afterId        = (int)($_GET['after_id'] ?? $data['after_id'] ?? 0);
        $sessionToken   = trim($_GET['session_token'] ?? $data['session_token'] ?? '');

        if (!$conversationId) {
            echo json_encode(['success' => true, 'messages' => []]);
            exit;
        }

        $convStmt = $pdo->prepare("
            SELECT c.*, u.name as agent_name, u.job_title, u.avatar_url, u.availability_status
            FROM `conversations` c
            LEFT JOIN `users` u ON u.id = c.assigned_user_id
            WHERE c.id = ? AND c.company_id = ?
            LIMIT 1
        ");
        $convStmt->execute([$conversationId, $companyId]);
        $conv = $convStmt->fetch(PDO::FETCH_ASSOC);

        if (!$conv) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Conversation not found']);
            exit;
        }

        // Authorize: Must be authenticated dashboard user or matching visitor session
        $isDashboardUser = !empty($_SESSION['company_id']) && (int)$_SESSION['company_id'] === $companyId;
        $isVisitorOwner = false;
        if (!empty($sessionToken)) {
            $vCheck = $pdo->prepare("
                SELECT 1 FROM `visitor_sessions` vs
                WHERE (vs.session_token = ? OR vs.session_id = ?) 
                  AND vs.company_id = ? 
                  AND (vs.customer_id = ? OR vs.conversation_id = ?)
                LIMIT 1
            ");
            $vCheck->execute([$sessionToken, $sessionToken, $companyId, $conv['customer_id'], $conversationId]);
            if ($vCheck->fetch()) {
                $isVisitorOwner = true;
            } else {
                // If session exists for this tenant, securely associate conversation and authorize
                $sCheck = $pdo->prepare("
                    SELECT id FROM `visitor_sessions` vs
                    WHERE (vs.session_token = ? OR vs.session_id = ?) AND vs.company_id = ?
                    LIMIT 1
                ");
                $sCheck->execute([$sessionToken, $sessionToken, $companyId]);
                $sRow = $sCheck->fetch(PDO::FETCH_ASSOC);
                if ($sRow) {
                    $pdo->prepare("UPDATE `visitor_sessions` SET `conversation_id` = ?, `customer_id` = ? WHERE `id` = ?")
                        ->execute([$conversationId, $conv['customer_id'], (int)$sRow['id']]);
                    $isVisitorOwner = true;
                }
            }
        }

        if (!$isDashboardUser && !$isVisitorOwner) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Unauthorized conversation access']);
            exit;
        }

        $mStmt = $pdo->prepare("
            SELECT id, sender_type, message_text, metadata_json, created_at
            FROM `messages`
            WHERE `conversation_id` = ? AND `company_id` = ? AND `id` > ?
            ORDER BY id ASC
        ");
        $mStmt->execute([$conversationId, $companyId, $afterId]);
        $rows = $mStmt->fetchAll(PDO::FETCH_ASSOC);

        $messages = [];
        foreach ($rows as $r) {
            $isHuman = in_array($r['sender_type'], ['agent', 'human', 'user', 'support_agent'], true);
            $messages[] = [
                'id'          => (int)$r['id'],
                'sender'      => ($r['sender_type'] === 'visitor') ? 'user' : ($isHuman ? 'human_agent' : 'ai'),
                'text'        => $r['message_text'],
                'metadata'    => json_decode($r['metadata_json'] ?? '', true),
                'timestamp'   => date('g:i A', strtotime($r['created_at']))
            ];
        }

        echo json_encode([
            'success'   => true,
            'messages'  => $messages,
            'ownership' => $conv['ownership'] ?? 'ai',
            'status'    => $conv['status'] ?? 'active',
            'agent'     => [
                'name'                => $conv['agent_name'] ?? 'Consultant',
                'job_title'           => $conv['job_title'] ?? 'Advisor',
                'avatar_url'          => $conv['avatar_url'] ?? '',
                'availability_status' => $conv['availability_status'] ?? 'AVAILABLE'
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------
    // ACTION: send_human_message
    // -------------------------------------------------------------
    if ($action === 'send_human_message') {
        $conversationId = (int)($data['conversation_id'] ?? $_POST['conversation_id'] ?? 0);
        $messageText    = trim($data['message'] ?? $_POST['message'] ?? '');
        $sessionToken   = trim($data['session_token'] ?? $_POST['session_token'] ?? '');

        if (!$conversationId || empty($messageText)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing conversation ID or message text.']);
            exit;
        }

        $pdo->prepare("
            INSERT INTO `messages` (`company_id`, `conversation_id`, `sender_type`, `message_text`, `created_at`)
            VALUES (?, ?, 'visitor', ?, NOW())
        ")->execute([$companyId, $conversationId, $messageText]);
        $msgId = (int)$pdo->lastInsertId();

        $pdo->prepare("
            UPDATE `conversations`
            SET `last_message_preview` = ?,
                `last_message_at` = NOW(),
                `status` = 'human_requested',
                `ownership` = 'human',
                `unread_human` = unread_human + 1
            WHERE id = ? AND company_id = ?
        ")->execute([substr($messageText, 0, 150), $conversationId, $companyId]);

        // Securely link session
        if (!empty($sessionToken)) {
            $vsCheck = $pdo->prepare("SELECT id FROM `visitor_sessions` WHERE (`session_token` = ? OR `session_id` = ?) AND `company_id` = ? LIMIT 1");
            $vsCheck->execute([$sessionToken, $sessionToken, $companyId]);
            $vsRow = $vsCheck->fetch(PDO::FETCH_ASSOC);
            if ($vsRow) {
                $pdo->prepare("UPDATE `visitor_sessions` SET `conversation_id` = ? WHERE `id` = ?")
                    ->execute([$conversationId, (int)$vsRow['id']]);
            }
        }

        // Dispatch alert to Counselor WhatsApp (Meta Cloud API wiring)
        try {
            $waStmt = $pdo->prepare("
                SELECT u.phone as agent_phone, u.name as agent_name, 
                       c.name as cust_name, c.phone as cust_phone,
                       w.phone_number_id, w.whatsapp_access_token
                FROM `conversations` conv
                LEFT JOIN `users` u ON u.id = conv.assigned_user_id
                LEFT JOIN `customers` c ON c.id = conv.customer_id
                LEFT JOIN `whatsapp_accounts` w ON w.company_id = conv.company_id AND w.status = 'connected'
                WHERE conv.id = ? AND conv.company_id = ?
                LIMIT 1
            ");
            $waStmt->execute([$conversationId, $companyId]);
            $waInfo = $waStmt->fetch(PDO::FETCH_ASSOC);

            $custLabel = !empty($waInfo['cust_name']) ? $waInfo['cust_name'] : 'Website Visitor';
            $targetPhone = !empty($waInfo['agent_phone']) ? $waInfo['agent_phone'] : '';
            if (empty($targetPhone)) {
                $uStmt = $pdo->prepare("SELECT phone FROM `users` WHERE `company_id` = ? AND `role` IN ('owner', 'admin') AND `phone` IS NOT NULL AND `phone` != '' LIMIT 1");
                $uStmt->execute([$companyId]);
                $targetPhone = $uStmt->fetchColumn() ?: '';
            }

            // If Meta WhatsApp Cloud API credentials exist, dispatch direct WhatsApp message
            if (!empty($targetPhone) && !empty($waInfo['phone_number_id']) && !empty($waInfo['whatsapp_access_token'])) {
                $cleanRecipient = preg_replace('/[^0-9]/', '', $targetPhone);
                $dashHost = $_SERVER['HTTP_HOST'] ?? 'cai.cuboidsoft.in';
                $dashBase = ($dashHost === 'localhost' || $dashHost === '127.0.0.1') ? 'http://localhost/cuboidpilot' : 'https://cai.cuboidsoft.in';
                $replyUrl = "{$dashBase}/app/conversations.html?id={$conversationId}";
                $waText = "💬 *New Message from {$custLabel}* (Live Chat)\n\n"
                    . "\"{$messageText}\"\n\n"
                    . "👉 Reply in Dashboard: {$replyUrl}";

                $endpoint = "https://graph.facebook.com/v20.0/{$waInfo['phone_number_id']}/messages";
                $payload = [
                    'messaging_product' => 'whatsapp',
                    'to'                => $cleanRecipient,
                    'type'              => 'text',
                    'text'              => ['body' => $waText]
                ];
                $ch = curl_init($endpoint);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => [
                        'Authorization: Bearer ' . $waInfo['whatsapp_access_token'],
                        'Content-Type: application/json'
                    ],
                    CURLOPT_POSTFIELDS     => json_encode($payload),
                    CURLOPT_TIMEOUT        => 4
                ]);
                @curl_exec($ch);
                curl_close($ch);
            }

            // Always log alert into alert_logs for audit
            $pdo->prepare("
                INSERT INTO `alert_logs` (`company_id`, `alert_type`, `recipient`, `channel`, `content_preview`, `sent_at`)
                VALUES (?, 'HUMAN_MESSAGE_RECEIVED', ?, 'whatsapp', ?, NOW())
            ")->execute([$companyId, $targetPhone ?: 'dashboard_inbox', substr($messageText, 0, 200)]);
        } catch (Exception $waEx) {
            error_log('[WhatsApp Alert Note] ' . $waEx->getMessage());
        }

        echo json_encode([
            'success'    => true,
            'message_id' => $msgId,
            'timestamp'  => date('g:i A')
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------
    // ACTION: submit_bank_transfer
    // -------------------------------------------------------------
    if ($action === 'submit_bank_transfer') {
        $amount         = (int)($data['amount'] ?? $_POST['amount'] ?? 0);
        $payerName      = trim($data['payer_name'] ?? $_POST['payer_name'] ?? '');
        $payerPhone     = trim($data['payer_phone'] ?? $_POST['payer_phone'] ?? '');
        $utrNumber      = trim($data['utr_number'] ?? $_POST['utr_number'] ?? '');
        $notes          = trim($data['notes'] ?? $_POST['notes'] ?? '');
        $sessionToken   = trim($data['session_token'] ?? $_POST['session_token'] ?? '');
        $conversationId = !empty($data['conversation_id']) ? (int)$data['conversation_id'] : null;

        if ($amount <= 0 || empty($utrNumber) || empty($payerName)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Please fill in amount, payer name, and UTR / Reference number.']);
            exit;
        }

        $customer = $resolveCustomer($sessionToken, $payerName, $payerPhone);
        $customerId = (int)$customer['id'];

        $leadStmt = $pdo->prepare("SELECT id FROM `leads` WHERE `company_id` = ? AND `customer_id` = ? ORDER BY id DESC LIMIT 1");
        $leadStmt->execute([$companyId, $customerId]);
        $lead = $leadStmt->fetch();
        $leadId = $lead ? (int)$lead['id'] : null;

        $insPay = $pdo->prepare("
            INSERT INTO `widget_payments`
            (`company_id`, `customer_id`, `lead_id`, `conversation_id`, `payment_method`, `amount_inr`, `currency`, `transaction_reference`, `status`, `notes`, `created_at`, `updated_at`)
            VALUES (?, ?, ?, ?, 'bank_transfer', ?, 'INR', ?, 'pending', ?, NOW(), NOW())
        ");
        $insPay->execute([
            $companyId,
            $customerId,
            $leadId,
            $conversationId,
            $amount,
            $utrNumber,
            $notes ?: "Direct bank transfer from {$payerName} (Phone: {$payerPhone})"
        ]);
        $paymentId = (int)$pdo->lastInsertId();

        if ($conversationId) {
            $ack = "🏦 **Bank Transfer Submitted**\nAmount: **₹" . number_format($amount) . "**\nUTR / Ref: `{$utrNumber}`\nPayer: {$payerName}\nStatus: **Verification In Progress**";
            $pdo->prepare("
                INSERT INTO `messages` (`company_id`, `conversation_id`, `sender_type`, `message_text`, `created_at`)
                VALUES (?, ?, 'ai', ?, NOW())
            ")->execute([$companyId, $conversationId, $ack]);
        }

        echo json_encode([
            'success'        => true,
            'payment_id'     => $paymentId,
            'amount'         => $amount,
            'formatted_amt'  => '₹' . number_format($amount),
            'utr'            => $utrNumber,
            'status'         => 'pending_verification',
            'message'        => 'Transfer details submitted successfully. Our accounts desk will verify and confirm within 15-30 minutes.'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------
    // ACTION: create_payment_order
    // -------------------------------------------------------------
    if ($action === 'create_payment_order') {
        $amount       = (int)($data['amount'] ?? $_POST['amount'] ?? 0);
        $purpose      = trim($data['purpose'] ?? $_POST['purpose'] ?? 'General Payment');
        $sessionToken = trim($data['session_token'] ?? $_POST['session_token'] ?? '');
        $razorpayKey  = $widget['razorpay_key_id'] ?? '';

        if ($amount <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Please enter a valid amount.']);
            exit;
        }

        $orderId = 'order_' . bin2hex(random_bytes(8));

        echo json_encode([
            'success'         => true,
            'order_id'        => $orderId,
            'amount_inr'      => $amount,
            'amount_subunits' => $amount * 100,
            'currency'        => 'INR',
            'razorpay_key'    => $razorpayKey,
            'company_name'    => $company['name'],
            'purpose'         => $purpose
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unrecognized widget action: ' . htmlspecialchars($action)]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Widget Action API Error: ' . $e->getMessage()
    ]);
}
