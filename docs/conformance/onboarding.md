# Conformance — Onboarding (Member Wizard)

**Feature:** Onboarding (Member Onboarding Wizard)
**Repo:** free
**Spec ref:** `docs/specs/features/10-onboarding-setup-wizard.md` (Locked, 2026-03-19)
**Journey/UX:** `docs/v2 Plans/v2/onboarding.html`
**Live-walk URL:** http://buddynext-dev.local/onboarding
**Verdict:** usable-leave-as-is

---

## Scope verified

This dossier traces the **Member Onboarding Wizard** happy path (the spec's
second wizard and the surface backed by `includes/Onboarding/` +
`templates/onboarding/index.php`). The Admin Setup Wizard
(`includes/Onboarding/SetupWizard.php`) and Admin Bulk Invite
(`InviteController`/`InviteService`) are separate surfaces, out of scope for
this member-journey walk.

---

## Journey chain (entry -> outcome)

A new member lands on the wizard after registration/verification, fills four
steps (Profile, Spaces, People, Notifications), and finishes to the activity
feed. Each step is reactive via the Interactivity API store
`@buddynext/onboarding`; the authoritative completion transaction is server-side.

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Registration redirects new user into wizard (verify off) | service | wired | includes/Auth/AuthController.php:428 |
| Verify-link page links verified user into wizard (verify on) | ui | wired | templates/auth/verify.php:73,169 |
| /onboarding route -> template; guest gate + already-complete redirect | rest | wired | includes/Core/PageRouter.php:190,203,725,900 |
| Wizard template renders 4 steps + live preview, binds store | ui | wired | templates/onboarding/index.php:144-643 |
| Onboarding store (next/prev/skip/finish, join, follow, channels) | store | wired | assets/js/onboarding/store.js:42-399; enqueue PageRouter.php:726; map AssetService.php:328 |
| Step 1 Profile — display name + bio saved | rest+service | wired | PUT /me/profile includes/Profile/ProfileController.php:158-168; OnboardingService::save_profile includes/Onboarding/OnboardingService.php:99 |
| Step 1 — username/handle live check + save | rest | wired | GET /profile-slug/check + PUT /me/profile-slug ProfileController.php:175-203; store.js:153-189,355-361 |
| Step 1 — avatar upload | rest | wired | POST /me/avatar ProfileController.php:126; store.js:305-336 |
| Step 2 Spaces — join inline | rest+db | wired | POST/DELETE /spaces/{id}/join includes/Spaces/SpaceController.php:51-56; store.js:200-250 |
| Step 3 People — follow inline | rest+db | wired | POST/DELETE /users/{id}/follow includes/SocialGraph/FollowController.php:34-43; store.js:251-300 |
| Step 4 Notifications — channel prefs | rest+service | wired | PUT /me/notification-channels includes/Notifications/NotificationController.php:114-123; save_channels OnboardingService.php:184 |
| Finish — authoritative server-side persist of all step payloads | rest+service | wired | OnboardingController::complete includes/Onboarding/OnboardingController.php:165-240 (registered includes/REST/Router.php:64) |
| Mark complete + fire completion hook (first call only) | service | wired | OnboardingService::finish OnboardingService.php:221-232 |
| Redirect to activity feed | ui | wired | store.js:390; redirect_to via PageRouter::activity_url() OnboardingController.php:236 |
| Skip path (any step / final skip) | store+rest | wired | POST /me/onboarding/skip OnboardingController.php:137; store.js:127-143 |
| Abandonment nudges (+24h/+72h), cancelled on complete | service+db | wired | OnboardingListener.php:42-87; email seeded Installer.php:206; catalogue NotificationPrefCatalogue.php:307; message NotificationMessageService.php:324 |

**First break:** none — journey complete.

---

## Spec-vs-code divergences (documented, not journey breaks)

1. **Step 2 "Interests" replaced by "Notifications."** Spec lists member step 2
   as Interests -> space-category mapping. The template removed the Interests
   step because nothing downstream consumed `bn_interests`, and substituted a
   Notifications channel-preference step (documented inline at
   templates/onboarding/index.php:117-121). The four-step flow remains complete
   and usable; the spec text is stale relative to the shipped UX.
2. **Completion hook name.** Spec prose says `buddynext_onboarding_complete`;
   code fires `buddynext_onboarding_completed` (OnboardingService.php:231).
   Internally consistent — the nudge-cancel listener subscribes to the same name
   (OnboardingListener.php:32). Any external WBGamification "Welcome" badge
   consumer must hook the `_completed` form; no in-repo (free) consumer exists,
   which is expected since gamification is a separate plugin via GamificationBridge.

---

## UX gaps

None that stop the journey. The two items above are spec-text/doc drift, not
usability breaks. The WBGamification "Welcome badge" award named in the spec has
no consumer in the free repo; this is by design (gamification is a separate
plugin via the bridge) and cannot be confirmed from this repo alone —
needs-live-verification only on a site running wb-gamification.

---

## Minimal refactor plan

Empty — usable, leave as is. (Optional doc hygiene only, not code: update the
locked spec to reflect the shipped Notifications step and the `_completed`
hook name.)

---

## App / REST client note

All four steps and finish/skip are backed by real REST endpoints with matching
methods, so an in-app/REST client can drive the same flow without the web UI.
No api-only gaps: every endpoint also has a bound web control.
