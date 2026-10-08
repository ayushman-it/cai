<?php
/**
 * CUBOIDPILOT — BUSINESS CONFIGURATION API
 * Manages tenant-defined Business Profile, Categories, Dynamic Sections, and Offering Catalogs.
 * Strictly multi-tenant isolated with session authentication.
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

$action = strtolower(trim($_GET['action'] ?? $_POST['action'] ?? 'get_overview'));
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true) ?? $_POST;

try {
    switch ($action) {

        // -------------------------------------------------------------
        // 1. BUSINESS PROFILE OVERVIEW
        // -------------------------------------------------------------
        case 'get_overview':
        case 'get_profile':
            $compStmt = $pdo->prepare("SELECT id, name, industry, business_description, company_key FROM `companies` WHERE id = ? LIMIT 1");
            $compStmt->execute([$companyId]);
            $comp = $compStmt->fetch(PDO::FETCH_ASSOC);

            // Fetch categories
            $catStmt = $pdo->prepare("
                SELECT c.*, 
                       (SELECT COUNT(*) FROM `products` p WHERE p.company_id = c.company_id AND (p.category = c.name OR p.subcategory = c.name)) as offering_count
                FROM `company_business_categories` c
                WHERE c.company_id = ?
                ORDER BY c.display_order ASC, c.id ASC
            ");
            $catStmt->execute([$companyId]);
            $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch dynamic sections
            $secStmt = $pdo->prepare("
                SELECT s.*, 
                       (SELECT COUNT(*) FROM `products` p WHERE p.company_id = s.company_id AND p.section_id = s.id) as item_count
                FROM `company_dynamic_sections` s
                WHERE s.company_id = ?
                ORDER BY s.display_order ASC, s.id ASC
            ");
            $secStmt->execute([$companyId]);
            $sections = $secStmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch catalogs summary
            $catlStmt = $pdo->prepare("
                SELECT id, name, slug, description, image_url, is_published, widget_visible, display_order, category_ids, section_ids
                FROM `offering_catalogs`
                WHERE company_id = ?
                ORDER BY display_order ASC, id ASC
            ");
            $catlStmt->execute([$companyId]);
            $catalogs = $catlStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success'    => true,
                'company'    => $comp,
                'categories' => $categories,
                'sections'   => $sections,
                'catalogs'   => $catalogs
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        case 'update_profile':
            $desc = trim($data['business_description'] ?? '');
            $industry = trim($data['industry'] ?? '');

            $pdo->prepare("UPDATE `companies` SET `business_description` = ?, `industry` = IF(? != '', ?, industry), `updated_at` = NOW() WHERE id = ?")
                ->execute([$desc, $industry, $industry, $companyId]);

            echo json_encode(['success' => true, 'message' => 'Business profile updated successfully.']);
            break;

        // -------------------------------------------------------------
        // 2. CATEGORIES MANAGEMENT
        // -------------------------------------------------------------
        case 'list_categories':
        case 'get_categories':
            $stmt = $pdo->prepare("SELECT * FROM `company_business_categories` WHERE company_id = ? ORDER BY display_order ASC, id ASC");
            $stmt->execute([$companyId]);
            echo json_encode(['success' => true, 'categories' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'save_category':
            $catId       = (int)($data['id'] ?? 0);
            $name        = trim($data['name'] ?? '');
            $description = trim($data['description'] ?? '');
            $parentId    = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;
            $isActive    = isset($data['is_active']) ? ((int)$data['is_active'] ? 1 : 0) : 1;
            $order       = (int)($data['display_order'] ?? 0);

            if (empty($name)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Category name is required.']);
                exit;
            }

            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));

            if ($catId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE `company_business_categories`
                    SET `name` = ?, `slug` = ?, `description` = ?, `parent_id` = ?, `is_active` = ?, `display_order` = ?
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([$name, $slug, $description, $parentId, $isActive, $order, $catId, $companyId]);
                echo json_encode(['success' => true, 'id' => $catId, 'message' => 'Category updated successfully.']);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO `company_business_categories` (`company_id`, `name`, `slug`, `description`, `parent_id`, `is_active`, `display_order`)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$companyId, $name, $slug, $description, $parentId, $isActive, $order]);
                echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'Category created successfully.']);
            }
            break;

        case 'delete_category':
            $catId = (int)($data['id'] ?? 0);
            if (!$catId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing category ID.']);
                exit;
            }

            $pdo->prepare("DELETE FROM `company_business_categories` WHERE id = ? AND company_id = ?")->execute([$catId, $companyId]);
            echo json_encode(['success' => true, 'message' => 'Category deleted successfully.']);
            break;

        // -------------------------------------------------------------
        // 3. DYNAMIC SECTIONS MANAGEMENT
        // -------------------------------------------------------------
        case 'list_sections':
        case 'get_sections':
            $stmt = $pdo->prepare("
                SELECT s.*, 
                       (SELECT COUNT(*) FROM `products` p WHERE p.company_id = s.company_id AND p.section_id = s.id) as item_count
                FROM `company_dynamic_sections` s
                WHERE s.company_id = ?
                ORDER BY s.display_order ASC, s.id ASC
            ");
            $stmt->execute([$companyId]);
            echo json_encode(['success' => true, 'sections' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'save_section':
            $secId       = (int)($data['id'] ?? 0);
            $name        = trim($data['name'] ?? '');
            $description = trim($data['description'] ?? '');
            $icon        = trim($data['icon'] ?? 'box');
            $visibility  = in_array($data['visibility'] ?? '', ['public', 'private']) ? $data['visibility'] : 'public';
            $isActive    = isset($data['is_active']) ? ((int)$data['is_active'] ? 1 : 0) : 1;
            $order       = (int)($data['display_order'] ?? 0);
            $catIdsJson  = is_array($data['linked_category_ids'] ?? null) ? json_encode($data['linked_category_ids']) : trim($data['linked_category_ids'] ?? '[]');
            $fieldsJson  = is_array($data['fields_config_json'] ?? null) ? json_encode($data['fields_config_json']) : trim($data['fields_config_json'] ?? '[]');

            if (empty($name)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Section name is required (e.g. Products, Courses, Treatments, Services).']);
                exit;
            }

            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));

            if ($secId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE `company_dynamic_sections`
                    SET `name` = ?, `slug` = ?, `description` = ?, `icon` = ?, `linked_category_ids` = ?,
                        `fields_config_json` = ?, `visibility` = ?, `is_active` = ?, `display_order` = ?
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([$name, $slug, $description, $icon, $catIdsJson, $fieldsJson, $visibility, $isActive, $order, $secId, $companyId]);
                echo json_encode(['success' => true, 'id' => $secId, 'message' => "Section '{$name}' updated successfully."]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO `company_dynamic_sections`
                    (`company_id`, `name`, `slug`, `description`, `icon`, `linked_category_ids`, `fields_config_json`, `visibility`, `is_active`, `display_order`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$companyId, $name, $slug, $description, $icon, $catIdsJson, $fieldsJson, $visibility, $isActive, $order]);
                echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => "Section '{$name}' created successfully."]);
            }
            break;

        case 'delete_section':
            $secId = (int)($data['id'] ?? 0);
            if (!$secId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing section ID.']);
                exit;
            }

            // Detach section_id from existing products non-destructively
            $pdo->prepare("UPDATE `products` SET `section_id` = NULL WHERE `section_id` = ? AND `company_id` = ?")->execute([$secId, $companyId]);
            $pdo->prepare("DELETE FROM `company_dynamic_sections` WHERE id = ? AND company_id = ?")->execute([$secId, $companyId]);
            echo json_encode(['success' => true, 'message' => 'Dynamic section removed. Existing offerings preserved.']);
            break;

        // -------------------------------------------------------------
        // 4. OFFERING CATALOGS MANAGEMENT
        // -------------------------------------------------------------
        case 'list_catalogs':
            $stmt = $pdo->prepare("
                SELECT c.*,
                       (SELECT COUNT(*) FROM `products` p WHERE p.company_id = c.company_id AND (
                           p.section_id IN (SELECT id FROM company_dynamic_sections WHERE company_id = c.company_id)
                           OR JSON_CONTAINS(c.offering_ids, CAST(p.id AS JSON))
                       )) as offering_count
                FROM `offering_catalogs` c
                WHERE c.company_id = ?
                ORDER BY c.display_order ASC, c.id ASC
            ");
            $stmt->execute([$companyId]);
            echo json_encode(['success' => true, 'catalogs' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'save_catalog':
            $catId       = (int)($data['id'] ?? 0);
            $name        = trim($data['name'] ?? '');
            $description = trim($data['description'] ?? '');
            $imageUrl    = trim($data['image_url'] ?? '');
            $isPub       = isset($data['is_published']) ? ((int)$data['is_published'] ? 1 : 0) : 1;
            $widgetVis   = isset($data['widget_visible']) ? ((int)$data['widget_visible'] ? 1 : 0) : 1;
            $order       = (int)($data['display_order'] ?? 0);
            $catIdsJson  = is_array($data['category_ids'] ?? null) ? json_encode($data['category_ids']) : trim($data['category_ids'] ?? '[]');
            $secIdsJson  = is_array($data['section_ids'] ?? null) ? json_encode($data['section_ids']) : trim($data['section_ids'] ?? '[]');
            $offIdsJson  = is_array($data['offering_ids'] ?? null) ? json_encode($data['offering_ids']) : trim($data['offering_ids'] ?? '[]');

            if (empty($name)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Catalog name is required.']);
                exit;
            }

            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));

            if ($catId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE `offering_catalogs`
                    SET `name` = ?, `slug` = ?, `description` = ?, `category_ids` = ?, `section_ids` = ?,
                        `offering_ids` = ?, `image_url` = ?, `is_published` = ?, `widget_visible` = ?, `display_order` = ?
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([$name, $slug, $description, $catIdsJson, $secIdsJson, $offIdsJson, $imageUrl, $isPub, $widgetVis, $order, $catId, $companyId]);
                echo json_encode(['success' => true, 'id' => $catId, 'message' => "Catalog '{$name}' updated successfully."]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO `offering_catalogs`
                    (`company_id`, `name`, `slug`, `description`, `category_ids`, `section_ids`, `offering_ids`, `image_url`, `is_published`, `widget_visible`, `display_order`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$companyId, $name, $slug, $description, $catIdsJson, $secIdsJson, $offIdsJson, $imageUrl, $isPub, $widgetVis, $order]);
                echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => "Catalog '{$name}' created successfully."]);
            }
            break;

        case 'toggle_catalog_published':
            $catId = (int)($data['id'] ?? 0);
            if (!$catId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing catalog ID.']);
                exit;
            }

            $pdo->prepare("UPDATE `offering_catalogs` SET `is_published` = NOT `is_published` WHERE id = ? AND company_id = ?")->execute([$catId, $companyId]);
            echo json_encode(['success' => true, 'message' => 'Catalog publishing status updated.']);
            break;

        case 'delete_catalog':
            $catId = (int)($data['id'] ?? 0);
            if (!$catId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing catalog ID.']);
                exit;
            }

            $pdo->prepare("DELETE FROM `offering_catalogs` WHERE id = ? AND company_id = ?")->execute([$catId, $companyId]);
            echo json_encode(['success' => true, 'message' => 'Catalog deleted successfully.']);
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

