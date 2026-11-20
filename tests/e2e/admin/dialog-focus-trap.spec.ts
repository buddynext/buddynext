import { test, expect } from '../_fixtures/auth.fixture';
import { dbSeedingAvailable, setOption, getOption, deleteOption } from '../_fixtures/db.fixture';

/**
 * J-826 admin confirm dialog keyboard contract (card 10530851023).
 *
 * Covers: cap-warn-suspend-or-shadow-ban
 * Roles: admin
 *
 * The shared admin confirm (bn-admin-dialogs.js, here the Restore defaults
 * dialog) is aria-modal, so Tab must stay inside it, Escape must close it, and
 * focus must return to the button that opened it. Effect: Escape cancels, so
 * the changed setting is still stored afterwards.
 */

const TAB_URL = '/wp-admin/admin.php?page=buddynext-settings&tab=moderation';
const KEY = 'buddynext_auto_hide_threshold';

test.describe('admin / confirm dialog focus trap', () => {
    test.skip(!dbSeedingAvailable(), 'Needs WP-CLI seeding (BN_WP_PATH).');

    test.beforeEach(async () => {
        await setOption(KEY, '1');
    });

    test.afterAll(async () => {
        await deleteOption(KEY);
    });

    test('Tab cycles inside the dialog, Escape cancels and returns focus', async ({ authenticatedPage: page }) => {
        await page.goto(TAB_URL, { waitUntil: 'domcontentloaded' });
        const opener = page.locator('[data-bn-restore-defaults]').first();
        await expect(opener).toBeVisible();
        await opener.focus();
        await opener.click();

        const dialog = page.locator('.bn-dialog-backdrop');
        await expect(dialog).toBeVisible();
        await expect(page.locator('.bn-admin-hub__main')).not.toHaveAttribute('tabindex', /.*/);

        // More presses than the dialog has controls: focus never leaves it.
        for (let i = 0; i < 6; i++) {
            await page.keyboard.press('Tab');
            expect(
                await page.evaluate(() => !!document.activeElement?.closest('.bn-dialog-backdrop')),
                `Tab ${i + 1} stays inside the dialog`
            ).toBe(true);
        }
        await page.keyboard.press('Shift+Tab');
        expect(await page.evaluate(() => !!document.activeElement?.closest('.bn-dialog-backdrop'))).toBe(true);

        await page.keyboard.press('Escape');
        await expect(dialog).toHaveCount(0);
        await expect(opener).toBeFocused();
        expect(await getOption(KEY), 'Escape cancels: the setting is not reset').toBe('1');
    });
});
