# Template refactor — Layer 3 parts sweep

This plan complements `TEMPLATE-REFACTOR-PLAN.md` by specifying the **part-level factoring** for every monolith template. The contract every part obeys is already canonical in `docs/specs/TEMPLATE-PARTS.md` and `docs/specs/MODULAR-ARCHITECTURE.md` (Layer 3).

## Why this sweep exists

The four largest inline templates (`partials/post-card.php` 878 lines, `profile/view.php` 1279, `spaces/settings.php` 1407, `profile/edit.php` 1400) bundle multiple distinct UI regions into one file with no factored parts. Every time Pro or a bridge needs to inject UI into one of those regions, Free has to ship a bespoke `do_action()` — see the 10 missing hook seams catalogued in the cross-plugin audit.

Once each monolith is factored into named parts, **every region automatically exposes the standard 4-hook contract** (`buddynext_part_{name}_{before|after|args|classes}`). Pro listeners hook the part name; no more per-feature seam requests.

## Goals

1. Every UI region used by 2+ templates becomes a part under `templates/parts/`.
2. Every region Pro or bridges might want to extend becomes a part — even if used by only 1 template today.
3. Every new part fires the standard 4-hook contract verbatim (see `parts/section-head.php` as the reference implementation).
4. Existing Pro listeners that hook bespoke `buddynext_*` actions/filters migrate to the part hook names.
5. Free no longer ships single-purpose `do_action()` calls for UI injection points — the part hook is the only documented extension mechanism for UI.

## Non-goals

- No visual / markup / token changes. This is a structural refactor only.
- No changes to Services, Controllers, or REST shape.
- No CSS class renames beyond moving existing class blocks into new files.
- No version changes anywhere (manifests, headers, docs, CHANGELOG).

## Reference: the part contract (binding)

Every new part file under `templates/parts/{name}.php`:

1. Top-of-file PHPDoc listing the `$args` keys with type + default, plus the hook/filter names fired.
2. Normalize `$args` from the variables in scope.
3. `$args = (array) apply_filters( 'buddynext_part_{name}_args', $args );`
4. Optional early bail (e.g. required field empty → silent return).
5. Build `$bn_classes = array_merge( array( 'bn-{name}' ), (array) $args['classes'] );`
6. `$bn_classes = (array) apply_filters( 'buddynext_part_{name}_classes', $bn_classes, $args );`
7. `do_action( 'buddynext_part_{name}_before', $args );`
8. Markup.
9. `do_action( 'buddynext_part_{name}_after', $args );`

Callers use `buddynext_get_template( 'parts/{name}.php', $args )`. Template overrides cascade child theme → parent theme → plugin — see `Core/TemplateLoader::locate()`.

Reference implementation: `templates/parts/section-head.php`.

---

## Per-monolith factoring

### 1. `templates/partials/post-card.php` (878 → ~120 + 7 parts)

Section landmarks already documented in inline comments (`Head row`, `Content warning overlay`, `Body`, `Reaction summary chips`, `Flat action row`, `Comments expand region`).

| New part | Absorbs lines (current) | Args | Unblocks Pro listener |
|---|---|---|---|
| `parts/post-byline.php` | 304–366 (avatar + author name + handle + timestamp + privacy chip) | `bn_post`, `author_id`, `display_name`, `username`, `avatar_url`, `member_type_label`, `created_at`, `edited_label`, `privacy_label` | P10 ProfileLabelInjector hooks `buddynext_part_post_byline_after` (label chips after name); P5.3 future "first-saw-you-on" indicator |
| `parts/post-options-menu.php` | 368–420 (kebab + dropdown items) | `bn_post`, `can_edit`, `can_pin`, `can_report`, `can_delete` | future Pro moderator menu items via `buddynext_part_post_options_menu_after` |
| `parts/post-cw-overlay.php` | 423–438 (content warning blur) | `cw_type`, `cw_label` | future AI-flagged CW labels via classes filter |
| `parts/post-body.php` | 439–705 (text, poll, link preview, media) | `bn_post`, `link_preview`, `poll_data`, `media_attachments`, `is_pinned` | bridges injecting custom embeds via `buddynext_part_post_body_after` |
| `parts/post-reaction-summary.php` | 706–725 (reaction chips, comment count, share count) | `reaction_count`, `comment_count`, `share_count`, `top_reactions` | P0 CustomReactions custom-emoji chip render via classes filter |
| `parts/post-actions.php` | 727–820 (react / comment / share / bookmark buttons) | `bn_post`, `user_reaction`, `is_bookmarked`, `can_react`, `can_comment`, `can_share`, `can_bookmark` | future Pro action additions (e.g. "Translate" button) via `buddynext_part_post_actions_after` |
| `parts/post-comment-form.php` | (currently inside 822–878 expand region) — the form node | `bn_post`, `user_id`, `placeholder` | P2.4 AI smart-reply button hooks `buddynext_part_post_comment_form_after` (replaces the proposed `buddynext_post_comment_form_extra` ad-hoc seam) |
| `parts/post-comments-list.php` | (currently inside 822–878 expand region) — the list node | `bn_post`, `comments`, `viewer_id` | future Pro nested-thread render via `buddynext_part_post_comments_list_args` |

`partials/post-card.php` becomes a thin composer: ~120 lines of variable resolution + 8 `buddynext_get_template('parts/post-*.php', $args)` calls.

**Pro seams retired by this refactor:**
- ✗ `buddynext_post_byline_extras` → ✓ `buddynext_part_post_byline_after`
- ✗ `buddynext_post_comment_form_extra` → ✓ `buddynext_part_post_comment_form_after`

### 2. `templates/profile/view.php` (1279 → ~150 + 4 parts)

| New part | Absorbs lines (current) | Args | Unblocks Pro listener |
|---|---|---|---|
| `parts/profile-hero.php` | 567–826 (cover + identity head + action buttons + share/options menus + stats strip) | `profile_user_id`, `viewer_id`, `display_name`, `username`, `avatar_url`, `cover_url`, `bio`, `stats`, `is_owner`, `is_following`, `is_connected`, `can_message` | P5.3 ProfileViewsWidget hooks `buddynext_part_profile_hero_after` (replaces ad-hoc `buddynext_profile_view_after_hero`) |
| `parts/profile-tab-bar.php` | 1000–1046 (tab nav) | `profile_user_id`, `viewer_id`, `active_tab`, `tabs` array (filterable) | bridges adding tabs via `buddynext_part_profile_tab_bar_args` filter (each tab is a row `{ slug, label, count }`) |
| `parts/profile-tab-panel.php` | 1047–1220 (tab body shells) | `active_tab`, `panels` map | bridges providing tab body via `buddynext_part_profile_tab_panel_args` |
| `parts/profile-stats-strip.php` | 828–999 (stat tiles) | `stats` array, `is_owner` | future Pro analytics tiles via `buddynext_part_profile_stats_strip_args` |

**Pro seam retired:**
- ✗ `buddynext_profile_view_after_hero` → ✓ `buddynext_part_profile_hero_after`

### 3. `templates/spaces/settings.php` (1407 → ~180 + 10 parts)

This is the largest payoff. The tab bar (lines 460–474) and per-tab panels are currently inline switch-case markup. After factoring, the tab registry is a filterable array and each panel is its own part.

| New part | Absorbs lines (current) | Args | Unblocks Pro listener |
|---|---|---|---|
| `parts/space-settings-tabs.php` | 460–474 + tab definition array (line 305+) | `space_id`, `active_tab`, `tabs` (filterable via `buddynext_part_space_settings_tabs_args` — array of `{ slug, label, icon, cap, panel }`) | P6.2 SpaceBrandAdmin adds `{ slug: 'brand', … }` to the tabs array — replaces ad-hoc `buddynext_space_settings_tabs` |
| `parts/space-settings-panel-general.php` | "general" branch body | `space`, `settings_general` | — |
| `parts/space-settings-panel-privacy.php` | "privacy" branch | `space`, `privacy_settings` | — |
| `parts/space-settings-panel-permissions.php` | "permissions" branch | `space`, `permissions` | — |
| `parts/space-settings-panel-members.php` | "members" branch | `space`, `members` | — |
| `parts/space-settings-panel-moderation.php` | "moderation" branch | `space`, `moderation_settings` | P9 advanced moderation panel injection via `buddynext_part_space_settings_panel_moderation_after` |
| `parts/space-settings-panel-branding.php` | "branding" branch | `space`, `branding_settings` | P6.2 per-space brand controls render here via `buddynext_part_space_settings_panel_branding_args` (or by extending the panel slug to `branding-pro` registered in the tabs array) |
| `parts/space-settings-panel-integrations.php` | "integrations" branch | `space`, `integrations` | — |
| `parts/space-settings-panel-notifications.php` | "notifications" branch | `space`, `notification_settings` | — |
| `parts/space-settings-panel-danger.php` | "danger" branch + transfer/delete/archive modals | `space`, `permissions` | — |

**Pro seams retired:**
- ✗ `buddynext_space_settings_tabs` → ✓ `buddynext_part_space_settings_tabs_args`
- ✗ `buddynext_space_settings_tab_content` → ✓ filterable `panel` callable in the tabs-args array + part-level `_after` hooks

### 4. `templates/profile/edit.php` (1400 → ~200 + 10 parts)

| New part | Absorbs lines (current) | Args | Unblocks Pro listener |
|---|---|---|---|
| `parts/profile-edit-hero.php` | 251–342 | `profile_user_id`, `avatar_url`, `cover_url`, `display_name` | — |
| `parts/profile-edit-section.php` | wraps every "Section: X" card | `id`, `title`, `subtitle`, `body_html` or `body_action` | future analytic surface injection on any section |
| `parts/profile-field.php` | the single-field render block (each field row inside About/Social Links/Work/Education/Interests) | `field` (type, key, label, value, options), `is_owner` | P11 AdvancedFieldRenderer swaps render via `buddynext_part_profile_field_args` (replaces ad-hoc `buddynext_profile_field_render`) |
| `parts/profile-field-group.php` | repeating-field rows (Work Experience, Education) | `field`, `entries`, `is_owner` | — |
| `parts/profile-edit-privacy-row.php` | 751–866 toggle rows | `key`, `label`, `value`, `options` | — |
| `parts/profile-edit-notif-row.php` | 867–961 toggle rows | `key`, `label`, `description`, `value` | P4.2 PushPrefService can inject push-toggle column via `buddynext_part_profile_edit_notif_row_after` |
| `parts/profile-edit-account-row.php` | 962–1199 (email/password/sign-out rows) | `row_id`, `label`, `value`, `inline_form` | future 2FA row via `buddynext_part_profile_edit_account_row_after` |
| `parts/profile-edit-danger-zone.php` | 1201–1223 | `actions` array | — |
| `parts/profile-edit-sidebar.php` | 1224–1299 (preview + visibility guide) | `profile`, `completeness` | future profile-completeness banner injection |
| `parts/profile-edit-save-bar.php` | 1300–1339 | `dirty_state` | — |

**Pro seam retired:**
- ✗ `buddynext_profile_field_render` → ✓ `buddynext_part_profile_field_args`

---

## Cumulative seam retirement

| Bespoke seam (proposed but not yet shipped) | Replaced by part hook | Pro listener that benefits |
|---|---|---|
| `buddynext_profile_view_after_hero` | `buddynext_part_profile_hero_after` | P5.3 ProfileViewsWidget |
| `buddynext_space_settings_tabs` | `buddynext_part_space_settings_tabs_args` | P6.2 SpaceBrandAdmin |
| `buddynext_space_settings_tab_content` | `buddynext_part_space_settings_panel_{slug}_after` | P6.2 SpaceBrandAdmin |
| `buddynext_post_comment_form_extra` | `buddynext_part_post_comment_form_after` | P2.4 ReplyButtonRenderer |
| `buddynext_profile_field_render` | `buddynext_part_profile_field_args` | P11 AdvancedFieldRenderer |
| `buddynext_post_byline_extras` | `buddynext_part_post_byline_after` | P10 ProfileLabelInjector |

The 4 service-level event gaps (`buddynext_post_bookmarked`, `_reported`, `_user_followed_first_time`, `_space_joined`) are NOT addressed by this sweep — those are Layer 2 service-emission compliance issues handled separately.

---

## Dispatch order (parallel-safe)

Each template is independent — different agents can run in worktree isolation per the existing `TEMPLATE-REFACTOR-PLAN.md` allocation.

| Wave | Template | Estimated parts | Pro listeners freed |
|---|---|---|---|
| 1 | `partials/post-card.php` | 8 | P2.4, P10, P0 |
| 1 | `profile/view.php` | 4 | P5.3 |
| 1 | `spaces/settings.php` | 10 | P6.2, P9 |
| 2 | `profile/edit.php` | 10 | P11, P4.2 |

Wave 1 covers the highest Pro-blocking impact. Wave 2 covers the largest single template and unblocks two more Pro modules.

After wave 2 lands, a sweep through the remaining > 500-line monoliths (`spaces/home.php` 1115, `search/results.php` 1011, `directory/members.php` 1000, `notifications/index.php` 835, `messages/thread.php` 807, `hashtags/feed.php` 771) follows the same playbook.

---

## Per-part verification checklist

For each new part, before merging:

1. **PHPDoc completeness.** All `$args` keys listed with type + default. All 4 hooks named in the docblock.
2. **Hook firing order.** `_args` filter → optional bail → `_classes` filter → `_before` action → markup → `_after` action. Reference: `parts/section-head.php`.
3. **Argument normalization.** Every consumed variable has a defined default; missing variables never produce undefined-variable notices.
4. **Caller migration.** Every site that used to render the old inline block now calls `buddynext_get_template( 'parts/{name}.php', $args )`. No dead code left in the monolith.
5. **Theme overridability.** The part loads from the plugin default if no child/parent theme override exists. Manual check by dropping a stub at `{theme}/buddynext/parts/{name}.php` and confirming it wins.
6. **WPCS + PHPStan clean.** `bin/check.sh --skip-audit` returns 0 against the touched files.
7. **No version bumps.** Manifests, plugin headers, composer.json, package.json, CHANGELOG, READMEs — untouched.
8. **Pro listener migration test.** For every retired bespoke seam, the corresponding Pro listener is verified to hook the new part-hook name and render on a live page.

---

## What this sweep does NOT change

- Layer 2 services (`FeedService`, `ProfileService`, `SpaceService`, etc.) — untouched.
- REST endpoints — untouched.
- Public hook names already documented in `docs/specs/HOOKS.md` and used in production — untouched. The retired hooks listed above are all *proposed* seams from cross-plugin audit, not shipped Free hooks.
- v2 design tokens / CSS classes — untouched.
- Pro plugin code — Pro migration to the new hook names is a separate follow-up task that lands AFTER each wave completes in Free.

---

## Cross-references

- Architecture contract: `docs/specs/MODULAR-ARCHITECTURE.md` (Layer 3 rules)
- Hook contract per part: `docs/specs/TEMPLATE-PARTS.md`
- Reference part implementation: `templates/parts/section-head.php`
- Prior plan (this doc complements, not replaces): `docs/v2 Plans/TEMPLATE-REFACTOR-PLAN.md`
- Cross-plugin seam audit that motivated this sweep: see Pro `audit/manifest.json` notes section + Pro `docs/PRO-ROADMAP.md` "Free seams to add" tables.

---

**Owner:** any developer with a free worktree. Pick a wave-1 template and start.
