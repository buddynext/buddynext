import { test, expect } from '../_fixtures/auth.fixture';
import { loginAs } from '../_fixtures/actor';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, postIdOfCard, restGet } from '../_fixtures/feed-wave1.helpers';
import type { Page } from '@playwright/test';

type PostRow = { content?: string; edited_at?: string | null };

/** The post as the post route serves it to the member who owns the page. */
async function storedPost(page: Page, nonce: string, postId: number): Promise<{ status: number; body: PostRow }> {
    return restGet<PostRow>(page.request, nonce, `/posts/${postId}`);
}

const MEMBER_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'alice';

/**
 * J-77 post edit + delete — the "past create" half J-12 never tested.
 *
 * Written from the member's promise: "an edit I save is the real post after a
 * reload, and a post I delete is gone — from the feed and from the API, not
 * just from my screen." Presence-only create journeys let a dead edit/delete
 * persistence layer ship green; this asserts the EFFECT after reload.
 *
 * Effect-based (never optimistic-UI): every assertion is made after a full
 * page reload, so a save/delete that 200s client-side but never persists
 * fails here. The post is always deleted at the end, so the feed is left as
 * found even on a mid-test failure.
 *
 * Covers: cap-edit-or-delete-your-own-post
 * despite this being one of the most essential post-lifecycle promises.
 * Roles: admin, member
 */
test.describe('feed / post edit + delete', () => {
    const editInput = '.bn-post-card__edit-input';

    /** The card containing exactly this text, or a zero-count locator. */
    const cardWith = (page: Page, text: string) =>
        page.locator(sel.postCard).filter({ hasText: text });

    const composePost = async (page: Page, content: string): Promise<void> => {
        await page.goto(urls.feed);
        const ta = page.locator(sel.composerTextarea).first();
        await expect(ta).toBeVisible();
        await ta.fill(content);
        await page.locator(sel.composerSubmit).first().click();
        await expect(cardWith(page, content).first()).toBeVisible({ timeout: 10_000 });
        // Reload so the card is server-rendered with a fully-wired options menu
        // (the optimistic card that first appears has no hydrated kebab yet).
        await page.goto(urls.feed);
        await expect(cardWith(page, content).first()).toBeVisible({ timeout: 10_000 });
    };

    /** Open a card's kebab menu and click the named item (menu items are
        `button.bn-post-card__menu-item`; the accessible name normalises the
        menu's raw whitespace, which a hasText regex would not). */
    const menuAction = async (page: Page, cardText: string, action: RegExp): Promise<void> => {
        const card = cardWith(page, cardText).first();
        await card.scrollIntoViewIfNeeded();
        const kebab = card.locator('.bn-post-card__menu');
        await kebab.click();
        // Wait for the Interactivity toggle to actually open the menu before
        // reaching for an item (the options-menu is display:none until then).
        await expect(kebab).toHaveAttribute('aria-expanded', 'true', { timeout: 5_000 });
        // Contains-match, not `^…$`: the menu-item's raw text carries newlines/
        // tabs around the label, which an anchored regex or exact accessible
        // name would miss (the label sits beside an icon span).
        const item = card.locator('.bn-post-card__options-menu .bn-post-card__menu-item')
            .filter({ hasText: action }).first();
        await expect(item).toBeVisible({ timeout: 5_000 });
        await item.click();
    };

    const deletePost = async (page: Page, cardText: string): Promise<void> => {
        const card = cardWith(page, cardText).first();
        if (!(await card.count())) return;
        // A confirm may appear (modal button) OR a native dialog — accept both.
        page.once('dialog', (d) => void d.accept());
        await menuAction(page, cardText, /Delete/);
        const confirm = page.getByRole('button', { name: /^(delete|confirm|yes.*delete|remove)$/i });
        if (await confirm.first().isVisible().catch(() => false)) {
            await confirm.first().click();
        }
        await expect(cardWith(page, cardText)).toHaveCount(0, { timeout: 10_000 });
    };

    test('J-77 edit persists after reload; delete removes it from feed and API', async ({ authenticatedPage: page }, testInfo) => {
        const stamp = Date.now().toString().slice(-6);
        const original = `j77 original ${stamp}`;
        const edited = `j77 EDITED ${stamp}`;

        try {
            await composePost(page, original);
            const nonce = await readRestNonce(page);
            const postId = await postIdOfCard(page, original);
            expect(postId, 'resolved server post id').toBeGreaterThan(0);
            // EFFECT (baseline): the stored body is what I typed, and it has never been edited.
            const created = await storedPost(page, nonce, postId);
            expect(created.status, 'GET /posts/{id} after create').toBe(200);
            expect(created.body.content, 'stored body after create').toBe(original);
            expect(created.body.edited_at ?? null, 'edited_at on a fresh post').toBeNull();

            // Edit: change the text in the inline editor, save.
            await menuAction(page, original, /Edit/);
            const input = page.locator(editInput).first();
            await expect(input).toBeVisible();
            await expect(input).toHaveValue(original); // editor prefilled with the real body
            await input.fill(edited);
            // Wait for the edit to land before navigating: going to the feed straight
            // after the click can abort the in-flight PUT (a flaky failure, not a bug).
            const saved = page.waitForResponse((r) => /\/posts\/\d+(\?|$)/.test(r.url()) && 'PUT' === r.request().method());
            await page.locator('.bn-post-card__edit-actions').getByRole('button', { name: 'Save', exact: true }).first().click();
            expect((await saved).status(), 'post edit did not save').toBe(200);

            // Round-trip: after a full reload the EDITED text is the post and the
            // original text is gone (not just a client-side swap).
            await page.goto(urls.feed);
            await expect(cardWith(page, edited).first()).toBeVisible({ timeout: 10_000 });
            await expect(cardWith(page, original)).toHaveCount(0);

            // EFFECT: API truth. This used to read the feed with window.wpApiSettings.nonce,
            // which the feed page does not define, so the request was a 401 and the check
            // never ran. Read the post route with the page's real wp_rest nonce instead: the
            // SAME post id now serves the edited body, flagged edited.
            const afterEdit = await storedPost(page, nonce, postId);
            expect(afterEdit.status, 'GET /posts/{id} after edit').toBe(200);
            expect(afterEdit.body.content, 'stored body after edit').toBe(edited);
            expect(afterEdit.body.edited_at, 'edited_at after edit').toBeTruthy();
            const feed = await restGet<{ items?: Array<{ id: number; content?: string }> }>(page.request, nonce, '/feed/home?per_page=20');
            expect(feed.status, 'GET /feed/home').toBe(200);
            expect((feed.body.items ?? []).find((it) => Number(it.id) === postId)?.content, 'body the feed route serves').toBe(edited);

            // Delete: gone from the feed after reload.
            await deletePost(page, edited);
            await page.goto(urls.feed);
            await expect(cardWith(page, edited)).toHaveCount(0);
            // EFFECT: gone from the API too, not only from my screen.
            expect((await storedPost(page, nonce, postId)).status, 'GET /posts/{id} after delete').toBe(404);
        } finally {
            // Leave the feed as found — remove either title if anything survived.
            await page.goto(urls.feed);
            await deletePost(page, edited).catch(() => {});
            await deletePost(page, original).catch(() => {});
        }
    });

    /**
     * J-77 member leg. The admin above owns `manage_options` and edits/deletes
     * their own post through the same code path a member's edit-own-post
     * capability check must also clear (CommentService/PostService owner
     * checks, not a capability the admin happens to bypass). A regression that
     * only breaks the ordinary-owner branch of that check would pass the admin
     * walk and fail here.
     */
    test('J-77 member  -  a member edits their own post and deletes it, both persisting after reload', async ({ page }) => {
        await loginAs(page, MEMBER_LOGIN);
        const stamp = Date.now().toString().slice(-6);
        const original = `j77m member original ${stamp}`;
        const edited = `j77m member EDITED ${stamp}`;

        try {
            await composePost(page, original);
            const nonce = await readRestNonce(page);
            const postId = await postIdOfCard(page, original);
            expect(postId, 'resolved server post id').toBeGreaterThan(0);

            await menuAction(page, original, /Edit/);
            const input = page.locator(editInput).first();
            await expect(input).toBeVisible();
            await expect(input).toHaveValue(original);
            await input.fill(edited);
            // Wait for the edit to land before navigating: going to the feed straight
            // after the click can abort the in-flight PUT (a flaky failure, not a bug).
            const saved = page.waitForResponse((r) => /\/posts\/\d+(\?|$)/.test(r.url()) && 'PUT' === r.request().method());
            await page.locator('.bn-post-card__edit-actions').getByRole('button', { name: 'Save', exact: true }).first().click();
            expect((await saved).status(), 'post edit did not save').toBe(200);

            // Round-trip: after a full reload the EDITED text is the post and the
            // original is gone — proven as the member's own session, not admin's.
            await page.goto(urls.feed);
            await expect(cardWith(page, edited).first()).toBeVisible({ timeout: 10_000 });
            await expect(cardWith(page, original)).toHaveCount(0);
            // EFFECT: the member's own row carries the edit, per the post route.
            const afterEdit = await storedPost(page, nonce, postId);
            expect(afterEdit.status, 'GET /posts/{id} after edit').toBe(200);
            expect(afterEdit.body.content, 'stored body after edit').toBe(edited);
            expect(afterEdit.body.edited_at, 'edited_at after edit').toBeTruthy();

            // Delete: gone from the feed after reload.
            await deletePost(page, edited);
            await page.goto(urls.feed);
            await expect(cardWith(page, edited)).toHaveCount(0);
            // EFFECT: the member's delete removed the row; the API answers 404.
            expect((await storedPost(page, nonce, postId)).status, 'GET /posts/{id} after delete').toBe(404);
        } finally {
            await page.goto(urls.feed);
            await deletePost(page, edited).catch(() => {});
            await deletePost(page, original).catch(() => {});
        }
    });
});
