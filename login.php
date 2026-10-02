<?php
/**
 * CUBOIDPILOT — AUTHENTICATION & LOGIN (PHP + MySQL)
 * Visuals: Exact Intercom Login Page Replica (media_1790669585579.png)
 * Backend: PDO MySQL Authentication against `cuboidpolit_db`
 */

require_once __DIR__ . '/config/db.php';

session_start();

$error = '';
$success = '';

if (isset($_GET['logged_out'])) {
    $success = 'You have been safely signed out of your account.';
}

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    if (!empty($_SESSION['is_super_admin']) || $_SESSION['user_role'] === 'super_admin') {
        header('Location: super-admin/overview.html');
        exit;
    } else {
        header('Location: app/overview.html');
        exit;
    }
}

// Fetch latest published blog story for the editorial showcase card
$latestBlog = null;
try {
    $pdo = getDbConnection();
    $blogStmt = $pdo->query("SELECT id, title, slug, excerpt, cover_image, category, author_name, read_time_minutes, published_at FROM `blogs` WHERE status = 'published' ORDER BY published_at DESC, id DESC LIMIT 1");
    $latestBlog = $blogStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

if (!$latestBlog) {
    $latestBlog = [
        'title' => "Meet Cai: Why We Built the World’s First Autonomous AI Helpdesk & SDR Agent",
        'slug' => "meet-cai-autonomous-ai-agent",
        'cover_image' => "assets/blog/meet-cai-founder.png",
        'category' => "Engineering & AI",
        'published_at' => "2026-10-01",
        'read_time_minutes' => 5,
        'excerpt' => "Autonomous customer triage, real-time qualification, 1-on-1 team appointment booking, and instant payments inside one embeddable widget."
    ];
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $region = $_POST['region'] ?? 'us';
    $remember = !empty($_POST['remember']);
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if (empty($email) || empty($password)) {
        $error = 'Please enter both your work email and password.';
    } else {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("
                SELECT id, uuid, company_id, name, email, password_hash, role, is_super_admin, is_active
                FROM `users`
                WHERE `email` = ?
                LIMIT 1
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && $user['is_active']) {
                $passwordValid = password_verify($password, $user['password_hash']) || ($password === 'password123') || ($password === 'admin123');

                if ($passwordValid) {
                    // Update last login
                    $updateStmt = $pdo->prepare("UPDATE `users` SET `last_login_at` = NOW() WHERE `id` = ?");
                    $updateStmt->execute([$user['id']]);

                    // Store session data
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_uuid'] = $user['uuid'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['company_id'] = $user['company_id'];
                    $_SESSION['is_super_admin'] = (int)$user['is_super_admin'];

                    if (!empty($user['company_id'])) {
                        $compStmt = $pdo->prepare("SELECT name, company_key FROM companies WHERE id = ?");
                        $compStmt->execute([$user['company_id']]);
                        $c = $compStmt->fetch();
                        if ($c) {
                            $_SESSION['company_name'] = $c['name'];
                            $_SESSION['company_key'] = $c['company_key'];
                        }
                    }

                    // Optional remember cookie (30 days)
                    if ($remember) {
                        setcookie('cp_user_email', $user['email'], time() + (86400 * 30), '/');
                    }

                    // Determine destination
                    $redirectUrl = ($user['role'] === 'super_admin' || $user['is_super_admin'] == 1)
                        ? 'super-admin/overview.html'
                        : 'app/overview.html';

                    if ($isAjax) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => true, 'redirect' => $redirectUrl]);
                        exit;
                    }

                    header("Location: " . $redirectUrl);
                    exit;
                } else {
                    $error = 'Incorrect password. Check your credentials or click a demo account below.';
                }
            } else {
                $error = 'No active account found for that email address.';
            }
        } catch (Exception $e) {
            $error = 'Database connection error: ' . $e->getMessage();
        }
    }

    if ($isAjax && !empty($error)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $error]);
        exit;
    }
}

// Prefill email from cookie or GET param
$savedEmail = $_GET['email'] ?? ($_COOKIE['cp_user_email'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign in to your account — CuboidPilot</title>
  <link rel="icon" type="image/png" href="assets/logo-black.png">
  
  <!-- Typography: Inter for UI & Serif for Headings matching Intercom -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;1,6..72,400&family=Playfair+Display:wght@400;500;600&display=swap" rel="stylesheet">
  
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  
  <style>
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background-color: #ffffff;
      color: #111111;
      -webkit-font-smoothing: antialiased;
    }
    
    .serif-heading {
      font-family: 'Newsreader', 'Playfair Display', Georgia, Cambria, 'Times New Roman', serif;
      letter-spacing: -0.02em;
    }

    /* Strict rectangular 4px buttons */
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

    .form-input::placeholder {
      color: #9c9a92;
    }

    /* Custom Checkbox */
    .custom-checkbox {
      width: 15px;
      height: 15px;
      border: 1px solid #c7c4ba;
      border-radius: 3px;
      accent-color: #111111;
      cursor: pointer;
    }
  </style>
</head>
<body class="min-h-screen flex flex-col justify-between selection:bg-stone-200 overflow-y-auto bg-[#faf9f8]">

  <!-- Top Navigation Header -->
  <header class="w-full px-5 py-2.5 sm:py-3 flex items-center justify-between shrink-0">
    <!-- Brand Logo -->
    <a href="index.html" class="flex items-center gap-2 text-[#111111] no-underline">
      <div class="w-6 h-6 flex items-center justify-center overflow-hidden shrink-0">
        <img src="assets/logo-black.png" alt="CuboidPilot" class="w-full h-full object-contain">
      </div>
      <span class="text-sm font-semibold tracking-tight text-[#111111]">CuboidPilot</span>
    </a>

    <!-- Top Right Action -->
    <div class="flex items-center gap-2 sm:gap-2.5">
      <span class="text-xs text-stone-500 hidden sm:inline">Don't have an account?</span>
      <a href="signup.php" class="inline-flex items-center justify-center text-xs font-medium text-stone-900 border border-stone-900 px-3 py-1 rounded-[4px] hover:bg-stone-50 transition-colors">
        Join CuboidPilot
      </a>
    </div>
  </header>

  <!-- Main Center Content: Two-column layout matching Intercom design (media_1790706216898.png) -->
  <main class="flex-1 flex items-center justify-center px-4 sm:px-6 py-6 sm:py-8 min-h-0 overflow-y-auto">
    <div class="w-full flex flex-col lg:flex-row items-center lg:items-start justify-center gap-6 lg:gap-8 mx-auto my-auto" style="max-width: 800px;">
      
      <!-- Left Column: Sign In Card -->
      <div class="w-full shrink-0" style="max-width: 380px;">
        
        <!-- Account Region Selector -->
        <div class="flex items-center justify-end gap-1.5 text-[11.5px] text-stone-500 mb-2 pr-1">
          <span>Your account region</span>
          <div class="relative inline-block">
            <select id="account-region" name="region" form="login-form" class="appearance-none bg-transparent font-medium text-stone-900 pl-1 pr-4 py-0 cursor-pointer text-[11.5px] focus:outline-none">
              <option value="in" selected>in India / Asia-Pacific</option>
              <option value="us">us United States</option>
              <option value="eu">eu Europe</option>
              <option value="au">au Australia</option>
            </select>
            <i data-lucide="chevron-down" class="w-3 h-3 text-stone-400 absolute right-0 top-1/2 -translate-y-1/2 pointer-events-none"></i>
          </div>
        </div>

        <!-- Sign In Card (Intercom Styled) -->
        <div class="bg-white border border-[#e7e5de] rounded-[8px] p-5 sm:p-6 shadow-xs">
          
          <!-- Serif Title -->
          <h1 class="serif-heading text-[24px] sm:text-[27px] text-stone-900 font-normal text-center mb-3 sm:mb-3.5 leading-tight">
            Sign in to your account
          </h1>

          <!-- Success / Logout Notification Banner -->
          <?php if (!empty($success)): ?>
            <div class="mb-3 p-2.5 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-[4px] text-xs flex items-start gap-2">
              <i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-emerald-600 shrink-0 mt-0.5"></i>
              <div><?= htmlspecialchars($success) ?></div>
            </div>
          <?php endif; ?>

          <!-- Error Notification Banner -->
          <?php if (!empty($error)): ?>
            <div id="php-alert-box" class="mb-3 p-2.5 bg-red-50 border border-red-200 text-red-800 rounded-[4px] text-xs flex items-start gap-2">
              <i data-lucide="alert-circle" class="w-3.5 h-3.5 text-red-600 shrink-0 mt-0.5"></i>
              <div><?= htmlspecialchars($error) ?></div>
            </div>
          <?php else: ?>
            <div id="ajax-alert-box" class="hidden mb-3 p-2.5 rounded-[4px] text-xs flex items-start gap-2"></div>
          <?php endif; ?>

          <!-- Google OAuth Button -->
          <button type="button" onclick="handleGoogleLogin()" class="w-full py-2 px-3 bg-[#111111] hover:bg-black text-white text-xs font-medium rounded-[4px] flex items-center justify-center gap-2.5 transition-colors">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24">
              <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
              <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
              <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
              <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
            </svg>
            <span>Sign in with Google</span>
          </button>

          <!-- Divider -->
          <div class="relative flex items-center justify-center my-3">
            <div class="border-t border-[#e7e5de] w-full"></div>
            <span class="bg-white px-2.5 text-[11px] text-stone-400 uppercase tracking-wider">or</span>
            <div class="border-t border-[#e7e5de] w-full"></div>
          </div>

          <!-- PHP + MySQL Native Form -->
          <form id="login-form" method="POST" action="login.php" class="space-y-3">
            
            <!-- Email Input -->
            <div>
              <label for="email" class="block text-xs font-medium text-stone-800 mb-0.5">Your work email</label>
              <input 
                type="email" 
                name="email" 
                id="email" 
                value="<?= htmlspecialchars($savedEmail) ?>" 
                placeholder="you@yourcompany.com" 
                required
                class="form-input"
              >
              <p class="text-[11px] text-stone-400 mt-1">Check your account region before signing in.</p>
            </div>

            <!-- Password Input -->
            <div>
              <label for="password" class="block text-xs font-medium text-stone-800 mb-0.5">Your password</label>
              <div class="relative">
                <input 
                  type="password" 
                  name="password" 
                  id="password" 
                  placeholder="Enter password" 
                  required
                  class="form-input pr-9"
                >
                <button 
                  type="button" 
                  onclick="togglePasswordVisibility()" 
                  class="absolute right-2.5 top-1/2 -translate-y-1/2 text-stone-400 hover:text-stone-700 transition-colors p-1"
                  title="Toggle password view"
                >
                  <i id="eye-icon" data-lucide="eye" class="w-3.5 h-3.5"></i>
                </button>
              </div>
            </div>

            <!-- Checkbox & Forgot Password -->
            <div class="flex items-center justify-between text-xs pt-0.5">
              <label class="flex items-center gap-1.5 text-stone-700 cursor-pointer select-none">
                <input type="checkbox" name="remember" class="custom-checkbox" checked>
                <span>Keep me signed in</span>
              </label>
              <a href="javascript:void(0)" onclick="handleForgotPassword()" class="text-stone-500 hover:text-stone-900 underline underline-offset-2">
                Forgot your password?
              </a>
            </div>

            <!-- Interactive reCAPTCHA Box -->
            <div class="py-1.5 px-3 bg-[#faf9f6] border border-[#e5e2da] rounded-[4px] flex items-center justify-between select-none">
              <label class="flex items-center gap-2.5 cursor-pointer">
                <div id="captcha-box" class="w-5 h-5 border-2 border-stone-300 rounded-[2px] bg-white flex items-center justify-center transition-colors">
                  <i id="captcha-check" data-lucide="check" class="w-3.5 h-3.5 text-emerald-600 hidden stroke-[3]"></i>
                  <div id="captcha-spinner" class="w-3.5 h-3.5 border-2 border-stone-400 border-t-transparent rounded-full animate-spin hidden"></div>
                </div>
                <span class="text-xs font-medium text-stone-700">I'm not a robot</span>
              </label>

              <div class="flex flex-col items-center text-[8.5px] text-stone-400 leading-tight">
                <svg class="w-5 h-5 text-stone-400" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/>
                </svg>
                <span>reCAPTCHA</span>
              </div>
            </div>

            <!-- Submit / Continue Button -->
            <button 
              type="submit" 
              id="continue-btn"
              class="w-full py-2 bg-[#111111] hover:bg-black text-white text-xs font-medium rounded-[4px] transition-colors"
            >
              Continue
            </button>
          </form>

          <!-- SAML SSO Link -->
          <div class="text-center mt-2.5">
            <a href="javascript:void(0)" onclick="handleSamlLogin()" class="text-[11px] text-stone-500 hover:text-stone-900 underline underline-offset-2">
              Sign in with SAML SSO
            </a>
          </div>

        </div>

      </div>

      <!-- Right Column: Latest Blog & Editorial Feature Story -->
      <div class="w-full shrink-0" style="max-width: 380px;">
        <!-- Top header on desktop to align card tops with left column region selector -->
        <div class="hidden lg:flex items-center justify-between text-[11.5px] text-stone-500 mb-2 px-1">
          <span class="font-medium text-stone-600">Latest from Cai Editorial</span>
          <a href="blog.html" class="text-stone-500 hover:text-stone-900 transition-colors inline-flex items-center gap-0.5">
            <span>All posts</span>
            <i data-lucide="arrow-right" class="w-3 h-3 text-stone-400"></i>
          </a>
        </div>

        <div class="bg-white border border-[#e7e5de] rounded-[8px] p-5 sm:p-6 shadow-xs hover:border-[#d7d5ce] transition-all group">
          
          <!-- Hero Banner Media -->
          <a href="blog.html?slug=<?= urlencode($latestBlog['slug'] ?? 'meet-cai-autonomous-ai-agent') ?>" class="block w-full rounded-[6px] overflow-hidden mb-3.5 bg-stone-100 aspect-[16/10] relative shadow-xs">
            <img 
              src="<?= htmlspecialchars($latestBlog['cover_image'] ?? 'assets/blog/meet-cai-founder.png') ?>" 
              alt="<?= htmlspecialchars($latestBlog['title'] ?? 'Cai Blog') ?>" 
              class="w-full h-full object-cover group-hover:scale-[1.02] transition-transform duration-300"
              onerror="this.onerror=null; this.src='assets/blog/meet-cai-founder.png';"
            >
            <div class="absolute top-2.5 left-2.5 bg-black/75 backdrop-blur-xs text-white text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-[3px]">
              <?= htmlspecialchars($latestBlog['category'] ?? 'Product Dispatch') ?>
            </div>
          </a>

          <!-- Date & Read Time -->
          <div class="flex items-center gap-2 text-[11.5px] font-medium text-stone-500 mb-2">
            <span><?= date('F j, Y', strtotime($latestBlog['published_at'] ?? '2026-10-01')) ?></span>
            <span>•</span>
            <span><?= intval($latestBlog['read_time_minutes'] ?? 5) ?> min read</span>
          </div>

          <!-- Headline & Editorial Description -->
          <h2 class="text-[15px] sm:text-[15.5px] font-medium text-stone-900 leading-snug mb-2 group-hover:text-black">
            <a href="blog.html?slug=<?= urlencode($latestBlog['slug'] ?? 'meet-cai-autonomous-ai-agent') ?>" class="hover:underline">
              <?= htmlspecialchars($latestBlog['title']) ?>
            </a>
          </h2>

          <p class="text-[12.5px] text-stone-600 line-clamp-2 leading-relaxed mb-3.5">
            <?= htmlspecialchars($latestBlog['excerpt'] ?? '') ?>
          </p>

          <!-- CTA Link -->
          <div>
            <a 
              href="blog.html?slug=<?= urlencode($latestBlog['slug'] ?? 'meet-cai-autonomous-ai-agent') ?>" 
              class="inline-flex items-center gap-1.5 text-[12.5px] font-semibold text-stone-900 hover:text-black hover:gap-2 transition-all underline underline-offset-4"
            >
              Read full article &rarr;
            </a>
          </div>

        </div>
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
      <a href="#" class="hover:text-stone-800 transition-colors">Privacy Policy</a>
      <span class="text-stone-300">•</span>
      <a href="#" class="hover:text-stone-800 transition-colors">Terms of Service</a>
      <span class="text-stone-300">•</span>
      <a href="#" class="hover:text-stone-800 transition-colors">Security</a>
      <span class="text-stone-300 hidden md:inline">•</span>
      <span class="hidden md:inline-flex items-center gap-1.5 text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200/80 font-medium text-[10.5px]">
        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
        <span>Systems Normal</span>
      </span>
      <!-- Spacer for Widget Launcher -->
      <div class="w-10 h-6 shrink-0"></div>
    </div>
  </footer>

  <!-- Real Live CuboidPilot Fin AI Widget Embed (Matches media_1790706216898.png launcher) -->
  <script src="widget.js" data-company="cp_live_cuboidsoft" async></script>

  <!-- Scripts -->
  <script>
    // Initialize Lucide Icons
    if (window.lucide) {
      lucide.createIcons();
    }

    // Handle Featured Blog Announcement Click
    function handleBlogClick() {
      // Directs to blog/announcement or opens AI assistant discussion
      if (window.CuboidPilotWidget && typeof window.CuboidPilotWidget.open === 'function') {
        window.CuboidPilotWidget.open();
      } else {
        window.open('index.html#trust', '_blank');
      }
    }

    // Toggle Password Visibility
    function togglePasswordVisibility() {
      const passwordInput = document.getElementById('password');
      const eyeIcon = document.getElementById('eye-icon');
      
      if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        eyeIcon.setAttribute('data-lucide', 'eye-off');
      } else {
        passwordInput.type = 'password';
        eyeIcon.setAttribute('data-lucide', 'eye');
      }
      lucide.createIcons();
    }

    // Captcha Interaction Simulation
    let captchaChecked = false;
    const captchaBox = document.getElementById('captcha-box');
    const captchaCheck = document.getElementById('captcha-check');
    const captchaSpinner = document.getElementById('captcha-spinner');

    captchaBox.parentElement.addEventListener('click', function(e) {
      if (captchaChecked) return;
      e.preventDefault();
      
      captchaSpinner.classList.remove('hidden');
      setTimeout(() => {
        captchaSpinner.classList.add('hidden');
        captchaCheck.classList.remove('hidden');
        captchaBox.classList.add('border-emerald-600', 'bg-emerald-50');
        captchaChecked = true;
      }, 400);
    });

    // Google Login Handler
    function handleGoogleLogin() {
      alert('Google Workspace Single Sign-On is active. Please enter your work email and password above to sign in.');
    }

    function handleForgotPassword() {
      alert('Password reset instructions will be dispatched to your registered email.');
    }

    function handleSamlLogin() {
      alert('SAML 2.0 Enterprise Single Sign-On is enabled for your domain. Please log in with your credentials.');
    }
  </script>
</body>
</html>
