<?php
/**
 * CUBOIDPILOT — CUSTOMER IDENTITY RESOLVER
 * Enterprise Omnichannel Identity Resolution Engine.
 * Resolves or links customer identities across Web, WhatsApp, and Instagram
 * while enforcing strict multi-tenant isolation.
 */

require_once __DIR__ . '/../config/db.php';

class CustomerIdentityResolver {

    /**
     * Resolve or create customer from Web channel.
     */
    public static function resolveFromWeb(
        PDO $pdo,
        int $companyId,
        ?string $sessionId = null,
        ?string $phone = null,
        ?string $email = null,
        ?string $name = null,
        ?int $conversationId = null,
        ?int $leadId = null,
        ?int $customerId = null
    ): array {
        $customer = null;

        // 1. Direct Customer ID lookup
        if ($customerId) {
            $stmt = $pdo->prepare("SELECT * FROM `customers` WHERE `id` = ? AND `company_id` = ? LIMIT 1");
            $stmt->execute([$customerId, $companyId]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // 2. Direct Conversation lookup
        if (!$customer && $conversationId) {
            $stmt = $pdo->prepare("SELECT `customer_id` FROM `conversations` WHERE `id` = ? AND `company_id` = ? LIMIT 1");
            $stmt->execute([$conversationId, $companyId]);
            $cId = $stmt->fetchColumn();
            if ($cId) {
                $cStmt = $pdo->prepare("SELECT * FROM `customers` WHERE `id` = ? AND `company_id` = ? LIMIT 1");
                $cStmt->execute([(int)$cId, $companyId]);
                $customer = $cStmt->fetch(PDO::FETCH_ASSOC);
                if ($customer) {
                    $customerId = (int)$customer['id'];
                }
            }
        }

        // 3. Direct Lead lookup
        if (!$customer && $leadId) {
            $stmt = $pdo->prepare("SELECT customer_id FROM `leads` WHERE `id` = ? AND `company_id` = ? LIMIT 1");
            $stmt->execute([$leadId, $companyId]);
            $cId = $stmt->fetchColumn();
            if ($cId) {
                $cStmt = $pdo->prepare("SELECT * FROM `customers` WHERE `id` = ? AND `company_id` = ? LIMIT 1");
                $cStmt->execute([(int)$cId, $companyId]);
                $customer = $cStmt->fetch(PDO::FETCH_ASSOC);
                if ($customer) {
                    $customerId = (int)$customer['id'];
                }
            }
        }

        // 4. Verified Phone lookup
        if (!$customer && !empty($phone)) {
            $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
            if (strlen($cleanPhone) >= 7) {
                $stmt = $pdo->prepare("
                    SELECT c.* FROM `customers` c
                    WHERE c.company_id = ? AND (
                        REPLACE(REPLACE(REPLACE(c.phone, ' ', ''), '-', ''), '+', '') LIKE ? OR
                        REPLACE(REPLACE(REPLACE(c.whatsapp_number, ' ', ''), '-', ''), '+', '') LIKE ?
                    )
                    ORDER BY c.last_seen_at DESC LIMIT 1
                ");
                $stmt->execute([$companyId, "%{$cleanPhone}%", "%{$cleanPhone}%"]);
                $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }

        // 5. Verified Email lookup
        if (!$customer && !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $stmt = $pdo->prepare("SELECT * FROM `customers` WHERE `company_id` = ? AND LOWER(`email`) = LOWER(?) LIMIT 1");
            $stmt->execute([$companyId, trim($email)]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // 6. Session Token lookup via visitor_sessions
        if (!$customer && !empty($sessionId)) {
            $stmt = $pdo->prepare("
                SELECT c.* FROM `customers` c
                JOIN `visitor_sessions` vs ON vs.customer_id = c.id
                WHERE (vs.session_id = ? OR vs.session_token = ?) AND vs.company_id = ?
                LIMIT 1
            ");
            $stmt->execute([$sessionId, $sessionId, $companyId]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // 7. Channel Identity lookup
        if (!$customer && !empty($sessionId)) {
            $stmt = $pdo->prepare("
                SELECT c.* FROM `customers` c
                JOIN `customer_channel_identities` cci ON cci.customer_id = c.id
                WHERE cci.company_id = ? AND cci.channel = 'web' AND cci.external_user_id = ?
                LIMIT 1
            ");
            $stmt->execute([$companyId, $sessionId]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // 8. Create Customer if not found
        if (!$customer) {
            $custUuid = 'cust_' . bin2hex(random_bytes(10));
            $custName = !empty($name) ? $name : (!empty($phone) ? 'Prospect ' . substr($phone, -4) : 'Website Visitor');
            
            $ins = $pdo->prepare("
                INSERT INTO `customers`
                (`company_id`, `customer_uuid`, `name`, `phone`, `email`, `whatsapp_number`, `first_seen_at`, `last_seen_at`)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $ins->execute([
                $companyId,
                $custUuid,
                $custName,
                $phone ?: null,
                $email ?: null,
                $phone ?: null
            ]);
            $customerId = (int)$pdo->lastInsertId();

            $fetch = $pdo->prepare("SELECT * FROM `customers` WHERE `id` = ? LIMIT 1");
            $fetch->execute([$customerId]);
            $customer = $fetch->fetch(PDO::FETCH_ASSOC);
        } else {
            $customerId = (int)$customer['id'];
            // Update fields if new information was provided
            $updates = [];
            $params = [];
            if (!empty($name) && ($customer['name'] === 'Website Visitor' || empty($customer['name']))) {
                $updates[] = "`name` = ?";
                $params[] = $name;
                $customer['name'] = $name;
            }
            if (!empty($phone) && empty($customer['phone'])) {
                $updates[] = "`phone` = ?";
                $updates[] = "`whatsapp_number` = COALESCE(`whatsapp_number`, ?)";
                $params[] = $phone;
                $params[] = $phone;
                $customer['phone'] = $phone;
            }
            if (!empty($email) && empty($customer['email'])) {
                $updates[] = "`email` = ?";
                $params[] = $email;
                $customer['email'] = $email;
            }
            $updates[] = "`last_seen_at` = NOW()";
            $params[] = $customerId;
            $params[] = $companyId;
            $pdo->prepare("UPDATE `customers` SET " . implode(', ', $updates) . " WHERE `id` = ? AND `company_id` = ?")->execute($params);
        }

        // Attach Web Channel Identity
        if (!empty($sessionId)) {
            self::attachIdentity($pdo, $companyId, $customerId, 'web', $sessionId, null, $phone, $email);
        }

        return [
            'customer'        => $customer,
            'customer_id'     => (int)$customerId,
            'lead_id'         => $leadId ? (int)$leadId : null,
            'conversation_id' => $conversationId ? (int)$conversationId : null
        ];
    }

    /**
     * Resolve customer from incoming WhatsApp message / webhook.
     * Supports both ($pdo, $senderPhone, $messageText, ...) and ($pdo, $companyId, $senderPhone, ...)
     */
    public static function resolveFromWhatsApp(
        PDO $pdo,
        $senderPhoneOrCompanyId,
        $messageTextOrPhone = null,
        ?string $wabaIdOrMsg = null,
        ?string $businessPhoneOrWaba = null,
        ?int $explicitCompanyId = null
    ): array {
        $matchedHandoff = null;
        $companyId = null;
        $senderPhone = '';
        $messageText = null;
        $wabaId = null;
        $businessPhone = null;

        $isCompanyIdFirst = (is_int($senderPhoneOrCompanyId) && $senderPhoneOrCompanyId < 1000000)
            || (is_numeric($senderPhoneOrCompanyId) && strlen((string)$senderPhoneOrCompanyId) <= 6 && !empty($messageTextOrPhone) && strlen((string)$messageTextOrPhone) >= 7);

        if ($isCompanyIdFirst) {
            // Pattern A: ($pdo, int $companyId, string $senderPhone, ?string $messageText, ?string $wabaId)
            $companyId = (int)$senderPhoneOrCompanyId;
            $senderPhone = $messageTextOrPhone;
            $messageText = $wabaIdOrMsg;
            $wabaId = $businessPhoneOrWaba;
        } else {
            // Pattern B: ($pdo, string $senderPhone, ?string $messageText, ?string $wabaId, ?string $businessPhone, ?int $explicitCompanyId)
            $senderPhone = (string)$senderPhoneOrCompanyId;
            $messageText = is_string($messageTextOrPhone) ? $messageTextOrPhone : null;
            $wabaId = $wabaIdOrMsg;
            $businessPhone = $businessPhoneOrWaba;
            $companyId = $explicitCompanyId;
        }

        $cleanPhone = preg_replace('/[^0-9]/', '', $senderPhone);
        $customer = null;
        $leadId = null;
        $conversationId = null;

        // Step A: Inspect message for explicit handoff reference (e.g. Ref: C7K29X or Ref: wh_...)
        if (!empty($messageText)) {
            $matchedHandoff = self::extractAndResolveHandoff($pdo, $companyId, $messageText, 'whatsapp');
            if ($matchedHandoff) {
                if (empty($companyId) && !empty($matchedHandoff['company_id'])) {
                    $companyId = (int)$matchedHandoff['company_id'];
                }
                if (!empty($matchedHandoff['lead_id'])) {
                    $leadId = (int)$matchedHandoff['lead_id'];
                }
                if (!empty($matchedHandoff['conversation_id'])) {
                    $conversationId = (int)$matchedHandoff['conversation_id'];
                }
                if (!empty($matchedHandoff['customer_id'])) {
                    $stmt = $pdo->prepare("SELECT * FROM `customers` WHERE `id` = ? LIMIT 1");
                    $stmt->execute([(int)$matchedHandoff['customer_id']]);
                    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
                }
            }
        }

        // Step B: If companyId is not yet known, resolve it from business phone, WABA ID, channel identity, or customer
        if (!$companyId) {
            // Check via customer_channel_identities
            if (!empty($cleanPhone)) {
                $stmt = $pdo->prepare("SELECT company_id, customer_id FROM `customer_channel_identities` WHERE channel = 'whatsapp' AND external_user_id = ? ORDER BY id DESC LIMIT 1");
                $stmt->execute([$cleanPhone]);
                $cRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($cRow) {
                    $companyId = (int)$cRow['company_id'];
                    if (!$customer) {
                        $stmtCust = $pdo->prepare("SELECT * FROM `customers` WHERE id = ? LIMIT 1");
                        $stmtCust->execute([(int)$cRow['customer_id']]);
                        $customer = $stmtCust->fetch(PDO::FETCH_ASSOC);
                    }
                }
            }

            // Check via whatsapp_accounts table
            if (!$companyId && !empty($businessPhone)) {
                $cleanDest = preg_replace('/[^0-9]/', '', $businessPhone);
                try {
                    $stmt = $pdo->prepare("SELECT company_id FROM `whatsapp_accounts` WHERE (`phone_number_id` LIKE ? OR `display_phone_number` LIKE ?) AND `status` != 'disconnected' LIMIT 1");
                    $stmt->execute(["%{$cleanDest}%", "%{$cleanDest}%"]);
                    $companyId = $stmt->fetchColumn() ?: null;
                } catch (Throwable $e) {}
            }

            // Check via whatsapp_accounts or companies table (waba_account_id)
            if (!$companyId && !empty($wabaId)) {
                try {
                    $stmt = $pdo->prepare("SELECT company_id FROM `whatsapp_accounts` WHERE `waba_account_id` = ? AND `status` != 'disconnected' LIMIT 1");
                    $stmt->execute([$wabaId]);
                    $companyId = $stmt->fetchColumn() ?: null;
                } catch (Throwable $e) {}
                if (!$companyId) {
                    try {
                        $stmt = $pdo->prepare("SELECT id FROM `companies` WHERE whatsapp_business_account_id = ? LIMIT 1");
                        $stmt->execute([$wabaId]);
                        $companyId = $stmt->fetchColumn() ?: null;
                    } catch (Throwable $e) {}
                }
            }

            // Check customers phone match
            if (!$companyId && !empty($cleanPhone)) {
                $stmt = $pdo->prepare("
                    SELECT company_id, id FROM `customers` 
                    WHERE REPLACE(REPLACE(REPLACE(`phone`, ' ', ''), '-', ''), '+', '') LIKE ? OR
                          REPLACE(REPLACE(REPLACE(`whatsapp_number`, ' ', ''), '-', ''), '+', '') LIKE ?
                    ORDER BY last_seen_at DESC LIMIT 1
                ");
                $stmt->execute(["%{$cleanPhone}%", "%{$cleanPhone}%"]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $companyId = (int)$row['company_id'];
                    if (!$customer) {
                        $stmtCust = $pdo->prepare("SELECT * FROM `customers` WHERE id = ? LIMIT 1");
                        $stmtCust->execute([(int)$row['id']]);
                        $customer = $stmtCust->fetch(PDO::FETCH_ASSOC);
                    }
                }
            }

            // Fallback to first company in database (e.g. single tenant / sandbox)
            if (!$companyId) {
                $companyId = (int)$pdo->query("SELECT id FROM companies ORDER BY id ASC LIMIT 1")->fetchColumn();
            }
        }

        // Step C: If customer not found via handoff or identity, search customer by phone within company
        if (!$customer && $companyId && !empty($cleanPhone)) {
            $stmt = $pdo->prepare("
                SELECT * FROM `customers` 
                WHERE `company_id` = ? AND (
                    REPLACE(REPLACE(REPLACE(`phone`, ' ', ''), '-', ''), '+', '') LIKE ? OR
                    REPLACE(REPLACE(REPLACE(`whatsapp_number`, ' ', ''), '-', ''), '+', '') LIKE ?
                )
                ORDER BY `last_seen_at` DESC LIMIT 1
            ");
            $stmt->execute([$companyId, "%{$cleanPhone}%", "%{$cleanPhone}%"]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Step D: Create customer if brand new
        if (!$customer && $companyId) {
            $custUuid = 'cust_' . bin2hex(random_bytes(10));
            $custName = 'WhatsApp ' . substr($cleanPhone, -4);
            $ins = $pdo->prepare("
                INSERT INTO `customers`
                (`company_id`, `customer_uuid`, `name`, `phone`, `whatsapp_number`, `first_seen_at`, `last_seen_at`)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $ins->execute([$companyId, $custUuid, $custName, $senderPhone, $senderPhone]);
            $customerId = (int)$pdo->lastInsertId();

            $fetch = $pdo->prepare("SELECT * FROM `customers` WHERE `id` = ? LIMIT 1");
            $fetch->execute([$customerId]);
            $customer = $fetch->fetch(PDO::FETCH_ASSOC);
        } elseif ($customer && $companyId) {
            $customerId = (int)$customer['id'];
            $pdo->prepare("
                UPDATE `customers` 
                SET `phone` = COALESCE(`phone`, ?),
                    `whatsapp_number` = ?,
                    `last_seen_at` = NOW()
                WHERE `id` = ? AND `company_id` = ?
            ")->execute([$senderPhone, $senderPhone, $customerId, $companyId]);
        } else {
            $customerId = null;
        }

        // Attach WhatsApp Channel Identity
        if ($customerId && $companyId && !empty($cleanPhone)) {
            self::attachIdentity($pdo, $companyId, $customerId, 'whatsapp', $cleanPhone, $wabaId, $senderPhone, null);
        }

        // Find linked lead if not already known
        if ($customerId && empty($leadId)) {
            $lStmt = $pdo->prepare("SELECT id FROM leads WHERE customer_id = ? AND company_id = ? ORDER BY id DESC LIMIT 1");
            $lStmt->execute([$customerId, $companyId]);
            $leadId = $lStmt->fetchColumn() ?: null;
        }

        return [
            'company_id'      => $companyId,
            'customer_id'     => $customerId,
            'customer'        => $customer,
            'handoff'         => $matchedHandoff,
            'lead_id'         => $leadId ? (int)$leadId : null,
            'conversation_id' => $conversationId ? (int)$conversationId : null
        ];
    }

    /**
     * Resolve customer from incoming Instagram message / webhook.
     */
    public static function resolveFromInstagram(PDO $pdo, int $companyId, string $instagramUserId, ?string $messageText = null, ?string $pageId = null): array {
        $customer = null;
        $matchedHandoff = null;
        $leadId = null;
        $conversationId = null;

        // Step A: Inspect message for explicit handoff token (e.g. Ref: C7K29X or Ref: CP-IG-...)
        if (!empty($messageText)) {
            $matchedHandoff = self::extractAndResolveHandoff($pdo, $companyId, $messageText, 'instagram');
            if ($matchedHandoff) {
                if (!empty($matchedHandoff['lead_id'])) {
                    $leadId = (int)$matchedHandoff['lead_id'];
                }
                if (!empty($matchedHandoff['conversation_id'])) {
                    $conversationId = (int)$matchedHandoff['conversation_id'];
                }
                if (!empty($matchedHandoff['customer_id'])) {
                    $stmt = $pdo->prepare("SELECT * FROM `customers` WHERE `id` = ? AND `company_id` = ? LIMIT 1");
                    $stmt->execute([(int)$matchedHandoff['customer_id'], $companyId]);
                    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
                }
            }
        }

        // Step B: Look up via customer_channel_identities (external_user_id = instagramUserId)
        if (!$customer) {
            $stmt = $pdo->prepare("
                SELECT c.* FROM `customers` c
                JOIN `customer_channel_identities` cci ON cci.customer_id = c.id
                WHERE cci.company_id = ? AND cci.channel = 'instagram' AND cci.external_user_id = ?
                LIMIT 1
            ");
            $stmt->execute([$companyId, $instagramUserId]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Step C: Create customer if brand new
        if (!$customer) {
            $custUuid = 'cust_' . bin2hex(random_bytes(10));
            $custName = 'Instagram User';
            $ins = $pdo->prepare("
                INSERT INTO `customers`
                (`company_id`, `customer_uuid`, `name`, `first_seen_at`, `last_seen_at`)
                VALUES (?, ?, ?, NOW(), NOW())
            ");
            $ins->execute([$companyId, $custUuid, $custName]);
            $customerId = (int)$pdo->lastInsertId();

            $fetch = $pdo->prepare("SELECT * FROM `customers` WHERE `id` = ? LIMIT 1");
            $fetch->execute([$customerId]);
            $customer = $fetch->fetch(PDO::FETCH_ASSOC);
        } else {
            $customerId = (int)$customer['id'];
            $pdo->prepare("UPDATE `customers` SET `last_seen_at` = NOW() WHERE `id` = ? AND `company_id` = ?")
                ->execute([$customerId, $companyId]);
        }

        // Attach Instagram Channel Identity
        self::attachIdentity($pdo, $companyId, $customerId, 'instagram', $instagramUserId, $pageId, null, null);

        if ($customerId && empty($leadId)) {
            $lStmt = $pdo->prepare("SELECT id FROM leads WHERE customer_id = ? AND company_id = ? ORDER BY id DESC LIMIT 1");
            $lStmt->execute([$customerId, $companyId]);
            $leadId = $lStmt->fetchColumn() ?: null;
        }

        return [
            'company_id'      => $companyId,
            'customer_id'     => $customerId,
            'customer'        => $customer,
            'handoff'         => $matchedHandoff,
            'lead_id'         => $leadId ? (int)$leadId : null,
            'conversation_id' => $conversationId ? (int)$conversationId : null
        ];
    }

    /**
     * Attach or update an external channel identity for a customer.
     */
    public static function attachIdentity(PDO $pdo, int $companyId, int $customerId, string $channel, string $externalUserId, ?string $externalAccountId = null, ?string $phone = null, ?string $email = null): void {
        if (empty($externalUserId)) return;

        try {
            $stmt = $pdo->prepare("
                INSERT INTO `customer_channel_identities`
                (`company_id`, `customer_id`, `channel`, `external_user_id`, `external_account_id`, `phone`, `email`, `verified_at`, `created_at`, `updated_at`)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    `customer_id` = VALUES(`customer_id`),
                    `external_account_id` = COALESCE(VALUES(`external_account_id`), `external_account_id`),
                    `phone` = COALESCE(VALUES(`phone`), `phone`),
                    `email` = COALESCE(VALUES(`email`), `email`),
                    `updated_at` = NOW()
            ");
            $stmt->execute([
                $companyId,
                $customerId,
                $channel,
                $externalUserId,
                $externalAccountId ?: null,
                $phone ?: null,
                $email ?: null
            ]);
        } catch (Exception $e) {
            error_log("[CustomerIdentityResolver] attachIdentity failed: " . $e->getMessage());
        }
    }

    /**
     * Extract and resolve handoff token from message text.
     */
    public static function extractAndResolveHandoff(PDO $pdo, ?int $companyId, string $messageText, string $targetChannel = 'any'): ?array {
        // Precise extraction: checks for Ref: TOKEN, #TOKEN, [Ref: TOKEN], CONTINUE_TOKEN, standalone 6-char token, or legacy wh_/CP- tokens
        if (preg_match('/(?:Ref:\s*|#\s*|\[Ref:\s*|CONTINUE_)([A-Z0-9_\-]{5,20})/i', $messageText, $match)) {
            $token = strtoupper(trim($match[1]));
        } elseif (preg_match('/\b(wh_[a-f0-9]{6,}|CP-[A-Z0-9\-]+)\b/i', $messageText, $match)) {
            $token = strtoupper(trim($match[1]));
        } elseif (preg_match('/^\s*([A-Z0-9]{6})\s*$/i', $messageText, $match)) {
            $token = strtoupper(trim($match[1]));
        } else {
            return null;
        }

        // Check unified channel_handoffs table
        $sql = "SELECT * FROM `channel_handoffs` WHERE `handoff_token` = ? AND `status` != 'expired' AND `expires_at` > NOW()";
        $params = [$token];
        if (!empty($companyId)) {
            $sql .= " AND `company_id` = ?";
            $params[] = $companyId;
        }
        $stmt = $pdo->prepare($sql . " LIMIT 1");
        $stmt->execute($params);
        $handoff = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($handoff) {
            // Mark handoff completed
            $pdo->prepare("UPDATE `channel_handoffs` SET `status` = 'completed', `completed_at` = NOW() WHERE `id` = ?")
                ->execute([(int)$handoff['id']]);
            return $handoff;
        }

        // Fallback: Check legacy whatsapp_handoffs / instagram_handoffs
        if ($targetChannel === 'whatsapp' || $targetChannel === 'any') {
            $sql = "SELECT * FROM `whatsapp_handoffs` WHERE `handoff_token` = ? AND `status` != 'expired'";
            $params = [$token];
            if (!empty($companyId)) {
                $sql .= " AND `company_id` = ?";
                $params[] = $companyId;
            }
            $stmt = $pdo->prepare($sql . " LIMIT 1");
            $stmt->execute($params);
            $leg = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($leg) {
                $pdo->prepare("UPDATE `whatsapp_handoffs` SET `status` = 'claimed', `claimed_at` = NOW() WHERE `id` = ?")->execute([(int)$leg['id']]);
                return [
                    'id'              => (int)$leg['id'],
                    'company_id'      => (int)$leg['company_id'],
                    'customer_id'     => (int)$leg['customer_id'],
                    'lead_id'         => !empty($leg['lead_id']) ? (int)$leg['lead_id'] : null,
                    'conversation_id' => (int)$leg['web_conversation_id'],
                    'source_channel'  => 'web',
                    'target_channel'  => 'whatsapp',
                    'handoff_token'   => $leg['handoff_token']
                ];
            }
        }
        if ($targetChannel === 'instagram' || $targetChannel === 'any') {
            $sql = "SELECT * FROM `instagram_handoffs` WHERE `handoff_token` = ? AND `status` != 'expired'";
            $params = [$token];
            if (!empty($companyId)) {
                $sql .= " AND `company_id` = ?";
                $params[] = $companyId;
            }
            $stmt = $pdo->prepare($sql . " LIMIT 1");
            $stmt->execute($params);
            $leg = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($leg) {
                $pdo->prepare("UPDATE `instagram_handoffs` SET `status` = 'claimed', `claimed_at` = NOW() WHERE `id` = ?")->execute([(int)$leg['id']]);
                return [
                    'id'              => (int)$leg['id'],
                    'company_id'      => (int)$leg['company_id'],
                    'customer_id'     => (int)$leg['customer_id'],
                    'lead_id'         => !empty($leg['lead_id']) ? (int)$leg['lead_id'] : null,
                    'conversation_id' => (int)$leg['web_conversation_id'],
                    'source_channel'  => 'web',
                    'target_channel'  => 'instagram',
                    'handoff_token'   => $leg['handoff_token']
                ];
            }
        }

        return null;
    }
}
