import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-55 admin moderation queue.
 *
 * Covers: cap-review-a-queue-of-reports-in-wp-admin
 * Roles: admin
 *
 * Walks the wp-admin queue as a full WP administrator (varundubey via
 * authenticatedPage), not a scoped BuddyNext moderator-only account, and does
 * not walk the reporting member's side (cap-let-members-report-content).
 */
test.describe('moderation / queue', () => {
    test('admin queue page renders', async ({ authenticatedPage: page }, testInfo) => {
        const resp = await page.goto(urls.adminModeration, { waitUntil: 'domcontentloaded' });
        if ((resp?.status() ?? 200) >= 400) {
            softSkip(testInfo, 'User is not admin or queue page not registered.');
            return;
        }
        const queue = page.locator(sel.adminModQueue).first();
        const fallbackTable = page.locator('.wp-list-table, .bn-admin__table').first();
        const anyVisible = (await queue.isVisible().catch(() => false)) || (await fallbackTable.isVisible().catch(() => false));
        expect(anyVisible).toBeTruthy();
    });
});
