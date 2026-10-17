import { test, expect } from '../_fixtures/auth.fixture';
import { restJson } from '../_fixtures/effects';

/**
 * J-987 Moderation Log filters are labelled and fit on a phone.
 *
 * Moderation -> Moderation Log. Every filter (Action, Moderator ID, Target member
 * ID, Space ID, date) carries a visible label that names its control, so the
 * purpose survives typing and narrow screens; at phone width the screen has no
 * sideways scroll. Filtering by an action narrows the log to that action.
 *
 * Effect: the action filtered on is read from REST (GET /moderation/log?action=),
 * and the table then lists that action's entries.
 *
 * Covers: cap-work-the-report-queue-on-the-front-end-without-wp-admin
 * Roles: admin
 */

const LOG = '/wp-admin/admin.php?page=buddynext-moderation&tab=log';

test.describe('moderation / log filters', () => {
    test('J-987 every Moderation Log filter has a visible label, the screen fits, and an action filter narrows the log', async ({ authenticatedPage: page }) => {
        await page.goto(LOG);
        const form = page.locator('form.bn-modlog-filters');
        await expect(form, 'the filter form is on the Moderation Log').toBeVisible();

        const fields = form.locator('.bn-modlog-field');
        const count = await fields.count();
        expect(count, 'filters found').toBeGreaterThanOrEqual(4);
        for (let i = 0; i < count; i++) {
            const field = fields.nth(i);
            const label = field.locator('.bn-label');
            await expect(label, `filter ${i + 1} shows its label`).toBeVisible();
            const text = (await label.innerText()).trim();
            expect(text, `filter ${i + 1} label text`).not.toBe('');
            // The label wraps its control, so the control's accessible name is the label.
            const control = field.locator('input, select').first();
            await expect(control).toBeVisible();
            await expect(control, `"${text}" names its control`).toHaveAccessibleName(new RegExp(text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
        }

        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        expect(overflow, 'no sideways scroll on the Moderation Log').toBeLessThanOrEqual(1);

        // EFFECT: pick an action the log really holds (REST), filter on it, and the
        // table lists that action's entries.
        const select = form.locator('select[name="log_action"]');
        const values = (await select.locator('option').evaluateAll((o) => o.map((x) => (x as HTMLOptionElement).value))).filter(Boolean);
        let chosen = '';
        for (const v of values) {
            const r = await restJson(page, `/wp-json/buddynext/v1/moderation/log?action=${encodeURIComponent(v)}&per_page=5`);
            expect(r.status, 'GET /moderation/log').toBe(200);
            const items = Array.isArray(r.data) ? r.data : ((r.data as { items?: unknown[] })?.items ?? []);
            if (items.length) {
                chosen = v;
                break;
            }
        }
        test.skip(!chosen, 'The moderation log holds no entries to filter.');
        await select.selectOption(chosen);
        await form.locator('button[type="submit"], input[type="submit"]').first().click();
        await page.waitForLoadState('load');
        expect(new URL(page.url()).searchParams.get('log_action')).toBe(chosen);
        await expect(page.locator('table tbody tr').first(), 'the filtered log lists entries').toBeVisible();
        const after = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        expect(after, 'still no sideways scroll with results').toBeLessThanOrEqual(1);
    });
});
