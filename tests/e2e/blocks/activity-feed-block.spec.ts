import { test, expect } from '../_fixtures/auth.fixture';
import { createPage, deletePage } from '../_fixtures/wp';

/**
 * J-806 — the Activity Feed block spaces its post cards.
 *
 * The bug: post cards render as direct children of .bn-block-activity-feed, but only
 * .bn-feed-list (the home/profile feed) had the flex+gap treatment, so inside the
 * block the cards sat flush with no vertical rhythm.
 *
 * Proven by EFFECT, not just a computed-style read: with two or more cards present
 * there is a real vertical gap between consecutive cards (card 2's top is below card
 * 1's bottom by a non-trivial amount). A regression that drops the gap rule collapses
 * that space and fails here. Falls back to the computed gap when the feed has <2
 * cards on the harness.
 */
test.describe('blocks / activity-feed spacing (J-806)', () => {
    test('J-806 Activity Feed block leaves vertical space between post cards', async ({
        authenticatedPage: page,
    }) => {
        const rec = await createPage('<!-- wp:buddynext/activity-feed /-->', {
            title: 'E2E Activity Feed Block',
            slug: 'e2e-activity-feed-block',
        });
        try {
            await page.goto(rec.url, { waitUntil: 'domcontentloaded' });

            const wrapper = page.locator('.bn-block-activity-feed').first();
            await expect(wrapper).toBeVisible({ timeout: 10_000 });

            const cards = wrapper.locator('.bn-post-card');
            const n = await cards.count();

            if (n >= 2) {
                // EFFECT: a genuine gap between the first two cards.
                const a = await cards.nth(0).boundingBox();
                const b = await cards.nth(1).boundingBox();
                expect(a && b).toBeTruthy();
                if (a && b) {
                    expect(b.y - (a.y + a.height)).toBeGreaterThan(4);
                }
            } else {
                // Sparse feed on the harness: assert the layout that produces the gap.
                const style = await wrapper.evaluate((el) => {
                    const cs = getComputedStyle(el);
                    return { display: cs.display, gap: parseFloat(cs.rowGap || cs.gap || '0') };
                });
                expect(style.display).toBe('flex');
                expect(style.gap).toBeGreaterThan(0);
            }
        } finally {
            await deletePage(rec.id);
        }
    });
});
