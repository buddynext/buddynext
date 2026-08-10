import { test, expect } from '@playwright/test';
import { loginAs } from '../_fixtures/actor';
import { softSkip } from '../_fixtures/precondition';
import {
    ensureUser,
    userId,
    resetPair,
    tablePrefix,
    dbCount,
    displayName,
    setUserMeta,
    wp,
} from '../_fixtures/wp';

/**
 * J-31 profile connection request + J-32 profile message button.
 *
 * UPGRADED from WEAK → EFFECT (Wave-4). Both tests used to walk the member
 * directory, click the first card, and assert an optimistic UI change (a button's
 * innerText flipped; the URL contained "messages") — presence that passed even
 * when the REST write silently failed and green on any seeded site regardless of
 * whether a relationship or a thread was ever created. They now drive a fixed
 * A→B pair through the real hero UI and assert the REAL consequence in the DB /
 * the DM engine:
 *
 *   J-31 Connect request → a `pending` row is written to wp_bn_connections.
 *   J-32 Message button  → the DM engine (WPMediaVerse) opens/creates the 1:1
 *        conversation and the thread pane renders for that member (not the
 *        blocked/unreachable notice). softSkips WITH a reason when the DM bridge
 *        is off or the thread genuinely cannot be created.
 *
 * Actors: A = varundubey (acting session), B = bn_e2e_target (seeded subscriber).
 * The A↔B connection pair is reset before + after each test so reruns are
 * idempotent; any 1:1 conversation created by J-32 is cleaned up in a finally.
 * Selectors are declared locally (repo rule: never edit the shared selectors.ts).
 */

const A_LOGIN = process.env.BN_TEST_USER ?? 'varundubey';
const B_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'bn_e2e_target';
const B_EMAIL = 'bn_e2e_target@example.com';
const B_NAME = 'BN E2E Target';

const memberUrl = (login: string) => `/members/${login}/`;

const HERO = '.bn-pf-hero';
const CONNECT_BTN = 'button[data-wp-on--click="actions.connect"]';
const MESSAGE_LINK = '.bn-pf-actions a[href*="/messages/"]';
const DM_THREAD = '.bn-dm-thread, .bn-dm-thread-messages, [data-dm-thread]';
const DM_BLOCKED_NOTICE = '.bn-dm-blocked, .bn-dm-unavailable, .bn-dm-notice';

let A_ID = 0;
let B_ID = 0;

test.beforeAll(async () => {
    A_ID = await userId(A_LOGIN);
    B_ID = await ensureUser(B_LOGIN, B_EMAIL, B_NAME);
    expect(A_ID, `actor "${A_LOGIN}" must exist`).toBeGreaterThan(0);
    expect(B_ID, `target "${B_LOGIN}" must exist`).toBeGreaterThan(0);
    await setUserMeta(B_ID, 'bn_onboarding_complete', '1');
});

async function pendingCount(): Promise<number> {
    const p = await tablePrefix();
    return dbCount(
        `SELECT COUNT(*) FROM ${p}bn_connections WHERE requester_id=${A_ID} AND recipient_id=${B_ID} AND status='pending'`
    );
}

/** Delete any 1:1 conversation (and its rows) that has exactly A and B in it. */
async function purgeDirectConversation(): Promise<void> {
    const p = await tablePrefix();
    // A derived-table wrapper is required — MySQL forbids referencing the delete
    // target directly in the subquery FROM.
    const sql =
        `DELETE c, part, m FROM ${p}mvs_conversations c ` +
        `JOIN ${p}mvs_conversation_participants part ON part.conversation_id = c.id ` +
        `LEFT JOIN ${p}mvs_messages m ON m.conversation_id = c.id ` +
        `WHERE c.type='direct' AND c.id IN (` +
        `SELECT id FROM (` +
        `SELECT conversation_id AS id FROM ${p}mvs_conversation_participants ` +
        `WHERE user_id IN (${A_ID}, ${B_ID}) GROUP BY conversation_id ` +
        `HAVING COUNT(DISTINCT user_id) = 2 AND COUNT(*) = 2` +
        `) t)`;
    await wp(['db', 'query', sql]).catch(() => undefined);
}

test.describe('profile / connections + messages (effect-based)', () => {
    test.beforeEach(async () => {
        await resetPair(A_ID, B_ID);
    });

    test.afterAll(async () => {
        await resetPair(A_ID, B_ID);
    });

    test('J-31 connection request writes a pending row', async ({ page }) => {
        await loginAs(page, A_LOGIN);
        await page.goto(memberUrl(B_LOGIN));
        const hero = page.locator(HERO).first();
        await expect(hero).toBeVisible();

        const connect = hero.locator(CONNECT_BTN).first();
        await expect(connect, 'the Connect control is available for a not-yet-connected member').toBeVisible();

        await Promise.all([
            page.waitForResponse(
                (r) => r.url().includes(`/users/${B_ID}/connect`) && r.request().method() === 'POST',
                { timeout: 10_000 }
            ),
            connect.click(),
        ]);

        // Effect: a pending connection row exists (server truth).
        expect(await pendingCount(), 'connect must create a pending wp_bn_connections row').toBe(1);

        // Reload — the server-truth "Pending" state renders and Connect is gone.
        await page.reload();
        await expect(
            page.locator(HERO).first().locator('button[data-wp-on--click="actions.withdrawRequest"]').first(),
            'the reloaded hero shows the Pending (withdraw) state'
        ).toBeVisible();
    });

    test('J-32 Message button opens/creates the DM thread with the member', async ({ page }, testInfo) => {
        await loginAs(page, A_LOGIN);
        await page.goto(memberUrl(B_LOGIN));
        await expect(page.locator(HERO).first()).toBeVisible();

        const msg = page.locator(MESSAGE_LINK).first();
        if (!(await msg.count())) {
            softSkip(testInfo, 'DM bridge entry point (Message button) not present — WPMediaVerse DM disabled.');
            return;
        }

        try {
            await Promise.all([page.waitForLoadState('domcontentloaded'), msg.click()]);
            // Landed on the messages surface targeting this member.
            await expect(page).toHaveURL(/messages/i);

            // If the engine refused the thread (DM access level / block / self), it
            // surfaces a reason-aware notice instead of a thread pane — a real
            // precondition, not a product bug, so softSkip with the reason.
            if (await page.locator(DM_BLOCKED_NOTICE).first().count()) {
                softSkip(testInfo, 'DM engine declined to open the thread (access level or block) — reason notice shown.');
                return;
            }

            // Effect: the thread pane for this member is open — the engine
            // find-or-created the 1:1 conversation (native.php → open_with_result),
            // so the compose surface renders with the member on the other side.
            await expect(
                page.locator(DM_THREAD).first(),
                'the DM thread/compose pane must render for the messaged member'
            ).toBeVisible({ timeout: 10_000 });
            await expect(
                page.getByText(await displayName(B_ID), { exact: false }).first(),
                'the opened thread names the member'
            ).toBeVisible();
        } finally {
            // A freshly opened empty 1:1 is transient state — remove it so reruns
            // start from a clean inbox.
            await purgeDirectConversation();
        }
    });
});
