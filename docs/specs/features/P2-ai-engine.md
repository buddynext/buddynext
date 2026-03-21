# BuddyNext Pro — AI Engine

**Status:** Locked
**Last updated:** 2026-03-19

---

## What It Does

AI-powered features layered on top of the free social platform. Uses `bn_ai_signals` to store behavioral data that feeds personalization, moderation, and discovery.

---

## Feed Ranking

Free feed: chronological + connections-first.
Pro feed: AI-ranked by engagement signals — relationship strength, content affinity, recency, interaction history. Uses `bn_ai_signals` data. Personalized per user.

---

## Content Moderation

- Image + text toxicity scoring at submission time
- Configurable thresholds: warn user / flag for review / auto-reject
- Reduces manual moderation load
- Logged in moderation log

---

## Smart Notifications

- Notification fatigue detection — suppress low-signal notifications when user is overloaded
- Optimal send time — delay non-urgent emails to when the user is most likely to open

---

## Discovery

- "Spaces you might like" — based on interests + activity
- "People you might know" extended — relationship graph inference, not just mutual follows
- Trending topics in member's interest clusters (not just site-wide trending)

---

## Data Stored

`bn_ai_signals` — behavioral events (views, dwell time, reactions, clicks) used for personalization. Privacy: aggregated per user, not shared externally.

---

## Integration Points

| Feature | Connection |
|---------|-----------|
| Activity Feed | AI ranking replaces chronological |
| Moderation | AI scores supplement manual queue |
| Notifications | Fatigue detection + optimal timing |
| Search | Better relevance ranking |
| Spaces + Directory | Smarter suggestions |

---

## Gaps / Open Questions

- None — fully locked
