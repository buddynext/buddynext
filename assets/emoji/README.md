# Reaction emoji assets

Vendored Microsoft Fluent Emoji SVGs (Flat style, 32×32) used by
`templates/parts/post-reaction-summary.php` and other surfaces that
display the 6 canonical BuddyNext reaction types.

## Source

[microsoft/fluentui-emoji](https://github.com/microsoft/fluentui-emoji)
— Licensed MIT for the code, CC-BY 4.0 for the graphical assets.

## Mapping

| BuddyNext reaction slug | Fluent Emoji asset | Source file |
| --- | --- | --- |
| `like`  | Thumbs up           | `assets/Thumbs up/Default/Flat/thumbs_up_flat_default.svg` |
| `love`  | Red heart           | `assets/Red heart/Flat/red_heart_flat.svg` |
| `haha`  | Face with tears of joy | `assets/Face with tears of joy/Flat/face_with_tears_of_joy_flat.svg` |
| `wow`   | Astonished face     | `assets/Astonished face/Flat/astonished_face_flat.svg` |
| `sad`   | Loudly crying face  | `assets/Loudly crying face/Flat/loudly_crying_face_flat.svg` |
| `angry` | Angry face          | `assets/Angry face/Flat/angry_face_flat.svg` |

These six match `ReactionService::REACTION_TYPES`.

## Why not native Unicode emoji

Native emoji characters render with whatever the OS font ships (Apple
Color Emoji on macOS / iOS, Segoe UI Emoji on Windows, Noto on
Android / Linux). The same reaction therefore looks visually different
on different platforms. Microsoft Fluent SVGs render identically
everywhere because we ship the asset.

## Adding a new reaction type

If Pro adds a reaction type via the `buddynext_reaction_types` filter:

1. Pick the matching emoji from the Fluent gallery at
   <https://github.com/microsoft/fluentui-emoji/tree/main/assets>.
2. Drop the Flat variant SVG into this directory, renamed to match the
   reaction slug (e.g. `celebrate.svg`).
3. The helper resolves the slug → file automatically. No code change.
