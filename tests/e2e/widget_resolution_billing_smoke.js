#!/usr/bin/env node
/*
 * Production smoke for tenant-safe widget embeds and AI resolution billing.
 *
 * Optional browser check: npm install playwright, then run with
 *   CAI_RUN_BROWSER=1 node tests/e2e/widget_resolution_billing_smoke.js
 */

const baseUrl = (process.env.CAI_BASE_URL || 'https://cai.cuboidsoft.in').replace(/\/$/, '');
const companyKey = process.env.CAI_COMPANY_KEY || 'cp_live_cuboidsoft';

async function readJson(url, options = {}) {
  const res = await fetch(url, options);
  const text = await res.text();
  let json = null;
  try { json = JSON.parse(text); } catch (e) {}
  return { res, text, json };
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

async function runHttpSmoke() {
  const missingConfig = await readJson(`${baseUrl}/api/config.php`);
  assert(missingConfig.res.status === 404, 'Missing company key must return 404.');
  assert(/Workspace not found/i.test(missingConfig.text), 'Missing company key must not fall back to a tenant.');

  const invalidToken = await readJson(`${baseUrl}/api/config.php?company_key=${encodeURIComponent(companyKey)}&widget_token=invalid-token`);
  assert(invalidToken.res.status === 403, 'Invalid widget token must return 403.');

  const goodConfig = await readJson(`${baseUrl}/api/config.php?company_key=${encodeURIComponent(companyKey)}`);
  assert(goodConfig.res.status === 200 && goodConfig.json && goodConfig.json.success, 'Explicit company key config must load.');

  const markResolution = await readJson(`${baseUrl}/api/ai_usage.php?action=mark_resolution`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Company-Key': companyKey },
    body: JSON.stringify({ company_key: companyKey, conversation_id: 0, session_id: '', resolved: true })
  });
  assert(markResolution.res.status === 400, 'Resolution billing must require a verified conversation and session.');

  const widgetRes = await fetch(`${baseUrl}/widget.js?v=smoke-${Date.now()}`);
  const widgetJs = await widgetRes.text();
  assert(widgetRes.ok, 'Widget script must be reachable.');
  assert(widgetJs.includes('Cai widget not configured'), 'Widget must render missing tenant configuration guard.');
  assert(widgetJs.includes('X-Widget-Token'), 'Widget must send install token header when present.');
  assert(widgetJs.includes('Did I resolve your query?'), 'Widget must include resolution confirmation prompt.');
}

async function runBrowserSmoke() {
  if (process.env.CAI_RUN_BROWSER !== '1') {
    return 'browser skipped';
  }
  let chromium;
  try {
    ({ chromium } = require('playwright'));
  } catch (e) {
    return 'browser skipped: playwright is not installed';
  }

  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  await page.setContent(`<!doctype html><html><body><script src="${baseUrl}/widget.js?v=browser-smoke"></script></body></html>`);
  await page.waitForSelector('#cuboidpilot-widget-config-error', { timeout: 10000 });
  const errorText = await page.textContent('#cuboidpilot-widget-config-error');
  await browser.close();
  assert(/Cai widget not configured/i.test(errorText || ''), 'Browser widget guard must be visible.');
  return 'browser passed';
}

(async () => {
  await runHttpSmoke();
  const browserStatus = await runBrowserSmoke();
  console.log(JSON.stringify({
    success: true,
    base_url: baseUrl,
    company_key: companyKey,
    browser: browserStatus
  }, null, 2));
})().catch((error) => {
  console.error(error && error.stack ? error.stack : error);
  process.exit(1);
});
