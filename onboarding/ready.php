<?php
/**
 * STEP 6: READY & LAUNCH (PHP + MySQL)
 * Finalizes onboarding, marks `companies.onboarding_completed = 1`, and seeds live data into DB.
 */

require_once __DIR__ . '/onboarding_helper.php';
$ctx = getOnboardingContext();
$pdo = $ctx['pdo'];
$company = $ctx['company'];

// 1. Mark onboarding completed in database
$pdo->prepare("UPDATE `companies` SET `onboarding_completed` = 1, `status` = 'trial' WHERE `id` = ?")->execute([$company['id']]);

// 2. Ensure initial demo lead exists for this company
$leadCheck = $pdo->prepare("SELECT id FROM `leads` WHERE `company_id` = ? LIMIT 1");
$leadCheck->execute([$company['id']]);
if (!$leadCheck->fetch()) {
    // Seed initial high-intent lead (Rahul Sharma)
    $insLead = $pdo->prepare("
        INSERT INTO `leads` (`company_id`, `customer_id`, `title`, `opportunity_value`, `intent_level`, `is_radar_active`, `radar_reason`, `source`, `status`)
        VALUES (?, 1, 'Full Stack Pro Program (EMI Inquiry)', 45000, 'urgent', 1, 'Customer requested zero-cost installment and asked to speak to human advisor', 'Ask Anything Widget', 'open')
    ");
    $insLead->execute([$company['id']]);
    $leadId = $pdo->lastInsertId();

    // Seed conversation
    $insConvo = $pdo->prepare("
        INSERT INTO `conversations` (`company_id`, `channel`, `status`, `ownership`, `last_message_preview`)
        VALUES (?, 'widget', 'human_requested', 'human', 'Do you provide a 3-month zero-interest EMI plan?')
    ");
    $insConvo->execute([$company['id']]);
    $convoId = $pdo->lastInsertId();

    // Seed messages
    $insMsg = $pdo->prepare("
        INSERT INTO `messages` (`company_id`, `conversation_id`, `sender_type`, `message_text`, `detected_intent`)
        VALUES (?, ?, ?, ?, ?)
    ");
    $insMsg->execute([$company['id'], $convoId, 'visitor', 'Hi, does your program cover cloud deployment on AWS?', 'curriculum_inquiry']);
    $insMsg->execute([$company['id'], $convoId, 'ai', 'Yes, our syllabus covers modern React, Next.js, Docker, and AWS deployment.', 'ai_answer']);
    $insMsg->execute([$company['id'], $convoId, 'visitor', 'Great. What are your tuition plans and do you have a 3-month zero-interest EMI?', 'pricing_objection']);

    // Seed AI Summary
    $insSummary = $pdo->prepare("
        INSERT INTO `ai_summaries` (`company_id`, `customer_id`, `conversation_id`, `lead_id`, `summary_text`, `recommended_action`)
        VALUES (?, 1, ?, ?, 'Customer evaluated pricing twice and asked for zero-cost installment options. Potential value: ₹7,000 upfront / ₹45,000 total.', 'Human handoff to Senior Closer within 15m')
    ");
    $insSummary->execute([$company['id'], $convoId, $leadId]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Setup Complete — CuboidPilot Onboarding</title>
  
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

  <?php renderOnboardingHeader(6); ?>

  <main class="flex-1 flex items-center justify-center p-6">
    <div class="w-full max-w-[540px] bg-white border border-[#e7e5de] rounded-[6px] p-8 shadow-sm text-center">
      
      <div class="w-12 h-12 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 flex items-center justify-center mx-auto mb-4">
        <i data-lucide="check" class="w-6 h-6 stroke-[2.5]"></i>
      </div>

      <span class="text-[11px] font-semibold uppercase tracking-wider text-emerald-700 block mb-1">Onboarding Completed</span>
      <h1 class="text-2xl font-medium tracking-tight text-stone-900 serif-heading mb-2">
        <?= htmlspecialchars($company['name']) ?> is Ready to Launch
      </h1>
      <p class="text-xs text-stone-500 max-w-sm mx-auto mb-6">
        All configuration parameters, widget grounding documents, and WhatsApp pairing records have been committed to <code class="font-mono text-stone-700 bg-stone-100 px-1 py-0.5 rounded">cuboidpolit_db</code>.
      </p>

      <!-- Checklist of DB entities committed -->
      <div class="bg-stone-50 border border-stone-200 rounded-[4px] p-4 text-left text-xs space-y-2.5 mb-6">
        <div class="flex items-center gap-2 text-stone-800">
          <i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-600 shrink-0"></i>
          <span>Organization record committed to <strong class="font-mono text-stone-900">companies (ID #<?= $company['id'] ?>)</strong></span>
        </div>
        <div class="flex items-center gap-2 text-stone-800">
          <i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-600 shrink-0"></i>
          <span>Widget persona & gates saved to <strong class="font-mono text-stone-900">widget_settings</strong></span>
        </div>
        <div class="flex items-center gap-2 text-stone-800">
          <i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-600 shrink-0"></i>
          <span>Knowledge sources indexed into <strong class="font-mono text-stone-900">knowledge_sources</strong></span>
        </div>
        <div class="flex items-center gap-2 text-stone-800">
          <i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-600 shrink-0"></i>
          <span>WhatsApp Cloud API paired in <strong class="font-mono text-stone-900">whatsapp_accounts</strong></span>
        </div>
        <div class="flex items-center gap-2 text-stone-800">
          <i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-600 shrink-0"></i>
          <span>Initial live lead & conversation seeded into <strong class="font-mono text-stone-900">leads</strong> &amp; <strong class="font-mono text-stone-900">conversations</strong></span>
        </div>
      </div>

      <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
        <a href="../app/overview.html" class="w-full sm:w-auto btn-primary py-2.5 px-6 bg-[#111111] hover:bg-black text-white text-xs font-medium rounded-[4px] transition-colors flex items-center justify-center gap-2">
          <span>Enter Company Workspace</span>
          <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
        </a>
        <a href="../app/connect.html" class="w-full sm:w-auto btn-secondary py-2.5 px-4 text-xs font-medium rounded-[4px] text-stone-700 hover:bg-stone-50 border border-stone-300">
          Get Widget Code Again
        </a>
      </div>

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
