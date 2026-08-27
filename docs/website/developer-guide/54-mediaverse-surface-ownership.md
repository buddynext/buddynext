# WPMediaVerse Surface Ownership Map

Which plugin owns the UI for each surface BuddyNext and WPMediaVerse both touch,
and which one owns the data behind it.

This page exists because the surfaces with a written owner behave, and the ones
without keep producing the same bug in a new place. It is the answer to "why do
we keep fixing this again and again": a shared surface with no named owner grows
a second implementation, and two implementations over one API always drift.

## The standing rule

From [Integration Bridges](44-integration-bridges.md): **BuddyNext consumes
WPMediaVerse at the REST/API level only and owns 100% of its own UX.
WPMediaVerse JS/CSS is never enqueued on BuddyNext pages.**

That rule is correct and is being honoured — verified 2026-08-27 by fetching
`/members/{user}/media/`, `/activity/` and a space home and grepping the markup:
zero `mvs*.js` / `mvs*.css` on any of them.

So the question for each surface below is never "may BuddyNext render this?" —
it is "**has BuddyNext actually finished rendering it**, and is WPMediaVerse's own
version of the same screen still reachable next to it?"

## The map

| Surface | UI owner | Data owner | State |
|---|---|---|---|
| Direct messages | **BuddyNext** (`templates/messages/native.php`) | WPMediaVerse (`mvs/v1`) | Settled. MV suppresses its chat panel + messages page via `mvs_buddynext_active` |
| Document drive (space + profile) | **BuddyNext** (`RendersDriveFiles`) | WPMediaVerse (`mvs-pro/v1`) | Settled by directive 2026-08-24. MV answers drive filters, never queries `bn_*` |
| Media viewer (lightbox) | **BuddyNext** (`templates/partials/media-lightbox.php`) | WPMediaVerse (`mvs/v1`) | **Incomplete** — see below |
| Media grid / profile Media tab | **BuddyNext** | WPMediaVerse | Settled |
| Composer media attach | **BuddyNext** (`assets/js/feed/composer.js`) | WPMediaVerse | Settled |
| Activity cards for uploads | **BuddyNext** | WPMediaVerse | Settled by directive 2026-08-27: media only (image/video/audio), never documents |
| Member avatar | **Shared, by precedence** | each plugin's own | Settled — see below |

## Media viewer — the two open items

### 1. BuddyNext's viewer is a subset of WPMediaVerse's

Measured 2026-08-27 on the same image, same comment, six viewer states:

| | BuddyNext viewer | WPMediaVerse viewer |
|---|---|---|
| View, prev/next, download, share, favorite, reactions, report | yes | yes |
| Block author | yes | — |
| Fullscreen | — | yes |
| Edit media (title, description, privacy, slug, allow-download) | — | yes |
| Save to collection | — | yes |
| **Edit / delete own comment** | — | yes |
| **Comments visible to a logged-out visitor** | — | — |

Two of those gaps are defects rather than scope decisions:

- **Nobody can edit or delete their own comment in BuddyNext's viewer** — not even
  its author. `commentEl()` builds an author `<strong>` and a text `<span>` and
  stops; there is no `canEdit` / `canDelete` anywhere in
  `assets/js/media/lightbox.js`. The API supports `PUT`/`PATCH`/`DELETE` on that
  comment.
- **A logged-out visitor sees no comments at all**, because
  `$bn_lb_can_interact = is_user_logged_in()` gates the comments *panel* as well
  as the comment *form* — reading is gated on being able to write. The API
  returns 200 with the comments to an anonymous caller, so the data is public and
  only the rendering is missing.

The remaining gaps (fullscreen, edit media, collections) are product scope, not
bugs. They are listed so the decision is deliberate.

### 2. WPMediaVerse's own media pages are still reachable on a BuddyNext stack

`mvs_buddynext_active` already tells WPMediaVerse to stand down its chat panel,
its standalone messages page and its duplicate notifications. It does **not**
cover its media pages: `/explore-media/`, `/my-media/`, `/upload-media/` and
`/explore-document/` remain published, and they legitimately load WPMediaVerse's
own viewer — those are WPMediaVerse pages, so the "no MV assets on BN pages" rule
does not apply to them.

That is where "the same image opens two different lightboxes" comes from. It is
not a breach of the rule; it is a door the rule was never asked to close.

BuddyNext already closes the equivalent door for single media:
`mvs_single_media_redirect` sends `/media/{slug}/` to the activity the media was
posted in. The same treatment is what the remaining pages need.

## Avatars — shared, by precedence

Avatars are the one surface no single plugin owns, because any of them may hold
the member's picture. Three register `pre_get_avatar_data`:

```
[10] Jetonomy\Avatar::filter_avatar_data
[10] WPMediaVerse\Services\ProfileService::filter_avatar_data
[10] BuddyNext\Profile\AvatarService::filter_avatar_data      (real avatars only)
[99] BuddyNext\Profile\AvatarService::filter_avatar_fallback  (generated initials)
```

**The rule: a real picture always beats a generated one.** Everything at priority
10 is somebody's actual uploaded image, and whichever answers first wins — that
is a fair race. BuddyNext's generated initials are not in that race; they run at
99, only when nobody produced a URL.

That split is the fix for a real defect. All three used to sit at priority 10 and
BuddyNext registered last, so its *placeholder* beat WPMediaVerse's *photograph*:
a member with a valid `_mvs_custom_avatar` and no BuddyNext avatar saw generated
initials on every surface, and their upload appeared nowhere. Verified before the
fix by unhooking BuddyNext's filter — the real avatar then appeared everywhere.

Measured precedence after the fix, one member, all four states:

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
register a generated or default image at 10 — that is the mistake this documents.

## Adding a surface

When either plugin adds a screen the other could also render:

1. Add a row here first, with an owner, before writing the UI.
2. The non-owner exposes data (REST or a filter) and renders nothing.
3. If the non-owner already has its own version of that screen, say here whether
   it stays reachable, and close the door if not.
4. Add a check to `bin/check-mediaverse-surfaces.php` that fails if the rule is
   broken. A rule nothing enforces is a rule that rots — the "no MV assets on BN
   pages" rule held for two years because it was easy to honour, not because
   anything tested it.

## What is enforced

`bin/check-mediaverse-surfaces.php`, wired into `bin/check.sh`, fails the build on:

| Rule | Detected by |
|---|---|
| No WPMediaVerse JS/CSS enqueued from BuddyNext | `wp_enqueue_script/style( 'mvs*' )` anywhere in `includes/` |
| Generated avatars never race real ones | `filter_avatar_fallback` missing, registered at priority <= 10, or initials generated inside the priority-10 filter |
| Comment controls follow the engine | `lightbox.js` no longer reading `can_edit` / `can_delete` off a comment |

Mutation-tested — each rule was broken in turn and the check failed for each,
then passed again once restored. A guard that has never been seen to fail is not
a guard. The comment-flag check originally matched anywhere in the file and was
satisfied by the docblock *explaining* the flags while the code had stopped
reading them; it now strips comments first and requires a real property read.
