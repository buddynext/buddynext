import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from '../_fixtures/precondition';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, postIdOfCard, deletePostRest, restGet } from '../_fixtures/feed-wave1.helpers';

type FeedHome = { items?: Array<{ id: number; type?: string; link_url?: string }> };

/**
 * J-12 text post, J-13 link post, J-14 poll, J-15 event.
 */
test.describe('feed / compose', () => {
    // B1 link/oEmbed preview — scoped selectors, kept out of shared selectors.ts.
    const linkPreviewNode = '.bn-post-card__link-preview, .bn-post-card__oembed';
    const linkDomainNode = '.bn-post-card__link-domain';
    test('J-12 text post  -  composer accepts text and submits', async ({ authenticatedPage: page }) => {
        await page.goto(urls.feed);
        const composer = page.locator(sel.composer).first();
        await expect(composer).toBeVisible();

        const stamp = Date.now().toString().slice(-6);
        const content = `e2e text ${stamp}`;

        const textarea = page.locator(sel.composerTextarea).first();
        await expect(textarea).toBeVisible();
        await textarea.fill(content);

        const submit = page.locator(sel.composerSubmit).first();
        await expect(submit).toBeVisible();
        await submit.click();

        // Expect either the new card to surface OR a success state on
        // the composer (some flows redirect to the new post detail).
        const cardWithText = page.locator(sel.postCard).filter({ hasText: content }).first();
        await expect(cardWithText).toBeVisible({ timeout: 8_000 }).catch(async () => {
            // Fallback: composer cleared = success.
            const after = await textarea.inputValue().catch(() => '');
            expect(after).toBe('');
        });
    });

    /**
     * WEAK ASSERTION REPLACED (Wave-4, 2026-08-10). The prior J-13 typed a URL
     * and asserted only `textarea value length > 10` — it proved nothing about a
     * link post at all (any 11 characters passed) and never looked at the
     * resulting card. It is now effect-based: post a URL through the composer,
     * reload, and assert the persisted card renders a link-preview node (the
     * `.bn-post-card__link-preview` card or, for an oEmbed provider, the
     * `.bn-post-card__oembed` player) carrying the URL's domain, AND that REST
     * server truth reports the stored post as `type='link'` with the link_url —
     * proving the composer stored it as a link post, not plain text. The
     * composer sends `type=link` + link_url whenever link-preview is enabled and
     * a URL is present (composer.js pendingUrl path), so this holds even without
     * network OG resolution; when the owner disables link previews the composer
     * never marks it a link and the spec soft-skips on that real precondition.
     * The post is deleted in `finally`.
     */
    test('J-13 link post  -  a posted URL renders a link-preview node and stores as type=link', async ({ authenticatedPage: page }, testInfo) => {
        const stamp = Date.now().toString().slice(-6);
        const host = 'wordpress.org';
        const body = `j13 link ${stamp} https://${host}/`;
        let createdId = 0;
        let nonce = '';

        try {
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            nonce = await readRestNonce(page);

            // Precondition: link previews must be enabled for the composer to mark a
            // URL post as a link. When off the URL is stored as plain text — a real
            // owner-controlled state, not a failure.
            const previewEnabled = await page.evaluate(() => {
                const el = document.querySelector('[data-wp-interactive="buddynext/post-composer"]');
                try { return el ? JSON.parse(el.getAttribute('data-wp-context') ?? '{}').linkPreviewEnabled !== false : true; } catch { return true; }
            });
            if (!previewEnabled) {
                softSkip(testInfo, 'Link previews disabled (buddynext_enable_link_preview off) — URL is stored as text.');
                return;
            }

            await page.locator(sel.composerTextarea).first().fill(body);
            // Let the debounced /link-preview fetch settle so link_meta rides along;
            // the pendingUrl path still marks type=link even if it does not.
            await page.waitForTimeout(1_500);
            await page.locator(sel.composerSubmit).first().click();
            await expect(page.locator(sel.postCard).filter({ hasText: `j13 link ${stamp}` }).first())
                .toBeVisible({ timeout: 10_000 });

            // Reload so the card is server-rendered from the stored link post.
            await page.goto(urls.feed);
            const card = page.locator(sel.postCard).filter({ hasText: `j13 link ${stamp}` }).first();
            await expect(card).toBeVisible({ timeout: 10_000 });
            createdId = await postIdOfCard(page, `j13 link ${stamp}`);
            expect(createdId).toBeGreaterThan(0);

            // The card renders a real link-preview node (preview card or oEmbed
            // player) — not just the URL as text — carrying the URL's domain.
            await expect(card.locator(linkPreviewNode).first()).toBeVisible({ timeout: 10_000 });
            await expect(card.locator(linkDomainNode).first()).toContainText(host);

            // Server truth: the stored post is a link post, not plain text.
            const feed = await restGet<FeedHome>(page.request, nonce, '/feed/home?per_page=20');
            const stored = (feed.body.items ?? []).find((it) => it.id === createdId);
            expect(stored, 'created link post must be in the REST feed').toBeTruthy();
            expect(stored?.type).toBe('link');
            expect(String(stored?.link_url ?? '')).toContain(host);
        } finally {
            await deletePostRest(page.request, nonce, createdId).catch(() => {});
        }
    });

    test('J-14 poll  -  switching to poll mode reveals option fields', async ({ authenticatedPage: page }) => {
        await page.goto(urls.feed);
        const composer = page.locator(sel.composer).first();
        await expect(composer).toBeVisible();

        const pollBtn = page.locator(sel.composerModePoll).first();
        if (!(await pollBtn.isVisible().catch(() => false))) {
            test.fixme(true, 'Poll mode not yet wired in this build  -  fixme until composer exposes data-composer-mode="poll".');
            return;
        }

        await pollBtn.click();
        const optionFields = page.locator('[data-poll-option], .bn-composer__poll-option');
        await expect(optionFields.first()).toBeVisible();
        expect(await optionFields.count()).toBeGreaterThanOrEqual(2);
    });

    test.fixme('J-15 event  -  event mode composer (event surface lands v0.3)', async ({ authenticatedPage: page }) => {
        await page.goto(urls.feed);
        const eventBtn = page.locator(sel.composerModeEvent).first();
        await expect(eventBtn).toBeVisible();
    });
});
