# Flow Verification — 2026-06-20

A worked record of verifying "customer-facing broken" claims against the **running** site,
and the method to repeat it. Read this with `docs/journeys/README.md` → *Verification discipline*.

**Site:** `http://buddynext.local/` (this machine; LocalWP). Older runbooks say `buddynext-dev.local` — stale, use `buddynext.local`.
**Auth:** append `?autologin=1` (admin) or `?autologin=member1` to any URL.
**Tools used:** `wp` (LocalWP wp-cli), `curl`, the live REST route index. Playwright MCP was **not available** this session — runtime verification was done at the live-REST + rendered-HTML layer instead (a valid substitute for these read-path / wiring checks; a visual pass still needs Playwright).

---

## Context

A static (grep-only) audit flagged five issues as customer-facing breakage. The rule
"verify root cause before fixing" (`CLAUDE.md`) plus a live check turned **all five** into
false positives. None are bugs. This file exists so they are **not re-filed** and so the
verification method is reusable.

## Results — 5 claims, 5 false positives

| # | Claim | Verdict | Live evidence | Why grep was fooled |
|---|---|---|---|---|
| 1 | Appeals approve/deny buttons 404 | NOT A BUG | `POST /appeals/{id}/resolve` present in live route index; JS calls exactly that (`moderation/store.js:172`) | `ModerationController.php:401` registers `/resolve`; grep matched only legacy `/approve`,`/deny` at 638/661 |
| 2 | Messages tab dead without WPMediaVerse | NOT A BUG | `/messages/` route bounces when engine absent or DM off | guard is in `PageRouter.php:267`, not at the nav-item grep site |
| 3 | "Show desktop sidebar rail" toggle dead | NOT A BUG | option ON → `bn-app__rail` renders; OFF → 0 nodes (HTML fetch) | read via helper `buddynext_community_rail_enabled()` (`hub-shell.php:89`), not a literal `get_option(...)` |
| 4 | "Show mobile bottom nav" toggle dead | NOT A BUG | option ON → 7 `bn-mobile-nav` nodes; OFF → 0 (HTML fetch) | read via helper `buddynext_community_mobile_nav_enabled()` (`hub-shell.php:140`) |
| 5 | Un-restrict button 404 | NOT A BUG | live route `DELETE /users/{id}/restrict` exists (POST+DELETE); JS calls `DELETE /users/{id}/restrict` (`social/relation-remove.js:65`) | one-controller grep missed the registration |

## Exact commands run (repeat these)

```bash
BASE=http://buddynext.local

# Claims 1 & 5 — does the endpoint actually exist, with which methods?
curl -s "$BASE/wp-json/buddynext/v1" | python3 -c "import sys,json;[print(r) for r in sorted(json.load(sys.stdin)['routes']) if 'appeal' in r or 'restrict' in r]"

# Claims 3 & 4 — flip the gating option, fetch logged-in HTML, count the markup, then RESTORE
curl -s -c /tmp/bn.txt -o /dev/null -L "$BASE/?autologin=1"
wp option update buddynext_enable_community_rail 0
curl -s -b /tmp/bn.txt -L "$BASE/activity/" | grep -c 'bn-app__rail'        # -> 0 (hidden, toggle works)
wp option update buddynext_enable_community_rail 1
curl -s -c /tmp/bn.txt -o /dev/null -L "$BASE/?autologin=1"
curl -s -b /tmp/bn.txt -L "$BASE/activity/" | grep -c 'bn-app__rail'        # -> 1 (rendered)
wp option delete buddynext_enable_community_rail                            # restore default (absent = on)
# repeat for buddynext_enable_community_mobile_nav / grep 'bn-mobile-nav'
```

State was restored to pristine after the run (`wp option delete` on both keys).

## What IS genuinely true (coverage gaps, not breakage)

These are not broken, but **no journey exercises them** — so the next static/QA flag can't be
quickly confirmed or denied. They are the real work, prioritized by blast radius:

1. **Auth: login / nonce-refresh / 2FA / password-reset** — no journey walks them. Consequence of a regression: lockout. Add to `auth-verification.md`.
2. **GDPR: `GET /me/data-export`, `DELETE /me/account`** — routes exist (`ProfileController.php:479,489`), untested + privacy-gated. Legal exposure. Add a privacy journey.
3. **Large-dataset pagination** — list endpoints (`/members`, `/feed/home`, `/me/notifications`) never seeded to 2000+ rows and asserted for `LIMIT`/`OFFSET`/`X-WP-Total`. Add a scale step per list journey.
4. **Admin-config → member-effect** — only `onboarding.md` flips an admin option. Every gating setting needs the item-12 step (feature toggle OFF → route 403 + nav item gone is the highest-value one).
5. **Frontend action wiring** — no journey maps controls to routes/nonces (item 11). `social-graph.md` is the first upgraded to the new contract; use it as the template.

---

## Reactor "see who reacted" popover — VERIFY IN A REAL BROWSER (not a confirmed bug)

While capturing doc images, the **reactors popover** (`.bn-reactors-popover`, opened by
`.bn-post-card__reactors-trigger`) behaved oddly under **headless** Chromium on a
single-post permalink (`/p/28/`, 5 seeded reactions):

- The popover header renders the real count ("5 reactions") server-side. Correct.
- Its list fetch `GET /buddynext/v1/reactions/list?object_type=post&object_id=28&limit=100`
  returns **200** with real items (Bob Martinez, etc. — confirmed by a manual `fetch` with
  the page's `reactNonce`). So the endpoint + nonce + data are all fine.
- BUT the popover `<ul class="bn-reactors-popover__list">` stays **empty** after the 200,
  and the popover re-acquires `hidden` (auto-closes) on its own.

This is the **same class** as the earlier hashtag "dead buttons" false-positive: an
Interactivity-API hydration/async-append timing quirk that only shows under headless and
**works in a real browser**. Per "verify root cause / no guess fixes", this is therefore
logged as **needs real-browser confirmation**, NOT filed as a bug and NOT fixed.

A stale-session 403 was also seen first (empty `X-WP-Nonce` → `rest_cookie_invalid_nonce`);
a fresh `?autologin=alice` load cleared it (200). That was a long-session artifact, not a defect.

**To confirm/deny (human, real browser):** open any post permalink with >1 reaction, click the
reaction-count summary, and check the popover lists the reactors with avatars. If it does, no
action. If the list is genuinely empty after a 200, trace the post-`yield` append in
`assets/js/feed/store.js:toggleReactors()` (the `items.forEach(... buildReactorRow ...)` at
~line 1185) against the reactive re-render that toggles `state.reactorsHidden`.

Doc impact: the reactions doc uses `reaction-picker.png` (the picker action) as its close-up;
no empty-popover image was shipped (would read as broken). Revisit once confirmed in a real browser.

### RESOLVED — reactor popover was a real bug (wrong list-container scope)

Update: the owner confirmed in a real browser that the popover showed only the
count ("N reactions") with no people — so this was a **real defect**, not the
headless quirk first suspected. Root cause, verified live:

- Clicking the reactor trigger opened the popover but fired **zero**
  `GET /reactions/list` requests.
- In `assets/js/feed/store.js::toggleReactors()`, the list container was resolved
  as `const card = getElement()?.ref; const listEl = card.querySelector('.bn-reactors-popover__list')`.
  `getElement().ref` is the **trigger button**, but the popover (and its `<ul>`)
  is a **sibling** of the button inside `.bn-post-card__reactors-wrap` — so
  `listEl` was always `null`, hitting `if (!listEl) return;` **before the fetch**.
  The list therefore never loaded on any post, anywhere.

Fix: scope the lookup to the enclosing wrap —
`const wrap = (getElement()?.ref || trigger)?.closest('.bn-post-card__reactors-wrap'); const listEl = wrap?.querySelector('.bn-reactors-popover__list');`

Verified live after the fix: one `/reactions/list` request fires, the popover
lists all reactors (avatar + name + their emoji). Doc image `reactors-popover.webp`
added to `community/04-reactions`.
