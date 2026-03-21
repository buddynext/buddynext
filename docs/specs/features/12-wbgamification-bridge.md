# BuddyNext — WBGamification Bridge

**Status:** Locked
**Last updated:** 2026-03-19

---

## What It Does

Connects WBGamification's event-sourced engine to BuddyNext's social actions. BuddyNext fires actions — WBGamificationBridge translates them into WBGam events so the rules engine can award points, badges, and levels.

---

## Modes

### Standalone (WBGamification without BuddyNext)
WBGamification listens to WordPress core actions (`publish_post`, `comment_post`) and BuddyPress actions if active.

### BuddyNext Mode
WBGamificationBridge extends the event source list — WBGam now also listens to `buddynext_*` actions. No deferral needed (WBGam doesn't own social features).

---

## Event Mapping

BuddyNext fires → WBGamificationBridge translates → WBGam event created:

| BuddyNext Action | WBGam Event Type | Example Award |
|-----------------|-----------------|---------------|
| `buddynext_user_followed` (being followed) | `bn_followed` | 5 pts for gaining a follower |
| `buddynext_connection_accepted` | `bn_connected` | 10 pts for new connection |
| `buddynext_post_created` | `bn_post_created` | 5 pts per post |
| `buddynext_reaction_added` (on own content) | `bn_reaction_received` | 2 pts per reaction received |
| `buddynext_comment_created` | `bn_comment_created` | 3 pts per comment |
| `buddynext_member_joined_space` | `bn_space_joined` | 5 pts for joining a space |
| `buddynext_onboarding_complete` | `bn_onboarding_complete` | "Welcome" badge |
| `buddynext_profile_updated` (completion milestone) | `bn_profile_completed` | "Complete Profile" badge |

All mappings are WBGam rule configurations — not hard-coded. Admin can adjust points or disable any rule.

---

## UI Integration

WBGamificationBridge injects WBGam data into BuddyNext surfaces:

- **Profile**: Points balance, level, badges grid (via `buddynext_profile_extra_data` filter)
- **Member Directory**: Points badge on member cards (optional, admin toggle)
- **Space pages**: Leaderboard widget in sidebar (via `buddynext_space_tabs` or widget)
- **Notifications**: Badge earned + level up appear in BuddyNext bell (`wb.badge_earned`, `wb.level_up`)

---

## Points for Moderation Events

- Strike issued → deduct points (configurable amount)
- Account suspended → points frozen

---

## Integration Points

| Feature | Connection |
|---------|-----------|
| Social Graph | Follow/connect actions → points events |
| Activity Feed | Post created → points event |
| Reactions + Comments | Reactions/comments received → points events |
| Spaces | Space join → points event |
| Profiles | Completion → badge award |
| Notifications | WBGam events surfaced in BuddyNext bell |
| Moderation | Strikes → point deduction |

---

## Gaps / Open Questions

- None — fully locked
