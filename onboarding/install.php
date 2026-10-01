<?php
/**
 * STEP 4: INSTALL WIDGET (PHP + MySQL)
 * Generates official embed snippet with verification and NON-BLOCKING skip option.
 */

require_once __DIR__ . '/onboarding_helper.php';
$ctx = getOnboardingContext();
$pdo = $ctx['pdo'];
$company = $ctx['company'];
$widget = $ctx['widget'];

$companyKey = $company['company_key'] ?? ('cp_live_' . bin2hex(random_bytes(12)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Add CuboidPilot to your website — CuboidPilot</title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Newsreader:opsz,wght@6..72,400;6..72,500&display=swap" rel="stylesheet">
  
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  
  <style>
    body { font-family: 'Inter', -apple-system, sans-serif; background-color: #ffffff; color: #111111; }
    .serif-heading { font-family: 'Newsreader', Georgia, serif; }
    button, input, select { border-radius: 4px; }

    /* Scan sweep animation for simulated installation */
    @keyframes scanSweep {
      0% { top: 0; opacity: 0; }
      20% { opacity: 1; }
      80% { opacity: 1; }
      100% { top: 90%; opacity: 0; }
    }
    .scanner-line {
      position: absolute;
      left: 0;
      right: 0;
      height: 2px;
      background: linear-gradient(90deg, transparent, #10b981, transparent);
      box-shadow: 0 0 12px #10b981;
      display: none;
      z-index: 20;
    }
    .scanning .scanner-line {
      display: block;
      animation: scanSweep 1.6s cubic-bezier(0.4, 0, 0.2, 1) infinite;
    }

    /* Spring pop-in for widget */
    @keyframes springPop {
      0% { transform: scale(0.6); opacity: 0; }
      70% { transform: scale(1.08); opacity: 1; }
      100% { transform: scale(1); opacity: 1; }
    }
    .animate-widget-pop {
      animation: springPop 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
    }

    /* Pulsing status ring */
    @keyframes ringPulse {
      0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
      70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
      100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }
    .pulse-indicator {
      animation: ringPulse 2s infinite;
    }
  </style>
</head>
<body class="min-h-screen flex flex-col justify-between selection:bg-stone-200">

  <?php renderOnboardingHeader(4); ?>

  <main class="flex-1 flex items-center justify-center p-3 sm:p-6 my-2">
    <div class="w-full max-w-[960px] grid grid-cols-1 lg:grid-cols-12 gap-5 sm:gap-6 items-stretch">
      
      <!-- Left Column: Installation Code & Verification (7 cols on desktop) -->
      <div class="lg:col-span-7 bg-white border border-[#e7e5de] rounded-[6px] p-5 sm:p-7 shadow-xs space-y-5 flex flex-col justify-between">
        <div class="space-y-4">
          <div>
            <div class="flex items-center gap-2 mb-1">
              <span class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Step 4 of 5</span>
              <span class="text-stone-300">•</span>
              <span class="text-[11px] text-emerald-800 bg-emerald-50 px-2 py-0.2 rounded border border-emerald-200 font-medium">Non-blocking</span>
            </div>
            <h1 class="text-xl sm:text-2xl font-medium tracking-tight text-stone-900 serif-heading">
              Add CuboidPilot to your website
            </h1>
            <p class="text-xs text-stone-500 mt-1 leading-relaxed">
              Paste this snippet right before the closing <code class="font-mono bg-stone-100 px-1 py-0.5 rounded text-stone-700">&lt;/body&gt;</code> tag on your website to activate your AI desk.
            </p>
          </div>

          <!-- Official Code Snippet Box -->
          <div>
            <div class="flex items-center justify-between text-xs text-stone-600 mb-1.5 font-medium">
              <span class="flex items-center gap-1.5">
                <i data-lucide="code" class="w-3.5 h-3.5 text-stone-500"></i>
                JavaScript Embed Snippet
              </span>
              <span class="text-[11px] text-stone-400 font-mono hidden sm:inline">
                Domain: <?= htmlspecialchars($company['slug'] ?? 'workspace') ?>.com
              </span>
            </div>

            <div class="relative group">
              <pre class="bg-stone-900 text-stone-100 p-3.5 sm:p-4 rounded-[4px] font-mono text-[11.5px] sm:text-xs leading-relaxed border border-stone-800 select-all overflow-x-auto">&lt;script
  src="https://cai.cuboidsoft.in/widget.js"
  data-company="<span class="text-amber-400 font-bold"><?= htmlspecialchars($companyKey) ?></span>"
  async&gt;
&lt;/script&gt;</pre>
            </div>

            <!-- Action buttons: Copy, Email & Test Harness -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 mt-2.5">
              <button 
                type="button" 
                onclick="copyCode()" 
                id="copy-btn" 
                class="w-full py-2 px-3 bg-stone-100 hover:bg-stone-200 text-stone-800 border border-stone-300 text-xs font-medium rounded-[4px] transition-all flex items-center justify-center gap-1.5"
              >
                <i data-lucide="copy" class="w-3.5 h-3.5" id="copy-icon"></i>
                <span id="copy-label">Copy Snippet</span>
              </button>

              <button 
                type="button" 
                onclick="openDevModal()" 
                class="w-full py-2 px-3 bg-white hover:bg-stone-50 text-stone-700 border border-stone-300 text-xs font-medium rounded-[4px] transition-all flex items-center justify-center gap-1.5"
              >
                <i data-lucide="mail" class="w-3.5 h-3.5 text-stone-500"></i>
                <span>Email Dev</span>
              </button>

              <a 
                href="../widget-test.html" 
                target="_blank" 
                class="w-full py-2 px-3 bg-amber-50 hover:bg-amber-100 text-amber-900 border border-amber-300 text-xs font-medium rounded-[4px] transition-all flex items-center justify-center gap-1.5 text-center"
              >
                <i data-lucide="external-link" class="w-3.5 h-3.5 text-amber-700"></i>
                <span>Test Live Page</span>
              </a>
            </div>
          </div>

          <!-- Animated Installation Console & Verification Box -->
          <div class="p-4 bg-stone-50 border border-stone-200 rounded-[4px] space-y-3">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2.5">
              <div class="text-xs flex items-center gap-2">
                <span id="status-dot" class="w-2 h-2 rounded-full bg-stone-400"></span>
                <span class="font-medium text-stone-800">Connection Status:</span>
                <span id="conn-status" class="text-stone-500 font-medium">Ready to verify</span>
              </div>

              <button 
                type="button" 
                onclick="runVerificationProcess()" 
                id="verify-btn" 
                class="px-3.5 py-1.5 bg-[#111111] hover:bg-black text-white text-xs font-medium rounded-[4px] transition-all flex items-center justify-center gap-1.5 shrink-0"
              >
                <i data-lucide="refresh-cw" class="w-3.5 h-3.5" id="verify-icon"></i>
                <span id="verify-label">Verify Installation</span>
              </button>
            </div>

            <!-- Animated Step-by-Step Diagnostic Progress Console -->
            <div id="diagnostic-console" class="hidden space-y-2 text-[11px] pt-2 border-t border-stone-200 font-mono">
              <div class="flex items-center justify-between text-stone-500 mb-1">
                <span id="diagnostic-headline">Running diagnostic handshake...</span>
                <span id="diagnostic-percent" class="font-semibold text-stone-800">0%</span>
              </div>

              <!-- Animated Micro-Bar -->
              <div class="w-full bg-stone-200 h-1.5 rounded-full overflow-hidden">
                <div id="diagnostic-bar" class="bg-stone-900 h-full w-0 transition-all duration-300 ease-out"></div>
              </div>

              <!-- Diagnostic Logs -->
              <div id="diagnostic-log" class="space-y-1 text-stone-600 pt-1 text-[10.5px]">
                <!-- Dynamic diagnostic rows inserted by JS -->
              </div>
            </div>

            <div class="text-[11px] text-stone-400 flex items-center justify-between">
              <span>Checking endpoint for active script handshake.</span>
              <button type="button" onclick="runSimulatedInstall()" class="text-stone-600 hover:text-stone-900 underline text-[10.5px]">
                Simulate Instant Install &rarr;
              </button>
            </div>
          </div>
        </div>

        <!-- Navigation Actions: Back, Non-blocking Skip, Continue -->
        <div class="pt-4 border-t border-[#f0eee8] flex items-center justify-between mt-4">
          <a href="test.php" class="text-xs text-stone-500 hover:text-stone-900 flex items-center gap-1">
            <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
            <span>Back</span>
          </a>

          <div class="flex items-center gap-3">
            <!-- Non-blocking skip as requested in Section 16 -->
            <a href="done.php?skipped=1" class="text-xs text-stone-500 hover:text-stone-900 underline underline-offset-2">
              Skip for now
            </a>

            <a 
              id="continue-link"
              href="done.php?installed=1" 
              class="btn-primary py-2 px-5 bg-[#111111] hover:bg-black text-white text-xs font-medium rounded-[4px] transition-colors flex items-center gap-1.5"
            >
              <span>Continue</span>
              <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
            </a>
          </div>
        </div>
      </div>

      <!-- Right Column: Live Website Mockup with Injection Animation (5 cols on desktop) -->
      <div id="mockup-container" class="lg:col-span-5 bg-stone-100 border border-[#e7e5de] rounded-[6px] p-3 sm:p-4 flex flex-col justify-between shadow-xs relative min-h-[440px] overflow-hidden">
        
        <!-- Scanner Laser Line for Animation -->
        <div class="scanner-line"></div>

        <div>
          <!-- Browser Mockup Chrome Header -->
          <div class="bg-white rounded-[4px] border border-stone-200 p-2 flex items-center gap-2 mb-3">
            <div class="flex items-center gap-1">
              <div class="w-2.5 h-2.5 rounded-full bg-stone-300"></div>
              <div class="w-2.5 h-2.5 rounded-full bg-stone-300"></div>
              <div class="w-2.5 h-2.5 rounded-full bg-stone-300"></div>
            </div>
            <div class="bg-stone-50 border border-stone-200 text-stone-500 text-[10px] px-2 py-0.5 rounded flex-1 truncate font-mono flex items-center justify-between">
              <span>https://<?= htmlspecialchars($company['slug'] ?? 'cuboidsoft') ?>.in</span>
              <span id="mockup-ssl-badge" class="text-[9px] text-stone-400">🔒 SSL</span>
            </div>
          </div>

          <!-- Notification Toast inside Mockup (Triggered during installation) -->
          <div id="injection-toast" class="hidden mb-2.5 p-2 bg-emerald-900 text-white rounded-[4px] text-[10.5px] flex items-center justify-between shadow-sm animate-fadeIn">
            <div class="flex items-center gap-1.5">
              <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-ping"></span>
              <span>CuboidPilot script tag detected in &lt;body&gt;</span>
            </div>
            <span class="font-mono text-[9px] text-emerald-300">200 OK</span>
          </div>

          <!-- Mock Website Body -->
          <div class="bg-white border border-stone-200 rounded-[4px] p-4 sm:p-5 relative min-h-[300px] flex flex-col justify-between">
            <div>
              <div class="flex items-center justify-between mb-1">
                <span class="text-sm font-semibold text-stone-900">
                  <?= htmlspecialchars($company['name'] ?? 'Your Company') ?>
                </span>
                <span id="site-active-status" class="text-[10px] text-stone-400 flex items-center gap-1">
                  <span class="w-1.5 h-1.5 rounded-full bg-stone-300"></span> Unverified
                </span>
              </div>
              <div class="text-[11.5px] text-stone-500 leading-relaxed mb-4">
                <?= htmlspecialchars(!empty($company['description']) ? $company['description'] : 'Official digital presence and conversational intelligence powered by CuboidPilot.') ?>
              </div>

              <div class="space-y-2 opacity-50">
                <div class="w-32 h-2.5 bg-stone-100 rounded"></div>
                <div class="w-48 h-2 bg-stone-100 rounded"></div>
                <div class="w-40 h-2 bg-stone-100 rounded"></div>
              </div>
            </div>

            <!-- Waiting Placeholder State (Before Install) -->
            <div id="widget-placeholder" class="p-3 border border-dashed border-stone-300 rounded-[4px] text-center bg-stone-50/70 text-stone-400 text-xs">
              <i data-lucide="crosshair" class="w-4 h-4 mx-auto mb-1 text-stone-400"></i>
              <span>Widget mounting target in &lt;body&gt;</span>
            </div>

            <!-- Authentic Cai Widget Launcher Simulation (Appears with smooth animation once verified) -->
            <div id="injected-widget" class="hidden absolute bottom-3 sm:bottom-4 right-3 sm:right-4 flex flex-col items-end gap-2.5 animate-widget-pop z-20">
              <!-- Authentic Teaser Bubble (Matching production widget media_1790706499005.png) -->
              <div onclick="testMockWidgetClick()" class="cursor-pointer bg-[#14151c] border border-[#282a38] text-white p-2.5 sm:p-3 rounded-[14px] shadow-2xl max-w-[250px] space-y-1.5 hover:border-[#3b3e52] transition-colors select-none text-left">
                <div class="flex items-center justify-between gap-2">
                  <div class="flex items-center gap-2">
                    <div class="w-5 h-5 rounded-full bg-stone-900 border border-stone-700 text-white flex items-center justify-center text-[9px] font-bold shrink-0">
                      <img src="../assets/logo-white.png" alt="Logo" class="w-3 h-3 object-contain">
                    </div>
                    <span class="text-xs font-semibold text-white truncate"><?= htmlspecialchars($widget['brand_name'] ?? 'Cai') ?></span>
                  </div>
                  <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                </div>
                <div class="text-[11px] text-stone-300 leading-snug line-clamp-3">
                  <?= htmlspecialchars($widget['greeting_heading'] ?? ('Hi there 👋 Welcome to ' . ($company['name'] ?? 'our business') . '! How can I help?')) ?>
                </div>
                <div class="text-[9.5px] text-stone-500 font-mono flex items-center gap-1">
                  <span>⚡ Powered by Cai</span>
                  <span>•</span>
                  <span>Replies instantly</span>
                </div>
              </div>

              <!-- Authentic Circular 4-Square Launcher -->
              <button type="button" onclick="testMockWidgetClick()" class="w-11 h-11 rounded-full bg-[#14151a] hover:bg-[#1a1c24] border border-[#282932] text-white flex items-center justify-center shadow-xl transition-all hover:scale-105 active:scale-95 relative group" title="Open AI Assistant">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-stone-200">
                  <rect x="3" y="3" width="7" height="7" rx="1.5"></rect>
                  <rect x="14" y="3" width="7" height="7" rx="1.5"></rect>
                  <rect x="14" y="14" width="7" height="7" rx="1.5"></rect>
                  <rect x="3" y="14" width="7" height="7" rx="1.5"></rect>
                </svg>
                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 absolute top-0.5 right-0.5 border-2 border-[#14151a] pulse-indicator"></span>
              </button>
            </div>

          </div>
        </div>

        <!-- Mockup Bottom Status Bar -->
        <div class="text-[10.5px] text-stone-400 text-center mt-2 flex items-center justify-center gap-2">
          <span id="mockup-indicator-text">Simulation preview: Click "Verify Installation" to run scanner.</span>
        </div>
      </div>

    </div>
  </main>

  <!-- Email to Developer Modal (Clean, responsive dialog) -->
  <div id="dev-email-modal" class="fixed inset-0 bg-stone-900/40 backdrop-blur-xs hidden z-50 flex items-center justify-center p-4">
    <div class="bg-white border border-[#e7e5de] rounded-[6px] max-w-md w-full p-6 shadow-xl space-y-4">
      <div class="flex items-center justify-between pb-3 border-b border-[#e7e5de]">
        <div class="flex items-center gap-2">
          <div class="w-6 h-6 rounded-[3px] bg-stone-900 text-white flex items-center justify-center text-xs">
            <i data-lucide="mail" class="w-3.5 h-3.5"></i>
          </div>
          <h3 class="text-sm font-semibold text-stone-900">Email Instructions to Developer</h3>
        </div>
        <button type="button" onclick="closeDevModal()" class="text-stone-400 hover:text-stone-700">
          <i data-lucide="x" class="w-4 h-4"></i>
        </button>
      </div>

      <div class="space-y-3 text-xs">
        <div>
          <label class="block font-medium text-stone-700 mb-1">Developer's Work Email</label>
          <input type="email" id="dev-email-input" value="developer@<?= htmlspecialchars($company['slug'] ?? 'cuboidsoft') ?>.in" class="w-full px-3 py-2 border border-stone-300 rounded-[4px] text-xs">
        </div>

        <div>
          <label class="block font-medium text-stone-700 mb-1">Subject</label>
          <input type="text" readonly value="Installation code for CuboidPilot AI widget (Company Key: <?= htmlspecialchars($companyKey) ?>)" class="w-full px-3 py-2 bg-stone-50 border border-stone-200 rounded-[4px] text-xs text-stone-600">
        </div>

        <div>
          <label class="block font-medium text-stone-700 mb-1">Installation Instructions</label>
          <div class="p-2.5 bg-stone-50 border border-stone-200 rounded-[4px] text-[11px] text-stone-600 font-mono leading-relaxed">
            Please paste this single-line tag before the &lt;/body&gt; closing tag:<br>
            &lt;script src="https://cai.cuboidsoft.in/widget.js" data-company="<?= htmlspecialchars($companyKey) ?>" async&gt;&lt;/script&gt;
          </div>
        </div>
      </div>

      <div class="pt-2 flex items-center justify-end gap-2 border-t border-[#f0eee8]">
        <button type="button" onclick="closeDevModal()" class="px-3 py-1.5 text-xs text-stone-600 hover:text-stone-900">Cancel</button>
        <button type="button" onclick="dispatchDevEmail()" class="px-4 py-1.5 bg-[#111111] hover:bg-black text-white text-xs font-medium rounded-[4px]">
          Send Instructions &rarr;
        </button>
      </div>
    </div>
  </div>

  <footer class="py-3 text-center text-xs text-stone-400 border-t border-[#f0eee8] bg-white">
    © 2026 CuboidPilot Technologies Inc. • Step 4: Installation
  </footer>

  <script>
    if (window.lucide) lucide.createIcons();

    function copyCode() {
      const code = `<script src="https://cai.cuboidsoft.in/widget.js" data-company="<?= htmlspecialchars($companyKey) ?>" async><\/script>`;
      navigator.clipboard.writeText(code).then(() => {
        document.getElementById('copy-label').innerText = 'Copied to Clipboard!';
        document.getElementById('copy-btn').classList.add('bg-emerald-50', 'text-emerald-800', 'border-emerald-300');
        setTimeout(() => {
          document.getElementById('copy-label').innerText = 'Copy Snippet';
          document.getElementById('copy-btn').classList.remove('bg-emerald-50', 'text-emerald-800', 'border-emerald-300');
        }, 2200);
      });
    }

    let isVerifying = false;

    function runVerificationProcess() {
      if (isVerifying) return;
      isVerifying = true;

      const btn = document.getElementById('verify-btn');
      const label = document.getElementById('verify-label');
      const icon = document.getElementById('verify-icon');
      const statusText = document.getElementById('conn-status');
      const statusDot = document.getElementById('status-dot');
      const consoleBox = document.getElementById('diagnostic-console');
      const logBox = document.getElementById('diagnostic-log');
      const progressBar = document.getElementById('diagnostic-bar');
      const progressPercent = document.getElementById('diagnostic-percent');
      const headline = document.getElementById('diagnostic-headline');
      const mockup = document.getElementById('mockup-container');
      const placeholder = document.getElementById('widget-placeholder');
      const widget = document.getElementById('injected-widget');
      const toast = document.getElementById('injection-toast');
      const siteStatus = document.getElementById('site-active-status');
      const indicatorText = document.getElementById('mockup-indicator-text');

      // Reset state and open console
      consoleBox.classList.remove('hidden');
      logBox.innerHTML = '';
      btn.disabled = true;
      btn.classList.add('opacity-80');
      label.innerText = 'Scanning...';
      if (icon) icon.classList.add('animate-spin');

      statusText.innerText = 'Checking website...';
      statusText.className = 'text-amber-700 font-medium';
      statusDot.className = 'w-2 h-2 rounded-full bg-amber-500 animate-pulse';

      // Start mockup scanner animation
      mockup.classList.add('scanning');
      indicatorText.innerText = 'Scanner active: sweeping DOM tree for script tag...';

      // Step 1: Connecting (200ms)
      setTimeout(() => {
        progressBar.style.width = '25%';
        progressPercent.innerText = '25%';
        headline.innerText = 'Connecting to https://<?= htmlspecialchars($company['slug'] ?? 'cuboidsoft') ?>.in...';
        logBox.innerHTML += `<div class="flex items-center gap-1.5 text-stone-600"><span>➔</span> HTTP GET request dispatched [TLS 1.3]</div>`;
      }, 300);

      // Step 2: DOM Scan (900ms)
      setTimeout(() => {
        progressBar.style.width = '55%';
        progressPercent.innerText = '55%';
        headline.innerText = 'Parsing document & locating <script> tags...';
        logBox.innerHTML += `<div class="flex items-center gap-1.5 text-stone-700"><span>➔</span> Found tag: <code>https://cai.cuboidsoft.in/widget.js</code></div>`;
      }, 1000);

      // Step 3: Key Handshake & Script Injection (1800ms)
      setTimeout(() => {
        progressBar.style.width = '80%';
        progressPercent.innerText = '80%';
        headline.innerText = 'Validating tenant authorization key...';
        logBox.innerHTML += `<div class="flex items-center gap-1.5 text-stone-700"><span>➔</span> Tenant Key confirmed: <code><?= htmlspecialchars($companyKey) ?></code></div>`;
        
        // Show injection toast in mockup
        toast.classList.remove('hidden');
      }, 1800);

      // Step 4: Verification Complete (2500ms)
      setTimeout(() => {
        progressBar.style.width = '100%';
        progressPercent.innerText = '100%';
        headline.innerText = 'Verification successful!';
        logBox.innerHTML += `<div class="flex items-center gap-1.5 text-emerald-800 font-semibold"><span>✓</span> Live WebSocket established • Latency 22ms</div>`;

        // Update button to verified state
        btn.disabled = false;
        btn.classList.remove('opacity-80', 'bg-[#111111]', 'hover:bg-black');
        btn.classList.add('bg-emerald-700', 'hover:bg-emerald-800');
        label.innerText = 'Verified Live ✓';
        if (icon) {
          icon.classList.remove('animate-spin');
          icon.setAttribute('data-lucide', 'check');
        }

        // Update status text
        statusText.innerHTML = '<span class="text-emerald-700 font-semibold">● Connected & Live</span>';
        statusDot.className = 'w-2 h-2 rounded-full bg-emerald-500 pulse-indicator';

        // Update website mockup to show mounted widget
        mockup.classList.remove('scanning');
        placeholder.classList.add('hidden');
        widget.classList.remove('hidden');
        siteStatus.innerHTML = `<span class="w-1.5 h-1.5 rounded-full bg-emerald-500 pulse-indicator"></span> <span class="text-emerald-700 font-semibold">Live</span>`;
        indicatorText.innerHTML = `<span class="text-emerald-700 font-medium">✓ Widget installed & active! Test conversation preview below.</span>`;

        isVerifying = false;
        if (window.lucide) lucide.createIcons();
      }, 2600);
    }

    function runSimulatedInstall() {
      runVerificationProcess();
    }

    function testMockWidgetClick() {
      const w = window.CuboidPilotWidget || window.CuboidPilot;
      if (w && typeof w.open === 'function') {
        w.open();
      }
    }

    // Modal controls for developer instructions
    function openDevModal() {
      document.getElementById('dev-email-modal').classList.remove('hidden');
    }
    function closeDevModal() {
      document.getElementById('dev-email-modal').classList.add('hidden');
    }
    function dispatchDevEmail() {
      const email = document.getElementById('dev-email-input').value;
      if (!email) {
        alert('Please enter developer email.');
        return;
      }
      closeDevModal();
      alert(`Widget installation snippet and credentials sent to ${email}!`);
    }
  </script>

  <!-- Live Assistant Real Widget -->
  <script 
    src="../widget.js" 
    data-company="<?= htmlspecialchars($companyKey) ?>" 
    data-theme="dark" 
    async
  ></script>
</body>
</html>
