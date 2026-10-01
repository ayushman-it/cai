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
    updatePrices: updatePrices
  };

})();
