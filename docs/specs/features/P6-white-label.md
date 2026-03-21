# BuddyNext Pro — White-label

**Status:** Locked
**Last updated:** 2026-03-19

---

## What It Does

Remove BuddyNext branding from all user-facing surfaces. Agencies and SaaS platforms using BuddyNext as their community engine can ship their own branded product.

---

## Scope

| Surface | White-labeled |
|---------|--------------|
| Plugin name in admin | Renamed to custom name |
| "Powered by BuddyNext" footer | Removed |
| Admin panel branding | Custom logo + name |
| Email template footers | Custom branding, no BuddyNext mention |
| Gutenberg blocks label | Blocks renamed in editor |
| Error messages + UI copy | Translatable, no hardcoded "BuddyNext" references |
| REST API namespace | Optionally remapped to custom namespace |
| Mobile app | Custom app name, icon, splash (see P4-mobile-app.md) |

---

## Configuration

Admin panel: White-label settings tab (Pro only).
- Custom product name
- Custom logo
- Hide "BuddyNext" from all strings
- Optional REST namespace alias

---

## What Is Not White-labeled

- WordPress admin plugin list still shows "BuddyNext" (WordPress requirement for licensed plugins)
- License key validation still pings BuddyNext license server
- Debug/error logs internally still reference BuddyNext (developer-facing only)

---

## Integration Points

| Feature | Connection |
|---------|-----------|
| Admin Settings | White-label settings tab |
| Email System | Custom branding in all emails |
| Mobile App | Custom app identity |
| Gutenberg Blocks | Renamed in block editor |

---

## Gaps / Open Questions

- None — fully locked
