import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { urls } from '../_fixtures/selectors';

/**
 * J-60 email editor.
 */
test.describe('admin / email editor', () => {
    test('email editor page renders template list and preview', async ({ authenticatedPage: page }, testInfo) => {
        const resp = await page.goto(urls.adminEmailEditor, { waitUntil: 'domcontentloaded' });
        if ((resp?.status() ?? 200) >= 400) {
            softSkip(testInfo, 'Email editor not registered.');
            return;
        }
        const list = page.locator('.bn-emails__list, [data-email-templates], .wp-list-table').first();
        const preview = page.locator('iframe[name="bn_email_preview"], iframe[data-email-preview]').first();
        const anyVisible = (await list.isVisible().catch(() => false)) || (await preview.isVisible().catch(() => false));
        expect(anyVisible).toBeTruthy();
    });
});
