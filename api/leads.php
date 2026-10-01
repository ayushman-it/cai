<?php
/**
 * CUBOIDPILOT — LEADS & CRM API (Sections 13, 14, 16, 29 & 44)
 * Strict multi-tenant queries for CRM leads, lead dossiers, and assignments.
 */

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/entitlements.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$pdo = getDbConnection();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];

try {
    $entitlements = getCompanyEntitlements($pdo, $companyId);

    $action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

    // 1. Single Lead Dossier (for app/lead-detail.html)
    if ($action === 'get') {
        $leadId = (int)($_GET['id'] ?? 0);
        if (!$leadId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Lead ID required']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT l.*, c.name AS customer_name, c.phone AS customer_phone, c.email AS customer_email,
                   c.city AS customer_city, c.whatsapp_number, c.notes AS customer_notes,
                   u.name AS assigned_user_name, u.email AS assigned_user_email, u.avatar_url AS assigned_user_avatar,
                   ps.name AS stage_title, ps.color_code AS stage_color
            FROM `leads` l
            LEFT JOIN `customers` c ON c.id = l.customer_id
            LEFT JOIN `users` u ON u.id = l.assigned_user_id
            LEFT JOIN `pipeline_stages` ps ON ps.id = l.stage_id
            WHERE l.id = ? AND l.company_id = ?
            LIMIT 1
        ");
        $stmt->execute([$leadId, $companyId]);
        $lead = $stmt->fetch();

        if (!$lead) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Lead not found in this workspace']);
            exit;
        }

        // Fetch AI Lead Artifact (Section 7)
        $artStmt = $pdo->prepare("SELECT * FROM `lead_artifacts` WHERE `lead_id` = ? AND `company_id` = ? LIMIT 1");
        $artStmt->execute([$leadId, $companyId]);
        $artifact = $artStmt->fetch(PDO::FETCH_ASSOC);

        // Fetch Custom Field Values (Section 41)
        $cfStmt = $pdo->prepare("
            SELECT ccf.field_key, ccf.field_label, ccf.field_type, lcfv.field_value
            FROM company_custom_fields ccf
            LEFT JOIN lead_custom_field_values lcfv ON lcfv.field_id = ccf.id AND lcfv.lead_id = ?
            WHERE ccf.company_id = ?
            ORDER BY ccf.id ASC
        ");
        $cfStmt->execute([$leadId, $companyId]);
        $customFields = $cfStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch journey events (Section 44)
        $evStmt = $pdo->prepare("
            SELECT id, event_type, description, event_data_json, created_at,
                   DATE_FORMAT(created_at, '%h:%i %p') AS time_formatted
            FROM `lead_events`
            WHERE `lead_id` = ? AND `company_id` = ?
            ORDER BY id ASC
        ");
        $evStmt->execute([$leadId, $companyId]);
        $events = $evStmt->fetchAll();

        // Fetch installments if any (Section 37)
        $instStmt = $pdo->prepare("
            SELECT * FROM `installments` 
            WHERE `lead_id` = ? AND `company_id` = ? 
            ORDER BY `due_date` ASC
        ");
        $instStmt->execute([$leadId, $companyId]);
        $installments = $instStmt->fetchAll();

        echo json_encode([
            'success'      => true,
            'lead'         => [
                'id'                 => (int)$lead['id'],
                'title'              => $lead['title'] ?: ($lead['customer_name'] ?: 'Prospect'),
                'customer_id'        => (int)$lead['customer_id'],
                'conversation_id'    => (int)$lead['conversation_id'],
                'customer_name'      => $lead['customer_name'] ?: $lead['title'],
                'customer_phone'     => $lead['customer_phone'] ?? '',
                'customer_email'     => $lead['customer_email'] ?? '',
                'customer_city'      => $lead['customer_city'] ?? '',
                'stage'              => $lead['stage_name'] ?: ($lead['stage_title'] ?: 'NEW'),
                'stage_color'        => $lead['stage_color'] ?? '#10b981',
                'priority'           => $lead['priority'] ?? 'MEDIUM',
                'opportunity_value'  => (int)$lead['opportunity_value'],
                'total_amount'       => (int)($lead['total_amount'] ?? $lead['opportunity_value']),
                'paid_amount'        => (int)($lead['paid_amount'] ?? 0),
                'remaining_amount'   => (int)($lead['remaining_amount'] ?? 0),
                'commercial_status'  => $lead['commercial_status'] ?? 'PENDING',
                'source'             => $lead['source'] ?? 'Website Widget',
                'status'             => $lead['status'],
                'ai_summary'         => $lead['ai_summary'] ?: ($lead['radar_reason'] ?: 'Inquired via Website AI Widget'),
                'recommended_action' => $lead['radar_recommended_action'] ?: 'Follow up via counselor briefing',
                'assigned_user_id'   => $lead['assigned_user_id'] ? (int)$lead['assigned_user_id'] : null,
                'assigned_user_name' => $lead['assigned_user_name'] ?: 'Unassigned',
                'assigned_user_avatar' => $lead['assigned_user_avatar'] ?? null,
                'human_attention_required' => (bool)($lead['human_attention_required'] ?? false),
                'human_attention_reason'   => $lead['human_attention_reason'] ?? '',
                'lead_score'         => $artifact ? (int)$artifact['lead_score'] : (int)($lead['lead_score'] ?? 0),
                'priority_reason'    => $artifact ? $artifact['priority_reason'] : '',
                'last_activity_at'   => $lead['last_activity_at'],
                'created_at'         => $lead['created_at']
            ],
            'artifact'     => $artifact ? [
                'score'               => (int)$artifact['lead_score'],
                'priority'            => $artifact['priority'],
                'priority_reason'     => $artifact['priority_reason'],
                'buying_signals'      => !empty($artifact['buying_signals_json']) ? json_decode($artifact['buying_signals_json'], true) : [],
                'objections'          => !empty($artifact['objections_json']) ? json_decode($artifact['objections_json'], true) : [],
                'missing_information' => !empty($artifact['missing_information_json']) ? json_decode($artifact['missing_information_json'], true) : [],
                'recommended_action'  => $artifact['recommended_action'],
                'summary'             => $artifact['conversation_summary'],
                'human_attention'     => $artifact['human_attention_status']
            ] : null,
            'custom_fields'=> $customFields,
            'events'       => array_map(function($ev) {
                return [
                    'id'          => (int)$ev['id'],
                    'event_type'  => $ev['event_type'],
                    'description' => $ev['description'],
                    'time'        => $ev['time_formatted'],
                    'created_at'  => $ev['created_at']
                ];
            }, $events),
            'installments' => $installments
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Assign Lead (Section 29)
    if ($action === 'assign') {
        checkEntitlement($pdo, $companyId, 'can_assign_leads', true);

        $leadId = (int)($_POST['lead_id'] ?? 0);
        $assigneeId = (int)($_POST['assigned_user_id'] ?? 0);

        if (!$leadId || !$assigneeId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Lead ID and Assignee ID are required']);
            exit;
        }

        // Verify assignee belongs to company
        $uCheck = $pdo->prepare("SELECT id, name, phone, email, role FROM `users` WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1");
        $uCheck->execute([$assigneeId, $companyId]);
        $assignee = $uCheck->fetch();

        if (!$assignee) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Team member not found in this company']);
            exit;
        }

        // Update lead
        $pdo->prepare("UPDATE `leads` SET `assigned_user_id` = ?, `updated_at` = NOW() WHERE `id` = ? AND `company_id` = ?")
            ->execute([$assigneeId, $leadId, $companyId]);

        // Log assignment event
        $pdo->prepare("
            INSERT INTO `lead_events`
            (`company_id`, `lead_id`, `event_type`, `description`, `event_data_json`, `created_at`)
            VALUES (?, ?, 'HUMAN_TAKEOVER', ?, ?, NOW())
        ")->execute([
            $companyId,
            $leadId,
            "Assigned to {$assignee['name']} ({$assignee['role']})",
            json_encode(['assigned_to' => $assignee['name'], 'assigned_by_user_id' => $userId])
        ]);

        // Dispatch Salesperson Alert if enabled (Section 30)
        require_once __DIR__ . '/alerts.php';
        sendSalespersonAssignmentAlert($pdo, $companyId, $leadId, $assignee);

        echo json_encode([
            'success'            => true,
            'lead_id'            => $leadId,
            'assigned_user_id'   => $assigneeId,
            'assigned_user_name' => $assignee['name']
        ]);
        exit;
    }

    // 3. Update Lead Stage / Priority
    if ($action === 'update_stage') {
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $stageName = strtoupper(trim($_POST['stage_name'] ?? ''));
        $priority = strtoupper(trim($_POST['priority'] ?? ''));
        $status = strtolower(trim($_POST['status'] ?? ''));

        if (!$leadId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Lead ID required']);
            exit;
        }

        $fields = ["`updated_at` = NOW()"];
        $params = [];

        if (!empty($stageName)) {
            $fields[] = "`stage_name` = ?";
            $params[] = $stageName;

            // Map stage_id
            $stgStmt = $pdo->prepare("SELECT id FROM `pipeline_stages` WHERE `company_id` = ? AND (UPPER(`name`) LIKE ? OR `slug` = ?) LIMIT 1");
            $stgStmt->execute([$companyId, "%{$stageName}%", strtolower($stageName)]);
            if ($stg = $stgStmt->fetch()) {
                $fields[] = "`stage_id` = ?";
                $params[] = (int)$stg['id'];
            }
        }
        if (!empty($priority)) {
            $fields[] = "`priority` = ?";
            $fields[] = "`intent_level` = ?";
            $params[] = $priority;
            $params[] = strtolower($priority);
        }
        if (!empty($status) && in_array($status, ['open', 'won', 'lost'])) {
            $fields[] = "`status` = ?";
            $params[] = $status;
        }

        $params[] = $leadId;
        $params[] = $companyId;

        $sql = "UPDATE `leads` SET " . implode(", ", $fields) . " WHERE `id` = ? AND `company_id` = ?";
        $pdo->prepare($sql)->execute($params);

        $pdo->prepare("
            INSERT INTO `lead_events` (`company_id`, `lead_id`, `event_type`, `description`, `created_at`)
            VALUES (?, ?, 'LEAD_WON', ?, NOW())
        ")->execute([$companyId, $leadId, "Lead updated: {$stageName} ({$priority})"]);

        echo json_encode(['success' => true]);
        exit;
    }

    // 3.5 Create Lead / Contact
    if ($action === 'create') {
        $inputRaw = file_get_contents('php://input');
        $body = json_decode($inputRaw, true) ?? $_POST;

        $name = trim($body['name'] ?? '');
        $email = trim($body['email'] ?? '');
        $phone = trim($body['phone'] ?? '');
        $city = trim($body['city'] ?? '');
        $notes = trim($body['notes'] ?? '');
        $value = (int)($body['opportunity_value'] ?? 0);
        $stageName = trim($body['stage'] ?? 'NEW');
        $priority = strtoupper(trim($body['priority'] ?? 'MEDIUM'));

        if (empty($name)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Contact name is required']);
            exit;
        }

        // Create Customer
        $custUuid = 'cust_' . bin2hex(random_bytes(10));
        $custStmt = $pdo->prepare("
            INSERT INTO `customers`
            (`company_id`, `customer_uuid`, `name`, `email`, `phone`, `city`, `notes`, `first_seen_at`, `last_seen_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $custStmt->execute([$companyId, $custUuid, $name, $email ?: null, $phone ?: null, $city ?: null, $notes ?: null]);
        $customerId = (int)$pdo->lastInsertId();

        // Create Lead
        $leadStmt = $pdo->prepare("
            INSERT INTO `leads`
            (`company_id`, `customer_id`, `title`, `stage_name`, `priority`, `intent_level`, `opportunity_value`, `source`, `status`, `ai_summary`, `created_at`, `updated_at`, `last_activity_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Direct CRM', 'open', ?, NOW(), NOW(), NOW())
        ");
        $aiSummary = $notes ?: "Contact created manually via CRM";
        $leadStmt->execute([$companyId, $customerId, $name, $stageName, $priority, strtolower($priority), $value, $aiSummary]);
        $leadId = (int)$pdo->lastInsertId();

        echo json_encode(['success' => true, 'lead_id' => $leadId, 'message' => 'Contact created successfully']);
        exit;
    }

    // 3.6 Update Lead / Contact Details
    if ($action === 'update') {
        $inputRaw = file_get_contents('php://input');
        $body = json_decode($inputRaw, true) ?? $_POST;

        $leadId = (int)($body['lead_id'] ?? $body['id'] ?? 0);
        $name = trim($body['name'] ?? '');
        $email = trim($body['email'] ?? '');
        $phone = trim($body['phone'] ?? '');
        $city = trim($body['city'] ?? '');
        $notes = trim($body['notes'] ?? '');
        $value = isset($body['opportunity_value']) ? (int)$body['opportunity_value'] : null;
        $priority = !empty($body['priority']) ? strtoupper(trim($body['priority'])) : null;
        $stageName = trim($body['stage'] ?? '');

        if (!$leadId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Lead ID is required']);
            exit;
        }

        $chk = $pdo->prepare("SELECT customer_id FROM `leads` WHERE id = ? AND company_id = ? LIMIT 1");
        $chk->execute([$leadId, $companyId]);
        $curr = $chk->fetch();
        if (!$curr) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Lead not found in this workspace']);
            exit;
        }

        if (!empty($name)) {
            $pdo->prepare("UPDATE `leads` SET `title` = ?, `updated_at` = NOW() WHERE id = ? AND company_id = ?")->execute([$name, $leadId, $companyId]);
        }
        if ($value !== null) {
            $pdo->prepare("UPDATE `leads` SET `opportunity_value` = ?, `updated_at` = NOW() WHERE id = ? AND company_id = ?")->execute([$value, $leadId, $companyId]);
        }
        if ($priority !== null) {
            $pdo->prepare("UPDATE `leads` SET `priority` = ?, `intent_level` = ?, `updated_at` = NOW() WHERE id = ? AND company_id = ?")->execute([$priority, strtolower($priority), $leadId, $companyId]);
        }
        if (!empty($stageName)) {
            $pdo->prepare("UPDATE `leads` SET `stage_name` = ?, `updated_at` = NOW() WHERE id = ? AND company_id = ?")->execute([$stageName, $leadId, $companyId]);
        }
        if (!empty($notes)) {
            $pdo->prepare("UPDATE `leads` SET `ai_summary` = ?, `updated_at` = NOW() WHERE id = ? AND company_id = ?")->execute([$notes, $leadId, $companyId]);
        }

        if (!empty($curr['customer_id'])) {
            $custUpdates = [];
            $custParams = [];
            if ($name !== '') { $custUpdates[] = "`name` = ?"; $custParams[] = $name; }
            if ($email !== '') { $custUpdates[] = "`email` = ?"; $custParams[] = $email; }
            if ($phone !== '') { $custUpdates[] = "`phone` = ?"; $custParams[] = $phone; }
            if ($city !== '') { $custUpdates[] = "`city` = ?"; $custParams[] = $city; }
            if ($notes !== '') { $custUpdates[] = "`notes` = ?"; $custParams[] = $notes; }
            if (!empty($custUpdates)) {
                $custUpdates[] = "`last_seen_at` = NOW()";
                $custParams[] = $curr['customer_id'];
                $custParams[] = $companyId;
                $pdo->prepare("UPDATE `customers` SET " . implode(', ', $custUpdates) . " WHERE id = ? AND company_id = ?")->execute($custParams);
            }
        }

        echo json_encode(['success' => true, 'message' => 'Contact updated successfully']);
        exit;
    }

    // 3.7 Delete Lead / Contact
    if ($action === 'delete' || $action === 'delete_batch') {
        $inputRaw = file_get_contents('php://input');
        $body = json_decode($inputRaw, true) ?? $_POST;

        $targetIds = [];
        if (!empty($body['lead_ids']) && is_array($body['lead_ids'])) {
            $targetIds = $body['lead_ids'];
        } elseif (!empty($body['ids']) && is_array($body['ids'])) {
            $targetIds = $body['ids'];
        } elseif (!empty($_REQUEST['ids']) && is_array($_REQUEST['ids'])) {
            $targetIds = $_REQUEST['ids'];
        } else {
            $singleId = (int)($body['lead_id'] ?? $body['id'] ?? $_REQUEST['lead_id'] ?? $_REQUEST['id'] ?? 0);
            if ($singleId > 0) $targetIds[] = $singleId;
        }

        $cleanIds = array_values(array_filter(array_unique(array_map('intval', $targetIds))));
        if (empty($cleanIds)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Lead ID(s) required for deletion']);
            exit;
        }

        $inPlaceholders = implode(',', array_fill(0, count($cleanIds), '?'));
        $custParams = array_merge($cleanIds, [$companyId]);
        
        // Find associated conversation IDs and customer IDs
        $leadRefStmt = $pdo->prepare("SELECT DISTINCT conversation_id, customer_id FROM `leads` WHERE id IN ($inPlaceholders) AND company_id = ?");
        $leadRefStmt->execute($custParams);
        $leadRefs = $leadRefStmt->fetchAll(PDO::FETCH_ASSOC);

        $convIdsToDelete = [];
        $custIds = [];
        foreach ($leadRefs as $ref) {
            if (!empty($ref['conversation_id'])) {
                $convIdsToDelete[] = (int)$ref['conversation_id'];
            }
            if (!empty($ref['customer_id'])) {
                $custIds[] = (int)$ref['customer_id'];
            }
        }
        $custIds = array_values(array_unique(array_filter($custIds)));

        // User requirement: "agar mai lead delete kru to Conversations sse bhi hona chahiye"
        // Also cascade delete conversations linked to these customers
        if (!empty($custIds)) {
            $cPlaceholders = implode(',', array_fill(0, count($custIds), '?'));
            $convFindStmt = $pdo->prepare("SELECT id FROM `conversations` WHERE customer_id IN ($cPlaceholders) AND company_id = ?");
            $convFindStmt->execute(array_merge($custIds, [$companyId]));
            $foundConvIds = $convFindStmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($foundConvIds as $fcId) {
                $convIdsToDelete[] = (int)$fcId;
            }
        }
        $convIdsToDelete = array_values(array_unique(array_filter($convIdsToDelete)));

        // Delete messages and conversations
        if (!empty($convIdsToDelete)) {
            $convPlaceholders = implode(',', array_fill(0, count($convIdsToDelete), '?'));
            $convParams = array_merge($convIdsToDelete, [$companyId]);

            $delMsg = $pdo->prepare("DELETE FROM `messages` WHERE conversation_id IN ($convPlaceholders) AND company_id = ?");
            $delMsg->execute($convParams);

            $delConv = $pdo->prepare("DELETE FROM `conversations` WHERE id IN ($convPlaceholders) AND company_id = ?");
            $delConv->execute($convParams);
        }

        // Delete from installments
        $delInst = $pdo->prepare("DELETE FROM `installments` WHERE lead_id IN ($inPlaceholders) AND company_id = ?");
        $delInst->execute($custParams);

        // Delete from lead_events
        $delEvents = $pdo->prepare("DELETE FROM `lead_events` WHERE lead_id IN ($inPlaceholders) AND company_id = ?");
        $delEvents->execute($custParams);

        // Delete from leads
        $delLeads = $pdo->prepare("DELETE FROM `leads` WHERE id IN ($inPlaceholders) AND company_id = ?");
        $delLeads->execute($custParams);
        $deletedCount = $delLeads->rowCount();

        // Clean up orphaned customers that have no leads and no conversations
        if (!empty($custIds)) {
            foreach ($custIds as $cId) {
                if (!$cId) continue;
                $chkL = $pdo->prepare("SELECT COUNT(*) FROM `leads` WHERE customer_id = ? AND company_id = ?");
                $chkL->execute([$cId, $companyId]);
                $hasL = (int)$chkL->fetchColumn();

                $chkC = $pdo->prepare("SELECT COUNT(*) FROM `conversations` WHERE customer_id = ? AND company_id = ?");
                $chkC->execute([$cId, $companyId]);
                $hasC = (int)$chkC->fetchColumn();

                if ($hasL === 0 && $hasC === 0) {
                    $pdo->prepare("DELETE FROM `customers` WHERE id = ? AND company_id = ?")->execute([$cId, $companyId]);
                }
            }
        }

        // Fresh total count
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM `leads` WHERE company_id = ?");
        $cntStmt->execute([$companyId]);
        $remainingTotal = (int)$cntStmt->fetchColumn();

        echo json_encode([
            'success'         => true,
            'deleted_count'   => $deletedCount,
            'remaining_total' => $remainingTotal,
            'message'         => "Successfully deleted {$deletedCount} contact(s)"
        ]);
        exit;
    }

    // 3.8 Export Contacts as CSV
    if ($action === 'export_csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="cuboidpilot_contacts_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Name', 'Phone', 'Email', 'City', 'Stage', 'Priority', 'Opportunity Value (INR)', 'Source', 'Created At']);

        $exportStmt = $pdo->prepare("
            SELECT l.id, COALESCE(c.name, l.title) as name, c.phone, c.email, c.city, l.stage_name, l.priority, l.opportunity_value, l.source, l.created_at
            FROM `leads` l
            LEFT JOIN `customers` c ON c.id = l.customer_id
            WHERE l.company_id = ?
            ORDER BY l.id DESC
        ");
        $exportStmt->execute([$companyId]);
        while ($row = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [
                $row['id'],
                $row['name'],
                $row['phone'] ?: '',
                $row['email'] ?: '',
                $row['city'] ?: '',
                $row['stage_name'],
                $row['priority'],
                $row['opportunity_value'],
                $row['source'],
                $row['created_at']
            ]);
        }
        fclose($out);
        exit;
    }

    // 3.9 Import Contacts (CSV Upload or JSON array)
    if ($action === 'import') {
        $importedCount = 0;
        $rawInput = file_get_contents('php://input');
        $body = json_decode($rawInput, true);

        $contacts = $body['contacts'] ?? [];

        // Check if CSV uploaded via multipart form
        if (empty($contacts) && !empty($_FILES['csv_file']['tmp_name'])) {
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
            $header = fgetcsv($handle);
            while (($data = fgetcsv($handle)) !== false) {
                if (!empty($data[0]) || !empty($data[1])) {
                    $contacts[] = [
                        'name'  => trim($data[0] ?? ($data[1] ?? 'Imported Contact')),
                        'phone' => trim($data[1] ?? ''),
                        'email' => trim($data[2] ?? ''),
                        'city'  => trim($data[3] ?? ''),
                        'stage' => trim($data[4] ?? 'NEW'),
                        'value' => (int)($data[5] ?? 0)
                    ];
                }
            }
            fclose($handle);
        }

        // Check if CSV text pasted directly
        $csvText = $_POST['csv_text'] ?? ($body['csv_text'] ?? null);
        if (empty($contacts) && !empty($csvText)) {
            $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", trim($csvText)));
            if (count($lines) > 0) {
                // Check if first line is a header
                $firstRow = str_getcsv($lines[0]);
                $hasHeader = in_array(strtolower($firstRow[0] ?? ''), ['name', 'full name', 'contact', 'contact name']);
                if ($hasHeader) array_shift($lines);
                
                foreach ($lines as $line) {
                    if (trim($line) === '') continue;
                    $data = str_getcsv($line);
                    if (!empty($data[0])) {
                        $contacts[] = [
                            'name'  => trim($data[0]),
                            'phone' => trim($data[1] ?? ''),
                            'email' => trim($data[2] ?? ''),
                            'city'  => trim($data[3] ?? ''),
                            'stage' => trim($data[4] ?? 'NEW'),
                            'value' => (int)($data[5] ?? 0)
                        ];
                    }
                }
            }
        }

        foreach ($contacts as $c) {
            $cName = trim($c['name'] ?? '');
            if (empty($cName)) continue;
            $cPhone = trim($c['phone'] ?? '');
            $cEmail = trim($c['email'] ?? '');
            $cCity = trim($c['city'] ?? '');
            $cStage = trim($c['stage'] ?? 'NEW');
            $cVal = (int)($c['value'] ?? 0);

            $custUuid = 'cust_' . bin2hex(random_bytes(10));
            $custStmt = $pdo->prepare("
                INSERT INTO `customers` (`company_id`, `customer_uuid`, `name`, `email`, `phone`, `city`, `first_seen_at`, `last_seen_at`)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $custStmt->execute([$companyId, $custUuid, $cName, $cEmail ?: null, $cPhone ?: null, $cCity ?: null]);
            $custId = (int)$pdo->lastInsertId();

            $pdo->prepare("
                INSERT INTO `leads` (`company_id`, `customer_id`, `title`, `stage_name`, `priority`, `intent_level`, `opportunity_value`, `source`, `status`, `ai_summary`, `created_at`, `updated_at`, `last_activity_at`)
                VALUES (?, ?, ?, ?, 'MEDIUM', 'medium', ?, 'CSV Import', 'open', 'Imported via CSV', NOW(), NOW(), NOW())
            ")->execute([$companyId, $custId, $cName, $cStage, $cVal]);

            $importedLeadIds[] = (int)$pdo->lastInsertId();
            $importedCount++;
        }

        echo json_encode([
            'success' => true,
            'imported_count' => $importedCount,
            'imported_leads' => $importedLeadIds ?? [],
            'message' => "Successfully imported {$importedCount} contacts"
        ]);
        exit;
    }

    // 4. Default: List of Leads (for app/leads.html)
    $filter = trim($_GET['filter'] ?? 'all');
    $search = trim($_GET['search'] ?? '');
    $sortBy = strtolower(trim($_GET['sort_by'] ?? 'date'));
    $sortDir = strtolower(trim($_GET['sort_dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 15)));
    $offset = ($page - 1) * $limit;

    $where = ["l.company_id = ?"];
    $params = [$companyId];

    if (!empty($search)) {
        $where[] = "(l.title LIKE ? OR c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.city LIKE ? OR l.ai_summary LIKE ?)";
        $sTerm = "%{$search}%";
        $params[] = $sTerm;
        $params[] = $sTerm;
        $params[] = $sTerm;
        $params[] = $sTerm;
        $params[] = $sTerm;
        $params[] = $sTerm;
    }

    if ($filter === 'new') {
        $where[] = "(l.stage_name = 'NEW' OR l.status = 'open')";
    } elseif ($filter === 'loyal') {
        $where[] = "(l.status = 'won' OR l.commercial_status = 'PAID' OR l.opportunity_value > 0)";
    } elseif ($filter === 'lost') {
        $where[] = "(l.status = 'lost' OR l.stage_name = 'LOST')";
    } elseif ($filter === 'high_intent') {
        $where[] = "(l.priority IN ('HIGH', 'URGENT') OR l.intent_level IN ('high', 'urgent'))";
    } elseif ($filter === 'needs_followup') {
        $where[] = "(l.stage_name = 'HUMAN_REQUIRED' OR l.is_radar_active = 1)";
    } elseif ($filter === 'converted') {
        $where[] = "l.status = 'won'";
    }

    $whereSql = implode(" AND ", $where);

    // Map sort column
    $orderByCol = 'l.id DESC';
    if ($sortBy === 'name') $orderByCol = "COALESCE(c.name, l.title) {$sortDir}";
    elseif ($sortBy === 'email') $orderByCol = "c.email {$sortDir}";
    elseif ($sortBy === 'phone') $orderByCol = "c.phone {$sortDir}";
    elseif ($sortBy === 'status' || $sortBy === 'stage') $orderByCol = "l.stage_name {$sortDir}";
    elseif ($sortBy === 'city') $orderByCol = "c.city {$sortDir}";
    elseif ($sortBy === 'date') $orderByCol = "l.created_at {$sortDir}";

    // Count matching rows
    $filterCountStmt = $pdo->prepare("
        SELECT COUNT(*) as filtered_total
        FROM `leads` l
        LEFT JOIN `customers` c ON c.id = l.customer_id
        WHERE {$whereSql}
    ");
    $filterCountStmt->execute($params);
    $filteredTotal = (int)$filterCountStmt->fetchColumn();

    // Query paginated rows
    $listParams = array_merge($params, [$limit, $offset]);
    $listStmt = $pdo->prepare("
        SELECT l.*, c.name AS customer_name, c.phone AS customer_phone, c.email AS customer_email,
               c.city AS customer_city,
               (SELECT id FROM conversations WHERE customer_id = l.customer_id ORDER BY id DESC LIMIT 1) AS convo_id,
               u.name AS assigned_user_name, u.avatar_url AS assigned_user_avatar
        FROM `leads` l
        LEFT JOIN `customers` c ON c.id = l.customer_id
        LEFT JOIN `users` u ON u.id = l.assigned_user_id
        WHERE {$whereSql}
        ORDER BY {$orderByCol}
        LIMIT ? OFFSET ?
    ");
    // Ensure limit and offset bound as integer
    for ($i = 0; $i < count($params); $i++) {
        $listStmt->bindValue($i + 1, $params[$i]);
    }
    $listStmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
    $listStmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
    $listStmt->execute();
    $leads = $listStmt->fetchAll();

    // Get count stats
    $countStmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN stage_name = 'NEW' OR status = 'open' THEN 1 ELSE 0 END) AS new_count,
            SUM(CASE WHEN status = 'won' OR commercial_status = 'PAID' OR opportunity_value > 0 THEN 1 ELSE 0 END) AS loyal_count,
            SUM(CASE WHEN status = 'lost' OR stage_name = 'LOST' THEN 1 ELSE 0 END) AS lost_count,
            SUM(CASE WHEN priority IN ('HIGH', 'URGENT') OR intent_level IN ('high', 'urgent') THEN 1 ELSE 0 END) AS high_intent_count,
            SUM(CASE WHEN stage_name = 'HUMAN_REQUIRED' OR is_radar_active = 1 THEN 1 ELSE 0 END) AS followup_count,
            SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) AS won_count
        FROM `leads`
        WHERE `company_id` = ?
    ");
    $countStmt->execute([$companyId]);
    $counts = $countStmt->fetch();

    $outputData = [
        'success' => true,
        'pagination' => [
            'page'        => $page,
            'limit'       => $limit,
            'total_leads' => $filteredTotal,
            'total_pages' => max(1, (int)ceil($filteredTotal / $limit))
        ],
        'counts'  => [
            'total'          => (int)($counts['total'] ?? 0),
            'new'            => (int)($counts['new_count'] ?? 0),
            'loyal'          => (int)($counts['loyal_count'] ?? 0),
            'lost'           => (int)($counts['lost_count'] ?? 0),
            'high_intent'    => (int)($counts['high_intent_count'] ?? 0),
            'needs_followup' => (int)($counts['followup_count'] ?? 0),
            'converted'      => (int)($counts['won_count'] ?? 0)
        ],
        'leads'   => array_map(function($row) {
            $name = $row['title'] ?: ($row['customer_name'] ?: 'Prospect');
            $initials = '';
            $words = array_values(array_filter(preg_split('/[\s\-_—–,.]+/u', trim($name)), function($w) {
                return !empty($w) && preg_match('/^\p{L}|\p{N}/u', $w);
            }));
            foreach (array_slice($words, 0, 2) as $p) {
                $initials .= mb_strtoupper(mb_substr($p, 0, 1, 'UTF-8'), 'UTF-8');
            }
            if (empty($initials)) $initials = 'CP';

            return [
                'id'                 => (int)$row['id'],
                'initials'           => $initials,
                'name'               => $name,
                'customer_phone'     => $row['customer_phone'] ? trim($row['customer_phone']) : '',
                'customer_email'     => $row['customer_email'] ? trim($row['customer_email']) : '',
                'city'               => $row['customer_city'] ? trim($row['customer_city']) : '',
                'convo_id'           => !empty($row['convo_id']) ? (int)$row['convo_id'] : null,
                'stage'              => $row['stage_name'] ?? 'NEW',
                'priority'           => $row['priority'] ?? 'MEDIUM',
                'opportunity_value'  => (int)$row['opportunity_value'],
                'ai_summary'         => $row['ai_summary'] ?: ($row['radar_reason'] ?: ''),
                'source'             => $row['source'] ?? 'Website',
                'assigned_user_name' => $row['assigned_user_name'] ?: 'Unassigned',
                'assigned_user_avatar' => $row['assigned_user_avatar'] ?? null,
                'recommended_action' => $row['radar_recommended_action'] ?: 'Follow up with prospect',
                'status'             => $row['status'],
                'created_at'         => $row['created_at'],
                'last_activity_at'   => $row['last_activity_at']
            ];
        }, $leads)
    ];

    $jsonOutput = json_encode($outputData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($jsonOutput === false) {
        echo json_encode(['success' => false, 'error' => 'JSON error: ' . json_last_error_msg()]);
    } else {
        echo $jsonOutput;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Leads API Error: ' . $e->getMessage()
    ]);
}
