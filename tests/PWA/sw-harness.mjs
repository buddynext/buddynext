// Runs the REAL generated service worker against an in-memory Cache API and a
// scripted network, replaying the "stale file after a plugin update" scenario.
// Usage: node sw-harness.mjs <sw.js> <site origin>. Exit 1 on any failed check.
// Driven by tests/PWA/ServiceWorkerAssetCachingTest.php.
import fs from 'node:fs';
const src = fs.readFileSync(process.argv[2], 'utf8');
const ORIGIN = process.argv[3] || 'https://example.org';
let net = {};            // url -> body (current server state); missing = offline
const stores = new Map(); // cache name -> Map(url -> Response)
const store = (n) => { if (!stores.has(n)) stores.set(n, new Map()); return stores.get(n); };
const keyOf = (r, ignoreSearch) => { const u = new URL(typeof r === 'string' ? new URL(r, ORIGIN) : r.url); return ignoreSearch ? u.origin + u.pathname : u.href; };
const mkCache = (name) => ({
  async put(req, res) { store(name).set(keyOf(req), res.clone()); },
  async match(req, o = {}) { for (const [k, v] of store(name)) if (keyOf(k, o.ignoreSearch) === keyOf(req, o.ignoreSearch)) return v.clone(); return undefined; },
  async keys() { return [...store(name).keys()].map((k) => new Request(k)); },
  async delete(req) { return store(name).delete(keyOf(req)); },
});
globalThis.caches = {
  open: async (n) => mkCache(n),
  keys: async () => [...stores.keys()],
  delete: async (n) => stores.delete(n),
  match: async (req, o) => { for (const n of stores.keys()) { const r = await mkCache(n).match(req, o); if (r) return r; } },
};
globalThis.fetch = async (req) => { const u = keyOf(typeof req === 'string' ? req : req); if (!(u in net)) throw new TypeError('offline'); return new Response(net[u], { status: 200, headers: { 'content-type': 'text/css' } }); };
const listeners = {};
globalThis.self = { location: new URL(ORIGIN + '/wp-json/buddynext/v1/pwa/sw'), addEventListener: (t, f) => (listeners[t] = f), skipWaiting() {}, clients: { claim() {} } };
globalThis.importScripts = () => {};
new Function(src)();
async function go(path) {
  let resp, waits = [];
  const req = new Request(ORIGIN + path); Object.defineProperty(req, 'mode', { value: 'no-cors' });
  listeners.fetch({ request: req, respondWith: (p) => (resp = p), waitUntil: (p) => waits.push(p) });
  if (!resp) return '(not intercepted: browser default)';
  const r = await resp; await Promise.all(waits); return r.type === 'error' ? 'Response.error' : await r.text();
}
// The worker receives decoded paths and encodes them itself (browserPath); the
// request below goes through new Request(), which encodes like a browser does.
const ownList = src.match(/const OWN_ASSET_PATHS = (\[.*?\])/);
const ownBase = (ownList ? JSON.parse(ownList[1])[0] : null) || '/wp-content/plugins/buddynext/';
// Browser-encoded, as a real request URL would be, so the scripted network keys match.
const OWN = new URL(ownBase + 'assets/css/qa-pwa.css', ORIGIN).pathname, OTHER = '/wp-content/uploads/qa/other.css';
const out = [];
const check = (label, got, want) => { const ok = got === want; out.push(`${ok ? 'PASS' : 'FAIL'}  ${label}: got ${JSON.stringify(got)}${ok ? '' : ' want ' + JSON.stringify(want)}`); };
// install: precache the shell (bare URLs) the worker lists
const shell = JSON.parse(src.match(/const SHELL_ASSETS = (\[.*?\]);/)[1]);
for (const a of shell) net[keyOf(a)] = 'shell:' + a;
let w = []; listeners.install({ waitUntil: (p) => w.push(p) }); await Promise.all(w);
w = []; listeners.activate({ waitUntil: (p) => w.push(p) }); await Promise.all(w);
// 1. plugin ships v1
net[ORIGIN + OWN + '?ver=1'] = 'own v1'; net[ORIGIN + OTHER + '?ver=1'] = 'other v1';
check('own ?ver=1 first view', await go(OWN + '?ver=1'), 'own v1');
check('other-plugin file not intercepted', await go(OTHER + '?ver=1'), '(not intercepted: browser default)');
// 2. update: file v2, enqueue bumps ?ver=2 (the ticket)
net = { ...net, [ORIGIN + OWN + '?ver=2']: 'own v2' }; delete net[ORIGIN + OWN + '?ver=1'];
check('FIRST view after update serves NEW file (was stale before fix)', await go(OWN + '?ver=2'), 'own v2');
check('repeat view, exact hit from cache', (net = { ...net, [ORIGIN + OWN + '?ver=2']: 'own v2' }, await go(OWN + '?ver=2')), 'own v2');
// 3. offline
const offlineNet = net; net = {};
check('offline, exact cached', await go(OWN + '?ver=2'), 'own v2');
const baseCss = shell.find((s) => s.includes('bn-base.css'));
check('offline, versioned shell file falls back to precached bare copy', await go(new URL(baseCss, ORIGIN).pathname + '?ver=9'), 'shell:' + baseCss);
check('offline, other plugin file still not intercepted', await go(OTHER + '?ver=1'), '(not intercepted: browser default)');
net = offlineNet;
const cached = [...stores.entries()].flatMap(([n, m]) => [...m.keys()].map((k) => n + '  ' + new URL(k).pathname));
console.log('caches after run:\n  ' + cached.join('\n  '));
// The precached shell is BuddyNext's own list; the runtime asset cache is where
// a theme's or another plugin's file used to land.
const assetName = src.match(/const ASSET_CACHE = '([^']+)'/)[1];
const ownPath = new URL(ownBase, ORIGIN).pathname; // browser-encoded, like the cache keys
const leaked = [...(stores.get(assetName) || new Map()).keys()].some((k) => new URL(k).pathname.indexOf(ownPath) !== 0);
check('nothing outside BuddyNext was cached', leaked, false);
console.log(out.join('\n'));
process.exit(out.some((l) => l.startsWith('FAIL')) ? 1 : 0);
