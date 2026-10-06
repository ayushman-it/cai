<?php
/**
 * CUBOIDPILOT — REVENUE LIFECYCLE & REMINDERS API
 * Handles Automated & Manual Reminders, Promise-to-Pay tracking,
 * and Bulk Student / Fee Schedule Import.
 */

if (php_sapi_name() !== 'cli' && !headers_sent()) {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Company-Key");
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/entitlements.php';

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

$pdo = getDbConnection();

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$rawInput = file_get_contents('php://input');
$jsonData = json_decode($rawInput, true) ?? [];
if (!empty($jsonData['action'])) {
    $action = $jsonData['action'];
}

// Special case: CSV Template download
if ($action === 'template_csv') {
    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="cai_students_dues_template.csv"');
    }
    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'student_name',
        'phone',
        'email',
        'course_name',
        'total_fee',
        'paid_fee',
        'remaining_fee',
        'due_date',
        'repeat_reminder_days'
    ]);
    fputcsv($output, [
        'Aarav Sharma',
        '+919876543210',
        'aarav.sharma@example.com',
        'Full Stack Web Development Cohort',
        '25000',
        '10000',
        '15000',
        date('Y-m-d', strtotime('+7 days')),
        '7'
    ]);
    fputcsv($output, [
        'Priya Patel',
        '+919876543211',
        'priya.patel@example.com',
        'Data Science & AI Engineering',
        '30000',
        '15000',
        '15000',
        date('Y-m-d', strtotime('+14 days')),
        '7'
    ]);
    fclose($output);
    exit;
}

// Resolve Company ID
$companyId = null;
if (!empty($_SESSION['company_id'])) {
    $companyId = (int)$_SESSION['company_id'];
} else {
    $companyKey = trim($_GET['company_key'] ?? $_POST['company_key'] ?? $jsonData['company_key'] ?? $_SERVER['HTTP_X_COMPANY_KEY'] ?? '');
    if (!empty($companyKey)) {
        if ($companyKey === 'default' || $companyKey === 'cp_live_cuboidpilot' || $companyKey === 'cuboidpilot') {
            $companyKey = 'cp_live_cuboidsoft';
        }
        $cStmt = $pdo->prepare("SELECT id FROM `companies` WHERE `company_key` = ? OR `slug` = ? LIMIT 1");
        $cStmt->execute([$companyKey, $companyKey]);
        $companyId = (int)$cStmt->fetchColumn();
    }
}

if (!$companyId) {
    if (!headers_sent()) {
        header("Content-Type: application/json; charset=UTF-8");
        http_response_code(401);
    }
    echo json_encode(['success' => false, 'error' => 'Authentication or valid company_key required.']);
    exit;
}

if (!headers_sent()) {
    header("Content-Type: application/json; charset=UTF-8");
}

try {
    switch ($action) {
        // =================================================================
        // ACTION: list
        // =================================================================
        case 'list':
            $status = trim($_GET['status'] ?? $jsonData['status'] ?? '');
            $customerId = (int)($_GET['customer_id'] ?? $jsonData['customer_id'] ?? 0);

            $sql = "
                SELECT r.*, c.name as customer_name, c.phone as customer_phone, c.email as customer_email,
                       l.title as lead_title, i.installment_number
                FROM `reminders` r
                JOIN `customers` c ON c.id = r.customer_id
                LEFT JOIN `leads` l ON l.id = r.lead_id
                LEFT JOIN `installments` i ON i.id = r.installment_id
                WHERE r.`company_id` = ?
            ";
            $params = [$companyId];

            if (!empty($status)) {
                $sql .= " AND r.`status` = ?";
                $params[] = strtoupper($status);
            }
            if ($customerId > 0) {
                $sql .= " AND r.`customer_id` = ?";
                $params[] = $customerId;
            }

            $sql .= " ORDER BY r.`due_date` ASC, r.`id` DESC LIMIT 100";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Compute summary metrics
            $sumStmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total_count,
                    COALESCE(SUM(CASE WHEN `status` = 'PENDING' THEN amount_inr ELSE 0 END), 0) as pending_amount,
                    COALESCE(SUM(CASE WHEN `status` = 'PROMISED' THEN amount_inr ELSE 0 END), 0) as promised_amount,
                    COALESCE(SUM(CASE WHEN `status` = 'RESOLVED' THEN amount_inr ELSE 0 END), 0) as resolved_amount,
                    COALESCE(SUM(CASE WHEN `due_date` < CURDATE() AND `status` = 'PENDING' THEN 1 ELSE 0 END), 0) as overdue_count
                FROM `reminders`
                WHERE `company_id` = ?
            ");
            $sumStmt->execute([$companyId]);
            $metrics = $sumStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success'   => true,
                'metrics'   => $metrics,
                'reminders' => $reminders
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;

        // =================================================================
        // ACTION: create_manual
        // =================================================================
        case 'create_manual':
            $payload = !empty($jsonData) ? $jsonData : $_POST;

            $customerId = (int)($payload['customer_id'] ?? 0);
            $amount = max(0, (int)($payload['amount_inr'] ?? 0));
            $title = trim($payload['title'] ?? 'Fee Collection Reminder');
            $dueDate = trim($payload['due_date'] ?? date('Y-m-d', strtotime('+3 days')));
            $repeatFreq = strtoupper(trim($payload['repeat_frequency'] ?? 'NONE'));
            $channels = trim($payload['channels'] ?? 'whatsapp');
            $notes = trim($payload['notes'] ?? '');
            $messageTemplate = trim($payload['message_template'] ?? '');

            if (!$customerId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'customer_id is required.']);
                exit;
            }

            // Verify customer belongs to company
            $cChk = $pdo->prepare("SELECT id, name FROM `customers` WHERE id = ? AND company_id = ? LIMIT 1");
            $cChk->execute([$customerId, $companyId]);
            $customer = $cChk->fetch(PDO::FETCH_ASSOC);
            if (!$customer) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Customer not found.']);
                exit;
            }

            // Find associated lead if any
            $lStmt = $pdo->prepare("SELECT id FROM `leads` WHERE customer_id = ? AND company_id = ? ORDER BY id DESC LIMIT 1");
            $lStmt->execute([$customerId, $companyId]);
            $leadId = $lStmt->fetchColumn() ?: null;

            if (!in_array($repeatFreq, ['NONE', 'DAILY', 'WEEKLY', 'MONTHLY', 'QUARTERLY'])) {
                $repeatFreq = 'NONE';
            }

            $ins = $pdo->prepare("
                INSERT INTO `reminders` (
                    `company_id`, `customer_id`, `lead_id`, `reminder_type`,
                    `title`, `message_template`, `amount_inr`, `due_date`,
                    `scheduled_at`, `repeat_frequency`, `channels`, `status`,
                    `created_by_user_id`, `notes`, `created_at`
                ) VALUES (
                    ?, ?, ?, 'MANUAL_COLLECTION',
                    ?, ?, ?, ?,
                    NOW(), ?, ?, 'PENDING',
                    ?, ?, NOW()
                )
            ");
            $ins->execute([
                $companyId, $customerId, $leadId,
                $title, $messageTemplate, $amount, $dueDate,
                $repeatFreq, $channels,
                $_SESSION['user_id'] ?? null, $notes
            ]);
            $reminderId = (int)$pdo->lastInsertId();

            echo json_encode([
                'success'     => true,
                'reminder_id' => $reminderId,
                'message'     => 'Collection reminder scheduled successfully.'
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;

        // =================================================================
        // ACTION: promise_to_pay
        // =================================================================
        case 'promise_to_pay':
            $payload = !empty($jsonData) ? $jsonData : $_POST;
            $reminderId = (int)($payload['reminder_id'] ?? 0);
            $promiseDate = trim($payload['promise_to_pay_date'] ?? '');
            $note = trim($payload['notes'] ?? 'Customer promised to pay on ' . $promiseDate);

            if (!$reminderId || empty($promiseDate)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'reminder_id and promise_to_pay_date are required.']);
                exit;
            }

            $rStmt = $pdo->prepare("SELECT * FROM `reminders` WHERE id = ? AND company_id = ? LIMIT 1");
            $rStmt->execute([$reminderId, $companyId]);
            $rem = $rStmt->fetch(PDO::FETCH_ASSOC);

            if (!$rem) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Reminder not found.']);
                exit;
            }

            $pdo->prepare("
                UPDATE `reminders`
                SET `promise_to_pay_date` = ?,
                    `status` = 'PROMISED',
                    `notes` = CONCAT(COALESCE(notes, ''), '\n[Promise-to-Pay: ', ?, ']'),
                    `updated_at` = NOW()
                WHERE id = ? AND company_id = ?
            ")->execute([$promiseDate, $note, $reminderId, $companyId]);

            // Sync with installment if linked
            if (!empty($rem['installment_id'])) {
                $pdo->prepare("
                    UPDATE `installments`
                    SET `notes` = CONCAT(COALESCE(notes, ''), ' [Promise Date: ', ?, ']')
                    WHERE id = ? AND company_id = ?
                ")->execute([$promiseDate, (int)$rem['installment_id'], $companyId]);
            }

            echo json_encode([
                'success'             => true,
                'reminder_id'         => $reminderId,
                'promise_to_pay_date' => $promiseDate,
                'message'             => "Promise-to-pay date recorded for {$promiseDate}."
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;

        // =================================================================
        // ACTION: mark_resolved
        // =================================================================
        case 'mark_resolved':
            $payload = !empty($jsonData) ? $jsonData : $_POST;
            $reminderId = (int)($payload['reminder_id'] ?? 0);
            $utrRef = trim($payload['reference'] ?? 'UTR-RESOLVED');

            if (!$reminderId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'reminder_id is required.']);
                exit;
            }

            $pdo->prepare("
                UPDATE `reminders`
                SET `status` = 'RESOLVED',
                    `notes` = CONCAT(COALESCE(notes, ''), ' [Resolved: ', ?, ']'),
                    `updated_at` = NOW()
                WHERE id = ? AND company_id = ?
            ")->execute([$utrRef, $reminderId, $companyId]);

            echo json_encode(['success' => true, 'message' => 'Reminder marked as resolved.']);
            exit;

        // =================================================================
        // ACTION: bulk_import_students
        // =================================================================
        case 'bulk_import_students':
            $csvContent = '';

            if (!empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
                $csvContent = file_get_contents($_FILES['csv_file']['tmp_name']);
            } elseif (!empty($_POST['csv_content'])) {
                $csvContent = $_POST['csv_content'];
            } elseif (!empty($jsonData['csv_content'])) {
                $csvContent = $jsonData['csv_content'];
            }

            if (empty($csvContent)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'No CSV content provided for student bulk import.']);
                exit;
            }

            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $csvContent);
            rewind($stream);

            $headers = null;
            $importedCount = 0;
            $totalReceivables = 0;
            $errors = [];
            $rowNum = 0;

            while (($row = fgetcsv($stream, 4096, ",")) !== false) {
                $rowNum++;
                if (empty($row) || (count($row) === 1 && $row[0] === null)) {
                    continue;
                }

                if ($headers === null) {
                    $headers = array_map(function($h) {
                        return strtolower(trim(preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $h)));
                    }, $row);
                    continue;
                }

                if (count($row) < 3) continue;

                $rowData = [];
                foreach ($headers as $idx => $hName) {
                    $rowData[$hName] = trim($row[$idx] ?? '');
                }

                $studentName = $rowData['student_name'] ?? ($row[0] ?? '');
                $phone = $rowData['phone'] ?? ($row[1] ?? '');
                $email = $rowData['email'] ?? ($row[2] ?? '');
                $courseName = $rowData['course_name'] ?? 'Cohort Course';
                $totalFee = max(0, (int)($rowData['total_fee'] ?? 0));
                $paidFee = max(0, (int)($rowData['paid_fee'] ?? 0));
                $remFee = max(0, (int)($rowData['remaining_fee'] ?? ($totalFee - $paidFee)));
                $dueDate = !empty($rowData['due_date']) ? $rowData['due_date'] : date('Y-m-d', strtotime('+7 days'));

                if (empty($studentName) || (empty($phone) && empty($email))) {
                    $errors[] = "Row {$rowNum}: Missing student name or contact info.";
                    continue;
                }

                try {
                    // 1. Resolve or Create Customer
                    $cStmt = $pdo->prepare("SELECT id FROM `customers` WHERE `company_id` = ? AND (`phone` = ? OR `email` = ?) LIMIT 1");
                    $cStmt->execute([$companyId, $phone, $email]);
                    $custId = (int)$cStmt->fetchColumn();

                    if (!$custId) {
                        $custUuid = 'cust_' . bin2hex(random_bytes(10));
                        $pdo->prepare("
                            INSERT INTO `customers` (`company_id`, `customer_uuid`, `name`, `phone`, `email`, `whatsapp_number`, `first_seen_at`, `last_seen_at`)
                            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                        ")->execute([$companyId, $custUuid, $studentName, $phone ?: null, $email ?: null, $phone ?: null]);
                        $custId = (int)$pdo->lastInsertId();
                    }

                    // 2. Create Lead Record
                    $leadUuid = 'lead_' . bin2hex(random_bytes(8));
                    $commStatus = ($remFee <= 0) ? 'PAID' : (($paidFee > 0) ? 'PARTIAL' : 'PENDING');
                    $stageName = ($remFee <= 0) ? 'WON' : 'PAYMENT_PENDING';

                    $pdo->prepare("
                        INSERT INTO `leads` (
                            `company_id`, `customer_id`, `lead_uuid`, `title`,
                            `total_amount`, `paid_amount`, `remaining_amount`, `next_due_date`,
                            `payment_type`, `commercial_status`, `stage_name`, `source`,
                            `created_at`, `updated_at`
                        ) VALUES (
                            ?, ?, ?, ?,
                            ?, ?, ?, ?,
                            'INSTALLMENT', ?, ?, 'bulk_import',
                            NOW(), NOW()
                        )
                    ")->execute([
                        $companyId, $custId, $leadUuid, "{$studentName} - {$courseName}",
                        $totalFee, $paidFee, $remFee, $dueDate,
                        $commStatus, $stageName
                    ]);
                    $leadId = (int)$pdo->lastInsertId();

                    // 3. If outstanding balance exists, create Installment and Reminder
                    if ($remFee > 0) {
                        $pdo->prepare("
                            INSERT INTO `installments` (
                                `company_id`, `lead_id`, `customer_id`, `installment_number`,
                                `title`, `amount_inr`, `due_date`, `status`, `reminder_days_before`,
                                `reminder_status`, `notes`, `created_at`
                            ) VALUES (
                                ?, ?, ?, 1,
                                ?, ?, ?, 'pending', 4,
                                'scheduled', 'Imported fee schedule balance', NOW()
                            )
                        ")->execute([
                            $companyId, $leadId, $custId,
                            "Fee Due: {$courseName}", $remFee, $dueDate
                        ]);
                        $instId = (int)$pdo->lastInsertId();

                        $pdo->prepare("
                            INSERT INTO `reminders` (
                                `company_id`, `customer_id`, `lead_id`, `installment_id`,
                                `reminder_type`, `title`, `amount_inr`, `due_date`,
                                `repeat_frequency`, `channels`, `status`, `notes`, `created_at`
                            ) VALUES (
                                ?, ?, ?, ?,
                                'AUTOMATED_INSTALLMENT', ?, ?, ?,
                                'WEEKLY', 'whatsapp', 'PENDING', 'Imported from student fee roster', NOW()
                            )
                        ")->execute([
                            $companyId, $custId, $leadId, $instId,
                            "Fee Reminder: {$courseName}", $remFee, $dueDate
                        ]);

                        $totalReceivables += $remFee;
                    }

                    $importedCount++;

                } catch (Exception $rowEx) {
                    $errors[] = "Row {$rowNum} ({$studentName}): " . $rowEx->getMessage();
                }
            }
            fclose($stream);

            echo json_encode([
                'success'           => true,
                'message'           => "Import completed. {$importedCount} student records processed.",
                'imported_count'    => $importedCount,
                'total_receivables' => $totalReceivables,
                'errors'            => $errors
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Unknown action '{$action}'."]);
            exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Reminders API Error: ' . $e->getMessage()]);
    exit;
}
