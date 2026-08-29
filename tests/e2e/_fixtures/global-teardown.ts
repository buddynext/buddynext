import { wp } from './wp';

/**
 * Playwright global teardown for the BuddyNext E2E suite.
 *
 * The specs create real data on the target site — posts, spaces, members,
 * profile fields — through the real UI, which is the point: a seeded row proves
 * nothing about the write path. What they never did was take it back, so a
 * shared dev site accumulated it run after run. That is not merely untidy: it
 * produced FALSE BUG REPORTS. A feed dominated by "e2e text 444809" reads as a
 * broken feed, a member edit form showing two Twitter inputs reads as a
 * duplicate-field bug, and a directory full of `probe_viewer` reads as a
 * sorting defect. Time was spent chasing each of those before anyone noticed
 * the harness had written them.
 *
 * Cleanup belongs HERE rather than in each spec's afterAll. Four specs had one
 * and the other ninety-odd did not, which is the shape every per-spec
 * convention ends up in — one teardown covers the suite, including specs that
 * fail early and never reach their own afterAll.
 *
 * `wp buddynext qa-reset` owns what counts as junk (anchored title/login
 * prefixes, never an administrator, orphan comments/reactions/bookmarks swept
 * with their parents). Keeping that in one WP-CLI command means the rules live
 * next to the data model rather than in a TypeScript copy that drifts from it,
 * and a human can run the same sweep by hand.
 *
 * Set BN_E2E_KEEP_DATA=1 to skip it — after a red run the leftovers are usually
 * the evidence, and wiping them costs you the repro.
 *
 * Best-effort, exactly like global-setup: a missing WP-CLI must not turn a green
 * suite red at the last step.
 */
async function globalTeardown(): Promise<void> {
    if (process.env.BN_E2E_KEEP_DATA === '1') {
        return;
    }

    try {
        await wp(['buddynext', 'qa-reset', '--yes']);
    } catch {
        /* WP-CLI unavailable or the command errored — never fail the run on cleanup. */
    }
}

export default globalTeardown;
