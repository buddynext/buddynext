# BuddyNext Bugs — Resume Plan

> STATUS: Bugs column (9990191646) has **24 open cards** as of 2026-06-16 — a fresh
> batch loaded after the previous queue was cleared. **Basecamp bugs are priority 1:
> clear this column before resuming the parked non-bug threads (bottom of file).**
>
> Snapshot of the prior cleared wave (for reference, all DONE → Ready for Testing):
> message gating 8595685 · social-7 settings 4245363 · comment @mention 6684924 ·
> Reign left-panel 0d0ee25 · reactions dead switch c4cf41e · integrations install e4b6663.

Basecamp project **47683682**. Bugs column **9990191646** → Ready for Testing **9990094424**.
Scope: **Bugs column ONLY** (Possible Bug / other columns out of scope).
Repos: free `/Users/vapvarun/dev/repos/buddynext` · pro `/Users/vapvarun/dev/repos/buddynext-pro`
(free is live via symlink to the Local site at
`/Users/vapvarun/Local Sites/buddynext-dev/app/public`). Branch: **master** (no release branches).

## Per-card workflow (non-negotiable)
1. **Re-verify root cause vs current code** — grep ALL surfaces; the card title may be wrong or
   already fixed. Don't "fix" a correct guard.
2. **Fix** in place (refactor, don't stack overrides).
3. **Verify MYSELF** — browser at 390px light + dark for any UI; `wp eval`/DB for backend;
   Mailpit `http://localhost:10010` for email. Code-quality passing != feature done.
4. **Commit + push** to master.
5. **Comment the card** with the fix summary + commit ref.
6. **Move card -> Ready for Testing (9990094424).**

Local verify: `?autologin=1` (admin user 1; autologin SKIPS when already logged in — to test
another user, log out first or use `wp eval`). Lint: `php -l` (filter imagick/opcache/Zend),
`node --check`. Versions stay in lockstep at **0.4.0-beta1** (free+pro) — never bump.

---

## Cluster A — Moderation (5)
- **9999965041** Permission Error Displayed After Removing a User from the Moderator Role.
- **9999962752** [Free] WORK INTAKE — `PUT /appeals/{id}/approve` and `/deny` return 200 success
  for **non-existent appeal IDs** (should 404). Contract-audit finding.
- **9999962684** [Free] WORK INTAKE — `GET /users/{id}/suspension` returns **already-lifted**
  suspensions as still active.
- **9999957860** Pre-moderated posts are NOT added to the moderation queue.
- **9999960712** [Medium] Moderation: Auto-Hide Threshold setting **not enforced**.

## Cluster B — Spaces (6)
- **9999965028** Spaces Filter: no proper user message when there are **no filter results**.
- **9999925717** Decline actions FAIL on Space join requests.
- **9999922539** Approve actions FAIL on Space join requests. (likely shares root cause w/ above)
- **9999916913** Archive Space action MISSING from the Danger Zone section.
- **9999960647** [Medium] Spaces: Max Sub-Spaces setting **not enforced**.
- **9999960594** [Medium] Spaces: Creation Role setting **not enforced**.

## Cluster C — Privacy / settings enforcement (4)
- **9999961041** [Medium] Privacy: Data Export / Account Deletion gates **missing** (3 settings).
- **9999960948** [Medium] Privacy: Data Retention Days **not enforced** (hardcoded 90).
- **9999960878** [Medium] Privacy: Cookie Consent Banner **not implemented**.
- **9999960808** [Medium] Privacy: Google Indexing setting **not applied**.

## Cluster D — Notifications / Email / Integrations (4)
- **9999961137** [Medium] Notifications: Default prefs **not applied at registration** (6 settings).
  (overlaps the Settings-IA notif work — check `bn_notification_prefs` seeding on user_register.)
- **9999961306** [Low] Notifications: Digest Frequency setting **vestigial** (read never enforced).
- **9999961236** [Medium] Email Editor: missing table-existence check (guard before query).
- **9999961353** [Low] Integrations: Jetonomy Feed Sync setting **stale** (saved, not applied).

## Cluster E — CSS / design tokens (2)  — both touch `assets/css/bn-shell.css`
- **9999965005** bn-shell.css Bug 1 — hardcoded font sizes (11/14/10/12/13px).
- **9999918448** bn-shell.css — hardcoded px/hex/font-family violate the design-token golden rule.
  (Do these two together; replace raw values with OKLCH/token vars. No AI-gradient colors.)

## Cluster F — Core / misc (3)
- **9999955355** Guest users cannot access Activity & Explore page even when the setting is enabled.
- **9999920529** [Free] Textdomain loaded before `init` triggers debug notice in WP 6.7+
  (load `buddynext` textdomain on `init`; mirror the Pro fix already in `buddynext-pro.php`).
- **9999911666** Edit Profile: cover-image upload & country-field update issues.

**Suggested order:** F (quick correctness: textdomain, guest access) -> E (mechanical token pass) ->
B (Spaces, shared root causes) -> A (Moderation) -> C (Privacy gates) -> D (notif/email/integrations).
Each card is independent; re-verify root cause before touching code.

---

## PARKED — non-bug threads (resume only AFTER the Bugs column is clear)
These are in-flight but explicitly lower priority than Basecamp bugs:
1. **Settings hub IA — finish (#24):** structural work landed (hub routing, tab shell, 8 sections
   relocated out of profile-edit, notif de-dup, In-app/Email column UX). Remaining: PageRouter
   route test, browser verify desktop+390px light/dark, commit. Plan:
   `~/.claude/plans/distributed-spinning-penguin.md`.
2. **QA MCP manifest v2 — rest enrich (#35):** capture each REST route's `requestArgs` + `gatedBy`
   (cap/setting) in the manifest generator.
3. **QA MCP manifest v2 — hooks<->listeners extractor (#36):** map `do_action`/`apply_filters` emit
   points to their listeners so the engine flags unwired hooks.
   (#35/#36 spec: `~/.mcp-servers/wp-plugin-qa-mcp-server/docs/superpowers/specs/2026-06-15-functionality-self-correction-redesign.md`)
