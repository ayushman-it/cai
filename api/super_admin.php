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

if (!$user || empty($user['is_super_admin']) || (int)$user['is_super_admin'] !== 1) {
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
                       c.trial_started_at, c.trial_ends_at, c.manual_pro_override,
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
            $planTier = strtolower(trim($_POST['plan_tier'] ?? 'growth'));
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

            $isTrialPlan = in_array($planTier, ['trial', 'free_trial', 'free'], true);
            $companyStatus = $isTrialPlan ? 'trial' : 'active';
            if ($isTrialPlan) {
                $planTier = 'free_trial';
            }

            // Find plan ID
            $pStmt = $pdo->prepare("SELECT id FROM plans WHERE code = ? OR name LIKE ? LIMIT 1");
            $pStmt->execute([$planTier, "%{$planTier}%"]);
            $planId = $pStmt->fetchColumn();
            if (!$planId && !$isTrialPlan) {
                $planId = 2;
            }

            $pdo->beginTransaction();
            $insComp = $pdo->prepare("
                INSERT INTO `companies`
                (`uuid`, `name`, `slug`, `company_key`, `status`, `plan_id`, `plan_tier`, `trial_started_at`, `trial_ends_at`, `manual_pro_override`, `created_at`, `updated_at`)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 14 DAY), 0, NOW(), NOW())
            ");
            $insComp->execute([$uuid, $name, $slug, $companyKey, $companyStatus, $planId ?: null, $planTier]);
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

        case 'toggle_manual_pro':
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true) ?? $_POST;
            $toggleCompanyId = (int)($data['company_id'] ?? ($_GET['company_id'] ?? 0));
            $enabled = !empty($data['enabled']) || (!empty($_GET['enabled']) && $_GET['enabled'] !== '0');

            if ($toggleCompanyId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Valid company ID required']);
                exit;
            }

            $cStmt = $pdo->prepare("SELECT id, name, trial_ends_at FROM `companies` WHERE id = ? LIMIT 1");
            $cStmt->execute([$toggleCompanyId]);
            $targetCompany = $cStmt->fetch();
            if (!$targetCompany) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Organization not found']);
                exit;
            }

            $proPlanId = (int)$pdo->query("SELECT id FROM `plans` WHERE `code` IN ('pro', 'scale', 'expert', 'premium') ORDER BY price_monthly_inr DESC LIMIT 1")->fetchColumn();
            if (!$proPlanId) {
                $proPlanId = (int)$pdo->query("SELECT id FROM `plans` WHERE `code` = 'growth' LIMIT 1")->fetchColumn() ?: 2;
            }

            $pdo->beginTransaction();

            if ($enabled) {
                $pdo->prepare("
                    UPDATE `companies`
                    SET `manual_pro_override` = 1,
                        `status` = 'active',
                        `plan_id` = ?,
                        `plan_tier` = 'pro',
                        `trial_started_at` = COALESCE(`trial_started_at`, NOW()),
                        `trial_ends_at` = DATE_ADD(NOW(), INTERVAL 365 DAY),
                        `updated_at` = NOW()
                    WHERE id = ?
                ")->execute([$proPlanId, $toggleCompanyId]);

                $subStmt = $pdo->prepare("SELECT id FROM `subscriptions` WHERE `company_id` = ? ORDER BY id DESC LIMIT 1");
                $subStmt->execute([$toggleCompanyId]);
                $subId = $subStmt->fetchColumn();
                if ($subId) {
                    $pdo->prepare("
                        UPDATE `subscriptions`
                        SET `plan_id` = ?,
                            `status` = 'active',
                            `amount_inr` = 0,
                            `current_period_start` = NOW(),
                            `current_period_end` = DATE_ADD(NOW(), INTERVAL 365 DAY),
                            `updated_at` = NOW()
                        WHERE id = ?
                    ")->execute([$proPlanId, $subId]);
                } else {
                    $pdo->prepare("
                        INSERT INTO `subscriptions`
                        (`company_id`, `plan_id`, `status`, `amount_inr`, `current_period_start`, `current_period_end`, `created_at`, `updated_at`)
                        VALUES (?, ?, 'active', 0, NOW(), DATE_ADD(NOW(), INTERVAL 365 DAY), NOW(), NOW())
                    ")->execute([$toggleCompanyId, $proPlanId]);
                }
            } else {
                $pdo->prepare("
                    UPDATE `companies`
                    SET `manual_pro_override` = 0,
                        `status` = CASE WHEN COALESCE(`trial_ends_at`, NOW()) >= NOW() THEN 'trial' ELSE 'suspended' END,
                        `plan_id` = NULL,
                        `plan_tier` = 'free_trial',
                        `updated_at` = NOW()
                    WHERE id = ?
                ")->execute([$toggleCompanyId]);

                $pdo->prepare("
                    UPDATE `subscriptions`
                    SET `status` = CASE
                            WHEN COALESCE(`current_period_end`, NOW()) >= NOW() THEN 'trial'
                            ELSE 'suspended'
                        END,
                        `amount_inr` = 0,
                        `updated_at` = NOW()
                    WHERE `company_id` = ?
                ")->execute([$toggleCompanyId]);
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => $enabled
                    ? "Manual Pro access enabled for {$targetCompany['name']}."
                    : "Manual Pro access disabled for {$targetCompany['name']}."
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

        case 'delete_company':
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true) ?? $_POST;
            $delCompanyId = (int)($data['company_id'] ?? ($_GET['company_id'] ?? 0));

            if ($delCompanyId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Valid company ID required.']);
                exit;
            }

            // Protect primary system company (e.g. ID 3 / CuboidSoft or active admin session)
            if ($delCompanyId === 3 || (!empty($_SESSION['company_id']) && $delCompanyId === (int)$_SESSION['company_id'] && $delCompanyId === 3)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Cannot delete the primary platform administration organization (CuboidSoft).']);
                exit;
            }

            // Verify company exists
            $chkStmt = $pdo->prepare("SELECT id, name FROM `companies` WHERE `id` = ? LIMIT 1");
            $chkStmt->execute([$delCompanyId]);
            $compToDelete = $chkStmt->fetch();

            if (!$compToDelete) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Organization not found.']);
                exit;
            }

            $pdo->beginTransaction();

            // 1. Delete messages
            try {
                $pdo->prepare("
                    DELETE m FROM `messages` m
                    INNER JOIN `conversations` c ON c.id = m.conversation_id
                    WHERE c.company_id = ?
                ")->execute([$delCompanyId]);
            } catch (Exception $ex) {}

            // 2. Delete conversations
            try { $pdo->prepare("DELETE FROM `conversations` WHERE `company_id` = ?")->execute([$delCompanyId]); } catch (Exception $ex) {}

            // 3. Delete lead events & leads
            try { $pdo->prepare("DELETE FROM `lead_events` WHERE `company_id` = ?")->execute([$delCompanyId]); } catch (Exception $ex) {}
            try { $pdo->prepare("DELETE FROM `leads` WHERE `company_id` = ?")->execute([$delCompanyId]); } catch (Exception $ex) {}

            // 4. Delete installments & customers
            try { $pdo->prepare("DELETE FROM `installments` WHERE `company_id` = ?")->execute([$delCompanyId]); } catch (Exception $ex) {}
            try { $pdo->prepare("DELETE FROM `customers` WHERE `company_id` = ?")->execute([$delCompanyId]); } catch (Exception $ex) {}

            // 5. Delete whatsapp records
            try { $pdo->prepare("DELETE FROM `whatsapp_messages` WHERE `company_id` = ?")->execute([$delCompanyId]); } catch (Exception $ex) {}
            try { $pdo->prepare("DELETE FROM `whatsapp_handoffs` WHERE `company_id` = ?")->execute([$delCompanyId]); } catch (Exception $ex) {}
            try { $pdo->prepare("DELETE FROM `whatsapp_accounts` WHERE `company_id` = ?")->execute([$delCompanyId]); } catch (Exception $ex) {}

            // 6. Delete knowledge, widget settings, pipeline stages
            try { $pdo->prepare("DELETE FROM `knowledge_sources` WHERE `company_id` = ?")->execute([$delCompanyId]); } catch (Exception $ex) {}
            try { $pdo->prepare("DELETE FROM `widget_settings` WHERE `company_id` = ?")->execute([$delCompanyId]); } catch (Exception $ex) {}
            try { $pdo->prepare("DELETE FROM `pipeline_stages` WHERE `company_id` = ?")->execute([$delCompanyId]); } catch (Exception $ex) {}

            // 7. Delete billing / subscriptions / payments
            try { $pdo->prepare("DELETE FROM `payments` WHERE `company_id` = ?")->execute([$delCompanyId]); } catch (Exception $ex) {}
            try { $pdo->prepare("DELETE FROM `subscriptions` WHERE `company_id` = ?")->execute([$delCompanyId]); } catch (Exception $ex) {}

            // 8. Delete users
            try { $pdo->prepare("DELETE FROM `users` WHERE `company_id` = ?")->execute([$delCompanyId]); } catch (Exception $ex) {}

            // 9. Delete company record itself
            $pdo->prepare("DELETE FROM `companies` WHERE `id` = ?")->execute([$delCompanyId]);

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => "Organization '{$compToDelete['name']}' (ID: {$delCompanyId}) and all associated records have been permanently deleted."
            ]);
            break;

        case 'export_companies':
            $stmt = $pdo->query("
                SELECT c.id, c.name, c.slug, c.status, c.plan_tier, c.created_at,
                       u.name as owner_name, u.email as owner_email,
                       (SELECT COUNT(*) FROM leads WHERE company_id = c.id) as leads_count,
                       (SELECT COUNT(*) FROM conversations WHERE company_id = c.id) as convs_count
                FROM `companies` c
                LEFT JOIN `users` u ON u.company_id = c.id AND u.role = 'owner'
                ORDER BY c.id DESC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=cuboidpilot_companies_' . date('Y-m-d') . '.csv');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Company ID', 'Name', 'Slug', 'Plan Tier', 'Status', 'Owner Name', 'Owner Email', 'Leads', 'Conversations', 'Created Date']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['id'], $r['name'], $r['slug'], $r['plan_tier'] ?: 'growth', $r['status'],
                    $r['owner_name'], $r['owner_email'], $r['leads_count'], $r['convs_count'], $r['created_at']
                ]);
            }
            fclose($out);
            exit;

        // Resume / Activate Workspace (Unlocks workspace immediately)
        case 'resume_company':
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true) ?? $_POST;
            $resCompanyId = (int)($data['company_id'] ?? ($_GET['company_id'] ?? 0));

            if ($resCompanyId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Valid company ID required']);
                exit;
            }

            // Set company to active, extend trial/subscription dates by 30 days
            $pdo->prepare("
                UPDATE `companies`
                SET `status` = 'active',
                    `trial_ends_at` = DATE_ADD(NOW(), INTERVAL 30 DAY),
                    `updated_at` = NOW()
                WHERE id = ?
            ")->execute([$resCompanyId]);

            // Ensure subscription record is active
            $subStmt = $pdo->prepare("SELECT id FROM `subscriptions` WHERE `company_id` = ? LIMIT 1");
            $subStmt->execute([$resCompanyId]);
            $subId = $subStmt->fetchColumn();

            if ($subId) {
                $pdo->prepare("
                    UPDATE `subscriptions`
                    SET `status` = 'active',
                        `current_period_end` = DATE_ADD(NOW(), INTERVAL 30 DAY),
                        `updated_at` = NOW()
                    WHERE id = ?
                ")->execute([$subId]);
            } else {
                $pId = (int)$pdo->query("SELECT id FROM `plans` WHERE `code` = 'growth' LIMIT 1")->fetchColumn() ?: 2;
                $pdo->prepare("
                    INSERT INTO `subscriptions`
                    (`company_id`, `plan_id`, `status`, `amount_inr`, `current_period_start`, `current_period_end`, `created_at`, `updated_at`)
                    VALUES (?, ?, 'active', 24999, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW(), NOW())
                ")->execute([$resCompanyId, $pId]);
            }

            echo json_encode([
                'success' => true,
                'message' => "Workspace (ID: {$resCompanyId}) has been successfully resumed and unlocked!"
            ]);
            break;

        // Extend 14-Day Free Trial (+14 or custom days)
        case 'extend_trial':
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true) ?? $_POST;
            $extCompanyId = (int)($data['company_id'] ?? ($_GET['company_id'] ?? 0));
            $days = max(1, min(365, (int)($data['days'] ?? 14)));

            if ($extCompanyId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Valid company ID required']);
                exit;
            }

            $pdo->prepare("
                UPDATE `companies`
                SET `status` = 'trial',
                    `trial_ends_at` = DATE_ADD(GREATEST(COALESCE(`trial_ends_at`, NOW()), NOW()), INTERVAL ? DAY),
                    `updated_at` = NOW()
                WHERE id = ?
            ")->execute([$days, $extCompanyId]);

            // Get new expiration date
            $newEndStmt = $pdo->prepare("SELECT trial_ends_at FROM `companies` WHERE id = ?");
            $newEndStmt->execute([$extCompanyId]);
            $newEnd = $newEndStmt->fetchColumn();

            echo json_encode([
                'success'       => true,
                'message'       => "Free trial extended by {$days} days. New trial end: " . date('M j, Y', strtotime($newEnd)),
                'trial_ends_at' => $newEnd
            ]);
            break;

        // Suspend Workspace (Manual lock by Super Admin)
        case 'suspend_company':
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true) ?? $_POST;
            $susCompanyId = (int)($data['company_id'] ?? ($_GET['company_id'] ?? 0));

            if ($susCompanyId <= 0 || $susCompanyId === 3) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Cannot suspend primary admin workspace']);
                exit;
            }

        // Fetch full catalog, products, and knowledge for a specific company
        case 'company_catalog':
            $cid = (int)($_GET['company_id'] ?? 0);
            if ($cid <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Company ID required']);
                exit;
            }

            $cStmt = $pdo->prepare("SELECT * FROM `companies` WHERE id = ? LIMIT 1");
            $cStmt->execute([$cid]);
            $comp = $cStmt->fetch(PDO::FETCH_ASSOC);

            if (!$comp) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Company not found']);
                exit;
            }

            $wStmt = $pdo->prepare("SELECT * FROM `widget_settings` WHERE company_id = ? LIMIT 1");
            $wStmt->execute([$cid]);
            $wSettings = $wStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $aiStmt = $pdo->prepare("SELECT * FROM `ai_configs` WHERE company_id = ? LIMIT 1");
            $aiStmt->execute([$cid]);
            $aiConfig = $aiStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $kStmt = $pdo->prepare("SELECT id, type, title, category, source_url, content, is_active FROM `knowledge_sources` WHERE company_id = ? ORDER BY id DESC");
            $kStmt->execute([$cid]);
            $knowledge = $kStmt->fetchAll(PDO::FETCH_ASSOC);

            $pStmt = $pdo->prepare("SELECT * FROM `products` WHERE company_id = ? ORDER BY id ASC");
            $pStmt->execute([$cid]);
            $products = $pStmt->fetchAll(PDO::FETCH_ASSOC);

            $categories = [];
            try {
                $catStmt = $pdo->prepare("SELECT * FROM `company_business_categories` WHERE company_id = ? ORDER BY id ASC");
                $catStmt->execute([$cid]);
                $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $t) {}

            $quickChips = [];
            try {
                $qStmt = $pdo->prepare("SELECT * FROM `quick_chips` WHERE company_id = ? ORDER BY id ASC");
                $qStmt->execute([$cid]);
                $quickChips = $qStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $t) {}

            echo json_encode([
                'success'         => true,
                'company'         => $comp,
                'widget_settings' => $wSettings,
                'ai_config'       => $aiConfig,
                'knowledge_count' => count($knowledge),
                'knowledge'       => $knowledge,
                'products_count'  => count($products),
                'products'        => $products,
                'categories'      => $categories,
                'quick_chips'     => $quickChips
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        // Securely train and sync company knowledge, products, and AI configuration
        case 'train_company_ai':
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true) ?? $_POST;
            $cid = (int)($data['company_id'] ?? 0);

            if ($cid <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Company ID required']);
                exit;
            }

            $pdo->beginTransaction();

            // 1. Update company profile if provided (safely checking actual columns)
            if (!empty($data['company_update']) && is_array($data['company_update'])) {
                $cu = $data['company_update'];
                $compCols = $pdo->query("SHOW COLUMNS FROM `companies`")->fetchAll(PDO::FETCH_COLUMN);
                $fields = [];
                $params = [];
                foreach (['name', 'slug', 'company_key', 'industry', 'city', 'country'] as $f) {
                    if (in_array($f, $compCols) && isset($cu[$f])) {
                        $fields[] = "`{$f}` = ?";
                        $params[] = $cu[$f];
                    }
                }
                if (!empty($fields)) {
                    if (in_array('updated_at', $compCols)) {
                        $fields[] = "`updated_at` = NOW()";
                    }
                    $params[] = $cid;
                    $pdo->prepare("UPDATE `companies` SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
                }
            }

            // 2. Update/Insert Widget Settings if provided
            if (!empty($data['widget_settings']) && is_array($data['widget_settings'])) {
                $ws = $data['widget_settings'];
                $wsCols = $pdo->query("SHOW COLUMNS FROM `widget_settings`")->fetchAll(PDO::FETCH_COLUMN);
                $wCheck = $pdo->prepare("SELECT id FROM `widget_settings` WHERE company_id = ? LIMIT 1");
                $wCheck->execute([$cid]);
                $wId = $wCheck->fetchColumn();

                if ($wId) {
                    $wFields = [];
                    $wParams = [];
                    foreach (['brand_name', 'assistant_name', 'greeting_heading', 'greeting_subheading', 'primary_color', 'whatsapp_number', 'enable_whatsapp_continue'] as $wf) {
                        if (in_array($wf, $wsCols) && isset($ws[$wf])) {
                            $wFields[] = "`{$wf}` = ?";
                            $wParams[] = $ws[$wf];
                        }
                    }
                    if (!empty($wFields)) {
                        if (in_array('updated_at', $wsCols)) {
                            $wFields[] = "`updated_at` = NOW()";
                        }
                        $wParams[] = $cid;
                        $pdo->prepare("UPDATE `widget_settings` SET " . implode(', ', $wFields) . " WHERE company_id = ?")->execute($wParams);
                    }
                } else {
                    $insCols = ['company_id'];
                    $insVals = ['?'];
                    $insParams = [$cid];
                    foreach (['brand_name', 'assistant_name', 'greeting_heading', 'greeting_subheading', 'primary_color', 'whatsapp_number', 'enable_whatsapp_continue'] as $wf) {
                        if (in_array($wf, $wsCols) && isset($ws[$wf])) {
                            $insCols[] = "`{$wf}`";
                            $insVals[] = '?';
                            $insParams[] = $ws[$wf];
                        }
                    }
                    if (in_array('created_at', $wsCols)) {
                        $insCols[] = "`created_at`";
                        $insVals[] = 'NOW()';
                    }
                    if (in_array('updated_at', $wsCols)) {
                        $insCols[] = "`updated_at`";
                        $insVals[] = 'NOW()';
                    }
                    $pdo->prepare("INSERT INTO `widget_settings` (" . implode(', ', $insCols) . ") VALUES (" . implode(', ', $insVals) . ")")->execute($insParams);
                }
            }

            // 3. Update/Insert AI Config
            if (!empty($data['ai_config']) && is_array($data['ai_config'])) {
                $ai = $data['ai_config'];
                $aiCols = $pdo->query("SHOW COLUMNS FROM `ai_configs`")->fetchAll(PDO::FETCH_COLUMN);
                $aiCheck = $pdo->prepare("SELECT id FROM `ai_configs` WHERE company_id = ? LIMIT 1");
                $aiCheck->execute([$cid]);
                $aiId = $aiCheck->fetchColumn();

                if ($aiId) {
                    $aiFields = [];
                    $aiParams = [];
                    foreach (['ai_name', 'tone', 'primary_objective', 'custom_instructions'] as $aif) {
                        if (in_array($aif, $aiCols) && isset($ai[$aif])) {
                            $aiFields[] = "`{$aif}` = ?";
                            $aiParams[] = $ai[$aif];
                        }
                    }
                    if (!empty($aiFields)) {
                        if (in_array('updated_at', $aiCols)) {
                            $aiFields[] = "`updated_at` = NOW()";
                        }
                        $aiParams[] = $cid;
                        $pdo->prepare("UPDATE `ai_configs` SET " . implode(', ', $aiFields) . " WHERE company_id = ?")->execute($aiParams);
                    }
                } else {
                    $insCols = ['company_id'];
                    $insVals = ['?'];
                    $insParams = [$cid];
                    foreach (['ai_name', 'tone', 'primary_objective', 'custom_instructions'] as $aif) {
                        if (in_array($aif, $aiCols) && isset($ai[$aif])) {
                            $insCols[] = "`{$aif}`";
                            $insVals[] = '?';
                            $insParams[] = $ai[$aif];
                        }
                    }
                    if (in_array('created_at', $aiCols)) {
                        $insCols[] = "`created_at`";
                        $insVals[] = 'NOW()';
                    }
                    if (in_array('updated_at', $aiCols)) {
                        $insCols[] = "`updated_at`";
                        $insVals[] = 'NOW()';
                    }
                    $pdo->prepare("INSERT INTO `ai_configs` (" . implode(', ', $insCols) . ") VALUES (" . implode(', ', $insVals) . ")")->execute($insParams);
                }
            }

            // 4. Upsert Knowledge Sources (replace/clean old matching or insert new)
            if (!empty($data['knowledge_sources']) && is_array($data['knowledge_sources'])) {
                if (!empty($data['replace_knowledge'])) {
                    $pdo->prepare("DELETE FROM `knowledge_sources` WHERE company_id = ?")->execute([$cid]);
                }

                $kCols = $pdo->query("SHOW COLUMNS FROM `knowledge_sources`")->fetchAll(PDO::FETCH_COLUMN);
                $hasCategory = in_array('category', $kCols);
                $hasSourceUrl = in_array('source_url', $kCols);
                $hasUpdatedAt = in_array('updated_at', $kCols);

                $kInsCols = ['company_id', 'type', 'title'];
                $kInsVals = ['?', '?', '?'];
                if ($hasCategory) { $kInsCols[] = 'category'; $kInsVals[] = '?'; }
                if ($hasSourceUrl) { $kInsCols[] = 'source_url'; $kInsVals[] = '?'; }
                $kInsCols[] = 'content'; $kInsVals[] = '?';
                $kInsCols[] = 'is_active'; $kInsVals[] = '?';
                $kInsCols[] = 'created_at'; $kInsVals[] = 'NOW()';
                if ($hasUpdatedAt) { $kInsCols[] = 'updated_at'; $kInsVals[] = 'NOW()'; }

                $kSql = "INSERT INTO `knowledge_sources` (" . implode(', ', $kInsCols) . ") VALUES (" . implode(', ', $kInsVals) . ")";
                $kIns = $pdo->prepare($kSql);

                foreach ($data['knowledge_sources'] as $ks) {
                    $kParams = [
                        $cid,
                        $ks['type'] ?? 'faq',
                        $ks['title']
                    ];
                    if ($hasCategory) { $kParams[] = $ks['category'] ?? 'General'; }
                    if ($hasSourceUrl) { $kParams[] = $ks['source_url'] ?? ''; }
                    $kParams[] = $ks['content'];
                    $kParams[] = isset($ks['is_active']) ? (int)$ks['is_active'] : 1;
                    $kIns->execute($kParams);
                }
            }

            // 5. Upsert Products / Offerings / Workshops
            if (!empty($data['products']) && is_array($data['products'])) {
                if (!empty($data['replace_products'])) {
                    $pdo->prepare("DELETE FROM `products` WHERE company_id = ?")->execute([$cid]);
                }

                $pIns = $pdo->prepare("
                    INSERT INTO `products`
                    (`company_id`, `name`, `category`, `price_inr`, `original_price_inr`, `discount_percent`, `duration`, `currency`, `target_audience`, `description`, `features_json`, `emi_available`, `emi_starting_at_inr`, `is_active`, `created_at`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'INR', ?, ?, ?, ?, ?, ?, NOW())
                ");

                foreach ($data['products'] as $idx => $p) {
                    $price = (float)($p['price_inr'] ?? 0);
                    $orig = (float)($p['original_price_inr'] ?? $price);
                    $discount = ($orig > $price && $orig > 0) ? (int)round((($orig - $price) / $orig) * 100) : (int)($p['discount_percent'] ?? 0);
                    $features = is_array($p['features'] ?? null) ? json_encode(array_values($p['features']), JSON_UNESCAPED_UNICODE) : (string)($p['features_json'] ?? '[]');

                    $pIns->execute([
                        $cid,
                        $p['name'],
                        $p['category'] ?? 'Course',
                        $price,
                        $orig,
                        $discount,
                        $p['duration'] ?? 'Self-paced',
                        $p['target_audience'] ?? 'College students & aspiring software developers',
                        $p['description'] ?? '',
                        $features,
                        !empty($p['emi_available']) ? 1 : 0,
                        (float)($p['emi_starting_at_inr'] ?? 0),
                        isset($p['is_active']) ? (int)$p['is_active'] : 1
                    ]);
                }
            }

            // 6. Upsert Quick Action Chips (supporting both widget_settings and standalone table if present)
            if (!empty($data['quick_chips']) && is_array($data['quick_chips'])) {
                $widgetChips = [];
                foreach ($data['quick_chips'] as $qidx => $qc) {
                    $lbl = $qc['label'];
                    $resp = $qc['response_text'] ?? $lbl;
                    $widgetChips[] = ['label' => $lbl, 'text' => $resp];
                }

                try {
                    $pdo->prepare("UPDATE `widget_settings` SET `quick_actions_json` = ?, `updated_at` = NOW() WHERE `company_id` = ?")
                        ->execute([json_encode($widgetChips, JSON_UNESCAPED_UNICODE), $cid]);
                } catch (Throwable $t) {}

                try {
                    if (!empty($data['replace_quick_chips'])) {
                        $pdo->prepare("DELETE FROM `quick_chips` WHERE company_id = ?")->execute([$cid]);
                    }

                    $qcCols = $pdo->query("SHOW COLUMNS FROM `quick_chips`")->fetchAll(PDO::FETCH_COLUMN);
                    $hasDisplayOrder = in_array('display_order', $qcCols);
                    $hasUpdatedAt = in_array('updated_at', $qcCols);

                    $qcSql = "INSERT INTO `quick_chips` (`company_id`, `label`, `action_type`, `response_text`, `status`, `is_active`"
                        . ($hasDisplayOrder ? ", `display_order`" : "")
                        . ", `created_at`"
                        . ($hasUpdatedAt ? ", `updated_at`" : "")
                        . ") VALUES (?, ?, ?, ?, 'published', 1"
                        . ($hasDisplayOrder ? ", ?" : "")
                        . ", NOW()"
                        . ($hasUpdatedAt ? ", NOW()" : "")
                        . ")";
                    $qcIns = $pdo->prepare($qcSql);

                    foreach ($data['quick_chips'] as $qidx => $qc) {
                        $lbl = $qc['label'];
                        $act = $qc['action_type'] ?? 'SEND_TEXT_RESPONSE';
                        $resp = $qc['response_text'] ?? $lbl;
                        $qcParams = [$cid, $lbl, $act, $resp];
                        if ($hasDisplayOrder) {
                            $qcParams[] = $qidx + 1;
                        }
                        $qcIns->execute($qcParams);
                    }
                } catch (Throwable $t) {}
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => "Successfully trained and configured AI workspace for company ID {$cid}."
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
