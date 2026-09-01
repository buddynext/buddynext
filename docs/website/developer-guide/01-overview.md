# Developer Overview

BuddyNext is a social-community plugin for WordPress that ships its features as REST-backed, theme-overridable modules. This page is the entry point to the developer guide: it explains the Free/Pro split, the frontend and templating model, the contract-first design philosophy, and a current stat snapshot of the codebase.

![BuddyNext admin dashboard - the control surface for the modules this developer overview describes](../images/admin-overview.webp)

![The activity feed front end that BuddyNext's REST-backed feature modules render for members](../images/community-activity-feed.webp)

## What you are building on

BuddyNext is "the social layer for WordPress" - follows, connections, an activity feed, spaces, profiles, member directory, search, notifications, email, reactions, comments, hashtags, moderation, and a direct-messaging UI. Each of these is a self-contained feature module, and every module exposes its contracts through three stable surfaces: a REST namespace (`buddynext/v1`), a large set of action and filter hooks, and theme-overridable PHP templates.

The product is split into two plugins:

| | Free (`buddynext`) | Pro (`buddynext-pro`) |
|---|---|---|
| Namespace | `BuddyNext\*` | `BuddyNextPro\*` |
| REST namespace | `buddynext/v1` | `buddynext-pro/v1` |
| Bootstrap | `plugins_loaded:15` -> `BuddyNext\Core\Plugin::init()` -> fires `buddynext_loaded` | `plugins_loaded:20` -> `BuddyNextPro\Core\Plugin::init()` |
| Relationship | Standalone, fully functional community | `extends` Free - consumes Free services, hooks, and tables; never forks them |
| Requires | WordPress 6.9+, PHP 8.1+ | WordPress 6.9+, PHP 8.1+ |

Pro boots after Free and extends it through the documented seams - it rebinds container services, listens on Free hooks, and reads Free tables. Pro never copies Free's logic. Licensing gates updates only; it never gates functionality, so the Free plugin is a complete community on its own.

## How the frontend works

BuddyNext has no React build step and no bundler. Two choices make this possible:

- **REST-driven.** Every frontend state change goes through the REST API, not `admin-ajax`. Reads and writes share one envelope and one nonce contract. The browser talks to `buddynext/v1` directly. (See the REST contract page for the envelope, auth, and pagination rules.)
- **WordPress Interactivity API.** Client behaviour is authored as Interactivity API stores - ES modules under `assets/js/{feature}/store.js`, each namespaced `buddynext/{feature}`. There is no React, no JSX, no jQuery, and nothing to compile. The plugin ships runnable JS and ships its production dependencies vendored, so a customer never runs a build command.

Frontend pages render inside the active theme's chrome. Every BuddyNext hub (activity, members, spaces, messages, notifications, auth, onboarding, moderation) is wrapped by the theme's `get_header()` / `get_footer()`; the plugin's shell renders only the rail, main column, and an optional right sidebar between them. There is no shell-takeover mode and no opt-out filter - one render path, one test surface.

## Theme-overridable templates

All markup lives in PHP templates that themes can override by copying into `{theme}/buddynext/`. The `buddynext_get_template()` helper checks the active theme's path first and the plugin path second, so a theme can replace any partial without touching the plugin. Reusable partials under `templates/parts/` fire `before`/`after` actions and an args filter around their output, so you can extend a part without overriding it.

## Contract-first philosophy

The guiding principle is recorded in the spec index:

> Minimal by default, extensible by design.
> Ship the essential feature. Cover it with hooks. Let the ecosystem add layers.

In practice this means the core stays small and every feature is a folder under `includes/{Feature}/` with up to four canonical files - `Service` (business logic and DB access), `Controller` (REST), `Listener` (hook registrations), and `Cache`. Services never render templates; templates never run raw SQL. Features never call each other directly - they communicate through hooks, filters, or container lookups. Pro, bridges, child themes, and third-party plugins extend BuddyNext through seven documented surfaces (new feature modules, hooks and filters, container rebinding, template-part overrides, the right-sidebar action, hub before/after hooks, and REST namespace separation) rather than by editing core.

Every Layer 2 feature is also opt-out via a `buddynext_feature_{folder}` filter (default `true`), so a deployment can run in minimal mode (Core plus Bridges) or enable only the features it needs.

## Stat snapshot

Counted from the source of both plugins. Figures move with each release; confirm anything load-bearing against the code.

| Metric | Free | Pro |
|---|---|---|
| PHP files | 357 | 132 |
| Total PHP lines | 127,409 | - |
| JS files | 41 | - |
| CSS files | 29 | - |
| REST endpoints | 168 (`buddynext/v1`) | 48 (`buddynext-pro/v1`) |
| Database tables | 41 | 22 |
| Unique hooks fired | 633 (619 `buddynext_*`) | 34 |
| Cron events | 6 | 4 |

> **Note:** These figures are a manifest snapshot and can lag the code between refreshes; they are regenerated at release. For the current block count see the Blocks Reference, and for the current version string see `readme.txt`. When a count here disagrees with the source, trust the source.

## How this guide is organized

This developer guide covers the contracts you build against:

- **Getting started** - local requirements, where code lives, the two WP-CLI commands, and how to run the quality gates. (See Getting Started for Developers.)
- **REST API** - the request/response envelope, auth, pagination, and the per-feature route tables for both namespaces.
- **Hooks and filters** - the action and filter reference, organized by feature, with example extension snippets.
- **Database schema** - per-table column references, indexes, and the scale rules (denormalized counters, cursor columns) that shaped them.
- **Templates and theming** - the override mechanism, template-part contracts, and the shell composition model.
- **Blocks** - the registered Gutenberg blocks and their attributes.

## Where the deep specs live

The website developer guide is the curated, contract-facing reference. Behind it, the code is the contract - Free ships no `docs/specs/*.md`. An earlier revision of this page pointed at an `INDEX.md`, `MODULAR-ARCHITECTURE.md`, `HOOKS.md`, `REST-FRONTEND-CONTRACT.md`, `SCALE-CONTRACT.md`, and `FREE-VS-PRO.md` under `docs/specs/`, and none of them exist. Read the source instead:

- The per-domain hooks pages (25-33) for the `buddynext_*` action and filter reference.
- The template PHP file headers under `templates/` and `templates/parts/` for the template-part contracts.
- `register_rest_route(` for routes, `do_action(` / `apply_filters(` for hooks, `FeatureRegistry::catalog()` for features, and `Installer::schema()` for the `bn_*` tables.

When a doc and the source disagree, the current source wins - `includes/` reflects what actually ships.
