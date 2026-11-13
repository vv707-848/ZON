// The website as a search engine and a tablet see it: the check in docs/website.md «الفحص الآلي للموقع».
//
// The pages: one of each kind the sitemap lists (the fixed pages, a filtered feed, a listing, a project, a unit, an
// advertiser, a policy), and the sign-in and complaint-tracking pages, which are closed to search engines on purpose.
// Lighthouse opens each as a phone and as a desktop, then the page is opened at four tablet sizes. A page fails when:
//   - it is in the sitemap and its SEO score is under 100;
//   - its accessibility score is under 100;
//   - a best-practices check fails, except the two about HTTPS: this server speaks plain http, production redirects
//     to https and sends HSTS (deploy/nginx/zon.conf);
//   - its performance score is under 90 (Lighthouse's simulated slow 4G on a mid-range phone, and a desktop; the
//     median of three runs when the first is under);
//   - at a tablet size it scrolls sideways or logs an error in the console.
//
//   cd _tools/site-audit && npm ci
//   node audit.mjs http://127.0.0.1:8099           # the website served as in docs/website.md «المعاينة محلياً»
//   CHROME_PATH=/path/to/chrome node audit.mjs …    # a Chrome other than the installed one

import { appendFileSync } from 'node:fs';
import * as chromeLauncher from 'chrome-launcher';
import lighthouse from 'lighthouse';
import desktopConfig from 'lighthouse/core/config/desktop-config.js';
import puppeteer from 'puppeteer-core';

const base = (process.argv[2] ?? '').replace(/\/$/, '');
if (!/^https?:\/\/[^/]+$/.test(base)) {
  console.error('usage: node audit.mjs <the website base, e.g. http://127.0.0.1:8099>');
  process.exit(2);
}

const KINDS = [
  ['home', /^\/$/], ['feed', /^\/listings$/], ['feed by city', /^\/listings\?/], ['listing', /^\/listings\/\d+$/],
  ['project', /^\/projects\/\d+$/], ['unit', /^\/projects\/\d+\/units\/\d+$/], ['advertiser', /^\/users\/[^/]+$/],
  ['plans', /^\/packages$/], ['complaints', /^\/complaints$/], ['account deletion', /^\/account\/delete$/], ['policy', /^\/legal\/[^/]+$/],
];
const REQUIRED = ['home', 'feed', 'listing', 'project', 'unit', 'advertiser'];   // the development seed has them all
const CLOSED = [['sign-in', '/login'], ['complaint tracking', '/complaints/track']];
const CATEGORIES = ['performance', 'accessibility', 'best-practices', 'seo'];
const HTTPS_ONLY = new Set(['is-on-https', 'redirects-http']);
const TABLETS = [['iPad mini', 768, 1024], ['iPad Air', 820, 1180], ['iPad Air landscape', 1180, 820], ['Galaxy Tab', 640, 1024]];

const failures = [];
let httpsSkipped = false;   // a best-practices score under 100 because the base is plain http on a host that is not local

async function fetchText(url) {
  const response = await fetch(url, { redirect: 'manual' });
  if (response.status !== 200) throw new Error(`${url}: HTTP ${response.status}`);
  return response.text();
}
const locs = (xml) => [...xml.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1].replaceAll('&amp;', '&'));

// The sitemap's addresses carry the website's own base (config zon.deep_link_base): it must be the one given here.
const own = (url) => {
  if (url.startsWith(base + '/')) return url;
  console.error(`the sitemap lists ${url}: the website's base is not ${base}`);
  process.exit(2);
};
const listed = [];
for (const part of locs(await fetchText(`${base}/sitemap.xml`)).map(own)) listed.push(...locs(await fetchText(part)).map(own));
const paths = listed.map((url) => url.slice(base.length));

const pages = [];
for (const [kind, pattern] of KINDS) {
  const path = paths.find((p) => pattern.test(p));
  if (path) pages.push({ kind, path, indexed: true, scores: {} });
  else if (REQUIRED.includes(kind)) failures.push(`the sitemap has no ${kind} page`);
}
for (const [kind, path] of CLOSED) pages.push({ kind, path, indexed: false, scores: {} });

// Chrome on a CI runner sometimes never opens its debugging port ("waiting for dynamic debugging port"): it is
// launched again, three times at most, before the audit fails on it.
async function launchChrome(tries = 3) {
  for (let i = 1; ; i++) {
    try {
      return await chromeLauncher.launch({ chromeFlags: ['--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'] });
    } catch (e) {
      if (i >= tries) throw e;
      console.error(`Chrome did not start (${e.message}); again (${i + 1}/${tries})`);
    }
  }
}
const chrome = await launchChrome();
try {
  const flags = { port: chrome.port, output: 'json', logLevel: 'error', onlyCategories: CATEGORIES };
  const run = async (path, config) => (await lighthouse(base + path, flags, config)).lhr;
  const failing = (lhr, id) => lhr.categories[id].auditRefs
    .filter((ref) => ref.weight > 0 && lhr.audits[ref.id].score !== null && lhr.audits[ref.id].score < 1)
    .map((ref) => `${ref.id} — ${lhr.audits[ref.id].title}`);
  await run('/', undefined);   // a first run on a cold browser blocks the main thread longer: not counted
  for (const page of pages) {
    for (const [form, config] of [['phone', undefined], ['desktop', desktopConfig]]) {
      const where = `${page.path} (${form})`;
      const lhr = await run(page.path, config);
      if (lhr.runtimeError) {
        failures.push(`${where}: ${lhr.runtimeError.message}`);
        continue;
      }
      // Performance varies from run to run on a shared machine: under the bar, the median of three decides it
      // (as Lighthouse CI does). The other categories read the page itself and do not vary.
      let speed = lhr;
      if (lhr.categories.performance.score < 0.9) {
        const runs = [lhr, await run(page.path, config), await run(page.path, config)].filter((r) => !r.runtimeError);
        speed = runs.sort((a, b) => a.categories.performance.score - b.categories.performance.score)[Math.floor(runs.length / 2)];
      }
      page.scores[form] = CATEGORIES.map((id) => Math.round((id === 'performance' ? speed : lhr).categories[id].score * 100));
      if (page.indexed && lhr.categories.seo.score < 1) failures.push(`${where} SEO: ${failing(lhr, 'seo').join('; ')}`);
      if (lhr.categories.accessibility.score < 1) failures.push(`${where} accessibility: ${failing(lhr, 'accessibility').join('; ')}`);
      const practices = failing(lhr, 'best-practices').filter((audit) => !HTTPS_ONLY.has(audit.split(' — ')[0]));
      httpsSkipped ||= practices.length < failing(lhr, 'best-practices').length;
      if (practices.length) failures.push(`${where} best practices: ${practices.join('; ')}`);
      if (speed.categories.performance.score < 0.9) failures.push(`${where} performance ${page.scores[form][0]} (median of three): ${failing(speed, 'performance').join('; ')}`);
    }
  }

  const browser = await puppeteer.connect({ browserURL: `http://127.0.0.1:${chrome.port}` });
  for (const page of pages) {
    page.tablets = [];
    for (const [device, width, height] of TABLETS) {
      const tab = await browser.newPage();
      const errors = [];
      tab.on('console', (message) => message.type() === 'error' && errors.push(message.text()));
      tab.on('pageerror', (error) => errors.push(String(error)));
      await tab.setViewport({ width, height, isMobile: true, hasTouch: true });
      const response = await tab.goto(base + page.path, { waitUntil: 'networkidle0' });
      const sideways = await tab.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
      const where = `${page.path} on ${device} ${width}×${height}`;
      if (response?.status() !== 200) failures.push(`${where}: HTTP ${response?.status()}`);
      if (sideways > 0) failures.push(`${where}: scrolls sideways by ${sideways}px`);
      for (const error of errors) failures.push(`${where}: console error «${error}»`);
      page.tablets.push(sideways > 0 || errors.length ? '✗' : '✓');
      await tab.close();
    }
  }
  await browser.disconnect();
} finally {
  chrome.kill();
}

// The table, in the log and in the run's summary on GitHub.
const cell = (scores) => (scores ? scores.join(' / ') : '—');
const rows = pages.map((p) => [`${p.kind}${p.indexed ? '' : ' (closed to search)'}`, p.path, cell(p.scores.phone), cell(p.scores.desktop), p.tablets?.join(' ') ?? '—']);
const head = ['page', 'path', 'phone: perf / a11y / practices / SEO', 'desktop', TABLETS.map(([d]) => d).join(' · ')];
console.log([head, ...rows].map((row) => row.join(' | ')).join('\n'));
if (httpsSkipped) console.log('practices: the two HTTPS checks fail on this plain-http host and are not counted; everything else must pass.');
if (process.env.GITHUB_STEP_SUMMARY) {
  const md = [head, head.map(() => '---'), ...rows].map((row) => `| ${row.join(' | ')} |`).join('\n');
  appendFileSync(process.env.GITHUB_STEP_SUMMARY, `### The website (Lighthouse and tablets)\n\n${md}\n\n${failures.length ? failures.map((f) => `- ${f}`).join('\n') : 'Every page passes.'}\n`);
}
if (failures.length) {
  console.error(`\n${failures.length} failure(s):\n` + failures.map((f) => `  - ${f}`).join('\n'));
  process.exit(1);
}
console.log('\nEvery page passes.');
