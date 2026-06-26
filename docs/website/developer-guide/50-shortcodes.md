# Shortcodes reference

BuddyNext registers a small set of shortcodes that place a full community hub - the activity feed, member directory, spaces, messages, notifications, auth, or the community admin panel - on any page, plus a `[buddynext_user_menu]` chrome shortcode. They exist for classic and page-builder themes (and any place you cannot drop a Gutenberg block); on a block theme the equivalent `buddynext/*` blocks are usually the better fit. This page documents each shortcode, its attributes, and when to reach for it versus the block.

![The community activity feed that [buddynext_activity] renders on a page](../images/community-activity-feed.webp)

## Overview / Contract

The hub shortcodes are registered by `BuddyNext\Shortcodes\ShortcodeService` (`includes/Shortcodes/ShortcodeService.php`); `[buddynext_user_menu]` is registered in `buddynext.php`. Two things are true of every hub shortcode:

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

The Auth hub. Logged-in users are redirected to the Activity hub immediately; guests are shown the login template, which carries both the sign-in and create-account forms. It enqueues the auth styles and the `@buddynext/auth-login` + `@buddynext/auth-signup` modules so the forms work even off the routed auth path.

- **Attributes:** none.
- **Use vs block:** Use the shortcode for the combined login + registration surface on a single page. Use the `buddynext/login-form` and `buddynext/registration-form` blocks to place either form on its own, with a `redirectUrl` attribute.

### `[buddynext_community_admin]`

The Community Admin panel - a front-end, site-wide overview for community managers (including an Appeals approve/deny surface). Requires login plus `manage_options` or the `buddynext-spaces/moderate` ability. It enqueues the `moderation` bundle so the Appeals controls work.

- **Attributes:** none.
- **Use vs block:** No block equivalent. Place it with this shortcode for managers who work from the front end rather than wp-admin.

### `[buddynext_user_menu]`

The logged-in header user section: the notification bell, the messages icon, and the avatar with a CSS-only profile dropdown and log-out. Renders nothing for guests. Registered in `buddynext.php`, it returns `BuddyNext\Header\HeaderUserSection::render()`.

- **Attributes:** none.
- **Use vs block:** This is the classic-theme way to drop the header chrome into a PHP header template or a page-builder header. The exact equivalent is the `buddynext/header-user-menu` block (a block-based widget for block themes). Both render the same component from `HeaderUserSection`.

## Notes / gotchas

- **These are for classic and page-builder themes.** On a block theme, prefer the matching `buddynext/*` blocks (see the Blocks reference) - they expose attributes and edit in place. The shortcodes shine when you cannot use a block: a classic theme, a page-builder text widget, or a custom PHP template via `do_shortcode()`.
- **Routing comes from the URL, not the attributes.** Most hub shortcodes take no attributes because the active endpoint is chosen from the hub query vars `PageRouter` sets. The same `[buddynext_activity]` renders different surfaces at `/activity/`, `/activity/explore/`, and `/activity/hashtag/{slug}/`.
- **Two shortcodes carry attributes:** `[buddynext_people view="profile"]` and the block-equivalent `redirectUrl` on the auth blocks. The rest take none.
- **Auth gating is built in.** `[buddynext_messages]`, `[buddynext_notifications]`, and `[buddynext_community_admin]` show a login prompt to guests; `[buddynext_auth]` redirects logged-in users away; `[buddynext_user_menu]` is empty for guests.
- **No manual enqueue needed.** The service loads the shell stylesheet and the per-feature bundles for an embedded shortcode and scopes the output in `.bn-app--embedded`, so the hub renders styled even on an arbitrary page.

See also the Blocks reference for the `buddynext/*` block equivalents, and Frontend Interactivity for how the enqueued feature stores drive the rendered surface.
