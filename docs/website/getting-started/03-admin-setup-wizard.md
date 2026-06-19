# Admin Setup Wizard

The Setup Wizard is the first thing BuddyNext shows you after the plugin is active, and it is the fastest way to a working community. In a few minutes it walks you through the handful of decisions that turn a fresh install into a real place to invite people: what to call it, how people join, what profiles look like, which notifications are on, how spaces are organized, and which pages members visit. Every choice comes with a sensible default, so you can click straight through and still end up with a complete, usable community - then fine-tune later if you want to.

## What it is

The wizard is an eight-step guided setup at **BuddyNext - Setup** in wp-admin. It is built for the community owner, not for developers. Each step asks one plain-language question, shows a short hint, and tells you exactly where the setting lives afterward so you never feel locked in.

You stay in control of the pace:

- **Continue** saves the current step and moves on.
- **Back** returns to the previous step without saving the current one.
- **Skip this step** moves on without changing anything on that step.
- **Save & exit** leaves the wizard and returns you to the dashboard. You can come back any time.

> **Note:** Nothing on this wizard is permanent. Every step names the admin section where you can change that setting later, and re-running the wizard never creates duplicate pages, categories, or profile groups.

> _Screenshot: the Setup Wizard with the step rail on the left, the Branding question in the body, and the progress bar at the top - captured in the image pass._

## Why use it

A community has a lot of moving parts - registration rules, profile fields, notification defaults, core pages, companion plugins. The wizard collects all of them into one short flow with safe defaults, so you do not have to hunt through the admin menu before your first member signs up. Finishing it gives you a community that is ready to invite people into, not a blank shell you still have to wire together.

## Step by step

| Step | Screen | What you decide |
|------|--------|-----------------|
| 1 | **Branding** | Your community name and a single brand color. The name appears in headers, emails, and the browser tab. The color drives primary buttons, links, and focus states. |
| 2 | **Registration** | How new members get in (see the registration modes below) and whether to require email verification before a member can post or react. |
| 3 | **Profile Fields** | Which optional profile groups to add. Headline, bio, and location are already on. Extras (Social Links, Work Experience, Education, Skills, Interests) are all pre-checked - leave them as-is or uncheck what you do not want. |
| 4 | **Notifications** | Which notifications are on by default for every new member: new follower, reactions, comments, mentions, and connection requests. Members can override their own later. |
| 5 | **Spaces** | Starter categories for organizing spaces. Comes pre-filled with General, Announcements, Help & Support, and Off-topic. Edit the comma-separated list or clear it to set categories up later. |
| 6 | **Pages** | Creates the core community pages - Community Feed, Members, and Spaces - with editable URL slugs. Pages that already exist are shown with a **Created** badge and are skipped, so nothing is duplicated. |
| 7 | **Addons** | Review the companion plugins that extend BuddyNext. If you can install plugins, each one is pre-selected and **Continue** installs and activates it. Already-active plugins show as connected. Uncheck anything you do not want. |
| 8 | **Done** | Confirms your community is ready. From here you can go to the dashboard to start inviting members, or open the front end to see what members will see. |

### Registration modes (Step 2)

This is your main lever for who can join. Pick one:

| Mode | Who gets in | Best for |
|------|-------------|----------|
| **Open registration** | Anyone can sign up directly. | Public communities. |
| **Invite only** | New members need an invite link. | Private circles. |
| **Admin approval** | Anyone can apply, and admins review each request. | Communities where curation matters. |

The same step has a **Require email verification** switch. With it on, members must confirm their email before they can post or react.

> **Note:** Open registration is the default. If you choose Invite only or Admin approval and want to be sure walk-in signups are fully closed, also turn off WordPress core registration under **Settings - General** in wp-admin.

### What finishing does

Reaching Step 8 and choosing **Finish setup** / **Go to dashboard** marks the wizard complete and applies everything you chose: your branding and registration options are saved, the profile groups you kept are created, your default notification preferences are stored, your space categories are added, and the core pages are published with the links you set, ready to use straight away.

## Re-running the wizard and where settings live afterward

You can re-open the wizard at any time from **BuddyNext - Setup** in wp-admin. It is safe to re-run: existing pages, categories, and profile groups are detected and left untouched rather than duplicated.

After setup, every choice has a permanent home in the admin:

| What you set in the wizard | Where to change it later |
|----------------------------|--------------------------|
| Community name and brand color | Settings - Branding |
| Registration mode and email verification | Members - Registration |
| Profile field groups | Members - Profile Fields |
| Default notifications | Notifications section |
| Space categories | Spaces - Categories |
| Page slugs | Settings - Pages |
| Companion plugins | Platform - Integrations, and the WordPress Plugins screen |

For the full map of the admin and where each of those sections lives, see the Admin Overview.
