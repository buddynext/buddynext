import { test, expect, type Page } from '@playwright/test';
import { loginAs } from '../_fixtures/actor';
import { ensureUser, userId, resetPair, dbCount, tablePrefix, setUserMeta } from '../_fixtures/wp';

/**
 * J-700 / J-701 keyboard focus after Follow and Connect (card 10526152728).
 *
 * Covers: cap-follow-people-and-connect-mutually
 * Roles: admin
 *
 * A keyboard member presses Follow or Connect with Enter. The button disables
 * itself (Follow) or is swapped for "Pending" (Connect) while the request runs;
 * before keepFocus() (assets/js/shell/focus-trap.js) focus dropped to <body> and
 * the member was sent back to the top of the page. Each test proves the effect
 * (the relationship row) AND that focus stayed on the control: the same Follow
 * button once re-enabled, the Pending (withdraw) button after Connect.
 */

const A_LOGIN = process.env.BN_TEST_USER ?? 'varundubey';
const B_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'bn_e2e_target';

const HERO = '.bn-pf-hero';
const FOLLOW_BTN = `${HERO} .bn-pf-actions button[data-wp-on--click="actions.toggleFollow"]`;
const CONNECT_BTN = `${HERO} button[data-wp-on--click="actions.connect"]`;
const WITHDRAW_BTN = `${HERO} button[data-wp-on--click="actions.withdrawRequest"]`;
// The optional connection-note dialog (bnConnectNoteDialog) when the site turns it on.
const NOTE_SEND = '.bn-modal__panel[role="dialog"]:visible .bn-modal__foot .bn-btn:last-child';

let A_ID = 0;
let B_ID = 0;

test.beforeAll(async () => {
    B_ID = await ensureUser(B_LOGIN, 'bn_e2e_target@example.com', 'Jordan Ellis');
    A_ID = await userId(A_LOGIN);
    expect(A_ID, `actor "${A_LOGIN}" must exist`).toBeGreaterThan(0);
    await setUserMeta(B_ID, 'bn_onboarding_complete', '1');
});

test.beforeEach(async () => {
    await resetPair(A_ID, B_ID);
});

test.afterAll(async () => {
    await resetPair(A_ID, B_ID);
});

/** True when the focused element matches the selector. */
async function focusIs(page: Page, selector: string): Promise<boolean> {
    return page.evaluate((s) => {
        const el = document.activeElement;
        return !!el && el !== document.body && el.matches(s);
    }, selector);
}

test.describe('profile / focus after Follow and Connect', () => {
    test('J-700 Follow by keyboard keeps focus on the button', async ({ page }) => {
        await loginAs(page, A_LOGIN);
        await page.goto(`/members/${B_LOGIN}/`);
        const follow = page.locator(FOLLOW_BTN).first();
        await expect(follow).toBeVisible();
        await follow.focus();

        await Promise.all([
            page.waitForResponse(
                (r) => r.url().includes(`/users/${B_ID}/follow`) && r.request().method() === 'POST',
                { timeout: 10_000 }
            ),
            page.keyboard.press('Enter'),
        ]);
        await expect(follow).toBeEnabled();

        const p = await tablePrefix();
        expect(
            await dbCount(`SELECT COUNT(*) FROM ${p}bn_follows WHERE follower_id=${A_ID} AND following_id=${B_ID}`),
            'Enter must create the follow row'
        ).toBe(1);
        await expect.poll(() => focusIs(page, FOLLOW_BTN), { message: 'focus is back on Follow' }).toBe(true);
    });

    test('J-701 Connect by keyboard moves focus to Pending', async ({ page }) => {
        await loginAs(page, A_LOGIN);
        await page.goto(`/members/${B_LOGIN}/`);
        const connect = page.locator(CONNECT_BTN).first();
        await expect(connect).toBeVisible();
        await connect.focus();

        const sent = page.waitForResponse(
            (r) => r.url().includes(`/users/${B_ID}/connect`) && r.request().method() === 'POST',
            { timeout: 10_000 }
        );
        await page.keyboard.press('Enter');
        // A site with the optional note turned on asks first; send without one.
        const note = page.locator(NOTE_SEND);
        if (await note.isVisible().catch(() => false)) {
            await note.click();
        }
        await sent;

        const p = await tablePrefix();
        expect(
            await dbCount(
                `SELECT COUNT(*) FROM ${p}bn_connections WHERE requester_id=${A_ID} AND recipient_id=${B_ID} AND status='pending'`
            ),
            'Connect must create a pending request'
        ).toBe(1);
        await expect(page.locator(WITHDRAW_BTN).first()).toBeVisible();
        await expect.poll(() => focusIs(page, WITHDRAW_BTN), { message: 'focus lands on Pending' }).toBe(true);
    });
});
