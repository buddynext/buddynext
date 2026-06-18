# UX Bugs Audit — Team 2 (2026-06-18)

The 14 UX cards claimed from the Bugs column, audited against the **Facebook usability bar**. Card text is treated as a QA *suggestion*; each verdict is from the real code, not the card wording. Per the "QA is not the source of truth" rule, several cards are bounced or re-scoped with code references.

**Verdict key:** REAL (fix as understood) · PARTIAL (fix part) · NEEDS-DIFFERENT-FIX (real defect, QA framing wrong) · NON-ISSUE (bounce to QA).

---

## Tier 1 — clear REAL fixes, small, do first

| Card | Verdict | Root cause | FB-bar fix | Sev |
|---|---|---|---|---|
| **10009392816** DM "Share a photo" modal flashes on Messages load | REAL (×4 modals) | DM modal roots ship with NO server-side hidden state; `.bn-modal-backdrop` (`bn-base.css:2148` fixed/inset-0/flex/z-10000) paints full-screen until the iAPI applies `is-hidden` post-hydration. `templates/parts/dm-media-modal.php:22`, `templates/messages/native.php:239-249` (all 4: media/compose/group/delete) | Ship the `is-hidden` **class** in the initial server markup of all 4 DM modal roots (matches the `data-wp-class--is-hidden` binding). Same FOUC class as the autofocus fix. Fix all four together. | **HIGH** S |
| **10009461221** Online-status dot cropped + **10009297132** (dot half) | REAL — **one fix, two cards** | `bn-profile.css:812` `.bn-pf-avatar-wrap .bn-avatar[data-size="2xl"]{overflow:hidden}` (spec 0-3-1) beats the intended `bn-base.css:2003 [data-presence]{overflow:visible}` (0-2-0); clips the presence `::after` dot | `.bn-pf-avatar-wrap .bn-avatar[data-presence]{overflow:visible}` (+ `z-index:1` on the dot, keep `img{border-radius:50%}`). Generalize, don't patch only 2xl. (QA's "z-index" theory is wrong — it's `overflow`.) | med S |
| **10009466087** DM timestamp "overlaps" bubbles | NEEDS-DIFFERENT-FIX (not an overlap) | Pro paints sent-meta white: `wpmediaverse-pro/assets/css/messaging.css:538` `color:rgba(255,255,255,.7)`. The `__meta` row is a static sibling *below* the bubble on the panel bg → white-on-light, illegible. Free is correct. | In Pro, sent-meta `color: var(--mvs-chat-text-secondary)` (match Free). One line, no layout change. **Bounce the "overlap" wording.** | med S |
| **10009290739** Copy-link low contrast (a11y) | REAL (light mode) | Share-modal Copy-link `data-variant="ghost"` → resting `--bn-ink-2` on `--bn-surface` ≈4.5:1 borderline AA; full color only on hover. `templates/partials/share-modal.php:92`, `bn-base.css:1869` | `.bn-share-modal__copy` explicit resting `color: var(--bn-ink)` (or `data-variant="secondary"`). | med S |
| **10009306053** Composer icons show border on hover | REAL | `.bn-composer__tool:hover` (`bn-feed.css:2198`) never re-asserts `border:none`; host theme's `button:hover` border wins | Add `border:0` (lock padding) to the hover rule — mirror the existing `.bn-post-card__action-btn:hover` "hover glitch" fix at `bn-feed.css:838`. | med S |
| **10009287447** Reply-form buttons misaligned | REAL | JS-built reply form (`feed/store.js:615`); container `align-items:flex-end` + Reply `align-self:flex-start` (`bn-feed.css:1370,1629`) vs zero-pad Cancel text link → opposite edges | Mirror the already-correct edit form (`bn-feed.css:1614` `align-items/self:center`). | low S |
| **10009321708** Setup Wizard checkbox overlaps circular selector | REAL | Pages step reuses option-list classes in a container that breaks both the hide rule and the checked-state rule: `.bn-wizard__option-input` has no `appearance:none` (`bn-onboarding.css:1043`); square override is scoped to a variant the Pages list doesn't use; checked fill keys off `.bn-wizard__option` which `<li.bn-wizard__page>` lacks. Native checkbox bleeds through the circular mark. `SetupWizard.php:971` | `appearance:none` on the input + a scoped Pages selector so the native box is fully suppressed and the mark gets its fill from `input:checked + .bn-wizard__option-mark`. One indicator per option. | med S–M |

---

## Tier 2 — REAL, but bigger / a product decision (M effort)

| Card | Verdict | The real ask | FB-bar direction |
|---|---|---|---|
| **10005083702** Connect not 1-click (forced "Add a note") | REAL — **card body is stale** | The note dialog already exists in JS (`shell/dialog.js:388`, called from `social/follow-store.js:268`, `members/store.js:492`, `profile/store.js:1542`). The card body says "add a note input" — already there; do NOT re-add. | LinkedIn *does* interpose this dialog; Facebook/X are pure 1-click. The genuine defect is **no opt-out**. Add a site-owner setting + `buddynext_connection_require_note` filter; when off, connect is true 1-click (POST empty note, no modal). Gate the 3 JS call sites. **Update the card** so the dev doesn't re-add the input. |
| **10009462282** Message controls "disappear on hover" | NEEDS-DIFFERENT-FIX — **QA premise false** | The menu is click/right-click toggled, not hover-revealed (`chat-message.php:24`, `messaging.js:990`); there is no hover-bridge to fix. Real friction: menu anchored `top:-40px` clips above the scroll area; right-click trigger is non-obvious. | Don't add a hover bridge. Add a discoverable hover "⋯" that opens on click (FB/IG), and flip the anchor to `top:100%` when there's no room above. Needs a seeded conversation to confirm the collision before coding. |
| **10009345084** Spaces "My Spaces" tab stays active | NEEDS-DIFFERENT-FIX — **QA framing wrong** | Scope (My Spaces) and visibility (Open/Private/Secret) are legitimately independent and *compose* (`spaces/store.js:2042` re-injects `mine=1`). They're rendered as identical sibling chips in one tablist, so it reads as broken. QA's "clear My Spaces on tab click" would break the intended compose. | Visually separate the scope dimension (own segmented toggle, distinct from the type chips) so it doesn't read as a peer tab; and make `resetFilters` clear `bn_scope` (`store.js:1638`) so Reset truly resets. |

---

## Tier 3 — push back / minor / NON-ISSUE

| Card | Verdict | Note |
|---|---|---|
| **10009336382** Explore-members card style | PARTIAL — **reject card pts 4–7** | Only real drift: `.ec-card:hover` darkens `border-color` (`bn-explore.css:171`) while the directory only lifts. Fix: drop the border-color change on the member explore card (keep lift+shadow). **Keep the ghost "View" button** — a quieter secondary action beside a filled Follow is correct FB/LinkedIn hierarchy; matching two filled buttons is worse. |
| **10009297132** (cover-radius half) | NON-ISSUE | `.bn-pf-hero` is intentionally square (no radius) to avoid clipping the absolutely-positioned More-Options dropdown; `.bn-pf-cover` already has `overflow:hidden`. QA's "add overflow to header" would clip the dropdown — wrong. Only the dot half is real (Tier 1). |
| **10009228576** Logo + collapse spacing | NON-ISSUE (optional polish) | `bn-shell.css:278-337` layout is correct (flex gap + `margin-inline-start:auto` toggle + shrinkable logo). Couldn't reproduce (no logo set; DB writes out of scope). Optional: cap `.bn-rail__logo` max-inline-size to guarantee a gutter with the widest logos. |
| **10009288808** Logo overlapping collapse button | NON-ISSUE — **bounce** | The "hover border overlaps logo" mechanism doesn't exist: toggle hover is background-only, `border:0` (`bn-shell.css:324`). Duplicate of 10009228576. Bounce to QA with the code reference. |

---

## Cross-cutting notes
- **Two cards share one fix:** 10009461221 + the dot half of 10009297132 → `bn-profile.css:812` overflow.
- **Two cards are the same rail-logo non-issue:** 10009228576 + 10009288808.
- **Four cards have a factually wrong QA mechanism** — bounce/re-scope, don't implement as written: 10009466087 ("overlap"), 10009462282 ("hover-bridge"), 10009288808 ("hover border"), 10005083702 ("add note input").
- **Plugin split:** composer/reply/copy-link/dot/wizard/spaces/explore/rail + DM-modal shells → `buddynext`; sent-timestamp color → `wpmediaverse-pro`; message-menu trigger/anchor → `wpmediaverse` + `-pro`.

## Recommended order
1. **Tier 1 batch** (7 cards, all S) — quick, high-confidence, biggest usability lift. The DM modal flash is HIGH (core surface looks broken). One commit per surface area.
2. **Tier 2** (3 cards) — each needs a small product call (connect opt-out default; message-menu pattern; spaces scope UI). Confirm direction, then implement.
3. **Tier 3** — make the 1 small explore tweak; bounce the 3 NON-ISSUE/duplicate cards to QA with the code references above (move them out of Team 2, comment why).
