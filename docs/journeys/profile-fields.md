# Journey: Profile Fields

**Free feature**: `includes/Profile/` (ProfileService, ProfileController)
**Actions / filters fired**: `buddynext_member_updated`, `buddynext_profile_completion_changed`, `buddynext_profile_field_types` (filter), `buddynext_profile_extra_data` (filter)
**DB tables touched**: `bn_profile_groups`, `bn_profile_fields`, `bn_profile_values`
**Estimated time**: 10 min manual

## Preconditions

- BuddyNext Free active on http://buddynext-dev.local/ (LocalWP dev site)
- Test data: `member1` user exists; default profile groups/fields seeded at activation (Basic Info, Social Links, Work Experience, Education, Skills)
- Admin user (autologin: append `?autologin=1` to any admin URL — the mu-plugin at `mu-plugins/00-autologin.php` handles it)
- Member users: `member1` / `password`, `member2` / `password`

## Happy-path steps

### Part 1: Admin creates a profile field group and fields

1. Log in as admin. Confirm the default field groups are seeded:

   ```sql
   SELECT id, group_key, label, type, visibility, is_system, sort_order
   FROM wp_bn_profile_groups
   ORDER BY sort_order;
   ```

   - Expected: 5 rows (`basic_info`, `social_links`, `work_experience`, `education`, `skills`), all with `is_system = 1`.

2. Create a custom field group via REST (admin capability required):

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/profile-groups \
     -u admin:password \
     -H "Content-Type: application/json" \
     -d '{
       "group_key": "professional_info",
       "label": "Professional Info",
       "type": "flat",
       "visibility": "public",
       "sort_order": 10
     }'
   ```

   - Expected: 201 or 200. Note the returned group `id` (referred to as `GROUP_ID`).

   **Note**: `POST /buddynext/v1/profile-groups` (and `POST /buddynext/v1/profile-fields`) are implemented (admin only — `ProfileController::create_group()` / `create_field()`). The WP-CLI insert below is only a fallback for scripting outside an authenticated REST session:

   ```bash
   wp db query "INSERT INTO wp_bn_profile_groups (group_key, label, type, visibility, is_system, sort_order) VALUES ('professional_info', 'Professional Info', 'flat', 'public', 0, 10);"
   wp db query "SELECT id FROM wp_bn_profile_groups WHERE group_key = 'professional_info';"
   ```

3. Verify the new group:

   ```sql
   SELECT id, group_key, label, type, visibility, is_system, sort_order
   FROM wp_bn_profile_groups
   WHERE group_key = 'professional_info';
   ```

   - Expected: 1 row, `is_system = 0`, `sort_order = 10`.

4. List all field groups via REST (public endpoint):

   ```bash
   curl -s http://buddynext-dev.local/wp-json/buddynext/v1/profile-groups
   ```

   - Expected: 200, array including the new `professional_info` group.

5. Create a custom profile field in the new group. Use `PUT /buddynext/v1/profile-groups/{id}` is the update route; to create a field, insert directly via WP-CLI since a field-create REST endpoint is not in the manifest:

   ```bash
   wp db query "INSERT INTO wp_bn_profile_fields (group_id, field_key, label, type, is_required, is_searchable, visibility, sort_order) VALUES (GROUP_ID, 'years_of_experience', 'Years of Experience', 'number', 0, 0, 'public', 1);"
   wp db query "SELECT id FROM wp_bn_profile_fields WHERE field_key = 'years_of_experience';"
   ```

   Note the field `id` (referred to as `FIELD_ID`).

6. Verify the field row:

   ```sql
   SELECT id, group_id, field_key, label, type, is_required, is_searchable, visibility
   FROM wp_bn_profile_fields
   WHERE field_key = 'years_of_experience';
   ```

   - Expected: 1 row, `type = number`.

### Part 2: Member edits profile and fills fields

7. As `member1`, list all profile fields:

   ```bash
   curl -s http://buddynext-dev.local/wp-json/buddynext/v1/profile-fields
   ```

   - Expected: 200, array including `years_of_experience` field.

8. As `member1`, update their profile (fill the `bio` field from the seeded `basic_info` group and the new `years_of_experience` field):

   ```bash
   # Get member1's user ID:
   wp user get member1 --field=ID

   curl -s -X PUT http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER1_ID/profile \
     -u member1:password \
     -H "Content-Type: application/json" \
     -d '{
       "fields": {
         "bio": "I am a developer and community builder.",
         "years_of_experience": "7"
       }
     }'
   ```

   - Expected: 200. Profile values saved.

9. Verify the profile values in the DB:

   ```sql
   SELECT pv.id, pv.user_id, pf.field_key, pv.value, pv.entry_visibility
   FROM wp_bn_profile_values pv
   INNER JOIN wp_bn_profile_fields pf ON pf.id = pv.field_id
   WHERE pv.user_id = MEMBER1_ID
   ORDER BY pf.field_key;
   ```

   - Expected: rows for `bio` and `years_of_experience` with their values.

10. Retrieve member1's public profile:

    ```bash
    curl -s http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER1_ID/profile
    ```

    - Expected: 200. `profile_fields` array in the response includes `bio` and `years_of_experience`.

### Part 3: Visibility filter (public / followers / private)

11. Update the visibility of the `years_of_experience` field to `followers` so only followers see it:

    ```bash
    wp db query "UPDATE wp_bn_profile_fields SET visibility = 'followers' WHERE field_key = 'years_of_experience';"
    ```

12. Fetch member1's profile as an anonymous (unauthenticated) viewer:

    ```bash
    curl -s http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER1_ID/profile
    ```

    - Expected: 200. `years_of_experience` field is absent from the response (visibility gate enforced by `ProfileService`).

13. Fetch member1's profile as `member2` (who does not follow member1):

    ```bash
    curl -s http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER1_ID/profile \
      -u member2:password
    ```

    - Expected: 200. `years_of_experience` still absent.

14. Have `member2` follow `member1`:

    ```bash
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER1_ID/follow \
      -u member2:password -H "Content-Type: application/json"
    ```

    Then re-fetch member1's profile as `member2`. Expected: `years_of_experience` now visible in the response.

## Edge cases to also verify

- **Required field validation**: Mark `years_of_experience` as `is_required = 1`. Then update profile as member1 without providing that field. Expected: 422 or 400 — required field missing.
- **Private field**: Set field visibility to `private`. Fetch profile as any other user including admin. Expected: field absent from response.
- **Per-entry visibility**: The `bn_profile_values.entry_visibility` column allows an individual entry to override the field-level visibility. Set `entry_visibility = 'private'` on a specific value row via WP-CLI, then confirm the entry is hidden even though the field is `public`.
- **Reorder group**: Call `POST /buddynext/v1/profile-groups/{id}/reorder` with `{"sort_order": 99}`. Expected: group moved to last position in the `GET /profile-groups` response.
- **Field type enforcement**: Attempt to save a non-numeric value for a `number` type field. Expected: 422.

## What this validates

- `ProfileService` reads / writes `bn_profile_values` per user per field.
- `ProfileController::list_groups()` returns groups ordered by `sort_order`.
- `ProfileController::list_fields()` returns all fields with their group.
- `ProfileController::get_profile()` applies visibility filtering based on viewer relationship (anonymous / follower / connection / self).
- `ProfileController::update_profile()` performs upsert on `bn_profile_values` (UNIQUE KEY `user_field_entry`).
- `buddynext_profile_field_types` filter exposes the allowed type list.

## Verification queries

```sql
-- All profile groups:
SELECT id, group_key, label, type, visibility, is_system, sort_order
FROM wp_bn_profile_groups
ORDER BY sort_order;

-- Fields for the custom group:
SELECT id, field_key, label, type, visibility, is_required, is_searchable
FROM wp_bn_profile_fields
WHERE group_id = GROUP_ID;

-- Profile values for member1:
SELECT pv.id, pf.field_key, pv.value, pv.entry_index, pv.entry_visibility
FROM wp_bn_profile_values pv
INNER JOIN wp_bn_profile_fields pf ON pf.id = pv.field_id
WHERE pv.user_id = MEMBER1_ID
ORDER BY pf.field_key;
```

## REST surface walked

```
GET  /buddynext/v1/profile-groups                    -- 200, all groups (public)
PUT  /buddynext/v1/profile-groups/{id}               -- 200, updated group (admin)
POST /buddynext/v1/profile-groups/{id}/reorder       -- 200, group reordered (admin)
GET  /buddynext/v1/profile-fields                    -- 200, all field definitions (public)
PUT  /buddynext/v1/profile-fields/{id}               -- 200, updated field (admin)
POST /buddynext/v1/profile-fields/{id}/reorder       -- 200, field reordered (admin)
GET  /buddynext/v1/users/{id}/profile                -- 200, user profile with visibility-filtered fields
PUT  /buddynext/v1/users/{id}/profile                -- 200, profile updated (self)
GET  /buddynext/v1/me/profile                        -- 200, own profile (all fields visible)
```

## Cleanup

```sql
-- Remove custom field values:
DELETE FROM wp_bn_profile_values
WHERE field_id IN (SELECT id FROM wp_bn_profile_fields WHERE group_id = GROUP_ID);

-- Remove custom fields:
DELETE FROM wp_bn_profile_fields WHERE group_id = GROUP_ID;

-- Remove custom group:
DELETE FROM wp_bn_profile_groups WHERE group_key = 'professional_info';

-- Remove member1's profile values for bio (restore to blank):
DELETE FROM wp_bn_profile_values
WHERE user_id = MEMBER1_ID
  AND field_id IN (SELECT id FROM wp_bn_profile_fields WHERE field_key IN ('bio', 'years_of_experience'));
```

## Known limitations

- `POST /buddynext/v1/profile-groups` and `POST /buddynext/v1/profile-fields` are implemented (admin-only, via `ProfileController::create_group()` / `create_field()`); the WP-CLI DB insert is only a scripting fallback, not a requirement.
- Advanced Pro field types (`date_extended`, `location`, `file`, `multi_select_advanced`, `number_advanced`, `conditional`) are registered by Pro via `buddynext_profile_field_types` filter but render as no-op until Free fires the `buddynext_profile_field_render` seam.

## Automation notes

- Profile field creation is not fully automatable via REST; use WP-CLI for the group/field schema setup phase.
- Profile value updates via `PUT /users/{id}/profile` are fully curl-automatable.
- Visibility tests require setting up a follow relationship first (see `social-graph.md`).
