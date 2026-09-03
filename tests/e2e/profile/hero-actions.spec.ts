import { test, expect, type Page } from '@playwright/test';
import { loginAs } from '../_fixtures/actor';
import {
    ensureUser,
    userId,
    resetPair,
    tablePrefix,
    dbCount,
    getUserMeta,
    setUserMeta,
    deleteUserMeta,
} from '../_fixtures/wp';
import { openMemberSession, restGet } from '../_fixtures/feed-wave1.helpers';
import { sel } from '../_fixtures/selectors';

/**
 * Wave-4 PROFILE hero actions — EFFECT-BASED (J-740..J-746).
 *
 * Closes the last WEAK/MISSING A1 + A4 rows the coverage re-scan flagged on the
 * profile hero (templates/parts/profile-hero.php). Every test asserts the REAL
 * server consequence — a usermeta written, a relationship row created/removed,
 * a permalink that actually resolves, a feed post that references the profile, a
 * gated private card shown to a non-permitted viewer — never an optimistic UI
 * flip and never `.catch(() => pass)`. DB/REST truth is read back AFTER the
 * action; a silent REST failure leaves the store unchanged and fails the test.
 *
 *   J-740 cover upload → reposition → save    → buddynext_cover_url + _focal set.
 *   J-741 withdraw own outgoing connect request → pending row gone (requester DB
 *         + target's /me/connection-requests both clear).
 *   J-742 Share → Copy link                    → the shared permalink is a real
 *         profile URL that resolves to that member's hero.
 *   J-743 Share → Share to feed                → lands on the composer prefilled
 *         with the @mention and creates a feed post referencing the profile.
 *   J-744 Restrict a member                    → wp_bn_blocks(restrict) row written.
 *   J-745 logged-out Follow                    → a login CTA routed back to the
 *         profile (no action-less hero for guests).
 *   J-746 private profile                      → a non-permitted viewer gets the
 *         "This profile is private" card, not the hero.
 *
 * Actors: A = varundubey (admin owner of the acting session), B = bn_e2e_target
 * (a seeded subscriber). J-746 needs a NON-admin owner whose gate actually bites
 * (admins bypass can_view_profile via manage_options), so it uses a dedicated
 * seeded private subscriber viewed BY B. Selectors are declared locally (repo
 * rule: never edit the shared selectors.ts). Every mutation is reverted so
 * reruns are idempotent regardless of how the previous run exited.
 */

const A_LOGIN = process.env.BN_TEST_USER ?? 'varundubey';
const B_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'bn_e2e_target';
const B_EMAIL = 'bn_e2e_target@example.com';
const B_NAME = 'BN E2E Target';

// A dedicated private member for J-746 (must be a non-admin so the gate bites).
const P_LOGIN = 'bn_e2e_private';
const P_EMAIL = 'bn_e2e_private@example.com';
const P_NAME = 'BN E2E Private';

const memberUrl = (login: string) => `/members/${login}/`;

// ---- profile-hero action selectors (live markup) --------------------------
const HERO = '.bn-pf-hero';
const PF_ACTIONS = '.bn-pf-actions';
const CONNECT_BTN = 'button[data-wp-on--click="actions.connect"]';
const WITHDRAW_BTN = 'button[data-wp-on--click="actions.withdrawRequest"]';
const MORE_TRIGGER = '.bn-pf-more-trigger';
const RESTRICT_ITEM = 'button[data-wp-on--click="actions.toggleRestrict"]';
// The share menu was folded into the More menu. profile-actions-overflow.php
// says why: the owner bar used actions.shareProfile and the member view used
// actions.copyProfileLink for the same intent, and shareProfile is strictly
// better - it opens the OS share sheet via navigator.share where that exists
// and falls back to the clipboard where it does not, so "Copy link" was the
// fallback masquerading as a separate feature. Neither .bn-share-menu-wrap nor
// actions.copyProfileLink exists any more, so this spec failed on the first
// click and never reached what it is named for: that the permalink resolves.
const SHARE_TOGGLE = MORE_TRIGGER;
const COPY_LINK_ITEM = '.bn-more-menu button[data-wp-on--click="actions.shareProfile"]';
const SHARE_TO_FEED = '.bn-share-menu-wrap a[href*="mention="]';
const PRIVATE_CARD = '.bn-profile-private';

// A valid 4x4 PNG (correct IDAT CRC — ImageMagick rejects a malformed one),
// small and well under the 4MB / 1920x1080 cover + 1024px avatar caps.
const PNG_IMG = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAIAAAAmkwkpAAAADklEQVR4nGNoQAIMxHEAcFIYAYPG8BkAAAAASUVORK5CYII=',
    'base64'
);

let A_ID = 0;
let B_ID = 0;
let P_ID = 0;

test.beforeAll(async () => {
    A_ID = await userId(A_LOGIN);
    B_ID = await ensureUser(B_LOGIN, B_EMAIL, B_NAME);
    P_ID = await ensureUser(P_LOGIN, P_EMAIL, P_NAME);
    expect(A_ID, `actor "${A_LOGIN}" must exist`).toBeGreaterThan(0);
    expect(B_ID, `target "${B_LOGIN}" must exist`).toBeGreaterThan(0);
    expect(P_ID, `private member "${P_LOGIN}" must exist`).toBeGreaterThan(0);
    // Fresh members are force-redirected to onboarding, so they never land on the
    // real profile pages these tests need. Mark both onboarded.
    await setUserMeta(B_ID, 'bn_onboarding_complete', '1');
    await setUserMeta(P_ID, 'bn_onboarding_complete', '1');
});

async function pendingCount(requester: number, recipient: number): Promise<number> {
    const p = await tablePrefix();
    return dbCount(
        `SELECT COUNT(*) FROM ${p}bn_connections WHERE requester_id=${requester} AND recipient_id=${recipient} AND status='pending'`
    );
}

async function restrictCount(): Promise<number> {
    const p = await tablePrefix();
    return dbCount(
        `SELECT COUNT(*) FROM ${p}bn_blocks WHERE blocker_id=${A_ID} AND blocked_id=${B_ID} AND type='restrict'`
    );
}

/** Open the profile-hero kebab (More options) menu. */
async function openMoreMenu(page: Page): Promise<void> {
    await page.locator(MORE_TRIGGER).click();
}

test.describe('profile / hero actions (effect-based)', () => {
    test('J-740 cover upload + reposition writes cover URL and focal point', async ({ browser }) => {
        const origUrl = await getUserMeta(A_ID, 'buddynext_cover_url');
        const origFocal = await getUserMeta(A_ID, 'buddynext_cover_focal');
        const sess = await openMemberSession(browser, A_LOGIN);
        try {
            // Upload through the real /me/cover write path with a reposition — the
            // exact multipart the cover-upload modal sends (file + focal_x/y/zoom).
            const res = await sess.request.post('/wp-json/buddynext/v1/me/cover', {
                headers: { 'X-WP-Nonce': sess.nonce },
                multipart: {
                    cover: { name: 'e2e-cover.png', mimeType: 'image/png', buffer: PNG_IMG },
                    focal_x: '25',
                    focal_y: '75',
                    focal_zoom: '1.5',
                },
            });
            expect(res.status(), 'cover upload must succeed').toBe(200);

            // Effect 1: the canonical cover URL usermeta is now an uploaded image.
            const storedUrl = await getUserMeta(A_ID, 'buddynext_cover_url');
            expect(storedUrl, 'buddynext_cover_url must be written by the upload').toMatch(/^https?:\/\//);

            // Effect 2: the reposition (focal point) is persisted, not dropped.
            const storedFocal = await getUserMeta(A_ID, 'buddynext_cover_focal');
            expect(storedFocal, 'the reposition focal point must be stored').toContain('25');
            expect(storedFocal, 'the reposition focal point must be stored').toContain('75');
        } finally {
            // Leave the account as found: drop the uploaded cover, restore prior meta.
            await sess.request
                .delete('/wp-json/buddynext/v1/me/cover', { headers: { 'X-WP-Nonce': sess.nonce } })
                .catch(() => undefined);
            if (origUrl) {
                await setUserMeta(A_ID, 'buddynext_cover_url', origUrl);
            } else {
                await deleteUserMeta(A_ID, 'buddynext_cover_url');
            }
            if (origFocal) {
                await setUserMeta(A_ID, 'buddynext_cover_focal', origFocal);
            } else {
                await deleteUserMeta(A_ID, 'buddynext_cover_focal');
            }
            await sess.ctx.close();
        }
    });

    test('J-741 withdraw an outgoing pending request removes it (requester + target)', async ({ page, browser }) => {
        await resetPair(A_ID, B_ID);
        try {
            // A sends B a connection request (through the hero UI).
            await loginAs(page, A_LOGIN);
            await page.goto(memberUrl(B_LOGIN));
            const hero = page.locator(HERO).first();
            await expect(hero).toBeVisible();
            await Promise.all([
                page.waitForResponse(
                    (r) => r.url().includes(`/users/${B_ID}/connect`) && r.request().method() === 'POST',
                    { timeout: 10_000 }
                ),
                hero.locator(CONNECT_BTN).first().click(),
            ]);
            expect(await pendingCount(A_ID, B_ID), 'precondition: an outgoing pending request exists').toBe(1);

            // Reload — server truth now renders the "Pending" (withdraw) control.
            await page.reload();
            const withdraw = hero.locator(WITHDRAW_BTN).first();
            await expect(withdraw).toBeVisible();

            // Withdraw (the action under test): DELETE /users/{B}/connect.
            await Promise.all([
                page.waitForResponse(
                    (r) => r.url().includes(`/users/${B_ID}/connect`) && r.request().method() === 'DELETE',
                    { timeout: 10_000 }
                ),
                withdraw.click(),
            ]);

            // Requester truth: the pending row is gone.
            expect(await pendingCount(A_ID, B_ID), 'withdraw must remove the pending row').toBe(0);

            // Target truth: B's incoming-requests list no longer names A.
            const bSess = await openMemberSession(browser, B_LOGIN);
            try {
                const { status, body } = await restGet<Array<Record<string, unknown>>>(
                    bSess.request,
                    bSess.nonce,
                    '/me/connection-requests'
                );
                expect(status, "B's connection-requests must load").toBeLessThan(300);
                const stillThere = (Array.isArray(body) ? body : []).some((row) =>
                    Object.values(row).some((v) => String(v) === String(A_ID))
                );
                expect(stillThere, "the withdrawn request must not remain in B's inbox").toBe(false);
            } finally {
                await bSess.ctx.close();
            }

            // Reload — the Connect CTA is back, proving the withdrawal persisted.
            await page.reload();
            await expect(page.locator(HERO).first().locator(CONNECT_BTN).first()).toBeVisible();
        } finally {
            await resetPair(A_ID, B_ID);
        }
    });

    test('J-742 Share → Copy link exposes a permalink that resolves', async ({ page }) => {
        await loginAs(page, A_LOGIN);
        await page.goto(memberUrl(B_LOGIN));
        await expect(page.locator(HERO).first()).toBeVisible();

        await page.locator(SHARE_TOGGLE).first().click();
        const copy = page.locator(COPY_LINK_ITEM).first();
        await expect(copy, 'the Copy link item is present in the share menu').toBeVisible();

        // The shared permalink is a real profile URL (present), not a "#" stub.
        const shareUrl = await copy.getAttribute('data-share-url');
        expect(shareUrl ?? '', 'the copy-link permalink must be a profile URL').toMatch(
            new RegExp(`/members/${B_LOGIN}/?`)
        );

        // Effect: the permalink actually RESOLVES to that member's profile hero.
        const resolved = await page.goto(shareUrl as string);
        expect(resolved?.status() ?? 0, 'the shared permalink must resolve (not 404)').not.toBe(404);
        await expect(page.locator(HERO).first(), 'the shared link opens the member profile').toBeVisible();
        await expect(page).toHaveURL(new RegExp(`/members/${B_LOGIN}/?`));
    });

    test('J-743 Share → Share to feed creates a post referencing the profile', async ({ page, browser }) => {
        const stamp = Date.now().toString().slice(-6);
        const marker = `e2e share ${stamp}`;

        await loginAs(page, A_LOGIN);
        await page.goto(memberUrl(B_LOGIN));
        await expect(page.locator(HERO).first()).toBeVisible();

        await page.locator(SHARE_TOGGLE).first().click();
        const toFeed = page.locator(SHARE_TO_FEED).first();
        await expect(toFeed, 'the Share-to-feed item is present').toBeVisible();

        // Follow the share-to-feed link — lands on the feed composer prefilled with
        // the @mention (composer.js reads ?mention=<handle>).
        await Promise.all([page.waitForLoadState('domcontentloaded'), toFeed.click()]);

        const textarea = page.locator(sel.composerTextarea).first();
        await expect(textarea, 'the feed composer must render').toBeVisible();
        // The composer is prefilled with the member's @handle (share intent).
        await expect(textarea).toHaveValue(new RegExp(`@${B_LOGIN}`));

        // The member adds their words and posts (real composer submit → POST /posts).
        await textarea.click();
        await page.keyboard.press('End');
        await page.keyboard.type(` ${marker}`);

        const created = await Promise.all([
            page.waitForResponse(
                (r) => r.url().includes('/posts') && r.request().method() === 'POST',
                { timeout: 15_000 }
            ),
            page.locator(sel.composerSubmit).first().click(),
        ]);
        expect(created[0].status(), 'the share post must be created').toBeLessThan(300);
        const body = (await created[0].json().catch(() => ({}))) as { id?: number; ID?: number };
        const postId = Number(body.id ?? body.ID ?? 0);
        expect(postId, 'the created post must carry a server id').toBeGreaterThan(0);

        try {
            // Effect: the new feed card references the shared profile — it carries the
            // @mention that autolinks to B's member page.
            const card = page.locator(sel.postCard).filter({ hasText: marker }).first();
            await expect(card, 'the shared post renders in the feed').toBeVisible({ timeout: 10_000 });
            await expect(
                card.locator(`a[href*="/members/${B_LOGIN}"]`).first(),
                'the post must reference the shared profile'
            ).toBeVisible();
        } finally {
            // Delete the post so reruns start clean.
            const sess = await openMemberSession(browser, A_LOGIN);
            await sess.request
                .delete(`/wp-json/buddynext/v1/posts/${postId}`, { headers: { 'X-WP-Nonce': sess.nonce } })
                .catch(() => undefined);
            await sess.ctx.close();
        }
    });

    test('J-744 Restrict a member writes a restrict row', async ({ page }) => {
        await resetPair(A_ID, B_ID);
        try {
            await loginAs(page, A_LOGIN);
            await page.goto(memberUrl(B_LOGIN));
            await expect(page.locator(HERO).first()).toBeVisible();

            await openMoreMenu(page);
            const restrict = page.locator(RESTRICT_ITEM).first();
            await expect(restrict).toBeVisible();
            await expect(restrict, 'the control reads Restrict before the action').toHaveText(/restrict/i);

            await Promise.all([
                page.waitForResponse(
                    (r) => r.url().includes(`/users/${B_ID}/restrict`) && r.request().method() === 'POST',
                    { timeout: 10_000 }
                ),
                restrict.click(),
            ]);

            // Effect: a restrict row exists in wp_bn_blocks (server truth).
            expect(await restrictCount(), 'restrict must create a wp_bn_blocks(restrict) row').toBe(1);

            // Reload — server truth now labels the control "Unrestrict".
            await page.reload();
            await openMoreMenu(page);
            await expect(page.locator(RESTRICT_ITEM).first()).toHaveText(/unrestrict/i);
        } finally {
            await resetPair(A_ID, B_ID);
        }
    });

    test('J-745 logged-out Follow shows a login CTA routed back to the profile', async ({ browser }) => {
        // A guest context — no session cookies.
        const ctx = await browser.newContext();
        const page = await ctx.newPage();
        try {
            await page.goto(memberUrl(B_LOGIN));
            await expect(page.locator(HERO).first()).toBeVisible();

            // The guest hero is never action-less: a single Follow CTA that routes
            // through login and returns to this profile.
            const cta = page.locator(`${PF_ACTIONS} a[href*="redirect_to"]`).first();
            await expect(cta, 'a logged-out visitor sees a login-routed Follow CTA').toBeVisible();
            await expect(cta).toHaveText(/follow/i);
            const href = (await cta.getAttribute('href')) ?? '';
            expect(href, 'the CTA returns the guest to this profile after login').toContain(
                `/members/${B_LOGIN}`
            );

            // Effect: following the CTA lands on the auth screen (login gate), not a
            // dead link or an optimistic follow.
            await Promise.all([page.waitForLoadState('domcontentloaded'), cta.click()]);
            await expect(page.locator(sel.loginUser).first(), 'the CTA opens the login screen').toBeVisible({
                timeout: 10_000,
            });
        } finally {
            await ctx.close();
        }
    });

    test('J-746 a private profile shows the private card to a non-permitted viewer', async ({ page }) => {
        // Deterministic gate: profile_visibility='private' denies EVERY non-owner,
        // non-admin viewer unconditionally (PrivacyService::can_view_profile default
        // branch) — it does not depend on follow/connection state, so the outcome
        // cannot flake on graph seed. B is a subscriber (non-admin) and P is a
        // non-admin, so the gate bites (admins would bypass via manage_options).
        // resetPair clears any stray block/connection so the private card is
        // attributable to the visibility preference and nothing else.
        await resetPair(P_ID, B_ID);
        await setUserMeta(P_ID, 'bn_privacy_profile_visibility', 'private');
        try {
            // Fail LOUDLY (no skip) if the preference did not actually persist — the
            // gate cannot be exercised otherwise, and a silent skip would hide it.
            expect(
                await getUserMeta(P_ID, 'bn_privacy_profile_visibility'),
                'the private visibility preference must be set for the gate to be exercised'
            ).toBe('private');

            await loginAs(page, B_LOGIN);
            const resp = await page.goto(memberUrl(P_LOGIN));
            expect(resp?.status() ?? 0, 'a private profile still returns a page (gated, not 404)').not.toBe(404);

            // Effect: a non-permitted viewer gets the private card, NOT the hero.
            const privateCard = page.locator(PRIVATE_CARD).first();
            await expect(privateCard, 'a non-permitted viewer sees the private card').toBeVisible();
            await expect(privateCard).toContainText(/this profile is private/i);
            await expect(page.locator(HERO), 'the hero must be withheld from a non-permitted viewer').toHaveCount(0);
        } finally {
            await deleteUserMeta(P_ID, 'bn_privacy_profile_visibility');
            await resetPair(P_ID, B_ID);
        }
    });
});
