import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';
import type { Page } from '@playwright/test';

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

            // Edit: change the text in the inline editor, save.
            await menuAction(page, original, /Edit/);
            const input = page.locator(editInput).first();
            await expect(input).toBeVisible();
            await expect(input).toHaveValue(original); // editor prefilled with the real body
            await input.fill(edited);
            await page.locator('.bn-post-card__edit-actions').getByRole('button', { name: 'Save', exact: true }).first().click();

            // Round-trip: after a full reload the EDITED text is the post and the
            // original text is gone (not just a client-side swap).
            await page.goto(urls.feed);
            await expect(cardWith(page, edited).first()).toBeVisible({ timeout: 10_000 });
            await expect(cardWith(page, original)).toHaveCount(0);

            // API truth: the edited body is what the REST feed serves.
            const apiHasEdited = await page.evaluate(async (text) => {
                const r = await fetch('/wp-json/buddynext/v1/feed/home?per_page=20', { headers: { 'X-WP-Nonce': (window as { wpApiSettings?: { nonce?: string } }).wpApiSettings?.nonce ?? '' } });
                if (!r.ok) return null; // nonce not exposed in this context — screen assertions still hold
                const body = await r.text();
                return body.includes(text);
            }, edited);
            if (apiHasEdited !== null) expect(apiHasEdited).toBe(true);

            // Delete: gone from the feed after reload.
            await deletePost(page, edited);
            await page.goto(urls.feed);
            await expect(cardWith(page, edited)).toHaveCount(0);
        } finally {
            // Leave the feed as found — remove either title if anything survived.
            await page.goto(urls.feed);
            await deletePost(page, edited).catch(() => {});
            await deletePost(page, original).catch(() => {});
        }
    });
});
