/**
 * CUBOIDPILOT — HOMEPAGE CLIENT LOGIC
 * Minimal, lightweight, zero-dependency vanilla JS.
 */

document.addEventListener('DOMContentLoaded', () => {
  // Initialize Lucide icons
  if (window.lucide) {
    window.lucide.createIcons();
  }

  // Top Announcement Bar Dismiss
  const closeAnnouncementBtn = document.getElementById('close-announcement');
  const topAnnouncementBar = document.getElementById('top-announcement-bar');
  if (closeAnnouncementBtn && topAnnouncementBar) {
    closeAnnouncementBtn.addEventListener('click', () => {
      topAnnouncementBar.style.display = 'none';
    });
  }

  // Header Sticky Border Transition
  const header = document.querySelector('header');
  window.addEventListener('scroll', () => {
    if (window.scrollY > 20) {
      header?.classList.add('shadow-sm', 'bg-opacity-95');
    } else {
      header?.classList.remove('shadow-sm', 'bg-opacity-95');
    }
  }, { passive: true });

  // Mobile Drawer Toggle
  const mobileMenuBtn = document.getElementById('mobile-menu-btn');
  const closeMobileMenuBtn = document.getElementById('close-mobile-menu');
  const mobileDrawer = document.getElementById('mobile-drawer');

  if (mobileMenuBtn && mobileDrawer) {
    mobileMenuBtn.addEventListener('click', () => {
      mobileDrawer.classList.add('open');
      document.body.style.overflow = 'hidden';
    });
  }

  if (closeMobileMenuBtn && mobileDrawer) {
    closeMobileMenuBtn.addEventListener('click', () => {
      mobileDrawer.classList.remove('open');
      document.body.style.overflow = '';
    });
  }

  if (mobileDrawer) {
    mobileDrawer.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', () => {
        mobileDrawer.classList.remove('open');
        document.body.style.overflow = '';
      });
    });
  }

  // Interactive Hero Chat Widget
  const chatMessages = document.getElementById('hero-chat-messages');
  const chatInput = document.getElementById('hero-chat-input');
  const chatSendBtn = document.getElementById('hero-chat-send');
  const chipHelp = document.getElementById('chip-help-me');
  const chipPerson = document.getElementById('chip-talk-person');

  function appendUserMessage(text) {
    if (!chatMessages) return;
    const msgDiv = document.createElement('div');
    msgDiv.className = 'flex justify-end';
    msgDiv.innerHTML = `
      <div class="cuboid-bubble-user">
        ${escapeHtml(text)}
      </div>
    `;
    chatMessages.appendChild(msgDiv);
    chatMessages.scrollTop = chatMessages.scrollHeight;
  }

  function appendTypingIndicator() {
    if (!chatMessages) return null;
    const typingDiv = document.createElement('div');
    typingDiv.id = 'hero-typing-indicator';
    typingDiv.className = 'flex items-start gap-2.5';
    typingDiv.innerHTML = `
      <div class="w-6 h-6 rounded-full bg-stone-900 text-white flex items-center justify-center text-[10px] font-semibold shrink-0 mt-1">Cai</div>
      <div class="cuboid-bubble-ai flex items-center gap-1.5 py-3">
        <span class="typing-dot"></span>
        <span class="typing-dot"></span>
        <span class="typing-dot"></span>
      </div>
    `;
    chatMessages.appendChild(typingDiv);
    chatMessages.scrollTop = chatMessages.scrollHeight;
    return typingDiv;
  }

  function appendAiResponse(htmlText) {
    const indicator = document.getElementById('hero-typing-indicator');
    if (indicator) indicator.remove();

    if (!chatMessages) return;
    const msgDiv = document.createElement('div');
    msgDiv.className = 'flex items-start gap-2.5';
    msgDiv.innerHTML = `
      <div class="w-6 h-6 rounded-full bg-stone-900 text-white flex items-center justify-center text-[10px] font-semibold shrink-0 mt-1">Cai</div>
      <div class="cuboid-bubble-ai">
        ${htmlText}
      </div>
    `;
    chatMessages.appendChild(msgDiv);
    chatMessages.scrollTop = chatMessages.scrollHeight;
    if (window.lucide) window.lucide.createIcons();
  }

  function escapeHtml(string) {
    const div = document.createElement('div');
    div.innerText = string;
    return div.innerHTML;
  }

  if (chipHelp) {
    chipHelp.addEventListener('click', () => {
      chipHelp.style.display = 'none';
      if (chipPerson) chipPerson.style.display = 'none';
      appendUserMessage('Yes, show features');
      appendTypingIndicator();
      setTimeout(() => {
        appendAiResponse(`
          <p class="mb-2">We offer 3 plans: <strong>Starter</strong> ($49/mo), <strong>Growth</strong> ($149/mo) and <strong>Enterprise</strong> (Custom).</p>
          <p class="text-xs text-stone-500">Cai can send this complete breakdown to your WhatsApp or connect you with a specialist.</p>
          <div class="mt-2.5 flex items-center gap-2">
            <span class="inline-flex items-center gap-1 text-xs font-medium text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">
              <i data-lucide="check-circle" class="w-3 h-3"></i> Cai high-intent recognized
            </span>
          </div>
        `);
      }, 700);
    });
  }

  if (chipPerson) {
    chipPerson.addEventListener('click', () => {
      chipPerson.style.display = 'none';
      if (chipHelp) chipHelp.style.display = 'none';
      appendUserMessage('Talk to a person');
      appendTypingIndicator();
      setTimeout(() => {
        appendAiResponse(`
          <p class="mb-1">Cai is preparing an executive briefing and routing to our specialist.</p>
          <div class="p-2.5 bg-white border border-stone-200 rounded-lg text-xs mt-2 flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-stone-100 border border-stone-200 flex items-center justify-center text-stone-800 font-semibold text-xs">AM</div>
            <div>
              <div class="font-medium text-stone-900">Arjun Mehta is joining...</div>
              <div class="text-stone-500">CX Team • Received Cai context summary</div>
            </div>
          </div>
        `);
      }, 700);
    });
  }

  if (chatSendBtn && chatInput) {
    const handleSend = () => {
      const val = chatInput.value.trim();
      if (!val) return;
      chatInput.value = '';
      appendUserMessage(val);
      appendTypingIndicator();
      setTimeout(() => {
        appendAiResponse(`
          <p>Thanks! <strong>Cai</strong> is grounded in your company knowledge base, answering accurately without hallucinations and synchronizing with your CRM in real time.</p>
        `);
      }, 800);
    };

    chatSendBtn.addEventListener('click', handleSend);
    chatInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        handleSend();
      }
    });
  }

  // Product Journey Step Navigator
  const journeyButtons = document.querySelectorAll('[data-journey-step]');
  const journeyPanels = document.querySelectorAll('[data-journey-panel]');

  journeyButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      const step = btn.getAttribute('data-journey-step');
      journeyButtons.forEach(b => b.classList.remove('active', 'bg-stone-900', 'text-white', 'border-stone-900'));
      journeyButtons.forEach(b => b.classList.add('bg-white', 'text-stone-700', 'border-stone-200'));

      btn.classList.add('active', 'bg-stone-900', 'text-white', 'border-stone-900');
      btn.classList.remove('bg-white', 'text-stone-700', 'border-stone-200');

      journeyPanels.forEach(panel => {
        if (panel.getAttribute('data-journey-panel') === step) {
          panel.classList.remove('hidden');
        } else {
          panel.classList.add('hidden');
        }
      });
      if (window.lucide) window.lucide.createIcons();
    });
  });

  // Action Buttons Feedback (Radar actions: Call, WhatsApp, Assign)
  const actionButtons = document.querySelectorAll('.radar-action-btn');
  actionButtons.forEach(btn => {
    btn.addEventListener('click', (e) => {
      const originalText = btn.innerHTML;
      btn.innerHTML = `<span class="inline-flex items-center gap-1"><i data-lucide="check" class="w-3.5 h-3.5"></i> Done</span>`;
      btn.classList.add('bg-stone-100', 'border-stone-300');
      if (window.lucide) window.lucide.createIcons();
      setTimeout(() => {
        btn.innerHTML = originalText;
        btn.classList.remove('bg-stone-100', 'border-stone-300');
        if (window.lucide) window.lucide.createIcons();
      }, 2000);
    });
  });

  // Hero Interactive Live Prompt Chips (Triggers real Cai in live embed)
  const promptChips = document.querySelectorAll('.hero-prompt-chip');
  promptChips.forEach(chip => {
    chip.addEventListener('click', () => {
      const query = chip.getAttribute('data-query');
      if (!query) return;

      const iframe = document.getElementById('hero-live-widget-iframe');
      if (iframe && iframe.contentWindow) {
        try {
          if (iframe.contentWindow.CuboidPilot && typeof iframe.contentWindow.CuboidPilot.ask === 'function') {
            iframe.contentWindow.CuboidPilot.ask(query);
            return;
          }
        } catch (e) {}
      }

      // Fallback to global widget if present
      if (window.CuboidPilot && typeof window.CuboidPilot.ask === 'function') {
        window.CuboidPilot.ask(query);
      }
    });
  });

  // FAQ Accordion Interactivity (Intercom style)
  const faqToggles = document.querySelectorAll('.faq-toggle');
  faqToggles.forEach(toggle => {
    toggle.addEventListener('click', () => {
      const item = toggle.closest('.faq-item');
      if (!item) return;
      const content = item.querySelector('.faq-content');
      const icon = item.querySelector('.faq-icon');
      const isExpanded = toggle.getAttribute('aria-expanded') === 'true';

      if (isExpanded) {
        toggle.setAttribute('aria-expanded', 'false');
        content?.classList.add('hidden');
        if (icon) icon.style.transform = 'rotate(0deg)';
      } else {
        toggle.setAttribute('aria-expanded', 'true');
        content?.classList.remove('hidden');
        if (icon) icon.style.transform = 'rotate(180deg)';
      }
    });
  });

  // Intercom Pricing Section Annual/Monthly Toggle
  const annualToggleSwitch = document.getElementById('annual-toggle-switch');
  function updateIntercomPrices(isAnnual) {
    document.querySelectorAll('.intercom-price').forEach(el => {
      el.textContent = isAnnual ? el.getAttribute('data-annual') : el.getAttribute('data-monthly');
    });
    document.querySelectorAll('.intercom-billed-note').forEach(el => {
      el.textContent = isAnnual ? 'billed annually' : 'billed monthly';
    });
  }

  if (annualToggleSwitch) {
    annualToggleSwitch.addEventListener('change', () => {
      updateIntercomPrices(annualToggleSwitch.checked);
    });
  }

  // Compare All Plan Features Expandable Matrix Toggle
  const toggleMatrixBtn = document.getElementById('toggle-feature-matrix-btn');
  const matrixTable = document.getElementById('pricing-feature-matrix');
  const matrixChevron = document.getElementById('feature-matrix-chevron');

  if (toggleMatrixBtn && matrixTable) {
    toggleMatrixBtn.addEventListener('click', () => {
      const isHidden = matrixTable.classList.contains('hidden');
      if (isHidden) {
        matrixTable.classList.remove('hidden');
        if (matrixChevron) matrixChevron.style.transform = 'rotate(180deg)';
      } else {
        matrixTable.classList.add('hidden');
        if (matrixChevron) matrixChevron.style.transform = 'rotate(0deg)';
      }
    });
  }

  // Super Admin Live Synchronization for Intercom Tiers
  function fetchLiveSuperAdminPlans() {
    fetch('api/plans.php')
      .then(res => res.json())
      .then(res => {
        if (res.success && Array.isArray(res.plans)) {
          res.plans.forEach(plan => {
            const el = document.querySelector(`.intercom-price[data-plan="${plan.code === 'starter' ? 'essential' : (plan.code === 'growth' ? 'advanced' : 'expert')}"]`);
            if (el) {
              if (plan.annual_monthly_usd) el.setAttribute('data-annual', `$${plan.annual_monthly_usd}`);
              if (plan.monthly_usd) el.setAttribute('data-monthly', `$${plan.monthly_usd}`);
            }
          });
          const isAnnual = annualToggleSwitch ? annualToggleSwitch.checked : true;
          updateIntercomPrices(isAnnual);
        }
      })
      .catch(() => {});
  }

  fetchLiveSuperAdminPlans();
});

