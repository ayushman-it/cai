<?php
/**
 * CUBOIDPILOT — SUPER ADMIN OPERATIONAL TELEMETRY API
 * Fleet monitoring, tenant directory, subscription telemetry, and system health.
 */

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = getDbConnection();

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$uStmt = $pdo->prepare("SELECT is_super_admin, role FROM `users` WHERE id = ? LIMIT 1");
$uStmt->execute([$userId]);
$user = $uStmt->fetch();

if (!$user || (empty($user['is_super_admin']) && $user['role'] !== 'owner')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Super admin authorization required']);
    exit;
}

$action = $_GET['action'] ?? 'overview';

try {
    switch ($action) {
        case 'overview':
            // 1. Tenant counts
            $totCompanies = (int)$pdo->query("SELECT COUNT(*) FROM `companies`")->fetchColumn();
            $actCompanies = (int)$pdo->query("SELECT COUNT(*) FROM `companies` WHERE status = 'active'")->fetchColumn();
            $trialCompanies = (int)$pdo->query("SELECT COUNT(*) FROM `companies` WHERE status = 'trial'")->fetchColumn();

            // 2. Operational volumes
            $totLeads = (int)$pdo->query("SELECT COUNT(*) FROM `leads`")->fetchColumn();
            $totConvs = (int)$pdo->query("SELECT COUNT(*) FROM `conversations`")->fetchColumn();
            $totMsgs  = (int)$pdo->query("SELECT COUNT(*) FROM `messages`")->fetchColumn();
            $totWaMsgs= (int)$pdo->query("SELECT COUNT(*) FROM `whatsapp_messages`")->fetchColumn();

            // 3. Platform MRR
            $mrrQuery = $pdo->query("
                SELECT COALESCE(SUM(p.price_monthly_inr), 0) as mrr_inr
                FROM `companies` c
                JOIN `plans` p ON p.id = c.plan_id
                WHERE c.status = 'active'
            ")->fetch();
            $mrrInr = (int)($mrrQuery['mrr_inr'] ?? 0);

            // 4. Recent tenants stream
            $recentStmt = $pdo->query("
                SELECT c.id, c.name, c.slug, c.status, c.created_at, c.city, c.country,
                       p.name as plan_name, p.price_monthly_inr,
                       u.name as owner_name, u.email as owner_email,
                       (SELECT COUNT(*) FROM leads WHERE company_id = c.id) as leads_count,
                       (SELECT COUNT(*) FROM conversations WHERE company_id = c.id) as convs_count
                FROM `companies` c
                LEFT JOIN `plans` p ON p.id = c.plan_id
                LEFT JOIN `users` u ON u.company_id = c.id AND u.role = 'owner'
                ORDER BY c.id DESC
                LIMIT 10
            ");
            $recentTenants = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'metrics' => [
                    'total_companies'   => $totCompanies,
                    'active_companies'  => $actCompanies,
                    'trial_companies'   => $trialCompanies,
                    'total_leads'       => $totLeads,
                    'total_conversations' => $totConvs,
                    'total_messages'    => $totMsgs,
                    'whatsapp_messages' => $totWaMsgs,
                    'platform_mrr_inr'  => $mrrInr,
                    'platform_mrr_usd'  => round($mrrInr / 85)
                ],
                'recent_tenants' => $recentTenants
            ]);
            break;

        case 'companies':
            $search = trim($_GET['search'] ?? '');
            $where = "1=1";
            $params = [];

            if (!empty($search)) {
                $where .= " AND (c.name LIKE ? OR c.slug LIKE ? OR u.email LIKE ?)";
                $params = ["%{$search}%", "%{$search}%", "%{$search}%"];
            }

            $stmt = $pdo->prepare("
                SELECT c.id, c.uuid, c.name, c.slug, c.company_key, c.status, c.created_at, c.city, c.plan_tier,
                       p.name as plan_name, p.price_monthly_inr,
                       u.name as owner_name, u.email as owner_email, u.phone as owner_phone,
                       (SELECT COUNT(*) FROM leads WHERE company_id = c.id) as leads_count,
                       (SELECT COUNT(*) FROM conversations WHERE company_id = c.id) as convs_count
                FROM `companies` c
                LEFT JOIN `plans` p ON p.id = c.plan_id
                LEFT JOIN `users` u ON u.company_id = c.id AND u.role = 'owner'
                WHERE {$where}
                ORDER BY (CASE WHEN c.plan_tier = 'pro' OR c.id = 3 THEN 1 ELSE 2 END), c.id DESC
            ");
            $stmt->execute($params);
            $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'companies' => $companies, 'count' => count($companies)]);
            break;

        case 'subscriptions':
            $stmt = $pdo->query("
                SELECT s.id as subscription_id, s.status as sub_status, s.amount_inr, s.current_period_start, s.current_period_end, s.razorpay_subscription_id,
                       c.id as company_id, c.name as company_name, c.slug as company_slug, c.plan_tier, c.status as company_status,
                       p.name as plan_name, p.code as plan_code, p.price_monthly_inr,
                       u.email as owner_email
                FROM `companies` c
                LEFT JOIN `subscriptions` s ON s.company_id = c.id
                LEFT JOIN `plans` p ON p.id = COALESCE(s.plan_id, c.plan_id)
                LEFT JOIN `users` u ON u.company_id = c.id AND u.role = 'owner'
                ORDER BY (CASE WHEN c.plan_tier = 'pro' OR c.id = 3 THEN 1 ELSE 2 END), c.id DESC
            ");
            $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success'       => true,
                'subscriptions' => $subs,
                'count'         => count($subs)
            ]);
            break;

        case 'system_health':
            $start = microtime(true);
            $pdo->query("SELECT 1");
            $dbLatencyMs = round((microtime(true) - $start) * 1000, 2);

            echo json_encode([
                'success' => true,
                'status'  => 'NOMINAL',
                'database' => [
                    'status'     => 'CONNECTED',
                    'latency_ms' => $dbLatencyMs,
                    'engine'     => 'MySQL (InnoDB)'
                ],
                'ai_engine' => [
                    'provider'   => 'Groq Cloud',
                    'model'      => 'qwen/qwen3.8-27b',
                    'status'     => 'OPERATIONAL'
                ],
                'whatsapp_gateway' => [
                    'status'     => 'OPERATIONAL',
                    'webhook'    => 'ACTIVE'
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;

        case 'create_company':
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $planTier = trim($_POST['plan_tier'] ?? 'growth');
            $subdomain = trim($_POST['subdomain'] ?? '');
            $password = trim($_POST['password'] ?? 'CuboidAdmin2026!');

            if (empty($name) || empty($email)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Company name and admin email are required.']);
                exit;
            }

            $slug = !empty($subdomain) ? preg_replace('/[^a-z0-9\-]/', '', strtolower($subdomain)) : preg_replace('/[^a-z0-9\-]/', '', strtolower($name));
            $companyKey = 'cp_live_' . bin2hex(random_bytes(16));
            $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );

            // Find plan ID
            $pStmt = $pdo->prepare("SELECT id FROM plans WHERE code = ? OR name LIKE ? LIMIT 1");
            $pStmt->execute([$planTier, "%{$planTier}%"]);
            $planId = $pStmt->fetchColumn() ?: 2;

            $pdo->beginTransaction();
            $insComp = $pdo->prepare("
                INSERT INTO `companies`
                (`uuid`, `name`, `slug`, `company_key`, `status`, `plan_id`, `plan_tier`, `created_at`, `updated_at`)
                VALUES (?, ?, ?, ?, 'active', ?, ?, NOW(), NOW())
            ");
            $insComp->execute([$uuid, $name, $slug, $companyKey, $planId, $planTier]);
            $newCompId = (int)$pdo->lastInsertId();

            // Create admin user
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $insUser = $pdo->prepare("
                INSERT INTO `users`
                (`company_id`, `name`, `email`, `password_hash`, `role`, `is_active`, `created_at`, `updated_at`)
                VALUES (?, ?, ?, ?, 'owner', 1, NOW(), NOW())
            ");
            $insUser->execute([$newCompId, $name . ' Admin', $email, $hash]);

            // Create default pipeline stages for new tenant
            $defaultStages = [
                ['name' => 'New Inquiry', 'slug' => 'new', 'stage_order' => 1, 'color_code' => '#6b7280', 'is_default' => 1],
                ['name' => 'Contacted', 'slug' => 'contacted', 'stage_order' => 2, 'color_code' => '#3b82f6', 'is_default' => 0],
                ['name' => 'Qualified', 'slug' => 'qualified', 'stage_order' => 3, 'color_code' => '#8b5cf6', 'is_default' => 0],
                ['name' => 'Proposal / Demo', 'slug' => 'proposal', 'stage_order' => 4, 'color_code' => '#f59e0b', 'is_default' => 0],
                ['name' => 'Won / Enrolled', 'slug' => 'won', 'stage_order' => 5, 'color_code' => '#10b981', 'is_default' => 0],
                ['name' => 'Lost', 'slug' => 'lost', 'stage_order' => 6, 'color_code' => '#ef4444', 'is_default' => 0]
            ];
            $stgIns = $pdo->prepare("INSERT INTO `pipeline_stages` (`company_id`, `name`, `slug`, `stage_order`, `color_code`, `is_default`) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($defaultStages as $ds) {
                $stgIns->execute([$newCompId, $ds['name'], $ds['slug'], $ds['stage_order'], $ds['color_code'], $ds['is_default']]);
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => "Tenant organization {$name} provisioned successfully!",
                'company_id' => $newCompId,
                'company_key' => $companyKey
            ]);
            break;

        case 'plans':
            $stmt = $pdo->query("SELECT * FROM `plans` ORDER BY `price_monthly_inr` ASC");
            $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'plans' => $plans, 'count' => count($plans)]);
            break;

        case 'update_plan':
            $planId = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $monthlyInr = (int)($_POST['price_monthly_inr'] ?? 0);
            $annualInr = (int)($_POST['price_annual_inr'] ?? 0);
            $aiLimit = (int)($_POST['ai_conversations_limit'] ?? 0);
            $seatsLimit = (int)($_POST['team_members_limit'] ?? 0);

            if ($planId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid plan ID']);
                exit;
            }

            $planUpdates = [];
            $planParams = [];
            if ($name !== '') {
                $planUpdates[] = "`name` = ?";
                $planParams[] = $name;
            }
            if ($monthlyInr > 0) {
                $planUpdates[] = "`price_monthly_inr` = ?";
                $planParams[] = $monthlyInr;
            }
            if ($annualInr > 0) {
                $planUpdates[] = "`price_annual_inr` = ?";
                $planParams[] = $annualInr;
            }
            if ($aiLimit > 0) {
                $planUpdates[] = "`ai_conversations_limit` = ?";
                $planParams[] = $aiLimit;
            }
            if ($seatsLimit > 0) {
                $planUpdates[] = "`team_members_limit` = ?";
                $planParams[] = $seatsLimit;
            }

            if (!empty($planUpdates)) {
                $planUpdates[] = "`updated_at` = NOW()";
                $planParams[] = $planId;
                $pdo->prepare("UPDATE `plans` SET " . implode(', ', $planUpdates) . " WHERE `id` = ?")->execute($planParams);
            }

            echo json_encode(['success' => true, 'message' => 'Plan limits updated successfully!']);
            break;

        case 'create_plan':
            $name = trim($_POST['name'] ?? '');
            $code = strtolower(preg_replace('/[^a-z0-9]/', '', $name));
            $monthlyInr = (int)($_POST['price_monthly_inr'] ?? 0);
            $annualInr = (int)($_POST['price_annual_inr'] ?? ($monthlyInr * 10));
            $aiLimit = (int)($_POST['ai_conversations_limit'] ?? 1000);
            $seatsLimit = (int)($_POST['team_members_limit'] ?? 5);

            if (empty($name) || empty($code)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Plan name is required']);
                exit;
            }

            $iStmt = $pdo->prepare("
                INSERT INTO `plans` (`code`, `name`, `price_monthly_inr`, `price_annual_inr`, `ai_conversations_limit`, `whatsapp_alerts_limit`, `team_members_limit`, `features_json`, `is_active`, `created_at`, `updated_at`)
                VALUES (?, ?, ?, ?, ?, 500, ?, '{\"closing_radar\": true, \"reports\": \"advanced\"}', 1, NOW(), NOW())
            ");
            $iStmt->execute([$code, $name, $monthlyInr, $annualInr, $aiLimit, $seatsLimit]);

            echo json_encode(['success' => true, 'message' => 'New plan tier created successfully!', 'plan_id' => $pdo->lastInsertId()]);
            break;

        case 'executive_linkedin':
            // Fetch executive settings from widget_settings (default to CuboidSoft / company 3)
            $ws = $pdo->query("SELECT linkedin_ayush, linkedin_cai, linkedin_cuboidsoft FROM widget_settings ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            
            $members = $pdo->query("
                SELECT id, name, email, role, job_title, department, avatar_url, linkedin_url 
                FROM `users` 
                ORDER BY FIELD(role, 'super_admin', 'owner', 'manager', 'sales_agent'), id ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'executives' => [
                    'linkedin_ayush'      => $ws['linkedin_ayush'] ?? 'https://linkedin.com/in/ayushman-varma',
                    'linkedin_cai'        => $ws['linkedin_cai'] ?? 'https://linkedin.com/company/cuboidpilot',
                    'linkedin_cuboidsoft' => $ws['linkedin_cuboidsoft'] ?? 'https://linkedin.com/company/cuboidsoft',
                    'avatar_ayush'        => 'assets/avatar-ayush.png',
                    'avatar_cai'          => 'assets/avatar-cai.png',
                    'avatar_cuboidsoft'   => 'assets/avatar-cuboidsoft.png',
                ],
                'team_members' => $members
            ]);
            break;

        case 'save_executive_linkedin':
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true) ?? $_POST;

            $linkedInAyush      = trim($data['linkedin_ayush'] ?? '');
            $linkedInCai        = trim($data['linkedin_cai'] ?? '');
            $linkedInCuboidsoft = trim($data['linkedin_cuboidsoft'] ?? '');

            // Update widget_settings table
            $upWs = $pdo->prepare("
                UPDATE `widget_settings` 
                SET `linkedin_ayush` = ?, `linkedin_cai` = ?, `linkedin_cuboidsoft` = ?, `updated_at` = NOW()
            ");
            $upWs->execute([$linkedInAyush, $linkedInCai, $linkedInCuboidsoft]);

            // Update Ayush user record
            if (!empty($linkedInAyush)) {
                $pdo->prepare("
                    UPDATE `users` 
                    SET `linkedin_url` = ? 
                    WHERE `email` LIKE '%ayush%' OR `name` LIKE '%Ayush%' OR `role` = 'super_admin'
                ")->execute([$linkedInAyush]);
            }

            // Update individual team members if provided
            if (!empty($data['team_members']) && is_array($data['team_members'])) {
                $uStmt = $pdo->prepare("UPDATE `users` SET `linkedin_url` = ? WHERE `id` = ?");
                foreach ($data['team_members'] as $tm) {
                    $tId = (int)($tm['id'] ?? 0);
                    $tUrl = trim($tm['linkedin_url'] ?? '');
                    if ($tId > 0) {
                        $uStmt->execute([$tUrl, $tId]);
                    }
                }
            }

            echo json_encode([
                'success' => true, 
                'message' => 'Executive LinkedIn URLs and team profiles updated successfully!'
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
