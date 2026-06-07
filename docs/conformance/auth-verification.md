# Conformance: Authentication & Verification

**Feature:** Authentication & Verification (Free)
**Spec ref:** `docs/specs/features/17-roles-permissions.md` (visibility/permissions) + `docs/journeys/auth-verification.md` (UX journey)
**Cross-cutting:** `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/SCALE-CONTRACT.md`
**Live-walk URL:** http://buddynext-dev.local/login
**Verdict:** usable-leave-as-is

---

## Summary

The web auth journey (login → register → email verify → resend) is fully wired end-to-end: real templates, bound Interactivity API stores, registered REST routes, working services, and a provisioned DB table. The journey doc was written before the REST/UI layer was confirmed (its "REST surface walked" section claims "No dedicated REST endpoint for verification"); in fact `AuthController` exposes `/auth/login`, `/auth/register`, `/auth/verify/resend`, `/auth/verify/status` (plus change-password/change-email/sign-out-everywhere), and the verify click-through is handled by `VerificationListener::handle_verify_request()` on `?bn_verify=TOKEN`. No usability break found.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| `/login` resolves to auth hub, login action | rest | wired | `includes/Core/PageRouter.php:1203` (rewrite → `bn_auth_action=login`), `:894` (dispatches `auth/login.php`) |
| Login form rendered with bound store | ui | wired | `templates/auth/login.php:56` (`data-wp-interactive="buddynext/auth-login"`), `:94` (`data-wp-on--submit="actions.submitLogin"`) |
| Login store posts to REST | store | wired | `assets/js/auth/login-store.js:66` (`rest(c,'auth/login',{method:'POST'})`); enqueued `includes/Core/PageRouter.php:716`; registered `includes/Core/AssetService.php:325` |
| `POST /auth/login` authenticates | rest | wired | `includes/Auth/AuthController.php:308` (`login()` → `wp_signon`), route `:34`; registered `includes/REST/Router.php:62` |
| Register → create user + issue token | service | wired | `includes/Auth/AuthController.php:358` (`register()`), `:422` (`VerificationService::create_token`) |
| Token persisted, UNIQUE token key | db | wired | `includes/Auth/VerificationService.php:38` (insert); table `includes/Core/Installer.php:567` (`UNIQUE KEY token`) |
| Verification email dispatched | service | wired | `includes/Auth/VerificationListener.php:110` (`send_verification_email`, EmailSender + wp_mail fallback); fired via `buddynext_send_verification_email` `VerificationService.php:59` |
| User clicks `?bn_verify=TOKEN` link | service | wired | `VerificationListener.php:72` (`handle_verify_request`), `VerificationService.php:74` (`verify()` sets `buddynext_email_verified=1`, deletes row, fires `buddynext_user_verified`) |
| Verify result page (success/error/pending) | ui | wired | `templates/auth/verify.php:38-59` (state machine on `bn_verified` / `bn_email_changed` params) |
| Resend verification | store | wired | `templates/auth/verify.php:284` (`actions.resendEmail`), `assets/js/auth/verify-store.js:40` (`POST auth/verify/resend`), `AuthController.php:462` (`resend_verification`), `VerificationService.php:157` (`resend`) |
| Idempotency / already-verified | service | wired | `VerificationService.php:140` (`is_verified`), `:158` (resend rejects already-verified) |

## First break

none — journey complete.

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Token expiry copy mismatch: code sets `+48 hours`; service docblock, journey doc, and verify-page UI all state "24 hours". Cosmetic drift — token is still valid when clicked, just longer-lived than advertised; does not break the flow. | low | confirmed-in-code | `includes/Auth/VerificationService.php:44` (`+48 hours`) vs docblock `:27`, `templates/auth/verify.php:270`, `docs/journeys/auth-verification.md:68` |
| Journey doc "REST surface walked" claims no verification REST endpoint exists; the implementation has a full `AuthController` REST surface plus the `?bn_verify` GET handler. Stale doc, no user impact. | low | confirmed-in-code | `docs/journeys/auth-verification.md:221-228` vs `includes/Auth/AuthController.php:31-151`, `includes/Auth/VerificationListener.php:45` |

## Minimal refactor plan

(empty — usable-leave-as-is. Both gaps are documentation/copy drift, not journey breaks. If ever touched: align the `+48 hours` in `VerificationService::create_token` with the surrounding "24 hours" copy to a single source of truth, and refresh the journey doc's "REST surface walked" section. Neither is required for the journey to work.)
