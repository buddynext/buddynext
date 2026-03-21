# BuddyNext — Reactions + Comments

**Status:** Locked
**Last updated:** 2026-03-19

---

## What It Does

Shared interaction layer for all content — feed posts, media (via bridge), discussion replies (via bridge), DM messages. One system, all plugins feed into it.

---

## Reactions

- Admin-configurable emoji set (default: 👍 ❤️ 😂 😮 😢 😡)
- One reaction per user per object — selecting a new emoji replaces the old one
- Reaction counts grouped by emoji, "who reacted" list on hover/tap
- Supported on: feed posts, comments, DM messages, WPMediaVerse media, Jetonomy discussion cards

---

## Comments

- Threading: post → comments → replies to comments (two levels max, no deeper nesting)
- Rich text with @mentions and emoji
- Edit own comment (shows "edited" marker)
- Soft delete (shows "Comment deleted" placeholder)
- React on comments
- Report to moderation queue
- Moderator can pin a comment on space posts
- Cursor-based pagination

---

## Privacy

Inherits parent object's privacy. Can't comment on content you can't see.

---

## Addon Behavior

### WPMediaVerse
In BuddyNext mode: media uses `bn_reactions` + `bn_comments` (`object_type = 'mvs_media'`). WPMediaVerse's own reaction/comment system disabled via `mvs_owns_reactions` filter.

### Jetonomy
Surface-level reactions on discussion feed cards use `bn_reactions` (`object_type = 'jt_discussion'`). Full comment threads inside Jetonomy discussions stay in Jetonomy's own tables — Jetonomy owns its discussion engine.

---

## DB Tables

```sql
bn_reactions   -- user_id, object_type, object_id, emoji (unique per user+object)
bn_comments    -- id, user_id, object_type, object_id, parent_id, content, is_edited, is_deleted
```

Reaction/comment counts cached on parent row, recomputed async if drift detected.

---

## Integration Points

| Feature | Connection |
|---------|-----------|
| Activity Feed | Counts cached on `bn_posts` row |
| Notifications | Reaction + comment → notify post owner |
| Moderation | Comments reportable to moderation queue |
| WBGamification | Points for receiving reactions |

---

## Gaps / Open Questions

- None — fully locked
