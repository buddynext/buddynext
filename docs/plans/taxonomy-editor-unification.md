# Unified Taxonomy Editor — Member Types + Space Categories

Status: design (implementation deferred until the admin-header-refactor workflow
finishes, because that workflow edits `Spaces.php` and shared CSS — we must not
edit those concurrently).

## Why

Two admin "add a labelled thing" forms exist with very different UX:

- **Member Types** (`Admin/Members/MemberTypesManager.php`) — 10 controls: Name,
  Slug, Description, Badge Background, Badge Text, a separate **Preview** button,
  **Icon SVG paste**, Sort Order, "Show as directory filter", "Allow self-assign".
  Cluttered; the common case (a coloured label) is buried.
- **Space Categories** (`Admin/Spaces.php::render_categories_subtab()`) — 4
  controls: Name, Slug, Description, Sort. No colour. Also does its own inline
  `$wpdb` writes instead of going through `SpaceCategoryController` (duplicate
  logic).

Result: inconsistent, and member types feel heavy. Goal: **one shared, compact,
premium editor** used by both — common case is *Name + Colour + Save*, everything
else tucked under "Advanced."

## The shared component

A single config-driven editor, rendered by a shared partial
`templates/parts/taxonomy-editor.php` (loaded via `buddynext_get_template`),
styled by `assets/css/bn-admin-taxonomy.css`, with one small live-preview script
`assets/js/admin/taxonomy-editor.js`. Both managers call it with a config:

```php
buddynext_get_template( 'parts/taxonomy-editor', array(
    'entity'   => 'member-type',          // or 'space-category'
    'title'    => $edit ? __('Edit…') : __('Add…'),
    'action'   => 'bn_save_member_type',  // admin-post action
    'nonce'    => 'bn_save_member_type',
    'edit'     => $row,                    // row array or null
    'fields'   => array( 'color', 'icon' ),   // optional shared fields this entity supports
    'toggles'  => array(                       // entity-specific checkboxes
        array( 'name' => 'show_in_dir', 'label' => __('Show as directory filter'), 'default' => true ),
        array( 'name' => 'self_select', 'label' => __('Allow members to self-assign'), 'default' => false ),
    ),
) );
```

### Field layout (identical for both)

Primary (always visible):
1. **Name** (required) → JS auto-fills **Slug**.
2. **Colour** (single swatch) with an **inline live badge preview** beside it
   that updates as you type the name/colour. No separate "Preview" button.
3. **Description** (short, 2 rows).

**Advanced** (collapsed `<details class="bn-tax-advanced">`):
- **Slug** override (auto-derived from Name otherwise).
- **Text colour** override — default is auto-derived from the background for
  readable contrast (luminance check), so the common case needs no text-colour
  picker at all.
- **Icon** — icon picker first (existing `assets/icons/`); raw `<svg>` paste
  stays available but lives here, not up front.
- **Sort order**.

Entity toggles (from config) render in a consistent row at the bottom; Save +
Cancel use the standard buttons.

### Live preview

One shared `taxonomy-editor.js`: binds the name + colour (+ text-colour) inputs
and repaints `.bn-tax-badge-preview` live; also derives the readable default text
colour. Replaces the per-form bespoke preview + the separate Preview button.

## Schema parity (Free `bn_space_categories`)

Categories need colour/icon to reach parity. Add via an idempotent
`maybe_alter_tables`-style ALTER (INFORMATION_SCHEMA guard), Free Installer:

- `color VARCHAR(7) NOT NULL DEFAULT '#0073aa'`
- `text_color VARCHAR(7) NOT NULL DEFAULT '#ffffff'`
- `icon_svg MEDIUMTEXT NULL`
- `show_in_dir TINYINT(1) NOT NULL DEFAULT 1`

(`self_select` is member-type-only; categories don't get it.) `bn_member_types`
already has every column, so no change there.

## Save path (kill the duplicate)

- Member types: keep `admin_post_bn_save_member_type` → `MemberTypeService` (already correct).
- Space categories: route the editor's save through `SpaceCategoryController`
  (or a new `SpaceCategoryService::create/update`) instead of the inline `$wpdb`
  block in `Spaces.php` — removes the duplicated insert/slug logic. Add
  `SpaceService::get_categories_full()` (rows incl. colour) for the editor + the
  front-end directory colour.

## Rollout (after the header workflow lands)

1. Free Installer: ALTER `bn_space_categories` (idempotent) + bump schema version.
2. Shared partial + CSS + JS (new files — no conflict).
3. `MemberTypesManager`: swap its bespoke form for the shared partial.
4. `Spaces.php` categories subtab: swap its form for the shared partial; route
   saves through the controller/service (drop inline `$wpdb`).
5. Optional follow-up: paint category colour on the front-end Spaces directory
   (badge/border) so the colour is actually used.
6. WPCS + `php -l` + PHPStan clean; browser-verify both editors (desktop + 390px),
   incl. the live preview and the auto-derived text colour.

## Consistency with the header refactor

The editor lives inside the screen body in a `.bn-settings-section` card, under
the unified header the workflow is building — complementary, no overlap. It uses
the same token vocabulary; no new nav/segment styles (those belong to the header
system).
