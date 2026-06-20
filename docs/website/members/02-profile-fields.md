# Custom Profile Fields

Profile fields let you decide what members can tell each other about themselves. You build the fields (name, bio, role, location, links, and more), and members fill them in on their profile. BuddyNext ships with a starter set of groups and fields, and you can add your own.

![Member profile showing custom fields, bio, and avatar populated from profile field groups](../images/member-profile.png)

![Platform - Features admin tab where profile field groups and the profile fields feature are configured](../images/admin-features.png)

## Why use it

A profile is the first thing one member sees about another. Out of the box you get a name and an avatar, which is rarely enough for a real community. Custom fields turn a thin profile into a useful one: a member can show their role, their skills, where they work, the links they want to share, and anything else your community cares about.

Two things make this worth setting up early:

- **Richer profiles.** Members who can describe themselves are more likely to be recognised, followed, and trusted. A profile that answers "who is this and why should I connect" does more for engagement than any feature you can bolt on later.
- **Better directory filtering.** Fields you mark as searchable feed the member directory and search. If you collect "Skills" or "Department" as a field, members can find each other by it. Empty profiles cannot be filtered, so the fields you ask for today are the filters you get tomorrow.

You group fields so they render as tidy sections (Basic Info, Work Experience, and so on), control who can see each one, and choose which fields appear on the sign-up form so you collect the essentials before a member ever reaches their profile.

## How it works (for members)

A member manages their own fields from Edit Profile.

- **Fill in fields.** Every field you have created shows on the member's Edit Profile form, grouped by section. The member types or selects a value and saves. Empty fields are simply left blank.
- **Repeating sections.** Some groups (for example Work Experience or Education) allow more than one entry, so a member can add several jobs or schools, each with its own set of values.
- **Per-field visibility.** Next to each field the member can set who is allowed to see that value. The member can only make a field more private than you set it, never more public. If you set a field to followers-only, a member can tighten it to private, but cannot open it back up to public.

When another member views the profile, they see only the fields their relationship allows. A logged-out visitor, a follower, a connection, and the profile owner can each see a different set of values from the same profile.


## Setting it up (for owners)

You manage groups and fields from the BuddyNext profile fields admin area. A group is a section; a field lives inside a group.

### Create a group, then add fields

1. Create a field group and give it a label (for example "Professional Info"). Choose whether the group is a flat section or a repeating section.
2. Add one or more fields to the group. For each field you set its label, its type, and the controls below.
3. Reorder groups and fields so they render in the order you want.

### Per-field controls

Every field carries the following controls.

| Control | What it controls | Default |
|---|---|---|
| Label | The field name members see on the form and the profile. | (required, no default) |
| Field type | How the field is captured and displayed - see the type table below. | Text |
| Visibility | Who can see the value: Public, Followers, Connections, or Private. A member may tighten this further per field, never loosen it. | Public |
| Required | Marks the field as expected. The member is nudged to complete it (it counts against their profile completion score). | Off |
| Searchable | Mirrors the value into search so members can find each other by this field in the directory and search. Available on text-style fields only. | Off |
| Show on registration | Adds the field to the sign-up form so you collect it before the member reaches their profile. Fields in a repeating group cannot be added to sign-up. | Off |
| Sort order | The position of the field within its group. Lower numbers appear first. | Appended last |

### Group controls

| Control | What it controls | Default |
|---|---|---|
| Label | The section heading shown on the profile and edit form. | (required, no default) |
| Type | Flat (single set of fields) or Repeater (members can add multiple entries). | Flat |
| Visibility | Section-level visibility applied on top of each field's own visibility. The most restrictive of the two wins. | Public |
| Sort order | The position of the group on the profile. Lower numbers appear first. | Appended last |

### Field types (free tier)

The free tier covers the everyday field types most communities need.

| Type | Use it for |
|---|---|
| Text | Short single-line answers (job title, city). |
| Paragraph | Longer free text (bio, about me). |
| Number | Numeric values (years of experience). |
| URL | A single web address. |
| Email | An email address. |
| Phone | A phone number. |
| Date | A single date. |
| Yes / No | A simple boolean toggle. |
| Dropdown | One choice from a list you define. |
| Radio | One choice shown as radio buttons. |
| Multi-select | Several choices from a list. |
| Colour | A colour value. |
| File | A single file. |

> **Tip:** Mark the one or two fields your directory should filter on (such as Skills or Department) as searchable, and the rest as not searchable. Only searchable fields can be used to find members.

### Showing a group on a page with the block

You can render a single profile group anywhere on the site with the Profile Fields block. The block has two settings: which member's profile to read from, and which group to display (for example Basic Info or Work Experience). Leave the member setting at its default to show the profile being viewed.

## Good to know

- **Visibility is enforced by relationship, not by hiding in the page.** When a profile is read, BuddyNext checks the viewer's relationship to the owner (logged-out, follower, connection, or the owner themselves) and drops any field the viewer is not allowed to see before the value ever leaves the server. A Private field never appears for anyone but the owner.
- **Most restrictive wins.** A value's effective visibility is the strictest of the group setting, the field setting, and the member's own per-field choice. Tightening any one of them tightens the result.
- **Required is a nudge, not a hard block.** A field marked required counts against the member's profile completion score, so they are prompted to fill it. Submitting the profile with a required field left empty does not currently block the save; the field is simply recorded as incomplete. Treat required as "strongly encouraged" rather than "cannot continue."
- **Bad values are skipped, not rejected.** If a member enters a value that does not fit the field type (for example letters in a Number field), that one value is not stored and the rest of the profile still saves. The member is not shown an inline error for the skipped field today.
- **Empty profiles hide the feature.** A field with no value does not render on the profile view. A brand-new community with empty profiles will look sparse until members fill fields in, which is why setting a few fields to show on registration is worth doing.
- **The starter set is yours to keep or change.** BuddyNext seeds a few groups (such as Basic Info, Social Links, Work Experience, Education, and Skills) so you are not starting from a blank slate. You can edit, reorder, or extend them.

## Free vs Pro

The free tier covers the basics: the everyday field types listed above (text, paragraph, number, URL, email, phone, date, yes/no, dropdown, radio, multi-select, colour, and file), grouped into sections with visibility, required, searchable, and show-on-registration controls. For a typical community a handful of well-chosen fields is enough to get started.

Pro adds six advanced field types for communities that need richer data capture:

- Extended date (date ranges and finer date handling)
- Location (structured place data)
- File (advanced file handling)
- Multi-select (advanced multi-choice)
- Advanced number (numeric fields with extra rules)
- Conditional (fields that show or hide based on another field's answer)

These advanced types are registered by the Pro add-on and become available in the same field type picker once Pro is active. For the full list and setup, see Advanced Profile Fields.
