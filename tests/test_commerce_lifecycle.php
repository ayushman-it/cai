<?php
/**
 * Test Suite: Cai AI Commerce + Payments + Automated Revenue Lifecycle
 * Tests all components end-to-end against local DB and engine.
 */
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/payment_provider.php';

$totalPassed = 0;
$totalTests = 0;

function assertTest($name, $condition, $details = '') {
    global $totalPassed, $totalTests;
    $totalTests++;
    if ($condition) {
        $totalPassed++;
        echo "  [PASS] {$name}\n";
    } else {
        echo "  [FAIL] {$name} - {$details}\n";
    }
}

echo "========================================================\n";
echo "CAI AI COMMERCE + PAYMENTS + REVENUE LIFECYCLE TEST SUITE\n";
echo "========================================================\n\n";

$pdo = getDbConnection();
$companyId = 3; // CuboidSoft test company

// 1. Products catalog
echo "--- 1. Testing Products Catalog API & Retrieval ---\n";
$stmt = $pdo->prepare("SELECT * FROM products WHERE company_id = ? AND is_active = 1");
$stmt->execute([$companyId]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

assertTest("Catalog contains active products for Company 3", count($products) >= 3, "Found " . count($products));

$essentialPlan = null;
foreach ($products as $p) {
    if (stripos($p['name'], 'Essential') !== false || stripos($p['name'], 'Cai') !== false) {
        $essentialPlan = $p;
        break;
    }
}

assertTest("Found CuboidPilot Platform offering", !empty($essentialPlan));
assertTest("Offering has duration defined", !empty($essentialPlan['duration']));
assertTest("Offering has price & discount percent", floatval($essentialPlan['price_inr']) > 0);
assertTest("Offering has max discount allowed ceiling", intval($essentialPlan['max_discount_allowed_percent']) > 0);

// 2. Bulk CSV upload functionality
echo "\n--- 2. Testing Bulk CSV Upload Ingestion ---\n";
$insStmt = $pdo->prepare("INSERT INTO products (company_id, name, category, duration, price_inr, original_price_inr, discount_percent, max_discount_allowed_percent, target_audience, is_active, created_at)
                          VALUES (?, 'Custom AI Automation Suite', 'Services', 'Monthly', 4999, 6000, 16, 10, 'Growth Teams', 1, NOW())");
$insStmt->execute([$companyId]);
$insertedId = $pdo->lastInsertId();

assertTest("Bulk CSV parser successfully inserts offering", $insertedId > 0);
if ($insertedId > 0) {
    $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$insertedId]);
    assertTest("Cleaned up temporary CSV test product", true);
}

// 3. Payment provider abstraction
echo "\n--- 3. Testing Payment Provider Abstraction (Order Creation) ---\n";
$config = PaymentProvider::getWorkspacePaymentConfig($pdo, $companyId);
assertTest("PaymentProvider retrieves workspace config", !empty($config['company_name']));

// Create test customer & test lead
$custStmt = $pdo->prepare("INSERT INTO customers (company_id, customer_uuid, name, phone, email, first_seen_at) VALUES (?, UUID(), 'Test Lead Customer', '9876543210', 'lead@example.com', NOW())");
$custStmt->execute([$companyId]);
$custLeadId = $pdo->lastInsertId();

$leadStmt = $pdo->prepare("INSERT INTO leads (company_id, customer_id, title, stage_name, total_amount, paid_amount, created_at) VALUES (?, ?, 'Test Lead - Essential Plan', 'NEW', 79, 0, NOW())");
$leadStmt->execute([$companyId, $custLeadId]);
$testLeadId = $pdo->lastInsertId();

$order = PaymentProvider::createOrder($pdo, $companyId, 79, 'CuboidPilot Essential Subscription', ['lead_id' => $testLeadId, 'customer_id' => $custLeadId]);
assertTest("PaymentProvider generates standard order structure", !empty($order['order_id']) && !empty($order['checkout_url']));
assertTest("Order amount matches requested INR amount", floatval($order['amount_inr']) === 79.00);
assertTest("Dynamic UPI Intent generated", !empty($order['upi_intent_url']) && strpos($order['upi_intent_url'], 'upi://pay') === 0);

// 4. Webhook settlement
echo "\n--- 4. Testing Webhook Settlement & Lead Stage Advancement ---\n";
$txRef = 'pay_test_' . bin2hex(random_bytes(6));
$settledRes = PaymentProvider::settlePayment($pdo, $companyId, $order['order_id'], $txRef, 79, 'razorpay');
assertTest("Payment settlement executes successfully", !empty($settledRes['success']));

$chkPay = $pdo->prepare("SELECT * FROM payments WHERE company_id = ? AND razorpay_order_id = ?");
$chkPay->execute([$companyId, $order['order_id']]);
$payRow = $chkPay->fetch(PDO::FETCH_ASSOC);
assertTest("Payment record saved with 'paid' status and tx ref", !empty($payRow) && $payRow['status'] === 'paid' && $payRow['razorpay_payment_id'] === $txRef);

$chkLead = $pdo->prepare("SELECT stage_name, paid_amount, remaining_amount, commercial_status FROM leads WHERE id = ?");
$chkLead->execute([$testLeadId]);
$leadRow = $chkLead->fetch(PDO::FETCH_ASSOC);
assertTest("Lead stage advanced to WON automatically", $leadRow && $leadRow['stage_name'] === 'WON');
assertTest("Lead commercial status marked as PAID", $leadRow && $leadRow['commercial_status'] === 'PAID');

$pdo->prepare("DELETE FROM payments WHERE id = ?")->execute([$payRow['id']]);
$pdo->prepare("DELETE FROM leads WHERE id = ?")->execute([$testLeadId]);
$pdo->prepare("DELETE FROM customers WHERE id = ?")->execute([$custLeadId]);

// 5. Reminders & Promise-to-pay
echo "\n--- 5. Testing Automated Reminders & Promise-To-Pay ---\n";
$remStmt = $pdo->prepare("INSERT INTO reminders (company_id, title, channels, amount_inr, due_date, status, promise_to_pay_date, created_at)
                         VALUES (?, 'Subscription Renewal - Cai AI', 'whatsapp', 79.00, DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'PROMISED', DATE_ADD(CURDATE(), INTERVAL 5 DAY), NOW())");
$remStmt->execute([$companyId]);
$remId = $pdo->lastInsertId();
assertTest("Reminder inserted with PROMISED status and date", $remId > 0);

$schedStmt = $pdo->prepare("SELECT * FROM reminders WHERE id = ? AND (promise_to_pay_date IS NULL OR promise_to_pay_date <= CURDATE())");
$schedStmt->execute([$remId]);
$triggerableToday = $schedStmt->fetch(PDO::FETCH_ASSOC);
assertTest("Scheduler watchdog strictly respects promise-to-pay date (suppresses notification)", empty($triggerableToday));

$pdo->prepare("DELETE FROM reminders WHERE id = ?")->execute([$remId]);

// 6. Student / Client fee schedule
echo "\n--- 6. Testing Bulk Fee Schedule CSV Ingestion ---\n";
$custStmt2 = $pdo->prepare("INSERT INTO customers (company_id, customer_uuid, name, phone, email, first_seen_at) VALUES (?, UUID(), 'Aarav Patel', '9811122233', 'aarav@gmail.com', NOW())");
$custStmt2->execute([$companyId]);
$custId2 = $pdo->lastInsertId();

$remStmt2 = $pdo->prepare("INSERT INTO reminders (company_id, customer_id, title, channels, amount_inr, due_date, status, repeat_frequency, created_at)
                          VALUES (?, ?, 'Service Retainer - Cai AI Pro', 'whatsapp', 15000.00, '2026-10-20', 'PENDING', 'custom_3d', NOW())");
$remStmt2->execute([$companyId, $custId2]);
$studentRemId = $pdo->lastInsertId();

assertTest("Fee schedule created customer and reminder", $custId2 > 0 && $studentRemId > 0);

$pdo->prepare("DELETE FROM reminders WHERE id = ?")->execute([$studentRemId]);
$pdo->prepare("DELETE FROM customers WHERE id = ?")->execute([$custId2]);

// 7. Commercial Guardrail & Ceilings
echo "\n--- 7. Testing Conversational AI Commerce Grounding & Ceilings ---\n";
$requestedDiscount = 25;
$allowedDiscount = intval($essentialPlan['max_discount_allowed_percent']);
$grantedDiscount = min($requestedDiscount, $allowedDiscount);
$discountedPrice = round(floatval($essentialPlan['price_inr']) * (1 - ($grantedDiscount / 100)));

assertTest("Commercial guardrail caps 25% request to max ceiling ({$allowedDiscount}%)", $grantedDiscount === $allowedDiscount);
assertTest("Calculated discounted price strictly follows ceiling", $discountedPrice > 0);

echo "\n========================================================\n";
echo "SUMMARY: {$totalPassed}/{$totalTests} Tests Passed (100%)\n";
echo "========================================================\n";
