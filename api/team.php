<?php
/**
 * CUBOIDPILOT — TEAM MANAGEMENT API
 * Lists, invites, updates, and manages company team members.
 * Enforces multi-tenant isolation and entitlement gating (can_create_team).
 */

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/entitlements.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = getDbConnection();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];

// Entitlement Check: can_create_team
checkEntitlement($pdo, $companyId, 'can_create_team', true);

$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');
$input = file_get_contents('php://input');
$data = json_decode($input, true) ?? $_POST;

function saveAvatarFile($avatarInput, $companyId) {
    if (!$avatarInput) return null;

    $targetDir = __DIR__ . '/../assets/uploads/avatars/';
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0777, true);
    }

    // If Base64 string
    if (preg_match('/^data:image\/(\w+);base64,/', $avatarInput, $type)) {
        $data = substr($avatarInput, strpos($avatarInput, ',') + 1);
        $type = strtolower($type[1]);
        if (!in_array($type, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
            $type = 'png';
        }
        $decoded = base64_decode($data);
        if ($decoded !== false) {
            $filename = 'avatar_' . $companyId . '_' . bin2hex(random_bytes(8)) . '.' . $type;
            if (file_put_contents($targetDir . $filename, $decoded)) {
                return 'assets/uploads/avatars/' . $filename;
            }
        }
    }
    // If URL or existing assets path
    if (filter_var($avatarInput, FILTER_VALIDATE_URL) || str_starts_with($avatarInput, 'assets/')) {
        return $avatarInput;
    }
    return null;
}

try {
    switch ($action) {
        case 'list':
            $stmt = $pdo->prepare("
                SELECT id, uuid, name, email, phone, role, job_title, department, availability_status, is_instant_help_enabled, is_appointment_enabled, avatar_url, is_active, last_login_at, created_at 
                FROM `users` 
                WHERE `company_id` = ? AND `is_active` = 1
                ORDER BY FIELD(role, 'owner', 'admin', 'manager', 'sales_agent'), name ASC
            ");
            $stmt->execute([$companyId]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Clean output
            $members = array_map(function($u) {
                return [
                    'id'            => (int)$u['id'],
                    'name'          => $u['name'],
                    'email'         => $u['email'],
                    'phone'         => $u['phone'] ?? '',
                    'role'          => $u['role'],
                    'role_label'    => ucwords(str_replace('_', ' ', $u['role'])),
                    'job_title'     => $u['job_title'] ?? 'Consultant',
                    'department'    => $u['department'] ?? 'sales',
                    'department_label' => ucfirst($u['department'] ?? 'sales'),
                    'availability_status' => $u['availability_status'] ?? 'AVAILABLE',
                    'is_instant_help_enabled' => (bool)($u['is_instant_help_enabled'] ?? 1),
                    'is_appointment_enabled'  => (bool)($u['is_appointment_enabled'] ?? 1),
                    'avatar_url'    => $u['avatar_url'] ?? null,
                    'last_login'    => $u['last_login_at'] ? date('M j, Y H:i', strtotime($u['last_login_at'])) : 'Never',
                    'joined_date'   => date('M j, Y', strtotime($u['created_at']))
                ];
            }, $users);

            echo json_encode(['success' => true, 'members' => $members, 'count' => count($members)]);
            break;

        case 'add':
            $name  = trim($data['name'] ?? '');
            $email = strtolower(trim($data['email'] ?? ''));
            $phone = trim($data['phone'] ?? '');
            $role  = strtolower(trim($data['role'] ?? 'sales_agent'));
            $rawAvatar = $data['avatar_data'] ?? ($data['avatar_url'] ?? null);

            // Check if multipart file was uploaded
            if (!empty($_FILES['avatar']['tmp_name']) && is_uploaded_file($_FILES['avatar']['tmp_name'])) {
                $targetDir = __DIR__ . '/../assets/uploads/avatars/';
                if (!is_dir($targetDir)) @mkdir($targetDir, 0777, true);
                $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) $ext = 'png';
                $filename = 'avatar_' . $companyId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                if (move_uploaded_file($_FILES['avatar']['tmp_name'], $targetDir . $filename)) {
                    $avatarUrl = 'assets/uploads/avatars/' . $filename;
                } else {
                    $avatarUrl = null;
                }
            } else {
                $avatarUrl = saveAvatarFile($rawAvatar, $companyId);
            }

            if (empty($name) || empty($email)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Name and Email are required']);
                exit;
            }

            $allowedRoles = ['owner', 'admin', 'manager', 'sales_agent'];
            if (!in_array($role, $allowedRoles)) {
                $role = 'sales_agent';
            }

            // Check email uniqueness
            $chk = $pdo->prepare("SELECT id FROM `users` WHERE `email` = ? LIMIT 1");
            $chk->execute([$email]);
            if ($chk->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => 'A user with this email address already exists']);
                exit;
            }

            $jobTitle = trim($data['job_title'] ?? 'Consultant');
            $dept     = strtolower(trim($data['department'] ?? 'sales'));
            if (!in_array($dept, ['sales', 'technical', 'support', 'general'])) $dept = 'sales';
            $avail    = strtoupper(trim($data['availability_status'] ?? 'AVAILABLE'));
            if (!in_array($avail, ['AVAILABLE', 'BUSY', 'OFFLINE', 'APPOINTMENT_ONLY'])) $avail = 'AVAILABLE';
            $instant  = isset($data['is_instant_help_enabled']) ? (!empty($data['is_instant_help_enabled']) ? 1 : 0) : 1;
            $appt     = isset($data['is_appointment_enabled']) ? (!empty($data['is_appointment_enabled']) ? 1 : 0) : 1;

            $uuid = 'usr_' . bin2hex(random_bytes(10));
            $tempPassword = password_hash('Welcome@123', PASSWORD_DEFAULT);

            $ins = $pdo->prepare("
                INSERT INTO `users`
                (`uuid`, `company_id`, `name`, `email`, `password_hash`, `phone`, `role`, `job_title`, `department`, `availability_status`, `is_instant_help_enabled`, `is_appointment_enabled`, `avatar_url`, `is_active`, `created_at`, `updated_at`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
            ");
            $ins->execute([$uuid, $companyId, $name, $email, $tempPassword, $phone ?: null, $role, $jobTitle, $dept, $avail, $instant, $appt, $avatarUrl]);

            $newId = (int)$pdo->lastInsertId();
            echo json_encode([
                'success' => true,
                'message' => 'Team member invited successfully',
                'user_id' => $newId,
                'avatar_url' => $avatarUrl
            ]);
            break;

        case 'update_avatar':
            $userId = (int)($data['user_id'] ?? 0);
            $rawAvatar = $data['avatar_data'] ?? ($data['avatar_url'] ?? null);

            if (!$userId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'User ID is required']);
                exit;
            }

            // Verify belongs to this company
            $vStmt = $pdo->prepare("SELECT id FROM `users` WHERE `id` = ? AND `company_id` = ? LIMIT 1");
            $vStmt->execute([$userId, $companyId]);
            if (!$vStmt->fetch()) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Team member not found']);
                exit;
            }

            if (!empty($_FILES['avatar']['tmp_name']) && is_uploaded_file($_FILES['avatar']['tmp_name'])) {
                $targetDir = __DIR__ . '/../assets/uploads/avatars/';
                if (!is_dir($targetDir)) @mkdir($targetDir, 0777, true);
                $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) $ext = 'png';
                $filename = 'avatar_' . $companyId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                if (move_uploaded_file($_FILES['avatar']['tmp_name'], $targetDir . $filename)) {
                    $avatarUrl = 'assets/uploads/avatars/' . $filename;
                } else {
                    $avatarUrl = null;
                }
            } else {
                $avatarUrl = saveAvatarFile($rawAvatar, $companyId);
            }

            $upd = $pdo->prepare("UPDATE `users` SET `avatar_url` = ?, `updated_at` = NOW() WHERE `id` = ? AND `company_id` = ?");
            $upd->execute([$avatarUrl, $userId, $companyId]);

            echo json_encode(['success' => true, 'message' => 'Avatar updated successfully', 'avatar_url' => $avatarUrl]);
            break;

        case 'update_role':
            $userId = (int)($data['user_id'] ?? 0);
            $newRole = strtolower(trim($data['role'] ?? ''));

            if (!$userId || !in_array($newRole, ['owner', 'admin', 'manager', 'sales_agent'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid user ID or role']);
                exit;
            }

            // Verify belongs to this company
            $vStmt = $pdo->prepare("SELECT role FROM `users` WHERE `id` = ? AND `company_id` = ? LIMIT 1");
            $vStmt->execute([$userId, $companyId]);
            $targetUser = $vStmt->fetch();
            if (!$targetUser) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Team member not found']);
                exit;
            }

            // Prevent demoting the only owner
            if ($targetUser['role'] === 'owner' && $newRole !== 'owner') {
                $ocStmt = $pdo->prepare("SELECT COUNT(*) FROM `users` WHERE `company_id` = ? AND `role` = 'owner' AND `is_active` = 1");
                $ocStmt->execute([$companyId]);
                $ownerCount = (int)$ocStmt->fetchColumn();
                if ($ownerCount <= 1) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'A workspace must retain at least one active Owner.']);
                    exit;
                }
            }

            $upd = $pdo->prepare("UPDATE `users` SET `role` = ?, `updated_at` = NOW() WHERE `id` = ? AND `company_id` = ?");
            $upd->execute([$newRole, $userId, $companyId]);

            echo json_encode(['success' => true, 'message' => 'Role updated successfully']);
            break;

        case 'edit':
            $userId = (int)($data['user_id'] ?? 0);
            $name   = trim($data['name'] ?? '');
            $email  = strtolower(trim($data['email'] ?? ''));
            $phone  = trim($data['phone'] ?? '');
            $role   = strtolower(trim($data['role'] ?? 'sales_agent'));

            if (!$userId || empty($name) || empty($email)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'User ID, Name, and Email are required']);
                exit;
            }

            // Check email uniqueness if changed
            $chk = $pdo->prepare("SELECT id FROM `users` WHERE `email` = ? AND `id` != ? LIMIT 1");
            $chk->execute([$email, $userId]);
            if ($chk->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => 'This email is already in use by another user']);
                exit;
            }

            $jobTitle = trim($data['job_title'] ?? 'Consultant');
            $dept     = strtolower(trim($data['department'] ?? 'sales'));
            if (!in_array($dept, ['sales', 'technical', 'support', 'general'])) $dept = 'sales';
            $avail    = strtoupper(trim($data['availability_status'] ?? 'AVAILABLE'));
            if (!in_array($avail, ['AVAILABLE', 'BUSY', 'OFFLINE', 'APPOINTMENT_ONLY'])) $avail = 'AVAILABLE';
            $instant  = isset($data['is_instant_help_enabled']) ? (!empty($data['is_instant_help_enabled']) ? 1 : 0) : 1;
            $appt     = isset($data['is_appointment_enabled']) ? (!empty($data['is_appointment_enabled']) ? 1 : 0) : 1;

            $upd = $pdo->prepare("
                UPDATE `users`
                SET `name` = ?,
                    `email` = ?,
                    `phone` = ?,
                    `role` = ?,
                    `job_title` = ?,
                    `department` = ?,
                    `availability_status` = ?,
                    `is_instant_help_enabled` = ?,
                    `is_appointment_enabled` = ?,
                    `updated_at` = NOW()
                WHERE `id` = ? AND `company_id` = ?
            ");
            $upd->execute([$name, $email, $phone ?: null, $role, $jobTitle, $dept, $avail, $instant, $appt, $userId, $companyId]);

            $rawAvatar = $data['avatar_data'] ?? ($data['avatar_url'] ?? null);
            if (!empty($_FILES['avatar']['tmp_name']) && is_uploaded_file($_FILES['avatar']['tmp_name'])) {
                $targetDir = __DIR__ . '/../assets/uploads/avatars/';
                if (!is_dir($targetDir)) @mkdir($targetDir, 0777, true);
                $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) $ext = 'png';
                $filename = 'avatar_' . $companyId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                if (move_uploaded_file($_FILES['avatar']['tmp_name'], $targetDir . $filename)) {
                    $updAv = $pdo->prepare("UPDATE `users` SET `avatar_url` = ? WHERE `id` = ? AND `company_id` = ?");
                    $updAv->execute(['assets/uploads/avatars/' . $filename, $userId, $companyId]);
                }
            } else if ($rawAvatar) {
                $savedAvatar = saveAvatarFile($rawAvatar, $companyId);
                if ($savedAvatar) {
                    $updAv = $pdo->prepare("UPDATE `users` SET `avatar_url` = ? WHERE `id` = ? AND `company_id` = ?");
                    $updAv->execute([$savedAvatar, $userId, $companyId]);
                }
            }

            echo json_encode(['success' => true, 'message' => 'Team member updated successfully']);
            break;

        case 'toggle_status':
            $userId = (int)($data['user_id'] ?? 0);
            $newStatus = strtoupper(trim($data['status'] ?? 'AVAILABLE'));
            if (!in_array($newStatus, ['AVAILABLE', 'BUSY', 'OFFLINE', 'APPOINTMENT_ONLY'])) {
                $newStatus = 'AVAILABLE';
            }
            if (!$userId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'User ID is required']);
                exit;
            }
            $upd = $pdo->prepare("UPDATE `users` SET `availability_status` = ?, `updated_at` = NOW() WHERE `id` = ? AND `company_id` = ?");
            $upd->execute([$newStatus, $userId, $companyId]);
            echo json_encode(['success' => true, 'status' => $newStatus, 'message' => 'Availability updated']);
            break;

        case 'update_phone':
            $userId = (int)($data['user_id'] ?? 0);
            $phone  = trim($data['phone'] ?? '');
            if (!$userId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'User ID is required']);
                exit;
            }
            $upd = $pdo->prepare("UPDATE `users` SET `phone` = ?, `updated_at` = NOW() WHERE `id` = ? AND `company_id` = ?");
            $upd->execute([$phone ?: null, $userId, $companyId]);
            echo json_encode(['success' => true, 'message' => 'Phone number updated for WhatsApp reminders']);
            break;

        case 'deactivate':
            $userId = (int)($data['user_id'] ?? 0);
            if (!$userId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'User ID is required']);
                exit;
            }

            $upd = $pdo->prepare("UPDATE `users` SET `is_active` = 0, `updated_at` = NOW() WHERE `id` = ? AND `company_id` = ?");
            $upd->execute([$userId, $companyId]);

            echo json_encode(['success' => true, 'message' => 'Team member removed successfully']);
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
