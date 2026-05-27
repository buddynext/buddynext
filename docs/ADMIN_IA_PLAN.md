# BuddyNext admin information architecture

The wp-admin surface is consolidated under a single top-level menu, "BuddyNext", with **section pages dispatched via tabs**. Every section page is owned by `BuddyNext\Admin\AdminHub`. Feature classes contribute a tab by calling `AdminHub::register_tab()` from their `register()` method — they no longer call `add_submenu_page()` directly.

This document is the policy. Future PRs are checked against it.

## The 6 sections

| Section | Page slug | Tabs (today) |
|---|---|---|
| **Settings** | `buddynext` *(shared with top-level)* | General · Navigation · Integrations · Email |
| **Members** | `buddynext-members` | Directory |
| **Spaces** | `buddynext-spaces` | Directory |
| **Moderation** | `buddynext-moderation` | *(empty — hidden)* |
| **Growth** | `buddynext-growth` | *(empty — hidden)* |
| **Monetization** | `buddynext-monetization` | *(empty — hidden)* |

Sections with zero registered tabs do not appear in the sub-menu. New core features or companion plugins slot a tab in by calling `AdminHub::register_tab( 'moderation', 'rules', __( 'Rules' ), [ $this, 'render_page' ] )` — the Moderation section appears automatically.

## How a feature contributes a tab

```php
namespace BuddyNext\Module;

use BuddyNext\Admin\AdminHub;

class MyFeature {
    public function register(): void {
        // Save handlers, asset enqueue, etc. as before.
        add_action( 'admin_post_my_save', [ $this, 'handle_save' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Contribute the tab. Order of register_tab calls = order in the strip.
        AdminHub::register_tab(
            'growth',                                 // section key
            'broadcasts',                             // tab slug — URL ?tab= value
            __( 'Broadcasts', 'buddynext' ),          // visible label
            [ $this, 'render_page' ],                 // body callback
            'manage_options'                          // (optional) capability
        );
    }
}
```

Asset enqueue guards check `AdminHub::is_active( $section, $tab )` — the same helper used by the dispatcher, so there's one source of truth for "am I the visible tab?".

## What the Hub paints

- `<header class="bn-admin-hub__header">` with the section H1 only
- `<nav class="bn-admin-hub__tabs">` — visible only when 2+ tabs are registered
- `<div class="bn-admin-hub__body">` — the active tab's body

Feature `render_page()` callbacks own everything inside `__body`. They must NOT render a `<div class="wrap">`, a top-level H1, or their own tab strip — the Hub owns all three. (Existing render methods still emit these and will be flattened in follow-up PRs; the Hub tolerates them but the visual nesting is a bug, not a feature.)

## Chrome rules

These come from the wizard cleanup and apply to every admin surface:

- **Accent is signal, not decoration.** `var(--bn-accent)` lights up active tabs, focus rings, primary buttons, selected borders, checkmarks. It NEVER paints decorative backgrounds. Never use `color-mix( in oklch, var(--bn-accent) X%, ... )` for panel fills — that produces unpredictable hues when the brand colour isn't blue.
- **No AI gradients.** No purple → pink → teal palettes anywhere.
- **No emoji in markup.** Use `\BuddyNext\Core\IconService::render( 'slug' )` for Lucide SVGs.
- **Tap targets ≥ 44px tall, gaps ≥ 8px** between adjacent interactive elements.
- **One escape hatch per page.** Never combine a top-link "Skip setup" with a footer "Skip for now" — pick one.

## Permission model

Per-tab capability check happens twice: at registration (Hub trusts the registered `$cap`) and at render time (`current_user_can()` inside the dispatch). Feature `render_page()` callbacks can still gate sub-sections inside themselves if needed.

The wp-admin baseline is `manage_options` — site administrators. Per-space management is a separate front-end surface (see below) so non-admin space owners are reachable.

## Per-space management — front-end, not wp-admin

A space owner or moderator who isn't a site admin cannot use wp-admin. Per-space management therefore lives on the **front-end** at `/space/<slug>/manage`, with its own tabs: General · Privacy · People · Moderation · Advanced. wp-admin's Spaces section is a directory + admin-only switches (feature, archive, force-delete, reassign owner). It NEVER duplicates the per-space editor.

This per-space surface is not yet implemented; the policy is documented here so it lands in the right place when it's built.

## Migration phases

- **Phase 1 (done):** `AdminHub` owns the top menu. All 6 native admin classes register via `AdminHub::register_tab()`. Stale `add_menu_page` / `add_submenu_page` calls in those classes are dead code, safe to delete in cleanup.
- **Phase 2:** flatten `Settings::render_page()`'s internal General/Features/Registration/… tab strip into Hub tabs so the nested-tab artefact goes away. Each existing internal section becomes a `register_tab( 'settings', '<key>', ... )` call.
- **Phase 3:** per-space front-end `/space/<slug>/manage` surface, plus an admin Dashboard landing page (counters, things-needing-attention, quick actions).

## Anti-patterns to refuse in PR review

- Adding a new sub-menu entry under `buddynext` parent slug via `add_submenu_page()`. Always go through `AdminHub::register_tab()`.
- Painting a coloured panel using `color-mix` of the accent. Use `--bn-sunken` / `--bn-surface`.
- Rendering a second `<div class="wrap">` or top-level H1 inside a tab body — collides with the Hub's chrome.
- Bumping version strings in PR. BuddyNext is pre-release.
- Mocking the database in integration tests.

## See also

- `assets/css/bn-onboarding.css` — wizard CSS, the reference for neutral-chrome rules.
- `assets/css/bn-admin.css` — Hub tab-strip styles (`.bn-admin-hub__*` block).
- `docs/v2 Plans/v2/` — visual prototypes for individual surfaces.
- `includes/Admin/AdminHub.php` — the implementation of this policy.
