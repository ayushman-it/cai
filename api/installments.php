<?php
/**
 * CUBOIDPILOT — COMMERCIAL & INSTALLMENT ENGINE
 * Deterministic schedule creation, installment tracking, manual UTR receipts,
 * and lead commercial state synchronization.
 * AI never invents financial figures — all amounts and dates are calculated here.
 */

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/entitlements.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = getDbConnection();

$input  = file_get_contents('php://input');
$data   = json_decode($input, true) ?? [];
if (empty($data)) {
    $data = $_REQUEST;
}

$action = $_GET['action'] ?? ($data['action'] ?? 'get');

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];

try {
    switch ($action) {
        // 1. Get installments for a lead or customer
        case 'get':
            $leadId = (int)($data['lead_id'] ?? ($_GET['lead_id'] ?? 0));
            $customerId = (int)($data['customer_id'] ?? ($_GET['customer_id'] ?? 0));

            if (!$leadId && !$customerId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'lead_id or customer_id is required']);
                exit;
            }

            $where = "company_id = ?";
            $params = [$companyId];

            if ($leadId) {
                $where .= " AND lead_id = ?";
                $params[] = $leadId;
            } else {
                $where .= " AND customer_id = ?";
                $params[] = $customerId;
            }

            $stmt = $pdo->prepare("
                SELECT id, installment_number, title, amount_inr, due_date, status, paid_at, reminder_days_before, reminder_status, notes 
                FROM `installments` 
                WHERE {$where} 
                ORDER BY installment_number ASC, due_date ASC
            ");
            $stmt->execute($params);
            $installments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch lead commercial summary
            $leadSummary = null;
            if ($leadId) {
                $lStmt = $pdo->prepare("
                    SELECT id, title, total_amount, paid_amount, remaining_amount, next_due_date, commercial_status, payment_type 
                    FROM `leads` 
                    WHERE id = ? AND company_id = ? 
                    LIMIT 1
                ");
                $lStmt->execute([$leadId, $companyId]);
                $leadSummary = $lStmt->fetch(PDO::FETCH_ASSOC);
            }

            echo json_encode([
                'success'      => true,
                'lead'         => $leadSummary,
                'installments' => $installments,
                'count'        => count($installments)
            ]);
            break;

        // 2. Create deterministic installment schedule
        case 'create_plan':
            $leadId       = (int)($data['lead_id'] ?? 0);
            $totalAmount  = (int)($data['total_amount'] ?? 0);
            $downPayment  = (int)($data['down_payment'] ?? 0);
            $numSplits    = max(1, (int)($data['num_installments'] ?? 3));
            $firstDueDate = trim($data['first_due_date'] ?? date('Y-m-d', strtotime('+30 days')));

            if (!$leadId || $totalAmount <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Valid lead_id and positive total_amount are required']);
                exit;
            }

            // Verify lead ownership
            $lStmt = $pdo->prepare("SELECT id, customer_id FROM `leads` WHERE id = ? AND company_id = ? LIMIT 1");
            $lStmt->execute([$leadId, $companyId]);
            $lead = $lStmt->fetch();
            if (!$lead) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Lead not found in this workspace']);
                exit;
            }
            $customerId = (int)$lead['customer_id'];

            // Clear previous upcoming/pending installments for this lead
            $pdo->prepare("DELETE FROM `installments` WHERE lead_id = ? AND company_id = ? AND status IN ('upcoming', 'pending')")
                ->execute([$leadId, $companyId]);

            $splitAmountTotal = $totalAmount - $downPayment;
            $perInstallment = (int)floor($splitAmountTotal / $numSplits);
            $remainder = $splitAmountTotal - ($perInstallment * $numSplits);

            $currentDueDate = strtotime($firstDueDate);
            $createdRows = [];

            // If there is a down payment paid upfront
            if ($downPayment > 0) {
                $ins = $pdo->prepare("
                    INSERT INTO `installments`
                    (`company_id`, `lead_id`, `customer_id`, `installment_number`, `title`, `amount_inr`, `due_date`, `status`, `paid_at`, `notes`, `created_at`)
                    VALUES (?, ?, ?, 1, 'Initial Down Payment', ?, CURDATE(), 'paid', NOW(), 'Upfront seat reservation payment', NOW())
                ");
                $ins->execute([$companyId, $leadId, $customerId, $downPayment]);
                $createdRows[] = [
                    'number' => 1,
                    'title'  => 'Initial Down Payment',
                    'amount' => $downPayment,
                    'status' => 'paid',
                    'due'    => date('Y-m-d')
                ];
            }

            $startIndex = ($downPayment > 0) ? 2 : 1;
            for ($i = 0; $i < $numSplits; $i++) {
                $amt = $perInstallment + ($i === ($numSplits - 1) ? $remainder : 0);
                $dueStr = date('Y-m-d', $currentDueDate);
                $insNum = $startIndex + $i;
                $title = "Installment {$insNum} of " . ($numSplits + ($downPayment > 0 ? 1 : 0));

                $ins = $pdo->prepare("
                    INSERT INTO `installments`
                    (`company_id`, `lead_id`, `customer_id`, `installment_number`, `title`, `amount_inr`, `due_date`, `status`, `reminder_days_before`, `reminder_status`, `created_at`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'upcoming', 4, 'none', NOW())
                ");
                $ins->execute([$companyId, $leadId, $customerId, $insNum, $title, $amt, $dueStr]);

                $createdRows[] = [
                    'number' => $insNum,
                    'title'  => $title,
                    'amount' => $amt,
                    'status' => 'upcoming',
                    'due'    => $dueStr
                ];

                // Next installment 30 days later
                $currentDueDate = strtotime('+30 days', $currentDueDate);
            }

            // Sync Leads record
            $paidSoFar = $downPayment;
            $remaining = $totalAmount - $paidSoFar;
            $commStatus = ($remaining <= 0) ? 'PAID' : (($paidSoFar > 0) ? 'PARTIAL' : 'PENDING');

            $updLead = $pdo->prepare("
                UPDATE `leads`
                SET `total_amount` = ?,
                    `paid_amount` = ?,
                    `remaining_amount` = ?,
                    `next_due_date` = ?,
                    `payment_type` = 'INSTALLMENT',
                    `commercial_status` = ?,
                    `updated_at` = NOW()
                WHERE id = ? AND company_id = ?
            ");
            $updLead->execute([$totalAmount, $paidSoFar, $remaining, $firstDueDate, $commStatus, $leadId, $companyId]);

            echo json_encode([
                'success'           => true,
                'message'           => 'Installment schedule created successfully',
                'total_amount'      => $totalAmount,
                'paid_amount'       => $paidSoFar,
                'remaining_amount'  => $remaining,
                'next_due_date'     => $firstDueDate,
                'schedule'          => $createdRows
            ]);
            break;

        // 3. Mark an installment as paid (Online or Manual Receipt)
        case 'mark_paid':
            $installmentId = (int)($data['installment_id'] ?? 0);
            $paymentRef    = trim($data['payment_ref'] ?? 'UTR-ONLINE');

            if (!$installmentId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'installment_id is required']);
                exit;
            }

            $iStmt = $pdo->prepare("SELECT * FROM `installments` WHERE id = ? AND company_id = ? LIMIT 1");
            $iStmt->execute([$installmentId, $companyId]);
            $inst = $iStmt->fetch(PDO::FETCH_ASSOC);

            if (!$inst) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Installment not found']);
                exit;
            }

            if (strtolower($inst['status']) === 'paid') {
                echo json_encode(['success' => true, 'message' => 'Installment was already marked as PAID']);
                exit;
            }

            // Mark paid
            $pdo->prepare("
                UPDATE `installments`
                SET `status` = 'paid', `paid_at` = NOW(), `notes` = CONCAT(COALESCE(notes, ''), ' [Ref: ', ?, ']')
                WHERE id = ? AND company_id = ?
            ")->execute([$paymentRef, $installmentId, $companyId]);

            // Recompute lead commercial status
            $leadId = (int)$inst['lead_id'];
            $sumStmt = $pdo->prepare("
                SELECT 
                    COALESCE(SUM(CASE WHEN LOWER(status) = 'paid' THEN amount_inr ELSE 0 END), 0) as paid_sum,
                    MIN(CASE WHEN LOWER(status) IN ('upcoming', 'pending') THEN due_date ELSE NULL END) as next_due
                FROM `installments`
                WHERE lead_id = ? AND company_id = ?
            ");
            $sumStmt->execute([$leadId, $companyId]);
            $calcs = $sumStmt->fetch();

            $newPaid = (int)$calcs['paid_sum'];
            $nextDue = $calcs['next_due'];

            $leadRow = $pdo->query("SELECT total_amount FROM `leads` WHERE id = $leadId")->fetch();
            $totAmt = (int)($leadRow['total_amount'] ?? 0);
            $newRem = max(0, $totAmt - $newPaid);
            $newCommStatus = ($newRem === 0) ? 'PAID' : 'PARTIAL';

            $updLead = $pdo->prepare("
                UPDATE `leads`
                SET `paid_amount` = ?,
                    `remaining_amount` = ?,
                    `next_due_date` = ?,
                    `commercial_status` = ?,
                    `stage_name` = CASE WHEN ? = 'PAID' THEN 'WON' ELSE `stage_name` END,
                    `updated_at` = NOW()
                WHERE id = ? AND company_id = ?
            ");
            $updLead->execute([$newPaid, $newRem, $nextDue, $newCommStatus, $newCommStatus, $leadId, $companyId]);

            echo json_encode([
                'success'           => true,
                'installment_id'    => $installmentId,
                'paid_amount'       => $newPaid,
                'remaining_amount'  => $newRem,
                'next_due_date'     => $nextDue,
                'commercial_status' => $newCommStatus
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
