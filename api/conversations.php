<?php
/**
 * CUBOIDPILOT — CONVERSATIONS & INBOX API (Sections 10 & 27)
 * Serves 3-pane inbox with real conversations and message threads.
 * Strictly multi-tenant isolated.
 */

error_reporting(0);
ini_set('display_errors', '0');
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/entitlements.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = getDbConnection();
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$companyId = (int)($_SESSION['company_id'] ?? 0);

if ($companyId === 0) {
    $uStmt = $pdo->prepare("SELECT company_id, is_super_admin FROM users WHERE id = ? LIMIT 1");
    $uStmt->execute([$userId]);
    $u = $uStmt->fetch();
    if (!empty($u['company_id'])) {
        $companyId = (int)$u['company_id'];
        $_SESSION['company_id'] = $companyId;
    } elseif (!empty($u['is_super_admin'])) {
        $firstComp = $pdo->query("SELECT id FROM companies ORDER BY id ASC LIMIT 1")->fetchColumn();
        if ($firstComp) {
            $companyId = (int)$firstComp;
            $_SESSION['company_id'] = $companyId;
        }
    }
}

if ($companyId === 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Workspace context not found']);
    exit;
}

session_write_close();

try {

    // 0. Fetch Company Team Members for Assignment
    if (isset($_GET['action']) && in_array($_GET['action'], ['team', 'team_members'])) {
        $tStmt = $pdo->prepare("SELECT id, name, role, email, avatar_url FROM `users` WHERE `company_id` = ? AND `is_active` = 1 ORDER BY name ASC");
        $tStmt->execute([$companyId]);
        echo json_encode([
            'success' => true,
            'team'    => array_map(function($u) {
                return [
                    'id'         => (int)$u['id'],
                    'name'       => $u['name'],
                    'role'       => ucfirst($u['role']),
                    'email'      => $u['email'],
                    'avatar_url' => $u['avatar_url'] ?? null
                ];
            }, $tStmt->fetchAll(PDO::FETCH_ASSOC))
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 1. Fetch Message Thread
    if (isset($_GET['id'])) {
        $convId = (int)$_GET['id'];
        $cStmt = $pdo->prepare("
            SELECT conv.*, c.name AS customer_name, c.phone AS customer_phone, c.email AS customer_email,
                   c.city AS customer_city,
                   u.name AS assigned_user_name, u.avatar_url AS assigned_user_avatar,
                   l.id AS lead_id, l.title AS lead_title, l.stage_name, l.priority, l.intent_level,
                   l.opportunity_value, l.ai_summary, l.radar_recommended_action,
                   l.human_attention_required, l.human_attention_reason
            FROM `conversations` conv
            LEFT JOIN `customers` c ON c.id = conv.customer_id
            LEFT JOIN `users` u ON u.id = conv.assigned_user_id
            LEFT JOIN `leads` l ON l.conversation_id = conv.id
            WHERE conv.id = ? AND conv.company_id = ?
            LIMIT 1
        ");
        $cStmt->execute([$convId, $companyId]);
        $conversation = $cStmt->fetch();

        if (!$conversation) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Conversation not found']);
            exit;
        }

        $mStmt = $pdo->prepare("
            SELECT m.id, m.sender_type, m.sender_id, m.message_text, m.detected_intent, m.metadata_json, m.created_at,
                   COALESCE(m.channel, 'web') AS channel,
                   DATE_FORMAT(m.created_at, '%h:%i %p') AS time_formatted,
                   u.avatar_url AS sender_avatar
            FROM `messages` m
            LEFT JOIN `users` u ON u.id = m.sender_id AND m.sender_type = 'user'
            WHERE m.conversation_id = ? AND m.company_id = ?
            ORDER BY m.id ASC
        ");
        $mStmt->execute([$convId, $companyId]);
        $messages = $mStmt->fetchAll();

        // Fetch AI Lead Artifact (Section 7)
        $artRow = null;
        if (!empty($conversation['lead_id'])) {
            $aStmt = $pdo->prepare("SELECT * FROM `lead_artifacts` WHERE `lead_id` = ? AND `company_id` = ? LIMIT 1");
            $aStmt->execute([(int)$conversation['lead_id'], $companyId]);
            $artRow = $aStmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$artRow && !empty($conversation['customer_id'])) {
            $aStmt = $pdo->prepare("SELECT * FROM `lead_artifacts` WHERE `customer_id` = ? AND `company_id` = ? ORDER BY id DESC LIMIT 1");
            $aStmt->execute([(int)$conversation['customer_id'], $companyId]);
            $artRow = $aStmt->fetch(PDO::FETCH_ASSOC);
        }

        // Fetch Omnichannel Customer Journey
        $jStmt = $pdo->prepare("SELECT * FROM `customer_journeys` WHERE (`conversation_id` = ? OR `customer_id` = ?) AND `company_id` = ? ORDER BY id DESC LIMIT 1");
        $jStmt->execute([$convId, (int)$conversation['customer_id'], $companyId]);
        $journeyRow = $jStmt->fetch(PDO::FETCH_ASSOC);

        // Fetch any appointments for this customer
        $appStmt = $pdo->prepare("SELECT * FROM `appointments` WHERE `customer_id` = ? AND `company_id` = ? ORDER BY slot_datetime DESC LIMIT 3");
        $appStmt->execute([(int)$conversation['customer_id'], $companyId]);
        $appointments = $appStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'      => true,
            'conversation' => [
                'id'                       => (int)$conversation['id'],
                'customer_name'            => $conversation['customer_name'] ?: 'Prospect',
                'customer_phone'           => $conversation['customer_phone'] ?? '',
                'customer_email'           => $conversation['customer_email'] ?? '',
                'customer_city'            => $conversation['customer_city'] ?? 'India',
                'assigned_user_id'         => $conversation['assigned_user_id'] ? (int)$conversation['assigned_user_id'] : null,
                'assigned_name'            => $conversation['assigned_user_name'] ?? 'Support Specialist',
                'assigned_avatar'          => $conversation['assigned_user_avatar'] ?? null,
                'channel'                  => $conversation['channel'],
                'status'                   => $conversation['status'],
                'ownership'                => $conversation['ownership'],
                'lead_id'                  => $conversation['lead_id'] ? (int)$conversation['lead_id'] : null,
                'stage'                    => $conversation['stage_name'] ?? 'NEW',
                'priority'                 => $conversation['priority'] ?? 'MEDIUM',
                'intent_level'             => $conversation['intent_level'] ?? 'medium',
                'opportunity_value'        => (int)($conversation['opportunity_value'] ?? 0),
                'ai_summary'               => $conversation['ai_summary'] ?? '',
                'radar_recommended_action' => $conversation['radar_recommended_action'] ?? '',
                'last_message_at'          => $conversation['last_message_at'],
                'human_attention_required' => (bool)($conversation['human_attention_required'] ?? false) || $conversation['status'] === 'human_requested',
                'human_attention_reason'   => $conversation['human_attention_reason'] ?? '',
                'lead_score'               => $artRow ? (int)($artRow['lead_score'] ?? 0) : 0,
                'priority_reason'          => $artRow ? ($artRow['priority_reason'] ?? '') : '',
                'journey'                  => $journeyRow ? [
                    'id'               => (int)$journeyRow['id'],
                    'journey_stage'    => $journeyRow['state'] ?? ($journeyRow['journey_stage'] ?? 'NEW'),
                    'intent_summary'   => $journeyRow['conversation_summary'] ?? ($journeyRow['intent_summary'] ?? ''),
                    'current_channel'  => $journeyRow['current_channel'] ?? 'web',
                    'pending_action'   => $journeyRow['pending_action'] ?? null,
                    'offered_assets'   => !empty($journeyRow['offered_assets_json']) ? json_decode($journeyRow['offered_assets_json'], true) : []
                ] : null,
                'artifact'                 => $artRow ? [
                    'score'               => (int)($artRow['lead_score'] ?? 0),
                    'priority'            => $artRow['priority'] ?? 'MEDIUM',
                    'priority_reason'     => $artRow['priority_reason'] ?? '',
                    'buying_signals'      => !empty($artRow['buying_signals_json']) ? json_decode($artRow['buying_signals_json'], true) : [],
                    'objections'          => !empty($artRow['objections_json']) ? json_decode($artRow['objections_json'], true) : [],
                    'missing_information' => !empty($artRow['missing_information_json']) ? json_decode($artRow['missing_information_json'], true) : [],
                    'recommended_action'  => $artRow['recommended_action'] ?? '',
                    'summary'             => $artRow['conversation_summary'] ?? '',
                    'human_attention'     => $artRow['human_attention_status'] ?? 'none'
                ] : null,
                'appointments'             => $appointments
            ],
            'messages'     => array_map(function($m) {
                return [
                    'id'            => (int)$m['id'],
                    'sender_type'   => $m['sender_type'],
                    'channel'       => $m['channel'] ?? 'web',
                    'text'          => $m['message_text'],
                    'intent'        => $m['detected_intent'],
                    'time'          => $m['time_formatted'],
                    'sender_avatar' => $m['sender_avatar'] ?? null,
                    'created_at'    => $m['created_at']
                ];
            }, $messages)
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Counselor Actions & Sending Messages
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?? $_POST;

        $convId = (int)($input['conversation_id'] ?? 0);
        $action = trim($input['action'] ?? 'send');

        if (!$convId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Conversation ID is required']);
            exit;
        }

        // Verify conversation belongs to company
        $check = $pdo->prepare("SELECT id, channel, status, ownership FROM `conversations` WHERE id = ? AND company_id = ? LIMIT 1");
        $check->execute([$convId, $companyId]);
        $conv = $check->fetch();

        if (!$conv) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Conversation not found']);
            exit;
        }

        // Action: Take Over (Section 34)
        if ($action === 'take_over') {
            require_once __DIR__ . '/events.php';
            
            $pdo->prepare("
                UPDATE `conversations`
                SET `ownership` = 'human', `status` = 'human_active', `unread_human` = 0, `assigned_user_id` = COALESCE(?, `assigned_user_id`), `last_message_at` = NOW()
                WHERE `id` = ? AND `company_id` = ?
            ")->execute([$userId, $convId, $companyId]);

            // Clear human attention flag on lead
            $pdo->prepare("
                UPDATE `leads`
                SET `human_attention_required` = 0, `assigned_user_id` = COALESCE(?, `assigned_user_id`), `updated_at` = NOW()
                WHERE (`conversation_id` = ? OR `customer_id` = (SELECT customer_id FROM `conversations` WHERE id = ?))
                  AND `company_id` = ?
            ")->execute([$userId, $convId, $convId, $companyId]);

            // Update lead_artifacts status
            $pdo->prepare("
                UPDATE `lead_artifacts`
                SET `human_attention_status` = 'resolved', `updated_at` = NOW()
                WHERE (`lead_id` = (SELECT id FROM `leads` WHERE conversation_id = ? LIMIT 1)
                   OR `customer_id` = (SELECT customer_id FROM `conversations` WHERE id = ?))
                  AND `company_id` = ?
            ")->execute([$convId, $convId, $companyId]);

            $note = 'Human specialist took over conversation.';
            $lastMsgCheck = $pdo->prepare("SELECT message_text FROM `messages` WHERE `conversation_id` = ? ORDER BY id DESC LIMIT 1");
            $lastMsgCheck->execute([$convId]);
            $lastMsg = $lastMsgCheck->fetchColumn();
            if ($lastMsg !== "[System Event] " . $note) {
                $pdo->prepare("
                    INSERT INTO `messages`
                    (`company_id`, `conversation_id`, `sender_type`, `sender_id`, `message_text`, `created_at`)
                    VALUES (?, ?, 'system', ?, ?, NOW())
                ")->execute([$companyId, $convId, $userId, "[System Event] " . $note]);
            }

            dispatchSystemEvent($pdo, $companyId, 'human.takeover', [
                'conversation_id' => $convId,
                'user_id'         => $userId
            ]);

            echo json_encode([
                'success'   => true,
                'ownership' => 'human',
                'status'    => 'human_active',
                'message'   => $note
            ]);
            exit;
        }

        // Action: Return to AI (Section 34)
        if ($action === 'return_to_ai') {
            require_once __DIR__ . '/events.php';

            $pdo->prepare("
                UPDATE `conversations`
                SET `ownership` = 'ai', `status` = 'ai_handling', `last_message_at` = NOW()
                WHERE `id` = ? AND `company_id` = ?
            ")->execute([$convId, $companyId]);

            $pdo->prepare("
                UPDATE `leads`
                SET `human_attention_required` = 0, `updated_at` = NOW()
                WHERE (`conversation_id` = ? OR `customer_id` = (SELECT customer_id FROM `conversations` WHERE id = ?))
                  AND `company_id` = ?
            ")->execute([$convId, $convId, $companyId]);

            $note = 'Conversation handed back to Autonomous AI (Cai).';
            $lastMsgCheck = $pdo->prepare("SELECT message_text FROM `messages` WHERE `conversation_id` = ? ORDER BY id DESC LIMIT 1");
            $lastMsgCheck->execute([$convId]);
            $lastMsg = $lastMsgCheck->fetchColumn();
            if ($lastMsg !== "[System Event] " . $note) {
                $pdo->prepare("
                    INSERT INTO `messages`
                    (`company_id`, `conversation_id`, `sender_type`, `sender_id`, `message_text`, `created_at`)
                    VALUES (?, ?, 'system', ?, ?, NOW())
                ")->execute([$companyId, $convId, $userId, "[System Event] " . $note]);
            }

            dispatchSystemEvent($pdo, $companyId, 'human.return_to_ai', [
                'conversation_id' => $convId,
                'user_id'         => $userId
            ]);

            echo json_encode([
                'success'   => true,
                'ownership' => 'ai',
                'status'    => 'ai_handling',
                'message'   => $note
            ]);
            exit;
        }

        // Action: Toggle Ownership (AI vs Human)
        if ($action === 'toggle_ownership') {
            require_once __DIR__ . '/events.php';
            $newOwnership = ($conv['ownership'] === 'human') ? 'ai' : 'human';
            $newStatus = ($newOwnership === 'human') ? 'human_active' : 'ai_handling';
            $note = ($newOwnership === 'human') 
                ? 'Human specialist took over conversation.' 
                : 'Conversation handed back to Autonomous AI (Cai).';

            $pdo->prepare("
                UPDATE `conversations`
                SET `ownership` = ?, `status` = ?, `unread_human` = 0, `last_message_at` = NOW()
                WHERE `id` = ? AND `company_id` = ?
            ")->execute([$newOwnership, $newStatus, $convId, $companyId]);

            if ($newOwnership === 'human') {
                $pdo->prepare("
                    UPDATE `leads`
                    SET `human_attention_required` = 0, `assigned_user_id` = COALESCE(?, `assigned_user_id`), `updated_at` = NOW()
                    WHERE (`conversation_id` = ? OR `customer_id` = (SELECT customer_id FROM `conversations` WHERE id = ?))
                      AND `company_id` = ?
                ")->execute([$userId, $convId, $convId, $companyId]);

                $pdo->prepare("
                    UPDATE `lead_artifacts`
                    SET `human_attention_status` = 'resolved', `updated_at` = NOW()
                    WHERE (`lead_id` = (SELECT id FROM `leads` WHERE conversation_id = ? LIMIT 1)
                       OR `customer_id` = (SELECT customer_id FROM `conversations` WHERE id = ?))
                      AND `company_id` = ?
                ")->execute([$convId, $convId, $companyId]);

                dispatchSystemEvent($pdo, $companyId, 'human.takeover', [
                    'conversation_id' => $convId,
                    'user_id'         => $userId
                ]);
            } else {
                $pdo->prepare("
                    UPDATE `leads`
                    SET `human_attention_required` = 0, `updated_at` = NOW()
                    WHERE (`conversation_id` = ? OR `customer_id` = (SELECT customer_id FROM `conversations` WHERE id = ?))
                      AND `company_id` = ?
                ")->execute([$convId, $convId, $companyId]);

                dispatchSystemEvent($pdo, $companyId, 'human.return_to_ai', [
                    'conversation_id' => $convId,
                    'user_id'         => $userId
                ]);
            }

            $lastMsgCheck = $pdo->prepare("SELECT message_text FROM `messages` WHERE `conversation_id` = ? ORDER BY id DESC LIMIT 1");
            $lastMsgCheck->execute([$convId]);
            $lastMsg = $lastMsgCheck->fetchColumn();
            if ($lastMsg !== "[System Event] " . $note) {
                $pdo->prepare("
                    INSERT INTO `messages`
                    (`company_id`, `conversation_id`, `sender_type`, `sender_id`, `message_text`, `created_at`)
                    VALUES (?, ?, 'human', ?, ?, NOW())
                ")->execute([$companyId, $convId, $userId, "[System Event] " . $note]);
            }

            echo json_encode([
                'success'   => true,
                'ownership' => $newOwnership,
                'status'    => $newStatus,
                'message'   => $note
            ]);
            exit;
        }

        // Action: Toggle Status (Resolved vs Active)
        if ($action === 'toggle_status') {
            $isResolved = ($conv['status'] === 'resolved');
            $newStatus = $isResolved ? ($conv['ownership'] === 'human' ? 'human_active' : 'ai_handling') : 'resolved';
            $note = $isResolved ? 'Conversation reopened by counselor.' : 'Conversation marked as resolved.';

            $pdo->prepare("
                UPDATE `conversations`
                SET `status` = ?, `last_message_at` = NOW()
                WHERE `id` = ? AND `company_id` = ?
            ")->execute([$newStatus, $convId, $companyId]);

            $lastMsgCheck = $pdo->prepare("SELECT message_text FROM `messages` WHERE `conversation_id` = ? ORDER BY id DESC LIMIT 1");
            $lastMsgCheck->execute([$convId]);
            $lastMsg = $lastMsgCheck->fetchColumn();
            if ($lastMsg !== "[System Event] " . $note) {
                $pdo->prepare("
                    INSERT INTO `messages`
                    (`company_id`, `conversation_id`, `sender_type`, `sender_id`, `message_text`, `created_at`)
                    VALUES (?, ?, 'human', ?, ?, NOW())
                ")->execute([$companyId, $convId, $userId, "[System Event] " . $note]);
            }

            echo json_encode([
                'success' => true,
                'status'  => $newStatus,
                'message' => $note
            ]);
            exit;
        }

        // Action: Assign Lead & Conversation to Teammate or Autonomous AI
        if ($action === 'assign') {
            $assignedUserId = isset($input['assigned_user_id']) ? (int)$input['assigned_user_id'] : 0;

            if ($assignedUserId > 0) {
                // Verify user belongs to company
                $uStmt = $pdo->prepare("SELECT id, name, role FROM `users` WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1");
                $uStmt->execute([$assignedUserId, $companyId]);
                $assignee = $uStmt->fetch();

                if (!$assignee) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'error' => 'Team member not found']);
                    exit;
                }

                $pdo->prepare("
                    UPDATE `conversations`
                    SET `assigned_user_id` = ?, `ownership` = 'human', `status` = 'human_active', `last_message_at` = NOW()
                    WHERE `id` = ? AND `company_id` = ?
                ")->execute([$assignedUserId, $convId, $companyId]);

                $pdo->prepare("
                    UPDATE `leads`
                    SET `assigned_user_id` = ?, `updated_at` = NOW()
                    WHERE (`conversation_id` = ? OR `customer_id` = (SELECT customer_id FROM `conversations` WHERE id = ?))
                      AND `company_id` = ?
                ")->execute([$assignedUserId, $convId, $convId, $companyId]);

                $note = "Assigned to " . $assignee['name'] . " (" . ucfirst($assignee['role']) . ").";
                $pdo->prepare("
                    INSERT INTO `messages`
                    (`company_id`, `conversation_id`, `sender_type`, `sender_id`, `message_text`, `created_at`)
                    VALUES (?, ?, 'system', ?, ?, NOW())
                ")->execute([$companyId, $convId, $userId, "[System Event] " . $note]);

                echo json_encode([
                    'success'          => true,
                    'assigned_user_id' => $assignedUserId,
                    'assigned_name'    => $assignee['name'],
                    'ownership'        => 'human',
                    'message'          => $note
                ]);
                exit;
            } else {
                // Return to Autonomous AI
                $pdo->prepare("
                    UPDATE `conversations`
                    SET `assigned_user_id` = NULL, `ownership` = 'ai', `status` = 'ai_handling', `last_message_at` = NOW()
                    WHERE `id` = ? AND `company_id` = ?
                ")->execute([$convId, $companyId]);

                $pdo->prepare("
                    UPDATE `leads`
                    SET `assigned_user_id` = NULL, `updated_at` = NOW()
                    WHERE (`conversation_id` = ? OR `customer_id` = (SELECT customer_id FROM `conversations` WHERE id = ?))
                      AND `company_id` = ?
                ")->execute([$convId, $convId, $companyId]);

                $note = "Conversation handed over to Autonomous AI (Cai).";
                $pdo->prepare("
                    INSERT INTO `messages`
                    (`company_id`, `conversation_id`, `sender_type`, `sender_id`, `message_text`, `created_at`)
                    VALUES (?, ?, 'system', ?, ?, NOW())
                ")->execute([$companyId, $convId, $userId, "[System Event] " . $note]);

                echo json_encode([
                    'success'          => true,
                    'assigned_user_id' => 0,
                    'assigned_name'    => 'Autonomous AI (Cai)',
                    'ownership'        => 'ai',
                    'message'          => $note
                ]);
                exit;
            }
        }

        // Action: Update Lead Dossier (Stage, Priority, Value, Contact Details)
        if ($action === 'update_lead') {
            $stage = trim($input['stage'] ?? '');
            $priority = trim($input['priority'] ?? '');
            $dealValue = isset($input['opportunity_value']) ? (int)$input['opportunity_value'] : null;
            $customerName = trim($input['customer_name'] ?? '');
            $customerPhone = trim($input['customer_phone'] ?? '');
            $customerEmail = trim($input['customer_email'] ?? '');
            $customerCity = trim($input['customer_city'] ?? '');
            $notes = trim($input['ai_summary'] ?? '');

            // Update customer details if provided
            $cUpdates = [];
            $cParams = [];
            if ($customerName !== '') { $cUpdates[] = "`name` = ?"; $cParams[] = $customerName; }
            if ($customerPhone !== '') { $cUpdates[] = "`phone` = ?"; $cParams[] = $customerPhone; }
            if ($customerEmail !== '') { $cUpdates[] = "`email` = ?"; $cParams[] = $customerEmail; }
            if ($customerCity !== '') { $cUpdates[] = "`city` = ?"; $cParams[] = $customerCity; }

            if (!empty($cUpdates)) {
                $cParams[] = $convId;
                $cParams[] = $companyId;
                $pdo->prepare("
                    UPDATE `customers`
                    SET " . implode(", ", $cUpdates) . "
                    WHERE id = (SELECT customer_id FROM `conversations` WHERE id = ? AND company_id = ?)
                ")->execute($cParams);
            }

            // Update lead details
            $lUpdates = [];
            $lParams = [];
            if ($stage !== '') { $lUpdates[] = "`stage_name` = ?"; $lParams[] = strtoupper($stage); }
            if ($priority !== '') {
                $lUpdates[] = "`priority` = ?"; $lParams[] = strtoupper($priority);
                $lUpdates[] = "`intent_level` = ?"; $lParams[] = strtolower($priority);
            }
            if ($dealValue !== null) { $lUpdates[] = "`opportunity_value` = ?"; $lParams[] = $dealValue; }
            if ($notes !== '') { $lUpdates[] = "`ai_summary` = ?"; $lParams[] = $notes; }

            if (!empty($lUpdates)) {
                $lUpdates[] = "`updated_at` = NOW()";
                $lParams[] = $convId;
                $lParams[] = $convId;
                $lParams[] = $companyId;
                $pdo->prepare("
                    UPDATE `leads`
                    SET " . implode(", ", $lUpdates) . "
                    WHERE (`conversation_id` = ? OR `customer_id` = (SELECT customer_id FROM `conversations` WHERE id = ?))
                      AND `company_id` = ?
                ")->execute($lParams);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Lead dossier updated successfully'
            ]);
            exit;
        }

        // Action: Send Message
        $messageText = trim($input['message'] ?? '');
        if (empty($messageText)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Message text cannot be empty']);
            exit;
        }

        // Insert human counselor message
        $pdo->prepare("
            INSERT INTO `messages`
            (`company_id`, `conversation_id`, `sender_type`, `sender_id`, `message_text`, `created_at`)
            VALUES (?, ?, 'human', ?, ?, NOW())
        ")->execute([$companyId, $convId, $userId, $messageText]);

        // Update conversation
        $pdo->prepare("
            UPDATE `conversations`
            SET `ownership` = 'human',
                `status` = 'human_active',
                `last_message_preview` = ?,
                `last_message_at` = NOW()
            WHERE `id` = ? AND `company_id` = ?
        ")->execute([substr($messageText, 0, 150), $convId, $companyId]);

        echo json_encode([
            'success' => true,
            'message' => [
                'sender_type' => 'human',
                'text'        => $messageText,
                'time'        => date('h:i A')
            ]
        ]);
        exit;
    }

    // 3. List Conversations with Smart Filters
    $filter = trim($_GET['filter'] ?? 'all');
    $search = trim($_GET['search'] ?? '');
    $where = ["conv.company_id = ?"];
    $params = [$companyId];

    if ($filter === 'widget') {
        $where[] = "conv.channel = 'widget'";
    } elseif ($filter === 'whatsapp') {
        $where[] = "conv.channel = 'whatsapp'";
    } elseif ($filter === 'ai') {
        $where[] = "conv.ownership = 'ai' AND conv.status NOT IN ('resolved', 'closed')";
    } elseif ($filter === 'human' || $filter === 'human_attention' || $filter === 'attention') {
        $where[] = "(conv.ownership = 'human' OR conv.status IN ('human_requested', 'human_active') OR l.human_attention_required = 1) AND conv.status NOT IN ('resolved', 'closed')";
    } elseif ($filter === 'high_intent') {
        $where[] = "(l.priority IN ('HIGH', 'URGENT') OR l.intent_level IN ('high', 'urgent'))";
    } elseif ($filter === 'open') {
        $where[] = "conv.status NOT IN ('resolved', 'closed')";
    } elseif ($filter === 'resolved') {
        $where[] = "conv.status = 'resolved'";
    } elseif ($filter === 'closed') {
        $where[] = "conv.status = 'closed'";
    }

    if (!empty($search)) {
        $where[] = "(c.name LIKE ? OR c.phone LIKE ? OR conv.last_message_preview LIKE ?)";
        $searchTerm = "%{$search}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    $whereSql = implode(" AND ", $where);

    $listStmt = $pdo->prepare("
        SELECT conv.*, c.name AS customer_name, c.phone AS customer_phone, c.email AS customer_email, c.city AS customer_city,
               l.id AS lead_id, l.priority, l.stage_name, l.opportunity_value, l.ai_summary, l.intent_level,
               l.human_attention_required, l.human_attention_reason,
               DATE_FORMAT(conv.last_message_at, '%h:%i %p') AS last_time
        FROM `conversations` conv
        LEFT JOIN `customers` c ON c.id = conv.customer_id
        LEFT JOIN `leads` l ON l.conversation_id = conv.id
        WHERE {$whereSql}
        ORDER BY conv.last_message_at DESC
        LIMIT 50
    ");
    $listStmt->execute($params);
    $conversations = $listStmt->fetchAll();

    // Comprehensive Vision-Aligned Counts
    $cStmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN conv.channel = 'widget' THEN 1 ELSE 0 END) AS widget_count,
            SUM(CASE WHEN conv.channel = 'whatsapp' THEN 1 ELSE 0 END) AS wa_count,
            SUM(CASE WHEN conv.ownership = 'ai' AND conv.status NOT IN ('resolved', 'closed') THEN 1 ELSE 0 END) AS ai_count,
            SUM(CASE WHEN (conv.ownership = 'human' OR conv.status IN ('human_requested', 'human_active') OR l.human_attention_required = 1) AND conv.status NOT IN ('resolved', 'closed') THEN 1 ELSE 0 END) AS human_count,
            SUM(CASE WHEN (l.human_attention_required = 1 OR conv.status = 'human_requested') AND conv.status NOT IN ('resolved', 'closed') THEN 1 ELSE 0 END) AS attention_count,
            SUM(CASE WHEN conv.status NOT IN ('resolved', 'closed') THEN 1 ELSE 0 END) AS open_count,
            SUM(CASE WHEN conv.status = 'resolved' THEN 1 ELSE 0 END) AS resolved_count,
            SUM(CASE WHEN conv.status = 'closed' THEN 1 ELSE 0 END) AS closed_count,
            SUM(CASE WHEN (l.priority IN ('HIGH', 'URGENT') OR l.intent_level IN ('high', 'urgent')) THEN 1 ELSE 0 END) AS high_intent_count
        FROM `conversations` conv
        LEFT JOIN `leads` l ON l.conversation_id = conv.id
        WHERE conv.company_id = ?
    ");
    $cStmt->execute([$companyId]);
    $counts = $cStmt->fetch();

    echo json_encode([
        'success'       => true,
        'counts'        => [
            'total'       => (int)($counts['total'] ?? 0),
            'ai'          => (int)($counts['ai_count'] ?? 0),
            'human'       => (int)($counts['human_count'] ?? 0),
            'attention'   => (int)($counts['attention_count'] ?? 0),
            'high_intent' => (int)($counts['high_intent_count'] ?? 0),
            'widget'      => (int)($counts['widget_count'] ?? 0),
            'whatsapp'    => (int)($counts['wa_count'] ?? 0),
            'open'        => (int)($counts['open_count'] ?? 0),
            'resolved'    => (int)($counts['resolved_count'] ?? 0),
            'closed'      => (int)($counts['closed_count'] ?? 0),
        ],
        'conversations' => array_map(function($row) {
            $name = $row['customer_name'] ?: 'Website Visitor';
            $initials = '';
            $parts = explode(' ', trim($name));
            foreach (array_slice($parts, 0, 2) as $p) {
                $initials .= strtoupper(substr($p, 0, 1));
            }
            if (empty($initials)) $initials = 'WV';

            return [
                'id'                       => (int)$row['id'],
                'initials'                 => $initials,
                'customer_name'            => $name,
                'customer_phone'           => $row['customer_phone'] ?? '',
                'channel'                  => $row['channel'],
                'status'                   => $row['status'],
                'ownership'                => $row['ownership'],
                'priority'                 => $row['priority'] ?? 'MEDIUM',
                'intent_level'             => $row['intent_level'] ?? 'medium',
                'stage'                    => $row['stage_name'] ?? 'NEW',
                'human_attention_required' => (bool)($row['human_attention_required'] ?? false) || $row['status'] === 'human_requested',
                'human_attention_reason'   => $row['human_attention_reason'] ?? '',
                'last_message_preview'     => $row['last_message_preview'] ?: 'Conversation opened',
                'last_time'                => $row['last_time'] ?: date('h:i A', strtotime($row['created_at'])),
                'unread_human'             => (bool)$row['unread_human']
            ];
        }, $conversations)
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Conversations API Error: ' . $e->getMessage()
    ]);
}

