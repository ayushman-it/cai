<?php
/**
 * STEP 2: CONVERSATIONAL GOALS & WIDGET PERSONA (PHP + MySQL)
 * Reads & Updates `widget_settings` in `cuboidpolit_db`.
 */

require_once __DIR__ . '/onboarding_helper.php';
$ctx = getOnboardingContext();
$pdo = $ctx['pdo'];
$company = $ctx['company'];
$widget = $ctx['widget'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $greetingHeading = trim($_POST['greeting_heading'] ?? $widget['greeting_heading']);
    $greetingSubheading = trim($_POST['greeting_subheading'] ?? $widget['greeting_subheading']);
    $accentColor = trim($_POST['accent_color'] ?? '#111111');
    $position = trim($_POST['position'] ?? 'bottom_right');
    $requirePhone = !empty($_POST['require_phone']) ? 1 : 0;
    $enableWhatsapp = !empty($_POST['enable_whatsapp']) ? 1 : 0;

    $stmt = $pdo->prepare("
        UPDATE `widget_settings`
        SET `greeting_heading` = ?, `greeting_subheading` = ?, `accent_color` = ?, `position` = ?, `require_phone_for_pricing` = ?, `enable_whatsapp_continue` = ?
        WHERE `company_id` = ?
    ");
    $stmt->execute([$greetingHeading, $greetingSubheading, $accentColor, $position, $requirePhone, $enableWhatsapp, $company['id']]);

    header('Location: ai.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Step 2: Goals & Persona — CuboidPilot Onboarding</title>
  
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

  <?php renderOnboardingHeader(2); ?>

  <main class="flex-1 flex items-center justify-center p-6">
    <div class="w-full max-w-[560px] bg-white border border-[#e7e5de] rounded-[6px] p-8 shadow-sm">
      
      <div class="mb-6">
        <span class="text-[11px] font-semibold uppercase tracking-wider text-stone-400 block mb-1">Step 2 of 6 • Conversational Persona</span>
        <h1 class="text-2xl font-medium tracking-tight text-stone-900 serif-heading">
          Configure Your “Ask Anything” Desk
        </h1>
        <p class="text-xs text-stone-500 mt-1">
          Saved directly into <code class="font-mono bg-stone-100 px-1 py-0.5 rounded text-stone-700">cuboidpolit_db.widget_settings</code> for company #<?= $company['id'] ?>.
        </p>
      </div>

      <form method="POST" action="goals.php" class="space-y-4">
        
        <div>
          <label class="block text-xs font-medium text-stone-700 mb-1">Widget Headline (Top Greeting)</label>
          <input type="text" name="greeting_heading" value="<?= htmlspecialchars($widget['greeting_heading'] ?? 'Ask anything about our admissions, fee structure & EMI') ?>" required class="form-input">
        </div>

        <div>
          <label class="block text-xs font-medium text-stone-700 mb-1">Widget Subheading</label>
          <input type="text" name="greeting_subheading" value="<?= htmlspecialchars($widget['greeting_subheading'] ?? 'Instant answers powered by CuboidPilot AI') ?>" required class="form-input">
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-medium text-stone-700 mb-1">Brand Accent Color</label>
            <div class="flex items-center gap-2">
              <input type="color" name="accent_color" value="<?= htmlspecialchars($widget['accent_color'] ?? '#111111') ?>" class="w-8 h-8 p-0 border border-[#dcdad3] rounded cursor-pointer">
              <input type="text" value="<?= htmlspecialchars($widget['accent_color'] ?? '#111111') ?>" class="form-input text-xs font-mono" readonly>
            </div>
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700 mb-1">Screen Position</label>
            <select name="position" class="form-input bg-white">
              <option value="bottom_right" <?= ($widget['position'] ?? '') === 'bottom_right' ? 'selected' : '' ?>>Bottom Right</option>
              <option value="bottom_left" <?= ($widget['position'] ?? '') === 'bottom_left' ? 'selected' : '' ?>>Bottom Left</option>
            </select>
          </div>
        </div>

        <!-- Strategy Switches -->
        <div class="pt-2 space-y-3">
          <label class="flex items-start gap-3 p-3 border border-[#e7e5de] rounded-[4px] hover:bg-stone-50 cursor-pointer transition-colors bg-stone-50/50">
            <input type="checkbox" name="require_phone" value="1" <?= !empty($widget['require_phone_for_pricing']) ? 'checked' : '' ?> class="mt-0.5 rounded-[3px] text-stone-900 focus:ring-0">
            <div>
              <div class="text-xs font-semibold text-stone-900">Enforce Lead Qualification Gate</div>
              <div class="text-[11px] text-stone-500">Intelligently capture visitor mobile or WhatsApp before revealing high-value fee quotes.</div>
            </div>
          </label>

          <label class="flex items-start gap-3 p-3 border border-[#e7e5de] rounded-[4px] hover:bg-stone-50 cursor-pointer transition-colors bg-stone-50/50">
            <input type="checkbox" name="enable_whatsapp" value="1" <?= !empty($widget['enable_whatsapp_continue']) ? 'checked' : '' ?> class="mt-0.5 rounded-[3px] text-stone-900 focus:ring-0">
            <div>
              <div class="text-xs font-semibold text-stone-900">Enable Seamless WhatsApp Handoff</div>
              <div class="text-[11px] text-stone-500">Permit visitor to click “Continue on WhatsApp” to mirror and preserve the chat on their phone.</div>
            </div>
          </label>
        </div>

        <div class="pt-4 border-t border-[#f0eee8] flex items-center justify-between">
          <a href="business.php" class="text-xs text-stone-500 hover:text-stone-900 flex items-center gap-1">
            <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
            <span>Back</span>
          </a>
          
          <button type="submit" class="btn-primary py-2 px-5 bg-[#111111] hover:bg-black text-white text-xs font-medium rounded-[4px] transition-colors flex items-center gap-1.5">
            <span>Save & Ground AI</span>
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
