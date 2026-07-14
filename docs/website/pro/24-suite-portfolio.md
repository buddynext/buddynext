# Suite Portfolio

The Portfolio tab is a single profile tab that gathers a member's activity from your other Wbcom apps into one place. Jobs and a resume from Career Board, listings from WB Listora, courses and certificates from Learnomy - they all appear as panels inside one "Portfolio" tab on the member's BuddyNext profile, rather than each app adding a tab of its own.

![A member profile showing the Portfolio tab with panels from the connected Wbcom apps](../images/member-profile.webp)

> **Before you start:** The Portfolio tab comes with BuddyNext Pro. It fills itself from whichever companion apps you run - Career Board, WB Listora, Learnomy - so you need Pro active and at least one of those integrations enabled for the tab to have anything to show. There is nothing to build; the tab assembles itself.

## Why use it

As you add business apps to your community - a job board, a directory, an LMS - each one has things worth showing on a member's profile: the jobs they posted, the listings they run, the courses they teach or completed. Left to themselves, each app would add its own profile tab, and a member's profile would sprawl into a row of tabs nobody reads.

The Portfolio tab solves that once. Every integration contributes a panel to the same tab, so ten integrations still mean one tidy "Portfolio". A member gets a single professional profile surface that reflects everything they do across your community, and a visitor gets one place to understand who they are looking at.

## How it works (for members)

A member's profile shows a Portfolio tab whenever there is something to put in it. Inside, each connected app contributes its own panel:

- **Career Board** - the member's jobs and their public resume, each linking out to the Career Board page.
- **WB Listora** - the member's directory listings, linking out to each listing, plus an owner-only link to manage their business.
- **Learnomy** - the member's credentials: completed courses and certificates (a public credential), courses they teach, and a private "Continue Learning" shelf.

Two things keep the tab honest:

- **Panels are gated by their own data.** A member who has no jobs, no listings, and no courses has no Portfolio tab at all - it appears only when a panel has content, so the tab is never empty.
- **Some panels are for the member only.** Learnomy's "Continue Learning" shelf (in-progress courses with progress bars) shows only on the member's own profile as a personal resume shortcut. It is never shown to other people. Public credentials like certificates are visible to everyone.

Every panel links out to the source app rather than trying to reproduce it. The Portfolio tab surfaces the member's activity and points to where it lives; it never takes over the other app's screens, and its copy stays about the member, not about the plugin behind it.

## In the app

The same Portfolio a member sees on the web is served to the BuddyNext mobile app through the API, from one shared data source. The app renders the identical set of panels without scraping the website, so a member's professional profile looks the same on both.

## Setting it up (for owners)

There is nothing to assemble. The Portfolio tab is built into Pro and fills itself from the integrations you enable:

1. Make sure BuddyNext Pro is active.
2. Install and activate the companion apps you want - Career Board, WB Listora, or Learnomy.
3. On the Platform → Integration Display tab, each integration has the usual switches (show in navigation, post to the feed, include in search). Its Portfolio panel appears automatically once the app is active and a member has content.

Each integration's own behaviour - what a job or listing or course does - is configured in that app, not here. The Portfolio tab only decides how a member's activity from those apps is shown on their profile.

## Good to know

- **One tab, never many.** However many Wbcom apps you run, they all share the single Portfolio tab. No integration adds a competing profile tab.
- **The tab hides when empty.** With no content from any integration, a member simply has no Portfolio tab - there is no blank surface to explain.
- **Owner-only panels stay private.** A member's in-progress learning shows only to them; public credentials show to everyone. Each panel decides its own audience.
- **Panels link out, they do not take over.** The Portfolio reflects activity that lives in Career Board, Listora, or Learnomy and links to it; it never edits or stores that data.
- **Inert without the apps.** With none of the companion apps installed, the Portfolio tab does nothing and shows nothing - no errors, no empty panels.

## Free vs Pro

The Portfolio tab and the suite integrations that feed it are part of BuddyNext Pro. BuddyNext Free surfaces none of the companion-app activity on profiles. The companion apps themselves (Career Board, WB Listora, Learnomy) run on their own, but bringing their activity onto a member's BuddyNext profile requires Pro. For what each app contributes to the community as a whole - feed activity, community search, and the shared notification center - see the Career Board, WB Listora, and Learnomy integration pages.

## Requirements

- BuddyNext Pro active alongside BuddyNext.
- At least one companion app active: Career Board, WB Listora, or Learnomy.
- Members with content in those apps (a job, a listing, a course) for their Portfolio tab to appear.
