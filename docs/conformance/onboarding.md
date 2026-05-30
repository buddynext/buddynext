# Conformance — Onboarding (Member Onboarding Wizard)

**Feature:** Onboarding (member wizard) · repo: free
**Spec ref:** `docs/specs/features/10-onboarding-setup-wizard.md` (Member Onboarding Wizard section)
**UX intent:** `docs/v2 Plans/v2/onboarding.html`
**Live-walk URL:** http://buddynext-dev.local/onboarding
**Verdict:** usable-leave-as-is

---

## Scope

This audit covers the **Member Onboarding Wizard** (the journey behind the
`/onboarding` front-door URL). The spec also defines an Admin Setup Wizard,
Admin Bulk Invite, and Registration Form — those are separate surfaces
(`SetupWizard.php`, `InviteController/Service.php`) and out of scope for the
`/onboarding` member journey, except where they feed the entry trigger.

## Happy-path journey (entry → outcome)

A new member registers → is signed in and redirected to `/onboarding` (or to
verify-then-onboarding when email verification is on) → walks Profile → Spaces
→ People → Notifications → Finish → lands on the activity feed. Every step is
skippable.

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Register → redirect into wizard | service | wired | `includes/Auth/AuthController.php:428` (`PageRouter::onboarding_url()`; verify-gated branch follows) |
| 2 | `/onboarding` route resolves + guest/complete gate | rest/service | wired | `includes/Core/PageRouter.php:202-205, 877-878, 1202-1207` |
| 3 | Wizard shell + assets load | ui | wired | `PageRouter.php:702-703` enqueues `onboarding`; module map `includes/Core/AssetService.php:328` → `assets/js/onboarding/store.js` |
| 4 | Step 1 Profile: name/handle/bio/avatar UI bound to store | ui/store | wired | `templates/onboarding/index.php:251-307` (`setDisplayName`/`checkUsername`/`setBio`/`handleAvatarUpload`); `assets/js/onboarding/store.js:144-189,298-333` |
| 4a | Username availability check | rest | wired | store `store.js:172` GET `profile-slug/check`; route `includes/Profile/ProfileController.php:201` |
| 4b | Avatar upload | rest | wired | store `store.js:312` POST `me/avatar`; route `includes/Profile/ProfileController.php:126` |
| 5 | Step 2 Spaces: join inline | ui/store/rest | wired | template `:376-384` `joinSuggestedSpace`; store `:200-247` POST/DELETE `spaces/{id}/members`; route `includes/Spaces/SpaceController.php:87` |
| 6 | Step 3 People: follow inline | ui/store/rest | wired | template `:480-488` `followSuggestedUser`; store `:248-297` POST/DELETE `users/{id}/follow`; route `includes/SocialGraph/FollowController.php:34,42` |
| 7 | Step 4 Notifications: channel toggles | ui/store/rest | wired | template `:539-579` `toggleChannel`; store `:190-199,364-371` PUT `me/notification-channels`; route `includes/Notifications/NotificationController.php:123` |
| 8 | Finish: persist profile/handle/channels + complete | store/rest/service | wired | store `:334-394` PUTs `me/profile`,`profile-slug`,`me/notification-channels` then POST `me/onboarding/complete`; `includes/Onboarding/OnboardingController.php:129-162`; route registered `includes/REST/Router.php:63` |
| 9 | Complete fires hook → cancels nudges, marks done, redirect | service/db | wired | `OnboardingService.php:104-115` fires `buddynext_onboarding_completed`; `OnboardingListener.php:30-55` cancels 24h/72h nudges; redirect to `PageRouter::activity_url()` `OnboardingController.php:158` |
| 10 | Skip at any step | ui/store/rest | wired | template `:311-317` `skipStep`; store `:127-143` POST `me/onboarding/skip`; `OnboardingController.php:111-117` |
| 11 | Nudge emails (24h / 72h) scheduled at register | service | wired | `OnboardingListener.php:42-45` schedules; `:65-87` sends `bn.onboarding_nudge` if not complete |

## First break

none — journey complete. Every UI affordance is bound to a store action that
calls a REST endpoint that exists and is registered.

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Spec Step 2 "interests → maps to space categories" was intentionally dropped; the wizard ships Profile/Spaces/People/Notifications instead. This is a documented, deliberate divergence (nothing downstream read `bn_interests`), not a defect — flagged only as a spec/implementation drift to reconcile in the locked doc. | low | confirmed-in-code | `templates/onboarding/index.php:117-121` (decision note); spec line 38 |
| Step values sent to `me/onboarding/step` are best-effort and ignored for steps 2-4 server-side (only step 1 profile persists there; spaces/people/channels persist via their own endpoints). Harmless — the dedicated endpoints carry the real data. | low | confirmed-in-code | `OnboardingService.php:73-84`; store `store.js:112-118` |

No critical/high/medium gaps. Per the seed-data rule, an empty test account will
show "No spaces available" / "No suggestions yet" empty states (`index.php:387-390,492-495`)
— that is expected on an unseeded site, not a break.

## Minimal refactor plan

EMPTY — usable, leave as is. (Optional, non-blocking: update the locked spec
Step-2 wording to match the shipped Notifications step, since the divergence is
intentional and already documented in the template.)
