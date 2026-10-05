<?php
/**
 * CUBOIDPILOT — 5-STEP FRICTIONLESS ONBOARDING HELPER
 * Manages tenant session, loads and saves state into `cuboidpolit_db`.
 * Follows the 5-step Intercom-inspired workflow:
 * 1. Business -> 2. Train AI -> 3. Test AI -> 4. Install Widget -> 5. Done
 */

require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Ensures tenant workspace session is initialized.
 * Falls back to default demo company if testing directly.
 */
function getOnboardingContext() {
    $pdo = getDbConnection();

    $companyId = $_SESSION['company_id'] ?? null;
    $userId = $_SESSION['user_id'] ?? null;

    if (!$companyId || !$userId) {
        $comp = $pdo->query("SELECT id, name, slug, company_key, industry, city, country, currency, timezone, onboarding_completed FROM `companies` ORDER BY id ASC LIMIT 1")->fetch();
        if ($comp) {
            $companyId = $comp['id'];
            $_SESSION['company_id'] = $companyId;
            $_SESSION['company_name'] = $comp['name'];

            $user = $pdo->prepare("SELECT id, name, email, role FROM `users` WHERE `company_id` = ? LIMIT 1");
            $user->execute([$companyId]);
            $u = $user->fetch();
            if ($u) {
                $userId = $u['id'];
                $_SESSION['user_id'] = $userId;
                $_SESSION['user_name'] = $u['name'];
                $_SESSION['user_email'] = $u['email'];
                $_SESSION['user_role'] = $u['role'];
            }
        }
    }

    // Load fresh company record
    $stmt = $pdo->prepare("SELECT * FROM `companies` WHERE `id` = ?");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch();

    // Load widget settings
    $wStmt = $pdo->prepare("SELECT * FROM `widget_settings` WHERE `company_id` = ?");
    $wStmt->execute([$companyId]);
    $widget = $wStmt->fetch();

    if (!$widget && $companyId) {
        $pdo->prepare("
            INSERT INTO `widget_settings` (`company_id`, `brand_name`, `accent_color`, `greeting_heading`, `greeting_subheading`, `position`, `enable_whatsapp_continue`, `require_phone_for_pricing`)
            VALUES (?, ?, '#111111', 'Ask anything about our pricing, plans or admissions', 'Instant AI answers powered by CuboidPilot', 'bottom_right', 1, 1)
        ")->execute([$companyId, $company['name'] ?? 'CuboidPilot Desk']);
        
        $wStmt->execute([$companyId]);
        $widget = $wStmt->fetch();
    }

    return [
        'pdo'     => $pdo,
        'company' => $company,
        'widget'  => $widget,
        'user_id' => $userId,
    ];
}

/**
 * Render Modern Intercom-Style Minimal Onboarding Header (5 Clean Steps)
 */
function renderOnboardingHeader($currentStep = 1) {
    $steps = [
        1 => ['title' => 'Business', 'file' => 'business.php'],
        2 => ['title' => 'Train AI', 'file' => 'train.php'],
        3 => ['title' => 'Test AI', 'file' => 'test.php'],
        4 => ['title' => 'Install', 'file' => 'install.php'],
        5 => ['title' => 'Done', 'file' => 'done.php'],
    ];

    $percent = round(($currentStep / 5) * 100);
    ?>
    <!-- Minimal Responsive Header matching Signup (CuboidPilot brand + Log in) -->
    <header class="sticky top-0 z-40 w-full px-5 py-2.5 sm:py-3 border-b border-[#e7e5de] bg-white flex items-center justify-between shrink-0">
      <a href="../index.html" class="flex items-center gap-2 text-[#111111] no-underline">
        <div class="w-6 h-6 flex items-center justify-center overflow-hidden shrink-0">
          <img src="../assets/logo-black.png" alt="CuboidPilot" class="w-full h-full object-contain">
        </div>
        <span class="text-sm font-semibold tracking-tight text-[#111111]">CuboidPilot</span>
      </a>

      <!-- Top Right Action -->
      <div class="flex items-center gap-2 sm:gap-2.5">
        <span class="text-xs text-stone-500 hidden sm:inline">Already have an account?</span>
        <a href="../login.php" class="inline-flex items-center justify-center text-xs font-medium text-stone-900 border border-stone-900 px-3 py-1 rounded-[4px] hover:bg-stone-50 transition-colors">
          Log in
        </a>
      </div>
    </header>
    <?php
}
