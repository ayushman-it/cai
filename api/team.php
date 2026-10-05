<?php
/**
 * CUBOIDPILOT — TEAM MANAGEMENT API
 * Lists, invites, updates, and manages company team members.
 * Enforces multi-tenant isolation and entitlement gating (can_create_team).
 */

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/mailer.php';
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

// Role Check: Verify caller is owner or admin for mutating actions
$callerStmt = $pdo->prepare("SELECT role, is_super_admin FROM `users` WHERE id = ? AND company_id = ? LIMIT 1");
$callerStmt->execute([$userId, $companyId]);
$caller = $callerStmt->fetch();
$callerRole = $caller['role'] ?? ($_SESSION['user_role'] ?? 'sales_agent');
$isOwnerOrAdmin = in_array($callerRole, ['owner', 'admin']) || !empty($caller['is_super_admin']);

$mutatingActions = ['add', 'invite', 'send_invitation', 'revoke_invitation', 'update_role', 'edit', 'delete'];
if (in_array($action, $mutatingActions) && !$isOwnerOrAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied. Only workspace Owners and Admins can manage team members.']);
    exit;
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
                'message' => 'Team member added successfully',
                'user_id' => $newId,
                'avatar_url' => $avatarUrl
            ]);
            break;

        case 'invite':
        case 'send_invitation':
            $email = strtolower(trim($data['email'] ?? ''));
            $role  = strtolower(trim($data['role'] ?? 'sales_agent'));

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Please provide a valid work email address.']);
                exit;
            }

            $allowedRoles = ['owner', 'admin', 'manager', 'sales_agent'];
            if (!in_array($role, $allowedRoles)) {
                $role = 'sales_agent';
            }

            // Check if user is already a member of this company
            $chkUser = $pdo->prepare("SELECT id, name FROM `users` WHERE `email` = ? AND `company_id` = ? AND `is_active` = 1 LIMIT 1");
            $chkUser->execute([$email, $companyId]);
            if ($chkUser->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => 'A team member with this email address already belongs to your workspace.']);
                exit;
            }

            // Verify that this company has configured an email sending account
            $emailCfg = CompanyMailer::getCompanyConfig($pdo, $companyId);
            if (!$emailCfg || empty($emailCfg['smtp_host']) || empty($emailCfg['smtp_username'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error'   => 'Your company email account is not configured. Please set up your company SMTP credentials in Settings -> Integrations -> Email before inviting members.'
                ]);
                exit;
            }

            // Fetch company details
            $compStmt = $pdo->prepare("SELECT name FROM `companies` WHERE `id` = ? LIMIT 1");
            $compStmt->execute([$companyId]);
            $comp = $compStmt->fetch();
            $companyName = $comp ? $comp['name'] : 'CuboidPilot Workspace';

            // Generate secure random single-use token (32 bytes = 64 hex chars)
            $inviteToken = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));

            // Store invitation in database
            $insInv = $pdo->prepare("
                INSERT INTO `team_invitations`
                (`company_id`, `invited_by_user_id`, `email`, `role`, `invitation_token`, `status`, `expires_at`, `created_at`, `updated_at`)
                VALUES (?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW())
            ");
            $insInv->execute([$companyId, $userId, $email, $role, $inviteToken, $expiresAt]);
            $invId = (int)$pdo->lastInsertId();

            // Construct secure invitation link
            $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $basePath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
            $inviteUrl = "{$scheme}://{$host}{$basePath}/accept_invite.php?token={$inviteToken}";

            $roleTitle = ucwords(str_replace('_', ' ', $role));
            $subject = "You have been invited to join {$companyName} on CuboidPilot";

            $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Invitation to join {$companyName}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f7f6f2; color: #1c1917; margin: 0; padding: 40px 20px;">
  <div style="max-width: 540px; margin: 0 auto; background: #ffffff; border: 1px solid #e7e5de; border-radius: 8px; padding: 36px; box-shadow: 0 4px 12px rgba(0,0,0,0.04);">
    <div style="margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid #e7e5de;">
      <h2 style="margin: 0; font-size: 18px; color: #1c1917; font-weight: 600;">CuboidPilot Workspace Invitation</h2>
    </div>
    <p style="font-size: 14px; line-height: 1.6; color: #44403c;">Hello,</p>
    <p style="font-size: 14px; line-height: 1.6; color: #44403c;">
      You have been invited to join <strong>{$companyName}</strong> on CuboidPilot with the assigned role of <strong>{$roleTitle}</strong>.
    </p>
    <div style="margin: 28px 0; text-align: center;">
      <a href="{$inviteUrl}" style="background-color: #1c1917; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-size: 13px; font-weight: 600; display: inline-block;">
        Accept Invitation
      </a>
    </div>
    <p style="font-size: 12px; color: #78716c; line-height: 1.5;">
      Or copy and paste this link into your browser:<br>
      <a href="{$inviteUrl}" style="color: #0284c7; word-break: break-all;">{$inviteUrl}</a>
    </p>
    <p style="font-size: 11px; color: #a8a29e; margin-top: 32px; border-top: 1px solid #f5f5f4; padding-top: 16px;">
      This invitation was sent via {$companyName}'s official email server and will expire on {$expiresAt}. If you were not expecting this invitation, you can safely ignore this email.
    </p>
  </div>
</body>
</html>
HTML;

            $textBody = "You have been invited to join {$companyName} on CuboidPilot as {$roleTitle}.\n\n"
                      . "Click the link below or paste it into your browser to accept the invitation:\n"
                      . "{$inviteUrl}\n\n"
                      . "This invitation will expire on {$expiresAt}.";

            // Send invitation email using THAT company's configured email account
            $sendRes = CompanyMailer::send($pdo, $companyId, $email, $subject, $htmlBody, $textBody);

            if (!$sendRes['success']) {
                // If email invitation fails: Do not show "Invitation Sent". Remove pending invite or fail.
                $pdo->prepare("DELETE FROM `team_invitations` WHERE `id` = ?")->execute([$invId]);
                http_response_code(502);
                echo json_encode([
                    'success' => false,
                    'error'   => 'Could not send invitation email: ' . $sendRes['error'] . '. Please verify your company email settings in Settings -> Integrations -> Email.'
                ]);
                exit;
            }

            echo json_encode([
                'success'          => true,
                'message'          => "Invitation sent successfully to {$email} via {$companyName}'s email account.",
                'invitation_id'    => $invId,
                'invitation_token' => $inviteToken,
                'invite_url'       => $inviteUrl,
                'expires_at'       => $expiresAt
            ]);
            break;

        case 'list_invitations':
            $stmt = $pdo->prepare("
                SELECT i.*, u.name as invited_by_name
                FROM `team_invitations` i
                LEFT JOIN `users` u ON u.id = i.invited_by_user_id
                WHERE i.company_id = ? AND i.status = 'pending' AND i.expires_at > NOW()
                ORDER BY i.id DESC
            ");
            $stmt->execute([$companyId]);
            $invites = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success'     => true,
                'invitations' => array_map(function($inv) {
                    return [
                        'id'              => (int)$inv['id'],
                        'email'           => $inv['email'],
                        'role'            => $inv['role'],
                        'role_label'      => ucwords(str_replace('_', ' ', $inv['role'])),
                        'invited_by_name' => $inv['invited_by_name'] ?? 'Admin',
                        'expires_at'      => date('M j, Y H:i', strtotime($inv['expires_at'])),
                        'created_at'      => date('M j, Y H:i', strtotime($inv['created_at']))
                    ];
                }, $invites)
            ]);
            break;

        case 'revoke_invitation':
            $invId = (int)($data['invitation_id'] ?? 0);
            if (!$invId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invitation ID is required']);
                exit;
            }

            $pdo->prepare("UPDATE `team_invitations` SET `status` = 'revoked', `updated_at` = NOW() WHERE `id` = ? AND `company_id` = ?")
                ->execute([$invId, $companyId]);

            echo json_encode(['success' => true, 'message' => 'Invitation revoked successfully']);
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
