> **MERGED → `docs/plans/spaces-master-plan.md` (Layer 0).** This is now the detailed spec for the
> foundation layer of the consolidated Spaces plan; build order, scale context, and the autoload-P0 fold-in
> live in the master plan. Keep this doc for the table/REST/field-API detail; treat the master as the index.

# Plan: Space Metadata (`bn_space_meta`) — the extensibility substrate for Spaces

Status: PLAN. Free. Adds ONE table (`bn_space_meta`) so Spaces gain a first-class, WordPress-native
metadata API — the data flow a community developer expects. Spaces go 4 -> 5 tables; this is the LAST
structural table Spaces need (every future per-space attribute becomes a meta row, not a new table/column).
Audience scale: 10,000+ installs.

## Why (designed from the developer's expected data flow, not from our existing tables)
A developer extending a community platform expects the WP metadata mental model, uniform across entities —
the same thing they already use for posts/users, and the same shape BuddyPress gives for groups
(`groups_update_groupmeta`). Spaces have NO meta store today (the 4 space tables carry no meta), so a
developer cannot attach arbitrary data to a space. This plan closes that gap with the idiomatic pattern WP
core (`postmeta`/`usermeta`/`termmeta`), BuddyPress (`bp_groups_groupmeta`), and our own profiles
(`bn_profile_values`) all use: a per-key, indexed meta table behind the standard metadata API.

The expected contract:
1. **Meta quartet:** `get_space_meta($id,$key,$single)`, `add/update/delete_space_meta()`.
2. **Typed registration:** `register_meta('bn_space', $key, [type, single, sanitize_callback, auth_callback, show_in_rest])` — like `register_post_meta()`.
3. **Directory queryable by meta:** `WP_Meta_Query` support so a developer can build location/attribute
   discovery (the prospect's lat/lng locator) even though our DEFAULT UI stays lean.
4. **REST `meta`:** `GET/POST /spaces/{id}` exposes a `meta` object like `wp/v2/posts`.
5. **Built-in object cache** for meta reads, lazy-loaded.

## Why NOT the alternatives (rejected, with reasons — so we don't thrash again)
- **wp_options** — space data in a globally-contended core table = waste; not queryable; not what a dev expects.
- **JSON column on `bn_spaces`** — fails the contract: no `meta_query`/index, a read-modify-write
  concurrency clobber (two writers lose each other's keys), bloats the hot `SELECT *` directory query, and
  hands the developer no standard meta API. Acceptable only for private display blobs, NOT an extensibility primitive.
- **Generalize `bn_profile_values` to an `object_type` meta store** — zero net new tables but a risky refactor
  of the live profiles subsystem, and *less* WP-idiomatic than a per-object meta table. Not worth the blast radius.
- **No new table at all** — would mean shipping a half-cooked, non-standard data flow. The one meta table IS
  the right table to add; it ends Spaces' structural growth rather than starting it.

## Table — `bn_space_meta` (WP-meta-shaped, native-API compatible)
```sql
CREATE TABLE {prefix}bn_space_meta (
    meta_id     BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    bn_space_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,   -- {meta_type}_id, required by WP metadata API
    meta_key    VARCHAR(255) DEFAULT NULL,
    meta_value  LONGTEXT DEFAULT NULL,
    PRIMARY KEY (meta_id),
    KEY bn_space_id (bn_space_id),
    KEY meta_key (meta_key(191))
) {charset};
```
- Column is `bn_space_id` because WP's `update_metadata($meta_type,…)` derives the id column as
  `{$meta_type}_id`. We use **`meta_type = 'bn_space'`** (namespaced — avoids collision with any other
  plugin's generic `space` meta type).
- Indexes mirror core meta tables: by object id (fetch a space's meta) and by `meta_key(191)` (`WP_Meta_Query`).
- Added via the existing schema path: bump `Installer::SCHEMA_VERSION`, add to the `bn_space_*` CREATE TABLE
  block (fresh installs) and the idempotent dbDelta back-fill (upgrades). **No data migration** — new empty
  table; existing spaces simply have no meta yet. Dormant, zero behavior change.

## Wiring WordPress's native metadata API to our table — THE LOAD-BEARING STEP
Verified against `wp-includes/meta.php`: `_get_meta_table($type)` returns `$wpdb->{$type}meta`, and the
object-id column is `sanitize_key($type . '_id')`. For `meta_type = 'bn_space'` that means WP looks up the
`$wpdb` property **`bn_spacemeta` (NO underscore)** and the column **`bn_space_id`**.

**Name mismatch we MUST bridge:** our table is `bn_space_meta` (underscore, repo convention) but WP resolves
the property `bn_spacemeta`. If the alias below is missing, `_get_meta_table('bn_space')` returns `false` and
**every `get/add/update/delete_metadata` silently returns false — you cannot add a field.** This is the single
point of failure for the whole feature.
```php
// Alias WP's resolver to our physical table. Refresh on blog switch for multisite correctness.
$wpdb->bn_spacemeta = $wpdb->prefix . 'bn_space_meta';
add_action( 'switch_blog', static function () use ( $wpdb ) {
    $wpdb->bn_spacemeta = $wpdb->prefix . 'bn_space_meta';
} );
```
(Our schema column is `bn_space_id` and PK is `meta_id` — both already match what core derives. ✓) With the
alias in place, `get_metadata('bn_space', …)`, `add/update/delete_metadata('bn_space', …)`,
`register_meta('bn_space', …)`, `update_meta_cache('bn_space', $ids)`, and `WP_Meta_Query` all work natively —
including WP's meta object cache (group `bn_space_meta`). Without the `switch_blog` refresh, multisite writes
after `switch_to_blog()` hit the wrong blog's table (WP recomputes registered `$wpdb->tables`, not ad-hoc aliases).

### Ergonomic wrappers (the public developer API)
Thin, documented helpers over the native calls (what devs actually call / what we document):
```php
get_space_meta( int $space_id, string $key = '', bool $single = false )   // -> get_metadata
add_space_meta( int $space_id, string $key, $value, bool $unique = false ) // -> add_metadata
update_space_meta( int $space_id, string $key, $value, $prev = '' )        // -> update_metadata
delete_space_meta( int $space_id, string $key, $value = '' )               // -> delete_metadata
```
Per-key rows mean `add/update/delete` of one key is atomic — **no read-modify-write clobber** (the JSON-blob bug is structurally impossible here).

## Typed fields layer (`FieldType` on top, not as storage)
`FieldType` (`includes/Profile/FieldType.php`, already fully decoupled) becomes the TYPE behavior over meta,
never the storage. A single registration helper unifies register_meta + FieldType + admin/display metadata:
```php
buddynext_register_space_field( 'location', [
    'label'        => __( 'Location', '…' ),
    'type'         => 'text',          // any FieldType type
    'single'       => true,
    'show_in_rest' => true,
    'searchable'   => true,            // fold into the space search index (PUBLIC fields only)
    'visibility'   => 'public',        // 'public' (anyone who can see the space) | 'members' (space members only)
    'show_on'      => 'all',           // 'all' | 'page' | 'group'
    'sort_order'   => 10,
] );
// internally: register_meta('bn_space','location', [
//   'single'=>true,'show_in_rest'=>true,
//   'sanitize_callback' => fn($v)=>FieldType::sanitize($def,$v),
//   'auth_callback'     => owner/mod gate,
// ]) + records label/type/searchable for UI, REST, and the search-fold.
```
Developers register fields in code (the expected pattern). Site-owner-managed fields via an admin
field-builder UI (reusing the profile field-builder) is a clean follow-on that simply drives the same
`buddynext_register_space_field()` — out of this core primitive's scope, noted under Phases.

## Directory: lean default UI, rich data layer
- **Default directory UX is unchanged** — search box + category chips + existing sort. We do NOT add a filter
  control per field (UX scope decision: [[spaces-custom-fields-search-not-filter]]).
- **But the data layer supports `meta_query`** so a developer can build their own filtered/geo directory:
  ```php
  SpaceService::list_spaces([ 'meta_query' => [[ 'key'=>'region','value'=>'EU' ]] ]);
  // internally: WP_Meta_Query->get_sql('bn_space', "{$wpdb->prefix}bn_spaces", 'id') -> JOIN bn_space_meta
  ```
  `meta_query` is OPT-IN per call; the default directory query passes none, so its cost and shape are
  unchanged. Richer-than-default-UI data layer is correct, not contradictory.
- **Discovery for our own UI = search-fold:** a registered `searchable` field's value is appended to the
  space's `content` in `bn_search_index` (`object_type='space'`) at (re)index time — found by the normal
  search box, no new UI, no per-request cost.

## Read paths & scale (10k-site)
- **List/hydrate stays lean.** `hydrate()` does NOT touch meta — directory cards don't render custom fields,
  so no meta read in list context. (Meta is a separate table now, so there is no `SELECT *` bloat either —
  another win over the JSON column.)
- **Detail page** reads a space's meta in **one** indexed query (`update_meta_cache('bn_space',[$id])` /
  `get_space_meta($id)`), then WP-cached. Registered fields render via `FieldType::render_display()`.
- **`meta_query` directory** (developer opt-in) uses indexed JOINs on `bn_space_id` + `meta_key(191)` —
  big-site-checklist compliant (indexed WHERE/JOIN, bounded, paginated).
- **Cleanup:** `SpaceService::delete()` calls `delete_metadata('bn_space',$space_id)`-equivalent (or a single
  `DELETE … WHERE bn_space_id=%d`) so a deleted space leaves no meta rows. Replaces the old option-suffix cleanup.

## REST surface (app-ready — the app consumes ONLY REST)
Our `SpaceController` is custom (not `WP_REST_Posts_Controller`), so `show_in_rest` won't auto-wire — we
expose everything explicitly, driven by the registry. A native app cannot read PHP `register_meta`, so the
**field SCHEMA must be a REST endpoint** (so the app can render forms + displays generically) AND the
per-space **values** must be readable/writable.

1. **`GET /spaces/fields` — field SCHEMA (NEW; the app-readiness keystone).** Returns the registered space
   fields so a client can build forms/displays without hardcoding:
   ```json
   [ { "key":"location","label":"Location","type":"text","options":[],
       "required":false,"visibility":"public","searchable":true,"show_on":"all","sort_order":10 } ]
   ```
   Public schema (definitions aren't sensitive). Cached. This is what the app reads once at startup.
2. **`GET /spaces/{id}` — values, viewer-filtered.** Includes a `fields` array, registry-driven and
   empty-handled, with BOTH a typed value and a human display string (so the app renders natively, not from HTML):
   ```json
   "fields":[ { "key":"location","label":"Location","type":"text",
                "value":"Berlin","display":"Berlin","visibility":"public" } ]
   ```
   `members`-visibility fields are omitted for non-members (server-side, never CSS). Also exposes a raw
   `meta` object (public keys) for generic clients.
3. **`POST`/`PUT /spaces/{id}` — write.** Accepts a `fields` (or `meta`) object. Each key is validated
   against the registry, owner/mod auth-gated, run through the field's `sanitize_callback` (`FieldType::sanitize`),
   then `update_space_meta`; the space is reindexed. Unknown keys dropped; invalid values return **400 with
   per-field error messages** (so the app can show inline validation).
4. **`GET /spaces` (list) — lean by default, app-pragmatic opt-ins.** No fields by default (directory stays
   lean). Two opt-ins for app list/map views, both bounded (one batched `update_meta_cache('bn_space',$page_ids)`
   — NO N+1): `?include_fields=location,region` embeds those PUBLIC fields per row; `?meta_query[…]` (or
   `?meta_key=&meta_value=`) filters via `WP_Meta_Query`. Default call passes neither and is unchanged.

All field responses are driven by the same registry the web templates use — one contract, two clients.

## UX / Display contract (web + app — the 2nd gap, closed)
"How do these fields display" is answered ONCE as a data-driven contract both clients render from. The web
renders rich HTML; the app renders natively from typed data. To serve both, `FieldType` gains two formatters
(it currently has only HTML `render_display`):
- `FieldType::display_text( $field, $value ): string` — plain, human-readable, **no HTML** (date localized,
  `select`/`radio` -> option *label* not raw value, multiselect -> comma list, number -> `number_format_i18n`,
  url/email -> the literal string). Feeds the REST `display`.
- `FieldType::rest_value( $field, $value )` — the typed scalar/array (`string|int|bool|array`) for the REST
  `value`, so app logic gets real types.
- `FieldType::render_display( $field, $value ): string` — existing; escaped **HTML** for web templates
  (url -> `<a rel="noopener">`, email -> `mailto:`, textarea -> `wpautop`+`wp_kses`, select -> label, etc.).

**Web display (where + how):**
- A new **`templates/parts/space-details-panel.php`** renders an "About / Details" block on the space home
  (`templates/spaces/home.php`), driven by `SpaceService::get_space_fields($id,$viewer)`.
- Loop fields in `sort_order`; **skip empty values; skip fields the viewer can't see** (members-only gated
  server-side). If no visible non-empty fields -> **render nothing** (no empty card).
- Semantic markup: a `<dl>` (`<dt>` label / `<dd>` value via `render_display()`); ARIA-clean, keyboard-safe.
- Tokens only (`var(--bn-*)`), `margin-inline-*`, dark-mode tokens, RTL-safe; `@media (max-width:640px)`
  stacks rows. Verified at 390px + dark (per repo Definition of Done).
- `show_on` lets a field target Page-mode vs Group-mode vs all (ties to [[spaces-page-mode-is-who-can-post]]).

**Owner edit (where + how):**
- A new **`templates/parts/space-settings-panel-details.php`** loops registered fields -> `FieldType::render_input()`,
  saves via the existing space-settings POST handler (sanitize -> `update_space_meta` -> reindex). Hidden when
  no fields are registered; shows per-field validation errors.

**App display (where + how):**
- App fetches `GET /spaces/fields` (schema) once + `GET /spaces/{id}` (`fields[]` with `value`+`display`).
- Renders natively by `type`: text/textarea -> text, url -> tappable link, email -> mail intent, date -> native
  date, select -> label, multiselect -> chips, a `location`/lat-lng pair -> map pin. No HTML dependency.
- App edit forms are generated from the schema (`type` -> native input); submit the `fields` object to `PUT`;
  show inline errors from the 400 response. One schema drives form + display on every platform.

## Sub-spaces & large-scale readiness
**Meta works for sub-spaces unchanged** — each space (root or sub) has its own `id`, so `bn_space_meta` rows
keyed by `bn_space_id` apply identically. **Decision: NO field inheritance** — a sub-space owns its own meta
and does NOT auto-inherit the parent's values (predictable; inheritance could later be an opt-in
registered-field flag). The `show_on` flag can target a level.

**Found gaps in the EXISTING sub-space feature (not introduced here, but block "large spaces ready"):**
1. **`parent_id` is unindexed** on `bn_spaces` (KEYs are only owner/category/is_archived) while
   `WHERE parent_id = %d` / `parent_id IS NULL` queries run -> table scans at scale. **Fix: piggyback
   `KEY parent (parent_id)` into THIS migration (TG-0)** since we already bump `SCHEMA_VERSION` — one extra
   line, real large-space win. (Owner approval pending — flagged, not silently bundled.)
2. **No paginated children-list** — there is `COUNT(parent_id)` (cap) and a root-spaces query, but no
   `list_sub_spaces($parent_id)` and no `GET /spaces/{id}/sub-spaces` route. Create-without-list.
3. **Directory intermingles sub-spaces** — `list_spaces()` does not filter `parent_id IS NULL`, so sub-spaces
   flood the top directory; no `?parent_id` filter.
4. **Parent picker UX is unbounded.** The create-modal parent select EXISTS and is correct for typical use
   (conditional, owned-roots, inline errors — `create-space-modal.php:149`), but `owned_root_spaces()` is
   `SELECT * … WHERE owner_id=%d AND parent_id IS NULL ORDER BY name` with **no LIMIT** -> a power owner gets
   an unbounded query + a huge `<select>`. Fix: a bounded, type-to-search async parent picker (the TG-0
   `parent` index helps the query). Also reconcile owned-only-vs-`manage`-cap (REST allows managers, UI lists owners).

Gaps 2-4 are **sub-space completeness**, a SEPARATE sibling epic (children-list + REST + directory grouping +
searchable parent picker + hierarchy display/app nesting) — NOT folded into custom-fields (avoid scope-mess).
Only the index (gap 1) rides this migration.

## No conflict with profiles
Profiles: defs in `bn_profile_fields`, values in `bn_profile_values` (object_type 'user'). Spaces: meta in
`bn_space_meta` (meta_type 'bn_space'). Separate tables, separate meta_type, separate search object_type.
Shared only the stateless `FieldType` engine and the `buddynext_field_types` type registry. A profile
`location` and a space `location` cannot collide.

## Migration (gap-free)
- **Fresh install:** add the `bn_space_meta` CREATE TABLE to `Installer::statements()` inside the `bn_space_*`
  block (next to `bn_space_bans`).
- **Upgrade:** bump `Installer::SCHEMA_VERSION` 10 -> 11. `run()` already gates on the stored
  `buddynext_schema_version` and calls `dbDelta()` over all statements; dbDelta creates the new table
  idempotently and leaves existing tables untouched. **No data migration** (new empty table).
- **Multisite:** creation rides the plugin's existing per-site activation/dbDelta path; the `$wpdb->bn_spacemeta`
  alias is refreshed on `switch_blog` (see wiring) so writes always target the active blog's table.
- **Uninstall:** add `bn_space_meta` to `uninstall.php`'s drop list (CLOSE THIS GAP — otherwise the table
  orphans on uninstall).
- **Verify:** `wp db tables | grep bn_space_meta` present on BOTH a fresh install and an upgraded site.

## Implementation — files to add / modify (gap-free)
**Add:**
- `includes/Spaces/SpaceMeta.php` — boot wiring (`$wpdb->bn_spacemeta` alias + `switch_blog` refresh), the
  `get/add/update/delete_space_meta()` wrappers, `get_space_fields($id,$viewer)` resolver (visibility-filtered,
  empty-skipped, sorted), and the schema export for the REST `/spaces/fields` endpoint.
- `includes/Spaces/SpaceFieldRegistry.php` — `buddynext_register_space_field()` + `buddynext_space_fields()`
  (static-cached registry) layered over `register_meta('bn_space',…)` + `buddynext_field_types`.
- `templates/parts/space-details-panel.php` — web "About / Details" display block.
- `templates/parts/space-settings-panel-details.php` — owner edit panel.
- `assets/css/bn-space-details.css` — token-driven styles (or extend an existing space CSS file).
- Tests: `tests/Spaces/SpaceMetaTest.php`, `SpaceFieldsRestTest.php`, `SpaceMetaQueryTest.php`,
  `SpaceFieldDisplayTest.php`.

**Modify:**
- `includes/Core/Installer.php` — CREATE TABLE + `SCHEMA_VERSION` bump.
- `uninstall.php` — drop `bn_space_meta`.
- `includes/Profile/FieldType.php` — add `display_text()` + `rest_value()` (engine is generic; both reusable by profiles too).
- `includes/Spaces/SpaceController.php` — `GET /spaces/fields`; `fields`/`meta` in `GET /spaces/{id}`;
  `fields` write on `POST/PUT /spaces/{id}` (validate/auth/sanitize/400-errors); `?include_fields` + `?meta_query` on `GET /spaces`.
- `includes/Spaces/SpaceService.php` — `meta_query` in `list_spaces()` (opt-in `WP_Meta_Query`); `get_space_fields()`;
  delete a space's meta in `delete()`.
- `includes/Search/SearchIndexListener.php` — fold PUBLIC searchable field values into the space index; reindex on meta write.
- `includes/Core/Plugin.php` — register `SpaceMeta` boot + the `switch_blog` listener.
- `templates/spaces/home.php` — include the details panel.
- `CLAUDE.md` (Database Tables + Recent Changes), `audit/manifest.json` (table + endpoints + meta API).

## Build order (each step independently shippable + testable)
1. **Migration** — `SCHEMA_VERSION` 10->11; `bn_space_meta` in CREATE TABLE block + dbDelta; `uninstall.php` drop.
2. **Native-API wiring** — `$wpdb->bn_spacemeta` alias + `switch_blog` refresh; meta cache group; `*_space_meta()` wrappers.
3. **Registry** — `buddynext_register_space_field()` over `register_meta` + `FieldType` (label/type/visibility/searchable/show_on/sort).
4. **FieldType formatters** — add `display_text()` + `rest_value()`.
5. **REST** — `GET /spaces/fields` (schema); `fields`+`meta` on `GET /spaces/{id}` (viewer-filtered);
   validated `fields` writes + 400 errors on `POST/PUT /spaces/{id}`; `?include_fields` + `?meta_query` on `GET /spaces`.
6. **`meta_query` in `SpaceService::list_spaces()`** (opt-in; `WP_Meta_Query` JOIN; batched `update_meta_cache` for `include_fields`).
7. **Search-fold** — PUBLIC searchable meta into the space index + reindex on meta write.
8. **Cleanup** — delete a space's meta rows on `SpaceService::delete()`.
9. **Web UX** — `space-details-panel.php` (display) + `space-settings-panel-details.php` (owner edit); verify 390px + dark.
10. *(Follow-on, not core)* owner field-builder admin UI driving `buddynext_register_space_field()`.

## Implementation plan — task groups + per-group verification gate
Execute in order. **Each group has a gate that MUST be green before the next starts.** Every gate ends with
the same three "no-new-mess" guards (so a regression is caught at the group that caused it, not at the end):
- **G-a Quality:** `bin/check.sh --staged` (PHP lint + WPCS + PHPStan L5 + UX audit) clean on touched files.
- **G-b Full suite:** `vendor/bin/phpunit` green — the ENTIRE free suite, not just new tests (proves no regression).
- **G-c Dormant invariant:** with NO fields registered, every existing space behavior is byte-identical
  (the feature ships off). Re-run a space smoke; nothing about spaces changes until a field is registered.

### TG-0 — Migration & table  (foundation; no deps)
- **Goal:** `bn_space_meta` exists on fresh install + upgrade; dropped on uninstall. **+ (pending owner OK)
  add `KEY parent (parent_id)` to `bn_spaces`** — large-space readiness for sub-space queries, piggybacked on
  the same `SCHEMA_VERSION` bump (one extra dbDelta line; no separate migration).
- **Files:** `includes/Core/Installer.php` (CREATE TABLE + `parent_id` KEY + `SCHEMA_VERSION` 10->11), `uninstall.php` (drop).
- **Verify gate:**
  - Fresh: drop DB tables, activate, `wp db tables | grep bn_space_meta` -> present; `DESCRIBE` shows
    `meta_id`/`bn_space_id`/`meta_key`/`meta_value` + the two KEYs.
  - Upgrade: set `buddynext_schema_version`=10, reload, confirm the table is created and (if approved) the
    `parent` index added; no other table altered.
  - Index proof: `EXPLAIN SELECT … WHERE parent_id = N` uses the `parent` key (not a full scan).
  - Uninstall path: dry-run the uninstall drop list includes the table.
  - G-a, G-b, G-c.

### TG-1 — Native metadata wiring  (deps: TG-0)
- **Goal:** WP metadata API targets our table; multisite-safe.
- **Files:** `includes/Spaces/SpaceMeta.php` (alias + `switch_blog` + `*_space_meta()` wrappers), `includes/Core/Plugin.php` (boot).
- **Verify gate (this is the load-bearing one):**
  - `wp eval` round-trip: `update_space_meta($id,'k','v'); echo get_space_meta($id,'k',true);` -> `v`; 2nd read = cache hit.
  - **Negative:** temporarily unset the alias -> `update_metadata('bn_space',…)` returns false (proves the alias is mandatory; keep this as a unit test, not a manual step).
  - Multisite: in a network, `switch_to_blog($b)` then write -> row lands in blog `$b`'s table, not the main site's.
  - G-a, G-b, G-c.

### TG-2 — Field registry + FieldType formatters  (deps: TG-1)
- **Goal:** register typed fields; engine can format for web + app.
- **Files:** `includes/Spaces/SpaceFieldRegistry.php`, `includes/Profile/FieldType.php` (+`display_text()`,`rest_value()`).
- **Verify gate:**
  - Register a `text`, `select`, `url`, `date` field; `buddynext_space_fields()` returns all four with resolved defs.
  - `display_text(select,$v)` -> the option *label*; `date` -> site-format; `render_display(url,$v)` -> `<a rel="noopener">`.
  - `FieldType` profile tests still pass (engine change didn't break profiles) — explicit cross-check.
  - G-a, G-b, G-c.

### TG-3 — REST surface  (deps: TG-2; app-readiness)
- **Goal:** schema + values fully REST-driven.
- **Files:** `includes/Spaces/SpaceController.php`.
- **Verify gate (all via REST — the app's only surface):**
  - `GET /spaces/fields` -> the schema array (key/label/type/options/visibility/show_on).
  - `GET /spaces/{id}` -> `fields[]` with `value`+`display`; a `members`-visibility field is ABSENT as a non-member, PRESENT as a member.
  - `PUT /spaces/{id}` with a valid `fields` payload persists; with an invalid value -> 400 + per-field message; as a non-owner -> 403.
  - `GET /spaces` default response unchanged (byte-diff vs pre-TG-3); `?include_fields=k` adds the field with ONE extra meta query (assert query count).
  - G-a, G-b, G-c.

### TG-4 — Directory meta_query + search-fold  (deps: TG-3)
- **Goal:** developer discovery + our search box find field values; no leak, no N+1.
- **Files:** `includes/Spaces/SpaceService.php`, `includes/Search/SearchIndexListener.php`.
- **Verify gate:**
  - `list_spaces(['meta_query'=>[['key'=>'region','value'=>'EU']]])` returns only matching spaces (indexed JOIN; check `EXPLAIN` uses the `meta_key` index).
  - Default `list_spaces()` issues NO meta JOIN (query-count assertion).
  - After writing a PUBLIC searchable field, `GET /search?type=space` finds it; a `members`-only value is NOT found.
  - G-a, G-b, G-c.

### TG-5 — Web UX (display + owner edit)  (deps: TG-2/3)
- **Goal:** fields display correctly; owner can edit; no empty-state mess.
- **Files:** `templates/parts/space-details-panel.php`, `templates/parts/space-settings-panel-details.php`, `templates/spaces/home.php`, `assets/css/bn-space-details.css`.
- **Verify gate (browser — Playwright MCP, per repo rule):**
  - With values: Details panel renders sorted, typed (url is a link, date localized), members-only hidden for guests.
  - With none: panel/section is ABSENT (no empty card).
  - Owner edit: change a value -> save -> reflected on the space + in `GET /spaces/{id}`.
  - Screenshot desktop + **390px** + **dark**; check focus rings on inputs/links.
  - G-a, G-b, G-c.

### TG-6 — Cleanup, docs, manifest  (deps: all)
- **Goal:** no orphans; inventory current.
- **Files:** `includes/Spaces/SpaceService.php` (`delete()` removes meta), `CLAUDE.md`, `audit/manifest.json`.
- **Verify gate:**
  - Delete a space with meta -> `SELECT COUNT(*) FROM bn_space_meta WHERE bn_space_id=$id` = 0.
  - `/wp-contract-audit` clean (no read-never-written / orphan keys introduced).
  - `audit/manifest.json` refreshed (table + `/spaces/fields` + `fields` on space endpoints); CLAUDE.md tables + Recent Changes updated.
  - G-a, G-b, G-c, plus a final FULL `bin/check.sh` (with UX audit) + a combo free+pro smoke.

### "No new mess" — the standing guarantees
- **Dormant by default:** zero registered fields = zero behavior change. Existing space flows are untouched until a field is registered (the G-c invariant at every gate).
- **No new wp_options, no JSON-on-hot-row:** values live only in `bn_space_meta`; `bn_spaces`/`SELECT *`/list path are unchanged.
- **Regression caught per-group:** the full suite (G-b) runs at every gate, so a break is attributed to the group that caused it.
- **No cross-subsystem damage:** profile-fields tests re-run in TG-2; contract audit + REST-boundary check in TG-6.
- **One source of truth:** the registry drives admin UI, REST schema, REST values, web display, and search — no parallel field lists to drift.

## Test plan (TDD — failing test first, per repo standard)
- Migration: after upgrade `bn_space_meta` exists with the WP-meta shape; existing spaces have 0 rows.
- **Alias guard (load-bearing):** with the `$wpdb->bn_spacemeta` alias set, `_get_meta_table('bn_space')`
  returns `{prefix}bn_space_meta`; assert a write+read round-trips. Negative test: without the alias,
  `update_metadata('bn_space',…)` returns false (proves why the wiring step is mandatory).
- Native API: `update_space_meta`/`get_space_meta` round-trip via WP `get_metadata('bn_space',…)`; cache hit on 2nd read.
- Concurrency: two `update_space_meta` calls on different keys both persist (no clobber) — the JSON-blob race cannot occur.
- Registered field: `buddynext_register_space_field()` → REST `GET /spaces/{id}` returns it under `fields`; write round-trips through `FieldType::sanitize`.
- Schema endpoint: `GET /spaces/fields` returns the registry (key/label/type/options/visibility/show_on) for the app.
- Display formatters: `display_text()` returns plain human text (date localized, select->label); `render_display()` returns escaped HTML; `rest_value()` returns the typed value.
- Visibility: a `members`-visibility field is OMITTED from `GET /spaces/{id}` and from search for a non-member; visible to a member.
- Auth: a non-owner cannot write space meta via REST (auth gate); invalid value returns 400 with a per-field message.
- meta_query: `list_spaces(['meta_query'=>…])` filters via indexed JOIN; default `list_spaces()` issues NO meta JOIN/read.
- include_fields: `GET /spaces?include_fields=location` embeds the field per row with ONE batched `update_meta_cache` (no N+1).
- N+1 guard: `GET /spaces` (default) issues identical query count with 0 vs 20 registered fields.
- Search: a unique PUBLIC searchable meta value is findable via `GET /search?type=space`; a `members`-only value is NOT.
- Profile isolation: a profile field and a space field sharing a key keep independent values.
- Cleanup: deleting a space removes all its `bn_space_meta` rows; uninstall drops the table.

## Long-term stability audit (10,000+ installs) — residual risks + verdicts
1. **Search visibility (safe).** `bn_search_index` has a `visibility` column and `search()` filters
   `visibility='public'`; the space index row carries the space's visibility and the fold only appends to
   content, so secret/private space meta values do NOT leak in search. Keep the fold on the existing space-index write.
2. **Orphan meta when a field DEFINITION is unregistered.** Rows persist but are inert — render/REST only
   surface registered keys; `meta_query` against an unregistered key simply returns nothing. Cost is one
   indexed table, not the hot row. Optional `wp buddynext spaces prune-meta` CLI. Out of v1 scope.
3. **Reindex lag for a newly-registered searchable field.** Existing spaces aren't searchable on it until
   re-saved or the batch reindex runs; the existing `SearchIndexListener` batch covers it. Document "register
   searchable field -> reindex." No new mechanism.
4. **Engine evolution coupling.** Spaces depend on `FieldType`; a profile-side change to a type's
   sanitize/display propagates. Desired (one engine, no drift); guarded by the reuse tests.
5. **No write amplification.** A meta write = one row write + one single-space reindex. No fan-out, no cron.
6. **Meta-key namespace.** Document a `bn_`/vendor prefix convention for registered keys to avoid two add-ons
   colliding on the same `meta_key`; `register_meta` is the single registration point that makes collisions visible.
7. **Dormant by default.** No registered fields = feature inert; the table exists but is empty. Zero behavior
   change on existing installs ([[no-empty-options-default]] — intentional off state).

## Definition of Done
- All tests above pass; full free suite green. WPCS clean, PHPStan level 5 clean on every touched file.
- `bn_space_meta` created on fresh install AND on upgrade; dropped on uninstall (verified via `wp db tables`).
- REST app-readiness verified end to end: `GET /spaces/fields` (schema), `GET /spaces/{id}` (`fields[]` with
  value+display, members-only filtered for non-members), `POST/PUT` write with 400 validation, `?include_fields`
  + `?meta_query` on list — all exercised via REST (the app's only surface).
- Web display + owner-edit panels verified in browser (desktop + 390px + dark), including the no-fields empty state.
- 1,000-space + 20-field seed: `GET /spaces` default query count identical with 0 vs 20 fields (no list regression);
  `?include_fields` adds exactly one batched meta query, not N.
- CLAUDE.md "Recent Changes" + Database Tables list updated; `audit/manifest.json` refreshed (new table + meta API
  + `/spaces/fields` + `fields` on space endpoints).
