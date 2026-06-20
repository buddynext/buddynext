# Blocks Reference

BuddyNext Free ships 18 server-rendered Gutenberg blocks under the `buddynext/*` namespace and the `buddynext` editor category. This page is the contract for each block: its name, purpose, attributes, and the REST data source that backs its rendered output. Developers embedding, theming, or extending these blocks should treat this table as authoritative.

![A feed page assembled from the server-rendered BuddyNext Gutenberg blocks documented here](../images/community-activity-feed.webp)

## Overview / Contract

All 18 blocks share the same registration and rendering model. Read this before the per-block tables.

- **Registration.** Every block is registered from `includes/Blocks/BlockRegistrar.php` via `register_block_type( block.json, [ 'render_callback' => ... ] )`. Each block's metadata lives in `blocks/bn-{slug}/block.json`. There is no JavaScript build step - render callbacks are inline closures on the registrar.
- **Server-rendered (dynamic).** Every block is dynamic: the saved post content holds only the block comment plus attributes, and the markup is produced at request time by a render callback that calls `buddynext_get_template( 'blocks/{slug}.php', ... )`. The template, in turn, reads data through a domain service (`buddynext_service( '...' )`) for the first paint and exposes a REST base URL plus a nonce for live client updates.
- **Editor preview.** The block editor renders a live preview with `wp.serverSideRender`. The shared editor handle `buddynext-blocks-editor` (`assets/js/blocks.js`) is pre-registered with `wp-server-side-render` in its dependency list and is localised with `window.bnBlocks` (`restUrl`, `nonce`, `searchUrl`) so editor-side REST calls carry a valid `wp_rest` nonce.
- **REST namespace.** Every data source below is under `buddynext/v1`. Interactive blocks read the base URL from `rest_url( 'buddynext/v1' )` passed into the template; the front-end view modules (`@buddynext/feed`, `@buddynext/social-buttons`, `@buddynext/spaces`) call specific routes under that namespace. Authenticated, state-changing routes require the `wp_rest` nonce; read routes for public directories do not.
- **Block supports.** With one exception, every block declares the same `supports`: `color` (background + text), `typography.fontSize`, and `spacing` (padding + margin). The Header User Menu block additionally sets `html: false` and `align: false`. These are standard core supports and are not repeated per-block below.
- **Styles.** Most blocks enqueue `assets/css/blocks.css`. The Header User Menu block enqueues `assets/css/bn-header.css` instead (it shares the logged-in header chrome stylesheet).

> **Note:** Attribute defaults shown below are the `block.json` defaults. The render callback sanitises each attribute (`sanitize_key`, `(int)`, `(bool)`, `esc_url_raw`, `sanitize_text_field`) before passing it to the template, so out-of-range or malformed values fall back safely.

## Block reference table

Each row lists the block name, what it renders, its attributes (name, type, default, and enum where the schema constrains it), and the REST data source the rendered output reads. The "Front-end view module" column names the JavaScript interactivity module that drives live updates after first paint, where one is attached.

### Social / Feed

| Block | Purpose | Attributes (name : type = default) | REST data source | View module |
|---|---|---|---|---|
| `buddynext/activity-feed` | Renders the community activity feed. Scope selects which feed to show. | `scope` : string = `home` (enum: `home`, `profile`, `space`, `explore`) · `perPage` : integer = `20` | First paint via the `feed` service; live load/refresh via `GET`/`POST` `buddynext/v1/posts` and `buddynext/v1/feed` (mark-all-read at `buddynext/v1/notifications/mark-all-read` for header contexts). | `@buddynext/feed` |
| `buddynext/post-composer` | "What's on your mind" post-creation box. | `placeholder` : string = `""` | `POST buddynext/v1/posts` (create). Link previews via `buddynext/v1/link-preview`. | `@buddynext/feed` |
| `buddynext/trending-hashtags` | Trending hashtags as a list or tag cloud. | `count` : integer = `10` · `display` : string = `list` (enum: `list`, `cloud`) | First paint via the `hashtags` service (`get_trending()`), backed by the hashtags REST surface under `buddynext/v1` (hashtags routes). | none |

### People

| Block | Purpose | Attributes (name : type = default) | REST data source | View module |
|---|---|---|---|---|
| `buddynext/member-directory` | Full filterable member grid or list with search and filters. | `perPage` : integer = `24` · `layout` : string = `grid` (enum: `grid`, `list`) | First paint via the `member_directory` service; member rows under the members/profiles REST surface (`buddynext/v1` users routes). | none |
| `buddynext/member-card` | Single user card with avatar, name, and follow button. Built for sidebars/widgets. | `userId` : integer = `0` | First paint via the `follows` service for relationship state; follow action via `buddynext/v1` follow routes. | `@buddynext/social-buttons` |
| `buddynext/follow-button` | Standalone follow / unfollow button for any user. | `userId` : integer = `0` | `follows` service for initial state; `POST`/`DELETE` against `buddynext/v1` follow routes (`/users/{id}/follow`). | `@buddynext/social-buttons` |
| `buddynext/connection-button` | Standalone connect / disconnect button for any user. | `userId` : integer = `0` | `connections` + `blocks` services for initial state; connection routes under `buddynext/v1` (`/users/{id}/connection`). | `@buddynext/social-buttons` |

### Spaces

| Block | Purpose | Attributes (name : type = default) | REST data source | View module |
|---|---|---|---|---|
| `buddynext/space-directory` | Filterable grid of community spaces (by category and membership). | `perPage` : integer = `12` · `layout` : string = `grid` (enum: `grid`, `list`) | First paint via the `spaces` service; spaces list via `GET buddynext/v1/spaces`. | none |
| `buddynext/space-card` | Single space card for a featured-space callout. | `spaceId` : integer = `0` | `spaces` + `space_members` services for initial state; join/leave and media via `buddynext/v1/spaces`, `buddynext/v1/spaces/{id}/avatar`, `buddynext/v1/spaces/{id}/cover`. | `@buddynext/spaces` |
| `buddynext/my-spaces` | Lists the spaces the current user belongs to. | `limit` : integer = `10` | `spaces` service scoped to the current user; spaces routes under `buddynext/v1`. | none |

### Profile

| Block | Purpose | Attributes (name : type = default) | REST data source | View module |
|---|---|---|---|---|
| `buddynext/profile-header` | Profile header: avatar, cover, name, bio, social links, follow/connect buttons. | `userId` : integer = `0` · `showStats` : boolean = `true` · `showActions` : boolean = `true` | `profiles` service (`get_profile()`) plus the `follows` service for the action buttons; profile + follow routes under `buddynext/v1`. | `@buddynext/social-buttons` |
| `buddynext/profile-fields` | Renders one profile field group (for example basic info, work, education). | `userId` : integer = `0` · `group` : string = `""` | `profiles` service (`get_profile()`); profile-field routes under `buddynext/v1`. | none |
| `buddynext/profile-completion-bar` | Profile completion progress bar with prompt cards for incomplete sections. | `userId` : integer = `0` | `profiles` service (`get_completion_score()`); profile routes under `buddynext/v1`. | none |

### Utility

| Block | Purpose | Attributes (name : type = default) | REST data source | View module |
|---|---|---|---|---|
| `buddynext/registration-form` | Signup form embeddable on any page. | `redirectUrl` : string = `""` | `POST buddynext/v1/auth/register`. | none |
| `buddynext/login-form` | Login form wrapping WordPress native authentication. | `redirectUrl` : string = `""` | WordPress core authentication (native login handling); no BuddyNext REST route for the credential exchange. | none |
| `buddynext/notification-bell` | Bell icon with unread count for custom header areas. | none | `notifications` service for the unread count; `buddynext/v1/notifications` (and `buddynext/v1/notifications/mark-all-read`). | none |
| `buddynext/header-user-menu` | Logged-in header chrome: notification bell, messages icon, and avatar with a CSS-only profile dropdown + log out. Renders nothing for guests. Uses `bn-header.css` and sets `html: false`, `align: false`. | none | `notifications` service for the bell count; notifications routes under `buddynext/v1`. Messages count comes from the WPMediaVerse DM layer when present. | none |
| `buddynext/search-bar` | Unified search input that opens grouped results. | `placeholder` : string = `""` | `GET buddynext/v1/search` (the editor handle is localised with `searchUrl` pointing at this route). | none |

## Examples

### Insert a block in a template or pattern

Blocks are dynamic, so the saved markup is just the block comment plus a JSON attribute object:

```html
<!-- wp:buddynext/activity-feed {"scope":"home","perPage":20} /-->
<!-- wp:buddynext/space-directory {"perPage":12,"layout":"grid"} /-->
<!-- wp:buddynext/follow-button {"userId":42} /-->
```

### Override a block's template

Render callbacks resolve their markup through `buddynext_get_template()`, which is theme-overridable. Copy the file into your theme to customise it:

```text
wp-content/themes/{your-theme}/buddynext/blocks/activity-feed.php
```

The variables passed into the template match the sanitised attributes (for the activity feed: `$scope`, `$per_page`).

### Register a block programmatically

If you need a block instance outside the editor (for example in a custom page builder), render it server-side:

```php
echo do_blocks( '<!-- wp:buddynext/member-directory {"perPage":24,"layout":"list"} /-->' );
```

## Notes / gotchas

- **First paint is server-rendered; updates are REST.** Treat the service call in the template as the source of the initial markup and the `buddynext/v1` routes as the live-update contract. Do not assume a block fetches everything over REST on load - the directory and feed blocks ship server-rendered rows for SEO and no-JS resilience.
- **Nonce scope.** Interactive blocks pass `restUrl => rest_url( 'buddynext/v1' )` and a `wp_rest` nonce from the template. State-changing routes (follow, connect, post, join) reject requests without it. Read-only directory routes do not require the nonce.
- **`userId` / `spaceId` default of 0.** For the per-entity blocks (member card, follow/connect buttons, profile blocks, space card), a `0` default means "resolve from the current context" - the profile being viewed, or the logged-in user. Set an explicit ID to pin the block to one entity.
- **Big-site lists.** The directory blocks (`member-directory`, `space-directory`) and `activity-feed` are paginated by `perPage` and back onto cursor/paged REST routes; they are safe to place on high-traffic pages. Avoid setting `perPage` to large values to "show everything" - use the directory's own load-more instead.
- **Login form.** The login block wraps WordPress core authentication, so it does not post to a `buddynext/v1` route for the credential exchange. The registration block does (`buddynext/v1/auth/register`).
- **Free vs Pro.** All 18 blocks ship in Free. Pro does not replace them; it adds capabilities (for example membership-gated content) that surface through the same blocks and the `buddynext-pro/v1` namespace where Pro routes apply.
