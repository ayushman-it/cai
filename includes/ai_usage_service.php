<?php
/**
 * Cai AI usage metering and duplicate-proof resolution billing helpers.
 */

function aiUsageJson(array $value): string {
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    return $json === false ? '{}' : $json;
}

function aiUsageGetSettings(PDO $pdo, int $companyId): array {
    $stmt = $pdo->prepare("SELECT * FROM `ai_billing_settings` WHERE `company_id` = ? LIMIT 1");
    $stmt->execute([$companyId]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        $ins = $pdo->prepare("
            INSERT INTO `ai_billing_settings` (`company_id`, `outcome_price_inr`, `currency`, `billing_status`, `created_at`)
            VALUES (?, 1.00, 'INR', 'active', NOW())
        ");
        $ins->execute([$companyId]);
        $stmt->execute([$companyId]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    return $settings ?: [
        'company_id' => $companyId,
        'outcome_price_inr' => '1.00',
        'currency' => 'INR',
        'billing_status' => 'active'
    ];
}

function aiUsageRecordEvent(PDO $pdo, int $companyId, ?int $conversationId, string $eventType, array $metadata = [], ?int $customerId = null, string $channel = 'web'): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO `ai_usage_events`
            (`company_id`, `conversation_id`, `customer_id`, `event_type`, `channel`, `metadata_json`, `occurred_at`)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $companyId,
            $conversationId ?: null,
            $customerId ?: null,
            $eventType,
            $channel ?: 'web',
            aiUsageJson($metadata)
        ]);
    } catch (Throwable $e) {
        error_log('[AI Usage Event] ' . $e->getMessage());
    }
}

function aiUsageRecordAiMessage(PDO $pdo, int $companyId, ?int $conversationId, array $metadata = [], ?int $customerId = null, string $channel = 'web'): void {
    aiUsageRecordEvent($pdo, $companyId, $conversationId, 'ai_message', $metadata, $customerId, $channel);
}

function aiUsageValidateWidgetConversation(PDO $pdo, int $companyId, int $conversationId, string $sessionId = ''): array {
    $stmt = $pdo->prepare("
        SELECT id, company_id, customer_id, session_id, status, ai_resolution_status
        FROM `conversations`
        WHERE `id` = ? AND `company_id` = ?
        LIMIT 1
    ");
    $stmt->execute([$conversationId, $companyId]);
    $conversation = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$conversation) {
        throw new RuntimeException('Conversation not found for this workspace.');
    }

    $sessionId = trim($sessionId);
    if ($sessionId !== '') {
        $convSession = trim((string)($conversation['session_id'] ?? ''));
        if ($convSession !== '' && hash_equals($convSession, $sessionId)) {
            return $conversation;
        }

        $vs = $pdo->prepare("
            SELECT id FROM `visitor_sessions`
            WHERE `company_id` = ?
              AND `conversation_id` = ?
              AND (`session_id` = ? OR `session_token` = ?)
            LIMIT 1
        ");
        $vs->execute([$companyId, $conversationId, $sessionId, $sessionId]);
        if ($vs->fetchColumn()) {
            return $conversation;
        }
    }

    throw new RuntimeException('Resolution confirmation could not be verified for this widget session.');
}

function aiUsageMarkResolution(PDO $pdo, int $companyId, int $conversationId, string $sessionId, bool $resolved, string $confirmedBy = 'visitor', array $metadata = []): array {
    $conversation = aiUsageValidateWidgetConversation($pdo, $companyId, $conversationId, $sessionId);
    $customerId = !empty($conversation['customer_id']) ? (int)$conversation['customer_id'] : null;
    $settings = aiUsageGetSettings($pdo, $companyId);
    $price = max(0, (float)($settings['outcome_price_inr'] ?? 1));
    $billingActive = ($settings['billing_status'] ?? 'active') === 'active';
    $nowStatus = $resolved ? 'resolved' : 'unresolved';

    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare("
            SELECT * FROM `ai_resolution_ledger`
            WHERE `company_id` = ? AND `conversation_id` = ?
            LIMIT 1
            FOR UPDATE
        ");
        $lock->execute([$companyId, $conversationId]);
        $existing = $lock->fetch(PDO::FETCH_ASSOC);

        if ($resolved) {
            if ($existing && (int)$existing['billable'] === 1 && $existing['status'] === 'resolved') {
                $ledgerId = (int)$existing['id'];
                $amount = (float)$existing['amount_inr'];
                $duplicate = true;
            } else {
                $amount = $billingActive ? $price : 0.00;
                $duplicate = false;
                if ($existing) {
                    $upd = $pdo->prepare("
                        UPDATE `ai_resolution_ledger`
                        SET `customer_id` = ?,
                            `session_id` = ?,
                            `status` = 'resolved',
                            `billable` = ?,
                            `outcome_price_inr` = ?,
                            `amount_inr` = ?,
                            `confirmed_by` = ?,
                            `confirmed_at` = NOW(),
                            `metadata_json` = ?,
                            `updated_at` = NOW()
                        WHERE `id` = ? AND `company_id` = ?
                    ");
                    $upd->execute([
                        $customerId,
                        $sessionId ?: null,
                        $billingActive ? 1 : 0,
                        $price,
                        $amount,
                        $confirmedBy,
                        aiUsageJson($metadata),
                        (int)$existing['id'],
                        $companyId
                    ]);
                    $ledgerId = (int)$existing['id'];
                } else {
                    $ins = $pdo->prepare("
                        INSERT INTO `ai_resolution_ledger`
                        (`company_id`, `conversation_id`, `customer_id`, `session_id`, `status`, `billable`, `outcome_price_inr`, `amount_inr`, `confirmed_by`, `confirmed_at`, `metadata_json`, `created_at`, `updated_at`)
                        VALUES (?, ?, ?, ?, 'resolved', ?, ?, ?, ?, NOW(), ?, NOW(), NOW())
                    ");
                    $ins->execute([
                        $companyId,
                        $conversationId,
                        $customerId,
                        $sessionId ?: null,
                        $billingActive ? 1 : 0,
                        $price,
                        $amount,
                        $confirmedBy,
                        aiUsageJson($metadata)
                    ]);
                    $ledgerId = (int)$pdo->lastInsertId();
                }
            }

            $pdo->prepare("
                UPDATE `conversations`
                SET `status` = 'resolved',
                    `ai_resolution_status` = 'resolved',
                    `ai_resolution_confirmed_at` = NOW(),
                    `ai_billable_outcome_id` = ?,
                    `closure_reason` = 'ai_confirmed_resolved',
                    `closed_at` = COALESCE(`closed_at`, NOW()),
                    `last_message_preview` = 'Visitor confirmed AI resolved the query',
                    `last_message_at` = NOW()
                WHERE `id` = ? AND `company_id` = ?
            ")->execute([$ledgerId, $conversationId, $companyId]);
        } else {
            if ($existing && (int)$existing['billable'] === 1 && $existing['status'] === 'resolved') {
                $ledgerId = (int)$existing['id'];
                $amount = (float)$existing['amount_inr'];
                $duplicate = true;
                $nowStatus = 'resolved';
            } else {
                $duplicate = false;
                $amount = 0.00;
                if ($existing) {
                    $upd = $pdo->prepare("
                        UPDATE `ai_resolution_ledger`
                        SET `customer_id` = ?,
                            `session_id` = ?,
                            `status` = 'unresolved',
                            `billable` = 0,
                            `outcome_price_inr` = ?,
                            `amount_inr` = 0.00,
                            `confirmed_by` = ?,
                            `confirmed_at` = NOW(),
                            `metadata_json` = ?,
                            `updated_at` = NOW()
                        WHERE `id` = ? AND `company_id` = ?
                    ");
                    $upd->execute([
                        $customerId,
                        $sessionId ?: null,
                        $price,
                        $confirmedBy,
                        aiUsageJson($metadata),
                        (int)$existing['id'],
                        $companyId
                    ]);
                    $ledgerId = (int)$existing['id'];
                } else {
                    $ins = $pdo->prepare("
                        INSERT INTO `ai_resolution_ledger`
                        (`company_id`, `conversation_id`, `customer_id`, `session_id`, `status`, `billable`, `outcome_price_inr`, `amount_inr`, `confirmed_by`, `confirmed_at`, `metadata_json`, `created_at`, `updated_at`)
                        VALUES (?, ?, ?, ?, 'unresolved', 0, ?, 0.00, ?, NOW(), ?, NOW(), NOW())
                    ");
                    $ins->execute([
                        $companyId,
                        $conversationId,
                        $customerId,
                        $sessionId ?: null,
                        $price,
                        $confirmedBy,
                        aiUsageJson($metadata)
                    ]);
                    $ledgerId = (int)$pdo->lastInsertId();
                }

                $pdo->prepare("
                    UPDATE `conversations`
                    SET `ai_resolution_status` = 'unresolved',
                        `ai_resolution_confirmed_at` = NOW(),
                        `last_message_preview` = 'Visitor needs more help',
                        `last_message_at` = NOW()
                    WHERE `id` = ? AND `company_id` = ?
                ")->execute([$conversationId, $companyId]);
            }
        }

        aiUsageRecordEvent($pdo, $companyId, $conversationId, $nowStatus, [
            'confirmed_by' => $confirmedBy,
            'billable' => $resolved && $billingActive,
            'duplicate' => $duplicate
        ] + $metadata, $customerId, 'web');

        $pdo->commit();

        return [
            'success' => true,
            'ledger_id' => $ledgerId,
            'status' => $nowStatus,
            'billable' => $resolved && $billingActive && !$duplicate,
            'already_recorded' => $duplicate,
            'outcome_price_inr' => $price,
            'amount_inr' => $amount,
            'billing_status' => $settings['billing_status'] ?? 'active'
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function aiUsageRecordHumanHandoff(PDO $pdo, int $companyId, int $conversationId, ?int $customerId = null, string $sessionId = '', array $metadata = []): void {
    try {
        $settings = aiUsageGetSettings($pdo, $companyId);
        $price = max(0, (float)($settings['outcome_price_inr'] ?? 1));
        $stmt = $pdo->prepare("
            INSERT INTO `ai_resolution_ledger`
            (`company_id`, `conversation_id`, `customer_id`, `session_id`, `status`, `billable`, `outcome_price_inr`, `amount_inr`, `confirmed_by`, `confirmed_at`, `metadata_json`, `created_at`, `updated_at`)
            VALUES (?, ?, ?, ?, 'human_handoff', 0, ?, 0.00, 'visitor', NOW(), ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                `status` = IF(`status` = 'resolved' AND `billable` = 1, `status`, 'human_handoff'),
                `billable` = IF(`status` = 'resolved' AND `billable` = 1, `billable`, 0),
                `amount_inr` = IF(`status` = 'resolved' AND `billable` = 1, `amount_inr`, 0.00),
                `confirmed_at` = IF(`status` = 'resolved' AND `billable` = 1, `confirmed_at`, NOW()),
                `metadata_json` = IF(`status` = 'resolved' AND `billable` = 1, `metadata_json`, VALUES(`metadata_json`)),
                `updated_at` = NOW()
        ");
        $stmt->execute([
            $companyId,
            $conversationId,
            $customerId,
            $sessionId ?: null,
            $price,
            aiUsageJson($metadata)
        ]);

        $pdo->prepare("
            UPDATE `conversations`
            SET `ai_resolution_status` = IF(`ai_resolution_status` = 'resolved', `ai_resolution_status`, 'human_handoff')
            WHERE `id` = ? AND `company_id` = ?
        ")->execute([$conversationId, $companyId]);

        aiUsageRecordEvent($pdo, $companyId, $conversationId, 'human_handoff', $metadata, $customerId, 'web');
    } catch (Throwable $e) {
        error_log('[AI Usage Human Handoff] ' . $e->getMessage());
    }
}

function aiUsageGetSubscriptionAmount(PDO $pdo, int $companyId): float {
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(NULLIF(s.amount_inr, 0), p.price_monthly_inr, 0) AS amount_inr
            FROM `companies` c
            LEFT JOIN `subscriptions` s ON s.company_id = c.id AND s.status IN ('active','trial','past_due')
            LEFT JOIN `plans` p ON p.id = COALESCE(s.plan_id, c.plan_id)
            WHERE c.id = ?
            ORDER BY s.id DESC
            LIMIT 1
        ");
        $stmt->execute([$companyId]);
        return (float)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0.0;
    }
}
