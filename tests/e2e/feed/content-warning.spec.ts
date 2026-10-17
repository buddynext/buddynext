import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, restPost, restGet, deletePostRest, openMemberSession, type MemberSession } from '../_fixtures/feed-wave1.helpers';
import type { APIRequestContext } from '@playwright/test';

type Warning = { has_warning?: boolean; warning_type?: string };

/** The warning as the content-warning route serves it to whoever owns this request context. */
async function warningOf(request: APIRequestContext, nonce: string, postId: number): Promise<Warning> {
    const r = await restGet<Warning>(request, nonce, `/posts/${postId}/content-warning`);
    expect(r.status, 'GET /posts/{id}/content-warning').toBe(200);
    return r.body;
}

const MEMBER_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'alice';

/**
 * J-516 reveal content-warning (B2).
 *
 * Written from the member's promise: "a content-warning post is covered until I
 * choose to reveal it, and clicking reveal shows the content." The coverage
 * matrix had "Reveal content-warning" MISSING.
 *
 * Effect-based (client reveal is the real consequence here): the spec seeds a
 * post with content_warning=1 through REST (the only way to force the CW state
 * deterministically), reloads the feed so the card is server-rendered with the
 * overlay, asserts the overlay is SHOWN (content hidden behind it), clicks
 * "Show anyway", and asserts the overlay is then HIDDEN (content revealed). A
 * reveal button wired to nothing would leave the overlay visible and fail. Post
 * deleted in `finally`.
 *
 * Covers: cap-blur-a-post-behind-a-content-warning-the-reader-reveals
 * Roles: admin, member
 */
test.describe('feed / content warning reveal', () => {
    const cwOverlay = '.bn-post-card__cw-overlay';
    const cwReveal = '.bn-post-card__cw-reveal';

    test('J-516 a content-warning post reveals its content on click', async ({ authenticatedPage: page }) => {
        const stamp = Date.now().toString().slice(-6);
        const content = `Long hospital recovery story, read only if you want to ${stamp}`;
        let postId = 0;
        let nonce = '';

        try {
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            nonce = await readRestNonce(page);

            const created = await restPost<{ id?: number }>(page.request, nonce, '/posts', {
                content,
                privacy: 'public',
                content_warning: true,
                content_warning_type: 'nsfw',
            });
            expect(created.status, `seed CW post -> ${created.status}`).toBeLessThan(300);
            postId = created.body.id ?? 0;
            expect(postId).toBeGreaterThan(0);

            await page.goto(urls.feed);
            const card = page.locator(sel.postCard).filter({ hasText: content }).first();
            await expect(card).toBeVisible({ timeout: 10_000 });

            // Covered: the overlay is shown before reveal.
            const overlay = card.locator(cwOverlay).first();
            await expect(overlay).toBeVisible();
            // EFFECT: the overlay is there because the stored post carries the warning.
            const stored = await restGet<{ content_warning?: boolean; content_warning_type?: string }>(page.request, nonce, `/posts/${postId}`);
            expect(stored.body.content_warning, 'stored content_warning').toBe(true);
            expect(stored.body.content_warning_type, 'stored content_warning_type').toBe('nsfw');
            expect(await warningOf(page.request, nonce, postId)).toMatchObject({ has_warning: true, warning_type: 'nsfw' });

            const reveal = card.locator(cwReveal).first();
            await expect(reveal).toBeVisible();
            await reveal.click();

            // Revealed: the overlay is now hidden (state.showContent flipped true).
            await expect(overlay).toBeHidden({ timeout: 5_000 });
            // EFFECT: revealing is this reader's choice only. The post keeps its warning.
            expect((await warningOf(page.request, nonce, postId)).has_warning, 'warning after my reveal').toBe(true);
        } finally {
            await deletePostRest(page.request, nonce, postId).catch(() => {});
        }
    });

    /**
     * J-516 member leg. The promise is written from the READER's side ("a
     * content-warning post is covered until I choose to reveal it") — a real
     * member browsing another member's public feed is exactly that reader, so
     * this seeds the CW post as the admin author and reveals it from a genuine
     * member session, not the author's own screen.
     */
    test('J-516 member  -  a member reader reveals a content-warning post', async ({ authenticatedPage: page, browser }) => {
        const stamp = Date.now().toString().slice(-6);
        const content = `Hard week after losing our old dog, sharing for support ${stamp}`;
        let postId = 0;
        let nonce = '';
        let reader: MemberSession | null = null;

        try {
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            nonce = await readRestNonce(page);

            const created = await restPost<{ id?: number }>(page.request, nonce, '/posts', {
                content,
                privacy: 'public',
                content_warning: true,
                content_warning_type: 'nsfw',
            });
            expect(created.status, `seed CW post -> ${created.status}`).toBeLessThan(300);
            postId = created.body.id ?? 0;
            expect(postId).toBeGreaterThan(0);

            reader = await openMemberSession(browser, MEMBER_LOGIN);
            await reader.page.goto(urls.feed);
            const card = reader.page.locator(sel.postCard).filter({ hasText: content }).first();
            await expect(card).toBeVisible({ timeout: 10_000 });

            // Covered for this member reader too — the overlay is shown before reveal.
            const overlay = card.locator(cwOverlay).first();
            await expect(overlay).toBeVisible();
            // EFFECT: the member is served the warning by the route, in her own session.
            expect(await warningOf(reader.request, reader.nonce, postId)).toMatchObject({ has_warning: true, warning_type: 'nsfw' });

            const reveal = card.locator(cwReveal).first();
            await expect(reveal).toBeVisible();
            await reveal.click();

            // Revealed: the overlay is now hidden for the reader who clicked it.
            await expect(overlay).toBeHidden({ timeout: 5_000 });
            // EFFECT: her reveal changed nothing on the server. The author still reads a warned post.
            expect((await warningOf(page.request, nonce, postId)).has_warning, 'warning after the member reveal').toBe(true);
        } finally {
            await deletePostRest(page.request, nonce, postId).catch(() => {});
            if (reader) {
                await reader.ctx.close().catch(() => {});
            }
        }
    });
});
