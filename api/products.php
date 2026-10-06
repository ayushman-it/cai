<?php
/**
 * CUBOIDPILOT — PRODUCTS & COMMERCIAL OFFERINGS API
 * Handles multi-tenant catalog management (Products, Services, Courses, Packages,
 * Memberships, Consultations, Subscriptions) and Bulk CSV Ingestion.
 */

if (php_sapi_name() !== 'cli' && !headers_sent()) {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Company-Key");
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/entitlements.php';

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

$pdo = getDbConnection();

// Resolve action and input
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$rawInput = file_get_contents('php://input');
$jsonData = json_decode($rawInput, true) ?? [];
if (!empty($jsonData['action'])) {
    $action = $jsonData['action'];
}

// -------------------------------------------------------------------------
// Helper: Resolve Workspace / Company ID
// -------------------------------------------------------------------------
$companyId = null;

if (!empty($_SESSION['company_id'])) {
    $companyId = (int)$_SESSION['company_id'];
} else {
    // Check company_key from query, post, json, or headers
    $companyKey = trim($_GET['company_key'] ?? $_POST['company_key'] ?? $jsonData['company_key'] ?? $_SERVER['HTTP_X_COMPANY_KEY'] ?? '');
    if (!empty($companyKey)) {
        if ($companyKey === 'default' || $companyKey === 'cp_live_cuboidpilot' || $companyKey === 'cuboidpilot') {
            $companyKey = 'cp_live_cuboidsoft';
        }
        $cStmt = $pdo->prepare("SELECT id FROM `companies` WHERE `company_key` = ? OR `slug` = ? LIMIT 1");
        $cStmt->execute([$companyKey, $companyKey]);
        $companyId = (int)$cStmt->fetchColumn();
    }
}

// Special case: CSV Template download does not require authentication
if ($action === 'template_csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="cai_catalog_template.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'name',
        'category',
        'price_inr',
        'original_price_inr',
        'duration',
        'target_audience',
        'features',
        'emi_available',
        'emi_starting_at_inr',
        'description',
        'payment_url'
    ]);
    fputcsv($output, [
        'Full Stack Web Development Cohort',
        'course',
        '24999',
        '34999',
        '12 Weeks',
        'College Freshers & Career Switchers',
        'React & Node.js;Live Mentorship;10 Capstone Projects;Placement Assistance;Verified Certificate',
        '1',
        '4999',
        'Comprehensive 12-week immersive bootcamp covering modern frontend, backend, system design, and deployment.',
        ''
    ]);
    fputcsv($output, [
        'Data Science & AI Engineering',
        'course',
        '29999',
        '39999',
        '16 Weeks',
        'Working Developers & Tech Graduates',
        'Python & PyTorch;Generative AI & LLMs;RAG Architecture;Production Deployment;Mock Interviews',
        '1',
        '5999',
        'Master real-world machine learning, deep learning, prompt engineering, and LLM orchestration.',
        ''
    ]);
    fputcsv($output, [
        '1-on-1 Career Strategy Consultation',
        'consultation',
        '1499',
        '2499',
        '45 Mins',
        'Developers preparing for senior interviews',
        'Resume Review;System Design Roadmap;Salary Negotiation Playbook;Recording provided',
        '0',
        '0',
        'Direct 45-minute private strategy session with a Senior Principal Architect.',
        ''
    ]);
    fputcsv($output, [
        'CuboidPilot Pro Annual Membership',
        'membership',
        '14999',
        '19999',
        '1 Year',
        'Agencies & SaaS Founders',
        'Unlimited AI chats;Full WhatsApp Cloud API;Custom Prompt Tuning;Dedicated CSM;White-labeling',
        '1',
        '2999',
        'Year-long complete enterprise conversational intelligence suite with priority SLA.',
        ''
    ]);
    fclose($output);
    exit;
}

// Authentication check for other actions
if (!$companyId) {
    header("Content-Type: application/json; charset=UTF-8");
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication or valid company_key required.']);
    exit;
}

if (!headers_sent()) {
    header("Content-Type: application/json; charset=UTF-8");
}

try {
    switch ($action) {
        // =================================================================
        // ACTION: list
        // =================================================================
        case 'list':
            $category = trim($_GET['category'] ?? $jsonData['category'] ?? '');
            $activeOnly = isset($_GET['active_only']) ? (int)$_GET['active_only'] : (isset($jsonData['active_only']) ? (int)$jsonData['active_only'] : 1);
            $search = trim($_GET['search'] ?? $jsonData['search'] ?? '');

            $sql = "SELECT p.*, a.file_name as brochure_file_name, a.file_size as brochure_file_size 
                    FROM `products` p
                    LEFT JOIN `company_assets` a ON a.id = p.brochure_asset_id
                    WHERE p.`company_id` = ?";
            $params = [$companyId];

            if ($activeOnly) {
                $sql .= " AND p.`is_active` = 1";
            }
            if (!empty($category)) {
                $sql .= " AND p.`category` = ?";
                $params[] = $category;
            }
            if (!empty($search)) {
                $sql .= " AND (p.`name` LIKE ? OR p.`description` LIKE ? OR p.`target_audience` LIKE ?)";
                $params[] = "%{$search}%";
                $params[] = "%{$search}%";
                $params[] = "%{$search}%";
            }

            $sql .= " ORDER BY p.`id` ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch variants for each product
            $vStmt = $pdo->prepare("SELECT * FROM `product_variants` WHERE `company_id` = ? AND `is_active` = 1 ORDER BY `price_inr` ASC");
            $vStmt->execute([$companyId]);
            $allVariants = $vStmt->fetchAll(PDO::FETCH_ASSOC);
            $variantsByProd = [];
            foreach ($allVariants as $v) {
                $variantsByProd[$v['product_id']][] = $v;
            }

            $formatted = [];
            foreach ($products as $p) {
                $features = !empty($p['features_json']) ? json_decode($p['features_json'], true) : [];
                $deliverables = !empty($p['deliverables_json']) ? json_decode($p['deliverables_json'], true) : [];
                $emiPlans = !empty($p['emi_plans_json']) ? json_decode($p['emi_plans_json'], true) : [];
                $faqs = !empty($p['faq_json']) ? json_decode($p['faq_json'], true) : [];

                $formatted[] = [
                    'id'                          => (int)$p['id'],
                    'company_id'                  => (int)$p['company_id'],
                    'name'                        => $p['name'],
                    'category'                    => $p['category'] ?: 'service',
                    'subcategory'                 => $p['subcategory'] ?: '',
                    'sku'                         => $p['sku'] ?: '',
                    'price_inr'                   => (int)$p['price_inr'],
                    'original_price_inr'          => (int)$p['original_price_inr'],
                    'discount_percent'            => (int)$p['discount_percent'],
                    'duration'                    => $p['duration'] ?: '',
                    'currency'                    => $p['currency'] ?: 'INR',
                    'description'                 => $p['description'] ?: '',
                    'features'                    => is_array($features) ? $features : [],
                    'deliverables'                => is_array($deliverables) ? $deliverables : [],
                    'prerequisites'               => $p['prerequisites'] ?: '',
                    'target_audience'             => $p['target_audience'] ?: '',
                    'emi_available'               => (bool)$p['emi_available'],
                    'emi_starting_at_inr'         => (int)$p['emi_starting_at_inr'],
                    'emi_plans'                   => is_array($emiPlans) ? $emiPlans : [],
                    'max_discount_allowed_percent'=> (int)$p['max_discount_allowed_percent'],
                    'brochure_asset_id'           => $p['brochure_asset_id'] ? (int)$p['brochure_asset_id'] : null,
                    'brochure_download_url'       => $p['brochure_asset_id'] ? "../api/assets.php?action=download&id=" . (int)$p['brochure_asset_id'] : null,
                    'thumbnail_url'               => $p['thumbnail_url'] ?: '',
                    'payment_url'                 => $p['payment_url'] ?: '',
                    'faqs'                        => is_array($faqs) ? $faqs : [],
                    'variants'                    => $variantsByProd[$p['id']] ?? [],
                    'is_active'                   => (bool)$p['is_active']
                ];
            }

            echo json_encode([
                'success'  => true,
                'count'    => count($formatted),
                'products' => $formatted
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;

        // =================================================================
        // ACTION: get
        // =================================================================
        case 'get':
            $id = (int)($_GET['id'] ?? $jsonData['id'] ?? 0);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Product ID is required']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT p.*, a.file_name as brochure_file_name, a.title as brochure_title, a.file_size as brochure_file_size
                FROM `products` p
                LEFT JOIN `company_assets` a ON a.id = p.brochure_asset_id
                WHERE p.`id` = ? AND p.`company_id` = ?
                LIMIT 1
            ");
            $stmt->execute([$id, $companyId]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$p) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Product offering not found']);
                exit;
            }

            $vStmt = $pdo->prepare("SELECT * FROM `product_variants` WHERE `product_id` = ? AND `company_id` = ? ORDER BY `price_inr` ASC");
            $vStmt->execute([$id, $companyId]);
            $variants = $vStmt->fetchAll(PDO::FETCH_ASSOC);

            $features = !empty($p['features_json']) ? json_decode($p['features_json'], true) : [];
            $deliverables = !empty($p['deliverables_json']) ? json_decode($p['deliverables_json'], true) : [];
            $emiPlans = !empty($p['emi_plans_json']) ? json_decode($p['emi_plans_json'], true) : [];

            // If emi_available is true but no custom emi_plans provided, generate standard 3-part plan
            if ($p['emi_available'] && empty($emiPlans) && $p['price_inr'] > 0) {
                $down = (int)round($p['price_inr'] * 0.3);
                $rem = $p['price_inr'] - $down;
                $perMonth = (int)round($rem / 3);
                $emiPlans = [
                    [
                        'splits'       => 3,
                        'down_payment' => $down,
                        'per_month'    => $perMonth,
                        'label'        => '3-Month Standard EMI'
                    ]
                ];
            }

            echo json_encode([
                'success' => true,
                'product' => [
                    'id'                          => (int)$p['id'],
                    'name'                        => $p['name'],
                    'category'                    => $p['category'],
                    'subcategory'                 => $p['subcategory'],
                    'sku'                         => $p['sku'],
                    'price_inr'                   => (int)$p['price_inr'],
                    'original_price_inr'          => (int)$p['original_price_inr'],
                    'discount_percent'            => (int)$p['discount_percent'],
                    'duration'                    => $p['duration'],
                    'currency'                    => $p['currency'] ?: 'INR',
                    'description'                 => $p['description'],
                    'features'                    => is_array($features) ? $features : [],
                    'deliverables'                => is_array($deliverables) ? $deliverables : [],
                    'prerequisites'               => $p['prerequisites'],
                    'target_audience'             => $p['target_audience'],
                    'emi_available'               => (bool)$p['emi_available'],
                    'emi_starting_at_inr'         => (int)$p['emi_starting_at_inr'],
                    'emi_plans'                   => $emiPlans,
                    'max_discount_allowed_percent'=> (int)$p['max_discount_allowed_percent'],
                    'brochure_asset_id'           => $p['brochure_asset_id'] ? (int)$p['brochure_asset_id'] : null,
                    'brochure_download_url'       => $p['brochure_asset_id'] ? "../api/assets.php?action=download&id=" . (int)$p['brochure_asset_id'] : null,
                    'brochure_title'              => $p['brochure_title'] ?? null,
                    'thumbnail_url'               => $p['thumbnail_url'],
                    'payment_url'                 => $p['payment_url'],
                    'variants'                    => $variants,
                    'is_active'                   => (bool)$p['is_active']
                ]
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;

        // =================================================================
        // ACTION: create
        // =================================================================
        case 'create':
            $payload = !empty($jsonData) ? $jsonData : $_POST;

            $name = trim($payload['name'] ?? '');
            $category = trim($payload['category'] ?? 'service');
            $priceInr = max(0, (int)($payload['price_inr'] ?? 0));
            $origPrice = max(0, (int)($payload['original_price_inr'] ?? 0));
            $duration = trim($payload['duration'] ?? '');
            $description = trim($payload['description'] ?? '');
            $targetAudience = trim($payload['target_audience'] ?? '');
            $prerequisites = trim($payload['prerequisites'] ?? '');
            $paymentUrl = trim($payload['payment_url'] ?? '');
            $sku = trim($payload['sku'] ?? '');
            $emiAvailable = !empty($payload['emi_available']) ? 1 : 0;
            $emiStarting = max(0, (int)($payload['emi_starting_at_inr'] ?? 0));
            $maxDiscount = max(0, min(100, (int)($payload['max_discount_allowed_percent'] ?? 10)));
            $brochureAssetId = !empty($payload['brochure_asset_id']) ? (int)$payload['brochure_asset_id'] : null;
            $thumbnailUrl = trim($payload['thumbnail_url'] ?? '');

            if (empty($name)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Offering name is required.']);
                exit;
            }

            // Features parsing
            $features = $payload['features'] ?? [];
            if (is_string($features)) {
                $features = array_filter(array_map('trim', preg_split('/[;\n|]/', $features)));
            }
            $featuresJson = !empty($features) ? json_encode(array_values($features), JSON_UNESCAPED_UNICODE) : null;

            // Deliverables parsing
            $deliverables = $payload['deliverables'] ?? [];
            if (is_string($deliverables)) {
                $deliverables = array_filter(array_map('trim', preg_split('/[;\n|]/', $deliverables)));
            }
            $deliverablesJson = !empty($deliverables) ? json_encode(array_values($deliverables), JSON_UNESCAPED_UNICODE) : null;

            // EMI plans
            $emiPlans = $payload['emi_plans'] ?? [];
            if (empty($emiPlans) && $emiAvailable && $priceInr > 0) {
                $down = (int)round($priceInr * 0.3);
                $perMonth = (int)round(($priceInr - $down) / 3);
                $emiPlans = [
                    ['splits' => 3, 'down_payment' => $down, 'per_month' => $perMonth, 'label' => '3-Month Standard EMI']
                ];
            }
            $emiPlansJson = !empty($emiPlans) ? json_encode($emiPlans, JSON_UNESCAPED_UNICODE) : null;

            $discountPercent = ($origPrice > $priceInr && $origPrice > 0)
                ? (int)round((($origPrice - $priceInr) / $origPrice) * 100)
                : 0;

            $ins = $pdo->prepare("
                INSERT INTO `products` (
                    `company_id`, `name`, `category`, `subcategory`, `sku`, `price_inr`,
                    `original_price_inr`, `discount_percent`, `duration`, `currency`,
                    `description`, `features_json`, `deliverables_json`, `prerequisites`,
                    `target_audience`, `emi_available`, `emi_starting_at_inr`, `emi_plans_json`,
                    `max_discount_allowed_percent`, `brochure_asset_id`, `thumbnail_url`,
                    `payment_url`, `is_active`, `created_at`
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, 'INR',
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, 1, NOW()
                )
            ");
            $ins->execute([
                $companyId, $name, $category, trim($payload['subcategory'] ?? ''), $sku, $priceInr,
                $origPrice, $discountPercent, $duration,
                $description, $featuresJson, $deliverablesJson, $prerequisites,
                $targetAudience, $emiAvailable, $emiStarting, $emiPlansJson,
                $maxDiscount, $brochureAssetId, $thumbnailUrl,
                $paymentUrl
            ]);
            $newId = (int)$pdo->lastInsertId();

            echo json_encode([
                'success'    => true,
                'product_id' => $newId,
                'message'    => "Offering '{$name}' created successfully."
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;

        // =================================================================
        // ACTION: update
        // =================================================================
        case 'update':
            $payload = !empty($jsonData) ? $jsonData : $_POST;
            $id = (int)($payload['id'] ?? 0);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Product ID is required']);
                exit;
            }

            // Verify ownership
            $chk = $pdo->prepare("SELECT id FROM `products` WHERE id = ? AND company_id = ? LIMIT 1");
            $chk->execute([$id, $companyId]);
            if (!$chk->fetch()) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Product not found in this workspace']);
                exit;
            }

            $updates = [];
            $params = [];

            if (isset($payload['name'])) {
                $updates[] = "`name` = ?";
                $params[] = trim($payload['name']);
            }
            if (isset($payload['category'])) {
                $updates[] = "`category` = ?";
                $params[] = trim($payload['category']);
            }
            if (isset($payload['price_inr'])) {
                $updates[] = "`price_inr` = ?";
                $params[] = max(0, (int)$payload['price_inr']);
            }
            if (isset($payload['original_price_inr'])) {
                $updates[] = "`original_price_inr` = ?";
                $params[] = max(0, (int)$payload['original_price_inr']);
            }
            if (isset($payload['duration'])) {
                $updates[] = "`duration` = ?";
                $params[] = trim($payload['duration']);
            }
            if (isset($payload['description'])) {
                $updates[] = "`description` = ?";
                $params[] = trim($payload['description']);
            }
            if (isset($payload['target_audience'])) {
                $updates[] = "`target_audience` = ?";
                $params[] = trim($payload['target_audience']);
            }
            if (isset($payload['prerequisites'])) {
                $updates[] = "`prerequisites` = ?";
                $params[] = trim($payload['prerequisites']);
            }
            if (isset($payload['emi_available'])) {
                $updates[] = "`emi_available` = ?";
                $params[] = !empty($payload['emi_available']) ? 1 : 0;
            }
            if (isset($payload['emi_starting_at_inr'])) {
                $updates[] = "`emi_starting_at_inr` = ?";
                $params[] = max(0, (int)$payload['emi_starting_at_inr']);
            }
            if (isset($payload['max_discount_allowed_percent'])) {
                $updates[] = "`max_discount_allowed_percent` = ?";
                $params[] = max(0, min(100, (int)$payload['max_discount_allowed_percent']));
            }
            if (isset($payload['payment_url'])) {
                $updates[] = "`payment_url` = ?";
                $params[] = trim($payload['payment_url']);
            }
            if (isset($payload['brochure_asset_id'])) {
                $updates[] = "`brochure_asset_id` = ?";
                $params[] = !empty($payload['brochure_asset_id']) ? (int)$payload['brochure_asset_id'] : null;
            }
            if (isset($payload['thumbnail_url'])) {
                $updates[] = "`thumbnail_url` = ?";
                $params[] = trim($payload['thumbnail_url']);
            }
            if (isset($payload['is_active'])) {
                $updates[] = "`is_active` = ?";
                $params[] = !empty($payload['is_active']) ? 1 : 0;
            }
            if (isset($payload['features'])) {
                $feat = $payload['features'];
                if (is_string($feat)) {
                    $feat = array_filter(array_map('trim', preg_split('/[;\n|]/', $feat)));
                }
                $updates[] = "`features_json` = ?";
                $params[] = json_encode(array_values($feat), JSON_UNESCAPED_UNICODE);
            }

            if (!empty($updates)) {
                $params[] = $id;
                $params[] = $companyId;
                $updSql = "UPDATE `products` SET " . implode(", ", $updates) . " WHERE `id` = ? AND `company_id` = ?";
                $pdo->prepare($updSql)->execute($params);
            }

            echo json_encode(['success' => true, 'message' => 'Product updated successfully.']);
            exit;

        // =================================================================
        // ACTION: delete
        // =================================================================
        case 'delete':
            $payload = !empty($jsonData) ? $jsonData : $_POST;
            $id = (int)($payload['id'] ?? ($_GET['id'] ?? 0));
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Product ID is required']);
                exit;
            }

            $pdo->prepare("UPDATE `products` SET `is_active` = 0 WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
            echo json_encode(['success' => true, 'message' => 'Offering deactivated successfully.']);
            exit;

        // =================================================================
        // ACTION: bulk_upload_csv
        // =================================================================
        case 'bulk_upload_csv':
            $csvContent = '';

            if (!empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
                $csvContent = file_get_contents($_FILES['csv_file']['tmp_name']);
            } elseif (!empty($_POST['csv_content'])) {
                $csvContent = $_POST['csv_content'];
            } elseif (!empty($jsonData['csv_content'])) {
                $csvContent = $jsonData['csv_content'];
            }

            if (empty($csvContent)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error'   => 'No CSV file or csv_content provided. Upload a valid CSV file with product data.'
                ]);
                exit;
            }

            // Parse CSV lines cleanly (handles Windows \r\n, Mac \r, Linux \n)
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $csvContent);
            rewind($stream);

            $headers = null;
            $importedCount = 0;
            $errors = [];
            $rowNum = 0;

            $insStmt = $pdo->prepare("
                INSERT INTO `products` (
                    `company_id`, `name`, `category`, `price_inr`, `original_price_inr`,
                    `discount_percent`, `duration`, `target_audience`, `description`,
                    `features_json`, `emi_available`, `emi_starting_at_inr`, `emi_plans_json`,
                    `payment_url`, `is_active`, `created_at`
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, 1, NOW()
                )
            ");

            while (($row = fgetcsv($stream, 4096, ",")) !== false) {
                $rowNum++;
                // Skip empty lines
                if (empty($row) || (count($row) === 1 && $row[0] === null)) {
                    continue;
                }

                if ($headers === null) {
                    // Normalize headers
                    $headers = array_map(function($h) {
                        return strtolower(trim(preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $h)));
                    }, $row);
                    continue;
                }

                if (count($row) < 2) {
                    continue;
                }

                // Map header to values
                $rowData = [];
                foreach ($headers as $idx => $hName) {
                    $rowData[$hName] = trim($row[$idx] ?? '');
                }

                $name = $rowData['name'] ?? ($row[0] ?? '');
                if (empty($name)) {
                    $errors[] = "Row {$rowNum}: Missing offering name.";
                    continue;
                }

                $category = !empty($rowData['category']) ? strtolower($rowData['category']) : 'service';
                $priceInr = max(0, (int)($rowData['price_inr'] ?? 0));
                $origPrice = max(0, (int)($rowData['original_price_inr'] ?? 0));
                $duration = $rowData['duration'] ?? '';
                $targetAudience = $rowData['target_audience'] ?? '';
                $description = $rowData['description'] ?? '';
                $paymentUrl = $rowData['payment_url'] ?? '';

                $rawFeatures = $rowData['features'] ?? '';
                $featuresArr = [];
                if (!empty($rawFeatures)) {
                    $featuresArr = array_filter(array_map('trim', preg_split('/[;\n|]/', $rawFeatures)));
                }
                $featuresJson = !empty($featuresArr) ? json_encode(array_values($featuresArr), JSON_UNESCAPED_UNICODE) : null;

                $emiAvailable = (!empty($rowData['emi_available']) && in_array(strtolower($rowData['emi_available']), ['1', 'true', 'yes'])) ? 1 : 0;
                $emiStarting = max(0, (int)($rowData['emi_starting_at_inr'] ?? 0));

                $emiPlans = null;
                if ($emiAvailable && $priceInr > 0) {
                    $down = (int)round($priceInr * 0.3);
                    $perMonth = (int)round(($priceInr - $down) / 3);
                    $emiPlans = json_encode([
                        ['splits' => 3, 'down_payment' => $down, 'per_month' => $perMonth, 'label' => '3-Month Standard EMI']
                    ], JSON_UNESCAPED_UNICODE);
                    if ($emiStarting === 0) {
                        $emiStarting = $perMonth;
                    }
                }

                $discPercent = ($origPrice > $priceInr && $origPrice > 0)
                    ? (int)round((($origPrice - $priceInr) / $origPrice) * 100)
                    : 0;

                try {
                    $insStmt->execute([
                        $companyId,
                        $name,
                        $category,
                        $priceInr,
                        $origPrice,
                        $discPercent,
                        $duration,
                        $targetAudience,
                        $description,
                        $featuresJson,
                        $emiAvailable,
                        $emiStarting,
                        $emiPlans,
                        $paymentUrl
                    ]);
                    $importedCount++;
                } catch (Exception $e) {
                    $errors[] = "Row {$rowNum} ('{$name}'): " . $e->getMessage();
                }
            }
            fclose($stream);

            echo json_encode([
                'success'        => true,
                'message'        => "Bulk upload complete. Successfully imported {$importedCount} offerings.",
                'imported_count' => $importedCount,
                'errors'         => $errors
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Unknown action '{$action}'."]);
            exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Products API Error: ' . $e->getMessage()]);
    exit;
}
