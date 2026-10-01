<?php
/**
 * STEP 5: WHATSAPP BUSINESS CLOUD API PAIRING (PHP + MySQL)
 * Stores pairing into `whatsapp_accounts` and marks `companies.whatsapp_connected = 1`.
 */

require_once __DIR__ . '/onboarding_helper.php';
$ctx = getOnboardingContext();
$pdo = $ctx['pdo'];
$company = $ctx['company'];

// Check existing account
$waStmt = $pdo->prepare("SELECT * FROM `whatsapp_accounts` WHERE `company_id` = ? LIMIT 1");
$waStmt->execute([$company['id']]);
$waAccount = $waStmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $displayNumber = trim($_POST['display_number'] ?? '+91 98200 12345');
    $phoneNumberId = trim($_POST['phone_number_id'] ?? '109283746190284');
    $wabaId = trim($_POST['waba_id'] ?? '384910293847192');

    if ($waAccount) {
        $upd = $pdo->prepare("
            UPDATE `whatsapp_accounts`
            SET `display_number` = ?, `phone_number_id` = ?, `waba_id` = ?, `status` = 'connected', `quality_rating` = 'GREEN'
            WHERE `id` = ?
        ");
        $upd->execute([$displayNumber, $phoneNumberId, $wabaId, $waAccount['id']]);
    } else {
        $ins = $pdo->prepare("
            INSERT INTO `whatsapp_accounts` (`company_id`, `display_number`, `phone_number_id`, `waba_id`, `status`, `quality_rating`)
            VALUES (?, ?, ?, ?, 'connected', 'GREEN')
        ");
        $ins->execute([$company['id'], $displayNumber, $phoneNumberId, $wabaId]);
    }

    // Mark company whatsapp_connected = 1 in database
    $pdo->prepare("UPDATE `companies` SET `whatsapp_connected` = 1 WHERE `id` = ?")->execute([$company['id']]);

    header('Location: ready.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Step 5: WhatsApp Cloud API — CuboidPilot Onboarding</title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Newsreader:opsz,wght@6..72,400;6..72,500&display=swap" rel="stylesheet">
  
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  
  <style>
    body { font-family: 'Inter', -apple-system, sans-serif; background-color: #faf9f7; color: #111111; }
    .serif-heading { font-family: 'Newsreader', Georgia, serif; }
    button, input, select { border-radius: 4px; }
    .form-input {
      width: 100%;
      padding: 8px 12px;
      font-size: 13px;
      border: 1px solid #dcdad3;
      border-radius: 4px;
      background-color: #ffffff;
      color: #111111;
      transition: border-color 0.15s ease;
    }
    .form-input:focus { outline: none; border-color: #111111; box-shadow: 0 0 0 1px #111111; }
  </style>
</head>
<body class="min-h-screen flex flex-col justify-between selection:bg-stone-200">

  <?php renderOnboardingHeader(5); ?>

  <main class="flex-1 flex items-center justify-center p-6">
    <div class="w-full max-w-[540px] bg-white border border-[#e7e5de] rounded-[6px] p-8 shadow-sm">
      
      <div class="mb-6">
        <span class="text-[11px] font-semibold uppercase tracking-wider text-stone-400 block mb-1">Step 5 of 6 • Omnichannel Sync</span>
        <h1 class="text-2xl font-medium tracking-tight text-stone-900 serif-heading">
          Pair Your WhatsApp Cloud API
        </h1>
        <p class="text-xs text-stone-500 mt-1">
          Stored in <code class="font-mono bg-stone-100 px-1 py-0.5 rounded text-stone-700">cuboidpolit_db.whatsapp_accounts</code>. Powers automatic 24/7 continuation when website visitors leave their screen.
        </p>
      </div>

      <form method="POST" action="whatsapp.php" class="space-y-4">
        
        <div>
          <label class="block text-xs font-medium text-stone-700 mb-1">WhatsApp Business Sender Number</label>
          <input 
            type="text" 
            name="display_number" 
            value="<?= htmlspecialchars($waAccount['display_number'] ?? '+91 98200 12345') ?>" 
            required 
            class="form-input font-mono"
            placeholder="+91 98200 12345"
          >
          <p class="text-[11px] text-stone-400 mt-1">Registered WABA number visible to your leads.</p>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-medium text-stone-700 mb-1">Meta Phone Number ID</label>
            <input 
              type="text" 
              name="phone_number_id" 
              value="<?= htmlspecialchars($waAccount['phone_number_id'] ?? '109283746190284') ?>" 
              class="form-input font-mono text-xs"
            >
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700 mb-1">WhatsApp Business Account (WABA ID)</label>
            <input 
              type="text" 
              name="waba_id" 
              value="<?= htmlspecialchars($waAccount['waba_id'] ?? '384910293847192') ?>" 
              class="form-input font-mono text-xs"
            >
          </div>
        </div>

        <!-- Automated Alert Rules -->
        <div class="p-3.5 bg-emerald-50/50 border border-emerald-200 rounded-[4px] space-y-2">
          <div class="flex items-center gap-2">
            <i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-700"></i>
            <span class="text-xs font-semibold text-emerald-900">High-Intent Lead Immediate WhatsApp Alert</span>
          </div>
          <p class="text-[11px] text-emerald-800 leading-relaxed">
            When a visitor asks for tuition fees or EMI, CuboidPilot automatically dispatches a summary alert to your sales team's WhatsApp phone.
          </p>
        </div>

        <div>
          <label class="block text-xs font-medium text-stone-700 mb-1">On-Call Sales Manager WhatsApp Number (For alerts)</label>
          <input type="text" value="+91 98111 88776" class="form-input font-mono text-xs" placeholder="+91 98111 88776">
        </div>

        <div class="pt-4 border-t border-[#f0eee8] flex items-center justify-between">
          <a href="website.php" class="text-xs text-stone-500 hover:text-stone-900 flex items-center gap-1">
            <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
            <span>Back</span>
          </a>
          
          <button type="submit" class="btn-primary py-2 px-5 bg-[#111111] hover:bg-black text-white text-xs font-medium rounded-[4px] transition-colors flex items-center gap-1.5">
            <span>Verify & Complete</span>
            <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
          </button>
        </div>

      </form>
    </div>
  </main>

  <footer class="py-4 text-center text-xs text-stone-400 border-t border-[#f0eee8] bg-white">
    © 2026 CuboidPilot Technologies • Enterprise Conversational Platform
  </footer>

  <script>
    if (window.lucide) lucide.createIcons();
  </script>
</body>
</html>
