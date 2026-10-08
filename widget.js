/**
 * CUBOIDPILOT — EMBEDDABLE AI AGENT WIDGET (widget.js)
 * Standalone, zero-dependency, Shadow-DOM isolated conversational AI widget.
 * Precision-crafted to mirror the Intercom "Fin" AI reference design (media_1790678123351.png).
 * 
 * Works locally on localhost and seamlessly adapts to any live production domain or CDN (e.g. thecodemunk.in).
 */

(function () {
  'use strict';

  if (window.__CUBOIDPILOT_INITIALIZED__) return;
  window.__CUBOIDPILOT_INITIALIZED__ = true;

  // 1. Detect Base URL and Script Configuration
  const currentScript = document.currentScript || (function () {
    const scripts = document.getElementsByTagName('script');
    for (let i = scripts.length - 1; i >= 0; i--) {
      if (scripts[i].src && (scripts[i].src.includes('widget.js') || scripts[i].hasAttribute('data-company'))) {
        return scripts[i];
      }
    }
    return null;
  })();

  const scriptSrc = currentScript ? currentScript.src : window.location.href;
  
  // Custom API host override (via data-api="https://cai.cuboidsoft.in" or data-api="http://localhost/cuboidpilot")
  let customApiUrl = (currentScript && currentScript.getAttribute('data-api')) || '';
  let baseUrl = '';

  if (customApiUrl) {
    baseUrl = customApiUrl.replace(/\/+$/, '');
  } else {
    try {
      const urlObj = new URL(scriptSrc, window.location.href);
      if (urlObj.hostname === 'cai.cuboidsoft.in' || urlObj.hostname.includes('cuboidsoft.in')) {
        baseUrl = 'https://cai.cuboidsoft.in';
      } else if (urlObj.hostname === 'localhost' || urlObj.hostname === '127.0.0.1') {
        baseUrl = urlObj.origin + (urlObj.pathname.includes('/cuboidpilot') ? '/cuboidpilot' : urlObj.pathname.substring(0, urlObj.pathname.lastIndexOf('/')));
      } else {
        baseUrl = urlObj.origin + urlObj.pathname.substring(0, urlObj.pathname.lastIndexOf('/'));
      }
    } catch (e) {
      baseUrl = '';
    }
  }

  // Fallback if loaded directly as file or root or external client domain
  const isLocalHost = (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1');
  const isCuboidDomain = window.location.hostname.includes('cuboidsoft.in');

  if (!baseUrl || baseUrl === 'null' || (!isLocalHost && !isCuboidDomain && baseUrl === window.location.origin)) {
    baseUrl = isLocalHost ? (window.location.origin + '/cuboidpilot') : 'https://cai.cuboidsoft.in';
  } else if (baseUrl === window.location.origin) {
    if (isCuboidDomain) {
      baseUrl = 'https://cai.cuboidsoft.in';
    } else if (isLocalHost) {
      baseUrl = window.location.origin + '/cuboidpilot';
    } else {
      baseUrl = 'https://cai.cuboidsoft.in';
    }
  }

  const urlCompanyParam = (typeof window !== 'undefined' && window.location) ? (new URLSearchParams(window.location.search).get('company') || new URLSearchParams(window.location.search).get('company_key')) : null;
  const companyKey = urlCompanyParam ||
                     (typeof window !== 'undefined' && window.__CP_COMPANY_KEY__) ||
                     (currentScript && currentScript.getAttribute('data-company')) || 
                     (currentScript && currentScript.getAttribute('data-key')) || 
                     'cp_live_cuboidsoft';

  // 2. Storage Helper (Cross-window & Cross-embed synchronization via localStorage with sessionStorage fallback)
  const widgetStorage = {
    getItem(key) {
      try {
        const val = window.localStorage ? window.localStorage.getItem(key) : null;
        if (val !== null && val !== undefined) return val;
      } catch (e) {}
      try {
        return window.sessionStorage ? window.sessionStorage.getItem(key) : null;
      } catch (e) {
        return null;
      }
    },
    setItem(key, value) {
      try {
        if (window.localStorage) window.localStorage.setItem(key, String(value));
      } catch (e) {}
      try {
        if (window.sessionStorage) window.sessionStorage.setItem(key, String(value));
      } catch (e) {}
    },
    removeItem(key) {
      try {
        if (window.localStorage) window.localStorage.removeItem(key);
      } catch (e) {}
      try {
        if (window.sessionStorage) window.sessionStorage.removeItem(key);
      } catch (e) {}
    }
  };

  try {
    const prevKey = widgetStorage.getItem('cp_active_tenant_key');
    if (prevKey && prevKey !== companyKey) {
      widgetStorage.removeItem('cp_chat_history');
      widgetStorage.removeItem('cp_conversation_id');
      widgetStorage.removeItem('cp_lead_id');
      widgetStorage.removeItem('cp_visitor_name');
      widgetStorage.removeItem('cp_is_identified');
    }
    widgetStorage.setItem('cp_active_tenant_key', companyKey);

    // Wipe legacy un-scoped key if it contains another company's name
    const legacyHistory = widgetStorage.getItem('cp_chat_history');
    if (legacyHistory) {
      if (companyKey === 'cp_live_cuboidsoft' && legacyHistory.includes('The Code Munk')) {
        widgetStorage.removeItem('cp_chat_history');
        widgetStorage.removeItem('cp_conversation_id');
      }
    }
  } catch (e) {}

  const STORAGE_KEYS = {
    SESSION_ID: 'cp_session_id_' + companyKey,
    CONVO_ID: 'cp_conversation_id_' + companyKey,
    LEAD_ID: 'cp_lead_id_' + companyKey,
    CUSTOMER_ID: 'cp_customer_id_' + companyKey,
    VISITOR_NAME: 'cp_visitor_name_' + companyKey,
    VISITOR_EMAIL: 'cp_visitor_email_' + companyKey,
    VISITOR_PHONE: 'cp_visitor_phone_' + companyKey,
    IS_IDENTIFIED: 'cp_is_identified_' + companyKey,
    LEAD_STEP: 'cp_lead_step_' + companyKey,
    MESSAGES: 'cp_chat_history_' + companyKey,
    STATE: 'cp_widget_open_' + companyKey
  };

  function syncConversationAcrossWindows(cId, sId) {
    try {
      if (window.parent && window.parent !== window) {
        window.parent.postMessage({ type: 'CP_SYNC_CONVERSATION', conversationId: cId, sessionId: sId }, '*');
      }
      const iframes = document.querySelectorAll('iframe');
      iframes.forEach(f => {
        try {
          if (f.contentWindow) {
            f.contentWindow.postMessage({ type: 'CP_SYNC_CONVERSATION', conversationId: cId, sessionId: sId }, '*');
          }
        } catch(e) {}
      });
    } catch(e) {}
  }

  let sessionId = widgetStorage.getItem(STORAGE_KEYS.SESSION_ID);
  if (!sessionId) {
    sessionId = 'sess_' + Math.random().toString(36).substring(2, 10) + Date.now().toString(36);
    widgetStorage.setItem(STORAGE_KEYS.SESSION_ID, sessionId);
  }

  let conversationId = widgetStorage.getItem(STORAGE_KEYS.CONVO_ID) ? parseInt(widgetStorage.getItem(STORAGE_KEYS.CONVO_ID), 10) : null;
  let leadId = widgetStorage.getItem(STORAGE_KEYS.LEAD_ID) ? parseInt(widgetStorage.getItem(STORAGE_KEYS.LEAD_ID), 10) : null;
  let visitorName = widgetStorage.getItem(STORAGE_KEYS.VISITOR_NAME) || '';
  if (/^(hello|hi|hey|namaste|test|null|undefined|courses?|fee|fees|pricing|syllabus|python|java)$/i.test(visitorName.trim())) {
    visitorName = '';
    widgetStorage.removeItem(STORAGE_KEYS.VISITOR_NAME);
  }
  let visitorEmail = widgetStorage.getItem(STORAGE_KEYS.VISITOR_EMAIL) || '';
  let visitorPhone = widgetStorage.getItem(STORAGE_KEYS.VISITOR_PHONE) || '';
  let isIdentified = widgetStorage.getItem(STORAGE_KEYS.IS_IDENTIFIED) === '1';
  let leadStep = 0;

  // 3. Create Host and Shadow DOM
  const isEmbedded = (currentScript && (currentScript.getAttribute('data-embedded') === 'true' || currentScript.getAttribute('data-mode') === 'embedded')) || !!window.__CUBOIDPILOT_EMBEDDED__;
  const targetSelector = (currentScript && currentScript.getAttribute('data-target')) || '#cuboidpilot-embed-root';

  const hostElement = document.createElement('div');
  hostElement.id = 'cuboidpilot-widget-host';
  if (isEmbedded) {
    hostElement.classList.add('cp-embedded');
  }
  const initialTheme = (currentScript && currentScript.getAttribute('data-theme')) || 'dark';
  hostElement.setAttribute('data-theme', initialTheme);

  if (isEmbedded) {
    const mountEmbeddedHost = () => {
      const target = document.querySelector(targetSelector);
      if (target) {
        target.appendChild(hostElement);
      } else {
        document.body.appendChild(hostElement);
      }
    };
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', mountEmbeddedHost);
    } else {
      mountEmbeddedHost();
    }
  } else {
    const mountFloatingHost = () => {
      if (document.body && !document.body.contains(hostElement)) {
        document.body.appendChild(hostElement);
      }
    };
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', mountFloatingHost);
    } else {
      mountFloatingHost();
    }
  }

  const shadow = hostElement.attachShadow({ mode: 'open' });

  // 4. Inject Styles matching Intercom Fin AI aesthetic (media_1790678123351.png)
  const styleEl = document.createElement('style');
  styleEl.textContent = `
    *, *::before, *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
      -webkit-tap-highlight-color: transparent;
    }

    button, [role="button"], .cp-action-card, .cp-pill, .cp-date-chip, .cp-slot-pill, .cp-amount-chip, .cp-option-item {
      touch-action: manipulation;
    }

    .cp-stream, .cp-news-list, .cp-date-chips, .cp-slots-grid {
      -webkit-overflow-scrolling: touch;
    }

    :host {
      --cp-bg-canvas: #121316;
      --cp-bg-surface: #15161b;
      --cp-bg-bubble: #202228;
      --cp-border-bubble: #2d2f38;
      --cp-bg-input: #1a1b20;
      --cp-border-input: #282931;
      --cp-border-header: #22242c;
      --cp-text-primary: #f3f4f6;
      --cp-text-secondary: #8e929f;
      --cp-text-muted: #737885;
      --cp-text-title: #ffffff;
      --cp-user-bubble-bg: #272832;
      --cp-user-bubble-border: #3b3e4e;
      --cp-user-bubble-color: #ffffff;
      --cp-composer-focus-border: #3e414f;
      --cp-input-text: #ffffff;
      --cp-input-placeholder: #6b707e;
      --cp-toolbar-border: rgba(255, 255, 255, 0.04);
      --cp-tool-btn: #727684;
      --cp-tool-btn-hover: #ffffff;
      --cp-shadow-window: 0 20px 48px -12px rgba(0, 0, 0, 0.4), 0 8px 24px -6px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(255, 255, 255, 0.08);
      --cp-shadow-window-expanded: 0 24px 54px -12px rgba(0, 0, 0, 0.45), 0 10px 26px -6px rgba(0, 0, 0, 0.28), 0 0 0 1px rgba(255, 255, 255, 0.08);
      --cp-options-bg: #181920;
      --cp-options-border: #2d2f3b;
      --cp-options-hover: #232530;
      --cp-teaser-bg: #14151c;
      --cp-teaser-border: #282a38;
      --cp-teaser-text: #d1d4dc;
      --cp-teaser-title: #ffffff;
      --cp-wa-card-bg: #14151c;
      --cp-wa-card-border: #282a38;
      --cp-wa-text: #b8bccb;
      --cp-wa-btn-bg: #090a0d;
      --cp-wa-btn-border: #323545;
      --cp-wa-btn-color: #ffffff;
      --cp-send-btn-bg: #2b2d35;
      --cp-send-btn-border: #363842;
      --cp-send-btn-color: #6c707d;
      --cp-send-btn-active-bg: #ffffff;
      --cp-send-btn-active-border: #ffffff;
      --cp-send-btn-active-color: #111111;
      --cp-send-btn-hover-bg: #f3f4f6;
      z-index: 2147483647;
      position: fixed;
      pointer-events: none;
    }

    :host(.cp-embedded) {
      pointer-events: auto !important;
    }

    .cp-launcher, 
    .cp-teaser, 
    .cp-window.open, 
    .cp-options-menu.show {
      pointer-events: auto;
    }

    /* Light Theme (Intercom Clean Light Aesthetic) - WebKit / Safari iOS Compatible */
    :host([data-theme="light"]), 
    :host(.cp-theme-light),
    .cp-root-container.theme-light,
    .cp-root-container.cp-light-theme,
    .cp-light-theme,
    .theme-light,
    .cp-window.theme-light,
    .cp-window.cp-light-theme {
      --cp-bg-canvas: #ffffff;
      --cp-bg-surface: #ffffff;
      --cp-bg-bubble: #f3f4f6;
      --cp-border-bubble: #e5e7eb;
      --cp-bg-input: #f8fafc;
      --cp-border-input: #e2e8f0;
      --cp-border-header: #f1f5f9;
      --cp-text-primary: #1e293b;
      --cp-text-secondary: #64748b;
      --cp-text-muted: #94a3b8;
      --cp-text-title: #0f172a;
      --cp-user-bubble-bg: #111111;
      --cp-user-bubble-border: #111111;
      --cp-user-bubble-color: #ffffff;
      --cp-composer-focus-border: #94a3b8;
      --cp-input-text: #0f172a;
      --cp-input-placeholder: #94a3b8;
      --cp-toolbar-border: #f1f5f9;
      --cp-tool-btn: #64748b;
      --cp-tool-btn-hover: #0f172a;
      --cp-shadow-window: 0 18px 42px -10px rgba(0, 0, 0, 0.12), 0 6px 18px -4px rgba(0, 0, 0, 0.06), 0 0 0 1px rgba(0, 0, 0, 0.06);
      --cp-shadow-window-expanded: 0 22px 48px -12px rgba(0, 0, 0, 0.14), 0 8px 22px -6px rgba(0, 0, 0, 0.08), 0 0 0 1px rgba(0, 0, 0, 0.07);
      --cp-options-bg: #ffffff;
      --cp-options-border: #e2e8f0;
      --cp-options-hover: #f8fafc;
      --cp-teaser-bg: #ffffff;
      --cp-teaser-border: #e2e8f0;
      --cp-teaser-text: #334155;
      --cp-teaser-title: #0f172a;
      --cp-wa-card-bg: #f8fafc;
      --cp-wa-card-border: #e2e8f0;
      --cp-wa-text: #334155;
      --cp-wa-btn-bg: #111111;
      --cp-wa-btn-border: #111111;
      --cp-wa-btn-color: #ffffff;
      --cp-send-btn-bg: #e2e8f0;
      --cp-send-btn-border: #cbd5e1;
      --cp-send-btn-color: #94a3b8;
      --cp-send-btn-active-bg: #111111;
      --cp-send-btn-active-border: #111111;
      --cp-send-btn-active-color: #ffffff;
      --cp-send-btn-hover-bg: #000000;
    }

    /* Light Theme Launcher & Teaser Overrides */
    :host([data-theme="light"]) .cp-launcher:not(.open),
    .cp-light-theme.cp-launcher:not(.open),
    .theme-light.cp-launcher:not(.open),
    .cp-light-theme .cp-launcher:not(.open),
    .theme-light .cp-launcher:not(.open) {
      background: #ffffff !important;
      border-color: #e5e7eb !important;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12) !important;
    }

    :host([data-theme="light"]) .cp-launcher:not(.open) .cp-launcher-icon,
    .cp-light-theme .cp-launcher:not(.open) .cp-launcher-icon,
    .theme-light .cp-launcher:not(.open) .cp-launcher-icon {
      color: #111111 !important;
    }

    :host([data-theme="light"]) .cp-teaser,
    .cp-light-theme.cp-teaser,
    .theme-light.cp-teaser,
    .cp-light-theme .cp-teaser,
    .theme-light .cp-teaser {
      background-color: #ffffff !important;
      border: 1px solid #e2e8f0 !important;
      box-shadow: 0 16px 36px -6px rgba(0, 0, 0, 0.12), 0 0 0 1px rgba(0, 0, 0, 0.04) !important;
    }

    :host([data-theme="light"]) .cp-teaser:hover,
    .cp-light-theme.cp-teaser:hover,
    .theme-light.cp-teaser:hover,
    .cp-light-theme .cp-teaser:hover,
    .theme-light .cp-teaser:hover {
      background-color: #fafaf9 !important;
      border-color: #cbd5e1 !important;
    }

    :host([data-theme="light"]) .cp-teaser-header,
    .cp-light-theme .cp-teaser-header,
    .theme-light .cp-teaser-header {
      color: #0f172a !important;
    }

    :host([data-theme="light"]) .cp-teaser-msg,
    .cp-light-theme .cp-teaser-msg,
    .theme-light .cp-teaser-msg {
      color: #334155 !important;
    }

    :host([data-theme="light"]) .cp-teaser-meta,
    .cp-light-theme .cp-teaser-meta,
    .theme-light .cp-teaser-meta {
      color: #64748b !important;
    }

    :host([data-theme="light"]) .cp-teaser-close,
    .cp-light-theme .cp-teaser-close,
    .theme-light .cp-teaser-close {
      background: rgba(0, 0, 0, 0.06) !important;
      color: #64748b !important;
    }

    :host([data-theme="light"]) .cp-teaser-close:hover,
    .cp-light-theme .cp-teaser-close:hover,
    .theme-light .cp-teaser-close:hover {
      background: rgba(0, 0, 0, 0.12) !important;
      color: #0f172a !important;
    }

    :host([data-theme="light"]) .cp-disclaimer,
    .cp-light-theme .cp-disclaimer,
    .theme-light .cp-disclaimer {
      color: #94a3b8 !important;
    }

    :host([data-theme="light"]) .cp-disclaimer a,
    .cp-light-theme .cp-disclaimer a,
    .theme-light .cp-disclaimer a {
      color: #64748b !important;
    }

    /* Light Theme Send Button (Adapts seamlessly to white background) */
    :host([data-theme="light"]) .cp-send-btn,
    .cp-light-theme .cp-send-btn,
    .theme-light .cp-send-btn,
    .cp-window.theme-light .cp-send-btn,
    .cp-window.cp-light-theme .cp-send-btn {
      background: #e2e8f0 !important;
      border: 1px solid #cbd5e1 !important;
      color: #94a3b8 !important;
      box-shadow: none !important;
    }

    :host([data-theme="light"]) .cp-send-btn.active,
    .cp-light-theme .cp-send-btn.active,
    .theme-light .cp-send-btn.active,
    .cp-window.theme-light .cp-send-btn.active,
    .cp-window.cp-light-theme .cp-send-btn.active {
      background: var(--cp-accent-custom, #111111) !important;
      border: 1px solid var(--cp-accent-custom, #111111) !important;
      color: #ffffff !important;
      cursor: pointer !important;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.18) !important;
    }

    :host([data-theme="light"]) .cp-send-btn.active:hover,
    .cp-light-theme .cp-send-btn.active:hover,
    .theme-light .cp-send-btn.active:hover,
    .cp-window.theme-light .cp-send-btn.active:hover,
    .cp-window.cp-light-theme .cp-send-btn.active:hover {
      background: #000000 !important;
      transform: scale(1.05);
    }

    /* Dark Theme Send Button (Crisp, sharp, non-blurry, high-contrast) */
    :host([data-theme="dark"]) .cp-send-btn,
    .cp-dark-theme .cp-send-btn,
    .theme-dark .cp-send-btn,
    .cp-window.theme-dark .cp-send-btn,
    .cp-window.cp-dark-theme .cp-send-btn {
      background: #252836 !important;
      border: 1px solid #3c4052 !important;
      color: #7e8499 !important;
      box-shadow: none !important;
      opacity: 0.85;
      transform: none !important;
    }

    :host([data-theme="dark"]) .cp-send-btn.active,
    .cp-dark-theme .cp-send-btn.active,
    .theme-dark .cp-send-btn.active,
    .cp-window.theme-dark .cp-send-btn.active,
    .cp-window.cp-dark-theme .cp-send-btn.active {
      background: #ffffff !important;
      border: 1px solid #ffffff !important;
      color: #0f172a !important;
      cursor: pointer !important;
      opacity: 1 !important;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.35) !important;
      transform: none !important;
    }

    :host([data-theme="dark"]) .cp-send-btn.active svg,
    .cp-dark-theme .cp-send-btn.active svg,
    .theme-dark .cp-send-btn.active svg,
    .cp-window.theme-dark .cp-send-btn.active svg,
    .cp-window.cp-dark-theme .cp-send-btn.active svg {
      stroke: #0f172a !important;
    }

    :host([data-theme="dark"]) .cp-send-btn.active:hover,
    .cp-dark-theme .cp-send-btn.active:hover,
    .theme-dark .cp-send-btn.active:hover,
    .cp-window.theme-dark .cp-send-btn.active:hover,
    .cp-window.cp-dark-theme .cp-send-btn.active:hover {
      background: #e2e8f0 !important;
      transform: none !important;
    }

    /* Floating Launcher Button */
    .cp-launcher {
      position: fixed;
      bottom: 20px;
      right: 20px;
      width: 48px;
      height: 48px;
      border-radius: 50%;
      background: #14151a;
      border: 1px solid #282932;
      box-shadow: 0 8px 22px rgba(0, 0, 0, 0.45);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.24s cubic-bezier(0.16, 1, 0.3, 1);
      z-index: 2147483647;
      outline: none;
    }

    .cp-launcher:hover {
      transform: scale(1.06);
      box-shadow: 0 12px 30px rgba(0, 0, 0, 0.6);
    }

    .cp-launcher:active {
      transform: scale(0.96);
    }

    /* When Widget is OPEN: White circle with down chevron (matching screenshot media_1790678123351.png) */
    .cp-launcher.open {
      background: #ffffff !important;
      border-color: #e5e7eb !important;
      box-shadow: 0 10px 28px rgba(0, 0, 0, 0.35) !important;
    }

    .cp-launcher-icon, .cp-launcher-chevron {
      position: absolute;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: opacity 0.2s ease, transform 0.2s ease;
    }

    /* Fin 4-square icon on dark launcher */
    .cp-launcher-icon {
      opacity: 1;
      transform: scale(1);
    }

    /* White down chevron when open */
    .cp-launcher-chevron {
      opacity: 0;
      transform: scale(0.7) translateY(-2px);
      color: #111111;
    }

    .cp-launcher.open .cp-launcher-icon {
      opacity: 0;
      transform: scale(0.6);
    }

    .cp-launcher.open .cp-launcher-chevron {
      opacity: 1;
      transform: scale(1) translateY(0);
    }

    /* Subtle Professional Notification Dot (No numbers, clean minimal pip) */
    .cp-launcher-badge {
      position: absolute;
      top: 3px;
      right: 3px;
      width: 10px;
      height: 10px;
      background: #ef4444;
      border-radius: 50%;
      display: none;
      border: 2px solid #14151a;
      box-shadow: 0 0 0 1px rgba(239, 68, 68, 0.4), 0 2px 5px rgba(239, 68, 68, 0.6);
      z-index: 3;
      pointer-events: none;
      animation: cp-badge-pop 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .cp-launcher.open .cp-launcher-badge {
      border-color: #ffffff;
    }

    @keyframes cp-badge-pop {
      0% { transform: scale(0); }
      70% { transform: scale(1.2); }
      100% { transform: scale(1); }
    }

    /* Proactive Teaser Preview Bubble (media_1790706499005.png) */
    .cp-teaser {
      position: fixed;
      bottom: 20px;
      right: 78px;
      width: 310px;
      max-width: calc(100vw - 98px);
      background-color: #14151c;
      border: 1px solid #282a38;
      border-radius: 16px;
      padding: 15px 18px;
      box-shadow: 0 16px 36px -6px rgba(0, 0, 0, 0.65), 0 0 0 1px rgba(255, 255, 255, 0.05);
      cursor: pointer;
      opacity: 0;
      pointer-events: none;
      transform: translateY(12px) scale(0.95);
      transition: opacity 0.3s cubic-bezier(0.16, 1, 0.3, 1), transform 0.35s cubic-bezier(0.16, 1, 0.3, 1), background-color 0.2s;
      z-index: 2147483645;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      user-select: none;
    }

    .cp-teaser.show {
      opacity: 1;
      pointer-events: auto;
      transform: translateY(0) scale(1);
    }

    .cp-teaser:hover {
      background-color: #181923;
      border-color: #35384a;
      box-shadow: 0 20px 42px -6px rgba(0, 0, 0, 0.75), 0 0 0 1px rgba(255, 255, 255, 0.08);
    }

    .cp-teaser-close {
      position: absolute;
      top: 10px;
      right: 10px;
      width: 22px;
      height: 22px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.08);
      border: none;
      color: #9da3b4;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      opacity: 0;
      transition: opacity 0.15s, background-color 0.15s, color 0.15s;
    }

    .cp-teaser:hover .cp-teaser-close {
      opacity: 1;
    }

    .cp-teaser-close:hover {
      background: rgba(255, 255, 255, 0.2);
      color: #ffffff;
    }

    .cp-teaser-content {
      display: flex;
      align-items: flex-start;
      gap: 13px;
    }

    .cp-teaser-logo-box {
      width: 30px;
      height: 30px;
      flex-shrink: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-top: 1px;
    }

    .cp-teaser-logo {
      width: 100%;
      height: 100%;
      object-fit: contain;
    }

    .cp-teaser-body {
      flex: 1;
      min-width: 0;
    }

    .cp-teaser-header {
      font-size: 14.5px;
      font-weight: 600;
      color: var(--cp-teaser-title, #0f172a);
      line-height: 1.25;
      display: flex;
      align-items: center;
      gap: 5px;
    }

    .cp-teaser-msg {
      font-size: 13.5px;
      color: var(--cp-teaser-text, #334155);
      line-height: 1.42;
      margin-top: 5px;
      margin-bottom: 7px;
      word-break: break-word;
    }

    .cp-teaser-meta {
      font-size: 11.5px;
      color: var(--cp-text-muted, #64748b);
      display: flex;
      align-items: center;
      gap: 5px;
      font-weight: 500;
    }

    @media (max-width: 480px) {
      .cp-launcher {
        right: 16px;
        bottom: 16px;
        width: 44px;
        height: 44px;
      }
      .cp-teaser {
        right: 16px;
        bottom: 72px;
        width: calc(100vw - 32px);
        max-width: 100vw;
      }
    }

    /* Embedded Mode Overrides (Step 3 Test AI Sandbox & In-page Mounts) */
    :host(.cp-embedded),
    #cuboidpilot-widget-host.cp-embedded {
      position: relative !important;
      display: flex !important;
      flex-direction: column !important;
      width: 100% !important;
      height: 100% !important;
      z-index: 10 !important;
      overflow: hidden !important;
    }

    :host(.cp-embedded) .cp-root-container,
    .cp-embedded .cp-root-container {
      width: 100% !important;
      height: 100% !important;
      display: flex !important;
      flex-direction: column !important;
      flex: 1 1 auto !important;
      overflow: hidden !important;
    }

    :host(.cp-embedded) .cp-launcher,
    :host(.cp-embedded) .cp-teaser,
    .cp-embedded .cp-launcher,
    .cp-embedded .cp-teaser {
      display: none !important;
    }

    :host(.cp-embedded) .cp-window,
    .cp-embedded .cp-window {
      position: relative !important;
      bottom: auto !important;
      right: auto !important;
      top: auto !important;
      left: auto !important;
      width: 100% !important;
      height: 100% !important;
      min-height: 100% !important;
      max-height: 100% !important;
      opacity: 1 !important;
      pointer-events: auto !important;
      transform: none !important;
      border-radius: 0 !important;
      border: none !important;
      box-shadow: none !important;
      flex: 1 1 auto !important;
    }

    :host(.cp-embedded) #cp-close-btn,
    .cp-embedded #cp-close-btn,
    :host(.cp-embedded) #cp-expand-btn,
    .cp-embedded #cp-expand-btn,
    :host(.cp-embedded) .cp-header-btn.close,
    .cp-embedded .cp-header-btn.close {
      display: none !important;
    }

    /* Main Chat Window (Intercom Fin dark UI - Compact & Sleek) */
    .cp-window {
      position: fixed;
      bottom: 78px;
      right: 20px;
      width: 385px;
      height: 640px;
      max-height: calc(100vh - 84px);
      max-height: calc(100dvh - 84px);
      background-color: var(--cp-bg-canvas);
      border: 1px solid var(--cp-border-header);
      border-radius: 18px;
      box-shadow: var(--cp-shadow-window);
      display: flex;
      flex-direction: column;
      overflow: hidden;
      opacity: 0;
      pointer-events: none;
      transform-origin: bottom right;
      transform: translateY(18px) scale(0.96);
      transition: width 0.32s cubic-bezier(0.16, 1, 0.3, 1),
                  height 0.32s cubic-bezier(0.16, 1, 0.3, 1),
                  max-height 0.32s cubic-bezier(0.16, 1, 0.3, 1),
                  max-width 0.32s cubic-bezier(0.16, 1, 0.3, 1),
                  opacity 0.28s cubic-bezier(0.16, 1, 0.3, 1),
                  transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
      z-index: 2147483646;
    }

    .cp-window.open {
      opacity: 1;
      pointer-events: auto;
      transform: translateY(0) scale(1);
    }

    /* Expanded Window Mode (Intercom Fin Spacious Desktop View) */
    .cp-window.expanded {
      width: 780px;
      height: 800px;
      max-width: calc(100vw - 32px);
      max-height: calc(100vh - 84px);
      max-height: calc(100dvh - 84px);
      box-shadow: var(--cp-shadow-window-expanded, var(--cp-shadow-window));
    }

    .cp-window.expanded .cp-header-subtitle {
      max-width: 480px;
    }

    .cp-window.expanded .cp-messages {
      padding: 18px 24px;
    }

    .cp-window.expanded .cp-msg-row.user .cp-bubble {
      max-width: 580px;
    }

    .cp-window.expanded .cp-msg-row.ai .cp-bubble {
      max-width: 660px;
    }

    .cp-window.expanded .cp-composer-section {
      padding: 12px 20px 14px 20px;
    }

    .cp-window.expanded .cp-screens-view {
      padding: 18px 24px;
    }

    @media (max-width: 840px) {
      .cp-window.expanded {
        width: calc(100vw - 20px) !important;
        right: 10px !important;
        bottom: 74px !important;
        height: calc(100vh - 84px) !important;
        height: calc(100dvh - 84px) !important;
        max-height: calc(100vh - 84px) !important;
      }
    }

    /* Window Header */
    .cp-header {
      background-color: var(--cp-bg-surface);
      border-bottom: 1px solid var(--cp-border-header);
      padding: 12px 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      user-select: none;
      flex-shrink: 0;
      height: 56px;
      box-sizing: border-box;
    }

    .cp-header-left {
      display: flex;
      align-items: center;
      gap: 8px;
      min-width: 0;
    }

    .cp-back-btn {
      background: transparent;
      border: none;
      color: var(--cp-text-secondary);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      width: 28px;
      height: 28px;
      min-width: 28px !important;
      min-height: 28px !important;
      max-width: 28px;
      max-height: 28px;
      padding: 0 !important;
      margin: 0 !important;
      border-radius: 6px;
      transition: background-color 0.15s, color 0.15s;
      flex-shrink: 0;
    }

    .cp-back-btn:hover {
      background: rgba(0, 0, 0, 0.05);
      color: var(--cp-text-title);
    }

    :host([data-theme="dark"]) .cp-back-btn:hover,
    .cp-dark-theme .cp-back-btn:hover,
    .theme-dark .cp-back-btn:hover {
      background: rgba(255, 255, 255, 0.08);
    }

    /* Cai Brand Logo (media_1790705726663.png) */
    .cp-brand-logo {
      height: 24px;
      width: auto;
      max-width: 32px;
      object-fit: contain;
      flex-shrink: 0;
      display: block;
      margin: 0;
      padding: 0;
    }

    .cp-launcher-logo {
      height: 26px;
      width: auto;
      max-width: 34px;
      object-fit: contain;
      display: block;
      filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.3));
    }

    .cp-header-title-box {
      display: flex;
      flex-direction: column;
      margin: 0;
      padding: 0;
      justify-content: center;
      min-width: 0;
    }

    .cp-header-title {
      font-size: 14.5px;
      font-weight: 600;
      color: var(--cp-text-title);
      line-height: 1.25;
      letter-spacing: -0.01em;
    }

    .cp-header-subtitle {
      font-size: 11px;
      color: var(--cp-text-secondary);
      font-weight: 400;
      line-height: 1.25;
      margin-top: 1px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 175px;
    }

    .cp-header-actions {
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .cp-icon-btn {
      background: transparent;
      border: none;
      color: var(--cp-text-secondary);
      cursor: pointer;
      width: 28px;
      height: 28px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 4px;
      transition: color 0.15s, background-color 0.15s;
    }

    .cp-icon-btn:hover {
      color: var(--cp-text-title);
      background: var(--cp-tool-btn-hover-bg);
    }

    /* Options Menu Dropdown */
    .cp-options-menu {
      position: absolute;
      top: 54px;
      right: 16px;
      background: var(--cp-options-bg);
      border: 1px solid var(--cp-options-border);
      border-radius: 8px;
      padding: 6px;
      box-shadow: var(--cp-shadow-window);
      z-index: 100;
      display: none;
      width: 200px;
    }

    .cp-options-menu.show {
      display: block;
      animation: fadeIn 0.15s ease-out;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-4px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .cp-option-item {
      padding: 8px 10px;
      font-size: 12px;
      color: var(--cp-text-primary);
      border-radius: 4px;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 8px;
      transition: background 0.15s;
    }

    .cp-option-item:hover {
      background: var(--cp-options-hover);
    }

    /* Messages Scroll Area */
    .cp-messages {
      flex: 1;
      overflow-y: auto;
      overflow-x: hidden !important;
      padding: 16px 14px 12px 14px;
      display: flex;
      flex-direction: column;
      gap: 12px;
      scroll-behavior: smooth;
      box-sizing: border-box;
      width: 100%;
    }

    .cp-messages::-webkit-scrollbar {
      width: 4px;
    }

    .cp-messages::-webkit-scrollbar-thumb {
      background: #282a35;
      border-radius: 10px;
    }

    /* Stream container flows naturally inside single scrollable .cp-messages */
    #cp-chat-stream {
      display: flex;
      flex-direction: column;
      gap: 12px;
      width: 100%;
      max-width: 100%;
      box-sizing: border-box;
      overflow: visible !important;
    }

    #cp-welcome-row,
    .cp-msg-row.cp-welcome-row {
      position: static !important;
      top: auto !important;
      flex-shrink: 0;
      margin: 0;
    }

    /* Message Bubbles */
    .cp-msg-row {
      display: flex;
      flex-direction: column;
      gap: 4px;
      animation: msgPop 0.22s cubic-bezier(0.16, 1, 0.3, 1);
      box-sizing: border-box;
      width: 100%;
    }

    @keyframes msgPop {
      from { opacity: 0; transform: translateY(6px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .cp-msg-row.ai {
      align-items: flex-start;
      align-self: flex-start;
      max-width: 88%;
      box-sizing: border-box;
    }

    .cp-msg-row.ai.has-cards {
      max-width: 100% !important;
      width: 100% !important;
    }

    .cp-msg-row.user {
      align-items: flex-end;
      align-self: flex-end;
      margin-left: auto;
      max-width: 82%;
    }

    .cp-bubble {
      padding: 11px 14px;
      font-size: 13.5px;
      line-height: 1.45;
      word-break: break-word;
      box-sizing: border-box;
      max-width: 100%;
    }

    .cp-msg-row.ai.has-cards .cp-bubble {
      max-width: 100% !important;
      width: 100% !important;
    }

    .cp-msg-row.ai .cp-bubble {
      background: var(--cp-bg-bubble);
      border: 1px solid var(--cp-border-bubble);
      color: var(--cp-text-primary);
      border-radius: 16px;
      border-bottom-left-radius: 4px;
    }

    .cp-msg-row.user .cp-bubble {
      background: var(--cp-user-bubble-bg);
      border: 1px solid var(--cp-user-bubble-border);
      color: var(--cp-user-bubble-color);
      border-radius: 16px;
      border-bottom-right-radius: 4px;
      text-align: left;
    }

    .cp-meta-line {
      font-size: 11px;
      color: var(--cp-text-muted);
      padding: 0 4px;
      display: flex;
      align-items: center;
      gap: 5px;
      user-select: none;
    }

    .cp-msg-row.user .cp-meta-line {
      align-self: flex-end;
      justify-content: flex-end;
    }

    /* WhatsApp Action Button inside AI Bubble */
    .cp-whatsapp-card {
      background: var(--cp-wa-card-bg);
      border: 1px solid var(--cp-wa-card-border);
      border-radius: 10px;
      padding: 12px 14px;
      margin-top: 10px;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .cp-wa-text {
      font-size: 12px;
      color: var(--cp-wa-text);
      line-height: 1.45;
      font-weight: 400;
    }

    .cp-wa-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      background: var(--cp-wa-btn-bg);
      color: var(--cp-wa-btn-color);
      border: 1px solid var(--cp-wa-btn-border);
      font-size: 12.5px;
      font-weight: 500;
      padding: 8px 14px;
      border-radius: 6px;
      text-decoration: none;
      transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
    }

    .cp-wa-btn:hover {
      opacity: 0.92;
      transform: translateY(-1px);
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.3);
    }

    .cp-wa-btn svg {
      width: 15px;
      height: 15px;
      fill: #25d366;
      flex-shrink: 0;
    }

    /* Chat Ended Concluding State */
    .cp-chat-ended-container {
      display: flex;
      flex-direction: column;
      gap: 12px;
      margin: 16px 0 8px 0;
      animation: msgPop 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .cp-chat-ended-divider {
      display: flex;
      align-items: center;
      text-align: center;
      color: var(--cp-text-muted);
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .cp-chat-ended-divider::before,
    .cp-chat-ended-divider::after {
      content: '';
      flex: 1;
      border-bottom: 1px solid var(--cp-options-border);
    }

    .cp-chat-ended-divider span {
      padding: 0 10px;
    }

    .cp-chat-ended-card {
      background: var(--cp-wa-card-bg);
      border: 1px solid var(--cp-wa-card-border);
      border-radius: 12px;
      padding: 14px;
      display: flex;
      flex-direction: column;
      gap: 12px;
      text-align: center;
    }

    .cp-ended-title {
      font-size: 12.5px;
      color: var(--cp-text-secondary);
      line-height: 1.4;
    }

    .cp-ended-actions {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .cp-ended-restart-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 7px;
      background: transparent;
      color: var(--cp-text-primary);
      border: 1px solid var(--cp-options-border);
      font-size: 12.5px;
      font-weight: 500;
      padding: 8px 14px;
      border-radius: 6px;
      cursor: pointer;
      transition: all 0.2s ease;
      font-family: inherit;
    }

    .cp-ended-restart-btn:hover {
      background: var(--cp-options-hover);
      border-color: var(--cp-text-muted);
    }

    /* Typing & Thinking Indicator Bubble (Modern AI Aesthetic) */
    .cp-typing-row {
      display: flex;
      align-items: flex-end;
      gap: 8px;
      animation: msgPop 0.25s cubic-bezier(0.16, 1, 0.3, 1);
      margin-bottom: 4px;
    }

    .cp-typing-avatar {
      width: 24px;
      height: 24px;
      border-radius: 50%;
      background: #111111;
      border: 1px solid rgba(255, 255, 255, 0.15);
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      padding: 4px;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
    }

    .cp-typing-avatar-img {
      width: 100%;
      height: 100%;
      object-fit: contain;
    }

    /* Intercom Fin AI Skeleton Loading Bubble & Typing Indicator */
    .cp-skeleton-bubble {
      background: var(--cp-bg-bubble);
      border: 1px solid var(--cp-border-bubble);
      padding: 12px 14px;
      border-radius: 16px;
      border-bottom-left-radius: 4px;
      display: flex;
      flex-direction: column;
      gap: 10px;
      width: fit-content;
      min-width: 240px;
      max-width: 340px;
      box-shadow: 0 4px 14px rgba(0, 0, 0, 0.15);
      position: relative;
      overflow: hidden;
    }

    .cp-skeleton-header {
      display: flex;
      align-items: center;
      gap: 7px;
    }

    .cp-skeleton-lines {
      display: flex;
      flex-direction: column;
      gap: 7px;
      width: 100%;
    }

    .cp-skeleton-line {
      height: 9px;
      border-radius: 5px;
      background: rgba(255, 255, 255, 0.08);
      position: relative;
      overflow: hidden;
    }

    :host([data-theme="light"]) .cp-skeleton-line,
    .theme-light .cp-skeleton-line,
    .cp-light-theme .cp-skeleton-line {
      background: rgba(0, 0, 0, 0.07);
    }

    .cp-skeleton-line::after,
    .cp-skeleton-box::after,
    .cp-skeleton-circle::after {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: linear-gradient(90deg, transparent 0%, rgba(255, 255, 255, 0.14) 50%, transparent 100%);
      transform: translateX(-100%);
      animation: cpSkeletonShimmer 1.5s cubic-bezier(0.4, 0, 0.2, 1) infinite;
    }

    :host([data-theme="light"]) .cp-skeleton-line::after,
    :host([data-theme="light"]) .cp-skeleton-box::after,
    :host([data-theme="light"]) .cp-skeleton-circle::after,
    .theme-light .cp-skeleton-line::after,
    .theme-light .cp-skeleton-box::after,
    .theme-light .cp-skeleton-circle::after,
    .cp-light-theme .cp-skeleton-line::after,
    .cp-light-theme .cp-skeleton-box::after,
    .cp-light-theme .cp-skeleton-circle::after {
      background: linear-gradient(90deg, transparent 0%, rgba(0, 0, 0, 0.08) 50%, transparent 100%);
    }

    @keyframes cpSkeletonShimmer {
      0% { transform: translateX(-100%); }
      100% { transform: translateX(100%); }
    }

    /* Screen View Skeleton Placeholders */
    .cp-skeleton-card-item {
      background: var(--cp-bg-surface);
      border: 1px solid var(--cp-border-input);
      border-radius: 12px;
      padding: 12px 14px;
      display: flex;
      align-items: center;
      gap: 12px;
      position: relative;
      overflow: hidden;
    }

    .cp-skeleton-circle {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.08);
      position: relative;
      overflow: hidden;
      flex-shrink: 0;
    }

    :host([data-theme="light"]) .cp-skeleton-circle,
    .theme-light .cp-skeleton-circle,
    .cp-light-theme .cp-skeleton-circle {
      background: rgba(0, 0, 0, 0.07);
    }

    .cp-skeleton-card-body {
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 7px;
    }

    .cp-skeleton-box {
      border-radius: 6px;
      background: rgba(255, 255, 255, 0.08);
      position: relative;
      overflow: hidden;
    }

    :host([data-theme="light"]) .cp-skeleton-box,
    .theme-light .cp-skeleton-box,
    .cp-light-theme .cp-skeleton-box {
      background: rgba(0, 0, 0, 0.07);
    }

    .cp-typing-bubble {
      background: var(--cp-bg-bubble);
      border: 1px solid var(--cp-border-bubble);
      padding: 9px 13px;
      border-radius: 16px;
      border-bottom-left-radius: 4px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      width: fit-content;
      box-shadow: 0 4px 14px rgba(0, 0, 0, 0.15);
      position: relative;
      overflow: hidden;
    }

    .cp-typing-bubble::after {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.05), transparent);
      animation: cpShimmer 1.8s infinite;
      pointer-events: none;
    }

    @keyframes cpShimmer {
      0% { left: -100%; }
      100% { left: 200%; }
    }

    .cp-typing-dots {
      display: flex;
      align-items: center;
      gap: 3.5px;
    }

    .cp-typing-dot {
      width: 5px;
      height: 5px;
      background-color: var(--cp-text-secondary);
      border-radius: 50%;
      animation: cpBounce 1.3s infinite ease-in-out both;
    }

    .cp-typing-dot:nth-child(1) { animation-delay: -0.32s; }
    .cp-typing-dot:nth-child(2) { animation-delay: -0.16s; }
    .cp-typing-dot:nth-child(3) { animation-delay: 0s; }

    @keyframes cpBounce {
      0%, 80%, 100% {
        transform: translateY(0) scale(0.85);
        opacity: 0.35;
      }
      40% {
        transform: translateY(-3px) scale(1.15);
        opacity: 1;
        background-color: #ffffff;
      }
    }

    .cp-typing-label {
      font-size: 11px;
      font-weight: 500;
      color: var(--cp-text-secondary);
      letter-spacing: 0.01em;
      white-space: nowrap;
    }

    /* Streaming typewriter blinking cursor */
    .cp-stream-cursor {
      display: inline-block;
      width: 2px;
      height: 14px;
      background-color: #60a5fa;
      margin-left: 2px;
      vertical-align: middle;
      border-radius: 1px;
      animation: cpCursorBlink 0.75s infinite;
    }

    @keyframes cpCursorBlink {
      0%, 45% { opacity: 1; }
      50%, 100% { opacity: 0; }
    }

    /* Intercom-style Minimalist Waiting / Status Pill (No bulky card, no hardcoded paragraphs) */
    .cp-waiting-pill {
      margin: 12px auto;
      padding: 6px 14px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 7.5px;
      background: var(--cp-surface);
      border: 1px solid var(--cp-border);
      border-radius: 999px;
      font-size: 11.5px;
      font-weight: 500;
      color: var(--cp-text-secondary);
      box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
      animation: cpFadeIn 0.25s ease;
      align-self: center;
      transition: all 0.3s ease;
    }

    .cp-waiting-pill.connected {
      background: rgba(16, 185, 129, 0.08);
      border-color: rgba(16, 185, 129, 0.3);
      color: #047857;
    }

    .cp-waiting-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #f59e0b;
      position: relative;
      flex-shrink: 0;
    }

    .cp-waiting-dot::after {
      content: '';
      position: absolute;
      top: -3px;
      left: -3px;
      width: 13px;
      height: 13px;
      border-radius: 50%;
      background: rgba(245, 158, 11, 0.35);
      animation: cpPulseRing 1.5s infinite ease-out;
    }

    .cp-waiting-pill.connected .cp-waiting-dot {
      background: #10b981;
    }

    .cp-waiting-pill.connected .cp-waiting-dot::after {
      display: none;
    }

    @keyframes cpPulseRing {
      0% { transform: scale(0.6); opacity: 0.9; }
      100% { transform: scale(1.6); opacity: 0; }
    }

    .cp-waiting-text {
      font-size: 11.5px;
      font-weight: 500;
      color: var(--cp-text-primary);
    }

    .cp-waiting-timer {
      font-variant-numeric: tabular-nums;
      font-weight: 600;
      font-size: 11px;
      color: var(--cp-text-secondary);
      background: rgba(120, 120, 120, 0.12);
      padding: 1px 6px;
      border-radius: 999px;
    }

    .cp-waiting-pill.connected .cp-waiting-timer {
      display: none;
    }

    /* Composer Section (Strict Intercom Fin match) */
    .cp-composer-section {
      padding: 0 16px 14px 16px;
      background: var(--cp-bg-canvas);
      flex-shrink: 0;
      position: relative;
    }

    .cp-composer-capsule {
      background: var(--cp-bg-input);
      border: 1px solid var(--cp-border-input);
      border-radius: 16px;
      padding: 12px 14px 10px 14px;
      display: flex;
      flex-direction: column;
      gap: 10px;
      transition: border-color 0.2s, box-shadow 0.2s;
    }

    .cp-composer-capsule:focus-within {
      border-color: var(--cp-composer-focus-border);
      box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.08);
    }

    /* Attachment Preview Bar inside Composer */
    .cp-attach-preview-bar {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 6px 10px;
      background: rgba(255, 255, 255, 0.04);
      border: 1px solid var(--cp-border-input);
      border-radius: 8px;
    }

    .cp-attach-thumb {
      width: 32px;
      height: 32px;
      border-radius: 6px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: rgba(0, 0, 0, 0.25);
      font-size: 16px;
      flex-shrink: 0;
      overflow: hidden;
      border: 1px solid rgba(255, 255, 255, 0.06);
    }

    .cp-attach-thumb img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .cp-attach-info {
      flex: 1;
      min-width: 0;
    }

    .cp-attach-name {
      font-size: 12px;
      font-weight: 500;
      color: var(--cp-text-primary);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .cp-attach-size {
      font-size: 10.5px;
      color: var(--cp-text-muted);
    }

    .cp-attach-remove {
      width: 20px;
      height: 20px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.08);
      border: none;
      color: var(--cp-text-secondary);
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      font-size: 11px;
      line-height: 1;
      transition: background 0.15s, color 0.15s;
    }

    .cp-attach-remove:hover {
      background: rgba(239, 68, 68, 0.2);
      color: #ef4444;
    }

    /* Voice / Speech Recognition Indicator */
    .cp-voice-indicator {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      padding: 6px 10px;
      background: rgba(239, 68, 68, 0.08);
      border: 1px solid rgba(239, 68, 68, 0.25);
      border-radius: 8px;
      color: #f87171;
      font-size: 11.5px;
      font-weight: 500;
    }

    .cp-voice-pulse {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #ef4444;
      animation: cp-voice-dot 1s infinite alternate;
      display: inline-block;
    }

    @keyframes cp-voice-dot {
      from { opacity: 0.3; transform: scale(0.9); }
      to { opacity: 1; transform: scale(1.3); }
    }

    .cp-voice-stop-btn {
      background: rgba(239, 68, 68, 0.2);
      border: 1px solid rgba(239, 68, 68, 0.4);
      color: #fca5a5;
      font-size: 10.5px;
      font-weight: 600;
      padding: 2px 7px;
      border-radius: 4px;
      cursor: pointer;
      transition: background 0.15s;
    }

    .cp-voice-stop-btn:hover {
      background: rgba(239, 68, 68, 0.35);
      color: #ffffff;
    }

    .cp-tool-btn.recording {
      color: #ef4444 !important;
      background: rgba(239, 68, 68, 0.18) !important;
      animation: cp-pulse-recording 1.2s infinite ease-in-out;
    }

    @keyframes cp-pulse-recording {
      0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
      50% { transform: scale(1.15); box-shadow: 0 0 0 6px rgba(239, 68, 68, 0); }
      100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
    }

    .cp-input-field {
      width: 100%;
      background: transparent;
      border: none;
      outline: none;
      color: var(--cp-input-text);
      font-size: 14px;
      line-height: 1.4;
      resize: none;
      max-height: 100px;
      min-height: 22px;
    }

    .cp-input-field::placeholder {
      color: var(--cp-input-placeholder);
    }

    /* Action bar inside bottom of composer */
    .cp-composer-toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-top: 1px solid var(--cp-toolbar-border);
      padding-top: 8px;
    }

    .cp-toolbar-actions {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .cp-tool-btn {
      background: transparent;
      border: none;
      color: var(--cp-tool-btn);
      width: 24px;
      height: 24px;
      border-radius: 4px;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: color 0.15s, background-color 0.15s;
    }

    .cp-tool-btn:hover {
      color: var(--cp-tool-btn-hover);
      background: var(--cp-tool-btn-hover-bg);
    }

    .cp-gif-badge {
      font-size: 9.5px;
      font-weight: 700;
      letter-spacing: 0.04em;
      border: 1px solid #444754;
      padding: 1px 3.5px;
      border-radius: 3px;
      color: #8e929f;
    }

    .cp-tool-btn:hover .cp-gif-badge {
      color: #ffffff;
      border-color: #8e929f;
    }

    /* Floating Popovers (Emoji & GIF) */
    .cp-popover {
      position: absolute;
      bottom: calc(100% - 4px);
      left: 16px;
      right: 16px;
      background: var(--cp-bg-surface);
      border: 1px solid var(--cp-border-input);
      border-radius: 14px;
      box-shadow: 0 16px 36px rgba(0, 0, 0, 0.45);
      z-index: 100;
      padding: 12px;
      display: flex;
      flex-direction: column;
      gap: 10px;
      animation: cp-popover-in 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes cp-popover-in {
      from { opacity: 0; transform: translateY(8px) scale(0.98); }
      to { opacity: 1; transform: translateY(0) scale(1); }
    }

    .cp-popover-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 1px solid var(--cp-toolbar-border);
      padding-bottom: 8px;
    }

    .cp-popover-title {
      font-size: 12px;
      font-weight: 600;
      color: var(--cp-text-primary);
    }

    .cp-popover-close {
      background: transparent;
      border: none;
      color: var(--cp-text-muted);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 2px 5px;
      border-radius: 4px;
      font-size: 13px;
      line-height: 1;
    }

    .cp-popover-close:hover {
      color: var(--cp-text-primary);
      background: rgba(255, 255, 255, 0.08);
    }

    /* Emoji Picker Styling */
    .cp-emoji-tabs {
      display: flex;
      gap: 4px;
      border-bottom: 1px solid var(--cp-toolbar-border);
      padding-bottom: 6px;
    }

    .cp-emoji-tab {
      background: transparent;
      border: none;
      color: var(--cp-text-muted);
      font-size: 11px;
      padding: 3px 8px;
      border-radius: 6px;
      cursor: pointer;
      transition: all 0.15s;
    }

    .cp-emoji-tab.active, .cp-emoji-tab:hover {
      color: var(--cp-text-primary);
      background: rgba(255, 255, 255, 0.06);
    }

    .cp-emoji-grid {
      display: grid;
      grid-template-columns: repeat(8, 1fr);
      gap: 4px;
      max-height: 170px;
      overflow-y: auto;
      padding-right: 4px;
    }

    .cp-emoji-btn {
      background: transparent;
      border: none;
      font-size: 17px;
      padding: 4px;
      border-radius: 6px;
      cursor: pointer;
      transition: transform 0.1s, background 0.15s;
      display: flex;
      align-items: center;
      justify-content: center;
      line-height: 1;
    }

    .cp-emoji-btn:hover {
      background: rgba(255, 255, 255, 0.1);
      transform: scale(1.2);
    }

    /* GIF Picker Styling */
    .cp-gif-search-box {
      display: flex;
      align-items: center;
      gap: 6px;
      background: var(--cp-bg-input);
      border: 1px solid var(--cp-border-input);
      border-radius: 8px;
      padding: 6px 10px;
    }

    .cp-gif-search-box input {
      background: transparent;
      border: none;
      outline: none;
      color: var(--cp-text-primary);
      font-size: 12px;
      width: 100%;
    }

    .cp-gif-search-box svg {
      color: var(--cp-text-muted);
      flex-shrink: 0;
    }

    .cp-gif-tags {
      display: flex;
      gap: 6px;
      overflow-x: auto;
      padding-bottom: 4px;
      scrollbar-width: none;
    }

    .cp-gif-tags::-webkit-scrollbar {
      display: none;
    }

    .cp-gif-tag {
      flex-shrink: 0;
      background: var(--cp-bg-input);
      border: 1px solid var(--cp-border-input);
      color: var(--cp-text-secondary);
      font-size: 10.5px;
      padding: 3px 8px;
      border-radius: 999px;
      cursor: pointer;
      transition: all 0.15s;
    }

    .cp-gif-tag:hover, .cp-gif-tag.active {
      color: #ffffff;
      background: rgba(255, 255, 255, 0.12);
      border-color: var(--cp-text-secondary);
    }

    .cp-gif-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 8px;
      max-height: 180px;
      overflow-y: auto;
      padding-right: 4px;
    }

    .cp-gif-card {
      position: relative;
      border-radius: 8px;
      overflow: hidden;
      aspect-ratio: 16/9;
      background: var(--cp-bg-input);
      cursor: pointer;
      border: 1px solid var(--cp-border-input);
      transition: transform 0.15s, border-color 0.15s;
    }

    .cp-gif-card:hover {
      transform: scale(1.02);
      border-color: #3b82f6;
    }

    .cp-gif-card img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .cp-gif-label {
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      padding: 3px 6px;
      background: linear-gradient(transparent, rgba(0, 0, 0, 0.85));
      color: #ffffff;
      font-size: 9.5px;
      font-weight: 500;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    /* Message Bubble Attachments (Image, File, GIF) */
    .cp-bubble-attachment-img {
      margin-top: 6px;
      max-width: 230px;
      border-radius: 10px;
      overflow: hidden;
      cursor: pointer;
      border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .cp-bubble-attachment-img img {
      width: 100%;
      height: auto;
      display: block;
      transition: transform 0.2s;
    }

    .cp-bubble-attachment-img img:hover {
      transform: scale(1.02);
    }

    .cp-bubble-attachment-gif {
      margin-top: 6px;
      max-width: 220px;
      border-radius: 10px;
      overflow: hidden;
      border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .cp-bubble-attachment-gif img {
      width: 100%;
      height: auto;
      display: block;
    }

    .cp-bubble-attachment-file {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-top: 6px;
      padding: 8px 12px;
      background: rgba(0, 0, 0, 0.25);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 8px;
      color: var(--cp-text-primary);
      text-decoration: none;
      transition: background 0.15s;
    }

    .cp-bubble-attachment-file:hover {
      background: rgba(255, 255, 255, 0.08);
    }

    .cp-bubble-file-icon {
      font-size: 20px;
      flex-shrink: 0;
    }

    .cp-bubble-file-info {
      flex: 1;
      min-width: 0;
    }

    .cp-bubble-file-name {
      font-size: 12px;
      font-weight: 500;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      color: var(--cp-text-title);
    }

    .cp-bubble-file-meta {
      font-size: 10.5px;
      color: var(--cp-text-muted);
    }

    /* Up-Arrow Send Button */
    .cp-send-btn {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      background: var(--cp-send-btn-bg, #2b2d35);
      border: 1px solid var(--cp-send-btn-border, #363842);
      color: var(--cp-send-btn-color, #6c707d);
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: not-allowed;
      transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .cp-send-btn.active {
      background: var(--cp-send-btn-active-bg, #ffffff);
      border: 1px solid var(--cp-send-btn-active-border, #ffffff);
      color: var(--cp-send-btn-active-color, #111111);
      cursor: pointer;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    }

    .cp-send-btn.active:hover {
      transform: scale(1.05);
      background: var(--cp-send-btn-hover-bg, #f3f4f6);
    }

    .cp-send-btn.active:active {
      transform: scale(0.95);
    }

    /* Disclaimer Footer (Exact match: "By chatting with us, you agree to our Privacy Policy") */
    .cp-disclaimer {
      font-size: 11px;
      color: #7a7e8d;
      text-align: center;
      margin-top: 10px;
      line-height: 1.35;
      padding: 0 4px;
    }

    .cp-disclaimer a {
      color: inherit;
      text-decoration: underline;
      text-underline-offset: 2px;
      cursor: pointer;
    }

    .cp-disclaimer a:hover {
      color: #d1d5db;
    }

    /* Mobile Responsive Fullscreen & Native App Experience */
    @media (max-width: 640px) {
      .cp-window {
        position: fixed !important;
        bottom: 0 !important;
        right: 0 !important;
        left: 0 !important;
        top: 0 !important;
        width: 100vw !important;
        height: 100% !important;
        height: 100dvh !important;
        max-height: 100dvh !important;
        border-radius: 0 !important;
        border: none !important;
        transform: translateY(100%) !important;
        transform-origin: bottom center !important;
        transition: transform 0.32s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.25s ease !important;
        box-shadow: none !important;
      }

      .cp-window.open {
        transform: translateY(0) !important;
        opacity: 1 !important;
        pointer-events: auto !important;
      }

      .cp-header {
        padding-top: max(14px, env(safe-area-inset-top)) !important;
        padding-left: max(16px, env(safe-area-inset-left)) !important;
        padding-right: max(16px, env(safe-area-inset-right)) !important;
      }

      .cp-composer-section {
        padding-bottom: max(14px, env(safe-area-inset-bottom)) !important;
        padding-left: max(16px, env(safe-area-inset-left)) !important;
        padding-right: max(16px, env(safe-area-inset-right)) !important;
      }

      .cp-input-field,
      .cp-form-input,
      .cp-composer-textarea,
      input, textarea, select {
        font-size: 16px !important; /* Prevents auto zoom in iOS Safari */
      }

      .cp-bottom-nav {
        padding-bottom: max(10px, env(safe-area-inset-bottom)) !important;
      }

      .cp-icon-btn {
        min-width: 44px;
        min-height: 44px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
      }

      /* Mobile: Completely remove expand window button and expand menu option */
      #cp-expand-btn,
      #cp-menu-expand {
        display: none !important;
      }

      .cp-back-btn {
        min-width: 28px !important;
        min-height: 28px !important;
        width: 28px !important;
        height: 28px !important;
        padding: 0 !important;
        margin: 0 !important;
      }

      .cp-launcher {
        bottom: max(16px, env(safe-area-inset-bottom));
        right: max(16px, env(safe-area-inset-right));
      }

      .cp-launcher.widget-open {
        display: none !important;
      }

      .cp-wave-pulse {
        bottom: max(16px, env(safe-area-inset-bottom));
        right: max(16px, env(safe-area-inset-right));
      }

      .cp-teaser {
        left: 16px !important;
        right: 16px !important;
        bottom: calc(max(16px, env(safe-area-inset-bottom)) + 74px) !important;
        width: calc(100vw - 32px) !important;
        max-width: 400px !important;
        margin: 0 auto;
      }
    }

    @media (max-width: 480px) {
      #cp-expand-btn,
      #cp-menu-expand {
        display: none !important;
      }
    }

    /* ----------------------------------------------------------------- */
    /* ChatGPT-style Assistant Sonic Wave / Screen Ripple Effect        */
    /* (Modular: can be toggled on/off or removed without side-effects)  */
    /* ----------------------------------------------------------------- */
    .cp-screen-wave-container {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      width: 100vw;
      height: 100vh;
      pointer-events: none !important;
      overflow: hidden;
      z-index: 2147483640;
    }

    /* ----------------------------------------------------------------- */
    /* 6.6. Screen Ambient Sonic Bloom (Ultra-Smooth, Borderless, No Arcs)*/
    /* ----------------------------------------------------------------- */
    .cp-screen-wave-container {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      width: 100vw;
      height: 100vh;
      pointer-events: none !important;
      overflow: hidden;
      z-index: 2147483640;
    }

    .cp-wave-bloom {
      position: absolute;
      bottom: 24px;
      right: 24px;
      width: 56px;
      height: 56px;
      border-radius: 50%;
      border: none !important;
      outline: none !important;
      pointer-events: none !important;
      box-sizing: border-box;
      transform-origin: center center;
      will-change: transform, opacity;
      animation: cp-wave-bloom-expand 1.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    .cp-wave-bloom-primary {
      background: radial-gradient(circle, rgba(99, 102, 241, 0.22) 0%, rgba(56, 189, 248, 0.12) 30%, rgba(99, 102, 241, 0.04) 60%, transparent 85%);
      filter: blur(24px);
      animation-delay: 0s;
    }

    .cp-wave-bloom-ambient {
      background: radial-gradient(circle, rgba(56, 189, 248, 0.16) 0%, rgba(99, 102, 241, 0.06) 40%, transparent 75%);
      filter: blur(40px);
      animation-delay: 0.08s;
    }

    /* Light Theme Adaptation for Screen Wave (Smooth Ethereal Mist, No Arcs) */
    :host([data-theme="light"]) .cp-wave-bloom-primary,
    .cp-light-theme .cp-wave-bloom-primary,
    .theme-light .cp-wave-bloom-primary {
      background: radial-gradient(circle, rgba(99, 102, 241, 0.16) 0%, rgba(14, 165, 233, 0.08) 35%, rgba(99, 102, 241, 0.02) 65%, transparent 85%) !important;
      filter: blur(26px) !important;
      border: none !important;
    }
    :host([data-theme="light"]) .cp-wave-bloom-ambient,
    .cp-light-theme .cp-wave-bloom-ambient,
    .theme-light .cp-wave-bloom-ambient {
      background: radial-gradient(circle, rgba(14, 165, 233, 0.12) 0%, rgba(99, 102, 241, 0.04) 45%, transparent 80%) !important;
      filter: blur(44px) !important;
      border: none !important;
    }


    /* ------------------------------------------------------------- */
    /* w-up: PREMIUM CUSTOMER ACTIONS (SCREENS 2 - 8)                */
    /* Intercom Fin / Cai Design System Consistent                   */
    /* ------------------------------------------------------------- */
    .cp-screens-view {
      flex: 1;
      overflow-y: auto;
      padding: 14px 16px 12px 16px;
      display: flex;
      flex-direction: column;
      gap: 8px;
      scroll-behavior: smooth;
      animation: cpFadeSlideIn 0.22s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .cp-messages {
      animation: cpFadeSlideIn 0.22s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes cpFadeSlideIn {
      from {
        opacity: 0;
        transform: translateY(5px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .cp-screens-view::-webkit-scrollbar {
      width: 4px;
    }

    .cp-screens-view::-webkit-scrollbar-thumb {
      background: #282a35;
      border-radius: 10px;
    }

    /* Header Title Box — always visible on all screens with company identity */
    .cp-header-title-box {
      display: flex !important;
      flex-direction: column;
      justify-content: center;
      min-width: 0;
    }

    /* Team Avatar Stack — hidden to preserve tenant isolation on client widgets */
    .cp-team-avatar-stack {
      display: none !important;
      align-items: center;
      margin-right: 8px;
    }

    .cp-convo-status-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #10b981;
      display: inline-block;
      box-shadow: 0 0 6px rgba(16, 185, 129, 0.6);
      flex-shrink: 0;
    }

    .cp-active-convo-card {
      border-color: rgba(16, 185, 129, 0.25) !important;
    }

    .cp-stack-avatar-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      text-decoration: none;
      position: relative;
      margin-left: -7px;
      transition: transform 0.18s cubic-bezier(0.16, 1, 0.3, 1), z-index 0.18s;
      cursor: pointer;
    }

    .cp-stack-avatar-link:first-child {
      margin-left: 0;
      z-index: 3;
    }
    .cp-stack-avatar-link:nth-child(2) {
      z-index: 2;
    }
    .cp-stack-avatar-link:nth-child(3) {
      z-index: 1;
    }

    .cp-stack-avatar-link:hover {
      transform: scale(1.18) translateY(-1px);
      z-index: 10 !important;
    }

    .cp-stack-avatar {
      width: 29px;
      height: 29px;
      border-radius: 50%;
      object-fit: cover;
      border: 2px solid var(--cp-bg-surface, #15161b);
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.45);
      background: #1e2028;
      display: block;
    }

    :host([data-theme="light"]) .cp-stack-avatar,
    .cp-light-theme .cp-stack-avatar,
    .theme-light .cp-stack-avatar {
      border-color: #ffffff;
      box-shadow: 0 1px 4px rgba(0, 0, 0, 0.15);
      background: #f1f5f9;
    }

    /* Action Home Hero Typography (Clean Classic Intercom Look) */
    .cp-hero-section {
      padding: 6px 4px 10px 4px;
      user-select: none;
    }

    .cp-hero-greeting-sub {
      font-size: 24px;
      font-weight: 500;
      color: rgba(255, 255, 255, 0.7);
      letter-spacing: -0.02em;
      line-height: 1.15;
    }

    .cp-hero-greeting-main {
      font-size: 26px;
      font-weight: 650;
      color: #ffffff;
      letter-spacing: -0.02em;
      line-height: 1.18;
      margin-top: 1px;
    }

    :host([data-theme="light"]) .cp-hero-greeting-sub,
    .cp-light-theme .cp-hero-greeting-sub,
    .theme-light .cp-hero-greeting-sub {
      color: #64748b;
    }

    :host([data-theme="light"]) .cp-hero-greeting-main,
    .cp-light-theme .cp-hero-greeting-main,
    .theme-light .cp-hero-greeting-main {
      color: #0f172a;
    }

    /* Member LinkedIn Icon Button in Team Directory */
    .cp-btn-linkedin {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 27px;
      height: 27px;
      padding: 0;
      border-radius: 6px;
      color: #0A66C2;
      background: rgba(10, 102, 194, 0.12);
      border: 1px solid rgba(10, 102, 194, 0.25);
      text-decoration: none;
      transition: all 0.18s ease;
      cursor: pointer;
    }

    .cp-btn-linkedin:hover {
      background: #0A66C2;
      color: #ffffff;
      border-color: #0A66C2;
      transform: translateY(-1px);
    }

    /* Card 1: Ask a question (Top hero card in media_1790884814897.png) */
    .cp-ask-action-card {
      background: #181920;
      border: 1px solid #282932;
      border-radius: 14px;
      padding: 13px 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      cursor: pointer;
      transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
      box-shadow: 0 3px 10px rgba(0, 0, 0, 0.25);
      user-select: none;
      touch-action: manipulation;
      -webkit-tap-highlight-color: transparent;
    }

    .cp-ask-action-card:hover {
      background: #20222a;
      border-color: #3b3e4e;
      transform: translateY(-1px);
      box-shadow: 0 6px 20px rgba(0, 0, 0, 0.35);
    }

    :host([data-theme="light"]) .cp-ask-action-card,
    .cp-light-theme .cp-ask-action-card,
    .theme-light .cp-ask-action-card {
      background: #ffffff;
      border-color: #e2e8f0;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    }

    :host([data-theme="light"]) .cp-ask-action-card:hover,
    .cp-light-theme .cp-ask-action-card:hover,
    .theme-light .cp-ask-action-card:hover {
      background: #f8fafc;
      border-color: #cbd5e1;
      box-shadow: 0 6px 18px rgba(0, 0, 0, 0.08);
    }

    .cp-ask-card-title {
      font-size: 14.5px;
      font-weight: 650;
      color: #ffffff;
      line-height: 1.25;
      letter-spacing: -0.01em;
    }

    .cp-ask-card-desc {
      font-size: 12px;
      color: #8e929f;
      margin-top: 3px;
      line-height: 1.3;
    }

    :host([data-theme="light"]) .cp-ask-card-title,
    .cp-light-theme .cp-ask-card-title,
    .theme-light .cp-ask-card-title {
      color: #0f172a;
    }

    :host([data-theme="light"]) .cp-ask-card-desc,
    .cp-light-theme .cp-ask-card-desc,
    .theme-light .cp-ask-card-desc {
      color: #64748b;
    }

    .cp-ask-card-arrow {
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      transition: transform 0.2s ease;
    }

    :host([data-theme="light"]) .cp-ask-card-arrow,
    .cp-light-theme .cp-ask-card-arrow,
    .theme-light .cp-ask-card-arrow {
      color: #0f172a;
    }

    .cp-ask-action-card:hover .cp-ask-card-arrow {
      transform: translateX(3px);
    }

    /* Card 2: Featured Story / Announcement Banner Card (India Edition, Clear Image) */
    /* Card 2 / Bottom: Lightweight Featured Story Card (media_1790922633144.png / media_1790923294302.png) */
    .cp-featured-banner-card {
      background: #ffffff;
      border: 1px solid #e7e5de;
      border-radius: 16px;
      padding: 12px 12px 14px 12px;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      cursor: pointer;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
      transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
      user-select: none;
      touch-action: manipulation;
      -webkit-tap-highlight-color: transparent;
      flex-shrink: 0;
    }

    .cp-featured-banner-card:hover {
      border-color: #cbd5e1;
      transform: translateY(-1px);
      box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
    }

    :host([data-theme="dark"]) .cp-featured-banner-card,
    .cp-dark-theme .cp-featured-banner-card,
    .theme-dark .cp-featured-banner-card {
      background: #181920;
      border-color: #282932;
      box-shadow: 0 3px 12px rgba(0, 0, 0, 0.25);
    }

    :host([data-theme="dark"]) .cp-featured-banner-card:hover,
    .cp-dark-theme .cp-featured-banner-card:hover,
    .theme-dark .cp-featured-banner-card:hover {
      border-color: #3b3e4e;
      box-shadow: 0 6px 20px rgba(0, 0, 0, 0.35);
    }

    .cp-banner-img-box {
      width: 100%;
      height: 112px;
      position: relative;
      overflow: hidden;
      border-radius: 10px;
      background: #fbfaf8;
      display: block;
      margin-bottom: 10px;
      flex-shrink: 0;
    }

    .cp-banner-cover-photo {
      width: 100%;
      height: 100%;
      object-fit: cover;
      object-position: center;
      display: block;
      transition: transform 0.3s ease;
    }

    .cp-featured-banner-card:hover .cp-banner-cover-photo {
      transform: scale(1.02);
    }

    .cp-banner-body {
      padding: 0 2px;
      display: flex;
      flex-direction: column;
    }

    .cp-banner-meta-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 6px;
    }

    .cp-banner-badge-tag {
      background: #d1fae5;
      color: #065f46;
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      padding: 2.5px 8px;
      border-radius: 9999px;
      display: inline-block;
    }

    :host([data-theme="dark"]) .cp-banner-badge-tag,
    .cp-dark-theme .cp-banner-badge-tag,
    .theme-dark .cp-banner-badge-tag {
      background: rgba(16, 185, 129, 0.18);
      color: #34d399;
      border: 1px solid rgba(16, 185, 129, 0.3);
    }

    .cp-banner-date-label {
      font-size: 11px;
      color: #94a3b8;
      font-weight: 500;
    }

    .cp-banner-headline {
      font-size: 13.5px;
      font-weight: 700;
      color: #0f172a;
      line-height: 1.35;
      letter-spacing: -0.01em;
      margin: 0;
    }

    :host([data-theme="dark"]) .cp-banner-headline,
    .cp-dark-theme .cp-banner-headline,
    .theme-dark .cp-banner-headline {
      color: #ffffff;
    }

    .cp-banner-subline {
      font-size: 11.5px;
      color: #64748b;
      line-height: 1.45;
      margin-top: 5px;
    }

    :host([data-theme="dark"]) .cp-banner-subline,
    .cp-dark-theme .cp-banner-subline,
    .theme-dark .cp-banner-subline {
      color: #94a3b8;
    }

    /* Sub-screen Header (e.g. Make a payment, media_1790861393761.png) */
    .cp-sub-screen-header {
      padding: 4px 2px 6px 2px;
      user-select: none;
    }

    .cp-sub-screen-title {
      font-size: 16.5px;
      font-weight: 700;
      color: var(--cp-text-title, #ffffff);
      line-height: 1.25;
      letter-spacing: -0.015em;
    }

    .cp-sub-screen-desc {
      font-size: 12px;
      color: var(--cp-text-secondary, #8e929f);
      margin-top: 2px;
    }

    /* Screen 2: Action Home Cards (Comfortably balanced vertical fill) */
    .cp-action-card {
      background: var(--cp-options-bg, #181920);
      border: 1px solid var(--cp-border-input, #282931);
      border-radius: 12px;
      padding: 10.5px 13px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 11px;
      cursor: pointer;
      transition: all 0.18s cubic-bezier(0.16, 1, 0.3, 1);
      text-decoration: none;
      position: relative;
      user-select: none;
      touch-action: manipulation;
      -webkit-tap-highlight-color: transparent;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
    }

    .cp-action-card:hover {
      background: var(--cp-options-hover, #232530);
      border-color: var(--cp-border-bubble, #2d2f38);
      transform: translateY(-1px);
      box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
    }

    .cp-action-card:active {
      transform: translateY(0);
    }

    .cp-action-card-left {
      display: flex;
      align-items: center;
      gap: 11px;
      flex: 1;
      min-width: 0;
    }

    .cp-action-icon-box {
      width: 35px;
      height: 35px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.08);
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      color: var(--cp-text-primary, #ffffff);
    }

    .cp-action-icon-box svg {
      width: 17px;
      height: 17px;
    }

    .cp-action-avatar-wrap {
      width: 35px;
      height: 35px;
      border-radius: 50%;
      position: relative;
      flex-shrink: 0;
      background: var(--cp-bg-surface, #15161b);
      border: 1px solid var(--cp-border-input, #282931);
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: visible;
    }

    .cp-action-avatar-img {
      width: 100%;
      height: 100%;
      border-radius: 50%;
      object-fit: contain;
      display: block;
      padding: 3px;
    }

    .cp-action-status-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #10B981;
      border: 1.5px solid var(--cp-bg-surface, #15161b);
      position: absolute;
      bottom: -0.5px;
      right: -0.5px;
    }

    .cp-action-status-dot.busy { background: #F59E0B; }
    .cp-action-status-dot.offline { background: #6B7280; }

    .cp-action-text-box {
      flex: 1;
      min-width: 0;
    }

    .cp-action-card-title {
      font-size: 13px;
      font-weight: 600;
      color: var(--cp-text-title, #ffffff);
      line-height: 1.25;
      letter-spacing: -0.01em;
      display: flex;
      align-items: center;
    }

    .cp-pro-badge {
      display: inline-flex;
      align-items: center;
      padding: 1.5px 5px;
      border-radius: 4px;
      font-size: 9px;
      font-weight: 700;
      letter-spacing: 0.04em;
      background: rgba(245, 158, 11, 0.18);
      color: #f59e0b;
      border: 1px solid rgba(245, 158, 11, 0.35);
      margin-left: 6px;
      line-height: 1;
    }

    /* Intercom-Style Workspace Copilot Action Card & Quick Chips */
    .cp-action-card-copilot {
      margin-bottom: 2px;
      border: 1px solid var(--cp-border-input, #e2e8f0);
      background: var(--cp-options-bg, #ffffff);
    }
    .cp-action-card-copilot:hover {
      background: var(--cp-options-hover, #f8fafc);
      border-color: var(--cp-border-bubble, #cbd5e1);
      transform: translateY(-1px);
    }
    .cp-action-icon-copilot {
      background: #0f172a !important;
      color: #ffffff !important;
      border: 1px solid rgba(255, 255, 255, 0.1) !important;
      box-shadow: 0 1px 2px rgba(0, 0, 0, 0.12);
    }
    .cp-copilot-tag {
      display: inline-flex;
      align-items: center;
      padding: 1.5px 5.5px;
      border-radius: 4px;
      font-size: 9px;
      font-weight: 600;
      letter-spacing: 0.03em;
      background: rgba(15, 23, 42, 0.06);
      color: var(--cp-text-secondary, #64748b);
      border: 1px solid rgba(15, 23, 42, 0.1);
      margin-left: 6px;
      line-height: 1;
    }
    :host([data-theme="dark"]) .cp-action-icon-copilot,
    .cp-window:not(.cp-light-theme) .cp-action-icon-copilot {
      background: #1e293b !important;
      color: #f8fafc !important;
      border: 1px solid rgba(255, 255, 255, 0.14) !important;
    }
    :host([data-theme="dark"]) .cp-copilot-tag,
    .cp-window:not(.cp-light-theme) .cp-copilot-tag {
      background: rgba(255, 255, 255, 0.08);
      color: #cbd5e1;
      border: 1px solid rgba(255, 255, 255, 0.12);
    }
    .cp-copilot-chips-wrap {
      margin: 10px 0 14px 0;
      padding: 0 2px;
      animation: cpFadeIn 0.25s ease-out;
    }
    .cp-copilot-chips-label {
      font-size: 11px;
      font-weight: 600;
      color: var(--cp-text-muted, #94a3b8);
      margin-bottom: 8px;
      letter-spacing: 0.01em;
      display: flex;
      align-items: center;
      gap: 5px;
    }
    .cp-copilot-chips {
      display: flex;
      flex-wrap: wrap;
      gap: 7px;
    }
    .cp-copilot-chip {
      background: var(--cp-options-bg, #ffffff);
      border: 1px solid var(--cp-border-input, #e2e8f0);
      border-radius: 20px;
      padding: 4px 10px 4px 4px;
      font-size: 11.5px;
      font-weight: 500;
      color: var(--cp-text-primary, #1e293b);
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6.5px;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
      transition: all 0.16s cubic-bezier(0.16, 1, 0.3, 1);
      touch-action: manipulation;
      user-select: none;
      -webkit-tap-highlight-color: transparent;
    }
    .cp-copilot-chip:hover {
      background: var(--cp-options-hover, #f8fafc);
      border-color: var(--cp-border-bubble, #cbd5e1);
      transform: translateY(-1.5px);
      box-shadow: 0 4px 10px rgba(0, 0, 0, 0.06);
    }
    .cp-copilot-chip:hover .cp-chip-arrow {
      opacity: 0.85;
      transform: translateX(2px);
    }
    .cp-copilot-chip:active {
      transform: translateY(0);
    }
    .cp-copilot-chip-icon {
      width: 22px;
      height: 22px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    .cp-copilot-chip-icon svg {
      width: 12px;
      height: 12px;
    }
    .cp-chip-blue {
      background: rgba(14, 165, 233, 0.12);
      color: #0284c7;
    }
    .cp-chip-purple {
      background: rgba(139, 92, 246, 0.12);
      color: #7c3aed;
    }
    .cp-chip-emerald {
      background: rgba(16, 185, 129, 0.12);
      color: #059669;
    }
    .cp-chip-amber {
      background: rgba(245, 158, 11, 0.12);
      color: #d97706;
    }
    .cp-chip-rose {
      background: rgba(244, 63, 94, 0.12);
      color: #e11d48;
    }
    .cp-chip-arrow {
      width: 9px;
      height: 9px;
      color: var(--cp-text-muted, #94a3b8);
      opacity: 0.35;
      transition: all 0.15s ease;
      margin-left: 1px;
    }
    :host([data-theme="dark"]) .cp-copilot-chip,
    .cp-window:not(.cp-light-theme) .cp-copilot-chip {
      background: #181920;
      border: 1px solid rgba(255, 255, 255, 0.09);
      color: #f1f5f9;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.35);
    }
    :host([data-theme="dark"]) .cp-copilot-chip:hover,
    .cp-window:not(.cp-light-theme) .cp-copilot-chip:hover {
      background: #232530;
      border-color: rgba(255, 255, 255, 0.18);
    }
    :host([data-theme="dark"]) .cp-chip-blue,
    .cp-window:not(.cp-light-theme) .cp-chip-blue {
      background: rgba(56, 189, 248, 0.18);
      color: #38bdf8;
    }
    :host([data-theme="dark"]) .cp-chip-purple,
    .cp-window:not(.cp-light-theme) .cp-chip-purple {
      background: rgba(167, 139, 250, 0.18);
      color: #a78bfa;
    }
    :host([data-theme="dark"]) .cp-chip-emerald,
    .cp-window:not(.cp-light-theme) .cp-chip-emerald {
      background: rgba(52, 211, 153, 0.18);
      color: #34d399;
    }
    :host([data-theme="dark"]) .cp-chip-amber,
    .cp-window:not(.cp-light-theme) .cp-chip-amber {
      background: rgba(251, 191, 36, 0.18);
      color: #fbbf24;
    }
    :host([data-theme="dark"]) .cp-chip-rose,
    .cp-window:not(.cp-light-theme) .cp-chip-rose {
      background: rgba(251, 113, 133, 0.18);
      color: #fb7185;
    }

    .cp-action-card-desc {
      font-size: 11px;
      color: var(--cp-text-secondary, #8e929f);
      margin-top: 2px;
      line-height: 1.3;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .cp-action-card-right {
      flex-shrink: 0;
      color: var(--cp-text-muted, #737885);
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .cp-action-send-arrow {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      background: #0f172a;
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: transform 0.15s ease;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
    }

    :host([data-theme="dark"]) .cp-action-send-arrow,
    .cp-window:not(.cp-light-theme) .cp-action-send-arrow {
      background: #ffffff;
      color: #111111;
    }

    :host([data-theme="light"]) .cp-action-send-arrow,
    .cp-window.cp-light-theme .cp-action-send-arrow,
    .cp-window.theme-light .cp-action-send-arrow {
      background: #0f172a;
      color: #ffffff;
    }

    .cp-action-card:hover .cp-action-send-arrow {
      transform: scale(1.06);
    }

    /* Screen 3 & 5: Department Filter Pills (Intercom Luxury Style) */
    .cp-filter-pills {
      display: flex;
      align-items: center;
      gap: 7px;
      overflow-x: auto;
      padding: 3px 2px 7px 2px;
      margin-bottom: 5px;
      scrollbar-width: none;
      -webkit-overflow-scrolling: touch;
      flex-shrink: 0;
    }

    .cp-filter-pills::-webkit-scrollbar {
      display: none;
    }

    .cp-pill {
      font-size: 11.5px;
      font-weight: 500;
      padding: 5.5px 12px;
      border-radius: 9999px;
      background: var(--cp-bg-input, #1a1b20);
      border: 1px solid var(--cp-border-input, #282931);
      color: var(--cp-text-secondary, #8e929f);
      white-space: nowrap;
      cursor: pointer;
      transition: all 0.15s cubic-bezier(0.16, 1, 0.3, 1);
      user-select: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .cp-pill:hover {
      color: var(--cp-text-title, #ffffff);
      border-color: var(--cp-border-bubble, #2d2f38);
    }

    /* Dark Theme Active Pill */
    .cp-pill.active {
      background: #ffffff !important;
      border-color: #ffffff !important;
      color: #0f172a !important;
      font-weight: 700 !important;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25) !important;
    }

    /* Light Theme Pill Overrides */
    :host([data-theme="light"]) .cp-pill,
    .cp-light-theme .cp-pill,
    .theme-light .cp-pill {
      background: #f1f5f9;
      border: 1px solid #e2e8f0;
      color: #475569;
    }

    :host([data-theme="light"]) .cp-pill:hover:not(.active),
    .cp-light-theme .cp-pill:hover:not(.active),
    .theme-light .cp-pill:hover:not(.active) {
      background: #e2e8f0;
      border-color: #cbd5e1;
      color: #0f172a;
    }

    :host([data-theme="light"]) .cp-pill.active,
    .cp-light-theme .cp-pill.active,
    .theme-light .cp-pill.active {
      background: #0f172a !important;
      border-color: #0f172a !important;
      color: #ffffff !important;
      font-weight: 700 !important;
      box-shadow: 0 2px 8px rgba(15, 23, 42, 0.18) !important;
    }

    /* Team Member Card */
    .cp-member-card {
      background: var(--cp-options-bg, #181920);
      border: 1px solid var(--cp-border-input, #282931);
      border-radius: 12px;
      padding: 12px 13px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      transition: all 0.18s ease;
    }

    .cp-member-card:hover {
      background: var(--cp-options-hover, #232530);
      border-color: var(--cp-border-bubble, #2d2f38);
    }

    .cp-member-left {
      display: flex;
      align-items: center;
      gap: 11px;
      flex: 1;
      min-width: 0;
    }

    .cp-member-name-row {
      display: flex;
      align-items: center;
      gap: 6px;
      flex-wrap: wrap;
    }

    .cp-member-name {
      font-size: 13.5px;
      font-weight: 600;
      color: var(--cp-text-title, #ffffff);
      line-height: 1.25;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 145px;
    }

    :host([data-theme="light"]) .cp-member-name,
    .cp-light-theme .cp-member-name,
    .theme-light .cp-member-name {
      color: #0f172a;
    }

    .cp-member-role {
      font-size: 11px;
      color: var(--cp-text-secondary, #8e929f);
      margin-top: 2px;
      line-height: 1.3;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 160px;
    }

    .cp-dept-tag {
      font-size: 8.5px;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      padding: 1.5px 5px;
      border-radius: 3px;
      background: rgba(255, 255, 255, 0.08);
      color: #94a3b8;
      line-height: 1.2;
      display: inline-block;
      vertical-align: middle;
    }

    :host([data-theme="light"]) .cp-dept-tag,
    .cp-light-theme .cp-dept-tag,
    .theme-light .cp-dept-tag {
      background: #f1f5f9;
      color: #475569;
      border: 1px solid #e2e8f0;
    }

    .cp-member-status-text {
      font-size: 10.5px;
      display: flex;
      align-items: center;
      gap: 4px;
      margin-top: 3px;
      font-weight: 500;
    }

    .cp-member-status-text.available { color: #10B981; }
    .cp-member-status-text.busy { color: #F59E0B; }
    .cp-member-status-text.offline { color: #94a3b8; }

    .cp-member-actions {
      display: flex;
      align-items: center;
      gap: 6px;
      flex-shrink: 0;
    }

    .cp-btn-sm {
      padding: 5px 10px;
      font-size: 11.5px;
      font-weight: 600;
      border-radius: 6px;
      border: 1px solid var(--cp-border-input, #282931);
      background: var(--cp-bg-input, #1a1b20);
      color: var(--cp-text-primary, #ffffff);
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      transition: all 0.15s ease;
      user-select: none;
    }

    .cp-btn-sm:hover {
      background: rgba(255, 255, 255, 0.1);
      border-color: rgba(255, 255, 255, 0.2);
    }

    .cp-btn-sm.primary {
      background: #ffffff;
      color: #111111;
      border-color: #ffffff;
    }

    .cp-btn-sm.primary:hover {
      background: #f1f5f9;
      transform: translateY(-1px);
    }

    /* Screen 6: Appointment Slot Booking */
    .cp-agent-hero-card {
      background: var(--cp-options-bg, #181920);
      border: 1px solid var(--cp-border-input, #282931);
      border-radius: 12px;
      padding: 12px 14px;
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 4px;
    }

    .cp-section-title {
      font-size: 11px;
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: 0.03em;
      color: var(--cp-text-muted, #737885);
      margin: 8px 0 6px 2px;
    }

    .cp-date-chips {
      display: flex;
      gap: 8px;
      overflow-x: auto;
      padding: 4px 2px 8px 2px;
      scrollbar-width: none;
      flex-shrink: 0;
      min-height: 56px;
    }

    .cp-date-chips::-webkit-scrollbar {
      display: none;
    }

    .cp-date-chip {
      background: var(--cp-bg-input, #1a1b20);
      border: 1px solid var(--cp-border-input, #282931);
      border-radius: 10px;
      padding: 7px 12px;
      min-width: 68px;
      text-align: center;
      cursor: pointer;
      transition: all 0.15s ease;
      flex-shrink: 0;
      user-select: none;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
    }

    .cp-date-chip:hover {
      border-color: var(--cp-border-bubble, #2d2f38);
    }

    /* Selected Date Chip - Dark Theme */
    :host([data-theme="dark"]) .cp-date-chip.selected,
    .cp-window:not(.cp-light-theme) .cp-date-chip.selected {
      background: #ffffff;
      border-color: #ffffff;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
    }
    :host([data-theme="dark"]) .cp-date-chip.selected .cp-date-chip-day,
    .cp-window:not(.cp-light-theme) .cp-date-chip.selected .cp-date-chip-day {
      color: #64748b;
    }
    :host([data-theme="dark"]) .cp-date-chip.selected .cp-date-chip-label,
    .cp-window:not(.cp-light-theme) .cp-date-chip.selected .cp-date-chip-label {
      color: #0f172a;
    }

    /* Selected Date Chip - Light Theme */
    :host([data-theme="light"]) .cp-date-chip.selected,
    .cp-window.cp-light-theme .cp-date-chip.selected,
    .cp-window.theme-light .cp-date-chip.selected {
      background: #0f172a !important;
      border-color: #0f172a !important;
      box-shadow: 0 2px 8px rgba(15, 23, 42, 0.18);
    }
    :host([data-theme="light"]) .cp-date-chip.selected .cp-date-chip-day,
    .cp-window.cp-light-theme .cp-date-chip.selected .cp-date-chip-day {
      color: #94a3b8 !important;
    }
    :host([data-theme="light"]) .cp-date-chip.selected .cp-date-chip-label,
    .cp-window.cp-light-theme .cp-date-chip.selected .cp-date-chip-label {
      color: #ffffff !important;
    }

    .cp-date-chip-day {
      font-size: 10px;
      color: var(--cp-text-secondary, #8e929f);
      text-transform: uppercase;
      font-weight: 500;
      letter-spacing: 0.02em;
    }

    .cp-date-chip-label {
      font-size: 12px;
      font-weight: 500;
      color: var(--cp-text-title, #ffffff);
      margin-top: 2px;
    }

    .cp-slots-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 6px;
      margin-bottom: 10px;
      flex-shrink: 0;
    }

    .cp-slot-pill {
      background: var(--cp-bg-input, #1a1b20);
      border: 1px solid var(--cp-border-input, #282931);
      border-radius: 8px;
      padding: 7px 4px;
      font-size: 11.5px;
      font-weight: 500;
      text-align: center;
      color: var(--cp-text-primary, #ffffff);
      cursor: pointer;
      transition: all 0.15s ease;
      user-select: none;
    }

    .cp-slot-pill:hover:not(.disabled) {
      background: rgba(255, 255, 255, 0.08);
      border-color: rgba(255, 255, 255, 0.2);
    }

    /* Selected Slot Pill - Dark Theme */
    :host([data-theme="dark"]) .cp-slot-pill.selected,
    .cp-window:not(.cp-light-theme) .cp-slot-pill.selected {
      background: #ffffff;
      color: #0f172a;
      border-color: #ffffff;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25);
    }

    /* Selected Slot Pill - Light Theme */
    :host([data-theme="light"]) .cp-slot-pill.selected,
    .cp-window.cp-light-theme .cp-slot-pill.selected,
    .cp-window.theme-light .cp-slot-pill.selected {
      background: #0f172a;
      color: #ffffff;
      border-color: #0f172a;
      box-shadow: 0 2px 6px rgba(15, 23, 42, 0.18);
    }

    .cp-slot-pill.disabled {
      opacity: 0.35;
      cursor: not-allowed;
      text-decoration: line-through;
    }

    .cp-sticky-booking-footer {
      position: sticky;
      bottom: -10px;
      margin-top: 10px;
      margin-left: -14px;
      margin-right: -14px;
      margin-bottom: -10px;
      padding: 10px 14px 12px 14px;
      background: var(--cp-bg-surface, #121316);
      border-top: 1px solid var(--cp-border-input, #282931);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      z-index: 30;
      flex-shrink: 0;
    }

    :host([data-theme="light"]) .cp-sticky-booking-footer,
    .cp-window.cp-light-theme .cp-sticky-booking-footer,
    .cp-window.theme-light .cp-sticky-booking-footer {
      background: rgba(255, 255, 255, 0.95);
      border-top: 1px solid #e2e8f0;
      box-shadow: 0 -4px 16px rgba(0, 0, 0, 0.05);
    }

    .cp-form-group {
      display: flex;
      flex-direction: column;
      gap: 4px;
      margin-bottom: 8px;
    }

    .cp-form-label {
      font-size: 11px;
      font-weight: 600;
      color: var(--cp-text-secondary, #8e929f);
      margin-left: 2px;
    }

    .cp-form-input {
      background: var(--cp-bg-input, #1a1b20);
      border: 1px solid var(--cp-border-input, #282931);
      border-radius: 8px;
      padding: 8px 11px;
      font-size: 12.5px;
      color: var(--cp-text-title, #ffffff);
      outline: none;
      transition: border-color 0.15s ease;
      width: 100%;
      box-sizing: border-box;
    }

    .cp-form-input:focus {
      border-color: rgba(255, 255, 255, 0.35);
    }

    .cp-form-input::placeholder {
      color: var(--cp-input-placeholder, #6b707e);
    }

    .cp-submit-btn {
      width: 100%;
      padding: 10px;
      font-size: 13px;
      font-weight: 500;
      background: #ffffff;
      color: #0f172a;
      border: 1px solid #ffffff;
      border-radius: 8px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      margin-top: 8px;
      transition: all 0.18s ease;
      user-select: none;
    }

    .cp-submit-btn:hover {
      background: #f1f5f9;
      transform: translateY(-1px);
    }

    .cp-submit-btn:active {
      transform: translateY(0);
    }

    /* Primary Submit Button - Light Theme: Solid Black/Charcoal with White Text */
    :host([data-theme="light"]) .cp-submit-btn,
    .cp-window.cp-light-theme .cp-submit-btn,
    .cp-window.theme-light .cp-submit-btn {
      background: #0f172a !important;
      border-color: #0f172a !important;
      color: #ffffff !important;
    }
    :host([data-theme="light"]) .cp-submit-btn:hover,
    .cp-window.cp-light-theme .cp-submit-btn:hover,
    .cp-window.theme-light .cp-submit-btn:hover {
      background: #1e293b !important;
      border-color: #1e293b !important;
    }

    /* Primary Submit Button - Dark Theme: Solid White with Dark Text */
    :host([data-theme="dark"]) .cp-submit-btn,
    .cp-window:not(.cp-light-theme) .cp-submit-btn {
      background: #ffffff !important;
      border-color: #ffffff !important;
      color: #0f172a !important;
    }
    :host([data-theme="dark"]) .cp-submit-btn:hover,
    .cp-window:not(.cp-light-theme) .cp-submit-btn:hover {
      background: #f1f5f9 !important;
      border-color: #f1f5f9 !important;
    }

    /* Screen 7: Booking Confirmed (media_1790861406544.png) */
    .cp-confirmed-box {
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      padding: 14px 4px 16px 4px;
      margin: auto 0;
      width: 100%;
    }

    .cp-confirmed-badge-wrap {
      position: relative;
      width: 58px;
      height: 58px;
      margin-bottom: 14px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .cp-confirmed-check-badge {
      position: absolute;
      bottom: -2px;
      right: -2px;
      width: 22px;
      height: 22px;
      border-radius: 50%;
      background: #10B981;
      border: 2px solid var(--cp-bg-surface, #15161b);
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .cp-confirmed-title {
      font-size: 18.5px;
      font-weight: 700;
      color: var(--cp-text-title, #ffffff);
      line-height: 1.25;
      letter-spacing: -0.015em;
    }

    .cp-confirmed-desc {
      font-size: 13px;
      color: var(--cp-text-secondary, #8e929f);
      line-height: 1.4;
      margin-top: 4px;
    }

    .cp-confirmed-summary-card {
      width: 100%;
      background: var(--cp-options-bg, #181920);
      border: 1px solid var(--cp-border-input, #282931);
      border-radius: 14px;
      padding: 14px 16px;
      margin: 16px 0 16px 0;
      text-align: left;
      display: flex;
      flex-direction: column;
      gap: 12px;
      box-sizing: border-box;
    }

    .cp-confirmed-card-row {
      display: flex;
      align-items: flex-start;
      gap: 12px;
      padding-bottom: 11px;
      border-bottom: 1px solid var(--cp-border-input, #282931);
    }

    .cp-confirmed-card-row:last-child {
      padding-bottom: 0;
      border-bottom: none;
    }

    .cp-confirmed-row-label {
      width: 60px;
      display: flex;
      align-items: center;
      gap: 7px;
      font-size: 12.5px;
      color: var(--cp-text-secondary, #8e929f);
      font-weight: 500;
      flex-shrink: 0;
    }

    .cp-confirmed-row-val {
      flex: 1;
      font-size: 13px;
      color: var(--cp-text-title, #ffffff);
      line-height: 1.35;
    }

    .cp-add-calendar-btn {
      width: 100%;
      padding: 11px 16px;
      border-radius: 10px;
      border: 1.5px solid var(--cp-border-input, #282931);
      background: var(--cp-bg-input, #1a1b20);
      color: var(--cp-text-title, #ffffff);
      font-size: 13px;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      cursor: pointer;
      text-decoration: none;
      transition: all 0.15s ease;
      user-select: none;
      box-sizing: border-box;
    }

    .cp-add-calendar-btn:hover {
      background: var(--cp-options-hover, #232530);
      border-color: var(--cp-border-bubble, #2d2f38);
    }

    :host([data-theme="light"]) .cp-add-calendar-btn,
    .cp-window.cp-light-theme .cp-add-calendar-btn,
    .cp-window.theme-light .cp-add-calendar-btn {
      background: #ffffff;
      border-color: #cbd5e1;
      color: #0f172a;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }

    :host([data-theme="light"]) .cp-add-calendar-btn:hover,
    .cp-window.cp-light-theme .cp-add-calendar-btn:hover,
    .cp-window.theme-light .cp-add-calendar-btn:hover {
      background: #f8fafc;
      border-color: #94a3b8;
    }

    /* Screen 8: Payments */
    .cp-bank-card {
      background: var(--cp-options-bg, #181920);
      border: 1px solid var(--cp-border-input, #282931);
      border-radius: 12px;
      padding: 14px;
      margin-bottom: 8px;
    }

    .cp-bank-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 7px 0;
      border-bottom: 1px solid var(--cp-border-input, rgba(255, 255, 255, 0.08));
      font-size: 12px;
    }

    .cp-bank-row:last-child {
      border-bottom: none;
      padding-bottom: 0;
    }

    .cp-bank-label {
      color: var(--cp-text-muted, #737885);
      font-weight: 500;
    }

    .cp-bank-val-box {
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .cp-bank-val {
      font-weight: 600;
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
      color: var(--cp-text-title, #ffffff);
    }

    .cp-copy-btn {
      background: transparent;
      border: none;
      color: var(--cp-text-secondary, #8e929f);
      cursor: pointer;
      padding: 2px;
      display: flex;
      align-items: center;
      transition: color 0.15s;
    }

    .cp-copy-btn:hover {
      color: var(--cp-text-title, #ffffff);
    }

    .cp-amount-chips {
      display: flex;
      gap: 6px;
      margin-bottom: 8px;
    }

    .cp-amount-chip {
      flex: 1;
      padding: 6px 4px;
      text-align: center;
      font-size: 11.5px;
      font-weight: 600;
      border-radius: 7px;
      background: var(--cp-bg-input, #1a1b20);
      border: 1px solid var(--cp-border-input, #282931);
      color: var(--cp-text-primary, #ffffff);
      cursor: pointer;
      transition: all 0.15s ease;
      user-select: none;
    }

    .cp-amount-chip:hover {
      border-color: rgba(255, 255, 255, 0.2);
    }

    .cp-amount-chip.active {
      background: rgba(255, 255, 255, 0.12);
      border-color: #ffffff;
    }

    /* Amount Chips - Light Theme */
    :host([data-theme="light"]) .cp-amount-chip,
    .cp-window.cp-light-theme .cp-amount-chip,
    .cp-window.theme-light .cp-amount-chip {
      background: #f8fafc;
      border: 1px solid #cbd5e1;
      color: #0f172a;
    }
    :host([data-theme="light"]) .cp-amount-chip.active,
    .cp-window.cp-light-theme .cp-amount-chip.active,
    .cp-window.theme-light .cp-amount-chip.active {
      background: #0f172a !important;
      border-color: #0f172a !important;
      color: #ffffff !important;
    }

    /* Amount Chips - Dark Theme */
    :host([data-theme="dark"]) .cp-amount-chip.active,
    .cp-window:not(.cp-light-theme) .cp-amount-chip.active {
      background: #ffffff !important;
      border-color: #ffffff !important;
      color: #0f172a !important;
    }
    @keyframes cp-wave-bloom-expand {
      0% {
        transform: scale(0.6);
        opacity: 0;
      }
      18% {
        opacity: 0.9;
      }
      50% {
        opacity: 0.35;
      }
      100% {
        transform: scale(60);
        opacity: 0;
      }
    }

    /* ------------------------------------------------------------- */
    /* Bottom Navigation Bar & Ask Question Pill (media_1790868630655.png) */
    /* ------------------------------------------------------------- */
    .cp-bottom-nav {
      display: flex;
      align-items: center;
      justify-content: space-around;
      padding: 6px 6px 8px 6px;
      background-color: var(--cp-bg-surface, #14151c);
      border-top: 1px solid var(--cp-toolbar-border, rgba(255, 255, 255, 0.08));
      flex-shrink: 0;
      user-select: none;
      z-index: 40;
    }

    :host([data-theme="light"]) .cp-bottom-nav,
    .cp-light-theme .cp-bottom-nav,
    .theme-light .cp-bottom-nav {
      background-color: #ffffff;
      border-top: 1px solid #e5e7eb;
    }

    /* Hide Bottom Navigation inside active chat screens (controlled via top back button) */
    .cp-window.screen-chat .cp-bottom-nav,
    .cp-window.screen-human-chat .cp-bottom-nav,
    .screen-chat .cp-bottom-nav,
    .screen-human-chat .cp-bottom-nav,
    :host([data-screen="chat"]) .cp-bottom-nav,
    :host([data-screen="human-chat"]) .cp-bottom-nav {
      display: none !important;
      visibility: hidden !important;
    }

    .cp-nav-item {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 3px;
      background: transparent;
      border: none;
      color: var(--cp-tool-btn, #787c8a);
      cursor: pointer;
      padding: 4px 8px;
      border-radius: 8px;
      transition: all 0.15s ease;
      flex: 1;
      max-width: 80px;
    }

    .cp-nav-item:hover {
      color: var(--cp-text-primary, #ffffff);
    }

    .cp-nav-item.active {
      color: #ffffff;
    }

    :host([data-theme="light"]) .cp-nav-item,
    .cp-light-theme .cp-nav-item,
    .theme-light .cp-nav-item {
      color: #64748b;
    }

    :host([data-theme="light"]) .cp-nav-item:hover,
    .cp-light-theme .cp-nav-item:hover,
    .theme-light .cp-nav-item:hover {
      color: #0f172a;
    }

    :host([data-theme="light"]) .cp-nav-item.active,
    .cp-light-theme .cp-nav-item.active,
    .theme-light .cp-nav-item.active {
      color: #0f172a;
    }

    .cp-nav-icon-badge-wrap {
      position: relative;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .cp-nav-badge {
      position: absolute;
      top: -4px;
      right: -8px;
      background: #f43f5e;
      color: #ffffff;
      font-size: 10px;
      font-weight: 700;
      min-width: 16px;
      height: 16px;
      border-radius: 999px;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0 4px;
      line-height: 1;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
    }

    .cp-nav-label {
      font-size: 11px;
      font-weight: 500;
      line-height: 1.2;
    }

    .cp-nav-item.active .cp-nav-label {
      font-weight: 700;
    }

    /* Floating Ask A Question Pill (media_1790868630655.png) */
    .cp-ask-question-wrap {
      display: flex;
      justify-content: center;
      align-items: center;
      padding: 6px 14px 4px 14px;
      margin-top: 5px;
      flex-shrink: 0;
    }

    .cp-ask-question-pill {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      background: #ffffff;
      color: #0f172a;
      border: 1px solid rgba(0, 0, 0, 0.08);
      border-radius: 9999px;
      padding: 7px 16px;
      font-size: 12.5px;
      font-weight: 600;
      box-shadow: 0 3px 12px rgba(0, 0, 0, 0.14), 0 1px 3px rgba(0, 0, 0, 0.08);
      cursor: pointer;
      transition: all 0.18s cubic-bezier(0.16, 1, 0.3, 1);
      user-select: none;
    }

    :host([data-theme="light"]) .cp-ask-question-pill,
    .cp-light-theme .cp-ask-question-pill,
    .theme-light .cp-ask-question-pill {
      background: #0f172a;
      color: #ffffff;
      border: 1px solid #0f172a;
    }

    .cp-ask-question-pill:hover {
      transform: translateY(-1.5px);
      box-shadow: 0 6px 16px rgba(0, 0, 0, 0.22);
    }

    .cp-ask-question-pill:active {
      transform: translateY(0);
    }

    .cp-ask-icon-bubble {
      width: 15px;
      height: 15px;
      border-radius: 50%;
      background: #0f172a;
      color: #ffffff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 10px;
      font-weight: 700;
      flex-shrink: 0;
    }

    :host([data-theme="light"]) .cp-ask-icon-bubble,
    .cp-light-theme .cp-ask-icon-bubble,
    .theme-light .cp-ask-icon-bubble {
      background: #ffffff;
      color: #0f172a;
    }

    /* Screen: Premium Locked Screen */
    .cp-locked-container {
      padding: 24px 16px;
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      justify-content: center;
      height: 100%;
    }

    .cp-locked-icon {
      width: 48px;
      height: 48px;
      border-radius: 50%;
      background: rgba(245, 158, 11, 0.12);
      color: #f59e0b;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 12px;
      border: 1px solid rgba(245, 158, 11, 0.25);
    }

    .cp-locked-title {
      font-size: 16px;
      font-weight: 700;
      color: var(--cp-text-title, #ffffff);
      margin-bottom: 6px;
    }

    .cp-locked-desc {
      font-size: 12px;
      color: var(--cp-text-secondary, #8e929f);
      line-height: 1.45;
      max-width: 270px;
      margin-bottom: 16px;
    }

    .cp-locked-upgrade-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      width: 100%;
      max-width: 240px;
      padding: 9px 16px;
      border-radius: 8px;
      background: #ffffff;
      color: #111111;
      font-size: 12.5px;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.15s ease;
      cursor: pointer;
      border: none;
      box-shadow: 0 2px 6px rgba(0,0,0,0.15);
    }

    .cp-locked-upgrade-btn:hover {
      background: #f1f5f9;
      transform: translateY(-1px);
    }

    .cp-locked-back-btn {
      margin-top: 10px;
      background: transparent;
      border: none;
      color: var(--cp-text-muted, #737885);
      font-size: 11.5px;
      cursor: pointer;
      text-decoration: underline;
    }

    .cp-locked-back-btn:hover {
      color: var(--cp-text-primary, #ffffff);
    }

    /* ------------------------------------------------------------- */
    /* Intercom Fin / Messenger Style Waiting Pill & Handoff Card   */
    /* ------------------------------------------------------------- */
    .cp-waiting-pill {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      margin: 10px auto;
      padding: 7px 14px;
      background: var(--cp-options-bg, #181920);
      border: 1px solid var(--cp-border-input, #282931);
      border-radius: 9999px;
      font-size: 12px;
      color: var(--cp-text-primary, #ffffff);
      box-shadow: 0 4px 14px rgba(0, 0, 0, 0.15);
      animation: cpFadeSlideIn 0.25s cubic-bezier(0.16, 1, 0.3, 1);
      max-width: 90%;
      user-select: none;
      transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }

    :host([data-theme="light"]) .cp-waiting-pill,
    .cp-window.cp-light-theme .cp-waiting-pill,
    .cp-window.theme-light .cp-waiting-pill {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      color: #0f172a;
      box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
    }

    .cp-waiting-avatar-wrap {
      position: relative;
      width: 22px;
      height: 22px;
      flex-shrink: 0;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .cp-waiting-avatar-img {
      width: 22px;
      height: 22px;
      border-radius: 50%;
      object-fit: cover;
      border: 1.5px solid var(--cp-bg-surface, #1e2028);
    }

    :host([data-theme="light"]) .cp-waiting-avatar-img,
    .cp-window.cp-light-theme .cp-waiting-avatar-img,
    .cp-window.theme-light .cp-waiting-avatar-img {
      border-color: #f1f5f9;
    }

    .cp-waiting-dot-pulse {
      position: absolute;
      bottom: -1px;
      right: -1px;
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #f59e0b;
      box-shadow: 0 0 0 2px var(--cp-bg-surface, #1e2028);
      animation: cpPulseAmber 1.6s infinite ease-in-out;
    }

    .cp-waiting-pill.connected .cp-waiting-dot-pulse {
      background: #10b981;
      animation: cpPulseGreen 1.8s infinite ease-in-out;
    }

    @keyframes cpPulseAmber {
      0%, 100% { transform: scale(1); opacity: 0.9; box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.5); }
      50% { transform: scale(1.15); opacity: 1; box-shadow: 0 0 0 4px rgba(245, 158, 11, 0); }
    }

    @keyframes cpPulseGreen {
      0%, 100% { transform: scale(1); opacity: 0.9; box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.5); }
      50% { transform: scale(1.15); opacity: 1; box-shadow: 0 0 0 4px rgba(16, 185, 129, 0); }
    }

    .cp-waiting-text {
      font-weight: 500;
      color: var(--cp-text-primary, #ffffff);
      letter-spacing: -0.01em;
      white-space: nowrap;
    }

    :host([data-theme="light"]) .cp-waiting-text,
    .cp-window.cp-light-theme .cp-waiting-text,
    .cp-window.theme-light .cp-waiting-text {
      color: #0f172a;
    }

    .cp-waiting-timer {
      font-size: 11px;
      font-weight: 600;
      color: #f59e0b;
      background: rgba(245, 158, 11, 0.12);
      border: 1px solid rgba(245, 158, 11, 0.25);
      padding: 1px 7px;
      border-radius: 9999px;
      font-variant-numeric: tabular-nums;
    }

    .cp-waiting-pill.connected .cp-waiting-timer {
      color: #10b981;
      background: rgba(16, 185, 129, 0.12);
      border-color: rgba(16, 185, 129, 0.25);
    }

    .cp-waiting-pill.pending .cp-waiting-dot-pulse {
      background: #6366f1;
      animation: none;
    }

    .cp-waiting-pill.pending .cp-waiting-timer {
      color: #6366f1;
      background: rgba(99, 102, 241, 0.12);
      border-color: rgba(99, 102, 241, 0.25);
    }

    /* Intercom Agent Takeover Card / Notification */
    .cp-takeover-banner {
      display: flex;
      align-items: center;
      gap: 12px;
      margin: 12px auto 8px auto;
      padding: 10px 14px;
      background: var(--cp-options-bg, #181920);
      border: 1px solid var(--cp-border-input, #282931);
      border-radius: 12px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.15);
      animation: cpFadeSlideIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
      width: calc(100% - 16px);
      box-sizing: border-box;
    }

    :host([data-theme="light"]) .cp-takeover-banner,
    .cp-window.cp-light-theme .cp-takeover-banner,
    .cp-window.theme-light .cp-takeover-banner {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      box-shadow: 0 2px 8px rgba(15, 23, 42, 0.05);
    }

    .cp-takeover-avatar {
      position: relative;
      width: 32px;
      height: 32px;
      flex-shrink: 0;
    }

    .cp-takeover-avatar img {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      object-fit: cover;
      border: 1.5px solid var(--cp-bg-surface, #1e2028);
    }

    :host([data-theme="light"]) .cp-takeover-avatar img,
    .cp-window.cp-light-theme .cp-takeover-avatar img,
    .cp-window.theme-light .cp-takeover-avatar img {
      border-color: #ffffff;
    }

    .cp-takeover-status-dot {
      position: absolute;
      bottom: -1px;
      right: -1px;
      width: 9px;
      height: 9px;
      border-radius: 50%;
      background: #10b981;
      border: 2px solid var(--cp-bg-surface, #1e2028);
    }

    :host([data-theme="light"]) .cp-takeover-status-dot,
    .cp-window.cp-light-theme .cp-takeover-status-dot,
    .cp-window.theme-light .cp-takeover-status-dot {
      border-color: #ffffff;
    }

    .cp-takeover-info {
      flex: 1;
      min-width: 0;
      text-align: left;
    }

    .cp-takeover-name {
      font-size: 12.5px;
      font-weight: 600;
      color: var(--cp-text-title, #ffffff);
      line-height: 1.3;
    }

    :host([data-theme="light"]) .cp-takeover-name,
    .cp-window.cp-light-theme .cp-takeover-name,
    .cp-window.theme-light .cp-takeover-name {
      color: #0f172a;
    }

    .cp-takeover-desc {
      font-size: 11px;
      color: var(--cp-text-secondary, #8e929f);
      margin-top: 1px;
    }

    :host([data-theme="light"]) .cp-takeover-desc,
    .cp-window.cp-light-theme .cp-takeover-desc,
    .cp-window.theme-light .cp-takeover-desc {
      color: #64748b;
    }

    .cp-msg-row.ai.human-agent-msg .cp-bubble {
      border-left: 2.5px solid #10b981;
    }

    :host([data-theme="light"]) .cp-ask-icon-bubble,
    .cp-light-theme .cp-ask-icon-bubble,
    .theme-light .cp-ask-icon-bubble {
      background: #ffffff;
      color: #0f172a;
    }

    /* News Screen Styles */
    .cp-news-header {
      padding: 6px 2px 8px 2px;
    }

    .cp-news-title {
      font-size: 19px;
      font-weight: 700;
      color: var(--cp-text-title, #ffffff);
      line-height: 1.25;
    }

    .cp-news-sub {
      font-size: 12.5px;
      color: var(--cp-text-secondary, #8e929f);
      margin-top: 3px;
    }

    .cp-news-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .cp-news-card {
      background: var(--cp-options-bg, #181920);
      border: 1px solid var(--cp-border-input, #282931);
      border-radius: 12px;
      padding: 13px 14px;
      cursor: pointer;
      transition: all 0.18s ease;
    }

    .cp-news-card:hover {
      background: var(--cp-options-hover, #232530);
      transform: translateY(-1px);
    }

    .cp-news-card-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 6px;
    }

    .cp-news-badge {
      font-size: 10px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      padding: 2px 7px;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.08);
      color: var(--cp-text-primary, #ffffff);
    }

    .cp-news-badge.new {
      background: rgba(16, 185, 129, 0.15);
      color: #34d399;
    }

    .cp-news-date {
      font-size: 11px;
      color: var(--cp-text-muted, #6b707e);
    }

    .cp-news-heading {
      font-size: 13.5px;
      font-weight: 600;
      color: var(--cp-text-title, #ffffff);
      margin-bottom: 4px;
    }

    .cp-news-desc {
      font-size: 12px;
      color: var(--cp-text-secondary, #8e929f);
      line-height: 1.4;
    }

    .cp-news-img {
      width: 100%;
      height: 110px;
      object-fit: cover;
      border-radius: 8px;
      margin-bottom: 8px;
    }

    .cp-news-all-link {
      display: block;
      text-align: center;
      padding: 10px;
      font-size: 11.5px;
      font-weight: 500;
      color: var(--cp-text-muted, #94a3b8);
      text-decoration: none;
      transition: color 0.15s ease;
      border-top: 1px solid var(--cp-border-input, #282931);
      margin-top: 6px;
    }

    .cp-news-all-link:hover {
      color: var(--cp-text-primary, #ffffff);
    }

    :host([data-theme="light"]) .cp-news-all-link,
    .cp-light-theme .cp-news-all-link,
    .theme-light .cp-news-all-link {
      border-color: #e2e8f0;
      color: #64748b;
    }

    :host([data-theme="light"]) .cp-news-all-link:hover,
    .cp-light-theme .cp-news-all-link:hover,
    .theme-light .cp-news-all-link:hover {
      color: #0f172a;
    }

    :host([data-theme="light"]) .cp-news-badge:not(.new),
    .cp-light-theme .cp-news-badge:not(.new),
    .theme-light .cp-news-badge:not(.new) {
      background: #f1f5f9;
      color: #334155;
    }

    /* Back Button Toggle on Home Screen */
    .cp-window.screen-home .cp-back-btn,
    #cp-chat-window.screen-home #cp-back-btn {
      display: none !important;
      visibility: hidden !important;
    }

    .cp-window.screen-chat .cp-back-btn,
    #cp-chat-window.screen-chat #cp-back-btn,
    .cp-window.screen-human-chat .cp-back-btn,
    #cp-chat-window.screen-human-chat #cp-back-btn {
      display: inline-flex !important;
      visibility: visible !important;
      opacity: 1 !important;
    }

    /* Markdown Tables Support */
    .cp-table-responsive {
      width: 100%;
      overflow-x: auto;
      margin: 10px 0;
      border-radius: 8px;
      border: 1px solid rgba(226, 232, 240, 0.9);
      background: #ffffff;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
      -webkit-overflow-scrolling: touch;
    }
    :host([data-theme="dark"]) .cp-table-responsive,
    .cp-dark-theme .cp-table-responsive,
    .theme-dark .cp-table-responsive {
      background: #18181b;
      border-color: #27272a;
      box-shadow: none;
    }
    .cp-table {
      width: 100%;
      min-width: 280px;
      border-collapse: collapse;
      font-size: 12px;
      line-height: 1.45;
      text-align: left;
      table-layout: auto;
      word-break: normal;
    }
    .cp-table th {
      background: #f8fafc;
      color: #334155;
      font-weight: 600;
      padding: 8px 10px;
      border-bottom: 1px solid #e2e8f0;
      white-space: nowrap;
      font-family: inherit;
    }
    :host([data-theme="dark"]) .cp-table th,
    .cp-dark-theme .cp-table th,
    .theme-dark .cp-table th {
      background: #27272a;
      color: #e4e4e7;
      border-bottom-color: #3f3f46;
    }
    .cp-table td {
      padding: 8px 10px;
      border-bottom: 1px solid #f1f5f9;
      color: #1e293b;
      vertical-align: middle;
      word-break: normal;
      overflow-wrap: break-word;
    }
    :host([data-theme="dark"]) .cp-table td,
    .cp-dark-theme .cp-table td,
    .theme-dark .cp-table td {
      border-bottom-color: #27272a;
      color: #d4d4d8;
    }
    .cp-table tr:last-child td {
      border-bottom: none;
    }
    .cp-table tr:nth-child(even) td {
      background: rgba(248, 250, 252, 0.6);
    }
    :host([data-theme="dark"]) .cp-table tr:nth-child(even) td,
    .cp-dark-theme .cp-table tr:nth-child(even) td,
    .theme-dark .cp-table tr:nth-child(even) td {
      background: rgba(255, 255, 255, 0.02);
    }

    /* Strictly Black & White Single-Row Action Chips & Inactivity Chips */
    .cp-action-chips-container {
      display: flex !important;
      flex-direction: row !important;
      flex-wrap: nowrap !important;
      overflow-x: auto !important;
      overflow-y: hidden !important;
      gap: 6px !important;
      padding: 6px 2px 4px 2px !important;
      margin-top: 8px !important;
      max-width: 100% !important;
      -webkit-overflow-scrolling: touch !important;
      scrollbar-width: none !important;
      -ms-overflow-style: none !important;
    }
    .cp-action-chips-container::-webkit-scrollbar {
      display: none !important;
      width: 0 !important;
      height: 0 !important;
    }
    .cp-action-chip-pill {
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
      flex-shrink: 0 !important;
      white-space: nowrap !important;
      background: #ffffff !important;
      color: #000000 !important;
      border: 1px solid #000000 !important;
      border-radius: 16px !important;
      padding: 4px 11px !important;
      font-size: 11px !important;
      font-weight: 500 !important;
      line-height: 1.2 !important;
      cursor: pointer !important;
      transition: all 0.15s ease-in-out !important;
      font-family: inherit !important;
      letter-spacing: -0.01em !important;
      outline: none !important;
      box-shadow: none !important;
      user-select: none !important;
    }
    .cp-action-chip-pill:hover,
    .cp-action-chip-pill:focus-visible {
      background: #000000 !important;
      color: #ffffff !important;
      border-color: #000000 !important;
    }
    :host([data-theme="dark"]) .cp-action-chip-pill,
    .cp-dark-theme .cp-action-chip-pill,
    .theme-dark .cp-action-chip-pill {
      background: #18181b !important;
      color: #ffffff !important;
      border: 1px solid #3f3f46 !important;
    }
    :host([data-theme="dark"]) .cp-action-chip-pill:hover,
    .cp-dark-theme .cp-action-chip-pill:hover,
    .theme-dark .cp-action-chip-pill:hover,
    :host([data-theme="dark"]) .cp-action-chip-pill:focus-visible,
    .cp-dark-theme .cp-action-chip-pill:focus-visible,
    .theme-dark .cp-action-chip-pill:focus-visible {
      background: #ffffff !important;
      color: #000000 !important;
      border-color: #ffffff !important;
    }

    /* 10-Second Inactivity Chips (Monochrome Black & White) */
    .cp-inactivity-chips-container {
      margin: 10px 0 4px 0;
      padding: 8px 10px;
      background: #fafafa;
      border: 1px solid #e4e4e7;
      border-radius: 10px;
      display: flex;
      flex-direction: column;
      gap: 6px;
      animation: cpSlideUpFade 0.25s ease-out;
    }
    :host([data-theme="dark"]) .cp-inactivity-chips-container,
    .cp-dark-theme .cp-inactivity-chips-container,
    .theme-dark .cp-inactivity-chips-container {
      background: #18181b;
      border-color: #27272a;
    }
    @keyframes cpSlideUpFade {
      from { opacity: 0; transform: translateY(6px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .cp-inactivity-chips-title {
      font-size: 10.5px;
      color: #71717a;
      font-weight: 500;
      letter-spacing: -0.01em;
    }
    :host([data-theme="dark"]) .cp-inactivity-chips-title,
    .cp-dark-theme .cp-inactivity-chips-title,
    .theme-dark .cp-inactivity-chips-title {
      color: #a1a1aa;
    }
    .cp-inactivity-chips-pills {
      display: flex !important;
      flex-direction: row !important;
      flex-wrap: nowrap !important;
      overflow-x: auto !important;
      overflow-y: hidden !important;
      gap: 6px !important;
      padding: 2px 0 !important;
      -webkit-overflow-scrolling: touch !important;
      scrollbar-width: none !important;
      -ms-overflow-style: none !important;
    }
    .cp-inactivity-chips-pills::-webkit-scrollbar {
      display: none !important;
      width: 0 !important;
      height: 0 !important;
    }
    .cp-inactivity-chip {
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
      flex-shrink: 0 !important;
      white-space: nowrap !important;
      gap: 5px !important;
      padding: 4px 10px !important;
      border-radius: 14px !important;
      font-size: 11px !important;
      font-weight: 500 !important;
      cursor: pointer !important;
      border: 1px solid #000000 !important;
      background: #ffffff !important;
      color: #000000 !important;
      transition: all 0.15s ease-in-out !important;
      font-family: inherit !important;
      outline: none !important;
      letter-spacing: -0.01em !important;
    }
    .cp-inactivity-chip:hover,
    .cp-inactivity-chip:focus-visible {
      background: #000000 !important;
      color: #ffffff !important;
      border-color: #000000 !important;
    }
    :host([data-theme="dark"]) .cp-inactivity-chip,
    .cp-dark-theme .cp-inactivity-chip,
    .theme-dark .cp-inactivity-chip {
      background: #18181b !important;
      color: #ffffff !important;
      border: 1px solid #3f3f46 !important;
    }
    :host([data-theme="dark"]) .cp-inactivity-chip:hover,
    .cp-dark-theme .cp-inactivity-chip:hover,
    .theme-dark .cp-inactivity-chip:hover,
    :host([data-theme="dark"]) .cp-inactivity-chip:focus-visible,
    .cp-dark-theme .cp-inactivity-chip:focus-visible,
    .theme-dark .cp-inactivity-chip:focus-visible {
      background: #ffffff !important;
      color: #000000 !important;
      border-color: #ffffff !important;
    }
    .cp-chip-wa,
    .cp-chip-person,
    .cp-chip-end {
      background: inherit;
      color: inherit;
      border-color: inherit;
    }

    /* Visitor Intake Card (Section 1: Intercom / Fin Minimalist Design) */
    .cp-visitor-intake-card {
      background: var(--cp-bg-bubble, #202228);
      border: 1px solid var(--cp-border-bubble, #2d2f38);
      border-radius: 12px;
      padding: 14px 16px;
      margin-top: 6px;
      max-width: 90%;
    }
    :host([data-theme="light"]) .cp-visitor-intake-card,
    .cp-window.cp-light-theme .cp-visitor-intake-card,
    .cp-window.theme-light .cp-visitor-intake-card {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
    }
    .cp-intake-title {
      font-size: 13px;
      font-weight: 600;
      color: var(--cp-text-title, #ffffff);
      margin-bottom: 12px;
      line-height: 1.4;
    }
    :host([data-theme="light"]) .cp-intake-title,
    .cp-window.cp-light-theme .cp-intake-title,
    .cp-window.theme-light .cp-intake-title {
      color: #0f172a;
    }
    .cp-intake-form {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .cp-intake-field {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .cp-intake-field label {
      font-size: 11.5px;
      font-weight: 500;
      color: var(--cp-text-muted, #737885);
    }
    :host([data-theme="light"]) .cp-intake-field label,
    .cp-window.cp-light-theme .cp-intake-field label,
    .cp-window.theme-light .cp-intake-field label {
      color: #475569;
    }
    .cp-intake-field label .req {
      color: #ef4444;
    }
    .cp-intake-field label .opt {
      font-size: 10.5px;
      color: var(--cp-text-muted, #737885);
      opacity: 0.7;
    }
    .cp-intake-field input {
      background: var(--cp-bg-input, #1a1b20);
      border: 1px solid var(--cp-border-input, #282931);
      color: var(--cp-text-primary, #ffffff);
      font-size: 13px;
      padding: 8px 11px;
      border-radius: 6px;
      outline: none;
      transition: border-color 0.2s;
    }
    :host([data-theme="light"]) .cp-intake-field input,
    .cp-window.cp-light-theme .cp-intake-field input,
    .cp-window.theme-light .cp-intake-field input {
      background: #ffffff;
      border: 1px solid #cbd5e1;
      color: #0f172a;
    }
    .cp-intake-field input:focus {
      border-color: var(--cp-composer-focus-border, #3e414f);
    }
    :host([data-theme="light"]) .cp-intake-field input:focus,
    .cp-window.cp-light-theme .cp-intake-field input:focus,
    .cp-window.theme-light .cp-intake-field input:focus {
      border-color: #0f172a;
    }
    .cp-intake-submit-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      width: 100%;
      background: #111111;
      color: #ffffff;
      border: 1px solid #111111;
      border-radius: 6px;
      padding: 10px 14px;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      margin-top: 4px;
      box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
      transition: all 0.15s ease;
    }
    .cp-intake-submit-btn:hover {
      background: #000000;
      border-color: #000000;
      transform: translateY(-1px);
    }
    .cp-intake-submit-btn:active {
      transform: translateY(0);
    }
    .cp-intake-submit-btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none;
    }

    /* Light Theme Button: High-Contrast Solid Black (#0f172a) with Crisp White Text */
    :host([data-theme="light"]) .cp-intake-submit-btn,
    .cp-window.cp-light-theme .cp-intake-submit-btn,
    .cp-window.theme-light .cp-intake-submit-btn,
    .theme-light .cp-intake-submit-btn {
      background: #0f172a !important;
      border-color: #0f172a !important;
      color: #ffffff !important;
    }
    :host([data-theme="light"]) .cp-intake-submit-btn:hover,
    .cp-window.cp-light-theme .cp-intake-submit-btn:hover,
    .cp-window.theme-light .cp-intake-submit-btn:hover,
    .theme-light .cp-intake-submit-btn:hover {
      background: #000000 !important;
      border-color: #000000 !important;
    }

    /* Dark Theme Button: Solid Pure White with Jet-Black text (Matching Intercom Fin primary action) */
    :host([data-theme="dark"]) .cp-intake-submit-btn,
    .cp-window:not(.cp-light-theme) .cp-intake-submit-btn {
      background: #ffffff !important;
      border-color: #ffffff !important;
      color: #0f172a !important;
    }
    :host([data-theme="dark"]) .cp-intake-submit-btn:hover,
    .cp-window:not(.cp-light-theme) .cp-intake-submit-btn:hover {
      background: #f1f5f9 !important;
      border-color: #f1f5f9 !important;
    }

    /* System Confirmation Minimal Badge (Intercom Fin Reference) */
    .cp-system-confirmation-row {
      display: flex;
      justify-content: center;
      margin: 14px auto;
      width: 100%;
    }
    .cp-system-confirmation-badge {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      background: var(--cp-bg-surface, #1e2028);
      border: 1px solid var(--cp-border-subtle, rgba(255, 255, 255, 0.08));
      color: var(--cp-text-secondary, #94a3b8);
      padding: 6px 14px;
      border-radius: 9999px;
      font-size: 11.5px;
      font-weight: 500;
      line-height: 1.4;
      max-width: 92%;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
      text-align: center;
      animation: cpFadeSlideIn 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .cp-light-theme .cp-system-confirmation-badge,
    .theme-light .cp-system-confirmation-badge {
      background: #f4f4f6 !important;
      border: 1px solid #e4e4e7 !important;
      color: #52525b !important;
      box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04) !important;
    }
    .cp-system-confirmation-badge svg {
      flex-shrink: 0;
      color: #10b981;
    }
    .cp-sys-conf-content {
      color: inherit;
      white-space: pre-line;
    }
    .cp-sys-conf-content strong {
      color: var(--cp-text-primary, #ffffff);
      font-weight: 600;
    }
    .cp-light-theme .cp-sys-conf-content strong,
    .theme-light .cp-sys-conf-content strong {
      color: #18181b !important;
    }

    /* Appointment Slot Chips (Section 8, 9, 10, 11) */
    .cp-appointment-slots-box {
      margin-top: 10px;
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    .cp-slots-header {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 11.5px;
      font-weight: 500;
      color: var(--cp-text-muted);
    }
    .cp-slots-grid {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
    }
    .cp-slot-pill {
      background: var(--cp-bg-surface, #15161b);
      border: 1px solid var(--cp-border-input, #282931);
      color: var(--cp-text-primary, #ffffff);
      font-size: 12px;
      padding: 6px 12px;
      border-radius: 6px;
      cursor: pointer;
      transition: all 0.2s ease;
      font-weight: 500;
    }
    :host([data-theme="light"]) .cp-slot-pill,
    .cp-window.cp-light-theme .cp-slot-pill,
    .cp-window.theme-light .cp-slot-pill {
      background: #ffffff;
      border: 1px solid #cbd5e1;
      color: #0f172a;
    }
    .cp-slot-pill:hover {
      border-color: var(--cp-text-primary, #ffffff);
      background: var(--cp-bg-bubble, #202228);
      color: var(--cp-text-primary, #ffffff);
      transform: translateY(-1px);
    }
    :host([data-theme="light"]) .cp-slot-pill:hover,
    .cp-window.cp-light-theme .cp-slot-pill:hover,
    .cp-window.theme-light .cp-slot-pill:hover {
      border-color: #0f172a;
      background: #f1f5f9;
      color: #0f172a;
    }

    /* Instagram Action Card (Section 4 & 5) */
    .cp-instagram-card {
      background: rgba(225, 48, 108, 0.06);
      border: 1px solid rgba(225, 48, 108, 0.25);
      border-radius: 10px;
      padding: 12px 14px;
      margin-top: 10px;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .cp-ig-text {
      font-size: 12px;
      color: var(--cp-text);
      line-height: 1.45;
      font-weight: 400;
    }
    .cp-ig-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      background: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%);
      color: #ffffff;
      border: none;
      font-size: 12.5px;
      font-weight: 500;
      padding: 8px 14px;
      border-radius: 6px;
      text-decoration: none;
      transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
    }
    .cp-ig-btn:hover {
      opacity: 0.92;
      transform: translateY(-1px);
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.3);
    }
    .cp-ig-btn svg {
      width: 15px;
      height: 15px;
      color: #ffffff;
    }

    /* Digital Shared Asset Card (Syllabus, Brochure, Fee Chart, PDF) */
    .cp-shared-asset-card {
      background: var(--cp-bg-surface, #15161b);
      border: 1px solid var(--cp-border-input, #282931);
      border-radius: 10px;
      padding: 12px 14px;
      margin-top: 10px;
      display: flex;
      flex-direction: column;
      gap: 10px;
      transition: all 0.2s ease;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
      text-align: left;
    }
    :host([data-theme="light"]) .cp-shared-asset-card,
    .cp-window.cp-light-theme .cp-shared-asset-card,
    .cp-window.theme-light .cp-shared-asset-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }
    .cp-asset-header {
      display: flex;
      align-items: flex-start;
      gap: 10px;
    }
    .cp-asset-icon-box {
      width: 34px;
      height: 34px;
      border-radius: 6px;
      background: rgba(15, 23, 42, 0.06);
      border: 1px solid rgba(15, 23, 42, 0.12);
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--cp-text-primary, #ffffff);
      flex-shrink: 0;
    }
    :host([data-theme="light"]) .cp-asset-icon-box,
    .cp-window.cp-light-theme .cp-asset-icon-box,
    .cp-window.theme-light .cp-asset-icon-box {
      background: #f1f5f9;
      border: 1px solid #e2e8f0;
      color: #0f172a;
    }
    .cp-asset-info {
      flex: 1;
      min-width: 0;
    }
    .cp-asset-category {
      font-size: 10px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: #10b981;
      margin-bottom: 2px;
    }
    .cp-asset-title {
      font-size: 12.5px;
      font-weight: 600;
      color: var(--cp-text-primary, #ffffff);
      line-height: 1.35;
      word-break: break-word;
    }
    :host([data-theme="light"]) .cp-asset-title,
    .cp-window.cp-light-theme .cp-asset-title,
    .cp-window.theme-light .cp-asset-title {
      color: #0f172a;
    }
    .cp-asset-meta {
      font-size: 10.5px;
      color: var(--cp-text-muted, #94a3b8);
      margin-top: 2px;
    }
    .cp-asset-desc {
      font-size: 11.5px;
      color: var(--cp-text-secondary, #cbd5e1);
      line-height: 1.4;
    }
    :host([data-theme="light"]) .cp-asset-desc,
    .cp-window.cp-light-theme .cp-asset-desc,
    .cp-window.theme-light .cp-asset-desc {
      color: #475569;
    }
    .cp-asset-actions {
      display: flex;
      gap: 8px;
    }
    .cp-asset-download-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      background: #0f172a;
      color: #ffffff !important;
      border: 1px solid #1e293b;
      font-size: 12px;
      font-weight: 500;
      padding: 7px 14px;
      border-radius: 6px;
      text-decoration: none;
      cursor: pointer;
      transition: all 0.2s ease;
      width: 100%;
    }
    .cp-asset-download-btn:hover {
      background: #1e293b;
      transform: translateY(-1px);
    }
    .cp-asset-email-badge {
      font-size: 11px;
      display: flex;
      align-items: center;
      gap: 5px;
      padding: 5px 8px;
      border-radius: 4px;
      line-height: 1.3;
    }
    .cp-asset-email-badge.success {
      background: rgba(16, 185, 129, 0.1);
      color: #10b981;
      border: 1px solid rgba(16, 185, 129, 0.25);
    }
    .cp-asset-email-badge.pending {
      background: rgba(59, 130, 246, 0.1);
      color: #3b82f6;
      border: 1px solid rgba(59, 130, 246, 0.25);
    }
    .cp-asset-email-badge.prompt {
      background: rgba(148, 163, 184, 0.1);
      color: var(--cp-text-muted, #94a3b8);
      border: 1px solid rgba(148, 163, 184, 0.2);
    }
    :host([data-theme="light"]) .cp-asset-email-badge.prompt,
    .cp-window.cp-light-theme .cp-asset-email-badge.prompt,
    .cp-window.theme-light .cp-asset-email-badge.prompt {
      color: #64748b;
    }

    /* ============================================================= */
    /* INTERCOM-STYLE COMMERCE & PRODUCT CAROUSEL (Cai AI Commerce)  */
    /* ============================================================= */
    /* ============================================================= */
    /* INTERCOM-STYLE COMMERCE & PRODUCT CAROUSEL (Cai AI Commerce)  */
    /* Lightweight, sleek, and strictly contained within chat bounds */
    /* ============================================================= */
    .cp-product-carousel-box {
      display: flex;
      gap: 10px;
      overflow-x: auto;
      scroll-snap-type: x mandatory;
      padding: 8px 2px 10px 2px;
      margin-top: 8px;
      width: 100%;
      max-width: 100%;
      box-sizing: border-box;
      -webkit-overflow-scrolling: touch;
      scrollbar-width: thin;
    }
    .cp-product-carousel-box::-webkit-scrollbar {
      height: 3px;
    }
    .cp-product-carousel-box::-webkit-scrollbar-thumb {
      background: rgba(255, 255, 255, 0.15);
      border-radius: 4px;
    }
    :host([data-theme="light"]) .cp-product-carousel-box::-webkit-scrollbar-thumb,
    .cp-window.cp-light-theme .cp-product-carousel-box::-webkit-scrollbar-thumb,
    .cp-window.theme-light .cp-product-carousel-box::-webkit-scrollbar-thumb {
      background: rgba(0, 0, 0, 0.12);
    }
    .cp-product-card {
      flex: 0 0 215px;
      min-width: 200px;
      max-width: 82%;
      box-sizing: border-box;
      scroll-snap-align: start;
      background: var(--cp-options-bg, #181920);
      border: 1px solid var(--cp-border-input, #282931);
      border-radius: 10px;
      padding: 11px 12px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      gap: 8px;
      box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
      transition: transform 0.16s ease, border-color 0.16s ease;
      text-align: left;
    }
    .cp-product-card:hover {
      border-color: rgba(255, 255, 255, 0.22);
      transform: translateY(-1.5px);
    }
    :host([data-theme="light"]) .cp-product-card,
    .cp-window.cp-light-theme .cp-product-card,
    .cp-window.theme-light .cp-product-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
    }
    :host([data-theme="light"]) .cp-product-card:hover,
    .cp-window.cp-light-theme .cp-product-card:hover,
    .cp-window.theme-light .cp-product-card:hover {
      border-color: #cbd5e1;
    }
    .cp-card-top-meta {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 6px;
    }
    .cp-prod-category-badge {
      font-size: 9.5px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: #6366f1;
      background: rgba(99, 102, 241, 0.12);
      padding: 2px 7px;
      border-radius: 9999px;
    }
    .cp-prod-duration-pill {
      font-size: 10px;
      color: var(--cp-text-muted, #94a3b8);
      font-weight: 500;
    }
    .cp-prod-title {
      font-size: 13px;
      font-weight: 700;
      color: var(--cp-text-primary, #ffffff);
      line-height: 1.3;
    }
    :host([data-theme="light"]) .cp-prod-title,
    .cp-window.cp-light-theme .cp-prod-title,
    .cp-window.theme-light .cp-prod-title {
      color: #0f172a;
    }
    .cp-prod-pricing-row {
      display: flex;
      align-items: baseline;
      gap: 5px;
      margin-top: 2px;
    }
    .cp-prod-price {
      font-size: 15px;
      font-weight: 800;
      color: var(--cp-text-primary, #ffffff);
    }
    :host([data-theme="light"]) .cp-prod-price,
    .cp-window.cp-light-theme .cp-prod-price,
    .cp-window.theme-light .cp-prod-price {
      color: #0f172a;
    }
    .cp-prod-orig-price {
      font-size: 11px;
      color: var(--cp-text-muted, #94a3b8);
      text-decoration: line-through;
    }
    .cp-prod-discount-pill {
      font-size: 9.5px;
      font-weight: 700;
      color: #10b981;
      background: rgba(16, 185, 129, 0.1);
      padding: 1px 5px;
      border-radius: 4px;
    }
    .cp-prod-emi-badge {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: 10.5px;
      font-weight: 600;
      color: #8b5cf6;
      background: rgba(139, 92, 246, 0.1);
      border: 1px solid rgba(139, 92, 246, 0.2);
      padding: 2.5px 7px;
      border-radius: 5px;
      width: fit-content;
    }
    .cp-prod-features-list {
      list-style: none;
      padding: 0;
      margin: 3px 0;
      display: flex;
      flex-direction: column;
      gap: 3px;
    }
    .cp-prod-feature-item {
      font-size: 11px;
      color: var(--cp-text-secondary, #94a3b8);
      display: flex;
      align-items: center;
      gap: 5px;
      line-height: 1.3;
    }
    :host([data-theme="light"]) .cp-prod-feature-item,
    .cp-window.cp-light-theme .cp-prod-feature-item,
    .cp-window.theme-light .cp-prod-feature-item {
      color: #475569;
    }
    .cp-prod-actions {
      display: flex;
      gap: 6px;
      margin-top: 4px;
    }
    .cp-prod-btn-primary {
      flex: 1;
      background: #ffffff;
      color: #0f172a;
      border: none;
      border-radius: 6px;
      padding: 6px 8px;
      font-size: 11px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.15s ease;
      text-align: center;
    }
    .cp-prod-btn-primary:hover {
      background: #f1f5f9;
      transform: translateY(-1px);
    }
    :host([data-theme="light"]) .cp-prod-btn-primary,
    .cp-window.cp-light-theme .cp-prod-btn-primary,
    .cp-window.theme-light .cp-prod-btn-primary {
      background: #0f172a;
      color: #ffffff;
    }
    :host([data-theme="light"]) .cp-prod-btn-primary:hover,
    .cp-window.cp-light-theme .cp-prod-btn-primary:hover,
    .cp-window.theme-light .cp-prod-btn-primary:hover {
      background: #000000;
    }
    .cp-prod-btn-secondary {
      background: transparent;
      color: var(--cp-text-secondary, #94a3b8);
      border: 1px solid var(--cp-border-input, #282931);
      border-radius: 6px;
      padding: 6px 8px;
      font-size: 11px;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.15s ease;
    }
    :host([data-theme="light"]) .cp-prod-btn-secondary,
    .cp-window.cp-light-theme .cp-prod-btn-secondary,
    .cp-window.theme-light .cp-prod-btn-secondary {
      border-color: #cbd5e1;
      color: #475569;
    }
    .cp-prod-btn-secondary:hover {
      color: var(--cp-text-primary, #ffffff);
      border-color: rgba(255, 255, 255, 0.3);
    }
    :host([data-theme="light"]) .cp-prod-btn-secondary:hover,
    .cp-window.cp-light-theme .cp-prod-btn-secondary:hover,
    .cp-window.theme-light .cp-prod-btn-secondary:hover {
      color: #0f172a;
      border-color: #94a3b8;
    }

    /* EMI Interactive Card */
    .cp-emi-card {
      background: var(--cp-options-bg, #181920);
      border: 1px solid var(--cp-border-input, #282931);
      border-radius: 10px;
      padding: 12px 13px;
      margin-top: 8px;
      width: 100%;
      max-width: 100%;
      box-sizing: border-box;
      display: flex;
      flex-direction: column;
      gap: 8px;
      box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
      text-align: left;
    }
    :host([data-theme="light"]) .cp-emi-card,
    .cp-window.cp-light-theme .cp-emi-card,
    .cp-window.theme-light .cp-emi-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
    }
    .cp-emi-header {
      display: flex;
      align-items: center;
      gap: 7px;
    }
    .cp-emi-title {
      font-size: 12.5px;
      font-weight: 700;
      color: var(--cp-text-primary, #ffffff);
    }
    :host([data-theme="light"]) .cp-emi-title,
    .cp-window.cp-light-theme .cp-emi-title,
    .cp-window.theme-light .cp-emi-title {
      color: #0f172a;
    }
    .cp-emi-stat-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 6px;
      background: rgba(255, 255, 255, 0.03);
      padding: 8px 10px;
      border-radius: 8px;
      border: 1px solid rgba(255, 255, 255, 0.06);
    }
    :host([data-theme="light"]) .cp-emi-stat-grid,
    .cp-window.cp-light-theme .cp-emi-stat-grid,
    .cp-window.theme-light .cp-emi-stat-grid {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
    }
    .cp-emi-stat-label {
      font-size: 10px;
      color: var(--cp-text-muted, #94a3b8);
      font-weight: 500;
    }
    .cp-emi-stat-value {
      font-size: 13.5px;
      font-weight: 700;
      color: var(--cp-text-primary, #ffffff);
      margin-top: 1px;
    }
    :host([data-theme="light"]) .cp-emi-stat-value,
    .cp-window.cp-light-theme .cp-emi-stat-value,
    .cp-window.theme-light .cp-emi-stat-value {
      color: #0f172a;
    }
    .cp-emi-timeline {
      display: flex;
      flex-direction: column;
      gap: 5px;
      margin: 4px 0;
    }
    .cp-emi-timeline-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      font-size: 11px;
      color: var(--cp-text-secondary, #94a3b8);
      padding: 3px 0;
      border-bottom: 1px dashed rgba(255, 255, 255, 0.08);
    }
    :host([data-theme="light"]) .cp-emi-timeline-row,
    .cp-window.cp-light-theme .cp-emi-timeline-row,
    .cp-window.theme-light .cp-emi-timeline-row {
      border-bottom: 1px dashed #e2e8f0;
      color: #475569;
    }
    .cp-emi-timeline-row:last-child {
      border-bottom: none;
    }

    /* Instant Payment Link Card */
    .cp-payment-link-card {
      background: var(--cp-bg-surface, #15161b);
      border: 1px solid rgba(16, 185, 129, 0.3);
      border-radius: 12px;
      padding: 14px;
      margin-top: 10px;
      display: flex;
      flex-direction: column;
      gap: 10px;
      box-shadow: 0 3px 12px rgba(16, 185, 129, 0.08);
      text-align: left;
    }
    :host([data-theme="light"]) .cp-payment-link-card,
    .cp-window.cp-light-theme .cp-payment-link-card,
    .cp-window.theme-light .cp-payment-link-card {
      background: #ffffff;
      border: 1px solid rgba(16, 185, 129, 0.4);
      box-shadow: 0 3px 12px rgba(16, 185, 129, 0.06);
    }
    .cp-payment-card-badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      font-size: 10.5px;
      font-weight: 700;
      color: #10b981;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .cp-payment-card-amount {
      font-size: 20px;
      font-weight: 800;
      color: var(--cp-text-primary, #ffffff);
    }
    :host([data-theme="light"]) .cp-payment-card-amount,
    .cp-window.cp-light-theme .cp-payment-card-amount,
    .cp-window.theme-light .cp-payment-card-amount {
      color: #0f172a;
    }
    .cp-payment-checkout-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      background: #10b981;
      color: #ffffff;
      border: none;
      border-radius: 8px;
      padding: 10px 16px;
      font-size: 13px;
      font-weight: 600;
      text-decoration: none;
      cursor: pointer;
      transition: all 0.18s ease;
      box-shadow: 0 2px 6px rgba(16, 185, 129, 0.3);
      width: 100%;
      box-sizing: border-box;
    }
    .cp-payment-checkout-btn:hover {
      background: #059669;
      transform: translateY(-1px);
    }
  `;

  shadow.appendChild(styleEl);

  // 5. Build Widget DOM Tree
  const widgetContainer = document.createElement('div');
  widgetContainer.className = 'cp-root-container';
  widgetContainer.innerHTML = `
    <!-- Floating Launcher Button -->
    <button class="cp-launcher" id="cp-launcher-btn" aria-label="Open AI Assistant">
      <!-- Launcher: Cai logo -->
      <div class="cp-launcher-icon">
        <img src="${baseUrl}/assets/logo-black.png" class="cp-launcher-logo" alt="Cai" />
      </div>
      <!-- Open State: Crisp Dark Down-Chevron (media_1790678123351.png) -->
      <div class="cp-launcher-chevron">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#111111" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="6 9 12 15 18 9"></polyline>
        </svg>
      </div>
      <!-- Subtle Notification Dot -->
      <div class="cp-launcher-badge" id="cp-launcher-badge"></div>
    </button>

    <!-- Proactive Teaser Preview Bubble (media_1790706499005.png) -->
    <div class="cp-teaser" id="cp-teaser-bubble" role="alert" aria-live="polite">
      <button class="cp-teaser-close" id="cp-teaser-close" title="Dismiss" aria-label="Dismiss">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <line x1="18" y1="6" x2="6" y2="18"></line>
          <line x1="6" y1="6" x2="18" y2="18"></line>
        </svg>
      </button>

      <div class="cp-teaser-content">
        <div class="cp-teaser-logo-box">
          <img src="${baseUrl}/assets/logo-black.png" class="cp-teaser-logo" alt="Cai" />
        </div>
        <div class="cp-teaser-body">
          <div class="cp-teaser-header">
            Hi there
          </div>
          <div class="cp-teaser-msg" id="cp-teaser-msg">
            You are now speaking with Cai. How can I help?
          </div>
          <div class="cp-teaser-meta">
            <span id="cp-teaser-author">Cai</span>
            <span>•</span>
            <span>Just now</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Main Chat Window (Intercom Cai UI) -->
    <div class="cp-window screen-home" id="cp-chat-window" role="dialog" aria-modal="true" aria-label="Chat with Cai">
      
      <!-- Top Header -->
      <header class="cp-header">
        <div class="cp-header-left">
          <button class="cp-back-btn" id="cp-back-btn" aria-label="Back" style="display: none;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="15 18 9 12 15 6"></polyline>
            </svg>
          </button>
          
          <!-- Cai Brand Logo -->
          <img src="${baseUrl}/assets/logo-black.png" class="cp-brand-logo" alt="Cai" />

          <div class="cp-header-title-box">
            <div class="cp-header-title" id="cp-header-assistant-name">Cai</div>
            <div class="cp-header-subtitle" id="cp-header-subtitle"></div>
          </div>
        </div>

        <div class="cp-header-actions">
          <!-- Overlapping Executive Avatars with LinkedIn (media_1790919508920.png) -->
          <div class="cp-team-avatar-stack" id="cp-team-avatar-stack" aria-label="Executive leadership and AI team">
            <a href="https://linkedin.com/in/ayushman-varma" target="_blank" rel="noopener noreferrer" class="cp-stack-avatar-link" id="cp-avatar-ayush-link" title="Ayush (Founder) — View LinkedIn Profile">
              <img class="cp-stack-avatar" src="${baseUrl}/assets/avatar-ayush.png" alt="Ayush" onerror="this.src='${baseUrl}/assets/uploads/avatars/avatar_default.svg';" />
            </a>
            <a href="https://linkedin.com/company/cuboidpilot" target="_blank" rel="noopener noreferrer" class="cp-stack-avatar-link" id="cp-avatar-cai-link" title="Cai (AI Agent) — View LinkedIn Profile">
              <img class="cp-stack-avatar" src="${baseUrl}/assets/avatar-cai.png" alt="Cai" onerror="this.src='${baseUrl}/assets/logo-white.png';" />
            </a>
            <a href="https://linkedin.com/company/cuboidsoft" target="_blank" rel="noopener noreferrer" class="cp-stack-avatar-link" id="cp-avatar-cuboidsoft-link" title="CuboidSoft — View LinkedIn Profile">
              <img class="cp-stack-avatar" src="${baseUrl}/assets/avatar-cuboidsoft.png" alt="CuboidSoft" onerror="this.src='${baseUrl}/assets/cuboidsoft-cube-logo.png';" />
            </a>
          </div>

          <button class="cp-icon-btn" id="cp-options-btn" aria-label="More Options">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
              <circle cx="5" cy="12" r="2"></circle>
              <circle cx="12" cy="12" r="2"></circle>
              <circle cx="19" cy="12" r="2"></circle>
            </svg>
          </button>

          <button class="cp-icon-btn" id="cp-expand-btn" aria-label="Expand window" title="Expand window">
            <svg id="cp-expand-header-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="15 3 21 3 21 9"></polyline>
              <polyline points="9 21 3 21 3 15"></polyline>
              <line x1="21" y1="3" x2="14" y2="10"></line>
              <line x1="3" y1="21" x2="10" y2="14"></line>
            </svg>
          </button>
          
          <button class="cp-icon-btn" id="cp-close-btn" aria-label="Close Chat">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="18" y1="6" x2="6" y2="18"></line>
              <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
          </button>
        </div>

        <!-- Options Dropdown Menu -->
        <div class="cp-options-menu" id="cp-options-menu">
          <div class="cp-option-item" id="cp-menu-expand">
            <svg id="cp-expand-menu-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="15 3 21 3 21 9"></polyline>
              <polyline points="9 21 3 21 3 15"></polyline>
              <line x1="21" y1="3" x2="14" y2="10"></line>
              <line x1="3" y1="21" x2="10" y2="14"></line>
            </svg>
            <span id="cp-expand-menu-label">Expand window</span>
          </div>
          <div class="cp-option-item" id="cp-menu-theme">
            <svg id="cp-theme-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="5"></circle>
              <line x1="12" y1="1" x2="12" y2="3"></line>
              <line x1="12" y1="21" x2="12" y2="23"></line>
              <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
              <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
              <line x1="1" y1="12" x2="3" y2="12"></line>
              <line x1="21" y1="12" x2="23" y2="12"></line>
              <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
              <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
            </svg>
            <span id="cp-theme-label">Switch to Light mode</span>
          </div>
          <div class="cp-option-item" id="cp-menu-sound">
            <svg id="cp-sound-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon>
              <path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"></path>
            </svg>
            <span id="cp-sound-label">Mute sounds</span>
          </div>
          <div class="cp-option-item" id="cp-menu-clear">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18m-2 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
            <span>Restart conversation</span>
          </div>
          <div class="cp-option-item" id="cp-menu-end-chat">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="10"></circle>
              <rect x="9" y="9" width="6" height="6"></rect>
            </svg>
            <span>End conversation</span>
          </div>
          <div class="cp-option-item" id="cp-menu-whatsapp">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>
            <span>Switch to WhatsApp</span>
          </div>
        </div>
      </header>

      <!-- Message History Container (Clean minimal Intercom Cai style) -->
            <!-- Customer Action Screens View Container (w-up: Premium Customer Actions) -->
      <div class="cp-screens-view" id="cp-screens-view" style="display: flex;"></div>

      <!-- Message History Container (Clean minimal Intercom Cai style) -->
      <main class="cp-messages" id="cp-messages-container" style="display: none;">
        
        <!-- Welcome Message Bubble (Hidden in favor of dynamic starter greeting with chips) -->
        <div class="cp-msg-row ai cp-welcome-row" id="cp-welcome-row" style="display: none;">
          <div class="cp-bubble" id="cp-welcome-text">
            Hi there<br/><br/>You are now speaking with Cai. How can I help?
          </div>
          <div class="cp-meta-line">
            <span id="cp-welcome-author">Cai</span>
            <span>•</span>
            <span>AI Agent</span>
            <span>•</span>
            <span>Just now</span>
          </div>
        </div>

        <!-- Dynamic Conversation Stream inserted here -->
        <div id="cp-chat-stream"></div>

        <!-- Intercom Fin AI Skeleton Loading Bubble -->
        <div class="cp-typing-row cp-skeleton-row" id="cp-typing-indicator" style="display: none;">
          <div class="cp-typing-avatar">
            <img src="${baseUrl}/assets/logo-white.png" alt="Cai" class="cp-typing-avatar-img">
          </div>
          <div class="cp-skeleton-bubble">
            <div class="cp-skeleton-header">
              <div class="cp-typing-dots">
                <span class="cp-typing-dot"></span>
                <span class="cp-typing-dot"></span>
                <span class="cp-typing-dot"></span>
              </div>
              <span class="cp-typing-label" id="cp-typing-label">Cai is thinking...</span>
            </div>
            <div class="cp-skeleton-lines">
              <div class="cp-skeleton-line" style="width: 82%;"></div>
              <div class="cp-skeleton-line" style="width: 95%;"></div>
              <div class="cp-skeleton-line" style="width: 58%;"></div>
            </div>
          </div>
        </div>

      </main>

      <!-- Bottom Composer Container (Exact Intercom Fin design) -->
      <footer class="cp-composer-section" style="display: none;">
        <!-- Hidden file input for native attachment picking -->
        <input type="file" id="cp-file-input" style="display:none;" accept="image/png,image/jpeg,image/webp,image/gif,application/pdf,.doc,.docx,.txt,.csv" />

        <!-- Floating Emoji Picker Popover -->
        <div class="cp-popover cp-emoji-popover" id="cp-emoji-popover" style="display:none;">
          <div class="cp-popover-header">
            <span class="cp-popover-title">Insert Emoji</span>
            <button type="button" class="cp-popover-close" id="cp-emoji-close" aria-label="Close emoji picker">✕</button>
          </div>
          <div class="cp-emoji-tabs">
            <button type="button" class="cp-emoji-tab active" data-group="popular">Top</button>
            <button type="button" class="cp-emoji-tab" data-group="smileys">Smileys</button>
            <button type="button" class="cp-emoji-tab" data-group="gestures">Hands</button>
            <button type="button" class="cp-emoji-tab" data-group="business">Work</button>
          </div>
          <div class="cp-emoji-grid" id="cp-emoji-grid"></div>
        </div>

        <!-- Floating GIF Picker Popover -->
        <div class="cp-popover cp-gif-popover" id="cp-gif-popover" style="display:none;">
          <div class="cp-popover-header">
            <span class="cp-popover-title">Choose a GIF</span>
            <button type="button" class="cp-popover-close" id="cp-gif-close" aria-label="Close GIF picker">✕</button>
          </div>
          <div class="cp-gif-search-box">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input type="text" id="cp-gif-search" placeholder="Search reaction GIFs..." autocomplete="off" />
          </div>
          <div class="cp-gif-tags" id="cp-gif-tags">
            <button type="button" class="cp-gif-tag active" data-tag="all">All</button>
            <button type="button" class="cp-gif-tag" data-tag="thumbs up">Thumbs Up</button>
            <button type="button" class="cp-gif-tag" data-tag="hello">Hello</button>
            <button type="button" class="cp-gif-tag" data-tag="party">Party</button>
            <button type="button" class="cp-gif-tag" data-tag="thinking">Thinking</button>
            <button type="button" class="cp-gif-tag" data-tag="thanks">Thanks</button>
            <button type="button" class="cp-gif-tag" data-tag="applause">Clap</button>
            <button type="button" class="cp-gif-tag" data-tag="deal">Deal</button>
          </div>
          <div class="cp-gif-grid" id="cp-gif-grid"></div>
        </div>

        <div class="cp-composer-capsule">
          <!-- Voice Recording Status Pill -->
          <div class="cp-voice-indicator" id="cp-voice-indicator" style="display:none;">
            <div style="display:flex;align-items:center;gap:6px;">
              <span class="cp-voice-pulse"></span>
              <span id="cp-voice-status-text">Listening... Speak now</span>
            </div>
            <button type="button" class="cp-voice-stop-btn" id="cp-voice-stop-btn">Done</button>
          </div>

          <!-- Attachment Preview Bar -->
          <div class="cp-attach-preview-bar" id="cp-attach-preview-bar" style="display:none;">
            <div class="cp-attach-thumb" id="cp-attach-thumb"></div>
            <div class="cp-attach-info">
              <div class="cp-attach-name" id="cp-attach-name"></div>
              <div class="cp-attach-size" id="cp-attach-size"></div>
            </div>
            <button type="button" class="cp-attach-remove" id="cp-attach-remove" title="Remove attachment">✕</button>
          </div>

          <textarea 
            class="cp-input-field" 
            id="cp-input-field" 
            placeholder="Enter your Name & WhatsApp Number..." 
            rows="1"
            aria-label="Enter your Name & WhatsApp Number"
          ></textarea>

          <div class="cp-composer-toolbar">
            <div class="cp-toolbar-actions">
              <!-- Attachment button -->
              <button class="cp-tool-btn" id="cp-tool-attach" title="Attach file or image" aria-label="Attach file or image">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path>
                </svg>
              </button>

              <!-- Emoji button -->
              <button class="cp-tool-btn" id="cp-tool-emoji" title="Insert emoji" aria-label="Insert emoji">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <circle cx="12" cy="12" r="10"></circle>
                  <path d="M8 14s1.5 2 4 2 4-2 4-2"></path>
                  <line x1="9" y1="9" x2="9.01" y2="9"></line>
                  <line x1="15" y1="9" x2="15.01" y2="9"></line>
                </svg>
              </button>

              <!-- GIF button -->
              <button class="cp-tool-btn" id="cp-tool-gif" title="Choose a GIF reaction" aria-label="Choose a GIF reaction">
                <span class="cp-gif-badge">GIF</span>
              </button>

              <!-- Mic / Voice button -->
              <button class="cp-tool-btn" id="cp-tool-mic" title="Voice input (Speech to text)" aria-label="Voice input">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path>
                  <path d="M19 10v2a7 7 0 0 1-14 0v-2"></path>
                  <line x1="12" y1="19" x2="12" y2="23"></line>
                  <line x1="8" y1="23" x2="16" y2="23"></line>
                </svg>
              </button>
            </div>

            <!-- Up-arrow Send Button -->
            <button class="cp-send-btn" id="cp-send-btn" aria-label="Send message">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="19" x2="12" y2="5"></line>
                <polyline points="5 12 12 5 19 12"></polyline>
              </svg>
            </button>
          </div>
        </div>

        <!-- Privacy Disclaimer (Exact match from screenshot media_1790678123351.png) -->
        <div class="cp-disclaimer">
          By chatting with us, you agree to our <a id="cp-privacy-link" href="javascript:void(0)">Privacy Policy</a>
        </div>
      </footer>

      <!-- Bottom Navigation Bar (media_1790868630655.png) -->
      <nav class="cp-bottom-nav" id="cp-bottom-nav" aria-label="Bottom Navigation">
        <!-- 1. Home -->
        <button class="cp-nav-item active" id="cp-nav-home" data-nav="home" type="button" aria-label="Home">
          <span class="cp-nav-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M3 9.5L12 3l9 6.5V20a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9.5z"></path>
              <path d="M9 14a3 3 0 0 0 6 0"></path>
            </svg>
          </span>
          <span class="cp-nav-label">Home</span>
        </button>

        <!-- 2. Messages -->
        <button class="cp-nav-item" id="cp-nav-messages" data-nav="chat" type="button" aria-label="Messages">
          <span class="cp-nav-icon cp-nav-icon-badge-wrap">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
              <line x1="8" y1="9" x2="16" y2="9"></line>
              <line x1="8" y1="13" x2="13" y2="13"></line>
            </svg>
            <span class="cp-nav-badge" id="cp-nav-messages-badge" style="display: none;">2</span>
          </span>
          <span class="cp-nav-label">Messages</span>
        </button>

        <!-- 3. Help -->
        <button class="cp-nav-item" id="cp-nav-help" data-nav="help" type="button" aria-label="Help">
          <span class="cp-nav-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="10"></circle>
              <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
              <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
          </span>
          <span class="cp-nav-label">Help</span>
        </button>

        <!-- 4. News -->
        <button class="cp-nav-item" id="cp-nav-news" data-nav="news" type="button" aria-label="News">
          <span class="cp-nav-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M3 11v3a1 1 0 0 0 1 1h3l5 4V5L7 9H4a1 1 0 0 0-1 1z"></path>
              <path d="M19.07 4.93a10 10 0 0 1 0 14.14"></path>
            </svg>
          </span>
          <span class="cp-nav-label">News</span>
        </button>
      </nav>
    </div>
  `;

  shadow.appendChild(widgetContainer);

  // 6. Element References
  const launcherBtn = shadow.getElementById('cp-launcher-btn');
  const launcherBadge = shadow.getElementById('cp-launcher-badge');
  const teaserBubble = shadow.getElementById('cp-teaser-bubble');
  const teaserCloseBtn = shadow.getElementById('cp-teaser-close');
  const teaserMsg = shadow.getElementById('cp-teaser-msg');
  const teaserAuthor = shadow.getElementById('cp-teaser-author');
  const chatWindow = shadow.getElementById('cp-chat-window');
  const backBtn = shadow.getElementById('cp-back-btn');
  const closeBtn = shadow.getElementById('cp-close-btn');
  const optionsBtn = shadow.getElementById('cp-options-btn');
  const optionsMenu = shadow.getElementById('cp-options-menu');
  const menuSound = shadow.getElementById('cp-menu-sound');
  const menuTheme = shadow.getElementById('cp-menu-theme');
  const menuClear = shadow.getElementById('cp-menu-clear');
  const menuEndChat = shadow.getElementById('cp-menu-end-chat');
  const menuWhatsapp = shadow.getElementById('cp-menu-whatsapp');
  const inputField = shadow.getElementById('cp-input-field');
  const sendBtn = shadow.getElementById('cp-send-btn');
  const chatStream = shadow.getElementById('cp-chat-stream');
  const messagesContainer = shadow.getElementById('cp-messages-container');
  const typingIndicator = shadow.getElementById('cp-typing-indicator');
  const assistantNameEl = shadow.getElementById('cp-header-assistant-name');
  const subtitleEl = shadow.getElementById('cp-header-subtitle');
  const welcomeTextEl = shadow.getElementById('cp-welcome-text');
  const screensView = shadow.getElementById('cp-screens-view');
  const composerSection = shadow.querySelector('.cp-composer-section');
  const expandBtn = shadow.getElementById('cp-expand-btn');
  const menuExpand = shadow.getElementById('cp-menu-expand');
  const menuExpandLabel = shadow.getElementById('cp-expand-menu-label');

  const EXPAND_STORAGE_KEY = 'cp_widget_expanded_' + companyKey;
  let isExpanded = widgetStorage.getItem(EXPAND_STORAGE_KEY) === '1';

  function updateExpandState(expanded) {
    if (window.innerWidth <= 480) {
      isExpanded = false;
      if (chatWindow) chatWindow.classList.remove('expanded');
      return;
    }
    isExpanded = Boolean(expanded);
    if (!chatWindow) return;
    
    if (isExpanded) {
      chatWindow.classList.add('expanded');
    } else {
      chatWindow.classList.remove('expanded');
    }

    if (expandBtn) {
      expandBtn.setAttribute('title', isExpanded ? 'Collapse window' : 'Expand window');
      expandBtn.setAttribute('aria-label', isExpanded ? 'Collapse window' : 'Expand window');
      expandBtn.innerHTML = isExpanded 
        ? `<svg id="cp-expand-header-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="4 14 10 14 10 20"></polyline>
            <polyline points="20 10 14 10 14 4"></polyline>
            <line x1="14" y1="10" x2="21" y2="3"></line>
            <line x1="10" y1="14" x2="3" y2="21"></line>
          </svg>`
        : `<svg id="cp-expand-header-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="15 3 21 3 21 9"></polyline>
            <polyline points="9 21 3 21 3 15"></polyline>
            <line x1="21" y1="3" x2="14" y2="10"></line>
            <line x1="3" y1="21" x2="10" y2="14"></line>
          </svg>`;
    }

    if (menuExpand) {
      const menuIcon = menuExpand.querySelector('svg');
      if (menuIcon) {
        menuIcon.outerHTML = isExpanded
          ? `<svg id="cp-expand-menu-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="4 14 10 14 10 20"></polyline>
              <polyline points="20 10 14 10 14 4"></polyline>
              <line x1="14" y1="10" x2="21" y2="3"></line>
              <line x1="10" y1="14" x2="3" y2="21"></line>
            </svg>`
          : `<svg id="cp-expand-menu-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="15 3 21 3 21 9"></polyline>
              <polyline points="9 21 3 21 3 15"></polyline>
              <line x1="21" y1="3" x2="14" y2="10"></line>
              <line x1="3" y1="21" x2="10" y2="14"></line>
            </svg>`;
      }
      if (menuExpandLabel) {
        menuExpandLabel.textContent = isExpanded ? 'Collapse window' : 'Expand window';
      }
    }
  }

  function toggleExpandWindow() {
    if (window.innerWidth <= 480) return;
    isExpanded = !isExpanded;
    widgetStorage.setItem(EXPAND_STORAGE_KEY, isExpanded ? '1' : '0');
    updateExpandState(isExpanded);
    if (currentScreen === 'chat') {
      setTimeout(scrollToBottom, 150);
    }
  }

  // Restore previous expand preference if stored (only on desktop/tablet)
  if (isExpanded && window.innerWidth > 480) {
    updateExpandState(true);
  } else {
    updateExpandState(false);
  }

  window.addEventListener('resize', () => {
    if (window.innerWidth <= 480 && isExpanded) {
      updateExpandState(false);
    }
  });
  let currentScreen = 'home'; // 'home' | 'chat' | 'human-team' | 'human-chat' | 'book-team' | 'book-slots' | 'book-confirmed' | 'payment-options' | 'news'
  let screenStack = ['home'];
  let teamMembersCache = [];
  let selectedAgent = null;
  let selectedDate = null;
  let selectedSlot = null;
  let humanPollingInterval = null;
  let lastPolledMessageId = 0;
  let isHumanChatActive = false;
  let humanCountdownTimer = null;
  let humanAttemptNumber = 1;
  let humanSecondsRemaining = 30;
  let isCopilotActive = false;
  let humanInactivityTimer = null;

  function stopHumanInactivityTimer() {
    if (humanInactivityTimer) {
      clearTimeout(humanInactivityTimer);
      humanInactivityTimer = null;
    }
  }

  function resetHumanInactivityTimer() {
    stopHumanInactivityTimer();
    if (!isHumanChatActive) return;
    // 60-second bidirectional inactivity timer for human support
    humanInactivityTimer = setTimeout(() => {
      if (isHumanChatActive) {
        closeConversationSession('inactivity');
      }
    }, 60000);
  }

  function bindTap(el, fn) {
    if (!el) return;
    let startX = 0;
    let startY = 0;
    let touchMoved = false;
    let lastTapTime = 0;

    el.addEventListener('touchstart', (e) => {
      if (e.touches && e.touches[0]) {
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
      }
      touchMoved = false;
    }, { passive: true });

    el.addEventListener('touchmove', (e) => {
      if (e.touches && e.touches[0]) {
        const dx = e.touches[0].clientX - startX;
        const dy = e.touches[0].clientY - startY;
        if (Math.hypot(dx, dy) > 10) {
          touchMoved = true;
        }
      }
    }, { passive: true });

    el.addEventListener('touchend', (e) => {
      if (!touchMoved) {
        lastTapTime = Date.now();
        if (e.cancelable) e.preventDefault();
        fn(e);
      }
      touchMoved = false;
    });

    el.addEventListener('click', (e) => {
      if (Date.now() - lastTapTime < 450) {
        return; // Suppress duplicate synthesized click event following touchend
      }
      fn(e);
    });
  }

  function stopHumanCountdown() {
    if (humanCountdownTimer) {
      clearInterval(humanCountdownTimer);
      humanCountdownTimer = null;
    }
  }

  const bottomNav = shadow.getElementById('cp-bottom-nav');
  const navHomeBtn = shadow.getElementById('cp-nav-home');
  const navMessagesBtn = shadow.getElementById('cp-nav-messages');
  const navHelpBtn = shadow.getElementById('cp-nav-help');
  const navNewsBtn = shadow.getElementById('cp-nav-news');
  const navMessagesBadge = shadow.getElementById('cp-nav-messages-badge');

  function updateNavMessagesBadge() {
    if (!navMessagesBadge) return;
    try {
      const history = JSON.parse(widgetStorage.getItem(STORAGE_KEYS.MESSAGES) || '[]');
      const count = history.filter(m => m.sender === 'ai' || m.sender === 'human').length;
      if (currentScreen !== 'chat' && currentScreen !== 'human-chat') {
        navMessagesBadge.textContent = (count > 0) ? (count > 9 ? '9+' : String(count)) : '2';
        navMessagesBadge.style.display = 'flex';
      } else {
        navMessagesBadge.style.display = 'none';
      }
    } catch(e) {
      navMessagesBadge.textContent = '2';
      navMessagesBadge.style.display = 'flex';
    }
  }

  if (navHomeBtn) {
    bindTap(navHomeBtn, () => navigateTo('home'));
    navHomeBtn.addEventListener('click', () => navigateTo('home'));
  }
  if (navMessagesBtn) {
    bindTap(navMessagesBtn, () => navigateTo('chat'));
    navMessagesBtn.addEventListener('click', () => navigateTo('chat'));
  }
  if (navHelpBtn) {
    bindTap(navHelpBtn, () => navigateTo('help'));
    navHelpBtn.addEventListener('click', () => navigateTo('help'));
  }
  if (navNewsBtn) {
    bindTap(navNewsBtn, () => navigateTo('news'));
    navNewsBtn.addEventListener('click', () => navigateTo('news'));
  }

  let widgetConfig = {
    brand_name: 'Cai',
    assistant_name: 'Cai',
    greeting_heading: 'Hi there! Welcome to CuboidPilot.\n\nI am Cai, your AI assistant. How can I help your business today?',
    greeting_subheading: 'Powered By CuboidPilot',
    whatsapp_enabled: true,
    whatsapp_number: '',
    theme_mode: 'dark',
    logo_url: '',
    logo_dark_url: '',
    logo_light_url: '',
    bank_name: '',
    bank_account_holder: '',
    bank_account_no: '',
    bank_ifsc: '',
    bank_upi_id: '',
    bank_qr_url: ''
  };

  let isSending = false;

  // 6.4. Theme Engine (Dynamic Light & Dark Modes)
  let currentTheme = localStorage.getItem('cp_user_theme') || 
                     (currentScript && currentScript.getAttribute('data-theme')) || 
                     'dark';

  function getActiveLogoUrl() {
    let rawLogo = '';
    if (currentTheme === 'light') {
      rawLogo = widgetConfig.logo_light_url || widgetConfig.logo_url || widgetConfig.logo_dark_url || 'assets/logo-black.png';
    } else {
      rawLogo = widgetConfig.logo_dark_url || widgetConfig.logo_url || widgetConfig.logo_light_url || 'assets/logo-white.png';
    }
    if (!rawLogo) {
      rawLogo = (currentTheme === 'light') ? 'assets/logo-black.png' : 'assets/logo-white.png';
    }
    return (rawLogo.startsWith('http://') || rawLogo.startsWith('https://') || rawLogo.startsWith('data:'))
      ? rawLogo 
      : `${baseUrl}/${rawLogo.replace(/^\/+/, '')}`;
  }

  function updateWidgetLogo() {
    const fullLogoUrl = getActiveLogoUrl();

    const brandLogo = shadow.querySelector('.cp-brand-logo');
    if (brandLogo) brandLogo.src = fullLogoUrl;

    const teaserLogo = shadow.querySelector('.cp-teaser-logo');
    if (teaserLogo) teaserLogo.src = fullLogoUrl;

    const launcherLogo = shadow.querySelector('.cp-launcher-logo');
    if (launcherLogo) launcherLogo.src = fullLogoUrl;

    const teaserAvatar = shadow.querySelector('.cp-teaser-avatar');
    if (teaserAvatar) {
      teaserAvatar.innerHTML = `<img src="${fullLogoUrl}" alt="" style="width:100%;height:100%;object-fit:contain;border-radius:50%;" />`;
    }

    const actionAvatar = shadow.querySelector('.cp-action-avatar-img');
    if (actionAvatar && currentScreen === 'home') {
      actionAvatar.src = fullLogoUrl;
    }
  }

  function applyTheme(theme) {
    currentTheme = (theme === 'light') ? 'light' : 'dark';
    hostElement.setAttribute('data-theme', currentTheme);
    hostElement.classList.toggle('cp-theme-light', currentTheme === 'light');
    hostElement.classList.toggle('cp-theme-dark', currentTheme !== 'light');
    const isLight = (currentTheme === 'light');

    if (widgetContainer) {
      widgetContainer.setAttribute('data-theme', currentTheme);
      widgetContainer.classList.toggle('theme-light', isLight);
      widgetContainer.classList.toggle('cp-light-theme', isLight);
      widgetContainer.classList.toggle('theme-dark', !isLight);
      widgetContainer.classList.toggle('cp-dark-theme', !isLight);
    }
    if (chatWindow) {
      chatWindow.classList.toggle('cp-light-theme', isLight);
      chatWindow.classList.toggle('theme-light', isLight);
      chatWindow.classList.toggle('cp-dark-theme', !isLight);
      chatWindow.classList.toggle('theme-dark', !isLight);
    }
    if (teaserBubble) {
      teaserBubble.classList.toggle('cp-light-theme', isLight);
      teaserBubble.classList.toggle('theme-light', isLight);
      teaserBubble.classList.toggle('cp-dark-theme', !isLight);
      teaserBubble.classList.toggle('theme-dark', !isLight);
    }
    if (launcherBtn) {
      launcherBtn.classList.toggle('cp-light-theme', isLight);
      launcherBtn.classList.toggle('theme-light', isLight);
      launcherBtn.classList.toggle('cp-dark-theme', !isLight);
      launcherBtn.classList.toggle('theme-dark', !isLight);
    }

    try {
      localStorage.setItem('cp_user_theme', currentTheme);
    } catch(e) {}

    updateWidgetLogo();

    const label = shadow.getElementById('cp-theme-label');
    const icon = shadow.getElementById('cp-theme-icon');
    if (label) {
      label.textContent = isLight ? 'Switch to Dark mode' : 'Switch to Light mode';
    }
    if (icon) {
      if (isLight) {
        icon.innerHTML = '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>';
      } else {
        icon.innerHTML = `
          <circle cx="12" cy="12" r="5"></circle>
          <line x1="12" y1="1" x2="12" y2="3"></line>
          <line x1="12" y1="21" x2="12" y2="23"></line>
          <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
          <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
          <line x1="1" y1="12" x2="3" y2="12"></line>
          <line x1="21" y1="12" x2="23" y2="12"></line>
          <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
          <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
        `;
      }
    }
  }

  function toggleTheme() {
    try {
      widgetStorage.setItem('cp_theme_user_manual', '1');
      localStorage.setItem('cp_theme_user_manual', '1');
    } catch(e) {}
    applyTheme(currentTheme === 'light' ? 'dark' : 'light');
  }

  // 6.5. Sound Synthesizer & Audio Engine (iOS Send Swoosh & Luxury Chimes)
  let audioContextInstance = null;
  let hasChimed = false;

  function isSoundEnabled() {
    return localStorage.getItem('cp_sound_enabled') !== '0';
  }

  function getAudioContext() {
    try {
      if (!audioContextInstance) {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (AudioCtx) {
          audioContextInstance = new AudioCtx();
        }
      }
      if (audioContextInstance && audioContextInstance.state === 'suspended') {
        audioContextInstance.resume().catch(() => {});
      }
      return audioContextInstance;
    } catch (e) {
      return null;
    }
  }

  // Authentic iOS Message Sent Sound ("Swoosh / Tactile Pop")
  function playSentSound() {
    if (!isSoundEnabled()) return;
    try {
      const ctx = getAudioContext();
      if (!ctx) return;
      const now = ctx.currentTime;

      // 1. Aerodynamic Swoosh (Bandpass noise sweep upward)
      const bufferSize = Math.floor(ctx.sampleRate * 0.16);
      const buffer = ctx.createBuffer(1, bufferSize, ctx.sampleRate);
      const data = buffer.getChannelData(0);
      for (let i = 0; i < bufferSize; i++) {
        data[i] = (Math.random() * 2 - 1) * Math.pow(1 - (i / bufferSize), 1.8);
      }

      const noiseSource = ctx.createBufferSource();
      noiseSource.buffer = buffer;

      const filter = ctx.createBiquadFilter();
      filter.type = 'bandpass';
      filter.Q.setValueAtTime(2.8, now);
      filter.frequency.setValueAtTime(420, now);
      filter.frequency.exponentialRampToValueAtTime(2900, now + 0.12);

      const noiseGain = ctx.createGain();
      noiseGain.gain.setValueAtTime(0, now);
      noiseGain.gain.linearRampToValueAtTime(0.18, now + 0.02);
      noiseGain.gain.exponentialRampToValueAtTime(0.0001, now + 0.15);

      noiseSource.connect(filter);
      filter.connect(noiseGain);
      noiseGain.connect(ctx.destination);

      noiseSource.start(now);

      // 2. Tactile Rising Pop (Sine pitch bend 350Hz -> 840Hz)
      const osc = ctx.createOscillator();
      const oscGain = ctx.createGain();

      osc.type = 'sine';
      osc.frequency.setValueAtTime(350, now);
      osc.frequency.exponentialRampToValueAtTime(840, now + 0.09);

      oscGain.gain.setValueAtTime(0, now);
      oscGain.gain.linearRampToValueAtTime(0.14, now + 0.02);
      oscGain.gain.exponentialRampToValueAtTime(0.0001, now + 0.13);

      osc.connect(oscGain);
      oscGain.connect(ctx.destination);

      osc.start(now);
      osc.stop(now + 0.14);
    } catch (e) {
      console.debug('Sent sound silent fallback:', e);
    }
  }

  // iOS / Luxury Incoming Message Sound ("Pop / Soft Chime")
  function playReceivedSound() {
    if (!isSoundEnabled()) return;
    try {
      const ctx = getAudioContext();
      if (!ctx) return;
      const now = ctx.currentTime;

      const playTone = (freq, startOffset, duration, peakGain) => {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();

        osc.type = 'sine';
        osc.frequency.setValueAtTime(freq, now + startOffset);

        gain.gain.setValueAtTime(0, now + startOffset);
        gain.gain.linearRampToValueAtTime(peakGain, now + startOffset + 0.015);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + startOffset + duration);

        osc.connect(gain);
        gain.connect(ctx.destination);

        osc.start(now + startOffset);
        osc.stop(now + startOffset + duration);
      };

      // Dual warm droplet pop
      playTone(784.00, 0, 0.22, 0.09);       // G5
      playTone(1046.50, 0.06, 0.35, 0.08);   // C6
    } catch (e) {
      console.debug('Received sound silent fallback:', e);
    }
  }

  // Pleasant, studio-grade soft notification chime for proactive bubble
  function playNotificationChime() {
    if (!isSoundEnabled()) return;
    try {
      const ctx = getAudioContext();
      if (!ctx) return;
      const now = ctx.currentTime;

      const playTone = (freq, startOffset, duration, peakGain) => {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();

        osc.type = 'sine';
        osc.frequency.setValueAtTime(freq, now + startOffset);

        gain.gain.setValueAtTime(0, now + startOffset);
        gain.gain.linearRampToValueAtTime(peakGain, now + startOffset + 0.015);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + startOffset + duration);

        osc.connect(gain);
        gain.connect(ctx.destination);

        osc.start(now + startOffset);
        osc.stop(now + startOffset + duration);
      };

      playTone(659.25, 0, 0.38, 0.08);       // E5
      playTone(987.77, 0.07, 0.50, 0.09);     // B5
      playTone(1318.51, 0.15, 0.65, 0.05);   // E6
      hasChimed = true;
    } catch (e) {
      console.debug('Notification chime silent fallback:', e);
    }
  }

  // Executive / High-End Studio Ambient Chime (Pure Acoustic Harmonics, No Harsh Sci-Fi Ramps)
  function playAssistantWakeSound() {
    if (!isSoundEnabled()) return;
    try {
      const ctx = getAudioContext();
      if (!ctx) return;
      const now = ctx.currentTime;

      // Pure acoustic harmonic notes: C5 (523.25 Hz) followed by G5 (783.99 Hz)
      // Natural marimba / glass bell acoustic decay with warm filtering
      const notes = [
        { freq: 261.63, delay: 0.00, duration: 0.55, peak: 0.045 },  // C4 warm resonant body
        { freq: 523.25, delay: 0.00, duration: 0.48, peak: 0.075 },  // C5 primary chime
        { freq: 783.99, delay: 0.065, duration: 0.52, peak: 0.060 }, // G5 soft harmonic bell
        { freq: 1046.50, delay: 0.075, duration: 0.35, peak: 0.022 } // C6 airy sparkle
      ];

      notes.forEach(({ freq, delay, duration, peak }) => {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        const filter = ctx.createBiquadFilter();

        osc.type = 'sine';
        osc.frequency.setValueAtTime(freq, now + delay);

        // Warm acoustic filter prevents any harshness or digital clipping
        filter.type = 'lowpass';
        filter.frequency.setValueAtTime(2200, now + delay);

        // Soft, smooth bell envelope (no abrupt clicks, no laser pitch ramps)
        gain.gain.setValueAtTime(0, now + delay);
        gain.gain.linearRampToValueAtTime(peak, now + delay + 0.028);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + delay + duration);

        osc.connect(filter);
        filter.connect(gain);
        gain.connect(ctx.destination);

        osc.start(now + delay);
        osc.stop(now + delay + duration + 0.05);
      });
    } catch (e) {
      console.debug('Assistant wake sound fallback:', e);
    }
  }

  // 6.6. Screen Sonic Wave Engine (Silky, ethereal ripple expanding from launcher)
  // Modular & cleanly removable without breaking any core widget features
  let isWaveAnimationEnabled = true;

  function triggerAssistantWave() {
    if (!isWaveAnimationEnabled) return;
    try {
      // Remove any existing active wave to prevent stacking
      const existing = shadow.querySelector('.cp-screen-wave-container');
      if (existing) existing.remove();

      const waveContainer = document.createElement('div');
      waveContainer.className = 'cp-screen-wave-container';
      waveContainer.setAttribute('aria-hidden', 'true');
      waveContainer.innerHTML = `
        <div class="cp-wave-bloom cp-wave-bloom-primary"></div>
        <div class="cp-wave-bloom cp-wave-bloom-ambient"></div>
      `;
      shadow.appendChild(waveContainer);

      // Cleanly remove from DOM once the smooth animation finishes
      setTimeout(() => {
        if (waveContainer && waveContainer.parentNode) {
          waveContainer.remove();
        }
      }, 1450);
    } catch (e) {
      console.debug('Assistant wave effect fallback:', e);
    }
  }

  function toggleSound() {
    const current = isSoundEnabled();
    localStorage.setItem('cp_sound_enabled', current ? '0' : '1');
    updateSoundUi();
    if (!current) {
      playSentSound();
    }
  }

  function updateSoundUi() {
    const enabled = isSoundEnabled();
    const label = shadow.getElementById('cp-sound-label');
    const icon = shadow.getElementById('cp-sound-icon');
    if (label) {
      label.textContent = enabled ? 'Mute sounds' : 'Enable sounds';
    }
    if (icon) {
      if (enabled) {
        icon.innerHTML = `
          <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon>
          <path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"></path>
        `;
      } else {
        icon.innerHTML = `
          <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon>
          <line x1="23" y1="9" x2="17" y2="15"></line>
          <line x1="17" y1="9" x2="23" y2="15"></line>
        `;
      }
    }
  }

  function dismissTeaser(persist) {
    if (teaserBubble) {
      teaserBubble.classList.remove('show');
    }
    if (launcherBadge) {
      launcherBadge.style.display = 'none';
    }
    if (persist) {
      widgetStorage.setItem('cp_teaser_dismissed', '1');
    }
  }

  function initTeaserNotification() {
    // Kept calm and clean: never auto-open or play unprompted sound chimes.
    // Subtle indicator on launcher button only if unread messages exist.
    if (chatWindow && chatWindow.classList.contains('open')) return;
    try {
      const history = JSON.parse(widgetStorage.getItem(STORAGE_KEYS.MESSAGES) || '[]');
      if (history.length > 0 && launcherBadge) {
        launcherBadge.style.display = 'flex';
      }
    } catch(e) {}
  }

  // 7. Toggle Open/Close
  function toggleWidget(forceState) {
    if (isEmbedded) {
      chatWindow.classList.add('open');
      launcherBtn.classList.remove('open');
      return;
    }
    const wasOpen = chatWindow.classList.contains('open');
    const isOpen = typeof forceState === 'boolean' ? forceState : !wasOpen;
    const isMobile = window.innerWidth <= 640;

    if (isOpen) {
      chatWindow.classList.add('open');
      launcherBtn.classList.add('open');
      if (isMobile) {
        launcherBtn.classList.add('widget-open');
        document.body.style.overflow = 'hidden';
      }
      dismissTeaser(false);
      if (currentScreen === 'home') {
        if (screensView) screensView.style.display = 'flex';
        if (messagesContainer) messagesContainer.style.display = 'none';
        if (composerSection) composerSection.style.display = 'none';
        renderActionHome();
      } else if ((currentScreen === 'chat' || currentScreen === 'human-chat') && !isMobile) {
        setTimeout(() => inputField && inputField.focus(), 150);
        scrollToBottom();
      }

      // Trigger dynamic data load on home screen if opening for first time
      if (currentScreen === 'home' && typeof loadHomeDynamicData === 'function') {
        loadHomeDynamicData();
      }

      // Play ChatGPT wake sound and trigger screen wave when opening
      if (!wasOpen) {
        playAssistantWakeSound();
        triggerAssistantWave();
      }
    } else {
      chatWindow.classList.remove('open');
      launcherBtn.classList.remove('open');
      launcherBtn.classList.remove('widget-open');
      if (isMobile) {
        document.body.style.overflow = '';
      }
      optionsMenu.classList.remove('show');
    }
    widgetStorage.setItem(STORAGE_KEYS.STATE, isOpen ? '1' : '0');
  }

  bindTap(launcherBtn, () => toggleWidget());
  bindTap(backBtn, (e) => { e.stopPropagation(); handleBackNavigation(); });
  bindTap(closeBtn, (e) => { e.stopPropagation(); toggleWidget(false); });

  if (teaserBubble) {
    bindTap(teaserBubble, (e) => {
      if (e.target.closest('#cp-teaser-close')) return;
      dismissTeaser(false);
      toggleWidget(true);
    });
  }

  if (teaserCloseBtn) {
    bindTap(teaserCloseBtn, (e) => {
      e.stopPropagation();
      dismissTeaser(true);
    });
  }

  bindTap(optionsBtn, (e) => {
    e.stopPropagation();
    optionsMenu.classList.toggle('show');
  });

  const dismissOptionsMenu = (e) => {
    try {
      const path = (e.composedPath && e.composedPath()) || [];
      if (path.includes(optionsBtn) || path.includes(optionsMenu)) {
        return;
      }
    } catch(err) {}
    optionsMenu.classList.remove('show');
  };
  document.addEventListener('click', dismissOptionsMenu);
  document.addEventListener('touchend', dismissOptionsMenu, { passive: true });

  if (menuSound) {
    bindTap(menuSound, (e) => {
      e.stopPropagation();
      toggleSound();
      optionsMenu.classList.remove('show');
    });
  }

  if (menuTheme) {
    bindTap(menuTheme, (e) => {
      e.stopPropagation();
      toggleTheme();
      optionsMenu.classList.remove('show');
    });
  }

  if (expandBtn) {
    bindTap(expandBtn, (e) => {
      e.stopPropagation();
      toggleExpandWindow();
    });
  }

  if (menuExpand) {
    bindTap(menuExpand, (e) => {
      e.stopPropagation();
      toggleExpandWindow();
      optionsMenu.classList.remove('show');
    });
  }

  function resetConversationState() {
    chatStream.innerHTML = '';
    widgetStorage.removeItem(STORAGE_KEYS.MESSAGES);
    widgetStorage.removeItem(STORAGE_KEYS.CONVO_ID);
    widgetStorage.removeItem(STORAGE_KEYS.LEAD_ID);
    widgetStorage.removeItem(STORAGE_KEYS.CUSTOMER_ID);
    widgetStorage.removeItem(STORAGE_KEYS.VISITOR_NAME);
    widgetStorage.removeItem(STORAGE_KEYS.VISITOR_EMAIL);
    widgetStorage.removeItem(STORAGE_KEYS.VISITOR_PHONE);
    widgetStorage.removeItem(STORAGE_KEYS.IS_IDENTIFIED);
    widgetStorage.removeItem(STORAGE_KEYS.LEAD_STEP);
    conversationId = null;
    leadId = null;
    customerId = null;
    visitorName = '';
    visitorEmail = '';
    visitorPhone = '';
    isIdentified = false;
    leadStep = 1;
    initVisitorState();
    if (inputField) {
      inputField.placeholder = "Enter your Name & WhatsApp Number...";
      inputField.value = '';
    }
  }

  menuClear.addEventListener('click', () => {
    if (confirm('Restart conversation and start fresh?')) {
      resetConversationState();
      optionsMenu.classList.remove('show');
    }
  });

  if (menuEndChat) {
    menuEndChat.addEventListener('click', () => {
      optionsMenu.classList.remove('show');
      closeConversationSession('user_exit');
    });
  }

  menuWhatsapp.addEventListener('click', () => {
    optionsMenu.classList.remove('show');
    openWhatsAppChannel('Hello, I was speaking with Cai on your website and would like assistance.');
  });

  // =========================================================================
  // 8. RICH COMPOSER TOOLS: ATTACHMENT, EMOJI, GIF & VOICE RECOGNITION
  // =========================================================================

  const CURATED_GIFS = [
    { title: 'Thumbs Up', tag: 'thumbs up', url: 'https://media.giphy.com/media/111ebonMs90YLu/giphy.gif' },
    { title: 'Great Job', tag: 'thumbs up', url: 'https://media.giphy.com/media/l41lI4bYmcsPJX9Go/giphy.gif' },
    { title: 'Hello Wave', tag: 'hello', url: 'https://media.giphy.com/media/Nx0rz3jtxt96LMqYTx/giphy.gif' },
    { title: 'Hi There', tag: 'hello', url: 'https://media.giphy.com/media/3o7TKOCXul7ZH5q05W/giphy.gif' },
    { title: 'Celebration', tag: 'party', url: 'https://media.giphy.com/media/artj92V8o75VPL7AeQ/giphy.gif' },
    { title: 'Party Dance', tag: 'party', url: 'https://media.giphy.com/media/26tPplGWjN0xLybiU/giphy.gif' },
    { title: 'Thinking', tag: 'thinking', url: 'https://media.giphy.com/media/3o7bu3XilJ5BOiSGic/giphy.gif' },
    { title: 'Smart Thinking', tag: 'thinking', url: 'https://media.giphy.com/media/d3mlE7uhX8KFgEmY/giphy.gif' },
    { title: 'Thank You', tag: 'thanks', url: 'https://media.giphy.com/media/osAcIG4MrQnlK/giphy.gif' },
    { title: 'Grateful', tag: 'thanks', url: 'https://media.giphy.com/media/RipfZWzjUDH253nqRl/giphy.gif' },
    { title: 'Applause', tag: 'applause', url: 'https://media.giphy.com/media/l3q2XhfQ8oCkm1Ts4/giphy.gif' },
    { title: 'Bravo Clap', tag: 'applause', url: 'https://media.giphy.com/media/Swx36wwSsU49HAnIhC/giphy.gif' },
    { title: 'Deal Handshake', tag: 'deal', url: 'https://media.giphy.com/media/BPJmthQ3YRwD6QqcVD/giphy.gif' },
    { title: 'Partnership', tag: 'deal', url: 'https://media.giphy.com/media/xT8qB3utUzMWqmpH20/giphy.gif' },
    { title: 'Mind Blown', tag: 'wow', url: 'https://media.giphy.com/media/26ufdipQqU2lhNA4g/giphy.gif' },
    { title: 'Rocket Launch', tag: 'rocket', url: 'https://media.giphy.com/media/mi6DsSSNKDbUY/giphy.gif' }
  ];

  const EMOJI_GROUPS = {
    popular: ['😊', '😂', '🤣', '😍', '🥰', '🙏', '👍', '🔥', '🎉', '🚀', '❤️', '👏', '💡', '💯', '🤔', '🤩', '😎', '🤝', '✨', '🎯', '⚡', '💬', '📞', '👌'],
    smileys: ['😀', '😃', '😄', '😁', '😆', '😅', '😂', '🤣', '🙂', '😉', '😊', '😇', '🥰', '😍', '🤩', '😘', '😋', '😜', '🤪', '😎', '🤓', '🥳', '🤗', '🤔'],
    gestures: ['👍', '👎', '👌', '✌️', '🤞', '🤟', '🤘', '🤙', '👈', '👉', '👆', '👇', '☝️', '✋', '🤚', '🖐️', '🖖', '👋', '🤝', '🙏', '✍️', '👏', '🙌', '💪'],
    business: ['💼', '🏢', '📈', '📊', '💳', '🛒', '📦', '📅', '⏰', '📍', '🔗', '🛡️', '💎', '🏆', '🌟', '💡', '🎯', '🚀', '🔥', '⚡', '❓', '❗', '✅', '❌']
  };

  let pendingAttachment = null;
  let speechRecognition = null;
  let isVoiceListening = false;

  const fileInput = shadow.getElementById('cp-file-input');
  const attachBar = shadow.getElementById('cp-attach-preview-bar');
  const attachThumb = shadow.getElementById('cp-attach-thumb');
  const attachName = shadow.getElementById('cp-attach-name');
  const attachSize = shadow.getElementById('cp-attach-size');
  const attachRemove = shadow.getElementById('cp-attach-remove');

  const emojiPopover = shadow.getElementById('cp-emoji-popover');
  const emojiGrid = shadow.getElementById('cp-emoji-grid');
  const emojiClose = shadow.getElementById('cp-emoji-close');

  const gifPopover = shadow.getElementById('cp-gif-popover');
  const gifGrid = shadow.getElementById('cp-gif-grid');
  const gifSearch = shadow.getElementById('cp-gif-search');
  const gifClose = shadow.getElementById('cp-gif-close');

  const micBtn = shadow.getElementById('cp-tool-mic');
  const voiceIndicator = shadow.getElementById('cp-voice-indicator');
  const voiceStatusText = shadow.getElementById('cp-voice-status-text');
  const voiceStopBtn = shadow.getElementById('cp-voice-stop-btn');

  function hideAllPopovers() {
    if (emojiPopover) emojiPopover.style.display = 'none';
    if (gifPopover) gifPopover.style.display = 'none';
  }

  // 1. Auto-Expand Input Field & Button State
  inputField.addEventListener('input', () => {
    clearInactivityChips();
    inputField.style.height = 'auto';
    inputField.style.height = Math.min(inputField.scrollHeight, 100) + 'px';
    const hasText = inputField.value.trim().length > 0;
    if (hasText || pendingAttachment) {
      sendBtn.classList.add('active');
    } else {
      sendBtn.classList.remove('active');
    }
  });

  inputField.addEventListener('focus', () => {
    clearInactivityChips();
  });

  inputField.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSend();
    }
  });

  sendBtn.addEventListener('click', handleSend);

  // 2. ATTACHMENT HANDLER (Paperclip 📎)
  function clearPendingAttachmentUI() {
    pendingAttachment = null;
    if (fileInput) fileInput.value = '';
    if (attachBar) attachBar.style.display = 'none';
    if (attachThumb) attachThumb.innerHTML = '';
    if (attachName) attachName.textContent = '';
    if (attachSize) attachSize.textContent = '';
    if (!inputField.value.trim()) {
      sendBtn.classList.remove('active');
    }
  }

  shadow.getElementById('cp-tool-attach').addEventListener('click', (e) => {
    e.stopPropagation();
    hideAllPopovers();
    if (fileInput) fileInput.click();
  });

  if (fileInput) {
    fileInput.addEventListener('change', (e) => {
      const file = e.target.files && e.target.files[0];
      if (!file) return;

      if (file.size > 15 * 1024 * 1024) {
        alert('File size exceeds the 15MB limit.');
        fileInput.value = '';
        return;
      }

      const isImg = file.type.startsWith('image/');
      pendingAttachment = {
        file: file,
        name: file.name,
        size: file.size,
        size_formatted: formatBytes(file.size),
        type: file.type,
        is_image: isImg,
        dataUrl: null
      };

      if (attachName) attachName.textContent = file.name;
      if (attachSize) attachSize.textContent = formatBytes(file.size);

      if (isImg) {
        const reader = new FileReader();
        reader.onload = (re) => {
          pendingAttachment.dataUrl = re.target.result;
          if (attachThumb) attachThumb.innerHTML = `<img src="${re.target.result}" alt="thumb" />`;
        };
        reader.readAsDataURL(file);
      } else {
        if (attachThumb) attachThumb.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>`;
      }

      if (attachBar) attachBar.style.display = 'flex';
      sendBtn.classList.add('active');
      inputField.focus();
    });
  }

  if (attachRemove) {
    attachRemove.addEventListener('click', (e) => {
      e.stopPropagation();
      clearPendingAttachmentUI();
    });
  }

  // 3. EMOJI PICKER HANDLER (🙂 Smiley)
  function renderEmojis(group = 'popular') {
    if (!emojiGrid) return;
    const list = EMOJI_GROUPS[group] || EMOJI_GROUPS.popular;
    emojiGrid.innerHTML = list.map(em => `
      <button type="button" class="cp-emoji-btn" data-emoji="${em}" title="${em}">${em}</button>
    `).join('');

    emojiGrid.querySelectorAll('.cp-emoji-btn').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const em = btn.getAttribute('data-emoji');
        insertEmoji(em);
      });
    });
  }

  function insertEmoji(em) {
    const val = inputField.value;
    const start = inputField.selectionStart !== undefined ? inputField.selectionStart : val.length;
    const end = inputField.selectionEnd !== undefined ? inputField.selectionEnd : val.length;
    inputField.value = val.substring(0, start) + em + val.substring(end);
    inputField.selectionStart = inputField.selectionEnd = start + em.length;
    inputField.style.height = 'auto';
    inputField.style.height = Math.min(inputField.scrollHeight, 100) + 'px';
    sendBtn.classList.add('active');
    inputField.focus();
  }

  shadow.getElementById('cp-tool-emoji').addEventListener('click', (e) => {
    e.stopPropagation();
    const isVisible = emojiPopover && emojiPopover.style.display === 'flex';
    hideAllPopovers();
    if (!isVisible && emojiPopover) {
      renderEmojis('popular');
      emojiPopover.style.display = 'flex';
    }
  });

  if (emojiClose) {
    emojiClose.addEventListener('click', (e) => {
      e.stopPropagation();
      if (emojiPopover) emojiPopover.style.display = 'none';
    });
  }

  shadow.querySelectorAll('.cp-emoji-tab').forEach(tab => {
    tab.addEventListener('click', (e) => {
      e.stopPropagation();
      shadow.querySelectorAll('.cp-emoji-tab').forEach(t => t.classList.remove('active'));
      tab.classList.add('active');
      renderEmojis(tab.getAttribute('data-group'));
    });
  });

  // 4. GIF REACTION PICKER HANDLER (GIF Badge)
  function renderGifs(query = '', tag = 'all') {
    if (!gifGrid) return;
    const q = query.toLowerCase().trim();
    const filtered = CURATED_GIFS.filter(g => {
      const matchTag = (tag === 'all') || (g.tag === tag);
      const matchQuery = !q || g.title.toLowerCase().includes(q) || g.tag.toLowerCase().includes(q);
      return matchTag && matchQuery;
    });

    if (filtered.length === 0) {
      gifGrid.innerHTML = `<div style="grid-column: span 2; text-align: center; color: var(--cp-text-muted); font-size: 11px; padding: 24px 0;">No matching GIFs found. Try 'thumbs up', 'hello', or 'party'!</div>`;
      return;
    }

    gifGrid.innerHTML = filtered.map(g => `
      <div class="cp-gif-card" data-url="${escapeHtml(g.url)}" data-title="${escapeHtml(g.title)}">
        <img src="${escapeHtml(g.url)}" alt="${escapeHtml(g.title)}" loading="lazy" />
        <div class="cp-gif-label">${escapeHtml(g.title)}</div>
      </div>
    `).join('');

    gifGrid.querySelectorAll('.cp-gif-card').forEach(card => {
      card.addEventListener('click', (e) => {
        e.stopPropagation();
        const url = card.getAttribute('data-url');
        const title = card.getAttribute('data-title');
        sendDirectGif(url, title);
      });
    });
  }

  shadow.getElementById('cp-tool-gif').addEventListener('click', (e) => {
    e.stopPropagation();
    const isVisible = gifPopover && gifPopover.style.display === 'flex';
    hideAllPopovers();
    if (!isVisible && gifPopover) {
      gifPopover.style.display = 'flex';
      renderGifs('', 'all');
      if (gifSearch) {
        gifSearch.value = '';
        setTimeout(() => gifSearch.focus(), 60);
      }
    }
  });

  if (gifClose) {
    gifClose.addEventListener('click', (e) => {
      e.stopPropagation();
      if (gifPopover) gifPopover.style.display = 'none';
    });
  }

  if (gifSearch) {
    gifSearch.addEventListener('input', () => {
      const activeTag = shadow.querySelector('.cp-gif-tag.active')?.getAttribute('data-tag') || 'all';
      renderGifs(gifSearch.value, activeTag);
    });
  }

  shadow.querySelectorAll('.cp-gif-tag').forEach(tagBtn => {
    tagBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      shadow.querySelectorAll('.cp-gif-tag').forEach(t => t.classList.remove('active'));
      tagBtn.classList.add('active');
      const tag = tagBtn.getAttribute('data-tag');
      renderGifs(gifSearch ? gifSearch.value : '', tag);
    });
  });

  // Direct GIF sender
  async function sendDirectGif(url, title) {
    if (isSending) return;
    hideAllPopovers();
    if (isVoiceListening) stopVoiceRecording();

    const attachment = {
      type: 'gif',
      url: url,
      name: title,
      is_image: true
    };
    appendUserMessage('', attachment);

    const gifMessage = `[Shared a reaction GIF: "${title}"]`;

    // HUMAN LIVE CHAT MODE
    if (isHumanChatActive || currentScreen === 'human-chat') {
      try {
        await fetch(`${baseUrl}/api/widget_actions.php?action=send_human_message`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Company-Key': companyKey },
          body: JSON.stringify({
            company_key: companyKey,
            conversation_id: conversationId,
            session_token: sessionId,
            message: gifMessage
          })
        });
      } catch (e) {}
      return;
    }

    isSending = true;
    showTyping('Cai is thinking...');
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 18000);
    try {
      const res = await fetch(`${baseUrl}/api/chat.php`, {
        method: 'POST',
        signal: controller.signal,
        headers: {
          'Content-Type': 'application/json',
          'X-Company-Key': companyKey
        },
        body: JSON.stringify({
          company_key: companyKey,
          message: gifMessage,
          session_id: sessionId,
          conversation_id: conversationId,
          lead_id: leadId,
          visitor_name: visitorName,
          mode: isCopilotActive ? 'workspace_copilot' : 'visitor'
        })
      });
      clearTimeout(timeoutId);
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const data = await res.json();
      if (data.conversation_id) {
        conversationId = data.conversation_id;
        widgetStorage.setItem(STORAGE_KEYS.CONVO_ID, conversationId);
        syncConversationAcrossWindows(conversationId, sessionId);
      }
      if (data.lead_id) {
        leadId = data.lead_id;
        widgetStorage.setItem(STORAGE_KEYS.LEAD_ID, leadId);
      }
      hideTyping();
      appendAIMessage(data);
    } catch (e) {
      clearTimeout(timeoutId);
      hideTyping();
      appendAIMessage({
        reply: "Nice reaction! 😊 How can I help you today?",
        timestamp: "Just now"
      });
    } finally {
      isSending = false;
    }
  }

  // 5. VOICE INPUT / SPEECH RECOGNITION (🎙️ Mic)
  function stopVoiceRecording() {
    if (speechRecognition && isVoiceListening) {
      try { speechRecognition.stop(); } catch (e) {}
    }
    isVoiceListening = false;
    if (micBtn) micBtn.classList.remove('recording');
    if (voiceIndicator) voiceIndicator.style.display = 'none';
    inputField.placeholder = "Ask a question...";
  }

  function startVoiceRecording() {
    const SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRec) {
      alert('Speech recognition is supported in Chrome, Edge, and modern browsers over HTTPS.');
      return;
    }

    try {
      if (!speechRecognition) {
        speechRecognition = new SpeechRec();
      }
      speechRecognition.continuous = false;
      speechRecognition.interimResults = true;
      speechRecognition.lang = (navigator.language && (navigator.language.startsWith('hi') || navigator.language === 'en-IN')) ? 'en-IN' : 'en-US';

      let baseText = inputField.value;
      if (baseText && !baseText.endsWith(' ')) baseText += ' ';

      speechRecognition.onstart = () => {
        isVoiceListening = true;
        if (micBtn) micBtn.classList.add('recording');
        if (voiceIndicator) {
          voiceIndicator.style.display = 'flex';
          if (voiceStatusText) voiceStatusText.textContent = 'Listening... Speak in Hindi or English';
        }
        inputField.placeholder = '🎙️ Listening... Speak now';
      };

      speechRecognition.onresult = (event) => {
        let transcript = '';
        for (let i = event.resultIndex; i < event.results.length; ++i) {
          transcript += event.results[i][0].transcript;
        }
        inputField.value = baseText + transcript;
        inputField.style.height = 'auto';
        inputField.style.height = Math.min(inputField.scrollHeight, 100) + 'px';
        sendBtn.classList.add('active');
      };

      speechRecognition.onerror = (event) => {
        console.warn('Speech recognition status:', event.error);
        stopVoiceRecording();
      };

      speechRecognition.onend = () => {
        stopVoiceRecording();
      };

      speechRecognition.start();
    } catch (err) {
      console.warn('Speech recognition start failed:', err);
      stopVoiceRecording();
    }
  }

  micBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    hideAllPopovers();
    if (isVoiceListening) {
      stopVoiceRecording();
    } else {
      startVoiceRecording();
    }
  });

  if (voiceStopBtn) {
    voiceStopBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      stopVoiceRecording();
    });
  }

  // Dismiss popovers on outside click
  shadow.addEventListener('click', (e) => {
    const path = e.composedPath ? e.composedPath() : [];
    const isPopoverClick = path.some(el => 
      el.id === 'cp-emoji-popover' || 
      el.id === 'cp-tool-emoji' || 
      el.id === 'cp-gif-popover' || 
      el.id === 'cp-tool-gif'
    );
    if (!isPopoverClick) {
      hideAllPopovers();
    }
  });

  shadow.getElementById('cp-privacy-link').addEventListener('click', () => {
    alert('Privacy Policy:\nYour chat messages are securely transmitted and processed strictly for customer assistance. We do not sell or expose your private contact information.');
  });

  // 9. Fetch Config from backend & Live Config Customizer
  function applyWidgetConfig(cfg) {
    if (!cfg) return;
    widgetConfig = { ...widgetConfig, ...cfg };
    if (typeof cfg.enable_appointments !== 'undefined') widgetConfig.enable_appointments = cfg.enable_appointments;
    if (typeof cfg.enable_human_help !== 'undefined') widgetConfig.enable_human_help = cfg.enable_human_help;
    if (typeof cfg.enable_payments !== 'undefined') widgetConfig.enable_payments = cfg.enable_payments;
    if (typeof cfg.bank_name !== 'undefined') widgetConfig.bank_name = cfg.bank_name;
    if (typeof cfg.bank_account_no !== 'undefined') widgetConfig.bank_account_no = cfg.bank_account_no;
    if (typeof cfg.bank_ifsc !== 'undefined') widgetConfig.bank_ifsc = cfg.bank_ifsc;
    if (typeof cfg.bank_upi_id !== 'undefined') widgetConfig.bank_upi_id = cfg.bank_upi_id;
    if (typeof cfg.bank_account_holder !== 'undefined') widgetConfig.bank_account_holder = cfg.bank_account_holder;
    if (typeof cfg.bank_qr_url !== 'undefined') widgetConfig.bank_qr_url = cfg.bank_qr_url;

    const asstName = widgetConfig.assistant_name || 'Cai';
    const brandName = widgetConfig.brand_name || asstName;
    if (teaserAuthor) teaserAuthor.textContent = asstName;
    if (teaserMsg) teaserMsg.textContent = `You are now speaking with ${asstName}. How can I help?`;
    const welcomeAuthor = shadow.getElementById('cp-welcome-author');
    if (welcomeAuthor) welcomeAuthor.textContent = asstName;

    if (currentScreen === 'home') {
      if (assistantNameEl) assistantNameEl.textContent = brandName;
      if (subtitleEl) subtitleEl.textContent = 'We are online';
    } else if (currentScreen === 'chat') {
      if (assistantNameEl) assistantNameEl.textContent = asstName;
      if (subtitleEl) {
        let sub = widgetConfig.greeting_subheading || '';
        if (sub === 'The team can also help' || sub === 'Cai AI & Team') sub = '';
        subtitleEl.textContent = sub;
      }
    }

    if (welcomeTextEl && widgetConfig.greeting_heading) {
      welcomeTextEl.innerHTML = formatMarkdown(widgetConfig.greeting_heading);
    }

    if (widgetConfig.accent_color && hostElement) {
      hostElement.style.setProperty('--cp-accent-custom', widgetConfig.accent_color);
    }

    // Dynamic Cloud Theme Sync:
    // If the user has manually selected a theme, respect user's manual choice!
    const userManual = widgetStorage.getItem('cp_theme_user_manual') === '1' || localStorage.getItem('cp_theme_user_manual') === '1';
    if (!userManual) {
      if (widgetConfig.theme_mode && (widgetConfig.theme_mode === 'dark' || widgetConfig.theme_mode === 'light')) {
        applyTheme(widgetConfig.theme_mode);
      } else if (currentScript && currentScript.getAttribute('data-theme')) {
        applyTheme(currentScript.getAttribute('data-theme'));
      }
    }

    if (currentScreen === 'home' && typeof renderActionHome === 'function') {
      renderActionHome();
    }
    if (chatStream) {
      const starterRow = chatStream.querySelector('.cp-starter-welcome-row') || (!chatStream.querySelector('.cp-msg-row.user') ? chatStream.querySelector('.cp-msg-row.ai') : null);
      if (starterRow) {
        const bubble = starterRow.querySelector('.cp-bubble');
        if (bubble && typeof attachChipsToBubble === 'function') attachChipsToBubble(bubble, true);
      } else if (currentScreen === 'chat' && typeof ensureStarterQuickActionChips === 'function') {
        ensureStarterQuickActionChips();
      }
    }
  }

  async function loadConfig() {
    // 1. Check local session cache for instant startup without waiting for network
    try {
      const cached = widgetStorage.getItem('cp_cached_cfg_' + companyKey);
      if (cached) {
        const parsed = JSON.parse(cached);
        if (parsed && parsed.widget) {
          if (parsed.company) widgetConfig.company = parsed.company;
          applyWidgetConfig(parsed.widget);
        }
      }
    } catch(e) {}

    try {
      const url = `${baseUrl}/api/config.php?company_key=${encodeURIComponent(companyKey)}&_t=${Date.now()}`;
      const res = await fetch(url);
      if (!res.ok) throw new Error('Config failed');
      const data = await res.json();
      if (data && data.success && data.widget) {
        try { widgetStorage.setItem('cp_cached_cfg_' + companyKey, JSON.stringify(data)); } catch(e) {}
        if (data.company) widgetConfig.company = data.company;
        applyWidgetConfig(data.widget);
      }
    } catch (e) {
      console.warn('[CuboidPilot Widget] Config fetch note: using default Cai persona.', e);
    }
  }

  // 10. Message Rendering Helpers
  function scrollToBottom() {
    setTimeout(() => {
      messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }, 40);
  }

  function formatBytes(bytes) {
    if (!bytes || bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
  }

  function appendUserMessage(text, attachment = null, skipSave = false) {
    const row = document.createElement('div');
    row.className = 'cp-msg-row user';

    let contentHtml = '';
    if (attachment) {
      if (attachment.type === 'gif') {
        contentHtml += `
          <div class="cp-bubble-attachment-gif">
            <img src="${escapeHtml(attachment.url)}" alt="${escapeHtml(attachment.name || 'GIF')}" loading="lazy" />
          </div>
        `;
      } else if (attachment.is_image) {
        const imgSrc = attachment.display_url || attachment.url;
        contentHtml += `
          <div class="cp-bubble-attachment-img" onclick="window.open('${escapeHtml(attachment.url)}', '_blank')">
            <img src="${escapeHtml(imgSrc)}" alt="${escapeHtml(attachment.name || 'Image')}" loading="lazy" onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='inline-flex';" />
            <span class="cp-bubble-attachment-fallback" style="display:none; align-items:center; gap:6px; font-size:12px; color:inherit; text-decoration:underline; cursor:pointer;">🖼️ ${escapeHtml(attachment.name || 'Image')}</span>
          </div>
        `;
      } else {
        contentHtml += `
          <a class="cp-bubble-attachment-file" href="${escapeHtml(attachment.url)}" target="_blank" rel="noopener noreferrer" download="${escapeHtml(attachment.name || 'file')}">
            <span class="cp-bubble-file-icon">📄</span>
            <div class="cp-bubble-file-info">
              <div class="cp-bubble-file-name">${escapeHtml(attachment.name || 'Attached File')}</div>
              <div class="cp-bubble-file-meta">${escapeHtml(attachment.size_formatted || 'Document')} • Click to view</div>
            </div>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
          </a>
        `;
      }
    }

    if (text) {
      contentHtml += `${contentHtml ? '<div style="margin-top:6px;">' : ''}${escapeHtml(text)}${contentHtml ? '</div>' : ''}`;
    }

    row.innerHTML = `
      <div class="cp-bubble">${contentHtml}</div>
      <div class="cp-meta-line">
        <span>You</span>
        <span>•</span>
        <span>${skipSave ? 'Recent' : 'Just now'}</span>
      </div>
    `;
    chatStream.appendChild(row);
    scrollToBottom();
    if (!skipSave) {
      saveHistory('user', text, null, false, attachment);
      playSentSound();
    }
  }

  // Visitor Lead Capture State Initialization & Identification (Section 1)
  function renderVisitorIdentificationPrompt() {
    if (isIdentified || widgetStorage.getItem(STORAGE_KEYS.IS_IDENTIFIED) === '1') return;
    if (chatStream.querySelector('.cp-visitor-intake-card')) return;

    const card = document.createElement('div');
    card.className = 'cp-msg-row ai cp-visitor-intake-row';
    card.innerHTML = `
      <div class="cp-bubble cp-visitor-intake-card">
        <div class="cp-intake-title">Welcome! Please introduce yourself to get started:</div>
        <form class="cp-intake-form" id="cp-visitor-intake-form">
          <div class="cp-intake-field">
            <label>Your Name <span class="req">*</span></label>
            <input type="text" id="cp-intake-name" placeholder="e.g. Rahul Sharma" required autocomplete="name" />
          </div>
          <div class="cp-intake-field">
            <label>Phone Number <span class="req">*</span></label>
            <input type="tel" id="cp-intake-phone" placeholder="e.g. 9876543210" required autocomplete="tel" />
          </div>
          <div class="cp-intake-field">
            <label>Email Address <span class="opt">(Optional)</span></label>
            <input type="email" id="cp-intake-email" placeholder="e.g. rahul@example.com" autocomplete="email" />
          </div>
          <div class="cp-intake-err" id="cp-intake-error" style="display:none; color:#ef4444; font-size:12px; margin-top:2px;"></div>
          <button type="submit" class="cp-intake-submit-btn" id="cp-intake-submit">
            <span>Start Conversation</span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
          </button>
        </form>
      </div>
    `;

    chatStream.appendChild(card);
    scrollToBottom();

    const form = card.querySelector('#cp-visitor-intake-form');
    const errEl = card.querySelector('#cp-intake-error');
    const submitBtn = card.querySelector('#cp-intake-submit');

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      errEl.style.display = 'none';

      const name = card.querySelector('#cp-intake-name').value.trim();
      const phone = card.querySelector('#cp-intake-phone').value.trim();
      const email = card.querySelector('#cp-intake-email').value.trim();

      if (!name) {
        errEl.textContent = 'Please enter your name.';
        errEl.style.display = 'block';
        return;
      }
      if (!phone || phone.replace(/[^0-9]/g, '').length < 7) {
        errEl.textContent = 'Please enter a valid phone number.';
        errEl.style.display = 'block';
        return;
      }

      submitBtn.disabled = true;
      submitBtn.innerHTML = `<span>Connecting...</span>`;

      try {
        const res = await fetch(`${baseUrl}/api/visitor.php`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Company-Key': companyKey
          },
          body: JSON.stringify({
            company_key: companyKey,
            name: name,
            phone: phone,
            email: email,
            channel: 'web'
          })
        });

        const data = await res.json();
        if (!data.success) {
          throw new Error(data.error || 'Failed to initialize session');
        }

        // Store session and visitor info
        sessionId = data.session_id;
        widgetStorage.setItem(STORAGE_KEYS.SESSION_ID, sessionId);
        if (data.conversation_id) {
          conversationId = data.conversation_id;
          widgetStorage.setItem(STORAGE_KEYS.CONVO_ID, conversationId);
        }
        if (data.lead_id) {
          leadId = data.lead_id;
          widgetStorage.setItem(STORAGE_KEYS.LEAD_ID, leadId);
        }
        visitorName = name;
        visitorEmail = email || '';
        visitorPhone = phone || '';
        widgetStorage.setItem(STORAGE_KEYS.VISITOR_NAME, visitorName);
        if (visitorEmail) widgetStorage.setItem(STORAGE_KEYS.VISITOR_EMAIL, visitorEmail);
        if (visitorPhone) widgetStorage.setItem(STORAGE_KEYS.VISITOR_PHONE, visitorPhone);
        isIdentified = true;
        widgetStorage.setItem(STORAGE_KEYS.IS_IDENTIFIED, '1');

        // Remove the intake card
        card.remove();

        // Render Minimal System Confirmation (Section 1 - Intercom Fin Style)
        renderSystemConfirmation(data.system_confirmation || data.system_message || "Session created successfully\nYou can now continue your conversation.");

        if (data.reply) {
          appendAIMessage({
            reply: data.reply,
            timestamp: 'Just now'
          });
        }

        if (inputField) {
          inputField.placeholder = "Ask a question...";
          inputField.focus();
        }

      } catch (err) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = `<span>Start Conversation</span>`;
        errEl.textContent = err.message || 'Connection error. Please try again.';
        errEl.style.display = 'block';
      }
    });
  }

  function renderSystemConfirmation(msgText) {
    const row = document.createElement('div');
    row.className = 'cp-system-confirmation-row';
    const cleanText = (msgText || '')
      .replace(/^[\s✓✔\u2713\u2714\u2705\-•]+/i, '')
      .trim();
    row.innerHTML = `
      <div class="cp-system-confirmation-badge">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="20 6 9 17 4 12"></polyline>
        </svg>
        <div class="cp-sys-conf-content">${formatMarkdown(cleanText || 'Session created successfully')}</div>
      </div>
    `;
    chatStream.appendChild(row);
    scrollToBottom();
  }

  function initVisitorState() {
    if (welcomeTextEl && widgetConfig.greeting_heading) {
      welcomeTextEl.innerHTML = formatMarkdown(widgetConfig.greeting_heading);
    }
    if (inputField) {
      inputField.placeholder = "Ask a question...";
    }
  }

  function renderChatConcludedCard(whatsappUrl) {
    const existing = chatStream.querySelector('.cp-chat-ended-container');
    if (existing) {
      existing.remove();
    }

    const cleanWaNum = (widgetConfig.whatsapp_number || '').replace(/[^0-9]/g, '');
    const waLink = cleanWaNum ? (whatsappUrl || `https://wa.me/${cleanWaNum}?text=${encodeURIComponent('Hi, I was chatting with Cai on your website and would like further assistance.')}`) : null;

    const endedDiv = document.createElement('div');
    endedDiv.className = 'cp-chat-ended-container';
    endedDiv.innerHTML = `
      <div class="cp-chat-ended-divider">
        <span>Conversation concluded</span>
      </div>
      <div class="cp-chat-ended-card">
        <div class="cp-ended-title">Need further assistance or prefer speaking directly with our team?</div>
        <div class="cp-ended-actions">
          ${waLink ? `
          <a class="cp-wa-btn" href="${escapeHtml(waLink)}" target="_blank" rel="noopener noreferrer">
            <svg viewBox="0 0 24 24"><path d="M20.52 3.48A11.9 11.9 0 0 0 12.04 0C5.46 0 .1 5.36.1 11.94c0 2.1.55 4.15 1.6 5.96L0 24l6.27-1.64a11.9 11.9 0 0 0 5.77 1.48h.01c6.58 0 11.94-5.36 11.94-11.94 0-3.19-1.24-6.19-3.47-8.42z"/></svg>
            <span>Continue on WhatsApp</span>
          </a>` : ''}
          <button type="button" class="cp-ended-restart-btn" id="cp-btn-ended-human">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
            <span>Talk to Human Specialist</span>
          </button>
          <button type="button" class="cp-ended-restart-btn" id="cp-btn-ended-restart">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18m-2 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
            <span>Start new conversation</span>
          </button>
        </div>
      </div>
    `;

    chatStream.appendChild(endedDiv);

    const humanBtn = endedDiv.querySelector('#cp-btn-ended-human');
    if (humanBtn) {
      humanBtn.addEventListener('click', () => {
        initiateHumanSupportHandoff();
      });
    }

    const restartBtn = endedDiv.querySelector('#cp-btn-ended-restart');
    if (restartBtn) {
      restartBtn.addEventListener('click', () => {
        resetConversationState();
      });
    }

    if (inputField) {
      inputField.placeholder = "Chat concluded. Start a new chat anytime...";
    }
  }

  let typingTimer = null;
  function showTyping(statusText = 'Cai is thinking...') {
    if (!typingIndicator) return;
    const labelEl = shadow.getElementById('cp-typing-label');
    if (labelEl) labelEl.textContent = statusText;
    typingIndicator.style.display = 'flex';
    scrollToBottom();

    clearTimeout(typingTimer);
    typingTimer = setTimeout(() => {
      if (typingIndicator && typingIndicator.style.display !== 'none') {
        const lbl = shadow.getElementById('cp-typing-label');
        if (lbl) lbl.textContent = 'Formulating response...';
      }
    }, 1400);
  }

  function hideTyping() {
    clearTimeout(typingTimer);
    if (typingIndicator) {
      typingIndicator.style.display = 'none';
    }
  }

  function streamAIMessage(bubbleEl, fullText, onDone) {
    if (!fullText || fullText.length === 0) {
      if (onDone) onDone();
      return;
    }

    const tokens = fullText.split(/(\s+)/);
    let index = 0;
    let accumulated = '';

    const cursor = document.createElement('span');
    cursor.className = 'cp-stream-cursor';

    const tokenSpeed = Math.max(12, Math.min(24, Math.floor(450 / Math.max(tokens.length, 1))));

    function pump() {
      const count = Math.min(2, tokens.length - index);
      for (let i = 0; i < count; i++) {
        accumulated += tokens[index++];
      }

      bubbleEl.innerHTML = formatMarkdown(accumulated);
      bubbleEl.appendChild(cursor);
      scrollToBottom();

      if (index < tokens.length) {
        setTimeout(pump, tokenSpeed);
      } else {
        cursor.remove();
        bubbleEl.innerHTML = formatMarkdown(accumulated);
        scrollToBottom();
        if (onDone) onDone();
      }
    }

    pump();
  }

  function appendAIMessage(data) {
    const isHuman = Boolean(data.is_human || data.agent_name || data.sender === 'human_agent');
    const senderName = data.agent_name || (isHuman ? (selectedAgent?.name || 'Advisor') : (widgetConfig.assistant_name || 'Cai'));
    const senderRole = isHuman ? 'Human Specialist' : 'AI Agent';

    const row = document.createElement('div');
    row.className = 'cp-msg-row ai' + (isHuman ? ' human-agent-msg' : '');

    const fullText = data.reply || data.text || '';
    const bubble = document.createElement('div');
    bubble.className = 'cp-bubble';
    row.appendChild(bubble);

    const metaLine = document.createElement('div');
    metaLine.className = 'cp-meta-line';
    metaLine.innerHTML = `
      <span>${escapeHtml(senderName)}</span>
      <span>•</span>
      <span>${escapeHtml(senderRole)}</span>
      <span>•</span>
      <span>${data.timestamp || 'Just now'}</span>
    `;

    chatStream.appendChild(row);
    scrollToBottom();

    const isLive = !data.skipSound;

    function finishMessage() {
      // Contextual WhatsApp continuation:
      // Only show inside message bubble if explicitly requested during active chat (!data.chat_ended)
      if (data.whatsapp_cta && data.whatsapp_cta.show && data.whatsapp_cta.url && !data.chat_ended) {
        const ctaLabel = data.whatsapp_cta.label || 'Prefer chatting on your phone? Continue seamlessly on WhatsApp:';
        const waCard = document.createElement('div');
        waCard.className = 'cp-whatsapp-card';
        waCard.innerHTML = `
          <div class="cp-wa-text">${escapeHtml(ctaLabel)}</div>
          <a class="cp-wa-btn" href="${escapeHtml(data.whatsapp_cta.url)}" target="_blank" rel="noopener noreferrer">
            <svg viewBox="0 0 24 24"><path d="M20.52 3.48A11.9 11.9 0 0 0 12.04 0C5.46 0 .1 5.36.1 11.94c0 2.1.55 4.15 1.6 5.96L0 24l6.27-1.64a11.9 11.9 0 0 0 5.77 1.48h.01c6.58 0 11.94-5.36 11.94-11.94 0-3.19-1.24-6.19-3.47-8.42z"/></svg>
            <span>Continue on WhatsApp</span>
          </a>
        `;
        bubble.appendChild(waCard);
      }

      // Contextual Instagram continuation:
      if (data.instagram_cta && data.instagram_cta.show && data.instagram_cta.url && !data.chat_ended) {
        const igCard = document.createElement('div');
        igCard.className = 'cp-instagram-card';
        igCard.innerHTML = `
          <div class="cp-ig-text">Prefer chatting on Instagram? Continue seamlessly:</div>
          <a class="cp-ig-btn" href="${escapeHtml(data.instagram_cta.url)}" target="_blank" rel="noopener noreferrer">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line></svg>
            <span>Continue on Instagram</span>
          </a>
        `;
        bubble.appendChild(igCard);
      }

      // Proactive Email / Document Action Chip
      const isDocOffer = Boolean(
        (data.reply && /(?:email par send|email par bhej|send kar doon|bhej doon|email address share)/i.test(data.reply)) ||
        (data.shared_asset && !data.shared_asset.email_dispatched)
      );

      if (isDocOffer && !data.chat_ended) {
        const chipContainer = document.createElement('div');
        chipContainer.className = 'cp-doc-action-chips';
        chipContainer.style.cssText = 'display:flex; gap:8px; margin-top:8px; flex-wrap:wrap;';
        chipContainer.innerHTML = `
          <button type="button" class="cp-chip-btn" style="display:inline-flex; align-items:center; gap:6px; background:rgba(99,102,241,0.12); color:#6366f1; border:1px solid rgba(99,102,241,0.3); border-radius:20px; padding:6px 14px; font-size:12px; font-weight:500; cursor:pointer; transition:all 0.15s ease;">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
            <span>✉️ Haan, email par bhej do</span>
          </button>
        `;
        const emailChip = chipContainer.querySelector('.cp-chip-btn');
        if (emailChip) {
          emailChip.addEventListener('click', () => {
            emailChip.disabled = true;
            emailChip.style.opacity = '0.5';
            handleSend("Haan, email par bhej do");
          });
        }
        bubble.appendChild(chipContainer);
      }

      // Interactive Appointment Slots (Sections 8, 9, 10, 11)
      if (data.appointment_slots && Array.isArray(data.appointment_slots) && data.appointment_slots.length > 0) {
        const slotsBox = document.createElement('div');
        slotsBox.className = 'cp-appointment-slots-box';
        slotsBox.innerHTML = `
          <div class="cp-slots-header">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
            <span>Select a time for your consultation / demo:</span>
          </div>
          <div class="cp-slots-grid"></div>
        `;
        const grid = slotsBox.querySelector('.cp-slots-grid');
        data.appointment_slots.forEach(slot => {
          const pill = document.createElement('button');
          pill.type = 'button';
          pill.className = 'cp-slot-pill';
          pill.innerHTML = `<span>${escapeHtml(slot.label || slot.slot_datetime)}</span>`;
          pill.addEventListener('click', async () => {
            slotsBox.querySelectorAll('.cp-slot-pill').forEach(b => {
              b.disabled = true;
              b.style.opacity = '0.5';
            });
            pill.style.opacity = '1';
            pill.innerHTML = `<span>Confirming...</span>`;
            appendUserMessage(`Selected slot: ${slot.label || slot.slot_datetime}`);
            await confirmSlotBooking(slot.slot_datetime);
          });
          grid.appendChild(pill);
        });
        bubble.appendChild(slotsBox);
      }

      // Interactive Shared Digital Asset Card (Syllabus, Brochure, Fee Chart, PDF)
      if (data.shared_asset && data.shared_asset.title) {
        const asset = data.shared_asset;
        const assetCard = document.createElement('div');
        assetCard.className = 'cp-shared-asset-card';
        
        let catLabel = 'Document';
        if (asset.category === 'syllabus') catLabel = 'Course Syllabus';
        else if (asset.category === 'brochure') catLabel = 'Official Brochure';
        else if (asset.category === 'fee_chart') catLabel = 'Fee Schedule';
        else if (asset.category === 'curriculum') catLabel = 'Curriculum';
        else if (asset.category === 'guide') catLabel = 'Guide';

        let sizeFormatted = '';
        if (asset.file_size > 0) {
          sizeFormatted = asset.file_size > 1048576 
            ? (asset.file_size / 1048576).toFixed(1) + ' MB' 
            : (asset.file_size / 1024).toFixed(0) + ' KB';
        }

        const emailBadgeHtml = asset.email_dispatched
          ? `<div class="cp-asset-email-badge success"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg> Emailed to ${escapeHtml(asset.recipient_email || 'your inbox')}</div>`
          : (asset.recipient_email 
              ? `<div class="cp-asset-email-badge pending"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg> Email copy sent to ${escapeHtml(asset.recipient_email)}</div>` 
              : `<div class="cp-asset-email-badge prompt"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg> Reply with your email to receive a copy</div>`);

          let directDlUrl = asset.download_url || '';
          if (directDlUrl) {
            if (!directDlUrl.startsWith('http://') && !directDlUrl.startsWith('https://')) {
              const cleanPath = directDlUrl.replace(/^(\.\.\/|\/)+/, '');
              directDlUrl = `${baseUrl}/${cleanPath}`;
            }
          }

          assetCard.innerHTML = `
          <div class="cp-asset-header">
            <div class="cp-asset-icon-box">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
            </div>
            <div class="cp-asset-info">
              <div class="cp-asset-category">${escapeHtml(catLabel)}</div>
              <div class="cp-asset-title">${escapeHtml(asset.title)}</div>
              <div class="cp-asset-meta">${escapeHtml(asset.file_name || 'Document')}${sizeFormatted ? ' • ' + sizeFormatted : ''}</div>
            </div>
          </div>
          ${asset.description ? `<div class="cp-asset-desc">${escapeHtml(asset.description)}</div>` : ''}
          <div class="cp-asset-actions">
            <a class="cp-asset-download-btn" href="${escapeHtml(directDlUrl)}" target="_blank" download="${escapeHtml(asset.file_name || 'document.pdf')}" rel="noopener noreferrer">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
              <span>Download Document</span>
            </a>
          </div>
          ${emailBadgeHtml}
        `;

        const dlBtn = assetCard.querySelector('.cp-asset-download-btn');
        if (dlBtn && directDlUrl) {
          dlBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const tempA = document.createElement('a');
            tempA.href = directDlUrl;
            tempA.download = asset.file_name || 'document.pdf';
            tempA.target = '_blank';
            tempA.rel = 'noopener noreferrer';
            document.body.appendChild(tempA);
            tempA.click();
            setTimeout(() => {
              if (tempA.parentNode) tempA.parentNode.removeChild(tempA);
            }, 600);
          });
        }
        bubble.appendChild(assetCard);
      }

      // Interactive Quick Action Chips (Intercom text-button style, ZERO emojis)
      if (data.action_chips && Array.isArray(data.action_chips) && data.action_chips.length > 0 && !data.chat_ended) {
        const chipsBox = document.createElement('div');
        chipsBox.className = 'cp-action-chips-container';
        chipsBox.style.cssText = 'display:flex; flex-direction:row; flex-wrap:nowrap; overflow-x:auto; overflow-y:hidden; gap:6px; margin-top:8px; padding:4px 2px; max-width:100%; scrollbar-width:none; -webkit-overflow-scrolling:touch;';
        data.action_chips.forEach(chip => {
          // Clean emoji characters out of chip label
          let cleanLabel = (chip.label || '').replace(/[\u{1F300}-\u{1F9FF}\u{2600}-\u{26FF}\u{2700}-\u{27BF}\u{1F1E0}-\u{1F1FF}\u{1F600}-\u{1F64F}\u{1F680}-\u{1F6FF}]/gu, '').trim();
          if (!cleanLabel) cleanLabel = chip.label || '';

          const chipBtn = document.createElement('button');
          chipBtn.type = 'button';
          chipBtn.className = 'cp-action-chip-pill';
          const isDark = currentTheme === 'dark';
          const bgNormal = isDark ? '#18181b' : '#ffffff';
          const borderNormal = isDark ? '#3f3f46' : '#18181b';
          const textNormal = isDark ? '#ffffff' : '#000000';
          chipBtn.style.cssText = `display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; white-space:nowrap; background:${bgNormal}; color:${textNormal}; border:1px solid ${borderNormal}; border-radius:16px; padding:4px 11px; font-size:11px; font-weight:500; cursor:pointer; transition:all 0.15s ease; font-family:inherit; letter-spacing:-0.01em; outline:none;`;
          
          chipBtn.addEventListener('mouseenter', () => {
            chipBtn.style.background = isDark ? '#ffffff' : '#000000';
            chipBtn.style.color = isDark ? '#000000' : '#ffffff';
            chipBtn.style.borderColor = isDark ? '#ffffff' : '#000000';
          });
          chipBtn.addEventListener('mouseleave', () => {
            chipBtn.style.background = bgNormal;
            chipBtn.style.color = textNormal;
            chipBtn.style.borderColor = borderNormal;
          });

          chipBtn.innerHTML = `<span>${escapeHtml(cleanLabel)}</span>`;
          chipBtn.addEventListener('click', () => {
            chipsBox.querySelectorAll('.cp-action-chip-pill').forEach(b => {
              b.disabled = true;
              b.style.opacity = '0.5';
              b.style.pointerEvents = 'none';
            });
            executeChipAction(chip, chipBtn);
          });
          chipsBox.appendChild(chipBtn);
        });
        bubble.appendChild(chipsBox);
      }

      // Interactive Intercom-Style Product Carousel (Cai AI Commerce)
      if (data.product_cards && Array.isArray(data.product_cards) && data.product_cards.length > 0) {
        row.classList.add('has-cards');
        const carouselBox = document.createElement('div');
        carouselBox.className = 'cp-product-carousel-box';

        data.product_cards.forEach(prod => {
          const card = document.createElement('div');
          card.className = 'cp-product-card';

          const catName = escapeHtml(prod.category ? prod.category.toUpperCase() : 'PLAN');
          const durationText = prod.duration ? `<span class="cp-prod-duration-pill">${escapeHtml(prod.duration)}</span>` : '';
          const origPriceHtml = (prod.original_price_inr && prod.original_price_inr > prod.price_inr)
            ? `<span class="cp-prod-orig-price">₹${Number(prod.original_price_inr).toLocaleString('en-IN')}</span>`
            : '';
          const discBadgeHtml = (prod.discount_percent && prod.discount_percent > 0)
            ? `<span class="cp-prod-discount-pill">${prod.discount_percent}% OFF</span>`
            : '';
          const emiBadgeHtml = (prod.emi_available && prod.emi_starting_at_inr > 0)
            ? `<div class="cp-prod-emi-badge"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg> EMI from ₹${Number(prod.emi_starting_at_inr).toLocaleString('en-IN')}/mo</div>`
            : '';

          let featuresHtml = '';
          if (prod.features && Array.isArray(prod.features) && prod.features.length > 0) {
            featuresHtml = '<ul class="cp-prod-features-list">' + prod.features.slice(0, 3).map(f => `
              <li class="cp-prod-feature-item">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                <span>${escapeHtml(f)}</span>
              </li>
            `).join('') + '</ul>';
          }

          card.innerHTML = `
            <div>
              <div class="cp-card-top-meta">
                <span class="cp-prod-category-badge">${catName}</span>
                ${durationText}
              </div>
              <div class="cp-prod-title" style="margin-top:6px;">${escapeHtml(prod.name)}</div>
              <div class="cp-prod-pricing-row">
                <span class="cp-prod-price">₹${Number(prod.price_inr).toLocaleString('en-IN')}</span>
                ${origPriceHtml}
                ${discBadgeHtml}
              </div>
              ${emiBadgeHtml}
              ${featuresHtml}
            </div>
            <div class="cp-prod-actions">
              <button type="button" class="cp-prod-btn-primary cp-select-prod-btn">Enroll / Pay Now</button>
              <button type="button" class="cp-prod-btn-secondary cp-ask-prod-btn">Ask Cai</button>
            </div>
          `;

          const selectBtn = card.querySelector('.cp-select-prod-btn');
          if (selectBtn) {
            selectBtn.addEventListener('click', () => {
              navigateTo('pay-online', {
                title: prod.name,
                item_title: prod.name,
                amount: Math.round(Number(prod.price_inr) || 0)
              });
            });
          }

          const askBtn = card.querySelector('.cp-ask-prod-btn');
          if (askBtn) {
            askBtn.addEventListener('click', () => {
              handleSend(`Can you tell me more about ${prod.name}?`);
            });
          }

          carouselBox.appendChild(card);
        });

        bubble.appendChild(carouselBox);
      }

      // Interactive Zero-Cost EMI Calculator Card (Cai AI Commerce)
      if (data.emi_plans && data.emi_plans.product_name) {
        row.classList.add('has-cards');
        const emi = data.emi_plans;
        const totalAmt = Number(emi.total_amount || 0);
        const numSplits = Number(emi.num_splits || emi.duration_months || 3);
        const downPayment = Number(emi.down_payment || emi.starting_at_inr || (totalAmt > 0 ? Math.round(totalAmt / numSplits) : 0));
        const perMonth = Number(emi.per_month || (totalAmt > 0 ? Math.round((totalAmt - downPayment) / Math.max(1, numSplits - 1)) : downPayment));

        const emiCard = document.createElement('div');
        emiCard.className = 'cp-emi-card';

        let timelineRowsHtml = '';
        if (emi.schedule && Array.isArray(emi.schedule)) {
          timelineRowsHtml = '<div class="cp-emi-timeline">' + emi.schedule.map(s => `
            <div class="cp-emi-timeline-row">
              <span>${escapeHtml(s.title || 'Installment')} (${escapeHtml(s.due_date || 'Due')})</span>
              <strong>₹${Number(s.amount || perMonth).toLocaleString('en-IN')}</strong>
            </div>
          `).join('') + '</div>';
        }

        emiCard.innerHTML = `
          <div class="cp-emi-header">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
            <div class="cp-emi-title">Zero-Cost EMI Plan: ${escapeHtml(emi.product_name)}</div>
          </div>
          <div class="cp-emi-stat-grid">
            <div>
              <div class="cp-emi-stat-label">Down Payment (Upfront)</div>
              <div class="cp-emi-stat-value">₹${downPayment.toLocaleString('en-IN')}</div>
            </div>
            <div>
              <div class="cp-emi-stat-label">Monthly (${numSplits} Months)</div>
              <div class="cp-emi-stat-value">₹${perMonth.toLocaleString('en-IN')}/mo</div>
            </div>
          </div>
          ${timelineRowsHtml}
          <button type="button" class="cp-prod-btn-primary cp-confirm-emi-btn" style="width:100%;padding:9px;margin-top:2px;">
            Confirm & Pay Down Payment (₹${downPayment.toLocaleString('en-IN')})
          </button>
        `;

        const confirmBtn = emiCard.querySelector('.cp-confirm-emi-btn');
        if (confirmBtn) {
          confirmBtn.addEventListener('click', () => {
            handleSend(`I confirm the EMI plan for ${emi.product_name}. Please send payment link for ₹${downPayment}.`);
          });
        }

        bubble.appendChild(emiCard);
      }

      // Interactive Verified Payment Order Card (Cai AI Commerce)
      if (data.payment_link && data.payment_link.order_id) {
        row.classList.add('has-cards');
        const pay = data.payment_link;
        const payCard = document.createElement('div');
        payCard.className = 'cp-payment-link-card';

        const checkoutHref = pay.checkout_url || (pay.upi_intent_url || '#');
        payCard.innerHTML = `
          <div class="cp-payment-card-badge">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
            <span>Verified Secure Checkout</span>
          </div>
          <div style="font-size:13px;color:var(--cp-text-secondary, #94a3b8);">${escapeHtml(pay.purpose || 'Enrollment Payment')}</div>
          <div class="cp-payment-card-amount">₹${Number(pay.amount_inr).toLocaleString('en-IN')}</div>
          <a class="cp-payment-checkout-btn" href="${escapeHtml(checkoutHref)}" target="_blank" rel="noopener noreferrer">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
            <span>Pay Now (UPI / Card / NetBanking)</span>
          </a>
        `;

        bubble.appendChild(payCard);
      }

      row.appendChild(metaLine);

      // If chat has ended, display concluding wrap-up card at the end
      if (data.chat_ended) {
        renderChatConcludedCard(data.whatsapp_cta && data.whatsapp_cta.url ? data.whatsapp_cta.url : null);
      }

      scrollToBottom();
      saveHistory('ai', fullText, data.whatsapp_cta, data.chat_ended, null, data.action_chips);
      if (!data.skipSound) {
        playReceivedSound();
      }

      // 10-Second Inactivity Action Chips (STRICTLY PREMIUM ONLY)
      if (!data.chat_ended) {
        startInactivityCountdown();
      }
    }

    if (isLive && fullText.length > 0) {
      streamAIMessage(bubble, fullText, finishMessage);
    } else {
      bubble.innerHTML = formatMarkdown(fullText);
      finishMessage();
    }
    return row;
  }

  async function confirmSlotBooking(slotDatetime) {
    showTyping('Confirming your appointment...');
    try {
      const res = await fetch(`${baseUrl}/api/chat.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Company-Key': companyKey
        },
        body: JSON.stringify({
          company_key: companyKey,
          action: 'confirm_slot',
          slot_datetime: slotDatetime,
          session_id: sessionId,
          conversation_id: conversationId,
          lead_id: leadId,
          visitor_name: visitorName
        })
      });
      hideTyping();
      const data = await res.json();
      if (data.session_id) {
        sessionId = data.session_id;
        widgetStorage.setItem(STORAGE_KEYS.SESSION_ID, sessionId);
      }
      appendAIMessage(data);
    } catch (e) {
      hideTyping();
      appendAIMessage({
        reply: "Your appointment request has been received. Our team will verify and connect with you shortly!",
        timestamp: "Just now"
      });
    }
  }

  // Inactivity Action Chips State & Logic (10-Second Timer, Premium Exclusive)
  let inactivityChipsTimer = null;

  function clearInactivityChips() {
    if (inactivityChipsTimer) {
      clearTimeout(inactivityChipsTimer);
      inactivityChipsTimer = null;
    }
    const existing = shadow.getElementById('cp-inactivity-chips');
    if (existing) {
      existing.remove();
    }
  }

  function startInactivityCountdown() {
    clearInactivityChips();

    // STRICT CHECK: Action chips are ONLY shown for Premium subscriptions
    const hasPremium = Boolean(
      widgetConfig && (
        widgetConfig.is_premium ||
        (widgetConfig.company && widgetConfig.company.is_premium) ||
        widgetConfig.can_use_whatsapp_continuation
      )
    );

    const isEnabled = widgetConfig && widgetConfig.enable_inactivity_chips !== false;
    const hasWhatsApp = Boolean(widgetConfig && (widgetConfig.whatsapp_number || (widgetConfig.company && widgetConfig.company.whatsapp_number)));

    if (!hasPremium || !isEnabled || isHumanChatActive || currentScreen !== 'chat') {
      return;
    }

    inactivityChipsTimer = setTimeout(() => {
      // Re-verify that user has not typed or changed screen
      if (inputField && inputField.value.trim().length > 0) return;
      if (currentScreen !== 'chat' || isHumanChatActive) return;

      renderInactivityChips();
    }, 10000);
  }

  function renderInactivityChips() {
    if (shadow.getElementById('cp-inactivity-chips')) return;

    const chipsRow = document.createElement('div');
    chipsRow.id = 'cp-inactivity-chips';
    chipsRow.className = 'cp-inactivity-chips-container';

    chipsRow.innerHTML = `
      <div class="cp-inactivity-chips-title">Need help?</div>
      <div class="cp-inactivity-chips-pills">
        <button type="button" class="cp-inactivity-chip cp-chip-person" id="cp-btn-inactivity-person">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
          <span>Human</span>
        </button>
        <button type="button" class="cp-inactivity-chip cp-chip-wa" id="cp-btn-inactivity-wa">
          <svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor"><path d="M20.52 3.48A11.9 11.9 0 0 0 12.04 0C5.46 0 .1 5.36.1 11.94c0 2.1.55 4.15 1.6 5.96L0 24l6.27-1.64a11.9 11.9 0 0 0 5.77 1.48h.01c6.58 0 11.94-5.36 11.94-11.94 0-3.19-1.24-6.19-3.47-8.42z"/></svg>
          <span>WhatsApp</span>
        </button>
        <button type="button" class="cp-inactivity-chip cp-chip-end" id="cp-btn-inactivity-end">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
          <span>End Chat</span>
        </button>
      </div>
    `;

    chatStream.appendChild(chipsRow);
    scrollToBottom();

    const personBtn = chipsRow.querySelector('#cp-btn-inactivity-person');
    if (personBtn) {
      personBtn.addEventListener('click', () => {
        chipsRow.remove();
        initiateHumanSupportHandoff();
      });
    }

    const waBtn = chipsRow.querySelector('#cp-btn-inactivity-wa');
    if (waBtn) {
      waBtn.addEventListener('click', () => {
        openWhatsAppChannel('Hi, I was chatting with Cai on your website and would like to continue on WhatsApp.');
        chipsRow.remove();
      });
    }

    const endBtn = chipsRow.querySelector('#cp-btn-inactivity-end');
    if (endBtn) {
      endBtn.addEventListener('click', () => {
        chipsRow.remove();
        closeConversationSession('user_exit');
      });
    }
  }

  async function openWhatsAppChannel(customText) {
    const cleanNumber = (widgetConfig.whatsapp_number || '').replace(/[^0-9]/g, '');
    if (!cleanNumber) {
      alert('WhatsApp support is not configured for this workspace.');
      return;
    }

    let url = '';
    try {
      const res = await fetch(`${baseUrl}/api/whatsapp_hub.php?action=create_widget_handoff`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          company_key: companyKey,
          conversation_id: conversationId || 0,
          lead_id: currentLeadId || 0
        })
      });
      const data = await res.json();
      if (data && data.success && data.redirect_url) {
        url = data.redirect_url;
      }
    } catch (e) {
      console.warn('[Cai] Handoff generation fallback:', e);
    }

    if (!url) {
      const msg = encodeURIComponent(customText || 'Hi, I need assistance with Cai.');
      url = `https://api.whatsapp.com/send?phone=${cleanNumber}&text=${msg}`;
    }

    try {
      const win = window.open(url, '_blank');
      if (!win || win.closed || typeof win.closed === 'undefined') {
        const a = document.createElement('a');
        a.href = url;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        document.body.appendChild(a);
        a.click();
        setTimeout(() => a.remove(), 100);
      }
    } catch(e) {
      window.location.href = url;
    }
  }

  async function closeConversationSession(reason = 'user_exit') {
    stopHumanCountdown();
    stopHumanInactivityTimer();
    clearInactivityChips();
    isHumanChatActive = false;

    if (humanPollingInterval) {
      clearInterval(humanPollingInterval);
      humanPollingInterval = null;
    }

    const activePill = shadow.getElementById('cp-waiting-pill');
    if (activePill) activePill.remove();

    if (conversationId) {
      try {
        await fetch(`${baseUrl}/api/widget_actions.php?action=close_conversation`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Company-Key': companyKey },
          body: JSON.stringify({
            conversation_id: conversationId,
            reason: reason,
            session_token: sessionId
          })
        });
      } catch (err) {
        console.warn('[CuboidPilot Widget] Error closing conversation:', err);
      }
    }

    renderChatConcludedCard();
    scrollToBottom();
  }

  function initiateHumanSupportHandoff(targetAgent = null) {
    if (targetAgent) selectedAgent = targetAgent;

    // Prefill known values from storage or memory
    const knownName = (visitorName || widgetStorage.getItem(STORAGE_KEYS.VISITOR_NAME) || '').trim();
    const knownEmail = (visitorEmail || widgetStorage.getItem(STORAGE_KEYS.VISITOR_EMAIL) || '').trim();
    const knownPhone = (visitorPhone || widgetStorage.getItem(STORAGE_KEYS.VISITOR_PHONE) || '').trim();

    // Check if missing any essential contact information
    const needsName = !knownName || /^(hello|hi|hey|test|null|undefined)$/i.test(knownName);
    const needsPhone = !knownPhone || knownPhone.replace(/[^0-9]/g, '').length < 7;
    const needsEmail = !knownEmail || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(knownEmail);

    if (needsName || needsPhone || needsEmail) {
      renderHumanDetailsIntakeCard({
        name: needsName ? '' : knownName,
        phone: needsPhone ? '' : knownPhone,
        email: needsEmail ? '' : knownEmail,
        needsName,
        needsPhone,
        needsEmail,
        targetAgent
      });
      return;
    }

    // All details available, connect immediately without prompting again
    startInstantHumanHelpSession(targetAgent || selectedAgent);
  }

  function renderHumanDetailsIntakeCard(opts) {
    if (chatStream.querySelector('.cp-human-intake-card')) return;
    if (currentScreen !== 'chat' && currentScreen !== 'human-chat') {
      navigateTo('chat');
    }

    const card = document.createElement('div');
    card.className = 'cp-msg-row ai cp-visitor-intake-row cp-human-intake-card';
    card.innerHTML = `
      <div class="cp-bubble cp-visitor-intake-card" style="box-shadow: 0 4px 14px rgba(37,99,235,0.12); border-left: 3px solid #2563eb;">
        <div class="cp-intake-title" style="display:flex; align-items:center; gap:6px;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
          <span>Connect with Support Specialist</span>
        </div>
        <div style="font-size:12px; color:var(--cp-text-secondary); margin-bottom:10px; line-height:1.4;">
          Please confirm your details so our human team can reach you directly and review your request.
        </div>
        <form class="cp-intake-form" id="cp-human-intake-form">
          <div class="cp-intake-field" style="${opts.needsName ? '' : 'display:none;'}">
            <label>Full Name <span class="req">*</span></label>
            <input type="text" id="cp-human-intake-name" placeholder="e.g. Rahul Sharma" value="${escapeHtml(opts.name || '')}" ${opts.needsName ? 'required' : ''} autocomplete="name" />
          </div>
          <div class="cp-intake-field" style="${opts.needsPhone ? '' : 'display:none;'}">
            <label>Phone / WhatsApp Number <span class="req">*</span></label>
            <input type="tel" id="cp-human-intake-phone" placeholder="e.g. 9876543210" value="${escapeHtml(opts.phone || '')}" ${opts.needsPhone ? 'required' : ''} autocomplete="tel" />
          </div>
          <div class="cp-intake-field" style="${opts.needsEmail ? '' : 'display:none;'}">
            <label>Email Address <span class="req">*</span></label>
            <input type="email" id="cp-human-intake-email" placeholder="e.g. rahul@example.com" value="${escapeHtml(opts.email || '')}" ${opts.needsEmail ? 'required' : ''} autocomplete="email" />
          </div>
          <div class="cp-intake-err" id="cp-human-intake-error" style="display:none; color:#ef4444; font-size:12px; margin-top:2px;"></div>
          <button type="submit" class="cp-intake-submit-btn" id="cp-human-intake-submit" style="background:#2563eb; color:#ffffff; font-weight:600;">
            <span>Connect to Team</span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
          </button>
        </form>
      </div>
    `;

    chatStream.appendChild(card);
    scrollToBottom();

    const form = card.querySelector('#cp-human-intake-form');
    const errEl = card.querySelector('#cp-human-intake-error');
    const submitBtn = card.querySelector('#cp-human-intake-submit');

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      errEl.style.display = 'none';

      const finalName = (opts.needsName ? card.querySelector('#cp-human-intake-name').value.trim() : opts.name).trim();
      const finalPhone = (opts.needsPhone ? card.querySelector('#cp-human-intake-phone').value.trim() : opts.phone).trim();
      const finalEmail = (opts.needsEmail ? card.querySelector('#cp-human-intake-email').value.trim() : opts.email).trim();

      if (!finalName) {
        errEl.textContent = 'Please enter your name.';
        errEl.style.display = 'block';
        return;
      }
      if (!finalPhone || finalPhone.replace(/[^0-9]/g, '').length < 7) {
        errEl.textContent = 'Please enter a valid phone number.';
        errEl.style.display = 'block';
        return;
      }
      if (!finalEmail || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(finalEmail)) {
        errEl.textContent = 'Please enter a valid email address.';
        errEl.style.display = 'block';
        return;
      }

      submitBtn.disabled = true;
      submitBtn.innerHTML = `<span>Connecting...</span>`;

      // Save details to memory and storage
      visitorName = finalName;
      visitorPhone = finalPhone;
      visitorEmail = finalEmail;
      widgetStorage.setItem(STORAGE_KEYS.VISITOR_NAME, visitorName);
      widgetStorage.setItem(STORAGE_KEYS.VISITOR_PHONE, visitorPhone);
      widgetStorage.setItem(STORAGE_KEYS.VISITOR_EMAIL, visitorEmail);
      isIdentified = true;
      widgetStorage.setItem(STORAGE_KEYS.IS_IDENTIFIED, '1');

      card.remove();

      startInstantHumanHelpSession(opts.targetAgent || selectedAgent);
    });
  }

  // 11. Send Message to Backend (Sections 7, 8, 9 & 10)
  async function handleSend(textOverride) {
    clearInactivityChips();
    if (isSending) return;
    const text = (typeof textOverride === 'string' && textOverride.length > 0) ? textOverride.trim() : inputField.value.trim();
    if (!text && !pendingAttachment) return;

    if (!textOverride) {
      inputField.value = '';
      inputField.style.height = 'auto';
    }
    sendBtn.classList.remove('active');
    hideAllPopovers();
    if (isVoiceListening) stopVoiceRecording();

    // Process file attachment if any
    let uploadedAttachment = null;
    if (pendingAttachment) {
      const pAtt = pendingAttachment;
      clearPendingAttachmentUI();

      try {
        const formData = new FormData();
        formData.append('file', pAtt.file);
        formData.append('company_key', companyKey);

        const upRes = await fetch(`${baseUrl}/api/upload.php`, {
          method: 'POST',
          headers: { 'X-Company-Key': companyKey },
          body: formData
        });
        const upData = await upRes.json();
        if (upData && upData.success) {
          const rawUrl = upData.file_url || '';
          const fullUrl = (rawUrl.startsWith('http://') || rawUrl.startsWith('https://'))
            ? rawUrl
            : `${baseUrl}/${rawUrl.replace(/^\/+/, '')}`;
          uploadedAttachment = {
            url: fullUrl,
            display_url: pAtt.dataUrl || fullUrl,
            name: upData.file_name,
            size: upData.file_size,
            size_formatted: formatBytes(upData.file_size),
            is_image: upData.is_image
          };
        } else {
          uploadedAttachment = {
            url: pAtt.dataUrl || '',
            display_url: pAtt.dataUrl || '',
            name: pAtt.name,
            size: pAtt.size,
            size_formatted: pAtt.size_formatted,
            is_image: pAtt.is_image
          };
        }
      } catch (err) {
        console.warn('Attachment upload error:', err);
        uploadedAttachment = {
          url: pAtt.dataUrl || '',
          display_url: pAtt.dataUrl || '',
          name: pAtt.name,
          size: pAtt.size,
          size_formatted: pAtt.size_formatted,
          is_image: pAtt.is_image
        };
      }
    }

    appendUserMessage(text, uploadedAttachment);

    let payloadText = text;
    if (uploadedAttachment) {
      const attTag = `[Attached ${uploadedAttachment.is_image ? 'Image' : 'Document'}: ${uploadedAttachment.name}] (${uploadedAttachment.url})`;
      payloadText = text ? `${text}\n${attTag}` : attTag;
    }

    // HUMAN LIVE CHAT MODE (Visitor message delivered to consultant & WhatsApp dispatch)
    if (isHumanChatActive || currentScreen === 'human-chat') {
      isSending = true;
      try {
        const res = await fetch(`${baseUrl}/api/widget_actions.php?action=send_human_message`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Company-Key': companyKey },
          body: JSON.stringify({
            company_key: companyKey,
            conversation_id: conversationId,
            session_token: sessionId,
            message: payloadText
          })
        });
        const data = await res.json();
        if (data && data.conversation_id) {
          conversationId = data.conversation_id;
          widgetStorage.setItem(STORAGE_KEYS.CONVO_ID, conversationId);
        }
      } catch (e) {
        console.warn('Send human message error:', e);
      } finally {
        isSending = false;
        resetHumanInactivityTimer();
      }
      return;
    }

    // DIRECT CONVERSATIONAL AI FLOW (Grounded in Knowledge Base & Groq)
    isSending = true;
    showTyping('Cai is thinking...');
    const startTime = Date.now();

    const postChatMessage = async (attempt = 1) => {
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), 25000);
      try {
        const url = `${baseUrl}/api/chat.php`;
        const res = await fetch(url, {
          method: 'POST',
          signal: controller.signal,
          headers: {
            'Content-Type': 'application/json',
            'X-Company-Key': companyKey
          },
          body: JSON.stringify({
            company_key: companyKey,
            message: payloadText,
            session_id: sessionId,
            conversation_id: conversationId,
            lead_id: leadId,
            visitor_name: visitorName,
            mode: isCopilotActive ? 'workspace_copilot' : 'visitor'
          })
        });
        clearTimeout(timeoutId);

        if (!res.ok) throw new Error('HTTP ' + res.status);
        return await res.json();
      } catch (e) {
        clearTimeout(timeoutId);
        if (attempt <= 1) {
          await new Promise(r => setTimeout(r, 600));
          return await postChatMessage(attempt + 1);
        }
        throw e;
      }
    };

    try {
      const data = await postChatMessage();

      if (data.conversation_id) {
        conversationId = data.conversation_id;
        widgetStorage.setItem(STORAGE_KEYS.CONVO_ID, conversationId);
      }
      if (data.lead_id) {
        leadId = data.lead_id;
        widgetStorage.setItem(STORAGE_KEYS.LEAD_ID, leadId);
      }
      if (data.lead_artifact && data.lead_artifact.customer_name) {
        const cName = data.lead_artifact.customer_name;
        if (!/^(website visitor|prospect|hello|hi|hey|namaste|courses?|fee|fees|pricing|syllabus|python|java|test|null|undefined)/i.test(cName.trim())) {
          visitorName = cName;
          widgetStorage.setItem(STORAGE_KEYS.VISITOR_NAME, visitorName);
          if (inputField) {
            inputField.placeholder = "Ask a question...";
          }
        }
      }
      if (data.lead_captured) {
        isIdentified = true;
        widgetStorage.setItem(STORAGE_KEYS.IS_IDENTIFIED, '1');
      }

      // Realistic thinking pacing: 850ms - 1150ms natural pause
      const elapsed = Date.now() - startTime;
      const minThinkingTime = Math.min(1200, Math.max(850, 800 + Math.floor(Math.random() * 300)));
      const remainingWait = Math.max(0, minThinkingTime - elapsed);

      setTimeout(() => {
        hideTyping();
        if (data.success) {
          appendAIMessage(data);
          if (data.human_handoff_requested && !isHumanChatActive) {
            setTimeout(() => {
              initiateHumanSupportHandoff();
            }, 500);
          }
        } else {
          let chatError = data.error || "Main aapki query process kar raha hoon. Kripya apna sawal ek baar dobara poochein!";
          if (chatError.toLowerCase().includes('workspace')) {
            chatError = "How else can I help you today? Please feel free to ask about our services, pricing, or solutions.";
          }
          appendAIMessage({
            reply: chatError,
            timestamp: "Just now"
          });
        }
        isSending = false;
      }, remainingWait);

    } catch (err) {
      console.warn('[CuboidPilot Widget Alert] Network pause, offering human assistance:', err);
      // Clear stale conversation ID so subsequent attempt self-heals immediately
      conversationId = null;
      try { widgetStorage.removeItem(STORAGE_KEYS.CONVO_ID); } catch(e){}
      setTimeout(() => {
        hideTyping();
        appendAIMessage({
          reply: "Maaf kijiye, server se connect hone me thoda waqt lag raha hai. Aap apna sawal dobara bhej sakte hain, ya turant connect karne ke liye niche WhatsApp choose kar sakte hain!",
          whatsapp_cta: {
            show: true,
            url: widgetConfig.whatsapp_number ? `https://wa.me/${widgetConfig.whatsapp_number.replace(/[^0-9]/g, '')}?text=${encodeURIComponent('Hello, I was chatting on your website and would like assistance.')}` : '',
            label: 'Instant WhatsApp Support'
          },
          timestamp: "Just now"
        });
        isSending = false;
      }, 400);
    }
  }

  // 12. Local Storage Persistence
  function saveHistory(sender, text, whatsappCta, chatEnded, attachment = null, actionChips = null) {
    try {
      const history = JSON.parse(widgetStorage.getItem(STORAGE_KEYS.MESSAGES) || '[]');
      history.push({ sender, text, whatsappCta, chatEnded, attachment, action_chips: actionChips, timestamp: 'Just now' });
      widgetStorage.setItem(STORAGE_KEYS.MESSAGES, JSON.stringify(history.slice(-30)));
    } catch (e) {}
  }

  function restoreHistory() {
    try {
      const raw = widgetStorage.getItem(STORAGE_KEYS.MESSAGES);
      if (!raw) return;

      // Cross-tenant history protection: if visiting CuboidSoft, do not restore The Code Munk messages
      if (companyKey === 'cp_live_cuboidsoft' && raw.includes('The Code Munk')) {
        widgetStorage.removeItem(STORAGE_KEYS.MESSAGES);
        return;
      }

      const history = JSON.parse(raw || '[]');
      if (Array.isArray(history) && history.length > 0) {
        history.forEach(item => {
          if (item.sender === 'user') {
            appendUserMessage(item.text, item.attachment, true);
          } else {
            appendAIMessage({
              reply: item.text,
              timestamp: 'Recent',
              skipSound: true,
              whatsapp_cta: item.whatsappCta,
              chat_ended: item.chatEnded,
              action_chips: item.action_chips
            });
          }
        });
      }
    } catch (e) {}
  }

  // 13. Markdown & Escaping Utility
  function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;')
              .replace(/</g, '&lt;')
              .replace(/>/g, '&gt;')
              .replace(/"/g, '&quot;')
              .replace(/'/g, '&#039;');
  }

  function parseMarkdownTables(text) {
    if (!text || text.indexOf('|') === -1) return text;
    const lines = text.split('\n');
    let inTable = false;
    let headers = [];
    let rows = [];
    let output = [];

    for (let i = 0; i < lines.length; i++) {
      const line = lines[i].trim();
      const isTableRow = /^\|(.+)\|$/.test(line);
      const isSeparator = /^\|(\s*:?-+:?\s*\|)+$/.test(line);

      if (isTableRow && !isSeparator) {
        const cells = line.slice(1, -1).split('|').map(c => c.trim());
        if (!inTable) {
          const nextLine = (lines[i + 1] || '').trim();
          if (/^\|(\s*:?-+:?\s*\|)+$/.test(nextLine)) {
            inTable = true;
            headers = cells;
            rows = [];
            i++; // skip separator
            continue;
          }
        } else {
          rows.push(cells);
          continue;
        }
      }

      if (inTable) {
        output.push(renderHtmlTable(headers, rows));
        inTable = false;
        headers = [];
        rows = [];
      }
      output.push(lines[i]);
    }

    if (inTable) {
      output.push(renderHtmlTable(headers, rows));
    }

    return output.join('\n');
  }

  function renderHtmlTable(headers, rows) {
    if (!headers.length && !rows.length) return '';
    let html = '<div class="cp-table-responsive"><table class="cp-table">';
    if (headers.length > 0) {
      html += '<thead><tr>';
      headers.forEach(h => {
        html += `<th>${h}</th>`;
      });
      html += '</tr></thead>';
    }
    if (rows.length > 0) {
      html += '<tbody>';
      rows.forEach(r => {
        html += '<tr>';
        r.forEach((c) => {
          const isNumeric = /^[₹$€£]?\s*[\d,]+(\.\d+)?%?$/.test(c.trim());
          html += `<td${isNumeric ? ' style="text-align:right; font-weight:600;"' : ''}>${c}</td>`;
        });
        html += '</tr>';
      });
      html += '</tbody>';
    }
    html += '</table></div>';
    return html;
  }

  function formatMarkdown(text) {
    if (!text) return '';
    let esc = escapeHtml(text);
    esc = parseMarkdownTables(esc);
    esc = esc.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    esc = esc.replace(/\*(.*?)\*/g, '<em>$1</em>');
    esc = esc.replace(/`([^`]+)`/g, '<code style="background:rgba(100,116,139,0.15); padding:2px 6px; border-radius:4px; font-size:11px; font-family:monospace;">$1</code>');
    esc = esc.replace(/(?:^|\n)[•\-\*]\s+(.*)/g, '<br/>• $1');
    esc = esc.replace(/(?:^|\n)(\d+)\.\s+(.*)/g, '<br/><strong>$1.</strong> $2');
    esc = esc.replace(/\n/g, '<br/>');
    esc = esc.replace(/<br\s*\/?>\s*(<div class="cp-table-responsive">)/g, '$1');
    esc = esc.replace(/(<\/div>)\s*<br\s*\/?>/g, '$1');
    // Parse markdown links [Title](https://...)
    esc = esc.replace(/\[([^\]]+)\]\((https?:\/\/[^\s\)<]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer" style="display:inline-flex; align-items:center; gap:3px; color:#38bdf8; text-decoration:underline; font-weight:600;">$1 ↗</a>');
    // Standalone URLs not inside href
    esc = esc.replace(/(?<!href=")(https?:\/\/[^\s<)]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer" style="color:#60a5fa; text-decoration:underline;">$1</a>');
    return esc;
  }


  function getActiveStarterChips() {
    if (widgetConfig.quick_actions && Array.isArray(widgetConfig.quick_actions) && widgetConfig.quick_actions.length > 0) {
      return widgetConfig.quick_actions;
    }
    return [];
  }

  // Action Registry Execution Handler for Dynamic Client-Controlled Chips
  async function executeChipAction(chip, triggerBtn = null) {
    const actionType = chip.action_type || 'SEND_TEXT_RESPONSE';
    const text = chip.text || chip.label || '';

    switch (actionType) {
      case 'OPEN_CATALOG_SELECTOR':
        await renderCatalogSelectorInChat();
        break;

      case 'OPEN_CATALOG':
        if (chip.linked_catalog_id) {
          await renderCatalogItemsInChat(chip.linked_catalog_id, chip.label);
        } else {
          await renderCatalogSelectorInChat();
        }
        break;

      case 'OPEN_BROCHURE_SELECTOR':
        await renderBrochureSelectorInChat(chip.linked_category_id);
        break;

      case 'OPEN_BROCHURE':
        if (chip.action_payload && chip.action_payload.file_url) {
          window.open(chip.action_payload.file_url, '_blank');
        } else {
          await renderBrochureSelectorInChat();
        }
        break;

      case 'START_HUMAN_HANDOFF':
        appendUserMessage(chip.label || 'Connect me with a team member');
        setTimeout(() => {
          initiateHumanSupportHandoff();
        }, 300);
        break;

      case 'BOOK_APPOINTMENT':
        navigateTo('appointments');
        break;

      case 'SEND_WHATSAPP':
        if (widgetConfig.whatsapp_number) {
          const num = widgetConfig.whatsapp_number.replace(/[^0-9]/g, '');
          window.open(`https://wa.me/${num}?text=${encodeURIComponent(text)}`, '_blank');
        } else {
          handleSend(text);
        }
        break;

      case 'OPEN_URL':
        if (chip.action_payload && chip.action_payload.url) {
          window.open(chip.action_payload.url, '_blank');
        } else {
          handleSend(text);
        }
        break;

      case 'SEND_TEXT_RESPONSE':
      default:
        handleSend(text);
        break;
    }
  }

  // Render Interactive Catalog Selector in Chat Stream
  async function renderCatalogSelectorInChat() {
    appendUserMessage('Browse Catalogs');
    const loadRow = appendAIMessage({
      reply: 'Fetching available catalogs...',
      timestamp: 'Just now',
      skipSound: true
    });

    try {
      const res = await fetch(`${baseUrl}/api/widget_actions.php?action=get_catalogs&company_key=${encodeURIComponent(companyKey)}`);
      const data = await res.json();
      if (loadRow && loadRow.parentNode) loadRow.remove();

      if (data.success && Array.isArray(data.catalogs) && data.catalogs.length > 0) {
        const bubbleContent = document.createElement('div');
        bubbleContent.innerHTML = `<div style="font-weight:600; margin-bottom:8px;">Choose a catalog to explore:</div>`;
        const listDiv = document.createElement('div');
        listDiv.style.cssText = 'display:flex; flex-direction:column; gap:8px; margin-top:6px;';

        data.catalogs.forEach(cat => {
          const itemCard = document.createElement('div');
          const isDark = currentTheme === 'dark';
          itemCard.style.cssText = `background:${isDark ? 'rgba(255,255,255,0.06)' : '#f8fafc'}; border:1px solid ${isDark ? 'rgba(255,255,255,0.12)' : '#e2e8f0'}; border-radius:10px; padding:10px 12px; cursor:pointer; transition:all 0.15s ease;`;
          itemCard.innerHTML = `
            <div style="font-weight:600; font-size:13px; color:${isDark ? '#f8fafc' : '#0f172a'}; display:flex; justify-content:space-between; align-items:center;">
              <span>${escapeHtml(cat.name)}</span>
              <span style="font-size:11px; font-weight:500; color:#6366f1; background:rgba(99,102,241,0.1); padding:2px 6px; border-radius:10px;">${cat.item_count} items</span>
            </div>
            ${cat.description ? `<div style="font-size:12px; color:${isDark ? '#94a3b8' : '#64748b'}; margin-top:3px;">${escapeHtml(cat.description)}</div>` : ''}
          `;
          itemCard.addEventListener('mouseenter', () => {
            itemCard.style.borderColor = '#6366f1';
          });
          itemCard.addEventListener('mouseleave', () => {
            itemCard.style.borderColor = isDark ? 'rgba(255,255,255,0.12)' : '#e2e8f0';
          });
          itemCard.addEventListener('click', () => {
            renderCatalogItemsInChat(cat.id, cat.name);
          });
          listDiv.appendChild(itemCard);
        });

        const replyRow = appendAIMessage({
          reply: 'Here are our verified catalogs:',
          timestamp: 'Just now'
        });
        if (replyRow) {
          const bubble = replyRow.querySelector('.cp-bubble');
          if (bubble) bubble.appendChild(listDiv);
        }
      } else {
        appendAIMessage({
          reply: 'No public catalogs are currently available for this workspace.',
          timestamp: 'Just now'
        });
      }
    } catch (e) {
      if (loadRow && loadRow.parentNode) loadRow.remove();
      appendAIMessage({
        reply: 'Unable to load catalogs at this moment.',
        timestamp: 'Just now'
      });
    }
  }

  // Render Interactive Catalog Items in Chat Stream
  async function renderCatalogItemsInChat(catalogId, catalogName) {
    appendUserMessage(`View ${catalogName || 'Catalog'}`);
    const loadRow = appendAIMessage({
      reply: `Loading offerings for ${escapeHtml(catalogName || 'catalog')}...`,
      timestamp: 'Just now',
      skipSound: true
    });

    try {
      const res = await fetch(`${baseUrl}/api/widget_actions.php?action=get_catalog_items&catalog_id=${catalogId}&company_key=${encodeURIComponent(companyKey)}`);
      const data = await res.json();
      if (loadRow && loadRow.parentNode) loadRow.remove();

      if (data.success && Array.isArray(data.items) && data.items.length > 0) {
        const productCards = data.items.map(it => ({
          id: it.id,
          name: it.name,
          description: it.description,
          price_inr: it.price,
          duration: it.duration,
          features: it.features
        }));

        appendAIMessage({
          reply: `Here are the verified offerings available under **${escapeHtml(catalogName || 'this catalog')}**:`,
          product_cards: productCards,
          timestamp: 'Just now'
        });
      } else {
        appendAIMessage({
          reply: `No items found under **${escapeHtml(catalogName || 'this catalog')}** yet.`,
          timestamp: 'Just now'
        });
      }
    } catch (e) {
      if (loadRow && loadRow.parentNode) loadRow.remove();
      appendAIMessage({
        reply: 'Unable to load catalog items right now.',
        timestamp: 'Just now'
      });
    }
  }

  // Render Interactive Brochure / Asset Selector in Chat Stream
  async function renderBrochureSelectorInChat(categoryId = null) {
    appendUserMessage('View Documents & Brochures');
    const loadRow = appendAIMessage({
      reply: 'Fetching available documents...',
      timestamp: 'Just now',
      skipSound: true
    });

    try {
      let url = `${baseUrl}/api/widget_actions.php?action=get_brochures&company_key=${encodeURIComponent(companyKey)}`;
      if (categoryId) url += `&category_id=${categoryId}`;

      const res = await fetch(url);
      const data = await res.json();
      if (loadRow && loadRow.parentNode) loadRow.remove();

      if (data.success && Array.isArray(data.brochures) && data.brochures.length > 0) {
        const isDark = currentTheme === 'dark';
        const listDiv = document.createElement('div');
        listDiv.style.cssText = 'display:flex; flex-direction:column; gap:8px; margin-top:8px;';

        data.brochures.forEach(b => {
          const item = document.createElement('div');
          item.style.cssText = `background:${isDark ? 'rgba(255,255,255,0.06)' : '#f8fafc'}; border:1px solid ${isDark ? 'rgba(255,255,255,0.12)' : '#e2e8f0'}; border-radius:10px; padding:10px 12px; display:flex; justify-content:space-between; align-items:center;`;
          
          let dlUrl = b.file_url || '';
          if (dlUrl && !dlUrl.startsWith('http')) {
            dlUrl = `${baseUrl}/${dlUrl.replace(/^\/+/, '')}`;
          }

          item.innerHTML = `
            <div>
              <div style="font-weight:600; font-size:13px; color:${isDark ? '#f8fafc' : '#0f172a'};">${escapeHtml(b.title)}</div>
              ${b.description ? `<div style="font-size:11px; color:${isDark ? '#94a3b8' : '#64748b'}; margin-top:2px;">${escapeHtml(b.description)}</div>` : ''}
            </div>
            <a href="${escapeHtml(dlUrl)}" target="_blank" download style="display:inline-flex; align-items:center; gap:4px; padding:5px 10px; background:#6366f1; color:#fff; border-radius:6px; font-size:11px; font-weight:600; text-decoration:none;">
              Download
            </a>
          `;
          listDiv.appendChild(item);
        });

        const replyRow = appendAIMessage({
          reply: 'Here are the official downloadable documents and brochures:',
          timestamp: 'Just now'
        });
        if (replyRow) {
          const bubble = replyRow.querySelector('.cp-bubble');
          if (bubble) bubble.appendChild(listDiv);
        }
      } else {
        appendAIMessage({
          reply: 'No brochures or documents are currently published for this company.',
          timestamp: 'Just now'
        });
      }
    } catch (e) {
      if (loadRow && loadRow.parentNode) loadRow.remove();
      appendAIMessage({
        reply: 'Unable to retrieve brochures at this time.',
        timestamp: 'Just now'
      });
    }
  }

  function attachChipsToBubble(bubble, forceRefresh = false) {
    if (!bubble) return;
    const existing = bubble.querySelector('.cp-action-chips-container');
    if (existing) {
      if (forceRefresh) {
        existing.remove();
      } else {
        return;
      }
    }
    const starterChips = getActiveStarterChips();
    if (!starterChips || starterChips.length === 0) return;

    const chipsBox = document.createElement('div');
    chipsBox.className = 'cp-action-chips-container';
    chipsBox.style.cssText = 'display:flex; flex-direction:row; flex-wrap:nowrap; overflow-x:auto; overflow-y:hidden; gap:6px; margin-top:8px; padding:4px 2px; max-width:100%; scrollbar-width:none; -webkit-overflow-scrolling:touch;';
    starterChips.forEach(chip => {
      let cleanLabel = (chip.label || '').replace(/[\u{1F300}-\u{1F9FF}\u{2600}-\u{26FF}\u{2700}-\u{27BF}\u{1F1E0}-\u{1F1FF}\u{1F600}-\u{1F64F}\u{1F680}-\u{1F6FF}]/gu, '').trim();
      if (!cleanLabel) cleanLabel = chip.label || '';

      const chipBtn = document.createElement('button');
      chipBtn.type = 'button';
      chipBtn.className = 'cp-action-chip-pill';
      const isDark = currentTheme === 'dark';
      const bgNormal = isDark ? '#18181b' : '#ffffff';
      const borderNormal = isDark ? '#3f3f46' : '#18181b';
      const textNormal = isDark ? '#ffffff' : '#000000';
      chipBtn.style.cssText = `display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; white-space:nowrap; background:${bgNormal}; color:${textNormal}; border:1px solid ${borderNormal}; border-radius:16px; padding:4px 11px; font-size:11px; font-weight:500; cursor:pointer; transition:all 0.15s ease; font-family:inherit; letter-spacing:-0.01em; outline:none;`;

      chipBtn.addEventListener('mouseenter', () => {
        chipBtn.style.background = isDark ? '#ffffff' : '#000000';
        chipBtn.style.color = isDark ? '#000000' : '#ffffff';
        chipBtn.style.borderColor = isDark ? '#ffffff' : '#000000';
      });
      chipBtn.addEventListener('mouseleave', () => {
        chipBtn.style.background = bgNormal;
        chipBtn.style.color = textNormal;
        chipBtn.style.borderColor = borderNormal;
      });

      chipBtn.innerHTML = `<span>${escapeHtml(cleanLabel)}</span>`;
      chipBtn.addEventListener('click', () => {
        chipsBox.querySelectorAll('.cp-action-chip-pill').forEach(b => {
          b.disabled = true;
          b.style.opacity = '0.5';
          b.style.pointerEvents = 'none';
        });
        executeChipAction(chip, chipBtn);
      });
      chipsBox.appendChild(chipBtn);
    });
    bubble.appendChild(chipsBox);
  }

  function ensureStarterQuickActionChips() {
    if (!chatStream) return;

    // Hide the static template welcome row if present
    const staticWelcome = shadow.getElementById('cp-welcome-row');
    if (staticWelcome) staticWelcome.style.display = 'none';

    // Check if starter message row already exists
    const existingStarter = chatStream.querySelector('.cp-starter-welcome-row');
    if (existingStarter) {
      const bubble = existingStarter.querySelector('.cp-bubble');
      if (bubble) attachChipsToBubble(bubble, true);
      return;
    }

    // If chatStream has any AI message(s) from restored history:
    const userMsg = chatStream.querySelector('.cp-msg-row.user');
    const aiRows = chatStream.querySelectorAll('.cp-msg-row.ai');
    if (aiRows.length > 0) {
      if (!userMsg) {
        const firstAiBubble = aiRows[0].querySelector('.cp-bubble');
        if (firstAiBubble) {
          attachChipsToBubble(firstAiBubble, true);
          return;
        }
      }
      return;
    }

    const brandName = widgetConfig.brand_name || 'CuboidPilot';
    const asstName = widgetConfig.assistant_name || 'Cai';

    let greetingText = widgetConfig.greeting_heading || '';
    greetingText = greetingText.replace(/[\u{1F300}-\u{1F9FF}\u{2600}-\u{26FF}\u{2700}-\u{27BF}\u{1F1E0}-\u{1F1FF}\u{1F600}-\u{1F64F}\u{1F680}-\u{1F6FF}]/gu, '').trim();
    if (!greetingText || greetingText.length < 5) {
      greetingText = `Hello! I am **${asstName}**, the AI advisor for **${brandName}**.\n\nHow can I help you today? Tap any option below or ask any question:`;
    }

    const starterChips = getActiveStarterChips();

    const starterRow = appendAIMessage({
      reply: greetingText,
      action_chips: starterChips,
      timestamp: 'Just now',
      skipSound: true
    });
    if (starterRow) {
      starterRow.classList.add('cp-starter-welcome-row');
    }
  }

  // =========================================================================
  // w-up: SCREEN NAVIGATION ENGINE & RENDERERS
  // =========================================================================

  function navigateTo(screen, data = {}) {
    currentScreen = screen;
    if (screen === 'home') {
      isCopilotActive = false;
    }
    if (screenStack[screenStack.length - 1] !== screen) {
      screenStack.push(screen);
    }

    if (screen !== 'human-chat') {
      isHumanChatActive = false;
      stopHumanInactivityTimer();
      if (humanPollingInterval) {
        clearInterval(humanPollingInterval);
        humanPollingInterval = null;
      }
      stopHumanCountdown();
      const stalePill = shadow.getElementById('cp-waiting-pill');
      if (stalePill) stalePill.remove();
    }

    // Toggle screen classes and back button display
    const isChatFlow = (screen === 'chat' || screen === 'human-chat');
    if (chatWindow) {
      chatWindow.classList.toggle('screen-home', screen === 'home');
      chatWindow.classList.toggle('screen-chat', screen === 'chat');
      chatWindow.classList.toggle('screen-human-chat', screen === 'human-chat');
    }
    if (backBtn) {
      backBtn.style.display = (screen === 'home') ? 'none' : 'inline-flex';
      backBtn.style.visibility = (screen === 'home') ? 'hidden' : 'visible';
    }

    const bottomNav = shadow.getElementById('cp-bottom-nav');
    if (bottomNav) {
      if (screen === 'chat' || screen === 'human-chat') {
        bottomNav.style.display = 'none';
        bottomNav.style.visibility = 'hidden';
      } else {
        bottomNav.style.display = 'flex';
        bottomNav.style.visibility = 'visible';
      }
    }

    // Update Bottom Navigation active tab
    const navItems = shadow.querySelectorAll('.cp-nav-item');
    navItems.forEach(item => {
      const target = item.getAttribute('data-nav');
      if (
        (screen === 'home' && target === 'home') ||
        ((screen === 'chat' || screen === 'human-chat') && target === 'chat') ||
        ((screen === 'help' || screen === 'human-team' || screen === 'book-team' || screen === 'book-slots' || screen === 'book-confirmed') && target === 'help') ||
        (screen === 'news' && target === 'news')
      ) {
        item.classList.add('active');
      } else {
        item.classList.remove('active');
      }
    });

    updateNavMessagesBadge();

    const brandLogo = shadow.querySelector('.cp-brand-logo');

    if (screen === 'chat') {
      if (screensView) screensView.style.display = 'none';
      if (messagesContainer) messagesContainer.style.display = 'flex';
      if (composerSection) composerSection.style.display = 'block';
      if (backBtn) {
        backBtn.style.display = 'inline-flex';
        backBtn.style.visibility = 'visible';
      }

      if (isCopilotActive) {
        if (assistantNameEl) assistantNameEl.textContent = 'Workspace Copilot';
        if (subtitleEl) subtitleEl.textContent = '● Live Telemetry';
        if (inputField) inputField.placeholder = "Poochiye: Kitni lead aayi, revenue, reminder...";
      } else {
        const asstName = widgetConfig.assistant_name || 'Cai';
        if (assistantNameEl) assistantNameEl.textContent = asstName;
        if (subtitleEl) {
          let sub = widgetConfig.greeting_subheading || '';
          if (sub === 'The team can also help' || sub === 'Cai AI & Team') sub = '';
          subtitleEl.textContent = sub;
        }
        if (inputField) inputField.placeholder = "Ask a question...";
      }
      if (brandLogo) updateWidgetLogo();
      if (conversationId) {
        startHumanPolling();
      }
      ensureStarterQuickActionChips();
      scrollToBottom();
      return;
    }

    if (screen === 'human-chat') {
      if (screensView) screensView.style.display = 'none';
      if (messagesContainer) messagesContainer.style.display = 'flex';
      if (composerSection) composerSection.style.display = 'block';
      if (backBtn) {
        backBtn.style.display = 'inline-flex';
        backBtn.style.visibility = 'visible';
      }

      const agent = data.agent || selectedAgent || { name: 'Advisor', job_title: 'Consultant' };
      if (assistantNameEl) assistantNameEl.textContent = agent.name;
      if (subtitleEl) subtitleEl.textContent = '● Connecting...';
      if (inputField) inputField.placeholder = `Message ${agent.name}...`;

      startHumanPolling();
      scrollToBottom();
      return;
    }

    if (messagesContainer) messagesContainer.style.display = 'none';
    if (screensView) {
      screensView.style.display = 'flex';
      screensView.scrollTop = 0;
    }

    if (screen === 'home') {
      if (composerSection) composerSection.style.display = 'none';
      if (assistantNameEl) assistantNameEl.textContent = widgetConfig.brand_name || 'Cai';
      if (subtitleEl) subtitleEl.textContent = 'We are online';
      renderActionHome();
    } else if (screen === 'human-team') {
      if (composerSection) composerSection.style.display = 'none';
      if (assistantNameEl) assistantNameEl.textContent = 'Instant human help';
      if (subtitleEl) subtitleEl.textContent = 'Available consultants';
      renderHumanTeam(data.filter || 'available');
    } else if (screen === 'book-team') {
      if (composerSection) composerSection.style.display = 'none';
      if (assistantNameEl) assistantNameEl.textContent = 'Book an appointment';
      if (subtitleEl) subtitleEl.textContent = 'Select a team member';
      renderBookTeam();
    } else if (screen === 'book-slots') {
      if (composerSection) composerSection.style.display = 'none';
      const agent = data.agent || selectedAgent;
      if (assistantNameEl) assistantNameEl.textContent = 'Select Date & Time';
      if (subtitleEl) subtitleEl.textContent = agent ? `With ${agent.name}` : 'Book consultation';
      renderBookSlots(agent, data.date || null);
    } else if (screen === 'book-confirmed') {
      if (composerSection) composerSection.style.display = 'none';
      if (assistantNameEl) assistantNameEl.textContent = 'Appointment Confirmed';
      if (subtitleEl) subtitleEl.textContent = 'Meeting scheduled';
      renderBookConfirmed(data);
    } else if (screen === 'payment-options') {
      if (composerSection) composerSection.style.display = 'none';
      if (assistantNameEl) assistantNameEl.textContent = 'Make a payment';
      if (subtitleEl) subtitleEl.textContent = 'Choose a payment option';
      renderPaymentOptions();
    } else if (screen === 'bank-transfer') {
      if (composerSection) composerSection.style.display = 'none';
      if (assistantNameEl) assistantNameEl.textContent = 'Bank Transfer';
      if (subtitleEl) subtitleEl.textContent = 'NEFT / IMPS / UPI';
      renderBankTransfer();
    } else if (screen === 'pay-online') {
      if (composerSection) composerSection.style.display = 'none';
      if (assistantNameEl) assistantNameEl.textContent = 'Pay Online';
      if (subtitleEl) subtitleEl.textContent = 'Instant & secure checkout';
      renderPayOnline(data);
    } else if (screen === 'pay-invoice') {
      if (composerSection) composerSection.style.display = 'none';
      if (assistantNameEl) assistantNameEl.textContent = 'Pay an Invoice';
      if (subtitleEl) subtitleEl.textContent = 'Invoice payment';
      renderPayInvoice();
    } else if (screen === 'pay-custom') {
      if (composerSection) composerSection.style.display = 'none';
      if (assistantNameEl) assistantNameEl.textContent = 'Custom Amount';
      if (subtitleEl) subtitleEl.textContent = 'Pay any amount';
      renderPayCustom();
    } else if (screen === 'help') {
      if (composerSection) composerSection.style.display = 'none';
      if (assistantNameEl) assistantNameEl.textContent = 'Help Center';
      if (subtitleEl) subtitleEl.textContent = 'Support & FAQs';
      renderHelp();
    } else if (screen === 'news') {
      if (composerSection) composerSection.style.display = 'none';
      if (assistantNameEl) assistantNameEl.textContent = 'News & Updates';
      if (subtitleEl) subtitleEl.textContent = 'Latest announcements';
      renderNews();
    }
  }

  function handleBackNavigation() {
    stopHumanCountdown();
    stopHumanInactivityTimer();
    isHumanChatActive = false;
    const stalePill = shadow.getElementById('cp-waiting-pill');
    if (stalePill) stalePill.remove();
    if (currentScreen === 'home') {
      return;
    }
    if (currentScreen === 'book-slots') {
      navigateTo('book-team');
      return;
    }
    if (currentScreen === 'book-confirmed') {
      navigateTo('home');
      return;
    }
    if (currentScreen === 'bank-transfer' || currentScreen === 'pay-online' || currentScreen === 'pay-invoice' || currentScreen === 'pay-custom') {
      navigateTo('payment-options');
      return;
    }
    navigateTo('home');
  }

  // -------------------------------------------------------------
  // SCREEN 10: HELP CENTER & FAQS
  // -------------------------------------------------------------
  function renderHelp() {
    if (!screensView) return;

    screensView.innerHTML = `
      <div class="cp-sub-screen-header">
        <div class="cp-sub-screen-title">Help Center</div>
        <div class="cp-sub-screen-desc">Find instant answers or connect directly with our team</div>
      </div>

      <!-- 1. Instant Human Help -->
      <div class="cp-action-card" id="cp-help-card-human" role="button" tabindex="0">
        <div class="cp-action-card-left">
          <div class="cp-action-icon-box">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
            </svg>
          </div>
          <div class="cp-action-text-box">
            <div class="cp-action-card-title">Instant human help</div>
            <div class="cp-action-card-desc">Talk live with an available specialist</div>
          </div>
        </div>
        <div class="cp-action-card-right">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="9 18 15 12 9 6"></polyline>
          </svg>
        </div>
      </div>

      <!-- 2. Book an Appointment -->
      <div class="cp-action-card" id="cp-help-card-book" role="button" tabindex="0">
        <div class="cp-action-card-left">
          <div class="cp-action-icon-box">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
              <line x1="16" y1="2" x2="16" y2="6"></line>
              <line x1="8" y1="2" x2="8" y2="6"></line>
              <line x1="3" y1="10" x2="21" y2="10"></line>
            </svg>
          </div>
          <div class="cp-action-text-box">
            <div class="cp-action-card-title">Schedule a Consultation</div>
            <div class="cp-action-card-desc">Book a dedicated 1-on-1 meeting</div>
          </div>
        </div>
        <div class="cp-action-card-right">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="9 18 15 12 9 6"></polyline>
          </svg>
        </div>
      </div>

      <!-- 3. Ask AI -->
      <div class="cp-action-card" id="cp-help-card-chat" role="button" tabindex="0">
        <div class="cp-action-card-left">
          <div class="cp-action-icon-box">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="10"></circle>
              <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
              <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
          </div>
          <div class="cp-action-text-box">
            <div class="cp-action-card-title">Ask AI Assistant</div>
            <div class="cp-action-card-desc">Grounded instant answers 24/7</div>
          </div>
        </div>
        <div class="cp-action-card-right">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="9 18 15 12 9 6"></polyline>
          </svg>
        </div>
      </div>

      <!-- 4. Frequently Asked Questions (Interactive Expandable Accordion) -->
      <div class="cp-section-title" style="margin-top:14px;display:flex;align-items:center;justify-content:space-between;">
        <span>Frequently Asked Questions</span>
        <span style="font-size:10px;font-weight:500;color:var(--cp-text-muted);text-transform:uppercase;letter-spacing:0.5px;">Tap to expand</span>
      </div>
      <div class="cp-faq-list" style="display:flex;flex-direction:column;gap:8px;">
        <div class="cp-faq-card active" data-faq="1" style="background:var(--cp-options-bg);border:1px solid var(--cp-border-input, #282931);border-radius:10px;overflow:hidden;transition:all 0.2s ease;">
          <div class="cp-faq-header" role="button" tabindex="0" style="padding:12px 14px;display:flex;align-items:center;justify-content:space-between;cursor:pointer;user-select:none;gap:10px;">
            <div style="font-size:12.5px;font-weight:600;color:var(--cp-text-primary);line-height:1.35;">How do I get started with CuboidPilot?</div>
            <div class="cp-faq-chevron" style="width:16px;height:16px;display:flex;align-items:center;justify-content:center;color:var(--cp-text-muted);transition:transform 0.2s ease;transform:rotate(180deg);shrink:0;">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </div>
          </div>
          <div class="cp-faq-body" style="padding:0 14px 12px 14px;font-size:11.5px;color:var(--cp-text-secondary);line-height:1.5;border-top:1px solid rgba(255,255,255,0.04);margin-top:2px;padding-top:8px;">
            <div>Paste our one-line script tag before the closing &lt;/body&gt; tag on your website or dashboard to activate AI reasoning, lead capture, and appointment scheduling.</div>
            <button type="button" class="cp-faq-ask-ai" data-q="How do I get started with CuboidPilot on my website?" style="margin-top:8px;display:inline-flex;align-items:center;gap:4px;padding:4px 8px;background:rgba(255,255,255,0.06);border:1px solid var(--cp-border-input, #282931);border-radius:4px;color:var(--cp-text-primary);font-size:10.5px;font-weight:500;cursor:pointer;transition:background 0.15s;">
              <span>Ask Cai about this</span>
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </button>
          </div>
        </div>

        <div class="cp-faq-card" data-faq="2" style="background:var(--cp-options-bg);border:1px solid var(--cp-border-input, #282931);border-radius:10px;overflow:hidden;transition:all 0.2s ease;">
          <div class="cp-faq-header" role="button" tabindex="0" style="padding:12px 14px;display:flex;align-items:center;justify-content:space-between;cursor:pointer;user-select:none;gap:10px;">
            <div style="font-size:12.5px;font-weight:600;color:var(--cp-text-primary);line-height:1.35;">Can I speak with a real human advisor?</div>
            <div class="cp-faq-chevron" style="width:16px;height:16px;display:flex;align-items:center;justify-content:center;color:var(--cp-text-muted);transition:transform 0.2s ease;shrink:0;">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </div>
          </div>
          <div class="cp-faq-body" style="display:none;padding:0 14px 12px 14px;font-size:11.5px;color:var(--cp-text-secondary);line-height:1.5;border-top:1px solid rgba(255,255,255,0.04);margin-top:2px;padding-top:8px;">
            <div>Yes! Click "Instant human help" to connect live with available specialists, or schedule a calendar meeting via Google Meet.</div>
            <button type="button" class="cp-faq-ask-ai" data-q="Can I speak with a human advisor?" style="margin-top:8px;display:inline-flex;align-items:center;gap:4px;padding:4px 8px;background:rgba(255,255,255,0.06);border:1px solid var(--cp-border-input, #282931);border-radius:4px;color:var(--cp-text-primary);font-size:10.5px;font-weight:500;cursor:pointer;transition:background 0.15s;">
              <span>Ask Cai about this</span>
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </button>
          </div>
        </div>

        <div class="cp-faq-card" data-faq="3" style="background:var(--cp-options-bg);border:1px solid var(--cp-border-input, #282931);border-radius:10px;overflow:hidden;transition:all 0.2s ease;">
          <div class="cp-faq-header" role="button" tabindex="0" style="padding:12px 14px;display:flex;align-items:center;justify-content:space-between;cursor:pointer;user-select:none;gap:10px;">
            <div style="font-size:12.5px;font-weight:600;color:var(--cp-text-primary);line-height:1.35;">Are payments and customer data secure?</div>
            <div class="cp-faq-chevron" style="width:16px;height:16px;display:flex;align-items:center;justify-content:center;color:var(--cp-text-muted);transition:transform 0.2s ease;shrink:0;">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </div>
          </div>
          <div class="cp-faq-body" style="display:none;padding:0 14px 12px 14px;font-size:11.5px;color:var(--cp-text-secondary);line-height:1.5;border-top:1px solid rgba(255,255,255,0.04);margin-top:2px;padding-top:8px;">
            <div>All payments are encrypted and verified directly via Razorpay and official Indian banking protocols (NEFT/IMPS/UPI) with instant digital receipts. Conversations are tenant-isolated and encrypted with TLS 1.3.</div>
            <button type="button" class="cp-faq-ask-ai" data-q="Are payments and data secure on CuboidPilot?" style="margin-top:8px;display:inline-flex;align-items:center;gap:4px;padding:4px 8px;background:rgba(255,255,255,0.06);border:1px solid var(--cp-border-input, #282931);border-radius:4px;color:var(--cp-text-primary);font-size:10.5px;font-weight:500;cursor:pointer;transition:background 0.15s;">
              <span>Ask Cai about this</span>
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </button>
          </div>
        </div>

        <div class="cp-faq-card" data-faq="4" style="background:var(--cp-options-bg);border:1px solid var(--cp-border-input, #282931);border-radius:10px;overflow:hidden;transition:all 0.2s ease;">
          <div class="cp-faq-header" role="button" tabindex="0" style="padding:12px 14px;display:flex;align-items:center;justify-content:space-between;cursor:pointer;user-select:none;gap:10px;">
            <div style="font-size:12.5px;font-weight:600;color:var(--cp-text-primary);line-height:1.35;">How does WhatsApp continuity work?</div>
            <div class="cp-faq-chevron" style="width:16px;height:16px;display:flex;align-items:center;justify-content:center;color:var(--cp-text-muted);transition:transform 0.2s ease;shrink:0;">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </div>
          </div>
          <div class="cp-faq-body" style="display:none;padding:0 14px 12px 14px;font-size:11.5px;color:var(--cp-text-secondary);line-height:1.5;border-top:1px solid rgba(255,255,255,0.04);margin-top:2px;padding-top:8px;">
            <div>Whenever you leave the page or tap "Continue on WhatsApp", Cai transfers the full transcript to your WhatsApp account seamlessly with zero lost context.</div>
            <button type="button" class="cp-faq-ask-ai" data-q="How does WhatsApp continuity work?" style="margin-top:8px;display:inline-flex;align-items:center;gap:4px;padding:4px 8px;background:rgba(255,255,255,0.06);border:1px solid var(--cp-border-input, #282931);border-radius:4px;color:var(--cp-text-primary);font-size:10.5px;font-weight:500;cursor:pointer;transition:background 0.15s;">
              <span>Ask Cai about this</span>
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </button>
          </div>
        </div>
      </div>
    `;

    const hHuman = shadow.getElementById('cp-help-card-human');
    if (hHuman) bindTap(hHuman, () => navigateTo('human-team'));
    const hBook = shadow.getElementById('cp-help-card-book');
    if (hBook) bindTap(hBook, () => navigateTo('book-team'));
    const hChat = shadow.getElementById('cp-help-card-chat');
    if (hChat) bindTap(hChat, () => navigateTo('chat'));

    // Bind Expandable FAQ Accordion Taps & Keyboard Accessibility
    shadow.querySelectorAll('.cp-faq-header').forEach(header => {
      const toggleFn = () => {
        const card = header.closest('.cp-faq-card');
        if (!card) return;
        const body = card.querySelector('.cp-faq-body');
        const chevron = card.querySelector('.cp-faq-chevron');
        const isOpen = card.classList.contains('active');

        // Close all other FAQ cards in widget
        shadow.querySelectorAll('.cp-faq-card').forEach(c => {
          c.classList.remove('active');
          const b = c.querySelector('.cp-faq-body');
          const ch = c.querySelector('.cp-faq-chevron');
          if (b) b.style.display = 'none';
          if (ch) ch.style.transform = 'rotate(0deg)';
        });

        if (!isOpen) {
          card.classList.add('active');
          if (body) body.style.display = 'block';
          if (chevron) chevron.style.transform = 'rotate(180deg)';
        }
      };

      bindTap(header, toggleFn);
      header.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          toggleFn();
        }
      });
    });

    // Bind Ask Cai button inside FAQs
    shadow.querySelectorAll('.cp-faq-ask-ai').forEach(btn => {
      bindTap(btn, (e) => {
        if (e && e.stopPropagation) e.stopPropagation();
        const query = btn.getAttribute('data-q') || '';
        if (query) {
          navigateTo('chat');
          setTimeout(() => {
            if (inputField) inputField.value = query;
            handleUserSend(query);
          }, 150);
        }
      });
    });
  }

  // -------------------------------------------------------------
  // SCREEN 2: ACTION HOME (Exact match: media_1790857558494.png)
  // -------------------------------------------------------------
  function startWorkspaceCopilotSession() {
    isCopilotActive = true;
    navigateTo('chat');

    const asstName = widgetConfig.assistant_name || 'Cai';
    if (assistantNameEl) assistantNameEl.textContent = `${asstName} Copilot`;
    if (subtitleEl) subtitleEl.textContent = '● Live Workspace Telemetry';

    // 1. Inject Clean Markdown Welcome message
    const welcomeMarkdown = `**Namaste! Main aapka Workspace Copilot hoon.**\n\nAap mujhse apne workspace ki live details aur CRM stats pooch sakte hain:\n• Kitni leads aayi hain aur kisko gayi hain?\n• Kitni convert hui hain aur total revenue kitna hai?\n• Upcoming appointments aur schedule status\n• Reminder setup karne ke liye direct bol sakte hain!`;

    appendAIMessage({
      reply: welcomeMarkdown,
      timestamp: 'Just now'
    });

    // 2. Render Interactive Suggestion Chips directly to DOM (No raw HTML string in message)
    const chipsContainer = document.createElement('div');
    chipsContainer.className = 'cp-copilot-chips-wrap';
    chipsContainer.innerHTML = `
      <div class="cp-copilot-chips-label">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="10"></circle>
          <line x1="12" y1="16" x2="12" y2="12"></line>
          <line x1="12" y1="8" x2="12.01" y2="8"></line>
        </svg>
        <span>Suggested workspace queries:</span>
      </div>
      <div class="cp-copilot-chips">
        <button type="button" class="cp-copilot-chip" data-q="Kitni lead aayi hain?">
          <span class="cp-copilot-chip-icon cp-chip-blue">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M18 20V10M12 20V4M6 20v-6"></path>
            </svg>
          </span>
          <span>Kitni lead aayi?</span>
          <svg class="cp-chip-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
        </button>
        <button type="button" class="cp-copilot-chip" data-q="Leads kisko gayi hain?">
          <span class="cp-copilot-chip-icon cp-chip-purple">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
              <circle cx="9" cy="7" r="4"></circle>
              <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
              <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
            </svg>
          </span>
          <span>Leads kisko gayi?</span>
          <svg class="cp-chip-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
        </button>
        <button type="button" class="cp-copilot-chip" data-q="Kitni convert hui aur kya revenue hai?">
          <span class="cp-copilot-chip-icon cp-chip-emerald">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="12" y1="1" x2="12" y2="23"></line>
              <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
            </svg>
          </span>
          <span>Revenue &amp; conversion?</span>
          <svg class="cp-chip-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
        </button>
        <button type="button" class="cp-copilot-chip" data-q="Upcoming appointments aur reminders kya hain?">
          <span class="cp-copilot-chip-icon cp-chip-amber">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
              <line x1="16" y1="2" x2="16" y2="6"></line>
              <line x1="8" y1="2" x2="8" y2="6"></line>
              <line x1="3" y1="10" x2="21" y2="10"></line>
            </svg>
          </span>
          <span>Appointments &amp; schedule</span>
          <svg class="cp-chip-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
        </button>
        <button type="button" class="cp-copilot-chip" data-q="Fees aur plan status kya hai?">
          <span class="cp-copilot-chip-icon cp-chip-rose">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
            </svg>
          </span>
          <span>Subscription status</span>
          <svg class="cp-chip-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
        </button>
      </div>
    `;
    chatStream.appendChild(chipsContainer);
    scrollToBottom();

    chipsContainer.querySelectorAll('.cp-copilot-chip').forEach(chip => {
      bindTap(chip, () => {
        const q = chip.getAttribute('data-q');
        if (q && inputField) {
          inputField.value = q;
          handleSend();
        }
      });
    });

    setTimeout(() => {
      if (inputField) {
        inputField.placeholder = "Poochiye: Kitni lead aayi, revenue, reminder...";
        inputField.focus();
      }
    }, 120);
  }

  let homeDataLoaded = false;
  function loadHomeDynamicData() {
    if (homeDataLoaded) return;
    homeDataLoaded = true;

    // Fetch dynamic executive LinkedIn profiles for header avatars & sync settings
    fetch(`${baseUrl}/api/widget_actions.php?action=get_actions&company_key=${encodeURIComponent(companyKey)}&_t=${Date.now()}`)
      .then(r => r.json())
      .then(d => {
        if (d && d.success && d.settings) {
          applyWidgetConfig(d.settings);
          const lAyush = shadow.getElementById('cp-avatar-ayush-link');
          const lCai = shadow.getElementById('cp-avatar-cai-link');
          const lCuboid = shadow.getElementById('cp-avatar-cuboidsoft-link');
          if (lAyush) {
            if (d.settings.linkedin_ayush) { lAyush.href = d.settings.linkedin_ayush; lAyush.style.display = 'inline-block'; }
            else { lAyush.style.display = 'none'; }
          }
          if (lCai) {
            if (d.settings.linkedin_cai) { lCai.href = d.settings.linkedin_cai; lCai.style.display = 'inline-block'; }
            else { lCai.style.display = 'none'; }
          }
          if (lCuboid) {
            if (d.settings.linkedin_cuboidsoft) { lCuboid.href = d.settings.linkedin_cuboidsoft; lCuboid.style.display = 'inline-block'; }
            else { lCuboid.style.display = 'none'; }
          }
          const stack = shadow.getElementById('cp-team-avatar-stack');
          if (stack && !d.settings.linkedin_ayush && !d.settings.linkedin_cai && !d.settings.linkedin_cuboidsoft) {
            stack.style.display = 'none';
          }
        }
      })
      .catch(() => {});

    // Dynamic Latest Blog Card fetch
    fetch(`${baseUrl}/api/blogs.php?action=list&limit=1`)
      .then(r => r.json())
      .then(d => {
        if (d && d.success && d.blogs && d.blogs.length > 0) {
          const b = d.blogs[0];
          const blogImg = shadow.getElementById('cp-home-blog-img');
          const blogTag = shadow.getElementById('cp-home-blog-tag');
          const blogDate = shadow.getElementById('cp-home-blog-date');
          const blogTitle = shadow.getElementById('cp-home-blog-title');
          const blogDesc = shadow.getElementById('cp-home-blog-desc');

          if (blogImg && b.cover_image) {
            let imgUrl = b.cover_image;
            if (!imgUrl.startsWith('http')) {
              imgUrl = `${baseUrl}/${imgUrl.replace(/^\.?\//, '')}`;
            }
            blogImg.src = imgUrl;
            blogImg.alt = b.title || 'Latest Story';
            blogImg.onerror = function() {
              this.onerror = null;
              if (b.cover_image.includes('gandhi')) {
                this.src = 'https://raw.githubusercontent.com/ayushman-it/cai/master/assets/blog/gandhi-jayanti-2026.png';
              } else if (b.cover_image.includes('meet-cai')) {
                this.src = 'https://raw.githubusercontent.com/ayushman-it/cai/master/assets/blog/meet-cai-founder.png';
              }
            };
          }
          if (blogTag && b.category) {
            blogTag.textContent = b.category.toUpperCase();
          }
          if (blogDate && b.formatted_date) {
            blogDate.textContent = b.formatted_date;
          }
          if (blogTitle && b.title) {
            blogTitle.textContent = b.title;
          }
          if (blogDesc && b.excerpt) {
            blogDesc.textContent = b.excerpt;
          }
        }
      })
      .catch(() => {});
  }

  function renderActionHome() {
    if (!screensView) return;

    let lastSnippet = 'Search answers or chat with our AI assistant';
    let hasRecentMessages = false;
    try {
      const history = JSON.parse(widgetStorage.getItem(STORAGE_KEYS.MESSAGES) || '[]');
      if (history.length > 0) {
        hasRecentMessages = true;
        const last = history[history.length - 1];
        lastSnippet = (last.text || '').replace(/<[^>]+>/g, '').replace(/[*_#`~]/g, '').trim().substring(0, 52);
        if (lastSnippet.length >= 52) lastSnippet += '...';
      }
    } catch(e) {}

    const logoUrl = getActiveLogoUrl();
    const fallbackLogo = (currentTheme === 'light') ? `${baseUrl}/assets/logo-black.png` : `${baseUrl}/assets/logo-white.png`;

    const isPlatformDemo = (!companyKey || companyKey === 'cp_live_cuboidsoft' || companyKey === 'cp_live_cuboidpilot' || companyKey === 'cuboidsoft' || companyKey === 'cuboidpilot');
    const hasPremium = isPlatformDemo || Boolean(
      widgetConfig && (
        widgetConfig.is_premium || 
        widgetConfig.is_full_access || 
        widgetConfig.enable_appointments ||
        widgetConfig.enable_human_help ||
        widgetConfig.enable_payments ||
        (widgetConfig.company && (widgetConfig.company.is_premium || widgetConfig.company.is_trial || widgetConfig.company.is_full_access))
      )
    );
    const proBadge = !hasPremium ? '<span class="cp-pro-badge">PRO</span>' : '';

    const isDashboardEnv = Boolean(
      (typeof window !== 'undefined' && window.location && (window.location.pathname.includes('/app/') || window.location.pathname.includes('/admin/'))) ||
      (typeof window !== 'undefined' && (window.__CUBOID_COMPANY__ || window.CuboidShell || document.getElementById('sidebar-container')))
    );

    screensView.innerHTML = `
      <!-- Moody Hero Greeting (Classic Intercom Aesthetic) -->
      <div class="cp-hero-section">
        <div class="cp-hero-greeting-sub">Hello there.</div>
        <div class="cp-hero-greeting-main">How can we help?</div>
      </div>

      ${isDashboardEnv ? `
      <!-- 0. Know your workspace Card (Intercom-Style Copilot) -->
      <div class="cp-action-card cp-action-card-copilot" id="cp-card-workspace-copilot" role="button" tabindex="0" title="Know your workspace">
        <div class="cp-action-card-left">
          <div class="cp-action-icon-box cp-action-icon-copilot">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
              <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
              <line x1="12" y1="22.08" x2="12" y2="12"></line>
            </svg>
          </div>
          <div class="cp-action-text-box">
            <div class="cp-action-card-title">
              <span>Know your workspace</span>
              <span class="cp-copilot-tag">Copilot</span>
            </div>
            <div class="cp-action-card-desc">Ask live leads, team assignments, revenue &amp; reminders</div>
          </div>
        </div>
        <div class="cp-action-card-right">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="9 18 15 12 9 6"></polyline>
          </svg>
        </div>
      </div>
      ` : ''}

      <!-- 2. Book an appointment -->
      <div class="cp-action-card" id="cp-card-book" role="button" tabindex="0">
        <div class="cp-action-card-left">
          <div class="cp-action-icon-box">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
              <line x1="16" y1="2" x2="16" y2="6"></line>
              <line x1="8" y1="2" x2="8" y2="6"></line>
              <line x1="3" y1="10" x2="21" y2="10"></line>
            </svg>
          </div>
          <div class="cp-action-text-box">
            <div class="cp-action-card-title">Book an appointment</div>
            <div class="cp-action-card-desc">Request a meeting with the team</div>
          </div>
        </div>
        <div class="cp-action-card-right">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="9 18 15 12 9 6"></polyline>
          </svg>
        </div>
      </div>

      <!-- 3. Instant human help -->
      <div class="cp-action-card" id="cp-card-human" role="button" tabindex="0">
        <div class="cp-action-card-left">
          <div class="cp-action-icon-box">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
            </svg>
          </div>
          <div class="cp-action-text-box">
            <div class="cp-action-card-title">Instant human help</div>
            <div class="cp-action-card-desc">Speak with an available team member</div>
          </div>
        </div>
        <div class="cp-action-card-right">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="9 18 15 12 9 6"></polyline>
          </svg>
        </div>
      </div>

      <!-- 4. Make a payment -->
      <div class="cp-action-card" id="cp-card-payment" role="button" tabindex="0">
        <div class="cp-action-card-left">
          <div class="cp-action-icon-box">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
              <line x1="1" y1="10" x2="23" y2="10"></line>
            </svg>
          </div>
          <div class="cp-action-text-box">
            <div class="cp-action-card-title">Make a payment</div>
            <div class="cp-action-card-desc">Pay online, bank transfer, or invoice</div>
          </div>
        </div>
        <div class="cp-action-card-right">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="9 18 15 12 9 6"></polyline>
          </svg>
        </div>
      </div>

      <!-- 5. News & Updates -->
      <div class="cp-action-card" id="cp-card-news-home" role="button" tabindex="0">
        <div class="cp-action-card-left">
          <div class="cp-action-icon-box" style="color:#8b5cf6;background:rgba(139,92,246,0.08);border-color:rgba(139,92,246,0.18);">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M3 11v3a1 1 0 0 0 1 1h3l5 4V5L7 9H4a1 1 0 0 0-1 1z"></path>
              <path d="M19.07 4.93a10 10 0 0 1 0 14.14"></path>
            </svg>
          </div>
          <div class="cp-action-text-box">
            <div class="cp-action-card-title">News &amp; Updates</div>
            <div class="cp-action-card-desc">Latest product announcements &amp; guides</div>
          </div>
        </div>
        <div class="cp-action-card-right">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="9 18 15 12 9 6"></polyline>
          </svg>
        </div>
      </div>

      ${isPlatformDemo ? `
      <!-- 6. Featured Story: Lightweight Latest Blog Card (media_1790923294302.png) -->
      <div class="cp-featured-banner-card" id="cp-card-latest-blog" role="button" tabindex="0" title="Read Latest Story">
        <div class="cp-banner-img-box">
          <img src="${baseUrl}/assets/blog/gandhi-jayanti-2026.png" 
               id="cp-home-blog-img" 
               class="cp-banner-cover-photo" 
               alt="Gandhi Jayanti Special" 
               onerror="this.onerror=null; this.src='https://raw.githubusercontent.com/ayushman-it/cai/master/assets/blog/gandhi-jayanti-2026.png';" />
        </div>
        <div class="cp-banner-body">
          <div class="cp-banner-meta-row">
            <span class="cp-banner-badge-tag" id="cp-home-blog-tag">SPECIAL EVENT</span>
            <span class="cp-banner-date-label" id="cp-home-blog-date">Oct 02, 2026</span>
          </div>
          <h4 class="cp-banner-headline" id="cp-home-blog-title">Gandhi Jayanti Special: Truth, Decentralized Technology &amp; The Spirit of Self-Reliance</h4>
          <p class="cp-banner-subline" id="cp-home-blog-desc">On October 2nd, we honor Mahatma Gandhi's enduring ideals &mdash; Satya (Truth), Swavalamban (Self-Reliance), and Sarvodaya (Welfare of All). Here is how these principles guide the future of autonomous, grounded AI at CuboidPilot.</p>
        </div>
      </div>
      ` : ''}
    `;

    let currentBlogSlug = 'gandhi-jayanti-truth-technology-self-reliance';
    const blogCard = shadow.getElementById('cp-card-latest-blog');
    if (blogCard) {
      bindTap(blogCard, () => {
        window.open(`${baseUrl}/blog.html?slug=${encodeURIComponent(currentBlogSlug)}`, '_blank');
      });
    }

    if (isEmbedded || (chatWindow && chatWindow.classList.contains('open'))) {
      loadHomeDynamicData();
    }

    const copilotCard = shadow.getElementById('cp-card-workspace-copilot');
    if (copilotCard) {
      bindTap(copilotCard, () => {
        startWorkspaceCopilotSession();
      });
    }

    const bookCard = shadow.getElementById('cp-card-book');
    if (bookCard) {
      bindTap(bookCard, () => {
        navigateTo('book-team');
      });
    }

    const humanCard = shadow.getElementById('cp-card-human');
    if (humanCard) {
      bindTap(humanCard, () => {
        navigateTo('human-team');
      });
    }

    const paymentCard = shadow.getElementById('cp-card-payment');
    if (paymentCard) {
      bindTap(paymentCard, () => {
        navigateTo('payment-options');
      });
    }

    const newsHomeCard = shadow.getElementById('cp-card-news-home');
    if (newsHomeCard) {
      bindTap(newsHomeCard, () => {
        navigateTo('news');
      });
    }

    const askPill = shadow.getElementById('cp-ask-question-pill');
    if (askPill) {
      bindTap(askPill, () => {
        navigateTo('chat');
        setTimeout(() => {
          if (inputField) inputField.focus();
        }, 120);
      });
    }

    const heroSec = screensView.querySelector('.cp-hero-section');
    if (heroSec) {
      heroSec.style.cursor = 'pointer';
      heroSec.title = 'Start conversation';
      bindTap(heroSec, () => {
        navigateTo('chat');
        setTimeout(() => {
          if (inputField) inputField.focus();
        }, 120);
      });
    }
  }

  function renderLockedScreen(featureName) {
    if (!screensView) return;
    screensView.innerHTML = `
      <div class="cp-locked-container">
        <div class="cp-locked-icon">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
          </svg>
        </div>
        <div class="cp-locked-title">Pro Feature</div>
        <div class="cp-locked-desc">${escapeHtml(featureName)} is available on CuboidPilot Pro &amp; Enterprise plans. Upgrade your workspace to unlock.</div>
        <a href="${baseUrl}/pricing.html" target="_blank" class="cp-locked-upgrade-btn">
          <span>View Plans &amp; Pricing</span>
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <line x1="5" y1="12" x2="19" y2="12"></line>
            <polyline points="12 5 19 12 12 19"></polyline>
          </svg>
        </a>
        <button class="cp-locked-back-btn" id="cp-locked-back-btn" type="button">Back to Options</button>
      </div>
    `;

    const backBtn = shadow.getElementById('cp-locked-back-btn');
    if (backBtn) {
      backBtn.onclick = () => renderActionHome();
    }
  }

  // -------------------------------------------------------------
  // SCREEN 9: NEWS & ANNOUNCEMENTS (media_1790868630655.png)
  // -------------------------------------------------------------
  async function renderNews() {
    if (!screensView) return;
    screensView.innerHTML = `
      <div class="cp-news-header">
        <div class="cp-news-title">Product News &amp; Updates</div>
        <div class="cp-news-sub">Latest announcements and editorial guides from CuboidPilot</div>
      </div>

      <div class="cp-news-list" id="cp-dynamic-news-list">
        <div class="cp-skeleton-card-item" style="flex-direction: column; align-items: flex-start; gap: 8px;">
          <div class="cp-skeleton-box" style="width: 100%; height: 110px; border-radius: 8px;"></div>
          <div class="cp-skeleton-card-body" style="width: 100%; margin-top: 4px;">
            <div class="cp-skeleton-line" style="width: 70%; height: 12px;"></div>
            <div class="cp-skeleton-line" style="width: 90%; height: 9px;"></div>
          </div>
        </div>
        <div class="cp-skeleton-card-item" style="flex-direction: column; align-items: flex-start; gap: 8px;">
          <div class="cp-skeleton-box" style="width: 100%; height: 110px; border-radius: 8px;"></div>
          <div class="cp-skeleton-card-body" style="width: 100%; margin-top: 4px;">
            <div class="cp-skeleton-line" style="width: 65%; height: 12px;"></div>
            <div class="cp-skeleton-line" style="width: 85%; height: 9px;"></div>
          </div>
        </div>
      </div>
      <a href="${baseUrl}/blog.html" target="_blank" class="cp-news-all-link">Visit Blog &amp; All Articles &rarr;</a>
    `;

    const listEl = shadow.getElementById('cp-dynamic-news-list');
    try {
      const res = await fetch(`${baseUrl}/api/blogs.php?action=list&limit=4`);
      const data = await res.json();
      if (data && data.success && data.blogs && data.blogs.length > 0) {
        listEl.innerHTML = data.blogs.map((b, idx) => {
          const imgSrc = b.cover_image ? (b.cover_image.startsWith('http') ? b.cover_image : `${baseUrl}/${b.cover_image.replace(/^\.?\//, '')}`) : '';
          return `
            <div class="cp-news-card" data-slug="${escapeHtml(b.slug)}" role="button" tabindex="0">
              ${imgSrc ? `<img src="${imgSrc}" class="cp-news-img" alt="${escapeHtml(b.title)}" onerror="this.onerror=null; if(this.src.indexOf('raw.github')===-1 && (b.cover_image||'').includes('gandhi')){this.src='https://raw.githubusercontent.com/ayushman-it/cai/master/assets/blog/gandhi-jayanti-2026.png';} else if(this.src.indexOf('raw.github')===-1 && (b.cover_image||'').includes('meet-cai')){this.src='https://raw.githubusercontent.com/ayushman-it/cai/master/assets/blog/meet-cai-founder.png';} else {this.style.display='none';}">` : ''}
              <div class="cp-news-card-top">
                <span class="cp-news-badge ${idx === 0 ? 'new' : ''}">${escapeHtml(b.category || 'Update')}</span>
                <span class="cp-news-date">${escapeHtml(b.formatted_date || 'Recent')}</span>
              </div>
              <div class="cp-news-heading">${escapeHtml(b.title)}</div>
              <div class="cp-news-desc">${escapeHtml(b.excerpt || '')}</div>
            </div>
          `;
        }).join('');

        listEl.querySelectorAll('.cp-news-card').forEach(card => {
          card.onclick = () => {
            const slug = card.getAttribute('data-slug');
            window.open(`${baseUrl}/blog.html?slug=${encodeURIComponent(slug)}`, '_blank');
          };
        });
        return;
      }
    } catch(e) {
      console.warn('[Widget] News fetch notice:', e);
    }

    // Clean Fallback if API unreachable
    listEl.innerHTML = `
      <div class="cp-news-card" role="button" tabindex="0" onclick="window.open('${baseUrl}/blog.html', '_blank')">
        <div class="cp-news-card-top">
          <span class="cp-news-badge new">New Feature</span>
          <span class="cp-news-date">This week</span>
        </div>
        <div class="cp-news-heading">Meet Cai: Autonomous AI 2.0</div>
        <div class="cp-news-desc">24/7 intelligent inquiry resolution, real-time knowledge grounding, and instant WhatsApp handoffs.</div>
      </div>
      <div class="cp-news-card" role="button" tabindex="0">
        <div class="cp-news-card-top">
          <span class="cp-news-badge">Calendar</span>
          <span class="cp-news-date">Updated</span>
        </div>
        <div class="cp-news-heading">1-on-1 Google Meet Consultations</div>
        <div class="cp-news-desc">Schedule discovery calls directly with verified advisors with automatic collision detection.</div>
      </div>
    `;
  }

  // -------------------------------------------------------------
  // SCREEN 3: INSTANT HUMAN HELP TEAM DIRECTORY
  // -------------------------------------------------------------
  async function renderHumanTeam(filter = 'available') {
    if (!screensView) return;

    screensView.innerHTML = `
      <div class="cp-filter-pills">
        <span class="cp-pill ${filter === 'available' ? 'active' : ''}" data-filter="available">Available now</span>
        <span class="cp-pill ${filter === 'sales' ? 'active' : ''}" data-filter="sales">Sales</span>
        <span class="cp-pill ${filter === 'technical' ? 'active' : ''}" data-filter="technical">Technical</span>
        <span class="cp-pill ${filter === 'support' ? 'active' : ''}" data-filter="support">Support</span>
        <span class="cp-pill ${filter === 'all' ? 'active' : ''}" data-filter="all">All</span>
      </div>
      <div id="cp-team-list-container" style="display:flex;flex-direction:column;gap:8px;">
        <div class="cp-skeleton-card-item">
          <div class="cp-skeleton-circle"></div>
          <div class="cp-skeleton-card-body">
            <div class="cp-skeleton-line" style="width: 55%; height: 11px;"></div>
            <div class="cp-skeleton-line" style="width: 80%; height: 9px;"></div>
          </div>
        </div>
        <div class="cp-skeleton-card-item">
          <div class="cp-skeleton-circle"></div>
          <div class="cp-skeleton-card-body">
            <div class="cp-skeleton-line" style="width: 45%; height: 11px;"></div>
            <div class="cp-skeleton-line" style="width: 70%; height: 9px;"></div>
          </div>
        </div>
        <div class="cp-skeleton-card-item">
          <div class="cp-skeleton-circle"></div>
          <div class="cp-skeleton-card-body">
            <div class="cp-skeleton-line" style="width: 60%; height: 11px;"></div>
            <div class="cp-skeleton-line" style="width: 75%; height: 9px;"></div>
          </div>
        </div>
      </div>
    `;

    screensView.querySelectorAll('.cp-pill').forEach(pill => {
      pill.addEventListener('click', () => {
        const selected = pill.getAttribute('data-filter');
        renderHumanTeam(selected);
      });
    });

    try {
      const res = await fetch(`${baseUrl}/api/widget_actions.php?action=get_team&department=${encodeURIComponent(filter)}&company_key=${encodeURIComponent(companyKey)}`);
      const data = await res.json();
      const listContainer = shadow.getElementById('cp-team-list-container');
      if (!listContainer) return;

      if (!data.success || !data.team || data.team.length === 0) {
        listContainer.innerHTML = `
          <div style="background:var(--cp-options-bg, #ffffff);border:1px solid var(--cp-border-input, #e2e8f0);border-radius:12px;padding:24px 16px;text-align:center;margin-top:6px;box-shadow:0 1px 3px rgba(0,0,0,0.03);">
            <div style="font-size:13px;font-weight:700;color:var(--cp-text-title);">No specialists online in this department</div>
            <div style="font-size:11.5px;color:var(--cp-text-secondary);margin-top:5px;line-height:1.45;">
              Our specialists in this department are currently assisting other clients. You can talk to available team members now or schedule a dedicated 1-on-1 consultation.
            </div>
            <div style="display:flex;gap:8px;justify-content:center;margin-top:14px;">
              <button class="cp-btn-sm primary" id="cp-fallback-avail-btn" type="button" style="padding:6px 12px;font-size:11.5px;">View Available</button>
              <button class="cp-btn-sm" id="cp-fallback-book-btn" type="button" style="padding:6px 12px;font-size:11.5px;">Book 1-on-1</button>
            </div>
          </div>
        `;
        const bAvail = listContainer.querySelector('#cp-fallback-avail-btn');
        if (bAvail) bAvail.onclick = () => renderHumanTeam('available');
        const bBook = listContainer.querySelector('#cp-fallback-book-btn');
        if (bBook) bBook.onclick = () => navigateTo('book-team');
        return;
      }

      teamMembersCache = data.team;
      listContainer.innerHTML = '';

      data.team.forEach(m => {
        const card = document.createElement('div');
        card.className = 'cp-member-card';

        const statusClass = (m.availability_status || '').toLowerCase();
        const fallbackAvatar = `${baseUrl}/assets/uploads/avatars/avatar_default.svg`;
        const avatarUrl = m.avatar_url ? (m.avatar_url.startsWith('http') ? m.avatar_url : `${baseUrl}/${m.avatar_url.replace(/^\/+/, '')}`) : fallbackAvatar;

        const cleanName = (m.name || '').replace(/\s*\([^)]*\)/g, '').trim();
        const deptUpper = (m.department || '').trim();

        card.innerHTML = `
          <div class="cp-member-left">
            <div class="cp-action-avatar-wrap">
              <img src="${avatarUrl}" class="cp-action-avatar-img" alt="" onerror="this.onerror=null; this.src='${fallbackAvatar}';" />
              <div class="cp-action-status-dot ${statusClass}"></div>
            </div>
            <div class="cp-action-text-box">
              <div class="cp-member-name-row">
                <span class="cp-member-name">${escapeHtml(cleanName || m.name)}</span>
                ${deptUpper ? `<span class="cp-dept-tag">${escapeHtml(deptUpper)}</span>` : ''}
              </div>
              <div class="cp-member-role">${escapeHtml(m.job_title || deptUpper || 'Specialist')}</div>
            </div>
          </div>
          <div class="cp-member-actions">
            ${m.linkedin_url ? `
              <a href="${escapeHtml(m.linkedin_url)}" target="_blank" rel="noopener noreferrer" class="cp-btn-linkedin" title="LinkedIn Profile" onclick="event.stopPropagation();">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M19 3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14m-.5 15.5v-5.3a3.26 3.26 0 0 0-3.26-3.26c-.85 0-1.84.52-2.28 1.3v-1.11h-2.79v8.37h2.79v-4.93c0-.77.62-1.4 1.39-1.4a1.4 1.4 0 0 1 1.4 1.4v4.93h2.75M6.46 10.9h2.8v8.37h-2.8v-8.37M7.86 6.32a1.63 1.63 0 0 0-1.62 1.62 1.62 1.62 0 0 0 1.62 1.63 1.62 1.62 0 0 0 1.63-1.63 1.63 1.63 0 0 0-1.63-1.62Z"/></svg>
              </a>
            ` : ''}
            <button class="cp-btn-sm primary cp-btn-chat" data-id="${m.id}">Chat</button>
            <button class="cp-btn-sm cp-btn-book" data-id="${m.id}">Book</button>
          </div>
        `;

        card.querySelector('.cp-btn-chat').addEventListener('click', (e) => {
          e.stopPropagation();
          startHumanChatWithAgent(m);
        });

        card.querySelector('.cp-btn-book').addEventListener('click', (e) => {
          e.stopPropagation();
          selectedAgent = m;
          navigateTo('book-slots', { agent: m });
        });

        listContainer.appendChild(card);
      });

    } catch (e) {
      console.error('[CuboidPilot Widget] Team fetch error:', e);
    }
  }

  // -------------------------------------------------------------
  // SCREEN 4: INSTANT HUMAN CHAT & SLEEK 30s COUNTDOWN HANDOFF FLOW
  // -------------------------------------------------------------
  async function startInstantHumanHelpSession(agent) {
    stopHumanCountdown();
    humanAttemptNumber = 1;
    humanSecondsRemaining = 30;
    isHumanChatActive = true;
    if (agent) selectedAgent = agent;

    navigateTo('human-chat', { agent: selectedAgent });

    // 1. Initial immediate acknowledgment
    const lastMsgBubble = chatStream ? chatStream.querySelector('.cp-msg-row.ai:last-child .cp-bubble') : null;
    const hasAck = lastMsgBubble && lastMsgBubble.textContent.includes("Sure! I'm connecting you with our team.");
    if (!hasAck) {
      appendAIMessage({
        reply: "Sure! I'm connecting you with our team. Please wait a moment.",
        timestamp: 'Just now'
      });
    }

    // Clean up any stale waiting pills
    const existingPill = chatStream ? chatStream.querySelector('.cp-waiting-pill') : null;
    if (existingPill) existingPill.remove();

    // Sleek Intercom-style waiting pill
    const pill = document.createElement('div');
    pill.className = 'cp-waiting-pill';
    pill.id = 'cp-waiting-pill';
    const fallbackAvatar = `${baseUrl}/assets/uploads/avatars/avatar_default.svg`;
    let agentAvatar = (agent && agent.avatar_url) ? (agent.avatar_url.startsWith('http') ? agent.avatar_url : `${baseUrl}/${agent.avatar_url.replace(/^\/+/, '')}`) : fallbackAvatar;
    const targetAgentName = (agent && agent.name) ? escapeHtml(agent.name) : 'team';

    pill.innerHTML = `
      <div class="cp-waiting-avatar-wrap">
        <img src="${agentAvatar}" class="cp-waiting-avatar-img" alt="" onerror="this.src='${fallbackAvatar}';" />
        <span class="cp-waiting-dot-pulse"></span>
      </div>
      <span class="cp-waiting-text" id="cp-waiting-text">Connecting to ${targetAgentName}...</span>
      <span class="cp-waiting-timer" id="cp-waiting-timer">30s</span>
    `;
    chatStream.appendChild(pill);
    scrollToBottom();

    // Trigger backend human request & consultant notification
    try {
      const res = await fetch(`${baseUrl}/api/widget_actions.php?action=start_human_chat`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Company-Key': companyKey },
        body: JSON.stringify({
          company_key: companyKey,
          user_id: selectedAgent ? selectedAgent.id : 0,
          session_token: sessionId,
          conversation_id: conversationId,
          name: visitorName,
          email: visitorEmail,
          phone: visitorPhone
        })
      });
      const data = await res.json();
      if (data.success) {
        if (data.conversation_id) {
          conversationId = data.conversation_id;
          widgetStorage.setItem(STORAGE_KEYS.CONVO_ID, conversationId);
        }
        if (data.agent) {
          selectedAgent = data.agent;
          if (assistantNameEl) assistantNameEl.textContent = data.agent.name;
        }
      }
    } catch (e) {
      console.warn('Start human help error:', e);
    }

    startHumanPolling();

    // Start single 30s countdown
    humanCountdownTimer = setInterval(() => {
      humanSecondsRemaining--;

      const timerEl = shadow.getElementById('cp-waiting-timer');
      const textEl = shadow.getElementById('cp-waiting-text');

      if (timerEl) {
        timerEl.textContent = `${humanSecondsRemaining}s`;
      }
      if (subtitleEl) {
        subtitleEl.textContent = `Connecting to ${selectedAgent?.name || 'team'} (${humanSecondsRemaining}s)...`;
      }

      if (humanSecondsRemaining <= 0) {
        // 30 seconds elapsed without immediate join: Transition to HUMAN_PENDING
        // Do NOT terminate conversation or falsely revert to AI
        stopHumanCountdown();

        const activePill = shadow.getElementById('cp-waiting-pill');
        if (activePill) {
          activePill.classList.add('pending');
          if (textEl) textEl.textContent = 'Our team has been notified';
          if (timerEl) timerEl.textContent = 'Pending';
        }
        if (subtitleEl) {
          subtitleEl.textContent = '● Team Notified (Waiting for reply)';
        }
        if (inputField) {
          inputField.placeholder = 'Leave a message for our team...';
        }

        // Leave human chat active so visitor messages are queued for human agent (not AI)
        isHumanChatActive = true;

        appendAIMessage({
          reply: "Our team has been notified. You can leave your message here, and we'll respond as soon as possible.",
          timestamp: 'Just now'
        });
        playReceivedSound();
      }
    }, 1000);
  }

  function startHumanChatWithAgent(agent) {
    return initiateHumanSupportHandoff(agent);
  }

  function showIncomingAgentNotification(agentName, messageText, avatarUrl) {
    if (chatWindow && chatWindow.classList.contains('open') && (currentScreen === 'chat' || currentScreen === 'human-chat')) return;
    if (launcherBadge) {
      launcherBadge.style.display = 'flex';
      launcherBadge.textContent = '1';
    }
    if (teaserBubble) {
      if (teaserAuthor) teaserAuthor.textContent = agentName || 'Customer Specialist';
      if (teaserMsg) teaserMsg.textContent = messageText || 'New message received';
      teaserBubble.classList.add('visible');
      teaserBubble.style.display = 'block';
    }
  }

  function startHumanPolling() {
    if (humanPollingInterval) clearInterval(humanPollingInterval);

    humanPollingInterval = setInterval(async () => {
      if (!conversationId) {
        const storedCId = widgetStorage.getItem(STORAGE_KEYS.CONVO_ID);
        if (storedCId) {
          conversationId = parseInt(storedCId, 10);
        } else {
          return;
        }
      }

      try {
        const url = `${baseUrl}/api/widget_actions.php?action=poll_messages&conversation_id=${conversationId}&after_id=${lastPolledMessageId}&company_key=${encodeURIComponent(companyKey)}&session_token=${encodeURIComponent(sessionId)}`;
        const res = await fetch(url);
        if (!res.ok) return;
        const data = await res.json();

        if (data.success) {
          let agentReplied = false;
          const latestAgentName = (data.agent && data.agent.name) || selectedAgent?.name || 'Advisor';
          const latestAgentTitle = (data.agent && data.agent.job_title) || selectedAgent?.job_title || 'Customer Specialist';
          const latestAgentAvatar = (data.agent && data.agent.avatar_url) || selectedAgent?.avatar_url || null;

          if (Array.isArray(data.messages)) {
            data.messages.forEach(msg => {
              if (msg.id > lastPolledMessageId) {
                lastPolledMessageId = msg.id;
                if (msg.sender === 'human_agent') {
                  agentReplied = true;
                  const alreadyExists = Array.from(chatStream.querySelectorAll('.cp-msg-row.ai .cp-bubble'))
                    .some(b => b.textContent.trim() === msg.text.trim());
                  if (!alreadyExists) {
                    appendAIMessage({
                      reply: msg.text,
                      timestamp: msg.timestamp || 'Just now',
                      agent_name: latestAgentName,
                      is_human: true,
                      avatar_url: latestAgentAvatar
                    });
                    playReceivedSound();
                  }
                  // Notify user if floating widget is closed or on home screen
                  if (!isEmbedded && (!chatWindow || !chatWindow.classList.contains('open') || currentScreen === 'home')) {
                    showIncomingAgentNotification(latestAgentName, msg.text, latestAgentAvatar);
                  }
                }
              }
            });
          }

          if (data.status === 'closed') {
            stopHumanCountdown();
            stopHumanInactivityTimer();
            isHumanChatActive = false;
            if (humanPollingInterval) {
              clearInterval(humanPollingInterval);
              humanPollingInterval = null;
            }
            const activePill = shadow.getElementById('cp-waiting-pill');
            if (activePill) activePill.remove();
            if (!chatStream.querySelector('.cp-chat-ended-container')) {
              renderChatConcludedCard();
            }
            return;
          }

          if (agentReplied || data.ownership === 'human' || data.status === 'human_active') {
            stopHumanCountdown();
            isHumanChatActive = true;
            resetHumanInactivityTimer();
            const activePill = shadow.getElementById('cp-waiting-pill');
            if (activePill) {
              activePill.classList.remove('pending');
              activePill.classList.add('connected');
              const textEl = shadow.getElementById('cp-waiting-text');
              const timerEl = shadow.getElementById('cp-waiting-timer');
              if (textEl) textEl.textContent = "You're now connected with our team.";
              if (timerEl) timerEl.textContent = 'Live';
              if (subtitleEl) subtitleEl.textContent = `● Online (${latestAgentName})`;
              if (assistantNameEl) assistantNameEl.textContent = latestAgentName;

              // Render Intercom Takeover Banner once
              if (!shadow.getElementById('cp-takeover-banner')) {
                const banner = document.createElement('div');
                banner.className = 'cp-takeover-banner';
                banner.id = 'cp-takeover-banner';
                const fAvatar = `${baseUrl}/assets/uploads/avatars/avatar_default.svg`;
                const avUrl = latestAgentAvatar ? (latestAgentAvatar.startsWith('http') ? latestAgentAvatar : `${baseUrl}/${latestAgentAvatar.replace(/^\/+/, '')}`) : fAvatar;
                banner.innerHTML = `
                  <div class="cp-takeover-avatar">
                    <img src="${avUrl}" alt="${escapeHtml(latestAgentName)}" onerror="this.src='${fAvatar}';" />
                    <span class="cp-takeover-status-dot"></span>
                  </div>
                  <div class="cp-takeover-info">
                    <div class="cp-takeover-name">${escapeHtml(latestAgentName)} joined the conversation</div>
                    <div class="cp-takeover-desc">${escapeHtml(latestAgentTitle)} • Live Support</div>
                  </div>
                `;
                if (activePill.parentNode) {
                  activePill.parentNode.insertBefore(banner, activePill);
                } else if (chatStream) {
                  chatStream.appendChild(banner);
                }
                scrollToBottom();
              }

              setTimeout(() => {
                const p = shadow.getElementById('cp-waiting-pill');
                if (p) p.remove();
              }, 1200);
            }
          }
        }
      } catch (e) {}
    }, 2500);
  }

  // -------------------------------------------------------------
  // SCREEN 5: BOOK APPOINTMENT — MEMBER SELECTOR
  // -------------------------------------------------------------
  async function renderBookTeam() {
    if (!screensView) return;

    screensView.innerHTML = `
      <div style="font-size:12px;color:var(--cp-text-secondary);margin-bottom:8px;padding:0 2px;">
        Select an advisor or consultant for your personalized consultation:
      </div>
      <div id="cp-book-team-list" style="display:flex;flex-direction:column;gap:8px;">
        <div class="cp-skeleton-card-item">
          <div class="cp-skeleton-circle"></div>
          <div class="cp-skeleton-card-body">
            <div class="cp-skeleton-line" style="width: 55%; height: 11px;"></div>
            <div class="cp-skeleton-line" style="width: 80%; height: 9px;"></div>
          </div>
        </div>
        <div class="cp-skeleton-card-item">
          <div class="cp-skeleton-circle"></div>
          <div class="cp-skeleton-card-body">
            <div class="cp-skeleton-line" style="width: 45%; height: 11px;"></div>
            <div class="cp-skeleton-line" style="width: 70%; height: 9px;"></div>
          </div>
        </div>
      </div>
    `;

    try {
      const res = await fetch(`${baseUrl}/api/widget_actions.php?action=get_team&department=all&company_key=${encodeURIComponent(companyKey)}`);
      const data = await res.json();
      const list = shadow.getElementById('cp-book-team-list');
      if (!list) return;

      if (!data.success || !data.team || data.team.length === 0) {
        list.innerHTML = `<div style="font-size:12px;color:var(--cp-text-muted);text-align:center;padding:20px;">No advisors available for booking right now.</div>`;
        return;
      }

      list.innerHTML = '';
      data.team.forEach(m => {
        const card = document.createElement('div');
        card.className = 'cp-action-card';
        const fallbackAvatar = `${baseUrl}/assets/uploads/avatars/avatar_default.svg`;
        const avatarUrl = m.avatar_url ? (m.avatar_url.startsWith('http') ? m.avatar_url : `${baseUrl}/${m.avatar_url.replace(/^\/+/, '')}`) : fallbackAvatar;

        card.innerHTML = `
          <div class="cp-action-card-left">
            <div class="cp-action-avatar-wrap">
              <img src="${avatarUrl}" class="cp-action-avatar-img" alt="" onerror="this.onerror=null; this.src='${fallbackAvatar}';" />
              <div class="cp-action-status-dot ${(m.availability_status || '').toLowerCase()}"></div>
            </div>
            <div class="cp-action-text-box">
              <div class="cp-action-card-title">${escapeHtml(m.name)}</div>
              <div class="cp-action-card-desc">${escapeHtml(m.job_title)} • ${m.next_available_slot || 'Next slot available'}</div>
            </div>
          </div>
          <div class="cp-action-card-right">
            <button class="cp-btn-sm primary">Select</button>
          </div>
        `;

        card.addEventListener('click', () => {
          selectedAgent = m;
          navigateTo('book-slots', { agent: m });
        });

        list.appendChild(card);
      });
    } catch(e) {
      console.error(e);
    }
  }

  // -------------------------------------------------------------
  // SCREEN 6: APPOINTMENT DATE & TIME SLOT PICKER + FORM
  // -------------------------------------------------------------
  async function renderBookSlots(agent, dateStr = null) {
    if (!screensView) return;
    const defaultAvatar = `${baseUrl}/assets/uploads/avatars/avatar_default.svg`;
    const currentAgent = agent || selectedAgent || (teamMembersCache && teamMembersCache[0]) || { id: 0, name: 'Advisor', job_title: 'Consultant', avatar_url: defaultAvatar };
    const rawAvatar = currentAgent.avatar_url || defaultAvatar;
    const avatarUrl = (rawAvatar.startsWith('http://') || rawAvatar.startsWith('https://')) ? rawAvatar : `${baseUrl}/${rawAvatar.replace(/^\/+/, '')}`;

    // Save any existing form values in case user already typed
    const existingName = (shadow.getElementById('cp-book-name')?.value) || visitorName || '';
    const existingPhone = (shadow.getElementById('cp-book-phone')?.value) || visitorPhone || '';
    const existingEmail = (shadow.getElementById('cp-book-email')?.value) || visitorEmail || '';
    const existingNotes = (shadow.getElementById('cp-book-notes')?.value) || '';

    screensView.innerHTML = `
      <!-- Selected Advisor Card -->
      <div class="cp-agent-hero-card">
        <div class="cp-action-avatar-wrap">
          <img src="${avatarUrl}" class="cp-action-avatar-img" alt="" onerror="this.onerror=null; this.src='${defaultAvatar}';" />
          <div class="cp-action-status-dot ${(currentAgent.availability_status || 'available').toLowerCase()}"></div>
        </div>
        <div class="cp-action-text-box">
          <div style="font-size:14px;font-weight:600;color:var(--cp-text-title);">${escapeHtml(currentAgent.name)}</div>
          <div style="font-size:11.5px;color:var(--cp-text-secondary);">${escapeHtml(currentAgent.job_title)} • 30-min Video Call</div>
        </div>
      </div>

      <div class="cp-section-title">1. Select Date</div>
      <div class="cp-date-chips" id="cp-date-chips-container">
        <div style="font-size:11.5px;color:var(--cp-text-muted);display:flex;align-items:center;padding:14px 4px;">Loading calendar dates...</div>
      </div>

      <div class="cp-section-title">2. Select Time Slot</div>
      <div class="cp-slots-grid" id="cp-slots-grid-container">
        <div style="font-size:11.5px;color:var(--cp-text-muted);grid-column:span 3;text-align:center;padding:12px;">Loading slots...</div>
      </div>

      <div class="cp-section-title">3. Your Contact Details</div>
      <div class="cp-form-group">
        <label class="cp-form-label">Full Name *</label>
        <input type="text" class="cp-form-input" id="cp-book-name" placeholder="Your name" value="${escapeHtml(existingName)}" />
      </div>
      <div class="cp-form-group">
        <label class="cp-form-label">Phone Number *</label>
        <input type="tel" class="cp-form-input" id="cp-book-phone" placeholder="+91 98765 43210" value="${escapeHtml(existingPhone)}" />
      </div>
      <div class="cp-form-group">
        <label class="cp-form-label">Email Address *</label>
        <input type="email" class="cp-form-input" id="cp-book-email" placeholder="you@example.com" value="${escapeHtml(existingEmail)}" />
      </div>
      <div class="cp-form-group">
        <label class="cp-form-label">Topic or Notes (Optional)</label>
        <input type="text" class="cp-form-input" id="cp-book-notes" placeholder="e.g. Inquiring about enterprise setup" value="${escapeHtml(existingNotes)}" />
      </div>

      <!-- Bottom Sticky Confirm Booking Footer -->
      <div class="cp-sticky-booking-footer">
        <button class="cp-submit-btn" id="cp-btn-confirm-booking" style="width:100%;margin:0;">
          Confirm Appointment
        </button>
      </div>
    `;

    function renderSlotsGrid(slots) {
      const slotsGrid = shadow.getElementById('cp-slots-grid-container');
      if (!slotsGrid) return;
      slotsGrid.innerHTML = '';
      let firstAvailable = null;
      selectedSlot = null;

      slots.forEach(s => {
        const pill = document.createElement('div');
        pill.className = `cp-slot-pill ${s.available ? '' : 'disabled'}`;
        pill.textContent = s.time;

        if (s.available) {
          if (!firstAvailable) {
            firstAvailable = s;
            pill.classList.add('selected');
            selectedSlot = s.datetime;
          }
          pill.addEventListener('click', () => {
            slotsGrid.querySelectorAll('.cp-slot-pill').forEach(p => p.classList.remove('selected'));
            pill.classList.add('selected');
            selectedSlot = s.datetime;
          });
        }

        slotsGrid.appendChild(pill);
      });
    }

    async function switchSelectedDate(newDate) {
      selectedDate = newDate;
      const dateContainer = shadow.getElementById('cp-date-chips-container');
      if (dateContainer) {
        dateContainer.querySelectorAll('.cp-date-chip').forEach(c => {
          if (c.getAttribute('data-date') === newDate) {
            c.classList.add('selected');
          } else {
            c.classList.remove('selected');
          }
        });
      }

      const slotsGrid = shadow.getElementById('cp-slots-grid-container');
      if (slotsGrid) {
        slotsGrid.innerHTML = '<div style="font-size:11.5px;color:var(--cp-text-muted);grid-column:span 3;text-align:center;padding:12px;">Loading slots...</div>';
      }

      try {
        const url = `${baseUrl}/api/widget_actions.php?action=get_slots&user_id=${currentAgent.id}&date=${newDate}&company_key=${encodeURIComponent(companyKey)}`;
        const res = await fetch(url);
        const data = await res.json();
        if (data.success && Array.isArray(data.slots)) {
          renderSlotsGrid(data.slots);
        }
      } catch(e) {
        console.error(e);
      }
    }

    // Load available slots from backend
    try {
      const url = `${baseUrl}/api/widget_actions.php?action=get_slots&user_id=${currentAgent.id}&date=${dateStr || ''}&company_key=${encodeURIComponent(companyKey)}`;
      const res = await fetch(url);
      const data = await res.json();

      if (data.success) {
        selectedDate = data.date;

        // Render Dates
        const dateContainer = shadow.getElementById('cp-date-chips-container');
        if (dateContainer && Array.isArray(data.dates)) {
          dateContainer.innerHTML = '';
          data.dates.forEach(d => {
            const chip = document.createElement('div');
            chip.className = `cp-date-chip ${d.selected ? 'selected' : ''}`;
            chip.setAttribute('data-date', d.date);
            chip.innerHTML = `
              <div class="cp-date-chip-day">${escapeHtml(d.day)}</div>
              <div class="cp-date-chip-label">${escapeHtml(d.label)}</div>
            `;
            chip.addEventListener('click', () => switchSelectedDate(d.date));
            dateContainer.appendChild(chip);
          });
        }

        // Render Slots
        if (Array.isArray(data.slots)) {
          renderSlotsGrid(data.slots);
        }
      }
    } catch(e) {
      console.error(e);
    }

    // Bind Confirm Button
    const confirmBtn = shadow.getElementById('cp-btn-confirm-booking');
    if (confirmBtn) {
      confirmBtn.addEventListener('click', async () => {
        const nameVal = shadow.getElementById('cp-book-name').value.trim();
        const phoneVal = shadow.getElementById('cp-book-phone').value.trim();
        const emailVal = shadow.getElementById('cp-book-email').value.trim();
        const notesVal = shadow.getElementById('cp-book-notes').value.trim();

        if (!selectedSlot) {
          alert('Please select an available time slot.');
          return;
        }
        if (!nameVal || (!phoneVal && !emailVal)) {
          alert('Please provide your name and phone number or email.');
          return;
        }

        // Update local memory and storage
        if (nameVal) { visitorName = nameVal; try { localStorage.setItem(STORAGE_KEYS.VISITOR_NAME, nameVal); } catch(e){} }
        if (phoneVal) { visitorPhone = phoneVal; try { localStorage.setItem(STORAGE_KEYS.VISITOR_PHONE, phoneVal); } catch(e){} }
        if (emailVal) { visitorEmail = emailVal; try { localStorage.setItem(STORAGE_KEYS.VISITOR_EMAIL, emailVal); } catch(e){} }

        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Scheduling...';

        try {
          const res = await fetch(`${baseUrl}/api/widget_actions.php?action=book_appointment`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Company-Key': companyKey },
            body: JSON.stringify({
              company_key: companyKey,
              user_id: currentAgent.id,
              slot_datetime: selectedSlot,
              name: nameVal,
              phone: phoneVal,
              email: emailVal,
              notes: notesVal,
              session_token: sessionId,
              conversation_id: conversationId
            })
          });
          const result = await res.json();
          if (result.success) {
            navigateTo('book-confirmed', result);
          } else {
            alert(result.error || 'Could not schedule appointment. Please choose another slot.');
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Confirm Appointment';
          }
        } catch(e) {
          alert('Connection error scheduling appointment.');
          confirmBtn.disabled = false;
          confirmBtn.textContent = 'Confirm Appointment';
        }
      });
    }
  }

  // -------------------------------------------------------------
  // SCREEN 7: BOOKING CONFIRMED & ADD TO CALENDAR (.ICS)
  // Exact match: media_1790861406544.png
  // -------------------------------------------------------------
  function renderBookConfirmed(data) {
    if (!screensView) return;

    let formattedDate = 'Today, 1 Oct 2026';
    let formattedTime = '4:30 PM';
    if (data.slot_datetime) {
      try {
        const dt = new Date(data.slot_datetime.replace(/-/g, '/'));
        const now = new Date();
        const isToday = dt.toDateString() === now.toDateString();
        const dayStr = isToday ? 'Today' : dt.toLocaleDateString('en-US', { weekday: 'short' });
        formattedDate = `${dayStr}, ${dt.getDate()} ${dt.toLocaleDateString('en-US', { month: 'short' })} ${dt.getFullYear()}`;
        formattedTime = dt.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
      } catch(e) {}
    } else if (data.formatted_time) {
      const parts = data.formatted_time.split(' at ');
      formattedDate = parts[0] || data.formatted_time;
      formattedTime = parts[1] || '4:30 PM';
    }

    const agentName = data.agent?.name || selectedAgent?.name || 'Consultant';
    const agentRole = data.agent?.job_title || selectedAgent?.job_title || 'Consultant';
    const meetLink = data.meet_link || 'https://meet.google.com';
    const icsUrl = data.ics_url ? `${baseUrl}/${data.ics_url}` : '#';

    screensView.innerHTML = `
      <div class="cp-confirmed-box">
        <!-- Big Calendar Icon with Green Check Badge (media_1790861406544.png) -->
        <div class="cp-confirmed-badge-wrap">
          <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="4" width="18" height="18" rx="3" ry="3"></rect>
            <line x1="16" y1="2" x2="16" y2="6"></line>
            <line x1="8" y1="2" x2="8" y2="6"></line>
            <line x1="3" y1="10" x2="21" y2="10"></line>
          </svg>
          <div class="cp-confirmed-check-badge">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
          </div>
        </div>

        <div class="cp-confirmed-title">Appointment confirmed!</div>
        <div class="cp-confirmed-desc">
          Your meeting with ${escapeHtml(agentName)} is scheduled.
        </div>

        <!-- Details Card (media_1790861406544.png) -->
        <div class="cp-confirmed-summary-card">
          <div class="cp-confirmed-card-row">
            <div class="cp-confirmed-row-label">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="16" y1="2" x2="16" y2="6"></line>
                <line x1="8" y1="2" x2="8" y2="6"></line>
                <line x1="3" y1="10" x2="21" y2="10"></line>
              </svg>
              <span>Date</span>
            </div>
            <div class="cp-confirmed-row-val">
              <strong>${escapeHtml(formattedDate)}</strong>
            </div>
          </div>

          <div class="cp-confirmed-card-row">
            <div class="cp-confirmed-row-label">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <polyline points="12 6 12 12 16 14"></polyline>
              </svg>
              <span>Time</span>
            </div>
            <div class="cp-confirmed-row-val">
              <strong>${escapeHtml(formattedTime)}</strong> <span style="font-size:11.5px;color:var(--cp-text-secondary);font-weight:normal;">(30 minutes)</span>
            </div>
          </div>

          <div class="cp-confirmed-card-row">
            <div class="cp-confirmed-row-label">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polygon points="23 7 16 12 23 17 23 7"></polygon>
                <rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect>
              </svg>
              <span>Meeting</span>
            </div>
            <div class="cp-confirmed-row-val">
              <a href="${escapeHtml(meetLink)}" target="_blank" style="color:#0284c7;text-decoration:none;font-weight:600;font-size:12px;">Join Video Room &rarr;</a>
            </div>
          </div>
        </div>

        <div style="font-size:11.5px;color:var(--cp-text-muted);text-align:center;margin:6px 0 10px;line-height:1.4;">
          A confirmation email with session details has been sent to your inbox.
        </div>

        ${icsUrl !== '#' ? `
        <a href="${icsUrl}" class="cp-add-calendar-btn" style="text-decoration:none;display:flex;align-items:center;justify-content:center;gap:6px;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
            <line x1="16" y1="2" x2="16" y2="6"></line>
            <line x1="8" y1="2" x2="8" y2="6"></line>
            <line x1="3" y1="10" x2="21" y2="10"></line>
          </svg>
          Add to Calendar (Optional)
        </a>` : ''}

        <div style="margin-top:12px;width:100%;">
          <button class="cp-btn-sm" id="cp-btn-back-chat" style="width:100%;padding:9px;justify-content:center;background:transparent;border:none;color:var(--cp-text-secondary);cursor:pointer;font-weight:500;">
            Back to conversation
          </button>
        </div>
      </div>
    `;

    shadow.getElementById('cp-btn-back-chat').addEventListener('click', () => navigateTo('chat'));
  }

  // -------------------------------------------------------------
  // SCREEN 8: MAKE A PAYMENT — MENU
  // Exact match: media_1790861393761.png
  // -------------------------------------------------------------
  function renderPaymentOptions() {
    if (!screensView) return;

    screensView.innerHTML = `
      <div class="cp-sub-screen-header">
        <div class="cp-sub-screen-title">Make a payment</div>
        <div class="cp-sub-screen-desc">Choose a payment option.</div>
      </div>

      <!-- 1. Pay Online (Razorpay) -->
      <div class="cp-action-card" id="cp-pay-online" role="button" tabindex="0">
        <div class="cp-action-card-left">
          <div class="cp-action-icon-box" style="color:#0284c7;background:rgba(2,132,199,0.08);border-color:rgba(2,132,199,0.18);">
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="2" y="5" width="20" height="14" rx="2"></rect>
              <line x1="2" y1="10" x2="22" y2="10"></line>
            </svg>
          </div>
          <div class="cp-action-text-box">
            <div class="cp-action-card-title">Pay Online (Razorpay)</div>
            <div class="cp-action-card-desc">Secure and instant payment</div>
          </div>
        </div>
        <div class="cp-action-card-right">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="9 18 15 12 9 6"></polyline>
          </svg>
        </div>
      </div>

      <!-- 2. Bank Transfer -->
      <div class="cp-action-card" id="cp-pay-bank" role="button" tabindex="0">
        <div class="cp-action-card-left">
          <div class="cp-action-icon-box" style="color:#3b82f6;background:rgba(59,130,246,0.08);border-color:rgba(59,130,246,0.18);">
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M3 21h18M3 10h18M5 10v11M9 10v11M15 10v11M19 10v11M12 2L2 7h20L12 2z"></path>
            </svg>
          </div>
          <div class="cp-action-text-box">
            <div class="cp-action-card-title">Bank Transfer</div>
            <div class="cp-action-card-desc">Pay via bank account (NEFT/UPI)</div>
          </div>
        </div>
        <div class="cp-action-card-right">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="9 18 15 12 9 6"></polyline>
          </svg>
        </div>
      </div>

      <!-- 3. Pay an Invoice -->
      <div class="cp-action-card" id="cp-pay-invoice" role="button" tabindex="0">
        <div class="cp-action-card-left">
          <div class="cp-action-icon-box" style="color:#6366f1;background:rgba(99,102,241,0.08);border-color:rgba(99,102,241,0.18);">
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
              <polyline points="14 2 14 8 20 8"></polyline>
              <line x1="16" y1="13" x2="8" y2="13"></line>
              <line x1="16" y1="17" x2="8" y2="17"></line>
            </svg>
          </div>
          <div class="cp-action-text-box">
            <div class="cp-action-card-title">Pay an Invoice</div>
            <div class="cp-action-card-desc">Enter invoice number and pay</div>
          </div>
        </div>
        <div class="cp-action-card-right">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="9 18 15 12 9 6"></polyline>
          </svg>
        </div>
      </div>

      <!-- 4. Custom Amount -->
      <div class="cp-action-card" id="cp-pay-custom" role="button" tabindex="0">
        <div class="cp-action-card-left">
          <div class="cp-action-icon-box" style="color:#10b981;background:rgba(16,185,129,0.08);border-color:rgba(16,185,129,0.18);">
            <span style="font-size:16px;font-weight:700;">₹</span>
          </div>
          <div class="cp-action-text-box">
            <div class="cp-action-card-title">Custom Amount</div>
            <div class="cp-action-card-desc">Pay any amount</div>
          </div>
        </div>
        <div class="cp-action-card-right">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="9 18 15 12 9 6"></polyline>
          </svg>
        </div>
      </div>
    `;

    shadow.getElementById('cp-pay-online').addEventListener('click', () => navigateTo('pay-online'));
    shadow.getElementById('cp-pay-bank').addEventListener('click', () => navigateTo('bank-transfer'));
    shadow.getElementById('cp-pay-invoice').addEventListener('click', () => navigateTo('pay-invoice'));
    shadow.getElementById('cp-pay-custom').addEventListener('click', () => navigateTo('pay-custom'));
  }

  // -------------------------------------------------------------
  // SUB-SCREEN 8A: BANK TRANSFER (NEFT / IMPS / UPI + UTR)
  // -------------------------------------------------------------
  async function renderBankTransfer() {
    if (!screensView) return;

    if (!widgetConfig.bank_name && !widgetConfig.bank_account_no && !widgetConfig.bank_upi_id) {
      try {
        const res = await fetch(`${baseUrl}/api/widget_actions.php?action=get_actions&company_key=${encodeURIComponent(companyKey)}&_t=${Date.now()}`);
        const data = await res.json();
        if (data && data.success && data.settings) {
          applyWidgetConfig(data.settings);
        }
      } catch (e) {}
    }

    const bankName = widgetConfig.bank_name || '';
    const accHolder = widgetConfig.bank_account_holder || (widgetConfig.company ? widgetConfig.company.name : '') || '';
    const accNo = widgetConfig.bank_account_no || '';
    const ifsc = widgetConfig.bank_ifsc || '';
    const upiId = widgetConfig.bank_upi_id || '';
    const qrUrl = widgetConfig.bank_qr_url ? (widgetConfig.bank_qr_url.startsWith('http') ? widgetConfig.bank_qr_url : `${baseUrl}/${widgetConfig.bank_qr_url.replace(/^\/+/, '')}`) : null;

    const hasAnyBankInfo = Boolean(bankName || accNo || ifsc || upiId || qrUrl);

    let bankCardHtml = '';
    if (hasAnyBankInfo) {
      bankCardHtml = `
      <div class="cp-bank-card">
        ${accHolder ? `
        <div class="cp-bank-row">
          <span class="cp-bank-label">A/C Holder</span>
          <span class="cp-bank-val">${escapeHtml(accHolder)}</span>
        </div>` : ''}
        ${bankName ? `
        <div class="cp-bank-row">
          <span class="cp-bank-label">Bank Name</span>
          <span class="cp-bank-val">${escapeHtml(bankName)}</span>
        </div>` : ''}
        ${accNo ? `
        <div class="cp-bank-row">
          <span class="cp-bank-label">Account No.</span>
          <div class="cp-bank-val-box">
            <span class="cp-bank-val">${escapeHtml(accNo)}</span>
            <button class="cp-copy-btn" data-val="${escapeHtml(accNo)}" title="Copy">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
            </button>
          </div>
        </div>` : ''}
        ${ifsc ? `
        <div class="cp-bank-row">
          <span class="cp-bank-label">IFSC Code</span>
          <div class="cp-bank-val-box">
            <span class="cp-bank-val">${escapeHtml(ifsc)}</span>
            <button class="cp-copy-btn" data-val="${escapeHtml(ifsc)}" title="Copy">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
            </button>
          </div>
        </div>` : ''}
        ${upiId ? `
        <div class="cp-bank-row">
          <span class="cp-bank-label">UPI ID</span>
          <div class="cp-bank-val-box">
            <span class="cp-bank-val">${escapeHtml(upiId)}</span>
            <button class="cp-copy-btn" data-val="${escapeHtml(upiId)}" title="Copy">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
            </button>
          </div>
        </div>` : ''}
        ${qrUrl ? `
        <div style="margin-top:10px;text-align:center;padding:10px;background:rgba(255,255,255,0.03);border-radius:8px;border:1px dashed var(--cp-border-input, #282931);">
          <div style="font-size:11px;color:var(--cp-text-muted);margin-bottom:6px;">Scan to pay via any UPI App</div>
          <img src="${escapeHtml(qrUrl)}" alt="UPI QR Code" style="width:120px;height:120px;margin:0 auto;border-radius:6px;background:#ffffff;padding:4px;display:block;" />
        </div>` : ''}
      </div>`;
    } else {
      bankCardHtml = `
      <div class="cp-bank-card" style="text-align:center;padding:24px 16px;">
        <div style="font-weight:600;font-size:13px;margin-bottom:6px;color:var(--cp-text-main);">Bank Details Pending Configuration</div>
        <div style="font-size:12px;color:var(--cp-text-muted);line-height:1.5;">Direct bank transfer details have not been published for this workspace. Please contact support or request an invoice.</div>
      </div>`;
    }

    screensView.innerHTML = `
      <div class="cp-section-title">Verified Bank Details</div>
      ${bankCardHtml}

      <div class="cp-section-title">Submit Transfer Details (Instant Receipt)</div>
      <div class="cp-form-group">
        <label class="cp-form-label">Transfer Amount (₹) *</label>
        <div class="cp-amount-chips">
          <span class="cp-amount-chip" data-amt="5000">₹5,000</span>
          <span class="cp-amount-chip active" data-amt="15000">₹15,000</span>
          <span class="cp-amount-chip" data-amt="45000">₹45,000</span>
        </div>
        <input type="number" class="cp-form-input" id="cp-pay-amount" placeholder="Amount in ₹" value="15000" />
      </div>

      <div class="cp-form-group">
        <label class="cp-form-label">Payer Full Name *</label>
        <input type="text" class="cp-form-input" id="cp-pay-name" placeholder="Name as per bank record" value="${escapeHtml(visitorName)}" />
      </div>

      <div class="cp-form-group">
        <label class="cp-form-label">UTR / Transaction Reference No. *</label>
        <input type="text" class="cp-form-input" id="cp-pay-utr" placeholder="12-digit UTR or UPI Ref Number" />
      </div>

      <div class="cp-sticky-booking-footer">
        <button class="cp-submit-btn" id="cp-btn-submit-utr" style="width:100%;margin:0;">
          Submit Payment for Verification
        </button>
      </div>

      <div id="cp-pay-success-box" style="display:none;margin-top:10px;"></div>
    `;

    // Copy handlers
    screensView.querySelectorAll('.cp-copy-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const val = btn.getAttribute('data-val');
        if (navigator.clipboard) {
          navigator.clipboard.writeText(val);
          btn.style.color = '#10B981';
          setTimeout(() => { btn.style.color = ''; }, 1200);
        }
      });
    });

    // Preset amount chips
    screensView.querySelectorAll('.cp-amount-chip').forEach(chip => {
      chip.addEventListener('click', () => {
        screensView.querySelectorAll('.cp-amount-chip').forEach(c => c.classList.remove('active'));
        chip.classList.add('active');
        shadow.getElementById('cp-pay-amount').value = chip.getAttribute('data-amt');
      });
    });

    // Submit UTR
    const submitBtn = shadow.getElementById('cp-btn-submit-utr');
    if (submitBtn) {
      submitBtn.addEventListener('click', async () => {
        const amt = parseInt(shadow.getElementById('cp-pay-amount').value, 10);
        const name = shadow.getElementById('cp-pay-name').value.trim();
        const utr = shadow.getElementById('cp-pay-utr').value.trim();

        if (!amt || amt <= 0 || !name || !utr) {
          alert('Please enter amount, payer name, and UTR reference.');
          return;
        }

        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting...';

        try {
          const res = await fetch(`${baseUrl}/api/widget_actions.php?action=submit_bank_transfer`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Company-Key': companyKey },
            body: JSON.stringify({
              company_key: companyKey,
              session_token: sessionId,
              conversation_id: conversationId,
              amount: amt,
              payer_name: name,
              utr_number: utr
            })
          });
          const result = await res.json();
          if (result.success) {
            submitBtn.style.display = 'none';
            const succBox = shadow.getElementById('cp-pay-success-box');
            succBox.style.display = 'block';
            succBox.innerHTML = `
              <div style="background:rgba(16,185,129,0.12);border:1px solid rgba(16,185,129,0.3);border-radius:10px;padding:14px;text-align:center;">
                <div style="font-weight:700;color:#10B981;font-size:14px;">Receipt Generated #${result.payment_id}</div>
                <div style="font-size:12px;color:var(--cp-text-secondary);margin-top:4px;">
                  Amount: <strong>${result.formatted_amt}</strong> • UTR: <code>${result.utr}</code><br/>
                  Status: <span style="color:#F59E0B;font-weight:600;">Verification Pending (15-30m)</span>
                </div>
                <button class="cp-btn-sm" id="cp-pay-done-btn" style="margin-top:10px;width:100%;justify-content:center;">Back to chat</button>
              </div>
            `;
            shadow.getElementById('cp-pay-done-btn').addEventListener('click', () => navigateTo('chat'));
          } else {
            alert(result.error || 'Submission error.');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Submit Payment for Verification';
          }
        } catch(e) {
          alert('Connection error submitting transfer details.');
          submitBtn.disabled = false;
          submitBtn.textContent = 'Submit Payment for Verification';
        }
      });
    }
  }

  // -------------------------------------------------------------
  // SUB-SCREEN 8B: PAY ONLINE / CREATE PAYMENT REQUEST
  // -------------------------------------------------------------
  function renderPayOnline(opts) {
    if (!screensView) return;

    opts = opts || {};
    const defaultAmount = opts.amount || 10000;
    const defaultTitle = opts.title || opts.item_title || 'Course Enrollment';
    const initialName = (opts.name || visitorName || '').trim();
    const initialEmail = (opts.email || visitorEmail || '').trim();
    const initialPhone = (opts.phone || visitorPhone || '').trim();

    screensView.innerHTML = `
      <div class="cp-sub-screen-header">
        <div class="cp-sub-screen-title">Payment &amp; Enrollment</div>
        <div class="cp-sub-screen-desc">Secure checkout with instant receipt and confirmation</div>
      </div>

      <div class="cp-form-group">
        <label class="cp-form-label">Item / Service Title</label>
        <input type="text" class="cp-form-input" id="cp-online-title" value="${escapeHtml(defaultTitle)}" placeholder="Course or service name" />
      </div>

      <div class="cp-form-group">
        <label class="cp-form-label">Payable Amount (₹) *</label>
        <div class="cp-amount-chips">
          <span class="cp-amount-chip ${defaultAmount === 2500 ? 'active' : ''}" data-amt="2500">₹2,500</span>
          <span class="cp-amount-chip ${defaultAmount === 10000 ? 'active' : ''}" data-amt="10000">₹10,000</span>
          <span class="cp-amount-chip ${defaultAmount === 25000 ? 'active' : ''}" data-amt="25000">₹25,000</span>
          ${(defaultAmount !== 2500 && defaultAmount !== 10000 && defaultAmount !== 25000) ? `<span class="cp-amount-chip active" data-amt="${defaultAmount}">₹${Number(defaultAmount).toLocaleString('en-IN')}</span>` : ''}
        </div>
        <input type="number" class="cp-form-input" id="cp-online-amount" placeholder="Amount in ₹" value="${defaultAmount}" />
      </div>

      <div class="cp-form-group">
        <label class="cp-form-label">Full Name *</label>
        <input type="text" class="cp-form-input" id="cp-online-name" placeholder="Full name" value="${escapeHtml(initialName)}" />
      </div>

      <div class="cp-form-group">
        <label class="cp-form-label">Email Address *</label>
        <input type="email" class="cp-form-input" id="cp-online-email" placeholder="name@example.com" value="${escapeHtml(initialEmail)}" />
      </div>

      <div class="cp-form-group">
        <label class="cp-form-label">WhatsApp / Phone Number *</label>
        <input type="tel" class="cp-form-input" id="cp-online-phone" placeholder="+91 98765 43210" value="${escapeHtml(initialPhone)}" />
      </div>

      <div class="cp-sticky-booking-footer">
        <button class="cp-submit-btn" id="cp-btn-initiate-online" style="width:100%;margin:0;">
          Proceed to Pay Securely
        </button>
      </div>

      <div id="cp-online-status-box" style="display:none;margin-top:14px;"></div>
    `;

    screensView.querySelectorAll('.cp-amount-chip').forEach(chip => {
      chip.addEventListener('click', () => {
        screensView.querySelectorAll('.cp-amount-chip').forEach(c => c.classList.remove('active'));
        chip.classList.add('active');
        shadow.getElementById('cp-online-amount').value = chip.getAttribute('data-amt');
      });
    });

    const initBtn = shadow.getElementById('cp-btn-initiate-online');
    if (initBtn) {
      initBtn.addEventListener('click', async () => {
        const amt = parseInt(shadow.getElementById('cp-online-amount').value, 10);
        const name = shadow.getElementById('cp-online-name').value.trim();
        const email = shadow.getElementById('cp-online-email').value.trim();
        const phone = shadow.getElementById('cp-online-phone').value.trim();
        const itemTitle = shadow.getElementById('cp-online-title').value.trim() || 'Course Enrollment';

        if (!amt || amt <= 0) {
          alert('Please enter a valid payment amount.');
          return;
        }
        if (!name) {
          alert('Please enter your full name.');
          return;
        }
        if (!email || !email.includes('@')) {
          alert('Please enter a valid email address to receive your payment instructions.');
          return;
        }
        if (!phone || phone.replace(/[^0-9]/g, '').length < 7) {
          alert('Please enter a valid phone number.');
          return;
        }

        // Save visitor credentials for session continuity
        visitorName = name;
        visitorEmail = email;
        visitorPhone = phone;
        widgetStorage.setItem(STORAGE_KEYS.VISITOR_NAME, visitorName);
        widgetStorage.setItem(STORAGE_KEYS.VISITOR_EMAIL, visitorEmail);
        widgetStorage.setItem(STORAGE_KEYS.VISITOR_PHONE, visitorPhone);

        initBtn.disabled = true;
        initBtn.textContent = 'Generating Payment Request...';

        try {
          const res = await fetch(`${baseUrl}/api/payment_requests.php?action=create`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-Company-Key': companyKey
            },
            body: JSON.stringify({
              company_key: companyKey,
              name: name,
              email: email,
              phone: phone,
              amount: amt,
              item_title: itemTitle,
              session_id: sessionId,
              conversation_id: conversationId
            })
          });

          const result = await res.json();
          if (!result.success) {
            alert(result.error || 'Failed to create payment request.');
            initBtn.disabled = false;
            initBtn.textContent = 'Proceed to Pay Securely';
            return;
          }

          // Payment request successfully created! Render instructions & options view
          const details = result.payment_details || {};
          const statusBox = shadow.getElementById('cp-online-status-box');
          statusBox.style.display = 'block';

          let bankHtml = '';
          if (details.account_number) {
            bankHtml = `
              <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin:10px 0;text-align:left;font-size:12px;">
                <div style="font-weight:700;color:#0f172a;margin-bottom:6px;text-transform:uppercase;font-size:11px;">Bank Transfer (NEFT / IMPS):</div>
                <div>A/C Holder: <strong>${escapeHtml(details.holder_name || '')}</strong></div>
                <div>Bank: <strong>${escapeHtml(details.bank_name || '')}</strong></div>
                <div>A/C No: <code style="font-weight:700;">${escapeHtml(details.account_number || '')}</code></div>
                <div>IFSC: <code style="font-weight:700;">${escapeHtml(details.ifsc || '')}</code></div>
                <div style="margin-top:4px;">UPI ID: <strong style="color:#0284c7;">${escapeHtml(details.upi_id || '')}</strong></div>
              </div>
            `;
          }

          let qrHtml = '';
          if (details.qr_code_url) {
            const qrSrc = details.qr_code_url.startsWith('http') ? details.qr_code_url : `${baseUrl}/${details.qr_code_url.replace(/^\/+/, '')}`;
            qrHtml = `
              <div style="text-align:center;margin:12px 0;">
                <div style="font-size:11px;color:var(--cp-text-secondary);margin-bottom:6px;">Scan UPI QR Code:</div>
                <img src="${qrSrc}" alt="UPI QR" style="max-width:140px;border-radius:6px;border:1px solid #e2e8f0;display:inline-block;" />
              </div>
            `;
          }

          let razorpayBtnHtml = '';
          if (details.razorpay_key && window.Razorpay) {
            razorpayBtnHtml = `
              <button class="cp-submit-btn" id="cp-btn-rzp-checkout" style="width:100%;margin-top:8px;background:#0284c7;">
                Pay Online via Razorpay (UPI / Card / NetBanking)
              </button>
            `;
          }

          statusBox.innerHTML = `
            <div style="background:rgba(2,132,199,0.06);border:1px solid rgba(2,132,199,0.25);border-radius:10px;padding:16px;text-align:center;">
              <div style="display:inline-flex;align-items:center;gap:6px;background:#0284c7;color:#fff;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;text-transform:uppercase;margin-bottom:8px;">
                Request Generated
              </div>
              <div style="font-weight:800;color:var(--cp-text-title);font-size:16px;">
                #${escapeHtml(result.request_code)}
              </div>
              <div style="font-size:12.5px;color:var(--cp-text-secondary);margin-top:4px;">
                Payable: <strong>₹${Number(amt).toLocaleString('en-IN')}</strong> for <em>${escapeHtml(itemTitle)}</em>
              </div>
              <div style="font-size:11px;color:var(--cp-text-muted);margin-top:6px;background:rgba(255,255,255,0.7);padding:8px;border-radius:6px;border:1px dashed #cbd5e1;">
                Payment instructions and company bank details have been sent to <strong>${escapeHtml(email)}</strong>.
              </div>

              ${qrHtml}
              ${bankHtml}
              ${razorpayBtnHtml}

              <button class="cp-btn-sm" id="cp-btn-payment-done" style="margin-top:10px;width:100%;justify-content:center;background:var(--cp-button-bg, #0f172a);color:#fff;padding:8px;border-radius:6px;">
                Back to conversation
              </button>
            </div>
          `;

          // Hide initiate button once request is generated
          initBtn.style.display = 'none';

          const rzpBtn = shadow.getElementById('cp-btn-rzp-checkout');
          if (rzpBtn) {
            rzpBtn.addEventListener('click', () => {
              const options = {
                key: details.razorpay_key,
                amount: amt * 100,
                currency: 'INR',
                name: widgetConfig.brand_name || (widgetConfig.company ? widgetConfig.company.name : 'CuboidPilot'),
                description: `${itemTitle} - ${result.request_code}`,
                prefill: {
                  name: name,
                  email: email,
                  contact: phone
                },
                theme: { color: '#0f172a' },
                handler: function (response) {
                  alert('Payment successful! Payment ID: ' + response.razorpay_payment_id);
                  navigateTo('chat');
                }
              };
              const rzp = new window.Razorpay(options);
              rzp.open();
            });
          }

          const doneBtn = shadow.getElementById('cp-btn-payment-done');
          if (doneBtn) {
            doneBtn.addEventListener('click', () => navigateTo('chat'));
          }

        } catch (e) {
          console.error(e);
          alert('Network error while creating payment request.');
          initBtn.disabled = false;
          initBtn.textContent = 'Proceed to Pay Securely';
        }
      });
    }
  }

  // -------------------------------------------------------------
  // SUB-SCREEN 8C: PAY AN INVOICE
  // -------------------------------------------------------------
  function renderPayInvoice() {
    if (!screensView) return;

    screensView.innerHTML = `
      <div class="cp-sub-screen-header">
        <div class="cp-sub-screen-title">Pay an Invoice</div>
        <div class="cp-sub-screen-desc">Enter invoice number to view balance & pay</div>
      </div>

      <div class="cp-form-group">
        <label class="cp-form-label">Invoice Number *</label>
        <input type="text" class="cp-form-input font-mono" id="cp-inv-number" placeholder="e.g. INV-2026-089" />
      </div>

      <div class="cp-form-group">
        <label class="cp-form-label">Invoice Amount (₹) *</label>
        <input type="number" class="cp-form-input" id="cp-inv-amount" placeholder="Amount as per invoice" />
      </div>

      <div class="cp-form-group">
        <label class="cp-form-label">Your Email / Phone *</label>
        <input type="text" class="cp-form-input" id="cp-inv-contact" placeholder="Email on invoice" />
      </div>

      <div class="cp-sticky-booking-footer">
        <button class="cp-submit-btn" id="cp-btn-pay-inv" style="width:100%;margin:0;">
          Pay Invoice Now
        </button>
      </div>
    `;

    const payBtn = shadow.getElementById('cp-btn-pay-inv');
    if (payBtn) {
      payBtn.addEventListener('click', () => {
        const inv = shadow.getElementById('cp-inv-number').value.trim();
        const amt = shadow.getElementById('cp-inv-amount').value.trim();
        if (!inv || !amt) {
          alert('Please enter your invoice number and amount.');
          return;
        }
        navigateTo('bank-transfer');
      });
    }
  }

  // -------------------------------------------------------------
  // SUB-SCREEN 8D: CUSTOM AMOUNT
  // -------------------------------------------------------------
  function renderPayCustom() {
    if (!screensView) return;

    screensView.innerHTML = `
      <div class="cp-sub-screen-header">
        <div class="cp-sub-screen-title">Custom Amount</div>
        <div class="cp-sub-screen-desc">Pay any custom amount securely</div>
      </div>

      <div class="cp-form-group">
        <label class="cp-form-label">Enter Custom Amount (₹) *</label>
        <input type="number" class="cp-form-input" id="cp-custom-amount" placeholder="e.g. 7500" style="font-size:16px;font-weight:700;" />
      </div>

      <div class="cp-form-group">
        <label class="cp-form-label">Payment Purpose / Remarks</label>
        <input type="text" class="cp-form-input" id="cp-custom-desc" placeholder="e.g. Consulting retainer, project milestone" />
      </div>

      <div class="cp-form-group">
        <label class="cp-form-label">Choose Transfer Method</label>
        <div style="display:flex;gap:8px;">
          <button class="cp-submit-btn" id="cp-btn-custom-upi" style="flex:1;margin:0;">
            Bank Transfer / UPI
          </button>
          <button class="cp-submit-btn" id="cp-btn-custom-online" style="flex:1;margin:0;">
            Card / NetBanking
          </button>
        </div>
      </div>
    `;

    shadow.getElementById('cp-btn-custom-upi').addEventListener('click', () => navigateTo('bank-transfer'));
    shadow.getElementById('cp-btn-custom-online').addEventListener('click', () => navigateTo('pay-online'));
  }

  // 14. Initialize
  applyTheme(currentTheme);
  loadConfig();
  initVisitorState();
  restoreHistory();
  updateSoundUi();
  const scriptInitialScreen = (currentScript && (currentScript.getAttribute('data-screen') || currentScript.getAttribute('data-initial-screen'))) || '';
  if (scriptInitialScreen === 'chat') {
    navigateTo('chat');
  } else {
    navigateTo('home');
  }

  if (isEmbedded) {
    toggleWidget(true);
  } else {
    // Keep floating widget docked and closed by default until explicitly clicked
    initTeaserNotification();
  }

  // Auto-start polling if an active conversation exists
  if (conversationId) {
    startHumanPolling();
  }

  // Cross-window and Cross-frame Storage Synchronization
  window.addEventListener('storage', (e) => {
    if (!e || !e.key) return;
    if (e.key === STORAGE_KEYS.CONVO_ID && e.newValue) {
      const newId = parseInt(e.newValue, 10);
      if (newId && newId !== conversationId) {
        conversationId = newId;
        startHumanPolling();
      }
    } else if (e.key === STORAGE_KEYS.MESSAGES && e.newValue) {
      try {
        const msgs = JSON.parse(e.newValue);
        if (Array.isArray(msgs) && msgs.length > 0) {
          const currentBubbles = chatStream ? chatStream.querySelectorAll('.cp-msg-row').length : 0;
          if (msgs.length > currentBubbles) {
            renderChatHistory();
          }
        }
      } catch (err) {}
    }
  });

  window.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'CP_SYNC_CONVERSATION') {
      if (event.data.conversationId && event.data.conversationId !== conversationId) {
        conversationId = event.data.conversationId;
        widgetStorage.setItem(STORAGE_KEYS.CONVO_ID, conversationId);
        startHumanPolling();
      }
      if (event.data.sessionId && event.data.sessionId !== sessionId) {
        sessionId = event.data.sessionId;
        widgetStorage.setItem(STORAGE_KEYS.SESSION_ID, sessionId);
      }
    }
  });

  // Mobile drawer collision protector: automatically hide floating widget if a full-screen drawer or modal is open
  if (!isEmbedded && hostElement) {
    const syncDrawerVisibility = () => {
      try {
        const isDrawerOpen = !!(
          document.body.classList.contains('menu-open') || 
          document.body.classList.contains('drawer-open') ||
          document.querySelector('#mobile-drawer.open') ||
          document.querySelector('.mobile-menu.open') ||
          document.querySelector('[data-mobile-drawer].open')
        );
        hostElement.style.visibility = isDrawerOpen ? 'hidden' : '';
        hostElement.style.pointerEvents = isDrawerOpen ? 'none' : '';
      } catch (err) {}
    };

    try {
      const drawerObserver = new MutationObserver(syncDrawerVisibility);
      drawerObserver.observe(document.body, { attributes: true, attributeFilter: ['class', 'style'], subtree: true });
      window.addEventListener('resize', syncDrawerVisibility, { passive: true });
    } catch (e) {}
  }

  window.CuboidPilot = {
    open: (screen = 'home') => {
      toggleWidget(true);
      const targetScreen = (screen && typeof screen === 'string') ? screen : 'home';
      navigateTo(targetScreen);
    },
    close: () => toggleWidget(false),
    toggle: () => toggleWidget(),
    navigateTo: (screen, data) => navigateTo(screen, data),
    getScreen: () => currentScreen,
    setTheme: (t) => applyTheme(t),
    getTheme: () => currentTheme,
    updateConfig: (cfg) => applyWidgetConfig(cfg),
    isOpen: () => chatWindow && chatWindow.classList.contains('open'),
    setWaveEnabled: (enabled) => { isWaveAnimationEnabled = !!enabled; },
    isWaveEnabled: () => isWaveAnimationEnabled,
    triggerWave: () => triggerAssistantWave(),
    playWakeSound: () => playAssistantWakeSound(),
    ask: (q) => {
      toggleWidget(true);
      navigateTo('chat');
      inputField.value = q;
      handleSend();
    }
  };
  window.CuboidPilotWidget = window.CuboidPilot;
  window.Cai = window.CuboidPilot;

})();
