# BuddyNext Pro — Advanced Analytics

**Status:** Locked
**Last updated:** 2026-03-19

---

## What It Does

Data-driven insights for site admins and space moderators. Understand community health, member engagement, content performance, and growth trends.

---

## Site-level Analytics (Admin)

- Member growth (new registrations over time, retention curve)
- Active members (DAU / WAU / MAU)
- Feed engagement (post volume, reaction rate, comment rate, share rate)
- Top content (most-reacted posts, most-commented)
- Top members (most followers, most posts, most engaged)
- Space health (member count over time, post activity per space)
- Notification open rates (email opens, click-through)
- Churn signals (members going inactive)

---

## Space-level Analytics (Moderators)

- Member count growth
- Post engagement within the space
- Most active members
- Join / leave rate

---

## Member-level Analytics (Self-only)

- Profile views (who viewed, when)
- Post reach (impressions, engagement rate)
- Follower growth
- Connection history

---

## Data Collection

Events logged to `bn_analytics_events` (lightweight event stream). Aggregates computed async via Action Scheduler — no heavy queries on page load.

Privacy: aggregate only for site/space analytics. Individual member analytics shown only to the member themselves (no admin spying on individual behavior).

---

## Data Stored

`bn_analytics_events` — event stream (type, actor_id, object_type, object_id, timestamp)

Aggregates stored in separate summary tables (computed by cron jobs).

---

## Integration Points

| Feature | Connection |
|---------|-----------|
| AI Engine | Analytics events feed AI signals |
| Spaces | Space moderators get space-scoped analytics tab |
| Admin Settings | Site-wide analytics in admin panel |
| Email | Digest emails can include "your community this week" summary |
| Action Scheduler | Aggregate computation runs async |

---

## Gaps / Open Questions

- None — fully locked
