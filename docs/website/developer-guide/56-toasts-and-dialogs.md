# Toasts, Dialogs and Popup Tokens

How a plugin or theme shows a message, a confirmation or a menu on a BuddyNext page so it looks and behaves like the rest of the community. Call the shared functions and read the shared tokens; do not draw a second toast or hard-code a layer number.

## Overview / Contract

Every popup has one of six roles, and each role has one shared design:

| Role | Use it for | How |
|---|---|---|
| Notice | A message that leaves by itself, or stays until dismissed when it asks for `persist` | `window.bnToast()` |
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
	key: 'points',                       // same key updates the toast in place
	title: '+{count} points',            // one line; {count} is replaced by the counting number
	body: 'Leave a comment',             // optional second line
	type: 'award',                       // success | info | error | award
	icon: 'https://example.com/badge.png', // icon name, or an http(s) or same-site image URL
	href: '/members/me/#points',
	linkLabel: 'See my progress',
	count: { from: 0, to: 10 },
} );

handle.update( { title: '+{count} points', count: { from: 10, to: 15 } } ); // repaint in place, restart the timer
handle.dismiss();
handle.el;       // the element, once shown
handle.waiting;  // true while the toast is queued
```

### Options

| Option | What it does |
|---|---|
| `key` | Group key. A call whose key is already showing or queued updates that toast in place and keeps its place in the queue. |
| `title` | One line of plain text. Longer text ends in an ellipsis. (`message` is an alias.) |
| `body` | Optional one-line detail. |
| `type` | `success`, `info`, `error` or `award`. Picks the icon and tone. `tone` is an alias. `warn`, `warning` and `danger` fold onto `info` and `error`; there is no fifth look. |
| `icon` | Optional. A built-in icon name, or an http(s) or same-site image URL drawn round in the icon disc (badge art). Anything else, such as a `javascript:` or `data:` value, is ignored. |
| `href` and `linkLabel` | Optional small action button. `action: { label, href }` or `action: { label, onClick }` is the long form, and `onClick` suits an in-page action such as Undo. |
| `timeout` | Auto-close time in milliseconds. Overrides the default. |
| `persist` | `true` keeps the toast until it is closed. Off by default. |
| `count` | `{ from, to }`. Put the literal `{count}` in `title` and the number counts up inside it over about 400ms. A later `update()` with a new `count` continues from there. With reduced motion the final number shows at once. |

The short form also takes `window.bnToast( 'Saved', { tone: 'success', timeout: 4000 } )`.

### Checking support

`window.bnToast.supports` is `{ count, icon, award, queue, key }`, all `true`. A plugin checks it at call time and falls back to plain text on a BuddyNext that predates it, where `bnToast.supports` is undefined.

```js
const rich = window.bnToast && window.bnToast.supports && window.bnToast.supports.count;
```

### Behaviour

- **How many show.** Two toasts on a desktop, one at 640px and narrower. The rest wait in a queue, show at least 600ms apart, keep their place when updated and are never dropped.
- **How long.** A toast closes after 4 seconds, or 8 seconds when it has a button or is an error. `timeout` overrides this and `persist: true` keeps it until it is closed.
- **Pausing.** The pointer or keyboard focus on a toast pauses its timer.
- **Escape.** Escape closes the newest toast, unless a dialog is open. Escape belongs to the dialog then.
- **Repeats.** The same message repeated collapses onto the one already showing and restarts its timer.
- **Accessibility.** Errors announce assertively; everything else is polite. The close button has a label.
- **Layering.** The host is a manual popover, so a toast raised while a native `<dialog>` is open is painted above it.
- **Early calls.** Calls made before the script runs are queued and replayed, so a classic script can call `window.bnToast` at any time.

## Dialogs

`bnConfirm( { title, body, tone, confirmLabel, cancelLabel } )` resolves `true` or `false`; `tone` is `danger` (the default) or `default`. `bnPrompt( { title, body, placeholder, defaultValue, confirmLabel, cancelLabel, inputType, autocomplete, validate } )` resolves the text, or `null` on cancel; `inputType` defaults to a textarea (pass `password` for a single-line secret) and `validate( value )` returns an error string, or `''` when valid, which keeps the dialog open. `bnReportDialog( { title, body, confirmLabel, reasons } )` resolves `{ reason, notes }` or `null`, and `bnBlockConfirm( displayName )` is the shared block-member confirmation. Confirm and prompt use the compact 420px panel; forms use the default 480px. Never call `window.confirm()` or `window.prompt()` on a member-facing surface.

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
