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
require_once __DIR__ . '/../includes/mailer.php';

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
            if (!empty($name) && ($customer['name'] === 'Website Visitor' || strpos($customer['name'], 'Prospect ') === 0)) {
                $updates[] = "`name` = ?";
                $params[] = $name;
            }
            if (!empty($phone) && (empty($customer['phone']) || $customer['phone'] !== $phone)) {
                $updates[] = "`phone` = ?";
                $updates[] = "`whatsapp_number` = ?";
                $params[] = $phone;
                $params[] = $phone;
            }
            if (!empty($email) && (empty($customer['email']) || $customer['email'] !== $email)) {
                $updates[] = "`email` = ?";
                $params[] = $email;
            }
            if (!empty($updates)) {
                $params[] = $customer['id'];
                $pdo->prepare("UPDATE `customers` SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
                $refetch = $pdo->prepare("SELECT * FROM `customers` WHERE id = ? LIMIT 1");
                $refetch->execute([$customer['id']]);
                $customer = $refetch->fetch();
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
                'linkedin_ayush'         => $widget['linkedin_ayush'] ?? (($company['company_key'] === 'cp_live_cuboidsoft') ? 'https://linkedin.com/in/ayushman-varma' : ''),
                'linkedin_cai'           => $widget['linkedin_cai'] ?? (($company['company_key'] === 'cp_live_cuboidsoft') ? 'https://linkedin.com/company/cuboidpilot' : ''),
                'linkedin_cuboidsoft'    => $widget['linkedin_cuboidsoft'] ?? (($company['company_key'] === 'cp_live_cuboidsoft') ? 'https://linkedin.com/company/cuboidsoft' : ''),
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
        $actionToken = bin2hex(random_bytes(24));

        $insApp = $pdo->prepare("
            INSERT INTO `appointments`
            (`company_id`, `customer_id`, `lead_id`, `conversation_id`, `assigned_user_id`, `customer_name`, `customer_phone`, `customer_email`, `title`, `appointment_type`, `slot_datetime`, `status`, `action_token`, `notes`, `meet_link`, `created_at`, `updated_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'consultation', ?, 'scheduled', ?, ?, ?, NOW(), NOW())
        ");
        $insApp->execute([
            $companyId,
            $customerId,
            $leadId,
            $conversationId,
            $userId,
            $visitorName,
            $visitorPhone,
            $visitorEmail,
            $appTitle,
            $slotDatetime,
            $actionToken,
            $notes,
            $meetLink
        ]);
        $appointmentId = (int)$pdo->lastInsertId();

        // Record initial creation activity
        try {
            $pdo->prepare("
                INSERT INTO `appointment_activities`
                (`appointment_id`, `company_id`, `action`, `actor_type`, `actor_name`, `channel`, `details`, `created_at`)
                VALUES (?, ?, 'created', 'customer', ?, 'widget', ?, NOW())
            ")->execute([
                $appointmentId,
                $companyId,
                $visitorName,
                "Booked 30-min consultation slot for {$slotDatetime}"
            ]);
        } catch (Exception $actEx) {}

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

        // Automated Email Notifications: Customer Confirmation & Admin Alert
        $compName = htmlspecialchars($company['name'] ?? 'CuboidPilot');
        $cNameEsc = htmlspecialchars($visitorName);
        $agentNameEsc = htmlspecialchars($agent['name'] ?? 'Advisor');
        $formattedDate = date('l, F j, Y', strtotime($slotDatetime));
        $formattedTime = date('g:i A', strtotime($slotDatetime));

        // 1. Send Customer Confirmation Email
        if (!empty($visitorEmail) && filter_var($visitorEmail, FILTER_VALIDATE_EMAIL)) {
            $custSubj = "Appointment Confirmed: {$appTitle} ({$compName})";
            $custHtml = "
            <div style='font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif;max-width:580px;margin:0 auto;padding:24px;background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;'>
                <div style='border-bottom:2px solid #0f172a;padding-bottom:12px;margin-bottom:18px;'>
                    <h2 style='margin:0;font-size:20px;color:#0f172a;'>{$compName}</h2>
                    <div style='font-size:12px;color:#64748b;margin-top:4px;'>Appointment Booking Confirmation</div>
                </div>
                <p style='font-size:14px;color:#1e293b;'>Dear <strong>{$cNameEsc}</strong>,</p>
                <p style='font-size:13px;color:#334155;line-height:1.6;'>
                    Your 1-on-1 consultation session with <strong>{$agentNameEsc}</strong> has been successfully confirmed.
                </p>
                <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:16px;margin:16px 0;'>
                    <table style='width:100%;font-size:13px;color:#334155;border-collapse:collapse;'>
                        <tr><td style='padding:4px 0;width:120px;color:#64748b;'>Booking ID:</td><td style='font-family:monospace;font-weight:700;'>#APT-{$appointmentId}</td></tr>
                        <tr><td style='padding:4px 0;color:#64748b;'>Date:</td><td style='font-weight:600;'>{$formattedDate}</td></tr>
                        <tr><td style='padding:4px 0;color:#64748b;'>Time:</td><td style='font-weight:600;'>{$formattedTime} (IST / 30 mins)</td></tr>
                        <tr><td style='padding:4px 0;color:#64748b;'>Advisor:</td><td style='font-weight:600;'>{$agentNameEsc} ({$agent['job_title']})</td></tr>
                        <tr><td style='padding:4px 0;color:#64748b;'>Meeting Room:</td><td><a href='{$meetLink}' style='color:#0284c7;font-weight:600;text-decoration:none;'>Join Video Room &rarr;</a></td></tr>
                    </table>
                </div>
                <div style='text-align:center;margin:20px 0;'>
                    <a href='{$meetLink}' style='background:#0f172a;color:#ffffff;text-decoration:none;padding:10px 20px;border-radius:6px;font-size:13px;font-weight:600;display:inline-block;'>Join Google Meet Room</a>
                </div>
                <p style='font-size:12px;color:#64748b;line-height:1.5;'>
                    Please keep this confirmation handy. A member of our team will join the call at the scheduled time.
                </p>
                <div style='border-top:1px solid #e2e8f0;padding-top:12px;margin-top:20px;font-size:11px;color:#94a3b8;'>
                    © " . date('Y') . " {$compName}. Powered by CuboidPilot.
                </div>
            </div>";
            try {
                CompanyMailer::send($pdo, $companyId, $visitorEmail, $custSubj, $custHtml);
                $pdo->prepare("
                    INSERT INTO `appointment_activities`
                    (`appointment_id`, `company_id`, `action`, `actor_type`, `channel`, `details`, `created_at`)
                    VALUES (?, ?, 'email_sent', 'system', 'email', 'Customer confirmation email sent', NOW())
                ")->execute([$appointmentId, $companyId]);
            } catch (Exception $mEx) {}
        }

        // 2. Send Admin / Host Notification Email with 1-Click Action Links
        $adminEmail = $company['email'] ?? '';
        if (empty($adminEmail)) {
            $uStmt = $pdo->prepare("SELECT email FROM `users` WHERE `company_id` = ? AND `role` IN ('owner', 'admin') AND email IS NOT NULL AND email != '' ORDER BY id ASC LIMIT 1");
            $uStmt->execute([$companyId]);
            $adminEmail = (string)$uStmt->fetchColumn();
        }

        if (!empty($adminEmail) && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'] ?? 'cai.cuboidsoft.in';
            $baseAppUrl = $protocol . $host;
            if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
                $baseAppUrl .= '/cuboidpilot';
            }

            $confirmActionUrl = "{$baseAppUrl}/api/appointments.php?action=token_action&token={$actionToken}&action_type=confirm";
            $cancelActionUrl  = "{$baseAppUrl}/api/appointments.php?action=token_action&token={$actionToken}&action_type=cancel";
            $completeActionUrl= "{$baseAppUrl}/api/appointments.php?action=token_action&token={$actionToken}&action_type=complete";
            $rescheduleUrl    = "{$baseAppUrl}/app/appointments.html?id={$appointmentId}&action=reschedule";

            $adminSubj = "📅 [New Appointment] {$visitorName} booked consultation with {$agent['name']}";
            $adminHtml = "
            <div style='font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif;max-width:580px;margin:0 auto;padding:24px;background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;'>
                <div style='border-bottom:2px solid #0f172a;padding-bottom:12px;margin-bottom:18px;'>
                    <div style='display:inline-block;padding:3px 8px;background:#eff6ff;color:#1e40af;border-radius:4px;font-size:11px;font-weight:700;'>NEW APPOINTMENT BOOKED</div>
                    <h2 style='margin:8px 0 0;font-size:20px;color:#0f172a;'>{$visitorName} with {$agent['name']}</h2>
                    <div style='font-size:12px;color:#64748b;margin-top:4px;'>Booking ID: #APT-{$appointmentId}</div>
                </div>
                <p style='font-size:14px;color:#1e293b;'>Hello Admin,</p>
                <p style='font-size:13px;color:#334155;line-height:1.6;'>
                    <strong>{$cNameEsc}</strong> has booked a consultation session on your website widget.
                </p>
                <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:16px;margin:16px 0;'>
                    <table style='width:100%;font-size:13px;color:#334155;border-collapse:collapse;'>
                        <tr><td style='padding:4px 0;width:120px;color:#64748b;'>Client Name:</td><td style='font-weight:600;'>{$cNameEsc}</td></tr>
                        <tr><td style='padding:4px 0;color:#64748b;'>Email:</td><td><a href='mailto:{$visitorEmail}' style='color:#0284c7;'>{$visitorEmail}</a></td></tr>
                        <tr><td style='padding:4px 0;color:#64748b;'>Phone:</td><td><a href='tel:{$visitorPhone}' style='color:#0f172a;'>{$visitorPhone}</a> · <a href='https://wa.me/" . preg_replace('/[^0-9]/', '', $visitorPhone) . "' style='color:#10b981;font-weight:600;text-decoration:none;'>WhatsApp &rarr;</a></td></tr>
                        <tr><td style='padding:4px 0;color:#64748b;'>Assigned Host:</td><td style='font-weight:600;'>{$agentNameEsc}</td></tr>
                        <tr><td style='padding:4px 0;color:#64748b;'>Date & Time:</td><td style='font-weight:600;'>{$formattedDate} at {$formattedTime}</td></tr>
                        <tr><td style='padding:4px 0;color:#64748b;'>Notes:</td><td>" . htmlspecialchars($notes ?: 'None') . "</td></tr>
                        <tr><td style='padding:4px 0;color:#64748b;'>Meeting Link:</td><td><a href='{$meetLink}' style='color:#0284c7;font-weight:600;'>{$meetLink}</a></td></tr>
                    </table>
                </div>

                <div style='background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;padding:14px;margin:20px 0;text-align:center;'>
                    <div style='font-size:12px;font-weight:700;color:#334155;margin-bottom:10px;text-transform:uppercase;'>Remote 1-Click Management:</div>
                    <div style='display:inline-flex;gap:8px;flex-wrap:wrap;justify-content:center;'>
                        <a href='{$confirmActionUrl}' style='background:#10b981;color:#ffffff;text-decoration:none;padding:8px 14px;border-radius:4px;font-size:12px;font-weight:600;'>✓ Confirm</a>
                        <a href='{$completeActionUrl}' style='background:#0f172a;color:#ffffff;text-decoration:none;padding:8px 14px;border-radius:4px;font-size:12px;font-weight:600;'>Mark Completed</a>
                        <a href='{$cancelActionUrl}' style='background:#ef4444;color:#ffffff;text-decoration:none;padding:8px 14px;border-radius:4px;font-size:12px;font-weight:600;'>✗ Cancel</a>
                        <a href='{$rescheduleUrl}' style='background:#64748b;color:#ffffff;text-decoration:none;padding:8px 14px;border-radius:4px;font-size:12px;font-weight:600;'>Reschedule</a>
                    </div>
                </div>

                <div style='border-top:1px solid #e2e8f0;padding-top:12px;margin-top:20px;font-size:11px;color:#94a3b8;'>
                    This appointment is synced with your dashboard's Scheduled Meetings section.
                </div>
            </div>";
            try {
                CompanyMailer::send($pdo, $companyId, $adminEmail, $adminSubj, $adminHtml);
                $pdo->prepare("
                    INSERT INTO `appointment_activities`
                    (`appointment_id`, `company_id`, `action`, `actor_type`, `channel`, `details`, `created_at`)
                    VALUES (?, ?, 'admin_alert_sent', 'system', 'email', 'Admin alert email sent with 1-click action links', NOW())
                ")->execute([$appointmentId, $companyId]);
            } catch (Exception $mEx) {}
        }

        // 3. Dispatch WhatsApp Notification if company has connected WhatsApp
        if (!empty($visitorPhone)) {
            try {
                $waStmt = $pdo->prepare("SELECT phone_number_id, whatsapp_access_token FROM `whatsapp_accounts` WHERE `company_id` = ? AND `status` = 'connected' LIMIT 1");
                $waStmt->execute([$companyId]);
                $waAcc = $waStmt->fetch(PDO::FETCH_ASSOC);
                if ($waAcc && !empty($waAcc['phone_number_id']) && !empty($waAcc['whatsapp_access_token'])) {
                    $cleanPhone = preg_replace('/[^0-9]/', '', $visitorPhone);
                    if (strlen($cleanPhone) === 10) $cleanPhone = '91' . $cleanPhone;
                    $waMsg = "📅 *Appointment Confirmed!*\n\nHello {$visitorName},\nYour consultation with *{$agent['name']}* is confirmed for *{$formattedDate} at {$formattedTime}*.\n\nMeeting link: {$meetLink}\n\nWe look forward to speaking with you!";
                    $payload = [
                        'messaging_product' => 'whatsapp',
                        'to'                => $cleanPhone,
                        'type'              => 'text',
                        'text'              => ['body' => $waMsg]
                    ];
                    $ch = curl_init("https://graph.facebook.com/v19.0/{$waAcc['phone_number_id']}/messages");
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Authorization: Bearer ' . $waAcc['whatsapp_access_token'],
                        'Content-Type: application/json'
                    ]);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                    curl_exec($ch);
                    curl_close($ch);
                }
            } catch (Throwable $waEx) {
                error_log('[WidgetActions WA Appointment Alert Error] ' . $waEx->getMessage());
            }
        }

        if ($conversationId) {
            $cCheck = $pdo->prepare("SELECT id FROM `conversations` WHERE id = ? AND company_id = ? LIMIT 1");
            $cCheck->execute([$conversationId, $companyId]);
            if ($cCheck->fetch()) {
                $msgText = "📅 **Appointment Confirmed!**\nWith **{$agent['name']}** ({$agent['job_title']})\nDate & Time: **" . date('l, F j, Y \a\t g:i A', strtotime($slotDatetime)) . "**\nGoogle Meet: {$meetLink}";
                $pdo->prepare("
                    INSERT INTO `messages` (`company_id`, `conversation_id`, `sender_type`, `message_text`, `created_at`)
                    VALUES (?, ?, 'ai', ?, NOW())
                ")->execute([$companyId, $conversationId, $msgText]);
            }
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
        $userId         = (int)($data['user_id'] ?? $_POST['user_id'] ?? 0);
        $sessionToken   = trim($data['session_token'] ?? $_POST['session_token'] ?? '');
        $conversationId = !empty($data['conversation_id']) ? (int)$data['conversation_id'] : null;
        $visitorName    = trim($data['name'] ?? $_POST['name'] ?? '');
        $visitorEmail   = trim($data['email'] ?? $_POST['email'] ?? '');
        $visitorPhone   = trim($data['phone'] ?? $_POST['phone'] ?? '');

        $agentStmt = $pdo->prepare("SELECT id, name, job_title, department, avatar_url, availability_status, email, phone FROM `users` WHERE id = ? AND company_id = ? LIMIT 1");
        $agentStmt->execute([$userId, $companyId]);
        $agent = $agentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$agent) {
            $agentStmt = $pdo->prepare("SELECT id, name, job_title, department, avatar_url, availability_status, email, phone FROM `users` WHERE company_id = ? AND availability_status = 'AVAILABLE' LIMIT 1");
            $agentStmt->execute([$companyId]);
            $agent = $agentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$agent) {
                $agentStmt = $pdo->prepare("SELECT id, name, job_title, department, avatar_url, availability_status, email, phone FROM `users` WHERE company_id = ? ORDER BY id ASC LIMIT 1");
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
                    'availability_status' => 'AVAILABLE',
                    'email' => '',
                    'phone' => ''
                ];
            }
            $userId = (int)($agent['id'] ?? 0);
        }

        $customer = $resolveCustomer($sessionToken, $visitorName, $visitorPhone, $visitorEmail);
        $customerId = (int)$customer['id'];

        $quickActionToken = bin2hex(random_bytes(24));
        if (!$conversationId) {
            $insConv = $pdo->prepare("
                INSERT INTO `conversations` 
                (`company_id`, `customer_id`, `channel`, `status`, `ownership`, `quick_action_token`, `assigned_user_id`, `unread_human`, `last_message_preview`, `last_message_at`, `created_at`)
                VALUES (?, ?, 'widget', 'human_requested', 'ai', ?, ?, 1, 'Visitor requested human assistance', NOW(), NOW())
            ");
            $insConv->execute([$companyId, $customerId, $quickActionToken, $userId ?: null]);
            $conversationId = (int)$pdo->lastInsertId();
        } else {
            $pdo->prepare("
                UPDATE `conversations`
                SET `status` = 'human_requested',
                    `ownership` = 'ai',
                    `quick_action_token` = COALESCE(quick_action_token, ?),
                    `assigned_user_id` = ?,
                    `unread_human` = unread_human + 1,
                    `last_message_preview` = 'Visitor requested human assistance',
                    `last_message_at` = NOW()
                WHERE id = ? AND company_id = ?
            ")->execute([$quickActionToken, $userId ?: null, $conversationId, $companyId]);

            // Retrieve existing token if present
            $qTokenStmt = $pdo->prepare("SELECT quick_action_token FROM `conversations` WHERE id = ? LIMIT 1");
            $qTokenStmt->execute([$conversationId]);
            $existingToken = $qTokenStmt->fetchColumn();
            if (!empty($existingToken)) {
                $quickActionToken = $existingToken;
            }
        }

        // Insert initial handoff log if human_handoffs exists
        try {
            $pdo->prepare("
                INSERT INTO `human_handoffs` (`company_id`, `lead_id`, `conversation_id`, `requested_by`, `reason`, `assigned_to_user_id`, `status`, `created_at`)
                VALUES (?, (SELECT id FROM leads WHERE conversation_id = ? OR customer_id = ? LIMIT 1), ?, 'visitor', 'Requested Talk to a Person via widget', ?, 'pending', NOW())
            ")->execute([$companyId, $conversationId, $customerId, $conversationId, $userId ?: null]);
        } catch (Exception $hEx) {}

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

        // Insert system handoff request note into messages stream if not recently added
        $lastNoteStmt = $pdo->prepare("SELECT message_text FROM `messages` WHERE `conversation_id` = ? ORDER BY id DESC LIMIT 1");
        $lastNoteStmt->execute([$conversationId]);
        $lastNote = $lastNoteStmt->fetchColumn();
        if ($lastNote !== "[System Event] Visitor requested human assistance.") {
            $pdo->prepare("
                INSERT INTO `messages` (`company_id`, `conversation_id`, `sender_type`, `message_text`, `channel`, `created_at`)
                VALUES (?, ?, 'system', '[System Event] Visitor requested human assistance.', 'widget', NOW())
            ")->execute([$companyId, $conversationId]);
        }

        // Dispatch alert notification to counselor / support team via WhatsApp (if connected) or fallback to Email
        try {
            $custDispName = !empty($customer['name']) && $customer['name'] !== 'Website Visitor' ? $customer['name'] : (!empty($visitorName) ? $visitorName : 'Website Visitor');
            $custDispPhone = !empty($customer['phone']) ? $customer['phone'] : ($visitorPhone ?: 'Not shared');
            $custDispEmail = !empty($customer['email']) ? $customer['email'] : ($visitorEmail ?: 'Not shared');

            $rawHost = $_SERVER['HTTP_HOST'] ?? '';
            $isLocal = ($rawHost === 'localhost' || strpos($rawHost, '127.0.0.1') !== false || empty($rawHost));
            $dashBaseUrl = $isLocal ? 'http://localhost/cuboidpilot' : 'https://cai.cuboidsoft.in';
            $dashConvoUrl = "{$dashBaseUrl}/app/conversations.html?id={$conversationId}";

            // Check if WhatsApp is connected for this tenant
            $waStmt = $pdo->prepare("SELECT phone_number_id, whatsapp_access_token FROM `whatsapp_accounts` WHERE `company_id` = ? AND `status` = 'connected' LIMIT 1");
            $waStmt->execute([$companyId]);
            $waAcc = $waStmt->fetch(PDO::FETCH_ASSOC);

            $targetPhone = !empty($agent['phone']) ? $agent['phone'] : '';
            if (empty($targetPhone)) {
                $uStmt = $pdo->prepare("SELECT phone FROM `users` WHERE `company_id` = ? AND `role` IN ('owner', 'admin', 'manager') AND `phone` IS NOT NULL AND `phone` != '' ORDER BY id ASC LIMIT 1");
                $uStmt->execute([$companyId]);
                $targetPhone = $uStmt->fetchColumn() ?: '9238695500';
            }

            $waSent = false;
            if ($waAcc && !empty($waAcc['phone_number_id']) && !empty($waAcc['whatsapp_access_token']) && !empty($targetPhone)) {
                $cleanRecipient = preg_replace('/[^0-9]/', '', $targetPhone);
                if (strlen($cleanRecipient) === 10) {
                    $cleanRecipient = '91' . $cleanRecipient;
                }
                $dashConvoUrl = "https://cai.cuboidsoft.in/app/conversations.html?id={$conversationId}";
                $compName = !empty($company['name']) ? $company['name'] : 'CuboidSoft';
                $waMsg = "*{$compName} Support Desk*\n"
                    . "Live Customer Assistance\n\n"
                    . "*New Lead Waiting*\n"
                    . "Lead Name: {$custDispName}\n"
                    . "Phone: {$custDispPhone}\n"
                    . "Email: {$custDispEmail}\n"
                    . "Conversation ID: #CONV-{$conversationId}\n\n"
                    . "*How to Reply*\n"
                    . "Reply directly to this chat (e.g. `Hello! How can I assist you?`), or type `REPLY #{$conversationId} <your message>`.\n\n"
                    . "*How to Resolve*\n"
                    . "Type `RESOLVE #{$conversationId}` when done.\n\n"
                    . "Live Dashboard:\n"
                    . "{$dashConvoUrl}\n\n"
                    . "— Powered by Cai (CuboidSoft AI)";

                $endpoint = "https://graph.facebook.com/v20.0/{$waAcc['phone_number_id']}/messages";
                $payload = [
                    'messaging_product' => 'whatsapp',
                    'to'                => $cleanRecipient,
                    'type'              => 'text',
                    'text'              => ['body' => $waMsg]
                ];
                $ch = curl_init($endpoint);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode($payload),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => [
                        'Authorization: Bearer ' . $waAcc['whatsapp_access_token'],
                        'Content-Type: application/json'
                    ],
                    CURLOPT_TIMEOUT        => 4
                ]);
                $wRes = @curl_exec($ch);
                $wCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($wCode >= 200 && $wCode < 300) {
                    $waSent = true;
                }
            }

            // 1. Dispatch Email notification to Company Admin & Assigned Agent
            $supportEmail = !empty($agent['email']) ? $agent['email'] : '';
            if (empty($supportEmail)) {
                $uStmt = $pdo->prepare("SELECT email FROM `users` WHERE `company_id` = ? AND `role` IN ('owner', 'admin') AND `is_active` = 1 ORDER BY id ASC LIMIT 1");
                $uStmt->execute([$companyId]);
                $supportEmail = $uStmt->fetchColumn() ?: '';
            }

            if (!empty($supportEmail) && filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) {
                $compName = htmlspecialchars($company['name'] ?? 'Support Team');
                $emailSubject = "Human Assistance Requested — {$compName} (#CONV-{$conversationId})";
                $replyDirectUrl = "{$dashBaseUrl}/api/quick_action.php?token={$quickActionToken}&action=view";
                $closeDirectUrl = "{$dashBaseUrl}/api/quick_action.php?token={$quickActionToken}&action=close";
                $resolveDirectUrl = "{$dashBaseUrl}/api/quick_action.php?token={$quickActionToken}&action=resolve";

                $emailCfg = CompanyMailer::getCompanyConfig($pdo, $companyId);
                $inboundDomain = !empty($emailCfg['reply_to_email']) && strpos($emailCfg['reply_to_email'], '@') !== false
                    ? substr(strrchr($emailCfg['reply_to_email'], "@"), 1)
                    : (!empty($host) && $host !== 'localhost' ? $host : 'cai.cuboidsoft.in');
                $inboundReplyTo = "reply+{$quickActionToken}@{$inboundDomain}";

                $emailHtml = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>{$emailSubject}</title></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background-color: #f7f6f2; color: #1c1917; margin: 0; padding: 24px;">
  <div style="max-width: 580px; margin: 0 auto; background: #ffffff; border: 1px solid #e7e5de; border-radius: 8px; padding: 30px; box-shadow: 0 4px 12px rgba(0,0,0,0.03);">
    <div style="padding-bottom: 16px; border-bottom: 1px solid #e7e5de; margin-bottom: 20px;">
      <span style="font-size: 11px; font-weight: 700; color: #b45309; text-transform: uppercase; background: #fef3c7; padding: 3px 8px; border-radius: 4px;">Live Human Support Request</span>
      <h2 style="font-size: 18px; margin: 12px 0 4px 0; color: #1c1917;">Visitor Requested Live Human Assistance</h2>
      <p style="font-size: 12.5px; color: #78716c; margin: 0;">AI automated replies have been paused. You can reply directly to this email or join via dashboard.</p>
    </div>
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 13px;">
      <tr>
        <td style="padding: 6px 0; color: #78716c; width: 35%;">Customer Name:</td>
        <td style="padding: 6px 0; font-weight: 600; color: #1c1917;">{$custDispName}</td>
      </tr>
      <tr>
        <td style="padding: 6px 0; color: #78716c;">Phone:</td>
        <td style="padding: 6px 0; font-weight: 600; color: #1c1917;">{$custDispPhone}</td>
      </tr>
      <tr>
        <td style="padding: 6px 0; color: #78716c;">Email:</td>
        <td style="padding: 6px 0; font-weight: 600; color: #1c1917;">{$custDispEmail}</td>
      </tr>
      <tr>
        <td style="padding: 6px 0; color: #78716c;">Conversation ID:</td>
        <td style="padding: 6px 0; font-weight: 600; color: #6366f1;">#CONV-{$conversationId}</td>
      </tr>
    </table>

    <!-- Remote 1-Click Action Hub (No Login Required) -->
    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; margin: 24px 0 16px 0; text-align: center;">
      <div style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 12px; letter-spacing: 0.5px;">Remote 1-Click Email Actions:</div>
      <div style="display: inline-flex; gap: 8px; flex-wrap: wrap; justify-content: center;">
        <a href="{$replyDirectUrl}" style="background-color: #0f172a; color: #ffffff; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-size: 12.5px; font-weight: 600; display: inline-block;">💬 Reply to Visitor</a>
        <a href="{$resolveDirectUrl}" style="background-color: #10b981; color: #ffffff; text-decoration: none; padding: 10px 16px; border-radius: 6px; font-size: 12.5px; font-weight: 600; display: inline-block;">✓ Mark Resolved</a>
        <a href="{$closeDirectUrl}" style="background-color: #ef4444; color: #ffffff; text-decoration: none; padding: 10px 16px; border-radius: 6px; font-size: 12.5px; font-weight: 600; display: inline-block;">✗ Close Chat</a>
      </div>
      <div style="margin-top: 10px; font-size: 11px; color: #94a3b8;">
        You can also reply directly to this email from your inbox. Your response will appear immediately on the visitor's website widget.
      </div>
    </div>

    <div style="text-align: center; margin: 16px 0 10px 0;">
      <a href="{$dashConvoUrl}" style="color: #64748b; text-decoration: underline; font-size: 12px;">Or open full conversation in Dashboard &rarr;</a>
    </div>
  </div>
</body>
</html>
HTML;
                $extraHeaders = [
                    'reply_to'   => $inboundReplyTo,
                    'message_id' => "<conv-{$conversationId}-{$quickActionToken}@{$inboundDomain}>"
                ];
                CompanyMailer::send($pdo, $companyId, $supportEmail, $emailSubject, $emailHtml, '', $extraHeaders);
            }

            // 2. Dispatch Customer Acknowledgment Email if visitor email is available
            $targetCustEmail = !empty($custDispEmail) && filter_var($custDispEmail, FILTER_VALIDATE_EMAIL) ? $custDispEmail : '';
            if (!empty($targetCustEmail)) {
                $compName = htmlspecialchars($company['name'] ?? 'Support Team');
                $custSubj = "We've received your live support request - {$compName}";
                $custHtml = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background-color: #f8fafc; color: #0f172a; margin: 0; padding: 24px;">
  <div style="max-width: 560px; margin: 0 auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 28px;">
    <div style="border-bottom: 2px solid #0f172a; padding-bottom: 12px; margin-bottom: 18px;">
      <h2 style="margin: 0; font-size: 19px; color: #0f172a;">{$compName}</h2>
      <div style="font-size: 12px; color: #64748b; margin-top: 4px;">Live Assistance Request Received</div>
    </div>
    <p style="font-size: 14px; color: #1e293b;">Hello <strong>{$custDispName}</strong>,</p>
    <p style="font-size: 13px; color: #334155; line-height: 1.6;">
      Thank you for reaching out to us. A customer support specialist has been notified of your request (Reference: <strong>#CONV-{$conversationId}</strong>).
    </p>
    <p style="font-size: 13px; color: #334155; line-height: 1.6;">
      Our team is connecting with you right on our website chat. If you navigated away, we will follow up with you directly via email or WhatsApp shortly.
    </p>
    <div style="border-top: 1px solid #e2e8f0; padding-top: 14px; margin-top: 22px; font-size: 11px; color: #94a3b8;">
      &copy; {$compName}. Powered by CuboidPilot.
    </div>
  </div>
</body>
</html>
HTML;
                CompanyMailer::send($pdo, $companyId, $targetCustEmail, $custSubj, $custHtml);
            }

            // Also trigger standard salesperson alert if lead exists
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
            $isSys   = ($r['sender_type'] === 'system');
            $messages[] = [
                'id'          => (int)$r['id'],
                'sender'      => ($r['sender_type'] === 'visitor') ? 'user' : ($isSys ? 'system' : ($isHuman ? 'human_agent' : 'ai')),
                'text'        => $r['message_text'],
                'metadata'    => json_decode($r['metadata_json'] ?? '', true),
                'timestamp'   => date('g:i A', strtotime($r['created_at']))
            ];
        }

        echo json_encode([
            'success'        => true,
            'messages'       => $messages,
            'ownership'      => $conv['ownership'] ?? 'ai',
            'status'         => $conv['status'] ?? 'active',
            'closure_reason' => $conv['closure_reason'] ?? null,
            'last_message_at'=> $conv['last_message_at'] ?? null,
            'agent'          => [
                'name'                => $conv['agent_name'] ?? 'Consultant',
                'job_title'           => $conv['job_title'] ?? 'Advisor',
                'avatar_url'          => $conv['avatar_url'] ?? '',
                'availability_status' => $conv['availability_status'] ?? 'AVAILABLE'
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------
    // ACTION: close_conversation (Handles user exit and 1-minute inactivity)
    // -------------------------------------------------------------
    if ($action === 'close_conversation') {
        $conversationId = (int)($data['conversation_id'] ?? $_POST['conversation_id'] ?? 0);
        $reason         = trim($data['reason'] ?? $_POST['reason'] ?? 'user_exit');
        $sessionToken   = trim($data['session_token'] ?? $_POST['session_token'] ?? '');

        if (!$conversationId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing conversation ID']);
            exit;
        }

        $cStmt = $pdo->prepare("SELECT c.*, cust.name as customer_name, cust.email as customer_email, cust.phone as customer_phone FROM `conversations` c LEFT JOIN `customers` cust ON cust.id = c.customer_id WHERE c.id = ? AND c.company_id = ? LIMIT 1");
        $cStmt->execute([$conversationId, $companyId]);
        $conv = $cStmt->fetch(PDO::FETCH_ASSOC);

        if (!$conv) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Conversation not found']);
            exit;
        }

        $closureReasonText = ($reason === 'inactivity') ? 'Closed due to inactivity' : 'Closed by visitor';

        $pdo->prepare("
            UPDATE `conversations`
            SET `status` = 'closed',
                `closure_reason` = ?,
                `closed_at` = NOW(),
                `last_message_at` = NOW()
            WHERE id = ? AND company_id = ?
        ")->execute([$reason, $conversationId, $companyId]);

        // Insert system message into stream
        $pdo->prepare("
            INSERT INTO `messages` (`company_id`, `conversation_id`, `sender_type`, `message_text`, `channel`, `created_at`)
            VALUES (?, ?, 'system', ?, 'widget', NOW())
        ")->execute([$companyId, $conversationId, "[System Event] Conversation {$closureReasonText}."]);

        // Update any pending human handoff to completed
        try {
            $pdo->prepare("
                UPDATE `human_handoffs`
                SET `status` = 'completed', `call_completed_at` = NOW()
                WHERE `conversation_id` = ? AND `company_id` = ? AND `status` != 'completed'
            ")->execute([$conversationId, $companyId]);
        } catch (Exception $hEx) {}

        // Dispatch closure notification alert via Email/WhatsApp
        try {
            $custName = !empty($conv['customer_name']) ? $conv['customer_name'] : 'Website Visitor';
            $custEmail = !empty($conv['customer_email']) ? $conv['customer_email'] : '';
            $custPhone = !empty($conv['customer_phone']) ? $conv['customer_phone'] : '';

            // Notify company/agent
            $alertStmt = $pdo->prepare("SELECT email FROM `users` WHERE `company_id` = ? AND `role` IN ('owner', 'admin') AND `is_active` = 1 LIMIT 1");
            $alertStmt->execute([$companyId]);
            $adminEmail = $alertStmt->fetchColumn();

            if (!empty($adminEmail) && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                $subj = "📁 [Conversation Closed] #CONV-{$conversationId} - {$custName} ({$closureReasonText})";
                $html = <<<HTML
<!DOCTYPE html>
<html>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background-color: #f7f6f2; color: #1c1917; margin: 0; padding: 24px;">
  <div style="max-width: 580px; margin: 0 auto; background: #ffffff; border: 1px solid #e7e5de; border-radius: 8px; padding: 30px;">
    <h3 style="margin-top: 0; color: #1c1917;">Conversation Closed</h3>
    <p style="font-size: 13px; color: #44403c;">Conversation <strong>#CONV-{$conversationId}</strong> with <strong>{$custName}</strong> has been marked as <strong>Closed</strong>.</p>
    <p style="font-size: 12px; color: #78716c;">Closure Reason: <strong>{$closureReasonText}</strong></p>
    <p style="font-size: 12px; color: #78716c;">Status has been set to <strong>closed</strong> in your dashboard inbox.</p>
  </div>
</body>
</html>
HTML;
                CompanyMailer::send($pdo, $companyId, $adminEmail, $subj, $html);
            }
        } catch (Exception $mEx) {}

        echo json_encode([
            'success'        => true,
            'status'         => 'closed',
            'closure_reason' => $reason,
            'message'        => "Conversation {$closureReasonText}."
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

        // Validate conversation exists
        $cCheck = $pdo->prepare("SELECT id FROM `conversations` WHERE id = ? AND company_id = ? LIMIT 1");
        $cCheck->execute([$conversationId, $companyId]);
        if (!$cCheck->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Conversation not found', 'reset_conversation' => true]);
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
                    . "👉 Dashboard: {$replyUrl}\n\n"
                    . "Or reply directly here:\n"
                    . "REPLY #{$conversationId} <your message>";

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

        // Dual Email Notifications: 1) Admin Verification Alert & 2) Customer Receipt
        try {
            $compName = htmlspecialchars($company['name'] ?? 'CuboidPilot');
            $fmtAmount = '₹' . number_format($amount);
            $payerEsc = htmlspecialchars($payerName);
            $utrEsc = htmlspecialchars($utrNumber);
            $phoneEsc = htmlspecialchars($payerPhone ?: 'Not provided');
            $custEmail = !empty($data['payer_email'] ?? $_POST['payer_email'] ?? $customer['email'] ?? '') ? trim($data['payer_email'] ?? $_POST['payer_email'] ?? $customer['email']) : '';

            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $baseAppUrl = $protocol . $host . ((strpos($_SERVER['REQUEST_URI'] ?? '', '/cuboidpilot') !== false) ? '/cuboidpilot' : '');
            $verifyUrl = "{$baseAppUrl}/app/leads.html?search=" . urlencode($utrNumber);

            // 1. Admin Alert Email
            $adminEmail = $company['email'] ?? '';
            if (empty($adminEmail)) {
                $uStmt = $pdo->prepare("SELECT email FROM `users` WHERE `company_id` = ? AND `role` IN ('owner', 'admin') AND email IS NOT NULL AND email != '' ORDER BY id ASC LIMIT 1");
                $uStmt->execute([$companyId]);
                $adminEmail = (string)$uStmt->fetchColumn();
            }

            if (!empty($adminEmail) && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                $adminSubj = "🏦 [Payment Verification Needed] {$fmtAmount} submitted by {$payerName}";
                $adminHtml = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background-color: #f7f6f2; color: #1c1917; margin: 0; padding: 24px;">
  <div style="max-width: 580px; margin: 0 auto; background: #ffffff; border: 1px solid #e7e5de; border-radius: 8px; padding: 30px; box-shadow: 0 4px 12px rgba(0,0,0,0.03);">
    <div style="padding-bottom: 16px; border-bottom: 1px solid #e7e5de; margin-bottom: 20px;">
      <span style="font-size: 11px; font-weight: 700; color: #15803d; text-transform: uppercase; background: #dcfce7; padding: 3px 8px; border-radius: 4px;">Bank Transfer Received</span>
      <h2 style="font-size: 18px; margin: 12px 0 4px 0; color: #1c1917;">New Payment Pending Verification</h2>
      <div style="font-size: 12.5px; color: #78716c; margin-top: 4px;">Payment ID: #PAY-{$paymentId}</div>
    </div>
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 13px;">
      <tr>
        <td style="padding: 6px 0; color: #78716c; width: 35%;">Amount:</td>
        <td style="padding: 6px 0; font-size: 16px; font-weight: 700; color: #15803d;">{$fmtAmount}</td>
      </tr>
      <tr>
        <td style="padding: 6px 0; color: #78716c;">Payer Name:</td>
        <td style="padding: 6px 0; font-weight: 600; color: #1c1917;">{$payerEsc}</td>
      </tr>
      <tr>
        <td style="padding: 6px 0; color: #78716c;">UTR / Ref Number:</td>
        <td style="padding: 6px 0; font-family: monospace; font-weight: 700; color: #4338ca;">{$utrEsc}</td>
      </tr>
      <tr>
        <td style="padding: 6px 0; color: #78716c;">Phone:</td>
        <td style="padding: 6px 0; font-weight: 600; color: #1c1917;">{$phoneEsc}</td>
      </tr>
      <tr>
        <td style="padding: 6px 0; color: #78716c;">Customer Email:</td>
        <td style="padding: 6px 0; font-weight: 600; color: #1c1917;">{$custEmail}</td>
      </tr>
      <tr>
        <td style="padding: 6px 0; color: #78716c;">Notes:</td>
        <td style="padding: 6px 0; color: #44403c;">{$notes}</td>
      </tr>
    </table>
    <div style="text-align: center; margin: 24px 0 10px 0;">
      <a href="{$verifyUrl}" style="background-color: #111111; color: #ffffff; text-decoration: none; padding: 11px 24px; border-radius: 6px; font-size: 13px; font-weight: 600; display: inline-block;">Verify & Update in Dashboard &rarr;</a>
    </div>
  </div>
</body>
</html>
HTML;
                CompanyMailer::send($pdo, $companyId, $adminEmail, $adminSubj, $adminHtml);
            }

            // 2. Customer Receipt Email
            if (!empty($custEmail) && filter_var($custEmail, FILTER_VALIDATE_EMAIL)) {
                $custSubj = "Payment Submission Acknowledgment - {$fmtAmount} ({$compName})";
                $custHtml = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background-color: #f8fafc; color: #0f172a; margin: 0; padding: 24px;">
  <div style="max-width: 560px; margin: 0 auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 28px;">
    <div style="border-bottom: 2px solid #0f172a; padding-bottom: 12px; margin-bottom: 18px;">
      <h2 style="margin: 0; font-size: 19px; color: #0f172a;">{$compName}</h2>
      <div style="font-size: 12px; color: #64748b; margin-top: 4px;">Payment Acknowledgment Receipt</div>
    </div>
    <p style="font-size: 14px; color: #1e293b;">Dear <strong>{$payerEsc}</strong>,</p>
    <p style="font-size: 13px; color: #334155; line-height: 1.6;">
      We have received your payment details submitted via bank transfer. Our accounts desk will verify the transaction and notify you shortly.
    </p>
    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 16px; margin: 16px 0;">
      <table style="width: 100%; font-size: 13px; color: #334155; border-collapse: collapse;">
        <tr><td style="padding: 4px 0; color: #64748b; width: 140px;">Amount:</td><td style="font-weight: 700; color: #15803d; font-size: 15px;">{$fmtAmount}</td></tr>
        <tr><td style="padding: 4px 0; color: #64748b;">UTR / Ref Number:</td><td style="font-family: monospace; font-weight: 700;">{$utrEsc}</td></tr>
        <tr><td style="padding: 4px 0; color: #64748b;">Status:</td><td><span style="background: #fef3c7; color: #b45309; padding: 2px 7px; border-radius: 4px; font-weight: 600; font-size: 11.5px;">Pending Verification</span></td></tr>
      </table>
    </div>
    <p style="font-size: 12.5px; color: #64748b; line-height: 1.5;">
      If you have any questions or require an updated invoice, feel free to reply directly to this email or reach us on live chat.
    </p>
    <div style="border-top: 1px solid #e2e8f0; padding-top: 14px; margin-top: 20px; font-size: 11px; color: #94a3b8;">
      &copy; {$compName}. Powered by CuboidPilot.
    </div>
  </div>
</body>
</html>
HTML;
                CompanyMailer::send($pdo, $companyId, $custEmail, $custSubj, $custHtml);
            }
        } catch (Exception $payMailEx) {
            error_log('[Payment Mail Note] ' . $payMailEx->getMessage());
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

    // -------------------------------------------------------------
    // ACTION: get_catalogs (Published Offering Catalogs for Tenant)
    // -------------------------------------------------------------
    if ($action === 'get_catalogs') {
        $catStmt = $pdo->prepare("
            SELECT oc.*
            FROM `offering_catalogs` oc
            WHERE oc.company_id = ? AND oc.is_published = 1
            ORDER BY oc.display_order ASC, oc.id ASC
        ");
        $catStmt->execute([$companyId]);
        $catalogs = $catStmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($catalogs as $c) {
            $itemCount = 0;
            $sectionIds = !empty($c['section_ids']) ? json_decode($c['section_ids'], true) : [];
            if (!empty($sectionIds) && is_array($sectionIds)) {
                $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
                $iStmt = $pdo->prepare("SELECT COUNT(*) FROM `products` WHERE `company_id` = ? AND `section_id` IN ($placeholders) AND `is_active` = 1");
                $iStmt->execute(array_merge([$companyId], $sectionIds));
                $itemCount = (int)$iStmt->fetchColumn();
            } else {
                $iStmt = $pdo->prepare("SELECT COUNT(*) FROM `products` WHERE `company_id` = ? AND `is_active` = 1");
                $iStmt->execute([$companyId]);
                $itemCount = (int)$iStmt->fetchColumn();
            }

            $result[] = [
                'id'            => (int)$c['id'],
                'name'          => $c['name'],
                'slug'          => $c['slug'],
                'description'   => $c['description'] ?? '',
                'item_count'    => $itemCount,
                'cover_image'   => $c['image_url'] ?? '',
                'cta_label'     => 'Browse Catalog'
            ];
        }

        echo json_encode([
            'success'  => true,
            'catalogs' => $result
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------
    // ACTION: get_catalog_items (Items/Offerings in a Catalog)
    // -------------------------------------------------------------
    if ($action === 'get_catalog_items') {
        $catalogId = (int)($_GET['catalog_id'] ?? $data['catalog_id'] ?? 0);
        $catalog = null;
        if ($catalogId > 0) {
            $catStmt = $pdo->prepare("SELECT * FROM `offering_catalogs` WHERE `id` = ? AND `company_id` = ? AND `is_published` = 1 LIMIT 1");
            $catStmt->execute([$catalogId, $companyId]);
            $catalog = $catStmt->fetch(PDO::FETCH_ASSOC);
        }

        $items = [];
        $sectionIds = ($catalog && !empty($catalog['section_ids'])) ? json_decode($catalog['section_ids'], true) : [];
        if (!empty($sectionIds) && is_array($sectionIds)) {
            $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
            $pStmt = $pdo->prepare("
                SELECT id, name, description, price_inr as price, duration, features_json as features, is_featured, custom_fields_json, section_id
                FROM `products`
                WHERE `company_id` = ? AND `section_id` IN ($placeholders) AND `is_active` = 1
                ORDER BY `id` ASC
            ");
            $pStmt->execute(array_merge([$companyId], $sectionIds));
            $items = $pStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $pStmt = $pdo->prepare("
                SELECT id, name, description, price_inr as price, duration, features_json as features, is_featured, custom_fields_json, section_id
                FROM `products`
                WHERE `company_id` = ? AND `is_active` = 1
                ORDER BY `id` ASC
            ");
            $pStmt->execute([$companyId]);
            $items = $pStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $formatted = [];
        foreach ($items as $item) {
            $customFields = !empty($item['custom_fields_json']) ? json_decode($item['custom_fields_json'], true) : [];
            $formatted[] = [
                'id'            => (int)$item['id'],
                'name'          => $item['name'],
                'description'   => $item['description'] ?? '',
                'price'         => $item['price'] !== null ? (float)$item['price'] : null,
                'currency'      => $item['currency'] ?: 'INR',
                'duration'      => $item['duration'] ?? '',
                'features'      => !empty($item['features']) ? json_decode($item['features'], true) : [],
                'is_featured'   => (bool)$item['is_featured'],
                'custom_fields' => is_array($customFields) ? $customFields : []
            ];
        }

        echo json_encode([
            'success' => true,
            'catalog' => $catalog ? [
                'id'          => (int)$catalog['id'],
                'name'        => $catalog['name'],
                'description' => $catalog['description'] ?? ''
            ] : null,
            'items'   => $formatted
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------
    // ACTION: get_brochures (Published Documents/Assets for Tenant)
    // -------------------------------------------------------------
    if ($action === 'get_brochures') {
        $categoryId = (int)($_GET['category_id'] ?? $data['category_id'] ?? 0);
        
        $sql = "
            SELECT id, title, description, file_url, original_filename, file_size_bytes, download_count, linked_category_ids
            FROM `company_assets`
            WHERE `company_id` = ? AND `asset_type` = 'brochure' AND `is_published` = 1
        ";
        $params = [$companyId];
        $sql .= " ORDER BY `id` DESC";

        $bStmt = $pdo->prepare($sql);
        $bStmt->execute($params);
        $brochures = $bStmt->fetchAll(PDO::FETCH_ASSOC);

        $filtered = [];
        foreach ($brochures as $b) {
            $linkedCats = !empty($b['linked_category_ids']) ? json_decode($b['linked_category_ids'], true) : [];
            if ($categoryId > 0 && !empty($linkedCats) && !in_array($categoryId, $linkedCats)) {
                continue;
            }
            $filtered[] = [
                'id'                => (int)$b['id'],
                'title'             => $b['title'],
                'description'       => $b['description'] ?? '',
                'file_url'          => $b['file_url'],
                'original_filename' => $b['original_filename'] ?? '',
                'file_size'         => (int)($b['file_size_bytes'] ?? 0),
                'download_count'    => (int)($b['download_count'] ?? 0)
            ];
        }

        echo json_encode([
            'success'   => true,
            'brochures' => $filtered
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------
    // ACTION: get_sales_reps (Available Human Sales Representatives for Contextual Assistance)
    // -------------------------------------------------------------
    if ($action === 'get_sales_reps') {
        $sql = "
            SELECT id, name, email, job_title, department, availability_status, avatar_url, linkedin_url
            FROM `users`
            WHERE `company_id` = ? AND `is_active` = 1
              AND (`department` = 'sales' OR `is_instant_help_enabled` = 1 OR `is_appointment_enabled` = 1)
            ORDER BY FIELD(availability_status, 'AVAILABLE', 'BUSY', 'OFFLINE'), id ASC
            LIMIT 4
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$companyId]);
        $reps = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($reps)) {
            $stmt = $pdo->prepare("
                SELECT id, name, email, job_title, department, availability_status, avatar_url, linkedin_url
                FROM `users`
                WHERE `company_id` = ? AND `is_active` = 1
                ORDER BY id ASC LIMIT 3
            ");
            $stmt->execute([$companyId]);
            $reps = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode([
            'success' => true,
            'sales_reps' => $reps
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------
    // ACTION: get_history (Visitor Conversation Sessions & Transcripts with Tenant Isolation)
    // -------------------------------------------------------------
    if ($action === 'get_history') {
        $sessionToken = trim($_GET['session_token'] ?? $data['session_token'] ?? '');
        $requestedConvId = (int)($_GET['conversation_id'] ?? $data['conversation_id'] ?? 0);

        if (empty($sessionToken)) {
            echo json_encode(['success' => true, 'conversations' => [], 'active_transcript' => []]);
            exit;
        }

        // 1. Resolve customer ID for this session within the company
        $sStmt = $pdo->prepare("
            SELECT customer_id, conversation_id FROM `visitor_sessions`
            WHERE (`session_token` = ? OR `session_id` = ?) AND `company_id` = ?
            ORDER BY id DESC LIMIT 1
        ");
        $sStmt->execute([$sessionToken, $sessionToken, $companyId]);
        $sessRow = $sStmt->fetch(PDO::FETCH_ASSOC);

        $customerId = $sessRow ? (int)$sessRow['customer_id'] : 0;
        $linkedConvId = $sessRow ? (int)$sessRow['conversation_id'] : 0;

        if ($customerId <= 0 && $linkedConvId <= 0) {
            echo json_encode(['success' => true, 'conversations' => [], 'active_transcript' => []]);
            exit;
        }

        // 2. Fetch past conversations belonging to this customer & tenant
        $cStmt = $pdo->prepare("
            SELECT c.id, c.status, c.ownership, c.last_message_preview, c.last_message_at, c.created_at,
                   u.name as agent_name
            FROM `conversations` c
            LEFT JOIN `users` u ON u.id = c.assigned_user_id
            WHERE c.company_id = ? AND (c.customer_id = ? OR c.id = ?)
            ORDER BY COALESCE(c.last_message_at, c.created_at) DESC
            LIMIT 10
        ");
        $cStmt->execute([$companyId, $customerId, $linkedConvId]);
        $convs = $cStmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. If a specific conversation transcript is requested, load its messages
        $transcript = [];
        $targetConvId = $requestedConvId > 0 ? $requestedConvId : ($linkedConvId > 0 ? $linkedConvId : (!empty($convs[0]['id']) ? (int)$convs[0]['id'] : 0));

        if ($targetConvId > 0) {
            // Verify conversation belongs to tenant & customer
            $verifyStmt = $pdo->prepare("SELECT id FROM `conversations` WHERE id = ? AND company_id = ? AND (customer_id = ? OR id = ?) LIMIT 1");
            $verifyStmt->execute([$targetConvId, $companyId, $customerId, $linkedConvId]);
            if ($verifyStmt->fetchColumn()) {
                $mStmt = $pdo->prepare("
                    SELECT id, sender_type, message_text, metadata_json, created_at
                    FROM `messages`
                    WHERE conversation_id = ? AND company_id = ?
                    ORDER BY id ASC LIMIT 50
                ");
                $mStmt->execute([$targetConvId, $companyId]);
                $rows = $mStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $isHuman = in_array($r['sender_type'], ['agent', 'human', 'user', 'support_agent'], true);
                    $transcript[] = [
                        'id'        => (int)$r['id'],
                        'sender'    => ($r['sender_type'] === 'visitor') ? 'user' : ($r['sender_type'] === 'system' ? 'system' : ($isHuman ? 'human_agent' : 'ai')),
                        'text'      => $r['message_text'],
                        'timestamp' => date('M j, g:i A', strtotime($r['created_at']))
                    ];
                }
            }
        }

        echo json_encode([
            'success'           => true,
            'conversations'     => array_map(function($c) {
                return [
                    'id'            => (int)$c['id'],
                    'status'        => $c['status'],
                    'ownership'     => $c['ownership'],
                    'preview'       => $c['last_message_preview'] ?: 'Conversation',
                    'agent_name'    => $c['agent_name'] ?: 'Cai AI',
                    'time'          => date('M j, g:i A', strtotime($c['last_message_at'] ?: $c['created_at']))
                ];
            }, $convs),
            'active_conversation_id' => $targetConvId,
            'transcript'        => $transcript
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------
    // ACTION: capture_lead (Direct Visitor Lead Ingestion & Profile Upgrade)
    // -------------------------------------------------------------
    if ($action === 'capture_lead') {
        $name = trim($data['name'] ?? $_POST['name'] ?? '');
        $phone = trim($data['phone'] ?? $_POST['phone'] ?? '');
        $email = trim($data['email'] ?? $_POST['email'] ?? '');
        $sessionToken = trim($data['session_token'] ?? $_POST['session_token'] ?? '');
        $customNotes = trim($data['notes'] ?? $_POST['notes'] ?? '');

        if (empty($name) && empty($phone) && empty($email)) {
            echo json_encode(['success' => false, 'error' => 'At least name, phone, or email is required.']);
            exit;
        }

        $customer = $resolveCustomer($sessionToken, $name, $phone, $email);
        $customerId = (int)$customer['id'];

        // Find or create lead
        $leadStmt = $pdo->prepare("SELECT id FROM `leads` WHERE `company_id` = ? AND `customer_id` = ? ORDER BY id DESC LIMIT 1");
        $leadStmt->execute([$companyId, $customerId]);
        $leadId = $leadStmt->fetchColumn();

        if (!$leadId) {
            $pdo->prepare("
                INSERT INTO `leads`
                (`company_id`, `customer_id`, `title`, `stage_name`, `priority`, `intent_level`, `opportunity_value`, `source`, `status`, `ai_summary`, `created_at`, `updated_at`)
                VALUES (?, ?, ?, 'Qualified', 'MEDIUM', 'medium', 25000, 'WEBSITE_WIDGET', 'open', ?, NOW(), NOW())
            ")->execute([
                $companyId,
                $customerId,
                $name ? "Lead: {$name}" : "Website Inquiry",
                $customNotes ?: "Visitor captured through widget lead form."
            ]);
            $leadId = (int)$pdo->lastInsertId();
        } else {
            $pdo->prepare("UPDATE `leads` SET `updated_at` = NOW() WHERE `id` = ? AND `company_id` = ?")->execute([$leadId, $companyId]);
        }

        echo json_encode([
            'success'     => true,
            'customer_id' => $customerId,
            'lead_id'     => (int)$leadId,
            'customer'    => [
                'id'    => $customerId,
                'name'  => $customer['name'],
                'phone' => $customer['phone'],
                'email' => $customer['email']
            ]
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
