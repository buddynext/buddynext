import { wp } from './wp';

/**
 * Playwright global setup for the BuddyNext E2E suite.
 *
 * Disables the per-minute comment rate limit for the test run. The suite is
 * serial (workers: 1) and legitimately posts many comments as one admin in a
 * tight window — comment-load-more alone seeds 23, and the comment-* cluster
 * plus feed comment specs add more. With the product default of 30/min
 * (`buddynext_comment_rate_limit`), the later comments in that burst come back
 * 429 and their cards never render, flaking those specs red under full-suite
 * load while they pass in isolation. The rate limit is an anti-spam guard, not
 * anything the feed/comment specs assert, so turning it off for the E2E lab
 * makes the run reflect real single-user behaviour instead of a serial-burst
 * artefact. Best-effort: never throws, so a missing WP-CLI can't fail the run.
 */
async function globalSetup(): Promise<void> {
    try {
        await wp(['option', 'update', 'buddynext_comment_rate_limit', '0']);
    } catch {
        /* WP-CLI unavailable — specs that hit the limit will surface it themselves. */
    }
}

export default globalSetup;
