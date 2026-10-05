<?php
/**
 * CUBOIDPILOT — SALESPERSON SMART ALERTS ENGINE
 * Generates and routes high-intent, human-required, assignment, and payment alerts.
 * Enforces multi-tenant isolation, 60-minute anti-spam cooldowns, and entitlement gating.
 */

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/entitlements.php';
require_once __DIR__ . '/../includes/mailer.php';

$pdo = getDbConnection();

/**
 * Function callable from scoring_engine, leads, events, etc.
 */
function sendSalespersonAssignmentAlert($pdo, $companyId, $leadId, $assignee = null, $alertType = 'LEAD_ASSIGNED') {
    try {
        $lStmt = $pdo->prepare("
            SELECT l.*, c.name as customer_name, c.phone as customer_phone, c.email as customer_email,
                   u.name as assigned_name, u.phone as assigned_phone, u.email as assigned_email,
                   comp.name as company_name
            FROM `leads` l
            LEFT JOIN `customers` c ON c.id = l.customer_id
            LEFT JOIN `users` u ON u.id = l.assigned_user_id
            LEFT JOIN `companies` comp ON comp.id = l.company_id
            WHERE l.id = ? AND l.company_id = ?
            LIMIT 1
        ");
        $lStmt->execute([$leadId, $companyId]);
        $lead = $lStmt->fetch(PDO::FETCH_ASSOC);

        if (!$lead) {
            return ['success' => false, 'error' => 'Lead not found in this workspace'];
        }

        $recipientPhone = $assignee['phone'] ?? $lead['assigned_phone'] ?? '';
        if (empty($recipientPhone)) {
            $uStmt = $pdo->prepare("SELECT phone FROM `users` WHERE `company_id` = ? AND `role` IN ('owner', 'admin', 'manager') AND `phone` IS NOT NULL AND `phone` != '' ORDER BY id ASC LIMIT 1");
            $uStmt->execute([$companyId]);
            $uRow = $uStmt->fetch();
            $recipientPhone = $uRow ? $uRow['phone'] : '+919820011111';
        }

        $custName  = $lead['customer_name'] ?: ($lead['title'] ?: 'Prospect');
        $custPhone = $lead['customer_phone'] ?: 'No phone provided';
        $dealVal   = !empty($lead['opportunity_value']) ? "₹" . number_format($lead['opportunity_value']) : "TBD";
        $summary   = $lead['ai_summary'] ?: 'High commercial intent indicated in chat session.';
        $action    = $lead['radar_recommended_action'] ?: 'Follow up promptly with prospective student.';

        // Anti-spam cooldown: don't dispatch same alert type for same lead within 15 minutes
        $cdStmt = $pdo->prepare("SELECT id FROM `alert_logs` WHERE `lead_id` = ? AND `alert_type` = ? AND `sent_at` >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) LIMIT 1");
        $cdStmt->execute([$leadId, $alertType]);
        if ($cdStmt->fetch()) {
            return [
                'success'      => true,
                'alert_status' => 'cooldown_skipped',
                'lead_id'      => $leadId,
                'recipient'    => $recipientPhone,
                'message'      => 'Alert skipped due to 15-minute anti-spam cooldown'
            ];
        }

        $headline = match ($alertType) {
            'LEAD_ASSIGNED'         => "🎯 NEW LEAD ASSIGNED TO YOU",
            'HIGH_INTENT_DETECTED'  => "🔥 HIGH-INTENT PROSPECT ACTIVE",
            'HIGH_INTENT_DROPPED'   => "⚠️ HIGH-INTENT LEAD DROPPED OFF",
            'HUMAN_REQUIRED'        => "🚨 PROSPECT REQUESTS HUMAN COUNSELOR",
            'PAYMENT_DUE'           => "💳 UPCOMING EMI INSTALLMENT DUE",
            'PAYMENT_OVERDUE'       => "⛔ OVERDUE INSTALLMENT NOTICE",
            default                 => "📢 CUBOIDPILOT LEAD ALERT"
        };

        $alertBody = "{$headline}\n"
            . "Company: {$lead['company_name']}\n"
            . "Prospect: {$custName} ({$custPhone})\n"
            . "Deal Value: {$dealVal} • Priority: {$lead['priority']}\n\n"
            . "Executive Context:\n{$summary}\n\n"
            . "Recommended Action:\n{$action}\n"
            . "View Lead: https://cuboidpilot.app/app/lead-detail.html?id={$leadId}";

        // Check if Meta WhatsApp Cloud API credentials exist for direct delivery
        $waStmt = $pdo->prepare("SELECT phone_number_id, whatsapp_access_token FROM `whatsapp_accounts` WHERE `company_id` = ? AND `status` = 'connected' LIMIT 1");
        $waStmt->execute([$companyId]);
        $waAcc = $waStmt->fetch();

        $dispatchStatus = 'queued';
        if ($waAcc && !empty($waAcc['phone_number_id']) && !empty($waAcc['whatsapp_access_token'])) {
            try {
                $cleanRecipient = preg_replace('/[^0-9]/', '', $recipientPhone);
                $endpoint = "https://graph.facebook.com/v20.0/{$waAcc['phone_number_id']}/messages";
                $payload = [
                    'messaging_product' => 'whatsapp',
                    'to'                => $cleanRecipient,
                    'type'              => 'text',
                    'text'              => ['body' => $alertBody]
                ];
                $ch = curl_init($endpoint);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode($payload),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $waAcc['whatsapp_access_token']
                    ],
                    CURLOPT_TIMEOUT        => 8
                ]);
                $cloudResponse = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $dispatchStatus = ($httpCode >= 200 && $httpCode < 300) ? 'sent' : 'failed';
            } catch (Exception $e) {
                $dispatchStatus = 'failed';
            }
        }

        // Record in alert_logs
        $pdo->prepare("
            INSERT INTO `alert_logs` (`company_id`, `lead_id`, `alert_type`, `recipient`, `sent_at`)
            VALUES (?, ?, ?, ?, NOW())
        ")->execute([$companyId, $leadId, $alertType, $recipientPhone]);

        // Record in whatsapp_messages as outgoing alert
        $pdo->prepare("
            INSERT INTO `whatsapp_messages` 
            (`company_id`, `recipient_phone`, `recipient_name`, `message_type`, `content`, `status`, `metadata_json`, `created_at`)
            VALUES (?, ?, ?, 'human_alert', ?, ?, ?, NOW())
        ")->execute([
            $companyId,
            $recipientPhone,
            $assignee['name'] ?? $lead['assigned_name'] ?? 'Sales Closer',
            $alertBody,
            $dispatchStatus,
            json_encode([
                'alert_type' => $alertType,
                'lead_id'    => $leadId,
                'deal_value' => $lead['opportunity_value']
            ])
        ]);

        // 2. Dispatch Automated Email Notification if Company SMTP is configured
        try {
            $adminEmail = $assignee['email'] ?? $lead['assigned_email'] ?? '';
            $adminName  = $assignee['name'] ?? $lead['assigned_name'] ?? 'Team Member';
            if (empty($adminEmail)) {
                $uStmt = $pdo->prepare("SELECT email, name FROM `users` WHERE `company_id` = ? AND `role` IN ('owner', 'admin') AND `is_active` = 1 ORDER BY FIELD(role, 'owner', 'admin'), id ASC LIMIT 1");
                $uStmt->execute([$companyId]);
                $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                if ($uRow) {
                    $adminEmail = $uRow['email'] ?? '';
                    $adminName  = $uRow['name'] ?? 'Workspace Admin';
                }
            }

            if (!empty($adminEmail) && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                $emailCfg = CompanyMailer::getCompanyConfig($pdo, $companyId);
                if ($emailCfg && !empty($emailCfg['smtp_host'])) {
                    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $leadUrl = "{$scheme}://{$host}/app/lead-detail.html?id={$leadId}";
                    $brand = htmlspecialchars($lead['company_name'] ?? 'CuboidPilot');
                    $escCustName = htmlspecialchars($custName);
                    $escCustPhone = htmlspecialchars($custPhone);
                    $escCustEmail = htmlspecialchars($lead['customer_email'] ?: 'Not provided');
                    $escSummary = nl2br(htmlspecialchars($summary));
                    $escAction = htmlspecialchars($action);

                    $emailSubject = "🎯 New Lead Alert: {$custName} ({$lead['priority']}) — {$brand}";
                    $emailHtml = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>{$emailSubject}</title></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background-color: #f7f6f2; color: #1c1917; margin: 0; padding: 30px 15px;">
  <div style="max-width: 560px; margin: 0 auto; background: #ffffff; border: 1px solid #e7e5de; border-radius: 8px; padding: 32px; box-shadow: 0 4px 12px rgba(0,0,0,0.03);">
    <div style="padding-bottom: 16px; border-bottom: 1px solid #e7e5de; margin-bottom: 20px;">
      <span style="font-size: 11px; font-weight: 700; color: #059669; text-transform: uppercase; letter-spacing: 0.05em; background: #ecfdf5; padding: 3px 8px; border-radius: 4px;">{$headline}</span>
      <h2 style="font-size: 18px; margin: 12px 0 4px 0; color: #1c1917;">{$brand} — Lead Captured</h2>
    </div>
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 13px;">
      <tr>
        <td style="padding: 6px 0; color: #78716c; width: 35%;">Prospect Name:</td>
        <td style="padding: 6px 0; font-weight: 600; color: #1c1917;">{$escCustName}</td>
      </tr>
      <tr>
        <td style="padding: 6px 0; color: #78716c;">Phone:</td>
        <td style="padding: 6px 0; font-weight: 600; color: #1c1917;">{$escCustPhone}</td>
      </tr>
      <tr>
        <td style="padding: 6px 0; color: #78716c;">Email:</td>
        <td style="padding: 6px 0; font-weight: 600; color: #1c1917;">{$escCustEmail}</td>
      </tr>
      <tr>
        <td style="padding: 6px 0; color: #78716c;">Priority / Value:</td>
        <td style="padding: 6px 0; font-weight: 600; color: #0284c7;">{$lead['priority']} • {$dealVal}</td>
      </tr>
    </table>
    <div style="background: #fbfaf8; border: 1px solid #f0eee8; border-radius: 6px; padding: 14px; margin-bottom: 20px;">
      <div style="font-size: 11px; font-weight: 600; color: #78716c; text-transform: uppercase; margin-bottom: 6px;">AI Conversation Summary:</div>
      <div style="font-size: 13px; line-height: 1.5; color: #292524;">{$escSummary}</div>
    </div>
    <div style="text-align: center; margin-top: 24px;">
      <a href="{$leadUrl}" style="background-color: #1c1917; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-size: 13px; font-weight: 600; display: inline-block;">Open Lead in CRM &rarr;</a>
    </div>
  </div>
</body>
</html>
HTML;
                    CompanyMailer::send($pdo, $companyId, $adminEmail, $emailSubject, $emailHtml, $alertBody);
                }
            }
        } catch (Throwable $mailEx) {
            error_log("[Alerts] Email notification failed: " . $mailEx->getMessage());
        }

        return [
            'success'       => true,
            'alert_status'  => $dispatchStatus,
            'alert_type'    => $alertType,
            'lead_id'       => $leadId,
            'recipient'     => $recipientPhone,
            'alert_preview' => $alertBody
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Only execute as HTTP endpoint when requested directly
if (php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'alerts.php') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true) ?? $_POST;

    $companyKey = trim($data['company_key'] ?? $_SERVER['HTTP_X_COMPANY_KEY'] ?? '');
    $companyId  = (int)($data['company_id'] ?? 0);
    $leadId     = (int)($data['lead_id'] ?? 0);
    $alertType  = strtoupper(trim($data['alert_type'] ?? 'HIGH_INTENT_DETECTED'));
    $recipientPhone = trim($data['recipient_phone'] ?? '');

    if (!$companyId && !empty($companyKey)) {
        $cStmt = $pdo->prepare("SELECT id FROM `companies` WHERE `company_key` = ? OR `slug` = ? LIMIT 1");
        $cStmt->execute([$companyKey, $companyKey]);
        $c = $cStmt->fetch();
        if ($c) $companyId = (int)$c['id'];
    }

    if (!$companyId) {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $companyId = (int)($_SESSION['company_id'] ?? 0);
    }

    if (!$companyId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Company identification is required']);
        exit;
    }

    if (!checkEntitlement($pdo, $companyId, 'can_receive_whatsapp_alerts')) {
        echo json_encode([
            'success' => false,
            'skipped' => true,
            'reason'  => 'Plan does not include WhatsApp sales alerts'
        ]);
        exit;
    }

    $res = sendSalespersonAssignmentAlert($pdo, $companyId, $leadId, ['phone' => $recipientPhone], $alertType);
    echo json_encode($res);
    exit;
}
