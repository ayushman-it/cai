<?php
/**
 * CUBOIDPILOT — ACCEPT TEAM INVITATION
 * Validates single-use expiring token and attaches user to the company workspace.
 */

require_once __DIR__ . '/config/db.php';

session_start();

$pdo = getDbConnection();
$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));
$error = '';
$success = '';

if (empty($token)) {
    $error = 'No invitation token was provided. Please check the link from your invitation email.';
}

$invitation = null;
$company = null;

if (!empty($token)) {
    $stmt = $pdo->prepare("
        SELECT i.*, c.name as company_name, c.logo_url, c.logo_dark_url
        FROM `team_invitations` i
        JOIN `companies` c ON c.id = i.company_id
        WHERE i.invitation_token = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $invitation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invitation) {
        $error = 'Invalid invitation token. This invitation does not exist or may have been revoked.';
    } elseif ($invitation['status'] === 'accepted') {
        $error = 'This invitation has already been used. Please log in with your credentials.';
    } elseif ($invitation['status'] === 'revoked') {
        $error = 'This invitation was revoked by the workspace administrator.';
    } elseif (strtotime($invitation['expires_at']) < time()) {
        $error = 'This invitation has expired. Please ask your workspace administrator to send a new invitation.';
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error) && $invitation) {
    $name = trim($_POST['name'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if (empty($name)) {
        $error = 'Please enter your full name.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $pdo->beginTransaction();

            $companyId = (int)$invitation['company_id'];
            $email = strtolower(trim($invitation['email']));
            $role = $invitation['role'];

            // Check if user already exists
            $uStmt = $pdo->prepare("SELECT id, password_hash, is_super_admin, company_id FROM `users` WHERE `email` = ? LIMIT 1");
            $uStmt->execute([$email]);
            $existingUser = $uStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingUser) {
                if (!empty($existingUser['is_super_admin'])) {
                    throw new Exception("Platform administrator accounts cannot be converted via team invitation.");
                }

                // If user already exists, require them to verify their existing password to authorize the link
                if (!password_verify($password, $existingUser['password_hash'])) {
                    throw new Exception("An account already exists for {$email}. Please enter your existing password to accept and link this workspace.");
                }

                $userId = (int)$existingUser['id'];
                $updU = $pdo->prepare("
                    UPDATE `users`
                    SET `company_id` = ?,
                        `role` = ?,
                        `name` = COALESCE(NULLIF(?, ''), `name`),
                        `is_active` = 1,
                        `updated_at` = NOW()
                    WHERE `id` = ?
                ");
                $updU->execute([$companyId, $role, $name, $userId]);
            } else {
                $passwordHash = password_hash($password, PASSWORD_BCRYPT);
                $uuid = 'usr_' . bin2hex(random_bytes(10));
                $insU = $pdo->prepare("
                    INSERT INTO `users`
                    (`uuid`, `company_id`, `name`, `email`, `password_hash`, `role`, `is_active`, `created_at`, `updated_at`)
                    VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
                ");
                $insU->execute([$uuid, $companyId, $name, $email, $passwordHash, $role]);
                $userId = (int)$pdo->lastInsertId();
            }

            // Mark single-use invitation as accepted
            $updInv = $pdo->prepare("
                UPDATE `team_invitations`
                SET `status` = 'accepted',
                    `accepted_at` = NOW(),
                    `updated_at` = NOW()
                WHERE `id` = ?
            ");
            $updInv->execute([(int)$invitation['id']]);

            $pdo->commit();

            // Establish authenticated session
            $_SESSION['user_id'] = $userId;
            $_SESSION['user_name'] = $name;
            $_SESSION['user_email'] = $email;
            $_SESSION['user_role'] = $role;
            $_SESSION['company_id'] = $companyId;
            $_SESSION['company_name'] = $invitation['company_name'];

            header('Location: app/overview.html?welcome=1');
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Error accepting invitation: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Accept Invitation — CuboidPilot</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/dashboard.css?v=5.0.0">
</head>
<body class="bg-[#f7f6f2] text-stone-900 min-h-screen flex items-center justify-center p-4">

  <div class="max-w-md w-full bg-white rounded-lg border border-[#e7e5de] shadow-xs p-8 space-y-6">
    <div class="text-center space-y-2">
      <div class="w-10 h-10 mx-auto bg-stone-900 text-white rounded-lg flex items-center justify-center font-bold text-sm tracking-wider">
        CP
      </div>
      <h1 class="text-lg font-semibold text-stone-900">Join Workspace</h1>
      <?php if ($invitation): ?>
        <p class="text-xs text-stone-500">
          You have been invited to join <strong class="text-stone-800"><?= htmlspecialchars($invitation['company_name']) ?></strong> as <span class="capitalize font-medium text-stone-700"><?= htmlspecialchars(str_replace('_', ' ', $invitation['role'])) ?></span>.
        </p>
      <?php endif; ?>
    </div>

    <?php if (!empty($error)): ?>
      <div class="p-3 bg-rose-50 border border-rose-200 text-rose-700 text-xs rounded-md">
        <?= htmlspecialchars($error) ?>
      </div>
      <?php if ($invitation && $invitation['status'] === 'accepted'): ?>
        <div class="text-center pt-2">
          <a href="login.php" class="btn-primary btn-sm inline-block px-4 py-2 text-xs">Go to Login</a>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($invitation && empty($error)): ?>
      <form method="POST" action="accept_invite.php?token=<?= htmlspecialchars($token) ?>" class="space-y-4 text-xs">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

        <div>
          <label class="block font-medium text-stone-700 mb-1">Your Email</label>
          <input type="email" value="<?= htmlspecialchars($invitation['email']) ?>" readonly class="app-input w-full bg-stone-50 text-stone-500 cursor-not-allowed text-xs">
        </div>

        <div>
          <label class="block font-medium text-stone-700 mb-1">Full Name *</label>
          <input type="text" name="name" required placeholder="e.g. Rahul Sharma" class="app-input w-full text-xs" autofocus>
        </div>

        <div>
          <label class="block font-medium text-stone-700 mb-1">Create Password *</label>
          <input type="password" name="password" required minlength="6" placeholder="At least 6 characters" class="app-input w-full text-xs">
        </div>

        <div>
          <label class="block font-medium text-stone-700 mb-1">Confirm Password *</label>
          <input type="password" name="password_confirm" required minlength="6" placeholder="Re-enter password" class="app-input w-full text-xs">
        </div>

        <button type="submit" class="w-full btn-primary text-xs py-2.5 font-medium mt-2">
          Accept Invitation & Join
        </button>
      </form>
    <?php endif; ?>

    <div class="text-center pt-4 border-t border-[#e7e5de]">
      <a href="login.php" class="text-xs text-stone-500 hover:text-stone-900 transition-colors">
        Already have an account? Sign in
      </a>
    </div>
  </div>

</body>
</html>
