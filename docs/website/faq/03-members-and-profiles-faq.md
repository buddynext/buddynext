# Members and Profiles FAQ

Questions about profile fields, privacy, and what other members and connected apps can see.

## What can I put on my profile?

Beyond your display name, avatar, cover photo, and bio, your profile shows whatever custom fields the site owner has set up - grouped into sections such as Basic Info, Work Experience, or Education. BuddyNext ships a starter set of groups the owner can keep, edit, or remove. See [Custom Profile Fields](../members/02-profile-fields.md) and [Member Profiles](../members/01-member-profiles.md).

## Who can see my profile fields?

Each field has its own visibility: Public, Members only, Followers only, Connections only, or Only me. The site owner sets a group-level ceiling and a starting value for each field, but within that ceiling you choose your own field's audience - opening it up to Public, or narrowing it to Only me. Visibility is enforced when the profile is read: a field you have not opened up to a viewer is dropped from the response before it ever leaves the server, both on the page and through the API. See [Custom Profile Fields](../members/02-profile-fields.md).

## Does adding my location expose my exact coordinates?

No. If the site has the Pro Location field type, filling it in shows other members, connected apps, and the API only the **address** you chose (the readable place name) - never your exact coordinates. Your precise point is kept privately to redraw your own map pin when you come back to edit it; everyone else, including a "connections only" viewer who otherwise qualifies to see the field, only ever gets the address. See [Advanced Profile Field Types](../pro/09-advanced-profile-fields.md).

## Can a field on my profile appear or hide depending on another answer?

Yes, with BuddyNext Pro. A field can carry an "Only show this field when" rule tied to another field's answer - for example, a "Beard style" field that only shows when Gender is Male. It updates instantly as you change the deciding answer, works the same way at registration and on Edit Profile, and if you change your answer so a field hides, its value is cleared on save so it never lingers on your profile or in search. See [Conditional Logic for Profile Fields](../pro/27-conditional-profile-fields.md).

## Can a number field enforce a range, like "years of experience between 0 and 50"?

Yes, with the Pro **Advanced number** field type. The owner can set a unit label, a minimum, a maximum, and a step, and the input enforces the range - a member cannot enter a value outside it. See [Advanced Profile Field Types](../pro/09-advanced-profile-fields.md).

## What is a profile handle, and can I choose my own?

Your handle is the short, readable part of your profile's web address. BuddyNext assigns one automatically, but you can claim a custom one from your profile settings; availability is checked live as you type. Handles are unique across the whole community and are converted to a clean, URL-safe form automatically. See [Member Profiles](../members/01-member-profiles.md).

## Can I hide my online status?

Not currently - there is no per-member privacy control to turn off the online indicator. A member counts as online when they have been active in the last five minutes, and it drops off automatically after that. See [Online Presence](../members/09-presence-online.md).

## What is the difference between blocking, muting, and restricting someone?

- **Block** - the strongest option. Neither of you can message or follow each other; an existing follow is cut. It asks you to confirm first and can be undone later.
- **Mute** - quietly hides their content from you. They are never told, and it toggles with one tap.
- **Restrict** - limits how much they can interact with you, short of a full block. Also quiet and one tap.

Manage everything you have blocked, muted, or restricted from one list in your own profile settings. See [Blocking and Muting](../members/08-blocking-and-muting.md).

## What happens to my data if I delete my account?

Deletion is a full erasure with no "keep my content" option: your profile data, preferences, follows, connections, and blocks are erased, and your posts and comments are permanently deleted along with the account. This can't be undone. If you only want to step away rather than close the account for good, consider making your profile private or muting people instead. See [Privacy and Data](../accounts-access/08-privacy-and-data.md).

## Can I get a copy of my data before I decide?

Yes, if the site owner has left data export enabled. Open your account's Privacy or Your Data section and choose Export - it downloads a portable copy of your profile, activity, and connections and does not change or remove anything. See [Privacy and Data](../accounts-access/08-privacy-and-data.md).

## Related

- [Member Profiles](../members/01-member-profiles.md)
- [Custom Profile Fields](../members/02-profile-fields.md)
- [Advanced Profile Field Types (Pro)](../pro/09-advanced-profile-fields.md)
- [Privacy and Data](../accounts-access/08-privacy-and-data.md)
