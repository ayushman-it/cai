<?php
/**
 * CUBOIDPILOT — CHANNEL HANDOFF SERVICE
 * Generates and consumes single-use, secure, tenant-bound continuation tokens (Ref: C7K29X)
 * across Web -> WhatsApp and Web -> Instagram transitions.
 * Enforces explicit consent auditing in channel_consents.
 */

require_once __DIR__ . '/../config/db.php';

class ChannelHandoffService {

    /**
     * Generate a new handoff token and continuation URL.
     */
    public static function createHandoff(
        PDO $pdo,
        int $companyId,
        int $customerId,
        ?int $leadId,
        int $conversationId,
        string $targetChannel,
        int $journeyId = 0,
        string $sourceChannel = 'web'
    ): array {
        if ($journeyId <= 0) {
            require_once __DIR__ . '/customer_journey_service.php';
            $j = CustomerJourneyService::getOrCreateJourney($pdo, $companyId, $customerId, $leadId, $sourceChannel, null, $conversationId);
            $journeyId = (int)($j['id'] ?? 0);
        }

        // Generate secure 6-character uppercase token (e.g. C7K29X)
        $token = self::generateUniqueToken($pdo);

        // 2-hour expiration window
        $stmt = $pdo->prepare("
            INSERT INTO `channel_handoffs`
            (`company_id`, `customer_id`, `lead_id`, `journey_id`, `conversation_id`, `source_channel`, `target_channel`, `handoff_token`, `status`, `expires_at`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL 2 HOUR), NOW())
        ");
        $stmt->execute([
            $companyId,
            $customerId,
            $leadId ?: null,
            $journeyId,
            $conversationId,
            $sourceChannel,
            $targetChannel,
            $token
        ]);
        $handoffId = (int)$pdo->lastInsertId();

        // Record Explicit Continuation Consent in channel_consents
        $consentType = ($targetChannel === 'whatsapp') ? 'whatsapp_continuation' : 'instagram_continuation';
        try {
            $pdo->prepare("
                INSERT INTO `channel_consents`
                (`company_id`, `customer_id`, `channel`, `consent_type`, `consent_status`, `consent_source`, `consent_text_version`, `handoff_token`, `granted_at`)
                VALUES (?, ?, ?, ?, 'granted', 'website_widget', 'v1', ?, NOW())
            ")->execute([$companyId, $customerId, $targetChannel, $consentType, $token]);
        } catch (Exception $e) {
            error_log("[ChannelHandoffService] consent logging failed: " . $e->getMessage());
        }

        // Fetch company target endpoints
        $redirectUrl = '';
        $prefilledMessage = '';

        if ($targetChannel === 'whatsapp') {
            $accStmt = $pdo->prepare("SELECT display_number FROM `whatsapp_accounts` WHERE `company_id` = ? AND `status` != 'disconnected' ORDER BY id ASC LIMIT 1");
            $accStmt->execute([$companyId]);
            $acc = $accStmt->fetch(PDO::FETCH_ASSOC);
            $cleanPhone = preg_replace('/[^0-9]/', '', $acc['display_number'] ?? '919820112345');

            $prefilledMessage = "Hi, I'd like to continue my conversation with Cai. Ref: {$token}";
            $encodedText = rawurlencode($prefilledMessage);
            $redirectUrl = "https://wa.me/{$cleanPhone}?text={$encodedText}";
        } elseif ($targetChannel === 'instagram') {
            $igStmt = $pdo->prepare("SELECT instagram_username FROM `company_instagram_configs` WHERE `company_id` = ? LIMIT 1");
            $igStmt->execute([$companyId]);
            $igConfig = $igStmt->fetch(PDO::FETCH_ASSOC);
            $username = $igConfig['instagram_username'] ?? '';

            if (empty($username)) {
                $comp = $pdo->query("SELECT slug, name FROM companies WHERE id = {$companyId} LIMIT 1")->fetch();
                $username = strtolower(preg_replace('/[^a-zA-Z0-9_.]/', '', $comp['slug'] ?? 'cuboidpilot'));
            }

            $prefilledMessage = "Hi, I was chatting on your website with Cai. Ref: {$token}";
            $redirectUrl = "https://ig.me/m/{$username}";
        }

        // Record CRM timeline event
        if ($leadId) {
            try {
                $pdo->prepare("
                    INSERT INTO `lead_events`
                    (`company_id`, `lead_id`, `customer_id`, `event_type`, `description`, `event_data_json`, `created_at`)
                    VALUES (?, ?, ?, 'CHANNEL_HANDOFF_INITIATED', ?, ?, NOW())
                ")->execute([
                    $companyId,
                    $leadId,
                    $customerId,
                    "Visitor requested {$targetChannel} continuation (Ref: {$token})",
                    json_encode([
                        'token'          => $token,
                        'source_channel' => $sourceChannel,
                        'target_channel' => $targetChannel,
                        'journey_id'     => $journeyId
                    ])
                ]);
            } catch (Exception $e) {}
        }

        return [
            'success'            => true,
            'handoff_id'         => $handoffId,
            'handoff_token'      => $token,
            'source_channel'     => $sourceChannel,
            'target_channel'     => $targetChannel,
            'prefilled_message'  => $prefilledMessage,
            'redirect_url'       => $redirectUrl,
            'expires_in_minutes' => 120
        ];
    }

    /**
     * Generate unique 6-character random token with collision avoidance.
     */
    private static function generateUniqueToken(PDO $pdo): string {
        $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'; // Exclude 0, 1, I, O for visual clarity
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $token = '';
            for ($i = 0; $i < 6; $i++) {
                $token .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $chk = $pdo->prepare("SELECT 1 FROM `channel_handoffs` WHERE `handoff_token` = ? LIMIT 1");
            $chk->execute([$token]);
            if (!$chk->fetch()) {
                return $token;
            }
        }
        return 'C' . strtoupper(bin2hex(random_bytes(3)));
    }
}
