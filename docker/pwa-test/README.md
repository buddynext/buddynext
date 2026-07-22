# PWA / service-worker test origin

Run the site on an origin where service workers actually work, so PWA behaviour
can be tested at all.

```bash
cd docker/pwa-test
SITE_HOST=buddynext.local docker compose up -d
open http://127.0.0.1:8080/
```

Stop it with `docker compose down`. Change the site with `SITE_HOST`, and the
port with `PROXY_PORT`.

## Why this exists

**A service worker only runs in a secure context, and a plain-HTTP `.local` host
is not one.** On `http://buddynext.local` the API is not merely restricted — it is
absent:

```js
'serviceWorker' in navigator   // false
```

So no worker registers, nothing is cached, and every PWA check silently passes by
doing nothing. Local's HTTPS is no help either: the certificate is self-signed, so
the browser refuses it and automation fails with `ERR_CERT_AUTHORITY_INVALID`.

This gap is not theoretical. It is why a service worker that precached exactly one
URL, and left the site's one offline page rendering with 61 failed assets, reached
production: every local test of it was measuring nothing.

`127.0.0.1` **is** a secure context regardless of scheme — that is a deliberate
carve-out in the spec for local development. Proxying the real site onto it makes
the worker testable with no certificates and no changes to the site.

## What the proxy does

`nginx.conf` is a thin reverse proxy with three jobs beyond forwarding:

1. **Rewrites the site's absolute URLs** to the proxy origin. A worker cannot
   control a scope on another origin, so without this the page would load assets
   from `buddynext.local`, the worker would never take control, and the test would
   pass while proving nothing.
2. **Rewrites redirects** back onto the proxy origin, so the site's own
   `/` → `/activity/` → `/activity/explore/` chain does not bounce the browser off
   mid-test. Redirect handling is itself a thing worth testing here.
3. **Passes `Service-Worker-Allowed`** through. The worker is served from
   `/wp-json/…` but must control the whole site; strip that header and the scope
   silently narrows to `/wp-json/buddynext/v1/pwa/`.

It also disables upstream compression, because `sub_filter` cannot rewrite a
gzipped body.

The container is published on `127.0.0.1` only. It forwards to a local dev site
with no authentication in front of it and must not be reachable from the network.

## Testing offline

Register the worker by loading a page normally — **let the site register it
itself**; a hand-written `navigator.serviceWorker.register(url)` without
`{ scope: '/' }` gets the script's own directory as its scope and will not control
the site. Then:

```bash
docker stop buddynext-pwa-origin      # the network is now gone
```

Navigate **within the page** (a link, or `location.href = '/members/'`). A
top-level browser `goto` may not go through the worker and can report
`ERR_CONNECTION_REFUSED` that a real member would never see.

Expected: the branded offline page, in the site's colours, with the address bar
still holding the URL that was requested, and **Try again** returning there once
`docker start buddynext-pwa-origin` brings the site back.

Inspect what is stored from the page console:

```js
for (const n of await caches.keys()) {
  console.log(n, (await (await caches.open(n)).keys()).map(r => r.url));
}
```

Expected: a `buddynext-shell-*` cache holding the offline page and BuddyNext's
base stylesheets, and a `buddynext-assets-*` cache that fills as you browse and
stops at its cap. **No page HTML and no `/wp-json/` responses** — those are never
cached, deliberately, because a community page is personalised.
