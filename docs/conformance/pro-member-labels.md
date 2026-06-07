# Conformance: Member Labels (Pro)

**Feature:** Member Labels — admin-defined editorial badges ("Verified", "Expert", "Staff") created in wp-admin, assigned to users via REST/admin, surfaced on member profiles, post bylines, and the profile REST payload.

**Repo:** buddynext-pro

**Spec ref:** `/Users/vapvarun/dev/repos/buddynext/docs/specs/features/05-user-profiles.md` (profile extension surface) + journey `/Users/vapvarun/dev/repos/buddynext-pro/docs/journeys/member-labels.md`

**Verdict:** usable-leave-as-is

---

## Verdict rationale

The full happy path — define a label in the admin UI, assign it to a member, see it on the profile and in the profile REST payload, edit it, unassign it, delete it — is implemented and wired end-to-end. Every layer (UI control → handler/REST → service → DB) is present and connected. Free fires all three injector seams the Pro code subscribes to. The admin confirm dialog dependency (`bn-admin-dialogs.js`) is enqueued site-wide on BN admin pages, so the delete confirmation works.

The journey doc is **stale relative to the code** (it still references the deprecated `buddynext_profile_extra_data` filter and claims frontend badge display is "deferred"). The actual code is *more* complete than the doc: `ProfileLabelInjector` renders label chips into the profile hero and post byline AND answers the `buddynext_profile_labels` REST seam. This is doc-lag, not a journey break — no refactor required.

---

## Journey chain

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Admin opens Member Labels page (`?page=buddynextpro-member-labels`) | ui | wired | `buddynext-pro/includes/Admin/MemberLabelsAdmin.php:123-133` (submenu under `buddynext`), `:288-312` (render) |
| 2 | Admin submits "Add Label" form | ui | wired | `MemberLabelsAdmin.php:429-477` (form, fields `#bnpro_label_slug` etc.), nonce `:432` |
| 3 | Add handler validates + inserts | service/db | wired | `MemberLabelsAdmin.php:144-188` → `LabelService::create_label()` `LabelService.php:56-103` (insert into `bn_member_labels`, slug-unique check `:68`) |
| 4 | Label tables exist with correct schema | db | wired | `buddynext-pro/includes/Core/Installer.php:459-481` (`bn_member_labels` UNIQUE `slug`; `bn_member_label_assignments` UNIQUE `user_label`) |
| 5 | Label row renders with swatch / icon / member count | ui | wired | `MemberLabelsAdmin.php:320-358`; count via `LabelService::get_member_count()` `LabelService.php:298-311` |
| 6 | Assign label to user (REST POST) | rest/service/db | wired | `LabelsController.php:155-170` route, `:361-400` handler → `LabelAssignmentService::assign_label()` `LabelAssignmentService.php:54-94` |
| 7 | Write endpoints gated to admins | rest | wired | `LabelsController::require_manage_options()` `LabelsController.php:454-463`; applied on create/update/delete/assign/unassign |
| 8 | Label appears in profile REST payload | rest/service | wired | Free fires `apply_filters('buddynext_profile_labels', [], $uid)` at `buddynext/includes/Profile/ProfileService.php:743`; Pro answers via `ProfileLabelInjector::rest_labels()` `ProfileLabelInjector.php:131-150` (registered `:76`) |
| 9 | Label chip renders on profile hero (web) | ui/service | wired | Free fires `do_action('buddynext_part_profile_hero_after', $args)` with `profile_user_id` at `buddynext/templates/parts/profile-hero.php:545` / arg bundle `:66`; Pro renders chips `ProfileLabelInjector::render_chips_from_hero()` `:100-116` |
| 10 | Label chip renders on post byline (web) | ui/service | wired | Free fires `do_action('buddynext_part_post_byline_after', $args)` with `author_id` at `buddynext/templates/parts/post-byline.php:236` / `:56`; Pro renders `render_chips_from_part()` `:196-212` |
| 11 | Edit label (admin inline `<details>` form) | ui/service/db | wired | `MemberLabelsAdmin.php:366-398` form → `handle_edit()` `:197-243` → `LabelService::update_label()` `LabelService.php:118-170` |
| 12 | Unassign label (REST DELETE) | rest/service/db | wired | `LabelsController.php:164-167`, `:410-440` → `LabelAssignmentService::unassign_label()` `LabelAssignmentService.php:107-140` |
| 13 | Delete label (admin, with confirm dialog) | ui/service/db | wired | `MemberLabelsAdmin.php:406-422` (`data-bn-confirm` form) → `handle_delete()` `:254-277` purges assignments then deletes; dialog JS enqueued site-wide on BN admin `buddynext/includes/Core/AssetService.php:130-136`, gate `:86` |

---

## First break

none — journey complete.

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| No web/admin UI control to *assign* a label to a member — assignment is REST-only (the journey itself assigns via `curl`). A site owner cannot click a member in wp-admin and toggle a label; they must call the REST endpoint. Fine for app/REST clients and scripted assignment; a gap for the pure point-and-click web admin. | low | confirmed-in-code | Assign/unassign exist only as REST routes `LabelsController.php:155-170`; `MemberLabelsAdmin.php` renders label CRUD only, with no member-row assignment toggle. |
| Journey doc is stale: references deprecated `buddynext_profile_extra_data` seam and states frontend badge display is "deferred". Code has moved past this. Not a usability break (display works); a documentation accuracy gap. | low | confirmed-in-code | Doc lines 88, 155, 206 vs actual seams `ProfileLabelInjector.php:76-80` and Free `ProfileService.php:743`. |

Neither gap stops the documented journey, which uses REST for assignment and confirms display via the profile API and rendered chips.

---

## Minimal refactor plan

EMPTY — usable, leave as is.

(Optional, out of scope for this verdict: an admin member-row "assign label" control would close the low-severity web-admin gap, and the journey doc should be refreshed to the current `buddynext_profile_labels` / hero-part seams. Neither is required for the journey to be usable.)

---

## Live-walk URL

http://buddynext-dev.local/members

(Admin surface to walk first: `…/wp-admin/admin.php?page=buddynextpro-member-labels`. Then view any member profile to confirm the chip renders in the hero. Seed at least one label + one assignment before walking — empty accounts hide the rendered chips.)
