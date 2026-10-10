/**
 * CUBOIDPILOT — SHARED APPLICATION COMPONENTS & SHELL RENDERER
 * Keeps all dashboard and admin pages 100% consistent, dry, and lightweight.
 */

window.CuboidShell = {
  user: null,
  company: null,
  entitlements: null,

  escapeHtml: function(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  },

  resolveAssetUrl: function(url, fallback = '../assets/logo-white.png') {
    if (!url) return fallback;
    const trimmed = String(url).trim();
    if (!trimmed) return fallback;
    if (trimmed.startsWith('http://') || trimmed.startsWith('https://') || trimmed.startsWith('data:') || trimmed.startsWith('blob:')) {
      return trimmed;
    }
    let clean = trimmed.replace(/^\.?\/?/, '');
    if (clean.startsWith('cuboidpilot/')) {
      clean = clean.replace(/^cuboidpilot\//, '');
    }
    return '../' + clean;
  },

  hydrateFromCache: function() {
    try {
      const raw = sessionStorage.getItem('cp_workspace_cache');
      if (raw) {
        const cached = JSON.parse(raw);
        if (cached && cached.company) {
          this.user = cached.user;
          this.company = cached.company;
          this.entitlements = cached.entitlements;
          window.__CUBOID_USER__ = cached.user;
          window.__CUBOID_COMPANY__ = cached.company;
          window.__CUBOID_ENTITLEMENTS__ = cached.entitlements;
          return true;
        }
      }
    } catch(e) {}
    return false;
  },

  // Render Company App Shell (Sidebar + Topbar) with Real Session
  initCompanyApp: function(activePage, pageTitle, breadcrumbs = []) {
    const hasCache = this.hydrateFromCache();

    // Show Google Workspace minimalist splash loading screen ONLY on cold load if no cache
    if (!hasCache) {
      this.renderSplashScreen();
    }

    this.renderCompanySidebar(activePage);
    this.renderCompanyTopbar(pageTitle, breadcrumbs);
    this.ensureBottomWidget();
    this.bindGlobalEvents();
    this.initSpaRouter();

    if (hasCache) {
      this.syncRealWorkspaceDom(activePage, pageTitle, breadcrumbs);
      this.dismissSplashScreen(0);
    }
    if (window.lucide) window.lucide.createIcons();

    // Auto-dismiss the "Start Here: Connect & Deploy" tooltip after 10 seconds
    setTimeout(() => {
      const tt = document.getElementById('rail-connect-tooltip');
      if (tt) {
        tt.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        tt.style.opacity = '0';
        tt.style.transform = 'translateY(-50%) translateX(-6px)';
        tt.style.pointerEvents = 'none';
        setTimeout(() => { if (tt.parentNode) tt.remove(); }, 650);
      }
    }, 10000);

    // Immediately trigger page data loader in parallel without waiting for me.php
    if (window.CuboidDashboard && typeof window.CuboidDashboard.loadPageData === 'function') {
      window.CuboidDashboard.loadPageData(activePage);
    }

    // Authenticate and refresh workspace in background
    fetch('../api/me.php')
      .then(res => {
        if (res.status === 401) {
          sessionStorage.removeItem('cp_workspace_cache');
          window.location.href = '../login.php';
          return null;
        }
        return res.json();
      })
      .then(data => {
        if (data && data.success) {
          this.user = data.user;
          this.company = data.company;
          this.entitlements = data.entitlements;
          window.__CUBOID_USER__ = data.user;
          window.__CUBOID_COMPANY__ = data.company;
          window.__CUBOID_ENTITLEMENTS__ = data.entitlements;
          
          sessionStorage.setItem('cp_workspace_cache', JSON.stringify({
            user: data.user,
            company: data.company,
            entitlements: data.entitlements
          }));

          this.syncRealWorkspaceDom(activePage, pageTitle, breadcrumbs);
        }
        // Always dismiss splash screen once authentication/workspace verification completes
        this.dismissSplashScreen(300);
      })
      .catch(e => {
        console.warn('[CuboidShell] Auth sync notice:', e);
        this.dismissSplashScreen(200);
      });
  },

  // Sync real workspace data into sidebar, topbar, and drawer
  syncRealWorkspaceDom: function(activePage, pageTitle, breadcrumbs) {
    if (!this.company) return;

    // 1. Sidebar Company Name & Plan Status
    const companyNameEl = document.getElementById('shell-company-name');
    if (companyNameEl) companyNameEl.textContent = this.company.name;

    const companyStatusEl = document.getElementById('shell-company-status');
    if (companyStatusEl && this.entitlements) {
      if (this.entitlements.is_premium) {
        companyStatusEl.innerHTML = `<span class="inline-block w-1.5 h-1.5 rounded-full bg-emerald-500"></span><span class="text-[11px] text-stone-400">Active · ${this.entitlements.effective_tier.toUpperCase()}</span>`;
      } else {
        companyStatusEl.innerHTML = `<span class="inline-block w-1.5 h-1.5 rounded-full bg-amber-500"></span><span class="text-[11px] text-stone-400">Trial · ${this.entitlements.trial_days_remaining} days left</span>`;
      }
    }

    // 2. Footer User Profile
    const userNameEl = document.getElementById('shell-user-name');
    if (userNameEl && this.user) userNameEl.textContent = this.user.name;

    const userRoleEl = document.getElementById('shell-user-role');
    if (userRoleEl && this.user) {
      userRoleEl.textContent = this.user.role.charAt(0).toUpperCase() + this.user.role.slice(1);
    }

    const userAvatarEl = document.getElementById('shell-user-avatar');
    if (userAvatarEl && this.user) {
      if (this.user.avatar_url) {
        userAvatarEl.innerHTML = `<img src="${this.resolveAssetUrl(this.user.avatar_url)}" alt="${this.escapeHtml(this.user.name)}" class="w-full h-full object-cover rounded-full">`;
        userAvatarEl.classList.remove('bg-stone-900', 'text-white');
      } else {
        const parts = (this.user.name || '').split(' ');
        let inits = (parts[0] ? parts[0][0] : '') + (parts[1] ? parts[1][0] : '');
        userAvatarEl.textContent = inits.toUpperCase() || 'U';
        userAvatarEl.classList.add('bg-stone-900', 'text-white');
      }
    }

    const topbarAvatarEl = document.getElementById('topbar-user-avatar');
    if (topbarAvatarEl && this.user) {
      if (this.user.avatar_url) {
        topbarAvatarEl.innerHTML = `<img src="${this.resolveAssetUrl(this.user.avatar_url)}" alt="${this.escapeHtml(this.user.name)}" class="w-full h-full object-cover rounded-full">`;
        topbarAvatarEl.classList.remove('bg-stone-900', 'text-white');
      } else {
        const parts = (this.user.name || '').split(' ');
        let inits = (parts[0] ? parts[0][0] : '') + (parts[1] ? parts[1][0] : '');
        topbarAvatarEl.textContent = inits.toUpperCase() || 'U';
        topbarAvatarEl.classList.add('bg-stone-900', 'text-white');
      }
    }

    const topbarUserNameEl = document.getElementById('topbar-user-name');
    if (topbarUserNameEl && this.user) {
      topbarUserNameEl.textContent = this.user.name;
    }

    // 3. Topbar Breadcrumbs
    const breadcrumbRootEl = document.getElementById('shell-breadcrumb-company');
    if (breadcrumbRootEl) breadcrumbRootEl.textContent = this.company.name;

    // 4. Trial Duration Indicator & Expiry Banner in Topbar & Content Area
    const trialIndicator = document.getElementById('shell-trial-indicator');
    if (trialIndicator && this.entitlements) {
      if (this.entitlements.is_trial_expired) {
        trialIndicator.className = 'inline-flex items-center gap-1.5 px-2 py-0.5 sm:px-2.5 sm:py-1 bg-rose-50 text-rose-800 border border-rose-300 rounded-[4px] text-[11px] font-medium shrink-0';
        trialIndicator.innerHTML = `
          <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
          <span>Trial Expired</span>
          <a href="billing.html" class="ml-1 text-[10px] px-1.5 py-0.5 bg-rose-700 hover:bg-black text-white rounded-[3px] font-semibold no-underline transition-colors">Renew</a>
        `;
      } else if (this.entitlements.is_trial && !this.entitlements.is_premium) {
        const days = this.entitlements.trial_days_remaining;
        trialIndicator.className = 'inline-flex items-center gap-1.5 px-2 py-0.5 sm:px-2.5 sm:py-1 bg-amber-50 text-amber-900 border border-amber-300 rounded-[4px] text-[11px] font-medium shrink-0';
        trialIndicator.innerHTML = `
          <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
          <span>Trial: <strong>${days}d left</strong></span>
          <a href="billing.html" class="ml-1 text-[10px] px-1.5 py-0.5 bg-stone-900 hover:bg-black text-white rounded-[3px] font-medium no-underline transition-colors">Renew</a>
        `;
      } else {
        trialIndicator.className = 'hidden md:inline-flex items-center gap-1.5 px-2.5 py-1 bg-emerald-50 text-emerald-800 border border-emerald-200 rounded-[4px] text-[11px] font-medium shrink-0';
        trialIndicator.innerHTML = `
          <span class="w-1.5 h-1.5 rounded-full bg-emerald-600"></span>
          <span>${(this.entitlements.effective_tier || 'PRO').toUpperCase()}</span>
        `;
      }
    }

    // Dynamic Expiry Banner & Strict 14-Day Free Trial Workspace Lock
    if (this.entitlements) {
      const isExpired = Boolean(this.entitlements.is_trial_expired || this.entitlements.status === 'EXPIRED' || this.entitlements.status === 'SUSPENDED');
      const isBillingPage = window.location.pathname.includes('billing.html') || activePage === 'billing';
      const isSuperAdmin = window.location.pathname.includes('super-admin');

      // 1. Strict Full-Screen Workspace Lock (Active on all pages except Billing so user can renew)
      if (isExpired && !isBillingPage && !isSuperAdmin) {
        let lockOverlay = document.getElementById('shell-workspace-lock-overlay');
        if (!lockOverlay) {
          lockOverlay = document.createElement('div');
          lockOverlay.id = 'shell-workspace-lock-overlay';
          lockOverlay.className = 'fixed inset-0 z-[99999] bg-stone-950/85 backdrop-blur-md flex items-center justify-center p-4';
          lockOverlay.innerHTML = `
            <div class="bg-white border border-stone-200 rounded-2xl max-w-md w-full p-6 sm:p-8 shadow-2xl text-center space-y-5 animate-in fade-in zoom-in-95 duration-200">
              <div class="w-14 h-14 rounded-2xl bg-amber-50 border border-amber-200 text-amber-700 flex items-center justify-center mx-auto shadow-sm">
                <i data-lucide="lock" class="w-7 h-7"></i>
              </div>
              
              <div class="space-y-1.5">
                <div class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-rose-50 text-rose-700 border border-rose-200 rounded-full text-xs font-semibold">
                  <span class="w-2 h-2 rounded-full bg-rose-600 animate-pulse"></span>
                  <span>14-Day Free Trial Ended</span>
                </div>
                <h2 class="text-xl sm:text-2xl font-bold text-stone-900 tracking-tight">Workspace Locked</h2>
                <p class="text-xs sm:text-sm text-stone-500 leading-relaxed max-w-sm mx-auto">
                  Your 14-day evaluation period for <strong class="text-stone-800">${this.company ? this.escapeHtml(this.company.name) : 'your organization'}</strong> has concluded. All your leads, chat histories, and settings are preserved safely.
                </p>
              </div>

              <div class="p-3.5 bg-stone-50 rounded-xl border border-stone-200/80 text-left text-xs space-y-2">
                <div class="flex items-center justify-between text-stone-600">
                  <span class="font-medium">Trial Ended On:</span>
                  <span class="font-mono font-semibold text-stone-900">${this.entitlements.formatted_trial_end || '14 Days Ago'}</span>
                </div>
                <div class="flex items-center justify-between text-stone-600">
                  <span class="font-medium">CRM Leads & Data:</span>
                  <span class="font-mono text-emerald-700 font-semibold">100% Intact</span>
                </div>
                <div class="flex items-center justify-between text-stone-600">
                  <span class="font-medium">To Resume:</span>
                  <span class="text-amber-800 font-semibold">Activate Subscription</span>
                </div>
              </div>

              <div class="flex flex-col gap-2.5 pt-1">
                <a href="billing.html" class="w-full py-3 bg-stone-900 hover:bg-black text-white text-xs sm:text-sm font-semibold rounded-xl flex items-center justify-center gap-2 shadow-sm transition-all no-underline">
                  <i data-lucide="sparkles" class="w-4 h-4 text-amber-400"></i>
                  <span>Renew Subscription / Choose Plan</span>
                  <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </a>
                
                <div class="flex items-center justify-center gap-4 text-xs text-stone-400 pt-1">
                  <a href="https://api.whatsapp.com/send?phone=919876543210&text=Hi%20CuboidPilot%20Team,%20please%20help%20resume%20my%20workspace%20${encodeURIComponent(this.company ? this.company.company_key : '')}" target="_blank" class="text-stone-600 hover:text-black hover:underline flex items-center gap-1">
                    <i data-lucide="message-circle" class="w-3.5 h-3.5 text-emerald-600"></i>
                    <span>Contact Support</span>
                  </a>
                  <span>•</span>
                  <a href="../logout.php" class="text-stone-500 hover:text-rose-600 hover:underline">
                    Sign Out
                  </a>
                </div>
              </div>
            </div>
          `;
          document.body.appendChild(lockOverlay);
          if (window.lucide) lucide.createIcons();
        }
      } else {
        const lockOverlay = document.getElementById('shell-workspace-lock-overlay');
        if (lockOverlay) lockOverlay.remove();
      }

      // 2. In-Page Top Banner
      let banner = document.getElementById('shell-trial-expiry-banner');
      const contentScroll = document.querySelector('.app-content-scroll') || document.querySelector('.app-main');
      if (contentScroll) {
        if (isExpired) {
          if (!banner) {
            banner = document.createElement('div');
            banner.id = 'shell-trial-expiry-banner';
            contentScroll.prepend(banner);
          }
          banner.className = 'mb-4 p-3 bg-red-50 border border-red-300 rounded-[6px] text-red-900 text-xs flex flex-col sm:flex-row sm:items-center justify-between gap-2 shadow-xs';
          banner.innerHTML = `
            <div class="flex items-center gap-2">
              <i data-lucide="alert-octagon" class="w-4 h-4 text-red-600 shrink-0"></i>
              <span><strong>14-Day Free Trial Expired:</strong> Your workspace trial has ended. Renew your plan to restore full access to WhatsApp, team seats, and autonomous AI automation.</span>
            </div>
            <a href="billing.html" class="px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white text-xs font-semibold rounded-[4px] no-underline shrink-0 text-center">
              Renew Plan Now &rarr;
            </a>
          `;
          if (window.lucide) lucide.createIcons();
        } else if (this.entitlements.is_trial && this.entitlements.trial_days_remaining <= 3) {
          if (!banner) {
            banner = document.createElement('div');
            banner.id = 'shell-trial-expiry-banner';
            contentScroll.prepend(banner);
          }
          banner.className = 'mb-4 p-3 bg-amber-50 border border-amber-300 rounded-[6px] text-amber-900 text-xs flex flex-col sm:flex-row sm:items-center justify-between gap-2 shadow-xs';
          banner.innerHTML = `
            <div class="flex items-center gap-2">
              <i data-lucide="clock" class="w-4 h-4 text-amber-600 shrink-0"></i>
              <span><strong>Trial Ending Soon:</strong> You have <strong>${this.entitlements.trial_days_remaining} days remaining</strong> on your free trial (Ends ${this.entitlements.formatted_trial_end || 'soon'}). Setup renewal to keep all capabilities active.</span>
            </div>
            <a href="billing.html" class="px-3 py-1.5 bg-stone-900 hover:bg-black text-white text-xs font-semibold rounded-[4px] no-underline shrink-0 text-center">
              Renew / Upgrade &rarr;
            </a>
          `;
          if (window.lucide) lucide.createIcons();
        } else if (banner) {
          banner.remove();
        }
      }
    }

    // 4.5. Dynamic Plan Card on Sidebar (Intercom Minimalist Hairline Card)
    const planCardContainer = document.getElementById('shell-plan-card-container');
    if (planCardContainer && this.entitlements) {
      if (this.entitlements.is_premium) {
        planCardContainer.innerHTML = `
          <div class="p-2.5 bg-white border border-[#e7e5de] rounded-[6px] shadow-2xs space-y-2.5">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-1.5 min-w-0">
                <span class="w-1.5 h-1.5 rounded-full bg-stone-900"></span>
                <span class="text-xs font-semibold text-stone-900 truncate">Pro Workspace</span>
              </div>
              <span class="text-[10px] font-mono font-medium px-1.5 py-0.5 bg-stone-100 text-stone-600 border border-stone-200/80 rounded-[4px] shrink-0">ACTIVE</span>
            </div>
            <p class="text-[11px] text-stone-500 leading-snug m-0">
              Autonomous AI &amp; WhatsApp live
            </p>
            <a href="billing.html" class="flex items-center justify-center gap-1.5 w-full py-1.5 px-2 bg-stone-50 hover:bg-stone-100 text-stone-700 hover:text-stone-900 text-xs font-medium rounded-[4px] no-underline border border-[#e7e5de] hover:border-stone-400 transition-colors shadow-2xs">
              <span>Manage subscription</span>
              <i data-lucide="arrow-right" class="w-3 h-3 text-stone-400"></i>
            </a>
            <div class="flex items-center justify-between text-[11px] text-stone-500 pt-1.5 border-t border-[#f0ede6]">
              <span class="flex items-center gap-1.5 text-stone-600">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                <span>Pilot operational</span>
              </span>
              <span class="font-mono text-stone-400 text-[10px]">100%</span>
            </div>
          </div>
        `;
        if (window.lucide) window.lucide.createIcons();
      } else {
        const days = this.entitlements.trial_days_remaining || 7;
        planCardContainer.innerHTML = `
          <div class="p-2.5 bg-white border border-[#e7e5de] rounded-[6px] shadow-2xs space-y-2.5">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-1.5 min-w-0">
                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                <span class="text-xs font-semibold text-stone-900 truncate">${days} days left</span>
              </div>
              <span class="text-[10px] font-mono font-medium px-1.5 py-0.5 bg-amber-50 text-amber-800 border border-amber-200/70 rounded-[4px] shrink-0">TRIAL</span>
            </div>
            <p class="text-[11px] text-stone-500 leading-snug m-0">
              Advanced trial · unlimited AI
            </p>
            <a href="billing.html" class="flex items-center justify-center gap-1.5 w-full py-1.5 px-2 bg-stone-900 hover:bg-black text-white text-xs font-medium rounded-[4px] no-underline transition-colors shadow-2xs">
              <span>Upgrade Plan</span>
              <i data-lucide="arrow-right" class="w-3 h-3 text-stone-300"></i>
            </a>
            <div class="flex items-center justify-between text-[11px] text-stone-500 pt-1.5 border-t border-[#f0ede6]">
              <span class="flex items-center gap-1.5 text-stone-600">
                <i data-lucide="check" class="w-3 h-3 text-emerald-600 stroke-[2.5]"></i>
                <span>Get set up</span>
              </span>
              <i data-lucide="chevron-right" class="w-3 h-3 text-stone-400"></i>
            </div>
          </div>
        `;
        if (window.lucide) window.lucide.createIcons();
      }
    }

    // 5. Sidebar Rail Logo & Splash Screen Logo
    const railLogoEl = document.getElementById('shell-rail-logo');
    const splashLogoEl = document.getElementById('cp-splash-logo');
    const splashBrandEl = document.getElementById('cp-splash-brand-name');
    if (this.company) {
      const railLogo = this.company.logo_light_url || this.company.logo_url || this.company.logo_dark_url;
      if (railLogo) {
        const resolved = this.resolveAssetUrl(railLogo, '../assets/logo-black.png');
        if (railLogoEl) railLogoEl.src = resolved;
        if (splashLogoEl) splashLogoEl.src = resolved;
      }
      if (splashBrandEl && this.company.name) {
        splashBrandEl.textContent = this.company.name;
      }
    }

    // 6. Connect & Embed Code Snippet Sync (Section 6 & 11)
    const officialSnippetEl = document.getElementById('official-widget-snippet');
    const popoverCodeEl = document.getElementById('rail-popover-code');
    if (this.company && this.company.company_key) {
      const isLocal = (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1');
      const scriptUrl = isLocal 
        ? `${window.location.origin}/cuboidpilot/widget.js?v=7.5`
        : 'https://cai.cuboidsoft.in/widget.js?v=7.5';
      const theme = (this.company.theme_mode === 'light') ? 'light' : 'dark';
      const snippetCode = `<script src="${scriptUrl}" data-company="${this.company.company_key}" data-theme="${theme}" async><\/script>`;
      if (officialSnippetEl) {
        officialSnippetEl.textContent = snippetCode;
      }
      if (popoverCodeEl) {
        popoverCodeEl.textContent = snippetCode;
      }
    }
  },

  // Render Super Admin Shell (Sidebar + Topbar)
  initSuperAdmin: function(activePage, pageTitle, breadcrumbs = []) {
    this.renderSuperAdminSidebar(activePage);
    this.renderSuperAdminTopbar(pageTitle, breadcrumbs);
    this.bindGlobalEvents();
    if (window.lucide) window.lucide.createIcons();
  },

  // Company App Sidebar HTML (Dual-Sidebar: Slim Icon Rail + Secondary Contextual Pane)
  renderCompanySidebar: function(active) {
    const el = document.getElementById('sidebar-container');
    if (!el) return;

    const scriptUrl = 'https://cai.cuboidsoft.in/widget.js';
    const compKey = (this.company && this.company.company_key) ? this.company.company_key : 'cp_live_cuboidsoft';

    let subNavHtml = '';

    if (active === 'overview' || active === 'analytics') {
      // 1. Overview / Reports Contextual Pane (media_1790744358742.png)
      subNavHtml = `
        <div class="sub-sidebar-header flex items-center justify-between">
          <span>Reports</span>
          <div class="flex items-center gap-1">
            <button id="shell-add-report-btn" onclick="CuboidDashboard.showCreateReportModal()" class="p-1 hover:bg-stone-100 rounded text-stone-600 transition-colors" title="Add report"><i data-lucide="plus" class="w-4 h-4"></i></button>
            <button type="button" class="shell-mobile-close lg:hidden p-1 text-stone-400 hover:text-stone-900 rounded transition-colors" title="Close menu"><i data-lucide="x" class="w-4 h-4"></i></button>
          </div>
        </div>
        <div class="sub-sidebar-nav flex-1 overflow-y-auto">
          <a href="overview.html" class="sub-nav-item ${active === 'overview' ? 'active font-medium' : 'text-stone-600'}">
            <span class="flex items-center gap-2"><i data-lucide="layout-grid" class="w-3.5 h-3.5 text-stone-700"></i> Overview</span>
          </a>
          <a href="overview.html" onclick="CuboidShell.toast('Viewing all 21 system reports', 'info')" class="sub-nav-item text-stone-600">
            <span class="flex items-center gap-2"><i data-lucide="file-text" class="w-3.5 h-3.5 text-stone-400"></i> All reports</span>
            <span class="text-[11px] text-stone-400 font-mono">21</span>
          </a>
          <a href="overview.html" onclick="CuboidShell.toast('Displaying your custom reports', 'info')" class="sub-nav-item text-stone-600">
            <span class="flex items-center gap-2"><i data-lucide="user" class="w-3.5 h-3.5 text-stone-400"></i> Your reports</span>
            <span class="text-[11px] text-stone-400 font-mono">0</span>
          </a>
          <div id="shell-favourites-box" class="pt-2 pb-1 cursor-pointer">
            <div class="flex items-center justify-between text-[11.5px] font-medium text-stone-700 px-2 py-1">
              <span class="flex items-center gap-1.5"><i data-lucide="heart" class="w-3.5 h-3.5 text-rose-500"></i> Your favourites</span>
              <span id="shell-fav-count" class="text-[11px] text-stone-400 font-mono">0</span>
            </div>
            <div id="shell-fav-desc" class="text-[11px] text-stone-400 px-2 py-0.5">No reports added</div>
          </div>
          <a href="javascript:void(0)" onclick="CuboidDashboard.showTopicsModal()" class="sub-nav-item text-stone-600 hover:text-stone-900 transition-colors">
            <span class="flex items-center gap-2"><i data-lucide="message-square" class="w-3.5 h-3.5 text-stone-400"></i> Conversation topics</span>
            <i data-lucide="chevron-right" class="w-3 h-3 text-stone-400"></i>
          </a>
          <a href="../api/analytics.php?action=export_csv" download="cuboidpilot_conversations.csv" class="sub-nav-item text-stone-600 hover:text-stone-900 transition-colors" title="Export conversation data to CSV">
            <span class="flex items-center gap-2"><i data-lucide="download" class="w-3.5 h-3.5 text-stone-400"></i> Dataset export</span>
            <span class="text-[10px] text-emerald-600 font-medium ml-auto">CSV</span>
          </a>
          <div class="pt-3">
            <a href="javascript:void(0)" onclick="CuboidDashboard.filterOverview('ai')" class="sub-nav-item text-stone-600 hover:text-stone-900">
              <span class="flex items-center gap-2"><i data-lucide="bot" class="w-3.5 h-3.5 text-stone-400"></i> AI & Automation</span>
              <div class="flex items-center gap-1">
                <span class="text-[11px] text-stone-400 font-mono">8</span>
                <i data-lucide="chevron-right" class="w-3 h-3 text-stone-400"></i>
              </div>
            </a>
            <a href="javascript:void(0)" onclick="CuboidDashboard.filterOverview('human')" class="sub-nav-item text-stone-600 hover:text-stone-900">
              <span class="flex items-center gap-2"><i data-lucide="headphones" class="w-3.5 h-3.5 text-stone-400"></i> Human support</span>
              <div class="flex items-center gap-1">
                <span class="text-[11px] text-stone-400 font-mono">9</span>
                <i data-lucide="chevron-right" class="w-3 h-3 text-stone-400"></i>
              </div>
            </a>
            <a href="javascript:void(0)" onclick="CuboidDashboard.filterOverview('proactive')" class="sub-nav-item text-stone-600 hover:text-stone-900">
              <span class="flex items-center gap-2"><i data-lucide="target" class="w-3.5 h-3.5 text-stone-400"></i> Proactive</span>
              <div class="flex items-center gap-1">
                <span class="text-[11px] text-stone-400 font-mono">4</span>
                <i data-lucide="chevron-right" class="w-3 h-3 text-stone-400"></i>
              </div>
            </a>
          </div>
          <div class="pt-3 pb-1 border-t border-[#f0ede6] mt-2">
            <div class="text-[10px] font-bold text-stone-400 uppercase tracking-wider px-2 pb-1">Channels</div>
            <a href="channels.html" class="sub-nav-item text-stone-600 hover:text-stone-900 transition-colors">
              <span class="flex items-center gap-2"><i data-lucide="layers" class="w-3.5 h-3.5 text-stone-500"></i> Channels</span>
              <span class="text-[9.5px] font-mono px-1 py-0.2 bg-emerald-50 text-emerald-700 rounded border border-emerald-200 ml-auto">11 APPS</span>
            </a>
          </div>
        </div>
        <div id="shell-plan-card-container" class="p-3 border-t border-[#e7e5de] bg-[#fbfaf8]">
          <div class="p-2.5 bg-white border border-[#e7e5de] rounded-[6px] shadow-2xs space-y-2.5">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-1.5 min-w-0">
                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                <span class="text-xs font-semibold text-stone-900 truncate">7 days left</span>
              </div>
              <span class="text-[10px] font-mono font-medium px-1.5 py-0.5 bg-amber-50 text-amber-800 border border-amber-200/70 rounded-[4px] shrink-0">TRIAL</span>
            </div>
            <p class="text-[11px] text-stone-500 leading-snug m-0">
              Advanced trial · unlimited AI
            </p>
            <a href="billing.html" class="flex items-center justify-center gap-1.5 w-full py-1.5 px-2 bg-stone-900 hover:bg-black text-white text-xs font-medium rounded-[4px] no-underline transition-colors shadow-2xs">
              <span>Upgrade Plan</span>
              <i data-lucide="arrow-right" class="w-3 h-3 text-stone-300"></i>
            </a>
            <div class="flex items-center justify-between text-[11px] text-stone-500 pt-1.5 border-t border-[#f0ede6]">
              <span class="flex items-center gap-1.5 text-stone-600">
                <i data-lucide="check" class="w-3 h-3 text-emerald-600 stroke-[2.5]"></i>
                <span>Get set up</span>
              </span>
              <i data-lucide="chevron-right" class="w-3 h-3 text-stone-400"></i>
            </div>
          </div>
        </div>
      `;
    } else if (active === 'leads' || active === 'pipeline') {
      // 2. Contacts / Leads Contextual Pane with Pipeline Kanban View
      subNavHtml = `
        <div class="sub-sidebar-header flex items-center justify-between">
          <span class="font-semibold text-xs text-stone-900 tracking-tight">Contacts & Pipeline</span>
          <div class="flex items-center gap-1">
            <button class="p-1 hover:bg-stone-100 rounded text-stone-600 transition-colors" onclick="CuboidDashboard.openAddLeadModal ? CuboidDashboard.openAddLeadModal() : window.location.href='pipeline.html'" title="Add contact / lead"><i data-lucide="plus" class="w-3.5 h-3.5"></i></button>
            <button class="p-1 hover:bg-stone-100 rounded text-stone-500 transition-colors" onclick="window.location.href='pipeline.html'" title="Open Pipeline Kanban"><i data-lucide="kanban" class="w-3.5 h-3.5"></i></button>
            <button type="button" class="shell-mobile-close lg:hidden p-1 text-stone-400 hover:text-stone-900 rounded transition-colors" title="Close menu"><i data-lucide="x" class="w-4 h-4"></i></button>
          </div>
        </div>
        <div class="sub-sidebar-nav flex-1 overflow-y-auto">
          <!-- Views Section -->
          <div class="pt-1 pb-1">
            <div class="text-[10px] font-bold text-stone-400 uppercase tracking-wider px-2 pt-1 pb-1">Views</div>
            <a href="pipeline.html" class="sub-nav-item ${active === 'pipeline' ? 'active font-medium' : 'text-stone-600'}">
              <span class="flex items-center gap-2"><i data-lucide="kanban" class="w-3.5 h-3.5 ${active === 'pipeline' ? 'text-stone-900' : 'text-stone-400'}"></i> Pipeline Kanban</span>
              <span class="text-[10px] font-mono px-1.5 py-0.2 bg-stone-100 text-stone-700 rounded border border-stone-200/60 ml-auto">Kanban</span>
            </a>
            <a href="leads.html" data-lead-filter="all" class="sub-nav-item ${active === 'leads' ? 'active font-medium' : 'text-stone-600'}">
              <span class="flex items-center gap-2"><i data-lucide="table" class="w-3.5 h-3.5 ${active === 'leads' ? 'text-stone-900' : 'text-stone-400'}"></i> All Contacts (List)</span>
              <span id="shell-contacts-count" class="text-[11px] text-stone-600 font-mono font-medium ml-auto">0</span>
            </a>
          </div>

          <!-- Segments Section -->
          <div class="pt-3 pb-1 border-t border-[#f0ede6] mt-2">
            <div class="flex items-center justify-between text-[10px] font-bold text-stone-400 uppercase tracking-wider px-2 pt-1 pb-1">
              <span>Segments</span>
              <i data-lucide="chevron-down" class="w-3 h-3 text-stone-400"></i>
            </div>
            <a href="leads.html?filter=new" data-lead-filter="new" class="sub-nav-item text-stone-600">
              <span class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span> New Leads</span>
              <span id="shell-contacts-new" class="text-[11px] text-stone-400 font-mono ml-auto">0</span>
            </a>
            <a href="leads.html?filter=loyal" data-lead-filter="loyal" class="sub-nav-item text-stone-600">
              <span class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Loyal Customers</span>
              <span id="shell-contacts-loyal" class="text-[11px] text-stone-400 font-mono ml-auto">0</span>
            </a>
            <a href="leads.html?filter=lost" data-lead-filter="lost" class="sub-nav-item text-stone-600">
              <span class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span> Lost Opportunities</span>
              <span id="shell-contacts-lost" class="text-[11px] text-stone-400 font-mono ml-auto">0</span>
            </a>
          </div>

          <!-- Quick Navigation -->
          <div class="pt-3 pb-1 border-t border-[#f0ede6] mt-2">
            <a href="overview.html" class="flex items-center justify-between text-[11.5px] font-medium text-stone-700 px-2 py-1.5 hover:bg-stone-100 rounded transition-colors no-underline">
              <span class="flex items-center gap-2"><i data-lucide="layout-grid" class="w-3.5 h-3.5 text-stone-400"></i> Executive Reports</span>
              <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-stone-400"></i>
            </a>
            <a href="payments.html" class="flex items-center justify-between text-[11.5px] font-medium text-stone-700 px-2 py-1.5 hover:bg-stone-100 rounded transition-colors no-underline">
              <span class="flex items-center gap-2"><i data-lucide="receipt" class="w-3.5 h-3.5 text-stone-400"></i> Payment Requests</span>
              <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-stone-400"></i>
            </a>
            <a href="conversations.html" class="flex items-center justify-between text-[11.5px] font-medium text-stone-700 px-2 py-1.5 hover:bg-stone-100 rounded transition-colors no-underline">
              <span class="flex items-center gap-2"><i data-lucide="message-square" class="w-3.5 h-3.5 text-stone-400"></i> Inbox &amp; Chats</span>
              <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-stone-400"></i>
            </a>
          </div>
        </div>
        <div class="p-3 border-t border-[#e7e5de] bg-[#fbfaf8]">
          <div class="flex items-center justify-between text-xs text-stone-500">
            <span id="shell-company-name" class="font-medium text-stone-800">${this.company ? this.escapeHtml(this.company.name) : 'CuboidSoft'}</span>
            <span id="shell-company-status" class="text-[10px] text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded border border-emerald-200">Active</span>
          </div>
        </div>
      `;
    } else if (active === 'conversations') {
      // 3. Vision-Aligned Inbox Navigation
      subNavHtml = `
        <div class="sub-sidebar-header flex items-center justify-between">
          <span class="font-semibold text-xs text-stone-900 tracking-tight">Conversations</span>
          <div class="flex items-center gap-1">
            <button class="p-1 hover:bg-stone-100 rounded text-stone-600 transition-colors" onclick="CuboidShell.openBottomWidget()" title="Simulate / Test New Chat"><i data-lucide="plus" class="w-3.5 h-3.5"></i></button>
            <button class="p-1 hover:bg-stone-100 rounded text-stone-400 transition-colors" id="inbox-refresh-btn" onclick="CuboidDashboard.loadConversations()" title="Refresh List"><i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i></button>
            <button type="button" class="shell-mobile-close lg:hidden p-1 text-stone-400 hover:text-stone-900 rounded transition-colors" title="Close menu"><i data-lucide="x" class="w-4 h-4"></i></button>
          </div>
        </div>
        <div class="sub-sidebar-nav flex-1 overflow-y-auto space-y-0.5">
          <!-- Main Smart Queues -->
          <div class="text-[10px] font-bold text-stone-400 uppercase tracking-wider px-2 pt-1 pb-1">Queues</div>
          
          <a href="javascript:void(0)" onclick="CuboidDashboard.setConversationFilter('all')" data-convo-filter="all" class="sub-nav-item active font-medium">
            <span class="flex items-center gap-2"><i data-lucide="messages-square" class="w-3.5 h-3.5 text-stone-700"></i> All Inquiries</span>
            <span id="convo-count-all" class="text-[11px] text-stone-800 font-mono font-medium">0</span>
          </a>
          <a href="javascript:void(0)" onclick="CuboidDashboard.setConversationFilter('ai')" data-convo-filter="ai" class="sub-nav-item text-stone-600">
            <span class="flex items-center gap-2"><i data-lucide="bot" class="w-3.5 h-3.5 text-indigo-600"></i> AI Autonomous</span>
            <span id="convo-count-ai" class="text-[11px] text-indigo-600 font-mono font-medium">0</span>
          </a>
          <a href="javascript:void(0)" onclick="CuboidDashboard.setConversationFilter('human')" data-convo-filter="human" class="sub-nav-item text-stone-600">
            <span class="flex items-center gap-2"><i data-lucide="user-check" class="w-3.5 h-3.5 text-amber-600"></i> Human Action</span>
            <span id="convo-count-human" class="text-[11px] text-amber-600 font-mono font-medium">0</span>
          </a>
          <a href="javascript:void(0)" onclick="CuboidDashboard.setConversationFilter('high_intent')" data-convo-filter="high_intent" class="sub-nav-item text-stone-600">
            <span class="flex items-center gap-2"><i data-lucide="flame" class="w-3.5 h-3.5 text-rose-500"></i> High Intent Leads</span>
            <span id="convo-count-high_intent" class="text-[11px] text-rose-600 font-mono font-medium">0</span>
          </a>

          <!-- Channels Section -->
          <div class="pt-3 pb-1">
            <div class="text-[10px] font-bold text-stone-400 uppercase tracking-wider px-2 pb-1">Channels</div>
            <a href="javascript:void(0)" onclick="CuboidDashboard.setConversationFilter('widget')" data-convo-filter="widget" class="sub-nav-item text-stone-600">
              <span class="flex items-center gap-2"><i data-lucide="globe" class="w-3.5 h-3.5 text-stone-500"></i> Website Widget</span>
              <span id="convo-count-widget" class="text-[10.5px] text-stone-400 font-mono">0</span>
            </a>
            <a href="javascript:void(0)" onclick="CuboidDashboard.setConversationFilter('whatsapp')" data-convo-filter="whatsapp" class="sub-nav-item text-stone-600">
              <span class="flex items-center gap-2"><i data-lucide="message-circle" class="w-3.5 h-3.5 text-[#25D366]"></i> WhatsApp Business</span>
              <span id="convo-count-whatsapp" class="text-[10.5px] text-stone-400 font-mono">0</span>
            </a>
          </div>

          <!-- Status Section -->
          <div class="pt-2 pb-1">
            <div class="text-[10px] font-bold text-stone-400 uppercase tracking-wider px-2 pb-1">Status</div>
            <a href="javascript:void(0)" onclick="CuboidDashboard.setConversationFilter('open')" data-convo-filter="open" class="sub-nav-item text-stone-600">
              <span class="flex items-center gap-2"><i data-lucide="circle-dot" class="w-3.5 h-3.5 text-emerald-600"></i> Open Inquiries</span>
              <span id="convo-count-open" class="text-[10.5px] text-stone-400 font-mono">0</span>
            </a>
            <a href="javascript:void(0)" onclick="CuboidDashboard.setConversationFilter('resolved')" data-convo-filter="resolved" class="sub-nav-item text-stone-600">
              <span class="flex items-center gap-2"><i data-lucide="check-circle" class="w-3.5 h-3.5 text-stone-400"></i> Resolved</span>
              <span id="convo-count-resolved" class="text-[10.5px] text-stone-400 font-mono">0</span>
            </a>
          </div>
        </div>
        <div class="p-3 border-t border-[#e7e5de] bg-[#fbfaf8]">
          <div class="flex items-center justify-between text-xs text-stone-500">
            <span class="flex items-center gap-1.5 text-emerald-700 font-medium">
              <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span> Cai Live AI
            </span>
            <span class="text-[10.5px] text-stone-400">Sync nominal</span>
          </div>
        </div>
      `;
    } else if (active === 'channels') {
      // 4. Dedicated Channels & Integrations Contextual Pane
      subNavHtml = `
        <div class="sub-sidebar-header flex items-center justify-between">
          <span class="font-semibold text-xs text-stone-900 tracking-tight">Channels &amp; Integrations</span>
          <button type="button" class="shell-mobile-close lg:hidden p-1 text-stone-400 hover:text-stone-900 rounded transition-colors" title="Close menu"><i data-lucide="x" class="w-4 h-4"></i></button>
        </div>
        <div class="sub-sidebar-nav flex-1 overflow-y-auto space-y-0.5">
          <div class="text-[10px] font-bold text-stone-400 uppercase tracking-wider px-2 pt-1 pb-1">Filter Apps</div>
          <a href="javascript:void(0)" onclick="window.filterCategory ? window.filterCategory('all', this) : null" class="cat-pill sub-nav-item active font-medium">
            <span class="flex items-center gap-2"><i data-lucide="layout-grid" class="w-3.5 h-3.5 text-stone-900"></i> All Channels</span>
            <span class="text-[11px] text-stone-600 font-mono font-medium ml-auto">11</span>
          </a>
          <a href="javascript:void(0)" onclick="window.filterCategory ? window.filterCategory('messaging', this) : null" class="cat-pill sub-nav-item text-stone-600">
            <span class="flex items-center gap-2"><i data-lucide="message-square" class="w-3.5 h-3.5 text-[#25D366]"></i> Messaging</span>
            <span class="text-[11px] text-stone-400 font-mono ml-auto">3</span>
          </a>
          <a href="javascript:void(0)" onclick="window.filterCategory ? window.filterCategory('scheduling', this) : null" class="cat-pill sub-nav-item text-stone-600">
            <span class="flex items-center gap-2"><i data-lucide="calendar" class="w-3.5 h-3.5 text-blue-500"></i> Scheduling</span>
            <span class="text-[11px] text-stone-400 font-mono ml-auto">2</span>
          </a>
          <a href="javascript:void(0)" onclick="window.filterCategory ? window.filterCategory('crm', this) : null" class="cat-pill sub-nav-item text-stone-600">
            <span class="flex items-center gap-2"><i data-lucide="database" class="w-3.5 h-3.5 text-amber-600"></i> CRM &amp; Pipeline</span>
            <span class="text-[11px] text-stone-400 font-mono ml-auto">4</span>
          </a>
          <a href="javascript:void(0)" onclick="window.filterCategory ? window.filterCategory('automation', this) : null" class="cat-pill sub-nav-item text-stone-600">
            <span class="flex items-center gap-2"><i data-lucide="zap" class="w-3.5 h-3.5 text-purple-600"></i> Automation &amp; Webhooks</span>
            <span class="text-[11px] text-stone-400 font-mono ml-auto">2</span>
          </a>

          <div class="pt-3 pb-1 border-t border-[#f0ede6] mt-2">
            <div class="text-[10px] font-bold text-stone-400 uppercase tracking-wider px-2 pb-1">Quick Links</div>
            <a href="whatsapp.html" class="sub-nav-item text-stone-600 hover:text-stone-900">
              <span class="flex items-center gap-2"><i data-lucide="phone-forwarded" class="w-3.5 h-3.5 text-stone-400"></i> WhatsApp Outbound</span>
            </a>
            <a href="settings.html" class="sub-nav-item text-stone-600 hover:text-stone-900">
              <span class="flex items-center gap-2"><i data-lucide="sliders" class="w-3.5 h-3.5 text-stone-400"></i> All Workspace Settings</span>
            </a>
          </div>
        </div>
        <div class="p-3 border-t border-[#e7e5de] bg-[#fbfaf8]">
          <div class="flex items-center justify-between text-xs text-stone-500">
            <span id="shell-company-name" class="font-medium text-stone-800">${this.company ? this.escapeHtml(this.company.name) : 'CuboidSoft'}</span>
            <span class="text-[10px] text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded border border-emerald-200 font-mono font-medium">11 Apps</span>
          </div>
        </div>
      `;
    } else if (active === 'payments') {
      // 5. Dedicated Payments & Collections Contextual Pane
      subNavHtml = `
        <div class="sub-sidebar-header flex items-center justify-between">
          <span class="font-semibold text-xs text-stone-900 tracking-tight">Payments &amp; Finance</span>
          <div class="flex items-center gap-1">
            <button class="p-1 hover:bg-stone-100 rounded text-stone-600 transition-colors" onclick="window.openManualPaymentModal ? window.openManualPaymentModal() : null" title="Record Payment"><i data-lucide="plus" class="w-3.5 h-3.5"></i></button>
            <button class="p-1 hover:bg-stone-100 rounded text-stone-400 transition-colors" onclick="window.loadPayments ? window.loadPayments() : null" title="Refresh"><i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i></button>
            <button type="button" class="shell-mobile-close lg:hidden p-1 text-stone-400 hover:text-stone-900 rounded transition-colors" title="Close menu"><i data-lucide="x" class="w-4 h-4"></i></button>
          </div>
        </div>
        <div class="sub-sidebar-nav flex-1 overflow-y-auto space-y-0.5">
          <div class="text-[10px] font-bold text-stone-400 uppercase tracking-wider px-2 pt-1 pb-1">Payment Queues</div>
          <a href="payments.html" onclick="window.filterTab ? window.filterTab('all', this) : null" class="sub-nav-item active font-medium">
            <span class="flex items-center gap-2"><i data-lucide="receipt" class="w-3.5 h-3.5 text-stone-900"></i> All Requests</span>
          </a>
          <a href="javascript:void(0)" onclick="window.filterTab ? window.filterTab('pending', this) : null" class="sub-nav-item text-stone-600 hover:text-stone-900">
            <span class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span> Pending Dues</span>
          </a>
          <a href="javascript:void(0)" onclick="window.filterTab ? window.filterTab('completed', this) : null" class="sub-nav-item text-stone-600 hover:text-stone-900">
            <span class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Confirmed &amp; Paid</span>
          </a>
          <a href="javascript:void(0)" onclick="window.filterTab ? window.filterTab('cancelled', this) : null" class="sub-nav-item text-stone-600 hover:text-stone-900">
            <span class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-stone-400"></span> Cancelled / Expired</span>
          </a>

          <div class="pt-3 pb-1 border-t border-[#f0ede6] mt-2">
            <div class="text-[10px] font-bold text-stone-400 uppercase tracking-wider px-2 pb-1">Operations</div>
            <a href="settings.html" class="sub-nav-item text-stone-600 hover:text-stone-900">
              <span class="flex items-center gap-2"><i data-lucide="qr-code" class="w-3.5 h-3.5 text-stone-400"></i> Payment QR &amp; UPI Setup</span>
            </a>
            <a href="billing.html" class="sub-nav-item text-stone-600 hover:text-stone-900">
              <span class="flex items-center gap-2"><i data-lucide="credit-card" class="w-3.5 h-3.5 text-stone-400"></i> Subscription Plans</span>
            </a>
          </div>
        </div>
        <div class="p-3 border-t border-[#e7e5de] bg-[#fbfaf8]">
          <div class="flex items-center justify-between text-xs text-stone-500">
            <span id="shell-company-name" class="font-medium text-stone-800">${this.company ? this.escapeHtml(this.company.name) : 'CuboidSoft'}</span>
            <span class="text-[10px] text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded border border-emerald-200 font-mono font-medium">Secured</span>
          </div>
        </div>
      `;
    } else if (active === 'automations') {
      // 5b. Dedicated Automations & Rules Contextual Pane
      subNavHtml = `
        <div class="sub-sidebar-header flex items-center justify-between">
          <span class="font-semibold text-xs text-stone-900 tracking-tight">Automations &amp; Rules</span>
          <div class="flex items-center gap-1">
            <button class="p-1 hover:bg-stone-100 rounded text-stone-600 transition-colors" onclick="window.switchMainTab ? window.switchMainTab('automation') : null; window.resetRuleForm ? window.resetRuleForm() : null;" title="New Automation Rule"><i data-lucide="plus" class="w-3.5 h-3.5"></i></button>
            <button class="p-1 hover:bg-stone-100 rounded text-stone-400 transition-colors" onclick="window.loadAutomationsCockpit ? window.loadAutomationsCockpit() : null" title="Refresh Rules"><i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i></button>
            <button type="button" class="shell-mobile-close lg:hidden p-1 text-stone-400 hover:text-stone-900 rounded transition-colors" title="Close menu"><i data-lucide="x" class="w-4 h-4"></i></button>
          </div>
        </div>
        <div class="sub-sidebar-nav flex-1 overflow-y-auto space-y-0.5">
          <div class="text-[10px] font-bold text-stone-400 uppercase tracking-wider px-2 pt-1 pb-1">Workflows</div>
          <a href="javascript:void(0)" onclick="window.switchMainTab ? window.switchMainTab('automation') : null" class="sub-nav-item active font-medium" id="side-link-auto">
            <span class="flex items-center gap-2"><i data-lucide="zap" class="w-3.5 h-3.5 text-emerald-600"></i> Automation</span>
            <span id="shell-rules-badge" class="text-[10px] font-mono font-bold px-1.5 py-0.2 bg-emerald-50 text-emerald-800 rounded border border-emerald-200 ml-auto">4</span>
          </a>
          <a href="javascript:void(0)" onclick="window.switchMainTab ? window.switchMainTab('templates') : null" class="sub-nav-item text-stone-600 hover:text-stone-900" id="side-link-tpl">
            <span class="flex items-center gap-2"><i data-lucide="layout-template" class="w-3.5 h-3.5 text-purple-600"></i> Meta Templates</span>
            <span class="text-[10px] font-mono px-1.5 py-0.2 bg-stone-100 text-stone-600 rounded ml-auto">5</span>
          </a>
          
          <div class="pt-3 pb-1 border-t border-[#f0ede6] mt-2">
            <div class="text-[10px] font-bold text-stone-400 uppercase tracking-wider px-2 pb-1">WhatsApp Suite</div>
            <a href="whatsapp.html" class="sub-nav-item text-stone-600 hover:text-stone-900">
              <span class="flex items-center gap-2"><i data-lucide="message-square" class="w-3.5 h-3.5 text-[#25D366]"></i> WhatsApp Inbox</span>
            </a>
            <a href="channels.html" class="sub-nav-item text-stone-600 hover:text-stone-900">
              <span class="flex items-center gap-2"><i data-lucide="layers" class="w-3.5 h-3.5 text-stone-400"></i> All Channels</span>
              <span class="text-[9px] font-mono px-1 py-0.2 bg-emerald-50 text-emerald-700 rounded border border-emerald-200 ml-auto">11 APPS</span>
            </a>
            <a href="ai-assistant.html" class="sub-nav-item text-stone-600 hover:text-stone-900">
              <span class="flex items-center gap-2"><i data-lucide="sparkles" class="w-3.5 h-3.5 text-amber-500"></i> Cai AI Copilot</span>
            </a>
          </div>
        </div>
        <div class="p-3 border-t border-[#e7e5de] bg-[#fbfaf8]">
          <div class="flex items-center justify-between text-xs text-stone-500">
            <span class="flex items-center gap-1.5 text-emerald-700 font-medium">
              <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span> Meta Live
            </span>
            <span class="text-[10px] font-mono text-stone-400">+91 82249 73413</span>
          </div>
        </div>
      `;
    } else {
      // 6. Default / Settings Contextual Pane
      subNavHtml = `
        <div class="sub-sidebar-header flex items-center justify-between">
          <span>Settings</span>
          <button type="button" class="shell-mobile-close lg:hidden p-1 text-stone-400 hover:text-stone-900 rounded transition-colors" title="Close menu"><i data-lucide="x" class="w-4 h-4"></i></button>
        </div>
        <div class="sub-sidebar-nav flex-1 overflow-y-auto">
          <a href="settings.html" class="sub-nav-item ${active === 'settings' ? 'active' : ''}">
            <span class="flex items-center gap-2"><i data-lucide="sliders" class="w-3.5 h-3.5 text-stone-600"></i> General</span>
          </a>
          <a href="ai-assistant.html" class="sub-nav-item ${active === 'ai-assistant' ? 'active' : ''}">
            <span class="flex items-center gap-2"><i data-lucide="bot" class="w-3.5 h-3.5 text-stone-600"></i> AI Assistant</span>
          </a>
          <a href="knowledge.html" class="sub-nav-item ${active === 'knowledge' ? 'active' : ''}">
            <span class="flex items-center gap-2"><i data-lucide="book-marked" class="w-3.5 h-3.5 text-stone-600"></i> Knowledge Grounding</span>
          </a>
          <!-- Expandable Channels Accordion -->
          <div class="nav-accordion-item">
            <button type="button" onclick="CuboidShell.toggleChannelsNav(this)" class="sub-nav-item w-full flex items-center justify-between text-left cursor-pointer transition-colors ${active === 'channels' || active === 'whatsapp' ? 'font-medium text-stone-900 bg-stone-100/70' : 'text-stone-600 hover:text-stone-900'}">
              <span class="flex items-center gap-2"><i data-lucide="layers" class="w-3.5 h-3.5 text-stone-600"></i> Channels</span>
              <i data-lucide="chevron-down" id="shell-channels-chevron" class="w-3.5 h-3.5 text-stone-400 transition-transform duration-200 ${active === 'channels' || active === 'whatsapp' ? 'rotate-180' : ''}"></i>
            </button>
            <div id="shell-channels-submenu" class="pl-4 pr-1 space-y-0.5 pt-1 pb-1 transition-all ${active === 'channels' || active === 'whatsapp' || active === 'automations' || active === 'contacts' || active === 'templates' ? '' : 'hidden'}">
              <a href="whatsapp.html" class="sub-nav-item text-xs py-1.5 ${active === 'whatsapp' ? 'active font-medium text-stone-900 bg-stone-100' : 'text-stone-600 hover:text-stone-900'}">
                <span class="flex items-center gap-2"><i data-lucide="phone-forwarded" class="w-3 h-3 text-[#25D366]"></i> WhatsApp Inbox</span>
              </a>
              <a href="automations.html" class="sub-nav-item text-xs py-1.5 ${active === 'automations' ? 'active font-medium text-stone-900 bg-stone-100' : 'text-stone-600 hover:text-stone-900'}">
                <span class="flex items-center gap-2"><i data-lucide="zap" class="w-3 h-3 text-amber-500"></i> Automations</span>
              </a>
              <a href="contacts.html" class="sub-nav-item text-xs py-1.5 ${active === 'contacts' ? 'active font-medium text-stone-900 bg-stone-100' : 'text-stone-600 hover:text-stone-900'}">
                <span class="flex items-center gap-2"><i data-lucide="users" class="w-3 h-3 text-blue-500"></i> Contacts CRM</span>
              </a>
              <a href="automations.html?tab=templates" class="sub-nav-item text-xs py-1.5 ${active === 'templates' ? 'active font-medium text-stone-900 bg-stone-100' : 'text-stone-600 hover:text-stone-900'}">
                <span class="flex items-center gap-2"><i data-lucide="layout-template" class="w-3 h-3 text-purple-500"></i> Meta Templates</span>
              </a>
              <a href="channels.html" class="sub-nav-item text-xs py-1.5 ${active === 'channels' ? 'active font-medium text-stone-900 bg-stone-100' : 'text-stone-600 hover:text-stone-900'}">
                <span class="flex items-center gap-2"><i data-lucide="layout-grid" class="w-3 h-3 text-stone-500"></i> Integrations Hub</span>
                <span class="text-[9px] font-mono px-1 py-0.2 bg-emerald-50 text-emerald-700 rounded border border-emerald-200 ml-auto">11 APPS</span>
              </a>
            </div>
          </div>
          <a href="team.html" class="sub-nav-item ${active === 'team' ? 'active' : ''}">
            <span class="flex items-center gap-2"><i data-lucide="users" class="w-3.5 h-3.5 text-stone-600"></i> Team Members</span>
          </a>
          <a href="appointments.html" class="sub-nav-item ${active === 'appointments' ? 'active' : ''}">
            <span class="flex items-center gap-2"><i data-lucide="calendar" class="w-3.5 h-3.5 text-stone-600"></i> Appointments</span>
          </a>
          <a href="payments.html" class="sub-nav-item ${active === 'payments' ? 'active' : ''}">
            <span class="flex items-center gap-2"><i data-lucide="receipt" class="w-3.5 h-3.5 text-stone-600"></i> Payment Requests</span>
          </a>
          <a href="billing.html" class="sub-nav-item ${active === 'billing' ? 'active' : ''}">
            <span class="flex items-center gap-2"><i data-lucide="credit-card" class="w-3.5 h-3.5 text-stone-600"></i> Billing & Plans</span>
          </a>
        </div>
        <div class="p-3 border-t border-[#e7e5de] bg-[#fbfaf8]">
          <div class="flex items-center justify-between text-xs text-stone-500">
            <span id="shell-company-name" class="font-medium text-stone-800">${this.company ? this.escapeHtml(this.company.name) : 'CuboidSoft'}</span>
          </div>
        </div>
      `;
    }

    el.innerHTML = `
      <div class="app-dual-sidebar">
        <!-- Far-Left Slim Icon Rail (58px) -->
        <aside class="app-icon-rail">
          <div class="flex flex-col items-center w-full">
            <!-- App Logo Badge (Clean, No Box Background) -->
            <a href="overview.html" class="rail-logo-badge" title="CuboidPilot">
              <img id="shell-rail-logo" src="${this.resolveAssetUrl(this.company ? (this.company.logo_light_url || this.company.logo_url || this.company.logo_dark_url) : null, '../assets/logo-black.png')}" onerror="this.onerror=null; this.src='../assets/logo-black.png';" alt="Logo" class="w-7 h-7 object-contain">
            </a>

            <!-- Navigation Rail List -->
            <div class="rail-nav-list">
              <a href="overview.html" class="rail-item ${active === 'overview' ? 'active' : ''}" title="Overview">
                <i data-lucide="home" class="w-5 h-5"></i>
              </a>
              <a href="conversations.html" class="rail-item ${active === 'conversations' ? 'active' : ''}" title="Inbox">
                <i data-lucide="inbox" class="w-5 h-5"></i>
                <span class="rail-badge-dot"></span>
              </a>
              <a href="ai-assistant.html" class="rail-item ${active === 'ai-assistant' || active === 'knowledge' ? 'active' : ''}" title="AI Assistant">
                <i data-lucide="sparkles" class="w-5 h-5"></i>
              </a>
              <a href="whatsapp.html" class="rail-item ${active === 'whatsapp' ? 'active' : ''}" title="WhatsApp Command Center">
                <i data-lucide="message-square" class="w-5 h-5"></i>
              </a>
              <a href="automations.html" class="rail-item ${active === 'automations' ? 'active' : ''}" title="Automation">
                <i data-lucide="zap" class="w-5 h-5"></i>
              </a>
              <a href="pipeline.html" class="rail-item ${active === 'leads' || active === 'pipeline' ? 'active' : ''}" title="Pipeline Kanban & Contacts">
                <i data-lucide="kanban" class="w-5 h-5"></i>
              </a>
              <a href="channels.html" class="rail-item ${active === 'channels' ? 'active' : ''}" title="Channels &amp; Integrations">
                <i data-lucide="layers" class="w-5 h-5"></i>
              </a>
              <a href="payments.html" class="rail-item ${active === 'payments' ? 'active' : ''}" title="Payment Requests">
                <i data-lucide="receipt" class="w-5 h-5"></i>
              </a>
              <a href="overview.html" class="rail-item ${active === 'analytics' ? 'active' : ''}" title="Reports">
                <i data-lucide="bar-chart-2" class="w-5 h-5"></i>
              </a>
            </div>
          </div>

          <!-- Rail Bottom Items -->
          <div class="flex flex-col items-center gap-2 w-full pb-2">
            <div class="relative group/rail-connect" id="rail-connect-wrapper">
              <a href="connect.html" class="rail-item ${active === 'connect' ? 'active' : ''} text-stone-400 hover:text-stone-700 relative" title="Connect & Deploy">
                <i data-lucide="link-2" class="w-5 h-5"></i>
                <span class="absolute top-1 right-1 w-2.5 h-2.5 rounded-full bg-emerald-500 ring-2 ring-white">
                  <span class="absolute inset-0 rounded-full bg-emerald-400 animate-ping opacity-75"></span>
                </span>
              </a>

              <!-- Clean White Tooltip (Default Open) -->
              <a href="connect.html" id="rail-connect-tooltip" class="absolute left-full ml-3 top-1/2 -translate-y-1/2 z-[100] whitespace-nowrap flex items-center gap-1.5 shadow-md hover:shadow-lg rounded-[6px] bg-white border border-[#e2e0d8] px-2.5 py-1 text-[11px] font-medium text-stone-800 transition-all select-none hover:border-stone-400 group/tt">
                <!-- Triangle pointer pointing to icon -->
                <div class="absolute right-full top-1/2 -translate-y-1/2 border-[5px] border-transparent border-r-white"></div>
                <div class="absolute right-full top-1/2 -translate-y-1/2 border-[6px] border-transparent border-r-[#e2e0d8] -z-10"></div>
                
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                <span class="font-semibold text-stone-900">Start Here:</span>
                <span class="text-stone-600">Connect & Deploy</span>
                <i data-lucide="arrow-right" class="w-3 h-3 text-stone-400 group-hover/tt:translate-x-0.5 transition-transform"></i>
              </a>
            </div>
            <a href="settings.html" class="rail-item ${active === 'settings' ? 'active' : ''} text-stone-400 hover:text-stone-700" title="Settings">
              <i data-lucide="settings" class="w-5 h-5"></i>
            </a>
            <a href="../logout.php" class="rail-item text-stone-400 hover:text-rose-600 transition-colors" title="Log Out / Sign Out" id="shell-rail-logout">
              <i data-lucide="log-out" class="w-5 h-5"></i>
            </a>
            <div class="relative group mt-1" onclick="CuboidShell.toggleUserMenu(event)">
              <div id="shell-user-avatar" class="w-8 h-8 rounded-full bg-stone-900 text-white flex items-center justify-center font-semibold text-xs border border-white cursor-pointer select-none shadow-xs hover:ring-2 hover:ring-stone-400 transition-all overflow-hidden" title="User Profile & Quick Actions">
                ${this.user && this.user.avatar_url 
                  ? `<img src="${this.resolveAssetUrl(this.user.avatar_url)}" alt="${this.escapeHtml(this.user.name)}" class="w-full h-full object-cover">`
                  : (this.user ? (((this.user.name||'').split(' ')[0]?.[0]||'') + ((this.user.name||'').split(' ')[1]?.[0]||'')).toUpperCase() || 'U' : 'AM')}
              </div>
            </div>
          </div>
        </aside>

        <!-- Secondary Contextual Pane (220px) -->
        <aside class="app-sub-sidebar">
          ${subNavHtml}
        </aside>
      </div>
    `;
  },

  // Company App Topbar HTML
  renderCompanyTopbar: function(title, breadcrumbs) {
    const el = document.getElementById('topbar-container');
    if (!el) return;

    let breadcrumbHtml = `<span id="shell-breadcrumb-company" class="text-stone-400 hidden sm:inline truncate">${this.company ? this.escapeHtml(this.company.name) : 'CuboidSoft'}</span><span class="text-stone-300 hidden sm:inline">/</span> `;
    if (breadcrumbs.length > 0) {
      breadcrumbs.forEach(b => {
        breadcrumbHtml += `<span class="text-stone-500 hidden md:inline truncate">${b}</span><span class="text-stone-300 hidden md:inline">/</span> `;
      });
    }
    breadcrumbHtml += `<span class="font-medium text-[#111111] truncate">${title}</span>`;

    el.innerHTML = `
      <header class="app-topbar">
        <div class="flex items-center gap-2 sm:gap-3 min-w-0 flex-1 overflow-hidden mr-2">
          <button id="mobile-menu-trigger" class="lg:hidden p-1.5 -ml-1 text-stone-700 hover:text-black hover:bg-stone-100 rounded-[4px] shrink-0 touch-manipulation cursor-pointer" aria-label="Open Navigation Menu">
            <i data-lucide="menu" class="w-5 h-5"></i>
          </button>
          <div class="text-[12.5px] sm:text-[13px] flex items-center gap-1 sm:gap-1.5 truncate">
            ${breadcrumbHtml}
          </div>
        </div>

        <div class="flex items-center gap-1.5 sm:gap-2.5 shrink-0">
          <!-- Search Input -->
          <div class="relative hidden xl:block">
            <i data-lucide="search" class="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-stone-400"></i>
            <input type="text" placeholder="Search leads, chats, docs... (⌘K)" class="app-input pl-8 w-52 text-xs text-stone-800 placeholder:text-stone-400">
          </div>

          <!-- Test AI Drawer Trigger -->
          <button id="open-test-ai-btn" class="btn-secondary btn-sm text-xs py-1.5 px-2.5 sm:px-3 inline-flex items-center gap-1 sm:gap-1.5 hover:border-stone-800 shrink-0" title="Open AI Test Widget">
            <i data-lucide="sparkles" class="w-3.5 h-3.5 text-amber-600"></i>
            <span class="hidden sm:inline">Test AI</span>
          </button>

          <!-- Trial Countdown & Renewal Badge / Status Indicator -->
          <div id="shell-trial-indicator" class="hidden sm:inline-flex items-center gap-1.5 px-2.5 py-1 bg-emerald-50 text-emerald-800 border border-emerald-200 rounded-[4px] text-[11px] font-medium shrink-0">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-600"></span>
            <span>AI Live</span>
          </div>

          <!-- Notifications -->
          <button class="relative p-1.5 sm:p-2 text-stone-500 hover:bg-stone-100 rounded-[4px] transition-colors shrink-0" title="Notifications">
            <i data-lucide="bell" class="w-4 h-4"></i>
            <span class="absolute top-1 right-1 w-1.5 h-1.5 bg-amber-500 rounded-full"></span>
          </button>

          <!-- Topbar Logout Button -->
          <a href="../logout.php" class="p-1.5 px-2 text-stone-500 hover:text-rose-600 hover:bg-rose-50 rounded-[4px] transition-colors flex items-center gap-1.5 text-xs font-medium no-underline shrink-0" title="Sign Out of CuboidPilot">
            <i data-lucide="log-out" class="w-3.5 h-3.5"></i>
            <span class="hidden md:inline">Logout</span>
          </a>

          <!-- Topbar User Profile Avatar Pill -->
          <div class="flex items-center gap-2 pl-2 border-l border-[#e7e5de] cursor-pointer hover:opacity-90 select-none" onclick="CuboidShell.toggleUserMenu(event)" title="Profile & Account">
            <div id="topbar-user-avatar" class="w-7 h-7 rounded-full bg-stone-900 text-white flex items-center justify-center font-semibold text-xs overflow-hidden border border-stone-200 shadow-2xs">
              ${this.user && this.user.avatar_url 
                ? `<img src="${this.resolveAssetUrl(this.user.avatar_url)}" alt="${this.escapeHtml(this.user.name)}" class="w-full h-full object-cover">`
                : (this.user ? (((this.user.name||'').split(' ')[0]?.[0]||'') + ((this.user.name||'').split(' ')[1]?.[0]||'')).toUpperCase() || 'U' : 'AM')}
            </div>
            <span id="topbar-user-name" class="hidden lg:inline text-xs font-medium text-stone-800">${this.user ? this.escapeHtml(this.user.name) : 'Ayush'}</span>
            <i data-lucide="chevron-down" class="w-3 h-3 text-stone-400 hidden lg:inline"></i>
          </div>
        </div>
      </header>
    `;
  },

  // Super Admin Sidebar HTML (Dual-Sidebar)
  renderSuperAdminSidebar: function(active) {
    const el = document.getElementById('sidebar-container');
    if (!el) return;

    el.innerHTML = `
      <div class="app-dual-sidebar">
        <!-- Slim Icon Rail (58px) -->
        <aside class="app-icon-rail">
          <div class="flex flex-col items-center w-full">
            <a href="overview.html" class="rail-logo-badge" title="CuboidPilot Super Admin">
              <img src="../assets/logo-black.png" alt="Super Admin" class="w-7 h-7 object-contain">
            </a>
            <div class="rail-nav-list">
              <a href="overview.html" class="rail-item ${active === 'overview' ? 'active' : ''}" title="Overview">
                <i data-lucide="home" class="w-5 h-5"></i>
              </a>
              <a href="companies.html" class="rail-item ${active === 'companies' ? 'active' : ''}" title="Companies">
                <i data-lucide="building" class="w-5 h-5"></i>
              </a>
              <a href="subscriptions.html" class="rail-item ${active === 'subscriptions' ? 'active' : ''}" title="Billing">
                <i data-lucide="credit-card" class="w-5 h-5"></i>
              </a>
              <a href="users.html" class="rail-item ${active === 'users' ? 'active' : ''}" title="Users">
                <i data-lucide="users" class="w-5 h-5"></i>
              </a>
              <a href="blogs.html" class="rail-item ${active === 'blogs' ? 'active' : ''}" title="Blog &amp; Articles">
                <i data-lucide="newspaper" class="w-5 h-5"></i>
              </a>
              <a href="logs.html" class="rail-item ${active === 'logs' ? 'active' : ''}" title="Logs">
                <i data-lucide="activity" class="w-5 h-5"></i>
              </a>
            </div>
          </div>
          <div class="flex flex-col items-center gap-2 w-full">
            <a href="../app/overview.html" class="rail-item text-stone-400 hover:text-stone-700" title="Exit to App">
              <i data-lucide="external-link" class="w-5 h-5"></i>
            </a>
            <a href="../logout.php" class="rail-item text-stone-400 hover:text-rose-600" title="Log Out">
              <i data-lucide="log-out" class="w-5 h-5"></i>
            </a>
          </div>
        </aside>

        <!-- Secondary Platform Pane (220px) -->
        <aside class="app-sub-sidebar">
          <div class="sub-sidebar-header flex items-center justify-between">
            <div class="flex items-center gap-1.5">
              <span>Platform Admin</span>
              <span class="text-[10px] font-mono bg-stone-900 text-white px-1.5 py-0.5 rounded">ROOT</span>
            </div>
            <button type="button" class="shell-mobile-close lg:hidden p-1 text-stone-400 hover:text-stone-900 rounded transition-colors" title="Close menu"><i data-lucide="x" class="w-4 h-4"></i></button>
          </div>
          <div class="sub-sidebar-nav flex-1 overflow-y-auto">
            <a href="overview.html" class="sub-nav-item ${active === 'overview' ? 'active' : ''}">
              <span class="flex items-center gap-2"><i data-lucide="activity" class="w-3.5 h-3.5 text-stone-700"></i> Platform Health</span>
            </a>
            <a href="companies.html" class="sub-nav-item ${active === 'companies' ? 'active' : ''}">
              <span class="flex items-center gap-2"><i data-lucide="building" class="w-3.5 h-3.5 text-stone-500"></i> Tenants</span>
              <span class="text-[11px] font-mono text-stone-400">142</span>
            </a>
            <a href="subscriptions.html" class="sub-nav-item ${active === 'subscriptions' ? 'active' : ''}">
              <span class="flex items-center gap-2"><i data-lucide="credit-card" class="w-3.5 h-3.5 text-stone-500"></i> Subscriptions</span>
            </a>
            <a href="blogs.html" class="sub-nav-item ${active === 'blogs' ? 'active' : ''}">
              <span class="flex items-center gap-2"><i data-lucide="newspaper" class="w-3.5 h-3.5 text-stone-500"></i> Blog &amp; Articles</span>
            </a>
            <a href="users.html" class="sub-nav-item ${active === 'users' ? 'active' : ''}">
              <span class="flex items-center gap-2"><i data-lucide="users" class="w-3.5 h-3.5 text-stone-500"></i> System Users</span>
            </a>
            <a href="logs.html" class="sub-nav-item ${active === 'logs' ? 'active' : ''}">
              <span class="flex items-center gap-2"><i data-lucide="file-text" class="w-3.5 h-3.5 text-stone-500"></i> Audit Logs</span>
            </a>
          </div>
          <div class="p-3 border-t border-[#e7e5de] bg-[#fbfaf8]">
            <a href="../app/overview.html" class="text-xs text-stone-600 hover:text-stone-900 flex items-center justify-between">
              <span>Tenant View</span>
              <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
            </a>
          </div>
        </aside>
      </div>
    `;
  },

  // Super Admin Topbar HTML
  renderSuperAdminTopbar: function(title, breadcrumbs) {
    const el = document.getElementById('topbar-container');
    if (!el) return;

    let breadcrumbHtml = `<span class="text-stone-400 hidden sm:inline truncate">Super Admin</span><span class="text-stone-300 hidden sm:inline">/</span> `;
    if (breadcrumbs.length > 0) {
      breadcrumbs.forEach(b => {
        breadcrumbHtml += `<span class="text-stone-500 hidden md:inline truncate">${b}</span><span class="text-stone-300 hidden md:inline">/</span> `;
      });
    }
    breadcrumbHtml += `<span class="font-medium text-[#111111] truncate">${title}</span>`;

    el.innerHTML = `
      <header class="app-topbar">
        <div class="flex items-center gap-2 sm:gap-3 min-w-0 flex-1 overflow-hidden mr-2">
          <button id="mobile-menu-trigger" class="lg:hidden p-1.5 -ml-1 text-stone-700 hover:text-black hover:bg-stone-100 rounded-[4px] shrink-0 touch-manipulation cursor-pointer" title="Open Menu" aria-label="Open Navigation Menu">
            <i data-lucide="menu" class="w-5 h-5"></i>
          </button>
          <div class="text-[12.5px] sm:text-[13px] flex items-center gap-1 sm:gap-1.5 truncate">
            ${breadcrumbHtml}
          </div>
        </div>

        <div class="flex items-center gap-2 sm:gap-3 shrink-0">
          <div class="hidden sm:flex items-center gap-2 text-xs text-stone-500 mr-1">
            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
            <span class="hidden md:inline">All 6 Microservices Operational</span>
            <span class="md:hidden">Live</span>
          </div>
          <a href="activity.html" class="btn-secondary btn-sm text-xs py-1.5 px-2.5 sm:px-3 inline-flex items-center gap-1 shrink-0">
            <i data-lucide="shield" class="w-3.5 h-3.5 text-stone-600"></i>
            <span class="hidden sm:inline">Security Audit</span>
          </a>
        </div>
      </header>
    `;
  },

  // Ensure bottom-right floating widget is loaded in dashboard
  ensureBottomWidget: function() {
    if (document.getElementById('cuboidpilot-widget-script')) return;
    let companyKey = (this.company && this.company.company_key) 
      ? this.company.company_key 
      : (window.__CUBOID_COMPANY__ && window.__CUBOID_COMPANY__.company_key)
      ? window.__CUBOID_COMPANY__.company_key
      : null;
    if (!companyKey) {
      try {
        const cached = JSON.parse(sessionStorage.getItem('cp_workspace_cache') || '{}');
        if (cached && cached.company && cached.company.company_key) {
          companyKey = cached.company.company_key;
        }
      } catch (e) {}
    }
    if (!companyKey) {
      companyKey = 'cp_live_cuboidsoft';
    }
    const s = document.createElement('script');
    s.id = 'cuboidpilot-widget-script';
    s.src = '../widget.js?v=6.2.' + Date.now();
    s.setAttribute('data-company', companyKey);
    s.async = true;
    document.body.appendChild(s);
  },

  // Open the bottom-right floating widget (matching website visitor experience, no sidebar)
  openBottomWidget: function(screen = 'home') {
    this.ensureBottomWidget();
    const targetScreen = (screen && typeof screen === 'string') ? screen : 'home';
    const tryOpen = (retries = 15) => {
      const w = window.CuboidPilotWidget || window.CuboidPilot;
      if (w && typeof w.open === 'function') {
        w.open(targetScreen);
        if (typeof w.navigateTo === 'function') {
          w.navigateTo(targetScreen);
        }
      } else if (retries > 0) {
        setTimeout(() => tryOpen(retries - 1), 120);
      }
    };
    tryOpen();
  },

  // Copy embed snippet directly from the rail popover
  copyPopoverSnippet: function(e, btn) {
    if (e) {
      e.preventDefault();
      e.stopPropagation();
    }
    const isLocal = (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1');
    const scriptUrl = isLocal 
      ? `${window.location.origin}/cuboidpilot/widget.js?v=7.5` 
      : 'https://cai.cuboidsoft.in/widget.js?v=7.5';
    const compKey = (this.company && this.company.company_key) ? this.company.company_key : 'cp_live_cuboidsoft';
    const snippet = `<script src="${scriptUrl}" data-company="${compKey}" data-theme="dark" async><\/script>`;

    this.copyToClipboard(snippet).then(() => {
      if (btn) {
        const textSpan = btn.querySelector('.popover-copy-text');
        if (textSpan) textSpan.textContent = 'Copied!';
        setTimeout(() => {
          if (textSpan) textSpan.textContent = 'Copy Tag';
        }, 2000);
      }
      this.toast('Embed snippet copied to clipboard!', 'success');
    }).catch(() => {
      this.toast('Snippet ready in box', 'info');
    });
  },

  // Universal clipboard copy helper (modern API + execCommand fallback)
  copyToClipboard: function(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    } else {
      const textArea = document.createElement("textarea");
      textArea.value = text;
      textArea.style.position = "fixed";
      textArea.style.left = "-999999px";
      textArea.style.top = "-999999px";
      document.body.appendChild(textArea);
      textArea.focus();
      textArea.select();
      return new Promise((res, rej) => {
        const ok = document.execCommand('copy');
        textArea.remove();
        ok ? res() : rej(new Error('Copy failed'));
      });
    }
  },

  // Backwards compatibility no-op for any legacy calls
  mountTestAiDrawer: function() {
    this.ensureBottomWidget();
  },

  // Mobile Off-Canvas Dual-Sidebar Toggle
  toggleMobileSidebar: function(forceState) {
    const sidebar = document.querySelector('.app-dual-sidebar') || document.getElementById('sidebar-container');
    let backdrop = document.getElementById('shell-mobile-backdrop');
    if (!backdrop) {
      backdrop = document.createElement('div');
      backdrop.id = 'shell-mobile-backdrop';
      backdrop.className = 'app-sidebar-backdrop';
      const dismiss = (e) => {
        if (e) {
          e.preventDefault();
          e.stopPropagation();
        }
        this.toggleMobileSidebar(false);
      };
      backdrop.onclick = dismiss;
      backdrop.ontouchend = dismiss;
      document.body.appendChild(backdrop);
    }
    const isCurrentlyOpen = document.body.classList.contains('sidebar-mobile-open') || 
                            (sidebar && sidebar.classList.contains('mobile-open'));
    const shouldOpen = (forceState !== undefined) ? forceState : !isCurrentlyOpen;

    if (shouldOpen) {
      document.body.classList.add('sidebar-mobile-open');
      document.querySelectorAll('.app-dual-sidebar').forEach(s => s.classList.add('mobile-open'));
      backdrop.classList.add('active');
    } else {
      document.body.classList.remove('sidebar-mobile-open');
      document.querySelectorAll('.app-dual-sidebar').forEach(s => s.classList.remove('mobile-open'));
      backdrop.classList.remove('active');
    }
  },

  // Bind Global App Shell Events
  bindGlobalEvents: function() {
    // 1. Mobile Menu Trigger (Hamburger)
    const handleToggle = (e) => {
      e.preventDefault();
      e.stopPropagation();
      this.toggleMobileSidebar();
    };

    const mobileMenuTrigger = document.getElementById('mobile-menu-trigger');
    if (mobileMenuTrigger) {
      mobileMenuTrigger.onclick = handleToggle;
      mobileMenuTrigger.ontouchend = handleToggle;
    }

    // Delegation backup for dynamically mounted topbars
    if (!this._hasBoundMenuDelegation) {
      this._hasBoundMenuDelegation = true;
      document.addEventListener('click', (e) => {
        const btn = e.target.closest('#mobile-menu-trigger');
        if (btn) {
          e.preventDefault();
          e.stopPropagation();
          this.toggleMobileSidebar();
        }
      });
    }

    // 2. Mobile Sidebar Close Buttons
    document.querySelectorAll('.shell-mobile-close').forEach(btn => {
      btn.onclick = (e) => {
        e.preventDefault();
        this.toggleMobileSidebar(false);
      };
    });

    // 3. Auto-close mobile drawer when any navigation link inside sidebar is tapped
    document.querySelectorAll('.app-dual-sidebar a').forEach(link => {
      link.addEventListener('click', () => {
        if (window.innerWidth < 1024) {
          this.toggleMobileSidebar(false);
        }
      });
    });

    // 4. Global Keyboard Escape to dismiss mobile drawer & modals
    if (!this._hasBoundEscape) {
      this._hasBoundEscape = true;
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
          this.toggleMobileSidebar(false);
          document.querySelectorAll('.app-modal-overlay.open').forEach(m => m.classList.remove('open'));
          const userMenu = document.getElementById('cp-user-profile-menu');
          if (userMenu) userMenu.remove();
        }
      });
    }

    const openBtn = document.getElementById('open-test-ai-btn');
    if (openBtn) {
      openBtn.onclick = (e) => {
        e.preventDefault();
        this.openBottomWidget();
      };
    }

    // Global Modal Helpers
    window.openModal = function(id) {
      const m = document.getElementById(id);
      if (m) {
        m.classList.add('open');
        if (window.lucide) window.lucide.createIcons();
      }
    };

    window.closeModal = function(id) {
      const m = document.getElementById(id);
      if (m) m.classList.remove('open');
    };

    // Modal open buttons
    document.querySelectorAll('[data-modal-target]').forEach(btn => {
      btn.onclick = (e) => {
        e.preventDefault();
        const targetId = btn.getAttribute('data-modal-target');
        const modal = document.getElementById(targetId);
        if (modal) modal.classList.add('open');
      };
    });

    // Modal close buttons
    document.querySelectorAll('[data-modal-close]').forEach(btn => {
      btn.onclick = (e) => {
        e.preventDefault();
        const modal = btn.closest('.app-modal-overlay');
        if (modal) modal.classList.remove('open');
      };
    });

    // Backdrop click close
    document.querySelectorAll('.app-modal-overlay').forEach(overlay => {
      overlay.onclick = (e) => {
        if (e.target === overlay) overlay.classList.remove('open');
      };
    });

    // Smooth horizontal mouse wheel scroll for tab bars with no-scrollbar
    document.querySelectorAll('.no-scrollbar').forEach(el => {
      el.addEventListener('wheel', (e) => {
        if (e.deltaY !== 0 && el.scrollWidth > el.clientWidth) {
          e.preventDefault();
          el.scrollLeft += e.deltaY;
        }
      }, { passive: false });
    });
  },

  // -------------------------------------------------------------
  // SPA INSTANT CLIENT-SIDE ROUTER (No Hard Page Reloads)
  // -------------------------------------------------------------
  _spaRouterInitialized: false,

  initSpaRouter: function() {
    // Disabled SPA interception to allow full native MPA page loads.
    // This ensures every page's DOMContentLoaded listeners, inline scripts, tabs (e.g. switchSettingsSection),
    // and event handlers initialize cleanly on every page transition without requiring manual page reload.
    return;
  },

  spaNavigate: async function(url, pushState = true) {
    try {
      this.showSpaLoader(true);
      const res = await fetch(url);
      if (!res.ok) {
        window.location.href = url;
        return;
      }
      const html = await res.text();
      const parser = new DOMParser();
      const doc = parser.parseFromString(html, 'text/html');

      // 1. Update Title
      if (doc.title) {
        document.title = doc.title;
      }

      // 2. Update Browser History
      if (pushState) {
        window.history.pushState({ url }, doc.title || '', url);
      }

      // 3. Extract target page name from pathname
      const urlObj = new URL(url, window.location.href);
      const filename = urlObj.pathname.split('/').pop() || 'overview.html';
      const pageName = filename.replace('.html', '') || 'overview';

      // 4. Swap Main Content (.app-main)
      const currentMain = document.querySelector('.app-main');
      const newMain = doc.querySelector('.app-main');
      if (currentMain && newMain) {
        currentMain.innerHTML = newMain.innerHTML;
        currentMain.className = newMain.className;
      }

      // 5. Swap Modals that may exist outside .app-main
      document.querySelectorAll('.app-modal-overlay').forEach(el => {
        if (!el.id.includes('cp-global-')) el.remove();
      });
      doc.querySelectorAll('.app-modal-overlay').forEach(m => {
        if (!document.getElementById(m.id)) {
          document.body.appendChild(m.cloneNode(true));
        }
      });

      // 6. Update Sidebar & Topbar for the new page
      this.renderCompanySidebar(pageName);
      this.syncRealWorkspaceDom(pageName);

      // 7. Scroll Content to Top
      const scroller = document.querySelector('.app-content-scroll');
      if (scroller) scroller.scrollTop = 0;

      // 8. Re-bind global events (modal close, backdrop clicks, etc.)
      this.bindGlobalEvents();

      // 9. Re-initialize Lucide Icons
      if (window.lucide) window.lucide.createIcons();

      // 10. Execute inline script initialization for the destination page
      if (pageName === 'settings' && typeof window.loadSettings === 'function') {
        window.loadSettings();
      } else if (window.CuboidDashboard && typeof window.CuboidDashboard.loadPageData === 'function') {
        window.CuboidDashboard.loadPageData(pageName);
      }

      // Execute any inline scripts in doc that aren't already global
      doc.querySelectorAll('script:not([src])').forEach(s => {
        try {
          const fn = new Function(s.textContent);
          fn();
        } catch(e) {}
      });

      this.showSpaLoader(false);
    } catch(err) {
      console.warn('[CuboidShell SPA Navigation Fallback]', err);
      window.location.href = url;
    }
  },

  // -------------------------------------------------------------
  // GOOGLE WORKSPACE STYLE SPLASH LOADING SCREEN
  // Pure white, centered pulsing logo, indeterminate progress bar, and workspace footer
  // -------------------------------------------------------------
  renderSplashScreen: function() {
    let splash = document.getElementById('cp-app-splash');
    if (!splash) {
      splash = document.createElement('div');
      splash.id = 'cp-app-splash';

      const logoSrc = this.resolveAssetUrl(this.company ? (this.company.logo_light_url || this.company.logo_url || this.company.logo_dark_url) : null, '../assets/logo-black.png');
      const companyName = (this.company && this.company.name) ? this.escapeHtml(this.company.name) : 'CuboidPilot';

      splash.innerHTML = `
        <div class="cp-splash-center">
          <div class="cp-splash-logo-wrap">
            <img src="${logoSrc}" id="cp-splash-logo" alt="Logo" class="cp-splash-logo-img" onerror="this.onerror=null; this.src='../assets/logo-black.png';">
          </div>
          <div class="cp-splash-brand-title">
            <span id="cp-splash-brand-name">${companyName}</span> <span>Workspace</span>
          </div>
          <div class="cp-splash-bar-wrap">
            <div class="cp-splash-bar-indicator"></div>
          </div>
        </div>
        <div class="cp-splash-footer">
          If you're having trouble loading, visit the <a href="https://cuboidsoft.in" target="_blank">Help Center</a>.
        </div>
      `;

      document.body.appendChild(splash);
    }
  },

  dismissSplashScreen: function(delayMs = 0) {
    const splash = document.getElementById('cp-app-splash');
    if (!splash) return;
    if (delayMs <= 0) {
      splash.classList.add('splash-hidden');
      if (splash.parentNode) splash.remove();
      return;
    }
    setTimeout(() => {
      splash.classList.add('splash-hidden');
      setTimeout(() => {
        if (splash.parentNode) splash.remove();
      }, 150);
    }, delayMs);
  },

  showSpaLoader: function(show) {
    let loader = document.getElementById('cp-spa-loader');
    if (show) {
      if (!loader) {
        loader = document.createElement('div');
        loader.id = 'cp-spa-loader';
        loader.style.cssText = 'position:fixed;top:0;left:0;height:2.5px;background:#111111;z-index:9999999;transition:width 0.2s cubic-bezier(0.16,1,0.3,1),opacity 0.2s ease;width:0%;pointer-events:none;box-shadow:0 0 8px rgba(0,0,0,0.3);';
        document.body.appendChild(loader);
      }
      loader.style.opacity = '1';
      loader.style.width = '35%';
      setTimeout(() => { if (loader) loader.style.width = '75%'; }, 80);
    } else {
      if (loader) {
        loader.style.width = '100%';
        setTimeout(() => {
          loader.style.opacity = '0';
          setTimeout(() => { loader.style.width = '0%'; }, 200);
        }, 120);
      }
    }
  },

  // -------------------------------------------------------------
  // REUSABLE INTERCOM UI: TOASTS & CONFIRMATION DIALOGS
  // -------------------------------------------------------------
  toast: function(message, type = 'success', duration = 3500) {
    let container = document.getElementById('cp-toast-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'cp-toast-container';
      container.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);z-index:99999999;display:flex;flex-direction:column;gap:8px;pointer-events:none;align-items:center;';
      document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = 'cp-intercom-toast';
    toast.style.cssText = 'pointer-events:auto;background:#111111;color:#ffffff;border:1px solid #292929;border-radius:8px;padding:9px 16px;font-size:12.5px;font-weight:500;box-shadow:0 10px 25px -5px rgba(0,0,0,0.35),0 0 0 1px rgba(255,255,255,0.06);display:flex;align-items:center;gap:10px;transform:translateY(16px) scale(0.96);opacity:0;transition:all 0.22s cubic-bezier(0.16,1,0.3,1);';

    let iconHtml = '';
    if (type === 'success') {
      iconHtml = '<span style="color:#10b981;display:flex;align-items:center;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg></span>';
    } else if (type === 'error') {
      iconHtml = '<span style="color:#f43f5e;display:flex;align-items:center;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></span>';
    } else {
      iconHtml = '<span style="color:#60a5fa;display:flex;align-items:center;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="8"/></svg></span>';
    }

    toast.innerHTML = `${iconHtml}<span>${message}</span>`;
    container.appendChild(toast);

    requestAnimationFrame(() => {
      toast.style.transform = 'translateY(0) scale(1)';
      toast.style.opacity = '1';
    });

    setTimeout(() => {
      toast.style.transform = 'translateY(8px) scale(0.96)';
      toast.style.opacity = '0';
      setTimeout(() => { toast.remove(); }, 250);
    }, duration);
  },

  confirm: function(options = {}) {
    return new Promise((resolve) => {
      const existing = document.getElementById('cp-global-confirm-dialog');
      if (existing) existing.remove();

      const overlay = document.createElement('div');
      overlay.id = 'cp-global-confirm-dialog';
      overlay.className = 'app-modal-overlay open';
      overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.45);backdrop-filter:blur(3px);z-index:999999999;display:flex;align-items:center;justify-content:center;padding:16px;';

      const title = options.title || 'Are you sure?';
      const message = options.message || 'This action cannot be undone.';
      const confirmText = options.confirmText || (options.isDestructive ? 'Delete' : 'Confirm');
      const cancelText = options.cancelText || 'Cancel';
      const isDestructive = !!options.isDestructive;

      const confirmBtnClass = isDestructive
        ? 'bg-rose-600 hover:bg-rose-700 text-white font-medium py-1.5 px-3.5 rounded-md text-xs transition-colors'
        : 'btn-primary btn-sm text-xs py-1.5 px-3.5';

      overlay.innerHTML = `
        <div class="bg-white border border-[#e7e5de] rounded-xl shadow-2xl max-w-sm w-full p-5 space-y-4">
          <div class="flex items-start justify-between gap-3">
            <div class="space-y-1">
              <h3 class="text-sm font-semibold text-stone-900">${title}</h3>
              <p class="text-xs text-stone-500 leading-relaxed">${message}</p>
            </div>
            <button id="cp-confirm-close-btn" class="text-stone-400 hover:text-stone-700 p-1 rounded">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
          <div class="flex items-center justify-end gap-2 pt-2 border-t border-[#f0eee8]">
            <button id="cp-confirm-cancel-btn" class="btn-secondary btn-sm text-xs py-1.5 px-3">${cancelText}</button>
            <button id="cp-confirm-ok-btn" class="${confirmBtnClass}">${confirmText}</button>
          </div>
        </div>
      `;

      document.body.appendChild(overlay);

      const close = (result) => {
        overlay.style.opacity = '0';
        setTimeout(() => overlay.remove(), 160);
        resolve(result);
        if (result && typeof options.onConfirm === 'function') {
          options.onConfirm();
        }
      };

      overlay.querySelector('#cp-confirm-ok-btn').addEventListener('click', () => close(true));
      overlay.querySelector('#cp-confirm-cancel-btn').addEventListener('click', () => close(false));
      overlay.querySelector('#cp-confirm-close-btn').addEventListener('click', () => close(false));
      overlay.addEventListener('click', (e) => {
        if (e.target === overlay) close(false);
      });
    });
  },

  // Toggle Intercom-style User Profile popover menu
  toggleUserMenu: function(e) {
    if (e) e.stopPropagation();
    const existing = document.getElementById('cp-user-profile-menu');
    if (existing) {
      existing.remove();
      return;
    }
    const name = (this.user && this.user.name) ? this.user.name : 'Ayush';
    const email = (this.user && this.user.email) ? this.user.email : 'founder@cuboidsoft.in';
    const company = (this.company && this.company.name) ? this.company.name : 'CuboidSoft';
    const isPro = this.entitlements && this.entitlements.is_premium;
    const tierBadge = isPro 
      ? '<span class="text-[10px] bg-emerald-100 text-emerald-800 font-mono font-semibold px-1.5 py-0.5 rounded">PRO</span>' 
      : '<span class="text-[10px] bg-amber-100 text-amber-800 font-mono font-semibold px-1.5 py-0.5 rounded">TRIAL</span>';

    const avatarUrl = (this.user && this.user.avatar_url) ? this.resolveAssetUrl(this.user.avatar_url) : null;
    const parts = name.split(' ');
    const inits = (((parts[0] ? parts[0][0] : '') + (parts[1] ? parts[1][0] : '')).toUpperCase()) || 'U';

    const avatarHtml = avatarUrl 
      ? `<img src="${avatarUrl}" alt="${this.escapeHtml(name)}" class="w-10 h-10 rounded-full object-cover border border-stone-200 shadow-2xs shrink-0">`
      : `<div class="w-10 h-10 rounded-full bg-stone-900 text-white flex items-center justify-center font-semibold text-xs border border-white shadow-2xs shrink-0">${inits}</div>`;

    // Detect if invoked from topbar or sidebar rail
    const trigger = e ? e.currentTarget : null;
    const isTopbar = trigger && (trigger.id === 'topbar-user-avatar' || trigger.closest('.app-topbar'));
    const posClass = isTopbar ? 'fixed right-4 top-14 w-68' : 'fixed left-16 bottom-4 w-68';

    const menu = document.createElement('div');
    menu.id = 'cp-user-profile-menu';
    menu.className = `${posClass} max-w-[calc(100vw-32px)] bg-white border border-[#e7e5de] shadow-xl rounded-lg p-3.5 z-50 animate-in fade-in duration-100`;
    menu.innerHTML = `
      <div class="flex items-center gap-3 pb-3 mb-2.5 border-b border-[#e7e5de]">
        ${avatarHtml}
        <div class="min-w-0 flex-1">
          <div class="flex items-center justify-between gap-1.5">
            <div class="font-semibold text-xs text-stone-900 truncate">${this.escapeHtml(name)}</div>
            ${tierBadge}
          </div>
          <div class="text-[11px] text-stone-500 truncate">${this.escapeHtml(email)}</div>
          <div class="text-[10.5px] text-stone-400 font-mono mt-0.5 truncate">${this.escapeHtml(company)}</div>
        </div>
      </div>
      <div class="space-y-1 text-xs text-stone-700">
        <a href="channels.html" class="flex items-center gap-2 p-1.5 hover:bg-stone-100 rounded transition-colors no-underline text-stone-700">
          <i data-lucide="layers" class="w-3.5 h-3.5 text-stone-400"></i> Channels &amp; Integrations Hub
        </a>
        <a href="team.html" class="flex items-center gap-2 p-1.5 hover:bg-stone-100 rounded transition-colors no-underline text-stone-700">
          <i data-lucide="camera" class="w-3.5 h-3.5 text-stone-400"></i> Change Profile Photo
        </a>
        <a href="settings.html" class="flex items-center gap-2 p-1.5 hover:bg-stone-100 rounded transition-colors no-underline text-stone-700">
          <i data-lucide="settings" class="w-3.5 h-3.5 text-stone-400"></i> Settings &amp; Branding
        </a>
        <a href="team.html" class="flex items-center gap-2 p-1.5 hover:bg-stone-100 rounded transition-colors no-underline text-stone-700">
          <i data-lucide="users" class="w-3.5 h-3.5 text-stone-400"></i> Team Members
        </a>
        <a href="billing.html" class="flex items-center gap-2 p-1.5 hover:bg-stone-100 rounded transition-colors no-underline text-stone-700">
          <i data-lucide="credit-card" class="w-3.5 h-3.5 text-stone-400"></i> Subscription &amp; Invoices
        </a>
      </div>
      <div class="pt-2 mt-2 border-t border-[#e7e5de]">
        <a href="../logout.php" class="flex items-center gap-2 p-1.5 text-rose-600 hover:bg-rose-50 rounded transition-colors font-medium text-xs no-underline">
          <i data-lucide="log-out" class="w-3.5 h-3.5"></i> Sign Out
        </a>
      </div>
    `;
    document.body.appendChild(menu);
    if (window.lucide) window.lucide.createIcons();

    setTimeout(() => {
      const dismiss = (ev) => {
        if (!menu.contains(ev.target)) {
          menu.remove();
          document.removeEventListener('click', dismiss);
        }
      };
      document.addEventListener('click', dismiss);
    }, 10);
  },

  toggleChannelsNav: function(btn) {
    const sub = document.getElementById('shell-channels-submenu');
    const chev = document.getElementById('shell-channels-chevron');
    if (!sub) return;
    const isHidden = sub.classList.toggle('hidden');
    if (chev) {
      chev.classList.toggle('rotate-180', !isHidden);
    }
    if (window.lucide) window.lucide.createIcons();
    try {
      localStorage.setItem('cp_channels_expanded', isHidden ? '0' : '1');
    } catch(e) {}
  },

  openOutcomeModal: function() {
    let modal = document.getElementById('cp-outcome-modal');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'cp-outcome-modal';
      modal.className = 'fixed inset-0 z-[99999] flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs transition-opacity duration-200';
      modal.setAttribute('role', 'dialog');
      modal.setAttribute('aria-modal', 'true');
      modal.innerHTML = `
        <div class="bg-white border border-[#dcdad0] rounded-[6px] shadow-2xl max-w-md w-full p-6 sm:p-7 relative text-left text-[#111111] animate-in fade-in zoom-in-95 duration-150">
          <button type="button" id="cp-close-outcome-modal" class="absolute top-4 right-4 p-1.5 rounded-[4px] text-stone-400 hover:text-stone-700 hover:bg-stone-100 transition-colors cursor-pointer" aria-label="Close dialog">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>

          <div class="flex items-center gap-2 mb-1.5">
            <span class="w-6 h-6 rounded-full bg-emerald-50 text-emerald-700 flex items-center justify-center shrink-0">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </span>
            <h3 class="text-base sm:text-lg font-semibold text-[#111111] tracking-tight">
              How Cai Outcome Pricing Works
            </h3>
          </div>
          <p class="text-xs text-stone-500 mb-4 leading-relaxed">
            Pay only when Cai delivers measurable customer resolution. No charges for simple greetings or human escalations.
          </p>

          <div class="p-3.5 bg-[#fcfbfa] border border-[#e7e5de] rounded-[4px] mb-4">
            <div class="text-[11px] font-mono uppercase text-stone-500 mb-0.5">Current Outcome Rate</div>
            <div class="text-2xl font-bold font-mono text-[#111111] flex items-baseline gap-1.5">
              <span class="text-emerald-800">₹1</span>
              <span class="text-xs font-normal text-stone-500 font-sans">per successful resolution</span>
            </div>
          </div>

          <div class="space-y-3.5 text-xs text-stone-700">
            <div>
              <div class="font-semibold text-stone-900 mb-1.5 flex items-center gap-1.5">
                <span class="w-4 h-4 rounded-full bg-emerald-100 text-emerald-800 flex items-center justify-center text-[10px] font-bold">✓</span>
                <span>What counts as an outcome?</span>
              </div>
              <ul class="space-y-1 text-stone-600 pl-5 list-disc list-outside">
                <li>Cai resolves a customer query from verified documentation without human agent intervention</li>
                <li>Cai captures a qualified lead with complete contact details (name, email/phone, intent)</li>
                <li>Cai schedules a confirmed team demo, appointment, or consultation</li>
              </ul>
            </div>

            <div class="pt-3 border-t border-[#f0eee9]">
              <div class="font-semibold text-stone-900 mb-1.5 flex items-center gap-1.5">
                <span class="w-4 h-4 rounded-full bg-stone-100 text-stone-500 flex items-center justify-center text-[10px] font-bold">✕</span>
                <span>What is always 100% Free (₹0)?</span>
              </div>
              <ul class="space-y-1 text-stone-600 pl-5 list-disc list-outside">
                <li>Greetings, pleasantries, and basic navigation queries</li>
                <li>Clarifying questions asked before arriving at a resolution</li>
                <li>Any conversation escalated to or answered by a human specialist</li>
                <li>Spam, test pings, or unresolvable inquiries</li>
              </ul>
            </div>

            <div class="pt-3 border-t border-[#f0eee9] text-[11.5px] text-stone-600 leading-relaxed bg-[#fbfaf6] p-3 rounded-[4px] border border-[#eceae4]">
              <strong class="text-stone-900">Budget Guardrails:</strong> Set custom monthly outcome limits directly in your dashboard (e.g. ₹500 or ₹2,000) so your bill never exceeds your planned budget.
            </div>
          </div>

          <div class="mt-5">
            <button type="button" id="cp-confirm-outcome-modal" class="w-full py-2.5 px-4 rounded-[3px] bg-[#111111] hover:bg-black text-white text-xs font-semibold text-center transition-colors shadow-2xs cursor-pointer">
              Got it, thanks
            </button>
          </div>
        </div>
      `;
      document.body.appendChild(modal);

      const closeModal = () => {
        modal.classList.add('hidden');
        document.body.style.overflow = '';
      };

      modal.querySelector('#cp-close-outcome-modal').addEventListener('click', closeModal);
      modal.querySelector('#cp-confirm-outcome-modal').addEventListener('click', closeModal);
      modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
      });
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeModal();
      });
    }

    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
  }
};

// Global click delegation for outcome question marks
document.addEventListener('click', function(e) {
  const trigger = e.target.closest('.cp-outcome-info-trigger, [data-action="outcome-info"], .cp-outcome-btn');
  if (trigger) {
    e.preventDefault();
    e.stopPropagation();
    if (window.CP_Pricing && typeof window.CP_Pricing.openOutcomeModal === 'function') {
      window.CP_Pricing.openOutcomeModal();
    } else if (window.CuboidShell && typeof window.CuboidShell.openOutcomeModal === 'function') {
      window.CuboidShell.openOutcomeModal();
    }
    return;
  }

  const helpIcon = e.target.closest('[data-lucide="help-circle"], svg.lucide-help-circle, i[data-lucide="help-circle"]');
  if (helpIcon) {
    const parentBlock = helpIcon.closest('.cp-cai-outcome-rate, .cp-hero-outcome-price, [title*="outcome"], [title*="Outcome"]') || helpIcon.parentElement;
    const text = (parentBlock ? parentBlock.textContent : '').toLowerCase();
    const title = (helpIcon.getAttribute('title') || '').toLowerCase();
    if (text.includes('outcome') || title.includes('outcome')) {
      e.preventDefault();
      e.stopPropagation();
      if (window.CP_Pricing && typeof window.CP_Pricing.openOutcomeModal === 'function') {
        window.CP_Pricing.openOutcomeModal();
      } else if (window.CuboidShell && typeof window.CuboidShell.openOutcomeModal === 'function') {
        window.CuboidShell.openOutcomeModal();
      }
    }
  }
});


