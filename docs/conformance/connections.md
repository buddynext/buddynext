# Conformance: Connections (Social Graph — mutual friendship)

**Feature:** Connections (request → accept / decline / withdraw / disconnect)
**Repo:** free
**Spec ref:** `docs/specs/features/01-social-graph.md` (Locked) + journey `docs/journeys/social-graph.md`
**Cross-cutting:** `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/SCALE-CONTRACT.md`, `docs/specs/features/17-roles-permissions.md`
**Live-walk URL:** http://buddynext-dev.local/members/varundubey/
**Verdict:** usable-leave-as-is

---

## Journey traced (web member happy path)

A logged-in member opens another member's profile, sends a connection request, the
recipient accepts/declines, either party can withdraw/disconnect, and accepted
connections appear on the connections sub-page.

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Profile loads with viewer-aware connect cluster (Connect / Pending / Accept+Decline / Connected) | ui | wired | `templates/parts/profile-hero.php:407-452` |
| Connection state computed server-side (is_connected / pending / received) | service | wired | `templates/profile/view.php:51-84`, `includes/SocialGraph/ConnectionService.php:339-370` |
| Interactivity context supplies profileUserId + restNonce + showConnect | store | wired | `templates/profile/view.php:317-345` |
| "Connect" click → POST /users/{id}/connect | store | wired | `assets/js/profile/store.js:1092-1109` |
| Accept click → POST /users/{id}/connect/accept | store | wired | `assets/js/profile/store.js:1126-1140` |
| Decline click → POST /users/{id}/connect/decline | store | wired | `assets/js/profile/store.js:1142-1155` |
| Pending withdraw / disconnect → DELETE /users/{id}/connect | store | wired | `assets/js/profile/store.js:1111-1124, 1157-1171` |
| Routes registered + auth-gated | rest | wired | `includes/SocialGraph/ConnectionController.php:32-89, 219-229`; `includes/REST/Router.php:65` |
| Block guard on send (403 if either party blocks) | rest/service | wired | `includes/SocialGraph/ConnectionController.php:101-107` |
| Service transitions pending→accepted / declined, deletes on withdraw/disconnect, fires hooks | service | wired | `includes/SocialGraph/ConnectionService.php:47-317` |
| Rows in wp_bn_connections (status pending/accepted/declined) | db | wired | `includes/SocialGraph/ConnectionService.php:90-99, 128-138, 201-207, 252-256` |
| Accepted connections list page renders member cards | ui+db | wired | `templates/profile/connections.php:49-77, 118-259`; `includes/Core/PageRouter.php:1452` |
| Profile store module registered/enqueued | store | wired | `includes/Core/AssetService.php:316, 331` |

Secondary reusable surface (member cards / space rosters): the standalone
`templates/partials/connection-button.php` and `templates/blocks/connection-button.php`
bind to the `buddynext/connection-button` Interactivity store for the same lifecycle.

---

## First break

none — journey complete. Every link (UI control → store action → REST route →
service → DB) is present and bound on the profile entry point, and the accepted-
connections page reads the same table.

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Journey doc describes `POST /connect` as a send+withdraw toggle, but controller/UI use `POST` to send and `DELETE` to withdraw/disconnect. Doc drift only — the live store consistently uses DELETE and matches the controller, so the journey is not broken. | low | confirmed-in-code | `docs/journeys/social-graph.md:103-108` vs `includes/SocialGraph/ConnectionController.php:42-48` and `assets/js/profile/store.js:1111-1124` |
| `connect` action sends no optional `note` param from the profile cluster, though the service + controller support a LinkedIn-style note (max 280 chars). No prompt UI exists on this surface. Not a break — note is optional and defaults to empty. | low | confirmed-in-code | `includes/SocialGraph/ConnectionController.php:109-113`, `assets/js/profile/store.js:1092-1109` |

Both items are cosmetic/documentation-level and do not stop a real user from
completing the connect → accept/decline → disconnect journey.

---

## Minimal refactor plan

EMPTY — usable, leave as is. No code changes required to make the journey usable.
(If desired separately from this audit, the journey doc's withdraw example could be
corrected to `-X DELETE` to match the shipped contract, but that is a docs edit, not
a feature fix.)

---

## App / REST client note

The full lifecycle is also reachable headlessly: `POST /connect`, `POST /connect/accept`,
`POST /connect/decline`, `DELETE /connect`, plus `GET /me/connections` and
`GET /me/connection-requests`, all under `buddynext/v1` and gated by `is_user_logged_in`.
App and web journeys are both served.

---

## Live-walk pointer

Open http://buddynext-dev.local/members/varundubey/ as a logged-in member viewing a
*different* member (the cluster returns early on own profile / when blocked). Seed at
least one other member and, for the Accept/Decline state, an inbound pending row in
`wp_bn_connections` — on an empty test account the cluster correctly shows only
"Connect". Walk light + dark.
