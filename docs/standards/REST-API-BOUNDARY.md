# REST API Boundary Standard (Frontend ⇄ Server)

> Portable foundation for **every Wbcom plugin**. Reference paths are BuddyNext-specific;
> the rule is not. This documents a boundary that is **already enforced by CI** so it
> stays enforced.
>
> Reference implementation + rationale: [`../plans/scale-readiness-100k.md`](../plans/scale-readiness-100k.md).

## 1. The one principle

**Every frontend ⇄ server data exchange goes through the REST API. Zero `admin-ajax`
in application code.** The frontend is a REST client; the server is a REST provider.
This is what makes the same backend serve the web UI today and a native/mobile app
tomorrow with no rewrite.

## 2. The rules

### Rule 1 — One namespace per plugin

- Free: `buddynext/v1`. Pro: `buddynext-pro/v1`. Register with
  `register_rest_route()`; no other route registration mechanism.
- Cross-engine calls (e.g. the WPMediaVerse DM engine `mvs/v1`) are the **only**
  exception, reached by an explicit per-call base override and documented as such.

### Rule 2 — One transport client on the frontend

- All calls go through the shared client (`assets/js/shell/rest-client.js`, `restFetch`).
- **Banned in app JS:** `admin-ajax.php`, `jQuery.ajax`, `XMLHttpRequest`, raw
  `apiFetch` outside the shared client. No `wp_ajax_*` handlers in plugin PHP (bundled
  libraries are exempt).

### Rule 3 — Uniform auth

- Every browser call sends `X-WP-Nonce` + `credentials: 'same-origin'`.
- Stale-nonce recovery is automatic: on `403 rest_cookie_invalid_nonce`, refresh via
  `GET /auth/nonce` and retry once. Don't hand-roll per-call nonce logic.
- Every route declares a real `permission_callback` (via
  `BaseRestController::require_auth()` or a capability check) — never
  `__return_true` on a route that reads or writes user data.

### Rule 4 — App-readiness: express the whole feature over REST

- Every feature is fully expressed as REST: **list + count + mutate** (e.g.
  notifications expose list, unread-count, mark-read, mark-all, delete). A native client
  must be able to do everything the web UI does.
- **Open item for native clients:** the surface is cookie+nonce auth only. Before a
  first-party app ships, add a token/JWT path or formally bless Application Passwords —
  do not bolt per-endpoint auth hacks.

## 3. The gate

The boundary is enforced by **`bin/check-rest-boundary.sh`** (run in CI and before
release). It fails the build on any frontend `admin-ajax` / `$.ajax` / `wp_ajax_` in
app code. New code that needs server data adds a REST route + a `restFetch` call — there
is no second way in.

## 4. Per-endpoint checklist

1. Registered under `buddynext/v1` or `buddynext-pro/v1` (or a documented cross-engine
   base)?
2. Called via the shared `restFetch` client — no `$.ajax` / `admin-ajax`?
3. Real `permission_callback` (auth or capability)?
4. Nonce handled by the shared client (not per-call)?
5. Does the feature expose list + count + mutate so an app could consume it?
6. `check-rest-boundary.sh` green?

## 5. BuddyNext reference implementation

| Pattern | File |
|---|---|
| Shared transport + nonce recovery | `assets/js/shell/rest-client.js` |
| Base REST controller + `require_auth()` | `includes/Rest/BaseRestController.php` |
| Full feature over REST (list/count/mutate) | `includes/Notifications/NotificationController.php` |
| Documented cross-engine base override | `assets/js/messages/store.js` (`mvs/v1`) |
| The enforcing gate | `bin/check-rest-boundary.sh` |
| Full audit + decisions | `docs/plans/scale-readiness-100k.md` |
