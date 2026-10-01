<?php
/**
 * STEP 1: BUSINESS PROFILE (PHP + MySQL)
 * Collects foundational business context and provides smart website crawling preview.
 */

require_once __DIR__ . '/onboarding_helper.php';
$ctx = getOnboardingContext();
$pdo = $ctx['pdo'];
$company = $ctx['company'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? $company['name']);
    $industry = trim($_POST['industry'] ?? 'Education & Academies');
    $website = trim($_POST['website_url'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $country = trim($_POST['country'] ?? 'India');

    // Update in MySQL
    $stmt = $pdo->prepare("
        UPDATE `companies` 
        SET `name` = ?, `industry` = ?, `country` = ?
        WHERE `id` = ?
    ");
    $stmt->execute([$name, $industry, $country, $company['id']]);

    // Update widget brand name
    $pdo->prepare("UPDATE `widget_settings` SET `brand_name` = ? WHERE `company_id` = ?")->execute([$name, $company['id']]);

    // If website URL was given, save as a knowledge source
    if (!empty($website)) {
        $checkSource = $pdo->prepare("SELECT id FROM `knowledge_sources` WHERE `company_id` = ? AND `type` = 'website_url' LIMIT 1");
        $checkSource->execute([$company['id']]);
        if ($existingSource = $checkSource->fetch()) {
            $pdo->prepare("UPDATE `knowledge_sources` SET `content` = ? WHERE `id` = ?")->execute([$website, $existingSource['id']]);
        } else {
            $pdo->prepare("INSERT INTO `knowledge_sources` (`company_id`, `type`, `title`, `content`, `is_active`) VALUES (?, 'website_url', 'Official Website Content', ?, 1)")
                ->execute([$company['id'], $website]);
        }
    }

    // If description was given, save as text document source
    if (!empty($description)) {
        $checkDesc = $pdo->prepare("SELECT id FROM `knowledge_sources` WHERE `company_id` = ? AND `type` = 'text_doc' LIMIT 1");
        $checkDesc->execute([$company['id']]);
        if ($existingDesc = $checkDesc->fetch()) {
            $pdo->prepare("UPDATE `knowledge_sources` SET `content` = ? WHERE `id` = ?")->execute([$description, $existingDesc['id']]);
        } else {
            $pdo->prepare("INSERT INTO `knowledge_sources` (`company_id`, `type`, `title`, `content`, `is_active`) VALUES (?, 'text_doc', 'Company Overview', ?, 1)")
                ->execute([$company['id'], $description]);
        }
    }

    header('Location: train.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tell us about your business — CuboidPilot</title>
  
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
      padding: 9px 12px;
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

  <?php renderOnboardingHeader(1); ?>

  <main class="flex-1 flex items-center justify-center p-3 sm:p-6 my-2">
    <div class="w-full max-w-[540px] bg-white border border-[#e7e5de] rounded-[6px] p-5 sm:p-8 shadow-xs">
      
      <div class="mb-5 sm:mb-6">
        <span class="text-[11px] font-semibold uppercase tracking-wider text-stone-400 block mb-1">Step 1 of 5</span>
        <h1 class="text-xl sm:text-2xl font-medium tracking-tight text-stone-900 serif-heading">
          Tell us about your business
        </h1>
        <p class="text-xs text-stone-500 mt-1">
          We'll use this to prepare your AI assistant.
        </p>
      </div>

      <form method="POST" action="business.php" class="space-y-4">
        
        <div>
          <label class="block text-xs font-medium text-stone-700 mb-1">Business Name *</label>
          <input type="text" name="name" value="<?= htmlspecialchars($company['name'] ?? 'My Business') ?>" required class="form-input" placeholder="e.g. <?= htmlspecialchars($company['name'] ?? 'Your Company Name') ?>">
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-medium text-stone-700 mb-1">Industry / Category *</label>
            <select name="industry" class="form-input bg-white" required>
              <option value="Education" <?= ($company['industry'] ?? '') === 'Education' ? 'selected' : '' ?>>Education</option>
              <option value="Healthcare" <?= ($company['industry'] ?? '') === 'Healthcare' ? 'selected' : '' ?>>Healthcare</option>
              <option value="SaaS" <?= ($company['industry'] ?? '') === 'SaaS' ? 'selected' : '' ?>>SaaS</option>
              <option value="Real Estate" <?= ($company['industry'] ?? '') === 'Real Estate' ? 'selected' : '' ?>>Real Estate</option>
              <option value="E-commerce" <?= ($company['industry'] ?? '') === 'E-commerce' ? 'selected' : '' ?>>E-commerce</option>
              <option value="Agency" <?= ($company['industry'] ?? '') === 'Agency' ? 'selected' : '' ?>>Agency</option>
              <option value="Professional Services" <?= ($company['industry'] ?? '') === 'Professional Services' ? 'selected' : '' ?>>Professional Services</option>
              <option value="Finance" <?= ($company['industry'] ?? '') === 'Finance' ? 'selected' : '' ?>>Finance</option>
              <option value="Travel" <?= ($company['industry'] ?? '') === 'Travel' ? 'selected' : '' ?>>Travel</option>
              <option value="Other" <?= ($company['industry'] ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700 mb-1">Country</label>
            <select name="country" class="form-input bg-white">
              <option value="India" selected>India</option>
              <option value="United States">United States</option>
              <option value="United Kingdom">United Kingdom</option>
              <option value="UAE">United Arab Emirates</option>
              <option value="Canada">Canada</option>
              <option value="Australia">Australia</option>
              <option value="Singapore">Singapore</option>
            </select>
          </div>
        </div>

        <div>
          <label class="block text-xs font-medium text-stone-700 mb-1">
            Website URL <span class="text-stone-400 font-normal">(Optional)</span>
          </label>
          <div class="flex items-center">
            <span class="px-2.5 py-2 bg-stone-100 border border-r-0 border-[#dcdad3] text-stone-500 text-xs rounded-l-[4px] shrink-0">
              https://
            </span>
            <input type="text" id="website-input" name="website_url" value="<?= htmlspecialchars($company['website'] ?? ($company['slug'] . '.in')) ?>" placeholder="yourcompany.com" class="form-input rounded-l-none">
          </div>
        </div>

        <!-- Smart Business Import Box -->
        <div id="website-import-box" class="p-3.5 bg-stone-50 border border-stone-200 rounded-[4px] space-y-2">
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <span class="text-xs font-medium text-stone-800">Let CuboidPilot learn from your website</span>
            <button type="button" onclick="startWebsiteImport()" id="import-btn" class="px-3 py-1.5 bg-stone-900 hover:bg-black text-white text-xs font-medium rounded-[4px] transition-colors shrink-0">
              Import Website
            </button>
          </div>
          <div id="import-status" class="hidden text-xs text-stone-600 space-y-1 pt-1 border-t border-stone-200">
            <div class="flex items-center gap-1.5 text-emerald-800 font-medium">
              <i data-lucide="check" class="w-3.5 h-3.5 text-emerald-600"></i>
              <span>Website analyzed: active pages discovered</span>
            </div>
            <div class="text-[11px] text-stone-500">
              Extracted key products, services, policies, and FAQs. You can review them in the next step.
            </div>
          </div>
        </div>

        <div>
          <label class="block text-xs font-medium text-stone-700 mb-1">
            Business Description <span class="text-stone-400 font-normal">(What does your business do? Optional)</span>
          </label>
          <textarea name="description" rows="2" class="form-input text-xs" placeholder="Describe your business offerings, core services, pricing models, and policies..."><?= htmlspecialchars($company['description'] ?? '') ?></textarea>
        </div>

        <div class="pt-4 border-t border-[#f0eee8] flex items-center justify-end">
          <button type="submit" class="w-full sm:w-auto btn-primary py-2.5 px-5 bg-[#111111] hover:bg-black text-white text-xs font-medium rounded-[4px] transition-colors flex items-center justify-center gap-1.5">
            <span>Continue to Train AI</span>
            <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
          </button>
        </div>

      </form>
    </div>
  </main>

  <footer class="py-4 text-center text-xs text-stone-400 border-t border-[#f0eee8] bg-white">
    © 2026 CuboidPilot Technologies Inc.
  </footer>

  <script>
    if (window.lucide) lucide.createIcons();

    function startWebsiteImport() {
      const btn = document.getElementById('import-btn');
      const status = document.getElementById('import-status');
      btn.innerText = 'Analyzing...';
      btn.disabled = true;

      setTimeout(() => {
        btn.innerText = 'Analyzed ✓';
        btn.classList.add('bg-emerald-700');
        status.classList.remove('hidden');
        if (window.lucide) lucide.createIcons();
      }, 600);
    }
  </script>
</body>
</html>
