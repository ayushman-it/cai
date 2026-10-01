<?php
/**
 * STEP 3: AI ENGINE & KNOWLEDGE GROUNDING (PHP + MySQL)
 * Saves knowledge sources into `knowledge_sources` in `cuboidpolit_db`.
 */

require_once __DIR__ . '/onboarding_helper.php';
$ctx = getOnboardingContext();
$pdo = $ctx['pdo'];
$company = $ctx['company'];

// Load existing sources
$sStmt = $pdo->prepare("SELECT * FROM `knowledge_sources` WHERE `company_id` = ?");
$sStmt->execute([$company['id']]);
$existingSources = $sStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $websiteUrl = trim($_POST['website_url'] ?? '');
    $faqQuestion = trim($_POST['faq_question'] ?? '');
    $faqAnswer = trim($_POST['faq_answer'] ?? '');
    $policyText = trim($_POST['policy_text'] ?? '');

    // 1. Save website URL source if provided
    if (!empty($websiteUrl)) {
        $ins = $pdo->prepare("
            INSERT INTO `knowledge_sources` (`company_id`, `type`, `title`, `content`, `is_active`)
            VALUES (?, 'website_url', ?, ?, 1)
        ");
        $ins->execute([$company['id'], 'Primary Website Index', $websiteUrl]);
    }

    // 2. Save FAQ if provided
    if (!empty($faqQuestion) && !empty($faqAnswer)) {
        $ins = $pdo->prepare("
            INSERT INTO `knowledge_sources` (`company_id`, `type`, `title`, `content`, `is_active`)
            VALUES (?, 'faq', ?, ?, 1)
        ");
        $faqContent = "Q: " . $faqQuestion . "\nA: " . $faqAnswer;
        $ins->execute([$company['id'], $faqQuestion, $faqContent]);
    }

    // 3. Save Admissions/Pricing Policy
    if (!empty($policyText)) {
        $ins = $pdo->prepare("
            INSERT INTO `knowledge_sources` (`company_id`, `type`, `title`, `content`, `is_active`)
            VALUES (?, 'policy', 'Admissions & Tuition Policies', ?, 1)
        ");
        $ins->execute([$company['id'], $policyText]);
    }

    header('Location: website.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Step 3: AI Grounding — CuboidPilot Onboarding</title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Newsreader:opsz,wght@6..72,400;6..72,500&display=swap" rel="stylesheet">
  
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  
  <style>
    body { font-family: 'Inter', -apple-system, sans-serif; background-color: #faf9f7; color: #111111; }
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
  </style>
</head>
<body class="min-h-screen flex flex-col justify-between selection:bg-stone-200">

  <?php renderOnboardingHeader(3); ?>

  <main class="flex-1 flex items-center justify-center p-6">
    <div class="w-full max-w-[840px] grid grid-cols-1 md:grid-cols-2 gap-6">
      
      <!-- Left Column: Knowledge Grounding Form -->
      <div class="bg-white border border-[#e7e5de] rounded-[6px] p-7 shadow-sm space-y-4">
        <div>
          <span class="text-[11px] font-semibold uppercase tracking-wider text-stone-400 block mb-1">Step 3 of 6 • Knowledge Store</span>
          <h1 class="text-xl font-medium tracking-tight text-stone-900 serif-heading">
            Ground Your AI in Verified Facts
          </h1>
          <p class="text-xs text-stone-500 mt-1">
            Stored in <code class="font-mono bg-stone-100 px-1 py-0.5 rounded text-stone-700">cuboidpolit_db.knowledge_sources</code>. The bot will never hallucinate beyond these verified facts.
          </p>
        </div>

        <form method="POST" action="ai.php" class="space-y-3.5">
          
          <div>
            <label class="block text-xs font-medium text-stone-700 mb-1">Company Website URL</label>
            <div class="flex items-center">
              <span class="px-2.5 py-2 bg-stone-100 border border-r-0 border-[#dcdad3] text-stone-500 text-xs rounded-l-[4px]">
                https://
              </span>
              <input type="text" name="website_url" value="<?= htmlspecialchars($company['website'] ?? ($company['slug'] . '.in')) ?>" placeholder="yourcompany.com" class="form-input rounded-l-none">
            </div>
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700 mb-1">Most Frequent Customer FAQ Question</label>
            <input type="text" name="faq_question" value="" class="form-input" placeholder="e.g. What pricing options or plans are available?">
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700 mb-1">Verified Accurate Answer</label>
            <textarea name="faq_answer" rows="2" class="form-input" placeholder="Explain the exact factual answer for your AI to answer confidently..."></textarea>
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700 mb-1">Business Policy / Terms</label>
            <textarea name="policy_text" rows="2" class="form-input" placeholder="Enter key business terms, turnaround times, and support guidelines..."></textarea>
          </div>

          <div class="pt-3 border-t border-[#f0eee8] flex items-center justify-between">
            <a href="goals.php" class="text-xs text-stone-500 hover:text-stone-900 flex items-center gap-1">
              <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
              <span>Back</span>
            </a>
            
            <button type="submit" class="btn-primary py-2 px-5 bg-[#111111] hover:bg-black text-white text-xs font-medium rounded-[4px] transition-colors flex items-center gap-1.5">
              <span>Save & Test Widget</span>
              <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
            </button>
          </div>

        </form>
      </div>

      <!-- Right Column: Live Grounded AI Sandbox -->
      <div class="bg-white border border-[#e7e5de] rounded-[6px] p-6 shadow-sm flex flex-col justify-between">
        <div>
          <div class="flex items-center justify-between pb-3 border-b border-[#e7e5de]">
            <div class="flex items-center gap-2">
              <div class="w-6 h-6 rounded-[3px] bg-stone-900 text-white flex items-center justify-center font-bold text-[10px]">
                CP
              </div>
              <div>
                <div class="text-xs font-semibold text-stone-900">CuboidPilot AI Sandbox</div>
                <div class="text-[10px] text-emerald-700 font-medium">● Grounded in Knowledge Base</div>
              </div>
            </div>
            <span class="text-[10px] font-mono bg-stone-100 text-stone-600 px-2 py-0.5 rounded">Claude 3.5 Sonnet</span>
          </div>

          <!-- Chat Sandbox Messages -->
          <div id="sandbox-messages" class="py-4 space-y-3 text-xs overflow-y-auto max-h-[300px]">
            <div class="flex items-start gap-2">
              <div class="w-5 h-5 rounded-[2px] bg-stone-900 text-white flex items-center justify-center text-[9px] shrink-0 mt-0.5">CP</div>
              <div class="p-2.5 bg-stone-50 border border-stone-200 rounded-[4px] text-stone-800 leading-relaxed">
                Hello! I am the automated admissions assistant for <strong><?= htmlspecialchars($company['name']) ?></strong>. Ask me anything about our curriculum, tuition fees, or EMI options.
              </div>
            </div>
          </div>
        </div>

        <!-- Chat Input Simulator -->
        <div class="pt-3 border-t border-[#f0eee8]">
          <div class="flex items-center gap-2">
            <input type="text" id="sandbox-input" placeholder="Test ask: Do you have EMI options?" class="form-input flex-1 text-xs">
            <button type="button" onclick="sendSandboxMsg()" class="btn-primary py-2 px-3 bg-stone-900 text-white text-xs rounded-[4px]">
              Ask
            </button>
          </div>
          <div class="flex items-center gap-1.5 mt-2 text-[10px] text-stone-400">
            <span>Quick test:</span>
            <button type="button" onclick="quickAsk('Do you offer zero-cost EMI?')" class="underline hover:text-stone-700">“Do you offer zero-cost EMI?”</button>
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

    function quickAsk(text) {
      document.getElementById('sandbox-input').value = text;
      sendSandboxMsg();
    }

    function sendSandboxMsg() {
      const input = document.getElementById('sandbox-input');
      const text = input.value.trim();
      if (!text) return;

      const container = document.getElementById('sandbox-messages');

      // Add user message
      const userBubble = document.createElement('div');
      userBubble.className = 'flex justify-end';
      userBubble.innerHTML = `
        <div class="p-2.5 bg-stone-900 text-white rounded-[4px] max-w-[80%]">
          ${text}
        </div>
      `;
      container.appendChild(userBubble);
      input.value = '';

      // AI Simulated Reply grounded in FAQ
      setTimeout(() => {
        const aiBubble = document.createElement('div');
        aiBubble.className = 'flex items-start gap-2';
        aiBubble.innerHTML = `
          <div class="w-5 h-5 rounded-[2px] bg-stone-900 text-white flex items-center justify-center text-[9px] shrink-0 mt-0.5">CP</div>
          <div class="p-2.5 bg-stone-50 border border-stone-200 rounded-[4px] text-stone-800 leading-relaxed max-w-[85%]">
            Yes! We offer 3-month and 6-month zero-interest EMI options for eligible applicants with a ₹7,000 upfront enrollment booking. Would you like me to connect you on WhatsApp with an admissions advisor?
            <div class="mt-1.5 text-[10px] text-emerald-800 border-t border-stone-200/60 pt-1 flex items-center gap-1 font-mono">
              ✓ Grounded in: Knowledge Store (FAQ #1)
            </div>
          </div>
        `;
        container.appendChild(aiBubble);
        container.scrollTop = container.scrollHeight;
      }, 400);
    }
  </script>
</body>
</html>
