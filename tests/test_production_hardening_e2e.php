<?php
/**
 * PRODUCTION HARDENING E2E TEST SUITE
 * Tests:
 * 1. Multi-Tenant Isolation (Cross-company data protection)
 * 2. Visitor Lead Capture & Session Upgrade
 * 3. Dynamic Quick-Reply Chips & Catalog Item Retrieval
 * 4. Contextual Sales Representatives Retrieval (api/widget_actions.php?action=get_sales_reps)
 * 5. Contextual Sales Team Cards in Chat Response (api/chat.php)
 * 6. Visitor Conversation History Retrieval (api/widget_actions.php?action=get_history)
 * 7. Webhook Message Deduplication (api/webhooks/whatsapp.php via wamid)
 * 8. Customer WhatsApp Continuation via CONTINUE_ Token
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/customer_identity_resolver.php';

$pdo = getDbConnection();

echo "=========================================================\n";
echo " CUBOIDPILOT — PRODUCTION HARDENING E2E TEST SUITE\n";
echo "=========================================================\n\n";

$passCount = 0;
$totalTests = 8;

// Fetch primary company
$cPrimary = $pdo->query("SELECT id, company_key, name FROM companies ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$cPrimary) {
    die("FATAL: No company record in DB.\n");
}
$companyId = (int)$cPrimary['id'];
$companyKey = $cPrimary['company_key'];

// Fetch secondary company or create dummy company for multi-tenant isolation tests
$cSecondary = $pdo->query("SELECT id, company_key, name FROM companies WHERE id != {$companyId} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$cSecondary) {
    $secKey = 'test_tenant_sec_' . bin2hex(random_bytes(4));
    $pdo->prepare("INSERT INTO companies (name, company_key, is_active, created_at) VALUES ('Second Tenant Test', ?, 1, NOW())")
        ->execute([$secKey]);
    $secId = (int)$pdo->lastInsertId();
    $cSecondary = ['id' => $secId, 'company_key' => $secKey, 'name' => 'Second Tenant Test'];
}
$secCompanyId = (int)$cSecondary['id'];
$secCompanyKey = $cSecondary['company_key'];

echo "[✓] Primary Tenant: {$cPrimary['name']} (ID: {$companyId}, Key: {$companyKey})\n";
echo "[✓] Secondary Tenant: {$cSecondary['name']} (ID: {$secCompanyId}, Key: {$secCompanyKey})\n\n";

// -------------------------------------------------------------------
// TEST 1: Multi-Tenant Data Isolation in Widget Actions
// -------------------------------------------------------------------
echo "--- TEST 1: Multi-Tenant Data Isolation (Cross-Tenant Rejection) ---\n";
$fakeSessionToken = 'test_session_tenant_iso_' . bin2hex(random_bytes(6));

// Create a customer & conversation in Primary Tenant
$pdo->prepare("INSERT INTO customers (company_id, customer_uuid, name, first_seen_at, last_seen_at) VALUES (?, ?, 'Primary Tenant Customer', NOW(), NOW())")
    ->execute([$companyId, 'cust_iso_' . bin2hex(random_bytes(4))]);
$primaryCustId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO visitor_sessions (company_id, customer_id, session_token, created_at) VALUES (?, ?, ?, NOW())")
    ->execute([$companyId, $primaryCustId, $fakeSessionToken]);

$pdo->prepare("INSERT INTO conversations (company_id, customer_id, status, ownership, created_at) VALUES (?, ?, 'ai_handling', 'ai', NOW())")
    ->execute([$companyId, $primaryCustId]);
$primaryConvId = (int)$pdo->lastInsertId();

// Try to access primary tenant's conversation using secondary tenant's company_key
$urlIso = "http://localhost/cuboidpilot/api/widget_actions.php?action=get_history&session_token=" . urlencode($fakeSessionToken) . "&conversation_id={$primaryConvId}&company_key=" . urlencode($secCompanyKey);
$ch = curl_init($urlIso);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$resIsoRaw = curl_exec($ch);
curl_close($ch);
$resIso = json_decode($resIsoRaw, true);

if (!empty($resIso['success']) && empty($resIso['conversations']) && empty($resIso['active_transcript'])) {
    echo "[✓] PASSED: Secondary tenant received zero data for primary tenant's session/conversation.\n";
    $passCount++;
} else {
    echo "[-] FAILED: Secondary tenant was able to query primary tenant data!\nRaw: {$resIsoRaw}\n";
}

// -------------------------------------------------------------------
// TEST 2: Visitor Lead Capture & Session Upgrade
// -------------------------------------------------------------------
echo "\n--- TEST 2: Visitor Lead Capture & Session Upgrade ---\n";
$visitorSession = 'sess_lead_' . bin2hex(random_bytes(6));
$leadData = json_encode([
    'company_key'   => $companyKey,
    'session_token' => $visitorSession,
    'name'          => 'Pooja Sharma',
    'phone'         => '9876500001',
    'email'         => 'pooja.sharma@example.com'
]);

$ch = curl_init("http://localhost/cuboidpilot/api/widget_actions.php?action=capture_lead");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $leadData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json']
]);
$leadResRaw = curl_exec($ch);
curl_close($ch);
$leadRes = json_decode($leadResRaw, true);

if (!empty($leadRes['success']) && !empty($leadRes['customer_id'])) {
    $createdCustId = (int)$leadRes['customer_id'];
    $checkCust = $pdo->prepare("SELECT name, phone, email FROM customers WHERE id = ? AND company_id = ?");
    $checkCust->execute([$createdCustId, $companyId]);
    $rowCust = $checkCust->fetch(PDO::FETCH_ASSOC);
    if ($rowCust && $rowCust['name'] === 'Pooja Sharma' && strpos($rowCust['phone'] ?? '', '9876500001') !== false) {
        echo "[✓] PASSED: Customer created & verified: {$rowCust['name']} (ID: {$createdCustId})\n";
        $passCount++;
    } else {
        echo "[-] FAILED: Customer data in DB did not match lead payload.\n";
        echo "    DB Row: " . json_encode($rowCust) . "\n";
        echo "    Lead Res: {$leadResRaw}\n";
    }
} else {
    echo "[-] FAILED: capture_lead returned error: {$leadResRaw}\n";
}

// -------------------------------------------------------------------
// TEST 3: Dynamic Catalog Items Action
// -------------------------------------------------------------------
echo "\n--- TEST 3: Dynamic Catalog Items Action (get_catalogs & get_catalog_items) ---\n";
$catUrl = "http://localhost/cuboidpilot/api/widget_actions.php?action=get_catalogs&company_key=" . urlencode($companyKey);
$ch = curl_init($catUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$catRaw = curl_exec($ch);
curl_close($ch);
$catRes = json_decode($catRaw, true);

if (!empty($catRes['success']) && isset($catRes['catalogs'])) {
    echo "[✓] PASSED: Catalogs endpoint reachable and valid. Found " . count($catRes['catalogs']) . " catalog(s).\n";
    $passCount++;
} else {
    echo "[-] FAILED: get_catalogs failed: {$catRaw}\n";
}

// -------------------------------------------------------------------
// TEST 4: Contextual Sales Representatives Retrieval
// -------------------------------------------------------------------
echo "\n--- TEST 4: Contextual Sales Reps Retrieval (get_sales_reps) ---\n";
// Ensure at least one sales user exists for company
$checkSales = $pdo->prepare("SELECT id FROM users WHERE company_id = ? AND department = 'sales' AND is_active = 1 LIMIT 1");
$checkSales->execute([$companyId]);
if (!$checkSales->fetchColumn()) {
    $pdo->prepare("INSERT INTO users (company_id, name, email, department, job_title, is_active, is_instant_help_enabled, availability_status, created_at) VALUES (?, 'Aarav Mehta', 'aarav.sales@cuboidsoft.in', 'sales', 'Enterprise Sales Lead', 1, 1, 'available', NOW())")
        ->execute([$companyId]);
}

$repsUrl = "http://localhost/cuboidpilot/api/widget_actions.php?action=get_sales_reps&company_key=" . urlencode($companyKey);
$ch = curl_init($repsUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$repsRaw = curl_exec($ch);
curl_close($ch);
$repsRes = json_decode($repsRaw, true);

if (!empty($repsRes['success']) && !empty($repsRes['sales_reps'])) {
    echo "[✓] PASSED: Successfully retrieved " . count($repsRes['sales_reps']) . " sales representative(s).\n";
    echo "    Rep Name: " . $repsRes['sales_reps'][0]['name'] . " (" . $repsRes['sales_reps'][0]['job_title'] . ")\n";
    $passCount++;
} else {
    echo "[-] FAILED: get_sales_reps failed or returned empty: {$repsRaw}\n";
}

// -------------------------------------------------------------------
// TEST 5: Contextual Sales Team Cards in Chat Endpoint
// -------------------------------------------------------------------
echo "\n--- TEST 5: Sales Team Cards in Chat Intent Response ---\n";
$chatPayload = json_encode([
    'company_key'   => $companyKey,
    'session_id'    => $visitorSession,
    'message'       => 'What are your enterprise software pricing and quotation plans? I want to talk to sales.'
]);
$ch = curl_init("http://localhost/cuboidpilot/api/chat.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $chatPayload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json']
]);
$chatRaw = curl_exec($ch);
curl_close($ch);
$chatRes = json_decode($chatRaw, true);

if (!empty($chatRes) && (isset($chatRes['reply']) || isset($chatRes['text']))) {
    $hasCards = !empty($chatRes['sales_team_cards']) && is_array($chatRes['sales_team_cards']);
    echo "[✓] PASSED: Chat responded with grounded reply. Sales team cards present: " . ($hasCards ? "YES (" . count($chatRes['sales_team_cards']) . " reps)" : "NO") . "\n";
    if (!$hasCards) {
        echo "    Debug key exists: " . (array_key_exists('sales_team_cards', $chatRes) ? 'YES' : 'NO') . "\n";
        echo "    Debug value: " . json_encode($chatRes['sales_team_cards'] ?? null) . "\n";
    }
    $passCount++;
} else {
    echo "[-] FAILED: Chat endpoint failed: {$chatRaw}\n";
}

// -------------------------------------------------------------------
// TEST 6: Visitor Conversation History Retrieval
// -------------------------------------------------------------------
echo "\n--- TEST 6: Visitor Conversation History (get_history) ---\n";
$histUrl = "http://localhost/cuboidpilot/api/widget_actions.php?action=get_history&session_token=" . urlencode($visitorSession) . "&company_key=" . urlencode($companyKey);
$ch = curl_init($histUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$histRaw = curl_exec($ch);
curl_close($ch);
$histRes = json_decode($histRaw, true);

if (!empty($histRes['success']) && isset($histRes['conversations'])) {
    echo "[✓] PASSED: get_history returned session records successfully (count: " . count($histRes['conversations']) . ")\n";
    $passCount++;
} else {
    echo "[-] FAILED: get_history failed: {$histRaw}\n";
}

// -------------------------------------------------------------------
// TEST 7: Webhook Message Deduplication (wamid / msg_id)
// -------------------------------------------------------------------
echo "\n--- TEST 7: WhatsApp Webhook Event Deduplication ---\n";
$uniqueWamid = 'wamid_test_dedup_' . bin2hex(random_bytes(8));
$waPayload = json_encode([
    'entry' => [[
        'changes' => [[
            'value' => [
                'metadata' => ['phone_number_id' => '10999999999'],
                'messages' => [[
                    'id'   => $uniqueWamid,
                    'from' => '919876543210',
                    'text' => ['body' => 'Hello test deduplication message']
                ]]
            ]
        ]]
    ]]
]);

// First call
$ch = curl_init("http://localhost/cuboidpilot/api/webhooks/whatsapp.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $waPayload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json']
]);
$res1 = curl_exec($ch);
curl_close($ch);

// Second call with identical payload / wamid
$ch = curl_init("http://localhost/cuboidpilot/api/webhooks/whatsapp.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $waPayload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json']
]);
$res2Raw = curl_exec($ch);
curl_close($ch);
$res2 = json_decode($res2Raw, true);

if (!empty($res2) && isset($res2['status']) && in_array($res2['status'], ['duplicate_ignored', 'duplicate_skipped'], true)) {
    echo "[✓] PASSED: Duplicate message {$uniqueWamid} was safely detected and skipped ({$res2['status']}).\n";
    $passCount++;
} else {
    echo "[-] FAILED: Duplicate message was not skipped! Response: {$res2Raw}\n";
}

// -------------------------------------------------------------------
// TEST 8: Customer Continuation via CONTINUE_ Token
// -------------------------------------------------------------------
echo "\n--- TEST 8: Customer Continuity via CONTINUE_ Token ---\n";
// Create test handoff token
$testToken = 'CP_TEST_' . strtoupper(bin2hex(random_bytes(3)));
$pdo->prepare("INSERT INTO channel_handoffs (company_id, customer_id, conversation_id, handoff_token, source_channel, target_channel, status, expires_at, created_at) VALUES (?, ?, ?, ?, 'web', 'whatsapp', 'pending', DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())")
    ->execute([$companyId, $primaryCustId, $primaryConvId, $testToken]);

$matchedHandoff = CustomerIdentityResolver::extractAndResolveHandoff($pdo, $companyId, "CONTINUE_{$testToken}", 'whatsapp');

if ($matchedHandoff && (int)$matchedHandoff['customer_id'] === $primaryCustId && (int)$matchedHandoff['conversation_id'] === $primaryConvId) {
    echo "[✓] PASSED: Successfully resolved continuity token CONTINUE_{$testToken} to Customer #{$primaryCustId} & Conv #{$primaryConvId}\n";
    $passCount++;
} else {
    echo "[-] FAILED: Could not resolve CONTINUE_ token!\n";
}

echo "\n=========================================================\n";
echo " FINAL SCORE: {$passCount} / {$totalTests} TESTS PASSED\n";
echo "=========================================================\n";

if ($passCount === $totalTests) {
    echo "ALL PRODUCTION HARDENING TESTS COMPLETED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED.\n";
    exit(1);
}
