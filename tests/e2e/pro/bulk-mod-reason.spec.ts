import { test, expect } from '../_fixtures/auth.fixture';
import { resolveOtherMemberSlug } from '../_fixtures/precondition';
import { dbCount, tablePrefix, userId } from '../_fixtures/wp';

/**
 * J-990 bulk Warn and Suspend ask for a reason instead of failing every member (Pro).
 *
 * Written from the admin's promise: "if I forget the reason, the screen tells me so
 * and nothing happens to anyone." A warning or suspension without a reason is refused
 * for each member (the member would get a blank notice), so the bulk form used to come
 * back as "0 succeeded, N failed" with no explanation, and above 50 members every
 * background job failed silently. BulkModAdmin now stops before dispatching and shows
 * one alert naming the missing reason.
 *
 * Effect read-back: the member's moderation log rows and suspensions are counted
 * before and after both actions; neither may change.
 *
 * Covers: cap-warn-suspend-or-shadow-ban
 * Roles: admin
 */
test.describe('pro / bulk moderation reason', () => {
    test.fixme(process.env.BN_PRO !== '1', 'Bulk moderation is a Pro feature. Set BN_PRO=1 to run.');

    test('J-990 an empty reason stops bulk Warn and Suspend with a message and changes nobody', async ({ authenticatedPage: page }) => {
        const login = await resolveOtherMemberSlug(page, 'varundubey');
        expect(login, 'need another member to act on').toBeTruthy();
        const id = await userId(login as string);
        expect(id, 'member id').toBeGreaterThan(0);
        const p = await tablePrefix();
        const standing = async () => ({
            log: await dbCount(`SELECT COUNT(*) FROM ${p}bn_mod_log WHERE target_user_id = ${id} AND action IN ('warn','suspend')`),
            suspensions: await dbCount(`SELECT COUNT(*) FROM ${p}bn_user_suspensions WHERE user_id = ${id}`),
        });
        const before = await standing();

        for (const action of ['bnpro_bulk_warn', 'bnpro_bulk_suspend']) {
            await page.goto('/wp-admin/admin.php?page=buddynext-automation&tab=bulk');
            await page.locator('#bnpro_user_id_list').fill(login as string);
            await page.locator('#bnpro_user_reason').fill('   ');
            await page.locator(`[data-bn-user-action="${action}"]`).click();
            const dialog = page.locator('.bn-dialog-backdrop');
            await expect(dialog).toBeVisible({ timeout: 5_000 });
            await dialog.locator('.bn-dialog__ok').click();
            // Suspend then opens a second dialog that unlocks only once the admin types the member count.
            if (action === 'bnpro_bulk_suspend') {
                const typed = page.locator('.bn-dialog-backdrop', { hasText: 'Confirm how many this affects' });
                await expect(typed).toBeVisible({ timeout: 5_000 });
                await typed.locator('input').first().fill('1');
                await typed.locator('.bn-dialog__ok').click();
            }

            const alert = page.locator('.bn-notice-error[role="alert"]');
            await expect(alert, `${action}: the reason is asked for`).toContainText('Add a reason');
            await expect(page.locator('.bn-notice-success'), `${action}: no "succeeded / failed" summary`).toHaveCount(0);
        }

        // EFFECT: nothing was recorded against the member.
        expect(await standing()).toEqual(before);
    });
});
