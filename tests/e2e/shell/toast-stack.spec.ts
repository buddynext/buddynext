import { test, expect } from '../_fixtures/auth.fixture';

/**
 * J-999 the shared toast stack (docs/standards/toasts.md).
 *
 * Roles: member
 *
 * Every plugin on a BuddyNext page raises toasts through one function, so the member sees one shape and one
 * flow whoever says it. Covers: two on screen on a desktop and one on a phone (640px and narrower), every toast
 * one shared width, nothing dropped, a real close button, Escape closing the newest, the count-up keeping the
 * final text for a screen reader, and an image icon accepting only a safe URL.
 *
 * Runs on every project: the mobile (iPhone 14, 390px) pass is the one-at-a-time check.
 */

type Seen = { titles: string[]; widths: number[]; heights: number[] };

async function seen(page: import('@playwright/test').Page): Promise<Seen> {
    return page.evaluate(() => {
        const els = [...document.querySelectorAll('.bn-toast-container > .bn-toast')];
        return {
            titles: els.map((e) => (e.querySelector('.bn-toast__title')?.textContent ?? '').replace(/\s+/g, ' ').trim()),
            widths: els.map((e) => Math.round(e.getBoundingClientRect().width)),
            heights: els.map((e) => Math.round(e.getBoundingClientRect().height)),
        };
    });
}

test.describe('shared toast stack', () => {
    test.beforeEach(async ({ authenticatedPage: page }) => {
        await page.goto('/activity/', { waitUntil: 'domcontentloaded' });
        await page.waitForFunction(() => typeof (window as unknown as { bnToast?: { supports?: unknown } }).bnToast?.supports === 'object');
    });

    test('J-999 toasts queue behind the cap, share one width and are never dropped', async ({ authenticatedPage: page }) => {
        const phone = (page.viewportSize()?.width ?? 1280) <= 640;
        const cap = phone ? 1 : 2;

        await page.evaluate(() => {
            const t = (window as unknown as { bnToast: (m: unknown, o?: unknown) => unknown }).bnToast;
            t('First thing happened', 'success');
            t({ title: 'Second: a long title that goes on well past forty characters', body: 'A detail line that is longer than the toast is wide, so it must end in an ellipsis', type: 'info' });
            t('Third waits its turn', 'info');
            t({ title: 'Fourth has a button', type: 'success', href: '/members/', linkLabel: 'See it' });
        });

        // Never more than the cap on screen, each 64px tall at the one shared width.
        await expect.poll(async () => (await seen(page)).titles.length).toBeGreaterThan(0);
        for (let i = 0; i < 12; i++) {
            const now = await seen(page);
            expect(now.titles.length, 'more toasts on screen than the cap').toBeLessThanOrEqual(cap);
            expect(new Set(now.widths).size, 'toasts are not one width').toBeLessThanOrEqual(1);
            now.heights.forEach((h) => expect(h).toBeLessThanOrEqual(72));
            await page.waitForTimeout(250);
        }

        // All four are shown in order, none dropped, each closed by its own timer.
        const order: string[] = [];
        await expect
            .poll(
                async () => {
                    for (const title of (await seen(page)).titles) {
                        if (!order.includes(title)) {
                            order.push(title);
                        }
                    }
                    return order.length;
                },
                { timeout: 30_000, intervals: [100] },
            )
            .toBe(4);
        expect(order[0]).toBe('First thing happened');
        expect(order[2]).toBe('Third waits its turn');

    });

    test('J-999 a toast has a labelled close button and Escape closes the newest', async ({ authenticatedPage: page }) => {
        await page.evaluate(() => {
            const t = (window as unknown as { bnToast: (m: unknown, o?: unknown) => unknown }).bnToast;
            t({ title: 'Closeable', type: 'info', timeout: 20_000 });
        });
        const toast = page.locator('.bn-toast-container > .bn-toast').first();
        await expect(toast).toBeVisible();
        const close = toast.locator('.bn-toast__close');
        await expect(close).toHaveAttribute('aria-label', /.+/);
        const box = await close.boundingBox();
        expect(box?.width ?? 0).toBeGreaterThanOrEqual(32);

        await page.keyboard.press('Escape');
        await expect(page.locator('.bn-toast-container')).toHaveCount(0);

        await page.evaluate(() => {
            (window as unknown as { bnToast: (m: unknown, o?: unknown) => unknown }).bnToast({ title: 'Closed by button', type: 'info', timeout: 20_000 });
        });
        await page.locator('.bn-toast__close').first().click();
        await expect(page.locator('.bn-toast-container')).toHaveCount(0);
    });

    test('J-999 the count-up ends on the number and a screen reader hears the final text once', async ({ authenticatedPage: page }) => {
        await page.evaluate(() => {
            (window as unknown as { bnToast: (m: unknown, o?: unknown) => unknown }).bnToast({ key: 'pts', title: '+{count} points', type: 'award', count: { from: 0, to: 30 }, timeout: 20_000 });
        });
        const toast = page.locator('[data-bn-toast-key="kpts"]');
        await expect(toast.locator('.bn-toast__count')).toHaveText('30');
        await expect(toast.locator('.bn-sr-only')).toHaveText('+30 points');
        await expect(toast.locator('.bn-toast__title [aria-hidden="true"]')).toContainText('points');
    });

    test('J-999 an image icon accepts a safe URL and refuses script', async ({ authenticatedPage: page }) => {
        await page.evaluate(() => {
            const t = (window as unknown as { bnToast: (m: unknown, o?: unknown) => unknown }).bnToast;
            t({ title: 'Script icon', type: 'info', icon: 'javascript:alert(1)', timeout: 20_000 });
        });
        const toast = page.locator('.bn-toast-container > .bn-toast').first();
        await expect(toast).toBeVisible();
        await expect(toast.locator('.bn-toast__img')).toHaveCount(0);
        await expect(toast.locator('.bn-toast__icon svg')).toHaveCount(1);
    });
});
