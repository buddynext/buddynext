# Advanced Profile Field Types (Pro)

Pro adds six richer profile field types on top of the free profile builder: an enhanced date, a map-based location, a file upload, an advanced multi-select, an advanced number with units, and a conditional field that appears only when another field has a specific value. You build these the same way you build any profile field, and members fill them in with the matching input control.

> **Before you start:** These field types come with BuddyNext Pro. With Pro active, they appear in the same profile field builder you already use, so there is nothing extra to switch on. This page covers the Pro field types only. For the base field builder, member-facing profile editing, and the field types that ship free, see Profile Fields.

## Why use it

Plain text fields capture text, and not much else. When you want clean, structured member data, you need controls that match the data. A "Location" field that records a real place on a map is far more useful than a free-text "City" box that members spell five different ways. A "Resume" field that accepts only PDFs is safer than a text box where people paste a link. A number field with a unit and a sensible minimum and maximum stops members entering "lots" in a field meant for years of experience.

Richer field types pay off in three places:

- Members fill profiles in faster because the control does the work (a date picker, a map search, a file chooser) instead of asking them to format a value by hand.
- The data comes back consistent, so directory filters, member search, and any later segmentation actually work on it.
- Conditional fields keep the edit form short - a follow-up question only shows up when it is relevant, so members are not scrolling past fields that do not apply to them.

A typical use: a professional network asks every member for their role, then a conditional "Years in management" number field that appears only when role is set to "Manager", a location field for their city, and a file field for an optional CV. Three of those four would be plain text boxes without Pro.

## How it works (for members)

A member opens their profile edit screen and sees each Pro field rendered with its own control. Saving validates and stores the value the same way free fields do, and the profile view shows the stored value formatted for reading.

### Date (extended)

Renders a native date picker. The member picks a day from the calendar control instead of typing a date string. On the profile view the value is shown using your site's configured date format.

### Location (map)

Renders an address box with a map below it. As the member types an address and confirms it, the field looks the place up and stores the address text together with its latitude and longitude. The member sees the resolved location; the stored value keeps the coordinates so the data is usable for distance or directory work later.

> **Note:** The map uses OpenStreetMap for its address lookup, loaded from a fast public source by default. If scripts are blocked for any reason, the address still saves as plain text, so nobody is ever stuck.

### File upload

Renders a file chooser limited to the file types the owner allowed. The member picks a file from their device; after upload the field shows the file name. Owners control which file types are accepted and the maximum size.

### Multi-select (advanced)

Renders the owner-defined list of choices and lets the member select more than one. Selections are stored together and shown back as a list on the profile view.

### Number (advanced)

Renders a number input. If you set a unit (for example years or kg), it shows next to the input, and the field enforces any minimum, maximum, and step you configured. The member cannot enter a value outside the allowed range.

### Conditional field

Stays hidden until the field it depends on holds the value the owner set. When the member sets that trigger field to the matching value, the conditional field appears and can be filled in. If the trigger value changes away, the conditional field hides again.

> **Tip:** Conditional fields are the cleanest way to ask a follow-up question. Use them instead of one long form where half the fields do not apply to most members.

## Setting it up (for owners)

Pro field types appear in the same field builder you use for free fields, under the field-type dropdown. Add a field, pick a Pro type, and a small set of type-specific options appears for you to configure. The options below are the ones each Pro type adds on top of the standard field settings (label, description, required, visibility).

> _Screenshot: the profile field builder with the type dropdown open showing the six Pro types - captured in the image pass._

### Date (extended)

| Setting | What it controls | Default |
|---|---|---|
| (none) | The extended date type has no extra options. It renders a date picker and displays using your site date format. | - |

### Location (map)

| Setting | What it controls | Default |
|---|---|---|
| (none) | The location type has no extra options to configure. It renders an address box plus map and stores address with coordinates. | - |

### File upload

| Setting | What it controls | Default |
|---|---|---|
| Allowed file types | Which file types members may upload, so you can limit it to (say) images and PDFs only. Leave empty to accept any type. | empty (any type) |
| Max size (MB) | The largest file a member may upload, from 1 to 100 MB. | 5 |

### Multi-select (advanced)

| Setting | What it controls | Default |
|---|---|---|
| Choices | The list members pick from, one option per line. | empty |

### Number (advanced)

| Setting | What it controls | Default |
|---|---|---|
| Unit | A label shown next to the input, such as years, km, or kg. | empty |
| Min | The lowest value a member may enter. | empty (no minimum) |
| Max | The highest value a member may enter. | empty (no maximum) |
| Step | The increment the input snaps to (for example 1 for whole numbers, 0.5 for halves). | any |

### Conditional field

| Setting | What it controls | Default |
|---|---|---|
| Trigger field | The other field this one watches. The conditional field shows only when that field holds the value you set below. | none |
| Trigger value | The value the watched field must hold for this field to appear. | empty |

## Good to know

- Pro field types are built on the free field engine, so visibility, required, and ordering work the same as any free field. See Profile Fields for those base behaviours.
- The location map, file-name preview, and conditional show/hide are progressive enhancements layered on the saved value. If scripts do not load, members still get a working text or file input and the value still saves - the picker UX is the enhancement, not the storage.
- Connected apps save through the same checks as the website, so a value entered in a mobile app is validated and stored exactly like one entered on the site.
- Empty profiles show nothing for a field a member has not filled in. To preview a field type end to end, fill it in and reopen the profile view.
- A conditional field watches another field by name. If you delete or rename the watched field, set the conditional field's trigger again so it keeps reacting to the right field.

## Free vs Pro

The free plugin ships the core field types (text, textarea, select, checkbox, and the other standard inputs) and the whole field builder, member edit form, and profile view. See Profile Fields for that baseline.

Pro adds the six field types documented here - extended date, location, file upload, advanced multi-select, advanced number, and conditional - by extending the free field engine. No free field type changes; Pro only adds to the type list and the per-type options.
