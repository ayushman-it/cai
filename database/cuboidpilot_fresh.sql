-- ==========================================================================
-- CUBOIDPILOT — COMPLETE FRESH PRODUCTION DATABASE DUMP
-- Generated: 2026-10-02 07:55:03
-- Target: MySQL 5.7+ / 8.0+ / MariaDB 10.3+
-- Includes: Full schema, SuperAdmin user, default tenants, INR plans,
--           Cai AI widget configuration, calendar, and launch SEO blog.
-- ==========================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ---------------------------------------------------------
-- Table structure for `ai_configs`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `ai_configs`;
CREATE TABLE `ai_configs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `ai_name` varchar(100) DEFAULT 'Cai',
  `tone` enum('professional','friendly','casual','custom') DEFAULT 'professional',
  `primary_objective` enum('generate_leads','book_appointments','sales','customer_support','qualification','custom') DEFAULT 'generate_leads',
  `info_to_collect_json` longtext DEFAULT NULL,
  `custom_instructions` text DEFAULT NULL,
  `qualification_rules_json` longtext DEFAULT NULL,
  `appointment_instructions` text DEFAULT NULL,
  `payment_instructions` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_id` (`company_id`),
  KEY `idx_company` (`company_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------
-- Table structure for `ai_summaries`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `ai_summaries`;
CREATE TABLE `ai_summaries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `conversation_id` int(11) NOT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `summary_text` text NOT NULL,
  `key_points_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`key_points_json`)),
  `recommended_action` varchar(255) NOT NULL,
  `generated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ais_company` (`company_id`),
  KEY `idx_ais_lead` (`lead_id`),
  KEY `fk_ais_conv` (`conversation_id`),
  CONSTRAINT `fk_ais_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ais_conv` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table structure for `ai_usage`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `ai_usage`;
CREATE TABLE `ai_usage` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `provider` varchar(50) NOT NULL DEFAULT 'gemini',
  `model` varchar(100) NOT NULL DEFAULT 'gemini-1.5-flash',
  `prompt_tokens` int(11) NOT NULL DEFAULT 0,
  `completion_tokens` int(11) NOT NULL DEFAULT 0,
  `latency_ms` int(11) NOT NULL DEFAULT 0,
  `estimated_cost_usd` decimal(10,6) NOT NULL DEFAULT 0.000000,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_aiu_company` (`company_id`),
  CONSTRAINT `fk_aiu_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table structure for `alert_logs`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `alert_logs`;
CREATE TABLE `alert_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `alert_type` varchar(100) NOT NULL,
  `recipient` varchar(100) NOT NULL,
  `recipient_type` enum('sales_agent','admin','customer') NOT NULL DEFAULT 'sales_agent',
  `channel` varchar(30) NOT NULL DEFAULT 'whatsapp',
  `content_preview` varchar(255) DEFAULT NULL,
  `sent_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_comp_lead_type` (`company_id`,`lead_id`,`alert_type`),
  KEY `idx_sent_at` (`sent_at`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------
-- Table structure for `appointments`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `appointments`;
CREATE TABLE `appointments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `assigned_user_id` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `appointment_type` varchar(100) DEFAULT 'consultation',
  `slot_datetime` datetime NOT NULL,
  `status` enum('scheduled','completed','cancelled','rescheduled') DEFAULT 'scheduled',
  `notes` text DEFAULT NULL,
  `meet_link` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_lead` (`lead_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `appointments`
INSERT INTO `appointments` (`id`, `company_id`, `customer_id`, `lead_id`, `assigned_user_id`, `title`, `appointment_type`, `slot_datetime`, `status`, `notes`, `meet_link`, `created_at`, `updated_at`) VALUES
(1, 3, 89, 85, NULL, 'Enterprise Platform Demo', 'consultation', '2026-10-03 15:50:13', 'cancelled', 'Enterprise Platform Demo & Custom Architecture Walkthrough', 'https://meet.google.com/cp-demo-vip', '2026-10-01 15:50:13', '2026-10-01 21:35:03'),
(2, 3, 90, 86, NULL, 'Enterprise Platform Demo', 'consultation', '2026-10-03 15:50:36', 'cancelled', 'Enterprise Platform Demo & Custom Architecture Walkthrough', 'https://meet.google.com/cp-demo-vip', '2026-10-01 15:50:36', '2026-10-01 21:35:02'),
(3, 3, 91, 87, NULL, 'Enterprise Platform Demo', 'consultation', '2026-10-03 17:29:23', 'cancelled', 'Enterprise Platform Demo & Custom Architecture Walkthrough', 'https://meet.google.com/cp-demo-vip', '2026-10-01 17:29:23', '2026-10-01 21:35:01'),
(4, 3, 92, 88, NULL, 'Enterprise Platform Demo', 'consultation', '2026-10-03 17:41:54', 'cancelled', 'Enterprise Platform Demo & Custom Architecture Walkthrough', 'https://meet.google.com/cp-demo-vip', '2026-10-01 17:41:54', '2026-10-01 21:35:00'),
(5, 3, 93, 89, 6, '1-on-1 Consultation: Amit Sharma & Rahul Sharma', 'consultation', '2026-10-02 16:30:00', 'cancelled', 'Looking to implement AI agent for real estate business', 'https://meet.google.com/cp-b37fd8e0', '2026-10-01 18:13:45', '2026-10-01 21:35:04'),
(6, 3, 96, 90, 6, '1-on-1 Consultation: Vikram Malhotra & Rahul Sharma', 'consultation', '2026-10-03 10:30:00', 'cancelled', 'Looking for CRM and WhatsApp continuity demo', 'https://meet.google.com/cp-73b57585', '2026-10-01 18:21:17', '2026-10-01 21:35:04'),
(7, 3, 97, 91, 6, '1-on-1 Consultation: Hii & Ayush', 'consultation', '2026-10-02 10:30:00', 'cancelled', '', 'https://meet.google.com/cp-ab077313', '2026-10-01 19:08:52', '2026-10-01 21:35:05'),
(8, 3, 84, NULL, NULL, 'Admissions & Solution Discovery Call (Test)', 'consultation', '2026-10-03 14:00:00', 'scheduled', 'Testing Calendar API sync', 'https://meet.google.com/test-room-123', '2026-10-02 00:27:27', '2026-10-02 00:27:27'),
(9, 3, 84, NULL, NULL, 'Admissions & Solution Discovery Call (Test)', 'consultation', '2026-10-03 14:00:00', 'scheduled', 'Testing Calendar API sync', 'https://meet.google.com/test-room-123', '2026-10-02 00:43:46', '2026-10-02 00:43:46');

-- ---------------------------------------------------------
-- Table structure for `audit_logs`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `target_type` varchar(100) DEFAULT NULL,
  `target_id` int(11) DEFAULT NULL,
  `details_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details_json`)),
  `ip_address` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_al_company` (`company_id`),
  KEY `idx_al_user` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table structure for `automations`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `automations`;
CREATE TABLE `automations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `trigger_event` varchar(100) NOT NULL,
  `action_type` varchar(100) NOT NULL,
  `wait_minutes` int(11) NOT NULL DEFAULT 0,
  `condition_key` varchar(100) DEFAULT NULL,
  `condition_value` varchar(100) DEFAULT NULL,
  `secondary_action` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_auto_company` (`company_id`),
  CONSTRAINT `fk_auto_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table structure for `blogs`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `blogs`;
CREATE TABLE `blogs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT 0,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `excerpt` text DEFAULT NULL,
  `content` longtext NOT NULL,
  `cover_image` varchar(500) DEFAULT NULL,
  `author_name` varchar(100) DEFAULT 'Ayush',
  `author_role` varchar(100) DEFAULT 'Founder & AI Architect',
  `author_avatar` varchar(500) DEFAULT NULL,
  `category` varchar(100) DEFAULT 'AI & Product Updates',
  `tags` varchar(255) DEFAULT 'Cai, AI Agents, Helpdesk, Automation',
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` text DEFAULT NULL,
  `status` enum('published','draft') DEFAULT 'published',
  `views_count` int(11) DEFAULT 148,
  `published_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_slug` (`slug`),
  KEY `idx_status` (`status`),
  KEY `idx_published` (`published_at`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `blogs`
INSERT INTO `blogs` (`id`, `company_id`, `title`, `slug`, `excerpt`, `content`, `cover_image`, `author_name`, `author_role`, `author_avatar`, `category`, `tags`, `meta_title`, `meta_description`, `status`, `views_count`, `published_at`, `created_at`, `updated_at`) VALUES
(1, 0, 'Meet Cai: Why We Built the World’s First Autonomous AI Helpdesk & SDR Agent', 'meet-cai-autonomous-ai-agent', 'Discover how Cai transforms visitor conversations into qualified pipeline, cuts ticket volume by 70%, and bridges autonomous AI support with instant WhatsApp sales handoffs.', '<p class=\"lead\">Today, we are thrilled to unveil <strong>Cai</strong>—an autonomous AI agent built from the ground up to unify 24/7 customer helpdesk resolution, predictive intent scoring, and immediate WhatsApp conversion for modern high-growth businesses.</p>\n\n<h2>The Broken Frontier of Traditional Support</h2>\n<p>For the past decade, businesses have been forced to choose between two unacceptable extremes:</p>\n<ul>\n  <li><strong>Dumb Keyword Chatbots:</strong> Frustrating rule-based trees that loop endlessly and drive high-intent prospective buyers away.</li>\n  <li><strong>Human-Only Support Queues:</strong> Expensive, slow, and unavailable outside 9-to-5 business hours when more than 62% of high-intent inquiries actually happen.</li>\n</ul>\n\n<p>Every minute a qualified buyer waits for a reply on your website, conversion probability plummets by over 390%. Modern customers do not want ticket numbers—they want authoritative answers, personalized consultative guidance, and a frictionless path to purchase.</p>\n\n<div class=\"blog-callout\">\n  <blockquote>\"We didn\'t just build another conversational chatbot. We engineered Cai to function as your best technical support engineer and top sales development representative (SDR) rolled into one autonomous system.\"</blockquote>\n  <cite>— Ayush, Founder & AI Architect at CuboidSoft</cite>\n</div>\n\n<h2>What Makes Cai Fundamentally Different?</h2>\n<p>Cai is powered by our proprietary dual-engine architecture combining low-latency neural retrieval (RAG) with real-time intent classification:</p>\n\n<h3>1. Sub-Second Autonomous Resolution</h3>\n<p>Trained and grounded exclusively on your company\'s live documents, FAQs, pricing sheets, and policies, Cai delivers nuanced, hallucination-free answers in under 800 milliseconds. It resolves over 70% of repetitive technical inquiries without ever burdening your human support staff.</p>\n\n<h3>2. Predictive Lead Scoring & Dossier Construction</h3>\n<p>As visitors interact with your website widget, Cai dynamically builds a rich commercial dossier in the background—evaluating urgency, budget readiness, target timeline, and specific objections. When intent crosses high-signal thresholds, Cai proactively offers personalized demos or booking links.</p>\n\n<h3>3. Seamless WhatsApp Continuity</h3>\n<p>Most website visitors leave before completing a purchase. With CuboidPilot\'s patent-pending omnichannel continuity, visitors can transition from the web widget to WhatsApp with a single tap. Their conversation history, questions, and context are preserved seamlessly, enabling your sales team to close deals on the channel buyers check 40+ times a day.</p>\n\n<h2>Proven Business Impact Across 140+ Deployments</h2>\n<p>Early access tenants deploying Cai across EdTech, SaaS, Healthcare, and Financial Services are seeing transformative operational metrics:</p>\n\n<div class=\"blog-stats-grid\">\n  <div class=\"blog-stat-box\">\n    <div class=\"stat-number\">72%</div>\n    <div class=\"stat-label\">Inquiries Resolved Autonomously</div>\n  </div>\n  <div class=\"blog-stat-box\">\n    <div class=\"stat-number\">&lt; 1s</div>\n    <div class=\"stat-label\">Average First Response Time</div>\n  </div>\n  <div class=\"blog-stat-box\">\n    <div class=\"stat-number\">3.4x</div>\n    <div class=\"stat-label\">Increase in Captured Lead Pipeline</div>\n  </div>\n  <div class=\"blog-stat-box\">\n    <div class=\"stat-number\">₹0</div>\n    <div class=\"stat-label\">Overtime / Weekend Support Overhead</div>\n  </div>\n</div>\n\n<h2>Getting Started with Cai in Under 5 Minutes</h2>\n<p>Deploying Cai requires no complex migration or weeks of training. Simply paste our lightweight, isolated script snippet into your website header, upload your knowledge base docs, and Cai is live 24/7 immediately.</p>\n\n<p>We are committed to making autonomous AI accessible to ambitious teams worldwide. Explore our risk-free 14-day trial and experience what autonomous support can do for your conversion rates.</p>', 'assets/blog/meet-cai-founder.png', 'Ayush', 'Founder & AI Architect', NULL, 'Product Launch & AI', 'Cai, AI Agents, Autonomous Helpdesk, Sales SDR, WhatsApp', 'Meet Cai: Autonomous AI Agent for Helpdesk, Support & Sales — CuboidPilot', 'Read how Cai, CuboidPilot\'s breakthrough autonomous AI agent, resolves 50%+ of inbound inquiries in under 1 second and automatically turns website traffic into enrolled customers.', 'published', 294, '2026-10-02 00:48:41', '2026-10-02 00:48:41', '2026-10-02 01:34:45');

-- ---------------------------------------------------------
-- Table structure for `companies`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `companies`;
CREATE TABLE `companies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(64) NOT NULL,
  `name` varchar(150) NOT NULL,
  `logo_url` varchar(255) DEFAULT NULL,
  `logo_dark_url` varchar(255) DEFAULT NULL,
  `logo_light_url` varchar(255) DEFAULT NULL,
  `slug` varchar(150) NOT NULL,
  `company_key` varchar(64) NOT NULL,
  `industry` varchar(100) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `country` varchar(50) NOT NULL DEFAULT 'India',
  `currency` varchar(10) NOT NULL DEFAULT 'INR',
  `timezone` varchar(50) NOT NULL DEFAULT 'Asia/Kolkata',
  `plan_id` int(11) DEFAULT NULL,
  `status` enum('active','trial','suspended','cancelled') NOT NULL DEFAULT 'trial',
  `onboarding_completed` tinyint(1) NOT NULL DEFAULT 0,
  `whatsapp_connected` tinyint(1) NOT NULL DEFAULT 0,
  `trial_started_at` datetime DEFAULT NULL,
  `trial_ends_at` datetime DEFAULT NULL,
  `plan_tier` varchar(50) DEFAULT 'free_trial',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `slug` (`slug`),
  UNIQUE KEY `company_key` (`company_key`),
  KEY `idx_company_key` (`company_key`),
  KEY `idx_plan_id` (`plan_id`),
  CONSTRAINT `fk_companies_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `companies`
INSERT INTO `companies` (`id`, `uuid`, `name`, `logo_url`, `logo_dark_url`, `logo_light_url`, `slug`, `company_key`, `industry`, `city`, `country`, `currency`, `timezone`, `plan_id`, `status`, `onboarding_completed`, `whatsapp_connected`, `trial_started_at`, `trial_ends_at`, `plan_tier`, `created_at`, `updated_at`) VALUES
(3, 'comp_b49330e379c34f76f946c3f7', 'CuboidSoft', 'assets/uploads/logos/logo_dark_comp_3_1790755745.png', 'assets/uploads/logos/logo_dark_comp_3_1790755745.png', 'assets/uploads/logos/logo_light_comp_3_1790755745.png', 'cuboidsoft', 'cp_live_cuboidsoft', 'Education', '', 'India', 'INR', 'Asia/Kolkata', 3, 'active', 1, 1, '2026-09-29 15:09:25', '2026-10-13 15:09:25', 'pro', '2026-09-29 15:09:25', '2026-10-01 02:58:11'),
(27, 'comp_c432e34bd355e08c1c8cc351', 'Arjun Mehta\'s Team', NULL, NULL, NULL, 'arjun-mehta-s-team-3379', 'cp_live_8b216358fa4d8d2e87fc846c76bb8d43', 'Education', 'Bengaluru', 'India', 'INR', 'Asia/Kolkata', NULL, 'trial', 1, 1, '2026-10-01 04:00:11', '2026-10-15 04:00:11', 'growth', '2026-10-01 04:00:11', '2026-10-01 04:04:05');

-- ---------------------------------------------------------
-- Table structure for `company_custom_fields`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `company_custom_fields`;
CREATE TABLE `company_custom_fields` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `field_key` varchar(64) NOT NULL,
  `field_label` varchar(100) NOT NULL,
  `field_type` enum('text','number','select','date') DEFAULT 'text',
  `options_json` text DEFAULT NULL,
  `is_required` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_comp_key` (`company_id`,`field_key`)
) ENGINE=InnoDB AUTO_INCREMENT=319 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------
-- Table structure for `conversations`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `conversations`;
CREATE TABLE `conversations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `visitor_session_id` int(11) DEFAULT NULL,
  `channel` enum('widget','whatsapp') NOT NULL DEFAULT 'widget',
  `status` enum('ai_handling','human_requested','human_active','resolved') NOT NULL DEFAULT 'ai_handling',
  `ownership` enum('ai','human') NOT NULL DEFAULT 'ai',
  `assigned_user_id` int(11) DEFAULT NULL,
  `unread_human` tinyint(1) NOT NULL DEFAULT 0,
  `last_message_preview` text DEFAULT NULL,
  `last_message_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_conv_company` (`company_id`),
  KEY `idx_conv_customer` (`customer_id`),
  KEY `idx_conv_assigned` (`assigned_user_id`),
  CONSTRAINT `fk_conv_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_conv_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_conv_user` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=80 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `conversations`
INSERT INTO `conversations` (`id`, `company_id`, `customer_id`, `visitor_session_id`, `channel`, `status`, `ownership`, `assigned_user_id`, `unread_human`, `last_message_preview`, `last_message_at`, `created_at`) VALUES
(74, 3, 95, NULL, 'widget', 'resolved', 'human', 6, 1, 'Hello Rahul, I have a quick question about enterprise deployment', '2026-10-01 21:37:18', '2026-10-01 18:13:45'),
(76, 3, 97, NULL, 'widget', '', 'human', 23, 0, 'Transferred to human consultant', '2026-10-01 18:38:27', '2026-10-01 18:38:27'),
(77, 3, 98, NULL, 'widget', 'ai_handling', 'ai', NULL, 0, 'We offer flexible tuition options, including zero-interest 3-month and 6-month EMI installment plans. Would you like our admissions advisor to share t', '2026-10-01 21:55:19', '2026-10-01 21:55:11'),
(78, 3, 99, NULL, 'widget', 'human_active', 'human', 51, 0, 'Transferred to human consultant', '2026-10-02 00:53:13', '2026-10-02 00:03:00');

-- ---------------------------------------------------------
-- Table structure for `customers`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `customer_uuid` varchar(64) NOT NULL,
  `name` varchar(150) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `whatsapp_number` varchar(30) DEFAULT NULL,
  `whatsapp_opt_in` tinyint(1) NOT NULL DEFAULT 0,
  `city` varchar(100) DEFAULT NULL,
  `preferred_language` varchar(20) NOT NULL DEFAULT 'hinglish',
  `notes` text DEFAULT NULL,
  `first_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `customer_uuid` (`customer_uuid`),
  KEY `idx_cust_company` (`company_id`),
  KEY `idx_cust_phone` (`phone`),
  KEY `idx_cust_whatsapp` (`whatsapp_number`),
  CONSTRAINT `fk_cust_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=102 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table structure for `feature_entitlements`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `feature_entitlements`;
CREATE TABLE `feature_entitlements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `feature_key` varchar(100) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `limit_value` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_company_feature` (`company_id`,`feature_key`),
  KEY `idx_feat_company` (`company_id`),
  CONSTRAINT `fk_feat_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table structure for `human_handoffs`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `human_handoffs`;
CREATE TABLE `human_handoffs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `lead_id` int(11) NOT NULL,
  `conversation_id` int(11) NOT NULL,
  `requested_by` enum('visitor','ai_rule','manual_trigger') NOT NULL,
  `reason` varchar(255) NOT NULL,
  `assigned_to_user_id` int(11) DEFAULT NULL,
  `status` enum('pending','accepted','in_progress','completed') NOT NULL DEFAULT 'pending',
  `response_time_seconds` int(11) DEFAULT NULL,
  `call_completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_hh_company` (`company_id`),
  KEY `idx_hh_lead` (`lead_id`),
  KEY `fk_hh_conv` (`conversation_id`),
  CONSTRAINT `fk_hh_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hh_conv` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hh_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table structure for `installments`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `installments`;
CREATE TABLE `installments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `customer_id` int(11) NOT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `installment_number` int(11) NOT NULL DEFAULT 1,
  `title` varchar(150) NOT NULL,
  `amount_inr` int(11) NOT NULL,
  `due_date` date NOT NULL,
  `status` enum('upcoming','pending','paid','overdue','cancelled') NOT NULL DEFAULT 'upcoming',
  `paid_at` datetime DEFAULT NULL,
  `reminder_days_before` int(11) NOT NULL DEFAULT 4,
  `reminder_sent_at` datetime DEFAULT NULL,
  `reminder_status` enum('none','scheduled','sent','delayed','acknowledged') NOT NULL DEFAULT 'none',
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_lead` (`lead_id`),
  KEY `idx_due_date` (`due_date`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------
-- Table structure for `intent_scores`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `intent_scores`;
CREATE TABLE `intent_scores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `lead_id` int(11) NOT NULL,
  `commercial_intent_score` int(11) NOT NULL DEFAULT 50,
  `urgency_score` int(11) NOT NULL DEFAULT 50,
  `budget_score` int(11) NOT NULL DEFAULT 50,
  `calculated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_is_company` (`company_id`),
  KEY `idx_is_lead` (`lead_id`),
  CONSTRAINT `fk_is_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_is_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table structure for `jobs`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `jobs`;
CREATE TABLE `jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `queue` varchar(50) NOT NULL DEFAULT 'default',
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload_json`)),
  `attempts` int(11) NOT NULL DEFAULT 0,
  `available_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_job_status` (`status`,`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table structure for `knowledge_sources`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `knowledge_sources`;
CREATE TABLE `knowledge_sources` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `type` enum('faq','website_url','text_doc','policy','ai_instruction') NOT NULL DEFAULT 'faq',
  `source_url` varchar(255) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT 'General',
  `content` longtext NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ks_company` (`company_id`),
  CONSTRAINT `fk_ks_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table structure for `lead_artifacts`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `lead_artifacts`;
CREATE TABLE `lead_artifacts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `lead_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `conversation_id` int(11) DEFAULT NULL,
  `customer_name` varchar(150) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `company_name` varchar(150) DEFAULT NULL,
  `requirement` text DEFAULT NULL,
  `interested_service` varchar(200) DEFAULT NULL,
  `budget` varchar(100) DEFAULT NULL,
  `timeline` varchar(100) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `intent` varchar(50) DEFAULT 'general',
  `current_stage` varchar(50) DEFAULT 'NEW',
  `buying_signals_json` longtext DEFAULT NULL,
  `objections_json` longtext DEFAULT NULL,
  `missing_information_json` longtext DEFAULT NULL,
  `conversation_summary` text DEFAULT NULL,
  `priority` varchar(20) DEFAULT 'LOW',
  `lead_score` int(11) DEFAULT 0,
  `priority_reason` text DEFAULT NULL,
  `recommended_action` text DEFAULT NULL,
  `assigned_salesperson_id` int(11) DEFAULT NULL,
  `preferred_channel` varchar(30) DEFAULT 'website',
  `ai_confidence` varchar(20) DEFAULT 'HIGH',
  `human_attention_status` varchar(50) DEFAULT 'none',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lead` (`lead_id`),
  KEY `idx_customer` (`customer_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------
-- Table structure for `lead_custom_field_values`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `lead_custom_field_values`;
CREATE TABLE `lead_custom_field_values` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `lead_id` int(11) NOT NULL,
  `field_id` int(11) NOT NULL,
  `field_value` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_lead_field` (`lead_id`,`field_id`),
  KEY `idx_company` (`company_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------
-- Table structure for `lead_events`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `lead_events`;
CREATE TABLE `lead_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `lead_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `event_type` enum('PHONE_SHARED','PRICING_ASKED','WHATSAPP_CONTINUED','EMI_ASKED','TRUST_CONCERN','HUMAN_REQUESTED','PAYMENT_LINK_CREATED','PAYMENT_LINK_OPENED','PAYMENT_FAILED','PAYMENT_COMPLETED','CUSTOMER_SILENT','HUMAN_TAKEOVER','LEAD_WON','LEAD_LOST') NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `event_data_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`event_data_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_le_company` (`company_id`),
  KEY `idx_le_lead` (`lead_id`),
  KEY `idx_le_cust` (`customer_id`),
  KEY `idx_le_type` (`event_type`),
  CONSTRAINT `fk_le_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_le_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=129 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table structure for `lead_scoring_rules`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `lead_scoring_rules`;
CREATE TABLE `lead_scoring_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `signal_type` varchar(50) NOT NULL,
  `condition_value` varchar(100) DEFAULT NULL,
  `score_delta` int(11) NOT NULL DEFAULT 10,
  `description` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company_signal` (`company_id`,`signal_type`)
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------
-- Table structure for `lead_stage_history`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `lead_stage_history`;
CREATE TABLE `lead_stage_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `lead_id` int(11) NOT NULL,
  `from_stage_id` int(11) DEFAULT NULL,
  `to_stage_id` int(11) NOT NULL,
  `changed_by_user_id` int(11) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lsh_company` (`company_id`),
  KEY `idx_lsh_lead` (`lead_id`),
  CONSTRAINT `fk_lsh_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lsh_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table structure for `leads`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `leads`;
CREATE TABLE `leads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `conversation_id` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `opportunity_value` int(11) NOT NULL DEFAULT 0,
  `total_amount` int(11) DEFAULT 0,
  `payment_type` enum('ONE_TIME','INSTALLMENT') DEFAULT 'ONE_TIME',
  `paid_amount` int(11) DEFAULT 0,
  `remaining_amount` int(11) DEFAULT 0,
  `next_due_date` date DEFAULT NULL,
  `commercial_status` enum('PENDING','PARTIAL','PAID','FAILED','OVERDUE') DEFAULT 'PENDING',
  `stage_id` int(11) DEFAULT NULL,
  `stage_name` varchar(50) DEFAULT 'NEW',
  `intent_level` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `priority` enum('LOW','MEDIUM','HIGH','URGENT') DEFAULT 'LOW',
  `is_radar_active` tinyint(1) NOT NULL DEFAULT 0,
  `human_attention_required` tinyint(1) DEFAULT 0,
  `human_attention_reason` text DEFAULT NULL,
  `human_attention_at` datetime DEFAULT NULL,
  `radar_reason` text DEFAULT NULL,
  `ai_summary` text DEFAULT NULL,
  `radar_recommended_action` varchar(255) DEFAULT NULL,
  `is_revenue_at_risk` tinyint(1) NOT NULL DEFAULT 0,
  `risk_category` varchar(100) DEFAULT NULL,
  `risk_amount` int(11) NOT NULL DEFAULT 0,
  `assigned_user_id` int(11) DEFAULT NULL,
  `source` varchar(100) NOT NULL DEFAULT 'Ask Anything Widget',
  `status` enum('open','won','lost') NOT NULL DEFAULT 'open',
  `last_activity_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lead_company` (`company_id`),
  KEY `idx_lead_cust` (`customer_id`),
  KEY `idx_lead_stage` (`stage_id`),
  KEY `idx_lead_radar` (`is_radar_active`),
  KEY `idx_lead_risk` (`is_revenue_at_risk`),
  KEY `fk_lead_user` (`assigned_user_id`),
  CONSTRAINT `fk_lead_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lead_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lead_stage` FOREIGN KEY (`stage_id`) REFERENCES `pipeline_stages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_lead_user` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=95 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `leads`
INSERT INTO `leads` (`id`, `company_id`, `customer_id`, `conversation_id`, `title`, `opportunity_value`, `total_amount`, `payment_type`, `paid_amount`, `remaining_amount`, `next_due_date`, `commercial_status`, `stage_id`, `stage_name`, `intent_level`, `priority`, `is_radar_active`, `human_attention_required`, `human_attention_reason`, `human_attention_at`, `radar_reason`, `ai_summary`, `radar_recommended_action`, `is_revenue_at_risk`, `risk_category`, `risk_amount`, `assigned_user_id`, `source`, `status`, `last_activity_at`, `created_at`, `updated_at`) VALUES
(91, 3, 97, 76, 'Hii (Appointment)', 45000, 0, 'ONE_TIME', 0, 0, NULL, 'PENDING', NULL, 'Proposal / Demo', 'high', 'HIGH', 0, 0, NULL, NULL, NULL, 'Booked consultation with Ayush for Oct 2, 2026 10:30 AM', NULL, 0, NULL, 0, NULL, 'WEBSITE_WIDGET', 'open', '2026-10-01 19:08:52', '2026-10-01 19:08:52', '2026-10-01 19:08:52'),
(92, 3, 98, 77, 'How does Cai qualify buyer intent and hand off to WhatsApp?', 45000, 0, 'ONE_TIME', 0, 0, NULL, 'PENDING', 71, 'Proposal / Demo', 'low', 'LOW', 0, 0, 'Asked about pricing, fees, or packages', NULL, 'Asked about pricing, fees, or packages', 'Inquired about program fees, installment plans and payment options.', 'Send fee schedule & payment breakdown', 0, NULL, 0, NULL, 'WEBSITE_WIDGET', 'open', '2026-10-01 22:07:55', '2026-10-01 21:55:11', '2026-10-01 22:07:55');

-- ---------------------------------------------------------
-- Table structure for `messages`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `messages`;
CREATE TABLE `messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `conversation_id` int(11) NOT NULL,
  `sender_type` enum('visitor','ai','human','system') NOT NULL,
  `sender_id` int(11) DEFAULT NULL,
  `message_text` text NOT NULL,
  `detected_intent` varchar(100) DEFAULT NULL,
  `detected_objection` varchar(100) DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_msg_company` (`company_id`),
  KEY `idx_msg_conv` (`conversation_id`),
  CONSTRAINT `fk_msg_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_msg_conv` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=321 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table structure for `notification_recipients`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `notification_recipients`;
CREATE TABLE `notification_recipients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `phone` varchar(30) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `role` varchar(100) NOT NULL DEFAULT 'Sales Closer',
  `categories_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`categories_json`)),
  `reporting_frequency` enum('instant','hourly','daily') NOT NULL DEFAULT 'instant',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_nr_company` (`company_id`),
  CONSTRAINT `fk_nr_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table structure for `notification_rules`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `notification_rules`;
CREATE TABLE `notification_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `event_category` varchar(100) NOT NULL,
  `recipient_id` int(11) NOT NULL,
  `condition_type` varchar(100) NOT NULL DEFAULT 'any',
  `condition_value` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_nrule_company` (`company_id`),
  KEY `idx_nrule_recipient` (`recipient_id`),
  CONSTRAINT `fk_nrule_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_nrule_recipient` FOREIGN KEY (`recipient_id`) REFERENCES `notification_recipients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table structure for `objections`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `objections`;
CREATE TABLE `objections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `lead_id` int(11) NOT NULL,
  `category` varchar(100) NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','handled','waived') NOT NULL DEFAULT 'active',
  `detected_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_obj_company` (`company_id`),
  KEY `idx_obj_lead` (`lead_id`),
  CONSTRAINT `fk_obj_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_obj_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table structure for `payment_events`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `payment_events`;
CREATE TABLE `payment_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_id` int(11) NOT NULL,
  `event_name` varchar(100) NOT NULL,
  `raw_payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`raw_payload_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pe_pay` (`payment_id`),
  CONSTRAINT `fk_pe_pay` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table structure for `payments`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `payments`;
CREATE TABLE `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `razorpay_order_id` varchar(100) DEFAULT NULL,
  `razorpay_payment_id` varchar(100) DEFAULT NULL,
  `amount_inr` int(11) NOT NULL DEFAULT 0,
  `currency` varchar(10) NOT NULL DEFAULT 'INR',
  `status` enum('created','link_opened','paid','failed','expired') NOT NULL DEFAULT 'created',
  `payment_link_url` varchar(255) DEFAULT NULL,
  `failure_reason` varchar(255) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pay_company` (`company_id`),
  KEY `idx_pay_cust` (`customer_id`),
  KEY `idx_pay_lead` (`lead_id`),
  CONSTRAINT `fk_pay_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pay_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table structure for `pipeline_stages`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `pipeline_stages`;
CREATE TABLE `pipeline_stages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `stage_order` int(11) NOT NULL DEFAULT 0,
  `color_code` varchar(20) NOT NULL DEFAULT '#4F46E5',
  `is_default` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_stage_company` (`company_id`),
  CONSTRAINT `fk_stage_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=128 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table structure for `plans`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `plans`;
CREATE TABLE `plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `price_monthly_inr` int(11) NOT NULL DEFAULT 0,
  `price_annual_inr` int(11) NOT NULL DEFAULT 0,
  `ai_conversations_limit` int(11) NOT NULL DEFAULT 500,
  `whatsapp_alerts_limit` int(11) NOT NULL DEFAULT 200,
  `team_members_limit` int(11) NOT NULL DEFAULT 3,
  `features_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`features_json`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `plans`
INSERT INTO `plans` (`id`, `code`, `name`, `price_monthly_inr`, `price_annual_inr`, `ai_conversations_limit`, `whatsapp_alerts_limit`, `team_members_limit`, `features_json`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'starter', 'Starter', 99, 948, 500, 250, 3, '{\"closing_radar\": true, \"revenue_at_risk\": true, \"ask_anything_widget\": true, \"whatsapp_alerts\": true, \"customer_memory\": true, \"reports\": \"basic\", \"custom_branding\": false}', 1, '2026-09-26 13:54:42', '2026-10-02 00:16:42'),
(2, 'growth', 'Growth', 199, 1908, 2500, 1500, 10, '{\"closing_radar\": true, \"revenue_at_risk\": true, \"ask_anything_widget\": true, \"whatsapp_alerts\": true, \"customer_memory\": true, \"reports\": \"advanced\", \"custom_branding\": true, \"multi_recipient_routing\": true, \"razorpay_direct\": true}', 1, '2026-09-26 13:54:42', '2026-10-02 00:16:42'),
(3, 'pro', 'Pro Scale', 349, 3348, 10000, 6000, 30, '{\"closing_radar\": true, \"revenue_at_risk\": true, \"ask_anything_widget\": true, \"whatsapp_alerts\": true, \"customer_memory\": true, \"reports\": \"executive\", \"custom_branding\": true, \"multi_recipient_routing\": true, \"razorpay_direct\": true, \"dedicated_sla\": true, \"audit_stream\": true}', 1, '2026-09-26 13:54:42', '2026-10-02 00:16:42');

-- ---------------------------------------------------------
-- Table structure for `products`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `price_inr` int(11) NOT NULL DEFAULT 0,
  `emi_available` tinyint(1) NOT NULL DEFAULT 1,
  `emi_starting_at_inr` int(11) NOT NULL DEFAULT 0,
  `description` text DEFAULT NULL,
  `payment_url` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_prod_company` (`company_id`),
  CONSTRAINT `fk_prod_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table structure for `subscriptions`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `subscriptions`;
CREATE TABLE `subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `status` enum('active','trial','past_due','cancelled') NOT NULL DEFAULT 'trial',
  `current_period_start` datetime NOT NULL,
  `current_period_end` datetime NOT NULL,
  `razorpay_subscription_id` varchar(100) DEFAULT NULL,
  `amount_inr` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sub_company` (`company_id`),
  KEY `fk_sub_plan` (`plan_id`),
  CONSTRAINT `fk_sub_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sub_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table structure for `support_tickets`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `support_tickets`;
CREATE TABLE `support_tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `priority` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `status` enum('open','investigating','resolved','closed') NOT NULL DEFAULT 'open',
  `message` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_st_company` (`company_id`),
  CONSTRAINT `fk_st_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table structure for `users`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(64) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `role` enum('super_admin','owner','admin','manager','sales_agent') NOT NULL DEFAULT 'owner',
  `job_title` varchar(100) DEFAULT 'Consultant',
  `department` enum('sales','support','technical','general') DEFAULT 'sales',
  `availability_status` enum('AVAILABLE','BUSY','OFFLINE','APPOINTMENT_ONLY') DEFAULT 'AVAILABLE',
  `is_instant_help_enabled` tinyint(1) DEFAULT 1,
  `is_appointment_enabled` tinyint(1) DEFAULT 1,
  `is_super_admin` tinyint(1) NOT NULL DEFAULT 0,
  `avatar_url` varchar(255) DEFAULT NULL,
  `linkedin_url` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_users_company` (`company_id`),
  KEY `idx_users_email` (`email`),
  CONSTRAINT `fk_users_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=57 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `users`
INSERT INTO `users` (`id`, `uuid`, `company_id`, `name`, `email`, `password_hash`, `phone`, `role`, `job_title`, `department`, `availability_status`, `is_instant_help_enabled`, `is_appointment_enabled`, `is_super_admin`, `avatar_url`, `linkedin_url`, `is_active`, `last_login_at`, `created_at`, `updated_at`) VALUES
(1, 'usr_superadmin_001', NULL, 'Ayush Varma', 'admin@cuboidpolit.com', '$2y$10$KKr8xShLf0DSMyT9w0ytz.ztSauFSeqFp92hhrV0Fi5UxELEEZXJi', '+91 98110 99999', 'super_admin', 'Solutions Architect', 'technical', 'AVAILABLE', 1, 1, 1, 'assets/avatar-ayush.png', 'https://linkedin.com/in/ayushman-varma', 1, '2026-10-01 02:32:33', '2026-09-26 13:54:42', '2026-10-02 11:17:30'),
(6, 'usr_57760422a1ccbc36bdbf19df', 3, 'Ayush', 'founder@cuboidsoft.in', '$2y$10$0xQHUSa6DjrojUEExFI4WeHWFQ74g5MFvRQBDowbd9CZb3Gzqg88K', '+91 98765 43210', 'owner', 'Founder & AI Architect', 'technical', 'AVAILABLE', 1, 1, 0, 'assets/avatar-ayush.png', 'https://linkedin.com/in/ayushman-varma', 1, '2026-10-02 01:25:37', '2026-09-29 15:09:25', '2026-10-02 11:17:30'),
(50, 'usr_owner_apex_02', 3, 'Rohan Mehta', 'owner@apexedtech.in', '$2y$10$ktPJXMgwY4gPxTXmkY22N.R8eUeTwExhq.m6ZAKBz17jNysRbJoe2', NULL, 'owner', 'Customer Success Lead', 'support', 'AVAILABLE', 1, 1, 0, NULL, 'https://linkedin.com/in/rohan-mehta', 1, NULL, '2026-10-01 19:34:55', '2026-10-02 11:17:30'),
(51, 'usr_manager_apex_03', 3, 'Neha Singhania', 'neha@apexedtech.in', '$2y$10$ktPJXMgwY4gPxTXmkY22N.R8eUeTwExhq.m6ZAKBz17jNysRbJoe2', NULL, 'manager', 'Head of Growth & Sales', 'sales', 'AVAILABLE', 1, 1, 0, NULL, 'https://linkedin.com/in/neha-singhania', 1, NULL, '2026-10-01 19:34:55', '2026-10-02 11:17:30'),
(52, 'usr_agent_apex_04', 3, 'Arjun Rao', 'arjun@apexedtech.in', '$2y$10$ktPJXMgwY4gPxTXmkY22N.R8eUeTwExhq.m6ZAKBz17jNysRbJoe2', NULL, 'sales_agent', 'Senior Account Executive', 'sales', 'AVAILABLE', 1, 1, 0, NULL, 'https://linkedin.com/in/arjun-rao', 1, NULL, '2026-10-01 19:34:55', '2026-10-02 11:17:30'),
(56, '42f802acdc8eb9aae7107913423dec50', 3, 'Priya Sharma', 'priya@cuboidpilot.com', '$2y$10$iza3q0Qhggq.7gO/gWJrLeqrQU0JJZWU6GEsPToo/h4KvSa..LUga', NULL, '', 'AI Integration Engineer', 'technical', 'AVAILABLE', 1, 1, 0, NULL, NULL, 1, NULL, '2026-10-02 01:25:45', '2026-10-02 01:25:45');

-- ---------------------------------------------------------
-- Table structure for `visitor_sessions`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `visitor_sessions`;
CREATE TABLE `visitor_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `session_token` varchar(64) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `referrer` varchar(255) DEFAULT NULL,
  `landing_page` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_token` (`session_token`),
  KEY `idx_sess_company` (`company_id`),
  KEY `idx_sess_customer` (`customer_id`),
  CONSTRAINT `fk_sess_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sess_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table structure for `webhook_events`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `webhook_events`;
CREATE TABLE `webhook_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `provider` varchar(50) NOT NULL,
  `event_name` varchar(100) NOT NULL,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload_json`)),
  `is_processed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_we_provider` (`provider`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table structure for `whatsapp_accounts`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `whatsapp_accounts`;
CREATE TABLE `whatsapp_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `phone_number_id` varchar(100) DEFAULT NULL,
  `waba_id` varchar(100) DEFAULT NULL,
  `display_number` varchar(30) NOT NULL,
  `status` enum('connected','disconnected','simulated') NOT NULL DEFAULT 'simulated',
  `quality_rating` varchar(20) NOT NULL DEFAULT 'GREEN',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_wa_company` (`company_id`),
  CONSTRAINT `fk_wa_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table structure for `whatsapp_handoffs`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `whatsapp_handoffs`;
CREATE TABLE `whatsapp_handoffs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `handoff_token` varchar(64) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `web_conversation_id` int(11) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `status` enum('pending','claimed','expired') NOT NULL DEFAULT 'pending',
  `created_at` datetime DEFAULT current_timestamp(),
  `claimed_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `handoff_token` (`handoff_token`),
  KEY `idx_token` (`handoff_token`),
  KEY `idx_company` (`company_id`)
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------
-- Table structure for `whatsapp_messages`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `whatsapp_messages`;
CREATE TABLE `whatsapp_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `recipient_phone` varchar(30) NOT NULL,
  `recipient_name` varchar(150) DEFAULT NULL,
  `message_type` enum('human_alert','radar_alert','risk_alert','summary','customer_message') NOT NULL,
  `template_name` varchar(100) DEFAULT NULL,
  `content` text NOT NULL,
  `status` enum('queued','sent','delivered','read','failed') NOT NULL DEFAULT 'sent',
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_wm_company` (`company_id`),
  KEY `idx_wm_phone` (`recipient_phone`),
  CONSTRAINT `fk_wm_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table structure for `widget_payments`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `widget_payments`;
CREATE TABLE `widget_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `conversation_id` int(11) DEFAULT NULL,
  `payment_method` enum('razorpay','bank_transfer','invoice','custom') DEFAULT 'razorpay',
  `amount_inr` int(11) NOT NULL,
  `currency` varchar(10) DEFAULT 'INR',
  `transaction_reference` varchar(100) DEFAULT NULL,
  `status` enum('pending','verified','failed') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_lead` (`lead_id`),
  KEY `idx_ref` (`transaction_reference`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------
-- Table structure for `widget_settings`
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `widget_settings`;
CREATE TABLE `widget_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `brand_name` varchar(100) NOT NULL,
  `assistant_name` varchar(100) DEFAULT 'Cai',
  `logo_url` varchar(255) DEFAULT NULL,
  `logo_dark_url` varchar(255) DEFAULT NULL,
  `logo_light_url` varchar(255) DEFAULT NULL,
  `accent_color` varchar(20) NOT NULL DEFAULT '#059669',
  `theme_mode` varchar(20) DEFAULT 'dark',
  `greeting_heading` varchar(255) NOT NULL DEFAULT 'Ask anything about our pricing, plans or EMI',
  `greeting_subheading` varchar(255) NOT NULL DEFAULT 'Instant answers powered by CuboidPolit AI',
  `position` enum('bottom_right','bottom_left') NOT NULL DEFAULT 'bottom_right',
  `enable_whatsapp_continue` tinyint(1) NOT NULL DEFAULT 1,
  `enable_appointments` tinyint(1) DEFAULT 1,
  `calendar_working_days` varchar(50) DEFAULT '1,2,3,4,5,6',
  `calendar_start_time` varchar(10) DEFAULT '10:00',
  `calendar_end_time` varchar(10) DEFAULT '18:00',
  `calendar_slot_duration` int(11) DEFAULT 30,
  `calendar_buffer_minutes` int(11) DEFAULT 0,
  `calendar_advance_days` int(11) DEFAULT 7,
  `calendar_meet_url` varchar(255) DEFAULT 'https://meet.google.com/cp-consult',
  `calendar_provider` varchar(50) DEFAULT 'native',
  `calendar_api_key` varchar(255) DEFAULT NULL,
  `calendar_booking_url` varchar(255) DEFAULT NULL,
  `calendar_webhook_url` varchar(255) DEFAULT NULL,
  `calendar_sync_enabled` tinyint(1) DEFAULT 1,
  `enable_human_help` tinyint(1) DEFAULT 1,
  `enable_payments` tinyint(1) DEFAULT 1,
  `razorpay_key_id` varchar(100) DEFAULT NULL,
  `razorpay_key_secret` varchar(100) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT 'HDFC Bank',
  `bank_account_holder` varchar(100) DEFAULT 'CuboidSoft Technologies',
  `bank_account_no` varchar(50) DEFAULT '50200088991122',
  `bank_ifsc` varchar(20) DEFAULT 'HDFC0001234',
  `bank_upi_id` varchar(50) DEFAULT 'cuboidsoft@hdfcbank',
  `bank_qr_url` varchar(255) DEFAULT NULL,
  `whatsapp_number` varchar(30) DEFAULT NULL,
  `require_phone_for_pricing` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `linkedin_ayush` varchar(255) DEFAULT 'https://linkedin.com/in/ayushman-varma',
  `linkedin_cai` varchar(255) DEFAULT 'https://linkedin.com/company/cuboidpilot',
  `linkedin_cuboidsoft` varchar(255) DEFAULT 'https://linkedin.com/company/cuboidsoft',
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_id` (`company_id`),
  CONSTRAINT `fk_ws_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `widget_settings`
INSERT INTO `widget_settings` (`id`, `company_id`, `brand_name`, `assistant_name`, `logo_url`, `logo_dark_url`, `logo_light_url`, `accent_color`, `theme_mode`, `greeting_heading`, `greeting_subheading`, `position`, `enable_whatsapp_continue`, `enable_appointments`, `calendar_working_days`, `calendar_start_time`, `calendar_end_time`, `calendar_slot_duration`, `calendar_buffer_minutes`, `calendar_advance_days`, `calendar_meet_url`, `calendar_provider`, `calendar_api_key`, `calendar_booking_url`, `calendar_webhook_url`, `calendar_sync_enabled`, `enable_human_help`, `enable_payments`, `razorpay_key_id`, `razorpay_key_secret`, `bank_name`, `bank_account_holder`, `bank_account_no`, `bank_ifsc`, `bank_upi_id`, `bank_qr_url`, `whatsapp_number`, `require_phone_for_pricing`, `created_at`, `updated_at`, `linkedin_ayush`, `linkedin_cai`, `linkedin_cuboidsoft`) VALUES
(3, 3, 'Cai', 'Cai', 'assets/uploads/logos/logo_dark_comp_3_1790755745.png', 'assets/uploads/logos/logo_dark_comp_3_1790755745.png', 'assets/uploads/logos/logo_light_comp_3_1790755745.png', '#111111', 'light', 'Hi there 👋\r\n\r\nYou are now speaking with Cai. How can I help?', 'Powered By CuboidPilot', 'bottom_right', 1, 1, '1,2,3,4,5,6', '10:00', '18:00', 30, 0, 7, 'https://meet.google.com/cp-consult', 'calcom', 'cal_live_testkey12345678', 'https://cal.com/cuboidpilot/30min', 'https://httpbin.org/post', 1, 1, 1, NULL, NULL, 'HDFC Bank', 'CuboidSoft Technologies', 50200088991122, 'HDFC0001234', 'cuboidsoft@hdfcbank', NULL, '+91 99999 88888', 1, '2026-09-29 15:09:25', '2026-10-02 00:27:27', 'https://linkedin.com/in/ayushman-varma', 'https://linkedin.com/company/cuboidpilot', 'https://linkedin.com/company/cuboidsoft'),
(21, 27, 'Arjun Mehta\'s Team AI', 'Cai', NULL, NULL, NULL, '#111111', 'dark', 'Hi there 👋 Welcome to Arjun Mehta\'s Team! How can I help you today?', 'Instant AI answers • Powered by CuboidPilot', 'bottom_right', 1, 1, '1,2,3,4,5,6', '10:00', '18:00', 30, 0, 7, 'https://meet.google.com/cp-consult', 'native', NULL, NULL, NULL, 1, 1, 1, NULL, NULL, 'HDFC Bank', 'CuboidSoft Technologies', 50200088991122, 'HDFC0001234', 'cuboidsoft@hdfcbank', NULL, '+91 98201 12345', 1, '2026-10-01 04:00:12', '2026-10-01 04:02:42', 'https://linkedin.com/in/ayushman-varma', 'https://linkedin.com/company/cuboidpilot', 'https://linkedin.com/company/cuboidsoft');

SET FOREIGN_KEY_CHECKS = 1;
