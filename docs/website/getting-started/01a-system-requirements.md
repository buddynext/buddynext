# System Requirements and Compatibility

The WordPress and PHP versions BuddyNext needs, the server settings worth checking before you install, and what it does and does not run alongside.

## Why this matters

BuddyNext is a WordPress plugin, so it runs on the hosting you already have. Checking the few requirements below before you install takes a couple of minutes and avoids the common first-day problems: a plugin that will not activate, community links that show a not-found page, and memory errors once Pro and the companion plugins are all active.

## Requirements

| Requirement | Minimum |
|-------------|---------|
| WordPress | 6.9 or newer |
| PHP | 8.1 or newer |
| PHP memory_limit | 512 MB recommended (128 MB can be exhausted with the full family active) |
| Database | MySQL 5.7+ or MariaDB 10.3+ (standard WordPress) |
| Permalinks | Pretty permalinks enabled (any setting other than Plain) |

The free plugin and BuddyNext Pro both declare WordPress 6.9 and PHP 8.1 as their minimums. The plugin's readme lists WordPress 7.1 as the newest version it has been tested on.

## Things worth checking first

- **HTTPS.** Browsers only offer to install the community as an app, and only allow web push, on secure origins. A site on plain HTTP works, but those two features do not.
- **Permalinks.** BuddyNext cannot run on Plain links. If your site uses them, wp-admin shows a notice with a button that switches to Post name links in one click.
- **Memory.** Set `memory_limit` to at least 512 MB. **Tools > Site Health** shows a recommendation when it is lower.
- **Email.** Notification, verification and digest emails go through WordPress mail. A site that cannot send mail reliably should add an SMTP plugin before email verification is switched on.
- **Object cache.** Optional on a small community, recommended as it grows. See [Object Cache at Scale](09-object-cache-at-scale.md).

## What BuddyNext runs alongside

- **Themes.** Regular WordPress themes work, and BuddyX, BuddyX Pro and Reign are built for BuddyNext. See [Choosing Your Theme](02a-choosing-a-theme.md).
- **Caching and optimisation plugins.** See [Page Cache and Optimisation Plugins](09a-page-cache-and-optimisation.md) for what was tested.
- **Multisite.** One community per network, on the main site. See [Running BuddyNext on a Multisite Network](08b-multisite.md).
- **BuddyPress and BuddyBoss.** Supported as a migration source only. Running either alongside BuddyNext on a live site is not supported. See [Moving your existing members and content](../migrating-from-buddypress/04-migrating-your-data.md).
- **Companion plugins.** Forums, messaging, media, courses and events come from companion plugins with their own requirements. See [Installing BuddyNext](02-installation.md) for the install order.

## If something does not work

See [Install and Setup Issues](../troubleshooting/01-install-and-setup-issues.md).

## Related

- [Installing BuddyNext](02-installation.md)
- [Introduction to BuddyNext](01-introduction.md)
- [Tools and Maintenance](08-tools-and-maintenance.md)
