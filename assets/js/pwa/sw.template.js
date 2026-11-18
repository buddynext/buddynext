'use strict';

{{imports_js}}const SHELL_CACHE = '{{shell_cache}}';
const ASSET_CACHE = '{{asset_cache}}';
const OFFLINE_URL = {{offline_js}};
const SHELL_ASSETS = {{shell}};

// This site's real admin / login / REST paths, injected from PHP. They are NOT
// assumed to be at the origin root: on a subdirectory install they are
// "/community/wp-admin/" and so on, and the guards below used to test for a
// leading "/wp-admin" that never matched there.
// Every path below is compared with url.pathname, so encode it exactly the way
// this browser does (a space becomes %20, ( ) + ' @ stay as they are). PHP sends
// the decoded path; a stricter or looser encoding on that side never matched on
// sites installed in such a folder.
const browserPath = (path) => new URL(path, self.location.origin).pathname;
const ADMIN_PATH = browserPath({{admin_path_js}});
const LOGIN_PATH = browserPath({{login_path_js}});
const REST_PATH = browserPath({{rest_path_js}});

// The only static files this worker caches: BuddyNext's own plugin directories.
const OWN_ASSET_PATHS = {{own_paths_js}}.map(browserPath);

// Cap the runtime asset cache. A community with many themes, avatars and icon
// sets can otherwise grow without limit on a member's device, and a phone that
// is low on storage evicts the WHOLE origin — including the shell that makes
// offline work at all. Trimmed oldest-first; cache.keys() is insertion-ordered.
const ASSET_CACHE_LIMIT = 60;

// A Response whose .redirected is true can NEVER be replayed for a navigation:
// respondWith() rejects it with "a redirected response was used for a request
// whose redirect mode is not follow", and the navigation fails outright — the
// browser shows a network error instead of the site.
//
// That is not hypothetical. cache.addAll() fetches with redirect mode "follow",
// so on any site with a canonical-host rule (www <-> apex) or http -> https in
// front of a precached URL, the stored copy came back redirected. Cache-first
// then replayed it for every navigation and took the whole site down for anyone
// who had the worker installed (Zoho #41005).
//
// Rebuilding the Response from its body clears the flag while keeping the
// content, so offline still works on those sites instead of being sacrificed.
async function bnCacheable(response) {
  if (!response || !response.ok) {
    return null;
  }
  if (!response.redirected) {
    return response;
  }
  const body = await response.blob();
  return new Response(body, {
    status: response.status,
    statusText: response.statusText,
    headers: response.headers,
  });
}

// Look up a cached entry, ignoring the ?ver= cache-buster on the second attempt.
// The shell is precached without a version query, but the page requests these
// files WITH one, so an exact-match-only lookup would miss every time and the
// offline page would render unstyled — the exact failure this release fixes.
async function bnMatch(request) {
  const exact = await caches.match(request);
  if (exact && !exact.redirected) {
    return exact;
  }
  const loose = await caches.match(request, { ignoreSearch: true });
  return (loose && !loose.redirected) ? loose : null;
}

async function bnTrim(cacheName, limit) {
  const cache = await caches.open(cacheName);
  const keys = await cache.keys();
  for (let i = 0; i < keys.length - limit; i++) {
    await cache.delete(keys[i]);
  }
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELL_CACHE).then(async (cache) => {
      // Deliberately not cache.addAll(): it stores whatever it gets, redirect
      // and all, and one failed asset rejects the whole install — which would
      // leave a member with no offline page at all.
      await Promise.all(SHELL_ASSETS.map(async (asset) => {
        try {
          const cacheable = await bnCacheable(await fetch(asset, { redirect: 'follow' }));
          if (cacheable) {
            await cache.put(asset, cacheable);
          }
        } catch (e) {
          // Precaching is best-effort; a missing asset must not block install.
        }
      }));
    })
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  const keep = [SHELL_CACHE, ASSET_CACHE];
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys.filter((k) => keep.indexOf(k) === -1).map((k) => caches.delete(k))
      )
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const request = event.request;

  // Only GET is cacheable, and only our own origin. Leaving everything else
  // untouched means a POST, a cross-origin embed or an analytics beacon behaves
  // exactly as it would with no worker installed.
  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) {
    return;
  }

  // wp-admin and the login screen must never be intercepted: they are
  // authenticated, nonce-bearing, and a stale copy locks an owner out.
  if (url.pathname.indexOf(ADMIN_PATH) === 0 || url.pathname.indexOf(LOGIN_PATH) === 0) {
    return;
  }

  // REST is member-specific data. Network only — no cache write, no cache read.
  // Falling back to a stored copy here would show one member another's feed.
  //
  // BOTH spellings, and the second one is the whole bug. With plain permalinks —
  // or any rest_url() built on a site that has them — REST arrives as
  // /index.php?rest_route=/wp/v2/... The pathname is then /index.php, so it
  // matches neither this test nor the /wp-admin one above, fell through to the
  // static-resource branch at the bottom, and got answered from cache or with the
  // offline page at HTTP 200 and content-type text/html.
  //
  // That broke WordPress CORE, not just our own routes: /wp/v2/users/me came back
  // as the offline page inside wp-admin, so any admin JS expecting JSON failed to
  // parse it, silently, with no error handler firing because the status was 200.
  //
  // This file already knew about the query-string form — see the offline-URL
  // comment in the PHP above, which preserves ?rest_route= precisely because
  // taking PHP_URL_PATH alone collapses it to "/". That reasoning simply never
  // reached the fetch bails.
  // REST_PATH is only a usable prefix when the site has pretty permalinks. Without
  // them rest_url() is "/?rest_route=/", whose PATH is just "/" — and
  // indexOf("/") === 0 is true for every request on the origin, which would bail on
  // everything and silently switch the whole PWA off. Those sites are covered by
  // the rest_route query check beside it, which is what identifies REST there.
  if ((REST_PATH !== '/' && url.pathname.indexOf(REST_PATH) === 0) || url.searchParams.has('rest_route')) {
    return;
  }

  // Navigations: network first, then the offline page. The rendered page itself
  // is never stored (see the class docblock), so there is no stale or
  // cross-member HTML to serve.
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(async () => {
        const offline = await bnMatch(new Request(OFFLINE_URL));
        return offline || Response.error();
      })
    );
    return;
  }

  // Static sub-resources. Only BuddyNext's own files are handled; the theme's,
  // other plugins' and uploads are left to the browser exactly as if no worker
  // were installed, so their updates are never answered with an old copy.
  if (!OWN_ASSET_PATHS.some((path) => url.pathname.indexOf(path) === 0)) {
    return;
  }

  // EXACT URL hit: serve it and refresh in the background. No exact hit (a
  // ?ver= bump after an update, or a first view): the network answers, so the
  // new file is used on this very view. The version-blind copy (the shell is
  // precached without ?ver=) is only the offline fallback.
  event.respondWith(
    (async () => {
      const exact = await caches.match(request);

      const network = fetch(request).then(async (response) => {
        const cacheable = await bnCacheable(response.clone());
        if (cacheable) {
          const cache = await caches.open(ASSET_CACHE);
          await cache.put(request, cacheable);
          bnTrim(ASSET_CACHE, ASSET_CACHE_LIMIT);
        }
        return response;
      }).catch(() => null);

      if (exact && !exact.redirected) {
        event.waitUntil(network);
        return exact;
      }

      const fresh = await network;
      if (fresh) {
        return fresh;
      }
      const offline = await bnMatch(request);
      return offline || Response.error();
    })()
  );
});