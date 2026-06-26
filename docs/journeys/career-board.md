# Journey: Career Board (jobs + resumes) integration — PRO

> **PRO + partner plugin required.** As of 2026-06-14 the Career Board integration is a **BuddyNext Pro** feature (jobs are an application layer). There is **no Career Board bridge in BuddyNext Free** — `buddynext/includes/` contains no `CareerBoardBridge` and no `wcb_*` listeners (verified: grep of Free `includes/` returns only unrelated mentions in `DemoDataService`, `CompanionRegistry`, `SearchService`, etc. — no bridge). This journey therefore needs **BuddyNext Free + BuddyNext Pro + the Career Board plugin (`wp-career-board`) all active**. On a free-only site, none of these steps fire: jobs do not index into search, no feed activity is posted, and no Portfolio panels appear.

**Layer (Pro):**
- `buddynext-pro/includes/Bridges/CareerBoardBridge.php` — job/resume **events → community surfaces** (feed activity + search index + notification-center mirror).
- `buddynext-pro/includes/Integrations/CareerBoard/CareerBoardSocial.php` — **profile "Portfolio" panels** (Jobs + public Resume).
- Rendered through Free's shared seams: `BuddyNext\Feed\IntegrationActivity`, `BuddyNext\Search\SearchService`, and the Pro `BuddyNextPro\Suite\SuiteProfile` / `SuiteNotifications` aggregators.

**Wiring:** both classes are registered from Pro's `BuddyNextPro\Core\Plugin::wire_extensions()`:
- the bridge on Free's `buddynext_load_bridges` action (`plugins_loaded:25`) — `Plugin.php:198-200`;
- `SuiteProfile::register()` — `Plugin.php:210`;
- `CareerBoardSocial::register()` — `Plugin.php:217`.

**Guard:** every entry point bails when the partner is absent — `defined( 'WCB_VERSION' )` in `CareerBoardBridge::init()` (`:46`), `CareerBoardSocial::add_panels()` (`:100`) and `add_nav_deny()` (`:47`). Dashboard-link helpers additionally guard `class_exists( '\WCB\Admin\Settings' )` (`CareerBoardSocial:183`).

**Partner hooks consumed (CB → BN):** `wcb_job_created` (1 arg), `wcb_job_expired` (1 arg), `wcbp_resume_published` (1 arg), `transition_post_status` (3 args, catches wp-admin publishes), `wcb_notification_created` (1 arg, CB Pro 1.4.3+). **Filters consumed:** `buddynext_member_suite_panels` (2 args), `buddynext_client_nav_deny` (1 arg).

**BN surfaces produced:**
- **Feed activity** via `IntegrationActivity::publish()` → a `wp_bn_posts` link card (stored `type = 'link'`, `privacy = 'public'`, idempotent per `link_url`).
- **Search index** via `SearchService::index( 'job', … )` → a `wp_bn_search_index` row with `object_type = 'job'`.
- **Profile Portfolio panels** via `SuiteProfile` (one shared "Portfolio" tab; each integration is a sub-tab). Panel keys: **`jobs`** (icon `briefcase`, priority 20) and **`resume`** (icon `file-text`, priority 25) — both **unprefixed** (`CareerBoardSocial:146,365`).
- **Notification-center mirror** via `SuiteNotifications::push()` → a `wp_bn_notifications` row of type `suite.career_board` (collect-only).

**Notifications — collect-only, never re-emailed.** The bridge does **not** generate its own job/application notifications. It only **mirrors** Career Board's own `wcb_notification_created` payloads into BN's center for display; the `career_board` source is registered `can_email = false` (`SuiteNotifications:164`), so BuddyNext never emails them — Career Board owns its own emails and in-app bell.

**DB tables touched:** `wp_bn_search_index` (job rows), `wp_bn_posts` (feed link card; removed on expiry), `wp_bn_notifications` (mirrored `suite.career_board` rows only).

**Estimated time:** 10 min manual

> **Site-owner expectation**
>
> A community owner runs BuddyNext Pro + the Career Board plugin. Their expectation is **plug-and-play**: jobs and resumes their members publish in Career Board automatically gain a community layer — a published job posts a feed activity card and becomes discoverable in community search, a public ("open to work") resume posts a feed card, and a member's profile grows a **Jobs** / **Resume** panel under the **Portfolio** tab linking out to the partner's own pages. They do not configure anything beyond activating both plugins. Career Board keeps owning the job/application/resume data model, its own pages, and its own notifications + emails; BuddyNext only adds the community surfaces on top.

## Preconditions

- BuddyNext Free **+ BuddyNext Pro + `wp-career-board`** active on http://buddynext-dev.local/ (LocalWP dev site). Confirm:

  ```bash
  wp plugin list --status=active --field=name | grep -E '^(buddynext|buddynext-pro|wp-career-board)$'
  wp eval 'var_dump( defined( "WCB_VERSION" ) );'   # the bridge guard — expect bool(true)
  ```

  All three plugins must appear and `WCB_VERSION` must be defined. If the partner is inactive the bridge is inert (see Edge case 1) and only the no-fatal guard can be verified.
- Admin user (autologin: append `?autologin=1` to any admin URL — the mu-plugin at `mu-plugins/00-autologin.php` handles it).
- Member users: `member1` / `password`, `member2` / `password`. For the happy path, treat `member1` as the **employer** (job author) and a separate candidate user as the **resume** author.
- Capture IDs up front:

  ```bash
  wp user get member1 --field=ID   # MEMBER1_ID (employer / job author)
  ```

> All `wp` commands assume LocalWP's site shell (right-click the site → "Open Site Shell"). No `wp-env` prefix.

## Happy-path steps

> The bridge is a one-way listener: it reacts to the **partner's** `wcb_*` actions and writes BN-side rows. The cleanest deterministic way to exercise it is to publish a real Career Board job/resume (so a real post with a real `post_author` and permalink exists), or to fire the partner hook with `wp eval` using a **real** job post ID. Because the handlers read the saved post (not the hook payload), a real post ID is required for meaningful assertions.

### Part 1: Publish a job → feed activity + search index

1. Create and publish a job in Career Board authored by `member1` (via the Career Board UI, or its REST create path). Note the job post ID (`JOB_ID`).

   - On publish, the bridge reacts via **either** `wcb_job_created` (CB's REST create path) **or** `transition_post_status` (wp-admin / any non-REST publish of a `wcb_job` post). Both route into `on_job_created()` (`CareerBoardBridge:117`, transition shim at `:155`), which is idempotent.

   To drive it synthetically against a real job post:

   ```bash
   wp eval 'do_action( "wcb_job_created", JOB_ID ); echo "fired\n";'
   ```

   - Expected: `fired`. `on_job_created()` loads the post with `get_post( JOB_ID )`, indexes it for search, and publishes a feed activity linking out to the job permalink.

2. Verify the job landed in the search index:

   ```sql
   SELECT object_type, object_id, title, author_id, visibility
   FROM wp_bn_search_index
   WHERE object_type = 'job' AND object_id = JOB_ID;
   ```

   - Expected: 1 row, `object_type = job`, `title` = the job title, `author_id` = `member1`'s ID, `visibility = public` (SearchService default). `SearchService::index()` is an idempotent upsert, so re-publishing the same job updates the row in place (`CareerBoardBridge:123-129`).

3. Verify the feed activity card was created:

   ```sql
   SELECT id, user_id, type, link_url
   FROM wp_bn_posts
   WHERE type = 'link' AND link_url = '<job permalink>'
   ORDER BY id DESC LIMIT 1;
   ```

   - Expected: 1 row, `user_id = member1`'s ID, `type = link` (the activity is a generic link card — the bridge calls `IntegrationActivity::publish()` with the default type; the **`job`** object type is the *search-index* row from step 2, not the feed post type). The card text is "posted a new job" and links out to the Career Board job page. `IntegrationActivity::publish()` dedupes on `link_url`, so firing the hook twice yields a single card.

4. Confirm discoverability through search (the site-owner-facing outcome):

   ```bash
   curl -s 'http://buddynext-dev.local/wp-json/buddynext/v1/search?q=<term from job title>' -u member1:password
   ```

   - Expected: the response includes a `job` group containing `JOB_ID`. The member-facing `/search` page also renders a **Jobs** tab/section (search result rendering auto-discovers extra indexed `object_type`s; labels filterable via `buddynext_search_type_labels`).

### Part 2: Resume published (public) → "open to work" feed activity

5. As a candidate member, publish a resume in Career Board and mark it **public** (`_wcb_resume_public = '1'`). Note `RESUME_ID`, then fire:

   ```bash
   wp eval 'do_action( "wcbp_resume_published", RESUME_ID ); echo "fired\n";'
   ```

   - Expected: `fired`. `on_resume_published()` (`CareerBoardBridge:191`) acts **only** when the resume is public (`_wcb_resume_public === '1'`) AND `is_post_type_viewable( 'wcb_resume' )` is true — otherwise it returns early so a private resume is never broadcast.

6. Verify the "open to work" activity:

   ```sql
   SELECT id, user_id, type, link_url
   FROM wp_bn_posts
   WHERE type = 'link' AND link_url = '<resume permalink>'
   ORDER BY id DESC LIMIT 1;
   ```

   - Expected: 1 row authored by the resume's `post_author`, card text "is open to work", linking out to the Career Board resume page.

7. **Negative:** mark the resume private (`_wcb_resume_public` unset / not `'1'`) and re-fire `wcbp_resume_published`. Expected: **no** new `bn_posts` row.

### Part 3: Job expires → feed activity removed

8. Fire the expiry hook against the real `JOB_ID`:

   ```bash
   wp eval 'do_action( "wcb_job_expired", JOB_ID ); echo "fired\n";'
   ```

   - Expected: `fired`. `on_job_expired()` (`CareerBoardBridge:171`) resolves `get_permalink( JOB_ID )` and calls `IntegrationActivity::remove()`, deleting the matching `wp_bn_posts` link card. If the permalink is falsy it returns early.

9. Verify the feed card is gone:

   ```sql
   SELECT id, type, link_url
   FROM wp_bn_posts
   WHERE type = 'link' AND link_url = '<job permalink>';
   ```

   - Expected: 0 rows. **Note:** expiry removes the feed card only; it does **not** de-index the `bn_search_index` job row (see Known limitations).

### Part 4: Profile Portfolio panels (Jobs + Resume)

10. As any user, load `member1`'s profile (`/members/member1/` → **Portfolio** tab). The bridge's social half (`CareerBoardSocial::add_panels`, hooked `buddynext_member_suite_panels`) contributes panels, which `SuiteProfile` renders as sub-tabs under the single "Portfolio" tab.

    - Expected for the employer (`member1`): a **Jobs** panel (`key = jobs`, icon `briefcase`) listing their published `wcb_job` posts (up to 10), each row showing company, location/Remote, job type, "Posted N ago", and a salary highlight. A "View all Jobs" CTA points at the member's Career Board **company** page; an owner-only "Manage Jobs" CTA points at the employer dashboard (only when viewing your own profile).
    - Expected for the candidate: a **Resume** panel (`key = resume`, icon `file-text`) listing **public** resumes only, with an owner-only "Manage Resume" CTA to the candidate dashboard.

11. **Negative (private resume):** confirm a candidate whose resume is **not** public shows **no** Resume panel — `resume_panel()` filters on `_wcb_resume_public = '1'` and requires `is_post_type_viewable( 'wcb_resume' )` (`CareerBoardSocial:332-352`).

### Part 5: Notification-center mirror (CB Pro 1.4.3+)

12. With Career Board **Pro** active (which fires `wcb_notification_created`), trigger any Career Board notification (e.g. an application event in CB's own flow), or fire it synthetically:

    ```bash
    wp eval '
      $uid = (int) ( new WP_User( "member1" ) )->ID;
      do_action( "wcb_notification_created", array(
        "user_id"    => $uid,
        "event_type" => "application_received",
        "message"    => "You have a new application for Senior Community Engineer.",
        "link"       => home_url( "/jobs/senior-community-engineer/" ),
        "id"         => 42,
      ) );
      echo "fired\n";
    '
    ```

    - Expected: `fired`. `on_notification()` (`CareerBoardBridge:88`) calls `SuiteNotifications::push()`, which writes one `wp_bn_notifications` row.

13. Verify the mirrored notification:

    ```sql
    SELECT recipient_id, type, data
    FROM wp_bn_notifications
    WHERE type = 'suite.career_board' AND recipient_id = MEMBER1_ID
    ORDER BY id DESC LIMIT 1;
    ```

    - Expected: 1 row, `type = suite.career_board`, `data` JSON containing the partner-supplied `message` and `url`. The notification appears in BN's `/notifications/` center under the "Jobs" source but is **never re-emailed** by BuddyNext (`can_email = false`).

## Edge cases to also verify

- **Bridge inert when partner inactive (no fatals).** With `wp-career-board` deactivated (so `WCB_VERSION` is undefined), load BuddyNext + Pro and confirm the front end and WP-CLI run clean. `CareerBoardBridge::init()` returns at the `defined( 'WCB_VERSION' )` guard, so none of the `wcb_*` / `transition_post_status` listeners attach.

  ```bash
  wp eval '( new BuddyNextPro\Bridges\CareerBoardBridge() )->init(); echo "no fatal\n";'
  wp eval 'var_dump( has_action( "wcb_job_created" ) );'   # expect false (no BN callback)
  ```

  - Expected: `no fatal`; firing `do_action( "wcb_job_created", … )` with the partner inactive writes **no** rows. The Portfolio Jobs/Resume panels are also absent (`add_panels()` bails on the same guard).

- **Job indexing is idempotent.** Re-fire `wcb_job_created` for the same `JOB_ID`. `SearchService::index()` upserts (`INSERT … ON DUPLICATE KEY UPDATE`), and `IntegrationActivity::publish()` dedupes by `link_url`, so the search row is updated in place and **no** duplicate feed card is created.

  ```sql
  SELECT COUNT(*) AS search_rows FROM wp_bn_search_index WHERE object_type = 'job' AND object_id = JOB_ID;
  SELECT COUNT(*) AS feed_cards  FROM wp_bn_posts        WHERE type = 'link'   AND link_url = '<job permalink>';
  ```

  - Expected: `search_rows = 1`, `feed_cards = 1`.

- **wp-admin publish path.** Publish a `wcb_job` from wp-admin (no REST create). `on_job_status_transition()` (`CareerBoardBridge:155`) catches the `wcb_job` post entering `publish` and routes it through `on_job_created()`, so admin-created jobs still get a feed card + search row.

## What this validates

- **Self-gating guard.** Both halves register nothing unless `defined( 'WCB_VERSION' )` — safe to load with the partner absent. The bridge attaches on Free's `buddynext_load_bridges` (`plugins_loaded:25`) seam, registered from Pro's `wire_extensions()`.
- **Feed seam.** `on_job_created()` / `on_resume_published()` → `IntegrationActivity::publish()` writes a public link card into `wp_bn_posts` (idempotent per `link_url`), linking out to the partner page. `on_job_expired()` → `IntegrationActivity::remove()` deletes it.
- **Search seam.** `on_job_created()` → `SearchService::index( 'job', $job_id, $title, $content, $author )` writes a `job` row into `wp_bn_search_index` (idempotent upsert). This is the "jobs appear in community search" behaviour.
- **Profile seam.** `CareerBoardSocial::add_panels()` (filter `buddynext_member_suite_panels`) contributes the `jobs` + `resume` panels into the shared Portfolio tab; public-resume-only gating protects sensitive data.
- **Notification mirror (collect-only).** `on_notification()` → `SuiteNotifications::push()` mirrors CB's own `wcb_notification_created` payloads into `wp_bn_notifications` as `suite.career_board`, displayed but never re-emailed (`can_email = false`).
- **No BN-generated job/application notifications.** The bridge does not create `cb.application_*` (or any job/application) notifications of its own — Career Board owns those.
- **Client-nav safety.** `CareerBoardSocial::add_nav_deny()` (filter `buddynext_client_nav_deny`) full-loads Career Board's CPT + dashboard paths so a Portfolio link does not get swapped into BN's router region and break.

## Verification queries

```sql
-- Job rows the bridge indexed:
SELECT object_type, object_id, title, author_id, visibility, updated_at
FROM wp_bn_search_index
WHERE object_type = 'job'
ORDER BY updated_at DESC;

-- Integration feed cards (job + resume activities link out via wp_bn_posts.link_url):
SELECT id, user_id, type, link_url, created_at
FROM wp_bn_posts
WHERE type = 'link' AND link_url LIKE '%/jobs/%' OR link_url LIKE '%/resume%'
ORDER BY id DESC;

-- Mirrored Career Board notifications (collect-only):
SELECT id, recipient_id, type, data, created_at
FROM wp_bn_notifications
WHERE type = 'suite.career_board'
ORDER BY id DESC;
```

## REST / observability surface walked

The integration exposes **no REST routes of its own** — both halves are server-side listeners/filters. Observability is through the Free surfaces its writes feed into:

```
GET  /buddynext/v1/search?q=<term>      -- indexed jobs appear in the 'job' group
GET  /buddynext/v1/me/notifications     -- mirrored suite.career_board rows appear in the center
```

- The `job` object type is discovered dynamically by `SearchService::grouped_search()` from the index table, so jobs are returned by REST `/search` and rendered on the member-facing search results page (Jobs tab) without per-partner search code.
- Partner-owned surfaces — the actual job/resume/company pages, the application UI, the employer/candidate dashboards — live in the **Career Board** plugin, not BuddyNext. The Portfolio panels link **out** to them.

## Bridge contract & partner gate

*(Item 11, bridge form.)*

| Direction | Hook / filter (arg count) | Handler | Guard |
|---|---|---|---|
| CB → BN | `wcb_job_created( $job_id )` **1 arg** (CB fires `($job_id, $request)`; bridge reads the saved post) | `CareerBoardBridge::on_job_created` (`:50` → `:117`) | `defined('WCB_VERSION')`; registered on `buddynext_load_bridges` (plugins_loaded:25) |
| CB → BN | `transition_post_status` **3 args** (wp-admin / non-REST publish of a `wcb_job`) | `on_job_status_transition` (`:59` → `:155`) → `on_job_created` | same |
| CB → BN | `wcb_job_expired( $job_id )` **1 arg** | `on_job_expired` (`:51` → `:171`) | same |
| CB → BN | `wcbp_resume_published( $resume_id )` **1 arg** (public resumes only) | `on_resume_published` (`:52` → `:191`) | same |
| CB → BN | `wcb_notification_created( array $payload )` **1 arg** (CB Pro 1.4.3+) | `on_notification` (`:76` → `:88`) → `SuiteNotifications::push` | same |
| BN profile panels | filter `buddynext_member_suite_panels` **2 args** | `CareerBoardSocial::add_panels` (`:30` → `:99`) — surfaces `jobs` + `resume` panels | `defined('WCB_VERSION')` |
| BN client-nav | filter `buddynext_client_nav_deny` **1 arg** | `CareerBoardSocial::add_nav_deny` (`:31` → `:45`) — full-load CB CPT/dashboard paths | `defined('WCB_VERSION')` |

**Verify this run (Free + Pro + `wp-career-board` active):**
1. Publish a job → confirm a `job` row in `bn_search_index`, a `link` feed card in `bn_posts`, and that `GET /search?q=` returns it in the `job` group.
2. Publish a public resume → "open to work" feed card; private resume → no card.
3. Expire a job → feed card removed (search row remains).
4. CB Pro notification → mirrored `suite.career_board` row in the center (never emailed by BN).
5. **Graceful absence:** deactivate `wp-career-board` → no fatal, no job group in search, Portfolio Jobs/Resume panels absent.

## Admin-config → member-effect

*(Item 12.)*

- **Career Board active + Pro active:** a member's profile shows the Jobs / Resume panels under the Portfolio tab (`buddynext_member_suite_panels`); deactivate the partner → panels vanish (graceful degrade), no error.
- **Resume visibility:** only `_wcb_resume_public = '1'` resumes surface (`CareerBoardSocial:345`) and only when `wcb_resume` is publicly viewable — confirm a private resume neither posts a feed card nor appears in the Resume panel.
- **Notification emails:** the `career_board` source is `can_email = false` — confirm BuddyNext never emails a Career Board notification (Career Board sends its own).

## Cleanup

```sql
-- Remove the indexed test job row (substitute the real JOB_ID):
DELETE FROM wp_bn_search_index
WHERE object_type = 'job' AND object_id = JOB_ID;

-- Remove the integration feed cards created by this walk (substitute real permalinks):
DELETE FROM wp_bn_posts
WHERE type = 'link' AND link_url IN ('<job permalink>', '<resume permalink>');

-- Remove the mirrored Career Board notifications:
DELETE FROM wp_bn_notifications
WHERE type = 'suite.career_board' AND recipient_id = MEMBER1_ID;
```

> Replace `JOB_ID` / `<job permalink>` / `<resume permalink>` / `MEMBER1_ID` with the real values you used. Partner-owned job/resume/company posts are Career Board's data — remove them through the Career Board UI if you need a pristine partner state.

## Known limitations

- **Pro + partner both required.** With Free only (no Pro) or with the partner inactive, the entire integration is absent. There is no Free fallback.
- **Partner-owned data model.** All job/application/resume/company entities are owned by Career Board. The bridge never reads or mutates Career Board tables directly — it reacts to the `wcb_*` actions, reads the saved post (`get_post`, `post_author`, `get_permalink`), and reads job meta/taxonomy only for the Portfolio panel rows (`_wcb_company_id`, `_wcb_salary_*`, `wcb_location`, `wcb_job_type`, etc.). If the partner changes these hook names or meta keys, the integration silently does nothing.
- **Feed activity is a generic link card.** `IntegrationActivity::publish()` is called with the default post type, so the feed row is stored as `type = 'link'` (not `'job'`). The `job` classification lives on the **search-index** row, not the feed post. (The bridge's header comment "(type: job)" refers to the search index.)
- **Expiry does not de-index search.** `on_job_expired()` removes the feed card but leaves the `bn_search_index` job row, so an expired job can still appear in community search until it is overwritten or removed.
- **Resume privacy is meta-gated.** Both the resume feed activity and the Resume panel depend on `_wcb_resume_public = '1'` and a publicly viewable `wcb_resume` CPT. If Career Board stores resume visibility differently, the gate may misjudge and either hide a public resume or (worse) surface one — verify against the partner's current meta contract.
- **Notification mirror needs CB Pro.** `wcb_notification_created` is a Career Board **Pro** hook (1.4.3+). On Career Board free, no notifications are mirrored — feed + search + Portfolio panels still work.

## Automation notes

- The integration has no REST surface of its own, so automate it by firing the partner `wcb_*` actions with `wp eval` against **real** Career Board posts (the handlers read the saved post, so synthetic IDs without a backing post yield no writes), then asserting the BN-side DB rows.
- For a hermetic guard test, `define( 'WCB_VERSION', '0.0.0' )` before calling `init()` and assert `has_action( 'wcb_job_created' )` is registered — this proves the guard without the full partner.
- Decisive assertions are DB-level: after a job publish, assert exactly one `bn_search_index` `job` row and one `bn_posts` `link` card for the permalink; after expiry, assert the card is gone but the search row remains.
- Do not hardcode IDs — derive `JOB_ID` / `RESUME_ID` / `MEMBER1_ID` from partner-create responses and `wp user get`.
</content>
</invoke>
