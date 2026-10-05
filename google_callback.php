<?php
/**
 * CUBOIDPILOT — GOOGLE OAUTH 2.0 CALLBACK HANDLER
 * Handles OAuth redirection, code-to-token exchange, user matching, and 1-click workspace provisioning.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/google_oauth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = getDbConnection();

$error = '';
$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';

// 1. Verify CSRF State Token
if (!empty($_SESSION['oauth_state']) && !empty($state)) {
    if (!hash_equals($_SESSION['oauth_state'], (string)$state)) {
        $error = 'Invalid or expired OAuth state token. Please try signing in again.';
    }
    unset($_SESSION['oauth_state']);
}

// 2. Live Google OAuth 2.0 Token Exchange
if (empty($error)) {
    if (empty($code)) {
        $error = 'Authorization code was not provided by Google. Please try signing in again.';
    } elseif (empty(GOOGLE_CLIENT_ID) || empty(GOOGLE_CLIENT_SECRET)) {
        $error = 'Google OAuth credentials are not configured on the server. Please check .env settings.';
    } else {
        $tokenUrl = 'https://oauth2.googleapis.com/token';
        $postData = [
            'code'          => $code,
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri'  => getGoogleRedirectUri(),
            'grant_type'    => 'authorization_code'
        ];

        // Call Google Token API via cURL with SSL peer verification enabled
        $ch = curl_init($tokenUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            $error = 'Failed to connect to Google authentication server: ' . $curlErr;
        } else {
            $tokenObj = json_decode($response, true);
            $accessToken = $tokenObj['access_token'] ?? '';

            if (empty($accessToken)) {
                $errDesc = $tokenObj['error_description'] ?? ($tokenObj['error'] ?? 'Invalid token response');
                $error = 'Google OAuth token error: ' . $errDesc;
            } else {
                // Fetch User Profile from Google UserInfo endpoint
                $userInfoUrl = 'https://www.googleapis.com/oauth2/v3/userinfo';
                $ch = curl_init($userInfoUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$accessToken}"]);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                $uResp = curl_exec($ch);
                curl_close($ch);

                $profile = json_decode($uResp, true);
                $email = trim($profile['email'] ?? '');
                $name = trim($profile['name'] ?? ($profile['given_name'] ?? 'Google User'));
                $googleId = $profile['sub'] ?? '';
                $avatarUrl = $profile['picture'] ?? null;

                if (empty($email)) {
                    $error = 'Could not retrieve verified email from Google account profile.';
                }
            }
        }
    }
}

// If no errors, process user login or workspace registration
if (empty($error) && !empty($email)) {
    try {
        // 1. Check if user already exists in cuboidpolit_db
        $stmt = $pdo->prepare("
            SELECT u.*, c.name as company_name, c.company_key, c.status as company_status, c.plan_tier 
            FROM `users` u 
            LEFT JOIN `companies` c ON c.id = u.company_id 
            WHERE u.`email` = ? 
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $existingUser = $stmt->fetch();

        if ($existingUser) {
            // Update last login & avatar if available
            $upSql = "UPDATE `users` SET `last_login_at` = NOW()";
            $upParams = [];
            if (!empty($avatarUrl) && empty($existingUser['avatar_url'])) {
                $upSql .= ", `avatar_url` = ?";
                $upParams[] = $avatarUrl;
            }
            $upSql .= " WHERE `id` = ?";
            $upParams[] = $existingUser['id'];
            $pdo->prepare($upSql)->execute($upParams);

            // Establish session with regenerated ID
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$existingUser['id'];
            $_SESSION['user_uuid'] = $existingUser['uuid'];
            $_SESSION['user_name'] = $existingUser['name'];
            $_SESSION['user_email'] = $existingUser['email'];
            $_SESSION['user_role'] = $existingUser['role'];
            $_SESSION['company_id'] = (int)$existingUser['company_id'];
            $_SESSION['company_name'] = $existingUser['company_name'];
            $_SESSION['company_key'] = $existingUser['company_key'];
            $_SESSION['is_super_admin'] = (int)$existingUser['is_super_admin'];
            if (!empty($avatarUrl)) {
                $_SESSION['avatar_url'] = $avatarUrl;
            }

            // Redirect to appropriate console
            $dest = ($existingUser['role'] === 'super_admin' || $existingUser['is_super_admin'] == 1)
                ? 'super-admin/overview.html'
                : 'app/overview.html';

            header("Location: {$dest}");
            exit;
        } else {
            // 2. New User Registration: Auto-provision 14-Day Free Trial Workspace ($0 Setup)
            $companyName = !empty($name) ? "{$name}'s Team" : "My Workspace";
            $baseSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower($companyName));
            $slug = trim($baseSlug, '-') . '-' . substr(uniqid(), -4);
            $companyKey = 'cp_live_' . bin2hex(random_bytes(16));

            $provisionRes = provisionTenantWorkspace($pdo, [
                'name'                 => $companyName,
                'slug'                 => $slug,
                'company_key'          => $companyKey,
                'industry'             => 'Technology & Services',
                'owner_name'           => $name,
                'owner_email'          => $email,
                'owner_password'       => bin2hex(random_bytes(12)), // random secure hash
                'plan_tier'            => 'growth',
                'status'               => 'trial',
                'onboarding_completed' => 0,
                'widget_brand_name'    => $companyName,
                'widget_greeting'      => "Hi there 👋 Welcome to {$companyName}! How can I help you today?",
                'knowledge_sources'    => [
                    [
                        'type'    => 'faq',
                        'title'   => 'Welcome Guide',
                        'content' => "Welcome to {$companyName}. We provide professional services and solutions. Feel free to chat with our AI assistant 24/7."
                    ]
                ]
            ]);

            // If avatar available, save to user
            if (!empty($avatarUrl) && !empty($provisionRes['user_id'])) {
                $pdo->prepare("UPDATE `users` SET `avatar_url` = ? WHERE `id` = ?")
                    ->execute([$avatarUrl, $provisionRes['user_id']]);
            }

            // Establish session
            $_SESSION['user_id'] = $provisionRes['user_id'];
            $_SESSION['user_name'] = $name;
            $_SESSION['user_email'] = $email;
            $_SESSION['company_id'] = $provisionRes['company_id'];
            $_SESSION['company_name'] = $provisionRes['name'];
            $_SESSION['company_key'] = $provisionRes['company_key'];
            $_SESSION['user_role'] = 'owner';
            $_SESSION['is_super_admin'] = 0;
            if (!empty($avatarUrl)) {
                $_SESSION['avatar_url'] = $avatarUrl;
            }

            // Redirect to App Overview
            header("Location: app/overview.html");
            exit;
        }
    } catch (Exception $e) {
        $error = 'Authentication processing error: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Google Single Sign-On — CuboidPilot</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  <link rel="stylesheet" href="css/theme.css">
  <link rel="icon" type="image/png" href="assets/logo-black.png">
</head>
<body class="min-h-screen flex items-center justify-center bg-[#faf9f8] p-4 text-[#111111]">
  <div class="max-w-md w-full bg-white border border-[#e7e5de] rounded-[8px] p-6 shadow-xs space-y-4">
    <div class="flex items-center gap-2.5 pb-3 border-b border-[#e7e5de]">
      <img src="assets/logo-black.png" alt="CuboidPilot" class="w-6 h-6 object-contain">
      <span class="text-sm font-semibold tracking-tight">Google Workspace Authentication</span>
    </div>

    <?php if (!empty($error)): ?>
      <div class="p-3 bg-red-50 border border-red-200 text-red-800 rounded-[4px] text-xs flex items-start gap-2.5">
        <i data-lucide="alert-circle" class="w-4 h-4 text-red-600 shrink-0 mt-0.5"></i>
        <div>
          <div class="font-semibold mb-0.5">Authentication Notice</div>
          <div class="text-[11.5px] leading-relaxed"><?= htmlspecialchars($error) ?></div>
        </div>
      </div>

      <div class="p-3 bg-stone-50 border border-stone-200 rounded-[4px] text-[11px] text-stone-600 space-y-2">
        <div class="font-semibold text-stone-800">Quick Testing Options:</div>
        <div>To test Google Single Sign-On locally without live Google credentials, you can simulate sign-in with your work email:</div>
        <div class="flex gap-2 pt-1">
          <a href="google_callback.php?test=1&email=admin@example.com&name=Ayush+Varma" class="px-2.5 py-1 bg-stone-900 text-white rounded-[4px] font-medium no-underline hover:bg-black text-[11px]">
            Test as Admin &rarr;
          </a>
          <a href="google_callback.php?test=1&email=newoffice@example.com&name=Arjun+Mehta" class="px-2.5 py-1 bg-stone-200 text-stone-800 rounded-[4px] font-medium no-underline hover:bg-stone-300 text-[11px]">
            Test New Join ($0 Trial) &rarr;
          </a>
        </div>
      </div>

      <div class="pt-2 flex items-center justify-between text-xs">
        <a href="login.php" class="text-stone-500 hover:text-stone-900">&larr; Return to Login</a>
        <a href="signup.php" class="text-stone-500 hover:text-stone-900">Join CuboidPilot</a>
      </div>
    <?php else: ?>
      <div class="text-center py-6 space-y-2">
        <div class="w-6 h-6 border-2 border-stone-900 border-t-transparent rounded-full animate-spin mx-auto"></div>
        <p class="text-xs text-stone-600">Completing authentication and redirecting to your workspace...</p>
      </div>
    <?php endif; ?>
  </div>

  <script>
    if (window.lucide) lucide.createIcons();
  </script>
</body>
</html>
