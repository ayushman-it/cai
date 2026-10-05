-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: cuboidpolit_db
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `ai_configs`
--

DROP TABLE IF EXISTS `ai_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ai_configs`
--

LOCK TABLES `ai_configs` WRITE;
/*!40000 ALTER TABLE `ai_configs` DISABLE KEYS */;
INSERT INTO `ai_configs` VALUES (1,3,'Cai','professional','generate_leads','[\"name\",\"phone\",\"email\",\"requirement\",\"budget\",\"timeline\"]','','{\"min_budget\":25000,\"urgent_timeline_days\":30,\"high_priority_keywords\":[\"pricing\",\"fee\",\"demo\",\"appointment\",\"urgent\",\"immediately\"]}','Admissions & discovery calls available Monday through Saturday 10:00 AM to 7:00 PM IST.','Accepts UPI, Netbanking, Credit Cards, and flexible 3-Month / 6-Month zero-interest EMI installment schedules.','2026-10-01 15:33:23','2026-10-01 21:49:52');
/*!40000 ALTER TABLE `ai_configs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ai_summaries`
--

DROP TABLE IF EXISTS `ai_summaries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ai_summaries`
--

LOCK TABLES `ai_summaries` WRITE;
/*!40000 ALTER TABLE `ai_summaries` DISABLE KEYS */;
/*!40000 ALTER TABLE `ai_summaries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ai_usage`
--

DROP TABLE IF EXISTS `ai_usage`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ai_usage`
--

LOCK TABLES `ai_usage` WRITE;
/*!40000 ALTER TABLE `ai_usage` DISABLE KEYS */;
/*!40000 ALTER TABLE `ai_usage` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `alert_logs`
--

DROP TABLE IF EXISTS `alert_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `alert_logs`
--

LOCK TABLES `alert_logs` WRITE;
/*!40000 ALTER TABLE `alert_logs` DISABLE KEYS */;
INSERT INTO `alert_logs` VALUES (3,3,83,'LEAD_ASSIGNED','+91 98765 43210','sales_agent','whatsapp',NULL,'2026-10-01 15:48:33'),(4,3,83,'LEAD_ASSIGNED','919876543210','sales_agent','whatsapp',NULL,'2026-10-01 15:48:33'),(5,3,84,'LEAD_ASSIGNED','+91 99887 76655','sales_agent','whatsapp',NULL,'2026-10-01 15:49:34'),(6,3,84,'LEAD_ASSIGNED','919876543210','sales_agent','whatsapp',NULL,'2026-10-01 15:49:34'),(7,3,85,'LEAD_ASSIGNED','+91 98765 43210','sales_agent','whatsapp',NULL,'2026-10-01 15:50:13'),(8,3,85,'LEAD_ASSIGNED','919876543210','sales_agent','whatsapp',NULL,'2026-10-01 15:50:13'),(9,3,86,'LEAD_ASSIGNED','+91 98765 43210','sales_agent','whatsapp',NULL,'2026-10-01 15:50:36'),(10,3,86,'LEAD_ASSIGNED','919876543210','sales_agent','whatsapp',NULL,'2026-10-01 15:50:36'),(11,3,87,'LEAD_ASSIGNED','+91 98765 43210','sales_agent','whatsapp',NULL,'2026-10-01 17:29:22'),(12,3,87,'LEAD_ASSIGNED','919876543210','sales_agent','whatsapp',NULL,'2026-10-01 17:29:22'),(13,3,88,'LEAD_ASSIGNED','+91 98765 43210','sales_agent','whatsapp',NULL,'2026-10-01 17:41:54'),(14,3,88,'LEAD_ASSIGNED','919876543210','sales_agent','whatsapp',NULL,'2026-10-01 17:41:54');
/*!40000 ALTER TABLE `alert_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `appointments`
--

DROP TABLE IF EXISTS `appointments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointments`
--

LOCK TABLES `appointments` WRITE;
/*!40000 ALTER TABLE `appointments` DISABLE KEYS */;
/*!40000 ALTER TABLE `appointments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES (1,3,6,'lead.assigned','lead',83,'{\"method\":\"round_robin\",\"agent\":\"Ayush\"}',NULL,'2026-10-01 15:48:33'),(2,3,24,'lead.assigned','lead',84,'{\"method\":\"round_robin\",\"agent\":\"Karan Sharma\"}',NULL,'2026-10-01 15:49:34'),(3,3,NULL,'whatsapp.connected','lead',84,'{\"description\":\"System event: whatsapp.connected\",\"data\":[]}',NULL,'2026-10-01 15:49:34'),(4,3,35,'lead.assigned','lead',85,'{\"method\":\"round_robin\",\"agent\":\"Rohan Mehta (Founder)\"}',NULL,'2026-10-01 15:50:13'),(5,3,NULL,'whatsapp.connected','lead',85,'{\"description\":\"System event: whatsapp.connected\",\"data\":[]}',NULL,'2026-10-01 15:50:13'),(6,3,NULL,'human_attention.required','lead',85,'{\"description\":\"System event: human_attention.required\",\"data\":[]}',NULL,'2026-10-01 15:50:13'),(7,3,35,'human.takeover','lead',85,'{\"description\":\"System event: human.takeover\",\"data\":[]}',NULL,'2026-10-01 15:50:13'),(8,3,NULL,'appointment.booked','lead',85,'{\"description\":\"System event: appointment.booked\",\"data\":[]}',NULL,'2026-10-01 15:50:13'),(9,3,35,'human.return_to_ai','lead',85,'{\"description\":\"System event: human.return_to_ai\",\"data\":[]}',NULL,'2026-10-01 15:50:13'),(10,3,NULL,'payment.completed','lead',85,'{\"description\":\"System event: payment.completed\",\"data\":[]}',NULL,'2026-10-01 15:50:13'),(11,3,36,'lead.assigned','lead',86,'{\"method\":\"round_robin\",\"agent\":\"Neha Singhania (Head of Sales)\"}',NULL,'2026-10-01 15:50:36'),(12,3,NULL,'whatsapp.connected','lead',86,'{\"description\":\"System event: whatsapp.connected\",\"data\":[]}',NULL,'2026-10-01 15:50:36'),(13,3,NULL,'human_attention.required','lead',86,'{\"description\":\"System event: human_attention.required\",\"data\":[]}',NULL,'2026-10-01 15:50:36'),(14,3,36,'human.takeover','lead',86,'{\"description\":\"System event: human.takeover\",\"data\":[]}',NULL,'2026-10-01 15:50:36'),(15,3,NULL,'appointment.booked','lead',86,'{\"description\":\"System event: appointment.booked\",\"data\":[]}',NULL,'2026-10-01 15:50:36'),(16,3,36,'human.return_to_ai','lead',86,'{\"description\":\"System event: human.return_to_ai\",\"data\":[]}',NULL,'2026-10-01 15:50:36'),(17,3,NULL,'payment.completed','lead',86,'{\"description\":\"System event: payment.completed\",\"data\":[]}',NULL,'2026-10-01 15:50:36'),(18,3,37,'lead.assigned','lead',87,'{\"method\":\"round_robin\",\"agent\":\"Arjun Rao (Senior Closer)\"}',NULL,'2026-10-01 17:29:22'),(19,3,NULL,'whatsapp.connected','lead',87,'{\"description\":\"System event: whatsapp.connected\",\"data\":[]}',NULL,'2026-10-01 17:29:23'),(20,3,NULL,'human_attention.required','lead',87,'{\"description\":\"System event: human_attention.required\",\"data\":[]}',NULL,'2026-10-01 17:29:23'),(21,3,37,'human.takeover','lead',87,'{\"description\":\"System event: human.takeover\",\"data\":[]}',NULL,'2026-10-01 17:29:23'),(22,3,NULL,'appointment.booked','lead',87,'{\"description\":\"System event: appointment.booked\",\"data\":[]}',NULL,'2026-10-01 17:29:23'),(23,3,37,'human.return_to_ai','lead',87,'{\"description\":\"System event: human.return_to_ai\",\"data\":[]}',NULL,'2026-10-01 17:29:23'),(24,3,NULL,'payment.completed','lead',87,'{\"description\":\"System event: payment.completed\",\"data\":[]}',NULL,'2026-10-01 17:29:23'),(25,3,6,'lead.assigned','lead',88,'{\"method\":\"round_robin\",\"agent\":\"Ayush\"}',NULL,'2026-10-01 17:41:54'),(26,3,NULL,'whatsapp.connected','lead',88,'{\"description\":\"System event: whatsapp.connected\",\"data\":[]}',NULL,'2026-10-01 17:41:54'),(27,3,NULL,'human_attention.required','lead',88,'{\"description\":\"System event: human_attention.required\",\"data\":[]}',NULL,'2026-10-01 17:41:54'),(28,3,6,'human.takeover','lead',88,'{\"description\":\"System event: human.takeover\",\"data\":[]}',NULL,'2026-10-01 17:41:54'),(29,3,NULL,'appointment.booked','lead',88,'{\"description\":\"System event: appointment.booked\",\"data\":[]}',NULL,'2026-10-01 17:41:54'),(30,3,6,'human.return_to_ai','lead',88,'{\"description\":\"System event: human.return_to_ai\",\"data\":[]}',NULL,'2026-10-01 17:41:54'),(31,3,NULL,'payment.completed','lead',88,'{\"description\":\"System event: payment.completed\",\"data\":[]}',NULL,'2026-10-01 17:41:54'),(32,3,6,'human.return_to_ai','conversation',78,'{\"description\":\"System event: human.return_to_ai\",\"data\":[]}',NULL,'2026-10-02 00:53:07');
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `automations`
--

DROP TABLE IF EXISTS `automations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `automations`
--

LOCK TABLES `automations` WRITE;
/*!40000 ALTER TABLE `automations` DISABLE KEYS */;
INSERT INTO `automations` VALUES (4,3,'Payment Stage Stalled Followup','lead_stage_payment','send_whatsapp_reminder',15,'stage_duration_min','15','tag_escalated',1,'2026-09-30 20:19:18'),(7,3,'Objection & Human Counselor Handoff','objection_trust_detected','tag_high_priority',0,'team','counselor','Alert on-call counselor on WhatsApp',1,'2026-09-30 20:43:31'),(9,3,'Automated Demo Session Welcome Sequence','stage_demo_booked','send_whatsapp_reminder',5,'customer','hot_leads','Hi {{name}}, your counseling demo is confirmed! We look forward to meeting you.',1,'2026-10-01 01:28:40');
/*!40000 ALTER TABLE `automations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `blogs`
--

DROP TABLE IF EXISTS `blogs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `blogs`
--

LOCK TABLES `blogs` WRITE;
/*!40000 ALTER TABLE `blogs` DISABLE KEYS */;
INSERT INTO `blogs` VALUES (1,0,'Meet Cai: Why We Built the World’s First Autonomous AI Helpdesk & SDR Agent','meet-cai-autonomous-ai-agent','Discover how Cai transforms visitor conversations into qualified pipeline, cuts ticket volume by 70%, and bridges autonomous AI support with instant WhatsApp sales handoffs.','<p class=\"lead\">Today, we are thrilled to unveil <strong>Cai</strong>—an autonomous AI agent built from the ground up to unify 24/7 customer helpdesk resolution, predictive intent scoring, and immediate WhatsApp conversion for modern high-growth businesses.</p>\n\n<h2>The Broken Frontier of Traditional Support</h2>\n<p>For the past decade, businesses have been forced to choose between two unacceptable extremes:</p>\n<ul>\n  <li><strong>Dumb Keyword Chatbots:</strong> Frustrating rule-based trees that loop endlessly and drive high-intent prospective buyers away.</li>\n  <li><strong>Human-Only Support Queues:</strong> Expensive, slow, and unavailable outside 9-to-5 business hours when more than 62% of high-intent inquiries actually happen.</li>\n</ul>\n\n<p>Every minute a qualified buyer waits for a reply on your website, conversion probability plummets by over 390%. Modern customers do not want ticket numbers—they want authoritative answers, personalized consultative guidance, and a frictionless path to purchase.</p>\n\n<div class=\"blog-callout\">\n  <blockquote>\"We didn\'t just build another conversational chatbot. We engineered Cai to function as your best technical support engineer and top sales development representative (SDR) rolled into one autonomous system.\"</blockquote>\n  <cite>— Ayush, Founder & AI Architect at CuboidSoft</cite>\n</div>\n\n<h2>What Makes Cai Fundamentally Different?</h2>\n<p>Cai is powered by our proprietary dual-engine architecture combining low-latency neural retrieval (RAG) with real-time intent classification:</p>\n\n<h3>1. Sub-Second Autonomous Resolution</h3>\n<p>Trained and grounded exclusively on your company\'s live documents, FAQs, pricing sheets, and policies, Cai delivers nuanced, hallucination-free answers in under 800 milliseconds. It resolves over 70% of repetitive technical inquiries without ever burdening your human support staff.</p>\n\n<h3>2. Predictive Lead Scoring & Dossier Construction</h3>\n<p>As visitors interact with your website widget, Cai dynamically builds a rich commercial dossier in the background—evaluating urgency, budget readiness, target timeline, and specific objections. When intent crosses high-signal thresholds, Cai proactively offers personalized demos or booking links.</p>\n\n<h3>3. Seamless WhatsApp Continuity</h3>\n<p>Most website visitors leave before completing a purchase. With CuboidPilot\'s patent-pending omnichannel continuity, visitors can transition from the web widget to WhatsApp with a single tap. Their conversation history, questions, and context are preserved seamlessly, enabling your sales team to close deals on the channel buyers check 40+ times a day.</p>\n\n<h2>Proven Business Impact Across 140+ Deployments</h2>\n<p>Early access tenants deploying Cai across EdTech, SaaS, Healthcare, and Financial Services are seeing transformative operational metrics:</p>\n\n<div class=\"blog-stats-grid\">\n  <div class=\"blog-stat-box\">\n    <div class=\"stat-number\">72%</div>\n    <div class=\"stat-label\">Inquiries Resolved Autonomously</div>\n  </div>\n  <div class=\"blog-stat-box\">\n    <div class=\"stat-number\">&lt; 1s</div>\n    <div class=\"stat-label\">Average First Response Time</div>\n  </div>\n  <div class=\"blog-stat-box\">\n    <div class=\"stat-number\">3.4x</div>\n    <div class=\"stat-label\">Increase in Captured Lead Pipeline</div>\n  </div>\n  <div class=\"blog-stat-box\">\n    <div class=\"stat-number\">₹0</div>\n    <div class=\"stat-label\">Overtime / Weekend Support Overhead</div>\n  </div>\n</div>\n\n<h2>Getting Started with Cai in Under 5 Minutes</h2>\n<p>Deploying Cai requires no complex migration or weeks of training. Simply paste our lightweight, isolated script snippet into your website header, upload your knowledge base docs, and Cai is live 24/7 immediately.</p>\n\n<p>We are committed to making autonomous AI accessible to ambitious teams worldwide. Explore our risk-free 14-day trial and experience what autonomous support can do for your conversion rates.</p>','assets/blog/meet-cai-founder.png','Ayush','Founder & AI Architect',NULL,'Product Launch & AI','Cai, AI Agents, Autonomous Helpdesk, Sales SDR, WhatsApp','Meet Cai: Autonomous AI Agent for Helpdesk, Support & Sales — CuboidPilot','Read how Cai, CuboidPilot\'s breakthrough autonomous AI agent, resolves 50%+ of inbound inquiries in under 1 second and automatically turns website traffic into enrolled customers.','published',294,'2026-10-02 00:48:41','2026-10-02 00:48:41','2026-10-02 01:34:45'),(3,0,'Gandhi Jayanti Special: Truth, Decentralized Technology & The Spirit of Self-Reliance','gandhi-jayanti-truth-technology-self-reliance','On October 2nd, we honor Mahatma Gandhi\'s enduring ideals — Satya (Truth), Swavalamban (Self-Reliance), and Sarvodaya (Welfare of All). Here is how these principles guide the future of autonomous, grounded AI at CuboidPilot.','<p class=\"lead\">\n  Every year on the 2nd of October, India and the world pause to honor the birth anniversary of <strong>Mahatma Gandhi</strong>. Beyond historical chronicles and textbooks, Gandhi was fundamentally a philosopher of ethical action, grassroots self-reliance (<em>Swavalamban</em>), and unwavering truth (<em>Satya</em>).\n</p>\n\n<p>\n  As we stand on the frontier of artificial intelligence, autonomous agents, and algorithmic automation, it is vital to ask: <em>How do Gandhi\'s century-old teachings inform modern technology?</em> At CuboidPilot, we believe ethical technology must never replace human dignity—it must uplift it.\n</p>\n\n<div class=\"blog-callout my-8 p-6 bg-amber-50 border-l-4 border-amber-600 rounded-r-lg\">\n  <blockquote class=\"italic text-stone-800 text-base font-serif\">\n    \"The future will depend on what we do in the present. Real freedom will come only when technology serves the humblest human being in the village, rather than concentrating power in the hands of a few.\"\n  </blockquote>\n  <cite class=\"block mt-2 text-xs font-semibold uppercase tracking-wider text-amber-900\">— Mahatma Gandhi</cite>\n</div>\n\n<h2>1. Satya: Grounded Truth vs Algorithmic Hallucination</h2>\n<p>\n  Mahatma Gandhi championed <em>Satya</em> as the absolute supreme virtue. In the era of Generative AI, truth is under unprecedented pressure. Many large language models hallucinate confident falsehoods, fabricate policies, and mislead customers.\n</p>\n<p>\n  When building <strong>Cai AI</strong>, our guiding tenet was strict grounding: if the agent cannot prove a statement from authentic company documentation, policy records, or live catalog tables, it will decline to fabricate answers. A technology that cannot honor truth will inevitably fail the public trust.\n</p>\n\n<h2>2. Swavalamban: Digital Self-Reliance for Indian Businesses</h2>\n<p>\n  Gandhi spent hours every day on the <em>Charkha</em> (spinning wheel)—not merely as an economic activity, but as a living symbol of self-sufficiency. In today’s hyper-connected economy, thousands of Indian D2C brands, small service providers, clinics, and manufacturing MSMEs depend heavily on expensive overseas software ecosystems that drain capital.\n</p>\n<p>\n  Our mission with CuboidPilot is to offer an authentic, enterprise-grade AI helpdesk built proudly from India, tailored with native UPI integrations, local language understanding, WhatsApp Cloud connectivity, and accessible pricing that empowers every homegrown founder to compete on a global scale.\n</p>\n\n<div class=\"blog-stats-grid my-8 grid grid-cols-1 sm:grid-cols-3 gap-4 text-center\">\n  <div class=\"p-5 bg-stone-50 border border-stone-200 rounded-lg\">\n    <div class=\"text-3xl font-bold text-stone-900 font-mono\">100%</div>\n    <div class=\"text-xs text-stone-500 mt-1 uppercase tracking-wider\">Grounded Truth Architecture</div>\n  </div>\n  <div class=\"p-5 bg-stone-50 border border-stone-200 rounded-lg\">\n    <div class=\"text-3xl font-bold text-emerald-600 font-mono\">0.6s</div>\n    <div class=\"text-xs text-stone-500 mt-1 uppercase tracking-wider\">Median AI Resolution Time</div>\n  </div>\n  <div class=\"p-5 bg-stone-50 border border-stone-200 rounded-lg\">\n    <div class=\"text-3xl font-bold text-stone-900 font-mono\">₹0</div>\n    <div class=\"text-xs text-stone-500 mt-1 uppercase tracking-wider\">Zero Setup Barrier for MSMEs</div>\n  </div>\n</div>\n\n<h2>3. Sarvodaya: Uplifting Everyone, Not Just the Elite</h2>\n<p>\n  The principle of <em>Sarvodaya</em> (progress of all) reminds us that innovation must never belong solely to billion-dollar enterprises. A local boutique hotel in Jaipur, an artisan cooperative in Varanasi, or a dental clinic in Kochi deserves the same customer resolution velocity as global tech giants.\n</p>\n<p>\n  By democratizing autonomous conversational AI with human oversight, we provide businesses with 24/7 responsiveness while allowing human team members to focus on complex, empathetic, and creative work.\n</p>\n\n<h2>Looking Ahead with Purpose</h2>\n<p>\n  As we commemorate Mahatma Gandhi today, we reaffirm our pledge: to build software that is honest in its assertions, humble in its service, and empowering in its economic impact.\n</p>\n<p>\n  Happy Gandhi Jayanti to everyone across India and around the globe.\n</p>','assets/blog/gandhi-jayanti-2026.png','Ayush','Founder & AI Architect','assets/avatar-ayush.png','Special Event','Gandhi Jayanti, Ethics in AI, Swadeshi, Technology, CuboidPilot, Self Reliance','Gandhi Jayanti: Truth, Decentralized Technology & Self-Reliance — CuboidPilot','Reflecting on Mahatma Gandhi\'s timeless values on October 2nd: How ethical AI, local data sovereignty, and human-centric automation build a self-reliant digital India.','published',344,'2026-10-02 08:00:00','2026-10-02 11:35:24','2026-10-02 13:38:21');
/*!40000 ALTER TABLE `blogs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `companies`
--

DROP TABLE IF EXISTS `companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `companies`
--

LOCK TABLES `companies` WRITE;
/*!40000 ALTER TABLE `companies` DISABLE KEYS */;
INSERT INTO `companies` VALUES (3,'comp_b49330e379c34f76f946c3f7','CuboidSoft','assets/uploads/logos/logo_dark_comp_3_1790755745.png','assets/uploads/logos/logo_dark_comp_3_1790755745.png','assets/uploads/logos/logo_light_comp_3_1790755745.png','cuboidsoft','cp_live_cuboidsoft','Education','','India','INR','Asia/Kolkata',3,'active',1,1,'2026-09-29 15:09:25','2026-10-13 15:09:25','pro','2026-09-29 15:09:25','2026-10-01 02:58:11');
/*!40000 ALTER TABLE `companies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `company_custom_fields`
--

DROP TABLE IF EXISTS `company_custom_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=328 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `company_custom_fields`
--

LOCK TABLES `company_custom_fields` WRITE;
/*!40000 ALTER TABLE `company_custom_fields` DISABLE KEYS */;
INSERT INTO `company_custom_fields` VALUES (4,3,'requirement_type','Project / Course Requirement','text',NULL,1,'2026-10-01 15:34:17'),(5,3,'budget_range','Budget Range','select','[\"\\u20b925,000 - \\u20b950,000\",\"\\u20b950,000 - \\u20b91,00,000\",\"\\u20b91,00,000+\"]',0,'2026-10-01 15:34:17'),(6,3,'target_timeline','Target Timeline','select','[\"Immediate (1-2 weeks)\",\"Within 30 Days\",\"1-3 Months\"]',0,'2026-10-01 15:34:17');
/*!40000 ALTER TABLE `company_custom_fields` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `conversations`
--

DROP TABLE IF EXISTS `conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `conversations`
--

LOCK TABLES `conversations` WRITE;
/*!40000 ALTER TABLE `conversations` DISABLE KEYS */;
/*!40000 ALTER TABLE `conversations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customers`
--

DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customers`
--

LOCK TABLES `customers` WRITE;
/*!40000 ALTER TABLE `customers` DISABLE KEYS */;
/*!40000 ALTER TABLE `customers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `feature_entitlements`
--

DROP TABLE IF EXISTS `feature_entitlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `feature_entitlements`
--

LOCK TABLES `feature_entitlements` WRITE;
/*!40000 ALTER TABLE `feature_entitlements` DISABLE KEYS */;
/*!40000 ALTER TABLE `feature_entitlements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `human_handoffs`
--

DROP TABLE IF EXISTS `human_handoffs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `human_handoffs`
--

LOCK TABLES `human_handoffs` WRITE;
/*!40000 ALTER TABLE `human_handoffs` DISABLE KEYS */;
/*!40000 ALTER TABLE `human_handoffs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `installments`
--

DROP TABLE IF EXISTS `installments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `installments`
--

LOCK TABLES `installments` WRITE;
/*!40000 ALTER TABLE `installments` DISABLE KEYS */;
/*!40000 ALTER TABLE `installments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `intent_scores`
--

DROP TABLE IF EXISTS `intent_scores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `intent_scores`
--

LOCK TABLES `intent_scores` WRITE;
/*!40000 ALTER TABLE `intent_scores` DISABLE KEYS */;
/*!40000 ALTER TABLE `intent_scores` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `knowledge_sources`
--

DROP TABLE IF EXISTS `knowledge_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `knowledge_sources`
--

LOCK TABLES `knowledge_sources` WRITE;
/*!40000 ALTER TABLE `knowledge_sources` DISABLE KEYS */;
INSERT INTO `knowledge_sources` VALUES (29,3,'website_url','http://localhost/cuboidpilot/index.html#','Website of Cai','General','CuboidPilot\nCuboidPilot\nMeet Cai\nProduct\nCustomers\nResources\nPricing\nLog in\nContact sales\nView demo\nStart free trial\nHire Cai\nOver 2,000 five-star reviews on\nG2\nHire Cai.\nThe breakthrough AI Agent\nfor support & sales.\nHire Cai to resolve customer questions 24/7 with zero hallucinations, qualify high-intent inbound leads, and hand off warm deals to your WhatsApp counselors in seconds.\n\nWork email\nHire Cai free\n14-day free trial\nZero hallucinations\nDeploy in 5 minutes\nTest Cai right now.\nReal-time answers, lead qualification & WhatsApp handoff.\nThis is the authentic Cai AI agent running live. Try chatting directly on the right to experience sub-second responses, hallucination-free precision, and instant qualification.\n\n< 0.8s\nMean response latency\n100%\nVerified grounding\n1-Click\nWhatsApp continuity\n\nMEET CAI — THE AUTONOMOUS AI AGENT\nHire Cai for your business.\nSupport agent & top SDR in one.\nCai resolves 50%+ of customer questions in under a second, scores commercial intent, and hands off warm deals to your team on WhatsApp.\n\n01 / AUTONOMOUS SUPPORT\n50%+ Resolution\nHire Cai for Customer Support\nAnswers customer questions 24/7 in under 0.8 seconds. Grounded strictly in your knowledge base—zero hallucinations.\n\n100% grounded answers from docs, FAQs & URLs\nMultilingual support across English, Hindi & regional languages\nZero wait queues for simultaneous visitors\n02 / LEAD QUALIFICATION\n3.4× Qualified\nHire Cai for Inbound Sales\nDiscovers buyer intent, budget, and timeline through natural conversation—no static forms required.\n\nReal-time commercial intent scoring (Cold, Warm, High)\nAuto-enriches lead profiles with company scale & email\nInstant sync to your CRM and Closing Radar dashboard\n03 / OMNICHANNEL PIPELINE\n0 Drop-offs\nHire Cai for WhatsApp Continuity\nResumes the conversation on WhatsApp when visitors leave your site, preserving full history and context.\n\n1-tap transition from desktop browser straight to WhatsApp\nFull chat history intact—visitors never have to re-explain\nDirect follow-up links dispatched automatically to your team\n04 / SMART TRIAGE\n< 60s Handoff\nHire Cai for Counselor Handoff\nAlerts your counselors with an AI executive briefing the moment high-intent enterprise deals are detected.\n\nInstant Closing Radar alerts for high-value prospects\nAI executive summary with key objections and pitch notes\nCounselors take over seamlessly on web or continue on WhatsApp\nWHY HIRE CAI\nHow Cai compares to legacy chatbots and human teams alone\nLegacy\nTraditional Chatbots\n—\nRigid decision trees & rule buttons\n—\nFrequent \"Sorry, I don\'t understand\" dead ends\n—\nHigh customer frustration & tab abandonment\n—\nNo WhatsApp continuity or CRM sync\nOverhead\nHuman Agents Alone\n—\nExpensive 24/7 night & weekend shift coverage\n—\nLong queue times during peak spikes\n—\nRepetitive Tier-1 queries burn out staff\n—\nUnqualified cold leads waste expensive AE time\nRecommended\nCai + Your Counselors\n50%+ instant resolution with zero hallucinations\n24/7/365 coverage in < 0.8s on Web & WhatsApp\nAutonomous commercial intent & lead scoring\nCounselors focus 100% on closing qualified deals\nDeploy Cai in under 5 minutes • Works with any CMS, Shopify, WordPress & Custom HTML\nHire Cai Free\n\nTest Cai Live\nTHE CUBOIDPILOT PLATFORM\nOne continuous conversation.\nFrom first question to conversion.\nCuboidPilot keeps Cai, your website conversations, leads, WhatsApp, and human counselors connected throughout the customer journey—with zero lost context.\n\n\n01\nWEBSITE\n\n02\nASK CAI\n\n03\nCAI REASONING\n\n04\nLEAD CAPTURED\n\n05\nWHATSAPP\n\n06\nHUMAN TEAM\n\n07\nCONVERTED\nSTAGE 01 — DISCOVERY\nVisitor arrives with an active question.\nInstead of searching through dense documentation or abandoning, the visitor encounters Cai\'s unassuming prompt.\n\nZero page slowdown (< 12KB lightweight script)\nURL: /solutions/enterprise\n01m 24s Session\nTrigger condition met\nUser lingered 45s on Enterprise SLA terms.\nSTRICT GROUNDING\nGive every visitor\nan instant grounded answer.\nCai learns from your approved business documents, URLs, and pricing sheets. It helps customers understand complex terms, packages, and technical requirements in 0.8 seconds without waiting for your team.\n\nExplore how Cai works\nCai\nCai AI Assistant\nTrained on 42 verified business documents\nOnline • Grounded\nDo you provide a plan for teams?\nCai\nYes. I can show you the available options. Our Team Plan includes shared inbox seats, automated routing, WhatsApp integration, and dedicated onboarding support.\nTeam Starter\n$89 /mo\nUp to 10 agent seats & WhatsApp.\nTeam Scale\nRecommended\n$189 /mo\nUnlimited seats, custom AI retraining.\nInstant response • 0.3s generation\nCaptured Prospect Profile\nQualified\nID: #LD-9821\nRS\nRahul Sharma\nAcme Technologies • VP of Engineering\n\nIntent: High\nInterest\nEnterprise Plan\nSource\nWebsite / Pricing\nStatus\nQualified\nTeam Size\n50–120 users\nRecommended:\nSchedule demo within 2 hours\nSchedule demo\nAutomatically synced to Salesforce, HubSpot & Webhook in real time.\nLEAD INTELLIGENCE\nKnow who\'s interested\nbefore they disappear.\nCuboidPilot understands intent during the conversation and turns high-intent visitors into structured leads. No manual data entry, no missed buying cues.\n\nIdentifies enterprise budget signals and organizational role.\nFilters out spam and low-intent queries automatically.\nCONTINUE THE CONVERSATION\nWebsite to WhatsApp.\nWithout starting again.\nMove an interested visitor from your website to WhatsApp while keeping their conversation context connected. No repetitive questions, no friction.\n\nStep 1 — Website Conversation\nDesktop Web\nCan you send me the enterprise tier contract and pricing breakdown?\nI can send this to your phone right now so you don\'t lose your progress.\nContinue on WhatsApp\nKeep full chat history intact\nTap & Sync\nZero Loss\nStep 2 — CuboidPilot WhatsApp\nMobile Sync\nContext linked: Rahul Sharma • Inquired about Enterprise Pricing\nCuboidPilot Assistant\n“Hi Rahul, continuing from our website conversation. Here is the link to review our Enterprise SLA & tier specifications.”\n\nPDF Document (2.4 MB)\nDelivered ✓✓\nReceived, thanks! Let\'s arrange a 15-minute briefing today.\nFull transcript mirrored to company dashboard\n100% compliant\nAI + YOUR TEAM\nLet AI handle the routine.\nBring humans in when it matters.\nCuboidPilot can handle common questions automatically and surface conversations that need personal attention. Your team never wastes time on repetitive FAQs.\n\nSee how handoff works\n1. AI Handling\n→\n2. High Intent Detected\n→\n3. Human Assistance Recommended\nConversation Summary & Handoff Card\nAssigned: Arjun Mehta\nCustomer\nRahul Sharma\nInterested In\nBusiness Plan\nKey Concern\nPricing / Multi-seat discount\nIntent Level\nHigh (Budget confirmed)\nRecommended Action\nCall today with customized volume discount breakdown\nTake over chat\nAM\nAssigned to Arjun Mehta • CX Specialist\nContext handover: 100% completed\nCLOSING RADAR\nKnow who needs\nyour attention now.\nCuboidPilot surfaces conversations with strong buying intent so your team knows where to focus. Never let hot deals sit unattended.\n\nLive Closing Radar\n2 Deals Pending Action\nAuto-refreshed 4 seconds ago\nRahul Sharma\nHigh Intent\nAcme Technologies • Session 18 mins ago\nPotential opportunity\n₹7,000\nAsked about pricing 3×\nVisited payment page\nNo payment yet (Cart idle)\nRecommended\nCall within 15 minutes\nCall\nWhatsApp\nAssign\nPriya Mehta\nDemo requested\nLumen Design Agency • Waiting 28 min\nTeam Size\n25 seats\nForm completed: Custom enterprise workflow\nWaiting 28 minutes for agent assignment\nPreferred time: Today 2:00 PM\nRecommended\nAssign sales agent\nAssign sales agent\nPREVENT PIPELINE LEAKAGE\nSee opportunities\nbefore they go cold.\nCuboidPilot monitors conversation drop-offs and flags dormant high-value pipeline before the customer turns to a competitor.\n\nRevenue requiring attention\n₹1,84,500\nAcross 28 high-intent conversations in the last 24 hours.\nPayment abandoned\n12\nCheckout drop-off\nFollow-up overdue\n8\n> 4 hrs unattended\nHuman requested\n5\nIn queue for AE\nHigh-intent inactive\n3\nIdle > 24 hrs\nRestrained alerts prioritize high lifetime value prospects over low-probability noise.\nConfigure alert thresholds →\nEND-TO-END VISIBILITY\nOne customer.\nOne continuous story.\nSee every milestone from initial page visit to final demo booking in a unified chronological timeline.\n\nRS\nRahul Sharma\nAcme Technologies • Lifecycle Duration: 38 mins\nDemo Scheduled\n10:42 AM\nPage Navigation\nVisited Pricing Page\nDirect referral from tech forum discussion.\n10:44 AM\nInbound Engagement\nAsked CuboidPilot about plans\n“What are your plans for teams with custom SOC2 compliance?”\n10:46 AM\nSystem AI\nHigh intent detected\nBudget confidence score: 94% • Matched Enterprise tier pattern.\n10:47 AM\nCRM Entity\nLead created\nEnriched with company domain info and synced to sales pipeline.\n10:50 AM\nChannel Pivot\nContinued on WhatsApp\nMobile sync handshake completed without session disconnect.\n11:02 AM\nHuman Handoff\nHuman agent joined\nArjun Mehta took over the conversation with full summarized briefing.\n11:20 AM\nMilestone\nDemo requested\nMeeting booked for Thursday 3:00 PM via calendar sync.\nCONVERSATION INTELLIGENCE\nUnderstand what\nconversations are doing.\nTrack query volume, resolution rates, lead qualification speed, and handoff efficiency through clean, restrained telemetry.\n\nTELEMETRY VIEW: LAST 30 DAYS\nPlatform Performance Overview\nAll Channels\nExport CSV\nConversations\n24,890\n+14.2%\nAI Responses\n92.4%\nAutonomous\nQualified Leads\n1,420\n+22.8%\nHuman Handoffs\n312\nTriage-only\nConversions\n18.4%\nLead-to-deal\nAvg Response\n0.8s\nInstantaneous\nWeekly Qualified Lead Trajectory\nTotal: 1,420 Qualified\nW1\nW2\nW3\nW4\nW5\nW6\nInbound Conversations\nQualified Conversion Spike\nPrecision Metric Filter\nVERSATILITY\nBuilt for businesses\nthat depend on conversations.\nWhether booking consultations, selling software, or clarifying complex catalogs, CuboidPilot powers customer communication that directly drives revenue.\n\n01\nSaaS & B2B Software\nAccelerate trial conversion, clarify API and security questions instantly, and route enterprise leads to SDRs in seconds.\n\n02\nEducation & Academies\nAnswer tuition, batch timing, and syllabus questions 24/7. Continue on WhatsApp to guide students through enrollment.\n\n03\nHealthcare & Clinics\nAddress procedure FAQs, insurance acceptance, and clinic timings while routing appointment scheduling requests seamlessly.\n\n04\nReal Estate & Developers\nQualify high-net-worth buyers by budget and location preference. Book site visits and dispatch brochures over WhatsApp immediately.\n\n05\nE-commerce & Retail\nTurn sizing queries and shipping doubts into sales. Recover abandoned checkout sessions before the cart goes cold.\n\n06\nProfessional Services\nAttorneys, financial advisors, and agencies filter inbound inquiries by project budget and schedule qualified discovery calls.\n\nTrusted by teams building better customer experiences\n\nNEXORA\nARC LABS\nNORTHSTAR\nKAIROS\nLUMEN\nPIXORA\n“CuboidPilot helps our team understand which conversations need us instead of treating every visitor the same.”\nArjun Mehta\nHead of Customer Experience, Nexora\nHIRE CAI TODAY\nTurn your website\ninto a conversation with Cai.\nHire Cai to answer customer questions 24/7, capture commercial intent, and connect qualified deals with your counselors in real time.\n\nHire Cai free trial\n\nTest Cai Live\nReady to deploy in under 5 minutes • Works with WordPress, Webflow, Shopify, Next.js & Custom HTML\n\nPRODUCT\nMeet Cai (AI Agent)\nHire Cai for Support\nHire Cai for Sales\nLead Intelligence\nWhatsApp Continuity\nClosing Radar\nAnalytics\nSOLUTIONS\nSales\nCustomer Support\nLead Generation\nEducation\nHealthcare\nSaaS\nRESOURCES\nHelp Center\nBlog\nDevelopers\nAPI\nDocumentation\nCOMPANY\nAbout\nContact\nCareers\nLEGAL\nPrivacy\nTerms\nSecurity\nCuboidPilot\nCuboidPilot\n© 2026 CuboidPilot. All rights reserved.',1,'2026-10-01 11:00:22','2026-10-01 11:00:22'),(30,3,'',NULL,'CuboidSoft & Cai AI Overview','General','CuboidSoft is an enterprise AI software development company and the creator of Cai (CuboidPilot). Cai is an autonomous customer service agent and sales development representative (SDR) that provides 24/7/365 instant query resolution, commercial lead qualification, omnichannel WhatsApp continuity, and in-widget Razorpay payments with zero hallucinations.',1,'2026-10-02 14:59:23','2026-10-02 14:59:23'),(31,3,'faq',NULL,'Cai Autonomous Capabilities & SLA','Product','Cai resolves over 76% of inbound customer support tickets autonomously with sub-second response latency (< 800ms). It grounds all responses strictly in verified company documentation configured in the SuperAdmin portal. If an inquiry requires human escalation or pricing approval, Cai gracefully hands off the customer to the human helpdesk or WhatsApp with 100% transcript continuity.',1,'2026-10-02 14:59:23','2026-10-02 14:59:23'),(32,3,'faq',NULL,'In-Widget Payments & Billing','Billing','Cai features native in-widget checkout supporting Razorpay, UPI, IMPS/NEFT bank transfers, and automated invoices. Customers can initiate and complete payments directly inside the chat widget without being redirected to third-party payment gateways.',1,'2026-10-02 14:59:23','2026-10-02 14:59:23'),(33,3,'',NULL,'JavaScript Embed & Deployment (5 Minutes)','Integration','Deploying Cai requires a single script tag: <script src=\"https://cai.cuboidsoft.in/widget.js\" data-company=\"cp_live_cuboidsoft\" async></script> placed before the closing </body> tag. It supports custom HTML, WordPress, Shopify, Webflow, React, Vue, Next.js, and any web platform.',1,'2026-10-02 14:59:23','2026-10-02 14:59:23');
/*!40000 ALTER TABLE `knowledge_sources` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lead_artifacts`
--

DROP TABLE IF EXISTS `lead_artifacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lead_artifacts`
--

LOCK TABLES `lead_artifacts` WRITE;
/*!40000 ALTER TABLE `lead_artifacts` DISABLE KEYS */;
/*!40000 ALTER TABLE `lead_artifacts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lead_custom_field_values`
--

DROP TABLE IF EXISTS `lead_custom_field_values`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lead_custom_field_values`
--

LOCK TABLES `lead_custom_field_values` WRITE;
/*!40000 ALTER TABLE `lead_custom_field_values` DISABLE KEYS */;
INSERT INTO `lead_custom_field_values` VALUES (1,3,85,5,'5,000 monthly inquiries','2026-10-01 15:50:13'),(2,3,86,5,'5,000 monthly inquiries','2026-10-01 15:50:36'),(3,3,87,5,'5,000 monthly inquiries','2026-10-01 17:29:23'),(4,3,88,5,'5,000 monthly inquiries','2026-10-01 17:41:54');
/*!40000 ALTER TABLE `lead_custom_field_values` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lead_events`
--

DROP TABLE IF EXISTS `lead_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=130 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lead_events`
--

LOCK TABLES `lead_events` WRITE;
/*!40000 ALTER TABLE `lead_events` DISABLE KEYS */;
INSERT INTO `lead_events` VALUES (126,3,92,98,'PHONE_SHARED','Lead created from website widget: How does Cai qualify buyer intent and hand off to WhatsApp? ()','{\"name\":\"How does Cai qualify buyer intent and hand off to WhatsApp?\",\"phone\":\"\",\"email\":\"\",\"source\":\"WEBSITE_WIDGET\"}','2026-10-01 21:55:11'),(127,3,92,98,'','Pipeline stage moved to New Inquiry',NULL,'2026-10-01 22:07:53'),(128,3,92,98,'','Pipeline stage moved to Proposal / Demo',NULL,'2026-10-01 22:07:55'),(129,3,95,102,'PHONE_SHARED','Lead created from website widget: Audit Test Visitor (9876543210)','{\"name\":\"Audit Test Visitor\",\"phone\":\"9876543210\",\"email\":\"audit@example.com\",\"source\":\"WEBSITE_WIDGET\"}','2026-10-02 14:37:10');
/*!40000 ALTER TABLE `lead_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lead_scoring_rules`
--

DROP TABLE IF EXISTS `lead_scoring_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lead_scoring_rules`
--

LOCK TABLES `lead_scoring_rules` WRITE;
/*!40000 ALTER TABLE `lead_scoring_rules` DISABLE KEYS */;
INSERT INTO `lead_scoring_rules` VALUES (1,3,'phone_shared',NULL,20,'Visitor provided phone number / contact info',1,'2026-10-01 15:33:23'),(2,3,'pricing_inquired',NULL,15,'Asked about pricing, fees, or packages',1,'2026-10-01 15:33:23'),(3,3,'budget_confirmed','50000',25,'Confirmed project/tuition budget >= ₹50,000',1,'2026-10-01 15:33:23'),(4,3,'timeline_urgent','30',20,'Needs project or enrollment within 30 days',1,'2026-10-01 15:33:23'),(5,3,'whatsapp_continued',NULL,15,'Continued conversation on WhatsApp',1,'2026-10-01 15:33:23'),(6,3,'demo_requested',NULL,20,'Requested demo or consultation meeting',1,'2026-10-01 15:33:23'),(7,3,'human_requested',NULL,25,'Explicitly requested human counselor / call',1,'2026-10-01 15:33:23'),(15,3,'phone_shared',NULL,20,'Visitor provided phone number / contact info',1,'2026-10-01 15:34:17'),(16,3,'pricing_inquired',NULL,15,'Asked about pricing, fees, or packages',1,'2026-10-01 15:34:17'),(17,3,'budget_confirmed','50000',25,'Confirmed project/tuition budget >= ₹50,000',1,'2026-10-01 15:34:17'),(18,3,'timeline_urgent','30',20,'Needs project or enrollment within 30 days',1,'2026-10-01 15:34:17'),(19,3,'whatsapp_continued',NULL,15,'Continued conversation on WhatsApp',1,'2026-10-01 15:34:17'),(20,3,'demo_requested',NULL,20,'Requested demo or consultation meeting',1,'2026-10-01 15:34:17'),(21,3,'human_requested',NULL,25,'Explicitly requested human counselor / call',1,'2026-10-01 15:34:17');
/*!40000 ALTER TABLE `lead_scoring_rules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lead_stage_history`
--

DROP TABLE IF EXISTS `lead_stage_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lead_stage_history`
--

LOCK TABLES `lead_stage_history` WRITE;
/*!40000 ALTER TABLE `lead_stage_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `lead_stage_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leads`
--

DROP TABLE IF EXISTS `leads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leads`
--

LOCK TABLES `leads` WRITE;
/*!40000 ALTER TABLE `leads` DISABLE KEYS */;
/*!40000 ALTER TABLE `leads` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `messages`
--

DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `messages`
--

LOCK TABLES `messages` WRITE;
/*!40000 ALTER TABLE `messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notification_recipients`
--

DROP TABLE IF EXISTS `notification_recipients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notification_recipients`
--

LOCK TABLES `notification_recipients` WRITE;
/*!40000 ALTER TABLE `notification_recipients` DISABLE KEYS */;
/*!40000 ALTER TABLE `notification_recipients` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notification_rules`
--

DROP TABLE IF EXISTS `notification_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notification_rules`
--

LOCK TABLES `notification_rules` WRITE;
/*!40000 ALTER TABLE `notification_rules` DISABLE KEYS */;
/*!40000 ALTER TABLE `notification_rules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `objections`
--

DROP TABLE IF EXISTS `objections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `objections`
--

LOCK TABLES `objections` WRITE;
/*!40000 ALTER TABLE `objections` DISABLE KEYS */;
/*!40000 ALTER TABLE `objections` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_events`
--

DROP TABLE IF EXISTS `payment_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_events`
--

LOCK TABLES `payment_events` WRITE;
/*!40000 ALTER TABLE `payment_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pipeline_stages`
--

DROP TABLE IF EXISTS `pipeline_stages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pipeline_stages`
--

LOCK TABLES `pipeline_stages` WRITE;
/*!40000 ALTER TABLE `pipeline_stages` DISABLE KEYS */;
INSERT INTO `pipeline_stages` VALUES (68,3,'New Inquiry','new',1,'#4F46E5',1),(69,3,'Contacted','contacted',2,'#0EA5E9',1),(70,3,'Qualified','qualified',3,'#F59E0B',1),(71,3,'Proposal / Demo','proposal',4,'#8B5CF6',1),(72,3,'Won / Enrolled','won',5,'#10B981',1),(73,3,'Lost','lost',6,'#EF4444',1);
/*!40000 ALTER TABLE `pipeline_stages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `plans`
--

DROP TABLE IF EXISTS `plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plans`
--

LOCK TABLES `plans` WRITE;
/*!40000 ALTER TABLE `plans` DISABLE KEYS */;
INSERT INTO `plans` VALUES (1,'starter','Starter',99,948,500,250,3,'{\"closing_radar\": true, \"revenue_at_risk\": true, \"ask_anything_widget\": true, \"whatsapp_alerts\": true, \"customer_memory\": true, \"reports\": \"basic\", \"custom_branding\": false}',1,'2026-09-26 13:54:42','2026-10-02 00:16:42'),(2,'growth','Growth',199,1908,2500,1500,10,'{\"closing_radar\": true, \"revenue_at_risk\": true, \"ask_anything_widget\": true, \"whatsapp_alerts\": true, \"customer_memory\": true, \"reports\": \"advanced\", \"custom_branding\": true, \"multi_recipient_routing\": true, \"razorpay_direct\": true}',1,'2026-09-26 13:54:42','2026-10-02 00:16:42'),(3,'pro','Pro Scale',349,3348,10000,6000,30,'{\"closing_radar\": true, \"revenue_at_risk\": true, \"ask_anything_widget\": true, \"whatsapp_alerts\": true, \"customer_memory\": true, \"reports\": \"executive\", \"custom_branding\": true, \"multi_recipient_routing\": true, \"razorpay_direct\": true, \"dedicated_sla\": true, \"audit_stream\": true}',1,'2026-09-26 13:54:42','2026-10-02 00:16:42');
/*!40000 ALTER TABLE `plans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subscriptions`
--

DROP TABLE IF EXISTS `subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subscriptions`
--

LOCK TABLES `subscriptions` WRITE;
/*!40000 ALTER TABLE `subscriptions` DISABLE KEYS */;
INSERT INTO `subscriptions` VALUES (3,3,3,'active','2026-09-30 13:51:32','2027-09-30 13:51:32',NULL,29999,'2026-09-30 13:51:32','2026-09-30 13:51:32');
/*!40000 ALTER TABLE `subscriptions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `support_tickets`
--

DROP TABLE IF EXISTS `support_tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `support_tickets`
--

LOCK TABLES `support_tickets` WRITE;
/*!40000 ALTER TABLE `support_tickets` DISABLE KEYS */;
/*!40000 ALTER TABLE `support_tickets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'usr_superadmin_001',NULL,'Ayush Varma','admin@cuboidpolit.com','$2y$10$KKr8xShLf0DSMyT9w0ytz.ztSauFSeqFp92hhrV0Fi5UxELEEZXJi','+91 98110 99999','super_admin','Solutions Architect','technical','AVAILABLE',1,1,1,'assets/avatar-ayush.png','https://linkedin.com/in/ayushman-varma',1,'2026-10-02 15:04:31','2026-09-26 13:54:42','2026-10-02 15:04:31'),(6,'usr_57760422a1ccbc36bdbf19df',3,'Ayush','founder@cuboidsoft.in','$2y$10$0xQHUSa6DjrojUEExFI4WeHWFQ74g5MFvRQBDowbd9CZb3Gzqg88K','+91 98765 43210','owner','Founder & AI Architect','technical','AVAILABLE',1,1,0,'assets/avatar-ayush.png','https://linkedin.com/in/ayushman-varma',1,'2026-10-02 15:04:31','2026-09-29 15:09:25','2026-10-02 15:04:31'),(56,'42f802acdc8eb9aae7107913423dec50',3,'Priya Sharma','priya@cuboidpilot.com','$2y$10$iza3q0Qhggq.7gO/gWJrLeqrQU0JJZWU6GEsPToo/h4KvSa..LUga',NULL,'','AI Integration Engineer','technical','AVAILABLE',1,1,0,NULL,NULL,1,NULL,'2026-10-02 01:25:45','2026-10-02 01:25:45');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `visitor_sessions`
--

DROP TABLE IF EXISTS `visitor_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `visitor_sessions`
--

LOCK TABLES `visitor_sessions` WRITE;
/*!40000 ALTER TABLE `visitor_sessions` DISABLE KEYS */;
INSERT INTO `visitor_sessions` VALUES (27,3,'sess_1qmwvdrdmuolhbmr',NULL,NULL,NULL,NULL,NULL,'2026-10-01 03:41:48'),(28,3,'sess_u2p28zd5mup2do2i',NULL,NULL,NULL,NULL,NULL,'2026-10-01 10:33:12'),(32,3,'sess_test123',95,NULL,NULL,NULL,NULL,'2026-10-01 18:13:45'),(33,3,'sess_v_test',NULL,NULL,NULL,NULL,NULL,'2026-10-01 18:21:17'),(34,3,'sess_c8b2wlh5mupgzg8w',97,NULL,NULL,NULL,NULL,'2026-10-01 18:38:27'),(35,3,'sess_d10zskgymupqptbx',98,NULL,NULL,NULL,NULL,'2026-10-01 21:55:11'),(36,3,'sess_se6xjjmnmupu31hw',99,NULL,NULL,NULL,NULL,'2026-10-02 00:03:00'),(37,3,'test_audit_session_123',102,NULL,NULL,NULL,NULL,'2026-10-02 14:37:10');
/*!40000 ALTER TABLE `visitor_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `webhook_events`
--

DROP TABLE IF EXISTS `webhook_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `webhook_events`
--

LOCK TABLES `webhook_events` WRITE;
/*!40000 ALTER TABLE `webhook_events` DISABLE KEYS */;
INSERT INTO `webhook_events` VALUES (1,'whatsapp','message_processed','{\"sender_phone\":\"+919820045678\",\"message\":\"Hi, I have a question about zero-interest EMI #CP-1-15B697\",\"waba_id\":\"waba_9918273645\"}',1,'2026-09-29 22:11:22'),(2,'whatsapp','message_processed','{\"sender_phone\":\"+919820045678\",\"message\":\"Hi, I have a question about zero-interest EMI #CP-1-30E027\",\"waba_id\":\"waba_9918273645\"}',1,'2026-09-29 22:13:40'),(3,'whatsapp','message_processed','{\"sender_phone\":\"+919820045678\",\"message\":\"Hi, I have a question about zero-interest EMI #CP-1-6537A7\",\"waba_id\":\"waba_9918273645\"}',1,'2026-09-29 22:14:23'),(4,'whatsapp','message_processed','{\"sender_phone\":\"+919820045678\",\"message\":\"Hi, I have a question about zero-interest EMI #CP-1-83320E\",\"waba_id\":\"waba_9918273645\"}',1,'2026-09-29 22:15:10');
/*!40000 ALTER TABLE `webhook_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `whatsapp_accounts`
--

DROP TABLE IF EXISTS `whatsapp_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `whatsapp_accounts`
--

LOCK TABLES `whatsapp_accounts` WRITE;
/*!40000 ALTER TABLE `whatsapp_accounts` DISABLE KEYS */;
INSERT INTO `whatsapp_accounts` VALUES (12,3,'waba_phone_comp3','waba_comp3','+91 98400 12890','connected','GREEN','2026-09-30 13:51:32','2026-09-30 13:51:32');
/*!40000 ALTER TABLE `whatsapp_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `whatsapp_handoffs`
--

DROP TABLE IF EXISTS `whatsapp_handoffs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `whatsapp_handoffs`
--

LOCK TABLES `whatsapp_handoffs` WRITE;
/*!40000 ALTER TABLE `whatsapp_handoffs` DISABLE KEYS */;
INSERT INTO `whatsapp_handoffs` VALUES (19,3,'wh_357c7d69ebeec84211f1453d2fcb5b49',60,57,46,'','pending','2026-10-01 03:41:51',NULL,'2026-10-01 05:41:51'),(20,3,'wh_1dd6a92ecbe900c4bc24b432fd0cdb37',66,63,48,'9238695500','pending','2026-10-01 10:34:45',NULL,'2026-10-01 12:34:45'),(21,3,'wh_0c35b0b599a86d50cb7c1cda469c19ab',66,63,48,'9238695500','pending','2026-10-01 10:36:26',NULL,'2026-10-01 12:36:26'),(22,3,'wh_876eef0f90c40dfd9423f0f1dd40bc23',68,65,50,NULL,'pending','2026-10-01 10:45:23',NULL,'2026-10-01 12:45:23'),(23,3,'wh_3dd69cabdfc0cd2c461093209278b91d',70,67,52,NULL,'pending','2026-10-01 10:48:00',NULL,'2026-10-01 12:48:00'),(24,3,'wh_ffaa1afe62433226243bc1cfc1ad48a4',71,68,53,NULL,'pending','2026-10-01 10:48:01',NULL,'2026-10-01 12:48:01'),(25,3,'wh_abbbf7152332bbab72d06249f297fb1d',66,63,48,'9238695500','pending','2026-10-01 10:55:05',NULL,'2026-10-01 12:55:05'),(26,3,'wh_295f988ec6b879350a250a626bc09f24',83,80,65,'8224973413','pending','2026-10-01 11:05:09',NULL,'2026-10-01 13:05:09'),(27,3,'wh_94d6699f7f92',88,84,69,'+91 99887 76655','pending','2026-10-01 15:49:34',NULL,'2026-10-02 15:49:34'),(28,3,'wh_f4344a355c87',89,85,70,'+91 99887 76655','pending','2026-10-01 15:50:13',NULL,'2026-10-02 15:50:13'),(29,3,'wh_242a72c02ddd',90,86,71,'+91 99887 76655','pending','2026-10-01 15:50:36',NULL,'2026-10-02 15:50:36'),(30,3,'wh_5a961e7fd750',91,87,72,'+91 99887 76655','pending','2026-10-01 17:29:23',NULL,'2026-10-02 17:29:23'),(31,3,'wh_d350f00673c6',92,88,73,'+91 99887 76655','pending','2026-10-01 17:41:54',NULL,'2026-10-02 17:41:54');
/*!40000 ALTER TABLE `whatsapp_handoffs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `whatsapp_messages`
--

DROP TABLE IF EXISTS `whatsapp_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `whatsapp_messages`
--

LOCK TABLES `whatsapp_messages` WRITE;
/*!40000 ALTER TABLE `whatsapp_messages` DISABLE KEYS */;
INSERT INTO `whatsapp_messages` VALUES (14,3,'+91 98765 43210','Ayush','human_alert',NULL,'🎯 NEW LEAD ASSIGNED TO YOU\nCompany: CuboidSoft\nProspect: Vikram Malhotra (+91 99887 76655)\nDeal Value: ₹50,000 • Priority: MEDIUM\n\nExecutive Context:\nVikram is exploring Enterprise tier for 5,000 monthly inquiries.\n\nRecommended Action:\nSchedule VIP product walkthrough and send commercial proposal.\nView Lead: https://cuboidpilot.app/app/lead-detail.html?id=83','sent','{\"alert_type\":\"LEAD_ASSIGNED\",\"lead_id\":83,\"deal_value\":50000}','2026-10-01 15:48:33'),(15,3,'919876543210','Support Rep','human_alert',NULL,'🎯 NEW LEAD ASSIGNED TO YOU\nCompany: CuboidSoft\nProspect: Vikram Malhotra (+91 99887 76655)\nDeal Value: ₹50,000 • Priority: MEDIUM\n\nExecutive Context:\nVikram is exploring Enterprise tier for 5,000 monthly inquiries.\n\nRecommended Action:\nSchedule VIP product walkthrough and send commercial proposal.\nView Lead: https://cuboidpilot.app/app/lead-detail.html?id=83','sent','{\"alert_type\":\"LEAD_ASSIGNED\",\"lead_id\":83,\"deal_value\":50000}','2026-10-01 15:48:33'),(16,3,'+91 99887 76655','Karan Sharma','human_alert',NULL,'🎯 NEW LEAD ASSIGNED TO YOU\nCompany: CuboidSoft\nProspect: Vikram Malhotra (+91 99887 76655)\nDeal Value: ₹50,000 • Priority: MEDIUM\n\nExecutive Context:\nVikram is exploring Enterprise tier for 5,000 monthly inquiries.\n\nRecommended Action:\nSchedule VIP product walkthrough and send commercial proposal.\nView Lead: https://cuboidpilot.app/app/lead-detail.html?id=84','sent','{\"alert_type\":\"LEAD_ASSIGNED\",\"lead_id\":84,\"deal_value\":50000}','2026-10-01 15:49:34'),(17,3,'919876543210','Support Rep','human_alert',NULL,'🎯 NEW LEAD ASSIGNED TO YOU\nCompany: CuboidSoft\nProspect: Vikram Malhotra (+91 99887 76655)\nDeal Value: ₹50,000 • Priority: MEDIUM\n\nExecutive Context:\nVikram is exploring Enterprise tier for 5,000 monthly inquiries.\n\nRecommended Action:\nSchedule VIP product walkthrough and send commercial proposal.\nView Lead: https://cuboidpilot.app/app/lead-detail.html?id=84','sent','{\"alert_type\":\"LEAD_ASSIGNED\",\"lead_id\":84,\"deal_value\":50000}','2026-10-01 15:49:34'),(18,3,'+91 98765 43210','Rohan Mehta (Founder)','human_alert',NULL,'🎯 NEW LEAD ASSIGNED TO YOU\nCompany: CuboidSoft\nProspect: Vikram Malhotra (+91 99887 76655)\nDeal Value: ₹50,000 • Priority: MEDIUM\n\nExecutive Context:\nVikram is exploring Enterprise tier for 5,000 monthly inquiries.\n\nRecommended Action:\nSchedule VIP product walkthrough and send commercial proposal.\nView Lead: https://cuboidpilot.app/app/lead-detail.html?id=85','sent','{\"alert_type\":\"LEAD_ASSIGNED\",\"lead_id\":85,\"deal_value\":50000}','2026-10-01 15:50:13'),(19,3,'919876543210','Support Rep','human_alert',NULL,'🎯 NEW LEAD ASSIGNED TO YOU\nCompany: CuboidSoft\nProspect: Vikram Malhotra (+91 99887 76655)\nDeal Value: ₹50,000 • Priority: MEDIUM\n\nExecutive Context:\nVikram is exploring Enterprise tier for 5,000 monthly inquiries.\n\nRecommended Action:\nSchedule VIP product walkthrough and send commercial proposal.\nView Lead: https://cuboidpilot.app/app/lead-detail.html?id=85','sent','{\"alert_type\":\"LEAD_ASSIGNED\",\"lead_id\":85,\"deal_value\":50000}','2026-10-01 15:50:13'),(20,3,'+91 98765 43210','Neha Singhania (Head of Sales)','human_alert',NULL,'🎯 NEW LEAD ASSIGNED TO YOU\nCompany: CuboidSoft\nProspect: Vikram Malhotra (+91 99887 76655)\nDeal Value: ₹50,000 • Priority: MEDIUM\n\nExecutive Context:\nVikram is exploring Enterprise tier for 5,000 monthly inquiries.\n\nRecommended Action:\nSchedule VIP product walkthrough and send commercial proposal.\nView Lead: https://cuboidpilot.app/app/lead-detail.html?id=86','sent','{\"alert_type\":\"LEAD_ASSIGNED\",\"lead_id\":86,\"deal_value\":50000}','2026-10-01 15:50:36'),(21,3,'919876543210','Support Rep','human_alert',NULL,'🎯 NEW LEAD ASSIGNED TO YOU\nCompany: CuboidSoft\nProspect: Vikram Malhotra (+91 99887 76655)\nDeal Value: ₹50,000 • Priority: MEDIUM\n\nExecutive Context:\nVikram is exploring Enterprise tier for 5,000 monthly inquiries.\n\nRecommended Action:\nSchedule VIP product walkthrough and send commercial proposal.\nView Lead: https://cuboidpilot.app/app/lead-detail.html?id=86','sent','{\"alert_type\":\"LEAD_ASSIGNED\",\"lead_id\":86,\"deal_value\":50000}','2026-10-01 15:50:36'),(22,3,'+91 98765 43210','Arjun Rao (Senior Closer)','human_alert',NULL,'🎯 NEW LEAD ASSIGNED TO YOU\nCompany: CuboidSoft\nProspect: Vikram Malhotra (+91 99887 76655)\nDeal Value: ₹50,000 • Priority: MEDIUM\n\nExecutive Context:\nVikram is exploring Enterprise tier for 5,000 monthly inquiries.\n\nRecommended Action:\nSchedule VIP product walkthrough and send commercial proposal.\nView Lead: https://cuboidpilot.app/app/lead-detail.html?id=87','sent','{\"alert_type\":\"LEAD_ASSIGNED\",\"lead_id\":87,\"deal_value\":50000}','2026-10-01 17:29:22'),(23,3,'919876543210','Support Rep','human_alert',NULL,'🎯 NEW LEAD ASSIGNED TO YOU\nCompany: CuboidSoft\nProspect: Vikram Malhotra (+91 99887 76655)\nDeal Value: ₹50,000 • Priority: MEDIUM\n\nExecutive Context:\nVikram is exploring Enterprise tier for 5,000 monthly inquiries.\n\nRecommended Action:\nSchedule VIP product walkthrough and send commercial proposal.\nView Lead: https://cuboidpilot.app/app/lead-detail.html?id=87','sent','{\"alert_type\":\"LEAD_ASSIGNED\",\"lead_id\":87,\"deal_value\":50000}','2026-10-01 17:29:23'),(24,3,'+91 98765 43210','Ayush','human_alert',NULL,'🎯 NEW LEAD ASSIGNED TO YOU\nCompany: CuboidSoft\nProspect: Vikram Malhotra (+91 99887 76655)\nDeal Value: ₹50,000 • Priority: MEDIUM\n\nExecutive Context:\nVikram is exploring Enterprise tier for 5,000 monthly inquiries.\n\nRecommended Action:\nSchedule VIP product walkthrough and send commercial proposal.\nView Lead: https://cuboidpilot.app/app/lead-detail.html?id=88','sent','{\"alert_type\":\"LEAD_ASSIGNED\",\"lead_id\":88,\"deal_value\":50000}','2026-10-01 17:41:54'),(25,3,'919876543210','Support Rep','human_alert',NULL,'🎯 NEW LEAD ASSIGNED TO YOU\nCompany: CuboidSoft\nProspect: Vikram Malhotra (+91 99887 76655)\nDeal Value: ₹50,000 • Priority: MEDIUM\n\nExecutive Context:\nVikram is exploring Enterprise tier for 5,000 monthly inquiries.\n\nRecommended Action:\nSchedule VIP product walkthrough and send commercial proposal.\nView Lead: https://cuboidpilot.app/app/lead-detail.html?id=88','sent','{\"alert_type\":\"LEAD_ASSIGNED\",\"lead_id\":88,\"deal_value\":50000}','2026-10-01 17:41:54');
/*!40000 ALTER TABLE `whatsapp_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `widget_payments`
--

DROP TABLE IF EXISTS `widget_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `widget_payments`
--

LOCK TABLES `widget_payments` WRITE;
/*!40000 ALTER TABLE `widget_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `widget_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `widget_settings`
--

DROP TABLE IF EXISTS `widget_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `widget_settings`
--

LOCK TABLES `widget_settings` WRITE;
/*!40000 ALTER TABLE `widget_settings` DISABLE KEYS */;
INSERT INTO `widget_settings` VALUES (3,3,'Cai','Cai','assets/uploads/logos/logo_dark_comp_3_1790755745.png','assets/uploads/logos/logo_dark_comp_3_1790755745.png','assets/uploads/logos/logo_light_comp_3_1790755745.png','#111111','light','Hi there 👋\r\n\r\nYou are now speaking with Cai. How can I help?','Powered By CuboidPilot','bottom_right',1,1,'1,2,3,4,5,6','10:00','18:00',30,0,7,'https://meet.google.com/cp-consult','calcom','cal_live_testkey12345678','https://cal.com/cuboidpilot/30min','https://httpbin.org/post',1,1,1,NULL,NULL,'HDFC Bank','CuboidSoft Technologies','50200088991122','HDFC0001234','cuboidsoft@hdfcbank',NULL,'+91 99999 88888',1,'2026-09-29 15:09:25','2026-10-02 00:27:27','https://linkedin.com/in/ayushman-varma','https://linkedin.com/company/cuboidpilot','https://linkedin.com/company/cuboidsoft');
/*!40000 ALTER TABLE `widget_settings` ENABLE KEYS */;
--
-- Table structure for table `company_assets`
--

DROP TABLE IF EXISTS `company_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `company_assets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `category` varchar(64) NOT NULL DEFAULT 'document',
  `description` text DEFAULT NULL,
  `keywords` text DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) DEFAULT 0,
  `file_type` varchar(100) DEFAULT 'application/pdf',
  `download_count` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company_asset` (`company_id`,`is_active`),
  KEY `idx_asset_category` (`company_id`,`category`),
  CONSTRAINT `fk_asset_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-02 15:09:03
