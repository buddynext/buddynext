import type { Page } from '@playwright/test';
import { test, expect } from '../_fixtures/auth.fixture';
import { createPage, deletePage } from '../_fixtures/wp';

/**
 * J-800 / J-801 — Space Directory + Spaces Showcase blocks: Layout=List actually
 * renders as a list, not a grid.
 *
 * Proven by EFFECT, not by reading the attribute back: the block writes
 * data-layout="list" onto its .bn-sd-grid, but that was cosmetic until a CSS rule
 * consumed it — the grid stayed a multi-column track. So the assertion is the real
 * geometric consequence: with List selected the cards STACK (the second card sits
 * below the first, not beside it) and the grid computes to a single column. A
 * regression that drops the [data-layout="list"] rule puts the cards side-by-side
 * again and fails here.
 *
 * The blocks are placed on an ordinary page (not the /spaces/ hub) because that is
 * where a site owner embeds them and where the defect shipped. Throwaway page torn
 * down in finally.
 */
test.describe('blocks / list layout (J-800, J-801)', () => {
    async function gridIsSingleColumn(page: Page, wrapperSelector: string): Promise<boolean> {
        const grid = page.locator(`${wrapperSelector} .bn-sd-grid`).first();
        await expect(grid).toBeVisible({ timeout: 10_000 });
        // One track in the computed grid template === a single column === a list.
        return grid.evaluate(
            (el) => getComputedStyle(el).gridTemplateColumns.trim().split(/\s+/).length === 1
        );
    }

    async function firstTwoCardsStack(page: Page, wrapperSelector: string): Promise<boolean> {
        const cards = page.locator(`${wrapperSelector} .bn-sd-grid > *`);
        if ((await cards.count()) < 2) {
            return true; // 0-1 cards can't disprove stacking; the column check carries it.
        }
        const a = await cards.nth(0).boundingBox();
        const b = await cards.nth(1).boundingBox();
        if (!a || !b) {
            return false;
        }
        // Stacked: the 2nd card starts at (or below) the 1st card's bottom, and they
        // share the left edge. Side-by-side (the bug) would put b.x well right of a.x.
        return b.y >= a.y + a.height - 4 && Math.abs(b.x - a.x) < 4;
    }

    test('J-800 Space Directory block List layout renders a single stacked column', async ({
        authenticatedPage: page,
    }) => {
        const slug = 'e2e-space-directory-list';
        const pageRec = await createPage(
            '<!-- wp:buddynext/space-directory {"layout":"list"} /-->',
            { title: 'E2E Space Directory List', slug }
        );
        try {
            await page.goto(pageRec.url, { waitUntil: 'domcontentloaded' });
            expect(await gridIsSingleColumn(page, '.wp-block-buddynext-space-directory')).toBe(true);
            expect(await firstTwoCardsStack(page, '.wp-block-buddynext-space-directory')).toBe(true);
        } finally {
            await deletePage(pageRec.id);
        }
    });

    test('J-801 Spaces Showcase block List layout renders a single stacked column', async ({
        authenticatedPage: page,
    }) => {
        const slug = 'e2e-spaces-showcase-list';
        const pageRec = await createPage(
            '<!-- wp:buddynext/spaces-showcase {"layout":"list","count":6} /-->',
            { title: 'E2E Spaces Showcase List', slug }
        );
        try {
            await page.goto(pageRec.url, { waitUntil: 'domcontentloaded' });
            expect(await gridIsSingleColumn(page, '.wp-block-buddynext-spaces-showcase')).toBe(true);
            expect(await firstTwoCardsStack(page, '.wp-block-buddynext-spaces-showcase')).toBe(true);
        } finally {
            await deletePage(pageRec.id);
        }
    });
});
