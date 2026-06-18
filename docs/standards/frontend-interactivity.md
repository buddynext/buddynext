# Wbcom Frontend Interactivity & Client-Side Navigation Standard

**Status:** Normative. Applies to every Wbcom WordPress plugin/theme that renders
interactive frontend views (forums, communities, dashboards, member areas).
**Version:** 1.0 (2026-06-18). **Reference implementation:** Jetonomy 1.5.0
(`jetonomy` free + `jetonomy-pro`).

This is the single source of truth. Each plugin keeps a synced copy at
`docs/standards/frontend-interactivity.md` and a one-line pointer in its
`CLAUDE.md`. Do not re-derive the rules per plugin — link here.

---

## 1. Why this exists

Frontend views should navigate like an app: clicking between listings, profiles,
and detail pages swaps content in place instead of reloading the whole document.
We do this with the **official WordPress Interactivity API client-side
navigation** (`@wordpress/interactivity-router`), not a custom SPA.

The failure mode this standard prevents: a feature wired up only on the *first*
page load silently dies after a client-side navigation, because the router swaps
the DOM without re-running page scripts and `DOMContentLoaded` never re-fires.
That ships as "works on full load, broken in the live app" — the worst kind of
bug because it passes a naive test.

Ref: <https://developer.wordpress.org/block-editor/reference-guides/interactivity-api/core-concepts/client-side-navigation/>

---

## 2. The model

- **One router region per layout.** Wrap the main view in a single element that
  carries BOTH `data-wp-interactive="{ns}"` AND
  `data-wp-router-region="{ns}/main"`. The router only swaps content inside this
  region. The region id must be identical across every route.
- **Persistent chrome lives outside the region.** Header, nav, opt-in buttons,
  footer — anything that must survive navigation — renders before the region
  opens, so it is never swapped and its handlers never need re-wiring.
- **Declarative first.** Interactive surfaces use `data-wp-on--*` store actions.
  The Interactivity API re-hydrates these automatically on every swap, so they
  need zero re-init code.
- **The router loads once.** Enqueue `@wordpress/interactivity-router` as a
  dynamic dependency of the store module (via
  `wp_interactivity()->add_client_navigation_support_to_script_module()` /
  `loadOnClientNavigation`). No per-route scripts.

---

## 3. Rules (normative)

Each rule is checkable. The wp-plugin-qa audit folds these into its notes.

1. **MUST** wrap the main view in one element with both `data-wp-interactive`
   and `data-wp-router-region`. Header/footer chrome stays outside it.
2. **MUST NOT** enqueue per-route / per-view scripts for region content. The
   store module + the router cover every view.
3. **MUST** make interactive controls declarative (`data-wp-on--*` store
   actions) by default. Declarative controls auto-hydrate on navigation.
4. **MUST** make any unavoidable imperative/classic script idempotent and bind
   its `init()` to BOTH initial load AND a custom `{ns}:navigated` event the
   navigate action dispatches after each swap. Never rely on `DOMContentLoaded`
   alone for region content.
5. **MUST NOT** use inline `<script>` / `wp_add_inline_script` to drive region
   behavior. Inline scripts in a swapped fragment do not execute. Move the logic
   into the store module's nav-aware `init()`.
6. **MUST** route all frontend data calls through one shared REST client with
   automatic nonce-refresh (e.g. `window.{ns}Rest.restFetch`). No scattered raw
   `fetch()` on the frontend; raw `fetch` is allowed only inside that client and
   inside service workers.
7. **SHOULD** keep an explicit, minimal **deny-list** for routes that must
   full-load (rich editors: post view, composer). Everything else client-navs.
   Prefer a deny-list over an allow-list so new routes are fast by default.
8. **MUST** render persistent UI (header nav items, opt-in prompts) outside the
   router region so it survives navigation without re-init.
9. **MUST** verify every interactive surface AFTER a client-side navigation, not
   only after a full load (see Section 6).

---

## 4. Canonical patterns

### Region wrapper (template)
```php
echo '<div id="{ns}-app" data-wp-interactive="{ns}" data-wp-on--click="actions.navigate">';
  // persistent chrome (header/nav) renders HERE — outside the region
  include $header_path;
  // only this region is swapped on client-side navigation
  echo '<div data-wp-interactive="{ns}" data-wp-router-region="{ns}/main">';
    include $template_path;
  echo '</div>';
echo '</div>';
```

### Router as a dynamic dep of the store module (PHP)
```php
wp_interactivity()->add_client_navigation_support_to_script_module(); // or the
// equivalent: register @wordpress/interactivity-router as a dynamic dep of the
// store module so it loads once and is reused across navigations.
```

### Declarative control (template) — preferred, no JS glue
```php
<button data-wp-on--click="actions.toggleReaction">React</button>
```

### Nav-aware re-init for unavoidable classic scripts (JS)
```js
function init() {
  // idempotent: guard so re-running only wires freshly-swapped nodes
  document.querySelectorAll('.thing:not([data-wired])').forEach(wire);
}
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else { init(); }
document.addEventListener('{ns}:navigated', init); // re-run after every swap
```

### Centralized fetch (JS)
```js
const res = await window.{ns}Rest.restFetch('/endpoint', { method: 'POST', body });
// returns { ok, status, data }; nonce + 403-refresh handled centrally
```

### Editor deny-list (JS, in the navigate action)
```js
// full-load only the rich-editor routes; everything else client-navs
const editorRoute = seg[0] === 's' && ['t', 'new'].includes(seg[2]);
if (editorRoute) return; // let the browser do a normal navigation
```

---

## 5. Compliance checklist (audit)

A plugin is compliant when ALL hold. This is what to grep/inspect in review and
what wp-plugin-qa reports against.

- [ ] Exactly one `data-wp-router-region` per layout; it also has
      `data-wp-interactive`.
- [ ] No per-route `wp_enqueue_script` for region content (only the store module
      + router).
- [ ] No `wp_add_inline_script` / inline `<script>` driving region behavior.
- [ ] No `DOMContentLoaded`-only handler that targets region content; every such
      script also listens for `{ns}:navigated`.
- [ ] No element-bound `addEventListener` on swapped content without a
      `{ns}:navigated` re-init (declarative or document-delegated is fine).
- [ ] No raw frontend `fetch()` outside the shared REST client / service worker.
- [ ] Persistent chrome (header/opt-ins) renders outside the region.
- [ ] Deny-list (not allow-list) governs which routes full-load; it is minimal
      and documented.

Fast greps (adapt `{ns}`):
```
grep -rn "data-wp-router-region" templates/            # expect exactly one per layout
grep -rn "wp_add_inline_script" includes/              # expect none for frontend region
grep -rn "DOMContentLoaded" assets/js/ | grep -v min   # each must also handle {ns}:navigated
grep -rn "fetch(" assets/js/ | grep -v "restFetch\|min" # raw fetch on frontend = smell
```

---

## 6. Verification (non-negotiable)

For every interactive surface, browser-test the CLIENT-SIDE path, not just full
load:

1. Full-load a different page.
2. Click a link to navigate to the surface (client-side swap).
3. Exercise the control (type, click, submit, scroll).
4. Confirm it behaves identically to a full load.

A surface that works on full load but is dead/blank/mis-scrolled after a
client-side navigation is a **failing** surface, even if code review looks clean.
This is exactly how the two real Jetonomy 1.5.0 client-nav bugs (messaging
typeahead; conversation auto-scroll + mobile fit) were caught and would
otherwise have shipped.

---

## 7. Anti-patterns (these break on client-nav)

- Per-route `<script>` enqueues / allow-listed route scripts.
- `wp_add_inline_script(..., 'after')` for region behavior.
- `document.addEventListener('DOMContentLoaded', ...)` as the *only* trigger for
  region content.
- Element-bound `el.addEventListener(...)` on content that gets swapped, with no
  `{ns}:navigated` re-init.
- Cloning a now-decorative node (e.g. an `aria-hidden` icon) without restoring
  the attributes the new context needs.
- Scattered raw `fetch()` with ad-hoc nonce handling.

---

## 8. Adoption per plugin

1. Copy this file to `docs/standards/frontend-interactivity.md` in the plugin.
2. Add a one-line pointer in the plugin's `CLAUDE.md` (see existing plugins).
3. Run the Section 5 checklist (wp-plugin-qa includes it in its audit notes).
4. Fix violations; verify per Section 6 before release.
