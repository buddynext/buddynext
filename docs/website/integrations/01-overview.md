# Integrations

Integrations let your community grow into messaging, forums, gamification, jobs, courses, and listings whenever you are ready - no setup work, no code, nothing to wire together by hand. Each one is optional and stays off until you turn it on, so you add features only as your members need them.

## Why use it

A community rarely needs everything on day one. Most owners start with the social core - profiles, a feed, spaces, follows - and add capabilities as the community grows. Integrations let you do exactly that: keep BuddyNext lean, then switch on direct messaging when members ask for it, or forums when discussions get long, or points and badges when you want to reward activity.

The point is to extend the community without bloating the core. BuddyNext does not bundle a messaging engine, a forum, or a points system into its own code. Instead it recognizes companion plugins when they are present and hands the relevant work to them. If a companion is not installed, BuddyNext simply does not show that capability - nothing is loaded, nothing slows the site down.

This matters for two kinds of people:

- For the site owner, it means you choose what your community does and pay (in performance and complexity) only for what you actually use.
- For members, it means each capability behaves like a native part of BuddyNext - messages, forum threads, and badges appear inside the same profiles and feed they already know, not in a separate disconnected plugin.

## What BuddyNext integrates with

BuddyNext keeps a catalog of companion plugins it knows how to work with. Each one adds a specific capability to your community.

| Integration | What it adds | What it unlocks inside BuddyNext |
|---|---|---|
| MediaVerse | Direct messaging, media galleries, and social feeds. | Member-to-member direct messaging inside BuddyNext. |
| Jetonomy | Forum-style threaded discussions and Q&A boards. | Forum activity surfaced in the BuddyNext feed. |
| Gamification | Points, badges, levels, and leaderboards. | Badges and a leaderboard on member profiles. |
| Career Board | Job listings and applicant management. | Job posts as activity cards in the feed. |
| Learnomy | Courses, lessons, and quizzes - a full LMS for your community. | Completed courses and certificates on member profiles. |
| Listora | Directory listings - members publish and manage their own listings. | Member listings surfaced in the feed and on profiles. |

Each integration has its own setup page in this section. Open the page for the one you want for the full walkthrough of its settings and member experience.

> **Note:** Direct messaging in BuddyNext is provided by MediaVerse. BuddyNext renders the messaging interface, but the underlying engine lives in MediaVerse - so messaging only appears once MediaVerse is installed and active.

## How it works (for owners)

Every integration is a bridge: BuddyNext checks whether the companion plugin is present and, if it is, connects the two. You never edit code or wire hooks yourself.

### Find the integrations screen

Open BuddyNext settings and go to the Integrations tab. Each companion shows as a card with its name, a one-line description of what it adds, and its current state:

- Active - the companion is installed and running, and the integration is live.
- Inactive - the companion is installed but not activated. Activate it to turn the integration on.
- Not installed - the companion is not on your site yet. You can install it in one click.

> _Screenshot: the Integrations tab showing companion cards with Active, Inactive, and Not installed states - captured in the image pass._

### Install a companion in one click

When a companion is not installed, its card offers a one-click install. BuddyNext downloads the free version of that plugin directly from wbcomdesigns.com, installs it, and activates it for you. There is no manual upload, no plugin search, and no license key to paste for the free tier - the download is handled for you.

After install, BuddyNext sends you to the right place to finish setup. For most companions that is the Plugins screen; for companions that have their own setup wizard (such as Career Board) you land on that companion's settings page so you can configure it straight away.

> **Tip:** One-click install needs the same permission WordPress requires for installing any plugin. If you do not see the install button, your account does not have the capability to install plugins on this site.

### Turn an integration on or off

An integration is only active when its companion plugin is active. Bridges are optional and opt-in:

- To turn a capability on, install and activate its companion.
- To turn it off, deactivate the companion. BuddyNext stops loading that bridge and the capability disappears cleanly - your members and feed simply no longer see it.

Some integrations also expose their own per-feature toggles (for example, whether forum activity appears in the feed). Those live on the integration's own page.

## Good to know

- An integration does nothing until its companion is present. BuddyNext loads zero integration code for a companion that is not installed, so unused integrations never affect performance.
- The catalog is extensible. Pro and third-party plugins can add their own entries, so the set of integrations you see can grow beyond the built-in list above.
- One-click install only ever downloads from wbcomdesigns.com. BuddyNext will not install an arbitrary plugin from an arbitrary source through this screen.
- Installing the free version is enough to light up the integration. Pro versions of these companions add more to each capability; each integration's own page covers what is free and what Pro adds.
- BuddyNext works fully standalone. If you never install a single companion, the social core - profiles, feed, spaces, follows, connections, notifications - works on its own.

## Outbound webhooks

Beyond these plugin integrations, BuddyNext can also send your community's events to any external system over a webhook - useful for automation tools like Zapier, Make, or n8n, or for syncing members into a CRM. See Outbound Webhooks for how to register an endpoint and subscribe to events.
