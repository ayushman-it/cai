<?php
/**
 * CUBOIDPILOT — APPOINTMENTS & CALENDAR BOOKING API (Section 11, 13 & 22)
 * Manages consultation bookings, discovery calls, and counselor schedules.
 * Strictly multi-tenant isolated by server session.
 */

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/entitlements.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = getDbConnection();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];

$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true) ?? $_POST;
if (!empty($data['action'])) {
    $action = $data['action'];
}

try {
    switch ($action) {
        // 1. List Appointments & Stats (Section 13: Today, Upcoming, Completed, Cancelled)
        case 'list':
            $statusFilter = strtolower(trim($_GET['status'] ?? $data['status'] ?? 'all'));
            $search = trim($_GET['search'] ?? $data['search'] ?? '');

            $whereSql = "WHERE a.company_id = ?";
            $params = [$companyId];

            if ($statusFilter === 'today') {
                $whereSql .= " AND DATE(a.slot_datetime) = CURDATE() AND a.status != 'cancelled'";
            } elseif ($statusFilter === 'upcoming') {
                $whereSql .= " AND a.slot_datetime >= NOW() AND a.status IN ('scheduled', 'rescheduled')";
            } elseif ($statusFilter === 'completed') {
                $whereSql .= " AND a.status = 'completed'";
            } elseif ($statusFilter === 'cancelled') {
                $whereSql .= " AND a.status = 'cancelled'";
            } elseif ($statusFilter !== 'all' && !empty($statusFilter)) {
                $whereSql .= " AND a.status = ?";
                $params[] = $statusFilter;
            }

            if (!empty($search)) {
                $whereSql .= " AND (COALESCE(a.customer_name, c.name) LIKE ? OR COALESCE(a.customer_phone, c.phone) LIKE ? OR COALESCE(a.customer_email, c.email) LIKE ? OR a.title LIKE ?)";
                $like = "%{$search}%";
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }

            $stmt = $pdo->prepare("
                SELECT a.*, 
                       COALESCE(a.customer_name, c.name, 'Prospect') as display_customer_name,
                       COALESCE(a.customer_phone, c.phone, '') as display_customer_phone,
                       COALESCE(a.customer_email, c.email, '') as display_customer_email,
                       l.title as lead_title, l.priority as lead_priority,
                       u.name as consultant_name, u.job_title as consultant_job_title, u.avatar_url as consultant_avatar,
                       DATE_FORMAT(a.slot_datetime, '%b %e, %Y at %l:%i %p') as formatted_slot,
                       DATE_FORMAT(a.slot_datetime, '%Y-%m-%d') as slot_date,
                       DATE_FORMAT(a.slot_datetime, '%l:%i %p') as slot_time
                FROM `appointments` a
                LEFT JOIN `customers` c ON c.id = a.customer_id
                LEFT JOIN `leads` l ON l.id = a.lead_id
                LEFT JOIN `users` u ON u.id = a.assigned_user_id
                {$whereSql}
                ORDER BY a.slot_datetime DESC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Compute Stats (Today, Upcoming, Completed, Cancelled)
            $statsStmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN DATE(slot_datetime) = CURDATE() AND status != 'cancelled' THEN 1 ELSE 0 END) as today_count,
                    SUM(CASE WHEN slot_datetime >= NOW() AND status IN ('scheduled', 'rescheduled') THEN 1 ELSE 0 END) as upcoming_count,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count
                FROM `appointments`
                WHERE company_id = ?
            ");
            $statsStmt->execute([$companyId]);
            $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [
                'total' => 0, 'today_count' => 0, 'upcoming_count' => 0, 'completed_count' => 0, 'cancelled_count' => 0
            ];

            echo json_encode([
                'success'      => true,
                'count'        => count($rows),
                'stats'        => [
                    'total'     => (int)($stats['total'] ?? 0),
                    'today'     => (int)($stats['today_count'] ?? 0),
                    'upcoming'  => (int)($stats['upcoming_count'] ?? 0),
                    'completed' => (int)($stats['completed_count'] ?? 0),
                    'cancelled' => (int)($stats['cancelled_count'] ?? 0)
                ],
                'appointments' => array_map(function($r) {
                    return [
                        'id'               => (int)$r['id'],
                        'customer_id'      => (int)$r['customer_id'],
                        'visitor_id'       => $r['visitor_id'] ? (int)$r['visitor_id'] : (int)$r['customer_id'],
                        'lead_id'          => $r['lead_id'] ? (int)$r['lead_id'] : null,
                        'session_id'       => $r['session_id'] ?? null,
                        'conversation_id'  => $r['conversation_id'] ? (int)$r['conversation_id'] : null,
                        'customer_name'    => $r['display_customer_name'],
                        'customer_phone'   => $r['display_customer_phone'],
                        'customer_email'   => $r['display_customer_email'],
                        'title'            => $r['title'],
                        'appointment_type' => $r['appointment_type'] ?? 'consultation',
                        'meeting_type'     => $r['meeting_type'] ?? ($r['appointment_type'] ?? 'consultation'),
                        'slot_datetime'    => $r['slot_datetime'],
                        'formatted_slot'   => $r['formatted_slot'],
                        'slot_date'        => $r['slot_date'],
                        'slot_time'        => $r['slot_time'],
                        'timezone'         => $r['timezone'] ?? 'Asia/Kolkata',
                        'status'           => $r['status'],
                        'notes'            => $r['notes'] ?? '',
                        'meet_link'        => $r['meet_link'] ?? 'https://meet.google.com/cp-consult',
                        'calendar_event_id'=> $r['calendar_event_id'] ?? ($r['google_event_id'] ?? null),
                        'consultant'       => [
                            'id'         => $r['assigned_user_id'] ? (int)$r['assigned_user_id'] : null,
                            'name'       => $r['consultant_name'] ?? 'Company Consultant',
                            'job_title'  => $r['consultant_job_title'] ?? 'Advisor',
                            'avatar_url' => $r['consultant_avatar'] ?? null
                        ]
                    ];
                }, $rows)
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        // 2. Book New Appointment (Section 11 database attributes)
        case 'book':
        case 'create':
            $customerId     = (int)($data['customer_id'] ?? 0);
            $visitorId      = !empty($data['visitor_id']) ? (int)$data['visitor_id'] : null;
            $leadId         = !empty($data['lead_id']) ? (int)$data['lead_id'] : null;
            $sessionId      = trim($data['session_id'] ?? '');
            $conversationId = !empty($data['conversation_id']) ? (int)$data['conversation_id'] : null;
            $assignedUserId = !empty($data['assigned_user_id']) ? (int)$data['assigned_user_id'] : null;
            $title          = trim($data['title'] ?? 'Admissions & Solution Discovery Call');
            $type           = trim($data['appointment_type'] ?? ($data['meeting_type'] ?? 'consultation'));
            $slotStr        = trim($data['slot_datetime'] ?? '');
            $notes          = trim($data['notes'] ?? '');
            $meetLink       = trim($data['meet_link'] ?? '');
            $cName          = trim($data['customer_name'] ?? '');
            $cEmail         = trim($data['customer_email'] ?? '');
            $cPhone         = trim($data['customer_phone'] ?? '');

            if (empty($meetLink)) {
                $wCfg = $pdo->query("SELECT calendar_meet_url FROM `widget_settings` WHERE company_id = {$companyId} LIMIT 1")->fetch();
                $meetLink = !empty($wCfg['calendar_meet_url']) ? $wCfg['calendar_meet_url'] : 'https://meet.google.com/cp-consult';
            }

            if (empty($slotStr)) {
                $slotStr = date('Y-m-d H:i:s', strtotime('+1 day 11:00:00'));
            }
            $slotDatetime = date('Y-m-d H:i:s', strtotime($slotStr));
            $apptDate = date('Y-m-d', strtotime($slotDatetime));
            $startTime = date('H:i:s', strtotime($slotDatetime));
            $endTime = date('H:i:s', strtotime($slotDatetime) + 1800); // 30 min duration default

            // Prevent Double Booking Collision (Section 10)
            if ($assignedUserId) {
                $collCheck = $pdo->prepare("
                    SELECT id FROM `appointments`
                    WHERE `company_id` = ? AND `assigned_user_id` = ? AND `slot_datetime` = ? AND `status` != 'cancelled'
                    LIMIT 1
                ");
                $collCheck->execute([$companyId, $assignedUserId, $slotDatetime]);
                if ($collCheck->fetch()) {
                    http_response_code(409);
                    echo json_encode(['success' => false, 'error' => 'This appointment slot is already booked for this advisor. Please select another time slot.']);
                    exit;
                }
            }

            if (!$customerId && $leadId) {
                $lStmt = $pdo->prepare("SELECT customer_id, conversation_id FROM `leads` WHERE id = ? AND company_id = ? LIMIT 1");
                $lStmt->execute([$leadId, $companyId]);
                $lRow = $lStmt->fetch();
                if ($lRow) {
                    $customerId = (int)$lRow['customer_id'];
                    if (!$conversationId && !empty($lRow['conversation_id'])) $conversationId = (int)$lRow['conversation_id'];
                }
            }

            // Auto-resolve or create customer if name/email/phone provided
            if (!$customerId && (!empty($cEmail) || !empty($cPhone) || !empty($cName))) {
                if ($cEmail) {
                    $chkC = $pdo->prepare("SELECT id FROM `customers` WHERE company_id = ? AND email = ? LIMIT 1");
                    $chkC->execute([$companyId, $cEmail]);
                    $customerId = (int)($chkC->fetchColumn() ?: 0);
                }
                if (!$customerId && $cPhone) {
                    $chkC = $pdo->prepare("SELECT id FROM `customers` WHERE company_id = ? AND phone = ? LIMIT 1");
                    $chkC->execute([$companyId, $cPhone]);
                    $customerId = (int)($chkC->fetchColumn() ?: 0);
                }
                if (!$customerId) {
                    $insC = $pdo->prepare("INSERT INTO `customers` (`company_id`, `name`, `email`, `phone`, `first_seen_at`, `last_seen_at`) VALUES (?, ?, ?, ?, NOW(), NOW())");
                    $insC->execute([$companyId, $cName ?: 'Prospect', $cEmail ?: null, $cPhone ?: null]);
                    $customerId = (int)$pdo->lastInsertId();
                }
            }

            if (!$customerId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Customer identification required']);
                exit;
            }

            if (!$visitorId) {
                $visitorId = $customerId;
            }

            $ins = $pdo->prepare("
                INSERT INTO `appointments` 
                (`company_id`, `customer_id`, `visitor_id`, `lead_id`, `session_id`, `conversation_id`, `assigned_user_id`, `customer_name`, `customer_phone`, `customer_email`, `title`, `appointment_type`, `meeting_type`, `appointment_date`, `start_time`, `end_time`, `timezone`, `slot_datetime`, `status`, `notes`, `meet_link`, `created_at`, `updated_at`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Asia/Kolkata', ?, 'scheduled', ?, ?, NOW(), NOW())
            ");
            $ins->execute([
                $companyId, $customerId, $visitorId, $leadId, $sessionId ?: null, $conversationId ?: null,
                $assignedUserId, $cName ?: null, $cPhone ?: null, $cEmail ?: null,
                $title, $type, $type, $apptDate, $startTime, $endTime,
                $slotDatetime, $notes, $meetLink
            ]);
            $appId = (int)$pdo->lastInsertId();

            // Update lead stage to Proposal / Demo booked if applicable
            if ($leadId) {
                $pdo->prepare("UPDATE `leads` SET `stage_name` = 'PROPOSAL', `updated_at` = NOW() WHERE `id` = ? AND `company_id` = ?")
                    ->execute([$leadId, $companyId]);
            }

            // Dispatch event for CRM journey timeline
            $pdo->prepare("
                INSERT INTO `lead_events`
                (`company_id`, `lead_id`, `customer_id`, `event_type`, `description`, `event_data_json`, `created_at`)
                VALUES (?, ?, ?, 'APPOINTMENT_BOOKED', ?, ?, NOW())
            ")->execute([
                $companyId,
                $leadId,
                $customerId,
                "Appointment booked: {$title} for {$slotDatetime}",
                json_encode([
                    'appointment_id' => $appId,
                    'slot_datetime'  => $slotDatetime,
                    'session_id'     => $sessionId,
                    'consultant_id'  => $assignedUserId
                ])
            ]);

            echo json_encode([
                'success'        => true,
                'appointment_id' => $appId,
                'slot_datetime'  => $slotDatetime,
                'message'        => 'Appointment booked successfully'
            ]);
            break;

        // 3. Reschedule Appointment (Section 13)
        case 'reschedule':
            $appId = (int)($data['id'] ?? 0);
            $newSlot = trim($data['slot_datetime'] ?? '');

            if (!$appId || empty($newSlot) || strtotime($newSlot) === false) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Valid appointment ID and new slot datetime are required.']);
                exit;
            }

            $slotDatetime = date('Y-m-d H:i:s', strtotime($newSlot));
            $apptDate = date('Y-m-d', strtotime($slotDatetime));
            $startTime = date('H:i:s', strtotime($slotDatetime));
            $endTime = date('H:i:s', strtotime($slotDatetime) + 1800);

            // Fetch existing appointment to verify tenant and advisor
            $stmt = $pdo->prepare("SELECT * FROM `appointments` WHERE `id` = ? AND `company_id` = ? LIMIT 1");
            $stmt->execute([$appId, $companyId]);
            $appt = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$appt) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Appointment not found in this workspace.']);
                exit;
            }

            // Check collision on new slot
            if (!empty($appt['assigned_user_id'])) {
                $coll = $pdo->prepare("
                    SELECT id FROM `appointments`
                    WHERE `company_id` = ? AND `assigned_user_id` = ? AND `slot_datetime` = ? AND `id` != ? AND `status` != 'cancelled'
                    LIMIT 1
                ");
                $coll->execute([$companyId, $appt['assigned_user_id'], $slotDatetime, $appId]);
                if ($coll->fetch()) {
                    http_response_code(409);
                    echo json_encode(['success' => false, 'error' => 'The selected slot conflicts with an existing booking for this consultant.']);
                    exit;
                }
            }

            $pdo->prepare("
                UPDATE `appointments`
                SET `slot_datetime` = ?,
                    `appointment_date` = ?,
                    `start_time` = ?,
                    `end_time` = ?,
                    `status` = 'rescheduled',
                    `updated_at` = NOW()
                WHERE `id` = ? AND `company_id` = ?
            ")->execute([$slotDatetime, $apptDate, $startTime, $endTime, $appId, $companyId]);

            // Update CRM Timeline
            if (!empty($appt['lead_id'])) {
                $pdo->prepare("
                    INSERT INTO `lead_events`
                    (`company_id`, `lead_id`, `customer_id`, `event_type`, `description`, `event_data_json`, `created_at`)
                    VALUES (?, ?, ?, 'APPOINTMENT_RESCHEDULED', ?, ?, NOW())
                ")->execute([
                    $companyId,
                    (int)$appt['lead_id'],
                    (int)$appt['customer_id'],
                    "Appointment rescheduled to {$slotDatetime}",
                    json_encode(['appointment_id' => $appId, 'new_slot' => $slotDatetime])
                ]);
            }

            echo json_encode([
                'success'       => true,
                'message'       => 'Appointment rescheduled successfully.',
                'slot_datetime' => $slotDatetime,
                'status'        => 'rescheduled'
            ]);
            break;

        // 4. Update Status or Cancel (Section 13)
        case 'cancel':
        case 'update_status':
            $appId = (int)($data['id'] ?? 0);
            $newStatus = trim($data['status'] ?? ($action === 'cancel' ? 'cancelled' : 'completed'));
            $reason = trim($data['reason'] ?? '');

            if (!$appId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Appointment ID required']);
                exit;
            }

            $allowedStatuses = ['scheduled', 'rescheduled', 'completed', 'cancelled', 'no_show'];
            if (!in_array($newStatus, $allowedStatuses)) {
                $newStatus = 'completed';
            }

            $stmt = $pdo->prepare("SELECT * FROM `appointments` WHERE id = ? AND company_id = ? LIMIT 1");
            $stmt->execute([$appId, $companyId]);
            $appt = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$appt) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Appointment not found in this workspace.']);
                exit;
            }

            $pdo->prepare("UPDATE `appointments` SET `status` = ?, `updated_at` = NOW() WHERE id = ? AND company_id = ?")
                ->execute([$newStatus, $appId, $companyId]);

            if ($newStatus === 'cancelled' && !empty($appt['lead_id'])) {
                $pdo->prepare("
                    INSERT INTO `lead_events`
                    (`company_id`, `lead_id`, `customer_id`, `event_type`, `description`, `event_data_json`, `created_at`)
                    VALUES (?, ?, ?, 'APPOINTMENT_CANCELLED', ?, ?, NOW())
                ")->execute([
                    $companyId,
                    (int)$appt['lead_id'],
                    (int)$appt['customer_id'],
                    "Appointment cancelled" . ($reason ? ": {$reason}" : ""),
                    json_encode(['appointment_id' => $appId, 'reason' => $reason])
                ]);
            }

            echo json_encode(['success' => true, 'status' => $newStatus, 'message' => "Appointment marked as {$newStatus}."]);
            break;

        // 5. Get Appointment Details
        case 'details':
            $appId = (int)($_GET['id'] ?? $data['id'] ?? 0);
            if (!$appId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Appointment ID is required']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT a.*, 
                       c.name as customer_name_orig, c.phone as customer_phone_orig, c.email as customer_email_orig,
                       l.title as lead_title, l.priority as lead_priority, l.stage_name as lead_stage,
                       u.name as consultant_name, u.job_title as consultant_job_title, u.email as consultant_email, u.avatar_url as consultant_avatar
                FROM `appointments` a
                LEFT JOIN `customers` c ON c.id = a.customer_id
                LEFT JOIN `leads` l ON l.id = a.lead_id
                LEFT JOIN `users` u ON u.id = a.assigned_user_id
                WHERE a.id = ? AND a.company_id = ?
                LIMIT 1
            ");
            $stmt->execute([$appId, $companyId]);
            $appt = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$appt) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Appointment not found in this workspace.']);
                exit;
            }

            echo json_encode([
                'success'     => true,
                'appointment' => [
                    'id'               => (int)$appt['id'],
                    'title'            => $appt['title'],
                    'appointment_type' => $appt['appointment_type'],
                    'slot_datetime'    => $appt['slot_datetime'],
                    'status'           => $appt['status'],
                    'meet_link'        => $appt['meet_link'],
                    'notes'            => $appt['notes'],
                    'customer'         => [
                        'id'    => (int)$appt['customer_id'],
                        'name'  => $appt['customer_name'] ?: ($appt['customer_name_orig'] ?: 'Prospect'),
                        'email' => $appt['customer_email'] ?: ($appt['customer_email_orig'] ?: ''),
                        'phone' => $appt['customer_phone'] ?: ($appt['customer_phone_orig'] ?: '')
                    ],
                    'lead'             => $appt['lead_id'] ? [
                        'id'         => (int)$appt['lead_id'],
                        'title'      => $appt['lead_title'],
                        'stage'      => $appt['lead_stage'],
                        'priority'   => $appt['lead_priority']
                    ] : null,
                    'consultant'       => [
                        'id'         => $appt['assigned_user_id'] ? (int)$appt['assigned_user_id'] : null,
                        'name'       => $appt['consultant_name'] ?? 'Company Consultant',
                        'job_title'  => $appt['consultant_job_title'] ?? 'Advisor',
                        'email'      => $appt['consultant_email'] ?? '',
                        'avatar_url' => $appt['consultant_avatar'] ?? null
                    ],
                    'session_id'       => $appt['session_id'],
                    'conversation_id'  => $appt['conversation_id'] ? (int)$appt['conversation_id'] : null,
                    'created_at'       => $appt['created_at']
                ]
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
