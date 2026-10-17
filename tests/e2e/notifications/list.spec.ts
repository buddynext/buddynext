import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';
import { idsOf, restList, unreadCount } from '../_fixtures/effects';
import { bnNonce } from '../_fixtures/spaces-rest';
import { dbScalar, tablePrefix, userId, wp } from '../_fixtures/wp';
import type { Page } from '@playwright/test';

// The notification row is templates/parts/notification-row.php: `[data-notif-id]`, unread
// rows carry `bn-notif-row--unread`. The old `.bn-notif-item.is-unread` matched nothing, so
// J-49 and J-50 soft-skipped on every run.
// The row root; its action buttons carry data-notif-id too.
const ROW = '[role="listitem"][data-notif-id]';
const UNREAD_ROW = '.bn-notif-row--unread[data-notif-id]';
const MARK_ALL = 'button[data-wp-on--click="actions.markAllRead"]';

/**
 * Unread count through page.request (not page.evaluate): a row click navigates, and a
 * read tied to the page's JS context dies with "Execution context was destroyed".
 */
// The Unread list, not /unread-count: that route is the bell badge, which opening
// the list clears ("seen") while the rows stay unread until clicked.
async function unreadIdsByRequest(page: Page, nonce: string): Promise<number[]> {
    const res = await page.request.get('/wp-json/buddynext/v1/me/notifications?filter=unread&per_page=50', {
        headers: { 'X-WP-Nonce': nonce },
    });
    return idsOf(await res.json().catch(() => null));
}

/** Seed one unread in-app notification for a member; returns its id (0 on failure). */
async function seedUnread(recipientId: number, senderId: number): Promise<number> {
    const out = await wp([
        'eval',
        `add_filter( 'buddynext_notification_force_on_site', '__return_true' ); echo (int) buddynext_service( 'notifications' )->create( array( 'recipient_id' => ${recipientId}, 'sender_id' => ${senderId}, 'type' => 'bn.new_follower', 'object_type' => 'user', 'object_id' => ${senderId} ) );`,
    ]).catch(() => '0');
    return parseInt(out.trim(), 10) || 0;
}

/**
 * J-48 list + J-49 mark read + J-50 mark all read.
 *
 * Covers: cap-see-in-app-notifications-mark-them-read-and-set-preferences
 * it jumps straight to "Send push notifications? PRO". Documentation gap,
 * not a missing test - see FRICTION in the role-coverage report.
 * Roles: member
 */
test.describe('notifications / list', () => {
    test('J-48 notifications page renders list or empty state', async ({ authenticatedPage: page }) => {
        await page.goto(urls.notifications);
        await expect(page.locator(sel.app)).toBeVisible();
        const list = page.locator(sel.notifList).first();
        const empty = page.locator('.bn-notif-empty, [data-notif-empty]').first();
        expect((await list.isVisible().catch(() => false)) || (await empty.isVisible().catch(() => false))).toBeTruthy();
        // EFFECT: rows on screen only when the notifications route has rows for this member.
        const rest = await restList(page, '/me/notifications?per_page=20');
        expect(rest.status, 'GET /me/notifications').toBe(200);
        const rows = await page.locator(ROW).count();
        expect(rows > 0, `screen rows ${rows}, REST rows ${rest.items.length}`).toBe(rest.items.length > 0);
    });

    test('J-49 clicking an unread notification marks it read', async ({ authenticatedPage: page }) => {
        // Seed our own unread row: J-50 (mark all read) empties the inbox, so relying on a
        // leftover unread row made this journey soft-skip on every run after it.
        const me = await userId(process.env.BN_TEST_USER ?? 'varundubey');
        const sender = await userId(process.env.BN_TEST_OTHER_USER ?? 'alice');
        expect(me, 'test member must exist').toBeGreaterThan(0);
        expect(sender, 'sender member must exist').toBeGreaterThan(0);
        const seeded = await seedUnread(me, sender);
        expect(seeded, 'could not seed an unread notification').toBeGreaterThan(0);

        try {
            await page.goto(urls.notifications);
            const unread = page.locator(`${UNREAD_ROW}[data-notif-id="${seeded}"]`);
            await expect(unread, 'seeded unread notification did not render as unread').toBeVisible({ timeout: 10_000 });

            // Nonce and baseline BEFORE the click: the row navigates to its object.
            const nonce = await bnNonce(page);
            expect(await unreadIdsByRequest(page, nonce), 'the Unread list holds the seeded row').toContain(seeded);
            await unread.click();

            // EFFECT: the row left the server's Unread list.
            await expect.poll(async () => (await unreadIdsByRequest(page, nonce)).includes(seeded), { timeout: 8_000 }).toBe(false);
            // EFFECT: the row itself reads is_read=1 in the table.
            const p = await tablePrefix();
            expect(await dbScalar(`SELECT is_read FROM ${p}bn_notifications WHERE id = ${seeded}`)).toBe('1');
            await page.goto(urls.notifications);
            await expect(page.locator(`${UNREAD_ROW}[data-notif-id="${seeded}"]`)).toHaveCount(0);
        } finally {
            await wp(['eval', `buddynext_service( 'notifications' )->delete( ${seeded}, ${me} );`]).catch(() => undefined);
        }
    });

    test('J-50 mark all read button drops unread count', async ({ authenticatedPage: page }) => {
        // The hero button is `actions.markAllRead` (parts/notifications-hero.php:109) and only
        // renders while something is unread. The shared `[data-action="mark-all-read"]` matched
        // nothing, so this journey soft-skipped on every run; seed one unread row instead.
        const me = await userId(process.env.BN_TEST_USER ?? 'varundubey');
        const sender = await userId(process.env.BN_TEST_OTHER_USER ?? 'alice');
        const seeded = await seedUnread(me, sender);
        expect(seeded, 'could not seed an unread notification').toBeGreaterThan(0);

        try {
            await page.goto(urls.notifications);
            const btn = page.locator(MARK_ALL).first();
            await expect(btn, 'mark-all-read button missing while a row is unread').toBeVisible({ timeout: 10_000 });
            await btn.click();
            await expect.poll(async () => {
                return page.locator(UNREAD_ROW).count();
            }, { timeout: 5_000 }).toBe(0);
            // EFFECT: the bell's own route says zero too.
            await expect.poll(() => unreadCount(page), { timeout: 5_000 }).toBe(0);
            // EFFECT: no unread row is left in the table for this member.
            const p = await tablePrefix();
            expect(await dbScalar(`SELECT COUNT(*) FROM ${p}bn_notifications WHERE recipient_id = ${me} AND is_read = 0`)).toBe('0');
        } finally {
            await wp(['eval', `buddynext_service( 'notifications' )->delete( ${seeded}, ${me} );`]).catch(() => undefined);
        }
    });
});
