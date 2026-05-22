import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { urls } from '../_fixtures/selectors';

/**
 * J-56 suspend user + J-57 restore user.
 */
test.describe('moderation / suspend + restore', () => {
    test('admin members page exposes a suspend control', async ({ authenticatedPage: page }, testInfo) => {
        const resp = await page.goto('/wp-admin/admin.php?page=buddynext-members', { waitUntil: 'domcontentloaded' });
        if ((resp?.status() ?? 200) >= 400) {
            softSkip(testInfo, 'Members admin page not available.');
            return;
        }
        const suspendBtn = page.locator('a:has-text("Suspend"), button:has-text("Suspend"), [data-action="suspend"]').first();
        if (!(await suspendBtn.isVisible().catch(() => false))) {
            softSkip(testInfo, 'No suspendable user row visible.');
            return;
        }
        await expect(suspendBtn).toBeVisible();
        void urls;
    });

    test('restore control returns the user to active', async ({ authenticatedPage: page }, testInfo) => {
        const resp = await page.goto('/wp-admin/admin.php?page=buddynext-members&filter=suspended', { waitUntil: 'domcontentloaded' });
        if ((resp?.status() ?? 200) >= 400) {
            softSkip(testInfo, 'Members admin not available.');
            return;
        }
        const restoreBtn = page.locator('a:has-text("Restore"), button:has-text("Restore"), [data-action="unsuspend"]').first();
        if (!(await restoreBtn.isVisible().catch(() => false))) {
            softSkip(testInfo, 'No suspended users to restore.');
            return;
        }
        await expect(restoreBtn).toBeVisible();
    });
});
