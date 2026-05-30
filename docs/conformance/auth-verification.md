# Conformance: Authentication & Verification

**Feature:** Authentication & Verification (Free)
**Spec ref:** `docs/specs/features/17-roles-permissions.md` (visibility/gate model) + `docs/journeys/auth-verification.md` (UX journey)
**Code traced:** `includes/Auth/{AuthController,VerificationListener,VerificationService}.php`, `templates/auth/{login,verify,signup}.php`, `assets/js/auth/{login,verify,signup}-store.js`
**Live-walk URL:** http://buddynext-dev.local/login
**Verdict:** usable-leave-as-is

---

## Journey chain

Happy path: a visitor opens `/login`, signs in (or registers), receives a verification email, clicks the token link, lands verified, and can resend if needed. Both the web journey (templates + Interactivity stores) and the app/REST journey are wired.

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | `/login` resolves to auth hub → renders login template | ui | wired | `includes/Core/PageRouter.php:871` (`auth/login.php`), `:1762` (default slug `login`), `:1174` |
| 2 | Login form bound to store; posts credentials | ui→store | wired | `templates/auth/login.php:94` `data-wp-on--submit="actions.submitLogin"`; `assets/js/auth/login-store.js:57-83` |
| 3 | `POST /auth/login` authenticates via `wp_signon`, returns redirect | rest→service | wired | `includes/Auth/AuthController.php:308-350`; route `:34-55` |
| 4 | Register: `POST /auth/register` creates user, issues token, signs in | rest→service | wired | `AuthController.php:358-444`; always calls `create_token` `:422` |
| 5 | `user_register` hook → create token when verify enabled | service | wired | `VerificationListener.php:44,58-64`; listener registered `Plugin.php:188` |
| 6 | `create_token` inserts row into `bn_verify_tokens`, fires send action | service→db | wired | `VerificationService.php:32-62`; table+UNIQUE KEY `Installer.php:568-576` |
| 7 | `buddynext_send_verification_email` → EmailSender (template) + log | service→db | wired | `VerificationListener.php:110-192`; `email_verify` template seeded `Installer.php:74-77`; `EmailSender::send` logs `bn_email_log` `EmailSender.php:260-271` |
| 8 | User clicks `?bn_verify=TOKEN` → server-side verify, redirect | ui→service | wired | `VerificationListener.php:72-96`; `VerificationService::verify` `:74-129` |
| 9 | Verify sets `buddynext_email_verified=1`, deletes token, fires actions | service→db | wired | `VerificationService.php:112-128`; `buddynext_email_verified` action `VerificationListener.php:92` |
| 10 | Verify result page (success / error / pending) | ui | wired | `templates/auth/verify.php:38-297` |
| 11 | Resend: pending/error CTA → `POST /auth/verify/resend` | ui→store→rest→service | wired | `verify.php:203,284`; `verify-store.js:34-72`; `AuthController.php:462-476`; `VerificationService::resend` `:157-180` |
| 12 | Verification status (app/REST clients) | rest→service | wired | `AuthController.php:483-489` `GET /auth/verify/status` |
| 13 | Idempotency / expired / not-found token handling | service | wired | `VerificationService.php:87-108` (invalid → WP_Error, expired → delete + WP_Error) |

Adjacent account flows in the same controller are also fully wired: change-password (`AuthController.php:161-211`), confirm-then-swap change-email (`:223-279` + `VerificationListener.php:209-312`), sign-out-everywhere (`:287-300`).

## First break

none — journey complete. Every layer (ui → store → rest → service → db) is present and bound for the core register → email → verify → resend path, in both the web (Interactivity) and REST/app surfaces.

## UX gaps

Only minor, non-blocking observations. None stop the journey.

1. **Journey doc drifts from code (token TTL & meta key).** The journey asserts `expires_at = +24h` and usermeta `bn_email_verified`. The code uses `+48 hours` (`VerificationService.php:44`) and `buddynext_email_verified` (`:112`). The verify template (`verify.php:47`) and service agree with each other, so the runtime is internally consistent; the journey doc is the stale party. Severity: low. Confidence: confirmed-in-code.

2. **Resend requires an authenticated session, but a guest who just registered is signed in immediately** (`AuthController.php:425-426`), so the pending-state resend button renders only for logged-in users (`verify.php:278`). A guest landing on the verify page from an email-only hint (`verify.php:53-55`) sees no resend control. This matches the template's deliberate `if current_user > 0` guard and is the expected design for the email-link flow; not a break. Severity: low. Confidence: confirmed-in-code.

## Minimal refactor plan

(empty — usable-leave-as-is)

## Notes on dual-surface coverage

- Web journey: login/signup/verify templates bind via `data-wp-interactive` + `data-wp-on--*` to registered script modules (`AssetService.php:325-327`), enqueued per-template (`PageRouter.php:686-693`). Stores are plain ES modules importing `@wordpress/interactivity` (no build artifact required).
- App/REST journey: `POST /auth/login`, `/auth/register`, `/auth/verify/resend`, `GET /auth/verify/status`, plus change-password/change-email/sign-out-everywhere are all registered under `buddynext/v1` (`AuthController.php:31-151`, wired in `REST/Router.php:61`).
- The verify-link click is intentionally server-side (`?bn_verify` GET handler) rather than a REST endpoint — consistent with the journey doc's "REST surface walked" note.
