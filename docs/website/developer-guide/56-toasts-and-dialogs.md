# Toasts, Dialogs and Popup Tokens

How a plugin or theme shows a message, a confirmation or a menu on a BuddyNext page so it looks and behaves like the rest of the community. Call the shared functions and read the shared tokens; do not draw a second toast or hard-code a layer number.

## Overview / Contract

Every popup has one of six roles, and each role has one shared design:

| Role | Use it for | How |
|---|---|---|
| Notice | A message that leaves by itself, or stays until dismissed when it carries a link | `window.bnToast()` |
| Decision | A question the member must answer | `window.bnConfirm()`, `window.bnPrompt()`, `window.bnReportDialog()` |
| Menu | Actions from a control | `.bn-*` menu classes with the menu tokens below |
| Moment | A full celebration card (level-up, streak) | The plugin that owns the moment, drawn from the tokens below |
| Panel | A side or bottom surface that holds a task | Tokens below |
| Banner | An inline result inside a page | Status background tokens, no shadow, no layer |

## Toasts

`window.bnToast` is defined on every BuddyNext page. Both call shapes work:

```js
window.bnToast( 'Profile saved', 'success' );

const handle = window.bnToast( {
	key: 'points',              // same key updates the toast in place
	title: '+10 Points',
	body: 'Leave a comment',    // optional second line
	type: 'achievement',        // success | error | info | achievement
	href: '/members/me/#points',
	linkLabel: 'See my progress',
	persist: true,              // default: true when there is a link
} );

handle.update( { title: '+15 Points' } ); // repaint in place, restart the timer
handle.dismiss();
handle.el;                                  // the element, for a celebration effect
```

- Status is shown by the icon disc only. `warn`, `warning` and `danger` fold onto `info` and `error`; there is no coloured fill and no fifth look.
- A toast that carries a link stays until dismissed. Any toast pauses while the pointer or keyboard focus is on it.
- The same message repeated collapses onto the first and counts the repeats. Three toasts show at once.
- Errors announce assertively; everything else is polite.
- The host is a manual popover, so a toast raised while a native `<dialog>` is open is painted above it.
- Calls made before the script runs are queued and replayed, so a classic script can call `window.bnToast` at any time.

## Dialogs

`bnConfirm( { title, body, tone, confirmLabel, cancelLabel } )` resolves `true` or `false`. `bnPrompt( { title, body, placeholder, validate } )` resolves the text or `null`. Both use the compact 420px panel; forms use the default 480px. Never call `window.confirm()` or `window.prompt()` on a member-facing surface.

## Tokens

| Token | Value | Use |
|---|---|---|
| `--bn-z-menu` | 99000 | Dropdowns and popovers |
| `--bn-z-lightbox` | 100000 | Media lightbox |
| `--bn-z-modal` | 100100 | Dialogs, drawers, celebration cards |
| `--bn-z-toast` | 100200 | Toasts |
| `--bn-overlay` | ink at 55% (black at 66% in dark) | The one scrim behind any modal surface |
| `--bn-btn-h` / `--bn-btn-r` | 40px / `--bn-r-md` | Every `.bn-btn` |
| `--bn-r-md`, `--bn-shadow-md` | | Menus and popovers |
| `--bn-r-lg`, `--bn-shadow-lg` | | Dialogs and panels |
| `--bn-dur-fast`, `--bn-dur`, `--bn-dur-slow` | 120ms, 200ms, 400ms | Motion, with `--bn-ease` and `--bn-ease-out` |

Read each token with a fallback equal to the value above so your plugin renders correctly before BuddyNext is updated. Reduced motion means a 120ms fade and no transforms.
