<?php
/**
 * CUBOIDPILOT — CONVERSATIONAL AI ENGINE (API)
 * Strict multi-tenant isolation, grounded company knowledge,
 * structured intent qualification, CRM auto-update, and contextual WhatsApp continuation.
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Company-Key");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/entitlements.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
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

    if (empty($messageText)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Message text cannot be empty']);
        exit;
    }

    // 1. Identify Tenant Company
    $company = null;
    if ($companyKey === 'cp_live_cuboidpilot' || $companyKey === 'cuboidpilot') {
        $companyKey = 'cp_live_cuboidsoft';
    }
    if (!empty($companyKey) && $companyKey !== 'default') {
        // If logged into dashboard as specific company, prioritize active session
        if (!empty($_SESSION['company_id']) && ($companyKey === 'cp_live_cuboidsoft' || $companyKey === 'default')) {
            $stmt = $pdo->prepare("SELECT * FROM `companies` WHERE `id` = ? LIMIT 1");
            $stmt->execute([(int)$_SESSION['company_id']]);
            $company = $stmt->fetch();
        }

        if (!$company) {
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
    $customInstructions = !empty($aiConfig['custom_instructions']) ? $aiConfig['custom_instructions'] : '';
    $aiTone = !empty($aiConfig['tone']) ? $aiConfig['tone'] : 'professional';
    $aiObjective = !empty($aiConfig['primary_objective']) ? $aiConfig['primary_objective'] : 'generate_leads';

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
        $compiledFacts[] = "[Source: {$src['title']}] {$src['content']}";
    }
    $knowledgeContext = !empty($compiledFacts) 
        ? implode("\n", $compiledFacts)
        : "Business Name: {$company['name']}\nIndustry: {$company['industry']}\nLocation: {$company['city']}, {$company['country']}";

    // 3. Entity Extraction from Visitor Message
    if (empty($visitorPhone) && preg_match('/(?:\+?91[\s.-]?)?[6-9]\d{9}|\b\d{10}\b|(?:\+?\d{1,3}[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', $messageText, $phoneMatch)) {
        $visitorPhone = preg_replace('/[^0-9+]/', '', $phoneMatch[0]);
    }
    if (empty($visitorEmail) && preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $messageText, $emailMatch)) {
        $visitorEmail = strtolower($emailMatch[0]);
    }
    if (empty($visitorName)) {
        if (preg_match('/(?:my name is|i am|this is|i\'m|mera naam|naam)\s+([a-zA-Z]{2,20}(?:\s+[a-zA-Z]{2,20})?)/i', $messageText, $nameMatch)) {
            $visitorName = ucwords(trim($nameMatch[1]));
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
            WHERE vs.session_token = ? AND vs.company_id = ?
            LIMIT 1
        ");
        $sessStmt->execute([$sessionId, $companyId]);
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

        if ($currentConv && ($currentConv['ownership'] === 'human' || $currentConv['status'] === 'human_handling')) {
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
    // If name is placeholder like "Prospect 987..." or "Website Visitor", do not use it in greeting
    if (preg_match('/^(prospect|website visitor)/i', $visitorDisplayName)) {
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

    $systemPrompt = "You are {$assistantName}, the consultative and official AI representative for {$company['name']} ({$company['industry']}).\n"
                  . "Visitor Information:\n"
                  . ($visitorGreetingName ? "- Name: {$visitorGreetingName}\n" : "- Name: Not specified yet\n")
                  . "Tone: {$aiTone}\n"
                  . "Primary Objective: {$aiObjective}\n"
                  . "Language: Match the visitor's language naturally (English, Hindi, or conversational Hinglish).\n\n"
                  . (!empty($customInstructions) ? "CUSTOM COMPANY INSTRUCTIONS (MANDATORY RULES):\n{$customInstructions}\n\n" : "")
                  . $memorySection
                  . "Verified Company Knowledge Base (Grounding):\n"
                  . $knowledgeContext . "\n\n"
                  . "CRITICAL CONVERSATIONAL GUIDELINES:\n"
                  . "1. SOLUTION-DRIVEN CONSULTATION: When a visitor asks for suggestions, recommendations, or asks what is good for them or how we can help:\n"
                  . "   - Directly present our company's specific verified services and programs as the solution.\n"
                  . "   - Explain practically HOW our services help them solve their problem or reach their goals.\n"
                  . "   - Conclude with a natural, friendly consultative question to keep the conversation engaging.\n"
                  . "2. STRICT FACTUAL GROUNDING: Base all details strictly on the Verified Knowledge Base above. Never invent unverified pricing, discounts, guarantees, or delivery dates.\n"
                  . "3. CONVERSATIONAL CONTINUITY: If information is already listed in the STRUCTURED CUSTOMER MEMORY above, DO NOT ask for it again.\n"
                  . "4. KEEP IT CRISP: Aim for 2-4 focused, readable sentences or short bullet points. Do not write long walls of text.\n"
                  . "5. AT THE VERY END OF YOUR RESPONSE, output the exact delimiter '---INTERNAL_METADATA---' followed by a valid JSON object analyzing the lead. The user will not see this metadata.\n"
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

    $groqPayload = [
        'model' => 'qwen/qwen3.8-27b',
        'messages' => array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $historyMessages,
            [['role' => 'user', 'content' => $messageText]]
        ),
        'max_tokens' => 450,
        'temperature' => 0.25
    ];

    $rawReply = '';
    $aiSuccess = false;

    if (defined('GROQ_API_KEY') && !empty(GROQ_API_KEY)) {
        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . GROQ_API_KEY,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($groqPayload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 7);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $groqResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && !empty($groqResponse)) {
            $jsonRes = json_decode($groqResponse, true);
            if (!empty($jsonRes['choices'][0]['message']['content'])) {
                $rawReply = trim($jsonRes['choices'][0]['message']['content']);
                $aiSuccess = true;
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

    // Robust Heuristic Fallback for Classification if LLM metadata was omitted or failed
    $msgLower = strtolower($messageText);
    if (!$structuredMeta) {
        $intent = 'general';
        $stage = 'ENGAGED';
        $priority = 'LOW';
        $estValue = 35000;
        $summary = "Visitor asked about " . substr($messageText, 0, 60);
        $action = "Follow up with details";
        $humanRequired = false;

        if (preg_match('/(pay|paid|fee|fees|cost|price|pricing|emi|installment|tuition|rate|charge|starter|growth|scale|buy|plan)/i', $msgLower)) {
            $intent = 'purchase_interest';
            $stage = 'QUALIFIED';
            $priority = 'HIGH';
            $estValue = 45000;
            $summary = "Inquired about program fees, installment plans and payment options.";
            $action = "Send fee schedule & payment breakdown";
        } elseif (preg_match('/(human|person|counselor|advisor|agent|speak|talk|call|team)/i', $msgLower)) {
            $intent = 'human_request';
            $stage = 'HUMAN_REQUIRED';
            $priority = 'URGENT';
            $humanRequired = true;
            $summary = "Requested direct human counselor discussion.";
            $action = "Call prospect immediately";
        } elseif (!empty($visitorPhone) || !empty($visitorEmail)) {
            $stage = 'INTERESTED';
            $priority = 'MEDIUM';
            $summary = "Shared contact information for curriculum inquiries.";
            $action = "Connect on phone or WhatsApp";
        }

        $structuredMeta = [
            'intent'             => $intent,
            'stage'              => $stage,
            'priority'           => $priority,
            'interest'           => $company['industry'],
            'summary'            => $summary,
            'human_required'     => $humanRequired,
            'recommended_action' => $action,
            'estimated_value'    => $estValue
        ];
    }

    // Fallback response if AI call failed
    if (!$aiSuccess || empty($publicReply)) {
        if ($structuredMeta['intent'] === 'purchase_interest') {
            $publicReply = "We offer flexible tuition options, including zero-interest 3-month and 6-month EMI installment plans. Would you like our admissions advisor to share the detailed syllabus and fee breakdown?";
        } elseif (!empty($structuredMeta['human_required'])) {
            $publicReply = "I've flagged your request for our counseling team. An admissions advisor from {$company['name']} will get in touch with you shortly.";
        } else {
            $publicReply = "Welcome to {$company['name']}! I'm here to assist with admissions criteria, course schedules, and tuition plans. How can I best help you today?";
        }
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

    // Priority ranking (cannot downgrade)
    $priorityRank = ['LOW' => 1, 'MEDIUM' => 2, 'HIGH' => 3, 'URGENT' => 4];
    $newPriority = $isHumanRequest ? 'URGENT' : (($isPricingOrCommercial || $isDemoOrMeeting) ? 'HIGH' : ($hasPhoneOrEmail ? 'MEDIUM' : 'LOW'));
    if ($lead && isset($priorityRank[$lead['priority']]) && $priorityRank[$lead['priority']] > $priorityRank[$newPriority]) {
        $newPriority = $lead['priority'];
    }

    $intentLevel = strtolower($newPriority);
    $estimatedVal = (int)($structuredMeta['estimated_value'] ?? 45000);
    $summary = $structuredMeta['summary'] ?? "Inquired via AI Assistant";
    $recommendedAction = $structuredMeta['recommended_action'] ?? ($isHumanRequest ? "Call prospect immediately" : "Follow up with visitor");
    $isRadarActive = ($newPriority === 'HIGH' || $newPriority === 'URGENT' || $isHumanRequest || $isPricingOrCommercial || ($lead && $lead['is_radar_active'])) ? 1 : 0;

    // 7. Persistence: Save Messages
    $pdo->prepare("
        INSERT INTO `messages` 
        (`company_id`, `conversation_id`, `sender_type`, `message_text`, `detected_intent`, `created_at`)
        VALUES (?, ?, 'visitor', ?, ?, NOW())
    ")->execute([$companyId, $conversationId, $messageText, $structuredMeta['intent']]);

    $pdo->prepare("
        INSERT INTO `messages` 
        (`company_id`, `conversation_id`, `sender_type`, `message_text`, `detected_intent`, `metadata_json`, `created_at`)
        VALUES (?, ?, 'ai', ?, ?, ?, NOW())
    ")->execute([
        $companyId,
        $conversationId,
        $publicReply,
        $structuredMeta['intent'],
        json_encode([
            'assistant_name' => $assistantName,
            'stage'          => $resolvedStageName,
            'priority'       => $newPriority,
            'human_required' => (bool)$isHumanRequest
        ])
    ]);

    // Update conversation preview and status
    $convStatus = $isHumanRequest ? 'human_requested' : 'ai_handling';
    $convOwnership = $isHumanRequest ? 'human' : 'ai';
    $unreadHuman = $isHumanRequest ? 1 : 0;

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
            $isRadarActive,
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
            $isRadarActive
        ]);
        $leadId = (int)$pdo->lastInsertId();
    }

    // 9. Sync AI Lead Artifact & Configurable Lead Scoring (Sections 7, 8, 9 & 26)
    require_once __DIR__ . '/scoring_engine.php';
    require_once __DIR__ . '/events.php';

    $buyingSignals = [];
    if ($isPricingOrCommercial) $buyingSignals[] = 'pricing_inquired';
    if (!empty($structuredMeta['budget']) || $estimatedVal >= 50000) $buyingSignals[] = 'budget_confirmed';
    if ($isDemoOrMeeting) $buyingSignals[] = 'demo_requested';
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
        'human_required'         => (bool)$isHumanRequest,
        'human_attention_required' => (bool)$isHumanRequest,
        'buying_signals'         => $buyingSignals,
        'objections'             => $structuredMeta['objections'] ?? []
    ];

    $syncedArtifact = syncLeadArtifact($pdo, $companyId, $leadId, $artifactData);

    // Refresh lead priority from scoring engine
    if (!empty($syncedArtifact['priority'])) {
        $newPriority = $syncedArtifact['priority'];
    }

    // Dispatch milestone events
    if ($isHumanRequest) {
        dispatchSystemEvent($pdo, $companyId, 'human_attention.required', [
            'lead_id'         => $leadId,
            'customer_id'     => $customerId,
            'conversation_id' => $conversationId,
            'description'     => "Human assistance requested: {$summary}",
            'data'            => ['reason' => $summary, 'priority' => 'URGENT']
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

    // 9. Contextual WhatsApp CTA Logic (Section 12, 24)
    // Only show WhatsApp CTA when:
    // 1) The visitor explicitly requested human/whatsapp/call handoff
    // 2) OR the conversation is concluding/terminal (user said bye, thank you, that's all, end chat, etc.)
    $isChatEnding = (bool)preg_match('/\b(bye|goodbye|alvida|thank you|thanks|dhanyawad|that\'?s all|end chat|chat end|khatam|done|ok thanks|okay thanks)\b/i', $messageText) || 
                    in_array($rawIntent, ['conversation_end', 'goodbye', 'closing']) ||
                    $isWon;

    $isExplicitHandoff = $isHumanRequest || 
                         (bool)preg_match('/(whatsapp|wa\.me|call me|contact me|phone pe baat|number do|direct call)/i', $messageText);

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

    echo json_encode([
        'success'          => true,
        'conversation_id'  => $conversationId,
        'lead_id'          => $leadId,
        'reply'            => $publicReply,
        'sender'           => 'ai',
        'assistant_name'   => $assistantName,
        'stage'            => $resolvedStageName,
        'priority'         => $newPriority,
        'lead_score'       => $syncedArtifact['lead_score'] ?? 0,
        'priority_reason'  => $syncedArtifact['priority_reason'] ?? '',
        'lead_artifact'    => $syncedArtifact ?? null,
        'lead_captured'    => (!empty($customer['phone']) || !empty($customer['email']) || !empty($visitorPhone) || !empty($visitorEmail)),
        'chat_ended'       => $isChatEnding,
        'whatsapp_cta'     => [
            'show'          => $showWhatsappCta,
            'url'           => $whatsappUrl,
            'handoff_token' => $handoffToken,
            'label'         => $isChatEnding
                ? "Chat ended. If you need any further help, feel free to continue on WhatsApp:"
                : (!empty($visitorDisplayName)
                    ? "Connect with our team on WhatsApp, {$visitorDisplayName}:"
                    : "Prefer instant support? Continue on WhatsApp:")
        ],
        'timestamp'        => 'Just now'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'AI Engine Error: ' . $e->getMessage()
    ]);
}
