# BuddyNext — Frontend Member Action QA Checklist

Run this checklist after every significant code push to verify all interactive member
actions work end-to-end in the browser.

**Local URL:** http://forums.local
**Auto-login helper:** append `?autologin=1` for admin, `?autologin=alice` for Alice, etc.

**Test accounts:**

| Handle | ID | Role |
|--------|----|------|
| admin | 1 | Administrator |
| alice | 3 | Member |
| bob | 4 | Member |
| carol | 5 | Member |
| testuser | 2 | Subscriber |

---

## How to Run

1. Open http://forums.local?autologin=alice in Chrome (Alice = primary test user)
2. Open http://forums.local?autologin=bob in a second Chrome profile (Bob = secondary)
3. Work through each section below in order
4. Mark `[x]` as you go — reset to `[ ]` before the next run

---

## 1. Activity Feed — Post Creation

| # | Action | Expected | Pass |
|---|--------|----------|------|
| 1.1 | Type text in composer → click Post | New post card appears at top of home feed instantly | `[ ]` |
| 1.2 | Include `#hashtag` in post text | `#hashtag` becomes a clickable link in the rendered card | `[ ]` |
| 1.3 | Include `@alice` in post text (as Bob) | Post saves; notification delivered to Alice | `[ ]` |
| 1.4 | Create post → check DB | `SELECT * FROM wp_bn_posts ORDER BY id DESC LIMIT 1` returns the row with correct `user_id`, `type='text'`, `status='published'` | `[ ]` |

**REST endpoint:** `POST /wp-json/buddynext/v1/posts`
**Hook fired:** `buddynext_post_created($post_id, $user_id, 'text')`

---

## 2. Poll

| # | Action | Expected | Pass |
|---|--------|----------|------|
| 2.1 | Open poll composer → add 3 options → submit | Poll card renders with 3 vote buttons and 0% bars | `[ ]` |
| 2.2 | Click option 1 | Bar fills to 100%, vote count = 1, button shows selected state | `[ ]` |
| 2.3 | Click option 2 | Vote switches to option 2; option 1 reverts to 0 | `[ ]` |
| 2.4 | Reload page | Vote selection persists (my-vote endpoint restores state) | `[ ]` |

**REST endpoints:** `POST /buddynext/v1/posts` (type=poll), `PUT /buddynext/v1/polls/{id}/vote`, `GET /buddynext/v1/posts/{id}/my-vote`

---

## 3. Reactions

| # | Action | Expected | Pass |
|---|--------|----------|------|
| 3.1 | Hover reaction button on a post | Reaction picker (6 icons) appears | `[ ]` |
| 3.2 | Click "Like" (thumbs-up) | Count increments by 1; button shows active/filled state | `[ ]` |
| 3.3 | Click same reaction again | Count decrements by 1; button reverts to inactive | `[ ]` |
| 3.4 | Click different reaction | Switches from old type to new; total count stays the same | `[ ]` |
| 3.5 | Check DB | `SELECT * FROM wp_bn_reactions WHERE post_id=? AND user_id=?` has 1 row with correct emoji | `[ ]` |

**REST endpoints:** `POST /buddynext/v1/posts/{id}/reactions`, `DELETE /buddynext/v1/posts/{id}/reactions`
**Hook fired:** `buddynext_reaction_added($reaction_id, $post_id, $user_id, $emoji)`

---

## 4. Comments

| # | Action | Expected | Pass |
|---|--------|----------|------|
| 4.1 | Click comment icon on a post | Inline comment form expands below post | `[ ]` |
| 4.2 | Type comment → submit | Comment appears threaded below post; `comment_count` on post card increments | `[ ]` |
| 4.3 | Click Reply on a comment | Nested reply form opens indented | `[ ]` |
| 4.4 | Submit reply | Reply appears indented under parent comment | `[ ]` |
| 4.5 | Delete own comment | Comment removed; count decrements | `[ ]` |

**REST endpoints:** `POST /buddynext/v1/posts/{id}/comments`, `GET /buddynext/v1/posts/{id}/comments`, `DELETE /buddynext/v1/comments/{id}`
**Hook fired:** `buddynext_comment_created($comment_id, $post_id, $user_id)`

---

## 5. Bookmarks

| # | Action | Expected | Pass |
|---|--------|----------|------|
| 5.1 | Click bookmark icon on a post | Icon changes to filled/active state | `[ ]` |
| 5.2 | Reload page | Bookmark icon still shows active on that post | `[ ]` |
| 5.3 | Click bookmark icon again | Reverts to inactive; `SELECT * FROM wp_bn_bookmarks` row deleted | `[ ]` |

**REST endpoints:** `POST /buddynext/v1/posts/{id}/bookmark`, `DELETE /buddynext/v1/posts/{id}/bookmark`

---

## 6. Share

| # | Action | Expected | Pass |
|---|--------|----------|------|
| 6.1 | Click share on a post | Share confirmation (modal or inline) appears | `[ ]` |
| 6.2 | Confirm share | Share card appears in home feed with original post embedded | `[ ]` |
| 6.3 | Check DB | `wp_bn_shares` has a row; `wp_bn_posts` has a `type='share'` row linking to original | `[ ]` |

**REST endpoint:** `POST /buddynext/v1/posts/{id}/share`
**Hook fired:** `buddynext_post_created($post_id, $user_id, 'share')`

---

## 7. Follow / Unfollow

*Log in as Alice. Visit Bob's profile: http://forums.local/members/bob/?autologin=alice*

| # | Action | Expected | Pass |
|---|--------|----------|------|
| 7.1 | Click Follow on Bob's profile | Button changes to "Following"; Bob's follower count +1 | `[ ]` |
| 7.2 | Bob's posts appear in Alice's home feed | Navigate to /activity/ — Bob's posts now visible | `[ ]` |
| 7.3 | Click Unfollow | Button reverts to "Follow"; follower count -1 | `[ ]` |
| 7.4 | Check DB | `wp_bn_follows` row deleted after unfollow | `[ ]` |

**REST endpoints:** `POST /buddynext/v1/users/{id}/follow`, `DELETE /buddynext/v1/users/{id}/follow`
**Hook fired:** `buddynext_user_followed($follower_id, $following_id)`

---

## 8. Connection Request Flow

*Alice sends request to Bob. Bob accepts.*

| # | Action | Expected | Pass |
|---|--------|----------|------|
| 8.1 | Alice clicks Connect on Bob's profile | Button changes to "Pending" | `[ ]` |
| 8.2 | Bob opens notifications (/notifications/?autologin=bob) | Sees "Alice sent you a connection request" | `[ ]` |
| 8.3 | Bob clicks Accept | Both profiles show "Connected"; connection count +1 on both | `[ ]` |
| 8.4 | Alice withdraws request (before accept) | Button reverts to "Connect"; no DB row | `[ ]` |
| 8.5 | Bob declines request | Button reverts on Alice's side after next page load | `[ ]` |

**REST endpoints:** `POST /buddynext/v1/users/{id}/connect`, `PUT /buddynext/v1/connections/{id}/accept`, `DELETE /buddynext/v1/connections/{id}/decline`
**Hooks fired:** `buddynext_connection_requested`, `buddynext_connection_accepted`, `buddynext_connection_declined`

---

## 9. Block / Mute

*Log in as Alice. Block Bob.*

| # | Action | Expected | Pass |
|---|--------|----------|------|
| 9.1 | Alice opens Bob's profile → More Options → Block | Confirmation shown; Bob's profile unreachable from Alice | `[ ]` |
| 9.2 | Alice reloads home feed | Bob's posts no longer appear | `[ ]` |
| 9.3 | Bob tries to follow Alice | Silently rejected (REST 403 or 200 with no DB row) | `[ ]` |
| 9.4 | Alice unblocks Bob | Bob's posts reappear in feed | `[ ]` |
| 9.5 | Alice mutes Bob | Bob's posts hidden from feed; Bob gets no notification | `[ ]` |
| 9.6 | Alice unmutes Bob | Posts reappear | `[ ]` |

**REST endpoints:** `POST /buddynext/v1/users/{id}/block`, `DELETE /buddynext/v1/users/{id}/block`, `POST /buddynext/v1/users/{id}/mute`, `DELETE /buddynext/v1/users/{id}/mute`

---

## 10. Notifications

| # | Action | Expected | Pass |
|---|--------|----------|------|
| 10.1 | Bob follows Alice | Alice sees "Bob started following you" in /notifications/ | `[ ]` |
| 10.2 | Bob reacts to Alice's post | Alice sees reaction notification | `[ ]` |
| 10.3 | Bob comments on Alice's post | Alice sees comment notification | `[ ]` |
| 10.4 | 3+ same-type events within 24h | Grouped: "Bob and 2 others reacted to your post" | `[ ]` |
| 10.5 | Click "Mark all read" | Unread badge on nav clears to 0 | `[ ]` |
| 10.6 | Click individual notification | Navigates to the relevant post/profile | `[ ]` |

**REST endpoints:** `GET /buddynext/v1/notifications`, `PUT /buddynext/v1/notifications/mark-read`

---

## 11. Content Warning

| # | Action | Expected | Pass |
|---|--------|----------|------|
| 11.1 | Admin sets content warning on a post via PUT /posts/{id}/content-warning | Post card shows warning overlay before content | `[ ]` |
| 11.2 | Click "Show content" on overlay | Content revealed; overlay dismissed for this page load | `[ ]` |
| 11.3 | Reload page | Warning overlay appears again (not persisted client-side) | `[ ]` |

**REST endpoint:** `PUT /buddynext/v1/posts/{id}/content-warning` (admin only)

---

## 12. Space Actions

| # | Action | Expected | Pass |
|---|--------|----------|------|
| 12.1 | Browse /spaces/ → click Join on an open space | Button → "Member"; member count +1 | `[ ]` |
| 12.2 | Posts appear in Alice's home feed from joined space | Home feed includes space posts | `[ ]` |
| 12.3 | Click Leave space | Member count -1; space posts leave feed | `[ ]` |
| 12.4 | Click Request to Join on a private space | Button → "Pending"; owner sees request in space moderation | `[ ]` |
| 12.5 | Post inside a space feed | Post appears in space home and Alice's home feed | `[ ]` |

---

## 13. Search

| # | Action | Expected | Pass |
|---|--------|----------|------|
| 13.1 | Type a search term in global search | Results page at /search/?q=term shows posts + users + spaces | `[ ]` |
| 13.2 | Search for a blocked user | Blocked user does not appear in results | `[ ]` |
| 13.3 | Search for a suspended user | Suspended user does not appear in results | `[ ]` |

---

## 14. Hashtag Feed

| # | Action | Expected | Pass |
|---|--------|----------|------|
| 14.1 | Click a `#hashtag` link in a post | Navigates to /hashtag/{slug}/ feed | `[ ]` |
| 14.2 | Feed shows all public posts with that hashtag | Posts listed correctly | `[ ]` |
| 14.3 | Click Follow hashtag | Hashtag posts now appear in Alice's home feed | `[ ]` |

---

## 15. Dark Mode

| # | Action | Expected | Pass |
|---|--------|----------|------|
| 15.1 | Toggle `[data-theme="dark"]` on `<html>` via DevTools | All BuddyNext components switch to dark palette | `[ ]` |
| 15.2 | No element shows hardcoded white/light background | Inspect: no `background: #fff` or `color: #37352f` inline | `[ ]` |

---

## 16. Mobile 390px

| # | Action | Expected | Pass |
|---|--------|----------|------|
| 16.1 | DevTools → 390×844 → home feed | Single column; composer full-width; nav collapses to hamburger/bottom bar | `[ ]` |
| 16.2 | Post card at 390px | No horizontal scroll; reaction bar wraps correctly | `[ ]` |
| 16.3 | Profile at 390px | Avatar + stats stack vertically; follow/connect buttons full-width | `[ ]` |
| 16.4 | Spaces directory at 390px | Cards stack to single column | `[ ]` |
| 16.5 | Notifications at 390px | List items full-width; no overflow | `[ ]` |

---

## Checklist Reset

Before each QA run, reset all `[x]` back to `[ ]`:

```bash
sed -i '' 's/\[x\]/[ ]/g' docs/QA-FRONTEND-ACTIONS.md
```

---

## Known Excluded (out of scope for this checklist)

- Direct Messaging — owned by WPMediaVerse; test against WPMediaVerse QA doc
- Admin panel actions — covered by `QA-ADMIN-PANEL.md` (TODO)
- Gutenberg block editor — covered by `QA-BLOCKS.md` (TODO)
- Email delivery — requires live SMTP; test manually via Settings → Email → Send Test
