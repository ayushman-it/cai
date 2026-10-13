(function () {
  var config = window.CP_SEO_CONFIG || {};
  var metaId = document.querySelector('meta[name="google-analytics-id"]');
  var measurementId = (config.gaMeasurementId || (metaId && metaId.content) || '').trim();

  if (!/^(G|AW|DC)-[A-Z0-9-]+$/i.test(measurementId)) {
    window.CPAnalytics = {
      enabled: false,
      track: function () {}
    };
    return;
  }

  window.dataLayer = window.dataLayer || [];
  window.gtag = window.gtag || function () {
    window.dataLayer.push(arguments);
  };

  var script = document.createElement('script');
  script.async = true;
  script.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(measurementId);
  document.head.appendChild(script);

  window.gtag('js', new Date());
  window.gtag('config', measurementId, {
    send_page_view: true,
    anonymize_ip: true
  });

  window.CPAnalytics = {
    enabled: true,
    track: function (eventName, params) {
      if (!eventName || typeof window.gtag !== 'function') {
        return;
      }
      window.gtag('event', eventName, params || {});
    }
  };

  document.addEventListener('click', function (event) {
    var link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
    if (!link || !window.CPAnalytics.enabled) {
      return;
    }

    var href = link.getAttribute('href') || '';
    var normalizedHref = href.toLowerCase();

    if (normalizedHref.indexOf('signup') !== -1 || normalizedHref.indexOf('pricing') !== -1) {
      window.CPAnalytics.track('select_promotion', {
        link_url: link.href,
        link_text: (link.textContent || '').trim().slice(0, 80)
      });
    }

    if (normalizedHref.indexOf('mailto:') === 0) {
      window.CPAnalytics.track('generate_lead', {
        method: 'email',
        link_url: href
      });
    }
  }, { passive: true });
})();
