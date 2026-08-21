# What's New in 1.1.3

BuddyNext 1.1.3 is mostly about the blocks. The block library gained two new blocks and a lot of options, but the more important part is that the buttons on block-placed cards now actually work - Join, Leave, Follow and Connect were inert wherever a block had put them, which made a page built out of blocks look finished and behave like a screenshot. Profile fields also got a round of fixes, and a long-standing problem where BuddyNext's pages could strip out other plugins' front-end code is closed.

> **Note:** BuddyNext free and BuddyNext Pro are released together. If you run both, update them at the same time so they stay in step.

## Buttons on block cards work

This is the fix worth updating for.

If you built a page with the member or space blocks - a members showcase on your homepage, a spaces directory on a landing page - the cards rendered correctly and the buttons on them did nothing. Join, Leave, Follow, Connect and the kebab menu were all dead. The same buttons worked perfectly on BuddyNext's own pages, which is what made this hard to spot: the markup was right, the styling was right, and nothing appeared in the browser console.

The cards were being drawn without the interactive code that powers them. A block has to declare the behaviour it needs, and these blocks were declaring their styles but not their store. Every block that renders an actionable card now declares both.

If you have blocks on a page and had quietly given up on them, try again after updating.

## Two new blocks, and more control over the existing ones

- **Community activity** puts a feed on any page.
- **Members showcase** presents a chosen set of members, rather than the whole directory.

Both existing card blocks gained options: card size, whether to show a follow control, whether to show member stats, and whether to offer a join action. A showcase can be hand-picked by naming exactly the members or spaces you want, instead of taking whatever the query returns.

The **Search bar** block can now be scoped, so a search box placed on a spaces page can search spaces rather than everything.

Two rendering improvements sit underneath all of this. Blocks now preview properly in the editor with the correct colours and spacing, instead of looking unstyled until published. And the member and space blocks render the same cards BuddyNext uses everywhere else rather than their own private copy, so a directory row looks identical wherever it appears.

## Long posts are readable

A very long post used to render in full in the feed, pushing everything else off the screen. Long posts now show a preview and open in full on their own page. Post text also uses a narrower measure, because a line of text that runs the full width of a wide screen is genuinely harder to read.

Interactive controls now meet the 40 pixel minimum on touch devices, so buttons are easier to hit on a phone.

## Other plugins keep working on community pages

BuddyNext renders its own pages, and in doing so it was dropping other plugins' front-end code - cookie-consent banners, header and footer builders, SEO output. On a community page those plugins simply were not there, which for a consent banner is a compliance problem rather than a cosmetic one.

Those plugins are now kept on BuddyNext pages, with their styles and scripts intact from the first request. They are discovered rather than kept on a list, so a plugin does not have to be known to BuddyNext in advance to survive.

## Profile fields

- A required field now carries its required state into the form, so a member sees the requirement before the save is rejected rather than after.
- A newly created field defaults to members-only rather than public.
- Saving a profile keeps the member on the edit screen instead of moving them away.
- The visibility control is properly tied to its label, which matters for screen readers.

## Reactions

A reaction the site does not recognise used to display its raw internal name. It now shows a neutral label and symbol instead.

## For Pro sites

**Plan access to profile groups is now something you choose.** Previously a plan carried a blunt limit on how many profile fields it unlocked. Now you pick which profile groups each plan includes, which is what owners were trying to express with the quota.

Alongside that: a paid plan no longer inherits the free plan's profile restrictions, and a system profile group can never be locked behind a plan - those groups hold the fields the community itself depends on.

Pro's public pages are also correctly reachable when the community is set to private, and the push-preferences toggle no longer shows the wrong state.

## Smaller fixes

- The Space Directory and Spaces Showcase blocks honour the List layout setting instead of always rendering a grid.
- A dismissed poll panel in the composer stays dismissed.
- Admin notices can be dismissed, and success notices clear themselves.
- Partner-integration colours resolve correctly instead of tinting against their own background.
