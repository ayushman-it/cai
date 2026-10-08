<?php
/**
 * CUBOIDPILOT — REMOTE QUICK ACTION ENDPOINT
 * Allows human agents / company owners to reply to conversations
 * and resolve/close them directly from their email inbox without logging in.
 */

error_reporting(0);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/mailer.php';

$pdo = getDbConnection();

$token   = trim($_GET['token'] ?? $_POST['token'] ?? '');
$action  = strtolower(trim($_GET['action'] ?? $_POST['action'] ?? 'view'));
$message = trim($_POST['message'] ?? $_GET['message'] ?? '');

$scheme   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = (strpos($_SERVER['REQUEST_URI'] ?? '', '/cuboidpilot') !== false) ? '/cuboidpilot' : '';
$baseUrl  = "{$scheme}://{$host}{$basePath}";

function renderQuickActionFeedback(string $title, string $desc, string $type = 'success', ?int $convId = null, string $baseUrl = ''): string {
    $color = $type === 'success' ? '#10b981' : ($type === 'warning' ? '#f59e0b' : '#ef4444');
    $icon  = $type === 'success' ? '✓' : ($type === 'warning' ? 'ℹ' : '✗');
    $dashUrl = "{$baseUrl}/app/conversations.html" . ($convId ? "?id={$convId}" : "");

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>{$title} — CuboidPilot Remote Action</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Inter', -apple-system, sans-serif; background: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
    .card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; max-width: 520px; width: 100%; padding: 32px; text-align: center; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05); }
    .badge { width: 56px; height: 56px; border-radius: 50%; background: rgba(0,0,0,0.04); color: {$color}; display: inline-flex; align-items: center; justify-content: center; font-size: 28px; margin-bottom: 16px; font-weight: bold; border: 2px solid {$color}; }
    h1 { font-size: 20px; font-weight: 700; color: #0f172a; margin: 0 0 10px; }
    p { font-size: 14px; color: #475569; line-height: 1.6; margin: 0 0 24px; }
    .btn { display: inline-block; padding: 11px 24px; background: #0f172a; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 13px; margin: 4px; }
    .btn-secondary { background: #f1f5f9; color: #334155; }
  </style>
</head>
<body>
  <div class="card">
    <div class="badge">{$icon}</div>
    <h1>{$title}</h1>
    <p>{$desc}</p>
    <div>
      <a href="{$dashUrl}" class="btn">Open in Dashboard</a>
      <a href="javascript:window.close()" class="btn btn-secondary">Close Window</a>
    </div>
  </div>
</body>
</html>
HTML;
}

if (empty($token)) {
    header("Content-Type: text/html; charset=UTF-8");
    echo renderQuickActionFeedback("Invalid Link", "Missing security authentication token.", "error", null, $baseUrl);
    exit;
}

// Find conversation by quick_action_token
$stmt = $pdo->prepare("
    SELECT c.*, cust.name as customer_name, cust.email as customer_email, cust.phone as customer_phone,
           comp.name as company_name, u.name as assigned_agent_name, u.email as assigned_agent_email
    FROM `conversations` c
    JOIN `companies` comp ON comp.id = c.company_id
    LEFT JOIN `customers` cust ON cust.id = c.customer_id
    LEFT JOIN `users` u ON u.id = c.assigned_user_id
    WHERE c.`quick_action_token` = ?
    LIMIT 1
");
$stmt->execute([$token]);
$conv = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$conv) {
    header("Content-Type: text/html; charset=UTF-8");
    echo renderQuickActionFeedback("Link Expired or Not Found", "This conversation action link is invalid or has expired.", "error", null, $baseUrl);
    exit;
}

$convId    = (int)$conv['id'];
$companyId = (int)$conv['company_id'];
$custName  = htmlspecialchars($conv['customer_name'] ?: 'Website Visitor');
$agentName = htmlspecialchars($conv['assigned_agent_name'] ?: 'Customer Specialist');

// -------------------------------------------------------------
// ACTION: close or resolve
// -------------------------------------------------------------
if ($action === 'close' || $action === 'resolve') {
    $newStatus   = ($action === 'close') ? 'closed' : 'resolved';
    $statusLabel = ($action === 'close') ? 'Closed' : 'Resolved';

    $pdo->prepare("
        UPDATE `conversations`
        SET `status` = ?,
            `ownership` = 'human',
            `closed_at` = IF(? = 'closed', NOW(), closed_at),
            `closure_reason` = 'Remote 1-Click Action via Email',
            `last_message_at` = NOW()
        WHERE id = ? AND company_id = ?
    ")->execute([$newStatus, $newStatus, $convId, $companyId]);

    // Insert system note
    $note = "[System Event] Conversation marked as {$statusLabel} remotely by agent via email.";
    $pdo->prepare("
        INSERT INTO `messages` (`company_id`, `conversation_id`, `sender_type`, `message_text`, `created_at`)
        VALUES (?, ?, 'system', ?, NOW())
    ")->execute([$companyId, $convId, $note]);

    header("Content-Type: text/html; charset=UTF-8");
    echo renderQuickActionFeedback(
        "Conversation #CONV-{$convId} {$statusLabel}!",
        "Conversation with <strong>{$custName}</strong> has been successfully marked as <strong>{$statusLabel}</strong>.<br>The live widget and dashboard have been updated in real-time.",
        "success",
        $convId,
        $baseUrl
    );
    exit;
}

// -------------------------------------------------------------
// ACTION: reply (POST submission from form)
// -------------------------------------------------------------
if ($action === 'reply' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($message)) {
        header("Content-Type: text/html; charset=UTF-8");
        echo renderQuickActionFeedback("Empty Message", "Please type a message before submitting.", "error", $convId, $baseUrl);
        exit;
    }

    // Insert message into conversation stream
    $pdo->prepare("
        INSERT INTO `messages` (`company_id`, `conversation_id`, `sender_type`, `message_text`, `channel`, `created_at`)
        VALUES (?, ?, 'agent', ?, 'widget', NOW())
    ")->execute([$companyId, $convId, $message]);

    // Update conversation state to human_active
    $pdo->prepare("
        UPDATE `conversations`
        SET `status` = 'human_active',
            `ownership` = 'human',
            `last_message_preview` = ?,
            `last_message_at` = NOW()
        WHERE id = ? AND company_id = ?
    ")->execute([substr($message, 0, 150), $convId, $companyId]);

    header("Content-Type: text/html; charset=UTF-8");
    $previewEsc = htmlspecialchars($message);
    echo renderQuickActionFeedback(
        "Reply Sent to Visitor!",
        "Your reply has been instantly delivered to <strong>{$custName}</strong> on the website widget.<br><br><strong>Message Sent:</strong><br><div style='text-align:left;background:#f8fafc;padding:12px 14px;border-radius:6px;border:1px solid #e2e8f0;margin-top:8px;font-size:13px;color:#334155;'>\"{$previewEsc}\"</div>",
        "success",
        $convId,
        $baseUrl
    );
    exit;
}

// -------------------------------------------------------------
// DEFAULT: View Reply Composer Interface (Mobile & Desktop Friendly)
// -------------------------------------------------------------
$mStmt = $pdo->prepare("
    SELECT sender_type, message_text, created_at
    FROM `messages`
    WHERE conversation_id = ? AND company_id = ?
    ORDER BY id DESC
    LIMIT 6
");
$mStmt->execute([$convId, $companyId]);
$recentMessages = array_reverse($mStmt->fetchAll(PDO::FETCH_ASSOC));

$messagesHtml = '';
foreach ($recentMessages as $m) {
    $isUser = ($m['sender_type'] === 'visitor' || $m['sender_type'] === 'user');
    $isAgent = in_array($m['sender_type'], ['agent', 'human', 'support_agent'], true);
    $badge = $isUser ? 'Client' : ($isAgent ? 'Support' : 'AI Assistant');
    $bg = $isUser ? '#ffffff' : ($isAgent ? '#eff6ff' : '#f8fafc');
    $border = $isUser ? '#e2e8f0' : ($isAgent ? '#bfdbfe' : '#e2e8f0');
    $time = date('g:i A', strtotime($m['created_at']));
    $text = htmlspecialchars($m['message_text']);

    $messagesHtml .= <<<HTML
<div style="background: {$bg}; border: 1px solid {$border}; border-radius: 8px; padding: 10px 12px; margin-bottom: 8px; font-size: 13px;">
  <div style="display: flex; justify-content: space-between; margin-bottom: 4px; font-size: 11px; color: #64748b; font-weight: 600;">
    <span>{$badge}</span>
    <span>{$time}</span>
  </div>
  <div style="color: #1e293b; line-height: 1.5; white-space: pre-wrap;">{$text}</div>
</div>
HTML;
}

$closeUrl   = "{$baseUrl}/api/quick_action.php?token={$token}&action=close";
$resolveUrl = "{$baseUrl}/api/quick_action.php?token={$token}&action=resolve";
$dashUrl    = "{$baseUrl}/app/conversations.html?id={$convId}";

header("Content-Type: text/html; charset=UTF-8");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Quick Reply to #CONV-<?= $convId ?> — <?= htmlspecialchars($conv['company_name']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body {
      font-family: 'Inter', -apple-system, sans-serif;
      background: #f1f5f9;
      margin: 0;
      padding: 20px 16px;
      color: #0f172a;
      display: flex;
      justify-content: center;
    }
    .wrapper {
      max-width: 600px;
      width: 100%;
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 24px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.04);
    }
    .header {
      border-bottom: 1px solid #e2e8f0;
      padding-bottom: 16px;
      margin-bottom: 18px;
    }
    .tag {
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      background: #fef3c7;
      color: #b45309;
      padding: 3px 8px;
      border-radius: 4px;
      display: inline-block;
      margin-bottom: 6px;
    }
    h2 {
      margin: 4px 0;
      font-size: 18px;
      font-weight: 700;
      color: #0f172a;
    }
    .sub {
      font-size: 12.5px;
      color: #64748b;
      margin: 0;
    }
    .history {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      padding: 12px;
      max-height: 240px;
      overflow-y: auto;
      margin-bottom: 20px;
    }
    textarea {
      width: 100%;
      box-sizing: border-box;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      padding: 12px;
      font-size: 13.5px;
      font-family: inherit;
      min-height: 110px;
      outline: none;
      resize: vertical;
      line-height: 1.5;
    }
    textarea:focus {
      border-color: #0f172a;
      box-shadow: 0 0 0 2px rgba(15,23,42,0.06);
    }
    .actions {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      margin-top: 14px;
      flex-wrap: wrap;
    }
    .btn-send {
      background: #0f172a;
      color: #ffffff;
      border: none;
      padding: 12px 26px;
      border-radius: 6px;
      font-size: 13.5px;
      font-weight: 600;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .btn-send:hover { background: #000000; }
    .remote-tools {
      display: flex;
      gap: 8px;
      align-items: center;
      border-top: 1px solid #e2e8f0;
      padding-top: 18px;
      margin-top: 22px;
      justify-content: space-between;
    }
    .btn-tool {
      text-decoration: none;
      font-size: 12px;
      font-weight: 600;
      padding: 7px 14px;
      border-radius: 5px;
      display: inline-block;
    }
    .btn-resolve { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
    .btn-close { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
    .btn-dash { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <span class="tag">Email Remote Response</span>
      <h2>Reply to <?= $custName ?> (#CONV-<?= $convId ?>)</h2>
      <p class="sub">Typing below sends an instant reply directly to the visitor's live website chat widget.</p>
    </div>

    <div style="font-size: 12px; font-weight: 700; color: #64748b; margin-bottom: 6px; text-transform: uppercase;">Recent Conversation:</div>
    <div class="history">
      <?= $messagesHtml ?: '<div style="font-size:12px;color:#94a3b8;text-align:center;padding:16px;">No previous messages yet.</div>' ?>
    </div>

    <form method="POST" action="<?= $baseUrl ?>/api/quick_action.php?token=<?= htmlspecialchars($token) ?>&action=reply">
      <label style="font-size: 12.5px; font-weight: 600; color: #1e293b; display: block; margin-bottom: 6px;">Your Reply to Visitor:</label>
      <textarea name="message" required placeholder="Type your response here... (Visitor will see this immediately on their screen)"></textarea>
      
      <div class="actions">
        <button type="submit" class="btn-send">Send Reply to Widget &rarr;</button>
        <span style="font-size: 11.5px; color: #94a3b8;">No login needed</span>
      </div>
    </form>

    <div class="remote-tools">
      <div style="font-size: 12px; color: #64748b; font-weight: 600;">Status Actions:</div>
      <div style="display: flex; gap: 8px;">
        <a href="<?= $resolveUrl ?>" onclick="return confirm('Mark this conversation as Resolved?')" class="btn-tool btn-resolve">✓ Mark Resolved</a>
        <a href="<?= $closeUrl ?>" onclick="return confirm('Close this conversation completely?')" class="btn-tool btn-close">✗ Close Chat</a>
        <a href="<?= $dashUrl ?>" class="btn-tool btn-dash">Open Dashboard</a>
      </div>
    </div>
  </div>
</body>
</html>
