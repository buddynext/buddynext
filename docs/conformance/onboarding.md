# Conformance — Onboarding (Member Onboarding Wizard)

**Feature:** Onboarding (member wizard) · repo: free
**Spec ref:** `docs/specs/features/10-onboarding-setup-wizard.md` (Member Onboarding Wizard section)
**UX intent:** `docs/v2 Plans/v2/onboarding.html`
**Live-walk URL:** http://buddynext-dev.local/onboarding
**Verdict:** partial-needs-wiring

> Supersedes a prior pass that read this as `usable-leave-as-is`. That pass listed
> Step 2 join as wired to `spaces/{id}/members` (`SpaceController.php:87`) but did not
> check the HTTP **method**: that route is GET-only, while the store issues POST/DELETE.
> The mismatch is the one real break below.

---

## Scope

This audit covers the **Member Onboarding Wizard** (the journey behind the
`/onboarding` front-door URL). The spec also defines an Admin Setup Wizard,
Admin Bulk Invite, and Registration Form — separate surfaces
(`SetupWizard.php`, `InviteController/Service.php`) and out of scope here except
as the wizard's entry trigger.

The shipped wizard is **4 steps** (Profile · Spaces · People · Notifications), a
documented divergence from the spec's "Interests" step — the old interests step
was removed because nothing downstream read `bn_interests`
(`templates/onboarding/index.php:117-121`). Reasonable divergence, not a break.

## Happy-path journey (entry → outcome)

A new member registers → is signed in and redirected to `/onboarding` (or
verify-then-onboarding when email verification is on) → walks Profile → Spaces →
People → Notifications → Finish → lands on the activity feed. Every step skippable.

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Register → redirect into wizard | service | wired | `includes/Auth/AuthController.php:428` (`PageRouter::onboarding_url()`; verify-gated branch `:429-435`) |
| 2 | `/onboarding` route resolves + guest/complete gate | rest | wired | `includes/Core/PageRouter.php:203-205,894-895,1219-1224` |
| 3 | Wizard shell + store enqueued | ui | wired | `PageRouter.php:719-720`; module map `includes/Core/AssetService.php:328` → `assets/js/onboarding/store.js` |
| 4 | Step 1 Profile UI bound to store | ui | wired | `templates/onboarding/index.php:255-306`; `store.js:144-189,298-333` |
| 4a | Username availability check | rest | wired | `store.js:172` GET `profile-slug/check` → `includes/Profile/ProfileController.php:201` |
| 4b | Avatar upload | rest | wired | `store.js:312` POST `me/avatar` → `includes/Profile/ProfileController.php:126` |
| 5 | Step 2 Spaces — list rendered from `bn_spaces` | db | wired | `templates/onboarding/index.php:63,344-392` |
| 6 | Step 2 inline "Join" button → REST | store→rest | **broken** | `store.js:222-224` POST/DELETE `spaces/{id}/members`, but route is **GET-only** (`includes/Spaces/SpaceController.php:85-93`); join endpoint is `spaces/{id}/join` (`:104-119`) |
| 7 | Step 3 People — follow inline | store→rest | wired | `templates/onboarding/index.php:480-488`; `store.js:271` POST/DELETE `users/{id}/follow` → `includes/SocialGraph/FollowController.php:34-46` |
| 8 | Step 4 Notifications — channel toggles | store→rest | wired | `templates/onboarding/index.php:546-577`; `store.js:364` PUT `me/notification-channels` → `includes/Notifications/NotificationController.php:123` |
| 9 | Finish — persist profile/slug/channels + complete | store→rest→service | wired | `store.js:334-394`; `OnboardingController.php:165-240`; `OnboardingService.php:99-201,221-232` |
| 10 | Skip at any step | store→rest | wired | `templates/onboarding/index.php:311-317`; `store.js:127-143` POST `me/onboarding/skip` → `OnboardingController.php:137-143` |
| 11 | Complete fires hook → cancels nudges, marks done | service | wired | `OnboardingService.php:231`; `OnboardingListener.php:30-55` |
| 12 | Nudge emails (24h / 72h) scheduled at register | service | wired | `OnboardingListener.php:42-87`; email template `includes/Core/Installer.php:206-209` |
| 13 | Redirect to home feed | store | wired | `store.js:387` uses `redirect_to` from `OnboardingController.php:236` (`activity_url()`) |

## First break

**Step 6 — Step 2 inline "Join space" button.** The Interactivity store posts to
`buddynext/v1/spaces/{id}/members` with `POST`/`DELETE` (`store.js:222-224`), but the
server registers that exact path as **GET only** (`SpaceController.php:85-93`). The real
join/leave endpoint is `/spaces/{id}/join` (POST/DELETE, `SpaceController.php:104-119`).
No route matches the method, so the `fetch` resolves non-OK / rejects and the optimistic
UI **rolls back** (`store.js:229-246`): the button reverts "Joined" → "Join" and an error
toast shows.

The rollback also strips the space ID from `c.joinedSpaces`. So the server-side
completion safety-net in `OnboardingController::complete()` (`:204-215`) — which *would*
join via `space_members->join()` (`SpaceMemberService.php:58`) — receives an **empty**
`spaces` array. Net result: a member who uses Step 2 as designed joins **zero** spaces.

The journey is not fully blocked (the wizard still advances and Finish completes), but the
spec's core Step-2 outcome ("joins spaces → `bn_space_members`", spec line 84) never
happens through the UI. Step 3 (Follow) uses the correct verb-bearing endpoint and works
— the tell that this is a single-path wiring slip, not a systemic issue.

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Step 2 "Join" button targets `spaces/{id}/members` (GET-only) instead of `spaces/{id}/join`; click fails, optimistic UI rolls back with an error toast, and the rolled-back state defeats the server-side join-on-complete fallback → user joins no spaces | high | confirmed-in-code | `store.js:222-246` vs `includes/Spaces/SpaceController.php:85-119` |
| Spec lists Step 2 as "Interests → space categories"; wizard ships Profile/Spaces/People/Notifications. Deliberate, documented divergence (nothing read `bn_interests`) — reconcile the locked spec wording, not the code | low | confirmed-in-code | `templates/onboarding/index.php:117-121`; spec line 38 |
| `me/onboarding/step` payload for steps 2-4 is best-effort and ignored server-side (only step 1 persists there; the dedicated endpoints carry the real data) — harmless | low | confirmed-in-code | `OnboardingService.php:73-84`; `store.js:112-118` |

Empty test accounts show "No spaces available" / "No suggestions yet" empty states
(`index.php:387-390,492-495`) — expected on an unseeded site per the seed-data rule, not a break.

## Minimal refactor plan

1. In `assets/js/onboarding/store.js` `joinSuggestedSpace()` (~line 222), change the
   request path from `'spaces/' + spaceId + '/members'` to `'spaces/' + spaceId + '/join'`.
   The existing POST/DELETE handlers at `SpaceController.php:104-119` already match this
   contract. No server change, no other action touched. Rebuild the `onboarding/store` bundle.

(One-line path fix. Leave the working Follow / Channels / Complete / Skip paths untouched.)

## App / REST client note

A pure REST/app client that drives onboarding via `me/onboarding/complete` with a `spaces`
array joins correctly server-side (`OnboardingController.php:204-215`). The break is
specific to the **web wizard's inline join button**; it does not affect an app client that
batches space IDs into the completion call.
