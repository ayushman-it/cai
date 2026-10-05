/**
 * CUBOIDPILOT — DYNAMIC MULTI-CURRENCY & PRICING ENGINE
 * Supports USD ($), EUR (€), GBP (£), INR (₹), CAD (CA$), AUD (AU$)
 * Custom animated dropdown UI, instant currency recalculation, and annual discount sync.
 */

(function () {
  'use strict';

  const CURRENCIES = {
    USD: {
      code: 'USD',
      symbol: '$',
      flag: '🇺🇸',
      label: 'USD ($)',
      name: 'US Dollar',
      plans: {
        essential: { monthly: '$29', annual: '$23', annualNote: 'billed annually' },
        advanced: { monthly: '$85', annual: '$68', annualNote: 'billed annually' },
        expert: { monthly: '$349', annual: '$279', annualNote: 'billed annually' }
      },
      caiOutcome: '$0.99',
      heroSeat: { monthly: '$29', annual: '$23' }
    },
    EUR: {
      code: 'EUR',
      symbol: '€',
      flag: '🇪🇺',
      label: 'EUR (€)',
      name: 'Euro',
      plans: {
        essential: { monthly: '€27', annual: '€21', annualNote: 'billed annually' },
        advanced: { monthly: '€79', annual: '€63', annualNote: 'billed annually' },
        expert: { monthly: '€325', annual: '€259', annualNote: 'billed annually' }
      },
      caiOutcome: '€0.92',
      heroSeat: { monthly: '€27', annual: '€21' }
    },
    GBP: {
      code: 'GBP',
      symbol: '£',
      flag: '🇬🇧',
      label: 'GBP (£)',
      name: 'British Pound',
      plans: {
        essential: { monthly: '£23', annual: '£18', annualNote: 'billed annually' },
        advanced: { monthly: '£67', annual: '£54', annualNote: 'billed annually' },
        expert: { monthly: '£279', annual: '£223', annualNote: 'billed annually' }
      },
      caiOutcome: '£0.79',
      heroSeat: { monthly: '£23', annual: '£18' }
    },
    INR: {
      code: 'INR',
      symbol: '₹',
      flag: '🇮🇳',
      label: 'INR (₹)',
      name: 'Indian Rupee',
      plans: {
        essential: { monthly: '₹99', annual: '₹79', annualNote: 'billed annually' },
        advanced: { monthly: '₹199', annual: '₹159', annualNote: 'billed annually' },
        expert: { monthly: '₹349', annual: '₹279', annualNote: 'billed annually' }
      },
      caiOutcome: '₹1',
      heroSeat: { monthly: '₹99', annual: '₹79' }
    },
    CAD: {
      code: 'CAD',
      symbol: 'CA$',
      flag: '🇨🇦',
      label: 'CAD (CA$)',
      name: 'Canadian Dollar',
      plans: {
        essential: { monthly: 'CA$39', annual: 'CA$31', annualNote: 'billed annually' },
        advanced: { monthly: 'CA$115', annual: 'CA$92', annualNote: 'billed annually' },
        expert: { monthly: 'CA$475', annual: 'CA$379', annualNote: 'billed annually' }
      },
      caiOutcome: 'CA$1.35',
      heroSeat: { monthly: 'CA$39', annual: 'CA$31' }
    },
    AUD: {
      code: 'AUD',
      symbol: 'AU$',
      flag: '🇦🇺',
      label: 'AUD (AU$)',
      name: 'Australian Dollar',
      plans: {
        essential: { monthly: 'AU$45', annual: 'AU$35', annualNote: 'billed annually' },
        advanced: { monthly: 'AU$129', annual: 'AU$103', annualNote: 'billed annually' },
        expert: { monthly: 'AU$539', annual: 'AU$429', annualNote: 'billed annually' }
      },
      caiOutcome: 'AU$1.50',
      heroSeat: { monthly: 'AU$45', annual: 'AU$35' }
    }
  };

  let currentCurrency = localStorage.getItem('cp_pricing_currency') || 'INR';
  if (!CURRENCIES[currentCurrency]) currentCurrency = 'INR';

  function isAnnualBilling() {
    const toggle = document.getElementById('page-annual-toggle') || document.getElementById('annual-toggle-switch');
    return toggle ? toggle.checked : true;
  }

  function updatePrices() {
    const curr = CURRENCIES[currentCurrency] || CURRENCIES.USD;
    const isAnnual = isAnnualBilling();

    // 1. Update Hero Transparent Pricing Card
    document.querySelectorAll('.cp-hero-seat-price').forEach(el => {
      el.textContent = isAnnual ? curr.heroSeat.annual : curr.heroSeat.monthly;
    });

    document.querySelectorAll('.cp-hero-outcome-price, .cp-cai-outcome-rate').forEach(el => {
      el.textContent = curr.caiOutcome;
    });

    // 2. Update Plan Cards (Essential, Advanced, Expert)
    document.querySelectorAll('.intercom-price').forEach(el => {
      const plan = el.getAttribute('data-plan');
      if (curr.plans[plan]) {
        el.textContent = isAnnual ? curr.plans[plan].annual : curr.plans[plan].monthly;
      }
    });

    document.querySelectorAll('.intercom-billed-note').forEach(el => {
      el.textContent = isAnnual ? 'billed annually' : 'billed monthly';
    });

    // 3. Update Dropdown Button UI
    document.querySelectorAll('#cp-currency-flag').forEach(el => {
      el.textContent = curr.flag;
    });
    document.querySelectorAll('#cp-currency-label').forEach(el => {
      el.textContent = curr.label;
    });

    // 4. Update checkmarks inside dropdown menus
    document.querySelectorAll('.cp-currency-item').forEach(item => {
      const code = item.getAttribute('data-currency');
      const check = item.querySelector('.cp-currency-check');
      if (check) {
        check.style.opacity = (code === currentCurrency) ? '1' : '0';
      }
    });
  }

  function renderDropdownMenu(menuEl) {
    if (!menuEl) return;
    menuEl.innerHTML = '';

    Object.keys(CURRENCIES).forEach(code => {
      const c = CURRENCIES[code];
      const isSelected = code === currentCurrency;

      const item = document.createElement('button');
      item.type = 'button';
      item.className = `cp-currency-item w-full flex items-center justify-between px-3 py-2 text-xs text-stone-700 hover:bg-[#faf9f5] transition-colors rounded-[2px] cursor-pointer text-left ${isSelected ? 'font-semibold text-stone-900 bg-[#f4f2ec]' : ''}`;
      item.setAttribute('data-currency', code);

      item.innerHTML = `
        <span class="flex items-center gap-2">
          <span class="text-sm">${c.flag}</span>
          <span class="font-mono">${c.code} (${c.symbol})</span>
          <span class="text-[11px] text-stone-400 font-normal">· ${c.name}</span>
        </span>
        <span class="cp-currency-check text-stone-900 font-bold ml-2" style="opacity: ${isSelected ? '1' : '0'};">✓</span>
      `;

      item.addEventListener('click', (e) => {
        e.stopPropagation();
        currentCurrency = code;
        try {
          localStorage.setItem('cp_pricing_currency', code);
        } catch (e) {}
        updatePrices();
        closeAllDropdowns();
      });

      menuEl.appendChild(item);
    });
  }

  function closeAllDropdowns() {
    document.querySelectorAll('#cp-currency-menu').forEach(menu => {
      menu.classList.add('hidden');
    });
    document.querySelectorAll('#cp-currency-chevron').forEach(chev => {
      chev.style.transform = '';
    });
  }

  function initCurrencyDropdown() {
    const triggerBtns = document.querySelectorAll('#cp-currency-btn');
    const menus = document.querySelectorAll('#cp-currency-menu');

    menus.forEach(menu => renderDropdownMenu(menu));

    triggerBtns.forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const container = btn.closest('#cp-currency-dropdown');
        if (!container) return;
        const menu = container.querySelector('#cp-currency-menu');
        const chevron = container.querySelector('#cp-currency-chevron');

        const isHidden = menu.classList.contains('hidden');
        closeAllDropdowns();

        if (isHidden) {
          menu.classList.remove('hidden');
          if (chevron) chevron.style.transform = 'rotate(180deg)';
        }
      });
    });

    document.addEventListener('click', (e) => {
      if (!e.target.closest('#cp-currency-dropdown')) {
        closeAllDropdowns();
      }
    });

    // Bind to billing toggles
    ['page-annual-toggle', 'annual-toggle-switch'].forEach(id => {
      const toggle = document.getElementById(id);
      if (toggle) {
        toggle.addEventListener('change', () => {
          updatePrices();
        });
      }
    });

    // Initial render
    updatePrices();
    initOutcomeTriggers();
  }

  function ensureOutcomeModal() {
    let modal = document.getElementById('cp-outcome-modal');
    if (modal) return modal;

    modal = document.createElement('div');
    modal.id = 'cp-outcome-modal';
    modal.className = 'fixed inset-0 flex items-center justify-center p-4 transition-opacity duration-200';
    modal.style.cssText = 'position:fixed;inset:0;z-index:9999999;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,0.65);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', 'cp-outcome-modal-title');

    modal.innerHTML = `
      <div class="bg-white border border-[#dcdad0] rounded-[6px] shadow-2xl max-w-md w-full p-6 sm:p-7 relative text-left text-[#111111] animate-in fade-in zoom-in-95 duration-150" style="background:#ffffff;border:1px solid #dcdad0;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
        <button type="button" id="cp-close-outcome-modal" class="absolute top-4 right-4 p-1.5 rounded-[4px] text-stone-400 hover:text-stone-700 hover:bg-stone-100 transition-colors cursor-pointer" aria-label="Close dialog" style="position:absolute;top:16px;right:16px;cursor:pointer;background:transparent;border:none;">
          <svg class="w-4 h-4" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>

        <div class="flex items-center gap-2 mb-1.5" style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
          <span class="w-6 h-6 rounded-full bg-emerald-50 text-emerald-700 flex items-center justify-center shrink-0" style="width:24px;height:24px;border-radius:9999px;background:#ecfdf5;color:#047857;display:flex;align-items:center;justify-content:center;">
            <svg class="w-3.5 h-3.5" width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
          </span>
          <h3 id="cp-outcome-modal-title" class="text-base sm:text-lg font-semibold text-[#111111] tracking-tight" style="margin:0;font-size:16px;font-weight:600;color:#111111;">
            How Cai Outcome Pricing Works
          </h3>
        </div>
        <p class="text-xs text-stone-500 mb-4 leading-relaxed" style="font-size:12px;color:#78716c;margin-bottom:16px;line-height:1.5;">
          Pay only when Cai delivers measurable customer resolution. No charges for simple greetings or human escalations.
        </p>

        <div class="p-3.5 bg-[#fcfbfa] border border-[#e7e5de] rounded-[4px] mb-4" style="background:#fcfbfa;border:1px solid #e7e5de;border-radius:4px;padding:14px;margin-bottom:16px;">
          <div class="text-[11px] font-mono uppercase text-stone-500 mb-0.5" style="font-size:11px;font-family:monospace;color:#78716c;">Current Outcome Rate</div>
          <div class="text-2xl font-bold font-mono text-[#111111] flex items-baseline gap-1.5" style="display:flex;align-items:baseline;gap:6px;font-size:24px;font-weight:700;font-family:monospace;">
            <span class="cp-modal-outcome-rate text-emerald-800" style="color:#065f46;">₹1</span>
            <span class="text-xs font-normal text-stone-500 font-sans" style="font-size:12px;font-weight:400;font-family:sans-serif;color:#78716c;">per successful resolution</span>
          </div>
        </div>

        <div class="space-y-3.5 text-xs text-stone-700" style="font-size:12px;color:#44403c;">
          <div style="margin-bottom:12px;">
            <div class="font-semibold text-stone-900 mb-1.5 flex items-center gap-1.5" style="font-weight:600;color:#1c1917;display:flex;align-items:center;gap:6px;margin-bottom:6px;">
              <span class="w-4 h-4 rounded-full bg-emerald-100 text-emerald-800 flex items-center justify-center text-[10px] font-bold" style="width:16px;height:16px;border-radius:9999px;background:#d1fae5;color:#065f46;display:inline-flex;align-items:center;justify-content:center;font-size:10px;">✓</span>
              <span>What counts as an outcome?</span>
            </div>
            <ul class="space-y-1 text-stone-600 pl-5 list-disc list-outside" style="padding-left:20px;margin:0;color:#57534e;">
              <li style="margin-bottom:4px;">Cai resolves a customer query from verified documentation without human agent intervention</li>
              <li style="margin-bottom:4px;">Cai captures a qualified lead with complete contact details (name, email/phone, intent)</li>
              <li>Cai schedules a confirmed team demo, appointment, or consultation</li>
            </ul>
          </div>

          <div class="pt-3 border-t border-[#f0eee9]" style="border-top:1px solid #f0eee9;padding-top:12px;margin-bottom:12px;">
            <div class="font-semibold text-stone-900 mb-1.5 flex items-center gap-1.5" style="font-weight:600;color:#1c1917;display:flex;align-items:center;gap:6px;margin-bottom:6px;">
              <span class="w-4 h-4 rounded-full bg-stone-100 text-stone-500 flex items-center justify-center text-[10px] font-bold" style="width:16px;height:16px;border-radius:9999px;background:#f5f5f4;color:#78716c;display:inline-flex;align-items:center;justify-content:center;font-size:10px;">✕</span>
              <span>What is always 100% Free (₹0)?</span>
            </div>
            <ul class="space-y-1 text-stone-600 pl-5 list-disc list-outside" style="padding-left:20px;margin:0;color:#57534e;">
              <li style="margin-bottom:4px;">Greetings, pleasantries, and basic navigation queries</li>
              <li style="margin-bottom:4px;">Clarifying questions asked before arriving at a resolution</li>
              <li style="margin-bottom:4px;">Any conversation escalated to or answered by a human specialist</li>
              <li>Spam, test pings, or unresolvable inquiries</li>
            </ul>
          </div>

          <div class="pt-3 border-t border-[#f0eee9] text-[11.5px] text-stone-600 leading-relaxed bg-[#fbfaf6] p-3 rounded-[4px] border border-[#eceae4]" style="background:#fbfaf6;border:1px solid #eceae4;border-radius:4px;padding:12px;font-size:11.5px;color:#57534e;line-height:1.5;">
            <strong class="text-stone-900" style="color:#1c1917;">Budget Guardrails:</strong> Set custom monthly outcome limits directly in your dashboard (e.g. ₹500 or ₹2,000) so your bill never exceeds your planned budget.
          </div>
        </div>

        <div class="mt-5" style="margin-top:20px;">
          <button type="button" id="cp-confirm-outcome-modal" class="w-full py-2.5 px-4 rounded-[3px] bg-[#111111] hover:bg-black text-white text-xs font-semibold text-center transition-colors shadow-2xs cursor-pointer" style="width:100%;padding:10px 16px;border-radius:3px;background:#111111;color:#ffffff;font-size:12px;font-weight:600;border:none;cursor:pointer;">
            Got it, thanks
          </button>
        </div>
      </div>
    `;

    document.body.appendChild(modal);

    const closeBtn = modal.querySelector('#cp-close-outcome-modal');
    const confirmBtn = modal.querySelector('#cp-confirm-outcome-modal');

    const closeModal = () => {
      modal.style.display = 'none';
      document.body.style.overflow = '';
    };

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (confirmBtn) confirmBtn.addEventListener('click', closeModal);

    modal.addEventListener('click', (e) => {
      if (e.target === modal) closeModal();
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && modal.style.display === 'flex') {
        closeModal();
      }
    });

    return modal;
  }

  function openOutcomeModal() {
    const modal = ensureOutcomeModal();
    const curr = CURRENCIES[currentCurrency] || CURRENCIES.INR;
    const rateEl = modal.querySelector('.cp-modal-outcome-rate');
    if (rateEl) {
      rateEl.textContent = curr.caiOutcome;
    }
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }

  function initOutcomeTriggers() {
    ensureOutcomeModal();

    document.addEventListener('click', function (e) {
      // 1. Explicit trigger button or question mark click
      const trigger = e.target.closest('.cp-outcome-info-trigger, [data-action="outcome-info"], .cp-outcome-btn, [data-lucide="help-circle"], [data-lucide="circle-help"], svg.lucide-circle-help, svg.lucide-help-circle');
      if (trigger) {
        if (trigger.closest('.cp-outcome-info-trigger, [data-action="outcome-info"], .cp-outcome-btn') || trigger.classList.contains('cp-outcome-info-trigger')) {
          e.preventDefault();
          e.stopPropagation();
          openOutcomeModal();
          return;
        }

        const parentBlock = trigger.closest('.cp-cai-outcome-rate, .cp-hero-outcome-price, [title*="outcome"], [title*="Outcome"]') || trigger.parentElement;
        const text = (parentBlock ? parentBlock.textContent : '').toLowerCase();
        const title = (trigger.getAttribute('title') || '').toLowerCase();
        if (text.includes('outcome') || title.includes('outcome')) {
          e.preventDefault();
          e.stopPropagation();
          openOutcomeModal();
        }
      }
    }, true);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCurrencyDropdown);
  } else {
    initCurrencyDropdown();
  }

  window.CP_Pricing = {
    setCurrency: function (code) {
      if (CURRENCIES[code]) {
        currentCurrency = code;
        localStorage.setItem('cp_pricing_currency', code);
        updatePrices();
      }
    },
    updatePrices: updatePrices,
    openOutcomeModal: openOutcomeModal
  };

  window.openOutcomeModal = openOutcomeModal;

})();
