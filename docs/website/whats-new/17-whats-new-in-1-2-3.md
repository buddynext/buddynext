# What's New in 1.2.3

This is a bug-fix release. It fixes directory pagination, log in return links and block theme support, and tidies a set of smaller details.

> **Note:** BuddyNext free and BuddyNext Pro are released together. If you run both, update them at the same time so they stay in step.

## Fixes for members

- **Directory pages.** Page 2 and beyond of the Members and Spaces directories now open instead of showing Page not found.
- **Members directory.** A stray line of code no longer appears under the member grid.
- **Log in return links.** The Log in links on the guest banner, the media lightbox, the profile editor and the login prompt now return members to the page they were on.
- **Report menu.** Report is no longer styled as a destructive action in the post options menu, matching the profile menu.
- **Removing a file.** Removing a file no longer promises a 30-day restore that the Files tab does not offer.
- **Activity feed.** The feed no longer fails on a site running an older WPMediaVerse.

## Fixes for site owners

- **Block themes.** On block themes such as Twenty Twenty-Five, community pages show the theme's own header and footer and no longer log a deprecation notice on every load.
- **Skip links.** Community pages expose a single main landmark when your theme already provides one, so screen reader skip links land in the right place.
- **Admin dropdowns.** Dropdowns on BuddyNext admin screens show their arrow again.
- **Faster document pages.** Pages with document uploads read the documents settings once per request instead of up to three times.

## Security

- The invite-link endpoints answer "not found" for a secret space the member cannot see, so the space's existence is not revealed.

## For developers

- The `buddynext_members_after` hook now fires on the Members directory.
- The REST API reference documents the `shared_post` field on re-shared posts.

## BuddyNext Pro 1.2.3

Pro 1.2.3 is the lockstep release. It has no changes of its own. Install it with BuddyNext 1.2.3.
