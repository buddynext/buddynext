import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';

const OWNER = process.env.BN_TEST_USER ?? 'admin';
import { readRestNonce, postIdOfCard, deletePostRest } from '../_fixtures/feed-wave1.helpers';
import type { Page, Locator } from '@playwright/test';

/**
 * J-505 pin / unpin a post (B2).
 *
 * Written from the member's promise: "a post I pin stays pinned after a reload,
 * and unpinning clears it." The coverage matrix had both Pin and Unpin (its
 * shipped counterpart) MISSING. This pins an own post, reloads and asserts the
 * server re-renders the pinned state (class + "Pinned" badge), then unpins and
 * reloads to assert it clears. Effect-based: every assertion is after a full
 * reload, so a pin that flips the DOM but never persists (POST /pin silently
 * failing) fails here.
 */
test.describe('feed / pin + unpin', () => {
    const pinnedClass = /bn-post-card--pinned/;
    const pinLabel = '.bn-post-card__pin-label';
    const pinItem = '.bn-post-card__options-menu .bn-post-card__menu-item[data-wp-on--click="actions.pinPost"]';

    const cardWith = (page: Page, text: string): Locator =>
        page.locator(sel.postCard).filter({ hasText: text }).first();

    const clickPin = async (page: Page, card: Locator): Promise<void> => {
        await card.scrollIntoViewIfNeeded();
        const kebab = card.locator('.bn-post-card__menu');
        await kebab.click();
        await expect(kebab).toHaveAttribute('aria-expanded', 'true', { timeout: 5_000 });
        const item = card.locator(pinItem).first();
        await expect(item).toBeVisible({ timeout: 5_000 });
        await item.click();
    };

    // Pin is PROFILE-only since 1.1.6. post-card.php gates it on
    //   $can_pin = $is_own_post && 'profile' === $context && 0 === $bn_space_id
    // after de1bcfa5 / 6e62e67d / f6114f5b re-scoped pinning: spaces surface
    // important content through Announcements, and a member pins only their
    // own non-space post, on their own profile. This spec still walked
    // /activity/, where the control correctly no longer renders, so the
    // journey gate reported a regression for behaviour that was removed on
    // purpose. Walk the profile feed, which is where pinning now lives.
    test('J-505 pin persists after reload; unpin clears it', async ({ authenticatedPage: page }) => {
        let createdId = 0;
        let nonce = '';
        const stamp = Date.now().toString().slice(-6);
        const body = `j505 pin-me ${stamp}`;

        try {
            await page.goto(urls.member(OWNER));
            await expect(page.locator(sel.composer).first()).toBeVisible();
            nonce = await readRestNonce(page);
            await page.locator(sel.composerTextarea).first().fill(body);
            await page.locator(sel.composerSubmit).first().click();
            await expect(cardWith(page, body)).toBeVisible({ timeout: 10_000 });

            // Reload so the card head + kebab are server-rendered and hydrated.
            await page.goto(urls.member(OWNER));
            await expect(cardWith(page, body)).toBeVisible({ timeout: 10_000 });
            createdId = await postIdOfCard(page, body);

            // Pin — optimistic flip first.
            await clickPin(page, cardWith(page, body));
            await expect(cardWith(page, body)).toHaveClass(pinnedClass, { timeout: 8_000 });
            await expect(cardWith(page, body).locator(pinLabel)).toBeVisible();

            // Persistence: after reload the server still says pinned.
            await page.goto(urls.member(OWNER));
            await expect(cardWith(page, body)).toHaveClass(pinnedClass, { timeout: 10_000 });
            await expect(cardWith(page, body).locator(pinLabel)).toBeVisible();

            // Unpin — the same control now DELETEs the pin.
            await clickPin(page, cardWith(page, body));
            await expect(cardWith(page, body)).not.toHaveClass(pinnedClass, { timeout: 8_000 });

            // Persistence: after reload the server confirms it is no longer pinned.
            await page.goto(urls.member(OWNER));
            await expect(cardWith(page, body)).toBeVisible({ timeout: 10_000 });
            await expect(cardWith(page, body)).not.toHaveClass(pinnedClass);
        } finally {
            await deletePostRest(page.request, nonce, createdId).catch(() => {});
        }
    });
});
