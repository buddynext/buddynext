# Integrations FAQ

Questions about the companion plugins BuddyNext connects to and how those bridges behave.

## Which plugins integrate with BuddyNext?

MediaVerse (WPMediaVerse) for direct messaging and media, Jetonomy for forums, WB Gamification for points/badges/levels, Career Board for jobs, Learnomy for courses and certificates, Listora for directory listings, Eventonomy for events, and WB Member Blog for front-end post publishing. Each one is optional. See [Integrations Overview](../integrations/01-overview.md).

## How does an integration actually work - does BuddyNext replace the companion plugin?

No. Each companion plugin keeps owning its own data and its own screens - Learnomy owns courses and lessons, Listora owns listings, Jetonomy owns forum threads. BuddyNext connects to it through a bridge: an adapter that reacts to the companion's own events and surfaces the meaningful moments (a course completion, a new listing, a new discussion) inside the community's feed, search, notifications, and profile tabs. See [Integration Bridges](../developer-guide/44-integration-bridges.md).

## Does installing BuddyNext slow my site down if I don't use any companion plugins?

No. An integration bridge only loads when its companion plugin is actually active on the site - BuddyNext checks for the companion first and loads zero integration code when it is not present. A companion you never install costs nothing in performance. See [Integrations Overview](../integrations/01-overview.md).

## Do I need BuddyNext Pro for these integrations to work?

It depends on the companion. WPMediaVerse (messaging), Jetonomy (forums), WB Gamification, and WB Member Blog surface in the community with BuddyNext Free. Career Board, Listora, Learnomy, and Eventonomy surface into the community feed, search, and profiles only with BuddyNext Pro active - the companion plugin still works fine on its own without Pro, you simply will not get the community surfacing until Pro is on. See [Integrations Overview](../integrations/01-overview.md).

## Can I choose where an integration shows up, without turning it off entirely?

Yes. **Platform > Integration Settings** gives each connected integration its own switches: show its tab in navigation, post its events to the activity feed, and include its content in community search - independently of each other. Turning a switch off only hides that surface; it never deletes anything from the companion plugin. See [Integrations Overview](../integrations/01-overview.md).

## How does Learnomy's community federation work?

When a course, a Learnomy Space, or a cohort is linked to a BuddyNext Space (set up from Learnomy's own admin screens), membership follows access automatically in one direction: enrolling adds the member to the linked community Space, and losing access - unenrolling, an expired enrollment, or leaving the Learnomy Space or cohort - removes them from it the same way. A community BuddyNext creates for you enforces this roster fully; linking an existing Space you already run only ever adds members to it, never removes someone you are managing yourself. See [Learnomy](../integrations/08-learnomy.md).

## Can a membership plan grant access to a Learnomy course?

Yes, the reverse direction also exists. A BuddyNext membership plan can be mapped to specific Learnomy courses or a Learnomy Space, so buying or upgrading to that plan grants access immediately, and losing the plan withdraws it the same way - tracked separately from anything the member purchased or enrolled in directly through Learnomy. See [Learnomy](../integrations/08-learnomy.md).

## What is the Listora "Businesses in a Space" feature?

It is a curated business directory a space owner can turn on for their own space (Space Settings > Integrations > Businesses, off by default). Members submit one of their own published Listora listings to the space, and the space owner, a space moderator, or a site admin approves it before it appears as a compact card. It respects the space's own privacy - a private or secret space's business directory is only visible to its members. Needs BuddyNext Pro and WB Listora 1.8.0 or newer. See [Listora](../integrations/07-listora.md).

## If I deactivate a companion plugin, does BuddyNext break?

No. BuddyNext stops loading that bridge and the capability disappears cleanly - your feed and navigation simply no longer show it. The rest of the community keeps working normally. See [Integrations Overview](../integrations/01-overview.md).

## Related

- [Integrations Overview](../integrations/01-overview.md)
- [Learnomy](../integrations/08-learnomy.md)
- [Listora](../integrations/07-listora.md)
- [WPMediaVerse](../integrations/02-wpmediaverse.md)
