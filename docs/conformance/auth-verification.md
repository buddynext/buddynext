# Conformance: Authentication & Verification

**Feature:** Authentication & Verification (Free)
**Spec ref:** `docs/specs/features/17-roles-permissions.md` (visibility/gating intent); journey `docs/journeys/auth-verification.md`
**Cross-cutting contracts checked:** `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/SCALE-CONTRACT.md`
**Live-walk URL:** http://buddynext-dev.local/login
**Verdict:** usable-leave-as-is

---

## Summary

The auth + email-verification journey is wired end-to-end for BOTH the web journey
(real UI controls → Interactivity stores → REST → service → DB) and the app/REST
client. Login, register, verify-link click-through, resend, and the
already-verified idempotency case all have a real path through the code. No
journey-stopping break was found.

The `bn_verify_tokens` and `bn_email_templates` tables are created on install
(`includes/Core/Installer.php:568`, `:545`), the `email_verify` template is seeded
with the correct `{{verify_url}}` / `{{user_name}}` placeholders
(`includes/Core/Installer.php:74-78`), the `?bn_verify=TOKEN` GET handler is hooked
on `init` (`includes/Auth/VerificationListener.php:45,72`), and the `^login/?$`
rewrite rule maps the entry URL to `auth/login.php`
(`includes/Core/PageRouter.php:1195`, `:886`).

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Visit `/login` → login card renders | ui | wired | `includes/Core/PageRouter.php:1195` (rewrite `^login/?$`), `:886` (maps to `auth/login.php`); `templates/auth/login.php:55` |
| Submit credentials (form bound to store) | store | wired | `templates/auth/login.php:94` `data-wp-on--submit="actions.submitLogin"`; `assets/js/auth/login-store.js:57-88`; enqueued at `includes/Core/PageRouter.php:710` / `AssetService.php:325` |
| POST `/buddynext/v1/auth/login` authenticates | rest | wired | `includes/Auth/AuthController.php:34,308` (`wp_signon`, returns `redirect_to`) |
| New user registers via `/signup` | ui | wired | `templates/auth/signup.php`, `assets/js/auth/signup-store.js`; `AuthController::register` `includes/Auth/AuthController.php:358-444` |
| Registration issues verify token | service | wired | `AuthController.php:422` calls `VerificationService::create_token`; also `VerificationListener::on_user_register` `VerificationListener.php:58-64` (gated on `buddynext_email_verify`) |
| `create_token` inserts row + fires send action | service | wired | `VerificationService.php:32-62` inserts to `bn_verify_tokens`, fires `buddynext_send_verification_email` |
| Email dispatched with template | service | wired | `VerificationListener::send_verification_email` `VerificationListener.php:110-192`; template seeded `Installer.php:74-78`; EmailSender path + wp_mail fallback |
| User clicks `?bn_verify=TOKEN` link | rest | wired | `VerificationListener::handle_verify_request` `VerificationListener.php:72-96` (hooked on `init` `:45`) |
| `verify()` validates, sets usermeta, deletes token, fires actions | service | wired | `VerificationService::verify` `VerificationService.php:74-129` sets `buddynext_email_verified=1`, deletes row, fires `buddynext_user_verified`; listener fires `buddynext_email_verified` `VerificationListener.php:92` |
| Verify page shows success / error state | ui | wired | `templates/auth/verify.php:42-45,152-225` (states from `?bn_verified`) |
| Resend verification (button → store → REST) | ui | wired | `templates/auth/verify.php:284` `actions.resendEmail`; `assets/js/auth/verify-store.js:34-72`; `AuthController::resend_verification` `AuthController.php:462-476` → `VerificationService::resend` `VerificationService.php:157-180` |
| Idempotency: re-verify consumed token | service | wired | `VerificationService.php:87-93` returns `WP_Error` (row gone); usermeta stays `1` (verify only sets, never unsets) |
| `bn_verify_tokens.token` UNIQUE constraint | db | wired | `includes/Core/Installer.php:576` `UNIQUE KEY token (token)` |
| Expired-token cleanup cron | db | wired | `includes/Core/CronService.php:141` deletes `expires_at < NOW()` |

---

## First break

none — journey complete

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Token expiry copy/data drift: code sets `+48 hours` but the seeded email body, the verify-page error/pending copy, and the journey doc all say "24 hours". Functionally harmless (verification still works inside the window) but the user is told the wrong expiry. | low | confirmed-in-code | `includes/Auth/VerificationService.php:44` (`+48 hours`) vs `includes/Core/Installer.php:77` and `templates/auth/verify.php:189,270` ("24 hours") |
| Verify-link click-through (`?bn_verify`) and the resend button are only fully exercised live; the on-`init` GET handler and the seeded template `enabled` flag cannot be confirmed without a running site + Mailpit. The code path reads correct; flagged only for live confirmation per grounding rules. | low | needs-live-verification | `includes/Auth/VerificationListener.php:72-96`; `includes/Core/Installer.php:218` (`INSERT IGNORE`, default `enabled=1`) |

Neither gap stops the journey.

---

## Minimal refactor plan

(empty — usable-leave-as-is)

Optional, non-blocking: reconcile the expiry value so copy and data agree —
either change `VerificationService.php:44` to `+24 hours` or update the three
"24 hours" strings to "48 hours". Pure consistency fix, not required for the
journey to work.

---

## Notes for the live walk (http://buddynext-dev.local/login)

- `buddynext_email_verify` defaults to `false` (`includes/Admin/Settings.php:44`).
  With it OFF, `is_verified()` returns `true` for everyone and the verify page
  redirects verified/logged-in users to the feed — verification is informational.
  To walk the full gated flow, enable it in the Setup Wizard
  (`includes/Onboarding/SetupWizard.php:620`) or via the
  `buddynext_email_verify` option first.
- Use the local Mailpit catcher (http://localhost:10010/) to capture the
  verification email and extract the `?bn_verify=` link for the click-through step.
- App/REST clients reach the same flow via `POST /auth/register`,
  `POST /auth/verify/resend`, and `GET /auth/verify/status` — all registered in
  `AuthController::register_routes` (`includes/Auth/AuthController.php:31-151`).
