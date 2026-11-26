# Accessibility and Keyboard Use

BuddyNext is built so members can use the whole community with a keyboard, a screen reader or a phone. You do not switch anything on: it works this way on every theme. This page lists what members can rely on, so you can answer an accessibility question or a support ticket quickly.

## Why it matters

Some members cannot use a mouse, some use a screen reader, and many only ever see your community on a phone. If a menu cannot be opened from the keyboard, or a button is too small to tap, those members are locked out of the action, not just slowed down. Mainstream social apps meet these basics, and members expect the same from your community.

## Keyboard

- **Menus and popovers.** The post menu (the three dots on a post), the **Posting to** popover in the composer and the emoji sheet open from the keyboard. Focus moves into them, the arrow keys move between choices, Escape closes them, and focus returns to the button that opened them.
- **Avatar cropper.** When you crop a new profile photo, Tab reaches the preview and the arrow keys move the picture under the round mask (hold Shift for bigger steps).
- **Emoji picker.** Focus moves into the picker, Tab stays inside it, the arrow keys move between emoji, and focus returns to the control that opened it.
- **Dialogs.** A dialog keeps Tab inside it until you close it, including in Safari and on iPad.
- **Safe default on risky choices.** A confirmation for something that cannot be undone, or that removes content (for example Report, or **Restore defaults** in the admin), opens with focus on **Cancel**, so pressing Enter by reflex does nothing harmful.
- **Shortcuts never interrupt your work.** Single-key shortcuts (`/` for search, `n` for a new post, `g` then a letter to go somewhere) do nothing while you are in a form, a dropdown or a dialog. Ctrl/Cmd+K opens search from anywhere. Press `?` on a community page to see the list. Developers can turn the single-key shortcuts off with the `buddynext_keyboard_shortcuts_enabled` filter.

## Seeing and tapping

- **Contrast.** Outlined buttons, form fields, chips, switches and muted text meet WCAG contrast in light and dark mode: control edges at 3:1, text at 4.5:1.
- **Tap size on phones.** Buttons, switches and filter rows are at least 40px tall on a phone, so they are easy to hit with a thumb.
- **Long text.** Text longer than its field or button ends in an ellipsis instead of spilling out of it.
- **Right-to-left languages.** Pages follow the reading direction of your site language. For example, the Arabic sign-up page fits the screen without scrolling sideways.

## Messages on screen

- **Toasts stay readable.** A short confirmation message (a toast) never sits on top of the cookie notice.
- **Clear form errors.** A malformed email address on the sign-up form is answered with "Please enter a valid email address." on the email field, instead of failing without a reason.

## Related

- [Appearance and Branding](07-appearance-and-branding.md) - brand colour and dark mode.
- [Admin Overview](04-admin-overview.md) - admin screen behaviour, including the Save bar.
- [Search](../community/12-search.md) - the search shortcuts.
