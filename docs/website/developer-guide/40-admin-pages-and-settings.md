# Admin Pages and Settings

The BuddyNext admin surface and the contracts that shape it: the registered wp-admin pages (5 in free, 20 in Pro), the `AdminHub` section + tab-placement system that arranges every screen into a capped information architecture, the `bn_admin_hub_sections` and `bn_admin_hub_tab_placement` filters for adding or relocating tabs from a mu-plugin, and the options-wiring model (about 86 option keys, registered into per-tab settings groups). This page is for developers adding an admin screen, moving an existing tab, or wiring a new setting.

![The Platform Features admin tab, a live BuddyNext screen arranged by the AdminHub section and tab-placement system](../images/admin-features.webp)

![The admin dashboard - one of the registered wp-admin pages whose option wiring this page documents](../images/admin-overview.webp)

## Overview / Contract

All BuddyNext admin pages gate on the native `manage_options` capability. There is no BuddyNext-specific admin role; site administrators reach the screens, and every other user is denied. `AdminHub::render_section()` re-checks `current_user_can( 'manage_options' )` on render and `wp_die()`s otherwise, and each tab can additionally declare its own `cap` (default `manage_options`).

The admin is built on three layers:

1. **Sections** - the top-level wp-admin sub-menu entries (`?page=` slugs). Declared in `AdminHub::DEFAULT_SECTIONS`, filterable via `bn_admin_hub_sections`.
2. **Tabs** - the individual screens, contributed by feature classes through `AdminHub::register_tab()`. Each tab declares an *origin* `section:slug`.
3. **Placement** - a canonical map (`AdminHub::TAB_PLACEMENT`) that moves each tab to its *final* section and sidebar position, filterable via `bn_admin_hub_tab_placement`. This lets a feature keep registering against its own domain while the hub arranges the final layout in one place.

A section appears in the sidebar **only when at least one visible tab is registered into it**. Empty sections are hidden. No section holds more than five tabs by design, so no screen overwhelms the owner.

## Registered admin pages

### Free (5 pages)

The free plugin registers one top-level menu and four section sub-menus, all on `manage_options`:

| Page slug | Type | Title | Registrar |
|-----------|------|-------|-----------|
| `buddynext` | menu | BuddyNext | `Admin/Settings.php` |
| `buddynext` | submenu | BuddyNext - Settings | `Admin/Settings.php` |
| `buddynext-members` | submenu | Members | `Admin/Members.php` |
| `buddynext-spaces` | submenu | Spaces | `Admin/Spaces.php` |
| `buddynext-integrations` | submenu | Integrations | `Admin/IntegrationHub.php` |

The visible section list is larger than this table because `DEFAULT_SECTIONS` declares 12 sections (Settings, Platform, Members, Spaces, Engagement, Notifications, Realtime & Push, Campaigns, Moderation, Auto-Moderation, Monetization, Upgrade). Most are populated by tabs rather than by a dedicated page registrar, and only sections with registered tabs render. Several sections (Realtime & Push, Campaigns, Auto-Moderation, Monetization) register no tabs in free, so they stay hidden until Pro is active.

### Pro (20 pages)

Pro registers 20 admin pages, each a `submenu`, all on `manage_options`:

| Page slug | Title |
|-----------|-------|
| `buddynextpro-analytics` | Analytics |
| `buddynextpro-broadcasts` | Broadcast Campaigns |
| `buddynextpro-drip` | Drip Sequences |
| `buddynextpro-member-labels` | Member Labels |
| `buddynextpro-tiers` | Membership Tiers |
| `buddynextpro-subs` | Subscriptions |
| `buddynextpro-paywall` | Paywall Settings |
| `buddynextpro-modrules` | Moderation Rules |
| `buddynextpro-bulk-mod` | Bulk Moderation |
| `buddynextpro-push` | Push |
| `buddynextpro-push-prefs` | Push Preferences |
| `buddynextpro-realtime` | Realtime |
| `buddynextpro-scheduled-posts` | Scheduled Posts |
| `buddynextpro-ai` | AI Feed |
| `buddynextpro-ai-mod` | AI Moderation |
| `buddynextpro-advanced-fields` | Advanced Fields |
| `buddynextpro-whitelabel` | White-label |
| `buddynextpro-space-brand` | Space Brand |
| `buddynextpro-custom-reactions` | Custom Reactions |
| `buddynextpro-stripe` | Stripe Settings |

Pro tabs register against their domain origin section (for example `monetization:tiers`, `growth:broadcasts`, `moderation:rules`) and are routed into the matching hidden-until-active sections by the placement map. The legacy `buddynextpro-*` page slugs remain registered so old bookmarks resolve, but they render inside the Hub chrome.

> The White-label tab is intentionally hidden from the IA via a `hidden` placement rule, even though Pro still registers its page. The underlying subsystem is slated for removal.

## The section / tab API

### Sections

`AdminHub::DEFAULT_SECTIONS` is keyed by a short section key, each entry carrying its `?page=` slug and label. One section is marked `top` (Settings) - its slug is shared with the top-level menu, so clicking "BuddyNext" lands on it.

```php
// AdminHub::sections() = DEFAULT_SECTIONS merged with the bn_admin_hub_sections filter.
'settings' => array( 'slug' => 'buddynext', 'label' => 'Settings', 'top' => true ),
'members'  => array( 'slug' => 'buddynext-members', 'label' => 'Members' ),
// ...
```

### Tabs

Feature classes contribute a tab from their `register()` method (or any code that runs before `admin_menu` priority 9):

```php
AdminHub::register_tab(
    string   $section,   // origin section key, e.g. 'settings'
    string   $slug,      // tab slug -> ?tab= value, e.g. 'general'
    string   $label,     // visible, already-translated label
    callable $render,    // body render callback
    array    $args = []  // cap, position, badge, icon, group, layout, subtitle, action
);
```

Recognised `$args` keys include `cap` (capability, default `manage_options`), `position` (lower sorts earlier), `badge` (a `fn(): int` that renders a counter pill when > 0), `icon` (a Lucide slug, auto-mapped from the tab slug when omitted), `layout` (`sidebar` default, or `wide` for list-detail editors), and `subtitle` / `action` for the standardized sub-header bar.

### Placement

`AdminHub::TAB_PLACEMENT` is the single source of truth for where each tab lands. It is keyed by the tab's origin `section:slug` and sets the final `section`, the sidebar `position`, and optional `hidden`. When `register_tab()` runs it applies the matching rule: a `hidden` rule drops the tab entirely, a `section` rule relocates it, a `position` rule reorders it.

This is why a feature can register `growth:broadcasts` while the tab actually renders under the Campaigns section - the placement map performs the move, and `AdminHub::tab_url( 'growth', 'broadcasts' )` resolves to the Campaigns page slug.

## The options-wiring model

The free plugin wires about 86 option keys (the manifest's `optionsWiring` count), the great majority prefixed `buddynext_` (a few legacy `bn_*` avatar keys and the core `admin_email` also appear). Each is read at its consumption point and written from its admin tab. Examples: `buddynext_site_name`, `buddynext_default_post_privacy`, `buddynext_space_creation_role`, `buddynext_banned_words`, `buddynext_enabled_reactions`.

Settings are registered in `Admin/Settings.php::register_settings()` so their `sanitize_callback` runs on save. Options are grouped **per tab**, not in one global bucket: `Settings::option_group( $option )` looks the option up in `TAB_OPTIONS` and returns `buddynext_{tab}` as the settings group (`option_page`). A save therefore only touches the active tab's options. The tab groups are:

```text
buddynext_general        buddynext_features       buddynext_registration
buddynext_social         buddynext_spaces         buddynext_moderation
buddynext_notifications  buddynext_email          buddynext_privacy
buddynext_integrations   buddynext_webhooks
```

Three options carry custom array sanitizers and are registered with explicit standalone `register_setting()` calls (in addition to flowing through their tab group):

| Option key | Type | Group | Sanitizer |
|------------|------|-------|-----------|
| `buddynext_features` | array | `buddynext_features` | `sanitize_features_option` |
| `buddynext_social_login` | array | `buddynext_registration` | `sanitize_social_login_option` |
| `buddynext_enabled_reactions` | array | `buddynext_social` | `sanitize_enabled_reactions` |

> An option with no `TAB_OPTIONS` entry falls back to the `buddynext` group. Keep new options in `SETTINGS_MAP` and `TAB_OPTIONS` so they sanitize on save and post to the right group.

## Examples

### Relocate or hide a tab from a mu-plugin

`bn_admin_hub_tab_placement` overrides the canonical placement map. Keys are the tab's origin `section:slug`.

```php
add_filter( 'bn_admin_hub_tab_placement', function ( array $map ) {
    // Hide a tab entirely.
    $map['settings:webhooks']['hidden'] = true;

    // Move the Social tab from Engagement to Notifications.
    $map['settings:social']['section']  = 'notifications';

    // Reorder a tab within its section (lower sorts earlier).
    $map['settings:reactions']['position'] = 5;

    return $map;
} );
```

### Add a new top-level section

`bn_admin_hub_sections` adds or renames a section. It appears in the sidebar only once a tab registers into it.

```php
add_filter( 'bn_admin_hub_sections', function ( array $sections ) {
    $sections['marketplace'] = array(
        'slug'  => 'buddynext-marketplace',
        'label' => __( 'Marketplace', 'my-ext' ),
    );
    return $sections;
} );
```

### Register a tab into a section

```php
add_action( 'init', function () {
    \BuddyNext\Admin\AdminHub::register_tab(
        'marketplace',           // origin section key
        'listings',              // tab slug
        __( 'Listings', 'my-ext' ),
        'my_ext_render_listings_tab',
        array(
            'cap'      => 'manage_options',
            'position' => 10,
            'icon'     => 'grid',
            'subtitle' => __( 'Manage marketplace listings.', 'my-ext' ),
        )
    );
} );
```

Build the link to it with `AdminHub::tab_url( 'marketplace', 'listings' )` rather than hand-assembling `?page=...&tab=...`, so a future placement move never breaks the URL.

## Notes / gotchas

- **`manage_options` everywhere.** Every page registrar and the Hub renderer gate on `manage_options`. A tab's own `cap` can tighten this further but the section page itself always requires `manage_options`.
- **Register tabs before `admin_menu` priority 9.** `AdminHub::build_menu()` runs at that priority; tabs registered later will not appear in their section's sub-menu.
- **Empty sections are hidden, not removed.** A section with no registered tab is skipped during menu build. Pro-only sections (Campaigns, Realtime & Push, Auto-Moderation, Monetization) stay hidden in free for this reason.
- **Origin section vs final section.** Always register against your tab's domain origin and let the placement map decide the final location. Resolve URLs and active-state through `AdminHub::tab_url()` / `is_tab_active()`, which apply the same placement, so a relocated tab keeps its assets and links.
- **Per-tab save scope.** Because options are grouped per tab, saving one tab never overwrites another tab's options. Add new settings to both `SETTINGS_MAP` (for sanitize-on-save) and `TAB_OPTIONS` (for the correct group).

See also Roles and Capabilities for the `manage_options`-vs-community-role distinction these screens rely on.
