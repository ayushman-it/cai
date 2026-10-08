<?php
/**
 * CUBOIDPILOT — INBOUND EMAIL WEBHOOK ROUTER
 * Handles incoming email replies from agents, consultants, or company owners.
 * Supports SendGrid Inbound Parse, Mailgun, Postmark, and generic multipart/JSON webhooks.
 * Extracts the conversation routing token, strips quoted reply history,
 * validates sender authorization, and injects the response into the canonical messages table.
 */

declare(strict_types = 0);
error_reporting(0);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=UTF-8');

$pdo = getDbConnection();

// 1. Read input payload (JSON or Form POST)
$rawInput = file_get_contents('php://input');
$jsonPayload = json_decode($rawInput, true);

$fromRaw    = '';
$toRaw      = '';
$subjectRaw = '';
$bodyRaw    = '';
$tokenParam = trim($_GET['token'] ?? $_POST['token'] ?? ($jsonPayload['token'] ?? ''));

// Detect payload structure
if (!empty($jsonPayload)) {
    // Postmark / Generic JSON format
    $fromRaw    = $jsonPayload['From'] ?? $jsonPayload['from'] ?? $jsonPayload['sender'] ?? '';
    $toRaw      = $jsonPayload['To'] ?? $jsonPayload['to'] ?? $jsonPayload['recipient'] ?? '';
    $subjectRaw = $jsonPayload['Subject'] ?? $jsonPayload['subject'] ?? '';
    $bodyRaw    = $jsonPayload['TextBody'] ?? $jsonPayload['text'] ?? $jsonPayload['body'] ?? $jsonPayload['HtmlBody'] ?? '';
} else {
    // SendGrid / Mailgun / Form POST format
    $fromRaw    = $_POST['from'] ?? $_POST['sender'] ?? '';
    $toRaw      = $_POST['to'] ?? $_POST['recipient'] ?? '';
    $subjectRaw = $_POST['subject'] ?? '';
    $bodyRaw    = $_POST['stripped-text'] ?? $_POST['text'] ?? $_POST['body-plain'] ?? $_POST['html'] ?? '';
}

// 2. Extract clean email address
function extractCleanEmail(string $raw): string {
    if (preg_match('/<([^>]+)>/', $raw, $matches)) {
        return strtolower(trim($matches[1]));
    }
    return strtolower(trim(preg_replace('/^[^<]*<\s*|\s*>.*$/', '', $raw)));
}

$senderEmail = extractCleanEmail($fromRaw);
$recipientEmail = extractCleanEmail($toRaw);

// 3. Strip quoted email history, reply demarcation, and signatures
function cleanInboundEmailBody(string $text): string {
    // Convert basic HTML breaks if raw text is HTML
    if (strpos($text, '<') !== false && strpos($text, '>') !== false) {
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/p>/i', "\n\n", $text);
        $text = strip_tags($text);
    }

    $lines = preg_split('/\r\n|\r|\n/', $text);
    $cleanLines = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // Common email reply header cutoffs
        if (preg_match('/^On\s+.+wrote:\s*$/i', $trimmed)) break;
        if (preg_match('/^-----Original Message-----/i', $trimmed)) break;
        if (preg_match('/^_{10,}/', $trimmed)) break; // Outlook underline break
        if (preg_match('/^-{10,}/', $trimmed)) break;
        if (preg_match('/^From:\s+.+Sent:\s+.+/i', $trimmed)) break;
        if (preg_match('/^Sent from my (iPhone|Android|Galaxy|iPad|device)/i', $trimmed)) break;
        if (preg_match('/^Get Outlook for (iOS|Android)/i', $trimmed)) break;

        // Skip quoted lines starting with >
        if (strpos($trimmed, '>') === 0) continue;

        $cleanLines[] = $line;
    }

    $result = trim(implode("\n", $cleanLines));

    // Fallback: If stripping left nothing, use original text without leading >
    if (empty($result)) {
        $nonQuoted = [];
        foreach ($lines as $l) {
            if (strpos(trim($l), '>') !== 0) {
                $nonQuoted[] = $l;
            }
        }
        $result = trim(implode("\n", $nonQuoted));
    }

    return !empty($result) ? $result : trim($text);
}

$cleanMessage = cleanInboundEmailBody($bodyRaw);

if (empty($cleanMessage)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Inbound email body is empty.']);
    exit;
}

// 4. Extract Routing Token & Conversation Identifier
$routingToken = $tokenParam;
$matchedConvId = null;

// Pattern A: Token in recipient address (reply+{token}@domain.com or reply-{token}@domain.com)
if (empty($routingToken) && preg_match('/(?:reply|conv|cai)[+-]([a-f0-9]{24,64})@/i', $toRaw, $toMatch)) {
    $routingToken = strtolower($toMatch[1]);
}

// Pattern B: Message-ID / In-Reply-To header parsing
$allHeaders = $_POST['headers'] ?? ($jsonPayload['Headers'] ?? '');
if (is_array($allHeaders)) {
    $allHeaders = json_encode($allHeaders);
}
if (empty($routingToken) && is_string($allHeaders)) {
    if (preg_match('/<(?:conv|lead)-(?:[0-9]+)-([a-f0-9]{24,64})@/i', $allHeaders, $hMatch)) {
        $routingToken = strtolower($hMatch[1]);
    }
}

// Pattern C: Subject Conversation ID tag [#CONV-123] or #CONV-123
if (preg_match('/(?:#CONV-|\[#CONV-|\[CONV-|CONV-)([0-9]+)/i', $subjectRaw, $sMatch)) {
    $matchedConvId = (int)$sMatch[1];
}

// 5. Look up conversation
$conv = null;
if (!empty($routingToken)) {
    $cStmt = $pdo->prepare("SELECT * FROM `conversations` WHERE `quick_action_token` = ? LIMIT 1");
    $cStmt->execute([$routingToken]);
    $conv = $cStmt->fetch(PDO::FETCH_ASSOC);
}

if (!$conv && $matchedConvId) {
    $cStmt = $pdo->prepare("SELECT * FROM `conversations` WHERE `id` = ? LIMIT 1");
    $cStmt->execute([$matchedConvId]);
    $conv = $cStmt->fetch(PDO::FETCH_ASSOC);
}

if (!$conv) {
    // Log unresolved inbound email event for diagnostic auditing
    try {
        $pdo->prepare("
            INSERT INTO `alert_logs` (`company_id`, `alert_type`, `recipient`, `recipient_type`, `channel`, `content_preview`, `sent_at`)
            VALUES (0, 'INBOUND_EMAIL_UNRESOLVED', ?, 'system', 'email', ?, NOW())
        ")->execute([$senderEmail, substr("To: {$toRaw} | Subj: {$subjectRaw} | Msg: {$cleanMessage}", 0, 250)]);
    } catch (Throwable $t) {}

    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error'   => 'Could not identify target conversation. Verify Reply-To token or subject #CONV tag.'
    ]);
    exit;
}

$conversationId = (int)$conv['id'];
$companyId      = (int)$conv['company_id'];

// 6. Authorize Sender (IDOR Protection & Multi-Tenant Isolation)
$matchedAgent = null;
if (!empty($senderEmail)) {
    $uStmt = $pdo->prepare("
        SELECT id, name, role, email, avatar_url, job_title 
        FROM `users` 
        WHERE `company_id` = ? AND LOWER(`email`) = ? AND `is_active` = 1 
        LIMIT 1
    ");
    $uStmt->execute([$companyId, $senderEmail]);
    $matchedAgent = $uStmt->fetch(PDO::FETCH_ASSOC);
}

$isTokenAuthorized = (!empty($routingToken) && !empty($conv['quick_action_token']) && hash_equals($conv['quick_action_token'], $routingToken));

if (!$isTokenAuthorized && !$matchedAgent) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'Unauthorized sender: email address does not belong to an active team member for this company, and no valid authorization token was provided.'
    ]);
    exit;
}

// If token matched or agent verified, resolve assigned user details
$agentUserId = $matchedAgent ? (int)$matchedAgent['id'] : (!empty($conv['assigned_user_id']) ? (int)$conv['assigned_user_id'] : null);
$agentName   = $matchedAgent ? $matchedAgent['name'] : 'Team Specialist';

// 7. Insert Response Message into Canonical Messages Table
$insMsg = $pdo->prepare("
    INSERT INTO `messages` 
    (`company_id`, `conversation_id`, `sender_type`, `sender_id`, `message_text`, `channel`, `created_at`)
    VALUES (?, ?, 'human', ?, ?, 'email', NOW())
");
$insMsg->execute([
    $companyId,
    $conversationId,
    $agentUserId,
    $cleanMessage
]);
$messageId = (int)$pdo->lastInsertId();

// 8. Update Conversation State to 'human_active'
$pdo->prepare("
    UPDATE `conversations`
    SET `status` = 'human_active',
        `ownership` = 'human',
        `assigned_user_id` = COALESCE(?, `assigned_user_id`),
        `last_message_preview` = ?,
        `last_message_at` = NOW(),
        `unread_human` = 0
    WHERE id = ? AND company_id = ?
")->execute([
    $agentUserId,
    substr($cleanMessage, 0, 150),
    $conversationId,
    $companyId
]);

// 9. Update Human Handoff status if present
try {
    $pdo->prepare("
        UPDATE `human_handoffs` 
        SET `status` = 'active', 
            `assigned_to_user_id` = COALESCE(?, `assigned_to_user_id`),
            `accepted_at` = COALESCE(`accepted_at`, NOW())
        WHERE `conversation_id` = ? AND `status` = 'pending'
    ")->execute([$agentUserId, $conversationId]);
} catch (Throwable $hEx) {}

// 10. Audit Log in alert_logs
try {
    $pdo->prepare("
        INSERT INTO `alert_logs` (`company_id`, `lead_id`, `alert_type`, `recipient`, `recipient_type`, `channel`, `content_preview`, `sent_at`)
        VALUES (?, (SELECT id FROM leads WHERE conversation_id = ? LIMIT 1), 'INBOUND_EMAIL_DELIVERED', ?, 'customer', 'email', ?, NOW())
    ")->execute([
        $companyId,
        $conversationId,
        $senderEmail,
        substr($cleanMessage, 0, 200)
    ]);
} catch (Throwable $lEx) {}

// Return successful JSON response
echo json_encode([
    'success'         => true,
    'conversation_id' => $conversationId,
    'company_id'      => $companyId,
    'message_id'      => $messageId,
    'agent_name'      => $agentName,
    'sender'          => $senderEmail,
    'channel'         => 'email',
    'status'          => 'human_active',
    'message_preview' => substr($cleanMessage, 0, 100)
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
