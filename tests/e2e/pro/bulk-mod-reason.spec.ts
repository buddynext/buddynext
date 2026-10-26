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
 * background job failed silently. bulk-mod.js now stops before any dialog and shows
 * one toast naming the missing reason, keeping the pasted list.
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
            // The reason is asked for before any confirmation dialog, so the pasted list is never lost.
            await expect(page.getByText('Add a reason first'), `${action}: the reason is asked for`).toBeVisible({ timeout: 5_000 });
            await expect(page.locator('.bn-dialog-backdrop'), `${action}: no confirmation dialog opens`).toHaveCount(0);
            await expect(page.locator('#bnpro_user_id_list'), `${action}: the pasted list is kept`).toHaveValue(login as string);
        }

        // EFFECT: nothing was recorded against the member.
        expect(await standing()).toEqual(before);
    });
});
