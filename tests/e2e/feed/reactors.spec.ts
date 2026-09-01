import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, postIdOfCard, deletePostRest } from '../_fixtures/feed-wave1.helpers';

/**
 * J-504 see reactors (B2).
 *
 * Written from the member's promise: "when I react, I'm in the list of people
 * who reacted." The coverage matrix had "see reactors" MISSING, and the
 * existing reactions spec is WEAK (asserts the button's own class flips —
 * optimistic, survives a silent 500). This reacts to a fresh own post, opens
 * the reactors popover, and asserts the current user's display name is in the
 * list. The list is filled from GET /reactions/list, so a name appearing there
 * is server truth that the reaction row was written — not an optimistic class.
 */
test.describe('feed / see reactors', () => {
    const reactorsTrigger = '.bn-post-card__reactors-trigger';
    const reactorName = '.bn-reactors-popover__name';

    test('J-504 reacting puts me in the reactors list', async ({ authenticatedPage: page }) => {
        let createdId = 0;
        let nonce = '';
        const stamp = Date.now().toString().slice(-6);
        const body = `j504 react-to-me ${stamp}`;

        try {
            // A fresh own post starts at zero reactions, so the summary strip is
            // hidden and the 0->1 reveal is exercised end-to-end.
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            nonce = await readRestNonce(page);
            await page.locator(sel.composerTextarea).first().fill(body);
            await page.locator(sel.composerSubmit).first().click();
            await expect(page.locator(sel.postCard).filter({ hasText: body }).first()).toBeVisible({ timeout: 10_000 });

            // Reload so the card's reaction store + reactors popover are hydrated.
            await page.goto(urls.feed);
            const card = page.locator(sel.postCard).filter({ hasText: body }).first();
            await expect(card).toBeVisible({ timeout: 10_000 });
            createdId = await postIdOfCard(page, body);

            // React: open the picker, pick the first emoji.
            await card.locator(sel.postReact).first().click();
            const emoji = card.locator(sel.postReactEmoji).first();
            await expect(emoji).toBeVisible({ timeout: 5_000 });
            await emoji.click();

            // The summary strip reveals (0->1); its reactors trigger becomes usable.
            const trigger = card.locator(reactorsTrigger).first();
            await expect(trigger).toBeVisible({ timeout: 8_000 });
            await trigger.click();

            // Effect: the popover, filled from GET /reactions/list, lists me.
            //
            // The name is READ FROM THE SITE, not hardcoded. It used to assert the
            // literal 'Varun Dubey' while this account's WordPress display_name is
            // 'varundubey' - so the spec could never pass here however correct the
            // product was, and it reported a healthy feature as broken. A display
            // name is site data; any spec that hardcodes one only works on the
            // machine it was written on.
            const popover = card.locator('.bn-reactors-popover');
            await expect(popover).toBeVisible({ timeout: 5_000 });

            // Taken from the card's own author line. The viewer wrote this post, so
            // that name IS the viewer's display name - and it comes from the post
            // render, not from the reactions endpoint the popover was filled from,
            // so the assertion stays independent of what it is checking.
            const selfDisplayName = (
                await card.locator('.bn-post-card__author-name').first().innerText()
            ).trim();

            expect(selfDisplayName, 'could not resolve the viewer display name').not.toBe('');

            await expect(popover.locator(reactorName).filter({ hasText: selfDisplayName }).first())
                .toBeVisible({ timeout: 8_000 });
        } finally {
            await deletePostRest(page.request, nonce, createdId).catch(() => {});
        }
    });
});
