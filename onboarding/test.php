<?php
/**
 * STEP 3: TEST AI (PHP + MySQL)
 * Interactive live conversation testing with authentic embedded Cai widget.
 * Real grounded AI responses via backend, dark checkboxes, and live real-time config sync.
 */

require_once __DIR__ . '/onboarding_helper.php';
$ctx = getOnboardingContext();
$pdo = $ctx['pdo'];
$company = $ctx['company'];
$widget = $ctx['widget'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $assistantName = trim($_POST['assistant_name'] ?? ($company['name'] . ' AI'));
    $greeting = trim($_POST['greeting'] ?? ($widget['greeting_heading'] ?? 'Hi there 👋 How can I help you today?'));
    $tone = trim($_POST['tone'] ?? 'Professional');
    $fallbackAction = trim($_POST['fallback_action'] ?? 'lead_capture');

    // Update in MySQL
    $stmt = $pdo->prepare("
        UPDATE `widget_settings`
        SET `brand_name` = ?, `greeting_heading` = ?
        WHERE `company_id` = ?
    ");
    $stmt->execute([$assistantName, $greeting, $company['id']]);

    header('Location: install.php');
    exit;
}

$defaultGreeting = !empty($widget['greeting_heading']) 
    ? $widget['greeting_heading'] 
    : "Hi there 👋 Welcome to " . ($company['name'] ?? 'our business') . "! How can I help you today?";

$defaultBrand = !empty($widget['brand_name']) 
    ? $widget['brand_name'] 
    : ($company['name'] . ' AI');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Test your AI — CuboidPilot</title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Newsreader:opsz,wght@6..72,400;6..72,500&display=swap" rel="stylesheet">
  
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  
  <style>
    body { font-family: 'Inter', -apple-system, sans-serif; background-color: #ffffff; color: #111111; }
    .serif-heading { font-family: 'Newsreader', Georgia, serif; }
    button, input, select, textarea { border-radius: 4px; }
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

    /* Modern Dark Checkbox styling */
    .dark-checkbox {
      accent-color: #111111 !important;
      width: 16px;
      height: 16px;
      cursor: pointer;
    }
  </style>
</head>
<body class="min-h-screen flex flex-col justify-between selection:bg-stone-200">

  <?php renderOnboardingHeader(3); ?>

  <main class="flex-1 flex items-center justify-center p-3 sm:p-6 my-2">
    <div class="w-full max-w-[980px] grid grid-cols-1 lg:grid-cols-12 gap-5 sm:gap-6 items-stretch">
      
      <!-- Left Column: Authentic Live Cai Assistant Widget (6 cols on desktop) -->
      <div class="lg:col-span-6 bg-white border border-[#e7e5de] rounded-[6px] p-4 sm:p-5 shadow-xs flex flex-col justify-between min-h-[560px]">
        <div class="flex-1 flex flex-col">
          <div class="pb-3 border-b border-[#e7e5de] flex items-center justify-between">
            <div>
              <span class="text-[11px] font-semibold uppercase tracking-wider text-stone-400 block mb-1">Step 3 of 5</span>
              <h1 class="text-xl sm:text-2xl font-medium tracking-tight text-stone-900 serif-heading">
                Test your AI
              </h1>
            </div>
            <div class="flex items-center gap-2">
              <span class="text-[11px] text-emerald-800 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200 font-medium flex items-center gap-1.5">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-600 animate-pulse"></span>
                Live Grounded
              </span>
            </div>
          </div>

          <!-- Authentic Live Widget Embedded Container (Mounted with real Fin AI Shadow-DOM) -->
          <div id="cuboidpilot-embed-root" class="w-full flex-1 mt-3 min-h-[460px] flex flex-col"></div>
        </div>

        <!-- Chat Helper / Improve Knowledge Link -->
        <div class="pt-3 border-t border-[#f0eee8] flex items-center justify-between text-[11px] text-stone-500 mt-2">
          <span>Not quite right?</span>
          <a href="train.php" class="text-stone-900 font-medium underline underline-offset-2 hover:text-black">
            Improve Knowledge &rarr;
          </a>
        </div>
      </div>

      <!-- Right Column: Basic AI Configuration with Live Reactive Sync (6 cols on desktop) -->
      <div class="lg:col-span-6 bg-white border border-[#e7e5de] rounded-[6px] p-5 sm:p-6 shadow-xs flex flex-col justify-between">
        <div>
          <h2 class="text-base font-semibold text-stone-900 mb-1">
            Basic Assistant Configuration
          </h2>
          <p class="text-xs text-stone-500 mb-4 leading-relaxed">
            Keep your conversational experience calm and aligned with your business tone. Changes reflect live on the left.
          </p>

          <form id="ai-config-form" method="POST" action="test.php" class="space-y-3.5">
            <div>
              <label class="block text-xs font-medium text-stone-700 mb-1">Assistant Name</label>
              <input 
                type="text" 
                id="cfg-assistant-name"
                name="assistant_name" 
                value="<?= htmlspecialchars($defaultBrand) ?>" 
                class="form-input" 
                placeholder="e.g. Cai"
                oninput="syncLiveAssistant()"
              >
            </div>

            <div>
              <label class="block text-xs font-medium text-stone-700 mb-1">Greeting</label>
              <textarea 
                id="cfg-greeting"
                name="greeting" 
                rows="2" 
                class="form-input text-xs leading-relaxed" 
                placeholder="Hi there 👋 How can I help you today?"
                oninput="syncLiveAssistant()"
              ><?= htmlspecialchars($defaultGreeting) ?></textarea>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-medium text-stone-700 mb-1">Tone</label>
                <select name="tone" id="cfg-tone" class="form-input bg-white" onchange="syncLiveAssistant()">
                  <option selected>Professional</option>
                  <option>Friendly</option>
                  <option>Concise</option>
                </select>
              </div>

              <div>
                <label class="block text-xs font-medium text-stone-700 mb-1">Primary Language</label>
                <select name="language" id="cfg-language" class="form-input bg-white">
                  <option selected>English</option>
                  <option>Hindi / Hinglish</option>
                  <option>Spanish</option>
                  <option>French</option>
                </select>
              </div>
            </div>

            <!-- Dark Styled Checkboxes for Lead Capture -->
            <div>
              <label class="block text-xs font-medium text-stone-700 mb-1.5">Lead Capture Fields</label>
              <div class="flex flex-wrap items-center gap-4 text-xs text-stone-700 py-1">
                <label class="flex items-center gap-2 cursor-pointer select-none">
                  <input type="checkbox" checked disabled class="dark-checkbox bg-stone-900 text-white rounded cursor-not-allowed">
                  <span class="font-medium text-stone-800">Name <span class="text-stone-400 font-normal">(Required)</span></span>
                </label>

                <label class="flex items-center gap-2 cursor-pointer select-none">
                  <input type="checkbox" name="capture_phone" checked class="dark-checkbox rounded">
                  <span class="font-medium text-stone-800">Phone / WhatsApp</span>
                </label>

                <label class="flex items-center gap-2 cursor-pointer select-none">
                  <input type="checkbox" name="capture_email" checked class="dark-checkbox rounded">
                  <span class="font-medium text-stone-800">Email</span>
                </label>
              </div>
            </div>

            <div>
              <label class="block text-xs font-medium text-stone-700 mb-1">When AI cannot answer</label>
              <select name="fallback_action" class="form-input bg-white">
                <option value="lead_capture" selected>Collect lead information for follow-up</option>
                <option value="human_routing">Suggest instant human team escalation</option>
              </select>
            </div>
          </form>
        </div>

        <!-- Navigation Buttons -->
        <div class="pt-4 border-t border-[#f0eee8] flex items-center justify-between mt-4">
          <a href="train.php" class="text-xs text-stone-500 hover:text-stone-900 flex items-center gap-1">
            <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
            <span>Back</span>
          </a>

          <button type="submit" form="ai-config-form" class="btn-primary py-2.5 px-5 bg-[#111111] hover:bg-black text-white text-xs font-medium rounded-[4px] transition-colors flex items-center gap-1.5">
            <span>Continue to Install</span>
            <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
          </button>
        </div>
      </div>

    </div>
  </main>

  <footer class="py-4 text-center text-xs text-stone-400 border-t border-[#f0eee8] bg-white">
    © 2026 CuboidPilot Technologies Inc.
  </footer>

  <!-- Embed Authentic Live Cai Widget in Embedded Mode -->
  <script 
    src="../widget.js?v=5.0.0" 
    data-company="<?= htmlspecialchars($company['company_key']) ?>" 
    data-embedded="true" 
    data-target="#cuboidpilot-embed-root"
    data-theme="dark"
  ></script>

  <script>
    if (window.lucide) lucide.createIcons();

    // Live Synchronizer: Instantly propagates form changes to the embedded Cai Assistant
    function syncLiveAssistant() {
      const nameInput = document.getElementById('cfg-assistant-name');
      const greetingInput = document.getElementById('cfg-greeting');
      if (!nameInput || !greetingInput) return;

      const asstName = nameInput.value.trim() || 'Cai';
      const greeting = greetingInput.value.trim();

      if (window.CuboidPilot && typeof window.CuboidPilot.updateConfig === 'function') {
        window.CuboidPilot.updateConfig({
          assistant_name: asstName,
          brand_name: asstName,
          greeting_heading: greeting
        });
      }
    }

    // Trigger initial sync once widget loads
    window.addEventListener('load', () => {
      setTimeout(syncLiveAssistant, 300);
    });
  </script>
</body>
</html>
