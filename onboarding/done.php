<?php
/**
 * STEP 5: ONBOARDING COMPLETE & READY (PHP + MySQL)
 * Finalizes onboarding, records trial state, and directs user to dashboard.
 */

require_once __DIR__ . '/onboarding_helper.php';
$ctx = getOnboardingContext();
$pdo = $ctx['pdo'];
$company = $ctx['company'];

$installed = !empty($_GET['installed']);

// Mark onboarding complete in MySQL
$pdo->prepare("UPDATE `companies` SET `onboarding_completed` = 1, `status` = 'trial' WHERE `id` = ?")->execute([$company['id']]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Your workspace is ready — CuboidPilot</title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Newsreader:opsz,wght@6..72,400;6..72,500&display=swap" rel="stylesheet">
  
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  
  <style>
    body { font-family: 'Inter', -apple-system, sans-serif; background-color: #ffffff; color: #111111; }
    .serif-heading { font-family: 'Newsreader', Georgia, serif; }
    button, input, select { border-radius: 4px; }
  </style>
</head>
<body class="min-h-screen flex flex-col justify-between selection:bg-stone-200">

  <?php renderOnboardingHeader(5); ?>

  <main class="flex-1 flex items-center justify-center p-3 sm:p-6 my-2">
    <div class="w-full max-w-[500px] bg-white border border-[#e7e5de] rounded-[6px] p-5 sm:p-8 shadow-xs text-center space-y-6">
      
      <!-- Icon & Heading -->
      <div>
        <div class="w-11 h-11 rounded-[4px] bg-[#111111] text-white flex items-center justify-center mx-auto mb-3 font-semibold text-sm">
          ✓
        </div>
        <h1 class="text-xl sm:text-2xl font-medium tracking-tight text-stone-900 serif-heading">
          Your workspace is ready.
        </h1>
        <p class="text-xs text-stone-500 mt-1">
          Welcome to <?= htmlspecialchars($company['name']) ?> on CuboidPilot.
        </p>
      </div>

      <!-- Setup Summary Checklist (Section 17) -->
      <div class="border border-[#e7e5de] rounded-[4px] p-4 text-left text-xs divide-y divide-[#f0eee8] bg-stone-50/50">
        <div class="flex items-center justify-between py-2 text-stone-800">
          <span class="flex items-center gap-2">
            <span class="text-emerald-700 font-bold">✓</span>
            <span>Business context configured</span>
          </span>
          <span class="text-[11px] text-stone-400">Complete</span>
        </div>

        <div class="flex items-center justify-between py-2 text-stone-800">
          <span class="flex items-center gap-2">
            <span class="text-emerald-700 font-bold">✓</span>
            <span>AI trained on verified facts</span>
          </span>
          <span class="text-[11px] text-stone-400">Ready</span>
        </div>

        <div class="flex items-center justify-between py-2 text-stone-800">
          <span class="flex items-center gap-2">
            <span class="text-emerald-700 font-bold">✓</span>
            <span>AI tested in conversational sandbox</span>
          </span>
          <span class="text-[11px] text-stone-400">Verified</span>
        </div>

        <div class="flex items-center justify-between py-2 text-stone-800">
          <span class="flex items-center gap-2">
            <?php if ($installed): ?>
              <span class="text-emerald-700 font-bold">✓</span>
              <span>Website widget installed</span>
            <?php else: ?>
              <span class="text-stone-400 font-bold">○</span>
              <span class="text-stone-600">Website widget not installed yet</span>
            <?php endif; ?>
          </span>
          <span class="text-[11px] <?= $installed ? 'text-emerald-700 font-semibold' : 'text-stone-400' ?>">
            <?= $installed ? 'Connected' : 'Pending' ?>
          </span>
        </div>
      </div>

      <!-- Trial Status Ribbon -->
      <div class="p-2.5 bg-stone-100 rounded-[4px] text-xs text-stone-700 flex items-center justify-between">
        <span class="font-medium">14-Day Free Trial Active</span>
        <span class="text-stone-500 font-mono text-[11px]">14 days remaining</span>
      </div>

      <!-- Actions (Section 17: Open Dashboard & Test AI Again) -->
      <div class="space-y-2 pt-2">
        <a 
          href="../app/overview.html<?= $installed ? '' : '?first_time=1' ?>" 
          class="w-full py-2.5 bg-[#111111] hover:bg-black text-white text-xs font-medium rounded-[4px] transition-colors flex items-center justify-center gap-1.5"
        >
          <span>Open Dashboard</span>
          <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
        </a>

        <a 
          href="test.php" 
          class="w-full py-2 bg-white hover:bg-stone-50 text-stone-700 border border-stone-300 text-xs font-medium rounded-[4px] transition-colors flex items-center justify-center gap-1.5"
        >
          <span>Test AI Again</span>
        </a>
      </div>

    </div>
  </main>

  <footer class="py-4 text-center text-xs text-stone-400 border-t border-[#f0eee8] bg-white">
    © 2026 CuboidPilot Technologies Inc.
  </footer>

  <script>
    if (window.lucide) lucide.createIcons();
  </script>
</body>
</html>
