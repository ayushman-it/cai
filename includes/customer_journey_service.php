<?php
/**
 * CUBOIDPILOT — CUSTOMER JOURNEY SERVICE
 * Persistent Omnichannel Journey Manager.
 * Maintains one persistent journey across Web, WhatsApp, Instagram, Human, and CRM,
 * tracking state, pending actions, offered assets, and compact AI memory.
 */

require_once __DIR__ . '/../config/db.php';

class CustomerJourneyService {

    /**
     * Get or create a persistent customer journey for the customer / conversation.
     */
    public static function getOrCreateJourney(
        PDO $pdo,
        int $companyId,
        int $customerId,
        ?int $leadId = null,
        string $channel = 'web',
        ?string $sessionId = null,
        ?int $conversationId = null
    ): array {
        // 1. Try to find active journey by customer_id within company
        $stmt = $pdo->prepare("
            SELECT * FROM `customer_journeys`
            WHERE `company_id` = ? AND `customer_id` = ?
            ORDER BY `last_activity_at` DESC LIMIT 1
        ");
        $stmt->execute([$companyId, $customerId]);
        $journey = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($journey) {
            // Update conversation_id and channel if changed
            $updates = ["`last_activity_at` = NOW()"];
            $params = [];

            if (!empty($leadId) && empty($journey['lead_id'])) {
                $updates[] = "`lead_id` = ?";
                $params[] = $leadId;
                $journey['lead_id'] = $leadId;
            }
            if ($conversationId && $journey['conversation_id'] != $conversationId) {
                $updates[] = "`conversation_id` = ?";
                $params[] = $conversationId;
                $journey['conversation_id'] = $conversationId;
            }
            if (!empty($channel) && $journey['current_channel'] !== $channel) {
                $updates[] = "`previous_channel` = `current_channel`";
                $updates[] = "`current_channel` = ?";
                $params[] = $channel;
                $journey['previous_channel'] = $journey['current_channel'];
                $journey['current_channel'] = $channel;
            }

            $params[] = (int)$journey['id'];
            $params[] = $companyId;
            $pdo->prepare("UPDATE `customer_journeys` SET " . implode(', ', $updates) . " WHERE `id` = ? AND `company_id` = ?")
                ->execute($params);

            $journey['journey_stage'] = $journey['state'] ?? 'NEW';
            return $journey;
        }

        // 2. Create new Journey
        $ins = $pdo->prepare("
            INSERT INTO `customer_journeys`
            (`company_id`, `customer_id`, `lead_id`, `conversation_id`, `state`, `current_channel`, `previous_channel`, `last_activity_at`, `created_at`, `updated_at`)
            VALUES (?, ?, ?, ?, 'NEW', ?, NULL, NOW(), NOW(), NOW())
        ");
        $ins->execute([
            $companyId,
            $customerId,
            $leadId ?: null,
            $conversationId ?: 0,
            $channel ?: 'web'
        ]);
        $journeyId = (int)$pdo->lastInsertId();

        $fetch = $pdo->prepare("SELECT * FROM `customer_journeys` WHERE `id` = ? LIMIT 1");
        $fetch->execute([$journeyId]);
        $res = $fetch->fetch(PDO::FETCH_ASSOC);
        if ($res) {
            $res['journey_stage'] = $res['state'] ?? 'NEW';
        }
        return $res ?: [];
    }

    /**
     * Update journey state, channel, pending action, or summary.
     * Supports both array of updates or positional arguments.
     */
    public static function updateJourneyState(
        PDO $pdo,
        int $journeyId,
        $updatesOrState = null,
        ?string $channel = null,
        ?string $pendingAction = null,
        ?int $pendingAssetId = null,
        ?string $summary = null
    ): void {
        $updates = ["`last_activity_at` = NOW()"];
        $params = [];

        if (is_array($updatesOrState)) {
            $data = $updatesOrState;
            if (isset($data['state']) || isset($data['journey_stage'])) {
                $updates[] = "`state` = ?";
                $params[] = $data['state'] ?? $data['journey_stage'];
            }
            if (isset($data['current_channel']) || isset($data['channel'])) {
                $newChan = $data['current_channel'] ?? $data['channel'];
                $updates[] = "`previous_channel` = `current_channel`";
                $updates[] = "`current_channel` = ?";
                $params[] = $newChan;
            }
            if (isset($data['previous_channel'])) {
                $updates[] = "`previous_channel` = ?";
                $params[] = $data['previous_channel'];
            }
            if (array_key_exists('pending_action', $data)) {
                $updates[] = "`pending_action` = ?";
                $params[] = !empty($data['pending_action']) ? $data['pending_action'] : null;
            }
            if (array_key_exists('pending_asset_id', $data)) {
                $updates[] = "`pending_asset_id` = ?";
                $params[] = !empty($data['pending_asset_id']) ? (int)$data['pending_asset_id'] : null;
            }
            if (!empty($data['conversation_summary']) || !empty($data['summary'])) {
                $updates[] = "`conversation_summary` = ?";
                $params[] = $data['conversation_summary'] ?? $data['summary'];
            }
            if (!empty($data['conversation_id'])) {
                $updates[] = "`conversation_id` = ?";
                $params[] = (int)$data['conversation_id'];
            }
            if (!empty($data['lead_id'])) {
                $updates[] = "`lead_id` = ?";
                $params[] = (int)$data['lead_id'];
            }
        } else {
            if ($updatesOrState !== null) {
                $updates[] = "`state` = ?";
                $params[] = $updatesOrState;
            }
            if ($channel !== null) {
                $updates[] = "`previous_channel` = `current_channel`";
                $updates[] = "`current_channel` = ?";
                $params[] = $channel;
            }
            if ($pendingAction !== null) {
                $updates[] = "`pending_action` = ?";
                $params[] = $pendingAction !== '' ? $pendingAction : null;
            }
            if ($pendingAssetId !== null) {
                $updates[] = "`pending_asset_id` = ?";
                $params[] = $pendingAssetId > 0 ? $pendingAssetId : null;
            }
            if ($summary !== null && $summary !== '') {
                $updates[] = "`conversation_summary` = ?";
                $params[] = $summary;
            }
        }

        $params[] = $journeyId;
        $pdo->prepare("UPDATE `customer_journeys` SET " . implode(', ', $updates) . " WHERE `id` = ?")
            ->execute($params);
    }

    /**
     * Build rich, structured memory string for Cai Conversational Engine.
     * Supports both ($journey, $recentMessages) and ($pdo, $journeyId, $recentMessages).
     */
    public static function buildStructuredMemory($journeyOrPdo, $journeyIdOrMessages = [], $recentMessages = []): string {
        $pdo = null;
        $journey = null;
        $history = [];

        if ($journeyOrPdo instanceof PDO) {
            $pdo = $journeyOrPdo;
            $journeyId = (int)$journeyIdOrMessages;
            $history = is_array($recentMessages) ? $recentMessages : [];
            $stmt = $pdo->prepare("SELECT * FROM `customer_journeys` WHERE `id` = ? LIMIT 1");
            $stmt->execute([$journeyId]);
            $journey = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif (is_array($journeyOrPdo)) {
            $journey = $journeyOrPdo;
            $pdo = getDbConnection();
            $history = is_array($journeyIdOrMessages) ? $journeyIdOrMessages : [];
        } elseif (is_numeric($journeyOrPdo)) {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("SELECT * FROM `customer_journeys` WHERE `id` = ? LIMIT 1");
            $stmt->execute([(int)$journeyOrPdo]);
            $journey = $stmt->fetch(PDO::FETCH_ASSOC);
            $history = is_array($journeyIdOrMessages) ? $journeyIdOrMessages : [];
        }

        if (!$journey) {
            return "";
        }

        $companyId = (int)($journey['company_id'] ?? 0);
        $customerId = (int)($journey['customer_id'] ?? 0);
        $leadId = !empty($journey['lead_id']) ? (int)$journey['lead_id'] : null;

        // Fetch customer details
        $customer = null;
        if ($customerId && $pdo) {
            $cStmt = $pdo->prepare("SELECT * FROM `customers` WHERE `id` = ? LIMIT 1");
            $cStmt->execute([$customerId]);
            $customer = $cStmt->fetch(PDO::FETCH_ASSOC);
        }

        // Fetch lead details
        $lead = null;
        if ($leadId && $pdo) {
            $lStmt = $pdo->prepare("SELECT * FROM `leads` WHERE `id` = ? LIMIT 1");
            $lStmt->execute([$leadId]);
            $lead = $lStmt->fetch(PDO::FETCH_ASSOC);
        }

        // Fetch offered or sent digital assets from lead events / activities
        $assetsSent = [];
        $offeredAssetTitle = null;
        if (!empty($journey['pending_asset_id']) && $pdo) {
            $aStmt = $pdo->prepare("SELECT `title` FROM `company_assets` WHERE `id` = ? LIMIT 1");
            $aStmt->execute([(int)$journey['pending_asset_id']]);
            $offeredAssetTitle = $aStmt->fetchColumn() ?: null;
        }

        if ($leadId && $pdo) {
            $eStmt = $pdo->prepare("
                SELECT `event_data_json` FROM `lead_events`
                WHERE `lead_id` = ? AND `company_id` = ? AND `event_type` = 'ASSET_DOWNLOADED'
                ORDER BY id DESC LIMIT 5
            ");
            $eStmt->execute([$leadId, $companyId]);
            $evs = $eStmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($evs as $evJson) {
                $evDecoded = json_decode($evJson, true);
                if (!empty($evDecoded['title'])) {
                    $assetsSent[] = $evDecoded['title'];
                }
            }
        }

        // Fetch appointments
        $appts = [];
        if ($customerId && $pdo) {
            $apStmt = $pdo->prepare("SELECT `slot_datetime`, `status` FROM `appointments` WHERE `customer_id` = ? AND `company_id` = ? ORDER BY slot_datetime DESC LIMIT 2");
            $apStmt->execute([$customerId, $companyId]);
            $appts = $apStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Extract stated customer needs & constraints from history
        $extractedNeeds = self::extractCustomerNeedsFromHistory($history);
        if ($lead && !empty($lead['opportunity_value']) && empty($extractedNeeds['budget'])) {
            $extractedNeeds['budget'] = '₹' . number_format((float)$lead['opportunity_value']);
        }

        $lines = [];
        $lines[] = "=== CUSTOMER CONVERSATION CONTEXT & ACTIVE MEMORY ===";
        $lines[] = "Journey Stage: " . ($journey['state'] ?? 'NEW');
        $lines[] = "Current Channel: " . ($journey['current_channel'] ?? 'web');
        if (!empty($journey['previous_channel'])) {
            $lines[] = "Channel Continuity: Transitioned from " . $journey['previous_channel'] . " to " . $journey['current_channel'];
        }
        if ($customer) {
            $custName = (!empty($customer['name']) && $customer['name'] !== 'Website Visitor' && $customer['name'] !== 'Prospect') ? $customer['name'] : 'Not provided yet';
            $custPhone = !empty($customer['phone']) ? $customer['phone'] : (!empty($customer['whatsapp_number']) ? $customer['whatsapp_number'] : 'None');
            $custEmail = !empty($customer['email']) ? $customer['email'] : 'None';
            $lines[] = "Customer Profile: Name={$custName} | Phone={$custPhone} | Email={$custEmail}";
        }
        if (!empty($extractedNeeds['budget'])) {
            $lines[] = "Stated Customer Budget: " . $extractedNeeds['budget'];
        }
        if (!empty($extractedNeeds['profile'])) {
            $lines[] = "Customer Profile / Team: " . $extractedNeeds['profile'];
        }
        if (!empty($extractedNeeds['specific_interests'])) {
            $lines[] = "Specific Needs / Topics of Interest: " . implode(', ', $extractedNeeds['specific_interests']);
        }
        if ($lead) {
            $lines[] = "Lead Pipeline: Stage=" . ($lead['stage_name'] ?? 'New') . " | Priority=" . ($lead['priority'] ?? 'Medium') . " | Intent=" . ($lead['intent_level'] ?? 'Normal');
        }
        if (!empty($journey['pending_action'])) {
            $lines[] = "Pending Next Action: " . $journey['pending_action'];
        }
        if (!empty($offeredAssetTitle)) {
            $lines[] = "Currently Offered Asset: " . $offeredAssetTitle;
        }
        if (!empty($assetsSent)) {
            $lines[] = "Previously Sent Documents: " . implode(', ', array_unique($assetsSent));
        }
        if (!empty($appts)) {
            $apptSummaries = [];
            foreach ($appts as $a) {
                $apptSummaries[] = "{$a['slot_datetime']} ({$a['status']})";
            }
            $lines[] = "Scheduled Appointments: " . implode(', ', $apptSummaries);
        }
        if (!empty($journey['conversation_summary'])) {
            $lines[] = "Conversation Summary: " . $journey['conversation_summary'];
        }
        $lines[] = "====================================================\n";

        return implode("\n", $lines);
    }

    /**
     * Extract key customer constraints, budget, team size, and goals from conversation history.
     */
    public static function extractCustomerNeedsFromHistory(array $history): array {
        $budget = null;
        $profile = null;
        $interests = [];

        foreach ($history as $msg) {
            if (($msg['role'] ?? '') !== 'user') continue;
            $text = $msg['content'] ?? '';
            if (empty($text)) continue;

            // Budget extraction (e.g. "budget is ₹150", "budget 10,000", "₹150 per seat", "budget ₹10,000")
            if (preg_match('/(?:budget|fees?|paisa|kharcha|cost)\s*(?:is|hai|around|approx|of|pe|mein)?\s*[:=]?\s*(?:₹|rs\.?|inr|\$)?\s*([0-9,]+(?:\s*(?:k|lac|lakh|thousand))?)/i', $text, $bm)) {
                $budget = trim($bm[0]);
            } elseif (preg_match('/(?:₹|rs\.?|\$)\s*([0-9,]+(?:\s*(?:k|lac|lakh|thousand|per seat|seat)?)?)/i', $text, $bm2)) {
                $budget = trim($bm2[0]);
            }

            // Profile / Role / Team Size
            if (preg_match('/\b(solo founder|single founder|solopreneur|startup|small team|1 person|freelancer|beginner|fresher|college student|enterprise|team of \d+|\d+\s*people|\d+\s*seats|\d+\s*users)\b/i', $text, $pm)) {
                $profile = trim($pm[0]);
            }

            // Key Requirements & Compliance
            if (preg_match('/\b(hipaa|soc2|sso|custom plan|custom pricing|sla|api|webhook|whatsapp|mern|python|fullstack|data science|classroom|offline|hostel)\b/i', $text, $im)) {
                $interests[] = trim($im[0]);
            }
        }

        return [
            'budget' => $budget,
            'profile' => $profile,
            'specific_interests' => array_unique($interests)
        ];
    }
}
