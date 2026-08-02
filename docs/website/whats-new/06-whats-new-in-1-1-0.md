# What's New in 1.1.0

BuddyNext 1.1.0 is about the phone. Continuous scrolling in the feed, community screens that use the full width of a small screen, offline support that looks like your community rather than the browser's error page, and a round of fixes to the surfaces members touch most - the feed, the composer, notifications, profiles and signup.

> **Note:** BuddyNext free and BuddyNext Pro are released together. If you run both, update them at the same time so they stay in step.

## The feed keeps loading as you scroll

The feed loads the next posts as you reach them - on the main feed, Explore and Bookmarks alike - with no page reload and without losing your place.

This also fixed something worse than it looked: everything past the first screen used to be inert. React, Comment, Share and Save did nothing on any post loaded after the first page, so the further a member scrolled the less the feed responded.

## Community screens use the full width of a phone

Cards were running at about two thirds of the screen because three separate layers of spacing stacked up. Community screens now use the full width, and the result is the same whichever theme is active.

Messages runs edge-to-edge too, with the message field taking the full width and emoji, attachment and send on the line below.

## Offline, in your community's colours

Losing your connection now shows a branded offline page with a retry, instead of the browser's error screen. BuddyNext's styling is stored on the device, so an offline page looks like your community rather than an unstyled list of links.

Stylesheets, scripts and images are cached as they are used, capped so they cannot fill a member's device, and cleared automatically when the plugin updates.

## Signup and onboarding

- The sign-up form subtitle is editable under **Members > Registration & Login**, so a paid-only community is not promised "Free forever".
- A Member Type profile field can be shown on signup and on profiles; the member's choice assigns their member type, once.
- The onboarding stepper shows numbered dots with the current step labelled, at every screen size, so six steps never clip or scroll.
- Continuing no longer keeps the previous step's scroll position, which on a phone opened the next step on blank space.

## Composer and posts

- A link preview can be removed while editing a post.
- The character counter no longer keeps the previous post's count after publishing.
- Announcement expiry and schedule fields were pushed off the row on phones, so neither the date nor its clear button could be reached. Both now also name the site timezone they are read in.
- Announcement and pinned posts are marked by their label rather than a coloured card edge.

## Notifications and messages

- Notification preferences could not be saved at any screen size. The save bar was rendered at the far end of the page rather than pinned, and on phones the email frequency options were clipped so "Off" was cut in half.
- The count beside the Notifications heading went blank as the page finished loading, and dismissing an unread notification left the Unread tab still counting it.
- Tapping Unread or Requests in Messages on a phone opened a conversation instead of showing the filtered list.

## Smaller things that mattered

- Your own post now sits at the top of your feed for a few minutes after you publish it, instead of below every post from the people you follow.
- Toasts, cookie notices and save bars could sit underneath the mobile navigation bar. Every bottom-pinned surface now clears it from a single measurement.
- The feed tab strip gives a hint that more tabs exist beyond the screen edge, and a desktop-only instruction no longer shows on phones.
- The save bar on Edit profile and Notification preferences spans the width of a phone screen and shows its status there, so saving is confirmed instead of silent.
- Members with an imported profile address that breaks mentions can be repaired with one click from **BuddyNext > Members**, not only from the command line.

## For developers

The app config endpoint reports each installed integration with its on/off state and version, so a mobile client can hide a module the site does not run. In Pro, Events, Jobs, Courses and Listings report their plugin version to the same endpoint.

See the [Developer Guide](../developer-guide/) for the full reference.
