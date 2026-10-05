<?php
/**
 * CUBOIDPILOT — AI APPOINTMENT BOOKING & INTENT HELPER (Sections 7, 8, 9, 10, 11)
 * Multi-lingual intent detection, collision-free slot generation,
 * double-booking prevention, CRM timeline sync, and database persistence.
 */

if (!defined('CUBOID_INIT')) {
    define('CUBOID_INIT', true);
}

/**
 * Multi-lingual Appointment Intent Detection (English, Hindi, Hinglish)
 */
function isAppointmentIntent(string $text): bool {
    $clean = mb_strtolower(trim($text));
    if (empty($clean)) return false;

    $patterns = [
        '/\b(appointment|appointments|schedule|scheduling|book|booking|demo|meeting|consultation)\b/iu',
        '/\b(call\s*fix|slot|time\s*slot|free\s*slot|available\s*time|counselor|counsellor)\b/iu',
        '/\b(baat\s*karni|call\s*pe\s*baat|timing\s*kya|kab\s*mil|milna\s*hai|demo\s*chahiye|call\s*karo)\b/iu',
        '/\b(talk\s*to|connect\s*call|discuss|discovery\s*call|slot\s*chahiye|time\s*batao)\b/iu',
        '/\b(call\s*schedule|demo\s*book|meeting\s*fix|appointment\s*lena)\b/iu'
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $clean)) {
            return true;
        }
    }
    return false;
}

/**
 * Fetch Company Calendar Configuration with tenant fallback
 */
function getCompanyCalendarConfig(PDO $pdo, int $companyId): array {
    $stmt = $pdo->prepare("SELECT * FROM `company_calendar_configs` WHERE `company_id` = ? LIMIT 1");
    $stmt->execute([$companyId]);
    $cfg = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cfg) {
        // Fallback to default working schedule
        return [
            'provider'                => 'native',
            'working_days'            => '1,2,3,4,5,6', // Mon-Sat
            'working_hours_start'     => '10:00:00',
            'working_hours_end'       => '18:00:00',
            'slot_duration_minutes'   => 30,
            'buffer_before_minutes'   => 0,
            'buffer_after_minutes'    => 10,
            'min_booking_notice_hours'=> 2,
            'max_advance_booking_days'=> 14,
            'timezone'                => 'Asia/Kolkata',
            'is_connected'            => 0,
            'meeting_title_template'  => 'Consultation: {customer_name}'
        ];
    }
    return $cfg;
}

/**
 * Generate Collision-Free Appointment Slots (Sections 8, 9 & 10)
 */
function getAvailableAppointmentSlots(PDO $pdo, int $companyId, int $count = 4): array {
    $cfg = getCompanyCalendarConfig($pdo, $companyId);
    $workingDays = array_map('intval', explode(',', $cfg['working_days'] ?? '1,2,3,4,5,6'));
    $duration = (int)($cfg['slot_duration_minutes'] ?? 30);
    if ($duration <= 0) $duration = 30;

    $slots = [];
    $candidateTimes = ['10:00', '11:30', '14:00', '16:00', '17:15'];

    // Check up to 7 advance days
    for ($d = 1; $d <= 7; $d++) {
        $timestamp = strtotime("+{$d} day");
        $dayOfWeek = (int)date('N', $timestamp); // 1 = Mon, 7 = Sun

        if (!in_array($dayOfWeek, $workingDays, true)) {
            continue;
        }

        $dateStr = date('Y-m-d', $timestamp);

        foreach ($candidateTimes as $timeStr) {
            $slotDt = "{$dateStr} {$timeStr}:00";

            // Collision check against existing appointments
            $chk = $pdo->prepare("
                SELECT id FROM `appointments`
                WHERE `company_id` = ? 
                  AND `status` != 'cancelled' 
                  AND `slot_datetime` = ?
                LIMIT 1
            ");
            $chk->execute([$companyId, $slotDt]);
            if ($chk->fetch()) {
                continue; // Collided, skip
            }

            $label = date('D, M j \a\t g:i A', strtotime($slotDt));
            $slots[] = [
                'slot_datetime' => $slotDt,
                'label'         => $label,
                'date'          => $dateStr,
                'time'          => date('g:i A', strtotime($slotDt)),
                'duration'      => $duration
            ];

            if (count($slots) >= $count) {
                break 2;
            }
        }
    }

    return $slots;
}

/**
 * Book Appointment Directly from AI Chat Session (Section 10 & 11)
 */
function bookAppointmentFromChat(PDO $pdo, int $companyId, array $data): array {
    $slotDatetime = trim($data['slot_datetime'] ?? '');
    if (empty($slotDatetime) || strtotime($slotDatetime) === false) {
        return ['success' => false, 'error' => 'invalid_time', 'message' => 'Please provide a valid appointment date and time.'];
    }

    $slotDatetime = date('Y-m-d H:i:s', strtotime($slotDatetime));
    $apptDate = date('Y-m-d', strtotime($slotDatetime));
    $startTime = date('H:i:s', strtotime($slotDatetime));
    $endTime = date('H:i:s', strtotime($slotDatetime) + 1800);

    // 1. Strict Double-Booking Collision Prevention (Section 10)
    $collStmt = $pdo->prepare("
        SELECT id FROM `appointments`
        WHERE `company_id` = ? 
          AND `status` != 'cancelled' 
          AND `slot_datetime` = ?
        LIMIT 1
    ");
    $collStmt->execute([$companyId, $slotDatetime]);
    if ($collStmt->fetch()) {
        $refreshSlots = getAvailableAppointmentSlots($pdo, $companyId, 3);
        return [
            'success' => false,
            'error'   => 'collision',
            'message' => 'That time slot was just booked by another prospect. Please select another slot below.',
            'slots'   => $refreshSlots
        ];
    }

    $customerId     = (int)($data['customer_id'] ?? 0);
    $visitorId      = !empty($data['visitor_id']) ? (int)$data['visitor_id'] : $customerId;
    $leadId         = !empty($data['lead_id']) ? (int)$data['lead_id'] : null;
    $sessionId      = trim($data['session_id'] ?? '');
    $conversationId = !empty($data['conversation_id']) ? (int)$data['conversation_id'] : null;
    $custName       = trim($data['customer_name'] ?? '');
    $custPhone      = trim($data['customer_phone'] ?? '');
    $custEmail      = trim($data['customer_email'] ?? '');
    $title          = trim($data['title'] ?? ('Demo & Consultation: ' . ($custName ?: 'Prospect')));
    $type           = trim($data['appointment_type'] ?? 'consultation');
    $notes          = trim($data['notes'] ?? 'Booked automatically via AI Chatbot.');
    $meetLink       = trim($data['meet_link'] ?? 'https://meet.google.com/cp-consult');

    $channel = !empty($data['channel']) ? strtolower(trim($data['channel'])) : 'web';
    $meetLink = trim($data['meet_link'] ?? $data['meeting_link'] ?? 'https://meet.google.com/cp-consult');

    // 2. Persist in appointments table with Section 11 attributes
    $ins = $pdo->prepare("
        INSERT INTO `appointments`
        (`company_id`, `customer_id`, `visitor_id`, `lead_id`, `session_id`, `conversation_id`, 
         `customer_name`, `customer_phone`, `customer_email`, `title`, `appointment_type`,
         `appointment_date`, `start_time`, `end_time`, `timezone`, `slot_datetime`, `status`, `created_channel`, `notes`, `meet_link`, `meeting_link`, `created_at`, `updated_at`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Asia/Kolkata', ?, 'scheduled', ?, ?, ?, ?, NOW(), NOW())
    ");
    $ins->execute([
        $companyId, $customerId, $visitorId, $leadId, $sessionId ?: null, $conversationId ?: null,
        $custName ?: null, $custPhone ?: null, $custEmail ?: null,
        $title, $type,
        $apptDate, $startTime, $endTime,
        $slotDatetime, $channel, $notes, $meetLink, $meetLink
    ]);
    $appointmentId = (int)$pdo->lastInsertId();

    // 3. Advance Lead Stage in CRM Pipeline
    if (!$leadId && $customerId) {
        $lStmt = $pdo->prepare("SELECT id FROM `leads` WHERE `customer_id` = ? AND `company_id` = ? ORDER BY id DESC LIMIT 1");
        $lStmt->execute([$customerId, $companyId]);
        $foundLeadId = $lStmt->fetchColumn();
        if ($foundLeadId) {
            $leadId = (int)$foundLeadId;
            $pdo->prepare("UPDATE `appointments` SET `lead_id` = ? WHERE `id` = ?")->execute([$leadId, $appointmentId]);
        }
    }

    if ($leadId) {
        $pdo->prepare("
            UPDATE `leads` 
            SET `stage_name` = 'PROPOSAL', 
                `status` = 'open',
                `last_activity_at` = NOW(),
                `updated_at` = NOW() 
            WHERE `id` = ? AND `company_id` = ?
        ")->execute([$leadId, $companyId]);

        // 4. Log in CRM Activity Timeline (lead_events)
        $pdo->prepare("
            INSERT INTO `lead_events`
            (`company_id`, `lead_id`, `customer_id`, `event_type`, `description`, `event_data_json`, `created_at`)
            VALUES (?, ?, ?, 'APPOINTMENT_BOOKED', ?, ?, NOW())
        ")->execute([
            $companyId,
            $leadId,
            $customerId,
            "Consultation booked for " . date('M j, Y at g:i A', strtotime($slotDatetime)),
            json_encode([
                'appointment_id' => $appointmentId,
                'session_id'     => $sessionId,
                'slot_datetime'  => $slotDatetime,
                'meet_link'      => $meetLink
            ])
        ]);
    }

    return [
        'success'        => true,
        'appointment_id' => $appointmentId,
        'slot_datetime'  => $slotDatetime,
        'formatted_slot' => date('l, F j, Y \a\t g:i A', strtotime($slotDatetime)),
        'meet_link'      => $meetLink,
        'title'          => $title,
        'message'        => '✓ Appointment booked successfully for ' . date('l, F j, Y \a\t g:i A', strtotime($slotDatetime)) . '!'
    ];
}
