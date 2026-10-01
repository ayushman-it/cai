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
    <!-- Minimal Responsive Header -->
    <header class="py-3 sm:py-3.5 px-4 sm:px-8 border-b border-[#e7e5de] bg-white flex items-center justify-between">
      <div class="flex items-center gap-2.5 sm:gap-3">
        <a href="../index.html" class="flex items-center gap-2 text-[#111111] no-underline">
          <div class="w-6 h-6 rounded-[3px] bg-[#111111] text-white flex items-center justify-center font-bold text-[10px] tracking-wider shrink-0">
            CP
          </div>
          <span class="text-xs font-semibold tracking-tight hidden sm:inline">CuboidPilot</span>
        </a>
        <span class="text-stone-300">|</span>
        <span class="text-[11.5px] text-stone-600 font-mono font-medium">Step <?= $currentStep ?> of 5</span>
        <span class="text-xs text-stone-400 font-medium hidden sm:inline">· <?= htmlspecialchars($steps[$currentStep]['title']) ?></span>
      </div>

      <!-- Stepper Nav items (Desktop / Tablet) -->
      <nav class="hidden md:flex items-center gap-1.5 lg:gap-2 text-xs">
        <?php foreach ($steps as $num => $s): ?>
          <?php if ($num < $currentStep): ?>
            <a href="<?= $s['file'] ?>" class="text-stone-600 hover:text-stone-900 font-medium flex items-center gap-1 transition-colors">
              <span class="w-4 h-4 rounded-full bg-stone-900 text-white text-[9px] flex items-center justify-center">✓</span>
              <span><?= $s['title'] ?></span>
            </a>
            <span class="text-stone-300">&rarr;</span>
          <?php elseif ($num === $currentStep): ?>
            <span class="text-stone-900 font-semibold flex items-center gap-1.5 px-2 py-0.5 bg-stone-100 rounded-[3px]">
              <span class="w-4 h-4 rounded-full bg-stone-900 text-white text-[9px] flex items-center justify-center font-bold"><?= $num ?></span>
              <span><?= $s['title'] ?></span>
            </span>
            <?php if ($num < 5): ?><span class="text-stone-300">&rarr;</span><?php endif; ?>
          <?php else: ?>
            <span class="text-stone-400 flex items-center gap-1">
              <span class="w-4 h-4 rounded-full border border-stone-300 text-stone-400 text-[9px] flex items-center justify-center"><?= $num ?></span>
              <span><?= $s['title'] ?></span>
            </span>
            <?php if ($num < 5): ?><span class="text-stone-300">&rarr;</span><?php endif; ?>
          <?php endif; ?>
        <?php endforeach; ?>
      </nav>

      <div class="flex items-center gap-2">
        <a href="../app/overview.html" class="text-[11px] text-stone-500 hover:text-stone-900 hover:underline">
          <span class="hidden sm:inline">Skip to Dashboard</span>
          <span class="sm:hidden">Skip</span> &rarr;
        </a>
      </div>
    </header>

    <!-- Progress Line (Positioned cleanly BELOW the header) -->
    <div class="w-full bg-[#f0eee8] h-[2.5px] relative overflow-hidden">
      <div 
        id="onboarding-progress-bar"
        class="bg-[#111111] h-full transition-all duration-500 ease-out" 
        style="width: <?= $percent ?>%;"
      ></div>
    </div>
    <?php
}
