# Suite Portfolio

The Portfolio tab is a single profile tab that gathers a member's activity from your other Wbcom apps into one place. Jobs and a resume from WP Career Board, listings from WB Listora, courses and certificates from Learnomy - they all appear inside one "Portfolio" tab on the member's BuddyNext profile, each as its own sub-tab, rather than each app adding a tab of its own.

> **Before you start:** The Portfolio tab is part of BuddyNext. The WP Career Board, WB Listora and Learnomy panels that fill it come with BuddyNext Pro, so for those apps you need Pro active and at least one of the integrations enabled. Any add-on can also add its own panel without Pro. There is nothing to build; the tab assembles itself.

## Why use it

As you add business apps to your community - a job board, a directory, an LMS - each one has things worth showing on a member's profile: the jobs they posted, the listings they run, the courses they teach or completed. Left to themselves, each app would add its own profile tab, and a member's profile would sprawl into a row of tabs nobody reads.

The Portfolio tab solves that once. Every integration contributes a panel to the same tab, so ten integrations still mean one tidy "Portfolio". A member gets a single professional profile surface that reflects everything they do across your community, and a visitor gets one place to understand who they are looking at.

## How it works (for members)

A member's profile shows a Portfolio tab whenever there is something to put in it. Inside, each connected app contributes its own sub-tab (a panel):

- **WP Career Board** - **Jobs** and **Resume** (the member's public resume), each linking out to the WP Career Board page. The owner sees "Manage Jobs" and "Manage Resume" links.
- **WB Listora** - **Listings**, the member's directory listings, linking out to each listing, plus an owner-only "Manage Business" link.
- **Learnomy** - **Certifications** (completed courses and certificates, public; it needs Learnomy's Certificates module, and Integration Settings shows it disabled with a **Turn on Certificates** action when the module is off), **Teaching** (courses they teach, public), and a private **Learning** shelf of in-progress courses.

Two things keep the tab honest:

- **Panels are gated by their own data.** A member who has no jobs, no listings, and no courses has no Portfolio tab at all - it appears only when a panel has content, so the tab is never empty.
- **Some panels are for the member only.** Learnomy's **Learning** sub-tab (in-progress courses with a completion percentage) shows only on the member's own profile as a personal resume shortcut. It is never shown to other people. Public credentials like certificates are visible to everyone.

Every panel links out to the source app rather than trying to reproduce it. The Portfolio tab surfaces the member's activity and points to where it lives; it never takes over the other app's screens, and its copy stays about the member, not about the plugin behind it.

## Built to serve an app, when there is one

The Portfolio is assembled from one shared data source and served through the REST API rather than being built into the page, so any client reading the API gets the same panels the web shows.

> **There is no BuddyNext mobile app yet.** The native iOS and Android app is planned and not yet released, so today this matters only if you are building your own client against the API. It is a design property of the Portfolio, not a feature you can use in a store app.

## Setting it up (for owners)

There is nothing to assemble. The Portfolio tab is built into BuddyNext; Pro fills it from the integrations you enable:

1. Make sure BuddyNext Pro is active.
2. Install and activate the companion apps you want - WP Career Board, WB Listora, or Learnomy.
3. Open **Integration Settings** (its own section in the BuddyNext admin menu). Each integration is a row with the usual switches: Navigation, Feed, and Search (an integration with no searchable content shows a dash under Search). Its Portfolio sub-tab appears automatically once the app is active, **Navigation** is on, and a member has content. The row also shows a badge when the partner is older than BuddyNext supports ("Update needed") or newer than it was built for ("Newer partner").

BuddyNext Pro declares these minimum partner versions: WP Career Board 1.4.3 and Learnomy 1.9.4. WB Listora declares none.

Each integration's own behaviour - what a job or listing or course does - is configured in that app, not here. The Portfolio tab only decides how a member's activity from those apps is shown on their profile.

### Space tabs from the same apps

Pro also adds two tabs to spaces, and both are on in every space by default while their app is active:

- **Events** - from Eventonomy. A space owner can switch it off for their space, and a choice already saved is kept. The tab lists **Upcoming** events first. A **Past** link (newest first) appears once the space has a past event the viewer may see. With Eventonomy 2.0.0 or later, an event open only to the space's members is listed only for those members.
- **Businesses** - from WB Listora. The tab shows when the space has a business listed, or when the viewer can add one (a space member) or review the queue (a space moderator). Developers can change this with the `buddynext_space_businesses_tab_visible` filter. Members of the space add their own listings. With WB Listora 2.1.1 or later, **Your businesses in this space** names every listing the member has there, newest first, up to 50 (with "Your newest 50 of N." beyond that), and the space team's own listing is listed at once without review. The submit message says whether the listing was added or sent for review. An older WB Listora keeps the shorter list and sends every listing for review.

In Settings > Navigation these two tabs are labelled with their app and show when Integration Settings has the app off.

With WB Listora 2.1.1 or later, an approved review of a published listing also posts a card to the community feed ("reviewed a listing", with the rating and an excerpt). Editing the review refreshes the card, and rejecting or deleting it removes the card. This follows the **Feed** switch for WB Listora.

## Partner cards in the feed (1.3.0)

When a member posts a job, a resume, a listing, a course result or an event in a connected app, a card appears in the community feed (while that app's **Feed** switch is on in Integration Settings). Each card reads the item as it is now each time the feed loads, so a changed price or date shows without reposting. The card's title opens the item in the partner app. Cards carry no extra "Open" button that would repeat the title.

| Card | What it shows | Buttons |
|---|---|---|
| **Job** (WP Career Board) | Company, place ("Remote" or the location), pay when the employer gave it, job type, and the closing date ("closes" before it, "closed" after it). The job's image follows the job: change or remove it in Career Board and the card follows. | None. The title opens the job. |
| **Open to Work** (WP Career Board Pro) | The resume headline as the title, then the summary, up to three skills, the location, years of experience, and the resume photo. | None. The headline opens the resume. |
| **Listing** (WB Listora) | Category, location, and the average rating with the review count once it has reviews. A review card leads with that review's own stars ("Rated 4 of 5"). | None. |
| **Course** (Learnomy) | Difficulty, number of lessons, length, and the price ("Free" or the amount). | **View certificate**, only on a card for an earned certificate. |
| **Event** (Eventonomy) | Date, time, city, price and how many are going, with **Cancelled**, **Ended**, **Online**, **Members only** or **Space members** labels when they apply. A repeating event gets one card for the whole series. | **Going** and **Maybe** for a free RSVP event that has not ended. Paid, sold-out and full events open the event page. |

When the source changes, the card changes with it:

- **Open to Work** shows only while the resume is published, public and marked open to work, and while Career Board lets visitors open resumes. If you limit who may open a resume in Career Board Pro, existing cards come down; they come back when you open it up again. Skills saved as rows in Career Board's resume builder show by name.
- **Jobs** hidden or restored in Career Board moderation take their card, search result and feed items with them.
- A job or resume that is unpublished leaves the feed, and re-publishing restores the same card with its reactions and comments. Deleting it removes the card.

## Good to know

- **One tab, never many.** However many Wbcom apps you run, they all share the single Portfolio tab. No integration adds a competing profile tab.
- **The tab hides when empty.** With no content from any integration, a member simply has no Portfolio tab - there is no blank surface to explain.
- **Owner-only panels stay private.** A member's in-progress learning shows only to them; public credentials show to everyone. Each panel decides its own audience.
- **Panels link out, they do not take over.** The Portfolio reflects activity that lives in WP Career Board, WB Listora, or Learnomy and links to it; it never edits or stores that data.
- **Inert without the apps.** With none of the companion apps installed, the Portfolio tab does nothing and shows nothing - no errors, no empty panels.

## Free vs Pro

The Portfolio tab itself is part of BuddyNext and appears once any integration or addon has something to show. The WP Career Board, WB Listora and Learnomy panels that feed it are part of BuddyNext Pro. The companion apps themselves (WP Career Board, WB Listora, Learnomy) run on their own, but bringing their activity onto a member's BuddyNext profile requires Pro. For what each app contributes to the community as a whole - feed activity, community search, and the shared notification center - see the WP Career Board, WB Listora, and Learnomy integration pages.

## Requirements

- BuddyNext Pro active alongside BuddyNext.
- At least one companion app active: WP Career Board, WB Listora, or Learnomy.
- Members with content in those apps (a job, a listing, a course) for their Portfolio tab to appear.

## Related

- [WP Career Board](../integrations/05-career-board.md) - one of the apps that fills the Portfolio tab.
- [Learnomy](../integrations/08-learnomy.md) - courses and certificates shown in the tab.
- [WB Listora](../integrations/07-listora.md) - directory listings shown in the tab.
- [Suite Notification Aggregation](28-suite-notifications.md) - the same connected apps mirroring their notifications into BuddyNext.
- [Member Profiles](../members/01-member-profiles.md) - the profile the tab lives on.
