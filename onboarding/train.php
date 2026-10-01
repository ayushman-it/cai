<?php
/**
 * STEP 2: TRAIN AI (PHP + MySQL)
 * Gives AI verified knowledge via Website, Documents, Text, and FAQs.
 * Completely avoids RAG/vector jargon.
 */

require_once __DIR__ . '/onboarding_helper.php';
$ctx = getOnboardingContext();
$pdo = $ctx['pdo'];
$company = $ctx['company'];

// Handle quick add of knowledge item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $type = $_POST['type'] ?? 'faq';
    $title = trim($_POST['title'] ?? 'Custom Source');
    $content = trim($_POST['content'] ?? '');

    if (!empty($content)) {
        $ins = $pdo->prepare("INSERT INTO `knowledge_sources` (`company_id`, `type`, `title`, `content`, `is_active`) VALUES (?, ?, ?, ?, 1)");
        $ins->execute([$company['id'], $type, $title, $content]);
    }
    header('Location: train.php');
    exit;
}

// Fetch sources for this company
$sourcesStmt = $pdo->prepare("SELECT * FROM `knowledge_sources` WHERE `company_id` = ? ORDER BY id DESC");
$sourcesStmt->execute([$company['id']]);
$sources = $sourcesStmt->fetchAll();

// Seed initial essential sources if empty
if (empty($sources)) {
    $compName = $company['name'] ?? 'Company';
    $compSlug = $company['slug'] ?? 'workspace';
    $websiteVal = !empty($company['website']) ? $company['website'] : ($compSlug . '.in');
    
    $pdo->prepare("INSERT INTO `knowledge_sources` (`company_id`, `type`, `title`, `content`, `is_active`) VALUES (?, 'website_url', ?, ?, 1)")
        ->execute([$company['id'], $websiteVal, "https://{$websiteVal} (Scanned pages)"]);
    $pdo->prepare("INSERT INTO `knowledge_sources` (`company_id`, `type`, `title`, `content`, `is_active`) VALUES (?, 'faq', 'Frequently Asked Questions', 'General pricing, product offerings, and customer service guidelines', 1)")
        ->execute([$company['id']]);
    $pdo->prepare("INSERT INTO `knowledge_sources` (`company_id`, `type`, `title`, `content`, `is_active`) VALUES (?, 'text_doc', 'Company Overview & Policies', 'Official service catalog, terms, and guidelines', 1)")
        ->execute([$company['id']]);
    
    $sourcesStmt->execute([$company['id']]);
    $sources = $sourcesStmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Give your AI the right knowledge — CuboidPilot</title>
  
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
  </style>
</head>
<body class="min-h-screen flex flex-col justify-between selection:bg-stone-200">

  <?php renderOnboardingHeader(2); ?>

  <main class="flex-1 flex items-center justify-center p-3 sm:p-6 my-2">
    <div class="w-full max-w-[680px] bg-white border border-[#e7e5de] rounded-[6px] p-5 sm:p-8 shadow-xs space-y-6">
      
      <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
        <div>
          <span class="text-[11px] font-semibold uppercase tracking-wider text-stone-400 block mb-1">Step 2 of 5</span>
          <h1 class="text-xl sm:text-2xl font-medium tracking-tight text-stone-900 serif-heading">
            Give your AI the right knowledge
          </h1>
          <p class="text-xs text-stone-500 mt-1">
            CuboidPilot only answers using verified information you provide.
          </p>
        </div>

        <button type="button" onclick="openAddSourceModal()" class="btn-secondary text-xs px-3 py-1.5 border border-stone-300 hover:bg-stone-50 rounded-[4px] font-medium flex items-center justify-center gap-1.5 shrink-0 w-full sm:w-auto">
          <i data-lucide="plus" class="w-3.5 h-3.5"></i>
          <span>Add Knowledge</span>
        </button>
      </div>

      <!-- 4 Simple Ingestion Methods -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 sm:gap-3">
        <button type="button" onclick="setSourceType('website_url', 'Website URL', 'e.g. https://yoursite.com')" class="p-3 border border-[#e7e5de] hover:border-stone-800 rounded-[4px] text-left transition-colors bg-white">
          <i data-lucide="globe" class="w-4 h-4 text-stone-700 mb-1.5"></i>
          <div class="font-medium text-xs text-stone-900">Website</div>
          <div class="text-[11px] text-stone-400">Import pages</div>
        </button>

        <button type="button" onclick="setSourceType('text_doc', 'Upload Document', 'Paste text from brochure or PDF')" class="p-3 border border-[#e7e5de] hover:border-stone-800 rounded-[4px] text-left transition-colors bg-white">
          <i data-lucide="file-text" class="w-4 h-4 text-stone-700 mb-1.5"></i>
          <div class="font-medium text-xs text-stone-900">Document</div>
          <div class="text-[11px] text-stone-400">PDF / DOC</div>
        </button>

        <button type="button" onclick="setSourceType('text_doc', 'Business Overview', 'Paste business policy details')" class="p-3 border border-[#e7e5de] hover:border-stone-800 rounded-[4px] text-left transition-colors bg-white">
          <i data-lucide="align-left" class="w-4 h-4 text-stone-700 mb-1.5"></i>
          <div class="font-medium text-xs text-stone-900">Text</div>
          <div class="text-[11px] text-stone-400">Paste notes</div>
        </button>

        <button type="button" onclick="setSourceType('faq', 'Add FAQ', 'Q: Question\nA: Verified answer')" class="p-3 border border-[#e7e5de] hover:border-stone-800 rounded-[4px] text-left transition-colors bg-white">
          <i data-lucide="help-circle" class="w-4 h-4 text-stone-700 mb-1.5"></i>
          <div class="font-medium text-xs text-stone-900">FAQ</div>
          <div class="text-[11px] text-stone-400">Add Q&amp;A</div>
        </button>
      </div>

      <!-- Knowledge Sources Clean Status List with Horizontal Overflow -->
      <div class="border border-[#e7e5de] rounded-[4px] overflow-hidden overflow-x-auto">
        <table class="w-full text-left text-xs border-collapse min-w-[440px]">
          <thead>
            <tr class="bg-stone-50 border-b border-[#e7e5de] text-stone-500 font-medium">
              <th class="py-2.5 px-4">SOURCE</th>
              <th class="py-2.5 px-3">TYPE</th>
              <th class="py-2.5 px-3">STATUS</th>
              <th class="py-2.5 px-4 text-right">ACTION</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-[#f0eee8]">
            <?php foreach ($sources as $s): ?>
              <tr>
                <td class="py-2.5 px-4 font-medium text-stone-900">
                  <?= htmlspecialchars($s['title']) ?>
                  <?php if (!empty($s['content']) && strlen($s['content']) < 60): ?>
                    <div class="text-[11px] text-stone-400 font-mono"><?= htmlspecialchars($s['content']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="py-2.5 px-3 text-stone-600 capitalize">
                  <?= htmlspecialchars(str_replace('_', ' ', $s['type'])) ?>
                </td>
                <td class="py-2.5 px-3">
                  <span class="inline-flex items-center gap-1 text-[11px] text-emerald-800 bg-emerald-50 px-2 py-0.5 rounded-[3px] border border-emerald-200">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-600"></span>
                    Ready
                  </span>
                </td>
                <td class="py-2.5 px-4 text-right">
                  <span class="text-stone-400 text-xs">Active</span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Navigation -->
      <div class="pt-4 border-t border-[#f0eee8] flex items-center justify-between">
        <a href="business.php" class="text-xs text-stone-500 hover:text-stone-900 flex items-center gap-1">
          <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
          <span>Back</span>
        </a>
        
        <a href="test.php" class="btn-primary py-2.5 px-5 bg-[#111111] hover:bg-black text-white text-xs font-medium rounded-[4px] transition-colors flex items-center gap-1.5">
          <span>Continue to Test AI</span>
          <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
        </a>
      </div>

    </div>
  </main>

  <!-- Add Source Inline Modal -->
  <div id="source-modal" class="hidden fixed inset-0 bg-stone-900/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white border border-[#e7e5de] rounded-[6px] max-w-md w-full p-6 shadow-md space-y-4">
      <div class="flex items-center justify-between pb-2 border-b border-[#e7e5de]">
        <h3 id="modal-title" class="text-sm font-semibold text-stone-900">Add Knowledge Source</h3>
        <button type="button" onclick="closeAddSourceModal()" class="text-stone-400 hover:text-stone-700">
          <i data-lucide="x" class="w-4 h-4"></i>
        </button>
      </div>

      <form method="POST" action="train.php" class="space-y-3">
        <input type="hidden" name="action" value="add_source">
        <input type="hidden" id="modal-type" name="type" value="faq">

        <div>
          <label class="block text-xs font-medium text-stone-700 mb-1">Source Title</label>
          <input type="text" id="modal-name" name="title" class="form-input" placeholder="e.g. 2026 Fee Structure">
        </div>

        <div>
          <label class="block text-xs font-medium text-stone-700 mb-1">Content / Facts</label>
          <textarea id="modal-content" name="content" rows="4" class="form-input" placeholder="Enter verified business facts..."></textarea>
        </div>

        <div class="flex items-center justify-end gap-2 pt-2 border-t border-[#e7e5de]">
          <button type="button" onclick="closeAddSourceModal()" class="px-3 py-1.5 text-xs text-stone-600 hover:text-stone-900">Cancel</button>
          <button type="submit" class="px-4 py-1.5 bg-stone-900 hover:bg-black text-white text-xs font-medium rounded-[4px]">Save Knowledge</button>
        </div>
      </form>
    </div>
  </div>

  <footer class="py-4 text-center text-xs text-stone-400 border-t border-[#f0eee8] bg-white">
    © 2026 CuboidPilot Technologies Inc.
  </footer>

  <script>
    if (window.lucide) lucide.createIcons();

    function setSourceType(type, title, placeholder) {
      document.getElementById('modal-type').value = type;
      document.getElementById('modal-title').innerText = title;
      document.getElementById('modal-name').value = title;
      document.getElementById('modal-content').placeholder = placeholder;
      openAddSourceModal();
    }

    function openAddSourceModal() {
      document.getElementById('source-modal').classList.remove('hidden');
      if (window.lucide) lucide.createIcons();
    }

    function closeAddSourceModal() {
      document.getElementById('source-modal').classList.add('hidden');
    }
  </script>
</body>
</html>
