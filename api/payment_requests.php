<?php
/**
 * CUBOIDPILOT — PAYMENT REQUESTS & MULTI-CHANNEL SETTLEMENT CONTROLLER
 * Handles:
 * 1. Creating payment requests with unique PRQ-XXXX codes & secure tokens.
 * 2. Automated emails to Customer (Payment Instructions, QR, UPI, Bank) and Admin (Lead Alert with 1-click confirm).
 * 3. WhatsApp notification dispatch for customer and admin.
 * 4. Dashboard listing (All Requests, Pending Payments, Completed History).
 * 5. Remote confirmation via email action link or WhatsApp command.
 * 6. CRM Lead progression to WON & automated customer tax receipt email.
 */

if (php_sapi_name() !== 'cli' && !headers_sent()) {
    header("Content-Type: application/json; charset=UTF-8");
    header("Cache-Control: no-cache, no-store, must-revalidate");
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/payment_provider.php';
require_once __DIR__ . '/../includes/mailer.php';

$pdo = getDbConnection();

// Allow public action for token confirmation landing page or webhooks
$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true) ?? $_POST;
if (!empty($data['action'])) {
    $action = $data['action'];
}

// -------------------------------------------------------------
// PUBLIC ACTION: verify_token_web (1-Click Confirm from Admin Email)
// -------------------------------------------------------------
if ($action === 'verify_token_web' || $action === 'confirm_by_token') {
    $token = trim($_GET['token'] ?? $data['token'] ?? '');
    $notes = trim($_GET['notes'] ?? $data['notes'] ?? 'Confirmed via secure admin email action link');
    $utr   = trim($_GET['utr'] ?? $data['utr'] ?? '');

    if (empty($token)) {
        http_response_code(400);
        die("Invalid or missing confirmation token.");
    }

    $stmt = $pdo->prepare("SELECT * FROM `payment_requests` WHERE `confirmation_token` = ? LIMIT 1");
    $stmt->execute([$token]);
    $pr = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pr) {
        http_response_code(404);
        die("Payment Request not found or link has expired.");
    }

    $companyId = (int)$pr['company_id'];
    $reqCode   = $pr['request_code'];

    if ($pr['status'] === 'completed') {
        if ($action === 'verify_token_web') {
            header("Content-Type: text/html; charset=UTF-8");
            echo renderHtmlFeedbackPage("Payment Already Completed", "Payment Request <strong>{$reqCode}</strong> has already been marked as <strong>Completed</strong>.", "success");
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'Payment request already completed.', 'already_completed' => true]);
        exit;
    }

    // Execute complete settlement
    $settled = settlePaymentRequest($pdo, $pr, [
        'confirmed_by' => 'Admin Email 1-Click Action',
        'notes'        => $notes,
        'utr'          => $utr ?: ('CONF-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8))),
        'method'       => 'email_verified'
    ]);

    if ($action === 'verify_token_web') {
        header("Content-Type: text/html; charset=UTF-8");
        echo renderHtmlFeedbackPage("Payment Confirmed Successfully!", "Payment Request <strong>{$reqCode}</strong> for <strong>₹" . number_format($pr['amount_inr']) . "</strong> has been marked as <strong>Completed</strong>.<br>The customer has been emailed their official payment receipt and the CRM lead has been updated.", "success");
        exit;
    }

    echo json_encode([
        'success'      => true,
        'message'      => "Payment Request {$reqCode} confirmed successfully.",
        'request_code' => $reqCode,
        'status'       => 'completed'
    ]);
    exit;
}

// -------------------------------------------------------------
// PUBLIC WIDGET ACTION: create (Visitor proceeds with Payment)
// -------------------------------------------------------------
if ($action === 'create') {
    $companyKey   = trim($data['company_key'] ?? $_SERVER['HTTP_X_COMPANY_KEY'] ?? '');
    $visitorName  = trim($data['name'] ?? $data['customer_name'] ?? '');
    $visitorEmail = trim($data['email'] ?? $data['customer_email'] ?? '');
    $visitorPhone = trim($data['phone'] ?? $data['customer_phone'] ?? '');
    $amountInr    = (int)($data['amount'] ?? $data['amount_inr'] ?? 0);
    $itemTitle    = trim($data['item_title'] ?? $data['title'] ?? 'Course Enrollment');
    $itemType     = trim($data['item_type'] ?? 'course');
    $sessionToken = trim($data['session_id'] ?? $data['session_token'] ?? '');
    $convoId      = !empty($data['conversation_id']) ? (int)$data['conversation_id'] : null;

    if (empty($companyKey)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing company key']);
        exit;
    }

    $cStmt = $pdo->prepare("SELECT id, name, email, company_key FROM `companies` WHERE `company_key` = ? LIMIT 1");
    $cStmt->execute([$companyKey]);
    $company = $cStmt->fetch(PDO::FETCH_ASSOC);

    if (!$company) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Company not found']);
        exit;
    }

    $companyId = (int)$company['id'];

    if (empty($visitorName) || empty($visitorEmail) || empty($visitorPhone) || $amountInr <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'Name, email, phone number, and a valid amount are required to create a payment request.'
        ]);
        exit;
    }

    // Resolve Customer
    $custStmt = $pdo->prepare("SELECT id FROM `customers` WHERE `company_id` = ? AND (`phone` = ? OR `email` = ?) LIMIT 1");
    $custStmt->execute([$companyId, $visitorPhone, $visitorEmail]);
    $customerId = (int)$custStmt->fetchColumn();

    if (!$customerId) {
        $uuid = 'cust_' . bin2hex(random_bytes(8));
        $insCust = $pdo->prepare("
            INSERT INTO `customers` (`company_id`, `customer_uuid`, `name`, `email`, `phone`, `first_seen_at`, `last_seen_at`)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $insCust->execute([$companyId, $uuid, $visitorName, $visitorEmail, $visitorPhone]);
        $customerId = (int)$pdo->lastInsertId();
    } else {
        $pdo->prepare("UPDATE `customers` SET `name` = COALESCE(NULLIF(?, ''), `name`), `email` = COALESCE(NULLIF(?, ''), `email`), `phone` = COALESCE(NULLIF(?, ''), `phone`), `last_seen_at` = NOW() WHERE `id` = ?")
            ->execute([$visitorName, $visitorEmail, $visitorPhone, $customerId]);
    }

    // Resolve or Link CRM Lead
    $leadStmt = $pdo->prepare("SELECT id, total_amount, paid_amount FROM `leads` WHERE `company_id` = ? AND `customer_id` = ? ORDER BY id DESC LIMIT 1");
    $leadStmt->execute([$companyId, $customerId]);
    $lead = $leadStmt->fetch(PDO::FETCH_ASSOC);
    $leadId = $lead ? (int)$lead['id'] : null;

    if (!$leadId) {
        $insLead = $pdo->prepare("
            INSERT INTO `leads` 
            (`company_id`, `customer_id`, `conversation_id`, `title`, `opportunity_value`, `total_amount`, `paid_amount`, `remaining_amount`, `commercial_status`, `stage_name`, `priority`, `intent_level`, `source`, `status`, `ai_summary`, `created_at`, `updated_at`)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?, 'PENDING', 'Proposal / Demo', 'HIGH', 'high', 'Website Payment Page', 'open', ?, NOW(), NOW())
        ");
        $summary = "Customer initiated payment request for {$itemTitle} worth ₹" . number_format($amountInr);
        $insLead->execute([$companyId, $customerId, $convoId, "{$visitorName} - {$itemTitle}", $amountInr, $amountInr, $amountInr, $summary]);
        $leadId = (int)$pdo->lastInsertId();
    } else {
        $pdo->prepare("UPDATE `leads` SET `total_amount` = GREATEST(COALESCE(total_amount, 0), ?), `remaining_amount` = GREATEST(0, COALESCE(total_amount, 0) - COALESCE(paid_amount, 0)), `commercial_status` = 'PENDING', `priority` = 'HIGH', `updated_at` = NOW() WHERE `id` = ?")
            ->execute([$amountInr, $leadId]);
    }

    // Generate unique Request Code: PRQ-YYYYMM-XXXX
    $datePart = date('Ym');
    $randPart = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $requestCode = "PRQ-{$datePart}-{$randPart}";
    $confirmationToken = bin2hex(random_bytes(24));

    // Persist Payment Request
    $insPr = $pdo->prepare("
        INSERT INTO `payment_requests`
        (`company_id`, `request_code`, `customer_id`, `lead_id`, `conversation_id`, `item_type`, `item_title`, `amount_inr`, `customer_name`, `customer_email`, `customer_phone`, `status`, `confirmation_token`, `notes`, `created_at`, `updated_at`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, NOW(), NOW())
    ");
    $notes = "Initiated online via widget checkout. Awaiting payment receipt.";
    $insPr->execute([
        $companyId,
        $requestCode,
        $customerId,
        $leadId,
        $convoId,
        in_array($itemType, ['course', 'product', 'service', 'custom']) ? $itemType : 'course',
        $itemTitle,
        $amountInr,
        $visitorName,
        $visitorEmail,
        $visitorPhone,
        $confirmationToken,
        $notes
    ]);
    $requestId = (int)$pdo->lastInsertId();

    // Fetch configured banking & payment options
    $paymentConfig = PaymentProvider::getWorkspacePaymentConfig($pdo, $companyId);
    $widgetStmt = $pdo->prepare("SELECT * FROM `widget_settings` WHERE `company_id` = ? LIMIT 1");
    $widgetStmt->execute([$companyId]);
    $widget = $widgetStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $upiId     = $widget['bank_upi_id'] ?? $widget['upi_id'] ?? 'accounts@cuboidsoft';
    $bankName  = $widget['bank_name'] ?? 'HDFC Bank';
    $bankAcc   = $widget['bank_account_no'] ?? $widget['bank_account_number'] ?? '';
    $bankIfsc  = $widget['bank_ifsc'] ?? '';
    $bankHolder= $widget['bank_account_holder'] ?? $company['name'];
    $bankQrUrl = !empty($widget['bank_qr_url']) ? $widget['bank_qr_url'] : '';
    $rzpKey    = $widget['razorpay_key_id'] ?? '';

    // Generate UPI Intent URL
    $payeeName = urlencode($company['name']);
    $note = urlencode(substr("{$itemTitle} - {$requestCode}", 0, 30));
    $upiIntent = "upi://pay?pa={$upiId}&pn={$payeeName}&am={$amountInr}&cu=INR&tn={$note}&tr={$requestCode}";

    // Protocol & Host
    $host = $_SERVER['HTTP_HOST'] ?? 'cai.cuboidsoft.in';
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $confirmActionUrl = "{$protocol}{$host}/api/payment_requests.php?action=verify_token_web&token={$confirmationToken}";

    // 1. Dispatch Customer Payment Instructions Email
    dispatchCustomerPaymentInstructionsEmail($pdo, $companyId, $company, [
        'request_code'  => $requestCode,
        'item_title'    => $itemTitle,
        'amount_inr'    => $amountInr,
        'customer_name' => $visitorName,
        'customer_email'=> $visitorEmail,
        'customer_phone'=> $visitorPhone,
        'upi_id'        => $upiId,
        'upi_intent'    => $upiIntent,
        'bank_name'     => $bankName,
        'bank_acc'      => $bankAcc,
        'bank_ifsc'     => $bankIfsc,
        'bank_holder'   => $bankHolder,
        'bank_qr_url'   => $bankQrUrl,
        'razorpay_key'  => $rzpKey
    ]);

    // 2. Dispatch Admin Notification Email
    dispatchAdminPaymentAlertEmail($pdo, $companyId, $company, [
        'request_code'       => $requestCode,
        'item_title'         => $itemTitle,
        'amount_inr'         => $amountInr,
        'customer_name'      => $visitorName,
        'customer_email'     => $visitorEmail,
        'customer_phone'     => $visitorPhone,
        'confirm_action_url' => $confirmActionUrl
    ]);

    // 3. Dispatch WhatsApp Notification (if company has connected WhatsApp)
    dispatchWhatsAppPaymentNotifications($pdo, $companyId, [
        'request_code'       => $requestCode,
        'item_title'         => $itemTitle,
        'amount_inr'         => $amountInr,
        'customer_name'      => $visitorName,
        'customer_phone'     => $visitorPhone,
        'upi_id'             => $upiId,
        'bank_name'          => $bankName,
        'bank_acc'           => $bankAcc,
        'bank_ifsc'          => $bankIfsc,
        'confirm_action_url' => $confirmActionUrl
    ]);

    // 4. If conversation exists, post prompt message in chat
    if ($convoId) {
        $chatMsg = "💳 **Payment Request Generated [#{$requestCode}]**\n\n"
            . "• **Item:** {$itemTitle}\n"
            . "• **Payable Amount:** ₹" . number_format($amountInr) . "\n"
            . "• **Status:** Pending Verification\n\n"
            . "Official payment instructions with QR code & bank details have been emailed to `{$visitorEmail}`. Once paid, please share your UTR/Reference number.";
        
        $pdo->prepare("
            INSERT INTO `messages` (`company_id`, `conversation_id`, `sender_type`, `message_text`, `created_at`)
            VALUES (?, ?, 'ai', ?, NOW())
        ")->execute([$companyId, $convoId, $chatMsg]);
    }

    echo json_encode([
        'success'        => true,
        'request_id'     => $requestId,
        'request_code'   => $requestCode,
        'amount'         => $amountInr,
        'item_title'     => $itemTitle,
        'status'         => 'pending',
        'customer'       => [
            'name'  => $visitorName,
            'email' => $visitorEmail,
            'phone' => $visitorPhone
        ],
        'payment_details'=> [
            'upi_id'        => $upiId,
            'upi_intent_url'=> $upiIntent,
            'bank_name'     => $bankName,
            'account_number'=> $bankAcc,
            'ifsc'          => $bankIfsc,
            'holder_name'   => $bankHolder,
            'qr_code_url'   => $bankQrUrl,
            'razorpay_key'  => $rzpKey
        ],
        'message'        => "Payment request generated successfully. Instructions dispatched to {$visitorEmail}."
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// =============================================================
// AUTHENTICATED DASHBOARD ACTIONS (Requires Session)
// =============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['company_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required for dashboard access.']);
    exit;
}

$companyId = (int)$_SESSION['company_id'];
$userId    = (int)($_SESSION['user_id'] ?? 0);

// -------------------------------------------------------------
// DASHBOARD ACTION: list (Payment Requests, Pending, Completed)
// -------------------------------------------------------------
if ($action === 'list') {
    $filter = strtolower(trim($_GET['status'] ?? $data['status'] ?? 'all'));
    $search = trim($_GET['search'] ?? $data['search'] ?? '');

    $whereSql = "WHERE pr.company_id = ?";
    $params = [$companyId];

    if ($filter === 'pending') {
        $whereSql .= " AND pr.status = 'pending'";
    } elseif ($filter === 'completed') {
        $whereSql .= " AND pr.status = 'completed'";
    } elseif ($filter === 'cancelled') {
        $whereSql .= " AND pr.status = 'cancelled'";
    }

    if (!empty($search)) {
        $whereSql .= " AND (pr.request_code LIKE ? OR pr.customer_name LIKE ? OR pr.customer_email LIKE ? OR pr.customer_phone LIKE ? OR pr.item_title LIKE ? OR pr.transaction_reference LIKE ?)";
        $sTerm = "%{$search}%";
        for ($i = 0; $i < 6; $i++) {
            $params[] = $sTerm;
        }
    }

    $stmt = $pdo->prepare("
        SELECT pr.*, 
               u.name as confirmed_by_name,
               DATE_FORMAT(pr.created_at, '%b %d, %Y · %l:%i %p') as formatted_date,
               DATE_FORMAT(pr.confirmed_at, '%b %d, %Y · %l:%i %p') as formatted_confirmed_at
        FROM `payment_requests` pr
        LEFT JOIN `users` u ON u.id = pr.confirmed_by_user_id
        {$whereSql}
        ORDER BY pr.id DESC
    ");
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Compute KPI statistics
    $statStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_requests,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
            SUM(CASE WHEN status = 'completed' THEN amount_inr ELSE 0 END) as total_volume_inr,
            SUM(CASE WHEN status = 'pending' THEN amount_inr ELSE 0 END) as pending_dues_inr
        FROM `payment_requests`
        WHERE company_id = ?
    ");
    $statStmt->execute([$companyId]);
    $stats = $statStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'stats'   => [
            'total_requests'  => (int)($stats['total_requests'] ?? 0),
            'pending_count'   => (int)($stats['pending_count'] ?? 0),
            'completed_count' => (int)($stats['completed_count'] ?? 0),
            'total_volume_inr'=> (int)($stats['total_volume_inr'] ?? 0),
            'pending_dues_inr'=> (int)($stats['pending_dues_inr'] ?? 0),
        ],
        'requests' => $items
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// -------------------------------------------------------------
// DASHBOARD ACTION: confirm (Mark Payment Request Completed)
// -------------------------------------------------------------
if ($action === 'confirm') {
    $requestId = (int)($data['id'] ?? $data['request_id'] ?? 0);
    $utr       = trim($data['transaction_reference'] ?? $data['utr'] ?? '');
    $notes     = trim($data['notes'] ?? 'Manually marked completed by dashboard operator');
    $method    = trim($data['payment_method'] ?? 'manual');

    if (!$requestId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing payment request ID.']);
        exit;
    }

    $prStmt = $pdo->prepare("SELECT * FROM `payment_requests` WHERE `id` = ? AND `company_id` = ? LIMIT 1");
    $prStmt->execute([$requestId, $companyId]);
    $pr = $prStmt->fetch(PDO::FETCH_ASSOC);

    if (!$pr) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Payment request not found.']);
        exit;
    }

    if ($pr['status'] === 'completed') {
        echo json_encode(['success' => true, 'message' => 'Payment request is already marked as completed.']);
        exit;
    }

    settlePaymentRequest($pdo, $pr, [
        'confirmed_by_id' => $userId,
        'confirmed_by'    => 'Dashboard Operator',
        'notes'           => $notes,
        'utr'             => $utr ?: ('MAN-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8))),
        'method'          => $method
    ]);

    echo json_encode([
        'success'      => true,
        'message'      => "Payment request #{$pr['request_code']} marked as completed successfully.",
        'request_code' => $pr['request_code'],
        'status'       => 'completed'
    ]);
    exit;
}

// -------------------------------------------------------------
// DASHBOARD ACTION: manual_entry (Add offline/direct payment entry)
// -------------------------------------------------------------
if ($action === 'manual_entry') {
    $name     = trim($data['customer_name'] ?? '');
    $email    = trim($data['customer_email'] ?? '');
    $phone    = trim($data['customer_phone'] ?? '');
    $title    = trim($data['item_title'] ?? 'Direct Payment');
    $amount   = (int)($data['amount_inr'] ?? $data['amount'] ?? 0);
    $method   = trim($data['payment_method'] ?? 'cash');
    $utr      = trim($data['transaction_reference'] ?? '');
    $notes    = trim($data['notes'] ?? 'Manual payment logged from dashboard');

    if (empty($name) || $amount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Please provide customer name and a valid amount.']);
        exit;
    }

    // Resolve or create Customer
    $custStmt = $pdo->prepare("SELECT id FROM `customers` WHERE `company_id` = ? AND (`phone` = ? OR `email` = ?) LIMIT 1");
    $custStmt->execute([$companyId, $phone, $email]);
    $customerId = (int)$custStmt->fetchColumn();

    if (!$customerId) {
        $uuid = 'cust_' . bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO `customers` (`company_id`, `customer_uuid`, `name`, `email`, `phone`, `first_seen_at`, `last_seen_at`) VALUES (?, ?, ?, ?, ?, NOW(), NOW())")
            ->execute([$companyId, $uuid, $name, $email, $phone]);
        $customerId = (int)$pdo->lastInsertId();
    }

    // Resolve or create Lead
    $leadStmt = $pdo->prepare("SELECT id FROM `leads` WHERE `company_id` = ? AND `customer_id` = ? ORDER BY id DESC LIMIT 1");
    $leadStmt->execute([$companyId, $customerId]);
    $leadId = (int)$leadStmt->fetchColumn();

    if (!$leadId) {
        $pdo->prepare("INSERT INTO `leads` (`company_id`, `customer_id`, `title`, `opportunity_value`, `total_amount`, `paid_amount`, `remaining_amount`, `commercial_status`, `stage_name`, `status`, `source`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, 0, 'PAID', 'WON', 'won', 'Manual Dashboard Entry', NOW(), NOW())")
            ->execute([$companyId, $customerId, "{$name} - {$title}", $amount, $amount, $amount]);
        $leadId = (int)$pdo->lastInsertId();
    }

    $datePart = date('Ym');
    $randPart = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $requestCode = "PRQ-{$datePart}-{$randPart}";
    $utrFinal = $utr ?: ('MAN-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)));

    $ins = $pdo->prepare("
        INSERT INTO `payment_requests`
        (`company_id`, `request_code`, `customer_id`, `lead_id`, `item_type`, `item_title`, `amount_inr`, `customer_name`, `customer_email`, `customer_phone`, `status`, `payment_method`, `transaction_reference`, `confirmed_by_user_id`, `confirmed_at`, `notes`, `created_at`, `updated_at`)
        VALUES (?, ?, ?, ?, 'custom', ?, ?, ?, ?, ?, 'completed', ?, ?, ?, NOW(), ?, NOW(), NOW())
    ");
    $ins->execute([
        $companyId,
        $requestCode,
        $customerId,
        $leadId,
        $title,
        $amount,
        $name,
        $email,
        $phone,
        $method,
        $utrFinal,
        $userId,
        $notes
    ]);
    $prId = (int)$pdo->lastInsertId();

    // Settle into payments table
    $pdo->prepare("
        INSERT INTO `widget_payments`
        (`company_id`, `customer_id`, `lead_id`, `payment_method`, `amount_inr`, `transaction_reference`, `status`, `notes`, `created_at`, `updated_at`)
        VALUES (?, ?, ?, ?, ?, ?, 'verified', ?, NOW(), NOW())
    ")->execute([$companyId, $customerId, $leadId, in_array($method, ['razorpay', 'bank_transfer', 'invoice', 'custom']) ? $method : 'custom', $amount, $utrFinal, $notes]);

    // Send receipt email if email is provided
    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $prRow = $pdo->query("SELECT * FROM `payment_requests` WHERE id = {$prId}")->fetch(PDO::FETCH_ASSOC);
        dispatchCustomerReceiptEmail($pdo, $companyId, $prRow);
    }

    echo json_encode([
        'success'      => true,
        'message'      => "Manual payment record created and verified successfully.",
        'request_code' => $requestCode,
        'payment_id'   => $prId
    ]);
    exit;
}

// -------------------------------------------------------------
// HELPER FUNCTIONS: Settlement & Email Dispatchers
// -------------------------------------------------------------

function settlePaymentRequest(PDO $pdo, array $pr, array $params = []): bool {
    $prId       = (int)$pr['id'];
    $companyId  = (int)$pr['company_id'];
    $customerId = (int)$pr['customer_id'];
    $leadId     = !empty($pr['lead_id']) ? (int)$pr['lead_id'] : null;
    $amount     = (int)$pr['amount_inr'];
    $utr        = $params['utr'] ?? ('TXN-' . bin2hex(random_bytes(4)));
    $method     = $params['method'] ?? 'manual';
    $userId     = $params['confirmed_by_id'] ?? null;
    $notes      = $params['notes'] ?? '';

    // 1. Update Payment Request status
    $pdo->prepare("
        UPDATE `payment_requests`
        SET `status` = 'completed',
            `transaction_reference` = ?,
            `payment_method` = ?,
            `confirmed_by_user_id` = ?,
            `confirmed_at` = NOW(),
            `receipt_sent_at` = NOW(),
            `notes` = CONCAT(COALESCE(notes, ''), ' [Completed: ', ?, ']')
        WHERE `id` = ?
    ")->execute([$utr, $method, $userId, $notes, $prId]);

    // 2. Insert into widget_payments
    $pdo->prepare("
        INSERT INTO `widget_payments`
        (`company_id`, `customer_id`, `lead_id`, `conversation_id`, `payment_method`, `amount_inr`, `currency`, `transaction_reference`, `status`, `notes`, `created_at`, `updated_at`)
        VALUES (?, ?, ?, ?, 'bank_transfer', ?, 'INR', ?, 'verified', ?, NOW(), NOW())
    ")->execute([
        $companyId,
        $customerId,
        $leadId,
        $pr['conversation_id'],
        $amount,
        $utr,
        "Settled via {$method}. Ref: {$utr}. {$notes}"
    ]);

    // 3. Update Lead commercial state
    if ($leadId) {
        $lStmt = $pdo->prepare("SELECT total_amount, paid_amount FROM `leads` WHERE id = ?");
        $lStmt->execute([$leadId]);
        $leadRow = $lStmt->fetch(PDO::FETCH_ASSOC);

        $tot = max((int)($leadRow['total_amount'] ?? 0), $amount);
        $newPaid = max((int)($leadRow['paid_amount'] ?? 0) + $amount, $tot);
        $rem = max(0, $tot - $newPaid);
        $commStatus = ($rem === 0) ? 'PAID' : 'PARTIAL';
        $stage = ($rem === 0) ? 'WON' : 'PAYMENT_PENDING';

        $pdo->prepare("
            UPDATE `leads`
            SET `paid_amount` = ?, `remaining_amount` = ?, `commercial_status` = ?, `stage_name` = ?, `status` = 'won', `updated_at` = NOW()
            WHERE id = ? AND company_id = ?
        ")->execute([$newPaid, $rem, $commStatus, $stage, $leadId, $companyId]);
    }

    // 4. Post AI confirmation in live chat conversation
    if (!empty($pr['conversation_id'])) {
        $cText = "✅ **Payment Verified & Completed!**\n\n"
            . "• **Request Code:** `{$pr['request_code']}`\n"
            . "• **Amount Paid:** ₹" . number_format($amount) . "\n"
            . "• **Transaction Ref:** `{$utr}`\n"
            . "• **Status:** Completed\n\n"
            . "Thank you! Your official payment receipt has been delivered to your email (`{$pr['customer_email']}`). Welcome aboard!";
        
        $pdo->prepare("
            INSERT INTO `messages` (`company_id`, `conversation_id`, `sender_type`, `message_text`, `created_at`)
            VALUES (?, ?, 'ai', ?, NOW())
        ")->execute([$companyId, $pr['conversation_id'], $cText]);
    }

    // 5. Send Professional Tax Invoice / Receipt Email to Customer
    $prUpdated = array_merge($pr, ['transaction_reference' => $utr, 'payment_method' => $method]);
    dispatchCustomerReceiptEmail($pdo, $companyId, $prUpdated);

    // 6. Send Completion Alert Email to Admin
    dispatchAdminPaymentCompletedAlertEmail($pdo, $companyId, $prUpdated);

    return true;
}

function dispatchCustomerPaymentInstructionsEmail(PDO $pdo, int $companyId, array $company, array $data): void {
    if (empty($data['customer_email']) || !filter_var($data['customer_email'], FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $cName = htmlspecialchars($data['customer_name']);
    $itemTitle = htmlspecialchars($data['item_title']);
    $amountFormatted = '₹' . number_format($data['amount_inr']);
    $reqCode = htmlspecialchars($data['request_code']);
    $compName = htmlspecialchars($company['name']);

    $bankHtml = '';
    if (!empty($data['bank_acc'])) {
        $bankHtml = "
        <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:14px;margin:16px 0;'>
            <div style='font-size:12px;font-weight:700;color:#0f172a;text-transform:uppercase;margin-bottom:8px;'>Bank Transfer Details:</div>
            <table style='width:100%;font-size:13px;color:#334155;border-collapse:collapse;'>
                <tr><td style='padding:3px 0;width:130px;color:#64748b;'>Beneficiary Name:</td><td style='font-weight:600;'>{$data['bank_holder']}</td></tr>
                <tr><td style='padding:3px 0;color:#64748b;'>Bank Name:</td><td style='font-weight:600;'>{$data['bank_name']}</td></tr>
                <tr><td style='padding:3px 0;color:#64748b;'>Account Number:</td><td style='font-family:monospace;font-weight:700;'>{$data['bank_acc']}</td></tr>
                <tr><td style='padding:3px 0;color:#64748b;'>IFSC Code:</td><td style='font-family:monospace;font-weight:700;'>{$data['bank_ifsc']}</td></tr>
                <tr><td style='padding:3px 0;color:#64748b;'>UPI ID / VPA:</td><td style='font-family:monospace;font-weight:700;color:#0284c7;'>{$data['upi_id']}</td></tr>
            </table>
        </div>";
    }

    $qrHtml = '';
    if (!empty($data['bank_qr_url'])) {
        $qrSrc = htmlspecialchars($data['bank_qr_url']);
        $qrHtml = "
        <div style='text-align:center;margin:16px 0;padding:12px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:6px;'>
            <div style='font-size:11px;font-weight:600;color:#64748b;margin-bottom:8px;'>SCAN DYNAMIC UPI QR CODE TO PAY:</div>
            <img src='{$qrSrc}' alt='UPI QR Code' style='max-width:160px;height:auto;border-radius:4px;border:1px solid #e2e8f0;'>
        </div>";
    }

    $subject = "Payment Request #{$data['request_code']} — {$data['item_title']} ({$compName})";
    $htmlBody = "
    <div style='font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif;max-width:580px;margin:0 auto;padding:24px;background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;'>
        <div style='border-bottom:2px solid #0f172a;padding-bottom:12px;margin-bottom:18px;'>
            <h2 style='margin:0;font-size:20px;color:#0f172a;'>{$compName}</h2>
            <div style='font-size:12px;color:#64748b;margin-top:4px;'>Payment Instructions & Order Confirmation</div>
        </div>

        <p style='font-size:14px;color:#1e293b;line-height:1.5;'>Dear <strong>{$cName}</strong>,</p>
        <p style='font-size:13px;color:#334155;line-height:1.6;'>
            Thank you for selecting <strong>{$itemTitle}</strong>. Your payment request has been registered under <strong>#{$reqCode}</strong>.
        </p>

        <div style='background:#f1f5f9;border-left:4px solid #0f172a;padding:12px 16px;border-radius:4px;margin:16px 0;'>
            <div style='font-size:12px;color:#64748b;'>Payable Amount</div>
            <div style='font-size:24px;font-weight:700;color:#0f172a;'>{$amountFormatted}</div>
            <div style='font-size:11px;color:#64748b;margin-top:2px;'>Request ID: <code>{$reqCode}</code> • Status: Pending</div>
        </div>

        {$bankHtml}
        {$qrHtml}

        <p style='font-size:12px;color:#64748b;line-height:1.5;margin-top:20px;'>
            Once payment is transferred via UPI or NEFT/IMPS, our accounts team will verify the transaction and immediately issue your official receipt and onboarding credentials.
        </p>

        <div style='border-top:1px solid #e2e8f0;padding-top:12px;margin-top:20px;font-size:11px;color:#94a3b8;'>
            Have questions? Reply directly to this email or reach us on our official support channels.<br>
            © " . date('Y') . " {$compName}. Powered by CuboidPilot.
        </div>
    </div>";

    CompanyMailer::send($pdo, $companyId, $data['customer_email'], $subject, $htmlBody);
}

function dispatchAdminPaymentAlertEmail(PDO $pdo, int $companyId, array $company, array $data): void {
    $adminEmail = $company['email'] ?? '';
    if (empty($adminEmail)) {
        $uStmt = $pdo->prepare("SELECT email FROM `users` WHERE `company_id` = ? AND `role` IN ('owner', 'admin') AND email IS NOT NULL AND email != '' ORDER BY id ASC LIMIT 1");
        $uStmt->execute([$companyId]);
        $adminEmail = (string)$uStmt->fetchColumn();
    }

    if (empty($adminEmail) || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $cName = htmlspecialchars($data['customer_name']);
    $cPhone = htmlspecialchars($data['customer_phone']);
    $cEmail = htmlspecialchars($data['customer_email']);
    $itemTitle = htmlspecialchars($data['item_title']);
    $amountFormatted = '₹' . number_format($data['amount_inr']);
    $reqCode = htmlspecialchars($data['request_code']);
    $confirmUrl = htmlspecialchars($data['confirm_action_url']);
    $compName = htmlspecialchars($company['name']);

    $subject = "💳 [{$cName}] wants to pay {$amountFormatted} for {$data['item_title']}";
    $htmlBody = "
    <div style='font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif;max-width:580px;margin:0 auto;padding:24px;background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;'>
        <div style='border-bottom:2px solid #0f172a;padding-bottom:10px;margin-bottom:16px;'>
            <div style='display:inline-block;padding:3px 8px;background:#fef3c7;color:#92400e;border-radius:4px;font-size:11px;font-weight:700;'>NEW PAYMENT REQUEST</div>
            <h2 style='margin:8px 0 0;font-size:18px;color:#0f172a;'>{$cName} wants to pay {$amountFormatted}</h2>
        </div>

        <p style='font-size:13px;color:#334155;line-height:1.5;'>
            A prospective client has submitted an active payment intent for <strong>{$itemTitle}</strong>.
        </p>

        <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:14px;margin:16px 0;font-size:13px;'>
            <table style='width:100%;border-collapse:collapse;color:#334155;'>
                <tr><td style='padding:3px 0;width:120px;color:#64748b;'>Request ID:</td><td style='font-family:monospace;font-weight:700;'>{$reqCode}</td></tr>
                <tr><td style='padding:3px 0;color:#64748b;'>Customer Name:</td><td style='font-weight:600;'>{$cName}</td></tr>
                <tr><td style='padding:3px 0;color:#64748b;'>Phone / WhatsApp:</td><td style='font-weight:600;'><a href='tel:{$cPhone}' style='color:#0f172a;'>{$cPhone}</a> · <a href='https://wa.me/" . preg_replace('/[^0-9]/', '', $cPhone) . "' style='color:#10b981;font-weight:600;text-decoration:none;'>Chat on WhatsApp &rarr;</a></td></tr>
                <tr><td style='padding:3px 0;color:#64748b;'>Email Address:</td><td style='font-weight:600;'><a href='mailto:{$cEmail}' style='color:#0284c7;'>{$cEmail}</a></td></tr>
                <tr><td style='padding:3px 0;color:#64748b;'>Selected Product:</td><td style='font-weight:600;'>{$itemTitle}</td></tr>
                <tr><td style='padding:3px 0;color:#64748b;'>Payable Amount:</td><td style='font-weight:700;color:#0f172a;font-size:15px;'>{$amountFormatted}</td></tr>
            </table>
        </div>

        <div style='text-align:center;margin:24px 0 16px;'>
            <a href='{$confirmUrl}' style='display:inline-block;padding:12px 24px;background:#0f172a;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;font-size:13px;box-shadow:0 2px 4px rgba(0,0,0,0.1);'>
                ✓ Confirm Payment Done (1-Click)
            </a>
            <div style='font-size:11px;color:#94a3b8;margin-top:8px;'>Clicking this verifies the transaction, marks the request Completed, updates CRM, and dispatches the customer receipt.</div>
        </div>

        <div style='border-top:1px solid #e2e8f0;padding-top:12px;margin-top:20px;font-size:11px;color:#94a3b8;'>
            Delivered securely to company administrators at {$compName}.
        </div>
    </div>";

    CompanyMailer::send($pdo, $companyId, $adminEmail, $subject, $htmlBody);
}

function dispatchCustomerReceiptEmail(PDO $pdo, int $companyId, array $pr): void {
    if (empty($pr['customer_email']) || !filter_var($pr['customer_email'], FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $cStmt = $pdo->prepare("SELECT name FROM `companies` WHERE id = ? LIMIT 1");
    $cStmt->execute([$companyId]);
    $compName = $cStmt->fetchColumn() ?: 'CuboidPilot Workspace';

    $cName = htmlspecialchars($pr['customer_name']);
    $itemTitle = htmlspecialchars($pr['item_title']);
    $amountFormatted = '₹' . number_format($pr['amount_inr']);
    $reqCode = htmlspecialchars($pr['request_code']);
    $utr = htmlspecialchars($pr['transaction_reference'] ?? 'VERIFIED');
    $date = date('F j, Y');

    $subject = "✅ Payment Receipt & Confirmation #{$pr['request_code']} — {$compName}";
    $htmlBody = "
    <div style='font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif;max-width:580px;margin:0 auto;padding:24px;background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;'>
        <div style='border-bottom:2px solid #10b981;padding-bottom:12px;margin-bottom:18px;'>
            <span style='font-size:11px;font-weight:700;color:#047857;background:#ecfdf5;padding:3px 8px;border-radius:4px;'>PAYMENT COMPLETED</span>
            <h2 style='margin:8px 0 0;font-size:20px;color:#0f172a;'>{$compName}</h2>
            <div style='font-size:12px;color:#64748b;margin-top:4px;'>Official Payment Receipt & Tax Invoice</div>
        </div>

        <p style='font-size:14px;color:#1e293b;line-height:1.5;'>Dear <strong>{$cName}</strong>,</p>
        <p style='font-size:13px;color:#334155;line-height:1.6;'>
            We are pleased to confirm that your payment for <strong>{$itemTitle}</strong> has been verified and marked as <strong>Completed</strong>.
        </p>

        <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:16px;margin:16px 0;font-size:13px;'>
            <table style='width:100%;border-collapse:collapse;color:#334155;'>
                <tr><td style='padding:4px 0;color:#64748b;'>Receipt Number:</td><td style='font-family:monospace;font-weight:700;'>#RCP-{$reqCode}</td></tr>
                <tr><td style='padding:4px 0;color:#64748b;'>Date:</td><td style='font-weight:600;'>{$date}</td></tr>
                <tr><td style='padding:4px 0;color:#64748b;'>Transaction / UTR Ref:</td><td style='font-family:monospace;font-weight:700;color:#047857;'>{$utr}</td></tr>
                <tr><td style='padding:4px 0;color:#64748b;'>Item / Service:</td><td style='font-weight:600;'>{$itemTitle}</td></tr>
                <tr style='border-top:1px solid #cbd5e1;'><td style='padding:8px 0 0;color:#0f172a;font-weight:700;'>Total Amount Paid:</td><td style='padding:8px 0 0;font-weight:700;color:#0f172a;font-size:16px;'>{$amountFormatted}</td></tr>
            </table>
        </div>

        <p style='font-size:13px;color:#334155;line-height:1.6;'>
            Your admission / service access is now fully active. Our coordination desk will reach out to you with your next steps and portal access.
        </p>

        <div style='border-top:1px solid #e2e8f0;padding-top:12px;margin-top:24px;font-size:11px;color:#94a3b8;'>
            Thank you for choosing {$compName}.<br>
            © " . date('Y') . " {$compName}. All rights reserved.
        </div>
    </div>";

    CompanyMailer::send($pdo, $companyId, $pr['customer_email'], $subject, $htmlBody);
}

function dispatchAdminPaymentCompletedAlertEmail(PDO $pdo, int $companyId, array $pr): void {
    $cStmt = $pdo->prepare("SELECT email, name FROM `companies` WHERE id = ? LIMIT 1");
    $cStmt->execute([$companyId]);
    $company = $cStmt->fetch(PDO::FETCH_ASSOC);

    $adminEmail = $company['email'] ?? '';
    if (empty($adminEmail)) {
        $uStmt = $pdo->prepare("SELECT email FROM `users` WHERE `company_id` = ? AND `role` IN ('owner', 'admin') AND email IS NOT NULL AND email != '' ORDER BY id ASC LIMIT 1");
        $uStmt->execute([$companyId]);
        $adminEmail = (string)$uStmt->fetchColumn();
    }

    if (empty($adminEmail) || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $amountFormatted = '₹' . number_format($pr['amount_inr']);
    $subject = "🎉 Payment Verified & Completed: {$pr['customer_name']} ({$amountFormatted})";
    $htmlBody = "
    <div style='font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif;max-width:580px;margin:0 auto;padding:24px;background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;'>
        <h3 style='margin:0 0 12px;color:#047857;'>✓ Payment Request Marked Completed</h3>
        <p style='font-size:13px;color:#334155;line-height:1.5;'>
            Payment Request <strong>#{$pr['request_code']}</strong> has been confirmed and settled.
        </p>
        <ul style='font-size:13px;color:#334155;line-height:1.6;'>
            <li><strong>Customer:</strong> {$pr['customer_name']} ({$pr['customer_phone']})</li>
            <li><strong>Amount:</strong> {$amountFormatted}</li>
            <li><strong>Item:</strong> {$pr['item_title']}</li>
            <li><strong>Transaction Ref:</strong> {$pr['transaction_reference']}</li>
        </ul>
        <p style='font-size:12px;color:#64748b;'>
            The customer has been emailed their official payment receipt and the associated CRM lead status has been updated to WON.
        </p>
    </div>";

    CompanyMailer::send($pdo, $companyId, $adminEmail, $subject, $htmlBody);
}

function dispatchWhatsAppPaymentNotifications(PDO $pdo, int $companyId, array $data): void {
    try {
        $waStmt = $pdo->prepare("SELECT phone_number_id, whatsapp_access_token FROM `whatsapp_accounts` WHERE `company_id` = ? AND `status` = 'connected' LIMIT 1");
        $waStmt->execute([$companyId]);
        $wa = $waStmt->fetch(PDO::FETCH_ASSOC);

        if (!$wa || empty($wa['phone_number_id']) || empty($wa['whatsapp_access_token'])) {
            return;
        }

        $cleanCustomerPhone = preg_replace('/[^0-9]/', '', $data['customer_phone']);
        if (strlen($cleanCustomerPhone) >= 10) {
            $msg = "💳 *Payment Instructions — #{$data['request_code']}*\n\n"
                . "Hello {$data['customer_name']},\n"
                . "Thank you for selecting *{$data['item_title']}*.\n"
                . "Payable Amount: *₹" . number_format($data['amount_inr']) . "*\n\n"
                . "• *UPI ID:* `{$data['upi_id']}`\n"
                . "• *Bank:* {$data['bank_name']}\n"
                . "• *A/C:* `{$data['bank_acc']}`\n"
                . "• *IFSC:* `{$data['bank_ifsc']}`\n\n"
                . "Once paid, please reply with your UTR/Reference number for instant verification.";

            $endpoint = "https://graph.facebook.com/v20.0/{$wa['phone_number_id']}/messages";
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $wa['whatsapp_access_token'],
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'messaging_product' => 'whatsapp',
                'to'                => $cleanCustomerPhone,
                'type'              => 'text',
                'text'              => ['body' => $msg]
            ]));
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            curl_exec($ch);
            curl_close($ch);
        }
    } catch (Throwable $e) {
        error_log("[Payment WhatsApp Dispatch Note] " . $e->getMessage());
    }
}

function renderHtmlFeedbackPage(string $title, string $desc, string $type = 'success'): string {
    $color = $type === 'success' ? '#10b981' : '#ef4444';
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>{$title} — CuboidPilot</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Inter', sans-serif; background: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
    .card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; max-width: 480px; width: 100%; padding: 32px; text-align: center; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05); }
    .badge { width: 54px; height: 54px; border-radius: 50%; background: rgba(16, 185, 129, 0.12); color: {$color}; display: inline-flex; align-items: center; justify-content: center; font-size: 26px; margin-bottom: 16px; }
    h1 { font-size: 20px; font-weight: 700; color: #0f172a; margin: 0 0 10px; }
    p { font-size: 14px; color: #475569; line-height: 1.6; margin: 0 0 24px; }
    .btn { display: inline-block; padding: 10px 22px; background: #0f172a; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 13px; }
  </style>
</head>
<body>
  <div class="card">
    <div class="badge">✓</div>
    <h1>{$title}</h1>
    <p>{$desc}</p>
    <a href="/app/payments.html" class="btn">Go to Payments Dashboard</a>
  </div>
</body>
</html>
HTML;
}
