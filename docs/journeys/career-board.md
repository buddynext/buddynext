# Journey: Career Board (jobs) bridge

**Bridge feature** (opt-in): `includes/Bridges/CareerBoardBridge.php` — registered in `FeatureRegistry` as `career_board` (`tier = opt-in`, `group = bridges`, `depends_on = ['feed']`).
**Requires**: the **Career Board** plugin (`career-board/career-board.php`) active. The bridge is **inert** unless `function_exists('wcb_get_job')` **and** `class_exists('WCB_Career_Board')` both pass.
**Partner hooks consumed**: `wcb_job_created`, `wcb_job_expired`, `wcb_application_submitted`, `wcb_application_status_changed`, `wcb_application_withdrawn`
**BN services called**: `SearchService::index()`, `NotificationService::create()`
**Notification types fired**: `cb.application_received`, `cb.application_status`, `cb.application_withdrawn`
**DB tables touched**: `bn_search_index` (job rows, `object_type = 'job'`), `bn_posts` (delete of `type = 'job_post'` rows on expiry), `bn_notifications` (via NotificationService)
**Estimated time**: 8 min manual

> **Site-owner expectation**
>
> A community owner activates the Career Board plugin alongside BuddyNext and turns on the opt-in `career_board` bridge. Their expectation is **plug-and-play**: job posts created in Career Board should automatically surface inside the BuddyNext community — i.e. a published job becomes discoverable through community search (it lands in `bn_search_index` as a `job` object), and the people involved in an application get BuddyNext notifications (employer on apply/withdraw, candidate on status change). No extra configuration, no manual sync. They do **not** need to know the bridge exists — they activate two plugins and jobs "just show up" in the community search surface. **Note:** this bridge does **not** currently surface jobs as community hashtags or as a profile/nav rail item — it is a search-index + notification bridge only.

## Preconditions

- BuddyNext Free active on http://buddynext-dev.local/ (LocalWP dev site).
- The `career_board` bridge feature is enabled (opt-in). The `feed` feature must be active (declared dependency).
- **Career Board plugin active.** On the stock dev site it is most likely **not installed/active** — in that state the bridge is inert and the only thing to verify is the no-fatal guard (see Edge case 1). The happy-path steps below require the partner plugin to be present and providing the `wcb_*` action hooks and `wcb_get_job()`/`WCB_Career_Board`.
- Admin user (autologin: append `?autologin=1` to any admin URL — the mu-plugin at `mu-plugins/00-autologin.php` handles it).
- Member users: `member1` / `password`, `member2` / `password`. For the happy path, treat `member1` as the **employer** (job author) and `member2` as the **candidate**.

> All `wp` commands assume LocalWP's site shell (right-click the site → "Open Site Shell"). No `wp-env` prefix.

## Happy-path steps

> These steps drive the **partner** plugin (Career Board owns the job/application data model) and then confirm the **bridge** reacted. Because the bridge listens on partner actions, the cleanest way to exercise it deterministically is to fire the partner hooks with `wp eval` using known IDs, then assert the BN-side write. Where the live Career Board UI is available, creating a real job / submitting a real application is equivalent.

### Part 1: Publish a job → bridge indexes it into search

1. Confirm the bridge is live (partner active, guard passed):

   ```bash
   wp eval 'var_dump( function_exists("wcb_get_job") && class_exists("WCB_Career_Board") );'
   ```

   - Expected: `bool(true)`. If `false`, the partner is inactive — skip to Edge case 1.

2. Simulate a job publish by firing the partner hook the bridge listens on (`wcb_job_created($job_id, $job_data, $user_id)`, 3 args). Use a real Career Board job post ID if you have one; otherwise pick an unused high ID for the index assertion:

   ```bash
   wp eval '
     $employer_id = (int) ( new WP_User( "member1" ) )->ID;
     do_action(
       "wcb_job_created",
       909001,
       array( "title" => "Senior Community Engineer", "description" => "Build #buddynext communities. Remote." ),
       $employer_id
     );
     echo "fired\n";
   '
   ```

   - Expected: `fired`. The bridge's `on_job_created()` calls `SearchService::index('job', 909001, $title, $description, $employer_id)`.

3. Verify the job landed in the search index:

   ```sql
   SELECT object_type, object_id, title, author_id, visibility
   FROM wp_bn_search_index
   WHERE object_type = 'job' AND object_id = 909001;
   ```

   - Expected: 1 row, `object_type = job`, `title = Senior Community Engineer`, `author_id = member1's ID`, `visibility = public` (SearchService default). The job is now discoverable via community search.

4. Confirm the job is reachable through the search service (the site-owner-facing outcome):

   ```bash
   wp eval '
     $r = ( new BuddyNext\Search\SearchService() )->grouped_search( "Community Engineer", 0, 5 );
     echo wp_json_encode( $r ) . "\n";
   '
   ```

   - Expected: a `job` group containing object_id `909001` (FULLTEXT on prod tables; LIKE fallback in test envs — both should match this title).

### Part 2: Application submitted → employer notified

5. Fire the application-submitted partner hook (`wcb_application_submitted($app_id, $job_id, $candidate_id)`, 3 args). The bridge resolves the employer from `post_author` of the job post, so this assertion needs a **real** Career Board job post whose `post_author` is `member1`. Substitute a real `JOB_ID`:

   ```bash
   wp eval '
     $candidate_id = (int) ( new WP_User( "member2" ) )->ID;
     do_action( "wcb_application_submitted", 5001, JOB_ID, $candidate_id );
     echo "fired\n";
   '
   ```

   - Expected: `fired`. `on_application_submitted()` reads `get_post_field('post_author', JOB_ID)`; if the author resolves to `0` it bails (no notification). With a valid employer it calls `NotificationService::create()` with `type = cb.application_received`, `object_type = job`, `object_id = JOB_ID`, `group_key = cb_app_{JOB_ID}_{employer_id}`.

6. Verify the employer notification row:

   ```sql
   SELECT recipient_id, sender_id, type, object_type, object_id
   FROM wp_bn_notifications
   WHERE type = 'cb.application_received' AND object_id = JOB_ID
   ORDER BY id DESC LIMIT 1;
   ```

   - Expected: 1 row, `recipient_id = member1's ID` (employer), `sender_id = member2's ID` (candidate).

### Part 3: Application status changed → candidate notified

7. Fire the status-changed hook (`wcb_application_status_changed($app_id, $old_status, $new_status, $candidate_id)`, 4 args):

   ```bash
   wp eval '
     $candidate_id = (int) ( new WP_User( "member2" ) )->ID;
     do_action( "wcb_application_status_changed", 5001, "submitted", "shortlisted", $candidate_id );
     echo "fired\n";
   '
   ```

8. Verify the candidate notification row:

   ```sql
   SELECT recipient_id, type, object_type, object_id, data
   FROM wp_bn_notifications
   WHERE type = 'cb.application_status' AND object_id = 5001
   ORDER BY id DESC LIMIT 1;
   ```

   - Expected: 1 row, `recipient_id = member2's ID`, `object_type = application`, `object_id = 5001 (app_id)`, `data` JSON contains `old_status = submitted`, `new_status = shortlisted`.

### Part 4: Application withdrawn → employer notified

9. Fire the withdrawn hook (`wcb_application_withdrawn($app_id, $job_id, $candidate_id, $employer_id)`, 4 args). Here the employer ID is passed explicitly (no `post_author` lookup):

   ```bash
   wp eval '
     $candidate_id = (int) ( new WP_User( "member2" ) )->ID;
     $employer_id  = (int) ( new WP_User( "member1" ) )->ID;
     do_action( "wcb_application_withdrawn", 5001, JOB_ID, $candidate_id, $employer_id );
     echo "fired\n";
   '
   ```

   - Expected: `fired`. `on_application_withdrawn()` bails if `$employer_id === 0`; otherwise creates `type = cb.application_withdrawn`.

10. Verify:

    ```sql
    SELECT recipient_id, sender_id, type, object_id
    FROM wp_bn_notifications
    WHERE type = 'cb.application_withdrawn' AND object_id = JOB_ID
    ORDER BY id DESC LIMIT 1;
    ```

    - Expected: 1 row, `recipient_id = member1's ID`, `sender_id = member2's ID`.

### Part 5: Job expires → feed card removed

11. Fire the expiry hook (`wcb_job_expired($job_id)`, 1 arg). The bridge resolves `get_permalink($job_id)` and deletes the `bn_posts` row where `type = 'job_post'` AND `link_url = <permalink>`. This requires a real job post whose permalink matches a previously-created `job_post` feed card. Substitute `JOB_ID`:

    ```bash
    wp eval 'do_action( "wcb_job_expired", JOB_ID ); echo "fired\n";'
    ```

    - Expected: `fired`. If `get_permalink(JOB_ID)` is falsy the bridge returns early (no delete).

12. Verify the feed card is gone:

    ```sql
    SELECT id, type, link_url
    FROM wp_bn_posts
    WHERE type = 'job_post' AND link_url = '<job permalink>';
    ```

    - Expected: 0 rows. (Note: the bridge does **not** remove the `bn_search_index` job row on expiry — see Known limitations.)

## Edge cases to also verify

- **Bridge inert when partner inactive (no fatals).** With the Career Board plugin **deactivated**, load BuddyNext and confirm the front end + WP-CLI run clean. `CareerBoardBridge::init()` returns immediately at the `function_exists('wcb_get_job') / class_exists('WCB_Career_Board')` guard, so none of the `wcb_*` listeners are registered.

  ```bash
  wp eval '( new BuddyNext\Bridges\CareerBoardBridge() )->init(); echo "no fatal\n";'
  wp eval 'var_dump( has_action( "wcb_job_created" ) );'
  ```

  - Expected: `no fatal`; `has_action('wcb_job_created')` is `false` (or no BN callback attached). Firing `do_action('wcb_job_created', ...)` with the partner inactive writes **no** `bn_search_index` row.

- **Job indexing is idempotent.** Re-fire `wcb_job_created` for the same `object_id` (909001) with a changed title. `SearchService::index()` uses `INSERT ... ON DUPLICATE KEY UPDATE`, so the existing row is updated in place (title/content/`updated_at` refreshed), not duplicated.

  ```bash
  wp eval '
    $employer_id = (int) ( new WP_User( "member1" ) )->ID;
    do_action( "wcb_job_created", 909001, array( "title" => "Staff Community Engineer", "description" => "Updated." ), $employer_id );
    echo "re-fired\n";
  '
  ```

  ```sql
  SELECT COUNT(*) AS rows_for_job, MAX(title) AS title
  FROM wp_bn_search_index WHERE object_type = 'job' AND object_id = 909001;
  ```

  - Expected: `rows_for_job = 1`, `title = Staff Community Engineer`.

- **Employer unresolved → no notification.** Fire `wcb_application_submitted` with a `JOB_ID` whose `post_author` is `0` (or a non-existent post). `on_application_submitted()` bails and writes no row.

  - Expected: 0 new `cb.application_received` rows.

## What this validates

- **Self-gating guard.** `CareerBoardBridge::init()` registers nothing unless both `function_exists('wcb_get_job')` and `class_exists('WCB_Career_Board')` pass — the bridge is safe to load with the partner absent (matches the `plugins_loaded` priority-25 `buddynext_load_bridges` pattern in `Plugin.php`).
- **Search seam.** `on_job_created()` → `SearchService::index('job', $job_id, $title, $description, $user_id)` writes a `job` row into `bn_search_index` (idempotent upsert, default `visibility = public`, `space_id = 0`). This is the site-owner-facing "jobs appear in community search" behavior.
- **Notification seam (×3).** `on_application_submitted()` (employer, `cb.application_received`, employer resolved from job `post_author`), `on_application_status_changed()` (candidate, `cb.application_status`), `on_application_withdrawn()` (employer, `cb.application_withdrawn`, employer passed in) each call `NotificationService::create()`.
- **Feed cleanup seam.** `on_job_expired()` deletes the `bn_posts` row of `type = 'job_post'` matched by the job permalink.
- **No nav / profile injection.** The bridge attaches **no** `buddynext_rail_items` or `buddynext_profile_extra_data` filters and registers **no** REST routes of its own. It is a one-way listener: partner action → BN write.

## Verification queries

```sql
-- Job rows the bridge indexed:
SELECT object_type, object_id, title, author_id, visibility, updated_at
FROM wp_bn_search_index
WHERE object_type = 'job'
ORDER BY updated_at DESC;

-- All Career Board bridge notifications:
SELECT id, recipient_id, sender_id, type, object_type, object_id, data, created_at
FROM wp_bn_notifications
WHERE type IN ('cb.application_received', 'cb.application_status', 'cb.application_withdrawn')
ORDER BY id DESC;

-- Job feed cards (removed on expiry):
SELECT id, type, link_url
FROM wp_bn_posts
WHERE type = 'job_post';
```

## REST / observability surface walked

The bridge exposes **no REST routes of its own** — it is a server-side listener. Observability is through the surfaces its writes feed into:

```
GET  /buddynext/v1/search?q=<term>      -- indexed job rows appear in the 'job' group (community search)
GET  /buddynext/v1/notifications        -- cb.application_* rows appear in the recipient's notification list
```

- The `job` object type is discovered dynamically by `SearchService::grouped_search()` from the index table, so jobs surface in search results without any search-side registration.
- Partner-owned surfaces (the actual job listing pages, application UI) live in the **Career Board** plugin, not BuddyNext.

## Cleanup

```sql
-- Remove indexed test job rows:
DELETE FROM wp_bn_search_index
WHERE object_type = 'job' AND object_id IN (909001);

-- Remove bridge test notifications:
DELETE FROM wp_bn_notifications
WHERE type IN ('cb.application_received', 'cb.application_status', 'cb.application_withdrawn')
  AND object_id IN (5001, JOB_ID);

-- Remove any test job feed card (substitute the real permalink if Part 5 created one):
-- DELETE FROM wp_bn_posts WHERE type = 'job_post' AND link_url = '<job permalink>';
```

> Replace `JOB_ID` / `<job permalink>` with the real values you used. If you only ran the synthetic `wp eval` steps with arbitrary IDs (909001 / 5001), the first two deletes are sufficient.

## Known limitations

- **Thinnest of the four bridges.** Compared to BuddyXBridge, WPMediaVerseBridge, GamificationBridge and JetonomyBridge, the Career Board bridge is the most minimal: 5 listeners, two BN service calls, no REST, no nav/profile rail injection, no filters. It is a pure inbound event → write adapter.
- **Partner-owned data model.** All job/application/employer/candidate entities are owned by the Career Board plugin. The bridge never reads or mutates Career Board tables directly — it only reacts to the `wcb_*` actions and reads `post_author` / `get_permalink()` off the job post. If the partner does not fire these exact actions (or changes their arg shapes), the bridge silently does nothing.
- **No hashtag indexing.** Despite earlier planning notes, this bridge does **not** touch `bn_hashtags` / `bn_post_hashtags` and does **not** fire `buddynext_index_hashtags`. A `#tag` inside a job description is stored as plain text in `bn_search_index.content` only — it is searchable as text but is **not** registered as a community hashtag. (Verified against `CareerBoardBridge.php` source — the hashtag path does not exist here.)
- **Expiry does not de-index search.** `on_job_expired()` deletes the `bn_posts` feed card but leaves the `bn_search_index` job row in place, so an expired job can still appear in community search until it is overwritten or manually removed.
- **Employer resolution depends on job authorship.** `on_application_submitted()` resolves the employer from the job post's `post_author`. If Career Board stores the employer elsewhere (e.g. meta) rather than as the post author, the wrong user (or `0` → no notification) results.

## Automation notes

- Because the bridge has no REST surface, automate it by firing the partner `wcb_*` actions with `wp eval` (as above) and asserting the BN-side DB writes — no curl/basic-auth flow needed.
- For a hermetic test, stub the partner guard: define a dummy `WCB_Career_Board` class and a `wcb_get_job()` function before `init()`, then assert `has_action('wcb_job_created')` is registered. This proves the guard without installing the real plugin.
- Assert idempotency by firing `wcb_job_created` twice for the same `object_id` and checking `COUNT(*) = 1` in `bn_search_index`.
- The notification assertions are deterministic only when a **real** job post with a known `post_author` exists (Parts 2 & 5 read `post_author` / `get_permalink`); the status-changed and withdrawn paths (Parts 3 & 4) take all required IDs as hook args and can run fully synthetically.
- All IDs (909001, 5001) are placeholders — do not hardcode real Career Board IDs into a shared test; derive them from partner-create responses or fixtures.
```