<?php
/**
 * STEP 4: WEBSITE WIDGET INTEGRATION & SNIPPET (PHP + MySQL)
 * Generates embed code using the actual `company_key` from `companies`.
 */

require_once __DIR__ . '/onboarding_helper.php';
$ctx = getOnboardingContext();
$pdo = $ctx['pdo'];
$company = $ctx['company'];
$widget = $ctx['widget'];

$companyKey = $company['company_key'] ?? ('cp_live_' . bin2hex(random_bytes(16)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Step 4: Connect Website Widget — CuboidPilot Onboarding</title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Newsreader:opsz,wght@6..72,400;6..72,500&display=swap" rel="stylesheet">
  
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  
  <style>
    body { font-family: 'Inter', -apple-system, sans-serif; background-color: #faf9f7; color: #111111; }
    .serif-heading { font-family: 'Newsreader', Georgia, serif; }
    button, input, select { border-radius: 4px; }
  </style>
</head>
<body class="min-h-screen flex flex-col justify-between selection:bg-stone-200">

  <?php renderOnboardingHeader(4); ?>

  <main class="flex-1 flex items-center justify-center p-6">
    <div class="w-full max-w-[840px] grid grid-cols-1 md:grid-cols-2 gap-6">
      
      <!-- Left Column: Snippet Generator -->
      <div class="bg-white border border-[#e7e5de] rounded-[6px] p-7 shadow-sm space-y-4">
        <div>
          <span class="text-[11px] font-semibold uppercase tracking-wider text-stone-400 block mb-1">Step 4 of 6 • Website Embed</span>
          <h1 class="text-xl font-medium tracking-tight text-stone-900 serif-heading">
            Embed the “Ask Anything” Widget
          </h1>
          <p class="text-xs text-stone-500 mt-1">
            Copy this 1-line script into your website HTML before the closing <code class="font-mono bg-stone-100 px-1 py-0.5 rounded text-stone-700">&lt;/body&gt;</code> tag.
          </p>
        </div>

        <div>
          <div class="flex items-center justify-between text-xs text-stone-600 mb-1.5">
            <span class="font-medium">Production JavaScript Snippet</span>
            <span class="text-[11px] text-stone-400 font-mono">Company ID #<?= $company['id'] ?></span>
          </div>

          <div class="relative bg-stone-900 text-stone-100 p-3.5 rounded-[4px] font-mono text-xs leading-relaxed overflow-x-auto border border-stone-800">
            &lt;!-- Cai by CuboidSoft AI Agent Widget --&gt;<br>
            &lt;script src="https://cai.cuboidsoft.in/widget.js"<br>
            &nbsp;&nbsp;data-company="<span class="text-emerald-400 font-bold"><?= htmlspecialchars($companyKey) ?></span>"<br>
            &nbsp;&nbsp;data-theme="dark"<br>
            &nbsp;&nbsp;async&gt;&lt;/script&gt;
          </div>

          <button 
            type="button" 
            onclick="copySnippet()" 
            id="copy-btn"
            class="mt-2.5 w-full py-2 bg-stone-100 hover:bg-stone-200 text-stone-800 border border-stone-300 text-xs font-medium rounded-[4px] transition-colors flex items-center justify-center gap-1.5"
          >
            <i data-lucide="copy" class="w-3.5 h-3.5"></i>
            <span id="copy-text">Copy Embed Code</span>
          </button>
        </div>

        <!-- CMS Integration Quick Guides -->
        <div class="pt-2 border-t border-[#f0eee8] space-y-2">
          <div class="text-xs font-semibold text-stone-800">Compatible with:</div>
          <div class="flex flex-wrap gap-2 text-[11px] text-stone-600 font-medium">
            <span class="px-2 py-0.5 bg-stone-100 rounded-[3px]">WordPress</span>
            <span class="px-2 py-0.5 bg-stone-100 rounded-[3px]">Webflow</span>
            <span class="px-2 py-0.5 bg-stone-100 rounded-[3px]">Shopify</span>
            <span class="px-2 py-0.5 bg-stone-100 rounded-[3px]">Next.js / React</span>
            <span class="px-2 py-0.5 bg-stone-100 rounded-[3px]">HTML / PHP</span>
          </div>
        </div>

        <div class="pt-3 border-t border-[#f0eee8] flex items-center justify-between">
          <a href="ai.php" class="text-xs text-stone-500 hover:text-stone-900 flex items-center gap-1">
            <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
            <span>Back</span>
          </a>
          
          <a href="whatsapp.php" class="btn-primary py-2 px-5 bg-[#111111] hover:bg-black text-white text-xs font-medium rounded-[4px] transition-colors flex items-center gap-1.5">
            <span>Next: WhatsApp Sync</span>
            <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
          </a>
        </div>
      </div>

      <!-- Right Column: Live Browser & Widget Simulation -->
      <div class="bg-stone-100 border border-[#e7e5de] rounded-[6px] p-4 flex flex-col justify-between shadow-sm relative overflow-hidden min-h-[380px]">
        
        <!-- Mock Browser Frame -->
        <div class="bg-white rounded-[4px] border border-stone-200 p-2 flex items-center gap-2 mb-3">
          <div class="flex items-center gap-1">
            <div class="w-2.5 h-2.5 rounded-full bg-red-400"></div>
            <div class="w-2.5 h-2.5 rounded-full bg-amber-400"></div>
            <div class="w-2.5 h-2.5 rounded-full bg-emerald-400"></div>
          </div>
          <div class="bg-stone-50 border border-stone-200 text-stone-400 text-[10px] px-2 py-0.5 rounded flex-1 truncate font-mono">
            https://<?= htmlspecialchars($company['slug']) ?>.cuboidpilot.com
          </div>
        </div>

        <!-- Simulated Website Content -->
        <div class="flex-1 bg-white border border-stone-200 rounded-[4px] p-5 relative">
          <div class="text-sm font-semibold text-stone-900 mb-1">
            <?= htmlspecialchars($company['name']) ?>
          </div>
          <div class="text-xs text-stone-500 leading-relaxed mb-4">
            Welcome to our online portal. Browse programs, compare tuition fee schedules, or chat with our automated AI assistant below.
          </div>

          <div class="w-28 h-3 bg-stone-100 rounded mb-2"></div>
          <div class="w-48 h-2 bg-stone-100 rounded mb-1.5"></div>
          <div class="w-36 h-2 bg-stone-100 rounded"></div>

          <!-- The Floating Widget Preview in Corner -->
          <div class="absolute bottom-3 right-3 flex items-center gap-2">
            <div class="bg-white border border-stone-300 text-stone-800 text-[11px] font-medium px-2.5 py-1.5 rounded-[4px] shadow-sm flex items-center gap-1.5 animate-bounce">
              <span>Ask anything...</span>
            </div>
            <div class="w-10 h-10 rounded-[4px] bg-[#111111] text-white flex items-center justify-center font-bold text-xs shadow-md">
              <i data-lucide="message-square" class="w-5 h-5"></i>
            </div>
          </div>
        </div>

      </div>

    </div>
  </main>

  <footer class="py-4 text-center text-xs text-stone-400 border-t border-[#f0eee8] bg-white">
    © 2026 CuboidPilot Technologies • Enterprise Conversational Platform
  </footer>

  <script>
    if (window.lucide) lucide.createIcons();

    function copySnippet() {
      const code = `<script src="https://cai.cuboidsoft.in/widget.js" data-company="<?= htmlspecialchars($companyKey) ?>" data-theme="dark" async><\/script>`;
      navigator.clipboard.writeText(code).then(() => {
        document.getElementById('copy-text').innerText = 'Copied to Clipboard!';
        document.getElementById('copy-btn').classList.add('bg-emerald-50', 'text-emerald-800', 'border-emerald-300');
        setTimeout(() => {
          document.getElementById('copy-text').innerText = 'Copy Embed Code';
          document.getElementById('copy-btn').classList.remove('bg-emerald-50', 'text-emerald-800', 'border-emerald-300');
        }, 2000);
      });
    }
  </script>
</body>
</html>
