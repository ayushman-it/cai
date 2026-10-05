<?php
/**
 * CUBOIDPILOT — WORKSPACE SIGNUP (PHP + MySQL)
 * Creates new user and tenant company in `cuboidpolit_db`, then starts onboarding.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/google_oauth.php';
session_start();

$error = '';
$email = trim($_GET['email'] ?? '');
$name = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($name) || empty($email) || empty($password)) {
        $error = 'Please fill in all required fields.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } else {
        try {
            $pdo = getDbConnection();

            // Check if email already exists
            $checkStmt = $pdo->prepare("SELECT id FROM `users` WHERE `email` = ?");
            $checkStmt->execute([$email]);
            if ($checkStmt->fetch()) {
                $error = 'An account with that email already exists. Please log in.';
            } else {
                // Generate default company name from user's name or domain
                $companyName = $name . "'s Team";
                $baseSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower($companyName));
                $baseSlug = trim($baseSlug, '-') ?: 'workspace';
                $slug = $baseSlug . '-' . substr(uniqid(), -4);
                $companyKey = 'cp_live_' . bin2hex(random_bytes(16));

                // Provision tenant workspace with pipeline stages, widget settings, and starter knowledge base
                $res = provisionTenantWorkspace($pdo, [
                    'name'                 => $companyName,
                    'slug'                 => $slug,
                    'company_key'          => $companyKey,
                    'industry'             => 'Education & Academies',
                    'owner_name'           => $name,
                    'owner_email'          => $email,
                    'owner_password'       => $password,
                    'plan_tier'            => 'growth',
                    'status'               => 'trial',
                    'onboarding_completed' => 0,
                    'widget_brand_name'    => $companyName,
                    'widget_greeting'      => "Hi there 👋 Welcome to {$companyName}! How can I help you today?",
                    'knowledge_sources'    => [
                        [
                            'type'    => 'faq',
                            'title'   => 'General Inquiries',
                            'content' => "Welcome to {$companyName}. We provide professional educational courses and career programs. Feel free to ask about our admission criteria, curriculum, fees, and installment options."
                        ]
                    ]
                ]);

                // Set Session
                $_SESSION['user_id'] = $res['user_id'];
                $_SESSION['user_name'] = $name;
                $_SESSION['user_email'] = $email;
                $_SESSION['company_id'] = $res['company_id'];
                $_SESSION['company_name'] = $res['name'];
                $_SESSION['company_key'] = $res['company_key'];
                $_SESSION['user_role'] = 'owner';
                $_SESSION['is_super_admin'] = 0;

                // Redirect to Onboarding Step 1
                header('Location: onboarding/business.php');
                exit;
            }
        } catch (Throwable $e) {
            error_log("Signup error: " . $e->getMessage());
            $error = 'We were unable to create your workspace. Please verify your details or contact support@cai.cuboidsoft.in.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Join CuboidPilot — Create your workspace</title>
  
  <!-- Typography: Inter for UI & Serif for Headings matching Intercom & Login page -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;1,6..72,400&family=Playfair+Display:wght@400;500;600&display=swap" rel="stylesheet">
  
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  
  <style>
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background-color: #faf9f8;
      color: #111111;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
    }
    .serif-heading {
      font-family: 'Newsreader', 'Playfair Display', Georgia, Cambria, 'Times New Roman', serif;
      letter-spacing: -0.02em;
    }
    button, input, select {
      border-radius: 4px;
      font-family: inherit;
    }
    .form-input {
      width: 100%;
      padding: 7px 11px;
      font-size: 13px;
      line-height: 1.4;
      border: 1px solid #dcdad3;
      border-radius: 4px;
      background-color: #ffffff;
      color: #111111;
      transition: border-color 0.15s ease;
    }
    .form-input:focus {
      outline: none;
      border-color: #111111;
      box-shadow: 0 0 0 1px #111111;
    }
  </style>
</head>
<body class="min-h-screen flex flex-col justify-between selection:bg-stone-200 overflow-y-auto bg-[#faf9f8]">

  <!-- Top Navigation Header -->
  <header class="w-full px-5 py-2.5 sm:py-3 flex items-center justify-between shrink-0">
    <a href="index.html" class="flex items-center gap-2 text-[#111111] no-underline">
      <div class="w-6 h-6 flex items-center justify-center overflow-hidden shrink-0">
        <img src="assets/logo-black.png" alt="CuboidPilot" class="w-full h-full object-contain">
      </div>
      <span class="text-sm font-semibold tracking-tight text-[#111111]">CuboidPilot</span>
    </a>

    <!-- Top Right Action -->
    <div class="flex items-center gap-2 sm:gap-2.5">
      <span class="text-xs text-stone-500 hidden sm:inline">Already have an account?</span>
      <a href="login.php" class="inline-flex items-center justify-center text-xs font-medium text-stone-900 border border-stone-900 px-3 py-1 rounded-[4px] hover:bg-stone-50 transition-colors">
        Log in
      </a>
    </div>
  </header>

  <!-- Main Container -->
  <main class="flex-1 flex flex-col items-center justify-center px-4 py-1 sm:py-2 min-h-0">
    <div class="w-full max-w-[420px] mx-auto my-auto">
      
      <div class="bg-white border border-[#e7e5de] rounded-[6px] p-5 sm:p-7 shadow-xs">
        <h1 class="serif-heading text-[24px] sm:text-[27px] text-stone-900 font-normal text-center mb-1 leading-tight">
          Create your workspace
        </h1>
        <p class="text-xs text-stone-500 text-center mb-4">
          14-day free trial • No credit card required • 3-minute setup
        </p>

        <?php if (!empty($error)): ?>
          <div class="mb-3 p-2.5 bg-red-50 border border-red-200 text-red-800 rounded-[4px] text-xs flex items-start gap-2">
            <i data-lucide="alert-circle" class="w-3.5 h-3.5 text-red-600 shrink-0 mt-0.5"></i>
            <div><?= htmlspecialchars($error) ?></div>
          </div>
        <?php endif; ?>

        <!-- Google Single Sign-On Button -->
        <a href="<?= getGoogleAuthUrl('join') ?>" class="w-full py-2.5 px-3 bg-white hover:bg-stone-50 text-stone-900 border border-stone-300 text-xs font-medium rounded-[4px] flex items-center justify-center gap-2.5 transition-colors mb-3.5 no-underline shadow-2xs">
          <svg class="w-4 h-4" viewBox="0 0 24 24">
            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
          </svg>
          <span>Continue with Google Workspace</span>
        </a>

        <!-- Divider -->
        <div class="relative flex items-center justify-center my-3.5">
          <div class="border-t border-[#e7e5de] w-full"></div>
          <span class="bg-white px-2.5 text-[11px] text-stone-400 uppercase tracking-wider">or work email</span>
          <div class="border-t border-[#e7e5de] w-full"></div>
        </div>

        <form method="POST" action="signup.php" class="space-y-3.5">
          <div>
            <label class="block text-xs font-medium text-stone-800 mb-1">Your full name</label>
            <input type="text" name="name" value="<?= htmlspecialchars($name ?? '') ?>" required placeholder="e.g. Arjun Mehta" class="form-input" autocomplete="name">
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-800 mb-1">Work email</label>
            <input type="email" name="email" value="<?= htmlspecialchars($email ?? '') ?>" required placeholder="you@company.com" class="form-input" autocomplete="email">
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-800 mb-1">Password</label>
            <input type="password" name="password" required placeholder="At least 6 characters" class="form-input" autocomplete="new-password">
          </div>

          <button type="submit" class="w-full py-2.5 bg-[#111111] hover:bg-black text-white text-xs font-medium rounded-[4px] transition-colors mt-2 shadow-xs cursor-pointer">
            Create Workspace & Continue &rarr;
          </button>
        </form>

        <p class="mt-3 text-center text-[11px] text-stone-400">
          By signing up, you agree to our <a href="terms.html" class="underline text-stone-600">Terms of Service</a> and <a href="privacy.html" class="underline text-stone-600">Privacy Policy</a>.
        </p>
      </div>

    </div>
  </main>

  <!-- Bottom Bar Footer -->
  <footer class="w-full px-5 py-2.5 sm:py-3 flex flex-col sm:flex-row items-center justify-between text-[11px] text-stone-500 shrink-0 border-t border-[#f0eee8] bg-transparent gap-2">
    <!-- Bottom Left: Brand Copyright & Privacy Choices -->
    <div class="flex items-center gap-3">
      <span class="text-stone-400">© 2026 CuboidPilot Technologies Inc.</span>
      <span class="text-stone-300 hidden sm:inline">•</span>
      <a href="javascript:void(0)" class="inline-flex items-center gap-1.5 hover:text-stone-800 transition-colors">
        <svg class="w-5 h-2.5 text-blue-600" viewBox="0 0 30 14" fill="none">
          <path d="M7 1h16a6 6 0 0 1 6 6 6 6 0 0 1-6 6H7A6 6 0 0 1 1 7a6 6 0 0 1 6-6z" fill="#0066cc"/>
          <path d="M7 3h16a4 4 0 0 1 4 4 4 4 0 0 1-4 4H7A4 4 0 0 1 3 7a4 4 0 0 1 4-4z" fill="#ffffff"/>
          <path d="M8 5.5l2 2 4-4" stroke="#0066cc" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M19 5.5l-4 4m0-4l4 4" stroke="#0066cc" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
        <span>Your Privacy Choices</span>
      </a>
    </div>

    <!-- Bottom Right: Enterprise Links & System Health -->
    <div class="flex items-center gap-3 sm:gap-4 text-stone-500">
      <a href="privacy.html" class="hover:text-stone-800 transition-colors">Privacy Policy</a>
      <span class="text-stone-300">•</span>
      <a href="terms.html" class="hover:text-stone-800 transition-colors">Terms of Service</a>
      <span class="text-stone-300">•</span>
      <a href="security.html" class="hover:text-stone-800 transition-colors">Security</a>
      <span class="text-stone-300 hidden md:inline">•</span>
      <span class="hidden md:inline-flex items-center gap-1.5 text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200/80 font-medium text-[10.5px]">
        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
        <span>Systems Normal</span>
      </span>
      <div class="w-10 h-6 shrink-0"></div>
    </div>
  </footer>

  <script>
    if (window.lucide) lucide.createIcons();
  </script>
</body>
</html>
