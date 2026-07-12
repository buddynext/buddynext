# CLAUDE.md — BuddyNext

Engineering guidance for AI agents and contributors working in this repository.
These are the rules the code in this repo is held to — read before changing anything.

## Where things live

| What | Where |
|---|---|
| Plugin code | `includes/` (PHP) · `templates/` · `assets/` · `blocks/` |
| Public documentation — usage guides, developer guide, REST API | `docs/website/` |
| Tests | `tests/` (mirrors `includes/`) |
| Quality tooling | `bin/` · `.githooks/` |
| Third-party runtime code (committed, ships in the zip) | `libs/` |

`docs/website/` is the only documentation this repo carries; product planning, QA
and audit material is maintained separately and is intentionally not part of the
public repository.

---

## What Is BuddyNext

Enterprise-grade social community platform for WordPress (free + pro). Owned by Wbcom Designs.

- **Plugin path:** `wp-content/plugins/buddynext/`
- **Namespace:** `BuddyNext\*` (free) / `BuddyNextPro\*` (pro)
- **REST namespaces:** `buddynext/v1` (free) · `buddynext-pro/v1` (pro)
- **Bootstrap hook:** `plugins_loaded:15` → `BuddyNext\Core\Plugin::init()` → fires `buddynext_loaded`
- **PHP:** 8.1+ · **WP:** 6.9+ (Abilities API required)

**Model = mainstream social: Facebook, X, LinkedIn.** A lean core that does the
mainstream-social basics excellently and stays fast at 100k members. A request that
adds an option, branch, or feature those platforms don't have is out of scope by
default. Speed and stability come first on every change.

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| PHP | 8.1+ strict types everywhere |
| WordPress | 6.9+ |
| Autoloader | Hand-written PSR-4 (`BuddyNext\` → `includes/`) in `buddynext.php`; runtime never touches Composer. `vendor/` is dev-only and gitignored. |
| Architecture | DI Service Container |
| Permissions | WordPress Abilities API — `buddynext_can( $user_id, 'ability-slug' )` |
| Frontend reactivity | WordPress Interactivity API — no React, no build step |
| Async jobs | Action Scheduler |
| REST API | `buddynext/v1` (free) + `buddynext-pro/v1` (pro) |
| Real-time (free) | REST polling — 5s active, adaptive |
| Tests | PHPUnit 9 + WP test suite |
| Code quality | WPCS (WordPress standard) + PHPStan level 5 |
| Templates | PHP, theme-overridable via `{theme}/buddynext/` |
| CSS | Custom properties (tokens) — no Tailwind, no Bootstrap |
| Fonts | Inter (body) + Plus Jakarta Sans (display) |

---

## Plugin Architecture

### Routing surfaces — `/feed/` is NOT the activity feed

Three things share the word "feed" — never conflate them:

- **`/feed/`** = **WordPress core RSS** (`Content-Type: application/rss+xml`). Not ours.
- **`/activity/`** = the **community activity feed**; 302-redirects to **`/activity/explore/`**. Backed by `FeedService::home_feed()` and `explore_feed()`.
- **Internal hub key `'feed'`** — in `Core/PageRouter.php` the activity page is keyed `'feed'` but its **public slug is `activity`** (`activity_url()`). The code says "feed"; the URL says `/activity/`.

Test the activity feed, composers, mention-typeahead and the "N new posts" pill at
**`/activity/`** — never `/feed/`.

### WordPress core stays untouched (non-negotiable)

BuddyNext **adds alongside** WordPress core — it never overrides, disables, shadows,
or breaks core behaviour. Core must keep working exactly as a vanilla install.

- **Never claim a core URL.** `/feed/`, `/comments/feed/`, `/wp-json/wp/v2/*`, `/wp-login.php`, `/xmlrpc.php`, author/date/category archives stay core. BuddyNext uses its **own** slugs (`/activity/`, `/spaces/`, …) and its **own** REST namespace — never `wp/v2`.
- **Add rewrite rules and query vars additively.** Never remove or reorder core ones. Don't blanket-override `template_include`, `request`, `parse_query`, or `pre_get_posts` outside our own surfaces.
- **Don't disarm core features.** No unconditional `remove_action`/`remove_filter` on core feeds, oEmbed, REST, cron, or the admin bar. Gate every hook to our own post types / pages / conditions.
- **Verify after any routing/rewrite/query-var change:** `/feed/` still returns `application/rss+xml`, a normal WP page still renders, and `/wp-json/wp/v2/posts` still responds.

### Bootstrap Chain
```
plugins_loaded (priority 10) → WPMediaVerse, Jetonomy (addons)
plugins_loaded (priority 15) → BuddyNext\Core\Plugin::init() → buddynext_loaded
plugins_loaded (priority 20) → BuddyNext Pro hooks
                              → Bridge classes (if addons active)
```

### Service Container
```php
// Bind
$container->bind( 'social_graph', fn() => new \BuddyNext\SocialGraph\FollowService() );

// Resolve (singleton)
$container->get( 'social_graph' );

// Global helper
buddynext_service( 'social_graph' );
```

### Permission System
Single entry point for every gate:
```php
buddynext_can( $user_id, 'view-space', [ 'space_id' => $space_id ] )
buddynext_can( $user_id, 'post-in-feed' )
buddynext_can( $user_id, 'send-dm', [ 'recipient_id' => $recipient_id ] )
```

### Abilities Registration
All abilities registered at boot via the WordPress Abilities API:
```php
wp_register_ability( 'buddynext-view-space',   [ 'label' => 'View Space' ] );
wp_register_ability( 'buddynext-post-in-feed', [ 'label' => 'Post in Feed' ] );
```

---

## Database Tables

`bn_*` tables, all created in `Installer::run()` via `dbDelta()`.

| Table | Domain |
|-------|-------|
| `bn_activity_log` | Core |
| `bn_follows`, `bn_connections`, `bn_blocks` | Social Graph |
| `bn_posts`, `bn_poll_options`, `bn_poll_votes`, `bn_bookmarks`, `bn_shares` | Activity Feed |
| `bn_profile_fields`, `bn_profile_values` | Profiles |
| `bn_search_index` | Search |
| `bn_spaces`, `bn_space_members`, `bn_space_categories` | Spaces |
| `bn_notifications`, `bn_notification_prefs` | Notifications |
| `bn_email_templates`, `bn_email_log` | Email |
| `bn_reactions`, `bn_comments` | Reactions + Comments |
| `bn_hashtags`, `bn_post_hashtags`, `bn_hashtag_follows` | Hashtags |
| `bn_reports`, `bn_mod_log`, `bn_user_strikes` | Moderation |
| `bn_verify_tokens` | Auth |

DM tables live in WPMediaVerse (`mvs_conversations`, `mvs_messages`, …) — BuddyNext
is the UI layer only for DM.

---

## File Placement Rules

### Domain Principle

Every feature domain owns its full stack in one folder:

```
includes/{Domain}/
  {Domain}Service.php        ← business logic
  {Domain}Controller.php     ← REST endpoints
  {Domain}Listener.php       ← WordPress hooks (implements ListenerInterface)
```

If a new file's name starts with the domain prefix, it goes in that domain folder.
Otherwise pick the domain whose responsibility best matches.

### Mandatory Placement Rules

| File type | Belongs in | Example |
|---|---|---|
| Outbound webhook service, controller, listener | `Outbound/` | `OutboundWebhookService` |
| Content moderation logic (banned words, rate limits, safeguards) | `Moderation/` | `SafeguardService` |
| REST controller for a domain | Same folder as its Service | `MemberTypeController` → `MemberTypes/` |
| Bridge adapter classes | `Bridges/` with `Bridge` suffix | `JetonomyBridge.php` |
| Bridge listener classes | `Bridges/` with `BridgeListener` suffix | `JetonomyBridgeListener.php` |
| Admin-only UI helpers | `Admin/{SubPage}/` not `Admin/Helpers/` | `MemberDisplay` → `Admin/Members/` |
| Directory/listing service | `Profile/` if it queries `WP_User_Query`; `Search/` only if it queries `bn_search_index` | `MemberDirectoryService` → `Profile/` |
| Cron job runner | `Core/CronService.php` — no `Handlers` suffix | — |

### Listener Convention

Every class ending in `Listener` **must**:

1. `implement BuddyNext\Contracts\ListenerInterface`
2. Expose `public function register(): void` (not `init()`)
3. Be wired in `Plugin::init()` as `( new XxxListener() )->register()`

Never use `init()` on a listener. Only Services and Admin registrars use `init()`.

### Bridge Naming Convention

```
Bridges/JetonomyBridge.php          class JetonomyBridge          ← adapter
Bridges/JetonomyBridgeListener.php  class JetonomyBridgeListener  ← hook registrar
```

Never name a bridge adapter `class Jetonomy` — it reads like the external plugin class.

### Tests Mirror Source

```
includes/Feed/PostController.php           →  tests/Feed/PostControllerTest.php
includes/SocialGraph/FollowController.php  →  tests/SocialGraph/FollowControllerTest.php
```

`tests/REST/` must stay empty. All controller tests live in the controller's domain folder.

### File Naming Conventions

| Type | Convention | Example |
|------|-----------|---------|
| Classes | PascalCase, one per file | `FollowService.php` |
| Templates | kebab-case | `home-feed.php` |
| Partials | `partial-*.php` | `partial-post-card.php` |
| Assets | kebab-case | `bn-feed.css`, `bn-feed.js` |
| Tests | `ClassTest.php` | `FollowServiceTest.php` |
| REST controllers | `[Feature]Controller.php` | `FeedController.php` |

---

## Design System Tokens

CSS variables are the single source of truth — never hardcode px, hex, or font
values. The system is **`--bn-*` prefixed and OKLCH-based**: a single `--bn-hue`
cascades into the full accent ramp, so re-theming is one hue change. `TokenService`
(`includes/Theme/TokenService.php`) injects the values inline on the `bn-base` handle.

**Canonical definitions live in `assets/css/bn-base.css`** — read that for exact
values. Do not paste a token table into docs; it drifts out of sync.

Token families (all `--bn-` prefixed):
- Surfaces: `--bn-bg`, `--bn-canvas`, `--bn-surface`, `--bn-sunken`, `--bn-raised`
- Ink (text): `--bn-ink`, `--bn-ink-2`, `--bn-ink-3`, `--bn-ink-4`
- Lines/focus: `--bn-line`, `--bn-line-faint`, `--bn-line-strong`, `--bn-ring`
- Accent ramp (OKLCH from `--bn-hue`): `--bn-accent`, `--bn-accent-50…900`, `--bn-accent-fg`
- Semantic: `--bn-success(-bg)`, `--bn-danger(-bg)`, `--bn-info(-bg)`
- Integration accents: `--bn-jetonomy(-bg)`, `--bn-media(-bg)`, `--bn-paid(-bg)`, `--bn-events(-bg)`
- Type: `--bn-font-{body,display,ui,mono}`, `--bn-text-{2xs…4xl,base,md}`, `--bn-fw-{normal…extrabold}`, `--bn-leading-{tight,snug,normal,body}`
- Spacing (4px grid): `--bn-s1 … --bn-s16` · Radius: `--bn-r-{sm,md,lg,xl,full}` · Shadow: `--bn-shadow-{xs,sm,md,lg}`

**Dark mode** flips tokens under `[data-bn-theme="dark"]`, `[data-theme="dark"]`,
or `[data-bx-mode="dark"]` (the last bridges BuddyX/Reign's colour-mode toggle so
BuddyNext follows the host theme). Verify dark via the real theme toggle, not a
hand-set attribute.

Bare-named aliases (`--bg`, `--text-1`, `--s4`…) exist only for back-compat —
always author with the `--bn-*` names. `bin/ux-audit.sh` (gate F3) rejects raw
hex/px and non-`--bn-` token use.

---

## CSS & JS Coding Standards — Non-Negotiable

### CSS Token Rules

**The golden rule: never write a hardcoded px, hex, or font-family value in any CSS file.**

| What you need | How to write it |
|---------------|-----------------|
| Font size | `var(--text-sm)`, `var(--text-base)` |
| Font weight | `var(--fw-semibold)`, `var(--fw-bold)` |
| Line height | `var(--leading-body)`, `var(--leading-normal)` |
| Letter spacing | `var(--ls-tight)`, `var(--ls-normal)` |
| Colors | `var(--bg)`, `var(--text-1)`, `var(--brand)` |
| Spacing | `var(--s1)` through `var(--s16)` (4px grid) |
| Border radius | `var(--r-sm)` through `var(--r-full)` |
| Font family | `var(--font-body)` or `var(--font-display)` |

**Where tokens come from:**
- `TokenService` (`includes/Theme/TokenService.php`) injects all `--text-*`, `--fw-*`, `--leading-*`, `--ls-*`, `--bg`, `--text-1`, `--brand`, `--s*`, `--r-*` tokens via `wp_add_inline_style('bn-base')`.
- `theme.json` registers the preset slugs so block themes can override via child theme.
- `bn-base.css` defines `--bn-text-*` as **aliases** to the global tokens: `--bn-text-base: var(--text-base)`.

**CSS `:root` blocks — allowed vs forbidden:**

```css
/* ALLOWED — component-specific tokens not in the global system */
:root {
  --bn-shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
  --bn-transition: 0.14s ease;
}

/* ALLOWED — aliasing global tokens for a local shorthand */
:root {
  --bn-text-base: var(--text-base); /* alias, not hardcode */
}

/* FORBIDDEN — hardcoded typography/color/spacing */
:root {
  --bn-text-base: 15px;   /* never */
  --bn-bg: #ffffff;       /* never */
  --bn-s4: 16px;          /* never */
}
```

**Font loading** — Inter and Plus Jakarta Sans are loaded in `AssetService`. Never
import from Google Fonts inside a CSS file. The `--font-body` / `--font-display`
tokens carry the full stack including system-font fallbacks.

### CSS File Structure

Every `assets/css/bn-{feature}.css` file follows this order:

```css
/* 1. File header comment — what this file covers */

/* 2. :root — ONLY component-specific tokens (shadows, transitions)
      and --bn-* aliases to global tokens. No hardcoded values. */
:root { ... }

/* 3. dark-mode overrides only — match the canonical triggers */
[data-bn-theme="dark"], [data-theme="dark"], [data-bx-mode="dark"] { ... }

/* 4. Component rules — desktop-first */
.bn-component { ... }

/* 5. Mobile at end — @media (max-width: 640px) for every layout section */
@media (max-width: 640px) { ... }
```

### JavaScript / Interactivity API Rules

- **All JS stores** use ES module syntax: `import { store, getContext } from '@wordpress/interactivity'`.
- **Store namespace** always `buddynext/{feature-name}` — e.g. `buddynext/feed`.
- **No window globals** — never `window.wp.interactivity.store(...)`.
- **No inline `<script>` in templates** — JS lives in `assets/js/{feature}/store.js`, loaded via `AssetService`.
- **REST calls in stores** use `fetch()` with the `restUrl` / `restNonce` context values passed from PHP via `data-wp-context`.
- **Computed state** for all class/text bindings — never inline ternaries in `data-wp-bind`.
- **No jQuery** — Interactivity API + vanilla fetch only.

### Adding a New CSS/JS Bundle

1. Create `assets/css/bn-{feature}.css` and `assets/js/{feature}/store.js`.
2. Register both in `AssetService::register_assets()`.
3. Enqueue in `PageRouter::enqueue_hub_assets()` for the relevant hub case.
4. Pass `restNonce` + `restUrl` from the template via `data-wp-context`.

---

## Non-Negotiable Standards

### 1. Enterprise Code Quality — No Shortcuts

- Every file must pass WPCS before committing.
- Every class must pass PHPStan level 5+.
- No `@todo`, no stub implementations, no `/* TODO */` — ship complete code or don't ship.
- **Zero AI markers** — no `// Generated by`, no `// AI-assisted`, no `// Claude`, no `@generated`. Code reads as if written by a senior WordPress engineer. No exceptions.
- No `echo` in production paths — use `wp_send_json_*`, templates, or REST responses.
- All DB queries use `$wpdb->prepare()`. Zero raw interpolation.
- All nonces validated on every state-changing request.
- Capabilities checked on every admin and REST endpoint.
- Sanitize input at entry. Escape output at exit. Always.

### 2. Test-Driven Development — Mandatory

Write the failing test FIRST, then the implementation, then make it pass.

```
vendor/bin/phpunit tests/[Area]/[ClassTest].php --testdox
```

Never mark a task complete unless tests pass.

### 3. Premium UX — Non-Negotiable

The bar is Facebook / Instagram / LinkedIn polish: clean copy, correct spacing and
alignment, real-time-feeling interactions, and fully-wired functionality that holds
up at large-community scale.

- **If it renders, it is real.** Every control a member can see is *bound* to an action, *enforced* by code that reads its value, and *observable* in its effect. A setting that saves but never gates anything is worse than no setting. Never ship a mockup, a disabled placeholder, or "coming soon" copy on a member-facing surface.
- **Tokens only** — author with `--bn-*`, never raw hex/px (see Design System Tokens).
- **Mobile is part of the same commit.** Every member-facing layout is verified at 390px — no horizontal scroll, no clipped controls. Not a follow-up.
- **Dark mode** works on every new surface, via tokens.
- **Every async surface** handles empty, error and loading states.

### 4. No Emoji — Ever

**Rules:**
- **Never** use Unicode emoji characters anywhere — PHP, JS, CSS, HTML, or comments.
- **Never** use HTML entities that render emoji (`&#128100;`, `&#x1F4BB;`).
- **Always** use SVG icons from `assets/icons/` via:
  - Templates: `buddynext_icon( 'icon-name' )` — echoes inline SVG
  - PHP classes: `echo \BuddyNext\Core\IconService::render( 'icon-name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped`
  - JS status hints: CSS class-based coloured text — no emoji in `textContent`
- **Adding new icons:** drop a Lucide-style SVG (no width/height, `stroke="currentColor"`, `viewBox="0 0 24 24"`) into `assets/icons/<slug>.svg`.
- **55+ icons already exist** in `assets/icons/` — check before creating one.
- `IconService::render()` returns `wp_kses()`-sanitized markup — always safe to echo.

### 5. Translation-ready from day one

Every user-facing string needs a PHP `__()` home **and** a render path that applies
the translation. A JS-only string literal the POT scanner never sees is a bug, as is
a store reading an i18n key that PHP never injects.

---

## Quality Gates — How to Run

Run from the repo root. All of these must pass before a commit.

| Gate | Command |
|---|---|
| Full CI-parity gate (lint + WPCS + PHPStan + UX audit) | `bin/check.sh` |
| Same, staged files only (fast pre-commit signal) | `bin/check.sh --staged` |
| WPCS | `vendor/bin/phpcs` |
| PHPStan level 5 | `vendor/bin/phpstan analyse` |
| Unit tests | `vendor/bin/phpunit` |
| UX audit — token + primitive compliance, inline style/script, no `alert()`/`confirm()` | `bin/ux-audit.sh` |
| REST boundary — 100% REST frontend, no admin-ajax | `bin/check-rest-boundary.sh` |

**Pre-commit hook (one-time per clone):**

```bash
git config core.hooksPath .githooks
```

`.githooks/pre-commit` runs `bin/check.sh --staged --skip-audit`. Use
`git commit --no-verify` only in emergencies.

---

## WP-CLI

```bash
# Point --path at your WordPress root
wp --path=/path/to/wordpress plugin activate buddynext

# Run the installer manually
wp --path=/path/to/wordpress eval 'BuddyNext\Core\Installer::run(); echo "done\n";'

# Check tables
wp --path=/path/to/wordpress db tables --all-tables | grep bn_
```

---

## Key Integration Hooks (Cross-Plugin)

### BuddyNext fires → Addons listen

> **These signatures are GENERATED from the real `do_action()` call sites. Do not hand-edit them.**
>
> The previous version of this table was hand-maintained and had rotted badly: **10 of 36 signatures
> handed wrong values to any listener that trusted them, and 2 were fatal under PHP 8** (they
> declared three arguments where only two are fired → `ArgumentCountError`). The in-code PHPDoc was
> correct the whole time; only this table was wrong. Integrators — and AI agents — read this table.
>
> **If you change a `do_action()`, regenerate this block. Never edit it by hand.** Hand-maintenance
> is precisely how it rotted, and a wrong signature here is a landmine in someone else's plugin.

```php
// ── Social graph ──────────────────────────────────────────────────────────────────
do_action( 'buddynext_user_followed',           $follower_id, $following_id );
do_action( 'buddynext_user_unfollowed',         $follower_id, $following_id );
do_action( 'buddynext_connection_requested',    $connection_id, $requester_id, $recipient_id, $note );
do_action( 'buddynext_connection_accepted',     $connection_id, $requester_id, $recipient_id );
do_action( 'buddynext_connection_declined',     $connection_id, $requester_id, $recipient_id );
do_action( 'buddynext_connection_withdrawn',    $connection_id, $requester_id, $recipient_id );
do_action( 'buddynext_block',                   $blocker_id, $blocked_id );
do_action( 'buddynext_unblock',                 $blocker_id, $blocked_id );

// ── Content ───────────────────────────────────────────────────────────────────────
do_action( 'buddynext_post_created',            $post_id, $user_id, $type );
do_action( 'buddynext_post_deleted',            $post_id, $user_id );

// NOTE: reactions and comments are OBJECT-GENERIC. Arg 1 (reactions) / arg 2 (comments) is a
// STRING object_type ('post', 'comment', …), NOT an id. The old table claimed a $reaction_id /
// $post_id int there — a listener following it read a string as an id.
do_action( 'buddynext_reaction_added',          $object_type, $object_id, $user_id, $emoji );
do_action( 'buddynext_reaction_removed',        $object_type, $object_id, $user_id, $emoji );
do_action( 'buddynext_comment_created',         $comment_id, $object_type, $object_id, $user_id );
do_action( 'buddynext_comment_updated',         $comment_id, $user_id );   // 2 args, not 3
do_action( 'buddynext_comment_deleted',         $comment_id, $user_id );   // 2 args, not 3

// ── Spaces ────────────────────────────────────────────────────────────────────────
do_action( 'buddynext_space_created',           $space_id, $owner_id );
do_action( 'buddynext_space_member_joined',     $space_id, $user_id, $role );  // $role is always 'member' today
do_action( 'buddynext_space_member_left',       $space_id, $user_id );
do_action( 'buddynext_space_member_removed',    $space_id, $user_id, $actor_id );
do_action( 'buddynext_space_join_approved',     $space_id, $user_id, $actor_id );
do_action( 'buddynext_space_user_banned',       $space_id, $user_id, $actor_id );
do_action( 'buddynext_space_user_unbanned',     $space_id, $user_id );

// ── Member types ──────────────────────────────────────────────────────────────────
do_action( 'buddynext_member_type_assigned',    $user_id, $type_slug, $old_slug );
do_action( 'buddynext_member_type_removed',     $user_id, $type_slug );
do_action( 'buddynext_member_type_created',     $type_id, $type_data );
do_action( 'buddynext_member_type_deleted',     $type_id, $slug );

// ── Moderation ────────────────────────────────────────────────────────────────────
// NOTE: report_created's args 2-4 are NOT in the order the old table claimed. $reporter_id is
// LAST. A doc-following listener received a string object_type where it expected an int id.
do_action( 'buddynext_report_created',          $report_id, $object_type, $object_id, $reporter_id );
do_action( 'buddynext_user_warned',             $user_id, $actor_id, $reason );
do_action( 'buddynext_user_suspended',          $user_id, $actor_id, $reason, $expires_at );
do_action( 'buddynext_user_unsuspended',        $user_id );
do_action( 'buddynext_user_shadow_banned',      $user_id, $actor_id, $reason );
do_action( 'buddynext_user_shadow_ban_removed', $user_id, $actor_id );
do_action( 'buddynext_appeal_submitted',        $user_id, $appeal_id, $appeal_type, $suspension_id );  // $appeal_type is always 'suspension' today
// NOTE: $decision is arg 3, not arg 2. A doc-following `if ( $decision === 'approved' )` was
// comparing an int user id to a string and could NEVER match.
do_action( 'buddynext_appeal_resolved',         $appeal_id, $user_id, $decision );

// ── Onboarding / notifications ────────────────────────────────────────────────────
do_action( 'buddynext_onboarding_completed',    $user_id );
// NOTE: arg 3 is an associative ARRAY, not a scalar type string. A listener typed
// `string $type` gets a TypeError; a `switch ( $type )` silently never matches.
do_action( 'buddynext_notification_created',    $notification_id, $recipient_id, $data );
```

### Addons fire → BuddyNext listens
```php
// WPMediaVerse
mvs_message_sent( $message_id, $conv_id, $sender_id, $recipient_ids )
mvs_buddynext_active → return true  // BuddyNext hooks this filter
mvs_can_send_message → checks bn_blocks

// Jetonomy
jetonomy_after_create_post( $post_id, ... )
jetonomy_after_create_reply( $reply_id, ... )

// WBGamification
wb_gamification_badge_awarded( $user_id, $badge_id )
wb_gamification_level_changed( $user_id, $old, $new )

// Career Board
wcb_job_created( $job_id, $request )
wcb_application_submitted( $app_id, $job_id, $candidate_id )
```

Full REST route and hook reference: `docs/website/developer-guide/`.
