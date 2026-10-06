# WPMediaVerse Surface Ownership Map

Which plugin owns the UI for each surface BuddyNext and WPMediaVerse both touch,
and which one owns the data behind it.

A shared surface with no named owner grows a second implementation, and two
implementations over one API drift. This page names an owner for each one.

## The standing rule

From [Integration Bridges](44-integration-bridges.md): **BuddyNext consumes
WPMediaVerse at the REST/API level only and owns 100% of its own UX.
WPMediaVerse JS/CSS is never enqueued on BuddyNext pages.**

That rule is honoured: the member Media tab, the activity feed and a space home
load zero `mvs*.js` / `mvs*.css`, and `bin/check-mediaverse-surfaces.php` fails
the build if BuddyNext enqueues one.

So the question for each surface below is never "may BuddyNext render this?" -
it is "**who owns the UI**, who owns the data, and is WPMediaVerse's own version
of the same screen still reachable next to it?"

## The map

| Surface | UI owner | Data owner | State |
|---|---|---|---|
| Direct messages | **BuddyNext** (`templates/messages/native.php`) | WPMediaVerse (`mvs/v1`) | Settled. MV suppresses its chat panel + messages page via `mvs_buddynext_active` |
| Document drive (space + profile) | **BuddyNext** (`RendersDriveFiles`) | WPMediaVerse (`mvs-pro/v1`) | Settled. MV answers drive filters, never queries `bn_*` |
| Media viewer (lightbox) | **BuddyNext** (`templates/partials/media-lightbox.php`, `assets/js/media/lightbox.js`) | WPMediaVerse (`mvs/v1`) | Settled - see below |
| Media grid / profile Media tab | **BuddyNext** | WPMediaVerse | Settled |
| Composer media attach | **BuddyNext** (`assets/js/feed/composer.js`) | WPMediaVerse | Settled |
| Activity cards for uploads | **BuddyNext** | WPMediaVerse | Settled: media only (image/video/audio), never documents |
| Member avatar | **Shared, by precedence** | each plugin's own | Settled - see below |

## Media viewer

BuddyNext's viewer is a full viewer over the `mvs/v1` API, not a stub:

| Capability | BuddyNext viewer |
|---|---|
| View, prev/next, download, share, reactions, report | yes |
| Save | Bookmarks the post the media belongs to, so the saved list is one list |
| Block author | yes (blocks the member, via BuddyNext) |
| Fullscreen | yes |
| Edit media (title, description, privacy, allow-download) | yes, shown only when the engine reports `can_edit` for that viewer |
| Edit / delete a comment | yes, per comment, from the engine's `can_edit` / `can_delete` flags |
| Comments for a logged-out visitor | readable; only posting needs a login |

Two rules hold this together:

- **Permissions come from the engine.** The viewer reads `can_edit` and `can_delete` off the media and comment payloads rather than re-deriving who may edit, and `bin/check-mediaverse-surfaces.php` fails the build if it stops.
- **Reading is not gated on writing.** The comments panel renders for everyone the API returns comments to; only the comment form and reaction controls are omitted for guests.

MediaVerse Collections are a MediaVerse feature and stay MediaVerse's. BuddyNext's viewer does not expose them.

### WPMediaVerse's own media pages

`mvs_buddynext_active` tells WPMediaVerse to stand down its chat panel, its standalone messages page and its duplicate notifications. It does **not** hide its own media pages: `/explore-media/`, `/my-media/` and `/upload-media/` stay published (with `/explore-document/` on sites running WPMediaVerse Pro), and they load WPMediaVerse's own viewer. They are WPMediaVerse pages, so the "no MV assets on BN pages" rule does not apply to them, and BuddyNext does not wrap them in its hub shell.

For single media, `mvs_single_media_redirect` sends `/media/{slug}/` to the activity the media was posted in (falling back to the owner's Media tab, then Explore), unless the owner set `buddynext_media_single_pages` to `dedicated`.

## Avatars, shared by precedence

Avatars are the one surface no single plugin owns, because any of them may hold
the member's picture. Three register `pre_get_avatar_data`:

```
[10] Jetonomy\Avatar::filter_avatar_data
[10] WPMediaVerse\Services\ProfileService::filter_avatar_data
[50] BuddyNext\Profile\AvatarService::filter_avatar_data      (BuddyNext upload only, else defers)
[99] BuddyNext\Profile\AvatarService::filter_avatar_fallback  (generated initials)
```

**The rule: a real picture always beats a generated one.** The two sources at
priority 10 each hold somebody's actual uploaded image, and whichever answers
first wins - a fair race. BuddyNext runs *after* them, at 50: it sets its own
uploaded avatar when the member has one (an explicit choice made in this
community, so it wins even over a sibling's) and otherwise returns the args
**unchanged**, leaving a real photo an earlier filter set. BuddyNext's generated
initials are not in that race at all; they run at 99, only when nobody produced a
URL.

That split keeps a generated *placeholder* from ever beating a real *photograph*:
a member with a valid `_mvs_custom_avatar` and no BuddyNext avatar sees their
WPMediaVerse picture, not generated initials.

Resulting precedence, one member, all four states:

| Member has | Renders |
|---|---|
| neither | BuddyNext initials |
| WPMediaVerse avatar only | **the WPMediaVerse avatar** |
| BuddyNext avatar only | the BuddyNext avatar |
| both | the BuddyNext avatar |

BuddyNext's own upload winning when a member has both is deliberate: it is the
avatar they set *in this community*. What is not acceptable is a placeholder
outranking anyone's real picture.

Adding another avatar source? Register at 10 if it holds real uploads. Never
register a generated or default image at 10.

Building a plugin that needs to *read* a member's avatar (rather than provide
one)? Pull it through `buddynext_user_avatar_url()` - see
[Member Identity Seams](55-member-identity-seams.md). It returns this same
resolved avatar and is never empty.

## Adding a surface

When either plugin adds a screen the other could also render:

1. Add a row here first, with an owner, before writing the UI.
2. The non-owner exposes data (REST or a filter) and renders nothing.
3. If the non-owner already has its own version of that screen, say here whether
   it stays reachable, and close the door if not.
4. Add a check to `bin/check-mediaverse-surfaces.php` that fails if the rule is
   broken. A rule nothing enforces is a rule that rots - the "no MV assets on BN
   pages" rule held for two years because it was easy to honour, not because
   anything tested it.

## What is enforced

`bin/check-mediaverse-surfaces.php`, wired into `bin/check.sh`, fails the build on:

| Rule | Detected by |
|---|---|
| No WPMediaVerse JS/CSS enqueued from BuddyNext | `wp_enqueue_script/style( 'mvs*' )` anywhere in `includes/` |
| Generated avatars never race real ones | `filter_avatar_fallback` missing, registered at priority <= 10, or initials generated inside the priority-10 filter |
| Comment controls follow the engine | `lightbox.js` no longer reading `can_edit` / `can_delete` off a comment |

The comment-flag check strips comments first and requires a real property read, so a docblock that merely explains the flags cannot satisfy it.
