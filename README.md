<p align="center">
  <img src="assets/images/pwa/icon-192.png" width="96" alt="BuddyNext">
</p>

<h1 align="center">BuddyNext</h1>

<p align="center"><strong>A community platform for WordPress: activity feed, spaces, member profiles, messaging and moderation in one plugin.</strong></p>

<p align="center">
  <a href="https://github.com/buddynext/buddynext/releases/latest"><img src="https://img.shields.io/github/v/release/buddynext/buddynext?label=release&color=2563eb" alt="Latest release"></a>
  <img src="https://img.shields.io/badge/WordPress-6.9%2B-21759b" alt="WordPress 6.9+">
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777bb4" alt="PHP 8.1+">
  <img src="https://img.shields.io/badge/license-GPLv2%2B-4c9a2a" alt="License GPLv2+">
</p>

---

BuddyNext turns a WordPress site into a social community. It is REST-first (the web UI and the installable app read the same endpoints) and built on the WordPress Interactivity API, with no build step needed to run it.

## What it does

### Activity and content
- Activity feed with For You, Following, Spaces and Network views.
- Text, link and poll posts with per-post privacy.
- Reactions (six built-in), threaded comments, bookmarks and scheduled posts.
- Hashtags with trending topics and per-tag pages.
- Site-wide announcements that members can dismiss.

### Spaces
- Public, private and hidden spaces with roles, join requests, categories and sub-spaces.
- Per-space feeds, search and moderation.
- Featured spaces and shareable invite links.

### Members and profiles
- Profile field groups with field-level visibility, avatar and cover, and a vanity profile slug.
- Follows, connections, block and mute.
- Member directory, member types and email invitations.
- Setup wizard and a member onboarding flow.

### Messaging and companions
- Direct messaging and media galleries through the [MediaVerse](https://wbcomdesigns.com/downloads/mediaverse/) bridge.
- Bridges for Jetonomy, WB Gamification, WP Career Board, Learnomy, WB Listora, Eventonomy and Member Blog, managed from the companion screen in wp-admin.
- To move an existing BuddyPress or BuddyBoss community across, use [BuddyNext Importer](https://github.com/buddynext/buddynext-importer).

### Moderation and safety
- Reports, a review queue, strikes, suspensions, appeals and shadow-ban.
- Content warnings, banned words and an auto-hide threshold.
- A front-end Community Admin hub for moderators.

### Accounts
- Login and signup with honeypot, time-trap and rate limiting.
- Email verification, optional TOTP two-factor authentication, social login and account data export and deletion.

### Platform
- Progressive Web App support (manifest and service worker served over REST).
- Outbound webhooks.
- White-label admin menu and plugin isolation on community pages.
- Translation-ready (text domain `buddynext`).

### Blocks and shortcodes
19 Gutenberg blocks: Activity Feed, Community Activity, Connection Button, Follow Button, Header User Menu, Member Card, Member Directory, Members Showcase, My Spaces, Notification Bell, Post Composer, Profile Completion Bar, Profile Fields, Profile Header, Search Bar, Space Card, Space Directory, Spaces Showcase and Trending Hashtags.

8 shortcodes: `buddynext_activity`, `buddynext_people`, `buddynext_spaces`, `buddynext_messages`, `buddynext_notifications`, `buddynext_auth`, `buddynext_community_admin`, `buddynext_search`.

## Who it is for

Site owners and developers who want to run a members-only or public community on their own WordPress install, and agencies building community sites for clients. Free covers the community itself. [BuddyNext Pro](https://wbcomdesigns.com/downloads/buddynext-pro/) adds membership plans, payments, email automation and more.

## Where to get it

- Product site: [buddynext.com](https://buddynext.com/)
- Download: [wbcomdesigns.com/downloads/buddynext](https://wbcomdesigns.com/downloads/buddynext/)
- Releases: the latest `buddynext-<version>.zip` is on the [GitHub releases page](https://github.com/buddynext/buddynext/releases/latest)

BuddyNext is not listed on wordpress.org.

## Documentation

- [BuddyNext docs](https://buddynext.com/docs/)
- [Wbcom docs hub](https://docs.wbcomdesigns.com/products/buddynext/)
- [Developer API overview](https://buddynext.com/developers/api/)
- [Release notes](https://wbcomdesigns.com/release-notes/buddynext/)
- [Pricing and Free vs Pro](https://buddynext.com/free-vs-pro/)

## Requirements

- WordPress 6.9 or newer
- PHP 8.1 or newer

## Installation

1. Download the zip from the link above.
2. In wp-admin go to **Plugins > Add New > Upload Plugin**, upload the zip and activate.
3. Activation opens the setup wizard (`wp-admin/admin.php?page=buddynext-setup`). It sets the community name, registration, profile and space defaults and creates the community pages.
4. Optional: install [BuddyNext Pro](https://wbcomdesigns.com/downloads/buddynext-pro/) and the companion plugins you need.

## For developers

| | |
|---|---|
| Plugin file | `buddynext.php` |
| PHP namespace | `BuddyNext\` (PSR-4, `includes/`) |
| Text domain | `buddynext` |
| Hook prefix | `buddynext_` |
| REST namespace | `buddynext/v1` |
| Blocks | `buddynext/*`, sources in `blocks/` |

- **REST API.** Cookie authentication with the `X-WP-Nonce` header. Main route groups include `/me`, `/users`, `/spaces`, `/posts`, `/feed`, `/comments`, `/reactions`, `/hashtags`, `/reports`, `/appeals`, `/auth`, `/account`, `/search`, `/webhooks` and `/member-types`. List the live surface at `/wp-json/buddynext/v1`. See the [REST contract](https://docs.wbcomdesigns.com/products/buddynext/developer-guide/14-rest-contract/).
- **Hooks.** Actions and filters are documented in the [hooks overview](https://docs.wbcomdesigns.com/products/buddynext/developer-guide/25-hooks-overview/).
- **Template overrides.** Copy a file from `templates/` to `your-theme/buddynext/` (child theme first, then parent theme).
- **WP-CLI.** `wp buddynext demo`, `cert`, `handles`, `bridge-status`, `repair-space-owners`, `repair-discussion-visibility` and `reconcile-media-privacy`. See the [WP-CLI reference](https://docs.wbcomdesigns.com/products/buddynext/developer-guide/38-wp-cli/).
- **Development setup.** The plugin runs without a build step. `composer install` then `composer lint` (PHPCS) and `composer lint-fix` (PHPCBF). End-to-end tests use Playwright: `npm install`, `npm run test:e2e:install`, `npm run test:e2e`.
- **Developer guide.** [Getting started for developers](https://docs.wbcomdesigns.com/products/buddynext/developer-guide/02-getting-started-dev/).

## Support

Open a ticket at [wbcomdesigns.com/support](https://wbcomdesigns.com/support/) or use the [contact page](https://buddynext.com/contact/) on the product site.

## License

GPLv2 or later. See [LICENSE](LICENSE).
