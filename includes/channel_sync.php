<?php
/**
 * CUBOIDPILOT — REAL-TIME CHANNEL & CRM DISPATCH PIPELINE
 * Synchronizes qualified leads, contact credentials, and AI summaries
 * to external platforms (Google Sheets, TeleCRM, HubSpot, Outbound Webhooks).
 */

require_once __DIR__ . '/../config/db.php';

class ChannelSync {

    /**
     * Dispatch a newly created or qualified lead to all active channels
     */
    public static function dispatchLead(PDO $pdo, int $companyId, int $leadId, array $extraContext = []): array {
        if ($companyId <= 0 || $leadId <= 0) {
            return ['success' => false, 'error' => 'Invalid company or lead ID'];
        }

        // 1. Fetch Lead & Customer Data
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    l.id AS lead_id,
                    l.company_id,
                    l.customer_id,
                    l.conversation_id,
                    l.title,
                    l.stage_name,
                    l.priority,
                    l.intent_level,
                    l.opportunity_value,
                    l.source,
                    l.ai_summary,
                    l.radar_reason,
                    l.created_at,
                    l.updated_at,
                    c.name AS customer_name,
                    c.phone AS customer_phone,
                    c.email AS customer_email,
                    c.city AS customer_city,
                    c.company_name AS customer_company
                FROM `leads` l
                LEFT JOIN `customers` c ON l.customer_id = c.id
                WHERE l.id = ? AND l.company_id = ?
                LIMIT 1
            ");
            $stmt->execute([$leadId, $companyId]);
            $lead = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$lead) {
                return ['success' => false, 'error' => 'Lead not found'];
            }
        } catch (Throwable $e) {
            error_log("[ChannelSync] Lead fetch error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }

        // 2. Fetch Active Integrations for this Company
        try {
            $intStmt = $pdo->prepare("
                SELECT id, channel_key, provider_name, credentials, settings 
                FROM `company_integrations` 
                WHERE company_id = ? AND is_active = 1
            ");
            $intStmt->execute([$companyId]);
            $integrations = $intStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("[ChannelSync] Integrations query error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }

        if (empty($integrations)) {
            return ['success' => true, 'dispatched' => 0, 'message' => 'No active channels configured'];
        }

        $results = [];

        foreach ($integrations as $integration) {
            $channelKey = $integration['channel_key'];
            $intId = (int)$integration['id'];

            $creds = [];
            if (!empty($integration['credentials'])) {
                $dec = decryptSecret($integration['credentials']);
                $creds = json_decode($dec, true) ?: [];
            }

            $settings = [];
            if (!empty($integration['settings'])) {
                $settings = json_decode($integration['settings'], true) ?: [];
            }

            $channelResult = ['channel' => $channelKey, 'success' => false];

            switch ($channelKey) {
                case 'google_sheets':
                    $webhookUrl = trim($creds['webhook_url'] ?? '');
                    $sheetName  = trim($creds['sheet_name'] ?? 'Leads');
                    $sheetId    = trim($creds['sheet_id'] ?? '');

                    if (!empty($webhookUrl) && filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
                        $channelResult = self::sendToGoogleSheets($webhookUrl, $sheetName, $sheetId, $lead, $extraContext);
                        self::recordSyncStatus($pdo, $intId, $channelResult['success'], $channelResult['error'] ?? null);
                    } else {
                        $channelResult['error'] = 'Invalid or empty Apps Script Webhook URL';
                    }
                    break;

                case 'webhooks':
                    $webhookUrl = trim($creds['webhook_url'] ?? '');
                    $secretKey  = trim($creds['secret_key'] ?? '');

                    if (!empty($webhookUrl) && filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
                        $channelResult = self::sendToGenericWebhook($webhookUrl, $secretKey, $lead, $extraContext);
                        self::recordSyncStatus($pdo, $intId, $channelResult['success'], $channelResult['error'] ?? null);
                    }
                    break;

                case 'telecrm':
                    $apiKey   = trim($creds['api_key'] ?? '');
                    $endpoint = trim($creds['endpoint'] ?? 'https://api.telecrm.in/enterprise/v1/lead');
                    $leadTag  = trim($creds['lead_tag'] ?? 'Cai AI Lead');

                    if (!empty($apiKey)) {
                        $channelResult = self::sendToTeleCRM($endpoint, $apiKey, $leadTag, $lead, $extraContext);
                        self::recordSyncStatus($pdo, $intId, $channelResult['success'], $channelResult['error'] ?? null);
                    }
                    break;

                case 'hubspot':
                    $token = trim($creds['access_token'] ?? '');
                    if (!empty($token)) {
                        $channelResult = self::sendToHubspot($token, $lead, $extraContext);
                        self::recordSyncStatus($pdo, $intId, $channelResult['success'], $channelResult['error'] ?? null);
                    }
                    break;
            }

            $results[$channelKey] = $channelResult;
        }

        return ['success' => true, 'dispatched' => count($results), 'results' => $results];
    }

    /**
     * Send lead payload to Google Sheets via Google Apps Script Web App
     */
    public static function sendToGoogleSheets(string $webhookUrl, string $sheetName, string $sheetId, array $lead, array $extraContext = []): array {
        $startTime = microtime(true);

        $name = !empty($lead['customer_name']) ? $lead['customer_name'] : ($lead['title'] ?? 'New Prospect');
        $phone = $lead['customer_phone'] ?? ($extraContext['phone'] ?? '');
        $email = $lead['customer_email'] ?? ($extraContext['email'] ?? '');
        $summary = $lead['ai_summary'] ?? ($lead['radar_reason'] ?? '');
        $intent = $lead['intent_level'] ?? ($extraContext['intent'] ?? 'General Inquiry');
        $value = !empty($lead['opportunity_value']) ? '₹' . number_format($lead['opportunity_value']) : '';
        $source = $lead['source'] ?? ($extraContext['source'] ?? 'Website Widget');

        $leadFields = [
            'timestamp'          => date('d-m-Y H:i:s'),
            'name'               => $name,
            'customer_name'      => $name,
            'phone'              => $phone,
            'email'              => $email,
            'intent'             => $intent,
            'interested_service' => $intent,
            'budget'             => $value,
            'opportunity_value'  => $lead['opportunity_value'] ?? 0,
            'summary'            => $summary,
            'ai_summary'         => $summary,
            'stage'              => $lead['stage_name'] ?? 'New Inquiry',
            'priority'           => $lead['priority'] ?? 'MEDIUM',
            'source'             => $source,
            'channel'            => $source,
            'lead_id'            => $lead['lead_id'] ?? 0,
            'sheet_name'         => $sheetName ?: 'Leads',
            'sheet_id'           => $sheetId ?: ''
        ];

        // Package with both direct keys and nested data key for universal compatibility with Apps Script
        $payload = array_merge($leadFields, [
            'event'      => 'lead.captured',
            'data'       => $leadFields
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $webhookUrl,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json; charset=utf-8',
                'Accept: application/json, text/plain, */*'
            ],
            CURLOPT_RETURNTRANSFER => true,
            // CRITICAL: Google Apps Script Web Apps always 302 redirect to script.googleusercontent.com
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        $latency = (int)round((microtime(true) - $startTime) * 1000);

        if ($curlErr) {
            return [
                'success'    => false,
                'latency_ms' => $latency,
                'error'      => "Google Sheets cURL error: {$curlErr}"
            ];
        }

        // Google Apps Script usually returns 200 after redirect
        if ($httpCode >= 200 && $httpCode < 400) {
            return [
                'success'    => true,
                'latency_ms' => $latency,
                'http_code'  => $httpCode,
                'response'   => $response,
                'message'    => "Successfully synced lead to Google Sheet '{$sheetName}' ({$latency}ms)"
            ];
        }

        return [
            'success'    => false,
            'latency_ms' => $latency,
            'http_code'  => $httpCode,
            'error'      => "Google Apps Script returned HTTP {$httpCode}"
        ];
    }

    /**
     * Real connection test for Google Sheets (appends a verified handshake row)
     */
    public static function testGoogleSheets(string $webhookUrl, string $sheetName = 'Leads', string $sheetId = ''): array {
        if (empty($webhookUrl) || !filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'error' => 'A valid Google Apps Script Web App URL is required.'];
        }

        $testLead = [
            'lead_id'           => 0,
            'customer_name'     => 'Test Lead (CuboidPilot Handshake)',
            'customer_phone'    => '+91 99999 99999',
            'customer_email'    => 'test.lead@cai.cuboidsoft.in',
            'title'             => 'Test Lead (CuboidPilot Handshake)',
            'intent_level'      => 'Connection Handshake',
            'opportunity_value' => 50000,
            'stage_name'        => 'Verification Test',
            'priority'          => 'HIGH',
            'ai_summary'        => 'Automated test handshake from Cai Multi-Channel Hub. Your spreadsheet is successfully connected and receiving live leads!',
            'radar_reason'      => 'Connection Test',
            'source'            => 'CuboidPilot Test Handshake'
        ];

        $res = self::sendToGoogleSheets($webhookUrl, $sheetName, $sheetId, $testLead, ['source' => 'Test Handshake']);

        if ($res['success']) {
            $res['message'] = "Handshake verified! Test row streamed to Google Sheet '{$sheetName}' ({$res['latency_ms']}ms, HTTP 200).";
        }
        return $res;
    }

    /**
     * Send lead to Outbound Webhook with HMAC-SHA256 signature
     */
    public static function sendToGenericWebhook(string $webhookUrl, string $secretKey, array $lead, array $extraContext = []): array {
        $startTime = microtime(true);
        $payload = [
            'event'      => 'lead.captured',
            'timestamp'  => time(),
            'lead'       => $lead,
            'context'    => $extraContext
        ];

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $headers = [
            'Content-Type: application/json',
            'User-Agent: CuboidPilot-Webhook-Dispatcher/2.0'
        ];

        if (!empty($secretKey)) {
            $sig = hash_hmac('sha256', $jsonPayload, $secretKey);
            $headers[] = "X-Cuboid-Signature: sha256={$sig}";
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $webhookUrl,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        $latency = (int)round((microtime(true) - $startTime) * 1000);

        if ($curlErr) {
            return ['success' => false, 'error' => "Webhook error: {$curlErr}"];
        }

        return [
            'success'   => ($httpCode >= 200 && $httpCode < 300),
            'http_code' => $httpCode,
            'latency_ms' => $latency
        ];
    }

    /**
     * Send lead to TeleCRM API
     */
    public static function sendToTeleCRM(string $endpoint, string $apiKey, string $leadTag, array $lead, array $extraContext = []): array {
        $startTime = microtime(true);

        $payload = [
            'fields' => [
                'name'  => $lead['customer_name'] ?? $lead['title'],
                'phone' => $lead['customer_phone'] ?? '',
                'email' => $lead['customer_email'] ?? ''
            ],
            'tags' => [$leadTag, $lead['intent_level'] ?? 'Inquiry'],
            'note' => $lead['ai_summary'] ?? 'Lead from CuboidPilot Cai'
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $endpoint,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "Authorization: Bearer {$apiKey}"
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        $latency = (int)round((microtime(true) - $startTime) * 1000);

        return [
            'success'   => ($httpCode >= 200 && $httpCode < 300),
            'http_code' => $httpCode,
            'latency_ms' => $latency,
            'error'     => $curlErr ?: ($httpCode >= 300 ? "TeleCRM HTTP {$httpCode}" : null)
        ];
    }

    /**
     * Send contact to HubSpot CRM
     */
    public static function sendToHubspot(string $token, array $lead, array $extraContext = []): array {
        $startTime = microtime(true);

        $nameParts = explode(' ', trim($lead['customer_name'] ?? $lead['title'] ?? 'Prospect'), 2);
        $firstName = $nameParts[0];
        $lastName  = $nameParts[1] ?? '';

        $payload = [
            'properties' => [
                'firstname' => $firstName,
                'lastname'  => $lastName,
                'phone'     => $lead['customer_phone'] ?? '',
                'email'     => $lead['customer_email'] ?? '',
                'notes'     => $lead['ai_summary'] ?? ''
            ]
        ];

        $ch = curl_init('https://api.hubapi.com/crm/v3/objects/contacts');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "Authorization: Bearer {$token}"
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        $latency = (int)round((microtime(true) - $startTime) * 1000);

        return [
            'success'   => ($httpCode >= 200 && $httpCode < 300),
            'http_code' => $httpCode,
            'latency_ms' => $latency,
            'error'     => $curlErr ?: ($httpCode >= 300 ? "HubSpot HTTP {$httpCode}" : null)
        ];
    }

    /**
     * Record sync status in company_integrations
     */
    private static function recordSyncStatus(PDO $pdo, int $integrationId, bool $success, ?string $errorMessage = null): void {
        try {
            $stmt = $pdo->prepare("
                UPDATE `company_integrations`
                SET `last_synced_at` = NOW(),
                    `error_message` = ?
                WHERE id = ?
            ");
            $stmt->execute([$success ? null : substr((string)$errorMessage, 0, 250), $integrationId]);
        } catch (Throwable $e) {
            error_log("[ChannelSync] Status update error: " . $e->getMessage());
        }
    }
}
