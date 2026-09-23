import { test, expect } from '@playwright/test';
import { loginAs } from '../_fixtures/actor';
import { ensureUser, userId, setUserMeta, wp, dbScalar, tablePrefix } from '../_fixtures/wp';

/**
 * PROFILE presence (online / last-active) — EFFECT-BASED (J-822).
 *
 * PresenceService.php stamps `bn_presence.last_active` for a logged-in member
 * on every front-end page view (template_redirect, no JS required). Readers
 * (BlockService::is_user_online[_at]) resolve that row into the
 * `data-presence="online"` attribute profile-hero.php puts on `.bn-avatar`
 * (assets/css/bn-base.css paints it as a coloured dot via
 * `.bn-avatar[data-presence="online"]::after`). This walks the whole chain as
 * a member: one member (B) becomes active by loading a real page, a second
 * member (P) then views B's profile and gets the visible online indicator —
 * the actual "show who is online" promise (seeing someone ELSE's presence,
 * not just your own). It also asserts the reverse: once B has been offline
 * long enough that no presence row exists, the dot is gone and the profile
 * still renders cleanly (no fatal, no stale dot).
 *
 * Covers: cap-show-who-is-online-last-active
 * Roles: member
 * Note: both actors are plain subscribers (B_LOGIN, P_LOGIN) — no admin
 * session is used anywhere in this file, since the promise is member-facing
 * only (PresenceService has no settings screen / admin toggle to walk).
 */

const B_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'bn_e2e_target';
const B_EMAIL = 'bn_e2e_target@example.com';
const B_NAME = 'BN E2E Target';

const P_LOGIN = 'bn_e2e_private';
const P_EMAIL = 'bn_e2e_private@example.com';
const P_NAME = 'BN E2E Private';

const memberUrl = (login: string) => `/members/${login}/`;

const HERO = '.bn-pf-hero';
const AVATAR = '.bn-pf-avatar-wrap .bn-avatar';

let B_ID = 0;
let P_ID = 0;

test.beforeAll(async () => {
    B_ID = await ensureUser(B_LOGIN, B_EMAIL, B_NAME);
    P_ID = await ensureUser(P_LOGIN, P_EMAIL, P_NAME);
    expect(B_ID, `actor "${B_LOGIN}" must exist`).toBeGreaterThan(0);
    expect(P_ID, `actor "${P_LOGIN}" must exist`).toBeGreaterThan(0);
    // Fresh members are force-redirected to onboarding, which never reaches
    // a profile hero.
    await setUserMeta(B_ID, 'bn_onboarding_complete', '1');
    await setUserMeta(P_ID, 'bn_onboarding_complete', '1');
});

/** Clear B's presence row and heartbeat throttle so each run starts offline. */
async function clearPresence(): Promise<void> {
    const p = await tablePrefix();
    await wp(['db', 'query', `DELETE FROM ${p}bn_presence WHERE user_id=${B_ID};`]);
    await wp(['transient', 'delete', `bn_presence_${B_ID}`]).catch(() => undefined);
}

test.describe('profile / presence (online / last-active, effect-based)', () => {
    test('J-822 an active member shows online to another member viewing their profile (mobile 390px)', async ({ page }) => {
        await clearPresence();
        try {
            // B becomes active: a real logged-in front-end page view is the
            // production trigger for the heartbeat (PresenceService::heartbeat
            // on template_redirect) — no JS, no seeded SQL row for the action
            // itself.
            await loginAs(page, B_LOGIN);
            await page.goto(memberUrl(B_LOGIN));
            await expect(page.locator(HERO).first()).toBeVisible();

            // Effect (server truth): the heartbeat actually wrote a fresh
            // bn_presence row for B.
            const p = await tablePrefix();
            const lastActive = parseInt(
                await dbScalar(`SELECT last_active FROM ${p}bn_presence WHERE user_id=${B_ID}`),
                10
            );
            expect(lastActive, 'visiting a page as a logged-in member must stamp bn_presence.last_active').toBeGreaterThan(
                Math.floor(Date.now() / 1000) - 60
            );

            // Effect (visible): a DIFFERENT member (P) viewing B's profile sees
            // the online indicator the render path derives from that row.
            await page.setViewportSize({ width: 390, height: 844 });
            await loginAs(page, P_LOGIN);
            await page.goto(memberUrl(B_LOGIN));
            await expect(page.locator(HERO).first()).toBeVisible();
            await expect(
                page.locator(AVATAR).first(),
                'a member active within the online window must show data-presence="online" to another viewer'
            ).toHaveAttribute('data-presence', 'online');
        } finally {
            await clearPresence();
        }
    });

    test('J-822 a member with no recent activity shows no online dot, without breaking the profile', async ({ page }) => {
        await clearPresence();
        try {
            await loginAs(page, P_LOGIN);
            await page.goto(memberUrl(B_LOGIN));

            // Degrade, not error: the hero still renders fully for an offline
            // member, it just carries no presence attribute at all (the
            // template only emits data-presence when is_online() is true).
            await expect(page.locator(HERO).first()).toBeVisible();
            await expect(
                page.locator(AVATAR).first(),
                'a member with no bn_presence row must not render a stale/false online dot'
            ).not.toHaveAttribute('data-presence', 'online');
        } finally {
            await clearPresence();
        }
    });
});
