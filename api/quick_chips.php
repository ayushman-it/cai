<?php
/**
 * CUBOIDPILOT — QUICK REPLY CHIPS & ACTION REGISTRY API
 * Allows company admins to create, edit, reorder, delete, and publish custom widget chips.
 * Multi-tenant scoped with strict isolation.
 */

error_reporting(0);
ini_set('display_errors', '0');

if (php_sapi_name() !== 'cli' && !headers_sent()) {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Company-Key");
    header("Content-Type: application/json; charset=UTF-8");
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/entitlements.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = getDbConnection();

// Resolve Tenant / Company Context
$companyId = null;
if (!empty($_SESSION['company_id'])) {
    $companyId = (int)$_SESSION['company_id'];
} else {
    $companyKey = trim($_GET['company_key'] ?? $_POST['company_key'] ?? $_SERVER['HTTP_X_COMPANY_KEY'] ?? '');
    if (!empty($companyKey)) {
        if ($companyKey === 'default' || $companyKey === 'cp_live_cuboidpilot' || $companyKey === 'cuboidpilot') {
            $companyKey = 'cp_live_cuboidsoft';
        }
        $cStmt = $pdo->prepare("SELECT id FROM `companies` WHERE `company_key` = ? OR `slug` = ? LIMIT 1");
        $cStmt->execute([$companyKey, $companyKey]);
        $companyId = (int)$cStmt->fetchColumn();
    }
}

if (!$companyId && !empty($_SESSION['user_id'])) {
    $uStmt = $pdo->prepare("SELECT company_id, is_super_admin FROM `users` WHERE id = ? LIMIT 1");
    $uStmt->execute([(int)$_SESSION['user_id']]);
    $u = $uStmt->fetch();
    if (!empty($u['company_id'])) {
        $companyId = (int)$u['company_id'];
        $_SESSION['company_id'] = $companyId;
    }
}

if (!$companyId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required: active workspace context not found.']);
    exit;
}

$action = strtolower(trim($_GET['action'] ?? $_POST['action'] ?? 'list'));
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true) ?? $_POST;

function syncQuickChipsToWidgetSettings($pdo, $companyId) {
    try {
        $stmt = $pdo->prepare("
            SELECT label, response_text as text, action_type, id
            FROM `quick_chips`
            WHERE company_id = ? AND is_active = 1 AND is_starter = 1 AND status = 'published'
            ORDER BY display_order ASC, id ASC
        ");
        $stmt->execute([$companyId]);
        $chips = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $json = json_encode($chips, JSON_UNESCAPED_UNICODE);
        $pdo->prepare("UPDATE widget_settings SET quick_actions_json = ?, updated_at = NOW() WHERE company_id = ?")
            ->execute([$json, $companyId]);
    } catch (Throwable $e) {}
}

try {
    switch ($action) {

        // 1. LIST CHIPS
        case 'list':
            $stmt = $pdo->prepare("
                SELECT qc.*,
                       cat.name as linked_catalog_name,
                       c.name as linked_category_name,
                       a.title as linked_asset_title,
                       p.name as linked_product_name
                FROM `quick_chips` qc
                LEFT JOIN `offering_catalogs` cat ON cat.id = qc.linked_catalog_id
                LEFT JOIN `company_business_categories` c ON c.id = qc.linked_category_id
                LEFT JOIN `company_assets` a ON a.id = qc.linked_asset_id
                LEFT JOIN `products` p ON p.id = qc.linked_product_id
                WHERE qc.company_id = ?
                ORDER BY qc.display_order ASC, qc.id ASC
            ");
            $stmt->execute([$companyId]);
            $chips = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'count'   => count($chips),
                'chips'   => $chips
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        // 2. SAVE (CREATE / UPDATE) CHIP
        case 'save':
            $chipId       = (int)($data['id'] ?? 0);
            $label        = trim($data['label'] ?? '');
            $actionType   = trim($data['action_type'] ?? 'SEND_TEXT_RESPONSE');
            $responseText = trim($data['response_text'] ?? '');
            $catalogId    = !empty($data['linked_catalog_id']) ? (int)$data['linked_catalog_id'] : null;
            $categoryId   = !empty($data['linked_category_id']) ? (int)$data['linked_category_id'] : null;
            $assetId      = !empty($data['linked_asset_id']) ? (int)$data['linked_asset_id'] : null;
            $productId    = !empty($data['linked_product_id']) ? (int)$data['linked_product_id'] : null;
            $payloadJson  = is_array($data['action_payload_json'] ?? null) ? json_encode($data['action_payload_json']) : trim($data['action_payload_json'] ?? '');
            $isStarter    = isset($data['is_starter']) ? ((int)$data['is_starter'] ? 1 : 0) : 1;
            $isContextual = isset($data['is_contextual']) ? ((int)$data['is_contextual'] ? 1 : 0) : 0;
            $status       = in_array($data['status'] ?? '', ['published', 'draft']) ? $data['status'] : 'published';
            $isActive     = isset($data['is_active']) ? ((int)$data['is_active'] ? 1 : 0) : 1;
            $order        = (int)($data['display_order'] ?? 0);

            if (empty($label)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Chip label is required.']);
                exit;
            }

            // Valid Action Types Registry
            $validActions = [
                'SEND_TEXT_RESPONSE',
                'OPEN_CATALOG_SELECTOR',
                'OPEN_CATALOG',
                'OPEN_BROCHURE_SELECTOR',
                'OPEN_BROCHURE',
                'DISPLAY_OFFERINGS',
                'DISPLAY_OFFERING_ITEM',
                'ASK_QUESTION',
                'BOOK_APPOINTMENT',
                'START_HUMAN_HANDOFF',
                'OPEN_URL'
            ];
            if (!in_array($actionType, $validActions)) {
                $actionType = 'SEND_TEXT_RESPONSE';
            }

            if ($chipId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE `quick_chips`
                    SET `label` = ?, `action_type` = ?, `response_text` = ?,
                        `linked_catalog_id` = ?, `linked_category_id` = ?, `linked_asset_id` = ?, `linked_product_id` = ?,
                        `action_payload_json` = ?, `is_starter` = ?, `is_contextual` = ?, `status` = ?, `is_active` = ?, `display_order` = ?, `updated_at` = NOW()
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([
                    $label, $actionType, $responseText,
                    $catalogId, $categoryId, $assetId, $productId,
                    $payloadJson, $isStarter, $isContextual, $status, $isActive, $order,
                    $chipId, $companyId
                ]);
                syncQuickChipsToWidgetSettings($pdo, $companyId);
                echo json_encode(['success' => true, 'id' => $chipId, 'message' => "Chip '{$label}' updated successfully."]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO `quick_chips`
                    (`company_id`, `label`, `action_type`, `response_text`,
                     `linked_catalog_id`, `linked_category_id`, `linked_asset_id`, `linked_product_id`,
                     `action_payload_json`, `is_starter`, `is_contextual`, `status`, `is_active`, `display_order`, `created_at`, `updated_at`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $companyId, $label, $actionType, $responseText,
                    $catalogId, $categoryId, $assetId, $productId,
                    $payloadJson, $isStarter, $isContextual, $status, $isActive, $order
                ]);
                $newId = (int)$pdo->lastInsertId();
                syncQuickChipsToWidgetSettings($pdo, $companyId);
                echo json_encode(['success' => true, 'id' => $newId, 'message' => "Chip '{$label}' created successfully."]);
            }
            break;

        // 3. TOGGLE ACTIVE STATUS
        case 'toggle_active':
            $chipId = (int)($data['id'] ?? 0);
            if (!$chipId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing chip ID.']);
                exit;
            }

            $pdo->prepare("UPDATE `quick_chips` SET `is_active` = NOT `is_active`, `updated_at` = NOW() WHERE id = ? AND company_id = ?")->execute([$chipId, $companyId]);
            syncQuickChipsToWidgetSettings($pdo, $companyId);
            echo json_encode(['success' => true, 'message' => 'Chip status updated.']);
            break;

        // 4. PUBLISH / DRAFT TOGGLE
        case 'toggle_status':
            $chipId = (int)($data['id'] ?? 0);
            if (!$chipId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing chip ID.']);
                exit;
            }

            $pdo->prepare("UPDATE `quick_chips` SET `status` = IF(`status` = 'published', 'draft', 'published'), `updated_at` = NOW() WHERE id = ? AND company_id = ?")->execute([$chipId, $companyId]);
            syncQuickChipsToWidgetSettings($pdo, $companyId);
            echo json_encode(['success' => true, 'message' => 'Chip publication status updated.']);
            break;

        // 5. DELETE CHIP
        case 'delete':
            $chipId = (int)($data['id'] ?? 0);
            if (!$chipId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing chip ID.']);
                exit;
            }

            $pdo->prepare("DELETE FROM `quick_chips` WHERE id = ? AND company_id = ?")->execute([$chipId, $companyId]);
            syncQuickChipsToWidgetSettings($pdo, $companyId);
            echo json_encode(['success' => true, 'message' => 'Chip deleted successfully.']);
            break;

        // 6. REORDER CHIPS
        case 'reorder':
            $orders = $data['orders'] ?? []; // [{id: 1, order: 1}, ...]
            if (is_array($orders)) {
                $upd = $pdo->prepare("UPDATE `quick_chips` SET `display_order` = ? WHERE id = ? AND company_id = ?");
                foreach ($orders as $o) {
                    if (isset($o['id'], $o['order'])) {
                        $upd->execute([(int)$o['order'], (int)$o['id'], $companyId]);
                    }
                }
            }
            syncQuickChipsToWidgetSettings($pdo, $companyId);
            echo json_encode(['success' => true, 'message' => 'Chip order saved.']);
            break;

        // 7. AI SUGGESTED CHIPS GENERATOR
        case 'suggest_ai_chips':
            require_once __DIR__ . '/../includes/gemini_service.php';

            // Gather company context
            $cStmt = $pdo->prepare("SELECT name, industry FROM `companies` WHERE id = ? LIMIT 1");
            $cStmt->execute([$companyId]);
            $comp = $cStmt->fetch(PDO::FETCH_ASSOC);
            $compName = $comp['name'] ?? 'Our Business';
            $industry = $comp['industry'] ?? 'General Business';

            // Gather catalog offerings
            $catStmt = $pdo->prepare("SELECT name, category FROM `products` WHERE company_id = ? LIMIT 8");
            $catStmt->execute([$companyId]);
            $prods = $catStmt->fetchAll(PDO::FETCH_ASSOC);
            $prodNames = array_map(fn($p) => "{$p['name']} ({$p['category']})", $prods);

            // Gather business categories
            $bizCats = [];
            try {
                $bCatStmt = $pdo->prepare("SELECT name FROM `company_business_categories` WHERE company_id = ? LIMIT 6");
                $bCatStmt->execute([$companyId]);
                $bizCats = $bCatStmt->fetchAll(PDO::FETCH_COLUMN);
            } catch (Throwable $e) {}

            $prompt = "You are an expert customer experience architect for Cai, an Intercom-level AI conversation platform.\n"
                . "Company: {$compName}\n"
                . "Industry / Profile: {$industry}\n"
                . (!empty($bizCats) ? "Offerings / Categories: " . implode(', ', $bizCats) . "\n" : "")
                . (!empty($prodNames) ? "Key Products / Services: " . implode(', ', $prodNames) . "\n" : "")
                . "\n"
                . "TASK:\n"
                . "Generate 4 to 6 high-converting, intelligent quick-reply chips for this specific business's website chat widget.\n"
                . "Each chip must have:\n"
                . "1. label: Short 2-4 words button text (concise, clear, zero emojis, professional).\n"
                . "2. text: The natural customer message sent when clicked.\n"
                . "3. action_type: One of 'SEND_TEXT_RESPONSE', 'OPEN_CATALOG_SELECTOR', 'OPEN_BROCHURE_SELECTOR', 'START_HUMAN_HANDOFF', 'BOOK_APPOINTMENT'.\n"
                . "4. explanation: 1 short sentence why this chip is great for the visitor.\n"
                . "\n"
                . "CRITICAL RULES:\n"
                . "- Do NOT use education/course/counselor wording unless the company is explicitly an education academy.\n"
                . "- Strictly tailor suggestions to their industry ({$industry}).\n"
                . "- Return ONLY a valid JSON array of objects. No markdown formatting, no code blocks.\n"
                . "Example format:\n"
                . "[\n"
                . "  {\"label\": \"Explore Solutions\", \"text\": \"Can you tell me more about your core solutions?\", \"action_type\": \"SEND_TEXT_RESPONSE\", \"explanation\": \"Helps visitors discover high-level capabilities.\"},\n"
                . "  {\"label\": \"View Pricing Plans\", \"text\": \"What are your pricing plans and packages?\", \"action_type\": \"SEND_TEXT_RESPONSE\", \"explanation\": \"Direct path for price-conscious qualified leads.\"},\n"
                . "  {\"label\": \"Browse Offerings\", \"text\": \"Show me all available offerings\", \"action_type\": \"OPEN_CATALOG_SELECTOR\", \"explanation\": \"Opens interactive product catalog.\"},\n"
                . "  {\"label\": \"Schedule Consultation\", \"text\": \"I would like to schedule a 1-on-1 consultation\", \"action_type\": \"BOOK_APPOINTMENT\", \"explanation\": \"High conversion for ready-to-talk prospects.\"},\n"
                . "  {\"label\": \"Talk to Specialist\", \"text\": \"Connect me with a team specialist\", \"action_type\": \"START_HUMAN_HANDOFF\", \"explanation\": \"Instant escalations for complex inquiries.\"}\n"
                . "]";

            $raw = GeminiService::generateResponse("You are an expert AI conversion strategist. Respond with pure JSON only.", [
                ['role' => 'user', 'content' => $prompt]
            ], ['temperature' => 0.4]);

            $rawClean = preg_replace('/```(?:json)?\s*/i', '', $raw);
            $rawClean = str_replace('```', '', $rawClean);
            $suggestions = json_decode(trim($rawClean), true);

            if (!is_array($suggestions) || empty($suggestions)) {
                // High quality fallback
                $suggestions = [
                    ['label' => 'Explore Solutions', 'text' => 'Can you give me an overview of your offerings and solutions?', 'action_type' => 'SEND_TEXT_RESPONSE', 'explanation' => 'Helps new visitors quickly understand your core value.'],
                    ['label' => 'Pricing & Plans', 'text' => 'What are your pricing packages and options?', 'action_type' => 'SEND_TEXT_RESPONSE', 'explanation' => 'Quick access for prospects evaluating budget.'],
                    ['label' => 'Interactive Catalog', 'text' => 'Browse full catalog of services', 'action_type' => 'OPEN_CATALOG_SELECTOR', 'explanation' => 'Opens interactive solutions cards.'],
                    ['label' => 'Download Overview', 'text' => 'Can you share official documentation or overview?', 'action_type' => 'OPEN_BROCHURE_SELECTOR', 'explanation' => 'Provides downloadable collateral and decks.'],
                    ['label' => 'Talk to Specialist', 'text' => 'I would like to speak with a human advisor', 'action_type' => 'START_HUMAN_HANDOFF', 'explanation' => 'Direct handoff for customized inquiries.']
                ];
            }

            echo json_encode([
                'success' => true,
                'company_name' => $compName,
                'industry' => $industry,
                'suggestions' => $suggestions
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Unknown action '{$action}'"]);
            break;
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
