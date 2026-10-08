<?php
/**
 * CUBOIDPILOT / CAI — VISUAL AI WORKFLOW EXECUTION ENGINE
 * Multi-tenant, event-driven, graph execution interpreter with Gemini 3.8 Flash intelligence.
 * Supports Mode 1 (Autopilot), Mode 2 (Guided AI Flow), and Mode 3 (Strict Workflow).
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/gemini_service.php';
require_once __DIR__ . '/asset_helper.php';

class WorkflowEngine {

    /**
     * Finds and executes or advances an active workflow for incoming chat messages.
     * Returns structured result array or null if no workflow handles this message (falls back to Autopilot).
     */
    public static function handleChatMessage(
        PDO $pdo,
        int $companyId,
        string $messageText,
        string $sessionId,
        ?int $conversationId,
        ?int $customerId,
        ?int $leadId,
        array $sessionData = []
    ): ?array {
        // 1. Check for an ongoing waiting execution for this session
        $stmt = $pdo->prepare("
            SELECT * FROM `automation_executions`
            WHERE `company_id` = ? AND `session_id` = ? AND `status` = 'waiting'
            ORDER BY `id` DESC LIMIT 1
        ");
        $stmt->execute([$companyId, $sessionId]);
        $activeExec = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($activeExec) {
            // Load workflow definition
            $wStmt = $pdo->prepare("SELECT * FROM `automations` WHERE `id` = ? AND `company_id` = ? LIMIT 1");
            $wStmt->execute([(int)$activeExec['automation_id'], $companyId]);
            $workflow = $wStmt->fetch(PDO::FETCH_ASSOC);

            if ($workflow && !empty($workflow['workflow_data'])) {
                $graph = json_decode($workflow['workflow_data'], true);
                if (is_array($graph) && !empty($graph['nodes'])) {
                    return self::advanceExecution(
                        $pdo,
                        $companyId,
                        $workflow,
                        $activeExec,
                        $graph,
                        $messageText,
                        $sessionData
                    );
                }
            }
        }

        // 2. If no ongoing execution, evaluate if any published active workflow matches the trigger
        $activeWorkflows = self::getActiveWorkflows($pdo, $companyId);
        foreach ($activeWorkflows as $wf) {
            if (empty($wf['workflow_data'])) continue;
            $graph = json_decode($wf['workflow_data'], true);
            if (!is_array($graph) || empty($graph['nodes'])) continue;

            $triggerNode = self::findTriggerNode($graph['nodes']);
            if (!$triggerNode) continue;

            $triggerType = $triggerNode['type'] ?? ($wf['trigger_event'] ?? '');
            $shouldTrigger = self::evaluateTriggerMatch($triggerType, $messageText, $sessionData);

            if ($shouldTrigger) {
                // Initialize new execution
                return self::startExecution(
                    $pdo,
                    $companyId,
                    $wf,
                    $graph,
                    $triggerNode,
                    $sessionId,
                    $conversationId,
                    $customerId,
                    $leadId,
                    $messageText,
                    $sessionData
                );
            }
        }

        return null; // Fallback to standard AI Assistant
    }

    /**
     * Retrieves all active workflows for a company.
     */
    public static function getActiveWorkflows(PDO $pdo, int $companyId): array {
        $stmt = $pdo->prepare("
            SELECT * FROM `automations`
            WHERE `company_id` = ? AND `is_active` = 1 AND `status` = 'active'
            ORDER BY `id` ASC
        ");
        $stmt->execute([$companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Finds the starting trigger node of a graph.
     */
    public static function findTriggerNode(array $nodes): ?array {
        foreach ($nodes as $n) {
            $cat = $n['category'] ?? '';
            $type = $n['type'] ?? '';
            if ($cat === 'triggers' || str_starts_with($type, 'trigger_') || $type === 'start') {
                return $n;
            }
        }
        return $nodes[0] ?? null;
    }

    /**
     * Checks if a trigger node matches incoming event/message.
     */
    public static function evaluateTriggerMatch(string $triggerType, string $message, array $sessionData): bool {
        $isFirstMessage = !empty($sessionData['is_first_message']);

        return match ($triggerType) {
            'trigger_chat_start', 'chat_start' => $isFirstMessage,
            'trigger_customer_message', 'customer_message' => true,
            'trigger_intent_detected', 'intent_detected' => true,
            'trigger_new_visitor', 'first_touch_lead' => $isFirstMessage,
            'trigger_visitor_returns', 'visitor_returns' => !empty($sessionData['is_returning']),
            'trigger_product_selected' => preg_match('/(?:choose|select|interested in|course|product)\b/i', $message),
            'trigger_payment_initiated' => !empty($sessionData['payment_initiated']),
            'trigger_payment_confirmed' => !empty($sessionData['payment_confirmed']),
            'trigger_appointment_created' => !empty($sessionData['appointment_created']),
            default => $isFirstMessage
        };
    }

    /**
     * Initializes and executes a workflow from its start node.
     */
    public static function startExecution(
        PDO $pdo,
        int $companyId,
        array $workflow,
        array $graph,
        array $startNode,
        string $sessionId,
        ?int $conversationId,
        ?int $customerId,
        ?int $leadId,
        string $messageText,
        array $sessionData = [],
        bool $isSimulation = false
    ): array {
        // Collect initial variables
        $variables = self::buildInitialVariables($pdo, $companyId, $customerId, $leadId, $sessionId, $sessionData);
        $variables['session']['lastMessage'] = $messageText;

        // Create execution record in DB
        $ins = $pdo->prepare("
            INSERT INTO `automation_executions`
            (`company_id`, `automation_id`, `version`, `session_id`, `customer_id`, `lead_id`, `conversation_id`, `trigger_type`, `status`, `current_node_id`, `completed_nodes_json`, `variables_json`, `is_simulation`, `started_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'running', ?, '[]', ?, ?, NOW())
        ");
        $ins->execute([
            $companyId,
            (int)$workflow['id'],
            (int)($workflow['version'] ?? 1),
            $sessionId,
            $customerId,
            $leadId,
            $conversationId,
            $startNode['type'] ?? 'trigger_chat_start',
            $startNode['id'],
            json_encode($variables, JSON_UNESCAPED_UNICODE),
            $isSimulation ? 1 : 0
        ]);
        $executionId = (int)$pdo->lastInsertId();

        // Increment execution count on workflow
        if (!$isSimulation) {
            $pdo->prepare("UPDATE `automations` SET `execution_count` = execution_count + 1, `last_executed_at` = NOW() WHERE `id` = ?")
                ->execute([(int)$workflow['id']]);
        }

        $activeExec = [
            'id' => $executionId,
            'company_id' => $companyId,
            'automation_id' => (int)$workflow['id'],
            'session_id' => $sessionId,
            'customer_id' => $customerId,
            'lead_id' => $leadId,
            'conversation_id' => $conversationId,
            'status' => 'running',
            'current_node_id' => $startNode['id'],
            'completed_nodes_json' => '[]',
            'variables_json' => json_encode($variables, JSON_UNESCAPED_UNICODE),
            'is_simulation' => $isSimulation ? 1 : 0
        ];

        // Traverse graph starting from the next node connected to startNode
        $nextNodes = self::getNextNodes($graph, $startNode['id']);
        if (empty($nextNodes)) {
            self::completeExecution($pdo, $executionId);
            return [
                'handled' => true,
                'reply' => "Welcome to our platform! How can I help you?",
                'execution_id' => $executionId
            ];
        }

        $nextNode = $nextNodes[0];
        return self::stepExecution($pdo, $companyId, $workflow, $activeExec, $graph, $nextNode, $messageText);
    }

    /**
     * Resumes a waiting execution on new customer message.
     */
    public static function advanceExecution(
        PDO $pdo,
        int $companyId,
        array $workflow,
        array $activeExec,
        array $graph,
        string $messageText,
        array $sessionData = []
    ): array {
        $currentNodeId = $activeExec['current_node_id'];
        $variables = json_decode($activeExec['variables_json'] ?? '{}', true) ?: [];
        $variables['session']['lastMessage'] = $messageText;

        $currentNode = self::findNodeById($graph['nodes'] ?? [], $currentNodeId);
        if (!$currentNode) {
            self::failExecution($pdo, (int)$activeExec['id'], "Node {$currentNodeId} not found in graph definition.");
            return ['handled' => false];
        }

        $mode = $workflow['mode'] ?? ($graph['mode'] ?? 'guided'); // 'guided' | 'strict' | 'autopilot'

        // 1. Dynamic Contact Extraction & CRM Update
        if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $messageText, $emailMatches)) {
            $extractedEmail = $emailMatches[0];
            $variables['customer']['email'] = $extractedEmail;
            if (!empty($activeExec['customer_id'])) {
                $pdo->prepare("UPDATE `customers` SET `email` = ?, `last_seen_at` = NOW() WHERE `id` = ? AND `company_id` = ?")
                    ->execute([$extractedEmail, (int)$activeExec['customer_id'], $companyId]);
            }
        }
        if (preg_match('/(\+?91[\-\s]?)?[6-9]\d{9}/', $messageText, $phoneMatches)) {
            $extractedPhone = $phoneMatches[0];
            $variables['customer']['phone'] = $extractedPhone;
            if (!empty($activeExec['customer_id'])) {
                $pdo->prepare("UPDATE `customers` SET `phone` = ?, `last_seen_at` = NOW() WHERE `id` = ? AND `company_id` = ?")
                    ->execute([$extractedPhone, (int)$activeExec['customer_id'], $companyId]);
            }
        }

        // 2. Real Human Advisor Request Interceptor
        if (preg_match('/\b(human|counselor|counsellor|call\s*me|call|advisor|talk\s*to\s*(someone|person|human|counselor)|founder|baat\s*karni|real\s*human)\b/i', $messageText)) {
            if (!empty($activeExec['conversation_id'])) {
                $pdo->prepare("UPDATE `conversations` SET `status` = 'human_handling', `ownership` = 'human' WHERE `id` = ? AND `company_id` = ?")
                    ->execute([(int)$activeExec['conversation_id'], $companyId]);
            }
            $humanReply = "I have prioritized your request for direct human assistance. Our team has been notified and a specialist will connect with you shortly.";
            return [
                'handled' => true,
                'reply' => $humanReply,
                'execution_id' => (int)$activeExec['id'],
                'current_node_id' => $currentNode['id'],
                'status' => 'waiting',
                'action_chips' => [
                    ['label' => 'Connect on WhatsApp', 'text' => 'Connect on WhatsApp'],
                    ['label' => 'Schedule Call', 'text' => 'Schedule a consultation call']
                ]
            ];
        }

        // Mode 2: Guided AI Flow handling
        // Check if customer asked an off-topic / tangential question before continuing
        if ($mode === 'guided') {
            $isTangential = self::detectTangentialInquiry($messageText, $currentNode);
            if ($isTangential) {
                // Answer question using Knowledge Base while keeping active stage
                $tangentialAnswer = self::generateGroundedTangentialReply($pdo, $companyId, $messageText, $currentNode, $variables);
                if (!empty($tangentialAnswer)) {
                    $matchedAsset = AssetHelper::matchAsset($pdo, $companyId, $messageText, []);
                    $sharedAssetPayload = null;
                    if ($matchedAsset) {
                        $sharedAssetPayload = [
                            'id'               => (int)$matchedAsset['id'],
                            'title'            => $matchedAsset['title'],
                            'category'         => $matchedAsset['category'],
                            'description'      => $matchedAsset['description'] ?? '',
                            'file_name'        => $matchedAsset['file_name'],
                            'file_size'        => (int)$matchedAsset['file_size'],
                            'file_type'        => $matchedAsset['file_type'],
                            'download_url'     => 'api/assets.php?action=download&id=' . (int)$matchedAsset['id'],
                            'email_dispatched' => false,
                            'recipient_email'  => $variables['customer']['email'] ?? null
                        ];
                    }

                    $chipRes = GeminiService::extractActionChips($tangentialAnswer);
                    $tangentialClean = $chipRes['text'];
                    $tangentialChips = $chipRes['chips'];

                    if (empty($tangentialChips)) {
                        $tangentialChips = self::resolveActionChips([], $tangentialClean, $messageText, $companyId, $pdo);
                    }

                    self::logNodeStep($pdo, (int)$activeExec['id'], $companyId, (int)$workflow['id'], $currentNode['id'], $currentNode['type'], $currentNode['data']['label'] ?? 'AI Guidance', 'success', ['question' => $messageText], ['guidance' => $tangentialClean]);
                    return [
                        'handled' => true,
                        'reply' => $tangentialClean,
                        'execution_id' => (int)$activeExec['id'],
                        'current_node_id' => $currentNode['id'],
                        'status' => 'waiting',
                        'retained_stage' => true,
                        'action_chips' => $tangentialChips,
                        'shared_asset' => $sharedAssetPayload
                    ];
                }
            }
        }

        // Store customer reply in variable if current node config asks for it
        if (!empty($currentNode['data']['variable_name'])) {
            $varKey = $currentNode['data']['variable_name'];
            $variables['custom'][$varKey] = $messageText;
        }

        // Advance to next node based on conditions or outgoing edge
        $outEdges = self::getOutgoingEdges($graph, $currentNodeId);
        $targetNodeId = null;

        if (count($outEdges) === 1) {
            $targetNodeId = $outEdges[0]['target'];
        } elseif (count($outEdges) > 1) {
            // Conditional branching based on customer message / intent
            $targetNodeId = self::resolveBranchTarget($currentNode, $outEdges, $messageText, $variables);
        }

        if (!$targetNodeId) {
            self::completeExecution($pdo, (int)$activeExec['id']);
            return ['handled' => true, 'execution_id' => (int)$activeExec['id']];
        }

        $targetNode = self::findNodeById($graph['nodes'] ?? [], $targetNodeId);
        if (!$targetNode) {
            self::completeExecution($pdo, (int)$activeExec['id']);
            return ['handled' => true, 'execution_id' => (int)$activeExec['id']];
        }

        $activeExec['variables_json'] = json_encode($variables, JSON_UNESCAPED_UNICODE);
        return self::stepExecution($pdo, $companyId, $workflow, $activeExec, $graph, $targetNode, $messageText);
    }

    /**
     * Executes nodes step by step until a waiting state or end is reached.
     */
    public static function stepExecution(
        PDO $pdo,
        int $companyId,
        array $workflow,
        array $activeExec,
        array $graph,
        array $node,
        string $messageText
    ): array {
        $executionId = (int)$activeExec['id'];
        $variables = json_decode($activeExec['variables_json'] ?? '{}', true) ?: [];
        $completedNodes = json_decode($activeExec['completed_nodes_json'] ?? '[]', true) ?: [];

        $accumulatedReply = '';
        $richCards = [];
        $quickReplies = [];
        $emiPlans = null;
        $paymentLink = null;
        $sharedAsset = null;
        $appointmentSlots = [];

        $currentNode = $node;
        $maxSteps = 25; // prevent runaway loops
        $stepsRun = 0;

        while ($currentNode && $stepsRun < $maxSteps) {
            $stepsRun++;
            $nodeId = $currentNode['id'];
            $nodeType = $currentNode['type'] ?? '';
            $nodeData = $currentNode['data'] ?? [];
            $completedNodes[] = $nodeId;

            $nodeResult = self::executeNode(
                $pdo,
                $companyId,
                $workflow,
                $activeExec,
                $currentNode,
                $variables,
                $messageText
            );

            // Log step
            self::logNodeStep(
                $pdo,
                $executionId,
                $companyId,
                (int)$workflow['id'],
                $nodeId,
                $nodeType,
                $nodeData['label'] ?? $nodeType,
                $nodeResult['status'] ?? 'success',
                ['input' => $messageText, 'variables' => $variables],
                $nodeResult
            );

            // Merge variables
            if (!empty($nodeResult['updated_variables'])) {
                $variables = array_merge_recursive($variables, $nodeResult['updated_variables']);
            }

            // Merge output presentation
            if (!empty($nodeResult['reply'])) {
                $accumulatedReply .= ($accumulatedReply ? "\n\n" : "") . $nodeResult['reply'];
            }
            if (!empty($nodeResult['product_cards'])) {
                $richCards = array_merge($richCards, $nodeResult['product_cards']);
            }
            if (!empty($nodeResult['action_chips'])) {
                $quickReplies = array_merge($quickReplies, $nodeResult['action_chips']);
            }
            if (!empty($nodeResult['emi_plans'])) {
                $emiPlans = $nodeResult['emi_plans'];
            }
            if (!empty($nodeResult['payment_link'])) {
                $paymentLink = $nodeResult['payment_link'];
            }
            if (!empty($nodeResult['shared_asset'])) {
                $sharedAsset = $nodeResult['shared_asset'];
            }
            if (!empty($nodeResult['appointment_slots'])) {
                $appointmentSlots = $nodeResult['appointment_slots'];
            }

            // Check if node requires waiting for customer response
            if (!empty($nodeResult['wait_for_reply'])) {
                $pdo->prepare("
                    UPDATE `automation_executions`
                    SET `status` = 'waiting', `current_node_id` = ?, `completed_nodes_json` = ?, `variables_json` = ?
                    WHERE `id` = ?
                ")->execute([
                    $nodeId,
                    json_encode($completedNodes),
                    json_encode($variables, JSON_UNESCAPED_UNICODE),
                    $executionId
                ]);

                return [
                    'handled' => true,
                    'reply' => self::interpolateVariables($accumulatedReply, $variables),
                    'execution_id' => $executionId,
                    'current_node_id' => $nodeId,
                    'status' => 'waiting',
                    'product_cards' => $richCards,
                    'action_chips' => self::resolveActionChips($quickReplies, $accumulatedReply, $messageText, $companyId, $pdo),
                    'emi_plans' => $emiPlans,
                    'payment_link' => $paymentLink,
                    'shared_asset' => $sharedAsset,
                    'appointment_slots' => $appointmentSlots,
                    'variables' => $variables
                ];
            }

            // Determine next node
            $targetBranchId = $nodeResult['branch_id'] ?? null;
            $nextNodes = self::getNextNodes($graph, $nodeId, $targetBranchId);

            if (empty($nextNodes) || $nodeType === 'control_end' || $nodeType === 'end') {
                self::completeExecution($pdo, $executionId, $completedNodes, $variables);
                break;
            }

            $currentNode = $nextNodes[0];
        }

        return [
            'handled' => true,
            'reply' => self::interpolateVariables($accumulatedReply, $variables),
            'execution_id' => $executionId,
            'status' => 'completed',
            'product_cards' => $richCards,
            'action_chips' => self::resolveActionChips($quickReplies, $accumulatedReply, $messageText, $companyId, $pdo),
            'emi_plans' => $emiPlans,
            'payment_link' => $paymentLink,
            'shared_asset' => $sharedAsset,
            'appointment_slots' => $appointmentSlots,
            'variables' => $variables
        ];
    }

    public static function resolveActionChips(array $quickReplies, string $replyText, string $messageText, int $companyId, PDO $pdo): array {
        if (!empty($quickReplies)) {
            return $quickReplies;
        }

        $chipData = GeminiService::extractActionChips($replyText);
        if (!empty($chipData['chips'])) {
            return $chipData['chips'];
        }

        // 1. Check published quick_chips configured by tenant in DB
        try {
            $qcStmt = $pdo->prepare("SELECT label, response_text, action_type FROM `quick_chips` WHERE `company_id` = ? AND `status` = 'published' ORDER BY `display_order` ASC, `id` ASC LIMIT 6");
            $qcStmt->execute([$companyId]);
            $dbChips = $qcStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($dbChips)) {
                $chips = [];
                foreach ($dbChips as $dc) {
                    $chips[] = [
                        'label' => $dc['label'],
                        'text'  => !empty($dc['response_text']) ? $dc['response_text'] : $dc['label']
                    ];
                }
                return $chips;
            }
        } catch (Throwable $e) {}

        // 2. Check widget_settings quick_actions_json
        $ws = $pdo->prepare("SELECT quick_actions_json FROM `widget_settings` WHERE `company_id` = ? LIMIT 1");
        $ws->execute([$companyId]);
        $row = $ws->fetch(PDO::FETCH_ASSOC);
        if (!empty($row['quick_actions_json'])) {
            $dec = json_decode($row['quick_actions_json'], true);
            if (is_array($dec) && count($dec) > 0) {
                return $dec;
            }
        }

        // 3. Fallback to clean, universal business chips (Zero hardcoded course/tuition bias)
        return [
            ['label' => 'Explore Offerings', 'text' => 'Tell me more about your solutions and offerings'],
            ['label' => 'Pricing & Plans', 'text' => 'What are your pricing plans and packages?'],
            ['label' => 'Download Overview', 'text' => 'Can you share official documentation or overview?'],
            ['label' => 'Talk to Team', 'text' => 'I would like to speak with a representative']
        ];
    }

    /**
     * Executes individual node logic based on category and type.
     */
    public static function executeNode(
        PDO $pdo,
        int $companyId,
        array $workflow,
        array $activeExec,
        array $node,
        array &$variables,
        string $messageText
    ): array {
        $type = $node['type'] ?? '';
        $data = $node['data'] ?? [];

        switch ($type) {
            // === CATEGORY B — AI INTELLIGENCE ===
            case 'ai_response_generator':
            case 'ai_response':
                $prompt = $data['prompt'] ?? "Answer the customer's inquiry helpfully and informatively.";
                $prompt = self::interpolateVariables($prompt, $variables);

                // Fetch grounded knowledge with RAG relevance scoring
                $kbContext = self::getGroundedKnowledge($pdo, $companyId, $data['knowledge_source_ids'] ?? [], $messageText);
                $companyName = $variables['company']['name'] ?? 'our company';
                $sysPrompt = "You are Cai, the consultative AI assistant for {$companyName}.
Company Knowledge:
{$kbContext}

Instruction:
{$prompt}";

                $aiResp = GeminiService::generateResponse($sysPrompt, [
                    ['role' => 'user', 'content' => $messageText]
                ], [
                    'temperature' => (float)($data['temperature'] ?? 0.3)
                ]);

                if (!empty($data['output_variable'])) {
                    $variables['ai'][$data['output_variable']] = $aiResp;
                }

                return [
                    'status' => 'success',
                    'reply' => $aiResp,
                    'wait_for_reply' => !empty($data['wait_for_reply'])
                ];

            case 'ai_intent_detection':
            case 'ai_intent':
                $intents = $data['intents'] ?? [
                    'pricing' => 'Pricing, cost, and fee inquiries',
                    'curriculum' => 'Course syllabus and topics',
                    'human_support' => 'Requests to speak to counselor or human',
                    'enrollment' => 'Ready to purchase or enroll'
                ];
                $intentRes = GeminiService::classifyIntent($messageText, $intents);
                $detected = $intentRes['intent'] ?? 'fallback';
                $variables['ai']['detectedIntent'] = $detected;

                return [
                    'status' => 'success',
                    'branch_id' => $detected,
                    'updated_variables' => ['ai' => ['detectedIntent' => $detected]]
                ];

            case 'ai_product_recommendation':
            case 'ai_recommendation':
                $products = self::getCompanyProducts($pdo, $companyId, $data['category'] ?? null);
                $rec = GeminiService::recommendProducts($products, $messageText, $variables);
                $matchingProds = [];
                $recommendedIds = $rec['recommended_product_ids'] ?? [];

                foreach ($products as $p) {
                    if (in_array((int)$p['id'], $recommendedIds)) {
                        $matchingProds[] = self::formatProductCard($p);
                    }
                }

                $explanation = $rec['match_reason'] ?? "Based on your goals, here are our recommended programs:";
                return [
                    'status' => 'success',
                    'reply' => $explanation,
                    'product_cards' => $matchingProds,
                    'wait_for_reply' => true
                ];

            case 'ai_decision':
                $criteria = $data['criteria'] ?? "Determine customer purchase intent";
                $branches = $data['branches'] ?? [['id' => 'yes', 'title' => 'Yes'], ['id' => 'no', 'title' => 'No']];
                $decision = GeminiService::evaluateDecision($criteria, $branches, $messageText, $variables);
                return [
                    'status' => 'success',
                    'branch_id' => $decision['selected_branch_id'] ?? 'default'
                ];

            case 'ai_qualification':
                $extracted = GeminiService::extractLeadQualification($messageText, $variables['customer'] ?? []);
                if (!empty($extracted['name']) && empty($variables['customer']['name'])) {
                    $variables['customer']['name'] = $extracted['name'];
                }
                if (!empty($extracted['phone']) && empty($variables['customer']['phone'])) {
                    $variables['customer']['phone'] = $extracted['phone'];
                }
                if (!empty($extracted['email']) && empty($variables['customer']['email'])) {
                    $variables['customer']['email'] = $extracted['email'];
                }

                $reply = $data['question'] ?? "Could you share your name and email so I can assist you better?";
                return [
                    'status' => 'success',
                    'reply' => self::interpolateVariables($reply, $variables),
                    'wait_for_reply' => true
                ];

            // === CATEGORY C — CUSTOMER MESSAGES ===
            case 'msg_plain_text':
            case 'plain_message':
                $msg = $data['message'] ?? $data['text'] ?? '';
                return [
                    'status' => 'success',
                    'reply' => self::interpolateVariables($msg, $variables)
                ];

            case 'msg_quick_replies':
            case 'comm_channel_dispatch':
            case 'comm_multichannel_option':
                $msg = $data['message'] ?? "Please select an option:";
                $chips = $data['options'] ?? $data['chips'] ?? [];
                $formattedChips = [];
                foreach ($chips as $c) {
                    $formattedChips[] = [
                        'label' => is_array($c) ? ($c['label'] ?? $c['text']) : $c,
                        'text' => is_array($c) ? ($c['text'] ?? $c['label']) : $c
                    ];
                }
                return [
                    'status' => 'success',
                    'reply' => self::interpolateVariables($msg, $variables),
                    'action_chips' => $formattedChips,
                    'wait_for_reply' => true
                ];

            case 'msg_course_carousel':
            case 'msg_product_carousel':
                $products = self::getCompanyProducts($pdo, $companyId, $data['category'] ?? null);
                $cards = array_map(fn($p) => self::formatProductCard($p), array_slice($products, 0, (int)($data['limit'] ?? 4)));
                $msg = $data['title'] ?? "Explore our verified programs below:";
                return [
                    'status' => 'success',
                    'reply' => $msg,
                    'product_cards' => $cards,
                    'wait_for_reply' => true
                ];

            case 'msg_emi_card':
                $prodId = (int)($data['product_id'] ?? ($variables['product']['id'] ?? 0));
                $prod = self::getProductById($pdo, $companyId, $prodId);
                if (!$prod) {
                    $allProds = self::getCompanyProducts($pdo, $companyId);
                    if (!empty($allProds)) {
                        $prod = $allProds[0];
                    }
                }
                $price = $prod ? (int)$prod['price_inr'] : 4999;
                $emiAmount = (int)ceil($price / 3);
                $prodName = $prod['name'] ?? 'Commercial Solution / Service Plan';

                $emiPayload = [
                    'product_name' => $prodName,
                    'total_amount' => $price,
                    'starting_at_inr' => $emiAmount,
                    'duration_months' => 3,
                    'down_payment' => $emiAmount,
                    'per_month' => $emiAmount,
                    'num_splits' => 3,
                    'schedule' => [
                        ['title' => '1st Installment (Today)', 'amount' => $emiAmount, 'due_date' => 'Immediate'],
                        ['title' => '2nd Installment', 'amount' => $emiAmount, 'due_date' => date('d M Y', strtotime('+30 days'))],
                        ['title' => '3rd Installment', 'amount' => $emiAmount, 'due_date' => date('d M Y', strtotime('+60 days'))]
                    ]
                ];
                return [
                    'status' => 'success',
                    'reply' => "Here is our flexible 3-Month 0% Interest installment plan for {$prodName}:",
                    'emi_plans' => $emiPayload,
                    'wait_for_reply' => true
                ];

            case 'msg_payment_link':
            case 'sales_send_payment_card':
                $prodId = (int)($data['product_id'] ?? ($variables['product']['id'] ?? 0));
                $prod = self::getProductById($pdo, $companyId, $prodId);
                if (!$prod) {
                    $allProds = self::getCompanyProducts($pdo, $companyId);
                    if (!empty($allProds)) {
                        $prod = $allProds[0];
                    }
                }
                $amount = $prod ? (int)$prod['price_inr'] : 4999;
                $link = "https://cai.cuboidsoft.in/pay?deal=" . bin2hex(random_bytes(6)) . "&amt=" . $amount;

                $paymentPayload = [
                    'url' => $link,
                    'amount' => $amount,
                    'currency' => 'INR',
                    'title' => $prod['name'] ?? 'Service Invoice / Order',
                    'label' => "Pay Securely ₹" . number_format($amount)
                ];
                return [
                    'status' => 'success',
                    'reply' => "Please use the verified payment link below to complete your enrollment:",
                    'payment_link' => $paymentPayload,
                    'wait_for_reply' => true
                ];

            case 'msg_document_card':
            case 'msg_brochure_download':
                $assets = self::getCompanyAssets($pdo, $companyId);
                $asset = !empty($assets) ? $assets[0] : null;
                $assetPayload = $asset ? [
                    'id' => $asset['id'],
                    'title' => $asset['title'],
                    'file_name' => $asset['file_name'],
                    'file_path' => $asset['file_path'],
                    'category' => $asset['category'] ?? 'Brochure'
                ] : null;

                return [
                    'status' => 'success',
                    'reply' => "Here is the official brochure you requested:",
                    'shared_asset' => $assetPayload,
                    'wait_for_reply' => true
                ];

            // === CATEGORY D — CONDITIONS & LOGIC ===
            case 'logic_if_else':
            case 'condition_if_else':
                $var = $data['variable'] ?? '';
                $op = $data['operator'] ?? 'equals';
                $expected = $data['value'] ?? '';

                $actual = self::resolveVariablePath($variables, $var);
                $isTrue = self::evaluateOperator($actual, $op, $expected);
                return [
                    'status' => 'success',
                    'branch_id' => $isTrue ? 'true' : 'false'
                ];

            case 'logic_response_match':
                $pattern = $data['pattern'] ?? '';
                $isMatch = (bool)preg_match('/' . preg_quote($pattern, '/') . '/i', $messageText);
                return [
                    'status' => 'success',
                    'branch_id' => $isMatch ? 'match' : 'no_match'
                ];

            case 'logic_emi_availability':
                $prodId = (int)($data['product_id'] ?? ($variables['product']['id'] ?? 0));
                $prod = self::getProductById($pdo, $companyId, $prodId);
                $emiAvailable = $prod ? !empty($prod['emi_available']) : true;
                return [
                    'status' => 'success',
                    'branch_id' => $emiAvailable ? 'emi_available' : 'full_payment_only'
                ];

            // === CATEGORY E — CRM & BUSINESS ACTIONS ===
            case 'crm_create_lead':
            case 'crm_update_lead':
                if (!empty($activeExec['is_simulation'])) {
                    return ['status' => 'success', 'simulated_action' => 'crm_update_lead'];
                }
                $leadName = $variables['customer']['name'] ?? 'Website Prospect';
                $leadPhone = $variables['customer']['phone'] ?? '';
                $leadEmail = $variables['customer']['email'] ?? '';
                $leadStage = $data['stage'] ?? 'QUALIFIED';

                if (!empty($activeExec['lead_id'])) {
                    $pdo->prepare("UPDATE `leads` SET `stage` = ?, `updated_at` = NOW() WHERE `id` = ? AND `company_id` = ?")
                        ->execute([$leadStage, (int)$activeExec['lead_id'], $companyId]);
                }
                return ['status' => 'success'];

            case 'crm_request_handoff':
                if (!empty($activeExec['is_simulation'])) {
                    return ['status' => 'success', 'simulated_action' => 'human_handoff'];
                }
                if (!empty($activeExec['conversation_id'])) {
                    $pdo->prepare("UPDATE `conversations` SET `status` = 'human_handling', `ownership` = 'human' WHERE `id` = ? AND `company_id` = ?")
                        ->execute([(int)$activeExec['conversation_id'], $companyId]);
                }
                return [
                    'status' => 'success',
                    'reply' => "I have notified our senior counselor to take over and assist you directly."
                ];

            // === CATEGORY G — REMINDERS & AUTOMATION ===
            case 'comm_schedule_reminder':
            case 'schedule_reminder':
                if (!empty($activeExec['is_simulation'])) {
                    return ['status' => 'success', 'simulated_action' => 'schedule_reminder'];
                }
                $delayMin = (int)($data['delay_minutes'] ?? 1440); // default 24h
                $remText = $data['message'] ?? "Follow up on program enrollment";
                $remText = self::interpolateVariables($remText, $variables);

                $pdo->prepare("
                    INSERT INTO `reminders` 
                    (`company_id`, `lead_id`, `customer_id`, `type`, `reminder_text`, `scheduled_at`, `status`, `created_at`)
                    VALUES (?, ?, ?, 'whatsapp', ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), 'scheduled', NOW())
                ")->execute([
                    $companyId,
                    $activeExec['lead_id'] ?? null,
                    $activeExec['customer_id'] ?? null,
                    $remText,
                    $delayMin
                ]);
                return ['status' => 'success'];

            // === CATEGORY H — WORKFLOW CONTROL ===
            case 'control_wait_reply':
                return ['status' => 'success', 'wait_for_reply' => true];

            case 'control_end':
            case 'end':
                return ['status' => 'success', 'end_workflow' => true];

            default:
                return ['status' => 'success'];
        }
    }

    /**
     * Resolves branch target connector based on condition / intent / choice.
     */
    public static function resolveBranchTarget(array $node, array $outEdges, string $messageText, array $variables): ?string {
        if (empty($outEdges)) return null;

        // Check if node evaluated a branch_id
        if (!empty($node['branch_id'])) {
            foreach ($outEdges as $edge) {
                if (($edge['sourceHandle'] ?? '') === $node['branch_id'] || ($edge['label'] ?? '') === $node['branch_id']) {
                    return $edge['target'];
                }
            }
        }

        // Default to first outgoing edge
        return $outEdges[0]['target'] ?? null;
    }

    /**
     * Checks if customer asked a tangential question in Mode 2 (Guided AI Flow).
     */
    public static function detectTangentialInquiry(string $messageText, array $currentNode): bool {
        // Broad pattern to catch questions, information requests, or mid-journey queries
        return (bool)preg_match('/\b(why|how|what|when|where|who|is\s+it|can\s+you|tell\s+me|difficult|hard|easy|placement|job|guarantee|doubt|fee|cost|price|pricing|syllabus|brochure|guide|document|doc|pdf|download|email|whatsapp|kya|kaise|kitna|pehle|before|detail|info|chahiye|bhejo|bhejna|de\s*do|batao|architecture)\b|\?/i', $messageText);
    }

    /**
     * Generates a grounded reply for tangential questions while keeping the current workflow step.
     */
    public static function generateGroundedTangentialReply(
        PDO $pdo,
        int $companyId,
        string $messageText,
        array $currentNode,
        array $variables
    ): string {
        $kbContext = self::getGroundedKnowledge($pdo, $companyId, [], $messageText);
        $stagePrompt = $currentNode['data']['label'] ?? ($currentNode['data']['prompt'] ?? 'continuing customer journey');

        $compName = $variables['company']['name'] ?? 'our company';
        $sysPrompt = "You are Cai, an intelligent AI counselor and customer success guide for {$compName}.
The customer sent a message or asked a question while in this workflow stage: '{$stagePrompt}'.
Verified Company Knowledge:
{$kbContext}

CRITICAL INSTRUCTIONS:
1. FIRST, carefully understand and address their exact requirement, intent, or question (e.g. if they asked for email details, syllabus, fees, instructor, or guidance, answer that directly and helpfully).
2. If they provided or asked about sending info to their email or WhatsApp, acknowledge that warmly.
3. Keep the answer clear, helpful, and natural (2-3 sentences), grounded in verified facts.
4. Conclude by smoothly guiding them to the next helpful step in their journey.
AT THE VERY END OF YOUR RESPONSE, provide 2-4 contextual action chips for what the visitor might want to ask or do next, prefixed by '---ACTION_CHIPS---' and formatted as a JSON array. Labels must be clean text with ZERO emojis:
---ACTION_CHIPS---
[
  {\"label\": \"Explore Offerings\", \"text\": \"Tell me more about your solutions and offerings\"},
  {\"label\": \"Pricing & Plans\", \"text\": \"What are your pricing plans and packages?\"},
  {\"label\": \"Download Overview\", \"text\": \"Can you share official documentation or overview?\"},
  {\"label\": \"Talk to Team\", \"text\": \"I would like to speak with a representative\"}
]";

        return GeminiService::generateResponse($sysPrompt, [
            ['role' => 'user', 'content' => $messageText]
        ]);
    }

    /**
     * Replaces {{variable.path}} tags with real session and catalog data.
     */
    public static function interpolateVariables(string $text, array $variables): string {
        return preg_replace_callback('/\{\{([a-zA-Z0-9_\.]+)\}\}/', function($m) use ($variables) {
            $val = self::resolveVariablePath($variables, $m[1]);
            return ($val !== null && $val !== '') ? (string)$val : '';
        }, $text);
    }

    public static function resolveVariablePath(array $array, string $path) {
        $keys = explode('.', $path);
        $curr = $array;
        foreach ($keys as $k) {
            if (is_array($curr) && array_key_exists($k, $curr)) {
                $curr = $curr[$k];
            } else {
                return null;
            }
        }
        return $curr;
    }

    public static function evaluateOperator($actual, string $op, $expected): bool {
        return match ($op) {
            'equals' => strtolower((string)$actual) === strtolower((string)$expected),
            'not_equals' => strtolower((string)$actual) !== strtolower((string)$expected),
            'contains' => stripos((string)$actual, (string)$expected) !== false,
            'greater_than' => (float)$actual > (float)$expected,
            'less_than' => (float)$actual < (float)$expected,
            'is_set' => !empty($actual),
            default => true
        };
    }

    public static function buildInitialVariables(PDO $pdo, int $companyId, ?int $customerId, ?int $leadId, string $sessionId, array $sessionData): array {
        $compStmt = $pdo->prepare("SELECT name, slug, industry, city FROM `companies` WHERE `id` = ? LIMIT 1");
        $compStmt->execute([$companyId]);
        $comp = $compStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $cust = [];
        if ($customerId) {
            $cStmt = $pdo->prepare("SELECT name, phone, email FROM `customers` WHERE `id` = ? AND `company_id` = ? LIMIT 1");
            $cStmt->execute([$customerId, $companyId]);
            $cust = $cStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        return [
            'company' => [
                'name' => $comp['name'] ?? 'Our Company',
                'slug' => $comp['slug'] ?? '',
                'industry' => $comp['industry'] ?? ''
            ],
            'customer' => [
                'name' => $cust['name'] ?? ($sessionData['visitor_name'] ?? 'there'),
                'phone' => $cust['phone'] ?? ($sessionData['visitor_phone'] ?? ''),
                'email' => $cust['email'] ?? ($sessionData['visitor_email'] ?? '')
            ],
            'session' => [
                'id' => $sessionId,
                'lastMessage' => ''
            ],
            'product' => [],
            'ai' => [],
            'custom' => []
        ];
    }

    public static function getGroundedKnowledge(PDO $pdo, int $companyId, array $sourceIds = [], string $query = ''): string {
        // 1. Company Profile
        $compStmt = $pdo->prepare("SELECT name, slug, industry, city, country FROM `companies` WHERE id = ? LIMIT 1");
        $compStmt->execute([$companyId]);
        $comp = $compStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $companyName = !empty($comp['name']) ? $comp['name'] : 'Our Company';

        $contextBlocks = [];
        $contextBlocks[] = "=== SECTION 1: COMPANY IDENTITY ===\n"
            . "- Business Name: {$companyName}\n"
            . (!empty($comp['industry']) ? "- Industry: {$comp['industry']}\n" : "")
            . (!empty($comp['city']) ? "- Location: {$comp['city']}, " . ($comp['country'] ?? 'India') . "\n" : "");

        // 2. Verified Knowledge Sources (Documents, Web Crawls, FAQs, Policies)
        if (!empty($sourceIds)) {
            $placeholders = implode(',', array_fill(0, count($sourceIds), '?'));
            $stmt = $pdo->prepare("SELECT id, title, type, category, content FROM `knowledge_sources` WHERE `company_id` = ? AND `id` IN ($placeholders) AND `is_active` = 1");
            $stmt->execute(array_merge([$companyId], $sourceIds));
        } else {
            $stmt = $pdo->prepare("SELECT id, title, type, category, content FROM `knowledge_sources` WHERE `company_id` = ? AND `is_active` = 1 ORDER BY id DESC LIMIT 15");
            $stmt->execute([$companyId]);
        }
        $sources = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($sources)) {
            // Intelligent token-based relevance scoring if query is provided
            $q = mb_strtolower(trim($query));
            $tokens = array_filter(preg_split('/[\s,\.\?!_\-]+/u', $q), fn($w) => mb_strlen($w) >= 3);

            $scored = [];
            foreach ($sources as $s) {
                $score = 1;
                $tLower = mb_strtolower($s['title'] ?? '');
                $catLower = mb_strtolower($s['category'] ?? '');
                $cLower = mb_strtolower($s['content'] ?? '');

                foreach ($tokens as $tok) {
                    if (strpos($tLower, $tok) !== false) $score += 10;
                    if (strpos($catLower, $tok) !== false) $score += 6;
                    if (strpos($cLower, $tok) !== false) $score += 2;
                }

                $clean = preg_replace('/[\x{FFFD}\x{0000}-\x{001F}\x{007F}]/u', ' ', $s['content'] ?? '');
                $clean = preg_replace('/[ \t]+/', ' ', $clean);
                $clean = trim($clean);

                $scored[] = [
                    'title' => $s['title'],
                    'clean' => $clean,
                    'score' => $score
                ];
            }

            usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

            $kbDocs = [];
            foreach (array_slice($scored, 0, 4) as $item) {
                $body = $item['clean'];
                if (mb_strlen($body) > 4500) {
                    $body = mb_substr($body, 0, 4500) . "\n... [truncated for concise reasoning]";
                }
                $kbDocs[] = "<verified_document title=\"{$item['title']}\">\n{$body}\n</verified_document>";
            }
            if (!empty($kbDocs)) {
                $contextBlocks[] = "=== SECTION 2: VERIFIED KNOWLEDGE DOCUMENTS ===\n" . implode("\n\n", $kbDocs);
            }
        }

        // 3. Active Commercial Offerings & Catalog (Products / Courses / Services)
        $products = self::getCompanyProducts($pdo, $companyId);
        if (!empty($products)) {
            $catLines = [];
            foreach ($products as $cp) {
                $features = !empty($cp['features']) ? (is_array($cp['features']) ? $cp['features'] : json_decode($cp['features'], true)) : [];
                $featStr = is_array($features) ? implode(', ', $features) : '';
                $emiStarting = (int)($cp['emi_starting_at_inr'] ?? ceil((int)$cp['price_inr'] / 3));
                $emiText = !empty($cp['emi_available'])
                    ? "Available (Starting ₹" . number_format($emiStarting) . "/mo, 3-Month EMI available)"
                    : "Not Available";
                $origStr = ((int)($cp['original_price_inr'] ?? 0) > (int)$cp['price_inr'])
                    ? " (Original: ₹" . number_format((int)$cp['original_price_inr']) . ", " . ($cp['discount_percent'] ?? 0) . "% OFF)"
                    : "";

                $catLines[] = "- [{$cp['category']}] \"{$cp['name']}\" (ID: {$cp['id']}):\n"
                    . "  • Fee: ₹" . number_format((int)$cp['price_inr']) . "{$origStr}\n"
                    . (!empty($cp['duration']) ? "  • Duration: {$cp['duration']}\n" : "")
                    . (!empty($featStr) ? "  • Highlights: {$featStr}\n" : "")
                    . "  • 0% EMI: {$emiText}";
            }
            $contextBlocks[] = "=== SECTION 3: COMMERCIAL OFFERINGS & CATALOG ===\n" . implode("\n", $catLines);
        }

        // 4. Downloadable Assets & Brochures
        $assets = self::getCompanyAssets($pdo, $companyId);
        if (!empty($assets)) {
            $assetLines = [];
            foreach ($assets as $ca) {
                $catLabel = ucfirst(str_replace('_', ' ', $ca['category'] ?? 'Document'));
                $assetLines[] = "- [{$catLabel}] \"{$ca['title']}\"" . (!empty($ca['description']) ? " — {$ca['description']}" : "");
            }
            $contextBlocks[] = "=== SECTION 4: DOWNLOADABLE BROCHURES & ASSETS ===\n" . implode("\n", $assetLines);
        }

        // 5. Strict Zero-Hallucination Guardrails
        $contextBlocks[] = "=== CRITICAL ZERO-HALLUCINATION & ACCURACY GUARDRAILS ===\n"
            . "1. STRICT GROUNDING: Answer using ONLY the verified facts from Sections 1, 2, 3, and 4 above.\n"
            . "2. ZERO SPECULATION: NEVER guess, extrapolate, or invent prices, fees, discounts, durations, or unlisted policies.\n"
            . "3. UNVERIFIED TOPICS: If an inquiry cannot be answered from this verified company knowledge base, explicitly and politely state that this information is not on file, and offer to connect them with a human advisor or explore our verified offerings.\n"
            . "4. TONE: Professional, consultative, concise, empathetic, and conversion-oriented.";

        return implode("\n\n", $contextBlocks);
    }

    public static function getCompanyProducts(PDO $pdo, int $companyId, ?string $category = null): array {
        if ($category) {
            $stmt = $pdo->prepare("SELECT * FROM `products` WHERE `company_id` = ? AND `category` = ? ORDER BY `id` ASC");
            $stmt->execute([$companyId, $category]);
            $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($prods)) return $prods;
        }
        $stmt = $pdo->prepare("SELECT * FROM `products` WHERE `company_id` = ? ORDER BY `id` ASC");
        $stmt->execute([$companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getProductById(PDO $pdo, int $companyId, int $id): ?array {
        $stmt = $pdo->prepare("SELECT * FROM `products` WHERE `id` = ? AND `company_id` = ? LIMIT 1");
        $stmt->execute([$id, $companyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function getCompanyAssets(PDO $pdo, int $companyId): array {
        $stmt = $pdo->prepare("SELECT * FROM `company_assets` WHERE `company_id` = ? AND `is_active` = 1 ORDER BY `id` ASC");
        $stmt->execute([$companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function formatProductCard(array $prod): array {
        $features = !empty($prod['features']) ? (is_array($prod['features']) ? $prod['features'] : json_decode($prod['features'], true)) : [];
        return [
            'id' => (int)$prod['id'],
            'name' => $prod['name'],
            'category' => $prod['category'] ?? 'Solution',
            'price_inr' => (int)$prod['price_inr'],
            'original_price_inr' => (int)($prod['original_price_inr'] ?? 0),
            'discount_percent' => (int)($prod['discount_percent'] ?? 0),
            'emi_available' => !empty($prod['emi_available']),
            'emi_starting_at_inr' => (int)($prod['emi_starting_at_inr'] ?? ceil($prod['price_inr'] / 3)),
            'duration' => $prod['duration'] ?? '',
            'features' => array_slice($features ?: ['Verified Commercial Offering', 'Instant Access', 'Dedicated Support'], 0, 3)
        ];
    }

    public static function getNextNodes(array $graph, string $sourceNodeId, ?string $sourceHandle = null): array {
        $edges = $graph['edges'] ?? [];
        $nodes = $graph['nodes'] ?? [];
        $targetIds = [];

        foreach ($edges as $e) {
            if ($e['source'] === $sourceNodeId) {
                if ($sourceHandle !== null && !empty($e['sourceHandle']) && $e['sourceHandle'] !== $sourceHandle) {
                    continue;
                }
                $targetIds[] = $e['target'];
            }
        }

        $result = [];
        foreach ($nodes as $n) {
            if (in_array($n['id'], $targetIds)) {
                $result[] = $n;
            }
        }
        return $result;
    }

    public static function getOutgoingEdges(array $graph, string $sourceNodeId): array {
        $out = [];
        foreach ($graph['edges'] ?? [] as $e) {
            if ($e['source'] === $sourceNodeId) {
                $out[] = $e;
            }
        }
        return $out;
    }

    public static function findNodeById(array $nodes, string $id): ?array {
        foreach ($nodes as $n) {
            if ($n['id'] === $id) return $n;
        }
        return null;
    }

    public static function logNodeStep(
        PDO $pdo,
        int $executionId,
        int $companyId,
        int $automationId,
        string $nodeId,
        string $nodeType,
        string $nodeTitle,
        string $status,
        array $input,
        array $output
    ): void {
        try {
            $pdo->prepare("
                INSERT INTO `automation_logs`
                (`execution_id`, `company_id`, `automation_id`, `node_id`, `node_type`, `node_title`, `status`, `input_data_json`, `output_data_json`, `created_at`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $executionId,
                $companyId,
                $automationId,
                $nodeId,
                $nodeType,
                $nodeTitle,
                $status,
                json_encode($input, JSON_UNESCAPED_UNICODE),
                json_encode($output, JSON_UNESCAPED_UNICODE)
            ]);
        } catch (Exception $e) {}
    }

    public static function completeExecution(PDO $pdo, int $executionId, array $completed = [], array $vars = []): void {
        $pdo->prepare("
            UPDATE `automation_executions`
            SET `status` = 'completed', `completed_nodes_json` = ?, `variables_json` = ?, `completed_at` = NOW()
            WHERE `id` = ?
        ")->execute([
            json_encode($completed),
            json_encode($vars, JSON_UNESCAPED_UNICODE),
            $executionId
        ]);
    }

    public static function failExecution(PDO $pdo, int $executionId, string $error): void {
        $pdo->prepare("
            UPDATE `automation_executions`
            SET `status` = 'failed', `error_details` = ?, `completed_at` = NOW()
            WHERE `id` = ?
        ")->execute([$error, $executionId]);
    }
}
