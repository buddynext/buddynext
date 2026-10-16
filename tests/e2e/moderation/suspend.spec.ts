import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { dbCount, ensureUser, tablePrefix, wp } from '../_fixtures/wp';
import type { Locator, Page } from '@playwright/test';
import { dbSeedingAvailable } from '../_fixtures/db.fixture';

/**
 * J-56 suspend user + J-57 restore user.
 *
 * Covers: cap-warn-suspend-or-shadow-ban
 * Roles: admin
 *
 * Admin-only: drives the suspend/unsuspend controls on the wp-admin members
 * screen against a throwaway member and reads the bn_user_suspensions row back
 * after each. The suspended member's own experience (login block, appeal) is
 * moderation/appeal.spec.ts.
 */
const TARGET_LOGIN = 'bn_e2e_suspendee';
const TARGET_NAME = 'Marcus Hale';
const MEMBERS_ADMIN = '/wp-admin/admin.php?page=buddynext-members';

/** Active (not lifted, not expired) suspension rows for one member, or for the site when uid is 0. */
async function activeSuspensions(uid = 0): Promise<number> {
    const p = await tablePrefix();
    return dbCount(
        `SELECT COUNT(*) FROM ${p}bn_user_suspensions WHERE lifted_at IS NULL AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())` +
            (uid > 0 ? ` AND user_id = ${uid}` : '')
    );
}

/** Lift any suspension on the throwaway member so every run starts and ends unsuspended. */
async function liftTarget(uid: number): Promise<void> {
    await wp([
        'eval',
        `$a = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) ); ( new \\BuddyNext\\Moderation\\ModerationService() )->unsuspend_user( ${uid}, (int) $a[0] );`,
    ]).catch(() => undefined);
}

/**
 * Open a member row's kebab. Suspend / Unsuspend are `role="menuitem"` buttons inside
 * `.bn-more-dropdown` (Members.php:1921-1944), hidden until `.bn-more-btn` is clicked; the old
 * `button:has-text("Suspend")` + isVisible() check never saw them, so both journeys
 * soft-skipped on every run.
 */
async function openRowMenu(page: Page, row: Locator): Promise<Locator> {
    await row.locator('.bn-more-btn').click();
    // more-menu.js moves the open dropdown to <body> (so the table cannot clip it): look
    // for it on the page, not inside the row.
    const menu = page.locator('.bn-more-dropdown.is-open[role="menu"]');
    await expect(menu).toBeVisible({ timeout: 5_000 });
    return menu;
}

function targetRow(page: Page): Locator {
    return page.locator('tbody tr').filter({ hasText: TARGET_NAME }).first();
}

test.describe('moderation / suspend + restore', () => {
    let targetId = 0;

    test.beforeAll(async () => {
        targetId = await ensureUser(TARGET_LOGIN, 'bn_e2e_suspendee@example.com', TARGET_NAME);
        expect(targetId, `member "${TARGET_LOGIN}" must exist`).toBeGreaterThan(0);
        await liftTarget(targetId);
    });

    test.afterAll(async () => {
        if (targetId > 0) {
            await liftTarget(targetId);
        }
    });

    test('admin members page exposes a suspend control', async ({ authenticatedPage: page }, testInfo) => {
        const resp = await page.goto(`${MEMBERS_ADMIN}&s=${TARGET_LOGIN}`, { waitUntil: 'domcontentloaded' });
        if ((resp?.status() ?? 200) >= 400) {
            softSkip(testInfo, 'Members admin page not available.');
            return;
        }
        // A retry starts from the suspension the first attempt left behind: lift it first.
        await liftTarget(targetId);
        expect(await activeSuspensions(targetId), 'throwaway member should start unsuspended').toBe(0);
        await page.reload({ waitUntil: 'domcontentloaded' });

        const row = targetRow(page);
        await expect(row, `members admin did not list "${TARGET_NAME}"`).toBeVisible({ timeout: 10_000 });
        const menu = await openRowMenu(page, row);
        const suspendBtn = menu.getByRole('menuitem', { name: 'Suspend', exact: true });
        await expect(suspendBtn).toBeVisible();
        await suspendBtn.click();

        // The suspend form opts into the confirm modal's reason fields (data-bn-confirm-suspend).
        const modal = page.locator('#bn-members-confirm-modal');
        await expect(modal).toBeVisible({ timeout: 5_000 });
        await modal.locator('[data-bn-confirm-reason-field]').selectOption({ index: 1 });
        await Promise.all([
            page.waitForURL(/action=suspended/, { timeout: 15_000 }),
            modal.locator('[data-bn-confirm-accept]').click(),
        ]);

        // EFFECT: one active suspension row exists for THIS member, with a reason.
        expect(await activeSuspensions(targetId), 'no active suspension row after the Suspend control').toBe(1);
        const p = await tablePrefix();
        expect(
            await dbCount(`SELECT COUNT(*) FROM ${p}bn_user_suspensions WHERE user_id = ${targetId} AND lifted_at IS NULL AND reason <> ''`),
            'suspension row carries no reason'
        ).toBe(1);

        // EFFECT: the list agrees: the row now reads Suspended and offers Unsuspend.
        await page.goto(`${MEMBERS_ADMIN}&status=suspended&s=${TARGET_LOGIN}`, { waitUntil: 'domcontentloaded' });
        await expect(targetRow(page).locator('.bn-badge', { hasText: 'Suspended' }).first()).toBeVisible({ timeout: 10_000 });
    });

    test('restore control returns the user to active', async ({ authenticatedPage: page }, testInfo) => {
        const resp = await page.goto(`${MEMBERS_ADMIN}&status=suspended`, { waitUntil: 'domcontentloaded' });
        if ((resp?.status() ?? 200) >= 400) {
            softSkip(testInfo, 'Members admin not available.');
            return;
        }
        // The Members admin filters by `status=suspended` (Members::list_members) and the row
        // action reads "Unsuspend" (`bn_unsuspend_member`); the old `filter=` URL and "Restore"
        // label matched nothing, so this journey soft-skipped on every run.
        const firstRow = page.locator('tbody tr').first();
        let visible = false;
        if (await firstRow.locator('.bn-more-btn').isVisible().catch(() => false)) {
            const firstMenu = await openRowMenu(page, firstRow);
            visible = await firstMenu.getByRole('menuitem', { name: 'Unsuspend', exact: true }).isVisible().catch(() => false);
        }
        // EFFECT: the control follows the data: an active suspension row means an Unsuspend control, and the reverse.
        if (dbSeedingAvailable()) {
            const active = await activeSuspensions();
            expect(visible, `${active} active suspensions in the table, Unsuspend control visible: ${visible}`).toBe(active > 0);
        }

        // Round trip on the throwaway member: suspend through the service (setup), lift
        // through the screen (the action under test), read the row back.
        if ((await activeSuspensions(targetId)) === 0) {
            const seeded = await wp([
                'eval',
                `$a = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) ); $r = ( new \\BuddyNext\\Moderation\\ModerationService() )->suspend_user( ${targetId}, (int) $a[0], 'e2e restore fixture' ); echo is_wp_error( $r ) ? 'err:' . $r->get_error_code() : 'ok';`,
            ]);
            expect(seeded.trim(), 'could not seed a suspension for the restore leg').toBe('ok');
        }
        expect(await activeSuspensions(targetId)).toBe(1);
        const siteBefore = await activeSuspensions();

        await page.goto(`${MEMBERS_ADMIN}&status=suspended&s=${TARGET_LOGIN}`, { waitUntil: 'domcontentloaded' });
        const row = targetRow(page);
        await expect(row, 'suspended member missing from the status=suspended list').toBeVisible({ timeout: 10_000 });
        const menu = await openRowMenu(page, row);
        const restoreBtn = menu.getByRole('menuitem', { name: 'Unsuspend', exact: true });
        await expect(restoreBtn).toBeVisible();
        await Promise.all([page.waitForURL(/action=unsuspended/, { timeout: 15_000 }), restoreBtn.click()]);

        // EFFECT: the member's suspension is lifted (lifted_at set), and only theirs.
        expect(await activeSuspensions(targetId), 'suspension still active after Unsuspend').toBe(0);
        const p = await tablePrefix();
        expect(
            await dbCount(`SELECT COUNT(*) FROM ${p}bn_user_suspensions WHERE user_id = ${targetId} AND lifted_at IS NOT NULL`),
            'no lifted suspension row for the member'
        ).toBeGreaterThan(0);
        expect(await activeSuspensions(), 'Unsuspend touched another member\'s suspension').toBe(siteBefore - 1);

        // EFFECT: the member is back in the default list as Active.
        await page.goto(`${MEMBERS_ADMIN}&s=${TARGET_LOGIN}`, { waitUntil: 'domcontentloaded' });
        await expect(targetRow(page).locator('.bn-badge', { hasText: 'Active' }).first()).toBeVisible({ timeout: 10_000 });
    });
});
