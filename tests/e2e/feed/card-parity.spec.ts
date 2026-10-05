import type { Locator, Page } from '@playwright/test';
import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, restGet, restPost } from '../_fixtures/feed-wave1.helpers';
import { createPage, deletePage, userId, wp } from '../_fixtures/wp';

/**
 * J-980 post-card parity across every surface that renders the card.
 *
 * The safety net for the feed rendering work (card 10369460065, plan
 * free-internal/docs/plans/2026-10-05-feed-rendering-plan.md): the same card
 * behaviour must hold wherever a card is drawn, before and after the card's
 * markup is restructured. Each surface runs the same four checks against a
 * fresh post, asserting server truth where there is a write:
 *
 *   1. React through the picker -> GET /reactions says has_reacted; the same
 *      emoji again -> has_reacted false.
 *   2. "More options" opens the post menu; Escape closes it and focus returns
 *      to the button (keyboard users are not dropped at the top of the page).
 *   3. A half-typed comment survives opening and closing another card's menu
 *      (only where the surface shows a second card).
 *   4. Bookmark toggles -> GET /me/bookmarks follows; toggled back after.
 *
 * Surfaces: home feed, single post, space feed, hashtag page, profile,
 * bookmarks, Activity Feed block.
 *
 * Covers: cap-let-members-react-comment-and-reply, cap-bookmark-and-reshare-posts
 * Roles: member (the logged-in test user, acting on their own fresh posts)
 */

type Reaction = { has_reacted?: boolean };

const stamp = Date.now().toString().slice(-7);
const tag = `parity${stamp}`;
const homeText = `card parity home ${stamp} #${tag}`;
const spaceText = `card parity space ${stamp}`;

let nonce = '';
let homeId = 0;
let spaceId = 0;
let spaceSlug = '';
let spacePostId = 0;
let blockPage: { id: number; url: string } | null = null;
let profileUrl = '';
const SELF_LOGIN = process.env.BN_TEST_USER ?? 'varundubey';

/** Run PHP through wp-cli as the test member and return its trimmed output. */
async function php(uid: number, code: string): Promise<string> {
    return (await wp(['eval', `wp_set_current_user(${uid}); ${code}`])).trim();
}

const cardOf = (page: Page, text: string): Locator => page.locator(sel.postCard).filter({ hasText: text }).first();

async function reacted(page: Page, id: number): Promise<boolean> {
    return !!(await restGet<Reaction>(page.request, nonce, `/reactions?object_type=post&object_id=${id}`)).body.has_reacted;
}

async function bookmarked(page: Page, id: number): Promise<boolean> {
    const body = (await restGet<{ ids?: number[] }>(page.request, nonce, '/me/bookmarks')).body;
    return (body.ids ?? []).map(Number).includes(id);
}

/** The four parity checks against one card on the current page. */
async function checkCard(page: Page, card: Locator, id: number): Promise<void> {
    await expect(card, 'the fresh post renders on this surface').toBeVisible({ timeout: 15_000 });

    // 1. React and un-react: server truth. pick() opens the picker if it is not
    // open yet and selects the first reaction, retried until the click lands, so
    // the check reads the outcome (the server row) rather than a race in timing.
    const reactBtn = card.locator(sel.postReact).first();
    const firstEmoji = card.locator(sel.postReactEmoji).first();
    const pick = async (): Promise<void> => {
        await expect(async () => {
            if (!(await firstEmoji.isVisible())) await reactBtn.click();
            await firstEmoji.click({ timeout: 1_500 });
        }).toPass({ timeout: 15_000 });
    };
    await pick();
    await expect.poll(() => reacted(page, id), { timeout: 8_000 }).toBe(true);
    await expect(reactBtn).not.toHaveText(/^\s*React\s*$/, { timeout: 8_000 });
    await pick();
    await expect.poll(() => reacted(page, id), { timeout: 8_000 }).toBe(false);
    await expect(reactBtn).toHaveText(/^\s*React\s*$/, { timeout: 8_000 });

    // 2. Menu: opens, Escape closes, focus returns to its button.
    const menuBtn = card.locator('button.bn-post-card__menu').first();
    await menuBtn.click();
    const menu = card.locator('.bn-post-card__options-menu').first();
    await expect(menu).toBeVisible({ timeout: 5_000 });
    await page.keyboard.press('Escape');
    await expect(menu).toBeHidden({ timeout: 5_000 });
    await expect(menuBtn).toBeFocused();

    // 3. A half-typed comment survives another card's menu.
    const others = page.locator(sel.postCard).filter({ hasNot: page.locator(`[data-post-id="${id}"]`) });
    const other = page.locator(`${sel.postCard}:not([data-post-id="${id}"])`).first();
    const commentBtn = card.locator(sel.postComment).first();
    if ((await others.count()) > 0 && (await other.count()) > 0 && (await commentBtn.count()) > 0) {
        await commentBtn.click();
        const input = card.locator(sel.commentInput).first();
        await expect(input).toBeVisible({ timeout: 5_000 });
        await input.fill(`draft ${stamp}`);
        await other.locator('button.bn-post-card__menu').first().click();
        await page.keyboard.press('Escape');
        await expect(input, 'the draft survives another card opening its menu').toHaveValue(`draft ${stamp}`);
        await input.fill('');
    }

    // 4. Bookmark both ways: server truth, ending where it started.
    const before = await bookmarked(page, id);
    const bm = card.locator(sel.postBookmark).first();
    await bm.click();
    await expect.poll(() => bookmarked(page, id), { timeout: 8_000 }).toBe(!before);
    await bm.click();
    await expect.poll(() => bookmarked(page, id), { timeout: 8_000 }).toBe(before);
}

test.describe('feed / card parity across surfaces (J-980)', () => {
    test.describe.configure({ mode: 'serial' });

    // Seed and clean up through wp-cli (the suite's pattern for beforeAll/afterAll):
    // the posts and space go through BuddyNext's own services, so every hook runs.
    test.beforeAll(async () => {
        const uid = await userId(SELF_LOGIN);
        expect(uid, 'test member exists').toBeGreaterThan(0);
        homeId = Number(await php(uid, `echo buddynext_service('post_service')->create(${uid}, array('type' => 'text', 'content' => '${homeText}'));`));
        const space = JSON.parse(
            await php(uid, `$id = ( new \\BuddyNext\\Spaces\\SpaceService() )->create(${uid}, array('name' => 'Parity ${stamp}', 'slug' => 'parity-${stamp}', 'type' => 'open')); echo wp_json_encode(array('id' => (int) $id, 'slug' => 'parity-${stamp}'));`)
        ) as { id: number; slug: string };
        spaceId = space.id;
        spaceSlug = space.slug;
        spacePostId = Number(await php(uid, `echo buddynext_service('post_service')->create(${uid}, array('type' => 'text', 'content' => '${spaceText}', 'space_id' => ${spaceId}));`));
        expect(homeId, 'seed home post').toBeGreaterThan(0);
        expect(spacePostId, 'seed space post').toBeGreaterThan(0);
        // Index the hashtag now rather than waiting for the background queue.
        await php(uid, `$t = buddynext_service('hashtags'); $t->sync('post', ${homeId}, $t->extract('${homeText}'));`);

        blockPage = await createPage('<!-- wp:buddynext/activity-feed /-->', {
            title: `E2E Card Parity ${stamp}`,
            slug: `e2e-card-parity-${stamp}`,
        });
    });

    test.afterAll(async () => {
        const uid = await userId(SELF_LOGIN);
        for (const id of [homeId, spacePostId]) {
            if (id) await php(uid, `buddynext_service('post_service')->delete(${id}, ${uid});`).catch(() => '');
        }
        if (spaceId) await php(uid, `( new \\BuddyNext\\Spaces\\SpaceService() )->delete(${spaceId}, ${uid});`).catch(() => '');
        if (blockPage) await deletePage(blockPage.id).catch(() => undefined);
    });

    /** Each test logs in afresh, so its REST nonce is read per test. */
    test.beforeEach(async ({ authenticatedPage: page }) => {
        await page.goto(urls.feed);
        nonce = await readRestNonce(page);
        if (!profileUrl) {
            profileUrl = (await cardOf(page, homeText).locator('a.bn-post-card__handle').first().getAttribute('href')) ?? '';
        }
    });

    test('J-980a home feed', async ({ authenticatedPage: page }) => {
        await page.goto(urls.feed);
        await checkCard(page, cardOf(page, homeText), homeId);
    });

    test('J-980b single post', async ({ authenticatedPage: page }) => {
        await page.goto(`/p/${homeId}/`);
        await checkCard(page, cardOf(page, homeText), homeId);
    });

    test('J-980c space feed', async ({ authenticatedPage: page }) => {
        await page.goto(urls.space(spaceSlug));
        await checkCard(page, cardOf(page, spaceText), spacePostId);
    });

    test('J-980d hashtag page', async ({ authenticatedPage: page }) => {
        // The tag is indexed in the background; wait until the page lists the post.
        await expect
            .poll(async () => {
                await page.goto(urls.hashtag(tag));
                return page.locator(sel.postCard).filter({ hasText: homeText }).count();
            }, { timeout: 30_000 })
            .toBeGreaterThan(0);
        await checkCard(page, cardOf(page, homeText), homeId);
    });

    test('J-980e profile', async ({ authenticatedPage: page }) => {
        expect(profileUrl, 'profile link read from the post byline').not.toBe('');
        await page.goto(profileUrl);
        await checkCard(page, cardOf(page, homeText), homeId);
    });

    test('J-980f bookmarks', async ({ authenticatedPage: page }) => {
        const add = await restPost(page.request, nonce, `/posts/${homeId}/bookmark`, {});
        expect([200, 201]).toContain(add.status);
        try {
            await page.goto('/me/bookmarks/');
            await checkCard(page, cardOf(page, homeText), homeId);
        } finally {
            if (await bookmarked(page, homeId)) {
                await page.request.delete(`/wp-json/buddynext/v1/posts/${homeId}/bookmark`, { headers: { 'X-WP-Nonce': nonce } }).catch(() => undefined);
            }
        }
    });

    test('J-980g Activity Feed block', async ({ authenticatedPage: page }) => {
        expect(blockPage).not.toBeNull();
        await page.goto(blockPage!.url);
        await checkCard(page, page.locator('.bn-block-activity-feed').locator(sel.postCard).filter({ hasText: homeText }).first(), homeId);
    });
});
