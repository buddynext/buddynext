# WP-CLI Commands

BuddyNext registers these WP-CLI command namespaces in Free, all in `Plugin::init()` and loaded only when WP-CLI is running: `wp buddynext demo` (the demo-data seeder), `wp buddynext cert` (the functional-certification harness), `wp buddynext repair-space-owners` (a one-off orphan sweep), `wp buddynext repair-discussion-visibility` (a one-off visibility sweep for discussions provisioned before visibility was derived from the space type), `wp buddynext reconcile-media-privacy` (bring stored media privacy back in line with its post), `wp buddynext handles` (check / repair / reconcile member handles that mentions cannot parse), `wp buddynext bridge-status` (report integration-bridge version freshness), and `wp buddynext qa-fixtures` plus `wp buddynext qa-reset` (deterministic QA data and harness cleanup - **development trees only**). This page documents their subcommands, what they seed or verify, and example invocations.

![The Platform > Tools admin tab for maintenance and CLI-adjacent operations](../images/admin-tools.webp)

![The admin dashboard you inspect after running the demo seeder documented on this page](../images/admin-overview.webp)

## Overview

```php
// includes/Core/Plugin.php
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    \WP_CLI::add_command( 'buddynext demo', new \BuddyNext\Demo\DemoCommand() );
    \WP_CLI::add_command( 'buddynext cert', new \BuddyNext\Cert\CertCommand() );
    \WP_CLI::add_command( 'buddynext repair-space-owners', \BuddyNext\Spaces\SpaceOwnerRepairCommand::class );
    \WP_CLI::add_command( 'buddynext repair-discussion-visibility', \BuddyNext\Bridges\DiscussionVisibilityRepairCommand::class );
    \WP_CLI::add_command( 'buddynext handles', new \BuddyNext\Profile\HandleCommand() );

    // Registered only when dev/QaFixturesCommand.php is present.
    $bn_qa_fixtures = BUDDYNEXT_DIR . 'dev/QaFixturesCommand.php';
    if ( is_readable( $bn_qa_fixtures ) ) {
        require_once $bn_qa_fixtures;
        \WP_CLI::add_command( 'buddynext qa-fixtures', new \BuddyNext\Dev\QaFixturesCommand() );
    }
}
```

The demo command is a thin wrapper over `DemoDataService`, so the CLI and the admin "Demo Data" button share one engine. The cert command wraps `CertRunner`, the same gate that `bin/check.sh` and CI run.

> **`qa-fixtures` does not exist in a packaged install.** It lives in `dev/`, which is not on `bin/build-release.sh`'s runtime allowlist, so the file is absent from the shipped zip and the `is_readable()` guard above never fires. It is a development-tree command. Do not write docs, support replies, or tooling that tells a customer to run it.

## wp buddynext demo

Populates, inspects, or removes a realistic demo community. Useful for screenshots, manual QA, and verifying behavior against a populated dataset rather than five empty rows. The seeder uses bundled offline images, so it works with no network access.

### Subcommands

| Subcommand | What it does |
|---|---|
| `seed` | Populates the demo community: members, spaces, posts, the social graph between them, and profile fields. Refuses to run if demo data is already installed - run `cleanup` first. |
| `status` | Prints what is currently installed: counts of members, spaces, posts, and profile fields. Prints "No demo data installed." when the dataset is absent. |
| `cleanup` | Removes everything the seeder created (posts, spaces, members, profile fields) and reports the counts removed. |

### What `seed` creates

The seeder produces a connected community, not isolated rows:

- **Members** - demo users with avatars (bundled offline images) and populated profiles.
- **Spaces** - demo spaces with members assigned to them.
- **Posts** - activity posts authored across the members and spaces.
- **Social graph** - follow / connection relationships between the demo members.
- **Profile fields** - the custom profile fields the demo profiles fill in.

`seed` is idempotent-guarded: if `DemoDataService::is_seeded()` reports data already present, it warns and exits instead of double-seeding. `cleanup` is the inverse and is similarly guarded - it warns if there is nothing to remove.

### Examples

```bash
# Populate a demo community
wp buddynext demo seed

# See what is installed
wp buddynext demo status

# Remove everything the seeder created
wp buddynext demo cleanup
```

Sample `seed` output:

```
Seeded 24 members, 6 spaces, 80 posts, 9 profile fields.
```

Sample `status` output:

```
Members:        24
Spaces:         6
Posts:          80
Profile fields: 9
```

> **Note:** The exact counts depend on the bundled dataset; the numbers above are illustrative. Re-running `seed` without first running `cleanup` is a no-op that warns - it never duplicates the dataset.

## wp buddynext cert

The functional-certification harness. It is the one trustworthy release gate that asserts the plugin **behaves** - toggles actually enforce and REST routes do not fatal - rather than that the code merely parses or passes a linter. It is invoked by `bin/check.sh` and CI.

### Subcommands and flags

| Invocation | What it runs |
|---|---|
| `wp buddynext cert` | Runs all checks (`contract` + `boot`). Exits 1 if any check fails. |
| `wp buddynext cert contract` | Runs only the contract (dead-toggle / behaviour-flip) check. |
| `wp buddynext cert boot` | Runs only the REST boot smoke - dispatches every public GET route and asserts none return 500. |
| `wp buddynext cert --porcelain` | Emits the ledger as machine-readable JSON (for the MCP and CI) instead of the human summary. Combine with a check name to scope it. |

> **Note:** The machine-readable flag is `--porcelain`, not `--json`. WP-CLI reserves `--json` for its own output formatter and would reject it.

### The contract check (the cert contract)

> **This is an internal QA gate, and its inputs do not ship.** The command is in the plugin, but the
> inventory and oracle files it reads live outside the distributed package by design - the internal
> surface inventory is deliberately not public. On a normal install the runner therefore finds no
> oracles and reports holes rather than passes. That is expected, and it refuses to report a vacuous
> "0 failures" pass when it has asserted nothing. Documented here because the command exists and you
> will find it; you are not missing a file you were supposed to have.

The contract check proves that every gated setting is wired through to real behavior, catching "dead toggle" bugs - a setting saved in the database but never read on the enforcement path.

For each gated feature listed in its contract oracle, the runner:

1. Snapshots the current setting state.
2. Flips the setting **OFF** and asserts the REST surface's behavior changes - the disabled error code appears.
3. Flips the setting **ON** and asserts the disabled error code is gone.
4. Restores the original state.

Each oracle yields a row with one of three statuses:

| Status | Meaning |
|---|---|
| `PASS` | The toggle enforces - behavior changed when flipped. |
| `FAIL` | The toggle is dead - flipping the setting did not change behavior. Fails the gate. |
| `HOLE` | The feature is toggleable but has no oracle, so enforcement is unproven. On a normal install every gated feature reports this, because the oracle file is not part of the distributed package. |

A `HOLE` is uncovered surface, not a failure, but it signals a gap in the oracle.

### The boot check

The boot check dispatches every public/GET REST route under the BuddyNext namespaces and asserts none return `>= 500` (a thrown fatal is captured as a 500). A `4xx` (for example an auth-required `401/403`) is acceptable - only server faults fail the gate.

### Examples

```bash
# Full functional certification (contract + boot); exits 1 on any failure
wp buddynext cert

# Only the dead-toggle behaviour-flip check
wp buddynext cert contract

# Only the REST boot smoke
wp buddynext cert boot

# Machine-readable ledger for CI / MCP
wp buddynext cert --porcelain
```

Sample human output:

```
  PASS  contract  feature-x              disabled code appears when off
  PASS  boot      GET /spaces            200
  FAIL  contract  feature-y              flip had no effect
  HOLE  contract  feature-z              no oracle - enforcement unproven

  2 passed, 1 failed, 1 holes (uncovered)
Error: Functional certification FAILED - 2 passed, 1 failed, 1 holes (uncovered)
```

## wp buddynext repair-space-owners

A one-off sweep for spaces whose `owner_id` points at a user who no longer exists.

Space succession (auto-promoting an heir when an owner is deleted or erased) guards deletions **from now on**. It cannot retroactively fix a space orphaned before it shipped. This command finds those spaces and runs them through the same `SpaceSuccession` path, chunked 200 rows at a time so it survives a large install.

It takes no subcommand - invoke it directly. One flag:

| Flag | What it does |
|---|---|
| `--dry-run` | Report what would change without writing anything. |

```bash
# See what it would do first.
wp buddynext repair-space-owners --dry-run

# Then let it write.
wp buddynext repair-space-owners
```

## wp buddynext repair-discussion-visibility

A one-off sweep for Jetonomy discussions provisioned by the space-forum bridge before a discussion's visibility was derived from its space's type. It walks the affected rows and recomputes visibility from the current space type, chunked so it survives a large install.

| Flag | What it does |
|---|---|
| `--dry-run` | Report what would change without writing anything. |

```bash
wp buddynext repair-discussion-visibility --dry-run
wp buddynext repair-discussion-visibility
```

## wp buddynext reconcile-media-privacy

Brings already-stored media privacy back in line with the post it belongs to. `WPMediaVerseBridge::on_post_privacy_changed` keeps new and edited posts in sync live; this repairs media stored under a post whose audience it never matched on a site that ran an earlier version - the "Only me" leak (a private post's photo serving at the default `public`) and the space leak (`space_members` collapsing onto `members`, so a photo posted into a secret space was readable by any signed-in member). Only ever tightens a privacy level, never loosens one, so it is idempotent and safe to re-run.

| Flag | What it does |
|---|---|
| `--dry-run` | Report what would change without writing anything. |

```bash
wp buddynext reconcile-media-privacy --dry-run
wp buddynext reconcile-media-privacy
```

## wp buddynext handles

Finds and repairs member handles (`user_nicename`) that fall outside the mentionable charset - a state WordPress and BuddyNext's own signup can never produce, but a direct-database migration from another platform can. An affected member is silently unmentionable: `@name@example-com` parses as `name` followed by `example-com`, and neither resolves. Their profile still works, which is why the fault goes unnoticed until someone reports a member who "does not come up".

| Subcommand | What it does |
|---|---|
| `check` | List members whose handle cannot be mentioned, with the nicename the repair would write. |
| `repair` | Normalise unmentionable handles to what WordPress itself would have written. Dry-run by default (rewrites profile URLs); pass `--yes` to apply. |
| `reconcile` | Fix members with two divergent identities (handle vs. nicename). `--prefer=<handle\|nicename>` (default `handle`) picks which one survives. Dry-run by default; pass `--yes` to apply. |

```bash
wp buddynext handles check
wp buddynext handles repair --yes
wp buddynext handles reconcile --prefer=nicename --yes
```

## wp buddynext bridge-status

Reports the freshness of every registered integration bridge against its partner plugin - the recurring staleness gate. Each bridge declares a `min_version` (the floor it needs) and a `tested_version` (the partner release it was last verified against) in the `buddynext_integrations` registry; this command compares those against the partner version actually installed, so a bridge cannot silently rot as its partner ships new releases. Intended to run in CI as well as by hand.

| Status | Meaning |
|---|---|
| `ok` | The installed partner version is at or above the floor and at or below the tested version. |
| `below-floor` | The installed partner version is older than the bridge's declared floor. Always a failure. |
| `partner-ahead` | The installed partner version is newer than what the bridge was verified against. A warning unless `--strict` is passed. |

| Flag | What it does |
|---|---|
| `--strict` | Treat `partner-ahead` as a failure too, not just a warning. |

```bash
wp buddynext bridge-status
wp buddynext bridge-status --strict
```

See the Integration Bridges page for the registry shape and per-bridge floors this command reads.

## wp buddynext qa-reset

**Development trees only** - lives in `dev/`, absent from a packaged install, same guard as `qa-fixtures` below. Removes what the Playwright e2e harnesses left behind on a shared site. Unlike `qa-fixtures cleanup` (which deletes exactly the ids it wrote from its own manifest), the e2e specs create data the way a member does - through the UI and REST - and leave no manifest, so this command matches by content pattern instead. Every pattern is anchored (`^`) so a member's own content is never caught, it reports and changes nothing unless `--yes` is passed, and an account that can administer the site is never deleted under any pattern - it is reported as needing a person instead.

## wp buddynext qa-fixtures

Deterministic QA data: the ugly states a customer demo must never contain (expired invites, orphaned space owners, cancelled subscriptions, rows backdated past the retention windows) plus big-site scale data.

This is **not** the demo seeder. `demo` builds a community that looks good; `qa-fixtures` builds the states that break things, so QA stops guessing at them.

> **Development trees only.** As above, `dev/` is not shipped, so this command does not exist in a packaged install.

### Subcommands

| Subcommand | What it does |
|---|---|
| `seed` | Creates the fixtures for the chosen profile. |
| `cleanup` | Removes what `seed` created. |
| `status` | Prints what is currently installed. |

`seed` takes one flag:

| Flag | Default | Options |
|---|---|---|
| `--profile=<profile>` | `edge` | `edge`, `community`, `scale`, `all` |

```bash
wp buddynext qa-fixtures seed                      # edge cases (the default)
wp buddynext qa-fixtures seed --profile=community  # a populated community
wp buddynext qa-fixtures seed --profile=scale      # big-site scale data
wp buddynext qa-fixtures seed --profile=all        # everything

wp buddynext qa-fixtures status
wp buddynext qa-fixtures cleanup
```

## Notes / gotchas

- `demo` and `cert` declare `@when after_wp_load`, so WordPress is fully loaded before they run - they have access to services, settings, and the REST router.
- `cert` writes a ledger as a side effect of every run, so CI and the MCP can read the last result without re-running the gate.
- `demo` and `cert` are Free commands. Pro registers four WP-CLI commands of its own, all in the Pro `Plugin::init()`:
  - `wp buddynext-pro cert` (`\BuddyNextPro\Cert\CertCommand`) - the same functional-certification harness scoped to Pro's gated features. Takes the same optional `contract` / `boot` positional and `--porcelain` flag. Free's `cert` oracle covers gated features in Free.
  - `wp buddynext-pro repair-orphan-subscriptions` - one-off sweep for subscription rows left behind by members deleted before `UserCleanupListener::purge_non_financial_subscriptions()` shipped (that listener stops new orphans accumulating but only fires during a live deletion). Reuses the listener's own "has no money behind it" predicate rather than restating it. Dry-run by default; pass `--yes` to apply.
  - `wp buddynext-pro repair-entitlements` - re-syncs tier-ability grants with the subscriptions that justify them, for drift `SubscriptionService`'s live re-issue-on-write cannot retroactively fix: an auto-renewing subscription whose grant still carries its first period's expiry, an admin extension/comp that kept the pre-extension date, or a refunded/revoked subscription that kept its grant because the expiry cron only sweeps active/cancelled/past-due rows. Dry-run by default; pass `--yes` to apply.
  - `wp buddynext-pro reconcile-memberships` - re-derives every bridge-granted membership from its partner's current state rather than trusting missed events. See the Membership Grant Bridges page for the full contract and flags (`--source=`, `--user=`, `--execute`).

See also the Cron and Async Jobs page for the scheduled-job surface these tools run alongside.
