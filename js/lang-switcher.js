/**
 * CUBOIDPILOT — INTERCOM-STYLE MULTILINGUAL LANGUAGE SWITCHER
 * Supports English, Hindi, Spanish, French, German, Arabic, Japanese, Portuguese, Chinese, Russian.
 * Translates both website (index.html) and blogs (blog.html) seamlessly.
 */

(function() {
  const LANGUAGES = [
    { code: 'en', name: 'English', native: 'English (US)', flag: '🇺🇸' },
    { code: 'hi', name: 'Hindi', native: 'हिन्दी', flag: '🇮🇳' },
    { code: 'es', name: 'Spanish', native: 'Español', flag: '🇪🇸' },
    { code: 'fr', name: 'French', native: 'Français', flag: '🇫🇷' },
    { code: 'de', name: 'German', native: 'Deutsch', flag: '🇩🇪' },
    { code: 'ar', name: 'Arabic', native: 'العربية', flag: '🇦🇪', rtl: true },
    { code: 'ja', name: 'Japanese', native: '日本語', flag: '🇯🇵' },
    { code: 'pt', name: 'Portuguese', native: 'Português', flag: '🇧🇷' },
    { code: 'zh-CN', name: 'Chinese', native: '简体中文', flag: '🇨🇳' },
    { code: 'ru', name: 'Russian', native: 'Русский', flag: '🇷🇺' }
  ];

  // Injected CSS for Intercom Style Dropdown & Clean Google Translate Cloaking
  const style = document.createElement('style');
  style.textContent = `
    /* Hide Google Translate top bar, menus, frames, balloons and ugly left list */
    .goog-te-banner-frame, 
    .goog-te-banner-frame.skiptranslate,
    iframe.goog-te-banner-frame,
    iframe.goog-te-menu-frame,
    .goog-te-menu-frame,
    .goog-te-menu2,
    table.goog-te-menu2,
    .goog-te-balloon-frame,
    #goog-gt-tt,
    .goog-te-spinner-pos,
    #google_translate_element,
    .goog-tooltip,
    .goog-tooltip:hover {
      display: none !important;
      visibility: hidden !important;
      opacity: 0 !important;
      pointer-events: none !important;
      position: absolute !important;
      left: -99999px !important;
      top: -99999px !important;
      width: 0 !important;
      height: 0 !important;
      z-index: -1000 !important;
    }
    body {
      top: 0px !important;
      position: static !important;
    }
    .goog-text-highlight {
      background-color: transparent !important;
      border: none !important;
      box-shadow: none !important;
    }

    /* Intercom-style Language Popover */
    .cp-lang-menu {
      animation: cpLangFadeIn 0.16s cubic-bezier(0.16, 1, 0.3, 1);
      box-shadow: 0 10px 30px -4px rgba(0,0,0,0.12), 0 4px 12px -2px rgba(0,0,0,0.06);
    }
    @keyframes cpLangFadeIn {
      from { opacity: 0; transform: translateY(-4px) scale(0.98); }
      to { opacity: 1; transform: translateY(0) scale(1); }
    }
  `;
  document.head.appendChild(style);

  // Hidden container for Google Translate widget
  let gtDiv = document.getElementById('google_translate_element');
  if (!gtDiv) {
    gtDiv = document.createElement('div');
    gtDiv.id = 'google_translate_element';
    gtDiv.style.display = 'none';
    document.body.appendChild(gtDiv);
  }

  // Get current active language
  function getActiveLang() {
    const saved = localStorage.getItem('cp_lang');
    if (saved) return saved;
    const cookie = document.cookie.match(/googtrans=\/en\/([a-zA-Z-]+)/);
    if (cookie && cookie[1]) return cookie[1];
    return 'en';
  }

  // Set active language
  window.changeSiteLanguage = function(langCode) {
    localStorage.setItem('cp_lang', langCode);
    
    // Set Google translate cookie for entire domain
    const host = window.location.hostname;
    const cookieVal = '/en/' + langCode;
    document.cookie = 'googtrans=' + cookieVal + '; path=/; domain=' + host;
    document.cookie = 'googtrans=' + cookieVal + '; path=/;';

    // Update UI elements
    updateLanguageButtons(langCode);

    // If Google Translate is initialized, trigger it
    const select = document.querySelector('.goog-te-combo');
    if (select) {
      select.value = langCode;
      select.dispatchEvent(new Event('change'));
    } else {
      // Reload if not ready so cookie kicks in
      window.location.reload();
    }

    // Close any open popovers
    document.querySelectorAll('.cp-lang-menu').forEach(m => m.classList.add('hidden'));
  };

  function updateLanguageButtons(langCode) {
    const current = LANGUAGES.find(l => l.code === langCode) || LANGUAGES[0];
    document.querySelectorAll('.cp-active-lang-code').forEach(el => {
      el.textContent = current.code.toUpperCase();
    });
    document.querySelectorAll('.cp-active-lang-name').forEach(el => {
      el.textContent = current.native;
    });
    document.querySelectorAll('.cp-active-lang-flag').forEach(el => {
      el.textContent = current.flag;
    });
  }

  // Render language switcher dropdown markup
  window.createLanguageDropdown = function(containerId, options = {}) {
    const container = document.getElementById(containerId);
    if (!container) return;

    const isFooter = options.isFooter || false;
    const curLang = getActiveLang();
    const current = LANGUAGES.find(l => l.code === curLang) || LANGUAGES[0];

    container.className = 'relative inline-block text-left select-none';
    container.innerHTML = `
      <button 
        type="button" 
        onclick="toggleLanguagePopover('${containerId}-menu')" 
        class="${isFooter 
          ? 'inline-flex items-center gap-2 px-3 py-1.5 rounded-[4px] border border-stone-200 bg-white hover:bg-stone-50 text-xs font-medium text-stone-700 transition-colors shadow-2xs' 
          : 'inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-[6px] hover:bg-stone-100 text-[13px] font-medium text-stone-700 hover:text-black transition-colors'}"
        aria-label="Select Language"
      >
        <i data-lucide="globe" class="w-3.5 h-3.5 text-stone-500"></i>
        <span class="cp-active-lang-flag text-sm leading-none">${current.flag}</span>
        <span class="${isFooter ? 'cp-active-lang-name' : 'cp-active-lang-code font-semibold'} text-xs">${isFooter ? current.native : current.code.toUpperCase()}</span>
        <i data-lucide="chevron-down" class="w-3 h-3 text-stone-400"></i>
      </button>

      <div 
        id="${containerId}-menu" 
        class="cp-lang-menu hidden absolute ${isFooter ? 'bottom-full mb-2 left-0 sm:right-0 sm:left-auto' : 'right-0 top-full mt-2'} w-64 rounded-[8px] border border-[#e7e5de] bg-white p-1.5 z-50 text-xs shadow-xl"
      >
        <div class="px-2.5 py-1.5 text-[11px] font-semibold text-stone-400 uppercase tracking-wider border-b border-stone-100 flex items-center justify-between">
          <span>Select Region &amp; Language</span>
          <span class="text-[10px] lowercase text-stone-400">10 languages</span>
        </div>
        <div class="max-h-60 overflow-y-auto py-1 space-y-0.5" id="${containerId}-list">
          ${LANGUAGES.map(l => `
            <button 
              type="button" 
              onclick="changeSiteLanguage('${l.code}')" 
              class="w-full flex items-center justify-between px-2.5 py-2 rounded-[5px] hover:bg-stone-100 text-left transition-colors ${l.code === curLang ? 'bg-stone-100/80 font-semibold text-black' : 'text-stone-700'}"
            >
              <span class="flex items-center gap-2.5">
                <span class="text-base leading-none">${l.flag}</span>
                <span class="text-xs text-stone-900">${l.native}</span>
                <span class="text-[11px] text-stone-400">(${l.name})</span>
              </span>
              ${l.code === curLang ? '<span class="text-emerald-600 font-bold text-xs">✓</span>' : ''}
            </button>
          `).join('')}
        </div>
      </div>
    `;

    if (window.lucide) window.lucide.createIcons();
  };

  window.toggleLanguagePopover = function(menuId) {
    const menu = document.getElementById(menuId);
    if (!menu) return;
    const isHidden = menu.classList.contains('hidden');
    // Close other menus first
    document.querySelectorAll('.cp-lang-menu').forEach(m => m.classList.add('hidden'));
    if (isHidden) {
      menu.classList.remove('hidden');
    }
  };

  // Close menus on outside click
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.cp-lang-menu') && !e.target.closest('button[onclick^="toggleLanguagePopover"]')) {
      document.querySelectorAll('.cp-lang-menu').forEach(m => m.classList.add('hidden'));
    }
  });

  // Global Google Translate Init Callback
  window.googleTranslateElementInit = function() {
    new google.translate.TranslateElement({
      pageLanguage: 'en',
      includedLanguages: 'en,hi,es,fr,de,ar,ja,pt,zh-CN,ru',
      autoDisplay: false,
      layout: google.translate.TranslateElement.InlineLayout.SIMPLE
    }, 'google_translate_element');

    const cur = getActiveLang();
    if (cur && cur !== 'en') {
      setTimeout(() => {
        const select = document.querySelector('.goog-te-combo');
        if (select) {
          select.value = cur;
          select.dispatchEvent(new Event('change'));
        }
      }, 300);
    }
  };

  // Asynchronously load Google Translate Engine
  const script = document.createElement('script');
  script.type = 'text/javascript';
  script.src = '//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit';
  script.async = true;
  document.head.appendChild(script);

})();