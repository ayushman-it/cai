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
      } else {
        baseUrl = urlObj.origin + urlObj.pathname.substring(0, urlObj.pathname.lastIndexOf('/'));
      }
    } catch (e) {
      baseUrl = window.location.hostname.includes('cuboidsoft.in') 
        ? 'https://cai.cuboidsoft.in' 
        : window.location.origin + '/cuboidpilot';
    }
  }

  // Fallback if loaded directly as file or root
  if (!baseUrl || baseUrl === 'null') {
    baseUrl = window.location.hostname.includes('cuboidsoft.in')
      ? 'https://cai.cuboidsoft.in'
      : window.location.origin + '/cuboidpilot';
  } else if (baseUrl === window.location.origin) {
    if (window.location.hostname === 'cai.cuboidsoft.in') {
      baseUrl = 'https://cai.cuboidsoft.in';
    } else {
      baseUrl = window.location.origin + '/cuboidpilot';
    }
  }

  const companyKey = (currentScript && currentScript.getAttribute('data-company')) || 
                     (currentScript && currentScript.getAttribute('data-key')) || 
                     'cp_live_cuboidsoft';

  // 2. Storage Session Helpers (Sections 7, 8 & 9)
  const STORAGE_KEYS = {
    SESSION_ID: 'cp_session_id',
    CONVO_ID: 'cp_conversation_id',
    LEAD_ID: 'cp_lead_id',
    CUSTOMER_ID: 'cp_customer_id',
    VISITOR_NAME: 'cp_visitor_name',
    IS_IDENTIFIED: 'cp_is_identified',
    LEAD_STEP: 'cp_lead_step',
    MESSAGES: 'cp_chat_history',
    STATE: 'cp_widget_open'
  };

  let sessionId = sessionStorage.getItem(STORAGE_KEYS.SESSION_ID);
  if (!sessionId) {
    sessionId = 'sess_' + Math.random().toString(36).substring(2, 10) + Date.now().toString(36);
    sessionStorage.setItem(STORAGE_KEYS.SESSION_ID, sessionId);
  }

  let conversationId = sessionStorage.getItem(STORAGE_KEYS.CONVO_ID) ? parseInt(sessionStorage.getItem(STORAGE_KEYS.CONVO_ID), 10) : null;
  let leadId = sessionStorage.getItem(STORAGE_KEYS.LEAD_ID) ? parseInt(sessionStorage.getItem(STORAGE_KEYS.LEAD_ID), 10) : null;
  let customerId = sessionStorage.getItem(STORAGE_KEYS.CUSTOMER_ID) ? parseInt(sessionStorage.getItem(STORAGE_KEYS.CUSTOMER_ID), 10) : null;
  let visitorName = sessionStorage.getItem(STORAGE_KEYS.VISITOR_NAME) || '';
  let isIdentified = sessionStorage.getItem(STORAGE_KEYS.IS_IDENTIFIED) === '1';
  let leadStep = isIdentified ? 0 : parseInt(sessionStorage.getItem(STORAGE_KEYS.LEAD_STEP) || '1', 10);

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
    document.body.appendChild(hostElement);
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
      --cp-tool-btn-hover-bg: rgba(255, 255, 255, 0.06);
      --cp-shadow-window: 0 24px 50px -10px rgba(0, 0, 0, 0.75), 0 10px 25px -5px rgba(0, 0, 0, 0.5);
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
    }

    /* Light Theme (Intercom Clean Light Aesthetic) */
    :host([data-theme="light"]), 
    :host-context([data-theme="light"]),
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
      --cp-tool-btn-hover-bg: rgba(0, 0, 0, 0.05);
      --cp-shadow-window: 0 24px 50px -10px rgba(0, 0, 0, 0.16), 0 10px 25px -5px rgba(0, 0, 0, 0.08);
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
      .cp-teaser {
        right: 16px;
        bottom: 76px;
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
      transition: opacity 0.28s cubic-bezier(0.16, 1, 0.3, 1), transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
      z-index: 2147483646;
    }

    .cp-window.open {
      opacity: 1;
      pointer-events: auto;
      transform: translateY(0) scale(1);
    }

    /* Window Header */
    .cp-header {
      background-color: var(--cp-bg-surface);
      border-bottom: 1px solid var(--cp-border-header);
      padding: 14px 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      user-select: none;
      flex-shrink: 0;
    }

    .cp-header-left {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .cp-back-btn {
      background: transparent;
      border: none;
      color: var(--cp-text-secondary);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 2px;
      margin-right: 2px;
      transition: color 0.15s;
    }

    .cp-back-btn:hover {
      color: var(--cp-text-title);
    }

    /* Cai Brand Logo (media_1790705726663.png) */
    .cp-brand-logo {
      height: 24px;
      width: auto;
      max-width: 32px;
      object-fit: contain;
      flex-shrink: 0;
      display: block;
      margin-right: 2px;
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
      margin-left: 2px;
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
      width: 185px;
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
      padding: 16px 14px 12px 14px;
      display: flex;
      flex-direction: column;
      gap: 12px;
      scroll-behavior: smooth;
    }

    .cp-messages::-webkit-scrollbar {
      width: 4px;
    }

    .cp-messages::-webkit-scrollbar-thumb {
      background: #282a35;
      border-radius: 10px;
    }

    /* Stream container ensures full width and flex alignment */
    #cp-chat-stream {
      display: flex;
      flex-direction: column;
      gap: 12px;
      width: 100%;
    }

    /* Message Bubbles */
    .cp-msg-row {
      display: flex;
      flex-direction: column;
      gap: 4px;
      animation: msgPop 0.22s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes msgPop {
      from { opacity: 0; transform: translateY(6px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .cp-msg-row.ai {
      align-items: flex-start;
      align-self: flex-start;
      max-width: 88%;
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

    /* Composer Section (Strict Intercom Fin match) */
    .cp-composer-section {
      padding: 0 16px 14px 16px;
      background: var(--cp-bg-canvas);
      flex-shrink: 0;
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
      background: var(--cp-accent-custom, var(--cp-send-btn-active-bg, #ffffff));
      border: 1px solid var(--cp-accent-custom, var(--cp-send-btn-active-border, #ffffff));
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

      .cp-input-field {
        font-size: 16px !important; /* Prevents auto zoom in iOS Safari */
      }

      .cp-icon-btn, .cp-back-btn {
        min-width: 44px;
        min-height: 44px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
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
      padding: 12px 14px 8px 14px;
      display: flex;
      flex-direction: column;
      gap: 8px;
      scroll-behavior: smooth;
    }

    .cp-screens-view::-webkit-scrollbar {
      width: 4px;
    }

    .cp-screens-view::-webkit-scrollbar-thumb {
      background: #282a35;
      border-radius: 10px;
    }

    /* Header Avatar Stack on Home Screen (media_1790884814897.png) */
    /* Header Avatar Stack on Home Screen (media_1790919508920.png) */
    .cp-team-avatar-stack {
      display: none;
      align-items: center;
      margin-right: 8px;
    }

    .cp-window.screen-home .cp-team-avatar-stack {
      display: flex;
    }

    .cp-window.screen-home .cp-header-title-box {
      display: none;
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
    .cp-featured-banner-card {
      background: #181920;
      border: 1px solid #282932;
      border-radius: 14px;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      cursor: pointer;
      box-shadow: 0 3px 12px rgba(0, 0, 0, 0.25);
      transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
      user-select: none;
    }

    .cp-featured-banner-card:hover {
      border-color: #3b3e4e;
      transform: translateY(-1px);
      box-shadow: 0 6px 20px rgba(0, 0, 0, 0.35);
    }

    :host([data-theme="light"]) .cp-featured-banner-card,
    .cp-light-theme .cp-featured-banner-card,
    .theme-light .cp-featured-banner-card {
      background: #ffffff;
      border-color: #e2e8f0;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    }

    :host([data-theme="light"]) .cp-featured-banner-card:hover,
    .cp-light-theme .cp-featured-banner-card:hover,
    .theme-light .cp-featured-banner-card:hover {
      background: #f8fafc;
      border-color: #cbd5e1;
      box-shadow: 0 6px 18px rgba(0, 0, 0, 0.08);
    }

    .cp-banner-img-box {
      width: 100%;
      height: 128px;
      position: relative;
      overflow: hidden;
      background: #111217;
    }

    .cp-banner-cover-photo {
      width: 100%;
      height: 100%;
      object-fit: cover;
      object-position: center 20%;
      display: block;
      transition: transform 0.3s ease;
    }

    .cp-featured-banner-card:hover .cp-banner-cover-photo {
      transform: scale(1.03);
    }

    .cp-banner-badge-tag {
      position: absolute;
      top: 10px;
      left: 10px;
      background: rgba(0, 0, 0, 0.75);
      backdrop-filter: blur(6px);
      color: #fbbf24;
      font-size: 9.5px;
      font-weight: 750;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      padding: 3px 7px;
      border-radius: 4px;
      border: 1px solid rgba(251, 191, 36, 0.3);
    }

    .cp-banner-body {
      padding: 12px 14px 11px 14px;
      display: flex;
      flex-direction: column;
      gap: 3px;
    }

    .cp-banner-headline {
      font-size: 13.5px;
      font-weight: 650;
      color: #ffffff;
      line-height: 1.3;
      letter-spacing: -0.01em;
    }

    :host([data-theme="light"]) .cp-banner-headline,
    .cp-light-theme .cp-banner-headline,
    .theme-light .cp-banner-headline {
      color: #0f172a;
    }

    .cp-banner-subline {
      font-size: 11px;
      color: #8e929f;
      line-height: 1.35;
    }

    :host([data-theme="light"]) .cp-banner-subline,
    .cp-light-theme .cp-banner-subline,
    .theme-light .cp-banner-subline {
      color: #64748b;
    }

    .cp-banner-footer-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-top: 6px;
      padding-top: 6px;
      border-top: 1px solid rgba(255, 255, 255, 0.06);
    }

    :host([data-theme="light"]) .cp-banner-footer-row,
    .cp-light-theme .cp-banner-footer-row,
    .theme-light .cp-banner-footer-row {
      border-top-color: #f1f5f9;
    }

    .cp-banner-meta-pill {
      font-size: 10px;
      color: #717686;
      font-weight: 500;
    }

    :host([data-theme="light"]) .cp-banner-meta-pill,
    .cp-light-theme .cp-banner-meta-pill,
    .theme-light .cp-banner-meta-pill {
      color: #64748b;
    }

    .cp-banner-arrow-link {
      font-size: 11px;
      font-weight: 600;
      color: #ffffff;
      transition: transform 0.2s ease;
    }

    :host([data-theme="light"]) .cp-banner-arrow-link,
    .cp-light-theme .cp-banner-arrow-link,
    .theme-light .cp-banner-arrow-link {
      color: #0f172a;
    }

    .cp-featured-banner-card:hover .cp-banner-arrow-link {
      transform: translateX(2px);
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
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
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
      color: #ffffff;
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

    /* Hide bottom menu in message / chat flow (media_1790870603887.png) */
    #cp-chat-window.screen-chat #cp-bottom-nav,
    #cp-chat-window.screen-human-chat #cp-bottom-nav,
    .cp-window.screen-chat #cp-bottom-nav,
    .cp-window.screen-human-chat #cp-bottom-nav,
    #cp-chat-window.screen-chat .cp-bottom-nav,
    #cp-chat-window.screen-human-chat .cp-bottom-nav,
    .cp-window.screen-chat .cp-bottom-nav,
    .cp-window.screen-human-chat .cp-bottom-nav {
      display: none !important;
      visibility: hidden !important;
      height: 0 !important;
      min-height: 0 !important;
      max-height: 0 !important;
      overflow: hidden !important;
      padding: 0 !important;
      margin: 0 !important;
      border: none !important;
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
      background: #0f172a;
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 12px;
      font-weight: 800;
      line-height: 1;
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

    /* Back Button strictly visible on Chat & inner screens */
    .cp-window.screen-chat .cp-back-btn,
    #cp-chat-window.screen-chat #cp-back-btn,
    .cp-window.screen-human-chat .cp-back-btn,
    #cp-chat-window.screen-human-chat #cp-back-btn {
      display: inline-flex !important;
      visibility: visible !important;
      opacity: 1 !important;
    }
  `;

  shadow.appendChild(styleEl);

  // 5. Build Widget DOM Tree
  const widgetContainer = document.createElement('div');
  widgetContainer.className = 'cp-root-container';
  widgetContainer.innerHTML = `
    <!-- Floating Launcher Button -->
    <button class="cp-launcher" id="cp-launcher-btn" aria-label="Open AI Assistant">
      <!-- Dark Launcher: White Cai logo -->
      <div class="cp-launcher-icon">
        <img src="${baseUrl}/assets/logo-white.png" class="cp-launcher-logo" alt="Cai" />
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
          <img src="${baseUrl}/assets/logo-white.png" class="cp-teaser-logo" alt="Cai" />
        </div>
        <div class="cp-teaser-body">
          <div class="cp-teaser-header">
            Hi there <span>👋</span>
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

    <!-- Main Chat Window (Intercom Cai dark UI) -->
    <div class="cp-window screen-home" id="cp-chat-window" role="dialog" aria-modal="true" aria-label="Chat with Cai">
      
      <!-- Top Header -->
      <header class="cp-header">
        <div class="cp-header-left">
          <button class="cp-back-btn" id="cp-back-btn" aria-label="Back" style="display: none;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="15 18 9 12 15 6"></polyline>
            </svg>
          </button>
          
          <!-- Cai Brand Logo (media_1790705223450.png) -->
          <img src="${baseUrl}/assets/logo-white.png" class="cp-brand-logo" alt="Cai" />

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
          
          <button class="cp-icon-btn" id="cp-close-btn" aria-label="Close Chat">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="18" y1="6" x2="6" y2="18"></line>
              <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
          </button>
        </div>

        <!-- Options Dropdown Menu -->
        <div class="cp-options-menu" id="cp-options-menu">
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
      <div class="cp-screens-view" id="cp-screens-view" style="display: none;"></div>

      <!-- Message History Container (Clean minimal Intercom Cai style) -->
      <main class="cp-messages" id="cp-messages-container">
        
        <!-- Welcome Message Bubble (media_1790678123351.png) -->
        <div class="cp-msg-row ai">
          <div class="cp-bubble" id="cp-welcome-text">
            Hi there 👋<br/><br/>You are now speaking with Cai. How can I help?
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

        <!-- Typing & Thinking Indicator -->
        <div class="cp-typing-row" id="cp-typing-indicator" style="display: none;">
          <div class="cp-typing-avatar">
            <img src="${baseUrl}/assets/logo-white.png" alt="Cai" class="cp-typing-avatar-img">
          </div>
          <div class="cp-typing-bubble">
            <div class="cp-typing-dots">
              <span class="cp-typing-dot"></span>
              <span class="cp-typing-dot"></span>
              <span class="cp-typing-dot"></span>
            </div>
            <span class="cp-typing-label" id="cp-typing-label">Cai is thinking...</span>
          </div>
        </div>

      </main>

      <!-- Bottom Composer Container (Exact Intercom Fin design) -->
      <footer class="cp-composer-section">
        <div class="cp-composer-capsule">
          <textarea 
            class="cp-input-field" 
            id="cp-input-field" 
            placeholder="Ask a question..." 
            rows="1"
            aria-label="Ask Fin a question"
          ></textarea>

          <div class="cp-composer-toolbar">
            <div class="cp-toolbar-actions">
              <!-- Attachment button -->
              <button class="cp-tool-btn" id="cp-tool-attach" title="Attach file" aria-label="Attach file">
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
              <button class="cp-tool-btn" id="cp-tool-gif" title="Search GIF" aria-label="Search GIF">
                <span class="cp-gif-badge">GIF</span>
              </button>

              <!-- Mic / Voice button -->
              <button class="cp-tool-btn" id="cp-tool-mic" title="Voice input" aria-label="Voice input">
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

  // w-up Screen State Machine
  let currentScreen = 'home'; // 'home' | 'chat' | 'human-team' | 'human-chat' | 'book-team' | 'book-slots' | 'book-confirmed' | 'payment-options' | 'news'
  let screenStack = ['home'];
  let teamMembersCache = [];
  let selectedAgent = null;
  let selectedDate = null;
  let selectedSlot = null;
  let humanPollingInterval = null;
  let lastPolledMessageId = 0;
  let isHumanChatActive = false;

  const bottomNav = shadow.getElementById('cp-bottom-nav');
  const navHomeBtn = shadow.getElementById('cp-nav-home');
  const navMessagesBtn = shadow.getElementById('cp-nav-messages');
  const navHelpBtn = shadow.getElementById('cp-nav-help');
  const navNewsBtn = shadow.getElementById('cp-nav-news');
  const navMessagesBadge = shadow.getElementById('cp-nav-messages-badge');

  function updateNavMessagesBadge() {
    if (!navMessagesBadge) return;
    try {
      const history = JSON.parse(sessionStorage.getItem(STORAGE_KEYS.MESSAGES) || '[]');
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

  if (navHomeBtn) navHomeBtn.addEventListener('click', () => navigateTo('home'));
  if (navMessagesBtn) navMessagesBtn.addEventListener('click', () => navigateTo('chat'));
  if (navHelpBtn) navHelpBtn.addEventListener('click', () => navigateTo('human-team'));
  if (navNewsBtn) navNewsBtn.addEventListener('click', () => navigateTo('news'));

  let widgetConfig = {
    brand_name: 'Cai',
    assistant_name: 'Cai',
    greeting_heading: 'Hi there 👋\n\nYou are now speaking with Cai. How can I help?',
    greeting_subheading: 'The team can also help',
    whatsapp_enabled: true,
    whatsapp_number: '+91 98765 43210',
    theme_mode: 'dark',
    logo_url: '',
    logo_dark_url: '',
    logo_light_url: ''
  };

  let isSending = false;

  // 6.4. Theme Engine (Dynamic Light & Dark Modes)
  let currentTheme = (currentScript && currentScript.getAttribute('data-theme')) || 'dark';

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
    const isLight = (currentTheme === 'light');

    if (chatWindow) {
      chatWindow.classList.toggle('cp-light-theme', isLight);
      chatWindow.classList.toggle('theme-light', isLight);
    }
    if (teaserBubble) {
      teaserBubble.classList.toggle('cp-light-theme', isLight);
      teaserBubble.classList.toggle('theme-light', isLight);
    }
    if (launcherBtn) {
      launcherBtn.classList.toggle('cp-light-theme', isLight);
      launcherBtn.classList.toggle('theme-light', isLight);
    }

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
    try { sessionStorage.setItem('cp_theme_user_manual', '1'); } catch(e) {}
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
      sessionStorage.setItem('cp_teaser_dismissed', '1');
    }
  }

  function initTeaserNotification() {
    // If chat is open or teaser was already dismissed in this session, skip
    if (chatWindow.classList.contains('open')) return;
    if (sessionStorage.getItem('cp_teaser_dismissed') === '1') return;
    if (sessionStorage.getItem(STORAGE_KEYS.STATE) === '1') return;

    // Display teaser bubble after smooth 1.8s delay
    setTimeout(() => {
      if (chatWindow.classList.contains('open')) return;
      if (sessionStorage.getItem('cp_teaser_dismissed') === '1') return;
      if (sessionStorage.getItem(STORAGE_KEYS.STATE) === '1') return;

      if (teaserBubble) {
        teaserBubble.classList.add('show');
      }
      if (launcherBadge) {
        launcherBadge.style.display = 'flex';
      }
      playNotificationChime();

      // Ensure audio plays upon very first user touch or scroll if browser suspended audio
      const resumeChime = () => {
        if (!hasChimed && teaserBubble && teaserBubble.classList.contains('show')) {
          playNotificationChime();
        }
        window.removeEventListener('click', resumeChime);
        window.removeEventListener('touchstart', resumeChime);
        window.removeEventListener('scroll', resumeChime);
      };
      window.addEventListener('click', resumeChime, { once: true });
      window.addEventListener('touchstart', resumeChime, { once: true });
      window.addEventListener('scroll', resumeChime, { once: true });
    }, 1800);
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
      if (!isMobile) {
        setTimeout(() => inputField.focus(), 150);
      }
      scrollToBottom();

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
    sessionStorage.setItem(STORAGE_KEYS.STATE, isOpen ? '1' : '0');
  }

  launcherBtn.addEventListener('click', () => toggleWidget());
  backBtn.addEventListener('click', (e) => { e.stopPropagation(); handleBackNavigation(); });
  closeBtn.addEventListener('click', () => toggleWidget(false));

  if (teaserBubble) {
    teaserBubble.addEventListener('click', (e) => {
      if (e.target.closest('#cp-teaser-close')) return;
      dismissTeaser(false);
      toggleWidget(true);
    });
  }

  if (teaserCloseBtn) {
    teaserCloseBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      dismissTeaser(true);
    });
  }

  optionsBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    optionsMenu.classList.toggle('show');
  });

  document.addEventListener('click', () => {
    optionsMenu.classList.remove('show');
  });

  if (menuSound) {
    menuSound.addEventListener('click', (e) => {
      e.stopPropagation();
      toggleSound();
      optionsMenu.classList.remove('show');
    });
  }

  if (menuTheme) {
    menuTheme.addEventListener('click', (e) => {
      e.stopPropagation();
      toggleTheme();
      optionsMenu.classList.remove('show');
    });
  }

  function resetConversationState() {
    chatStream.innerHTML = '';
    sessionStorage.removeItem(STORAGE_KEYS.MESSAGES);
    sessionStorage.removeItem(STORAGE_KEYS.CONVO_ID);
    sessionStorage.removeItem(STORAGE_KEYS.LEAD_ID);
    sessionStorage.removeItem(STORAGE_KEYS.CUSTOMER_ID);
    sessionStorage.removeItem(STORAGE_KEYS.VISITOR_NAME);
    sessionStorage.removeItem(STORAGE_KEYS.IS_IDENTIFIED);
    sessionStorage.removeItem(STORAGE_KEYS.LEAD_STEP);
    conversationId = null;
    leadId = null;
    customerId = null;
    visitorName = '';
    isIdentified = false;
    leadStep = 1;
    initVisitorState();
    if (inputField) {
      inputField.placeholder = "Ask a question...";
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
      renderChatConcludedCard();
      scrollToBottom();
    });
  }

  menuWhatsapp.addEventListener('click', () => {
    optionsMenu.classList.remove('show');
    openWhatsAppChannel('Hello, I was speaking with Cai on your website and would like assistance.');
  });

  // 8. Auto-Expand Input Field & Button State
  inputField.addEventListener('input', () => {
    inputField.style.height = 'auto';
    inputField.style.height = Math.min(inputField.scrollHeight, 100) + 'px';
    const hasText = inputField.value.trim().length > 0;
    if (hasText) {
      sendBtn.classList.add('active');
    } else {
      sendBtn.classList.remove('active');
    }
  });

  inputField.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSend();
    }
  });

  sendBtn.addEventListener('click', handleSend);

  // Composer tools
  shadow.getElementById('cp-tool-emoji').addEventListener('click', () => {
    inputField.value += ' 😊 ';
    inputField.dispatchEvent(new Event('input'));
    inputField.focus();
  });

  shadow.getElementById('cp-tool-attach').addEventListener('click', () => {
    alert('File attachment is enabled for verified company inquiries. You can also paste document links directly.');
  });

  shadow.getElementById('cp-tool-gif').addEventListener('click', () => {
    inputField.value += ' [GIF] ';
    inputField.dispatchEvent(new Event('input'));
    inputField.focus();
  });

  shadow.getElementById('cp-tool-mic').addEventListener('click', () => {
    if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
      const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
      const recognition = new SpeechRecognition();
      recognition.lang = 'en-US';
      recognition.start();
      inputField.placeholder = 'Listening... Speak now';
      recognition.onresult = function(event) {
        inputField.value = event.results[0][0].transcript;
        inputField.placeholder = 'Ask a question...';
        inputField.dispatchEvent(new Event('input'));
      };
      recognition.onerror = function() {
        inputField.placeholder = 'Ask a question...';
      };
    } else {
      alert('Speech recognition is not supported in this browser version.');
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
    if (cfg.bank_name) widgetConfig.bank_name = cfg.bank_name;
    if (cfg.bank_account_no) widgetConfig.bank_account_no = cfg.bank_account_no;
    if (cfg.bank_ifsc) widgetConfig.bank_ifsc = cfg.bank_ifsc;
    if (cfg.bank_upi_id) widgetConfig.bank_upi_id = cfg.bank_upi_id;
    if (cfg.bank_account_holder) widgetConfig.bank_account_holder = cfg.bank_account_holder;
    if (cfg.bank_qr_url) widgetConfig.bank_qr_url = cfg.bank_qr_url;

    const asstName = widgetConfig.assistant_name || 'Cai';
    if (assistantNameEl) assistantNameEl.textContent = asstName;
    if (teaserAuthor) teaserAuthor.textContent = asstName;
    if (teaserMsg) teaserMsg.textContent = `You are now speaking with ${asstName}. How can I help?`;
    const welcomeAuthor = shadow.getElementById('cp-welcome-author');
    if (welcomeAuthor) welcomeAuthor.textContent = asstName;

    if (subtitleEl) {
      let sub = widgetConfig.greeting_subheading || '';
      if (sub === 'The team can also help' || sub === 'Cai AI & Team') sub = '';
      subtitleEl.textContent = sub;
    }

    if (welcomeTextEl && widgetConfig.greeting_heading) {
      welcomeTextEl.innerHTML = formatMarkdown(widgetConfig.greeting_heading);
    }

    if (widgetConfig.accent_color && hostElement) {
      hostElement.style.setProperty('--cp-accent-custom', widgetConfig.accent_color);
    }

    if (widgetConfig.theme_mode) {
      applyTheme(widgetConfig.theme_mode);
    } else {
      updateWidgetLogo();
    }
  }

  async function loadConfig() {
    try {
      const url = `${baseUrl}/api/config.php?company_key=${encodeURIComponent(companyKey)}`;
      const res = await fetch(url);
      if (!res.ok) throw new Error('Config failed');
      const data = await res.json();
      if (data && data.success && data.widget) {
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

  function appendUserMessage(text) {
    const row = document.createElement('div');
    row.className = 'cp-msg-row user';
    row.innerHTML = `
      <div class="cp-bubble">${escapeHtml(text)}</div>
      <div class="cp-meta-line">
        <span>You</span>
        <span>•</span>
        <span>Just now</span>
      </div>
    `;
    chatStream.appendChild(row);
    scrollToBottom();
    saveHistory('user', text);
    playSentSound();
  }

  // Visitor Lead Capture State Initialization (Section 7)
  function initVisitorState() {
    if (!isIdentified) {
      if (leadStep === 1) {
        if (welcomeTextEl) {
          welcomeTextEl.innerHTML = "Hi 👋 Before we start, what's your name?";
        }
        if (inputField) inputField.placeholder = "Enter your name...";
      } else if (leadStep === 2) {
        if (welcomeTextEl) {
          welcomeTextEl.innerHTML = `Hi ${escapeHtml(visitorName || 'there')}! How can we reach you if needed? Please share your Phone Number or Email Address:`;
        }
        if (inputField) inputField.placeholder = "Phone number or email...";
      }
    } else {
      if (welcomeTextEl) {
        const brand = widgetConfig.brand_name || 'CuboidPilot';
        welcomeTextEl.innerHTML = `Hi ${escapeHtml(visitorName || 'there')} 👋 Welcome back to ${escapeHtml(brand)}. How can I help you today?`;
      }
      if (inputField) inputField.placeholder = "Ask a question...";
    }
  }

  function renderChatConcludedCard(whatsappUrl) {
    const existing = chatStream.querySelector('.cp-chat-ended-container');
    if (existing) {
      existing.remove();
    }

    const cleanWaNum = (widgetConfig.whatsapp_number || '919876543210').replace(/[^0-9]/g, '');
    const waLink = whatsappUrl || `https://wa.me/${cleanWaNum}?text=${encodeURIComponent('Hi, I was chatting with Cai on your website and would like further assistance.')}`;

    const endedDiv = document.createElement('div');
    endedDiv.className = 'cp-chat-ended-container';
    endedDiv.innerHTML = `
      <div class="cp-chat-ended-divider">
        <span>Conversation concluded</span>
      </div>
      <div class="cp-chat-ended-card">
        <div class="cp-ended-title">Need further assistance or prefer speaking directly with our team?</div>
        <div class="cp-ended-actions">
          <a class="cp-wa-btn" href="${escapeHtml(waLink)}" target="_blank" rel="noopener noreferrer">
            <svg viewBox="0 0 24 24"><path d="M20.52 3.48A11.9 11.9 0 0 0 12.04 0C5.46 0 .1 5.36.1 11.94c0 2.1.55 4.15 1.6 5.96L0 24l6.27-1.64a11.9 11.9 0 0 0 5.77 1.48h.01c6.58 0 11.94-5.36 11.94-11.94 0-3.19-1.24-6.19-3.47-8.42z"/></svg>
            <span>Continue on WhatsApp</span>
          </a>
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
        if (inputField) {
          inputField.value = "I would like to speak with a human specialist";
          handleSend();
        }
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
    const row = document.createElement('div');
    row.className = 'cp-msg-row ai';

    const fullText = data.reply || data.text || '';
    const bubble = document.createElement('div');
    bubble.className = 'cp-bubble';
    row.appendChild(bubble);

    const metaLine = document.createElement('div');
    metaLine.className = 'cp-meta-line';
    metaLine.innerHTML = `
      <span>${escapeHtml(widgetConfig.assistant_name || 'Cai')}</span>
      <span>•</span>
      <span>AI Agent</span>
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

      row.appendChild(metaLine);

      // If chat has ended, display concluding wrap-up card at the end
      if (data.chat_ended) {
        renderChatConcludedCard(data.whatsapp_cta && data.whatsapp_cta.url ? data.whatsapp_cta.url : null);
      }

      scrollToBottom();
      saveHistory('ai', fullText, data.whatsapp_cta, data.chat_ended);
      if (!data.skipSound) {
        playReceivedSound();
      }
    }

    if (isLive && fullText.length > 0) {
      streamAIMessage(bubble, fullText, finishMessage);
    } else {
      bubble.innerHTML = formatMarkdown(fullText);
      finishMessage();
    }
  }

  function openWhatsAppChannel(customText) {
    const cleanNumber = (widgetConfig.whatsapp_number || '919876543210').replace(/[^0-9]/g, '');
    const url = `https://wa.me/${cleanNumber}?text=${encodeURIComponent(customText || 'Hi, I need assistance with Cai.')}`;
    window.open(url, '_blank');
  }

  // 11. Send Message to Backend (Sections 7, 8, 9 & 10)
  async function handleSend() {
    if (isSending) return;
    const text = inputField.value.trim();
    if (!text) return;

    inputField.value = '';
    inputField.style.height = 'auto';
    sendBtn.classList.remove('active');

    appendUserMessage(text);

    // LEAD CAPTURE STEP 1: Capture Name
    if (!isIdentified && leadStep === 1) {
      visitorName = text;
      sessionStorage.setItem(STORAGE_KEYS.VISITOR_NAME, visitorName);
      leadStep = 2;
      sessionStorage.setItem(STORAGE_KEYS.LEAD_STEP, '2');
      
      showTyping('Cai is processing...');
      setTimeout(() => {
        hideTyping();
        appendAIMessage({
          reply: `Nice to meet you, ${visitorName}! 👋 How can we reach you if needed? Please share your Phone Number or Email Address:`,
          timestamp: 'Just now'
        });
        inputField.placeholder = 'Phone number or email...';
        inputField.focus();
      }, 850);
      return;
    }

    // LEAD CAPTURE STEP 2: Capture Contact & Provision Visitor + Lead in DB
    if (!isIdentified && leadStep === 2) {
      isSending = true;
      showTyping('Saving details & connecting...');
      const startTime = Date.now();

      let isEmail = text.includes('@');
      let phone = isEmail ? '' : text;
      let email = isEmail ? text : '';

      try {
        const url = `${baseUrl}/api/visitor.php`;
        const res = await fetch(url, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Company-Key': companyKey
          },
          body: JSON.stringify({
            company_key: companyKey,
            name: visitorName,
            phone: phone,
            email: email,
            session_id: sessionId
          })
        });

        const data = await res.json();
        const elapsed = Date.now() - startTime;
        const wait = Math.max(0, 900 - elapsed);

        setTimeout(() => {
          hideTyping();
          isSending = false;

          if (data.success) {
            isIdentified = true;
            leadStep = 0;
            conversationId = data.conversation_id;
            leadId = data.lead_id;
            customerId = data.customer_id;
            sessionStorage.setItem(STORAGE_KEYS.IS_IDENTIFIED, '1');
            sessionStorage.setItem(STORAGE_KEYS.LEAD_STEP, '0');
            sessionStorage.setItem(STORAGE_KEYS.CONVO_ID, conversationId);
            sessionStorage.setItem(STORAGE_KEYS.LEAD_ID, leadId);
            sessionStorage.setItem(STORAGE_KEYS.CUSTOMER_ID, customerId);

            appendAIMessage({
              reply: data.reply || `Thanks ${visitorName}! You're all set. What can I help you with today?`,
              timestamp: 'Just now'
            });
            inputField.placeholder = 'Ask a question...';
            inputField.focus();
          } else {
            let errorMsg = data.error || 'Please provide a valid phone number or email address so our team can assist you.';
            if (
              errorMsg.toLowerCase().includes('workspace') || 
              errorMsg.includes('SQLSTATE') || 
              errorMsg.includes('Duplicate') || 
              errorMsg.includes('Integrity') || 
              errorMsg.includes('constraint') || 
              errorMsg.toLowerCase().includes('error:')
            ) {
              errorMsg = 'Please provide a valid phone number or email address so our team can assist you.';
            }
            appendAIMessage({
              reply: errorMsg,
              timestamp: 'Just now'
            });
            inputField.placeholder = 'Phone number or email...';
            inputField.focus();
          }
        }, wait);
      } catch (err) {
        hideTyping();
        isSending = false;
        appendAIMessage({
          reply: 'There was a connection issue saving your details. Please try once more.',
          timestamp: 'Just now'
        });
      }
      return;
    }

    // STANDARD AI CONVERSATION (Identified)
    isSending = true;
    showTyping('Cai is thinking...');
    const startTime = Date.now();

    try {
      const url = `${baseUrl}/api/chat.php`;
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Company-Key': companyKey
        },
        body: JSON.stringify({
          company_key: companyKey,
          message: text,
          session_id: sessionId,
          conversation_id: conversationId,
          lead_id: leadId,
          visitor_name: visitorName
        })
      });

      if (!res.ok) throw new Error('Network error ' + res.status);
      const data = await res.json();

      if (data.conversation_id) {
        conversationId = data.conversation_id;
        sessionStorage.setItem(STORAGE_KEYS.CONVO_ID, conversationId);
      }
      if (data.lead_id) {
        leadId = data.lead_id;
        sessionStorage.setItem(STORAGE_KEYS.LEAD_ID, leadId);
      }

      // Realistic thinking pacing: 850ms - 1150ms natural pause
      const elapsed = Date.now() - startTime;
      const minThinkingTime = Math.min(1200, Math.max(850, 800 + Math.floor(Math.random() * 300)));
      const remainingWait = Math.max(0, minThinkingTime - elapsed);

      setTimeout(() => {
        hideTyping();
        if (data.success) {
          appendAIMessage(data);
        } else {
          let chatError = data.error || "I apologize, but I could not reach the server right now. Please try again in a moment.";
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
      console.error('[CuboidPilot Widget Error]', err);
      setTimeout(() => {
        hideTyping();
        appendAIMessage({
          reply: "I am having trouble connecting to the server. Please ensure your network is connected.",
          timestamp: "Just now"
        });
        isSending = false;
      }, 500);
    }
  }

  // 12. Local Storage Persistence
  function saveHistory(sender, text, whatsappCta, chatEnded) {
    try {
      const history = JSON.parse(sessionStorage.getItem(STORAGE_KEYS.MESSAGES) || '[]');
      history.push({ sender, text, whatsappCta, chatEnded, timestamp: 'Just now' });
      sessionStorage.setItem(STORAGE_KEYS.MESSAGES, JSON.stringify(history.slice(-30)));
    } catch (e) {}
  }

  function restoreHistory() {
    try {
      const history = JSON.parse(sessionStorage.getItem(STORAGE_KEYS.MESSAGES) || '[]');
      if (Array.isArray(history) && history.length > 0) {
        history.forEach(item => {
          if (item.sender === 'user') {
            const row = document.createElement('div');
            row.className = 'cp-msg-row user';
            row.innerHTML = `
              <div class="cp-bubble">${escapeHtml(item.text)}</div>
              <div class="cp-meta-line"><span>You</span><span>•</span><span>Recent</span></div>
            `;
            chatStream.appendChild(row);
          } else {
            appendAIMessage({
              reply: item.text,
              timestamp: 'Recent',
              skipSound: true,
              whatsapp_cta: item.whatsappCta,
              chat_ended: item.chatEnded
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

  function formatMarkdown(text) {
    if (!text) return '';
    let esc = escapeHtml(text);
    esc = esc.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    esc = esc.replace(/\*(.*?)\*/g, '<em>$1</em>');
    esc = esc.replace(/(?:^|\n)[•\-\*]\s+(.*)/g, '<br/>• $1');
    esc = esc.replace(/(?:^|\n)(\d+)\.\s+(.*)/g, '<br/><strong>$1.</strong> $2');
    esc = esc.replace(/\n/g, '<br/>');
    esc = esc.replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer" style="color:#60a5fa; text-decoration:underline;">$1</a>');
    return esc;
  }


  // =========================================================================
  // w-up: SCREEN NAVIGATION ENGINE & RENDERERS
  // =========================================================================

  function navigateTo(screen, data = {}) {
    currentScreen = screen;
    if (screenStack[screenStack.length - 1] !== screen) {
      screenStack.push(screen);
    }

    if (screen !== 'human-chat' && humanPollingInterval) {
      clearInterval(humanPollingInterval);
      humanPollingInterval = null;
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
      bottomNav.style.display = isChatFlow ? 'none' : 'flex';
      bottomNav.style.visibility = isChatFlow ? 'hidden' : 'visible';
    }

    // Update Bottom Navigation active tab
    const navItems = shadow.querySelectorAll('.cp-nav-item');
    navItems.forEach(item => {
      const target = item.getAttribute('data-nav');
      if (
        (screen === 'home' && target === 'home') ||
        ((screen === 'chat' || screen === 'human-chat') && target === 'chat') ||
        ((screen === 'human-team' || screen === 'book-team') && target === 'help') ||
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
      if (bottomNav) {
        bottomNav.style.display = 'none';
        bottomNav.style.visibility = 'hidden';
      }
      if (backBtn) {
        backBtn.style.display = 'inline-flex';
        backBtn.style.visibility = 'visible';
      }

      const asstName = widgetConfig.assistant_name || 'Cai';
      if (assistantNameEl) assistantNameEl.textContent = asstName;
      if (subtitleEl) {
        let sub = widgetConfig.greeting_subheading || '';
        if (sub === 'The team can also help' || sub === 'Cai AI & Team') sub = '';
        subtitleEl.textContent = sub;
      }
      if (brandLogo) updateWidgetLogo();
      if (inputField) inputField.placeholder = "Ask a question...";
      scrollToBottom();
      return;
    }

    if (screen === 'human-chat') {
      if (screensView) screensView.style.display = 'none';
      if (messagesContainer) messagesContainer.style.display = 'flex';
      if (composerSection) composerSection.style.display = 'block';
      if (bottomNav) {
        bottomNav.style.display = 'none';
        bottomNav.style.visibility = 'hidden';
      }
      if (backBtn) {
        backBtn.style.display = 'inline-flex';
        backBtn.style.visibility = 'visible';
      }

      const agent = data.agent || selectedAgent || { name: 'Advisor', job_title: 'Consultant' };
      if (assistantNameEl) assistantNameEl.textContent = agent.name;
      if (subtitleEl) subtitleEl.textContent = '● Online';
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
      renderPayOnline();
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
      navigateTo('human-team');
      return;
    } else if (screen === 'news') {
      if (composerSection) composerSection.style.display = 'none';
      if (assistantNameEl) assistantNameEl.textContent = 'News & Updates';
      if (subtitleEl) subtitleEl.textContent = 'Latest announcements';
      renderNews();
    }
  }

  function handleBackNavigation() {
    if (currentScreen === 'chat' || currentScreen === 'human-chat' || currentScreen === 'human-team' || currentScreen === 'news') {
      navigateTo('home');
    } else if (currentScreen === 'home') {
      return;
    } else {
      if (screenStack.length > 1) {
        screenStack.pop();
        const prev = screenStack.pop();
        navigateTo(prev || 'home');
      } else {
        navigateTo('home');
      }
    }
  }

  // -------------------------------------------------------------
  // SCREEN 2: ACTION HOME (Exact match: media_1790857558494.png)
  // -------------------------------------------------------------
  function renderActionHome() {
    if (!screensView) return;

    let lastSnippet = 'How can I help you?';
    try {
      const history = JSON.parse(sessionStorage.getItem(STORAGE_KEYS.MESSAGES) || '[]');
      if (history.length > 0) {
        const last = history[history.length - 1];
        lastSnippet = last.text.replace(/<[^>]+>/g, '').replace(/[*_#`~]/g, '').substring(0, 48) + '...';
      }
    } catch(e) {}

    const logoUrl = getActiveLogoUrl();
    const fallbackLogo = (currentTheme === 'light') ? `${baseUrl}/assets/logo-black.png` : `${baseUrl}/assets/logo-white.png`;

    const isPlatformDemo = (!companyKey || companyKey === 'cp_live_cuboidsoft' || companyKey === 'cp_live_cuboidpilot' || companyKey === 'cuboidsoft' || companyKey === 'cuboidpilot');
    const hasPremium = isPlatformDemo || Boolean(widgetConfig && widgetConfig.company && widgetConfig.company.is_premium);
    const proBadge = !hasPremium ? '<span class="cp-pro-badge">PRO</span>' : '';

    screensView.innerHTML = `
      <!-- Moody Hero Greeting (Classic Intercom Aesthetic) -->
      <div class="cp-hero-section">
        <div class="cp-hero-greeting-sub">Hello there.</div>
        <div class="cp-hero-greeting-main">How can we help?</div>
      </div>

      <!-- 1. Ask anything Card (media_1790884814897.png) -->
      <div class="cp-ask-action-card" id="cp-card-ask" role="button" tabindex="0" title="Ask anything">
        <div class="cp-ask-card-content">
          <div class="cp-ask-card-title">Ask anything</div>
          <div class="cp-ask-card-desc">Search answers or chat with Cai AI</div>
        </div>
        <div class="cp-ask-card-arrow">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
            <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"></path>
          </svg>
        </div>
      </div>

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
            <div class="cp-action-card-title">Book an appointment ${proBadge}</div>
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
            <div class="cp-action-card-title">Instant human help ${proBadge}</div>
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
            <div class="cp-action-card-title">Make a payment ${proBadge}</div>
            <div class="cp-action-card-desc">Pay online, bank transfer, or invoice</div>
          </div>
        </div>
        <div class="cp-action-card-right">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="9 18 15 12 9 6"></polyline>
          </svg>
        </div>
      </div>

      <!-- 5. Featured Story: Meet Cai India Launch Card (media_1790918693375.png) -->
      <div class="cp-featured-banner-card" id="cp-card-latest-blog" role="button" tabindex="0" title="Read Story: Meet Cai">
        <div class="cp-banner-img-box">
          <img src="${baseUrl}/assets/blog/meet-cai-founder.png" id="cp-home-blog-img" class="cp-banner-cover-photo" alt="Meet Cai AI" onerror="this.onerror=null; this.src='assets/blog/meet-cai-founder.png';" />
          <div class="cp-banner-badge-tag" id="cp-home-blog-tag">INDIA LAUNCH</div>
        </div>
        <div class="cp-banner-body">
          <div class="cp-banner-headline" id="cp-home-blog-title">Meet Cai: India's 1st Autonomous AI Helpdesk &amp; SDR Agent</div>
          <div class="cp-banner-subline" id="cp-home-blog-desc">Autonomous lead capture, WhatsApp syncing &amp; 1-on-1 team bookings.</div>
          <div class="cp-banner-footer-row">
            <span class="cp-banner-meta-pill" id="cp-home-blog-date">Bengaluru, IN &bull; Available Now</span>
            <span class="cp-banner-arrow-link">Read story &rarr;</span>
          </div>
        </div>
      </div>
    `;

    // Fetch dynamic executive LinkedIn profiles for header avatars
    fetch(`${baseUrl}/api/widget_actions.php?action=get_actions&company_key=${encodeURIComponent(companyKey)}`)
      .then(r => r.json())
      .then(d => {
        if (d && d.success && d.settings) {
          const lAyush = shadow.getElementById('cp-avatar-ayush-link');
          const lCai = shadow.getElementById('cp-avatar-cai-link');
          const lCuboid = shadow.getElementById('cp-avatar-cuboidsoft-link');
          if (lAyush && d.settings.linkedin_ayush) lAyush.href = d.settings.linkedin_ayush;
          if (lCai && d.settings.linkedin_cai) lCai.href = d.settings.linkedin_cai;
          if (lCuboid && d.settings.linkedin_cuboidsoft) lCuboid.href = d.settings.linkedin_cuboidsoft;
        }
      })
      .catch(() => {});

    // India Launch Card click listener
    const blogCard = shadow.getElementById('cp-card-latest-blog');
    if (blogCard) {
      blogCard.addEventListener('click', () => {
        window.open(`${baseUrl}/blog.html?slug=meet-cai-autonomous-ai-agent`, '_blank');
      });
    }


    const askCard = shadow.getElementById('cp-card-ask');
    if (askCard) {
      askCard.addEventListener('click', () => {
        navigateTo('chat');
        setTimeout(() => {
          if (inputField) inputField.focus();
        }, 80);
      });
    }
    
    shadow.getElementById('cp-card-book').addEventListener('click', () => {
      if (!hasPremium) {
        renderLockedScreen('1-on-1 Appointment Booking');
        return;
      }
      navigateTo('book-team');
    });

    shadow.getElementById('cp-card-human').addEventListener('click', () => {
      if (!hasPremium) {
        renderLockedScreen('Instant Human Escalation');
        return;
      }
      navigateTo('human-team');
    });

    shadow.getElementById('cp-card-payment').addEventListener('click', () => {
      if (!hasPremium) {
        renderLockedScreen('In-Widget Direct Payments');
        return;
      }
      navigateTo('payment-options');
    });

    const askPill = shadow.getElementById('cp-ask-question-pill');
    if (askPill) {
      askPill.addEventListener('click', () => {
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
        <div class="cp-news-loading" style="padding: 24px; text-align: center; color: var(--cp-text-muted); font-size: 12px;">Loading latest updates...</div>
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
              ${imgSrc ? `<img src="${imgSrc}" class="cp-news-img" alt="${escapeHtml(b.title)}" onerror="this.style.display='none'">` : ''}
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
        <div style="font-size:12px;color:var(--cp-text-muted);text-align:center;padding:24px 0;">Loading team members...</div>
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
  // SCREEN 4: HUMAN CHAT INITIALIZATION & REAL-TIME POLLING
  // -------------------------------------------------------------
  async function startHumanChatWithAgent(agent) {
    selectedAgent = agent;
    isHumanChatActive = true;

    try {
      const res = await fetch(`${baseUrl}/api/widget_actions.php?action=start_human_chat`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Company-Key': companyKey },
        body: JSON.stringify({
          company_key: companyKey,
          user_id: agent.id,
          session_token: sessionId,
          conversation_id: conversationId,
          name: visitorName
        })
      });
      const data = await res.json();
      if (data.success) {
        if (data.conversation_id) {
          conversationId = data.conversation_id;
          sessionStorage.setItem(STORAGE_KEYS.CONVO_ID, conversationId);
        }

        if (data.notice) {
          appendAIMessage({
            reply: data.notice,
            timestamp: 'Just now'
          });
        }
      }
    } catch (e) {
      console.warn('Human chat init note:', e);
    }

    navigateTo('human-chat', { agent });
  }

  function startHumanPolling() {
    if (humanPollingInterval) clearInterval(humanPollingInterval);

    humanPollingInterval = setInterval(async () => {
      if (currentScreen !== 'human-chat' || !conversationId) return;

      try {
        const url = `${baseUrl}/api/widget_actions.php?action=poll_messages&conversation_id=${conversationId}&after_id=${lastPolledMessageId}&company_key=${encodeURIComponent(companyKey)}`;
        const res = await fetch(url);
        if (!res.ok) return;
        const data = await res.json();

        if (data.success && Array.isArray(data.messages)) {
          data.messages.forEach(msg => {
            if (msg.id > lastPolledMessageId) {
              lastPolledMessageId = msg.id;
              if (msg.sender === 'human_agent') {
                appendAIMessage({
                  reply: msg.text,
                  timestamp: msg.timestamp || 'Just now',
                  agent_name: (data.agent && data.agent.name) || selectedAgent?.name || 'Advisor'
                });
                playReceivedSound();
              }
            }
          });
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
        <div style="font-size:12px;color:var(--cp-text-muted);text-align:center;padding:24px 0;">Loading advisors...</div>
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
    const existingContact = (shadow.getElementById('cp-book-contact')?.value) || '';
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
        <label class="cp-form-label">Phone or Email *</label>
        <input type="text" class="cp-form-input" id="cp-book-contact" placeholder="+91 98765 43210 or email" value="${escapeHtml(existingContact)}" />
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
        const contactVal = shadow.getElementById('cp-book-contact').value.trim();
        const notesVal = shadow.getElementById('cp-book-notes').value.trim();

        if (!selectedSlot) {
          alert('Please select an available time slot.');
          return;
        }
        if (!nameVal || !contactVal) {
          alert('Please provide your name and phone/email.');
          return;
        }

        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Scheduling...';

        const isEmail = contactVal.includes('@');
        const phone = isEmail ? '' : contactVal;
        const email = isEmail ? contactVal : '';

        try {
          const res = await fetch(`${baseUrl}/api/widget_actions.php?action=book_appointment`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Company-Key': companyKey },
            body: JSON.stringify({
              company_key: companyKey,
              user_id: currentAgent.id,
              slot_datetime: selectedSlot,
              name: nameVal,
              phone: phone,
              email: email,
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
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                <circle cx="12" cy="7" r="4"></circle>
              </svg>
              <span>With</span>
            </div>
            <div class="cp-confirmed-row-val">
              <strong>${escapeHtml(agentName)}</strong>
              <div style="font-size:11.5px;color:var(--cp-text-secondary);margin-top:2px;">${escapeHtml(agentRole)}</div>
            </div>
          </div>
        </div>

        <a href="${icsUrl}" download="appointment.ics" class="cp-add-calendar-btn">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
            <line x1="16" y1="2" x2="16" y2="6"></line>
            <line x1="8" y1="2" x2="8" y2="6"></line>
            <line x1="3" y1="10" x2="21" y2="10"></line>
          </svg>
          Add to Calendar
        </a>

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
  function renderBankTransfer() {
    if (!screensView) return;

    const bankName = widgetConfig.bank_name || 'HDFC Bank';
    const accHolder = widgetConfig.bank_account_holder || '';
    const accNo = widgetConfig.bank_account_no || '50200088991122';
    const ifsc = widgetConfig.bank_ifsc || 'HDFC0001234';
    const upiId = widgetConfig.bank_upi_id || 'cuboidsoft@hdfcbank';
    const qrUrl = widgetConfig.bank_qr_url ? (widgetConfig.bank_qr_url.startsWith('http') ? widgetConfig.bank_qr_url : `${baseUrl}/${widgetConfig.bank_qr_url}`) : null;

    screensView.innerHTML = `
      <div class="cp-section-title">Verified Bank Details</div>
      <div class="cp-bank-card">
        ${accHolder ? `
        <div class="cp-bank-row">
          <span class="cp-bank-label">A/C Holder</span>
          <span class="cp-bank-val">${escapeHtml(accHolder)}</span>
        </div>` : ''}
        <div class="cp-bank-row">
          <span class="cp-bank-label">Bank Name</span>
          <span class="cp-bank-val">${escapeHtml(bankName)}</span>
        </div>
        <div class="cp-bank-row">
          <span class="cp-bank-label">Account No.</span>
          <div class="cp-bank-val-box">
            <span class="cp-bank-val">${escapeHtml(accNo)}</span>
            <button class="cp-copy-btn" data-val="${escapeHtml(accNo)}" title="Copy">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
            </button>
          </div>
        </div>
        <div class="cp-bank-row">
          <span class="cp-bank-label">IFSC Code</span>
          <div class="cp-bank-val-box">
            <span class="cp-bank-val">${escapeHtml(ifsc)}</span>
            <button class="cp-copy-btn" data-val="${escapeHtml(ifsc)}" title="Copy">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
            </button>
          </div>
        </div>
        <div class="cp-bank-row">
          <span class="cp-bank-label">UPI ID</span>
          <div class="cp-bank-val-box">
            <span class="cp-bank-val">${escapeHtml(upiId)}</span>
            <button class="cp-copy-btn" data-val="${escapeHtml(upiId)}" title="Copy">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
            </button>
          </div>
        </div>
        ${qrUrl ? `
        <div style="margin-top:10px;text-align:center;padding:10px;background:rgba(255,255,255,0.03);border-radius:8px;border:1px dashed var(--cp-border-input, #282931);">
          <div style="font-size:11px;color:var(--cp-text-muted);margin-bottom:6px;">Scan to pay via any UPI App</div>
          <img src="${qrUrl}" alt="UPI QR Code" style="width:120px;height:120px;margin:0 auto;border-radius:6px;background:#ffffff;padding:4px;display:block;" />
        </div>` : ''}
      </div>

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
  // SUB-SCREEN 8B: PAY ONLINE (RAZORPAY GATEWAY)
  // -------------------------------------------------------------
  function renderPayOnline() {
    if (!screensView) return;

    screensView.innerHTML = `
      <div class="cp-sub-screen-header">
        <div class="cp-sub-screen-title">Pay Online (Razorpay)</div>
        <div class="cp-sub-screen-desc">Cards, NetBanking, UPI & Wallets</div>
      </div>

      <div class="cp-form-group">
        <label class="cp-form-label">Payment Amount (₹) *</label>
        <div class="cp-amount-chips">
          <span class="cp-amount-chip" data-amt="2500">₹2,500</span>
          <span class="cp-amount-chip active" data-amt="10000">₹10,000</span>
          <span class="cp-amount-chip" data-amt="25000">₹25,000</span>
        </div>
        <input type="number" class="cp-form-input" id="cp-online-amount" placeholder="Amount in ₹" value="10000" />
      </div>

      <div class="cp-form-group">
        <label class="cp-form-label">Payer Name *</label>
        <input type="text" class="cp-form-input" id="cp-online-name" placeholder="Full name" value="${escapeHtml(visitorName)}" />
      </div>

      <div class="cp-form-group">
        <label class="cp-form-label">Phone or Email *</label>
        <input type="text" class="cp-form-input" id="cp-online-contact" placeholder="+91 98765 43210 or email" />
      </div>

      <div class="cp-form-group">
        <label class="cp-form-label">Description / Purpose (Optional)</label>
        <input type="text" class="cp-form-input" id="cp-online-desc" placeholder="e.g. Consultation booking or advance" />
      </div>

      <div class="cp-sticky-booking-footer">
        <button class="cp-submit-btn" id="cp-btn-initiate-online" style="width:100%;margin:0;">
          Proceed to Pay Securely
        </button>
      </div>

      <div id="cp-online-status-box" style="display:none;margin-top:10px;"></div>
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
      initBtn.addEventListener('click', () => {
        const amt = parseInt(shadow.getElementById('cp-online-amount').value, 10);
        const name = shadow.getElementById('cp-online-name').value.trim();
        const contact = shadow.getElementById('cp-online-contact').value.trim();
        if (!amt || amt <= 0 || !name || !contact) {
          alert('Please enter amount, name, and contact information.');
          return;
        }

        const rzpKey = widgetConfig.razorpay_key_id;
        if (rzpKey && window.Razorpay) {
          const options = {
            key: rzpKey,
            amount: amt * 100,
            currency: 'INR',
            name: widgetConfig.brand_name || 'CuboidPilot',
            description: shadow.getElementById('cp-online-desc').value.trim() || 'Online Payment',
            prefill: {
              name: name,
              email: contact.includes('@') ? contact : '',
              contact: contact.includes('@') ? '' : contact
            },
            theme: { color: '#0f172a' },
            handler: function (response) {
              alert('Payment successful! Payment ID: ' + response.razorpay_payment_id);
              navigateTo('chat');
            }
          };
          const rzp = new window.Razorpay(options);
          rzp.open();
        } else {
          // Clean checkout response / simulation
          initBtn.disabled = true;
          initBtn.textContent = 'Redirecting to payment...';
          setTimeout(() => {
            const statusBox = shadow.getElementById('cp-online-status-box');
            statusBox.style.display = 'block';
            statusBox.innerHTML = `
              <div style="background:rgba(2,132,199,0.1);border:1px solid rgba(2,132,199,0.3);border-radius:10px;padding:14px;text-align:center;">
                <div style="font-weight:700;color:#0284c7;font-size:14px;">Checkout Session Created</div>
                <div style="font-size:12px;color:var(--cp-text-secondary);margin-top:4px;">
                  Amount: <strong>₹${amt.toLocaleString('en-IN')}</strong><br/>
                  ${rzpKey ? 'Opening Razorpay checkout...' : 'Razorpay Gateway is in Test/Verification mode.'}
                </div>
                <button class="cp-btn-sm" id="cp-online-done-btn" style="margin-top:10px;width:100%;justify-content:center;">Back to menu</button>
              </div>
            `;
            shadow.getElementById('cp-online-done-btn').addEventListener('click', () => navigateTo('payment-options'));
          }, 800);
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
  loadConfig();
  initVisitorState();
  restoreHistory();
  updateSoundUi();
  navigateTo('home');

  if (isEmbedded) {
    toggleWidget(true);
  } else if (sessionStorage.getItem(STORAGE_KEYS.STATE) === '1') {
    toggleWidget(true);
  } else {
    initTeaserNotification();
  }

  window.CuboidPilot = {
    open: () => toggleWidget(true),
    close: () => toggleWidget(false),
    toggle: () => toggleWidget(),
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
