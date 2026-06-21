# BuddyNext

[![CI](https://github.com/buddynext/buddynext/actions/workflows/ci.yml/badge.svg)](https://github.com/buddynext/buddynext/actions/workflows/ci.yml)
[![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://github.com/buddynext/buddynext/releases/latest)
[![License: GPL v2+](https://img.shields.io/badge/license-GPLv2%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**The community operating system for WordPress.** BuddyNext turns any WordPress site into a modern social network — activity feeds, spaces, member profiles, direct messaging, and moderation — in one plugin, with a native REST API and a Progressive Web App so the same community runs on the web and in an app.

Built and maintained by [Wbcom Designs](https://wbcomdesigns.com/buddynext). Free and open source (GPLv2+).

---

## Features

### Activity & content
- **Activity feed** with For You, Following, Spaces, and Network views.
- **Posts** — text, link, and poll posts with per-post privacy (public / followers / space).
- **Reactions** — six switchable emoji, one reaction per member per item.
- **Threaded comments** — nested replies, inline edit, soft delete, and moderator pinning.
- **Bookmarks** to save any post for later.
- **Hashtags** — auto-extracted, with trending topics, follow, related tags, and per-tag pages.
- **Announcements** — site-wide banners admins pin to every feed; each member can dismiss their own.

### Spaces (communities)
- **Public, private, and hidden spaces** with membership, roles, and join requests.
- **Per-space feeds**, space moderation, settings, and categories.

### Members & profiles
- **Rich profiles** with customizable field groups (basic info, social links, work, education, skills, and custom groups).
- **Avatar + cover**, vanity profile slug, and a profile-completion strength meter.
- **Field-level visibility** — public, followers-only, private, or per-entry.
- **Social graph** — follows, mutual connections, block, and mute.
- **Member directory** with search and by-role browsing.

### Direct messaging
- **One-to-one and group messaging** with media attachments, a conversation info panel, shared-media gallery, safety actions, and a full-bleed media lightbox. *(Powered by the free WPMediaVerse companion plugin.)*

### Moderation
- **Reporting** from any post, a **review queue**, **strikes**, **suspensions**, and an **appeals** flow.
- **Shadow-ban**, content warnings, and an auto-hide threshold.
- Every action is written to an **immutable audit log**.

### Auth & account security (in-house, no third-party services)
- **Login & signup** with built-in spam protection — honeypot, human-check, rate limiting, and time-trap.
- **Email verification**, opt-in **TOTP two-factor** (RFC 6238), password reset, change email/password, and sign-out-everywhere.
- **Social login** with verified-email account linking.
- **GDPR** self-service data export and account deletion.

### Engagement & discovery
- **Onboarding wizard** — Profile → Spaces → People → Notifications.
- **Gamification** — streaks, badges, and standings on member profiles.
- **Notifications** — in-app and email, with per-channel preferences and partner-notification aggregation.
- **Search** across members, spaces, hashtags, and posts.
- **Sidebar widgets** — trending topics, suggested people, your spaces, and a greeting/streak card.

### Platform
- **Native REST API** — every surface is REST-first, so the web and the app render from the same endpoints.
- **Progressive Web App** — installable to the home screen with branded BuddyNext icons.
- **Dark mode** that follows your theme's tokens; tuned for BuddyX and Reign, works with any theme.
- **Fully translation-ready** — every template, admin label, and JavaScript module is internationalized, with a complete `.pot`.
- **Integrations layer** for companion plugins (messaging, forums, gamification, jobs, courses, listings) via a one-click install flow.
- **White-label** the admin menu label, and a built-in outbound webhook.

## BuddyNext Pro

[**BuddyNext Pro**](https://github.com/buddynext/buddynext-pro) adds the application and monetization layer on top of Free: membership plans with on-site checkout and Stripe, content protection and gated spaces, email automation (campaigns + drip), AI-assisted moderation, member labels, advanced profile field types, a Portfolio profile panel, analytics, and more. Free runs first; Pro extends it through a published contract without modifying any Free code.

## Requirements

- WordPress 6.9+
- PHP 8.2+

## Installation

Download the latest `buddynext-<version>.zip` from the [Releases](https://github.com/buddynext/buddynext/releases/latest) page and install it from **Plugins → Add New → Upload Plugin**. No build step, no Composer, no npm — install the zip and activate.

---

## Development

See [CLAUDE.md](CLAUDE.md) for development standards and workflow.

### Building a distribution zip

QA and customers install a single zip — **no composer, no npm, no commands**. Build it with:

```bash
bin/build-release.sh
# → ~/Documents/work-artifacts/scratch/buddynext-<version>.zip
```

Pass a target directory to override the default destination:

```bash
bin/build-release.sh ./dist        # → ./dist/buddynext-<version>.zip
```

What the builder does:

- Stages **committed state only** (`git archive HEAD`) — so commit your changes first, or the
  zip ships the old code even though its filename shows the new version.
- Regenerates a lean runtime `vendor/` with `composer install --no-dev --optimize-autoloader`
  (just the autoloader + bundled `libs/`; no dev tooling).
- Copies **only an allowlist** of runtime paths (`buddynext.php includes templates assets blocks
  libs theme.json` + optional `languages uninstall.php readme.txt`). QA dirs, screenshots, docs,
  `.md` files, and dev configs can never leak in.
- Reads the version from the plugin header. Free and Pro ship in lockstep on the same version
  string, so bump both headers together when the version changes.

The mu-plugin (BuddyNext Isolation) is **not** shipped in the zip — it is auto-created on plugin
activation by the installer, so a fresh install needs nothing extra.

## License

GPLv2 or later. See [LICENSE](LICENSE).
