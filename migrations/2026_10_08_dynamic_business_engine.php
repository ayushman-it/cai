<?php
/**
 * MIGRATION: Universal Dynamic Business Engine & Client-Controlled Conversation System
 * Non-destructive schema additions for:
 * 1. company_business_categories
 * 2. company_dynamic_sections
 * 3. offering_catalogs
 * 4. quick_chips updates (flexible action_type, links, status)
 * 5. products / company_assets / companies extensions
 */

require_once __DIR__ . '/../config/db.php';

$pdo = getDbConnection();

echo "Starting Dynamic Business Engine Migration...\n";

// 1. company_business_categories
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `company_business_categories` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `company_id` INT NOT NULL,
        `name` VARCHAR(150) NOT NULL,
        `slug` VARCHAR(150) NOT NULL,
        `description` TEXT NULL,
        `parent_id` INT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `display_order` INT NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_comp_cat` (`company_id`, `is_active`),
        INDEX `idx_parent` (`parent_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "[✓] Table company_business_categories ready.\n";

// 2. company_dynamic_sections
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `company_dynamic_sections` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `company_id` INT NOT NULL,
        `name` VARCHAR(100) NOT NULL,
        `slug` VARCHAR(100) NOT NULL,
        `description` TEXT NULL,
        `icon` VARCHAR(50) NOT NULL DEFAULT 'box',
        `linked_category_ids` TEXT NULL,
        `fields_config_json` LONGTEXT NULL,
        `visibility` ENUM('public', 'private') NOT NULL DEFAULT 'public',
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `display_order` INT NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_comp_sec` (`company_id`, `is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "[✓] Table company_dynamic_sections ready.\n";

// 3. offering_catalogs
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `offering_catalogs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `company_id` INT NOT NULL,
        `name` VARCHAR(150) NOT NULL,
        `slug` VARCHAR(150) NOT NULL,
        `description` TEXT NULL,
        `category_ids` TEXT NULL,
        `section_ids` TEXT NULL,
        `offering_ids` TEXT NULL,
        `image_url` VARCHAR(255) NULL,
        `is_published` TINYINT(1) NOT NULL DEFAULT 1,
        `widget_visible` TINYINT(1) NOT NULL DEFAULT 1,
        `display_order` INT NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_comp_pub` (`company_id`, `is_published`, `widget_visible`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "[✓] Table offering_catalogs ready.\n";

// Helper for column existence
function addColumnIfNotExists(PDO $pdo, string $table, string $column, string $colDef) {
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$colDef}");
        echo "  [+] Added {$column} to {$table}.\n";
    }
}

// 4. Extend quick_chips
echo "Updating quick_chips schema...\n";
// Change action_type to VARCHAR(64) so it accommodates universal actions
$pdo->exec("ALTER TABLE `quick_chips` MODIFY COLUMN `action_type` VARCHAR(64) NULL DEFAULT 'SEND_TEXT_RESPONSE'");
addColumnIfNotExists($pdo, 'quick_chips', 'linked_category_id', 'INT NULL');
addColumnIfNotExists($pdo, 'quick_chips', 'linked_catalog_id', 'INT NULL');
addColumnIfNotExists($pdo, 'quick_chips', 'action_payload_json', 'TEXT NULL');
addColumnIfNotExists($pdo, 'quick_chips', 'is_starter', 'TINYINT(1) NOT NULL DEFAULT 1');
addColumnIfNotExists($pdo, 'quick_chips', 'is_contextual', 'TINYINT(1) NOT NULL DEFAULT 0');
addColumnIfNotExists($pdo, 'quick_chips', 'status', "ENUM('published', 'draft') NOT NULL DEFAULT 'published'");
echo "[✓] Table quick_chips extended.\n";

// 5. Extend products
echo "Updating products schema...\n";
addColumnIfNotExists($pdo, 'products', 'section_id', 'INT NULL');
addColumnIfNotExists($pdo, 'products', 'custom_fields_json', 'LONGTEXT NULL');
echo "[✓] Table products extended.\n";

// 6. Extend company_assets
echo "Updating company_assets schema...\n";
addColumnIfNotExists($pdo, 'company_assets', 'linked_category_ids', 'TEXT NULL');
addColumnIfNotExists($pdo, 'company_assets', 'is_published', 'TINYINT(1) NOT NULL DEFAULT 1');
echo "[✓] Table company_assets extended.\n";

// 7. Extend companies
echo "Updating companies schema...\n";
addColumnIfNotExists($pdo, 'companies', 'business_description', 'TEXT NULL');
echo "[✓] Table companies extended.\n";

// 8. Non-Destructive Data Seeding for existing companies
echo "Checking existing companies for baseline business categories & catalogs...\n";
$companies = $pdo->query("SELECT id, name, industry, business_description FROM companies")->fetchAll(PDO::FETCH_ASSOC);

foreach ($companies as $comp) {
    $cId = (int)$comp['id'];
    $cName = $comp['name'];
    $cInd = $comp['industry'] ?: 'Technology & Professional Services';

    // Check if categories already exist
    $chkCat = $pdo->prepare("SELECT COUNT(*) FROM company_business_categories WHERE company_id = ?");
    $chkCat->execute([$cId]);
    if ((int)$chkCat->fetchColumn() === 0) {
        echo "  [*] Initializing baseline categories for company #{$cId} ({$cName})...\n";
        
        // Define relevant categories based on company profile or general IT/Services
        $categoriesToInsert = [];
        if (stripos($cInd, 'education') !== false || stripos($cName, 'munk') !== false) {
            $categoriesToInsert = [
                ['Full Stack Development', 'Career-oriented full stack engineering and architecture', 1],
                ['Data Science & AI', 'Real-world data science, machine learning, and AI logic', 2],
                ['System Architecture', 'Modern cloud, DevOps, and backend engineering', 3]
            ];
        } else {
            $categoriesToInsert = [
                ['Software Development', 'Custom web, mobile, and cloud software engineering', 1],
                ['AI & Automation Solutions', 'Conversational AI, automation agents, and RAG workflows', 2],
                ['Cloud & DevOps Services', 'Managed cloud infrastructure, scalability, and security', 3]
            ];
        }

        $insCat = $pdo->prepare("
            INSERT INTO `company_business_categories` (`company_id`, `name`, `slug`, `description`, `display_order`, `is_active`)
            VALUES (?, ?, ?, ?, ?, 1)
        ");

        $insertedCatIds = [];
        foreach ($categoriesToInsert as $c) {
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $c[0])));
            $insCat->execute([$cId, $c[0], $slug, $c[1], $c[2]]);
            $insertedCatIds[] = (int)$pdo->lastInsertId();
        }

        // Create initial dynamic section
        $secName = (stripos($cInd, 'education') !== false) ? 'Courses' : 'Services';
        $secSlug = strtolower($secName);
        $insSec = $pdo->prepare("
            INSERT INTO `company_dynamic_sections` (`company_id`, `name`, `slug`, `description`, `icon`, `linked_category_ids`, `visibility`, `is_active`, `display_order`)
            VALUES (?, ?, ?, ?, 'layers', ?, 'public', 1, 1)
        ");
        $insSec->execute([$cId, $secName, $secSlug, "Primary {$secName} portfolio", json_encode($insertedCatIds)]);
        $secId = (int)$pdo->lastInsertId();

        // Update existing products with section_id
        $pdo->prepare("UPDATE `products` SET `section_id` = ? WHERE `company_id` = ? AND (`section_id` IS NULL OR `section_id` = 0)")
            ->execute([$secId, $cId]);

        // Create baseline catalog
        $catTitle = "{$cName} Solutions Catalog";
        $catSlug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $catTitle)));
        $insCatalog = $pdo->prepare("
            INSERT INTO `offering_catalogs` (`company_id`, `name`, `slug`, `description`, `category_ids`, `section_ids`, `is_published`, `widget_visible`, `display_order`)
            VALUES (?, ?, ?, ?, ?, ?, 1, 1, 1)
        ");
        $insCatalog->execute([$cId, $catTitle, $catSlug, "Comprehensive verified offerings and services offered by {$cName}.", json_encode($insertedCatIds), json_encode([$secId])]);
        $catalogId = (int)$pdo->lastInsertId();

        // Check if starter chips exist in quick_chips
        $chkChips = $pdo->prepare("SELECT COUNT(*) FROM `quick_chips` WHERE `company_id` = ?");
        $chkChips->execute([$cId]);
        if ((int)$chkChips->fetchColumn() === 0) {
            $insChip = $pdo->prepare("
                INSERT INTO `quick_chips` (`company_id`, `label`, `action_type`, `linked_catalog_id`, `display_order`, `is_active`, `is_starter`, `status`, `response_text`)
                VALUES (?, ?, ?, ?, ?, 1, 1, 'published', ?)
            ");
            $insChip->execute([$cId, 'Explore Solutions', 'OPEN_CATALOG_SELECTOR', null, 1, 'Explore our published solutions and service catalogs.']);
            $insChip->execute([$cId, 'Browse Documents', 'OPEN_BROCHURE_SELECTOR', null, 2, 'Browse official brochures and company documentation.']);
            $insChip->execute([$cId, 'Connect with Team', 'START_HUMAN_HANDOFF', null, 3, 'Connect directly with our team for personalized assistance.']);
        }
    }
}

echo "\n=======================================================\n";
echo " MIGRATION COMPLETED SUCCESSFULLY!\n";
echo "=======================================================\n";
