<?php
/**
 * CUBOIDPILOT — CONVERSATIONAL AI ENGINE (API)
 * Strict multi-tenant isolation, grounded company knowledge,
 * structured intent qualification, CRM auto-update, and contextual WhatsApp continuation.
 */

error_reporting(0);
ini_set('display_errors', '0');

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Company-Key");

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/entitlements.php';
require_once __DIR__ . '/../includes/appointment_helper.php';
require_once __DIR__ . '/../includes/asset_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
    session_write_close();
}

try {
    $pdo = getDbConnection();

    // Read JSON or POST input
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?? $_POST;

    $companyKey     = trim($data['company_key'] ?? $_SERVER['HTTP_X_COMPANY_KEY'] ?? '');
    $messageText    = trim($data['message'] ?? '');
    $conversationId = !empty($data['conversation_id']) ? (int)$data['conversation_id'] : null;
    $leadId         = !empty($data['lead_id']) ? (int)$data['lead_id'] : null;
    $sessionId      = trim($data['session_id'] ?? '');
    $visitorName    = trim($data['visitor_name'] ?? '');
    $visitorPhone   = trim($data['visitor_phone'] ?? '');
    $visitorEmail   = trim($data['visitor_email'] ?? '');
    $mode           = trim($data['mode'] ?? '');
    $action         = trim($data['action'] ?? '');
    $selectedSlot   = trim($data['slot_datetime'] ?? ($data['selected_slot'] ?? ''));

    if ($action === 'confirm_slot' || !empty($selectedSlot)) {
        if (empty($messageText)) {
            $messageText = "Confirm appointment for " . ($selectedSlot ?: 'selected slot');
        }
    }

    if (empty($messageText)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Message text cannot be empty']);
        exit;
    }

    // 1. Identify Tenant Company (Strict Multi-Tenant Isolation)
    $company = null;
    if ($companyKey === 'cp_live_cuboidpilot' || $companyKey === 'cuboidpilot') {
        $companyKey = 'cp_live_cuboidsoft';
    }
    if (!empty($companyKey) && $companyKey !== 'default') {
        $stmt = $pdo->prepare("
            SELECT * FROM `companies` 
            WHERE `company_key` = ? 
               OR `slug` = ? 
               OR (slug = 'cuboidsoft' AND ? IN ('cp_live_cuboidsoft', 'cp_live_cuboidpilot', 'cuboidsoft', 'cuboidpilot'))
            LIMIT 1
        ");
        $stmt->execute([$companyKey, $companyKey, $companyKey]);
        $company = $stmt->fetch();
    }

    if (!$company && !empty($_SESSION['company_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM `companies` WHERE `id` = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['company_id']]);
        $company = $stmt->fetch();
    }

    if (!$company) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Workspace not found. Check widget configuration.']);
        exit;
    }
    $companyId = (int)$company['id'];
    $entitlements = getCompanyEntitlements($pdo, $companyId);

    // Fetch widget settings for custom assistant name and branding
    $wStmt = $pdo->prepare("SELECT assistant_name, brand_name FROM `widget_settings` WHERE `company_id` = ? LIMIT 1");
    $wStmt->execute([$companyId]);
    $widgetRow = $wStmt->fetch();

    // Fetch AI Brain Configuration & Custom Instructions (Sections 2 & 3)
    $aiConfigStmt = $pdo->prepare("SELECT * FROM `ai_configs` WHERE `company_id` = ? LIMIT 1");
    $aiConfigStmt->execute([$companyId]);
    $aiConfig = $aiConfigStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $assistantName = !empty($aiConfig['ai_name']) ? $aiConfig['ai_name'] : (!empty($widgetRow['assistant_name']) ? $widgetRow['assistant_name'] : 'Cai');
    $brandDisplayName = !empty($widgetRow['brand_name']) && $widgetRow['brand_name'] !== 'Cai' ? $widgetRow['brand_name'] : $company['name'];
    $customInstructions = !empty($aiConfig['custom_instructions']) ? $aiConfig['custom_instructions'] : '';
    $aiTone = !empty($aiConfig['tone']) ? $aiConfig['tone'] : 'warm and consultative';
    $aiObjective = !empty($aiConfig['primary_objective']) ? $aiConfig['primary_objective'] : 'consultative_lead_qualification';

    // 2. Load Active Verified Knowledge Sources (Strict Tenant Isolation)
    $sourcesStmt = $pdo->prepare("
        SELECT type, title, content 
        FROM `knowledge_sources` 
        WHERE `company_id` = ? AND `is_active` = 1
        ORDER BY id DESC
    ");
    $sourcesStmt->execute([$companyId]);
    $knowledgeList = $sourcesStmt->fetchAll();

    $compiledFacts = [];
    foreach ($knowledgeList as $src) {
        $cleanContent = preg_replace('/[\x{FFFD}\x{0000}-\x{001F}\x{007F}]/u', ' ', $src['content']);
        $cleanContent = preg_replace('/[ \t]+/', ' ', $cleanContent);
        $cleanContent = preg_replace('/\n\s*\n+/', "\n", $cleanContent);
        $cleanContent = trim($cleanContent);
        if (mb_strlen($cleanContent) > 3500) {
            $cleanContent = mb_substr($cleanContent, 0, 3500) . "...";
        }
        $compiledFacts[] = "[Source: {$src['title']}]\n{$cleanContent}";
    }

    // Fetch Active Digital Assets for LLM Grounding
    $assetStmt = $pdo->prepare("SELECT id, title, category, keywords, description, file_name FROM `company_assets` WHERE `company_id` = ? AND `is_active` = 1");
    $assetStmt->execute([$companyId]);
    $companyAssetsList = $assetStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($companyAssetsList)) {
        $assetDescriptions = [];
        foreach ($companyAssetsList as $ca) {
            $catLabel = ucfirst(str_replace('_', ' ', $ca['category']));
            $assetDescriptions[] = "- [{$catLabel}] \"{$ca['title']}\" (Keywords: {$ca['keywords']}) Description: {$ca['description']}";
        }
        $compiledFacts[] = "[Verified Downloadable Documents, Syllabi & Brochures]:\n" . implode("\n", $assetDescriptions);
    }

    $knowledgeContext = !empty($compiledFacts) 
        ? implode("\n\n", $compiledFacts)
        : "Business Name: {$brandDisplayName}\nIndustry: {$company['industry']}\nLocation: {$company['city']}, {$company['country']}";

    // 3. Entity Extraction from Visitor Message
    $nonNameTokens = [
        'hi', 'hello', 'hey', 'namaste', 'pranam', 'yo', 'hola', 'gm', 'gn',
        'yes', 'no', 'ok', 'okay', 'sure', 'yep', 'nope', 'fine', 'cool', 'right', 'correct', 'done',
        'course', 'courses', 'syllabus', 'fee', 'fees', 'cost', 'price', 'pricing', 'payment', 'pay',
        'admission', 'admissions', 'batch', 'batches', 'schedule', 'timing', 'time', 'duration',
        'placement', 'placements', 'job', 'jobs', 'interview', 'hiring', 'certificate', 'certification',
        'project', 'projects', 'internship', 'institute', 'company', 'website',
        'python', 'java', 'react', 'reactjs', 'node', 'nodejs', 'mern', 'mean', 'fullstack', 'frontend',
        'backend', 'data', 'science', 'ai', 'ml', 'cloud', 'devops', 'aws', 'azure', 'docker', 'html',
        'css', 'js', 'javascript', 'sql', 'php', 'laravel', 'flutter', 'android', 'ios', 'bca', 'mca', 'btech',
        'kya', 'kyu', 'kyun', 'kaise', 'kaun', 'kon', 'kab', 'kahan', 'kaha', 'kitna', 'kitni', 'kitne',
        'batao', 'bataiye', 'bol', 'bolo', 'chahiye', 'dena', 'lena', 'hai', 'hain', 'hoon', 'hu', 'tha',
        'thi', 'the', 'hum', 'mujhe', 'mera', 'meri', 'mere', 'aap', 'aapka', 'aapke', 'aapki', 'tum',
        'help', 'support', 'inquiry', 'enquiry', 'details', 'info', 'information', 'test', 'prospect',
        'visitor', 'null', 'undefined', 'none', 'demo', 'contact', 'number', 'phone', 'mobile', 'whatsapp', 'call'
    ];

    if (empty($visitorPhone) && preg_match('/(?:\+?91[\s.-]?)?[6-9]\d{9}|\b\d{10}\b|(?:\+?\d{1,3}[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', $messageText, $phoneMatch)) {
        $visitorPhone = preg_replace('/[^0-9+]/', '', $phoneMatch[0]);
    }
    if (empty($visitorEmail) && preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $messageText, $emailMatch)) {
        $visitorEmail = strtolower($emailMatch[0]);
    }

    $isBlacklistedName = function($str) use ($nonNameTokens) {
        $clean = strtolower(trim($str));
        if (empty($clean) || strlen($clean) < 2) return true;
        if (preg_match('/[?!0-9]/', $clean)) return true;
        $words = preg_split('/\s+/', $clean);
        foreach ($words as $w) {
            if (in_array($w, $nonNameTokens, true)) return true;
        }
        return false;
    };

    if (empty($visitorName) || $isBlacklistedName($visitorName)) {
        $visitorName = '';

        // Case A: Explicit intro phrases: "My name is X", "Mera naam X hai", "I am X"
        if (preg_match('/(?:my name is|i am|this is|i\'m|call me|myself|mera naam|naam(?:\s+hai)?)\s+([a-zA-Z]{2,20}(?:\s+[a-zA-Z]{2,20})?)/i', $messageText, $nameMatch)) {
            $rawExtracted = trim($nameMatch[1]);
            $rawExtracted = preg_replace('/\b(hai|hoon|hu|tha|thi|the)\b$/i', '', $rawExtracted);
            $rawExtracted = trim($rawExtracted);
            if (!$isBlacklistedName($rawExtracted)) {
                $visitorName = ucwords($rawExtracted);
            }
        }
        // Case A2: Name with comma/dash/here prefix: "Ayushman, fees kitni hai?" or "Ayushman here"
        elseif (preg_match('/^([a-zA-Z]{2,20}(?:\s+[a-zA-Z]{2,20})?)\s*(?:here\b|[,:\-–—])\s*(.*)$/i', trim($messageText), $prefixMatch)) {
            $candidate = trim($prefixMatch[1]);
            if (!$isBlacklistedName($candidate) && !preg_match('/\b(course|courses|fee|fees|cost|price|pricing|syllabus|python|react|java|help|hello|hi|hey|good|namaste)\b/i', $candidate)) {
                $visitorName = ucwords(strtolower($candidate));
            }
        } 
        // Case B: Combined Lead Intake (Phone + Name in single message, e.g. "Rahul Sharma 9876543210" or "9876543210 - Rahul")
        elseif (!empty($visitorPhone)) {
            $stripped = preg_replace('/(?:\+?91[\s.-]?)?[6-9]\d{9}|\b\d{10}\b|(?:\+?\d{1,3}[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', '', $messageText);
            $stripped = trim(preg_replace('/[^a-zA-Z\s]/', ' ', $stripped));
            $words = array_values(array_filter(preg_split('/\s+/', $stripped)));
            if (count($words) >= 1 && count($words) <= 3) {
                $candidate = implode(' ', $words);
                if (!$isBlacklistedName($candidate)) {
                    $visitorName = ucwords(strtolower($candidate));
                }
            }
        }
        // Case C: Clean 1-2 words provided strictly when no question/intent keywords are present
        elseif (preg_match('/^[a-zA-Z]{2,15}(?:\s+[a-zA-Z]{2,15})?$/', trim($messageText))) {
            $candidate = trim($messageText);
            // Strict check: candidate must not contain inquiry/topic/question words
            $hasInquiryIntent = (bool)preg_match('/\b(course|courses|fee|fees|cost|price|pricing|prining|syllabus|admission|batch|class|study|learn|join|tell|info|help|service|web|python|react|java|data|tech|training|developer|coding|kya|hai|hain|karo|batao|chahiye|kitna|kitni|kitne|good|hello|namaste|hi|hey|test)\b/i', $candidate);
            if (!$isBlacklistedName($candidate) && !$hasInquiryIntent) {
                $visitorName = ucwords(strtolower($candidate));
            }
        }
    }

    // 4. Resolve / Validate Customer & Conversation
    $customer = null;
    if ($conversationId) {
        $convCheck = $pdo->prepare("SELECT id, customer_id FROM `conversations` WHERE `id` = ? AND `company_id` = ? LIMIT 1");
        $convCheck->execute([$conversationId, $companyId]);
        $existingConv = $convCheck->fetch();
        if ($existingConv) {
            if (!empty($existingConv['customer_id'])) {
                $cStmt = $pdo->prepare("SELECT * FROM `customers` WHERE `id` = ? AND `company_id` = ? LIMIT 1");
                $cStmt->execute([(int)$existingConv['customer_id'], $companyId]);
                $customer = $cStmt->fetch();
            }
        } else {
            // Reset cross-tenant conversation ID to prevent corruption
            $conversationId = null;
        }
    }

    if ($leadId) {
        $lCheck = $pdo->prepare("SELECT id FROM `leads` WHERE `id` = ? AND `company_id` = ? LIMIT 1");
        $lCheck->execute([$leadId, $companyId]);
        if (!$lCheck->fetch()) {
            $leadId = null;
        }
    }

    // Deduplicate customer by phone or email within company
    if (!$customer && !empty($visitorPhone)) {
        $cStmt = $pdo->prepare("SELECT * FROM `customers` WHERE `company_id` = ? AND (`phone` = ? OR `whatsapp_number` = ?) LIMIT 1");
        $cStmt->execute([$companyId, $visitorPhone, $visitorPhone]);
        $customer = $cStmt->fetch();
    }
    if (!$customer && !empty($visitorEmail)) {
        $cStmt = $pdo->prepare("SELECT * FROM `customers` WHERE `company_id` = ? AND `email` = ? LIMIT 1");
        $cStmt->execute([$companyId, $visitorEmail]);
        $customer = $cStmt->fetch();
    }
    if (!$customer && !empty($sessionId)) {
        $sessStmt = $pdo->prepare("
            SELECT c.* FROM `customers` c
            JOIN `visitor_sessions` vs ON vs.customer_id = c.id
            WHERE (vs.session_id = ? OR vs.session_token = ?) AND vs.company_id = ?
            LIMIT 1
        ");
        $sessStmt->execute([$sessionId, $sessionId, $companyId]);
        $customer = $sessStmt->fetch();
    }

    if (!$customer) {
        $custUuid = 'cust_' . bin2hex(random_bytes(12));
        $custName = !empty($visitorName) ? $visitorName : (!empty($visitorPhone) ? 'Prospect ' . $visitorPhone : 'Website Visitor');
        $pdo->prepare("
            INSERT INTO `customers` 
            (`company_id`, `customer_uuid`, `name`, `phone`, `email`, `whatsapp_number`, `first_seen_at`, `last_seen_at`)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
        ")->execute([
            $companyId,
            $custUuid,
            $custName,
            $visitorPhone ?: null,
            $visitorEmail ?: null,
            $visitorPhone ?: null
        ]);
        $customerId = (int)$pdo->lastInsertId();

        $cFetch = $pdo->prepare("SELECT * FROM `customers` WHERE id = ? LIMIT 1");
        $cFetch->execute([$customerId]);
        $customer = $cFetch->fetch();
    } else {
        $customerId = (int)$customer['id'];
        $cleanCustName = !empty($visitorName) ? $visitorName : ($customer['name'] === 'Website Visitor' && !empty($visitorPhone) ? 'Prospect ' . $visitorPhone : $customer['name']);
        if (!empty($visitorName) || !empty($visitorPhone) || !empty($visitorEmail)) {
            $custUpdates = [];
            $custParams = [];
            if (!empty($cleanCustName)) {
                $custUpdates[] = "`name` = ?";
                $custParams[] = $cleanCustName;
            }
            if (!empty($visitorPhone)) {
                $custUpdates[] = "`phone` = ?";
                $custUpdates[] = "`whatsapp_number` = ?";
                $custParams[] = $visitorPhone;
                $custParams[] = $visitorPhone;
            }
            if (!empty($visitorEmail)) {
                $custUpdates[] = "`email` = ?";
                $custParams[] = $visitorEmail;
            }
            $custUpdates[] = "`last_seen_at` = NOW()";
            $custParams[] = $customerId;
            $custParams[] = $companyId;

            $pdo->prepare("UPDATE `customers` SET " . implode(', ', $custUpdates) . " WHERE `id` = ? AND `company_id` = ?")->execute($custParams);

            $cFetch = $pdo->prepare("SELECT * FROM `customers` WHERE id = ? LIMIT 1");
            $cFetch->execute([$customerId]);
            $customer = $cFetch->fetch();
        }
    }

    // Ensure conversation belongs to this workspace
    if (!$conversationId) {
        $insConv = $pdo->prepare("
            INSERT INTO `conversations` 
            (`company_id`, `customer_id`, `channel`, `status`, `ownership`, `last_message_preview`, `last_message_at`, `created_at`)
            VALUES (?, ?, 'widget', 'ai_handling', 'ai', ?, NOW(), NOW())
        ");
        $insConv->execute([$companyId, $customerId, substr($messageText, 0, 150)]);
        $conversationId = (int)$pdo->lastInsertId();
    }

    // Resolve or generate Session ID (Section 1: CP-XXXXXXX)
    if (empty($sessionId) && !empty($conversationId)) {
        $sStmt = $pdo->prepare("SELECT session_id FROM `conversations` WHERE id = ? AND company_id = ? LIMIT 1");
        $sStmt->execute([$conversationId, $companyId]);
        $sessionId = $sStmt->fetchColumn() ?: '';
    }
    if (empty($sessionId) && !empty($customerId)) {
        $sStmt = $pdo->prepare("SELECT session_id FROM `visitor_sessions` WHERE customer_id = ? AND company_id = ? ORDER BY id DESC LIMIT 1");
        $sStmt->execute([$customerId, $companyId]);
        $sessionId = $sStmt->fetchColumn() ?: '';
    }
    if (empty($sessionId)) {
        $sessionId = 'CP-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 7));
    }

    // Ensure session association in conversations
    $pdo->prepare("UPDATE `conversations` SET `session_id` = ?, `channel` = 'web' WHERE id = ? AND company_id = ? AND (`session_id` IS NULL OR `session_id` = '')")
        ->execute([$sessionId, $conversationId, $companyId]);

    // Handle Direct Slot Confirmation (Section 10 & 11)
    if ($action === 'confirm_slot' || (!empty($selectedSlot) && $action !== 'chat')) {
        $bookingResult = bookAppointmentFromChat($pdo, $companyId, [
            'customer_id'     => $customerId,
            'visitor_id'      => $customerId,
            'lead_id'         => $leadId,
            'session_id'      => $sessionId,
            'conversation_id' => $conversationId,
            'customer_name'   => !empty($customer['name']) ? $customer['name'] : ($visitorName ?: 'Prospect'),
            'customer_phone'  => $customer['phone'] ?? $visitorPhone,
            'customer_email'  => $customer['email'] ?? $visitorEmail,
            'slot_datetime'   => $selectedSlot,
            'title'           => 'Consultation / Demo: ' . (!empty($customer['name']) ? $customer['name'] : ($visitorName ?: 'Prospect')),
            'appointment_type'=> 'consultation'
        ]);

        if (!$bookingResult['success']) {
            echo json_encode([
                'success'           => false,
                'error'             => $bookingResult['error'],
                'reply'             => $bookingResult['message'],
                'conversation_id'   => $conversationId,
                'session_id'        => $sessionId,
                'appointment_slots' => $bookingResult['slots'] ?? []
            ]);
            exit;
        }

        // Persist messages in conversation history
        $pdo->prepare("
            INSERT INTO `messages` 
            (`company_id`, `conversation_id`, `sender_type`, `message_text`, `channel`, `session_id`, `detected_intent`, `created_at`)
            VALUES (?, ?, 'visitor', ?, 'web', ?, 'confirm_slot', NOW())
        ")->execute([$companyId, $conversationId, $messageText, $sessionId]);

        $confirmReply = $bookingResult['message'];
        $pdo->prepare("
            INSERT INTO `messages` 
            (`company_id`, `conversation_id`, `sender_type`, `message_text`, `channel`, `session_id`, `detected_intent`, `metadata_json`, `created_at`)
            VALUES (?, ?, 'ai', ?, 'web', ?, 'appointment_confirmed', ?, NOW())
        ")->execute([
            $companyId, $conversationId, $confirmReply, $sessionId,
            json_encode(['appointment_id' => $bookingResult['appointment_id'], 'slot_datetime' => $bookingResult['slot_datetime']])
        ]);

        $pdo->prepare("UPDATE `conversations` SET `last_message_preview` = ?, `last_message_at` = NOW() WHERE `id` = ? AND `company_id` = ?")
            ->execute([substr($confirmReply, 0, 150), $conversationId, $companyId]);

        echo json_encode([
            'success'               => true,
            'appointment_confirmed' => true,
            'appointment_id'        => $bookingResult['appointment_id'],
            'appointment_details'   => $bookingResult,
            'reply'                 => $confirmReply,
            'conversation_id'       => $conversationId,
            'session_id'            => $sessionId,
            'lead_id'               => $leadId,
            'timestamp'             => 'Just now'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Check if conversation is currently handled by a human agent (AI Silencing)
    if ($conversationId) {
        $checkHumanStmt = $pdo->prepare("
            SELECT c.*, u.name as agent_name, u.job_title, u.avatar_url 
            FROM `conversations` c
            LEFT JOIN `users` u ON u.id = c.assigned_user_id
            WHERE c.id = ? AND c.company_id = ?
            LIMIT 1
        ");
        $checkHumanStmt->execute([$conversationId, $companyId]);
        $currentConv = $checkHumanStmt->fetch(PDO::FETCH_ASSOC);

        if ($currentConv && ($currentConv['ownership'] === 'human' || $currentConv['status'] === 'human_handling' || $currentConv['status'] === 'human_active')) {
            $pdo->prepare("
                INSERT INTO `messages` (`company_id`, `conversation_id`, `sender_type`, `message_text`, `created_at`)
                VALUES (?, ?, 'visitor', ?, NOW())
            ")->execute([$companyId, $conversationId, $messageText]);

            $pdo->prepare("
                UPDATE `conversations`
                SET `last_message_preview` = ?,
                    `last_message_at` = NOW(),
                    `unread_human` = unread_human + 1
                WHERE id = ? AND company_id = ?
            ")->execute([substr($messageText, 0, 150), $conversationId, $companyId]);

            $agentName = $currentConv['agent_name'] ?: 'Advisor';

            echo json_encode([
                'success'         => true,
                'conversation_id' => $conversationId,
                'human_handling'  => true,
                'agent_name'      => $agentName,
                'reply'           => null,
                'message'         => "Delivered to {$agentName}",
                'timestamp'       => 'Just now'
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // =========================================================================
    // WORKSPACE COPILOT MODE (Know Your Workspace & Real-time Live Telemetry)
    // Strictly requires authenticated session belonging to this workspace
    // =========================================================================
    $hasWorkspaceSession = !empty($_SESSION['user_id']) && !empty($_SESSION['company_id']) && (int)$_SESSION['company_id'] === $companyId;
    $isCopilotMode = $hasWorkspaceSession && (
        ($mode === 'workspace_copilot') || 
        preg_match('/(kitni lead|leads?|kisko|assign|revenue|conversion|convert|reminder|reminders|appointment|appointments|fees|fee|billing|trial|workspace|stats)/i', $messageText)
    );

    if ($isCopilotMode) {
        // Record visitor/user message in conversation
        if ($conversationId) {
            $pdo->prepare("INSERT INTO `messages` (`company_id`, `conversation_id`, `sender_type`, `message_text`, `created_at`) VALUES (?, ?, 'visitor', ?, NOW())")
                ->execute([$companyId, $conversationId, $messageText]);
        }

        // 1. Gather live telemetry
        $teleStmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_leads,
                SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as leads_today,
                SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) as won_leads,
                SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_leads,
                SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) as lost_leads,
                SUM(CASE WHEN human_attention_required = 1 THEN 1 ELSE 0 END) as human_req,
                COALESCE(SUM(opportunity_value), 0) as total_pipeline_value,
                COALESCE(SUM(CASE WHEN status = 'won' THEN opportunity_value ELSE 0 END), 0) as won_revenue,
                COALESCE(SUM(paid_amount), 0) as total_collected
            FROM `leads` 
            WHERE `company_id` = ?
        ");
        $teleStmt->execute([$companyId]);
        $wLeads = $teleStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $teamStmt = $pdo->prepare("
            SELECT 
                u.id, u.name, u.role, u.department, u.availability_status,
                COUNT(l.id) as assigned_leads,
                SUM(CASE WHEN l.status = 'won' THEN 1 ELSE 0 END) as won_count,
                COALESCE(SUM(l.opportunity_value), 0) as pipeline_value
            FROM `users` u
            LEFT JOIN `leads` l ON l.assigned_user_id = u.id AND l.company_id = ?
            WHERE u.company_id = ? AND u.is_active = 1
            GROUP BY u.id
            ORDER BY assigned_leads DESC
        ");
        $teamStmt->execute([$companyId, $companyId]);
        $wTeam = $teamStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $unassignedStmt = $pdo->prepare("SELECT COUNT(*) FROM `leads` WHERE `company_id` = ? AND (assigned_user_id IS NULL OR assigned_user_id = 0)");
        $unassignedStmt->execute([$companyId]);
        $wUnassigned = (int)($unassignedStmt->fetchColumn() ?: 0);

        $apptStmt = $pdo->prepare("
            SELECT 
                a.id, a.title, a.appointment_type, a.slot_datetime, a.status, a.meet_link,
                u.name as advisor_name,
                c.name as customer_name
            FROM `appointments` a
            LEFT JOIN `users` u ON u.id = a.assigned_user_id
            LEFT JOIN `customers` c ON c.id = a.customer_id
            WHERE a.company_id = ? AND a.slot_datetime >= NOW() - INTERVAL 2 HOUR
            ORDER BY a.slot_datetime ASC
            LIMIT 5
        ");
        $apptStmt->execute([$companyId]);
        $wAppointments = $apptStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $msgLower = strtolower($messageText);
        $copilotReply = '';

        // Check for Reminder Setup
        if (preg_match('/(?:reminder|remind|schedule|setup|set up)\s+(?:setup\s+)?(?:kr\s*do|karo|karein|kar do|for|to)?\s*(.+)/i', $messageText, $m)) {
            $remTitle = trim($m[1]);
            $slot = date('Y-m-d 10:00:00', strtotime('+1 day'));
            if (preg_match('/(\d{1,2})\s*(?:baje|pm|am)/i', $messageText, $tm)) {
                $h = (int)$tm[1];
                if (strpos($msgLower, 'pm') !== false && $h < 12) $h += 12;
                $slot = date('Y-m-d', strtotime('+1 day')) . sprintf(' %02d:00:00', $h);
            }
            try {
                $pdo->prepare("INSERT INTO `appointments` (`company_id`, `title`, `appointment_type`, `slot_datetime`, `status`, `notes`, `created_at`, `updated_at`) VALUES (?, ?, 'reminder', ?, 'scheduled', 'Added via Workspace Copilot', NOW(), NOW())")
                    ->execute([$companyId, "Reminder: " . substr($remTitle, 0, 100), $slot]);

                $copilotReply = "✅ **Reminder setup kar diya gaya hai!**\n\n"
                    . "📌 **Title:** " . htmlspecialchars($remTitle) . "\n"
                    . "⏰ **Scheduled for:** " . date('M j, Y — g:i A', strtotime($slot)) . "\n"
                    . "🔔 Iska record dashboard alerts aur appointments pipeline mein add kar diya gaya hai.";
            } catch (Exception $e) {
                $copilotReply = "⚠️ Reminder setup karne mein problem aayi: " . $e->getMessage();
            }
        }

        // Check for specific queries if reminder wasn't triggered
        if (empty($copilotReply)) {
            if (preg_match('/(kisko|assign|member|team|counselor|agent)/i', $msgLower)) {
                $resp = "👥 **Team Lead Allocation & Performance:**\n\n";
                if (empty($wTeam)) {
                    $resp .= "Abhi koi team member active nahi hai.\n";
                } else {
                    $resp .= "| Team Member | Role | Assigned | Won Deals |\n"
                          . "|---|---|---|---|\n";
                    foreach ($wTeam as $t) {
                        $role = ucfirst($t['role'] ?: 'Counselor');
                        $resp .= "| **{$t['name']}** | {$role} | {$t['assigned_leads']} leads | {$t['won_count']} Won |\n";
                    }
                }
                if ($wUnassigned > 0) {
                    $resp .= "\n⚠️ **{$wUnassigned} leads** abhi unassigned hain. Aap CRM pipeline se inhe directly team mein distribute kar sakte hain.";
                }
                $copilotReply = $resp;
            } elseif (preg_match('/(convert|revenue|pipeline|collection|kamai|paisa|value)/i', $msgLower)) {
                $wonRev = number_format((float)$wLeads['won_revenue']);
                $pipeVal = number_format((float)$wLeads['total_pipeline_value']);
                $wonCnt = (int)$wLeads['won_leads'];
                $totCnt = (int)$wLeads['total_leads'];
                $convRate = $totCnt > 0 ? round(($wonCnt / $totCnt) * 100, 1) : 0;

                $copilotReply = "💰 **Revenue & Pipeline Financials:**\n\n"
                    . "| Financial Indicator | Amount / Rate | Note |\n"
                    . "|---|---|---|\n"
                    . "| **Total Pipeline Value** | ₹{$pipeVal} | Estimated deal value |\n"
                    . "| **Won Revenue** | ₹{$wonRev} | Closed won earnings |\n"
                    . "| **Total Collected** | ₹" . number_format((float)$wLeads['total_collected']) . " | Settled amount |\n"
                    . "| **Conversion Rate** | {$convRate}% | {$wonCnt} / {$totCnt} deals closed |\n\n"
                    . "💡 *Pro Tip:* High intent leads ko timely follow-up karke conversion rate ko improve kiya ja sakta hai.";
            } elseif (preg_match('/(appointment|meeting|slot|calander|calendar|schedule)/i', $msgLower)) {
                if (empty($wAppointments)) {
                    $copilotReply = "📅 **Upcoming Appointments:**\n\nAapke workspace mein abhi koi aage ki appointment ya scheduled meeting nahi hai.\n\nAap *'Reminder setup kr do [details]'* bolkar yahan se naya reminder ya meeting note schedule kar sakte hain!";
                } else {
                    $resp = "📅 **Upcoming Appointments & Reminders:**\n\n";
                    foreach ($wAppointments as $idx => $ap) {
                        $num = $idx + 1;
                        $dt = date('M j, Y — g:i A', strtotime($ap['slot_datetime']));
                        $adv = $ap['advisor_name'] ? " • Advisor: {$ap['advisor_name']}" : "";
                        $cust = $ap['customer_name'] ?: "Client";
                        $link = $ap['meet_link'] ? "\n  🔗 [Join Google Meet]({$ap['meet_link']})" : "";
                        $statusBadge = ucfirst($ap['status'] ?: 'Scheduled');
                        $resp .= "**{$num}. {$ap['title']}**\n"
                               . "  👤 Client: {$cust}{$adv}\n"
                               . "  🕒 Time: {$dt}\n"
                               . "  📌 Status: `{$statusBadge}`{$link}\n\n";
                    }
                    $copilotReply = trim($resp);
                }
            } elseif (preg_match('/(fee|fees|plan|subscription|trial|price|tier|billing)/i', $msgLower)) {
                $trialDays = (int)$entitlements['trial_days_remaining'];
                $status = ucfirst($entitlements['status']);
                $plan = $entitlements['current_plan_name'];
                $wa = $entitlements['whatsapp_connected'] ? "Active & Connected ✅" : "Not connected ⚠️";

                $copilotReply = "⚡ **Subscription & Workspace Status:**\n\n"
                    . "| Parameter | Details | Status |\n"
                    . "|---|---|---|\n"
                    . "| **Current Plan** | {$plan} | {$status} |\n"
                    . ($entitlements['is_trial'] ? "| **Trial Window** | {$trialDays} days remaining | Full Growth Tier |\n" : "| **Access Level** | Active Subscription | Full Access |\n")
                    . "| **WhatsApp Integration** | {$wa} | Live Webhooks |\n"
                    . "| **Next Renewal Cycle** | " . ($entitlements['formatted_trial_end'] ?: "Active") . " | Standard Cycle |\n\n"
                    . "Sabhi core features (AI Assistant, CRM Pipeline, Appointments, Payments) aapke plan mein fully active hain.";
            } else {
                // Default live lead status overview
                $tot = (int)$wLeads['total_leads'];
                $today = (int)$wLeads['leads_today'];
                $open = (int)$wLeads['open_leads'];
                $won = (int)$wLeads['won_leads'];
                $pipe = number_format((float)$wLeads['total_pipeline_value']);

                $copilotReply = "📊 **Workspace Live Lead Summary:**\n\n"
                    . "| Metric | Count / Value | Details |\n"
                    . "|---|---|---|\n"
                    . "| **Total Inquiries** | {$tot} Leads | All-time CRM pipeline |\n"
                    . "| **Aaj ki Leads (Today)** | {$today} Leads | Received today |\n"
                    . "| **Open Inquiries** | {$open} Active | In progress / follow-up |\n"
                    . "| **Converted (Won)** | {$won} Closed | Successfully converted |\n"
                    . "| **Total Pipeline Value** | ₹{$pipe} | Estimated opportunity |\n\n"
                    . ($wUnassigned > 0 ? "⚠️ **Pending Assignment:** {$wUnassigned} leads abhi unassigned hain.\n\n" : "")
                    . "Aap specific details pooch sakte hain — jaise *'Leads kisko gayi hain?'*, *'Kya revenue hai?'*, ya *'Reminder setup kr do'*!";
            }
        }

        // Record AI response in conversation
        if ($conversationId) {
            $pdo->prepare("INSERT INTO `messages` (`company_id`, `conversation_id`, `sender_type`, `message_text`, `created_at`) VALUES (?, ?, 'ai', ?, NOW())")
                ->execute([$companyId, $conversationId, $copilotReply]);
            $pdo->prepare("UPDATE `conversations` SET `last_message_preview` = ?, `last_message_at` = NOW() WHERE id = ? AND company_id = ?")
                ->execute([substr($copilotReply, 0, 150), $conversationId, $companyId]);
        }

        echo json_encode([
            'success'         => true,
            'conversation_id' => $conversationId,
            'reply'           => $copilotReply,
            'sender'          => 'ai',
            'assistant_name'  => $assistantName,
            'mode'            => 'workspace_copilot',
            'copilot'         => true,
            'timestamp'       => 'Just now'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 5. Build Grounded AI Prompt with Structured Classification
    $historyMessages = [];
    if ($conversationId) {
        $hStmt = $pdo->prepare("
            SELECT sender_type, message_text 
            FROM `messages` 
            WHERE `conversation_id` = ? AND `company_id` = ? 
            ORDER BY id DESC LIMIT 8
        ");
        $hStmt->execute([$conversationId, $companyId]);
        $recentRows = array_reverse($hStmt->fetchAll());
        foreach ($recentRows as $r) {
            $role = ($r['sender_type'] === 'visitor') ? 'user' : 'assistant';
            $historyMessages[] = ['role' => $role, 'content' => $r['message_text']];
        }
    }

    // Get fresh customer name for polite, human personalization
    $cStmt = $pdo->prepare("SELECT name, phone, email FROM `customers` WHERE id = ? LIMIT 1");
    $cStmt->execute([$customerId]);
    $latestCust = $cStmt->fetch();
    $visitorDisplayName = !empty($latestCust['name']) ? $latestCust['name'] : (!empty($visitorName) ? $visitorName : '');
    // If name is placeholder or blacklisted keyword, do not use it in greeting
    if (preg_match('/^(prospect|website visitor)/i', $visitorDisplayName) || $isBlacklistedName($visitorDisplayName)) {
        $visitorDisplayName = '';
    }
    $visitorGreetingName = !empty($visitorDisplayName) ? $visitorDisplayName : '';

    $prevArtifactStmt = $pdo->prepare("SELECT * FROM `lead_artifacts` WHERE `customer_id` = ? AND `company_id` = ? ORDER BY id DESC LIMIT 1");
    $prevArtifactStmt->execute([$customerId, $companyId]);
    $prevArtifact = $prevArtifactStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $knownFields = [];
    if (!empty($visitorDisplayName)) $knownFields[] = "- Customer Name: {$visitorDisplayName}";
    if (!empty($customer['phone'])) $knownFields[] = "- Phone: {$customer['phone']}";
    if (!empty($customer['email'])) $knownFields[] = "- Email: {$customer['email']}";
    if (!empty($prevArtifact['interested_service'])) $knownFields[] = "- Interested Service: {$prevArtifact['interested_service']}";
    if (!empty($prevArtifact['budget'])) $knownFields[] = "- Stated Budget: {$prevArtifact['budget']}";
    if (!empty($prevArtifact['timeline'])) $knownFields[] = "- Timeline: {$prevArtifact['timeline']}";
    if (!empty($prevArtifact['requirement'])) $knownFields[] = "- Requirement: {$prevArtifact['requirement']}";

    $memorySection = !empty($knownFields) 
        ? "STRUCTURED CUSTOMER MEMORY (ALREADY COLLECTED — DO NOT ASK FOR THESE AGAIN):\n" . implode("\n", $knownFields) . "\n\n"
        : "";

    $systemPrompt = "You are {$assistantName}, the consultative and warm AI representative for {$brandDisplayName} ({$company['name']}).\n"
                  . "Visitor Information:\n"
                  . ($visitorGreetingName ? "- Name: {$visitorGreetingName}\n" : "- Name: Not specified yet\n")
                  . "Tone: Warm, consultative, human-like, friendly, and empathetic\n"
                  . "Primary Objective: {$aiObjective}\n"
                  . "Language: Match the visitor's language naturally (conversational Hinglish, Hindi, or friendly English).\n\n"
                  . (!empty($customInstructions) ? "CUSTOM COMPANY INSTRUCTIONS (MANDATORY RULES):\n{$customInstructions}\n\n" : "")
                  . $memorySection
                  . "Verified Company Knowledge Base (Grounding):\n"
                  . $knowledgeContext . "\n\n"
                  . "CRITICAL CONVERSATIONAL GUIDELINES:\n"
                  . "1. ULTRA-HUMAN & CONSULTATIVE PERSONA: Speak like an empathetic, friendly human team member who genuinely wants to help. NEVER sound robotic, scripted, or like an automated answering machine. NEVER say 'Here is the verified information for...' or dump raw unformatted text.\n"
                  . (empty($visitorGreetingName)
                      ? "2. MANDATORY NAME-FIRST INTAKE: The visitor has not provided their name yet. In this conversation, YOU MUST ASK FOR THEIR NAME FIRST before providing catalog breakdowns, deep solutions, or proceeding further! If they ask a question or greet you, politely and warmly acknowledge that you will be delighted to help them with full details, but kindly ask: 'Aage badhne se pehle, kya main aapka shubh naam jaan sakta hoon?' (or 'Before we proceed, could you please tell me your name so I can assist you better?'). Once they tell you their name, you will answer their question in the next turn.\n"
                      : "2. VISITOR IS IDENTIFIED: The visitor's verified name is '{$visitorGreetingName}'. Greet them warmly by name (e.g. 'Namaste {$visitorGreetingName}!' or 'Hi {$visitorGreetingName}!'). CRITICAL CONVERSATION CONTINUITY: If the visitor just provided their name in response to your previous prompt asking for their name, AND they had previously asked a question in this chat (such as inquiring about courses, fees, syllabus, or services), DO NOT JUST SAY GREETINGS! You MUST IMMEDIATELY AND COMPREHENSIVELY ANSWER THEIR PREVIOUS QUESTION with full details, pricing, and markdown tables from the Verified Knowledge Base right here in this response!\n")
                  . "3. STRICT NAME SAFETY: Inquiry terms such as 'Courses', 'Fees', 'Syllabus', 'Details', 'Python', 'Training', 'Admission' are SUBJECT TOPICS, NEVER personal names! NEVER say 'Nice to meet you, [Topic]'. If name is not specified or visitor asked a query, address them warmly without a name.\n"
                  . "4. CLEAN TABLES & BULLETS: When presenting courses, pricing, fees, or comparative data, format them cleanly using standard Markdown tables (e.g. | Course | Duration | Fee | with separator |---|---|---|) or clean bullet points with bold titles. Keep it mobile-friendly and readable.\n"
                  . "5. SEAMLESS TYPO HANDLING: Understand typos naturally without pointing them out. For example, if the visitor types 'prinings bata do', understand they are asking about pricing/plans; if they write 'apki services kya hai', explain our services clearly and conversationally.\n"
                  . "6. GROUNDED CONSULTATION: Answer using facts from the Verified Company Knowledge Base above. Keep details accurate, clear, and honest.\n"
                  . "7. PROGRESSIVE LEAD COLLECTION: If the visitor's Name or WhatsApp/Phone number is not yet known, after answering their question, naturally invite them to share their Name and WhatsApp number so our team can send them the syllabus/brochure or set up a personalized demo.\n"
                  . "8. KEEP IT CRISP: Aim for 2-4 focused, readable sentences or clean bullet points/tables. Do not write walls of text.\n"
                  . "9. DIGITAL ASSET & SYLLABUS DISPATCH: If the visitor asks for a course syllabus, curriculum, brochure, or fee chart for any item in Downloadable Documents above, acknowledge that you have shared the download card right here in chat! If their email is not on file, kindly invite them to provide their email address so you can also dispatch a copy directly to their inbox.\n"
                  . "10. AT THE VERY END OF YOUR RESPONSE, output the exact delimiter '---INTERNAL_METADATA---' followed by a valid JSON object analyzing the lead. The user will not see this metadata.\n"
                  . "Format:\n"
                  . "Your public conversational answer here.\n"
                  . "---INTERNAL_METADATA---\n"
                  . "{\n"
                  . '  "intent": "purchase_interest | fee_inquiry | syllabus_inquiry | human_request | general | conversation_end",' . "\n"
                  . '  "stage": "NEW | ENGAGED | INTERESTED | QUALIFIED | HIGH_INTENT | HUMAN_REQUIRED",' . "\n"
                  . '  "priority": "LOW | MEDIUM | HIGH | URGENT",' . "\n"
                  . '  "interest": "concise product or course name",' . "\n"
                  . '  "budget": "stated budget or null",' . "\n"
                  . '  "timeline": "stated timeline or null",' . "\n"
                  . '  "summary": "1 concise sentence describing the user inquiry and situation",' . "\n"
                  . '  "human_required": false,' . "\n"
                  . '  "buying_signals": ["pricing_inquired", "budget_confirmed"],' . "\n"
                  . '  "objections": [],' . "\n"
                  . '  "recommended_action": "clear next action for sales team",' . "\n"
                  . '  "estimated_value": 45000' . "\n"
                  . "}";

    // =========================================================================
    // LOCAL RAG KNOWLEDGE SYNTHESIS & RELEVANCE MATCHER
    // Accurately extracts verified facts, fees, syllabus, and services
    // in warm, human-like Hindi, Hinglish, or English.
    // =========================================================================
    if (!function_exists('synthesizeKnowledgeResponse')) {
        function synthesizeKnowledgeResponse($messageText, $knowledgeList, $company, $assistantName, $visitorGreetingName = '', $brandDisplayName = '', $historyMessages = []) {
            $rawMsg = trim($messageText);
            $query = mb_strtolower($rawMsg);
            $brand = !empty($brandDisplayName) ? $brandDisplayName : ($company['name'] ?? 'CuboidPilot');

            // Detect if visitor just shared their name, and recover previous inquiry from history
            $effectiveQuery = $query;
            $hasPriorInquiry = false;
            if (!empty($visitorGreetingName) && !empty($historyMessages)) {
                $isNameOnly = (strcasecmp($rawMsg, $visitorGreetingName) === 0) || (preg_match('/^(?:mera naam|my name is|i am|this is|naam)\b/i', $rawMsg));
                if ($isNameOnly) {
                    for ($hi = count($historyMessages) - 1; $hi >= 0; $hi--) {
                        if ($historyMessages[$hi]['role'] === 'user' && !empty($historyMessages[$hi]['content'])) {
                            $pastContent = trim($historyMessages[$hi]['content']);
                            if (strcasecmp($pastContent, $rawMsg) !== 0) {
                                $effectiveQuery = mb_strtolower($pastContent);
                                $hasPriorInquiry = true;
                                break;
                            }
                        }
                    }
                }
            }

            // 1. Language Detection (Hindi / Hinglish vs English)
            $isHindi = (bool)preg_match('/\b(hai|hain|kya|kyu|kyun|kaun|kon|apke|aapke|paas|pass|kitna|kitni|kitne|batao|bataiye|bata\s*do|chahiye|hoga|hogi|karte|sikhate|padhate|kaise|kaha|kab|karein|dena|paisa|paise|namaste|mujhe|hum|aap|bhai|sir|accha|theek|bolo|madad|bata|prining|prinings)\b/iu', ($hasPriorInquiry ? $effectiveQuery : $query));

            // 2. Intent Classification with Typo Tolerance
            $isGreeting = (bool)preg_match('/^(hi|hello|hey|namaste|pranam|good\s*(morning|afternoon|evening)|yo|hola)[\s!.]*$/i', $query);
            $isHumanRequest = (bool)preg_match('/\b(human|person|counselor|advisor|agent|speak|talk|call|team|real person|banda|baat\s*karni)\b/i', $effectiveQuery);
            $isFeeInquiry = (bool)preg_match('/\b(fee|fees|cost|price|pricing|prining|prinings|charge|charges|emi|installment|rate|rates|tuition|kitna|kitni|paise|rupaye|kharcha|payment|pay|plan|plans|package|packages)\b/i', $effectiveQuery);
            $isServiceInquiry = (bool)preg_match('/\b(service|services|servis|servises|kya karte|features|feature|offering|offerings|solution|solutions|product|products|kaise kaam|what do you do|help|madad)\b/i', $effectiveQuery);
            $isCourseInquiry = (bool)preg_match('/\b(course|courses|batch|batches|program|programs|curriculum|syllabus|subjects|stack|training|mern|python|java|web|frontend|backend|fullstack|data|ai|ml|sikhate|padhate)\b/i', $effectiveQuery);
            $isAdmissionInquiry = (bool)preg_match('/\b(admission|admissions|join|enroll|enrollment|register|registration|apply|entry|kaise join|process|trial|free trial)\b/i', $effectiveQuery);
            $isScheduleInquiry = (bool)preg_match('/\b(schedule|timing|time|duration|months|weeks|days|hours|kab|start|shuru)\b/i', $effectiveQuery);
            $isGifReaction = (bool)preg_match('/\[Shared a reaction GIF: "([^"]+)"\]/i', $rawMsg, $gifMatches);
            $isAttachment = (bool)preg_match('/\[Attached (Image|Document): ([^\]]+)\]/i', $rawMsg, $attMatches);

            $reply = '';
            $meta = [
                'intent'             => 'general',
                'stage'              => 'ENGAGED',
                'priority'           => 'MEDIUM',
                'interest'           => $brand,
                'summary'            => "Visitor asked: " . substr($rawMsg, 0, 60),
                'human_required'     => false,
                'recommended_action' => 'Provide consultative guidance',
                'estimated_value'    => 35000
            ];

            if ($isGifReaction) {
                $gifTitle = $gifMatches[1] ?? 'reaction';
                $reply = $isHindi
                    ? "Great reaction! 😊 Main {$assistantName} hoon, {$brand} ka AI assistant. Main aapki kya sahayata kar sakta hoon?"
                    : "Love the energy! 😊 I'm {$assistantName}, your AI assistant at {$brand}. How can I best help you today?";
                return ['reply' => $reply, 'meta' => $meta];
            } elseif ($isAttachment) {
                $fileName = $attMatches[2] ?? 'file';
                $meta['intent'] = 'file_attachment';
                $meta['priority'] = 'HIGH';
                $reply = $isHindi
                    ? "Maine aapka attached file **{$fileName}** receive kar liya hai. Iske regarding main aapki kya madad kar sakta hoon?"
                    : "I've received your attached document **{$fileName}**. How can I best assist you with this?";
                return ['reply' => $reply, 'meta' => $meta];
            }

            // Dynamic Grounding: Search matching knowledge source in tenant's indexed knowledge
            $matchedSource = null;
            if (!empty($knowledgeList)) {
                $terms = array_filter(preg_split('/\s+/', ($hasPriorInquiry ? $effectiveQuery : $query)), fn($w) => strlen($w) >= 3);
                $bestScore = 0;
                foreach ($knowledgeList as $src) {
                    $score = 0;
                    $titleLower = mb_strtolower($src['title'] ?? '');
                    $contentLower = mb_strtolower($src['content'] ?? '');
                    foreach ($terms as $t) {
                        if (strpos($titleLower, $t) !== false) $score += 4;
                        if (strpos($contentLower, $t) !== false) $score += 1;
                    }
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $matchedSource = $src;
                    }
                }
            }

            // MANDATORY NAME-FIRST POLICY: If visitor has not provided their name yet, ask for their name first!
            if (empty($visitorGreetingName)) {
                $meta['intent'] = 'name_request';
                $meta['stage'] = 'NEW';
                $meta['priority'] = 'MEDIUM';
                $meta['summary'] = 'Asking for visitor name before proceeding';

                if ($isGreeting) {
                    $reply = $isHindi
                        ? "Namaste! 👋 Main {$assistantName} hoon, {$brand} ka AI guide. Aage badhne se pehle, kya main aapka shubh naam jaan sakta hoon?"
                        : "Hello! 👋 I'm {$assistantName}, your AI guide at {$brand}. Before we get started, may I know your name?";
                } else {
                    $reply = $isHindi
                        ? "Namaste! Main aapko iske baare mein zaroor poori jaankari dunga. Aage badhne se pehle, kya main aapka shubh naam jaan sakta hoon?"
                        : "Hello! I would be glad to help you with that. Before we proceed, could you please tell me your name so I can assist you better?";
                }
                return ['reply' => $reply, 'meta' => $meta];
            }

            if ($isHumanRequest) {
                $meta['intent'] = 'human_request';
                $meta['stage'] = 'HUMAN_REQUIRED';
                $meta['priority'] = 'URGENT';
                $meta['human_required'] = true;
                $meta['recommended_action'] = 'Contact prospect immediately';
                $reply = $isHindi
                    ? "Maine aapki request hamari team ko forward kar di hai. {$brand} ke senior specialist aapse jaldi hi connect karenge. Agar aap chahein toh niche diye WhatsApp button par click karke turant chat continue kar sakte hain!"
                    : "I've flagged your request for our team at {$brand}. A team specialist will connect with you shortly. You can also tap the WhatsApp button below to chat with us directly!";
            } elseif ($isGreeting) {
                $meta['intent'] = 'greeting';
                $reply = $isHindi
                    ? "Namaste {$visitorGreetingName}! 👋 Main {$assistantName} hoon, {$brand} ka AI assistant. Main aapko hamare courses, fees, services aur programs ke baare me poori jaankari de sakta hoon. Aap kis baare me jaanna chahte hain?"
                    : "Hello {$visitorGreetingName}! 👋 I'm {$assistantName}, your AI assistant at {$brand}. How can I best assist you with our offerings and programs today?";
            } elseif ($matchedSource && !empty($matchedSource['content'])) {
                // Grounded directly in matched knowledge source
                $meta['intent'] = ($isFeeInquiry || $isCourseInquiry) ? 'purchase_interest' : 'service_inquiry';
                $meta['stage'] = 'QUALIFIED';
                $meta['priority'] = 'HIGH';
                $meta['interest'] = $matchedSource['title'];
                
                $snippet = trim($matchedSource['content']);
                if (mb_strlen($snippet) > 800) {
                    $snippet = mb_substr($snippet, 0, 800) . '...';
                }

                $reply = $isHindi
                    ? "{$snippet}\n\nAapko iske baare mein aur detail chahiye ya batch timing aur syllabus check karna hai? Kripya apna Naam aur WhatsApp number share karein taaki hum aapko poori guide bhej sakein!"
                    : "{$snippet}\n\nWould you like more details on the curriculum, timings, or enrollment? Please share your Name and WhatsApp number so our team can send you the complete information!";
            } elseif ($isFeeInquiry || $isCourseInquiry) {
                $meta['intent'] = 'purchase_interest';
                $meta['stage'] = 'QUALIFIED';
                $meta['priority'] = 'HIGH';
                $meta['recommended_action'] = 'Share detailed fee structure and syllabus';

                $reply = $isHindi
                    ? "Hamare paas industry-aligned practical programs available hain. Fees aur batch structure ki poori details ke liye, kripya apna **Naam aur WhatsApp number** share karein taaki hum aapko detailed brochure aur syllabus turant send kar sakein!"
                    : "We offer industry-aligned practical programs. For the complete fee structure and upcoming batch schedules, please share your **Name and WhatsApp number** so our team can send you the detailed brochure!";
            } elseif ($isServiceInquiry) {
                $meta['intent'] = 'service_inquiry';
                $meta['stage'] = 'ENGAGED';
                $meta['priority'] = 'MEDIUM';
                $meta['recommended_action'] = 'Provide consultative services overview';

                $reply = $isHindi
                    ? "{$brand} practical, career-oriented programs aur professional solutions provide karta hai. Aapko kis specific program ya requirement ke liye guidance chahiye? Aap apna sawal pooch sakte hain ya WhatsApp number share kar sakte hain!"
                    : "{$brand} provides career-oriented practical programs and professional services. What specific area or requirement are you looking for? Feel free to ask or share your WhatsApp number!";
            } else {
                $reply = $isHindi
                    ? "Namaste! Main {$assistantName} hoon, {$brand} ka AI representative. Main aapko hamare programs, fees, batch schedules aur admission ke baare me poori jaankari de sakta hoon.\n\nAapko kis baare me help chahiye? Kripya apna sawal poochein ya apna Naam aur WhatsApp number share karein!"
                    : "Hello! I'm {$assistantName}, your AI representative at {$brand}. How can I best help you today? Feel free to ask your question or share your Name and WhatsApp number!";
            }

            if ($hasPriorInquiry && !empty($visitorGreetingName) && !preg_match('/^(Namaste|Hello|Hi)/i', $reply)) {
                $reply = $isHindi
                    ? "Namaste {$visitorGreetingName}! 👋\n\n{$reply}"
                    : "Hello {$visitorGreetingName}! 👋\n\n{$reply}";
            }

            return ['reply' => $reply, 'meta' => $meta];
        }
    }

    // Multi-Model Resilient Groq LLM Inference
    $rawReply = '';
    $aiSuccess = false;

    if (defined('GROQ_API_KEY') && !empty(GROQ_API_KEY) && GROQ_API_KEY !== 'YOUR_GROQ_API_KEY_HERE') {
        $candidateModels = ['qwen/qwen3.8-27b', 'openai/gpt-oss-120b', 'openai/gpt-oss-20b'];

        foreach ($candidateModels as $modelCandidate) {
            $groqPayload = [
                'model' => $modelCandidate,
                'messages' => array_merge(
                    [['role' => 'system', 'content' => $systemPrompt]],
                    $historyMessages,
                    [['role' => 'user', 'content' => $messageText]]
                ),
                'max_tokens' => 450,
                'temperature' => 0.3
            ];

            $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . GROQ_API_KEY,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($groqPayload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $groqResponse = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && !empty($groqResponse)) {
                $jsonRes = json_decode($groqResponse, true);
                if (!empty($jsonRes['choices'][0]['message']['content'])) {
                    $rawReply = trim($jsonRes['choices'][0]['message']['content']);
                    $aiSuccess = true;
                    break;
                }
            }
        }
    }

    // Parse reply and structured metadata
    $publicReply = $rawReply;
    $structuredMeta = null;

    if (strpos($rawReply, '---INTERNAL_METADATA---') !== false) {
        $parts = explode('---INTERNAL_METADATA---', $rawReply, 2);
        $publicReply = trim($parts[0]);
        $metaJsonStr = trim($parts[1]);
        $decoded = json_decode($metaJsonStr, true);
        if (is_array($decoded)) {
            $structuredMeta = $decoded;
        }
    }

    // Intelligent Human-Like Knowledge Base RAG Response if LLM call was omitted or failed
    if (!$aiSuccess || empty($publicReply)) {
        $ragResult = synthesizeKnowledgeResponse($messageText, $knowledgeList, $company, $assistantName, $visitorGreetingName, $brandDisplayName, $historyMessages);
        $publicReply = $ragResult['reply'];
        if (!$structuredMeta) {
            $structuredMeta = $ragResult['meta'];
        }
    }

    if (!is_array($structuredMeta)) {
        $structuredMeta = [
            'intent'             => 'general',
            'stage'              => 'ENGAGED',
            'priority'           => 'MEDIUM',
            'interest'           => $brandDisplayName,
            'budget'             => null,
            'timeline'           => null,
            'summary'            => 'Visitor inquiry with Cai',
            'human_required'     => false,
            'buying_signals'     => [],
            'objections'         => [],
            'recommended_action' => 'Review inquiry and follow up',
            'estimated_value'    => 45000
        ];
    }


    // 6. Canonical Pipeline Stage & Qualification Mapping
    $stgStmt = $pdo->prepare("SELECT id, name, slug, stage_order FROM `pipeline_stages` WHERE `company_id` = ? ORDER BY `stage_order` ASC");
    $stgStmt->execute([$companyId]);
    $companyStages = $stgStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($companyStages)) {
        $defaultStages = [
            ['New Inquiry', 'new', 1, '#4F46E5'],
            ['Contacted', 'contacted', 2, '#0EA5E9'],
            ['Qualified', 'qualified', 3, '#F59E0B'],
            ['Proposal / Demo', 'proposal', 4, '#8B5CF6'],
            ['Won / Enrolled', 'won', 5, '#10B981'],
            ['Lost', 'lost', 6, '#EF4444'],
        ];
        $insStg = $pdo->prepare("INSERT INTO `pipeline_stages` (`company_id`, `name`, `slug`, `stage_order`, `color_code`, `is_default`) VALUES (?, ?, ?, ?, ?, 1)");
        foreach ($defaultStages as $s) {
            $insStg->execute([$companyId, $s[0], $s[1], $s[2], $s[3]]);
        }
        $stgStmt->execute([$companyId]);
        $companyStages = $stgStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stageBySlug = [];
    foreach ($companyStages as $stg) {
        $stageBySlug[strtolower($stg['slug'])] = $stg;
    }

    $rawStage = strtoupper($structuredMeta['stage'] ?? '');
    $rawIntent = strtolower($structuredMeta['intent'] ?? '');
    $hasPhoneOrEmail = (!empty($visitorPhone) || !empty($visitorEmail) || !empty($customer['phone']) || !empty($customer['email']));

    $isPricingOrCommercial = in_array($rawIntent, ['purchase_interest', 'fee_inquiry', 'pricing', 'commercial']) ||
                            in_array($rawStage, ['QUALIFIED', 'HIGH_INTENT', 'PURCHASE']) ||
                            preg_match('/(pay|paid|fee|fees|cost|price|pricing|emi|installment|tuition|rate|charge|buy|starter|growth|scale|purchase|admission|enroll|enrollment|subscription|subscribe)/i', $messageText);

    $isDemoOrMeeting = in_array($rawIntent, ['demo_request', 'meeting_request']) ||
                       in_array($rawStage, ['PROPOSAL', 'DEMO', 'PROPOSAL_DEMO']) ||
                       preg_match('/(demo|meeting|schedule|walkthrough|proposal|presentation)/i', $messageText);

    $isHumanRequest = !empty($structuredMeta['human_required']) ||
                      $rawIntent === 'human_request' ||
                      in_array($rawStage, ['HUMAN_REQUIRED', 'HUMAN_REQUEST']) ||
                      preg_match('/(human|person|counselor|advisor|agent|speak|talk|call|team|sales representative|executive)/i', $messageText);

    $isWon = in_array($rawStage, ['WON', 'ENROLLED']) || $rawIntent === 'enrolled';

    if ($isWon) {
        $targetSlug = 'won';
    } elseif ($isDemoOrMeeting) {
        $targetSlug = 'proposal';
    } elseif ($isPricingOrCommercial || $isHumanRequest) {
        $targetSlug = 'qualified';
    } elseif ($hasPhoneOrEmail || $rawStage === 'ENGAGED' || $rawStage === 'INTERESTED' || count($historyMessages) > 0) {
        $targetSlug = 'contacted';
    } else {
        $targetSlug = 'new';
    }

    $resolvedStage = $stageBySlug[$targetSlug] 
        ?? ($targetSlug === 'proposal' ? ($stageBySlug['demo'] ?? null) : null)
        ?? ($stageBySlug['qualified'] ?? null)
        ?? $companyStages[0];

    $resolvedStageId = (int)$resolvedStage['id'];
    $resolvedStageName = $resolvedStage['name'];

    // Stage order ranking (higher stage cannot downgrade)
    $stageRankMap = [
        'new'       => 1,
        'contacted' => 2,
        'qualified' => 3,
        'proposal'  => 4,
        'won'       => 5,
        'lost'      => 0
    ];
    $newRank = $stageRankMap[$targetSlug] ?? 2;

    // Check existing lead
    $lead = null;
    if ($leadId) {
        $lCheck = $pdo->prepare("SELECT * FROM `leads` WHERE `id` = ? AND `company_id` = ? LIMIT 1");
        $lCheck->execute([$leadId, $companyId]);
        $lead = $lCheck->fetch();
    }
    if (!$lead) {
        $lCheck = $pdo->prepare("
            SELECT * FROM `leads` 
            WHERE `company_id` = ? AND (`conversation_id` = ? OR `customer_id` = ?) AND `status` = 'open'
            ORDER BY id DESC LIMIT 1
        ");
        $lCheck->execute([$companyId, $conversationId, $customerId]);
        $lead = $lCheck->fetch();
    }

    if ($lead) {
        $existingStageSlug = '';
        if (!empty($lead['stage_id'])) {
            foreach ($companyStages as $cs) {
                if ((int)$cs['id'] === (int)$lead['stage_id']) {
                    $existingStageSlug = strtolower($cs['slug']);
                    break;
                }
            }
        }
        if (!$existingStageSlug) {
            $existingStageSlug = strtolower($lead['stage_name'] ?? '');
        }
        $existingRank = $stageRankMap[$existingStageSlug] ?? 1;

        // Preserve higher or equal stage
        if ($existingRank >= $newRank && $existingRank > 1) {
            $resolvedStageId = (int)$lead['stage_id'] ?: $resolvedStageId;
            $resolvedStageName = $lead['stage_name'] ?: $resolvedStageName;
        }
    }

    // Comprehensive Human Action Detection Triggers (Section 8)
    $isPaymentOrBillingIssue = (bool)preg_match('/\b(payment failed|card declined|invoice issue|refund|cancel|cancellation|fraud|chargeback|money back|billing error|transaction failed)\b/i', $messageText);

    $isDissatisfied = (bool)preg_match('/\b(unresolved|not helpful|useless|stuck|terrible|frustrated|angry|escalat|manager|supervisor|complaint|wrong answer)\b/i', $messageText);

    $isHighIntentPurchase = in_array($rawIntent, ['purchase_interest', 'commercial']) ||
                            (bool)preg_match('/\b(pro plan|enterprise|annual plan|custom pricing|custom quote|quotation|volume discount|bulk discount|ready to buy|upgrade my plan|buy license|subscribe now)\b/i', $messageText);

    $isHumanRequest = !empty($structuredMeta['human_required']) ||
                      $rawIntent === 'human_request' ||
                      in_array($rawStage, ['HUMAN_REQUIRED', 'HUMAN_REQUEST']) ||
                      (bool)preg_match('/\b(human|person|counselor|advisor|agent|speak|talk|call me|connect to team|sales representative|executive|operator|representative|specialist)\b/i', $messageText);

    $isHumanAction = $isPaymentOrBillingIssue || $isDissatisfied || $isHighIntentPurchase || $isHumanRequest || $isDemoOrMeeting;
    
    $humanActionPriority = 'NORMAL';
    $humanActionReason = '';

    if ($isPaymentOrBillingIssue) {
        $humanActionPriority = 'URGENT';
        $humanActionReason = 'Payment or billing issue requires immediate human resolution.';
    } elseif ($isDissatisfied) {
        $humanActionPriority = 'URGENT';
        $humanActionReason = 'Customer dissatisfaction detected; escalated for human attention.';
    } elseif ($isHumanRequest) {
        $humanActionPriority = 'URGENT';
        $humanActionReason = 'Visitor explicitly requested to speak with a human team member.';
    } elseif ($isHighIntentPurchase) {
        $humanActionPriority = 'HIGH';
        $humanActionReason = 'High commercial buying intent detected (plan purchase / upgrade inquiry).';
    } elseif ($isDemoOrMeeting) {
        $humanActionPriority = 'HIGH';
        $humanActionReason = 'Demonstration or consultation meeting requested.';
    } elseif ($isHumanAction) {
        $humanActionPriority = 'NORMAL';
        $humanActionReason = 'Inquiry requires human specialist review.';
    }

    // Priority ranking (cannot downgrade)
    $priorityRank = ['LOW' => 1, 'MEDIUM' => 2, 'HIGH' => 3, 'URGENT' => 4];
    $newPriority = $isHumanAction 
        ? $humanActionPriority 
        : (($isPricingOrCommercial || $isDemoOrMeeting) ? 'HIGH' : ($hasPhoneOrEmail ? 'MEDIUM' : 'LOW'));

    if ($lead && isset($priorityRank[$lead['priority']]) && $priorityRank[$lead['priority']] > $priorityRank[$newPriority]) {
        $newPriority = $lead['priority'];
    }

    $intentLevel = strtolower($newPriority);
    $estimatedVal = (int)($structuredMeta['estimated_value'] ?? 45000);

    // Contextual Cai Summary & Recommended Action (Section 12)
    $caiSummaryLines = [];
    $caiSummaryLines[] = "Customer asks: “" . substr($messageText, 0, 120) . "”";
    if ($isHighIntentPurchase) $caiSummaryLines[] = "• Buying Intent: High probability upgrade / commercial inquiry";
    if ($isDemoOrMeeting) $caiSummaryLines[] = "• Request: Demo walkthrough or consultation inquiry";
    if ($isPaymentOrBillingIssue) $caiSummaryLines[] = "• Critical Issue: Payment / billing transaction dispute";
    if ($isHumanRequest) $caiSummaryLines[] = "• Handoff: Customer requested human team interaction";
    if ($hasPhoneOrEmail) $caiSummaryLines[] = "• Contact: " . ($visitorPhone ?: $customer['phone'] ?: '') . " " . ($visitorEmail ?: $customer['email'] ?: '');
    $summary = implode("\n", $caiSummaryLines);

    if ($isPaymentOrBillingIssue) {
        $recommendedAction = "Inspect payment logs and contact customer to resolve transaction.";
    } elseif ($isHumanRequest) {
        $recommendedAction = "Take over conversation or connect with prospect on WhatsApp.";
    } elseif ($isHighIntentPurchase) {
        $recommendedAction = "Contact prospect regarding plan tiers, seats and commercial terms.";
    } elseif ($isDemoOrMeeting) {
        $recommendedAction = "Confirm demo consultation slot with prospect.";
    } else {
        $recommendedAction = $structuredMeta['recommended_action'] ?? "Review inquiry and follow up if needed.";
    }

    // Check for multi-lingual Appointment Intent and compute available slots (Section 8, 9 & 10)
    $isApptIntent = isAppointmentIntent($messageText);
    $appointmentSlots = [];
    if ($isApptIntent) {
        $canBookAppointments = !empty($entitlements['capabilities']['can_use_ai_appointment_booking']);
        if ($canBookAppointments) {
            $appointmentSlots = getAvailableAppointmentSlots($pdo, $companyId, 4);
            if (!empty($appointmentSlots) && strpos($publicReply, 'slot') === false && strpos($publicReply, 'Slot') === false) {
                $publicReply .= "\n\n📅 **Available Consultation / Demo Slots:**\nPlease choose a convenient time slot below:";
            }
        }
    }

    // Digital Assets & Syllabi Auto-Detection & Automated Email Dispatch
    $matchedAsset = AssetHelper::matchAsset($pdo, $companyId, $messageText, $historyMessages);
    $sharedAssetPayload = null;
    $emailDispatched = false;
    $assetRecipientEmail = !empty($visitorEmail) ? $visitorEmail : (!empty($customer['email']) ? $customer['email'] : '');

    if ($matchedAsset) {
        if (!empty($assetRecipientEmail)) {
            $emailDispatched = AssetHelper::dispatchAssetEmail(
                $pdo,
                $companyId,
                $assetRecipientEmail,
                $visitorGreetingName ?: ($customer['name'] ?? 'there'),
                $matchedAsset,
                $brandDisplayName ?: $company['name']
            );
            if (mb_strpos($publicReply, 'email') === false && mb_strpos($publicReply, 'bhej') === false && mb_strpos($publicReply, 'send') === false) {
                $publicReply .= "\n\n📄 **{$matchedAsset['title']}** is ready for you below! I have also dispatched a copy directly to your email (**{$assetRecipientEmail}**). ✉️";
            }
        } else {
            if (mb_strpos($publicReply, 'email') === false && mb_strpos($publicReply, 'inbox') === false && mb_strpos($publicReply, 'download') === false) {
                $publicReply .= "\n\n📄 **{$matchedAsset['title']}** is ready for you below! If you'd like me to email you a copy as well, just drop your email address.";
            }
        }

        // Track dispatch count in DB
        try {
            $pdo->prepare("UPDATE company_assets SET download_count = download_count + 1 WHERE id = ?")->execute([(int)$matchedAsset['id']]);
        } catch (Exception $e) {}

        $sharedAssetPayload = [
            'id'               => (int)$matchedAsset['id'],
            'title'            => $matchedAsset['title'],
            'category'         => $matchedAsset['category'],
            'description'      => $matchedAsset['description'] ?? '',
            'file_name'        => $matchedAsset['file_name'],
            'file_size'        => (int)$matchedAsset['file_size'],
            'file_type'        => $matchedAsset['file_type'],
            'download_url'     => '../api/assets.php?action=download&id=' . (int)$matchedAsset['id'],
            'email_dispatched' => (bool)$emailDispatched,
            'recipient_email'  => $assetRecipientEmail ?: null
        ];
    }

    // 7. Persistence: Save Messages
    $pdo->prepare("
        INSERT INTO `messages` 
        (`company_id`, `conversation_id`, `sender_type`, `message_text`, `channel`, `session_id`, `detected_intent`, `created_at`)
        VALUES (?, ?, 'visitor', ?, 'web', ?, ?, NOW())
    ")->execute([$companyId, $conversationId, $messageText, $sessionId, $structuredMeta['intent'] ?? 'general']);

    $pdo->prepare("
        INSERT INTO `messages` 
        (`company_id`, `conversation_id`, `sender_type`, `message_text`, `channel`, `session_id`, `detected_intent`, `metadata_json`, `created_at`)
        VALUES (?, ?, 'ai', ?, 'web', ?, ?, ?, NOW())
    ")->execute([
        $companyId,
        $conversationId,
        $publicReply,
        $sessionId,
        $structuredMeta['intent'] ?? 'general',
        json_encode([
            'assistant_name'     => $assistantName,
            'stage'              => $resolvedStageName,
            'priority'           => $newPriority,
            'human_action'       => (bool)$isHumanAction,
            'action_reason'      => $humanActionReason,
            'appointment_intent' => $isApptIntent,
            'shared_asset_id'    => $matchedAsset ? (int)$matchedAsset['id'] : null,
            'email_dispatched'   => (bool)$emailDispatched
        ])
    ]);

    // Update conversation status:
    // When Human Action is triggered, flag as human_requested while keeping ownership=ai until agent Take Over
    $convStatus = $isHumanAction ? 'human_requested' : 'ai_handling';
    $convOwnership = 'ai';
    $unreadHuman = $isHumanAction ? 1 : 0;

    $pdo->prepare("
        UPDATE `conversations`
        SET `customer_id` = ?,
            `status` = ?,
            `ownership` = ?,
            `unread_human` = GREATEST(`unread_human`, ?),
            `last_message_preview` = ?,
            `last_message_at` = NOW()
        WHERE `id` = ? AND `company_id` = ?
    ")->execute([$customerId, $convStatus, $convOwnership, $unreadHuman, substr($publicReply, 0, 150), $conversationId, $companyId]);

    // 8. CRM Auto-Update & Qualification
    // Determine radar alert activation (High priority, urgent intent, or human intervention needed)
    $isRadarActive = ($newPriority === 'HIGH' || $newPriority === 'URGENT' || !empty($isHumanAction)) ? 1 : 0;

    if ($lead) {
        $leadId = (int)$lead['id'];
        $pdo->prepare("
            UPDATE `leads`
            SET `conversation_id` = ?,
                `stage_name` = ?,
                `stage_id` = ?,
                `priority` = ?,
                `intent_level` = ?,
                `opportunity_value` = GREATEST(`opportunity_value`, ?),
                `ai_summary` = ?,
                `radar_reason` = ?,
                `radar_recommended_action` = ?,
                `is_radar_active` = ?,
                `last_activity_at` = NOW(),
                `updated_at` = NOW()
            WHERE `id` = ? AND `company_id` = ?
        ")->execute([
            $conversationId,
            $resolvedStageName,
            $resolvedStageId,
            $newPriority,
            $intentLevel,
            $estimatedVal,
            $summary,
            $summary,
            $recommendedAction,
            (int)$isRadarActive,
            $leadId,
            $companyId
        ]);
    } else {
        $leadTitle = !empty($customer['name']) ? $customer['name'] : 'Website Lead (' . ($visitorPhone ?: 'Prospect') . ')';
        $pdo->prepare("
            INSERT INTO `leads`
            (`company_id`, `customer_id`, `conversation_id`, `title`, `stage_name`, `stage_id`, `priority`, `intent_level`, `opportunity_value`, `source`, `status`, `ai_summary`, `radar_reason`, `radar_recommended_action`, `is_radar_active`, `last_activity_at`, `created_at`, `updated_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'WEBSITE_WIDGET', 'open', ?, ?, ?, ?, NOW(), NOW(), NOW())
        ")->execute([
            $companyId,
            $customerId,
            $conversationId,
            $leadTitle,
            $resolvedStageName,
            $resolvedStageId,
            $newPriority,
            $intentLevel,
            $estimatedVal,
            $summary,
            $summary,
            $recommendedAction,
            (int)$isRadarActive
        ]);
        $leadId = (int)$pdo->lastInsertId();
    }

    // 9. Sync AI Lead Artifact & Configurable Lead Scoring (Sections 7, 8, 9 & 26)
    require_once __DIR__ . '/scoring_engine.php';
    require_once __DIR__ . '/events.php';

    $buyingSignals = [];
    if ($isPricingOrCommercial) $buyingSignals[] = 'pricing_inquired';
    if (!empty($structuredMeta['budget']) || $estimatedVal >= 50000) $buyingSignals[] = 'budget_confirmed';
    if ($isDemoOrMeeting || $isApptIntent) $buyingSignals[] = 'demo_requested';
    if (!empty($visitorPhone)) $buyingSignals[] = 'phone_shared';
    if (!empty($structuredMeta['buying_signals']) && is_array($structuredMeta['buying_signals'])) {
        $buyingSignals = array_unique(array_merge($buyingSignals, $structuredMeta['buying_signals']));
    }

    $artifactData = [
        'customer_name'          => !empty($customer['name']) ? $customer['name'] : $visitorDisplayName,
        'phone'                  => $visitorPhone ?: ($customer['phone'] ?? ''),
        'email'                  => $visitorEmail ?: ($customer['email'] ?? ''),
        'interested_service'     => $structuredMeta['interest'] ?? $company['industry'],
        'budget'                 => $structuredMeta['budget'] ?? ($estimatedVal ? "₹" . number_format($estimatedVal) : null),
        'timeline'               => $structuredMeta['timeline'] ?? null,
        'intent'                 => $structuredMeta['intent'] ?? 'commercial',
        'current_stage'          => $resolvedStageName,
        'conversation_summary'   => $summary,
        'recommended_action'     => $recommendedAction,
        'conversation_id'        => $conversationId,
        'human_required'         => (bool)$isHumanAction,
        'human_attention_required' => (bool)$isHumanAction,
        'human_attention_status' => $isHumanAction ? 'attention_required' : 'none',
        'priority_reason'        => $humanActionReason,
        'buying_signals'         => $buyingSignals,
        'objections'             => $structuredMeta['objections'] ?? []
    ];

    $syncedArtifact = syncLeadArtifact($pdo, $companyId, $leadId, $artifactData);

    // Refresh lead priority from scoring engine
    if (!empty($syncedArtifact['priority'])) {
        $newPriority = $syncedArtifact['priority'];
    }

    // Dispatch milestone events
    if ($isHumanAction) {
        dispatchSystemEvent($pdo, $companyId, 'human_attention.required', [
            'lead_id'         => $leadId,
            'customer_id'     => $customerId,
            'conversation_id' => $conversationId,
            'description'     => "Human Action required: {$humanActionReason}",
            'data'            => [
                'reason'             => $humanActionReason,
                'priority'           => $humanActionPriority,
                'summary'            => $summary,
                'recommended_action' => $recommendedAction
            ]
        ]);
    } elseif ($resolvedStageName === 'QUALIFIED' || $newPriority === 'HIGH' || $newPriority === 'URGENT') {
        dispatchSystemEvent($pdo, $companyId, 'lead.qualified', [
            'lead_id'         => $leadId,
            'customer_id'     => $customerId,
            'conversation_id' => $conversationId,
            'description'     => "Lead qualified: Score {$syncedArtifact['lead_score']}/100 ({$syncedArtifact['priority']})",
            'data'            => ['score' => $syncedArtifact['lead_score'], 'priority' => $syncedArtifact['priority'], 'opportunity_value' => $estimatedVal]
        ]);
    }

    // Automated Salesperson & Owner Alert Dispatch (Instant Email & WhatsApp Notification)
    if (!empty($leadId) && ($hasPhoneOrEmail || $isHumanAction || in_array($newPriority, ['HIGH', 'URGENT']))) {
        require_once __DIR__ . '/alerts.php';
        sendSalespersonAssignmentAlert(
            $pdo, 
            $companyId, 
            $leadId, 
            null, 
            $isHumanAction ? 'HUMAN_REQUIRED' : ($hasPhoneOrEmail ? 'LEAD_ASSIGNED' : 'HIGH_INTENT_DETECTED')
        );

        // Multi-Channel & Google Sheets Real-Time Sync
        try {
            require_once __DIR__ . '/../includes/channel_sync.php';
            ChannelSync::dispatchLead($pdo, $companyId, $leadId, [
                'phone'   => $visitorPhone ?: ($customer['phone'] ?? ''),
                'email'   => $visitorEmail ?: ($customer['email'] ?? ''),
                'intent'  => $structuredMeta['intent'] ?? ($structuredMeta['interest'] ?? $resolvedStageName),
                'source'  => 'Website AI Chat'
            ]);
        } catch (Throwable $e) {
            error_log("[Chat] ChannelSync lead dispatch error: " . $e->getMessage());
        }
    }

    // 10. Contextual WhatsApp & Instagram CTA Logic (Sections 4, 5, 6 & 12)
    $isChatEnding = (bool)preg_match('/\b(bye|goodbye|alvida|thank you|thanks|dhanyawad|that\'?s all|end chat|chat end|khatam|done|ok thanks|okay thanks)\b/i', $messageText) || 
                    in_array($rawIntent, ['conversation_end', 'goodbye', 'closing']) ||
                    $isWon;

    $isExplicitHandoff = $isHumanRequest || 
                         (bool)preg_match('/(whatsapp|wa\.me|call me|contact me|phone pe baat|number do|direct call|instagram|insta|ig)/i', $messageText);

    $showWhatsappCta = false;
    $whatsappUrl = '';
    $handoffToken = '';

    $canUseWhatsapp = !empty($entitlements['capabilities']['can_use_whatsapp_continuation']);
    if ($canUseWhatsapp && ($isEnabledInWidget ?? true)) {
        $wStmt = $pdo->prepare("SELECT whatsapp_number, enable_whatsapp_continue FROM `widget_settings` WHERE `company_id` = ? LIMIT 1");
        $wStmt->execute([$companyId]);
        $wSetting = $wStmt->fetch();

        $waNumber = !empty($wSetting['whatsapp_number']) ? preg_replace('/[^0-9]/', '', $wSetting['whatsapp_number']) : '';
        $isEnabledInWidget = (bool)($wSetting['enable_whatsapp_continue'] ?? 1);

        if ($isEnabledInWidget && !empty($waNumber) && ($isExplicitHandoff || $isChatEnding)) {
            $showWhatsappCta = true;
            // Generate short-lived opaque handoff token (Section 24)
            $handoffToken = 'wh_' . bin2hex(random_bytes(16));
            $pdo->prepare("
                INSERT INTO `whatsapp_handoffs`
                (`company_id`, `handoff_token`, `customer_id`, `lead_id`, `web_conversation_id`, `phone`, `status`, `expires_at`, `created_at`)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL 2 HOUR), NOW())
            ")->execute([
                $companyId,
                $handoffToken,
                $customerId,
                $leadId,
                $conversationId,
                $customer['phone'] ?? null
            ]);

            $greetingRef = "Hi! I was chatting with Cai on your website. [Ref: {$handoffToken}]";
            $whatsappUrl = "https://wa.me/{$waNumber}?text=" . urlencode($greetingRef);
        }
    }

    // Instagram Handoff CTA (Section 4 & 5)
    $showInstagramCta = false;
    $instagramUrl = '';
    $instagramHandoffToken = '';

    $igStmt = $pdo->prepare("SELECT * FROM `company_instagram_configs` WHERE `company_id` = ? AND `status` = 'connected' LIMIT 1");
    $igStmt->execute([$companyId]);
    $igRow = $igStmt->fetch(PDO::FETCH_ASSOC);

    if ($igRow && ($isExplicitHandoff || $isChatEnding || preg_match('/(instagram|insta|ig)/i', $messageText))) {
        $showInstagramCta = true;
        $instagramHandoffToken = 'CP-IG-' . $companyId . '-' . bin2hex(random_bytes(6));
        $pdo->prepare("
            INSERT INTO `instagram_handoffs`
            (`company_id`, `handoff_token`, `session_id`, `customer_id`, `lead_id`, `web_conversation_id`, `status`, `expires_at`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW())
        ")->execute([
            $companyId,
            $instagramHandoffToken,
            $sessionId,
            $customerId,
            $leadId,
            $conversationId
        ]);

        $igHandle = !empty($igRow['instagram_username']) ? $igRow['instagram_username'] : ($igRow['instagram_account_id'] ?: 'cuboidpilot');
        $instagramUrl = "https://ig.me/m/" . urlencode($igHandle) . "?ref=" . urlencode($instagramHandoffToken);
    }

    $outputPayload = [
        'success'            => true,
        'conversation_id'    => $conversationId,
        'lead_id'            => $leadId,
        'session_id'         => $sessionId,
        'reply'              => $publicReply,
        'sender'             => 'ai',
        'assistant_name'     => $assistantName,
        'stage'              => $resolvedStageName,
        'priority'           => $newPriority,
        'lead_score'         => $syncedArtifact['lead_score'] ?? 0,
        'priority_reason'    => $syncedArtifact['priority_reason'] ?? '',
        'lead_artifact'      => $syncedArtifact ?? null,
        'lead_captured'      => (!empty($customer['phone']) || !empty($customer['email']) || !empty($visitorPhone) || !empty($visitorEmail)),
        'appointment_intent' => $isApptIntent,
        'appointment_slots'  => $appointmentSlots,
        'shared_asset'       => $sharedAssetPayload,
        'chat_ended'         => $isChatEnding,
        'whatsapp_cta'       => [
            'show'          => $showWhatsappCta,
            'url'           => $whatsappUrl,
            'handoff_token' => $handoffToken,
            'label'         => $isChatEnding
                ? "Chat ended. If you need any further help, feel free to continue on WhatsApp:"
                : (!empty($visitorDisplayName)
                    ? "Connect with our team on WhatsApp, {$visitorDisplayName}:"
                    : "Prefer instant support? Continue on WhatsApp:")
        ],
        'instagram_cta'      => [
            'show'          => $showInstagramCta,
            'url'           => $instagramUrl,
            'handoff_token' => $instagramHandoffToken,
            'label'         => 'Continue on Instagram'
        ],
        'timestamp'          => 'Just now'
    ];

    $jsonOutput = json_encode($outputPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($jsonOutput === false) {
        array_walk_recursive($outputPayload, function (&$val) {
            if (is_string($val)) {
                $val = mb_convert_encoding($val, 'UTF-8', 'UTF-8');
            }
        });
        $jsonOutput = json_encode($outputPayload, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    echo $jsonOutput;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'AI Engine Error: ' . $e->getMessage(),
        'trace'   => $e->getFile() . ':' . $e->getLine()
    ]);
}
