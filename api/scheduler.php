<?php
/**
 * CUBOIDPILOT — DETERMINISTIC REMINDER SCHEDULER & PIPELINE WATCHDOG
 * Scans upcoming installment dues, flags overdue payments, and detects high-intent drops.
 * Can be triggered via Cron CLI (php api/scheduler.php) or automated HTTP worker.
 */

if (php_sapi_name() !== 'cli') {
    header("Content-Type: application/json; charset=UTF-8");
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/entitlements.php';

$pdo = getDbConnection();

$results = [
    'started_at'        => date('Y-m-d H:i:s'),
    'reminders_sent'    => 0,
    'overdue_flagged'   => 0,
    'drops_detected'    => 0,
    'errors'            => []
];

try {
    // -------------------------------------------------------------
    // JOB 1: UPCOMING INSTALLMENT REMINDERS (4-DAY LOOKAHEAD)
    // -------------------------------------------------------------
    $remStmt = $pdo->query("
        SELECT i.*, l.title as lead_title, c.name as customer_name, c.phone as customer_phone,
               comp.name as company_name, comp.id as comp_id
        FROM `installments` i
        JOIN `leads` l ON l.id = i.lead_id
        JOIN `customers` c ON c.id = i.customer_id
        JOIN `companies` comp ON comp.id = i.company_id
        WHERE i.status IN ('upcoming', 'pending')
          AND (i.reminder_status = 'none' OR i.reminder_status = 'NONE' OR i.reminder_status IS NULL)
          AND i.due_date <= DATE_ADD(CURDATE(), INTERVAL i.reminder_days_before DAY)
          AND i.due_date >= CURDATE()
        ORDER BY i.due_date ASC
        LIMIT 50
    ");
    $upcoming = $remStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($upcoming as $inst) {
        $cId = (int)$inst['comp_id'];

        // Entitlement Check: can_use_payment_reminders
        if (!checkEntitlement($pdo, $cId, 'can_use_payment_reminders')) {
            continue;
        }

        $custPhone = $inst['customer_phone'];
        if (empty($custPhone)) continue;

        $amtFmt = number_format($inst['amount_inr']);
        $dueDateFmt = date('D, M j, Y', strtotime($inst['due_date']));

        $reminderText = "👋 Hello {$inst['customer_name']},\n\n"
            . "This is a friendly reminder from {$inst['company_name']} regarding your upcoming installment:\n\n"
            . "📌 Schedule: {$inst['title']}\n"
            . "💰 Amount Due: ₹{$amtFmt}\n"
            . "🗓️ Due Date: {$dueDateFmt}\n\n"
            . "To ensure uninterrupted access to your cohort lectures and learning resources, please make your payment by the due date.\n\n"
            . "Reply to this message if you need your payment link resent or have questions.";

        // Record in whatsapp_messages
        $pdo->prepare("
            INSERT INTO `whatsapp_messages`
            (`company_id`, `recipient_phone`, `recipient_name`, `message_type`, `content`, `status`, `metadata_json`, `created_at`)
            VALUES (?, ?, ?, 'customer_message', ?, 'sent', ?, NOW())
        ")->execute([
            $cId,
            $custPhone,
            $inst['customer_name'],
            $reminderText,
            json_encode([
                'installment_id' => $inst['id'],
                'due_date'       => $inst['due_date'],
                'amount_inr'     => $inst['amount_inr']
            ])
        ]);

        // Mark installment reminder as sent
        $pdo->prepare("UPDATE `installments` SET `reminder_status` = 'sent', `reminder_sent_at` = NOW() WHERE id = ?")
            ->execute([$inst['id']]);

        $results['reminders_sent']++;
    }

    // -------------------------------------------------------------
    // JOB 2: SCAN & FLAG OVERDUE INSTALLMENTS
    // -------------------------------------------------------------
    $overdueStmt = $pdo->query("
        SELECT i.id, i.company_id, i.lead_id, i.amount_inr, i.due_date, l.title as lead_title
        FROM `installments` i
        JOIN `leads` l ON l.id = i.lead_id
        WHERE i.status IN ('upcoming', 'pending')
          AND i.due_date < CURDATE()
        LIMIT 50
    ");
    $overdueList = $overdueStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($overdueList as $od) {
        $pdo->prepare("UPDATE `installments` SET `status` = 'overdue' WHERE id = ?")
            ->execute([$od['id']]);

        $pdo->prepare("UPDATE `leads` SET `commercial_status` = 'OVERDUE', `updated_at` = NOW() WHERE id = ?")
            ->execute([$od['lead_id']]);

        // Check if alert needs to be dispatched via alert_logs
        $chkAlert = $pdo->prepare("
            SELECT id FROM `alert_logs` 
            WHERE company_id = ? AND lead_id = ? AND alert_type = 'PAYMENT_OVERDUE' 
              AND sent_at >= (NOW() - INTERVAL 24 HOUR)
            LIMIT 1
        ");
        $chkAlert->execute([$od['company_id'], $od['lead_id']]);

        if (!$chkAlert->fetch()) {
            $pdo->prepare("
                INSERT INTO `alert_logs` (`company_id`, `lead_id`, `alert_type`, `recipient`, `sent_at`)
                VALUES (?, ?, 'PAYMENT_OVERDUE', 'sales_team', NOW())
            ")->execute([$od['company_id'], $od['lead_id']]);
        }

        $results['overdue_flagged']++;
    }

    // -------------------------------------------------------------
    // JOB 3: DETECT HIGH-INTENT LEAD DROP-OFFS (>24 HOURS INACTIVE)
    // -------------------------------------------------------------
    $dropStmt = $pdo->query("
        SELECT id, company_id, title, opportunity_value, last_activity_at
        FROM `leads`
        WHERE status = 'open'
          AND priority IN ('HIGH', 'URGENT')
          AND last_activity_at < (NOW() - INTERVAL 24 HOUR)
          AND last_activity_at > (NOW() - INTERVAL 7 DAY)
        LIMIT 25
    ");
    $droppedLeads = $dropStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($droppedLeads as $dl) {
        $cId = (int)$dl['company_id'];
        $lId = (int)$dl['id'];

        // Deduplication: 24-hour cooldown on HIGH_INTENT_DROPPED alert
        $chk = $pdo->prepare("
            SELECT id FROM `alert_logs` 
            WHERE company_id = ? AND lead_id = ? AND alert_type = 'HIGH_INTENT_DROPPED' 
              AND sent_at >= (NOW() - INTERVAL 24 HOUR)
            LIMIT 1
        ");
        $chk->execute([$cId, $lId]);
        if ($chk->fetch()) continue;

        // Log drop alert
        $pdo->prepare("
            INSERT INTO `alert_logs` (`company_id`, `lead_id`, `alert_type`, `recipient`, `sent_at`)
            VALUES (?, ?, 'HIGH_INTENT_DROPPED', 'closer_team', NOW())
        ")->execute([$cId, $lId]);

        $results['drops_detected']++;
    }

} catch (Exception $e) {
    $results['errors'][] = $e->getMessage();
}

$results['completed_at'] = date('Y-m-d H:i:s');

if (php_sapi_name() === 'cli') {
    echo "=== CUBOIDPILOT SCHEDULER COMPLETED ===\n";
    echo "Reminders Dispatched : " . $results['reminders_sent'] . "\n";
    echo "Overdue Flagged      : " . $results['overdue_flagged'] . "\n";
    echo "Drops Detected       : " . $results['drops_detected'] . "\n";
    if (!empty($results['errors'])) {
        echo "Errors:\n" . implode("\n", $results['errors']) . "\n";
    }
} else {
    echo json_encode(['success' => true, 'summary' => $results]);
}
