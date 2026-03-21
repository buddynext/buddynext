# BuddyNext — Career Board Bridge

**Status:** Locked
**Last updated:** 2026-03-19 (audit: hook names corrected against actual Career Board codebase)

---

## What It Does

Connects Career Board's job platform to BuddyNext's social layer. Career Board always owns job management; in BuddyNext mode it contributes job content into BuddyNext surfaces.

---

## What Career Board Always Owns

Job management, job applications, employer/candidate management, application workflows. These never defer.

---

## What Defers in BuddyNext Mode

| Career Board feature | Defers to |
|--------------------|----------|
| Notifications | BuddyNext bell (`bn_notifications`) |

---

## What the Bridge Does

**Content → BuddyNext Feed**
- `wcb_job_created` → `bn_posts` entry (type: `job`) — a job card in the feed
- `wcb_job_expired` → removes/archives the `bn_posts` entry

**Content → Search Index**
- Job published → `bn_search_index` entry (type: `job`)
- Job unpublished/deleted → removes from index

**Notifications**
- `wcb_application_submitted($app_id, $job_id, $candidate_id)` → notify employer (type: `cb.application_received`)
- `wcb_application_status_changed($app_id, $old_status, $new_status)` → notify candidate (type: `cb.application_status`)
- `wcb_application_withdrawn($app_id, $job_id, $candidate_id)` → notify employer (type: `cb.application_withdrawn`) — candidate withdrew their application

**Profile Integration**
- "Open to work" checkbox on BuddyNext profile → signals candidate availability in Career Board
- Work experience + skills fields from BuddyNext profile pre-populate Career Board candidate profile via `buddynext_profile_updated` hook

**Employer Profiles**
Link employer pages to BuddyNext profiles rather than maintaining duplicate profile data.

---

## Standalone Mode

Career Board runs its own notification system and listing pages when BuddyNext is not active. The bridge is not loaded.

---

## Integration Points

| Feature | Connection |
|---------|-----------|
| Activity Feed | Job cards appear in `bn_posts` |
| Notifications | Application events → `bn_notifications` |
| Search | Jobs indexed in `bn_search_index` |
| Profiles | "Open to work" flag + skills/experience cross-population |

---

## Gaps / Open Questions

- None — fully locked
