# Frontend Interactivity and Client-Side Navigation

How BuddyNext renders interactive frontend views: the WordPress Interactivity API store model, in-place client-side navigation, the shared REST client, and the rules that keep a surface working after a navigation. This page is for developers building blocks, hub surfaces, or bridge plugins that add interactive markup.

## Overview / Contract

BuddyNext uses the official WordPress Interactivity API (`@wordpress/interactivity`) for all frontend reactivity. There is no React, no JSX, and no build step you have to run as a consumer: stores are plain ES modules registered against the runtime WordPress already ships, and templates wire behavior with `data-wp-*` attributes.

Three rules govern every interactive surface:

1. **Declarative by default.** Controls bind to store actions and state through `data-wp-on--*`, `data-wp-bind--*`, and `data-wp-text` attributes. The Interactivity API re-hydrates these automatically, including after a client-side navigation, so they need no re-init code.
2. **REST-only data.** Every frontend read and mutation goes through the shared REST client (`restFetch`), never `admin-ajax.php` and never a scattered raw `fetch()`. The client centralizes the nonce, stale-nonce recovery, and error toasts.
3. **Navigation-safe imperative code.** Any unavoidable imperative setup is registered through `onNavReady()` so it runs on initial load and again after every client-side swap. Code bound only to `DOMContentLoaded` silently dies after the first navigation.

The full normative standard lives in `docs/standards/frontend-interactivity.md` (v1.0; reference implementation Jetonomy 1.5.0). This page documents how BuddyNext implements it.

## Frontend JS entry points

The shell module graph lives under `assets/js/shell/` and is shared by every feature store:

| File | Role |
| --- | --- |
| `assets/js/shell/rest-client.js` | The single REST client. Exports `restFetch`; also exposes `window.buddynextRest.restFetch` for the block bundle. |
| `assets/js/shell/navigate.js` | Registers the bare `buddynext` store's `navigate` action that swaps the router region. |
| `assets/js/shell/nav-init.js` | Exports `onNavReady()` - binds idempotent imperative init to load and to every client-nav. |
| `assets/js/shell/dialog.js` | `bnToast`, `bnConfirm`, `bnPrompt`, `bnReportDialog` - token-styled replacements for native `alert`/`confirm`. |
| `assets/js/shell/font-scale.js` | Pre-paint theme (`data-bn-theme`) and font-scale stamping; chrome-level, bound once. |

Feature stores live under `assets/js/{feature}/store.js` (for example `feed`, `members`, `spaces`, `messages`, `search`, `profile`, `notifications`, `hashtags`). Each is registered in `AssetService` and enqueued per hub by `PageRouter::enqueue_hub_assets()`. The block bundle (`assets/js/blocks.js`) registers the small per-block stores (`buddynext/follow-button`, `buddynext/connection-button`, `buddynext/notification-bell`, and so on).

> **Note:** Store namespaces are always `buddynext/{feature-name}` (for example `buddynext/feed`, `buddynext/follow-button`). The bare `buddynext` namespace is reserved for the shell's `navigate` action.

## The store pattern

A store has two halves: `state` (getters, computed from context) and `actions` (event handlers, written as generator functions so they can `yield` async work). Templates supply per-instance data through `data-wp-context` and bind to the store by name with `data-wp-interactive`.

### A small store binding

This is the complete follow button (block bundle `buddynext/follow-button` plus its template). It is the minimal end-to-end shape: context in, computed class and label out, a single action that calls REST and flips context.

Template (`templates/blocks/follow-button.php`):

```php
<div
    class="bn-block-follow-button"
    data-wp-interactive="buddynext/follow-button"
    data-wp-context="<?php echo esc_attr( $context_json ); ?>"
>
    <button
        type="button"
        class="bn-btn"
        data-variant="<?php echo $is_following ? 'secondary' : 'primary'; ?>"
        data-size="sm"
        data-wp-on--click="actions.toggleFollow"
        data-wp-bind--class="state.buttonClass"
        data-wp-text="state.label"
        aria-pressed="<?php echo $is_following ? 'true' : 'false'; ?>"
    ><?php echo $is_following ? esc_html__( 'Following', 'buddynext' ) : esc_html__( 'Follow', 'buddynext' ); ?></button>
</div>
```

`$context_json` is the JSON-encoded initial context, for example `{ "userId": 42, "isFollowing": false, "restUrl": "...", "nonce": "..." }`.

Store (`assets/js/blocks.js`):

```js
import { store, getContext } from '@wordpress/interactivity';

store( 'buddynext/follow-button', {
    state: {
        get buttonClass() {
            const ctx = getContext();
            return ctx.isFollowing
                ? 'bn-btn bn-btn--sm bn-btn--secondary bn-following'
                : 'bn-btn bn-btn--sm bn-btn--primary';
        },
        get label() {
            return getContext().isFollowing ? 'Following' : 'Follow';
        },
    },
    actions: {
        *toggleFollow() {
            const ctx    = getContext();
            const method = ctx.isFollowing ? 'DELETE' : 'POST';
            const res    = yield window.buddynextRest.restFetch(
                '/users/' + ctx.userId + '/follow',
                { base: ctx.restUrl, nonce: ctx.nonce, method }
            );
            if ( res.ok ) {
                ctx.isFollowing = ! ctx.isFollowing; // re-renders class + label
            }
        },
    },
} );
```

Mutating `ctx.isFollowing` re-runs the `buttonClass` and `label` getters, so the button text and styling update with no DOM code. Because the binding is declarative, the same button keeps working after a client-side navigation.

> **Tip:** Use computed `state` getters for every class and text binding. Do not write inline ternaries inside `data-wp-bind` attributes - keep the logic in the store.

## Client-side navigation

When client-side navigation is enabled, the `.bn-app` shell carries `data-wp-interactive="buddynext"` and `data-wp-on--click="actions.navigate"`, and the main column is wrapped in a single router region: `<main data-wp-router-region="buddynext/main">`. Clicking an in-app link swaps only that region instead of reloading the document.

How it works (`assets/js/shell/navigate.js`):

- The `navigate` action intercepts same-origin left-clicks on `<a>` elements, calls `@wordpress/interactivity-router` (a dynamic dependency that downloads once and is reused), and dispatches a `buddynext:navigated` event after each swap.
- The real `<a href>` is always preserved as the fallback. JS-off, router errors, modified clicks (cmd/ctrl/shift/alt), new-tab/download links, cross-origin links, and deny-listed routes all degrade to a classic full-page load.
- After a swap the action focuses the region, scrolls to top, and re-syncs active state on the persistent rail and mobile nav (which live outside the region, so their server-rendered active markers go stale).

### The route deny-list

Navigation uses a **deny-list, not an allow-list**, so new routes are fast by default. Only rich-editor and security-sensitive routes full-load: profile edit, space settings/admin, single-post permalinks (`/p/{id}/`), membership checkout, and the auth/signup/verify/onboarding flows. Integration surfaces register their own deny entries through the `buddynext_client_nav_deny` filter, so a newly bridged plugin's editor routes are respected without editing the shell.

Client-side navigation is gated behind the `buddynext_client_nav_enabled` filter and the `window.bnShellData.clientNav` switch (staged activation per surface). While off, every click falls through to a normal navigation and the shell renders exactly as a static page.

### Keeping imperative code navigation-safe

Region content swapped in by the router does not re-fire `DOMContentLoaded`. Bind any imperative setup through `onNavReady()` instead:

```js
import { onNavReady } from '../shell/nav-init.js';

// Runs on initial load AND after every client-side navigation.
onNavReady( function init() {
    // Idempotent: guard per-element work so re-running only wires new nodes.
    document
        .querySelectorAll( '.bn-thing:not([data-wired])' )
        .forEach( ( el ) => {
            el.dataset.wired = '1';
            // wire el ...
        } );
} );

// Chrome that lives OUTSIDE the router region and must not re-run:
onNavReady( setupFontScale, { once: true } );
```

`init` must be idempotent: guard per-element work with a dataset flag, and install document-delegated listeners behind a single window flag.

## The shared REST client

`restFetch( path, opts )` is the only sanctioned way to call the API from the frontend. It:

- Resolves the base from `window.buddynextRestData.restBase` (default `/wp-json/buddynext/v1`); `opts.base` overrides it for cross-namespace calls (for example the WPMediaVerse `mvs/v1` routes the messages store uses).
- Sends `X-WP-Nonce` and `credentials: 'same-origin'`, and on a `403 rest_cookie_invalid_nonce` refreshes the nonce once via `/auth/nonce` and retries.
- JSON-encodes plain-object bodies (FormData and Blob pass through so uploads keep their multipart boundary).
- Never throws - it always resolves to `{ ok, status, data, error? }`.
- Shows one generic error toast on failure unless the caller passes `{ toastOnError: false }` (needed when you do optimistic rollback with your own toast).

```js
import { restFetch } from '../shell/rest-client.js';

const res = await restFetch( '/posts', { method: 'POST', body: { content } } );
if ( res.ok ) {
    // res.data is the created post
}
```

## Notes / gotchas

### Anti-patterns to avoid

These all break after a client-side navigation, which is the failure mode the standard exists to prevent. A surface that works on full load but is dead or blank after a navigation is a failing surface.

- **Per-route or per-view `wp_enqueue_script`.** The store module plus the router cover every view; per-route scripts do not re-run on a swap.
- **`wp_add_inline_script(..., 'after')` or inline `<script>`** driving region behavior. Inline scripts in a swapped fragment do not execute. Move the logic into the store or an `onNavReady()` init.
- **`DOMContentLoaded`-only handlers** that target region content. Use `onNavReady()` so they re-run after each swap.
- **Element-bound `el.addEventListener(...)`** on content that gets swapped, without a re-init. Prefer declarative `data-wp-on--*`, or document-delegated listeners, or `onNavReady()`.
- **Raw `fetch()` on the frontend** with ad-hoc nonce handling. Route everything through `restFetch`; raw `fetch` is allowed only inside the REST client and the service worker.
- **Native `alert()` / `confirm()`.** Use `bnToast` / `bnConfirm` from `shell/dialog.js`.
- **`window` globals for stores** (`window.wp.interactivity.store(...)`). Always import `store` from `@wordpress/interactivity`.

### Verification

For every interactive surface, test the client-side path, not just a full load: full-load a different page, click a link to navigate to the surface, then exercise the control. It must behave identically to a full load. Code review looking clean is not sufficient.

### Free and Pro boundary

The shell module graph (REST client, navigate action, nav-init) is Free and shared. Pro features add their own stores under the same conventions and reuse the same `restFetch` client against the `buddynext-pro/v1` namespace by passing `opts.base`. Pro does not ship a second REST client or its own router.
