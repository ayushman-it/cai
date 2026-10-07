<?php
/**
 * CUBOIDPILOT — DATABASE CONFIGURATION & CONNECTION (PDO)
 * Automatically connects to MySQL and ensures tables and demo users exist.
 */

// Load environment variables from .env if present
(function() {
    $envFile = dirname(__DIR__) . '/.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (strpos($line, '=') !== false) {
                list($key, $val) = explode('=', $line, 2);
                $key = trim($key);
                $val = trim($val);
                // Strip quotes if wrapped
                if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                    (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                    $val = substr($val, 1, -1);
                }
                if (!array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
                    putenv("{$key}={$val}");
                    $_ENV[$key] = $val;
                    $_SERVER[$key] = $val;
                }
            }
        }
    }
})();

// Detect environment (Local XAMPP vs Hostinger Production)
$httpHost = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = in_array(strtolower(explode(':', $httpHost)[0]), ['localhost', '127.0.0.1'])
    || (php_sapi_name() === 'cli' && strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');

$envUser = getenv('DB_USER');
$envPass = getenv('DB_PASS');
$envName = getenv('DB_NAME');
$envHost = getenv('DB_HOST');
$envPort = getenv('DB_PORT');

// If running in production (Hostinger/Live Domain) or if DB_USER is still 'root' on server
if (!$isLocal || (!empty($httpHost) && !in_array(strtolower(explode(':', $httpHost)[0]), ['localhost', '127.0.0.1']))) {
    define('DB_HOST', (!empty($envHost) && $envHost !== '127.0.0.1') ? $envHost : 'localhost');
    define('DB_PORT', $envPort ?: '3306');
    define('DB_USER', (!empty($envUser) && $envUser !== 'root') ? $envUser : 'u325640649_cai');
    define('DB_PASS', (!empty($envPass)) ? $envPass : 'AyushmanIndia@2026');
    define('DB_NAME', (!empty($envName) && $envName !== 'cuboidpolit_db') ? $envName : 'u325640649_cai');
} else {
    define('DB_HOST', $envHost ?: '127.0.0.1');
    define('DB_PORT', $envPort ?: '3306');
    define('DB_USER', $envUser ?: 'root');
    define('DB_PASS', $envPass !== false ? $envPass : '');
    define('DB_NAME', $envName ?: 'cuboidpolit_db');
}

// Groq AI Engine Key (Configure in .env)
define('GROQ_API_KEY', getenv('GROQ_API_KEY') ?: '');

// Google Gemini AI Engine Key & Model (Primary AI Engine)
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');
define('GEMINI_MODEL', getenv('GEMINI_MODEL') ?: 'gemini-3.8-flash');

if (!defined('ENCRYPTION_KEY')) {
    define('ENCRYPTION_KEY', getenv('CP_ENCRYPTION_KEY') ?: '');
}

/**
 * Symmetric AES-256-CBC Encryption & Decryption for multi-tenant sensitive secrets
 */
function encryptSecret($plainText) {
    if ($plainText === null || $plainText === '') return '';
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($plainText, 'aes-256-cbc', hash('sha256', ENCRYPTION_KEY, true), 0, $iv);
    return base64_encode($iv . '::' . $encrypted);
}

function decryptSecret($cipherText) {
    if (empty($cipherText)) return '';
    $decoded = base64_decode($cipherText);
    if (!$decoded || strpos($decoded, '::') === false) {
        return $cipherText;
    }
    $parts = explode('::', $decoded, 2);
    if (count($parts) < 2) return $cipherText;
    list($iv, $encrypted) = $parts;
    $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', hash('sha256', ENCRYPTION_KEY, true), 0, $iv);
    return $decrypted !== false ? $decrypted : '';
}

/**
 * Get PDO Database Connection
 * @return PDO
 */
function getDbConnection() {
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        // If database doesn't exist, try connecting to MySQL server to create it
        if ($e->getCode() == 1049 || strpos($e->getMessage(), 'Unknown database') !== false) {
            try {
                $rootDsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
                $rootPdo = new PDO($rootDsn, DB_USER, DB_PASS, $options);
                $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (PDOException $ex) {
                die("Database connection failed: " . htmlspecialchars($ex->getMessage()));
            }
        } else {
            die("Database connection failed: " . htmlspecialchars($e->getMessage()));
        }
    }

    // Ensure schema and demo users exist (cached check to avoid running heavy DDL on every single request)
    static $schemaChecked = false;
    if (!$schemaChecked) {
        $localLock = __DIR__ . '/.schema_installed_v19';
        $tempLock  = sys_get_temp_dir() . '/cuboid_schema_v19.lock';
        if (!file_exists($localLock) || !file_exists($tempLock)) {
            initDbSchemaAndUsers($pdo);
            ensureExtendedSchema($pdo);
            @file_put_contents($localLock, time());
            @file_put_contents($tempLock, time());
        }
        $schemaChecked = true;
    }

    return $pdo;
}

/**
 * Ensures all extended tables and multi-tenant columns exist.
 */
function ensureExtendedSchema(PDO $pdo) {
    // 1. Companies columns for 14-day trial & plan tier
    $companyCols = $pdo->query("SHOW COLUMNS FROM `companies`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('trial_started_at', $companyCols)) {
        $pdo->exec("ALTER TABLE `companies` ADD COLUMN `trial_started_at` DATETIME NULL AFTER `whatsapp_connected`");
    }
    if (!in_array('trial_ends_at', $companyCols)) {
        $pdo->exec("ALTER TABLE `companies` ADD COLUMN `trial_ends_at` DATETIME NULL AFTER `trial_started_at`");
    }
    if (!in_array('plan_tier', $companyCols)) {
        $pdo->exec("ALTER TABLE `companies` ADD COLUMN `plan_tier` VARCHAR(50) DEFAULT 'free_trial' AFTER `trial_ends_at`");
    }
    // Update null trial dates based on created_at (14 days)
    $pdo->exec("
        UPDATE `companies` 
        SET `trial_started_at` = COALESCE(`trial_started_at`, `created_at`, NOW()),
            `trial_ends_at` = COALESCE(`trial_ends_at`, DATE_ADD(COALESCE(`created_at`, NOW()), INTERVAL 14 DAY))
        WHERE `trial_ends_at` IS NULL
    ");

    // 2. Installments / EMI schedule table (Section 34, 37, 38)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `installments` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT NOT NULL,
            `lead_id` INT NULL,
            `customer_id` INT NOT NULL,
            `payment_id` INT NULL,
            `installment_number` INT NOT NULL DEFAULT 1,
            `title` VARCHAR(150) NOT NULL,
            `amount_inr` INT NOT NULL,
            `due_date` DATE NOT NULL,
            `status` ENUM('upcoming', 'paid', 'overdue', 'cancelled') NOT NULL DEFAULT 'upcoming',
            `paid_at` DATETIME NULL,
            `reminder_days_before` INT NOT NULL DEFAULT 4,
            `reminder_sent_at` DATETIME NULL,
            `reminder_status` ENUM('none', 'scheduled', 'sent', 'delayed', 'acknowledged') NOT NULL DEFAULT 'none',
            `notes` TEXT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_company` (`company_id`),
            KEY `idx_customer` (`customer_id`),
            KEY `idx_lead` (`lead_id`),
            KEY `idx_due_date` (`due_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 3. Website -> WhatsApp handoffs table (Section 24)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `whatsapp_handoffs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT NOT NULL,
            `handoff_token` VARCHAR(64) NOT NULL UNIQUE,
            `customer_id` INT NOT NULL,
            `lead_id` INT NULL,
            `web_conversation_id` INT NOT NULL,
            `phone` VARCHAR(30) NULL,
            `status` ENUM('pending', 'claimed', 'expired') NOT NULL DEFAULT 'pending',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `claimed_at` DATETIME NULL,
            `expires_at` DATETIME NOT NULL,
            KEY `idx_token` (`handoff_token`),
            KEY `idx_company` (`company_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 4. Alert logs for notification deduplication & cooldowns (Section 33)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `alert_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT NOT NULL,
            `lead_id` INT NULL,
            `alert_type` VARCHAR(100) NOT NULL,
            `recipient` VARCHAR(100) NOT NULL,
            `recipient_type` ENUM('sales_agent', 'admin', 'customer') NOT NULL DEFAULT 'sales_agent',
            `channel` VARCHAR(30) NOT NULL DEFAULT 'whatsapp',
            `content_preview` VARCHAR(255) NULL,
            `sent_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_comp_lead_type` (`company_id`, `lead_id`, `alert_type`),
            KEY `idx_sent_at` (`sent_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 5. Leads table columns for structured classification & commercial data
    $leadCols = $pdo->query("SHOW COLUMNS FROM `leads`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('stage_name', $leadCols)) {
        $pdo->exec("ALTER TABLE `leads` ADD COLUMN `stage_name` VARCHAR(50) DEFAULT 'NEW' AFTER `stage_id`");
    }
    if (!in_array('priority', $leadCols)) {
        $pdo->exec("ALTER TABLE `leads` ADD COLUMN `priority` ENUM('LOW', 'MEDIUM', 'HIGH', 'URGENT') DEFAULT 'LOW' AFTER `intent_level`");
    }
    if (!in_array('ai_summary', $leadCols)) {
        $pdo->exec("ALTER TABLE `leads` ADD COLUMN `ai_summary` TEXT NULL AFTER `radar_reason`");
    }
    if (!in_array('total_amount', $leadCols)) {
        $pdo->exec("ALTER TABLE `leads` ADD COLUMN `total_amount` INT DEFAULT 0 AFTER `opportunity_value`");
    }
    if (!in_array('payment_type', $leadCols)) {
        $pdo->exec("ALTER TABLE `leads` ADD COLUMN `payment_type` ENUM('ONE_TIME', 'INSTALLMENT') DEFAULT 'ONE_TIME' AFTER `total_amount`");
    }
    if (!in_array('paid_amount', $leadCols)) {
        $pdo->exec("ALTER TABLE `leads` ADD COLUMN `paid_amount` INT DEFAULT 0 AFTER `payment_type`");
    }
    if (!in_array('remaining_amount', $leadCols)) {
        $pdo->exec("ALTER TABLE `leads` ADD COLUMN `remaining_amount` INT DEFAULT 0 AFTER `paid_amount`");
    }
    if (!in_array('next_due_date', $leadCols)) {
        $pdo->exec("ALTER TABLE `leads` ADD COLUMN `next_due_date` DATE NULL AFTER `remaining_amount`");
    }
    if (!in_array('commercial_status', $leadCols)) {
        $pdo->exec("ALTER TABLE `leads` ADD COLUMN `commercial_status` ENUM('PENDING', 'PARTIAL', 'PAID', 'FAILED', 'OVERDUE') DEFAULT 'PENDING' AFTER `next_due_date`");
    }

    // 6. Ensure theme_mode, logo_dark_url, logo_light_url columns in widget_settings
    $widgetCols = $pdo->query("SHOW COLUMNS FROM `widget_settings`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('theme_mode', $widgetCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `theme_mode` VARCHAR(20) DEFAULT 'dark' AFTER `accent_color`");
    }
    if (!in_array('logo_dark_url', $widgetCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `logo_dark_url` VARCHAR(255) NULL AFTER `logo_url`");
    }
    if (!in_array('logo_light_url', $widgetCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `logo_light_url` VARCHAR(255) NULL AFTER `logo_dark_url`");
    }

    // Ensure logo_dark_url, logo_light_url columns in companies
    $compCols = $pdo->query("SHOW COLUMNS FROM `companies`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('logo_dark_url', $compCols)) {
        $pdo->exec("ALTER TABLE `companies` ADD COLUMN `logo_dark_url` VARCHAR(255) NULL AFTER `logo_url`");
    }
    if (!in_array('logo_light_url', $compCols)) {
        $pdo->exec("ALTER TABLE `companies` ADD COLUMN `logo_light_url` VARCHAR(255) NULL AFTER `logo_dark_url`");
    }

    // 7. Knowledge sources columns (category, source_url)
    $kbCols = $pdo->query("SHOW COLUMNS FROM `knowledge_sources`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('category', $kbCols)) {
        $pdo->exec("ALTER TABLE `knowledge_sources` ADD COLUMN `category` VARCHAR(100) DEFAULT 'General' AFTER `title`");
    }
    if (!in_array('source_url', $kbCols)) {
        $pdo->exec("ALTER TABLE `knowledge_sources` ADD COLUMN `source_url` VARCHAR(255) NULL AFTER `type`");
    }

    // 8. Align installments status and reminder_status to be forgiving of both upcoming/pending
    try {
        $pdo->exec("ALTER TABLE `installments` MODIFY COLUMN `status` ENUM('upcoming', 'pending', 'paid', 'overdue', 'cancelled') NOT NULL DEFAULT 'upcoming'");
        $pdo->exec("ALTER TABLE `installments` MODIFY COLUMN `reminder_status` ENUM('none', 'scheduled', 'sent', 'delayed', 'acknowledged') NOT NULL DEFAULT 'none'");
    } catch (Exception $e) {}

    // 9. Lead Attention Columns in leads table
    $leadCols = $pdo->query("SHOW COLUMNS FROM `leads`")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('is_radar_active', $leadCols)) {
        try {
            $pdo->exec("ALTER TABLE `leads` MODIFY COLUMN `is_radar_active` TINYINT(1) NOT NULL DEFAULT 0");
        } catch (Exception $e) {}
    }
    if (!in_array('human_attention_required', $leadCols)) {
        $pdo->exec("ALTER TABLE `leads` ADD COLUMN `human_attention_required` TINYINT(1) DEFAULT 0 AFTER `is_radar_active`");
    }
    if (!in_array('human_attention_reason', $leadCols)) {
        $pdo->exec("ALTER TABLE `leads` ADD COLUMN `human_attention_reason` TEXT NULL AFTER `human_attention_required`");
    }
    if (!in_array('human_attention_at', $leadCols)) {
        $pdo->exec("ALTER TABLE `leads` ADD COLUMN `human_attention_at` DATETIME NULL AFTER `human_attention_reason`");
    }

    // 10. AI Lead Artifacts Table (Section 7)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `lead_artifacts` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT NOT NULL,
            `lead_id` INT NOT NULL,
            `customer_id` INT NOT NULL,
            `conversation_id` INT NULL,
            `customer_name` VARCHAR(150) NULL,
            `phone` VARCHAR(30) NULL,
            `email` VARCHAR(150) NULL,
            `company_name` VARCHAR(150) NULL,
            `requirement` TEXT NULL,
            `interested_service` VARCHAR(200) NULL,
            `budget` VARCHAR(100) NULL,
            `timeline` VARCHAR(100) NULL,
            `location` VARCHAR(100) NULL,
            `intent` VARCHAR(50) DEFAULT 'general',
            `current_stage` VARCHAR(50) DEFAULT 'NEW',
            `buying_signals_json` LONGTEXT NULL,
            `objections_json` LONGTEXT NULL,
            `missing_information_json` LONGTEXT NULL,
            `conversation_summary` TEXT NULL,
            `priority` VARCHAR(20) DEFAULT 'LOW',
            `lead_score` INT DEFAULT 0,
            `priority_reason` TEXT NULL,
            `recommended_action` TEXT NULL,
            `assigned_salesperson_id` INT NULL,
            `preferred_channel` VARCHAR(30) DEFAULT 'website',
            `ai_confidence` VARCHAR(20) DEFAULT 'HIGH',
            `human_attention_status` VARCHAR(50) DEFAULT 'none',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_company` (`company_id`),
            KEY `idx_lead` (`lead_id`),
            KEY `idx_customer` (`customer_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 11. Company-Specific Configurable Lead Scoring Rules (Section 8)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `lead_scoring_rules` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT NOT NULL,
            `signal_type` VARCHAR(50) NOT NULL,
            `condition_value` VARCHAR(100) NULL,
            `score_delta` INT NOT NULL DEFAULT 10,
            `description` VARCHAR(255) NOT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_company_signal` (`company_id`, `signal_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 12. Company AI Brain Configuration & Custom Instructions (Sections 2 & 3)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ai_configs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT NOT NULL UNIQUE,
            `ai_name` VARCHAR(100) DEFAULT 'Cai',
            `tone` ENUM('professional', 'friendly', 'casual', 'custom') DEFAULT 'professional',
            `primary_objective` ENUM('generate_leads', 'book_appointments', 'sales', 'customer_support', 'qualification', 'custom') DEFAULT 'generate_leads',
            `info_to_collect_json` LONGTEXT NULL,
            `custom_instructions` TEXT NULL,
            `qualification_rules_json` LONGTEXT NULL,
            `appointment_instructions` TEXT NULL,
            `payment_instructions` TEXT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_company` (`company_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 13. Appointments Table (Section 22)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `appointments` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT NOT NULL,
            `customer_id` INT NOT NULL,
            `lead_id` INT NULL,
            `title` VARCHAR(200) NOT NULL,
            `appointment_type` VARCHAR(100) DEFAULT 'consultation',
            `slot_datetime` DATETIME NOT NULL,
            `status` ENUM('scheduled', 'completed', 'cancelled', 'rescheduled') DEFAULT 'scheduled',
            `notes` TEXT NULL,
            `meet_link` VARCHAR(255) NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_company` (`company_id`),
            KEY `idx_customer` (`customer_id`),
            KEY `idx_lead` (`lead_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 14. Company Custom Fields (Section 29)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `company_custom_fields` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT NOT NULL,
            `field_key` VARCHAR(64) NOT NULL,
            `field_label` VARCHAR(100) NOT NULL,
            `field_type` ENUM('text', 'number', 'select', 'date') DEFAULT 'text',
            `options_json` TEXT NULL,
            `is_required` TINYINT(1) DEFAULT 0,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `idx_comp_key` (`company_id`, `field_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `lead_custom_field_values` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT NOT NULL,
            `lead_id` INT NOT NULL,
            `field_id` INT NOT NULL,
            `field_value` TEXT NULL,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `idx_lead_field` (`lead_id`, `field_id`),
            KEY `idx_company` (`company_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 15. WhatsApp Meta Cloud API credentials in whatsapp_accounts
    $waCols = $pdo->query("SHOW COLUMNS FROM `whatsapp_accounts`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('whatsapp_access_token', $waCols)) {
        $pdo->exec("ALTER TABLE `whatsapp_accounts` ADD COLUMN `whatsapp_access_token` TEXT NULL AFTER `display_number`");
    }
    if (!in_array('app_secret', $waCols)) {
        $pdo->exec("ALTER TABLE `whatsapp_accounts` ADD COLUMN `app_secret` VARCHAR(255) NULL AFTER `whatsapp_access_token`");
    }
    if (!in_array('webhook_verify_token', $waCols)) {
        $pdo->exec("ALTER TABLE `whatsapp_accounts` ADD COLUMN `webhook_verify_token` VARCHAR(100) NULL AFTER `app_secret`");
    }

    // 16. Google Calendar & Extended scheduling in widget_settings
    $wsCols = $pdo->query("SHOW COLUMNS FROM `widget_settings`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('google_calendar_id', $wsCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `google_calendar_id` VARCHAR(255) NULL AFTER `calendar_sync_enabled`");
    }
    if (!in_array('google_client_id', $wsCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `google_client_id` VARCHAR(255) NULL AFTER `google_calendar_id`");
    }
    if (!in_array('google_client_secret', $wsCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `google_client_secret` VARCHAR(255) NULL AFTER `google_client_id`");
    }
    if (!in_array('google_refresh_token', $wsCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `google_refresh_token` TEXT NULL AFTER `google_client_secret`");
    }
    if (!in_array('google_access_token', $wsCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `google_access_token` TEXT NULL AFTER `google_refresh_token`");
    }
    if (!in_array('google_token_expires_at', $wsCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `google_token_expires_at` DATETIME NULL AFTER `google_access_token`");
    }

    // 17. Google event ID in appointments
    $appCols = $pdo->query("SHOW COLUMNS FROM `appointments`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('google_event_id', $appCols)) {
        $pdo->exec("ALTER TABLE `appointments` ADD COLUMN `google_event_id` VARCHAR(255) NULL AFTER `meet_link`");
    }

    // Seed default custom fields for existing companies if empty
    $compIds = $pdo->query("SELECT id FROM `companies`")->fetchAll(PDO::FETCH_COLUMN);
    $insField = $pdo->prepare("
        INSERT IGNORE INTO `company_custom_fields` (`company_id`, `field_key`, `field_label`, `field_type`, `options_json`, `is_required`)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    foreach ($compIds as $cId) {
        $insField->execute([$cId, 'requirement_type', 'Project / Course Requirement', 'text', null, 1]);
        $insField->execute([$cId, 'budget_range', 'Budget Range', 'select', json_encode(['₹25,000 - ₹50,000', '₹50,000 - ₹1,00,000', '₹1,00,000+']), 0]);
        $insField->execute([$cId, 'target_timeline', 'Target Timeline', 'select', json_encode(['Immediate (1-2 weeks)', 'Within 30 Days', '1-3 Months']), 0]);
    }

    // 15. Ensure official CuboidSoft company exists for the marketing website
    $chkCuboid = $pdo->query("SELECT id FROM `companies` WHERE `slug` = 'cuboidsoft' OR `company_key` = 'cp_live_cuboidsoft' LIMIT 1")->fetch();
    if (!$chkCuboid) {
        provisionTenantWorkspace($pdo, [
            'name'                  => 'CuboidSoft',
            'slug'                  => 'cuboidsoft',
            'company_key'           => 'cp_live_cuboidsoft',
            'industry'              => 'AI & Software Solutions',
            'city'                  => 'Bengaluru',
            'country'               => 'India',
            'status'                => 'active',
            'plan_tier'             => 'growth',
            'onboarding_completed'  => 1,
            'owner_name'            => 'Ayush (Founder)',
            'owner_email'           => 'founder@cuboidsoft.in',
            'owner_password'        => 'password123',
            'widget_brand_name'     => 'Cai',
            'widget_assistant_name' => 'Cai',
            'widget_greeting'       => "Hi there 👋 Welcome to CuboidSoft!\n\nI am Cai, your AI assistant. How can I help you today?",
            'widget_subheading'     => "Instant AI answers • Powered by Cai",
            'widget_accent_color'   => '#111111',
            'whatsapp_number'       => '+91 98201 12345',
            'knowledge_sources'     => [
                [
                    'type'    => 'text_doc',
                    'title'   => 'About CuboidSoft & Cai Conversational Platform',
                    'content' => "CuboidSoft is an enterprise-grade AI conversation and lead conversion platform powered by Cai. It turns website visitors into qualified leads, answers questions instantly using your verified business knowledge, qualifies buyer intent, captures contact details, enables seamless 1-click continuation on WhatsApp, and alerts sales closers on high-intent opportunities."
                ],
                [
                    'type'    => 'faq',
                    'title'   => 'Subscription Plans & Pricing',
                    'content' => "CuboidSoft offers 3 pricing tiers with an initial 14-day free trial (no credit card required):
1. Starter Plan: ₹4,999/month (up to 500 conversations, AI Agent Cai, basic CRM pipeline).
2. Growth Plan: ₹12,999/month (WhatsApp continuity, Salesperson WhatsApp alerts, lead assignment, 1,500 conversations).
3. Scale Plan: ₹29,999/month (Automated EMI & payment reminders, custom AI training, unlimited seats, dedicated support).
Annual billing receives a 20% discount or 3-month zero-interest EMI financing."
                ],
                [
                    'type'    => 'faq',
                    'title'   => 'WhatsApp Continuity & Instant Sales Alerts',
                    'content' => "When a website visitor has a high-intent conversation or prefers chatting on phone, Cai provides a 1-click 'Continue on WhatsApp' card with a unique reference token. The visitor never needs to repeat their questions. Meanwhile, sales reps receive instant WhatsApp alerts containing deal value, visitor questions, and recommended next action, guarded by a 60-minute anti-spam cooldown."
                ],
                [
                    'type'    => 'faq',
                    'title'   => 'CRM, Installments & EMI Reminders',
                    'content' => "CuboidSoft includes a built-in CRM that automatically categorizes leads by intent (Low, Medium, High, Urgent) and deal stage. It also supports flexible 3-month and 6-month installment schedules with automated 4-day lookahead payment reminders sent via WhatsApp."
                ],
                [
                    'type'    => 'faq',
                    'title'   => 'Installation & 5-Minute Setup',
                    'content' => "Installing Cai requires copying a single script tag: <script src=\"https://cai.cuboidsoft.in/widget.js\" data-company=\"cp_live_cuboidsoft\" async></script> and pasting it right before the </body> tag of your website. It works seamlessly on WordPress, Shopify, Webflow, custom HTML, and React."
                ]
            ]
        ]);
    }

    // 16. Dynamic Widget Actions & Payments Configuration in widget_settings
    $widgetCols = $pdo->query("SHOW COLUMNS FROM `widget_settings`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('enable_appointments', $widgetCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `enable_appointments` TINYINT(1) DEFAULT 1 AFTER `enable_whatsapp_continue`");
    }
    if (!in_array('enable_human_help', $widgetCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `enable_human_help` TINYINT(1) DEFAULT 1 AFTER `enable_appointments`");
    }
    if (!in_array('enable_payments', $widgetCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `enable_payments` TINYINT(1) DEFAULT 1 AFTER `enable_human_help`");
    }
    if (!in_array('razorpay_key_id', $widgetCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `razorpay_key_id` VARCHAR(100) NULL AFTER `enable_payments`");
    }
    if (!in_array('bank_name', $widgetCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `bank_name` VARCHAR(100) NULL DEFAULT NULL AFTER `razorpay_key_id`");
    }
    if (!in_array('bank_account_no', $widgetCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `bank_account_no` VARCHAR(50) NULL DEFAULT NULL AFTER `bank_name`");
    }
    if (!in_array('bank_ifsc', $widgetCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `bank_ifsc` VARCHAR(20) NULL DEFAULT NULL AFTER `bank_account_no`");
    }
    if (!in_array('bank_upi_id', $widgetCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `bank_upi_id` VARCHAR(50) NULL DEFAULT NULL AFTER `bank_ifsc`");
    }
    if (!in_array('razorpay_key_secret', $widgetCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `razorpay_key_secret` VARCHAR(100) NULL AFTER `razorpay_key_id`");
    }
    if (!in_array('bank_account_holder', $widgetCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `bank_account_holder` VARCHAR(100) NULL DEFAULT NULL AFTER `bank_name`");
    }
    if (!in_array('bank_qr_url', $widgetCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `bank_qr_url` VARCHAR(255) NULL AFTER `bank_upi_id`");
    }
    if (!in_array('calendar_working_days', $widgetCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `calendar_working_days` VARCHAR(50) DEFAULT '1,2,3,4,5,6' AFTER `enable_appointments`");
    }
    if (!in_array('calendar_start_time', $widgetCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `calendar_start_time` VARCHAR(10) DEFAULT '10:00' AFTER `calendar_working_days`");
    }
    if (!in_array('calendar_end_time', $widgetCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `calendar_end_time` VARCHAR(10) DEFAULT '18:00' AFTER `calendar_start_time`");
    }
    if (!in_array('calendar_slot_duration', $widgetCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `calendar_slot_duration` INT DEFAULT 30 AFTER `calendar_end_time`");
    }
    if (!in_array('calendar_buffer_minutes', $widgetCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `calendar_buffer_minutes` INT DEFAULT 0 AFTER `calendar_slot_duration`");
    }
    if (!in_array('calendar_advance_days', $widgetCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `calendar_advance_days` INT DEFAULT 7 AFTER `calendar_buffer_minutes`");
    }
    if (!in_array('calendar_meet_url', $widgetCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `calendar_meet_url` VARCHAR(255) DEFAULT 'https://meet.google.com/cp-consult' AFTER `calendar_advance_days`");
    }
    if (!in_array('calendar_provider', $widgetCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `calendar_provider` VARCHAR(50) DEFAULT 'native' AFTER `calendar_meet_url`");
    }
    if (!in_array('calendar_api_key', $widgetCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `calendar_api_key` VARCHAR(255) NULL AFTER `calendar_provider`");
    }
    if (!in_array('calendar_booking_url', $widgetCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `calendar_booking_url` VARCHAR(255) NULL AFTER `calendar_api_key`");
    }
    if (!in_array('calendar_webhook_url', $widgetCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `calendar_webhook_url` VARCHAR(255) NULL AFTER `calendar_booking_url`");
    }
    if (!in_array('calendar_sync_enabled', $widgetCols)) {
        $pdo->exec("ALTER TABLE `widget_settings` ADD COLUMN `calendar_sync_enabled` TINYINT(1) DEFAULT 1 AFTER `calendar_webhook_url`");
    }

    // 17. Team Member Availability & Job Titles in users table
    $userCols = $pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('job_title', $userCols)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `job_title` VARCHAR(100) DEFAULT 'Consultant' AFTER `role`");
    }
    if (!in_array('department', $userCols)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `department` ENUM('sales', 'support', 'technical', 'general') DEFAULT 'sales' AFTER `job_title`");
    }
    if (!in_array('availability_status', $userCols)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `availability_status` ENUM('AVAILABLE', 'BUSY', 'OFFLINE', 'APPOINTMENT_ONLY') DEFAULT 'AVAILABLE' AFTER `department`");
    }
    if (!in_array('is_instant_help_enabled', $userCols)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `is_instant_help_enabled` TINYINT(1) DEFAULT 1 AFTER `availability_status`");
    }
    if (!in_array('is_appointment_enabled', $userCols)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `is_appointment_enabled` TINYINT(1) DEFAULT 1 AFTER `is_instant_help_enabled`");
    }

    // 18. Assigned User in appointments table
    $apptCols = $pdo->query("SHOW COLUMNS FROM `appointments`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('assigned_user_id', $apptCols)) {
        $pdo->exec("ALTER TABLE `appointments` ADD COLUMN `assigned_user_id` INT NULL AFTER `lead_id`");
    }

    // Ensure products table has all modern commerce & lifecycle columns
    try {
        $pCols = $pdo->query("SHOW COLUMNS FROM `products`")->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($pCols)) {
            if (!in_array('category', $pCols)) {
                $pdo->exec("ALTER TABLE `products` ADD COLUMN `category` VARCHAR(50) NOT NULL DEFAULT 'service'");
            }
            if (!in_array('subcategory', $pCols)) {
                $pdo->exec("ALTER TABLE `products` ADD COLUMN `subcategory` VARCHAR(100) DEFAULT NULL");
            }
            if (!in_array('sku', $pCols)) {
                $pdo->exec("ALTER TABLE `products` ADD COLUMN `sku` VARCHAR(100) DEFAULT NULL");
            }
            if (!in_array('duration', $pCols)) {
                $pdo->exec("ALTER TABLE `products` ADD COLUMN `duration` VARCHAR(100) DEFAULT NULL");
            }
            if (!in_array('original_price_inr', $pCols)) {
                $pdo->exec("ALTER TABLE `products` ADD COLUMN `original_price_inr` INT(11) NOT NULL DEFAULT 0");
            }
            if (!in_array('discount_percent', $pCols)) {
                $pdo->exec("ALTER TABLE `products` ADD COLUMN `discount_percent` INT(11) NOT NULL DEFAULT 0");
            }
            if (!in_array('currency', $pCols)) {
                $pdo->exec("ALTER TABLE `products` ADD COLUMN `currency` VARCHAR(10) NOT NULL DEFAULT 'INR'");
            }
            if (!in_array('features_json', $pCols)) {
                $pdo->exec("ALTER TABLE `products` ADD COLUMN `features_json` LONGTEXT DEFAULT NULL");
            }
            if (!in_array('deliverables_json', $pCols)) {
                $pdo->exec("ALTER TABLE `products` ADD COLUMN `deliverables_json` LONGTEXT DEFAULT NULL");
            }
            if (!in_array('prerequisites', $pCols)) {
                $pdo->exec("ALTER TABLE `products` ADD COLUMN `prerequisites` TEXT DEFAULT NULL");
            }
            if (!in_array('target_audience', $pCols)) {
                $pdo->exec("ALTER TABLE `products` ADD COLUMN `target_audience` VARCHAR(255) DEFAULT NULL");
            }
            if (!in_array('emi_available', $pCols)) {
                $pdo->exec("ALTER TABLE `products` ADD COLUMN `emi_available` TINYINT(1) DEFAULT 0");
            }
            if (!in_array('emi_starting_at_inr', $pCols)) {
                $pdo->exec("ALTER TABLE `products` ADD COLUMN `emi_starting_at_inr` INT(11) DEFAULT 0");
            }
            if (!in_array('emi_plans_json', $pCols)) {
                $pdo->exec("ALTER TABLE `products` ADD COLUMN `emi_plans_json` LONGTEXT DEFAULT NULL");
            }
            if (!in_array('max_discount_allowed_percent', $pCols)) {
                $pdo->exec("ALTER TABLE `products` ADD COLUMN `max_discount_allowed_percent` INT(11) NOT NULL DEFAULT 10");
            }
            if (!in_array('brochure_asset_id', $pCols)) {
                $pdo->exec("ALTER TABLE `products` ADD COLUMN `brochure_asset_id` INT(11) DEFAULT NULL");
            }
            if (!in_array('thumbnail_url', $pCols)) {
                $pdo->exec("ALTER TABLE `products` ADD COLUMN `thumbnail_url` VARCHAR(255) DEFAULT NULL");
            }
            if (!in_array('payment_url', $pCols)) {
                $pdo->exec("ALTER TABLE `products` ADD COLUMN `payment_url` VARCHAR(255) DEFAULT NULL");
            }
            if (!in_array('faq_json', $pCols)) {
                $pdo->exec("ALTER TABLE `products` ADD COLUMN `faq_json` LONGTEXT DEFAULT NULL");
            }
            if (!in_array('is_active', $pCols)) {
                $pdo->exec("ALTER TABLE `products` ADD COLUMN `is_active` TINYINT(1) DEFAULT 1");
            }
            if (!in_array('updated_at', $pCols)) {
                $pdo->exec("ALTER TABLE `products` ADD COLUMN `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
            }
        }
    } catch (Throwable $e) {}

    // Ensure Commerce Tables exist: product_variants, payment_plans, reminders
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `product_variants` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `company_id` INT(11) NOT NULL,
              `product_id` INT(11) NOT NULL,
              `name` VARCHAR(150) NOT NULL,
              `price_inr` INT(11) NOT NULL DEFAULT 0,
              `original_price_inr` INT(11) NOT NULL DEFAULT 0,
              `duration` VARCHAR(100) DEFAULT NULL,
              `features_json` LONGTEXT DEFAULT NULL,
              `is_default` TINYINT(1) NOT NULL DEFAULT 0,
              `is_active` TINYINT(1) NOT NULL DEFAULT 1,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_pv_company_prod` (`company_id`, `product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `payment_plans` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `company_id` INT(11) NOT NULL,
              `customer_id` INT(11) NOT NULL,
              `lead_id` INT(11) DEFAULT NULL,
              `product_id` INT(11) DEFAULT NULL,
              `variant_id` INT(11) DEFAULT NULL,
              `plan_type` ENUM('FULL', 'EMI', 'SUBSCRIPTION') NOT NULL DEFAULT 'FULL',
              `total_amount` INT(11) NOT NULL DEFAULT 0,
              `discount_amount` INT(11) NOT NULL DEFAULT 0,
              `final_amount` INT(11) NOT NULL DEFAULT 0,
              `down_payment` INT(11) NOT NULL DEFAULT 0,
              `paid_amount` INT(11) NOT NULL DEFAULT 0,
              `remaining_amount` INT(11) NOT NULL DEFAULT 0,
              `num_installments` INT(11) NOT NULL DEFAULT 1,
              `status` ENUM('DRAFT', 'ACCEPTED', 'PARTIALLY_PAID', 'PAID', 'OVERDUE', 'CANCELLED') NOT NULL DEFAULT 'DRAFT',
              `payment_gateway` VARCHAR(50) DEFAULT 'razorpay',
              `payment_link_url` VARCHAR(255) DEFAULT NULL,
              `next_due_date` DATE DEFAULT NULL,
              `metadata_json` LONGTEXT DEFAULT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_pp_company_cust` (`company_id`, `customer_id`),
              KEY `idx_pp_lead` (`lead_id`),
              KEY `idx_pp_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `reminders` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `company_id` INT(11) NOT NULL,
              `customer_id` INT(11) NOT NULL,
              `lead_id` INT(11) DEFAULT NULL,
              `installment_id` INT(11) DEFAULT NULL,
              `reminder_type` ENUM('AUTOMATED_INSTALLMENT', 'MANUAL_COLLECTION', 'RENEWAL_UPSELL', 'PROMISE_TO_PAY') NOT NULL DEFAULT 'AUTOMATED_INSTALLMENT',
              `title` VARCHAR(200) NOT NULL,
              `message_template` TEXT DEFAULT NULL,
              `amount_inr` INT(11) NOT NULL DEFAULT 0,
              `due_date` DATE DEFAULT NULL,
              `scheduled_at` DATETIME DEFAULT NULL,
              `repeat_frequency` ENUM('NONE', 'DAILY', 'WEEKLY', 'MONTHLY', 'QUARTERLY') NOT NULL DEFAULT 'NONE',
              `channels` VARCHAR(100) NOT NULL DEFAULT 'whatsapp',
              `promise_to_pay_date` DATE DEFAULT NULL,
              `status` ENUM('PENDING', 'SENT', 'DELAYED', 'PROMISED', 'RESOLVED', 'CANCELLED') NOT NULL DEFAULT 'PENDING',
              `sent_at` DATETIME DEFAULT NULL,
              `created_by_user_id` INT(11) DEFAULT NULL,
              `notes` TEXT DEFAULT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_rem_company_status` (`company_id`, `status`),
              KEY `idx_rem_due` (`due_date`),
              KEY `idx_rem_inst` (`installment_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {}

    // 19. Widget Payments Table for tracking widget pay online / bank transfers / invoices
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `widget_payments` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT NOT NULL,
            `customer_id` INT NULL,
            `lead_id` INT NULL,
            `conversation_id` INT NULL,
            `payment_method` ENUM('razorpay', 'bank_transfer', 'invoice', 'custom') DEFAULT 'razorpay',
            `amount_inr` INT NOT NULL,
            `currency` VARCHAR(10) DEFAULT 'INR',
            `transaction_reference` VARCHAR(100) NULL,
            `status` ENUM('pending', 'verified', 'failed') DEFAULT 'pending',
            `notes` TEXT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_company` (`company_id`),
            KEY `idx_customer` (`customer_id`),
            KEY `idx_lead` (`lead_id`),
            KEY `idx_ref` (`transaction_reference`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 20. Blogs and Editorial Articles Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `blogs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT DEFAULT 0,
            `title` VARCHAR(255) NOT NULL,
            `slug` VARCHAR(255) NOT NULL UNIQUE,
            `excerpt` TEXT NULL,
            `content` LONGTEXT NOT NULL,
            `cover_image` VARCHAR(500) NULL,
            `author_name` VARCHAR(100) DEFAULT 'Ayush',
            `author_role` VARCHAR(100) DEFAULT 'Founder & AI Architect',
            `author_avatar` VARCHAR(500) NULL,
            `category` VARCHAR(100) DEFAULT 'AI & Product Updates',
            `tags` VARCHAR(255) DEFAULT 'Cai, AI Agents, Helpdesk, Automation',
            `meta_title` VARCHAR(255) NULL,
            `meta_description` TEXT NULL,
            `status` ENUM('published', 'draft') DEFAULT 'published',
            `views_count` INT DEFAULT 148,
            `published_at` DATETIME NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_slug` (`slug`),
            KEY `idx_status` (`status`),
            KEY `idx_published` (`published_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Seed premier launch blog if not present
    $chkBlog = $pdo->query("SELECT id FROM `blogs` WHERE `slug` = 'meet-cai-autonomous-ai-agent' LIMIT 1")->fetch();
    if (!$chkBlog) {
        $blogStmt = $pdo->prepare("
            INSERT INTO `blogs` 
            (`company_id`, `title`, `slug`, `excerpt`, `content`, `cover_image`, `author_name`, `author_role`, `category`, `tags`, `meta_title`, `meta_description`, `status`, `views_count`, `published_at`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'published', 285, NOW(), NOW())
        ");

        $articleContent = <<<HTML
<p class="lead">Today, we are thrilled to unveil <strong>Cai</strong>—an autonomous AI agent built from the ground up to unify 24/7 customer helpdesk resolution, predictive intent scoring, and immediate WhatsApp conversion for modern high-growth businesses.</p>

<h2>The Broken Frontier of Traditional Support</h2>
<p>For the past decade, businesses have been forced to choose between two unacceptable extremes:</p>
<ul>
  <li><strong>Dumb Keyword Chatbots:</strong> Frustrating rule-based trees that loop endlessly and drive high-intent prospective buyers away.</li>
  <li><strong>Human-Only Support Queues:</strong> Expensive, slow, and unavailable outside 9-to-5 business hours when more than 62% of high-intent inquiries actually happen.</li>
</ul>

<p>Every minute a qualified buyer waits for a reply on your website, conversion probability plummets by over 390%. Modern customers do not want ticket numbers—they want authoritative answers, personalized consultative guidance, and a frictionless path to purchase.</p>

<div class="blog-callout">
  <blockquote>"We didn't just build another conversational chatbot. We engineered Cai to function as your best technical support engineer and top sales development representative (SDR) rolled into one autonomous system."</blockquote>
  <cite>— Ayush, Founder & AI Architect at CuboidSoft</cite>
</div>

<h2>What Makes Cai Fundamentally Different?</h2>
<p>Cai is powered by our proprietary dual-engine architecture combining low-latency neural retrieval (RAG) with real-time intent classification:</p>

<h3>1. Sub-Second Autonomous Resolution</h3>
<p>Trained and grounded exclusively on your company's live documents, FAQs, pricing sheets, and policies, Cai delivers nuanced, hallucination-free answers in under 800 milliseconds. It resolves over 70% of repetitive technical inquiries without ever burdening your human support staff.</p>

<h3>2. Predictive Lead Scoring & Dossier Construction</h3>
<p>As visitors interact with your website widget, Cai dynamically builds a rich commercial dossier in the background—evaluating urgency, budget readiness, target timeline, and specific objections. When intent crosses high-signal thresholds, Cai proactively offers personalized demos or booking links.</p>

<h3>3. Seamless WhatsApp Continuity</h3>
<p>Most website visitors leave before completing a purchase. With CuboidPilot's patent-pending omnichannel continuity, visitors can transition from the web widget to WhatsApp with a single tap. Their conversation history, questions, and context are preserved seamlessly, enabling your sales team to close deals on the channel buyers check 40+ times a day.</p>

<h2>Proven Business Impact Across 140+ Deployments</h2>
<p>Early access tenants deploying Cai across EdTech, SaaS, Healthcare, and Financial Services are seeing transformative operational metrics:</p>

<div class="blog-stats-grid">
  <div class="blog-stat-box">
    <div class="stat-number">72%</div>
    <div class="stat-label">Inquiries Resolved Autonomously</div>
  </div>
  <div class="blog-stat-box">
    <div class="stat-number">&lt; 1s</div>
    <div class="stat-label">Average First Response Time</div>
  </div>
  <div class="blog-stat-box">
    <div class="stat-number">3.4x</div>
    <div class="stat-label">Increase in Captured Lead Pipeline</div>
  </div>
  <div class="blog-stat-box">
    <div class="stat-number">₹0</div>
    <div class="stat-label">Overtime / Weekend Support Overhead</div>
  </div>
</div>

<h2>Getting Started with Cai in Under 5 Minutes</h2>
<p>Deploying Cai requires no complex migration or weeks of training. Simply paste our lightweight, isolated script snippet into your website header, upload your knowledge base docs, and Cai is live 24/7 immediately.</p>

<p>We are committed to making autonomous AI accessible to ambitious teams worldwide. Explore our risk-free 14-day trial and experience what autonomous support can do for your conversion rates.</p>
HTML;

        $blogStmt->execute([
            0,
            'Meet Cai: Why We Built the World’s First Autonomous AI Helpdesk & SDR Agent',
            'meet-cai-autonomous-ai-agent',
            'Discover how Cai transforms visitor conversations into qualified pipeline, cuts ticket volume by 70%, and bridges autonomous AI support with instant WhatsApp sales handoffs.',
            $articleContent,
            'assets/blog/meet-cai-founder.png',
            'Ayush',
            'Founder & AI Architect',
            'Product Launch & AI',
            'Cai, AI Agents, Autonomous Helpdesk, Sales SDR, WhatsApp',
            'Meet Cai: Autonomous AI Agent for Helpdesk, Support & Sales — CuboidPilot',
            'Read how Cai, CuboidPilot\'s breakthrough autonomous AI agent, resolves 50%+ of inbound inquiries in under 1 second and automatically turns website traffic into enrolled customers.'
        ]);
    }

    // Seed "Why Cai is Important for You" blog if not present
    $chkWhiCai = $pdo->query("SELECT id FROM `blogs` WHERE `slug` = 'why-cai-is-important-for-you' LIMIT 1")->fetch();
    if (!$chkWhiCai) {
        $whyCaiStmt = $pdo->prepare("
            INSERT INTO `blogs` 
            (`company_id`, `title`, `slug`, `excerpt`, `content`, `cover_image`, `author_name`, `author_role`, `category`, `tags`, `meta_title`, `meta_description`, `status`, `views_count`, `published_at`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'published', 194, NOW(), NOW())
        ");

        $whyCaiContent = <<<HTML
<p class="lead">Agar aap koi bhi business chalate hain — chahe online store ho, SaaS company, consulting agency, clinic, ya educational institute — aapne ye dard zaroor mehsus kiya hoga: <strong>"Customer ne website par query daali, lekin jab tak hamne reply kiya, tab tak customer competitor ke paas chala gaya!"</strong></p>

<p>Aaj ke fast digital era me customer kisi ka wait nahi karta. Unhe turant jawab chahiye. Agar aapka reply aane me 10 minute bhi lag gaye, toh 80% chance hai ki buyer kisi doosre vendor par switch kar chuka hoga. Yahin par <strong>Cai</strong> aapke liye game-changer banta hai.</p>

<div class="blog-callout">
  <blockquote>"Cai koi purana robotic bot nahi hai jo bas 'Press 1' ya 'Press 2' bole. Cai aapka smart digital employee hai — jo aapke business ko deeply samajhta hai aur 24/7 aapki sales aur customer support ko bina ruke sambhalta hai."</blockquote>
  <cite>— Ayush, Founder &amp; AI Architect at CuboidPilot</cite>
</div>

<h2>Cai Aapke Liye Kyun Zaroori Hai? (5 Badi Superpowers)</h2>
<p>Neeche diye gaye 5 powerful reasons samjhate hain ki har modern business owner, startup founder aur team ke liye Cai kyun must-have ban chuka hai:</p>

<h3>1. 💬 24/7 Instant Answers — Kabhi Koi Customer Wait Nahi Karega</h3>
<p>Aap aur aapki team so sakti hai, chhutti par ja sakti hai, lekin aapki website par customers 24 ghante aate hain (visheshkar late night aur weekends par). Cai sub-second (1 second se bhi kam) me aapke company documents, FAQs, aur pricing ke hisaab se bilkul sahi aur accurate jawab deta hai. No waiting in queue, no "Our agents are offline" message.</p>

<h3>2. 📅 Automatic Meeting &amp; Demo Booking</h3>
<p>Jab koi customer bolta hai: <em>"Mujhe demo chahiye"</em> ya <em>"Mujhe founder se baat karni hai"</em>, toh Cai chat ke andar hi direct live calendar slots show karta hai aur meeting book kar deta hai. Na lambi email chains ka jhanjhat, na missed calls ka tension. Har high-intent lead sidha aapke calendar me schedule ho jaati hai.</p>

<h3>3. 📱 Direct WhatsApp Seamless Handoff</h3>
<p>Zyadatar website visitors 2 minute me window band karke chale jaate hain. Lekin Cai ke paas hai <strong>Omnichannel WhatsApp Continuity</strong>! Customer single tap me apni poori chat history ke sath WhatsApp par connect ho sakta hai. Iska faayda? Aapki sales team direct customer ke personal phone par follow-up kar sakti hai — jahan response rate email se 10x zyada hota hai!</p>

<h3>4. 💳 In-Chat Instant Payments &amp; Checkout</h3>
<p>Agar koi visitor pricing pooch kar khareedne ke liye ready hai, toh usko 10 alag pages par bhatakne ki zaroorat nahi hai. Cai chat widget ke andar hi secure payment links generate kar deta hai (Razorpay, UPI, Cards) taaki impulse buyers bina drop hue turant checkout kar sakein.</p>

<h3>5. 📊 Real-Time Analytics &amp; High-Intent Lead Scoring</h3>
<p>Aapko kaise pata chalega ki 100 visitors me se kaun sach me purchase karne aaya hai aur kaun bas casually browse kar raha hai? Cai background me har conversation ko analyze karta hai, lead quality score generate karta hai aur high-intent prospects ka dossier taiyyar karta hai. Aapka keemti samay sirf serious buyers par focus hota hai.</p>

<h2>Numbers Don't Lie: Cai Ka Asli Business Impact</h2>
<p>Real businesses jo Cai use kar rahe hain unhone ye massive growth dekhi hai:</p>

<div class="blog-stats-grid">
  <div class="blog-stat-box">
    <div class="stat-number">&lt; 1s</div>
    <div class="stat-label">Instant Response Time</div>
  </div>
  <div class="blog-stat-box">
    <div class="stat-number">75%</div>
    <div class="stat-label">Support Tickets Solved Autonomously</div>
  </div>
  <div class="blog-stat-box">
    <div class="stat-number">3.5x</div>
    <div class="stat-label">Increase in Captured Leads</div>
  </div>
  <div class="blog-stat-box">
    <div class="stat-number">₹0</div>
    <div class="stat-label">Night Shift &amp; Weekend Overhead</div>
  </div>
</div>

<h2>Old Chatbots vs Cai: Antar Kya Hai?</h2>
<ul>
  <li><strong>Old Traditional Chatbots:</strong> Sirf fixed buttons aur boring rules hote hain. Agar customer ne thoda sa bhi ghuma kar sawaal poocha toh bot kehta hai: <em>"Sorry, I did not understand."</em> Result: Customer gussa hokar website chhod deta hai.</li>
  <li><strong>Cai (Autonomous AI Agent):</strong> Human ki tarah naturally baat karta hai. Hinglish, Hindi, English, aur regional bhashaon ko samajhta hai. Har conversation ka context yaad rakhta hai aur hamesha exact solution deta hai.</li>
</ul>

<h2>Bas 5 Minute Me Shuru Karein (No Technical Knowledge Needed)</h2>
<p>Cai ko apni website par lagana utna hi aasan hai jitna WhatsApp status lagana:</p>
<ol>
  <li>Apni website par sirf 1-line ka Cai script embed karein.</li>
  <li>Apne business ke FAQs, documents, aur details upload karein.</li>
  <li>Aur bas! Aapka 24/7 intelligent AI agent live ho gaya aur kaam karne laga.</li>
</ol>

<h2>Final Words: Apne Business Ko Supercharge Karein</h2>
<p>Samay badal raha hai aur customer ki expectations high hain. Cai lagane ka seedha matlab hai: <strong>Aapka business kabhi so nahi raha, har single lead capture ho rahi hai, aur har visitor khush aur satisfied ja raha hai.</strong></p>

<p>Agar aap bhi apne business ko autopilot par laana chahte hain, toh aaj hi CuboidPilot par free account banayein aur Cai ko action me dekhein!</p>
HTML;

        $whyCaiStmt->execute([
            0,
            'Why Cai is Important for You: The Complete Guide (आसान शब्दों में समझें)',
            'why-cai-is-important-for-you',
            'Customer queries ka instant reply nahi milta? Leads miss ho rahi hain? Jaaniye kaise Cai aapke business ko 24/7 active rakh kar sales aur customer support ko superfast bana deta hai.',
            $whyCaiContent,
            'assets/blog/why-cai-is-important-for-you.png',
            'Ayush',
            'Founder & AI Architect',
            'Product & Growth',
            'Why Cai, AI Customer Support, WhatsApp AI, Lead Generation, Business Automation, CuboidPilot',
            'Why Cai is Important for You: The Complete Guide — CuboidPilot',
            'Discover why Cai is essential for your business: 24/7 autonomous support, instant WhatsApp handoff, calendar booking, and seamless lead conversion in simple words.'
        ]);
    }

    // 21. Company Isolated Email Configurations (Section 2)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `company_email_configs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT NOT NULL UNIQUE,
            `sender_name` VARCHAR(150) NOT NULL,
            `sender_email` VARCHAR(150) NOT NULL,
            `smtp_host` VARCHAR(150) NOT NULL,
            `smtp_port` INT NOT NULL DEFAULT 587,
            `smtp_username` VARCHAR(150) NOT NULL,
            `smtp_password_encrypted` TEXT NOT NULL,
            `encryption_type` ENUM('tls', 'ssl', 'none') NOT NULL DEFAULT 'tls',
            `reply_to_email` VARCHAR(150) NULL,
            `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
            `last_tested_at` DATETIME NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_company` (`company_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 22. Team Member Email Invitations (Section 3)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `team_invitations` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT NOT NULL,
            `invited_by_user_id` INT NOT NULL,
            `email` VARCHAR(150) NOT NULL,
            `role` ENUM('owner', 'admin', 'manager', 'sales_agent') NOT NULL DEFAULT 'sales_agent',
            `invitation_token` VARCHAR(64) NOT NULL UNIQUE,
            `status` ENUM('pending', 'accepted', 'expired', 'revoked') NOT NULL DEFAULT 'pending',
            `expires_at` DATETIME NOT NULL,
            `accepted_at` DATETIME NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_company` (`company_id`),
            KEY `idx_token` (`invitation_token`),
            KEY `idx_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 23. Company Instagram / Meta Business Messaging Configs (Section 4)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `company_instagram_configs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT NOT NULL UNIQUE,
            `page_id` VARCHAR(100) NULL,
            `instagram_account_id` VARCHAR(100) NULL,
            `instagram_username` VARCHAR(100) NULL,
            `access_token_encrypted` TEXT NULL,
            `app_secret_encrypted` TEXT NULL,
            `webhook_verify_token` VARCHAR(100) NULL,
            `status` ENUM('connected', 'disconnected', 'pending') NOT NULL DEFAULT 'disconnected',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_company` (`company_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 24. Instagram Handoffs (Section 5)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `instagram_handoffs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT NOT NULL,
            `handoff_token` VARCHAR(64) NOT NULL UNIQUE,
            `session_id` VARCHAR(64) NOT NULL,
            `customer_id` INT NOT NULL,
            `lead_id` INT NULL,
            `web_conversation_id` INT NOT NULL,
            `status` ENUM('pending', 'claimed', 'expired') NOT NULL DEFAULT 'pending',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `claimed_at` DATETIME NULL,
            `expires_at` DATETIME NOT NULL,
            KEY `idx_token` (`handoff_token`),
            KEY `idx_company` (`company_id`),
            KEY `idx_session` (`session_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 25. Company Calendar Configurations (Sections 7 & 8)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `company_calendar_configs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT NOT NULL UNIQUE,
            `provider` VARCHAR(50) NOT NULL DEFAULT 'google_calendar',
            `google_calendar_id` VARCHAR(255) NOT NULL DEFAULT 'primary',
            `google_client_id` VARCHAR(255) NULL,
            `google_client_secret_encrypted` TEXT NULL,
            `google_refresh_token_encrypted` TEXT NULL,
            `google_access_token_encrypted` TEXT NULL,
            `google_token_expires_at` DATETIME NULL,
            `working_days` VARCHAR(50) NOT NULL DEFAULT '1,2,3,4,5,6',
            `working_hours_start` TIME NOT NULL DEFAULT '09:00:00',
            `working_hours_end` TIME NOT NULL DEFAULT '18:00:00',
            `slot_duration_minutes` INT NOT NULL DEFAULT 30,
            `buffer_before_minutes` INT NOT NULL DEFAULT 0,
            `buffer_after_minutes` INT NOT NULL DEFAULT 10,
            `min_booking_notice_hours` INT NOT NULL DEFAULT 2,
            `max_advance_booking_days` INT NOT NULL DEFAULT 14,
            `timezone` VARCHAR(50) NOT NULL DEFAULT 'Asia/Kolkata',
            `meeting_title_template` VARCHAR(200) NOT NULL DEFAULT 'Consultation: {customer_name}',
            `meeting_description_template` TEXT NULL,
            `is_connected` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_company` (`company_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 26. Extend visitor_sessions table with Session ID & CRM associations (Section 1)
    $vsCols = $pdo->query("SHOW COLUMNS FROM `visitor_sessions`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('session_id', $vsCols)) {
        $pdo->exec("ALTER TABLE `visitor_sessions` ADD COLUMN `session_id` VARCHAR(64) NULL AFTER `company_id`");
        $pdo->exec("ALTER TABLE `visitor_sessions` ADD UNIQUE KEY `idx_vs_session_id` (`session_id`)");
    }
    if (!in_array('visitor_id', $vsCols)) {
        $pdo->exec("ALTER TABLE `visitor_sessions` ADD COLUMN `visitor_id` INT NULL AFTER `session_id`");
    }
    if (!in_array('lead_id', $vsCols)) {
        $pdo->exec("ALTER TABLE `visitor_sessions` ADD COLUMN `lead_id` INT NULL AFTER `customer_id`");
    }
    if (!in_array('conversation_id', $vsCols)) {
        $pdo->exec("ALTER TABLE `visitor_sessions` ADD COLUMN `conversation_id` INT NULL AFTER `lead_id`");
    }
    if (!in_array('channel', $vsCols)) {
        $pdo->exec("ALTER TABLE `visitor_sessions` ADD COLUMN `channel` VARCHAR(50) NOT NULL DEFAULT 'web' AFTER `conversation_id`");
    }
    if (!in_array('updated_at', $vsCols)) {
        $pdo->exec("ALTER TABLE `visitor_sessions` ADD COLUMN `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");
    }

    // 27. Extend appointments table with Section 11 specifications
    $apptCols = $pdo->query("SHOW COLUMNS FROM `appointments`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('visitor_id', $apptCols)) {
        $pdo->exec("ALTER TABLE `appointments` ADD COLUMN `visitor_id` INT NULL AFTER `customer_id`");
    }
    if (!in_array('session_id', $apptCols)) {
        $pdo->exec("ALTER TABLE `appointments` ADD COLUMN `session_id` VARCHAR(64) NULL AFTER `lead_id`");
    }
    if (!in_array('conversation_id', $apptCols)) {
        $pdo->exec("ALTER TABLE `appointments` ADD COLUMN `conversation_id` INT NULL AFTER `session_id`");
    }
    if (!in_array('calendar_integration_id', $apptCols)) {
        $pdo->exec("ALTER TABLE `appointments` ADD COLUMN `calendar_integration_id` INT NULL AFTER `assigned_user_id`");
    }
    if (!in_array('customer_name', $apptCols)) {
        $pdo->exec("ALTER TABLE `appointments` ADD COLUMN `customer_name` VARCHAR(150) NULL AFTER `calendar_integration_id`");
    }
    if (!in_array('customer_phone', $apptCols)) {
        $pdo->exec("ALTER TABLE `appointments` ADD COLUMN `customer_phone` VARCHAR(50) NULL AFTER `customer_name`");
    }
    if (!in_array('customer_email', $apptCols)) {
        $pdo->exec("ALTER TABLE `appointments` ADD COLUMN `customer_email` VARCHAR(150) NULL AFTER `customer_phone`");
    }
    if (!in_array('appointment_date', $apptCols)) {
        $pdo->exec("ALTER TABLE `appointments` ADD COLUMN `appointment_date` DATE NULL AFTER `appointment_type`");
    }
    if (!in_array('start_time', $apptCols)) {
        $pdo->exec("ALTER TABLE `appointments` ADD COLUMN `start_time` TIME NULL AFTER `appointment_date`");
    }
    if (!in_array('end_time', $apptCols)) {
        $pdo->exec("ALTER TABLE `appointments` ADD COLUMN `end_time` TIME NULL AFTER `start_time`");
    }
    if (!in_array('timezone', $apptCols)) {
        $pdo->exec("ALTER TABLE `appointments` ADD COLUMN `timezone` VARCHAR(50) NOT NULL DEFAULT 'Asia/Kolkata' AFTER `end_time`");
    }
    if (!in_array('calendar_event_id', $apptCols)) {
        $pdo->exec("ALTER TABLE `appointments` ADD COLUMN `calendar_event_id` VARCHAR(255) NULL AFTER `meet_link`");
    }
    if (!in_array('created_channel', $apptCols)) {
        $pdo->exec("ALTER TABLE `appointments` ADD COLUMN `created_channel` VARCHAR(50) NOT NULL DEFAULT 'web' AFTER `status`");
    }
    if (!in_array('meeting_link', $apptCols)) {
        $pdo->exec("ALTER TABLE `appointments` ADD COLUMN `meeting_link` VARCHAR(255) NULL AFTER `calendar_event_id`");
    }
    // Update appointment status enum to support scheduled, rescheduled, completed, cancelled, no_show
    try {
        $pdo->exec("ALTER TABLE `appointments` MODIFY COLUMN `status` ENUM('scheduled', 'rescheduled', 'completed', 'cancelled', 'no_show') NOT NULL DEFAULT 'scheduled'");
    } catch (Exception $e) {}

    // 28. Generic Channel Architecture: Extend conversations & messages (Section 6)
    try {
        $pdo->exec("ALTER TABLE `conversations` MODIFY COLUMN `channel` VARCHAR(50) NOT NULL DEFAULT 'web'");
    } catch (Exception $e) {}
    $convCols = $pdo->query("SHOW COLUMNS FROM `conversations`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('session_id', $convCols)) {
        $pdo->exec("ALTER TABLE `conversations` ADD COLUMN `session_id` VARCHAR(64) NULL AFTER `visitor_session_id`");
    }

    $msgCols = $pdo->query("SHOW COLUMNS FROM `messages`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('channel', $msgCols)) {
        $pdo->exec("ALTER TABLE `messages` ADD COLUMN `channel` VARCHAR(50) NOT NULL DEFAULT 'web' AFTER `company_id`");
    }
    if (!in_array('session_id', $msgCols)) {
        $pdo->exec("ALTER TABLE `messages` ADD COLUMN `session_id` VARCHAR(64) NULL AFTER `conversation_id`");
    }

    // 29. Workspace Payments: Ensure payments.customer_id is nullable for workspace subscriptions
    try {
        $pdo->exec("ALTER TABLE `payments` MODIFY COLUMN `customer_id` INT(11) NULL DEFAULT NULL");
    } catch (Exception $e) {}

    // 30. Company Digital Assets Library (Documents, Syllabi, Brochures, Fee Charts, PDFs)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `company_assets` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `category` VARCHAR(64) NOT NULL DEFAULT 'document',
            `description` TEXT NULL,
            `keywords` TEXT NULL,
            `file_name` VARCHAR(255) NOT NULL,
            `file_path` VARCHAR(500) NOT NULL,
            `file_size` INT DEFAULT 0,
            `file_type` VARCHAR(100) DEFAULT 'application/pdf',
            `download_count` INT DEFAULT 0,
            `is_active` TINYINT(1) DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_company_asset` (`company_id`, `is_active`),
            INDEX `idx_asset_category` (`company_id`, `category`),
            CONSTRAINT `fk_asset_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 31. Omnichannel Identity Mapping (Web, WhatsApp, Instagram, Email, Phone)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `customer_channel_identities` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT NOT NULL,
            `customer_id` INT NOT NULL,
            `channel` ENUM('web', 'whatsapp', 'instagram', 'email', 'phone') NOT NULL,
            `external_user_id` VARCHAR(128) NOT NULL,
            `external_account_id` VARCHAR(128) NULL,
            `phone` VARCHAR(64) NULL,
            `email` VARCHAR(128) NULL,
            `verified_at` DATETIME NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_company_channel_user` (`company_id`, `channel`, `external_user_id`),
            KEY `idx_customer` (`customer_id`),
            KEY `idx_comp_channel` (`company_id`, `channel`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 32. Omnichannel Customer Journeys (Single Source of Truth across Channels)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `customer_journeys` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT NOT NULL,
            `customer_id` INT NOT NULL,
            `lead_id` INT NULL,
            `conversation_id` INT NOT NULL,
            `state` VARCHAR(64) NOT NULL DEFAULT 'NEW',
            `current_channel` VARCHAR(32) NOT NULL DEFAULT 'web',
            `previous_channel` VARCHAR(32) NULL,
            `pending_action` VARCHAR(64) NULL,
            `pending_asset_id` INT NULL,
            `assigned_user_id` INT NULL,
            `conversation_summary` TEXT NULL,
            `last_activity_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_comp_cust` (`company_id`, `customer_id`),
            KEY `idx_comp_conv` (`company_id`, `conversation_id`),
            KEY `idx_lead` (`lead_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 33. Unified Channel Handoffs (Web -> WhatsApp / Instagram)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `channel_handoffs` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT NOT NULL,
            `customer_id` INT NOT NULL,
            `lead_id` INT NULL,
            `journey_id` BIGINT NOT NULL,
            `conversation_id` INT NOT NULL,
            `source_channel` VARCHAR(32) NOT NULL DEFAULT 'web',
            `target_channel` VARCHAR(32) NOT NULL,
            `handoff_token` VARCHAR(32) NOT NULL UNIQUE,
            `status` ENUM('pending', 'completed', 'expired') NOT NULL DEFAULT 'pending',
            `expires_at` DATETIME NOT NULL,
            `completed_at` DATETIME NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_token` (`handoff_token`),
            KEY `idx_comp_journey` (`company_id`, `journey_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 34. Channel Consents & Opt-In Auditing
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `channel_consents` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT NOT NULL,
            `customer_id` INT NOT NULL,
            `channel` VARCHAR(32) NOT NULL,
            `consent_type` VARCHAR(64) NOT NULL,
            `consent_status` ENUM('granted', 'revoked') NOT NULL DEFAULT 'granted',
            `consent_source` VARCHAR(64) NOT NULL DEFAULT 'website_widget',
            `consent_text_version` VARCHAR(32) NOT NULL DEFAULT 'v1',
            `handoff_token` VARCHAR(32) NULL,
            `granted_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `revoked_at` DATETIME NULL,
            KEY `idx_cust_consent` (`company_id`, `customer_id`, `channel`, `consent_type`),
            KEY `idx_handoff_token` (`handoff_token`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Ensure handoff_token exists in channel_consents
    try {
        $cCols = $pdo->query("SHOW COLUMNS FROM `channel_consents`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('handoff_token', $cCols)) {
            $pdo->exec("ALTER TABLE `channel_consents` ADD COLUMN `handoff_token` VARCHAR(32) NULL AFTER `consent_text_version`");
            $pdo->exec("ALTER TABLE `channel_consents` ADD INDEX `idx_handoff_token` (`handoff_token`)");
        }
    } catch (Exception $e) {}

    // Ensure conversation_id in customer_journeys allows NULL
    try {
        $pdo->exec("ALTER TABLE `customer_journeys` MODIFY `conversation_id` INT NULL");
    } catch (Exception $e) {}

    // Ensure lead_events supports modern CRM events and nullable lead_id/customer_id
    try {
        $pdo->exec("ALTER TABLE `lead_events` MODIFY `lead_id` INT NULL");
        $pdo->exec("ALTER TABLE `lead_events` MODIFY `customer_id` INT NULL");
        $pdo->exec("ALTER TABLE `lead_events` MODIFY `event_type` VARCHAR(64) NOT NULL");
    } catch (Exception $e) {}

    // Ensure messages table has channel column
    try {
        $msgCols = $pdo->query("SHOW COLUMNS FROM `messages`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('channel', $msgCols)) {
            $pdo->exec("ALTER TABLE `messages` ADD COLUMN `channel` VARCHAR(32) NOT NULL DEFAULT 'web' AFTER `message_text`");
        }
    } catch (Exception $e) {}

    // 35. Visual AI Workflows & Automation Builder Schema
    try {
        $autoCols = $pdo->query("SHOW COLUMNS FROM `automations`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('description', $autoCols)) {
            $pdo->exec("ALTER TABLE `automations` ADD COLUMN `description` TEXT NULL AFTER `name`");
        }
        if (!in_array('status', $autoCols)) {
            $pdo->exec("ALTER TABLE `automations` ADD COLUMN `status` VARCHAR(30) NOT NULL DEFAULT 'active' AFTER `description`");
        }
        if (!in_array('workflow_data', $autoCols)) {
            $pdo->exec("ALTER TABLE `automations` ADD COLUMN `workflow_data` LONGTEXT NULL AFTER `secondary_action`");
        }
        if (!in_array('version', $autoCols)) {
            $pdo->exec("ALTER TABLE `automations` ADD COLUMN `version` INT NOT NULL DEFAULT 1 AFTER `workflow_data`");
        }
        if (!in_array('trigger_type', $autoCols)) {
            $pdo->exec("ALTER TABLE `automations` ADD COLUMN `trigger_type` VARCHAR(100) NOT NULL DEFAULT 'chat_start' AFTER `version`");
        }
        if (!in_array('execution_count', $autoCols)) {
            $pdo->exec("ALTER TABLE `automations` ADD COLUMN `execution_count` INT NOT NULL DEFAULT 0 AFTER `trigger_type`");
        }
        if (!in_array('last_executed_at', $autoCols)) {
            $pdo->exec("ALTER TABLE `automations` ADD COLUMN `last_executed_at` DATETIME NULL AFTER `execution_count`");
        }
        if (!in_array('updated_at', $autoCols)) {
            $pdo->exec("ALTER TABLE `automations` ADD COLUMN `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");
        }
    } catch (Exception $e) {}

    // 36. Automation Executions & Logs Tables
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `automation_executions` (
                `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                `company_id` INT NOT NULL,
                `automation_id` INT NOT NULL,
                `version` INT NOT NULL DEFAULT 1,
                `session_id` VARCHAR(64) NULL,
                `customer_id` INT NULL,
                `lead_id` INT NULL,
                `conversation_id` INT NULL,
                `trigger_type` VARCHAR(100) NOT NULL,
                `status` ENUM('running', 'waiting', 'completed', 'failed', 'paused') NOT NULL DEFAULT 'running',
                `current_node_id` VARCHAR(100) NULL,
                `completed_nodes_json` LONGTEXT NULL,
                `variables_json` LONGTEXT NULL,
                `error_details` TEXT NULL,
                `is_simulation` TINYINT(1) NOT NULL DEFAULT 0,
                `started_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `completed_at` DATETIME NULL,
                KEY `idx_comp_status` (`company_id`, `status`),
                KEY `idx_session` (`session_id`),
                KEY `idx_automation` (`automation_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `automation_logs` (
                `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                `execution_id` BIGINT NOT NULL,
                `company_id` INT NOT NULL,
                `automation_id` INT NOT NULL,
                `node_id` VARCHAR(100) NOT NULL,
                `node_type` VARCHAR(100) NOT NULL,
                `node_title` VARCHAR(255) NULL,
                `status` ENUM('success', 'failed', 'waiting', 'skipped') NOT NULL DEFAULT 'success',
                `input_data_json` LONGTEXT NULL,
                `output_data_json` LONGTEXT NULL,
                `duration_ms` INT NOT NULL DEFAULT 0,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_exec` (`execution_id`),
                KEY `idx_comp` (`company_id`, `automation_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // 21. Payment Requests & Multichannel Settlement Table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `payment_requests` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `company_id` INT NOT NULL,
                `request_code` VARCHAR(50) NOT NULL UNIQUE,
                `customer_id` INT NOT NULL,
                `lead_id` INT NULL,
                `conversation_id` INT NULL,
                `item_type` ENUM('course', 'product', 'service', 'custom') NOT NULL DEFAULT 'course',
                `item_title` VARCHAR(200) NOT NULL,
                `amount_inr` INT NOT NULL DEFAULT 0,
                `customer_name` VARCHAR(150) NOT NULL,
                `customer_email` VARCHAR(150) NOT NULL,
                `customer_phone` VARCHAR(30) NOT NULL,
                `status` ENUM('pending', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
                `payment_method` VARCHAR(50) NULL DEFAULT 'upi',
                `transaction_reference` VARCHAR(100) NULL,
                `confirmation_token` VARCHAR(64) NULL,
                `confirmed_by_user_id` INT NULL,
                `confirmed_at` DATETIME NULL,
                `receipt_sent_at` DATETIME NULL,
                `notes` TEXT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_pr_company_status` (`company_id`, `status`),
                KEY `idx_pr_code` (`request_code`),
                KEY `idx_pr_customer` (`customer_id`),
                KEY `idx_pr_lead` (`lead_id`),
                KEY `idx_pr_token` (`confirmation_token`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // 22. Appointments Migration & Activity History Audit Trail
        $apptCols = $pdo->query("SHOW COLUMNS FROM `appointments`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('action_token', $apptCols)) {
            $pdo->exec("ALTER TABLE `appointments` ADD COLUMN `action_token` VARCHAR(64) NULL AFTER `status`, ADD KEY `idx_action_token` (`action_token`)");
        }
        if (!in_array('gcal_sync_status', $apptCols)) {
            $pdo->exec("ALTER TABLE `appointments` ADD COLUMN `gcal_sync_status` ENUM('none','synced','failed') NOT NULL DEFAULT 'none' AFTER `google_event_id`");
        }
        if (!in_array('gcal_sync_error', $apptCols)) {
            $pdo->exec("ALTER TABLE `appointments` ADD COLUMN `gcal_sync_error` TEXT NULL AFTER `gcal_sync_status`");
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `appointment_activities` (
                `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                `appointment_id` INT NOT NULL,
                `company_id` INT NOT NULL,
                `action` VARCHAR(64) NOT NULL,
                `actor_type` ENUM('customer','admin','system','ai') NOT NULL DEFAULT 'system',
                `actor_name` VARCHAR(150) NULL,
                `channel` VARCHAR(32) NOT NULL DEFAULT 'web',
                `details` TEXT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_act_appt` (`appointment_id`),
                KEY `idx_act_comp` (`company_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } catch (Exception $e) {}
}

/**
 * Universal Tenant Workspace Provisioner.
 * Creates company, owner user, default CRM stages, widget settings, and initial knowledge.
 */
function provisionTenantWorkspace(PDO $pdo, array $params): array {
    $name = trim($params['name'] ?? 'My Business');
    $slug = trim($params['slug'] ?? '');
    if (empty($slug)) {
        $baseSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
        $slug = trim($baseSlug, '-') . '-' . substr(uniqid(), -4);
    }
    $companyKey = trim($params['company_key'] ?? ('cp_live_' . bin2hex(random_bytes(16))));
    $industry   = trim($params['industry'] ?? 'Technology & SaaS');
    $city       = trim($params['city'] ?? 'Bengaluru');
    $country    = trim($params['country'] ?? 'India');
    $status     = trim($params['status'] ?? 'trial');
    $planTier   = trim($params['plan_tier'] ?? 'growth');
    $onboarding = (int)($params['onboarding_completed'] ?? 0);

    // 1. Insert Company
    $compUuid = 'comp_' . bin2hex(random_bytes(12));
    $compStmt = $pdo->prepare("
        INSERT INTO `companies` 
        (`uuid`, `name`, `slug`, `company_key`, `industry`, `city`, `country`, `currency`, `timezone`, `status`, `plan_tier`, `trial_started_at`, `trial_ends_at`, `whatsapp_connected`, `onboarding_completed`, `created_at`, `updated_at`)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'INR', 'Asia/Kolkata', ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 14 DAY), 1, ?, NOW(), NOW())
    ");
    $compStmt->execute([
        $compUuid, $name, $slug, $companyKey, $industry, $city, $country, $status, $planTier, $onboarding
    ]);
    $companyId = (int)$pdo->lastInsertId();

    // 2. Insert Owner User
    $userId = null;
    $ownerEmail = trim($params['owner_email'] ?? '');
    if (!empty($ownerEmail)) {
        $ownerName = trim($params['owner_name'] ?? $name);
        $userUuid = 'usr_' . bin2hex(random_bytes(12));
        $rawPass = $params['owner_password'] ?? 'password123';
        $hash = password_hash($rawPass, PASSWORD_BCRYPT);

        $chk = $pdo->prepare("SELECT id FROM `users` WHERE `email` = ?");
        $chk->execute([$ownerEmail]);
        $existing = $chk->fetch();
        if ($existing) {
            $userId = (int)$existing['id'];
            $pdo->prepare("UPDATE `users` SET `company_id` = ?, `role` = 'owner' WHERE `id` = ?")
                ->execute([$companyId, $userId]);
        } else {
            $insU = $pdo->prepare("
                INSERT INTO `users` (`uuid`, `company_id`, `name`, `email`, `password_hash`, `role`, `is_super_admin`, `is_active`, `created_at`)
                VALUES (?, ?, ?, ?, ?, 'owner', 0, 1, NOW())
            ");
            $insU->execute([$userUuid, $companyId, $ownerName, $ownerEmail, $hash]);
            $userId = (int)$pdo->lastInsertId();
        }
    }

    // 3. Default Pipeline Stages
    $defaultStages = [
        ['New Inquiry', 'new', 1, '#4F46E5'],
        ['Contacted', 'contacted', 2, '#0EA5E9'],
        ['Qualified', 'qualified', 3, '#F59E0B'],
        ['Proposal / Demo', 'proposal', 4, '#8B5CF6'],
        ['Won / Enrolled', 'won', 5, '#10B981'],
        ['Lost', 'lost', 6, '#EF4444'],
    ];
    $insStg = $pdo->prepare("
        INSERT INTO `pipeline_stages` (`company_id`, `name`, `slug`, `stage_order`, `color_code`, `is_default`)
        VALUES (?, ?, ?, ?, ?, 1)
    ");
    foreach ($defaultStages as $s) {
        $insStg->execute([$companyId, $s[0], $s[1], $s[2], $s[3]]);
    }

    // 4. Default Widget Settings
    $brandName = $params['widget_brand_name'] ?? $name;
    $greeting = $params['widget_greeting'] ?? "Hi there 👋 Welcome to {$name}! How can I help you today?";
    $subheading = $params['widget_subheading'] ?? "Instant AI answers • Powered by CuboidPilot";
    $accentColor = $params['widget_accent_color'] ?? '#111111';
    $waNumber = $params['whatsapp_number'] ?? '+91 98201 12345';

    $pdo->prepare("
        INSERT INTO `widget_settings` 
        (`company_id`, `brand_name`, `accent_color`, `greeting_heading`, `greeting_subheading`, `position`, `enable_whatsapp_continue`, `require_phone_for_pricing`, `whatsapp_number`)
        VALUES (?, ?, ?, ?, ?, 'bottom_right', 1, 1, ?)
    ")->execute([
        $companyId, $brandName, $accentColor, $greeting, $subheading, $waNumber
    ]);

    // 5. Default WhatsApp Account
    $pdo->prepare("
        INSERT INTO `whatsapp_accounts` (`company_id`, `phone_number_id`, `waba_id`, `display_number`, `status`, `quality_rating`, `created_at`)
        VALUES (?, 'sim_phone_001', 'waba_sim_001', ?, 'connected', 'GREEN', NOW())
    ")->execute([$companyId, $waNumber]);

    // 6. Initial Knowledge Sources
    if (!empty($params['knowledge_sources']) && is_array($params['knowledge_sources'])) {
        $insKb = $pdo->prepare("
            INSERT INTO `knowledge_sources` (`company_id`, `type`, `title`, `content`, `is_active`, `created_at`)
            VALUES (?, ?, ?, ?, 1, NOW())
        ");
        foreach ($params['knowledge_sources'] as $kb) {
            $insKb->execute([
                $companyId,
                $kb['type'] ?? 'faq',
                $kb['title'] ?? 'Overview',
                $kb['content'] ?? ''
            ]);
        }
    }

    // 7. Seed Default AI Brain Configuration (Section 2 & 3)
    $pdo->prepare("
        INSERT INTO `ai_configs` 
        (`company_id`, `ai_name`, `tone`, `primary_objective`, `info_to_collect_json`, `custom_instructions`, `qualification_rules_json`, `appointment_instructions`, `payment_instructions`)
        VALUES (?, ?, 'professional', 'generate_leads', ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE `updated_at` = NOW()
    ")->execute([
        $companyId,
        $params['widget_assistant_name'] ?? 'Cai',
        json_encode(['name', 'phone', 'email', 'requirement', 'budget', 'timeline']),
        "Never invent unverified pricing, discounts, guarantees, or delivery dates. When reliable information is unavailable, ask for clarification or offer human counselor assistance.",
        json_encode([
            'min_budget' => 25000,
            'urgent_timeline_days' => 30,
            'high_priority_keywords' => ['pricing', 'fee', 'demo', 'appointment', 'urgent', 'immediately']
        ]),
        "Admissions & discovery calls available Monday through Saturday 10:00 AM to 7:00 PM IST.",
        "Accepts UPI, Netbanking, Credit Cards, and flexible 3-Month / 6-Month zero-interest EMI installment schedules."
    ]);

    // 8. Seed Default Lead Scoring Rules (Section 8)
    $defaultScoringRules = [
        ['phone_shared', null, 20, 'Visitor provided phone number / contact info'],
        ['pricing_inquired', null, 15, 'Asked about pricing, fees, or packages'],
        ['budget_confirmed', '50000', 25, 'Confirmed project/tuition budget >= ₹50,000'],
        ['timeline_urgent', '30', 20, 'Needs project or enrollment within 30 days'],
        ['whatsapp_continued', null, 15, 'Continued conversation on WhatsApp'],
        ['demo_requested', null, 20, 'Requested demo or consultation meeting'],
        ['human_requested', null, 25, 'Explicitly requested human counselor / call']
    ];
    $insRule = $pdo->prepare("
        INSERT IGNORE INTO `lead_scoring_rules` (`company_id`, `signal_type`, `condition_value`, `score_delta`, `description`, `is_active`)
        VALUES (?, ?, ?, ?, ?, 1)
    ");
    foreach ($defaultScoringRules as $r) {
        $insRule->execute([$companyId, $r[0], $r[1], $r[2], $r[3]]);
    }

    // 9. Seed Default Custom Fields (Section 29)
    $defaultFields = [
        ['requirement_type', 'Project / Course Requirement', 'text', null, 1],
        ['budget_range', 'Budget Range', 'select', json_encode(['₹25,000 - ₹50,000', '₹50,000 - ₹1,00,000', '₹1,00,000+']), 0],
        ['target_timeline', 'Target Timeline', 'select', json_encode(['Immediate (1-2 weeks)', 'Within 30 Days', '1-3 Months']), 0]
    ];
    $insField = $pdo->prepare("
        INSERT IGNORE INTO `company_custom_fields` (`company_id`, `field_key`, `field_label`, `field_type`, `options_json`, `is_required`)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    foreach ($defaultFields as $f) {
        $insField->execute([$companyId, $f[0], $f[1], $f[2], $f[3], $f[4]]);
    }

    // 10. Seed Initial 14-Day Free Trial Subscription & $0 Payment Receipt (Start with $0 / ₹0)
    try {
        $growthPlan = $pdo->query("SELECT id FROM `plans` WHERE code = 'growth' OR name LIKE '%growth%' LIMIT 1")->fetch();
        $planId = $growthPlan ? (int)$growthPlan['id'] : 2;

        $pdo->prepare("
            INSERT INTO `subscriptions`
            (`company_id`, `plan_id`, `status`, `amount_inr`, `current_period_start`, `current_period_end`, `created_at`, `updated_at`)
            VALUES (?, ?, 'trial', 0, NOW(), DATE_ADD(NOW(), INTERVAL 14 DAY), NOW(), NOW())
        ")->execute([$companyId, $planId]);

        $pdo->prepare("
            INSERT INTO `payments`
            (`company_id`, `customer_id`, `amount_inr`, `currency`, `status`, `razorpay_order_id`, `razorpay_payment_id`, `paid_at`, `created_at`, `updated_at`)
            VALUES (?, NULL, 0, 'INR', 'paid', 'order_trial_free_14d', 'pay_trial_start_0', NOW(), NOW(), NOW())
        ")->execute([$companyId]);
    } catch (Exception $e) {}

    return [
        'company_id'  => $companyId,
        'user_id'     => $userId,
        'company_key' => $companyKey,
        'slug'        => $slug,
        'name'        => $name
    ];
}

/**
 * Initializes users and company table if missing, and ensures demo credentials work.
 */
function initDbSchemaAndUsers(PDO $pdo) {
    // Check if users table exists
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'users'")->fetch();
    if (!$tableCheck) {
        // Create companies table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `companies` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `uuid` VARCHAR(64) NOT NULL UNIQUE,
                `name` VARCHAR(150) NOT NULL,
                `slug` VARCHAR(100) NOT NULL UNIQUE,
                `status` ENUM('trial', 'active', 'past_due', 'suspended') DEFAULT 'active',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Create users table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `users` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `uuid` VARCHAR(64) NOT NULL UNIQUE,
                `company_id` INT NULL,
                `name` VARCHAR(150) NOT NULL,
                `email` VARCHAR(150) NOT NULL UNIQUE,
                `password_hash` VARCHAR(255) NOT NULL,
                `phone` VARCHAR(30) NULL,
                `role` ENUM('super_admin','owner','admin','manager','sales_agent') NOT NULL DEFAULT 'owner',
                `is_super_admin` TINYINT(1) NOT NULL DEFAULT 0,
                `avatar_url` VARCHAR(255) NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `last_login_at` DATETIME NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_company` (`company_id`),
                KEY `idx_email` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    $firstCompId = (int)$pdo->query("SELECT id FROM `companies` ORDER BY id ASC LIMIT 1")->fetchColumn();
    if (!$firstCompId) $firstCompId = null;

    // Ensure production administration accounts exist with verified credentials
    $productionUsers = [
        [
            'uuid'           => 'usr_root_superadmin_01',
            'company_id'     => null,
            'name'           => 'Ayush Varma (Super Admin)',
            'email'          => 'admin@cuboidpolit.com',
            'role'           => 'super_admin',
            'is_super_admin' => 1,
            'is_active'      => 1
        ],
        [
            'uuid'           => 'usr_owner_cuboidsoft_01',
            'company_id'     => $firstCompId,
            'name'           => 'Ayush (CuboidSoft)',
            'email'          => 'founder@cuboidsoft.in',
            'role'           => 'owner',
            'is_super_admin' => 0,
            'is_active'      => 1
        ]
    ];

    $hash = password_hash('password123', PASSWORD_BCRYPT);

    foreach ($productionUsers as $u) {
        $checkStmt = $pdo->prepare("SELECT id, password_hash FROM `users` WHERE `email` = ?");
        $checkStmt->execute([$u['email']]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            // User exists; preserve their existing password and active status
            continue;
        } else {
            // Insert production user
            $ins = $pdo->prepare("
                INSERT INTO `users` (`uuid`, `company_id`, `name`, `email`, `password_hash`, `role`, `is_super_admin`, `is_active`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([
                $u['uuid'],
                $u['company_id'],
                $u['name'],
                $u['email'],
                $hash,
                $u['role'],
                $u['is_super_admin'],
                $u['is_active']
            ]);
        }
    }
}
