<?php
/**
 * Migration runner for commerce_revenue_lifecycle.sql
 */
require_once __DIR__ . '/../config/db.php';

try {
    $pdo = getDbConnection();
    echo "Connected to database.\n";

    // 1. Expand products table
    $alterStatements = [
        "ALTER TABLE `products` ADD COLUMN IF NOT EXISTS `category` VARCHAR(50) NOT NULL DEFAULT 'service'",
        "ALTER TABLE `products` ADD COLUMN IF NOT EXISTS `subcategory` VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE `products` ADD COLUMN IF NOT EXISTS `duration` VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE `products` ADD COLUMN IF NOT EXISTS `original_price_inr` INT(11) NOT NULL DEFAULT 0",
        "ALTER TABLE `products` ADD COLUMN IF NOT EXISTS `discount_percent` INT(11) NOT NULL DEFAULT 0",
        "ALTER TABLE `products` ADD COLUMN IF NOT EXISTS `currency` VARCHAR(10) NOT NULL DEFAULT 'INR'",
        "ALTER TABLE `products` ADD COLUMN IF NOT EXISTS `features_json` LONGTEXT DEFAULT NULL",
        "ALTER TABLE `products` ADD COLUMN IF NOT EXISTS `deliverables_json` LONGTEXT DEFAULT NULL",
        "ALTER TABLE `products` ADD COLUMN IF NOT EXISTS `prerequisites` TEXT DEFAULT NULL",
        "ALTER TABLE `products` ADD COLUMN IF NOT EXISTS `target_audience` VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE `products` ADD COLUMN IF NOT EXISTS `emi_plans_json` LONGTEXT DEFAULT NULL",
        "ALTER TABLE `products` ADD COLUMN IF NOT EXISTS `max_discount_allowed_percent` INT(11) NOT NULL DEFAULT 10",
        "ALTER TABLE `products` ADD COLUMN IF NOT EXISTS `brochure_asset_id` INT(11) DEFAULT NULL",
        "ALTER TABLE `products` ADD COLUMN IF NOT EXISTS `thumbnail_url` VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE `products` ADD COLUMN IF NOT EXISTS `faq_json` LONGTEXT DEFAULT NULL",
        "ALTER TABLE `products` ADD COLUMN IF NOT EXISTS `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
    ];

    foreach ($alterStatements as $sql) {
        try {
            $pdo->exec($sql);
            echo "Executed: " . substr($sql, 0, 60) . "...\n";
        } catch (Exception $e) {
            // MySQL before 8.0.29 might throw duplicate column error if ADD COLUMN IF NOT EXISTS is not supported
            if (strpos($e->getMessage(), 'Duplicate column') !== false) {
                echo "Column already exists, skipped.\n";
            } else {
                echo "Notice: " . $e->getMessage() . "\n";
            }
        }
    }

    // 2. Create product_variants
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "Table product_variants verified/created.\n";

    // 3. Create payment_plans
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "Table payment_plans verified/created.\n";

    // 4. Create reminders
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "Table reminders verified/created.\n";

    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
    exit(1);
}
