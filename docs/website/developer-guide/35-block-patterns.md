# Block Patterns

BuddyNext Free registers 4 pre-built block patterns under the `buddynext/*` namespace and the `buddynext` pattern category. Each pattern is a ready-made page layout composed entirely from the BuddyNext blocks documented in Blocks Reference. This page covers what each pattern contains, how it composes blocks, and what BuddyNext does and does not register at the WordPress integration layer so addon authors know where to look.

![A community page layout composed from the BuddyNext block patterns documented on this page](../images/community-activity-feed.webp)

## Overview / Contract

- **Registration.** All 4 patterns are registered from `includes/Blocks/BlockRegistrar.php::register_patterns()` via `register_block_pattern()`. Each pattern's `content` is a static block-markup string returned by a private helper on the registrar.
- **Category.** Every pattern is filed under the `buddynext` pattern category, the same category used by the blocks. Users find them in the editor's pattern inserter under "BuddyNext".
- **Composition only.** Patterns contain no logic of their own. They are pre-arranged compositions of the dynamic `buddynext/*` blocks (and, where layout requires it, core `wp:columns` / `wp:group` wrappers). All data, REST access, and behaviour come from the embedded blocks - see Blocks Reference for each block's data source.
- **Starting points, not locked layouts.** Once inserted, a pattern becomes ordinary block markup in the post. Editors can rearrange, remove, or reconfigure the blocks. Changing a block's attributes after insertion changes only that page.

## Pattern reference

| Pattern name | Title | Composes | Layout |
|---|---|---|---|
| `buddynext/community-home` | Community Home | `post-composer`, `activity-feed` (scope `home`), `space-directory` (perPage 6, list), `trending-hashtags` (count 5) | Two-column: a 66.66% main column (composer + home feed) and a 33.33% sidebar (space directory + trending hashtags), wrapped in `wp:columns` with class `bn-layout-community-home`. |
| `buddynext/member-profile` | Member Profile | `profile-header`, `activity-feed` (scope `profile`), `profile-fields`, `profile-completion-bar` | Single column: full-width profile header, then a `wp:group` body (`bn-member-profile-body`) holding the profile-scoped feed, the field groups, and the completion bar. |
| `buddynext/spaces-directory` | Spaces Directory | `search-bar` (placeholder "Search spaces..."), `space-directory` (perPage 12, grid) | Single column: a search bar above a 12-per-page grid of spaces. |
| `buddynext/member-directory` | Member Directory | `search-bar` (placeholder "Search members..."), `member-directory` (perPage 24, grid) | Single column: a search bar above a 24-per-page grid of members. |

## How the patterns compose blocks

Each pattern's `content` is the literal block markup. Reading it shows exactly which blocks and attributes a pattern injects.

### Community Home

```html
<!-- wp:columns {"className":"bn-layout-community-home"} -->
<div class="wp-block-columns bn-layout-community-home">
  <!-- wp:column {"width":"66.66%"} -->
  <div class="wp-block-column" style="flex-basis:66.66%">
    <!-- wp:buddynext/post-composer /-->
    <!-- wp:buddynext/activity-feed {"scope":"home"} /-->
  </div>
  <!-- /wp:column -->
  <!-- wp:column {"width":"33.33%"} -->
  <div class="wp-block-column" style="flex-basis:33.33%">
    <!-- wp:buddynext/space-directory {"perPage":6,"layout":"list"} /-->
    <!-- wp:buddynext/trending-hashtags {"count":5} /-->
  </div>
  <!-- /wp:column -->
</div>
<!-- /wp:columns -->
```

### Member Profile

```html
<!-- wp:buddynext/profile-header /-->
<!-- wp:group {"className":"bn-member-profile-body"} -->
<div class="wp-block-group bn-member-profile-body">
  <!-- wp:buddynext/activity-feed {"scope":"profile"} /-->
  <!-- wp:buddynext/profile-fields /-->
  <!-- wp:buddynext/profile-completion-bar /-->
</div>
<!-- /wp:group -->
```

> **Note:** The profile body is wrapped in a core `wp:group`. There is no core "tabs" block, so the body is a stacked group rather than a tabbed layout - using a non-existent block here would trip an invalid-block warning and render raw markup.

### Spaces Directory and Member Directory

```html
<!-- wp:buddynext/search-bar {"placeholder":"Search spaces…"} /-->
<!-- wp:buddynext/space-directory {"perPage":12,"layout":"grid"} /-->
```

```html
<!-- wp:buddynext/search-bar {"placeholder":"Search members…"} /-->
<!-- wp:buddynext/member-directory {"perPage":24,"layout":"grid"} /-->
```

## What BuddyNext does NOT register (for addon authors)

BuddyNext Free does not register any custom post types or taxonomies. There are zero `register_post_type()` and zero `register_taxonomy()` calls in the plugin. BuddyNext stores its content (posts, spaces, profiles, hashtags, notifications, and so on) in dedicated `bn_*` database tables, not in WordPress posts or terms. Addon authors should integrate through the BuddyNext REST API (`buddynext/v1`), the action/filter hooks, and the domain services - not by querying for a custom post type or taxonomy that does not exist.

> **Warning:** Do not assume a `buddynext`-style CPT or taxonomy. Spaces, member types, and hashtags are custom tables and services. Use the documented hooks and REST routes (see the REST and Hooks pages) to read or react to BuddyNext content.

For completeness, two integration surfaces that BuddyNext Free **does** register (in addition to the 18 blocks and these 4 patterns):

- **Shortcodes.** `ShortcodeService` (`includes/Shortcodes/ShortcodeService.php`, initialised in `Plugin::init()`) registers 7 hub shortcodes: `[buddynext_activity]`, `[buddynext_people]`, `[buddynext_spaces]`, `[buddynext_messages]`, `[buddynext_notifications]`, `[buddynext_auth]`, and `[buddynext_community_admin]`. These render the same surfaces the blocks do, for classic (non-block) themes.
- **Widgets.** `WidgetService` (`includes/Widgets/WidgetService.php`, initialised in `Plugin::init()`) registers 3 classic `WP_Widget` widgets: Online Members, Trending Hashtags, and Recent Activity. The block-based equivalents (for block themes) are the `buddynext/*` blocks above.

> **Note:** The `audit/manifest.json` `features.shortcodes`, `features.widgets`, `features.cpts`, and `features.taxonomies` arrays are all length 0 at the time of writing. The CPT and taxonomy counts are correct (none exist). The shortcode and widget arrays are stale - the services above are registered and active in the current code. This page documents the current source state; treat the manifest's empty shortcode/widget arrays as a manifest gap, not as the runtime behaviour.

## Notes / gotchas

- **Patterns inherit block behaviour.** Because patterns are pure compositions, every scale, auth, and REST rule from Blocks Reference applies unchanged. For example, the directories inside the directory patterns are paginated by their `perPage` attribute and back onto cursor/paged `buddynext/v1` routes.
- **Editing after insert is local.** Reconfiguring a block inside an inserted pattern (changing `perPage`, swapping `layout`, removing a block) affects only that page. The registered pattern definition is unchanged.
- **Free vs Pro.** All 4 patterns ship in Free. Pro does not register replacement patterns; it extends the embedded blocks (for example membership gating) which then surface within these same layouts.
