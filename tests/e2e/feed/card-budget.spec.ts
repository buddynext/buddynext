import { test, expect } from '../_fixtures/auth.fixture';
import { urls } from '../_fixtures/selectors';

/**
 * J-981 post-card weight budget (feed rendering plan, Phase C).
 *
 * Measure-only guard: it changes nothing on a site, it stops the card from
 * quietly growing heavier one feature at a time. Baseline 2026-10-05 on the
 * home feed (15 cards): ~20.4 KB and ~119 elements per card on average, 139
 * at most, and zero inline icon shapes on a hub page (icons are drawn once,
 * IconService sprite). Budgets sit ~17% above the averages; the per-card
 * ceiling catches one runaway variant. A deliberate increase raises the
 * budget here with the reason in the commit.
 *
 * Server time is recorded as an annotation, not asserted: it depends on the
 * machine. Compare it run to run on the Docker scale lab.
 *
 * Roles: member
 */
const BUDGET = {
    avgBytes: 24_000,
    avgElements: 140,
    maxElements: 260,
};

test.describe('feed / post-card budget (J-981)', () => {
    test('J-981 cards stay within their weight budget; icons stay drawn once', async ({ authenticatedPage: page }, info) => {
        const response = await page.goto(urls.feed);
        const timing = await page.evaluate(() => {
            const nav = performance.getEntriesByType('navigation')[0] as PerformanceNavigationTiming | undefined;
            return nav ? Math.round(nav.responseStart - nav.requestStart) : -1;
        });
        expect(response?.status()).toBe(200);

        const m = await page.evaluate(() => {
            const cards = [...document.querySelectorAll('article.bn-post-card')];
            const bytes = cards.map((c) => c.outerHTML.length);
            const els = cards.map((c) => c.querySelectorAll('*').length);
            const sum = (a: number[]) => a.reduce((x, y) => x + y, 0);
            return {
                cards: cards.length,
                avgBytes: cards.length ? Math.round(sum(bytes) / cards.length) : 0,
                avgElements: cards.length ? Math.round(sum(els) / cards.length) : 0,
                maxElements: cards.length ? Math.max(...els) : 0,
                inlineIconShapes: cards.reduce(
                    (n, c) => n + c.querySelectorAll('svg.bn-icon > path, svg.bn-icon > circle, svg.bn-icon > line, svg.bn-icon > rect, svg.bn-icon > polyline, svg.bn-icon > polygon').length,
                    0
                ),
                pageElements: document.querySelectorAll('*').length,
            };
        });

        info.annotations.push({
            type: 'budget',
            description: `cards=${m.cards} avgBytes=${m.avgBytes} avgElements=${m.avgElements} maxElements=${m.maxElements} pageElements=${m.pageElements} serverMs=${timing}`,
        });

        expect(m.cards, 'the feed has cards to measure').toBeGreaterThan(0);
        expect(m.avgBytes, `average card ${m.avgBytes} B (budget ${BUDGET.avgBytes})`).toBeLessThanOrEqual(BUDGET.avgBytes);
        expect(m.avgElements, `average card ${m.avgElements} elements (budget ${BUDGET.avgElements})`).toBeLessThanOrEqual(BUDGET.avgElements);
        expect(m.maxElements, `heaviest card ${m.maxElements} elements (ceiling ${BUDGET.maxElements})`).toBeLessThanOrEqual(BUDGET.maxElements);
        expect(m.inlineIconShapes, 'icons on a hub page are drawn once (sprite), not inline').toBe(0);
    });

    // "Load more" swaps only the feed region. Shapes first used on a later page
    // must travel with it, or those icons draw blank (card 10369460065).
    for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
        test(`J-981 every icon still has its shape after two Load more steps (${viewport.width}px)`, async ({ authenticatedPage: page }) => {
            await page.setViewportSize(viewport);
            await page.goto(urls.feed);
            const unresolved = () =>
                page.evaluate(() => {
                    const ids = new Set([...document.querySelectorAll('symbol[id]')].map((s) => s.id));
                    return [...document.querySelectorAll('use')]
                        .map((u) => u.getAttribute('href') || '')
                        .filter((h) => h.startsWith('#bn-i-') && !ids.has(h.slice(1)));
                });
            expect(await unresolved(), 'first page').toEqual([]);

            for (let step = 1; step <= 2; step++) {
                const more = page.locator('#bn-load-more .bn-load-more__btn');
                if (!(await more.count())) break;
                const before = await page.locator('article.bn-post-card').count();
                await more.click();
                await expect.poll(() => page.locator('article.bn-post-card').count()).toBeGreaterThan(before);
                expect(await unresolved(), `after Load more ${step}`).toEqual([]);
            }
        });
    }
});
