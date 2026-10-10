<?php
/**
 * CUBOIDPILOT / CAI — WHATSAPP AUTOMATION & META TEMPLATES SERVICE
 * Multi-tenant backend powering Task 3:
 * - 4-Phase Custom Automation Engine (Greeting, Information Delivery, Follow-up, Lead Handoff)
 * - Number-based menus and keyword matching
 * - Document & link dispatch
 * - Dynamic inactivity follow-up queuing & instant cancel-on-reply
 * - High-intent qualification & team WhatsApp bridge notification
 * - Live Meta Graph API Message Template sync & draft submission
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/whatsapp_bridge.php';

class WhatsAppAutomationService {

    /**
     * Self-healing migration: Ensures all 4 tables exist and Company 3 has starter defaults.
     */
    public static function ensureTables(PDO $pdo): void {
        static $checked = false;
        if ($checked) return;
        $checked = true;

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `whatsapp_automation_settings` (
                  `company_id` INT NOT NULL,
                  `greeting_message` TEXT NULL,
                  `prefilled_template` VARCHAR(255) NULL DEFAULT 'Hi, I was chatting on your website (Ref: {{ref}}). I would like more information.',
                  `follow_up_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                  `follow_up_delay_seconds` INT NOT NULL DEFAULT 60,
                  `follow_up_message` TEXT NULL,
                  `follow_up_options_json` TEXT NULL,
                  `max_followups` INT NOT NULL DEFAULT 1,
                  `high_intent_keywords` TEXT NULL,
                  `notify_user_id` INT NULL,
                  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`company_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `whatsapp_automation_rules` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `company_id` INT NOT NULL,
                  `phase` ENUM('greeting', 'info_delivery', 'follow_up', 'handoff') NOT NULL DEFAULT 'info_delivery',
                  `trigger_type` ENUM('keyword', 'menu_number', 'intent', 'default') NOT NULL DEFAULT 'keyword',
                  `trigger_value` VARCHAR(255) NOT NULL,
                  `response_text` TEXT NOT NULL,
                  `attachment_type` ENUM('none', 'document', 'image', 'link') NOT NULL DEFAULT 'none',
                  `attachment_url` TEXT NULL,
                  `attachment_name` VARCHAR(255) NULL,
                  `next_options_json` TEXT NULL,
                  `is_high_intent` TINYINT(1) NOT NULL DEFAULT 0,
                  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                  `sort_order` INT NOT NULL DEFAULT 0,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  KEY `idx_company_phase` (`company_id`, `phase`),
                  KEY `idx_company_active` (`company_id`, `is_active`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `whatsapp_followup_queue` (
                  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                  `company_id` INT NOT NULL,
                  `conversation_id` INT NOT NULL,
                  `session_id` VARCHAR(64) NULL,
                  `recipient_phone` VARCHAR(30) NOT NULL,
                  `scheduled_at` DATETIME NOT NULL,
                  `status` ENUM('pending', 'sent', 'cancelled') NOT NULL DEFAULT 'pending',
                  `followup_count` INT NOT NULL DEFAULT 0,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `sent_at` DATETIME NULL,
                  KEY `idx_company_status` (`company_id`, `status`),
                  KEY `idx_scheduled` (`scheduled_at`, `status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `whatsapp_templates_cache` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `company_id` INT NOT NULL,
                  `meta_template_id` VARCHAR(64) NOT NULL,
                  `name` VARCHAR(128) NOT NULL,
                  `category` VARCHAR(64) NOT NULL DEFAULT 'UTILITY',
                  `language` VARCHAR(32) NOT NULL DEFAULT 'en_US',
                  `status` ENUM('APPROVED', 'PENDING', 'REJECTED') NOT NULL DEFAULT 'APPROVED',
                  `components_json` LONGTEXT NULL,
                  `last_synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  UNIQUE KEY `idx_company_tpl` (`company_id`, `name`, `language`),
                  KEY `idx_company_status` (`company_id`, `status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        } catch (Throwable $e) {
            error_log("[WhatsAppAutomationService] Table ensure error: " . $e->getMessage());
        }
    }

    /**
     * Retrieve full automation configuration for a company.
     */
    public static function getConfig(PDO $pdo, int $companyId): array {
        self::ensureTables($pdo);

        // 1. Settings (Phase 1, 3, 4 general config)
        $stmt = $pdo->prepare("SELECT * FROM `whatsapp_automation_settings` WHERE `company_id` = ? LIMIT 1");
        $stmt->execute([$companyId]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$settings) {
            // Initialize default settings for company
            $defaultGreeting = "Hello {{name}}! 👋 Welcome to our WhatsApp desk. I am Cai, your AI counselor. How can I help you today? Reply with a number or question:\n1️⃣ Pricing & Plans\n2️⃣ Company Brochure\n3️⃣ Speak with a Counselor";
            $defaultPrefill = "Hi, I was chatting on your website (Ref: {{ref}}). I would like more information.";
            $defaultFollowup = "Hi {{name}}, just checking in! Would you like to review our brochure or speak with a counselor?";
            $defaultOptions = json_encode(["1. Pricing & Plans", "2. Company Brochure", "3. Speak with Counselor"], JSON_UNESCAPED_UNICODE);
            $defaultKeywords = "enroll, admission, pricing, price, fees, fee, cost, emi, installment, quote, proposal, book call, counselor, human, talk to expert";

            $ins = $pdo->prepare("
                INSERT INTO `whatsapp_automation_settings`
                (`company_id`, `greeting_message`, `prefilled_template`, `follow_up_enabled`, `follow_up_delay_seconds`, `follow_up_message`, `follow_up_options_json`, `max_followups`, `high_intent_keywords`, `is_active`)
                VALUES (?, ?, ?, 1, 60, ?, ?, 1, ?, 1)
            ");
            $ins->execute([$companyId, $defaultGreeting, $defaultPrefill, $defaultFollowup, $defaultOptions, $defaultKeywords]);

            $stmt->execute([$companyId]);
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Parse options JSON
        if (!empty($settings['follow_up_options_json'])) {
            $settings['follow_up_options'] = json_decode($settings['follow_up_options_json'], true) ?: [];
        } else {
            $settings['follow_up_options'] = [];
        }

        // 2. Rules (Phase 2 Information Delivery)
        $rStmt = $pdo->prepare("
            SELECT id, company_id, phase, trigger_type, trigger_value, response_text,
                   attachment_type, attachment_url, attachment_name, next_options_json,
                   is_high_intent, is_active, sort_order,
                   DATE_FORMAT(updated_at, '%b %e, %Y %H:%i') as updated_formatted
            FROM `whatsapp_automation_rules`
            WHERE `company_id` = ?
            ORDER BY `sort_order` ASC, `id` ASC
        ");
        $rStmt->execute([$companyId]);
        $rules = $rStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rules as &$r) {
            $r['next_options'] = !empty($r['next_options_json']) ? (json_decode($r['next_options_json'], true) ?: []) : [];
            $r['is_high_intent'] = (bool)$r['is_high_intent'];
            $r['is_active'] = (bool)$r['is_active'];
        }

        // 3. Connected WhatsApp Account
        $accStmt = $pdo->prepare("SELECT phone_number_id, waba_id, display_number, status, quality_rating FROM `whatsapp_accounts` WHERE `company_id` = ? LIMIT 1");
        $accStmt->execute([$companyId]);
        $account = $accStmt->fetch(PDO::FETCH_ASSOC) ?: [
            'phone_number_id' => '',
            'waba_id'         => '',
            'display_number'  => 'Not Connected',
            'status'          => 'disconnected',
            'quality_rating'  => 'UNKNOWN'
        ];

        // 4. Team Members list for assignment dropdown
        $uStmt = $pdo->prepare("SELECT id, name, email, phone, role, job_title FROM `users` WHERE `company_id` = ? AND `is_active` = 1 ORDER BY name ASC");
        $uStmt->execute([$companyId]);
        $team = $uStmt->fetchAll(PDO::FETCH_ASSOC);

        // 5. Follow-up Queue Stats
        $qStmt = $pdo->prepare("SELECT COUNT(*) FROM `whatsapp_followup_queue` WHERE `company_id` = ? AND `status` = 'pending'");
        $qStmt->execute([$companyId]);
        $pendingFollowups = (int)$qStmt->fetchColumn();

        return [
            'settings'          => $settings,
            'rules'             => $rules,
            'account'           => $account,
            'team'              => $team,
            'pending_followups' => $pendingFollowups
        ];
    }

    /**
     * Save Phase 1, 3, and 4 Settings.
     */
    public static function saveSettings(PDO $pdo, int $companyId, array $data): bool {
        self::ensureTables($pdo);

        $greeting   = trim($data['greeting_message'] ?? '');
        $prefill    = trim($data['prefilled_template'] ?? '');
        $fuEnabled  = !empty($data['follow_up_enabled']) ? 1 : 0;
        $fuDelay    = max(10, min(86400, (int)($data['follow_up_delay_seconds'] ?? 60)));
        $fuMsg      = trim($data['follow_up_message'] ?? '');
        $maxFu      = max(1, min(5, (int)($data['max_followups'] ?? 1)));
        $keywords   = trim($data['high_intent_keywords'] ?? '');
        $notifyUser = !empty($data['notify_user_id']) ? (int)$data['notify_user_id'] : null;
        $isActive   = isset($data['is_active']) ? (int)$data['is_active'] : 1;

        $optionsJson = null;
        if (!empty($data['follow_up_options'])) {
            $opts = is_array($data['follow_up_options']) ? $data['follow_up_options'] : array_map('trim', explode("\n", (string)$data['follow_up_options']));
            $opts = array_values(array_filter($opts));
            $optionsJson = json_encode($opts, JSON_UNESCAPED_UNICODE);
        }

        $stmt = $pdo->prepare("
            INSERT INTO `whatsapp_automation_settings`
            (`company_id`, `greeting_message`, `prefilled_template`, `follow_up_enabled`, `follow_up_delay_seconds`,
             `follow_up_message`, `follow_up_options_json`, `max_followups`, `high_intent_keywords`, `notify_user_id`, `is_active`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              `greeting_message` = VALUES(`greeting_message`),
              `prefilled_template` = VALUES(`prefilled_template`),
              `follow_up_enabled` = VALUES(`follow_up_enabled`),
              `follow_up_delay_seconds` = VALUES(`follow_up_delay_seconds`),
              `follow_up_message` = VALUES(`follow_up_message`),
              `follow_up_options_json` = VALUES(`follow_up_options_json`),
              `max_followups` = VALUES(`max_followups`),
              `high_intent_keywords` = VALUES(`high_intent_keywords`),
              `notify_user_id` = VALUES(`notify_user_id`),
              `is_active` = VALUES(`is_active`)
        ");

        return $stmt->execute([
            $companyId, $greeting, $prefill, $fuEnabled, $fuDelay,
            $fuMsg, $optionsJson, $maxFu, $keywords, $notifyUser, $isActive
        ]);
    }

    /**
     * Create or update a Phase 2 Information Delivery Rule.
     */
    public static function saveRule(PDO $pdo, int $companyId, array $data): array {
        self::ensureTables($pdo);

        $ruleId     = (int)($data['id'] ?? 0);
        $phase      = in_array($data['phase'] ?? '', ['greeting', 'info_delivery', 'follow_up', 'handoff']) ? $data['phase'] : 'info_delivery';
        $triggerType= in_array($data['trigger_type'] ?? '', ['keyword', 'menu_number', 'intent', 'default']) ? $data['trigger_type'] : 'keyword';
        $triggerVal = trim($data['trigger_value'] ?? '');
        $responseTxt= trim($data['response_text'] ?? '');
        $attType    = in_array($data['attachment_type'] ?? '', ['none', 'document', 'image', 'link']) ? $data['attachment_type'] : 'none';
        $attUrl     = trim($data['attachment_url'] ?? '');
        $attName    = trim($data['attachment_name'] ?? '');
        $isHighIntent = !empty($data['is_high_intent']) ? 1 : 0;
        $isActive   = isset($data['is_active']) ? (int)$data['is_active'] : 1;
        $sortOrder  = (int)($data['sort_order'] ?? 0);

        if (empty($triggerVal)) {
            return ['success' => false, 'error' => 'Trigger keyword or menu selection is required.'];
        }
        if (empty($responseTxt)) {
            return ['success' => false, 'error' => 'Response text is required.'];
        }

        $optionsJson = null;
        if (!empty($data['next_options'])) {
            $opts = is_array($data['next_options']) ? $data['next_options'] : array_map('trim', explode("\n", (string)$data['next_options']));
            $opts = array_values(array_filter($opts));
            $optionsJson = json_encode($opts, JSON_UNESCAPED_UNICODE);
        }

        if ($ruleId > 0) {
            // Update existing rule with tenant isolation
            $stmt = $pdo->prepare("
                UPDATE `whatsapp_automation_rules`
                SET `phase` = ?,
                    `trigger_type` = ?,
                    `trigger_value` = ?,
                    `response_text` = ?,
                    `attachment_type` = ?,
                    `attachment_url` = ?,
                    `attachment_name` = ?,
                    `next_options_json` = ?,
                    `is_high_intent` = ?,
                    `is_active` = ?,
                    `sort_order` = ?
                WHERE `id` = ? AND `company_id` = ?
            ");
            $stmt->execute([
                $phase, $triggerType, $triggerVal, $responseTxt,
                $attType, $attUrl, $attName, $optionsJson,
                $isHighIntent, $isActive, $sortOrder, $ruleId, $companyId
            ]);
            return ['success' => true, 'id' => $ruleId, 'message' => 'Automation rule updated.'];
        } else {
            // Insert new rule
            $stmt = $pdo->prepare("
                INSERT INTO `whatsapp_automation_rules`
                (`company_id`, `phase`, `trigger_type`, `trigger_value`, `response_text`,
                 `attachment_type`, `attachment_url`, `attachment_name`, `next_options_json`,
                 `is_high_intent`, `is_active`, `sort_order`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $companyId, $phase, $triggerType, $triggerVal, $responseTxt,
                $attType, $attUrl, $attName, $optionsJson,
                $isHighIntent, $isActive, $sortOrder
            ]);
            $newId = (int)$pdo->lastInsertId();
            return ['success' => true, 'id' => $newId, 'message' => 'Automation rule created.'];
        }
    }

    /**
     * Delete an automation rule.
     */
    public static function deleteRule(PDO $pdo, int $companyId, int $ruleId): bool {
        self::ensureTables($pdo);
        $stmt = $pdo->prepare("DELETE FROM `whatsapp_automation_rules` WHERE `id` = ? AND `company_id` = ?");
        return $stmt->execute([$ruleId, $companyId]);
    }

    /**
     * Toggle a rule active status.
     */
    public static function toggleRule(PDO $pdo, int $companyId, int $ruleId): bool {
        self::ensureTables($pdo);
        $stmt = $pdo->prepare("UPDATE `whatsapp_automation_rules` SET `is_active` = IF(`is_active` = 1, 0, 1) WHERE `id` = ? AND `company_id` = ?");
        return $stmt->execute([$ruleId, $companyId]);
    }

    /**
     * Match an incoming visitor message against active automation rules.
     * Evaluates number menus first, then exact keyword phrases.
     */
    public static function matchIncomingMessage(PDO $pdo, int $companyId, string $messageText): ?array {
        self::ensureTables($pdo);
        $cleanText = strtolower(trim($messageText));
        if (empty($cleanText)) return null;

        // Fetch all active rules for this company
        $stmt = $pdo->prepare("
            SELECT * FROM `whatsapp_automation_rules`
            WHERE `company_id` = ? AND `is_active` = 1
            ORDER BY `trigger_type` = 'menu_number' DESC, `sort_order` ASC, `id` ASC
        ");
        $stmt->execute([$companyId]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 1. First priority: Exact single number / menu digit match (e.g. "1", "2", "3")
        if (preg_match('/^\s*([0-9]{1,2})\s*$/', $cleanText, $numMatch)) {
            $num = $numMatch[1];
            foreach ($rules as $r) {
                if ($r['trigger_type'] === 'menu_number') {
                    $triggerNumbers = array_map('trim', explode(',', strtolower($r['trigger_value'])));
                    if (in_array($num, $triggerNumbers, true)) {
                        return $r;
                    }
                }
            }
        }

        // 2. Second priority: Keyword / phrase match
        foreach ($rules as $r) {
            $keywords = array_map('trim', explode(',', strtolower($r['trigger_value'])));
            foreach ($keywords as $kw) {
                if (empty($kw)) continue;
                // Word-boundary match or substring if phrase
                $pattern = '/\b' . preg_quote($kw, '/') . '\b/i';
                if (preg_match($pattern, $cleanText) || (strlen($kw) > 3 && strpos($cleanText, $kw) !== false)) {
                    return $r;
                }
            }
        }

        return null;
    }

    /**
     * Schedule an automated inactivity follow-up.
     */
    public static function scheduleFollowUp(
        PDO $pdo,
        int $companyId,
        int $conversationId,
        ?string $sessionId,
        string $recipientPhone
    ): ?int {
        self::ensureTables($pdo);

        // Fetch company follow-up settings
        $stmt = $pdo->prepare("SELECT follow_up_enabled, follow_up_delay_seconds, max_followups, is_active FROM `whatsapp_automation_settings` WHERE `company_id` = ? LIMIT 1");
        $stmt->execute([$companyId]);
        $cfg = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cfg || !$cfg['is_active'] || !$cfg['follow_up_enabled']) {
            return null;
        }

        // Check if conversation is human owned or closed
        $cStmt = $pdo->prepare("SELECT ownership, status FROM `conversations` WHERE id = ? AND company_id = ? LIMIT 1");
        $cStmt->execute([$conversationId, $companyId]);
        $conv = $cStmt->fetch(PDO::FETCH_ASSOC);
        if ($conv && ($conv['ownership'] === 'human' || $conv['status'] === 'closed')) {
            return null; // Do not follow up after human handoff or closure
        }

        // Check max followups sent for this conversation
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM `whatsapp_followup_queue` WHERE `conversation_id` = ? AND `company_id` = ? AND `status` = 'sent'");
        $cntStmt->execute([$conversationId, $companyId]);
        $sentCount = (int)$cntStmt->fetchColumn();

        $maxAllowed = max(1, (int)$cfg['max_followups']);
        if ($sentCount >= $maxAllowed) {
            return null; // Spam prevention limit reached
        }

        // Cancel any previous pending follow-ups for this conversation
        self::cancelFollowUps($pdo, $companyId, $conversationId);

        $delaySec = max(10, (int)$cfg['follow_up_delay_seconds']);
        $cleanPhone = preg_replace('/[^0-9]/', '', $recipientPhone);

        $ins = $pdo->prepare("
            INSERT INTO `whatsapp_followup_queue`
            (`company_id`, `conversation_id`, `session_id`, `recipient_phone`, `scheduled_at`, `status`, `followup_count`, `created_at`)
            VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), 'pending', ?, NOW())
        ");
        $ins->execute([$companyId, $conversationId, $sessionId, $cleanPhone, $delaySec, $sentCount]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Unconditionally cancel pending follow-ups when visitor replies.
     */
    public static function cancelFollowUps(PDO $pdo, int $companyId, int $conversationId): bool {
        self::ensureTables($pdo);
        $stmt = $pdo->prepare("UPDATE `whatsapp_followup_queue` SET `status` = 'cancelled' WHERE `conversation_id` = ? AND `company_id` = ? AND `status` = 'pending'");
        return $stmt->execute([$conversationId, $companyId]);
    }

    /**
     * Background worker: Process all due follow-ups in the queue.
     */
    public static function processPendingFollowUps(PDO $pdo): array {
        self::ensureTables($pdo);

        $qStmt = $pdo->query("
            SELECT q.*, s.follow_up_message, s.follow_up_options_json, c.name as customer_name, comp.name as company_name
            FROM `whatsapp_followup_queue` q
            JOIN `whatsapp_automation_settings` s ON s.company_id = q.company_id
            JOIN `conversations` conv ON conv.id = q.conversation_id
            JOIN `companies` comp ON comp.id = q.company_id
            LEFT JOIN `customers` c ON c.id = conv.customer_id
            WHERE q.scheduled_at <= NOW() AND q.status = 'pending' AND conv.ownership != 'human' AND conv.status != 'closed'
            ORDER BY q.scheduled_at ASC
            LIMIT 25
        ");
        $queue = $qStmt->fetchAll(PDO::FETCH_ASSOC);

        $processed = 0;
        $cancelled = 0;

        foreach ($queue as $item) {
            $qId = (int)$item['id'];
            $compCompanyId = (int)$item['company_id'];
            $convId = (int)$item['conversation_id'];
            $recipient = $item['recipient_phone'];
            $custName = $item['customer_name'] ?: 'there';

            // Check if visitor replied after this follow-up was scheduled
            $repStmt = $pdo->prepare("
                SELECT COUNT(*) FROM `messages` 
                WHERE `conversation_id` = ? AND `company_id` = ? AND `sender_type` = 'visitor' AND `created_at` >= ?
            ");
            $repStmt->execute([$convId, $compCompanyId, $item['created_at']]);
            $repliedCount = (int)$repStmt->fetchColumn();

            if ($repliedCount > 0) {
                // Visitor already replied! Cancel immediately!
                $pdo->prepare("UPDATE `whatsapp_followup_queue` SET `status` = 'cancelled' WHERE id = ?")->execute([$qId]);
                $cancelled++;
                continue;
            }

            // Prepare follow-up message text
            $baseMsg = !empty($item['follow_up_message'])
                ? $item['follow_up_message']
                : "Hi {{name}}, just checking in! Would you like to review our brochure or speak with a counselor?";
            $bodyText = str_replace(
                ['{{name}}', '{{company}}'],
                [$custName, $item['company_name']],
                $baseMsg
            );

            // Append next steps options if configured
            if (!empty($item['follow_up_options_json'])) {
                $opts = json_decode($item['follow_up_options_json'], true);
                if (!empty($opts) && is_array($opts)) {
                    $bodyText .= "\n\n" . implode("\n", $opts);
                }
            }

            // Dispatch via Meta Cloud API
            $metaSent = self::sendWhatsAppText($pdo, $compCompanyId, $recipient, $bodyText);

            // Record in messages table
            $pdo->prepare("
                INSERT INTO `messages` (`company_id`, `conversation_id`, `sender_type`, `message_text`, `channel`, `created_at`)
                VALUES (?, ?, 'ai', ?, 'whatsapp', NOW())
            ")->execute([$compCompanyId, $convId, $bodyText]);

            // Update queue record to sent
            $pdo->prepare("
                UPDATE `whatsapp_followup_queue`
                SET `status` = 'sent', `sent_at` = NOW(), `followup_count` = followup_count + 1
                WHERE id = ?
            ")->execute([$qId]);

            $processed++;
        }

        return ['processed' => $processed, 'cancelled' => $cancelled];
    }

    /**
     * Synchronize actual Message Templates from Meta Graph API.
     */
    public static function syncMetaTemplates(PDO $pdo, int $companyId): array {
        self::ensureTables($pdo);

        // Fetch WABA credentials
        $stmt = $pdo->prepare("SELECT phone_number_id, waba_id, whatsapp_access_token FROM `whatsapp_accounts` WHERE `company_id` = ? LIMIT 1");
        $stmt->execute([$companyId]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$acc || empty($acc['waba_id']) || empty($acc['whatsapp_access_token'])) {
            return [
                'success' => false,
                'error'   => 'WhatsApp Business Account (WABA) not connected for this company.'
            ];
        }

        $wabaId = $acc['waba_id'];
        $token  = $acc['whatsapp_access_token'];

        $endpoint = "https://graph.facebook.com/v20.0/{$wabaId}/message_templates?limit=100";
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($res)) {
            return [
                'success'   => false,
                'http_code' => $httpCode,
                'error'     => 'Failed to fetch templates from Meta API. Response: ' . substr($res, 0, 200)
            ];
        }

        $data = json_decode($res, true);
        $templates = $data['data'] ?? [];

        $upsert = $pdo->prepare("
            INSERT INTO `whatsapp_templates_cache`
            (`company_id`, `meta_template_id`, `name`, `category`, `language`, `status`, `components_json`, `last_synced_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
              `meta_template_id` = VALUES(`meta_template_id`),
              `category` = VALUES(`category`),
              `status` = VALUES(`status`),
              `components_json` = VALUES(`components_json`),
              `last_synced_at` = NOW()
        ");

        $synced = [];
        foreach ($templates as $t) {
            $tId   = $t['id'] ?? '';
            $name  = $t['name'] ?? '';
            $cat   = $t['category'] ?? 'UTILITY';
            $lang  = $t['language'] ?? 'en_US';
            $stat  = strtoupper($t['status'] ?? 'APPROVED');
            $comps = json_encode($t['components'] ?? [], JSON_UNESCAPED_UNICODE);

            $upsert->execute([$companyId, $tId, $name, $cat, $lang, $stat, $comps]);
            $synced[] = [
                'id'         => $tId,
                'name'       => $name,
                'category'   => $cat,
                'language'   => $lang,
                'status'     => $stat,
                'components' => $t['components'] ?? []
            ];
        }

        return [
            'success'   => true,
            'count'     => count($synced),
            'templates' => $synced
        ];
    }

    /**
     * Retrieve cached templates for a company with optional category filter.
     */
    public static function getCachedTemplates(PDO $pdo, int $companyId, ?string $category = null, ?string $search = null): array {
        self::ensureTables($pdo);

        $sql = "SELECT meta_template_id as id, name, category, language, status, components_json, last_synced_at FROM `whatsapp_templates_cache` WHERE `company_id` = ?";
        $params = [$companyId];

        if (!empty($category) && $category !== 'all') {
            $sql .= " AND LOWER(category) = LOWER(?)";
            $params[] = $category;
        }
        if (!empty($search)) {
            $sql .= " AND (name LIKE ? OR components_json LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $sql .= " ORDER BY status = 'APPROVED' DESC, name ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // If cache empty, attempt one auto-sync
        if (empty($rows)) {
            $syncRes = self::syncMetaTemplates($pdo, $companyId);
            if ($syncRes['success'] && !empty($syncRes['templates'])) {
                return self::getCachedTemplates($pdo, $companyId, $category, $search);
            }
        }

        foreach ($rows as &$r) {
            $r['components'] = !empty($r['components_json']) ? (json_decode($r['components_json'], true) ?: []) : [];
            unset($r['components_json']);
        }

        return $rows;
    }

    /**
     * Create and submit a template draft to Meta Graph API.
     */
    public static function createMetaTemplate(PDO $pdo, int $companyId, array $data): array {
        self::ensureTables($pdo);

        $name = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', trim($data['name'] ?? '')));
        $category = strtoupper(trim($data['category'] ?? 'UTILITY'));
        $language = trim($data['language'] ?? 'en_US');
        $bodyText = trim($data['body'] ?? '');

        if (empty($name) || empty($bodyText)) {
            return ['success' => false, 'error' => 'Template name and body text are required.'];
        }

        // Fetch WABA credentials
        $stmt = $pdo->prepare("SELECT waba_id, whatsapp_access_token FROM `whatsapp_accounts` WHERE `company_id` = ? LIMIT 1");
        $stmt->execute([$companyId]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$acc || empty($acc['waba_id']) || empty($acc['whatsapp_access_token'])) {
            return ['success' => false, 'error' => 'WABA not connected for this company.'];
        }

        $components = [
            [
                'type' => 'BODY',
                'text' => $bodyText
            ]
        ];

        // Optional footer
        if (!empty($data['footer'])) {
            $components[] = [
                'type' => 'FOOTER',
                'text' => trim($data['footer'])
            ];
        }

        // Optional call to action / quick reply button
        if (!empty($data['button_text'])) {
            $components[] = [
                'type' => 'BUTTONS',
                'buttons' => [
                    [
                        'type' => 'QUICK_REPLY',
                        'text' => trim($data['button_text'])
                    ]
                ]
            ];
        }

        $payload = [
            'name'       => $name,
            'category'   => $category,
            'language'   => $language,
            'components' => $components
        ];

        $endpoint = "https://graph.facebook.com/v20.0/{$acc['waba_id']}/message_templates";
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$acc['whatsapp_access_token']}",
                "Content-Type: application/json"
            ],
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($res, true);
        if ($httpCode >= 200 && $httpCode < 300 && !empty($json['id'])) {
            $metaId = $json['id'];
            $status = $json['status'] ?? 'PENDING';

            $ins = $pdo->prepare("
                INSERT INTO `whatsapp_templates_cache`
                (`company_id`, `meta_template_id`, `name`, `category`, `language`, `status`, `components_json`, `last_synced_at`)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE `status` = VALUES(`status`), `components_json` = VALUES(`components_json`)
            ");
            $ins->execute([$companyId, $metaId, $name, $category, $language, $status, json_encode($components, JSON_UNESCAPED_UNICODE)]);

            return [
                'success'          => true,
                'meta_template_id' => $metaId,
                'status'           => $status,
                'name'             => $name,
                'message'          => "Template '{$name}' successfully submitted to Meta (Status: {$status})."
            ];
        }

        $errorMsg = $json['error']['message'] ?? "Meta API rejected template draft (HTTP {$httpCode}).";
        return [
            'success'   => false,
            'http_code' => $httpCode,
            'error'     => $errorMsg
        ];
    }

    /**
     * Dispatch simple text message via Meta Cloud API.
     */
    private static function sendWhatsAppText(PDO $pdo, int $companyId, string $toPhone, string $text): bool {
        $stmt = $pdo->prepare("SELECT phone_number_id, whatsapp_access_token FROM `whatsapp_accounts` WHERE `company_id` = ? AND `status` != 'disconnected' LIMIT 1");
        $stmt->execute([$companyId]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$acc || empty($acc['phone_number_id']) || empty($acc['whatsapp_access_token'])) {
            return false;
        }

        $cleanRecipient = preg_replace('/[^0-9]/', '', $toPhone);
        if (strlen($cleanRecipient) === 10) {
            $cleanRecipient = '91' . $cleanRecipient;
        }

        $endpoint = "https://graph.facebook.com/v20.0/{$acc['phone_number_id']}/messages";
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $cleanRecipient,
            'type'              => 'text',
            'text'              => ['preview_url' => false, 'body' => $text]
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$acc['whatsapp_access_token']}",
                "Content-Type: application/json"
            ],
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($code >= 200 && $code < 300);
    }
}
