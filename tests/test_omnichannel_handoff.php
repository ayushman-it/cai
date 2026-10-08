<?php
/**
 * TEST SUITE: Omnichannel Notifications, Two-Way Email/WhatsApp Replies & Human Handoff
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../api/alerts.php';

$pdo = getDbConnection();

echo "=== CUBOIDPILOT OMNICHANNEL & HUMAN HANDOFF TEST SUITE ===\n\n";

// 1. Setup Test Workspace & User
$cCheck = $pdo->query("SELECT id, company_key, name FROM companies LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$cCheck) {
    die("ERROR: No company found in DB\n");
}
$companyId = (int)$cCheck['id'];
$companyKey = $cCheck['company_key'];
echo "[✓] Using Company: {$cCheck['name']} (#{$companyId}, key: {$companyKey})\n";

// Ensure a test user exists
$uCheck = $pdo->prepare("SELECT id, name, email, phone FROM users WHERE company_id = ? AND is_active = 1 LIMIT 1");
$uCheck->execute([$companyId]);
$testAgent = $uCheck->fetch(PDO::FETCH_ASSOC);
if (!$testAgent) {
    die("ERROR: No active user found for company #{$companyId}\n");
}
echo "[✓] Using Agent: {$testAgent['name']} ({$testAgent['email']}, phone: {$testAgent['phone']})\n";

// 2. Test Human Handoff Initiation via API
echo "\n--- TEST 1: Initiate Human Chat (widget_actions.php) ---\n";
$token = 'test_sess_' . bin2hex(random_bytes(8));
$postData = json_encode([
    'company_key'   => $companyKey,
    'user_id'       => $testAgent['id'],
    'session_token' => $token,
    'name'          => 'Rahul Test',
    'email'         => 'rahul.test@example.com',
    'phone'         => '9876543210'
]);

$ch = curl_init("http://localhost/cuboidpilot/api/widget_actions.php?action=start_human_chat");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-Company-Key: ' . $companyKey
    ]
]);
$resRaw = curl_exec($ch);
$resCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$res = json_decode($resRaw, true);
if ($resCode === 200 && !empty($res['success']) && !empty($res['conversation_id'])) {
    $convId = (int)$res['conversation_id'];
    echo "[✓] Human Chat started successfully! Conversation ID: #{$convId}\n";
} else {
    die("[-] FAILED starting human chat: {$resRaw}\n");
}

// Verify conversation status in DB
$conv = $pdo->prepare("SELECT status, ownership, quick_action_token FROM conversations WHERE id = ?");
$conv->execute([$convId]);
$convData = $conv->fetch(PDO::FETCH_ASSOC);
echo "    DB Status: {$convData['status']} (Expected: human_requested)\n";
echo "    DB Ownership: {$convData['ownership']} (Expected: human)\n";
echo "    Quick Action Token: {$convData['quick_action_token']}\n";
assert($convData['status'] === 'human_requested');
assert(!empty($convData['quick_action_token']));

// 3. Test Inbound Email Webhook
echo "\n--- TEST 2: Inbound Email Webhook (api/webhooks/inbound_email.php) ---\n";
$quickToken = $convData['quick_action_token'];
$emailReplyPayload = json_encode([
    'from'     => $testAgent['email'],
    'to'       => "reply+{$quickToken}@cai.cuboidsoft.in",
    'subject'  => "Re: Human Assistance Requested — CuboidSoft (#CONV-{$convId})",
    'text'     => "Hello Rahul! This is an email reply from your support agent.\n\nOn Wed, Oct 8, 2026 at 5:00 PM Support wrote:\n> Original quoted email text that should be stripped\n> Line 2"
]);

$ch = curl_init("http://localhost/cuboidpilot/api/webhooks/inbound_email.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $emailReplyPayload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json']
]);
$emailResRaw = curl_exec($ch);
$emailResCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$emailRes = json_decode($emailResRaw, true);
echo "HTTP {$emailResCode}: " . json_encode($emailRes) . "\n";
assert($emailResCode === 200);
assert(!empty($emailRes['success']));
echo "[✓] Inbound email processed! Injected message ID: {$emailRes['message_id']}\n";

// Check stripped message text in DB
$msgStmt = $pdo->prepare("SELECT message_text, sender_type, channel FROM messages WHERE id = ?");
$msgStmt->execute([(int)$emailRes['message_id']]);
$msgRow = $msgStmt->fetch(PDO::FETCH_ASSOC);
echo "    Cleaned Text: \"{$msgRow['message_text']}\"\n";
echo "    Sender Type: {$msgRow['sender_type']} (Expected: human)\n";
echo "    Channel: {$msgRow['channel']} (Expected: email)\n";
assert(strpos($msgRow['message_text'], 'Original quoted') === false);
assert($msgRow['sender_type'] === 'human');
assert($msgRow['channel'] === 'email');

// 4. Test Inbound WhatsApp Reply Bridge
echo "\n--- TEST 3: Inbound WhatsApp Agent Reply (api/webhooks/whatsapp.php) ---\n";
$agentPhone = !empty($testAgent['phone']) ? $testAgent['phone'] : '919999999999';
$waPayload = json_encode([
    'from'    => $agentPhone,
    'message' => "REPLY #{$convId} I am also replying to your widget from WhatsApp!"
]);

$ch = curl_init("http://localhost/cuboidpilot/api/webhooks/whatsapp.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $waPayload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json']
]);
$waResRaw = curl_exec($ch);
$waResCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$waRes = json_decode($waResRaw, true);
echo "HTTP {$waResCode}: " . json_encode($waRes) . "\n";
assert($waResCode === 200);
assert($waRes['status'] === 'agent_reply_delivered');
echo "[✓] WhatsApp agent reply processed!\n";

// 5. Test Widget poll_messages Endpoint
echo "\n--- TEST 4: Widget poll_messages Real-Time Delivery ---\n";
$pollUrl = "http://localhost/cuboidpilot/api/widget_actions.php?action=poll_messages&conversation_id={$convId}&after_id=0&company_key=" . urlencode($companyKey) . "&session_token=" . urlencode($token);
$ch = curl_init($pollUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$pollRaw = curl_exec($ch);
$pollCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$pollRes = json_decode($pollRaw, true);
echo "HTTP {$pollCode}: Success: " . ($pollRes['success'] ? 'true' : 'false') . ", Status: {$pollRes['status']}\n";
assert($pollCode === 200);
assert($pollRes['status'] === 'human_active');
assert($pollRes['ownership'] === 'human');

$humanMsgs = array_filter($pollRes['messages'], function($m) {
    return $m['sender'] === 'human_agent';
});
echo "[✓] Human messages delivered to widget: " . count($humanMsgs) . "\n";
foreach ($humanMsgs as $m) {
    echo "    - [{$m['sender']}] {$m['text']}\n";
}
assert(count($humanMsgs) >= 2);

// 6. Test Multi-Tenant Security / IDOR Protection
echo "\n--- TEST 5: Multi-Tenant / IDOR Protection ---\n";
// Create another company check or simulate spoofed sender
$fakeSenderPayload = json_encode([
    'from'    => 'intruder@unknown-domain.com',
    'to'      => 'noreply@cai.cuboidsoft.in',
    'subject' => "Re: [#CONV-{$convId}] Unauthorized injection test",
    'text'    => "Hacking attempt without valid capability token"
]);

$ch = curl_init("http://localhost/cuboidpilot/api/webhooks/inbound_email.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $fakeSenderPayload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json']
]);
$fakeResRaw = curl_exec($ch);
$fakeCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP {$fakeCode}: {$fakeResRaw}\n";
assert($fakeCode === 422 || $fakeCode === 400 || $fakeCode === 403);
echo "[✓] Unauthorized inbound email properly rejected!\n";

echo "\n============================================\n";
echo " ALL TESTS PASSED SUCCESSFULLY! (5 / 5)\n";
echo "============================================\n";
