# General FAQ

Common questions about what BuddyNext is, how Free and Pro differ, and what a site needs to run it.

## What is BuddyNext?

BuddyNext is a community platform for WordPress. It gives your site its own activity feed, spaces (groups), member profiles, direct messaging, notifications, and moderation tools - the full social layer a community site needs, built as a plugin for the WordPress you already run. It is a community platform in its own right, not an add-on for another product.

## What is the difference between BuddyNext Free and Pro?

Free covers the social core: profiles, the activity feed, spaces with all three privacy types, following and connections, direct messaging (with WPMediaVerse installed), comments and reactions, notifications, and the full moderation toolkit (reporting, the moderation queue, warnings/strikes/suspensions, appeals).

Pro adds the business layer on top: paid membership plans, gated spaces and content protection, Stripe and other payment gateways, scheduled posts, advanced profile field types and conditional logic, advanced search, analytics, broadcast email and drip sequences, auto-moderation and AI-assisted moderation, push notifications, real-time WebSocket delivery, white-labeling, and the deeper companion-plugin integrations (Learnomy, Eventonomy, Listora, Career Board). See [Membership Plans](../pro/01-membership-plans.md) for how Pro's monetization layer is structured.

## Which themes does BuddyNext work with?

BuddyNext works with any WordPress theme, because it renders its own complete community interface - the navigation rail, feed, spaces, directory, profiles, and messaging - independently of the theme. Your theme only supplies the outer chrome: the site header, footer, and base fonts.

Wbcom Designs builds and tests BuddyNext against three themes, in this order of recommendation:

- **BuddyX** (free) - the recommended starting point. Purpose-built for community sites, with a light/dark toggle BuddyNext follows automatically.
- **BuddyX Pro** (premium) - BuddyX plus more header and layout options.
- **Reign** (premium) - the most design-rich option, used for the BuddyNext demo.

If you already run a different theme you like, keep it - BuddyNext works inside it. See [Choosing Your Theme](../getting-started/02a-choosing-a-theme.md).

## What does a site need to run BuddyNext?

WordPress 6.9 or newer, PHP 8.1 or newer, pretty permalinks enabled, and a standard MySQL 5.7+/MariaDB 10.3+ database. A PHP memory limit of at least 512 MB is recommended once Pro and the media/integration plugins are all active. See [Installing BuddyNext](../getting-started/02-installation.md).

## Do I need a developer to set BuddyNext up?

No. The [Setup Wizard](../getting-started/03-admin-setup-wizard.md) walks you through branding, registration, profile fields, spaces, pages, and companion plugins in a few minutes with sensible defaults, and every setting has a permanent home in the admin afterward if you want to change it later.

## Does BuddyNext Pro unlock features that are hidden in Free, or is it a separate install?

Pro is a separate plugin installed alongside Free (through the built-in one-click installer under **Platform > Add-ons**), not a key that unlocks hidden code in Free. Once Pro is active, its features and admin sections appear automatically. The Pro license key gates updates only - it never locks or unlocks features once Pro is installed and active. See [Installing BuddyNext](../getting-started/02-installation.md).

## What are the optional companion plugins, and do I need them?

None of them are required for the social core to work. Each adds one specific capability: WPMediaVerse (direct messaging and photo/file uploads), Jetonomy (forums), WB Gamification (points, badges, levels), Career Board (jobs), Learnomy (courses and certificates), Listora (directory listings), Eventonomy (events), and WB Member Blog (front-end post publishing). Install only the ones your community needs - a companion plugin you never install costs nothing in performance, and removing one only disables the feature it powered. See [Integrations Overview](../integrations/01-overview.md).

## Related

- [Installing BuddyNext](../getting-started/02-installation.md)
- [Choosing Your Theme](../getting-started/02a-choosing-a-theme.md)
- [Admin Setup Wizard](../getting-started/03-admin-setup-wizard.md)
- [Integrations Overview](../integrations/01-overview.md)
