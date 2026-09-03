import { test, expect, type Page } from '@playwright/test';
import { loginAs } from '../_fixtures/actor';
import {
    ensureUser,
    userId,
    resetPair,
    dbCount,
    tablePrefix,
    setUserMeta,
} from '../_fixtures/wp';

/**
 * Wave-1 PROFILE social-graph actions — EFFECT-BASED (J-700..J-706).
 *
 * The existing Follow/Connect/Mute specs are WEAK: they assert only that a
 * control mutates its own label/class, which passes even when the REST write
 * silently 500s while the JS flips optimistically. Every test here instead
 * asserts the REAL server consequence — the relationship row is created or
 * removed in the BuddyNext custom tables (wp_bn_follows / _connections /
 * _blocks / _reports) — read back over WP-CLI AFTER the action. A silent REST
 * failure leaves the DB unchanged and fails the test, which is the whole point.
 *
 * Actor model: A = varundubey (owner of the acting session), B = a dedicated
 * seeded member (bn_e2e_target). Most A4 actions target ANOTHER member, and two
 * (connection accept/decline) require B to respond, so the spec switches actors
 * with ?autologin=<login> (cookies cleared between switches — the mu-plugin
 * refuses to swap an existing session). Every test resets the A<->B pair first,
 * so reruns are idempotent regardless of how the previous run exited.
 *
 * Selectors are declared locally (per repo rule: do not edit shared selectors.ts).
 * These read the LIVE profile-hero markup (templates/parts/profile-hero.php) and
 * its report/block modals (templates/partials/*.php).
 */

const A_LOGIN = process.env.BN_TEST_USER ?? 'varundubey';
const B_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'bn_e2e_target';
const B_EMAIL = 'bn_e2e_target@example.com';
const B_NAME = 'BN E2E Target';

// ---- profile-hero action selectors (live markup) --------------------------
const HERO = '.bn-pf-hero';
const MORE_WRAP = '.bn-more-menu-wrap';
const MORE_TRIGGER = '.bn-pf-more-trigger';
// One control, not two. `actions.follow` and `actions.unfollow` do not exist
// anywhere in the plugin - every follow control in every template emits
// `actions.toggleFollow`, and expresses which way it will go through
// aria-pressed (and its accessible name). The old selectors matched nothing,
// so this spec failed at the FIRST assertion and never reached the unfollow
// behaviour it is named for.
//
// The spec reloads before each assertion, so the server-rendered aria-pressed
// is authoritative at the point it is read.
const TOGGLE_FOLLOW = 'button[data-wp-on--click="actions.toggleFollow"]';
const FOLLOW_BTN = `${TOGGLE_FOLLOW}[aria-pressed="false"]`;
const UNFOLLOW_BTN = `${TOGGLE_FOLLOW}[aria-pressed="true"]`;
const CONNECT_BTN = 'button[data-wp-on--click="actions.connect"]';
const ACCEPT_BTN = 'button[data-wp-on--click="actions.acceptRequest"]';
const DECLINE_BTN = 'button[data-wp-on--click="actions.declineRequest"]';
const DISCONNECT_BTN = 'button[data-wp-on--click="actions.disconnectUser"]';
const MUTE_ITEM = 'button[data-wp-on--click="actions.toggleMute"]';
const BLOCK_ITEM = 'button[data-wp-on--click="actions.toggleBlock"]';
const REPORT_ITEM = 'button[data-wp-on--click="actions.openReport"]';
const BLOCK_BACKDROP = '.bn-pf-block-backdrop';
const BLOCK_CONFIRM = '.bn-pf-block-backdrop button[data-wp-on--click="actions.confirmBlock"]';
const REPORT_BACKDROP = '.bn-pf-report-backdrop';
const REPORT_SUBMIT = '.bn-pf-report-backdrop button[data-wp-on--click="actions.submitReport"]';

const memberUrl = (login: string) => `/members/${login}/`;
const SETTINGS_PRIVACY = '/settings/privacy/';

let A_ID = 0;
let B_ID = 0;

test.beforeAll(async () => {
    B_ID = await ensureUser(B_LOGIN, B_EMAIL, B_NAME);
    A_ID = await userId(A_LOGIN);
    expect(A_ID, `actor "${A_LOGIN}" must exist`).toBeGreaterThan(0);
    expect(B_ID, `target "${B_LOGIN}" must exist`).toBeGreaterThan(0);
    // A fresh member is otherwise force-redirected to the onboarding hub, so B's
    // profile view (needed for the accept/decline response flow) never renders
    // the hero. Mark B onboarded so it lands on real pages.
    await setUserMeta(B_ID, 'bn_onboarding_complete', '1');
});

/** Open the profile-hero kebab and return the More-menu wrapper locator. */
async function openMoreMenu(page: Page) {
    const wrap = page.locator(MORE_WRAP);
    await expect(wrap).toBeVisible();
    await page.locator(MORE_TRIGGER).click();
    return wrap;
}

/** DB truth helpers. */
async function followCount() {
    const p = await tablePrefix();
    return dbCount(
        `SELECT COUNT(*) FROM ${p}bn_follows WHERE follower_id=${A_ID} AND following_id=${B_ID}`
    );
}
async function connectionCount(status: string, requester: number, recipient: number) {
    const p = await tablePrefix();
    return dbCount(
        `SELECT COUNT(*) FROM ${p}bn_connections WHERE requester_id=${requester} AND recipient_id=${recipient} AND status='${status}'`
    );
}
async function blockTypeCount(type: 'block' | 'mute' | 'restrict') {
    const p = await tablePrefix();
    return dbCount(
        `SELECT COUNT(*) FROM ${p}bn_blocks WHERE blocker_id=${A_ID} AND blocked_id=${B_ID} AND type='${type}'`
    );
}
async function reportCount() {
    const p = await tablePrefix();
    return dbCount(
        `SELECT COUNT(*) FROM ${p}bn_reports WHERE reporter_id=${A_ID} AND object_type='user' AND object_id=${B_ID}`
    );
}

test.describe('profile / social-graph actions (effect-based)', () => {
    test.beforeEach(async () => {
        await resetPair(A_ID, B_ID);
    });

    test.afterAll(async () => {
        await resetPair(A_ID, B_ID);
    });

    test('J-700 unfollow removes the follow relationship (reload + DB confirm)', async ({
        page,
    }) => {
        await loginAs(page, A_LOGIN);
        await page.goto(memberUrl(B_LOGIN));
        const hero = page.locator(HERO).first();
        await expect(hero).toBeVisible();

        // --- Follow (precondition, but its effect is asserted too) ---
        const follow = hero.locator(FOLLOW_BTN).first();
        await expect(follow).toBeVisible();
        await Promise.all([
            page.waitForResponse(
                (r) =>
                    r.url().includes(`/users/${B_ID}/follow`) &&
                    r.request().method() === 'POST',
                { timeout: 10_000 }
            ),
            follow.click(),
        ]);
        expect(await followCount(), 'follow must create a wp_bn_follows row').toBe(1);

        // Reload — server truth now renders the "Following" state.
        await page.reload();
        const following = hero.locator(UNFOLLOW_BTN).filter({ hasText: 'Following' }).first();
        await expect(following).toBeVisible();
        await expect(hero.locator(FOLLOW_BTN).first()).toBeHidden();

        // --- Unfollow (the action under test) ---
        await Promise.all([
            page.waitForResponse(
                (r) =>
                    r.url().includes(`/users/${B_ID}/follow`) &&
                    r.request().method() === 'DELETE',
                { timeout: 10_000 }
            ),
            following.click(),
        ]);
        expect(await followCount(), 'unfollow must remove the wp_bn_follows row').toBe(0);

        // Reload — Follow CTA is back, proving the removal persisted server-side.
        await page.reload();
        await expect(hero.locator(FOLLOW_BTN).first()).toBeVisible();
    });

    test('J-701 connection accept links both members (DB confirm)', async ({ page }) => {
        // A sends the request.
        await loginAs(page, A_LOGIN);
        await page.goto(memberUrl(B_LOGIN));
        const heroB = page.locator(HERO).first();
        await expect(heroB).toBeVisible();
        await Promise.all([
            page.waitForResponse(
                (r) =>
                    r.url().includes(`/users/${B_ID}/connect`) &&
                    r.request().method() === 'POST',
                { timeout: 10_000 }
            ),
            heroB.locator(CONNECT_BTN).first().click(),
        ]);
        expect(await connectionCount('pending', A_ID, B_ID), 'connect must create a pending row').toBe(1);

        // B accepts.
        await loginAs(page, B_LOGIN);
        await page.goto(memberUrl(A_LOGIN));
        const heroA = page.locator(HERO).first();
        await expect(heroA).toBeVisible();
        const accept = heroA.locator(ACCEPT_BTN).first();
        await expect(accept).toBeVisible();
        await Promise.all([
            page.waitForResponse(
                (r) =>
                    r.url().includes(`/users/${A_ID}/connect/accept`) &&
                    r.request().method() === 'POST',
                { timeout: 10_000 }
            ),
            accept.click(),
        ]);
        expect(await connectionCount('accepted', A_ID, B_ID), 'accept must flip the row to accepted').toBe(1);
        expect(await connectionCount('pending', A_ID, B_ID), 'no pending row should remain').toBe(0);

        // Reload — B now sees the Connected/Disconnect control (server truth).
        await page.reload();
        await expect(page.locator(HERO).first().locator(DISCONNECT_BTN).first()).toBeVisible();
    });

    test('J-702 connection decline clears the request without connecting', async ({ page }) => {
        // A sends the request.
        await loginAs(page, A_LOGIN);
        await page.goto(memberUrl(B_LOGIN));
        await expect(page.locator(HERO).first()).toBeVisible();
        await Promise.all([
            page.waitForResponse(
                (r) =>
                    r.url().includes(`/users/${B_ID}/connect`) &&
                    r.request().method() === 'POST',
                { timeout: 10_000 }
            ),
            page.locator(HERO).first().locator(CONNECT_BTN).first().click(),
        ]);
        expect(await connectionCount('pending', A_ID, B_ID)).toBe(1);

        // B declines.
        await loginAs(page, B_LOGIN);
        await page.goto(memberUrl(A_LOGIN));
        const heroA = page.locator(HERO).first();
        const decline = heroA.locator(DECLINE_BTN).first();
        await expect(decline).toBeVisible();
        await Promise.all([
            page.waitForResponse(
                (r) =>
                    r.url().includes(`/users/${A_ID}/connect/decline`) &&
                    r.request().method() === 'POST',
                { timeout: 10_000 }
            ),
            decline.click(),
        ]);
        expect(await connectionCount('accepted', A_ID, B_ID), 'decline must NOT connect').toBe(0);
        expect(await connectionCount('pending', A_ID, B_ID), 'decline must clear the pending row').toBe(0);
    });

    test('J-703 disconnect reverts a connected pair to not-connected', async ({ page }) => {
        // Build an accepted connection through the real UI (A request -> B accept).
        await loginAs(page, A_LOGIN);
        await page.goto(memberUrl(B_LOGIN));
        await expect(page.locator(HERO).first()).toBeVisible();
        await Promise.all([
            page.waitForResponse(
                (r) =>
                    r.url().includes(`/users/${B_ID}/connect`) &&
                    r.request().method() === 'POST',
                { timeout: 10_000 }
            ),
            page.locator(HERO).first().locator(CONNECT_BTN).first().click(),
        ]);
        await loginAs(page, B_LOGIN);
        await page.goto(memberUrl(A_LOGIN));
        await Promise.all([
            page.waitForResponse(
                (r) =>
                    r.url().includes(`/users/${A_ID}/connect/accept`) &&
                    r.request().method() === 'POST',
                { timeout: 10_000 }
            ),
            page.locator(HERO).first().locator(ACCEPT_BTN).first().click(),
        ]);
        expect(await connectionCount('accepted', A_ID, B_ID), 'precondition: pair connected').toBe(1);

        // A disconnects (the action under test).
        await loginAs(page, A_LOGIN);
        await page.goto(memberUrl(B_LOGIN));
        const heroB = page.locator(HERO).first();
        const disconnect = heroB.locator(DISCONNECT_BTN).first();
        await expect(disconnect).toBeVisible();
        await Promise.all([
            page.waitForResponse(
                (r) =>
                    r.url().includes(`/users/${B_ID}/connect`) &&
                    r.request().method() === 'DELETE',
                { timeout: 10_000 }
            ),
            disconnect.click(),
        ]);
        expect(await connectionCount('accepted', A_ID, B_ID), 'disconnect must remove the accepted row').toBe(0);

        // Reload — Connect CTA is back.
        await page.reload();
        await expect(page.locator(HERO).first().locator(CONNECT_BTN).first()).toBeVisible();
    });

    test('J-704 block then unblock a member (DB + blocked-list confirm)', async ({ page }) => {
        await loginAs(page, A_LOGIN);
        await page.goto(memberUrl(B_LOGIN));
        await expect(page.locator(HERO).first()).toBeVisible();

        // Block via the kebab -> confirm modal.
        const wrap = await openMoreMenu(page);
        const blockItem = wrap.locator(BLOCK_ITEM).first();
        await expect(blockItem).toBeVisible();
        await blockItem.click();
        await expect(page.locator(BLOCK_BACKDROP)).toBeVisible();
        await Promise.all([
            page.waitForResponse(
                (r) =>
                    r.url().includes(`/users/${B_ID}/block`) &&
                    r.request().method() === 'POST',
                { timeout: 10_000 }
            ),
            page.locator(BLOCK_CONFIRM).click(),
        ]);
        expect(await blockTypeCount('block'), 'block must create a wp_bn_blocks row').toBe(1);

        // The blocked member is now listed in Settings -> Privacy (server truth).
        await page.goto(SETTINGS_PRIVACY);
        const blockedRow = page.locator(
            `.bn-ep-relation[data-relation="block"][data-user-id="${B_ID}"]`
        );
        await expect(blockedRow).toBeVisible();

        // Unblock via the blocked-list row (relation-remove handler -> DELETE).
        await Promise.all([
            page.waitForResponse(
                (r) =>
                    r.url().includes(`/users/${B_ID}/block`) &&
                    r.request().method() === 'DELETE',
                { timeout: 10_000 }
            ),
            blockedRow.locator('[data-bn-relation-remove]').first().click(),
        ]);
        expect(await blockTypeCount('block'), 'unblock must remove the wp_bn_blocks row').toBe(0);

        // Reload — the row is gone from the blocked list.
        await page.goto(SETTINGS_PRIVACY);
        await expect(
            page.locator(`.bn-ep-relation[data-relation="block"][data-user-id="${B_ID}"]`)
        ).toHaveCount(0);
    });

    test('J-705 report a member; repeat is rejected as already-reported', async ({ page }) => {
        await loginAs(page, A_LOGIN);
        await page.goto(memberUrl(B_LOGIN));
        await expect(page.locator(HERO).first()).toBeVisible();

        // First report — default reason "spam".
        let wrap = await openMoreMenu(page);
        await wrap.locator(REPORT_ITEM).first().click();
        await expect(page.locator(REPORT_BACKDROP)).toBeVisible();
        await Promise.all([
            page.waitForResponse(
                (r) => r.url().includes('/reports') && r.request().method() === 'POST',
                { timeout: 10_000 }
            ),
            page.locator(REPORT_SUBMIT).click(),
        ]);
        // Success closes the modal and creates exactly one report row.
        await expect(page.locator(REPORT_BACKDROP)).toBeHidden();
        expect(await reportCount(), 'report must create a wp_bn_reports row').toBe(1);

        // Second report — the server answers 409 already-reported: the modal stays
        // open and NO duplicate row is written.
        wrap = await openMoreMenu(page);
        await wrap.locator(REPORT_ITEM).first().click();
        await expect(page.locator(REPORT_BACKDROP)).toBeVisible();
        const second = await Promise.all([
            page.waitForResponse(
                (r) => r.url().includes('/reports') && r.request().method() === 'POST',
                { timeout: 10_000 }
            ),
            page.locator(REPORT_SUBMIT).click(),
        ]);
        expect(second[0].status(), 'second report must be rejected (409)').toBe(409);
        await expect(page.locator(REPORT_BACKDROP)).toBeVisible();
        expect(await reportCount(), 'already-reported must NOT write a duplicate').toBe(1);
    });

    test('J-706 mute then unmute a member (reload label flip + DB confirm)', async ({ page }) => {
        await loginAs(page, A_LOGIN);
        await page.goto(memberUrl(B_LOGIN));
        await expect(page.locator(HERO).first()).toBeVisible();

        // Mute.
        let wrap = await openMoreMenu(page);
        const muteItem = wrap.locator(MUTE_ITEM).first();
        await expect(muteItem).toBeVisible();
        await expect(muteItem).toHaveText(/mute/i);
        await Promise.all([
            page.waitForResponse(
                (r) =>
                    r.url().includes(`/users/${B_ID}/mute`) &&
                    r.request().method() === 'POST',
                { timeout: 10_000 }
            ),
            muteItem.click(),
        ]);
        expect(await blockTypeCount('mute'), 'mute must create a wp_bn_blocks(mute) row').toBe(1);

        // Reload — server truth now labels the control "Unmute".
        await page.reload();
        wrap = await openMoreMenu(page);
        const unmuteItem = wrap.locator(MUTE_ITEM).first();
        await expect(unmuteItem).toBeVisible();
        await expect(unmuteItem).toHaveText(/unmute/i);

        // Unmute.
        await Promise.all([
            page.waitForResponse(
                (r) =>
                    r.url().includes(`/users/${B_ID}/mute`) &&
                    r.request().method() === 'DELETE',
                { timeout: 10_000 }
            ),
            unmuteItem.click(),
        ]);
        expect(await blockTypeCount('mute'), 'unmute must remove the wp_bn_blocks(mute) row').toBe(0);
    });
});
