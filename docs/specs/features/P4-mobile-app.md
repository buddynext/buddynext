# BuddyNext Pro — Mobile App (React Native / Expo)

**Status:** Locked
**Last updated:** 2026-03-19

---

## What It Does

Native mobile app for iOS and Android — white-labelable so each site can publish their own branded app. Consumes BuddyNext REST API.

---

## Coverage

All free BuddyNext features in mobile:
- Home feed, profile feed, space feed, explore
- Spaces — browse, join, post
- Member directory + unified search
- User profiles
- Notifications (push + in-app bell)
- Direct messages
- Reactions + comments

Pro features available in app when Pro is active:
- AI-ranked feed
- WebSocket real-time (instant DM, push notifications)
- Membership gating UI

---

## Tech Stack

- React Native + Expo (managed workflow)
- Expo EAS Build for iOS + Android builds
- Expo Notifications for push notifications
- Connects to WordPress site via `buddynext/v1` REST API
- Auth: JWT token (WordPress JWT auth plugin or BuddyNext Pro JWT)

---

## White-Label

Admin panel for app configuration:
- App name, icon, splash screen, brand colors
- Domain (API endpoint)
- App Store / Play Store metadata fields

Site admin downloads pre-built app shell, configures via admin panel, submits to stores.

---

## Push Notifications

BuddyNext Pro manages push token registration (Expo Push Token stored per user).
All notification types that support "push" channel send via Expo Push Notifications Service.
User can opt out per notification type in mobile app settings.

---

## Offline Handling

- Cached feed (last loaded) visible offline
- Compose drafts saved locally when offline, sync on reconnect
- Clear "no connection" state — no silent failures

---

## Integration Points

| Feature | Connection |
|---------|-----------|
| REST API | All data via `buddynext/v1` + `buddynext-pro/v1` |
| Notifications | Push tokens via Expo; types from `bn_notifications` |
| Real-time | WebSocket Pro for instant DM + notification push |
| Membership | Gating enforced at API level, reflected in app UI |
| WPMediaVerse | Media upload via `mvs/v1` API |
| Jetonomy | Discussion access via `jetonomy/v1` API |

---

## Gaps / Open Questions

- None — fully locked
