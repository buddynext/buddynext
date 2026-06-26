# Admin Settings IA Reorganization — Plan & Status (free + pro)

**Status:** IA implemented + browser-verified 2026-06-17 (free + Pro). First-run
"recommended defaults" work still pending (see end).

## Goal

The old layout crammed ~22 tabs into one "Settings" page while domain sections sat
half-used. Reorganize so each setting lives in the section where the owner manages
that area, every sub-menu stays shallow, and support can guide with a clean script.

**Owner-set rules (2026-06-17):**

- **Cap of 3-5 tabs per sub-menu** — no deep tab walls. Split into more sub-menus
  rather than grow one.
- Long-term + extensible: Pro and custom code extend through the same registry.
- White-label is **removed** (waste).
- License lives in **Pro** (Monetization).
- Free standalone shows a **Free vs Pro comparison** (Upgrade sub-menu).
- Registration default **defers to WordPress** (no opinionated BN default).

## Architecture — one central placement map

`AdminHub::TAB_PLACEMENT` is the single source of truth for the whole IA. It is
keyed by each tab's *origin* `section:slug` (whatever a registrar passes to
`register_tab()`) and maps it to a final `section` + `position`, or hides it:

```php
'settings:webhooks'  => array( 'section' => 'platform', 'position' => 50 ),
'settings:white-label' => array( 'hidden' => true ),
```

Every admin class — free and Pro — keeps registering against its own domain
section; the hub arranges the final layout. Pro therefore needed almost no edits.

Owners reorder / move / hide any tab from a mu-plugin with no core change:

```php
add_filter( 'bn_admin_hub_tab_placement', function ( $map ) {
    $map['settings:webhooks']['hidden'] = true;
    $map['settings:social']['section']  = 'notifications';
    return $map;
} );
```

New top-level sections are still added via `bn_admin_hub_sections`.

## Final IA (each section ≤ 5 tabs; empty sections auto-hide)

| Sub-menu | Tabs | free / with-Pro |
|---|---|---|
| **Settings** | General, Appearance, Navigation | 3 / 3 |
| **Platform** | Features, Integrations, Add-ons, Tools, Webhooks | 5 / 5 |
| **Members** | Directory, Registration & Login, Roles & Capabilities, Privacy & Data (+ Labels·Pro) | 4 / 5 |
| **Spaces** | Directory, Settings | 2 / 2 |
| **Engagement** | Insights, Social (+ Analytics·Pro, Reactions·Pro) | 2 / 4 |
| **Notifications** | Notifications, Email, Email Templates | 3 / 3 |
| **Realtime & Push**·Pro | Realtime, Push, Push Prefs | 0 / 3 |
| **Campaigns**·Pro | Broadcasts, Drip, Scheduled, AI Feed | 0 / 4 |
| **Moderation** | Reports, Suspensions, Appeals, Filters & Limits (+ Bulk·Pro) | 4 / 5 |
| **Auto-Moderation**·Pro | Rules, AI Moderation | 0 / 2 |
| **Monetization**·Pro | Tiers, Subscriptions, Stripe, License, Paywall | 0 / 5 |
| **Upgrade** (free only) | Free vs Pro | 1 / 0 |

The "Growth" section was removed and split into Engagement / Notifications /
Realtime & Push / Campaigns. Each section opens on its management/overview tab
(lowest `position`). Free standalone shows ~7 sub-menus; Pro fills the rest.

## What changed in code

- `AdminHub.php` — `DEFAULT_SECTIONS` rewritten (11 sections + Upgrade); added
  `TAB_PLACEMENT` + `tab_placement()` (`bn_admin_hub_tab_placement` filter);
  `register_tab()` applies placement (relocate / reposition / hide); menu label
  no longer reads the retired white-label option; `is_hub_screen()` made public
  static; new `is_tab_active($slug)` resolves a tab's real section for asset
  gating.
- `Settings.php` — tab labels are canonical only (section/order owned by the map);
  relabels: Registration → "Registration & Login", Moderation → "Filters &
  Limits", Spaces → "Settings"; License registration stays Pro-gated (map sends
  it to Monetization); new free-only `render_upgrade_tab()` (Free vs Pro table,
  `buddynext_pro_upgrade_url` filter); `enqueue_assets()` now gates on
  `is_hub_screen()`.
- `AssetService.php` — templates + navigation asset gates switched to
  `AdminHub::is_tab_active()`; removed the dead `$active_tab` brittle checks.
- Pro `PushAdmin` / `RealtimeAdmin` / `AnalyticsAdmin` — asset gates switched to
  `is_tab_active()` so moved tabs keep their JS/CSS.
- In-code link sweep: Insights growth→engagement, features settings→platform;
  user-facing "Settings → Features/Registration/Notifications" pointer copy fixed.

## Not lost

No saved options, forms, or access changed — `settings_fields('buddynext_<slug>')`
groups and `render_tab_*` methods are untouched; only the section a tab renders in
moved. Pre-release, so old `?page=buddynext&tab=<moved>` bookmarks are not
redirected (in-code links updated directly).

## Verification (done)

- [x] All 11 sub-menus render; each ≤5 tabs; management/overview tab is default.
- [x] Settings (3), Platform + Webhooks (CRUD JS loads), Members + Registration &
  Login, Engagement (Insights charts), Monetization + License — all verified in
  browser with Pro active.
- [x] White-label absent; Upgrade hidden when Pro active.
- [x] WPCS clean on every changed file; `php -l` clean.

## Support script (post-reorg)

- Branding, look, nav → **Settings**
- Features, integrations, add-ons, tools, webhooks → **Platform**
- Registration, login, roles, privacy, directory, labels → **Members**
- Space rules/layout → **Spaces**
- Insights, social, reactions, analytics → **Engagement**
- In-app/email/templates → **Notifications**; realtime/push → **Realtime & Push**
- Newsletters, drip, scheduled → **Campaigns**
- Reports, strikes, filters, bulk → **Moderation**; auto rules/AI → **Auto-Moderation**
- Tiers, subscriptions, Stripe, license, paywall → **Monetization**

## PENDING — first-run "full community experience day 1"

Owner choice: **strong ON defaults + a "Recommended for new communities" apply
button** (General/Features), and **registration defers to WordPress
`users_can_register`**.

To build:

1. One `RecommendedDefaults` source (option → recommended value): explore public,
   DM on, polls/shares/bookmarks/link-preview/emoji on, reactions on,
   notifications on, spam protection on. Registration mode left to defer to WP.
2. Installer seeds these on **fresh install only** (`add_option`, never overwrite
   on upgrade) so a new site is fully featured out of the box.
3. A dismissible "Recommended for new communities" apply action (one-click
   `update_option` of the bundle) on General/Features for re-apply.
4. Browser-verify defaults + apply button; WPCS.

## Follow-up

- Full removal of the Pro white-label P6 subsystem (BrandService, HueOverride,
  DomainMapper, SpaceBrandAdmin, REST, `bn_domain_map`, tests). Currently only
  hidden from the IA via the placement map.
