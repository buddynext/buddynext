# BuddyNext translations

BuddyNext is fully translation-ready. Every UI string is wrapped in gettext
(`__()`, `_e()`, `esc_html__()`, …) with the text domain **`buddynext`**, loaded
from this folder on `init`. JavaScript strings load via
`wp_set_script_translations()`, which reads the `.json` files in this folder.

## Translate BuddyNext into a new language

1. Copy `buddynext.pot` to `buddynext-{locale}.po` — e.g. `buddynext-fr_FR.po`,
   `buddynext-de_DE.po`. `{locale}` is the WordPress locale code shown at
   **Settings → General → Site Language**.
2. Translate the `msgstr` entries (Poedit, GlotPress, or any PO editor).
3. Compile the runtime files WordPress actually loads:
   ```
   wp i18n make-mo   languages/            # PHP -> buddynext-{locale}.mo
   wp i18n make-json languages/ --no-purge # JS  -> buddynext-{locale}-{hash}.json
   ```
4. Keep the `.po`, `.mo`, and `.json` together — in this `languages/` folder, or
   in `wp-content/languages/plugins/` to survive plugin updates. Both locations
   are searched.

`bin/i18n.sh` does steps for every `.po` in one command (and regenerates the
`.pot` first).

## "I created a .po but nothing translates"

The three usual causes:

- **No `.mo`.** WordPress loads the compiled `.mo`, never the `.po`. Run
  `wp i18n make-mo languages/` (Poedit also writes the `.mo` on save).
- **No `.json`.** Admin and interactive text is rendered in JavaScript and needs
  the `.json` files — run `wp i18n make-json languages/ --no-purge`. Without them
  those strings stay in English even when the `.mo` is present.
- **Wrong file name.** It must be exactly `buddynext-{locale}.mo` and
  `buddynext-{locale}-{hash}.json`, and `{locale}` must match the site language.
