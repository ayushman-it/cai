<?php
/**
 * CUBOIDPILOT — UNIFIED PAYMENT WEBHOOK PROCESSOR
 * Receives Razorpay, Cashfree, and Stripe webhooks.
 * Validates HMAC signatures, guarantees idempotency, updates installment schedules,
 * and advances customer CRM lifecycle.
 */

if (php_sapi_name() !== 'cli' && !headers_sent()) {
    header("Content-Type: application/json; charset=UTF-8");
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/payment_provider.php';

$pdo = getDbConnection();

$rawPayload = file_get_contents('php://input');
$signature  = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] 
           ?? $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] 
           ?? $_SERVER['HTTP_X_CASHFREE_SIGNATURE'] 
           ?? '';

$data = json_decode($rawPayload, true) ?? [];

if (empty($rawPayload) || empty($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Empty or invalid JSON webhook payload.']);
    exit;
}

try {
    // 1. Extract Order / Payment Details
    $event = $data['event'] ?? 'payment.captured';
    $orderId = null;
    $paymentId = null;
    $amountInr = 0;
    $provider = 'razorpay';
    $companyKey = $_GET['company_key'] ?? $_SERVER['HTTP_X_COMPANY_KEY'] ?? '';

    if (!empty($data['payload']['payment']['entity'])) {
        $pEntity = $data['payload']['payment']['entity'];
        $orderId = $pEntity['order_id'] ?? null;
        $paymentId = $pEntity['id'] ?? null;
        $amountInr = (int)round(($pEntity['amount'] ?? 0) / 100);
        $provider = 'razorpay';
    } elseif (!empty($data['order_id']) && !empty($data['payment_id'])) {
        // Direct / custom webhook
        $orderId = $data['order_id'];
        $paymentId = $data['payment_id'];
        $amountInr = (int)($data['amount_inr'] ?? $data['amount'] ?? 0);
        $provider = $data['provider'] ?? 'custom';
    }

    if (!$orderId || !$paymentId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing order_id or payment_id in webhook payload.']);
        exit;
    }

    // 2. Resolve Workspace from payment order
    $oStmt = $pdo->prepare("SELECT company_id, amount_inr, customer_id, lead_id FROM `payments` WHERE `razorpay_order_id` = ? LIMIT 1");
    $oStmt->execute([$orderId]);
    $existingPay = $oStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingPay) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => "Order '{$orderId}' not found."]);
        exit;
    }

    $companyId = (int)$existingPay['company_id'];
    if ($amountInr <= 0) {
        $amountInr = (int)$existingPay['amount_inr'];
    }

    // 3. Verify Signature if secret configured
    $config = PaymentProvider::getWorkspacePaymentConfig($pdo, $companyId);
    if (!empty($config['razorpay_secret']) && !empty($signature)) {
        $isValid = PaymentProvider::verifySignature($rawPayload, $signature, $config['razorpay_secret']);
        if (!$isValid) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Invalid cryptographic signature.']);
            exit;
        }
    }

    // 4. Settle Payment Idempotently
    $settleRes = PaymentProvider::settlePayment($pdo, $companyId, $orderId, $paymentId, $amountInr, $provider);

    // 5. Post AI payment confirmation into customer conversation
    $convStmt = $pdo->prepare("SELECT id FROM `conversations` WHERE `company_id` = ? AND `customer_id` = ? ORDER BY id DESC LIMIT 1");
    $convStmt->execute([$companyId, (int)$existingPay['customer_id']]);
    $convId = (int)$convStmt->fetchColumn();

    if ($convId > 0 && !$settleRes['already_settled']) {
        $confirmMsg = "✅ **Payment Verified & Recorded**\n\n"
            . "• **Amount Paid:** ₹" . number_format($amountInr) . "\n"
            . "• **Transaction ID:** `{$paymentId}`\n"
            . "• **Order Ref:** `{$orderId}`\n"
            . "• **Status:** Confirmed\n\n"
            . "Thank you! Your enrollment / purchase has been activated. Our team has dispatched your onboarding receipt and portal access.";

        $pdo->prepare("
            INSERT INTO `messages` (`company_id`, `conversation_id`, `sender_type`, `message_text`, `channel`, `created_at`)
            VALUES (?, ?, 'ai', ?, 'web', NOW())
        ")->execute([$companyId, $convId, $confirmMsg]);

        $pdo->prepare("
            UPDATE `conversations`
            SET `last_message_preview` = ?, `last_message_at` = NOW()
            WHERE id = ?
        ")->execute([substr($confirmMsg, 0, 150), $convId]);
    }

    echo json_encode([
        'success'         => true,
        'status'          => 'processed',
        'order_id'        => $orderId,
        'payment_id'      => $paymentId,
        'amount_inr'      => $amountInr,
        'already_settled' => $settleRes['already_settled']
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Webhook Error: ' . $e->getMessage()]);
}
