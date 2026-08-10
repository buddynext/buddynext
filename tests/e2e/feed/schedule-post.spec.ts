import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce } from '../_fixtures/feed-wave1.helpers';
import { wp, dbScalar, tablePrefix } from '../_fixtures/wp';

/**
 * J-512 composer schedule-a-post (B1).
 *
 * Written from the member's promise: "a post I schedule for later is NOT in the
 * feed now, and the server is holding it as scheduled." The coverage matrix had
 * "Schedule post" MISSING.
 *
 * Effect-based via DB truth: after composing with a future publish datetime the
 * spec reads the bn_posts row directly and asserts status='scheduled' with a
 * future scheduled_at, AND that the card is absent from the current home feed.
 * A composer that ignores the schedule field and publishes immediately (status
 * 'published', card visible now) fails both legs. The row is deleted in
 * `finally` by id so a scheduled post never lingers in the seed.
 */
test.describe('feed / composer schedule', () => {
    // Composer toolbar schedule tool + its datetime field (partials/composer.php).
    const scheduleTool = 'button.bn-composer__tool[aria-label="Schedule for later"]';
    const scheduleInput = '#bn-composer-schedule-at';

    test('J-512 a scheduled post is held (status=scheduled) and stays out of the feed now', async ({ authenticatedPage: page }) => {
        const stamp = Date.now().toString().slice(-6);
        const content = `j512 scheduled ${stamp}`;
        let postId = 0;

        try {
            await page.goto(urls.feed);
            const composer = page.locator(sel.composer).first();
            await expect(composer).toBeVisible();
            await readRestNonce(page); // asserts the feed carries a live nonce

            const ta = page.locator(sel.composerTextarea).first();
            await ta.fill(content);

            // Open the schedule affordance and set a far-future publish datetime
            // (site wall-clock; the store converts it to UTC on input).
            await page.locator(scheduleTool).first().click();
            const dt = page.locator(scheduleInput).first();
            await expect(dt).toBeVisible({ timeout: 5_000 });
            await dt.fill('2035-06-01T10:00');

            await page.locator(sel.composerSubmit).first().click();

            // Success clears the composer content (no card is prepended for a
            // scheduled post — it is not live). Wait for the textarea to empty.
            await expect(ta).toHaveValue('', { timeout: 10_000 });

            // Effect 1 — the row exists as SCHEDULED with a future publish time.
            const p = await tablePrefix();
            // wp-cli db query with LIKE; content is unique by stamp.
            const row = await dbScalar(
                `SELECT CONCAT(id,'|',status,'|',COALESCE(scheduled_at,'')) FROM ${p}bn_posts WHERE content LIKE '%${content}%' ORDER BY id DESC LIMIT 1;`
            );
            expect(row, 'scheduled row exists').not.toBe('');
            const [idStr, status, schedAt] = row.split('|');
            postId = parseInt(idStr, 10) || 0;
            expect(status, 'stored status').toBe('scheduled');
            expect(schedAt, 'scheduled_at is set').not.toBe('');
            expect(new Date(schedAt.replace(' ', 'T') + 'Z').getTime()).toBeGreaterThan(Date.now());

            // Effect 2 — it is NOT in the live home feed right now.
            await page.goto(urls.feed);
            await expect(page.locator(sel.postCard).filter({ hasText: content })).toHaveCount(0);
        } finally {
            if (postId > 0) {
                const p = await tablePrefix();
                await wp(['db', 'query', `DELETE FROM ${p}bn_posts WHERE id=${postId};`]).catch(() => '');
            }
        }
    });
});
