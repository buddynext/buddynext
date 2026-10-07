# Shortcodes reference

BuddyNext registers a small set of shortcodes that place a full community hub - the activity feed, member directory, spaces, messages, notifications, auth, or the community admin panel - on any page, plus the `[buddynext_search]` and `[buddynext_user_menu]` chrome shortcodes. BuddyNext Pro adds membership shortcodes (see the end of the reference). They exist for classic and page-builder themes (and any place you cannot drop a Gutenberg block); on a block theme the equivalent `buddynext/*` blocks are usually the better fit. This page documents each shortcode, its attributes, and when to reach for it versus the block.

![The community activity feed that [buddynext_activity] renders on a page](../images/community-activity-feed.webp)

## Overview / Contract

The eight Free shortcodes `[buddynext_activity]`, `[buddynext_people]`, `[buddynext_spaces]`, `[buddynext_messages]`, `[buddynext_notifications]`, `[buddynext_auth]`, `[buddynext_community_admin]` and `[buddynext_search]` are registered by `BuddyNext\Shortcodes\ShortcodeService` (`includes/Shortcodes/ShortcodeService.php`); `[buddynext_user_menu]` is registered in `buddynext.php`. Two things are true of every hub shortcode:

- **They route by query var, not by attribute.** Each one reads the hub query vars that `PageRouter` sets (for example `bn_activity_action`, `bn_profile_action`, `bn_space_action`) and renders the template for the active endpoint. A single `[buddynext_activity]` on a page therefore serves the feed, explore, a hashtag feed, search, or the leaderboard depending on the URL.
- **They self-enqueue and self-scope.** When a shortcode sits on an arbitrary page (off the routed hub path), the service enqueues the shell stylesheet and the feature bundles it needs, and wraps the output in a `.bn-app.bn-app--embedded` scoping canvas so the `--bn-*` tokens and layout apply. You do not need to enqueue anything yourself.

Auth-gated shortcodes return a "you must be logged in" message with a login link for guests.

## The shortcodes

### `[buddynext_activity]`

The Activity hub. Routes by the `bn_activity_action` query var: `explore` -> the explore feed, `hashtag` -> the hashtag feed (`bn_hashtag` slug), `search` -> search results (`?q=` from the URL), `leaderboard` -> the gamification leaderboard, default -> the home feed.

- **Attributes:** none.
- **Use vs block:** Use the shortcode to place the whole, URL-driven activity hub (feed + explore + hashtag + search + leaderboard) on one page. Use the `buddynext/activity-feed` block (with its `scope` and `perPage` attributes) when you want one specific feed embedded as a widget rather than the routed hub.

### `[buddynext_people]`

The People hub. With no resolved user slug it shows the member directory; with `view="profile"` it shows the current user's own profile (guests are sent to login). When a user slug is in the URL it routes by `bn_profile_action`: `edit` -> the profile editor (owner or admin only; others are redirected), `connections` -> the connections page, default -> the profile view.

- **Attributes:** `view` (string, default `""`). The only recognised value is `profile` (show the current user's own profile when no slug is present).
- **Use vs block:** Use the shortcode for the full directory-plus-profile hub. Use the `buddynext/member-directory` block for an embeddable directory grid, or `buddynext/profile-header` / `buddynext/profile-fields` to surface one member's details as a widget.

### `[buddynext_spaces]`

The Spaces hub. With no space slug it shows the spaces directory; with a slug it routes by `bn_space_action`: `members`, `settings`, `moderation`, `admin`, default -> the space home.

- **Attributes:** none.
- **Use vs block:** Use the shortcode for the full spaces hub (directory plus single-space surfaces). Use the `buddynext/space-directory` block for an embeddable directory, or `buddynext/my-spaces` / `buddynext/space-card` for sidebar widgets.

### `[buddynext_messages]`

The Messages hub. Requires login. Routes by `bn_msg_action` / `bn_conv_id`: `requests` -> the message-requests view, a conversation id -> the thread, default -> the conversation list.

- **Attributes:** none.
- **Use vs block:** No block equivalent - direct messaging is a full hub, not an embeddable widget. Use this shortcode (or the routed Messages hub page) to place it.

### `[buddynext_notifications]`

The Notifications hub (the full notifications list). Requires login.

- **Attributes:** none.
- **Use vs block:** Use the shortcode for the full notifications surface. Use the `buddynext/notification-bell` block when you only want the bell icon with an unread count in a custom header - it is the badge, not the list.

### `[buddynext_auth]`

The Auth hub. Logged-in users are redirected to the Activity hub immediately; guests are shown the sign-in form by default, or the create-account form with `view="signup"`. With no `view`, the routed hub's own action is honoured. `view="signup"` falls back to sign-in when WordPress registration is closed. It enqueues the auth styles and the matching `@buddynext/auth-login` or `@buddynext/auth-signup` module so the form works even off the routed auth path.

- **Attributes:** `view` (string, default `""`): `login` or `signup`.
- **Use vs block:** Use the shortcode for the combined login + registration surface on a single page. Use the `buddynext/login-form` and `buddynext/registration-form` blocks to place either form on its own, with a `redirectUrl` attribute.

### `[buddynext_community_admin]`

The Community Admin panel - a front-end, site-wide overview for community managers (including an Appeals approve/deny surface). Requires login plus `manage_options` or the `buddynext-spaces/moderate` ability. It enqueues the `moderation` bundle so the Appeals controls work.

- **Attributes:** none.
- **Use vs block:** No block equivalent. Place it with this shortcode for managers who work from the front end rather than wp-admin.

### `[buddynext_user_menu]`

The logged-in header user section: the notification bell, the messages icon, and the avatar with a CSS-only profile dropdown and log-out. Renders nothing for guests. Registered in `buddynext.php`, it returns `BuddyNext\Header\HeaderUserSection::render()`.

- **Attributes:** none.
- **Use vs block:** This is the classic-theme way to drop the header chrome into a PHP header template or a page-builder header. The exact equivalent is the `buddynext/header-user-menu` block (a block-based widget for block themes). Both render the same component from `HeaderUserSection`.

### Header functions for theme templates

A theme that draws its own header icons places the pieces one at a time with these functions, each of which echoes markup and is safe to call inside `if ( function_exists( ... ) )`:

| Function | Renders |
|---|---|
| `buddynext_header_search()` | Search icon. A link to the community search page that opens the search palette in place on BuddyNext pages and simply navigates to the search page anywhere else. Shown to guests too. |
| `buddynext_header_notification_bell()` | Notification bell with the unread badge (members only). |
| `buddynext_header_messages_bell()` | Messages icon with the unread count (members only). |
| `buddynext_header_user_menu()` | Avatar and profile dropdown (members only). |

`buddynext_header_user_section()` returns the bell, messages icon and avatar menu together (Log In and Register for guests); it does not include the search icon. Call the functions instead of writing your own markup, so the icons look and behave the same in every theme. A theme keeps its own search markup only until it calls `buddynext_header_search()`.

### `[buddynext_search]`

A community search bar: a GET form that opens the BuddyNext search results page (members, spaces, posts, hashtags). Chrome, not a hub - drop it in a header, a sidebar, or page content. It does **not** replace WordPress or WooCommerce `?s=` search; it is a first-class entry point into *community* search that themes should use instead of hand-building a form against the search URL.

- **Attributes:**
  - `placeholder` (string, default empty, which shows the built-in `Search…` text) - the input placeholder.
  - `type` (string, default `all`) - which results tab to open: `all`, `members`, `spaces`, or `posts`. Any other value falls back to `all`.
- **Use vs block:** The exact equivalent is the `buddynext/search-bar` block (attributes `placeholder`, `searchIn`). For a classic PHP header, call the helper directly instead:

```php
<?php echo buddynext_search_bar( array( 'placeholder' => 'Search the community…', 'search_in' => 'members' ) ); ?>
```

  The block, the `[buddynext_search]` shortcode and `buddynext_search_bar()` all render the **same** markup - the helper is the single source, the other two wrap it. `buddynext_search_bar( array $args = [] ): string` returns the markup (safe to echo) and enqueues the one stylesheet it needs, so it is safe in a theme header.

## Pro membership shortcodes

BuddyNext Pro registers these when the Monetization feature is on; they return nothing otherwise.

| Shortcode | Attributes | Renders |
|---|---|---|
| `[buddynext_membership_pricing]` | `heading`, `subcopy` | The pricing table for the listed plans. |
| `[buddynext_my_membership]` | none | The member's active plan and status. |
| `[buddynext_plan_button]` | `plan` (plan id), `label`, `class` (default `bn-btn`) | A link straight to one plan on the pricing page; nothing for an unknown, inactive or archived plan. |
| `[buddynext_membership_header]`, `[buddynext_membership_proof]`, `[buddynext_membership_compare]`, `[buddynext_membership_faq]` | The matching `bn-membership-*` block's attributes | The sections of a pricing page, each also available as a block. |
| `[buddynext_members_only plan="slug"]...[/buddynext_members_only]` | `plan` (optional tier slug) | Wrapped content shown to entitled members, a locked card to everyone else. |

## Notes / gotchas

- **These are for classic and page-builder themes.** On a block theme, prefer the matching `buddynext/*` blocks (see the Blocks reference) - they expose attributes and edit in place. The shortcodes shine when you cannot use a block: a classic theme, a page-builder text widget, or a custom PHP template via `do_shortcode()`.
- **Routing comes from the URL, not the attributes.** Most hub shortcodes take no attributes because the active endpoint is chosen from the hub query vars `PageRouter` sets. The same `[buddynext_activity]` renders different surfaces at `/activity/`, `/activity/explore/`, and `/activity/hashtag/{slug}/`.
- **A few shortcodes carry attributes:** `[buddynext_people view="profile"]`, `[buddynext_auth view="signup"]`, `[buddynext_search placeholder="…" type="members"]`, and the block-equivalent `redirectUrl` on the auth blocks. The rest take none.
- **Auth gating is built in.** `[buddynext_messages]`, `[buddynext_notifications]`, and `[buddynext_community_admin]` show a login prompt to guests; `[buddynext_auth]` redirects logged-in users away; `[buddynext_user_menu]` is empty for guests.
- **No manual enqueue needed.** The service loads the shell stylesheet and the per-feature bundles for an embedded shortcode and scopes the output in `.bn-app--embedded`, so the hub renders styled even on an arbitrary page.

See also the Blocks reference for the `buddynext/*` block equivalents, and Frontend Interactivity for how the enqueued feature stores drive the rendered surface.
